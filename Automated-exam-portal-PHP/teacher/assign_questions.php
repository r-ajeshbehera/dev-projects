<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering to prevent headers already sent errors
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_teacher();

$conn = db_connect();
$teacher_id = $_SESSION['user_id'];

// Fetch teacher's exams for selection
$exams_stmt = $conn->prepare("SELECT id, title FROM exams WHERE teacher_id = ? AND status = 'draft'");
$exams_stmt->bind_param('i', $teacher_id);
$exams_stmt->execute();
$exams_result = $exams_stmt->get_result();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question_ids'])) {
    $question_ids = $_POST['question_ids'];
    
    if ($_POST['exam_id'] ?? false) {
        $exam_id = filter_input(INPUT_POST, 'exam_id', FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("UPDATE questions SET exam_id = ? WHERE id = ? AND teacher_id = ?");
        foreach ($question_ids as $qid) {
            $stmt->bind_param('iii', $exam_id, $qid, $teacher_id);
            $stmt->execute();
        }
        $stmt->close();
        header("Location: dashboard.php?success=" . urlencode("Questions assigned successfully!"));
        ob_end_flush();
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP- Assign Questions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f0f4ff, #e6e9ff);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }
        .assign-container {
            max-width: 600px;
            margin: 5rem auto;
            padding: 2rem;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .assign-container:hover {
            transform: translateY(-5px);
        }
        .assign-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .form-label {
            font-weight: 500;
            color: #555;
        }
        .form-select {
            border-radius: 8px;
            padding: 0.75rem;
            border: 1px solid #ddd;
            transition: border-color 0.3s ease;
        }
        .form-select:focus {
            border-color: #6e8efb;
            box-shadow: 0 0 5px rgba(110, 142, 251, 0.5);
        }
        .btn-custom {
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            transition: background 0.3s ease, transform 0.3s ease;
        }
        .btn-custom:hover {
            transform: scale(1.05);
        }
        .btn-success {
            background: #28a745;
            border: none;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="assign-container">
            <h2 class="assign-title">Assign Questions to Exam</h2>
            <?php if (!isset($_POST['question_ids']) || empty($_POST['question_ids'])): ?>
                <div class="alert alert-danger text-center">No questions selected. Please select questions from the dashboard.</div>
                <div class="text-center">
                    <a href="dashboard.php" class="btn btn-secondary btn-custom">Back to Dashboard</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <?php foreach ($_POST['question_ids'] as $qid): ?>
                        <input type="hidden" name="question_ids[]" value="<?php echo htmlspecialchars($qid); ?>">
                    <?php endforeach; ?>
                    <div class="mb-3">
                        <label for="exam_id" class="form-label">Select Exam</label>
                        <select name="exam_id" id="exam_id" class="form-select" required>
                            <option value="" disabled selected>-- Select an Exam --</option>
                            <?php while ($exam = $exams_result->fetch_assoc()): ?>
                                <option value="<?php echo $exam['id']; ?>"><?php echo htmlspecialchars($exam['title']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-success btn-custom"><i class="bi bi-check-circle"></i> Assign Questions</button>
                        <a href="dashboard.php" class="btn btn-secondary btn-custom">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$exams_stmt->close();
ob_end_flush();
?>