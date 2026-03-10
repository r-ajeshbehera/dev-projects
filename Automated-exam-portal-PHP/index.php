<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP - Automated Exam Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f0f4ff, #e6e9ff);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            position: relative;
        }
        .overlay-logo {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: -2;
            pointer-events: none;
            animation: fadeInLogo 2s ease-in-out;
            background: rgba(110, 142, 251, 0.05); /* Faded blue tint */
        }
        .overlay-logo img {
            max-width: 95%; /* Increased for 14-inch screen coverage */
            max-height: 95%;
            opacity: 0.2; /* Subtle visibility */
            object-fit: contain;
        }
        @keyframes fadeInLogo {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        .hero-section {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 30px;
            padding: 70px 50px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            max-width: 800px;
            width: 90%;
            text-align: center;
            animation: fadeInUp 1s ease-out;
            margin: 80px auto 50px auto;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(110, 142, 251, 0.1) 0%, transparent 70%);
            opacity: 0.5;
            z-index: -1;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(60px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .hero-logo {
            max-width: 150px;
            margin-bottom: 20px;
            display: block;
            margin-left: auto;
            margin-right: auto;
            border-radius: 50%; /* Circular shape */
            object-fit: cover; /* Prevent distortion */
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            line-height: 1.2;
        }
        .hero-subtitle {
            font-size: 1.5rem;
            color: #6b7280;
            margin-bottom: 50px;
            font-weight: 300;
            line-height: 1.6;
        }
        .btn-custom {
            border-radius: 50px;
            padding: 12px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            text-transform: uppercase;
            transition: all 0.4s ease;
            margin: 10px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            display: inline-block;
            text-decoration: none;
            border: none;
        }
        .btn-login {
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
        }
        .btn-login:hover {
            background: linear-gradient(90deg, #5a75e3, #9466d6);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(110, 142, 251, 0.4);
            color: #fff;
        }
        .btn-register {
            background: linear-gradient(90deg, #16a34a, #4ade80);
            color: #fff;
        }
        .btn-register:hover {
            background: linear-gradient(90deg, #15803d, #22c55e);
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(22, 163, 74, 0.4);
            color: #fff;
        }
        .feature-list {
            list-style: none;
            padding: 0;
            margin-top: 40px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .feature-list li {
            font-size: 1.15rem;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 15px;
            transition: transform 0.3s ease;
        }
        .feature-list li:hover {
            transform: scale(1.05);
        }
        .feature-list li i {
            color: #16a34a;
            margin-right: 12px;
            font-size: 1.4rem;
        }
        .wave {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 180px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M0,0V46c150,39,350,58,600,58s450-19,600-58V0Z" fill="rgba(255,255,255,0.85)"/></svg>');
            background-size: cover;
            z-index: -1;
        }
        .alert {
            border-radius: 10px;
            padding: 1rem;
            font-size: 0.9rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: 90%;
            max-width: 500px;
            animation: fadeIn 0.5s ease-in-out;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        /* About Section Styles */
        .about-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: linear-gradient(90deg, #6e8efb, #a777e3);
            color: #fff;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(110, 142, 251, 0.3);
            transition: all 0.3s ease;
            z-index: 2;
        }
        .about-btn:hover {
            background: linear-gradient(90deg, #5a75e3, #9466d6);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(110, 142, 251, 0.5);
            color: #fff;
        }
        .about-content {
            display: none;
            position: fixed;
            top: 70px;
            right: 20px;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 25px;
            width: 350px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            border: 1px solid rgba(110, 142, 251, 0.2);
            text-align: center;
        }
        .about-content.active {
            display: block;
        }
        .about-content h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 15px;
        }
        .about-content p {
            font-size: 1rem;
            color: #374151;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .about-content a {
            color: #6e8efb;
            text-decoration: none;
            font-weight: 600;
        }
        .about-content a:hover {
            color: #5a75e3;
            text-decoration: underline;
        }
        .about-content .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #6b7280;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        .about-content .close-btn:hover {
            color: #1e40af;
        }
        @media (max-width: 768px) {
            .hero-section {
                padding: 40px 20px;
                margin: 60px 10px 50px 10px;
            }
            .hero-logo {
                max-width: 120px;
            }
            .hero-title {
                font-size: 2.5rem;
            }
            .hero-subtitle {
                font-size: 1.2rem;
            }
            .btn-custom {
                width: 100%;
                padding: 12px;
                font-size: 1.1rem;
            }
            .feature-list {
                grid-template-columns: 1fr;
            }
            .overlay-logo img {
                max-width: 98%;
            }
            .about-btn {
                top: 15px;
                right: 15px;
                padding: 8px 15px;
                font-size: 0.9rem;
            }
            .about-content {
                width: 90%;
                max-width: 300px;
                top: 60px;
                right: 15px;
            }
        }
        @media (max-width: 576px) {
            .hero-logo {
                max-width: 100px;
            }
            .hero-title {
                font-size: 2rem;
            }
            .hero-subtitle {
                font-size: 1rem;
            }
            .feature-list li {
                font-size: 1rem;
            }
            .overlay-logo img {
                max-width: 100%;
            }
            .about-content {
                width: 85%;
                top: 50px;
            }
        }
    </style>
</head>
<body>
    <div class="overlay-logo">
        <img src="<?php echo SITE_URL; ?>/assets/img/logo.jpg" alt="AEP Background Logo">
    </div>

    <div class="hero-section">
        <!-- About Button -->
        <a href="#" class="about-btn" id="aboutBtn">About</a>
        <!-- About Content -->
        <div class="about-content" id="aboutContent">
            <button class="close-btn" id="closeAbout">×</button>
            <h3>About AEP</h3>
            <p>Hi, I’m <strong>Rajesh Behera</strong>, the designer and developer of the Automated Exam Portal (AEP). I’m passionate about creating tools that enhance education through technology.</p>
            <p>AEP is a smart exam management system designed to simplify question creation, exam scheduling, and result tracking for teachers and students alike. It features automated question generation, timed exams, and secure user dashboards.</p>
            <p><strong>Technologies Used:</strong> PHP, MySQL, JavaScript, HTML5, CSS3 (Bootstrap 5), and a touch of creativity!</p>
            <p>Check out my work on <a href="https://github.com/r-ajeshbehera" target="_blank">GitHub</a>.</p>
        </div>

        <img src="<?php echo SITE_URL; ?>/assets/img/logo.jpg" alt="AEP Hero Logo" class="hero-logo">
        <h1 class="hero-title">Automated Exam Portal</h1>
        <p class="hero-subtitle">Elevate Your Exam Experience with Smart Technology</p>
        <div>
            <a href="auth/login.php" class="btn btn-login btn-custom">Login <br>(Registered user)</a>
            <a href="auth/register.php" class="btn btn-register btn-custom">Register <br>(New user)</a>
        </div>
        <ul class="feature-list">
            <li><i class="fas fa-check-circle"></i> Seamless Exam Creation & Management</li>
            <li><i class="fas fa-check-circle"></i> Timed Exams with Instant Results</li>
            <li><i class="fas fa-check-circle"></i> Intuitive Dashboards for All Users</li>
            <li><i class="fas fa-check-circle"></i> Secure & Modern Interface</li>
        </ul>
    </div>
    <div class="wave"></div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" id="alertMessage"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger" id="alertMessage"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const aboutBtn = document.getElementById('aboutBtn');
            const aboutContent = document.getElementById('aboutContent');
            const closeAbout = document.getElementById('closeAbout');

            // Toggle About section
            aboutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                aboutContent.classList.toggle('active');
            });

            // Close About section
            closeAbout.addEventListener('click', function() {
                aboutContent.classList.remove('active');
            });

            // Close About section when clicking outside
            document.addEventListener('click', function(e) {
                if (!aboutContent.contains(e.target) && !aboutBtn.contains(e.target)) {
                    aboutContent.classList.remove('active');
                }
            });

            // Alert fade-out
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