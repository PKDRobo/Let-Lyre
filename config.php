<?php
// ============================================================
// Let Lyre - Configuration File
// ============================================================

session_start();

define('DB_HOST', 'sql101.infinityfree.com');
define('DB_NAME', 'if0_41981954_LetLyre');
define('DB_USER', 'if0_41981954');
define('DB_PASS', '0dytGZFd2MSy');

define('GOOGLE_CLIENT_ID', '756188842044-04nggq5go2tf2roqmgk2qt0cg4p8fat1.apps.googleusercontent.com');

// Site URL (auto-detect)
define('SITE_URL', (
    ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
    ? 'https://' : 'http://'
) . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/');

$mysqli = null;

function getDB() {
    global $mysqli;
    if ($mysqli !== null) return $mysqli;
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_error) {
        error_log("DB Connection failed: " . $mysqli->connect_error);
        return null;
    }
    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'avatar' => $_SESSION['user_avatar'],
        'is_admin' => $_SESSION['is_admin'] ?? false,
    ];
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
