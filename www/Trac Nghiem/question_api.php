<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once 'config.php';
$questions = json_decode(file_get_contents(QUESTION_BANK_FILE), true);
if (!is_array($questions) || count($questions) === 0) {
    echo json_encode(['success' => true, 'total' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['success' => true, 'total' => count($questions), 'data' => $questions], JSON_UNESCAPED_UNICODE);
?>
