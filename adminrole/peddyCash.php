<?php
session_start();

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

function get_db_connection($dbHost, $dbUser, $dbPass, $dbName) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

// Save uploaded receipt file for petty cash transactions
function save_receipt_file($transactionId) {
    if (!isset($_FILES['receipt']) || empty($_FILES['receipt']['name'])) {
        return null;
    }
    $file = $_FILES['receipt'];
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    
    // Check file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        return null;
    }
    
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    $detected = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
    $mime = $detected ?: ($file['type'] ?? '');
    if (!in_array($mime, $allowed)) {
        return null;
    }
    
    $root = dirname(__DIR__);
    $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'petty_cash' . DIRECTORY_SEPARATOR . intval($transactionId);
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'receipt.' . ($safeExt ?: 'dat');
    
    if (@move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Return relative path for database storage
        return 'uploads/petty_cash/' . intval($transactionId) . '/receipt.' . ($safeExt ?: 'dat');
    }
    return null;
}

// Check if it's an API request
$is_api_request = isset($_GET['action']);

// Session check for API requests
if ($is_api_request) {
    if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
} else {
    // Session check for page requests
    if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../index.php");
        exit();
    }
}

// Handle API actions
if ($is_api_request) {
    header('Content-Type: application/json');
    
    $conn = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit();
    }

    // Create uploads directory if it doesn't exist
    $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0777, true);
    }
    $pettyCashDir = $uploadsDir . DIRECTORY_SEPARATOR . 'petty_cash';
    if (!is_dir($pettyCashDir)) {
        @mkdir($pettyCashDir, 0777, true);
    }

    $action = $_GET['action'];

    if ($action === 'fetch_petty_cash') {
        // Create tables if they don't exist
        $conn->query("CREATE TABLE IF NOT EXISTS pettyCash (
            id INT AUTO_INCREMENT PRIMARY KEY,
            home_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        $conn->query("CREATE TABLE IF NOT EXISTS pettyCash_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            petty_cash_id INT NOT NULL,
            home_id INT NOT NULL,
            transaction_type ENUM('add', 'use') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            description TEXT,
            proof_path VARCHAR(255) DEFAULT NULL,
            staff_name VARCHAR(255),
            transaction_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Add missing columns if they don't exist (for existing databases)
        $conn->query("ALTER TABLE pettyCash_transactions ADD COLUMN IF NOT EXISTS transaction_date DATE NULL AFTER staff_name");
        
        $query = "
            SELECT 
                h.id as home_id,
                h.name as home_name,
                pc.updated_at
            FROM homes h
            LEFT JOIN pettyCash pc ON h.id = pc.home_id
            ORDER BY h.id ASC
        ";
        
        $result = $conn->query($query);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit();
        }
        
        $pettyCash = [];
        
        while ($row = $result->fetch_assoc()) {
            $homeId = (int)$row['home_id'];
            
            // Calculate total balance across all months based on transaction_date (preferred) or created_at as fallback
            $totalBalanceQuery = "SELECT 
                                   SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                                   SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as total_balance
                                FROM pettyCash_transactions 
                                WHERE home_id = ?";
            $totalStmt = $conn->prepare($totalBalanceQuery);
            $totalStmt->bind_param('i', $homeId);
            $totalStmt->execute();
            $totalStmt->bind_result($totalBalance);
            $remainingAmount = $totalStmt->fetch() ? (float)$totalBalance : 0;
            $totalStmt->close();
            
            $pettyCash[] = [
                'homeId' => $homeId,
                'homeName' => $row['home_name'],
                'remainingAmount' => $remainingAmount,
                'lastUpdated' => $row['updated_at']
            ];
        }
        echo json_encode(['success' => true, 'data' => $pettyCash]);
        $conn->close();
        exit();
    }

    if ($action === 'fetch_home_transactions') {
        $homeId = (int)($_GET['homeId'] ?? 0);
        $month = $_GET['month'] ?? '';
        
        if ($homeId < 1) {
            echo json_encode(['success' => false, 'error' => 'Invalid home ID']);
            exit();
        }
        
        $transactions = [];
        $previousBalance = 0;
        
        if (!empty($month)) {
            // Calculate previous month balance using transaction_date
            $prevBalanceQuery = "SELECT 
                                   SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                                   SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as prev_balance
                                FROM pettyCash_transactions 
                                WHERE home_id = ? AND COALESCE(transaction_date, DATE(created_at)) < ?";
            $prevStmt = $conn->prepare($prevBalanceQuery);
            if ($prevStmt) {
                $monthStart = $month . '-01';
                $prevStmt->bind_param('is', $homeId, $monthStart);
                $prevStmt->execute();
                $prevStmt->bind_result($prevBal);
                if ($prevStmt->fetch()) {
                    $previousBalance = (float)$prevBal;
                }
                $prevStmt->close();
            }
            
            // Add previous balance as first "transaction"
            if ($previousBalance != 0) {
                $transactions[] = [
                    'type' => 'balance',
                    'amount' => $previousBalance,
                    'description' => 'Previous month balance',
                    'staffName' => 'System',
                    'createdAt' => $month . '-01 00:00:00',
                    'transactionDate' => null
                ];
            }
        }
        
        // Get actual transactions
        $query = "SELECT transaction_type, amount, description, staff_name, created_at, proof_path, transaction_date FROM pettyCash_transactions WHERE home_id = ?";
        $params = [$homeId];
        $types = 'i';
        
        if (!empty($month)) {
            $query .= " AND DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') = ?";
            $params[] = $month;
            $types .= 's';
        }
        
        $query .= " ORDER BY COALESCE(transaction_date, DATE(created_at)) DESC, created_at DESC";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        
        if (count($params) == 1) {
            $stmt->bind_param('i', $homeId);
        } else {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $stmt->bind_result($transactionType, $transAmount, $transDescription, $transStaffName, $transCreatedAt, $transProofPath, $transTransactionDate);
        
        while ($stmt->fetch()) {
            $transactions[] = [
                'type' => $transactionType,
                'amount' => (float)$transAmount,
                'description' => $transDescription,
                'staffName' => $transStaffName,
                'createdAt' => $transCreatedAt,
                'proofPath' => $transProofPath,
                'transactionDate' => $transTransactionDate
            ];
        }
        $stmt->close();
        
        // Get monthly summary based on transaction_date (preferred) or created_at as fallback
        $summaryQuery = "SELECT DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') as month, 
                               SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) as added,
                               SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as used
                        FROM pettyCash_transactions 
                        WHERE home_id = ? 
                        GROUP BY DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') 
                        ORDER BY month DESC";
        
        $summaryStmt = $conn->prepare($summaryQuery);
        if ($summaryStmt) {
            $summaryStmt->bind_param('i', $homeId);
            $summaryStmt->execute();
            $summaryStmt->bind_result($summaryMonth, $summaryAdded, $summaryUsed);
            
            $monthlySummary = [];
            while ($summaryStmt->fetch()) {
                $monthlySummary[] = [
                    'month' => $summaryMonth,
                    'added' => (float)$summaryAdded,
                    'used' => (float)$summaryUsed,
                    'net' => (float)$summaryAdded - (float)$summaryUsed
                ];
            }
            $summaryStmt->close();
        } else {
            $monthlySummary = [];
        }
        
        echo json_encode(['success' => true, 'transactions' => $transactions, 'monthlySummary' => $monthlySummary, 'previousBalance' => $previousBalance]);
        $conn->close();
        exit();
    }

        // Handle Excel export for monthly transactions
    if ($action === 'export_monthly_excel') {
        if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
            http_response_code(401);
            exit();
        }
        
        $homeId = (int)($_GET['homeId'] ?? 0);
        $month = $_GET['month'] ?? '';
        $monthName = $_GET['monthName'] ?? 'Unknown Month';
        
        if ($homeId < 1 || empty($month)) {
            http_response_code(400);
            exit('Invalid parameters');
        }
        
        $conn = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
        if (!$conn) {
            http_response_code(500);
            exit('Database connection failed');
        }
        
        $transactions = [];
        $previousBalance = 0;
        
        // Calculate previous month balance using transaction_date (preferred) or created_at as fallback
        $prevBalanceQuery = "SELECT 
                               SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                               SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as prev_balance
                            FROM pettyCash_transactions 
                            WHERE home_id = ? AND COALESCE(transaction_date, DATE(created_at)) < ?";
        $prevStmt = $conn->prepare($prevBalanceQuery);
        if ($prevStmt) {
            $monthStart = $month . '-01';
            $prevStmt->bind_param('is', $homeId, $monthStart);
            $prevStmt->execute();
            $prevStmt->bind_result($prevBal);
            if ($prevStmt->fetch()) {
                $previousBalance = (float)$prevBal;
            }
            $prevStmt->close();
        }
        
        // Add previous balance as first row
        if ($previousBalance != 0) {
            $transactions[] = [
                'Date' => 'Previous Month Balance',
                'Type' => 'BALANCE',
                'Amount' => '£' . number_format($previousBalance, 2),
                'Description' => 'Carried forward from previous month',
                'Staff' => 'System',
                'Receipt' => 'N/A'
            ];
        }
        
        // Fetch ALL monthly transactions (both add and use) based on transaction_date
        $query = "SELECT 
                    transaction_type, 
                    amount, 
                    description, 
                    staff_name, 
                    created_at, 
                    proof_path,
                    transaction_date
                  FROM pettyCash_transactions 
                  WHERE home_id = ? AND DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') = ?
                  ORDER BY COALESCE(transaction_date, DATE(created_at)) ASC, created_at ASC";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param('is', $homeId, $month);
            $stmt->execute();
            $stmt->bind_result($transactionType, $amount, $description, $staffName, $createdAt, $proofPath, $transactionDate);
            
            $totalAdded = 0;
            $totalUsed = 0;
            
            while ($stmt->fetch()) {
                // Use transaction_date if available, otherwise use created_at
                $displayDate = $transactionDate ? $transactionDate : date('Y-m-d', strtotime($createdAt));
                $transactions[] = [
                    'Date' => date('d/m/Y', strtotime($displayDate)) . ($transactionDate ? '' : ' ' . date('H:i', strtotime($createdAt))),
                    'Type' => strtoupper($transactionType),
                    'Amount' => ($transactionType === 'add' ? '+' : '-') . '£' . number_format($amount, 2),
                    'Description' => $description ?: 'N/A',
                    'Staff' => $staffName ?: 'Unknown',
                    'Receipt' => $proofPath ? 'Available' : 'No receipt'
                ];
                
                // Calculate totals for remaining balance
                if ($transactionType === 'add') {
                    $totalAdded += $amount;
                } else if ($transactionType === 'use') {
                    $totalUsed += $amount;
                }
            }
            $stmt->close();
            
            // Calculate remaining balance
            $remainingBalance = $previousBalance + $totalAdded - $totalUsed;
            
            // Add empty row for separation
            $transactions[] = [
                'Date' => '',
                'Type' => '',
                'Amount' => '',
                'Description' => '',
                'Staff' => '',
                'Receipt' => ''
            ];
            
            // Add summary rows
            $transactions[] = [
                'Date' => 'SUMMARY',
                'Type' => '',
                'Amount' => '',
                'Description' => '',
                'Staff' => '',
                'Receipt' => ''
            ];
            
            $transactions[] = [
                'Date' => 'Previous Balance',
                'Type' => '',
                'Amount' => '£' . number_format($previousBalance, 2),
                'Description' => '',
                'Staff' => '',
                'Receipt' => ''
            ];
            
            $transactions[] = [
                'Date' => 'Total Added',
                'Type' => '',
                'Amount' => '£' . number_format($totalAdded, 2),
                'Description' => '',
                'Staff' => '',
                'Receipt' => ''
            ];
            
            $transactions[] = [
                'Date' => 'Total Used',
                'Type' => '',
                'Amount' => '£' . number_format($totalUsed, 2),
                'Description' => '',
                'Staff' => '',
                'Receipt' => ''
            ];
            
            $transactions[] = [
                'Date' => 'REMAINING BALANCE',
                'Type' => '',
                'Amount' => '£' . number_format($remainingBalance, 2),
                'Description' => '',
                'Staff' => '',
                'Receipt' => ''
            ];
        }
        
        // Generate Excel file
        $filename = 'petty_cash_' . str_replace(' ', '_', $monthName) . '_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");
        
        // Add headers
        $headers = ['Date', 'Type', 'Amount', 'Description', 'Staff', 'Receipt'];
        fputcsv($output, $headers);
        
        // Add data rows
        foreach ($transactions as $transaction) {
            fputcsv($output, $transaction);
        }
        
        fclose($output);
        $conn->close();
        exit();
    }

    if ($action === 'add_petty_cash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $homeId = (int)($_POST['homeId'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? 'Money added by admin');
        $transactionDate = trim($_POST['transaction_date'] ?? '');

        if ($homeId < 1 || $amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            $conn->close();
            exit();
        }

        if (empty($transactionDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Transaction date is required']);
            $conn->close();
            exit();
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid transaction date format']);
            $conn->close();
            exit();
        }

        // Validate transaction date is not in the future
        if ($transactionDate > date('Y-m-d')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Transaction date cannot be in the future']);
            $conn->close();
            exit();
        }

        try {
            // Check if petty cash record exists, if not create one
            $check_stmt = $conn->prepare('SELECT id FROM pettyCash WHERE home_id = ?');
            if (!$check_stmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit();
            }
            $check_stmt->bind_param('i', $homeId);
            $check_stmt->execute();
            $check_stmt->bind_result($existingId);
            
            if ($check_stmt->fetch()) {
                $pettyCashId = $existingId;
                $check_stmt->close();
                
                // Update the last updated timestamp
                $update_stmt = $conn->prepare('UPDATE pettyCash SET updated_at = NOW() WHERE home_id = ?');
                if ($update_stmt) {
                    $update_stmt->bind_param('i', $homeId);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            } else {
                // Insert new record with default values
                $check_stmt->close();
                $insert_stmt = $conn->prepare('INSERT INTO pettyCash (home_id, amount, remaining_amount) VALUES (?, 0, 0)');
                if (!$insert_stmt) {
                    echo json_encode(['success' => false, 'error' => 'Insert prepare failed: ' . $conn->error]);
                    exit();
                }
                $insert_stmt->bind_param('i', $homeId);
                $insert_stmt->execute();
                $pettyCashId = $conn->insert_id;
                $insert_stmt->close();
            }

            // Add transaction record
            $trans_stmt = $conn->prepare('INSERT INTO pettyCash_transactions (petty_cash_id, home_id, transaction_type, amount, description, staff_name, transaction_date) VALUES (?, ?, "add", ?, ?, "Admin", ?)');
            if ($trans_stmt) {
                $trans_stmt->bind_param('iidss', $pettyCashId, $homeId, $amount, $description, $transactionDate);
                $trans_stmt->execute();
                $transactionId = $conn->insert_id;
                $trans_stmt->close();

                // Handle receipt upload if provided
                $receiptPath = save_receipt_file($transactionId);
                if ($receiptPath) {
                    $updateReceiptStmt = $conn->prepare('UPDATE pettyCash_transactions SET proof_path = ? WHERE id = ?');
                    if ($updateReceiptStmt) {
                        $updateReceiptStmt->bind_param('si', $receiptPath, $transactionId);
                        $updateReceiptStmt->execute();
                        $updateReceiptStmt->close();
                    }
                }
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        $conn->close();
        exit();
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    $conn->close();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petty Cash Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styles */
        body { 
            margin: 0; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f4f6f9; 
            color: #333;
        }
        
        .dashboard-container { 
            display: flex; 
            min-height: 100vh; 
        }
        
        /* Sidebar Styles */
        .sidebar { 
            width: 240px; 
            background: linear-gradient(to bottom, #2c3e50, #3498db);
            color: #fff; 
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            z-index: 100;
        }
        
        .sidebar-header { 
            padding: 20px; 
            font-size: 1.2rem; 
            background: rgba(0,0,0,0.2);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: relative;
        }
        
        .sidebar-header h2 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .sidebar-menu { 
            list-style: none; 
            margin: 0; 
            padding: 10px 0;
        }
        
        .menu-item a { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 14px 20px; 
            color: #ecf0f1; 
            text-decoration: none; 
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .menu-item.active a, 
        .menu-item a:hover { 
            background: rgba(255,255,255,0.1); 
            border-left-color: #3498db;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 101;
            background: #2c3e50;
            color: white;
            border: none;
            border-radius: 5px;
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        /* Main Content Styles */
        .main-content { 
            flex: 1; 
            padding: 20px; 
            overflow-x: hidden;
            transition: all 0.3s ease;
        }
        
        .main-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px; 
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .main-header h1 {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
            font-size: 1.8rem;
        }
        
        .user-info { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            color: #2c3e50; 
            font-weight: 500;
        }
        
        /* Button Styles */
        .btn {
            transition: all 0.3s ease;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            padding: 10px 16px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: #3498db;
            color: #fff;
        }
        
        .btn-success {
            background: #2ecc71;
            color: #fff;
        }
        
        .btn-secondary {
            background: #9b59b6;
            color: #fff;
        }
        
        .btn-warning {
            background: #f39c12;
            color: #fff;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: #fff;
        }
        
        .btn-info {
            background: #17a2b8;
            color: #fff;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .logout-btn {
            padding: 8px 15px;
            border: none;
            background: #e74c3c;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background: #c0392b;
        }
        
        /* Content Area Styles */
        .content-area {
            margin-bottom: 25px;
        }
        
        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 16px 0;
        }
        
        /* Card Styles */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            padding: 14px 18px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fa;
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 1.1rem;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .card-body {
            padding: 16px;
        }
        
        /* Table Styles */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* NEW: Mobile Table Styles */
        .mobile-table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .mobile-card {
            display: none;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 15px;
            padding: 15px;
        }
        
        .mobile-card-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .mobile-card-row:last-child {
            border-bottom: none;
        }
        
        .mobile-card-label {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .mobile-card-value {
            color: #7f8c8d;
            text-align: right;
        }
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(2px);
            padding: 15px;
            box-sizing: border-box;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            max-width: 55%;
            width: 55%;
            max-height: 100vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .modal-content1 {
            background: white;
            border-radius: 10px;
            max-width: 95%;
            width: 95%;
            max-height: 100vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8f9fa;
            border-radius: 10px 10px 0 0;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .amount {
            font-weight: 600;
            color: #27ae60;
        }
        
        .amount.zero {
            color: #e74c3c;
        }
        
        /* NEW: Notification Styles */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            max-width: 400px;
        }
        
        .notification {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            border-left: 4px solid #3498db;
        }
        
        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification.success {
            border-left-color: #2ecc71;
        }
        
        .notification.error {
            border-left-color: #e74c3c;
        }
        
        .notification.warning {
            border-left-color: #f39c12;
        }
        
        .notification-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .notification.success .notification-icon {
            color: #2ecc71;
        }
        
        .notification.error .notification-icon {
            color: #e74c3c;
        }
        
        .notification.warning .notification-icon {
            color: #f39c12;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin: 0 0 4px 0;
            color: #2c3e50;
        }
        
        .notification-message {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .notification-close {
            background: none;
            border: none;
            font-size: 1rem;
            color: #95a5a6;
            cursor: pointer;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* NEW: Loading Spinner Styles */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            backdrop-filter: blur(2px);
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #3498db;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .dashboard-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                position: fixed;
                height: 100vh;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                padding: 15px;
                margin-left: 0;
            }
            
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .main-header {
                margin-top: 60px;
            }
            
            .notification-container {
                max-width: 300px;
                right: 10px;
                top: 70px;
            }
        }
        
        @media (max-width: 768px) {
            .main-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .top-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                width: 95%;
            }
            
            /* Show mobile cards and hide table on small screens */
            .mobile-card {
                display: block;
            }
            
            .desktop-table {
                display: none;
            }
            
            .card-body {
                padding: 10px;
            }
            
            .modal-body {
                padding: 15px;
            }
            
            .modal-header {
                padding: 12px 15px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .notification-container {
                max-width: calc(100% - 20px);
                right: 10px;
                left: 10px;
            }
            
            .modal-content {
                width: 95%;
            }
            
            .table th, 
            .table td {
                padding: 8px 10px;
                font-size: 0.9rem;
            }
            
            .mobile-card {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <!-- NEW: Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- NEW: Notification Container -->
    <div class="notification-container" id="notificationContainer"></div>
    
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-home"></i> Master Admin</h2>
            </div>
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="adash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-calculator"></i> Accounts</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="response.php"><i class="fas fa-comment-dots"></i> Response</a></li>
                <li class="menu-item"><a href="responseCount.php"><i class="fas fa-chart-pie"></i> survey</a></li>
                <li class="menu-item active"><a href="peddyCash.php"><i class="fas fa-wallet"></i> Petty Cash</a></li>
                <li class="menu-item"><a href="notification.php"><i class="fas fa-bell"></i> Notification</a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1>Petty Cash Management</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span> 
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>

            <!-- Top Actions -->
            <div class="content-area">
                <div class="top-actions">
                    <button id="btnAddMoney" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Money to Home
                    </button>
                    <button id="btnRefresh" class="btn btn-info">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Petty Cash Table -->
            <div class="content-area">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-wallet"></i>
                        <h2>Petty Cash Overview</h2>
                    </div>
                    <div class="card-body">
                        <!-- Desktop Table -->
                        <div class="mobile-table-container">
                            <table class="table desktop-table">
                                <thead>
                                    <tr>
                                        <th>Home ID</th>
                                        <th>Home Name</th>
                                        <th class="text-right">Remaining Amount</th>
                                        <th class="text-center">Last Updated</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="pettyCashTableBody">
                                    <tr>
                                        <td colspan="5" class="text-center">
                                            <i class="fas fa-spinner fa-spin"></i> Loading petty cash data...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- NEW: Mobile Cards -->
                        <div id="pettyCashMobileCards">
                            <!-- Mobile cards will be generated here -->
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

<script>
// NEW: Mobile menu toggle functionality
function setupMobileMenu() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    
    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 992 && 
            !sidebar.contains(e.target) && 
            e.target !== mobileMenuToggle && 
            !mobileMenuToggle.contains(e.target)) {
            sidebar.classList.remove('active');
        }
    });
}

// NEW: Show notification function
function showNotification(title, message, type = 'info', duration = 5000) {
    const container = document.getElementById('notificationContainer');
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    
    const icon = type === 'success' ? 'fa-check-circle' : 
                 type === 'error' ? 'fa-exclamation-circle' : 
                 type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
    
    notification.innerHTML = `
        <div class="notification-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div class="notification-content">
            <h4 class="notification-title">${title}</h4>
            <p class="notification-message">${message}</p>
        </div>
        <button class="notification-close">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Auto remove after duration
    const removeNotification = () => {
        notification.classList.remove('show');
        setTimeout(() => {
            if (container.contains(notification)) {
                container.removeChild(notification);
            }
        }, 300);
    };
    
    // Close button event
    notification.querySelector('.notification-close').addEventListener('click', removeNotification);
    
    // Auto remove after duration if provided
    if (duration > 0) {
        setTimeout(removeNotification, duration);
    }
    
    return removeNotification;
}

// NEW: Show loading overlay
function showLoading() {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.id = 'loadingOverlay';
    
    overlay.innerHTML = '<div class="loading-spinner"></div>';
    document.body.appendChild(overlay);
    
    return () => {
        if (document.getElementById('loadingOverlay')) {
            document.body.removeChild(overlay);
        }
    };
}

let pettyCashData = [];

// Fetch petty cash data
async function fetchPettyCashData() {
    const hideLoading = showLoading();
    
    try {
        const res = await fetch('peddyCash.php?action=fetch_petty_cash', { 
            credentials: 'same-origin',
            headers: {
                'Cache-Control': 'no-cache'
            }
        });
        
        if (!res.ok) {
            if (res.status === 401) {
                window.location.href = '../index.php';
                return;
            }
            throw new Error(`HTTP ${res.status}`);
        }
        
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Fetch failed');
        }
        
        pettyCashData = data.data || [];
        updatePettyCashTable();
        generateMobileCards(); // NEW: Generate mobile cards
    } catch (err) {
        console.error('Petty cash fetch error:', err);
        document.getElementById('pettyCashTableBody').innerHTML = `
            <tr>
                <td colspan="5" class="text-center" style="color:#e74c3c;">
                    <i class="fas fa-exclamation-triangle"></i> Failed to load data: ${err.message}
                </td>
            </tr>`;
        
        document.getElementById('pettyCashMobileCards').innerHTML = `
            <div class="mobile-card" style="text-align:center;color:#e74c3c;">
                <i class="fas fa-exclamation-triangle"></i> Failed to load data: ${err.message}
            </div>`;
    } finally {
        hideLoading();
    }
}

// NEW: Generate mobile cards for petty cash
function generateMobileCards() {
    const container = document.getElementById('pettyCashMobileCards');
    
    if (pettyCashData.length === 0) {
        container.innerHTML = `
            <div class="mobile-card" style="text-align:center;">
                <i class="fas fa-info-circle"></i> No homes found.
            </div>`;
        return;
    }
    
    const cardsHTML = pettyCashData.map(item => {
        const isZero = item.remainingAmount <= 0;
        const lastUpdated = item.lastUpdated ? new Date(item.lastUpdated).toLocaleDateString() : 'Never';
        
        return `
            <div class="mobile-card">
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Home ID:</span>
                    <span class="mobile-card-value">${item.homeId}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Home Name:</span>
                    <span class="mobile-card-value">${item.homeName}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Remaining:</span>
                    <span class="mobile-card-value amount ${isZero ? 'zero' : ''}">£${Number(item.remainingAmount).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Last Updated:</span>
                    <span class="mobile-card-value">${lastUpdated}</span>
                </div>
                <div class="mobile-card-row" style="justify-content:center;padding-top:10px;gap:10px;">
                    <button class="btn btn-success btn-add-money" data-home-id="${item.homeId}" data-home-name="${item.homeName}" style="padding:8px 12px;">
                        <i class="fas fa-plus"></i> Add Money
                    </button>
                    <button class="btn btn-info btn-view-transactions" data-home-id="${item.homeId}" data-home-name="${item.homeName}" style="padding:8px 12px;">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = cardsHTML;
    
    // Remove existing event listeners and add new ones
    const newContainer = container.cloneNode(true);
    container.parentNode.replaceChild(newContainer, container);
    
    // Use event delegation for mobile cards
    newContainer.addEventListener('click', function(e) {
        if (e.target.closest('.btn-add-money')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.btn-add-money');
            const homeId = btn.getAttribute('data-home-id');
            const homeName = btn.getAttribute('data-home-name');
            openAddMoneyModal(homeId, homeName);
        } else if (e.target.closest('.btn-view-transactions')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.btn-view-transactions');
            const homeId = btn.getAttribute('data-home-id');
            const homeName = btn.getAttribute('data-home-name');
            openMonthlySummaryModal(homeId, homeName);
        }
    });
}

// Update petty cash table
function updatePettyCashTable() {
    const tbody = document.getElementById('pettyCashTableBody');
    
    if (pettyCashData.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center">
                    <i class="fas fa-info-circle"></i> No homes found.
                </td>
            </tr>`;
        return;
    }
    
    const rows = pettyCashData.map(item => `
        <tr>
            <td>${item.homeId}</td>
            <td>${item.homeName}</td>
            <td class="text-right amount ${item.remainingAmount <= 0 ? 'zero' : ''}">£${Number(item.remainingAmount).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
            <td class="text-center">${item.lastUpdated ? new Date(item.lastUpdated).toLocaleDateString() : 'Never'}</td>
            <td class="text-center">
                <button class="btn btn-success btn-add-money" data-home-id="${item.homeId}" data-home-name="${item.homeName}" style="margin-right:5px;">
                    <i class="fas fa-plus"></i> Add Money
                </button>
                <button class="btn btn-info btn-view-transactions" data-home-id="${item.homeId}" data-home-name="${item.homeName}">
                    <i class="fas fa-eye"></i> View
                </button>
            </td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
    
    // Remove existing event listeners and add new ones
    const newTbody = tbody.cloneNode(true);
    tbody.parentNode.replaceChild(newTbody, tbody);
    
    // Use event delegation for desktop table
    newTbody.addEventListener('click', function(e) {
        if (e.target.closest('.btn-add-money')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.btn-add-money');
            const homeId = btn.getAttribute('data-home-id');
            const homeName = btn.getAttribute('data-home-name');
            openAddMoneyModal(homeId, homeName);
        } else if (e.target.closest('.btn-view-transactions')) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.target.closest('.btn-view-transactions');
            const homeId = btn.getAttribute('data-home-id');
            const homeName = btn.getAttribute('data-home-name');
            openMonthlySummaryModal(homeId, homeName);
        }
    });
}

// Open add money modal
function openAddMoneyModal(homeId, homeName) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content1';
    
    modal.innerHTML = `
        <div class="modal-header">
            <h3 style="margin:0;font-size:1.1rem;color:#2c3e50;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-plus"></i> Add Money to ${homeName}
            </h3>
            <button type="button" id="addMoneyCloseBtn" class="btn btn-danger">Close</button>
        </div>
        <div class="modal-body">
            <form id="addMoneyForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Home Name:</label>
                    <input type="text" value="${homeName}" readonly class="form-control" style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Amount to Add:</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required class="form-control" placeholder="Enter amount">
                </div>
                <div class="form-group">
                    <label>Transaction Date:</label>
                    <input type="date" name="transaction_date" required class="form-control" value="${new Date().toISOString().split('T')[0]}">
                </div>
                <div class="form-group">
                    <label>Description (Optional):</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Enter description..."></textarea>
                </div>
                <div class="form-group">
                    <label>Receipt (Optional):</label>
                    <input type="file" name="receipt" accept="image/*,.pdf" class="form-control" style="padding:8px;">
                    <small style="color:#666;font-size:0.85rem;margin-top:4px;display:block;">Upload receipt image (JPG, PNG, GIF) or PDF. Max size: 5MB</small>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-save"></i> Add Money
                </button>
            </form>
        </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('addMoneyCloseBtn').addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
        if(e.target === overlay) {
            document.body.removeChild(overlay);
        }
    });

    document.getElementById('addMoneyForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const hideLoading = showLoading();
        
        try {
            const fd = new FormData(this);
            fd.append('homeId', homeId);
            const res = await fetch('peddyCash.php?action=add_petty_cash', { 
                method: 'POST', 
                body: fd, 
                credentials: 'same-origin' 
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Add money failed');
            
            showNotification(
                'Money Added', 
                'Money has been added successfully!', 
                'success'
            );
            
            document.body.removeChild(overlay);
            await fetchPettyCashData();
        } catch (err) {
            showNotification(
                'Add Money Failed', 
                `Failed to add money: ${err.message}`, 
                'error'
            );
        } finally {
            hideLoading();
        }
    });
}

// Initialize the page
document.addEventListener('DOMContentLoaded', () => {
    setupMobileMenu(); // NEW: Initialize mobile menu
    fetchPettyCashData();
    
    document.getElementById('btnAddMoney').addEventListener('click', () => {
        if (pettyCashData.length === 0) {
            showNotification(
                'No Homes Available', 
                'Please add homes first.', 
                'warning'
            );
            return;
        }
        // Show home selection modal
        openHomeSelectionModal();
    });
    
    document.getElementById('btnRefresh').addEventListener('click', fetchPettyCashData);
});

// Open home selection modal
function openHomeSelectionModal() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    const homeOptions = pettyCashData.map(item => 
        `<option value="${item.homeId}">${item.homeName}</option>`
    ).join('');
    
    modal.innerHTML = `
        <div class="modal-header">
            <h3 style="margin:0;font-size:1.1rem;color:#2c3e50;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-home"></i> Select Home
            </h3>
            <button id="selectHomeCloseBtn" class="btn btn-danger">Close</button>
        </div>
        <div class="modal-body">
            <form id="selectHomeForm">
                <div class="form-group">
                    <label>Select Home:</label>
                    <select name="homeId" required class="form-control">
                        <option value="">Choose a home...</option>
                        ${homeOptions}
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-arrow-right"></i> Continue
                </button>
            </form>
        </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('selectHomeCloseBtn').addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
        if(e.target === overlay) {
            document.body.removeChild(overlay);
        }
    });

    document.getElementById('selectHomeForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const homeId = this.homeId.value;
        const selectedHome = pettyCashData.find(item => item.homeId == homeId);
        if (selectedHome) {
            document.body.removeChild(overlay);
            openAddMoneyModal(selectedHome.homeId, selectedHome.homeName);
        }
    });
}

// View receipt modal
function viewReceiptModal(receiptPath) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    modal.style.maxWidth = '90vw';
    modal.style.maxHeight = '90vh';
    
    const isPdf = receiptPath.toLowerCase().includes('.pdf');
    
    modal.innerHTML = `
        <div class="modal-header">
            <h3 style="margin:0;font-size:1.2rem;color:#2c3e50;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-${isPdf ? 'file-pdf' : 'image'}"></i> Receipt
            </h3>
            <button id="receiptCloseBtn" class="btn" style="background:#e74c3c;color:white;">Close</button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 20px;">
            ${isPdf ? `
                <iframe src="/${receiptPath}" style="width: 100%; height: 70vh; border: none; border-radius: 8px;"></iframe>
            ` : `
                <img src="/${receiptPath}" alt="Receipt" style="max-width: 100%; max-height: 70vh; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            `}
        </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('receiptCloseBtn').addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
        if(e.target === overlay) {
            document.body.removeChild(overlay);
        }
    });
}

// Global variable to store current home ID for monthly summary
let currentMonthlySummaryHomeId = null;

// Open monthly summary modal
function openMonthlySummaryModal(homeId, homeName) {
    currentMonthlySummaryHomeId = homeId; // Store for later use
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content1';
    modal.style.maxWidth = '95%';
    modal.style.maxHeight = '100vh';
    
    modal.innerHTML = `
        <div class="modal-header">
            <h3 style="margin:0;font-size:1.2rem;color:#2c3e50;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-chart-bar"></i> Monthly Summary - ${homeName}
            </h3>
            <button id="summaryCloseBtn" class="btn btn-danger">Close</button>
        </div>
        <div class="modal-body" style="padding: 25px;">
            <div id="summaryContentSection" style="max-height:500px;overflow-y:auto;">
                <div class="text-center" style="padding:40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#3498db;"></i>
                    <p style="margin-top:15px;color:#7f8c8d;">Loading monthly summary...</p>
                </div>
            </div>
        </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Close button event
    document.getElementById('summaryCloseBtn').addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
    
    // Close on overlay click
    overlay.addEventListener('click', (e) => {
        if(e.target === overlay) {
            document.body.removeChild(overlay);
        }
    });

    // Load initial data
    loadMonthlySummaryData(homeId);
}

// Load monthly summary data
async function loadMonthlySummaryData(homeId, selectedMonth = '') {
    const hideLoading = showLoading();
    
    try {
        const url = `peddyCash.php?action=fetch_home_transactions&homeId=${homeId}`;
        const res = await fetch(url, { 
            credentials: 'same-origin',
            headers: {
                'Cache-Control': 'no-cache'
            }
        });
        
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Fetch failed');
        }
        
        // Display summary content
        displayMonthlySummaryInModal(data.monthlySummary, selectedMonth);
        
    } catch (err) {
        console.error('Monthly summary fetch error:', err);
        document.getElementById('summaryContentSection').innerHTML = `
            <div class="text-center" style="padding:40px;color:#e74c3c;">
                <i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i>
                <p style="margin-top:15px;">Failed to load monthly summary: ${err.message}</p>
            </div>`;
    } finally {
        hideLoading();
    }
}

// Display monthly summary in modal
function displayMonthlySummaryInModal(monthlySummary, selectedMonth) {
    // Store monthly summary data for navigation
    window.lastMonthlySummary = monthlySummary;
    
    const contentSection = document.getElementById('summaryContentSection');
    if (!contentSection || !monthlySummary || monthlySummary.length === 0) {
        if (contentSection) {
            contentSection.innerHTML = `
                <div class="text-center" style="padding:40px;color:#7f8c8d;">
                    <i class="fas fa-info-circle" style="font-size:2rem;"></i>
                    <p style="margin-top:15px;">No monthly summary data available.</p>
                </div>`;
        }
        return;
    }
    
    let summaryHtml = '';
    
    if (selectedMonth) {
        // Show detailed view for selected month
        const monthData = monthlySummary.find(s => s.month === selectedMonth);
        if (monthData) {
            const monthName = new Date(selectedMonth + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'});
            summaryHtml = `
                <div style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:white;padding:25px;border-radius:12px;margin-bottom:25px;text-align:center;">
                    <h2 style="margin:0;font-size:1.8rem;">${monthName}</h2>
                    <p style="margin:10px 0 0 0;opacity:0.9;">Monthly Financial Summary</p>
                </div>
                
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">
                    <div style="background:#d4edda;padding:20px;border-radius:10px;border-left:5px solid #28a745;">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                            <i class="fas fa-plus-circle" style="font-size:1.5rem;color:#28a745;"></i>
                            <h4 style="margin:0;color:#155724;">Money Added</h4>
                        </div>
                        <p style="font-size:1.5rem;font-weight:bold;margin:0;color:#155724;">£${Number(monthData.added).toLocaleString(undefined,{minimumFractionDigits:2})}</p>
                    </div>
                    
                    <div style="background:#f8d7da;padding:20px;border-radius:10px;border-left:5px solid #dc3545;">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                            <i class="fas fa-minus-circle" style="font-size:1.5rem;color:#dc3545;"></i>
                            <h4 style="margin:0;color:#721c24;">Money Used</h4>
                        </div>
                        <p style="font-size:1.5rem;font-weight:bold;margin:0;color:#721c24;">£${Number(monthData.used).toLocaleString(undefined,{minimumFractionDigits:2})}</p>
                    </div>
                    
                    <div style="background:#cce5ff;padding:20px;border-radius:10px;border-left:5px solid #007bff;">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                            <i class="fas fa-calculator" style="font-size:1.5rem;color:#007bff;"></i>
                            <h4 style="margin:0;color:#004085;">Net Change</h4>
                        </div>
                        <p style="font-size:1.5rem;font-weight:bold;margin:0;color:#004085;">£${Number(monthData.net).toLocaleString(undefined,{minimumFractionDigits:2})}</p>
                    </div>
                </div>
                
                <div style="background:#f8f9fa;padding:20px;border-radius:10px;margin-top:20px;border:1px solid #dee2e6;">
                    <h5 style="margin:0 0 15px 0;color:#495057;">
                        <i class="fas fa-info-circle"></i> Month Summary
                    </h5>
                    <p style="margin:0;color:#6c757d;line-height:1.6;">
                        During ${monthName}, £${Number(monthData.added).toLocaleString(undefined,{minimumFractionDigits:2})} was added to petty cash and 
                        £${Number(monthData.used).toLocaleString(undefined,{minimumFractionDigits:2})} was used for expenses, resulting in a 
                        ${monthData.net >= 0 ? 'net increase' : 'net decrease'} of £${Number(Math.abs(monthData.net)).toLocaleString(undefined,{minimumFractionDigits:2})}.
                    </p>
                </div>`;
        } else {
            summaryHtml = `
                <div class="text-center" style="padding:40px;color:#dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i>
                    <p style="margin-top:15px;">No data found for the selected month.</p>
                </div>`;
        }
    } else {
        // Show overview of all months
        summaryHtml = `
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:15px;">`;
        
        let runningBalance = 0;
        monthlySummary.slice().reverse().forEach((summary, index) => {
            const monthStart = runningBalance;
            runningBalance += summary.net;
            const monthName = new Date(summary.month + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'short'});
            
            summaryHtml += `
                <div style="background:white;border:1px solid #dee2e6;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,0.1);transition:transform 0.2s;cursor:pointer;" onclick="showMonthlyTransactionDetails('${summary.month}', '${monthName}');">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h5 style="margin:0;color:#2c3e50;font-weight:600;">${monthName}</h5>
<button 
  style="
    padding: 5px 10px;
    font-size: 16px;
    background-color: #4CAF50;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.3s ease;
  "
  onmouseover="this.style.backgroundColor='#45a049'"
  onmouseout="this.style.backgroundColor='#4CAF50'"
>
  View Full Record
</button>
                    </div>
                    
                    <div style="margin-bottom:8px;">
                        <small style="color:#6c757d;">Previous Balance:</small>
                        <span style="font-weight:600;color:#495057;">£${Number(monthStart).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
                    </div>
                    
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                        <span style="color:#28a745;">Added: £${Number(summary.added).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
                        <span style="color:#dc3545;">Used: £${Number(summary.used).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
                    </div>
                    
                    <div style="border-top:1px solid #dee2e6;padding-top:8px;">
                        <small style="color:#6c757d;">End Balance:</small>
                        <span style="font-weight:bold;color:#007bff;font-size:1.1rem;">£${Number(runningBalance).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
                    </div>
                </div>`;
        });
        
        summaryHtml += `</div>
            <div style="background:#e9ecef;padding:15px;border-radius:8px;margin-top:20px;text-align:center;">
                <small style="color:#6c757d;">
                    <i class="fas fa-info-circle"></i> Click on any month card above to view detailed transaction history
                </small>
            </div>`;
    }
    
    contentSection.innerHTML = summaryHtml;
}

