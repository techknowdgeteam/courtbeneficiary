<?php
$host = 'sql209.infinityfree.com';
$db   = 'if0_41321983_inheritance_system';
$user = 'if0_41321983'; // Your DB username
$pass = '1gdpHEUtjFvyC';     // Your DB password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>