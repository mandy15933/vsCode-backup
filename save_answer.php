<?php
session_start();
require 'db.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// === 1️⃣ 讀取前端 JSON ===
$raw = file_get_contents("php://input");
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // 移除 BOM
file_put_contents("debug_input.txt", "[".date('H:i:s')."] ".$raw."\n", FILE_APPEND);

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["success" => false, "message" => "JSON decode error: " . json_last_error_msg()]);
    exit;
}
if (!is_array($data)) {
    echo json_encode(["success" => false, "message" => "❌ 無效 JSON 結構"]);
    exit;
}

// === 2️⃣ 解析資料 ===
$userId          = $_SESSION['user_id'] ?? 1;
$questionId      = (int)($data['question_id'] ?? 0);
$isCorrect       = (int)($data['is_correct'] ?? 0);
$timeSpent       = (int)($data['time_spent'] ?? 0);
$mindmapClicks   = (int)($data['mindmap_clicks'] ?? 0);
$flowchartClicks = (int)($data['flowchart_clicks'] ?? 0);
$aiHintClicks    = (int)($data['aiHint_clicks'] ?? 0);
$aiComment       = $data['ai_comment'] ?? '';
$testGroupId     = isset($data['test_group_id']) && $data['test_group_id'] !== '' ? (int)$data['test_group_id'] : null;

// viewed_types 可能是 JSON 字串，也可能是陣列
$viewedRaw = $data['viewed_types'] ?? '[]';
if (is_string($viewedRaw)) {
    $viewed_types = json_decode($viewedRaw, true) ?: [];
} else {
    $viewed_types = $viewedRaw;
}
$viewedJson = json_encode($viewed_types, JSON_UNESCAPED_UNICODE);

// === 3️⃣ 查章節 ID ===
$stmt = $conn->prepare("SELECT chapter FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$stmt->bind_result($chapterId);
$stmt->fetch();
$stmt->close();
$chapterId = $chapterId ?? null;

// === 4️⃣ 查是否已有紀錄 ===
$stmt = $conn->prepare("SELECT id, attempts, first_correct_time FROM student_answers WHERE user_id=? AND question_id=?");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

$passStatus = $isCorrect ? '通過' : '未通過';

// === 5️⃣ 更新或新增 ===
if ($existing) {
    // 更新（累加次數與點擊）
    $newAttempts = ($existing['attempts'] ?? 0) + 1;
    $firstCorrect = $existing['first_correct_time'];
    if ($isCorrect && !$firstCorrect) {
        $firstCorrect = date('Y-m-d H:i:s');
    }

    $stmt = $conn->prepare("
        UPDATE student_answers
        SET is_correct=?, attempts=?, time_spent=?,
            mindmap_clicks = mindmap_clicks + ?,
            flowchart_clicks = flowchart_clicks + ?,
            aiHint_clicks = aiHint_clicks + ?,
            viewed_types=?, ai_comment=?,
            test_group_id=?, pass_status=?,
            first_correct_time=IFNULL(?, first_correct_time),
            answered_at=NOW()
        WHERE user_id=? AND question_id=?;
    ");
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "SQL prepare failed: " . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        "iiiiii ssissii",
        $isCorrect,        // i
        $newAttempts,      // i
        $timeSpent,        // i
        $mindmapClicks,    // i
        $flowchartClicks,  // i
        $aiHintClicks,     // i
        $viewedJson,       // s
        $aiComment,        // s
        $testGroupId,      // i
        $passStatus,       // s
        $firstCorrect,     // s
        $userId,           // i
        $questionId        // i
    );
    $stmt->execute();
    $stmt->close();

} else {
    // 新增
    $firstCorrect = $isCorrect ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("
        INSERT INTO student_answers 
        (user_id, question_id, is_correct, attempts, first_correct_time, answered_at, time_spent,
         mindmap_clicks, flowchart_clicks, aiHint_clicks, viewed_types, chapter_id,
         test_group_id, ai_comment, answer_mode, pass_status)
        VALUES (?, ?, ?, 1, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 'practice', ?);
    ");
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "SQL prepare failed: " . $conn->error]);
        exit;
    }

    $stmt->bind_param(
        "iii siiii s iiss s",
        $userId,           // i
        $questionId,       // i
        $isCorrect,        // i
        $firstCorrect,     // s
        $timeSpent,        // i
        $mindmapClicks,    // i
        $flowchartClicks,  // i
        $aiHintClicks,     // i
        $viewedJson,       // s
        $chapterId,        // i
        $testGroupId,      // i
        $aiComment,        // s
        $passStatus        // s
    );
    $stmt->execute();
    $stmt->close();
}

// === 6️⃣ 回傳成功訊息 ===
echo json_encode(["success" => true, "message" => "✅ 作答紀錄已更新"]);
?>
