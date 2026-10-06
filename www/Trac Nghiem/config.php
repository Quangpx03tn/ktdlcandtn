<?php
// Cấu hình hệ thống - Chỉ admin mới được truy cập
// Đổi mật khẩu admin tại đây
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'CongAn@2026');   // <-- Đổi mật khẩu mạnh hơn khi triển khai thật
define('GOOGLE_CLIENT_ID', getenv('TRAC_NGHIEM_GOOGLE_CLIENT_ID') ?: '');

define('RESULTS_FILE', __DIR__ . '/data/results.json');
define('DATA_DIR', __DIR__ . '/data');
define('SETTINGS_FILE', DATA_DIR . '/settings.json');
define('UNIT_TARGETS_FILE', DATA_DIR . '/unit_targets.json');
define('AUDIT_LOG_FILE', DATA_DIR . '/audit_log.json');
define('ROOM_STATUS_FILE', DATA_DIR . '/room_status.json');
define('QUESTION_BANK_FILE', DATA_DIR . '/question_bank.json');
define('PRESENCE_FILE', DATA_DIR . '/presence.json');
define('EXAM_SETS_FILE', DATA_DIR . '/exam_sets.json');
define('EXAM_SETS_LOCK_FILE', DATA_DIR . '/exam_sets_lock.json');
define('EXAM_SETS_FROZEN_FILE', DATA_DIR . '/exam_sets_frozen.json');
define('EXAM_SETS_PUBLISHED_FILE', DATA_DIR . '/exam_sets_published.json');
define('EXAM_TOKEN_SECRET', hash('sha256', ADMIN_PASSWORD . __DIR__ . 'exam-room-token'));

// Tạo thư mục data nếu chưa có
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Khởi tạo file results nếu chưa tồn tại
if (!file_exists(RESULTS_FILE)) {
    file_put_contents(RESULTS_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

if (!file_exists(SETTINGS_FILE)) {
    file_put_contents(SETTINGS_FILE, json_encode([
        'quiz_duration_minutes' => 6,
        'pass_percent' => 50,
        'excellent_percent' => 80,
        'fast_warning_seconds' => 30
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

foreach ([UNIT_TARGETS_FILE, AUDIT_LOG_FILE] as $jsonFile) {
    if (!file_exists($jsonFile)) {
        file_put_contents($jsonFile, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

if (!file_exists(QUESTION_BANK_FILE)) {
    file_put_contents(QUESTION_BANK_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

if (!file_exists(PRESENCE_FILE)) {
    file_put_contents(PRESENCE_FILE, json_encode([
        'total_visits' => 0,
        'visits' => [],
        'clients' => []
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

if (!file_exists(EXAM_SETS_FILE)) {
    file_put_contents(EXAM_SETS_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if (!file_exists(EXAM_SETS_LOCK_FILE)) {
    file_put_contents(EXAM_SETS_LOCK_FILE, json_encode(['locked' => false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if (!file_exists(EXAM_SETS_FROZEN_FILE)) {
    file_put_contents(EXAM_SETS_FROZEN_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if (!file_exists(EXAM_SETS_PUBLISHED_FILE)) {
    file_put_contents(EXAM_SETS_PUBLISHED_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

if (!file_exists(ROOM_STATUS_FILE)) {
    file_put_contents(ROOM_STATUS_FILE, json_encode([
        'default_open' => false,
        'rooms' => [],
        'scheduled_open' => [],
        'scheduled_close' => []
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
?>
