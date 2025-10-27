<?php
session_start();

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'staff') {
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

// Get staff's home ID from user_name in session
$staffHomeId = null;
if (isset($_SESSION['username'])) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM homes WHERE user_name = ?");
        $stmt->execute([$_SESSION['username']]);
        $staffHomeId = $stmt->fetchColumn();
        
        if (!$staffHomeId) {
            die("Error: Staff user not associated with any care home.");
        }
    } catch (PDOException $e) {
        die("Error fetching staff home: " . $e->getMessage());
    }
}

// Handle AJAX request for all care homes data
if (isset($_GET['action']) && $_GET['action'] === 'get_all_homes_data') {
    header('Content-Type: application/json');
    
    try {
        // Fetch all care homes with their data
        $stmt = $pdo->query("
            SELECT 
                h.id,
                h.name,
                h.address,
                h.total_rooms as totalRooms,
                h.single_rooms as singleRooms,
                h.double_rooms as doubleRooms,
                h.beds,
                h.bank,
                h.cash,
                (h.bank + h.cash) as balance,
                COUNT(r.id) as residents
            FROM homes h
            LEFT JOIN residents r ON h.id = r.home_id
            GROUP BY h.id, h.name, h.address, h.total_rooms, h.single_rooms, h.double_rooms, h.beds, h.bank, h.cash
            ORDER BY h.name
        ");
        
        $homes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for consistency
        foreach ($homes as &$home) {
            $home['beds'] = (int)$home['beds'];
            $home['residents'] = (int)$home['residents'];
            $home['totalRooms'] = (int)$home['totalRooms'];
            $home['singleRooms'] = (int)$home['singleRooms'];
            $home['doubleRooms'] = (int)$home['doubleRooms'];
            $home['bank'] = (float)$home['bank'];
            $home['cash'] = (float)$home['cash'];
            $home['balance'] = (float)$home['balance'];
        }
        
        echo json_encode([
            'success' => true,
            'homes' => $homes,
            'total_homes' => count($homes)
        ]);
        exit();
        
    } catch (PDOException $e) {
        error_log("Database error in NewReport.php get_all_homes_data: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Error fetching care homes data'
        ]);
        exit();
    }
}

// Handle AJAX request for financial report data
if (isset($_GET['action']) && $_GET['action'] === 'get_financial_report' && isset($_GET['home_id'])) {
    header('Content-Type: application/json');
    
    try {
        $home_id = (int)$_GET['home_id'];
        $reportData = generateFinancialReport($pdo, $home_id);
        
        if ($reportData) {
            echo json_encode([
                'success' => true,
                'report' => $reportData
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Error generating financial report'
            ]);
        }
        exit();
        
    } catch (Exception $e) {
        error_log("Error in get_financial_report: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error generating financial report'
        ]);
        exit();
    }
}

// Set selected home ID to staff's home ID
$selectedHomeId = $staffHomeId;

// Fetch staff's care home details
$careHomes = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM homes WHERE id = ?");
    $stmt->execute([$staffHomeId]);
    $careHomes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching care home: " . $e->getMessage();
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

// Handle report generation
$reportData = [];
$reportType = $_GET['report_type'] ?? '';
$timePeriod = $_GET['time_period'] ?? '';
$residentId = $_GET['resident_id'] ?? '';
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$week = $_GET['week'] ?? date('W');
$download = isset($_GET['download']) ? (int)$_GET['download'] : 0;
$format = $_GET['format'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($reportType)) {
    try {
        switch($reportType) {
            case 'yearly':
                $reportData = generateYearlyReport($pdo, $selectedHomeId, $residentId, $year);
                break;
            case 'monthly':
                $reportData = generateMonthlyReport($pdo, $selectedHomeId, $residentId, $year, $month);
                break;
            case 'weekly':
                $reportData = generateWeeklyReport($pdo, $selectedHomeId, $residentId, $year, $week);
                break;
            case 'resident':
                $reportData = generateResidentReport($pdo, $selectedHomeId, $residentId);
                break;
            case 'carehome':
                $reportData = generateCarehomeReport($pdo, $selectedHomeId);
                break;
            case 'all_homes':
                $reportData = generateAllHomesReport($pdo);
                break;
        }


    } catch (Exception $e) {
        $error = "Error generating report: " . $e->getMessage();
    }
}

// Report generation functions
function generateYearlyReport($pdo, $homeId, $residentId, $year) {
    $report = [
        'type' => 'yearly',
        'year' => $year,
        'months' => [],
        'totals' => [
            'income' => 0,
            'expense' => 0,
            'drop' => 0,
            'net' => 0
        ]
    ];
    
    for ($month = 1; $month <= 12; $month++) {
        $monthData = getMonthlyData($pdo, $homeId, $residentId, $year, $month);
        $report['months'][$month] = $monthData;
        
        $report['totals']['income'] += $monthData['income'];
        $report['totals']['expense'] += $monthData['expense'];
        $report['totals']['drop'] += $monthData['drop'];
        $report['totals']['net'] += $monthData['net'];
    }
    
    return $report;
}

function generateMonthlyReport($pdo, $homeId, $residentId, $year, $month) {
    $report = [
        'type' => 'monthly',
        'year' => $year,
        'month' => $month,
        'weeks' => [],
        'totals' => getMonthlyData($pdo, $homeId, $residentId, $year, $month)
    ];
    
    // Get weekly breakdown
    $weeks = getWeeksInMonth($year, $month);
    foreach ($weeks as $week) {
        $weekData = getWeeklyData($pdo, $homeId, $residentId, $year, $week);
        $report['weeks'][$week] = $weekData;
    }
    
    return $report;
}

function generateWeeklyReport($pdo, $homeId, $residentId, $year, $week) {
    $report = [
        'type' => 'weekly',
        'year' => $year,
        'week' => $week,
        'days' => [],
        'totals' => getWeeklyData($pdo, $homeId, $residentId, $year, $week)
    ];
    
    // Get daily breakdown
    $dates = getDatesFromWeek($year, $week);
    foreach ($dates as $date) {
        $dayData = getDailyData($pdo, $homeId, $residentId, $date);
        $report['days'][$date] = $dayData;
    }
    
    return $report;
}

function generateResidentReport($pdo, $homeId, $residentId) {
    if (!$residentId) {
        throw new Exception("Resident ID is required for resident report");
    }
    
    $report = [
        'type' => 'resident',
        'resident' => getResidentDetails($pdo, $residentId),
        'transactions' => getResidentTransactions($pdo, $residentId),
        'totals' => getResidentTotals($pdo, $residentId)
    ];
    
    return $report;
}

function generateCarehomeReport($pdo, $homeId) {
    if (!$homeId) {
        throw new Exception("Care Home ID is required for care home report");
    }
    
    $report = [
        'type' => 'carehome',
        'carehome' => getCarehomeDetails($pdo, $homeId),
        'residents' => getCarehomeResidentsSummary($pdo, $homeId),
        'totals' => getCarehomeTotals($pdo, $homeId)
    ];
    
    return $report;
}

function generateAllHomesReport($pdo) {
    $report = [
        'type' => 'all_homes',
        'carehomes' => [],
        'totals' => [
            'income' => 0,
            'expense' => 0,
            'drop' => 0,
            'net' => 0,
            'residents' => 0
        ]
    ];
    
    $stmt = $pdo->query("SELECT id, name FROM homes");
    $carehomes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($carehomes as $carehome) {
        $homeData = getCarehomeTotals($pdo, $carehome['id']);
        $residentsCount = getResidentsCount($pdo, $carehome['id']);
        
        $report['carehomes'][$carehome['id']] = [
            'name' => $carehome['name'],
            'totals' => $homeData,
            'residents_count' => $residentsCount
        ];
        
        $report['totals']['income'] += $homeData['income'];
        $report['totals']['expense'] += $homeData['expense'];
        $report['totals']['drop'] += $homeData['drop'];
        $report['totals']['net'] += $homeData['net'];
        $report['totals']['residents'] += $residentsCount;
    }
    
    return $report;
}

// Helper functions
function getMonthlyData($pdo, $homeId, $residentId, $year, $month) {
    $query = "SELECT 
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) as drop_amount
              FROM transactions 
              WHERE YEAR(COALESCE(transaction_date, created_at)) = ? AND MONTH(COALESCE(transaction_date, created_at)) = ?";
    
    $params = [$year, $month];
    
    if ($homeId) {
        $query .= " AND home_id = ?";
        $params[] = $homeId;
    }
    
    if ($residentId) {
        $query .= " AND resident_id = ?";
        $params[] = $residentId;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $net = $data['income'] - $data['expense'] - $data['drop_amount'];
    
    return [
        'income' => (float)$data['income'],
        'expense' => (float)$data['expense'],
        'drop' => (float)$data['drop_amount'],
        'net' => $net
    ];
}

function getWeeklyData($pdo, $homeId, $residentId, $year, $week) {
    $query = "SELECT 
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) as drop_amount
              FROM transactions 
              WHERE YEAR(COALESCE(transaction_date, created_at)) = ? AND WEEK(COALESCE(transaction_date, created_at), 1) = ?";
    
    $params = [$year, $week];
    
    if ($homeId) {
        $query .= " AND home_id = ?";
        $params[] = $homeId;
    }
    
    if ($residentId) {
        $query .= " AND resident_id = ?";
        $params[] = $residentId;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $net = $data['income'] - $data['expense'] - $data['drop_amount'];
    
    return [
        'income' => (float)$data['income'],
        'expense' => (float)$data['expense'],
        'drop' => (float)$data['drop_amount'],
        'net' => $net
    ];
}

function getDailyData($pdo, $homeId, $residentId, $date) {
    $query = "SELECT 
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) as drop_amount
              FROM transactions 
              WHERE DATE(COALESCE(transaction_date, created_at)) = ?";
    
    $params = [$date];
    
    if ($homeId) {
        $query .= " AND home_id = ?";
        $params[] = $homeId;
    }
    
    if ($residentId) {
        $query .= " AND resident_id = ?";
        $params[] = $residentId;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $net = $data['income'] - $data['expense'] - $data['drop_amount'];
    
    return [
        'income' => (float)$data['income'],
        'expense' => (float)$data['expense'],
        'drop' => (float)$data['drop_amount'],
        'net' => $net
    ];
}

