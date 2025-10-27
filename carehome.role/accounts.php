<?php
session_start();

date_default_timezone_set('Asia/Kolkata'); 

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Get today's date in YYYY-MM-DD format
$today = date('Y-m-d');

// Save uploaded proof file into shared folder uploads/transactions/{transaction_id}
function save_proof_file_staff($transactionId) {
    if (!isset($_FILES['proof']) || empty($_FILES['proof']['name'])) {
        return null;
    }
    $file = $_FILES['proof'];
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
    $detected = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
    $mime = $detected ?: ($file['type'] ?? '');
    if (!in_array($mime, $allowed)) {
        return null;
    }
    $root = dirname(__DIR__); // C:\xampp\htdocs\carehome
    $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'transactions' . DIRECTORY_SEPARATOR . intval($transactionId);
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'proof.' . ($safeExt ?: 'dat');
    if (@move_uploaded_file($file['tmp_name'], $targetPath)) {
        // return relative path
        return str_replace($root . DIRECTORY_SEPARATOR, '', $targetPath);
    }
    return null;
}

// Check if user is logged in and has staff role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../index.php");
    exit();
}

// Get carehome information for logged-in user (mysqlnd-free version)
function get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $username) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false; // connection failed
    }

    $mysqli->set_charset('utf8mb4');

    $sql = "SELECT id, name, bank, cash, balance FROM homes WHERE user_name = ? LIMIT 1";
    $carehome = null;

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $username);

        if ($stmt->execute()) {
            // Bind result variables (order must match SELECT)
            $stmt->bind_result($id, $name, $bank, $cash, $balance);

            // Fetch single row
            if ($stmt->fetch()) {
                $carehome = [
                    'id'      => (int) $id,
                    'name'    => $name,
                    'bank'    => $bank,
                    'cash'    => (float) $cash,
                    'balance' => (float) $balance
                ];
            } else {
                // no row found -> $carehome remains null
                $carehome = null;
            }
        } else {
            // execute failed
            $carehome = null;
        }

        $stmt->close();
    } else {
        // prepare failed
        $carehome = null;
    }

    $mysqli->close();
    return $carehome;
}

// Get residents for specific carehome (mysqlnd-free version)
function get_residents($dbHost, $dbUser, $dbPass, $dbName, $home_id) {
    $home_id = (int) $home_id;

    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return [];
    }

    $mysqli->set_charset('utf8mb4');

    $residents = [];
    $sql = "SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ? ORDER BY first_name, last_name";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $home_id);

        if ($stmt->execute()) {
            // Bind result columns (order must match SELECT)
            $stmt->bind_result($id, $first_name, $last_name, $room_number);

            // Fetch rows into array
            while ($stmt->fetch()) {
                $residents[] = [
                    'id' => (int) $id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'room_number' => $room_number
                ];
            }
        }

        $stmt->close();
    }

    $mysqli->close();

    return $residents;
}

// Get only active residents (excluding deactivated ones)
function get_active_residents($dbHost, $dbUser, $dbPass, $dbName, $home_id) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return [];
    }

    $mysqli->set_charset('utf8mb4');

    $residents = [];
    $sql = "SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ? AND (status IS NULL OR status != 'deactivated') ORDER BY first_name, last_name";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $home_id);

        if ($stmt->execute()) {
            // Bind result columns (order must match SELECT)
            $stmt->bind_result($id, $first_name, $last_name, $room_number);

            // Fetch rows into array
            while ($stmt->fetch()) {
                $residents[] = [
                    'id' => (int) $id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'room_number' => $room_number
                ];
            }
        }

        $stmt->close();
    }

    $mysqli->close();

    return $residents;
}

// Add transaction
function add_transaction($dbHost, $dbUser, $dbPass, $dbName, $transaction_data) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false;
    }
    
    $stmt = $mysqli->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("iisdsssss",
        $transaction_data['home_id'],
        $transaction_data['resident_id'],
        $transaction_data['type'],
        $transaction_data['amount'],
        $transaction_data['payment_method'],
        $transaction_data['description'],
        $transaction_data['reference_no'],
        $transaction_data['transaction_date'],
        $transaction_data['created_by']
    );
    
    $success = $stmt->execute();
    $inserted_id = $stmt->insert_id;
    
    $stmt->close();
    $mysqli->close();
    
    return $success ? $inserted_id : false;
}

// Get transactions for specific carehome with filters (FIXED VERSION)
function get_transactions($dbHost, $dbUser, $dbPass, $dbName, $home_id, $filters = []) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return [];
    }

    $mysqli->set_charset('utf8mb4');

    // Build the base query
    $query = "SELECT 
                t.id, 
                t.home_id, 
                t.resident_id, 
                t.amount,
                t.type,
                t.payment_method,
                t.description, 
                t.created_at, 
                t.created_by, 
                COALESCE(t.transaction_date, DATE(t.created_at)) as transaction_date, 
                t.reference_no,
                t.proof_path,
                r.first_name, 
                r.last_name, 
                r.room_number
            FROM transactions t
            LEFT JOIN residents r ON t.resident_id = r.id
            WHERE t.home_id = ?";
    
    $params = ["i", $home_id];
    
    // Add date filter if provided
    if (!empty($filters['date'])) {
        $query .= " AND (DATE(t.transaction_date) = ? OR (t.transaction_date IS NULL AND DATE(t.created_at) = ?))";
        $params[0] .= "ss";
        $params[] = $filters['date'];
        $params[] = $filters['date'];
    }

    // Add type filter if provided
    if (!empty($filters['type'])) {
        $query .= " AND t.type = ?";
        $params[0] .= "s";
        $params[] = $filters['type'];
    }

    // Add resident filter if provided
    if (!empty($filters['resident_id'])) {
        $query .= " AND t.resident_id = ?";
        $params[0] .= "i";
        $params[] = $filters['resident_id'];
    }

    // Order by transaction date/created date
    $query .= " ORDER BY COALESCE(t.transaction_date, t.created_at) DESC, t.id DESC";

    $stmt = $mysqli->prepare($query);

    if (count($params) > 1) {
        $stmt->bind_param(...$params);
    }

    $stmt->execute();

    // Bind results manually
    $stmt->bind_result(
        $id, $home_id, $resident_id, $amount, $type, $payment_method, 
        $description, $created_at, $created_by, $transaction_date, 
        $reference_no, $proof_path, $first_name, $last_name, $room_number
    );

    $transactions = [];
    while ($stmt->fetch()) {
        $transactions[] = [
            'id' => $id,
            'home_id' => $home_id,
            'resident_id' => $resident_id,
            'amount' => floatval($amount),
            'type' => $type,
            'payment_method' => $payment_method,
            'description' => $description,
            'created_at' => $created_at,
            'created_by' => $created_by,
            'transaction_date' => $transaction_date,
            'reference_no' => $reference_no,
            'proof_path' => $proof_path,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'room_number' => $room_number
        ];
    }

    $stmt->close();
    $mysqli->close();

    return $transactions;
}

// Get financial summary for specific date (without get_result)
function get_daily_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id, $date) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return null;
    }

    $stmt = $mysqli->prepare("SELECT id, home_id, summary_date, total_income, total_expense, net_balance FROM daily_summary WHERE home_id = ? AND summary_date = ?");
    $stmt->bind_param("is", $home_id, $date);
    $stmt->execute();
    $stmt->bind_result($id, $db_home_id, $summary_date, $total_income, $total_expense, $net_balance);

    $summary = null;
    if ($stmt->fetch()) {
        $summary = [
            'id' => $id,
            'home_id' => $db_home_id,
            'summary_date' => $summary_date,
            'total_income' => floatval($total_income),
            'total_expense' => floatval($total_expense),
            'net_balance' => floatval($net_balance)
        ];
    }

    $stmt->close();
    $mysqli->close();

    return $summary;
}

// Get resident-wise financial summary (without get_result)
function get_resident_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id, $resident_id = null) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false;
    }

    $query = "SELECT 
                r.id,
                r.first_name,
                r.last_name,
                r.room_number,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as bank_income,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as cash_income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as bank_expenses,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as cash_expenses,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as total_expenses,
                COALESCE(SUM(CASE WHEN t.type = 'drop' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as bank_drops,
                COALESCE(SUM(CASE WHEN t.type = 'drop' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as cash_drops,
                COALESCE(SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END), 0) as total_drops
              FROM residents r
              LEFT JOIN transactions t ON r.id = t.resident_id
              WHERE r.home_id = ?";

    $types = "i";
    $values = [$home_id];

    if ($resident_id) {
        $query .= " AND r.id = ?";
        $types .= "i";
        $values[] = $resident_id;
    }

    $query .= " GROUP BY r.id, r.first_name, r.last_name, r.room_number
                ORDER BY r.first_name, r.last_name";

    $residents = [];

    if ($stmt = $mysqli->prepare($query)) {
        if (!empty($values)) {
            $stmt->bind_param($types, ...$values);
        }

        if ($stmt->execute()) {
            $stmt->bind_result(
                $id,
                $first_name,
                $last_name,
                $room_number,
                $bank_income,
                $cash_income,
                $bank_expenses,
                $cash_expenses,
                $total_expenses,
                $bank_drops,
                $cash_drops,
                $total_drops
            );

            while ($stmt->fetch()) {
                $total_income = $bank_income + $cash_income;
                $total_costs = $total_expenses + $total_drops;
                $pending_balance = $total_income - $total_costs;
                
                // Calculate current balances by payment method
                $current_bank_balance = $bank_income - $bank_expenses - $bank_drops;
                $current_cash_balance = $cash_income - $cash_expenses - $cash_drops;

                $residents[] = [
                    'id' => (int) $id,
                    'name' => $first_name . ' ' . $last_name,
                    'room_number' => $room_number,
                    'bank_income' => floatval($bank_income),
                    'cash_income' => floatval($cash_income),
                    'total_income' => floatval($total_income),
                    'bank_expenses' => floatval($bank_expenses),
                    'cash_expenses' => floatval($cash_expenses),
                    'total_expenses' => floatval($total_expenses),
                    'bank_drops' => floatval($bank_drops),
                    'cash_drops' => floatval($cash_drops),
                    'total_drops' => floatval($total_drops),
                    'total_costs' => floatval($total_costs),
                    'current_bank_balance' => floatval($current_bank_balance),
                    'current_cash_balance' => floatval($current_cash_balance),
                    'pending_balance' => floatval($pending_balance)
                ];
            }
        }

        $stmt->close();
    }

    $mysqli->close();

    return $residents;
}

// Get carehome financial summary (without get_result)
function get_carehome_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        return false;
    }

    $query = "SELECT 
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as total_bank_income,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as total_cash_income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as total_expenses,
                COALESCE(SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END), 0) as total_drops,
                h.bank as current_bank_balance,
                h.cash as current_cash_balance
              FROM homes h
              LEFT JOIN transactions t ON h.id = t.home_id
              WHERE h.id = ?
              GROUP BY h.bank, h.cash";

    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        $mysqli->close();
        return false;
    }

    $stmt->bind_param("i", $home_id);
    $stmt->execute();

    // Bind results manually
    $stmt->bind_result(
        $total_bank_income,
        $total_cash_income,
        $total_expenses,
        $total_drops,
        $current_bank_balance,
        $current_cash_balance
    );

    $summary = null;
    if ($stmt->fetch()) {
        $total_income = $total_bank_income + $total_cash_income;
        $total_costs = $total_expenses + $total_drops;
        $net_balance = $total_income - $total_costs;

        $summary = [
            'total_bank_income' => floatval($total_bank_income),
            'total_cash_income' => floatval($total_cash_income),
            'total_income' => floatval($total_income),
            'total_expenses' => floatval($total_expenses),
            'total_drops' => floatval($total_drops),
            'total_costs' => floatval($total_costs),
            'current_bank_balance' => floatval($current_bank_balance),
            'current_cash_balance' => floatval($current_cash_balance),
            'net_balance' => floatval($net_balance)
        ];
    }

    $stmt->close();
    $mysqli->close();

    return $summary;
}

