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
$exam_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$success = '';

if (!$exam_id) {
    header('Location: dashboard.php?error=Invalid+exam+ID');
    exit;
}

// Fetch exam with new fields
$stmt = $conn->prepare("SELECT title, status, deadline, category FROM exams WHERE id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $exam_id, $teacher_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
if (!$exam) {
    header('Location: dashboard.php?error=Exam+not+found');
    exit;
}

// Fetch unassigned questions
$questions_stmt = $conn->prepare("SELECT id, topic, question_text FROM questions WHERE teacher_id = ? AND exam_id IS NULL");
$questions_stmt->bind_param('i', $teacher_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();

// Fetch assigned questions
$assigned_stmt = $conn->prepare("SELECT id, topic, question_text FROM questions WHERE exam_id = ?");
$assigned_stmt->bind_param('i', $exam_id);
$assigned_stmt->execute();
$assigned_result = $assigned_stmt->get_result();

// Handle assigning questions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    $question_ids = $_POST['question_ids'] ?? [];
    if (!empty($question_ids)) {
        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        $update_stmt = $conn->prepare("UPDATE questions SET exam_id = ? WHERE id IN ($placeholders) AND teacher_id = ?");
        $params = array_merge([$exam_id], $question_ids, [$teacher_id]);
        $types = str_repeat('i', count($question_ids) + 2);
        $update_stmt->bind_param($types, ...$params);
        if ($update_stmt->execute()) {
            header("Location: manage_exam.php?id=$exam_id&success=Questions+assigned");
            exit;
        } else {
            $error = "Failed to assign questions: " . $update_stmt->error;
        }
        $update_stmt->close();
    }
}

// Handle updating exam details (deadline, category)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exam'])) {
    $deadline = filter_input(INPUT_POST, 'deadline', FILTER_SANITIZE_SPECIAL_CHARS);
    $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($deadline && !DateTime::createFromFormat('Y-m-d', $deadline)) {
        $error = 'Invalid deadline format. Use YYYY-MM-DD.';
    } else {
        $update_exam_stmt = $conn->prepare("UPDATE exams SET deadline = ?, category = ? WHERE id = ? AND teacher_id = ?");
        $update_exam_stmt->bind_param('ssii', $deadline, $category, $exam_id, $teacher_id);
        if ($update_exam_stmt->execute()) {
            $success = "Exam details updated successfully!";
            header("Location: manage_exam.php?id=$exam_id&success=" . urlencode($success));
            exit;
        } else {
            $error = "Failed to update exam: " . $update_exam_stmt->error;
        }
        $update_exam_stmt->close();
    }
}