function getResidentDetails($pdo, $residentId) {
    $stmt = $pdo->prepare("SELECT * FROM residents WHERE id = ?");
    $stmt->execute([$residentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getResidentTransactions($pdo, $residentId) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE resident_id = ? ORDER BY COALESCE(transaction_date, created_at) DESC");
    $stmt->execute([$residentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getResidentTotals($pdo, $residentId) {
    $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) as drop_amount
              FROM transactions 
              WHERE resident_id = ?");
    
    $stmt->execute([$residentId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $net = $data['income'] - $data['expense'] - $data['drop_amount'];
    
    return [
        'income' => (float)$data['income'],
        'expense' => (float)$data['expense'],
        'drop' => (float)$data['drop_amount'],
        'net' => $net
    ];
}

function getCarehomeDetails($pdo, $homeId) {
    $stmt = $pdo->prepare("SELECT * FROM homes WHERE id = ?");
    $stmt->execute([$homeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getCarehomeResidentsSummary($pdo, $homeId) {
    $stmt = $pdo->prepare("SELECT 
                r.id,
                r.first_name,
                r.last_name,
                r.room_number,
                COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END), 0) as drop_amount
              FROM residents r
              LEFT JOIN transactions t ON r.id = t.resident_id
              WHERE r.home_id = ?
              GROUP BY r.id, r.first_name, r.last_name, r.room_number");
    
    $stmt->execute([$homeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCarehomeTotals($pdo, $homeId) {
    $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) as drop_amount
              FROM transactions 
              WHERE home_id = ?");
    
    $stmt->execute([$homeId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $net = $data['income'] - $data['expense'] - $data['drop_amount'];
    
    return [
        'income' => (float)$data['income'],
        'expense' => (float)$data['expense'],
        'drop' => (float)$data['drop_amount'],
        'net' => $net
    ];
}

function getResidentsCount($pdo, $homeId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM residents WHERE home_id = ?");
    $stmt->execute([$homeId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    return $data['count'];
}

// Generate comprehensive financial report (like in newAccounts.php)
function generateFinancialReport($pdo, $home_id) {
    try {
        // Get care home name
        $stmt = $pdo->prepare("SELECT name FROM homes WHERE id = ?");
        $stmt->execute([$home_id]);
        $home_name = $stmt->fetchColumn();
        
        // Get overall financial summary
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN type = 'income' AND payment_method = 'bank' THEN amount ELSE 0 END) as total_bank_income,
                SUM(CASE WHEN type = 'income' AND payment_method = 'cash' THEN amount ELSE 0 END) as total_cash_income,
                SUM(CASE WHEN type = 'expense' AND payment_method = 'bank' THEN amount ELSE 0 END) as bank_expense,
                SUM(CASE WHEN type = 'expense' AND payment_method = 'cash' THEN amount ELSE 0 END) as cash_expense,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expenses,
                SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END) as total_drops,
                COUNT(CASE WHEN type = 'income' THEN 1 END) as income_count,
                COUNT(CASE WHEN type = 'expense' THEN 1 END) as expense_count,
                COUNT(CASE WHEN type = 'drop' THEN 1 END) as drop_count
            FROM transactions 
            WHERE home_id = ?
        ");
        $stmt->execute([$home_id]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get all transactions with resident details
        $stmt = $pdo->prepare("
            SELECT t.*, r.first_name, r.last_name, r.room_number 
            FROM transactions t 
            LEFT JOIN residents r ON t.resident_id = r.id 
            WHERE t.home_id = ? 
            ORDER BY COALESCE(t.transaction_date, t.created_at) DESC
        ");
        $stmt->execute([$home_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get monthly trends (last 6 months)
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') as month,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END) as drops
            FROM transactions 
            WHERE home_id = ? AND COALESCE(transaction_date, created_at) >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$home_id]);
        $monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent transactions (last 30 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as recent_transactions
            FROM transactions 
            WHERE home_id = ? AND COALESCE(transaction_date, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$home_id]);
        $recent_transactions = $stmt->fetchColumn();
        
        // Get payment method breakdown
        $stmt = $pdo->prepare("
            SELECT 
                payment_method,
                SUM(amount) as total_amount,
                COUNT(*) as transaction_count
            FROM transactions 
            WHERE home_id = ? AND type = 'income'
            GROUP BY payment_method
        ");
        $stmt->execute([$home_id]);
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_income = ($summary['total_bank_income'] ?? 0) + ($summary['total_cash_income'] ?? 0);
        $total_costs = ($summary['total_expenses'] ?? 0) + ($summary['total_drops'] ?? 0);
        $net_balance = $total_income - $total_costs;
        
        return [
            'home_name' => $home_name,
            'transactions' => $transactions,
            'total_income' => $total_income,
            'total_bank_income' => $summary['total_bank_income'] ?? 0,
            'total_cash_income' => $summary['total_cash_income'] ?? 0,
            'total_expenses' => $summary['total_expenses'] ?? 0,
            'total_drops' => $summary['total_drops'] ?? 0,
            'bank_expense' => $summary['bank_expense'] ?? 0,
            'cash_expense' => $summary['cash_expense'] ?? 0,
            'total_costs' => $total_costs,
            'net_balance' => $net_balance,
            'income_count' => $summary['income_count'] ?? 0,
            'expense_count' => $summary['expense_count'] ?? 0,
            'drop_count' => $summary['drop_count'] ?? 0,
            'recent_transactions' => $recent_transactions,
            'monthly_trends' => $monthly_trends,
            'payment_methods' => $payment_methods,
            'report_date' => date('Y-m-d H:i:s')
        ];
    } catch (PDOException $e) {
        return null;
    }
}



function getWeeksInMonth($year, $month) {
    $date = new DateTime("$year-$month-01");
    $weeks = [];
    
    while ($date->format('m') == $month) {
        $week = $date->format('W');
        if (!in_array($week, $weeks)) {
            $weeks[] = $week;
        }
        $date->modify('+1 day');
    }
    
    return $weeks;
}

function getDatesFromWeek($year, $week) {
    $dates = [];
    $date = new DateTime();
    $date->setISODate($year, $week);
    
    for ($i = 0; $i < 7; $i++) {
        $dates[] = $date->format('Y-m-d');
        $date->modify('+1 day');
    }
    
    return $dates;
}

// Get current year, month, and week for default values
$currentYear = date('Y');
$currentMonth = date('m');
$currentWeek = date('W');
$years = range($currentYear - 5, $currentYear);
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>





















<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Care Home Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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
    padding: 14px 20px;
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    transition: all 0.3s;
    border-left: 3px solid transparent;
}

.menu-item a:hover, .menu-item.active a {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border-left-color: #3498db;
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
    margin-left: 240px;
    padding: 0;
    width: calc(100% - 240px);
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

/* Report Summary Cards */
.report-summary {
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
    background: rgba(52, 152, 219, 0.2);
    color: #3498db;
}

.summary-content h3 {
    font-size: 1rem;
    color: #7f8c8d;
    margin-bottom: 5px;
}

.summary-number {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 5px;
    color: #2c3e50;
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
    min-width: 200px;
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

/* Report controls */
.report-controls {
    display: flex;
    gap: 20px;
    margin-bottom: 25px;
    flex-wrap: wrap;
}

.control-group {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 200px;
}

.control-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: 500;
    color: #2c3e50;
}

.control-group select {
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 1rem;
    transition: border 0.3s;
}

.control-group select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

/* Report preview */
.report-preview {
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 25px;
    background: #f8f9fa;
}

.report-preview h3 {
    color: #2c3e50;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.preview-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.report-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.stat-item {
    background: white;
    padding: 15px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.stat-item i {
    color: #3498db;
    font-size: 1.2rem;
}

.resident-list-preview, .chart-preview, .operational-metrics {
    background: white;
    padding: 15px;
    border-radius: 5px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.resident-list-preview h4, .chart-preview h4, .operational-metrics h4 {
    color: #2c3e50;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.resident-item {
    display: flex;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}

.resident-item:last-child {
    border-bottom: none;
}

.resident-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #3498db;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    margin-right: 15px;
}

.resident-details {
    flex: 1;
}

.resident-details h5 {
    color: #2c3e50;
    margin-bottom: 5px;
}

.resident-details p {
    color: #7f8c8d;
    font-size: 0.9rem;
}

.resident-status {
    margin-left: 15px;
}

.status-badge {
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-badge.active {
    background: rgba(46, 204, 113, 0.2);
    color: #2ecc71;
}

.chart-container {
    height: 200px;
    margin: 20px 0;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.metric-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 5px;
}

.metric-item i {
    font-size: 1.5rem;
    color: #3498db;
}

.metric-item h5 {
    color: #2c3e50;
    margin-bottom: 5px;
}

.metric-item p {
    color: #7f8c8d;
    font-size: 0.9rem;
}

/* Report actions */
.report-actions {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
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

.btn-info {
    background: #9b59b6;
    color: white;
}

.btn-info:hover {
    background: #8e44ad;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.modal-header h3 {
    color: #2c3e50;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #7f8c8d;
}

.modal-body {
    margin-bottom: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Additional styles for report display */
.report-results {
    margin-top: 20px;
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
}

.report-table th,
.report-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.report-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #2c3e50;
}

.financial-positive {
    color: #2ecc71;
    font-weight: 600;
}

.financial-negative {
    color: #e74c3c;
    font-weight: 600;
}

.report-section {
    margin-bottom: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 5px;
}

.report-section h4 {
    color: #2c3e50;
    margin-bottom: 15px;
    border-bottom: 2px solid #3498db;
    padding-bottom: 10px;
}

/* Mobile card view for report tables */
.report-card-view {
    display: none;
    flex-direction: column;
    gap: 15px;
}

.report-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #eee;
}

.report-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.report-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
}

.report-card-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 15px;
}

.report-card-detail {
    display: flex;
    flex-direction: column;
}

.report-detail-label {
    font-size: 0.8rem;
    color: #7f8c8d;
    margin-bottom: 3px;
}

.report-detail-value {
    font-weight: 500;
    color: #2c3e50;
}

.report-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #eee;
    padding-top: 15px;
}

/* Care Home Info Styles */
.carehome-selector {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 16px 18px;
    margin: 20px;
    margin-bottom: 20px;
}

.carehome-selector-inner {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: space-between;
}

.carehome-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.carehome-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #2c3e50;
}

.carehome-name {
    font-size: 16px;
    color: #3498db;
    font-weight: 600;
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

/* Responsive styles */
@media (max-width: 1200px) {
    .report-table-container {
        overflow-x: auto;
    }
    
    .report-table {
        min-width: 800px;
    }
}

@media (max-width: 992px) {
    
      .dashboard-container{
                margin-top: 60px;
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
        margin-left: 0;
        width: 100%;
    }
    
    .mobile-menu-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .main-header {
        margin-top: 60px;
    }
    
    .report-controls {
        flex-direction: column;
    }
    
    .control-group {
        min-width: 100%;
    }
    
    .notification-container {
        max-width: 300px;
        right: 10px;
        top: 70px;
    }
    
    .carehome-selector {
        margin: 15px;
    }
    
    .content-area {
        padding: 20px;
    }
}

@media (max-width: 768px) {
    .main-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 15px 20px;
    }
    
    .report-summary {
        grid-template-columns: 1fr;
    }
    
    .sub-content-nav {
        flex-direction: column;
    }
    
    .sub-nav-btn {
        min-width: 100%;
    }
    
    .report-stats, .metrics-grid {
        grid-template-columns: 1fr;
    }
    
    .report-actions {
        flex-direction: column;
    }
    
    .resident-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .resident-status {
        margin-left: 0;
    }
    
    .summary-card {
        flex-direction: column;
        text-align: center;
    }
    
    .summary-icon {
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .report-card-details {
        grid-template-columns: 1fr;
    }
    
    .sub-content-body {
        padding: 20px;
    }
    
    .carehome-selector-inner {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .report-table-container {
        display: none;
    }
    
    .report-card-view {
        display: flex;
    }
}

@media (max-width: 576px) {
    .content-area {
        padding: 15px;
    }
    
    .main-header {
        padding: 15px;
    }
    
    .page-header h2 {
        font-size: 1.5rem;
    }
    
    .main-header h1 {
        font-size: 1.2rem;
    }
    
    .sub-content-body {
        padding: 15px;
    }
    
    .report-results {
        padding: 15px;
    }
    
    .report-section {
        padding: 15px;
    }
    
    .btn {
        padding: 10px 20px;
        font-size: 0.9rem;
    }
    
    .carehome-selector {
        margin: 10px;
        padding: 12px 15px;
    }
    
    .notification-container {
        max-width: calc(100% - 20px);
        right: 10px;
        left: 10px;
    }
}

@media (max-width: 400px) {
    .content-area {
        padding: 10px;
    }
    
    .report-card {
        padding: 15px;
    }
    
    .summary-card {
        padding: 15px;
    }
    
    .summary-icon {
        width: 50px;
        height: 50px;
        font-size: 1.3rem;
    }
    
    .summary-number {
        font-size: 1.3rem;
    }
    
    .sub-content-header {
        padding: 15px;
        font-size: 1.1rem;
    }
    
    .carehome-selector {
        padding: 10px 12px;
    }
    
    .carehome-name {
        font-size: 14px;
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
                <li class="menu-item"><a href="sdash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-calculator"></i> Accounts</a></li>
                <li class="menu-item active"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="peddyCash.php"><i class="fas fa-money-bill-wave"></i><span>Petty Cash</span></a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1><i class="fas fa-wallet"></i> Reports & Analytics -<?php echo !empty($careHomes) ? htmlspecialchars($careHomes[0]['name']) : 'Not Assigned'; ?></h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <a href="../logout.php"><button class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></button></a>
                </div>
            </header>

            <!-- Care Home Info -->
            <div class="content-area" style="padding-top:0;">
                <div class="carehome-selector">
                    <div class="carehome-selector-inner">
                        <div class="carehome-info">
                            <label class="carehome-label">
                                <i class="fas fa-home"></i> Your Care Home:
                            </label>
                            <span class="carehome-name">
                                <?php echo !empty($careHomes) ? htmlspecialchars($careHomes[0]['name']) : 'Not Assigned'; ?>
                            </span>
                        </div>
                        <button type="button" id="btnRefreshData" class="btn" style="background:#27ae60;color:#fff;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>

            <div class="content-area">
                <div class="page-header">
                    <h2>Comprehensive Reporting System</h2>
                    <p>Generate detailed reports and analytics for residents and care home operations</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="message error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Report Summary Cards -->
                <div class="report-summary">
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Your Care Home</h3>
                            <p class="summary-number"><?php echo !empty($careHomes) ? htmlspecialchars($careHomes[0]['name']) : 'N/A'; ?></p>
                            <small>Assigned Home</small>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Residents</h3>
                            <p class="summary-number">
                                <?php
                                echo $staffHomeId ? getResidentsCount($pdo, $staffHomeId) : 0;
                                ?>
                            </p>
                            <small>In Your Home</small>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Net Money</h3>
                            <p class="summary-number">
                                <?php
                                if ($staffHomeId) {
                                    $stmt = $pdo->prepare("SELECT 
                                        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                                        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
                                        COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END), 0) as total_drop
                                        FROM transactions WHERE home_id = ?");
                                    $stmt->execute([$staffHomeId]);
                                    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
                                    $profit = $totals['total_income'] - $totals['total_expense'] - $totals['total_drop'];
                                    echo '£' . number_format($profit, 2);
                                } else {
                                    echo '£0.00';
                                }
                                ?>
                            </p>
                            <small>Your Home</small>
                        </div>
                    </div>
                </div>

                <!-- Sub-content Navigation -->
                <div class="sub-content-nav">
                    <button class="sub-nav-btn active" data-target="time-reports">
                        <i class="fas fa-calendar"></i>
                        Time Period Reports
                    </button>
                    <button class="sub-nav-btn" data-target="category-reports">
                        <i class="fas fa-chart-pie"></i>
                        Category Reports
                    </button>
                </div>

                <!-- Time Period Reports -->
                <div id="time-reports" class="sub-content active">
                    <div class="sub-content-header">
                        <i class="fas fa-calendar"></i>
                        Time Period Reports
                    </div>
                    <div class="sub-content-body">
                        <div class="report-controls">
                            <div class="control-group">
                                <label for="timeReportType">
                                    <i class="fas fa-filter"></i>
                                    Report Type
                                </label>
                                <select id="timeReportType" name="report_type">
                                    <option value="yearly">Yearly Report</option>
                                    <option value="monthly">Monthly Report</option>
                                </select>
                            </div>
                            <div class="control-group">
                                <label for="reportYear">
                                    <i class="fas fa-calendar"></i>
                                    Year
                                </label>
                                <select id="reportYear" name="year">
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="control-group" id="monthGroup" style="display:none;">
                                <label for="reportMonth">
                                    <i class="fas fa-calendar-alt"></i>
                                    Month
                                </label>
                                <select id="reportMonth" name="month">
                                    <?php foreach ($months as $key => $month): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $key == $currentMonth ? 'selected' : ''; ?>>
                                            <?php echo $month; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="control-group" id="weekGroup" style="display:none;">
                                <label for="reportWeek">
                                    <i class="fas fa-calendar-week"></i>
                                    Week
                                </label>
                                <select id="reportWeek" name="week">
                                    <?php for ($w = 1; $w <= 52; $w++): ?>
                                        <option value="<?php echo $w; ?>" <?php echo $w == $currentWeek ? 'selected' : ''; ?>>
                                            Week <?php echo $w; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="control-group">
                                <label for="timeResident">
                                    <i class="fas fa-user"></i>
                                    Resident (Optional)
                                </label>
                                <select id="timeResident" name="resident_id">
                                    <option value="">All Residents</option>
                                    <?php foreach ($residents as $resident): ?>
                                        <option value="<?php echo $resident['id']; ?>">
                                            <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="report-actions">
                            <button class="btn btn-primary" onclick="generateTimeReport()">
                                <i class="fas fa-file-pdf"></i>
                                Generate PDF Report
                            </button>
                        </div>

                        <?php if (!empty($reportData) && in_array($reportType, ['yearly', 'monthly', 'weekly'])): ?>
                            <div class="report-results">
                                <h3>Report Results</h3>
                                
                                <!-- Desktop Table View -->
                                <div class="report-table-container">
                                    <?php displayTimeReport($reportData); ?>
                                </div>
                                
                                <!-- Mobile Card View -->
                                <div class="report-card-view">
                                    <?php displayTimeReportMobile($reportData); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Category Reports -->
                <div id="category-reports" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-chart-pie"></i>
                        Category Reports
                    </div>
                    <div class="sub-content-body">
                        <div class="report-controls">
                            <div class="control-group">
                                <label for="categoryReportType">
                                    <i class="fas fa-filter"></i>
                                    Report Type
                                </label>
                                <select id="categoryReportType" name="report_type">
                                    <option value="carehome">Care Home Report</option>
                                </select>
                            </div>
                            <div class="control-group" id="categoryResidentGroup">
                                <label for="categoryResident">
                                    <i class="fas fa-user"></i>
                                    Select Resident
                                </label>
                                <select id="categoryResident" name="resident_id">
                                    <option value="">Choose Resident</option>
                                    <?php foreach ($residents as $resident): ?>
                                        <option value="<?php echo $resident['id']; ?>">
                                            <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="report-actions">
                            <button class="btn btn-primary" onclick="generateCategoryReport()">
                                <i class="fas fa-file-pdf"></i>
                                Generate PDF Report
                            </button>
                        </div>

                        <?php if (!empty($reportData) && in_array($reportType, ['resident', 'carehome', 'all_homes'])): ?>
                            <div class="report-results">
                                <h3>Report Results</h3>
                                
                                <!-- Desktop Table View -->
                                <div class="report-table-container">
                                    <?php displayCategoryReport($reportData); ?>
                                </div>
                                
                                <!-- Mobile Card View -->
                                <div class="report-card-view">
                                    <?php displayCategoryReportMobile($reportData); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
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

        // DOM elements
        const subNavButtons = document.querySelectorAll('.sub-nav-btn');
        const subContents = document.querySelectorAll('.sub-content');
        const timeReportType = document.getElementById('timeReportType');
        const monthGroup = document.getElementById('monthGroup');
        const weekGroup = document.getElementById('weekGroup');
        const categoryReportType = document.getElementById('categoryReportType');
        const categoryResidentGroup = document.getElementById('categoryResidentGroup');

        // Switch between sub-content sections
        subNavButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-target');
                
                subNavButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                subContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === targetId) {
                        content.classList.add('active');
                    }
                });
            });
        });

        // Toggle month/week selectors based on report type
        timeReportType.addEventListener('change', function() {
            monthGroup.style.display = this.value === 'monthly' ? 'block' : 'none';
            weekGroup.style.display = this.value === 'weekly' ? 'block' : 'none';
        });

        // Toggle resident selector for category reports
        categoryReportType.addEventListener('change', function() {
            categoryResidentGroup.style.display = this.value === 'resident' ? 'block' : 'none';
        });

        // Initialize on load
        timeReportType.dispatchEvent(new Event('change'));
        categoryReportType.dispatchEvent(new Event('change'));

        // Report generation functions
        function generateTimeReport() {
            <?php if (!$staffHomeId): ?>
            alert('Error: No care home assigned to your account.');
            return;
            <?php endif; ?>
            
            const reportType = document.getElementById('timeReportType').value;
            const year = document.getElementById('reportYear').value;
            const month = document.getElementById('reportMonth').value;
            const residentId = document.getElementById('timeResident').value;
            
            showTimePeriodReportModal(reportType, year, month, residentId);
        }

        function generateCategoryReport() {
            const reportType = document.getElementById('categoryReportType').value;
            
            if (reportType === 'carehome') {
                <?php if ($staffHomeId): ?>
                showFinancialReportModal();
                <?php else: ?>
                alert('Error: No care home assigned to your account.');
                <?php endif; ?>
            }
        }

        // Generate Time Period Report Modal
        async function showTimePeriodReportModal(reportType, year, month, residentId) {
            try {
                let url = `?report_type=${reportType}&year=${year}`;
                if (reportType === 'monthly') url += `&month=${month}`;
                if (residentId) url += `&resident_id=${residentId}`;
                
                const response = await fetch(url);
                const html = await response.text();
                
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const reportContent = doc.querySelector('.report-results');
                
                if (!reportContent) {
                    alert('No report data available');
                    return;
                }
                
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.style.cssText = `position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;`;
                
                const modal = document.createElement('div');
                modal.className = 'modal-content';
                modal.style.cssText = `background:white;border-radius:10px;max-width:95%;width:900px;max-height:80vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.2);`;
                
                const homeId = <?php echo $staffHomeId ?? 'null'; ?>;
                const homeName = '<?php echo !empty($careHomes) ? addslashes($careHomes[0]['name']) : ''; ?>';
                const reportTitle = reportType === 'yearly' ? `Yearly Report ${year}` : `Monthly Report ${month}/${year}`;
                
                modal.innerHTML = `
                    <div style="padding:15px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;background:#f8f9fa;border-radius:10px 10px 0 0;">
                        <h3 style="margin:0;font-size:1.1rem;color:#2c3e50;">
                            <i class="fas fa-calendar"></i> ${reportTitle} - ${homeName}
                        </h3>
                        <div>
                            <button id="printTimeReport" style="background:#3498db;color:#fff;padding:6px 10px;border:none;border-radius:5px;cursor:pointer;margin-right:8px;">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button id="closeTimeReport" style="background:#e74c3c;color:#fff;padding:6px 10px;border:none;border-radius:5px;cursor:pointer;">Close</button>
                        </div>
                    </div>
                    <div style="padding:20px;">
                        ${reportContent.innerHTML}
                    </div>
                `;
                
                overlay.appendChild(modal);
                document.body.appendChild(overlay);

                // Fallback: ensure Transaction Details table has Net Amount column (in case server didn't include it)
                (function ensureNetAmountInModal() {
                    try {
                        const container = modal.querySelector('.report-section, .report-results') || modal;
                        const tables = Array.from(container.querySelectorAll('table.report-table'));
                        tables.forEach(table => {
                            const headers = Array.from(table.querySelectorAll('thead th'));
                            const hasNet = headers.some(h => h.textContent.trim().toLowerCase() === 'net amount');
                            const amountIdx = headers.findIndex(h => h.textContent.trim().toLowerCase() === 'amount');
                            if (!hasNet && amountIdx !== -1) {
                                // Insert Net Amount header after Amount
                                const netTh = document.createElement('th');
                                netTh.textContent = 'Net Amount';
                                netTh.style.cssText = 'padding: 12px; text-align: right;';
                                headers[amountIdx].insertAdjacentElement('afterend', netTh);

                                // Calculate running total (oldest-first) based on displayed rows
                                let running = 0;
                                const rows = Array.from(table.querySelectorAll('tbody tr'));
                                rows.forEach(row => {
                                    const cells = Array.from(row.querySelectorAll('td'));
                                    const amountCell = cells[amountIdx];
                                    if (!amountCell) return;
                                    const txt = amountCell.textContent.replace(/[^0-9+\-.]/g, '').trim();
                                    const sign = txt.startsWith('+') ? 1 : (txt.startsWith('-') ? -1 : 1);
                                    const num = parseFloat(txt.replace(/^[+\-]/, '')) || 0;
                                    // assume positive unless prefixed with '-'
                                    if (sign === 1) running += num; else running -= num;

                                    const netTd = document.createElement('td');
                                    netTd.style.cssText = `padding:12px; text-align:right; font-weight:600; color: ${running >= 0 ? '#2ecc71' : '#e74c3c'};`;
                                    netTd.textContent = `£${running.toFixed(2)}`;
                                    amountCell.insertAdjacentElement('afterend', netTd);
                                });
                            }
                        });
                    } catch (e) {
                        console.error('Net Amount fallback error:', e);
                    }
                })();
                
                document.getElementById('printTimeReport').addEventListener('click', () => {
                    const printContent = modal.querySelector('[style*="padding:20px"]').cloneNode(true);
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(`
                        <html>
                            <head>
                                <title>${reportTitle} - ${homeName}</title>
                                <style>
                                    body { font-family: Arial, sans-serif; margin: 20px; }
                                    h2, h3, h4, h5 { color: #2c3e50; }
                                    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                                    th { background: #2c3e50; color: white; padding: 8px; }
                                    td { padding: 8px; border-bottom: 1px solid #eee; }
                                    .financial-positive { color: #2ecc71; font-weight: 600; }
                                    .financial-negative { color: #e74c3c; font-weight: 600; }
                                    .report-table { border: 1px solid #ddd; }
                                    @media print { 
                                        body { margin: 10px; }
                                        table { page-break-inside: auto; }
                                        tr { page-break-inside: avoid; page-break-after: auto; }
                                    }
                                </style>
                            </head>
                            <body>
                                <h2>${reportTitle} - ${homeName}</h2>
                                ${printContent.innerHTML}
                            </body>
                        </html>
                    `);
                    printWindow.document.close();
                    setTimeout(() => {
                        printWindow.print();
                    }, 250);
                });
                
                document.getElementById('closeTimeReport').addEventListener('click', () => {
                    if (document.body.contains(overlay)) {
                        document.body.removeChild(overlay);
                    }
                });
                
                overlay.addEventListener('click', (e) => {
                    if(e.target === overlay && document.body.contains(overlay)) {
                        document.body.removeChild(overlay);
                    }
                });
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading report data');
            }
        }

        // Show All Care Homes Report Modal
        async function showAllHomesReportModal() {
            try {
                // Fetch all care homes data
                const response = await fetch('?action=get_all_homes_data');
                const data = await response.json();
                
                if (!data.success || !data.homes || data.homes.length === 0) {
                    alert('No care homes data available to generate report');
                    return;
                }

                const allHomes = data.homes;
                
                // Calculate totals
                const totalBeds = allHomes.reduce((sum, home) => sum + (parseInt(home.beds) || 0), 0);
                const totalResidents = allHomes.reduce((sum, home) => sum + (parseInt(home.residents) || 0), 0);
                const totalBank = allHomes.reduce((sum, home) => sum + (parseFloat(home.bank) || 0), 0);
                const totalCash = allHomes.reduce((sum, home) => sum + (parseFloat(home.cash) || 0), 0);
                const totalBalance = totalBank + totalCash;

                // Build report rows
                const reportRows = allHomes.map(home => `
                    <tr>
                        <td>${home.name || ''}</td>
                        <td>${home.address || ''}</td>
                        <td style="text-align:center;">${home.totalRooms || 0}</td>
                        <td style="text-align:center;">${home.singleRooms || 0}</td>
                        <td style="text-align:center;">${home.doubleRooms || 0}</td>
                        <td style="text-align:center;">${home.beds || 0}</td>
                        <td style="text-align:center;">${home.residents || 0}</td>
                        <td style="text-align:center;">${(parseInt(home.beds) || 0) - (parseInt(home.residents) || 0)}</td>
                        <td style="text-align:right;">£${(parseFloat(home.bank) || 0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                        <td style="text-align:right;">£${(parseFloat(home.cash) || 0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                        <td style="text-align:right;">£${(parseFloat(home.balance) || 0).toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                    </tr>
                `).join('');

                const container = document.createElement('div');
                container.style.padding = '20px';
                container.style.fontFamily = 'Arial, sans-serif';
                container.innerHTML = `
                    <div style="text-align:center;border-bottom:2px solid #2c3e50;padding-bottom:15px;margin-bottom:20px;">
                        <h1 style="color:#2c3e50;margin:0 0 10px 0;font-size:24px;">Care Homes Management Report</h1>
                        <div style="color:#7f8c8d;font-size:14px;">Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</div>
                    </div>

                    <div style="margin:20px 0;padding:15px;background:#f8f9fa;border-radius:6px;display:grid;grid-template-columns:repeat(5,1fr);gap:15px;">
                        <div style="text-align:center;padding:10px;">
                            <div style="font-size:1.5rem;font-weight:bold;color:#2c3e50;">${allHomes.length}</div>
                            <div style="font-size:0.9rem;color:#7f8c8d;">Total Homes</div>
                        </div>
                        <div style="text-align:center;padding:10px;">
                            <div style="font-size:1.5rem;font-weight:bold;color:#2c3e50;">${totalBeds}</div>
                            <div style="font-size:0.9rem;color:#7f8c8d;">Total Beds</div>
                        </div>
                        <div style="text-align:center;padding:10px;">
                            <div style="font-size:1.5rem;font-weight:bold;color:#2c3e50;">${totalResidents}</div>
                            <div style="font-size:0.9rem;color:#7f8c8d;">Total Residents</div>
                        </div>
                        <div style="text-align:center;padding:10px;">
                            <div style="font-size:1.5rem;font-weight:bold;color:#2c3e50;">${totalBeds - totalResidents}</div>
                            <div style="font-size:0.9rem;color:#7f8c8d;">Vacant Beds</div>
                        </div>
                        <div style="text-align:center;padding:10px;">
                            <div style="font-size:1.5rem;font-weight:bold;color:#2c3e50;">£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</div>
                            <div style="font-size:0.9rem;color:#7f8c8d;">Total Balance</div>
                        </div>
                    </div>

                    <div style="overflow-x:auto;margin-top:20px;">
                        <table style="width:100%;border-collapse:collapse;table-layout:auto;">
                            <thead>
                                <tr style="background:#2c3e50;color:white;">
                                    <th style="padding:10px;text-align:left;border:1px solid #ddd;">Home Name</th>
                                    <th style="padding:10px;text-align:left;border:1px solid #ddd;">Address</th>
                                    <th style="padding:10px;text-align:center;border:1px solid #ddd;">Total Rooms</th>
                                    <th style="padding:10px;text-align:center;border:1px solid #ddd;">Single Rooms</th>
                                    <th style="padding:10px;text-align:center;border:1px solid #ddd;">Double Rooms</th>
                                    <th style="padding:10px;text-align:center;border:1px solid #ddd;">Beds</th>
                                    <th style="padding:10px;text-align:center;border:1px solid #ddd;">Residents</th>
                                    <th style="padding:10px;text-align:center;border:1px solid #ddd;">Vacant</th>
                                    <th style="padding:10px;text-align:right;border:1px solid #ddd;">Bank</th>
                                    <th style="padding:10px;text-align:right;border:1px solid #ddd;">Cash</th>
                                    <th style="padding:10px;text-align:right;border:1px solid #ddd;">Total Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${reportRows}
                            </tbody>
                            <tfoot>
                                <tr style="background:#2c3e50;color:white;font-weight:bold;">
                                    <td colspan="5" style="text-align:right;padding:10px;border:1px solid #ddd;">TOTALS:</td>
                                    <td style="text-align:center;padding:10px;border:1px solid #ddd;">${totalBeds}</td>
                                    <td style="text-align:center;padding:10px;border:1px solid #ddd;">${totalResidents}</td>
                                    <td style="text-align:center;padding:10px;border:1px solid #ddd;">${totalBeds - totalResidents}</td>
                                    <td style="text-align:right;padding:10px;border:1px solid #ddd;">£${totalBank.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                                    <td style="text-align:right;padding:10px;border:1px solid #ddd;">£${totalCash.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                                    <td style="text-align:right;padding:10px;border:1px solid #ddd;">£${totalBalance.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div style="border-top:1px solid #ddd;margin-top:20px;padding-top:10px;font-size:10px;color:#777;text-align:center;">
                        Generated by Care Home Management System
                    </div>
                `;

                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.style.cssText = `position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(2px);`;
                
                const modal = document.createElement('div');
                modal.className = 'modal-content';
                modal.style.cssText = `background:white;border-radius:10px;max-width:95%;width:1200px;max-height:80vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.2);`;
                
                modal.innerHTML = `
                    <div style="padding:15px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;background:#f8f9fa;border-radius:10px 10px 0 0;">
                        <h3 style="margin:0;font-size:1.1rem;color:#2c3e50;">
                            <i class="fas fa-building"></i> All Care Homes Report
                        </h3>
                        <div>
                            <button id="printAllHomes" style="background:#3498db;color:#fff;padding:6px 10px;border:none;border-radius:5px;cursor:pointer;margin-right:8px;">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button id="closeAllHomes" style="background:#e74c3c;color:#fff;padding:6px 10px;border:none;border-radius:5px;cursor:pointer;">Close</button>
                        </div>
                    </div>
                    <div style="padding:20px;">
                        ${container.innerHTML}
                    </div>
                `;
                
                overlay.appendChild(modal);
                document.body.appendChild(overlay);
                
                document.getElementById('printAllHomes').addEventListener('click', () => {
                    const printContent = modal.querySelector('[style*="padding:20px"]').cloneNode(true);
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(`
                        <html>
                            <head>
                                <title>All Care Homes Report</title>
                                <style>
                                    body { font-family: Arial, sans-serif; margin: 20px; }
                                    h1, h2, h3 { color: #2c3e50; }
                                    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                                    th { background: #2c3e50; color: white; padding: 8px; border: 1px solid #ddd; }
                                    td { padding: 8px; border: 1px solid #ddd; }
                                    @media print { 
                                        body { margin: 10px; }
                                        table { page-break-inside: auto; }
                                        tr { page-break-inside: avoid; page-break-after: auto; }
                                    }
                                </style>
                            </head>
                            <body>
                                ${printContent.innerHTML}
                            </body>
                        </html>
                    `);
                    printWindow.document.close();
                    setTimeout(() => {
                        printWindow.print();
                    }, 250);
                });
                
                document.getElementById('closeAllHomes').addEventListener('click', () => {
                    if (document.body.contains(overlay)) {
                        document.body.removeChild(overlay);
                    }
                });
                
                overlay.addEventListener('click', (e) => {
                    if(e.target === overlay && document.body.contains(overlay)) {
                        document.body.removeChild(overlay);
                    }
                });
                
            } catch (error) {
                console.error('Error generating All Homes Report PDF:', error);
                alert('Error generating PDF report. Please try again.');
            }
        }

        // Show financial report modal (like in newAccounts.php)
        function showFinancialReportModal() {
            <?php if ($staffHomeId): ?>
            // Fetch financial report data
            fetch('?action=get_financial_report&home_id=<?php echo $staffHomeId; ?>')
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !data.report) {
                        alert('Error loading financial report data');
                        return;
                    }
                    
                    const reportData = data.report;
                    
                    const overlay = document.createElement('div');
                    overlay.className = 'modal-overlay';
                    overlay.style.cssText = `
                        position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                        background: rgba(0,0,0,0.5); display: flex; align-items: center; 
                        justify-content: center; z-index: 1000; backdrop-filter: blur(2px);
                    `;
                    
                    const modal = document.createElement('div');
                    modal.className = 'modal-content';
                    modal.style.cssText = `
                        background: white; border-radius: 10px; max-width: 95%; width: 900px; 
                        max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                    `;
                    
                    // Transaction rows
                    let transactionRows = '';
                    if (reportData.transactions && reportData.transactions.length > 0) {
                        transactionRows = reportData.transactions.map(transaction => `
                            <tr>
                                <td style="padding: 12px; white-space: nowrap;">${new Date(transaction.transaction_date || transaction.created_at).toLocaleDateString()}</td>
                                <td style="padding: 12px; min-width: 100px;">
                                    <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 15px; font-size: 0.8rem; font-weight: 500; white-space: nowrap;
                                        ${transaction.type === 'income' ? 'background: rgba(46, 204, 113, 0.1); color: #2ecc71;' : 
                                          transaction.type === 'expense' ? 'background: rgba(231, 76, 60, 0.1); color: #e74c3c;' : 
                                          'background: rgba(241, 196, 15, 0.1); color: #f1c40f;'}">
                                        <i class="fas fa-${transaction.type === 'income' ? 'arrow-up' : (transaction.type === 'expense' ? 'arrow-down' : 'hand-holding-usd')}"></i>
                                        ${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type && transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction || 'N/A')))}
                                    </span>
                                </td>
                                <td style="padding: 12px; max-width: 200px; word-wrap: break-word;">${transaction.description || '-'}</td>
                                <td style="padding: 12px; min-width: 150px;">${transaction.first_name ? `${transaction.first_name} ${transaction.last_name} (Room ${transaction.room_number})` : 'General'}</td>
                                <td style="padding: 12px; font-weight: 600; color: ${transaction.type === 'income' ? '#2ecc71' : '#e74c3c'}; text-align: right; white-space: nowrap;">
                                    ${transaction.type === 'income' ? '+' : '-'}£${parseFloat(transaction.amount).toFixed(2)}
                                </td>
                                <td style="padding: 12px; text-transform: capitalize; white-space: nowrap;">${transaction.payment_method}</td>
                            </tr>
                        `).join('');
                    } else {
                        transactionRows = '<tr><td colspan="6" style="text-align:center;padding:20px;">No transactions found</td></tr>';
                    }

                    // Monthly trends table
                    let monthlyTrendsRows = '';
                    if (reportData.monthly_trends && reportData.monthly_trends.length > 0) {
                        monthlyTrendsRows = reportData.monthly_trends.map(trend => `
                            <tr>
                                <td>${trend.month}</td>
                                <td style="color: #2ecc71; font-weight: 500;">+£${parseFloat(trend.income).toFixed(2)}</td>
                                <td style="color: #e74c3c; font-weight: 500;">-£${parseFloat(trend.expenses).toFixed(2)}</td>
                                <td style="color: #f39c12; font-weight: 500;">-£${parseFloat(trend.drops).toFixed(2)}</td>
                                <td style="font-weight: 600; color: ${(trend.income - trend.expenses - trend.drops) >= 0 ? '#2ecc71' : '#e74c3c'};">
                                    £${(parseFloat(trend.income) - parseFloat(trend.expenses) - parseFloat(trend.drops)).toFixed(2)}
                                </td>
                            </tr>
                        `).join('');
                    } else {
                        monthlyTrendsRows = '<tr><td colspan="5" style="text-align:center;">No monthly data available</td></tr>';
                    }
                    
                    // Payment methods table
                    let paymentMethodsRows = '';
                    if (reportData.payment_methods && reportData.payment_methods.length > 0) {
                        paymentMethodsRows = reportData.payment_methods.map(method => `
                            <tr>
                                <td>${method.payment_method.toUpperCase()}</td>
                                <td>£${parseFloat(method.total_amount).toFixed(2)}</td>
                                <td>${method.transaction_count}</td>
                            </tr>
                        `).join('');
                    } else {
                        paymentMethodsRows = '<tr><td colspan="3" style="text-align:center;">No payment method data</td></tr>';
                    }
                    
                    modal.innerHTML = `
                        <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; background: #f8f9fa; border-radius: 10px 10px 0 0;">
                            <h3 style="margin: 0; font-size: 1.1rem; color: #2c3e50; display: flex; gap: 8px; align-items: center;">
                                <i class="fas fa-chart-line"></i> Financial Report - ${reportData.home_name}
                            </h3>
                            <div>
                                <button id="printReport" style="background: #3498db; color: #fff; padding: 6px 10px; border: none; border-radius: 5px; cursor: pointer; margin-right: 8px;">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button id="closeReport" style="background: #e74c3c; color: #fff; padding: 6px 10px; border: none; border-radius: 5px; cursor: pointer;">Close</button>
                            </div>
                        </div>
                        <div style="padding: 20px;">
                            <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #2c3e50;">
                                <h2 style="color: #2c3e50; margin: 0 0 10px 0;">Financial Report - ${reportData.home_name}</h2>
                                <div style="color: #7f8c8d; font-size: 14px;">Generated on: ${new Date(reportData.report_date).toLocaleDateString()} at ${new Date(reportData.report_date).toLocaleTimeString()}</div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 30px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                                <div style="text-align: center; padding: 10px;">
                                    <div style="font-size: 1.3rem; font-weight: bold; color: #2c3e50;">£${parseFloat(reportData.total_income).toFixed(2)}</div>
                                    <div style="font-size: 0.8rem; color: #7f8c8d;">Total Income</div>
                                </div>
                                <div style="text-align: center; padding: 10px;">
                                    <div style="font-size: 1.3rem; font-weight: bold; color: #2c3e50;">£${parseFloat(reportData.total_expenses).toFixed(2)}</div>
                                    <div style="font-size: 0.8rem; color: #7f8c8d;">Total Expenses</div>
                                </div>
                                <div style="text-align: center; padding: 10px;">
                                    <div style="font-size: 1.3rem; font-weight: bold; color: #2c3e50;">£${parseFloat(reportData.total_drops).toFixed(2)}</div>
                                    <div style="font-size: 0.8rem; color: #7f8c8d;">Total Drops</div>
                                </div>
                                <div style="text-align: center; padding: 10px;">
                                    <div style="font-size: 1.3rem; font-weight: bold; color: ${reportData.net_balance >= 0 ? '#2ecc71' : '#e74c3c'};">£${parseFloat(reportData.net_balance).toFixed(2)}</div>
                                    <div style="font-size: 0.8rem; color: #7f8c8d;">Net Balance</div>
                                </div>
                                <div style="text-align: center; padding: 10px;">
                                    <div style="font-size: 1.3rem; font-weight: bold; color: #2c3e50;">${reportData.income_count}</div>
                                    <div style="font-size: 0.8rem; color: #7f8c8d;">Income Transactions</div>
                                </div>
                                <div style="text-align: center; padding: 10px;">
                                    <div style="font-size: 1.3rem; font-weight: bold; color: #2c3e50;">${reportData.recent_transactions}</div>
                                    <div style="font-size: 0.8rem; color: #7f8c8d;">Recent Transactions (30 days)</div>
                                </div>
                            </div>
                            
                            <!-- Transaction Details Table -->
                            <div style="margin-bottom: 30px;">
                                <h3 style="color:#2c3e50; margin-bottom:15px;"><i class="fas fa-list-ul"></i> Transaction Details</h3>
                                <div class="transaction-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px;">
                                    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                                        <thead>
                                            <tr style="background: #2c3e50; color: white; position: sticky; top: 0;">
                                                <th style="padding: 12px; text-align: left; width: 12%;">Date</th>
                                                <th style="padding: 12px; text-align: left; width: 15%;">Type</th>
                                                <th style="padding: 12px; text-align: left; width: 25%;">Description</th>
                                                <th style="padding: 12px; text-align: left; width: 23%;">Resident</th>
                                                <th style="padding: 12px; text-align: right; width: 12%;">Amount</th>
                                                <th style="padding: 12px; text-align: left; width: 13%;">Payment Method</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${transactionRows}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr; gap: 30px; margin-bottom: 30px;">
                                <div>
                                    <h3 style="color:#2c3e50; margin-bottom:15px;"><i class="fas fa-chart-line"></i> Monthly Trends (Last 6 Months)</h3>
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <thead>
                                            <tr style="background: #2c3e50; color: white;">
                                                <th style="padding: 10px; text-align: left;">Month</th>
                                                <th style="padding: 10px; text-align: left;">Transfer in</th>
                                                <th style="padding: 10px; text-align: left;">Transfer out</th>
                                                <th style="padding: 10px; text-align: left;">Paid back</th>
                                                <th style="padding: 10px; text-align: left;">Net</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${monthlyTrendsRows}
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div>
                                    <h3 style="color:#2c3e50; margin-bottom:15px;"><i class="fas fa-credit-card"></i> Payment Methods</h3>
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <thead>
                                            <tr style="background: #2c3e50; color: white;">
                                                <th style="padding: 10px; text-align: left;">Method</th>
                                                <th style="padding: 10px; text-align: left;">Amount</th>
                                                <th style="padding: 10px; text-align: left;">Count</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${paymentMethodsRows}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div style="margin-top:30px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                                <p style="margin: 0; color: #7f8c8d; font-size: 0.9rem;">
                                    <i class="fas fa-info-circle"></i> 
                                    This report provides a comprehensive overview of financial transactions, income trends, and payment analysis.
                                </p>
                            </div>
                        </div>
                    `;
                    
                    overlay.appendChild(modal);
                    document.body.appendChild(overlay);

                    // Print functionality
                    document.getElementById('printReport').addEventListener('click', () => {
                        // Clone the modal content for printing
                        const printContent = modal.querySelector('[style*="padding: 20px"]').cloneNode(true);
                        
                        // Find and modify the transaction details container to remove scroll
                        const transactionContainer = printContent.querySelector('.transaction-container');
                        if (transactionContainer) {
                            // Remove scroll properties for print version
                            transactionContainer.style.maxHeight = 'none';
                            transactionContainer.style.overflowY = 'visible';
                            transactionContainer.style.border = '1px solid #ddd';
                            transactionContainer.style.borderRadius = '8px';
                        }
                        
                        const printWindow = window.open('', '_blank');
                        printWindow.document.write(`
                            <html>
                                <head>
                                    <title>Financial Report - ${reportData.home_name}</title>
                                    <style>
                                        body { font-family: Arial, sans-serif; margin: 20px; }
                                        h2, h3 { color: #2c3e50; }
                                        table { width: 100%; border-collapse: collapse; margin: 10px 0; page-break-inside: avoid; }
                                        th { background: #2c3e50; color: white; padding: 8px; }
                                        td { padding: 8px; border-bottom: 1px solid #eee; }
                                        .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0; }
                                        .summary div { text-align: center; padding: 10px; background: #f8f9fa; }
                                        /* Ensure all transactions are visible in print */
                                        .transaction-container { max-height: none !important; overflow: visible !important; }
                                        @media print { 
                                            .fas { display: none; }
                                            table { page-break-inside: auto; }
                                            tr { page-break-inside: avoid; }
                                        }
                                    </style>
                                </head>
                                <body>
                                    ${printContent.innerHTML}
                                </body>
                            </html>
                        `);
                        printWindow.document.close();
                        printWindow.print();
                        printWindow.close();
                    });

                    // Close report
                    document.getElementById('closeReport').addEventListener('click', () => {
                        document.body.removeChild(overlay);
                    });
                    
                    overlay.addEventListener('click', (e) => {
                        if(e.target === overlay) {
                            document.body.removeChild(overlay);
                        }
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading financial report data');
                });
            <?php else: ?>
            alert('Error: No care home assigned to your account.');
            <?php endif; ?>
        }

        // Refresh button
        document.getElementById('btnRefreshData').addEventListener('click', function() {
            location.reload();
        });
    </script>
</body>
</html>


























<?php
// Report display functions
function displayTimeReport($reportData) {
    switch ($reportData['type']) {
        case 'yearly':
            displayYearlyReport($reportData);
            break;
        case 'monthly':
            displayMonthlyReport($reportData);
            break;
        case 'weekly':
            displayWeeklyReport($reportData);
            break;
    }
}

function displayCategoryReport($reportData) {
    switch ($reportData['type']) {
        case 'resident':
            displayResidentReport($reportData);
            break;
        case 'carehome':
            displayCarehomeReport($reportData);
            break;
        case 'all_homes':
            displayAllHomesReport($reportData);
            break;
    }
}

function displayYearlyReport($report) {
    global $pdo, $selectedHomeId;
    
    echo '<div class="report-section">';
    echo '<h4>Yearly Financial Summary - ' . $report['year'] . '</h4>';
    
    // Display home and resident details if available
    if ($selectedHomeId) {
        $homeDetails = getCarehomeDetails($pdo, $selectedHomeId);
        echo '<div style="background:#e8f4fd;padding:15px;border-radius:5px;margin-bottom:20px;">';
        echo '<h5>Care Home: ' . htmlspecialchars($homeDetails['name']) . '</h5>';
        echo '<p><strong>Address:</strong> ' . htmlspecialchars($homeDetails['address']) . '</p>';
        
        // Get residents for this home (filter by resident if specified)
        $residentQuery = "SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ?";
        $residentParams = [$selectedHomeId];
        
        if (!empty($_GET['resident_id'])) {
            $residentQuery .= " AND id = ?";
            $residentParams[] = $_GET['resident_id'];
        }
        
        $residentQuery .= " ORDER BY first_name, last_name";
        
        $stmt = $pdo->prepare($residentQuery);
        $stmt->execute($residentParams);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($residents)) {
            echo '<h5 style="margin-top:15px;">Resident(s):</h5>';
            echo '<table class="report-table" style="font-size:0.9rem;">';
            echo '<thead><tr><th>Name</th><th>Room</th></tr></thead><tbody>';
            foreach ($residents as $res) {
                echo '<tr><td>' . htmlspecialchars($res['first_name'] . ' ' . $res['last_name']) . '</td>';
                echo '<td>' . htmlspecialchars($res['room_number']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    echo '<h5>Monthly Financial Summary</h5>';
    echo '<table class="report-table">';
    echo '<thead><tr>
            <th>Month</th>
            <th>Transfer in</th>
            <th>Transfer out</th>
            <th>Paid back</th>
            <th>Net Balance</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($report['months'] as $month => $data) {
        $monthName = date('F', mktime(0, 0, 0, $month, 1));
        $netClass = $data['net'] >= 0 ? 'financial-positive' : 'financial-negative';
        
        echo '<tr>
                <td>' . $monthName . '</td>
                <td>£' . number_format($data['income'], 2) . '</td>
                <td>£' . number_format($data['expense'], 2) . '</td>
                <td>£' . number_format($data['drop'], 2) . '</td>
                <td class="' . $netClass . '">£' . number_format($data['net'], 2) . '</td>
              </tr>';
    }
    
    $totalNetClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
    echo '<tr style="background:#f8f9fa; font-weight:bold;">
            <td>Total</td>
            <td>£' . number_format($report['totals']['income'], 2) . '</td>
            <td>£' . number_format($report['totals']['expense'], 2) . '</td>
            <td>£' . number_format($report['totals']['drop'], 2) . '</td>
            <td class="' . $totalNetClass . '">£' . number_format($report['totals']['net'], 2) . '</td>
          </tr>';
    
    echo '</tbody></table>';
    
    // Display transaction details
    if ($selectedHomeId) {
        echo '<h5 style="margin-top:30px;">Transaction Details</h5>';
        
        $query = "
            SELECT t.*, r.first_name, r.last_name, r.room_number 
            FROM transactions t 
            LEFT JOIN residents r ON t.resident_id = r.id 
            WHERE t.home_id = ? AND YEAR(COALESCE(t.transaction_date, t.created_at)) = ?";
        
        $params = [$selectedHomeId, $report['year']];
        
        // Add resident filter if specified
        if (!empty($_GET['resident_id'])) {
            $query .= " AND t.resident_id = ?";
            $params[] = $_GET['resident_id'];
        }
        
    $query .= " ORDER BY COALESCE(t.transaction_date, t.created_at) ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($transactions)) {
            echo '<table class="report-table">';
            echo '<thead><tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Resident</th>
                    <th>Description</th>
                    <th>Payment Method</th>
                    <th>Amount</th>
                    <th>Net Amount</th>
                    <th>Reference</th>
                  </tr></thead>';
            echo '<tbody>';

            // Calculate running total (oldest to newest)
            $runningTotal = 0;
                foreach ($transactions as $tx) {
                $amount = floatval($tx['amount']);
                if ($tx['type'] === 'income') {
                    $runningTotal += $amount;
                } else {
                    $runningTotal -= $amount;
                }
                $amountClass = $tx['type'] === 'income' ? 'financial-positive' : 'financial-negative';
                $amountPrefix = $tx['type'] === 'income' ? '+' : '-';
                $netAmountClass = $runningTotal >= 0 ? 'financial-positive' : 'financial-negative';
                $residentName = $tx['first_name'] ? htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name'] . ' (Room ' . $tx['room_number'] . ')') : 'General';

                // Build styled badge for transaction type
                $typeVal = $tx['type'] ?? '';
                if ($typeVal === 'income') {
                    $typeStyle = 'background: rgba(46, 204, 113, 0.1); color: #2ecc71;';
                    $typeIcon = 'arrow-up';
                    $typeLabel = 'Transfer in';
                } elseif ($typeVal === 'expense') {
                    $typeStyle = 'background: rgba(231, 76, 60, 0.1); color: #e74c3c;';
                    $typeIcon = 'arrow-down';
                    $typeLabel = 'Transfer out';
                } else {
                    $typeStyle = 'background: rgba(241, 196, 15, 0.1); color: #f1c40f;';
                    $typeIcon = 'hand-holding-usd';
                    $typeLabel = ($typeVal === 'drop') ? 'Paid back' : (isset($typeVal) ? ucfirst($typeVal) : 'N/A');
                }

                echo '<tr>
                        <td>' . date('M j, Y', strtotime($tx['transaction_date'] ?? $tx['created_at'])) . '</td>
                        <td><span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:15px;font-size:0.8rem;font-weight:500;white-space:nowrap;' . $typeStyle . '"><i class="fas fa-' . $typeIcon . '"></i> ' . htmlspecialchars($typeLabel) . '</span></td>
                        <td>' . $residentName . '</td>
                        <td>' . htmlspecialchars($tx['description']) . '</td>
                        <td>' . ucfirst($tx['payment_method']) . '</td>
                        <td class="' . $amountClass . '">' . $amountPrefix . '£' . number_format($amount, 2) . '</td>
                        <td class="' . $netAmountClass . '">£' . number_format($runningTotal, 2) . '</td>
                        <td>' . htmlspecialchars($tx['reference_no']) . '</td>
                      </tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>No transactions found for this period.</p>';
        }
    }
    
    echo '</div>';
}

function displayMonthlyReport($report) {
    global $pdo, $selectedHomeId;
    
    echo '<div class="report-section">';
    echo '<h4>Monthly Financial Summary - ' . date('F Y', mktime(0, 0, 0, $report['month'], 1, $report['year'])) . '</h4>';
    
    // Display home and resident details if available
    if ($selectedHomeId) {
        $homeDetails = getCarehomeDetails($pdo, $selectedHomeId);
        echo '<div style="background:#e8f4fd;padding:15px;border-radius:5px;margin-bottom:20px;">';
        echo '<h5>Care Home: ' . htmlspecialchars($homeDetails['name']) . '</h5>';
        echo '<p><strong>Address:</strong> ' . htmlspecialchars($homeDetails['address']) . '</p>';
        
        // Get residents for this home (filter by resident if specified)
        $residentQuery = "SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ?";
        $residentParams = [$selectedHomeId];
        
        if (!empty($_GET['resident_id'])) {
            $residentQuery .= " AND id = ?";
            $residentParams[] = $_GET['resident_id'];
        }
        
        $residentQuery .= " ORDER BY first_name, last_name";
        
        $stmt = $pdo->prepare($residentQuery);
        $stmt->execute($residentParams);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($residents)) {
            echo '<h5 style="margin-top:15px;">Resident(s):</h5>';
            echo '<table class="report-table" style="font-size:0.9rem;">';
            echo '<thead><tr><th>Name</th><th>Room</th></tr></thead><tbody>';
            foreach ($residents as $res) {
                echo '<tr><td>' . htmlspecialchars($res['first_name'] . ' ' . $res['last_name']) . '</td>';
                echo '<td>' . htmlspecialchars($res['room_number']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    echo '<h5>Weekly Financial Summary</h5>';
    echo '<table class="report-table">';
    echo '<thead><tr>
            <th>Week</th>
            <th>Transfer in</th>
            <th>Transfer out</th>
            <th>Paid back</th>
            <th>Net Balance</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($report['weeks'] as $week => $data) {
        $netClass = $data['net'] >= 0 ? 'financial-positive' : 'financial-negative';
        
        echo '<tr>
                <td>Week ' . $week . '</td>
                <td>£' . number_format($data['income'], 2) . '</td>
                <td>£' . number_format($data['expense'], 2) . '</td>
                <td>£' . number_format($data['drop'], 2) . '</td>
                <td class="' . $netClass . '">£' . number_format($data['net'], 2) . '</td>
              </tr>';
    }
    
    $totalNetClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
    echo '<tr style="background:#f8f9fa; font-weight:bold;">
            <td>Total</td>
            <td>£' . number_format($report['totals']['income'], 2) . '</td>
            <td>£' . number_format($report['totals']['expense'], 2) . '</td>
            <td>£' . number_format($report['totals']['drop'], 2) . '</td>
            <td class="' . $totalNetClass . '">£' . number_format($report['totals']['net'], 2) . '</td>
          </tr>';
    
    echo '</tbody></table>';
    
    // Display transaction details
    if ($selectedHomeId) {
        echo '<h5 style="margin-top:30px;">Transaction Details</h5>';
        
        $query = "
            SELECT t.*, r.first_name, r.last_name, r.room_number 
            FROM transactions t 
            LEFT JOIN residents r ON t.resident_id = r.id 
            WHERE t.home_id = ? AND YEAR(COALESCE(t.transaction_date, t.created_at)) = ? AND MONTH(COALESCE(t.transaction_date, t.created_at)) = ?";
        
        $params = [$selectedHomeId, $report['year'], $report['month']];
        
        // Add resident filter if specified
        if (!empty($_GET['resident_id'])) {
            $query .= " AND t.resident_id = ?";
            $params[] = $_GET['resident_id'];
        }
        
    $query .= " ORDER BY COALESCE(t.transaction_date, t.created_at) ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($transactions)) {
            echo '<table class="report-table">';
            echo '<thead><tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Resident</th>
                    <th>Description</th>
                    <th>Payment Method</th>
                    <th>Amount</th>
                    <th>Net Amount</th>
                    <th>Reference</th>
                  </tr></thead>';
            echo '<tbody>';
            
            // First calculate total balance
            $totalBalance = 0;
            foreach ($transactions as $tx) {
                $amount = floatval($tx['amount']);
                if ($tx['type'] === 'income') {
                    $totalBalance += $amount;
                } else {
                    $totalBalance -= $amount;
                }
            }

            // Now display transactions with running total
            $runningTotal = 0; // Start from 0 and accumulate
            foreach ($transactions as $tx) {
                $amountClass = $tx['type'] === 'income' ? 'financial-positive' : 'financial-negative';
                $amountPrefix = $tx['type'] === 'income' ? '+' : '-';
                $amount = floatval($tx['amount']);
                
                // Update running total based on transaction type
                if ($tx['type'] === 'income') {
                    $runningTotal += $amount;
                } else {
                    $runningTotal -= $amount;
                }
                
                $netAmountClass = $runningTotal >= 0 ? 'financial-positive' : 'financial-negative';
                $residentName = $tx['first_name'] ? htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name'] . ' (Room ' . $tx['room_number'] . ')') : 'General';
                
                // Build styled badge for transaction type
                $typeVal = $tx['type'] ?? '';
                if ($typeVal === 'income') {
                    $typeStyle = 'background: rgba(46, 204, 113, 0.1); color: #2ecc71;';
                    $typeIcon = 'arrow-up';
                    $typeLabel = 'Transfer in';
                } elseif ($typeVal === 'expense') {
                    $typeStyle = 'background: rgba(231, 76, 60, 0.1); color: #e74c3c;';
                    $typeIcon = 'arrow-down';
                    $typeLabel = 'Transfer out';
                } else {
                    $typeStyle = 'background: rgba(241, 196, 15, 0.1); color: #f1c40f;';
                    $typeIcon = 'hand-holding-usd';
                    $typeLabel = ($typeVal === 'drop') ? 'Paid back' : (isset($typeVal) ? ucfirst($typeVal) : 'N/A');
                }

                echo '<tr>
                        <td>' . date('M j, Y', strtotime($tx['transaction_date'] ?? $tx['created_at'])) . '</td>
                        <td><span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:15px;font-size:0.8rem;font-weight:500;white-space:nowrap;' . $typeStyle . '"><i class="fas fa-' . $typeIcon . '"></i> ' . htmlspecialchars($typeLabel) . '</span></td>
                        <td>' . $residentName . '</td>
                        <td>' . htmlspecialchars($tx['description']) . '</td>
                        <td>' . ucfirst($tx['payment_method']) . '</td>
                        <td class="' . $amountClass . '">' . $amountPrefix . '£' . number_format($amount, 2) . '</td>
                        <td class="' . $netAmountClass . '">£' . number_format($runningTotal, 2) . '</td>
                        <td>' . htmlspecialchars($tx['reference_no']) . '</td>
                      </tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No transactions found for this period.</p>';
        }
    }
    
    echo '</div>';
}

function displayWeeklyReport($report) {
    echo '<div class="report-section">';
    echo '<h4>Weekly Financial Summary - Week ' . $report['week'] . ' of ' . $report['year'] . '</h4>';
    
    echo '<table class="report-table">';
    echo '<thead><tr>
            <th>Date</th>
            <th>Income</th>
            <th>Expenses</th>
            <th>Drops</th>
            <th>Net Balance</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($report['days'] as $date => $data) {
        $netClass = $data['net'] >= 0 ? 'financial-positive' : 'financial-negative';
        
        echo '<tr>
                <td>' . date('M j, Y', strtotime($date)) . '</td>
                <td>£' . number_format($data['income'], 2) . '</td>
                <td>£' . number_format($data['expense'], 2) . '</td>
                <td>£' . number_format($data['drop'], 2) . '</td>
                <td class="' . $netClass . '">£' . number_format($data['net'], 2) . '</td>
              </tr>';
    }
    
    $totalNetClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
    echo '<tr style="background:#f8f9fa; font-weight:bold;">
            <td>Total</td>
            <td>£' . number_format($report['totals']['income'], 2) . '</td>
            <td>£' . number_format($report['totals']['expense'], 2) . '</td>
            <td>£' . number_format($report['totals']['drop'], 2) . '</td>
            <td class="' . $totalNetClass . '">£' . number_format($report['totals']['net'], 2) . '</td>
          </tr>';
    
    echo '</tbody></table>';
    echo '</div>';
}

function displayResidentReport($report) {
    $resident = $report['resident'];
    
    echo '<div class="report-section">';
    echo '<h4>Resident Financial Report - ' . htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) . '</h4>';
    
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">';
    echo '<div style="background:#e8f4fd; padding:15px; border-radius:5px;">
            <h5>Resident Details</h5>
            <p><strong>Room:</strong> ' . htmlspecialchars($resident['room_number']) . '</p>
            <p><strong>Admission Date:</strong> ' . htmlspecialchars($resident['admission_date']) . '</p>
            <p><strong>NHS Number:</strong> ' . htmlspecialchars($resident['nhs_number']) . '</p>
          </div>';
    echo '</div>';
    
    echo '<table class="report-table">';
    echo '<thead><tr>
            <th>Transaction Date</th>
            <th>Type</th>
            <th>Description</th>
            <th>Payment Method</th>
            <th>Amount</th>
            <th>Reference</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($report['transactions'] as $transaction) {
        $amountClass = $transaction['type'] === 'income' ? 'financial-positive' : 'financial-negative';
        $amountPrefix = $transaction['type'] === 'income' ? '+' : '-';
        
        echo '<tr>
                <td>' . date('M j, Y H:i', strtotime($transaction['transaction_date'] ?? $transaction['created_at'])) . '</td>
                <td>' . ucfirst($transaction['type']) . '</td>
                <td>' . htmlspecialchars($transaction['description']) . '</td>
                <td>' . ucfirst($transaction['payment_method']) . '</td>
                <td class="' . $amountClass . '">' . $amountPrefix . '£' . number_format($transaction['amount'], 2) . '</td>
                <td>' . htmlspecialchars($transaction['reference_no']) . '</td>
              </tr>';
    }
    
    echo '</tbody></table>';
    
    $netClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
    echo '<div style="background:#f8f9fa; padding:15px; border-radius:5px; margin-top:20px;">
            <h5>Financial Summary</h5>
            <p><strong>Total Income:</strong> £' . number_format($report['totals']['income'], 2) . '</p>
            <p><strong>Total Expenses:</strong> £' . number_format($report['totals']['expense'], 2) . '</p>
            <p><strong>Total Drops:</strong> £' . number_format($report['totals']['drop'], 2) . '</p>
            <p><strong>Net Balance:</strong> <span class="' . $netClass . '">£' . number_format($report['totals']['net'], 2) . '</span></p>
          </div>';
    
    echo '</div>';
}

function displayCarehomeReport($report) {
    $carehome = $report['carehome'];
    
    echo '<div class="report-section">';
    echo '<h4>Care Home Report - ' . htmlspecialchars($carehome['name']) . '</h4>';
    
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">';
    echo '<div style="background:#e8f4fd; padding:15px; border-radius:5px;">
            <h5>Care Home Details</h5>
            <p><strong>Address:</strong> ' . htmlspecialchars($carehome['address']) . '</p>
            <p><strong>Total Rooms:</strong> ' . $carehome['total_rooms'] . '</p>
            <p><strong>Current Residents:</strong> ' . $carehome['residents'] . '</p>
          </div>';
    echo '</div>';
    
    echo '<h5>Residents Financial Summary</h5>';
    echo '<table class="report-table">';
    echo '<thead><tr>
            <th>Resident</th>
            <th>Room</th>
            <th>Income</th>
            <th>Expenses</th>
            <th>Drops</th>
            <th>Net Balance</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($report['residents'] as $resident) {
        $net = $resident['income'] - $resident['expense'] - $resident['drop_amount'];
        $netClass = $net >= 0 ? 'financial-positive' : 'financial-negative';
        
        echo '<tr>
                <td>' . htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) . '</td>
                <td>' . htmlspecialchars($resident['room_number']) . '</td>
                <td>£' . number_format($resident['income'], 2) . '</td>
                <td>£' . number_format($resident['expense'], 2) . '</td>
                <td>£' . number_format($resident['drop_amount'], 2) . '</td>
                <td class="' . $netClass . '">£' . number_format($net, 2) . '</td>
              </tr>';
    }
    
    $totalNetClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
    echo '<tr style="background:#f8f9fa; font-weight:bold;">
            <td colspan="2">Total</td>
            <td>£' . number_format($report['totals']['income'], 2) . '</td>
            <td>£' . number_format($report['totals']['expense'], 2) . '</td>
            <td>£' . number_format($report['totals']['drop'], 2) . '</td>
            <td class="' . $totalNetClass . '">£' . number_format($report['totals']['net'], 2) . '</td>
          </tr>';
    
    echo '</tbody></table>';
    echo '</div>';
}

function displayAllHomesReport($report) {
    echo '<div class="report-section">';
    echo '<h4>All Care Homes Combined Report</h4>';
    
    echo '<table class="report-table">';
    echo '<thead><tr>
            <th>Care Home</th>
            <th>Residents</th>
            <th>Income</th>
            <th>Expenses</th>
            <th>Drops</th>
            <th>Net Balance</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($report['carehomes'] as $homeId => $homeData) {
        $netClass = $homeData['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
        
        echo '<tr>
                <td>' . htmlspecialchars($homeData['name']) . '</td>
                <td>' . $homeData['residents_count'] . '</td>
                <td>£' . number_format($homeData['totals']['income'], 2) . '</td>
                <td>£' . number_format($homeData['totals']['expense'], 2) . '</td>
                <td>£' . number_format($homeData['totals']['drop'], 2) . '</td>
                <td class="' . $netClass . '">£' . number_format($homeData['totals']['net'], 2) . '</td>
              </tr>';
    } 
    
    $totalNetClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
    echo '<tr style="background:#f8f9fa; font-weight:bold;">
            <td>Grand Total</td>
            <td>' . $report['totals']['residents'] . '</td>
            <td>£' . number_format($report['totals']['income'], 2) . '</td>
            <td>£' . number_format($report['totals']['expense'], 2) . '</td>
            <td>£' . number_format($report['totals']['drop'], 2) . '</td>
            <td class="' . $totalNetClass . '">£' . number_format($report['totals']['net'], 2) . '</td>
          </tr>';
    
    echo '</tbody></table>';
    echo '</div>';
}

// Mobile-friendly display for time reports (compact cards)
function displayTimeReportMobile($report) {
    switch ($report['type']) {
        case 'yearly':
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            for ($m = 1; $m <= 12; $m++) {
                $data = $report['months'][$m] ?? ['income'=>0,'expense'=>0,'drop'=>0,'net'=>0];
                $monthName = date('F', mktime(0,0,0,$m,1));
                $netClass = $data['net'] >= 0 ? 'financial-positive' : 'financial-negative';
                echo '<div style="background:#fff;padding:12px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.06);">';
                echo '<strong>' . $monthName . '</strong>';
                echo '<div>Income: £' . number_format($data['income'],2) . '</div>';
                echo '<div>Expenses: £' . number_format($data['expense'],2) . '</div>';
                echo '<div>Drops: £' . number_format($data['drop'],2) . '</div>';
                echo '<div class="' . $netClass . '">Net: £' . number_format($data['net'],2) . '</div>';
                echo '</div>';
            }
            echo '</div>';
            break;
        case 'monthly':
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            foreach ($report['weeks'] as $week => $data) {
                $netClass = $data['net'] >= 0 ? 'financial-positive' : 'financial-negative';
                echo '<div style="background:#fff;padding:12px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.06);">';
                echo '<strong>Week ' . htmlspecialchars($week) . '</strong>';
                echo '<div>Income: £' . number_format($data['income'],2) . '</div>';
                echo '<div>Expenses: £' . number_format($data['expense'],2) . '</div>';
                echo '<div>Drops: £' . number_format($data['drop'],2) . '</div>';
                echo '<div class="' . $netClass . '">Net: £' . number_format($data['net'],2) . '</div>';
                echo '</div>';
            }
            echo '</div>';
            break;
        case 'weekly':
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            foreach ($report['days'] as $day => $data) {
                $netClass = $data['net'] >= 0 ? 'financial-positive' : 'financial-negative';
                echo '<div style="background:#fff;padding:12px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.06);">';
                echo '<strong>' . date('D, j M', strtotime($day)) . '</strong>';
                echo '<div>Income: £' . number_format($data['income'],2) . '</div>';
                echo '<div>Expenses: £' . number_format($data['expense'],2) . '</div>';
                echo '<div>Drops: £' . number_format($data['drop'],2) . '</div>';
                echo '<div class="' . $netClass . '">Net: £' . number_format($data['net'],2) . '</div>';
                echo '</div>';
            }
            echo '</div>';
            break;
    }
}

// Mobile-friendly display for category reports
function displayCategoryReportMobile($report) {
    switch ($report['type']) {
        case 'resident':
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            echo '<div style="background:#fff;padding:12px;border-radius:8px;">';
            echo '<strong>' . htmlspecialchars($report['resident']['first_name'] . ' ' . $report['resident']['last_name']) . '</strong>';
            echo '<div>Income: £' . number_format($report['totals']['income'],2) . '</div>';
            echo '<div>Expenses: £' . number_format($report['totals']['expense'],2) . '</div>';
            echo '<div>Drops: £' . number_format($report['totals']['drop'],2) . '</div>';
            $netClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
            echo '<div class="' . $netClass . '">Net: £' . number_format($report['totals']['net'],2) . '</div>';
            echo '</div></div>';
            break;
        case 'carehome':
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            echo '<div style="background:#fff;padding:12px;border-radius:8px;">';
            echo '<strong>' . htmlspecialchars($report['carehome']['name']) . '</strong>';
            echo '<div>Income: £' . number_format($report['totals']['income'],2) . '</div>';
            echo '<div>Expenses: £' . number_format($report['totals']['expense'],2) . '</div>';
            echo '<div>Drops: £' . number_format($report['totals']['drop'],2) . '</div>';
            $netClass = $report['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
            echo '<div class="' . $netClass . '">Net: £' . number_format($report['totals']['net'],2) . '</div>';
            echo '</div></div>';
            break;
        case 'all_homes':
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            foreach ($report['carehomes'] as $home) {
                $netClass = $home['totals']['net'] >= 0 ? 'financial-positive' : 'financial-negative';
                echo '<div style="background:#fff;padding:12px;border-radius:8px;">';
                echo '<strong>' . htmlspecialchars($home['name']) . '</strong>';
                echo '<div>Income: £' . number_format($home['totals']['income'],2) . '</div>';
                echo '<div>Expenses: £' . number_format($home['totals']['expense'],2) . '</div>';
                echo '<div>Drops: £' . number_format($home['totals']['drop'],2) . '</div>';
                echo '<div class="' . $netClass . '">Net: £' . number_format($home['totals']['net'],2) . '</div>';
                echo '</div>';
            }
            echo '</div>';
            break;
    }
}
?>