// Handle AJAX request for filtered transactions
if (isset($_GET['action']) && $_GET['action'] === 'filter_transactions') {
    header('Content-Type: application/json');
    
    // Get carehome info first
    $carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $_SESSION['username']);
    
    if (!$carehome_info) {
        echo json_encode(['success' => false, 'error' => 'Care home not found']);
        exit();
    }
    
    $home_id = $carehome_info['id'];
    
    try {
        // Create PDO connection for filtering
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $start_date = $_GET['start_date'] ?? date('Y-m-d');
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
        $type_filter = $_GET['type'] ?? '';
        $resident_filter = $_GET['resident_id'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = max(1, intval($_GET['per_page'] ?? 10));
        
        // Build query conditions
        $where_conditions = ['t.home_id = ?'];
        $params = [$home_id];
        
        // Date range filter - use transaction_date if set, otherwise fall back to created_at
        $where_conditions[] = "((t.transaction_date IS NOT NULL AND DATE(t.transaction_date) BETWEEN ? AND ?) OR (t.transaction_date IS NULL AND DATE(t.created_at) BETWEEN ? AND ?))";
        $params[] = $start_date;
        $params[] = $end_date;
        $params[] = $start_date;
        $params[] = $end_date;
        
        // Type filter
        if (!empty($type_filter)) {
            $where_conditions[] = 't.type = ?';
            $params[] = $type_filter;
        }
        
        // Resident filter
        if (!empty($resident_filter)) {
            $where_conditions[] = 't.resident_id = ?';
            $params[] = $resident_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $count_stmt = $pdo->prepare("SELECT COUNT(*) as total 
                                    FROM transactions t 
                                    LEFT JOIN residents r ON t.resident_id = r.id 
                                    WHERE $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_records / $per_page);
        
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        // Calculate summary from all matching records (not just current page)
        $summary_stmt = $pdo->prepare("SELECT 
                                      SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) as total_income,
                                      SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) as total_expense,
                                      SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END) as total_drop
                                      FROM transactions t 
                                      LEFT JOIN residents r ON t.resident_id = r.id 
                                      WHERE $where_clause");
        $summary_stmt->execute($params);
        $summary_result = $summary_stmt->fetch(PDO::FETCH_ASSOC);
        
        $summary = [
            'total_income' => floatval($summary_result['total_income'] ?? 0),
            'total_expense' => floatval($summary_result['total_expense'] ?? 0),
            'total_drop' => floatval($summary_result['total_drop'] ?? 0)
        ];
        
        // Get filtered transactions with pagination
        $stmt = $pdo->prepare("SELECT t.*, r.first_name, r.last_name, r.room_number 
                              FROM transactions t 
                              LEFT JOIN residents r ON t.resident_id = r.id 
                              WHERE $where_clause 
                              ORDER BY COALESCE(t.transaction_date, t.created_at) DESC, t.id DESC
                              LIMIT $per_page OFFSET $offset");
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions,
            'summary' => $summary,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_records' => $total_records,
                'per_page' => $per_page
            ]
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for pending amount
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_amount') {
    header('Content-Type: application/json');
    
    $carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $_SESSION['username']);
    
    if ($carehome_info) {
        $home_id = $carehome_info['id'];
        $mode = $_GET['mode'] ?? 'carehome';
        $resident_id = $_GET['resident_id'] ?? null;
        
        if ($mode === 'resident' && $resident_id) {
            // Get specific resident's financial summary
            $resident_summary = get_resident_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id, $resident_id);
            echo json_encode(['success' => true, 'data' => $resident_summary[0] ?? null, 'mode' => 'resident']);
        } else {
            // Get carehome summary
            $carehome_summary = get_carehome_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id);
            echo json_encode(['success' => true, 'data' => $carehome_summary, 'mode' => 'carehome']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Care home not found']);
    }
    exit();
}

// Handle balance check for expense validation
if (isset($_GET['action']) && $_GET['action'] === 'check_balance') {
    header('Content-Type: application/json');
    
    $carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $_SESSION['username']);
    
    if ($carehome_info) {
        $home_id = $carehome_info['id'];
        $resident_id = $_POST['resident_id'] ?? '';
        
        if (!empty($resident_id)) {
            // Get resident-specific balance
            $resident_summary = get_resident_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id, $resident_id);
            $resident_data = $resident_summary[0] ?? null;
            if ($resident_data) {
                echo json_encode([
                    'success' => true, 
                    'cash_balance' => $resident_data['current_cash_balance'] ?? 0,
                    'bank_balance' => $resident_data['current_bank_balance'] ?? 0
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Resident not found']);
            }
        } else {
            // Get carehome general balance
            $carehome_summary = get_carehome_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id);
            echo json_encode([
                'success' => true, 
                'cash_balance' => $carehome_summary['current_cash_balance'] ?? 0,
                'bank_balance' => $carehome_summary['current_bank_balance'] ?? 0
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Care home not found']);
    }
    exit();
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $_SESSION['username']);
    
    if ($carehome_info) {
        $home_id = $carehome_info['id'];
        $action = $_POST['action'] ?? '';
        
        if ($action === 'transfer') {
            // Transfer handling
            try {
                if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
                    throw new Exception("Invalid amount specified");
                }

                if (empty($_POST['description'])) {
                    throw new Exception("Description is required");
                }

                $method = $_POST['transfer_method'] ?? '';
                if (!in_array($method, ['bank_to_cash', 'cash_to_bank'])) {
                    throw new Exception('Invalid transfer method');
                }

                // Get transaction date
                $tx_date = null;
                if (!empty($_POST['transaction_date'])) {
                    $d = trim($_POST['transaction_date']);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        $tx_date = $d;
                    }
                }

                $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->beginTransaction();

                try {
                    $amount = floatval($_POST['amount']);
                    $residentId = !empty($_POST['resident_id']) ? $_POST['resident_id'] : null;
                    $description = trim($_POST['description']);
                    $reference = trim($_POST['reference_no'] ?? '');
                    $username = $_SESSION['username'] ?? $_SESSION['user'];

                    if ($method === 'cash_to_bank') {
                        // First insert cash expense
                        $stmtOut = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'expense', ?, 'cash', ?, ?, ?, ?)");
                        $successOut = $stmtOut->execute([$home_id, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successOut) throw new Exception('Failed to record cash expense');

                        // Then insert bank income
                        $stmtIn = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'income', ?, 'bank', ?, ?, ?, ?)");
                        $successIn = $stmtIn->execute([$home_id, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successIn) throw new Exception('Failed to record bank income');
                    } else {
                        // First insert bank expense
                        $stmtOut = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'expense', ?, 'bank', ?, ?, ?, ?)");
                        $successOut = $stmtOut->execute([$home_id, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successOut) throw new Exception('Failed to record bank expense');

                        // Then insert cash income
                        $stmtIn = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'income', ?, 'cash', ?, ?, ?, ?)");
                        $successIn = $stmtIn->execute([$home_id, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successIn) throw new Exception('Failed to record cash income');
                    }

                    $pdo->commit();
                    $_SESSION['message'] = 'Transfer processed successfully';
                    $_SESSION['message_type'] = 'success';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();

                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw new Exception('Transfer failed: ' . $e->getMessage());
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
                $message_type = 'error';
            }
        } elseif ($action === 'add_income') {
            // Get transaction date
            $tx_date = null;
            if (!empty($_POST['transaction_date'])) {
                $d = trim($_POST['transaction_date']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $tx_date = $d;
                }
            }
            
            $transaction_data = [
                'home_id' => $home_id,
                'resident_id' => !empty($_POST['resident_id']) ? $_POST['resident_id'] : null,
                'type' => 'income',
                'amount' => floatval($_POST['amount']),
                'payment_method' => $_POST['payment_method'],
                'description' => trim($_POST['description']),
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'transaction_date' => $tx_date,
                'created_by' => $_SESSION['username']
            ];
            
            $result = add_transaction($dbHost, $dbUser, $dbPass, $dbName, $transaction_data);
            if ($result) {
                $rel = save_proof_file_staff($result);
                if ($rel) {
                    // update transactions.proof_path
                    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                    if (!$mysqli->connect_errno) {
                        $upd = $mysqli->prepare("UPDATE transactions SET proof_path = ? WHERE id = ?");
                        $upd->bind_param("si", $rel, $result);
                        $upd->execute();
                        $upd->close();
                        $mysqli->close();
                    }
                }
                $_SESSION['flash_message'] = "Income transaction added successfully!";
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $message = "Error adding income transaction.";
                $message_type = "error";
            }
        }
        elseif ($action === 'add_expense') {
            // Get transaction date
            $tx_date = null;
            if (!empty($_POST['transaction_date'])) {
                $d = trim($_POST['transaction_date']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $tx_date = $d;
                }
            }
            
            $transaction_data = [
                'home_id' => $home_id,
                'resident_id' => !empty($_POST['resident_id']) ? $_POST['resident_id'] : null,
                'type' => 'expense',
                'amount' => floatval($_POST['amount']),
                'payment_method' => $_POST['payment_method'],
                'description' => trim($_POST['description']),
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'transaction_date' => $tx_date,
                'created_by' => $_SESSION['username']
            ];
            
            $result = add_transaction($dbHost, $dbUser, $dbPass, $dbName, $transaction_data);
            if ($result) {
                $rel = save_proof_file_staff($result);
                if ($rel) {
                    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                    if (!$mysqli->connect_errno) {
                        $upd = $mysqli->prepare("UPDATE transactions SET proof_path = ? WHERE id = ?");
                        $upd->bind_param("si", $rel, $result);
                        $upd->execute();
                        $upd->close();
                        $mysqli->close();
                    }
                }
                $_SESSION['flash_message'] = "Expense transaction added successfully!";
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $message = "Error adding expense transaction.";
                $message_type = "error";
            }
        }
        elseif ($action === 'add_drop') {
            // Get transaction date
            $tx_date = null;
            if (!empty($_POST['transaction_date'])) {
                $d = trim($_POST['transaction_date']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $tx_date = $d;
                }
            }
            
            $transaction_data = [
                'home_id' => $home_id,
                'resident_id' => !empty($_POST['resident_id']) ? $_POST['resident_id'] : null,
                'type' => 'drop',
                'amount' => floatval($_POST['amount']),
                'payment_method' => $_POST['payment_method'],
                'description' => trim($_POST['description']),
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'transaction_date' => $tx_date,
                'created_by' => $_SESSION['username']
            ];
            
            $result = add_transaction($dbHost, $dbUser, $dbPass, $dbName, $transaction_data);
            if ($result) {
                $rel = save_proof_file_staff($result);
                if ($rel) {
                    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                    if (!$mysqli->connect_errno) {
                        $upd = $mysqli->prepare("UPDATE transactions SET proof_path = ? WHERE id = ?");
                        $upd->bind_param("si", $rel, $result);
                        $upd->execute();
                        $upd->close();
                        $mysqli->close();
                    }
                }
                $_SESSION['flash_message'] = "Amount drop processed successfully!";
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $message = "Error processing amount drop.";
                $message_type = "error";
            }
        }
    } else {
        $message = "Error: Unable to verify care home information.";
        $message_type = "error";
    }
}

// Get carehome information for display
$carehome_info = get_carehome_info($dbHost, $dbUser, $dbPass, $dbName, $_SESSION['username']);
$carehome_name = $carehome_info ? $carehome_info['name'] : 'CareHome';
$home_id = $carehome_info ? $carehome_info['id'] : null;

// Get residents for this carehome
$residents = $home_id ? get_residents($dbHost, $dbUser, $dbPass, $dbName, $home_id) : [];
$activeResidents = $home_id ? get_active_residents($dbHost, $dbUser, $dbPass, $dbName, $home_id) : [];

// Get today's transactions for summary - USING THE FIXED FUNCTION
$today = date('Y-m-d');
$today_transactions = $home_id ? get_transactions($dbHost, $dbUser, $dbPass, $dbName, $home_id, ['date' => $today]) : [];

// Calculate financial summary
$total_income = 0;
$total_expense = 0;
$total_drop = 0;

foreach ($today_transactions as $transaction) {
    if ($transaction['type'] === 'income') {
        $total_income += $transaction['amount'];
    } elseif ($transaction['type'] === 'expense') {
        $total_expense += $transaction['amount'];
    } elseif ($transaction['type'] === 'drop') {
        $total_drop += $transaction['amount'];
    }
}

$net_profit = $total_income - $total_expense - $total_drop;

// Get current bank and cash balances from homes table for display in summary cards
$current_bank_balance = 0;
$current_cash_balance = 0;

if ($home_id) {
    $carehome_summary = get_carehome_financial_summary($dbHost, $dbUser, $dbPass, $dbName, $home_id);
    if ($carehome_summary) {
        $current_bank_balance = $carehome_summary['current_bank_balance'];
        $current_cash_balance = $carehome_summary['current_cash_balance'];
    }
}

// For display in the table
$display_transactions = $today_transactions;
?>












































<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts - Care Home Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

/* Financial Summary Cards */
.financial-summary {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 15px;
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
}

