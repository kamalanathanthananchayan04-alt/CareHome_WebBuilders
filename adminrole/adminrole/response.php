<?php
session_start();

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_user';
$dbPass = 'nWr87zcyZnpt';
$dbName = 'carehomesurvey_db';

function get_db_connection($dbHost, $dbUser, $dbPass, $dbName) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

// Session check
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Check if it's an API request
$is_api_request = isset($_GET['action']);

// Handle Excel export
if ($is_api_request && $_GET['action'] === 'export_excel') {
    if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        exit();
    }
    
    $export_type = isset($_GET['type']) ? $_GET['type'] : 'all';
    $export_query = isset($_GET['query']) ? $_GET['query'] : '';
    $export_service = isset($_GET['service']) ? $_GET['service'] : '';
    $mysqli = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($mysqli) {
        $query = "SELECT * FROM responses";
        $where_conditions = [];
        
        if ($export_type !== 'all') {
            $where_conditions[] = "type = '" . $mysqli->real_escape_string($export_type) . "'";
        }
        
        if (!empty($export_query)) {
            $where_conditions[] = "full_name LIKE '%" . $mysqli->real_escape_string($export_query) . "%'";
        }
        
        if (!empty($export_service)) {
            $where_conditions[] = "Q1 = '" . $mysqli->real_escape_string($export_service) . "'";
        }
        
        if (!empty($where_conditions)) {
            $query .= " WHERE " . implode(" AND ", $where_conditions);
        }
        
        $result = $mysqli->query($query);
        
        $filename = 'survey_responses_' . $export_type . '_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        $headers = ['Respondent', 'Contact', 'Service', 'Submitted'];
        fputcsv($output, $headers);
        
        while ($row = $result->fetch_assoc()) {
            $data = [
                $row['full_name'],
                $row['tel'],
                $row['Q1'] ?? '',
                $row['created_at']
            ];
            fputcsv($output, $data);
        }
        
        fclose($output);
        $mysqli->close();
        exit();
    }
}

// Session check for API requests
if ($is_api_request) {
    if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
}

// Get response type from URL
$resp_type = isset($_GET['type']) ? $_GET['type'] : '';

// Handle search query
$query = isset($_GET['query']) ? $_GET['query'] : '';

// Handle service filter
$service_filter = isset($_GET['service']) ? $_GET['service'] : '';

// Handle delete action
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $conn = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
    if ($conn) {
        $stmt = $conn->prepare("DELETE FROM responses WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Redirect to avoid resubmission on refresh
        header("Location: response.php?type=" . urlencode($resp_type));
        exit();
    }
}

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch responses from database
$conn = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
$responses = [];
$total_count = 0;
$service_options = [];

if ($conn) {
    // Fetch unique service options for dropdown
    $service_sql = "SELECT DISTINCT Q1 as service FROM responses WHERE Q1 IS NOT NULL AND Q1 != '' ORDER BY Q1";
    $service_result = $conn->query($service_sql);
    if ($service_result) {
        while ($row = $service_result->fetch_assoc()) {
            $service_options[] = $row['service'];
        }
        $service_result->free();
    }

    // Build query based on filters
    $where_conditions = [];
    $params = [];
    $types = "";
    
    if (!empty($resp_type)) {
        $where_conditions[] = "type = ?";
        $params[] = $resp_type;
        $types .= "s";
    }
    
    if (!empty($query)) {
        $where_conditions[] = "full_name LIKE ?";
        $params[] = "%" . $query . "%";
        $types .= "s";
    }
    
    if (!empty($service_filter)) {
        $where_conditions[] = "Q1 = ?";
        $params[] = $service_filter;
        $types .= "s";
    }
    
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    }
    
    // ---- Get total count for pagination ----
    $count_sql = "SELECT COUNT(*) as total FROM responses $where_clause";
    $total_count = 0;

    $stmt = $conn->prepare($count_sql);
    if (!empty($where_conditions)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($total_count);
    $stmt->fetch();
    $stmt->close();

    // ---- Get paginated results ----
    $sql = "SELECT id, full_name, email, tel, Q1, created_at, type, job_role, department
            FROM responses 
            $where_clause 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?";
    
    // Add pagination params
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $stmt->bind_result($id, $full_name, $email, $tel, $Q1, $created_at, $type, $job_role, $department);
    
    $responses = [];
    while ($stmt->fetch()) {
        $responses[] = [
            'id' => $id,
            'full_name' => $full_name,
            'email' => $email,
            'tel' => $tel,
            'service' => $Q1,
            'created_at' => $created_at,
            'type' => $type,
            'job_role' => $job_role,
            'department' => $department
        ];
    }

    $stmt->close();
    $conn->close();
}

