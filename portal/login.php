<?php
$page_title = 'Login';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (isset($_SESSION['role'])) {
    $redirect = ($_SESSION['role'] === 'Owner') ? '../owner/dashboard.php' : '../staff/dashboard.php';
    header("Location: $redirect");
    exit();
}

$error = "";
$success = "";

if (isset($_GET['message']) && $_GET['message'] === 'logged_out') {
    $success = "You have been logged out successfully.";
}

if (isset($_GET['registered'])) {
    $success = "Account created successfully. You can now log in.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter username and password.";
    } else {

        $stmt = $conn->prepare("SELECT user_id, username, password, role FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];

                $redirect = ($user['role'] === 'Owner') ? '../owner/dashboard.php' : '../staff/dashboard.php';
                header("Location: $redirect");
                exit();

            } else {
                $error = "Invalid credentials.";
                sleep(1);
            }

        } else {
            $error = "Invalid credentials.";
            sleep(1);
        }

        $stmt->close();
    }
}
?>
<div class="login-split">

    <!-- LEFT BRAND PANEL -->
    <div class="login-brand">

        <div class="brand-content">
            <h1>Egg Ledger System</h1>

            <p>
                A centralized farm management system for tracking production,
                monitoring flock health, and improving operational efficiency.
            </p>

            <div class="brand-highlights">
                <div>✔ Real-time production tracking</div>
                <div>✔ Smart analytics dashboard</div>
                <div>✔ Role-based access control</div>
            </div>
        </div>

    </div>

    <!-- RIGHT LOGIN PANEL -->
    <div class="login-panel">

        <div class="login-card card">

            <!-- HEADER -->
            <div class="login-header">
                <h2>Welcome Back</h2>
                <p>Sign in to continue</p>
            </div>

            <!-- ALERTS -->
            <?php if ($success): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- FORM -->
            <form method="POST">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text"
                           name="username"
                           class="form-input"
                           placeholder="Enter username"
                           required
                           autocomplete="username"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Password</label>

                    <div class="password-wrapper">
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-input"
                               placeholder="Enter password"
                               required
                               autocomplete="current-password">

                        <button type="button"
                                class="toggle-password"
                                onclick="togglePassword('password', 'eyeIcon')">
                            <span id="eyeIcon" class="eye-icon active"></span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-farm btn-full login-btn">
                    Sign In
                </button>

            </form>

            <!-- FOOTER -->
            <div class="login-footer">
                <p>
                    Don't have an account?
                    <a href="register.php">Create one</a>
                </p>
            </div>

        </div>

    </div>

</div>
<script>
function togglePassword(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input || !icon) return;

    input.type = input.type === 'password' ? 'text' : 'password';

    // slashed eye when password is hidden, open eye when visible
    icon.classList.toggle('active', input.type === 'password');
}
</script>

<?php include('../includes/footer.php'); ?>