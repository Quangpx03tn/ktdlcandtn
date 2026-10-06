<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$now = time();
$onlineWindow = 35;
$handle = fopen(PRESENCE_FILE, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Không cập nhật được trạng thái trực tuyến.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = stream_get_contents($handle);
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];
$data += ['total_visits' => 0, 'visits' => [], 'clients' => []];
if (!is_array($data['visits'])) $data['visits'] = [];
if (!is_array($data['clients'])) $data['clients'] = [];

foreach ($data['clients'] as $id => $client) {
    if ($now - (int)($client['last_seen'] ?? 0) > $onlineWindow) {
        unset($data['clients'][$id]);
    } else {
        // Loại bỏ dữ liệu định danh từng được lưu ở phiên bản cũ.
        unset($data['clients'][$id]['email'], $data['clients'][$id]['fullname'], $data['clients'][$id]['unit'], $data['clients'][$id]['ip']);
    }
}
foreach ($data['visits'] as $id => $timestamp) {
    if ($now - (int)$timestamp > 2592000) unset($data['visits'][$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string)($input['client_id'] ?? ''), 0, 80));
    $deviceId = preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string)($input['device_id'] ?? ''), 0, 80));
    $visitId = preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string)($input['visit_id'] ?? ''), 0, 80));
    $stage = in_array(($input['stage'] ?? ''), ['online', 'exam', 'offline'], true) ? $input['stage'] : 'online';
    $blocked = false;
    if ($stage === 'exam' && $deviceId !== '') {
        foreach ($data['clients'] as $existingId => $existingClient) {
            if ($existingId !== $clientId && ($existingClient['device_id'] ?? '') === $deviceId && ($existingClient['stage'] ?? '') === 'exam') {
                $blocked = true;
                break;
            }
        }
    }
    if ($clientId !== '') {
        if ($stage === 'offline') {
            unset($data['clients'][$clientId]);
        } else {
            $data['clients'][$clientId] = [
                'last_seen' => $now,
                'stage' => $blocked ? 'online' : $stage,
                'device_id' => $deviceId ?: $clientId,
                'ip_hash' => hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', EXAM_TOKEN_SECRET)
            ];
        }
    }
    if ($visitId !== '' && !isset($data['visits'][$visitId])) {
        $data['visits'][$visitId] = $now;
        $data['total_visits'] = (int)$data['total_visits'] + 1;
    }
}

$onlineDevices = [];
foreach ($data['clients'] as $id => $client) $onlineDevices[$client['device_id'] ?? $id] = true;
$online = count($onlineDevices);
$examining = count(array_filter($data['clients'], fn($client) => ($client['stage'] ?? '') === 'exam'));
$ipGroups = [];
foreach ($data['clients'] as $client) {
    if (($client['stage'] ?? '') !== 'exam') continue;
    $ip = $client['ip_hash'] ?? '';
    if ($ip !== '') $ipGroups[$ip] = ($ipGroups[$ip] ?? 0) + 1;
}
$riskCount = count(array_filter($ipGroups, fn($count) => $count >= 3));

rewind($handle);
ftruncate($handle, 0);
fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'success' => true,
    'online' => $online,
    'examining' => $examining,
    'total_visits' => (int)$data['total_visits'],
    'risk_ip_groups' => $riskCount,
    'blocked' => $blocked ?? false,
    'message' => ($blocked ?? false) ? 'Thiết bị này đang có một bài thi khác. Mỗi thiết bị chỉ được làm một bài tại cùng thời điểm.' : ''
], JSON_UNESCAPED_UNICODE);
?>
