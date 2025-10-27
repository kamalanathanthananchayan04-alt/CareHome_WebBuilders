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

// Function to update home statistics
function updateHomeStatistics($conn, $homeId) {
    // Get actual residents count
    $residentsStmt = $conn->prepare('SELECT COUNT(*) as count FROM residents WHERE home_id = ?');
    $residentsStmt->bind_param('i', $homeId);
    $residentsStmt->execute();
    $residentsStmt->bind_result($residentsCount);
    $residentsStmt->fetch();
    $residentsStmt->close();
    
    // Update homes table with actual resident count
    $updateStmt = $conn->prepare('UPDATE homes SET residents = ?, last_action = ? WHERE id = ?');
    $lastAction = 'Statistics updated - ' . date('Y-m-d H:i:s');
    $updateStmt->bind_param('isi', $residentsCount, $lastAction, $homeId);
    $updateStmt->execute();
    $updateStmt->close();
    
    return $residentsCount;
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

    $action = $_GET['action'];

    if ($action === 'fetch_homes') {
        // Get homes with actual resident count from residents table
        $query = '
            SELECT 
                h.id, h.name, h.address, h.total_rooms, h.single_rooms, h.double_rooms, h.beds, 
                h.bank, h.cash, h.balance, h.user_name, h.user_password, h.last_action,
                COALESCE(COUNT(r.id), 0) as actual_residents
            FROM homes h 
            LEFT JOIN residents r ON h.id = r.home_id 
            GROUP BY h.id, h.name, h.address, h.total_rooms, h.single_rooms, h.double_rooms, h.beds, 
                     h.bank, h.cash, h.balance, h.user_name, h.user_password, h.last_action
            ORDER BY h.id ASC
        ';
        
        $result = $conn->query($query);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit();
        }
        
        $homes = [];
        while ($row = $result->fetch_assoc()) {
            $homes[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'address' => $row['address'],
                'totalRooms' => (int)$row['total_rooms'],
                'singleRooms' => (int)$row['single_rooms'],
                'doubleRooms' => (int)$row['double_rooms'],
                'beds' => (int)$row['beds'],
                'residents' => (int)$row['actual_residents'], // Use actual count from residents table
                'bank' => (float)$row['bank'],
                'cash' => (float)$row['cash'],
                'balance' => (float)$row['balance'],
                'userName' => $row['user_name'],
                'userPassword' => $row['user_password'],
                'lastAction' => $row['last_action'] ?? ''
            ];
        }
        echo json_encode(['success' => true, 'data' => $homes]);
        $conn->close();
        exit();
    }

    if ($action === 'insert_home' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        $name = trim($_POST['homeName'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $totalRooms = (int)($_POST['totalRooms'] ?? 0);
        $singleRooms = (int)($_POST['singleRooms'] ?? 0);
        $doubleRooms = (int)($_POST['doubleRooms'] ?? 0);
        $beds = (int)($_POST['beds'] ?? 0);
        $userName = trim($_POST['userName'] ?? '');
        $userPassword = trim($_POST['userPassword'] ?? '');
        $staffEmail = trim($_POST['staffEmail'] ?? '');

        if (empty($name) || empty($address) || $totalRooms < 1 || $beds < 1 || empty($userName) || empty($userPassword) || empty($staffEmail)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'All fields are required']);
            $conn->close();
            exit();
        }

        // Validate email format
        if (!filter_var($staffEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email format']);
            $conn->close();
            exit();
        }

        // Check if username already exists
        $check_stmt = $conn->prepare('SELECT id FROM homes WHERE user_name = ?');
        $check_stmt->bind_param('s', $userName);
        $check_stmt->execute();
        $check_stmt->bind_result($existingId);

        if ($check_stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Username already exists']);
            $check_stmt->close();
            $conn->close();
            exit();
        }
        $check_stmt->close();

        // Create home_staff table if it doesn't exist
        $createTableQuery = "CREATE TABLE IF NOT EXISTS home_staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            home_id INT NOT NULL,
            home_name VARCHAR(255) NOT NULL,
            staff_name VARCHAR(255) NOT NULL,
            staff_email VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_home_id (home_id),
            INDEX idx_staff_email (staff_email)
        )";
        $conn->query($createTableQuery);

        // Begin transaction to ensure both operations succeed
        $conn->begin_transaction();

        try {
            // Insert new home (existing logic)
            $stmt = $conn->prepare('INSERT INTO homes (name, address, total_rooms, single_rooms, double_rooms, beds, residents, bank, cash, balance, user_name, user_password, last_action) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');

            $residents = 0;
            $bank = 0.00;
            $cash = 0.00;
            $balance = 0.00;
            $lastAction = 'Home created - just now';

            $stmt->bind_param('ssiiiiidddsss',
                $name, $address, $totalRooms, $singleRooms, $doubleRooms, $beds, $residents, $bank, $cash, $balance, $userName, $userPassword, $lastAction
            );

            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }

            $insertedHomeId = $stmt->insert_id;
            $stmt->close();

            // Insert staff data into separate table
            $staffStmt = $conn->prepare('INSERT INTO home_staff (home_id, home_name, staff_name, staff_email) VALUES (?, ?, ?, ?)');
            $staffStmt->bind_param('isss', $insertedHomeId, $name, $userName, $staffEmail);

            if (!$staffStmt->execute()) {
                throw new Exception($staffStmt->error);
            }

            $staffStmt->close();

            // Commit transaction
            $conn->commit();
            $conn->close();

            echo json_encode(['success' => true, 'id' => $insertedHomeId, 'message' => 'Home and staff details saved successfully']);
            exit();

        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $conn->close();
            
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save data: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'update_home' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['homeName'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $totalRooms = (int)($_POST['totalRooms'] ?? 0);
        $singleRooms = (int)($_POST['singleRooms'] ?? 0);
        $doubleRooms = (int)($_POST['doubleRooms'] ?? 0);
        $beds = (int)($_POST['beds'] ?? 0);
        $userName = trim($_POST['userName'] ?? '');
        $userPassword = trim($_POST['userPassword'] ?? '');

        if ($id < 1 || $name === '' || $address === '' || $totalRooms < 1 || $beds < 1 || $userName === '' || $userPassword === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            $conn->close();
            exit();
        }

        $stmt = $conn->prepare('UPDATE homes SET name=?, address=?, total_rooms=?, single_rooms=?, double_rooms=?, beds=?, user_name=?, user_password=?, updated_at=NOW() WHERE id=?');
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $conn->error]);
            $conn->close();
            exit();
        }
        $stmt->bind_param('ssiiiiisi', $name, $address, $totalRooms, $singleRooms, $doubleRooms, $beds, $userName, $userPassword, $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $stmt->error]);
            $stmt->close();
            $conn->close();
            exit();
        }
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'fetch_staff') {
        // Get staff information for all homes
        $query = '
            SELECT 
                hs.id, hs.home_id, hs.home_name, hs.staff_name, hs.staff_email, 
                hs.created_at, hs.updated_at
            FROM home_staff hs
            ORDER BY hs.home_name ASC, hs.created_at DESC
        ';
        
        $result = $conn->query($query);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit();
        }
        
        $staff = [];
        while ($row = $result->fetch_assoc()) {
            $staff[] = [
                'id' => (int)$row['id'],
                'homeId' => (int)$row['home_id'],
                'homeName' => $row['home_name'],
                'staffName' => $row['staff_name'],
                'staffEmail' => $row['staff_email'],
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at']
            ];
        }
        echo json_encode(['success' => true, 'data' => $staff]);
        $conn->close();
        exit();
    }

    if ($action === 'delete_home' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid id']);
            $conn->close();
            exit();
        }

        // Begin transaction to ensure both deletions succeed
        $conn->begin_transaction();

        try {
            // Delete staff data first (if table exists)
            $staffDeleteStmt = $conn->prepare('DELETE FROM home_staff WHERE home_id = ?');
            if ($staffDeleteStmt) {
                $staffDeleteStmt->bind_param('i', $id);
                $staffDeleteStmt->execute();
                $staffDeleteStmt->close();
            }

            // Delete home record
            $stmt = $conn->prepare('DELETE FROM homes WHERE id=?');
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            // Commit transaction
            $conn->commit();
            $conn->close();
            
            echo json_encode(['success' => true, 'message' => 'Home and staff data deleted successfully']);
            exit();

        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $conn->close();
            
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'update_statistics') {
        try {
            // Get all home IDs
            $result = $conn->query('SELECT id FROM homes');
            $updated = 0;
            
            while ($row = $result->fetch_assoc()) {
                updateHomeStatistics($conn, $row['id']);
                $updated++;
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "Updated statistics for {$updated} homes",
                'updated_count' => $updated
            ]);
            $conn->close();
            exit();
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            $conn->close();
            exit();
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action or method']);
    $conn->close();
    exit();
}
?>





