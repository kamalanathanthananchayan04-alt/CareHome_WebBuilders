<?php
// Automated Email Scheduler for Care Home Notifications
// This script should be run every Monday at 9:00 AM via Windows Task Scheduler

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt'; 
$dbName = 'carehomesurvey_carehome1';

// Database connection function
function getDBConnection() {
    global $dbHost, $dbUser, $dbPass, $dbName;
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        error_log("Database connection failed: " . $mysqli->connect_error);
        return false;
    }
    return $mysqli;
}

// Get setting value from database
function getSetting($key, $default = 0) {
    $mysqli = getDBConnection();
    if (!$mysqli) return $default;
    
    $stmt = $mysqli->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $stmt->bind_result($value);
    $result = $stmt->fetch() ? $value : $default;
    $stmt->close();
    $mysqli->close();
    return $result;
}

// Get staff email by home name (with fallback and table creation)
function getStaffEmailByHomeName($home_name) {
    $mysqli = getDBConnection();
    if (!$mysqli) {
        error_log("Failed to get database connection for staff email lookup");
        return '';
    }
    
    // Check if home_staff table exists, create if not
    $table_check = $mysqli->query("SHOW TABLES LIKE 'home_staff'");
    if (!$table_check || $table_check->num_rows == 0) {
        error_log("home_staff table does not exist, creating it...");
        $create_sql = "CREATE TABLE IF NOT EXISTS `home_staff` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `home_name` varchar(255) NOT NULL,
            `staff_email` varchar(255) NOT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_home_name` (`home_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if (!$mysqli->query($create_sql)) {
            error_log("Failed to create home_staff table: " . $mysqli->error);
            $mysqli->close();
            return '';
        }
        
        error_log("Created home_staff table");
    }
    
    $stmt = $mysqli->prepare("SELECT staff_email FROM home_staff WHERE home_name = ? LIMIT 1");
    $stmt->bind_param("s", $home_name);
    $stmt->execute();
    $stmt->bind_result($staff_email);
    $result = $stmt->fetch() ? $staff_email : '';
    $stmt->close();
    $mysqli->close();
    
    if (empty($result)) {
        error_log("No staff email found for home: $home_name");
    } else {
        error_log("Found staff email for $home_name: $result");
    }
    
    return $result;
}

