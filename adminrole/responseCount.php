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

// Check if it's an API request
$is_api_request = isset($_GET['action']);

// Function to get question texts based on survey type
function getQuestionHeaders($survey_type) {
    $base_headers = ['ID', 'Full Name', 'Email', 'Phone', 'Job Role', 'Department', 'Type', 'Service'];
    
    $staff_questions = [
        'I feel I have the training and resources to do my job well.',
        'I am confident in my ability to provide safe and compassionate care.',
        'The organisation prioritises patient/service user safety.',
        'Our safety protocols and procedures are clear, up-to-date, and regularly reviewed.',
        'Communication between teams and departments is effective.',
        'I feel supported to learn and develop in my role.',
        'I am encouraged to share ideas and contribute to improvements.',
        'The organisation actively seeks and uses feedback to improve care.',
        'I feel valued and respected as a member of staff.',
        'I feel comfortable speaking up if I have concerns about patient safety or quality of care.',
        'My colleagues demonstrate kindness and compassion in their interactions with service users.',
        'The organisation prioritises the emotional well-being of those in our care.',
        'I am involved in decisions that affect my work and the care we provide.',
        'The organisation listens to and acts upon staff feedback.',
        'The organisation actively seeks and values feedback from service users about their care experiences.',
        'Our care plans are adaptable and responsive to the changing needs of service users.',
        'I feel that leaders in the organisation are competent and effective.',
        'The organisation\'s values are clear and guide our work.',
        'I feel that the leaders in our organisation lead by example, demonstrating the values they expect from staff.',
        'I am confident that the organisation is well-managed and sustainable.',
        'How often do you receive feedback on your performance?',
        'How would you rate your overall job satisfaction?',
        'Do you feel that staff from diverse backgrounds are represented and valued in the organisation?',
        'To what extent do you feel that the organisation is committed to continuous improvement?',
        'Q25', 'Q26', 'Q27', 'Q28', 'Q29', 'Q30', 'Q31', 'Q32', 'Q33', 'Q34', 'Q35', // Staff extended questions
        'Q36', 'Q37', 'Q38', 'Q39', 'Q40' // Staff additional questions
    ];
    
    $relative_questions = [
        'I feel my relative is safe and protected in this care setting.',
        'The staff seem knowledgeable and follow safety procedures.',
        'I know how to raise concerns if I have any about my relative\'s safety.',
        'The environment appears clean, well-maintained, and safe for my relative.',
        'My relative\'s care plan seems to address their specific needs.',
        'The care my relative receives is based on the best available information and treatments.',
        'Staff communicate effectively with me about my relative\'s care.',
        'I believe my relative\'s care is regularly reviewed and adjusted as needed.',
        'Staff treat my relative with kindness, compassion, and dignity.',
        'My relative\'s wishes and preferences are respected.',
        'Staff respond promptly and effectively to my relative\'s needs.',
        'The organisation fosters a culture of empathy and understanding towards my relative.',
        'My relative\'s care plan focuses on their individual goals and priorities.',
        'I feel listened to when I raise concerns about my relative\'s care.',
        'I believe the organisation actively seeks feedback to improve care.',
        'Staff are open to my feedback and suggestions.',
        'I believe the organisation is committed to providing good care and support.',
        'I feel comfortable raising concerns or complaints.',
        'I see positive changes and improvements in the care and support my relative receives.',
        'Staff seem happy and well-supported in their roles.',
        'Overall, I am satisfied with the quality of care/services provided to my relative.',
        'I would recommend this organisation to others.',
        'I feel that my relative\'s needs are met by this organisation.',
        'I feel confident in the organisation\'s ability to provide safe and effective care.',
        'Q25', 'Q26', 'Q27', 'Q28', 'Q29', 'Q30', 'Q31', 'Q32', 'Q33', 'Q34', 'Q35', // Relative extended questions
        'Q36', 'Q37', 'Q38', 'Q39', 'Q40' // Relative additional questions
    ];
    
    $client_questions = [
        'I feel safe and protected in this care setting.',
        'Staff seem knowledgeable and follow safety procedures.',
        'I know how to raise concerns if I have any about my safety.',
        'The environment appears clean, well-maintained, and safe.',
        'My care/support plan addresses my specific needs.',
        'The care I receive is based on the best available information and treatments.',
        'Staff communicate effectively with me about my care.',
        'I believe my care is regularly reviewed and adjusted as needed.',
        'Staff treat me with kindness and respect.',
        'I feel listened to, and my opinions are valued.',
        'Staff respond quickly when I need assistance.',
        'Staff make me feel comfortable and cared for.',
        'My care/support plan focuses on what\'s important to me.',
        'I feel involved in decisions about my care and health.',
        'Staff make sure I get the care and support I need, when I need it.',
        'Staff are open to my feedback and suggestions.',
        'I believe the organisation is committed to providing good care and support.',
        'I feel comfortable raising concerns or complaints.',
        'I see positive changes and improvements in the care and support I receive.',
        'Staff seem happy and well-supported in their roles.',
        'Overall, I am satisfied with the quality of care/services provided to me.',
        'I would recommend this organisation to others.',
        'I feel that my needs are met by this organisation.',
        'I feel confident in the organisation\'s ability to provide safe and effective care.',
        'Q25', 'Q26', 'Q27', 'Q28', 'Q29', 'Q30', 'Q31', 'Q32', 'Q33', 'Q34', 'Q35', // Service user extended questions
        'Q36', 'Q37', 'Q38', 'Q39', 'Q40' // Service user additional questions
    ];
    
    if ($survey_type === 'staff') {
        $questions = $staff_questions;
    } elseif ($survey_type === 'relative') {
        $questions = $relative_questions;
    } elseif ($survey_type === 'user') {
        $questions = $client_questions;
    } elseif ($survey_type === 'all') {
        // For 'all' export, use generic Q1-Q40 headers since it contains mixed survey types
        $generic_questions = [];
        for ($i = 1; $i <= 40; $i++) {
            $generic_questions[] = 'Q' . $i;
        }
        $questions = $generic_questions;
    } else {
        // Default to staff questions
        $questions = $staff_questions;
    }
    
    return array_merge($base_headers, $questions, ['Created At', 'Updated At']);
}

