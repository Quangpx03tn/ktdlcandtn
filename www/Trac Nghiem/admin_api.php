<?php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function read_json_file($path, $default = []) {
    if (!file_exists($path)) return $default;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function write_json_file($path, $data) {
    return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function audit_log($actionName, $detail = '') {
    $logs = read_json_file(AUDIT_LOG_FILE);
    $logs[] = [
        'id' => uniqid('log_', true),
        'time' => date('Y-m-d H:i:s'),
        'user' => $_SESSION['admin_user'] ?? 'system',
        'action' => $actionName,
        'detail' => $detail,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    write_json_file(AUDIT_LOG_FILE, array_slice($logs, -1000));
}

if ($action === 'public_settings') {
    echo json_encode(['success' => true, 'data' => read_json_file(SETTINGS_FILE)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== Đăng nhập Admin =====
if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $user = $input['username'] ?? '';
    $pass = $input['password'] ?? '';

    if ($user === ADMIN_USERNAME && $pass === ADMIN_PASSWORD) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $user;
        audit_log('Đăng nhập', 'Đăng nhập Admin thành công');
        echo json_encode(['success' => true, 'message' => 'Đăng nhập thành công']);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sai tên đăng nhập hoặc mật khẩu']);
    }
    exit;
}

// ===== Kiểm tra đăng nhập =====
if ($action === 'check') {
    echo json_encode([
        'logged_in' => !empty($_SESSION['admin_logged_in']),
        'user' => $_SESSION['admin_user'] ?? null
    ]);
    exit;
}

// ===== Đăng xuất =====
if ($action === 'logout') {
    audit_log('Đăng xuất', 'Đăng xuất Admin');
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// Từ đây trở đi bắt buộc đăng nhập admin
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập admin']);
    exit;
}

// ===== Lấy danh sách kết quả =====
if ($action === 'list') {
    $results = json_decode(file_get_contents(RESULTS_FILE), true) ?: [];
    
    // Lọc theo đơn vị nếu có
    $unit = $_GET['unit'] ?? '';
    if ($unit) {
        $results = array_filter($results, fn($r) => $r['unit'] === $unit);
        $results = array_values($results);
    }

    // Sắp xếp mới nhất trước
    usort($results, fn($a, $b) => strcmp($b['time'], $a['time']));

    echo json_encode([
        'success' => true,
        'total' => count($results),
        'data' => $results
    ]);
    exit;
}

if ($action === 'get_circular_stats') {
    $results = read_json_file(RESULTS_FILE);
    $stats = [];
    $coveredSubmissions = 0;
    foreach ($results as $result) {
        $items = $result['circular_breakdown'] ?? [];
        if (!is_array($items) || count($items) === 0) continue;
        $coveredSubmissions++;
        $unit = html_entity_decode((string)($result['unit'] ?? 'Không xác định'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ($items as $item) {
            $name = trim((string)($item['circular'] ?? ''));
            if ($name === '') continue;
            if (!isset($stats[$name])) {
                $stats[$name] = [
                    'circular' => $name, 'attempts' => 0, 'correct' => 0,
                    'wrong' => 0, 'unanswered' => 0, 'questions' => [], 'units' => []
                ];
            }
            $answered = !empty($item['answered']);
            $correct = !empty($item['correct']);
            $stats[$name]['attempts']++;
            if ($correct) $stats[$name]['correct']++;
            else $stats[$name]['wrong']++;
            if (!$answered) $stats[$name]['unanswered']++;
            $questionKey = (string)($item['question_key'] ?? '');
            if ($questionKey !== '') $stats[$name]['questions'][$questionKey] = true;
            if (!isset($stats[$name]['units'][$unit])) {
                $stats[$name]['units'][$unit] = ['attempts' => 0, 'correct' => 0, 'wrong' => 0, 'unanswered' => 0];
            }
            $stats[$name]['units'][$unit]['attempts']++;
            if ($correct) $stats[$name]['units'][$unit]['correct']++;
            else $stats[$name]['units'][$unit]['wrong']++;
            if (!$answered) $stats[$name]['units'][$unit]['unanswered']++;
        }
    }
    $rows = array_values(array_map(function ($row) {
        $row['question_count'] = count($row['questions']);
        unset($row['questions']);
        $row['correct_percent'] = $row['attempts'] > 0 ? round($row['correct'] * 100 / $row['attempts'], 1) : 0;
        return $row;
    }, $stats));
    usort($rows, fn($a, $b) => ($a['correct_percent'] <=> $b['correct_percent']) ?: strcmp($a['circular'], $b['circular']));
    echo json_encode([
        'success' => true,
        'total_results' => count($results),
        'covered_submissions' => $coveredSubmissions,
        'data' => $rows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_settings') {
    echo json_encode(['success' => true, 'data' => read_json_file(SETTINGS_FILE)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_questions') {
    $questions = read_json_file(QUESTION_BANK_FILE);
    echo json_encode(['success' => true, 'total' => count($questions), 'data' => $questions], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_exam_sets') {
    $sets = read_json_file(EXAM_SETS_FILE);
    $lock = read_json_file(EXAM_SETS_LOCK_FILE, ['locked' => false]);
    echo json_encode(['success' => true, 'total' => count($sets), 'data' => $sets, 'lock' => $lock], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_exam_sets') {
    $lock = read_json_file(EXAM_SETS_LOCK_FILE, ['locked' => false]);
    if (!empty($lock['locked'])) {
        http_response_code(423);
        echo json_encode(['success' => false, 'message' => 'Các bộ đề đã được đóng băng. Hãy upload ngân hàng câu hỏi mới để mở một kỳ tạo đề mới.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $incoming = $input['sets'] ?? [];
    $validSets = [];
    foreach ($incoming as $set) {
        $code = trim((string)($set['code'] ?? ''));
        $questions = [];
        foreach (($set['questions'] ?? []) as $question) {
            $text = trim((string)($question['q'] ?? ''));
            $options = array_values(array_map('strval', $question['options'] ?? []));
            $answer = (int)($question['answer'] ?? -1);
            if ($text !== '' && count($options) >= 2 && $answer >= 0 && $answer < count($options)) {
                $questions[] = [
                    'q' => $text,
                    'options' => $options,
                    'answer' => $answer,
                    'source_id' => (int)($question['source_id'] ?? 0)
                ];
            }
        }
        if ($code !== '' && count($questions) > 0) {
            $validSets[] = ['code' => $code, 'question_count' => count($questions), 'questions' => $questions];
        }
    }
    if (!write_json_file(EXAM_SETS_FILE, $validSets)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không lưu được các bộ đề.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    audit_log('Tạo bộ đề', count($validSets) . ' bộ đề đã được lưu');
    echo json_encode(['success' => true, 'total' => count($validSets)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'freeze_exam_sets') {
    $sets = read_json_file(EXAM_SETS_FILE);
    if (count($sets) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Chưa có bộ đề nào để đóng băng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $lock = read_json_file(EXAM_SETS_LOCK_FILE, ['locked' => false]);
    if (!empty($lock['locked'])) {
        echo json_encode(['success' => true, 'lock' => $lock], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $snapshotHash = hash('sha256', json_encode($sets, JSON_UNESCAPED_UNICODE));
    $lock = [
        'locked' => true,
        'locked_at' => date('Y-m-d H:i:s'),
        'locked_by' => $_SESSION['admin_user'] ?? 'admin',
        'set_count' => count($sets),
        'sha256' => $snapshotHash,
        'published' => false
    ];
    if (!write_json_file(EXAM_SETS_FROZEN_FILE, $sets) || !write_json_file(EXAM_SETS_LOCK_FILE, $lock)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không tạo được bản lưu đóng băng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    audit_log('Đóng băng bộ đề', count($sets) . ' bộ đề; mã kiểm tra SHA-256: ' . $snapshotHash);
    echo json_encode(['success' => true, 'lock' => $lock], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'publish_exam_sets') {
    $lock = read_json_file(EXAM_SETS_LOCK_FILE, ['locked' => false]);
    if (empty($lock['locked'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Phải lưu cứng và đóng băng bộ đề trước khi upload lên server.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $frozenSets = read_json_file(EXAM_SETS_FROZEN_FILE);
    if (count($frozenSets) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy bản bộ đề đã đóng băng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $snapshotHash = hash('sha256', json_encode($frozenSets, JSON_UNESCAPED_UNICODE));
    if (!empty($lock['sha256']) && !hash_equals((string)$lock['sha256'], $snapshotHash)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Bản đóng băng không còn toàn vẹn nên không thể upload.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!write_json_file(EXAM_SETS_PUBLISHED_FILE, $frozenSets)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không upload được ngân hàng đề lên server.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $lock['published'] = true;
    $lock['published_at'] = date('Y-m-d H:i:s');
    $lock['published_by'] = $_SESSION['admin_user'] ?? 'admin';
    write_json_file(EXAM_SETS_LOCK_FILE, $lock);
    audit_log('Upload ngân hàng đề lên server', count($frozenSets) . ' bộ đề đã sẵn sàng gán ngẫu nhiên cho thí sinh; SHA-256: ' . $snapshotHash);
    echo json_encode(['success' => true, 'total' => count($frozenSets), 'lock' => $lock], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_questions') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = ($input['mode'] ?? 'replace') === 'append' ? 'append' : 'replace';
    $incoming = $input['questions'] ?? [];
    $valid = [];
    foreach ($incoming as $question) {
        $text = trim($question['q'] ?? '');
        $options = array_values(array_filter(array_map('trim', $question['options'] ?? []), fn($item) => $item !== ''));
        $answer = (int)($question['answer'] ?? -1);
        if ($text !== '' && count($options) >= 2 && $answer >= 0 && $answer < count($options)) {
            $valid[] = ['q' => $text, 'options' => $options, 'answer' => $answer];
        }
    }
    $questions = $mode === 'append' ? read_json_file(QUESTION_BANK_FILE) : [];
    $seen = [];
    $merged = [];
    foreach (array_merge($questions, $valid) as $question) {
        $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($question['q'])), 'UTF-8');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $merged[] = $question;
    }
    if (!write_json_file(QUESTION_BANK_FILE, $merged)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Không lưu được ngân hàng câu hỏi.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Một file câu hỏi mới bắt đầu chu kỳ tạo đề mới và là cách duy nhất gỡ trạng thái đóng băng.
    write_json_file(EXAM_SETS_FILE, []);
    write_json_file(EXAM_SETS_FROZEN_FILE, []);
    write_json_file(EXAM_SETS_PUBLISHED_FILE, []);
    write_json_file(EXAM_SETS_LOCK_FILE, ['locked' => false, 'unlocked_at' => date('Y-m-d H:i:s'), 'reason' => 'new_question_upload']);
    audit_log('Cập nhật ngân hàng câu hỏi', ($mode === 'append' ? 'Gộp thêm' : 'Thay thế') . ': ' . count($valid) . ' câu; tổng ' . count($merged));
    echo json_encode(['success' => true, 'imported' => count($valid), 'total' => count($merged)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_settings') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $settings = [
        'quiz_duration_minutes' => max(1, min(120, (int)($input['quiz_duration_minutes'] ?? 6))),
        'pass_percent' => max(1, min(100, (int)($input['pass_percent'] ?? 50))),
        'excellent_percent' => max(1, min(100, (int)($input['excellent_percent'] ?? 80))),
        'fast_warning_seconds' => max(1, min(3600, (int)($input['fast_warning_seconds'] ?? 30)))
    ];
    if ($settings['excellent_percent'] < $settings['pass_percent']) {
        $settings['excellent_percent'] = $settings['pass_percent'];
    }
    write_json_file(SETTINGS_FILE, $settings);
    audit_log('Cập nhật cài đặt', json_encode($settings, JSON_UNESCAPED_UNICODE));
    echo json_encode(['success' => true, 'data' => $settings], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_targets') {
    echo json_encode(['success' => true, 'data' => read_json_file(UNIT_TARGETS_FILE)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_rooms') {
    $rooms = read_json_file(ROOM_STATUS_FILE, ['default_open' => false, 'rooms' => [], 'scheduled_open' => [], 'scheduled_close' => []]);
    $rooms['scheduled_open'] = is_array($rooms['scheduled_open'] ?? null) ? $rooms['scheduled_open'] : [];
    $rooms['scheduled_close'] = is_array($rooms['scheduled_close'] ?? null) ? $rooms['scheduled_close'] : [];
    $changed = false;
    foreach ($rooms['scheduled_open'] as $unit => $timestamp) {
        if ((int)$timestamp <= time()) {
            $rooms['rooms'][$unit] = true;
            unset($rooms['scheduled_open'][$unit]);
            $changed = true;
        }
    }
    foreach ($rooms['scheduled_close'] as $unit => $timestamp) {
        if ((int)$timestamp <= time()) {
            $rooms['rooms'][$unit] = false;
            unset($rooms['scheduled_close'][$unit], $rooms['scheduled_open'][$unit]);
            $changed = true;
        }
    }
    if ($changed) write_json_file(ROOM_STATUS_FILE, $rooms);
    echo json_encode(['success' => true, 'data' => $rooms], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set_room') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $unit = trim($input['unit'] ?? '');
    $open = !empty($input['open']);
    if ($unit === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu đơn vị']);
        exit;
    }
    $data = read_json_file(ROOM_STATUS_FILE, ['default_open' => false, 'rooms' => [], 'scheduled_open' => [], 'scheduled_close' => []]);
    $data['rooms'][$unit] = $open;
    unset($data['scheduled_open'][$unit], $data['scheduled_close'][$unit]);
    write_json_file(ROOM_STATUS_FILE, $data);
    audit_log($open ? 'Mở phòng thi' : 'Khóa phòng thi', $unit);
    echo json_encode(['success' => true, 'unit' => $unit, 'open' => $open], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'schedule_room') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $unit = trim((string)($input['unit'] ?? ''));
    $dateTime = trim((string)($input['datetime'] ?? ''));
    if ($unit === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu đơn vị'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = read_json_file(ROOM_STATUS_FILE, ['default_open' => false, 'rooms' => [], 'scheduled_open' => [], 'scheduled_close' => []]);
    $data['scheduled_open'] = is_array($data['scheduled_open'] ?? null) ? $data['scheduled_open'] : [];
    $data['scheduled_close'] = is_array($data['scheduled_close'] ?? null) ? $data['scheduled_close'] : [];
    if ($dateTime === '') {
        unset($data['scheduled_open'][$unit]);
        if (empty($data['rooms'][$unit])) unset($data['scheduled_close'][$unit]);
        write_json_file(ROOM_STATUS_FILE, $data);
        audit_log('Hủy hẹn mở phòng', $unit);
        echo json_encode(['success' => true, 'cancelled' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $dateTime, new DateTimeZone('Asia/Ho_Chi_Minh'));
    $timestamp = $date ? $date->getTimestamp() : 0;
    if (!$timestamp || $timestamp <= time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thời gian mở phòng phải lớn hơn thời gian hiện tại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $closeTimestamp = (int)($data['scheduled_close'][$unit] ?? 0);
    if ($closeTimestamp > 0 && $timestamp >= $closeTimestamp) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Giờ mở phải sớm hơn giờ đóng đã hẹn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data['rooms'][$unit] = false;
    $data['scheduled_open'][$unit] = $timestamp;
    write_json_file(ROOM_STATUS_FILE, $data);
    audit_log('Hẹn giờ mở phòng', $unit . ' - ' . date('d/m/Y H:i', $timestamp));
    echo json_encode(['success' => true, 'unit' => $unit, 'timestamp' => $timestamp], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'schedule_room_close') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $unit = trim((string)($input['unit'] ?? ''));
    $dateTime = trim((string)($input['datetime'] ?? ''));
    if ($unit === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu đơn vị'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = read_json_file(ROOM_STATUS_FILE, ['default_open' => false, 'rooms' => [], 'scheduled_open' => [], 'scheduled_close' => []]);
    $data['scheduled_open'] = is_array($data['scheduled_open'] ?? null) ? $data['scheduled_open'] : [];
    $data['scheduled_close'] = is_array($data['scheduled_close'] ?? null) ? $data['scheduled_close'] : [];
    if ($dateTime === '') {
        unset($data['scheduled_close'][$unit]);
        write_json_file(ROOM_STATUS_FILE, $data);
        audit_log('Hủy hẹn đóng phòng', $unit);
        echo json_encode(['success' => true, 'cancelled' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $isOpen = array_key_exists($unit, $data['rooms'] ?? [])
        ? !empty($data['rooms'][$unit])
        : !empty($data['default_open']);
    $openTimestamp = (int)($data['scheduled_open'][$unit] ?? 0);
    if (!$isOpen && $openTimestamp <= time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phải mở phòng hoặc hẹn giờ mở trước khi hẹn giờ đóng.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $dateTime, new DateTimeZone('Asia/Ho_Chi_Minh'));
    $timestamp = $date ? $date->getTimestamp() : 0;
    $minimumClose = $openTimestamp > time() ? $openTimestamp : time();
    if (!$timestamp || $timestamp <= $minimumClose) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Giờ đóng phải sau giờ mở phòng và lớn hơn thời gian hiện tại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data['scheduled_close'][$unit] = $timestamp;
    write_json_file(ROOM_STATUS_FILE, $data);
    audit_log('Hẹn giờ đóng phòng', $unit . ' - ' . date('d/m/Y H:i', $timestamp));
    echo json_encode(['success' => true, 'unit' => $unit, 'timestamp' => $timestamp], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set_all_rooms') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $open = !empty($input['open']);
    $data = ['default_open' => $open, 'rooms' => [], 'scheduled_open' => [], 'scheduled_close' => []];
    write_json_file(ROOM_STATUS_FILE, $data);
    audit_log($open ? 'Mở toàn bộ phòng thi' : 'Khóa toàn bộ phòng thi', 'Tất cả đơn vị');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_target') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $unit = trim($input['unit'] ?? '');
    $target = max(0, (int)($input['target'] ?? 0));
    if ($unit === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu đơn vị']);
        exit;
    }
    $targets = read_json_file(UNIT_TARGETS_FILE);
    if ($target === 0) unset($targets[$unit]); else $targets[$unit] = $target;
    write_json_file(UNIT_TARGETS_FILE, $targets);
    audit_log('Cập nhật quân số', $unit . ': ' . $target);
    echo json_encode(['success' => true, 'data' => $targets], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'logs') {
    $storedLogs = read_json_file(AUDIT_LOG_FILE);
    $changed = false;
    foreach ($storedLogs as &$log) {
        if (empty($log['id'])) {
            $log['id'] = uniqid('log_', true);
            $changed = true;
        }
    }
    unset($log);
    if ($changed) write_json_file(AUDIT_LOG_FILE, $storedLogs);
    $logs = array_reverse($storedLogs);
    echo json_encode(['success' => true, 'data' => array_slice($logs, 0, 300)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_log') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = trim($input['id'] ?? '');
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu ID nhật ký']);
        exit;
    }
    $logs = read_json_file(AUDIT_LOG_FILE);
    $before = count($logs);
    $logs = array_values(array_filter($logs, fn($log) => ($log['id'] ?? '') !== $id));
    write_json_file(AUDIT_LOG_FILE, $logs);
    echo json_encode(['success' => count($logs) < $before, 'message' => count($logs) < $before ? 'Đã xóa nhật ký' : 'Không tìm thấy nhật ký']);
    exit;
}

if ($action === 'log') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    audit_log(trim($input['action'] ?? 'Thao tác'), trim($input['detail'] ?? ''));
    echo json_encode(['success' => true]);
    exit;
}

// ===== Xóa một kết quả =====
if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Thiếu ID']);
        exit;
    }

    $results = json_decode(file_get_contents(RESULTS_FILE), true) ?: [];
    $results = array_filter($results, fn($r) => $r['id'] !== $id);
    $results = array_values($results);

    file_put_contents(RESULTS_FILE, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    audit_log('Xóa kết quả', 'ID: ' . $id);

    echo json_encode(['success' => true, 'message' => 'Đã xóa']);
    exit;
}

// ===== Xóa tất cả =====
if ($action === 'clear_all') {
    file_put_contents(RESULTS_FILE, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    audit_log('Xóa toàn bộ', 'Đã xóa toàn bộ kết quả');
    echo json_encode(['success' => true, 'message' => 'Đã xóa toàn bộ kết quả']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action không hợp lệ']);
?>