// Show monthly transaction details
function showMonthlyTransactionDetails(selectedMonth, monthName) {
    const contentSection = document.getElementById('summaryContentSection');
    if (!contentSection) return;
    
    // Show loading state
    contentSection.innerHTML = `
        <div class="text-center" style="padding:40px;">
            <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:#3498db;"></i>
            <p style="margin-top:15px;color:#7f8c8d;">Loading ${monthName} transaction details...</p>
        </div>`;
    
    // Load transaction details for the specific month
    loadMonthlyTransactionDetails(currentMonthlySummaryHomeId, selectedMonth, monthName);
}

// Load monthly transaction details
async function loadMonthlyTransactionDetails(homeId, selectedMonth, monthName) {
    const hideLoading = showLoading();
    
    try {
        const url = `peddyCash.php?action=fetch_home_transactions&homeId=${homeId}&month=${selectedMonth}`;
        const res = await fetch(url, { 
            credentials: 'same-origin',
            headers: {
                'Cache-Control': 'no-cache'
            }
        });
        
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Fetch failed');
        }
        
        // Store transaction data globally for pagination
        window.lastTransactionData = {
            transactions: data.transactions,
            selectedMonth: selectedMonth,
            monthName: monthName
        };
        
        // Display transaction details
        displayMonthlyTransactionDetails(data.transactions, selectedMonth, monthName, 1);
        
    } catch (err) {
        console.error('Monthly transaction details fetch error:', err);
        document.getElementById('summaryContentSection').innerHTML = `
            <div class="text-center" style="padding:40px;color:#e74c3c;">
                <i class="fas fa-exclamation-triangle" style="font-size:2rem;"></i>
                <p style="margin-top:15px;">Failed to load transaction details: ${err.message}</p>
                <button onclick="displayMonthlySummaryInModal(window.lastMonthlySummary)" class="btn btn-secondary" style="margin-top:15px;">
                    <i class="fas fa-arrow-left"></i> Back to Summary
                </button>
            </div>`;
    } finally {
        hideLoading();
    }
}

