<?php 
session_start(); 
// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt'; 
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in and has staff role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Create uploads directory if it doesn't exist
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
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

// Get residents by care home with optional search and filters
function getResidents($home_id, $search = '', $room_filter = '', $gender_filter = '') {
    $mysqli = getDBConnection();
    
    $query = "SELECT * FROM residents WHERE home_id = ?";
    $params = ["i", $home_id];
    
    if (!empty($search)) {
        $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR nhs_number LIKE ?)";
        $search_term = "%$search%";
        $params[0] .= "sss";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($room_filter)) {
        $query .= " AND room_number = ?";
        $params[0] .= "s";
        $params[] = $room_filter;
    }
    
    if (!empty($gender_filter)) {
        $query .= " AND gender = ?";
        $params[0] .= "s";
        $params[] = $gender_filter;
    }
    
    $query .= " ORDER BY first_name, last_name";
    
    $stmt = $mysqli->prepare($query);
    
    if (count($params) > 1) {
        $stmt->bind_param(...$params);
    }
    
    $stmt->execute();
    $stmt->store_result();

    // Bind result variables
    $stmt->bind_result($id, $home_id, $first_name, $last_name, $date_of_birth, $gender, $nhs_number, $nok_name, $nok_relationship, $nok_email, $phone, $nok_number, $address, $medical_conditions, $medications, $admission_date, $room_number, $status);

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
            'status' => $status
        ];
    }
    
    $stmt->close();
    $mysqli->close();
    
    return $residents;
}

// Get resident by ID - FIXED VERSION without get_result()
function getResidentById($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("SELECT * FROM residents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->store_result();

    // Bind result variables
    $stmt->bind_result($id, $home_id, $first_name, $last_name, $date_of_birth, $gender, $nhs_number, $nok_name, $nok_relationship, $nok_email, $phone, $nok_number, $address, $medical_conditions, $medications, $admission_date, $room_number, $status);

    $resident = null;
    if ($stmt->fetch()) {
        $resident = [
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
            'status' => $status
        ];
    }

    $stmt->close();
    $mysqli->close();
    
    return $resident;
}

// Add new resident
function addResident($data) {
    $mysqli = getDBConnection();
    
    $query = "INSERT INTO residents (home_id, first_name, last_name, date_of_birth, gender, nhs_number, nok_name, nok_relationship, nok_email, phone, nok_number, address, medical_conditions, medications, admission_date, room_number) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("isssssssssssssss",
        $data['home_id'],
        $data['first_name'],
        $data['last_name'],
        $data['date_of_birth'],
        $data['gender'],
        $data['nhs_number'],
        $data['nok_name'],
        $data['nok_relationship'],
        $data['nok_email'],
        $data['phone'],
        $data['nok_number'],
        $data['address'],
        $data['medical_conditions'],
        $data['medications'],
        $data['admission_date'],
        $data['room_number']
    );
    
    $success = $stmt->execute();
    $inserted_id = $stmt->insert_id;
    
    $stmt->close();
    $mysqli->close();
    
    return $success ? $inserted_id : false;
}

// Update resident
function updateResident($id, $data) {
    $mysqli = getDBConnection();
    
    $query = "UPDATE residents SET first_name = ?, last_name = ?, date_of_birth = ?, gender = ?, nhs_number = ?, nok_name = ?, nok_relationship = ?, nok_email = ?, phone = ?, nok_number = ?, address = ?, medical_conditions = ?, medications = ?, admission_date = ?, room_number = ? WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("sssssssssssssssi",
        $data['first_name'],
        $data['last_name'],
        $data['date_of_birth'],
        $data['gender'],
        $data['nhs_number'],
        $data['nok_name'],
        $data['nok_relationship'],
        $data['nok_email'],
        $data['phone'],
        $data['nok_number'],
        $data['address'],
        $data['medical_conditions'],
        $data['medications'],
        $data['admission_date'],
        $data['room_number'],
        $id
    );
    
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    
    return $success;
}

// Delete resident
function deleteResident($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("DELETE FROM residents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    return $success;
}

// Deactivate resident
function deactivateResident($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("UPDATE residents SET status = 'deactivated' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    return $success;
}

// Reactivate resident
function reactivateResident($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("UPDATE residents SET status = 'active' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    return $success;
}

// Get unique rooms for filter
function getUniqueRooms($home_id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("SELECT DISTINCT room_number FROM residents WHERE home_id = ? ORDER BY room_number");
    $stmt->bind_param("i", $home_id);
    $stmt->execute();
    $stmt->bind_result($room_number);
    
    $rooms = [];
    while ($stmt->fetch()) {
        $rooms[] = $room_number;
    }

    $stmt->close();
    $mysqli->close();
    
    return $rooms;
}

// Generate report data for a specific home
function generateResidentReport($home_id) {
    $mysqli = getDBConnection();
    
    // Get home name
    $stmt = $mysqli->prepare("SELECT name FROM homes WHERE id = ?");
    $stmt->bind_param("i", $home_id);
    $stmt->execute();
    $stmt->bind_result($home_name);
    $stmt->fetch();
    $stmt->close();
    
    // Get basic resident statistics
    $total_residents = $mysqli->query("SELECT COUNT(*) FROM residents WHERE home_id = $home_id")->fetch_row()[0];
    $avg_age = $mysqli->query("SELECT AVG(TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())) FROM residents WHERE home_id = $home_id")->fetch_row()[0];
    
    // Get room occupancy with resident details
    $room_details = [];
    $room_result = $mysqli->query("
        SELECT 
            room_number,
            COUNT(*) as occupant_count,
            GROUP_CONCAT(CONCAT(first_name, ' ', last_name) SEPARATOR ', ') as residents
        FROM residents 
        WHERE home_id = $home_id 
        GROUP BY room_number 
        ORDER BY room_number
    ");
    while ($row = $room_result->fetch_assoc()) {
        $room_details[] = $row;
    }
    
    // Get residents with medical conditions
    $medical_residents = $mysqli->query("SELECT COUNT(*) FROM residents WHERE home_id = $home_id AND medical_conditions IS NOT NULL AND medical_conditions != ''")->fetch_row()[0];
    
    // Get residents on medications
    $medication_residents = $mysqli->query("SELECT COUNT(*) FROM residents WHERE home_id = $home_id AND medications IS NOT NULL AND medications != ''")->fetch_row()[0];
    
    // Get recent admissions (last 30 days)
    $recent_admissions = $mysqli->query("SELECT COUNT(*) FROM residents WHERE home_id = $home_id AND admission_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch_row()[0];
    
    // Get total available rooms (assuming from room numbers)
    $total_rooms = $mysqli->query("SELECT COUNT(DISTINCT room_number) FROM residents WHERE home_id = $home_id")->fetch_row()[0];
    
    // Get residents with emergency contacts
    $emergency_contacts = $mysqli->query("SELECT COUNT(*) FROM residents WHERE home_id = $home_id AND nok_name IS NOT NULL AND nok_name != ''")->fetch_row()[0];
    
    $mysqli->close();
    
    return [
        'home_name' => $home_name,
        'total_residents' => $total_residents,
        'avg_age' => round($avg_age, 1),
        'room_details' => $room_details,
        'medical_residents' => $medical_residents,
        'medication_residents' => $medication_residents,
        'recent_admissions' => $recent_admissions,
        'total_rooms' => $total_rooms,
        'emergency_contacts' => $emergency_contacts,
        'report_date' => date('Y-m-d H:i:s')
    ];
}

// Get resident financial data
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
   // --- Total Drop ---
    $drop_query = $mysqli->prepare("SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'drop'");
    $drop_query->bind_param("ii", $home_id, $resident_id);
    $drop_query->execute();
    $drop_query->bind_result($total_drop);
    $drop_query->fetch();
    $drop_query->close();
    if (!$total_drop) $total_drop = 0;

    // --- Net Amount = Income - (Expense + Drop) ---
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
        // You can split the net_amount equally or use other logic
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

// Handle API requests for resident transactions
if (isset($_GET['action']) && $_GET['action'] === 'get_resident_transactions') {
    header('Content-Type: application/json');
    
    try {
        $resident_id = $_GET['resident_id'] ?? null;
        $home_id = $_GET['home_id'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $month_filter = $_GET['month'] ?? '';
        $sort_order = $_GET['sort'] ?? 'DESC';
        $per_page = 10;
        $offset = ($page - 1) * $per_page;
        
        if (!$resident_id || !$home_id) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            exit();
        }
        
        $mysqli = getDBConnection();
        if (!$mysqli) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit();
        }
        
        // Build WHERE clause for month filter
        $month_where = '';
        $month_params = [];
        if (!empty($month_filter)) {
            $month_where = " AND DATE_FORMAT(COALESCE(t.transaction_date, DATE(t.created_at)), '%Y-%m') = ?";
            $month_params[] = $month_filter;
        }
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM transactions t WHERE t.resident_id = ? AND t.home_id = ?" . $month_where;
        $count_stmt = $mysqli->prepare($count_query);
        if (!$count_stmt) {
            echo json_encode(['success' => false, 'error' => 'Count query preparation failed: ' . $mysqli->error]);
            exit();
        }
        
        if (!empty($month_params)) {
            $count_stmt->bind_param("iis", $resident_id, $home_id, $month_params[0]);
        } else {
            $count_stmt->bind_param("ii", $resident_id, $home_id);
        }
        $count_stmt->execute();
        $count_stmt->bind_result($total_records);
        $count_stmt->fetch();
        $count_stmt->close();
        
        // Get transactions with pagination and sorting
        $order_clause = "ORDER BY COALESCE(t.transaction_date, t.created_at) " . ($sort_order === 'ASC' ? 'ASC' : 'DESC') . ", t.id " . ($sort_order === 'ASC' ? 'ASC' : 'DESC');
        
        $query = "SELECT t.id, t.home_id, t.resident_id, t.type, t.amount, t.payment_method, t.description, t.reference_no, t.created_by, t.created_at, t.transaction_date,
                         COALESCE(t.transaction_date, DATE(t.created_at)) as display_date,
                         DATE_FORMAT(COALESCE(t.transaction_date, DATE(t.created_at)), '%Y-%m') as month_year
                  FROM transactions t 
                  WHERE t.resident_id = ? AND t.home_id = ?" . $month_where . "
                  " . $order_clause . "
                  LIMIT ? OFFSET ?";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Main query preparation failed: ' . $mysqli->error]);
            exit();
        }
        
        if (!empty($month_params)) {
            $stmt->bind_param("iisii", $resident_id, $home_id, $month_params[0], $per_page, $offset);
        } else {
            $stmt->bind_param("iiii", $resident_id, $home_id, $per_page, $offset);
        }
        $stmt->execute();
        $stmt->bind_result($id, $t_home_id, $t_resident_id, $type, $amount, $payment_method, $description, $reference_no, $created_by, $created_at, $transaction_date, $display_date, $month_year);
        
        $transactions = [];
        while ($stmt->fetch()) {
            $transactions[] = [
                'id' => $id,
                'home_id' => $t_home_id,
                'resident_id' => $t_resident_id,
                'type' => $type,
                'amount' => $amount,
                'payment_method' => $payment_method,
                'description' => $description,
                'reference_no' => $reference_no,
                'created_by' => $created_by,
                'created_at' => $created_at,
                'transaction_date' => $transaction_date,
                'display_date' => $display_date,
                'month_year' => $month_year
            ];
        }
        $stmt->close();
        
        // Get monthly summary (always show all months for filter dropdown)
        $summary_query = "SELECT DATE_FORMAT(COALESCE(t.transaction_date, DATE(t.created_at)), '%Y-%m') as month,
                                 SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as income,
                                 SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as expense,
                                 SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END) as drop_amount,
                                 COUNT(*) as count
                          FROM transactions t 
                          WHERE t.resident_id = ? AND t.home_id = ?
                          GROUP BY DATE_FORMAT(COALESCE(t.transaction_date, DATE(t.created_at)), '%Y-%m')
                          ORDER BY month DESC";
        
        $summary_stmt = $mysqli->prepare($summary_query);
        if (!$summary_stmt) {
            echo json_encode(['success' => false, 'error' => 'Summary query preparation failed: ' . $mysqli->error]);
            exit();
        }
        
        $summary_stmt->bind_param("ii", $resident_id, $home_id);
        $summary_stmt->execute();
        $summary_stmt->bind_result($month, $income, $expense, $drop_amount, $count);
        
        $monthly_summary = [];
        while ($summary_stmt->fetch()) {
            $monthly_summary[] = [
                'month' => $month,
                'income' => $income,
                'expense' => $expense,
                'drop' => $drop_amount,
                'count' => $count
            ];
        }
        $summary_stmt->close();
        
        // Format transactions
        foreach ($transactions as &$transaction) {
            // Format amount based on transaction type
            if ($transaction['type'] === 'income') {
                $transaction['formatted_amount'] = '+£' . number_format((float)$transaction['amount'], 2);
                $transaction['amount_class'] = 'text-success';
            } else {
                $transaction['formatted_amount'] = '-£' . number_format((float)$transaction['amount'], 2);
                $transaction['amount_class'] = 'text-danger';
            }
            
            // Format date
            $transaction['formatted_date'] = date('d/m/Y', strtotime($transaction['display_date']));
            
            // Ensure numeric values
            $transaction['amount'] = (float)$transaction['amount'];
        }
        
        // Calculate previous month balance (all transactions before current month/filter)
        $current_month = !empty($month_filter) ? $month_filter : date('Y-m');
        $prev_balance_query = "SELECT 
                                SUM(CASE WHEN t.type = 'income' THEN t.amount 
                                    WHEN t.type IN ('expense', 'drop') THEN -t.amount 
                                    ELSE 0 END) as balance
                               FROM transactions t 
                               WHERE t.resident_id = ? AND t.home_id = ? 
                               AND DATE_FORMAT(COALESCE(t.transaction_date, DATE(t.created_at)), '%Y-%m') < ?";
        
        $prev_stmt = $mysqli->prepare($prev_balance_query);
        $prev_stmt->bind_param("iis", $resident_id, $home_id, $current_month);
        $prev_stmt->execute();
        $prev_stmt->bind_result($previous_balance);
        $prev_stmt->fetch();
        $prev_stmt->close();
        
        $previous_balance = (float)($previous_balance ?? 0);
        
        $total_pages = ceil($total_records / $per_page);
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions,
            'monthly_summary' => $monthly_summary,
            'previous_balance' => $previous_balance,
            'current_month' => $current_month,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_records' => $total_records,
                'per_page' => $per_page
            ]
        ]);
        
        $mysqli->close();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
    }
    
    exit();
}

