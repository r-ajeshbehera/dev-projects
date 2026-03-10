<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_teacher() {
    return is_logged_in() && $_SESSION['role'] === 'teacher';
}

function is_student() {
    return is_logged_in() && $_SESSION['role'] === 'student';
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . SITE_URL . 'auth/login.php?error=Please+login+first');
        exit;
    }
}

function require_teacher() {
    require_login();
    if (!is_teacher()) {
        header('Location: ' . SITE_URL . 'student/dashboard.php?error=Access+denied');
        exit;
    }
}

function require_student() {
    require_login();
    if (!is_student()) {
        header('Location: ' . SITE_URL . 'teacher/dashboard.php?error=Access+denied');
        exit;
    }
}
?>