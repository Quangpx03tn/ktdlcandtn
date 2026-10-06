<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GOOGLE_CLIENT_ID === '') {
    http_response_code(GOOGLE_CLIENT_ID === '' ? 503 : 405);
    echo json_encode(['success' => false, 'message' => GOOGLE_CLIENT_ID === '' ? 'Google OAuth chưa được cấu hình.' : 'Chỉ chấp nhận POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$credential = trim((string)($input['credential'] ?? ''));
if ($credential === '' || strlen($credential) > 10000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Google ID token không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$curl = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential));
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$profile = json_decode($response ?: '', true);
$validIssuer = in_array($profile['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
$valid = $status === 200 && is_array($profile)
    && hash_equals(GOOGLE_CLIENT_ID, (string)($profile['aud'] ?? ''))
    && $validIssuer && (int)($profile['exp'] ?? 0) >= time()
    && filter_var($profile['email'] ?? '', FILTER_VALIDATE_EMAIL)
    && ($profile['email_verified'] ?? '') === 'true'
    && str_ends_with(strtolower((string)$profile['email']), '@gmail.com');
if (!$valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Không xác minh được tài khoản Gmail với Google.'], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['success' => true, 'profile' => [
    'google_id' => $profile['sub'] ?? '', 'email' => $profile['email'],
    'name' => $profile['name'] ?? '', 'picture' => $profile['picture'] ?? ''
]], JSON_UNESCAPED_UNICODE);
?>
