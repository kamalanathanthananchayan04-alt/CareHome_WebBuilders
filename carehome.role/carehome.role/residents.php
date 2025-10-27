<?php
session_start();

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in and has staff role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../index.php");
    exit();
}

// Database connection function
function getDBConnection() {
    global $dbHost, $dbUser, $dbPass, $dbName;
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_errno) {
        die("Database connection failed: " . $mysqli->connect_error);
    }
    return $mysqli;
}

// Get carehome information for logged-in user
function get_carehome_info($username) {
    $mysqli = getDBConnection();
    
    $sql = "SELECT id, name FROM homes WHERE user_name = ? LIMIT 1";
    $carehome = null;

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("s", $username);

        if ($stmt->execute()) {
            $stmt->bind_result($id, $name);
            
            if ($stmt->fetch()) {
                $carehome = [
                    'id' => $id,
                    'name' => $name
                ];
            }
        }
        $stmt->close();
    }
    
    $mysqli->close();
    return $carehome;
}

// Get residents for specific carehome
function get_residents($home_id) {
    $mysqli = getDBConnection();
    $residents = [];

    $sql = "SELECT * FROM residents WHERE home_id = ? ORDER BY first_name, last_name";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $home_id);

        if ($stmt->execute()) {
            $stmt->store_result();
            $meta = $stmt->result_metadata();
            
            if ($meta) {
                $fields = [];
                $bindParams = [];

                while ($field = $meta->fetch_field()) {
                    $fields[] = $field->name;
                    $bindParams[$field->name] = null;
                }

                $refs = [];
                foreach ($bindParams as $key => &$val) {
                    $refs[] = &$val;
                }
                unset($val);

                call_user_func_array([$stmt, 'bind_result'], $refs);

                while ($stmt->fetch()) {
                    $row = [];
                    foreach ($bindParams as $col => $value) {
                        $row[$col] = $value;
                    }
                    $residents[] = $row;
                }

                $meta->free();
            }
        }
        $stmt->close();
    }

    $mysqli->close();
    return $residents;
}

// Add new resident
function add_resident($resident_data) {
    $mysqli = getDBConnection();
    
    $stmt = $mysqli->prepare("INSERT INTO residents (home_id, first_name, last_name, date_of_birth, gender, nhs_number, nok_name, nok_relationship, nok_email, phone, nok_number, address, medical_conditions, medications, admission_date, room_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("isssssssssssssss",
        $resident_data['home_id'],
        $resident_data['first_name'],
        $resident_data['last_name'],
        $resident_data['date_of_birth'],
        $resident_data['gender'],
        $resident_data['nhs_number'],
        $resident_data['nok_name'],
        $resident_data['nok_relationship'],
        $resident_data['nok_email'],
        $resident_data['phone'],
        $resident_data['nok_number'],
        $resident_data['address'],
        $resident_data['medical_conditions'],
        $resident_data['medications'],
        $resident_data['admission_date'],
        $resident_data['room_number']
    );
    
    $success = $stmt->execute();
    $inserted_id = $stmt->insert_id;
    
    $stmt->close();
    $mysqli->close();
    
    return $success ? $inserted_id : false;
}

// Deactivate resident
function deactivate_resident($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("UPDATE residents SET status = 'deactivated' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    return $success;
}

// Reactivate resident
function reactivate_resident($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("UPDATE residents SET status = 'active' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    return $success;
}

// Delete resident
function delete_resident($id) {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("DELETE FROM residents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    return $success;
}

// Update resident
function update_resident($id, $resident_data) {
    $mysqli = getDBConnection();
    
    $stmt = $mysqli->prepare("UPDATE residents SET first_name = ?, last_name = ?, date_of_birth = ?, gender = ?, nhs_number = ?, nok_name = ?, nok_relationship = ?, nok_email = ?, phone = ?, nok_number = ?, address = ?, medical_conditions = ?, medications = ?, admission_date = ?, room_number = ? WHERE id = ?");
    
    $stmt->bind_param("sssssssssssssssi",
        $resident_data['first_name'],
        $resident_data['last_name'],
        $resident_data['date_of_birth'],
        $resident_data['gender'],
        $resident_data['nhs_number'],
        $resident_data['nok_name'],
        $resident_data['nok_relationship'],
        $resident_data['nok_email'],
        $resident_data['phone'],
        $resident_data['nok_number'],
        $resident_data['address'],
        $resident_data['medical_conditions'],
        $resident_data['medications'],
        $resident_data['admission_date'],
        $resident_data['room_number'],
        $id
    );
    
    $success = $stmt->execute();
    $stmt->close();
    $mysqli->close();
    
    return $success;
}

// Get resident by ID
function get_resident_by_id($id, $home_id) {
    $mysqli = getDBConnection();
    $resident = null;

    $sql = "SELECT * FROM residents WHERE id = ? AND home_id = ? LIMIT 1";

    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("ii", $id, $home_id);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $resident = $result->fetch_assoc();
            }
        }
        $stmt->close();
    }

    $mysqli->close();
    return $resident;
}

// Get resident financial data (FIXED VERSION)
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

    // Calculate total drop
    $drop_query = $mysqli->prepare("SELECT SUM(amount) AS total_drop FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'drop'");
    $drop_query->bind_param("ii", $home_id, $resident_id);
    $drop_query->execute();
    $drop_query->bind_result($total_drop);
    $drop_query->fetch();
    $drop_query->close();

    if (!$total_drop) {
        $total_drop = 0;
    }

    // Net Amount = Income - (Expense + Drop)
    $net_amount = $total_income - ($total_expense + $total_drop);

    // Initialize bank and cash amounts
    $net_bank = 0;
    $net_cash = 0;

    // Attempt to compute Net Bank and Net Cash if payment_method column exists
    // We'll check prepares — if prepare fails, fall back to a split assumption
    $canCalculatePaymentMethod = true;

    // Bank (bank, card, transfer) income and expenses
    $bank_income = 0;
    $bank_expense = 0;
    $cash_income = 0;
    $cash_expense = 0;

    $bankIncomeSql = "SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'income' AND (payment_method = 'bank' OR payment_method = 'card' OR payment_method = 'transfer')";
    $bankExpenseSql = "SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type IN ('expense', 'drop') AND (payment_method = 'bank' OR payment_method = 'card' OR payment_method = 'transfer')";
    $cashIncomeSql = "SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type = 'income' AND payment_method = 'cash'";
    $cashExpenseSql = "SELECT SUM(amount) FROM transactions WHERE home_id = ? AND resident_id = ? AND type IN ('expense', 'drop') AND payment_method = 'cash'";

    if ($stmt = $mysqli->prepare($bankIncomeSql)) {
        $stmt->bind_param("ii", $home_id, $resident_id);
        $stmt->execute();
        $stmt->bind_result($bank_income);
        $stmt->fetch();
        $stmt->close();
        if (!$bank_income) $bank_income = 0;
    } else {
        $canCalculatePaymentMethod = false;
    }

    if ($canCalculatePaymentMethod && ($stmt = $mysqli->prepare($bankExpenseSql))) {
        $stmt->bind_param("ii", $home_id, $resident_id);
        $stmt->execute();
        $stmt->bind_result($bank_expense);
        $stmt->fetch();
        $stmt->close();
        if (!$bank_expense) $bank_expense = 0;
    } else {
        $canCalculatePaymentMethod = false;
    }

    if ($canCalculatePaymentMethod && ($stmt = $mysqli->prepare($cashIncomeSql))) {
        $stmt->bind_param("ii", $home_id, $resident_id);
        $stmt->execute();
        $stmt->bind_result($cash_income);
        $stmt->fetch();
        $stmt->close();
        if (!$cash_income) $cash_income = 0;
    } else {
        $canCalculatePaymentMethod = false;
    }

    if ($canCalculatePaymentMethod && ($stmt = $mysqli->prepare($cashExpenseSql))) {
        $stmt->bind_param("ii", $home_id, $resident_id);
        $stmt->execute();
        $stmt->bind_result($cash_expense);
        $stmt->fetch();
        $stmt->close();
        if (!$cash_expense) $cash_expense = 0;
    } else {
        $canCalculatePaymentMethod = false;
    }

    if ($canCalculatePaymentMethod) {
        $net_bank = $bank_income - $bank_expense;
        $net_cash = $cash_income - $cash_expense;
    } else {
        // Fallback: split net amount (70% bank, 30% cash) — same fallback as residents.php
        $net_bank = $net_amount * 0.7;
        $net_cash = $net_amount * 0.3;
    }

    $mysqli->close();
    
    return [
        'total_income' => $total_income,
        'total_expense' => $total_expense,
        'total_drop' => $total_drop,
        'net_amount' => $net_amount,
        'net_bank' => $net_bank,
        'net_cash' => $net_cash,
        'formatted_amount' => number_format($net_amount, 2),
        'formatted_bank' => number_format($net_bank, 2),
        'formatted_cash' => number_format($net_cash, 2)
    ];
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_resident') {
    $carehome_info = get_carehome_info($_SESSION['username']);
    
    if ($carehome_info) {
        // Merge optional title with first name so the listing shows the title
        $first_name = trim($_POST['firstName']);
        $title = trim($_POST['title'] ?? '');
        if (!empty($title)) {
            $first_name = $title . ' ' . $first_name;
        }

        $resident_data = [
            'home_id' => $carehome_info['id'],
            'first_name' => $first_name,
            'last_name' => trim($_POST['lastName']),
            'date_of_birth' => $_POST['dateOfBirth'],
            'gender' => $_POST['gender'],
            'nhs_number' => trim($_POST['nhsNumber']),
            'nok_name' => trim($_POST['nokName']),
            'nok_relationship' => trim($_POST['nokRelationship']),
            'nok_email' => trim($_POST['nokEmail']),
            'phone' => trim($_POST['phone']),
            'nok_number' => trim($_POST['nokNumber']),
            'address' => trim($_POST['address']),
            'medical_conditions' => trim($_POST['medicalConditions']),
            'medications' => trim($_POST['medications']),
            'admission_date' => $_POST['admissionDate'],
            'room_number' => trim($_POST['roomNumber'])
        ];
        
        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'date_of_birth', 'gender', 'nhs_number', 'nok_name', 'phone', 'admission_date', 'room_number'];
        $valid = true;
        
        foreach ($required_fields as $field) {
            if (empty($resident_data[$field])) {
                $valid = false;
                break;
            }
        }
        
        if ($valid) {
            $result = add_resident($resident_data);
            if ($result) {
                $message = "Resident added successfully!";
                $message_type = "success";
            } else {
                $message = "Error adding resident. Please try again.";
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields.";
            $message_type = "error";
        }
    } else {
        $message = "Error: Unable to verify care home information.";
        $message_type = "error";
    }
}

