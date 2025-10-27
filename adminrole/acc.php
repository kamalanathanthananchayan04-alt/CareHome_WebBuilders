<?php
session_start();

$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in and has admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Database connection
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle care home selection
if (isset($_POST['carehome_select']) && !empty($_POST['carehome_select'])) {
    $_SESSION['selected_home_id'] = (int)$_POST['carehome_select'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get selected care home ID from session
$selectedHomeId = $_SESSION['selected_home_id'] ?? null;

// Fetch all care homes for dropdown
$careHomes = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM homes");
    $careHomes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching care homes: " . $e->getMessage();
}

// Get residents for selected care home
$residents = [];
if ($selectedHomeId) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ? ORDER BY first_name, last_name");
        $stmt->execute([$selectedHomeId]);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error fetching residents: " . $e->getMessage();
    }
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedHomeId) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_income' || $action === 'add_expense' || $action === 'add_drop') {
        try {
            // Validate required fields
            if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
                throw new Exception("Invalid amount specified");
            }
            
            if (empty($_POST['description'])) {
                throw new Exception("Description is required");
            }
            
            if (empty($_POST['payment_method'])) {
                throw new Exception("Payment method is required");
            }
            
            // Prepare transaction data
            $transaction_data = [
                'home_id' => $selectedHomeId,
                'resident_id' => !empty($_POST['resident_id']) ? (int)$_POST['resident_id'] : null,
                'type' => str_replace('add_', '', $action), // income, expense, or drop
                'amount' => floatval($_POST['amount']),
                'payment_method' => $_POST['payment_method'],
                'description' => trim($_POST['description']),
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'created_by' => $_SESSION['username'] ?? 'admin'
            ];
            
            // Insert transaction
            $stmt = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $success = $stmt->execute([
                $transaction_data['home_id'],
                $transaction_data['resident_id'],
                $transaction_data['type'],
                $transaction_data['amount'],
                $transaction_data['payment_method'],
                $transaction_data['description'],
                $transaction_data['reference_no'],
                $transaction_data['created_by']
            ]);
            
            if ($success) {
                // Save proof file and update DB with relative path
                $txId = $pdo->lastInsertId();
                if (!empty($_FILES['proof']['name'])) {
                    $file = $_FILES['proof'];
                    if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                        $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
                        $detected = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
                        $mime = $detected ?: ($file['type'] ?? '');
                        if (in_array($mime, $allowed)) {
                            $root = dirname(__DIR__);
                            $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'transactions' . DIRECTORY_SEPARATOR . intval($txId);
                            if (!is_dir($targetDir)) { @mkdir($targetDir, 0777, true); }
                            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                            $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext);
                            $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'proof.' . ($safeExt ?: 'dat');
                            if (@move_uploaded_file($file['tmp_name'], $targetPath)) {
                                $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $targetPath);
                                $upd = $pdo->prepare("UPDATE transactions SET proof_path = ? WHERE id = ?");
                                $upd->execute([$relative, $txId]);
                            }
                        }
                    }
                }
                // Flash message + PRG redirect
                $_SESSION['flash_message'] = ucfirst($transaction_data['type']) . " transaction added successfully!";
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } else {
                throw new Exception("Failed to add transaction");
            }
            
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Read flash message if present
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Get today's transactions for selected care home
$today = date('Y-m-d');
$today_transactions = [];
$total_income = 0;
$total_expense = 0;
$total_drop = 0;
$net_profit = 0;