// Handle adding new questions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_questions'])) {
    $questions = $_POST['questions'] ?? [];
    if (!empty($questions)) {
        $questions_stmt_add = $conn->prepare("INSERT INTO questions (teacher_id, exam_id, topic, question_text, question_type) VALUES (?, ?, ?, ?, ?)");
        $option_stmt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

        foreach ($questions as $q) {
            $topic = filter_var($q['topic'], FILTER_SANITIZE_SPECIAL_CHARS);
            $question_text = filter_var($q['text'], FILTER_SANITIZE_SPECIAL_CHARS);
            $question_type = filter_var($q['type'], FILTER_SANITIZE_SPECIAL_CHARS);
            $questions_stmt_add->bind_param('iisss', $teacher_id, $exam_id, $topic, $question_text, $question_type);
            if ($questions_stmt_add->execute()) {
                $question_id = $conn->insert_id;
                if ($question_type === 'mcq' && isset($q['options'])) {
                    foreach ($q['options'] as $i => $option) {
                        $option_text = filter_var($option['text'], FILTER_SANITIZE_SPECIAL_CHARS);
                        $is_correct = isset($q['correct']) && $q['correct'] == $i ? 1 : 0;
                        $option_stmt->bind_param('isi', $question_id, $option_text, $is_correct);
                        if (!$option_stmt->execute()) {
                            $error = "Failed to save option: " . $option_stmt->error;
                            break 2;
                        }
                    }
                }
            } else {
                $error = "Failed to save question: " . $questions_stmt_add->error;
                break;
            }
        }
        $questions_stmt_add->close();
        $option_stmt->close();
        if (!$error) {
            header("Location: manage_exam.php?id=$exam_id&success=" . urlencode(count($questions) . " new question(s) added"));
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Exam - AEP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef2ff, #d9e2ff);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .container {
            max-width: 900px;
            margin-top: 50px;
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            animation: slideIn 0.5s ease-in-out;
        }
        .btn-custom {
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            transform: scale(1.08);
        }
        .table {
            background: #fff;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05);
        }
        .table thead th {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            border: none;
        }
        .question-block {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 10px;
            background: #f9f9f9;
        }
        .option-block {
            margin-left: 20px;
            margin-top: 10px;
        }
        .alert {
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Manage Exam: <?php echo htmlspecialchars($exam['title']); ?></h2>
        <p><strong>Status:</strong> <?php echo $exam['status']; ?></p>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success']) || $success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success'] ?? $success); ?></div>
        <?php endif; ?>

        <!-- Exam Details Update Form -->
        <h3>Exam Details</h3>
        <form method="POST">
            <input type="hidden" name="update_exam" value="1">
            <div class="mb-3">
                <label for="deadline" class="form-label">Deadline</label>
                <input type="date" name="deadline" id="deadline" class="form-control" value="<?php echo $exam['deadline'] ?? ''; ?>">
            </div>
            <div class="mb-3">
                <label for="category" class="form-label">Category</label>
                <select name="category" id="category" class="form-select">
                    <option value="General" <?php echo ($exam['category'] === 'General') ? 'selected' : ''; ?>>General</option>
                    <option value="Math" <?php echo ($exam['category'] === 'Math') ? 'selected' : ''; ?>>Math</option>
                    <option value="Science" <?php echo ($exam['category'] === 'Science') ? 'selected' : ''; ?>>Science</option>
                    <option value="History" <?php echo ($exam['category'] === 'History') ? 'selected' : ''; ?>>History</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-custom">Update Details</button>
        </form>

        <!-- Assigned Questions -->
        <h3 class="mt-4">Assigned Questions</h3>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Topic</th>
                    <th>Question Text</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $assigned_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['topic']); ?></td>
                        <td><?php echo htmlspecialchars($row['question_text']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <!-- Assign Questions -->
        <h3 class="mt-4">Assign Existing Questions</h3>
        <form method="POST">
            <input type="hidden" name="assign" value="1">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Select</th>
                        <th>ID</th>
                        <th>Topic</th>
                        <th>Question Text</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $questions_result->fetch_assoc()): ?>
                        <tr>
                            <td><input type="checkbox" name="question_ids[]" value="<?php echo $row['id']; ?>"></td>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['topic']); ?></td>
                            <td><?php echo htmlspecialchars($row['question_text']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary btn-custom">Assign Selected Questions</button>
        </form>

        <!-- Add New Questions -->
        <h3 class="mt-4">Add New Questions</h3>
        <form method="POST" id="addQuestionsForm">
            <input type="hidden" name="add_questions" value="1">
            <div id="questionsContainer"></div>
            <button type="button" class="btn btn-outline-primary mb-3 btn-custom" onclick="addQuestion()">Add Question</button>
            <div class="mt-3">
                <button type="submit" class="btn btn-success btn-custom">Save New Questions</button>
                <a href="dashboard.php" class="btn btn-secondary btn-custom">Back to Dashboard</a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let questionCount = 0;

        function addQuestion() {
            questionCount++;
            const container = document.getElementById('questionsContainer');
            const questionDiv = document.createElement('div');
            questionDiv.className = 'question-block';
            questionDiv.innerHTML = `
                <div class="mb-3">
                    <label class="form-label">Topic</label>
                    <input type="text" name="questions[${questionCount}][topic]" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Question Text</label>
                    <textarea name="questions[${questionCount}][text]" class="form-control" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Question Type</label>
                    <select name="questions[${questionCount}][type]" class="form-select" onchange="toggleOptions(this, ${questionCount})">
                        <option value="short_answer">Short Answer</option>
                        <option value="mcq">Multiple Choice</option>
                    </select>
                </div>
                <div class="options-container" id="options-${questionCount}" style="display: none;">
                    <div class="option-block">
                        <label>Options (select correct one)</label>
                        <div class="mb-2">
                            <input type="text" name="questions[${questionCount}][options][0][text]" class="form-control" placeholder="Option A">
                            <input type="radio" name="questions[${questionCount}][correct]" value="0" class="form-check-input">
                        </div>
                        <div class="mb-2">
                            <input type="text" name="questions[${questionCount}][options][1][text]" class="form-control" placeholder="Option B">
                            <input type="radio" name="questions[${questionCount}][correct]" value="1" class="form-check-input">
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addOption(${questionCount})">Add Option</button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-danger mt-2" onclick="this.parentElement.remove()">Remove</button>
            `;
            container.appendChild(questionDiv);
        }

        function toggleOptions(select, qIndex) {
            const optionsDiv = document.getElementById(`options-${qIndex}`);
            optionsDiv.style.display = select.value === 'mcq' ? 'block' : 'none';
        }

        function addOption(qIndex) {
            const optionsContainer = document.getElementById(`options-${qIndex}`);
            const optionCount = optionsContainer.querySelectorAll('.mb-2').length;
            const optionDiv = document.createElement('div');
            optionDiv.className = 'mb-2';
            optionDiv.innerHTML = `
                <input type="text" name="questions[${qIndex}][options][${optionCount}][text]" class="form-control" placeholder="Option ${String.fromCharCode(65 + optionCount)}">
                <input type="radio" name="questions[${qIndex}][correct]" value="${optionCount}" class="form-check-input">
            `;
            optionsContainer.insertBefore(optionDiv, optionsContainer.querySelector('button'));
        }

        // Add one question by default
        document.addEventListener('DOMContentLoaded', addQuestion);
    </script>
</body>
</html>

<?php
$stmt->close();
$questions_stmt->close();
$assigned_stmt->close();
require_once __DIR__ . '/../includes/footer.php';
?>