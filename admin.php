<?php
// admin.php
session_start();
require 'db.php';

// --- PASSKEY AUTHENTICATION ---
function verifyPasskey($pdo, $password) {
    $stmt = $pdo->prepare("SELECT password FROM server_passkey LIMIT 1");
    $stmt->execute();
    $stored = $stmt->fetchColumn();
    return $stored && password_verify($password, $stored);
}

// Handle passkey submission
$passkey_error = '';
if (isset($_POST['submit_passkey'])) {
    if (verifyPasskey($pdo, $_POST['passkey'])) {
        $_SESSION['server_authenticated'] = true;
        $_SESSION['auth_time'] = time();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $passkey_error = 'Invalid passkey';
    }
}

// Check authentication (30 minute timeout)
$is_authenticated = isset($_SESSION['server_authenticated']) && 
                    $_SESSION['server_authenticated'] === true && 
                    (time() - $_SESSION['auth_time'] < 800);

// If not authenticated, show only the passkey modal
if (!$is_authenticated):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Authentication</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .passkey-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .passkey-card {
            background: white;
            border-radius: 16px;
            padding: 40px 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            text-align: center;
        }
        .passkey-card h2 {
            color: #0f172a;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .passkey-card p {
            color: #64748b;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .passkey-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            margin-bottom: 20px;
            transition: border-color 0.2s;
        }
        .passkey-input:focus {
            outline: none;
            border-color: #2563eb;
        }
        .passkey-button {
            width: 100%;
            padding: 15px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .passkey-button:hover { opacity: 0.9; }
        .error-message {
            color: #ef4444;
            font-size: 14px;
            margin-top: 15px;
        }
        .lock-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="passkey-container">
        <div class="passkey-card">
            <div class="lock-icon">🔒</div>
            <h2>Server Access</h2>
            <p>Enter passkey to continue</p>
            <form method="POST">
                <input type="password" name="passkey" class="passkey-input" placeholder="Enter passkey" autofocus required>
                <button type="submit" name="submit_passkey" class="passkey-button">Authenticate</button>
                <?php if ($passkey_error): ?>
                    <div class="error-message"><?php echo htmlspecialchars($passkey_error); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</body>
</html>
<?php
    exit();
endif;

$message = "";
if (isset($_GET['msg'])) { $message = $_GET['msg']; }
$target_user = isset($_POST['target_user']) ? $_POST['target_user'] : (isset($_GET['uid']) ? $_GET['uid'] : null);
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'grid';

// --- HELPER FUNCTIONS ---
function formatCurrency($amount, $currency = 'USD') {
    $currency_symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'CHF' => 'CHF',
        'CNY' => '¥',
        'INR' => '₹',
        'BTC' => '₿'
    ];
    $symbol = isset($currency_symbols[$currency]) ? $currency_symbols[$currency] : $currency;
    if ($currency == 'JPY' || $currency == 'BTC') {
        return $symbol . number_format($amount, 0);
    } else {
        return $symbol . number_format($amount, 2);
    }
}

function getCurrencySymbol($currency) {
    $currency_symbols = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'CHF' => 'CHF',
        'CNY' => '¥',
        'INR' => '₹',
        'BTC' => '₿'
    ];
    return isset($currency_symbols[$currency]) ? $currency_symbols[$currency] : $currency;
}

function generateTransferSentence($amount, $method, $detail, $currency = 'USD') {
    $currency_symbol = getCurrencySymbol($currency);
    $sentences = [
        "A secure allocation of {$currency_symbol}{$amount} has been initiated via $method ($detail).",
        "System confirms the dispatch of {$currency_symbol}{$amount} to the registered $method account: $detail.",
        "Beneficiary payout of {$currency_symbol}{$amount} is currently being routed through $method to $detail.",
        "Financial release of {$currency_symbol}{$amount} authorized for Withdraw funds ($method: $detail).",
        "Processing a transaction of {$currency_symbol}{$amount} directed towards $method address $detail."
    ];
    return $sentences[array_rand($sentences)];
}

function getUserUploadPath($user_id) {
    $user_folder = 'uploads/user_' . $user_id;
    $full_path = __DIR__ . DIRECTORY_SEPARATOR . $user_folder;
    if (!file_exists($full_path)) {
        mkdir($full_path, 0777, true);
        chmod($full_path, 0777);
    }
    return $user_folder;
}

function resolveImagePath($image_path) {
    if (empty($image_path)) return null;
    if (filter_var($image_path, FILTER_VALIDATE_URL)) return $image_path;
    $document_root = $_SERVER['DOCUMENT_ROOT'];
    $script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $paths_to_try = [
        $image_path,
        './' . ltrim($image_path, '/'),
        $document_root . '/' . ltrim($image_path, '/'),
        $script_dir . '/' . $image_path
    ];
    foreach ($paths_to_try as $path) {
        if (file_exists($path)) {
            if (strpos($path, $document_root) === 0) {
                return substr($path, strlen($document_root));
            }
            return $path;
        }
    }
    return null;
}

function getImageUrl($image_path) {
    $resolved_path = resolveImagePath($image_path);
    if ($resolved_path && file_exists($resolved_path)) {
        if (filter_var($resolved_path, FILTER_VALIDATE_URL)) return $resolved_path;
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $base_url = $protocol . $host;
        $clean_path = str_replace('//', '/', '/' . ltrim($resolved_path, './'));
        return $base_url . $clean_path;
    }
    return null;
}

/**
 * Generate account number based on user ID and currency
 * Returns a unique account number with user ID prefix + random digits
 * Length depends on currency (USD: 11 digits, EUR: 12 digits, etc.)
 */
function generateAccountNumber($user_id, $currency = 'USD') {
    // Define account number length by currency
    $lengths = [
        'USD' => 11,
        'EUR' => 12,
        'GBP' => 11,
        'JPY' => 12,
        'CAD' => 11,
        'AUD' => 11,
        'CHF' => 12,
        'CNY' => 12,
        'INR' => 11,
        'BTC' => 34 // BTC addresses are longer
    ];
    
    $target_length = isset($lengths[$currency]) ? $lengths[$currency] : 11;
    
    // Start with user ID
    $prefix = (string)$user_id;
    
    // Calculate how many random digits we need
    $random_digits_needed = $target_length - strlen($prefix);
    
    // If user ID is already longer than target, we need to adjust
    if ($random_digits_needed < 0) {
        // If user ID is longer, just use the user ID truncated
        return substr($prefix, 0, $target_length);
    }
    
    // Generate random digits
    $random_part = '';
    for ($i = 0; $i < $random_digits_needed; $i++) {
        $random_part .= mt_rand(0, 9);
    }
    
    return $prefix . $random_part;
}

/**
 * Ensure user has an account number
 * If not, generate one based on user ID and currency
 */
