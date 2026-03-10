<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration
require_once __DIR__ . '/../config/config.php';

// Regenerate session ID to prevent session fixation
session_regenerate_id(true);

// Unset all session variables
$_SESSION = array();

// Destroy the session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Finally destroy the session
session_destroy();

// Clear any existing output
if (ob_get_length()) {
    ob_end_clean();
}

// Redirect to login page with success message
header('Location: ' . SITE_URL . '/auth/login.php?success=' . urlencode('Logged out successfully'));
exit();
?>