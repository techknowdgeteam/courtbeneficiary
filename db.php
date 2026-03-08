<?php
$host = 'sql100.infinityfree.com';
$db   = 'if0_41320379_inheritanace_system';
$user = 'if0_41320379'; // Your DB username
$pass = 'PsQ4FIkZWP3nczg';     // Your DB password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>