// Function to get the relevant columns for export based on survey type
function getExportColumns($survey_type) {
    // Base columns that are always included
    $base_columns = ['id', 'full_name', 'email', 'tel', 'job_role', 'department', 'type', 'Q1'];
    
    // Question columns Q2-Q40 (all survey types use Q1-Q35, and some use Q36-Q40)
    $question_columns = [];
    for ($i = 2; $i <= 35; $i++) {
        $question_columns[] = 'Q' . $i;
    }
    
    // Additional questions q36-q40 (lowercase in database)
    $additional_columns = ['q36', 'q37', 'q38', 'q39', 'q40'];
    
    // Timestamp columns
    $timestamp_columns = ['created_at', 'updated_at'];
    
    // All survey types use the same database columns, just with different question meanings
    return array_merge($base_columns, $question_columns, $additional_columns, $timestamp_columns);
}

// Handle Excel export
if ($is_api_request && $_GET['action'] === 'export_excel') {
    if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        exit();
    }
    
    $export_type = isset($_GET['q_type']) ? $_GET['q_type'] : 'all';
    $mysqli = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($mysqli) {
        // Get the columns to export based on survey type
        $export_columns = getExportColumns($export_type);
        $columns_str = implode(', ', $export_columns);
        
        $query = "SELECT $columns_str FROM responses";
        if ($export_type !== 'all') {
            $query .= " WHERE type = '" . $mysqli->real_escape_string($export_type) . "'";
        }
        $query .= " ORDER BY created_at DESC";
        
        $result = $mysqli->query($query);
        
        if (!$result) {
            http_response_code(500);
            exit('Database query failed');
        }
        
        $filename = 'care_home_survey_' . $export_type . '_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for proper Excel encoding
        fwrite($output, "\xEF\xBB\xBF");
        
        // Get headers with actual question texts based on survey type
        $headers = getQuestionHeaders($export_type);
        fputcsv($output, $headers);
        
        // Export only the relevant data for the survey type
        while ($row = $result->fetch_assoc()) {
            // Create ordered row based on the export columns
            $export_row = [];
            foreach ($export_columns as $column) {
                $export_row[] = isset($row[$column]) ? $row[$column] : '';
            }
            fputcsv($output, $export_row);
        }
        
        fclose($output);
        $mysqli->close();
        exit();
    } else {
        http_response_code(500);
        exit('Database connection failed');
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
} else {
    // Session check for page requests
    if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../index.php");
        exit();
    }
}

// Get the survey type from query parameter or default to 'staff'
$q_type = isset($_GET['q_type']) ? $_GET['q_type'] : 'staff';

