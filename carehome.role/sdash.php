<?php
session_start();

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in and has staff role
if (!isset($_SESSION['logged_in']) ) {
    header("Location: ../index.php");
    exit();
}

// Get carehome information for logged-in user (mysqlnd-free version)
function get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $username) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        // connection failed
        return false;
    }

    $mysqli->set_charset('utf8mb4');

    $sql = "SELECT id, name, address, beds, residents, bank, cash, balance 
            FROM homes 
            WHERE user_name = ? 
            LIMIT 1";

    $carehome = null;

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $username);
        $stmt->execute();

        // Bind result variables (in same order as SELECT)
        $stmt->bind_result($id, $name, $address, $beds, $residents, $bank, $cash, $balance);
        
    

        // Fetch the row
        if ($stmt->fetch()) {
            $carehome = [
                'id' => $id,
                'name' => $name,
                'address' => $address,
                'beds' => $beds,
                'residents' => $residents,
                'bank' => $bank,
                'cash' => $cash,
                'balance' => $balance
            ];
        } else {
            // no row found, $carehome remains null
            $carehome = null;
        }

        $stmt->close();
        
       
    } else {
        // prepare failed (optional: log $mysqli->error)
        $carehome = null;
    }
     
    $mysqli->close();
    
   
    return $carehome;
}



// Get total residents count (mysqlnd-free version)
function get_total_residents($dbHost, $dbUser, $dbPass, $dbName, $home_id) {
    // Ensure home_id is an integer
    $home_id = (int) $home_id;

    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        // connection failed
        return 0;
    }

    // set charset
    $mysqli->set_charset('utf8mb4');

    $sql = "SELECT COUNT(*) AS total FROM residents WHERE home_id = ?";

    $total = 0;

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $home_id);

        if ($stmt->execute()) {
            // Bind the result column
            $stmt->bind_result($count);

            // Fetch the value
            if ($stmt->fetch()) {
                $total = (int) $count;
            }
        }
        // close statement
        $stmt->close();
    } else {
        // optional: error_log("Prepare failed: " . $mysqli->error);
        $total = 0;
    }

    $mysqli->close();

    return $total;
}


// Get monthly financial summary (mysqlnd-free version, includes drop)
function get_monthly_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id) {
    // Ensure home_id is integer
    $home_id = (int) $home_id;

    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return ['income' => 0, 'expenses' => 0, 'drops' => 0, 'net' => 0];
    }

    $mysqli->set_charset('utf8mb4');

    $current_month = date('Y-m');

    $query = "
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS monthly_income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS monthly_expenses,
            COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) AS monthly_drops
        FROM transactions
        WHERE home_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?
    ";

    $monthly_income = 0.0;
    $monthly_expenses = 0.0;
    $monthly_drops = 0.0;

    if ($stmt = $mysqli->prepare($query)) {
        $stmt->bind_param("is", $home_id, $current_month);

        if ($stmt->execute()) {
            // Bind results
            $stmt->bind_result($income_db, $expense_db, $drop_db);

            if ($stmt->fetch()) {
                $monthly_income = (float)$income_db;
                $monthly_expenses = (float)$expense_db;
                $monthly_drops = (float)$drop_db;
            }
        }
        $stmt->close();
    }

    $mysqli->close();

    // ✅ Include drops in net calculation
    $net_amount = $monthly_income - ($monthly_expenses + $monthly_drops);

    return [
        'income' => $monthly_income,
        'expenses' => $monthly_expenses,
        'drops' => $monthly_drops,
        'net' => $net_amount
    ];
}


// Get residents with net balance below threshold (mysqlnd-free version)
function get_residents_low_balance($dbHost, $dbUser, $dbPass, $dbName, $home_id, $threshold = 5000) {
    // normalize types
    $home_id = (int) $home_id;
    $threshold = (float) $threshold;

    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return [];
    }

    $mysqli->set_charset('utf8mb4');

    $query = "
        SELECT 
            r.id,
            r.first_name,
            r.last_name,
            r.room_number,
            COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) 
            - COALESCE(SUM(CASE WHEN t.type IN ('expense', 'drop') THEN t.amount ELSE 0 END), 0) 
            AS net_balance
        FROM residents r
        LEFT JOIN transactions t ON r.id = t.resident_id
        WHERE r.home_id = ?
        GROUP BY r.id, r.first_name, r.last_name, r.room_number
        HAVING net_balance < ?
        ORDER BY net_balance ASC
        LIMIT 10
    ";

    $residents = [];

    if ($stmt = $mysqli->prepare($query)) {
        // types: i -> integer (home_id), d -> double/float (threshold)
        $stmt->bind_param("id", $home_id, $threshold);

        if ($stmt->execute()) {
            // Bind result columns (in same order as SELECT)
            $stmt->bind_result($id, $first_name, $last_name, $room_number, $net_balance);

            // Fetch rows into array
            while ($stmt->fetch()) {
                $residents[] = [
                    'id' => $id,
                    'name' => trim($first_name . ' ' . $last_name),
                    'room_number' => $room_number,
                    'net_balance' => (float) $net_balance
                ];
            }
        }
        $stmt->close();
    } else {
        // optional: error_log("Prepare failed: " . $mysqli->error);
    }

    $mysqli->close();

    return $residents;
}




