<?php
session_start();

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Create database connection
function get_db_connection($dbHost, $dbUser, $dbPass, $dbName) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        error_log("Database connection failed: " . $mysqli->connect_error);
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

// Initialize connection
$conn = get_db_connection($dbHost, $dbUser, $dbPass, $dbName);
$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'], $_POST['role'])) {
    // Validate input
    $username = trim($_POST['username']);
    $input_password = trim($_POST['password']); // renamed to avoid confusion with DB password variable
    $role = $_POST['role'];
    
    // Basic validation
    if (empty($username) || empty($input_password) || empty($role)) {
        $error = "All fields are required!";
    } elseif (!$conn) {
        $error = "Database connection failed. Please try again later.";
    } else {
        // Prepare query based on role
        if ($role === 'admin') {
            $sql = "SELECT id, username, password FROM admins WHERE username = ?";
        } else {
            // staff / carehome
            $sql = "SELECT id, name, user_name, user_password FROM homes WHERE user_name = ?";
        }
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();

            // Bind columns to variables depending on role
            if ($role === 'admin') {
                $stmt->bind_result($id, $db_username, $db_password);
            } else {
                $stmt->bind_result($id, $db_name, $db_username, $db_password);
            }

            // Fetch the first row
            if ($stmt->fetch()) {
                if ($role === 'admin') {
                    $user = [
                        'id' => $id,
                        'username' => $db_username,
                        'password' => $db_password
                    ];
                } else {
                    $user = [
                        'id' => $id,
                        'name' => $db_name,
                        'user_name' => $db_username,
                        'user_password' => $db_password
                    ];
                }
            } else {
                $user = null;
            }

            $stmt->close();

            if ($user) {
                $isValid = false;
                
                // Check plain text password based on role
                if ($role === 'admin') {
                    // For admin - plain text comparison
                    if ($input_password === $user['password']) {
                        $isValid = true;
                    }
                } else {
                    // For staff - plain text comparison
                    if ($input_password === $user['user_password']) {
                        $isValid = true;
                    }
                }
                
                if ($isValid) {
                    // Set session variables
                    $_SESSION['logged_in'] = true;
                    $_SESSION['role'] = $role;
                    $_SESSION['username'] = $role === 'admin' ? $user['username'] : $user['user_name'];
                    $_SESSION['login_time'] = time();
                    
                    // Set role-specific session variables
                    if ($role === 'staff') {
                        $_SESSION['carehome_id'] = $user['id'];
                        $_SESSION['carehome_name'] = $user['name'];
                        header("Location: carehome.role/sdash.php");
                        exit();
                    } else {
                        header("Location: adminrole/adash.php");
                        exit();
                    }
                } else {
                    $error = "Invalid password!";
                }
            } else {
                $error = "User not found!";
            }
        } else {
            $error = "Database error. Please try again.";
        }
    }
    
    // Close connection
    if ($conn) {
        $conn->close();
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Care Home Management - Login</title>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#3498db">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="CareHome">
    <meta name="description" content="Care Home Management System - Login and Dashboard">
    <meta name="mobile-web-app-capable" content="yes">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest.json">
    
    <!-- Apple Touch Icon -->
    <link rel="apple-touch-icon" href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTkyIiBoZWlnaHQ9IjE5MiIgdmlld0JveD0iMCAwIDE5MiAxOTIiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIxOTIiIGhlaWdodD0iMTkyIiByeD0iMjQiIGZpbGw9IiMzNDk4ZGIiLz4KPHN2ZyB4PSI0OCIgeT0iNDgiIHdpZHRoPSI5NiIgaGVpZ2h0PSI5NiIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJ3aGl0ZSI+CjxwYXRoIGQ9Ik0xMiAyQzEzLjEgMiAxNCAyLjkgMTQgNFY2SDE4QzE5LjEgNiAyMCA2LjkgMjAgOFYxOUMyMCAyMC4xIDE5LjEgMjEgMTggMjFINkM0LjkgMjEgNCAyMC4xIDQgMTlWOEM0IDYuOSA0LjkgNiA2IDZIMTBWNEMxMCAyLjkgMTAuOSAyIDEyIDJaTTEyIDRWNkgxMlY0Wk02IDhWMTlIMThWOEg2WiIvPgo8L3N2Zz4KPC9zdmc+">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            margin: 20px;
        }

        .login-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .login-form {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        .form-group input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .role-selection {
            margin-bottom: 25px;
        }

        .role-selection label {
            display: block;
            margin-bottom: 10px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .role-options {
            display: flex;
            gap: 10px;
        }

        .role-option {
            flex: 1;
            position: relative;
        }

        .role-option input[type="radio"] {
            display: none;
        }

        .role-option label {
            display: block;
            padding: 15px;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
            margin: 0;
        }

        .role-option input[type="radio"]:checked + label {
            border-color: #3498db;
            background: #3498db;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .role-option label i {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #2980b9;
        }

        /* Install App Button Styles */
        .install-app-container {
            margin-top: 20px;
            text-align: center;
        }

        .install-app-btn {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 0 auto;
            max-width: 200px;
            width: 100%;
        }

        .install-app-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.4);
        }

        .install-app-btn:active {
            transform: translateY(0);
        }

        .install-app-btn.hidden {
            display: none !important;
        }

        /* Install Instructions Modal */
        .install-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .install-modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .install-modal h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.4rem;
        }

        .install-modal ol {
            text-align: left;
            color: #2c3e50;
            margin: 20px 0;
            padding-left: 20px;
        }

        .install-modal li {
            margin: 10px 0;
            line-height: 1.5;
        }

        .install-modal-close {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            margin-top: 20px;
            transition: background 0.3s ease;
        }

        .install-modal-close:hover {
            background: #2980b9;
        }

        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            border-left: 4px solid #3498db;
        }

        .demo-credentials h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }

        .demo-credentials .credential-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }

        .demo-credentials .credential-item strong {
            color: #2c3e50;
        }

        .demo-credentials .credential-item span {
            color: #7f8c8d;
            font-family: monospace;
        }

        .error-message {
            background: #e74c3c;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.9rem;
        }

        .success-message {
            background: #27ae60;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.9rem;
            display: none;
        }

        .pwa-info {
            background: #e3f2fd;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            text-align: center;
            font-size: 0.8rem;
            color: #1565c0;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .login-form {
                padding: 30px 20px;
            }
            
            .role-options {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-home"></i> Care Home Management</h1>
            <p>Sign in to access your dashboard</p>
        </div>

        <div class="login-form">
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" required placeholder="Enter your username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" required placeholder="Enter your password">
                    </div>
                </div>

                <div class="role-selection">
                    <label>Select Role</label>
                    <div class="role-options">
                        <div class="role-option">
                            <input type="radio" id="admin" name="role" value="admin" required <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'checked' : ''; ?>>
                            <label for="admin">
                                <i class="fas fa-user-shield"></i>
                                Master Admin
                            </label>
                        </div>
                        <div class="role-option">
                            <input type="radio" id="staff" name="role" value="staff" required <?php echo (isset($_POST['role']) && $_POST['role'] === 'staff') ? 'checked' : ''; ?>>
                            <label for="staff">
                                <i class="fas fa-user"></i>
                                Admin
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
            </form>

            <div class="forgot-password">
                <a href="#" onclick="alert('Contact administrator for password reset')">Forgot Password?</a>
            </div>

<div>
  <style>
    /* Import Google Font for better typography */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    
    /* Install App Button */
    .install-app-container {
      text-align: center;
      margin: 30px 0;
    }
    
    .install-app-btn {
      background: linear-gradient(135deg, #10b981, #059669);
      color: white;
      border: none;
      padding: 14px 32px;
      font-size: 1.15rem;
      border-radius: 10px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 12px;
      transition: all 0.3s ease;
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
      font-weight: 600;
      letter-spacing: 0.025em;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    
    .install-app-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
    }
    
    .install-app-btn:active {
      transform: translateY(-1px);
    }
    
    /* Modal styles */
    .install-modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.7);
      z-index: 1000;
      animation: fadeIn 0.3s ease;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    
    .install-modal-content {
      position: relative;
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      margin: 5% auto;
      padding: 0;
      width: 90%;
      max-width: 680px;
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
      animation: slideUp 0.4s ease;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.8);
    }
    
    /* Step content styles */
    .step-content {
      display: none;
      padding: 40px;
      min-height: 450px;
    }
    
    .step-content.active {
      display: block;
    }
    
    .step-header {
      display: flex;
      align-items: center;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid #e2e8f0;
    }
    
    .step-number {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      background: linear-gradient(135deg, #3498db, #2980b9);
      color: white;
      border-radius: 50%;
      font-weight: 700;
      font-size: 16px;
      margin-right: 16px;
      box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
    }
    
    .step-content h3 {
      color: #1e293b;
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
      letter-spacing: -0.025em;
    }
    
    .step-content p {
      margin-bottom: 20px;
      line-height: 1.7;
      color: #475569;
      font-size: 1.05rem;
      font-weight: 400;
    }
    
    .step-content strong {
      color: #1e293b;
      font-weight: 600;
      background-color: rgba(52, 152, 219, 0.1);
      padding: 2px 6px;
      border-radius: 4px;
    }
    
    .step-content img {
      width: 100%;
      max-width: 480px;
      display: block;
      margin: 24px auto;
      border-radius: 12px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
      border: 1px solid #e2e8f0;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      cursor: zoom-in;
    }
    
    .step-content img:hover {
      transform: scale(1.02);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
    }
    
    .image-caption {
      text-align: center;
      color: #64748b;
      font-size: 0.9rem;
      font-style: italic;
      margin-top: -16px;
      margin-bottom: 24px;
    }
    
    /* Lightbox styles */
    .lightbox {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.9);
      z-index: 1100;
      justify-content: center;
      align-items: center;
      animation: fadeIn 0.3s ease;
    }
    
    .lightbox.active {
      display: flex;
    }
    
    .lightbox-content {
      position: relative;
      max-width: 90%;
      max-height: 90%;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    
    .lightbox-img {
      max-width: 100%;
      max-height: 90vh;
      border-radius: 8px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
      animation: zoomIn 0.3s ease;
    }
    
    .lightbox-close {
      position: absolute;
      top: -50px;
      right: 0;
      background: rgba(255, 255, 255, 0.2);
      color: white;
      border: none;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      font-size: 1.5rem;
      cursor: pointer;
      display: flex;
      justify-content: center;
      align-items: center;
      transition: background 0.3s ease;
    }
    
    .lightbox-close:hover {
      background: rgba(255, 255, 255, 0.3);
    }
    
    .lightbox-nav {
      position: absolute;
      top: 50%;
      width: 100%;
      display: flex;
      justify-content: space-between;
      transform: translateY(-50%);
      padding: 0 20px;
    }
    
    .lightbox-prev, .lightbox-next {
      background: rgba(255, 255, 255, 0.2);
      color: white;
      border: none;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      font-size: 1.5rem;
      cursor: pointer;
      display: flex;
      justify-content: center;
      align-items: center;
      transition: background 0.3s ease;
    }
    
    .lightbox-prev:hover, .lightbox-next:hover {
      background: rgba(255, 255, 255, 0.3);
    }
    
    /* Pagination styles */
    .install-modal-pagination {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 24px 40px;
      background-color: #f8fafc;
      border-top: 1px solid #e2e8f0;
    }
    
    .pagination-btn {
      padding: 12px 24px;
      background: linear-gradient(135deg, #3498db, #2980b9);
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      font-size: 1rem;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .pagination-btn:hover {
      background: linear-gradient(135deg, #2980b9, #2471a3);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(52, 152, 219, 0.4);
    }
    
    .pagination-btn:active {
      transform: translateY(0);
    }
    
    .pagination-btn:disabled {
      background: #cbd5e1;
      color: #94a3b8;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }
    
    .pagination-btn.got-it {
      background: linear-gradient(135deg, #10b981, #059669);
    }
    
    .pagination-btn.got-it:hover {
      background: linear-gradient(135deg, #059669, #047857);
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
    }
    
    .step-indicator {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 12px;
      margin: 0 20px;
    }
    
    .step-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background-color: #cbd5e1;
      transition: all 0.3s ease;
    }
    
    .step-dot.active {
      background: linear-gradient(135deg, #3498db, #2980b9);
      transform: scale(1.2);
      box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
    }
    
    /* Animations */
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    @keyframes slideUp {
      from { 
        opacity: 0;
        transform: translateY(60px);
      }
      to { 
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    @keyframes fadeInContent {
      from { 
        opacity: 0;
        transform: translateY(20px);
      }
      to { 
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    @keyframes zoomIn {
      from { 
        opacity: 0;
        transform: scale(0.8);
      }
      to { 
        opacity: 1;
        transform: scale(1);
      }
    }
    
    .step-content.active {
      animation: fadeInContent 0.4s ease;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      .install-modal-content {
        width: 95%;
        margin: 5% auto;
        max-height: 90vh;
        overflow-y: auto;
        padding: 20px;
      }
      
      .step-content {
        padding: 20px 15px;
        min-height: auto;
        max-height: none;
      }
      
      .install-modal-pagination {
        padding: 15px;
        flex-direction: column;
        gap: 16px;
        position: relative;
        background: white;
        border-top: 1px solid #eee;
        margin-top: 20px;
      }
      
      .step-indicator {
        order: -1;
        margin-bottom: 8px;
      }
      
      .step-content h3 {
        font-size: 1.3rem;
      }
      
      .step-content p {
        font-size: 1rem;
      }
      
      .lightbox-close {
        top: -40px;
      }
    }

    /* Tablet specific adjustments */
    @media (min-width: 769px) and (max-width: 1024px) {
      .install-modal-content {
        width: 85%;
        margin: 5% auto;
        max-height: 90vh;
        overflow-y: auto;
        padding: 25px;
      }
      
      .step-content {
        padding: 25px 20px;
        min-height: auto;
      }
      
      .install-modal-pagination {
        padding: 20px;
        position: relative;
        background: white;
        border-top: 1px solid #eee;
        margin-top: 20px;
      }
    }
      
      .lightbox-nav {
        padding: 0 10px;
      }
      
      .lightbox-prev, .lightbox-next {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
      }
    
    
    @media (max-width: 480px) {
      .step-content {
        padding: 24px 20px;
      }
      
      .step-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
      }
      
      .step-number {
        margin-right: 0;
      }
      
      .pagination-btn {
        padding: 10px 20px;
        font-size: 0.95rem;
      }
    }
  </style>

  <!-- Install App Button -->
  <div class="install-app-container">
    <button id="installAppBtn" class="install-app-btn">
      <i class="fas fa-download"></i>
      Install App
    </button>
  </div>

  <!-- Install Instructions Modal -->
  <div id="installModal" class="install-modal">
    <div class="install-modal-content">
      <!-- Lightbox for image zoom -->
      <div class="lightbox" id="lightbox">
        <div class="lightbox-content">
          <img class="lightbox-img" id="lightbox-img" src="" alt="Zoomed image">
          <button class="lightbox-close" id="lightbox-close">
            <i class="fas fa-times"></i>
          </button>
          <div class="lightbox-nav">
            <button class="lightbox-prev" id="lightbox-prev">
              <i class="fas fa-chevron-left"></i>
            </button>
            <button class="lightbox-next" id="lightbox-next">
              <i class="fas fa-chevron-right"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Desktop Steps -->
      <div class="desktop-steps">
        <div class="step-content active" id="step1">
          <div class="step-header">
            <div class="step-number">1</div>
            <h3>How to Install - Step 1</h3>
          </div>
          <p>Click the browser <strong>⋮ (three dots)</strong> icon in the top-right corner.</p>
          <p>See the image below to identify the three dots menu:</p>
          <img src="img/step1.png" alt="Step 1 - Browser three dots menu" class="zoomable-image" data-step="1">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
        
        <div class="step-content" id="step2">
          <div class="step-header">
            <div class="step-number">2</div>
            <h3>How to Install - Step 2</h3>
          </div>
          <p>Navigate to the <strong>Cast, Save and Share</strong> option in the menu.</p>
          <p>Refer to the image below to locate this option:</p>
          <img src="img/step2.png" alt="Step 2 - Cast, Save and Share option" class="zoomable-image" data-step="2">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
        
        <div class="step-content" id="step3">
          <div class="step-header">
            <div class="step-number">3</div>
            <h3>How to Install - Step 3</h3>
          </div>
          <p>Select the <strong>Install page as an app</strong> option from the menu.</p>
          <p>Use the image below as a reference:</p>
          <img src="img/step3.png" alt="Step 3 - Install page as an app option" class="zoomable-image" data-step="3">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
        
        <div class="step-content" id="step4">
          <div class="step-header">
            <div class="step-number">4</div>
            <h3>How to Install - Step 4</h3>
          </div>
          <p>Give a <strong>descriptive name to your app</strong> when prompted.</p>
          <p>See the example below for guidance:</p>
          <img src="img/step4.png" alt="Step 4 - Name your app" class="zoomable-image" data-step="4">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
      </div>

      <!-- Mobile Steps -->
      <div class="mobile-steps">
        <div class="step-content active" id="mobile-step1">
          <div class="step-header">
            <div class="step-number">1</div>
            <h3>How to Install - Step 1</h3>
          </div>
          <p>Click the browser <strong>⋮ (three dots)</strong> icon in the top-right corner.</p>
          <p>See the image below to identify the three dots menu:</p>
          <img src="img/stepMB1.png" alt="Step 1 - Browser three dots menu" class="zoomable-image" data-step="1">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
        
        <div class="step-content" id="mobile-step2">
          <div class="step-header">
            <div class="step-number">2</div>
            <h3>How to Install - Step 2</h3>
          </div>
          <p>Navigate to the <strong>Add to Home Screen</strong> option in the menu.</p>
          <p>Refer to the image below to locate this option:</p>
          <img src="img/stepMB2.png" alt="Step 2 - Add to Home Screen option" class="zoomable-image" data-step="2">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
        
        <div class="step-content" id="mobile-step3">
          <div class="step-header">
            <div class="step-number">3</div>
            <h3>How to Install - Step 3</h3>
          </div>
          <p>Give a <strong>descriptive name to your app</strong> when prompted.</p>
          <p>See the example below for guidance:</p>
          <img src="img/stepMB3.png" alt="Step 3 - Name your app" class="zoomable-image" data-step="3">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
        
        <div class="step-content" id="mobile-step4">
          <div class="step-header">
            <div class="step-number">4</div>
            <h3>How to Install - Step 4</h3>
          </div>
          <p>Hold above the icon, then it will go to the mobile screen, then drop where you want to put in the screen.</p>
          <p>See the example below for guidance:</p>
          <img src="img/stepMB4.png" alt="Step 4 - Drag and drop your app" class="zoomable-image" data-step="4">
          <div class="image-caption">Click the image to view it larger</div>
        </div>
      </div>
      
      <div class="install-modal-pagination">
        <button class="pagination-btn" id="prevBtn" disabled>
          <i class="fas fa-arrow-left"></i> Previous
        </button>
        
        <div class="step-indicator">
          <span class="step-dot active"></span>
          <span class="step-dot"></span>
          <span class="step-dot"></span>
          <span class="step-dot"></span>
        </div>
        
        <button class="pagination-btn" id="nextBtn">
          Next <i class="fas fa-arrow-right"></i>
        </button>
        <button class="pagination-btn got-it" id="gotItBtn" style="display: none;">
          <i class="fas fa-check"></i> Got it!
        </button>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Elements
      const installAppBtn = document.getElementById('installAppBtn');
      const installModal = document.getElementById('installModal');
      const prevBtn = document.getElementById('prevBtn');
      const nextBtn = document.getElementById('nextBtn');
      const gotItBtn = document.getElementById('gotItBtn');
      const stepDots = document.querySelectorAll('.step-dot');
      const desktopSteps = document.querySelectorAll('.desktop-steps .step-content');
      const mobileSteps = document.querySelectorAll('.mobile-steps .step-content');
      
      // Lightbox elements
      const lightbox = document.getElementById('lightbox');
      const lightboxImg = document.getElementById('lightbox-img');
      const lightboxClose = document.getElementById('lightbox-close');
      const lightboxPrev = document.getElementById('lightbox-prev');
      const lightboxNext = document.getElementById('lightbox-next');
      const zoomableImages = document.querySelectorAll('.zoomable-image');
      
      let currentStep = 1;
      const totalSteps = 4;
      let currentLightboxStep = 1;
      let isMobile = false;
      
      // Check if device is mobile
      function checkDeviceType() {
        isMobile = window.innerWidth <= 768;
        
        // Show appropriate steps based on device
        if (isMobile) {
          document.querySelector('.desktop-steps').style.display = 'none';
          document.querySelector('.mobile-steps').style.display = 'block';
        } else {
          document.querySelector('.desktop-steps').style.display = 'block';
          document.querySelector('.mobile-steps').style.display = 'none';
        }
        
        // Reset to first step when device changes
        currentStep = 1;
        updateStepDisplay();
      }
      
      // Open modal when install button is clicked
      installAppBtn.addEventListener('click', function() {
        checkDeviceType();
        installModal.style.display = 'block';
        currentStep = 1;
        updateStepDisplay();
      });
      
      // Close modal when clicking outside content
      installModal.addEventListener('click', function(e) {
        if (e.target === installModal) {
          closeInstallModal();
        }
      });
      
      // Navigation functions
      prevBtn.addEventListener('click', function() {
        if (currentStep > 1) {
          currentStep--;
          updateStepDisplay();
        }
      });
      
      nextBtn.addEventListener('click', function() {
        if (currentStep < totalSteps) {
          currentStep++;
          updateStepDisplay();
        }
      });
      
      gotItBtn.addEventListener('click', closeInstallModal);
      
      // Update step display
      function updateStepDisplay() {
        // Update step contents based on device type
        const currentSteps = isMobile ? mobileSteps : desktopSteps;
        
        currentSteps.forEach((content, index) => {
          if (index + 1 === currentStep) {
            content.classList.add('active');
          } else {
            content.classList.remove('active');
          }
        });
        
        // Update step dots
        stepDots.forEach((dot, index) => {
          if (index + 1 === currentStep) {
            dot.classList.add('active');
          } else {
            dot.classList.remove('active');
          }
        });
        
        // Update button states
        prevBtn.disabled = currentStep === 1;
        
        if (currentStep === totalSteps) {
          nextBtn.style.display = 'none';
          gotItBtn.style.display = 'flex';
        } else {
          nextBtn.style.display = 'flex';
          gotItBtn.style.display = 'none';
        }
      }
      
      // Close modal function
      function closeInstallModal() {
        installModal.style.display = 'none';
      }
      
      // Lightbox functionality
      zoomableImages.forEach(img => {
        img.addEventListener('click', function() {
          currentLightboxStep = parseInt(this.getAttribute('data-step'));
          lightboxImg.src = this.src;
          lightbox.classList.add('active');
          document.body.style.overflow = 'hidden';
        });
      });
      
      lightboxClose.addEventListener('click', closeLightbox);
      
      lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) {
          closeLightbox();
        }
      });
      
      lightboxPrev.addEventListener('click', function() {
        if (currentLightboxStep > 1) {
          currentLightboxStep--;
          updateLightboxImage();
        }
      });
      
      lightboxNext.addEventListener('click', function() {
        if (currentLightboxStep < totalSteps) {
          currentLightboxStep++;
          updateLightboxImage();
        }
      });
      
      // Keyboard navigation for lightbox
      document.addEventListener('keydown', function(e) {
        if (lightbox.classList.contains('active')) {
          if (e.key === 'Escape') {
            closeLightbox();
          } else if (e.key === 'ArrowLeft') {
            lightboxPrev.click();
          } else if (e.key === 'ArrowRight') {
            lightboxNext.click();
          }
        }
      });
      
      function updateLightboxImage() {
        const newImage = document.querySelector(`.zoomable-image[data-step="${currentLightboxStep}"]`);
        if (newImage) {
          lightboxImg.src = newImage.src;
        }
      }
      
      function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.style.overflow = 'auto';
      }
      
      // Check device type on resize
      window.addEventListener('resize', checkDeviceType);
      
      // Make function globally available
      window.closeInstallModal = closeInstallModal;
    });
  </script>
</div>

    <script>
        // PWA Installation
        let deferredPrompt;
        const installAppBtn = document.getElementById('installAppBtn');
        const installModal = document.getElementById('installModal');

        // Function to show install modal
        function showInstallModal() {
            installModal.style.display = 'block';
        }

        // Function to close install modal
        function closeInstallModal() {
            installModal.style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === installModal) {
                closeInstallModal();
            }
        });

        // Register Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('sw.js')
                    .then(function(registration) {
                        console.log('Service Worker registered successfully with scope:', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('Service Worker registration failed:', error);
                    });
            });
        }

        // Listen for beforeinstallprompt event
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('beforeinstallprompt event fired');
            // Prevent Chrome 67 and earlier from automatically showing the prompt
            e.preventDefault();
            // Stash the event so it can be triggered later
            deferredPrompt = e;
            // Show the install button
            installAppBtn.style.display = 'flex';
            
            // Update button text based on device
            if (isIOS()) {
                installAppBtn.innerHTML = '<i class="fas fa-plus-square"></i> Add to Home Screen';
            } else if (isAndroid()) {
                installAppBtn.innerHTML = '<i class="fas fa-download"></i> Install App';
            } else {
                installAppBtn.innerHTML = '<i class="fas fa-download"></i> Install App';
            }
        });

        // Install button click handler
        installAppBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                // Show the install prompt
                deferredPrompt.prompt();
                
                // Wait for the user to respond to the prompt
                const { outcome } = await deferredPrompt.userChoice;
                
                if (outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                    installAppBtn.innerHTML = '<i class="fas fa-check"></i> Installed!';
                    installAppBtn.style.background = 'linear-gradient(135deg, #27ae60, #2ecc71)';
                    setTimeout(() => {
                        installAppBtn.style.display = 'none';
                    }, 3000);
                } else {
                    console.log('User dismissed the install prompt');
                    // Show instructions for manual installation
                    showInstallModal();
                }
                
                // Clear the saved prompt since it can't be used again
                deferredPrompt = null;
            } else {
                // If deferredPrompt is not available, show instructions
                showInstallModal();
            }
        });

        // Listen for app installed event
        window.addEventListener('appinstalled', (evt) => {
            console.log('App was successfully installed');
            installAppBtn.innerHTML = '<i class="fas fa-check"></i> Installed!';
            installAppBtn.style.background = 'linear-gradient(135deg, #27ae60, #2ecc71)';
            setTimeout(() => {
                installAppBtn.style.display = 'none';
            }, 3000);
        });

        // Device detection functions
        function isIOS() {
            return [
                'iPad Simulator',
                'iPhone Simulator',
                'iPod Simulator',
                'iPad',
                'iPhone',
                'iPod'
            ].includes(navigator.platform) || 
            (navigator.userAgent.includes("Mac") && "ontouchend" in document);
        }

        function isAndroid() {
            return /Android/i.test(navigator.userAgent);
        }

        // Check if app is already running in standalone mode
        function isInStandaloneMode() {
            return (window.matchMedia('(display-mode: standalone)').matches) ||
                   (window.navigator.standalone) ||
                   (document.referrer.includes('android-app://'));
        }

        // Hide install button if app is already installed
        if (isInStandaloneMode()) {
            installAppBtn.style.display = 'none';
            console.log('App is running in standalone mode');
        }

        // Debug info
        console.log('PWA Debug Info:', {
            serviceWorker: 'serviceWorker' in navigator,
            standalone: isInStandaloneMode(),
            userAgent: navigator.userAgent,
            platform: navigator.platform,
            isIOS: isIOS(),
            isAndroid: isAndroid()
        });

        // Simple client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();
            const role = document.querySelector('input[name="role"]:checked');
            
            if (!username || !password || !role) {
                e.preventDefault();
                alert('Please fill in all fields');
            }
        });

        // Auto-hide error message after 5 seconds
        <?php if (!empty($error)): ?>
            setTimeout(() => {
                const errorMsg = document.querySelector('.error-message');
                if (errorMsg) {
                    errorMsg.style.display = 'none';
                }
            }, 5000);
        <?php endif; ?>

        // Show install instructions if button is clicked and no prompt available
        installAppBtn.addEventListener('click', function() {
            if (!deferredPrompt) {
                showInstallModal();
            }
        });
    </script>
</body>
</html>