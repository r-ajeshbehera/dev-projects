<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_student();

$conn = db_connect();
$student_id = $_SESSION['user_id'];

// Set timezone to UTC for consistency with database
date_default_timezone_set('UTC');

// Get server time for initial sync
$server_time = new DateTime('now', new DateTimeZone('UTC'));
$server_time_ms = $server_time->getTimestamp() * 1000; // Pass to JS in milliseconds

$stmt = $conn->prepare("SELECT username, email, profile_photo, created_at FROM users WHERE id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$student_name = $student['username'] ?? 'Student';
$student_email = $student['email'] ?? '';
$profile_photo = $student['profile_photo'] ?? null;
$join_date = $student['created_at'] ? date('F j, Y', strtotime($student['created_at'])) : 'Unknown';
$stmt->close();

// Fetch exams with sorting to prioritize statuses
$exams_stmt = $conn->prepare("
    SELECT e.id, e.title, e.created_at, e.deadline, e.published_at, e.start_at, u.username AS teacher_name
    FROM exams e
    LEFT JOIN users u ON e.teacher_id = u.id
    WHERE e.status IN ('published', 'republished')
    ORDER BY 
        CASE 
            WHEN (e.start_at IS NULL OR e.start_at <= NOW()) AND (e.deadline IS NULL OR e.deadline >= NOW()) AND NOT EXISTS (SELECT 1 FROM exam_submissions es WHERE es.exam_id = e.id AND es.student_id = ?) THEN 1
            WHEN e.start_at IS NOT NULL AND e.start_at > NOW() AND NOT EXISTS (SELECT 1 FROM exam_submissions es WHERE es.exam_id = e.id AND es.student_id = ?) THEN 2
            WHEN EXISTS (SELECT 1 FROM exam_submissions es WHERE es.exam_id = e.id AND es.student_id = ?) THEN 3
            ELSE 4
        END,
        e.published_at DESC
");
$exams_stmt->bind_param('iii', $student_id, $student_id, $student_id);
$exams_stmt->execute();
$exams_result = $exams_stmt->get_result();

// Fetch submitted exams
$submitted_stmt = $conn->prepare("SELECT exam_id FROM exam_submissions WHERE student_id = ?");
$submitted_stmt->bind_param('i', $student_id);
$submitted_stmt->execute();
$submitted_result = $submitted_stmt->get_result();
$submitted_exams = [];
while ($row = $submitted_result->fetch_assoc()) {
    $submitted_exams[] = $row['exam_id'];
}

// Calculate initial stats
$total_exams = $exams_result->num_rows;
$completed_exams = count($submitted_exams);
$available_to_take = 0;
$not_started_exams = 0;
$near_deadline = [];
$missed_exams = [];

$exams_result->data_seek(0);
while ($exam = $exams_result->fetch_assoc()) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $deadline = $exam['deadline'] ? new DateTime($exam['deadline'], new DateTimeZone('UTC')) : null;
    $start_at = $exam['start_at'] ? new DateTime($exam['start_at'], new DateTimeZone('UTC')) : null;
    $is_submitted = in_array($exam['id'], $submitted_exams);

    if (!$is_submitted) {
        if ($deadline && $now > $deadline) {
            $missed_exams[] = [
                'title' => $exam['title'],
                'deadline' => date('F j, Y g:i A', strtotime($exam['deadline']) + 19800) // UTC to IST (+5:30)
            ];
        } elseif ($start_at && $now < $start_at) {
            $not_started_exams++;
        } elseif ((!$start_at || $now >= $start_at) && (!$deadline || $now <= $deadline)) {
            $available_to_take++;
            if ($deadline) {
                $days_until_deadline = ($deadline->getTimestamp() - $now->getTimestamp()) / (60 * 60 * 24);
                if ($days_until_deadline <= 7 && $days_until_deadline >= 0) {
                    $near_deadline[] = [
                        'title' => $exam['title'],
                        'deadline' => date('F j, Y g:i A', strtotime($exam['deadline']) + 19800),
                        'days_left' => floor($days_until_deadline)
                    ];
                }
            }
        }
    }
}

// Handle password change
$password_error = '';
$password_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $pwd_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $pwd_stmt->bind_param('i', $student_id);
    $pwd_stmt->execute();
    $pwd_result = $pwd_stmt->get_result()->fetch_assoc();
    $current_password_hash = $pwd_result['password'];

    if (!password_verify($old_password, $current_password_hash)) {
        $password_error = 'Old password is incorrect.';
    } elseif ($new_password !== $confirm_password) {
        $password_error = 'New password and confirmation do not match.';
    } elseif (strlen($new_password) < 6) {
        $password_error = 'New password must be at least 6 characters long.';
    } else {
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param('si', $new_password_hash, $student_id);
        if ($update_stmt->execute()) {
            $password_success = 'Password updated successfully!';
        } else {
            $password_error = 'Failed to update password. Please try again.';
        }
        $update_stmt->close();
    }
    $pwd_stmt->close();
}