// Initialize all response count variables
$sum = 0;
$q3_1 = $q3_2 = $q3_3 = $q3_4 = $q3_5 = 0;
$q4_1 = $q4_2 = $q4_3 = $q4_4 = $q4_5 = 0;
$q5_1 = $q5_2 = $q5_3 = $q5_4 = $q5_5 = 0;
$q6_1 = $q6_2 = $q6_3 = $q6_4 = $q6_5 = 0;
$q7_1 = $q7_2 = $q7_3 = $q7_4 = $q7_5 = 0;
$q8_1 = $q8_2 = $q8_3 = $q8_4 = $q8_5 = 0;
$q9_1 = $q9_2 = $q9_3 = $q9_4 = $q9_5 = 0;
$q10_1 = $q10_2 = $q10_3 = $q10_4 = $q10_5 = 0;
$q11_1 = $q11_2 = $q11_3 = $q11_4 = $q11_5 = 0;
$q12_1 = $q12_2 = $q12_3 = $q12_4 = $q12_5 = 0;
$q13_1 = $q13_2 = $q13_3 = $q13_4 = $q13_5 = 0;
$q14_1 = $q14_2 = $q14_3 = $q14_4 = $q14_5 = 0;
$q15_1 = $q15_2 = $q15_3 = $q15_4 = $q15_5 = 0;
$q16_1 = $q16_2 = $q16_3 = $q16_4 = $q16_5 = 0;
$q17_1 = $q17_2 = $q17_3 = $q17_4 = $q17_5 = 0;
$q18_1 = $q18_2 = $q18_3 = $q18_4 = $q18_5 = 0;
$q19_1 = $q19_2 = $q19_3 = $q19_4 = $q19_5 = 0;
$q20_1 = $q20_2 = $q20_3 = $q20_4 = $q20_5 = 0;
$q21_1 = $q21_2 = $q21_3 = $q21_4 = $q21_5 = 0;
$q22_1 = $q22_2 = $q22_3 = $q22_4 = $q22_5 = 0;
$q28_1 = $q28_2 = $q28_3 = $q28_4 = 0;
$q29_1 = $q29_2 = $q29_3 = $q29_4 = 0;
$q30_1 = $q30_2 = $q30_3 = $q30_4 = 0;
$q31_1 = $q31_2 = $q31_3 = $q31_4 = 0;

// Database connection
$mysqli = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);