// Get residents with low net amount by care home
function getResidentsWithLowNetAmount($home_id = null) {
    $mysqli = getDBConnection();
    if (!$mysqli) return [];
    
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
    $stmt->bind_result($id, $home_id_result, $first_name, $last_name, $date_of_birth, $gender, $nhs_number, $nok_name, $phone, $nok_number, $address, $medical_conditions, $medications, $admission_date, $room_number, $status, $home_name);
    
    $residents = [];
    while ($stmt->fetch()) {
        $residents[] = [
            'id' => $id,
            'home_id' => $home_id_result,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'date_of_birth' => $date_of_birth,
            'gender' => $gender,
            'nhs_number' => $nhs_number,
            'nok_name' => $nok_name,
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

// Get resident financial data (updated to match notification.php structure)
function getResidentFinancialData($resident_id, $home_id) {
    $mysqli = getDBConnection();
    if (!$mysqli) return ['net_amount' => 0, 'formatted_amount' => '0.00'];
    
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
    
    $mysqli->close();
    
    return [
        'net_amount' => $net_amount,
        'formatted_amount' => number_format($net_amount, 2)
    ];
}

// Get care homes with low petty cash
function getCareHomesWithLowPettyCash() {
    $mysqli = getDBConnection();
    if (!$mysqli) return [];
    
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

// Send automated email notification (improved)
function sendAutomatedEmail($to, $subject, $message) {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: $to");
        return false;
    }
    
    // Check if mail function is available
    if (!function_exists('mail')) {
        error_log("PHP mail() function is not available");
        return false;
    }
    
    // Prepare email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Care Home System <noreply@carehome.com>" . "\r\n";
    $headers .= "Reply-To: noreply@carehome.com" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Format the message
    $formatted_message = "
    <html>
    <head>
        <title>" . htmlspecialchars($subject) . "</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #2c3e50; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
            .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
            .alert { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; }
            .footer { color: #7f8c8d; font-size: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Weekly Care Home Notification</h2>
            </div>
            <div class='content'>
                " . nl2br(htmlspecialchars($message)) . "
            </div>
            <div class='footer'>
                <p>This email was sent automatically every Monday at 9:00 AM from the Care Home Management System.<br>
                Generated on: " . date('Y-m-d H:i:s') . "<br>
                Please do not reply to this email address.</p>
            </div>
        </div>
    </body>
    </html>";
    
    // Log email attempt
    error_log("Attempting to send email to: $to with subject: $subject");
    
    // Send email
    $result = mail($to, $subject, $formatted_message, $headers);
    
    if ($result) {
        error_log("Automated email sent successfully to: $to");
    } else {
        error_log("Failed to send automated email to: $to");
        // Get last error if available
        $error = error_get_last();
        if ($error && $error['message']) {
            error_log("Last PHP error: " . $error['message']);
        }
    }
    
    return $result;
}

// Log email activity
function logEmailActivity($home_name, $email, $status, $details = '') {
    $mysqli = getDBConnection();
    if (!$mysqli) return;
    
    $stmt = $mysqli->prepare("INSERT INTO email_log (home_name, recipient_email, status, details, sent_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("ssss", $home_name, $email, $status, $details);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();
}

// Create email log table if it doesn't exist
function createEmailLogTable() {
    $mysqli = getDBConnection();
    if (!$mysqli) return;
    
    $createTableQuery = "CREATE TABLE IF NOT EXISTS email_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        home_name VARCHAR(255) NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        status ENUM('sent', 'failed', 'skipped') NOT NULL,
        details TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_home_name (home_name),
        INDEX idx_sent_at (sent_at)
    )";
    
    $mysqli->query($createTableQuery);
    $mysqli->close();
}

// Send consolidated admin summary (ONLY admin email - no individual alerts)
function sendAdminSummary($allLowPettyCash, $totalEmailsSent, $totalEmailsFailed) {
    $adminEmail = 'kamalanathanthananchayan04@gmail.com';
    
    if (empty($allLowPettyCash)) {
        // Send all-clear summary
        $subject = "Weekly Care Home Summary - All Petty Cash Levels OK";
        $message = "Weekly Care Home Petty Cash Summary\n\n";
        $message .= "All care homes have adequate petty cash levels.\n\n";
        $message .= "Summary:\n";
        $message .= "- Total Emails Sent: $totalEmailsSent\n";
        $message .= "- Total Email Failures: $totalEmailsFailed\n\n";
        $message .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
        $message .= "Care Home Management System";
    } else {
        // Send low petty cash summary
        $subject = "URGENT: Weekly Low Petty Cash Summary - " . count($allLowPettyCash) . " Homes Need Attention";
        $message = "URGENT: Low Petty Cash Alert Summary\n\n";
        $message .= "The following care homes have low petty cash and require immediate attention:\n\n";
        
        $pettyCashThreshold = getSetting('petty_cash_alert_threshold', 50);
        
        foreach ($allLowPettyCash as $home) {
            $message .= "🔴 {$home['home_name']}\n";
            $message .= "   Current Balance: £{$home['formatted_amount']}\n";
            $message .= "   Alert Threshold: £" . number_format($pettyCashThreshold, 2) . "\n";
            $message .= "   Deficit: £" . number_format($pettyCashThreshold - $home['remaining_amount'], 2) . "\n\n";
        }
        
        $message .= "Total Homes with Low Petty Cash: " . count($allLowPettyCash) . "\n\n";
        $message .= "Email Summary:\n";
        $message .= "- Total Emails Sent: $totalEmailsSent\n";
        $message .= "- Total Email Failures: $totalEmailsFailed\n\n";
        $message .= "Please ensure these homes receive petty cash replenishment as soon as possible.\n\n";
        $message .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
        $message .= "Care Home Management System";
    }
    
    echo "Sending admin summary to $adminEmail\n";
    if (sendAutomatedEmail($adminEmail, $subject, $message)) {
        echo "Admin summary sent successfully to $adminEmail\n";
        error_log("Admin summary sent successfully to $adminEmail");
        logEmailActivity('ADMIN_SUMMARY', $adminEmail, 'sent', 'Weekly admin summary sent');
        return true;
    } else {
        echo "Failed to send admin summary to $adminEmail\n";
        error_log("Failed to send admin summary to $adminEmail");
        logEmailActivity('ADMIN_SUMMARY', $adminEmail, 'failed', 'Weekly admin summary failed');
        return false;
    }
}

// Main execution function
function runAutomatedEmails() {
    // Create email log table if it doesn't exist
    createEmailLogTable();
    
    echo "Starting automated email process at " . date('Y-m-d H:i:s') . "\n";
    error_log("Starting automated email process at " . date('Y-m-d H:i:s'));
    
    // Get all care homes
    $mysqli = getDBConnection();
    if (!$mysqli) {
        echo "Database connection failed\n";
        error_log("Database connection failed in runAutomatedEmails");
        return;
    }
    
    // Check if homes table exists
    $table_check = $mysqli->query("SHOW TABLES LIKE 'homes'");
    if (!$table_check || $table_check->num_rows == 0) {
        echo "Error: homes table does not exist\n";
        error_log("Error: homes table does not exist");
        $mysqli->close();
        return;
    }
    
    $query = "SELECT id, name FROM homes ORDER BY name";
    $result = $mysqli->query($query);
    
    if (!$result) {
        echo "Error: Could not fetch homes: " . $mysqli->error . "\n";
        error_log("Error: Could not fetch homes: " . $mysqli->error);
        $mysqli->close();
        return;
    }
    
    if ($result->num_rows == 0) {
        echo "No care homes found in database\n";
        error_log("No care homes found in database");
        $mysqli->close();
        return;
    }
    
    $emailsSent = 0;
    $emailsFailed = 0;
    $allLowPettyCashHomes = []; // Collect all homes with low petty cash
    
    // Get all petty cash data once
    $lowPettyCash = getCareHomesWithLowPettyCash();
    
    while ($row = $result->fetch_assoc()) {
        $homeId = (int)$row['id'];
        $homeName = $row['name'];
        
        echo "Processing home: $homeName\n";
        error_log("Processing home: $homeName");
        
        // Get staff email for this home
        $staffEmail = getStaffEmailByHomeName($homeName);
        
        if (empty($staffEmail)) {
            echo "No staff email found for $homeName, skipping...\n";
            error_log("No staff email found for $homeName, skipping...");
            logEmailActivity($homeName, '', 'skipped', 'No staff email found');
            continue;
        }
        
        echo "Staff email for $homeName: $staffEmail\n";
        
        // Get residents with low balance
        $lowBalanceResidents = getResidentsWithLowNetAmount($homeId);
        echo "Found " . count($lowBalanceResidents) . " residents with low balance\n";
        
        // Check petty cash status for this home
        $homePettyCashData = null;
        foreach ($lowPettyCash as $pettyCashData) {
            if ($pettyCashData['home_name'] === $homeName) {
                $homePettyCashData = $pettyCashData;
                $allLowPettyCashHomes[] = $pettyCashData; // Collect for admin summary
                break;
            }
        }
        
        if ($homePettyCashData) {
            echo "Petty cash alert for $homeName: £{$homePettyCashData['formatted_amount']}\n";
        } else {
            echo "Petty cash OK for $homeName\n";
        }
        
        // Check if there are any alerts to send
        if (empty($lowBalanceResidents) && !$homePettyCashData) {
            echo "No alerts for $homeName, skipping...\n";
            error_log("No alerts for $homeName, skipping...");
            logEmailActivity($homeName, $staffEmail, 'skipped', 'No alerts to send');
            continue;
        }
        
        // Generate email content
        $subject = "Weekly Care Home Alert Summary - $homeName";
        $residentThreshold = getSetting('resident_alert_threshold', 100);
        $pettyCashThreshold = getSetting('petty_cash_alert_threshold', 50);
        
        $message = "Dear Care Home Manager,\n\n";
        $message .= "This is your weekly automated alert summary for $homeName.\n\n";
        
        if (!empty($lowBalanceResidents)) {
            $message .= "RESIDENT BALANCE ALERTS:\n";
            $message .= "The following residents have account balances below £" . number_format($residentThreshold, 2) . ":\n\n";
            
            foreach ($lowBalanceResidents as $resident) {
                $message .= "• {$resident['first_name']} {$resident['last_name']}\n";
                $message .= "  - NHS Number: {$resident['nhs_number']}\n";
                $message .= "  - Room: {$resident['room_number']}\n";
                $message .= "  - Current Balance: £{$resident['financial_data']['formatted_amount']}\n";
                $message .= "  - Phone: {$resident['phone']}\n\n";
            }
            
            $message .= "Total residents with low balance: " . count($lowBalanceResidents) . "\n\n";
        }
        
        if ($homePettyCashData) {
            $message .= "PETTY CASH ALERT:\n";
            $message .= "Current petty cash balance: £{$homePettyCashData['formatted_amount']}\n";
            $message .= "Alert threshold: £" . number_format($pettyCashThreshold, 2) . "\n";
            $message .= "Please add funds to maintain adequate petty cash levels.\n\n";
        }
        
        $message .= "Please review these alerts and take appropriate action.\n\n";
        $message .= "This notification was generated automatically by the Care Home Management System.\n\n";
        $message .= "Best regards,\nCare Home Management System";
        
        echo "Sending email to $staffEmail...\n";
        
        // Send email to staff
        $emailSentToStaff = sendAutomatedEmail($staffEmail, $subject, $message);
        if ($emailSentToStaff) {
            $emailsSent++;
            logEmailActivity($homeName, $staffEmail, 'sent', 'Weekly summary sent successfully');
            echo "Email sent successfully to $staffEmail\n";
            error_log("Email sent successfully to $staffEmail for $homeName");
        } else {
            $emailsFailed++;
            logEmailActivity($homeName, $staffEmail, 'failed', 'Email sending failed');
            echo "Failed to send email to $staffEmail\n";
            error_log("Failed to send email to $staffEmail for $homeName");
        }
    }
    
    $mysqli->close();
    
    // Send consolidated admin summary
    echo "\nSending consolidated admin summary...\n";
    if (sendAdminSummary($allLowPettyCashHomes, $emailsSent, $emailsFailed)) {
        echo "Admin summary sent successfully\n";
    } else {
        echo "Failed to send admin summary\n";
        $emailsFailed++;
    }
    
    echo "\nAutomated email process completed at " . date('Y-m-d H:i:s') . "\n";
    echo "Emails sent: $emailsSent\n";
    echo "Emails failed: $emailsFailed\n";
    echo "Total low petty cash homes: " . count($allLowPettyCashHomes) . "\n";
    error_log("Automated email process completed. Sent: $emailsSent, Failed: $emailsFailed, Low Petty Cash Homes: " . count($allLowPettyCashHomes));
}

// Check if script is being run directly (not included)
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    // Run the automated email process
    runAutomatedEmails();
}
?>