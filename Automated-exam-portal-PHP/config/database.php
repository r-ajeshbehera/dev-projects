<?php
// Sample configuration file for Auto Question Generator
// Copy this file to config.php and update the values below

define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'automated_exam_portal');
define('OPENAI_API_KEY', 'your_openai_api_key');

function db_connect() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }
    return $conn;
}
?>