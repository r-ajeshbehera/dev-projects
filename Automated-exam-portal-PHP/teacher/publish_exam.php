<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reportinG(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];
$exam_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING);

if ($exam_id && ($action === 'publish' || $action === 'republish')) {
    // Check current status and start_at
    $stmt = $conn->prepare("SELECT status, start_at FROM exams WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param('ii', $exam_id, $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exam = $result->fetch_assoc();
    $current_status = $exam['status'] ?? null;
    $start_at = $exam['start_at'] ? new DateTime($exam['start_at'], new DateTimeZone('UTC')) : null;
    $stmt->close();

    if (!$current_status) {
        header('Location: dashboard.php?error=Exam+not+found');
        $conn->close();
        exit;
    }

    // Current time in UTC
    $now = new DateTime('now', new DateTimeZone('UTC'));

    // Determine new status and start_at based on action
    if ($action === 'publish' && $current_status === 'draft') {
        $new_status = 'published';
        // Keep existing start_at (set in manage_exam.php) or leave as is if null
        $new_start_at = $start_at ? $start_at->format('Y-m-d H:i:s') : null;
    } elseif ($action === 'republish') {
        $new_status = 'republished';
        // For republish: set start_at to NOW() if null or future, keep if past
        if (!$start_at || $start_at > $now) {
            $new_start_at = $now->format('Y-m-d H:i:s');
        } else {
            $new_start_at = $start_at->format('Y-m-d H:i:s');
        }
    } else {
        header('Location: dashboard.php?error=Invalid+action+for+current+exam+status');
        $conn->close();
        exit;
    }

    // Update exam status, published_at, and start_at
    $update_stmt = $conn->prepare("
        UPDATE exams 
        SET status = ?, published_at = NOW(), start_at = ?
        WHERE id = ? AND teacher_id = ?
    ");
    $update_stmt->bind_param('ssii', $new_status, $new_start_at, $exam_id, $teacher_id);
    if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
        $message = ($new_status === 'published') ? 'Exam+published' : 'Exam+republished';
        header("Location: dashboard.php?success={$message}");
    } else {
        header('Location: dashboard.php?error=Failed+to+update+exam+status');
    }
    $update_stmt->close();
} else {
    header('Location: dashboard.php?error=Invalid+exam+ID+or+action');
}

$conn->close();
exit;
?>