<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Care Home Management Dashboard</title>
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
        
        .btn-purple {
            background: #8e44ad;
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
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table th {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        
        table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eee;
        }
        
        table tr:last-child td {
            border-bottom: none;
        }
        
        table tr:hover {
            background: #f8f9fa;
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
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #2c3e50;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
            transition: border 0.3s;
            box-sizing: border-box;
        }
        
        .form-control:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        /* Report Styles */
        .report-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2c3e50;
        }
        
        .report-title {
            color: #2c3e50;
            margin: 0 0 10px 0;
        }
        
        .report-date {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .report-table th {
            background: #2c3e50;
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        .report-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .report-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .report-summary {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .summary-item {
            text-align: center;
            padding: 10px;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .summary-label {
            font-size: 0.9rem;
            color: #7f8c8d;
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
        
        /* NEW: Confirmation Dialog Styles */
        .confirmation-dialog {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-width: 450px;
            width: 90%;
            overflow: hidden;
            animation: modalFadeIn 0.3s ease;
        }
        
        .confirmation-header {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .confirmation-icon {
            font-size: 1.5rem;
            color: #e74c3c;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(231, 76, 60, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .confirmation-title {
            margin: 0;
            font-size: 1.2rem;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .confirmation-body {
            padding: 20px;
        }
        
        .confirmation-message {
            margin: 0 0 20px 0;
            color: #7f8c8d;
            line-height: 1.5;
        }
        
        .confirmation-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
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
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
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
            
            .report-summary {
                grid-template-columns: repeat(2, 1fr);
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
            
            .confirmation-dialog {
                width: 95%;
            }
            
            .confirmation-actions {
                flex-direction: column;
            }
            
            .report-summary {
                grid-template-columns: 1fr;
            }
            
            /* Show mobile cards and hide table on small screens */
            .mobile-card {
                display: block;
            }
            
            .desktop-table {
                display: none;
            }
            
            .modal-body .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
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
            
            .notification-container {
                max-width: calc(100% - 20px);
                right: 10px;
                left: 10px;
            }
            
            .report-container {
                padding: 15px;
            }
            
            .report-table th, 
            .report-table td {
                padding: 8px 10px;
                font-size: 0.9rem;
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
              <li class="menu-item active"><a href="adash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> Accounts</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="response.php"><i class="fas fa-comment-dots"></i> Response</a></li>
                <li class="menu-item"><a href="responseCount.php"><i class="fas fa-chart-pie"></i> survey</a></li>
                <li class="menu-item"><a href="peddyCash.php"><i class="fas fa-money-bill-wave"></i> Petty Cash</a></li>
                <li class="menu-item"><a href="notification.php"><i class="fas fa-bell"></i> Notification</a></li>
                
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1>Care Home Management Dashboard</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span> 
                   
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>
            
            
            <!-- Top Actions -->
            <div class="content-area">
                <div class="top-actions">
                    <button id="btnAddHome" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Home
                    </button>
                    <button id="btnViewHome" class="btn btn-success">
                        <i class="fas fa-eye"></i> View All Homes
                    </button>
                    <button id="btnViewStaff" class="btn btn-purple">
                        <i class="fas fa-users"></i> View Staff
                    </button>
                    <button id="btnRefreshStats" class="btn btn-warning">
                        <i class="fas fa-sync-alt"></i> Refresh Statistics
                    </button>
                    <button id="btnGenerateReport" class="btn btn-info">
                        <i class="fas fa-file-alt"></i> Generate Report
                    </button>
                    <button id="btnTechnicalContact" class="btn btn-secondary">
                        <i class="fas fa-headset"></i> Technical Contact
                    </button>
                </div>
            </div>

            <!-- Homes Table -->
            <div class="content-area">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-house"></i>
                        <h2>Homes Details</h2>
                    </div>
                    <div class="card-body">
                        <!-- Desktop Table -->
                        <div class="mobile-table-container">
                            <table class="residents-table desktop-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Home Name</th>
                                        <th style="text-align:right;">Bank</th>
                                        <th style="text-align:right;">Cash</th>
                                        <th style="text-align:right;">Total</th>
                                        <th style="text-align:right;">Residents</th>
                                        <th style="text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="homesTableBody">
                                    <!-- Loading row -->
                                    <tr>
                                        <td colspan="7" style="padding:20px;text-align:center;">
                                            <i class="fas fa-spinner fa-spin"></i> Loading homes...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile Cards -->
                        <div id="homesMobileCards">
                            <!-- Mobile cards will be generated here -->
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

<script>
  let allCareHomes = [];

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

  // NEW: Generate mobile cards for homes
  function generateMobileCards() {
    const container = document.getElementById('homesMobileCards');
    
    if (allCareHomes.length === 0) {
      container.innerHTML = `
        <div class="mobile-card" style="text-align:center;">
          <i class="fas fa-info-circle"></i> No homes found. Click "Add Home" to create one.
        </div>`;
      return;
    }
    
    const cardsHTML = allCareHomes.map(home => {
      const totalBalance = (parseFloat(home.bank) || 0) + (parseFloat(home.cash) || 0);
      const residents = parseInt(home.residents) || 0;
      
      return `
        <div class="mobile-card">
          <div class="mobile-card-row">
            <span class="mobile-card-label">Home Name:</span>
            <span class="mobile-card-value">${home.name}</span>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Bank:</span>
            <span class="mobile-card-value">£${Number(home.bank||0).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Cash:</span>
            <span class="mobile-card-value">£${Number(home.cash||0).toLocaleString(undefined,{minimumFractionDigits:2})}</span>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Total:</span>
            <span class="mobile-card-value" style="font-weight:600;">£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</span>
          </div>
          <div class="mobile-card-row">
            <span class="mobile-card-label">Residents:</span>
            <span class="mobile-card-value">${residents}</span>
          </div>
          <div class="mobile-card-row" style="justify-content:center;padding-top:10px;">
            <button class="btn btn-primary btnViewMore" data-home='${JSON.stringify(home)}' style="padding:8px 12px;">
              <i class="fas fa-eye"></i> View Details
            </button>
          </div>
        </div>
      `;
    }).join('');
    
    container.innerHTML = cardsHTML;
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

  // NEW: Show confirmation dialog
  function showConfirmation(title, message, confirmText = 'Confirm', cancelText = 'Cancel') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        
        const dialog = document.createElement('div');
        dialog.className = 'confirmation-dialog';
        
        dialog.innerHTML = `
            <div class="confirmation-header">
                <div class="confirmation-icon">
                    <i class="fas fa-exclamation"></i>
                </div>
                <h3 class="confirmation-title">${title}</h3>
            </div>
            <div class="confirmation-body">
                <p class="confirmation-message">${message}</p>
                <div class="confirmation-actions">
                    <button class="btn btn-secondary" id="confirmCancel">${cancelText}</button>
                    <button class="btn btn-danger" id="confirmOk">${confirmText}</button>
                </div>
            </div>
        `;
        
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        
        // Handle button clicks
        document.getElementById('confirmOk').addEventListener('click', () => {
            document.body.removeChild(overlay);
            resolve(true);
        });
        
        document.getElementById('confirmCancel').addEventListener('click', () => {
            document.body.removeChild(overlay);
            resolve(false);
        });
        
        // Close on overlay click
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                document.body.removeChild(overlay);
                resolve(false);
            }
        });
    });
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

  // Fetch homes from backend
  async function fetchHomesAndRender() {
    const hideLoading = showLoading();
    
    try {
      const res = await fetch('adash.php?action=fetch_homes', { 
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
      
      allCareHomes = data.data || [];
      console.log('Fetched homes:', allCareHomes); // Debug log
      updateMainTable();
      generateMobileCards(); // NEW: Generate mobile cards
      attachViewButtons();
    } catch (err) {
      console.error('Homes fetch error:', err);
      document.getElementById('homesTableBody').innerHTML = `
        <tr>
          <td colspan="7" style="padding:20px;text-align:center;color:#e74c3c;">
            <i class="fas fa-exclamation-triangle"></i> Failed to load homes: ${err.message}
          </td>
        </tr>`;
      
      document.getElementById('homesMobileCards').innerHTML = `
        <div class="mobile-card" style="text-align:center;color:#e74c3c;">
          <i class="fas fa-exclamation-triangle"></i> Failed to load homes: ${err.message}
        </div>`;
      
      showNotification(
        'Data Load Error', 
        `Failed to load care homes: ${err.message}`, 
        'error'
      );
    } finally {
      hideLoading();
    }
  }

  // Attach event listeners to view buttons
  function attachViewButtons() {
    document.querySelectorAll('.btnViewMore').forEach(btn => {
      btn.addEventListener('click', function() {
        try {
          const home = JSON.parse(this.getAttribute('data-home'));
          openHomeModal(home);
        } catch(e) {
          showNotification(
            'Error', 
            'Unable to open home details', 
            'error'
          );
        }
      });
    });
  }

  // View All Homes Modal
  function openAllHomesModal() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    const homeRows = allCareHomes.map(home => {
      const totalBalance = (parseFloat(home.bank) || 0) + (parseFloat(home.cash) || 0);
      return `
      <tr>
        <td>${home.id}</td>
        <td>${home.name}</td>
        <td>${home.address}</td>
        <td style="text-align:center;">${home.beds}</td>
        <td style="text-align:center;">${home.residents}</td>
        <td style="text-align:right;">£${Number(home.bank||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
        <td style="text-align:right;">£${Number(home.cash||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
        <td style="text-align:right;font-weight:600;">£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
        <td style="text-align:center;">
          <button class="btn btn-warning btnEditHome" data-home='${JSON.stringify(home)}' style="padding:6px 10px;font-size:12px;">
            <i class="fas fa-edit"></i> Edit
          </button>
          <button class="btn btn-danger btnDeleteHome" data-home='${JSON.stringify(home)}' style="padding:6px 10px;font-size:12px;">
            <i class="fas fa-trash"></i> Delete
          </button>
        </td>
      </tr>
    `}).join('');

    modal.innerHTML = `
      <div class="modal-header">
        <h3><i class="fas fa-eye"></i> All Care Homes</h3>
        <button id="allHomesCloseBtn" class="btn btn-danger" style="padding:6px 10px;">Close</button>
      </div>
      <div class="modal-body">
        <div class="mobile-table-container">
          <table style="width:100%;">
            <thead>
              <tr>
                <th>#</th>
                <th>Home Name</th>
                <th>Address</th>
                <th style="text-align:center;">Beds</th>
                <th style="text-align:center;">Residents</th>
                <th style="text-align:right;">Bank</th>
                <th style="text-align:right;">Cash</th>
                <th style="text-align:right;">Total</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${homeRows}
            </tbody>
          </table>
        </div>
      </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Close modal
    document.getElementById('allHomesCloseBtn').addEventListener('click', () => {
      document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
      if(e.target === overlay) {
        document.body.removeChild(overlay);
      }
    });

    // Add edit and delete functionality
    document.querySelectorAll('.btnEditHome').forEach(btn => {
      btn.addEventListener('click', function() {
        const home = JSON.parse(this.getAttribute('data-home'));
        openEditHomeModal(home);
        document.body.removeChild(overlay);
      });
    });

    document.querySelectorAll('.btnDeleteHome').forEach(btn => {
      btn.addEventListener('click', async function() {
        const home = JSON.parse(this.getAttribute('data-home'));
        
        // Use confirmation dialog instead of standard confirm
        const confirmed = await showConfirmation(
          'Delete Home', 
          `Are you sure you want to delete "${home.name}"? This action cannot be undone.`,
          'Delete',
          'Cancel'
        );
        
        if (confirmed) {
          await deleteHome(home.id);
          document.body.removeChild(overlay);
        }
      });
    });
  }

  // View Single Home Modal - FIXED VERSION
  function openHomeModal(home) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    // Calculate values properly
    const bank = parseFloat(home.bank) || 0;
    const cash = parseFloat(home.cash) || 0;
    const totalBalance = bank + cash;
    const residents = parseInt(home.residents) || 0;
    const beds = parseInt(home.beds) || 0;
    const vacantBeds = beds - residents;
    
    modal.innerHTML = `
      <div class="modal-header">
        <h3><i class="fas fa-house"></i> Home Details</h3>
        <button id="homeCloseBtn" class="btn btn-danger" style="padding:6px 10px;">Close</button>
      </div>
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;" class="home-details-grid">
          <div class="form-group"><strong>Home Name:</strong><br>${home.name || '-'}</div>
          <div class="form-group"><strong>Address:</strong><br>${home.address || '-'}</div>
          <div class="form-group"><strong>Total Rooms:</strong><br>${home.totalRooms || '0'}</div>
          <div class="form-group"><strong>Single Rooms:</strong><br>${home.singleRooms || '0'}</div>
          <div class="form-group"><strong>Double Rooms:</strong><br>${home.doubleRooms || '0'}</div>
          <div class="form-group"><strong>Total Beds:</strong><br>${beds}</div>
          <div class="form-group"><strong>Current Residents:</strong><br>${residents}</div>
          <div class="form-group"><strong>Vacant Beds:</strong><br>${vacantBeds}</div>
          <div class="form-group"><strong>Bank Balance:</strong><br>£${bank.toLocaleString(undefined,{minimumFractionDigits:2})}</div>
          <div class="form-group"><strong>Cash Balance:</strong><br>£${cash.toLocaleString(undefined,{minimumFractionDigits:2})}</div>
          <div class="form-group"><strong>Total Balance:</strong><br>£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</div>
          <div class="form-group"><strong>Username:</strong><br>${home.userName || '-'}</div>
          <div class="form-group"><strong>Password:</strong><br>${home.userPassword || '-'}</div>
          <div class="form-group"><strong>Last Action:</strong><br>${home.lastAction || 'No recent activity'}</div>
        </div>
      </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // NEW: Make home details grid responsive
    const detailsGrid = modal.querySelector('.home-details-grid');
    if (window.innerWidth <= 768) {
      detailsGrid.style.gridTemplateColumns = '1fr';
    }

    document.getElementById('homeCloseBtn').addEventListener('click', () => {
      document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
      if(e.target === overlay) {
        document.body.removeChild(overlay);
      }
    });
  }

  // Edit Home Modal
  function openEditHomeModal(home) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    modal.innerHTML = `
      <div class="modal-header">
        <h3><i class="fas fa-edit"></i> Edit Home</h3>
        <button id="editHomeCloseBtn" class="btn btn-danger" style="padding:6px 10px;">Close</button>
      </div>
      <div class="modal-body">
        <form id="editHomeForm">
          <div class="form-grid">
            <div class="form-group">
              <label>Home Name:</label>
              <input type="text" class="form-control" name="homeName" value="${home.name}" required>
            </div>
            <div class="form-group">
              <label>Address:</label>
              <input type="text" class="form-control" name="address" value="${home.address}" required>
            </div>
            <div class="form-group">
              <label>Total Rooms:</label>
              <input type="number" class="form-control" name="totalRooms" value="${home.totalRooms}" min="1" required>
            </div>
            <div class="form-group">
              <label>Single Rooms:</label>
              <input type="number" class="form-control" name="singleRooms" value="${home.singleRooms}" min="0" required>
            </div>
            <div class="form-group">
              <label>Double Rooms:</label>
              <input type="number" class="form-control" name="doubleRooms" value="${home.doubleRooms}" min="0" required>
            </div>
            <div class="form-group">
              <label>Total Beds:</label>
              <input type="number" class="form-control" name="beds" value="${home.beds}" min="1" required>
            </div>
            <div class="form-group">
              <label>Username:</label>
              <input type="text" class="form-control" name="userName" value="${home.userName}" required>
            </div>
            <div class="form-group">
              <label>Password:</label>
              <input type="password" class="form-control" name="userPassword" value="${home.userPassword}" required>
            </div>
          </div>
          <button type="submit" class="btn btn-warning" style="width:100%;margin-top:15px;">
            <i class="fas fa-save"></i> Update Home
          </button>
        </form>
      </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('editHomeCloseBtn').addEventListener('click', () => {
      document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
      if(e.target === overlay) {
        document.body.removeChild(overlay);
      }
    });

    document.getElementById('editHomeForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      const hideLoading = showLoading();
      
      try {
        const fd = new FormData(this);
        fd.append('id', home.id);
        const res = await fetch('adash.php?action=update_home', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Update failed');
        
        showNotification(
          'Home Updated', 
          `"${home.name}" has been updated successfully`, 
          'success'
        );
        
        document.body.removeChild(overlay);
        await fetchHomesAndRender();
      } catch (err) {
        showNotification(
          'Update Failed', 
          `Failed to update home: ${err.message}`, 
          'error'
        );
      } finally {
        hideLoading();
      }
    });
  }

  // Delete Home Function
  async function deleteHome(homeId) {
    const hideLoading = showLoading();
    
    try {
      const fd = new FormData();
      fd.append('id', homeId);
      const res = await fetch('adash.php?action=delete_home', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Delete failed');
      
      showNotification(
        'Home Deleted', 
        'The home has been deleted successfully', 
        'success'
      );
      
      await fetchHomesAndRender();
    } catch (err) {
      showNotification(
        'Delete Failed', 
        `Failed to delete home: ${err.message}`, 
        'error'
      );
    } finally {
      hideLoading();
    }
  }

  // Update main table - FIXED VERSION
  function updateMainTable() {
    const tbody = document.getElementById('homesTableBody');
    
    if (allCareHomes.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" style="padding:20px;text-align:center;">
            <i class="fas fa-info-circle"></i> No homes found. Click "Add Home" to create one.
          </td>
        </tr>`;
      return;
    }
    
    const homeRows = allCareHomes.map(home => {
      // Calculate total balance (bank + cash)
      const totalBalance = (parseFloat(home.bank) || 0) + (parseFloat(home.cash) || 0);
      const residents = parseInt(home.residents) || 0;
      
      return `
        <tr>
          <td>${home.id}</td>
          <td>${home.name}</td>
          <td style="text-align:right;">£${Number(home.bank||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td style="text-align:right;">£${Number(home.cash||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td style="text-align:right;font-weight:600;">£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td style="text-align:right;">${residents}</td>
          <td style="text-align:center;">
            <button class="btn btn-primary btnViewMore" data-home='${JSON.stringify(home)}' style="padding:8px 12px;">
              <i class="fas fa-eye"></i> View Details
            </button>
          </td>
        </tr>
      `;
    }).join('');
    
    tbody.innerHTML = homeRows;
  }

  // Add Home Modal
  function openAddHomeModal() {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    
    const modal = document.createElement('div');
    modal.className = 'modal-content';
    
    modal.innerHTML = `
      <div class="modal-header">
        <h3><i class="fas fa-plus"></i> Add New Home</h3>
        <button id="addHomeCloseBtn" class="btn btn-danger" style="padding:6px 10px;">Close</button>
      </div>
      <div class="modal-body">
        <form id="addHomeForm">
          <div class="form-grid">
            <div class="form-group">
              <label>Home Name:</label>
              <input type="text" class="form-control" name="homeName" required placeholder="Enter home name">
            </div>
            <div class="form-group">
              <label>Address:</label>
              <input type="text" class="form-control" name="address" required placeholder="Enter full address">
            </div>
            <div class="form-group">
              <label>Total Rooms:</label>
              <input type="number" class="form-control" name="totalRooms" min="1" required placeholder="Total rooms">
            </div>
            <div class="form-group">
              <label>Single Rooms:</label>
              <input type="number" class="form-control" name="singleRooms" min="0" required placeholder="Single rooms">
            </div>
            <div class="form-group">
              <label>Double Rooms:</label>
              <input type="number" class="form-control" name="doubleRooms" min="0" required placeholder="Double rooms">
            </div>
            <div class="form-group">
              <label>Total Beds:</label>
              <input type="number" class="form-control" name="beds" min="1" required placeholder="Total beds">
            </div>
            <div class="form-group">
              <label>Username:</label>
              <input type="text" class="form-control" name="userName" required placeholder="Staff username">
            </div>
            <div class="form-group">
              <label>Password:</label>
              <input type="password" class="form-control" name="userPassword" required placeholder="Staff password">
            </div>
            <div class="form-group">
              <label>Staff Email:</label>
              <input type="email" class="form-control" name="staffEmail" required placeholder="Staff email address">
            </div>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:15px;">
            <i class="fas fa-save"></i> Save Home
          </button>
        </form>
      </div>`;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.getElementById('addHomeCloseBtn').addEventListener('click', () => {
      document.body.removeChild(overlay);
    });
    
    overlay.addEventListener('click', (e) => {
      if(e.target === overlay) {
        document.body.removeChild(overlay);
      }
    });

    // Add validation for room counts
    const totalRoomsInput = modal.querySelector('input[name="totalRooms"]');
    const singleRoomsInput = modal.querySelector('input[name="singleRooms"]');
    const doubleRoomsInput = modal.querySelector('input[name="doubleRooms"]');
    
    function validateRoomCounts(showErrorOnSubmit = false) {
      const totalRooms = parseInt(totalRoomsInput.value) || 0;
      const singleRooms = parseInt(singleRoomsInput.value) || 0;
      const doubleRooms = parseInt(doubleRoomsInput.value) || 0;
      
      if (totalRooms > 0 && (singleRooms + doubleRooms) > 0) {
        if (totalRooms !== (singleRooms + doubleRooms)) {
          if (showErrorOnSubmit) {
            showNotification(
              'Validation Error',
              `Total rooms (${totalRooms}) must equal the sum of single rooms (${singleRooms}) and double rooms (${doubleRooms}). Current sum: ${singleRooms + doubleRooms}`,
              'error',
              6000
            );
          }
          return false;
        }
      }
      return true;
    }
    
    // Add event listeners for real-time validation
    [totalRoomsInput, singleRoomsInput, doubleRoomsInput].forEach(input => {
      input.addEventListener('input', function() {
        // Only validate if all fields have values
        if (totalRoomsInput.value && singleRoomsInput.value && doubleRoomsInput.value) {
          validateRoomCounts();
        }
      });
    });

    document.getElementById('addHomeForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      // Validate room counts before submitting with error message
      if (!validateRoomCounts(true)) {
        return;
      }
      
      const hideLoading = showLoading();
      
      try {
        const fd = new FormData(this);
        const res = await fetch('adash.php?action=insert_home', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        
        const data = await res.json();
        if (!data.success) {
          throw new Error(data.error || 'Failed to add home');
        }
        
        showNotification(
          'Home Added', 
          'New home has been added successfully', 
          'success'
        );
        
        document.body.removeChild(overlay);
        await fetchHomesAndRender();
      } catch (err) {
        showNotification(
          'Add Home Failed', 
          `Failed to add home: ${err.message}`, 
          'error'
        );
      } finally {
        hideLoading();
      }
    });
  }

  // Generate Report
  function generateReport() {
    if (allCareHomes.length === 0) {
      showNotification(
        'No Data',
        'No data available to generate report',
        'warning'
      );
      return;
    }

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';

    const modal = document.createElement('div');
    modal.className = 'modal-content';
    modal.style.maxWidth = '90%';

    // Calculate totals
    const totalBeds = allCareHomes.reduce((sum, home) => sum + (parseInt(home.beds) || 0), 0);
    const totalResidents = allCareHomes.reduce((sum, home) => sum + (parseInt(home.residents) || 0), 0);
    const totalBank = allCareHomes.reduce((sum, home) => sum + (parseFloat(home.bank) || 0), 0);
    const totalCash = allCareHomes.reduce((sum, home) => sum + (parseFloat(home.cash) || 0), 0);
    const totalBalance = totalBank + totalCash;

    // Build report rows (no ID column)
    const reportRows = allCareHomes.map(home => {
      const totalHomeBalance = (parseFloat(home.bank) || 0) + (parseFloat(home.cash) || 0);
      const residents = parseInt(home.residents) || 0;
      const beds = parseInt(home.beds) || 0;
      
      return `
        <tr>
          <td>${home.name}</td>
          <td>${home.address}</td>
          <td>${home.totalRooms || 0}</td>
          <td>${home.singleRooms || 0}</td>
          <td>${home.doubleRooms || 0}</td>
          <td>${beds}</td>
          <td>${residents}</td>
          <td>${beds - residents}</td>
          <td>£${Number(home.bank||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td>£${Number(home.cash||0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td>£${totalHomeBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
        </tr>
      `;
    }).join('');

    modal.innerHTML = `
      <div class="modal-header">
        <h3><i class="fas fa-file-alt"></i> Care Homes Report</h3>
        <div>
          <button id="printReport" class="btn btn-primary" style="padding:6px 10px;margin-right:8px;">
            <i class="fas fa-print"></i> Print
          </button>
          <button id="closeReport" class="btn btn-danger" style="padding:6px 10px;">Close</button>
        </div>
      </div>
      <div class="modal-body">
        <div class="report-container">
          <div class="report-header">
            <h2 class="report-title">Care Homes Management Report</h2>
            <div class="report-date">Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</div>
          </div>

          <div class="report-summary">
            <div class="summary-item">
              <div class="summary-value">${allCareHomes.length}</div>
              <div class="summary-label">Total Homes</div>
            </div>
            <div class="summary-item">
              <div class="summary-value">${totalBeds}</div>
              <div class="summary-label">Total Beds</div>
            </div>
            <div class="summary-item">
              <div class="summary-value">${totalResidents}</div>
              <div class="summary-label">Total Residents</div>
            </div>
            <div class="summary-item">
              <div class="summary-value">${totalBeds - totalResidents}</div>
              <div class="summary-label">Vacant Beds</div>
            </div>
            <div class="summary-item">
              <div class="summary-value">£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</div>
              <div class="summary-label">Total Balance</div>
            </div>
          </div>

          <div class="mobile-table-container" style="margin-top:20px;">
            <table class="report-table" style="width:100%; table-layout:auto;">
              <thead>
                <tr>
                  <th>Home Name</th>
                  <th>Address</th>
                  <th>Total Rooms</th>
                  <th>Single Rooms</th>
                  <th>Double Rooms</th>
                  <th>Beds</th>
                  <th>Residents</th>
                  <th>Vacant</th>
                  <th>Bank</th>
                  <th>Cash</th>
                  <th>Total Balance</th>
                </tr>
              </thead>
              <tbody>
                ${reportRows}
              </tbody>
              <tfoot>
                <tr style="background:#2c3e50; color:white; font-weight:bold;">
                  <td colspan="5" style="text-align:right;">TOTALS:</td>
                  <td>${totalBeds}</td>
                  <td>${totalResidents}</td>
                  <td>${totalBeds - totalResidents}</td>
                  <td>£${totalBank.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td>£${totalCash.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                  <td>£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>`;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Print functionality
    document.getElementById('printReport').addEventListener('click', () => {
      const reportContent = modal.querySelector('.report-container').innerHTML;
      const printWindow = window.open('', '_blank');
      printWindow.document.write(`
        <html>
          <head>
            <title>Care Homes Report</title>
            <style>
              body { font-family: Arial, sans-serif; margin: 20px; }
              .report-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #2c3e50; padding-bottom: 15px; }
              .report-title { color: #2c3e50; margin: 0 0 10px 0; }
              .report-date { color: #7f8c8d; font-size: 14px; }
              .report-table { width: 100%; border-collapse: collapse; margin-top: 20px; table-layout: auto; }
              .report-table th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
              .report-table td { padding: 8px 10px; border-bottom: 1px solid #eee; }
              .report-table tr:nth-child(even) { background: #f8f9fa; }
              .report-summary { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; }
              .summary-item { text-align: center; padding: 10px; }
              .summary-value { font-size: 1.5rem; font-weight: bold; color: #2c3e50; }
              .summary-label { font-size: 0.9rem; color: #7f8c8d; }
              @media print {
                body { margin: 0; }
                .report-table { font-size: 12px; }
              }
            </style>
          </head>
          <body>
            ${reportContent}
          </body>
        </html>
      `);
      printWindow.document.close();
      printWindow.focus();
      printWindow.print();
      printWindow.close();

      showNotification(
        'Report Printed',
        'Report has been sent to printer',
        'success',
        3000
      );
    });

    // Close report
    document.getElementById('closeReport').addEventListener('click', () => {
      document.body.removeChild(overlay);
    });

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        document.body.removeChild(overlay);
      }
    });
  }

  // Refresh Statistics Function
  async function refreshStatistics() {
    const hideLoading = showLoading();
    
    try {
      const res = await fetch('adash.php?action=update_statistics', { 
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
        throw new Error(data.error || 'Update failed');
      }
      
      showNotification(
        'Statistics Updated', 
        data.message || 'All home statistics have been refreshed', 
        'success'
      );
      
      // Refresh the table data
      await fetchHomesAndRender();
      
    } catch (err) {
      console.error('Statistics update error:', err);
      showNotification(
        'Update Failed', 
        `Failed to update statistics: ${err.message}`, 
        'error'
      );
    } finally {
      hideLoading();
    }
  }

  // Staff Modal Function
  async function openStaffModal() {
    const hideLoading = showLoading();
    
    try {
      const res = await fetch('adash.php?action=fetch_staff', { 
        credentials: 'same-origin',
        headers: {
          'Cache-Control': 'no-cache'
        }
      });
      
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      
      const data = await res.json();
      if (!data.success) {
        throw new Error(data.error || 'Failed to fetch staff');
      }
      
      const staffData = data.data || [];
      
      // Create modal
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      
      const modal = document.createElement('div');
      modal.className = 'modal-content';
      modal.style.maxWidth = '1000px';
      modal.style.width = '95%';
      
      let staffRows = '';
      if (staffData.length === 0) {
        staffRows = '<tr><td colspan="5" style="text-align:center;padding:30px;color:#7f8c8d;"><i class="fas fa-info-circle"></i> No staff records found</td></tr>';
      } else {
        staffData.forEach((staff, index) => {
          staffRows += `
            <tr>
              <td>${index + 1}</td>
              <td>${staff.homeName}</td>
              <td>${staff.staffName}</td>
              <td><a href="mailto:${staff.staffEmail}" style="color:#3498db;">${staff.staffEmail}</a></td>
              <td>${new Date(staff.createdAt).toLocaleDateString()}</td>
            </tr>
          `;
        });
      }
      
      modal.innerHTML = `
        <div class="modal-header">
          <h3><i class="fas fa-users"></i> Staff Information</h3>
          <button id="staffModalCloseBtn" class="btn btn-danger" style="padding:6px 10px;">Close</button>
        </div>
        <div class="modal-body">
          <div class="report-container">
            <div class="report-header">
              <h1 class="report-title">Staff Directory</h1>
              <p class="report-date">Generated on: ${new Date().toLocaleDateString()}</p>
              <p style="color:#7f8c8d; margin:10px 0;">Total Staff Records: ${staffData.length}</p>
            </div>
            
            <div style="overflow-x: auto;">
              <table class="report-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Home Name</th>
                    <th>Staff Name</th>
                    <th>Email</th>
                    <th>Created Date</th>
                  </tr>
                </thead>
                <tbody>
                  ${staffRows}
                </tbody>
              </table>
            </div>
            
            <div style="margin-top:20px; padding:15px; background:#f8f9fa; border-radius:6px; text-align:center;">
              <button id="printStaffReport" class="btn btn-info" style="margin-right:10px;">
                <i class="fas fa-print"></i> Print Report
              </button>
              <small style="color:#7f8c8d;">
                <i class="fas fa-info-circle"></i> Staff information is updated when homes are added or modified
              </small>
            </div>
          </div>
        </div>
      `;
      
      overlay.appendChild(modal);
      document.body.appendChild(overlay);
      
      // Close button functionality
      document.getElementById('staffModalCloseBtn').addEventListener('click', () => {
        document.body.removeChild(overlay);
      });
      
      // Print functionality
      document.getElementById('printStaffReport').addEventListener('click', () => {
        const reportContent = modal.querySelector('.report-container').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
          <html>
            <head>
              <title>Staff Directory Report</title>
              <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .report-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #8e44ad; padding-bottom: 15px; }
                .report-title { color: #8e44ad; margin: 0 0 10px 0; }
                .report-date { color: #7f8c8d; font-size: 14px; }
                .report-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                .report-table th { background: #8e44ad; color: white; padding: 10px; text-align: left; }
                .report-table td { padding: 8px 10px; border-bottom: 1px solid #eee; }
                .report-table tr:nth-child(even) { background: #f8f9fa; }
                @media print {
                  body { margin: 0; }
                  .report-table { font-size: 12px; }
                }
              </style>
            </head>
            <body>
              ${reportContent}
            </body>
          </html>
        `);
        printWindow.document.close();
        printWindow.print();
      });
      
    } catch (err) {
      console.error('Staff fetch error:', err);
      showNotification(
        'Staff Data Failed', 
        `Failed to load staff information: ${err.message}`, 
        'error'
      );
    } finally {
      hideLoading();
    }
  }

  // Initialize the page
  document.addEventListener('DOMContentLoaded', () => {
    setupMobileMenu(); // NEW: Initialize mobile menu
    fetchHomesAndRender();
    
    document.getElementById('btnAddHome').addEventListener('click', openAddHomeModal);
    document.getElementById('btnViewHome').addEventListener('click', openAllHomesModal);
    document.getElementById('btnViewStaff').addEventListener('click', openStaffModal);
    document.getElementById('btnRefreshStats').addEventListener('click', refreshStatistics);
    document.getElementById('btnGenerateReport').addEventListener('click', generateReport);
    document.getElementById('btnTechnicalContact').addEventListener('click', () => {
    window.location.href = 'mailto:info@webbuilders.lk?subject=Technical%20Support&body=Hello%20WEBbuilders.lk%20%F0%9F%91%8B,';
});

  });
</script>
</body>
</html>