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

// Get response ID from GET
$response_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch response
$response = null;
$mysqli = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli) {
    $stmt = $mysqli->prepare("SELECT id, type, full_name, email, tel, department, job_role, Q1, Q3, Q4, Q5, Q6, Q7, Q8, Q9, Q10, Q11, Q12, Q13, Q14, Q15, Q16, Q17, Q18, Q19, Q20, Q21, Q22, Q23, Q24, Q25, Q26, Q27, Q28, Q29, Q30, Q31, Q32, Q33, Q34, Q35, created_at FROM responses WHERE id = ?");
    $stmt->bind_param("i", $response_id);
    $stmt->execute();
    $stmt->store_result();

    $stmt->bind_result(
        $id, $type, $full_name, $email, $tel, $department, $job_role, $Q1,
        $Q3, $Q4, $Q5, $Q6, $Q7, $Q8, $Q9, $Q10, $Q11, $Q12, $Q13, $Q14, $Q15,
        $Q16, $Q17, $Q18, $Q19, $Q20, $Q21, $Q22, $Q23, $Q24, $Q25, $Q26, $Q27,
        $Q28, $Q29, $Q30, $Q31, $Q32, $Q33, $Q34, $Q35, $created_at
    );

    if ($stmt->fetch()) {
        $response = [
            'id' => $id,
            'type' => $type,
            'full_name' => $full_name,
            'email' => $email,
            'tel' => $tel,
            'department' => $department,
            'job_role' => $job_role,
            'Q1' => $Q1,
            'Q3' => $Q3,
            'Q4' => $Q4,
            'Q5' => $Q5,
            'Q6' => $Q6,
            'Q7' => $Q7,
            'Q8' => $Q8,
            'Q9' => $Q9,
            'Q10' => $Q10,
            'Q11' => $Q11,
            'Q12' => $Q12,
            'Q13' => $Q13,
            'Q14' => $Q14,
            'Q15' => $Q15,
            'Q16' => $Q16,
            'Q17' => $Q17,
            'Q18' => $Q18,
            'Q19' => $Q19,
            'Q20' => $Q20,
            'Q21' => $Q21,
            'Q22' => $Q22,
            'Q23' => $Q23,
            'Q24' => $Q24,
            'Q25' => $Q25,
            'Q26' => $Q26,
            'Q27' => $Q27,
            'Q28' => $Q28,
            'Q29' => $Q29,
            'Q30' => $Q30,
            'Q31' => $Q31,
            'Q32' => $Q32,
            'Q33' => $Q33,
            'Q34' => $Q34,
            'Q35' => $Q35,
            'created_at' => $created_at
        ];
    }

    $stmt->close();
    $mysqli->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Response Details - Care Home Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #212529;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --gray-900: #212529;
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --border-radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            --shadow-md: 0 3px 6px rgba(0,0,0,0.15), 0 2px 4px rgba(0,0,0,0.12);
            --shadow-lg: 0 10px 20px rgba(0,0,0,0.1), 0 6px 6px rgba(0,0,0,0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            color: var(--gray-800);
            line-height: 1.6;
            min-height: 100vh;
            padding: 0;
        }
        
        .container-fluid {
            min-height: 100vh;
            padding: 0;
        }
        
        /* Main Content Styles */
        .main-content {
            padding: 30px;
            width: 100%;
        }
        
        /* UPDATED HEADER STYLES */
        .main-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .main-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            z-index: 2;
        }

        .header-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .header-text {
            flex: 1;
        }

        .main-header h1 {
            color: white;
            font-weight: 700;
            margin: 0;
            font-size: 2.2rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            letter-spacing: -0.5px;
        }

        .header-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.1rem;
            margin-top: 5px;
            font-weight: 500;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            transition: var(--transition);
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 2;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            color: white;
        }
        
        /* Rest of the existing CSS remains exactly the same */
        .content-card {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 30px;
            margin-bottom: 30px;
            border: none;
            transition: var(--transition);
        }
        
        .content-card:hover {
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            color: var(--primary);
            font-weight: 700;
            margin: 0;
            font-size: 1.8rem;
            position: relative;
            padding-bottom: 10px;
        }
        
        .page-title:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--primary);
            border-radius: 2px;
        }
        
        .response-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding: 25px;
            background: linear-gradient(135deg, #cfdce8ff 0%, #e9ecef 100%);
            border-radius: var(--border-radius);
            color: var(--gray-800);
            box-shadow: var(--shadow-md);
            border: 1px solid #dee2e6;
        }
        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 25px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            font-size: 1.8rem;
            border: 3px solid var(--primary);
        }
        
        .user-details {
            flex: 1;
        }
        
        .user-name {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .user-email {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .user-meta {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.9));
            padding: 8px 12px;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(67, 97, 238, 0.1);
            transition: all 0.3s ease;
            font-weight: 500;
            backdrop-filter: blur(5px);
            position: relative;
            overflow: hidden;
        }
        
        .meta-item:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .meta-item:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-color: rgba(67, 97, 238, 0.2);
            background: linear-gradient(145deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, 0.95));
        }
        
        .meta-item:hover:before {
            left: 100%;
        }
        
        .meta-item i {
            color: #667eea;
            font-size: 0.9rem;
            width: 14px;
            text-align: center;
        }
        
        .meta-item span {
            color: var(--gray-800);
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
            position: relative;
            overflow: hidden;
        }
        
        .status-badge:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .status-badge:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
        }
        
        .status-badge:hover:before {
            left: 100%;
        }
        
        .badge-staff {
            background: rgba(76, 201, 240, 0.2);
            color: #4cc9f0;
            border: 1px solid rgba(76, 201, 240, 0.3);
        }
        
        .badge-relative {
            background: rgba(72, 149, 239, 0.2);
            color: #4895ef;
            border: 1px solid rgba(72, 149, 239, 0.3);
        }
        
        .badge-user {
            background: rgba(67, 97, 238, 0.2);
            color: #4361ee;
            border: 1px solid rgba(67, 97, 238, 0.3);
        }
        
        /* Section Styles */
        .section {
            margin-bottom: 35px;
        }
        
        .section-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-200);
            position: relative;
        }
        
        .section-title:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 40px;
            height: 2px;
            background: var(--primary);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            background: var(--gray-100);
            padding: 20px;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }
        
        .info-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .info-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 1.1rem;
            color: var(--gray-800);
            font-weight: 600;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }
        
        .data-table th {
            background: linear-gradient(to right, var(--primary), var(--primary-light));
            color: white;
            padding: 18px 15px;
            text-align: left;
            font-weight: 700;
            border: none;
            position: sticky;
            top: 0;
        }
        
        .data-table td {
            padding: 18px 15px;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: top;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .data-table tr:nth-child(even) {
            background-color: var(--gray-100);
        }
        
        .data-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .question-cell {
            width: 60%;
            font-weight: 600;
            color: var(--gray-800);
        }
        
        .answer-cell {
            width: 40%;
            color: var(--gray-700);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }
        
        .btn-back-old {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
        }
        
        .btn-back-old:hover {
            background: linear-gradient(135deg, #218838, #1ea387);
            color: white;
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        /* Alert Styles */
        .alert {
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-danger {
            background: rgba(230, 57, 70, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .main-content {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            
            .main-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
                text-align: center;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .header-icon {
                align-self: center;
            }
            
            .response-header {
                flex-direction: column;
                align-items: flex-start;
                text-align: center;
            }
            
            .user-avatar {
                margin-right: 0;
                margin-bottom: 20px;
                align-self: center;
            }
            
            .user-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                font-size: 0.9rem;
            }
            
            .data-table th, 
            .data-table td {
                padding: 12px 10px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .content-card {
                padding: 20px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-icon {
                justify-content: center;
            }
        }
        
        /* Animation for page load */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .content-card {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Print Styles */
        @media print {
            .main-header, .action-buttons {
                display: none;
            }
            
            .main-content {
                padding: 0;
            }
            
            .content-card {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Main Content -->
        <main class="main-content">
            <!-- UPDATED HEADER SECTION -->
            <div class="main-header">
                <div class="header-content">
                    <div class="header-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="header-text">
                        <h1>Response Details</h1>
                        <div class="header-subtitle">View complete survey response information</div>
                    </div>
                </div>
                <a href="response.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Responses</span>
                </a>
            </div>
            
            <div class="content-card">
                <?php if ($response): ?>
                <div class="response-header">
                    <div class="user-avatar">
                        <?php 
                            $initials = '';
                            $name_parts = explode(' ', $response['full_name']);
                            if (count($name_parts) >= 2) {
                                $initials = substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1);
                            } else {
                                $initials = substr($response['full_name'], 0, 2);
                            }
                            echo strtoupper($initials);
                        ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?= htmlspecialchars($response['full_name']) ?></div>
                        <div class="user-email"><?= htmlspecialchars($response['email']) ?></div>
                        <div class="user-meta">
                            <div class="meta-item">
                                <i class="fas fa-phone"></i>
                                <span><?= htmlspecialchars($response['tel']) ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <span><?= htmlspecialchars($response['created_at']) ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="status-badge <?php 
                                    if ($response['type'] == 'staff') echo 'badge-staff';
                                    elseif ($response['type'] == 'relative') echo 'badge-relative';
                                    else echo 'badge-user';
                                ?>">
                                    <?= htmlspecialchars($response['type']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Rest of the content remains exactly the same -->
                <div class="section">
                    <h3 class="section-title">Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?= htmlspecialchars($response['full_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Email Address</span>
                            <span class="info-value"><?= htmlspecialchars($response['email']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Mobile Number</span>
                            <span class="info-value"><?= htmlspecialchars($response['tel']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Service</span>
                            <span class="info-value"><?= htmlspecialchars($response['Q1']) ?></span>
                        </div>
                        <?php if ($response['type'] === 'staff'): ?>
                        <div class="info-item">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?= htmlspecialchars($response['department']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Job Role</span>
                            <span class="info-value"><?= htmlspecialchars($response['job_role']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Date of Submission</span>
                            <span class="info-value"><?= htmlspecialchars($response['created_at']) ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h3 class="section-title">Survey Responses</h3>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="question-cell">Question</th>
                                    <th class="answer-cell">Answer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="question-cell">
                                        <?php
                                        if ($response['type'] == 'staff') echo "I feel I have the training and resources to do my job well.";
                                        elseif ($response['type'] == 'relative') echo "I feel my relative is safe and protected in this care setting.";
                                        elseif ($response['type'] == 'user') echo "I feel safe and well-cared for here.";
                                        ?>
                                    </td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q3']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I am confident in my ability to provide safe and compassionate care.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q4']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">The organisation prioritises patient/service user safety.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q5']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Our safety protocols and procedures are clear, up-to-date, and regularly reviewed.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q6']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Communication between teams and departments is effective.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q7']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I feel supported to learn and develop in my role.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q8']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I am encouraged to share ideas and contribute to improvements.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q9']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">The organisation actively seeks and uses feedback to improve care.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q10']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I feel valued and respected as a member of staff.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q11']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I feel comfortable speaking up if I have concerns about patient safety or quality of care.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q12']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">My colleagues demonstrate kindness and compassion in their interactions with service users.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q13']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">The organisation prioritises the emotional well-being of those in our care.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q14']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I am involved in decisions that affect my work and the care we provide.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q15']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">The organisation listens to and acts upon staff feedback.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q16']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">The organisation actively seeks and values feedback from service users about their care experiences.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q17']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Our care plans are adaptable and responsive to the changing needs of service users.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q18']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I feel that leaders in the organisation are competent and effective.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q19']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">The organisation's values are clear and guide our work.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q20']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I feel that the leaders in our organisation lead by example, demonstrating the values they expect from staff.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q21']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">I am confident that the organisation is well-managed and sustainable.</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q22']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Safe: How do our safety practices contribute to a positive work environment? What could be improved?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q23']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Effective: How does our focus on evidence-based care impact your job satisfaction and the quality of care we provide?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q24']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Caring: What specific actions or behaviours within our organisation contribute to a caring and compassionate workplace?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q25']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Responsive: How does our responsiveness to service user needs and feedback affect your work experience?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q26']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Well-led: How does the leadership in our organisation contribute to a positive work environment and high-quality care?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q27']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">How often do you receive feedback on your performance?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q28']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">How would you rate your overall job satisfaction?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q29']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Do you feel that staff from diverse backgrounds are represented and valued in the organisation?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q30']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">To what extent do you feel that the organisation is committed to continuous improvement?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q31']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">What are the top three reasons you would (or would not) recommend this organisation as a place to work?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q32']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">What are the top three reasons you would (or would not) recommend this service to someone seeking care?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q33']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">What aspects of our service do you think could be improved to better meet the needs of service users?</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q34']) ?></td>
                                </tr>
                                <tr>
                                    <td class="question-cell">Comments</td>
                                    <td class="answer-cell"><?= htmlspecialchars($response['Q35']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <?php else: ?>
                    <div class="alert alert-danger">Response not found.</div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add interactive effects
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>
</html>