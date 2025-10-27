<?php
session_start();

date_default_timezone_set('Asia/Kolkata');

// Database configuration
$dbHost = 'localhost';
$dbUser = 'carehomesurvey_thana';
$dbPass = 'q)7#Pi_]SeQt';
$dbName = 'carehomesurvey_carehome1';

// Check if user is logged in and has admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
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

// Get today's date in YYYY-MM-DD format
$today = date('Y-m-d');

// Get transactions for specific carehome with filters (FIXED VERSION) - ADDED FROM CAREHOME.ROLE
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

// Handle care home selection
if (isset($_POST['carehome_select']) && !empty($_POST['carehome_select'])) {
    $_SESSION['selected_home_id'] = (int)$_POST['carehome_select'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get selected care home ID from session
$selectedHomeId = $_SESSION['selected_home_id'] ?? null;

// Save uploaded proof file into shared folder uploads/transactions/{transaction_id}
function save_proof_file($transactionId) {
    if (!isset($_FILES['proof']) || empty($_FILES['proof']['name'])) {
        return null;
    }
    $file = $_FILES['proof'];
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    $detected = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
    $mime = $detected ?: ($file['type'] ?? '');
    if (!in_array($mime, $allowed)) {
        return null;
    }
    $root = dirname(__DIR__);
    $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'transactions' . DIRECTORY_SEPARATOR . intval($transactionId);
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext);
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'proof.' . ($safeExt ?: 'dat');
    if (@move_uploaded_file($file['tmp_name'], $targetPath)) {
        return $targetPath;
    }
    return null;
}

// Fetch all care homes for dropdown
$careHomes = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM homes");
    $careHomes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching care homes: " . $e->getMessage();
}

