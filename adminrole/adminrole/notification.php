<?php
session_start();
// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt'; 
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in and has admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Handle AJAX email sending
if (isset($_POST['action']) && $_POST['action'] === 'send_email') {
    header('Content-Type: application/json');
    
    $to = trim($_POST['to'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($to) || empty($subject) || empty($message)) {
        echo json_encode(['success' => false, 'error' => 'All fields are required']);
        exit();
    }
    
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        exit();
    }
    
    // Prepare email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Care Home System <noreply@carehome.com>" . "\r\n";
    $headers .= "Reply-To: noreply@carehome.com" . "\r\n";
    
    // Format the message
    $formatted_message = "
    <html>
    <head>
        <title>$subject</title>
    </head>
    <body>
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #2c3e50;'>Care Home Notification</h2>
            <div style='background-color: #f8f9fa; padding: 20px; border-radius: 5px;'>
                " . nl2br(htmlspecialchars($message)) . "
            </div>
            <hr style='margin: 20px 0;'>
            <p style='color: #7f8c8d; font-size: 12px;'>
                This email was sent from the Care Home Management System.<br>
                Please do not reply to this email address.
            </p>
        </div>
    </body>
    </html>";
    
    // Send email
    if (mail($to, $subject, $formatted_message, $headers)) {
        echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send email']);
    }
    exit();
}