// Handle CSV export for resident transactions
if (isset($_GET['action']) && $_GET['action'] === 'export_resident_transactions') {
    $resident_id = $_GET['resident_id'] ?? null;
    $home_id = $_GET['home_id'] ?? null;
    $month_filter = $_GET['month'] ?? '';
    
    if (!$resident_id || !$home_id) {
        http_response_code(400);
        echo 'Missing parameters';
        exit();
    }
    
    try {
        $mysqli = getDBConnection();
        if (!$mysqli) {
            http_response_code(500);
            echo 'Database connection failed';
            exit();
        }
        
        // Build WHERE clause for month filter
        $month_where = '';
        $month_params = [];
        if (!empty($month_filter)) {
            $month_where = " AND DATE_FORMAT(COALESCE(t.transaction_date, DATE(t.created_at)), '%Y-%m') = ?";
            $month_params[] = $month_filter;
        }
        
        // Get resident name
        $name_query = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM residents WHERE id = ?";
        $name_stmt = $mysqli->prepare($name_query);
        $name_stmt->bind_param("i", $resident_id);
        $name_stmt->execute();
        $name_stmt->bind_result($resident_name);
        $name_stmt->fetch();
        $name_stmt->close();
        
        // Get all transactions
        $query = "SELECT t.id, t.type, t.amount, t.payment_method, t.description, t.reference_no, t.created_by, t.created_at, t.transaction_date,
                         COALESCE(t.transaction_date, DATE(t.created_at)) as display_date
                  FROM transactions t 
                  WHERE t.resident_id = ? AND t.home_id = ?" . $month_where . "
                  ORDER BY COALESCE(t.transaction_date, t.created_at) DESC, t.id DESC";
        
        $stmt = $mysqli->prepare($query);
        if (!empty($month_params)) {
            $stmt->bind_param("iis", $resident_id, $home_id, $month_params[0]);
        } else {
            $stmt->bind_param("ii", $resident_id, $home_id);
        }
        $stmt->execute();
        $stmt->bind_result($id, $type, $amount, $payment_method, $description, $reference_no, $created_by, $created_at, $transaction_date, $display_date);
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="transactions_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $resident_name) . '_' . date('Y-m-d') . '.csv"');
        
        // Output CSV header
        echo "Date,Type,Description,Payment Method,Amount,Reference,Created By,Created Date\n";
        
        // Output data
        while ($stmt->fetch()) {
            $formatted_amount = ($type === 'income' ? '+' : '-') . number_format($amount, 2);
            $formatted_date = date('d/m/Y', strtotime($display_date));
            $formatted_created = date('d/m/Y H:i:s', strtotime($created_at));
            
            echo sprintf('"%s","%s","%s","%s","£%s","%s","%s","%s"' . "\n",
                $formatted_date,
                strtoupper($type),
                str_replace('"', '""', $description ?? ''),
                strtoupper($payment_method),
                $formatted_amount,
                $reference_no ?? '',
                $created_by ?? 'System',
                $formatted_created
            );
        }
        
        $stmt->close();
        $mysqli->close();
        
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Export failed: ' . $e->getMessage();
    }
    
    exit();
}

