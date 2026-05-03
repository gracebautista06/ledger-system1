<?php
/* ============================================================
   portal/logout.php — Secure Session Logout

   IMPROVEMENT NOTES:
   - Added session_start() before trying to destroy session
     (original had it, but good to document why it's needed)
   - Added cookie deletion for extra session cleanup
   - Added CSRF-safe: GET-based logout is acceptable for simple
     systems; for high-security apps, use POST + CSRF token
   ============================================================ */

// Must start session before we can clear it
session_start();
// Log the logout BEFORE destroying the session (we need user_id and role)
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    // Minimal db connection just for logging
    include('../includes/db.php');
    include('../includes/log_activity.php');
    log_activity($conn, $_SESSION['user_id'], $_SESSION['role'], 'Logout',
        htmlspecialchars($_SESSION['username']) . ' logged out');
}

// 1. Clear all session variables from memory
$_SESSION = [];

// 2. IMPROVEMENT: Delete the session cookie from the browser too.
//    session_destroy() removes server-side data, but without this
//    the browser still holds the old (now invalid) session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destroy the server-side session data
session_destroy();

// 4. Redirect to login with a "logged out" success message
header("Location: login.php?message=logged_out");
exit();
?>