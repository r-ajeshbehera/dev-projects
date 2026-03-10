<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_student();

$conn = db_connect();
$student_id = $_SESSION['user_id'];
$exam_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$success = '';

if (!$exam_id) {
    header('Location: dashboard.php?error=Invalid+exam+ID');
    exit;
}

date_default_timezone_set('UTC');
$now = new DateTime('now', new DateTimeZone('UTC'));

$stmt = $conn->prepare("
    SELECT e.title, e.deadline, e.start_at, u.username AS teacher_name 
    FROM exams e 
    JOIN users u ON e.teacher_id = u.id 
    WHERE e.id = ? AND e.status IN ('published', 'republished')
");
$stmt->bind_param('i', $exam_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
if (!$exam) {
    header('Location: dashboard.php?error=Exam+not+found+or+not+published');
    exit;
}

$start_at = $exam['start_at'] ? new DateTime($exam['start_at'], new DateTimeZone('UTC')) : null;
$deadline = $exam['deadline'] ? new DateTime($exam['deadline'], new DateTimeZone('UTC')) : null;

if ($start_at && $now < $start_at) {
    header('Location: dashboard.php?error=Exam+has+not+started+yet');
    exit;
}
if ($deadline && $now > $deadline) {
    header('Location: dashboard.php?error=Exam+deadline+has+passed');
    exit;
}

$check_stmt = $conn->prepare("SELECT id FROM exam_submissions WHERE student_id = ? AND exam_id = ?");
$check_stmt->bind_param('ii', $student_id, $exam_id);
$check_stmt->execute();
$submission = $check_stmt->get_result()->fetch_assoc();
if ($submission) {
    header("Location: view_exams.php?submission_id={$submission['id']}");
    exit;
}

$questions_stmt = $conn->prepare("
    SELECT q.id, q.question_text, q.question_type 
    FROM questions q
    WHERE (q.exam_id = ? OR q.id IN (
        SELECT question_id FROM exam_questions WHERE exam_id = ?
    ))
    ORDER BY q.id
");
$questions_stmt->bind_param('ii', $exam_id, $exam_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();
$question_count = $questions_result->num_rows;

$exam_duration = $question_count * 60;
$deadline_timestamp = $deadline ? $deadline->getTimestamp() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $submission_stmt = $conn->prepare("INSERT INTO exam_submissions (student_id, exam_id, submitted_at) VALUES (?, ?, NOW())");
    $submission_stmt->bind_param('ii', $student_id, $exam_id);
    if ($submission_stmt->execute()) {
        $submission_id = $conn->insert_id;
        $answer_stmt = $conn->prepare("INSERT INTO student_answers (submission_id, question_id, selected_option_id) VALUES (?, ?, ?)");
        if (isset($_POST['answers'])) {
            foreach ($_POST['answers'] as $question_id => $option_id) {
                $question_id = filter_var($question_id, FILTER_VALIDATE_INT);
                $option_id = filter_var($option_id, FILTER_VALIDATE_INT);
                if ($question_id && $option_id) {
                    $answer_stmt->bind_param('iii', $submission_id, $question_id, $option_id);
                    $answer_stmt->execute();
                }
            }
        }
        $answer_stmt->close();
        $success = "Exam Submitted Successfully!";
    } else {
        $error = "Failed to submit exam: " . $submission_stmt->error;
    }
    $submission_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Exam - AEP</title>
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
        }
        .navbar-brand, .nav-link {
            color: #fff !important;
            font-weight: 600;
        }
        .navbar-brand:hover, .nav-link:hover {
            color: #e0e0e0 !important;
        }
        .exam-container {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            max-width: 900px;
            margin: 80px auto 50px auto;
        }
        .question-box {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            background: #f9f9f9;
        }
        .btn-submit {
            border-radius: 25px;
            padding: 10px 30px;
            background: #6e8efb;
            border: none;
            transition: transform 0.3s ease;
        }
        .btn-submit:hover {
            transform: scale(1.05);
            background: #5a75e3;
        }
        .teacher-info {
            font-size: 1.1em;
            color: #555;
            text-align: center;
        }
        .timer {
            font-size: 1.2em;
            font-weight: bold;
            color: #dc3545;
            text-align: center;
            margin-bottom: 20px;
        }
        .score-card {
            background: linear-gradient(135deg, #6e8efb, #a777e3);
            color: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-around;
            text-align: center;
            animation: pulse 2s infinite;
        }
        .score-card div {
            flex: 1;
        }
        .score-card h5 {
            margin-bottom: 10px;
            font-size: 1.1em;
        }
        .score-card p {
            font-size: 1.5em;
            font-weight: 700;
            margin: 0;
        }
        .alert {
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: 90%;
            max-width: 600px;
        }
        .footer {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
            width: 100%;
            margin-left: 0;
            margin-right: 0;
            padding-left: 0;
            padding-right: 0;
        }
        .footer-text {
            font-size: 0.9rem;
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: opacity 0.3s ease;
        }
        .footer-text:hover {
            opacity: 0.9;
        }
        .footer a {
            color: #fff;
            text-decoration: underline;
        }
        .footer a:hover {
            color: #e0e0e0;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        @media (max-width: 768px) {
            .exam-container {
                padding: 15px;
                margin: 60px 10px 50px 10px;
            }
            .btn-submit {
                width: 100%;
                margin-bottom: 10px;
            }
            .timer {
                font-size: 1em;
            }
            .score-card {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body oncontextmenu="return false;">
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>/student/dashboard.php">AEP - Student</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo SITE_URL; ?>/auth/logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="exam-container">
        <h2 class="text-center mb-4">Take Exam: <?php echo htmlspecialchars($exam['title']); ?></h2>
        <p class="teacher-info">Created by: <strong><?php echo htmlspecialchars($exam['teacher_name']); ?></strong></p>
        <p class="text-center">Start Time: <strong><?php echo $exam['start_at'] ? date('F j, Y g:i A', strtotime($exam['start_at']) + 19800) : 'Immediate'; ?></strong></p>
        <p class="text-center">Deadline: <strong><?php echo $exam['deadline'] ? date('F j, Y g:i A', strtotime($exam['deadline']) + 19800) : 'No Deadline'; ?></strong></p>
        <p class="timer" id="timer">Time Remaining: Calculating...</p>

        <?php if ($question_count === 0): ?>
            <div class="alert alert-warning">
                No questions have been assigned to this exam yet. Please contact your teacher.
            </div>
        <?php else: ?>
            <div class="score-card" id="scoreCard">
                <div>
                    <h5>Total Questions</h5>
                    <p id="totalQuestions"><?php echo $question_count; ?></p>
                </div>
                <div>
                    <h5>Answered</h5>
                    <p id="answeredQuestions">0</p>
                </div>
                <div>
                    <h5>Remaining</h5>
                    <p id="remainingQuestions"><?php echo $question_count; ?></p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" id="alertMessage"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" id="alertMessage"><?php echo $success; ?></div>
            <?php endif; ?>

            <?php if (!$success): ?>
                <form method="POST" id="examForm">
                    <input type="hidden" name="auto_submit" id="autoSubmit" value="0">
                    <?php 
                    $questions_result->data_seek(0);
                    $question_number = 1;
                    while ($question = $questions_result->fetch_assoc()): 
                    ?>
                        <div class="question-box" id="question_<?php echo $question['id']; ?>">
                            <p><strong>Question <?php echo $question_number++; ?>: <?php echo htmlspecialchars($question['question_text']); ?></strong></p>
                            <?php if ($question['question_type'] === 'mcq'): ?>
                                <?php
                                $option_stmt = $conn->prepare("SELECT id, option_text FROM question_options WHERE question_id = ?");
                                $option_stmt->bind_param('i', $question['id']);
                                $option_stmt->execute();
                                $options_result = $option_stmt->get_result();
                                ?>
                                <?php while ($option = $options_result->fetch_assoc()): ?>
                                    <div class="form-check">
                                        <input type="radio" name="answers[<?php echo $question['id']; ?>]" value="<?php echo $option['id']; ?>" 
                                               class="form-check-input" id="option_<?php echo $option['id']; ?>">
                                        <label class="form-check-label" for="option_<?php echo $option['id']; ?>">
                                            <?php echo htmlspecialchars($option['option_text']); ?>
                                        </label>
                                    </div>
                                <?php endwhile; ?>
                                <?php $option_stmt->close(); ?>
                            <?php else: ?>
                                <textarea name="answers[<?php echo $question['id']; ?>]" class="form-control" disabled></textarea>
                                <small class="text-muted">Text questions not supported yet.</small>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                    <div class="text-center">
                        <button type="submit" class="btn btn-submit" id="submitBtn">Submit Exam</button>
                        <a href="dashboard.php" class="btn btn-secondary btn-submit" id="backBtn">Back to Dashboard</a>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-center">
                    <a href="view_exams.php?submission_id=<?php echo $submission_id; ?>" class="btn btn-submit">View Results</a>
                    <a href="dashboard.php" class="btn btn-secondary btn-submit">Back to Dashboard</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer class="footer mt-auto py-3">
        <div class="text-center">
            <p class="footer-text mb-0">© <?php echo date('Y'); ?> AEP | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank">Rajesh Behera</a></p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($question_count > 0 && !$success): ?>
        const IST_OFFSET = 19800000; // UTC+05:30 in milliseconds
        let durationTimeLeft = <?php echo $exam_duration; ?>;
        const deadlineTimestamp = <?php echo $deadline_timestamp ? $deadline_timestamp * 1000 : 'null'; ?>;
        const timerElement = document.getElementById('timer');
        const form = document.getElementById('examForm');
        const autoSubmitInput = document.getElementById('autoSubmit');
        let examStarted = true;
        let isAutoSubmitting = false;

        function updateTimer() {
            if (isAutoSubmitting) return;

            const nowUTC = Date.now();
            const nowIST = nowUTC + IST_OFFSET;
            let timeLeft;

            if (deadlineTimestamp) {
                const deadlineTimeLeft = Math.max(0, Math.floor((deadlineTimestamp + IST_OFFSET - nowIST) / 1000));
                timeLeft = Math.min(durationTimeLeft, deadlineTimeLeft);
            } else {
                timeLeft = durationTimeLeft;
            }

            if (timeLeft > 0) {
                let minutes = Math.floor(timeLeft / 60);
                let seconds = timeLeft % 60;
                timerElement.textContent = `Time Remaining: ${minutes}:${seconds < 10 ? '0' + seconds : seconds}`;
                durationTimeLeft--;
                setTimeout(updateTimer, 1000);
            } else {
                isAutoSubmitting = true;
                examStarted = false;
                timerElement.textContent = 'Time Out!';
                setTimeout(() => {
                    timerElement.textContent = 'Submitting...';
                    setTimeout(() => {
                        autoSubmitInput.value = '1';
                        form.submit();
                    }, 2000); // 2 seconds for "Submitting..."
                }, 1000); // 1 second for "Time Out!"
            }
        }
        updateTimer();

        const totalQuestions = <?php echo $question_count; ?>;
        let answeredQuestions = 0;
        const answeredElement = document.getElementById('answeredQuestions');
        const remainingElement = document.getElementById('remainingQuestions');

        function updateScoreCard() {
            answeredElement.textContent = answeredQuestions;
            remainingElement.textContent = totalQuestions - answeredQuestions;
        }

        const radios = document.querySelectorAll('input[type="radio"]');
        radios.forEach(radio => {
            radio.addEventListener('change', function() {
                const questionId = this.name.match(/answers\[(\d+)\]/)[1];
                const questionRadios = document.querySelectorAll(`input[name="answers[${questionId}]"]`);
                let wasPreviouslyAnswered = Array.from(questionRadios).some(r => r !== this && r.checked);
                if (!wasPreviouslyAnswered) {
                    answeredQuestions++;
                    updateScoreCard();
                }
            });
        });

        const submitBtn = document.getElementById('submitBtn');
        form.addEventListener('submit', function(e) {
            submitBtn.disabled = true;
            examStarted = false;
            if (!isAutoSubmitting) {
                isAutoSubmitting = true; // Prevent multiple submissions
            }
        });

        const backBtn = document.getElementById('backBtn');
        backBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (examStarted) {
                if (confirm('Are you sure you want to leave? Your answers will be submitted.')) {
                    form.submit();
                }
            } else {
                window.location.href = this.href;
            }
        });

        window.history.pushState(null, null, window.location.href);
        window.onpopstate = function(event) {
            if (examStarted && !isAutoSubmitting) {
                if (confirm('Are you sure you want to leave? Your answers will be submitted.')) {
                    form.submit();
                } else {
                    window.history.pushState(null, null, window.location.href);
                }
            }
        };

        window.addEventListener('beforeunload', function(e) {
            if (examStarted && !isAutoSubmitting) {
                const confirmationMessage = 'Are you sure you want to leave? Your answers will be submitted.';
                e.returnValue = confirmationMessage;
                return confirmationMessage;
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'PrintScreen' || (e.ctrlKey && (e.key === 's' || e.key === 'p')) || (e.metaKey && e.key === 's')) {
                e.preventDefault();
                alert('Screenshots are not allowed during the exam.');
            }
        });

        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            alert('Right-click is disabled during the exam.');
        });
        <?php endif; ?>

        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('alertMessage');
            if (alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                        <?php if ($success): ?>
                        setTimeout(() => {
                            window.location.href = 'view_exams.php?submission_id=<?php echo $submission_id; ?>';
                        }, 500); // Wait for fade-out to complete
                        <?php endif; ?>
                    }, 2000); // Display success message for 2 seconds
                }, 0); // Start fade-out immediately after DOM load
            }
        });
    </script>
</body>
</html>

<?php
$stmt->close();
$check_stmt->close();
$questions_stmt->close();
$conn->close();
?>