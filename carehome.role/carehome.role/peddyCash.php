<?php
session_start();

date_default_timezone_set('Asia/Kolkata');

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

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Check if it's an API request
$is_api_request = isset($_GET['action']);

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
    $staffUsername = $_SESSION['username'];

    if ($action === 'fetch_staff_petty_cash') {
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
        $conn->query("ALTER TABLE pettyCash_transactions ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) DEFAULT NULL AFTER description");
        $conn->query("ALTER TABLE pettyCash_transactions ADD COLUMN IF NOT EXISTS transaction_date DATE NULL AFTER staff_name");

        // Get staff's home ID
        $homeStmt = $conn->prepare('SELECT id, name FROM homes WHERE user_name = ?');
        if (!$homeStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $homeStmt->bind_param('s', $staffUsername);
        $homeStmt->execute();
        $homeStmt->bind_result($homeId, $homeName);
        
        if (!$homeStmt->fetch()) {
            $homeStmt->close();
            echo json_encode(['success' => false, 'error' => 'Home not found for this staff member']);
            exit();
        }
        $homeStmt->close();
        
        // Get petty cash data
        $pettyCashStmt = $conn->prepare('SELECT remaining_amount, amount, updated_at FROM pettyCash WHERE home_id = ?');
        if (!$pettyCashStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $pettyCashStmt->bind_param('i', $homeId);
        $pettyCashStmt->execute();
        $pettyCashStmt->bind_result($remainingAmount, $totalAdded, $lastUpdated);
        
        $pettyCashData = [
            'homeId' => $homeId,
            'homeName' => $homeName,
            'remainingAmount' => 0,
            'totalAdded' => 0,
            'lastUpdated' => null
        ];
        
        if ($pettyCashStmt->fetch()) {
            $pettyCashData['remainingAmount'] = (float)$remainingAmount;
            $pettyCashData['totalAdded'] = (float)$totalAdded;
            $pettyCashData['lastUpdated'] = $lastUpdated;
        }
        $pettyCashStmt->close();
        
        echo json_encode(['success' => true, 'data' => $pettyCashData]);
        $conn->close();
        exit();
    }

    if ($action === 'use_petty_cash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $transactionDate = trim($_POST['transaction_date'] ?? '');

        if ($amount <= 0 || empty($description) || empty($transactionDate)) {
            echo json_encode(['success' => false, 'error' => 'Amount, description and transaction date are required']);
            exit();
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
            echo json_encode(['success' => false, 'error' => 'Invalid transaction date format']);
            exit();
        }

        // Validate transaction date is not in the future
        if ($transactionDate > date('Y-m-d')) {
            echo json_encode(['success' => false, 'error' => 'Transaction date cannot be in the future']);
            exit();
        }

        // Get staff's home ID
        $homeStmt = $conn->prepare('SELECT id FROM homes WHERE user_name = ?');
        if (!$homeStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $homeStmt->bind_param('s', $staffUsername);
        $homeStmt->execute();
        $homeStmt->bind_result($homeId);
        
        if (!$homeStmt->fetch()) {
            $homeStmt->close();
            echo json_encode(['success' => false, 'error' => 'Home not found']);
            exit();
        }
        $homeStmt->close();

        try {
            // Calculate available balance up to the transaction date (inclusive)
            // This means: Total added up to transaction_date - Total used up to transaction_date (excluding current transaction)
            
            // Get balance up to and including transaction date
            $balanceUpToDateQuery = "SELECT 
                                       SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) as total_added,
                                       SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as total_used
                                    FROM pettyCash_transactions 
                                    WHERE home_id = ? AND COALESCE(transaction_date, DATE(created_at)) <= ?";
            $balanceStmt = $conn->prepare($balanceUpToDateQuery);
            $balanceStmt->bind_param('is', $homeId, $transactionDate);
            $balanceStmt->execute();
            $balanceStmt->bind_result($totalAdded, $totalUsed);
            $balanceStmt->fetch();
            $totalAdded = (float)($totalAdded ?? 0);
            $totalUsed = (float)($totalUsed ?? 0);
            $balanceStmt->close();
            
            // Calculate available balance up to this date
            $availableBalanceUpToDate = $totalAdded - $totalUsed;
            
            // Check if sufficient balance available up to this transaction date
            if ($availableBalanceUpToDate < $amount) {
                echo json_encode(['success' => false, 'error' => 'Insufficient petty cash balance up to ' . $transactionDate . '. Available: £' . number_format($availableBalanceUpToDate, 2) . '. Total added up to date: £' . number_format($totalAdded, 2) . ', Total used up to date: £' . number_format($totalUsed, 2)]);
                exit();
            }
            
            // Get petty cash record for updating (we still need to update the main record)
            $checkStmt = $conn->prepare('SELECT id, remaining_amount FROM pettyCash WHERE home_id = ?');
            if (!$checkStmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit();
            }
            $checkStmt->bind_param('i', $homeId);
            $checkStmt->execute();
            $checkStmt->bind_result($pettyCashId, $currentBalance);
            
            if (!$checkStmt->fetch()) {
                $checkStmt->close();
                echo json_encode(['success' => false, 'error' => 'No petty cash available for this home']);
                exit();
            }
            $checkStmt->close();
            
            // Update remaining amount in main table (keep this for consistency)
            $newBalance = $currentBalance - $amount;
            $updateStmt = $conn->prepare('UPDATE pettyCash SET remaining_amount = ?, updated_at = NOW() WHERE id = ?');
            if (!$updateStmt) {
                echo json_encode(['success' => false, 'error' => 'Update prepare failed: ' . $conn->error]);
                exit();
            }
            $updateStmt->bind_param('di', $newBalance, $pettyCashId);
            $updateStmt->execute();
            $updateStmt->close();

            // Add transaction record
            $transStmt = $conn->prepare('INSERT INTO pettyCash_transactions (petty_cash_id, home_id, transaction_type, amount, description, staff_name, transaction_date) VALUES (?, ?, "use", ?, ?, ?, ?)');
            if ($transStmt) {
                $transStmt->bind_param('iidsss', $pettyCashId, $homeId, $amount, $description, $staffUsername, $transactionDate);
                $transStmt->execute();
                $transactionId = $conn->insert_id;
                $transStmt->close();

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

            // Calculate new total available balance after transaction
            $newTotalBalance = $totalAvailableBalance - $amount;
            echo json_encode(['success' => true, 'newBalance' => $newTotalBalance]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        $conn->close();
        exit();
    }

    if ($action === 'fetch_monthly_staff_data') {
        $month = $_GET['month'] ?? date('Y-m');
        
        // Get staff's home ID
        $homeStmt = $conn->prepare('SELECT id FROM homes WHERE user_name = ?');
        if (!$homeStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $homeStmt->bind_param('s', $staffUsername);
        $homeStmt->execute();
        $homeStmt->bind_result($homeId);
        
        if (!$homeStmt->fetch()) {
            $homeStmt->close();
            echo json_encode(['success' => false, 'error' => 'Home not found']);
            exit();
        }
        $homeStmt->close();
        
        // Calculate previous balance
        $prevBalanceQuery = "SELECT 
                               SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                               SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as prev_balance
                            FROM pettyCash_transactions 
                            WHERE home_id = ? AND COALESCE(transaction_date, DATE(created_at)) < ?";
        $prevStmt = $conn->prepare($prevBalanceQuery);
        $monthStart = $month . '-01';
        $prevStmt->bind_param('is', $homeId, $monthStart);
        $prevStmt->execute();
        $prevStmt->bind_result($prevBalance);
        $prevBalance = $prevStmt->fetch() ? (float)$prevBalance : 0;
        $prevStmt->close();
        
        // Get pagination parameters
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        // Get total count of transactions for pagination
        $countQuery = "SELECT COUNT(*) FROM pettyCash_transactions 
                      WHERE home_id = ? AND DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') = ?";
        $countStmt = $conn->prepare($countQuery);
        $countStmt->bind_param('is', $homeId, $month);
        $countStmt->execute();
        $countStmt->bind_result($totalTransactions);
        $countStmt->fetch();
        $countStmt->close();
        
        $totalPages = ceil($totalTransactions / $limit);
        
        // Calculate monthly totals for the entire month (not just current page)
        $monthlyTotalsQuery = "SELECT 
                                SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) as monthly_added,
                                SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as monthly_used
                              FROM pettyCash_transactions 
                              WHERE home_id = ? AND DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') = ?";
        $monthlyStmt = $conn->prepare($monthlyTotalsQuery);
        $monthlyStmt->bind_param('is', $homeId, $month);
        $monthlyStmt->execute();
        $monthlyStmt->bind_result($monthlyAdded, $monthlyUsed);
        $monthlyStmt->fetch();
        $monthlyAdded = (float)($monthlyAdded ?? 0);
        $monthlyUsed = (float)($monthlyUsed ?? 0);
        $monthlyStmt->close();
        
        $currentBalance = $prevBalance + $monthlyAdded - $monthlyUsed;
        
        // Get monthly transactions for current page
        $transQuery = "SELECT transaction_type, amount, description, staff_name, created_at, proof_path, transaction_date 
                      FROM pettyCash_transactions 
                      WHERE home_id = ? AND DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') = ? 
                      ORDER BY COALESCE(transaction_date, DATE(created_at)) DESC, created_at DESC
                      LIMIT ? OFFSET ?";
        $transStmt = $conn->prepare($transQuery);
        $transStmt->bind_param('isii', $homeId, $month, $limit, $offset);
        $transStmt->execute();
        $transStmt->bind_result($transType, $transAmount, $transDesc, $transStaff, $transDate, $proofPath, $transTransactionDate);
        
        $transactions = [];
        if ($page === 1 && $prevBalance != 0) {
            $transactions[] = [
                'type' => 'balance',
                'amount' => $prevBalance,
                'description' => 'Previous month balance',
                'staffName' => 'System',
                'createdAt' => $monthStart . ' 00:00:00',
                'proofPath' => null,
                'transactionDate' => null
            ];
        }
        
        while ($transStmt->fetch()) {
            $transactions[] = [
                'type' => $transType,
                'amount' => (float)$transAmount,
                'description' => $transDesc,
                'staffName' => $transStaff,
                'createdAt' => $transDate,
                'proofPath' => $proofPath,
                'transactionDate' => $transTransactionDate
            ];
        }
        $transStmt->close();
        
        // Get available months
        $monthsQuery = "SELECT DISTINCT DATE_FORMAT(COALESCE(transaction_date, DATE(created_at)), '%Y-%m') as month 
                       FROM pettyCash_transactions 
                       WHERE home_id = ?
                       ORDER BY month DESC";
        $monthsStmt = $conn->prepare($monthsQuery);
        $monthsStmt->bind_param('i', $homeId);
        $monthsStmt->execute();
        $monthsStmt->bind_result($availableMonth);
        
        $availableMonths = [];
        while ($monthsStmt->fetch()) {
            $availableMonths[] = $availableMonth;
        }
        $monthsStmt->close();
        
        $pettyCashData = [
            'homeId' => $homeId,
            'remainingAmount' => $currentBalance,
            'previousBalance' => $prevBalance,
            'monthlyAdded' => $monthlyAdded,
            'monthlyUsed' => $monthlyUsed
        ];
        
        echo json_encode([
            'success' => true, 
            'data' => $pettyCashData,
            'transactions' => $transactions,
            'availableMonths' => $availableMonths,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'totalTransactions' => $totalTransactions,
                'limit' => $limit
            ]
        ]);
        $conn->close();
        exit();
    }

    if ($action === 'add_petty_cash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $amount = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? 'Money added by staff');
        $transactionDate = trim($_POST['transaction_date'] ?? '');

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
            exit();
        }

        if (empty($transactionDate)) {
            echo json_encode(['success' => false, 'error' => 'Transaction date is required']);
            exit();
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate)) {
            echo json_encode(['success' => false, 'error' => 'Invalid transaction date format']);
            exit();
        }

        // Validate transaction date is not in the future
        if ($transactionDate > date('Y-m-d')) {
            echo json_encode(['success' => false, 'error' => 'Transaction date cannot be in the future']);
            exit();
        }

        // Get staff's home ID
        $homeStmt = $conn->prepare('SELECT id FROM homes WHERE user_name = ?');
        if (!$homeStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $homeStmt->bind_param('s', $staffUsername);
        $homeStmt->execute();
        $homeStmt->bind_result($homeId);
        
        if (!$homeStmt->fetch()) {
            $homeStmt->close();
            echo json_encode(['success' => false, 'error' => 'Home not found']);
            exit();
        }
        $homeStmt->close();

        try {
            // Check if petty cash record exists
            $check_stmt = $conn->prepare('SELECT id, remaining_amount, amount FROM pettyCash WHERE home_id = ?');
            if (!$check_stmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit();
            }
            $check_stmt->bind_param('i', $homeId);
            $check_stmt->execute();
            $check_stmt->bind_result($existingId, $existingRemaining, $existingAmount);
            
            if ($check_stmt->fetch()) {
                // Update existing record
                $check_stmt->close();
                $newRemaining = $existingRemaining + $amount;
                $newTotal = $existingAmount + $amount;
                
                $update_stmt = $conn->prepare('UPDATE pettyCash SET amount = ?, remaining_amount = ?, updated_at = NOW() WHERE home_id = ?');
                if (!$update_stmt) {
                    echo json_encode(['success' => false, 'error' => 'Update prepare failed: ' . $conn->error]);
                    exit();
                }
                $update_stmt->bind_param('ddi', $newTotal, $newRemaining, $homeId);
                $update_stmt->execute();
                $update_stmt->close();
                $pettyCashId = $existingId;
            } else {
                // Insert new record
                $check_stmt->close();
                $insert_stmt = $conn->prepare('INSERT INTO pettyCash (home_id, amount, remaining_amount) VALUES (?, ?, ?)');
                if (!$insert_stmt) {
                    echo json_encode(['success' => false, 'error' => 'Insert prepare failed: ' . $conn->error]);
                    exit();
                }
                $insert_stmt->bind_param('idd', $homeId, $amount, $amount);
                $insert_stmt->execute();
                $pettyCashId = $conn->insert_id;
                $insert_stmt->close();
            }

            // Add transaction record
            $trans_stmt = $conn->prepare('INSERT INTO pettyCash_transactions (petty_cash_id, home_id, transaction_type, amount, description, staff_name, transaction_date) VALUES (?, ?, "add", ?, ?, ?, ?)');
            if ($trans_stmt) {
                $trans_stmt->bind_param('iidsss', $pettyCashId, $homeId, $amount, $description, $staffUsername, $transactionDate);
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

    if ($action === 'fetch_transactions') {
        // Get staff's home ID
        $homeStmt = $conn->prepare('SELECT id FROM homes WHERE user_name = ?');
        if (!$homeStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $homeStmt->bind_param('s', $staffUsername);
        $homeStmt->execute();
        $homeStmt->bind_result($homeId);
        
        if (!$homeStmt->fetch()) {
            $homeStmt->close();
            echo json_encode(['success' => false, 'error' => 'Home not found']);
            exit();
        }
        $homeStmt->close();
        
        // Get recent transactions
        $transStmt = $conn->prepare('SELECT transaction_type, amount, description, staff_name, created_at, proof_path, transaction_date FROM pettyCash_transactions WHERE home_id = ? ORDER BY COALESCE(transaction_date, DATE(created_at)) DESC, created_at DESC LIMIT 20');
        if (!$transStmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $transStmt->bind_param('i', $homeId);
        $transStmt->execute();
        $transStmt->bind_result($transactionType, $transAmount, $transDescription, $transStaffName, $transCreatedAt, $transProofPath, $transTransactionDate);
        
        $transactions = [];
        while ($transStmt->fetch()) {
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
        $transStmt->close();
        
        echo json_encode(['success' => true, 'data' => $transactions]);
        $conn->close();
        exit();
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    $conn->close();
    exit();
}

// Get carehome information for logged-in user
function get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $username) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false;
    }
    $mysqli->set_charset('utf8mb4');

    $sql = "SELECT id, name FROM homes WHERE user_name = ? LIMIT 1";
    $carehome = null;

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($id, $name);
        
        if ($stmt->fetch()) {
            $carehome = ['id' => $id, 'name' => $name];
        }
        $stmt->close();
    }
    $mysqli->close();
    return $carehome;
}

$username1 = $_SESSION['username'];
$carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $username1);
$carehome_name = $carehome_info ? $carehome_info['name'] : 'CareHome';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Petty Cash - Care Home Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: linear-gradient(to bottom, #2c3e50, #3498db);
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
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

        /* NEW: Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 101;
            background: #2c3e50;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 5px;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 0;
            width: calc(100% - 250px);
            transition: margin-left 0.3s ease, width 0.3s ease;
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

        .content-area {
            padding: 30px;
        }

        .balance-section {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .balance-amount {
            font-size: 3.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .balance-label {
            font-size: 1.3rem;
            opacity: 0.9;
            margin-bottom: 20px;
        }

        .balance-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary {
            background: #3498db;
            color: #fff;
        }

        .btn-warning {
            background: #f39c12;
            color: #fff;
        }

        .btn-info {
            background: #17a2b8;
            color: #fff;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .card-header {
            background: linear-gradient(to right, #3498db, #2c3e50);
            color: white;
            padding: 20px 25px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 25px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .table th,
        .table td {
            padding: 15px 12px;
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

        .transaction-type {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .transaction-type.add {
            background: #d4edda;
            color: #155724;
        }

        .transaction-type.use {
            background: #f8d7da;
            color: #721c24;
        }

        .transaction-type.balance {
            background: #cce5ff;
            color: #004085;
        }

        .amount {
            font-weight: 600;
        }

        .amount.positive {
            color: #27ae60;
        }

        .amount.negative {
            color: #e74c3c;
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
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8f9fa;
            border-radius: 15px 15px 0 0;
        }

        .modal-body {
            padding: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
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
        
        /* Pagination Styles */
        .pagination-container {
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        
        .pagination-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .page-number {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .page-number:hover {
            background: #e9ecef;
        }
        
        .page-number.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .page-number.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-header h2 span, .menu-item a span {
                display: inline;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .balance-amount {
                font-size: 2.5rem;
            }
            
            .balance-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .notification-container {
                max-width: 300px;
                right: 10px;
                top: 70px;
            }
            
            .dashboard-container{
                margin-top: 60px;
            }
        }

        @media (max-width: 768px) {
            .content-area {
                padding: 20px;
            }
            
            .balance-section {
                padding: 30px 20px;
            }
            
            .balance-amount {
                font-size: 2rem;
            }
            
            .balance-label {
                font-size: 1.1rem;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .main-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .user-info {
                width: 100%;
                justify-content: space-between;
            }
            
            /* Show mobile cards and hide table on small screens */
            .mobile-card {
                display: block;
            }
            
            .desktop-table {
                display: none;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
        }

        @media (max-width: 576px) {
            .content-area {
                padding: 15px;
            }
            
            .balance-section {
                padding: 25px 15px;
                border-radius: 10px;
            }
            
            .balance-amount {
                font-size: 1.8rem;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .notification-container {
                max-width: calc(100% - 20px);
                right: 10px;
                left: 10px;
            }
            
            .mobile-card {
                padding: 12px;
            }
        }

        @media (max-width: 400px) {
            .content-area {
                padding: 10px;
            }
            
            .balance-section {
                padding: 20px 10px;
            }
            
            .balance-amount {
                font-size: 1.5rem;
            }
            
            .main-header h1 {
                font-size: 1.2rem;
            }
            
            .card-header {
                padding: 15px 20px;
                font-size: 1.1rem;
            }
            
            .user-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
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
                <h2><i class="fas fa-home"></i> <span>Care Home Admin</span></h2>
            </div>
            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="sdash.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="residents.php">
                        <i class="fas fa-users"></i>
                        <span>Residents</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="accounts.php">
                        <i class="fas fa-calculator"></i>
                        <span>Accounts</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="menu-item active">
                    <a href="peddyCash.php">
                        <i class="fas fa-wallet"></i>
                        <span>Petty Cash</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1><i class="fas fa-wallet"></i> Petty Cash Management - <?php echo htmlspecialchars($carehome_name); ?></h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <a href="../logout.php"><button class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></button></a>
                </div>
            </header>

            <div class="content-area">
                <!-- Month Filter -->
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body" style="padding:15px;">
                        <label>Filter by Month:</label>
                        <select id="monthFilter" class="form-control" style="width:200px;display:inline-block;margin-left:10px;box-sizing:border-box;">
                            <option value="">Loading...</option>
                        </select>
                    </div>
                </div>

                <!-- Balance Display -->
                <div class="balance-section">
                    <div class="balance-amount" id="balanceAmount">£0.00</div>
                    <div class="balance-label" id="balanceLabel">Available Petty Cash</div>
                </div>

                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                        <span><i class="fas fa-history"></i> Recent Transactions</span>
                        <div style="display:flex;gap:5px;">
                            <button id="btnAddMoney" class="btn btn-success" style="padding:5px 10px;font-size:12px;">
                                <i class="fas fa-plus"></i> Add Money
                            </button>
                            <button id="btnUseMoney" class="btn btn-warning" style="padding:5px 10px;font-size:12px;">
                                <i class="fas fa-minus"></i> Add Expense
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Desktop Table -->
                        <div class="mobile-table-container">
                            <table class="table desktop-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Description</th>
                                        <th>Staff</th>
                                        <th>Transaction Date</th>
                                        <th>Updated Date</th>
                                        <th>Receipt</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionsTableBody">
                                    <tr>
                                        <td colspan="6" class="text-center">
                                            <i class="fas fa-spinner fa-spin"></i> Loading transactions...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- NEW: Mobile Cards -->
                        <div id="transactionsMobileCards">
                            <!-- Mobile cards will be generated here -->
                        </div>
                        
                        <!-- Pagination Controls -->
                        <div id="paginationControls" class="pagination-container" style="margin-top: 20px; text-align: center; display: none;">
                            <div class="pagination-info" style="margin-bottom: 10px; color: #666; font-size: 0.9rem;">
                                <span id="paginationInfo">Showing 1-10 of 0 transactions</span>
                            </div>
                            <div class="pagination-buttons">
                                <button id="prevPageBtn" class="btn" style="margin-right: 10px; padding: 8px 12px; background: #f8f9fa; border: 1px solid #ddd;">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </button>
                                <span id="pageNumbers" style="margin: 0 10px;"></span>
                                <button id="nextPageBtn" class="btn" style="margin-left: 10px; padding: 8px 12px; background: #f8f9fa; border: 1px solid #ddd;">
                                    Next <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
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

let pettyCashData = {};
let currentMonth = new Date().toISOString().slice(0, 7);
let currentPage = 1;
let paginationData = null;

// NEW: Generate mobile cards for transactions
function generateMobileCards(transactions) {
    const container = document.getElementById('transactionsMobileCards');
    
    if (transactions.length === 0) {
        container.innerHTML = `
            <div class="mobile-card" style="text-align:center;">
                <i class="fas fa-info-circle"></i> No transactions found.
            </div>`;
        return;
    }
    
    const cardsHTML = transactions.map(transaction => {
        const typeClass = transaction.type === 'add' ? 'add' : 
                         transaction.type === 'use' ? 'use' : 'balance';
        const typeText = transaction.type === 'balance' ? 'BALANCE' : 
                        (transaction.type === 'add' ? 'ADDED' : 'USED');
        const amountSign = transaction.type === 'add' ? '+' : 
                          (transaction.type === 'use' ? '-' : '');
        const amountClass = transaction.type === 'add' ? 'positive' : 
                           (transaction.type === 'use' ? 'negative' : '');
        
        return `
            <div class="mobile-card">
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Type:</span>
                    <span class="mobile-card-value">
                        <span class="transaction-type ${typeClass}">${typeText}</span>
                    </span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Amount:</span>
                    <span class="mobile-card-value ${amountClass}">
                        ${amountSign}£${Number(transaction.amount).toLocaleString(undefined,{minimumFractionDigits:2})}
                    </span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Description:</span>
                    <span class="mobile-card-value">${transaction.description}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Staff:</span>
                    <span class="mobile-card-value">${transaction.staffName}</span>
                </div>
                <?php date_default_timezone_set('Asia/Kolkata'); // set your timezone ?>

                <div class="mobile-card-row">
                    <span class="mobile-card-label">Transaction Date:</span>
                    <span class="mobile-card-value">${transaction.transactionDate ? new Date(transaction.transactionDate).toLocaleDateString() : 'N/A'}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Updated Date:</span>
                    <span class="mobile-card-value">${new Date(transaction.createdAt).toLocaleString()}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">Receipt:</span>
                    <span class="mobile-card-value">
                        ${transaction.proofPath ? `
                            <button onclick="viewReceiptModal('${transaction.proofPath}')" style="display: inline-flex; align-items: center; gap: 4px; color: white; background: #3498db; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                <i class="fas fa-${transaction.proofPath.toLowerCase().includes('.pdf') ? 'file-pdf' : 'image'}"></i> View
                            </button>
                        ` : '<span style="color: #999; font-style: italic;">No receipt</span>'}
                    </span>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = cardsHTML;
}

// Fetch monthly petty cash data
async function fetchMonthlyData(page = 1) {
    const hideLoading = showLoading();
    
    try {
        const res = await fetch(`peddyCash.php?action=fetch_monthly_staff_data&month=${currentMonth}&page=${page}`, { 
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
        
        pettyCashData = data.data;
        paginationData = data.pagination;
        currentPage = data.pagination.currentPage;
        updateMonthFilter(data.availableMonths);
        updateBalanceDisplay();
        updateTransactionsTable(data.transactions || []);
        generateMobileCards(data.transactions || []); // NEW: Generate mobile cards
        updatePaginationControls(); // NEW: Update pagination controls
    } catch (err) {
        console.error('Petty cash fetch error:', err);
        showNotification(
            'Data Load Error', 
            `Failed to load petty cash data: ${err.message}`, 
            'error'
        );
    } finally {
        hideLoading();
    }
}

// Update month filter
function updateMonthFilter(availableMonths) {
    const filter = document.getElementById('monthFilter');
    filter.innerHTML = '<option value="">Current Month</option>';
    
    availableMonths.forEach(month => {
        const option = document.createElement('option');
        option.value = month;
        option.textContent = new Date(month + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'});
        if (month === currentMonth) option.selected = true;
        filter.appendChild(option);
    });
}

// Update balance display
function updateBalanceDisplay() {
    document.getElementById('balanceAmount').textContent = '£' + Number(pettyCashData.remainingAmount || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('balanceLabel').textContent = `Available Petty Cash - ${new Date(currentMonth + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'})}`;
}

// Update transactions table
function updateTransactionsTable(transactions) {
    const tbody = document.getElementById('transactionsTableBody');
    
    if (transactions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center">
                    <i class="fas fa-info-circle"></i> No transactions found.
                </td>
            </tr>`;
        return;
    }
    
    const rows = transactions.map(transaction => `
        <tr ${transaction.type === 'balance' ? 'style="background:#f0f8ff;"' : ''}>
            <td>
                <span class="transaction-type ${transaction.type}">
                    ${transaction.type === 'balance' ? 'BALANCE' : (transaction.type === 'add' ? 'ADDED' : 'USED')}
                </span>
            </td>
            <td class="
            ${transaction.type === 'add' ? 'positive' : (transaction.type === 'use' ? 'negative' : '')}">
                ${transaction.type === 'add' ? '+' : (transaction.type === 'use' ? '-' : '')}£${Number(transaction.amount).toLocaleString(undefined,{minimumFractionDigits:2})}
            </td>
            <td>${transaction.description}</td>
            <td>${transaction.staffName}</td>
            <td>${transaction.transactionDate ? new Date(transaction.transactionDate).toLocaleDateString() : 'N/A'}</td>
            <td>${new Date(transaction.createdAt).toLocaleString()}</td>
            <td>
                ${transaction.proofPath ? `
                    <button onclick="viewReceiptModal('${transaction.proofPath}')" style="display: inline-flex; align-items: center; gap: 4px; color: white; background: #3498db; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                        <i class="fas fa-${transaction.proofPath.toLowerCase().includes('.pdf') ? 'file-pdf' : 'image'}"></i> View
                    </button>
                ` : '<span style="color: #999; font-style: italic;">No receipt</span>'}
            </td>
        </tr>
    `).join('');
    
    tbody.innerHTML = rows;
    
    // Also update mobile cards
    generateMobileCards(transactions);
}

// Open add money modal
function openAddMoneyModal() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    modal.innerHTML = `
        <div class="modal-header">
            <h3 style="margin:0;font-size:1.2rem;color:#2c3e50;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-plus"></i> Add Money
            </h3>
            <button id="addMoneyCloseBtn" class="btn" style="background:#e74c3c;color:white;">Close</button>
        </div>
        <div class="modal-body">
            <form id="addMoneyForm" enctype="multipart/form-data">
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
                <button type="submit" class="btn btn-success" style="width:100%;">
                    <i class="fas fa-plus"></i> Add Money
                </button>
            </form>
        </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('addMoneyCloseBtn').addEventListener('click', () => {
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
            const res = await fetch('?action=add_petty_cash', { 
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
            currentPage = 1; // Reset to first page
            await fetchMonthlyData(1);
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

// Open use money modal
function openUseMoneyModal() {
    if (pettyCashData.remainingAmount <= 0) {
        showNotification(
            'No Funds Available', 
            'No petty cash available to use.', 
            'warning'
        );
        return;
    }
    
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    modal.innerHTML = `
        <div class="modal-header">
            <h3 style="margin:0;font-size:1.2rem;color:#2c3e50;display:flex;gap:8px;align-items:center;">
                <i class="fas fa-minus"></i> Add Expense
            </h3>
            <button id="useMoneyCloseBtn" class="btn" style="background:#e74c3c;color:white;">Close</button>
        </div>
        <div class="modal-body">
            <div style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:20px;">
                <strong>Available Balance: £${Number(pettyCashData.remainingAmount).toLocaleString(undefined,{minimumFractionDigits:2})}</strong>
            </div>
            <form id="useMoneyForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Amount to Use:</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="${pettyCashData.remainingAmount}" required class="form-control" placeholder="Enter amount">
                </div>
                <div class="form-group">
                    <label>Transaction Date:</label>
                    <input type="date" name="transaction_date" required class="form-control" value="${new Date().toISOString().split('T')[0]}" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Description:</label>
                    <textarea name="description" required class="form-control" rows="4" placeholder="Enter description of what the money was used for..."></textarea>
                </div>
                <div class="form-group">
                    <label>Receipt (Optional):</label>
                    <input type="file" name="receipt" accept="image/*,.pdf" class="form-control" style="padding:8px;">
                    <small style="color:#666;font-size:0.85rem;margin-top:4px;display:block;">Upload receipt image (JPG, PNG, GIF) or PDF. Max size: 5MB</small>
                </div>
                <button type="submit" class="btn btn-warning" style="width:100%;">
                    <i class="fas fa-minus"></i> Expense Money
                </button>
            </form>
        </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('useMoneyCloseBtn').addEventListener('click', () => {
        document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
        if(e.target === overlay) {
            document.body.removeChild(overlay);
        }
    });

    document.getElementById('useMoneyForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const hideLoading = showLoading();
        
        try {
            const fd = new FormData(this);
            const res = await fetch('peddyCash.php?action=use_petty_cash', { 
                method: 'POST', 
                body: fd, 
                credentials: 'same-origin' 
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Use money failed');
            
            showNotification(
                'Money Used', 
                'Money has been used successfully!', 
                'success'
            );
            
            document.body.removeChild(overlay);
            currentPage = 1; // Reset to first page
            await fetchMonthlyData(1);
        } catch (err) {
            showNotification(
                'Use Money Failed', 
                `Failed to use money: ${err.message}`, 
                'error'
            );
        } finally {
            hideLoading();
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
                <img src="/${receiptPath}" alt="Receiptss" style="max-width: 100%; max-height: 70vh; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
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

// Update pagination controls
function updatePaginationControls() {
    const paginationContainer = document.getElementById('paginationControls');
    const paginationInfo = document.getElementById('paginationInfo');
    const pageNumbers = document.getElementById('pageNumbers');
    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    
    if (!paginationData || paginationData.totalPages <= 1) {
        paginationContainer.style.display = 'none';
        return;
    }
    
    paginationContainer.style.display = 'block';
    
    // Update pagination info
    const start = (paginationData.currentPage - 1) * paginationData.limit + 1;
    const end = Math.min(paginationData.currentPage * paginationData.limit, paginationData.totalTransactions);
    paginationInfo.textContent = `Showing ${start}-${end} of ${paginationData.totalTransactions} transactions`;
    
    // Update page numbers
    let pageNumbersHtml = '';
    const maxVisiblePages = 5;
    let startPage = Math.max(1, paginationData.currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(paginationData.totalPages, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        pageNumbersHtml += `
            <span class="page-number ${i === paginationData.currentPage ? 'active' : ''}" 
                  onclick="goToPage(${i})">${i}</span>
        `;
    }
    pageNumbers.innerHTML = pageNumbersHtml;
    
    // Update prev/next buttons
    prevBtn.disabled = paginationData.currentPage <= 1;
    nextBtn.disabled = paginationData.currentPage >= paginationData.totalPages;
    
    prevBtn.style.opacity = prevBtn.disabled ? '0.5' : '1';
    nextBtn.style.opacity = nextBtn.disabled ? '0.5' : '1';
}

// Go to specific page
function goToPage(page) {
    if (page >= 1 && page <= paginationData.totalPages && page !== currentPage) {
        fetchMonthlyData(page);
    }
}

// Initialize the page
document.addEventListener('DOMContentLoaded', () => {
    setupMobileMenu(); // NEW: Initialize mobile menu         
    fetchMonthlyData();
    
    document.getElementById('monthFilter').addEventListener('change', function() {
        currentMonth = this.value || new Date().toISOString().slice(0, 7);
        currentPage = 1; // Reset to first page when changing month
        fetchMonthlyData(1);
    });
    
    // Pagination event listeners
    document.getElementById('prevPageBtn').addEventListener('click', () => {
        if (currentPage > 1) {
            goToPage(currentPage - 1);
        }
    });
    
    document.getElementById('nextPageBtn').addEventListener('click', () => {
        if (currentPage < paginationData.totalPages) {
            goToPage(currentPage + 1);
        }
    });
    
    document.getElementById('btnAddMoney').addEventListener('click', openAddMoneyModal);
    document.getElementById('btnUseMoney').addEventListener('click', openUseMoneyModal);
});
</script>
</body>
</html>