if ($mysqli) {
    // Get total responses count
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM responses WHERE type = ?");
    $stmt->bind_param("s", $q_type);
    $stmt->execute();
    $stmt->bind_result($sum);
    $stmt->fetch();
    $stmt->close();
    
    // Function to get response counts for a specific question
    function getResponseCounts($mysqli, $q_type, $question, $answerOptions) {
        $counts = [];
        
        foreach ($answerOptions as $option) {
            $stmt = $mysqli->prepare("SELECT COUNT(*) FROM responses WHERE type = ? AND $question = ?");
            $stmt->bind_param("ss", $q_type, $option);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $counts[] = $count;
            $stmt->close();
        }
        
        return $counts;
    }
    
    // Define answer options for different question types
    $standardOptions = ["Strongly Agree", "Agree", "Unsure", "Disagree", "Strongly Disagree"];
    $staffOptions = ["Regularly", "Occasionally", "Rarely", "Never"];
    $staffOptions2 = ["Excellent", "Good", "Not Very", "Not at all"];
    $staffOptions3 = ["Definitely", "Mostly", "Not really", "Not at all"];
    $staffOptions4 = ["Very Much", "Somewhat", "Not Very", "Not at all"];
    $relativeOptions = ["Agree", "Unsure", "Disagree", "N/A"];
    
    // Get counts for all questions
    $q3_counts = getResponseCounts($mysqli, $q_type, "q3", $standardOptions);
    list($q3_1, $q3_2, $q3_3, $q3_4, $q3_5) = $q3_counts;
    
    $q4_counts = getResponseCounts($mysqli, $q_type, "q4", $standardOptions);
    list($q4_1, $q4_2, $q4_3, $q4_4, $q4_5) = $q4_counts;
    
    $q5_counts = getResponseCounts($mysqli, $q_type, "q5", $standardOptions);
    list($q5_1, $q5_2, $q5_3, $q5_4, $q5_5) = $q5_counts;
    
    $q6_counts = getResponseCounts($mysqli, $q_type, "q6", $standardOptions);
    list($q6_1, $q6_2, $q6_3, $q6_4, $q6_5) = $q6_counts;
    
    $q7_counts = getResponseCounts($mysqli, $q_type, "q7", $standardOptions);
    list($q7_1, $q7_2, $q7_3, $q7_4, $q7_5) = $q7_counts;
    
    $q8_counts = getResponseCounts($mysqli, $q_type, "q8", $standardOptions);
    list($q8_1, $q8_2, $q8_3, $q8_4, $q8_5) = $q8_counts;
    
    $q9_counts = getResponseCounts($mysqli, $q_type, "q9", $standardOptions);
    list($q9_1, $q9_2, $q9_3, $q9_4, $q9_5) = $q9_counts;
    
    $q10_counts = getResponseCounts($mysqli, $q_type, "q10", $standardOptions);
    list($q10_1, $q10_2, $q10_3, $q10_4, $q10_5) = $q10_counts;
    
    $q11_counts = getResponseCounts($mysqli, $q_type, "q11", $standardOptions);
    list($q11_1, $q11_2, $q11_3, $q11_4, $q11_5) = $q11_counts;
    
    $q12_counts = getResponseCounts($mysqli, $q_type, "q12", $standardOptions);
    list($q12_1, $q12_2, $q12_3, $q12_4, $q12_5) = $q12_counts;
    
    $q13_counts = getResponseCounts($mysqli, $q_type, "q13", $standardOptions);
    list($q13_1, $q13_2, $q13_3, $q13_4, $q13_5) = $q13_counts;
    
    $q14_counts = getResponseCounts($mysqli, $q_type, "q14", $standardOptions);
    list($q14_1, $q14_2, $q14_3, $q14_4, $q14_5) = $q14_counts;
    
    $q15_counts = getResponseCounts($mysqli, $q_type, "q15", $standardOptions);
    list($q15_1, $q15_2, $q15_3, $q15_4, $q15_5) = $q15_counts;
    
    $q16_counts = getResponseCounts($mysqli, $q_type, "q16", $standardOptions);
    list($q16_1, $q16_2, $q16_3, $q16_4, $q16_5) = $q16_counts;
    
    $q17_counts = getResponseCounts($mysqli, $q_type, "q17", $standardOptions);
    list($q17_1, $q17_2, $q17_3, $q17_4, $q17_5) = $q17_counts;
    
    $q18_counts = getResponseCounts($mysqli, $q_type, "q18", $standardOptions);
    list($q18_1, $q18_2, $q18_3, $q18_4, $q18_5) = $q18_counts;
    
    $q19_counts = getResponseCounts($mysqli, $q_type, "q19", $standardOptions);
    list($q19_1, $q19_2, $q19_3, $q19_4, $q19_5) = $q19_counts;
    
    $q20_counts = getResponseCounts($mysqli, $q_type, "q20", $standardOptions);
    list($q20_1, $q20_2, $q20_3, $q20_4, $q20_5) = $q20_counts;
    
    $q21_counts = getResponseCounts($mysqli, $q_type, "q21", $standardOptions);
    list($q21_1, $q21_2, $q21_3, $q21_4, $q21_5) = $q21_counts;
    
    $q22_counts = getResponseCounts($mysqli, $q_type, "q22", $standardOptions);
    list($q22_1, $q22_2, $q22_3, $q22_4, $q22_5) = $q22_counts;
    
    // Get counts for question 28-31 based on survey type
    if ($q_type == "staff") {
        $q28_counts = getResponseCounts($mysqli, $q_type, "q28", $staffOptions);
        list($q28_1, $q28_2, $q28_3, $q28_4) = $q28_counts;
        
        $q29_counts = getResponseCounts($mysqli, $q_type, "q29", $staffOptions2);
        list($q29_1, $q29_2, $q29_3, $q29_4) = $q29_counts;
        
        $q30_counts = getResponseCounts($mysqli, $q_type, "q30", $staffOptions3);
        list($q30_1, $q30_2, $q30_3, $q30_4) = $q30_counts;
        
        $q31_counts = getResponseCounts($mysqli, $q_type, "q31", $staffOptions4);
        list($q31_1, $q31_2, $q31_3, $q31_4) = $q31_counts;
    } else {
        $q28_counts = getResponseCounts($mysqli, $q_type, "q28", $relativeOptions);
        list($q28_1, $q28_2, $q28_3, $q28_4) = $q28_counts;
        
        $q29_counts = getResponseCounts($mysqli, $q_type, "q29", $relativeOptions);
        list($q29_1, $q29_2, $q29_3, $q29_4) = $q29_counts;
        
        $q30_counts = getResponseCounts($mysqli, $q_type, "q30", $relativeOptions);
        list($q30_1, $q30_2, $q30_3, $q30_4) = $q30_counts;
        
        $q31_counts = getResponseCounts($mysqli, $q_type, "q31", $relativeOptions);
        list($q31_1, $q31_2, $q31_3, $q31_4) = $q31_counts;
    }
    
    $mysqli->close();
}
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

    /* Survey Type Selector - UPDATED */
    .survey-type-selector {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .survey-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .survey-btn {
        padding: 10px 20px;
        border: 2px solid #3498db;
        border-radius: 6px;
        background: white;
        color: #3498db;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .survey-btn:hover, .survey-btn.active {
        background: #3498db;
        color: white;
        transform: translateY(-2px);
    }
    
    .response-count {
        background: #2ecc71;
        color: white;
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Response Table Styles - UPDATED */
    .response-table-container {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        overflow: hidden;
        margin-bottom: 20px;
    }
    
    .response-table-header {
        background: linear-gradient(135deg, #2c3e50, #3498db);
        color: white;
        padding: 15px 20px;
        font-weight: 600;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .response-row {
        display: flex;
        border-bottom: 1px solid #e0e0e0;
        transition: all 0.3s ease;
    }
    
    .response-row:hover {
        background: #f8f9fa;
    }
    
    .response-row:last-child {
        border-bottom: none;
    }
    
    .question-col {
        flex: 3;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        border-right: 1px solid #e0e0e0;
    }
    
    .answer-col {
        flex: 1;
        padding: 15px;
        text-align: center;
        display: flex;
        flex-direction: column;
        justify-content: center;
        border-right: 1px solid #e0e0e0;
    }
    
    .answer-col:last-child {
        border-right: none;
    }
    
    .question-number {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #3498db;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        flex-shrink: 0;
    }
    
    .question-text {
        font-weight: 500;
        color: #2c3e50;
        line-height: 1.5;
    }
    
    .answer-label {
        font-size: 0.85rem;
        color: #6c757d;
        margin-bottom: 5px;
        font-weight: 500;
    }
    
    .answer-count {
        font-size: 1.2rem;
        font-weight: 700;
        color: #2c3e50;
    }
    
    /* Color coding for different answer types */
    .answer-col.positive .answer-count {
        color: #28a745;
    }
    
    .answer-col.neutral .answer-count {
        color: #ffc107;
    }
    
    .answer-col.negative .answer-count {
        color: #dc3545;
    }

    /* NEW: Mobile Card Styles for Responses */
    .mobile-response-card {
        display: none;
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        margin-bottom: 15px;
        padding: 15px;
        border-left: 4px solid #3498db;
    }
    
    .mobile-question-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }
    
    .mobile-answers-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .mobile-answer-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px;
        background: #f8f9fa;
        border-radius: 5px;
    }
    
    .mobile-answer-label {
        font-size: 0.8rem;
        color: #6c757d;
        font-weight: 500;
    }
    
    .mobile-answer-count {
        font-weight: 700;
        font-size: 1rem;
    }
    
    .mobile-answer-item.positive .mobile-answer-count {
        color: #28a745;
    }
    
    .mobile-answer-item.neutral .mobile-answer-count {
        color: #ffc107;
    }
    
    .mobile-answer-item.negative .mobile-answer-count {
        color: #dc3545;
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

    /* Responsive Design */
    @media (max-width: 1200px) {
        .response-row {
            flex-direction: column;
        }
        
        .question-col {
            border-right: none;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .answer-col {
            border-right: none;
            border-bottom: 1px solid #e0e0e0;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
        }
        
        .answer-col:last-child {
            border-bottom: none;
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
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .user-info {
            align-self: flex-end;
        }
        
        .survey-type-selector {
            flex-direction: column;
            align-items: flex-start;
        }

        .notification-container {
            max-width: 300px;
            right: 10px;
            top: 70px;
        }
    }
    
    @media (max-width: 768px) {
        .main-content {
            padding: 10px;
        }
        
        .survey-buttons {
            flex-direction: column;
            width: 100%;
        }
        
        .survey-btn {
            width: 100%;
            justify-content: center;
        }
        
        .question-col {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        /* Show mobile cards and hide desktop table on small screens */
        .mobile-response-card {
            display: block;
        }
        
        .desktop-response-table {
            display: none;
        }

        .mobile-answers-grid {
            grid-template-columns: 1fr;
        }

        .response-table-header {
            padding: 12px 15px;
            font-size: 1rem;
        }
    }
    
    @media (max-width: 576px) {
        .main-content {
            padding: 10px;
        }
        
        .card-body {
            padding: 10px;
        }
        
        .survey-type-selector {
            padding: 15px;
        }
        
        .response-count {
            font-size: 0.9rem;
            padding: 6px 12px;
        }
        
        .notification-container {
            max-width: calc(100% - 20px);
            right: 10px;
            left: 10px;
        }

        .mobile-question-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .question-text {
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
              <li class="menu-item"><a href="adash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="menu-item"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
                <li class="menu-item"><a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> Accounts</a></li>
                <li class="menu-item"><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li class="menu-item"><a href="response.php"><i class="fas fa-comment-dots"></i> Response</a></li>
                <li class="menu-item active"><a href="responseCount.php"><i class="fas fa-chart-pie"></i> survey</a></li>
                <li class="menu-item"><a href="peddyCash.php"><i class="fas fa-money-bill-wave"></i> Petty Cash</a></li>
                <li class="menu-item"><a href="notification.php"><i class="fas fa-bell"></i> Notification</a></li>
            </ul>
        </nav>
        
    

        <!-- Main Content -->
        <main class="main-content">
            <header class="main-header">
                <h1><i class="fas fa-comment-dots"></i> Survey Responses Dashboard</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span> 
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>
            
            <!-- Survey Type Selector -->
            <div class="survey-type-selector">
                <div class="survey-buttons">
                    <a href="?q_type=staff" class="survey-btn <?php echo $q_type == 'staff' ? 'active' : ''; ?>">
                        <i class="fas fa-user-tie"></i> Staff Survey
                    </a>
                    <a href="?q_type=relative" class="survey-btn <?php echo $q_type == 'relative' ? 'active' : ''; ?>">
                        <i class="fas fa-user-friends"></i> Relative Survey
                    </a>
                    <a href="?q_type=user" class="survey-btn <?php echo $q_type == 'user' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> Service User Survey
                    </a>
                </div>
                <div class="response-count">
                    <i class="fas fa-chart-bar"></i>
                    <?php echo $sum; ?> Total Responses
                </div>
                <div>
                    <a href="?action=export_excel&q_type=<?php echo $q_type; ?>" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </a>
                </div>
            </div>
            
            <!-- Responses Table -->
            <div class="response-table-container">
                <div class="response-table-header">
                    <i class="fas fa-list-alt"></i>
                    Survey Questions & Responses
                </div>
                
                <?php if ($q_type == 'staff'): ?>
                    <!-- Staff Survey Questions -->
                    <div class="response-table">
                        <!-- Questions 1-24 for Staff Survey -->
                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">1</div>
                                <div class="question-text">I feel I have the training and resources to do my job well.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q3_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q3_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q3_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q3_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q3_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">2</div>
                                <div class="question-text">I am confident in my ability to provide safe and compassionate care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q4_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q4_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q4_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q4_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q4_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">3</div>
                                <div class="question-text">The organisation prioritises patient/service user safety.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q5_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q5_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q5_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q5_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q5_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">4</div>
                                <div class="question-text">Our safety protocols and procedures are clear, up-to-date, and regularly reviewed.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q6_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q6_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q6_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q6_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q6_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">5</div>
                                <div class="question-text">Communication between teams and departments is effective.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q7_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q7_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q7_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q7_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q7_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">6</div>
                                <div class="question-text">I feel supported to learn and develop in my role.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q8_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q8_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q8_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q8_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q8_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">7</div>
                                <div class="question-text">I am encouraged to share ideas and contribute to improvements.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q9_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q9_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q9_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q9_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q9_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">8</div>
                                <div class="question-text">The organisation actively seeks and uses feedback to improve care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q10_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q10_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q10_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q10_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q10_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">9</div>
                                <div class="question-text">I feel valued and respected as a member of staff.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q11_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q11_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q11_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q11_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q11_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">10</div>
                                <div class="question-text">I feel comfortable speaking up if I have concerns about patient safety or quality of care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q12_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q12_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q12_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q12_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q12_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">11</div>
                                <div class="question-text">My colleagues demonstrate kindness and compassion in their interactions with service users.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q13_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q13_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q13_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q13_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q13_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">12</div>
                                <div class="question-text">The organisation prioritises the emotional well-being of those in our care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q14_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q14_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q14_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q14_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q14_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">13</div>
                                <div class="question-text">I am involved in decisions that affect my work and the care we provide.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q15_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q15_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q15_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q15_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q15_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">14</div>
                                <div class="question-text">The organisation listens to and acts upon staff feedback.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q16_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q16_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q16_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q16_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q16_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">15</div>
                                <div class="question-text">The organisation actively seeks and values feedback from service users about their care experiences.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q17_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q17_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q17_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q17_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q17_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">16</div>
                                <div class="question-text">Our care plans are adaptable and responsive to the changing needs of service users.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q18_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q18_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q18_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q18_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q18_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">17</div>
                                <div class="question-text">I feel that leaders in the organisation are competent and effective.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q19_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q19_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q19_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q19_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q19_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">18</div>
                                <div class="question-text">The organisation's values are clear and guide our work.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q20_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q20_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q20_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q20_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q20_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">19</div>
                                <div class="question-text">I feel that the leaders in our organisation lead by example, demonstrating the values they expect from staff.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q21_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q21_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q21_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q21_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q21_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">20</div>
                                <div class="question-text">I am confident that the organisation is well-managed and sustainable.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q22_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q22_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q22_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q22_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q22_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">21</div>
                                <div class="question-text">How often do you receive feedback on your performance?</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Regularly</div>
                                <div class="answer-count"><?php echo $q28_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Occasionally</div>
                                <div class="answer-count"><?php echo $q28_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Rarely</div>
                                <div class="answer-count"><?php echo $q28_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Never</div>
                                <div class="answer-count"><?php echo $q28_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">22</div>
                                <div class="question-text">How would you rate your overall job satisfaction?</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Excellent</div>
                                <div class="answer-count"><?php echo $q29_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Good</div>
                                <div class="answer-count"><?php echo $q29_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Not Very</div>
                                <div class="answer-count"><?php echo $q29_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Not at all</div>
                                <div class="answer-count"><?php echo $q29_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">23</div>
                                <div class="question-text">Do you feel that staff from diverse backgrounds are represented and valued in the organisation?</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Definitely</div>
                                <div class="answer-count"><?php echo $q30_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Mostly</div>
                                <div class="answer-count"><?php echo $q30_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Not really</div>
                                <div class="answer-count"><?php echo $q30_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Not at all</div>
                                <div class="answer-count"><?php echo $q30_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">24</div>
                                <div class="question-text">To what extent do you feel that the organisation is committed to continuous improvement?</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Very Much</div>
                                <div class="answer-count"><?php echo $q31_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Somewhat</div>
                                <div class="answer-count"><?php echo $q31_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Not Very</div>
                                <div class="answer-count"><?php echo $q31_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Not at all</div>
                                <div class="answer-count"><?php echo $q31_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($q_type == 'relative'): ?>
                    <!-- Relative Survey Questions -->
                    <div class="response-table">
                        <!-- Questions 1-24 for Relative Survey -->
                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">1</div>
                                <div class="question-text">I feel my relative is safe and protected in this care setting.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q3_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q3_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q3_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q3_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q3_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">2</div>
                                <div class="question-text">The staff seem knowledgeable and follow safety procedures.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q4_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q4_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q4_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q4_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q4_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">3</div>
                                <div class="question-text">I know how to raise concerns if I have any about my relative's safety.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q5_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q5_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q5_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q5_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q5_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">4</div>
                                <div class="question-text">The environment appears clean, well-maintained, and safe for my relative.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q6_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q6_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q6_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q6_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q6_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">5</div>
                                <div class="question-text">My relative's care plan seems to address their specific needs.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q7_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q7_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q7_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q7_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q7_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">6</div>
                                <div class="question-text">The care my relative receives is based on the best available information and treatments.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q8_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q8_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q8_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q8_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q8_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">7</div>
                                <div class="question-text">Staff communicate effectively with me about my relative's care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q9_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q9_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q9_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q9_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q9_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">8</div>
                                <div class="question-text">I believe my relative's care is regularly reviewed and adjusted as needed.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q10_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q10_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q10_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q10_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q10_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">9</div>
                                <div class="question-text">Staff treat my relative with kindness, compassion, and dignity.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q11_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q11_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q11_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q11_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q11_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">10</div>
                                <div class="question-text">My relative's wishes and preferences are respected.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q12_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q12_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q12_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q12_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q12_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">11</div>
                                <div class="question-text">Staff respond promptly and effectively to my relative's needs.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q13_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q13_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q13_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q13_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q13_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">12</div>
                                <div class="question-text">The organisation fosters a culture of empathy and understanding towards my relative.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q14_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q14_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q14_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q14_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q14_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">13</div>
                                <div class="question-text">My relative's care plan focuses on their individual goals and priorities.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q15_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q15_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q15_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q15_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q15_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">14</div>
                                <div class="question-text">I feel listened to when I raise concerns about my relative's care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q16_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q16_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q16_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q16_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q16_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">15</div>
                                <div class="question-text">My relative's needs are met in a timely and effective manner.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q17_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q17_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q17_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q17_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q17_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">16</div>
                                <div class="question-text">The organisation is open to feedback and makes changes based on my input.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q18_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q18_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q18_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q18_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q18_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">17</div>
                                <div class="question-text">The organisation seems well-run and focused on providing quality care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q19_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q19_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q19_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q19_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q19_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">18</div>
                                <div class="question-text">I feel comfortable raising concerns with the organisation's leadership.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q20_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q20_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q20_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q20_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q20_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">19</div>
                                <div class="question-text">The organisation values feedback from relatives and makes improvements based on it.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q21_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q21_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q21_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q21_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q21_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">20</div>
                                <div class="question-text">Leaders in the organisation demonstrate strong communication and decision-making skills.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q22_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q22_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q22_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q22_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q22_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">21</div>
                                <div class="question-text">Overall, I am satisfied with the quality of care/services provided to my relative.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q28_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q28_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q28_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q28_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">22</div>
                                <div class="question-text">I would recommend this organisation to others.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q29_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q29_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q29_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q29_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">23</div>
                                <div class="question-text">I feel that my relative's needs are met by this organisation.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q30_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q30_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q30_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q30_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">24</div>
                                <div class="question-text">I feel confident in the organisation's ability to provide safe and effective care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q31_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q31_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q31_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q31_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Service User Survey Questions -->
                    <div class="response-table">
                        <!-- Questions 1-24 for Service User Survey -->
                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">1</div>
                                <div class="question-text">I feel safe and well-cared for here.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q3_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q3_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q3_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q3_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q3_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">2</div>
                                <div class="question-text">The people looking after me know what to do to keep me safe.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q4_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q4_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q4_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q4_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q4_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">3</div>
                                <div class="question-text">I know who to talk to if I have any worries or concerns.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q5_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q5_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q5_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q5_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q5_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">4</div>
                                <div class="question-text">My environment feels clean, tidy, and safe.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q6_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q6_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q6_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q6_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q6_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">5</div>
                                <div class="question-text">My care and support plan helps me meet my goals.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q7_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q7_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q7_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q7_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q7_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">6</div>
                                <div class="question-text">The staff use the best information and treatments to help me.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q8_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q8_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q8_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q8_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q8_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">7</div>
                                <div class="question-text">The people caring for me work well together as a team.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q9_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q9_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q9_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q9_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q9_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">8</div>
                                <div class="question-text">My health and well-being have improved since receiving care here.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q10_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q10_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q10_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q10_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q10_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">9</div>
                                <div class="question-text">Staff treat me with kindness and respect.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q11_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q11_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q11_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q11_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q11_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">10</div>
                                <div class="question-text">I feel listened to, and my opinions are valued.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q12_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q12_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q12_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q12_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q12_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">11</div>
                                <div class="question-text">Staff respond quickly when I need assistance.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q13_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q13_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q13_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q13_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q13_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">12</div>
                                <div class="question-text">Staff make me feel comfortable and cared for.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q14_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q14_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q14_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q14_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q14_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">13</div>
                                <div class="question-text">My care/support plan focuses on what's important to me.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q15_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q15_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q15_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q15_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q15_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">14</div>
                                <div class="question-text">I feel involved in decisions about my care and health.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q16_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q16_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q16_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q16_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q16_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">15</div>
                                <div class="question-text">Staff make sure I get the care and support I need, when I need it.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q17_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q17_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q17_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q17_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q17_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">16</div>
                                <div class="question-text">Staff are open to my feedback and suggestions.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q18_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q18_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q18_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q18_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q18_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">17</div>
                                <div class="question-text">I believe the organisation is committed to providing good care and support.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q19_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q19_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q19_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q19_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q19_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">18</div>
                                <div class="question-text">I feel comfortable raising concerns or complaints.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q20_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q20_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q20_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q20_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q20_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">19</div>
                                <div class="question-text">I see positive changes and improvements in the care and support I receive.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q21_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q21_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q21_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q21_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q21_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">20</div>
                                <div class="question-text">Staff seem happy and well-supported in their roles.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Strongly Agree</div>
                                <div class="answer-count"><?php echo $q22_1; ?></div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q22_2; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q22_3; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q22_4; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Strongly Disagree</div>
                                <div class="answer-count"><?php echo $q22_5; ?></div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">21</div>
                                <div class="question-text">Overall, I am satisfied with the quality of care/services provided to me.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q28_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q28_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q28_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q28_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">22</div>
                                <div class="question-text">I would recommend this organisation to others.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q29_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q29_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q29_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q29_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">23</div>
                                <div class="question-text">I feel that my needs are met by this organisation.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q30_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q30_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q30_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q30_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>

                        <div class="response-row">
                            <div class="question-col">
                                <div class="question-number">24</div>
                                <div class="question-text">I feel confident in the organisation's ability to provide safe and effective care.</div>
                            </div>
                            <div class="answer-col positive">
                                <div class="answer-label">Agree</div>
                                <div class="answer-count"><?php echo $q31_1; ?></div>
                            </div>
                            <div class="answer-col neutral">
                                <div class="answer-label">Unsure</div>
                                <div class="answer-count"><?php echo $q31_2; ?></div>
                            </div>
                            <div class="answer-col negative">
                                <div class="answer-label">Disagree</div>
                                <div class="answer-count"><?php echo $q31_3; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">N/A</div>
                                <div class="answer-count"><?php echo $q31_4; ?></div>
                            </div>
                            <div class="answer-col">
                                <div class="answer-label">-</div>
                                <div class="answer-count">-</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add some interactive features
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to response rows
            const responseRows = document.querySelectorAll('.response-row');
            responseRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 6px 12px rgba(0,0,0,0.1)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });
            
            // Add loading animation
            const surveyButtons = document.querySelectorAll('.survey-btn');
            surveyButtons.forEach(button => {
                button.addEventListener('click', function() {
                    if (!this.classList.contains('active')) {
                        // Show loading state
                        const originalText = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                        
                        setTimeout(() => {
                            this.innerHTML = originalText;
                        }, 1000);
                    }
                });
            });
        });
        
        
        
        
    // Mobile menu functionality
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

        // Generate mobile response cards
        generateMobileResponseCards();

        // Add hover effects to response rows (for desktop)
        const responseRows = document.querySelectorAll('.response-row');
        responseRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 6px 12px rgba(0,0,0,0.1)';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'none';
            });
        });
        
        // Add loading animation to survey buttons
        const surveyButtons = document.querySelectorAll('.survey-btn');
        surveyButtons.forEach(button => {
            button.addEventListener('click', function() {
                if (!this.classList.contains('active')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    
                    setTimeout(() => {
                        this.innerHTML = originalText;
                    }, 1000);
                }
            });
        });
    });

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