if ($selectedHomeId) {
    try {
        $stmt = $pdo->prepare("SELECT t.*, r.first_name, r.last_name, r.room_number 
                              FROM transactions t 
                              LEFT JOIN residents r ON t.resident_id = r.id 
                              WHERE t.home_id = ? AND DATE(t.created_at) = ? 
                              ORDER BY t.created_at DESC");
        $stmt->execute([$selectedHomeId, $today]);
        $today_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        foreach ($today_transactions as $transaction) {
            if ($transaction['type'] === 'income') {
                $total_income += $transaction['amount'];
            } elseif ($transaction['type'] === 'expense') {
                $total_expense += $transaction['amount'];
            } elseif ($transaction['type'] === 'drop') {
                $total_drop += $transaction['amount'];
            }
        }
        
        $net_profit = $total_income - $total_expense - $total_drop;
        
    } catch (PDOException $e) {
        $error = "Error fetching transactions: " . $e->getMessage();
    }
}

// Handle AJAX request for pending amount
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_amount' && $selectedHomeId) {
    header('Content-Type: application/json');
    
    try {
        $mode = $_GET['mode'] ?? 'carehome';
        $resident_id = $_GET['resident_id'] ?? null;
        
        if ($mode === 'resident' && $resident_id) {
            // Get specific resident's financial summary
            $stmt = $pdo->prepare("SELECT 
                        r.id,
                        r.first_name,
                        r.last_name,
                        r.room_number,
                        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as bank_income,
                        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as cash_income,
                        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as total_expenses,
                        COALESCE(SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END), 0) as total_drops
                    FROM residents r
                    LEFT JOIN transactions t ON r.id = t.resident_id
                    WHERE r.home_id = ? AND r.id = ?
                    GROUP BY r.id, r.first_name, r.last_name, r.room_number");
            
            $stmt->execute([$selectedHomeId, $resident_id]);
            $resident_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($resident_data) {
                $total_income = $resident_data['bank_income'] + $resident_data['cash_income'];
                $total_costs = $resident_data['total_expenses'] + $resident_data['total_drops'];
                $pending_balance = $total_income - $total_costs;
                
                $result = [
                    'id' => $resident_data['id'],
                    'name' => $resident_data['first_name'] . ' ' . $resident_data['last_name'],
                    'room_number' => $resident_data['room_number'],
                    'bank_income' => floatval($resident_data['bank_income']),
                    'cash_income' => floatval($resident_data['cash_income']),
                    'total_income' => $total_income,
                    'total_expenses' => floatval($resident_data['total_expenses']),
                    'total_drops' => floatval($resident_data['total_drops']),
                    'total_costs' => $total_costs,
                    'pending_balance' => $pending_balance
                ];
                
                echo json_encode(['success' => true, 'data' => $result, 'mode' => 'resident']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Resident not found']);
            }
        } else {
            // Get care home summary
            $stmt = $pdo->prepare("SELECT 
                        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as total_bank_income,
                        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as total_cash_income,
                        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as total_expenses,
                        COALESCE(SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END), 0) as total_drops,
                        h.bank as current_bank_balance,
                        h.cash as current_cash_balance
                    FROM homes h
                    LEFT JOIN transactions t ON h.id = t.home_id
                    WHERE h.id = ?
                    GROUP BY h.bank, h.cash");
            
            $stmt->execute([$selectedHomeId]);
            $carehome_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($carehome_data) {
                $total_income = $carehome_data['total_bank_income'] + $carehome_data['total_cash_income'];
                $total_costs = $carehome_data['total_expenses'] + $carehome_data['total_drops'];
                $net_balance = $total_income - $total_costs;
                
                $result = [
                    'total_bank_income' => floatval($carehome_data['total_bank_income']),
                    'total_cash_income' => floatval($carehome_data['total_cash_income']),
                    'total_income' => $total_income,
                    'total_expenses' => floatval($carehome_data['total_expenses']),
                    'total_drops' => floatval($carehome_data['total_drops']),
                    'total_costs' => $total_costs,
                    'current_bank_balance' => floatval($carehome_data['current_bank_balance']),
                    'current_cash_balance' => floatval($carehome_data['current_cash_balance']),
                    'net_balance' => $net_balance
                ];
                
                echo json_encode(['success' => true, 'data' => $result, 'mode' => 'carehome']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Care home not found']);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts - Care Home Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset and base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        /* Dashboard layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar styles */
        .sidebar {
            width: 250px;
            background: linear-gradient(to bottom, #2c3e50, #3498db);
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .sidebar-header h2 {
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }

        .menu-item {
            margin-bottom: 5px;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
        }

        .menu-item a:hover, .menu-item.active a {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid #e74c3c;
        }

        .menu-item a i {
            width: 25px;
            margin-right: 10px;
        }

        /* Main content styles */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 0;
            width: calc(100% - 250px);
        }

        .main-header {
            background-color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 99;
        }

        .main-header h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #7f8c8d;
        }

        .content-area {
            padding: 30px;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h2 {
            font-size: 1.8rem;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #7f8c8d;
        }

        /* Message styles */
        .message {
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Financial Summary Cards */
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .summary-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.5rem;
        }

        .income .summary-icon {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .expense .summary-icon {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .profit .summary-icon {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        .summary-content h3 {
            font-size: 1rem;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .summary-amount {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .summary-card small {
            color: #95a5a6;
        }

        /* Sub-content navigation */
        .sub-content-nav {
            display: flex;
            background: white;
            border-radius: 8px;
            padding: 5px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            flex-wrap: wrap;
        }

        .sub-nav-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            border-radius: 5px;
            transition: all 0.3s;
            color: #7f8c8d;
            font-weight: 500;
            min-width: 150px;
        }

        .sub-nav-btn.active {
            background: #3498db;
            color: white;
            box-shadow: 0 2px 5px rgba(52, 152, 219, 0.3);
        }

        /* Sub-content sections */
        .sub-content {
            display: none;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .sub-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sub-content-header {
            background: linear-gradient(to right, #3498db, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sub-content-body {
            padding: 25px;
        }

        /* Form styles */
        .account-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: flex;
            gap: 20px;
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }

        .form-group input, .form-group select, .form-group textarea {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border 0.3s;
        }

        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        /* Transaction controls */
        .transaction-controls {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .date-picker, .transaction-filters {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-picker label, .transaction-filters label {
            font-weight: 500;
            color: #2c3e50;
        }

        .date-picker input, .transaction-filters select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        /* Table styles */
        .transactions-table-container {
            overflow-x: auto;
            border-radius: 5px;
            border: 1px solid #eee;
            margin-bottom: 20px;
        }

        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }

        .transactions-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
        }

        .transactions-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .transactions-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .transaction-type {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .transaction-type.income {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .transaction-type.expense {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }

        .transaction-type.drop {
            background: rgba(155, 89, 182, 0.1);
            color: #9b59b6;
        }

        .amount {
            font-weight: 600;
        }

        .amount.income {
            color: #2ecc71;
        }

        .amount.expense {
            color: #e74c3c;
        }

        .amount.drop {
            color: #9b59b6;
        }

        /* Transaction summary */
        .transaction-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .summary-item.total {
            font-weight: 600;
            font-size: 1.1rem;
            border-bottom: none;
            border-top: 2px solid #3498db;
            margin-top: 5px;
            padding-top: 15px;
        }

        /* Pending section styles */
        .pending-section {
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .pending-section > div:first-child {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pending-table-container {
            overflow-x: auto;
            border: 1px solid #eee;
            border-radius: 6px;
        }

        .pending-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pending-table th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
        }

        .pending-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }

        /* Logout button */
        .logout-btn {
            padding: 8px 15px;
            border: none;
            background: #e74c3c;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }
        .logout-btn:hover {
            background: #c0392b;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar-header h2 span, .menu-item a span {
                display: none;
            }
            
            .main-content {
                margin-left: 70px;
                width: calc(100% - 70px);
            }
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .financial-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .transaction-controls {
                flex-direction: column;
            }
            
            .date-picker, .transaction-filters {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .transactions-table {
                font-size: 0.9rem;
            }
            
            .transactions-table th, .transactions-table td {
                padding: 10px 5px;
            }
            
            .transaction-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .content-area {
                padding: 15px;
            }
            
            .main-header {
                padding: 15px;
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .sub-content-nav {
                flex-direction: column;
            }
            
            .sub-nav-btn {
                min-width: 100%;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-home"></i> <span>Care Home Admin</span></h2>
            </div>
            <ul class="sidebar-menu">
               <li class="menu-item"><a href="adash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item active"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> Accounts</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="response.php"><i class="fas fa-comment-dots"></i> Response</a></li>
                <li class="menu-item"><a href="responseCount.php"><i class="fas fa-chart-pie"></i> Summary</a></li>
                <li class="menu-item"><a href="peddyCash.php"><i class="fas fa-money-bill-wave"></i> Peddy Cash</a></li>
                <li class="menu-item"><a href="notification.php"><i class="fas fa-bell"></i> Notification</a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1><i class="fas fa-calculator"></i> Accounts Management</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                    <a href="../logout.php"><button type="submit" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></button></a>
                </div>
            </header>

            <!-- Care Home Selector -->
            <div class="content-area" style="padding-top:0;">
                <div class="carehome-selector" style="background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.08);padding:16px 18px;margin:20px;margin-bottom:20px;">
                    <form method="post" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#2c3e50;">
                            <i class="fas fa-home"></i> Select Care Home:
                        </label>
                        <select name="carehome_select" id="carehomeSelector" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;min-width:200px;font-size:14px;" onchange="this.form.submit()">
                            <option value="">Choose a care home...</option>
                            <?php foreach ($careHomes as $home): ?>
                                <option value="<?php echo $home['id']; ?>" <?php echo $selectedHomeId == $home['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($home['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnRefreshData" class="btn" style="background:#27ae60;color:#fff;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </form>
                </div>
            </div>

            <div class="content-area">
                <div class="page-header">
                    <h2>Financial Management System</h2>
                    <p>Manage income, expenses, and daily transactions</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$selectedHomeId): ?>
                    <div class="message error">
                        <i class="fas fa-exclamation-triangle"></i> Please select a care home to manage transactions.
                    </div>
                <?php else: ?>

                <!-- Financial Summary Cards -->
                <div class="financial-summary">
                    <div class="summary-card income">
                        <div class="summary-icon">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Income</h3>
                            <p class="summary-amount">$<?php echo number_format($total_income, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    <div class="summary-card expense">
                        <div class="summary-icon">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Expenses</h3>
                            <p class="summary-amount">$<?php echo number_format($total_expense, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    <div class="summary-card profit">
                        <div class="summary-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Net Profit</h3>
                            <p class="summary-amount">$<?php echo number_format($net_profit, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                </div>

                <!-- Sub-content Navigation -->
                <div class="sub-content-nav">
                    <button class="sub-nav-btn active" data-target="add-income">
                        <i class="fas fa-plus-circle"></i>
                        Add Income
                    </button>
                    <button class="sub-nav-btn" data-target="add-expense">
                        <i class="fas fa-minus-circle"></i>
                        Add Expense
                    </button>
                    <button class="sub-nav-btn" data-target="drop-amount">
                        <i class="fas fa-hand-holding-usd"></i>
                        Drop Amount
                    </button>
                    <button class="sub-nav-btn" data-target="daily-transaction">
                        <i class="fas fa-calendar-day"></i>
                        Daily Transaction
                    </button>
                </div>

                <!-- Add Income Sub-content -->
                <div id="add-income" class="sub-content active">
                    <div class="sub-content-header">
                        <i class="fas fa-plus-circle"></i>
                        Add New Income
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="incomeForm" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_income">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="incomeType">
                                        <i class="fas fa-tag"></i>
                                        Income Type
                                    </label>
                                    <select id="incomeType" name="incomeType" required>
                                        <option value="">Select Income Type</option>
                                        <option value="resident_fees">Resident Fees</option>
                                        <option value="government_funding">Government Funding</option>
                                        <option value="donations">Donations</option>
                                        <option value="insurance">Insurance Payments</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="incomeAmount">
                                        <i class="fas fa-dollar-sign"></i>
                                        Amount
                                    </label>
                                    <input type="number" id="incomeAmount" name="amount" step="0.01" min="0" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="incomeResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <select id="incomeResident" name="resident_id">
                                        <option value="">Choose Resident (Optional)</option>
                                        <?php foreach ($residents as $resident): ?>
                                            <option value="<?php echo $resident['id']; ?>">
                                                <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-money-check-alt"></i>
                                        Payment Type
                                    </label>
                                    <div style="display:flex;gap:1rem;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="cash" required>
                                            Cash
                                        </label>
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="bank" required>
                                            Bank
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="incomeReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference/Receipt No.
                                    </label>
                                    <input type="text" id="incomeReference" name="reference_no" placeholder="Optional reference number">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="incomeDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="incomeDescription" name="description" rows="3" placeholder="Additional details about this income..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="incomeProof">
                                    <i class="fas fa-paperclip"></i>
                                    Upload Proof (Image/PDF)
                                </label>
                                <input type="file" id="incomeProof" name="proof" accept="image/*,application/pdf">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Add Income
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Add Expense Sub-content -->
                <div id="add-expense" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-minus-circle"></i>
                        Add New Expense
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="expenseForm" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_expense">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expenseType">
                                        <i class="fas fa-tag"></i>
                                        Expense Type
                                    </label>
                                    <select id="expenseType" name="expenseType" required>
                                        <option value="">Select Expense Type</option>
                                        <option value="staff_salaries">Staff Salaries</option>
                                        <option value="utilities">Utilities</option>
                                        <option value="food_supplies">Food & Supplies</option>
                                        <option value="medical_supplies">Medical Supplies</option>
                                        <option value="maintenance">Maintenance</option>
                                        <option value="insurance">Insurance</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="expenseAmount">
                                        <i class="fas fa-dollar-sign"></i>
                                        Amount
                                    </label>
                                    <input type="number" id="expenseAmount" name="amount" step="0.01" min="0" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expenseResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <select id="expenseResident" name="resident_id">
                                        <option value="">Choose Resident (Optional)</option>
                                        <?php foreach ($residents as $resident): ?>
                                            <option value="<?php echo $resident['id']; ?>">
                                                <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-money-check-alt"></i>
                                        Payment Type
                                    </label>
                                    <div style="display:flex;gap:1rem;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="cash" required>
                                            Cash
                                        </label>
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="bank" required>
                                            Bank
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expenseReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference/Receipt No.
                                    </label>
                                    <input type="text" id="expenseReference" name="reference_no" placeholder="Optional reference number">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="expenseDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="expenseDescription" name="description" rows="3" placeholder="Additional details about this expense..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="expenseProof">
                                    <i class="fas fa-paperclip"></i>
                                    Upload Proof (Image/PDF)
                                </label>
                                <input type="file" id="expenseProof" name="proof" accept="image/*,application/pdf">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Add Expense
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Drop Amount Sub-content -->
                <div id="drop-amount" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-hand-holding-usd"></i>
                        Drop Amount
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="dropForm" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_drop">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dropAmount">
                                        <i class="fas fa-dollar-sign"></i>
                                        Drop Amount
                                    </label>
                                    <input type="number" id="dropAmount" name="amount" step="0.01" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label for="dropResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <select id="dropResident" name="resident_id">
                                        <option value="">Choose Resident (Optional)</option>
                                        <?php foreach ($residents as $resident): ?>
                                            <option value="<?php echo $resident['id']; ?>">
                                                <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dropReason">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Reason for Drop
                                    </label>
                                    <select id="dropReason" name="dropReason" required>
                                        <option value="">Select Reason</option>
                                        <option value="refund">Refund to Resident</option>
                                        <option value="adjustment">Account Adjustment</option>
                                        <option value="error_correction">Error Correction</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-money-check-alt"></i>
                                        Payment Type
                                    </label>
                                    <div style="display:flex;gap:1rem;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="cash" required>
                                            Cash
                                        </label>
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="bank" required>
                                            Bank
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dropReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference Number
                                    </label>
                                    <input type="text" id="dropReference" name="reference_no" placeholder="Transaction reference">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="dropDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="dropDescription" name="description" rows="3" placeholder="Detailed explanation for the amount drop..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="dropProof">
                                    <i class="fas fa-paperclip"></i>
                                    Upload Proof (Image/PDF)
                                </label>
                                <input type="file" id="dropProof" name="proof" accept="image/*,application/pdf">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Process Drop
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Daily Transaction Sub-content -->
                <div id="daily-transaction" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-calendar-day"></i>
                        Daily Transactions
                    </div>
                    <div class="sub-content-body">
                        <div class="transaction-controls">
                            <div class="date-picker">
                                <label for="transactionDate">
                                    <i class="fas fa-calendar"></i>
                                    Select Date
                                </label>
                                <input type="date" id="transactionDate" value="<?php echo $today; ?>">
                            </div>
                            <div class="transaction-filters">
                                <label for="transactionType">Type:</label>
                                <select id="transactionType">
                                    <option value="">All Transactions</option>
                                    <option value="income">Income Only</option>
                                    <option value="expense">Expense Only</option>
                                    <option value="drop">Drops Only</option>
                                </select>
                                <label for="transactionResident">Resident:</label>
                                <select id="transactionResident">
                                    <option value="">All Residents</option>
                                    <?php foreach ($residents as $resident): ?>
                                        <option value="<?php echo $resident['id']; ?>">
                                            <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="transactions-table-container">
                            <table class="transactions-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-clock"></i> Time</th>
                                        <th><i class="fas fa-tag"></i> Type</th>
                                        <th><i class="fas fa-file-text"></i> Description</th>
                                        <th><i class="fas fa-user"></i> Resident</th>
                                        <th><i class="fas fa-dollar-sign"></i> Amount</th>
                                        <th><i class="fas fa-money-check"></i> Payment</th>
                                        <th><i class="fas fa-user"></i> User</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionsTableBody">
                                    <?php if (empty($today_transactions)): ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 20px;">
                                                <i class="fas fa-info-circle"></i> No transactions found for today.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($today_transactions as $transaction): ?>
                                            <tr>
                                                <td><?php echo date('h:i A', strtotime($transaction['created_at'])); ?></td>
                                                <td>
                                                    <span class="transaction-type <?php echo $transaction['type']; ?>">
                                                        <i class="fas fa-<?php echo $transaction['type'] === 'income' ? 'arrow-up' : ($transaction['type'] === 'expense' ? 'arrow-down' : 'hand-holding-usd'); ?>"></i>
                                                        <?php echo ucfirst($transaction['type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td>
                                                    <?php if ($transaction['first_name']): ?>
                                                        <?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?>
                                                    <?php else: ?>
                                                        <em>General</em>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount <?php echo $transaction['type']; ?>">
                                                    <?php echo ($transaction['type'] === 'income' ? '+' : '-'); ?><?php echo number_format($transaction['amount'], 2); ?>
                                                </td>
                                                <td>
                                                    <span style="text-transform: capitalize;"><?php echo $transaction['payment_method']; ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['created_by']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="transaction-summary">
                            <div class="summary-item">
                                <span>Total Income:</span>
                                <span class="summary-amount income" id="totalIncome">+$<?php echo number_format($total_income, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Total Expenses:</span>
                                <span class="summary-amount expense" id="totalExpense">-$<?php echo number_format($total_expense, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Total Drops:</span>
                                <span class="summary-amount drop" id="totalDrop">-$<?php echo number_format($total_drop, 2); ?></span>
                            </div>
                            <div class="summary-item total">
                                <span>Net Total:</span>
                                <span class="summary-amount" id="netTotal">$<?php echo number_format($net_profit, 2); ?></span>
                            </div>
                        </div>

                        <!-- Pending Amount Section -->
                        <div class="pending-section">
                            <div>
                                <i class="fas fa-hourglass-half"></i>
                                <strong>Pending Amount Summary</strong>
                            </div>
                            <div style="padding: 16px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <label for="pendingMode" style="font-weight:600; color:#2c3e50;">View Mode:</label>
                                    <select id="pendingMode" style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
                                        <option value="carehome">Care Home Overall</option>
                                        <option value="resident">Individual Resident</option>
                                    </select>
                                </div>
                                <div id="pendingResidentBlock" style="display:none; align-items:center; gap:8px;">
                                    <label for="pendingResident" style="font-weight:600; color:#2c3e50;">Select Resident:</label>
                                    <select id="pendingResident" style="padding:8px 12px; border:1px solid #ddd; border-radius:6px; min-width: 220px;">
                                        <option value="">Choose Resident</option>
                                        <?php foreach ($residents as $resident): ?>
                                            <option value="<?php echo $resident['id']; ?>">
                                                <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div style="padding: 0 16px 16px 16px;">
                                <div class="pending-table-container">
                                    <table class="pending-table">
                                        <thead>
                                            <tr>
                                                <th>Metric</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pendingTableBody">
                                            <tr>
                                                <td colspan="2" style="padding:20px; text-align:center; color:#7f8c8d;">
                                                    <i class="fas fa-info-circle"></i> Select a view mode to see financial summary
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // DOM elements
        const subNavButtons = document.querySelectorAll('.sub-nav-btn');
        const subContents = document.querySelectorAll('.sub-content');

        // Switch between sub-content sections
        subNavButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-target');
                
                // Update active button
                subNavButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                // Show target content
                subContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === targetId) {
                        content.classList.add('active');
                    }
                });
            });
        });

        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }, 5000);

        // Format currency function
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(amount);
        }

        // Load pending amount data
        async function loadPendingAmount(mode, residentId = null) {
            try {
                const params = new URLSearchParams({
                    action: 'get_pending_amount',
                    mode: mode
                });
                
                if (mode === 'resident' && residentId) {
                    params.append('resident_id', residentId);
                }
                
                const response = await fetch(`acc.php?${params}`, {
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success && data.data) {
                    updatePendingTable(data.data, data.mode);
                } else {
                    throw new Error(data.error || 'Failed to load pending amount data');
                }
            } catch (error) {
                console.error('Error loading pending amount:', error);
                document.getElementById('pendingTableBody').innerHTML = `
                    <tr>
                        <td colspan="2" style="padding:20px; text-align:center; color:#e74c3c;">
                            <i class="fas fa-exclamation-triangle"></i> Error loading data: ${error.message}
                        </td>
                    </tr>
                `;
            }
        }

        // Update pending table with data
        function updatePendingTable(data, mode) {
            const tbody = document.getElementById('pendingTableBody');
            
            if (mode === 'carehome') {
                // Care Home overall summary
                tbody.innerHTML = `
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Bank Income</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.total_bank_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Cash Income</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.total_cash_income)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Income</td>
                        <td style="font-weight:700;">${formatCurrency(data.total_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Expenses</td>
                        <td style="color:#e74c3c; font-weight:600;">${formatCurrency(data.total_expenses)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Drops</td>
                        <td style="color:#9b59b6; font-weight:600;">${formatCurrency(data.total_drops)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Costs</td>
                        <td style="font-weight:700; color:#e74c3c;">${formatCurrency(data.total_costs)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Current Bank Balance</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.current_bank_balance)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Current Cash Balance</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.current_cash_balance)}</td>
                    </tr>
                    <tr style="background:#e8f4fd; border-top: 2px solid #3498db;">
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">NET BALANCE</td>
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">${formatCurrency(data.net_balance)}</td>
                    </tr>
                `;
            } else if (mode === 'resident' && data) {
                // Individual resident summary
                const residentName = document.getElementById('pendingResident').options[document.getElementById('pendingResident').selectedIndex].text;
                
                tbody.innerHTML = `
                    <tr style="background:#f0f8ff;">
                        <td colspan="2" style="padding:12px; text-align:center; font-weight:700; color:#2c3e50;">
                            ${residentName}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Bank Income</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.bank_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Cash Income</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.cash_income)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Income</td>
                        <td style="font-weight:700;">${formatCurrency(data.total_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Expenses</td>
                        <td style="color:#e74c3c; font-weight:600;">${formatCurrency(data.total_expenses)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Drops</td>
                        <td style="color:#9b59b6; font-weight:600;">${formatCurrency(data.total_drops)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Costs</td>
                        <td style="font-weight:700; color:#e74c3c;">${formatCurrency(data.total_costs)}</td>
                    </tr>
                    <tr style="background:#e8f4fd; border-top: 2px solid #3498db;">
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">PENDING BALANCE</td>
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">${formatCurrency(data.pending_balance)}</td>
                    </tr>
                `;
            }
        }

        // Event listeners for pending section
        document.addEventListener('DOMContentLoaded', function() {
            const pendingMode = document.getElementById('pendingMode');
            const pendingResidentBlock = document.getElementById('pendingResidentBlock');
            const pendingResident = document.getElementById('pendingResident');
            
            if (pendingMode) {
                pendingMode.addEventListener('change', function() {
                    const mode = this.value;
                    
                    if (mode === 'resident') {
                        pendingResidentBlock.style.display = 'flex';
                        // Load data for first resident by default
                        if (pendingResident.value) {
                            loadPendingAmount('resident', pendingResident.value);
                        }
                    } else {
                        pendingResidentBlock.style.display = 'none';
                        loadPendingAmount('carehome');
                    }
                });
            }
            
            if (pendingResident) {
                pendingResident.addEventListener('change', function() {
                    if (this.value && document.getElementById('pendingMode').value === 'resident') {
                        loadPendingAmount('resident', this.value);
                    }
                });
            }
            
            // Load initial data for carehome
            <?php if ($selectedHomeId): ?>
            loadPendingAmount('carehome');
            <?php endif; ?>
        });

        // Refresh button functionality
        document.getElementById('btnRefreshData').addEventListener('click', function() {
            location.reload();
        });
    </script>
</body>
</html>