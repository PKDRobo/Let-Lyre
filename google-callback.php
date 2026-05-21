<?php
// ============================================================
// Let Lyre - Google OAuth Callback
// ============================================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Invalid request method.'], 405);
    }

    $credential = $_POST['credential'] ?? '';
    if (!$credential) {
        jsonResponse(['error' => 'No credential provided.'], 400);
    }

    // Verify Google JWT token using Google's token info endpoint
    $tokenInfoUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenInfoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Fallback using file_get_contents if cURL is not available
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $response = @file_get_contents($tokenInfoUrl, false, $ctx);
        $httpCode = $response !== false ? 200 : 0;
    }

    if ($httpCode !== 200 || !$response) {
        jsonResponse(['error' => 'Token verification failed.'], 401);
    }

    $payload = json_decode($response, true);
    if (!$payload || !isset($payload['sub'], $payload['email'])) {
        jsonResponse(['error' => 'Invalid token payload.'], 401);
    }

    // Verify the token is intended for our app
    if ($payload['aud'] !== GOOGLE_CLIENT_ID) {
        jsonResponse(['error' => 'Token audience mismatch.'], 401);
    }

    $googleId = $payload['sub'];
    $name = $payload['name'] ?? $payload['email'];
    $email = $payload['email'];
    $avatar = $payload['picture'] ?? '';

    // Save/update user in MySQL
    $db = getDB();
    if (!$db) {
        jsonResponse(['error' => 'Database connection failed.'], 500);
    }

    // Check if user exists by google_id
    $stmt = $db->prepare("SELECT id FROM users WHERE google_id = ?");
    $stmt->bind_param("s", $googleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingUser = $result->fetch_assoc();
    $stmt->close();

    if ($existingUser) {
        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, avatar = ?, updated_at = NOW() WHERE google_id = ?");
        $stmt->bind_param("ssss", $name, $email, $avatar, $googleId);
        $stmt->execute();
        $stmt->close();
        $userId = $existingUser['id'];
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $emailUser = $result->fetch_assoc();
        $stmt->close();

        if ($emailUser) {
            $stmt = $db->prepare("UPDATE users SET google_id = ?, name = ?, avatar = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $googleId, $name, $avatar, $emailUser['id']);
            $stmt->execute();
            $stmt->close();
            $userId = $emailUser['id'];
        } else {
            $stmt = $db->prepare("INSERT INTO users (google_id, name, email, avatar) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $googleId, $name, $email, $avatar);
            $stmt->execute();
            $stmt->close();
            $userId = $db->insert_id;
        }
    }

    $isAdmin = ($email === 'beaigenius@gmail.com') ? 1 : 0;

    // Update is_admin in DB for the admin email
    $stmt = $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
    $stmt->bind_param("ii", $isAdmin, $userId);
    $stmt->execute();
    $stmt->close();

    $db->close();

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_avatar'] = $avatar;
    $_SESSION['is_admin'] = (bool)$isAdmin;
    $_SESSION['logged_in'] = true;

    jsonResponse([
        'success' => true,
        'user' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'avatar' => $avatar,
        ]
    ]);

} catch (Throwable $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