// Handle profile update
$profile_error = '';
$profile_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $upload_ok = true;
    $new_profile_photo = $profile_photo;

    if (empty($new_username)) {
        $profile_error = 'Username is required.';
        $upload_ok = false;
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL) && !empty($new_email)) {
        $profile_error = 'Invalid email format.';
        $upload_ok = false;
    }

    if ($upload_ok && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['size'] > 0) {
        $target_dir = __DIR__ . '/../assets/uploads/profiles/';
        $file_ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png'];
        $new_filename = "student_{$student_id}_" . time() . ".{$file_ext}";
        $target_file = $target_dir . $new_filename;

        if (!in_array($file_ext, $allowed_exts)) {
            $profile_error = 'Only JPG, JPEG, and PNG files are allowed.';
            $upload_ok = false;
        } elseif ($_FILES['profile_photo']['size'] > 2000000) {
            $profile_error = 'File size must be less than 2MB.';
            $upload_ok = false;
        } else {
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_file)) {
                $new_profile_photo = "assets/uploads/profiles/{$new_filename}";
                if ($profile_photo && file_exists(__DIR__ . '/../' . $profile_photo)) {
                    unlink(__DIR__ . '/../' . $profile_photo);
                }
            } else {
                $profile_error = 'Failed to upload profile photo.';
                $upload_ok = false;
            }
        }
    }

    if ($upload_ok) {
        $update_stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, profile_photo = ? WHERE id = ?");
        $update_stmt->bind_param('sssi', $new_username, $new_email, $new_profile_photo, $student_id);
        if ($update_stmt->execute()) {
            $profile_success = 'Profile updated successfully!';
            $student_name = $new_username;
            $student_email = $new_email;
            $profile_photo = $new_profile_photo;
        } else {
            $profile_error = 'Failed to update profile. Please try again.';
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
    <title>Student Dashboard - AEP</title>
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
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        .custom-header {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            padding: 15px 25px;
            min-height: 80px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: margin-left 0.3s ease, width 0.3s ease;
            width: 100%;
        }
        .header-left {
            display: flex;
            align-items: center;
        }
        .header-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        .menu-button {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s ease;
            margin-right: 15px;
        }
        .menu-button:hover {
            transform: scale(1.1);
        }
        .header-title {
            color: #fff;
            font-size: 1.75rem;
            font-weight: 600;
            flex-grow: 1;
        }
        .header-right {
            display: flex;
            align-items: center;
        }
        .btn-logout {
            background-color: #ff6b6b;
            border: none;
            font-weight: 600;
            padding: 8px 16px;
            transition: background-color 0.3s ease, transform 0.3s ease;
        }
        .btn-logout:hover {
            background-color: #ff4c4c;
            transform: scale(1.05);
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: #1e3a8a;
            color: #fff;
            transition: width 0.3s ease, padding 0.3s ease;
            z-index: 1000;
            padding: 0;
            border-radius: 0 10px 10px 0;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.2);
            overflow-x: hidden;
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
            background: #2b4b9e;
        }
        .sidebar-profile {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        .sidebar-profile img {
            width: 120px;
            height: 120px;
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
            background: #2b4b9e;
        }
        .container {
            max-width: 1000px;
            margin: 20px auto;
            transition: margin-left 0.3s ease, width 0.3s ease;
            padding: 0 15px;
        }
        .footer {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            padding: 10px 0;
            margin-top: auto;
            width: 100%;
            transition: margin-left 0.3s ease, width 0.3s ease;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 15px 15px 0 0;
        }
        .footer-content {
            max-width: 1000px;
            margin: 0 auto;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            backdrop-filter: blur(5px);
            text-align: center;
            font-size: 0.85rem;
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
        .profile-form input {
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
            width: 60px;
            height: 60px;
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
        .btn-info {
            background: #17a2b8;
            border: none;
        }
        .btn-info:hover {
            background: #117a8b;
        }
        .btn-warning {
            background: #ffc107;
            border: none;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-outline-secondary {
            border-color: #6e8efb;
            color: #6e8efb;
        }
        .btn-outline-secondary:hover {
            background: #6e8efb;
            color: #fff;
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
        .highlight-today {
            background: #e9f7ff;
            font-weight: 600;
            color: #1e90ff;
        }
        .badge.bg-success {
            background: #28a745 !important;
            padding: 8px 14px;
            border-radius: 12px;
        }
        .badge.bg-warning {
            background: #ffc107 !important;
            padding: 8px 14px;
            border-radius: 12px;
        }
        .badge.bg-info {
            background: #17a2b8 !important;
            padding: 8px 14px;
            border-radius: 12px;
        }
        .badge.bg-danger {
            background: #dc3545 !important;
            padding: 8px 14px;
            border-radius: 12px;
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
        .exam-stats {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-top: 20px;
        }
        .stat-box {
            flex: 1;
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        .stat-box:hover {
            transform: translateY(-5px);
        }
        .stat-box h5 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .stat-box p {
            font-size: 1.5rem;
            font-weight: 700;
            color: #6e8efb;
        }
        .exam-note {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            border-left: 5px solid #6e8efb;
            margin-top: 20px;
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
                padding: 12px 15px;
                min-height: 70px;
                flex-wrap: wrap;
                width: 100%;
            }
            .header-logo {
                width: 50px;
                height: 50px;
            }
            .header-title {
                font-size: 1.4rem;
            }
            .btn-logout {
                padding: 6px 12px;
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
            .footer {
                width: 100%;
            }
            .footer-content {
                font-size: 0.8rem;
            }
            .sidebar-profile img {
                width: 100px;
                height: 100px;
            }
            .sidebar-profile h4 {
                font-size: 1rem;
            }
            .dashboard-header { padding: 20px; }
            .profile-photo { width: 80px; height: 80px; }
            .btn-custom { width: 100%; margin: 10px 0; }
            .table { font-size: 0.9rem; }
            .exam-stats { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <header class="custom-header content-full" id="header">
        <div class="header-left">
            <img src="<?php echo SITE_URL; ?>/assets/img/logo.jpg" alt="AEP Logo" class="header-logo">
            <button class="menu-button" id="menuToggle"><i class="bi bi-list"></i></button>
            <h1 class="header-title">Student Dashboard</h1>
        </div>
        <div class="header-right">
            <button class="btn btn-logout btn-custom" id="logoutBtn">Logout</button>
        </div>
    </header>

    <div class="sidebar" id="sidebar">
        <button class="sidebar-close" id="sidebarClose"><i class="bi bi-x"></i></button>
        <div class="sidebar-profile">
            <img src="<?php echo $profile_photo ? SITE_URL . '/' . $profile_photo : 'https://via.placeholder.com/120'; ?>" alt="Profile Photo">
            <h4><?php echo htmlspecialchars($student_name); ?></h4>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a></li>
            <li><a href="#" id="profileLink"><i class="bi bi-person-circle"></i> My Profile</a></li>
            <li><a href="dashboard.php"><i class="bi bi-list-check"></i> View Available Exams</a></li>
            <li><a href="view_exams.php"><i class="bi bi-bar-chart"></i> View Results</a></li>
            <li><a href="#" id="passwordLink"><i class="bi bi-key"></i> Change Password</a></li>
            <li><a href="<?php echo SITE_URL; ?>/auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
        </ul>
    </div>

    <div class="container content-full" id="mainContainer">
        <div class="dashboard-header text-center">
            <img src="<?php echo $profile_photo ? SITE_URL . '/' . $profile_photo : 'https://via.placeholder.com/100'; ?>" alt="Profile Photo" class="profile-photo">
            <h2 class="welcome-text">Welcome, <?php echo htmlspecialchars($student_name); ?>!</h2>
            <p class="text-muted">Your Dashboard</p>
            <button class="btn btn-outline-secondary btn-custom m-2" type="button" data-bs-toggle="collapse" data-bs-target="#profileCollapse" aria-expanded="false" aria-controls="profileCollapse">
                <i class="bi bi-person-circle"></i> My Profile
            </button>
            <div class="collapse mt-4" id="profileCollapse">
                <div class="card">
                    <div class="card-header">My Profile</div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h5 class="mb-3">Student Details</h5>
                            <form method="POST" enctype="multipart/form-data" class="profile-form" id="profileForm">
                                <input type="hidden" name="update_profile" value="1">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($student_name); ?>" disabled required>
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($student_email); ?>" placeholder="Enter email" disabled>
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
                <a href="view_exams.php" class="btn btn-info btn-custom m-2"><i class="bi bi-eye"></i> View Submitted Exams</a>
                <a href="<?php echo SITE_URL; ?>/auth/logout.php" class="btn btn-outline-secondary btn-custom m-2"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
            <div class="exam-stats">
                <div class="stat-box" onclick="filterExams('all')">
                    <h5>Total Exams</h5>
                    <p><?php echo $total_exams; ?></p>
                </div>
                <div class="stat-box" onclick="filterExams('available')">
                    <h5>Available to Take</h5>
                    <p id="availableToTakeCount"><?php echo $available_to_take; ?></p>
                </div>
                <div class="stat-box" onclick="filterExams('taken')">
                    <h5>Taken</h5>
                    <p><?php echo $completed_exams; ?></p>
                </div>
                <div class="stat-box" onclick="filterExams('near_deadline')">
                    <h5>Near Deadline</h5>
                    <p><?php echo count($near_deadline); ?></p>
                </div>
                <div class="stat-box" onclick="filterExams('missed')">
                    <h5>Missed Exams</h5>
                    <p><?php echo count($missed_exams); ?></p>
                </div>
                <div class="stat-box" onclick="filterExams('not_started')">
                    <h5>Not Started</h5>
                    <p><?php echo $not_started_exams; ?></p>
                </div>
            </div>
        </div>

        <?php if ($profile_success): ?>
            <div class="alert alert-success" id="alertMessage"><?php echo $profile_success; ?></div>
        <?php endif; ?>
        <?php if ($profile_error): ?>
            <div class="alert alert-danger" id="alertMessage"><?php echo $profile_error; ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success" id="alertMessage"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger" id="alertMessage"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <?php if ($password_success): ?>
            <div class="alert alert-success" id="alertMessage"><?php echo $password_success; ?></div>
        <?php endif; ?>
        <?php if ($password_error): ?>
            <div class="alert alert-danger" id="alertMessage"><?php echo $password_error; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">Available Exams</div>
                    <div class="card-body">
                        <?php if ($exams_result->num_rows === 0): ?>
                            <p class="text-muted text-center">No exams available at the moment.</p>
                        <?php else: ?>
                            <table class="table table-hover" id="examsTable">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Exam Title</th>
                                        <th scope="col">Created At</th>
                                        <th scope="col">Start At (IST)</th>
                                        <th scope="col">Deadline (IST)</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $exams_result->data_seek(0);
                                    $exam_counter = 1;
                                    $now = new DateTime('now', new DateTimeZone('UTC'));
                                    while ($exam = $exams_result->fetch_assoc()): 
                                        $deadline = $exam['deadline'] ? new DateTime($exam['deadline'], new DateTimeZone('UTC')) : null;
                                        $start_at = $exam['start_at'] ? new DateTime($exam['start_at'], new DateTimeZone('UTC')) : null;
                                        $is_submitted = in_array($exam['id'], $submitted_exams);
                                        $is_today = (date('Y-m-d', strtotime($exam['published_at'] ?? $exam['created_at'])) === date('Y-m-d'));

                                        // Convert UTC timestamps to IST
                                        $now_ist_timestamp = $now->getTimestamp() + 19800;
                                        $start_at_ist_timestamp = $start_at ? ($start_at->getTimestamp() + 19800) : null;
                                        $deadline_ist_timestamp = $deadline ? ($deadline->getTimestamp() + 19800) : null;

                                        // Determine status
                                        $is_missed = !$is_submitted && $deadline && $now_ist_timestamp > $deadline_ist_timestamp;
                                        $is_not_started = !$is_submitted && $start_at && $now_ist_timestamp < $start_at_ist_timestamp;
                                        $is_available = !$is_submitted && (!$start_at || $now_ist_timestamp >= $start_at_ist_timestamp) && (!$deadline || $now_ist_timestamp <= $deadline_ist_timestamp);

                                        // Display times in IST
                                        $deadline_display = $exam['deadline'] ? date('F j, Y g:i A', strtotime($exam['deadline']) + 19800) : 'No Deadline';
                                        $start_display = $exam['start_at'] ? date('F j, Y g:i A', strtotime($exam['start_at']) + 19800) : 'Immediate';
                                        $teacher_display = $exam['teacher_name'] ? " - Taken by " . htmlspecialchars($exam['teacher_name']) : " - Taken by Unknown";
                                    ?>
                                        <tr class="<?php echo $is_today && $is_available ? 'highlight-today' : ''; ?>" 
                                            data-exam-id="<?php echo $exam['id']; ?>" 
                                            data-start-at="<?php echo $exam['start_at'] ? (strtotime($exam['start_at']) * 1000) : ''; ?>"
                                            data-deadline="<?php echo $exam['deadline'] ? (strtotime($exam['deadline']) * 1000) : ''; ?>"
                                            data-submitted="<?php echo $is_submitted ? '1' : '0'; ?>"
                                            data-status="<?php echo $is_submitted ? 'taken' : ($is_missed ? 'missed' : ($is_not_started ? 'not_started' : 'available')); ?>">
                                            <td><?php echo $exam_counter++; ?></td>
                                            <td><?php echo htmlspecialchars($exam['title']) . $teacher_display; ?></td>
                                            <td><?php echo date('F j, Y', strtotime($exam['created_at'])); ?></td>
                                            <td><?php echo $start_display; ?></td>
                                            <td><?php echo $deadline_display; ?></td>
                                            <td class="status-cell">
                                                <?php 
                                                if ($is_submitted) {
                                                    echo 'Taken';
                                                } elseif ($is_missed) {
                                                    echo 'Missed';
                                                } elseif ($is_not_started) {
                                                    echo 'Not Started';
                                                } else {
                                                    echo 'Available';
                                                }
                                                ?>
                                            </td>
                                            <td class="action-cell">
                                                <?php if ($is_submitted): ?>
                                                    <span class="badge bg-success">Completed</span>
                                                <?php elseif ($is_missed): ?>
                                                    <span class="badge bg-danger">Missed</span>
                                                <?php elseif ($is_not_started): ?>
                                                    <span class="badge bg-warning">Not Started</span>
                                                <?php else: ?>
                                                    <a href="take_exam.php?id=<?php echo $exam['id']; ?>" 
                                                       class="btn btn-primary btn-sm take-exam-btn" 
                                                       data-exam-id="<?php echo $exam['id']; ?>">
                                                        Take Exam
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            <div class="exam-note">
                                <h5>Exam Status Guide</h5>
                                <ul>
                                    <li><strong>Available:</strong> Take these exams now before the deadline!</li>
                                    <li><strong>Not Started:</strong> These exams will open soon. Check the start time.</li>
                                    <li><strong>Taken:</strong> You’ve completed these exams. See results!</li>
                                    <li><strong>Missed:</strong> These exams are past due and can’t be taken.</li>
                                    <li><strong>Near Deadline:</strong> Hurry! These exams are due within 7 days.</li>
                                </ul>
                                <p>All times are in IST (UTC+05:30). Click the stat boxes above to filter exams.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer content-full" id="footer">
        <div class="footer-content">
            <?php require_once __DIR__ . '/../includes/footer.php'; ?>
        </div>
    </footer>

    <div class="modal fade" id="examNotStartedModal" tabindex="-1" aria-labelledby="examNotStartedModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="examNotStartedModalLabel">Exam Not Available</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    The exam has not started yet. Please wait until the IST start time set by the teacher.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="examMissedModal" tabindex="-1" aria-labelledby="examMissedModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="examMissedModalLabel">Exam Missed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    The deadline for this exam has passed. It is no longer available to take.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const IST_OFFSET = 19800000; // UTC+05:30 in milliseconds
        const serverTimeUTC = <?php echo $server_time_ms; ?>; // Server time in ms (UTC)
        let timeOffset = serverTimeUTC - Date.now(); // Adjust for client-server difference

        function getAdjustedIST() {
            const nowUTC = Date.now() + timeOffset; // Adjust client time to match server
            return nowUTC + IST_OFFSET;
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

        // Logout button in header
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                window.location.href = '<?php echo SITE_URL; ?>/auth/logout.php';
            });
        }

        // Alert handling
        const alert = document.getElementById('alertMessage');
        if (alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.remove(); }, 500);
            }, 2000);
        }

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

        // Exam handling logic
        const examNotStartedModal = new bootstrap.Modal(document.getElementById('examNotStartedModal'));
        const examMissedModal = new bootstrap.Modal(document.getElementById('examMissedModal'));

        function updateExamStatus() {
            const nowIST = getAdjustedIST();
            const rows = document.querySelectorAll('#examsTable tbody tr');
            let availableToTakeCount = 0;

            rows.forEach(row => {
                const examId = parseInt(row.getAttribute('data-exam-id'));
                const startAt = row.getAttribute('data-start-at') ? parseInt(row.getAttribute('data-start-at')) : null; // UTC start time in ms
                const deadline = row.getAttribute('data-deadline') ? parseInt(row.getAttribute('data-deadline')) : null; // UTC deadline in ms
                const isSubmitted = row.getAttribute('data-submitted') === '1';
                const statusCell = row.querySelector('.status-cell');
                const actionCell = row.querySelector('.action-cell');

                // Convert UTC timestamps to IST
                const startAtIST = startAt ? startAt + IST_OFFSET : null;
                const deadlineIST = deadline ? deadline + IST_OFFSET : null;

                let newStatus = '';
                let actionHTML = '';

                if (isSubmitted) {
                    newStatus = 'Taken';
                    actionHTML = '<span class="badge bg-success">Completed</span>';
                } else if (deadline && nowIST > deadlineIST) {
                    newStatus = 'Missed';
                    actionHTML = '<span class="badge bg-danger">Missed</span>';
                } else if (startAt && nowIST < startAtIST) {
                    newStatus = 'Not Started';
                    actionHTML = '<span class="badge bg-warning">Not Started</span>';
                } else {
                    newStatus = 'Available';
                    actionHTML = `<a href="take_exam.php?id=${examId}" class="btn btn-primary btn-sm take-exam-btn" data-exam-id="${examId}">Take Exam</a>`;
                    availableToTakeCount++;
                }

                // Update status and action if changed
                if (statusCell.textContent.trim() !== newStatus) {
                    statusCell.textContent = newStatus;
                    row.setAttribute('data-status', newStatus.toLowerCase().replace(' ', '_'));
                }
                if (actionCell.innerHTML.trim() !== actionHTML.trim()) {
                    actionCell.innerHTML = actionHTML;
                }

                // Highlight today's available exams
                const isToday = row.classList.contains('highlight-today');
                if (newStatus === 'Available' && isToday) {
                    row.classList.add('highlight-today');
                } else {
                    row.classList.remove('highlight-today');
                }
            });

            // Update available to take count
            const availableToTakeElement = document.getElementById('availableToTakeCount');
            if (availableToTakeElement && availableToTakeElement.textContent !== availableToTakeCount.toString()) {
                availableToTakeElement.textContent = availableToTakeCount;
            }
        }

        // Filter exams based on stat box click
        function filterExams(filter) {
            const rows = document.querySelectorAll('#examsTable tbody tr');
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                const deadline = row.getAttribute('data-deadline') ? parseInt(row.getAttribute('data-deadline')) + IST_OFFSET : null;
                const nowIST = getAdjustedIST();
                const isNearDeadline = deadline && ((deadline - nowIST) / (1000 * 60 * 60 * 24)) <= 7 && ((deadline - nowIST) / (1000 * 60 * 60 * 24)) >= 0 && status === 'available';

                if (filter === 'all' ||
                    (filter === 'available' && status === 'available') ||
                    (filter === 'taken' && status === 'taken') ||
                    (filter === 'missed' && status === 'missed') ||
                    (filter === 'not_started' && status === 'not_started') ||
                    (filter === 'near_deadline' && isNearDeadline)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            document.getElementById('examsTable').scrollIntoView({ behavior: 'smooth' });
        }

        // Initial update and periodic refresh
        updateExamStatus();
        setInterval(updateExamStatus, 1000); // Update every second

        // Handle Take Exam button clicks with precise validation
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.take-exam-btn');
            if (btn) {
                e.preventDefault(); // Prevent default navigation until validated
                const examId = btn.getAttribute('data-exam-id');
                const row = btn.closest('tr');
                const startAt = row.getAttribute('data-start-at') ? parseInt(row.getAttribute('data-start-at')) : null;
                const deadline = row.getAttribute('data-deadline') ? parseInt(row.getAttribute('data-deadline')) : null;
                const isSubmitted = row.getAttribute('data-submitted') === '1';
                const nowIST = getAdjustedIST();
                const startAtIST = startAt ? startAt + IST_OFFSET : null;
                const deadlineIST = deadline ? deadline + IST_OFFSET : null;

                if (isSubmitted) {
                    return; // Shouldn't happen due to button rendering logic
                } else if (deadline && nowIST > deadlineIST) {
                    examMissedModal.show();
                } else if (startAt && nowIST < startAtIST) {
                    examNotStartedModal.show();
                } else {
                    window.location.href = `take_exam.php?id=${examId}`;
                }
            }
        });
    });
    </script>
</body>
</html>

<?php
$exams_stmt->close();
$submitted_stmt->close();
$conn->close();
?>