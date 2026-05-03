<?php
/* ============================================================
   portal/register.php — New User Registration
   IMPROVEMENTS:
   - Uses prepared statements (fixes SQL injection risk)
   - Server-side role validation (whitelist)
   - Username + password strength validation
   - Password confirmation field added
   - Password strength meter (JS)
   - Secret keys externalized to a constant block with note
     to move to .env in production
   - Redirect to login on success
   ============================================================ */

$page_title = 'Register';

include('../includes/db.php');
include('../includes/header.php');

// Redirect if already logged in
if (isset($_SESSION['role'])) {
    header("Location: ../index.php");
    exit();
}

// IMPROVEMENT: Define secret keys in one place.
// In production, move these to a config.php outside the web root.
define('KEY_STAFF', 'EGG_STAFF_2026');
define('KEY_OWNER', 'FARM_BOSS_99');

$errors  = [];
$success = "";
$post    = []; // Holds safe repopulation values

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']       ?? '';
    $password2 = $_POST['password2']      ?? '';
    $role      = $_POST['role']           ?? '';
    $input_key = $_POST['secret_key']     ?? '';

    // --- VALIDATION ---

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors[] = "Username must be 3–20 characters: letters, numbers, or underscores only.";
    }

    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }

    // IMPROVEMENT: Confirm password match
    if ($password !== $password2) {
        $errors[] = "Passwords do not match. Please try again.";
    }

    // Whitelist roles server-side
    $allowed_roles = ['Staff', 'Owner'];
    if (!in_array($role, $allowed_roles)) {
        $errors[] = "Invalid role selected.";
    }

    // Validate secret key against role
    if (empty($errors)) {
        $authorized = ($role === 'Staff' && $input_key === KEY_STAFF)
                   || ($role === 'Owner' && $input_key === KEY_OWNER);

        if (!$authorized) {
            $errors[] = "🔒 Invalid secret key for the selected role. Access denied.";
        }
    }

    if (empty($errors)) {
        // IMPROVEMENT: Prepared statement — no manual escaping needed
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $errors[] = "That username is already taken. Please choose another.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $hashed, $role);

        if ($stmt->execute()) {
            header("Location: login.php?registered=1");
            exit();
        } else {
            $errors[] = "A database error occurred. Please try again.";
        }
        $stmt->close();
    }

    // Repopulate form (never repopulate passwords)
    $post = [
        'username' => htmlspecialchars($username),
        'role'     => $role,
    ];
}
?>
<div class="login-split">

    <!-- LEFT BRAND PANEL -->
    <div class="login-brand">

        <div class="brand-content">
            <h1>Create Account</h1>

            <p>
                Join the Egg Ledger System and start managing farm operations,
                tracking production, and monitoring performance in one place.
            </p>

            <div class="brand-highlights">
                <div>✔ Secure role-based system</div>
                <div>✔ Real-time farm tracking</div>
                <div>✔ Admin-controlled access</div>
            </div>
        </div>

    </div>

    <!-- RIGHT PANEL -->
    <div class="login-panel">

        <div class="login-card card">

            <!-- HEADER -->
            <div class="login-header">
                <h2>Register</h2>
                <p>Fill in your details to create an account</p>
            </div>

            <!-- ERRORS -->
            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <?php echo implode("<br>", array_map('htmlspecialchars', $errors)); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="register-form" novalidate>

                <!-- USERNAME -->
                <div class="form-group">
                    <label>Username</label>
                    <input type="text"
                           name="username"
                           class="form-input"
                           placeholder="3–20 characters"
                           required
                           value="<?php echo $post['username'] ?? ''; ?>">
                </div>

                <!-- PASSWORD -->
                <div class="form-group">
                    <label>Password</label>

                    <div class="password-wrapper">
                        <input type="password"
                               id="reg_password"
                               name="password"
                               class="form-input"
                               placeholder="Minimum 8 characters"
                               required
                               oninput="updateStrength(this.value)">

                        <button type="button"
                                class="toggle-password"
                                onclick="togglePassword('reg_password','eye1')">
                            <span id="eye1" class="eye-icon active"></span>
                        </button>
                    </div>

                    <div id="strength-bar-wrap" class="strength-wrap">
                        <div class="strength-bar-bg">
                            <div id="strength-bar"></div>
                        </div>
                        <small id="strength-label"></small>
                    </div>
                </div>

                <!-- CONFIRM PASSWORD -->
                <div class="form-group">
                    <label>Confirm Password</label>

                    <div class="password-wrapper">
                        <input type="password"
                               id="reg_password2"
                               name="password2"
                               class="form-input"
                               placeholder="Re-enter password"
                               required>

                        <button type="button"
                                class="toggle-password"
                                onclick="togglePassword('reg_password2','eye2')">
                            <span id="eye2" class="eye-icon active"></span>
                        </button>
                    </div>

                    <small id="pw-match-msg"></small>
                </div>

                <!-- ROLE -->
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-input" required>
                        <option value="">Select role</option>
                        <option value="Staff" <?php echo (($post['role'] ?? '') === 'Staff') ? 'selected' : ''; ?>>
                            Staff
                        </option>
                        <option value="Owner" <?php echo (($post['role'] ?? '') === 'Owner') ? 'selected' : ''; ?>>
                            Owner
                        </option>
                    </select>
                </div>

                <!-- SECRET KEY -->
                <div class="form-group">
                    <label>Secret Key</label>

                    <div class="password-wrapper">
                        <input type="password"
                                id="reg_key"
                               name="secret_key"
                               class="form-input"
                               placeholder="Provided by admin"
                               required>

                        <button type="button"
                                class="toggle-password"
                                onclick="togglePassword('reg_key','eye3')">
                            <span id="eye3" class="eye-icon active"></span>
                        </button>
                    </div>

                    <small class="hint-text">
                        Contact your administrator for access key
                    </small>
                </div>

                <!-- SUBMIT -->
                <button type="submit" class="btn-farm btn-full login-btn">
                    Create Account
                </button>

            </form>

            <!-- FOOTER -->
            <div class="login-footer">
                <p>
                    Already have an account?
                    <a href="login.php">Sign in</a>
                </p>
            </div>

        </div>

    </div>