.income .summary-icon {
    background: rgba(46, 204, 113, 0.2);
    color: #2ecc71;
}

.expense .summary-icon {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
}

.profit .summary-icon {
    background: rgba(52, 152, 219, 0.2);
    color: #3498db;
}

.summary-content h3 {
    font-size: 1rem;
    color: #7f8c8d;
    margin-bottom: 5px;
}

.summary-amount {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 5px;
}

.summary-card small {
    color: #95a5a6;
}

/* Message styles */
.message {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 5px;
    font-weight: 500;
}

.message.success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.message.error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
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
    min-width: 150px;
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

/* Form styles */
.account-form {
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
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 10px;
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

/* Transaction controls */
.transaction-controls {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.date-picker, .transaction-filters {
    display: flex;
    align-items: center;
    gap: 10px;
}

.date-picker label, .transaction-filters label {
    font-weight: 500;
    color: #2c3e50;
}

.date-picker input, .transaction-filters select {
    padding: 10px 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

/* Table styles */
.transactions-table-container {
    overflow-x: auto;
    border-radius: 5px;
    border: 1px solid #eee;
    margin-bottom: 20px;
}

.transactions-table {
    width: 100%;
    border-collapse: collapse;
}

.transactions-table th {
    background: #f8f9fa;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: #2c3e50;
    border-bottom: 1px solid #eee;
}

.transactions-table td {
    padding: 15px;
    border-bottom: 1px solid #eee;
}

.transactions-table tbody tr:hover {
    background-color: #f8f9fa;
}

.transaction-type {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
}

.transaction-type.income {
    background: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
}

.transaction-type.expense {
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
}

.transaction-type.drop {
    background: rgba(155, 89, 182, 0.1);
    color: #9b59b6;
}

.amount {
    font-weight: 600;
}

.amount.income {
    color: #2ecc71;
}

.amount.expense {
    color: #e74c3c;
}

.amount.drop {
    color: #9b59b6;
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
    background: #3498db;
    color: white;
}

.btn-action:hover {
    background: #2980b9;
    transform: scale(1.1);
}

/* Transaction summary */
.transaction-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.summary-item.total {
    font-weight: 600;
    font-size: 1.1rem;
    border-bottom: none;
    border-top: 2px solid #3498db;
    margin-top: 5px;
    padding-top: 15px;
}

/* Pending Amount Section Styles */
.pending-section {
    margin-top: 20px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    overflow: hidden;
}

.pending-section > div:first-child {
    background: linear-gradient(135deg, #3498db, #2c3e50);
    color: white;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pending-table-container {
    overflow-x: auto;
    border: 1px solid #eee;
    border-radius: 6px;
}

.pending-table {
    width: 100%;
    border-collapse: collapse;
}

.pending-table th {
    text-align: left;
    padding: 10px;
    border-bottom: 1px solid #eee;
    background: #f8f9fa;
}

.pending-table th:last-child {
    text-align: right;
}

.pending-table td {
    padding: 10px;
    border-bottom: 1px solid #eee;
}

.pending-table td:last-child {
    text-align: right;
}

/* NEW: Mobile Card View for Transactions */
.transactions-mobile-view {
    display: none;
    flex-direction: column;
    gap: 15px;
}

.transaction-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid #eee;
}

.transaction-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.transaction-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
}

.transaction-card-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 15px;
}

.transaction-card-detail {
    display: flex;
    flex-direction: column;
}

.transaction-detail-label {
    font-size: 0.8rem;
    color: #7f8c8d;
    margin-bottom: 3px;
}

.transaction-detail-value {
    font-weight: 500;
    color: #2c3e50;
}

.transaction-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #eee;
    padding-top: 15px;
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

/* Date Range Buttons */
.date-range-buttons {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.date-range-btn {
    padding: 10px 15px;
    font-size: 0.9rem;
    white-space: nowrap;
}

/* Pagination Styles */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    padding: 15px 20px;
    border-radius: 8px;
    margin: 20px 0;
    flex-wrap: wrap;
    gap: 15px;
}

.pagination-info {
    color: #2c3e50;
    font-weight: 500;
    font-size: 0.9rem;
}

.pagination-controls {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.pagination-btn {
    padding: 8px 12px;
    font-size: 0.85rem;
    min-width: auto;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.pagination-pages {
    display: flex;
    gap: 5px;
    align-items: center;
}

.page-number {
    padding: 8px 12px;
    border: none;
    background: transparent;
    color: #2c3e50;
    cursor: pointer;
    border-radius: 4px;
    font-weight: 500;
    transition: all 0.3s;
}

.page-number:hover {
    background: #3498db;
    color: white;
}

.page-number.active {
    background: #3498db;
    color: white;
}

.page-ellipsis {
    padding: 8px 4px;
    color: #7f8c8d;
}

/* Responsive styles */
@media (max-width: 1200px) {
    .financial-summary {
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
    }
    
    .form-row {
        flex-direction: column;
        gap: 15px;
    }
}

@media (max-width: 992px) {
    .financial-summary {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
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
    
    .notification-container {
        max-width: 300px;
        right: 10px;
        top: 70px;
    }
    
    .content-area {
        padding: 20px;
    }
    
    .transaction-controls {
        flex-direction: column;
        gap: 15px;
    }
    
    .date-range-buttons {
        flex-direction: row;
        gap: 8px;
        justify-content: center;
    }
    
    .pagination-container {
        flex-direction: column;
        gap: 10px;
    }
    
    .pagination-controls {
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .financial-summary {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .main-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 15px 20px;
    }
    
    .transaction-controls {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .date-picker, .transaction-filters {
        flex-direction: column;
        align-items: flex-start;
        width: 100%;
    }
    
    .date-picker input, .transaction-filters select {
        width: 100%;
    }
    
    .sub-content-nav {
        flex-direction: column;
    }
    
    .sub-nav-btn {
        min-width: 100%;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .transaction-summary {
        grid-template-columns: 1fr;
    }
    
    .sub-content-body {
        padding: 20px;
    }
    
    /* Show mobile cards and hide table on mobile */
    .transactions-table-container {
        display: none;
    }
    
    .transactions-mobile-view {
        display: flex;
    }
    
    .pending-table-container {
        display: none;
    }
    
    .pending-section .pending-table-container {
        display: block;
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
    
    .transaction-card {
        padding: 15px;
    }
    
    .summary-card {
        padding: 15px;
        flex-direction: column;
        text-align: center;
    }
    
    .summary-icon {
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .transaction-card-details {
        grid-template-columns: 1fr;
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
    
    .summary-card {
        padding: 12px;
    }
    
    .summary-icon {
        width: 50px;
        height: 50px;
        font-size: 1.3rem;
    }
    
    .summary-amount {
        font-size: 1.3rem;
    }
    
    .sub-content-header {
        padding: 12px 15px;
        font-size: 1.1rem;
    }
    
    .transaction-card {
        padding: 12px;
    }
}

    /* Searchable Dropdown Styles */
    .resident-selector-container {
        position: relative;
        width: 100%;
    }

    .resident-selector-input {
        width: 100%;
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        background: white;
        cursor: pointer;
    }

    .resident-selector-input:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
    }

    .resident-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 5px 5px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }

    .resident-dropdown.show {
        display: block;
    }

    .resident-option {
        padding: 10px 15px;
        cursor: pointer;
        border-bottom: 1px solid #f1f1f1;
        transition: background-color 0.2s;
    }

    .resident-option:hover, .resident-option.selected {
        background-color: #f8f9fa;
    }

    .resident-option:last-child {
        border-bottom: none;
    }

    .resident-option-name {
        font-weight: 500;
        color: #2c3e50;
    }

    .resident-option-room {
        font-size: 12px;
        color: #7f8c8d;
        margin-top: 2px;
    }

    /* Balance Validation Styles */
    .balance-confirmation-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 10000;
        display: none;
    }

    .balance-confirmation-box {
        background: white;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        max-width: 450px;
        width: 90%;
        text-align: center;
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-30px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .balance-warning-icon {
        font-size: 3rem;
        color: #f39c12;
        margin-bottom: 20px;
    }

    .balance-warning-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 15px;
    }

    .balance-warning-message {
        font-size: 1rem;
        color: #7f8c8d;
        margin-bottom: 25px;
        line-height: 1.5;
    }

    .balance-amount {
        font-weight: 700;
        color: #e74c3c;
    }

    .balance-modal-buttons {
        display: flex;
        gap: 15px;
        justify-content: center;
    }

    .balance-btn {
        padding: 12px 25px;
        border: none;
        border-radius: 6px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .balance-btn-continue {
        background: #e74c3c;
        color: white;
    }

    .balance-btn-continue:hover {
        background: #c0392b;
        transform: translateY(-1px);
    }

    .balance-btn-cancel {
        background: #95a5a6;
        color: white;
    }

    .balance-btn-cancel:hover {
        background: #7f8c8d;
        transform: translateY(-1px);
    }

    /* Toast Notification Styles */
    .toast-notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border-left: 5px solid #e74c3c;
        border-radius: 8px;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        padding: 20px 25px;
        max-width: 400px;
        z-index: 10001;
        transform: translateX(500px);
        transition: transform 0.4s ease-out;
    }

    .toast-notification.show {
        transform: translateX(0);
    }

    .toast-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .toast-icon {
        font-size: 1.5rem;
        color: #e74c3c;
        margin-right: 10px;
    }

    .toast-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #2c3e50;
        flex: 1;
    }

    .toast-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        color: #95a5a6;
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .toast-close:hover {
        color: #2c3e50;
    }

    .toast-message {
        color: #7f8c8d;
        line-height: 1.5;
        font-size: 0.95rem;
    }

    .toast-amount {
        font-weight: 700;
        color: #e74c3c;
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
                <li class="menu-item">
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
                <li class="menu-item active">
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
                <h1><i class="fas fa-calculator"></i> Accounts Management - <?php echo htmlspecialchars($carehome_name); ?></h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>  <a href="../logout.php"><button type="submit" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></button></a>


<style>
.logout-btn {
    padding: 8px 15px;
    border: none;
    background: #e74c3c;
    color: white;
    border-radius: 5px;
    cursor: pointer;
}
.logout-btn:hover {
    background: #c0392b;
}
</style>
                </div>
            </header>

            <div class="content-area">
                <div class="page-header">
                    <h2>Financial Management System</h2>
                    <p>Manage income, expenses, and daily transactions</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Financial Summary Cards -->
                <div class="financial-summary">
                    <div class="summary-card income">
                        <div class="summary-icon">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Receipts</h3>
                            <p class="summary-amount">£<?php echo number_format($total_income, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    <div class="summary-card expense">
                        <div class="summary-icon">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Payment</h3>
                            <p class="summary-amount">£<?php echo number_format($total_expense, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    
                    <div class="summary-card drop">
                        <div class="summary-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Drops</h3>
                            <p class="summary-amount">£<?php echo number_format($total_drop, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    <div class="summary-card profit">
                        <div class="summary-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Net Money</h3>
                            <p class="summary-amount">£<?php echo number_format($net_profit, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    <div class="summary-card bank">
                        <div class="summary-icon">
                            <i class="fas fa-university"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Overall Bank Balance</h3>
                            <p class="summary-amount">£<?php echo number_format($current_bank_balance, 2); ?></p>
                            <small>Current</small>
                        </div>
                    </div>
                    <div class="summary-card cash">
                        <div class="summary-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Overall Cash Balance</h3>
                            <p class="summary-amount">£<?php echo number_format($current_cash_balance, 2); ?></p>
                            <small>Current</small>
                        </div>
                    </div>
                </div>

                <!-- Sub-content Navigation -->
                <div class="sub-content-nav">
                    <button class="sub-nav-btn active" data-target="add-income">
                        <i class="fas fa-plus-circle"></i>
                        Add Money
                    </button>
                    <button class="sub-nav-btn" data-target="add-expense">
                        <i class="fas fa-minus-circle"></i>
                        Add Expense
                    </button>
                    <button class="sub-nav-btn" data-target="drop-amount">
                        <i class="fas fa-hand-holding-usd"></i>
                        Paid Back
                    </button>
                    <button class="sub-nav-btn" data-target="transfer-amount">
                        <i class="fas fa-exchange-alt"></i>
                        Transfer Money
                    </button>
                    <button class="sub-nav-btn" data-target="daily-transaction">
                        <i class="fas fa-calendar-day"></i>
                        Daily Transaction
                    </button>
                </div>

                <!-- Add Income Sub-content -->
                <div id="add-income" class="sub-content active">
                    <div class="sub-content-header">
                        <i class="fas fa-plus-circle"></i>
                        Adding Money
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="incomeForm" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_income">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="incomeType">
                                        <i class="fas fa-tag"></i>
                                        Income Type
                                    </label>
                                    <select id="incomeType" name="incomeType" required>
                                        <option value="">Select Income Type</option>
                                        <option value="resident_fees">Resident </option>
                                        <option value="government_funding">Reletive</option>
                                        <option value="donations">Friends</option>
                                        <option value="insurance">Council</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="incomeAmount">
                                        <i class="fas fa-pound-sign"></i>
                                        Amount
                                    </label>
                                    <input type="number" id="incomeAmount" name="amount" step="0.01" min="0" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="incomeResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <div class="resident-selector-container">
                                        <input type="text" 
                                               id="incomeResident" 
                                               name="resident_display" 
                                               class="resident-selector-input" 
                                               placeholder="Search or select a resident..." 
                                               readonly autocomplete="off">
                                        <input type="hidden" name="resident_id" id="incomeResidentId">
                                        <div class="resident-dropdown" id="incomeResidentDropdown">
                                            <div class="resident-option" data-value="" data-id="">
                                                <span class="resident-option-name">No resident selected</span>
                                            </div>
                                            <?php foreach ($activeResidents as $resident): ?>
                                                <div class="resident-option" 
                                                     data-value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>" 
                                                     data-id="<?php echo $resident['id']; ?>">
                                                    <span class="resident-option-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></span>
                                                    <span class="resident-option-room">Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-money-check-alt"></i>
                                        Payment Type
                                    </label>
                                    <div style="display:flex;gap:1rem;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="cash" required>
                                            Cash
                                        </label>
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="bank" required>
                                            Bank
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="incomeReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference/Receipt No.
                                    </label>
                                    <input type="text" id="incomeReference" name="reference_no" placeholder="Optional reference number">
                                </div>
                                <div class="form-group">
                                    <label for="incomeTransactionDate">
                                        <i class="fas fa-calendar-alt"></i>
                                        Transaction Date
                                    </label>
                                    <input type="date" id="incomeTransactionDate" name="transaction_date" title="Transaction Date">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="incomeDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="incomeDescription" name="description" rows="3" placeholder="Additional details about this income..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="incomeProof">
                                    <i class="fas fa-paperclip"></i>
                                    Upload Proof (Image/PDF)
                                </label>
                                <input type="file" id="incomeProof" name="proof" accept="image/*,application/pdf">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Add Income
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Add Expense Sub-content -->
                <div id="add-expense" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-minus-circle"></i>
                        Add New Expense
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="expenseForm" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_expense">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expenseType">
                                        <i class="fas fa-tag"></i>
                                        Expense Type
                                    </label>
                                    <select id="expenseType" name="expenseType" required>
                                    <option value="">Select Expense Type</option>
                                        <option value="staff_salaries">Hair Cut </option>
                                        <option value="utilities">Nail</option>
                                        <option value="food_supplies">Podiatrists </option>
                                        <option value="medical_supplies">Chiropodists </option>
                                        <option value="maintenance">Personal Trainer</option>
                                        <option value="insurance">Yoga</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="expenseAmount">
                                        <i class="fas fa-pound-sign"></i>
                                        Amount
                                    </label>
                                    <input type="number" id="expenseAmount" name="amount" step="0.01" min="0" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expenseResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <div class="resident-selector-container">
                                        <input type="text" 
                                               id="expenseResident" 
                                               name="resident_display" 
                                               class="resident-selector-input" 
                                               placeholder="Search or select a resident..." 
                                               readonly autocomplete="off">
                                        <input type="hidden" name="resident_id" id="expenseResidentId">
                                        <div class="resident-dropdown" id="expenseResidentDropdown">
                                            <div class="resident-option" data-value="" data-id="">
                                                <span class="resident-option-name">No resident selected</span>
                                            </div>
                                            <?php foreach ($activeResidents as $resident): ?>
                                                <div class="resident-option" 
                                                     data-value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>" 
                                                     data-id="<?php echo $resident['id']; ?>">
                                                    <span class="resident-option-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></span>
                                                    <span class="resident-option-room">Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-money-check-alt"></i>
                                        Payment Type
                                    </label>
                                    <div style="display:flex;gap:1rem;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="cash" required>
                                            Cash
                                        </label>
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="bank" required>
                                            Bank
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <?php date_default_timezone_set('Asia/Kolkata'); // set your timezone ?>

                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="expenseReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference/Receipt No.
                                    </label>
                                    <input type="text" id="expenseReference" name="reference_no" placeholder="Optional reference number">
                                </div>
                                <div class="form-group">
                                    <label for="expenseTransactionDate">
                                        <i class="fas fa-calendar-alt"></i>
                                        Transaction Date
                                    </label>
                                    <input type="date" id="expenseTransactionDate" name="transaction_date" title="Transaction Date" max="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="expenseDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="expenseDescription" name="description" rows="3" placeholder="Additional details about this expense..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="expenseProof">
                                    <i class="fas fa-paperclip"></i>
                                    Upload Proof (Image/PDF)
                                </label>
                                <input type="file" id="expenseProof" name="proof" accept="image/*,application/pdf">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Add Expense
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Drop Amount Sub-content -->
                <div id="drop-amount" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-hand-holding-usd"></i>
                        Paid Back
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="dropForm" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_drop">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dropAmount">
                                        <i class="fas fa-pound-sign"></i>
                                        Drop Amount
                                    </label>
                                    <input type="number" id="dropAmount" name="amount" step="0.01" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label for="dropResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <div class="resident-selector-container">
                                        <input type="text" 
                                               id="dropResident" 
                                               name="resident_display" 
                                               class="resident-selector-input" 
                                               placeholder="Search or select a resident..." 
                                               readonly autocomplete="off">
                                        <input type="hidden" name="resident_id" id="dropResidentId">
                                        <div class="resident-dropdown" id="dropResidentDropdown">
                                            <div class="resident-option" data-value="" data-id="">
                                                <span class="resident-option-name">No resident selected</span>
                                            </div>
                                            <?php foreach ($activeResidents as $resident): ?>
                                                <div class="resident-option" 
                                                     data-value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>" 
                                                     data-id="<?php echo $resident['id']; ?>">
                                                    <span class="resident-option-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></span>
                                                    <span class="resident-option-room">Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dropReason">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Reason for Drop
                                    </label>
                                    <select id="dropReason" name="dropReason" required>
                                        <option value="">Select Reason</option>
                                        <option value="refund">Refund to Resident</option>
                                        <option value="adjustment">Account Adjustment</option>
                                        <option value="error_correction">Error Correction</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-money-check-alt"></i>
                                        Payment Type
                                    </label>
                                    <div style="display:flex;gap:1rem;align-items:center;">
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="cash" required>
                                            Cash
                                        </label>
                                        <label style="display:flex;align-items:center;gap:0.5rem;">
                                            <input type="radio" name="payment_method" value="bank" required>
                                            Bank
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dropReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference Number
                                    </label>
                                    <input type="text" id="dropReference" name="reference_no" placeholder="Transaction reference">
                                </div>
                                <div class="form-group">
                                    <label for="dropTransactionDate">
                                        <i class="fas fa-calendar-alt"></i>
                                        Transaction Date
                                    </label>
                                    <input type="date" id="dropTransactionDate" name="transaction_date" title="Transaction Date" max="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="dropDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="dropDescription" name="description" rows="3" placeholder="Detailed explanation for the amount drop..." required></textarea>
                            </div>

                            <div class="form-group">
                                <label for="dropProof">
                                    <i class="fas fa-paperclip"></i>
                                    Upload Proof (Image/PDF)
                                </label>
                                <input type="file" id="dropProof" name="proof" accept="image/*,application/pdf">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Process Drop
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Transfer Money Sub-content -->
                <div id="transfer-amount" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-exchange-alt"></i>
                        Transfer Funds
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" method="POST" action="">
                            <input type="hidden" name="action" value="transfer">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="transferAmount">
                                        <i class="fas fa-pound-sign"></i>
                                        Amount
                                    </label>
                                    <input type="number" id="transferAmount" name="amount" step="0.01" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label for="transferResident">
                                        <i class="fas fa-user"></i>
                                        Select Resident
                                    </label>
                                    <div class="resident-selector-container">
                                        <input type="text" 
                                               id="transferResident" 
                                               name="resident_display" 
                                               class="resident-selector-input" 
                                               placeholder="Search or select a resident..." 
                                               readonly autocomplete="off">
                                        <input type="hidden" name="resident_id" id="transferResidentId">
                                        <div class="resident-dropdown" id="transferResidentDropdown">
                                            <div class="resident-option" data-value="" data-id="">
                                                <span class="resident-option-name">General Transfer</span>
                                            </div>
                                            <?php foreach ($activeResidents as $resident): ?>
                                                <div class="resident-option" 
                                                     data-value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>" 
                                                     data-id="<?php echo $resident['id']; ?>">
                                                    <span class="resident-option-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></span>
                                                    <span class="resident-option-room">Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>
                                    <i class="fas fa-exchange-alt"></i>
                                    Transfer Method
                                </label>
                                <div style="display:flex;gap:1rem;align-items:center;">
                                    <label style="display:flex;align-items:center;gap:0.5rem;">
                                        <input type="radio" name="transfer_method" value="cash_to_bank" required>
                                        Cash to Bank
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.5rem;">
                                        <input type="radio" name="transfer_method" value="bank_to_cash" required>
                                        Bank to Cash
                                    </label>
                                </div>
                            </div>

                            <div class="form-row" style="display: flex; gap: 20px;">
                                <div class="form-group" style="flex: 1;">
                                    <label for="transferReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference No.
                                    </label>
                                    <input type="text" id="transferReference" name="reference_no" placeholder="Optional reference number">
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label for="transferDate">
                                        <i class="fas fa-calendar-alt"></i>
                                        Transaction Date
                                    </label>
                                    <input type="date" id="transferDate" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="transferDescription">
                                    <i class="fas fa-file-text"></i>
                                    Description
                                </label>
                                <textarea id="transferDescription" name="description" rows="3" placeholder="Details about this transfer..." required></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-exchange-alt"></i>
                                    Process Transfer
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Daily Transaction Sub-content -->
                <div id="daily-transaction" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-calendar-day"></i>
                        Daily Transactions
                    </div>
                    <div class="sub-content-body">
                        <div class="transaction-controls">
                            <div class="date-picker">
                                <label for="startDate">
                                    <i class="fas fa-calendar"></i>
                                    Start Date
                                </label>
                                <input type="date" id="startDate" value="<?php echo $today; ?>">
                            </div>
                            <div class="date-picker">
                                <label for="endDate">
                                    <i class="fas fa-calendar"></i>
                                    End Date
                                </label>
                                <input type="date" id="endDate" value="<?php echo $today; ?>">
                            </div>
                            <div class="date-range-buttons">
                                <button type="button" class="btn btn-secondary date-range-btn" id="thisMonthBtn">
                                    <i class="fas fa-calendar-alt"></i>
                                    This Month
                                </button>
                                <button type="button" class="btn btn-secondary date-range-btn" id="thisYearBtn">
                                    <i class="fas fa-calendar"></i>
                                    This Year
                                </button>
                            </div>
                            <!-- Add this script to load transactions on page load -->
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    // Trigger initial load of transactions
                                    loadDailyTransactions();
                                });
                            </script>
                            </div>
                            <div class="transaction-filters">
                                <label for="transactionType">Working Type:</label>
                                <select id="transactionType">
                                    <option value="">All Types</option>
                                    <option value="income">Transfer in</option>
                                    <option value="expense">Transfer out</option>
                                    <option value="drop">Paid back</option>
                                </select>
                                <label for="transactionResident">Resident:</label>
                                <div class="resident-selector-container">
                                    <input type="text" 
                                           id="transactionResident" 
                                           name="transaction_resident_display" 
                                           class="resident-selector-input" 
                                           placeholder="All Residents - Search or select..." 
                                           readonly autocomplete="off">
                                    <input type="hidden" name="transaction_resident_id" id="transactionResidentId">
                                    <div class="resident-dropdown" id="transactionResidentDropdown">
                                        <div class="resident-option" data-value="" data-id="">
                                            <span class="resident-option-name">All Residents</span>
                                        </div>
                                        <?php foreach ($residents as $resident): ?>
                                            <div class="resident-option" 
                                                 data-value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>" 
                                                 data-id="<?php echo $resident['id']; ?>">
                                                <span class="resident-option-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></span>
                                                <span class="resident-option-room">Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="transactions-table-container">
                            <table class="transactions-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-calendar"></i> Transaction Date</th>
                                        <th><i class="fas fa-tag"></i> Type</th>
                                        <th><i class="fas fa-file-text"></i> Description</th>
                                        <th><i class="fas fa-user"></i> Resident</th>
                                        <th><i class="fas fa-pound-sign"></i> Amount</th>
                                        <th><i class="fas fa-money-check"></i> Payment</th>
                                        <th><i class="fas fa-receipt"></i> Slip</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionsTableBody">
                                    <?php if (empty($today_transactions)): ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 20px;">
                                                <i class="fas fa-info-circle"></i> No transactions found for today.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($display_transactions as $transaction): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($transaction['transaction_date'] ?? $transaction['created_at']))); ?></td>
                                                <td>
                                                    <span class="transaction-type <?php echo $transaction['type']; ?>">
                                                        <i class="fas fa-<?php echo $transaction['type'] === 'income' ? 'arrow-up' : ($transaction['type'] === 'expense' ? 'arrow-down' : ($transaction['type'] === 'transfer' ? 'exchange-alt' : 'hand-holding-usd')); ?>"></i>
                                                        <?php
                                                            if ($transaction['type'] === 'income') {
                                                                echo 'Transfer in';
                                                            } elseif ($transaction['type'] === 'expense') {
                                                                echo 'Transfer out';
                                                            } elseif ($transaction['type'] === 'drop') {
                                                                echo 'Paid back';
                                                            } else {
                                                                echo ucfirst($transaction['type']);
                                                            }
                                                        ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td>
                                                    <?php if ($transaction['first_name']): ?>
                                                        <?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?>
                                                    <?php else: ?>
                                                        <em>General</em>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount <?php echo $transaction['display_type']; ?>">
                                                    <?php 
                                                        if ($transaction['type'] === 'transfer') {
                                                            echo ($transaction['original_type'] === 'expense' ? '-' : '+');
                                                        } else {
                                                            echo ($transaction['type'] === 'income' ? '+' : '-');
                                                        }
                                                        echo number_format($transaction['amount'], 2); 
                                                    ?>
                                                </td>
                                                <td>
                                                    <span style="text-transform: capitalize;"><?php echo $transaction['payment_method']; ?></span>
                                                </td>
                                                <td>
                                                    <button class="btn-view-slip" onclick="viewTransactionSlip(<?php echo htmlspecialchars(json_encode($transaction)); ?>)" style="background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Controls -->
                        <div class="pagination-container" id="paginationContainer" style="display: none;">
                            <div class="pagination-info">
                                <span id="paginationInfo">Showing 0-0 of 0 records</span>
                            </div>
                            <div class="pagination-controls" id="paginationControls">
                                <!-- Pagination buttons will be dynamically generated -->
                            </div>
                        </div>

                        <div class="transaction-summary">
                            <div class="summary-item">
                                <span>Total Income:</span>
                                <span class="summary-amount income" id="totalIncome">+£<?php echo number_format($total_income, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Total Expenses:</span>
                                <span class="summary-amount expense" id="totalExpense">-£<?php echo number_format($total_expense, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Total Drops:</span>
                                <span class="summary-amount drop" id="totalDrop">-£<?php echo number_format($total_drop, 2); ?></span>
                            </div>
                            <div class="summary-item total">
                                <span>Net Total:</span>
                                <span class="summary-amount" id="netTotal">£<?php echo number_format($net_profit, 2); ?></span>
                            </div>
                        </div>

                        <!-- Pending Amount Section -->
                        <div class="pending-section">
                            <div>
                                <i class="fas fa-hourglass-half"></i>
                                <strong>Pending Amount Summary</strong>
                            </div>
                            <div style="padding: 16px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <label for="pendingMode" style="font-weight:600; color:#2c3e50;">View Mode:</label>
                                    <select id="pendingMode" style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
                                        <option value="carehome">Care Home Overall</option>
                                        <option value="resident">Individual Resident</option>
                                    </select>
                                </div>
                                <div id="pendingResidentBlock" style="display:none; align-items:center; gap:8px;">
                                    <label for="pendingResident" style="font-weight:600; color:#2c3e50;">Select Resident:</label>
                                    <div class="resident-selector-container" style="min-width: 220px;">
                                        <input type="text" 
                                               id="pendingResident" 
                                               name="resident_display" 
                                               class="resident-selector-input" 
                                               placeholder="Search or select a resident..." 
                                               readonly autocomplete="off">
                                        <input type="hidden" name="resident_id" id="pendingResidentId">
                                        <div class="resident-dropdown" id="pendingResidentDropdown">
                                            <div class="resident-option" data-value="" data-id="">
                                                <span class="resident-option-name">Choose Resident</span>
                                            </div>
                                            <?php foreach ($residents as $resident): ?>
                                                <div class="resident-option" 
                                                     data-value="<?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name'] . ' (Room ' . $resident['room_number'] . ')'); ?>" 
                                                     data-id="<?php echo $resident['id']; ?>">
                                                    <span class="resident-option-name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></span>
                                                    <span class="resident-option-room">Room: <?php echo htmlspecialchars($resident['room_number']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div style="padding: 0 16px 16px 16px;">
                                <div class="pending-table-container">
                                    <table class="pending-table">
                                        <thead>
                                            <tr>
                                                <th>Metric</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pendingTableBody">
                                            <tr>
                                                <td colspan="2" style="padding:20px; text-align:center; color:#7f8c8d;">
                                                    <i class="fas fa-info-circle"></i> Select a view mode to see financial summary
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // DOM elements
        const subNavButtons = document.querySelectorAll('.sub-nav-btn');
        const subContents = document.querySelectorAll('.sub-content');
        const transactionDate = document.getElementById('transactionDate');
        const transactionType = document.getElementById('transactionType');
        const transactionResident = document.getElementById('transactionResident');

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

        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }, 5000);

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date as default for date inputs
            const today = new Date().toISOString().split('T')[0];
            const transactionDate = document.getElementById('transactionDate');
            if (transactionDate) {
                transactionDate.value = today;
            }
            
            // Load pending amount data for carehome by default
            loadPendingAmount('carehome');
        });

        // Format currency function
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'GBP'
            }).format(amount);
        }

        // Load pending amount data
        async function loadPendingAmount(mode, residentId = null) {
            try {
                const params = new URLSearchParams({
                    action: 'get_pending_amount',
                    mode: mode
                });
                
                if (mode === 'resident' && residentId) {
                    params.append('resident_id', residentId);
                }
                
                const response = await fetch(`?${params}`, {
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success && data.data) {
                    updatePendingTable(data.data, data.mode);
                } else {
                    throw new Error(data.error || 'Failed to load pending amount data');
                }
            } catch (error) {
                console.error('Error loading pending amount:', error);
                document.getElementById('pendingTableBody').innerHTML = `
                    <tr>
                        <td colspan="2" style="padding:20px; text-align:center; color:#e74c3c;">
                            <i class="fas fa-exclamation-triangle"></i> Error loading data: ${error.message}
                        </td>
                    </tr>
                `;
            }
        }

        // Update pending table with data
        function updatePendingTable(data, mode) {
            const tbody = document.getElementById('pendingTableBody');
            
            if (mode === 'carehome') {
                // CareHome overall summary
                tbody.innerHTML = `
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Bank Income</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.total_bank_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Cash Income</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.total_cash_income)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Income</td>
                        <td style="font-weight:700;">${formatCurrency(data.total_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Expenses</td>
                        <td style="color:#e74c3c; font-weight:600;">${formatCurrency(data.total_expenses)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Drops</td>
                        <td style="color:#9b59b6; font-weight:600;">${formatCurrency(data.total_drops)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Costs</td>
                        <td style="font-weight:700; color:#e74c3c;">${formatCurrency(data.total_costs)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Current Bank Balance</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.current_bank_balance)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Current Cash Balance</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.current_cash_balance)}</td>
                    </tr>
                    <tr style="background:#e8f4fd; border-top: 2px solid #3498db;">
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">NET BALANCE</td>
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">${formatCurrency(data.net_balance)}</td>
                    </tr>
                `;
            } else if (mode === 'resident' && data) {
                // Individual resident summary
                const residentNameElement = document.getElementById('pendingResident');
                const residentName = residentNameElement ? residentNameElement.value || 'Selected Resident' : 'Selected Resident';
                
                tbody.innerHTML = `
                    <tr style="background:#f0f8ff;">
                        <td colspan="2" style="padding:12px; text-align:center; font-weight:700; color:#2c3e50;">
                            ${residentName}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Bank Income</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.bank_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Cash Income</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.cash_income)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Income</td>
                        <td style="font-weight:700;">${formatCurrency(data.total_income)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Bank Expenses</td>
                        <td style="color:#e74c3c; font-weight:600;">${formatCurrency(data.bank_expenses)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Cash Expenses</td>
                        <td style="color:#e74c3c; font-weight:600;">${formatCurrency(data.cash_expenses)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Expenses</td>
                        <td style="color:#e74c3c; font-weight:600;">${formatCurrency(data.total_expenses)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Bank Drops</td>
                        <td style="color:#9b59b6; font-weight:600;">${formatCurrency(data.bank_drops)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Cash Drops</td>
                        <td style="color:#9b59b6; font-weight:600;">${formatCurrency(data.cash_drops)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Total Drops</td>
                        <td style="color:#9b59b6; font-weight:600;">${formatCurrency(data.total_drops)}</td>
                    </tr>
                    <tr style="background:#f8f9fa;">
                        <td style="font-weight:700;">Total Costs</td>
                        <td style="font-weight:700; color:#e74c3c;">${formatCurrency(data.total_costs)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Current Bank Balance</td>
                        <td style="color:#3498db; font-weight:600;">${formatCurrency(data.current_bank_balance)}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:600; color:#2c3e50;">Current Cash Balance</td>
                        <td style="color:#27ae60; font-weight:600;">${formatCurrency(data.current_cash_balance)}</td>
                    </tr>
                    <tr style="background:#e8f4fd; border-top: 2px solid #3498db;">
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">PENDING BALANCE</td>
                        <td style="padding:12px; font-weight:800; color:#2c3e50;">${formatCurrency(data.pending_balance)}</td>
                    </tr>
                `;
            }
        }



        // Add this function to refresh pending data when transactions are updated
        function refreshPendingData() {
            const mode = document.getElementById('pendingMode').value;
            const residentId = mode === 'resident' ? document.getElementById('pendingResidentId').value : null;
            loadPendingAmount(mode, residentId);
        }
        
        // Daily Transactions filtering functionality
        function filterTransactions() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const transactionType = document.getElementById('transactionType').value;
            const residentId = document.getElementById('transactionResidentId').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date');
                return;
            }
            
            // Show loading state
            const tableBody = document.getElementById('transactionsTableBody');
            tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</td></tr>';
            
            // Build query parameters
            const params = new URLSearchParams({
                action: 'filter_transactions',
                start_date: startDate,
                end_date: endDate
            });
            
            if (transactionType) params.append('type', transactionType);
            if (residentId) params.append('resident_id', residentId);
            
            // Fetch filtered transactions
            fetch(`?${params}`, {
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateTransactionsTable(data.transactions);
                    updateTransactionSummary(data.summary);
                } else {
                    throw new Error(data.error || 'Failed to load transactions');
                }
            })
            .catch(error => {
                console.error('Error filtering transactions:', error);
                tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px; color: #e74c3c;"><i class="fas fa-exclamation-triangle"></i> Error loading transactions: ${error.message}</td></tr>`;
            });
        }
        
        // Update transactions table with filtered data
        function updateTransactionsTable(transactions) {
            const tableBody = document.getElementById('transactionsTableBody');
            
            if (!transactions || transactions.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px;"><i class="fas fa-info-circle"></i> No transactions found for the selected criteria.</td></tr>';
                return;
            }
            
            tableBody.innerHTML = transactions.map(transaction => `
                <tr>
                    <td>${transaction.transaction_date ? transaction.transaction_date : new Date(transaction.created_at).toLocaleDateString('en-CA')}</td>
                    <td>
                        <span class="transaction-type ${(transaction.display_type || transaction.type)}">
                            <i class="fas fa-${
                                (transaction.display_type || transaction.type) === 'transfer' ? 'exchange-alt' : 
                                ((transaction.display_type || transaction.type) === 'income' ? 'arrow-up' : 'arrow-down')
                            }"></i>
                            ${ (transaction.display_type || transaction.type) === 'income' ? 'Transfer in' : ((transaction.display_type || transaction.type) === 'expense' ? 'Transfer out' : ((transaction.display_type || transaction.type) === 'drop' ? 'Paid back' : ((transaction.display_type || transaction.type || '').charAt ? (transaction.display_type || transaction.type || '').charAt(0).toUpperCase() + (transaction.display_type || transaction.type || '').slice(1) : (transaction.display_type || transaction.type)))) }
                        </span>
                    </td>
                    <td>${transaction.description || '-'}</td>
                    <td>${transaction.first_name ? `${transaction.first_name} ${transaction.last_name}` : '<em>General</em>'}</td>
                    <td class="amount ${(transaction.display_type || transaction.type)}">
                        ${
                            (transaction.display_type || transaction.type) === 'transfer' ? 
                            (transaction.original_type === 'expense' ? '-' : '+') :
                            ((transaction.display_type || transaction.type) === 'income' ? '+' : '-')
                        }£${parseFloat(Math.abs(transaction.amount)).toFixed(2)}
                    </td>
                    <td style="text-transform: capitalize;">
                        ${(transaction.display_type || transaction.type) === 'transfer' ? 
                          (transaction.display_payment_method || '').replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase()) :
                          (transaction.original_payment_method || transaction.payment_method)
                        }
                    </td>
                    <td>
                        <button class="btn-view-slip" onclick="viewTransactionSlip(${JSON.stringify(transaction).replace(/"/g, '&quot;')})" style="background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
            `).join('');
        }
        
        // Update transaction summary with filtered data
        function updateTransactionSummary(summary) {
            // Update Pending Amount Summary section
            document.getElementById('totalIncome').textContent = `+£${parseFloat(summary.total_income || 0).toFixed(2)}`;
            document.getElementById('totalExpense').textContent = `-£${parseFloat(summary.total_expense || 0).toFixed(2)}`;
            document.getElementById('totalDrop').textContent = `-£${parseFloat(summary.total_drop || 0).toFixed(2)}`;
            
            const netTotal = (summary.total_income || 0) - (summary.total_expense || 0) - (summary.total_drop || 0);
            document.getElementById('netTotal').textContent = `£${netTotal.toFixed(2)}`;
            document.getElementById('netTotal').style.color = netTotal >= 0 ? '#2ecc71' : '#e74c3c';
            
            // Update main Financial Summary Cards (only the filtered values, not bank/cash balances)
            const incomeCard = document.querySelector('.summary-card.income .summary-amount');
            const expenseCard = document.querySelector('.summary-card.expense .summary-amount');
            const dropCard = document.querySelector('.summary-card.drop .summary-amount');
            const profitCard = document.querySelector('.summary-card.profit .summary-amount');
            
            if (incomeCard) incomeCard.textContent = `£${parseFloat(summary.total_income || 0).toFixed(2)}`;
            if (expenseCard) expenseCard.textContent = `£${parseFloat(summary.total_expense || 0).toFixed(2)}`;
            if (dropCard) dropCard.textContent = `£${parseFloat(summary.total_drop || 0).toFixed(2)}`;
            if (profitCard) profitCard.textContent = `£${netTotal.toFixed(2)}`;
        }
        
        // Function to load daily transactions
        function loadDailyTransactions() {
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            if (startDate && endDate) {
                filterTransactions();
            }
        }

        // Add event listeners for transaction filters
        document.addEventListener('DOMContentLoaded', function() {
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            const transactionType = document.getElementById('transactionType');
            const transactionResidentId = document.getElementById('transactionResidentId');
            
            if (startDate) startDate.addEventListener('change', filterTransactions);
            if (endDate) endDate.addEventListener('change', filterTransactions);
            if (transactionType) transactionType.addEventListener('change', filterTransactions);
            if (transactionResidentId) transactionResidentId.addEventListener('change', filterTransactions);
            
            // Initialize pending section event listeners
            const pendingMode = document.getElementById('pendingMode');
            const pendingResidentBlock = document.getElementById('pendingResidentBlock');
            const pendingResident = document.getElementById('pendingResident');
            const pendingResidentId = document.getElementById('pendingResidentId');
            
            if (pendingMode) {
                pendingMode.addEventListener('change', function() {
                    const mode = this.value;
                    
                    if (mode === 'resident') {
                        if (pendingResidentBlock) pendingResidentBlock.style.display = 'flex';
                        if (pendingResidentId && pendingResidentId.value) {
                            loadPendingAmount('resident', pendingResidentId.value);
                        }
                    } else {
                        if (pendingResidentBlock) pendingResidentBlock.style.display = 'none';
                        loadPendingAmount('carehome');
                    }
                });
            }
            
            if (pendingResidentId) {
                pendingResidentId.addEventListener('change', function() {
                    if (this.value && pendingMode && pendingMode.value === 'resident') {
                        loadPendingAmount('resident', this.value);
                    }
                });
            }
        });
        
        // View transaction slip modal function
        function viewTransactionSlip(transaction) {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                background: rgba(0,0,0,0.5); display: flex; align-items: center; 
                justify-content: center; z-index: 1000; backdrop-filter: blur(2px);
            `;
            
            const modal = document.createElement('div');
            modal.className = 'slip-modal';
            modal.style.cssText = `
                background: white; border-radius: 10px; max-width: 500px; width: 90%; 
                max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            `;
            
            const residentInfo = transaction.first_name ? 
                `${transaction.first_name} ${transaction.last_name}${transaction.room_number ? ` (Room ${transaction.room_number})` : ''}` : 
                'General Transaction';
            
            const typeColor = transaction.type === 'income' ? '#2ecc71' : 
                             transaction.type === 'expense' ? '#e74c3c' : '#9b59b6';
            
            const typeIcon = transaction.type === 'income' ? 'arrow-up' : 
                            transaction.type === 'expense' ? 'arrow-down' : 'hand-holding-usd';
            
            modal.innerHTML = `
                <div style="padding: 20px; border-bottom: 1px solid #eee; background: #f8f9fa; border-radius: 10px 10px 0 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-receipt"></i> Transaction Slip
                        </h3>
                        <button onclick="closeSlipModal()" style="background: #e74c3c; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div style="padding: 25px;">
                    <div style="text-align: center; margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                        <div style="display: inline-flex; align-items: center; gap: 10px; padding: 10px 20px; border-radius: 25px; background: ${typeColor}; color: white; margin-bottom: 15px;">
                            <i class="fas fa-${typeIcon}"></i>
                            <span style="font-weight: 600; text-transform: uppercase;">${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type)))}</span>
                        </div>
                        <div style="font-size: 2rem; font-weight: bold; color: ${typeColor}; margin-bottom: 5px;">
                            ${transaction.type === 'income' ? '+' : '-'}£${parseFloat(transaction.amount).toFixed(2)}
                        </div>
                        <div style="color: #7f8c8d; font-size: 0.9rem;">
                            Transaction ID: #${transaction.id}
                        </div>
                    </div>
                    
                    <div style="display: grid; gap: 15px;">
                        <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                            <span style="font-weight: 600; color: #2c3e50;">Transaction Date:</span>
                            <span>${transaction.transaction_date ? transaction.transaction_date : new Date(transaction.created_at).toLocaleDateString()} ${new Date(transaction.created_at).toLocaleTimeString()}</span>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                            <span style="font-weight: 600; color: #2c3e50;">Resident:</span>
                            <span>${residentInfo}</span>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                            <span style="font-weight: 600; color: #2c3e50;">Payment Method:</span>
                            <span style="text-transform: capitalize; padding: 4px 12px; background: #e8f4fd; color: #3498db; border-radius: 15px; font-size: 0.85rem;">
                                <i class="fas fa-${transaction.payment_method === 'cash' ? 'money-bill-wave' : 'credit-card'}"></i> ${transaction.payment_method}
                            </span>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                            <span style="font-weight: 600; color: #2c3e50;">Created By:</span>
                            <span>${transaction.created_by}</span>
                        </div>
                        
                        <div style="padding: 15px 0;">
                            <div style="font-weight: 600; color: #2c3e50; margin-bottom: 8px;">Description:</div>
                            <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid ${typeColor};">
                                ${transaction.description || 'No description provided'}
                            </div>
                        </div>
                    </div>
                    
                    <div>${transaction.proof_path ? `
                        <div style="padding: 15px 0; border-top: 1px solid #eee;">
                            <div style="font-weight: 600; color: #2c3e50; margin-bottom: 12px; font-size: 1.1rem;">Transaction Slip:</div>
                            <div style="display: flex; gap: 15px; align-items: flex-start;">
                                <button onclick="viewSlipModal('../${transaction.proof_path}')" style="display: inline-flex; align-items: center; gap: 8px; color: white; background: #3498db; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">
                                    <i class="fas fa-${transaction.proof_path.toLowerCase().includes('.pdf') ? 'file-pdf' : 'image'}"></i> View Slip
                                </button>
                                ${transaction.proof_path.toLowerCase().match(/\.(jpg|jpeg|png|gif)$/) ? `
                                <img src="../${transaction.proof_path}" alt="Transaction Slip" style="max-width: 150px; max-height: 150px; border-radius: 8px; border: 2px solid #ddd; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" onclick="viewSlipModal('../${transaction.proof_path}')">
                                ` : ''}
                            </div>
                        </div>
                        ` : ''}</div>
                    
                    <div style="margin-top: 25px; padding-top: 20px; border-top: 2px solid #eee; text-align: center;">
                        <button onclick="printSlip()" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-right: 10px;">
                            <i class="fas fa-print"></i> Print Slip
                        </button>
                        <button onclick="closeSlipModal()" style="background: #95a5a6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            
            // Store transaction data for printing
            window.currentSlipData = transaction;
            
            // Close modal when clicking overlay
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeSlipModal();
                }
            });
        }
        
        // Close slip modal
        function closeSlipModal() {
            const overlay = document.querySelector('.modal-overlay');
            if (overlay) {
                document.body.removeChild(overlay);
            }
            window.currentSlipData = null;
        }
        
        // Print slip function
        function printSlip() {
            if (!window.currentSlipData) return;
            
            const transaction = window.currentSlipData;
            const residentInfo = transaction.first_name ? 
                `${transaction.first_name} ${transaction.last_name}${transaction.room_number ? ` (Room ${transaction.room_number})` : ''}` : 
                'General Transaction';
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Transaction Slip #${transaction.id}</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
                            .header { text-align: center; border-bottom: 2px solid #2c3e50; padding-bottom: 20px; margin-bottom: 30px; }
                            .amount { font-size: 2rem; font-weight: bold; text-align: center; margin: 20px 0; }
                            .details { margin: 20px 0; }
                            .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
                            .detail-label { font-weight: bold; }
                            .description { background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 15px 0; }
                            @media print { body { margin: 0; } }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h1>Transaction Slip</h1>
                            <p>Transaction ID: #${transaction.id}</p>
                        </div>
                        
                        <div class="amount" style="color: ${transaction.type === 'income' ? '#2ecc71' : '#e74c3c'};">
                            ${transaction.type === 'income' ? '+' : '-'}£${parseFloat(transaction.amount).toFixed(2)}
                        </div>
                        
                        <div class="details">
                            <div class="detail-row">
                                <span class="detail-label">Type:</span>
                                <span style="text-transform: uppercase;">${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type)))}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Transaction Date:</span>
                                <span>${transaction.transaction_date ? transaction.transaction_date : new Date(transaction.created_at).toLocaleDateString()} ${new Date(transaction.created_at).toLocaleTimeString()}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Resident:</span>
                                <span>${residentInfo}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Payment Method:</span>
                                <span style="text-transform: capitalize;">${transaction.payment_method}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Created By:</span>
                                <span>${transaction.created_by}</span>
                            </div>
                        </div>
                        
                        <div class="description">
                            <strong>Description:</strong><br>
                            ${transaction.description || 'No description provided'}
                        </div>
                        
                        <div style="text-align: center; margin-top: 40px; font-size: 0.9rem; color: #7f8c8d;">
                            <p>Generated on ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</p>
                        </div>
                    </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
            printWindow.close();
        }
        
        
        // Mobile menu functionality