// Handle edit resident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_resident') {
    $carehome_info = get_carehome_info($_SESSION['username']);
    $resident_id = $_POST['resident_id'] ?? null;
    
    if ($carehome_info && $resident_id && is_numeric($resident_id)) {
        // Merge optional title with first name so the listing shows the title
        $first_name = trim($_POST['firstName']);
        $title = trim($_POST['title'] ?? '');
        if (!empty($title)) {
            $first_name = $title . ' ' . $first_name;
        }

        $resident_data = [
            'first_name' => $first_name,
            'last_name' => trim($_POST['lastName']),
            'date_of_birth' => $_POST['dateOfBirth'],
            'gender' => $_POST['gender'],
            'nhs_number' => trim($_POST['nhsNumber']),
            'nok_name' => trim($_POST['nokName']),
            'nok_relationship' => trim($_POST['nokRelationship']),
            'nok_email' => trim($_POST['nokEmail']),
            'phone' => trim($_POST['phone']),
            'nok_number' => trim($_POST['nokNumber']),
            'address' => trim($_POST['address']),
            'medical_conditions' => trim($_POST['medicalConditions']),
            'medications' => trim($_POST['medications']),
            'admission_date' => $_POST['admissionDate'],
            'room_number' => trim($_POST['roomNumber'])
        ];
        
        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'date_of_birth', 'gender', 'nhs_number', 'nok_name', 'phone', 'admission_date', 'room_number'];
        $valid = true;
        
        foreach ($required_fields as $field) {
            if (empty($resident_data[$field])) {
                $valid = false;
                break;
            }
        }
        
        if ($valid) {
            $result = update_resident($resident_id, $resident_data);
            if ($result) {
                $message = "Resident updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating resident. Please try again.";
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields.";
            $message_type = "error";
        }
    } else {
        $message = "Error: Invalid resident ID or care home information.";
        $message_type = "error";
    }
}

// Handle deactivate/reactivate/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $resident_id = $_POST['resident_id'] ?? null;
    
    if ($resident_id && is_numeric($resident_id)) {
        if ($action === 'deactivate_resident') {
            $result = deactivate_resident($resident_id);
            if ($result) {
                $message = "Resident deactivated successfully!";
                $message_type = "success";
                header("Location: residents.php?message=deactivated");
                exit();
            } else {
                $message = "Error deactivating resident.";
                $message_type = "error";
            }
        } elseif ($action === 'reactivate_resident') {
            $result = reactivate_resident($resident_id);
            if ($result) {
                $message = "Resident reactivated successfully!";
                $message_type = "success";
                header("Location: residents.php?message=reactivated");
                exit();
            } else {
                $message = "Error reactivating resident.";
                $message_type = "error";
            }
        } elseif ($action === 'delete_resident') {
            $result = delete_resident($resident_id);
            if ($result) {
                $message = "Resident deleted successfully!";
                $message_type = "success";
                header("Location: residents.php?message=deleted");
                exit();
            } else {
                $message = "Error deleting resident.";
                $message_type = "error";
            }
        }
    }
}