// Get monthly financial data for chart (mysqlnd-free version)
function get_monthly_financial_data($dbHost, $dbUser, $dbPass, $dbName, $home_id) {
    // normalize home_id to int
    $home_id = (int) $home_id;

    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return [];
    }

    $mysqli->set_charset('utf8mb4');

    $query = "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expenses
              FROM transactions 
              WHERE home_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')
              ORDER BY month DESC
              LIMIT 6";

    $data = [];

    if ($stmt = $mysqli->prepare($query)) {
        $stmt->bind_param("i", $home_id);

        if ($stmt->execute()) {
            // Bind result columns (in same order as SELECT)
            $stmt->bind_result($month, $income_db, $expenses_db);

            // Fetch rows
            while ($stmt->fetch()) {
                $data[] = [
                    'month' => (string) $month,
                    'income' => (float) $income_db,
                    'expenses' => (float) $expenses_db
                ];
            }
        }
        $stmt->close();
    } else {
        // optional: error_log("Prepare failed: " . $mysqli->error);
    }

    $mysqli->close();

    // Reverse to chronological order (oldest -> newest)
    return array_reverse($data);
}




// Get carehome information

$username1=$_SESSION['username'];

$carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName,$username1);



$carehome_name = $carehome_info ? $carehome_info['name'] : 'CareHome';
$home_id = $carehome_info ? $carehome_info['id'] : null;


// Get dashboard statistics
$total_residents = $home_id ? get_total_residents($dbHost, $dbUser, $dbPass, $dbName, $home_id) : 0;
$monthly_financial = $home_id ? get_monthly_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id) : ['income' => 0, 'expenses' => 0, 'net' => 0];
$low_payment_residents = $home_id ? get_residents_low_balance($dbHost, $dbUser, $dbPass, $dbName, $home_id) : [];
$financial_data = $home_id ? get_monthly_financial_data($dbHost, $dbUser, $dbPass, $dbName, $home_id) : [];




