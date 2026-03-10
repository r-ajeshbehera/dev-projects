<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_student();

$conn = db_connect();
$student_id = (int)$_SESSION['user_id'];

// Fetch all submissions for the student with teacher information
$submissions_stmt = $conn->prepare("
    SELECT es.id AS submission_id, es.exam_id, e.title, e.teacher_id, 
           u.username AS teacher_name, es.submitted_at 
    FROM exam_submissions es 
    JOIN exams e ON es.exam_id = e.id 
    JOIN users u ON e.teacher_id = u.id
    WHERE es.student_id = ?
    ORDER BY es.submitted_at DESC
");
$submissions_stmt->bind_param('i', $student_id);
$submissions_stmt->execute();
$submissions = $submissions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$selected_submission_id = filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT);
$selected_submission = null;
if ($selected_submission_id) {
    foreach ($submissions as $submission) {
        if ($submission['submission_id'] === $selected_submission_id) {
            $selected_submission = $submission;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Exams - AEP</title>
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
        .container {
            max-width: 1000px;
            margin: 80px auto 50px auto;
        }
        h2 {
            color: #2c3e50;
            font-weight: 700;
            text-align: center;
            margin-bottom: 30px;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
            background: #fff;
            margin-bottom: 30px;
        }
        .card-header {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            border-radius: 20px 20px 0 0;
            font-weight: 600;
            padding: 15px 20px;
            font-size: 1.2rem;
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
            padding: 15px;
        }
        .table tbody tr:hover {
            background: #f5f7fa;
        }
        .performance-box {
            text-align: center;
            padding: 20px;
            border-radius: 15px;
            margin-top: 20px;
            font-weight: 600;
            animation: fadeIn 1s ease-in-out;
        }
        .excellent {
            background: #d4edda;
            color: #155724;
        }
        .good {
            background: #fff3cd;
            color: #856404;
        }
        .needs-improvement {
            background: #f8d7da;
            color: #721c24;
        }
        .question-details {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #f9f9f9;
        }
        .correct {
            color: #28a745;
            font-weight: bold;
        }
        .incorrect {
            color: #dc3545;
            font-weight: bold;
        }
        .unanswered {
            color: #6c757d;
            font-style: italic;
        }
        .btn-custom {
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            transform: scale(1.05);
        }
        .form-select {
            max-width: 400px;
            margin: 0 auto 20px auto;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }
        .teacher-name {
            font-size: 0.9rem;
            color: #6c757d;
            font-style: italic;
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
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (max-width: 768px) {
            .container { margin: 60px 10px 50px 10px; }
            .table { font-size: 0.9rem; }
            .performance-box { font-size: 1rem; }
            .form-select { max-width: 100%; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>/student/dashboard.php">AEP   Student - View Exam Results</a>
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

    <div class="container">
        <h2>Results</h2>
        <a href="dashboard.php" class="btn btn-secondary btn-custom mb-3"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>

        <?php if (empty($submissions)): ?>
            <div class="card">
                <div class="card-body text-center">
                    <p class="text-muted">No exam history available.</p>
                </div>
            </div>
        <?php else: ?>
            <form method="GET" class="text-center">
                <select name="submission_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Select an Exam</option>
                    <?php foreach ($submissions as $submission): ?>
                        <option value="<?php echo $submission['submission_id']; ?>" 
                                <?php echo $selected_submission_id === $submission['submission_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($submission['title']) . " (By: " . htmlspecialchars($submission['teacher_name']) . ") - " . date('F j, Y', strtotime($submission['submitted_at'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($selected_submission): ?>
                <div class="card">
                    <div class="card-header">
                        <?php echo htmlspecialchars($selected_submission['title']); ?>
                        <div class="teacher-name">By: <?php echo htmlspecialchars($selected_submission['teacher_name']); ?></div>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get all questions for this exam (both direct and via exam_questions)
                        $questions_stmt = $conn->prepare("
                            SELECT q.id, q.question_text, q.question_type 
                            FROM questions q 
                            WHERE q.exam_id = ? OR q.id IN (
                                SELECT question_id FROM exam_questions WHERE exam_id = ?
                            )
                            ORDER BY q.id
                        ");
                        $questions_stmt->bind_param('ii', $selected_submission['exam_id'], $selected_submission['exam_id']);
                        $questions_stmt->execute();
                        $questions = $questions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $total_questions = count($questions);

                        // Get student's answers for this submission
                        $answers_stmt = $conn->prepare("
                            SELECT sa.question_id, sa.selected_option_id, qo.option_text AS selected_text, qo.is_correct 
                            FROM student_answers sa 
                            LEFT JOIN question_options qo ON sa.selected_option_id = qo.id 
                            WHERE sa.submission_id = ?
                        ");
                        $answers_stmt->bind_param('i', $selected_submission['submission_id']);
                        $answers_stmt->execute();
                        $answers_result = $answers_stmt->get_result();
                        $answers = [];
                        while ($answer = $answers_result->fetch_assoc()) {
                            $answers[$answer['question_id']] = $answer;
                        }

                        // Calculate score (MCQs only)
                        $correct_count = 0;
                        $mcq_questions = 0;
                        foreach ($questions as $question) {
                            if ($question['question_type'] === 'mcq') {
                                $mcq_questions++;
                                if (isset($answers[$question['id']])){
                                    $correct_count += $answers[$question['id']]['is_correct'] ? 1 : 0;
                                }
                            }
                        }
                        
                        $score = $mcq_questions > 0 ? ($correct_count / $mcq_questions) * 100 : 0;
                        $score_display = "$correct_count / $mcq_questions";

                        // Performance feedback
                        if ($score >= 80) {
                            $performance_class = 'excellent';
                            $performance_message = 'Excellent! Keep up the great work!';
                        } elseif ($score >= 50) {
                            $performance_class = 'good';
                            $performance_message = 'Good Effort! You\'re on the right track!';
                        } else {
                            $performance_class = 'needs-improvement';
                            $performance_message = 'Needs Improvement. Practice makes perfect!';
                        }
                        ?>

                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Exam</th>
                                    <th>Teacher</th>
                                    <th>Score (MCQs only)</th>
                                    <th>Date Taken</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo htmlspecialchars($selected_submission['title']); ?></td>
                                    <td><?php echo htmlspecialchars($selected_submission['teacher_name']); ?></td>
                                    <td><?php echo $score_display; ?> (<?php echo round($score, 2); ?>%)</td>
                                    <td><?php echo date('F j, Y, H:i', strtotime($selected_submission['submitted_at'])); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="performance-box <?php echo $performance_class; ?>">
                            <?php echo $performance_message; ?>
                        </div>

                        <div class="question-details">
                            <h5>Your Answers</h5>
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="mb-3">
                                    <p><strong>Question <?php echo $index + 1; ?>: <?php echo htmlspecialchars($question['question_text']); ?></strong></p>
                                    <?php if ($question['question_type'] === 'mcq'): ?>
                                        <?php
                                        // Get all options for this question
                                        $options_stmt = $conn->prepare("
                                            SELECT id, option_text, is_correct 
                                            FROM question_options 
                                            WHERE question_id = ?
                                        ");
                                        $options_stmt->bind_param('i', $question['id']);
                                        $options_stmt->execute();
                                        $options = $options_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                        $options_stmt->close();
                                        
                                        // Get correct answers
                                        $correct_options = array_filter($options, function($opt) {
                                            return $opt['is_correct'];
                                        });
                                        ?>
                                        
                                        <p><strong>Your Answer:</strong> 
                                            <?php if (isset($answers[$question['id']])): ?>
                                                <span class="<?php echo $answers[$question['id']]['is_correct'] ? 'correct' : 'incorrect'; ?>">
                                                    <?php echo htmlspecialchars($answers[$question['id']]['selected_text'] ?? 'N/A'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="unanswered">Not Answered</span>
                                            <?php endif; ?>
                                        </p>
                                        
                                        <p><strong>Correct Answer(s):</strong> 
                                            <span class="correct">
                                                <?php 
                                                echo implode(', ', array_map(function($opt) {
                                                    return htmlspecialchars($opt['option_text']);
                                                }, $correct_options));
                                                ?>
                                            </span>
                                        </p>
                                        
                                        <p><strong>All Options:</strong></p>
                                        <ul>
                                            <?php foreach ($options as $option): ?>
                                                <li>
                                                    <?php echo htmlspecialchars($option['option_text']); ?>
                                                    <?php if ($option['is_correct']): ?>
                                                        <span class="text-success">(Correct)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p><em>Text question: Answer not available as text responses are not stored.</em></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer>
        <p>© <?php echo date('Y'); ?> AEP | All Rights Reserved | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank">Rajesh Behera.</a></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('alertMessage');
            if (alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 2000);
            }
        });
    </script>
</body>
</html>

<?php
// Cleanup
$submissions_stmt->close();
if (isset($questions_stmt)) $questions_stmt->close();
if (isset($answers_stmt)) $answers_stmt->close();
$conn->close();
?>