<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'config.php';

function room_data() {
    $data = json_decode(file_get_contents(ROOM_STATUS_FILE), true);
    if (!is_array($data)) $data = ['default_open' => false, 'rooms' => []];
    $data['scheduled_open'] = is_array($data['scheduled_open'] ?? null) ? $data['scheduled_open'] : [];
    $data['scheduled_close'] = is_array($data['scheduled_close'] ?? null) ? $data['scheduled_close'] : [];
    return $data;
}

function room_is_open($unit, $data) {
    $scheduledClose = (int)($data['scheduled_close'][$unit] ?? 0);
    if ($scheduledClose > 0 && $scheduledClose <= time()) return false;
    $scheduled = (int)($data['scheduled_open'][$unit] ?? 0);
    if ($scheduled > 0 && $scheduled <= time()) return true;
    if (array_key_exists($unit, $data['rooms'] ?? [])) return !empty($data['rooms'][$unit]);
    return !empty($data['default_open']);
}

function base64url_encode_value($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$action = $_GET['action'] ?? 'status';
$data = room_data();

if ($action === 'status') {
    $unit = trim($_GET['unit'] ?? '');
    if ($unit !== '') {
        echo json_encode([
            'success' => true, 'unit' => $unit, 'open' => room_is_open($unit, $data),
            'scheduled_open' => (int)($data['scheduled_open'][$unit] ?? 0) ?: null,
            'scheduled_close' => (int)($data['scheduled_close'][$unit] ?? 0) ?: null
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $effectiveRooms = $data['rooms'] ?? [];
        foreach (($data['scheduled_open'] ?? []) as $scheduledUnit => $timestamp) {
            if ((int)$timestamp <= time()) $effectiveRooms[$scheduledUnit] = true;
        }
        foreach (($data['scheduled_close'] ?? []) as $scheduledUnit => $timestamp) {
            if ((int)$timestamp <= time()) $effectiveRooms[$scheduledUnit] = false;
        }
        echo json_encode([
            'success' => true, 'default_open' => !empty($data['default_open']),
            'rooms' => $effectiveRooms, 'scheduled_open' => $data['scheduled_open'],
            'scheduled_close' => $data['scheduled_close']
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'enter') {
    $unit = trim($_GET['unit'] ?? '');
    if ($unit === '' || !room_is_open($unit, $data)) {
        $scheduled = (int)($data['scheduled_open'][$unit] ?? 0);
        $scheduledClose = (int)($data['scheduled_close'][$unit] ?? 0);
        $closedBySchedule = $scheduledClose > 0 && $scheduledClose <= time();
        $message = $closedBySchedule
            ? 'Phòng thi đã đóng lúc ' . date('H:i', $scheduledClose) . ' ngày ' . date('d/m/Y', $scheduledClose) . '. Vui lòng liên hệ cán bộ coi thi.'
            : ($scheduled > time()
                ? 'Phòng thi chưa mở.'
                    . "\nThời gian mở cửa: " . date('H:i', $scheduled) . ' ngày ' . date('d/m/Y', $scheduled) . '.'
                    . ($scheduledClose > $scheduled
                        ? "\nThời gian đóng cửa: " . date('H:i', $scheduledClose) . ' ngày ' . date('d/m/Y', $scheduledClose) . '.'
                        : '')
                    . "\nVui lòng truy cập trong khung thời gian trên."
                : 'Phòng thi đang khóa. Vui lòng chờ Admin mở phòng.');
        http_response_code(423);
        echo json_encode([
            'success' => false,
            'code' => $closedBySchedule ? 'ROOM_CLOSED' : ($scheduled > time() ? 'ROOM_SCHEDULED' : 'ROOM_LOCKED'),
            'message' => $message,
            'scheduled_open' => $scheduled ?: null,
            'scheduled_close' => $scheduledClose ?: null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_encode(['unit' => $unit, 'iat' => time(), 'exp' => time() + 1200], JSON_UNESCAPED_UNICODE);
    $encoded = base64url_encode_value($payload);
    $signature = hash_hmac('sha256', $encoded, EXAM_TOKEN_SECRET);
    echo json_encode([
        'success' => true, 'unit' => $unit, 'token' => $encoded . '.' . $signature,
        'scheduled_open' => (int)($data['scheduled_open'][$unit] ?? 0) ?: null,
        'scheduled_close' => (int)($data['scheduled_close'][$unit] ?? 0) ?: null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Action không hợp lệ'], JSON_UNESCAPED_UNICODE);
?>