function setupMobileMenu() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (mobileMenuToggle && sidebar) {
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
    }
}

document.addEventListener('DOMContentLoaded', function() {
    setupMobileMenu();
    
    // Generate mobile transaction cards
    generateMobileTransactionCards();
});

// Function to generate mobile transaction cards
function generateMobileTransactionCards() {
    const tableBody = document.getElementById('transactionsTableBody');
    const mobileView = document.querySelector('.transactions-mobile-view');
    
    if (!tableBody || !mobileView) return;
    
    const rows = tableBody.querySelectorAll('tr');
    if (rows.length === 0) return;
    
    let cardsHTML = '';
    
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length === 0) return;
        
        const date = cells[0].textContent;
        const typeElement = cells[1].querySelector('.transaction-type');
        const type = typeElement ? typeElement.textContent.trim() : '';
        const typeClass = typeElement ? typeElement.className.includes('income') ? 'income' : 
                                      typeElement.className.includes('expense') ? 'expense' : 'drop' : '';
        const description = cells[2].textContent;
        const resident = cells[3].textContent;
        const amount = cells[4].textContent;
        const payment = cells[5].textContent;
        
        cardsHTML += `
            <div class="transaction-card">
                <div class="transaction-card-header">
                    <div class="transaction-card-title">${description}</div>
                    <span class="transaction-type ${typeClass}">${type}</span>
                </div>
                <div class="transaction-card-details">
                    <div class="transaction-card-detail">
                        <span class="transaction-detail-label">Date</span>
                        <span class="transaction-detail-value">${date}</span>
                    </div>
                    <div class="transaction-card-detail">
                        <span class="transaction-detail-label">Resident</span>
                        <span class="transaction-detail-value">${resident}</span>
                    </div>
                    <div class="transaction-card-detail">
                        <span class="transaction-detail-label">Amount</span>
                        <span class="transaction-detail-value amount ${typeClass}">${amount}</span>
                    </div>
                    <div class="transaction-card-detail">
                        <span class="transaction-detail-label">Payment</span>
                        <span class="transaction-detail-value">${payment}</span>
                    </div>
                </div>
                <div class="transaction-card-footer">
                    <button class="btn btn-primary" style="padding: 8px 12px;">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </div>
            </div>
        `;
    });
    
    mobileView.innerHTML = cardsHTML;
}

