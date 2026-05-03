<?php
$page_title = 'Home';
include('includes/header.php');

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Owner') {
        header("Location: owner/dashboard.php");
    } else {
        header("Location: staff/dashboard.php");
    }
    exit();
}
?>

<div class="landing-wrapper">

    <!-- HERO SECTION -->
    <div class="hero-section">

        <div class="hero-left">
            <h1 class="hero-title">
                Egg Ledger System
            </h1>

            <p class="hero-subtitle">
                A centralized platform for managing egg production, monitoring flock health, and tracking farm performance in real time.
            </p>

            <div class="hero-actions">
                <a href="portal/login.php" class="btn-farm">
                    Login
                </a>
                <a href="portal/register.php" class="btn-farm btn-outline">
                    Create Account
                </a>
            </div>
        </div>

        <div class="hero-right">

            <div class="preview-card highlight">
                <h3>Live Overview</h3>
                <p>Track daily production and farm status at a glance.</p>
            </div>

            <div class="preview-card highlight">
                <h3>Smart Insights</h3>
                <p>View trends in egg output and flock performance.</p>
            </div>

        </div>

    </div>

    <!-- FEATURE STRIP -->
    <div class="feature-strip">

        <div class="preview-card highlight">
            <h3>Production Tracking</h3>
            <p>Accurate daily egg logging system.</p>
        </div>

        <div class="preview-card highlight">
            <h3>Analytics</h3>
            <p>Understand farm performance trends.</p>
        </div>

        <div class="preview-card highlight">
            <h3>Role Access</h3>
            <p>Separate dashboards for staff and owners.</p>
        </div>

    </div>

</div>

<?php include('includes/footer.php'); ?>