function ensureAccountNumber($pdo, $user_id, $currency = 'USD') {
    $stmt = $pdo->prepare("SELECT account_number FROM inheritance_accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $account_number = $stmt->fetchColumn();
    
    if (empty($account_number)) {
        $account_number = generateAccountNumber($user_id, $currency);
        
        // Make sure it's unique
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inheritance_accounts WHERE account_number = ?");
        $stmt->execute([$account_number]);
        if ($stmt->fetchColumn() > 0) {
            // If not unique, regenerate with more random digits
            do {
                $account_number = generateAccountNumber($user_id, $currency);
                $stmt->execute([$account_number]);
            } while ($stmt->fetchColumn() > 0);
        }
        
        // Save the account number
        $stmt = $pdo->prepare("UPDATE inheritance_accounts SET account_number = ? WHERE user_id = ?");
        $stmt->execute([$account_number, $user_id]);
    }
    
    return $account_number;
}

// --- DIRECT DELETE USER ---
if (isset($_POST['delete_user_direct'])) {
    $user_id = $_POST['user_id'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT image_path FROM portfolio_assets WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $assets = $stmt->fetchAll();
        foreach ($assets as $asset) {
            if (!empty($asset['image_path'])) {
                $full_path = __DIR__ . DIRECTORY_SEPARATOR . $asset['image_path'];
                if (file_exists($full_path)) unlink($full_path);
            }
        }
        $user_folder = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'user_' . $user_id;
        if (file_exists($user_folder)) {
            $files = glob($user_folder . DIRECTORY_SEPARATOR . '*');
            foreach($files as $file) {
                if(is_file($file)) unlink($file);
            }
            rmdir($user_folder);
        }
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=User deleted successfully");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error deleting user: " . $e->getMessage();
    }
}

// --- UPDATE USER WITH PASSKEY AND THEME ---
if (isset($_POST['update_user_direct'])) {
    $user_id = $_POST['user_id'];
    $fullname = $_POST['full_name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $portal_name = $_POST['portal_name'] ?? null;
    $theme_mode = $_POST['theme_mode'] ?? 'white'; // Get theme mode from form
    $passkey = !empty($_POST['passkey']) ? $_POST['passkey'] : null;
    
    try {
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            if ($passkey) {
                $passkey_hash = password_hash($passkey, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, password = ?, theme_mode = ?, passkey = ?, portal_name = ? WHERE id = ?");
                $stmt->execute([$fullname, $username, $email, $password, $theme_mode, $passkey_hash, $portal_name, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, password = ?, theme_mode = ?, portal_name = ? WHERE id = ?");
                $stmt->execute([$fullname, $username, $email, $password, $theme_mode, $portal_name, $user_id]);
            }
        } else {
            if ($passkey) {
                $passkey_hash = password_hash($passkey, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, theme_mode = ?, passkey = ?, portal_name = ? WHERE id = ?");
                $stmt->execute([$fullname, $username, $email, $theme_mode, $passkey_hash, $portal_name, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, theme_mode = ?, portal_name = ? WHERE id = ?");
                $stmt->execute([$fullname, $username, $email, $theme_mode, $portal_name, $user_id]);
            }
        }
        
        // Ensure account number exists after user update
        $currency = 'USD';
        $stmt = $pdo->prepare("SELECT currency FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $currencyData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($currencyData) {
            $currency = $currencyData['currency'] ?? 'USD';
        }
        ensureAccountNumber($pdo, $user_id, $currency);
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=" . $user_id . "&msg=User updated successfully");
        exit();
    } catch (Exception $e) {
        $message = "Error updating user: " . $e->getMessage();
    }
}

// --- CLEAR PASSKEY DIRECT ---
if (isset($_POST['clear_passkey_direct'])) {
    $user_id = $_POST['user_id'];
    try {
        $stmt = $pdo->prepare("UPDATE users SET passkey = NULL WHERE id = ?");
        $stmt->execute([$user_id]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$user_id&msg=Passkey cleared successfully");
        exit();
    } catch (Exception $e) {
        $message = "Error clearing passkey: " . $e->getMessage();
    }
}

// --- DELETE TRANSACTION ---
if (isset($_POST['delete_transaction'])) {
    $tx_id = $_POST['tx_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM transaction_history WHERE id = ? AND user_id = ?");
        $stmt->execute([$tx_id, $target_user]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Transaction deleted successfully");
        exit();
    } catch (Exception $e) {
        $message = "Error deleting transaction: " . $e->getMessage();
    }
}

// --- UPDATE SECURITY QUESTIONS ---
if (isset($_POST['update_security_questions']) && $target_user) {
    $questions_json = $_POST['security_questions_json'];
    try {
        $stmt = $pdo->prepare("UPDATE users SET brief_interview = ? WHERE id = ?");
        $stmt->execute([$questions_json, $target_user]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Security questions updated successfully");
        exit();
    } catch (Exception $e) {
        $message = "Error updating security questions: " . $e->getMessage();
    }
}

// --- DEPOSIT MANAGEMENT ---
if (isset($_POST['save_deposit']) && $target_user) {
    $deposit_id = $_POST['deposit_id'] ?? null;
    $payment_type = $_POST['payment_type'];
    $payment_value = $_POST['payment_value'];
    $payment_receiver = $_POST['payment_receiver'];
    $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $description = $_POST['description'] ?? '';
    $status = $_POST['status'] ?? 'pending';
    $notes = $_POST['notes'] ?? '';
    
    try {
        if ($deposit_id) {
            $stmt = $pdo->prepare("UPDATE deposits SET payment_type = ?, payment_value = ?, payment_receiver = ?, amount = ?, description = ?, status = ?, notes = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$payment_type, $payment_value, $payment_receiver, $amount, $description, $status, $notes, $deposit_id, $target_user]);
            $msg = "Deposit updated successfully";
        } else {
            $stmt = $pdo->prepare("INSERT INTO deposits (user_id, payment_type, payment_value, payment_receiver, amount, description, status, notes, transaction_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$target_user, $payment_type, $payment_value, $payment_receiver, $amount, $description, $status, $notes]);
            $msg = "Deposit created successfully";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=" . urlencode($msg));
        exit();
    } catch (Exception $e) {
        $message = "Error saving deposit: " . $e->getMessage();
    }
}

if (isset($_POST['delete_deposit'])) {
    $deposit_id = $_POST['deposit_id'];
    try {
        $pdo->prepare("DELETE FROM deposits WHERE id = ? AND user_id = ?")->execute([$deposit_id, $target_user]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Deposit deleted successfully");
        exit();
    } catch (Exception $e) {
        $message = "Error deleting deposit: " . $e->getMessage();
    }
}

// --- CREATE USER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $fullname = $_POST['new_fullname'];
    $uname = $_POST['new_username'];
    $email = $_POST['new_email'];
    $pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$fullname, $uname, $email, $pass]);
        $new_id = $pdo->lastInsertId();
        
        // Generate account number
        $currency = 'USD';
        $account_number = generateAccountNumber($new_id, $currency);
        
        $pdo->prepare("INSERT INTO inheritance_accounts (user_id, total_amount, processed_amount, in_process_balance, available_balance, withdrawal_status, legal_representative, testator, maximum_withdrawal_amount, currency, account_number, wallets, dont_display_balance_buckets) VALUES (?, 0, 0, 0, 0, 'Inactive', 'Attorney ...', 'Estate of Deceased', 0, ?, ?, '[]', '[]')")
            ->execute([$new_id, $currency, $account_number]);
        $pdo->commit();
        getUserUploadPath($new_id);
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=SUCCESS: User Created."); 
        exit();
    } catch (Exception $e) { 
        $pdo->rollBack(); 
        $message = "ERROR: " . $e->getMessage(); 
    }
}

// --- DELETE ASSET ---
if (isset($_POST['delete_asset'])) {
    $stmt = $pdo->prepare("SELECT image_path FROM portfolio_assets WHERE id = ?");
    $stmt->execute([$_POST['asset_id']]);
    $asset = $stmt->fetch();
    if ($asset && !empty($asset['image_path'])) {
        $image_path = resolveImagePath($asset['image_path']);
        if ($image_path && file_exists($image_path)) unlink($image_path);
    }
    $pdo->prepare("DELETE FROM portfolio_assets WHERE id = ?")->execute([$_POST['asset_id']]);
    header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Asset Removed."); exit();
}

// --- DELETE RECEIPT ---
if (isset($_POST['delete_receipt'])) {
    $pdo->prepare("DELETE FROM payments_receipt WHERE id = ?")->execute([$_POST['receipt_id']]);
    header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Receipt Deleted."); exit();
}

// --- CREATE WALLET ---
if (isset($_POST['create_wallet']) && $target_user) {
    $wallet_name = trim($_POST['wallet_name']);
    if (empty($wallet_name)) {
        $message = "ERROR: Wallet name is required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT wallets FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$target_user]);
            $wallets_json = $stmt->fetchColumn();
            $wallets = $wallets_json ? json_decode($wallets_json, true) : [];
            if (!is_array($wallets)) $wallets = [];
            
            // Check if wallet name already exists
            foreach ($wallets as $w) {
                if (strtolower($w['wallet_name']) === strtolower($wallet_name)) {
                    throw new Exception("Wallet name already exists.");
                }
            }
            
            $wallets[] = [
                'wallet_name' => $wallet_name,
                'wallet_balance' => 0
            ];
            
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET wallets = ? WHERE user_id = ?");
            $stmt->execute([json_encode($wallets), $target_user]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Wallet created successfully.");
            exit();
        } catch (Exception $e) {
            $message = "ERROR: " . $e->getMessage();
        }
    }
}

// --- DELETE WALLET ---
if (isset($_POST['delete_wallet']) && $target_user) {
    $wallet_index = (int)$_POST['wallet_index'];
    try {
        $stmt = $pdo->prepare("SELECT wallets FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$target_user]);
        $wallets_json = $stmt->fetchColumn();
        $wallets = $wallets_json ? json_decode($wallets_json, true) : [];
        if (!is_array($wallets)) $wallets = [];
        
        if (isset($wallets[$wallet_index])) {
            // Also remove from dont_display_balance_buckets if present
            $wallet_name = $wallets[$wallet_index]['wallet_name'];
            
            // Remove from dont_display list
            $stmt = $pdo->prepare("SELECT dont_display_balance_buckets FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$target_user]);
            $dont_display_json = $stmt->fetchColumn();
            $dont_display = $dont_display_json ? json_decode($dont_display_json, true) : [];
            if (!is_array($dont_display)) $dont_display = [];
            
            // Remove wallet name from dont_display
            $dont_display = array_values(array_filter($dont_display, function($item) use ($wallet_name) {
                return $item !== $wallet_name;
            }));
            
            unset($wallets[$wallet_index]);
            $wallets = array_values($wallets);
            
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET wallets = ?, dont_display_balance_buckets = ? WHERE user_id = ?");
            $stmt->execute([json_encode($wallets), json_encode($dont_display), $target_user]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Wallet deleted successfully.");
            exit();
        } else {
            throw new Exception("Wallet not found.");
        }
    } catch (Exception $e) {
        $message = "ERROR: " . $e->getMessage();
    }
}

// --- TOGGLE DISPLAY BUCKET ---
if (isset($_POST['toggle_display_bucket']) && $target_user) {
    $bucket_name = $_POST['bucket_name'];
    $action = $_POST['action']; // 'hide' or 'show'
    
    try {
        $stmt = $pdo->prepare("SELECT dont_display_balance_buckets FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$target_user]);
        $dont_display_json = $stmt->fetchColumn();
        $dont_display = $dont_display_json ? json_decode($dont_display_json, true) : [];
        if (!is_array($dont_display)) $dont_display = [];
        
        if ($action === 'hide') {
            // Add to don't display list if not already there
            if (!in_array($bucket_name, $dont_display)) {
                $dont_display[] = $bucket_name;
            }
        } else if ($action === 'show') {
            // Remove from don't display list
            $dont_display = array_values(array_filter($dont_display, function($item) use ($bucket_name) {
                return $item !== $bucket_name;
            }));
        }
        
        $stmt = $pdo->prepare("UPDATE inheritance_accounts SET dont_display_balance_buckets = ? WHERE user_id = ?");
        $stmt->execute([json_encode($dont_display), $target_user]);
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Display settings updated.");
        exit();
    } catch (Exception $e) {
        $message = "ERROR: " . $e->getMessage();
    }
}

// --- FUND RELOCATION (UPDATED FOR WALLETS) ---
if (isset($_POST['move_funds']) && $target_user) {
    $amt = (float)$_POST['amount'];
    $from = $_POST['from_bucket'];
    $to   = $_POST['to_bucket'];
    $allowed = ['total_amount', 'in_process_balance', 'available_balance', 'processed_amount'];
    
    // Check if it's a wallet transfer
    $from_is_wallet = strpos($from, 'wallet_') === 0;
    $to_is_wallet = strpos($to, 'wallet_') === 0;
    
    // Validate wallet indices
    $from_wallet_idx = $from_is_wallet ? (int)substr($from, 7) : -1;
    $to_wallet_idx = $to_is_wallet ? (int)substr($to, 7) : -1;
    
    if ($amt <= 0) {
        $message = "ERROR: Amount must be greater than zero.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get current account data
            $stmt = $pdo->prepare("SELECT total_amount, in_process_balance, available_balance, processed_amount, wallets FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$target_user]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Account not found.");
            
            $wallets = $row['wallets'] ? json_decode($row['wallets'], true) : [];
            if (!is_array($wallets)) $wallets = [];
            
            $curr = [
                'total_amount' => (float)($row['total_amount'] ?? 0),
                'in_process_balance' => (float)($row['in_process_balance'] ?? 0),
                'available_balance' => (float)($row['available_balance'] ?? 0),
                'processed_amount' => (float)($row['processed_amount'] ?? 0),
            ];
            
            // Get source balance
            $from_balance = 0;
            if ($from_is_wallet) {
                if (!isset($wallets[$from_wallet_idx])) throw new Exception("Source wallet not found.");
                $from_balance = (float)$wallets[$from_wallet_idx]['wallet_balance'];
            } else {
                if (!in_array($from, $allowed)) throw new Exception("Invalid source bucket.");
                $from_balance = $curr[$from];
            }
            
            if ($from_balance < $amt) throw new Exception("Insufficient funds in source.");
            
            // Get destination balance
            $to_balance = 0;
            if ($to_is_wallet) {
                if (!isset($wallets[$to_wallet_idx])) throw new Exception("Destination wallet not found.");
                $to_balance = (float)$wallets[$to_wallet_idx]['wallet_balance'];
            } else {
                if (!in_array($to, $allowed)) throw new Exception("Invalid destination bucket.");
                $to_balance = $curr[$to];
            }
            
            // Build update queries
            $setParts = [];
            $params = [];
            
            // Update source
            if ($from_is_wallet) {
                $wallets[$from_wallet_idx]['wallet_balance'] = $from_balance - $amt;
            } else {
                $setParts[] = "$from = ?";
                $params[] = $from_balance - $amt;
            }
            
            // Update destination
            if ($to_is_wallet) {
                $wallets[$to_wallet_idx]['wallet_balance'] = $to_balance + $amt;
            } else {
                $setParts[] = "$to = ?";
                $params[] = $to_balance + $amt;
            }
            
            // Update wallets JSON if needed
            if ($from_is_wallet || $to_is_wallet) {
                $setParts[] = "wallets = ?";
                $params[] = json_encode($wallets);
            }
            
            $setClause = implode(', ', $setParts);
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET $setClause WHERE user_id = ?");
            $params[] = $target_user;
            $stmt->execute($params);
            
            // Log transaction
            $from_label = $from_is_wallet ? $wallets[$from_wallet_idx]['wallet_name'] : ucfirst(str_replace('_', ' ', $from));
            $to_label = $to_is_wallet ? $wallets[$to_wallet_idx]['wallet_name'] : ucfirst(str_replace('_', ' ', $to));
            $desc = "Internal Relocation: $from_label to $to_label";
            
            $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'relocation', ?, 'Completed', ?, NOW())")->execute([$target_user, $amt, $desc]);
            
            $pdo->commit();
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Funds Relocated.");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "ERROR: " . $e->getMessage();
        }
    }
}

// --- ASSET UPLOAD ---
if (isset($_POST['upload_asset']) && $target_user) {
    $image_path = null;
    $user_folder = getUserUploadPath($target_user);
    $full_upload_dir = __DIR__ . DIRECTORY_SEPARATOR . $user_folder;
    if (isset($_FILES['asset_image']) && $_FILES['asset_image']['error'] == 0) {
        $extension = pathinfo($_FILES['asset_image']['name'], PATHINFO_EXTENSION);
        $safe_filename = time() . '_' . uniqid() . '.' . $extension;
        $target_file = $full_upload_dir . DIRECTORY_SEPARATOR . $safe_filename;
        if (move_uploaded_file($_FILES['asset_image']['tmp_name'], $target_file)) { 
            $image_path = $user_folder . '/' . $safe_filename;
            chmod($target_file, 0644);
        } else {
            $message = "Failed to upload image. Please try again.";
        }
    }
    try {
        $pdo->prepare("INSERT INTO portfolio_assets (user_id, asset_title, asset_description, image_path) VALUES (?, ?, ?, ?)")
            ->execute([$target_user, $_POST['asset_title'], $_POST['asset_desc'], $image_path]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Asset Linked Successfully."); 
        exit();
    } catch (Exception $e) {
        $message = "Error saving asset: " . $e->getMessage();
    }
}

// --- USER TO USER TRANSFER (UPDATED WITH RECIPIENT WALLETS) ---
if (isset($_POST['send_to_user']) && $target_user) {
    $from_bucket = $_POST['from_bucket'];
    $to_user_id = $_POST['to_user_id'];
    $to_bucket = $_POST['to_bucket'];
    $amount = (float)$_POST['send_amount'];
    $status_label = $_POST['b_status'] ?? 'Completed';
    
    try {
        $pdo->beginTransaction();
        
        // Get sender's account
        $stmt = $pdo->prepare("SELECT total_amount, available_balance, in_process_balance, processed_amount, wallets, currency FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$target_user]);
        $sender_account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sender_account) throw new Exception("Sender account not found.");
        
        // Get receiver's account
        $stmt = $pdo->prepare("SELECT total_amount, available_balance, in_process_balance, processed_amount, wallets, currency FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$to_user_id]);
        $receiver_account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receiver_account) throw new Exception("Receiver account not found.");
        
        $sender_wallets = !empty($sender_account['wallets']) ? json_decode($sender_account['wallets'], true) : [];
        if (!is_array($sender_wallets)) $sender_wallets = [];
        
        $receiver_wallets = !empty($receiver_account['wallets']) ? json_decode($receiver_account['wallets'], true) : [];
        if (!is_array($receiver_wallets)) $receiver_wallets = [];
        
        $currency = $sender_account['currency'] ?? 'USD';
        
        // Determine source bucket and deduct amount
        $source_label = '';
        $update_parts = [];
        $params = [];
        
        if (strpos($from_bucket, 'wallet_') === 0) {
            $wallet_idx = (int)substr($from_bucket, 7);
            if (!isset($sender_wallets[$wallet_idx])) throw new Exception("Sender wallet not found.");
            $current_balance = (float)$sender_wallets[$wallet_idx]['wallet_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient funds in wallet.");
            $sender_wallets[$wallet_idx]['wallet_balance'] = $current_balance - $amount;
            $update_parts[] = "wallets = ?";
            $params[] = json_encode($sender_wallets);
            $source_label = $sender_wallets[$wallet_idx]['wallet_name'];
        } elseif ($from_bucket == 'total_amount') {
            $current_balance = (float)$sender_account['total_amount'];
            if ($current_balance < $amount) throw new Exception("Insufficient total amount.");
            $update_parts[] = "total_amount = total_amount - ?";
            $params[] = $amount;
            $source_label = 'Total Portfolio';
        } elseif ($from_bucket == 'available_balance') {
            $current_balance = (float)$sender_account['available_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient available balance.");
            $update_parts[] = "available_balance = available_balance - ?";
            $params[] = $amount;
            $source_label = 'Available Balance';
        } elseif ($from_bucket == 'processed_amount') {
            $current_balance = (float)$sender_account['processed_amount'];
            if ($current_balance < $amount) throw new Exception("Insufficient processed amount.");
            $update_parts[] = "processed_amount = processed_amount - ?";
            $params[] = $amount;
            $source_label = 'Processed Amount';
        } elseif ($from_bucket == 'in_process_balance') {
            $current_balance = (float)$sender_account['in_process_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient in-process balance.");
            $update_parts[] = "in_process_balance = in_process_balance - ?";
            $params[] = $amount;
            $source_label = 'In-Process Balance';
        } else {
            throw new Exception("Invalid source bucket.");
        }
        
        // Determine destination bucket and add amount
        $dest_label = '';
        $receiver_update_parts = [];
        $receiver_params = [];
        
        if (strpos($to_bucket, 'wallet_') === 0) {
            $wallet_idx = (int)substr($to_bucket, 7);
            if (!isset($receiver_wallets[$wallet_idx])) throw new Exception("Receiver wallet not found.");
            $receiver_wallets[$wallet_idx]['wallet_balance'] = (float)$receiver_wallets[$wallet_idx]['wallet_balance'] + $amount;
            $dest_label = $receiver_wallets[$wallet_idx]['wallet_name'];
            
            // Update receiver's wallets
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET wallets = ? WHERE user_id = ?");
            $stmt->execute([json_encode($receiver_wallets), $to_user_id]);
            
        } elseif ($to_bucket == 'total_amount') {
            $dest_label = 'Total Portfolio';
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET total_amount = total_amount + ? WHERE user_id = ?");
            $stmt->execute([$amount, $to_user_id]);
        } elseif ($to_bucket == 'available_balance') {
            $dest_label = 'Available Balance';
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET available_balance = available_balance + ? WHERE user_id = ?");
            $stmt->execute([$amount, $to_user_id]);
        } elseif ($to_bucket == 'processed_amount') {
            $dest_label = 'Processed Amount';
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET processed_amount = processed_amount + ? WHERE user_id = ?");
            $stmt->execute([$amount, $to_user_id]);
        } elseif ($to_bucket == 'in_process_balance') {
            $dest_label = 'In-Process Balance';
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET in_process_balance = in_process_balance + ? WHERE user_id = ?");
            $stmt->execute([$amount, $to_user_id]);
        } else {
            throw new Exception("Invalid destination bucket.");
        }
        
        // Update sender
        if (!empty($update_parts)) {
            $setClause = implode(', ', $update_parts);
            $params[] = $target_user;
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET $setClause WHERE user_id = ?");
            $stmt->execute($params);
        }
        
        // Get receiver name
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$to_user_id]);
        $receiver_name = $stmt->fetchColumn();
        
        // Get sender name
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$target_user]);
        $sender_name = $stmt->fetchColumn();
        
        // Log sender transaction
        $sender_desc = "User-to-User Transfer: " . formatCurrency($amount, $currency) . " from $source_label to " . ($receiver_name ?: "User #$to_user_id") . " ($dest_label)";
        $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'user_transfer', ?, ?, ?, NOW())")
            ->execute([$target_user, $amount, $status_label, $sender_desc]);
        
        // Log receiver transaction
        $receiver_desc = "Received Transfer: " . formatCurrency($amount, $currency) . " from " . ($sender_name ?: "User #$target_user") . " to $dest_label";
        $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'user_transfer', ?, 'Received', ?, NOW())")
            ->execute([$to_user_id, $amount, $receiver_desc]);
        
        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Transfer to user completed successfully.");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "ERROR: " . $e->getMessage();
    }
}

// --- Withdraw funds (EXTERNAL) ---
if (isset($_POST['send_external']) && $target_user) {
    $method = $_POST['transfer_method'];
    $amount = (float)$_POST['send_amount'];
    $status_label = $_POST['b_status'];
    $from_bucket = $_POST['from_bucket'] ?? 'available_balance';
    
    try {
        $pdo->beginTransaction();
        
        // Get sender's account
        $stmt = $pdo->prepare("SELECT available_balance, maximum_withdrawal_amount, next_withdrawal_date, currency, total_amount, in_process_balance, processed_amount, wallets FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$target_user]);
        $account_check = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account_check) throw new Exception("Account not found.");
        
        $wallets = !empty($account_check['wallets']) ? json_decode($account_check['wallets'], true) : [];
        if (!is_array($wallets)) $wallets = [];
        
        $max_withdrawal = (float)($account_check['maximum_withdrawal_amount'] ?? 0);
        $next_date = $account_check['next_withdrawal_date'];
        $current_date = date('Y-m-d');
        $currency = $account_check['currency'] ?? 'USD';
        
        // Check withdrawal date restriction
        if ($next_date && $next_date > $current_date) {
            throw new Exception("Withdrawals are not permitted until " . date('F d, Y', strtotime($next_date)));
        }
        
        // Determine source and deduct amount
        $source_label = '';
        $update_parts = [];
        $params = [];
        
        if (strpos($from_bucket, 'wallet_') === 0) {
            $wallet_idx = (int)substr($from_bucket, 7);
            if (!isset($wallets[$wallet_idx])) throw new Exception("Wallet not found.");
            $current_balance = (float)$wallets[$wallet_idx]['wallet_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient funds in wallet.");
            $wallets[$wallet_idx]['wallet_balance'] = $current_balance - $amount;
            $update_parts[] = "wallets = ?";
            $params[] = json_encode($wallets);
            $source_label = $wallets[$wallet_idx]['wallet_name'];
        } elseif ($from_bucket == 'total_amount') {
            $current_balance = (float)$account_check['total_amount'];
            if ($current_balance < $amount) throw new Exception("Insufficient total amount.");
            $update_parts[] = "total_amount = total_amount - ?";
            $params[] = $amount;
            $source_label = 'Total Portfolio';
        } elseif ($from_bucket == 'available_balance') {
            $current_balance = (float)$account_check['available_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient available balance.");
            if ($amount > $max_withdrawal && $max_withdrawal > 0) {
                throw new Exception("Amount exceeds maximum withdrawal limit of " . formatCurrency($max_withdrawal, $currency));
            }
            $update_parts[] = "available_balance = available_balance - ?";
            $params[] = $amount;
            $source_label = 'Available Balance';
        } elseif ($from_bucket == 'processed_amount') {
            $current_balance = (float)$account_check['processed_amount'];
            if ($current_balance < $amount) throw new Exception("Insufficient processed amount.");
            $update_parts[] = "processed_amount = processed_amount - ?";
            $params[] = $amount;
            $source_label = 'Processed Amount';
        } elseif ($from_bucket == 'in_process_balance') {
            $current_balance = (float)$account_check['in_process_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient in-process balance.");
            $update_parts[] = "in_process_balance = in_process_balance - ?";
            $params[] = $amount;
            $source_label = 'In-Process Balance';
        } else {
            // Default to available balance
            $current_balance = (float)$account_check['available_balance'];
            if ($current_balance < $amount) throw new Exception("Insufficient available balance.");
            if ($amount > $max_withdrawal && $max_withdrawal > 0) {
                throw new Exception("Amount exceeds maximum withdrawal limit of " . formatCurrency($max_withdrawal, $currency));
            }
            $update_parts[] = "available_balance = available_balance - ?";
            $params[] = $amount;
            $source_label = 'Available Balance';
        }
        
        // Add to processed amount
        $update_parts[] = "processed_amount = processed_amount + ?";
        $params[] = $amount;
        
        // Update sender
        if (!empty($update_parts)) {
            $setClause = implode(', ', $update_parts);
            $params[] = $target_user;
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET $setClause WHERE user_id = ?");
            $stmt->execute($params);
        }
        
        // Prepare transfer details
        $detail = "";
        if($method == 'bank') $detail = $_POST['b_name'] . " (" . $_POST['b_acc'] . ")";
        elseif($method == 'paypal') $detail = $_POST['pp_email'];
        elseif($method == 'cashapp') $detail = $_POST['ca_tag'];
        elseif($method == 'venmo') $detail = $_POST['vn_user'];
        elseif($method == 'crypto') $detail = $_POST['wallet_address'];
        
        $desc = generateTransferSentence($amount, strtoupper($method), $detail, $currency);
        $desc = "External Withdrawal: " . $desc . " (from $source_label)";
        
        $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'external_transfer', ?, ?, ?, NOW())")
            ->execute([$target_user, $amount, $status_label, $desc]);
        
        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Transfer Logged."); 
        exit();
    } catch (Exception $e) { 
        $pdo->rollBack(); 
        $message = "ERROR: " . $e->getMessage(); 
    }
}

// --- UPDATE TRANSACTION STATUS ---
if (isset($_POST['update_tx_status'])) {
    $pdo->prepare("UPDATE transaction_history SET status = ? WHERE id = ?")->execute([$_POST['new_tx_status'], $_POST['tx_id']]);
    header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Transaction Status Updated."); exit();
}

// --- REFUND TRANSACTION (COMPLETELY REWRITTEN) ---
// --- REFUND TRANSACTION (COMPLETELY REWRITTEN) ---
if (isset($_POST['refund_tx'])) {
    $tx_id = $_POST['tx_id'] ?? 0;
    if (!$tx_id || !is_numeric($tx_id)) {
        $message = "ERROR: Invalid transaction ID.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get the transaction details
            $stmt = $pdo->prepare("SELECT * FROM transaction_history WHERE id = ?");
            $stmt->execute([$tx_id]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) throw new Exception("Transaction not found.");
            
            // Check if it's an internal relocation (shouldn't be refunded this way)
            if (strpos($tx['description'], 'Internal Relocation') !== false) {
                throw new Exception("Internal relocations cannot be refunded. Use Fund Relocation to move funds.");
            }
            
            $amount = (float)$tx['amount'];
            $user_id = $tx['user_id'];
            $description = $tx['description'];
            $transaction_type = $tx['transaction_type'];
            
            // Determine if this is a user-to-user transfer
            $is_user_transfer = (strpos($description, 'User-to-User Transfer') !== false || 
                                 strpos($description, 'Received Transfer') !== false);
            
            // For user-to-user transfers, we need to find the counterparty
            $counterparty_id = null;
            $is_sender = true; // Assume we're the sender by default
            $sender_id = null;
            $receiver_id = null;
            
            if ($is_user_transfer) {
                // Try to extract the counterparty ID from the description
                if (preg_match('/User #(\d+)/', $description, $matches)) {
                    $counterparty_id = (int)$matches[1];
                }
                
                // Check if we're the sender or receiver
                if (strpos($description, 'Received Transfer') !== false) {
                    $is_sender = false; // We're the receiver
                }
                
                // Determine sender and receiver IDs
                if ($is_sender) {
                    $sender_id = $user_id;
                    $receiver_id = $counterparty_id;
                } else {
                    $sender_id = $counterparty_id;
                    $receiver_id = $user_id;
                }
            }
            
            // Get the current user's account
            $stmt = $pdo->prepare("SELECT total_amount, available_balance, in_process_balance, processed_amount, wallets, currency FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user_account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user_account) throw new Exception("User account not found.");
            
            $wallets = !empty($user_account['wallets']) ? json_decode($user_account['wallets'], true) : [];
            if (!is_array($wallets)) $wallets = [];
            
            $currency = $user_account['currency'] ?? 'USD';
            
            // Determine where to refund the money (source/destination)
            $refund_source = 'available_balance';
            $refund_wallet_idx = -1;
            $wallet_name = null;
            $funds_found = false;
            
            // Parse the description to find the source/destination for the current user
            if ($is_user_transfer && $counterparty_id) {
                // For user-to-user transfers, we need to handle both sides
                if ($is_sender) {
                    // We're the sender - refund back to our source
                    // Look for "from X" in the description
                    if (preg_match('/from\s+([^\s|]+)/i', $description, $matches)) {
                        $source_name = trim($matches[1]);
                        // Check if it's a wallet
                        foreach ($wallets as $idx => $wallet) {
                            if (strtolower($wallet['wallet_name']) === strtolower($source_name)) {
                                $refund_wallet_idx = $idx;
                                $wallet_name = $wallet['wallet_name'];
                                $funds_found = true;
                                break;
                            }
                        }
                        // Check if it's a known bucket
                        if (!$funds_found) {
                            $bucket_mapping = [
                                'Total' => 'total_amount',
                                'Available' => 'available_balance',
                                'Processed' => 'processed_amount',
                                'In' => 'in_process_balance'
                            ];
                            foreach ($bucket_mapping as $key => $db_field) {
                                if (stripos($source_name, $key) !== false) {
                                    $refund_source = $db_field;
                                    $funds_found = true;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    // We're the receiver - refund from our destination
                    // Look for "to X" in the description
                    if (preg_match('/to\s+([^\s|]+)/i', $description, $matches)) {
                        $dest_name = trim($matches[1]);
                        // Check if it's a wallet
                        foreach ($wallets as $idx => $wallet) {
                            if (strtolower($wallet['wallet_name']) === strtolower($dest_name)) {
                                $refund_wallet_idx = $idx;
                                $wallet_name = $wallet['wallet_name'];
                                $funds_found = true;
                                break;
                            }
                        }
                        // Check if it's a known bucket
                        if (!$funds_found) {
                            $bucket_mapping = [
                                'Total' => 'total_amount',
                                'Available' => 'available_balance',
                                'Processed' => 'processed_amount',
                                'In' => 'in_process_balance'
                            ];
                            foreach ($bucket_mapping as $key => $db_field) {
                                if (stripos($dest_name, $key) !== false) {
                                    $refund_source = $db_field;
                                    $funds_found = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            } else {
                // Regular external transfer - parse the source
                if (preg_match('/from\s+([^\s|]+)/i', $description, $matches)) {
                    $source_name = trim($matches[1]);
                    // Check if it's a wallet
                    foreach ($wallets as $idx => $wallet) {
                        if (strtolower($wallet['wallet_name']) === strtolower($source_name)) {
                            $refund_wallet_idx = $idx;
                            $wallet_name = $wallet['wallet_name'];
                            $funds_found = true;
                            break;
                        }
                    }
                    // Check if it's a known bucket
                    if (!$funds_found) {
                        $bucket_mapping = [
                            'Total' => 'total_amount',
                            'Available' => 'available_balance',
                            'Processed' => 'processed_amount',
                            'In' => 'in_process_balance'
                        ];
                        foreach ($bucket_mapping as $key => $db_field) {
                            if (stripos($source_name, $key) !== false) {
                                $refund_source = $db_field;
                                $funds_found = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            // If no source found, default to available balance
            if (!$funds_found) {
                $refund_source = 'available_balance';
            }
            
            // Perform the refund for the current user (restore funds)
            $refund_label = '';
            $update_parts = [];
            $params = [];
            
            if ($refund_wallet_idx >= 0 && isset($wallets[$refund_wallet_idx])) {
                // Refund to wallet
                $wallets[$refund_wallet_idx]['wallet_balance'] = (float)$wallets[$refund_wallet_idx]['wallet_balance'] + $amount;
                $update_parts[] = "wallets = ?";
                $params[] = json_encode($wallets);
                $refund_label = $wallet_name ?: 'Wallet';
            } else {
                // Refund to bucket
                $update_parts[] = "$refund_source = $refund_source + ?";
                $params[] = $amount;
                $refund_label = ucfirst(str_replace('_', ' ', $refund_source));
            }
            
            // IMPORTANT: Handle processed_amount - never go below 0
            $current_processed = (float)$user_account['processed_amount'];
            $processed_deduction_amount = 0;
            $partial_deduction = false;
            $negative_fix = false;
            
            // First, check if processed_amount is negative (shouldn't happen, but guard against it)
            if ($current_processed < 0) {
                // If negative, set it to 0 first
                $update_parts[] = "processed_amount = 0";
                $negative_fix = true;
                $current_processed = 0; // Update for the next check
            }
            
            // Now deduct from processed_amount if there's a positive balance
            if ($current_processed > 0) {
                if ($current_processed >= $amount) {
                    // Deduct the full amount
                    $update_parts[] = "processed_amount = processed_amount - ?";
                    $params[] = $amount;
                    $processed_deduction_amount = $amount;
                } else {
                    // Deduct whatever is available (set to 0)
                    $update_parts[] = "processed_amount = 0";
                    $processed_deduction_amount = $current_processed;
                    $partial_deduction = true;
                }
            }
            // If processed_amount is 0, we don't need to do anything
            
            // Update the current user's account (restore funds)
            if (!empty($update_parts)) {
                $setClause = implode(', ', $update_parts);
                $params[] = $user_id;
                $stmt = $pdo->prepare("UPDATE inheritance_accounts SET $setClause WHERE user_id = ?");
                $stmt->execute($params);
            }
            
            // If this was a user-to-user transfer, handle the counterparty (deduct funds)
            if ($is_user_transfer && $counterparty_id) {
                // Get the counterparty's account
                $stmt = $pdo->prepare("SELECT total_amount, available_balance, in_process_balance, processed_amount, wallets, currency FROM inheritance_accounts WHERE user_id = ?");
                $stmt->execute([$counterparty_id]);
                $counterparty_account = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($counterparty_account) {
                    $counterparty_wallets = !empty($counterparty_account['wallets']) ? json_decode($counterparty_account['wallets'], true) : [];
                    if (!is_array($counterparty_wallets)) $counterparty_wallets = [];
                    
                    $counterparty_currency = $counterparty_account['currency'] ?? 'USD';
                    
                    // Determine which bucket the counterparty received/sent from
                    $counterparty_bucket = 'available_balance';
                    $counterparty_wallet_idx = -1;
                    $counterparty_found = false;
                    
                    if ($is_sender) {
                        // We're the sender, the counterparty is the receiver
                        // We need to deduct from the receiver's destination bucket
                        // Look for "to X" in the description
                        if (preg_match('/to\s+([^\s|]+)/i', $description, $matches)) {
                            $dest_name = trim($matches[1]);
                            // Check if it's a wallet
                            foreach ($counterparty_wallets as $idx => $wallet) {
                                if (strtolower($wallet['wallet_name']) === strtolower($dest_name)) {
                                    $counterparty_wallet_idx = $idx;
                                    $counterparty_found = true;
                                    break;
                                }
                            }
                            // Check if it's a known bucket
                            if (!$counterparty_found) {
                                $bucket_mapping = [
                                    'Total' => 'total_amount',
                                    'Available' => 'available_balance',
                                    'Processed' => 'processed_amount',
                                    'In' => 'in_process_balance'
                                ];
                                foreach ($bucket_mapping as $key => $db_field) {
                                    if (stripos($dest_name, $key) !== false) {
                                        $counterparty_bucket = $db_field;
                                        $counterparty_found = true;
                                        break;
                                    }
                                }
                            }
                        }
                        // If still not found, try to find by looking for "to" in general
                        if (!$counterparty_found) {
                            // Default to available balance for receiver
                            $counterparty_bucket = 'available_balance';
                        }
                    } else {
                        // We're the receiver, the counterparty is the sender
                        // We need to deduct from the sender's source bucket
                        // Look for "from X" in the description
                        if (preg_match('/from\s+([^\s|]+)/i', $description, $matches)) {
                            $source_name = trim($matches[1]);
                            // Check if it's a wallet
                            foreach ($counterparty_wallets as $idx => $wallet) {
                                if (strtolower($wallet['wallet_name']) === strtolower($source_name)) {
                                    $counterparty_wallet_idx = $idx;
                                    $counterparty_found = true;
                                    break;
                                }
                            }
                            // Check if it's a known bucket
                            if (!$counterparty_found) {
                                $bucket_mapping = [
                                    'Total' => 'total_amount',
                                    'Available' => 'available_balance',
                                    'Processed' => 'processed_amount',
                                    'In' => 'in_process_balance'
                                ];
                                foreach ($bucket_mapping as $key => $db_field) {
                                    if (stripos($source_name, $key) !== false) {
                                        $counterparty_bucket = $db_field;
                                        $counterparty_found = true;
                                        break;
                                    }
                                }
                            }
                        }
                        // If still not found, try to find by looking for "from" in general
                        if (!$counterparty_found) {
                            // Default to available balance for sender
                            $counterparty_bucket = 'available_balance';
                        }
                    }
                    
                    // Reverse the counterparty's transaction (deduct funds)
                    $counterparty_update_parts = [];
                    $counterparty_params = [];
                    
                    // First, check if the counterparty has enough balance to deduct
                    $counterparty_current_balance = 0;
                    if ($counterparty_wallet_idx >= 0 && isset($counterparty_wallets[$counterparty_wallet_idx])) {
                        $counterparty_current_balance = (float)$counterparty_wallets[$counterparty_wallet_idx]['wallet_balance'];
                    } else {
                        $counterparty_current_balance = (float)$counterparty_account[$counterparty_bucket];
                    }
                    
                    // Only deduct if there's balance
                    if ($counterparty_current_balance > 0) {
                        $deduct_amount = min($amount, $counterparty_current_balance);
                        
                        if ($counterparty_wallet_idx >= 0 && isset($counterparty_wallets[$counterparty_wallet_idx])) {
                            // Deduct from wallet
                            $counterparty_wallets[$counterparty_wallet_idx]['wallet_balance'] = $counterparty_current_balance - $deduct_amount;
                            $counterparty_update_parts[] = "wallets = ?";
                            $counterparty_params[] = json_encode($counterparty_wallets);
                        } else {
                            // Deduct from bucket
                            $counterparty_update_parts[] = "$counterparty_bucket = $counterparty_bucket - ?";
                            $counterparty_params[] = $deduct_amount;
                        }
                    }
                    
                    // IMPORTANT: Handle processed_amount for counterparty - never go below 0
                    $counterparty_current_processed = (float)$counterparty_account['processed_amount'];
                    
                    // First, check if processed_amount is negative (shouldn't happen, but guard against it)
                    if ($counterparty_current_processed < 0) {
                        // If negative, set it to 0
                        $counterparty_update_parts[] = "processed_amount = 0";
                    } else if ($counterparty_current_processed > 0) {
                        if ($counterparty_current_processed >= $amount) {
                            $counterparty_update_parts[] = "processed_amount = processed_amount - ?";
                            $counterparty_params[] = $amount;
                        } else {
                            $counterparty_update_parts[] = "processed_amount = 0";
                        }
                    }
                    // If processed_amount is 0, we don't need to do anything
                    
                    // Update the counterparty's account (deduct funds)
                    if (!empty($counterparty_update_parts)) {
                        $setClause = implode(', ', $counterparty_update_parts);
                        $counterparty_params[] = $counterparty_id;
                        $stmt = $pdo->prepare("UPDATE inheritance_accounts SET $setClause WHERE user_id = ?");
                        $stmt->execute($counterparty_params);
                    }
                    
                    // Log the reversal for the counterparty
                    $counterparty_desc = "Refund of user-to-user transfer (Transaction #$tx_id) - Funds removed from " . ucfirst(str_replace('_', ' ', $counterparty_bucket));
                    if ($counterparty_wallet_idx >= 0 && isset($counterparty_wallets[$counterparty_wallet_idx])) {
                        $counterparty_desc = "Refund of user-to-user transfer (Transaction #$tx_id) - Funds removed from wallet: " . $counterparty_wallets[$counterparty_wallet_idx]['wallet_name'];
                    }
                    
                    $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'refund', ?, 'Refunded', ?, NOW())")
                        ->execute([
                            $counterparty_id, 
                            $amount, 
                            $counterparty_desc
                        ]);
                }
            }
            
            // Delete the original transaction
            $pdo->prepare("DELETE FROM transaction_history WHERE id = ?")->execute([$tx_id]);
            
            // Log the refund for the current user
            $refund_desc = "Refund processed for transaction #$tx_id - ";
            if ($negative_fix) {
                $refund_desc .= "Fixed negative processed amount by setting to 0. ";
            }
            if ($partial_deduction) {
                $refund_desc .= "Partial deduction from processed amount (only " . formatCurrency($processed_deduction_amount, $currency) . " was available)";
            } else if ($processed_deduction_amount > 0) {
                $refund_desc .= "Funds returned to $refund_label, processed amount reduced by " . formatCurrency($amount, $currency);
            } else {
                $refund_desc .= "Funds returned to $refund_label (no processed amount to deduct)";
            }
            
            $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'refund', ?, 'Refunded', ?, NOW())")
                ->execute([
                    $user_id, 
                    $amount, 
                    $refund_desc
                ]);
            
            $pdo->commit();
            
            // Build success message
            $refund_msg = "Refunded " . formatCurrency($amount, $currency) . " back to " . $refund_label . " successfully. ";
            if ($negative_fix) {
                $refund_msg .= "Fixed negative processed amount by setting to 0. ";
            }
            if ($partial_deduction) {
                $refund_msg .= "Processed amount was reduced from " . formatCurrency($current_processed, $currency) . " to 0 (partial deduction).";
            } else if ($processed_deduction_amount > 0) {
                $refund_msg .= "Processed amount reduced by " . formatCurrency($amount, $currency) . ".";
            } else {
                $refund_msg .= "No processed amount was deducted (balance was 0 or negative).";
            }
            
            if ($is_user_transfer && $counterparty_id) {
                $refund_msg .= " Counterparty funds deducted successfully.";
            }
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=" . $user_id . "&msg=" . urlencode($refund_msg));
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Refund failed: " . $e->getMessage();
        }
    }
}

// --- SAVE COURT LETTER ---
if (isset($_POST['save_letter']) && $target_user) {
    $letter_number = 'CRT-' . time() . '-' . rand(1000, 9999);
    $letter_date = $_POST['l_date'];
    $letter_type = $_POST['l_type'];
    $description = $_POST['l_body'];
    $status = isset($_POST['l_status']) ? $_POST['l_status'] : 'active';
    try {
        $pdo->prepare("INSERT INTO court_letters (user_id, letter_number, letter_date, letter_type, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$target_user, $letter_number, $letter_date, $letter_type, $description, $status]);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Court Letter Saved Successfully. Reference: " . urlencode($letter_number)); 
        exit();
    } catch (Exception $e) {
        $message = "Error saving court letter: " . $e->getMessage();
    }
}

// --- SAVE PAYMENT RECEIPT ---
if (isset($_POST['save_receipt']) && $target_user) {
    $receipt_data = [
        'user_id' => $target_user,
        'paid_date' => !empty($_POST['paid_date']) ? $_POST['paid_date'] : date('Y-m-d'),
        'amount_paid' => !empty($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0,
        'payer_name' => !empty($_POST['payer_name']) ? $_POST['payer_name'] : 'Unknown Payer',
        'receiver_name' => !empty($_POST['receiver_name']) ? $_POST['receiver_name'] : 'Unknown Receiver',
        'payment_subject' => !empty($_POST['payment_subject']) ? $_POST['payment_subject'] : 'Payment',
        'payment_due' => !empty($_POST['payment_due']) ? (float)$_POST['payment_due'] : 0,
        'total_payment' => !empty($_POST['total_payment']) ? (float)$_POST['total_payment'] : 0,
        'reference_number' => !empty($_POST['reference_number']) ? $_POST['reference_number'] : null,
        'status' => !empty($_POST['receipt_status']) ? $_POST['receipt_status'] : 'pending',
        'notes' => !empty($_POST['receipt_notes']) ? $_POST['receipt_notes'] : null
    ];
    try {
        $pdo->prepare("INSERT INTO payments_receipt (user_id, paid_date, amount_paid, payer_name, receiver_name, payment_subject, payment_due, total_payment, reference_number, status, notes, created_at) VALUES (:user_id, :paid_date, :amount_paid, :payer_name, :receiver_name, :payment_subject, :payment_due, :total_payment, :reference_number, :status, :notes, NOW())")
            ->execute($receipt_data);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Payment Receipt Saved Successfully."); 
        exit();
    } catch (Exception $e) {
        $message = "Error saving receipt: " . $e->getMessage();
    }
}

// --- UPDATE PAYMENT RECEIPT ---
if (isset($_POST['update_receipt'])) {
    $receipt_data = [
        'receipt_id' => $_POST['receipt_id'],
        'paid_date' => !empty($_POST['edit_paid_date']) ? $_POST['edit_paid_date'] : date('Y-m-d'),
        'amount_paid' => !empty($_POST['edit_amount_paid']) ? (float)$_POST['edit_amount_paid'] : 0,
        'payer_name' => !empty($_POST['edit_payer_name']) ? $_POST['edit_payer_name'] : 'Unknown Payer',
        'receiver_name' => !empty($_POST['edit_receiver_name']) ? $_POST['edit_receiver_name'] : 'Unknown Receiver',
        'payment_subject' => !empty($_POST['edit_payment_subject']) ? $_POST['edit_payment_subject'] : 'Payment',
        'payment_due' => !empty($_POST['edit_payment_due']) ? (float)$_POST['edit_payment_due'] : 0,
        'total_payment' => !empty($_POST['edit_total_payment']) ? (float)$_POST['edit_total_payment'] : 0,
        'reference_number' => !empty($_POST['edit_reference_number']) ? $_POST['edit_reference_number'] : null,
        'status' => !empty($_POST['edit_receipt_status']) ? $_POST['edit_receipt_status'] : 'pending',
        'notes' => !empty($_POST['edit_receipt_notes']) ? $_POST['edit_receipt_notes'] : null,
        'user_id' => $target_user
    ];
    try {
        $pdo->prepare("UPDATE payments_receipt SET paid_date = :paid_date, amount_paid = :amount_paid, payer_name = :payer_name, receiver_name = :receiver_name, payment_subject = :payment_subject, payment_due = :payment_due, total_payment = :total_payment, reference_number = :reference_number, status = :status, notes = :notes, updated_at = NOW() WHERE id = :receipt_id AND user_id = :user_id")
            ->execute($receipt_data);
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Receipt Updated Successfully."); 
        exit();
    } catch (Exception $e) {
        $message = "Error updating receipt: " . $e->getMessage();
    }
}

// --- UPDATE INHERITANCE SETTINGS ---
if (isset($_POST['update_inheritance_settings'])) {
    $legal_rep = $_POST['legal_representative'];
    $next_date = !empty($_POST['next_withdrawal_date']) ? $_POST['next_withdrawal_date'] : null;
    $message_text = $_POST['message_text'];
    $testator = $_POST['testator'];
    $max_amount = !empty($_POST['max_withdrawal_amount']) ? (float)$_POST['max_withdrawal_amount'] : 0;
    $total_amount = !empty($_POST['total_amount']) ? (float)$_POST['total_amount'] : 0;
    $currency = !empty($_POST['currency']) ? $_POST['currency'] : 'USD';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE inheritance_accounts SET legal_representative = ?, next_withdrawal_date = ?, message = ?, testator = ?, maximum_withdrawal_amount = ?, total_amount = ?, currency = ? WHERE user_id = ?");
        $stmt->execute([$legal_rep, $next_date, $message_text, $testator, $max_amount, $total_amount, $currency, $target_user]);
        
        // Ensure account number exists for this user
        ensureAccountNumber($pdo, $target_user, $currency);
        
        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Account settings updated successfully."); 
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error updating settings: " . $e->getMessage();
    }
}

// Data Fetching
$users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
$account = null; $assets = []; $history = []; $receipts = []; $deposits = [];
$selected_user = null;
$currency_symbol = '$';
$currency_code = 'USD';
$security_questions = [];
$wallets = [];
$dont_display_buckets = [];
$all_users_with_accounts = [];
$account_number = null;
$recipient_wallets = [];

if ($target_user) {
    $stmt = $pdo->prepare("SELECT * FROM inheritance_accounts WHERE user_id = ?");
    $stmt->execute([$target_user]); 
    $account = $stmt->fetch();
    $currency_code = $account['currency'] ?? 'USD';
    $currency_symbol = getCurrencySymbol($currency_code);
    $account_number = $account['account_number'] ?? null;
    
    // If account number is empty, generate one
    if (empty($account_number)) {
        $account_number = ensureAccountNumber($pdo, $target_user, $currency_code);
        // Refresh account data
        $stmt->execute([$target_user]);
        $account = $stmt->fetch();
        $account_number = $account['account_number'] ?? null;
    }
    
    // Parse wallets
    $wallets = [];
    if (!empty($account['wallets'])) {
        $wallets = json_decode($account['wallets'], true);
        if (!is_array($wallets)) $wallets = [];
    }
    
    // Parse dont_display_balance_buckets
    $dont_display_buckets = [];
    if (!empty($account['dont_display_balance_buckets'])) {
        $dont_display_buckets = json_decode($account['dont_display_balance_buckets'], true);
        if (!is_array($dont_display_buckets)) $dont_display_buckets = [];
    }
    
    $astmt = $pdo->prepare("SELECT * FROM portfolio_assets WHERE user_id = ? ORDER BY id DESC");
    $astmt->execute([$target_user]); 
    $assets = $astmt->fetchAll();
    
    $hstmt = $pdo->prepare("SELECT * FROM transaction_history WHERE user_id = ? ORDER BY id DESC");
    $hstmt->execute([$target_user]); 
    $history = $hstmt->fetchAll();
    
    $rstmt = $pdo->prepare("SELECT * FROM payments_receipt WHERE user_id = ? ORDER BY id DESC");
    $rstmt->execute([$target_user]); 
    $receipts = $rstmt->fetchAll();
    
    $dstmt = $pdo->prepare("SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC");
    $dstmt->execute([$target_user]); 
    $deposits = $dstmt->fetchAll();
    
    $ustmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $ustmt->execute([$target_user]); 
    $selected_user = $ustmt->fetch();
    
    // Parse security questions from brief_interview
    if ($selected_user && !empty($selected_user['brief_interview'])) {
        $security_questions = json_decode($selected_user['brief_interview'], true);
        if (!is_array($security_questions)) $security_questions = [];
    }
    
    // Fetch all users with account numbers for the search dropdown
    $all_users_stmt = $pdo->query("SELECT u.id, u.full_name, u.username, ia.account_number, ia.wallets FROM users u JOIN inheritance_accounts ia ON u.id = ia.user_id WHERE ia.account_number IS NOT NULL AND u.id != " . intval($target_user));
    $all_users_with_accounts = $all_users_stmt->fetchAll();
}

$edit_deposit = null;
if (isset($_GET['edit_deposit']) && $target_user) {
    $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['edit_deposit'], $target_user]);
    $edit_deposit = $stmt->fetch();
}

$edit_receipt = null;
if (isset($_GET['edit_receipt']) && $target_user) {
    $stmt = $pdo->prepare("SELECT * FROM payments_receipt WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['edit_receipt'], $target_user]);
    $edit_receipt = $stmt->fetch();
}

// Define card sections
$card_sections = [
    'profile' => ['icon' => '👤', 'title' => 'Edit Profile', 'desc' => 'Manage user details'],
    'security' => ['icon' => '🔐', 'title' => 'Security Questions', 'desc' => 'Manage security Q&A'],
    'passkey' => ['icon' => '🔑', 'title' => 'Passkey Management', 'desc' => 'Set user passkey'],
    'portfolio' => ['icon' => '💼', 'title' => 'Portfolio & Fund Relocation', 'desc' => 'View account balances, wallets & move funds'],
    'transfer' => ['icon' => '💸', 'title' => 'Withdraw Funds', 'desc' => 'Send funds externally or to users'],
    'deposits' => ['icon' => '🏦', 'title' => 'Payment and Deposit method', 'desc' => 'Manage deposit and payment methods'],
    'assets' => ['icon' => '📦', 'title' => 'Portfolio Assets', 'desc' => 'Manage assets & images'],
    'letters' => ['icon' => '📜', 'title' => 'Court Letters', 'desc' => 'Manage legal letters'],
    'receipts' => ['icon' => '🧾', 'title' => 'Payment or Deposit made records', 'desc' => 'Manage receipts'],
    'history' => ['icon' => '📋', 'title' => 'Transaction History', 'desc' => 'View all transactions']
];

// Helper function to check if a bucket should be displayed
function shouldDisplayBucket($bucket_name, $dont_display_buckets) {
    return !in_array($bucket_name, $dont_display_buckets);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Control Panel | Administration</title>
<style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            height: 100%;
            overflow: hidden;
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f4f8;
            color: #1a202c;
        }
        
        .custom-body {
            position: relative;
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
        }
        
        .custom-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .custom-body::-webkit-scrollbar-track {
            background: #f0f4f8;
        }
        
        .custom-body::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 3px;
        }
        
        .custom-body::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        
        /* Header */
        .header {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 100;
            background: #ffffff;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header h1 span {
            color: #4a5568;
            font-weight: 400;
            font-size: 16px;
        }
        
        .header-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .header-controls select {
            padding: 10px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            min-width: 200px;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        
        .header-controls select:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
        }
        
        .header-controls .user-badge {
            background: #ebf8ff;
            color: #2b6cb0;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 40px 100px 40px;
        }
        
        /* Notification */
        #notify {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: #48bb78;
            color: white;
            padding: 12px 30px;
            border-radius: 12px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: 0.4s;
            max-width: 90%;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            font-weight: 500;
        }
        #notify.show { opacity: 1; visibility: visible; }
        #notify.error { background: #fc8181; }
        
        /* Grid Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
            margin-top: 10px;
        }
        
        .grid-card {
            background: white;
            border-radius: 16px;
            padding: 28px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            min-height: 160px;
            justify-content: center;
        }
        
        .grid-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            border-color: #bee3f8;
        }
        
        .grid-card .card-icon {
            font-size: 42px;
            margin-bottom: 12px;
        }
        
        .grid-card .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .grid-card .card-desc {
            font-size: 13px;
            color: #718096;
            margin-top: 4px;
        }
        
        /* Card Views */
        .card-view-container {
            display: none;
        }
        .card-view-container.active {
            display: block;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            background: #edf2f7;
            color: #2d3748;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
            transition: background 0.2s;
        }
        .back-button:hover {
            background: #e2e8f0;
        }
        
        /* Detail Cards */
        .detail-card {
            background: white;
            border-radius: 16px;
            padding: 28px 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            margin-bottom: 28px;
        }
        
        .detail-card:last-child {
            margin-bottom: 0;
        }
        
        .detail-card .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 2px solid #edf2f7;
        }
        
        .detail-card .card-title .badge {
            background: #ebf8ff;
            color: #2b6cb0;
            padding: 2px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Form Elements */
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 6px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: white;
            color: #2d3748;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66,153,225,0.12);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 18px;
        }
        
        .form-row-4 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 16px;
        }
        
        .form-row-5 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
            gap: 14px;
        }
        
        @media (max-width: 1024px) {
            .form-row-4 { grid-template-columns: 1fr 1fr; }
            .form-row-5 { grid-template-columns: 1fr 1fr 1fr; }
        }
        
        @media (max-width: 768px) {
            .form-row, .form-row-3, .form-row-4, .form-row-5 {
                grid-template-columns: 1fr;
            }
            .container { 
                padding: 20px 20px 100px 20px; 
            }
            .header { 
                padding: 15px 20px; 
            }
            .dashboard-grid { 
                grid-template-columns: 1fr; 
            }
            .detail-card { 
                padding: 16px; 
            }
            .grid-card {
                min-height: 120px;
                padding: 20px;
            }
            .grid-card .card-icon {
                font-size: 32px;
            }
        }
        
        @media (max-width: 480px) {
            .header {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 16px;
            }
            .header-controls {
                flex-direction: column;
                align-items: stretch;
            }
            .header-controls select {
                min-width: auto;
                width: 100%;
            }
            .container {
                padding: 16px 16px 100px 16px;
            }
            .detail-card {
                padding: 16px;
            }
            .modal-box {
                padding: 20px;
                margin: 10px;
            }
            .form-row-4, .form-row-5 {
                grid-template-columns: 1fr;
            }
            .keypad-grid {
                max-width: 200px;
            }
            .keypad-btn {
                padding: 10px;
                font-size: 16px;
            }
        }
        
        .btn {
            display: inline-block;
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            text-align: center;
        }
        
        .btn-primary { background: #4299e1; color: white; }
        .btn-primary:hover { background: #3182ce; }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-danger { background: #fc8181; color: white; }
        .btn-danger:hover { background: #f56565; }
        .btn-warning { background: #ecc94b; color: #2d3748; }
        .btn-warning:hover { background: #d69e2e; }
        .btn-purple { background: #9f7aea; color: white; }
        .btn-purple:hover { background: #805ad5; }
        .btn-indigo { background: #667eea; color: white; }
        .btn-indigo:hover { background: #5a67d8; }
        .btn-teal { background: #4fd1c5; color: white; }
        .btn-teal:hover { background: #38b2ac; }
        .btn-orange { background: #ed8936; color: white; }
        .btn-orange:hover { background: #dd6b20; }
        .btn-dark { background: #2d3748; color: white; }
        .btn-dark:hover { background: #1a202c; }
        .btn-sm { padding: 6px 14px; font-size: 12px; }
        .btn-xs { padding: 4px 10px; font-size: 11px; }
        .btn-block { width: 100%; }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        
        /* Toggle Switch */
        .toggle-container {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #f7fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .toggle-container .toggle-label {
            font-size: 13px;
            font-weight: 500;
            color: #4a5568;
            flex: 1;
        }
        
        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
            cursor: pointer;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e0;
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .toggle-switch input:checked + .toggle-slider {
            background-color: #48bb78;
        }
        
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
        
        .toggle-switch input:disabled + .toggle-slider {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Tables */
        .table-wrap {
            overflow-x: auto;
            margin-top: 6px;
            -webkit-overflow-scrolling: touch;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 600px;
        }
        
        table thead th {
            background: #f7fafc;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        
        table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #edf2f7;
            color: #2d3748;
            vertical-align: middle;
        }
        
        table tbody tr:hover {
            background: #f7fafc;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        .status-completed { background: #c6f6d5; color: #276749; }
        .status-pending { background: #fefcbf; color: #975a16; }
        .status-failed { background: #fed7d7; color: #9b2c2c; }
        .status-refunded { background: #e9d8fd; color: #553c9a; }
        .status-received { background: #bee3f8; color: #2a69ac; }
        .status-active { background: #bee3f8; color: #2a69ac; }
        .status-archived { background: #e2e8f0; color: #4a5568; }
        
        .amount-highlight {
            font-weight: 700;
            color: #2b6cb0;
        }
        
        .address-cell {
            font-family: 'Courier New', monospace;
            background: #f7fafc;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            word-break: break-all;
            display: inline-block;
            max-width: 180px;
        }
        
        .action-cell {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .action-cell .btn {
            font-size: 12px;
            padding: 4px 12px;
        }
        
        /* Amount display with currency inline */
        .amount-display {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .amount-display .currency-symbol {
            font-weight: 700;
            font-size: 16px;
            color: #4a5568;
        }
        .amount-display .amount-value {
            font-weight: 700;
            font-size: 16px;
            color: #2d3748;
        }
        
        /* Asset Feed */
        .asset-container {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }
        
        .asset-feed {
            width: 240px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 8px;
            background: #f7fafc;
            flex-shrink: 0;
        }
        
        .asset-feed::-webkit-scrollbar {
            width: 4px;
        }
        .asset-feed::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .feed-item {
            position: relative;
            margin-bottom: 12px;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            border: 1px solid #e2e8f0;
        }
        
        .feed-item .feed-title {
            padding: 6px 12px;
            font-weight: 600;
            font-size: 13px;
            color: #2d3748;
            background: white;
            border-bottom: 1px solid #edf2f7;
        }
        
        .feed-item .feed-img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            display: block;
            background: #edf2f7;
            cursor: pointer;
        }
        
        .feed-item .feed-img-placeholder {
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #edf2f7;
            color: #a0aec0;
            font-size: 14px;
            flex-direction: column;
            gap: 4px;
        }
        
        .feed-item .feed-desc {
            padding: 8px 12px;
            font-size: 13px;
            color: #4a5568;
            border-top: 1px solid #edf2f7;
        }
        
        .feed-item .asset-del-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #fc8181;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            z-index: 10;
        }
        .feed-item .asset-del-btn:hover { background: #f56565; }
        
        .asset-upload-area {
            flex: 1;
            min-width: 280px;
        }
        
        /* Security Questions */
        .security-question-item {
            background: #f7fafc;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .security-question-item .q-text {
            font-weight: 600;
            color: #2d3748;
            font-size: 15px;
        }
        
        .security-question-item .a-text {
            color: #4a5568;
            margin-top: 4px;
            font-size: 14px;
        }
        
        .security-question-item .actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
        }
        
        /* Passkey Keypad */
        .keypad-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            max-width: 280px;
            margin: 12px auto;
        }
        
        .keypad-btn {
            padding: 14px;
            font-size: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: white;
            color: #2d3748;
            cursor: pointer;
            transition: background 0.15s;
            font-weight: 600;
            user-select: none;
            -webkit-user-select: none;
            touch-action: manipulation;
        }
        .keypad-btn:hover { background: #f7fafc; }
        .keypad-btn:active { transform: scale(0.95); }
        .keypad-btn.clear { background: #fc8181; color: white; border-color: #fc8181; }
        .keypad-btn.clear:hover { background: #f56565; }
        .keypad-btn.enter { background: #48bb78; color: white; border-color: #48bb78; }
        .keypad-btn.enter:hover { background: #38a169; }
        .keypad-btn.back { background: #ed8936; color: white; border-color: #ed8936; }
        .keypad-btn.back:hover { background: #dd6b20; }
        
        .passkey-display {
            font-size: 30px;
            letter-spacing: 12px;
            padding: 14px;
            background: #f7fafc;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            margin: 12px 0;
            font-family: 'Courier New', monospace;
            color: #2d3748;
            text-align: center;
            min-height: 56px;
        }
        
        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }
        
        .modal-box {
            background: white;
            border-radius: 16px;
            padding: 32px;
            max-width: 520px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: modalIn 0.25s ease;
        }
        
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .modal-box h3 {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #edf2f7;
        }
        
        .modal-box .modal-msg {
            color: #4a5568;
            margin-bottom: 20px;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .modal-box .modal-btns {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .modal-box .modal-btns .btn {
            flex: 1;
            min-width: 100px;
        }
        
        /* Image Modal */
        .image-modal {
            background: rgba(0,0,0,0.85);
        }
        
        .image-modal .modal-box {
            background: transparent;
            padding: 20px;
            max-width: 90vw;
            text-align: center;
            box-shadow: none;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .image-modal .modal-box img {
            max-width: 100%;
            max-height: 85vh;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            object-fit: contain;
        }
        
        .image-modal .modal-box .image-title {
            color: white;
            margin-top: 12px;
            font-size: 16px;
        }
        
        .image-modal .modal-box .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.9);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            z-index: 20;
        }
        .image-modal .modal-box .close-btn:hover { background: white; }
        
        /* Passkey Modal */
        .passkey-modal .modal-box {
            max-width: 400px;
            text-align: center;
        }
        
        .passkey-modal .modal-box p {
            color: #718096;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        /* Utility */
        .mt-10 { margin-top: 10px; }
        .mt-16 { margin-top: 16px; }
        .mt-20 { margin-top: 20px; }
        .mb-10 { margin-bottom: 10px; }
        .mb-16 { margin-bottom: 16px; }
        .mb-20 { margin-bottom: 20px; }
        .gap-8 { gap: 8px; }
        .gap-12 { gap: 12px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .text-center { text-align: center; }
        .text-muted { color: #718096; }
        .text-sm { font-size: 13px; }
        
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #a0aec0;
        }
        .empty-state .icon { font-size: 40px; margin-bottom: 8px; }
        .empty-state p { font-size: 14px; }
        
        /* Responsive fixes for tables on small screens */
        @media (max-width: 768px) {
            .table-wrap {
                margin: 0 -8px;
                padding: 0 8px;
            }
            table {
                font-size: 13px;
                min-width: 500px;
            }
            table thead th,
            table tbody td {
                padding: 8px 10px;
            }
            .action-cell {
                flex-direction: column;
                gap: 4px;
            }
            .action-cell .btn {
                font-size: 11px;
                padding: 3px 8px;
                width: 100%;
                text-align: center;
            }
        }
        
        @media (max-width: 480px) {
            .asset-feed {
                width: 100%;
                max-height: 300px;
            }
            .asset-upload-area {
                min-width: auto;
                width: 100%;
            }
            .keypad-grid {
                max-width: 200px;
            }
            .keypad-btn {
                padding: 10px;
                font-size: 16px;
            }
            .passkey-display {
                font-size: 24px;
                letter-spacing: 8px;
                padding: 10px;
                min-height: 44px;
            }
            .modal-box {
                padding: 20px 16px;
                max-height: 95vh;
            }
            .modal-box .modal-btns .btn {
                min-width: 80px;
                font-size: 13px;
                padding: 8px 16px;
            }
        }
        
        /* Wallet Styles */
        .wallet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin: 16px 0;
        }
        
        .wallet-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 20px;
            color: white;
            position: relative;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .wallet-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .wallet-card .wallet-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            opacity: 0.9;
        }
        
        .wallet-card .wallet-balance {
            font-size: 28px;
            font-weight: 700;
            margin: 8px 0;
        }
        
        .wallet-card .wallet-balance .currency-symbol {
            font-size: 20px;
            opacity: 0.8;
            margin-right: 2px;
        }
        
        .wallet-card .wallet-delete {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        
        .wallet-card .wallet-delete:hover {
            background: rgba(255,255,255,0.35);
        }
        
        .wallet-card.wallet-empty {
            background: #edf2f7;
            color: #a0aec0;
            box-shadow: none;
            border: 2px dashed #cbd5e0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 120px;
        }
        
        .wallet-card.wallet-empty:hover {
            transform: none;
            box-shadow: none;
        }
        
        /* Large Amount Display */
        .amount-large {
            font-size: 36px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .amount-large .currency-symbol {
            font-size: 28px;
            color: #4a5568;
        }
        
        .account-stat {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        
        .account-stat:hover {
            border-color: #bee3f8;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .account-stat .stat-label {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #718096;
            margin-bottom: 4px;
        }
        
        .account-stat .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .account-stat .stat-value .currency-symbol {
            font-size: 24px;
            color: #4a5568;
            margin-right: 2px;
        }
        
        .stat-icon {
            font-size: 24px;
            margin-right: 12px;
            opacity: 0.7;
        }
        
        .fund-relocation-section {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .fund-relocation-section h4 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 12px;
        }
        
        /* Display Toggle Section */
        .display-toggle-section {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .display-toggle-section h4 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 12px;
        }
        
        .toggle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
        }
        
        /* User Search Styles */
        .user-search-result {
            background: #f7fafc;
            border-radius: 8px;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .user-search-result .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-search-result .user-info .name {
            font-weight: 600;
            color: #2d3748;
        }
        
        .user-search-result .user-info .account {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #4a5568;
        }
        
        .user-search-result .user-info .email {
            font-size: 13px;
            color: #718096;
        }
        
        .user-found {
            border-color: #48bb78;
            background: #f0fff4;
        }
        
        .user-not-found {
            border-color: #fc8181;
            background: #fff5f5;
        }
        
        .user-selected {
            border-color: #4299e1;
            background: #ebf8ff;
        }
        
        /* Tab styles */
        .transfer-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
            flex-wrap: wrap;
        }
        
        .transfer-tab {
            padding: 8px 20px;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #4a5568;
            border: none;
            background: transparent;
            transition: all 0.2s;
            border-bottom: 3px solid transparent;
        }
        
        .transfer-tab.active {
            color: #2b6cb0;
            border-bottom-color: #4299e1;
            background: #ebf8ff;
        }
        
        .transfer-tab:hover:not(.active) {
            background: #f7fafc;
        }
        
        .transfer-panel {
            display: none;
        }
        
        .transfer-panel.active {
            display: block;
        }
        
        /* Wallet dropdown styling */
        .wallet-option {
            padding-left: 20px;
            font-style: italic;
            color: #4a5568;
        }
        
        .bucket-option {
            font-weight: 500;
        }
        
        .wallet-badge {
            display: inline-block;
            background: #667eea;
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }
        /* Theme preview indicator */
        .theme-preview {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .theme-preview.light {
            background: #f7fafc;
            color: #2d3748;
            border: 1px solid #e2e8f0;
        }
        .theme-preview.dark {
            background: #2d3748;
            color: #f7fafc;
            border: 1px solid #4a5568;
        }
</style>
</head>
<body>
<div class="custom-body">

<div id="notify" class="<?php echo $message ? 'show' : ''; ?>"><?php echo htmlspecialchars($message); ?></div>

<!-- Header -->
<header class="header">
    <h1>🖥️ Control Panel <span>Administration</span></h1>
    <div class="header-controls">
        <select id="userSelect" onchange="window.location.href='?uid='+this.value">
            <option value="">— Select User —</option>
            <?php foreach($users as $u): ?>
                <option value="<?php echo $u['id']; ?>" <?php echo ($target_user == $u['id']) ? 'selected' : ''; ?>>
                    #<?php echo $u['id']; ?> — <?php echo htmlspecialchars($u['full_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if($target_user && $selected_user): ?>
            <span class="user-badge">👤 <?php echo htmlspecialchars($selected_user['full_name']); ?></span>
        <?php endif; ?>
    </div>
</header>

<!-- Main Container -->
<div class="container">

    <?php if(!$target_user): ?>
        <!-- User Directory -->
        <div class="detail-card">
            <div class="card-title">👥 Beneficiary Directory</div>
            <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:16px;">
                <?php foreach($users as $u): ?>
                    <div style="background:#f7fafc; border-radius:12px; padding:16px; text-align:center; border:1px solid #e2e8f0;">
                        <div style="width:50px;height:50px;border-radius:50%;background:#ebf8ff;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-weight:700;color:#2b6cb0;font-size:18px;">#<?php echo $u['id']; ?></div>
                        <div style="font-weight:600;font-size:15px;"><?php echo htmlspecialchars($u['full_name']); ?></div>
                        <div style="font-size:12px;color:#718096;"><?php echo htmlspecialchars($u['username']); ?></div>
                        <div style="display:flex;gap:8px;margin-top:10px;justify-content:center;">
                            <a href="?uid=<?php echo $u['id']; ?>" class="btn btn-primary btn-sm">Manage</a>
                            <button onclick="confirmDeleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')" class="btn btn-danger btn-sm">Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Create User -->
        <div class="detail-card">
            <div class="card-title">➕ Create New Beneficiary</div>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="new_fullname" placeholder="John Doe" required>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="new_username" placeholder="johndoe" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="new_email" placeholder="john@example.com" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="new_password" placeholder="••••••••" required>
                    </div>
                </div>
                <button type="submit" name="create_user" class="btn btn-success">Initialize Account</button>
            </form>
        </div>

    <?php else: ?>
        
        <!-- Card Grid View -->
        <div id="cardGridView">
            <div class="dashboard-grid">
                <?php foreach($card_sections as $key => $section): ?>
                    <div class="grid-card" onclick="showCardView('<?php echo $key; ?>')">
                        <div class="card-icon"><?php echo $section['icon']; ?></div>
                        <div class="card-title"><?php echo $section['title']; ?></div>
                        <div class="card-desc"><?php echo $section['desc']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Card View Container -->
        <div id="cardViewContainer" class="card-view-container">
            <button class="back-button" onclick="showGrid()">← Back to Dashboard</button>
            
            <!-- ===== PORTFOLIO CARD (Combined with Fund Relocation) ===== -->
            <div id="view-portfolio" class="card-view">
                <div class="detail-card">
                    <div class="card-title">💼 Portfolio Overview & Fund Relocation <span class="badge">UID #<?php echo $target_user; ?></span></div>
                    
                    <!-- Account Number Display -->
                    <div style="background:#ebf8ff; border-radius:12px; padding:12px 16px; margin-bottom:16px; border-left:4px solid #4299e1;">
                        <span style="font-weight:600; color:#2b6cb0;">🏷️ Account Number:</span>
                        <span style="font-family:'Courier New',monospace; font-size:18px; font-weight:700; color:#2b6cb0; letter-spacing:2px;">
                            <?php echo htmlspecialchars($account_number ?? 'Not assigned'); ?>
                        </span>
                        <span class="text-muted text-sm" style="margin-left:12px;">
                            (Auto-generated based on user ID & currency)
                        </span>
                    </div>
                    
                    <?php if($account): ?>
                    <!-- Display Toggle Section -->
                    <div class="display-toggle-section">
                        <h4>🎯 Filter Balance Buckets</h4>
                        <p class="text-muted text-sm mb-16">Toggle off to hide specific balances from the portfolio view.</p>
                        <div class="toggle-grid">
                            <!-- Total Portfolio -->
                            <div class="toggle-container">
                                <span class="toggle-label">Total Portfolio</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" <?php echo shouldDisplayBucket('total_amount', $dont_display_buckets) ? 'checked' : ''; ?> onchange="toggleDisplayBucket('total_amount', this.checked)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            
                            <!-- Available Balance -->
                            <div class="toggle-container">
                                <span class="toggle-label">Available Balance</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" <?php echo shouldDisplayBucket('available_balance', $dont_display_buckets) ? 'checked' : ''; ?> onchange="toggleDisplayBucket('available_balance', this.checked)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            
                            <!-- Wallets -->
                            <?php foreach($wallets as $wallet): ?>
                                <div class="toggle-container">
                                    <span class="toggle-label"><?php echo htmlspecialchars($wallet['wallet_name']); ?></span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" <?php echo shouldDisplayBucket($wallet['wallet_name'], $dont_display_buckets) ? 'checked' : ''; ?> onchange="toggleDisplayBucket('<?php echo htmlspecialchars($wallet['wallet_name']); ?>', this.checked)">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Large Account Stats -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:30px; margin-top:16px;">
                        <?php if(shouldDisplayBucket('total_amount', $dont_display_buckets)): ?>
                        <div class="account-stat" style="border-left:4px solid #4299e1;">
                            <div class="stat-label"><span class="stat-icon">📊</span>Total Portfolio Value</div>
                            <div class="stat-value">
                                <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                                <?php echo number_format($account['total_amount'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if(shouldDisplayBucket('available_balance', $dont_display_buckets)): ?>
                        <div class="account-stat" style="border-left:4px solid #48bb78;">
                            <div class="stat-label"><span class="stat-icon">💳</span>Available Balance</div>
                            <div class="stat-value">
                                <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                                <?php echo number_format($account['available_balance'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if(shouldDisplayBucket('processed_amount', $dont_display_buckets)): ?>
                        <div class="account-stat" style="border-left:4px solid #9f7aea;">
                            <div class="stat-label"><span class="stat-icon">✅</span>Processed Amount</div>
                            <div class="stat-value">
                                <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                                <?php echo number_format($account['processed_amount'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Wallets Section -->
                    <div style="margin-bottom:24px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                            <h3 style="font-size:18px; font-weight:700; color:#2d3748;">💰 Wallets</h3>
                            <button onclick="openCreateWalletModal()" class="btn btn-indigo btn-sm">➕ Create Wallet</button>
                        </div>
                        
                        <div class="wallet-grid">
                            <?php 
                            $displayed_wallets = array_filter($wallets, function($wallet) use ($dont_display_buckets) {
                                return shouldDisplayBucket($wallet['wallet_name'], $dont_display_buckets);
                            });
                            ?>
                            <?php if(empty($displayed_wallets)): ?>
                                <div class="wallet-card wallet-empty">
                                    <span style="font-size:32px;"></span>
                                    <p>No wallets displayed</p>
                                    <p style="font-size:12px; opacity:0.7;">Toggle wallets on in the filter section above</p>
                                </div>
                            <?php else: ?>
                                <?php foreach($displayed_wallets as $index => $wallet): ?>
                                    <div class="wallet-card">
                                        <button class="wallet-delete" onclick="confirmDeleteWallet(<?php echo $index; ?>)" title="Delete wallet">×</button>
                                        <div class="wallet-name"> <?php echo htmlspecialchars($wallet['wallet_name']); ?></div>
                                        <div class="wallet-balance">
                                            <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                                            <?php echo number_format($wallet['wallet_balance'] ?? 0, 2); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Fund Relocation Section -->
                    <div class="fund-relocation-section">
                        <h4>💰 Fund Relocation</h4>
                        <p class="text-muted text-sm mb-16">Move funds between account buckets or wallets. Select source and destination.</p>
                        <form method="POST">
                            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                            <div class="form-group">
                                <label>Amount</label>
                                <div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;">
                                    <span class="currency-symbol" style="font-size:14px; color:#4a5568;"><?php echo $currency_symbol; ?></span>
                                    <input type="number" name="amount" step="0.01" placeholder="0.00" required style="border:none; padding:10px 0; flex:1; min-width:50px;">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>From</label>
                                    <select name="from_bucket" id="fromBucket">
                                        <?php if(shouldDisplayBucket('total_amount', $dont_display_buckets)): ?>
                                        <option value="total_amount">Total Portfolio <?php echo $currency_symbol; ?><?php echo number_format($account['total_amount'] ?? 0, 2); ?></option>
                                        <?php endif; ?>
                                        <?php if(shouldDisplayBucket('available_balance', $dont_display_buckets)): ?>
                                        <option value="available_balance">Available Balance <?php echo $currency_symbol; ?><?php echo number_format($account['available_balance'] ?? 0, 2); ?></option>
                                        <?php endif; ?>
                                        <?php foreach($wallets as $idx => $wallet): ?>
                                            <?php if(shouldDisplayBucket($wallet['wallet_name'], $dont_display_buckets)): ?>
                                            <option value="wallet_<?php echo $idx; ?>"> <?php echo htmlspecialchars($wallet['wallet_name']); ?> <?php echo $currency_symbol; ?><?php echo number_format($wallet['wallet_balance'] ?? 0, 2); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>To</label>
                                    <select name="to_bucket" id="toBucket">
                                        <?php if(shouldDisplayBucket('available_balance', $dont_display_buckets)): ?>
                                        <option value="available_balance">Available Balance <?php echo $currency_symbol; ?><?php echo number_format($account['available_balance'] ?? 0, 2); ?></option>
                                        <?php endif; ?>
                                        <?php if(shouldDisplayBucket('total_amount', $dont_display_buckets)): ?>
                                        <option value="total_amount">Total Portfolio <?php echo $currency_symbol; ?><?php echo number_format($account['total_amount'] ?? 0, 2); ?></option>
                                        <?php endif; ?>
                                        <?php foreach($wallets as $idx => $wallet): ?>
                                            <?php if(shouldDisplayBucket($wallet['wallet_name'], $dont_display_buckets)): ?>
                                            <option value="wallet_<?php echo $idx; ?>"> <?php echo htmlspecialchars($wallet['wallet_name']); ?> <?php echo $currency_symbol; ?><?php echo number_format($wallet['wallet_balance'] ?? 0, 2); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" name="move_funds" class="btn btn-dark">🔄 Relocate Funds</button>
                        </form>
                    </div>
                    
                    <!-- Account Details -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; background:#f7fafc; border-radius:12px; padding:18px; margin-top:16px;">
                        <div><span style="font-weight:600; color:#4a5568;">Currency:</span> <?php echo htmlspecialchars($account['currency'] ?? 'USD'); ?> (<?php echo $currency_symbol; ?>)</div>
                        <div><span style="font-weight:600; color:#4a5568;">Legal Rep:</span> <?php echo htmlspecialchars($account['legal_representative'] ?? 'Not assigned'); ?></div>
                        <div><span style="font-weight:600; color:#4a5568;">Testator:</span> <?php echo htmlspecialchars($account['testator'] ?? 'Estate of Deceased'); ?></div>
                        <div><span style="font-weight:600; color:#4a5568;">Max Withdrawal:</span> <span class="amount-display" style="display:inline-flex;"><span class="currency-symbol"><?php echo $currency_symbol; ?></span><span><?php echo number_format($account['maximum_withdrawal_amount'] ?? 0, 2); ?></span></span></div>
                        <div><span style="font-weight:600; color:#4a5568;">Next Withdrawal:</span> <?php echo !empty($account['next_withdrawal_date']) ? date('M d, Y', strtotime($account['next_withdrawal_date'])) : 'No restriction'; ?></div>
                        <?php if(!empty($account['message'])): ?>
                            <div><span style="font-weight:600; color:#4a5568;">Message:</span> <?php echo htmlspecialchars($account['message']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Account Settings -->
                <div class="detail-card">
                    <div class="card-title">⚙️ Set & Update Account Settings</div>
                    <form method="POST">
                        <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                        <div class="form-row-4">
                            <div class="form-group">
                                <label>💰 Set Currency</label>
                                <select name="currency">
                                    <option value="USD" <?php echo ($account['currency'] ?? 'USD') == 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                    <option value="EUR" <?php echo ($account['currency'] ?? 'USD') == 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                    <option value="GBP" <?php echo ($account['currency'] ?? 'USD') == 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                    <option value="JPY" <?php echo ($account['currency'] ?? 'USD') == 'JPY' ? 'selected' : ''; ?>>JPY (¥)</option>
                                    <option value="BTC" <?php echo ($account['currency'] ?? 'USD') == 'BTC' ? 'selected' : ''; ?>>BTC (₿)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>👤 Set Legal Representative</label>
                                <input type="text" name="legal_representative" value="<?php echo htmlspecialchars($account['legal_representative'] ?? ''); ?>" placeholder="Attorney name">
                            </div>
                            <div class="form-group">
                                <label>📜 Set Testator</label>
                                <input type="text" name="testator" value="<?php echo htmlspecialchars($account['testator'] ?? 'Estate of Deceased'); ?>" placeholder="Estate of...">
                            </div>
                            <div class="form-group">
                                <label>💵 Set Total Portfolio</label>
                                <div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;">
                                    <span class="currency-symbol" style="font-size:14px; color:#4a5568;"><?php echo $currency_symbol; ?></span>
                                    <input type="number" step="0.01" name="total_amount" value="<?php echo $account['total_amount'] ?? '0'; ?>" placeholder="0.00" style="border:none; padding:10px 0; flex:1; min-width:50px;">
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>💳 Set Maximum Withdrawal Amount</label>
                                <div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;">
                                    <span class="currency-symbol" style="font-size:14px; color:#4a5568;"><?php echo $currency_symbol; ?></span>
                                    <input type="number" step="0.01" name="max_withdrawal_amount" value="<?php echo $account['maximum_withdrawal_amount'] ?? '0'; ?>" placeholder="0.00" style="border:none; padding:10px 0; flex:1; min-width:50px;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>📅 Set Next Withdrawal Date</label>
                                <input type="date" name="next_withdrawal_date" value="<?php echo $account['next_withdrawal_date'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>💬 Set Display Message</label>
                            <input type="text" name="message_text" value="<?php echo htmlspecialchars($account['message'] ?? ''); ?>" placeholder="Message to display">
                        </div>
                        <button type="submit" name="update_inheritance_settings" class="btn btn-purple">💾 Update Settings</button>
                    </form>
                </div>
            </div>
            
            <!-- ===== PROFILE CARD ===== -->
            <div id="view-profile" class="card-view">
                <div class="detail-card">
                    <div class="card-title">👤 Edit Profile <span class="badge">UID #<?php echo $target_user; ?></span></div>
                    
                    <form method="POST">
                        <input type="hidden" name="user_id" value="<?php echo $selected_user['id']; ?>">
                        
                        <!-- Personal Information -->
                        <h4 style="font-size:15px; font-weight:600; color:#4a5568; margin-bottom:12px;">📋 Personal Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($selected_user['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Username *</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($selected_user['username']); ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($selected_user['email']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Portal Name</label>
                                <input type="text" name="portal_name" value="<?php echo htmlspecialchars($selected_user['portal_name'] ?? ''); ?>" placeholder="Portal Name">
                                <div class="text-muted text-sm">Display name shown in the portal header</div>
                            </div>
                        </div>
                        
                        <!-- Security & Theme Settings -->
                        <h4 style="font-size:15px; font-weight:600; color:#4a5568; margin:20px 0 12px;">🔐 Security & Theme Settings</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="password" placeholder="Enter new password (leave blank to keep current)">
                                <div class="text-muted text-sm">Must be at least 8 characters</div>
                            </div>
                            <div class="form-group">
                                <label>Theme Mode</label>
                                <select name="theme_mode" required style="padding:10px 14px; border:1px solid #e2e8f0; border-radius:8px; width:100%;">
                                    <option value="white" <?php echo ($selected_user['theme_mode'] ?? 'white') == 'white' ? 'selected' : ''; ?>>☀️ Light Mode</option>
                                    <option value="dark" <?php echo ($selected_user['theme_mode'] ?? 'white') == 'dark' ? 'selected' : ''; ?>>🌙 Dark Mode</option>
                                </select>
                                <div class="text-muted text-sm">Select the default theme for this user's dashboard</div>
                            </div>
                        </div>
                        
                        <!-- Passkey -->
                        <div class="form-row" style="margin-top:8px;">
                            <div class="form-group">
                                <label>Passkey (6 digits)</label>
                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <input type="text" id="profilePasskeyDisplay" placeholder="6-digit passkey" maxlength="6" pattern="[0-9]*" readonly style="flex:1; background:#f7fafc; padding:10px 14px; border:1px solid #e2e8f0; border-radius:8px; font-family:'Courier New',monospace; letter-spacing:4px;">
                                    <button type="button" onclick="openPasskeyModal()" class="btn btn-purple" style="white-space:nowrap;">🔑 Set Passkey</button>
                                    <button type="button" onclick="clearProfilePasskey()" class="btn btn-danger" style="white-space:nowrap;">🗑️ Clear</button>
                                </div>
                                <input type="hidden" name="passkey" id="profilePasskeyHidden" value="">
                                <div class="text-muted text-sm mt-10">Enter a 6-digit numeric passkey for user login (e.g., 123456)</div>
                            </div>
                        </div>
                        
                        <!-- Account Info Display -->
                        <div style="background:#f7fafc; border-radius:12px; padding:16px; margin:20px 0 16px; display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:12px;">
                            <div>
                                <span style="font-weight:600; color:#4a5568; font-size:13px;">User ID:</span>
                                <span style="font-weight:700; color:#2d3748;">#<?php echo $selected_user['id']; ?></span>
                            </div>
                            <div>
                                <span style="font-weight:600; color:#4a5568; font-size:13px;">Created:</span>
                                <span style="color:#2d3748;"><?php echo date('M d, Y', strtotime($selected_user['created_at'])); ?></span>
                            </div>
                            <div>
                                <span style="font-weight:600; color:#4a5568; font-size:13px;">Passkey Status:</span>
                                <span class="status-badge <?php echo !empty($selected_user['passkey']) ? 'status-completed' : 'status-pending'; ?>" style="font-size:12px;">
                                    <?php echo !empty($selected_user['passkey']) ? '✅ Set' : '🔓 Not Set'; ?>
                                </span>
                            </div>
                            <div>
                                <span style="font-weight:600; color:#4a5568; font-size:13px;">Current Theme:</span>
                                <span style="font-weight:600; color:#2d3748;"><?php echo ucfirst($selected_user['theme_mode'] ?? 'white'); ?></span>
                            </div>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" name="update_user_direct" class="btn btn-primary" style="padding:12px 40px;">💾 Save All Changes</button>
                            <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?uid=<?php echo $target_user; ?>'" class="btn btn-dark">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- ===== WITHDRAW FUNDS CARD (With Tabs) ===== -->
            <div id="view-transfer" class="card-view">
                <div class="detail-card">
                    <div class="card-title">💸 Withdraw Funds</div>
                    
                    <!-- Tabs -->
                    <div class="transfer-tabs">
                        <button class="transfer-tab active" data-tab="send-to-user" onclick="switchTransferTab('send-to-user')">👤 Send to User</button>
                        <button class="transfer-tab" data-tab="send-external" onclick="switchTransferTab('send-external')">🌐 Send External</button>
                    </div>
                    
                    <!-- Tab: Send to User -->
                    <div id="send-to-user" class="transfer-panel active">
                        <p class="text-muted text-sm mb-16">Send funds to another user by their account number. Search for the user, select source and destination buckets, then submit.</p>
                        
                        <form method="POST" id="sendToUserForm">
                            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>💰 From (Your Account)</label>
                                    <select name="from_bucket" required>
                                        <optgroup label="Main Balances">
                                            <?php if(shouldDisplayBucket('available_balance', $dont_display_buckets)): ?>
                                            <option value="available_balance" class="bucket-option">Available Balance <?php echo $currency_symbol; ?><?php echo number_format($account['available_balance'] ?? 0, 2); ?></option>
                                            <?php endif; ?>
                                            <?php if(shouldDisplayBucket('total_amount', $dont_display_buckets)): ?>
                                            <option value="total_amount" class="bucket-option">Total Portfolio <?php echo $currency_symbol; ?><?php echo number_format($account['total_amount'] ?? 0, 2); ?></option>
                                            <?php endif; ?>
                                        </optgroup>
                                        <?php if(!empty($wallets)): ?>
                                        <optgroup label="Wallets">
                                            <?php foreach($wallets as $idx => $wallet): ?>
                                                <?php if(shouldDisplayBucket($wallet['wallet_name'], $dont_display_buckets)): ?>
                                                <option value="wallet_<?php echo $idx; ?>" class="wallet-option"><?php echo htmlspecialchars($wallet['wallet_name']); ?> <?php echo $currency_symbol; ?><?php echo number_format($wallet['wallet_balance'] ?? 0, 2); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>🔍 Enter Recipient Account Number</label>
                                    <div style="display:flex; gap:10px;">
                                        <input type="text" id="recipientAccountSearch" placeholder="Enter account number" style="flex:1; font-family:'Courier New',monospace; letter-spacing:1px;" 
                                               oninput="searchUserByAccount(this.value)">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="searchUserByAccount(document.getElementById('recipientAccountSearch').value)">🔍 Search</button>
                                    </div>
                                    <div id="userSearchResult"></div>
                                </div>
                            </div>
                            
                            <div id="recipientInfo" style="display:none;">
                                <div class="user-search-result user-selected">
                                    <div class="user-info">
                                        <span class="name" id="recipientName">—</span>
                                        <span class="account" id="recipientAccount">—</span>
                                        <span class="email" id="recipientEmail">—</span>
                                    </div>
                                    <span class="status-badge status-received">✓ Selected</span>
                                </div>
                                <input type="hidden" name="to_user_id" id="recipientUserId" value="">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>💳 Amount to Send</label>
                                    <div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;">
                                        <span class="currency-symbol" style="font-size:14px; color:#4a5568;"><?php echo $currency_symbol; ?></span>
                                        <input type="number" name="send_amount" placeholder="0.00" step="0.01" required style="border:none; padding:10px 0; flex:1; min-width:50px;">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>📥 To (Recipient's Bucket)</label>
                                    <select name="to_bucket" id="recipientBucketSelect" required>
                                        <optgroup label="Main Balances">
                                            <option value="available_balance">Available Balance</option>
                                            <option value="total_amount">Total Portfolio</option>
                                            <option value="processed_amount">Processed Amount</option>
                                            <option value="in_process_balance">In-Process Balance</option>
                                        </optgroup>
                                        <optgroup label="Recipient's Wallets" id="recipientWalletsGroup">
                                            <!-- Will be populated dynamically when a user is selected -->
                                        </optgroup>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Status Label</label>
                                <input type="text" name="b_status" value="Completed" placeholder="e.g. Processing, Completed, etc.">
                            </div>
                            
                            <button type="submit" name="send_to_user" class="btn btn-success" id="sendToUserBtn" disabled>📤 Send to User</button>
                        </form>
                    </div>
                    
                    <!-- Tab: Send External -->
                    <div id="send-external" class="transfer-panel">
                        <p class="text-muted text-sm mb-16">Send funds to external accounts via various payment methods.</p>
                        
                        <div style="background:#c6f6d5; border-radius:8px; padding:12px 16px; margin-bottom:16px; border-left:4px solid #48bb78;">
                            <span style="font-weight:600;">Available Balance:</span>
                            <span class="amount-display" style="display:inline-flex; font-size:18px;">
                                <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                                <span class="amount-value" style="color:#276749;"><?php echo number_format($account['available_balance'] ?? 0, 2); ?></span>
                            </span>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>💰 From</label>
                                    <select name="from_bucket" required>
                                        <optgroup label="Main Balances">
                                            <?php if(shouldDisplayBucket('available_balance', $dont_display_buckets)): ?>
                                            <option value="available_balance">Available Balance <?php echo $currency_symbol; ?><?php echo number_format($account['available_balance'] ?? 0, 2); ?></option>
                                            <?php endif; ?>
                                            <?php if(shouldDisplayBucket('total_amount', $dont_display_buckets)): ?>
                                            <option value="total_amount">Total Portfolio <?php echo $currency_symbol; ?><?php echo number_format($account['total_amount'] ?? 0, 2); ?></option>
                                            <?php endif; ?>
                                        </optgroup>
                                        <?php if(!empty($wallets)): ?>
                                        <optgroup label="Wallets">
                                            <?php foreach($wallets as $idx => $wallet): ?>
                                                <?php if(shouldDisplayBucket($wallet['wallet_name'], $dont_display_buckets)): ?>
                                                <option value="wallet_<?php echo $idx; ?>"><?php echo htmlspecialchars($wallet['wallet_name']); ?> <?php echo $currency_symbol; ?><?php echo number_format($wallet['wallet_balance'] ?? 0, 2); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Amount</label>
                                    <div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;">
                                        <span class="currency-symbol" style="font-size:14px; color:#4a5568;"><?php echo $currency_symbol; ?></span>
                                        <input type="number" name="send_amount" placeholder="0.00" step="0.01" required style="border:none; padding:10px 0; flex:1; min-width:50px;">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Payment Method</label>
                                    <select name="transfer_method" id="methodSelect" onchange="toggleTransferFields()">
                                        <option value="bank">Bank Wire</option>
                                        <option value="paypal">PayPal</option>
                                        <option value="cashapp">CashApp</option>
                                        <option value="venmo">Venmo</option>
                                        <option value="crypto">Cryptocurrency</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Status Label</label>
                                    <input type="text" name="b_status" value="Completed" placeholder="e.g. Processing, Completed, etc.">
                                </div>
                            </div>
                            
                            <div id="fields_bank" class="form-row">
                                <div class="form-group"><label>Bank Name</label><input type="text" name="b_name" placeholder="Bank name"></div>
                                <div class="form-group"><label>Account Number</label><input type="text" name="b_acc" placeholder="Account number"></div>
                            </div>
                            <div id="fields_paypal" class="form-row" style="display:none;">
                                <div class="form-group"><label>PayPal Email</label><input type="email" name="pp_email" placeholder="email@example.com"></div>
                            </div>
                            <div id="fields_cashapp" class="form-row" style="display:none;">
                                <div class="form-group"><label>CashTag</label><input type="text" name="ca_tag" placeholder="$cashtag"></div>
                            </div>
                            <div id="fields_venmo" class="form-row" style="display:none;">
                                <div class="form-group"><label>Venmo Username</label><input type="text" name="vn_user" placeholder="@username"></div>
                            </div>
                            <div id="fields_crypto" class="form-row" style="display:none;">
                                <div class="form-group"><label>Wallet Address</label><input type="text" name="wallet_address" placeholder="0x..."></div>
                            </div>
                            
                            <button type="submit" name="send_external" class="btn btn-success">📤 Submit External Transfer</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- ===== DEPOSITS CARD ===== -->
            <div id="view-deposits" class="card-view">
                <div class="detail-card">
                    <div class="card-title">🏦 Payment and Deposit methods <span class="badge"><?php echo count($deposits); ?> Records</span></div>
                    
                    <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
                        <a href="?uid=<?php echo $target_user; ?>&new_deposit=1" class="btn btn-indigo">➕ Add Method</a>
                    </div>
                    
                    <?php if(empty($deposits)): ?>
                        <div class="empty-state"><div class="icon">📭</div><p>Payment or Deposit methods are not set yet</p></div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Value</th>
                                    <th>Mnimum amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($deposits as $dep): 
                                    $type_display = ucfirst($dep['payment_type']);
                                    $status_class = $dep['status'] == 'completed' ? 'status-completed' : ($dep['status'] == 'pending' ? 'status-pending' : ($dep['status'] == 'failed' ? 'status-failed' : 'status-refunded'));
                                ?>
                                <tr>
                                    <td>#<?php echo $dep['id']; ?></td>
                                    <td><?php echo date('m/d/Y', strtotime($dep['created_at'])); ?></td>
                                    <td><?php echo $type_display; ?></td>
                                    <td><span class="address-cell"><?php echo htmlspecialchars($dep['payment_value']); ?></span></td>
                                    <td class="amount-highlight"><?php echo formatCurrency($dep['amount'], $currency_code); ?></td>
                                    <td>
                                        <div class="action-cell">
                                            <a href="?uid=<?php echo $target_user; ?>&edit_deposit=<?php echo $dep['id']; ?>" class="btn btn-warning btn-xs">Edit</a>
                                            <button onclick="confirmDeleteDeposit(<?php echo $dep['id']; ?>)" class="btn btn-danger btn-xs">Del</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ===== ASSETS CARD ===== -->
            <div id="view-assets" class="card-view">
                <div class="detail-card">
                    <div class="card-title">📦 Portfolio Assets</div>
                    <div class="asset-container">
                        <div class="asset-feed">
                            <?php if(empty($assets)): ?>
                                <div class="empty-state"><div class="icon">🖼️</div><p>No assets linked</p></div>
                            <?php else: ?>
                                <?php foreach($assets as $ast): ?>
                                    <div class="feed-item">
                                        <div class="feed-title"><?php echo htmlspecialchars($ast['asset_title']); ?></div>
                                        <button class="asset-del-btn" onclick="confirmDeleteAsset(<?php echo $ast['id']; ?>)">×</button>
                                        <?php 
                                        $image_url = getImageUrl($ast['image_path'] ?? '');
                                        if($image_url): 
                                        ?>
                                            <img src="<?php echo htmlspecialchars($image_url); ?>" class="feed-img" alt="<?php echo htmlspecialchars($ast['asset_title']); ?>" onclick="openImageModal('<?php echo htmlspecialchars($image_url); ?>', '<?php echo htmlspecialchars($ast['asset_title']); ?>')">
                                        <?php else: ?>
                                            <div class="feed-img-placeholder">📷 No Image</div>
                                        <?php endif; ?>
                                        <div class="feed-desc"><?php echo htmlspecialchars($ast['asset_description'] ?: 'No description'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="asset-upload-area">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                                <div class="form-group">
                                    <label>Asset Title</label>
                                    <input type="text" name="asset_title" placeholder="Asset title" required>
                                </div>
                                <div class="form-group">
                                    <label>Image</label>
                                    <input type="file" name="asset_image" accept="image/*" required>
                                </div>
                                <div class="form-group">
                                    <label>Description</label>
                                    <textarea name="asset_desc" rows="3" placeholder="Asset description..."></textarea>
                                </div>
                                <button type="submit" name="upload_asset" class="btn btn-primary">Upload Asset</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===== COURT LETTERS CARD ===== -->
            <div id="view-letters" class="card-view">
                <div class="detail-card">
                    <div class="card-title">📜 Court Letters</div>
                    
                    <?php
                    $letter_stmt = $pdo->prepare("SELECT * FROM court_letters WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                    $letter_stmt->execute([$target_user]);
                    $court_letters = $letter_stmt->fetchAll();
                    ?>
                    
                    <?php if(!empty($court_letters)): ?>
                    <div style="background:#f7fafc; border-radius:12px; padding:16px; margin-bottom:20px;">
                        <div style="font-weight:600; margin-bottom:10px;">📋 Recent Letters</div>
                        <?php foreach($court_letters as $cl): ?>
                            <div style="padding:8px 0; border-bottom:1px solid #e2e8f0; font-size:14px;">
                                <strong><?php echo htmlspecialchars($cl['letter_number']); ?></strong>
                                <span class="text-muted text-sm">(<?php echo date('M d, Y', strtotime($cl['letter_date'])); ?>)</span>
                                <span class="status-badge <?php echo $cl['status'] == 'active' ? 'status-active' : 'status-archived'; ?>" style="font-size:11px;"><?php echo ucfirst($cl['status']); ?></span>
                                <div class="text-muted text-sm"><?php echo htmlspecialchars(substr($cl['description'], 0, 80)) . (strlen($cl['description']) > 80 ? '...' : ''); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Letter Number</label>
                                <input type="text" value="CRT-<?php echo date('Ymd') . '-' . rand(100, 999); ?>" readonly style="background:#f7fafc;">
                            </div>
                            <div class="form-group">
                                <label>Date</label>
                                <input type="date" name="l_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Type</label>
                                <select name="l_type" required>
                                    <option value="notice">Notice</option>
                                    <option value="order">Court Order</option>
                                    <option value="letter">General Letter</option>
                                    <option value="notification">Notification</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Content</label>
                            <textarea name="l_body" rows="4" placeholder="Enter letter content..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="l_status">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                        <button type="submit" name="save_letter" class="btn btn-dark">Save Letter</button>
                    </form>
                </div>
            </div>
            
            <!-- ===== RECEIPTS CARD ===== -->
            <div id="view-receipts" class="card-view">
                <div class="detail-card">
                    <div class="card-title">🧾 Payment or Deposit made records <span class="badge"><?php echo count($receipts); ?> Receipts</span></div>
                    
                    <form method="POST" style="background:#f7fafc; border-radius:12px; padding:20px; margin-bottom:20px;">
                        <h4 style="font-size:15px; margin-bottom:12px;">➕ Add received payment or deposit</h4>
                        <p class="text-muted text-sm mb-16">All fields are optional</p>
                        <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                        
                        <div class="form-row-5">
                            <div class="form-group"><label>Paid Date</label><input type="date" name="paid_date" value="<?php echo date('Y-m-d'); ?>"></div>
                            <div class="form-group"><label>Amount Paid</label><div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;"><span class="currency-symbol" style="font-size:13px; color:#4a5568;"><?php echo $currency_symbol; ?></span><input type="number" step="0.01" name="amount_paid" placeholder="0.00" style="border:none; padding:8px 0; flex:1; min-width:40px;"></div></div>
                            <div class="form-group"><label>Payment Due</label><div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;"><span class="currency-symbol" style="font-size:13px; color:#4a5568;"><?php echo $currency_symbol; ?></span><input type="number" step="0.01" name="payment_due" placeholder="0.00" style="border:none; padding:8px 0; flex:1; min-width:40px;"></div></div>
                            <div class="form-group"><label>Total Payment</label><div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;"><span class="currency-symbol" style="font-size:13px; color:#4a5568;"><?php echo $currency_symbol; ?></span><input type="number" step="0.01" name="total_payment" placeholder="0.00" style="border:none; padding:8px 0; flex:1; min-width:40px;"></div></div>
                            <div class="form-group"><label>Reference #</label><input type="text" name="reference_number" placeholder="INV-001"></div>
                        </div>
                        <div class="form-row-3">
                            <div class="form-group"><label>Payer Name</label><input type="text" name="payer_name" placeholder="Payer name"></div>
                            <div class="form-group"><label>Receiver Name</label><input type="text" name="receiver_name" placeholder="Receiver name"></div>
                            <div class="form-group"><label>Status</label>
                                <select name="receipt_status">
                                    <option value="pending">Pending</option>
                                    <option value="completed" selected>Completed</option>
                                    <option value="failed">Failed</option>
                                    <option value="refunded">Refunded</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group"><label>Payment Subject</label><input type="text" name="payment_subject" placeholder="Payment subject"></div>
                        <div class="form-group"><label>Notes</label><textarea name="receipt_notes" rows="2" placeholder="Additional notes..."></textarea></div>
                        <button type="submit" name="save_receipt" class="btn btn-teal">Save Receipt</button>
                    </form>
                    
                    <?php if(empty($receipts)): ?>
                        <div class="empty-state"><div class="icon">📭</div><p>No receipts recorded</p></div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Payer</th>
                                    <th>Subject</th>
                                    <th>Paid</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($receipts as $rec): 
                                    $status_class = $rec['status'] == 'completed' ? 'status-completed' : ($rec['status'] == 'pending' ? 'status-pending' : ($rec['status'] == 'failed' ? 'status-failed' : 'status-refunded'));
                                ?>
                                <tr>
                                    <td>#<?php echo $rec['id']; ?></td>
                                    <td><?php echo date('m/d/Y', strtotime($rec['paid_date'])); ?></td>
                                    <td><?php echo htmlspecialchars(substr($rec['payer_name'], 0, 20)) . (strlen($rec['payer_name']) > 20 ? '...' : ''); ?></td>
                                    <td><?php echo htmlspecialchars(substr($rec['payment_subject'], 0, 25)) . (strlen($rec['payment_subject']) > 25 ? '...' : ''); ?></td>
                                    <td><?php echo formatCurrency($rec['amount_paid'], $currency_code); ?></td>
                                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($rec['status']); ?></span></td>
                                    <td>
                                        <div class="action-cell">
                                            <a href="?uid=<?php echo $target_user; ?>&edit_receipt=<?php echo $rec['id']; ?>" class="btn btn-warning btn-xs">Edit</a>
                                            <button onclick="confirmDeleteReceipt(<?php echo $rec['id']; ?>)" class="btn btn-danger btn-xs">Del</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ===== TRANSACTION HISTORY CARD ===== -->
            <div id="view-history" class="card-view">
                <div class="detail-card">
                    <div class="card-title">📋 Transaction History</div>
                    
                    <?php if(empty($history)): ?>
                        <div class="empty-state"><div class="icon">📭</div><p>No transactions recorded</p></div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Details</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($history as $tx): 
                                    $date_raw = $tx['transaction_date'] ?? $tx['created_at'];
                                    $display_date = ($date_raw) ? date('M d, Y', strtotime($date_raw)) : "Just Now";
                                    $is_internal = (strpos($tx['description'], 'Internal Relocation') !== false);
                                    $status_class = strtolower($tx['status']) == 'completed' ? 'status-completed' : (strtolower($tx['status']) == 'pending' ? 'status-pending' : (strtolower($tx['status']) == 'failed' ? 'status-failed' : (strtolower($tx['status']) == 'refunded' ? 'status-refunded' : 'status-received')));
                                ?>
                                <tr>
                                    <td><?php echo $display_date; ?></td>
                                    <td style="max-width:300px; word-break:break-word;"><?php echo htmlspecialchars($tx['description']); ?></td>
                                    <td class="amount-highlight"><?php echo formatCurrency($tx['amount'], $currency_code); ?></td>
                                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                                    <td>
                                        <?php if($is_internal): ?>
                                            <span class="text-muted text-sm">Internal</span>
                                        <?php else: ?>
                                            <div class="action-cell" style="flex-direction:column; gap:4px;">
                                                <form method="POST" style="display:flex; gap:4px; flex-wrap:wrap; width:100%;">
                                                    <input type="hidden" name="tx_id" value="<?php echo $tx['id']; ?>">
                                                    <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                                                    <select name="new_tx_status" style="padding:4px 8px; border:1px solid #e2e8f0; border-radius:4px; font-size:12px; flex:1; min-width:90px;">
                                                        <option value="completed" <?php echo strtolower($tx['status']) == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="pending" <?php echo strtolower($tx['status']) == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="failed" <?php echo strtolower($tx['status']) == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                                        <option value="refunded" <?php echo strtolower($tx['status']) == 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                                        <option value="received" <?php echo strtolower($tx['status']) == 'received' ? 'selected' : ''; ?>>Received</option>
                                                    </select>
                                                    <button type="submit" name="update_tx_status" class="btn btn-dark btn-xs">Update</button>
                                                </form>
                                                <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                                    <button onclick="confirmRefund(<?php echo $tx['id']; ?>, '<?php echo number_format($tx['amount'], 2); ?>')" class="btn btn-success btn-xs">Refund</button>
                                                    <button onclick="confirmDeleteTransaction(<?php echo $tx['id']; ?>)" class="btn btn-danger btn-xs">Delete</button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- ===== SECURITY QUESTIONS CARD ===== -->
            <div id="view-security" class="card-view">
                <div class="detail-card">
                    <div class="card-title">🔐 Security Questions <span class="badge"><?php echo count($security_questions); ?> Questions</span></div>
                    
                    <div id="securityQuestionsList">
                        <?php if(empty($security_questions)): ?>
                            <div class="empty-state"><div class="icon">📭</div><p>No security questions set up</p></div>
                        <?php else: ?>
                            <?php foreach($security_questions as $index => $sq): ?>
                                <div class="security-question-item">
                                    <div class="q-text">❓ <?php echo htmlspecialchars($sq['question'] ?? 'Untitled'); ?></div>
                                    <div class="a-text">🔑 <?php echo htmlspecialchars($sq['answer'] ?? 'No answer'); ?></div>
                                    <div class="actions">
                                        <button onclick="editSecurityQuestion(<?php echo $index; ?>)" class="btn btn-warning btn-xs">Edit</button>
                                        <button onclick="deleteSecurityQuestion(<?php echo $index; ?>)" class="btn btn-danger btn-xs">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="background:#f7fafc; border-radius:12px; padding:20px; margin-top:16px;">
                        <h4 style="font-size:15px; margin-bottom:12px;">➕ Add / Edit Question</h4>
                        <form method="POST" id="securityForm">
                            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                            <input type="hidden" name="security_questions_json" id="securityQuestionsJson" value='<?php echo htmlspecialchars(json_encode($security_questions)); ?>'>
                            <input type="hidden" name="edit_index" id="editIndex" value="-1">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Question</label>
                                    <input type="text" id="sqQuestion" placeholder="Enter security question" required>
                                </div>
                                <div class="form-group">
                                    <label>Answer</label>
                                    <input type="text" id="sqAnswer" placeholder="Enter answer" required>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button type="button" id="addSecurityBtn" class="btn btn-success">➕ Add Question</button>
                                <button type="button" id="updateSecurityBtn" class="btn btn-purple" style="display:none;">🔄 Update Question</button>
                                <button type="button" id="cancelSecurityBtn" class="btn btn-dark" style="display:none;">Cancel</button>
                            </div>
                            <button type="submit" name="update_security_questions" class="btn btn-purple mt-16">💾 Save All Questions</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- ===== PASSKEY CARD ===== -->
            <div id="view-passkey" class="card-view">
                <div class="detail-card">
                    <div class="card-title">🔑 Passkey Management</div>
                    
                    <?php 
                    $current_passkey = $selected_user['passkey'] ?? null;
                    $has_passkey = !empty($current_passkey);
                    ?>
                    
                    <div style="background:#f7fafc; border-radius:12px; padding:24px; text-align:center; margin-bottom:20px;">
                        <div style="font-size:56px; margin-bottom:8px;"><?php echo $has_passkey ? '✅' : '🔓'; ?></div>
                        <div style="font-size:20px; font-weight:700; color:#2d3748;">
                            <?php echo $has_passkey ? 'Passkey is set' : 'No passkey set'; ?>
                        </div>
                        <?php if($has_passkey): ?>
                            <div class="text-muted text-sm">Passkey is hashed and stored securely</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="btn-group">
                        <button onclick="openPasskeyModal()" class="btn btn-purple"><?php echo $has_passkey ? '🔄 Update Passkey' : '➕ Set Passkey'; ?></button>
                        <?php if($has_passkey): ?>
                            <button onclick="clearPasskeyDirect()" class="btn btn-danger">🗑️ Clear Passkey</button>
                        <?php endif; ?>
                    </div>
                    
                    <div style="background:#f7fafc; border-radius:12px; padding:16px; margin-top:20px;">
                        <div style="font-weight:600; font-size:14px; margin-bottom:8px;">📋 Instructions</div>
                        <ul style="color:#4a5568; font-size:13px; padding-left:20px; line-height:1.8;">
                            <li>Passkey must be exactly 6 digits (0-9)</li>
                            <li>User will use this passkey to log in to their account</li>
                            <li>Click the keypad buttons or use your keyboard to enter the passkey</li>
                            <li>Click ✓ to confirm, ⌫ to delete last digit, C to clear all</li>
                        </ul>
                    </div>
                </div>
            </div>
            
        </div><!-- /cardViewContainer -->

    <?php endif; ?>
</div><!-- /container -->

<!-- ===== MODALS ===== -->

<!-- Confirmation Modal -->
<div id="customModal" class="modal-overlay">
    <div class="modal-box">
        <h3 id="modalTitle">Confirm Action</h3>
        <div class="modal-msg" id="modalMsg"></div>
        <form id="modalForm" method="POST">
            <input type="hidden" name="tx_id" id="modalTxId" value="">
            <input type="hidden" name="asset_id" id="modalAssetId" value="">
            <input type="hidden" name="receipt_id" id="modalReceiptId" value="">
            <input type="hidden" name="deposit_id" id="modalDepositId" value="">
            <input type="hidden" name="user_id" id="modalUserId" value="">
            <input type="hidden" name="wallet_index" id="modalWalletIndex" value="">
            <input type="hidden" name="target_user" id="modalTargetUser" value="<?php echo $target_user; ?>">
            <div class="modal-btns">
                <button type="button" onclick="closeModal()" class="btn btn-dark">Cancel</button>
                <button type="submit" id="modalConfirmBtn" class="btn btn-danger">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Wallet Modal -->
<div id="createWalletModal" class="modal-overlay">
    <div class="modal-box">
        <h3>Create Wallet</h3>
        <p class="text-muted text-sm mb-16">Enter a name for the new wallet. Balance will start at 0.</p>
        <form method="POST">
            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
            <div class="form-group">
                <label>Wallet Name</label>
                <input type="text" name="wallet_name" placeholder="e.g. Savings, Investment, Crypto" required>
            </div>
            <div class="modal-btns">
                <button type="button" onclick="closeCreateWalletModal()" class="btn btn-dark">Cancel</button>
                <button type="submit" name="create_wallet" class="btn btn-indigo">Create Wallet</button>
            </div>
        </form>
    </div>
</div>

<!-- Passkey Modal -->
<div id="passkeyModal" class="modal-overlay passkey-modal">
    <div class="modal-box">
        <h3>🔑 Enter Passkey</h3>
        <p>Enter a 6-digit numeric passkey</p>
        <div class="passkey-display" id="passkeyDisplay">······</div>
        <div class="keypad-grid">
            <button type="button" class="keypad-btn" onclick="pressKey('1')">1</button>
            <button type="button" class="keypad-btn" onclick="pressKey('2')">2</button>
            <button type="button" class="keypad-btn" onclick="pressKey('3')">3</button>
            <button type="button" class="keypad-btn clear" onclick="clearPasskey()">C</button>
            <button type="button" class="keypad-btn" onclick="pressKey('4')">4</button>
            <button type="button" class="keypad-btn" onclick="pressKey('5')">5</button>
            <button type="button" class="keypad-btn" onclick="pressKey('6')">6</button>
            <button type="button" class="keypad-btn back" onclick="backspacePasskey()">⌫</button>
            <button type="button" class="keypad-btn" onclick="pressKey('7')">7</button>
            <button type="button" class="keypad-btn" onclick="pressKey('8')">8</button>
            <button type="button" class="keypad-btn" onclick="pressKey('9')">9</button>
            <button type="button" class="keypad-btn enter" onclick="submitPasskey()">✓</button>
        </div>
        <div class="btn-group">
            <button type="button" onclick="closePasskeyModal()" class="btn btn-dark">Cancel</button>
        </div>
        <input type="hidden" id="passkeyInput" value="">
    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal-overlay image-modal" onclick="closeImageModal()">
    <div class="modal-box" onclick="event.stopPropagation()" style="position:relative;">
        <button class="close-btn" onclick="closeImageModal()">×</button>
        <img id="modalImage" src="" alt="Full size image">
        <div class="image-title" id="modalImageTitle"></div>
    </div>
</div>

<!-- Deposit Modal -->
<?php if(isset($_GET['edit_deposit']) || isset($_GET['new_deposit'])): 
    $is_edit = isset($_GET['edit_deposit']);
    $deposit = $is_edit ? $edit_deposit : null;
?>
<div id="depositModal" class="modal-overlay active">
    <div class="modal-box" style="max-width:600px;">
        <h3><?php echo $is_edit ? '✏️ Edit Deposit' : '➕ Add New Deposit'; ?></h3>
        <form method="POST">
            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
            <?php if($is_edit): ?>
                <input type="hidden" name="deposit_id" value="<?php echo $deposit['id']; ?>">
            <?php endif; ?>
            
            <div class="form-row-3">
                <div class="form-group">
                    <label>Payment Type *</label>
                    <select name="payment_type" required>
                        <option value="paypal" <?php echo ($is_edit && $deposit['payment_type'] == 'paypal') ? 'selected' : ''; ?>>💳 PayPal</option>
                        <option value="cashapp" <?php echo ($is_edit && $deposit['payment_type'] == 'cashapp') ? 'selected' : ''; ?>>📱 CashApp</option>
                        <option value="zelle" <?php echo ($is_edit && $deposit['payment_type'] == 'zelle') ? 'selected' : ''; ?>>🏦 Zelle</option>
                        <option value="bitcoin" <?php echo ($is_edit && $deposit['payment_type'] == 'bitcoin') ? 'selected' : ''; ?>>🔗 Bitcoin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value/Address *</label>
                    <input type="text" name="payment_value" value="<?php echo $is_edit ? htmlspecialchars($deposit['payment_value']) : ''; ?>" placeholder="Email, cashtag, or address" required>
                </div>
                <div class="form-group">
                    <label>Receiver *</label>
                    <select name="payment_receiver" required>
                        <option value="court_official" <?php echo ($is_edit && $deposit['payment_receiver'] == 'court_official') ? 'selected' : ''; ?>>⚖️ Court Official</option>
                        <option value="legal_representative" <?php echo ($is_edit && $deposit['payment_receiver'] == 'legal_representative') ? 'selected' : ''; ?>>👤 Legal Representative</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Amount (<?php echo $currency_symbol; ?>) *</label>
                    <div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;">
                        <span class="currency-symbol" style="font-size:14px; color:#4a5568;"><?php echo $currency_symbol; ?></span>
                        <input type="number" step="0.01" name="amount" value="<?php echo $is_edit ? $deposit['amount'] : ''; ?>" placeholder="0.00" required style="border:none; padding:10px 0; flex:1; min-width:50px;">
                    </div>
                </div>
                <div class="form-group">
                    <label>Status *</label>
                    <select name="status" required>
                        <option value="pending" <?php echo ($is_edit && $deposit['status'] == 'pending') ? 'selected' : ''; ?>>⏳ Pending</option>
                        <option value="completed" <?php echo ($is_edit && $deposit['status'] == 'completed') ? 'selected' : ''; ?>>✅ Completed</option>
                        <option value="failed" <?php echo ($is_edit && $deposit['status'] == 'failed') ? 'selected' : ''; ?>>❌ Failed</option>
                        <option value="cancelled" <?php echo ($is_edit && $deposit['status'] == 'cancelled') ? 'selected' : ''; ?>>🚫 Cancelled</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" placeholder="Description..."><?php echo $is_edit ? htmlspecialchars($deposit['description']) : ''; ?></textarea>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Additional notes..."><?php echo $is_edit ? htmlspecialchars($deposit['notes']) : ''; ?></textarea>
            </div>
            
            <div class="modal-btns">
                <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?uid=<?php echo $target_user; ?>'" class="btn btn-dark">Cancel</button>
                <button type="submit" name="save_deposit" class="btn btn-indigo"><?php echo $is_edit ? 'Update' : 'Create'; ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Receipt Modal -->
<?php if($edit_receipt): ?>
<div id="receiptModal" class="modal-overlay active">
    <div class="modal-box" style="max-width:600px;">
        <h3>✏️ Edit Receipt #<?php echo $edit_receipt['id']; ?></h3>
        <form method="POST">
            <input type="hidden" name="receipt_id" value="<?php echo $edit_receipt['id']; ?>">
            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
            
            <div class="form-row-3">
                <div class="form-group"><label>Paid Date</label><input type="date" name="edit_paid_date" value="<?php echo $edit_receipt['paid_date']; ?>"></div>
                <div class="form-group"><label>Amount Paid</label><div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;"><span class="currency-symbol" style="font-size:13px; color:#4a5568;"><?php echo $currency_symbol; ?></span><input type="number" step="0.01" name="edit_amount_paid" value="<?php echo $edit_receipt['amount_paid']; ?>" style="border:none; padding:8px 0; flex:1; min-width:40px;"></div></div>
                <div class="form-group"><label>Payment Due</label><div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;"><span class="currency-symbol" style="font-size:13px; color:#4a5568;"><?php echo $currency_symbol; ?></span><input type="number" step="0.01" name="edit_payment_due" value="<?php echo $edit_receipt['payment_due']; ?>" style="border:none; padding:8px 0; flex:1; min-width:40px;"></div></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Payer Name</label><input type="text" name="edit_payer_name" value="<?php echo htmlspecialchars($edit_receipt['payer_name']); ?>"></div>
                <div class="form-group"><label>Receiver Name</label><input type="text" name="edit_receiver_name" value="<?php echo htmlspecialchars($edit_receipt['receiver_name']); ?>"></div>
            </div>
            <div class="form-group"><label>Payment Subject</label><input type="text" name="edit_payment_subject" value="<?php echo htmlspecialchars($edit_receipt['payment_subject']); ?>"></div>
            <div class="form-row-3">
                <div class="form-group"><label>Total Payment</label><div class="amount-display" style="border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; background:white;"><span class="currency-symbol" style="font-size:13px; color:#4a5568;"><?php echo $currency_symbol; ?></span><input type="number" step="0.01" name="edit_total_payment" value="<?php echo $edit_receipt['total_payment']; ?>" style="border:none; padding:8px 0; flex:1; min-width:40px;"></div></div>
                <div class="form-group"><label>Reference #</label><input type="text" name="edit_reference_number" value="<?php echo htmlspecialchars($edit_receipt['reference_number']); ?>"></div>
                <div class="form-group"><label>Status</label>
                    <select name="edit_receipt_status">
                        <option value="pending" <?php echo $edit_receipt['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $edit_receipt['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="failed" <?php echo $edit_receipt['status'] == 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="refunded" <?php echo $edit_receipt['status'] == 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="edit_receipt_notes" rows="2"><?php echo htmlspecialchars($edit_receipt['notes']); ?></textarea></div>
            
            <div class="modal-btns">
                <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?uid=<?php echo $target_user; ?>'" class="btn btn-dark">Cancel</button>
                <button type="submit" name="update_receipt" class="btn btn-warning">Update</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    // ===== NAVIGATION =====
    function showCardView(viewId) {
        document.getElementById('cardGridView').style.display = 'none';
        const container = document.getElementById('cardViewContainer');
        container.classList.add('active');
        
        document.querySelectorAll('.card-view').forEach(el => {
            el.style.display = 'none';
        });
        
        const target = document.getElementById('view-' + viewId);
        if (target) {
            target.style.display = 'block';
        }
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    function showGrid() {
        document.getElementById('cardGridView').style.display = 'grid';
        document.getElementById('cardViewContainer').classList.remove('active');
        document.querySelectorAll('.card-view').forEach(el => {
            el.style.display = 'none';
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    // ===== TRANSFER TABS =====
    function switchTransferTab(tabId) {
        document.querySelectorAll('.transfer-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelector(`.transfer-tab[data-tab="${tabId}"]`).classList.add('active');
        
        document.querySelectorAll('.transfer-panel').forEach(panel => {
            panel.classList.remove('active');
        });
        document.getElementById(tabId).classList.add('active');
    }
    
    // ===== USER SEARCH =====
    const allUsers = <?php echo json_encode($all_users_with_accounts); ?>;
    let selectedRecipient = null;
    let selectedRecipientWallets = [];
    
    function searchUserByAccount(accountNumber) {
        const resultDiv = document.getElementById('userSearchResult');
        const recipientInfo = document.getElementById('recipientInfo');
        const sendBtn = document.getElementById('sendToUserBtn');
        
        selectedRecipient = null;
        sendBtn.disabled = true;
        
        if (!accountNumber || accountNumber.length < 3) {
            resultDiv.innerHTML = '<div class="text-muted text-sm">Enter at least 3 characters to search...</div>';
            recipientInfo.style.display = 'none';
            return;
        }
        
        const matches = allUsers.filter(u => 
            u.account_number && u.account_number.toString().includes(accountNumber)
        );
        
        if (matches.length === 0) {
            resultDiv.innerHTML = '<div class="user-search-result user-not-found"><span>❌ No user found with that account number</span></div>';
            recipientInfo.style.display = 'none';
            return;
        }
        
        if (matches.length === 1) {
            const user = matches[0];
            selectedRecipient = user;
            resultDiv.innerHTML = `
                <div class="user-search-result user-found">
                    <div class="user-info">
                        <span class="name">✅ ${escapeHtml(user.full_name)}</span>
                        <span class="account">Account: ${escapeHtml(user.account_number)}</span>
                        <span class="email">${escapeHtml(user.username || '')}</span>
                    </div>
                    <button type="button" class="btn btn-success btn-sm" onclick="selectRecipient(${user.id})">Select</button>
                </div>
            `;
            recipientInfo.style.display = 'none';
            return;
        }
        
        let html = '<div style="background:#f7fafc; border-radius:8px; padding:8px; margin-top:8px;"><div class="text-muted text-sm" style="margin-bottom:8px;">Multiple users found. Select one:</div>';
        matches.forEach(user => {
            html += `
                <div class="user-search-result" style="margin:4px 0; padding:8px 12px;">
                    <div class="user-info">
                        <span class="name">${escapeHtml(user.full_name)}</span>
                        <span class="account">Account: ${escapeHtml(user.account_number)}</span>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" onclick="selectRecipient(${user.id})">Select</button>
                </div>
            `;
        });
        html += '</div>';
        resultDiv.innerHTML = html;
        recipientInfo.style.display = 'none';
    }
    
    function selectRecipient(userId) {
        const user = allUsers.find(u => u.id == userId);
        if (!user) return;
        
        selectedRecipient = user;
        
        // Parse recipient's wallets
        try {
            if (user.wallets) {
                selectedRecipientWallets = typeof user.wallets === 'string' ? JSON.parse(user.wallets) : user.wallets;
                if (!Array.isArray(selectedRecipientWallets)) selectedRecipientWallets = [];
            } else {
                selectedRecipientWallets = [];
            }
        } catch (e) {
            selectedRecipientWallets = [];
        }
        
        // Update UI
        document.getElementById('recipientName').textContent = user.full_name;
        document.getElementById('recipientAccount').textContent = 'Account: ' + user.account_number;
        document.getElementById('recipientEmail').textContent = user.username || '';
        document.getElementById('recipientUserId').value = user.id;
        document.getElementById('recipientInfo').style.display = 'block';
        document.getElementById('userSearchResult').innerHTML = '';
        document.getElementById('sendToUserBtn').disabled = false;
        
        // Update recipient bucket dropdown with their wallets
        updateRecipientWallets();
        
        showNotification('User selected: ' + user.full_name, 'success');
    }
    
    function updateRecipientWallets() {
        const group = document.getElementById('recipientWalletsGroup');
        if (!group) return;
        
        // Clear existing wallet options
        group.innerHTML = '';
        
        // Add recipient's wallets if any
        if (selectedRecipientWallets && selectedRecipientWallets.length > 0) {
            selectedRecipientWallets.forEach((wallet, idx) => {
                const option = document.createElement('option');
                option.value = 'wallet_' + idx;
                option.textContent = wallet.wallet_name + ' (Wallet)';
                option.className = 'wallet-option';
                group.appendChild(option);
            });
        } else {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No wallets available';
            option.disabled = true;
            group.appendChild(option);
        }
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // ===== TRANSFER FIELDS =====
    function toggleTransferFields() {
        const method = document.getElementById('methodSelect').value;
        document.querySelectorAll('[id^="fields_"]').forEach(div => {
            div.style.display = 'none';
        });
        const target = document.getElementById('fields_' + method);
        if (target) target.style.display = 'grid';
    }
    
    // ===== TOGGLE DISPLAY BUCKET =====
    function toggleDisplayBucket(bucketName, isChecked) {
        const action = isChecked ? 'show' : 'hide';
        const form = document.createElement('form');
        form.method = 'POST';
        
        const hiddenAction = document.createElement('input');
        hiddenAction.type = 'hidden';
        hiddenAction.name = 'toggle_display_bucket';
        hiddenAction.value = '1';
        
        const hiddenBucket = document.createElement('input');
        hiddenBucket.type = 'hidden';
        hiddenBucket.name = 'bucket_name';
        hiddenBucket.value = bucketName;
        
        const hiddenActionType = document.createElement('input');
        hiddenActionType.type = 'hidden';
        hiddenActionType.name = 'action';
        hiddenActionType.value = action;
        
        form.appendChild(hiddenAction);
        form.appendChild(hiddenBucket);
        form.appendChild(hiddenActionType);
        document.body.appendChild(form);
        form.submit();
    }
    
    // ===== PASSKEY KEYPAD =====
    let passkeyValue = '';
    
    function openPasskeyModal() {
        passkeyValue = '';
        document.getElementById('passkeyDisplay').textContent = '······';
        document.getElementById('passkeyInput').value = '';
        document.getElementById('passkeyModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closePasskeyModal() {
        document.getElementById('passkeyModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function pressKey(num) {
        if (passkeyValue.length < 6) {
            passkeyValue += num;
            updatePasskeyDisplay();
        }
    }
    
    function backspacePasskey() {
        passkeyValue = passkeyValue.slice(0, -1);
        updatePasskeyDisplay();
    }
    
    function clearPasskey() {
        passkeyValue = '';
        updatePasskeyDisplay();
    }
    
    function updatePasskeyDisplay() {
        const display = document.getElementById('passkeyDisplay');
        let dots = '';
        for (let i = 0; i < 6; i++) {
            dots += i < passkeyValue.length ? '●' : '·';
        }
        display.textContent = dots;
        document.getElementById('passkeyInput').value = passkeyValue;
    }
    
    function submitPasskey() {
        if (passkeyValue.length === 6) {
            document.getElementById('profilePasskeyHidden').value = passkeyValue;
            document.getElementById('profilePasskeyDisplay').value = passkeyValue;
            closePasskeyModal();
            showNotification('Passkey set successfully!', 'success');
        } else {
            showNotification('Please enter exactly 6 digits', 'error');
        }
    }
    
    function clearProfilePasskey() {
        document.getElementById('profilePasskeyHidden').value = '';
        document.getElementById('profilePasskeyDisplay').value = '';
        showNotification('Passkey cleared', 'success');
    }
    
    function clearPasskeyDirect() {
        if (confirm('Clear the passkey for this user?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'clear_passkey_direct';
            hidden.value = '1';
            const userId = document.createElement('input');
            userId.type = 'hidden';
            userId.name = 'user_id';
            userId.value = '<?php echo $target_user; ?>';
            form.appendChild(hidden);
            form.appendChild(userId);
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // ===== CREATE WALLET MODAL =====
    function openCreateWalletModal() {
        document.getElementById('createWalletModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        document.querySelector('#createWalletModal input[name="wallet_name"]').focus();
    }
    
    function closeCreateWalletModal() {
        document.getElementById('createWalletModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function confirmDeleteWallet(index) {
        document.getElementById('modalTitle').textContent = 'Delete Wallet';
        document.getElementById('modalMsg').textContent = 'Delete this wallet permanently? This will remove all its data.';
        document.getElementById('modalWalletIndex').value = index;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'delete_wallet';
        confirmBtn.textContent = 'Delete Wallet';
        confirmBtn.className = 'btn btn-danger';
        document.getElementById('customModal').classList.add('active');
    }
    
    // ===== SECURITY QUESTIONS =====
    let securityQuestions = <?php echo json_encode($security_questions); ?>;
    let editIndex = -1;
    
    function renderSecurityQuestions() {
        const container = document.getElementById('securityQuestionsList');
        if (!container) return;
        
        if (securityQuestions.length === 0) {
            container.innerHTML = '<div class="empty-state"><div class="icon">📭</div><p>No security questions set up</p></div>';
            return;
        }
        
        let html = '';
        securityQuestions.forEach((sq, index) => {
            html += `
                <div class="security-question-item">
                    <div class="q-text">❓ ${escapeHtml(sq.question || 'Untitled')}</div>
                    <div class="a-text">🔑 ${escapeHtml(sq.answer || 'No answer')}</div>
                    <div class="actions">
                        <button onclick="editSecurityQuestion(${index})" class="btn btn-warning btn-xs">Edit</button>
                        <button onclick="deleteSecurityQuestion(${index})" class="btn btn-danger btn-xs">Delete</button>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
        updateHiddenJson();
    }
    
    function updateHiddenJson() {
        document.getElementById('securityQuestionsJson').value = JSON.stringify(securityQuestions);
    }
    
    function addSecurityQuestion() {
        const question = document.getElementById('sqQuestion').value.trim();
        const answer = document.getElementById('sqAnswer').value.trim();
        
        if (!question || !answer) {
            showNotification('Please fill in both question and answer', 'error');
            return;
        }
        
        securityQuestions.push({ question: question, answer: answer });
        document.getElementById('sqQuestion').value = '';
        document.getElementById('sqAnswer').value = '';
        renderSecurityQuestions();
        showNotification('Question added successfully', 'success');
    }
    
    function editSecurityQuestion(index) {
        editIndex = index;
        const sq = securityQuestions[index];
        document.getElementById('sqQuestion').value = sq.question || '';
        document.getElementById('sqAnswer').value = sq.answer || '';
        document.getElementById('editIndex').value = index;
        document.getElementById('addSecurityBtn').style.display = 'none';
        document.getElementById('updateSecurityBtn').style.display = 'inline-block';
        document.getElementById('cancelSecurityBtn').style.display = 'inline-block';
        document.getElementById('sqQuestion').focus();
    }
    
    function updateSecurityQuestion() {
        const question = document.getElementById('sqQuestion').value.trim();
        const answer = document.getElementById('sqAnswer').value.trim();
        const index = parseInt(document.getElementById('editIndex').value);
        
        if (index < 0 || index >= securityQuestions.length) {
            showNotification('Invalid question index', 'error');
            return;
        }
        
        if (!question || !answer) {
            showNotification('Please fill in both question and answer', 'error');
            return;
        }
        
        securityQuestions[index] = { question: question, answer: answer };
        document.getElementById('sqQuestion').value = '';
        document.getElementById('sqAnswer').value = '';
        document.getElementById('editIndex').value = '-1';
        document.getElementById('addSecurityBtn').style.display = 'inline-block';
        document.getElementById('updateSecurityBtn').style.display = 'none';
        document.getElementById('cancelSecurityBtn').style.display = 'none';
        renderSecurityQuestions();
        showNotification('Question updated successfully', 'success');
    }
    
    function cancelEditSecurity() {
        document.getElementById('sqQuestion').value = '';
        document.getElementById('sqAnswer').value = '';
        document.getElementById('editIndex').value = '-1';
        document.getElementById('addSecurityBtn').style.display = 'inline-block';
        document.getElementById('updateSecurityBtn').style.display = 'none';
        document.getElementById('cancelSecurityBtn').style.display = 'none';
    }
    
    function deleteSecurityQuestion(index) {
        if (confirm('Delete this security question?')) {
            securityQuestions.splice(index, 1);
            renderSecurityQuestions();
            showNotification('Question deleted successfully', 'success');
        }
    }
    
    // ===== MODALS =====
    function closeModal() {
        document.getElementById('customModal').classList.remove('active');
    }
    
    function openImageModal(imageUrl, title) {
        const modal = document.getElementById('imageModal');
        document.getElementById('modalImage').src = imageUrl;
        document.getElementById('modalImageTitle').textContent = title || 'Asset Image';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeImageModal() {
        document.getElementById('imageModal').classList.remove('active');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            document.getElementById('modalImage').src = '';
        }, 200);
    }
    
    function showNotification(msg, type) {
        const notify = document.getElementById('notify');
        notify.textContent = msg;
        notify.className = 'show';
        if (type === 'error') notify.classList.add('error');
        setTimeout(() => {
            notify.className = '';
            notify.classList.remove('error');
        }, 3500);
    }
    
    // ===== CONFIRMATION FUNCTIONS =====
    function confirmDeleteAsset(id) {
        document.getElementById('modalTitle').textContent = 'Delete Asset';
        document.getElementById('modalMsg').textContent = 'Delete this portfolio asset permanently?';
        document.getElementById('modalAssetId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'delete_asset';
        confirmBtn.textContent = 'Delete Asset';
        confirmBtn.className = 'btn btn-danger';
        document.getElementById('customModal').classList.add('active');
    }
    
    function confirmDeleteReceipt(id) {
        document.getElementById('modalTitle').textContent = 'Delete Receipt';
        document.getElementById('modalMsg').textContent = 'Delete this payment receipt permanently?';
        document.getElementById('modalReceiptId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'delete_receipt';
        confirmBtn.textContent = 'Delete Receipt';
        confirmBtn.className = 'btn btn-danger';
        document.getElementById('customModal').classList.add('active');
    }
    
    function confirmDeleteDeposit(id) {
        document.getElementById('modalTitle').textContent = 'Delete Deposit';
        document.getElementById('modalMsg').textContent = 'Delete this deposit record permanently?';
        document.getElementById('modalDepositId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'delete_deposit';
        confirmBtn.textContent = 'Delete Deposit';
        confirmBtn.className = 'btn btn-danger';
        document.getElementById('customModal').classList.add('active');
    }
    
    function confirmDeleteTransaction(id) {
        document.getElementById('modalTitle').textContent = 'Delete Transaction';
        document.getElementById('modalMsg').textContent = 'Delete this transaction record permanently? This cannot be undone.';
        document.getElementById('modalTxId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'delete_transaction';
        confirmBtn.textContent = 'Delete Transaction';
        confirmBtn.className = 'btn btn-danger';
        document.getElementById('customModal').classList.add('active');
    }
    
    function confirmRefund(id, amount) {
        document.getElementById('modalTitle').textContent = 'Confirm Refund';
        document.getElementById('modalMsg').textContent = `Refund ${amount} to the original source and remove this record?`;
        document.getElementById('modalTxId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'refund_tx';
        confirmBtn.textContent = 'Confirm Refund';
        confirmBtn.className = 'btn btn-success';
        document.getElementById('customModal').classList.add('active');
    }
    
    function confirmDeleteUser(id, name) {
        document.getElementById('modalTitle').textContent = 'Delete User';
        document.getElementById('modalMsg').textContent = `Delete account for "${name}" permanently? This cannot be undone.`;
        document.getElementById('modalUserId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = 'delete_user_direct';
        confirmBtn.textContent = 'Delete User';
        confirmBtn.className = 'btn btn-danger';
        document.getElementById('customModal').classList.add('active');
    }
    
    // ===== KEYBOARD SHORTCUTS =====
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closeImageModal();
            closePasskeyModal();
            closeCreateWalletModal();
        }
        
        const passkeyModal = document.getElementById('passkeyModal');
        if (passkeyModal && passkeyModal.classList.contains('active')) {
            if (e.key >= '0' && e.key <= '9') {
                e.preventDefault();
                pressKey(e.key);
            } else if (e.key === 'Backspace') {
                e.preventDefault();
                backspacePasskey();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                submitPasskey();
            }
        }
        
        const walletModal = document.getElementById('createWalletModal');
        if (walletModal && walletModal.classList.contains('active')) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const form = walletModal.querySelector('form');
                if (form) form.submit();
            }
        }
    });
    
    // ===== DOM INIT =====
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const view = urlParams.get('view');
        if (view && document.getElementById('view-' + view)) {
            showCardView(view);
        }
        
        const notify = document.getElementById('notify');
        if (notify && notify.classList.contains('show')) {
            setTimeout(() => {
                notify.className = '';
                notify.classList.remove('error');
            }, 3500);
        }
        
        const addBtn = document.getElementById('addSecurityBtn');
        if (addBtn) addBtn.addEventListener('click', addSecurityQuestion);
        
        const updateBtn = document.getElementById('updateSecurityBtn');
        if (updateBtn) updateBtn.addEventListener('click', updateSecurityQuestion);
        
        const cancelBtn = document.getElementById('cancelSecurityBtn');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelEditSecurity);
        
        document.getElementById('sqQuestion')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('sqAnswer').focus();
            }
        });
        
        document.getElementById('sqAnswer')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (document.getElementById('updateSecurityBtn').style.display !== 'none') {
                    updateSecurityQuestion();
                } else {
                    addSecurityQuestion();
                }
            }
        });
    });
</script>
</div>
</body>
</html>