// Handle transaction API requests
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'get_resident_transactions') {
        $resident_id = isset($_GET['resident_id']) ? intval($_GET['resident_id']) : 0;
        $home_id = isset($_GET['home_id']) ? intval($_GET['home_id']) : 0;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'DESC';
        $month = isset($_GET['month']) ? $_GET['month'] : '';
        
        $limit = 10;
        $offset = ($page - 1) * $limit;
        
        $mysqli = getDBConnection();
        
        // Calculate previous month balance
        $prev_balance = 0;
        if ($month) {
            $prev_month_sql = "SELECT 
                COALESCE(SUM(CASE 
                    WHEN type = 'income' THEN amount 
                    WHEN type IN ('expense', 'drop') THEN -amount 
                    ELSE 0 
                END), 0) as balance
                FROM transactions 
                WHERE home_id = ? AND resident_id = ? AND DATE(COALESCE(transaction_date, created_at)) < ?";
            $first_day = $month . '-01';
            
            if ($stmt = $mysqli->prepare($prev_month_sql)) {
                $stmt->bind_param("iis", $home_id, $resident_id, $first_day);
                $stmt->execute();
                $stmt->bind_result($prev_balance);
                $stmt->fetch();
                $stmt->close();
            }
        }
        
        // Build WHERE clause for date filtering
        $where_clause = "WHERE home_id = ? AND resident_id = ?";
        $params = [$home_id, $resident_id];
        $param_types = "ii";
        
        if ($month) {
            $where_clause .= " AND DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') = ?";
            $params[] = $month;
            $param_types .= "s";
        }
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM transactions $where_clause";
        $total_records = 0;
        
        if ($stmt = $mysqli->prepare($count_sql)) {
            if ($params) {
                $stmt->bind_param($param_types, ...$params);
            }
            $stmt->execute();
            $stmt->bind_result($total_records);
            $stmt->fetch();
            $stmt->close();
        }
        
        // Get transactions
        $sql = "SELECT 
                    id, type, amount, description, payment_method, reference_no, 
                    created_by, created_at, transaction_date,
                    DATE_FORMAT(COALESCE(transaction_date, created_at), '%d/%m/%Y') as formatted_date,
                    amount as drop_amount
                FROM transactions 
                $where_clause 
                ORDER BY COALESCE(transaction_date, created_at) $sort, id $sort 
                LIMIT ? OFFSET ?";
        
        $transactions = [];
        
        if ($stmt = $mysqli->prepare($sql)) {
            $param_types .= "ii";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            
            $stmt->bind_result($id, $type, $amount, $description, $payment_method, 
                             $reference_no, $created_by, $created_at, $transaction_date, $formatted_date, $drop_amount);
            
            while ($stmt->fetch()) {
                $amount_class = '';
                $formatted_amount = '';
                
                if ($type === 'income') {
                    $amount_class = 'income';
                    $formatted_amount = '+£' . number_format($amount, 2);
                } elseif ($type === 'expense') {
                    $amount_class = 'expense';
                    $formatted_amount = '-£' . number_format($amount, 2);
                } elseif ($type === 'drop') {
                    $amount_class = 'drop';
                    $formatted_amount = '-£' . number_format($drop_amount, 2);
                }
                
                $transactions[] = [
                    'id' => $id,
                    'type' => $type,
                    'amount' => $amount,
                    'drop_amount' => $drop_amount,
                    'description' => $description,
                    'payment_method' => $payment_method,
                    'reference_no' => $reference_no,
                    'created_by' => $created_by,
                    'created_at' => $created_at,
                    'formatted_date' => $formatted_date,
                    'amount_class' => $amount_class,
                    'formatted_amount' => $formatted_amount
                ];
            }
            $stmt->close();
        }
        
        // Get monthly summaries
        $summary_sql = "SELECT 
                           DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') as month,
                           DATE_FORMAT(COALESCE(transaction_date, created_at), '%M %Y') as month_name,
                           SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                           SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
                           SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END) as total_drop,
                           COUNT(*) as total_transactions
                       FROM transactions 
                       WHERE home_id = ? AND resident_id = ?
                       GROUP BY DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m')
                       ORDER BY month DESC";
        
        $monthly_summaries = [];
        
        if ($stmt = $mysqli->prepare($summary_sql)) {
            $stmt->bind_param("ii", $home_id, $resident_id);
            $stmt->execute();
            $stmt->bind_result($month_key, $month_name, $total_income, $total_expense, $total_drop, $total_transactions);
            
            while ($stmt->fetch()) {
                $net_amount = $total_income - $total_expense - $total_drop;
                $monthly_summaries[] = [
                    'month' => $month_key,
                    'month_name' => $month_name,
                    'total_income' => $total_income,
                    'total_expense' => $total_expense,
                    'total_drop' => $total_drop,
                    'net_amount' => $net_amount,
                    'total_transactions' => $total_transactions,
                    'formatted_income' => number_format($total_income, 2),
                    'formatted_expense' => number_format($total_expense, 2),
                    'formatted_drop' => number_format($total_drop, 2),
                    'formatted_net' => number_format($net_amount, 2)
                ];
            }
            $stmt->close();
        }
        
        $mysqli->close();
        
        $response = [
            'success' => true,
            'transactions' => $transactions,
            'pagination' => [
                'current_page' => $page,
                'total_records' => $total_records,
                'total_pages' => ceil($total_records / $limit),
                'has_next' => $page < ceil($total_records / $limit),
                'has_prev' => $page > 1
            ],
            'monthly_summaries' => $monthly_summaries,
            'previous_balance' => $prev_balance ?? 0,
            'selected_month' => $month,
            'current_month' => $month
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    if ($action === 'export_resident_transactions') {
        $resident_id = isset($_GET['resident_id']) ? intval($_GET['resident_id']) : 0;
        $home_id = isset($_GET['home_id']) ? intval($_GET['home_id']) : 0;
        $month = isset($_GET['month']) ? $_GET['month'] : '';
        
        $mysqli = getDBConnection();
        
        // Build WHERE clause for date filtering
        $where_clause = "WHERE home_id = ? AND resident_id = ?";
        $params = [$home_id, $resident_id];
        $param_types = "ii";
        
        if ($month) {
            $where_clause .= " AND DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') = ?";
            $params[] = $month;
            $param_types .= "s";
        }
        
        $sql = "SELECT 
                    DATE_FORMAT(COALESCE(transaction_date, created_at), '%d/%m/%Y %H:%i') as date_time,
                    type, description, payment_method, amount, reference_no, created_by
                FROM transactions 
                $where_clause 
                ORDER BY COALESCE(transaction_date, created_at) DESC";
        
        $transactions = [];
        
        if ($stmt = $mysqli->prepare($sql)) {
            if ($params) {
                $stmt->bind_param($param_types, ...$params);
            }
            $stmt->execute();
            $stmt->bind_result($date_time, $type, $description, $payment_method, $amount, $reference_no, $created_by);
            
            while ($stmt->fetch()) {
                $transactions[] = [
                    $date_time,
                    strtoupper($type),
                    $description,
                    strtoupper($payment_method),
                    number_format($amount, 2),
                    $reference_no,
                    $created_by
                ];
            }
            $stmt->close();
        }
        
        $mysqli->close();
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="resident_transactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date & Time', 'Type', 'Description', 'Payment Method', 'Amount', 'Reference', 'Created By']);
        
        foreach ($transactions as $transaction) {
            fputcsv($output, $transaction);
        }
        
        fclose($output);
        exit();
    }
}

// Check for success message from redirect
if (isset($_GET['message'])) {
    if ($_GET['message'] == 'deactivated') {
        $message = "Resident deactivated successfully!";
        $message_type = "success";
    } elseif ($_GET['message'] == 'reactivated') {
        $message = "Resident reactivated successfully!";
        $message_type = "success";
    } elseif ($_GET['message'] == 'deleted') {
        $message = "Resident deleted successfully!";
        $message_type = "success";
    }
}

// Get carehome information for display
$carehome_info = get_carehome_info($_SESSION['username']);
$carehome_name = $carehome_info ? $carehome_info['name'] : 'CareHome';
$home_id = $carehome_info ? $carehome_info['id'] : null;

// Get residents for this carehome
$residents = $home_id ? get_residents($home_id) : [];

