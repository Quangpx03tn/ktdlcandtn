<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận POST']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ']);
    exit;
}

// Validate các trường bắt buộc
$required = ['email', 'unit', 'fullname', 'rank', 'team', 'total', 'correct', 'wrong', 'percent', 'passed'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Thiếu trường: $field"]);
        exit;
    }
}

// Xác thực vé vào phòng thi do server cấp khi phòng đang mở.
$roomToken = trim($input['room_token'] ?? '');
$tokenParts = explode('.', $roomToken, 2);
$tokenValid = false;
if (count($tokenParts) === 2) {
    [$encodedPayload, $providedSignature] = $tokenParts;
    $expectedSignature = hash_hmac('sha256', $encodedPayload, EXAM_TOKEN_SECRET);
    $padding = strlen($encodedPayload) % 4;
    $base64 = strtr($encodedPayload, '-_', '+/');
    if ($padding) $base64 .= str_repeat('=', 4 - $padding);
    $tokenData = json_decode(base64_decode($base64), true);
    $tokenValid = hash_equals($expectedSignature, $providedSignature)
        && is_array($tokenData)
        && ($tokenData['unit'] ?? '') === trim($input['unit'])
        && (int)($tokenData['exp'] ?? 0) >= time();
}
if (!$tokenValid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'INVALID_ROOM_TOKEN', 'message' => 'Vé phòng thi không hợp lệ hoặc đã hết hạn. Vui lòng vào lại phòng thi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Chuẩn hóa Họ tên để so khớp (không dấu, chữ thường, bỏ khoảng trắng thừa)
 */
function normalize_name($name) {
    $name = trim(mb_strtolower($name, 'UTF-8'));
    // Bỏ dấu tiếng Việt
    $from = [
        'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
        'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
        'ì','í','ị','ỉ','ĩ',
        'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
        'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
        'ỳ','ý','ỵ','ỷ','ỹ',
        'đ',
        'À','Á','Ạ','Ả','Ã','Â','Ầ','Ấ','Ậ','Ẩ','Ẫ','Ă','Ằ','Ắ','Ặ','Ẳ','Ẵ',
        'È','É','Ẹ','Ẻ','Ẽ','Ê','Ề','Ế','Ệ','Ể','Ễ',
        'Ì','Í','Ị','Ỉ','Ĩ',
        'Ò','Ó','Ọ','Ỏ','Õ','Ô','Ồ','Ố','Ộ','Ổ','Ỗ','Ơ','Ờ','Ớ','Ợ','Ở','Ỡ',
        'Ù','Ú','Ụ','Ủ','Ũ','Ư','Ừ','Ứ','Ự','Ử','Ữ',
        'Ỳ','Ý','Ỵ','Ỷ','Ỹ',
        'Đ'
    ];
    $to = [
        'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
        'e','e','e','e','e','e','e','e','e','e','e',
        'i','i','i','i','i',
        'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
        'u','u','u','u','u','u','u','u','u','u','u',
        'y','y','y','y','y',
        'd',
        'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
        'e','e','e','e','e','e','e','e','e','e','e',
        'i','i','i','i','i',
        'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
        'u','u','u','u','u','u','u','u','u','u','u',
        'y','y','y','y','y',
        'd'
    ];
    $name = str_replace($from, $to, $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

/**
 * Nhận diện số Thông tư trong câu hỏi. Nếu câu hỏi chỉ hỏi "Thông tư nào",
 * hệ thống lấy số Thông tư trong đáp án đúng để tránh tính cả các phương án nhiễu.
 */
function extract_circular_refs($questionText, $correctOptionText = '') {
    $extract = function ($text) {
        $refs = [];
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/(?:thông\s*tư(?:\s*liên\s*tịch)?(?:\s*số)?\s*[:.]?\s*)?(\d{1,3}\s*\/\s*\d{4}\s*\/\s*(?:TTLT\s*-\s*)?[A-ZĐ0-9-]{2,30})/iu', $text, $matches)) {
            foreach ($matches[1] as $raw) {
                $code = mb_strtoupper(preg_replace('/\s+/', '', $raw), 'UTF-8');
                $refs['Thông tư ' . $code] = true;
            }
        }
        if (!$refs && preg_match_all('/thông\s*tư(?:\s*liên\s*tịch)?(?:\s*số)?\s*[:.]?\s*(\d{1,3})(?!\s*\/)/iu', $text, $matches)) {
            foreach ($matches[1] as $number) $refs['Thông tư số ' . (int)$number] = true;
        }
        return array_keys($refs);
    };
    $refs = $extract($questionText);
    return $refs ?: $extract($correctOptionText);
}

function build_circular_breakdown($examCode, $submittedAnswers) {
    $sets = json_decode(@file_get_contents(EXAM_SETS_PUBLISHED_FILE), true);
    if (!is_array($sets)) return [];
    $examSet = null;
    foreach ($sets as $set) {
        if (trim((string)($set['code'] ?? '')) === trim((string)$examCode)) {
            $examSet = $set;
            break;
        }
    }
    if (!$examSet || !is_array($examSet['questions'] ?? null)) return [];
    $answers = is_array($submittedAnswers) ? array_values($submittedAnswers) : [];
    $breakdown = [];
    foreach (array_values($examSet['questions']) as $index => $question) {
        $options = array_values($question['options'] ?? []);
        $correctIndex = (int)($question['answer'] ?? -1);
        $correctText = ($correctIndex >= 0 && isset($options[$correctIndex])) ? $options[$correctIndex] : '';
        $refs = extract_circular_refs($question['q'] ?? '', $correctText);
        if (!$refs) continue;
        $selected = $answers[$index]['selected'] ?? null;
        $answered = $selected !== null && $selected !== '';
        $isCorrect = $answered && (int)$selected === $correctIndex;
        foreach ($refs as $ref) {
            $breakdown[] = [
                'circular' => $ref,
                'question_key' => (string)($question['source_id'] ?? $index),
                'answered' => $answered,
                'correct' => $isCorrect
            ];
        }
    }
    return $breakdown;
}

$fullname = trim($input['fullname']);
$normNew = normalize_name($fullname);
$email = trim($input['email']);
$unit = trim($input['unit']);
$rank = trim($input['rank']);
$team = trim($input['team']);
$normEmail = mb_strtolower($email, 'UTF-8');
$normUnit = normalize_name($unit);
$normRank = normalize_name($rank);
$normTeam = normalize_name($team);
$examCode = trim((string)($input['exam_code'] ?? ''));
$circularBreakdown = build_circular_breakdown($examCode, $input['answers'] ?? []);

if ($normNew === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Họ tên không hợp lệ']);
    exit;
}

// Đọc file hiện tại (có khóa)
$fp = fopen(RESULTS_FILE, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Không mở được file kết quả']);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Không khóa được file']);
    exit;
}

