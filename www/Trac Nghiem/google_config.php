<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once 'config.php';
echo json_encode(['success' => true, 'enabled' => GOOGLE_CLIENT_ID !== '', 'client_id' => GOOGLE_CLIENT_ID], JSON_UNESCAPED_UNICODE);
?>