// Pre-calculate financial data for all residents
$resident_financial_data = [];
if ($residents) {
    foreach ($residents as $resident) {
        $resident_financial_data[$resident['id']] = getResidentFinancialData($resident['id'], $home_id);
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
        }

        .menu-item a:hover, .menu-item.active a {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 4px solid #e74c3c;
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

        /* Sub-content navigation */
        .sub-content-nav {
            display: flex;
            background: white;
            border-radius: 8px;
            padding: 5px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
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
            text-decoration: none;
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

        /* Table controls */
        .residents-controls {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
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
            min-width: 200px;
        }

        .room-filter-container input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            box-sizing: border-box;
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
            border-bottom: 1px solid #f1f1f1;
        }

        .room-option:hover, .room-option.selected {
            background-color: #f8f9fa;
        }

        .room-option:last-child {
            border-bottom: none;
        }

        /* Table styles */
        .residents-table-container {
            overflow-x: auto;
            border-radius: 5px;
            border: 1px solid #eee;
        }

        .residents-table {
            width: 100%;
            border-collapse: collapse;
        }

        .residents-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
        }

        .residents-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .residents-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .resident-info {
            display: flex;
            align-items: center;
            gap: 10px;
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
        }

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
        
        .btn-transactions {
            background: #9b59b6;
            color: white;
        }
        
        .btn-transactions:hover {
            background: #8e44ad;
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

        /* Styled Confirmation Dialog */
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
            z-index: 10000;
        }

        .confirmation-dialog {
            background: white;
            border-radius: 10px;
            padding: 0;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            animation: fadeInScale 0.3s ease-out;
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .confirmation-header {
            background: #3498db;
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }

        .confirmation-icon {
            font-size: 3em;
            margin-bottom: 10px;
        }

        .confirmation-title {
            margin: 0;
            font-size: 1.2em;
            font-weight: bold;
        }

        .confirmation-body {
            padding: 20px;
        }

        .confirmation-message {
            margin: 0 0 20px 0;
            text-align: center;
            color: #333;
            line-height: 1.5;
        }

        .confirmation-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .confirmation-actions .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }

        .confirmation-actions .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .confirmation-actions .btn-secondary:hover {
            background: #7f8c8d;
        }

        .confirmation-actions .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .confirmation-actions .btn-danger:hover {
            background: #c0392b;
        }

        .confirmation-actions .btn-warning {
            background: #f39c12;
            color: white;
        }

        .confirmation-actions .btn-warning:hover {
            background: #e67e22;
        }

        .confirmation-actions .btn-success {
            background: #27ae60;
            color: white;
        }

        .confirmation-actions .btn-success:hover {
            background: #219a52;
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

        /* Mobile card view for residents */
        .residents-card-view {
            display: none;
            flex-direction: column;
            gap: 15px;
        }

        .resident-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #eee;
        }

        .resident-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .resident-card-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2c3e50;
        }

        .resident-card-id {
            font-size: 0.8rem;
            color: #7f8c8d;
        }

        .resident-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .resident-card-detail {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #7f8c8d;
            margin-bottom: 3px;
        }

        .detail-value {
            font-weight: 500;
            color: #2c3e50;
        }

        .resident-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }

        .resident-card-financial {
            display: flex;
            gap: 15px;
        }

        .financial-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .financial-label {
            font-size: 0.7rem;
            color: #7f8c8d;
        }

        .financial-value {
            font-weight: 600;
            color: #2c3e50;
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
            .residents-table-container {
                overflow-x: auto;
            }
            
            .residents-table {
                min-width: 1000px;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-header h2 span, .menu-item a span {
                display: inline;
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
            
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .residents-table-container {
                display: none;
            }
            
            .residents-card-view {
                display: flex;
            }
            
            .notification-container {
                max-width: 300px;
                right: 10px;
                top: 70px;
            }
            
             .dashboard-container{
                margin-top: 60px;
            }
        }

        @media (max-width: 768px) {
            .residents-controls {
                flex-direction: column;
            }
            
            .filter-controls {
                flex-direction: column;
                width: 100%;
            }
            
            .filter-controls select {
                min-width: 100%;
            }
            
            .residents-table {
                font-size: 0.9rem;
            }
            
            .residents-table th, .residents-table td {
                padding: 10px 5px;
            }
            
            .resident-card-details {
                grid-template-columns: 1fr;
            }
            
            .resident-card-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .resident-card-financial {
                width: 100%;
                justify-content: space-between;
            }
            
            .content-area {
                padding: 20px;
            }
            
            .sub-content-body {
                padding: 20px;
            }
        }

        @media (max-width: 576px) {
            .content-area {
                padding: 15px;
            }
            
            .main-header {
                padding: 15px;
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .sub-content-nav {
                flex-direction: column;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .sub-content-body {
                padding: 15px;
            }
            
            .page-header h2 {
                font-size: 1.5rem;
            }
            
            .main-header h1 {
                font-size: 1.2rem;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
            
            .resident-card {
                padding: 15px;
            }
            
            .resident-card-name {
                font-size: 1.1rem;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
            
            .sub-content-header {
                padding: 15px;
                font-size: 1.1rem;
            }
            
            .main-header {
                padding: 10px 15px;
            }
            
            .user-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- NEW: Notification Container -->
    <div class="notification-container" id="notificationContainer"></div>

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
                <li class="menu-item active">
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
                <h1><i class="fas fa-users"></i> Residents Management - <?php echo htmlspecialchars($carehome_name); ?></h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>  
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>

            <div class="content-area">
                <div class="page-header">
                    <h2>Resident Management System</h2>
                    <p>Manage resident information, admissions, and records</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- Sub-content Navigation -->
                <div class="sub-content-nav">
                    <button class="sub-nav-btn active" data-target="add-resident">
                        <i class="fas fa-user-plus"></i>
                        Add Resident
                    </button>
                    <button class="sub-nav-btn" data-target="list-residents">
                        <i class="fas fa-list"></i>
                        List of Residents
                    </button>
                </div>

                <!-- Add Resident Sub-content -->
                <div id="add-resident" class="sub-content active">
                    <div class="sub-content-header">
                        <i class="fas fa-user-plus"></i>
                        Add New Resident
                    </div>
                    <div class="sub-content-body">
                        <form class="resident-form" id="residentForm" method="POST" action="">
                            <input type="hidden" name="action" value="add_resident">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="title">
                                        <i class="fas fa-id-badge"></i>
                                        Title (optional)
                                    </label>
                                    <select id="title" name="title">
                                        <option value="">None</option>
                                        <option value="Mr">Mr</option>
                                        <option value="Mrs">Mrs</option>
                                        <option value="Ms">Ms</option>
                                        <option value="Dr">Dr</option>
                                        <option value="Lord">Lord</option>
                                    </select>

                                    <label for="firstName" style="margin-top:10px;">
                                        <i class="fas fa-user"></i>
                                        First Name
                                    </label>
                                    <input type="text" id="firstName" name="firstName" required>
                                </div>
                                <div class="form-group">
                                    <label for="lastName">
                                        <i class="fas fa-user"></i>
                                        Last Name
                                    </label>
                                    <input type="text" id="lastName" name="lastName" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="dateOfBirth">
                                        <i class="fas fa-calendar"></i>
                                        Date of Birth
                                    </label>
                                    <input type="date" id="dateOfBirth" name="dateOfBirth" required>
                                </div>
                                <div class="form-group">
                                    <label for="gender">
                                        <i class="fas fa-venus-mars"></i>
                                        Gender
                                    </label>
                                    <select id="gender" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nhsNumber">
                                        <i class="fas fa-id-card"></i>
                                        NHS Number
                                    </label>
                                    <input type="text" id="nhsNumber" name="nhsNumber" pattern="\d{10}" title="Enter 10 digit NHS Number" required>
                                </div>
                                <div class="form-group">
                                    <label for="nokName">
                                        <i class="fas fa-user-friends"></i>
                                        NOK Name
                                    </label>
                                    <input type="text" id="nokName" name="nokName" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="phone">
                                        <i class="fas fa-phone"></i>
                                        Phone Number
                                    </label>
                                    <input type="tel" id="phone" name="phone" required>
                                </div>
                                <div class="form-group">
                                    <label for="nokEmail">
                                        <i class="fas fa-envelope"></i>
                                        NOK Email Address
                                    </label>
                                    <input type="email" id="nokEmail" name="nokEmail" placeholder="next.of.kin@email.com">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="nokNumber">
                                        <i class="fas fa-phone-alt"></i>
                                        NOK Phone Number
                                    </label>
                                    <input type="tel" id="nokNumber" name="nokNumber" required>
                                </div>
                                <div class="form-group">
                                    <label for="nokRelationship">
                                        <i class="fas fa-heart"></i>
                                        NOK Relationship
                                    </label>
                                    <input type="text" id="nokRelationship" name="nokRelationship" placeholder="e.g., Son, Daughter, Spouse" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="address">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Address
                                </label>
                                <textarea id="address" name="address" rows="3"></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="medicalConditions">
                                        <i class="fas fa-heartbeat"></i>
                                        Medical Conditions
                                    </label>
                                    <textarea id="medicalConditions" name="medicalConditions" rows="2" placeholder="Any existing medical conditions..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="medications">
                                        <i class="fas fa-pills"></i>
                                        Current Medications
                                    </label>
                                    <textarea id="medications" name="medications" rows="2" placeholder="Current medications..."></textarea>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="admissionDate">
                                        <i class="fas fa-calendar-check"></i>
                                        Admission Date
                                    </label>
                                    <input type="date" id="admissionDate" name="admissionDate" required>
                                </div>
                                <div class="form-group">
                                    <label for="roomNumber">
                                        <i class="fas fa-bed"></i>
                                        Room Number
                                    </label>
                                    <input type="text" id="roomNumber" name="roomNumber" required>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Add Resident
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- List of Residents Sub-content -->
                <div id="list-residents" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-list"></i>
                        List of Residents
                    </div>
                    <div class="sub-content-body">
                        <div class="residents-controls">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" placeholder="Search residents..." id="searchResidents">
                            </div>
                            <div class="filter-controls">
                                <div class="room-filter-container">
                                    <input type="text" name="room_filter" id="roomFilterInput" placeholder="Type room number..." 
                                           autocomplete="off">
                                    <div class="room-dropdown" id="roomDropdown">
                                        <div class="room-option" data-room="">All Rooms</div>
                                        <?php
                                        // Get unique room numbers for this carehome
                                        $rooms = [];
                                        foreach ($residents as $resident) {
                                            if (!empty($resident['room_number']) && !in_array($resident['room_number'], $rooms)) {
                                                $rooms[] = $resident['room_number'];
                                                echo '<div class="room-option" data-room="' . htmlspecialchars($resident['room_number']) . '">Room ' . htmlspecialchars($resident['room_number']) . '</div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Desktop Table View -->
                        <div class="residents-table-container">
                            <table class="residents-table">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-user"></i> Name</th>
                                        <th><i class="fas fa-calendar"></i> Age</th>
                                        <th><i class="fas fa-bed"></i> Room</th>
                                        <th><i class="fas fa-university"></i> Net Bank</th>
                                        <th><i class="fas fa-money-bill-wave"></i> Net Cash</th>
                                        <th><i class="fas fa-wallet"></i> Net Amount</th>
                                        <th><i class="fas fa-cogs"></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="residentsTableBody">
                                    <?php if (empty($residents)): ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 20px;">
                                                <i class="fas fa-info-circle"></i> No residents found. Add your first resident above.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($residents as $resident): ?>
                                            <?php
                                            // Calculate age from date of birth
                                            $age = 'N/A';
                                            if (!empty($resident['date_of_birth'])) {
                                                $birth_date = new DateTime($resident['date_of_birth']);
                                                $today = new DateTime();
                                                $age = $birth_date->diff($today)->y;
                                            }
                                            
                                            // Format admission date
                                            $admission_date = 'N/A';
                                            if (!empty($resident['admission_date'])) {
                                                $admission_date = date('M j, Y', strtotime($resident['admission_date']));
                                            }
                                            
                                            // Get financial data for this resident
                                            $financial_data = isset($resident_financial_data[$resident['id']]) ? $resident_financial_data[$resident['id']] : ['formatted_amount' => '0.00'];
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="resident-info">
                                                        <div class="resident-avatar">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                       <div style="line-height: 1.5; font-family: Arial, sans-serif;">
  <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong>
  <?php if (isset($resident['status']) && $resident['status'] === 'deactivated'): ?>
      <span class="status-badge deactivated">Deactivated</span>
  <?php endif; ?>
  <br>
  <small style="color: #666;">ID: RES<?php echo str_pad($resident['id'] ?? '0', 3, '0', STR_PAD_LEFT); ?></small>
</div>

                                                    </div>
                                                </td>
                                                <td><?php echo $age; ?></td>
                                                <td>Room <?php echo htmlspecialchars($resident['room_number'] ?? 'N/A'); ?></td>
                                                <td><strong>£<?php echo isset($financial_data['formatted_bank']) ? $financial_data['formatted_bank'] : number_format(0,2); ?></strong></td>
                                                <td><strong>£<?php echo isset($financial_data['formatted_cash']) ? $financial_data['formatted_cash'] : number_format(0,2); ?></strong></td>
                                                <td><strong>£<?php echo $financial_data['formatted_amount']; ?></strong></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn-action btn-view" title="View Details" onclick="viewResidentDetails(<?php echo htmlspecialchars(json_encode($resident + $financial_data)); ?>)">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        
                                                        <button type="button" class="btn-action btn-transactions" title="View Transactions" onclick="viewResidentTransactions(<?php echo $resident['id']; ?>, '<?php echo addslashes($resident['first_name'] . ' ' . $resident['last_name']); ?>', <?php echo $home_id; ?>)">
                                                            <i class="fas fa-exchange-alt"></i>
                                                        </button>
                                                        
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

                        <!-- Mobile Card View -->
                        <div class="residents-card-view" id="residentsCardView">
                            <?php if (empty($residents)): ?>
                                <div style="text-align: center; padding: 20px;">
                                    <i class="fas fa-info-circle"></i> No residents found. Add your first resident above.
                                </div>
                            <?php else: ?>
                                <?php foreach ($residents as $resident): ?>
                                    <?php
                                    // Calculate age from date of birth
                                    $age = 'N/A';
                                    if (!empty($resident['date_of_birth'])) {
                                        $birth_date = new DateTime($resident['date_of_birth']);
                                        $today = new DateTime();
                                        $age = $birth_date->diff($today)->y;
                                    }
                                    
                                    // Format admission date
                                    $admission_date = 'N/A';
                                    if (!empty($resident['admission_date'])) {
                                        $admission_date = date('M j, Y', strtotime($resident['admission_date']));
                                    }
                                    
                                    // Get financial data for this resident
                                    $financial_data = isset($resident_financial_data[$resident['id']]) ? $resident_financial_data[$resident['id']] : ['formatted_amount' => '0.00'];
                                    ?>
                                    <div class="resident-card">
                                        <div class="resident-card-header">
                                            <div>
                                                <div class="resident-card-name">
                                                    <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?>
                                                    <?php if (isset($resident['status']) && $resident['status'] === 'deactivated'): ?>
                                                        <span class="status-badge deactivated">Deactivated</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="resident-card-id">ID: RES<?php echo str_pad($resident['id'] ?? '0', 3, '0', STR_PAD_LEFT); ?></div>
                                            </div>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
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
                                        <div class="resident-card-details">
                                            <div class="resident-card-detail">
                                                <span class="detail-label">Age</span>
                                                <span class="detail-value"><?php echo $age; ?></span>
                                            </div>
                                            <div class="resident-card-detail">
                                                <span class="detail-label">Room</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($resident['room_number'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="resident-card-detail">
                                                <span class="detail-label">Contact</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($resident['phone'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="resident-card-detail">
                                                <span class="detail-label">Admission</span>
                                                <span class="detail-value"><?php echo $admission_date; ?></span>
                                            </div>
                                        </div>
                                        <div class="resident-card-footer">
                                            <div class="resident-card-financial">
                                                <div class="financial-item">
                                                    <span class="financial-label">Bank</span>
                                                    <span class="financial-value">£<?php echo isset($financial_data['formatted_bank']) ? $financial_data['formatted_bank'] : number_format(0,2); ?></span>
                                                </div>
                                                <div class="financial-item">
                                                    <span class="financial-label">Cash</span>
                                                    <span class="financial-value">£<?php echo isset($financial_data['formatted_cash']) ? $financial_data['formatted_cash'] : number_format(0,2); ?></span>
                                                </div>
                                                <div class="financial-item">
                                                    <span class="financial-label">Total</span>
                                                    <span class="financial-value">£<?php echo $financial_data['formatted_amount']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Hidden Forms for Actions -->
    <form id="deactivateResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="resident_id" id="deactivateResidentId">
        <input type="hidden" name="action" value="deactivate_resident">
    </form>

    <form id="reactivateResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="resident_id" id="reactivateResidentId">
        <input type="hidden" name="action" value="reactivate_resident">
    </form>

    <form id="deleteResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="resident_id" id="deleteResidentId">
        <input type="hidden" name="action" value="delete_resident">
    </form>

    <script>
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

        // Sub-content navigation
        document.querySelectorAll('.sub-nav-btn').forEach(button => {
            button.addEventListener('click', () => {
                // Remove active class from all buttons and content
                document.querySelectorAll('.sub-nav-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                document.querySelectorAll('.sub-content').forEach(content => {
                    content.classList.remove('active');
                });
                
                // Add active class to clicked button and corresponding content
                button.classList.add('active');
                const targetId = button.getAttribute('data-target');
                document.getElementById(targetId).classList.add('active');
            });
        });

        // Search functionality
        document.getElementById('searchResidents').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            // Filter table rows
            const tableRows = document.querySelectorAll('#residentsTableBody tr');
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
            
            // Filter cards
            const cards = document.querySelectorAll('.resident-card');
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                card.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Setup Room Filter Dropdown
        function setupRoomFilter() {
            const input = document.getElementById('roomFilterInput');
            const dropdown = document.getElementById('roomDropdown');
            
            if (!input || !dropdown) return;

            input.addEventListener('focus', () => {
                dropdown.classList.add('show');
                filterRoomOptions();
            });

            input.addEventListener('input', () => {
                filterRoomOptions();
                performRoomFilter();
            });

            input.addEventListener('blur', (e) => {
                // Delay hiding to allow clicking on options
                setTimeout(() => {
                    if (!dropdown.contains(document.activeElement)) {
                        dropdown.classList.remove('show');
                    }
                }, 100);
            });

            // Handle option clicks
            dropdown.addEventListener('click', (e) => {
                if (e.target.classList.contains('room-option')) {
                    const roomValue = e.target.getAttribute('data-room');
                    input.value = roomValue;
                    dropdown.classList.remove('show');
                    
                    // Remove selected class from all options
                    dropdown.querySelectorAll('.room-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    
                    // Add selected class to clicked option
                    e.target.classList.add('selected');
                    
                    performRoomFilter();
                }
            });

            function filterRoomOptions() {
                const searchTerm = input.value.toLowerCase();
                const options = dropdown.querySelectorAll('.room-option');
                
                options.forEach(option => {
                    const text = option.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                });
            }

            function performRoomFilter() {
                // Use the combined filter function instead
                if (typeof performCombinedFilter === 'function') {
                    performCombinedFilter();
                }
            }
        }

        // DOM elements
        const subNavButtons = document.querySelectorAll('.sub-nav-btn');
        const subContents = document.querySelectorAll('.sub-content');
        const searchInput = document.getElementById('searchResidents');
        const roomFilter = document.getElementById('filterRoom');

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

        // Combined search and room filter function
        function performCombinedFilter() {
            const searchTerm = (searchInput && searchInput.value) ? searchInput.value.toLowerCase() : '';
            const roomInput = document.getElementById('roomFilterInput');
            const roomValue = roomInput ? roomInput.value : '';
            
            const rows = document.querySelectorAll('#residentsTableBody tr');
            
            rows.forEach(row => {
                if (row.querySelector('td')) {
                    const nameEl = row.querySelector('td:first-child strong');
                    const roomEl = row.querySelector('td:nth-child(3)'); // Room is still the 3rd column

                    const name = nameEl ? nameEl.textContent.toLowerCase() : '';
                    const room = roomEl ? roomEl.textContent.toLowerCase() : '';

                    const matchesSearch = name.includes(searchTerm) || room.includes(searchTerm);
                    const matchesRoom = roomValue === '' || room.includes(roomValue.toLowerCase());

                    row.style.display = (matchesSearch && matchesRoom) ? '' : 'none';
                }
            });
            
            // Also filter cards
            const cards = document.querySelectorAll('.resident-card');
            cards.forEach(card => {
                const nameEl = card.querySelector('.resident-card-name');
                const roomEl = card.querySelector('.resident-card-detail:nth-child(2) .detail-value');
                
                const name = nameEl ? nameEl.textContent.toLowerCase() : '';
                const room = roomEl ? roomEl.textContent.toLowerCase() : '';
                
                const matchesSearch = name.includes(searchTerm) || room.includes(searchTerm);
                const matchesRoom = roomValue === '' || room.includes(roomValue.toLowerCase());
                
                card.style.display = (matchesSearch && matchesRoom) ? '' : 'none';
            });
        }

        // Search event listener  
        if (searchInput) searchInput.addEventListener('input', performCombinedFilter);

        // View resident details function
        function viewResidentDetails(resident) {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-user"></i> Resident Details</h3>
                        <button class="modal-close" onclick="closeModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="resident-details">
                            <div class="detail-section">
                                <h4><i class="fas fa-id-card"></i> Personal Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <strong>Name:</strong> ${resident.first_name} ${resident.last_name}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Date of Birth:</strong> ${resident.date_of_birth}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Gender:</strong> ${resident.gender}
                                    </div>
                                    <div class="detail-item">
                                        <strong>NHS Number:</strong> ${resident.nhs_number}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-phone"></i> Contact Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <strong>Phone:</strong> ${resident.phone}
                                    </div>
                                    <div class="detail-item">
                                        <strong>NOK Name:</strong> ${resident.nok_name}
                                    </div>
                                    <div class="detail-item">
                                        <strong>NOK Relationship:</strong> ${resident.nok_relationship || 'Not specified'}
                                    </div>
                                    <div class="detail-item">
                                        <strong>NOK Email:</strong> ${resident.nok_email || 'Not provided'}
                                    </div>
                                    <div class="detail-item">
                                        <strong>NOK Phone Number:</strong> ${resident.nok_number}
                                    </div>
                                    <div class="detail-item full-width">
                                        <strong>Address:</strong> ${resident.address}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-bed"></i> Residence Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <strong>Room Number:</strong> ${resident.room_number}
                                    </div>
                                    <div class="detail-item">
                                        <strong>Admission Date:</strong> ${resident.admission_date}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-heartbeat"></i> Medical Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item full-width">
                                        <strong>Medical Conditions:</strong> ${resident.medical_conditions || 'None specified'}
                                    </div>
                                    <div class="detail-item full-width">
                                        <strong>Medications:</strong> ${resident.medications || 'None specified'}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="detail-section">
                                <h4><i class="fas fa-wallet"></i> Financial Summary</h4>
                                <div class="financial-summary">
                                    <div class="financial-card">
                                        <div class="financial-title">Net Bank</div>
                                        <div class="financial-amount" style="color: ${resident.net_bank >= 0 ? '#27ae60' : '#e74c3c'}">
                                            £${resident.formatted_bank}
                                        </div>
                                    </div>
                                    <div class="financial-card">
                                        <div class="financial-title">Net Cash</div>
                                        <div class="financial-amount" style="color: ${resident.net_cash >= 0 ? '#27ae60' : '#e74c3c'}">
                                            £${resident.formatted_cash}
                                        </div>
                                    </div>
                                    <div class="financial-card">
                                        <div class="financial-title">Total Amount</div>
                                        <div class="financial-amount" style="color: ${resident.net_amount >= 0 ? '#27ae60' : '#e74c3c'}">
                                            £${resident.formatted_amount}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Add modal styles if not already added
            if (!document.getElementById('modal-styles')) {
                const modalStyles = document.createElement('style');
                modalStyles.id = 'modal-styles';
                modalStyles.textContent = `
                    .modal-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(0, 0, 0, 0.7);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 2000;
                        padding: 20px;
                    }
                    
                    .modal-content {
                        background: white;
                        border-radius: 10px;
                        max-width: 800px;
                        width: 100%;
                        max-height: 90vh;
                        overflow-y: auto;
                        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                    }
                    
                    .modal-header {
                        background: linear-gradient(to right, #3498db, #2c3e50);
                        color: white;
                        padding: 20px;
                        border-radius: 10px 10px 0 0;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    }
                    
                    .modal-header h3 {
                        margin: 0;
                        font-size: 1.3rem;
                    }
                    
                    .modal-close {
                        background: none;
                        border: none;
                        color: white;
                        font-size: 2rem;
                        cursor: pointer;
                        padding: 0;
                        width: 30px;
                        height: 30px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        border-radius: 50%;
                        transition: background 0.3s;
                    }
                    
                    .modal-close:hover {
                        background: rgba(255, 255, 255, 0.2);
                    }
                    
                    .modal-body {
                        padding: 25px;
                    }
                    
                    .detail-section {
                        margin-bottom: 25px;
                    }
                    
                    .detail-section h4 {
                        color: #2c3e50;
                        margin-bottom: 15px;
                        font-size: 1.1rem;
                        padding-bottom: 8px;
                        border-bottom: 2px solid #ecf0f1;
                    }
                    
                    .detail-grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 15px;
                    }
                    
                    .detail-item {
                        background: #f8f9fa;
                        padding: 12px;
                        border-radius: 5px;
                        border-left: 3px solid #3498db;
                    }
                    
                    .detail-item.full-width {
                        grid-column: 1 / -1;
                    }
                    
                    .financial-summary {
                        display: flex;
                        gap: 20px;
                        justify-content: space-between;
                    }
                    
                    .financial-card {
                        flex: 1;
                        text-align: center;
                        padding: 20px;
                        background: #f8f9fa;
                        border-radius: 8px;
                        border: 1px solid #ecf0f1;
                    }
                    
                    .financial-title {
                        font-size: 0.9rem;
                        color: #7f8c8d;
                        margin-bottom: 10px;
                    }
                    
                    .financial-amount {
                        font-size: 1.4rem;
                        font-weight: bold;
                    }
                    
                    @media (max-width: 768px) {
                        .modal-content {
                            margin: 10px;
                            max-width: calc(100% - 20px);
                        }
                        
                        .detail-grid {
                            grid-template-columns: 1fr;
                        }
                        
                        .financial-summary {
                            flex-direction: column;
                        }
                    }
                `;
                document.head.appendChild(modalStyles);
            }
        }

        function closeModal() {
            const modal = document.querySelector('.modal-overlay');
            if (modal) {
                modal.remove();
            }
        }

        // Show styled confirmation dialog
        function showConfirmation(title, message, confirmText = 'Confirm', cancelText = 'Cancel', confirmClass = 'btn-danger') {
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
                            <button class="btn ${confirmClass}" id="confirmOk">${confirmText}</button>
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
                
                // Focus on cancel button by default
                setTimeout(() => {
                    document.getElementById('confirmCancel').focus();
                }, 100);
            });
        }

        // Edit resident function
        function editResident(id) {
            // Find the resident data from the current residents array
            const resident = residents.find(r => r.id == id);
            if (!resident) {
                alert('Resident data not found.');
                return;
            }

            // Create edit form modal
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.5); z-index: 1000; display: flex;
                align-items: center; justify-content: center;
            `;

            const modal = document.createElement('div');
            modal.className = 'modal-content';
            modal.style.cssText = `
                background: white; border-radius: 15px; width: 90%; max-width: 800px;
                max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            `;

            // Parse name to separate title and first name
            const nameParts = resident.first_name.split(' ');
            let title = '';
            let firstName = resident.first_name;
            
            // Common titles to check for
            const titles = ['Mr', 'Mrs', 'Miss', 'Ms', 'Dr', 'Prof', 'Sir', 'Dame', 'Lord', 'Lady'];
            if (nameParts.length > 1 && titles.includes(nameParts[0])) {
                title = nameParts[0];
                firstName = nameParts.slice(1).join(' ');
            }

            modal.innerHTML = 
                '<div class="modal-header" style="padding: 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between;">' +
                    '<h3 style="margin: 0; color: #2c3e50;"><i class="fas fa-user-edit"></i> Edit Resident</h3>' +
                    '<button type="button" class="modal-close" style="background: none; border: none; font-size: 24px; color: #999; cursor: pointer;">&times;</button>' +
                '</div>' +
                '<div class="modal-body" style="padding: 20px;">' +
                    '<form class="resident-form" method="POST" action="">' +
                        '<input type="hidden" name="action" value="edit_resident">' +
                        '<input type="hidden" name="resident_id" value="' + resident.id + '">' +
                        
                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editTitle"><i class="fas fa-user"></i> Title</label>' +
                                '<select id="editTitle" name="title">' +
                                    '<option value="">Select Title</option>' +
                                    '<option value="Mr"' + (title === 'Mr' ? ' selected' : '') + '>Mr</option>' +
                                    '<option value="Mrs"' + (title === 'Mrs' ? ' selected' : '') + '>Mrs</option>' +
                                    '<option value="Miss"' + (title === 'Miss' ? ' selected' : '') + '>Miss</option>' +
                                    '<option value="Ms"' + (title === 'Ms' ? ' selected' : '') + '>Ms</option>' +
                                    '<option value="Dr"' + (title === 'Dr' ? ' selected' : '') + '>Dr</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editFirstName"><i class="fas fa-user"></i> First Name</label>' +
                                '<input type="text" id="editFirstName" name="firstName" value="' + firstName + '" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editLastName"><i class="fas fa-user"></i> Last Name</label>' +
                                '<input type="text" id="editLastName" name="lastName" value="' + (resident.last_name || '') + '" required>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editDateOfBirth"><i class="fas fa-calendar"></i> Date of Birth</label>' +
                                '<input type="date" id="editDateOfBirth" name="dateOfBirth" value="' + resident.date_of_birth + '" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editGender"><i class="fas fa-venus-mars"></i> Gender</label>' +
                                '<select id="editGender" name="gender" required>' +
                                    '<option value="male"' + (resident.gender === 'male' ? ' selected' : '') + '>Male</option>' +
                                    '<option value="female"' + (resident.gender === 'female' ? ' selected' : '') + '>Female</option>' +
                                    '<option value="other"' + (resident.gender === 'other' ? ' selected' : '') + '>Other</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editNhsNumber"><i class="fas fa-id-card"></i> NHS Number</label>' +
                                '<input type="text" id="editNhsNumber" name="nhsNumber" value="' + (resident.nhs_number || '') + '" pattern="\\d{10}" title="Enter 10 digit NHS Number" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editNokName"><i class="fas fa-user-friends"></i> NOK Name</label>' +
                                '<input type="text" id="editNokName" name="nokName" value="' + (resident.nok_name || '') + '" required>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editNokEmail"><i class="fas fa-envelope"></i> NOK Email Address</label>' +
                                '<input type="email" id="editNokEmail" name="nokEmail" value="' + (resident.nok_email || '') + '" placeholder="next.of.kin@email.com">' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editPhone"><i class="fas fa-phone"></i> Phone Number</label>' +
                                '<input type="tel" id="editPhone" name="phone" value="' + (resident.phone || '') + '" required>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editNokNumber"><i class="fas fa-phone-alt"></i> NOK Phone Number</label>' +
                                '<input type="tel" id="editNokNumber" name="nokNumber" value="' + (resident.nok_number || '') + '" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editNokRelationship"><i class="fas fa-heart"></i> NOK Relationship</label>' +
                                '<input type="text" id="editNokRelationship" name="nokRelationship" value="' + (resident.nok_relationship || '') + '" placeholder="e.g., Son, Daughter, Spouse" required>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editRoomNumber"><i class="fas fa-bed"></i> Room Number</label>' +
                                '<input type="text" id="editRoomNumber" name="roomNumber" value="' + (resident.room_number || '') + '" required>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-group">' +
                            '<label for="editAddress"><i class="fas fa-map-marker-alt"></i> Address</label>' +
                            '<textarea id="editAddress" name="address" rows="3">' + (resident.address || '') + '</textarea>' +
                        '</div>' +

                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="editMedicalConditions"><i class="fas fa-heartbeat"></i> Medical Conditions</label>' +
                                '<textarea id="editMedicalConditions" name="medicalConditions" rows="2" placeholder="Any existing medical conditions...">' + (resident.medical_conditions || '') + '</textarea>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="editMedications"><i class="fas fa-pills"></i> Medications</label>' +
                                '<textarea id="editMedications" name="medications" rows="2" placeholder="Current medications...">' + (resident.medications || '') + '</textarea>' +
                            '</div>' +
                        '</div>' +

                        '<div class="form-group">' +
                            '<label for="editAdmissionDate"><i class="fas fa-calendar-plus"></i> Admission Date</label>' +
                            '<input type="date" id="editAdmissionDate" name="admissionDate" value="' + resident.admission_date + '" required>' +
                        '</div>' +

                        '<div class="form-actions" style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">' +
                            '<button type="button" class="btn btn-secondary" onclick="this.closest(\'.modal-overlay\').remove()">Cancel</button>' +
                            '<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Resident</button>' +
                        '</div>' +
                    '</form>' +
                '</div>';

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Add event listeners
            const closeBtn = modal.querySelector('.modal-close');
            closeBtn.onclick = () => overlay.remove();
            
            overlay.onclick = (e) => {
                if (e.target === overlay) overlay.remove();
            };
        }

        // Deactivate resident confirmation
        async function confirmDeactivateResident(residentId, residentName) {
            const confirmed = await showConfirmation(
                'Deactivate Resident', 
                'Are you sure you want to deactivate resident "' + residentName + '"? This will mark them as inactive but they can be reactivated later.',
                'Deactivate',
                'Cancel',
                'btn-warning'
            );
            
            if (confirmed) {
                document.getElementById('deactivateResidentId').value = residentId;
                document.getElementById('deactivateResidentForm').submit();
            }
        }

        // Reactivate resident confirmation
        async function confirmReactivateResident(residentId, residentName) {
            const confirmed = await showConfirmation(
                'Reactivate Resident', 
                'Are you sure you want to reactivate resident "' + residentName + '"? This will mark them as active again.',
                'Reactivate',
                'Cancel',
                'btn-success'
            );
            
            if (confirmed) {
                document.getElementById('reactivateResidentId').value = residentId;
                document.getElementById('reactivateResidentForm').submit();
            }
        }

        // Delete resident confirmation
        async function confirmDeleteResident(residentId, residentName) {
            const confirmed = await showConfirmation(
                'Delete Resident', 
                'Are you sure you want to delete resident "' + residentName + '"? This action cannot be undone.',
                'Delete',
                'Cancel',
                'btn-danger'
            );
            
            if (confirmed) {
                document.getElementById('deleteResidentId').value = residentId;
                document.getElementById('deleteResidentForm').submit();
            }
        }

        // viewResident function removed - using viewResidentDetails with onclick instead

        function deleteResident(id) {
            if (confirm('Are you sure you want to delete this resident?')) {
                alert('Delete functionality for resident ' + id + ' would be implemented here.');
            }
        }

        // Add event listeners to action buttons
        document.addEventListener('DOMContentLoaded', function() {
            setupRoomFilter(); // Initialize room filter dropdown
            
            document.querySelectorAll('.btn-edit').forEach(btn => {
                btn.addEventListener('click', () => editResident(btn.dataset.id));
            });
            
            // Note: View buttons use onclick in HTML, so no need for additional event listeners
            
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', () => deleteResident(btn.dataset.id));
            });
        });

        // Auto-hide success message after 5 seconds
        setTimeout(() => {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }, 5000);

        // Transaction viewing functionality
        window.currentTransactionData = {
            residentId: null,
            residentName: '',
            homeId: null,
            currentPage: 1,
            selectedMonth: ''
        };

        // View Resident Transactions Modal
        async function viewResidentTransactions(residentId, residentName, homeId) {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            
            const modal = document.createElement('div');
            modal.className = 'modal-content';
            modal.style.maxWidth = '95%';
            modal.style.maxHeight = '90vh';
            modal.style.overflowY = 'auto';
            modal.style.background = 'white';
            modal.style.borderRadius = '10px';
            
            modal.innerHTML = `
                <div class="modal-header" style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; background: #f8f9fa; border-radius: 10px 10px 0 0;">
                    <h3 style="margin: 0; font-size: 1.1rem; color: #2c3e50; display: flex; gap: 8px; align-items: center;"><i class="fas fa-exchange-alt"></i> Transaction History - ${residentName}</h3>
                    <div>
                        <button id="closeTransactionsModal" class="btn btn-danger" style="padding:6px 10px;">Close</button>
                    </div>
                </div>
                <div class="modal-body" style="background: white; padding: 25px;">
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

            document.getElementById('prevMonth').addEventListener('click', () => {
                navigateMonth(-1);
            });

            document.getElementById('nextMonth').addEventListener('click', () => {
                navigateMonth(1);
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

            // Load initial transaction data
            loadTransactionData();
        }

        // Load transaction data with enhanced error handling
        async function loadTransactionData() {
            const container = document.getElementById('transactionsTableContainer');
            const monthFilter = document.getElementById('monthFilter');
            
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
                
                // Remove success check since carehome role always returns success: true
                // if (!data.success) {
                //     throw new Error(data.error || 'Failed to load transactions');
                // }
                
                // Populate month filter if not already done
                if (monthFilter.children.length === 1 && data.monthly_summaries.length > 0) {
                    data.monthly_summaries.forEach(summary => {
                        const option = document.createElement('option');
                        option.value = summary.month;
                        option.textContent = summary.month_name;
                        monthFilter.appendChild(option);
                    });
                }
                
                // Set the current month filter value
                monthFilter.value = window.currentTransactionData.selectedMonth;
                
                // Display monthly summary (show filtered or all)
                const summaryToShow = window.currentTransactionData.selectedMonth 
                    ? data.monthly_summaries.filter(s => s.month === window.currentTransactionData.selectedMonth)
                    : data.monthly_summaries;
                displayMonthlySummary(summaryToShow);
                
                // Display transactions table
                displayTransactionsTable(data.transactions, data.pagination, data.previous_balance, data.selected_month);
                
                // Display pagination
                displayPagination(data.pagination);
                
            } catch (error) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #e74c3c;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem;"></i>
                        <p style="margin-top: 15px;">Error loading transactions: ${error.message}</p>
                    </div>`;
            }
        }

        // Display transactions table
        function displayTransactionsTable(transactions, pagination, previousBalance, currentMonth) {
            const container = document.getElementById('transactionsTableContainer');
            
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
                                    ${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type && transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type || 'N/A')))}
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
            
            tableHtml += `
                    </tbody>
                </table>`;
            
            container.innerHTML = tableHtml;
        }

        // Display pagination
        function displayPagination(pagination) {
            const container = document.getElementById('paginationContainer');
            
            if (!pagination || pagination.total_pages <= 1) {
                container.innerHTML = '';
                return;
            }
            
            let paginationHtml = `
                <div style="display: flex; justify-content: center; align-items: center; gap: 10px; margin: 20px 0;">
                    <span style="color: #7f8c8d;">Page ${pagination.current_page} of ${pagination.total_pages} (${pagination.total_records} records)</span>`;
            
            if (pagination.has_prev) {
                paginationHtml += `<button onclick="changePage(${pagination.current_page - 1})" class="btn btn-secondary btn-sm" style="padding: 6px 12px;">Previous</button>`;
            }
            
            if (pagination.has_next) {
                paginationHtml += `<button onclick="changePage(${pagination.current_page + 1})" class="btn btn-secondary btn-sm" style="padding: 6px 12px;">Next</button>`;
            }
            
            paginationHtml += '</div>';
            container.innerHTML = paginationHtml;
        }

        // Change page
        function changePage(page) {
            window.currentTransactionData.currentPage = page;
            loadTransactionData();
        }

        // Navigate between months
        function navigateMonth(direction) {
            const currentMonth = window.currentTransactionData.selectedMonth;
            let date;
            
            if (!currentMonth) {
                // If no month selected, start from current month
                date = new Date();
            } else {
                date = new Date(currentMonth + '-01');
            }
            
            date.setMonth(date.getMonth() + direction);
            
            const newMonth = date.toISOString().slice(0, 7);
            window.currentTransactionData.selectedMonth = newMonth;
            window.currentTransactionData.currentPage = 1;
            
            // Update month filter dropdown
            const monthFilter = document.getElementById('monthFilter');
            if (monthFilter) {
                monthFilter.value = newMonth;
            }
            
            // Load new month data
            loadTransactionData();
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
                const totalExpenses = parseFloat(summary.total_expense || 0) + parseFloat(summary.total_drop || 0);
                const netAmount = parseFloat(summary.total_income || 0) - totalExpenses;
                const netColor = netAmount >= 0 ? '#27ae60' : '#e74c3c';
                
                summaryHtml += `
                    <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px;">
                        <h5 style="margin: 0 0 15px 0; color: #2c3e50; text-align: center; border-bottom: 2px solid #3498db; padding-bottom: 8px;">
                            ${monthName}
                        </h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9rem;">
                            <div style="text-align: center; background: #d4edda; padding: 10px; border-radius: 6px;">
                                <strong style="color: #155724;">Transfer in</strong><br>
                                £${parseFloat(summary.total_income || 0).toFixed(2)}
                            </div>
                            <div style="text-align: center; background: #f8d7da; padding: 10px; border-radius: 6px;">
                                <strong style="color: #721c24;">Transfer out</strong><br>
                                £${parseFloat(summary.total_expense || 0).toFixed(2)}
                            </div>
                            <div style="text-align: center; background: #fff3cd; padding: 10px; border-radius: 6px;">
                                <strong style="color: #856404;">Paid back</strong><br>
                                £${parseFloat(summary.total_drop || 0).toFixed(2)}
                            </div>
                            <div style="text-align: center; background: #cce5ff; padding: 10px; border-radius: 6px;">
                                <strong style="color: #004085;">Total Out</strong><br>
                                £${totalExpenses.toFixed(2)}
                            </div>
                            <div style="text-align: center; grid-column: 1 / -1; margin-top: 8px; padding: 12px; border-top: 1px solid #ddd; background: white; border-radius: 6px;">
                                <strong style="color: ${netColor}; font-size: 1.1rem;">Net: £${netAmount.toFixed(2)}</strong><br>
                                <small style="color: #7f8c8d;">${summary.total_transactions} transactions</small>
                            </div>
                        </div>
                    </div>`;
            });
            
            summaryHtml += '</div>';
            container.innerHTML = summaryHtml;
        }
    </script>

    <!-- Hidden Forms for Actions -->
    <form id="deactivateResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="resident_id" id="deactivateResidentId">
        <input type="hidden" name="action" value="deactivate_resident">
    </form>

    <form id="reactivateResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="resident_id" id="reactivateResidentId">
        <input type="hidden" name="action" value="reactivate_resident">
    </form>

    <form id="deleteResidentForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="resident_id" id="deleteResidentId">
        <input type="hidden" name="action" value="delete_resident">
    </form>
</body>
</html>