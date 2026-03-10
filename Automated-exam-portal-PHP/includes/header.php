<?php
require_once __DIR__ . '/../config/config.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AEP - Automated Exam Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #6e8efb;
            --secondary: #a777e3;
            --white: #fff;
        }
        .navbar {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            padding: 1rem 0;
        }
        .navbar-brand {
            font-size: 2rem; /* Increased from 1.75rem */
            font-weight: 700;
            color: var(--white);
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
        }
        .navbar-brand:hover {
            color: #f8f9fa;
        }
        .navbar-brand img {
            height: 60px; /* Increased from 40px */
            width: 60px; /* Ensure square for circular shape */
            object-fit: cover; /* Prevent distortion */
            border-radius: 50%; /* Circular shape */
            margin-right: 12px; /* Adjusted spacing */
        }
        .nav-link {
            color: var(--white);
            font-weight: 500;
            padding: 0.5rem 1.25rem;
            transition: background 0.3s ease, color 0.3s ease;
            border-radius: 5px;
        }
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #f8f9fa;
        }
        .nav-link i {
            margin-right: 5px;
        }
        .navbar-toggler {
            border: none;
        }
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.95)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }
        .navbar-collapse {
            justify-content: flex-end;
        }
        @media (max-width: 991px) {
            .navbar-brand {
                font-size: 1.75rem; /* Adjusted for responsiveness */
            }
            .navbar-brand img {
                height: 50px; /* Responsive scaling */
                width: 50px;
            }
            .navbar-collapse {
                background: linear-gradient(90deg, var(--primary), var(--secondary));
                margin-top: 0.5rem;
                padding: 0.5rem;
                border-radius: 5px;
            }
        }
        @media (max-width: 576px) {
            .navbar-brand {
                font-size: 1.5rem; /* Further adjusted */
            }
            .navbar-brand img {
                height: 40px; /* Mobile-friendly size */
                width: 40px;
            }
            .nav-link {
                padding: 0.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>">
                <img src="<?php echo SITE_URL; ?>/assets/img/logo.jpg" alt="Logo">
                Automated Exam Portal
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/auth/logout.php">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</body>
</html>