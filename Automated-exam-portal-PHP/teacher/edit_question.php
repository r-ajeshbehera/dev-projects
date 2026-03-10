<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];
$question_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$success = '';

if (!$question_id) {
    header('Location: dashboard.php?error=Invalid+question+ID');
    exit;
}

// Fetch question details
$stmt = $conn->prepare("SELECT topic, question_text, question_type FROM questions WHERE id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $question_id, $teacher_id);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) {
    header('Location: dashboard.php?error=Question+not+found');
    exit;
}

// Fetch options if MCQ
$options = [];
$correct_option = null;
if ($question['question_type'] === 'mcq') {
    $option_stmt = $conn->prepare("SELECT id, option_text, is_correct FROM question_options WHERE question_id = ? ORDER BY id");
    $option_stmt->bind_param('i', $question_id);
    $option_stmt->execute();
    $result = $option_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $options[] = $row;
        if ($row['is_correct']) {
            $correct_option = count($options); // 1-based index for form
        }
    }
    $option_stmt->close();
    // Debug: Ensure options are fetched
    if (empty($options)) {
        $error = "No options found for this MCQ question. Please check the database.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $topic = filter_input(INPUT_POST, 'topic', FILTER_SANITIZE_SPECIAL_CHARS);
    $question_text = filter_input(INPUT_POST, 'question_text', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$topic || !$question_text) {
        $error = 'Please provide both topic and question text.';
    } else {
        // Update question details
        $stmt = $conn->prepare("UPDATE questions SET topic = ?, question_text = ? WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param('ssii', $topic, $question_text, $question_id, $teacher_id);
        if ($stmt->execute()) {
            // Update options if MCQ
            if ($question['question_type'] === 'mcq') {
                $new_options = [
                    filter_input(INPUT_POST, 'option1', FILTER_SANITIZE_SPECIAL_CHARS),
                    filter_input(INPUT_POST, 'option2', FILTER_SANITIZE_SPECIAL_CHARS),
                    filter_input(INPUT_POST, 'option3', FILTER_SANITIZE_SPECIAL_CHARS),
                    filter_input(INPUT_POST, 'option4', FILTER_SANITIZE_SPECIAL_CHARS),
                ];
                $correct_option_new = filter_input(INPUT_POST, 'correct_option', FILTER_VALIDATE_INT);

                if (count(array_filter($new_options)) < 4 || $correct_option_new === false || $correct_option_new < 1 || $correct_option_new > 4) {
                    $error = "Please provide 4 valid options and select a correct one.";
                } else {
                    // Delete existing options
                    $delete_stmt = $conn->prepare("DELETE FROM question_options WHERE question_id = ?");
                    $delete_stmt->bind_param('i', $question_id);
                    $delete_stmt->execute();
                    $delete_stmt->close();

                    // Insert new options
                    $option_stmt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                    foreach ($new_options as $i => $option_text) {
                        $is_correct = ($i + 1 === $correct_option_new) ? 1 : 0;
                        $option_stmt->bind_param('isi', $question_id, $option_text, $is_correct);
                        if (!$option_stmt->execute()) {
                            $error = "Failed to update option: " . $option_stmt->error;
                            break;
                        }
                    }
                    $option_stmt->close();
                }
            }
            if (!$error) {
                $success = "Question updated successfully!";
            }
        } else {
            $error = "Failed to update question: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<div class="container mt-4">
    <h2>Edit Question</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3">
            <label for="topic" class="form-label">Topic</label>
            <input type="text" name="topic" id="topic" class="form-control" value="<?php echo htmlspecialchars($question['topic']); ?>" required>
        </div>
        <div class="mb-3">
            <label for="question_text" class="form-label">Question Text</label>
            <textarea name="question_text" id="question_text" class="form-control" required><?php echo htmlspecialchars($question['question_text']); ?></textarea>
        </div>
        <?php if ($question['question_type'] === 'mcq'): ?>
            <div class="mb-3">
                <label class="form-label">Options</label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="input-group mb-2">
                        <span class="input-group-text"><?php echo chr(65 + $i); ?></span>
                        <input type="text" name="option<?php echo $i + 1; ?>" class="form-control" 
                               value="<?php echo isset($options[$i]) ? htmlspecialchars($options[$i]['option_text']) : ''; ?>" 
                               placeholder="Option <?php echo chr(65 + $i); ?>" required>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="mb-3">
                <label for="correct_option" class="form-label">Correct Option</label>
                <select name="correct_option" id="correct_option" class="form-control" required>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $correct_option === $i ? 'selected' : ''; ?>>
                            <?php echo chr(64 + $i); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Update Question</button>
        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>