// Initialize searchable resident dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all resident selectors
    initializeResidentSelector('incomeResident', 'incomeResidentDropdown', 'incomeResidentId');
    initializeResidentSelector('expenseResident', 'expenseResidentDropdown', 'expenseResidentId');
    initializeResidentSelector('dropResident', 'dropResidentDropdown', 'dropResidentId');
    initializeResidentSelector('transferResident', 'transferResidentDropdown', 'transferResidentId');
    initializeResidentSelector('pendingResident', 'pendingResidentDropdown', 'pendingResidentId');
    initializeResidentSelector('transactionResident', 'transactionResidentDropdown', 'transactionResidentId');
});

// Function to initialize resident selector functionality
function initializeResidentSelector(inputId, dropdownId, hiddenId) {
    const input = document.getElementById(inputId);
    const dropdown = document.getElementById(dropdownId);
    const hiddenInput = document.getElementById(hiddenId);
    const options = dropdown ? dropdown.querySelectorAll('.resident-option') : [];
    
    if (!input || !dropdown || !hiddenInput) return;
    
    // Toggle dropdown on input click
    input.addEventListener('click', function(e) {
        e.stopPropagation();
        // Close all other dropdowns
        document.querySelectorAll('.resident-dropdown.show').forEach(dd => {
            if (dd.id !== dropdownId) dd.classList.remove('show');
        });
        dropdown.classList.toggle('show');
    });
    
    // Filter options on input
    input.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        let hasVisibleOptions = false;
        
        options.forEach(option => {
            const name = option.querySelector('.resident-option-name')?.textContent.toLowerCase() || '';
            const room = option.querySelector('.resident-option-room')?.textContent.toLowerCase() || '';
            const isMatch = name.includes(searchTerm) || room.includes(searchTerm) || option.dataset.value === '';
            
            option.style.display = isMatch ? 'block' : 'none';
            if (isMatch && option.dataset.value !== '') hasVisibleOptions = true;
        });
        
        dropdown.classList.add('show');
    });
    
    // Handle option selection
    options.forEach(option => {
        option.addEventListener('click', function(e) {
            e.stopPropagation();
            input.value = this.dataset.value;
            hiddenInput.value = this.dataset.id;
            dropdown.classList.remove('show');
            
            // Update selected state
            options.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            
            // Special handling for pending resident selector
            if (inputId === 'pendingResident') {
                loadPendingAmount('resident', this.dataset.id);
            }
            
            // Special handling for transaction resident selector
            if (inputId === 'transactionResident') {
                filterTransactions();
            }
        });
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });
    
    // Make input readonly after setup to prevent typing but allow clicking
    input.readOnly = false; // Allow typing for search
}

