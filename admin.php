<?php
    require 'db.php';

    // --- PASSKEY AUTHENTICATION ---
    session_start();
    
    // Check if passkey is valid
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
            // Redirect to prevent form resubmission
            header("Location: " . $_SERVER['PHP_SELF'] . ($target_user ? "?uid=$target_user" : ""));
            exit();
        } else {
            $passkey_error = 'Invalid passkey';
        }
    }

    // Check authentication (30 minute timeout)
    $is_authenticated = isset($_SESSION['server_authenticated']) && 
                        $_SESSION['server_authenticated'] === true && 
                        (time() - $_SESSION['auth_time'] < 60);

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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .passkey-button:hover {
            opacity: 0.9;
        }
        
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

    // --- REDIRECT TO PREVENT FORM RESUBMISSION ON REFRESH ---
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['submit_passkey'])) {
        // Store POST data in session temporarily if needed
        // But for now, just redirect to GET to prevent resubmission
        $redirect_url = $_SERVER['PHP_SELF'] . "?";
        if ($target_user) {
            $redirect_url .= "uid=$target_user";
        }
        if (isset($_GET['edit_receipt'])) {
            $redirect_url .= "&edit_receipt=" . $_GET['edit_receipt'];
        }
        header("Location: $redirect_url");
        exit();
    }

    // --- HELPER FUNCTIONS ---
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

    // Robust image path resolver function
    function resolveImagePath($image_path) {
        if (empty($image_path)) {
            return null;
        }
        
        // If it's already a full URL, return as is
        if (filter_var($image_path, FILTER_VALIDATE_URL)) {
            return $image_path;
        }
        
        // Get the base paths
        $document_root = $_SERVER['DOCUMENT_ROOT'];
        $script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
        
        // Array of possible paths to check
        $paths_to_try = [];
        
        // 1. Try as stored (relative to script)
        $paths_to_try[] = $image_path;
        
        // 2. Try with uploads/ prefix
        $paths_to_try[] = 'uploads/' . basename($image_path);
        
        // 3. Try with ./uploads/ prefix
        $paths_to_try[] = './uploads/' . basename($image_path);
        
        // 4. Try with absolute path from document root
        $paths_to_try[] = $document_root . '/' . ltrim($image_path, '/');
        
        // 5. Try with absolute path from script directory
        $paths_to_try[] = $script_dir . '/' . $image_path;
        
        // 6. Try with uploads directory from document root
        $paths_to_try[] = $document_root . '/uploads/' . basename($image_path);
        
        // 7. Try with the full path as stored
        if (file_exists($image_path)) {
            return $image_path;
        }
        
        // Check each path
        foreach ($paths_to_try as $path) {
            if (file_exists($path)) {
                // Convert to web-accessible path
                if (strpos($path, $document_root) === 0) {
                    // Remove document root to get web path
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
            // If it's already a URL, return it
            if (filter_var($resolved_path, FILTER_VALIDATE_URL)) {
                return $resolved_path;
            }
            
            // Get the base URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $base_url = $protocol . $host;
            
            // Clean up the path
            $clean_path = str_replace('//', '/', '/' . ltrim($resolved_path, './'));
            
            return $base_url . $clean_path;
        }
        
        return null;
    }

    // --- SERVER LOGIC ---
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        
        // 1. Create User
        if (isset($_POST['create_user'])) {
            $fullname = $_POST['new_fullname'];
            $uname = $_POST['new_username'];
            $email = $_POST['new_email'];
            $pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password) VALUES (?, ?, ?, ?)");
                $stmt->execute([$fullname, $uname, $email, $pass]);
                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO inheritance_accounts (user_id, total_amount, processed_amount, in_process_balance, available_balance, withdrawal_status, legal_representative, testator, maximum_withdrawal_amount) VALUES (?, 0, 0, 0, 0, 'Inactive', 'Court Appointed Administrator', 'Estate of Deceased', 0)")->execute([$new_id]);
                $pdo->commit();
                header("Location: " . $_SERVER['PHP_SELF'] . "?msg=SUCCESS: User Created."); exit();
            } catch (Exception $e) { $pdo->rollBack(); $message = "ERROR: " . $e->getMessage(); }
        }

        // 2. Update User Login Details
        if (isset($_POST['update_login'])) {
            $uid = $_POST['edit_user_id'];
            $fullname = $_POST['edit_fullname'];
            $username = $_POST['edit_username'];
            $email = $_POST['edit_email'];
            
            try {
                $pdo->beginTransaction();
                
                if (!empty($_POST['edit_password'])) {
                    $password = password_hash($_POST['edit_password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, password = ? WHERE id = ?");
                    $stmt->execute([$fullname, $username, $email, $password, $uid]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?");
                    $stmt->execute([$fullname, $username, $email, $uid]);
                }
                
                $pdo->commit();
                header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$uid&msg=Login details updated successfully.");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "ERROR: " . $e->getMessage();
            }
        }

        // 3. Delete User/Asset
        if (isset($_POST['delete_user'])) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_POST['del_id']]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=User Deleted."); exit();
        }
        if (isset($_POST['delete_asset'])) {
            $stmt = $pdo->prepare("SELECT image_path FROM portfolio_assets WHERE id = ?");
            $stmt->execute([$_POST['asset_id']]);
            $asset = $stmt->fetch();
            
            if ($asset && !empty($asset['image_path'])) {
                $image_path = resolveImagePath($asset['image_path']);
                if ($image_path && file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            $pdo->prepare("DELETE FROM portfolio_assets WHERE id = ?")->execute([$_POST['asset_id']]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Asset Removed."); exit();
        }

        // 4. Delete Receipt
        if (isset($_POST['delete_receipt'])) {
            $pdo->prepare("DELETE FROM payments_receipt WHERE id = ?")->execute([$_POST['receipt_id']]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Receipt Deleted."); exit();
        }

        // 5. Fund Relocation
        if (isset($_POST['move_funds']) && $target_user) {
            $amt = (float)$_POST['amount'];
            $from = $_POST['from_bucket'];
            $to   = $_POST['to_bucket'];

            $allowed = ['total_amount', 'in_process_balance', 'available_balance', 'processed_amount'];

            if (!in_array($from, $allowed) || !in_array($to, $allowed)) {
                $message = "ERROR: Invalid bucket selected.";
            } elseif ($amt <= 0) {
                $message = "ERROR: Amount must be greater than zero.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        SELECT total_amount, in_process_balance, available_balance, processed_amount
                        FROM inheritance_accounts
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$target_user]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        throw new Exception("Account not found.");
                    }

                    $curr = [
                        'total_amount'       => (float)($row['total_amount'] ?? 0),
                        'in_process_balance' => (float)($row['in_process_balance'] ?? 0),
                        'available_balance'  => (float)($row['available_balance'] ?? 0),
                        'processed_amount'   => (float)($row['processed_amount'] ?? 0),
                    ];

                    if ($curr[$from] < $amt) {
                        throw new Exception("Insufficient funds");
                    }

                    $new_from = $curr[$from] - $amt;
                    $new_to   = $curr[$to]   + $amt;

                    $setParts = [];
                    $params   = [];

                    if ($from === $to) {
                        $setParts[] = "$from = ?";
                        $params[] = $curr[$from];
                    } else {
                        $setParts[] = "$from = ?";
                        $params[] = $new_from;
                        $setParts[] = "$to = ?";
                        $params[] = $new_to;
                    }

                    $setClause = implode(', ', $setParts);
                    $stmt = $pdo->prepare("UPDATE inheritance_accounts SET $setClause WHERE user_id = ?");
                    $params[] = $target_user;
                    $stmt->execute($params);

                    $pdo->prepare("
                        INSERT INTO transaction_history 
                        (user_id, transaction_type, amount, status, description, transaction_date) 
                        VALUES (?, 'relocation', ?, 'Completed', 'Internal Relocation: $from to $to', NOW())
                    ")->execute([$target_user, $amt]);

                    $pdo->commit();
                    header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Funds Relocated."); exit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "" . $e->getMessage();
                }
            }
        }

        // 6. Asset Upload
        if (isset($_POST['upload_asset']) && $target_user) {
            $image_path = null;
            
            $uploads_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            if (!file_exists($uploads_dir)) {
                mkdir($uploads_dir, 0777, true);
                chmod($uploads_dir, 0777);
            }
            
            if (isset($_FILES['asset_image']) && $_FILES['asset_image']['error'] == 0) {
                $extension = pathinfo($_FILES['asset_image']['name'], PATHINFO_EXTENSION);
                $safe_filename = time() . '_' . uniqid() . '.' . $extension;
                $target_file = $uploads_dir . DIRECTORY_SEPARATOR . $safe_filename;
                
                if (move_uploaded_file($_FILES['asset_image']['tmp_name'], $target_file)) { 
                    $image_path = 'uploads/' . $safe_filename;
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

        // 7. External Transfer
        if (isset($_POST['send_external']) && $target_user) {
            $method = $_POST['transfer_method'];
            $amount = (float)$_POST['send_amount'];
            $status_label = $_POST['b_status'];
            
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT available_balance, maximum_withdrawal_amount, next_withdrawal_date FROM inheritance_accounts WHERE user_id = ?");
                $stmt->execute([$target_user]);
                $account_check = $stmt->fetch(PDO::FETCH_ASSOC);

                $avail = (float)$account_check['available_balance'];
                $max_withdrawal = (float)($account_check['maximum_withdrawal_amount'] ?? $avail);
                $next_date = $account_check['next_withdrawal_date'];
                $current_date = date('Y-m-d');

                if ($next_date && $next_date > $current_date) {
                    throw new Exception("Withdrawals are not permitted until " . date('F d, Y', strtotime($next_date)));
                }

                if ($avail < $amount) {
                    throw new Exception("Insufficient Available Balance.");
                }
                
                if ($amount > $max_withdrawal) {
                    throw new Exception("Amount exceeds maximum withdrawal limit of $" . number_format($max_withdrawal, 2));
                }

                $detail = "";
                if($method == 'bank') $detail = $_POST['b_name'] . " (" . $_POST['b_acc'] . ")";
                elseif($method == 'paypal') $detail = $_POST['pp_email'];
                elseif($method == 'cashapp') $detail = $_POST['ca_tag'];
                elseif($method == 'venmo') $detail = $_POST['vn_user'];
                elseif($method == 'crypto') $detail = $_POST['wallet_address'];

                $desc = generateTransferSentence($amount, strtoupper($method), $detail);

                $pdo->prepare("UPDATE inheritance_accounts SET available_balance = available_balance - ?, processed_amount = processed_amount + ?, withdrawal_status = ? WHERE user_id = ?")
                    ->execute([$amount, $amount, $status_label, $target_user]);
                
                $pdo->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, description, transaction_date) VALUES (?, 'external_transfer', ?, ?, ?, NOW())")
                    ->execute([$target_user, $amount, $status_label, $desc]);
                
                $pdo->commit();
                header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Transfer Logged."); exit();
            } catch (Exception $e) { 
                $pdo->rollBack(); 
                $message = "ERROR: " . $e->getMessage(); 
            }
        }

        // 8. Update Specific Transaction Status
        if (isset($_POST['update_tx_status'])) {
            $pdo->prepare("UPDATE transaction_history SET status = ? WHERE id = ?")->execute([$_POST['new_tx_status'], $_POST['tx_id']]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Transaction Status Updated."); exit();
        }

        // 9. REFUND TRANSACTION
        if (isset($_POST['refund_tx'])) {
            $tx_id = $_POST['tx_id'] ?? 0;
            
            if (!$tx_id || !is_numeric($tx_id)) {
                $message = "ERROR: Invalid transaction ID.";
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("SELECT * FROM transaction_history WHERE id = ?");
                    $stmt->execute([$tx_id]);
                    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$tx) {
                        throw new Exception("Transaction not found.");
                    }
                    
                    if (strpos($tx['description'], 'Internal Relocation') !== false) {
                        throw new Exception("Internal relocations cannot be refunded. Use Fund Relocation to move funds.");
                    }
                    
                    $amount = (float)$tx['amount'];
                    $user_id = $tx['user_id'];
                    
                    $stmt = $pdo->prepare("
                        UPDATE inheritance_accounts 
                        SET 
                            available_balance = available_balance + ?,
                            processed_amount = processed_amount - ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$amount, $amount, $user_id]);
                    
                    $stmt = $pdo->prepare("DELETE FROM transaction_history WHERE id = ?");
                    $stmt->execute([$tx_id]);
                    
                    $pdo->commit();
                    
                    header("Location: " . $_SERVER['PHP_SELF'] . "?uid=" . $user_id . "&msg=Refunded $" . number_format($amount, 2) . " successfully.");
                    exit();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Refund failed: " . $e->getMessage();
                }
            }
        }

        // 10. Save Court Letter
        if (isset($_POST['save_letter'])) {
            $letter_number = $_POST['l_num'];
            $letter_date = $_POST['l_date'];
            $letter_type = $_POST['l_type'];
            $description = $_POST['l_body'];
            $status = isset($_POST['l_status']) ? $_POST['l_status'] : 'active';
            
            try {
                $pdo->prepare("INSERT INTO court_letters (user_id, letter_number, letter_date, letter_type, description, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$target_user, $letter_number, $letter_date, $letter_type, $description, $status]);
                header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Court Letter Saved Successfully."); 
                exit();
            } catch (Exception $e) {
                $message = "Error saving court letter: " . $e->getMessage();
            }
        }

        // 11. Save Payment Receipt - Modified to accept partial data
        if (isset($_POST['save_receipt']) && $target_user) {
            // Build receipt data array with defaults
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
                $pdo->prepare("
                    INSERT INTO payments_receipt 
                    (user_id, paid_date, amount_paid, payer_name, receiver_name, payment_subject, payment_due, total_payment, reference_number, status, notes, created_at) 
                    VALUES (:user_id, :paid_date, :amount_paid, :payer_name, :receiver_name, :payment_subject, :payment_due, :total_payment, :reference_number, :status, :notes, NOW())
                ")->execute($receipt_data);
                
                header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Payment Receipt Saved Successfully."); 
                exit();
            } catch (Exception $e) {
                $message = "Error saving receipt: " . $e->getMessage();
            }
        }

        // 12. Update Payment Receipt - Modified to accept partial data
        if (isset($_POST['update_receipt'])) {
            // Build receipt data array with defaults
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
                $pdo->prepare("
                    UPDATE payments_receipt 
                    SET 
                        paid_date = :paid_date, 
                        amount_paid = :amount_paid, 
                        payer_name = :payer_name, 
                        receiver_name = :receiver_name, 
                        payment_subject = :payment_subject, 
                        payment_due = :payment_due, 
                        total_payment = :total_payment, 
                        reference_number = :reference_number, 
                        status = :status, 
                        notes = :notes, 
                        updated_at = NOW()
                    WHERE id = :receipt_id AND user_id = :user_id
                ")->execute($receipt_data);
                
                header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Receipt Updated Successfully."); 
                exit();
            } catch (Exception $e) {
                $message = "Error updating receipt: " . $e->getMessage();
            }
        }

        // 13. Update Inheritance Account Settings
        if (isset($_POST['update_inheritance_settings'])) {
            $legal_rep = $_POST['legal_representative'];
            $next_date = !empty($_POST['next_withdrawal_date']) ? $_POST['next_withdrawal_date'] : null;
            $message_text = $_POST['message_text'];
            $testator = $_POST['testator'];
            $max_amount = !empty($_POST['max_withdrawal_amount']) ? (float)$_POST['max_withdrawal_amount'] : 0;
            $total_amount = !empty($_POST['total_amount']) ? (float)$_POST['total_amount'] : 0;
            
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    UPDATE inheritance_accounts 
                    SET 
                        legal_representative = ?,
                        next_withdrawal_date = ?,
                        message = ?,
                        testator = ?,
                        maximum_withdrawal_amount = ?,
                        total_amount = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$legal_rep, $next_date, $message_text, $testator, $max_amount, $total_amount, $target_user]);
                
                $pdo->commit();
                header("Location: " . $_SERVER['PHP_SELF'] . "?uid=$target_user&msg=Account settings updated successfully."); 
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error updating settings: " . $e->getMessage();
            }
        }
    }

    // Data Fetching
    $users = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
    $account = null; $assets = []; $history = []; $receipts = [];
    $selected_user = null;
    
    if ($target_user) {
        $stmt = $pdo->prepare("SELECT * FROM inheritance_accounts WHERE user_id = ?");
        $stmt->execute([$target_user]); 
        $account = $stmt->fetch();
        
        $astmt = $pdo->prepare("SELECT * FROM portfolio_assets WHERE user_id = ? ORDER BY id DESC");
        $astmt->execute([$target_user]); 
        $assets = $astmt->fetchAll();
        
        $hstmt = $pdo->prepare("SELECT * FROM transaction_history WHERE user_id = ? ORDER BY id DESC");
        $hstmt->execute([$target_user]); 
        $history = $hstmt->fetchAll();
        
        $rstmt = $pdo->prepare("SELECT * FROM payments_receipt WHERE user_id = ? ORDER BY id DESC");
        $rstmt->execute([$target_user]); 
        $receipts = $rstmt->fetchAll();
        
        $ustmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $ustmt->execute([$target_user]); 
        $selected_user = $ustmt->fetch();
    }

    // Get receipt for editing if requested
    $edit_receipt = null;
    if (isset($_GET['edit_receipt']) && $target_user) {
        $stmt = $pdo->prepare("SELECT * FROM payments_receipt WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['edit_receipt'], $target_user]);
        $edit_receipt = $stmt->fetch();
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Global Administration | Beneficiary Portal</title>
    <style>
        :root { 
            --glass: rgba(255, 255, 255, 0.95); 
            --dark: #0f172a; 
            --accent: #2563eb; 
            --gold: #f59e0b; 
            --emerald: #10b981; 
            --sidebar-bg: #f1f5f9; 
            --danger: #ef4444; 
            --purple: #8b5cf6;
            --orange: #f97316;
            --teal: #14b8a6;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f1f5f9; 
            margin: 0; 
            display: flex; 
            color: var(--dark); 
            height: 100vh; 
            overflow: hidden; 
            position: relative;
        }
        
        /* Sidebar - hidden on mobile, slides in when toggled */
        .sidebar { 
            width: 340px; 
            background: var(--sidebar-bg); 
            border-right: 1px solid #e2e8f0; 
            padding: 30px; 
            display: flex; 
            flex-direction: column; 
            overflow-y: auto; 
            transition: transform 0.3s ease;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .main { 
            flex: 1; 
            padding: 40px; 
            overflow-y: auto; 
            position: relative; 
            width: 100%;
        }
        
        /* Mobile menu button */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            width: 50px;
            height: 50px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            font-size: 24px;
            z-index: 1002;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            align-items: center;
            justify-content: center;
        }
        
        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        .card { 
            background: var(--glass); 
            border-radius: 16px; 
            padding: clamp(15px, 3vw, 24px); 
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); 
            border: 1px solid #e2e8f0; 
            margin-bottom: 25px; 
            width: 100%;
            overflow-x: auto;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-header h3 {
            margin: 0;
            color: var(--dark);
            font-size: clamp(16px, 3vw, 20px);
            word-break: break-word;
        }
        
        .card-header .badge {
            background: var(--purple);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: clamp(11px, 2vw, 13px);
            white-space: nowrap;
        }
        
        label { 
            display: block; 
            font-size: clamp(11px, 2vw, 13px); 
            font-weight: 700; 
            margin-bottom: 5px; 
            color: #64748b; 
            text-transform: uppercase; 
        }
        
        input, select, textarea { 
            width: 100%; 
            padding: clamp(8px, 2vw, 12px); 
            border: 1px solid #cbd5e1; 
            border-radius: 8px; 
            margin-bottom: 10px; 
            box-sizing: border-box; 
            font-family: inherit; 
            font-size: clamp(14px, 2vw, 16px);
        }
        
        button { 
            width: 100%; 
            padding: clamp(10px, 2vw, 12px); 
            background: var(--accent); 
            color: white; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 600; 
            font-size: clamp(14px, 2vw, 16px);
            transition: opacity 0.2s;
        }
        
        button:hover {
            opacity: 0.9;
        }
        
        .btn-secondary {
            background: var(--dark);
        }
        
        .btn-success {
            background: var(--emerald);
        }
        
        .btn-warning {
            background: var(--gold);
            color: var(--dark);
        }
        
        .btn-purple {
            background: var(--purple);
        }
        
        .btn-orange {
            background: var(--orange);
        }
        
        .btn-teal {
            background: var(--teal);
        }
        
        .asset-container {
            display: flex;
            gap: clamp(15px, 3vw, 25px);
            align-items: stretch;
            flex-wrap: wrap;
        }
        
        .asset-feed {
            width: min(100%, 220px);
            height: 320px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 5px;
            flex-shrink: 0;
        }
        
        @media screen and (max-width: 768px) {
            .asset-feed {
                width: 100%;
                height: 250px;
            }
        }
        
        .feed-item {
            position: relative;
            margin-bottom: 15px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 10px;
        }
        
        .feed-title {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.6);
            color: #fff;
            font-size: clamp(10px, 2vw, 12px);
            font-weight: 700;
            padding: 4px 8px;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            z-index: 2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .feed-img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            display: block;
            background: #f8fafc;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        
        .feed-img:hover {
            opacity: 0.9;
        }
        
        .feed-img-placeholder {
            width: 100%;
            height: 180px;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            border-radius: 8px;
            flex-direction: column;
            gap: 5px;
            cursor: default;
            text-align: center;
            padding: 10px;
            word-break: break-word;
        }
        
        .feed-img-placeholder small {
            font-size: clamp(9px, 1.5vw, 11px);
            color: #94a3b8;
        }
        
        .feed-desc {
            font-size: clamp(11px, 1.8vw, 13px);
            color: #475569;
            margin-top: 6px;
            line-height: 1.3;
            padding: 0 4px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        
        .asset-del-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        
        #notify { 
            position: fixed; 
            top: 20px; 
            left: 50%; 
            transform: translateX(-50%); 
            background: #0a7600; 
            color: #ffffff; 
            padding: 12px 30px; 
            border-radius: 50px; 
            z-index: 9999; 
            opacity: 0; 
            visibility: hidden; 
            transition: 0.4s; 
            max-width: 90%;
            text-align: center;
            word-break: break-word;
        }
        
        #notify.show { 
            opacity: 1; 
            visibility: visible; 
        }
        
        .user-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); 
            gap: 15px; 
            margin-top: 20px; 
        }
        
        .user-card { 
            background: white; 
            border-radius: 12px; 
            padding: 15px; 
            text-align: center; 
            border: 1px solid #e2e8f0; 
        }
        
        .profile-circle { 
            width: 50px; 
            height: 50px; 
            background: #e2e8f0; 
            border-radius: 50%; 
            margin: 0 auto 10px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold; 
            color: #64748b; 
            font-size: 18px;
        }
        
        .btn-del-user { 
            background: none; 
            color: var(--danger); 
            font-size: clamp(11px, 2vw, 13px); 
            border: 1px solid #fee2e2; 
            margin-top: 8px; 
            padding: 4px; 
            width: auto; 
        }
        
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 20px; 
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }
        
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
        }
        
        .grid-5 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 8px;
        }
        
        @media screen and (max-width: 480px) {
            .grid-2, .grid-3, .grid-4, .grid-5 {
                grid-template-columns: 1fr;
                gap: 8px;
            }
        }
        
        .stat-item { 
            background: white; 
            padding: 15px; 
            border-radius: 10px; 
            border: 1px solid #e2e8f0; 
            border-left: 4px solid var(--accent); 
            margin-bottom: 10px; 
        }
        
        .stat-item h1 { 
            margin: 0; 
            font-size: clamp(18px, 4vw, 24px); 
            word-break: break-word;
        }
        
        .status-badge { 
            font-size: clamp(10px, 1.8vw, 12px); 
            background: #e2e8f0; 
            padding: 2px 8px; 
            border-radius: 4px; 
            display: inline-block; 
            margin-top: 5px; 
            color: var(--dark); 
            font-weight: 700; 
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: clamp(12px, 2vw, 14px);
        }
        
        .info-value {
            color: var(--dark);
            font-weight: 500;
            font-size: clamp(12px, 2vw, 14px);
            word-break: break-word;
            text-align: right;
        }
        
        .tx-table, .receipt-table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: clamp(12px, 2vw, 14px); 
        }
        
        /* FIX: Responsive text alignment - keep original table structure but improve text wrapping */
        @media screen and (max-width: 768px) {
            .tx-table, .receipt-table {
                display: block;
                width: 100%;
            }
            
            .tx-table tbody, .receipt-table tbody {
                display: block;
                width: 100%;
            }
            
            .tx-table tr, .receipt-table tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                background: white;
                padding: 10px;
            }
            
            .tx-table td, .receipt-table td {
                display: block;
                padding: 8px 0;
                border-bottom: 1px solid #f1f5f9;
                text-align: left;
                white-space: normal;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            
            .tx-table td:last-child, .receipt-table td:last-child {
                border-bottom: none;
            }
            
            .tx-table td:before, .receipt-table td:before {
                content: attr(data-label);
                font-weight: 600;
                color: #64748b;
                display: inline-block;
                width: 100px;
                margin-right: 10px;
            }
            
            /* Hide table headers on mobile */
            .tx-table thead, .receipt-table thead {
                display: none;
            }
            
            /* Action cell specific styling */
            .action-cell {
                display: flex !important;
                flex-direction: column;
                gap: 8px;
            }
            
            .action-cell:before {
                display: block !important;
                width: 100% !important;
                margin-bottom: 5px;
            }
            
            .action-cell > form,
            .action-cell > button,
            .action-cell > div {
                width: 100%;
            }
            
            /* Ensure text in details column wraps properly */
            td[data-label="Details"] {
                max-width: 100%;
                overflow: visible;
                white-space: normal;
                word-break: break-word;
            }
        }
        
        /* Additional fix for very small screens */
        @media screen and (max-width: 480px) {
            .tx-table td:before, .receipt-table td:before {
                display: block;
                width: 100%;
                margin-bottom: 4px;
            }
            
            .action-cell {
                flex-direction: column;
            }
            
            .action-cell > form,
            .action-cell > button,
            .action-cell > div {
                width: 100%;
            }
            
            .tx-update-btn, .tx-refund-btn, .receipt-edit-btn, .receipt-delete-btn {
                width: 100%;
                text-align: center;
            }
        }
        
        .tx-table th, .receipt-table th { 
            text-align: left; 
            padding: 10px; 
            border-bottom: 2px solid #f1f5f9; 
            color: #64748b; 
        }
        
        .tx-table td, .receipt-table td { 
            padding: 12px 10px; 
            border-bottom: 1px solid #f1f9f2; 
        }
        
        .tx-status-input { 
            padding: 4px; 
            font-size: clamp(11px, 1.8vw, 13px); 
            width: 100%; 
            min-width: 80px;
            margin: 0; 
        }
        
        .tx-update-btn { 
            padding: 4px 8px; 
            width: auto; 
            font-size: clamp(10px, 1.8vw, 12px); 
            background: var(--dark); 
        }
        
        .tx-refund-btn { 
            padding: 4px 0px; 
            width: auto; 
            font-size: clamp(10px, 1.8vw, 12px); 
            background: #00990f; 
            color: white; 
            border-radius: 4px; 
            border:none; 
            cursor:pointer;
        }
        
        .receipt-edit-btn {
            padding: 4px 8px;
            width: auto;
            font-size: clamp(10px, 1.8vw, 12px);
            background: var(--orange);
            color: white;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 5px;
        }
        
        .receipt-delete-btn {
            padding: 4px 8px;
            width: auto;
            font-size: clamp(10px, 1.8vw, 12px);
            background: var(--danger);
            color: white;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        
        /* Custom Modal Styles */
        #customModal, #imageModal, #profileModal, #receiptModal { 
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
            padding: 15px;
        }
        
        #imageModal {
            background: rgba(255, 255, 255, 0.95);
        }
        
        .modal-content, .profile-modal-content, .receipt-modal-content { 
            background: white; 
            padding: 30px; 
            border-radius: 15px; 
            text-align: center; 
            max-width: 400px; 
            width: 100%; 
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .receipt-modal-content {
            max-width: 700px;
            text-align: left;
        }
        
        .profile-modal-content {
            max-width: 500px;
            text-align: left;
        }
        
        .profile-modal-content h3, .receipt-modal-content h3 {
            margin-bottom: 20px;
            color: var(--dark);
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            font-size: clamp(18px, 4vw, 24px);
        }
        
        .image-modal-content {
            background: transparent;
            padding: 20px;
            border-radius: 0;
            text-align: center;
            max-width: 90vw;
            max-height: 90vh;
            position: relative;
        }
        
        .image-modal-content img {
            max-width: 100%;
            max-height: 85vh;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .modal-btns { 
            display: flex; 
            gap: 10px; 
            margin-top: 20px; 
            flex-wrap: wrap;
        }
        
        .modal-btns button { 
            flex: 1 1 auto; 
        }
        
        .close-image-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            color: var(--dark);
            border: none;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 10001;
        }
        
        .close-image-btn:hover {
            background: #f1f5f9;
        }
        
        .image-title {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            color: var(--dark);
            font-size: clamp(12px, 2vw, 14px);
            background: rgba(255,255,255,0.9);
            padding: 8px;
            margin: 0 auto;
            max-width: 80%;
            border-radius: 20px;
            pointer-events: none;
            word-break: break-word;
        }
        
        .no-actions {
            color: #94a3b8;
            font-style: italic;
            font-size: clamp(11px, 2vw, 13px);
            text-align: center;
        }
        
        .action-cell {
            min-width: 160px;
        }
        
        .profile-icon {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 999;
            transition: transform 0.2s;
        }
        
        .profile-icon:hover {
            transform: scale(1.1);
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .profile-header .avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
        }
        
        .form-row {
            margin-bottom: 15px;
        }
        
        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-row input, .form-row select, .form-row textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        
        .password-hint {
            font-size: clamp(11px, 2vw, 13px);
            color: #64748b;
            margin-top: 5px;
        }
        
        .letter-preview {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-family: 'Times New Roman', serif;
        }
        
        .info-panel {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        .info-panel h4 {
            margin: 0 0 10px 0;
            color: var(--purple);
            font-size: clamp(12px, 2.5vw, 14px);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-completed {
            color: var(--emerald);
            font-weight: 600;
        }
        .status-pending {
            color: var(--gold);
            font-weight: 600;
        }
        .status-failed {
            color: var(--danger);
            font-weight: 600;
        }
        .status-refunded {
            color: var(--purple);
            font-weight: 600;
        }
        
        /* Responsive Design */
        @media screen and (max-width: 1024px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                transform: translateX(-100%);
                z-index: 1001;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .menu-toggle {
                display: flex;
            }
            
            .main {
                padding: 70px 20px 20px 20px;
            }
            
            .profile-icon {
                top: 15px;
                right: 15px;
                width: 45px;
                height: 45px;
                font-size: 18px;
            }
        }
        
        @media screen and (max-width: 480px) {
            .main {
                padding: 70px 10px 10px 10px;
            }
            
            .card {
                padding: 15px;
            }
            
            .user-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content, .profile-modal-content, .receipt-modal-content {
                padding: 20px;
            }
            
            .modal-btns {
                flex-direction: column;
            }
            
            .modal-btns button {
                width: 100%;
            }
        }
        
        .optional-field {
            opacity: 0.9;
            border-left: 3px solid var(--teal);
            padding-left: 10px;
        }
        
        .optional-field label::after {
            content: " (optional)";
            font-weight: normal;
            color: #64748b;
            font-size: 0.8em;
        }
        
        .required-field label::after {
            content: " *";
            color: var(--danger);
            font-weight: bold;
        }
    </style>
</head>
<body>

<div id="notify" class="<?php echo $message ? 'show' : ''; ?>"><?php echo htmlspecialchars($message); ?></div>

<!-- Mobile Menu Toggle -->
<button class="menu-toggle" onclick="toggleSidebar()">☰</button>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<?php if($target_user && $selected_user): ?>
<div class="profile-icon" onclick="openProfileModal()">
    <?php echo strtoupper(substr($selected_user['full_name'], 0, 1)); ?>
</div>
<?php endif; ?>

<!-- Action Confirmation Modal -->
<div id="customModal">
    <div class="modal-content">
        <h3 id="modalTitle">Confirm Action</h3>
        <p id="modalMsg"></p>
        <form id="modalForm" method="POST">
            <input type="hidden" name="tx_id" id="modalTxId" value="">
            <input type="hidden" name="asset_id" id="modalAssetId" value="">
            <input type="hidden" name="receipt_id" id="modalReceiptId" value="">
            <input type="hidden" name="del_id" id="modalUserId" value="">
            <input type="hidden" name="target_user" id="modalTargetUser" value="<?php echo $target_user; ?>">
            <div class="modal-btns">
                <button type="button" onclick="closeModal()" style="background:#cbd5e1; color:var(--dark);">Cancel</button>
                <button type="submit" id="modalConfirmBtn" style="background:var(--danger);">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Profile Edit Modal -->
<div id="profileModal">
    <div class="profile-modal-content">
        <div class="profile-header">
            <div class="avatar"><?php echo strtoupper(substr($selected_user['full_name'] ?? 'U', 0, 1)); ?></div>
            <h3>Edit Login Details</h3>
        </div>
        
        <?php if($selected_user): ?>
        <form method="POST">
            <input type="hidden" name="edit_user_id" value="<?php echo $selected_user['id']; ?>">
            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
            
            <div class="form-row">
                <label>Full Name</label>
                <input type="text" name="edit_fullname" value="<?php echo htmlspecialchars($selected_user['full_name']); ?>" required>
            </div>
            
            <div class="form-row">
                <label>Username</label>
                <input type="text" name="edit_username" value="<?php echo htmlspecialchars($selected_user['username']); ?>" required>
            </div>
            
            <div class="form-row">
                <label>Email Address</label>
                <input type="email" name="edit_email" value="<?php echo htmlspecialchars($selected_user['email']); ?>" required>
            </div>
            
            <div class="form-row">
                <label>New Password (leave blank to keep current)</label>
                <input type="password" name="edit_password" placeholder="Enter new password">
                <div class="password-hint">Leave empty to keep current password</div>
            </div>
            
            <div class="modal-btns">
                <button type="button" onclick="closeProfileModal()" style="background:#cbd5e1; color:var(--dark);">Cancel</button>
                <button type="submit" name="update_login" style="background:var(--accent);">Save Changes</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Image Viewer Modal -->
<div id="imageModal" onclick="closeImageModal()">
    <div class="image-modal-content" onclick="event.stopPropagation()">
        <button class="close-image-btn" onclick="closeImageModal()">×</button>
        <img id="modalImage" src="" alt="Full size image">
        <div class="image-title" id="modalImageTitle"></div>
    </div>
</div>

<!-- Receipt Edit Modal -->
<?php if($edit_receipt): ?>
<div id="receiptModal" style="display: flex;">
    <div class="receipt-modal-content">
        <h3>✏️ Edit Payment Receipt #<?php echo $edit_receipt['id']; ?></h3>
        <form method="POST">
            <input type="hidden" name="receipt_id" value="<?php echo $edit_receipt['id']; ?>">
            <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
            
            <div class="grid-3">
                <div class="form-row">
                    <label>Paid Date</label>
                    <input type="date" name="edit_paid_date" value="<?php echo $edit_receipt['paid_date']; ?>">
                </div>
                <div class="form-row">
                    <label>Amount Paid ($)</label>
                    <input type="number" step="0.01" name="edit_amount_paid" value="<?php echo $edit_receipt['amount_paid']; ?>">
                </div>
                <div class="form-row">
                    <label>Payment Due ($)</label>
                    <input type="number" step="0.01" name="edit_payment_due" value="<?php echo $edit_receipt['payment_due']; ?>">
                </div>
            </div>
            
            <div class="grid-2">
                <div class="form-row">
                    <label>Payer Name</label>
                    <input type="text" name="edit_payer_name" value="<?php echo htmlspecialchars($edit_receipt['payer_name']); ?>">
                </div>
                <div class="form-row">
                    <label>Receiver Name</label>
                    <input type="text" name="edit_receiver_name" value="<?php echo htmlspecialchars($edit_receipt['receiver_name']); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <label>Payment Subject</label>
                <input type="text" name="edit_payment_subject" value="<?php echo htmlspecialchars($edit_receipt['payment_subject']); ?>">
            </div>
            
            <div class="grid-3">
                <div class="form-row">
                    <label>Total Payment ($)</label>
                    <input type="number" step="0.01" name="edit_total_payment" value="<?php echo $edit_receipt['total_payment']; ?>">
                </div>
                <div class="form-row">
                    <label>Reference Number</label>
                    <input type="text" name="edit_reference_number" value="<?php echo htmlspecialchars($edit_receipt['reference_number']); ?>">
                </div>
                <div class="form-row">
                    <label>Status</label>
                    <select name="edit_receipt_status">
                        <option value="pending" <?php echo $edit_receipt['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $edit_receipt['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="failed" <?php echo $edit_receipt['status'] == 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="refunded" <?php echo $edit_receipt['status'] == 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <label>Notes</label>
                <textarea name="edit_receipt_notes" rows="3"><?php echo htmlspecialchars($edit_receipt['notes']); ?></textarea>
            </div>
            
            <div class="modal-btns">
                <button type="button" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>?uid=<?php echo $target_user; ?>'" style="background:#cbd5e1; color:var(--dark);">Cancel</button>
                <button type="submit" name="update_receipt" style="background:var(--orange);">Update Receipt</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <h2 style="margin-top:0;">SERVER CONTROL</h2>
    <form method="GET">
        <label>Beneficiary</label>
        <select name="uid" onchange="this.form.submit()">
            <option value="">-- Main Directory --</option>
            <?php foreach($users as $u): ?>
                <option value="<?php echo $u['id']; ?>" <?php echo ($target_user == $u['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($u['full_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if($account): ?>
    <div style="margin-top: 30px;">
        <div class="stat-item"><label>Total Portfolio</label><h1>$<?php echo number_format($account['total_amount'], 2); ?></h1></div>
        <div class="stat-item" style="border-color:var(--gold);"><label>In Process</label><h1>$<?php echo number_format($account['in_process_balance'], 2); ?></h1></div>
        <div class="stat-item" style="border-color:var(--emerald);"><label>Available Balance</label><h1>$<?php echo number_format($account['available_balance'], 2); ?></h1></div>
    </div>
    
    <!-- Legal Info Sidebar -->
    <div class="info-panel" style="margin-top: 20px;">
        <h4>⚖️ Legal Information</h4>
        <div class="info-row">
            <span class="info-label">Legal Rep:</span>
            <span class="info-value"><?php echo htmlspecialchars($account['legal_representative'] ?? 'Not assigned'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Testator:</span>
            <span class="info-value"><?php echo htmlspecialchars($account['testator'] ?? 'Estate of Deceased'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Next Withdrawal:</span>
            <span class="info-value">
                <?php 
                if (!empty($account['next_withdrawal_date'])) {
                    echo date('M d, Y', strtotime($account['next_withdrawal_date']));
                } else {
                    echo 'No restriction';
                }
                ?>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Max Withdrawal:</span>
            <span class="info-value">$<?php echo number_format($account['maximum_withdrawal_amount'] ?? 0, 2); ?></span>
        </div>
        <?php if(!empty($account['message'])): ?>
        <div class="info-row" style="border-bottom: none;">
            <span class="info-label">Message:</span>
            <span class="info-value" style="font-style: italic;"><?php echo htmlspecialchars($account['message']); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Main Content -->
<div class="main">
    <?php if(!$target_user): ?>
        <h1 style="margin-top:0;">Global Administration</h1>
        <div class="dashboard-grid">
            <div class="card">
                <h3>Create New Beneficiary Account</h3>
                <form method="POST">
                    <input type="text" name="new_fullname" placeholder="Full Name" required>
                    <input type="text" name="new_username" placeholder="Username" required>
                    <input type="email" name="new_email" placeholder="Email" required>
                    <input type="password" name="new_password" placeholder="Password" required>
                    <button type="submit" name="create_user" style="background:var(--dark);">Initialize Account</button>
                </form>
            </div>
        </div>
        <h3>Beneficiary Directory</h3>
        <div class="user-grid">
            <?php foreach($users as $u): ?>
                <div class="user-card">
                    <div class="profile-circle">#<?php echo $u['id']; ?></div>
                    <span style="font-weight:700; display:block; word-break:break-word;"><?php echo htmlspecialchars($u['full_name']); ?></span>
                    <a href="?uid=<?php echo $u['id']; ?>" style="text-decoration:none;"><button style="margin-top:10px; padding:5px; font-size:0.75rem;">Manage Ledger</button></a>
                    <button type="button" class="btn-del-user" onclick="confirmDeleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">Delete User</button>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <h1 style="margin-top:0; font-size:clamp(20px, 5vw, 32px;">Ledger Control: <span style="color: var(--accent);">UID#<?php echo $target_user; ?></span></h1>

        <!-- Account Settings Card with Total Portfolio -->
        <div class="card" style="border-left: 5px solid var(--purple);">
            <div class="card-header">
                <h3>⚖️ Account Legal & Financial Settings</h3>
                <span class="badge">Inheritance Account Configuration</span>
            </div>
            <form method="POST">
                <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                
                <div class="grid-4">
                    <div>
                        <label>Legal Representative</label>
                        <input type="text" name="legal_representative" value="<?php echo htmlspecialchars($account['legal_representative'] ?? ''); ?>" placeholder="e.g., Hon. Judge Robert M. Harrison">
                    </div>
                    <div>
                        <label>Testator (Estate Of)</label>
                        <input type="text" name="testator" value="<?php echo htmlspecialchars($account['testator'] ?? 'Estate of Deceased'); ?>" placeholder="Estate of ...">
                    </div>
                    <div>
                        <label>Total Portfolio</label>
                        <input type="number" name="total_amount" step="0.01" value="<?php echo $account['total_amount'] ?? '0'; ?>" placeholder="0.00">
                        <small style="color: #64748b;">Total estate value</small>
                    </div>
                    <div>
                        <label>Max Withdrawal Amount</label>
                        <input type="number" name="max_withdrawal_amount" step="0.01" value="<?php echo $account['maximum_withdrawal_amount'] ?? '0'; ?>" placeholder="0.00">
                        <small style="color: #64748b;">Per transaction limit</small>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div>
                        <label>Next Withdrawal Date</label>
                        <input type="date" name="next_withdrawal_date" value="<?php echo $account['next_withdrawal_date'] ?? ''; ?>">
                        <small style="color: #64748b;">Leave empty for no restriction</small>
                    </div>
                    <div>
                        <label>Display Message</label>
                        <input type="text" name="message_text" value="<?php echo htmlspecialchars($account['message'] ?? ''); ?>" placeholder="Message to display to beneficiary">
                    </div>
                </div>
                
                <button type="submit" name="update_inheritance_settings" class="btn-purple" style="margin-top: 10px;">Update Account Settings</button>
            </form>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h3>1. Fund Relocation</h3>
                <form method="POST">
                    <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                    <input type="number" name="amount" step="0.01" placeholder="0.00" required>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <select name="from_bucket" style="flex:1;">
                            <option value="total_amount">From Total</option>
                            <option value="available_balance">From Available</option>
                            <option value="in_process_balance">From In Process</option>
                        </select>
                        <select name="to_bucket" style="flex:1;">
                            <option value="available_balance">To Available</option>
                            <option value="total_amount">To Total</option>
                            <option value="in_process_balance">To In Process</option>
                        </select>
                    </div>
                    <button type="submit" name="move_funds" style="background:var(--dark);">Relocate Funds</button>
                </form>
            </div>

            <div class="card">
                <h3>2. External Transfer</h3>
                <form method="POST">
                    <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                    <input type="number" name="send_amount" placeholder="Amount" step="0.01" required>
                    <select name="transfer_method" id="methodSelect" onchange="toggleTransferFields()">
                        <option value="bank">Traditional Bank Wire</option>
                        <option value="paypal">PayPal</option>
                        <option value="cashapp">CashApp</option>
                        <option value="venmo">Venmo</option>
                        <option value="crypto">Cryptocurrency</option>
                    </select>
                    <div id="fields_bank" class="method-fields">
                        <input type="text" name="b_name" placeholder="Bank Name">
                        <input type="text" name="b_acc" placeholder="Account Number">
                    </div>
                    <div id="fields_paypal" class="method-fields" style="display:none;"><input type="email" name="pp_email" placeholder="PayPal Email"></div>
                    <div id="fields_cashapp" class="method-fields" style="display:none;"><input type="text" name="ca_tag" placeholder="CashTag ($)"></div>
                    <div id="fields_venmo" class="method-fields" style="display:none;"><input type="text" name="vn_user" placeholder="Venmo (@)"></div>
                    <div id="fields_crypto" class="method-fields" style="display:none;"><input type="text" name="wallet_address" placeholder="Wallet Address"></div>

                    <label>Set General Withdrawal Status</label>
                    <input type="text" name="b_status" placeholder="e.g. Processing..." required>
                    <button type="submit" name="send_external" style="background:var(--emerald);">Push Transfer</button>
                </form>
            </div>
        </div>

        <div class="card" style="border-left: 5px solid var(--accent);">
            <h3>3. Portfolio Assets Ledger</h3>
            <div class="asset-container">
                <div class="asset-feed">
                    <?php if(empty($assets)): ?>
                        <div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 0.8rem;">
                            No assets linked yet.
                        </div>
                    <?php else: ?>
                        <?php foreach($assets as $ast): ?>
                            <div class="feed-item">
                                <div class="feed-title"><?php echo htmlspecialchars($ast['asset_title']); ?></div>
                                <button type="button" class="asset-del-btn" 
                                        onclick="confirmDeleteAsset(<?php echo $ast['id']; ?>)">×</button>
                                <?php 
                                $image_url = getImageUrl($ast['image_path'] ?? '');
                                if($image_url): 
                                ?>
                                    <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                         class="feed-img" 
                                         alt="<?php echo htmlspecialchars($ast['asset_title']); ?>"
                                         ondblclick="openImageModal('<?php echo htmlspecialchars($image_url); ?>', '<?php echo htmlspecialchars($ast['asset_title']); ?>')"
                                         onerror="this.style.display='none'; this.parentElement.innerHTML+='<div class=\'feed-img-placeholder\'><span>⚠️ Image Error</span><small><?php echo htmlspecialchars(basename($ast['image_path'] ?? '')); ?></small></div>';">
                                <?php else: ?>
                                    <div class="feed-img-placeholder">
                                        <span>📷 No Image</span>
                                        <?php if(!empty($ast['image_path'])): ?>
                                            <small><?php echo htmlspecialchars(basename($ast['image_path'])); ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="feed-desc"><?php echo htmlspecialchars($ast['asset_description'] ?: 'No description'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div style="flex:1;">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                        <label>Register New Asset</label>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <input type="text" name="asset_title" placeholder="Asset Title" required>
                            <input type="file" name="asset_image" accept="image/*" required>
                        </div>
                        <textarea name="asset_desc" rows="4" placeholder="Full asset description..."></textarea>
                        <button type="submit" name="upload_asset">Upload & Link Asset</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 4. Court Letters Section -->
        <div class="card">
            <h3>4. Court Letters</h3>
            <form method="POST">
                <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                <div class="grid-3">
                    <div>
                        <label>Letter Number</label>
                        <input type="text" name="l_num" placeholder="e.g., CRT-2024-001" required>
                    </div>
                    <div>
                        <label>Letter Date</label>
                        <input type="date" name="l_date" required>
                    </div>
                    <div>
                        <label>Letter Type</label>
                        <select name="l_type" required>
                            <option value="notice">Notice</option>
                            <option value="order">Court Order</option>
                            <option value="letter">General Letter</option>
                            <option value="notification">Notification</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <label>Description/Content</label>
                    <textarea name="l_body" rows="3" placeholder="Enter letter content..." required></textarea>
                </div>
                <div style="margin-top: 10px;">
                    <label>Status</label>
                    <select name="l_status">
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
                <button type="submit" name="save_letter" style="background:var(--dark); margin-top: 15px;">Save Court Letter</button>
            </form>
        </div>

        <!-- 5. Payment Receipts Section - Modified to accept partial data -->
        <div class="card" style="border-left: 5px solid var(--teal);">
            <div class="card-header">
                <h3>💰 5. Payment Receipts</h3>
                <span class="badge" style="background: var(--teal);"><?php echo count($receipts); ?> Receipts</span>
            </div>
            
            <!-- Receipt Creation Form - All fields optional -->
            <form method="POST" style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px dashed #e2e8f0;">
                <h4 style="margin-top: 0; margin-bottom: 15px;">➕ Add New Receipt</h4>
                <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px;">All fields are optional - fill only what you need</p>
                
                <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                
                <div class="grid-5">
                    <div class="optional-field">
                        <label>Paid Date</label>
                        <input type="date" name="paid_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="optional-field">
                        <label>Amount Paid ($)</label>
                        <input type="number" step="0.01" name="amount_paid" placeholder="0.00">
                    </div>
                    <div class="optional-field">
                        <label>Payment Due ($)</label>
                        <input type="number" step="0.01" name="payment_due" placeholder="0.00">
                    </div>
                    <div class="optional-field">
                        <label>Total Payment ($)</label>
                        <input type="number" step="0.01" name="total_payment" placeholder="0.00">
                    </div>
                    <div class="optional-field">
                        <label>Reference #</label>
                        <input type="text" name="reference_number" placeholder="e.g., INV-2024-001">
                    </div>
                </div>
                
                <div class="grid-3">
                    <div class="optional-field">
                        <label>Payer Name</label>
                        <input type="text" name="payer_name" placeholder="Full name of payer">
                    </div>
                    <div class="optional-field">
                        <label>Receiver Name</label>
                        <input type="text" name="receiver_name" placeholder="Full name of receiver">
                    </div>
                    <div class="optional-field">
                        <label>Status</label>
                        <select name="receipt_status">
                            <option value="pending">Pending</option>
                            <option value="completed" selected>Completed</option>
                            <option value="failed">Failed</option>
                            <option value="refunded">Refunded</option>
                        </select>
                    </div>
                </div>
                
                <div class="optional-field">
                    <label>Payment Subject</label>
                    <input type="text" name="payment_subject" placeholder="e.g., Estate Tax Payment, Legal Fees, etc.">
                </div>
                
                <div class="optional-field">
                    <label>Notes (Optional)</label>
                    <textarea name="receipt_notes" rows="2" placeholder="Additional notes about this payment..."></textarea>
                </div>
                
                <button type="submit" name="save_receipt" class="btn-teal" style="margin-top: 10px;">💾 Save Payment Receipt</button>
            </form>
            
            <!-- Receipts Table - Original style but with responsive data labels -->
            <h4>📋 Recent Receipts</h4>
            <div style="overflow-x: auto;">
                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Payer</th>
                            <th>Subject</th>
                            <th>Paid</th>
                            <th>Due</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($receipts)): ?>
                            <tr><td colspan="9" style="text-align:center; color:#94a3b8; padding: 20px;">No payment receipts recorded.</td></tr>
                        <?php else: foreach($receipts as $rec): 
                            $status_class = '';
                            if($rec['status'] == 'completed') $status_class = 'status-completed';
                            elseif($rec['status'] == 'pending') $status_class = 'status-pending';
                            elseif($rec['status'] == 'failed') $status_class = 'status-failed';
                            elseif($rec['status'] == 'refunded') $status_class = 'status-refunded';
                        ?>
                            <tr>
                                <td data-label="ID">#<?php echo $rec['id']; ?></td>
                                <td data-label="Date"><?php echo date('m/d/Y', strtotime($rec['paid_date'])); ?></td>
                                <td data-label="Payer"><?php echo htmlspecialchars(substr($rec['payer_name'], 0, 15)) . (strlen($rec['payer_name']) > 15 ? '...' : ''); ?></td>
                                <td data-label="Subject"><?php echo htmlspecialchars(substr($rec['payment_subject'], 0, 20)) . (strlen($rec['payment_subject']) > 20 ? '...' : ''); ?></td>
                                <td data-label="Paid">$<?php echo number_format($rec['amount_paid'], 2); ?></td>
                                <td data-label="Due">$<?php echo number_format($rec['payment_due'], 2); ?></td>
                                <td data-label="Total">$<?php echo number_format($rec['total_payment'], 2); ?></td>
                                <td data-label="Status" class="<?php echo $status_class; ?>"><?php echo ucfirst($rec['status']); ?></td>
                                <td data-label="Actions" class="action-cell">
                                    <a href="?uid=<?php echo $target_user; ?>&edit_receipt=<?php echo $rec['id']; ?>" class="receipt-edit-btn">Edit</a>
                                    <button type="button" class="receipt-delete-btn" onclick="confirmDeleteReceipt(<?php echo $rec['id']; ?>)">Del</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="border-top: 5px solid var(--gold);">
            <h3>6. Transaction History & Status Control</h3>
            <div style="overflow-x: auto;">
                <table class="tx-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Details</th>
                            <th>Amount</th>
                            <th class="action-cell">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($history)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#94a3b8;">No transactions recorded.</td></tr>
                        <?php else: foreach($history as $tx): 
                            $date_raw = $tx['transaction_date'] ?? $tx['created_at'];
                            $display_date = ($date_raw) ? date('M d, Y', strtotime($date_raw)) : "Just Now";
                            $is_internal = (strpos($tx['description'], 'Internal Relocation') !== false);
                        ?>
                            <tr>
                                <td data-label="Date"><?php echo $display_date; ?></td>
                                <td data-label="Details" style="max-width:250px; word-break:break-word;"><?php echo htmlspecialchars($tx['description']); ?></td>
                                <td data-label="Amount" style="font-weight:700;">$<?php echo number_format($tx['amount'], 2); ?></td>
                                
                                <td data-label="Actions" class="action-cell">
                                    <?php if($is_internal): ?>
                                        <div class="no-actions">Completed</div>
                                    <?php else: ?>
                                        <div style="display:flex; flex-direction:column; gap:5px;">
                                            <form method="POST" style="display:flex; gap:5px; margin:0; flex-wrap:wrap;">
                                                <input type="hidden" name="tx_id" value="<?php echo $tx['id']; ?>">
                                                <input type="hidden" name="target_user" value="<?php echo $target_user; ?>">
                                                <input type="text" name="new_tx_status" class="tx-status-input" value="<?php echo htmlspecialchars($tx['status']); ?>">
                                                <button type="submit" name="update_tx_status" class="tx-update-btn">Update</button>
                                            </form>
                                            <button type="button" class="tx-refund-btn" onclick="confirmRefund(<?php echo $tx['id']; ?>, '<?php echo number_format($tx['amount'], 2); ?>')">Refund & Delete</button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function toggleTransferFields() {
        const method = document.getElementById('methodSelect').value;
        document.querySelectorAll('.method-fields').forEach(div => div.style.display = 'none');
        const selectedField = document.getElementById('fields_' + method);
        if (selectedField) {
            selectedField.style.display = 'block';
        }
    }
    
    // Sidebar toggle for mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    }
    
    function confirmDeleteAsset(id) {
        document.getElementById('modalTitle').innerText = 'Delete Asset';
        document.getElementById('modalMsg').innerText = "Delete this portfolio asset permanently?";
        document.getElementById('modalAssetId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = "delete_asset";
        confirmBtn.style.background = "var(--danger)";
        confirmBtn.textContent = "Delete Asset";
        document.getElementById('customModal').style.display = "flex";
        closeSidebarOnMobile();
    }

    function confirmDeleteReceipt(id) {
        document.getElementById('modalTitle').innerText = 'Delete Receipt';
        document.getElementById('modalMsg').innerText = "Delete this payment receipt permanently?";
        document.getElementById('modalReceiptId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = "delete_receipt";
        confirmBtn.style.background = "var(--danger)";
        confirmBtn.textContent = "Delete Receipt";
        document.getElementById('customModal').style.display = "flex";
        closeSidebarOnMobile();
    }

    function confirmRefund(id, amount) {
        document.getElementById('modalTitle').innerText = 'Confirm Refund';
        document.getElementById('modalMsg').innerText = `Refund $${amount} to Available Balance and remove this record?`;
        document.getElementById('modalTxId').value = id;
        
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = "refund_tx";
        confirmBtn.style.background = "var(--emerald)";
        confirmBtn.textContent = "Confirm Refund";
        
        document.getElementById('customModal').style.display = "flex";
        closeSidebarOnMobile();
    }

    function confirmDeleteUser(id, name) {
        document.getElementById('modalTitle').innerText = 'Delete User';
        document.getElementById('modalMsg').innerText = "Delete account for " + name + "?";
        document.getElementById('modalUserId').value = id;
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.name = "delete_user";
        confirmBtn.style.background = "var(--danger)";
        confirmBtn.textContent = "Delete User";
        document.getElementById('customModal').style.display = "flex";
        closeSidebarOnMobile();
    }

    function closeModal() { 
        document.getElementById('customModal').style.display = "none"; 
    }
    
    function openProfileModal() {
        document.getElementById('profileModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        closeSidebarOnMobile();
    }
    
    function closeProfileModal() {
        document.getElementById('profileModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    function openImageModal(imageUrl, title) {
        const modal = document.getElementById('imageModal');
        const modalImg = document.getElementById('modalImage');
        const modalTitle = document.getElementById('modalImageTitle');
        
        modalImg.src = imageUrl;
        modalTitle.textContent = title || 'Asset Image';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        closeSidebarOnMobile();
    }
    
    function closeImageModal() {
        const modal = document.getElementById('imageModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            document.getElementById('modalImage').src = '';
        }, 100);
    }
    
    function closeSidebarOnMobile() {
        if (window.innerWidth <= 1024) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeImageModal();
            closeProfileModal();
            closeModal();
            closeSidebarOnMobile();
        }
    });
    
    window.addEventListener('load', function() {
        const note = document.getElementById('notify');
        
        if(note && note.classList.contains('show')) {
            setTimeout(() => {
                note.classList.remove('show');
            }, 3000);

            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('msg');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            }
        }
        
        // Hide receipt edit modal if it exists and click outside
        const receiptModal = document.getElementById('receiptModal');
        if(receiptModal) {
            receiptModal.onclick = function(event) {
                if (event.target == receiptModal) {
                    window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>?uid=<?php echo $target_user; ?>';
                }
            }
        }
        
        // Close sidebar when clicking on a link in mobile
        document.querySelectorAll('.sidebar a, .sidebar button').forEach(el => {
            el.addEventListener('click', function() {
                if (window.innerWidth <= 1024) {
                    setTimeout(closeSidebarOnMobile, 100);
                }
            });
        });
        
        // Add data-label attributes to all table cells for responsive view
        document.querySelectorAll('.tx-table thead th').forEach((th, index) => {
            const label = th.textContent.trim();
            document.querySelectorAll('.tx-table tbody tr').forEach(row => {
                const cell = row.cells[index];
                if (cell) {
                    cell.setAttribute('data-label', label);
                }
            });
        });
        
        document.querySelectorAll('.receipt-table thead th').forEach((th, index) => {
            const label = th.textContent.trim();
            document.querySelectorAll('.receipt-table tbody tr').forEach(row => {
                const cell = row.cells[index];
                if (cell) {
                    cell.setAttribute('data-label', label);
                }
            });
        });
    });
    
    window.onclick = function(event) {
        const customModal = document.getElementById('customModal');
        const imageModal = document.getElementById('imageModal');
        const profileModal = document.getElementById('profileModal');
        
        if (event.target == customModal) {
            closeModal();
        }
        
        if (event.target == imageModal) {
            closeImageModal();
        }
        
        if (event.target == profileModal) {
            closeProfileModal();
        }
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    });
</script>

</body>
</html>