// Calculate pagination
$total_pages = ceil($total_count / $per_page);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Care Home Management Responses</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
    
    .btn-primary { background: #3498db; color: #fff; }
    .btn-success { background: #2ecc71; color: #fff; }
    .btn-secondary { background: #9b59b6; color: #fff; }
    .btn-warning { background: #f39c12; color: #fff; }
    .btn-danger { background: #e74c3c; color: #fff; }
    .btn-info { background: #17a2b8; color: #fff; }
    
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
    
    .logout-btn:hover { background: #c0392b; }
    
    /* Content Card Styles */
    .content-card {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        padding: 25px;
        margin-bottom: 25px;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }
    
    .page-title {
        color: #2c3e50;
        font-weight: 600;
        margin: 0;
    }
    
    /* Filter and Search Styles */
    .filter-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    
    .filter-tabs .btn {
        border-radius: 30px;
        padding: 8px 20px;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .search-box {
        position: relative;
        max-width: 300px;
        width: 100%;
    }
    
    .search-box input {
        padding-left: 40px;
        border-radius: 30px;
        border: 1px solid #ddd;
        width: 100%;
        padding: 10px 15px 10px 40px;
        font-size: 14px;
    }
    
    .search-box i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
    }
    
    /* Filter Row Styles */
    .filter-row {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        min-width: 200px;
        flex: 1;
    }
    
    .filter-group label {
        font-weight: 500;
        margin-bottom: 5px;
        color: #2c3e50;
    }
    
    /* Table Styles */
    .table-container {
        overflow-x: auto;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        -webkit-overflow-scrolling: touch;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        min-width: 600px;
    }
    
    .data-table th {
        background: #f8f9fa;
        padding: 15px 12px;
        text-align: left;
        font-weight: 600;
        color: #2c3e50;
        border-bottom: 1px solid #dee2e6;
    }
    
    .data-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #eee;
        vertical-align: middle;
    }
    
    .data-table tr:last-child td {
        border-bottom: none;
    }
    
    .data-table tr:hover {
        background-color: #f8f9fa;
    }
    
    /* User Avatar Styles */
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        margin-right: 12px;
        background: #3498db;
        font-size: 0.9rem;
    }
    
    .user-info-cell {
        display: flex;
        align-items: center;
    }
    
    .user-details {
        display: flex;
        flex-direction: column;
    }
    
    .user-name {
        font-weight: 600;
        color: #2c3e50;
    }
    
    .user-email {
        font-size: 0.85rem;
        color: #6c757d;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        transition: all 0.3s ease;
        text-decoration: none;
    }
    
    .btn-view {
        background: rgba(52, 152, 219, 0.1);
        color: #3498db;
    }
    
    .btn-view:hover {
        background: #3498db;
        color: white;
        transform: translateY(-2px);
    }
    
    .btn-delete {
        background: rgba(231, 76, 60, 0.1);
        color: #e74c3c;
    }
    
    .btn-delete:hover {
        background: #e74c3c;
        color: white;
        transform: translateY(-2px);
    }
    
    /* Pagination Styles */
    .pagination-container {
        display: flex;
        justify-content: center;
        margin-top: 25px;
    }
    
    .pagination {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }
    
    .pagination .page-link {
        color: #2c3e50;
        border: 1px solid #dee2e6;
        padding: 8px 16px;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .pagination .page-item.active .page-link {
        background: #3498db;
        border-color: #3498db;
        color: white;
    }
    
    .pagination .page-link:hover {
        background: #e9ecef;
        border-color: #dee2e6;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
    }
    
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 15px;
        color: #dee2e6;
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge-staff {
        background: rgba(52, 152, 219, 0.1);
        color: #3498db;
    }
    
    .badge-relative {
        background: rgba(46, 204, 113, 0.1);
        color: #2ecc71;
    }
    
    .badge-user {
        background: rgba(155, 89, 182, 0.1);
        color: #9b59b6;
    }
    
    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000;
        padding: 15px;
        box-sizing: border-box;
    }
    
    .modal-content {
        background: white;
        border-radius: 10px;
        width: 90%;
        max-width: 800px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        border-bottom: 1px solid #eee;
        background: #f8f9fa;
        border-radius: 10px 10px 0 0;
    }
    
    .modal-header h3 {
        margin: 0;
        color: #2c3e50;
    }
    
    .modal-body {
        padding: 20px;
    }
    
    .report-container {
        font-family: Arial, sans-serif;
    }
    
    .report-header {
        text-align: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #2c3e50;
        padding-bottom: 15px;
    }
    
    .report-title {
        color: #2c3e50;
        margin: 0 0 10px 0;
    }
    
    .report-date {
        color: #7f8c8d;
        font-size: 14px;
    }
    
    .report-summary {
        margin-top: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 6px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    
    .summary-item {
        text-align: center;
        padding: 10px;
    }
    
    .summary-value {
        font-size: 1.5rem;
        font-weight: bold;
        color: #2c3e50;
    }
    
    .summary-label {
        font-size: 0.9rem;
        color: #7f8c8d;
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
    
    /* Mobile Responsive Design */
    @media (max-width: 1200px) {
        .filter-group { min-width: 180px; }
    }
    
    @media (max-width: 992px) {
        .dashboard-container { flex-direction: column; }
        
        .sidebar {
            width: 100%;
            position: fixed;
            height: 100vh;
            transform: translateX(-100%);
        }
        
        .sidebar.active { transform: translateX(0); }
        
        
        
        .main-content {
            padding: 15px;
            margin-left: 0;
        }
        
        .mobile-menu-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .main-header { margin-top: 60px; }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .search-box { max-width: 100%; }
        
        .filter-row { flex-direction: column; }
        .filter-group { min-width: 100%; }
    }
    
    @media (max-width: 768px) {
        .main-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .filter-tabs {
            justify-content: center;
            gap: 8px;
        }
        
        .filter-tabs .btn {
            padding: 6px 15px;
            font-size: 0.85rem;
        }
        
        .content-card { padding: 20px; }
        
        .table-container {
            font-size: 0.9rem;
        }
        
        .data-table th, 
        .data-table td {
            padding: 10px 8px;
        }
        
        .user-info-cell {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .user-avatar {
            margin-right: 0;
            margin-bottom: 8px;
        }
        
        .action-buttons {
            flex-direction: column;
            gap: 5px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            font-size: 0.8rem;
        }
        
        .report-summary {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 576px) {
        .main-content { padding: 10px; }
        .content-card { padding: 15px; }
        
        .main-header h1 { font-size: 1.5rem; }
        .page-title { font-size: 1.3rem; }
        
        .filter-tabs {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-tabs .btn {
            text-align: center;
            justify-content: center;
        }
        
        .data-table { min-width: 500px; }
        
        .pagination {
            justify-content: center;
        }
        
        .pagination .page-link {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        .modal-body { padding: 15px; }
        .modal-header { padding: 15px; }
        
        .report-summary {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .summary-value { font-size: 1.3rem; }
    }
    
    @media (max-width: 480px) {
        .main-header h1 { font-size: 1.3rem; }
        .page-title { font-size: 1.1rem; }
        
        .user-details .user-name { font-size: 0.9rem; }
        .user-details .user-email { font-size: 0.8rem; }
        
        .status-badge { font-size: 0.7rem; }
        
        .data-table th, 
        .data-table td {
            padding: 8px 6px;
            font-size: 0.8rem;
        }
        
        .modal-content {
            width: 95%;
            margin: 10px;
        }
    }
</style>
</head>
<body>
    


       <div class="dashboard-container">
        
          <!-- Mobile Menu Toggle -->
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Notification Container -->
        <div class="notification-container" id="notificationContainer"></div>
        
        
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-home"></i> <span>Master Admin</span></h2>
            </div>
            <ul class="sidebar-menu">
                <li class="menu-item"><a href="adash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> Accounts</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item active"><a href="response.php"><i class="fas fa-comment-dots"></i> Response</a></li>
                <li class="menu-item"><a href="responseCount.php"><i class="fas fa-chart-pie"></i> survey</a></li>
                <li class="menu-item"><a href="peddyCash.php"><i class="fas fa-money-bill-wave"></i> Petty Cash</a></li>
                <li class="menu-item"><a href="notification.php"><i class="fas fa-bell"></i> Notification</a></li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1>Care Home Management Responses</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span> 
                   <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>
            
            <div class="content-card">
                <div class="page-header">
                    <h2 class="page-title">Survey Responses</h2>
                    <div class="search-box">
                        <form method="GET" class="d-flex">
                            <?php if (!empty($resp_type)): ?>
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($resp_type); ?>">
                            <?php endif; ?>
                            <?php if (!empty($service_filter)): ?>
                                <input type="hidden" name="service" value="<?php echo htmlspecialchars($service_filter); ?>">
                            <?php endif; ?>
                            <i class="fas fa-search"></i>
                            <input type="text" name="query" class="form-control" placeholder="Search by name" value="<?php echo htmlspecialchars($query); ?>">
                        </form>
                    </div>
                </div>
                
                <div class="filter-tabs">
                    <a href="response.php" class="btn <?php echo empty($resp_type) ? 'btn-primary' : 'btn-outline-primary'; ?>">All Responses</a>
                    <a href="response.php?type=staff" class="btn <?php echo $resp_type == 'staff' ? 'btn-primary' : 'btn-outline-primary'; ?>">Staff Survey</a>
                    <a href="response.php?type=relative" class="btn <?php echo $resp_type == 'relative' ? 'btn-primary' : 'btn-outline-primary'; ?>">Relative Survey</a>
                    <a href="response.php?type=user" class="btn <?php echo $resp_type == 'user' ? 'btn-primary' : 'btn-outline-primary'; ?>">Service User Survey</a>
                    <div class="btn-group" role="group">
                        <button id="viewReportBtn" class="btn btn-info"><i class="fas fa-eye"></i> View Report</button>
                        <a href="?action=export_excel<?php 
                            $params = [];
                            if (!empty($resp_type)) $params[] = 'type=' . urlencode($resp_type);
                            if (!empty($query)) $params[] = 'query=' . urlencode($query);
                            if (!empty($service_filter)) $params[] = 'service=' . urlencode($service_filter);
                            echo !empty($params) ? '&' . implode('&', $params) : '';
                        ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> Export to Excel</a>
                    </div>
                </div>
                
                <!-- Service Filter Dropdown -->
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="service-filter">Filter by Service:</label>
                        <form method="GET" class="gap-2 d-flex">
                            <?php if (!empty($resp_type)): ?>
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($resp_type); ?>">
                            <?php endif; ?>
                            <?php if (!empty($query)): ?>
                                <input type="hidden" name="query" value="<?php echo htmlspecialchars($query); ?>">
                            <?php endif; ?>
                            <select name="service" id="service-filter" class="form-select" onchange="this.form.submit()">
                                <option value="">All Services</option>
                                <?php foreach($service_options as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service); ?>" <?php echo $service_filter == $service ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($service); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            
                            <?php if (!empty($service_filter)): ?>
                                <a href="response.php?<?php 
                                    $params = [];
                                    if (!empty($resp_type)) $params[] = 'type=' . urlencode($resp_type);
                                    if (!empty($query)) $params[] = 'query=' . urlencode($query);
                                    echo implode('&', $params);
                                ?>" class="btn btn-outline-secondary">Clear Filter</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <div class="table-container">
                    <?php if (empty($responses)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Responses Found</h3>
                            <p>There are no survey responses that match your current filters.</p>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Respondent</th>
                                    <?php if ($resp_type == 'staff'): ?>
                                        <th>Job Role</th>
                                    <?php endif; ?>
                                    <th>Contact</th>
                                    <?php if ($resp_type == 'staff'): ?>
                                        <th>Department</th>
                                    <?php endif; ?>
                                    <th>Service</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($responses as $res): ?>
                                <tr>
                                    <td>
                                        <div class="user-info-cell">
                                            <div class="user-avatar">
                                                <?php 
                                                    $initials = '';
                                                    $name_parts = explode(' ', $res['full_name']);
                                                    if (count($name_parts) >= 2) {
                                                        $initials = substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1);
                                                    } else {
                                                        $initials = substr($res['full_name'], 0, 2);
                                                    }
                                                    echo strtoupper($initials);
                                                ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($res['full_name']); ?></span>
                                                <span class="user-email"><?php echo htmlspecialchars($res['email']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <?php if ($resp_type == 'staff'): ?>
                                        <td><?php echo htmlspecialchars($res['job_role'] ?? 'N/A'); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo htmlspecialchars($res['tel']); ?></td>
                                    <?php if ($resp_type == 'staff'): ?>
                                        <td><?php echo htmlspecialchars($res['department'] ?? 'N/A'); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="status-badge <?php 
                                            if ($res['type'] == 'staff') echo 'badge-staff';
                                            elseif ($res['type'] == 'relative') echo 'badge-relative';
                                            else echo 'badge-user';
                                        ?>">
                                            <?php echo htmlspecialchars($res['service']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($res['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="response_detail.php?id=<?php echo $res['id']; ?>" class="btn-icon btn-view" title="View Response">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="response.php?delete_id=<?php echo $res['id']; ?>&type=<?php echo urlencode($resp_type); ?>&service=<?php echo urlencode($service_filter); ?>" class="btn-icon btn-delete" title="Delete Response" onclick="return confirm('Are you sure you want to delete this response?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php 
                                        $params = [];
                                        if (!empty($resp_type)) $params[] = 'type=' . urlencode($resp_type);
                                        if (!empty($query)) $params[] = 'query=' . urlencode($query);
                                        if (!empty($service_filter)) $params[] = 'service=' . urlencode($service_filter);
                                        $params[] = 'page=' . ($page - 1);
                                        echo implode('&', $params);
                                    ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php 
                                        $params = [];
                                        if (!empty($resp_type)) $params[] = 'type=' . urlencode($resp_type);
                                        if (!empty($query)) $params[] = 'query=' . urlencode($query);
                                        if (!empty($service_filter)) $params[] = 'service=' . urlencode($service_filter);
                                        $params[] = 'page=' . $i;
                                        echo implode('&', $params);
                                    ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php 
                                        $params = [];
                                        if (!empty($resp_type)) $params[] = 'type=' . urlencode($resp_type);
                                        if (!empty($query)) $params[] = 'query=' . urlencode($query);
                                        if (!empty($service_filter)) $params[] = 'service=' . urlencode($service_filter);
                                        $params[] = 'page=' . ($page + 1);
                                        echo implode('&', $params);
                                    ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize mobile menu
            setupMobileMenu();
            
            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('.data-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
                    this.style.transition = 'all 0.3s ease';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });
            
            // Add animation to cards on load
            const contentCard = document.querySelector('.content-card');
            if (contentCard) {
                contentCard.style.opacity = '0';
                contentCard.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    contentCard.style.transition = 'all 0.5s ease';
                    contentCard.style.opacity = '1';
                    contentCard.style.transform = 'translateY(0)';
                }, 100);
            }
            
            // View Report Modal
            document.getElementById('viewReportBtn').addEventListener('click', function() {
                showReportModal();
            });
        });
        
        function showReportModal() {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'modal-content';
            
            // Get current filter parameters
            const urlParams = new URLSearchParams(window.location.search);
            const currentType = urlParams.get('type') || 'all';
            const currentService = urlParams.get('service') || 'all';
            const currentQuery = urlParams.get('query') || '';
            
            // Get responses data
            const responses = <?php echo json_encode($responses); ?>;
            const totalResponses = responses.length;
            const staffCount = responses.filter(r => r.type === 'staff').length;
            const relativeCount = responses.filter(r => r.type === 'relative').length;
            const userCount = responses.filter(r => r.type === 'user').length;
            
            modal.innerHTML = `
                <div class="modal-header">
                    <h3><i class="fas fa-file-alt"></i> Survey Responses Report</h3>
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
                            <h2 class="report-title">Survey Responses Report</h2>
                            <div class="report-date">Generated on: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</div>
                        </div>
                        
                        <div class="report-summary">
                            <div class="summary-item">
                                <div class="summary-value">${totalResponses}</div>
                                <div class="summary-label">Total Responses</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${staffCount}</div>
                                <div class="summary-label">Staff Surveys</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${relativeCount}</div>
                                <div class="summary-label">Relative Surveys</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${userCount}</div>
                                <div class="summary-label">User Surveys</div>
                            </div>
                        </div>
                        
                        <div style="margin-top:30px;">
                            <h3 style="color:#2c3e50;margin-bottom:15px;"><i class="fas fa-list"></i> Individual Response Records</h3>
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Type</th>
                                        ${currentType === 'staff' ? '<th>Job Role</th><th>Department</th>' : ''}
                                        <th>Service</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${responses.map(response => `
                                        <tr>
                                            <td>${response.full_name}</td>
                                            <td>${response.email}</td>
                                            <td>${response.tel}</td>
                                            <td>${response.type}</td>
                                            ${currentType === 'staff' ? `<td>${response.job_role || 'N/A'}</td><td>${response.department || 'N/A'}</td>` : ''}
                                            <td>${response.service || 'N/A'}</td>
                                            <td>${new Date(response.created_at).toLocaleDateString()}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div style="margin-top:30px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                            <p style="margin: 0; color: #7f8c8d; font-size: 0.9rem;">
                                <i class="fas fa-info-circle"></i> 
                                This report shows individual survey response records. The same data is available in Excel export.
                            </p>
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
                            <title>Survey Responses Report</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                .report-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #2c3e50; padding-bottom: 15px; }
                                .report-title { color: #2c3e50; margin: 0 0 10px 0; }
                                .report-date { color: #7f8c8d; font-size: 14px; }
                                .report-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                                .report-table th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
                                .report-table td { padding: 8px 10px; border-bottom: 1px solid #eee; }
                                .report-table tr:nth-child(even) { background: #f8f9fa; }
                                .report-summary { 
                                    margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; 
                                    display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; 
                                }
                                .summary-item { text-align: center; padding: 10px; }
                                .summary-value { font-size: 1.3rem; font-weight: bold; color: #2c3e50; }
                                .summary-label { font-size: 0.8rem; color: #7f8c8d; }
                                h3 { color: #2c3e50; margin-bottom: 15px; }
                                .fas { display: none; }
                                @media print {
                                    body { margin: 0; }
                                    .report-table { font-size: 10px; }
                                    .report-summary { grid-template-columns: repeat(2, 1fr); }
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
        
        // Mobile menu setup function moved inside DOMContentLoaded
        function setupMobileMenu() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (mobileMenuToggle && sidebar) {
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
        }

    // Function to generate mobile response cards
    function generateMobileResponseCards() {
        const desktopRows = document.querySelectorAll('.response-row');
        const responseTable = document.querySelector('.response-table');
        
        if (!responseTable) return;
        
        // Create mobile cards container
        let mobileCardsContainer = document.getElementById('mobileResponseCards');
        if (!mobileCardsContainer) {
            mobileCardsContainer = document.createElement('div');
            mobileCardsContainer.id = 'mobileResponseCards';
            mobileCardsContainer.className = 'mobile-cards-container';
            responseTable.parentNode.insertBefore(mobileCardsContainer, responseTable);
        }
        
        // Clear existing mobile cards
        mobileCardsContainer.innerHTML = '';
        
        // Generate mobile cards for each question
        desktopRows.forEach((row, index) => {
            const questionCol = row.querySelector('.question-col');
            const answerCols = row.querySelectorAll('.answer-col');
            
            if (!questionCol) return;
            
            const questionNumber = questionCol.querySelector('.question-number')?.textContent || (index + 1);
            const questionText = questionCol.querySelector('.question-text')?.textContent || '';
            
            const mobileCard = document.createElement('div');
            mobileCard.className = 'mobile-response-card';
            
            let answersHTML = '';
            answerCols.forEach((col, colIndex) => {
                const answerLabel = col.querySelector('.answer-label')?.textContent || '';
                const answerCount = col.querySelector('.answer-count')?.textContent || '0';
                const answerType = col.classList.contains('positive') ? 'positive' : 
                                 col.classList.contains('neutral') ? 'neutral' : 
                                 col.classList.contains('negative') ? 'negative' : '';
                
                if (answerLabel && answerLabel !== '-') {
                    answersHTML += `
                        <div class="mobile-answer-item ${answerType}">
                            <span class="mobile-answer-label">${answerLabel}</span>
                            <span class="mobile-answer-count">${answerCount}</span>
                        </div>
                    `;
                }
            });
            
            mobileCard.innerHTML = `
                <div class="mobile-question-header">
                    <div class="question-number">${questionNumber}</div>
                    <div class="question-text">${questionText}</div>
                </div>
                <div class="mobile-answers-grid">
                    ${answersHTML}
                </div>
            `;
            
            mobileCardsContainer.appendChild(mobileCard);
        });
        
        // Show/hide based on screen size
        toggleMobileView();
        
        // Update on window resize
        window.addEventListener('resize', toggleMobileView);
    }

    // Function to toggle between mobile and desktop view
    function toggleMobileView() {
        const mobileCards = document.getElementById('mobileResponseCards');
        const desktopTable = document.querySelector('.response-table');
        
        if (window.innerWidth <= 768) {
            if (mobileCards) mobileCards.style.display = 'block';
            if (desktopTable) desktopTable.style.display = 'none';
        } else {
            if (mobileCards) mobileCards.style.display = 'none';
            if (desktopTable) desktopTable.style.display = 'block';
        }
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

                
                
</script>
</body>
</html>