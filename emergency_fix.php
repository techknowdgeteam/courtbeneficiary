<?php
// emergency_fix.php
require 'db.php';

// Set all users' passwords to 'password123'
$new_hash = password_hash('password123', PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password = ?");
$stmt->execute([$new_hash]);

echo "All passwords have been reset to: <strong>password123</strong><br>";
echo "Number of users affected: " . $stmt->rowCount() . "<br>";
echo "<a href='admin.php'>Go to Admin Panel</a>";
?>