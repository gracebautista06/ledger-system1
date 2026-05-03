<?php
/* ============================================================
   includes/db.php — Database Connection
   IMPROVEMENTS:
   - Added mysqli_report() to throw exceptions on DB errors
     instead of silent failures
   - Set charset to utf8mb4 to prevent encoding issues
     and certain SQL injection edge cases
   - Credentials note: move to a .env or config file
     outside the web root for production use
   ============================================================ */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = "localhost";
$username   = "root";   // Default for XAMPP — change for production
$password   = "";       // Default for XAMPP — change for production
$dbname     = "ledger_db";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("A database error occurred. Please contact the system administrator.");
}
?>