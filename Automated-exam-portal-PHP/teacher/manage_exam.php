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
$error = '';
$success = '';

if (!$exam_id) {
    header('Location: dashboard.php?error=Invalid+exam+ID');
    exit;
}

// Fetch exam details
$stmt = $conn->prepare("SELECT title, status, deadline, start_at, created_at FROM exams WHERE id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $exam_id, $teacher_id);
$stmt->execute();
$stmt->bind_result($exam_title, $exam_status, $exam_deadline, $exam_start_at, $exam_created_at);
$stmt->fetch();
$stmt->close();

if (!$exam_title) {
    header('Location: dashboard.php?error=Exam+not+found');
    exit;
}

// Determine if the exam is editable (only draft status allows changes)
$is_draft = ($exam_status === 'draft');

// Fetch assigned questions
$assigned_stmt = $conn->prepare("
    SELECT q.id, q.topic, q.question_text, q.question_type 
    FROM questions q 
    WHERE q.teacher_id = ? AND (q.exam_id = ? OR q.id IN (
        SELECT question_id FROM exam_questions WHERE exam_id = ?
    ))
    ORDER BY q.id
");
$assigned_stmt->bind_param('iii', $teacher_id, $exam_id, $exam_id);
$assigned_stmt->execute();
$assigned_result = $assigned_stmt->get_result();

// Fetch available questions (only for draft exams)
$questions_stmt = $conn->prepare("
    SELECT q.id, q.topic, q.question_text, e.title AS assigned_exam_title 
    FROM questions q 
    LEFT JOIN exam_questions eq ON q.id = eq.question_id AND eq.exam_id = ?
    LEFT JOIN exams e ON q.exam_id = e.id OR eq.exam_id = e.id
    WHERE q.teacher_id = ? AND (q.exam_id IS NULL OR q.exam_id != ?) 
    AND (eq.exam_id IS NULL OR eq.exam_id != ?)
    GROUP BY q.id
    ORDER BY q.id
");
$questions_stmt->bind_param('iiii', $exam_id, $teacher_id, $exam_id, $exam_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();

// Set timezones
$ist_timezone = new DateTimeZone('Asia/Kolkata');
$now_utc = new DateTime('now', new DateTimeZone('UTC'));
$now_ist = new DateTime('now', $ist_timezone);

// Prepare display values (IST)
// Fix: Default to 'Immediate' if no start_at is set, avoiding fallback to created_at or now
$display_start_at = $exam_start_at ? (new DateTime($exam_start_at, new DateTimeZone('UTC')))->setTimezone($ist_timezone)->format('F j, Y, g:i a') : 'Immediate';
$display_deadline = $exam_deadline ? (new DateTime($exam_deadline, new DateTimeZone('UTC')))->setTimezone($ist_timezone)->format('F j, Y, g:i a') : 'Not set';

// Prepare input values (IST)
$input_start_at = $exam_start_at ? (new DateTime($exam_start_at, new DateTimeZone('UTC')))->setTimezone($ist_timezone)->format('Y-m-d\TH:i') : '';
$input_deadline = $exam_deadline ? (new DateTime($exam_deadline, new DateTimeZone('UTC')))->setTimezone($ist_timezone)->format('Y-m-d\TH:i') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish']) && $is_draft) {
    $new_title = trim($_POST['exam_title']);
    $deadline_raw = $_POST['deadline'] ?: null;
    $start_at_raw = $_POST['start_at'] ?: null;
    $question_ids = $_POST['question_ids'] ?? [];
    $delete_question_ids = $_POST['delete_question_ids'] ?? [];

    // Handle start_at: explicit value if provided, otherwise NULL for immediate publishing
    if ($start_at_raw) {
        $start_at_dt = DateTime::createFromFormat('Y-m-d\TH:i', $start_at_raw, $ist_timezone);
    } else {
        $start_at_dt = null; // Explicitly set to NULL for immediate start
    }
    $start_at = $start_at_dt ? $start_at_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;

    // Handle deadline
    $deadline_dt = $deadline_raw ? DateTime::createFromFormat('Y-m-d\TH:i', $deadline_raw, $ist_timezone) : ($exam_deadline ? new DateTime($exam_deadline, new DateTimeZone('UTC')) : null);
    $deadline = $deadline_dt ? $deadline_dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;

    // Validation
    if (empty($new_title)) {
        $error = 'Please provide an exam title.';
    } elseif ($start_at_dt && $start_at_dt < $now_utc) {
        $error = 'Start time cannot be in the past for a new exam.';
    } elseif ($deadline_dt && $deadline_dt < $now_utc) {
        $error = 'Deadline cannot be in the past.';
    } elseif ($start_at_dt && $deadline_dt && $start_at_dt >= $deadline_dt) {
        $error = 'Start time must be before the deadline.';
    } else {
        $conn->begin_transaction();
        try {
            $submission_check_stmt = $conn->prepare("SELECT COUNT(*) FROM exam_submissions WHERE exam_id = ?");
            $submission_check_stmt->bind_param('i', $exam_id);
            $submission_check_stmt->execute();
            $submission_check_stmt->bind_result($submission_count);
            $submission_check_stmt->fetch();
            $submission_check_stmt->close();

            $new_status = 'published';

            $update_exam_stmt = $conn->prepare("
                UPDATE exams 
                SET title = ?, deadline = ?, start_at = ?, status = ?, published_at = NOW()
                WHERE id = ? AND teacher_id = ?
            ");
            $update_exam_stmt->bind_param('ssssii', $new_title, $deadline, $start_at, $new_status, $exam_id, $teacher_id);
            if (!$update_exam_stmt->execute()) {
                throw new Exception("Failed to update exam: " . $update_exam_stmt->error);
            }
            $update_exam_stmt->close();

            // Assign new questions
            if (!empty($question_ids)) {
                $update_stmt = $conn->prepare("UPDATE questions SET exam_id = ? WHERE id = ? AND teacher_id = ?");
                foreach ($question_ids as $qid) {
                    $update_stmt->bind_param('iii', $exam_id, $qid, $teacher_id);
                    if (!$update_stmt->execute()) {
                        throw new Exception("Failed to update question: " . $update_stmt->error);
                    }
                }
                $update_stmt->close();

                $placeholders = implode(',', array_fill(0, count($question_ids), '(?, ?)'));
                $insert_stmt = $conn->prepare("INSERT INTO exam_questions (exam_id, question_id) VALUES $placeholders ON DUPLICATE KEY UPDATE question_id = question_id");
                $params = [];
                foreach ($question_ids as $qid) {
                    $params[] = $exam_id;
                    $params[] = $qid;
                }
                $types = str_repeat('ii', count($question_ids));
                $insert_stmt->bind_param($types, ...$params);
                if (!$insert_stmt->execute()) {
                    throw new Exception("Failed to assign questions: " . $insert_stmt->error);
                }
                $insert_stmt->close();
            }

            // Delete selected questions
            if (!empty($delete_question_ids)) {
                $placeholders = implode(',', array_fill(0, count($delete_question_ids), '?'));
                $delete_stmt = $conn->prepare("UPDATE questions SET exam_id = NULL WHERE id IN ($placeholders) AND exam_id = ? AND teacher_id = ?");
                $params = array_merge($delete_question_ids, [$exam_id, $teacher_id]);
                $types = str_repeat('i', count($delete_question_ids)) . 'ii';
                $delete_stmt->bind_param($types, ...$params);
                if (!$delete_stmt->execute()) {
                    throw new Exception("Failed to delete questions: " . $delete_stmt->error);
                }
                $delete_stmt->close();

                $delete_link_stmt = $conn->prepare("DELETE FROM exam_questions WHERE exam_id = ? AND question_id IN ($placeholders)");
                $params = array_merge([$exam_id], $delete_question_ids);
                $types = 'i' . str_repeat('i', count($delete_question_ids));
                $delete_link_stmt->bind_param($types, ...$params);
                if (!$delete_link_stmt->execute()) {
                    throw new Exception("Failed to remove question links: " . $delete_link_stmt->error);
                }
                $delete_link_stmt->close();
            }

            $conn->commit();
            $success = "Exam updated and published successfully!";
            header("Location: manage_exam.php?id=$exam_id&success=" . urlencode($success));
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
    <title>Manage Exam - AEP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #eef2ff, #d9e2ff); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; overflow-x: hidden; margin: 0; }
        .navbar { background: linear-gradient(90deg, #6e8efb, #a777e3); padding: 15px 20px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); }
        .navbar-brand, .nav-link { color: #fff !important; font-weight: 600; }
        .navbar-brand:hover, .nav-link:hover { color: #e0e0e0 !important; }
        .container { max-width: 1000px; margin: 80px auto 50px auto; }
        h2, h3 { color: #2c3e50; font-weight: 700; }
        .card { border: none; border-radius: 20px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05); background: #fff; margin-bottom: 30px; }
        .card-header { background: linear-gradient(90deg, #6e8efb, #a777e3); color: #fff; border-radius: 20px 20px 0 0; font-weight: 600; padding: 15px 20px; font-size: 1.2rem; }
        .table { background: #fff; border-radius: 15px; overflow: hidden; box-shadow: 0 6px 15px rgba(0, 0, 0, 0.05); }
        .table thead th { background: linear-gradient(90deg, #6e8efb, #a777e3); color: #fff; border: none; padding: 15px; }
        .table tbody tr:hover { background: #f5f7fa; }
        .btn-custom { border-radius: 25px; padding: 10px 25px; font-weight: 600; transition: all 0.3s ease; }
        .btn-custom:hover { transform: scale(1.05); }
        .btn-primary { background: #6e8efb; border: none; }
        .btn-primary:hover { background: #5a75e3; }
        .btn-danger { background: #dc3545; border: none; }
        .btn-danger:hover { background: #c82333; }
        .btn-publish { background: #28a745; border: none; }
        .btn-publish:hover { background: #218838; }
        .btn-secondary { background: #6c757d; border: none; }
        .btn-secondary:hover { background: #5a6268; }
        .alert { border-radius: 15px; box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1); position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; width: 90%; max-width: 600px; }
        .assigned-note { color: #dc3545; font-style: italic; }
        .deadline-info { font-size: 1.1rem; margin-bottom: 10px; }
        .start-info { font-size: 1.1rem; margin-bottom: 20px; }
        footer { background: linear-gradient(90deg, #6e8efb, #a777e3); color: #fff; text-align: center; padding: 15px 0; position: fixed; bottom: 0; width: 100%; box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.1); }
        .readonly-note { color: #6c757d; font-style: italic; margin-top: 10px; }
        @media (max-width: 768px) { .container { margin: 60px 10px 50px 10px; } .table { font-size: 0.9rem; } .btn-custom { width: 100%; margin: 10px 0; } }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>/teacher/dashboard.php">AEP Teacher - Manage Exam</a>
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
        <form method="POST">
            <div class="card">
                <div class="card-header">Exam Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="exam_title" class="form-label">Exam Title</label>
                        <input type="text" name="exam_title" id="exam_title" class="form-control" 
                               value="<?php echo htmlspecialchars($exam_title); ?>" 
                               <?php echo $is_draft ? 'required' : 'readonly'; ?>>
                        <p>Status: <span class="badge <?php echo $exam_status === 'published' ? 'bg-success' : ($exam_status === 'republished' ? 'bg-warning' : 'bg-secondary'); ?>">
                            <?php echo $exam_status; ?></span></p>
                    </div>
                    <div class="mb-3">
                        <label for="start_at" class="form-label">Set New Start Time (IST)</label>
                        <input type="datetime-local" name="start_at" id="start_at" class="form-control" 
                               value="<?php echo $input_start_at; ?>" 
                               <?php echo $is_draft ? '' : 'readonly'; ?>>
                        <small class="form-text text-muted">Leave blank for immediate start upon publishing.</small>
                    </div>
                    <div class="mb-3">
                        <label for="deadline" class="form-label">Set New Deadline (IST)</label>
                        <input type="datetime-local" name="deadline" id="deadline" class="form-control" 
                               value="<?php echo $input_deadline; ?>" 
                               <?php echo $is_draft ? '' : 'readonly'; ?>>
                        <small class="form-text text-muted">Leave blank for no deadline.</small>
                    </div>
                    <div class="start-info">
                        <strong>Current Start Time:</strong> 
                        <?php echo $display_start_at; ?>
                        <?php if ($exam_start_at && strtotime($exam_start_at) > time()): ?>
                            <span class="badge bg-warning text-dark">Scheduled</span>
                        <?php elseif ($exam_start_at && strtotime($exam_start_at) <= time()): ?>
                            <span class="badge bg-success">Started</span>
                        <?php endif; ?>
                    </div>
                    <div class="deadline-info">
                        <strong>Current Deadline:</strong> 
                        <?php echo $display_deadline; ?>
                        <?php if ($exam_deadline && strtotime($exam_deadline) < time()): ?>
                            <span class="badge bg-danger">Expired</span>
                        <?php elseif ($exam_deadline): ?>
                            <span class="badge bg-warning text-dark">Active</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$is_draft): ?>
                        <p class="readonly-note">This exam is published and cannot be modified.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" id="alertMessage"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success || isset($_GET['success'])): ?>
                <div class="alert alert-success" id="alertMessage"><?php echo $success ?: htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">Assigned Questions</div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>ID</th>
                                <th>Topic</th>
                                <th>Question Text</th>
                                <th>Type</th>
                                <?php if ($is_draft): ?>
                                    <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($assigned_result->num_rows === 0): ?>
                                <tr><td colspan="<?php echo $is_draft ? 6 : 5; ?>" class="text-center">No questions assigned yet.</td></tr>
                            <?php else: ?>
                                <?php $question_number = 1; ?>
                                <?php while ($row = $assigned_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $question_number++; ?></td>
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['topic']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($row['question_text']); ?>
                                            <?php if ($row['question_type'] === 'mcq'): ?>
                                                <?php
                                                $option_stmt = $conn->prepare("SELECT option_text, is_correct FROM question_options WHERE question_id = ?");
                                                $option_stmt->bind_param('i', $row['id']);
                                                $option_stmt->execute();
                                                $options_result = $option_stmt->get_result();
                                                while ($opt = $options_result->fetch_assoc()) {
                                                    echo "<br>- " . htmlspecialchars($opt['option_text']) . ($opt['is_correct'] ? " <span class='text-success'>(Correct)</span>" : "");
                                                }
                                                $option_stmt->close();
                                                ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $row['question_type']; ?></td>
                                        <?php if ($is_draft): ?>
                                            <td>
                                                <input type="checkbox" name="delete_question_ids[]" value="<?php echo $row['id']; ?>" class="form-check-input">
                                                <label class="form-check-label">Delete</label>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($is_draft): ?>
                <div class="card">
                    <div class="card-header">Assign More Questions</div>
                    <div class="card-body">
                        <?php if ($questions_result->num_rows === 0): ?>
                            <p>No available questions to assign. Add more in <a href="generate_questions.php" class="btn btn-info btn-sm">Generate Questions</a>.</p>
                        <?php else: ?>
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>ID</th>
                                        <th>Topic</th>
                                        <th>Question Text</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $questions_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><input type="checkbox" name="question_ids[]" value="<?php echo $row['id']; ?>"></td>
                                            <td><?php echo $row['id']; ?></td>
                                            <td><?php echo htmlspecialchars($row['topic']); ?></td>
                                            <td><?php echo htmlspecialchars($row['question_text']); ?></td>
                                            <td>
                                                <?php if ($row['assigned_exam_title']): ?>
                                                    <span class="assigned-note">Assigned to <?php echo htmlspecialchars($row['assigned_exam_title']); ?></span>
                                                <?php else: ?>
                                                    Available
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($is_draft): ?>
                <button type="submit" name="publish" class="btn btn-publish btn-custom mt-3">Publish Exam</button>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-secondary btn-custom mt-3"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        </form>
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
                    setTimeout(function() { alert.remove(); }, 500);
                }, 2000);
            }
        });
    </script>
</body>
</html>

<?php
$questions_stmt->close();
$assigned_stmt->close();
$conn->close();
?>