// Process form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $selected_home_id = $_POST['home_id'] ?? ($_GET['home_id'] ?? null);
        
        if ($action === 'add_resident') {
            // Merge title with first name if title is provided
            $first_name = trim($_POST['first_name']);
            $title = trim($_POST['title'] ?? '');
            if (!empty($title)) {
                $first_name = $title . ' ' . $first_name;
            }
            
            $resident_data = [
                'home_id' => $selected_home_id,
                'first_name' => $first_name,
                'last_name' => trim($_POST['last_name']),
                'date_of_birth' => $_POST['date_of_birth'],
                'gender' => $_POST['gender'],
                'nhs_number' => trim($_POST['nhs_number']),
                'nok_name' => trim($_POST['nok_name']),
                'nok_relationship' => trim($_POST['nok_relationship']),
                'nok_email' => trim($_POST['nok_email']),
                'phone' => trim($_POST['phone']),
                'nok_number' => trim($_POST['nok_number']),
                'address' => trim($_POST['address']),
                'medical_conditions' => trim($_POST['medical_conditions']),
                'medications' => trim($_POST['medications']),
                'admission_date' => $_POST['admission_date'],
                'room_number' => trim($_POST['room_number'])
            ];
            
            $result = addResident($resident_data);
            if ($result) {
                $message = "Resident added successfully!";
                $message_type = "success";
            } else {
                $message = "Error adding resident.";
                $message_type = "error";
            }
        }
        elseif ($action === 'update_resident') {
            $resident_id = $_POST['resident_id'];
            
            // Merge title with first name if title is provided
            $first_name = trim($_POST['first_name']);
            $title = trim($_POST['title'] ?? '');
            if (!empty($title)) {
                $first_name = $title . ' ' . $first_name;
            }
            
            $resident_data = [
                'first_name' => $first_name,
                'last_name' => trim($_POST['last_name']),
                'date_of_birth' => $_POST['date_of_birth'],
                'gender' => $_POST['gender'],
                'nhs_number' => trim($_POST['nhs_number']),
                'nok_name' => trim($_POST['nok_name']),
                'nok_relationship' => trim($_POST['nok_relationship']),
                'nok_email' => trim($_POST['nok_email']),
                'phone' => trim($_POST['phone']),
                'nok_number' => trim($_POST['nok_number']),
                'address' => trim($_POST['address']),
                'medical_conditions' => trim($_POST['medical_conditions']),
                'medications' => trim($_POST['medications']),
                'admission_date' => $_POST['admission_date'],
                'room_number' => trim($_POST['room_number'])
            ];
            
            $result = updateResident($resident_id, $resident_data);
            if ($result) {
                $message = "Resident updated successfully!";
                $message_type = "success";
                // Redirect to clear edit mode
                header("Location: residents.php?home_id=" . $selected_home_id . "&message=updated");
                exit();
            } else {
                $message = "Error updating resident.";
                $message_type = "error";
            }
        }
        elseif ($action === 'delete_resident') {
            $resident_id = $_POST['resident_id'];
            $result = deleteResident($resident_id);
            if ($result) {
                $message = "Resident deleted successfully!";
                $message_type = "success";
                // Redirect to refresh the page
                header("Location: residents.php?home_id=" . $selected_home_id . "&message=deleted");
                exit();
            } else {
                $message = "Error deleting resident.";
                $message_type = "error";
            }
        }
        elseif ($action === 'deactivate_resident') {
            $resident_id = $_POST['resident_id'];
            $result = deactivateResident($resident_id);
            if ($result) {
                $message = "Resident deactivated successfully!";
                $message_type = "success";
                // Redirect to refresh the page
                header("Location: residents.php?home_id=" . $selected_home_id . "&message=deactivated");
                exit();
            } else {
                $message = "Error deactivating resident.";
                $message_type = "error";
            }
        }
        elseif ($action === 'reactivate_resident') {
            $resident_id = $_POST['resident_id'];
            $result = reactivateResident($resident_id);
            if ($result) {
                $message = "Resident reactivated successfully!";
                $message_type = "success";
                // Redirect to refresh the page
                header("Location: residents.php?home_id=" . $selected_home_id . "&message=reactivated");
                exit();
            } else {
                $message = "Error reactivating resident.";
                $message_type = "error";
            }
        }
    }
}

// Get selected care home
$selected_home_id = $_GET['home_id'] ?? ($_POST['home_id'] ?? null);
$care_homes = getCareHomes();

// Get residents for selected care home
$residents = [];
$unique_rooms = [];
if ($selected_home_id) {
    $search = $_GET['search'] ?? '';
    $room_filter = $_GET['room_filter'] ?? '';
    $gender_filter = $_GET['gender_filter'] ?? '';
    
    $residents = getResidents($selected_home_id, $search, $room_filter, $gender_filter);
    $unique_rooms = getUniqueRooms($selected_home_id);
}

// Get resident for editing
$edit_resident = null;
$edit_title = '';
$edit_first_name = '';
if (isset($_GET['edit_id'])) {
    $edit_resident = getResidentById($_GET['edit_id']);
    
    // Split the first name to extract title if it exists
    if ($edit_resident && !empty($edit_resident['first_name'])) {
        $titles = ['Mr', 'Mrs', 'Ms', 'Dr', 'Lord'];
        $full_first_name = $edit_resident['first_name'];
        
        foreach ($titles as $title) {
            if (strpos($full_first_name, $title . ' ') === 0) {
                $edit_title = $title;
                $edit_first_name = substr($full_first_name, strlen($title . ' '));
                break;
            }
        }
        
        // If no title found, use the full name as first name
        if (empty($edit_title)) {
            $edit_first_name = $full_first_name;
        }
    }
}

// Generate report if requested
$report_data = null;
if (isset($_GET['generate_report']) && $selected_home_id) {
    $report_data = generateResidentReport($selected_home_id);
}

