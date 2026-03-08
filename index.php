<?php
    session_start();
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    require 'db.php';

    // --- LOGIN LOGIC ---
    if (isset($_POST['login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $login_error = "Invalid username/email or password";
        }
    }

    // --- LOGOUT LOGIC ---
    if (isset($_GET['logout'])) {
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Check if user is logged in
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

    // --- HELPER: Random Sentence Generator ---
    function generateTransferSentence($amount, $method, $detail) {
        $sentences = [
            "A secure allocation of $$amount has been initiated via $method ($detail).",
            "System confirms the dispatch of $$amount to the registered $method account: $detail.",
            "Beneficiary payout of $$amount is currently being routed through $method to $detail.",
            "Financial release of $$amount authorized for external transfer ($method: $detail).",
            "Processing a transaction of $$amount directed towards $method address $detail."
        ];
        return $sentences[array_rand($sentences)];
    }

    // Function to resolve image paths
    function resolveImagePath($image_path) {
        if (empty($image_path)) {
            return null;
        }
        
        if (filter_var($image_path, FILTER_VALIDATE_URL)) {
            return $image_path;
        }
        
        $document_root = $_SERVER['DOCUMENT_ROOT'];
        $script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
        
        $paths_to_try = [];
        $paths_to_try[] = $image_path;
        $paths_to_try[] = 'uploads/' . basename($image_path);
        $paths_to_try[] = './uploads/' . basename($image_path);
        $paths_to_try[] = $document_root . '/' . ltrim($image_path, '/');
        $paths_to_try[] = $script_dir . '/' . $image_path;
        $paths_to_try[] = $document_root . '/uploads/' . basename($image_path);
        
        if (file_exists($image_path)) {
            return $image_path;
        }
        
        foreach ($paths_to_try as $path) {
            if (file_exists($path)) {
                if (strpos($path, $document_root) === 0) {
                    $web_path = substr($path, strlen($document_root));
                    return $web_path;
                }
                return $path;
            }
        }
        
        return null;
    }

    // Function to get web-accessible image URL
    function getImageUrl($image_path) {
        $resolved_path = resolveImagePath($image_path);
        
        if ($resolved_path && file_exists($resolved_path)) {
            if (filter_var($resolved_path, FILTER_VALIDATE_URL)) {
                return $resolved_path;
            }
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $base_url = $protocol . $host;
            $clean_path = str_replace('//', '/', '/' . ltrim($resolved_path, './'));
            
            return $base_url . $clean_path;
        }
        
        return null;
    }

    // --- LOGIC: DECLINE COURT TRANSFER ---
    if (isset($_POST['decline_court_transfer']) && $user_id) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT processed_amount FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $processed = (float)$stmt->fetchColumn();
            
            if ($processed >= 15000) {
                $stmt = $pdo->prepare("UPDATE inheritance_accounts SET available_balance = available_balance + 15000, processed_amount = processed_amount - 15000 WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                $stmt = $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'refund', 15000, 'Completed', 'Court transfer declined; funds returned to available balance', NOW())");
                $stmt->execute([$user_id]);
                $pdo->commit();
                header("Location: " . $_SERVER['PHP_SELF'] . "?msg=Court transfer declined successfully");
                exit;
            } else {
                $error = "No court transfer available to decline";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }

    // --- LOGIC: WITHDRAWAL (External Transfer) ---
    if (isset($_POST['withdraw']) && $user_id) {
        $amount = (float)$_POST['amount'];
        $method = $_POST['transfer_method'];
        $status_label = 'Pending';
        $receiver_name = $_POST['receiver_name'] ?? 'Beneficiary';
        
        try {
            $pdo->beginTransaction();
            
            // Get account info including max withdrawal amount and next withdrawal date
            $stmt = $pdo->prepare("SELECT available_balance, maximum_withdrawal_amount, next_withdrawal_date FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $account_check = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $avail = (float)$account_check['available_balance'];
            $max_withdrawal = (float)($account_check['maximum_withdrawal_amount'] ?? $avail);
            $next_date = $account_check['next_withdrawal_date'];
            $current_date = date('Y-m-d');
            
            // Check if withdrawal is allowed based on next_withdrawal_date
            if ($next_date && $next_date > $current_date) {
                throw new Exception("Withdrawals are not permitted until " . date('F d, Y', strtotime($next_date)));
            }
            
            if ($avail < $amount) {
                throw new Exception("Insufficient available balance");
            }
            
            if ($amount > $max_withdrawal) {
                throw new Exception("Amount exceeds maximum withdrawal limit of $" . number_format($max_withdrawal, 2));
            }
            
            $detail = "Receiver: $receiver_name | ";
            if($method == 'bank') {
                $bank_name = $_POST['b_name'] ?? '';
                $acc_number = $_POST['b_acc'] ?? '';
                $routing = $_POST['b_routing'] ?? '';
                $swift = $_POST['b_swift'] ?? '';
                
                $detail .= "Bank: $bank_name | Acc: $acc_number";
                if (!empty($routing)) $detail .= " | Routing: $routing";
                if (!empty($swift)) $detail .= " | SWIFT: $swift";
            } elseif($method == 'paypal') {
                $detail .= "PayPal: " . $_POST['pp_email'];
            } elseif($method == 'cashapp') {
                $detail .= "CashApp: " . $_POST['ca_tag'];
            } elseif($method == 'venmo') {
                $detail .= "Venmo: " . $_POST['vn_user'];
            } elseif($method == 'crypto') {
                $detail .= "Crypto: " . $_POST['wallet_address'] . " (Chain: " . $_POST['crypto_chain'] . ")";
            }
            
            $desc = generateTransferSentence($amount, strtoupper($method), $detail);
            
            $stmt = $pdo->prepare("UPDATE inheritance_accounts SET available_balance = available_balance - ?, processed_amount = processed_amount + ? WHERE user_id = ?");
            $stmt->execute([$amount, $amount, $user_id]);
            
            $stmt = $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'external_transfer', ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $amount, $status_label, $desc]);
            
            $pdo->commit();
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=Transfer initiated successfully. Processing may take up to 10 days.");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }

    // --- DATA FETCH (only if logged in) ---
    $account = null;
    $user = null;
    $transactions = [];
    $assets = [];
    $court_letters = [];
    $payment_receipts = [];
    $latest_receipt = null;

    if ($user_id) {
        // Get account info with new fields
        $stmt = $pdo->prepare("SELECT * FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no account exists, create one with default values for new fields
        if (!$account) {
            $stmt = $pdo->prepare("INSERT INTO inheritance_accounts (user_id, total_amount, processed_amount, in_process_balance, available_balance, withdrawal_status, legal_representative, testator, maximum_withdrawal_amount) VALUES (?, 0, 0, 0, 0, 'Active', 'Pending Assignment', 'Estate of Deceased', 0)");
            $stmt->execute([$user_id]);
            
            $stmt = $pdo->prepare("SELECT * FROM inheritance_accounts WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Get user info
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get transaction history (exclude internal relocations)
        $stmt = $pdo->prepare("SELECT * FROM transaction_history WHERE user_id = ? AND description NOT LIKE '%Internal Relocation%' ORDER BY id DESC LIMIT 20");
        $stmt->execute([$user_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get portfolio assets
        $stmt = $pdo->prepare("SELECT * FROM portfolio_assets WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get court letters (using court_letters table)
        $stmt = $pdo->prepare("SELECT * FROM court_letters WHERE user_id = ? AND status = 'active' ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $court_letters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get payment receipts
        $stmt = $pdo->prepare("SELECT * FROM payments_receipt WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $payment_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get latest receipt
        if (!empty($payment_receipts)) {
            $latest_receipt = $payment_receipts[0];
        }
    }

    // Determine if withdrawal is allowed
    $withdrawal_disabled = false;
    $withdrawal_message = '';

    if ($user_id && $account) {
        $current_date = date('Y-m-d');
        $next_date = $account['next_withdrawal_date'] ?? null;
        
        if ($next_date && $next_date > $current_date) {
            $withdrawal_disabled = true;
            $withdrawal_message = "Next withdrawal available: " . date('F d, Y', strtotime($next_date));
            $next_formatted_date =  date('F d, Y', strtotime($next_date));
        } elseif ($account['available_balance'] <= 0) {
            $withdrawal_disabled = true;
            $withdrawal_message = "No funds available for disbursement";
        }
    }

    // Get message from URL
    $message = "";
    if (isset($_GET['msg'])) {
        $message = $_GET['msg'];
    }
    if (isset($error)) {
        $message = "ERROR: " . $error;
    }
    if (isset($login_error)) {
        $message = "ERROR: " . $login_error;
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Estate Management System | Official Beneficiary Portal</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Times New Roman', Georgia, Garamond, serif;
            background: #e8e6e1;
            margin: 0;
            min-height: 100vh;
            color: #2c3e50;
            line-height: 1.6;
            overflow: hidden; /* Disable body scroll */
            height: 100vh;
            width: 100%;
        }
        
        /* Custom scrollable body */
        .custom-body {
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            scroll-behavior: smooth;
            width: 100%;
            padding: 10px;
        }
        
        .custom-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .custom-body::-webkit-scrollbar-track {
            background: #e9d9c4;
        }
        
        .custom-body::-webkit-scrollbar-thumb {
            background: #8b6b4d;
            border-radius: 4px;
        }
        
        .custom-body::-webkit-scrollbar-thumb:hover {
            background: #5a3e2b;
        }
        
        /* Login Modal - Document Style */
        .login-container {
            background: #fcf9f4;
            padding: 45px 50px;
            border-radius: 0;
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 480px;
            border: 1px solid #d4b68a;
            position: relative;
            margin: 30px auto;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #e6d5b8;
            pointer-events: none;
        }
        
        .login-container h2 {
            color: #5a3e2b;
            margin-bottom: 30px;
            text-align: center;
            font-weight: 400;
            letter-spacing: 1px;
            font-size: 28px;
            border-bottom: 2px solid #d4b68a;
            padding-bottom: 15px;
            font-family: 'Times New Roman', serif;
        }
        
        .login-container .form-group {
            margin-bottom: 25px;
        }
        
        .login-container label {
            display: block;
            margin-bottom: 8px;
            color: #5a3e2b;
            font-weight: 500;
            font-size: 16px;
            font-family: 'Times New Roman', serif;
        }
        
        .login-container input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #d0b28c;
            border-radius: 0;
            font-size: 16px;
            transition: all 0.3s;
            background: #fffcf7;
            font-family: 'Times New Roman', serif;
        }
        
        .login-container input:focus {
            outline: none;
            border-color: #8b6b4d;
            box-shadow: 0 0 0 2px rgba(139, 107, 77, 0.2);
        }
        
        .login-container button {
            width: 100%;
            padding: 14px;
            background: #5a3e2b;
            color: #f5e6d3;
            border: none;
            border-radius: 0;
            font-size: 18px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            font-family: 'Times New Roman', serif;
            letter-spacing: 0.5px;
        }
        
        .login-container button:hover {
            background: #3f2e1f;
        }
        
        .login-footer {
            margin-top: 25px;
            text-align: center;
            color: #7f6b5a;
            font-size: 15px;
            font-style: italic;
            border-top: 1px dashed #d4b68a;
            padding-top: 15px;
        }
        
        /* Dashboard - Document Style */
        .dashboard {
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
            background: #fcf9f4;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
            border: 1px solid #d4b68a;
            position: relative;
            overflow-x: hidden;
        }
        
        .dashboard::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 15px;
            right: 15px;
            bottom: 15px;
            border: 1px solid #e6d5b8;
            pointer-events: none;
        }
        
        /* Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: #fffcf7;
            padding: 20px 30px;
            border-bottom: 2px solid #d4b68a;
            position: relative;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .dashboard-header h1 {
            color: #3f2e1f;
            font-weight: 400;
            font-size: clamp(18px, 4vw, 26px);
            letter-spacing: 0.5px;
            font-family: 'Times New Roman', serif;
            word-break: break-word;
        }
        
        /* Document-style Cards */
        .card {
            background: #fffcf7;
            border: 1px solid #d0b28c;
            padding: 20px;
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 5px;
            left: 5px;
            right: 5px;
            bottom: 5px;
            border: 1px solid #e9d9c4;
            pointer-events: none;
        }
        
        h3 {
            color: #5a3e2b;
            font-size: clamp(18px, 3vw, 20px);
            font-weight: 500;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2cfb5;
            font-family: 'Times New Roman', serif;
            letter-spacing: 0.3px;
            word-break: break-word;
        }
        
        h4 {
            color: #5a3e2b;
            font-size: clamp(16px, 2.5vw, 18px);
            font-weight: 500;
            margin-bottom: 15px;
            font-family: 'Times New Roman', serif;
            word-break: break-word;
        }
        
        /* Balance Section - Combined */
        .balance-section {
            background: #f6efe5;
            border: 1px solid #d0b28c;
            padding: 20px;
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            position: relative;
            width: 100%;
        }
        
        @media screen and (max-width: 640px) {
            .balance-section {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        .balance-section::before {
            content: '';
            position: absolute;
            top: 8px;
            left: 8px;
            right: 8px;
            bottom: 8px;
            border: 1px solid #e2cfb5;
            pointer-events: none;
        }
        
        .balance-item {
            text-align: center;
            width: 100%;
        }
        
        .balance-item .label {
            color: #5a3e2b;
            font-size: clamp(14px, 2vw, 16px);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            font-weight: 500;
            word-break: break-word;
        }
        
        .balance-item .amount {
            font-size: clamp(28px, 6vw, 42px);
            font-weight: 400;
            color: #2c3e50;
            font-family: 'Times New Roman', serif;
            line-height: 1.2;
            border-bottom: 2px solid #d4b68a;
            padding-bottom: 10px;
            margin-bottom: 10px;
            word-break: break-word;
        }
        
        .balance-item .amount small {
            font-size: clamp(14px, 3vw, 18px);
            color: #7f6b5a;
        }
        
        .balance-item .desc {
            color: #6b5a4a;
            font-size: clamp(12px, 2vw, 14px);
            font-style: italic;
            word-break: break-word;
        }
        
        /* Status Message Section - New */
        .status-section {
            background: #fcf9f4;
            border: 1px solid #d0b28c;
            padding: 15px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            position: relative;
            font-size: clamp(13px, 2vw, 15px);
            width: 100%;
        }
        
        .status-section::before {
            content: '';
            position: absolute;
            top: 5px;
            left: 5px;
            right: 5px;
            bottom: 5px;
            border: 1px solid #e9d9c4;
            pointer-events: none;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #5a3e2b;
            flex-wrap: wrap;
            flex: 1 1 auto;
            min-width: 200px;
        }
        
        .status-item strong {
            font-weight: 600;
            margin-right: 5px;
            word-break: break-word;
        }
        
        .status-badge {
            background: #f6efe5;
            border: 1px solid #d0b28c;
            padding: 5px 10px;
            font-size: clamp(11px, 2vw, 14px);
            color: #5a3e2b;
            white-space: normal;
            word-break: break-word;
        }
        
        .in-process-info {
            background: #e9d9c4;
            color: #3f2e1f;
            padding: 8px 15px;
            border-left: 3px solid #5a3e2b;
            font-style: italic;
            width: 100%;
            word-break: break-word;
        }
        
        .message-box {
            background: #fffcf7;
            border: 1px solid #d4b68a;
            padding: 15px;
            margin: 15px 0;
            font-size: clamp(13px, 2vw, 15px);
            color: #5a3e2b;
            border-left-width: 4px;
            word-break: break-word;
            width: 100%;
        }
        
        /* Main Content Grid - Two Columns */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
            width: 100%;
        }
        
        @media screen and (max-width: 900px) {
            .main-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
        
        /* Withdrawal Form */
        .receiver-section {
            background: #f6efe5;
            border: 1px solid #d0b28c;
            padding: 15px;
            margin-bottom: 20px;
            width: 100%;
        }
        
        .form-group {
            margin-bottom: 15px;
            width: 100%;
        }
        
        label {
            display: block;
            margin-bottom: 6px;
            color: #5a3e2b;
            font-weight: 500;
            font-size: clamp(13px, 2vw, 15px);
            font-family: 'Times New Roman', serif;
            word-break: break-word;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d0b28c;
            background: #fffcf7;
            font-family: 'Times New Roman', serif;
            font-size: clamp(13px, 2vw, 15px);
            transition: all 0.3s;
            border-radius: 0;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #8b6b4d;
            box-shadow: 0 0 0 2px rgba(139, 107, 77, 0.1);
        }
        
        .method-fields {
            margin-top: 20px;
            padding: 15px;
            background: #f6efe5;
            border: 1px solid #d0b28c;
            width: 100%;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        @media screen and (max-width: 480px) {
            .grid-2 {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        
        .btn {
            padding: 12px 15px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            width: 100%;
            font-size: clamp(14px, 2.5vw, 16px);
            transition: all 0.3s;
            font-family: 'Times New Roman', serif;
            background: #5a3e2b;
            color: #f5e6d3;
            letter-spacing: 0.5px;
            border: 1px solid #3f2e1f;
            position: relative;
            white-space: normal;
            word-break: break-word;
        }
        
        .btn:hover {
            background: #3f2e1f;
        }
        
        .btn-red {
            background: #7d4e3a;
            border-color: #5a3e2b;
        }
        
        .btn-red:hover {
            background: #5a3e2b;
        }
        
        .btn-logout {
            width: auto;
            padding: 8px 15px;
            background: #7f6b5a;
            border-color: #5a3e2b;
            font-size: clamp(13px, 2vw, 14px);
        }
        
        .btn:disabled {
            background: #b7a18f;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        .btn-small {
            padding: 8px 12px;
            font-size: clamp(12px, 2vw, 14px);
            width: auto;
            background: #7f6b5a;
        }
        
        /* Receipt Button */
        .receipt-button {
            background: #5a3e2b;
            color: #f5e6d3;
            border: 1px solid #3f2e1f;
            padding: 8px 12px;
            cursor: pointer;
            font-family: 'Times New Roman', serif;
            font-size: clamp(12px, 2vw, 14px);
            transition: all 0.3s;
            margin-left: 10px;
            white-space: normal;
        }
        
        @media screen and (max-width: 768px) {
            .receipt-button {
                margin-left: 0;
                width: 100%;
                margin-top: 10px;
            }
        }
        
        .receipt-button:hover {
            background: #3f2e1f;
        }
        
        /* Receipt Overlay */
        #receiptOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.98);
            z-index: 10000;
            overflow-y: auto;
            padding: 15px;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            background: #fcf9f4;
            border: 2px solid #d4b68a;
            padding: 25px;
            position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            width: 100%;
        }
        
        @media screen and (max-width: 768px) {
            .receipt-container {
                padding: 15px;
                margin: 10px auto;
            }
        }
        
        .receipt-container::before {
            content: '';
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #e6d5b8;
            pointer-events: none;
        }
        
        /* Document style for download */
        .receipt-container.document-style {
            background: white;
            border: none;
            box-shadow: none;
            color: black;
            padding: 100px;
            margin-bottom: 50px;
        }
        
        .receipt-container.document-style::before {
            display: none;
        }
        
        .receipt-container.document-style .receipt-header h2,
        .receipt-container.document-style .receipt-header p,
        .receipt-container.document-style .receipt-label,
        .receipt-container.document-style .receipt-value,
        .receipt-container.document-style .receipt-amount,
        .receipt-container.document-style .receipt-footer p {
            color: black;
        }
        
        .receipt-container.document-style .receipt-header {
            border-bottom-color: #000;
        }
        
        .receipt-container.document-style .receipt-row {
            border-bottom-color: #ccc;
        }
        
        .receipt-container.document-style .receipt-amount {
            background: #f5f5f5;
            border-color: #000;
            color: black;
        }
        
        .receipt-container.document-style .receipt-footer {
            border-top-color: #000;
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #d4b68a;
            padding-bottom: 15px;
        }
        
        .receipt-header h2 {
            color: #5a3e2b;
            font-size: clamp(22px, 5vw, 28px);
            font-weight: 400;
            margin-bottom: 10px;
            word-break: break-word;
        }
        
        .receipt-header p {
            color: #7f6b5a;
            font-size: clamp(13px, 2.5vw, 16px);
            word-break: break-word;
        }
        
        .receipt-details {
            margin-bottom: 20px;
            width: 100%;
        }
        
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2cfb5;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .receipt-label {
            font-weight: 600;
            color: #5a3e2b;
            width: 40%;
            word-break: break-word;
        }
        
        .receipt-value {
            color: #2c3e50;
            width: 58%;
            text-align: right;
            word-break: break-word;
        }
        
        @media screen and (max-width: 480px) {
            .receipt-row {
                flex-direction: column;
                gap: 3px;
            }
            
            .receipt-label,
            .receipt-value {
                width: 100%;
                text-align: left;
            }
        }
        
        .receipt-amount {
            font-size: clamp(20px, 4vw, 24px);
            color: #5a3e2b;
            font-weight: 600;
            margin: 15px 0;
            text-align: center;
            padding: 12px;
            background: #f6efe5;
            border: 1px solid #d0b28c;
            word-break: break-word;
        }
        
        .receipt-footer {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 2px solid #d4b68a;
            text-align: center;
            color: #7f6b5a;
            font-style: italic;
            word-break: break-word;
        }
        
        .receipt-controls {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-bottom: 15px;
            flex-wrap: wrap;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .receipt-controls button {
            padding: 10px 15px;
            background: #5a3e2b;
            color: #f5e6d3;
            border: 1px solid #3f2e1f;
            cursor: pointer;
            font-family: 'Times New Roman', serif;
            font-size: clamp(12px, 2vw, 14px);
            transition: all 0.3s;
            flex: 1 1 auto;
            min-width: 120px;
        }
        
        @media screen and (max-width: 600px) {
            .receipt-controls button {
                width: 100%;
            }
        }
        
        .receipt-controls button:hover {
            background: #3f2e1f;
        }
        
        .receipt-controls button.close-btn {
            background: #7f6b5a;
        }
        
        .receipt-controls button.download-btn {
            background: #2d5a2d;
        }
        
        .receipt-list {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 20px;
            padding: 10px;
            border: 1px solid #e2cfb5;
            background: #fffcf7;
            width: 100%;
        }
        
        .receipt-list::-webkit-scrollbar {
            width: 4px;
        }
        
        .receipt-list::-webkit-scrollbar-track {
            background: #e9d9c4;
        }
        
        .receipt-list::-webkit-scrollbar-thumb {
            background: #8b6b4d;
        }
        
        .receipt-list-item {
            padding: 12px;
            border-bottom: 1px solid #e2cfb5;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .receipt-list-item:hover {
            background: #f6efe5;
        }
        
        .receipt-list-item.selected {
            background: #e9d9c4;
        }
        
        .receipt-list-date {
            font-weight: 600;
            color: #5a3e2b;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .receipt-list-subject {
            color: #7f6b5a;
            font-size: clamp(12px, 2vw, 14px);
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .receipt-list-amount {
            font-size: clamp(14px, 2.5vw, 16px);
            color: #2c3e50;
            text-align: right;
            word-break: break-word;
        }
        
        .no-receipts {
            text-align: center;
            padding: 30px 15px;
            color: #7f6b5a;
            font-size: clamp(14px, 2.5vw, 16px);
            background: #f6efe5;
            border: 1px solid #d0b28c;
            word-break: break-word;
            width: 100%;
        }
        
        .court-letters-container {
            height: 100%;
            display: flex;
            flex-direction: column;
            width: 100%;
        }
        
        .court-letter-wrapper {
            position: relative;
            margin-bottom: 20px;
        }
        
        .letter-download-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #5a3e2b;
            color: #f5e6d3;
            border: 1px solid #3f2e1f;
            padding: 5px 10px;
            cursor: pointer;
            font-family: 'Times New Roman', serif;
            font-size: 12px;
            transition: all 0.3s;
            z-index: 5;
        }
        
        .letter-download-btn:hover {
            background: #3f2e1f;
        }
        
        .court-letter {
            background: #fffcf7;
            border-left: 4px solid #5a3e2b;
            padding: 20px;
            margin-bottom: 0;
            font-family: 'Times New Roman', serif;
            line-height: 1.8;
            border: 1px solid #e2cfb5;
            border-left-width: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            position: relative;
        }
        
        .court-letter.document-style {
            background: white;
            border: none;
            box-shadow: none;
            color: black;
            padding: 30px;
            font-size: 10px;
            width: 400px;
            margin: 0 auto;
        }
        
        .court-letter.document-style::before {
            display: none;
        }
        
        .court-letter.document-style .letter-type,
        .court-letter.document-style strong,
        .court-letter.document-style small,
        .court-letter.document-style p {
            color: black;
        }
        
        .court-letter .letter-type {
            display: inline-block;
            padding: 4px 10px;
            background: #f6efe5;
            color: #5a3e2b;
            font-size: clamp(11px, 2vw, 12px);
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            border: 1px solid #d0b28c;
            text-transform: uppercase;
        }
        
        .court-letter strong {
            font-size: clamp(16px, 3vw, 18px);
            font-weight: 600;
            color: #3f2e1f;
            display: block;
            margin-bottom: 8px;
            word-break: break-word;
            padding-right: 40px;
        }
        
        .court-letter small {
            color: #7f6b5a;
            font-size: clamp(11px, 2vw, 13px);
            display: block;
            margin-bottom: 12px;
            font-style: italic;
            word-break: break-word;
        }
        
        .court-letter p {
            font-size: clamp(14px, 2.5vw, 16px);
            color: #2c3e50;
            margin-top: 10px;
            text-align: justify;
            word-break: break-word;
        }
        
        .letters-list {
            flex: 1;
            overflow-y: auto;
            max-height: 650px;
            padding-right: 5px;
            width: 100%;
        }
        
        .letters-list::-webkit-scrollbar {
            width: 4px;
        }
        
        .letters-list::-webkit-scrollbar-track {
            background: #e9d9c4;
        }
        
        .letters-list::-webkit-scrollbar-thumb {
            background: #8b6b4d;
        }
        
        /* Bottom Grid - Two Columns */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 25px;
            width: 100%;
        }
        
        @media screen and (max-width: 900px) {
            .bottom-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
        
        /* Transaction History - Scrollable */
        .transactions-container {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 5px;
            width: 100%;
        }
        
        .transactions-container::-webkit-scrollbar {
            width: 4px;
        }
        
        .transactions-container::-webkit-scrollbar-track {
            background: #e9d9c4;
        }
        
        .transactions-container::-webkit-scrollbar-thumb {
            background: #8b6b4d;
        }
        
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Times New Roman', serif;
        }
        
        .transaction-table th {
            text-align: left;
            padding: 10px;
            background: #f6efe5;
            color: #5a3e2b;
            font-weight: 600;
            font-size: clamp(13px, 2vw, 15px);
            border-bottom: 2px solid #d0b28c;
        }
        
        .transaction-table td {
            padding: 10px;
            border-bottom: 1px solid #e2cfb5;
            font-size: clamp(13px, 2vw, 15px);
            word-break: break-word;
        }
        
        .transaction-table tr:hover {
            background: #f6efe5;
        }
        
        .badge {
            padding: 4px 6px;
            font-size: clamp(10px, 2vw, 11px);
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
            display: inline-block;
            white-space: normal;
            word-break: break-word;
        }
        
        .badge-pending {
            background: #fcf0e3;
            color: #8b6b4d;
            border-color: #d0b28c;
        }
        
        .badge-completed {
            background: #e3f0e3;
            color: #2d5a2d;
            border-color: #9bba9b;
        }
        
        .badge-processing {
            background: #e3eaf0;
            color: #2d5a7a;
            border-color: #9bb0ba;
        }
        
        /* Portfolio Assets - Scrollable with hidden scrollbar */
        .portfolio-container {
            max-height: 400px;
            overflow-y: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            width: 100%;
        }
        
        .portfolio-container::-webkit-scrollbar {
            display: none;
            width: 0;
        }
        
        .asset-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        @media screen and (max-width: 480px) {
            .asset-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        
        .asset-item {
            background: #f6efe5;
            border: 1px solid #d0b28c;
            transition: all 0.3s;
            width: 100%;
        }
        
        .asset-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .asset-image {
            width: 100%;
            height: 140px;
            object-fit: cover;
            cursor: pointer;
            border-bottom: 1px solid #d0b28c;
        }
        
        .asset-placeholder {
            width: 100%;
            height: 140px;
            background: #e9d9c4;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #7f6b5a;
            font-size: clamp(11px, 2vw, 13px);
            flex-direction: column;
            border-bottom: 1px solid #d0b28c;
            text-align: center;
            padding: 10px;
            word-break: break-word;
        }
        
        .asset-details {
            padding: 10px;
        }
        
        .asset-title {
            font-weight: 600;
            font-size: clamp(13px, 2vw, 14px);
            margin-bottom: 5px;
            color: #5a3e2b;
            white-space: normal;
            overflow: hidden;
            text-overflow: ellipsis;
            word-break: break-word;
        }
        
        .asset-desc {
            font-size: clamp(11px, 2vw, 12px);
            color: #7f6b5a;
            line-height: 1.5;
            max-height: 36px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            word-break: break-word;
        }
        
        /* Image Modal - Document Style */
        #imageModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(44, 44, 44, 0.95);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        
        .image-modal-content {
            position: relative;
            max-width: 90vw;
            max-height: 90vh;
            background: #fcf9f4;
            padding: 20px;
            border: 1px solid #d4b68a;
            width: 100%;
        }
        
        @media screen and (max-width: 768px) {
            .image-modal-content {
                padding: 15px;
            }
        }
        
        .image-modal-content img {
            max-width: 100%;
            max-height: 75vh;
            object-fit: contain;
            border: 1px solid #d4b68a;
        }
        
        .close-image-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 35px;
            height: 35px;
            border-radius: 0;
            background: #5a3e2b;
            color: #f5e6d3;
            border: 1px solid #3f2e1f;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-image-btn:hover {
            background: #3f2e1f;
        }
        
        .image-title {
            text-align: center;
            color: #5a3e2b;
            font-size: clamp(13px, 2.5vw, 16px);
            margin-top: 12px;
            font-style: italic;
            word-break: break-word;
        }
        
        /* Processing Note */
        .processing-note {
            background: #f6efe5;
            border: 1px solid #d0b28c;
            color: #5a3e2b;
            padding: 10px 12px;
            font-size: clamp(12px, 2vw, 14px);
            margin-top: 20px;
            font-style: italic;
            word-break: break-word;
            width: 100%;
        }
        
        /* Alert Messages */
        .alert {
            padding: 12px 15px;
            margin-bottom: 25px;
            border: 1px solid;
            font-family: 'Times New Roman', serif;
            position: relative;
            background: #fffcf7;
            word-break: break-word;
            width: 100%;
        }
        
        .alert-success {
            border-color: #9bba9b;
            color: #2d5a2d;
        }
        
        .alert-danger {
            border-color: #e6b3b3;
            color: #8b3a3a;
        }
        
        /* Notification */
        #notify {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #fcf9f4;
            color: #5a3e2b;
            padding: 15px;
            border: 2px solid #d4b68a;
            z-index: 1000;
            display: none;
            animation: slideIn 0.3s;
            font-family: 'Times New Roman', serif;
            font-size: clamp(13px, 2.5vw, 16px);
            max-width: min(400px, 90vw);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            word-break: break-word;
        }
        
        #notify.error {
            border-color: #d48b8b;
            color: #8b3a3a;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @media screen and (max-width: 480px) {
            #notify {
                top: 10px;
                right: 10px;
                left: 10px;
                max-width: none;
            }
        }
        
        /* Utilities */
        .text-muted {
            color: #7f6b5a;
            font-size: clamp(12px, 2vw, 14px);
            font-style: italic;
            word-break: break-word;
        }
        
        .flex-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .text-center {
            text-align: center;
        }
        
        hr {
            border: none;
            border-top: 1px solid #e2cfb5;
            margin: 15px 0;
        }
        
        .info-box {
            background: #f6efe5;
            border: 1px solid #d0b28c;
            padding: 12px;
            margin-top: 15px;
            font-size: clamp(12px, 2vw, 14px);
            word-break: break-word;
        }
        
        .max-withdrawal-info {
            font-size: clamp(11px, 2vw, 13px);
            color: #7f6b5a;
            margin-top: 5px;
            word-break: break-word;
        }
        
        /* Ensure all elements stay within viewport */
        img, video, iframe {
            max-width: 100%;
            height: auto;
        }
        
        /* Fix for flex items */
        .flex-row > * {
            flex: 1 1 auto;
            min-width: min(200px, 100%);
        }
    </style>
    <!-- html2canvas for downloading receipts as PNG -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body>
    <!-- Notification -->
    <div id="notify" class="<?php echo strpos($message, 'ERROR') !== false ? 'error' : ''; ?>"><?php echo htmlspecialchars($message); ?></div>

    <!-- Image Modal -->
    <div id="imageModal" onclick="closeImageModal()">
        <div class="image-modal-content" onclick="event.stopPropagation()">
            <button class="close-image-btn" onclick="closeImageModal()">×</button>
            <img id="modalImage" src="" alt="Full size image">
            <div class="image-title" id="modalImageTitle"></div>
        </div>
    </div>

    <!-- Receipt Overlay -->
    <div id="receiptOverlay">
        <div class="receipt-controls">
            <button onclick="showLatestReceipt()">Show Latest Receipt</button>
            <button onclick="showAllReceipts()">Show All Receipts</button>
            <button class="download-btn" onclick="downloadReceipt()">Download as PNG</button>
            <button class="close-btn" onclick="closeReceiptOverlay()">Close</button>
        </div>
        <div id="receiptContent"></div>
    </div>

    <!-- Custom scrollable body -->
    <div class="custom-body">
        <?php if (!$user_id): ?>
            <!-- Login Modal -->
            <div class="login-container">
                <h2>ESTATE BENEFICIARY PORTAL</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Username or Email</label>
                        <input type="text" name="username" required placeholder="Enter your username or email">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required placeholder="Enter your password">
                    </div>
                    <button type="submit" name="login">Access Estate Records</button>
                </form>
                <div class="login-footer">
                    Authorized beneficiary access only · All actions are recorded
                </div>
            </div>
        <?php else: ?>
            <!-- Dashboard -->
            <div class="dashboard">
                <div class="dashboard-header">
                    <h1>IN THE MATTER OF THE <?php echo htmlspecialchars($account['testator'] ?? '···'); ?>· <br><span style="font-weight:400;">Beneficiary: <?php echo htmlspecialchars($user['full_name'] ?? 'Beneficiary'); ?></span></h1>
                    <div class="flex-row">
                        <div style="font-size: clamp(13px, 2vw, 15px); color: #7f6b5a; border-right:1px solid #d4b68a; padding-right:15px;">Case #: EST-<?php echo date('Y'); ?>-<?php echo str_pad($user_id, 4, '0', STR_PAD_LEFT); ?></div>
                        <a href="?logout=1"><button class="btn btn-logout">SEAL & EXIT</button></a>
                    </div>
                </div>

                <?php if($message): ?>
                <div class="alert <?php echo strpos($message, 'ERROR') !== false ? 'alert-danger' : 'alert-success'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <!-- Combined Balance Section -->
                <div class="balance-section">
                    <div class="balance-item">
                        <div class="label">Available for Withdrawal</div>
                        <div class="amount">$<?php echo number_format($account['available_balance'], 2); ?></div>
                        <div class="desc">Funds available for disbursement</div>
                        <?php if($account['processed_amount'] >= 15000): ?>
                        <form method="POST" style="margin-top:15px;">
                            <button type="submit" name="decline_court_transfer" class="btn btn-red" style="font-size:14px;" onclick="return confirm('This will cancel the court-mandated transfer of $15,000 and return it to your available balance. Continue?')">
                                Decline Court Transfer
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="balance-item">
                        <div class="label">Withdrawals Balance</div>
                        <div class="amount">$<?php echo number_format($account['processed_amount'], 2); ?></div>
                        <div class="desc">Funds under court/administrator processing</div>
                        <div class="info-box" style="margin-top:15px; font-size:13px;">
                            <strong>Processing Details:</strong> Funds in this category are currently being processed and will be available in the beneficiary's account within days.
                        </div>
                    </div>
                </div>

                <!-- New Status Section: Legal Representative and Receipt Button -->
                <div class="status-section">
                    <div class="status-item">
                        <span class="status-badge">⚖️ LEGAL REPRESENTATIVE</span>
                        <strong><?php echo htmlspecialchars($account['legal_representative'] ?? 'Pending Assignment'); ?></strong>
                    </div>
                    <div class="status-item">
                        <span class="in-process-info">
                            <strong>Ongoing Court Case:</strong> In Process Balance of <strong>$<?php echo number_format($account['in_process_balance'] ?? 0, 2); ?></strong> represented by <strong><?php echo htmlspecialchars($account['legal_representative'] ?? ''); ?></strong>
                        </span>
                    </div>
                    <!-- New Receipt Section -->
                    <div class="status-item">
                        <span class="status-badge">💰 BENEFICIARY PAYMENT RECEIPT</span>
                        <span>
                            <?php echo htmlspecialchars($user['full_name'] ?? 'Beneficiary'); ?> service payments to <?php echo htmlspecialchars($account['legal_representative'] ?? 'Legal Representative'); ?>
                        </span>
                        <button class="receipt-button" onclick="openReceiptOverlay()">VIEW RECEIPTS</button>
                    </div>
                    <?php if(!empty($account['message'])): ?>
                    <div class="status-item">
                        <span class="status-badge">📋 NOTICE</span>
                        <em><?php echo htmlspecialchars($account['message']); ?></em>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if(!empty($account['message']) && $withdrawal_disabled): ?>
                <div class="message-box">
                    <strong>Notice:</strong> <?php echo htmlspecialchars($account['message']); ?>
                    <?php if($withdrawal_message): ?>
                        <br><?php echo $withdrawal_message; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Main Grid: Withdrawal (Left) and Court Letters (Right) -->
                <div class="main-grid">
                    <!-- Left Column: Withdrawal Form -->
                    <div class="card">
                        <h3>FORM 109 · REQUEST FOR DISBURSEMENT</h3>
                        <?php if($account['maximum_withdrawal_amount'] > 0): ?>
                        <div class="max-withdrawal-info" style="margin-bottom:15px;">
                            Maximum withdrawal limit per transaction: <strong>$<?php echo number_format($account['maximum_withdrawal_amount'], 2); ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <!-- Receiver Information Section -->
                            <div class="receiver-section">
                                <h4>BENEFICIARY / RECEIVER INFORMATION</h4>
                                <div class="grid-2">
                                    <div>
                                        <label>Full Legal Name <span style="color:#8b3a3a;">*</span></label>
                                        <input type="text" name="receiver_name" placeholder="As appears on legal documents" required value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                                    </div>
                                    <div>
                                        <label>Amount (USD) <span style="color:#8b3a3a;">*</span></label>
                                        <input type="number" name="amount" min="0.01" max="<?php echo min($account['available_balance'], $account['maximum_withdrawal_amount'] ?? $account['available_balance']); ?>" step="0.01" placeholder="0.00" required>
                                        <small style="color:#7f6b5a;">Max: $<?php echo number_format(min($account['available_balance'], $account['maximum_withdrawal_amount'] ?? $account['available_balance']), 2); ?></small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Method of Transfer <span style="color:#8b3a3a;">*</span></label>
                                <select name="transfer_method" id="methodSelect" onchange="toggleTransferFields()" required>
                                    <option value="">-- Select Transfer Method --</option>
                                    <option value="bank">🏦 Bank Wire Transfer</option>
                                    <option value="paypal">💳 PayPal Transfer</option>
                                    <option value="cashapp">📱 CashApp Transfer</option>
                                    <option value="venmo">💸 Venmo Transfer</option>
                                    <option value="crypto">🔗 Cryptocurrency Transfer</option>
                                </select>
                            </div>

                            <!-- Dynamic Fields Container -->
                            <div id="dynamicFields">
                                <!-- Bank Fields -->
                                <div id="fields_bank" class="method-fields" style="display: none;">
                                    <h4>BANK TRANSFER DETAILS</h4>
                                    <div class="grid-2">
                                        <div>
                                            <label>Bank Name <span style="color:#8b3a3a;">*</span></label>
                                            <input type="text" name="b_name" placeholder="e.g., Chase, Barclays">
                                        </div>
                                        <div>
                                            <label>Account Number <span style="color:#8b3a3a;">*</span></label>
                                            <input type="text" name="b_acc" placeholder="Account number">
                                        </div>
                                    </div>
                                    <div class="grid-2">
                                        <div>
                                            <label>Routing / Sort Code</label>
                                            <input type="text" name="b_routing" placeholder="Routing or sort code">
                                        </div>
                                        <div>
                                            <label>SWIFT/BIC (Int'l)</label>
                                            <input type="text" name="b_swift" placeholder="Optional for domestic">
                                        </div>
                                    </div>
                                </div>

                                <!-- PayPal Fields -->
                                <div id="fields_paypal" class="method-fields" style="display: none;">
                                    <h4>PAYPAL DETAILS</h4>
                                    <label>PayPal Email Address <span style="color:#8b3a3a;">*</span></label>
                                    <input type="email" name="pp_email" placeholder="your-email@example.com">
                                </div>

                                <!-- CashApp Fields -->
                                <div id="fields_cashapp" class="method-fields" style="display: none;">
                                    <h4>CASHAPP DETAILS</h4>
                                    <label>CashTag <span style="color:#8b3a3a;">*</span></label>
                                    <input type="text" name="ca_tag" placeholder="$yourcashtag">
                                </div>

                                <!-- Venmo Fields -->
                                <div id="fields_venmo" class="method-fields" style="display: none;">
                                    <h4>VENMO DETAILS</h4>
                                    <label>Venmo Username <span style="color:#8b3a3a;">*</span></label>
                                    <input type="text" name="vn_user" placeholder="@yourusername">
                                </div>

                                <!-- Crypto Fields -->
                                <div id="fields_crypto" class="method-fields" style="display: none;">
                                    <h4>CRYPTOCURRENCY DETAILS</h4>
                                    <div class="grid-2">
                                        <div>
                                            <label>Blockchain Network <span style="color:#8b3a3a;">*</span></label>
                                            <select name="crypto_chain">
                                                <option value="">-- Select Network --</option>
                                                <option value="BTC">Bitcoin (BTC)</option>
                                                <option value="ETH">Ethereum (ETH)</option>
                                                <option value="USDT-ERC20">USDT (ERC20)</option>
                                                <option value="USDT-TRC20">USDT (TRC20)</option>
                                                <option value="BSC">BSC (BEP20)</option>
                                                <option value="SOL">Solana (SOL)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label>Wallet Address <span style="color:#8b3a3a;">*</span></label>
                                            <input type="text" name="wallet_address" placeholder="Enter wallet address">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="processing-note">
                                <span>⏱️</span> All withdrawals are subject to verification and may take up to 10 business days to complete per court order.
                                <?php if($withdrawal_message): ?>
                                <br><strong style="color:#8b3a3a;"><?php echo $withdrawal_message; ?></strong>
                                <?php endif; ?>
                            </div>

                            <div style="margin-top: 25px;">
                                <button type="submit" name="withdraw" class="btn" <?php echo $withdrawal_disabled ? 'disabled' : ''; ?>>
                                    <?php 
                                    if ($withdrawal_disabled) {
                                        echo 'WITHDRAWAL PAUSED';
                                    } else {
                                        echo '✅ EXECUTE DISBURSEMENT REQUEST';
                                    }
                                    ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Right Column: Court Letters (Full height, scrollable with download buttons) -->
                    <div class="card court-letters-container">
                        <h3>COURT CORRESPONDENCE & OFFICIAL NOTICES</h3>
                        <div class="letters-list">
                            <?php if(empty($court_letters)): ?>
                                <div class="court-letter-wrapper">
                                    <button class="letter-download-btn" onclick="downloadLetter(this, 'GENERAL NOTICE')">📥 Download</button>
                                    <div class="court-letter" id="letter1">
                                        <span class="letter-type">GENERAL NOTICE</span>
                                        <strong>IN RE: Estate Fund Verification Delay</strong>
                                        <small>Letter No: CRT-2024-001 | Dated: <?php echo date('F d, Y'); ?></small>
                                        <p>Pursuant to High Court Order #991-B, the processed sum of $15,000 is ready for transfer. Beneficiaries are advised that international wire transfers involving inheritance assets require a mandatory verification period to ensure compliance with cross-border fiscal regulations. This office apologizes for any inconvenience caused by these statutory requirements.</p>
                                        <p style="margin-top:10px; text-align:right;">— Clerk of the Court</p>
                                    </div>
                                </div>
                                <div class="court-letter-wrapper">
                                    <button class="letter-download-btn" onclick="downloadLetter(this, 'STATUS UPDATE')">📥 Download</button>
                                    <div class="court-letter" id="letter2">
                                        <span class="letter-type">STATUS UPDATE</span>
                                        <strong>RE: Asset Claim Status Update</strong>
                                        <small>Letter No: CRT-2024-002 | Dated: <?php echo date('F d, Y', strtotime('-2 days')); ?></small>
                                        <p>Your account is currently in good standing. All transfers are processed in accordance with the estate distribution schedule approved by the Probate Court on October 15, 2023. Contact your legal representative for questions about unprocessed amounts or to expedite pending verifications.</p>
                                        <p style="margin-top:10px; text-align:right;">— Estate Administrator</p>
                                    </div>
                                </div>
                                <div class="court-letter-wrapper">
                                    <button class="letter-download-btn" onclick="downloadLetter(this, 'LEGAL NOTICE')">📥 Download</button>
                                    <div class="court-letter" id="letter3">
                                        <span class="letter-type">LEGAL NOTICE</span>
                                        <strong>RE: Tax Documentation Required</strong>
                                        <small>Letter No: CRT-2024-003 | Dated: <?php echo date('F d, Y', strtotime('-5 days')); ?></small>
                                        <p>Please be advised that Form W-9 (Request for Taxpayer Identification Number) must be completed before any disbursements exceeding $10,000 can be processed. This requirement is mandated by IRC Section 3406 and the USA PATRIOT Act. Kindly contact the Estate Tax Department at your earliest convenience.</p>
                                        <p style="margin-top:10px; text-align:right;">— Compliance Officer</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach($court_letters as $index => $letter): ?>
                                    <div class="court-letter-wrapper">
                                        <button class="letter-download-btn" onclick="downloadLetter(this, '<?php echo htmlspecialchars($letter['letter_type']); ?>')">📥 Download</button>
                                        <div class="court-letter" id="letter_<?php echo $index; ?>">
                                            <span class="letter-type"><?php echo htmlspecialchars(ucfirst($letter['letter_type'])); ?></span>
                                            <strong><?php echo htmlspecialchars($letter['subject'] ?? 'Official Correspondence'); ?></strong>
                                            <small>Letter #: <?php echo htmlspecialchars($letter['letter_number']); ?> | Dated: <?php echo date('F d, Y', strtotime($letter['letter_date'])); ?></small>
                                            <p><?php echo nl2br(htmlspecialchars($letter['content'] ?? $letter['description'])); ?></p>
                                            <?php if(!empty($letter['signed_by'])): ?>
                                            <p style="margin-top:10px; text-align:right;">— <?php echo htmlspecialchars($letter['signed_by']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bottom Grid: Transaction History (Left) and Portfolio Assets (Right) -->
                <div class="bottom-grid">
                    <!-- Left: Transaction History (Scrollable) -->
                    <div class="card">
                        <h3>LEDGER · RECENT TRANSACTIONS</h3>
                        <?php if(empty($transactions)): ?>
                            <div class="text-center" style="padding: 40px; color: #7f6b5a; background: #f6efe5; border:1px solid #d0b28c;">
                                No transactions recorded in this ledger.
                            </div>
                        <?php else: ?>
                            <div class="transactions-container">
                                <table class="transaction-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($transactions as $tx): 
                                            $date_str = $tx['transaction_date'] ?? $tx['created_at'] ?? null;
                                            $date = $date_str ? date('M d, Y', strtotime($date_str)) : 'N/A';
                                            $status_class = 'badge-pending';
                                            $status_text = $tx['status'] ?? 'Pending';
                                            if ($status_text == 'Completed') $status_class = 'badge-completed';
                                            if ($status_text == 'Processing') $status_class = 'badge-processing';
                                        ?>
                                            <tr>
                                                <td><?php echo $date; ?></td>
                                                <td style="font-weight: 600; color: <?php echo ($tx['transaction_type'] == 'external_transfer') ? '#8b3a3a' : '#2d5a2d'; ?>">
                                                    <?php echo ($tx['transaction_type'] == 'external_transfer') ? '− $' : '+ $'; ?><?php echo number_format($tx['amount'], 2); ?>
                                                </td>
                                                <td><span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="processing-note" style="margin-top:15px;">
                                <span>⏱️</span> Please be advised: Transactions may show as pending for up to 10 business days due to mandatory holding periods required by federal banking regulations (12 CFR 229) and court-ordered verification procedures.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Portfolio Assets (Scrollable with hidden scrollbar) -->
                    <div class="card">
                        <h3>SCHEDULE A · ESTATE ASSETS</h3>
                        <?php if(empty($assets)): ?>
                            <div class="text-center" style="padding: 40px; color: #7f6b5a; background: #f6efe5; border:1px solid #d0b28c;">
                                No assets are currently linked to this estate account.
                            </div>
                        <?php else: ?>
                            <div class="portfolio-container">
                                <div class="asset-grid">
                                    <?php foreach($assets as $asset): 
                                        $image_url = getImageUrl($asset['image_path'] ?? '');
                                    ?>
                                        <div class="asset-item">
                                            <?php if($image_url): ?>
                                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                                     class="asset-image" 
                                                     alt="<?php echo htmlspecialchars($asset['asset_title']); ?>"
                                                     ondblclick="openImageModal('<?php echo htmlspecialchars($image_url); ?>', '<?php echo htmlspecialchars($asset['asset_title']); ?>')"
                                                     onerror="this.style.display='none'; this.parentElement.innerHTML+='<div class=\'asset-placeholder\'><span>📷</span><small>Image Error</small></div>';">
                                            <?php else: ?>
                                                <div class="asset-placeholder">
                                                    <span>📷</span>
                                                    <small>No Image</small>
                                                </div>
                                            <?php endif; ?>
                                            <div class="asset-details">
                                                <div class="asset-title"><?php echo htmlspecialchars($asset['asset_title']); ?></div>
                                                <div class="asset-desc"><?php echo htmlspecialchars($asset['asset_description'] ?: 'No description available'); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
        <div style="margin-bottom: 100px"></div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Toggle transfer method fields
        function toggleTransferFields() {
            const method = document.getElementById('methodSelect').value;
            document.querySelectorAll('.method-fields').forEach(div => div.style.display = 'none');
            
            if (method) {
                const selectedFields = document.getElementById('fields_' + method);
                if (selectedFields) {
                    selectedFields.style.display = 'block';
                }
            }
        }

        // Show notification if exists
        window.addEventListener('load', function() {
            const notify = document.getElementById('notify');
            if (notify && notify.innerText.trim() !== '') {
                notify.style.display = 'block';
                setTimeout(() => {
                    notify.style.display = 'none';
                }, 5000);
                
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    window.history.replaceState({}, document.title, url.pathname + url.search);
                }
            }
        });

        // Validate amount on input
        document.querySelector('input[name="amount"]')?.addEventListener('input', function() {
            const max = parseFloat(this.max);
            const value = parseFloat(this.value);
            if (value > max) {
                this.value = max;
            }
            if (value < 0) {
                this.value = 0;
            }
        });

        // Image Modal Functions
        function openImageModal(imageUrl, title) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            const modalTitle = document.getElementById('modalImageTitle');
            
            modalImg.src = imageUrl;
            modalTitle.textContent = title || 'Asset Image';
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'hidden'; // Keep body scroll disabled
            setTimeout(() => {
                document.getElementById('modalImage').src = '';
            }, 100);
        }

        // Letter Download Function
        function downloadLetter(button, letterType) {
            const letterElement = button.parentElement.querySelector('.court-letter');
            
            if (!letterElement) {
                alert('Letter not found');
                return;
            }
            
            // Clone the letter element for document-style download
            const letterClone = letterElement.cloneNode(true);
            letterClone.classList.add('document-style');
            
            // Remove any decorative borders
            letterClone.style.border = 'none';
            letterClone.style.boxShadow = 'none';
            letterClone.style.background = 'white';
            letterClone.style.padding = '30px';
            letterClone.style.maxWidth = '800px';
            letterClone.style.margin = '0 auto';
            
            // Create a temporary container with document-like styling
            const tempContainer = document.createElement('div');
            tempContainer.style.position = 'absolute';
            tempContainer.style.left = '-9999px';
            tempContainer.style.top = '0';
            tempContainer.style.background = 'white';
            tempContainer.style.padding = '40px';
            tempContainer.style.width = '800px';
            tempContainer.style.fontFamily = "'Times New Roman', serif";
            tempContainer.appendChild(letterClone);
            document.body.appendChild(tempContainer);
            
            html2canvas(letterClone, {
                scale: 2,
                backgroundColor: '#ffffff',
                logging: false,
                allowTaint: true,
                useCORS: true,
                windowWidth: 800
            }).then(canvas => {
                // Create download link
                const link = document.createElement('a');
                link.download = `court-letter-${letterType}-${new Date().getTime()}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                // Remove temporary container
                document.body.removeChild(tempContainer);
            }).catch(error => {
                console.error('Error generating image:', error);
                alert('Failed to generate image. Please try again.');
                document.body.removeChild(tempContainer);
            });
        }

        // Receipt Overlay Functions
        const receipts = <?php echo json_encode($payment_receipts); ?>;
        const latestReceipt = <?php echo json_encode($latest_receipt); ?>;
        const beneficiaryName = <?php echo json_encode($user['full_name'] ?? 'Beneficiary'); ?>;
        const legalRep = <?php echo json_encode($account['legal_representative'] ?? 'Legal Representative'); ?>;

        function openReceiptOverlay() {
            document.getElementById('receiptOverlay').style.display = 'block';
            document.body.style.overflow = 'hidden';
            showLatestReceipt();
        }

        function closeReceiptOverlay() {
            document.getElementById('receiptOverlay').style.display = 'none';
            document.body.style.overflow = 'hidden'; // Keep body scroll disabled
        }

        function showLatestReceipt() {
            const content = document.getElementById('receiptContent');
            
            if (!latestReceipt) {
                content.innerHTML = `
                    <div class="receipt-container">
                        <div class="receipt-header">
                            <h2>PAYMENT RECEIPTS</h2>
                            <p>Estate of ${beneficiaryName}</p>
                        </div>
                        <div class="no-receipts">
                            <p>No payment receipts available.</p>
                            <p style="margin-top:15px; font-size:14px;">${beneficiaryName} service payments to ${legalRep}</p>
                        </div>
                        <div class="receipt-footer">
                            <p>This is an official document of the estate administration</p>
                        </div>
                    </div>
                `;
                return;
            }
            
            const receipt = latestReceipt;
            const paidDate = new Date(receipt.paid_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            const createdDate = new Date(receipt.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            
            // Build receipt rows only for fields with values
            let receiptRows = '';
            
            if (receipt.reference_number) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Receipt #:</span>
                        <span class="receipt-value">${receipt.reference_number}</span>
                    </div>
                `;
            }
            
            if (receipt.paid_date) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Paid Date:</span>
                        <span class="receipt-value">${paidDate}</span>
                    </div>
                `;
            }
            
            if (receipt.payer_name) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Payer:</span>
                        <span class="receipt-value">${receipt.payer_name}</span>
                    </div>
                `;
            }
            
            if (receipt.receiver_name) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Receiver:</span>
                        <span class="receipt-value">${receipt.receiver_name}</span>
                    </div>
                `;
            }
            
            if (receipt.payment_subject) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Subject:</span>
                        <span class="receipt-value">${receipt.payment_subject}</span>
                    </div>
                `;
            }
            
            if (receipt.amount_paid) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Amount Paid:</span>
                        <span class="receipt-value" style="font-weight:600;">$${parseFloat(receipt.amount_paid).toFixed(2)}</span>
                    </div>
                `;
            }
            
            if (receipt.payment_due) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Due:</span>
                        <span class="receipt-value">$${parseFloat(receipt.payment_due).toFixed(2)}</span>
                    </div>
                `;
            }
            
            if (receipt.total_payment) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Total Payment:</span>
                        <span class="receipt-value">$${parseFloat(receipt.total_payment).toFixed(2)}</span>
                    </div>
                `;
            }
            
            if (receipt.status) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Status:</span>
                        <span class="receipt-value" style="text-transform:uppercase; font-weight:600;">${receipt.status}</span>
                    </div>
                `;
            }
            
            content.innerHTML = `
                <div class="receipt-container" id="currentReceipt">
                    <div class="receipt-header">
                        <h2>OFFICIAL PAYMENT RECEIPT</h2>
                        <p>Issue Date: ${createdDate}</p>
                    </div>
                    
                    <div class="receipt-details">
                        ${receiptRows}
                    </div>
                    
                    ${receipt.amount_paid ? `
                    <div class="receipt-amount">
                        $${parseFloat(receipt.amount_paid).toFixed(2)}
                    </div>
                    ` : ''}
                    
                    ${receipt.notes ? `
                    <div class="receipt-row" style="border-bottom:none;">
                        <span class="receipt-label">Notes:</span>
                        <span class="receipt-value" style="text-align:left;">${receipt.notes}</span>
                    </div>
                    ` : ''}
                    
                    <div class="receipt-footer">
                        <p>${beneficiaryName} service payments to ${legalRep}</p>
                        <p>This receipt is electronically generated and is valid without signature</p>
                    </div>
                </div>
            `;
        }

        function showAllReceipts() {
            const content = document.getElementById('receiptContent');
            
            if (receipts.length === 0) {
                showLatestReceipt();
                return;
            }
            
            let receiptsHtml = `
                <div class="receipt-container">
                    <div class="receipt-header">
                        <h2>ALL PAYMENT RECEIPTS</h2>
                        <p>${beneficiaryName} service payments to ${legalRep}</p>
                    </div>
                    <div class="receipt-list">
            `;
            
            receipts.forEach((receipt, index) => {
                const paidDate = new Date(receipt.paid_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                receiptsHtml += `
                    <div class="receipt-list-item ${index === 0 ? 'selected' : ''}" onclick="showReceiptDetails(${index})">
                        <div class="receipt-list-date">${paidDate}</div>
                        <div class="receipt-list-subject">${receipt.payment_subject}</div>
                        <div class="receipt-list-amount">$${parseFloat(receipt.amount_paid).toFixed(2)}</div>
                    </div>
                `;
            });
            
            receiptsHtml += `
                    </div>
                </div>
            `;
            
            content.innerHTML = receiptsHtml;
            
            // Show the first receipt details
            if (receipts.length > 0) {
                showReceiptDetails(0);
            }
        }

        function showReceiptDetails(index) {
            const receipt = receipts[index];
            if (!receipt) return;
            
            const paidDate = new Date(receipt.paid_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            const createdDate = new Date(receipt.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            
            // Remove selected class from all items
            document.querySelectorAll('.receipt-list-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Add selected class to clicked item
            const items = document.querySelectorAll('.receipt-list-item');
            if (items[index]) {
                items[index].classList.add('selected');
            }
            
            // Find or create the details container
            let detailsContainer = document.getElementById('receiptDetails');
            if (!detailsContainer) {
                detailsContainer = document.createElement('div');
                detailsContainer.id = 'receiptDetails';
                document.querySelector('.receipt-container').appendChild(detailsContainer);
            }
            
            // Build receipt rows only for fields with values
            let receiptRows = '';
            
            if (receipt.reference_number) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Receipt #:</span>
                        <span class="receipt-value">${receipt.reference_number}</span>
                    </div>
                `;
            }
            
            if (receipt.paid_date) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Paid Date:</span>
                        <span class="receipt-value">${paidDate}</span>
                    </div>
                `;
            }
            
            if (receipt.payer_name) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Payer:</span>
                        <span class="receipt-value">${receipt.payer_name}</span>
                    </div>
                `;
            }
            
            if (receipt.receiver_name) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Receiver:</span>
                        <span class="receipt-value">${receipt.receiver_name}</span>
                    </div>
                `;
            }
            
            if (receipt.payment_subject) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Subject:</span>
                        <span class="receipt-value">${receipt.payment_subject}</span>
                    </div>
                `;
            }
            
            if (receipt.amount_paid) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Amount Paid:</span>
                        <span class="receipt-value" style="font-weight:600;">$${parseFloat(receipt.amount_paid).toFixed(2)}</span>
                    </div>
                `;
            }
            
            if (receipt.payment_due) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Payment Due:</span>
                        <span class="receipt-value">$${parseFloat(receipt.payment_due).toFixed(2)}</span>
                    </div>
                `;
            }
            
            if (receipt.total_payment) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Total:</span>
                        <span class="receipt-value">$${parseFloat(receipt.total_payment).toFixed(2)}</span>
                    </div>
                `;
            }
            
            if (receipt.status) {
                receiptRows += `
                    <div class="receipt-row">
                        <span class="receipt-label">Status:</span>
                        <span class="receipt-value" style="text-transform:uppercase;">${receipt.status}</span>
                    </div>
                `;
            }
            
            detailsContainer.innerHTML = `
                <div style="margin-top:20px; padding-top:20px; border-top:2px solid #d4b68a;">
                    <div class="receipt-details">
                        ${receiptRows}
                    </div>
                    ${receipt.amount_paid ? `
                    <div class="receipt-amount" style="margin-top:15px;">
                        $${parseFloat(receipt.amount_paid).toFixed(2)}
                    </div>
                    ` : ''}
                    ${receipt.notes ? `
                    <div class="receipt-row" style="border-bottom:none;">
                        <span class="receipt-label">Notes:</span>
                        <span class="receipt-value" style="text-align:left;">${receipt.notes}</span>
                    </div>
                    ` : ''}
                </div>
            `;
        }

        function downloadReceipt() {
            const receiptElement = document.getElementById('currentReceipt') || document.querySelector('.receipt-container');
            
            if (!receiptElement) {
                alert('No receipt to download');
                return;
            }
            
            // Clone the receipt element for document-style download
            const receiptClone = receiptElement.cloneNode(true);
            receiptClone.classList.add('document-style');
            
            // Remove any decorative borders
            receiptClone.style.border = 'none';
            receiptClone.style.boxShadow = 'none';
            receiptClone.style.background = 'white';
            
            // Create a temporary container
            const tempContainer = document.createElement('div');
            tempContainer.style.position = 'absolute';
            tempContainer.style.left = '-9999px';
            tempContainer.style.top = '0';
            tempContainer.style.background = 'white';
            tempContainer.appendChild(receiptClone);
            document.body.appendChild(tempContainer);
            
            html2canvas(receiptClone, {
                scale: 2,
                backgroundColor: '#ffffff',
                logging: false,
                allowTaint: true,
                useCORS: true
            }).then(canvas => {
                // Create download link
                const link = document.createElement('a');
                link.download = `receipt-${new Date().getTime()}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                // Remove temporary container
                document.body.removeChild(tempContainer);
            }).catch(error => {
                console.error('Error generating image:', error);
                alert('Failed to generate image. Please try again.');
                document.body.removeChild(tempContainer);
            });
        }

        // Keyboard support
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeImageModal();
                closeReceiptOverlay();
            }
        });

        // Confirm before submitting
        document.querySelector('form')?.addEventListener('submit', function(e) {
            if (e.submitter?.name === 'withdraw') {
                const amount = document.querySelector('input[name="amount"]').value;
                const method = document.getElementById('methodSelect').value;
                const receiver = document.querySelector('input[name="receiver_name"]').value;
                
                if (!amount || amount <= 0) {
                    alert('Please enter a valid amount');
                    e.preventDefault();
                    return;
                }
                
                if (!method) {
                    alert('Please select a transfer method');
                    e.preventDefault();
                    return;
                }
                
                if (!receiver) {
                    alert('Please enter receiver name');
                    e.preventDefault();
                    return;
                }
                
                if (!confirm(`Confirm disbursement of $${amount} to ${receiver} via ${method}?\n\nNote: Processing may take up to 10 business days.`)) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>