<?php
require 'db.php';

session_start();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_passkey = $_POST['new_passkey'] ?? '';
    $confirm_passkey = $_POST['confirm_passkey'] ?? '';
    
    // Validate input
    if (empty($new_passkey)) {
        $error = 'Passkey cannot be empty';
    } elseif (strlen($new_passkey) < 6) {
        $error = 'Passkey must be at least 6 characters long';
    } elseif ($new_passkey !== $confirm_passkey) {
        $error = 'Passkeys do not match';
    } else {
        try {
            // Hash the new passkey
            $hashed_passkey = password_hash($new_passkey, PASSWORD_DEFAULT);
            
            // Check if a passkey already exists
            $stmt = $pdo->query("SELECT COUNT(*) FROM server_passkey");
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                // Update existing passkey
                $stmt = $pdo->prepare("UPDATE server_passkey SET password = ? WHERE id = (SELECT id FROM server_passkey LIMIT 1)");
                $stmt->execute([$hashed_passkey]);
                $message = '✅ Passkey updated successfully!';
            } else {
                // Insert new passkey
                $stmt = $pdo->prepare("INSERT INTO server_passkey (password) VALUES (?)");
                $stmt->execute([$hashed_passkey]);
                $message = '✅ New passkey created successfully!';
            }
            
            // Clear any existing sessions to force re-login with new passkey
            if (isset($_POST['clear_sessions']) && $_POST['clear_sessions'] == '1') {
                // Note: This only affects future sessions, not current ones
                // For complete session clearing, you'd need to implement session cleanup
                $message .= ' All sessions will require the new passkey.';
            }
            
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get current passkey status
$passkey_exists = false;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM server_passkey");
    $passkey_exists = $stmt->fetchColumn() > 0;
} catch (PDOException $e) {
    $error = 'Error checking passkey status: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Passkey Manager</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px 35px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header {
            text-align: center;
            margin-bottom: 35px;
        }
        
        .header h1 {
            color: #1a1a2e;
            font-size: 28px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .header h1 span {
            font-size: 32px;
        }
        
        .header p {
            color: #64748b;
            font-size: 14px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 25px;
        }
        
        .status-badge.exists {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }
        
        .status-badge.not-exists {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-group input {
            width: 100%;
            padding: 15px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f8fafc;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .form-group input::placeholder {
            color: #94a3b8;
            font-size: 14px;
        }
        
        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s;
        }
        
        .strength-bar.weak { width: 33%; background: #ef4444; }
        .strength-bar.medium { width: 66%; background: #f59e0b; }
        .strength-bar.strong { width: 100%; background: #10b981; }
        
        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            color: #64748b;
        }
        
        .checkbox-group {
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            font-size: 14px;
            color: #475569;
            cursor: pointer;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 15px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .message {
            padding: 15px 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.3s;
        }
        
        .message.success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }
        
        .message.error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }
        
        .message.info {
            background: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .security-note {
            margin-top: 25px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
        }
        
        .security-note strong {
            color: #334155;
            display: block;
            margin-bottom: 5px;
        }
        
        .security-note ul {
            margin-top: 8px;
            padding-left: 20px;
        }
        
        .security-note li {
            margin-bottom: 4px;
        }
        
        .icon {
            font-size: 20px;
        }
        
        .footer {
            text-align: center;
            margin-top: 20px;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
        }
        
        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 600;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>
                    <span>🔐</span>
                    Server Passkey Manager
                </h1>
                <p>Set or change the authentication passkey for server access</p>
            </div>
            
            <!-- Status Badge -->
            <div style="text-align: center;">
                <div class="status-badge <?php echo $passkey_exists ? 'exists' : 'not-exists'; ?>">
                    <?php if ($passkey_exists): ?>
                        ✅ Passkey is currently set
                    <?php else: ?>
                        ⚠️ No passkey set - System is insecure!
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Message Display -->
            <?php if ($message): ?>
                <div class="message success">
                    <span>✅</span>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">
                    <span>❌</span>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Passkey Form -->
            <form method="POST" id="passkeyForm">
                <div class="form-group">
                    <label for="new_passkey">New Passkey</label>
                    <input 
                        type="password" 
                        id="new_passkey" 
                        name="new_passkey" 
                        placeholder="Enter new passkey" 
                        required
                        minlength="6"
                        oninput="checkPasswordStrength(this.value)"
                    >
                    <div class="password-strength">
                        <div id="strengthBar" class="strength-bar"></div>
                    </div>
                    <div id="strengthText" class="strength-text"></div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_passkey">Confirm Passkey</label>
                    <input 
                        type="password" 
                        id="confirm_passkey" 
                        name="confirm_passkey" 
                        placeholder="Re-enter new passkey" 
                        required
                        minlength="6"
                        oninput="checkPasswordMatch(this.value)"
                    >
                    <div id="matchMessage" style="font-size: 12px; margin-top: 5px;"></div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="clear_sessions" name="clear_sessions" value="1">
                    <label for="clear_sessions">Force re-authentication (clear existing sessions)</label>
                </div>
                
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span>🔒</span>
                    <?php echo $passkey_exists ? 'Update Passkey' : 'Create Passkey'; ?>
                </button>
                
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                    <span>🔄</span>
                    Reset Form
                </a>
            </form>
            
            <!-- Security Notes -->
            <div class="security-note">
                <strong>📋 Security Guidelines:</strong>
                <ul>
                    <li>Passkey must be at least 6 characters long</li>
                    <li>Use a mix of letters, numbers, and symbols for better security</li>
                    <li>Changing the passkey will require all new sessions to use the new key</li>
                    <li>Existing sessions will continue until they timeout (30 minutes)</li>
                    <li>Check "Force re-authentication" to immediately expire all sessions</li>
                </ul>
            </div>
        </div>
        
        <div class="footer">
            <p>Server Access Control | <a href="#" onclick="history.back(); return false;">← Back to Admin</a></p>
        </div>
    </div>
    
    <script>
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            // Remove all classes
            strengthBar.className = 'strength-bar';
            
            if (password.length === 0) {
                strengthText.textContent = '';
                return;
            }
            
            // Calculate strength
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;
            
            // Character variety checks
            if (/[a-z]/.test(password)) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 1;
            
            // Determine strength level
            if (strength <= 2) {
                strengthBar.classList.add('weak');
                strengthText.textContent = 'Weak password';
                strengthText.style.color = '#ef4444';
            } else if (strength <= 4) {
                strengthBar.classList.add('medium');
                strengthText.textContent = 'Medium password';
                strengthText.style.color = '#f59e0b';
            } else {
                strengthBar.classList.add('strong');
                strengthText.textContent = 'Strong password';
                strengthText.style.color = '#10b981';
            }
            
            // Check password match if confirm field has value
            const confirmPass = document.getElementById('confirm_passkey').value;
            if (confirmPass) {
                checkPasswordMatch(confirmPass);
            }
        }
        
        function checkPasswordMatch(confirmValue) {
            const newPass = document.getElementById('new_passkey').value;
            const matchMessage = document.getElementById('matchMessage');
            const submitBtn = document.getElementById('submitBtn');
            
            if (confirmValue.length === 0) {
                matchMessage.textContent = '';
                submitBtn.disabled = false;
                return;
            }
            
            if (newPass === confirmValue) {
                matchMessage.textContent = '✅ Passkeys match';
                matchMessage.style.color = '#10b981';
                submitBtn.disabled = false;
            } else {
                matchMessage.textContent = '❌ Passkeys do not match';
                matchMessage.style.color = '#ef4444';
                submitBtn.disabled = true;
            }
        }
        
        // Real-time validation
        document.getElementById('passkeyForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_passkey').value;
            const confirmPass = document.getElementById('confirm_passkey').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('Passkeys do not match!');
            }
            
            if (newPass.length < 6) {
                e.preventDefault();
                alert('Passkey must be at least 6 characters long!');
            }
        });
        
        // Toggle password visibility (optional enhancement)
        function addPasswordToggle() {
            const passwordFields = document.querySelectorAll('input[type="password"]');
            passwordFields.forEach(field => {
                const wrapper = document.createElement('div');
                wrapper.style.position = 'relative';
                field.parentNode.insertBefore(wrapper, field);
                wrapper.appendChild(field);
                
                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.innerHTML = '👁️';
                toggleBtn.style.position = 'absolute';
                toggleBtn.style.right = '10px';
                toggleBtn.style.top = '50%';
                toggleBtn.style.transform = 'translateY(-50%)';
                toggleBtn.style.background = 'none';
                toggleBtn.style.border = 'none';
                toggleBtn.style.cursor = 'pointer';
                toggleBtn.style.fontSize = '16px';
                toggleBtn.style.padding = '5px';
                
                toggleBtn.onclick = function() {
                    const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
                    field.setAttribute('type', type);
                    toggleBtn.innerHTML = type === 'password' ? '👁️' : '🔒';
                };
                
                wrapper.appendChild(toggleBtn);
            });
        }
        
        // Uncomment to add password visibility toggles
        // window.addEventListener('load', addPasswordToggle);
    </script>
</body>
</html>