// Balance validation for expense form
document.addEventListener('DOMContentLoaded', function() {
    const expenseForm = document.getElementById('expenseForm');
    
    if (expenseForm) {
        expenseForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('expenseAmount').value);
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
            
            if (!amount || !paymentMethod) {
                this.submit(); // Let normal validation handle missing fields
                return;
            }
            
            try {
                // Get resident ID if selected
                const residentSelect = document.getElementById('expenseResidentId');
                const residentId = residentSelect ? residentSelect.value : null;
                
                // Get current balance
                let balanceCheckUrl = '?action=check_balance';
                let fetchOptions = {
                    credentials: 'same-origin'
                };
                
                // If resident is selected, check resident-specific balance
                if (residentId) {
                    fetchOptions.method = 'POST';
                    fetchOptions.headers = {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    };
                    fetchOptions.body = `resident_id=${residentId}`;
                }
                
                const response = await fetch(balanceCheckUrl, fetchOptions);
                const data = await response.json();
                
                if (data.success) {
                    const currentBalance = paymentMethod === 'cash' ? data.cash_balance : data.bank_balance;
                    
                    if (currentBalance < amount) {
                        // Get resident balances if resident is selected
                        let residentBalances = null;
                        if (residentId) {
                            try {
                                const residentResponse = await fetch(`?action=get_pending_amount&mode=resident&resident_id=${residentId}`, {
                                    credentials: 'same-origin'
                                });
                                if (residentResponse.ok) {
                                    const residentData = await residentResponse.json();
                                    if (residentData.success && residentData.data) {
                                        residentBalances = {
                                            bank_balance: residentData.data.current_bank_balance,
                                            cash_balance: residentData.data.current_cash_balance,
                                            net_balance: residentData.data.pending_balance
                                        };
                                    }
                                }
                            } catch (error) {
                                console.error('Error fetching resident balances:', error);
                            }
                        }
                        
                        if (paymentMethod === 'cash') {
                            // Show confirmation modal for cash
                            showCashBalanceConfirmation(currentBalance, amount, this, residentBalances);
                        } else {
                            // Show toast notification for bank
                            showBankInsufficientToast(currentBalance, amount, residentBalances);
                        }
                        return;
                    }
                }
                
                // Balance is sufficient, submit the form
                this.submit();
                
            } catch (error) {
                console.error('Error checking balance:', error);
                // If balance check fails, allow form submission
                this.submit();
            }
        });
    }

    // Balance validation for drop/paid back form
    const dropForm = document.getElementById('dropForm');
    
    if (dropForm) {
        dropForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('dropAmount').value);
            const paymentMethod = document.querySelector('#dropForm input[name="payment_method"]:checked')?.value;
            
            if (!amount || !paymentMethod) {
                this.submit(); // Let normal validation handle missing fields
                return;
            }
            
            try {
                // Get resident ID if selected
                const residentSelect = document.getElementById('dropResidentId');
                const residentId = residentSelect ? residentSelect.value : null;
                
                // Get current balance
                let balanceCheckUrl = '?action=check_balance';
                let fetchOptions = {
                    credentials: 'same-origin'
                };
                
                // If resident is selected, check resident-specific balance
                if (residentId) {
                    fetchOptions.method = 'POST';
                    fetchOptions.headers = {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    };
                    fetchOptions.body = `resident_id=${residentId}`;
                }
                
                const response = await fetch(balanceCheckUrl, fetchOptions);
                const data = await response.json();
                
                if (data.success) {
                    const currentBalance = paymentMethod === 'cash' ? data.cash_balance : data.bank_balance;
                    
                    if (currentBalance < amount) {
                        // Get resident balances if resident is selected
                        let residentBalances = null;
                        if (residentId) {
                            try {
                                const residentResponse = await fetch(`?action=get_pending_amount&mode=resident&resident_id=${residentId}`, {
                                    credentials: 'same-origin'
                                });
                                if (residentResponse.ok) {
                                    const residentData = await residentResponse.json();
                                    if (residentData.success && residentData.data) {
                                        residentBalances = {
                                            bank_balance: residentData.data.current_bank_balance,
                                            cash_balance: residentData.data.current_cash_balance,
                                            net_balance: residentData.data.pending_balance
                                        };
                                    }
                                }
                            } catch (error) {
                                console.error('Error fetching resident balances:', error);
                            }
                        }
                        
                        // Show toast notification for both cash and bank insufficient balance
                        if (paymentMethod === 'cash') {
                            showInsufficientBalanceToast('cash', currentBalance, amount, residentBalances);
                        } else {
                            showInsufficientBalanceToast('bank', currentBalance, amount, residentBalances);
                        }
                        return;
                    }
                }
                
                // Balance is sufficient, submit the form
                this.submit();
                
            } catch (error) {
                console.error('Error checking balance:', error);
                // If balance check fails, allow form submission
                this.submit();
            }
        });
    }
});

