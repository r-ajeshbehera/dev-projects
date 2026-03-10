<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];

// Fetch exams
$exams_stmt = $conn->prepare("SELECT id, title, deadline FROM exams WHERE teacher_id = ? AND status = 'published'");
$exams_stmt->bind_param('i', $teacher_id);
$exams_stmt->execute();
$exams_result = $exams_stmt->get_result();

// Fetch results for selected exam
$selected_exam_id = filter_input(INPUT_GET, 'exam_id', FILTER_VALIDATE_INT) ?: null;
$submissions = [];
$summary = ['total_submissions' => 0, 'avg_score' => 0, 'highest_score' => 0, 'missed_students' => 0];

if ($selected_exam_id) {
    // Fetch total questions in the exam (MCQs only for now)
    $total_questions_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT q.id) AS total_questions
        FROM questions q
        WHERE (q.exam_id = ? OR q.id IN (
            SELECT question_id FROM exam_questions WHERE exam_id = ?
        )) AND q.question_type = 'mcq'
    ");
    $total_questions_stmt->bind_param('ii', $selected_exam_id, $selected_exam_id);
    $total_questions_stmt->execute();
    $total_questions_result = $total_questions_stmt->get_result()->fetch_assoc();
    $total_questions = $total_questions_result['total_questions'] ?? 0;

    // Fetch submissions with correct answers count
    $submissions_stmt = $conn->prepare("
        SELECT 
            es.id, 
            u.username, 
            es.submitted_at,
            COUNT(DISTINCT sa.question_id) AS answered_questions,
            SUM(CASE WHEN qo.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers
        FROM exam_submissions es
        JOIN users u ON es.student_id = u.id
        LEFT JOIN student_answers sa ON es.id = sa.submission_id
        LEFT JOIN question_options qo ON sa.selected_option_id = qo.id
        WHERE es.exam_id = ?
        GROUP BY es.id, u.username, es.submitted_at
    ");
    $submissions_stmt->bind_param('i', $selected_exam_id);
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();

    while ($sub = $submissions_result->fetch_assoc()) {
        $sub['total_questions'] = $total_questions;
        $submissions[] = $sub;
        $summary['total_submissions']++;
        
        $score_percent = ($total_questions > 0) ? ($sub['correct_answers'] / $total_questions) * 100 : 0;
        $summary['avg_score'] += $score_percent;
        $summary['highest_score'] = max($summary['highest_score'], $score_percent);
    }

    if ($summary['total_submissions'] > 0) {
        $summary['avg_score'] = $summary['avg_score'] / $summary['total_submissions'];
    }

    // Fetch missed students (students who didn't submit before deadline)
    $missed_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT u.id) as missed_count
        FROM users u
        LEFT JOIN exam_submissions es ON u.id = es.student_id AND es.exam_id = ?
        JOIN exams e ON e.id = ?
        WHERE u.role = 'student' 
        AND es.id IS NULL 
        AND (e.deadline IS NULL OR e.deadline < NOW())
    ");
    $missed_stmt->bind_param('ii', $selected_exam_id, $selected_exam_id);
    $missed_stmt->execute();
    $missed_result = $missed_stmt->get_result()->fetch_assoc();
    $summary['missed_students'] = $missed_result['missed_count'];

    $submissions_stmt->close();
    $missed_stmt->close();
    $total_questions_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP - View Exam Results </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            padding: 15px 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .navbar-brand {
            color: #fff !important;
            font-weight: 600;
            font-size: 1.5rem;
        }
        .navbar-brand:hover {
            color: #e0e0e0 !important;
        }
        .container {
            max-width: 900px;
            margin: 50px auto;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card-header {
            background: #007bff;
            color: white;
            border-radius: 15px 15px 0 0;
            font-weight: 500;
        }
        .summary-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .table {
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .table thead th {
            background: #007bff;
            color: white;
            cursor: pointer;
            border: none;
        }
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        .btn-custom {
            border-radius: 25px;
            padding: 10px 20px;
            font-weight: 500;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        .progress {
            height: 25px;
            border-radius: 12px;
        }
        .progress-bar {
            background-color: #007bff;
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
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">AEP Teacher - View Exam Results</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0">Results</h2>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <label for="exam_select" class="form-label fw-bold">Select Exam</label>
                    <select id="exam_select" class="form-select" onchange="location.href='view_results.php?exam_id='+this.value">
                        <option value="">-- Select an Exam --</option>
                        <?php while ($exam = $exams_result->fetch_assoc()): ?>
                            <option value="<?php echo $exam['id']; ?>" <?php echo $selected_exam_id == $exam['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($exam['title']) . ($exam['deadline'] ? " (Deadline: " . date('Y-m-d H:i', strtotime($exam['deadline'])) . ")" : ""); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <?php if ($selected_exam_id): ?>
                    <div class="summary-card">
                        <h5>Summary</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Total Submissions:</strong> <?php echo $summary['total_submissions']; ?></p>
                                <p><strong>Average Score:</strong> <?php echo number_format($summary['avg_score'], 2); ?>%</p>
                                <p><strong>Highest Score:</strong> <?php echo number_format($summary['highest_score'], 2); ?>%</p>
                                <p><strong>Missed Students:</strong> <?php echo $summary['missed_students']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <div class="progress mb-3">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: <?php echo $summary['avg_score']; ?>%" 
                                         aria-valuenow="<?php echo $summary['avg_score']; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <?php echo number_format($summary['avg_score'], 2); ?>%
                                    </div>
                                </div>
                                <small class="text-muted">Class Average</small>
                            </div>
                        </div>
                    </div>

                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Submitted At</th>
                                <th>Score</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)): ?>
                                <tr><td colspan="4" class="text-center">No submissions yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $sub): ?>
                                    <?php 
                                    $score_percent = ($sub['total_questions'] > 0) ? ($sub['correct_answers'] / $sub['total_questions']) * 100 : 0;
                                    $progress_class = '';
                                    if ($score_percent >= 80) $progress_class = 'bg-success';
                                    elseif ($score_percent >= 50) $progress_class = 'bg-warning';
                                    else $progress_class = 'bg-danger';
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($sub['username']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($sub['submitted_at'])); ?></td>
                                        <td><?php echo $sub['correct_answers'] . "/" . $sub['total_questions'] . " (" . number_format($score_percent, 2) . "%)"; ?></td>
                                        <td>
                                            <div class="progress">
                                                <div class="progress-bar <?php echo $progress_class; ?>" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $score_percent; ?>%" 
                                                     aria-valuenow="<?php echo $score_percent; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">Select an exam to view its results.</p>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <a href="dashboard.php" class="btn btn-primary btn-custom">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <p>© <?php echo date('Y'); ?> AEP | All Rights Reserved | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank">Rajesh Behera.</a></p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$exams_stmt->close();
$conn->close();
?>