// Get residents for selected care home
$residents = [];
$activeResidents = [];
$current_bank_balance = 0;
$current_cash_balance = 0;
if ($selectedHomeId) {
    try {
        // Get all residents (for Daily Transaction and Pending Amount Summary)
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ? ORDER BY first_name, last_name");
        $stmt->execute([$selectedHomeId]);
        $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get only active residents (for Add Money, Add Expense, Paid Back, Transfer tabs)
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, room_number FROM residents WHERE home_id = ? AND (status IS NULL OR status != 'deactivated') ORDER BY first_name, last_name");
        $stmt->execute([$selectedHomeId]);
        $activeResidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get current bank and cash balances
        $stmt = $pdo->prepare("SELECT bank, cash FROM homes WHERE id = ?");
        $stmt->execute([$selectedHomeId]);
        $home_balances = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($home_balances) {
            $current_bank_balance = floatval($home_balances['bank']);
            $current_cash_balance = floatval($home_balances['cash']);
        }
    } catch (PDOException $e) {
        $error = "Error fetching residents: " . $e->getMessage();
    }
}

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedHomeId) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_income' || $action === 'add_expense' || $action === 'add_drop' || $action === 'transfer') {
        try {
            // Common validation for amount
            if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
                throw new Exception("Invalid amount specified");
            }
            $amount = floatval($_POST['amount']);

            // Transfer handling
            if ($action === 'transfer') {
                $method = $_POST['transfer_method'] ?? '';
                if (!in_array($method, ['bank_to_cash', 'cash_to_bank'])) {
                    throw new Exception('Invalid transfer method');
                }

                if (empty($_POST['description'])) {
                    throw new Exception("Description is required");
                }

                // Get transaction date
                $tx_date = null;
                if (!empty($_POST['transaction_date'])) {
                    $d = trim($_POST['transaction_date']);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        $tx_date = $d;
                    }
                }

                $pdo->beginTransaction();

                try {
                    // Get resident ID
                    $residentId = !empty($_POST['resident_id']) ? $_POST['resident_id'] : null;
                    $description = trim($_POST['description'] ?? '');
                    $reference = trim($_POST['reference_no'] ?? '');
                    $username = $_SESSION['username'] ?? 'admin';

                    if ($method === 'cash_to_bank') {
                        // First insert cash expense
                        $stmtOut = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'expense', ?, 'cash', ?, ?, ?, ?)");
                        $successOut = $stmtOut->execute([$selectedHomeId, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successOut) throw new Exception('Failed to record cash expense');

                        // Then insert bank income
                        $stmtIn = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'income', ?, 'bank', ?, ?, ?, ?)");
                        $successIn = $stmtIn->execute([$selectedHomeId, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successIn) throw new Exception('Failed to record bank income');
                    } else {
                        // First insert bank expense
                        $stmtOut = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'expense', ?, 'bank', ?, ?, ?, ?)");
                        $successOut = $stmtOut->execute([$selectedHomeId, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successOut) throw new Exception('Failed to record bank expense');

                        // Then insert cash income
                        $stmtIn = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, 'income', ?, 'cash', ?, ?, ?, ?)");
                        $successIn = $stmtIn->execute([$selectedHomeId, $residentId, $amount, $description, $reference, $tx_date, $username]);
                        if (!$successIn) throw new Exception('Failed to record cash income');
                    }

                    $pdo->commit();
                    $_SESSION['flash_message'] = 'Transfer processed successfully';
                    $_SESSION['flash_type'] = 'success';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();

                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw new Exception('Transfer failed: ' . $e->getMessage());
                }
            }
            // Non-transfer transactions validation
            if (empty($_POST['amount']) || !is_numeric($_POST['amount']) || $_POST['amount'] <= 0) {
                throw new Exception("Invalid amount specified");
            }
            
            if (empty($_POST['description'])) {
                throw new Exception("Description is required");
            }
            
            if (empty($_POST['payment_method'])) {
                throw new Exception("Payment method is required");
            }
            
            // Prepare transaction data
            // transaction_date is optional; if provided we store it (YYYY-MM-DD)
            $tx_date = null;
            if (!empty($_POST['transaction_date'])) {
                // basic sanitize/validate (expect YYYY-MM-DD)
                $d = trim($_POST['transaction_date']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $tx_date = $d;
                }
            }

            $transaction_data = [
                'home_id' => $selectedHomeId,
                'resident_id' => !empty($_POST['resident_id']) ? (int)$_POST['resident_id'] : null,
                'type' => str_replace('add_', '', $action), // income, expense, or drop
                'amount' => floatval($_POST['amount']),
                'payment_method' => $_POST['payment_method'],
                'description' => trim($_POST['description']),
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'transaction_date' => $tx_date,
                'created_by' => $_SESSION['username'] ?? 'admin'
            ];
            
            // Insert transaction (include optional transaction_date)
            $stmt = $pdo->prepare("INSERT INTO transactions (home_id, resident_id, type, amount, payment_method, description, reference_no, transaction_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $success = $stmt->execute([
        $transaction_data['home_id'],
        $transaction_data['resident_id'],
        $transaction_data['type'],
        $transaction_data['amount'],
        $transaction_data['payment_method'],
        $transaction_data['description'],
        $transaction_data['reference_no'],
        $transaction_data['transaction_date'],
        $transaction_data['created_by']
            ]);
            
            if ($success) {
                $newId = $pdo->lastInsertId();
                $savedPath = save_proof_file($newId);
                if ($savedPath) {
                    // store relative path for easier serving
                    $relative = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $savedPath);
                    $upd = $pdo->prepare("UPDATE transactions SET proof_path = ? WHERE id = ?");
                    $upd->execute([$relative, $newId]);
                }
                // Flash message and redirect (PRG) to prevent duplicate on refresh
                $_SESSION['flash_message'] = ucfirst($transaction_data['type']) . " transaction added successfully!";
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } else {
                throw new Exception("Failed to add transaction");
            }
            
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Read flash message if present
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Handle AJAX request for financial summary when care home is selected
if (isset($_GET['action']) && $_GET['action'] === 'get_financial_summary') {
    header('Content-Type: application/json');
    
    $home_id = isset($_GET['home_id']) ? (int)$_GET['home_id'] : null;
    
    if (!$home_id) {
        echo json_encode(['success' => false, 'error' => 'No care home selected']);
        exit();
    }
    
    try {
        // Get today's transactions using the same logic as staff version
        $today_transactions = get_transactions($dbHost, $dbUser, $dbPass, $dbName, $home_id, ['date' => $today]);
        
        // Calculate financial summary using the same logic as staff version
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

        $net_balance = $total_income - $total_expense - $total_drop;
        
        echo json_encode([
            'success' => true,
            'total_income' => number_format($total_income, 2),
            'total_expense' => number_format($total_expense, 2),
            'total_drop' => number_format($total_drop, 2),
            'net_balance' => number_format($net_balance, 2)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error calculating financial summary: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for filtered transactions
if (isset($_GET['action']) && $_GET['action'] === 'filter_transactions' && $selectedHomeId) {
    header('Content-Type: application/json');
    
    try {
        $start_date = $_GET['start_date'] ?? date('Y-m-d');
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
        $type_filter = $_GET['type'] ?? '';
        $resident_filter = $_GET['resident_id'] ?? '';
        
        // Build query conditions
        $where_conditions = ['t.home_id = ?'];
        $params = [$selectedHomeId];
        
    // Date range filter - use transaction_date if set, otherwise fall back to created_at
    // We'll select rows where (transaction_date BETWEEN ? AND ?) OR (transaction_date IS NULL AND DATE(created_at) BETWEEN ? AND ?)
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
        
        // Get filtered transactions
    $stmt = $pdo->prepare("SELECT t.*, r.first_name, r.last_name, r.room_number 
                  FROM transactions t 
                  LEFT JOIN residents r ON t.resident_id = r.id 
                  WHERE $where_clause 
                  ORDER BY COALESCE(t.transaction_date, t.created_at) DESC, t.id DESC");
        $stmt->execute($params);
        $raw_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group transactions by reference_no, resident_id, and transaction_date to find transfer pairs
        $transfers = [];
        $transactions = [];
        $used_transactions = [];  // Keep track of transactions that are part of transfers
        
        foreach ($raw_transactions as $idx => $trans) {
            $key = $trans['reference_no'] . '_' . $trans['resident_id'] . '_' . $trans['transaction_date'];
            
            if (!isset($transfers[$key])) {
                $transfers[$key] = [$trans];
            } else {
                $transfers[$key][] = $trans;
                
                // Check if we have a matching income/expense pair
                if (count($transfers[$key]) === 2) {
                    $t1 = $transfers[$key][0];
                    $t2 = $transfers[$key][1];
                    
                    if ($t1['type'] !== $t2['type'] && 
                        (($t1['type'] === 'income' && $t2['type'] === 'expense') || 
                         ($t1['type'] === 'expense' && $t2['type'] === 'income')) &&
                        $t1['amount'] === $t2['amount'] &&
                        (($t1['payment_method'] === 'cash' && $t2['payment_method'] === 'bank') ||
                         ($t1['payment_method'] === 'bank' && $t2['payment_method'] === 'cash'))) {
                            
                        // This is a transfer pair - create a display version
                        $displayTrans = $t1;
                        $displayTrans['original_type'] = $displayTrans['type'];
                        $displayTrans['type'] = 'transfer';
                        
                        // Determine transfer direction
                        if ($t1['payment_method'] === 'cash' && $t1['type'] === 'expense') {
                            $displayTrans['payment_method'] = 'cash to bank';
                        } elseif ($t1['payment_method'] === 'bank' && $t1['type'] === 'expense') {
                            $displayTrans['payment_method'] = 'bank to cash';
                        } elseif ($t2['payment_method'] === 'cash' && $t2['type'] === 'expense') {
                            $displayTrans['payment_method'] = 'cash to bank';
                        } else {
                            $displayTrans['payment_method'] = 'bank to cash';
                        }
                        
                        $transactions[] = $displayTrans;
                        
                        // Mark both transactions as used
                        $used_transactions[$t1['id']] = true;
                        $used_transactions[$t2['id']] = true;
                        continue;
                    }
                }
            }
        }
        
        // Calculate summary from raw transactions
        $summary = [
            'total_income' => 0,
            'total_expense' => 0,
            'total_drop' => 0
        ];

        // Calculate totals from raw transactions
        foreach ($raw_transactions as $transaction) {
            if ($transaction['type'] === 'income') {
                $summary['total_income'] += $transaction['amount'];
            } elseif ($transaction['type'] === 'expense') {
                $summary['total_expense'] += $transaction['amount'];
            } elseif ($transaction['type'] === 'drop') {
                $summary['total_drop'] += $transaction['amount'];
            }
        }

        // Now add non-transfer transactions to display list
        foreach ($raw_transactions as $trans) {
            if (!isset($used_transactions[$trans['id']])) {
                $transactions[] = $trans;
            }
        }
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions,
            'summary' => $summary
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Get today's transactions for selected care home - USING THE SAME LOGIC AS STAFF VERSION
$today = date('Y-m-d');
$today_transactions = [];
$total_income = 0;
$total_expense = 0;
$total_drop = 0;
$net_profit = 0;

if ($selectedHomeId) {
    try {
        // Use the same function as staff version for consistency
        $today_transactions = get_transactions($dbHost, $dbUser, $dbPass, $dbName, $selectedHomeId, ['date' => $today]);
        
        // Calculate financial summary using the same logic as staff version
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
        
    } catch (Exception $e) {
        $error = "Error fetching transactions: " . $e->getMessage();
    }
}

// For display in the table - same logic as staff version
$display_transactions = $today_transactions;

// Generate comprehensive financial report
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
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$home_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get monthly trends (last 6 months)
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expenses,
                SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END) as drops
            FROM transactions 
            WHERE home_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$home_id]);
        $monthly_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent transactions (last 30 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as recent_transactions
            FROM transactions 
            WHERE home_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
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

// Generate report if requested
$report_data = null;
if (isset($_GET['generate_report']) && $selectedHomeId) {
    $report_data = generateFinancialReport($pdo, $selectedHomeId);
}

// Handle AJAX request for pending amount
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_amount' && $selectedHomeId) {
    header('Content-Type: application/json');
    
    try {
        $mode = $_GET['mode'] ?? 'carehome';
        $resident_id = $_GET['resident_id'] ?? null;
        
        if ($mode === 'resident' && $resident_id) {
            // Get specific resident's financial summary with payment method breakdown
            $stmt = $pdo->prepare("SELECT 
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
                    WHERE r.home_id = ? AND r.id = ?
                    GROUP BY r.id, r.first_name, r.last_name, r.room_number");
            
            $stmt->execute([$selectedHomeId, $resident_id]);
            $resident_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($resident_data) {
                $total_income = $resident_data['bank_income'] + $resident_data['cash_income'];
                $total_costs = $resident_data['total_expenses'] + $resident_data['total_drops'];
                $pending_balance = $total_income - $total_costs;
                
                // Calculate current balances by payment method
                $current_bank_balance = $resident_data['bank_income'] - $resident_data['bank_expenses'] - $resident_data['bank_drops'];
                $current_cash_balance = $resident_data['cash_income'] - $resident_data['cash_expenses'] - $resident_data['cash_drops'];
        
                $result = [
                    'id' => $resident_data['id'],
                    'name' => $resident_data['first_name'] . ' ' . $resident_data['last_name'],
                    'room_number' => $resident_data['room_number'],
                    'bank_income' => floatval($resident_data['bank_income']),
                    'cash_income' => floatval($resident_data['cash_income']),
                    'total_income' => $total_income,
                    'bank_expenses' => floatval($resident_data['bank_expenses']),
                    'cash_expenses' => floatval($resident_data['cash_expenses']),
                    'total_expenses' => floatval($resident_data['total_expenses']),
                    'bank_drops' => floatval($resident_data['bank_drops']),
                    'cash_drops' => floatval($resident_data['cash_drops']),
                    'total_drops' => floatval($resident_data['total_drops']),
                    'total_costs' => $total_costs,
                    'current_bank_balance' => $current_bank_balance,
                    'current_cash_balance' => $current_cash_balance,
                    'pending_balance' => $pending_balance
                ];
                
                echo json_encode(['success' => true, 'data' => $result, 'mode' => 'resident']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Resident not found']);
            }
        } else {
            // Get carehome summary
            $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'bank' THEN t.amount ELSE 0 END), 0) as total_bank_income,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.payment_method = 'cash' THEN t.amount ELSE 0 END), 0) as total_cash_income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) as total_expenses,
                COALESCE(SUM(CASE WHEN t.type = 'drop' THEN t.amount ELSE 0 END), 0) as total_drops,
                h.bank as current_bank_balance,
                h.cash as current_cash_balance
              FROM homes h
              LEFT JOIN transactions t ON h.id = t.home_id
              WHERE h.id = ?
                    GROUP BY h.bank, h.cash");
            
            $stmt->execute([$selectedHomeId]);
            $carehome_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($carehome_data) {
                $total_income = $carehome_data['total_bank_income'] + $carehome_data['total_cash_income'];
                $total_costs = $carehome_data['total_expenses'] + $carehome_data['total_drops'];
        $net_balance = $total_income - $total_costs;
        
                $result = [
                    'total_bank_income' => floatval($carehome_data['total_bank_income']),
                    'total_cash_income' => floatval($carehome_data['total_cash_income']),
            'total_income' => $total_income,
                    'total_expenses' => floatval($carehome_data['total_expenses']),
                    'total_drops' => floatval($carehome_data['total_drops']),
            'total_costs' => $total_costs,
                    'current_bank_balance' => floatval($carehome_data['current_bank_balance']),
                    'current_cash_balance' => floatval($carehome_data['current_cash_balance']),
            'net_balance' => $net_balance
        ];
                
                echo json_encode(['success' => true, 'data' => $result, 'mode' => 'carehome']);
        } else {
                echo json_encode(['success' => false, 'error' => 'Care home not found']);
        }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle balance check for expense validation
if (isset($_GET['action']) && $_GET['action'] === 'check_balance' && $selectedHomeId) {
    header('Content-Type: application/json');
    
    try {
        $resident_id = $_POST['resident_id'] ?? '';
        
        if (!empty($resident_id)) {
            // Get resident-specific balance from transactions
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN type = 'income' AND payment_method = 'bank' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN type IN ('expense', 'drop') AND payment_method = 'bank' THEN amount ELSE 0 END), 0) +
                    COALESCE(SUM(CASE WHEN type = 'transfer' AND payment_method = 'bank' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN type = 'transfer' AND payment_method = 'cash' THEN amount ELSE 0 END), 0) AS bank_balance,
                    
                    COALESCE(SUM(CASE WHEN type = 'income' AND payment_method = 'cash' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN type IN ('expense', 'drop') AND payment_method = 'cash' THEN amount ELSE 0 END), 0) +
                    COALESCE(SUM(CASE WHEN type = 'transfer' AND payment_method = 'cash' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN type = 'transfer' AND payment_method = 'bank' THEN amount ELSE 0 END), 0) AS cash_balance
                FROM transactions 
                WHERE home_id = ? AND resident_id = ?
            ");
            $stmt->execute([$selectedHomeId, $resident_id]);
            $resident_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'cash_balance' => floatval($resident_data['cash_balance'] ?? 0),
                'bank_balance' => floatval($resident_data['bank_balance'] ?? 0)
            ]);
        } else {
            // Get carehome current balances from homes table
            $stmt = $pdo->prepare("SELECT bank, cash FROM homes WHERE id = ?");
            $stmt->execute([$selectedHomeId]);
            $home_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($home_data) {
                echo json_encode([
                    'success' => true, 
                    'cash_balance' => floatval($home_data['cash']),
                    'bank_balance' => floatval($home_data['bank'])
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Care home not found']);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts - Care Home Management</title>
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
    
    .card-body { padding: 16px; }
    
    /* Table Styles */
    table { width: 100%; border-collapse: collapse; }
    table th {
        text-align: left;
        padding: 12px 10px;
        border-bottom: 1px solid #eee;
        background: #f8f9fa;
        font-weight: 600;
        color: #2c3e50;
    }
    table td { padding: 12px 10px; border-bottom: 1px solid #eee; }
    table tr:last-child td { border-bottom: none; }
    table tr:hover { background: #f8f9fa; }
    
    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
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
    
    .modal-body { padding: 20px; }
    
    /* Form Styles */
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .form-group { margin-bottom: 15px; }
    
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
    
    /* Notification Styles */
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
    
    .notification.success { border-left-color: #2ecc71; }
    .notification.error { border-left-color: #e74c3c; }
    .notification.warning { border-left-color: #f39c12; }
    
    .notification-icon { font-size: 1.5rem; flex-shrink: 0; }
    .notification.success .notification-icon { color: #2ecc71; }
    .notification.error .notification-icon { color: #e74c3c; }
    .notification.warning .notification-icon { color: #f39c12; }
    
    .notification-content { flex: 1; }
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
    
    /* Loading Spinner */
    .loading-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
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
    
    @keyframes spin { to { transform: rotate(360deg); } }
    
    /* Mobile Table Styles */
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
    
    .mobile-card-row:last-child { border-bottom: none; }
    .mobile-card-label { font-weight: 600; color: #2c3e50; }
    .mobile-card-value { color: #7f8c8d; }
    
    /* Accounts Specific Styles */
    .page-header { margin-bottom: 25px; }
    .page-header h2 {
        font-size: 1.8rem;
        color: #2c3e50;
        margin-bottom: 5px;
    }
    .page-header p { color: #7f8c8d; }
    
    .message {
        padding: 12px 16px;
        border-radius: 5px;
        margin-bottom: 20px;
        font-weight: 500;
    }
    .message.success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .message.error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
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
    
    .income .summary-icon { background: rgba(46, 204, 113, 0.2); color: #2ecc71; }
    .expense .summary-icon { background: rgba(231, 76, 60, 0.2); color: #e74c3c; }
    .drop .summary-icon { background: rgba(241, 196, 15, 0.2); color: #f1c40f; }
    .profit .summary-icon { background: rgba(52, 152, 219, 0.2); color: #3498db; }
    .bank .summary-icon { background: rgba(155, 89, 182, 0.2); color: #9b59b6; }
    .cash .summary-icon { background: rgba(243, 156, 18, 0.2); color: #f39c12; }
    
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
    
    .summary-card small { color: #95a5a6; }
    
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
    
    .sub-content-body { padding: 25px; }
    
    /* Account Form Styles */
    .account-form { display: flex; flex-direction: column; gap: 20px; }
    .form-row { display: flex; gap: 20px; }
    .form-group { flex: 1; display: flex; flex-direction: column; }
    
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
    
    /* Resident Selector Styles */
    .resident-selector-container {
        position: relative;
        width: 100%;
    }

    .resident-selector-input {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 1rem;
        box-sizing: border-box;
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
        font-size: 0.9rem;
        color: #7f8c8d;
        margin-left: 8px;
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
    
    .transactions-table { width: 100%; border-collapse: collapse; }
    
    .transactions-table th {
        background: #f8f9fa;
        padding: 15px;
        text-align: left;
        font-weight: 600;
        color: #2c3e50;
        border-bottom: 1px solid #eee;
    }
    
    .transactions-table td { padding: 15px; border-bottom: 1px solid #eee; }
    .transactions-table tbody tr:hover { background-color: #f8f9fa; }
    
    .transaction-type {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    .transaction-type.income { background: rgba(46, 204, 113, 0.1); color: #2ecc71; }
    .transaction-type.expense { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
    .transaction-type.drop { background: rgba(155, 89, 182, 0.1); color: #9b59b6; }
    
    .amount { font-weight: 600; }
    .amount.income { color: #2ecc71; }
    .amount.expense { color: #e74c3c; }
    .amount.drop { color: #9b59b6; }
    
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
    
    /* Pending section styles */
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
    
    .pending-table { width: 100%; border-collapse: collapse; }
    .pending-table th {
        background: #f8f9fa;
        padding: 10px;
        text-align: left;
        font-weight: 600;
        color: #2c3e50;
        border-bottom: 1px solid #eee;
    }
    .pending-table td { padding: 10px; border-bottom: 1px solid #eee; }
    
    /* Care Home Selector */
    .carehome-selector {
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        padding: 16px 18px;
        margin: 20px;
        margin-bottom: 20px;
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
    
    /* Mobile Responsive Design */
    @media (max-width: 1200px) {
        .financial-summary { grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .form-grid { grid-template-columns: 1fr; }
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
        
        .notification-container {
            max-width: 300px;
            right: 10px;
            top: 70px;
        }
        
        .financial-summary { grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .transaction-summary { grid-template-columns: repeat(2, 1fr); }
        
        .form-row { flex-direction: column; gap: 15px; }
        .transaction-controls { flex-direction: column; }
        
        .date-picker, .transaction-filters, .date-range-buttons {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .date-range-buttons {
            flex-direction: row;
            gap: 8px;
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
        .main-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .top-actions { flex-direction: column; }
        .btn { width: 100%; justify-content: center; }
        
        .modal-content { width: 95%; }
        .sub-content-nav { flex-direction: column; }
        .sub-nav-btn { min-width: 100%; }
        
        .form-actions { flex-direction: column; }
        .financial-summary { grid-template-columns: 1fr; gap: 15px; }
        .transaction-summary { grid-template-columns: 1fr; }
        
        /* Show mobile cards and hide table on small screens */
        .mobile-card { display: block; }
        .desktop-table { display: none; }
        
        .modal-body .form-grid { grid-template-columns: 1fr; }
        
        .transactions-table { font-size: 0.9rem; }
        .transactions-table th, .transactions-table td { padding: 10px 5px; }
        
        .carehome-selector { margin: 10px; }
        
        .date-range-buttons {
            width: 100%;
            justify-content: center;
        }
        
        .date-range-btn {
            flex: 1;
            max-width: 120px;
        }
    }
    
    @media (max-width: 576px) {
        .main-content { padding: 10px; }
        .card-body { padding: 10px; }
        .modal-body { padding: 15px; }
        .modal-header { padding: 12px 15px; }
        
        .notification-container {
            max-width: calc(100% - 20px);
            right: 10px;
            left: 10px;
        }
        
        .content-area { padding: 15px; }
        .sub-content-body { padding: 15px; }
        .sub-content-header { padding: 12px 15px; font-size: 1.1rem; }
        
        .summary-card { padding: 15px; }
        .summary-icon { 
            width: 50px; 
            height: 50px; 
            margin-right: 12px; 
            font-size: 1.3rem; 
        }
        
        .summary-amount { font-size: 1.3rem; }
        .carehome-selector { padding: 12px 15px; margin: 10px; }
        
        .pagination-container {
            padding: 10px 15px;
        }
        
        .pagination-btn {
            padding: 6px 10px;
            font-size: 0.8rem;
        }
        
        .page-number {
            padding: 6px 10px;
            font-size: 0.8rem;
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
                <li class="menu-item active"><a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> Accounts</a></li>
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
                <h1><i class="fas fa-calculator"></i> Accounts Management</h1>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout <i class="fas fa-sign-out-alt"></i></a>
                </div>
            </header>

            <!-- Top Actions -->
            <div class="content-area">
                <div class="top-actions" style="display: flex; gap: 12px; flex-wrap: wrap; margin: 16px 20px;">
                    <button id="btnGenerateReport" class="btn btn-info" style="background: #17a2b8; color: #fff; padding: 10px 16px; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 500;">
                        <i class="fas fa-file-alt"></i> Generate Report
                    </button>
                    <button id="btnTechnicalContact" class="btn btn-secondary" style="background: #9b59b6; color: #fff; padding: 10px 16px; border: none; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 500;">
                        <i class="fas fa-headset"></i> Technical Contact
                    </button>
                </div>
            </div>

                 <!-- Care Home Selector -->
            <div class="content-area" style="padding-top:0;">
                <div class="carehome-selector" style="background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.08);padding:16px 18px;margin:20px;margin-bottom:20px;">
                    <form method="post" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#2c3e50;">
                            <i class="fas fa-home"></i> Select Care Home:
                        </label>
                        <select name="carehome_select" id="carehomeSelector" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;min-width:200px;font-size:14px;">
                            <option value="">Choose a care home...</option>
                            <?php foreach ($careHomes as $home): ?>
                                <option value="<?php echo $home['id']; ?>" <?php echo $selectedHomeId == $home['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($home['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnRefreshData" class="btn" style="background:#27ae60;color:#fff;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </form>
                </div>
            </div>

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

                <?php if (!$selectedHomeId): ?>
                    <div class="message error">
                        <i class="fas fa-exclamation-triangle"></i> Please select a care home to manage transactions.
                    </div>
                <?php else: ?>

                <!-- Financial Summary Cards -->
                <div class="financial-summary">
                    <div class="summary-card income">
                        <div class="summary-icon">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Income</h3>
                            <p class="summary-amount">£<?php echo number_format($total_income, 2); ?></p>
                            <small>Today</small>
                        </div>
                    </div>
                    <div class="summary-card expense">
                        <div class="summary-icon">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="summary-content">
                            <h3>Total Expenses</h3>
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
                            <h3>Net Balance</h3>
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
                        Transfer
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
                                        <option value="donations">Friends </option>
                                        <option value="insurance">Council </option>
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
                          <div class="form-row" style="display: flex; gap: 20px; align-items: flex-start;">
                              <!-- Reference / Receipt No. -->
                              <div class="form-group" style="flex: 1;">
                                <label for="incomeReference">
                                  <i class="fas fa-hashtag"></i>
                                  Reference / Receipt No.
                                </label>
                                <input
                                  type="text"
                                  id="incomeReference"
                                  name="reference_no"
                                  placeholder="Optional reference number"
                                  style="width: 95%;"
                                >
                              </div>
                            <?php date_default_timezone_set('Asia/Kolkata'); // set your timezone ?>

                              <!-- Transaction Date -->
                              <div class="form-group" style="flex: 1;">
                                <label for="incomeTransactionDate">
                                  <i class="fas fa-calendar-alt"></i>
                                  Transaction Date
                                </label>
                                <input
                                  type="date"
                                  id="incomeTransactionDate"
                                  name="transaction_date"
                                  title="Transaction Date"
                                  style="width: 95%;"
                                >
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
                            
                            <div class="form-row" style="display: flex; gap: 20px; align-items: flex-start;">
                              <!-- Reference / Receipt No. -->
                              <div class="form-group" style="flex: 1;">
                                <label for="expenseReference">
                                  <i class="fas fa-hashtag"></i>
                                  Reference / Receipt No.
                                </label>
                                <input
                                  type="text"
                                  id="expenseReference"
                                  name="reference_no"
                                  placeholder="Optional reference number"
                                  style="width: 95%;"
                                >
                              </div>
                            
                              <!-- Transaction Date -->
                              <div class="form-group" style="flex: 1;">
                                <label for="expenseTransactionDate">
                                  <i class="fas fa-calendar-alt"></i>
                                  Transaction Date
                                </label>
                                <input
                                  type="date"
                                  id="expenseTransactionDate"
                                  name="transaction_date"
                                  max="<?php echo date('Y-m-d'); ?>"
                                  title="Transaction Date"
                                  style="width: 95%;"
                                >
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
                        Drop Amount
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

                            <div class="form-row" style="display: flex; gap: 20px; align-items: flex-start;">
                              <!-- Reference Number -->
                              <div class="form-group" style="flex: 1;">
                                <label for="dropReference">
                                  <i class="fas fa-hashtag"></i>
                                  Reference Number
                                </label>
                                <input type="text" id="dropReference" name="reference_no" placeholder="Transaction reference" style="width: 95%;">
                              </div>
                            
                              <!-- Transaction Date -->
                              <div class="form-group" style="flex: 1;">
                                <label for="dropTransactionDate">
                                  <i class="fas fa-calendar-alt"></i>
                                  Transaction Date
                                </label>
                                <input type="date" id="dropTransactionDate" name="transaction_date" title="Transaction Date" max="<?php echo date('Y-m-d'); ?>" style="width: 95%;">
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

                <!-- Transfer Sub-content -->
                <div id="transfer-amount" class="sub-content">
                    <div class="sub-content-header">
                        <i class="fas fa-exchange-alt"></i>
                        Transfer Funds
                    </div>
                    <div class="sub-content-body">
                        <form class="account-form" id="transferForm" method="POST" action="" enctype="multipart/form-data">
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
                                        Resident (Optional)
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
                                <div class="form-group">
                                    <label for="transferReference">
                                        <i class="fas fa-hashtag"></i>
                                        Reference / Receipt No.
                                    </label>
                                    <input type="text" id="transferReference" name="reference_no" placeholder="Optional reference number">
                                </div>
                            </div>

                            <div class="form-row" style="display:flex;gap:20px;align-items:flex-start;">
                                <div class="form-group" style="flex:1;">
                                    <label for="transferTransactionDate">
                                        <i class="fas fa-calendar-alt"></i>
                                        Transaction Date
                                    </label>
                                    <input type="date" id="transferTransactionDate" max="<?php echo date('Y-m-d'); ?>" name="transaction_date" style="width:95%;">
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label for="transferDescription">
                                        <i class="fas fa-file-text"></i>
                                        Description
                                    </label>
                                    <textarea id="transferDescription" name="description" rows="2" placeholder="Optional notes about this transfer..."></textarea>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-exchange-alt"></i> Process Transfer</button>
                                <button type="reset" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</button>
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
                                        <th><i class="fas fa-user"></i> User</th>
                                        <th><i class="fas fa-receipt"></i> Slip</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionsTableBody">
                                    <?php if (empty($today_transactions)): ?>
                                        <tr>
                                            <td colspan="8" style="text-align: center; padding: 20px;">
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
                                                            // Display mapping: show "Transfer in" for income, "Transfer out" for expense, "Paid back" for drop; keep other types as-is
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
                                                <td class="amount <?php echo $transaction['type']; ?>">
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
                                                <td><?php echo htmlspecialchars($transaction['created_by']); ?></td>
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
                            <div class="pagination-controls">
                                <button type="button" class="btn btn-secondary pagination-btn" id="firstPageBtn" disabled>
                                    <i class="fas fa-angle-double-left"></i>
                                    First
                                </button>
                                <button type="button" class="btn btn-secondary pagination-btn" id="prevPageBtn" disabled>
                                    <i class="fas fa-angle-left"></i>
                                    Previous
                                </button>
                                <span class="pagination-pages" id="paginationPages">
                                    <!-- Page numbers will be dynamically generated -->
                                </span>
                                <button type="button" class="btn btn-secondary pagination-btn" id="nextPageBtn" disabled>
                                    Next
                                    <i class="fas fa-angle-right"></i>
                                </button>
                                <button type="button" class="btn btn-secondary pagination-btn" id="lastPageBtn" disabled>
                                    Last
                                    <i class="fas fa-angle-double-right"></i>
                                </button>
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
                                               name="pending_resident_display" 
                                               class="resident-selector-input" 
                                               placeholder="Choose Resident - Search or select..." 
                                               readonly autocomplete="off"
                                               style="padding:8px 12px; border:1px solid #ddd; border-radius:6px;">
                                        <input type="hidden" name="pending_resident_id" id="pendingResidentId">
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

                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Notification system functions
        function showNotification(title, message, type = 'info', duration = 5000) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            const iconMap = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };
            
            notification.innerHTML = `
                <div class="notification-icon">
                    <i class="${iconMap[type] || iconMap.info}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${title}</div>
                    <div class="notification-message">${message}</div>
                </div>
                <button class="notification-close" onclick="closeNotification(this)">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(notification);
            
            // Trigger animation
            setTimeout(() => notification.classList.add('show'), 10);
            
            // Auto remove
            if (duration > 0) {
                setTimeout(() => {
                    if (notification.parentNode) {
                        closeNotification(notification.querySelector('.notification-close'));
                    }
                }, duration);
            }
        }
        
        function closeNotification(button) {
            const notification = button.closest('.notification');
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
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

        // Show notifications for PHP messages
        <?php if (!empty($message)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showNotification(
                    '<?php echo $message_type === 'success' ? 'Success' : 'Error'; ?>',
                    '<?php echo addslashes($message); ?>',
                    '<?php echo $message_type; ?>'
                );
            });
        <?php endif; ?>

        // Initialize financial summary on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener for care home selector
            const selector = document.getElementById('carehomeSelector');
            if (selector) {
                selector.addEventListener('change', function(event) {
                    const homeId = this.value;
                    if (homeId) {
                        // Update the session and reload the page to show data for selected home
                        updateSelectedHome(homeId, true);
                    } else {
                        // If no home selected, just update financial summary
                        updateFinancialSummary();
                    }
                });
                
                // If a care home is already selected, update the financial summary
                if (selector.value) {
                    updateFinancialSummary();
                }
            }

            // Prevent form submission for care home selector form
            const carehomeForm = selector?.closest('form');
            if (carehomeForm) {
                carehomeForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    return false;
                });
            }
        });

        // Auto-hide old style messages after 5 seconds
        setTimeout(() => {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }, 5000);

        // Format currency function
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'GBP'
            }).format(amount);
        }

        // Update financial summary when care home is selected
        function updateFinancialSummary() {
            const selector = document.getElementById('carehomeSelector');
            const homeId = selector.value;
            
            if (!homeId) {
                // Reset all values to 0 if no home is selected
                document.querySelector('.summary-card.income .summary-amount').textContent = '£0.00';
                document.querySelector('.summary-card.expense .summary-amount').textContent = '£0.00';
                document.querySelector('.summary-card.drop .summary-amount').textContent = '£0.00';
                document.querySelector('.summary-card.profit .summary-amount').textContent = '£0.00';
                return;
            }
            
            // Show loading state
            const summaryCards = {
                income: document.querySelector('.summary-card.income .summary-amount'),
                expense: document.querySelector('.summary-card.expense .summary-amount'),
                drop: document.querySelector('.summary-card.drop .summary-amount'),
                profit: document.querySelector('.summary-card.profit .summary-amount')
            };
            
            // Set loading state
            Object.values(summaryCards).forEach(card => {
                if (card) card.textContent = '£...';
            });
            
            // Make AJAX call to get financial summary
            fetch(`?action=get_financial_summary&home_id=${homeId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (summaryCards.income) summaryCards.income.textContent = '£' + data.total_income;
                        if (summaryCards.expense) summaryCards.expense.textContent = '£' + data.total_expense;
                        if (summaryCards.drop) summaryCards.drop.textContent = '£' + data.total_drop;
                        if (summaryCards.profit) summaryCards.profit.textContent = '£' + data.net_balance;
                        
                        // Update the session to remember the selected home (without reloading)
                        updateSelectedHome(homeId, false);
                    } else {
                        console.error('Error fetching financial summary:', data.error);
                        showNotification('Error', 'Failed to load financial summary: ' + data.error, 'error');
                        // Reset to 0 on error
                        Object.values(summaryCards).forEach(card => {
                            if (card) card.textContent = '£0.00';
                        });
                    }
                })
                .catch(error => {
                    console.error('Network error:', error);
                    showNotification('Error', 'Network error loading financial summary', 'error');
                    // Reset to 0 on error
                    Object.values(summaryCards).forEach(card => {
                        if (card) card.textContent = '£0.00';
                    });
                });
        }

        // Update selected home in session
        function updateSelectedHome(homeId, shouldReload = false) {
            return fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `carehome_select=${homeId}`
            })
            .then(response => {
                if (response.ok && shouldReload && homeId) {
                    // Reload the page to show data for the selected home
                    window.location.reload();
                }
                return response;
            })
            .catch(error => {
                console.error('Error updating selected home:', error);
                showNotification('Error', 'Failed to update selected home', 'error');
            });
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
                const residentName = document.getElementById('pendingResident').value || 'Selected Resident';
                
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

        // Event listeners for pending section
        document.addEventListener('DOMContentLoaded', function() {
            const pendingMode = document.getElementById('pendingMode');
            const pendingResidentBlock = document.getElementById('pendingResidentBlock');
            const pendingResident = document.getElementById('pendingResidentId');
            
            if (pendingMode) {
                pendingMode.addEventListener('change', function() {
                    const mode = this.value;
                    
                    if (mode === 'resident') {
                        pendingResidentBlock.style.display = 'flex';
                        // Load data for first resident by default
                        if (pendingResident.value) {
                            loadPendingAmount('resident', pendingResident.value);
                        }
                    } else {
                        pendingResidentBlock.style.display = 'none';
                        loadPendingAmount('carehome');
                    }
                });
            }
            
            if (pendingResident) {
                pendingResident.addEventListener('change', function() {
                    if (this.value && document.getElementById('pendingMode').value === 'resident') {
                        loadPendingAmount('resident', this.value);
                    }
                });
            }
            
            // Load initial data for carehome
            <?php if ($selectedHomeId): ?>
            loadPendingAmount('carehome');
            <?php endif; ?>
        });

        // Generate financial report function
        function generateFinancialReport() {
            <?php if (!$selectedHomeId): ?>
                showNotification(
                    'No Home Selected', 
                    'Please select a care home to generate a report.', 
                    'warning'
                );
                return;
            <?php endif; ?>
            
            window.location.href = 'accounts.php?<?php echo $selectedHomeId ? "home_id=$selectedHomeId&" : ""; ?>generate_report=true';
        }

        // Show financial report modal
        function showFinancialReportModal() {
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
            
            const reportData = <?php echo json_encode($report_data); ?>;
            
            // Transaction rows
            let transactionRows = '';
            if (reportData.transactions && reportData.transactions.length > 0) {
                transactionRows = reportData.transactions.map(transaction => `
                    <tr>
                        <td style="padding: 12px; white-space: nowrap;">${transaction.transaction_date ? transaction.transaction_date : new Date(transaction.created_at).toLocaleDateString()}</td>
                        <td style="padding: 12px; min-width: 100px;">
                            <span style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 15px; font-size: 0.8rem; font-weight: 500; white-space: nowrap;
                                ${transaction.type === 'income' ? 'background: rgba(46, 204, 113, 0.1); color: #2ecc71;' : 
                                  transaction.type === 'expense' ? 'background: rgba(231, 76, 60, 0.1); color: #e74c3c;' : 
                                  'background: rgba(241, 196, 15, 0.1); color: #f1c40f;'}">
                                <i class="fas fa-${transaction.type === 'income' ? 'arrow-up' : (transaction.type === 'expense' ? 'arrow-down' : 'hand-holding-usd')}"></i>
                                ${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type)))}
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
                        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px;">
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
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                        <div>
                            <h3 style="color:#2c3e50; margin-bottom:15px;"><i class="fas fa-chart-line"></i> Monthly Trends (Last 6 Months)</h3>
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #2c3e50; color: white;">
                                        <th style="padding: 10px; text-align: left;">Month</th>
                                        <th style="padding: 10px; text-align: left;">Income</th>
                                        <th style="padding: 10px; text-align: left;">Expenses</th>
                                        <th style="padding: 10px; text-align: left;">Drops</th>
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
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>Financial Report - ${reportData.home_name}</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                h2, h3 { color: #2c3e50; }
                                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                                th { background: #2c3e50; color: white; padding: 8px; }
                                td { padding: 8px; border-bottom: 1px solid #eee; }
                                .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0; }
                                .summary div { text-align: center; padding: 10px; background: #f8f9fa; }
                                @media print { .fas { display: none; } }
                            </style>
                        </head>
                        <body>
                            ${modal.querySelector('[style*="padding: 20px"]').innerHTML}
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
                window.history.replaceState({}, document.title, window.location.pathname + '<?php echo $selectedHomeId ? "?home_id=$selectedHomeId" : ""; ?>');
            });
            
            overlay.addEventListener('click', (e) => {
                if(e.target === overlay) {
                    document.body.removeChild(overlay);
                    window.history.replaceState({}, document.title, window.location.pathname + '<?php echo $selectedHomeId ? "?home_id=$selectedHomeId" : ""; ?>');
                }
            });
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Show report if generated
            <?php if ($report_data): ?>
                showFinancialReportModal();
            <?php endif; ?>

            // Add event listeners
            document.getElementById('btnGenerateReport').addEventListener('click', generateFinancialReport);
            document.getElementById('btnTechnicalContact').addEventListener('click', () => {
                    window.location.href = 'mailto:info@webbuilders.lk?subject=Technical%20Support&body=Hello%20WEBbuilders.lk%20%F0%9F%91%8B,';
                });

        });

        // Refresh button functionality - now just reloads if a home is selected
        document.getElementById('btnRefreshData').addEventListener('click', function() {
            const selector = document.getElementById('carehomeSelector');
            if (selector && selector.value) {
                location.reload();
            } else {
                showNotification('No Home Selected', 'Please select a care home first', 'warning');
            }
        });
        
        // Daily Transactions filtering functionality
        function filterTransactions() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const transactionType = document.getElementById('transactionType').value;
            const residentId = document.getElementById('transactionResidentId').value;
            
            if (!startDate || !endDate) {
                showNotification('Date Required', 'Please select both start and end dates', 'warning');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                showNotification('Invalid Date Range', 'Start date cannot be after end date', 'error');
                return;
            }
            
            // Show loading state
            const tableBody = document.getElementById('transactionsTableBody');
            tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</td></tr>';
            
            // Build query parameters
            const params = new URLSearchParams({
                action: 'filter_transactions',
                start_date: startDate,
                end_date: endDate
            });
            
            if (transactionType) params.append('type', transactionType);
            if (residentId) params.append('resident_id', residentId);
            
            // Fetch filtered transactions
            fetch(`accounts.php?${params}`, {
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store transactions globally for pagination
                    currentTransactions = data.transactions || [];
                    currentPage = 1; // Reset to first page
                    
                    displayCurrentPageTransactions();
                    updatePagination();
                    updateTransactionSummary(data.summary);
                } else {
                    throw new Error(data.error || 'Failed to load transactions');
                }
            })
            .catch(error => {
                console.error('Error filtering transactions:', error);
                tableBody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 20px; color: #e74c3c;"><i class="fas fa-exclamation-triangle"></i> Error loading transactions: ${error.message}</td></tr>`;
                showNotification('Error', 'Failed to load transactions', 'error');
            });
        }
        
        // Update transactions table with filtered data
        function updateTransactionsTable(transactions) {
            const tableBody = document.getElementById('transactionsTableBody');
            
            if (!transactions || transactions.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 20px;"><i class="fas fa-info-circle"></i> No transactions found for the selected criteria.</td></tr>';
                return;
            }
            
            tableBody.innerHTML = transactions.map(transaction => `
                <tr>
                    <td>${transaction.transaction_date ? transaction.transaction_date : new Date(transaction.created_at).toLocaleDateString('en-CA')}</td>
                    <td>
                        <span class="transaction-type ${transaction.type}">
                            <i class="fas fa-${transaction.type === 'income' ? 'arrow-up' : (transaction.type === 'expense' ? 'arrow-down' : 'hand-holding-usd')}"></i>
                            ${transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type)))}
                        </span>
                    </td>
                    <td>${transaction.description || '-'}</td>
                    <td>${transaction.first_name ? `${transaction.first_name} ${transaction.last_name}` : '<em>General</em>'}</td>
                    <td class="amount ${transaction.type}">
                        ${transaction.type === 'income' ? '+' : '-'}£${parseFloat(transaction.amount).toFixed(2)}
                    </td>
                    <td style="text-transform: capitalize;">${transaction.payment_method}</td>
                    <td>${transaction.created_by}</td>
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
            document.getElementById('totalIncome').textContent = `+£${parseFloat(summary.total_income || 0).toFixed(2)}`;
            document.getElementById('totalExpense').textContent = `-£${parseFloat(summary.total_expense || 0).toFixed(2)}`;
            document.getElementById('totalDrop').textContent = `-£${parseFloat(summary.total_drop || 0).toFixed(2)}`;
            
            const netTotal = (summary.total_income || 0) - (summary.total_expense || 0) - (summary.total_drop || 0);
            document.getElementById('netTotal').textContent = `£${netTotal.toFixed(2)}`;
            document.getElementById('netTotal').style.color = netTotal >= 0 ? '#2ecc71' : '#e74c3c';
        }
        
        // Add event listeners for transaction filters
        document.addEventListener('DOMContentLoaded', function() {
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            const transactionType = document.getElementById('transactionType');
            const transactionResident = document.getElementById('transactionResidentId');
            
            if (startDate) startDate.addEventListener('change', filterTransactions);
            if (endDate) endDate.addEventListener('change', filterTransactions);
            if (transactionType) transactionType.addEventListener('change', filterTransactions);
            if (transactionResident) transactionResident.addEventListener('change', filterTransactions);
            
            // Add date range button event listeners
            const thisMonthBtn = document.getElementById('thisMonthBtn');
            const thisYearBtn = document.getElementById('thisYearBtn');
            
            if (thisMonthBtn) {
                thisMonthBtn.addEventListener('click', () => setDateRange('month'));
            }
            
            if (thisYearBtn) {
                thisYearBtn.addEventListener('click', () => setDateRange('year'));
            }
            
            // Add pagination button event listeners
            const firstPageBtn = document.getElementById('firstPageBtn');
            const prevPageBtn = document.getElementById('prevPageBtn');
            const nextPageBtn = document.getElementById('nextPageBtn');
            const lastPageBtn = document.getElementById('lastPageBtn');
            
            if (firstPageBtn) {
                firstPageBtn.addEventListener('click', () => goToPage(1));
            }
            
            if (prevPageBtn) {
                prevPageBtn.addEventListener('click', () => goToPage(currentPage - 1));
            }
            
            if (nextPageBtn) {
                nextPageBtn.addEventListener('click', () => goToPage(currentPage + 1));
            }
            
            if (lastPageBtn) {
                lastPageBtn.addEventListener('click', () => {
                    const totalPages = Math.ceil(currentTransactions.length / recordsPerPage);
                    goToPage(totalPages);
                });
            }
        });
        
        // Global pagination variables
        let currentTransactions = [];
        let currentPage = 1;
        const recordsPerPage = 10;
        
        // Date range button functionality
        function setDateRange(type) {
            const today = new Date();
            const startDateInput = document.getElementById('startDate');
            const endDateInput = document.getElementById('endDate');
            
            if (type === 'month') {
                const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                
                startDateInput.value = firstDay.toISOString().split('T')[0];
                endDateInput.value = lastDay.toISOString().split('T')[0];
            } else if (type === 'year') {
                const firstDay = new Date(today.getFullYear(), 0, 1);
                const lastDay = new Date(today.getFullYear(), 11, 31);
                
                startDateInput.value = firstDay.toISOString().split('T')[0];
                endDateInput.value = lastDay.toISOString().split('T')[0];
            }
            
            // Trigger filter after setting dates
            filterTransactions();
        }
        
        // Pagination functions
        function updatePagination() {
            const totalRecords = currentTransactions.length;
            const totalPages = Math.ceil(totalRecords / recordsPerPage);
            
            const paginationContainer = document.getElementById('paginationContainer');
            const paginationInfo = document.getElementById('paginationInfo');
            const paginationPages = document.getElementById('paginationPages');
            
            if (totalRecords === 0) {
                paginationContainer.style.display = 'none';
                return;
            }
            
            paginationContainer.style.display = 'flex';
            
            const startRecord = (currentPage - 1) * recordsPerPage + 1;
            const endRecord = Math.min(currentPage * recordsPerPage, totalRecords);
            
            paginationInfo.textContent = `Showing ${startRecord}-${endRecord} of ${totalRecords} records`;
            
            // Generate page numbers
            paginationPages.innerHTML = '';
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
            
            if (startPage > 1) {
                const btn = document.createElement('button');
                btn.className = 'page-number';
                btn.textContent = '1';
                btn.onclick = () => goToPage(1);
                paginationPages.appendChild(btn);
                
                if (startPage > 2) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'page-ellipsis';
                    ellipsis.textContent = '...';
                    paginationPages.appendChild(ellipsis);
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const btn = document.createElement('button');
                btn.className = `page-number ${i === currentPage ? 'active' : ''}`;
                btn.textContent = i;
                btn.onclick = () => goToPage(i);
                paginationPages.appendChild(btn);
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'page-ellipsis';
                    ellipsis.textContent = '...';
                    paginationPages.appendChild(ellipsis);
                }
                
                const btn = document.createElement('button');
                btn.className = 'page-number';
                btn.textContent = totalPages;
                btn.onclick = () => goToPage(totalPages);
                paginationPages.appendChild(btn);
            }
            
            // Update navigation buttons
            document.getElementById('firstPageBtn').disabled = currentPage === 1;
            document.getElementById('prevPageBtn').disabled = currentPage === 1;
            document.getElementById('nextPageBtn').disabled = currentPage === totalPages;
            document.getElementById('lastPageBtn').disabled = currentPage === totalPages;
        }
        
        function goToPage(page) {
            const totalPages = Math.ceil(currentTransactions.length / recordsPerPage);
            
            if (page < 1 || page > totalPages) return;
            
            currentPage = page;
            displayCurrentPageTransactions();
            updatePagination();
        }
        
        function displayCurrentPageTransactions() {
            const startIndex = (currentPage - 1) * recordsPerPage;
            const endIndex = startIndex + recordsPerPage;
            const pageTransactions = currentTransactions.slice(startIndex, endIndex);
            
            updateTransactionsTable(pageTransactions);
        }
        
        // Update the filterTransactions function to work with pagination
        const originalFilterTransactions = filterTransactions;
        filterTransactions = function() {
            currentPage = 1; // Reset to first page when filtering
            originalFilterTransactions();
        };

        // View transaction slip modal function
        function viewTransactionSlip(transaction) {
            // Get the selected care home name from the dropdown
            const carehomeSelector = document.getElementById('carehomeSelector');
            const selectedHomeName = carehomeSelector.options[carehomeSelector.selectedIndex].text;
            const homeName = selectedHomeName && selectedHomeName !== 'Choose a care home...' ? selectedHomeName : 'N/A';
            
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
                            <span style="font-weight: 600; color: #2c3e50;">Care Home:</span>
                            <span style="font-weight: 600; color: #3498db;">${homeName}</span>
                        </div>
                        
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
                        
                        ${transaction.reference_no ? `
                        <div style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee;">
                            <span style="font-weight: 600; color: #2c3e50;">Reference No:</span>
                            <span style="font-family: monospace; background: #f8f9fa; padding: 4px 8px; border-radius: 4px;">${transaction.reference_no}</span>
                        </div>
                        ` : ''}
                        
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
                        
                        ${transaction.proof_path ? `
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
                        ` : ''}
                    </div>
                    
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
            
            // Get the selected care home name from the dropdown
            const carehomeSelector = document.getElementById('carehomeSelector');
            const selectedHomeName = carehomeSelector.options[carehomeSelector.selectedIndex].text;
            const homeName = selectedHomeName && selectedHomeName !== 'Choose a care home...' ? selectedHomeName : 'N/A';
            
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
                            <p><strong>Care Home:</strong> ${homeName}</p>
                            <p>Transaction ID: #${transaction.id}</p>
                        </div>
                        
                        <div class="amount" style="color: ${transaction.type === 'income' ? '#2ecc71' : '#e74c3c'};">
                            ${transaction.type === 'income' ? '+' : '-'}£${parseFloat(transaction.amount).toFixed(2)}
                        </div>
                        
                        <div class="details">
                            <div class="detail-row">
                                <span class="detail-label">Care Home:</span>
                                <span style="font-weight: bold; color: #3498db;">${homeName}</span>
                            </div>
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
                            ${transaction.reference_no ? `
                            <div class="detail-row">
                                <span class="detail-label">Reference No:</span>
                                <span>${transaction.reference_no}</span>
                            </div>
                            ` : ''}
                            <div class="detail-row">
                                <span class="detail-label">Created By:</span>
                                <span>${transaction.created_by}</span>
                            </div>
                        </div>
                        
                        <div class="description">
                            <strong>Description:</strong><br>
                            ${transaction.description || 'No description provided'}
                        </div>
                        
                        ${transaction.proof_path ? `
                        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
                            <strong>Transaction Slip:</strong> Available
                        </div>
                        ` : ''}
                        
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
        
        // View slip modal function
        function viewSlipModal(imagePath) {
            const overlay = document.createElement('div');
            overlay.className = 'slip-image-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                background: rgba(0,0,0,0.8); display: flex; align-items: center; 
                justify-content: center; z-index: 1100; backdrop-filter: blur(3px);
            `;
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                background: white; border-radius: 10px; max-width: 90%; max-height: 90%; 
                overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); position: relative;
            `;
            
            modal.innerHTML = `
                <div style="padding: 15px; background: #f8f9fa; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; color: #2c3e50;"><i class="fas fa-receipt"></i> Transaction Slip</h3>
                    <button onclick="closeSlipImageModal()" style="background: #e74c3c; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div style="padding: 20px; text-align: center;">
                    ${imagePath.toLowerCase().includes('.pdf') ? 
                        `<iframe src="${imagePath}" style="width: 100%; height: 600px; border: none;"></iframe>` :
                        `<img src="${imagePath}" alt="Transaction Slip" style="max-width: 100%; max-height: 70vh; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">`
                    }
                </div>
            `;
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    closeSlipImageModal();
                }
            });
        }
        
        // Close slip image modal
        function closeSlipImageModal() {
            const overlay = document.querySelector('.slip-image-overlay');
            if (overlay) {
                document.body.removeChild(overlay);
            }
        }
        
        
        // Mobile menu functionality
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
        
        // Initialize mobile menu when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            setupMobileMenu();
            
            // Your existing initialization code...
            <?php if ($report_data): ?>
                showFinancialReportModal();
            <?php endif; ?>
        
            document.getElementById('btnGenerateReport').addEventListener('click', generateFinancialReport);
            document.getElementById('btnTechnicalContact').addEventListener('click', () => {
                window.open('https://api.whatsapp.com/send?phone=94769988123&text=Hello%20WEBbuilders.lk%20%F0%9F%91%8B%2C', '_blank');
            });
            
            document.getElementById('btnRefreshData').addEventListener('click', function() {
                const selector = document.getElementById('carehomeSelector');
                if (selector && selector.value) {
                    location.reload();
                } else {
                    showNotification('No Home Selected', 'Please select a care home first', 'warning');
                }
            });
            
            // Add event listeners for transaction filters
            const startDate = document.getElementById('startDate');
            const endDate = document.getElementById('endDate');
            const transactionType = document.getElementById('transactionType');
            const transactionResident = document.getElementById('transactionResidentId');
            
            if (startDate) startDate.addEventListener('change', filterTransactions);
            if (endDate) endDate.addEventListener('change', filterTransactions);
            if (transactionType) transactionType.addEventListener('change', filterTransactions);
            if (transactionResident) transactionResident.addEventListener('change', filterTransactions);
            
            // Initialize resident selectors
            initializeResidentSelector('incomeResident', 'incomeResidentDropdown', 'incomeResidentId');
            initializeResidentSelector('expenseResident', 'expenseResidentDropdown', 'expenseResidentId');
            initializeResidentSelector('dropResident', 'dropResidentDropdown', 'dropResidentId');
            initializeResidentSelector('transferResident', 'transferResidentDropdown', 'transferResidentId');
            initializeResidentSelector('transactionResident', 'transactionResidentDropdown', 'transactionResidentId');
            initializeResidentSelector('pendingResident', 'pendingResidentDropdown', 'pendingResidentId');
        });
        
        // Function to initialize resident selector functionality
        function initializeResidentSelector(inputId, dropdownId, hiddenId) {
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            const hiddenInput = document.getElementById(hiddenId);
            const options = dropdown.querySelectorAll('.resident-option');
            
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
                    
                    // Special handling for transaction resident selector
                    if (inputId === 'transactionResident') {
                        filterTransactions();
                    }
                    
                    // Special handling for pending resident selector
                    if (inputId === 'pendingResident' && this.dataset.id) {
                        const pendingMode = document.getElementById('pendingMode');
                        if (pendingMode && pendingMode.value === 'resident') {
                            loadPendingAmount('resident', this.dataset.id);
                        }
                        
                        // Trigger change event on hidden input for any other listeners
                        hiddenInput.dispatchEvent(new Event('change'));
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
                    const residentId = document.getElementById('expenseResidentId')?.value;
                    
                    if (!amount || !paymentMethod) {
                        this.submit(); // Let normal validation handle missing fields
                        return;
                    }
                    
                    try {
                        // Get current balance
                        const response = await fetch('?action=check_balance', {
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        
                        // Get resident-specific balances if resident is selected
                        let residentBalances = null;
                        if (residentId) {
                            const residentResponse = await fetch(`?action=get_pending_amount&mode=resident&resident_id=${residentId}`, {
                                credentials: 'same-origin'
                            });
                            const residentData = await residentResponse.json();
                            if (residentData.success && residentData.data) {
                                residentBalances = {
                                    bank: residentData.data.current_bank_balance,
                                    cash: residentData.data.current_cash_balance,
                                    net: residentData.data.pending_balance
                                };
                            }
                        }
                        
                        if (data.success) {
                            const currentBalance = paymentMethod === 'cash' ? data.cash_balance : data.bank_balance;
                            
                            if (currentBalance < amount) {
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
                    const residentId = document.getElementById('dropResidentId')?.value;
                    
                    if (!amount || !paymentMethod) {
                        this.submit(); // Let normal validation handle missing fields
                        return;
                    }
                    
                    try {
                        // Get current balance (carehome level)
                        const response = await fetch('?action=check_balance', {
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        
                        // Get resident-specific balances if resident is selected
                        let residentBalances = null;
                        if (residentId) {
                            const residentResponse = await fetch(`?action=get_pending_amount&mode=resident&resident_id=${residentId}`, {
                                credentials: 'same-origin'
                            });
                            const residentData = await residentResponse.json();
                            if (residentData.success && residentData.data) {
                                residentBalances = {
                                    bank: residentData.data.current_bank_balance,
                                    cash: residentData.data.current_cash_balance,
                                    net: residentData.data.pending_balance
                                };
                            }
                        }
                        
                        if (data.success) {
                            const currentBalance = paymentMethod === 'cash' ? data.cash_balance : data.bank_balance;
                            
                            if (currentBalance < amount) {
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
                        Your current <span style="color:#007bff; font-weight:bold;">Cash balance</span> is <span class="balance-amount">£${currentBalance.toFixed(2)}</span>.<br>
                        You are trying to spend <span class="balance-amount">£${requestedAmount.toFixed(2)}</span>.<br>
                        This will result in a negative balance.
                        ${residentBalances ? `<br><br><strong>Resident Balance Details:</strong><br>
                        Current Bank Balance: <span class="balance-amount">£${residentBalances.bank.toFixed(2)}</span><br>
                        Current Cash Balance: <span class="balance-amount">£${residentBalances.cash.toFixed(2)}</span><br>
                        Current Net Balance: <span class="balance-amount">£${residentBalances.net.toFixed(2)}</span>` : ''}
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
                    ${residentBalances ? `<br><br><strong>Resident Balance Details:</strong><br>
                    Current Bank Balance: <span class="toast-amount">£${residentBalances.bank.toFixed(2)}</span><br>
                    Current Cash Balance: <span class="toast-amount">£${residentBalances.cash.toFixed(2)}</span><br>
                    Current Net Balance: <span class="toast-amount">£${residentBalances.net.toFixed(2)}</span>` : ''}
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
                    ${residentBalances ? `<br><br><strong>Resident Balance Details:</strong><br>
                    Current Bank Balance: <span class="toast-amount">£${residentBalances.bank.toFixed(2)}</span><br>
                    Current Cash Balance: <span class="toast-amount">£${residentBalances.cash.toFixed(2)}</span><br>
                    Current Net Balance: <span class="toast-amount">£${residentBalances.net.toFixed(2)}</span>` : ''}
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
                    ${residentBalances ? `<br><br><strong>Resident Balance Details:</strong><br>
                    Current Bank Balance: <span class="toast-amount">£${residentBalances.bank.toFixed(2)}</span><br>
                    Current Cash Balance: <span class="toast-amount">£${residentBalances.cash.toFixed(2)}</span><br>
                    Current Net Balance: <span class="toast-amount">£${residentBalances.net.toFixed(2)}</span>` : ''}
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
                const selectedHomeId = document.getElementById('selectedHomeId')?.value;
                
                if (!amount || !transferMethod) {
                    this.submit(); // Let normal validation handle missing fields
                    return;
                }
                
                try {
                    // Get current balance for the selected resident and home
                    const response = await fetch('?action=check_balance', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `resident_id=${residentId || ''}&home_id=${selectedHomeId || ''}`
                    });
                    
                    // Get resident-specific balances if resident is selected
                    let residentBalances = null;
                    if (residentId) {
                        const residentResponse = await fetch(`?action=get_pending_amount&mode=resident&resident_id=${residentId}`, {
                            credentials: 'same-origin'
                        });
                        const residentData = await residentResponse.json();
                        if (residentData.success && residentData.data) {
                            residentBalances = {
                                bank: residentData.data.current_bank_balance,
                                cash: residentData.data.current_cash_balance,
                                net: residentData.data.pending_balance
                            };
                        }
                    }
                    
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

    </script>
</body>
</html>