// Display monthly transaction details
function displayMonthlyTransactionDetails(transactions, selectedMonth, monthName, currentPage = 1) {
    const itemsPerPage = 10;
    const totalItems = transactions ? transactions.length : 0;
    const totalPages = Math.ceil(totalItems / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = Math.min(startIndex + itemsPerPage, totalItems);
    const paginatedTransactions = totalItems > 0 ? transactions.slice(startIndex, endIndex) : [];
    
    const contentSection = document.getElementById('summaryContentSection');
    if (!contentSection) return;
    
    let detailsHtml = `
        <style>
  /* Responsive layout for mobile */
  @media (max-width: 768px) {
    .transaction-header {
      flex-direction: column !important;
      align-items: flex-start !important;
      gap: 15px !important;
    }
    .transaction-header .btn-container {
      display: flex !important;
      flex-direction: column !important;
      gap: 10px !important;
      width: 100% !important;
    }
    .transaction-header .btn-container button {
      width: 100% !important;
    }
  }
</style>

<div style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:white;padding:20px;border-radius:12px;margin-bottom:25px;">
  <div class="transaction-header" style="display:flex;justify-content:space-between;align-items:center;">
    <!-- Text -->
    <div>
      <h2 style="margin:0;font-size:1.6rem;">${monthName} Transactions</h2>
      <p style="margin:8px 0 0 0;opacity:0.9;">Showing ${totalItems > 0 ? startIndex + 1 : 0}-${endIndex} of ${totalItems} transactions</p>
    </div>

    <!-- Buttons -->
    <div class="btn-container" style="display:flex;gap:10px;">
      <button onclick="downloadMonthlyExcel('${selectedMonth}', '${monthName}')" class="btn" style="background:#28a745;color:white;border:1px solid #28a745;padding:8px 15px;">
        <i class="fas fa-file-excel"></i> Download Excel
      </button>
      <button onclick="displayMonthlySummaryInModal(window.lastMonthlySummary)" class="btn" style="background:rgba(255,255,255,0.2);color:white;border:1px solid rgba(255,255,255,0.3);padding:8px 15px;">
        <i class="fas fa-arrow-left"></i> Back to Summary
      </button>
    </div>
  </div>
</div>

        </div>`;
    
    if (!transactions || transactions.length === 0) {
        detailsHtml += `
            <div class="text-center" style="padding:40px;color:#7f8c8d;">
                <i class="fas fa-info-circle" style="font-size:2rem;"></i>
                <p style="margin-top:15px;">No transactions found for ${monthName}.</p>
            </div>`;
    } else {
        detailsHtml += `
            <div style="background:white;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
                <div style="overflow-x:auto;">
                    <table class="table" style="margin:0;">
                        <thead style="background:#f8f9fa;">
                            <tr>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;">Transaction Date</th>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;">Updated Date</th>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;">Type</th>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;text-align:right;">Amount</th>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;">Description</th>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;">Staff</th>
                                <th style="padding:15px;border:none;font-weight:600;color:#495057;text-align:center;">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>`;
        
        paginatedTransactions.forEach((transaction, index) => {
            const isEven = index % 2 === 0;
            const rowBg = isEven ? '#ffffff' : '#f8f9fa';
            
            if (transaction.type === 'balance') {
                detailsHtml += `
                    <tr style="background:#e8f4fd;border-left:4px solid #3498db;">
                        <td style="padding:12px 15px;border:none;">N/A</td>
                        <td style="padding:12px 15px;border:none;">${new Date(transaction.createdAt).toLocaleDateString()}</td>
                        <td style="padding:12px 15px;border:none;">
                            <span style="padding:4px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;background:#3498db;color:white;">
                                BALANCE
                            </span>
                        </td>
                        <td style="padding:12px 15px;border:none;text-align:right;font-weight:600;color:#3498db;">
                            £${Number(transaction.amount).toLocaleString(undefined,{minimumFractionDigits:2})}
                        </td>
                        <td style="padding:12px 15px;border:none;font-weight:600;">${transaction.description}</td>
                        <td style="padding:12px 15px;border:none;">${transaction.staffName}</td>
                        <td style="padding:12px 15px;border:none;text-align:center;">
                            <span style="color: #999; font-style: italic;">N/A</span>
                        </td>
                    </tr>`;
            } else {
                detailsHtml += `
                    <tr style="background:${rowBg};">
                        <td style="padding:12px 15px;border:none;">${transaction.transactionDate ? new Date(transaction.transactionDate).toLocaleDateString() : 'N/A'}</td>
                        <td style="padding:12px 15px;border:none;">${new Date(transaction.createdAt).toLocaleDateString()}</td>
                        <td style="padding:12px 15px;border:none;">
                            <span style="padding:4px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;background:${transaction.type === 'add' ? '#d4edda' : '#f8d7da'};color:${transaction.type === 'add' ? '#155724' : '#721c24'};">
                                ${transaction.type === 'add' ? 'ADDED' : 'USED'}
                            </span>
                        </td>
                        <td style="padding:12px 15px;border:none;text-align:right;font-weight:600;color:${transaction.type === 'add' ? '#27ae60' : '#e74c3c'};">
                            ${transaction.type === 'add' ? '+' : '-'}£${Number(transaction.amount).toLocaleString(undefined,{minimumFractionDigits:2})}
                        </td>
                        <td style="padding:12px 15px;border:none;">${transaction.description || '-'}</td>
                        <td style="padding:12px 15px;border:none;">${transaction.staffName || '-'}</td>
                        <td style="padding:12px 15px;border:none;text-align:center;">
                            ${transaction.proofPath ? `
                                <button onclick="viewReceiptModal('${transaction.proofPath}')" style="display: inline-flex; align-items: center; gap: 4px; color: white; background: #3498db; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                                    <i class="fas fa-${transaction.proofPath.toLowerCase().includes('.pdf') ? 'file-pdf' : 'image'}"></i> View
                                </button>
                            ` : '<span style="color: #999; font-style: italic;">No receipt</span>'}
                        </td>
                    </tr>`;
            }
        });
        
        detailsHtml += `
                        </tbody>
                    </table>
                </div>
            </div>`;
        
        // Add pagination controls if needed (before summary)
        if (totalPages > 1) {
            detailsHtml += `
                <div style="display:flex;justify-content:center;align-items:center;gap:10px;margin:25px 0;padding:20px;">
                    <button onclick="displayMonthlyTransactionDetails(window.lastTransactionData.transactions, '${selectedMonth}', '${monthName}', ${Math.max(1, currentPage - 1)})" 
                            ${currentPage === 1 ? 'disabled' : ''} 
                            style="padding:8px 12px;border:1px solid #ddd;background:${currentPage === 1 ? '#f8f9fa' : 'white'};color:${currentPage === 1 ? '#999' : '#333'};border-radius:4px;cursor:${currentPage === 1 ? 'not-allowed' : 'pointer'};">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    
                    <div style="display:flex;gap:5px;">`;
            
            // Page numbers
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                detailsHtml += `
                    <button onclick="displayMonthlyTransactionDetails(window.lastTransactionData.transactions, '${selectedMonth}', '${monthName}', 1)" 
                            style="padding:8px 12px;border:1px solid #ddd;background:white;color:#333;border-radius:4px;cursor:pointer;">1</button>`;
                if (startPage > 2) {
                    detailsHtml += `<span style="padding:8px 4px;color:#999;">...</span>`;
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                detailsHtml += `
                    <button onclick="displayMonthlyTransactionDetails(window.lastTransactionData.transactions, '${selectedMonth}', '${monthName}', ${i})" 
                            style="padding:8px 12px;border:1px solid #ddd;background:${i === currentPage ? '#007bff' : 'white'};color:${i === currentPage ? 'white' : '#333'};border-radius:4px;cursor:pointer;font-weight:${i === currentPage ? 'bold' : 'normal'};">
                        ${i}
                    </button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    detailsHtml += `<span style="padding:8px 4px;color:#999;">...</span>`;
                }
                detailsHtml += `
                    <button onclick="displayMonthlyTransactionDetails(window.lastTransactionData.transactions, '${selectedMonth}', '${monthName}', ${totalPages})" 
                            style="padding:8px 12px;border:1px solid #ddd;background:white;color:#333;border-radius:4px;cursor:pointer;">${totalPages}</button>`;
            }
            
            detailsHtml += `
                    </div>
                    
                    <button onclick="displayMonthlyTransactionDetails(window.lastTransactionData.transactions, '${selectedMonth}', '${monthName}', ${Math.min(totalPages, currentPage + 1)})" 
                            ${currentPage === totalPages ? 'disabled' : ''} 
                            style="padding:8px 12px;border:1px solid #ddd;background:${currentPage === totalPages ? '#f8f9fa' : 'white'};color:${currentPage === totalPages ? '#999' : '#333'};border-radius:4px;cursor:${currentPage === totalPages ? 'not-allowed' : 'pointer'};">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>`;
        }
        
        // Add summary statistics
        const addedTotal = transactions.filter(t => t.type === 'add').reduce((sum, t) => sum + t.amount, 0);
        const usedTotal = transactions.filter(t => t.type === 'use').reduce((sum, t) => sum + t.amount, 0);
        const currentMonthNet = addedTotal - usedTotal;
        
        // Get previous month balance from balance transaction
        const previousBalance = transactions.find(t => t.type === 'balance');
        const previousBalanceAmount = previousBalance ? previousBalance.amount : 0;
        
        // Net Change = Previous Month Balance + Current Month Net
        const netChange = previousBalanceAmount + currentMonthNet;
        
        detailsHtml += `
            <div style="background:#f8f9fa;padding:20px;border-radius:10px;margin-top:20px;border:1px solid #dee2e6;">
                <h5 style="margin:0 0 15px 0;color:#495057;">
                    <i class="fas fa-calculator"></i> ${monthName} Summary
                </h5>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
                    <div>
                        <small style="color:#6c757d;">Previous Month Balance:</small>
                        <div style="font-size:1.2rem;font-weight:bold;color:#007bff;">£${Number(previousBalanceAmount).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
                    </div>
                    <div>
                        <small style="color:#6c757d;">Total Added:</small>
                        <div style="font-size:1.2rem;font-weight:bold;color:#28a745;">£${Number(addedTotal).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
                    </div>
                    <div>
                        <small style="color:#6c757d;">Total Used:</small>
                        <div style="font-size:1.2rem;font-weight:bold;color:#dc3545;">£${Number(usedTotal).toLocaleString(undefined,{minimumFractionDigits:2})}</div>
                    </div>
                    <div>
                        <small style="color:#6c757d;">Net Change:</small>
                        <div style="font-size:1.2rem;font-weight:bold;color:${netChange >= 0 ? '#28a745' : '#dc3545'};">
                            ${netChange >= 0 ? '+' : ''}£${Number(netChange).toLocaleString(undefined,{minimumFractionDigits:2})}
                        </div>
                        <small style="color:#999;font-size:0.85rem;">Previous Balance + Current Month Net</small>
                    </div>
                    <div>
                        <small style="color:#6c757d;">Total Transactions:</small>
                        <div style="font-size:1.2rem;font-weight:bold;color:#007bff;">${transactions.filter(t => t.type !== 'balance').length}</div>
                    </div>
                </div>
            </div>`;
    }
    
    contentSection.innerHTML = detailsHtml;
}

// Download monthly transaction details as Excel
function downloadMonthlyExcel(selectedMonth, monthName) {
    if (!window.lastTransactionData || !window.lastTransactionData.transactions) {
        alert('No transaction data available for download.');
        return;
    }
    
    const currentHomeId = currentMonthlySummaryHomeId;
    const url = `peddyCash.php?action=export_monthly_excel&homeId=${currentHomeId}&month=${selectedMonth}&monthName=${encodeURIComponent(monthName)}`;
    
    // Create a temporary link element and trigger download
    const link = document.createElement('a');
    link.href = url;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>