// Show cash balance confirmation modal
function showCashBalanceConfirmation(currentBalance, requestedAmount, form, residentBalances = null) {
    const modal = document.createElement('div');
    modal.className = 'balance-confirmation-modal';
    modal.style.display = 'flex';
    
    modal.innerHTML = `
        <div class="balance-confirmation-box">
            <div class="balance-warning-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="balance-warning-title">Insufficient Cash Balance</div>
            <div class="balance-warning-message">
                Your current <span style="color:#007bff; font-weight:bold;">cash balance</span> is <span class="balance-amount">£${currentBalance.toFixed(2)}</span>.<br>
                You are trying to spend <span class="balance-amount">£${requestedAmount.toFixed(2)}</span>.<br>
                This will result in a negative balance.
                ${residentBalances ? `<br><br><strong>Current bank balance:</strong> £${residentBalances.bank_balance}<br><strong>Current cash balance:</strong> £${residentBalances.cash_balance}<br><strong>Current Net Balance:</strong> £${residentBalances.net_balance}` : ''}
            </div>
            <div class="balance-modal-buttons">
                <button type="button" class="balance-btn balance-btn-continue" onclick="confirmCashExpense(this)">
                    <i class="fas fa-check"></i> Continue Anyway
                </button>
                <button type="button" class="balance-btn balance-btn-cancel" onclick="cancelCashExpense(this)">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    `;
    
    // Store form reference on modal
    modal.expenseForm = form;
    document.body.appendChild(modal);
}

// Confirm cash expense despite insufficient balance
function confirmCashExpense(button) {
    const modal = button.closest('.balance-confirmation-modal');
    const form = modal.expenseForm;
    document.body.removeChild(modal);
    
    // Submit the form
    form.submit();
}

// Cancel cash expense
function cancelCashExpense(button) {
    const modal = button.closest('.balance-confirmation-modal');
    document.body.removeChild(modal);
}

// Show bank insufficient balance toast
function showBankInsufficientToast(currentBalance, requestedAmount, residentBalances = null) {
    // Remove any existing toast
    const existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    toast.innerHTML = `
        <div class="toast-header">
            <div style="display: flex; align-items: center;">
                <div class="toast-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="toast-title">Insufficient Bank Balance</div>
            </div>
            <button type="button" class="toast-close" onclick="closeToast(this)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="toast-message">
            Your current <span style="color:#007bff; font-weight:bold;">bank balance</span> is <span class="toast-amount">£${currentBalance.toFixed(2)}</span>.<br>
            You cannot spend <span class="toast-amount">£${requestedAmount.toFixed(2)}</span> as it exceeds your available balance.<br>
            Please check your bank balance or use a different payment method.
            ${residentBalances ? `<br><br><strong>Current bank balance:</strong> £${residentBalances.bank_balance}<br><strong>Current cash balance:</strong> £${residentBalances.cash_balance}<br><strong>Current Net Balance:</strong> £${residentBalances.net_balance}` : ''}
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Show toast with animation
    setTimeout(() => toast.classList.add('show'), 100);
    
    // Auto-hide after 8 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            closeToast(toast.querySelector('.toast-close'));
        }
    }, 8000);
}

// Close toast notification
function closeToast(button) {
    const toast = button.closest('.toast-notification');
    toast.classList.remove('show');
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 400);
}

// Show insufficient balance toast for paid back (drop) transactions
function showInsufficientBalanceToast(paymentType, currentBalance, requestedAmount, residentBalances = null) {
    // Remove any existing toast
    const existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    const paymentLabel = paymentType === 'cash' ? 'Cash' : 'Bank';
    const paymentIcon = paymentType === 'cash' ? 'fas fa-money-bill-wave' : 'fas fa-university';
    
    toast.innerHTML = `
        <div class="toast-header">
            <div style="display: flex; align-items: center;">
                <div class="toast-icon">
                    <i class="${paymentIcon}"></i>
                </div>
                <div class="toast-title">Insufficient ${paymentLabel} Balance</div>
            </div>
            <button type="button" class="toast-close" onclick="closeToast(this)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="toast-message">
            Your current <span style="color:#007bff; font-weight:bold;">${paymentType} balance</span> is <span class="toast-amount">£${currentBalance.toFixed(2)}</span>.<br>
            You cannot pay back <span class="toast-amount">£${requestedAmount.toFixed(2)}</span> as it exceeds your available balance.<br>
            Please check your ${paymentType} balance or adjust the amount.
            ${residentBalances ? `<br><br><strong>Current bank balance:</strong> £${residentBalances.bank_balance}<br><strong>Current cash balance:</strong> £${residentBalances.cash_balance}<br><strong>Current Net Balance:</strong> £${residentBalances.net_balance}` : ''}
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Show toast with animation
    setTimeout(() => toast.classList.add('show'), 100);
    
    // Auto-hide after 8 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            closeToast(toast.querySelector('.toast-close'));
        }
    }, 8000);
}

