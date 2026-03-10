<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];

date_default_timezone_set('UTC');

$stmt = $conn->prepare("SELECT username, email, profile_photo, created_at FROM users WHERE id = ?");
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$teacher_result = $stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
$teacher_name = $teacher['username'];
$teacher_email = $teacher['email'] ?? '';
$profile_photo = $teacher['profile_photo'] ?? null;
$join_date = $teacher['created_at'] ? date('F j, Y', strtotime($teacher['created_at'])) : 'Unknown';
$stmt->close();

$questions_stmt = $conn->prepare("
    SELECT q.id, q.topic, q.question_text, q.exam_id, e.title AS exam_title 
    FROM questions q 
    LEFT JOIN exams e ON q.exam_id = e.id 
    WHERE q.teacher_id = ?
");
$questions_stmt->bind_param('i', $teacher_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();

$exams_stmt = $conn->prepare("SELECT id, title, status, created_at, deadline, start_at FROM exams WHERE teacher_id = ? ORDER BY created_at DESC");
$exams_stmt->bind_param('i', $teacher_id);
$exams_stmt->execute();
$exams_result = $exams_stmt->get_result();

$total_exams = $exams_result->num_rows;
$draft_exams = 0;
$published_exams = 0;
$draft_exams_array = [];
$exams_result->data_seek(0);
while ($exam = $exams_result->fetch_assoc()) {
    if ($exam['status'] === 'draft') {
        $draft_exams++;
        $draft_exams_array[] = $exam;
    } elseif ($exam['status'] === 'published' || $exam['status'] === 'republished') {
        $published_exams++;
    }
}
$exams_result->data_seek(0);

$total_questions = $questions_result->num_rows;
$assigned_questions = 0;
$new_questions = 0;
$questions_result->data_seek(0);
while ($row = $questions_result->fetch_assoc()) {
    if ($row['exam_id']) {
        $assigned_questions++;
    } else {
        $new_questions++;
    }
}

$password_message = '';
$profile_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $pwd_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $pwd_stmt->bind_param('i', $teacher_id);
    $pwd_stmt->execute();
    $pwd_result = $pwd_stmt->get_result()->fetch_assoc();
    $current_password_hash = $pwd_result['password'];

    if (!password_verify($old_password, $current_password_hash)) {
        $password_message = '<div class="alert alert-danger" id="alertMessage">Old password is incorrect.</div>';
    } elseif ($new_password !== $confirm_password) {
        $password_message = '<div class="alert alert-danger" id="alertMessage">New password and confirmation do not match.</div>';
    } elseif (strlen($new_password) < 6) {
        $password_message = '<div class="alert alert-danger" id="alertMessage">New password must be at least 6 characters long.</div>';
    } else {
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param('si', $new_password_hash, $teacher_id);
        if ($update_stmt->execute()) {
            $password_message = '<div class="alert alert-success" id="alertMessage">Password updated successfully!</div>';
        } else {
            $password_message = '<div class="alert alert-danger" id="alertMessage">Failed to update password. Please try again.</div>';
        }
        $update_stmt->close();
    }
    $pwd_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $upload_ok = true;
    $new_profile_photo = $profile_photo;

    if (empty($new_username)) {
        $profile_message = '<div class="alert alert-danger" id="alertMessage">Username is required.</div>';
        $upload_ok = false;
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL) && !empty($new_email)) {
        $profile_message = '<div class="alert alert-danger" id="alertMessage">Invalid email format.</div>';
        $upload_ok = false;
    }

    if ($upload_ok && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['size'] > 0) {
        $target_dir = __DIR__ . '/../assets/uploads/profiles/';
        $file_ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png'];
        $new_filename = "teacher_{$teacher_id}_" . time() . ".{$file_ext}";
        $target_file = $target_dir . $new_filename;

        if (!in_array($file_ext, $allowed_exts)) {
            $profile_message = '<div class="alert alert-danger" id="alertMessage">Only JPG, JPEG, and PNG files are allowed.</div>';
            $upload_ok = false;
        } elseif ($_FILES['profile_photo']['size'] > 2000000) {
            $profile_message = '<div class="alert alert-danger" id="alertMessage">File size must be less than 2MB.</div>';
            $upload_ok = false;
        } else {
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_file)) {
                $new_profile_photo = "assets/uploads/profiles/{$new_filename}";
                if ($profile_photo && file_exists(__DIR__ . '/../' . $profile_photo)) {
                    unlink(__DIR__ . '/../' . $profile_photo);
                }
            } else {
                $profile_message = '<div class="alert alert-danger" id="alertMessage">Failed to upload profile photo.</div>';
                $upload_ok = false;
            }
        }
    }

    if ($upload_ok) {
        $update_stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, profile_photo = ? WHERE id = ?");
        $update_stmt->bind_param('sssi', $new_username, $new_email, $new_profile_photo, $teacher_id);
        if ($update_stmt->execute()) {
            $profile_message = '<div class="alert alert-success" id="alertMessage">Profile updated successfully!</div>';
            $teacher_name = $new_username;
            $teacher_email = $new_email;
            $profile_photo = $new_profile_photo;
        } else {
            $profile_message = '<div class="alert alert-danger" id="alertMessage">Failed to update profile. Please try again.</div>';
        }
        $update_stmt->close();
    }
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP - Teacher Dashboard </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #eef2ff, #d9e2ff);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            margin: 0;
        }
        .custom-header {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            padding: 15px 30px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: margin-left 0.3s ease, width 0.3s ease;
            width: 100%;
        }
        .header-logo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .menu-button {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s ease;
            margin-left: 10px;
        }
        .menu-button:hover {
            transform: scale(1.1);
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: #2c3e50;
            color: #fff;
            transition: width 0.3s ease;
            z-index: 1000;
            padding: 0;
            border-radius: 0 10px 10px 0;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .sidebar.open {
            width: 250px;
            padding: 20px;
        }
        .sidebar-close {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s ease;
        }
        .sidebar-close:hover {
            background: #34495e;
        }
        .sidebar-profile {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        .sidebar-profile img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            margin-bottom: 10px;
        }
        .sidebar-profile h4 {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        .sidebar-menu li {
            margin-bottom: 10px;
        }
        .sidebar-menu a {
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            display: block;
            padding: 10px 15px;
            border-radius: 5px;
            transition: background 0.3s ease;
        }
        .sidebar-menu a:hover {
            background: #34495e;
        }
        .header-title {
            color: #fff;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0 15px;
            flex-grow: 1;
        }
        .header-logout {
            background: #fff;
            color: #6e8efb;
            border-radius: 20px;
            padding: 8px 20px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .header-logout:hover {
            background: #f5f7fa;
            color: #5a75e3;
        }
        .container {
            transition: margin-left 0.3s ease, width 0.3s ease;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        .footer {
            transition: margin-left 0.3s ease, width 0.3s ease;
            background: #2c3e50;
            color: #fff;
            text-align: center;
            padding: 15px 0;
            margin-top: auto;
            width: 100%;
        }
        .content-shift {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
        }
        .content-full {
            margin-left: 0 !important;
            width: 100% !important;
            margin: 0 auto !important;
        }
        .profile-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        .profile-photo:hover {
            transform: scale(1.05);
        }
        .dashboard-header {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 40px;
            animation: slideIn 0.5s ease-in-out;
            text-align: center;
        }
        .welcome-text {
            color: #2c3e50;
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background: #fff;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            border-radius: 20px 20px 0 0;
            font-weight: 600;
            padding: 15px 20px;
            font-size: 1.2rem;
        }
        .profile-card-body {
            padding: 30px;
            background: #f9f9f9;
            border-radius: 0 0 20px 20px;
        }
        .profile-form input, .profile-form select {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 10px;
            font-size: 1rem;
        }
        .profile-form label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        .profile-photo-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-top: 10px;
            display: none;
        }
        .btn-custom {
            border-radius: 30px;
            padding: 12px 28px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            transform: scale(1.08);
        }
        .btn-primary {
            background: #6e8efb;
            border: none;
        }
        .btn-primary:hover {
            background: #5a75e3;
        }
        .btn-success {
            background: #28a745;
            border: none;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-danger {
            background: #dc3545;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-republish {
            background: #fd7e14;
            border: none;
        }
        .btn-republish:hover {
            background: #e06c00;
        }
        .btn-info {
            background: #17a2b8;
            border: none;
        }
        .btn-info:hover {
            background: #138496;
        }
        .btn-outline-secondary {
            border-color: #6e8efb;
            color: #6e8efb;
        }
        .btn-outline-secondary:hover {
            background: #6e8efb;
            color: #fff;
        }
        .btn-sm {
            padding: 5px 15px;
            font-size: 0.9rem;
        }
        .table {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }
        .table thead th {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            border: none;
            padding: 15px;
        }
        .table tbody tr {
            transition: background 0.3s ease;
        }
        .table tbody tr:hover {
            background: #f5f7fa;
        }
        .status-draft {
            color: #ffc107;
            font-weight: bold;
        }
        .status-republished {
            color: #fd7e14;
            font-weight: bold;
        }
        .status-published {
            color: #28a745;
            font-weight: bold;
        }
        .alert {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: 90%;
            max-width: 600px;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            animation: fadeIn 0.5s ease-in-out;
        }
        .stats-box {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .stats-box:hover {
            transform: translateY(-5px);
            cursor: pointer;
        }
        .stats-box h5 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .stats-box p {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        .total-exams { color: #6e8efb; }
        .draft-exams { color: #ffc107; }
        .published-exams { color: #28a745; }
        .total-questions { color: #17a2b8; }
        .assigned-questions { color: #dc3545; }
        .new-questions { color: #6f42c1; }
        .scroll-buttons {
            position: fixed;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1000;
        }
        .scroll-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        .exam-link {
            color: #6e8efb;
            text-decoration: none;
            font-weight: bold;
        }
        .exam-link:hover {
            text-decoration: underline;
            color: #5a75e3;
        }
        .exam-details-row {
            background: #f9f9f9;
            transition: all 0.3s ease;
        }
        .exam-details-content {
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }
        .details-header {
            border-bottom: 2px solid #6e8efb;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .details-header h5 {
            color: #2c3e50;
            font-weight: 600;
            margin: 0;
        }
        .details-body {
            display: grid;
            gap: 15px;
        }
        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
            min-width: 150px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .question-list {
            margin: 0;
            padding-left: 20px;
            color: #333;
        }
        .question-list li {
            margin-bottom: 5px;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @media (max-width: 768px) {
            .custom-header {
                padding: 10px 20px;
                flex-wrap: wrap;
            }
            .header-logo {
                width: 40px;
                height: 40px;
            }
            .header-title {
                font-size: 1.2rem;
            }
            .header-logout {
                padding: 6px 15px;
                font-size: 0.9rem;
            }
            .sidebar.open {
                width: 200px;
            }
            .content-shift {
                margin-left: 200px !important;
                width: calc(100% - 200px) !important;
            }
            .content-full {
                margin-left: 0 !important;
                width: 100% !important;
                margin: 0 auto !important;
            }
            .sidebar-profile img {
                width: 80px;
                height: 80px;
            }
            .sidebar-profile h4 {
                font-size: 1rem;
            }
            .dashboard-header { padding: 20px; }
            .profile-photo { width: 80px; height: 80px; }
            .btn-custom { width: 100%; margin: 10px 0; }
            .table { font-size: 0.85rem; }
            .stats-box { margin-bottom: 20px; }
            .scroll-buttons { right: 10px; }
            .scroll-btn { width: 40px; height: 40px; font-size: 1.2rem; }
            .detail-item { flex-direction: column; align-items: flex-start; }
            .detail-label { min-width: auto; }
        }
    </style>
</head>
<body>
    <header class="custom-header content-full" id="header">
        <div style="display: flex; align-items: center;">
            <img src="<?php echo SITE_URL; ?>/assets/img/logo.jpg" alt="AEP Logo" class="header-logo">
            <button class="menu-button" id="menuToggle"><i class="bi bi-list"></i></button>
        </div>
        <h1 class="header-title">Teacher Dashboard</h1>
        <a href="<?php echo SITE_URL; ?>/auth/logout.php" class="header-logout">Logout</a>
    </header>

    <div class="sidebar" id="sidebar">
        <button class="sidebar-close" id="sidebarClose"><i class="bi bi-x"></i></button>
        <div class="sidebar-profile">
            <img src="<?php echo $profile_photo ? SITE_URL . '/' . $profile_photo : 'https://via.placeholder.com/100'; ?>" alt="Profile Photo">
            <h4><?php echo htmlspecialchars($teacher_name); ?> Sir.</h4>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
            <li><a href="#" id="profileLink"><i class="bi bi-person-circle"></i> Profile</a></li>
            <li><a href="generate_questions.php"><i class="bi bi-plus-circle"></i> Generate/Add Questions</a></li>
            <li><a href="create_exam.php"><i class="bi bi-file-earmark-plus"></i> Create Exam</a></li>
            <li><a href="view_results.php"><i class="bi bi-bar-chart"></i> View Results</a></li>
            <li><a href="#" id="passwordLink"><i class="bi bi-key"></i> Change Password</a></li>
            <li><a href="<?php echo SITE_URL; ?>/auth/logout.php" onclick="return confirm('Are you sure you want to logout?');"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
    </div>

    <div class="container mt-5 content-full" id="mainContainer">
        <div class="dashboard-header">
            <img src="<?php echo $profile_photo ? SITE_URL . '/' . $profile_photo : 'https://via.placeholder.com/100'; ?>" alt="Profile Photo" class="profile-photo">
            <h2 class="welcome-text">Welcome, <?php echo htmlspecialchars($teacher_name); ?> Sir</h2>
            <p class="text-muted">Manage Your Questions and Exams</p>
            <button class="btn btn-outline-secondary btn-custom m-2" type="button" data-bs-toggle="collapse" data-bs-target="#profileCollapse" aria-expanded="false" aria-controls="profileCollapse">
                <i class="bi bi-person-circle"></i> My Profile
            </button>
            <div class="collapse mt-4" id="profileCollapse">
                <div class="card">
                    <div class="card-header">My Profile</div>
                    <div class="card-body profile-card-body">
                        <div class="mb-4">
                            <h5 class="mb-3">Teacher Details</h5>
                            <form method="POST" enctype="multipart/form-data" class="profile-form" id="profileForm">
                                <input type="hidden" name="update_profile" value="1">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($teacher_name); ?>" disabled required>
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($teacher_email); ?>" placeholder="Enter email" disabled>
                                </div>
                                <div class="mb-3">
                                    <label for="profile_photo" class="form-label">Profile Photo</label>
                                    <input type="file" name="profile_photo" id="profile_photo" class="form-control" accept="image/jpeg,image/png" disabled>
                                    <img id="photo_preview" class="profile-photo-preview" alt="Photo Preview">
                                </div>
                                <button type="button" class="btn btn-warning btn-custom" id="editProfileBtn"><i class="bi bi-pencil"></i> Edit Profile</button>
                                <button type="submit" class="btn btn-primary btn-custom" id="updateProfileBtn" disabled><i class="bi bi-save"></i> Update Profile</button>
                            </form>
                        </div>
                        <button class="btn btn-primary btn-custom mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#passwordCollapse" aria-expanded="false" aria-controls="passwordCollapse">
                            <i class="bi bi-key"></i> Change Password
                        </button>
                        <div class="collapse mt-3" id="passwordCollapse">
                            <h5 class="mb-3">Change Password</h5>
                            <form method="POST">
                                <input type="hidden" name="change_password" value="1">
                                <div class="mb-3">
                                    <label for="old_password" class="form-label">Old Password</label>
                                    <input type="password" name="old_password" id="old_password" class="form-control" placeholder="Enter current password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Enter new password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm new password" required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-custom"><i class="bi bi-key"></i> Update Password</button>
                            </form>
                        </div>
                        <a href="#" class="btn btn-secondary btn-custom mt-3" data-bs-toggle="collapse" data-bs-target="#profileCollapse">Back</a>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <a href="generate_questions.php" class="btn btn-primary btn-custom m-2"><i class="bi bi-plus-circle"></i> Add/Generate Questions</a>
                <a href="create_exam.php" class="btn btn-success btn-custom m-2"><i class="bi bi-file-earmark-plus"></i> Create Exam</a>
                <a href="view_results.php" class="btn btn-warning btn-custom m-2"><i class="bi bi-bar-chart"></i> View Results</a>
                <a href="<?php echo SITE_URL; ?>/auth/logout.php" class="btn btn-outline-secondary btn-custom m-2"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>

        <?php echo $password_message; ?>
        <?php echo $profile_message; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success" id="alertMessage"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger" id="alertMessage"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12 mb-4">
                <div class="row">
                    <div class="col-md-2 col-6">
                        <a href="#examsTable" class="stats-box" data-filter="all" onclick="filterExams('all')">
                            <h5>Total Exams</h5>
                            <p class="total-exams"><?php echo $total_exams; ?></p>
                        </a>
                    </div>
                    <div class="col-md-2 col-6">
                        <a href="#examsTable" class="stats-box" data-filter="draft" onclick="filterExams('draft')">
                            <h5>Draft Exams</h5>
                            <p class="draft-exams"><?php echo $draft_exams; ?></p>
                        </a>
                    </div>
                    <div class="col-md-2 col-6">
                        <a href="#examsTable" class="stats-box" data-filter="published" onclick="filterExams('published')">
                            <h5>Published Exams</h5>
                            <p class="published-exams"><?php echo $published_exams; ?></p>
                        </a>
                    </div>
                    <div class="col-md-2 col-6">
                        <a href="#questionsTable" class="stats-box" data-filter="all" onclick="filterQuestions('all')">
                            <h5>Total Questions</h5>
                            <p class="total-questions"><?php echo $total_questions; ?></p>
                        </a>
                    </div>
                    <div class="col-md-2 col-6">
                        <a href="#questionsTable" class="stats-box" data-filter="assigned" onclick="filterQuestions('assigned')">
                            <h5>Assigned Questions</h5>
                            <p class="assigned-questions"><?php echo $assigned_questions; ?></p>
                        </a>
                    </div>
                    <div class="col-md-2 col-6">
                        <a href="#questionsTable" class="stats-box" data-filter="new" onclick="filterQuestions('new')">
                            <h5>New Questions</h5>
                            <p class="new-questions"><?php echo $new_questions; ?></p>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">Your Questions</div>
                    <div class="card-body">
                        <form method="POST" action="assign_questions.php">
                            <div class="mb-3">
                                <?php if ($draft_exams > 0): ?>
                                    <select name="exam_id" class="form-select d-inline-block w-auto me-2" required>
                                        <option value="">Select Draft Exam</option>
                                        <?php foreach ($draft_exams_array as $draft_exam): ?>
                                            <option value="<?php echo $draft_exam['id']; ?>">
                                                <?php echo htmlspecialchars($draft_exam['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-success btn-custom" id="assignButton" disabled>
                                        <i class="bi bi-check-circle"></i> Assign Selected to Exam
                                    </button>
                                <?php else: ?>
                                    <p class="text-muted">No draft exams available to assign questions.</p>
                                <?php endif; ?>
                            </div>
                            <table class="table table-hover" id="questionsTable">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="select-all" onclick="toggleSelectAll()"></th>
                                        <th>#</th>
                                        <th>Topic</th>
                                        <th>Question Text</th>
                                        <th>Exam</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $question_index = 1;
                                    $questions_result->data_seek(0);
                                    while ($row = $questions_result->fetch_assoc()): 
                                        $display_style = ($question_index > 10) ? 'style="display: none;"' : '';
                                        $is_assigned = $row['exam_id'] ? true : false;
                                    ?>
                                        <tr class="question-row" data-assigned="<?php echo $is_assigned ? 'yes' : 'no'; ?>" <?php echo $display_style; ?>>
                                            <td>
                                                <input type="checkbox" name="question_ids[]" value="<?php echo $row['id']; ?>" class="question-checkbox">
                                            </td>
                                            <td><?php echo $question_index++; ?></td>
                                            <td><?php echo htmlspecialchars($row['topic']); ?></td>
                                            <td><?php echo htmlspecialchars($row['question_text']); ?></td>
                                            <td>
                                                <?php 
                                                if ($is_assigned) {
                                                    echo "Assigned to " . htmlspecialchars($row['exam_title']);
                                                } else {
                                                    echo 'Not Assigned';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <a href="edit_question.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i> Edit</a>
                                                <a href="delete_question.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?');"><i class="bi bi-trash"></i> Delete</a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <?php if ($total_questions > 10): ?>
                                <button type="button" class="btn btn-info btn-custom mt-3" id="showMoreQuestions">Show More</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">Your Exams</div>
                    <div class="card-body">
                        <table class="table table-hover" id="examsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Start At</th>
                                    <th>Deadline</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $exam_index = 1;
                                $ist_timezone = new DateTimeZone('Asia/Kolkata');
                                $exams_result->data_seek(0);
                                while ($exam = $exams_result->fetch_assoc()): 
                                    $deadline = $exam['deadline'] ? (new DateTime($exam['deadline'], new DateTimeZone('UTC')))->setTimezone($ist_timezone)->format('F j, Y g:i A') : 'No Deadline';
                                    $start_at = $exam['start_at'] ? (new DateTime($exam['start_at'], new DateTimeZone('UTC')))->setTimezone($ist_timezone)->format('F j, Y g:i A') : 'Immediate';
                                    $status_class = ($exam['status'] === 'draft') ? 'status-draft' : (($exam['status'] === 'republished') ? 'status-republished' : 'status-published');
                                    $display_style = ($exam_index > 10) ? 'style="display: none;"' : '';
                                    $is_draft = ($exam['status'] === 'draft');
                                    $is_published = ($exam['status'] === 'published' || $exam['status'] === 'republished');

                                    $exam_details = [];
                                    if ($is_published) {
                                        $q_stmt = $conn->prepare("
                                            SELECT q.id, q.question_text 
                                            FROM questions q 
                                            WHERE q.exam_id = ? OR q.id IN (SELECT question_id FROM exam_questions WHERE exam_id = ?)
                                        ");
                                        $q_stmt->bind_param('ii', $exam['id'], $exam['id']);
                                        $q_stmt->execute();
                                        $q_result = $q_stmt->get_result();
                                        $exam_details['question_count'] = $q_result->num_rows;
                                        $exam_details['questions'] = [];
                                        while ($q = $q_result->fetch_assoc()) {
                                            $exam_details['questions'][] = $q['question_text'];
                                        }
                                        $q_stmt->close();

                                        $avg_stmt = $conn->prepare("
                                            SELECT AVG(score) as avg_score 
                                            FROM (
                                                SELECT es.id, 
                                                       (SUM(CASE WHEN qo.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(q.id)) * 100 as score
                                                FROM exam_submissions es
                                                JOIN student_answers sa ON es.id = sa.submission_id
                                                JOIN questions q ON sa.question_id = q.id
                                                JOIN question_options qo ON sa.selected_option_id = qo.id
                                                WHERE es.exam_id = ?
                                                GROUP BY es.id
                                            ) as scores
                                        ");
                                        $avg_stmt->bind_param('i', $exam['id']);
                                        $avg_stmt->execute();
                                        $avg_result = $avg_stmt->get_result()->fetch_assoc();
                                        $exam_details['avg_score'] = $avg_result['avg_score'] ? round($avg_result['avg_score'], 2) : 'N/A';
                                        $avg_stmt->close();
                                    }
                                ?>
                                    <tr class="exam-row" data-status="<?php echo $exam['status']; ?>" <?php echo $display_style; ?>>
                                        <td><?php echo $exam_index++; ?></td>
                                        <td>
                                            <?php if ($is_published): ?>
                                                <a href="#" class="exam-link" onclick="toggleExamDetails('exam-details-<?php echo $exam['id']; ?>'); return false;"><?php echo htmlspecialchars($exam['title']); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($exam['title']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="<?php echo $status_class; ?>"><?php echo htmlspecialchars($exam['status']); ?></td>
                                        <td><?php echo $start_at; ?></td>
                                        <td><?php echo $deadline; ?></td>
                                        <td>
                                            <?php if ($is_draft): ?>
                                                <a href="manage_exam.php?id=<?php echo $exam['id']; ?>" class="btn btn-sm btn-info"><i class="bi bi-gear"></i> Manage</a>
                                                <a href="delete_exam.php?id=<?php echo $exam['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this exam?');"><i class="bi bi-trash"></i> Delete</a>
                                                <?php
                                                $history_stmt = $conn->prepare("SELECT COUNT(*) FROM exams WHERE id = ? AND teacher_id = ? AND status IN ('published', 'republished')");
                                                $history_stmt->bind_param('ii', $exam['id'], $teacher_id);
                                                $history_stmt->execute();
                                                $history_stmt->bind_result($has_history);
                                                $history_stmt->fetch();
                                                $history_stmt->close();
                                                ?>
                                                <?php if ($has_history): ?>
                                                    <a href="publish_exam.php?id=<?php echo $exam['id']; ?>&action=republish" class="btn btn-sm btn-republish"><i class="bi bi-upload"></i> Republish</a>
                                                <?php else: ?>
                                                    <a href="publish_exam.php?id=<?php echo $exam['id']; ?>&action=publish" class="btn btn-sm btn-success"><i class="bi bi-upload"></i> Publish</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($is_published): ?>
                                        <tr id="exam-details-<?php echo $exam['id']; ?>" class="exam-details-row" style="display: none;">
                                            <td colspan="6">
                                                <div class="exam-details-content">
                                                    <div class="details-header">
                                                        <h5><i class="bi bi-info-circle"></i> Exam Details: <?php echo htmlspecialchars($exam['title']); ?></h5>
                                                    </div>
                                                    <div class="details-body">
                                                        <div class="detail-item">
                                                            <span class="detail-label"><i class="bi bi-flag"></i> Status:</span>
                                                            <span class="<?php echo $exam['status'] === 'published' ? 'status-published' : 'status-republished'; ?>">
                                                                <?php echo htmlspecialchars($exam['status']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="detail-item">
                                                            <span class="detail-label"><i class="bi bi-clock"></i> Start Time:</span>
                                                            <span><?php echo htmlspecialchars($start_at); ?></span>
                                                        </div>
                                                        <div class="detail-item">
                                                            <span class="detail-label"><i class="bi bi-hourglass-split"></i> Deadline:</span>
                                                            <span><?php echo htmlspecialchars($deadline); ?></span>
                                                        </div>
                                                        <div class="detail-item">
                                                            <span class="detail-label"><i class="bi bi-question-circle"></i> Number of Questions:</span>
                                                            <span><?php echo $exam_details['question_count']; ?></span>
                                                        </div>
                                                        <div class="detail-item">
                                                            <span class="detail-label"><i class="bi bi-list-ul"></i> Assigned Questions:</span>
                                                            <ul class="question-list">
                                                                <?php foreach ($exam_details['questions'] as $index => $question): ?>
                                                                    <li><?php echo ($index + 1) . ". " . htmlspecialchars($question); ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                        <div class="detail-item">
                                                            <span class="detail-label"><i class="bi bi-graph-up"></i> Average Class Result:</span>
                                                            <span><?php echo $exam_details['avg_score'] === 'N/A' ? 'N/A' : $exam_details['avg_score'] . '%'; ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php if ($total_exams > 10): ?>
                            <button type="button" class="btn btn-info btn-custom mt-3" id="showMoreExams">Show More</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="scroll-buttons">
            <button class="btn btn-primary scroll-btn" id="goUpBtn"><i class="bi bi-arrow-up"></i></button>
            <button class="btn btn-primary scroll-btn" id="goDownBtn"><i class="bi bi-arrow-down"></i></button>
        </div>
    </div>

    <footer class="footer content-full" id="footer">
        <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.remove(); }, 500);
            }, 2000);
        }

        // Sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const sidebar = document.getElementById('sidebar');
        const header = document.getElementById('header');
        const mainContainer = document.getElementById('mainContainer');
        const footer = document.getElementById('footer');

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            header.classList.toggle('content-shift');
            header.classList.toggle('content-full');
            mainContainer.classList.toggle('content-shift');
            mainContainer.classList.toggle('content-full');
            footer.classList.toggle('content-shift');
            footer.classList.toggle('content-full');
        });

        sidebarClose.addEventListener('click', function() {
            sidebar.classList.remove('open');
            header.classList.remove('content-shift');
            header.classList.add('content-full');
            mainContainer.classList.remove('content-shift');
            mainContainer.classList.add('content-full');
            footer.classList.remove('content-shift');
            footer.classList.add('content-full');
        });

        // Profile link handling
        const profileLink = document.getElementById('profileLink');
        profileLink.addEventListener('click', function(e) {
            e.preventDefault();
            const profileCollapse = document.getElementById('profileCollapse');
            const bsCollapse = new bootstrap.Collapse(profileCollapse, {
                toggle: true
            });
            profileCollapse.scrollIntoView({ behavior: 'smooth' });
        });

        // Password link handling
        const passwordLink = document.getElementById('passwordLink');
        passwordLink.addEventListener('click', function(e) {
            e.preventDefault();
            const profileCollapse = document.getElementById('profileCollapse');
            const passwordCollapse = document.getElementById('passwordCollapse');
            const bsProfileCollapse = new bootstrap.Collapse(profileCollapse, {
                toggle: true
            });
            const bsPasswordCollapse = new bootstrap.Collapse(passwordCollapse, {
                toggle: true
            });
            passwordCollapse.scrollIntoView({ behavior: 'smooth' });
        });

        // Profile form handling
        const profileForm = document.getElementById('profileForm');
        const editProfileBtn = document.getElementById('editProfileBtn');
        const updateProfileBtn = document.getElementById('updateProfileBtn');
        const usernameInput = document.getElementById('username');
        const emailInput = document.getElementById('email');
        const profilePhotoInput = document.getElementById('profile_photo');
        const photoPreview = document.getElementById('photo_preview');

        // Store original values
        const originalUsername = usernameInput.value;
        const originalEmail = emailInput.value;
        let originalPhoto = profilePhotoInput.files[0] || null;

        // Toggle edit mode
        let isEditing = false;
        editProfileBtn.addEventListener('click', function() {
            isEditing = !isEditing;
            usernameInput.disabled = !isEditing;
            emailInput.disabled = !isEditing;
            profilePhotoInput.disabled = !isEditing;
            editProfileBtn.textContent = isEditing ? 'Cancel Edit' : 'Edit Profile';
            editProfileBtn.classList.toggle('btn-warning', !isEditing);
            editProfileBtn.classList.toggle('btn-danger', isEditing);
            editProfileBtn.innerHTML = isEditing ? '<i class="bi bi-x-circle"></i> Cancel Edit' : '<i class="bi bi-pencil"></i> Edit Profile';
            if (!isEditing) {
                // Reset form to original values
                usernameInput.value = originalUsername;
                emailInput.value = originalEmail;
                profilePhotoInput.value = '';
                photoPreview.style.display = 'none';
                updateProfileBtn.disabled = true;
            }
        });

        // Detect changes to enable/disable Update Profile button
        function checkForChanges() {
            const usernameChanged = usernameInput.value !== originalUsername;
            const emailChanged = emailInput.value !== originalEmail;
            const photoChanged = profilePhotoInput.files.length > 0;
            updateProfileBtn.disabled = !(usernameChanged || emailChanged || photoChanged);
        }

        usernameInput.addEventListener('input', checkForChanges);
        emailInput.addEventListener('input', checkForChanges);
        profilePhotoInput.addEventListener('change', function() {
            checkForChanges();
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    photoPreview.src = e.target.result;
                    photoPreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                photoPreview.style.display = 'none';
            }
        });

        // Rest of the existing JavaScript
        function toggleSelectAll() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.question-row:not([style="display: none;"]) .question-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateAssignButton();
        }

        function updateAssignButton() {
            const assignButton = document.getElementById('assignButton');
            if (assignButton) {
                const checkedCheckboxes = document.querySelectorAll('.question-checkbox:checked').length;
                assignButton.disabled = checkedCheckboxes === 0;
            }
        }

        function filterExams(filter) {
            const rows = document.querySelectorAll('.exam-row');
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                if (filter === 'all' || 
                    (filter === 'draft' && status === 'draft') || 
                    (filter === 'published' && (status === 'published' || status === 'republished'))) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            document.getElementById('showMoreExams').style.display = 'none';
        }

        function filterQuestions(filter) {
            const rows = document.querySelectorAll('.question-row');
            rows.forEach(row => {
                const assigned = row.getAttribute('data-assigned');
                if (filter === 'all' || 
                    (filter === 'assigned' && assigned === 'yes') || 
                    (filter === 'new' && assigned === 'no')) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            document.getElementById('showMoreQuestions').style.display = 'none';
            updateAssignButton();
        }

        const showMoreQuestionsBtn = document.getElementById('showMoreQuestions');
        if (showMoreQuestionsBtn) {
            showMoreQuestionsBtn.addEventListener('click', function() {
                const hiddenRows = document.querySelectorAll('.question-row[style="display: none;"]');
                let shownCount = 0;
                hiddenRows.forEach(row => {
                    if (shownCount < 10) {
                        row.style.display = '';
                        shownCount++;
                    }
                });
                if (document.querySelectorAll('.question-row[style="display: none;"]').length === 0) {
                    showMoreQuestionsBtn.style.display = 'none';
                }
                document.getElementById('select-all').checked = false;
                updateAssignButton();
            });
        }

        const showMoreExamsBtn = document.getElementById('showMoreExams');
        if (showMoreExamsBtn) {
            showMoreExamsBtn.addEventListener('click', function() {
                const hiddenRows = document.querySelectorAll('.exam-row[style="display: none;"]');
                let shownCount = 0;
                hiddenRows.forEach(row => {
                    if (shownCount < 10) {
                        row.style.display = '';
                        shownCount++;
                    }
                });
                if (document.querySelectorAll('.exam-row[style="display: none;"]').length === 0) {
                    showMoreExamsBtn.style.display = 'none';
                }
            });
        }

        const goUpBtn = document.getElementById('goUpBtn');
        const goDownBtn = document.getElementById('goDownBtn');
        goUpBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        goDownBtn.addEventListener('click', function() {
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        });

        const checkboxes = document.querySelectorAll('.question-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateAssignButton);
        });

        updateAssignButton();

        window.toggleExamDetails = function(id) {
            const detailsRow = document.getElementById(id);
            const allDetailsRows = document.querySelectorAll('.exam-details-row');
            allDetailsRows.forEach(row => {
                if (row.id !== id && row.style.display !== 'none') {
                    row.style.display = 'none';
                }
            });
            detailsRow.style.display = detailsRow.style.display === 'none' ? '' : 'none';
        };
    });
    </script>
</body>
</html>

<?php
$questions_stmt->close();
$exams_stmt->close();
$conn->close();
?>