?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Care Home Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        border-left: 3px solid transparent;
    }

    .menu-item a:hover, .menu-item.active a {
        background-color: rgba(255, 255, 255, 0.1);
        color: white;
        border-left-color: #e74c3c;
    }

    .menu-item a i {
        width: 25px;
        margin-right: 10px;
    }

    /* Mobile menu toggle */
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

    /* Main content styles */
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

    .content-area {
        padding: 30px;
    }

    /* Welcome section */
    .welcome-section {
        background: linear-gradient(135deg, #3498db, #2c3e50);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .welcome-section h2 {
        font-size: 2rem;
        margin-bottom: 10px;
    }

    .welcome-section p {
        font-size: 1.1rem;
        opacity: 0.9;
    }

    /* Stats grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 25px;
        display: flex;
        align-items: center;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stat-icon {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 20px;
        font-size: 1.8rem;
    }

    .stat-card.residents .stat-icon {
        background: rgba(52, 152, 219, 0.2);
        color: #3498db;
    }

    .stat-card.income .stat-icon {
        background: rgba(46, 204, 113, 0.2);
        color: #2ecc71;
    }

    .stat-card.expenses .stat-icon {
        background: rgba(231, 76, 60, 0.2);
        color: #e74c3c;
    }

    .stat-card.net .stat-icon {
        background: rgba(155, 89, 182, 0.2);
        color: #9b59b6;
    }

    .stat-card.staff .stat-icon {
        background: rgba(241, 196, 15, 0.2);
        color: #f39c12;
    }

    .stat-card.occupancy .stat-icon {
        background: rgba(52, 152, 219, 0.2);
        color: #3498db;
    }

    .stat-content h3 {
        font-size: 0.95rem;
        color: #7f8c8d;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: #2c3e50;
    }

    /* Dashboard content grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }

    .dashboard-card {
        background: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .card-header {
        background: linear-gradient(to right, #3498db, #2c3e50);
        color: white;
        padding: 18px 25px;
        font-size: 1.2rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-body {
        padding: 25px;
    }

    /* Resident list styles */
    .resident-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .resident-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 0;
        border-bottom: 1px solid #eee;
    }

    .resident-item:last-child {
        border-bottom: none;
    }

    .resident-info h4 {
        color: #2c3e50;
        margin-bottom: 5px;
    }

    .resident-info p {
        color: #7f8c8d;
        font-size: 0.9rem;
    }

    .resident-payment {
        text-align: right;
    }

    .payment-amount {
        font-weight: 600;
        color: #e74c3c;
    }

    .payment-label {
        font-size: 0.8rem;
        color: #95a5a6;
    }

    /* Chart container */
    .chart-container {
        height: 300px;
        position: relative;
    }

    /* Quick actions */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 30px;
    }

    .action-btn {
        background: white;
        border: none;
        border-radius: 8px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        text-decoration: none;
        color: inherit;
    }

    .action-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        background: #3498db;
        color: white;
    }

    .action-icon {
        font-size: 2rem;
        margin-bottom: 5px;
    }

    .action-text {
        font-weight: 600;
        text-align: center;
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

    /* NEW: Mobile Card Styles for Stats */
    .mobile-stats-container {
        display: none;
    }

    .mobile-stat-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        border-left: 4px solid #3498db;
    }

    .mobile-stat-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }

    .mobile-stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        background: rgba(52, 152, 219, 0.1);
        color: #3498db;
    }

    .mobile-stat-title {
        font-size: 0.9rem;
        color: #7f8c8d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .mobile-stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
        text-align: center;
        margin-top: 5px;
    }

    /* NEW: Mobile Dashboard Grid */
    .mobile-dashboard-container {
        display: none;
    }

    .mobile-dashboard-card {
        background: white;
        border-radius: 10px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    .mobile-card-header {
        background: linear-gradient(to right, #3498db, #2c3e50);
        color: white;
        padding: 15px;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .mobile-card-body {
        padding: 15px;
    }

    .mobile-chart-container {
        height: 250px;
        position: relative;
    }

    /* Responsive styles */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
        }
        
        .sidebar.active {
            transform: translateX(0);
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
        
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }

        
        
          .dashboard-container{
                margin-top: 60px;
            }
    }

    @media (max-width: 768px) {
        .content-area {
            padding: 15px;
        }
        
        .main-header {
            padding: 15px;
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }
        
        .welcome-section {
            padding: 20px;
        }
        
        .welcome-section h2 {
            font-size: 1.5rem;
        }
        
        .stat-card {
            padding: 20px;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            font-size: 1.5rem;
            margin-right: 15px;
        }
        
        .stat-number {
            font-size: 1.7rem;
        }
        
        .card-body {
            padding: 15px;
        }
        
        .chart-container {
            height: 250px;
        }

        /* Show mobile stats and hide desktop on small screens */
        .mobile-stats-container {
            display: block;
        }
        
        .stats-grid {
            display: none;
        }

        /* Show mobile dashboard and hide desktop */
        .mobile-dashboard-container {
            display: block;
        }
        
        .dashboard-grid {
            display: none;
        }

        .quick-actions {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 576px) {
        .quick-actions {
            grid-template-columns: 1fr;
        }
        
        .main-header h1 {
            font-size: 1.2rem;
        }
        
        .welcome-section h2 {
            font-size: 1.3rem;
        }
        
        .welcome-section p {
            font-size: 1rem;
        }
        
        .stat-card {
            flex-direction: column;
            text-align: center;
        }
        
        .stat-icon {
            margin-right: 0;
            margin-bottom: 15px;
        }
        
        .card-header {
            padding: 15px;
            font-size: 1.1rem;
        }
        
        .resident-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .resident-payment {
            text-align: left;
        }

        .mobile-stat-card {
            padding: 15px;
        }

        .mobile-stat-icon {
            width: 45px;
            height: 45px;
            font-size: 1.2rem;
        }

        .mobile-stat-value {
            font-size: 1.3rem;
        }

        .mobile-chart-container {
            height: 200px;
        }
    }

    @media (max-width: 400px) {
        .content-area {
            padding: 10px;
        }
        
        .welcome-section {
            padding: 15px;
        }
        
        .stat-card {
            padding: 15px;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            font-size: 1.3rem;
        }
        
        .stat-number {
            font-size: 1.5rem;
        }

        .mobile-stat-card {
            padding: 12px;
        }

        .mobile-stat-header {
            gap: 8px;
        }

        .mobile-stat-icon {
            width: 40px;
            height: 40px;
            font-size: 1.1rem;
        }

        .mobile-stat-title {
            font-size: 0.8rem;
        }

        .mobile-stat-value {
            font-size: 1.2rem;
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
                <h2><i class="fas fa-home"></i> <span>Care Home Admin</span></h2>
            </div>
            <ul class="sidebar-menu">
                <li class="menu-item active">
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
                <li class="menu-item">
                    <a href="peddyCash.php">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Petty Cash</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1><i class="fas fa-tachometer-alt"></i> Care Home Management Dashboard - <?php echo htmlspecialchars($carehome_name); ?></h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?> </span><a href="../logout.php"><button type="submit" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></button></a>
                </div>
            </header>

            <div class="content-area">
                <!-- Welcome Section -->
                <div class="welcome-section">
                    <h2>Welcome to Care Home Management System</h2>
                    <p>Manage residents, accounts, and generate reports efficiently. Everything you need in one place.</p>
                </div>
                
                
                <!-- Add this after the welcome section -->
<!-- Mobile Stats Container -->
<div class="mobile-stats-container"></div>

<!-- Mobile Dashboard Container -->
<div class="mobile-dashboard-container"></div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card residents">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Total Residents</h3>
                            <p class="stat-number"><?php echo $total_residents; ?></p>
                        </div>
                    </div>
                    
                    <div class="stat-card income">
                        <div class="stat-icon">
                            <i class="fas fa-pound-sign"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Monthly Receipts</h3>
                            <p class="stat-number">£<?php echo number_format($monthly_financial['income'], 2); ?></p>
                        </div>
                    </div>
                    
                    <div class="stat-card expenses">
                        <div class="stat-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Monthly Payment </h3>
                            <p class="stat-number">£<?php echo number_format($monthly_financial['expenses'], 2); ?></p>
                        </div>
                    </div>
                    
                    <div class="stat-card net">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Net Money</h3>
                            <p class="stat-number">£<?php echo number_format($monthly_financial['net'], 2); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Dashboard Content Grid -->
                <div class="dashboard-grid">
                    <!-- Financial Chart -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <i class="fas fa-chart-bar"></i>
                            Monthly Income vs Expenses
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="financialChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Residents with Low Payments -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <i class="fas fa-exclamation-triangle"></i>
                            Residents with Low Monthly Payments
                        </div>
                        <div class="card-body">
                            <div class="resident-list">
                                <?php if (empty($low_payment_residents)): ?>
                                    <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                                        <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px; color: #2ecc71;"></i>
                                        <p>All residents have adequate payment records.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($low_payment_residents as $resident): ?>
                                        <div class="resident-item">
                                            <div class="resident-info">
                                                <h4><?php echo htmlspecialchars($resident['name']); ?></h4>
                                                <p>Room <?php echo htmlspecialchars($resident['room_number']); ?></p>
                                            </div>
                                           
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <a href="residents.php" class="action-btn">
                        <div class="action-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="action-text">Add New Resident</div>
                    </a>
                    
                    <a href="accounts.php" class="action-btn">
                        <div class="action-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div class="action-text">Record Income</div>
                    </a>
                    
                    <a href="accounts.php" class="action-btn">
                        <div class="action-icon">
                            <i class="fas fa-minus-circle"></i>
                        </div>
                        <div class="action-text">Record Expense</div>
                    </a>
                    
                    <a href="reports.php" class="action-btn">
                        <div class="action-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="action-text">Generate Reports</div>
                    </a>
                </div>
            </div>
        </main>
    </div>
    
    
    <script>
    // Mobile menu toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    
    mobileMenuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (event) => {
        if (window.innerWidth <= 992 && 
            !sidebar.contains(event.target) && 
            !mobileMenuToggle.contains(event.target) &&
            sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }
    });

    // Generate mobile stats cards
    function generateMobileStats() {
        const statsContainer = document.querySelector('.mobile-stats-container');
        if (!statsContainer) return;

        const statsData = [
            { 
                title: 'Total Residents', 
                value: '<?php echo $total_residents; ?>', 
                icon: 'fas fa-users',
                color: '#3498db'
            },
            { 
                title: 'Monthly Receipts', 
                value: '£<?php echo number_format($monthly_financial['income'], 2); ?>', 
                icon: 'fas fa-pound-sign',
                color: '#2ecc71'
            },
            { 
                title: 'Monthly Payment', 
                value: '£<?php echo number_format($monthly_financial['expenses'], 2); ?>', 
                icon: 'fas fa-receipt',
                color: '#e74c3c'
            },
            { 
                title: 'Net Money', 
                value: '£<?php echo number_format($monthly_financial['net'], 2); ?>', 
                icon: 'fas fa-chart-line',
                color: '#9b59b6'
            }
        ];

        let statsHTML = '';
        statsData.forEach(stat => {
            statsHTML += `
                <div class="mobile-stat-card">
                    <div class="mobile-stat-header">
                        <div class="mobile-stat-icon" style="background: rgba(${hexToRgb(stat.color)}, 0.1); color: ${stat.color}">
                            <i class="${stat.icon}"></i>
                        </div>
                        <div class="mobile-stat-title">${stat.title}</div>
                    </div>
                    <div class="mobile-stat-value">${stat.value}</div>
                </div>
            `;
        });

        statsContainer.innerHTML = statsHTML;
    }

    // Helper function to convert hex to rgb
    function hexToRgb(hex) {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? 
            `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` 
            : '52, 152, 219';
    }

    // Generate mobile dashboard content
    function generateMobileDashboard() {
        const mobileContainer = document.querySelector('.mobile-dashboard-container');
        if (!mobileContainer) return;

        mobileContainer.innerHTML = `
            <!-- Financial Chart -->
            <div class="mobile-dashboard-card">
                <div class="mobile-card-header">
                    <i class="fas fa-chart-bar"></i>
                    Monthly Income vs Expenses
                </div>
                <div class="mobile-card-body">
                    <div class="mobile-chart-container">
                        <canvas id="mobileFinancialChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Residents with Low Payments -->
            <div class="mobile-dashboard-card">
                <div class="mobile-card-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    Residents with Low Monthly Payments
                </div>
                <div class="mobile-card-body">
                    <div class="resident-list">
                        <?php if (empty($low_payment_residents)): ?>
                            <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px; color: #2ecc71;"></i>
                                <p>All residents have adequate payment records.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($low_payment_residents as $resident): ?>
                                <div class="resident-item">
                                    <div class="resident-info">
                                        <h4><?php echo htmlspecialchars($resident['name']); ?></h4>
                                        <p>Room <?php echo htmlspecialchars($resident['room_number']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        `;

        // Initialize mobile chart
        initializeMobileChart();
    }

    // Initialize mobile financial chart
    function initializeMobileChart() {
        const mobileFinancialCtx = document.getElementById('mobileFinancialChart');
        if (!mobileFinancialCtx) return;

        new Chart(mobileFinancialCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($item) {
                    return date('M Y', strtotime($item['month'] . '-01'));
                }, $financial_data)); ?>,
                datasets: [
                    {
                        label: 'Income',
                        data: <?php echo json_encode(array_map(function($item) {
                            return $item['income'];
                        }, $financial_data)); ?>,
                        backgroundColor: 'rgba(46, 204, 113, 0.7)',
                        borderColor: 'rgba(46, 204, 113, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Expenses',
                        data: <?php echo json_encode(array_map(function($item) {
                            return $item['expenses'];
                        }, $financial_data)); ?>,
                        backgroundColor: 'rgba(231, 76, 60, 0.7)',
                        borderColor: 'rgba(231, 76, 60, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '£' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '£' + context.parsed.y.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // Desktop Financial Chart
    const financialCtx = document.getElementById('financialChart');
    if (financialCtx) {
        const financialChart = new Chart(financialCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($item) {
                    return date('M Y', strtotime($item['month'] . '-01'));
                }, $financial_data)); ?>,
                datasets: [
                    {
                        label: 'Income',
                        data: <?php echo json_encode(array_map(function($item) {
                            return $item['income'];
                        }, $financial_data)); ?>,
                        backgroundColor: 'rgba(46, 204, 113, 0.7)',
                        borderColor: 'rgba(46, 204, 113, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Expenses',
                        data: <?php echo json_encode(array_map(function($item) {
                            return $item['expenses'];
                        }, $financial_data)); ?>,
                        backgroundColor: 'rgba(231, 76, 60, 0.7)',
                        borderColor: 'rgba(231, 76, 60, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '£' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += '£' + context.parsed.y.toLocaleString();
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // Initialize mobile components
    document.addEventListener('DOMContentLoaded', function() {
        generateMobileStats();
        generateMobileDashboard();
    });

    // Auto-refresh dashboard every 60 seconds
    setInterval(() => {
        window.location.reload();
    }, 60000);
</script>


</body>
</html>