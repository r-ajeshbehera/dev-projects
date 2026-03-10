<?php
// includes/footer.php
?>

<footer class="footer">
    <div class="container text-center">
        <p class="footer-text mb-0">© <?php echo date('Y'); ?> AEP | All Rights Reserved | Designed by <a href="https://github.com/r-ajeshbehera" target="_blank"><u>Rajesh Behera.</u></a></p>
    </div>
</footer>

<style>
    :root {
        --primary: #6e8efb;
        --secondary: #a777e3;
        --white: #fff;
    }
    .footer {
        background: linear-gradient(90deg, var(--primary), var(--secondary));
        color: var(--white);
        padding: 1.5rem 0;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    }
    .footer-text {
        font-size: 0.85rem;
        font-weight: 500;
        letter-spacing: 0.5px;
    }
    .footer a {
        color: var(--white);
        text-decoration: none;
        transition: color 0.3s ease;
    }
    .footer a:hover {
        color: #e0e0e0;
        text-decoration: underline;
    }
</style>