// Check for success message from redirect
if (isset($_GET['message'])) {
    if ($_GET['message'] == 'updated') {
        $message = "Resident updated successfully!";
        $message_type = "success";
    } elseif ($_GET['message'] == 'deleted') {
        $message = "Resident deleted successfully!";
        $message_type = "success";
    } elseif ($_GET['message'] == 'deactivated') {
        $message = "Resident deactivated successfully!";
        $message_type = "success";
    } elseif ($_GET['message'] == 'reactivated') {
        $message = "Resident reactivated successfully!";
        $message_type = "success";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Residents - Care Home Management</title>
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
        
        /* Transaction Modal Specific Styles */
        .text-success {
            color: #27ae60 !important;
        }
        
        .text-danger {
            color: #e74c3c !important;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 0.8rem;
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
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
        
        /* Sub-content Navigation */
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
        
        /* Sub-content sections */
        .sub-content {
            display: none;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
        
        /* Form styles */
        .resident-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
        }
        
        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border 0.3s;
            box-sizing: border-box;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        /* Custom layout for name fields */
        .form-group-container.half-width {
            flex: 1;
        }
        
        .form-group.half-width {
            flex: 1;
        }
        
        .name-group {
            display: flex;
            gap: 10px;
        }
        
        .title-field {
            flex: 1; /* 1/3 of the name-group */
        }
        
        .firstname-field {
            flex: 2; /* 2/3 of the name-group */
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        /* Table controls */
        .residents-controls {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-start;
        }
        
        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
            max-width: 400px;
        }
        
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            box-sizing: border-box;
        }
        
        .filter-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            flex-shrink: 0;
        }
        
        .filter-controls select {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            min-width: 150px;
            box-sizing: border-box;
        }
        
        /* Room Filter Dropdown Styles */
        .room-filter-container {
            position: relative;
            min-width: 150px;
        }
        
        .room-filter-container input {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 100%;
            box-sizing: border-box;
            background: white;
        }
        
        .room-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 5px 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .room-dropdown.show {
            display: block;
        }
        
        .room-option {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        
        .room-option:last-child {
            border-bottom: none;
        }
        
        .room-option:hover {
            background-color: #f8f9fa;
        }
        
        .room-option.selected {
            background-color: #3498db;
            color: white;
        }
        
        .room-option.hidden {
            display: none;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-edit {
            background: #f39c12;
            color: white;
        }
        
        .btn-edit:hover {
            background: #e67e22;
        }
        
        .btn-view {
            background: #3498db;
            color: white;
        }
        
        .btn-view:hover {
            background: #2980b9;
        }
        
        .btn-transactions {
            background: #9b59b6;
            color: white;
        }
        
        .btn-transactions:hover {
            background: #8e44ad;
        }
        
        .btn-delete {
            background: #e74c3c;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c0392b;
        }
        
        .btn-deactivate {
            background: #f39c12;
            color: white;
        }
        
        .btn-deactivate:hover {
            background: #e67e22;
        }
        
        .btn-reactivate {
            background: #27ae60;
            color: white;
        }
        
        .btn-reactivate:hover {
            background: #219a52;
        }
        
        .btn-view {
            background: #3498db;
            color: white;
        }
        
        .btn-view:hover {
            background: #2980b9;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin-left: 8px;
        }
        
        .status-badge.deactivated {
            background: #e74c3c;
            color: white;
        }
        
        /* NEW: Mobile Resident Cards */
        .resident-mobile-card {
            display: none;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 15px;
            padding: 15px;
        }
        
        .resident-card-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .resident-card-row:last-child {
            border-bottom: none;
        }
        
        .resident-card-label {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .resident-card-value {
            color: #7f8c8d;
            text-align: right;
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
            
            .form-grid, .form-row {
                grid-template-columns: 1fr;
                flex-direction: column;
                gap: 15px;
            }
            
            /* Stack name fields vertically on mobile */
            .name-group {
                flex-direction: column;
                gap: 15px;
            }
            
            .title-field, .firstname-field {
                flex: 1;
            }
        }
        
        @media (max-width: 768px) {
            .main-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .top-actions, .residents-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                max-width: 100%;
                margin-bottom: 10px;
            }
            
            .filter-controls {
                flex-direction: column;
                gap: 10px;
            }
            
            .filter-controls select {
                min-width: 100%;
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
            
            .sub-content-nav {
                flex-direction: column;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            /* Show mobile cards and hide table on small screens */
            .resident-mobile-card {
                display: block;
            }
            
            .desktop-table {
                display: none;
            }
            
            .modal-body .form-grid {
                grid-template-columns: 1fr;
            }
            
            .sub-content-body {
                padding: 15px;
            }
            
            .report-summary {
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
            
            .sub-content-header {
                padding: 12px 15px;
                font-size: 1.1rem;
            }
            
            .form-group input, .form-group select, .form-group textarea {
                padding: 10px 12px;
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
                <li class="menu-item active"><a href="residents.php"><i class="fas fa-users"></i> Residents</a></li>
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
                <h1><i class="fas fa-users"></i> Residents Management</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin User'); ?></span> 
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>

            <!-- Top Actions -->
            <div class="content-area">
                <div class="top-actions">
                    <button id="btnGenerateReport" class="btn btn-info">
                        <i class="fas fa-file-alt"></i> Generate Report
                    </button>
                    <button id="btnTechnicalContact" class="btn btn-secondary">
                        <i class="fas fa-headset"></i> Technical Contact
                    </button>
                </div>
            </div>

            <!-- Care Home Selector -->
            <div class="content-area">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-home"></i>
                        <h2>Select Care Home</h2>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#2c3e50;">
                                <i class="fas fa-home"></i> Select Care Home:
                            </label>
                            <select id="carehomeSelector" name="home_id" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;min-width:200px;font-size:14px;box-sizing:border-box;" onchange="this.form.submit()">
                                <option value="">Choose a care home...</option>
                                <?php foreach ($care_homes as $home): ?>
                                    <option value="<?php echo $home['id']; ?>" <?php echo $selected_home_id == $home['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($home['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn" style="background:#27ae60;color:#fff;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="content-area">
                <?php if (!empty($message)): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            showNotification(
                                '<?php echo $message_type === 'success' ? 'Success' : 'Error'; ?>',
                                '<?php echo addslashes($message); ?>',
                                '<?php echo $message_type; ?>'
                            );
                        });
                    </script>
                <?php endif; ?>

                <!-- Show warning if no care home selected -->
                <?php if (!$selected_home_id): ?>
                    <div class="card">
                        <div class="card-body">
                            <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                                <i class="fas fa-home" style="font-size: 3rem; margin-bottom: 20px;"></i>
                                <p>Please select a care home to manage residents.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Sub-content Navigation -->
                    <div class="sub-content-nav">
                        <button class="sub-nav-btn<?php echo $edit_resident ? ' active' : ''; ?>" data-target="add-resident">
                            <i class="fas fa-user-plus"></i>
                            <?php echo $edit_resident ? 'Edit Resident' : 'Add Resident'; ?>
                        </button>
                        <button class="sub-nav-btn<?php echo !$edit_resident ? ' active' : ''; ?>" data-target="list-residents">
                            <i class="fas fa-list"></i>
                            List of Residents
                        </button>
                    </div>

                    <!-- Add/Edit Resident Sub-content -->
                    <div id="add-resident" class="sub-content<?php echo $edit_resident ? ' active' : ''; ?>">
                        <div class="sub-content-header">
                            <i class="fas fa-user-plus"></i>
                            <?php echo $edit_resident ? 'Edit Resident' : 'Add New Resident'; ?>
                        </div>
                        <div class="sub-content-body">
                            <form class="resident-form" id="residentForm" method="POST" action="">
                                <input type="hidden" name="home_id" value="<?php echo $selected_home_id; ?>">
                                <input type="hidden" name="action" value="<?php echo $edit_resident ? 'update_resident' : 'add_resident'; ?>">
                                <?php if ($edit_resident): ?>
                                    <input type="hidden" name="resident_id" value="<?php echo $edit_resident['id']; ?>">
                                <?php endif; ?>

                                <div class="form-row">
                                    <div class="form-group-container half-width">
                                        <div class="name-group">
                                            <div class="form-group title-field">
                                                <label for="title">
                                                    <i class="fas fa-user-tag"></i>
                                                    Title
                                                </label>
                                                <select id="title" name="title">
                                                    <option value="">Select Title</option>
                                                    <option value="Mr" <?php echo ($edit_resident && $edit_title == 'Mr') ? 'selected' : ''; ?>>Mr</option>
                                                    <option value="Mrs" <?php echo ($edit_resident && $edit_title == 'Mrs') ? 'selected' : ''; ?>>Mrs</option>
                                                    <option value="Ms" <?php echo ($edit_resident && $edit_title == 'Ms') ? 'selected' : ''; ?>>Ms</option>
                                                    <option value="Dr" <?php echo ($edit_resident && $edit_title == 'Dr') ? 'selected' : ''; ?>>Dr</option>
                                                    <option value="Lord" <?php echo ($edit_resident && $edit_title == 'Lord') ? 'selected' : ''; ?>>Lord</option>
                                                </select>
                                            </div>
                                            <div class="form-group firstname-field">
                                                <label for="firstName">
                                                    <i class="fas fa-user"></i>
                                                    First Name
                                                </label>
                                                <input type="text" id="firstName" name="first_name" value="<?php echo $edit_resident ? htmlspecialchars($edit_first_name) : ''; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group half-width">
                                        <label for="lastName">
                                            <i class="fas fa-user"></i>
                                            Last Name
                                        </label>
                                        <input type="text" id="lastName" name="last_name" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['last_name']) : ''; ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="dateOfBirth">
                                            <i class="fas fa-calendar"></i>
                                            Date of Birth
                                        </label>
                                        <input type="date" id="dateOfBirth" name="date_of_birth" value="<?php echo $edit_resident ? $edit_resident['date_of_birth'] : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="gender">
                                            <i class="fas fa-venus-mars"></i>
                                            Gender
                                        </label>
                                        <select id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo ($edit_resident && $edit_resident['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($edit_resident && $edit_resident['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($edit_resident && $edit_resident['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="nhsNumber">
                                            <i class="fas fa-id-card"></i>
                                            NHS Number
                                        </label>
                                        <input type="text" id="nhsNumber" name="nhs_number" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['nhs_number']) : ''; ?>" pattern="\d{10}" title="Enter 10 digit NHS Number" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="nokName">
                                            <i class="fas fa-user-friends"></i>
                                            NOK Name
                                        </label>
                                        <input type="text" id="nokName" name="nok_name" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['nok_name']) : ''; ?>" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="phone">
                                            <i class="fas fa-phone"></i>
                                            Phone Number
                                        </label>
                                        <input type="tel" id="phone" name="phone" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['phone']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="nokEmail">
                                            <i class="fas fa-envelope"></i>
                                            NOK Email Address
                                        </label>
                                        <input type="email" id="nokEmail" name="nok_email" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['nok_email']) : ''; ?>" placeholder="next.of.kin@email.com">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="nokNumber">
                                            <i class="fas fa-phone-alt"></i>
                                            NOK Phone Number
                                        </label>
                                        <input type="tel" id="nokNumber" name="nok_number" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['nok_number']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="nokRelationship">
                                            <i class="fas fa-heart"></i>
                                            NOK Relationship
                                        </label>
                                        <input type="text" id="nokRelationship" name="nok_relationship" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['nok_relationship']) : ''; ?>" placeholder="e.g., Son, Daughter, Spouse" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="address">
                                        <i class="fas fa-map-marker-alt"></i>
                                        Address
                                    </label>
                                    <textarea id="address" name="address" rows="3"><?php echo $edit_resident ? htmlspecialchars($edit_resident['address']) : ''; ?></textarea>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="medicalConditions">
                                            <i class="fas fa-heartbeat"></i>
                                            Medical Conditions
                                        </label>
                                        <textarea id="medicalConditions" name="medical_conditions" rows="2" placeholder="Any existing medical conditions..."><?php echo $edit_resident ? htmlspecialchars($edit_resident['medical_conditions']) : ''; ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="medications">
                                            <i class="fas fa-pills"></i>
                                            Current Medications
                                        </label>
                                        <textarea id="medications" name="medications" rows="2" placeholder="Current medications..."><?php echo $edit_resident ? htmlspecialchars($edit_resident['medications']) : ''; ?></textarea>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="admissionDate">
                                            <i class="fas fa-calendar-check"></i>
                                            Admission Date
                                        </label>
                                        <input type="date" id="admissionDate" name="admission_date" value="<?php echo $edit_resident ? $edit_resident['admission_date'] : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="roomNumber">
                                            <i class="fas fa-bed"></i>
                                            Room Number
                                        </label>
                                        <input type="text" id="roomNumber" name="room_number" value="<?php echo $edit_resident ? htmlspecialchars($edit_resident['room_number']) : ''; ?>" required>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i>
                                        <?php echo $edit_resident ? 'Update Resident' : 'Add Resident'; ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                        <i class="fas fa-undo"></i>
                                        Reset Form
                                    </button>
                                    <?php if ($edit_resident): ?>
                                        <a href="residents.php?home_id=<?php echo $selected_home_id; ?>" class="btn btn-secondary">
                                            <i class="fas fa-times"></i>
                                            Cancel Edit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- List of Residents Sub-content -->
                    <div id="list-residents" class="sub-content<?php echo !$edit_resident ? ' active' : ''; ?>">
                        <div class="sub-content-header">
                            <i class="fas fa-list"></i>
                            List of Residents
                        </div>
                        <div class="sub-content-body">
                            <form method="GET" action="">
                                <input type="hidden" name="home_id" value="<?php echo $selected_home_id; ?>">
                                <div class="residents-controls">
                                    <div class="search-box">
                                        <i class="fas fa-search"></i>
                                        <input type="text" name="search" placeholder="Search by name or NHS number..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                    </div>
                                    <div class="filter-controls">
                                        <div class="room-filter-container">
                                            <input type="text" name="room_filter" id="roomFilterInput" placeholder="Type room number..." 
                                                   value="<?php echo htmlspecialchars($_GET['room_filter'] ?? ''); ?>" 
                                                   autocomplete="off">
                                            <div class="room-dropdown" id="roomDropdown">
                                                <div class="room-option" data-room="">All Rooms</div>
                                                <?php foreach ($unique_rooms as $room): ?>
                                                    <div class="room-option" data-room="<?php echo htmlspecialchars($room); ?>">
                                                        Room <?php echo htmlspecialchars($room); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <select name="gender_filter">
                                            <option value="">All Genders</option>
                                            <option value="male" <?php echo ($_GET['gender_filter'] ?? '') == 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($_GET['gender_filter'] ?? '') == 'female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($_GET['gender_filter'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                        <button type="submit" class="btn btn-primary" style="padding: 8px 15px;">
                                            <i class="fas fa-filter"></i> Apply
                                        </button>
                                        <a href="residents.php?home_id=<?php echo $selected_home_id; ?>" class="btn btn-secondary" style="padding: 8px 15px;">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
                                    </div>
                                </div>
                            </form>

                            <!-- Desktop Table -->
                            <div class="mobile-table-container">
                                <table class="residents-table desktop-table">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-user"></i> Name</th>
                                            <th><i class="fas fa-calendar-check"></i> Admission Date</th>
                                            <th><i class="fas fa-university"></i> Net Bank</th>
                                            <th><i class="fas fa-money-bill-wave"></i> Net Cash</th>
                                            <th><i class="fas fa-wallet"></i> Net Amount</th>
                                            <th><i class="fas fa-cogs"></i> Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="residentsTableBody">
                                        <?php if (empty($residents)): ?>
                                            <tr>
                                                <td colspan="6" style="text-align: center; padding: 20px;">
                                                    <i class="fas fa-info-circle"></i> No residents found.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($residents as $resident): ?>
                                                <?php
                                                $age = '';
                                                if ($resident['date_of_birth']) {
                                                    $dob = new DateTime($resident['date_of_birth']);
                                                    $today = new DateTime();
                                                    $age = $dob->diff($today)->y;
                                                }
                                                
                                                // Get financial data for this resident
                                                $financial_data = getResidentFinancialData($resident['id'], $selected_home_id);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="resident-info">
                                                            <div class="resident-avatar">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong>
                                                                <?php if (isset($resident['status']) && $resident['status'] === 'deactivated'): ?>
                                                                    <span class="status-badge deactivated">Deactivated</span>
                                                                <?php endif; ?>
                                                                <br>
                                                                <small style="color: #666;">Age: <?php echo $age; ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($resident['admission_date'])); ?></td>
                                                    <td>
                                                        <strong style="color: <?php echo $financial_data['net_bank'] >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                                            £<?php echo $financial_data['formatted_bank']; ?>
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <strong style="color: <?php echo $financial_data['net_cash'] >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                                            £<?php echo $financial_data['formatted_cash']; ?>
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <strong style="color: <?php echo $financial_data['net_amount'] >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                                            £<?php echo $financial_data['formatted_amount']; ?>
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button type="button" class="btn-action btn-view" title="View Details" onclick="viewResidentDetails(<?php echo htmlspecialchars(json_encode($resident + $financial_data)); ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            
                                                            <button type="button" class="btn-action btn-transactions" title="View Transactions" onclick="viewResidentTransactions(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>', <?php echo $selected_home_id; ?>)">
                                                                <i class="fas fa-exchange-alt"></i>
                                                            </button>
                                                            
                                                            <a href="residents.php?home_id=<?php echo $selected_home_id; ?>&edit_id=<?php echo $resident['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            
                                                            <?php if (!isset($resident['status']) || $resident['status'] !== 'deactivated'): ?>
                                                                <button type="button" class="btn-action btn-deactivate" title="Deactivate" onclick="confirmDeactivateResident(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>')">
                                                                    <i class="fas fa-ban"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" class="btn-action btn-reactivate" title="Reactivate" onclick="confirmReactivateResident(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>')">
                                                                    <i class="fas fa-check-circle"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            
                                                            <button type="button" class="btn-action btn-delete" title="Delete" onclick="confirmDeleteResident(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- NEW: Mobile Resident Cards -->
                            <div id="residentsMobileCards">
                                <?php if (empty($residents)): ?>
                                    <div class="resident-mobile-card" style="text-align: center;">
                                        <i class="fas fa-info-circle"></i> No residents found.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($residents as $resident): ?>
                                        <?php
                                        $age = '';
                                        if ($resident['date_of_birth']) {
                                            $dob = new DateTime($resident['date_of_birth']);
                                            $today = new DateTime();
                                            $age = $dob->diff($today)->y;
                                        }
                                        
                                        $financial_data = getResidentFinancialData($resident['id'], $selected_home_id);
                                        ?>
                                        <div class="resident-mobile-card">
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Name:</span>
                                                <span class="resident-card-value">
                                                    <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?>
                                                    <?php if (isset($resident['status']) && $resident['status'] === 'deactivated'): ?>
                                                        <span class="status-badge deactivated">Deactivated</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Age:</span>
                                                <span class="resident-card-value"><?php echo $age; ?></span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">NHS Number:</span>
                                                <span class="resident-card-value"><?php echo htmlspecialchars($resident['nhs_number']); ?></span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Gender:</span>
                                                <span class="resident-card-value"><?php echo ucfirst(htmlspecialchars($resident['gender'])); ?></span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Room:</span>
                                                <span class="resident-card-value"><?php echo htmlspecialchars($resident['room_number']); ?></span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Contact:</span>
                                                <span class="resident-card-value"><?php echo htmlspecialchars($resident['phone']); ?></span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Net Bank:</span>
                                                <span class="resident-card-value" style="color: <?php echo $financial_data['net_bank'] >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                                    £<?php echo $financial_data['formatted_bank']; ?>
                                                </span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Net Cash:</span>
                                                <span class="resident-card-value" style="color: <?php echo $financial_data['net_cash'] >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                                    £<?php echo $financial_data['formatted_cash']; ?>
                                                </span>
                                            </div>
                                            <div class="resident-card-row">
                                                <span class="resident-card-label">Net Amount:</span>
                                                <span class="resident-card-value" style="color: <?php echo $financial_data['net_amount'] >= 0 ? '#27ae60' : '#e74c3c'; ?>;">
                                                    £<?php echo $financial_data['formatted_amount']; ?>
                                                </span>
                                            </div>
                                            <div class="resident-card-row" style="justify-content:center;padding-top:10px;">
                                                <div class="action-buttons">
                                                    <button type="button" class="btn-action btn-view" title="View Details" onclick="viewResidentDetails(<?php echo htmlspecialchars(json_encode($resident + $financial_data)); ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="btn-action btn-transactions" title="View Transactions" onclick="viewResidentTransactions(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>', <?php echo $selected_home_id; ?>)">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </button>
                                                    
                                                    <a href="residents.php?home_id=<?php echo $selected_home_id; ?>&edit_id=<?php echo $resident['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <?php if (!isset($resident['status']) || $resident['status'] !== 'deactivated'): ?>
                                                        <button type="button" class="btn-action btn-deactivate" title="Deactivate" onclick="confirmDeactivateResident(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>')">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn-action btn-reactivate" title="Reactivate" onclick="confirmReactivateResident(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>')">
                                                            <i class="fas fa-check-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <button type="button" class="btn-action btn-delete" title="Delete" onclick="confirmDeleteResident(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Delete Resident Form (hidden) -->
    <form id="deleteResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="home_id" id="deleteHomeId" value="<?php echo $selected_home_id; ?>">
        <input type="hidden" name="resident_id" id="deleteResidentId">
        <input type="hidden" name="action" value="delete_resident">
    </form>

    <!-- Deactivate Resident Form (hidden) -->
    <form id="deactivateResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="home_id" id="deactivateHomeId" value="<?php echo $selected_home_id; ?>">
        <input type="hidden" name="resident_id" id="deactivateResidentId">
        <input type="hidden" name="action" value="deactivate_resident">
    </form>

    <!-- Reactivate Resident Form (hidden) -->
    <form id="reactivateResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="home_id" id="reactivateHomeId" value="<?php echo $selected_home_id; ?>">
        <input type="hidden" name="resident_id" id="reactivateResidentId">
        <input type="hidden" name="action" value="reactivate_resident">
    </form>

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

        // Delete resident confirmation
        async function confirmDeleteResident(residentId, residentName) {
            const confirmed = await showConfirmation(
                'Delete Resident', 
                `Are you sure you want to delete resident "${residentName}"? This action cannot be undone.`,
                'Delete',
                'Cancel'
            );
            
            if (confirmed) {
                const hideLoading = showLoading();
                
                // Set form values and submit
                document.getElementById('deleteResidentId').value = residentId;
                document.getElementById('deleteHomeId').value = '<?php echo $selected_home_id; ?>';
                document.getElementById('deleteResidentForm').submit();
                
                // The loading will be interrupted by form submission
                setTimeout(hideLoading, 2000);
            }
        }

        // Deactivate resident confirmation
        async function confirmDeactivateResident(residentId, residentName) {
            const confirmed = await showConfirmation(
                'Deactivate Resident', 
                `Are you sure you want to deactivate resident "${residentName}"? This will mark them as inactive but they can be reactivated later.`,
                'Deactivate',
                'Cancel'
            );
            
            if (confirmed) {
                const hideLoading = showLoading();
                
                // Set form values and submit
                document.getElementById('deactivateResidentId').value = residentId;
                document.getElementById('deactivateHomeId').value = '<?php echo $selected_home_id; ?>';
                document.getElementById('deactivateResidentForm').submit();
                
                // The loading will be interrupted by form submission
                setTimeout(hideLoading, 2000);
            }
        }

        // Reactivate resident confirmation
        async function confirmReactivateResident(residentId, residentName) {
            const confirmed = await showConfirmation(
                'Reactivate Resident', 
                `Are you sure you want to reactivate resident "${residentName}"? This will mark them as active again.`,
                'Reactivate',
                'Cancel'
            );
            
            if (confirmed) {
                const hideLoading = showLoading();
                
                // Set form values and submit
                document.getElementById('reactivateResidentId').value = residentId;
                document.getElementById('reactivateHomeId').value = '<?php echo $selected_home_id; ?>';
                document.getElementById('reactivateResidentForm').submit();
                
                // The loading will be interrupted by form submission
                setTimeout(hideLoading, 2000);
            }
        }

        // View Resident Transactions Modal
        async function viewResidentTransactions(residentId, residentName, homeId) {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'modal-content';
            modal.style.maxWidth = '95%';
            modal.style.maxHeight = '90vh';
            modal.style.overflowY = 'auto';
            
            modal.innerHTML = `
                <div class="modal-header">
                    <h3><i class="fas fa-exchange-alt"></i> Transaction History - ${residentName}</h3>
                    <div>
                        <button id="exportTransactions" class="" style="">
                        </button>
                        <button id="closeTransactionsModal" class="btn btn-danger" style="padding:6px 10px;">Close</button>
                    </div>
                </div>
                <div class="modal-body">
                    <!-- Month Filter -->
                    <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <label style="font-weight: 600; color: #2c3e50;">
                            <i class="fas fa-calendar"></i> Filter by Month:
                        </label>
                        <select id="monthFilter" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; min-width: 150px;">
                            <option value="">All Months</option>
                        </select>
                        
                        <!-- Month Navigation -->
                        <div style="display: flex; align-items: center; gap: 5px; margin-left: 10px;">
                            <button id="prevMonth" class="btn btn-secondary btn-sm" style="padding: 6px 10px;" title="Previous Month">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button id="nextMonth" class="btn btn-secondary btn-sm" style="padding: 6px 10px;" title="Next Month">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        
                        <button id="sortDateDesc" class="btn btn-secondary btn-sm" style="padding: 6px 12px;">
                            <i class="fas fa-sort-amount-down"></i> Newest First
                        </button>
                        <button id="sortDateAsc" class="btn btn-secondary btn-sm" style="padding: 6px 12px;">
                            <i class="fas fa-sort-amount-up"></i> Oldest First
                        </button>
                    </div>
                    
                    <!-- Transactions Table -->
                    <div id="transactionsTableContainer">
                        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                            <p style="margin-top: 15px;">Loading transactions...</p>
                        </div>
                    </div>
                    
                    <!-- Pagination -->
                    <div id="paginationContainer" style="margin-top: 20px; text-align: center;">
                        <!-- Pagination will be populated here -->
                    </div>
                    
                    <!-- Monthly Summary Section (moved to bottom) -->
                    <div id="monthlySummarySection" style="margin-top: 30px; border-top: 2px solid #dee2e6; padding-top: 25px;">
                        <!-- Monthly cards will be populated here -->
                    </div>
                </div>`;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Store current transaction data
            window.currentTransactionData = {
                residentId: residentId,
                homeId: homeId,
                currentPage: 1,
                sortOrder: 'DESC',
                selectedMonth: new Date().toISOString().slice(0, 7) // Default to current month (YYYY-MM)
            };

            // Event listeners
            document.getElementById('closeTransactionsModal').addEventListener('click', () => {
                document.body.removeChild(overlay);
            });
            
            document.getElementById('monthFilter').addEventListener('change', (e) => {
                window.currentTransactionData.selectedMonth = e.target.value;
                window.currentTransactionData.currentPage = 1;
                loadTransactionData();
            });
            
            document.getElementById('sortDateDesc').addEventListener('click', () => {
                window.currentTransactionData.sortOrder = 'DESC';
                window.currentTransactionData.currentPage = 1;
                loadTransactionData();
            });
            
            document.getElementById('sortDateAsc').addEventListener('click', () => {
                window.currentTransactionData.sortOrder = 'ASC';
                window.currentTransactionData.currentPage = 1;
                loadTransactionData();
            });
            
            document.getElementById('exportTransactions').addEventListener('click', () => {
                exportTransactionData(residentId, residentName, homeId);
            });
            
            document.getElementById('prevMonth').addEventListener('click', () => {
                navigateMonth(-1);
            });
            
            document.getElementById('nextMonth').addEventListener('click', () => {
                navigateMonth(1);
            });

            overlay.addEventListener('click', (e) => {
                if(e.target === overlay) {
                    document.body.removeChild(overlay);
                }
            });

            // Load initial data
            await loadTransactionData();
        }

        // Load transaction data for the modal
        async function loadTransactionData() {
            const container = document.getElementById('transactionsTableContainer');
            const paginationContainer = document.getElementById('paginationContainer');
            const monthFilter = document.getElementById('monthFilter');
            
            if (!container) return;
            
            container.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                    <p style="margin-top: 15px;">Loading transactions...</p>
                </div>`;
            
            try {
                const params = new URLSearchParams({
                    action: 'get_resident_transactions',
                    resident_id: window.currentTransactionData.residentId,
                    home_id: window.currentTransactionData.homeId,
                    page: window.currentTransactionData.currentPage,
                    sort: window.currentTransactionData.sortOrder
                });
                
                if (window.currentTransactionData.selectedMonth) {
                    params.append('month', window.currentTransactionData.selectedMonth);
                }
                
                const response = await fetch(`residents.php?${params}`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to load transactions');
                }
                
                // Populate month filter if not already done
                if (monthFilter.children.length === 1 && data.monthly_summary.length > 0) {
                    data.monthly_summary.forEach(summary => {
                        const option = document.createElement('option');
                        option.value = summary.month;
                        option.textContent = new Date(summary.month + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'});
                        monthFilter.appendChild(option);
                    });
                }
                
                // Set the current month filter value
                monthFilter.value = window.currentTransactionData.selectedMonth;
                
                // Display monthly summary (show filtered or all)
                const summaryToShow = window.currentTransactionData.selectedMonth 
                    ? data.monthly_summary.filter(s => s.month === window.currentTransactionData.selectedMonth)
                    : data.monthly_summary;
                displayMonthlySummary(summaryToShow);
                
                // Display transactions table
                displayTransactionsTable(data.transactions, data.pagination, data.previous_balance, data.current_month);
                
            } catch (error) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem;"></i>
                        <p style="margin-top: 15px;">Error loading transactions: ${error.message}</p>
                    </div>`;
            }
        }

        // Display monthly summary cards
        function displayMonthlySummary(monthlySummary) {
            const container = document.getElementById('monthlySummarySection');
            if (!container || !monthlySummary.length) {
                container.innerHTML = '';
                return;
            }
            
            let summaryHtml = `
                <h4 style="color: #2c3e50; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-chart-bar"></i> Monthly Financial Summary
                </h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-bottom: 20px;">`;
            
            monthlySummary.forEach(summary => {
                const monthName = new Date(summary.month + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'});
                const totalExpenses = parseFloat(summary.expense || 0) + parseFloat(summary.drop || 0);
                const netAmount = parseFloat(summary.income || 0) - totalExpenses;
                const netColor = netAmount >= 0 ? '#27ae60' : '#e74c3c';
                
                summaryHtml += `
                    <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px;">
                        <h5 style="margin: 0 0 15px 0; color: #2c3e50; text-align: center; border-bottom: 2px solid #3498db; padding-bottom: 8px;">
                            ${monthName}
                        </h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9rem;">
                            <div style="text-align: center; background: #d4edda; padding: 10px; border-radius: 6px;">
                                <strong style="color: #155724;">Transfer in</strong><br>
                                £${parseFloat(summary.income || 0).toFixed(2)}
                            </div>
                            <div style="text-align: center; background: #f8d7da; padding: 10px; border-radius: 6px;">
                                <strong style="color: #721c24;">Transfer out</strong><br>
                                £${parseFloat(summary.expense || 0).toFixed(2)}
                            </div>
                            <div style="text-align: center; background: #fff3cd; padding: 10px; border-radius: 6px;">
                                <strong style="color: #856404;">Paid back</strong><br>
                                £${parseFloat(summary.drop || 0).toFixed(2)}
                            </div>
                            <div style="text-align: center; background: #cce5ff; padding: 10px; border-radius: 6px;">
                                <strong style="color: #004085;">Total Out</strong><br>
                                £${totalExpenses.toFixed(2)}
                            </div>
                            <div style="text-align: center; grid-column: 1 / -1; margin-top: 8px; padding: 12px; border-top: 1px solid #ddd; background: white; border-radius: 6px;">
                                <strong style="color: ${netColor}; font-size: 1.1rem;">Net: £${netAmount.toFixed(2)}</strong><br>
                                <small style="color: #7f8c8d;">${summary.count} transactions</small>
                            </div>
                        </div>
                    </div>`;
            });
            
            summaryHtml += '</div>';
            container.innerHTML = summaryHtml;
        }

        // Display transactions table
        function displayTransactionsTable(transactions, pagination, previousBalance, currentMonth) {
            const container = document.getElementById('transactionsTableContainer');
            const paginationContainer = document.getElementById('paginationContainer');
            
            let tableHtml = `
                <h4 style="color: #2c3e50; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-table"></i> Transaction Details
                    ${currentMonth ? ` - ${new Date(currentMonth + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'})}` : ''}
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <thead>
                        <tr style="background: #2c3e50; color: white;">
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Date</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Type</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Description</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Payment Method</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Amount</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600;">Balance</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Reference</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Created By</th>
                        </tr>
                    </thead>
                    <tbody>`;
            
            // Always add previous balance row (whether we have transactions or not)
            const prevBalanceColor = previousBalance >= 0 ? '#27ae60' : '#e74c3c';
            const monthName = currentMonth ? new Date(currentMonth + '-01').toLocaleDateString('en-US', {year: 'numeric', month: 'long'}) : 'Period';
            tableHtml += `
                <tr style="background: #e9ecef; border-bottom: 2px solid #dee2e6; font-weight: 600;">
                    <td style="padding: 12px; font-size: 0.9rem;">${monthName} Opening</td>
                    <td style="padding: 10px;">
                        <span style="background: #6c757d; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                            BALANCE
                        </span>
                    </td>
                    <td style="padding: 10px; font-size: 0.9rem; font-style: italic;">Previous month closing balance</td>
                    <td style="padding: 10px; font-size: 0.9rem;">SYSTEM</td>
                    <td style="padding: 10px; text-align: right; font-weight: 600; font-size: 0.9rem; color: ${prevBalanceColor}">
                        £${Math.abs(previousBalance).toFixed(2)}${previousBalance < 0 ? ' DR' : ''}
                    </td>
                    <td style="padding: 10px; text-align: right; font-weight: 600; font-size: 0.9rem; color: ${prevBalanceColor}">
                        £${Math.abs(previousBalance).toFixed(2)}${previousBalance < 0 ? ' DR' : ''}
                    </td>
                    <td style="padding: 10px; font-size: 0.9rem;">-</td>
                    <td style="padding: 10px; font-size: 0.9rem;">System</td>
                </tr>`;
            
            if (!transactions.length) {
                tableHtml += `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-info-circle" style="font-size: 2rem;"></i>
                            <p style="margin-top: 15px;">No transactions found for this period.</p>
                        </td>
                    </tr>`;
            } else {
                let runningBalance = previousBalance;
                
                transactions.forEach((transaction, index) => {
                    // Calculate running balance
                    if (transaction.type === 'income') {
                        runningBalance += parseFloat(transaction.amount);
                    } else {
                        runningBalance -= parseFloat(transaction.amount);
                    }
                    
                    const rowColor = index % 2 === 0 ? '#f8f9fa' : 'white';
                    const balanceColor = runningBalance >= 0 ? '#27ae60' : '#e74c3c';
                    
                    tableHtml += `
                        <tr style="background: ${rowColor}; border-bottom: 1px solid #dee2e6;">
                            <td style="padding: 10px; font-size: 0.9rem;">${transaction.formatted_date}</td>
                            <td style="padding: 10px;">
                                <span style="background: ${transaction.type === 'income' ? '#d4edda' : '#f8d7da'}; 
                                             color: ${transaction.type === 'income' ? '#155724' : '#721c24'}; 
                                             padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                                    ${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type)))}
                                </span>
                            </td>
                            <td style="padding: 10px; font-size: 0.9rem; max-width: 200px; word-wrap: break-word;">${transaction.description || 'N/A'}</td>
                            <td style="padding: 10px; font-size: 0.9rem;">${transaction.payment_method.toUpperCase()}</td>
                            <td style="padding: 10px; text-align: right; font-weight: 600; font-size: 0.9rem;" class="${transaction.amount_class}">
                                ${transaction.formatted_amount}
                            </td>
                            <td style="padding: 10px; text-align: right; font-weight: 600; font-size: 0.9rem; color: ${balanceColor}">
                                £${Math.abs(runningBalance).toFixed(2)}${runningBalance < 0 ? ' DR' : ''}
                            </td>
                            <td style="padding: 10px; font-size: 0.9rem;">${transaction.reference_no || 'N/A'}</td>
                            <td style="padding: 10px; font-size: 0.9rem;">${transaction.created_by || 'System'}</td>
                        </tr>`;
                });
            }
            
            tableHtml += '</tbody></table>';
            container.innerHTML = tableHtml;
            
            // Display pagination
            if (pagination.total_pages > 1) {
                let paginationHtml = `
                    <div style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 20px;">
                        <span style="color: #7f8c8d; font-size: 0.9rem;">
                            Page ${pagination.current_page} of ${pagination.total_pages} 
                            (${pagination.total_records} total records)
                        </span>`;
                
                if (pagination.current_page > 1) {
                    paginationHtml += `
                        <button onclick="changePage(${pagination.current_page - 1})" class="btn btn-sm btn-secondary">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>`;
                }
                
                if (pagination.current_page < pagination.total_pages) {
                    paginationHtml += `
                        <button onclick="changePage(${pagination.current_page + 1})" class="btn btn-sm btn-secondary">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>`;
                }
                
                paginationHtml += '</div>';
                paginationContainer.innerHTML = paginationHtml;
            } else {
                paginationContainer.innerHTML = '';
            }
        }

        // Change page function
        function changePage(page) {
            window.currentTransactionData.currentPage = page;
            loadTransactionData();
        }
        
        // Export transaction data
        function exportTransactionData(residentId, residentName, homeId) {
            const params = new URLSearchParams({
                action: 'export_resident_transactions',
                resident_id: residentId,
                home_id: homeId,
                month: window.currentTransactionData.selectedMonth || ''
            });
            
            const url = `residents.php?${params}`;
            const link = document.createElement('a');
            link.href = url;
            link.download = `transactions_${residentName.replace(/\s+/g, '_')}_${new Date().toISOString().slice(0,10)}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification('Export Started', 'Transaction data is being downloaded...', 'info', 3000);
        }
        
        // Navigate between months
        function navigateMonth(direction) {
            const currentMonth = window.currentTransactionData.selectedMonth;
            if (!currentMonth) {
                // If no month selected, start from current month
                const now = new Date();
                window.currentTransactionData.selectedMonth = now.toISOString().slice(0, 7);
            }
            
            const date = new Date(window.currentTransactionData.selectedMonth + '-01');
            date.setMonth(date.getMonth() + direction);
            
            const newMonth = date.toISOString().slice(0, 7);
            window.currentTransactionData.selectedMonth = newMonth;
            window.currentTransactionData.currentPage = 1;
            
            // Update month filter dropdown
            const monthFilter = document.getElementById('monthFilter');
            monthFilter.value = newMonth;
            
            // Load new month data
            loadTransactionData();
        }

        // Generate report function
        function generateResidentReport() {
            <?php if (!$selected_home_id): ?>
                showNotification(
                    'No Home Selected', 
                    'Please select a care home to generate a report.', 
                    'warning'
                );
                return;
            <?php endif; ?>
            
            window.location.href = 'residents.php?home_id=<?php echo $selected_home_id; ?>&generate_report=true';
        }

        // DOM elements
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

        // Reset form function
        function resetForm() {
            document.getElementById('residentForm').reset();
        }

        // Setup Room Filter Dropdown
        function setupRoomFilter() {
            const input = document.getElementById('roomFilterInput');
            const dropdown = document.getElementById('roomDropdown');
            const options = dropdown.querySelectorAll('.room-option');
            
            // Show dropdown when input is focused
            input.addEventListener('focus', () => {
                dropdown.classList.add('show');
                filterRoomOptions();
            });
            
            // Filter options when typing
            input.addEventListener('input', () => {
                filterRoomOptions();
                dropdown.classList.add('show');
            });
            
            // Handle option selection
            options.forEach(option => {
                option.addEventListener('click', () => {
                    const roomValue = option.getAttribute('data-room');
                    const displayText = roomValue === '' ? '' : roomValue;
                    
                    input.value = displayText;
                    dropdown.classList.remove('show');
                    
                    // Update form and submit
                    setTimeout(() => {
                        input.closest('form').submit();
                    }, 100);
                });
            });
            
            // Hide dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.remove('show');
                }
            });
            
            // Filter room options based on input
            function filterRoomOptions() {
                const searchTerm = input.value.toLowerCase().trim();
                let hasVisibleOptions = false;
                
                options.forEach(option => {
                    const roomText = option.textContent.toLowerCase();
                    const roomValue = option.getAttribute('data-room').toLowerCase();
                    
                    if (searchTerm === '' || 
                        roomText.includes(searchTerm) || 
                        roomValue.includes(searchTerm) ||
                        option.getAttribute('data-room') === '') {
                        option.classList.remove('hidden');
                        hasVisibleOptions = true;
                    } else {
                        option.classList.add('hidden');
                    }
                });
                
                // Show dropdown only if there are visible options
                if (hasVisibleOptions) {
                    dropdown.classList.add('show');
                } else {
                    dropdown.classList.remove('show');
                }
            }
        }

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            setupMobileMenu(); // NEW: Initialize mobile menu
            setupRoomFilter(); // Initialize room filter dropdown
            
            // If we're editing a resident, switch to the add/edit tab
            <?php if ($edit_resident): ?>
                document.querySelector('[data-target="add-resident"]').click();
            <?php endif; ?>

            // Show report if generated
            <?php if ($report_data): ?>
                showResidentReportModal();
            <?php endif; ?>

            // Add event listeners
            document.getElementById('btnGenerateReport').addEventListener('click', generateResidentReport);
            document.getElementById('btnTechnicalContact').addEventListener('click', () => {
                window.open('https://api.whatsapp.com/send?phone=94769988123&text=Hello%20WEBbuilders.lk%20%F0%9F%91%8B%2C', '_blank');
            });
        });

        // Show resident report modal
        function showResidentReportModal() {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'modal-content';
            modal.style.maxWidth = '95%';
            
            const reportData = <?php echo json_encode($report_data); ?>;
            
            // Room details table
            let roomDetailsRows = '';
            if (reportData.room_details && reportData.room_details.length > 0) {
                roomDetailsRows = reportData.room_details.map(room => `
                    <tr>
                        <td>Room ${room.room_number}</td>
                        <td>${room.occupant_count}</td>
                        <td style="max-width: 200px; word-wrap: break-word;">${room.residents}</td>
                    </tr>
                `).join('');
            } else {
                roomDetailsRows = '<tr><td colspan="3" style="text-align:center;">No room data available</td></tr>';
            }
            
            modal.innerHTML = `
                <div class="modal-header">
                    <h3><i class="fas fa-file-alt"></i> Resident Summary Report - ${reportData.home_name}</h3>
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
                            <h2 class="report-title">Resident Summary Report - ${reportData.home_name}</h2>
                            <div class="report-date">Generated on: ${new Date(reportData.report_date).toLocaleDateString()} at ${new Date(reportData.report_date).toLocaleTimeString()}</div>
                        </div>
                        
                        <div class="report-summary">
                            <div class="summary-item">
                                <div class="summary-value">${reportData.total_residents}</div>
                                <div class="summary-label">Total Residents</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${reportData.total_rooms}</div>
                                <div class="summary-label">Occupied Rooms</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${reportData.avg_age}</div>
                                <div class="summary-label">Average Age</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${reportData.medical_residents}</div>
                                <div class="summary-label">With Medical Conditions</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${reportData.medication_residents}</div>
                                <div class="summary-label">On Medications</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${reportData.recent_admissions}</div>
                                <div class="summary-label">Recent Admissions (30 days)</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-value">${reportData.emergency_contacts}</div>
                                <div class="summary-label">With Emergency Contacts</div>
                            </div>
                        </div>
                        
                        <div style="margin-top:30px;">
                            <h3 style="color:#2c3e50;margin-bottom:15px;"><i class="fas fa-bed"></i> Room Occupancy Details</h3>
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Room Number</th>
                                        <th>Occupants</th>
                                        <th>Resident Names</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${roomDetailsRows}
                                </tbody>
                            </table>
                        </div>
                        
                        <div style="margin-top:30px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                            <p style="margin: 0; color: #7f8c8d; font-size: 0.9rem;">
                                <i class="fas fa-info-circle"></i> 
                                This report provides a summary of resident occupancy, health information, and room assignments.
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
                            <title>Resident Summary Report - ${reportData.home_name}</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                .report-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #2c3e50; padding-bottom: 15px; }
                                .report-title { color: #2c3e50; margin: 0 0 10px 0; }
                                .report-date { color: #7f8c8d; font-size: 14px; }
                                .report-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                                .report-table th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
                                .report-table td { padding: 8px 10px; border-bottom: 1px solid #eee; word-wrap: break-word; }
                                .report-table tr:nth-child(even) { background: #f8f9fa; }
                                .report-summary { 
                                    margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; 
                                    display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 15px; 
                                }
                                .summary-item { text-align: center; padding: 10px; }
                                .summary-value { font-size: 1.3rem; font-weight: bold; color: #2c3e50; }
                                .summary-label { font-size: 0.8rem; color: #7f8c8d; }
                                h3 { color: #2c3e50; margin-bottom: 15px; }
                                .fas { display: none; }
                                @media print {
                                    body { margin: 0; }
                                    .report-table { font-size: 11px; }
                                    .report-summary { grid-template-columns: repeat(3, 1fr); }
                                    h3 { page-break-before: avoid; }
                                    table { page-break-inside: avoid; }
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
                // Remove the report parameter from URL
                window.history.replaceState({}, document.title, window.location.pathname + '?home_id=<?php echo $selected_home_id; ?>');
            });
            
            overlay.addEventListener('click', (e) => {
                if(e.target === overlay) {
                    document.body.removeChild(overlay);
                    window.history.replaceState({}, document.title, window.location.pathname + '?home_id=<?php echo $selected_home_id; ?>');
                }
            });
        }

        // View Resident Details Modal
        function viewResidentDetails(resident) {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'modal-content';
            modal.style.maxWidth = '800px';
            
            // Calculate age
            const age = resident.date_of_birth ? new Date().getFullYear() - new Date(resident.date_of_birth).getFullYear() : 'N/A';
            
            modal.innerHTML = `
                <div class="modal-header">
                    <h3><i class="fas fa-user"></i> Resident Details - ${resident.first_name} ${resident.last_name}</h3>
                    <button id="closeResidentModal" class="btn btn-danger" style="padding:6px 10px;">Close</button>
                </div>
                <div class="modal-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div class="details-section">
                            <h4 style="color: #2c3e50; margin-bottom: 15px; border-bottom: 2px solid #3498db; padding-bottom: 5px;">
                                <i class="fas fa-user"></i> Personal Information
                            </h4>
                            <div class="detail-item">
                                <strong>Full Name:</strong> ${resident.first_name} ${resident.last_name}
                            </div>
                            <div class="detail-item">
                                <strong>Date of Birth:</strong> ${new Date(resident.date_of_birth).toLocaleDateString()}
                            </div>
                            <div class="detail-item">
                                <strong>Age:</strong> ${age} years
                            </div>
                            <div class="detail-item">
                                <strong>Gender:</strong> ${resident.gender.charAt(0).toUpperCase() + resident.gender.slice(1)}
                            </div>
                            <div class="detail-item">
                                <strong>NHS Number:</strong> ${resident.nhs_number || 'N/A'}
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 style="color: #2c3e50; margin-bottom: 15px; border-bottom: 2px solid #e74c3c; padding-bottom: 5px;">
                                <i class="fas fa-home"></i> Residence Information
                            </h4>
                            <div class="detail-item">
                                <strong>Room Number:</strong> ${resident.room_number}
                            </div>
                            <div class="detail-item">
                                <strong>Admission Date:</strong> ${new Date(resident.admission_date).toLocaleDateString()}
                            </div>
                            <div class="detail-item">
                                <strong>Address:</strong> ${resident.address || 'N/A'}
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 style="color: #2c3e50; margin-bottom: 15px; border-bottom: 2px solid #f39c12; padding-bottom: 5px;">
                                <i class="fas fa-phone"></i> Contact Information
                            </h4>
                            <div class="detail-item">
                                <strong>Phone:</strong> ${resident.phone || 'N/A'}
                            </div>
                            <div class="detail-item">
                                <strong>NOK Name:</strong> ${resident.nok_name || 'N/A'}
                            </div>
                            <div class="detail-item">
                                <strong>NOK Relationship:</strong> ${resident.nok_relationship || 'N/A'}
                            </div>
                            <div class="detail-item">
                                <strong>NOK Email:</strong> ${resident.nok_email || 'N/A'}
                            </div>
                            <div class="detail-item">
                                <strong>NOK Phone Number:</strong> ${resident.nok_number || 'N/A'}
                            </div>
                        </div>
                        
                        <div class="details-section">
                            <h4 style="color: #2c3e50; margin-bottom: 15px; border-bottom: 2px solid #27ae60; padding-bottom: 5px;">
                                <i class="fas fa-pound-sign"></i> Financial Information
                            </h4>
                            <div class="detail-item">
                                <strong>Net Bank:</strong> 
                                <span style="color: ${resident.net_bank >= 0 ? '#27ae60' : '#e74c3c'};">
                                    £${resident.formatted_bank || '0.00'}
                                </span>
                            </div>
                            <div class="detail-item">
                                <strong>Net Cash:</strong> 
                                <span style="color: ${resident.net_cash >= 0 ? '#27ae60' : '#e74c3c'};">
                                    £${resident.formatted_cash || '0.00'}
                                </span>
                            </div>
                            <div class="detail-item">
                                <strong>Net Amount:</strong> 
                                <span style="color: ${resident.net_amount >= 0 ? '#27ae60' : '#e74c3c'}; font-weight: bold;">
                                    £${resident.formatted_amount || '0.00'}
                                </span>
                            </div>
                        </div>
                        
                        <div class="details-section" style="grid-column: 1 / -1;">
                            <h4 style="color: #2c3e50; margin-bottom: 15px; border-bottom: 2px solid #9b59b6; padding-bottom: 5px;">
                                <i class="fas fa-notes-medical"></i> Medical Information
                            </h4>
                            <div class="detail-item">
                                <strong>Medical Conditions:</strong><br>
                                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 5px;">
                                    ${resident.medical_conditions || 'No medical conditions recorded'}
                                </div>
                            </div>
                            <div class="detail-item" style="margin-top: 15px;">
                                <strong>Medications:</strong><br>
                                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 5px;">
                                    ${resident.medications || 'No medications recorded'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add styles for the detail items
            const style = document.createElement('style');
            style.textContent = `
                .details-section {
                    background: #fff;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .detail-item {
                    margin-bottom: 12px;
                    padding: 8px 0;
                    border-bottom: 1px solid #eee;
                }
                .detail-item:last-child {
                    border-bottom: none;
                }
                .detail-item strong {
                    color: #2c3e50;
                    display: inline-block;
                    min-width: 140px;
                }
            `;
            document.head.appendChild(style);
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Close modal
            document.getElementById('closeResidentModal').addEventListener('click', () => {
                document.body.removeChild(overlay);
                document.head.removeChild(style);
            });
            
            overlay.addEventListener('click', (e) => {
                if(e.target === overlay) {
                    document.body.removeChild(overlay);
                    document.head.removeChild(style);
                }
            });
        }
    </script>
</body>
</html>