// Handle AJAX settings update
if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    header('Content-Type: application/json');
    
    $resident_threshold = floatval($_POST['resident_threshold'] ?? 0);
    $petty_cash_threshold = floatval($_POST['petty_cash_threshold'] ?? 0);
    
    if ($resident_threshold <= 0 || $petty_cash_threshold <= 0) {
        echo json_encode(['success' => false, 'error' => 'Threshold amounts must be greater than 0']);
        exit();
    }
    
    try {
        $mysqli = getDBConnection();
        
        // Update or insert resident alert threshold
        $stmt = $mysqli->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $key = 'resident_alert_threshold';
        $desc = 'Threshold amount for resident account balance alerts';
        $stmt->bind_param("sds", $key, $resident_threshold, $desc);
        $stmt->execute();
        
        // Update or insert petty cash alert threshold
        $key = 'petty_cash_alert_threshold';
        $desc = 'Threshold amount for petty cash alerts';
        $stmt->bind_param("sds", $key, $petty_cash_threshold, $desc);
        $stmt->execute();
        
        $stmt->close();
        $mysqli->close();
        
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX get staff email
if (isset($_POST['action']) && $_POST['action'] === 'get_staff_email') {
    header('Content-Type: application/json');
    
    $home_name = trim($_POST['home_name'] ?? '');
    
    if (empty($home_name)) {
        echo json_encode(['success' => false, 'error' => 'Home name is required']);
        exit();
    }
    
    try {
        $staff_email = getStaffEmailByHomeName($home_name);
        echo json_encode(['success' => true, 'email' => $staff_email]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Database connection
function getDBConnection() {
    global $dbHost, $dbUser, $dbPass, $dbName;
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        die("Database connection failed: " . $mysqli->connect_error);
    }
    return $mysqli;
}

// Get setting value from database
function getSetting($key, $default = 0) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->bind_result($value);
    $result = $stmt->fetch() ? $value : $default;
    $stmt->close();
    $mysqli->close();
    return $result;
}

// Get all settings
function getAllSettings() {
    $mysqli = getDBConnection();
    $query = "SELECT setting_key, setting_value FROM settings";
    $result = $mysqli->query($query);
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $mysqli->close();
    return $settings;
}

// Get staff email by home name
function getStaffEmailByHomeName($home_name) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("SELECT staff_email FROM home_staff WHERE home_name = ? LIMIT 1");
    $stmt->bind_param("s", $home_name);
    $stmt->execute();
    $stmt->bind_result($staff_email);
    $result = $stmt->fetch() ? $staff_email : '';
    $stmt->close();
    $mysqli->close();
    return $result;
}

// Get all care homes
function getCareHomes() {
    $mysqli = getDBConnection();
    $query = "SELECT id, name FROM homes ORDER BY name";
    $result = $mysqli->query($query);
    $homes = [];
    while ($row = $result->fetch_assoc()) {
        $homes[] = $row;
    }
    $mysqli->close();
    return $homes;
}

// Get residents with low net amount by care home
function getResidentsWithLowNetAmount($home_id = null) {
    $mysqli = getDBConnection();
    
    // Get threshold from settings
    $threshold = getSetting('resident_alert_threshold', 100);
    
    $query = "SELECT r.*, h.name as home_name 
              FROM residents r 
              JOIN homes h ON r.home_id = h.id
              WHERE (r.status IS NULL OR r.status != 'deactivated')";
    
    if ($home_id) {
        $query .= " AND r.home_id = ?";
    }
    
    $stmt = $mysqli->prepare($query);
    
    if ($home_id) {
        $stmt->bind_param("i", $home_id);
    }
    
    $stmt->execute();
    $stmt->store_result();

    // Bind result variables
    $stmt->bind_result($id, $home_id, $first_name, $last_name, $date_of_birth, $gender, $nhs_number, $nok_name, $nok_relationship, $nok_email, $phone, $nok_number, $address, $medical_conditions, $medications, $admission_date, $room_number, $status, $home_name);

    $residents = [];
    while ($stmt->fetch()) {
        $residents[] = [
            'id' => $id,
            'home_id' => $home_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'date_of_birth' => $date_of_birth,
            'gender' => $gender,
            'nhs_number' => $nhs_number,
            'nok_name' => $nok_name,
            'nok_relationship' => $nok_relationship,
            'nok_email' => $nok_email,
            'phone' => $phone,
            'nok_number' => $nok_number,
            'address' => $address,
            'medical_conditions' => $medical_conditions,
            'medications' => $medications,
            'admission_date' => $admission_date,
            'room_number' => $room_number,
            'status' => $status,
            'home_name' => $home_name
        ];
    }
    
    $stmt->close();
    
    // Calculate financial data and filter residents with net amount below threshold
    $filtered_residents = [];
    foreach ($residents as $resident) {
        $financial_data = getResidentFinancialData($resident['id'], $resident['home_id']);
        if ($financial_data['net_amount'] < $threshold) {
            $resident['financial_data'] = $financial_data;
            $filtered_residents[] = $resident;
        }
    }
    
    $mysqli->close();
    
    return $filtered_residents;
}

// Get resident financial data (same function as in residents.php)
function getResidentFinancialData($resident_id, $home_id) {
    $mysqli = getDBConnection();
    
    // Calculate total income
    $income_query = $mysqli->prepare("SELECT SUM(amount) AS total_income FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'income'");
    $income_query->bind_param("ii", $home_id, $resident_id);
    $income_query->execute();
    $income_query->bind_result($total_income);
    $income_query->fetch();
    $income_query->close();

    if (!$total_income) {
        $total_income = 0;
    }

    // Calculate total expense
    $expense_query = $mysqli->prepare("SELECT SUM(amount) AS total_expense FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'expense'");
    $expense_query->bind_param("ii", $home_id, $resident_id);
    $expense_query->execute();
    $expense_query->bind_result($total_expense);
    $expense_query->fetch();
    $expense_query->close();

    if (!$total_expense) {
        $total_expense = 0;
    }
   
    // Total Drop
    $drop_query = $mysqli->prepare("SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'drop'");
    $drop_query->bind_param("ii", $home_id, $resident_id);
    $drop_query->execute();
    $drop_query->bind_result($total_drop);
    $drop_query->fetch();
    $drop_query->close();
    if (!$total_drop) $total_drop = 0;

    // Net Amount = Income - (Expense + Drop)
    $net_amount = $total_income - ($total_expense + $total_drop);

    // Initialize bank and cash amounts
    $net_bank = 0;
    $net_cash = 0;
    
    // Try to calculate Net Bank and Net Cash if payment_method column exists
    try {
        // Calculate Net Bank (bank, card, transfer transactions)
        $bank_income_query = $mysqli->prepare("SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'income' AND (payment_method = 'bank' OR payment_method = 'card' OR payment_method = 'transfer')");
        $bank_income_query->bind_param("ii", $home_id, $resident_id);
        $bank_income_query->execute();
        $bank_income_query->bind_result($bank_income);
        $bank_income_query->fetch();
        $bank_income_query->close();
        if (!$bank_income) $bank_income = 0;

        $bank_expense_query = $mysqli->prepare("SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type IN ('expense', 'drop') AND (payment_method = 'bank' OR payment_method = 'card' OR payment_method = 'transfer')");
        $bank_expense_query->bind_param("ii", $home_id, $resident_id);
        $bank_expense_query->execute();
        $bank_expense_query->bind_result($bank_expense);
        $bank_expense_query->fetch();
        $bank_expense_query->close();
        if (!$bank_expense) $bank_expense = 0;

        $net_bank = $bank_income - $bank_expense;

        // Calculate Net Cash
        $cash_income_query = $mysqli->prepare("SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'income' AND payment_method = 'cash'");
        $cash_income_query->bind_param("ii", $home_id, $resident_id);
        $cash_income_query->execute();
        $cash_income_query->bind_result($cash_income);
        $cash_income_query->fetch();
        $cash_income_query->close();
        if (!$cash_income) $cash_income = 0;

        $cash_expense_query = $mysqli->prepare("SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type IN ('expense', 'drop') AND payment_method = 'cash'");
        $cash_expense_query->bind_param("ii", $home_id, $resident_id);
        $cash_expense_query->execute();
        $cash_expense_query->bind_result($cash_expense);
        $cash_expense_query->fetch();
        $cash_expense_query->close();
        if (!$cash_expense) $cash_expense = 0;

        $net_cash = $cash_income - $cash_expense;
    } catch (Exception $e) {
        // If payment_method column doesn't exist, set default values
        $net_bank = $net_amount * 0.7; // 70% assumed bank
        $net_cash = $net_amount * 0.3;  // 30% assumed cash
    }

    $mysqli->close();
    
    return [
        'total_income' => $total_income,
        'total_expense' => $total_expense,
        'net_amount' => $net_amount,
        'net_bank' => $net_bank,
        'net_cash' => $net_cash,
        'formatted_amount' => number_format($net_amount, 2),
        'formatted_bank' => number_format($net_bank, 2),
        'formatted_cash' => number_format($net_cash, 2)
    ];
}

// Get care homes with residents having low net amount
function getCareHomesWithLowNetResidents() {
    $care_homes = getCareHomes();
    $homes_with_low_net = [];
    
    foreach ($care_homes as $home) {
        $residents = getResidentsWithLowNetAmount($home['id']);
        if (count($residents) > 0) {
            $homes_with_low_net[] = [
                'home' => $home,
                'residents' => $residents,
                'count' => count($residents)
            ];
        }
    }
    
    return $homes_with_low_net;
}

// Get care homes with low petty cash (< £50)
function getCareHomesWithLowPettyCash() {
    $mysqli = getDBConnection();
    $homes_with_low_petty_cash = [];
    
    // Get threshold from settings
    $threshold = getSetting('petty_cash_alert_threshold', 50);
    
    // Get all care homes
    $query = "SELECT id, name FROM homes ORDER BY name";
    $result = $mysqli->query($query);
    
    $currentMonth = date('Y-m');
    
    while ($row = $result->fetch_assoc()) {
        $homeId = (int)$row['id'];
        
        // Calculate previous month balance (before current month)
        $prevBalanceQuery = "SELECT 
                               SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                               SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as prev_balance
                            FROM pettyCash_transactions 
                            WHERE home_id = ? AND created_at < ?";
        $prevStmt = $mysqli->prepare($prevBalanceQuery);
        $monthStart = $currentMonth . '-01';
        $prevStmt->bind_param('is', $homeId, $monthStart);
        $prevStmt->execute();
        $prevStmt->bind_result($prevBalance);
        $prevBalance = $prevStmt->fetch() ? (float)$prevBalance : 0;
        $prevStmt->close();
        
        // Calculate current month transactions
        $currentMonthQuery = "SELECT 
                                SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                                SUM(CASE WHEN transaction_type = 'use' THEN amount ELSE 0 END) as current_month_net
                             FROM pettyCash_transactions 
                             WHERE home_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?";
        $currentStmt = $mysqli->prepare($currentMonthQuery);
        $currentStmt->bind_param('is', $homeId, $currentMonth);
        $currentStmt->execute();
        $currentStmt->bind_result($currentMonthNet);
        $currentMonthNet = $currentStmt->fetch() ? (float)$currentMonthNet : 0;
        $currentStmt->close();
        
        // Remaining amount = previous month balance + current month net
        $remainingAmount = $prevBalance + $currentMonthNet;
        
        // Check if petty cash is below threshold
        if ($remainingAmount < $threshold) {
            $homes_with_low_petty_cash[] = [
                'home_id' => $homeId,
                'home_name' => $row['name'],
                'remaining_amount' => $remainingAmount,
                'formatted_amount' => number_format($remainingAmount, 2)
            ];
        }
    }
    
    $mysqli->close();
    return $homes_with_low_petty_cash;
}

// Get all residents with low net amount across all homes
$all_low_net_residents = getResidentsWithLowNetAmount();
$homes_with_low_net = getCareHomesWithLowNetResidents();

// Get care homes with low petty cash
$homes_with_low_petty_cash = getCareHomesWithLowPettyCash();

// Get current settings for display
$settings = getAllSettings();
$resident_threshold = $settings['resident_alert_threshold'] ?? 100;
$petty_cash_threshold = $settings['petty_cash_alert_threshold'] ?? 50;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Care Home Management</title>
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
            text-decoration: none;
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
        
        /* Notification Styles */
        .notification-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
            border-left: 5px solid #e74c3c;
        }
        
        .notification-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .notification-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        
        .notification-count {
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .notification-content {
            padding: 20px;
        }
        
        .resident-alert {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin-bottom: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-left: 4px solid #f39c12;
        }
        
        .resident-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .resident-name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        
        .resident-details {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        
        .resident-financial {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }
        
        .net-amount {
            font-size: 1.2rem;
            font-weight: bold;
            color: #e74c3c;
        }
        
        .financial-breakdown {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
        }
        
        .bank-amount {
            color: #3498db;
        }
        
        .cash-amount {
            color: #27ae60;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #bdc3c7;
        }
        
        .empty-state p {
            margin: 0;
            font-size: 1.1rem;
        }
        
        /* Summary Card */
        .summary-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 20px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .summary-item {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .summary-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 0.9rem;
            color: #7f8c8d;
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
        }
        
        @media (max-width: 768px) {
            .main-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .top-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .resident-alert {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .resident-financial {
                align-items: flex-start;
                width: 100%;
            }
            
            .financial-breakdown {
                width: 100%;
                justify-content: space-between;
            }
            
            .summary-card {
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
            
            .notification-content {
                padding: 15px;
            }
            
            .resident-details {
                flex-direction: column;
                gap: 5px;
            }
        }
        
        /* Tab Navigation Styles */
        .sub-content-nav {
            display: flex;
            background: white;
            border-radius: 8px;
            padding: 5px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
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
        }
        
        .sub-nav-btn.active {
            background: #3498db;
            color: white;
            box-shadow: 0 2px 5px rgba(52, 152, 219, 0.3);
        }
        
        .sub-nav-btn:hover:not(.active) {
            background: #ecf0f1;
            color: #2c3e50;
        }
        
        /* Sub-content sections */
        .sub-content {
            display: none;
        }
        
        .sub-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Mobile tab styles */
        @media (max-width: 768px) {
            .sub-content-nav {
                flex-direction: column;
                gap: 5px;
            }
            
            .sub-nav-btn {
                justify-content: flex-start;
                padding: 10px 15px;
            }
        }
        
        /* Email Modal Styles */
        .modal {
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .modal-content {
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        #emailTo:focus,
        #emailSubject:focus,
        #emailMessage:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .modal-content button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        
        #toast {
            animation: toastSlideIn 0.5s ease;
        }
        
        @keyframes toastSlideIn {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Responsive modal */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
            
            .modal-header h2 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="dashboard-container">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-home"></i> Master Admin</h2>
            </div>
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="adash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> Accounts</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="response.php"><i class="fas fa-comment-dots"></i> Response</a></li>
                <li class="menu-item"><a href="responseCount.php"><i class="fas fa-chart-pie"></i> survey</a></li>
                <li class="menu-item"><a href="peddyCash.php"><i class="fas fa-money-bill-wave"></i> Petty Cash</a></li>
                <li class="menu-item active"><a href="notification.php"><i class="fas fa-bell"></i> Notification</a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1><i class="fas fa-bell"></i> Financial Alerts</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span> 
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>

            <!-- Summary Cards -->
            <div class="content-area">
                <div class="summary-card">
                    <div class="summary-item">
                        <div class="summary-value"><?php echo count($all_low_net_residents); ?></div>
                        <div class="summary-label">Residents with Low Balance</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?php echo count($homes_with_low_net); ?></div>
                        <div class="summary-label">Homes with Low Resident Balance</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value"><?php echo count($homes_with_low_petty_cash); ?></div>
                        <div class="summary-label">Homes with Low Petty Cash</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value">£<?php echo number_format($resident_threshold, 0); ?></div>
                        <div class="summary-label">Resident Alert Threshold</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value">£<?php echo number_format($petty_cash_threshold, 0); ?></div>
                        <div class="summary-label">Petty Cash Alert Threshold</div>
                    </div>
                </div>
                
                <!-- Settings Button -->
                <div style="margin-top: 20px; text-align: right;">
                    <button class="btn btn-primary" id="settingsBtn" style="background: #3498db; border: none; padding: 10px 20px; color: white; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-cog"></i> Configure Thresholds
                    </button>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="content-area">
                <div class="sub-content-nav">
                    <button class="sub-nav-btn active" data-target="resident-alerts">
                        <i class="fas fa-users"></i>
                        Resident Balance Alerts
                        <?php if (count($all_low_net_residents) > 0): ?>
                            <span style="background: #e74c3c; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; margin-left: 8px;">
                                <?php echo count($all_low_net_residents); ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <button class="sub-nav-btn" data-target="petty-cash-alerts">
                        <i class="fas fa-wallet"></i>
                        Petty Cash Alerts
                        <?php if (count($homes_with_low_petty_cash) > 0): ?>
                            <span style="background: #e74c3c; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em; margin-left: 8px;">
                                <?php echo count($homes_with_low_petty_cash); ?>
                            </span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- Resident Balance Alerts Tab -->
                <div id="resident-alerts" class="sub-content active">
                    <?php if (empty($homes_with_low_net)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No resident balance alerts at this time. All residents have sufficient balance.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($homes_with_low_net as $home_data): ?>
                            <div class="notification-card">
                                <div class="notification-header">
                                    <div class="notification-title">
                                        <i class="fas fa-home"></i>
                                        <?php echo htmlspecialchars($home_data['home']['name']); ?>
                                        <div class="notification-count"><?php echo $home_data['count']; ?></div>
                                    </div>
                                    <div class="notification-actions">
                                        <a href="residents.php?home_id=<?php echo $home_data['home']['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-external-link-alt"></i> View Residents
                                        </a>
                                        <button class="btn btn-warning" onclick="openEmailModal(<?php echo $home_data['home']['id']; ?>, '<?php echo htmlspecialchars($home_data['home']['name'], ENT_QUOTES); ?>', <?php echo htmlspecialchars(json_encode($home_data['residents']), ENT_QUOTES); ?>)" style="margin-left: 10px;">
                                            <i class="fas fa-envelope"></i> Send Email
                                        </button>
                                    </div>
                                </div>
                                <div class="notification-content">
                                    <?php foreach ($home_data['residents'] as $resident): ?>
                                        <div class="resident-alert">
                                            <div class="resident-info">
                                                <div class="resident-name">
                                                    <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?>
                                                </div>
                                                <div class="resident-details">
                                                    <span><i class="fas fa-id-card"></i> NHS: <?php echo htmlspecialchars($resident['nhs_number']); ?></span>
                                                    <span><i class="fas fa-bed"></i> Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($resident['phone']); ?></span>
                                                </div>
                                            </div>
                                            <div class="resident-financial">
                                                <div class="net-amount">
                                                    £<?php echo $resident['financial_data']['formatted_amount']; ?>
                                                </div>
                                                <div class="financial-breakdown">
                                                    <span class="bank-amount">
                                                        <i class="fas fa-university"></i> Bank: £<?php echo $resident['financial_data']['formatted_bank']; ?>
                                                    </span>
                                                    <span class="cash-amount">
                                                        <i class="fas fa-money-bill-wave"></i> Cash: £<?php echo $resident['financial_data']['formatted_cash']; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Petty Cash Alerts Tab -->
                <div id="petty-cash-alerts" class="sub-content">
                    <?php if (empty($homes_with_low_petty_cash)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No petty cash alerts at this time. All homes have sufficient petty cash balance.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($homes_with_low_petty_cash as $petty_cash_data): ?>
                            <div class="notification-card">
                                <div class="notification-header">
                                    <div class="notification-title">
                                        <i class="fas fa-wallet"></i>
                                        <?php echo htmlspecialchars($petty_cash_data['home_name']); ?>
                                    </div>
                                    <div class="notification-actions">
                                        <a href="peddyCash.php?home_id=<?php echo $petty_cash_data['home_id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-wallet"></i> Manage Petty Cash
                                        </a>
                                    </div>
                                </div>
                                <div class="notification-content">
                                    <div class="resident-alert" style="background-color: #fff5f5; border-left: 4px solid #e74c3c;">
                                        <div class="resident-info">
                                            <div class="resident-name" style="color: #e74c3c;">
                                                <i class="fas fa-exclamation-triangle"></i> Petty Cash Below Threshold
                                            </div>
                                            <div class="resident-details">
                                                <span><i class="fas fa-coins"></i> Current Balance: £<?php echo $petty_cash_data['formatted_amount']; ?></span>
                                                <span><i class="fas fa-exclamation-circle"></i> Threshold: £<?php echo number_format($petty_cash_threshold, 2); ?></span>
                                                <span><i class="fas fa-clock"></i> Please add funds soon</span>
                                            </div>
                                        </div>
                                        <div class="resident-financial">
                                            <div class="net-amount" style="color: #e74c3c; font-weight: bold;">
                                                £<?php echo $petty_cash_data['formatted_amount']; ?>
                                            </div>
                                            <div class="financial-breakdown">
                                                <span class="cash-amount" style="color: #e74c3c;">
                                                    <i class="fas fa-wallet"></i> Remaining: £<?php echo $petty_cash_data['formatted_amount']; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Email Modal -->
    <div id="emailModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 0; border: none; border-radius: 10px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0;">
                <h2 style="margin: 0; font-size: 1.5rem;">
                    <i class="fas fa-envelope"></i> Send Email Notification
                </h2>
                <button onclick="closeEmailModal()" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; float: right; margin-top: -30px;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 35px;">
                <form id="emailForm">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="emailTo" style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">To:</label>
                        <input type="email" id="emailTo" name="to" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 14px; transition: border-color 0.3s;" placeholder="Enter recipient email address">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="emailSubject" style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">Subject:</label>
                        <input type="text" id="emailSubject" name="subject" required style="width: 100%; padding: 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 14px;" placeholder="Enter email subject">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label for="emailMessage" style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">Message:</label>
                        <textarea id="emailMessage" name="message" required style="width: 100%; height: 200px; padding: 12px; border: 2px solid #e9ecef; border-radius: 6px; font-size: 14px; resize: vertical; font-family: Arial, sans-serif;" placeholder="Enter email message"></textarea>
                    </div>
                    
                    <div class="modal-footer" style="text-align: right; border-top: 1px solid #e9ecef; padding-top: 20px;">
                        <button type="button" onclick="closeEmailModal()" style="background-color: #6c757d; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; margin-right: 10px; font-size: 14px;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" style="background-color: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            <i class="fas fa-paper-plane"></i> Send Email
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Settings Modal -->
    <div id="settingsModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 0; border: none; border-radius: 10px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <div class="modal-header" style="background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0;">
                <h2 style="margin: 0; display: inline-block;"><i class="fas fa-cog"></i> Configure Alert Thresholds</h2>
            </div>
            <div class="modal-body" style="padding: 25px;">
                <form id="settingsForm">
                    <div style="margin-bottom: 20px;">
                        <label for="residentThreshold" style="display: block; margin-bottom: 5px; font-weight: bold;">
                            <i class="fas fa-users"></i> Resident Alert Threshold (£)
                        </label>
                        <input type="number" id="residentThreshold" name="residentThreshold" 
                               value="<?php echo $resident_threshold; ?>" 
                               min="1" step="0.01" required
                               style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px;">
                        <small style="color: #666; font-size: 12px;">Alert when resident balance falls below this amount</small>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label for="pettyCashThreshold" style="display: block; margin-bottom: 5px; font-weight: bold;">
                            <i class="fas fa-wallet"></i> Petty Cash Alert Threshold (£)
                        </label>
                        <input type="number" id="pettyCashThreshold" name="pettyCashThreshold" 
                               value="<?php echo $petty_cash_threshold; ?>" 
                               min="1" step="0.01" required
                               style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px;">
                        <small style="color: #666; font-size: 12px;">Alert when petty cash falls below this amount</small>
                    </div>
                    
                    <div style="text-align: right; margin-top: 30px;">
                        <button type="button" onclick="closeSettingsModal()" 
                                style="background: #95a5a6; color: white; border: none; padding: 10px 20px; border-radius: 5px; margin-right: 10px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" 
                                style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" style="visibility: hidden; min-width: 250px; margin-left: -125px; background-color: #333; color: #fff; text-align: center; border-radius: 6px; padding: 16px; position: fixed; z-index: 1001; left: 50%; bottom: 30px; font-size: 17px; transition: visibility 0.5s, opacity 0.5s;">
        <span id="toastMessage"></span>
    </div>

    <script>
        // Mobile menu toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
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

            // Tab switching functionality
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
        });

        // Email Modal Functions
        function openEmailModal(homeId, homeName, residents) {
            const modal = document.getElementById('emailModal');
            const emailTo = document.getElementById('emailTo');
            const subject = document.getElementById('emailSubject');
            const message = document.getElementById('emailMessage');
            
            // Fetch staff email for the home
            const fetchStaffEmail = async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'get_staff_email');
                    formData.append('home_name', homeName);
                    
                    const response = await fetch('notification.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    if (result.success && result.email) {
                        emailTo.value = result.email;
                    }
                } catch (error) {
                    console.error('Error fetching staff email:', error);
                }
            };
            
            // Generate email subject
            subject.value = `Low Balance Alert - ${homeName}`;
            
            // Generate email message
            let emailContent = `Dear Care Home Manager,\n\n`;
            emailContent += `This is an automated notification regarding residents with low account balances at ${homeName}.\n\n`;
            emailContent += `The following residents have account balances below £<?php echo number_format($resident_threshold, 2); ?>:\n\n`;
            
            residents.forEach(resident => {
                emailContent += `• ${resident.first_name} ${resident.last_name}\n`;
                emailContent += `  - NHS Number: ${resident.nhs_number}\n`;
                emailContent += `  - Room: ${resident.room_number}\n`;
                emailContent += `  - Current Balance: £${resident.financial_data.formatted_amount}\n`;
                emailContent += `  - Phone: ${resident.phone}\n\n`;
            });
            
            emailContent += `Please review these accounts and take appropriate action to ensure residents have sufficient funds for their care and personal needs.\n\n`;
            emailContent += `Total residents affected: ${residents.length}\n\n`;
            emailContent += `This notification was generated automatically by the Care Home Management System.\n\n`;
            emailContent += `Best regards,\nCare Home Management System`;
            
            message.value = emailContent;
            
            // Fetch and populate staff email
            fetchStaffEmail();
            
            // Show modal
            modal.style.display = 'block';
            
            // Focus on email input
            document.getElementById('emailTo').focus();
        }
        
        function closeEmailModal() {
            document.getElementById('emailModal').style.display = 'none';
            document.getElementById('emailForm').reset();
        }
        
        // Handle email form submission
        document.getElementById('emailForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'send_email');
            formData.append('to', document.getElementById('emailTo').value);
            formData.append('subject', document.getElementById('emailSubject').value);
            formData.append('message', document.getElementById('emailMessage').value);
            
            // Show loading state
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('Email sent successfully!', 'success');
                    closeEmailModal();
                } else {
                    showToast('Error: ' + result.error, 'error');
                }
            } catch (error) {
                showToast('Error: Failed to send email', 'error');
            }
            
            // Restore button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
        
        // Toast notification function
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            toastMessage.textContent = message;
            toast.style.backgroundColor = type === 'success' ? '#28a745' : '#dc3545';
            toast.style.visibility = 'visible';
            toast.style.opacity = '1';
            
            setTimeout(() => {
                toast.style.visibility = 'hidden';
                toast.style.opacity = '0';
            }, 4000);
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const emailModal = document.getElementById('emailModal');
            const settingsModal = document.getElementById('settingsModal');
            if (event.target === emailModal) {
                closeEmailModal();
            }
            if (event.target === settingsModal) {
                closeSettingsModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEmailModal();
                closeSettingsModal();
            }
        });
        
        // Settings Modal Functions
        function openSettingsModal() {
            document.getElementById('settingsModal').style.display = 'block';
        }
        
        function closeSettingsModal() {
            document.getElementById('settingsModal').style.display = 'none';
        }
        
        // Settings form submission
        document.addEventListener('DOMContentLoaded', function() {
            // Settings form event listener
            const settingsForm = document.getElementById('settingsForm');
            if (settingsForm) {
                settingsForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData();
                    formData.append('action', 'update_settings');
                    formData.append('resident_threshold', document.getElementById('residentThreshold').value);
                    formData.append('petty_cash_threshold', document.getElementById('pettyCashThreshold').value);
                    
                    fetch('notification.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Settings updated successfully!', 'success');
                            closeSettingsModal();
                            // Reload page to show updated values
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showToast('Error: ' + data.error, 'error');
                        }
                    })
                    .catch(error => {
                        showToast('Network error occurred', 'error');
                        console.error('Error:', error);
                    });
                });
            }
            
            // Settings button event listener
            const settingsBtn = document.getElementById('settingsBtn');
            if (settingsBtn) {
                settingsBtn.addEventListener('click', openSettingsModal);
            }
        });
        
        // Settings button event
        // document.getElementById('settingsBtn').addEventListener('click', openSettingsModal);
    </script>
</body>
</html>
</html>