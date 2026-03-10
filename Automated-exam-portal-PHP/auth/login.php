<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering to prevent headers already sent errors
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth.php';

// Handle redirects before any output
if (is_logged_in()) {
    header('Location: ' . SITE_URL . (is_teacher() ? '/teacher/dashboard.php' : '/student/dashboard.php'));
    ob_end_flush(); // Send buffered output and stop buffering
    exit;
}

$conn = db_connect();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        header('Location: ' . SITE_URL . ($user['role'] === 'teacher' ? '/teacher/dashboard.php' : '/student/dashboard.php') . '?success=' . urlencode('Logged in successfully'));
        ob_end_flush(); // Send buffered output and stop buffering
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}

// Output the HTML only after PHP logic is complete
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AEP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef2ff, #d9e2ff);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        .login-container {
            min-height: calc(100vh - 200px); /* Adjusted for header/footer */
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 20px;
        }
        .login-card {
            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            background: #fff;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            transition: transform 0.3s ease;
        }
        .login-card:hover {
            transform: translateY(-5px);
        }
        .login-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }
        .login-indicator {
            font-size: 1rem;
            font-weight: 500;
            color: #6e8efb;
            background: rgba(110, 142, 251, 0.1);
            border: 1px solid #6e8efb;
            border-radius: 20px;
            padding: 0.25rem 1rem;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        .form-control {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            transition: border-color 0.3s ease;
        }
        .form-control:focus {
            border-color: #6e8efb;
            box-shadow: 0 0 5px rgba(110, 142, 251, 0.5);
        }
        .input-group-text {
            background: #f8f9fa;
            border-radius: 8px 0 0 8px;
            border: 1px solid #ddd;
            border-right: none;
        }
        .btn-login {
            background: #6e8efb;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            font-weight: 600;
            transition: background 0.3s ease;
        }
        .btn-login:hover {
            background: #5a75e3;
        }
        .alert {
            border-radius: 8px;
            font-size: 0.9rem;
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: 90%;
            max-width: 400px;
        }
        .register-link {
            color: #6e8efb;
            text-decoration: none;
            font-weight: 500;
        }
        .register-link:hover {
            text-decoration: underline;
        }
        .back-btn {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, background 0.3s ease;
            position: absolute;
            top: 20px;
            left: 20px;
        }
        .back-btn:hover {
            transform: scale(1.1);
            background: linear-gradient(90deg, #5a75e3, #9466d6);
        }
        .back-btn i {
            font-size: 1.5rem;
        }
        footer {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            text-align: center;
            padding: 20px;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
            border-radius: 15px 15px 0 0;
            margin-top: auto;
            width: 90%;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Back Button -->
        <button class="back-btn" onclick="goBack()">
            <i class="bi bi-arrow-left"></i>
        </button>

        <div class="login-card">
            <h2 class="login-title text-center">Welcome Back</h2>
            <div class="login-indicator text-center">Login Here</div>
            <?php if ($error): ?>
                <div class="alert alert-danger text-center" id="alertMessage"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success text-center" id="alertMessage"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" id="username" class="form-control" placeholder="Enter your username" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-login w-100">Sign In</button>
            </form>
            <p class="text-center mt-3">New here? <a href="<?php echo SITE_URL; ?>/auth/register.php" class="register-link">Create an Account</a></p>
        </div>
    </div>

    <footer class="footer">
    <div class="container text-center">
        <p class="footer-text mb-0">© <?php echo date('Y'); ?> AEP | All Rights Reserved | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank"><u>Rajesh Behera.</u></a></p>
    </div>
</footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fade out alerts after 2 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('alertMessage');
            if (alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 2000); // Fade out after 2 seconds
            }
        });

        // Back button functionality
        function goBack() {
            window.location.href = '<?php echo SITE_URL; ?>/index.php';
        }
    </script>
</body>
</html>

<?php 
ob_end_flush(); // Ensure any remaining buffer is sent
?>