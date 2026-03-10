<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];
$exam_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($exam_id) {
    // Check if the exam exists and is in 'draft' status
    $status_stmt = $conn->prepare("SELECT status FROM exams WHERE id = ? AND teacher_id = ?");
    $status_stmt->bind_param('ii', $exam_id, $teacher_id);
    $status_stmt->execute();
    $status_stmt->bind_result($status);
    $status_stmt->fetch();
    $status_stmt->close();

    if ($status === 'draft') {
        // Start a transaction to ensure all deletions succeed or none happen
        $conn->begin_transaction();

        try {
            // Step 1: Delete related student answers (if any)
            $answers_stmt = $conn->prepare("
                DELETE sa FROM student_answers sa
                INNER JOIN exam_submissions es ON sa.submission_id = es.id
                WHERE es.exam_id = ?
            ");
            $answers_stmt->bind_param('i', $exam_id);
            $answers_stmt->execute();
            $answers_stmt->close();

            // Step 2: Delete related exam submissions
            $submissions_stmt = $conn->prepare("DELETE FROM exam_submissions WHERE exam_id = ?");
            $submissions_stmt->bind_param('i', $exam_id);
            $submissions_stmt->execute();
            $submissions_stmt->close();

            // Step 3: Delete related questions (Fix for foreign key issue)
            $questions_stmt = $conn->prepare("DELETE FROM questions WHERE exam_id = ?");
            $questions_stmt->bind_param('i', $exam_id);
            $questions_stmt->execute();
            $questions_stmt->close();

            // Step 4: Delete the exam
            $exam_stmt = $conn->prepare("DELETE FROM exams WHERE id = ? AND teacher_id = ?");
            $exam_stmt->bind_param('ii', $exam_id, $teacher_id);
            if ($exam_stmt->execute() && $exam_stmt->affected_rows > 0) {
                $conn->commit();
                header("Location: dashboard.php?success=Exam+deleted+successfully");
            } else {
                throw new Exception("No exam deleted or exam not found");
            }
            $exam_stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: dashboard.php?error=Failed+to+delete+exam:+" . urlencode($e->getMessage()));
        }
    } else {
        header("Location: dashboard.php?error=Exam+cannot+be+deleted+once+published");
    }
} else {
    header("Location: dashboard.php?error=Invalid+exam+ID");
}

$conn->close();
exit();
?>
