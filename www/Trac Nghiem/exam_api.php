<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'config.php';

$count = max(1, min(100, (int)($_GET['count'] ?? 20)));
$sets = json_decode(file_get_contents(EXAM_SETS_PUBLISHED_FILE), true);
if (!is_array($sets)) $sets = [];
$ready = count($sets) > 0;
$matches = array_values(array_filter($sets, fn($set) => (int)($set['question_count'] ?? count($set['questions'] ?? [])) === $count));
if (!$matches) {
    echo json_encode([
        'success' => true,
        'ready' => $ready,
        'assigned' => false,
        'message' => $ready ? 'Ngân hàng chưa có bộ đề phù hợp với số câu đã chọn.' : 'Ngân hàng đề thi chưa được Admin upload lên server.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$set = $matches[random_int(0, count($matches) - 1)];
echo json_encode(['success' => true, 'ready' => true, 'assigned' => true, 'data' => $set], JSON_UNESCAPED_UNICODE);
?>
