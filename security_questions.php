<?php
// security_questions.php
session_start();
require 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: bnc.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user's security questions
$stmt = $pdo->prepare("SELECT brief_interview FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

$questions = [];
if ($user_data && !empty($user_data['brief_interview'])) {
    $questions = json_decode($user_data['brief_interview'], true);
    if (!is_array($questions)) $questions = [];
}

// If no questions, redirect to dashboard
if (empty($questions)) {
    $_SESSION['security_verified'] = true;
    unset($_SESSION['security_question_index']);
    header("Location: bnc.php");
    exit;
}

// Handle answer submission
$error_message = '';
$current_index = isset($_SESSION['security_question_index']) ? (int)$_SESSION['security_question_index'] : 0;

// If current index is out of bounds, reset
if ($current_index >= count($questions)) {
    $_SESSION['security_verified'] = true;
    unset($_SESSION['security_question_index']);
    header("Location: bnc.php");
    exit;
}

if (isset($_POST['submit_answer'])) {
    $answer = trim($_POST['answer'] ?? '');
    $index = (int)$_POST['question_index'];
    
    // Verify the answer matches (case-insensitive)
    $expected_answer = $questions[$index]['answer'] ?? '';
    
    if (strtolower(trim($answer)) === strtolower(trim($expected_answer))) {
        // Correct answer - move to next question
        $current_index = $index + 1;
        $_SESSION['security_question_index'] = $current_index;
        
        // If all questions answered, mark as verified and redirect
        if ($current_index >= count($questions)) {
            $_SESSION['security_verified'] = true;
            unset($_SESSION['security_question_index']);
            
            // Redirect with a success message
            header("Location: bnc.php?msg=Security verification completed successfully!&type=success");
            exit;
        }
        
        // Reload page to show next question
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        // Wrong answer - show error and log out
        session_destroy();
        header("Location: bnc.php?msg=Security verification failed. Please log in again.&type=error");
        exit;
    }
}

// Get current question
$current_question = $questions[$current_index] ?? null;

// If no question at current index, redirect
if (!$current_question) {
    $_SESSION['security_verified'] = true;
    unset($_SESSION['security_question_index']);
    header("Location: bnc.php");
    exit;
}

$is_last = ($current_index >= count($questions) - 1);
$button_text = $is_last ? 'Continue' : 'Next';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Security Verification</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8f9fa;
            --bg-card: #ffffff;
            --text-primary: #1a1a2e;
            --text-secondary: #4a4a6a;
            --text-muted: #8888a8;
            --border-color: #e8e8ee;
            --shadow-color: rgba(0,0,0,0.04);
            --accent-color: #2d6a4f;
            --accent-hover: #1a4f3a;
            --accent-light: #f0f5f2;
            --danger-color: #dc3545;
            --input-bg: #f8f9fa;
            --card-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        
        [data-theme="dark"] {
            --bg-primary: #0f0f1a;
            --bg-secondary: #1a1a2e;
            --bg-card: #1e1e32;
            --text-primary: #e8e8f0;
            --text-secondary: #b0b0c8;
            --text-muted: #707090;
            --border-color: #2a2a44;
            --shadow-color: rgba(0,0,0,0.3);
            --accent-color: #4caf7a;
            --accent-hover: #66d09a;
            --accent-light: #1a2e24;
            --danger-color: #ef5350;
            --input-bg: #2a2a44;
            --card-shadow: 0 2px 12px rgba(0,0,0,0.2);
        }
        
        html, body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            transition: background 0.3s, color 0.3s;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            width: 100%;
            max-width: 480px;
            padding: 20px;
        }
        
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: var(--card-shadow);
            transition: background 0.3s, border-color 0.3s;
            text-align: center;
        }
        
        .card .icon {
            font-size: 56px;
            margin-bottom: 16px;
        }
        
        .card h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .card .subtitle {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .card .question-counter {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        
        .card .question-text {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            padding: 20px;
            background: var(--accent-light);
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--accent-color);
            background: var(--bg-card);
        }
        
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: var(--accent-color);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-primary:hover {
            background: var(--accent-hover);
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
            border: 1px solid #f5c6cb;
        }
        
        [data-theme="dark"] .error-message {
            background: #2a1a1a;
            color: #ef5350;
            border-color: #3a2a2a;
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--border-color);
            border-radius: 2px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .progress-bar .progress-fill {
            height: 100%;
            background: var(--accent-color);
            border-radius: 2px;
            transition: width 0.5s ease;
        }
        
        @media (max-width: 480px) {
            .card {
                padding: 30px 20px;
            }
            
            .card .question-text {
                font-size: 17px;
                padding: 16px;
                min-height: 60px;
            }
            
            .card h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($_SESSION['theme_mode'] ?? 'white'); ?>">
    <div class="container">
        <div class="card">
            <div class="icon">🔐</div>
            <h1>Security Verification</h1>
            <p class="subtitle">Please answer the following security question to continue</p>
            
            <div class="question-counter">
                Question <?php echo $current_index + 1; ?> of <?php echo count($questions); ?>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo (($current_index + 1) / count($questions)) * 100; ?>%;"></div>
            </div>
            
            <div class="question-text">
                <?php echo htmlspecialchars($current_question['question'] ?? 'Question not found'); ?>
            </div>
            
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="question_index" value="<?php echo $current_index; ?>">
                <div class="form-group">
                    <label>Your Answer</label>
                    <input type="text" name="answer" placeholder="Type your answer here..." required autofocus>
                </div>
                <button type="submit" name="submit_answer" class="btn-primary">
                    <?php echo $button_text; ?> →
                </button>
            </form>
        </div>
    </div>
    
    <script>
        // Auto-focus the input on load
        document.addEventListener('DOMContentLoaded', function() {
            const input = document.querySelector('input[name="answer"]');
            if (input) input.focus();
        });
        
        // Enter key submit
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const form = document.querySelector('form');
                if (form) form.submit();
            }
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>