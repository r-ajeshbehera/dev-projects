<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];
$question_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Validate question_id
if ($question_id === false || $question_id <= 0) {
    header('Location: dashboard.php?error=Invalid+question+ID');
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM questions WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param('ii', $question_id, $teacher_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        header('Location: dashboard.php?success=Question+deleted');
    } else {
        header('Location: dashboard.php?error=Question+not+found+or+not+authorized');
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    header('Location: dashboard.php?error=Failed+to+delete+question:+Database+error');
} finally {
    $conn->close();
}
exit;
?>