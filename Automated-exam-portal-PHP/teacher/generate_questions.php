<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();

// Fetch user's profile photo (still needed for session validation, though not displayed)
$user_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
$user_stmt->bind_param('i', $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result()->fetch_assoc();
$profile_photo = $user_result['profile_photo'] ?: 'https://via.placeholder.com/40';
$user_stmt->close();

$error = '';
$success = '';
$questions = '';
$generated_questions = [];

date_default_timezone_set('UTC'); // Ensure consistent timezone

// Clear generated questions if coming from dashboard or revisiting the page without a POST action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_GET['from_dashboard'])) {
    unset($_SESSION['generated_questions']);
    unset($_SESSION['topic']);
    unset($_SESSION['question_type']);
    unset($_SESSION['manual_question']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['manual_add'])) {
        $topic = filter_input(INPUT_POST, 'topic', FILTER_SANITIZE_SPECIAL_CHARS);
        $question_text = filter_input(INPUT_POST, 'question_text', FILTER_SANITIZE_SPECIAL_CHARS);
        $question_type = filter_input(INPUT_POST, 'question_type', FILTER_SANITIZE_SPECIAL_CHARS);
        $teacher_id = $_SESSION['user_id'];

        if (!$topic || !$question_text) {
            $error = 'Please provide topic and question text.';
        } else {
            $manual_question = [
                'text' => $question_text,
                'type' => $question_type,
                'topic' => $topic
            ];
            if ($question_type === 'mcq') {
                $options = [
                    filter_input(INPUT_POST, 'option1', FILTER_SANITIZE_SPECIAL_CHARS),
                    filter_input(INPUT_POST, 'option2', FILTER_SANITIZE_SPECIAL_CHARS),
                    filter_input(INPUT_POST, 'option3', FILTER_SANITIZE_SPECIAL_CHARS),
                    filter_input(INPUT_POST, 'option4', FILTER_SANITIZE_SPECIAL_CHARS),
                ];
                $correct_option = filter_input(INPUT_POST, 'correct_option', FILTER_VALIDATE_INT);
                if (count(array_filter($options)) < 4 || $correct_option === false || $correct_option < 1 || $correct_option > 4) {
                    $error = "Please provide 4 valid options and select a correct one.";
                } else {
                    $manual_question['options'] = $options;
                    $manual_question['correct_option'] = $correct_option;
                    $_SESSION['manual_question'] = $manual_question;
                    $success = "Question ready for assignment. Choose an option below.";
                }
            } else {
                $_SESSION['manual_question'] = $manual_question;
                $success = "Question ready for assignment. Choose an option below.";
            }
        }
    } elseif (isset($_POST['generate']) || isset($_POST['refresh'])) {
        $topic = filter_input(INPUT_POST, 'topic', FILTER_SANITIZE_SPECIAL_CHARS);
        $num_questions = filter_input(INPUT_POST, 'num_questions', FILTER_VALIDATE_INT);
        $question_type = filter_input(INPUT_POST, 'question_type', FILTER_SANITIZE_SPECIAL_CHARS);

        if (!$topic || !$num_questions || !$question_type || $num_questions <= 0) {
            $error = 'Please provide a valid topic, number of questions, and question type.';
        } else {
            $templates_file = __DIR__ . '/question_templates.json';
            if (!file_exists($templates_file)) {
                $error = "Question templates file not found.";
            } else {
                $templates_data = json_decode(file_get_contents($templates_file), true);
                $templates = $templates_data['templates'][$question_type] ?? [];
                $template_count = count($templates);

                if ($template_count === 0) {
                    $error = "No templates available for $question_type.";
                } elseif ($num_questions > $template_count) {
                    $error = "Requested $num_questions questions, but only $template_count templates available. Generating maximum possible.";
                    $num_questions = $template_count;
                }

                $questions = "Generated questions for topic: <strong>$topic</strong><br>";
                $generated_questions = [];

                $selected_indices = array_rand($templates, $num_questions);
                if (!is_array($selected_indices)) {
                    $selected_indices = [$selected_indices];
                }

                foreach ($selected_indices as $i) {
                    $template = $templates[$i];
                    $question_text = str_replace('{topic}', $topic, $template['question']);

                    if ($question_type === 'mcq') {
                        $options = $template['options'];
                        $correct_option = $template['correct'] + 1;

                        $questions .= "Q" . (count($generated_questions) + 1) . ": $question_text<br>";
                        foreach ($options as $j => $opt) {
                            $questions .= chr(65 + $j) . ": $opt" . ($j + 1 === $correct_option ? " (Correct)" : "") . "<br>";
                        }
                        $questions .= "<br>";

                        $generated_questions[] = [
                            'text' => $question_text,
                            'options' => $options,
                            'correct_option' => $correct_option
                        ];
                    } else {
                        $questions .= "Q" . (count($generated_questions) + 1) . ": $question_text<br><br>";
                        $generated_questions[] = [
                            'text' => $question_text
                        ];
                    }
                }

                $_SESSION['generated_questions'] = $generated_questions;
                $_SESSION['topic'] = $topic;
                $_SESSION['question_type'] = $question_type;
            }
        }
    } elseif (isset($_POST['save_questions'])) {
        $teacher_id = $_SESSION['user_id'];
        $selected_questions = $_POST['selected_questions'] ?? [];
        $exam_action = $_POST['exam_action'] ?? 'none';

        if (!isset($_SESSION['generated_questions']) && !isset($_SESSION['manual_question'])) {
            $error = 'No questions to save. Generate or add a question first.';
        } else {
            $conn->begin_transaction();
            $has_error = false;
            $question_ids = [];
            $exam_id = null;
            $exam_title = null;

            try {
                $stmt = $conn->prepare("INSERT INTO questions (teacher_id, topic, question_text, question_type) VALUES (?, ?, ?, ?)");

                // Handle generated questions
                if (isset($_SESSION['generated_questions']) && !isset($_SESSION['manual_question'])) {
                    $topic = $_SESSION['topic'];
                    $question_type = $_SESSION['question_type'];
                    foreach ($_SESSION['generated_questions'] as $index => $q) {
                        $question_text = $q['text'];
                        $stmt->bind_param('isss', $teacher_id, $topic, $question_text, $question_type);
                        if ($stmt->execute()) {
                            $question_ids[$index] = $conn->insert_id;
                            if ($question_type === 'mcq') {
                                $option_stmt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                                foreach ($q['options'] as $i => $option_text) {
                                    $is_correct = ($i + 1 === $q['correct_option']) ? 1 : 0;
                                    $option_stmt->bind_param('isi', $question_ids[$index], $option_text, $is_correct);
                                    if (!$option_stmt->execute()) {
                                        throw new Exception("Failed to save option: " . $option_stmt->error);
                                    }
                                }
                                $option_stmt->close();
                            }
                        } else {
                            throw new Exception("Failed to save question: " . $stmt->error);
                        }
                    }
                }

                // Handle manual question
                if (isset($_SESSION['manual_question'])) {
                    $manual_q = $_SESSION['manual_question'];
                    $topic = $manual_q['topic'];
                    $question_text = $manual_q['text'];
                    $question_type = $manual_q['type'];
                    $stmt->bind_param('isss', $teacher_id, $topic, $question_text, $question_type);
                    if ($stmt->execute()) {
                        $question_ids[0] = $conn->insert_id;
                        if ($question_type === 'mcq') {
                            $option_stmt = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                            foreach ($manual_q['options'] as $i => $option_text) {
                                $is_correct = ($i + 1 === $manual_q['correct_option']) ? 1 : 0;
                                $option_stmt->bind_param('isi', $question_ids[0], $option_text, $is_correct);
                                if (!$option_stmt->execute()) {
                                    throw new Exception("Failed to save option: " . $option_stmt->error);
                                }
                            }
                            $option_stmt->close();
                        }
                    } else {
                        throw new Exception("Failed to save question: " . $stmt->error);
                    }
                }

                $stmt->close();

                if (empty($question_ids)) {
                    throw new Exception("No questions were saved.");
                }

                if ($exam_action === 'new' && !empty($selected_questions)) {
                    $new_exam_title = filter_input(INPUT_POST, 'new_exam_title', FILTER_SANITIZE_SPECIAL_CHARS);
                    $deadline_raw = filter_input(INPUT_POST, 'deadline', FILTER_SANITIZE_SPECIAL_CHARS) ?: null;
                    $start_at_raw = filter_input(INPUT_POST, 'start_at', FILTER_SANITIZE_SPECIAL_CHARS) ?: null;
                    $publish_immediately = isset($_POST['publish_immediately']) ? 1 : 0;

                    $now = new DateTime('now', new DateTimeZone('UTC'));

                    // Convert IST inputs to UTC for storage
                    $start_at = $start_at_raw ? DateTime::createFromFormat('Y-m-d\TH:i', $start_at_raw, new DateTimeZone('Asia/Kolkata')) : null;
                    if ($start_at) {
                        $start_at->setTimezone(new DateTimeZone('UTC'));
                    }
                    $deadline = $deadline_raw ? DateTime::createFromFormat('Y-m-d\TH:i', $deadline_raw, new DateTimeZone('Asia/Kolkata')) : null;
                    if ($deadline) {
                        $deadline->setTimezone(new DateTimeZone('UTC'));
                    }

                    if (!$new_exam_title) {
                        throw new Exception("Please provide a title for the new exam.");
                    }
                    if ($start_at && $start_at < $now) {
                        throw new Exception("Start time cannot be in the past.");
                    }
                    if ($deadline && $deadline < $now) {
                        throw new Exception("Deadline cannot be in the past.");
                    }
                    if ($start_at && $deadline && $start_at >= $deadline) {
                        throw new Exception("Start time must be before the deadline.");
                    }

                    $status = $publish_immediately ? 'published' : 'draft';
                    $start_at_db = $start_at ? $start_at->format('Y-m-d H:i:s') : null;
                    $deadline_db = $deadline ? $deadline->format('Y-m-d H:i:s') : null;

                    $exam_stmt = $conn->prepare("INSERT INTO exams (teacher_id, title, deadline, start_at, status) VALUES (?, ?, ?, ?, ?)");
                    $exam_stmt->bind_param('issss', $teacher_id, $new_exam_title, $deadline_db, $start_at_db, $status);
                    if (!$exam_stmt->execute()) {
                        throw new Exception("Failed to create exam: " . $exam_stmt->error);
                    }
                    $exam_id = $conn->insert_id;
                    $exam_title = $new_exam_title;
                    $exam_stmt->close();
                } elseif ($exam_action === 'existing' && !empty($selected_questions)) {
                    $exam_id = filter_input(INPUT_POST, 'exam_id', FILTER_VALIDATE_INT);
                    if (!$exam_id || $exam_id <= 0) {
                        throw new Exception("Please select a valid existing exam.");
                    }
                    $title_stmt = $conn->prepare("SELECT title FROM exams WHERE id = ? AND teacher_id = ? AND status = 'draft'");
                    $title_stmt->bind_param('ii', $exam_id, $teacher_id);
                    $title_stmt->execute();
                    $title_result = $title_stmt->get_result()->fetch_assoc();
                    if (!$title_result) {
                        throw new Exception("Selected exam is not a draft exam or does not exist.");
                    }
                    $exam_title = $title_result['title'];
                    $title_stmt->close();
                }

                if ($exam_id && !empty($selected_questions)) {
                    $assign_stmt = $conn->prepare("UPDATE questions SET exam_id = ? WHERE id = ?");
                    foreach ($selected_questions as $index) {
                        if (isset($question_ids[$index])) {
                            $qid = $question_ids[$index];
                            $assign_stmt->bind_param('ii', $exam_id, $qid);
                            if (!$assign_stmt->execute()) {
                                throw new Exception("Failed to assign question ID $qid to exam: " . $assign_stmt->error);
                            }
                        }
                    }
                    $assign_stmt->close();
                }

                $conn->commit();

                unset($_SESSION['generated_questions']);
                unset($_SESSION['topic']);
                unset($_SESSION['question_type']);
                unset($_SESSION['manual_question']);

                if ($exam_id && !empty($selected_questions)) {
                    if ($exam_action === 'new') {
                        $success = "Selected questions assigned to new exam '$exam_title' successfully! Status: " . ($publish_immediately ? 'Published' : 'Draft');
                    } else {
                        $success = "Selected questions assigned to existing exam '$exam_title' successfully!";
                    }
                } else {
                    $success = "Question saved successfully!";
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// Fetch only draft exams for the dropdown
$exams_stmt = $conn->prepare("SELECT id, title FROM exams WHERE teacher_id = ? AND status = 'draft'");
$exams_stmt->bind_param('i', $_SESSION['user_id']);
$exams_stmt->execute();
$exams = $exams_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exams_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP - Generate question</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef2ff, #d9e2ff);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            padding: 15px 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
        }
        .navbar-brand {
            color: #fff !important;
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .aep-title {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .aep-subtitle {
            font-size: 1.2rem;
            font-weight: 500;
        }
        .navbar-brand:hover, .nav-link:hover {
            color: #e0e0e0 !important;
        }
        .nav-link {
            color: #fff !important;
            font-weight: 600;
        }
        .container {
            max-width: 900px;
            margin: 100px auto 50px auto; /* Adjusted for fixed navbar */
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            animation: slideIn 0.5s ease-in-out;
        }
        h2, h3, h4 {
            color: #2c3e50;
            font-weight: 700;
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
        .btn-primary {
            background: #6e8efb;
            border: none;
        }
        .btn-primary:hover {
            background: #5a75e3;
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn-success {
            background: #28a745;
            border: none;
        }
        .btn-success:hover {
            background: #218838;
        }
        .alert {
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
        .question-list {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #f9f9f9;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #6e8efb;
            box-shadow: 0 0 0 0.2rem rgba(110, 142, 251, 0.25);
        }
        .mcq-fields {
            display: none;
            margin-top: 15px;
        }
        footer {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            text-align: center;
            padding: 15px 0;
            width: 100%;
            margin-top: auto;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 15px 15px 0 0;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 768px) {
            .navbar {
                padding: 10px 15px;
            }
            .aep-title {
                font-size: 1.5rem;
            }
            .aep-subtitle {
                font-size: 1rem;
            }
            .container {
                margin-top: 80px; /* Adjusted for smaller screens */
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>/teacher/generate_questions.php">
                <span class="aep-title">AEP Teacher - Add/Generate Question</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo SITE_URL; ?>/teacher/dashboard.php">Dashboard</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <a href="dashboard.php?from_dashboard=1" class="btn btn-secondary btn-custom mb-3"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success && !isset($_SESSION['manual_question']) && !isset($_SESSION['generated_questions'])): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['generated_questions'])): ?>
            <div class="question-list">
                <h4>Generated Questions</h4>
                <form method="POST" id="assignForm">
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()">
                        <label class="form-check-label" for="selectAll">Select All</label>
                    </div>
                    <?php foreach ($_SESSION['generated_questions'] as $index => $q): ?>
                        <div class="form-check mb-2">
                            <input type="checkbox" name="selected_questions[]" value="<?php echo $index; ?>" class="form-check-input question-checkbox" id="q<?php echo $index; ?>">
                            <label class="form-check-label" for="q<?php echo $index; ?>">
                                <strong>Q<?php echo $index + 1; ?>:</strong> <?php echo htmlspecialchars($q['text']); ?>
                                <?php if ($_SESSION['question_type'] === 'mcq'): ?>
                                    <br>
                                    <?php foreach ($q['options'] as $i => $opt): ?>
                                        <?php echo chr(65 + $i) . ": " . htmlspecialchars($opt) . ($i + 1 === $q['correct_option'] ? " (Correct)" : ""); ?><br>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>

                    <div class="mt-4">
                        <button type="submit" name="refresh" class="btn btn-success btn-custom mb-3" 
                                onclick="document.getElementById('refreshTopic').value = '<?php echo htmlspecialchars($_SESSION['topic']); ?>'; 
                                         document.getElementById('refreshNum').value = '<?php echo count($_SESSION['generated_questions']); ?>'; 
                                         document.getElementById('refreshType').value = '<?php echo htmlspecialchars($_SESSION['question_type']); ?>';">
                            <i class="bi bi-arrow-repeat"></i> Refresh
                        </button>
                        <input type="hidden" name="topic" id="refreshTopic">
                        <input type="hidden" name="num_questions" id="refreshNum">
                        <input type="hidden" name="question_type" id="refreshType">

                        <h5>Exam Assignment Options</h5>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="radio" name="exam_action" value="none" class="form-check-input" id="noExam" onchange="toggleExamFields()">
                                <label class="form-check-label" for="noExam">Save Without Assigning to Exam</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="radio" name="exam_action" value="existing" class="form-check-input" id="existingExam" onchange="toggleExamFields()">
                                <label class="form-check-label" for="existingExam">Assign Selected to Existing Draft Exam</label>
                            </div>
                            <div id="existing_exam_fields" style="display: none;" class="ms-4 mt-2">
                                <select name="exam_id" class="form-select">
                                    <option value="">Select a Draft Exam</option>
                                    <?php foreach ($exams as $exam): ?>
                                        <option value="<?php echo $exam['id']; ?>"><?php echo htmlspecialchars($exam['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="radio" name="exam_action" value="new" class="form-check-input" id="newExam" onchange="toggleExamFields()">
                                <label class="form-check-label" for="newExam">Create New Exam for Selected</label>
                            </div>
                            <div id="new_exam_fields" style="display: none;" class="ms-4 mt-2">
                                <div class="mb-3">
                                    <label for="new_exam_title" class="form-label">Exam Title</label>
                                    <input type="text" name="new_exam_title" id="new_exam_title" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="start_at" class="form-label">Start Time (IST, Optional)</label>
                                    <input type="datetime-local" name="start_at" id="start_at" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="deadline" class="form-label">Deadline (IST, Optional)</label>
                                    <input type="datetime-local" name="deadline" id="deadline" class="form-control">
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="publish_immediately" id="publish_immediately" class="form-check-input">
                                    <label class="form-check-label" for="publish_immediately">Publish Immediately</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="save_questions" class="btn btn-primary btn-custom" id="actionButton">Save Questions</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['manual_question']) && !$error): ?>
            <div class="question-list">
                <h4>Manually Added Question</h4>
                <form method="POST" id="manualAssignForm">
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="selectManual" onchange="toggleSelectManual()" checked>
                        <label class="form-check-label" for="selectManual">Select</label>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="selected_questions[]" value="0" class="form-check-input question-checkbox" id="manualQ" checked>
                        <label class="form-check-label" for="manualQ">
                            <strong>Q1:</strong> <?php echo htmlspecialchars($_SESSION['manual_question']['text']); ?>
                            <?php if ($_SESSION['manual_question']['type'] === 'mcq'): ?>
                                <br>
                                <?php foreach ($_SESSION['manual_question']['options'] as $i => $opt): ?>
                                    <?php echo chr(65 + $i) . ": " . htmlspecialchars($opt) . ($i + 1 === $_SESSION['manual_question']['correct_option'] ? " (Correct)" : ""); ?><br>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </label>
                    </div>

                    <div class="mt-4">
                        <h5>Exam Assignment Options</h5>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="radio" name="exam_action" value="none" class="form-check-input" id="manualNoExam" onchange="toggleManualExamFields()">
                                <label class="form-check-label" for="manualNoExam">Save Without Assigning to Exam</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="radio" name="exam_action" value="existing" class="form-check-input" id="manualExistingExam" onchange="toggleManualExamFields()">
                                <label class="form-check-label" for="manualExistingExam">Assign to Existing Draft Exam</label>
                            </div>
                            <div id="manual_existing_exam_fields" style="display: none;" class="ms-4 mt-2">
                                <select name="exam_id" class="form-select">
                                    <option value="">Select a Draft Exam</option>
                                    <?php foreach ($exams as $exam): ?>
                                        <option value="<?php echo $exam['id']; ?>"><?php echo htmlspecialchars($exam['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="radio" name="exam_action" value="new" class="form-check-input" id="manualNewExam" onchange="toggleManualExamFields()">
                                <label class="form-check-label" for="manualNewExam">Create New Exam</label>
                            </div>
                            <div id="manual_new_exam_fields" style="display: none;" class="ms-4 mt-2">
                                <div class="mb-3">
                                    <label for="new_exam_title" class="form-label">Exam Title</label>
                                    <input type="text" name="new_exam_title" id="new_exam_title" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="start_at" class="form-label">Start Time (IST, Optional)</label>
                                    <input type="datetime-local" name="start_at" id="start_at" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label for="deadline" class="form-label">Deadline (IST, Optional)</label>
                                    <input type="datetime-local" name="deadline" id="deadline" class="form-control">
                                </div>
                                <div class="form-check mb-3">
                                    <input type="checkbox" name="publish_immediately" id="publish_immediately" class="form-check-input">
                                    <label class="form-check-label" for="publish_immediately">Publish Immediately</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="save_questions" class="btn btn-primary btn-custom" id="manualActionButton">Save Question</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <h3 class="mt-4">Generate Questions Automatically</h3>
        <form method="POST">
            <div class="mb-3">
                <label for="topic" class="form-label">Topic</label>
                <input type="text" name="topic" id="topic" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="num_questions" class="form-label">Number of Questions</label>
                <input type="number" name="num_questions" id="num_questions" class="form-control" min="1" required>
            </div>
            <div class="mb-3">
                <label for="question_type" class="form-label">Question Type</label>
                <select name="question_type" id="question_type" class="form-select" required>
                    <option value="text"></option>
                    <option value="mcq">Multiple Choice</option>
                </select>
            </div>
            <button type="submit" name="generate" class="btn btn-primary btn-custom">Generate Questions</button>
        </form>

        <h3 class="mt-4">Add Question Manually</h3>
        <form method="POST">
            <div class="mb-3">
                <label for="manual_topic" class="form-label">Topic</label>
                <input type="text" name="topic" id="manual_topic" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="question_text" class="form-label">Question Text</label>
                <textarea name="question_text" id="question_text" class="form-control" rows="3" required></textarea>
            </div>
            <div class="mb-3">
                <label for="manual_question_type" class="form-label">Question Type</label>
                <select name="question_type" id="manual_question_type" class="form-select" onchange="toggleMCQFields(this)" required>
                    <option value="text">Text</option>
                    <option value="mcq">Multiple Choice</option>
                </select>
            </div>
            <div class="mcq-fields" id="mcqFields">
                <div class="mb-3">
                    <label for="option1" class="form-label">Option A</label>
                    <input type="text" name="option1" id="option1" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="option2" class="form-label">Option B</label>
                    <input type="text" name="option2" id="option2" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="option3" class="form-label">Option C</label>
                    <input type="text" name="option3" id="option3" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="option4" class="form-label">Option D</label>
                    <input type="text" name="option4" id="option4" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Correct Option</label>
                    <select name="correct_option" class="form-select">
                        <option value="1">A</option>
                        <option value="2">B</option>
                        <option value="3">C</option>
                        <option value="4">D</option>
                    </select>
                </div>
            </div>
            <button type="submit" name="manual_add" class="btn btn-primary btn-custom">Add Question</button>
        </form>
    </div>

    <footer>
        <p>© <?php echo date('Y'); ?> AEP | All Rights Reserved | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank">Rajesh Behera.</a></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('#assignForm .question-checkbox');
            for (let checkbox of checkboxes) {
                checkbox.checked = selectAll.checked;
            }
        }

        function toggleSelectManual() {
            const selectManual = document.getElementById('selectManual');
            const checkbox = document.getElementById('manualQ');
            checkbox.checked = selectManual.checked;
        }

        function toggleExamFields() {
            const noExam = document.getElementById('noExam');
            const existingExam = document.getElementById('existingExam');
            const newExam = document.getElementById('newExam');
            const actionButton = document.getElementById('actionButton');
            const existingFields = document.getElementById('existing_exam_fields');
            const newFields = document.getElementById('new_exam_fields');
            const newExamTitle = document.getElementById('new_exam_title');

            existingFields.style.display = existingExam.checked ? 'block' : 'none';
            newFields.style.display = newExam.checked ? 'block' : 'none';
            newExamTitle.required = newExam.checked;

            if (noExam.checked) {
                actionButton.textContent = 'Save Questions';
            } else if (existingExam.checked) {
                actionButton.textContent = 'Assign Questions';
            } else if (newExam.checked) {
                actionButton.textContent = 'Create Exam';
            }
        }

        function toggleManualExamFields() {
            const noExam = document.getElementById('manualNoExam');
            const existingExam = document.getElementById('manualExistingExam');
            const newExam = document.getElementById('manualNewExam');
            const actionButton = document.getElementById('manualActionButton');
            const existingFields = document.getElementById('manual_existing_exam_fields');
            const newFields = document.getElementById('manual_new_exam_fields');
            const newExamTitle = document.getElementById('new_exam_title');

            existingFields.style.display = existingExam.checked ? 'block' : 'none';
            newFields.style.display = newExam.checked ? 'block' : 'none';
            newExamTitle.required = newExam.checked;

            if (noExam.checked) {
                actionButton.textContent = 'Save Question';
            } else if (existingExam.checked) {
                actionButton.textContent = 'Assign Question';
            } else if (newExam.checked) {
                actionButton.textContent = 'Create Exam';
            }
        }

        function toggleMCQFields(select) {
            const mcqFields = document.getElementById('mcqFields');
            const isMCQ = select.value === 'mcq';
            mcqFields.style.display = isMCQ ? 'block' : 'none';
            const inputs = mcqFields.querySelectorAll('input');
            inputs.forEach(input => input.required = isMCQ);
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleMCQFields(document.getElementById('manual_question_type'));
            // Ensure no radio is checked by default for generated questions
            const radios = document.querySelectorAll('#assignForm input[name="exam_action"]');
            radios.forEach(radio => radio.checked = false);
            toggleExamFields(); // Initialize button text for generated questions

            // Ensure no radio is checked by default for manual question
            const manualRadios = document.querySelectorAll('#manualAssignForm input[name="exam_action"]');
            manualRadios.forEach(radio => radio.checked = false);
            toggleManualExamFields(); // Initialize button text for manual question
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>