$contents = stream_get_contents($fp);
$results = json_decode($contents ?: '[]', true) ?: [];

// Chỉ chấp nhận lần thi đầu tiên theo email + đơn vị.
// Không dùng riêng họ tên vì có thể có nhiều cán bộ trùng tên, cấp bậc.
foreach ($results as $existing) {
    $oldEmail = mb_strtolower(trim($existing['email'] ?? ''), 'UTF-8');
    $oldUnit = normalize_name($existing['unit'] ?? '');
    $samePrimaryIdentity = $normEmail !== '' && $oldEmail !== ''
        && $oldEmail === $normEmail && $oldUnit === $normUnit;

    // Dữ liệu cũ thiếu email: dùng tổ hợp đầy đủ để vẫn chống nộp lặp.
    $legacyIdentity = ($oldEmail === '' || $normEmail === '')
        && normalize_name($existing['fullname'] ?? '') === $normNew
        && $oldUnit === $normUnit
        && normalize_name($existing['rank'] ?? '') === $normRank
        && normalize_name($existing['team'] ?? '') === $normTeam;

    if ($samePrimaryIdentity || $legacyIdentity) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(409); // Conflict
        echo json_encode([
            'success' => false,
            'code' => 'ALREADY_SUBMITTED',
            'message' => 'Tài khoản này đã có kết quả thi lần đầu tại đơn vị đã chọn. Hệ thống không chấp nhận các lần thi sau.',
            'existing_time' => $existing['time'] ?? '',
            'existing_unit' => $existing['unit'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$record = [
    'id'        => uniqid('r_', true),
    'email'     => htmlspecialchars($email),
    'unit'      => htmlspecialchars($unit),
    'fullname'   => htmlspecialchars($fullname),
    'rank'      => htmlspecialchars($rank),
    'team'      => htmlspecialchars($team),
    'exam_code' => htmlspecialchars($examCode),
    'total'     => (int)$input['total'],
    'correct'   => (int)$input['correct'],
    'wrong'     => (int)$input['wrong'],
    'answered'  => max(0, min((int)$input['total'], (int)($input['answered'] ?? $input['total']))),
    'duration_seconds' => max(0, (int)($input['duration_seconds'] ?? 0)),
    'auto_submitted' => !empty($input['auto_submitted']),
    'answer_signature' => hash('sha256', json_encode($input['answers'] ?? [], JSON_UNESCAPED_UNICODE)),
    'circular_breakdown' => $circularBreakdown,
    'percent'   => (int)$input['percent'],
    'passed'    => (bool)$input['passed'],
    'grade'     => htmlspecialchars(trim($input['grade'] ?? '')),
    'time'      => date('Y-m-d H:i:s'),
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? ''
];

$results[] = $record;

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
    'success' => true,
    'message' => 'Đã lưu kết quả thành công (lần thi đầu tiên)',
    'id' => $record['id']
], JSON_UNESCAPED_UNICODE);