// Show insufficient balance toast for transfer transactions
function showTransferInsufficientBalanceToast(transferMethod, currentBalance, requestedAmount, residentBalances = null) {
    // Remove any existing toast
    const existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    
    let paymentLabel, paymentIcon, sourceType;
    if (transferMethod === 'cash_to_bank') {
        paymentLabel = 'Cash';
        paymentIcon = 'fas fa-money-bill-wave';
        sourceType = 'cash';
    } else {
        paymentLabel = 'Bank';
        paymentIcon = 'fas fa-university';
        sourceType = 'bank';
    }
    
    toast.innerHTML = `
        <div class="toast-header">
            <div style="display: flex; align-items: center;">
                <div class="toast-icon">
                    <i class="${paymentIcon}"></i>
                </div>
                <div class="toast-title">Insufficient ${paymentLabel} Balance for Transfer</div>
            </div>
            <button type="button" class="toast-close" onclick="closeToast(this)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="toast-message">
            Your current <span style="color:#007bff; font-weight:bold;">${sourceType} balance</span> is <span class="toast-amount">£${currentBalance.toFixed(2)}</span>.<br>
            You cannot transfer <span class="toast-amount">£${requestedAmount.toFixed(2)}</span> as it exceeds your available ${sourceType} balance.<br>
            Please check your ${sourceType} balance or adjust the transfer amount.
            ${residentBalances ? `<br><br><strong>Current bank balance:</strong> £${residentBalances.bank_balance}<br><strong>Current cash balance:</strong> £${residentBalances.cash_balance}<br><strong>Current Net Balance:</strong> £${residentBalances.net_balance}` : ''}
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Show toast with animation
    setTimeout(() => toast.classList.add('show'), 100);
    
    // Auto-hide after 8 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            closeToast(toast.querySelector('.toast-close'));
        }
    }, 8000);
}

// Balance validation for transfer form
const transferForm = document.querySelector('form[action=""] input[name="action"][value="transfer"]')?.closest('form');

if (transferForm) {
    transferForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const amount = parseFloat(document.getElementById('transferAmount').value);
        const transferMethod = document.querySelector('input[name="transfer_method"]:checked')?.value;
        const residentId = document.getElementById('transferResidentId').value;
        
        if (!amount || !transferMethod) {
            this.submit(); // Let normal validation handle missing fields
            return;
        }
        
        try {
            // Get current balance for the selected resident (or general balance if no resident selected)
            const response = await fetch('?action=check_balance', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `resident_id=${residentId || ''}`
            });
            
            if (!response.ok) {
                throw new Error('Failed to check balance');
            }
            
            const balanceData = await response.json();
            
            // Check balance based on transfer method
            let currentBalance;
            if (transferMethod === 'cash_to_bank') {
                currentBalance = balanceData.cash_balance;
            } else if (transferMethod === 'bank_to_cash') {
                currentBalance = balanceData.bank_balance;
            }
            
            // Check if there's sufficient balance
            if (currentBalance < amount) {
                // Get resident balances if resident is selected
                let residentBalances = null;
                if (residentId) {
                    try {
                        const residentResponse = await fetch(`?action=get_pending_amount&mode=resident&resident_id=${residentId}`, {
                            credentials: 'same-origin'
                        });
                        if (residentResponse.ok) {
                            const residentData = await residentResponse.json();
                            if (residentData.success && residentData.data) {
                                residentBalances = {
                                    bank_balance: residentData.data.current_bank_balance,
                                    cash_balance: residentData.data.current_cash_balance,
                                    net_balance: residentData.data.pending_balance
                                };
                            }
                        }
                    } catch (error) {
                        console.error('Error fetching resident balances:', error);
                    }
                }
                
                showTransferInsufficientBalanceToast(transferMethod, currentBalance, amount, residentBalances);
                return;
            }
            
            // Balance is sufficient, submit the form
            this.submit();
            
        } catch (error) {
            console.error('Error checking balance:', error);
            // If balance check fails, allow form submission
            this.submit();
        }
    });
}

// Date Range Button Functionality
function setDateRange(period) {
    const startDateElement = document.getElementById('startDate');
    const endDateElement = document.getElementById('endDate');
    
    if (!startDateElement || !endDateElement) return;
    
    const now = new Date();
    const today = now.toISOString().split('T')[0];
    let startDate, endDate;
    
    switch(period) {
        case 'month':
            startDate = new Date(now.getFullYear(), now.getMonth(), 1);
            endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            break;
        case 'year':
            startDate = new Date(now.getFullYear(), 0, 1);
            endDate = new Date(now.getFullYear(), 11, 31);
            break;
        default:
            return;
    }
    
    startDateElement.value = startDate.toISOString().split('T')[0];
    endDateElement.value = endDate.toISOString().split('T')[0];
    
    // Trigger the filter function
    filterTransactions();
}

// Global pagination variables
let currentPage = 1;
let totalPages = 1;
let totalRecords = 0;
let allTransactions = [];

// Modified filter function to support pagination
function filterTransactionsWithPagination() {
    currentPage = 1; // Reset to first page
    filterTransactions();
}

// Update the existing filterTransactions function to handle pagination
function filterTransactionsPaginated() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const transactionType = document.getElementById('transactionType').value;
    const residentId = document.getElementById('transactionResidentId').value;
    
    if (!startDate || !endDate) {
        alert('Please select both start and end dates');
        return;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        alert('Start date cannot be after end date');
        return;
    }
    
    // Show loading state
    const tableBody = document.getElementById('transactionsTableBody');
    tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</td></tr>';
    
    // Build query parameters
    const params = new URLSearchParams({
        action: 'filter_transactions',
        start_date: startDate,
        end_date: endDate,
        page: currentPage,
        per_page: 10
    });
    
    if (transactionType) params.append('type', transactionType);
    if (residentId) params.append('resident_id', residentId);
    
    // Fetch filtered transactions
    fetch(`?${params}`, {
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            allTransactions = data.transactions || [];
            totalPages = data.pagination ? data.pagination.total_pages : 1;
            totalRecords = data.pagination ? data.pagination.total_records : allTransactions.length;
            updateTransactionsTableWithPagination(data.transactions);
            updateTransactionSummary(data.summary);
            updatePaginationControls();
        } else {
            throw new Error(data.error || 'Failed to load transactions');
        }
    })
    .catch(error => {
        console.error('Error filtering transactions:', error);
        tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px; color: #e74c3c;"><i class="fas fa-exclamation-triangle"></i> Error loading transactions: ${error.message}</td></tr>`;
    });
}

// Update transactions table with pagination info
function updateTransactionsTableWithPagination(transactions) {
    const tableBody = document.getElementById('transactionsTableBody');
    const paginationContainer = document.getElementById('paginationContainer');
    
    if (!transactions || transactions.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px;"><i class="fas fa-info-circle"></i> No transactions found for the selected criteria.</td></tr>';
        if (paginationContainer) paginationContainer.style.display = 'none';
        return;
    }
    
    // Show pagination container
    if (paginationContainer) paginationContainer.style.display = 'flex';
    
    tableBody.innerHTML = transactions.map(transaction => `
        <tr>
            <td>${transaction.transaction_date ? transaction.transaction_date : new Date(transaction.created_at).toLocaleDateString('en-CA')}</td>
            <td>
                <span class="transaction-type ${(transaction.display_type || transaction.type)}">
                    <i class="fas fa-${
                        (transaction.display_type || transaction.type) === 'transfer' ? 'exchange-alt' : 
                        ((transaction.display_type || transaction.type) === 'income' ? 'arrow-up' : 'arrow-down')
                    }"></i>
                    ${ (transaction.display_type || transaction.type) === 'income' ? 'Transfer in' : ((transaction.display_type || transaction.type) === 'expense' ? 'Transfer out' : ((transaction.display_type || transaction.type) === 'drop' ? 'Paid back' : ((transaction.display_type || transaction.type || '').charAt ? (transaction.display_type || transaction.type || '').charAt(0).toUpperCase() + (transaction.display_type || transaction.type || '').slice(1) : (transaction.display_type || transaction.type)))) }
                </span>
            </td>
            <td>${transaction.description || '-'}</td>
            <td>${transaction.first_name ? `${transaction.first_name} ${transaction.last_name}` : '<em>General</em>'}</td>
            <td class="amount ${(transaction.display_type || transaction.type)}">
                ${
                    (transaction.display_type || transaction.type) === 'transfer' ? 
                    (transaction.original_type === 'expense' ? '-' : '+') :
                    ((transaction.display_type || transaction.type) === 'income' ? '+' : '-')
                }£${parseFloat(Math.abs(transaction.amount)).toFixed(2)}
            </td>
            <td style="text-transform: capitalize;">
                ${(transaction.display_type || transaction.type) === 'transfer' ? 
                  (transaction.display_payment_method || '').replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase()) :
                  (transaction.original_payment_method || transaction.payment_method)
                }
            </td>
            <td>
                <button class="btn-view-slip" onclick="viewTransactionSlip(${JSON.stringify(transaction).replace(/"/g, '&quot;')})" style="background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                    <i class="fas fa-eye"></i> View
                </button>
            </td>
        </tr>
    `).join('');
}

// Update pagination controls
function updatePaginationControls() {
    const paginationInfo = document.getElementById('paginationInfo');
    const paginationControls = document.getElementById('paginationControls');
    
    if (!paginationInfo || !paginationControls) return;
    
    // Update pagination info
    const startRecord = totalRecords > 0 ? ((currentPage - 1) * 10) + 1 : 0;
    const endRecord = Math.min(currentPage * 10, totalRecords);
    paginationInfo.textContent = `Showing ${startRecord}-${endRecord} of ${totalRecords} transactions`;
    
    // Update pagination controls
    paginationControls.innerHTML = `
        <button onclick="goToPage(1)" ${currentPage === 1 ? 'disabled' : ''} class="pagination-btn">
            <i class="fas fa-angle-double-left"></i>
        </button>
        <button onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} class="pagination-btn">
            <i class="fas fa-angle-left"></i> Previous
        </button>
        <span class="pagination-current">Page ${currentPage} of ${totalPages}</span>
        <button onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} class="pagination-btn">
            Next <i class="fas fa-angle-right"></i>
        </button>
        <button onclick="goToPage(${totalPages})" ${currentPage === totalPages ? 'disabled' : ''} class="pagination-btn">
            <i class="fas fa-angle-double-right"></i>
        </button>
    `;
}

// Go to specific page
function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    filterTransactionsPaginated();
}

// Initialize date range buttons and pagination
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners for date range buttons
    const thisMonthBtn = document.getElementById('thisMonthBtn');
    const thisYearBtn = document.getElementById('thisYearBtn');
    
    if (thisMonthBtn) {
        thisMonthBtn.addEventListener('click', () => setDateRange('month'));
    }
    
    if (thisYearBtn) {
        thisYearBtn.addEventListener('click', () => setDateRange('year'));
    }
    
    // Override the existing filterTransactions function to use pagination
    const originalFilterTransactions = window.filterTransactions;
    window.filterTransactions = filterTransactionsPaginated;
});

    </script>
</body>
</html>