</div>

<script>
function togglePassword() {
    const input = document.getElementById("inputId");
    const icon = document.getElementById("iconId");

    if (!input || !icon) return;

    const isHidden = input.type === "password";
    input.type = isHidden ? "text" : "password";

    icon.classList.toggle("active", isHidden);
}

function updateStrength(val) {
    const wrap  = document.getElementById('strength-bar-wrap');
    const bar   = document.getElementById('strength-bar');
    const label = document.getElementById('strength-label');
    wrap.style.display = val.length ? 'block' : 'none';

    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '20%',  color: '#C23A3A', text: 'Very weak' },
        { pct: '40%',  color: '#C24B2A', text: 'Weak' },
        { pct: '60%',  color: '#D4900A', text: 'Fair' },
        { pct: '80%',  color: '#4E9B5B', text: 'Strong' },
        { pct: '100%', color: '#2A7A40', text: 'Very strong' },
    ];
    const l = levels[Math.min(score, 4)];
    bar.style.width      = l.pct;
    bar.style.background = l.color;
    label.textContent    = l.text;
    label.style.color    = l.color;
}

// Live password match check
document.getElementById('reg_password2').addEventListener('input', function () {
    const pw1 = document.getElementById('reg_password').value;
    const msg = document.getElementById('pw-match-msg');
    if (this.value.length === 0) { msg.textContent = ''; return; }
    if (this.value === pw1) {
        msg.textContent = '✔ Passwords match';
        msg.style.color = '#4E9B5B';
    } else {
        msg.textContent = '✖ Passwords do not match';
        msg.style.color = '#C23A3A';
    }
});
</script>

<?php include('../includes/footer.php'); ?>