<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];
$error = '';
$success = '';

$available_questions_stmt = $conn->prepare("SELECT id, topic, question_text FROM questions WHERE teacher_id = ? AND exam_id IS NULL");
$available_questions_stmt->bind_param('i', $teacher_id);
$available_questions_stmt->execute();
$available_questions_result = $available_questions_stmt->get_result();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_SPECIAL_CHARS);
    $deadline_raw = filter_input(INPUT_POST, 'deadline', FILTER_SANITIZE_SPECIAL_CHARS);
    $start_at_raw = filter_input(INPUT_POST, 'start_at', FILTER_SANITIZE_SPECIAL_CHARS);
    $publish = isset($_POST['publish']);
    $manual_questions = isset($_POST['questions']) ? $_POST['questions'] : [];
    $assigned_questions = isset($_POST['assigned_questions']) ? $_POST['assigned_questions'] : [];

    $deadline = !empty($deadline_raw) ? $deadline_raw : null;
    $start_at = !empty($start_at_raw) ? $start_at_raw : null;

    // Convert IST to UTC
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $start_at_dt = $start_at ? DateTime::createFromFormat('Y-m-d\TH:i', $start_at, new DateTimeZone('Asia/Kolkata')) : null;
    if ($start_at_dt) {
        $start_at_dt->setTimezone(new DateTimeZone('UTC'));
    }
    $deadline_dt = $deadline ? DateTime::createFromFormat('Y-m-d\TH:i', $deadline, new DateTimeZone('Asia/Kolkata')) : null;
    if ($deadline_dt) {
        $deadline_dt->setTimezone(new DateTimeZone('UTC'));
    }

    if (!$title) {
        $error = 'Please provide an exam title.';
    } elseif ($start_at_dt && $start_at_dt < $now) {
        $error = 'Start time cannot be in the past.';
    } elseif ($deadline_dt && $deadline_dt < $now) {
        $error = 'Deadline cannot be in the past.';
    } elseif ($start_at_dt && $deadline_dt && $start_at_dt >= $deadline_dt) {
        $error = 'Start time must be before the deadline.';
    } else {
        $status = $publish ? 'published' : 'draft';
        $start_at_db = $start_at_dt ? $start_at_dt->format('Y-m-d H:i:s') : null;
        $deadline_db = $deadline_dt ? $deadline_dt->format('Y-m-d H:i:s') : null;

        $conn->begin_transaction();
        
        try {
            $stmt = $conn->prepare("INSERT INTO exams (teacher_id, title, status, deadline, start_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('issss', $teacher_id, $title, $status, $deadline_db, $start_at_db);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create exam: " . $stmt->error);
            }
            
            $exam_id = $conn->insert_id;
            $stmt->close();

            $questions_stmt = $conn->prepare("INSERT INTO questions (teacher_id, exam_id, topic, question_text, question_type) VALUES (?, ?, ?, ?, ?)");
            $update_questions_stmt = $conn->prepare("UPDATE questions SET exam_id = ? WHERE id = ? AND teacher_id = ?");
            $option_stmt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

            if (!empty($manual_questions)) {
                foreach ($manual_questions as $q) {
                    $topic = filter_var($q['topic'], FILTER_SANITIZE_SPECIAL_CHARS);
                    $question_text = filter_var($q['text'], FILTER_SANITIZE_SPECIAL_CHARS);
                    $question_type = filter_var($q['type'], FILTER_SANITIZE_SPECIAL_CHARS);
                    $questions_stmt->bind_param('iisss', $teacher_id, $exam_id, $topic, $question_text, $question_type);
                    
                    if (!$questions_stmt->execute()) {
                        throw new Exception("Failed to save question: " . $questions_stmt->error);
                    }
                    
                    $question_id = $conn->insert_id;
                    if ($question_type === 'mcq' && isset($q['options'])) {
                        foreach ($q['options'] as $i => $option) {
                            $option_text = filter_var($option['text'], FILTER_SANITIZE_SPECIAL_CHARS);
                            $is_correct = isset($q['correct']) && $q['correct'] == $i ? 1 : 0;
                            $option_stmt->bind_param('isi', $question_id, $option_text, $is_correct);
                            if (!$option_stmt->execute()) {
                                throw new Exception("Failed to save option: " . $option_stmt->error);
                            }
                        }
                    }
                }
            }

            if (!empty($assigned_questions)) {
                foreach ($assigned_questions as $question_id) {
                    $question_id = (int)$question_id;
                    $update_questions_stmt->bind_param('iii', $exam_id, $question_id, $teacher_id);
                    if (!$update_questions_stmt->execute()) {
                        throw new Exception("Failed to assign question ID $question_id: " . $update_questions_stmt->error);
                    }
                }
            }

            $questions_stmt->close();
            $option_stmt->close();
            $update_questions_stmt->close();

            $conn->commit();
            
            $question_count = count($manual_questions) + count($assigned_questions);
            $success = "Exam '$title' created" . ($publish ? ' and published' : '') . " with $question_count question(s)!";
            header("Location: dashboard.php?success=" . urlencode($success));
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP - Create New Exam </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .navbar-brand {
            color: white !important;
            font-size: 1.25rem;
            font-weight: 600;
        }
        .aep-logo {
            font-size: 2rem;
            font-weight: 700;
        }
        .nav-link {
            color: white !important;
            font-weight: 600;
        }
        .container {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        h2, h4 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-weight: 700;
        }
        .btn-custom {
            border-radius: 30px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: #6e8efb;
            border: none;
        }
        .btn-primary:hover {
            background: #5a75e3;
            transform: translateY(-2px);
        }
        .btn-outline-primary {
            color: #6e8efb;
            border-color: #6e8efb;
        }
        .btn-outline-primary:hover {
            background: #6e8efb;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            border: none;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 15px;
            border: 1px solid #ced4da;
        }
        .form-control:focus, .form-select:focus {
            border-color: #6e8efb;
            box-shadow: 0 0 0 0.25rem rgba(110, 142, 251, 0.25);
        }
        .question-block {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        .options-container {
            padding: 15px;
            background: white;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px solid #e9ecef;
        }
        .option-block {
            margin-bottom: 15px;
        }
        .option-block .form-control {
            margin-bottom: 5px;
        }
        .form-check-input {
            margin-right: 8px;
        }
        .alert {
            border-radius: 8px;
        }
        .form-check-label {
            margin-left: 5px;
        }
        footer {
            background: linear-gradient(90deg, rgb(80, 101, 169), #a777e3);
            color: white;
            padding: 15px 0;
            text-align: center;
            margin-top: 40px;
        }
        .footer a {
            color: white;
            text-decoration: underline;
        }
        .back-btn {
            margin-bottom: 20px;
        }
        .assign-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .container {
                margin: 15px;
                padding: 15px;
            }
            .navbar-brand {
                font-size: 1rem;
            }
            .aep-logo {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <span class="aep-logo">AEP</span> Teacher - Create New Exam
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <a href="dashboard.php" class="btn btn-secondary btn-custom back-btn"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        <h2>Create New Exam</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>

        <form method="POST" id="examForm">
            <div class="mb-3">
                <label for="title" class="form-label">Exam Title</label>
                <input type="text" name="title" id="title" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="start_at" class="form-label">Start Time (IST, Optional)</label>
                <input type="datetime-local" name="start_at" id="start_at" class="form-control">
            </div>
            <div class="mb-3">
                <label for="deadline" class="form-label">Deadline (IST, Optional)</label>
                <input type="datetime-local" name="deadline" id="deadline" class="form-control">
            </div>

            <div class="assign-section">
                <h4>Available Questions to Assign</h4>
                <?php if ($available_questions_result->num_rows > 0): ?>
                    <?php while ($q = $available_questions_result->fetch_assoc()): ?>
                        <div class="form-check">
                            <input type="checkbox" name="assigned_questions[]" value="<?php echo $q['id']; ?>" class="form-check-input" id="assign_q<?php echo $q['id']; ?>">
                            <label class="form-check-label" for="assign_q<?php echo $q['id']; ?>">
                                <?php echo htmlspecialchars($q['topic']) . ": " . htmlspecialchars($q['question_text']); ?>
                            </label>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>No available questions to assign.</p>
                <?php endif; ?>
            </div>

            <div id="questionsContainer">
                <h4>Add New Questions</h4>
            </div>
            <button type="button" class="btn btn-outline-primary btn-custom mb-3" onclick="addQuestion()">Add Question</button>

            <div class="form-check mb-3">
                <input type="checkbox" name="publish" id="publish" class="form-check-input">
                <label class="form-check-label" for="publish">Publish Immediately</label>
            </div>
            <button type="submit" class="btn btn-primary btn-custom">Create Exam</button>
        </form>
    </div>

    <footer class="footer">
        <p>© <?php echo date('Y'); ?> AEP | All Rights Reserved | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank">Rajesh Behera.</a></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let questionCounter = 0;

        function addQuestion() {
            questionCounter++;
            const container = document.getElementById('questionsContainer');
            const questionBlock = document.createElement('div');
            questionBlock.className = 'question-block';
            questionBlock.innerHTML = `
                <div class="mb-3">
                    <label class="form-label">Topic</label>
                    <input type="text" name="questions[${questionCounter}][topic]" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Question Text</label>
                    <textarea name="questions[${questionCounter}][text]" class="form-control" rows="2" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Question Type</label>
                    <select name="questions[${questionCounter}][type]" class="form-select" onchange="toggleOptions(this, ${questionCounter})" required>
                        <option value="text">Text</option>
                        <option value="mcq">Multiple Choice</option>
                    </select>
                </div>
                <div class="options-container" id="options_${questionCounter}" style="display: none;">
                    <div class="option-block">
                        <label class="form-label">Option A</label>
                        <input type="text" name="questions[${questionCounter}][options][0][text]" class="form-control">
                        <div class="form-check">
                            <input type="radio" name="questions[${questionCounter}][correct]" value="0" class="form-check-input">
                            <label class="form-check-label">Correct</label>
                        </div>
                    </div>
                    <div class="option-block">
                        <label class="form-label">Option B</label>
                        <input type="text" name="questions[${questionCounter}][options][1][text]" class="form-control">
                        <div class="form-check">
                            <input type="radio" name="questions[${questionCounter}][correct]" value="1" class="form-check-input">
                            <label class="form-check-label">Correct</label>
                        </div>
                    </div>
                    <div class="option-block">
                        <label class="form-label">Option C</label>
                        <input type="text" name="questions[${questionCounter}][options][2][text]" class="form-control">
                        <div class="form-check">
                            <input type="radio" name="questions[${questionCounter}][correct]" value="2" class="form-check-input">
                            <label class="form-check-label">Correct</label>
                        </div>
                    </div>
                    <div class="option-block">
                        <label class="form-label">Option D</label>
                        <input type="text" name="questions[${questionCounter}][options][3][text]" class="form-control">
                        <div class="form-check">
                            <input type="radio" name="questions[${questionCounter}][correct]" value="3" class="form-check-input">
                            <label class="form-check-label">Correct</label>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-danger btn-sm mt-2" onclick="this.parentElement.remove()">Remove Question</button>
            `;
            container.appendChild(questionBlock);
        }

        function toggleOptions(select, counter) {
            const optionsContainer = document.getElementById(`options_${counter}`);
            const isMCQ = select.value === 'mcq';
            optionsContainer.style.display = isMCQ ? 'block' : 'none';
            const inputs = optionsContainer.querySelectorAll('input[type="text"]');
            inputs.forEach(input => input.required = isMCQ);
            const radios = optionsContainer.querySelectorAll('input[type="radio"]');
            if (isMCQ) {
                radios[0].required = true;
            } else {
                radios.forEach(radio => radio.required = false);
            }
        }
    </script>
</body>
</html>

<?php
$available_questions_stmt->close();
$conn->close();
?>