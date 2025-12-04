<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents("php://input"), true);

$userId     = $_SESSION['user_id'] ?? 0;
$questionId = (int)($data['question_id'] ?? 0);
$action     = trim($data['action'] ?? '');
$timestamp  = date('Y-m-d H:i:s');
$code       = $data['code'] ?? '';
$aiComment  = $data['ai_comment'] ?? null; // 你可接提示結果

if (!$userId || !$questionId || !$action) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "❌ 缺少必要參數"]);
    exit;
}

// =============================
// 限制 AI 提示最多 3 次
// =============================
if ($action === 'aihint') {
    $stmt = $conn->prepare("
        SELECT id, aiHint_clicks 
        FROM student_answers 
        WHERE user_id=? AND question_id=?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("ii", $userId, $questionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $used = isset($row['aiHint_clicks']) ? (int)$row['aiHint_clicks'] : 0;

    if ($used >= 3) {
        echo json_encode([
            "success" => false,
            "message" => "❌ AI 提示已達 3 次上限"
        ]);
        exit;
    }
}

// =============================
// 依 action 更新的欄位
// =============================
switch ($action) {
    case 'mindmap':   $field = 'mindmap_clicks'; break;
    case 'flowchart': $field = 'flowchart_clicks'; break;
    case 'aihint':    $field = 'aiHint_clicks';   break;
    default:
        echo json_encode(["success" => false, "message" => "❌ 無效的 action 類型"]);
        exit;
}

// =============================
// 查是否已有 student_answers
// =============================
$stmt = $conn->prepare("
    SELECT id, viewed_types, answered_at 
    FROM student_answers 
    WHERE user_id=? AND question_id=?
");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nowISO = date('c');
$cooldownSeconds = 1;

if ($row) {

    $id = $row['id'];

    // ===== viewed_types 處理 =====
    $oldViewed = json_decode($row['viewed_types'] ?: '[]', true);
    if (!is_array($oldViewed)) $oldViewed = [];

    // 防止短時間重複記錄
    $lastTime = null;
    for ($i = count($oldViewed) - 1; $i >= 0; $i--) {
        if ($oldViewed[$i]['type'] === $action) {
            $lastTime = strtotime($oldViewed[$i]['time']);
            break;
        }
    }

    if ($lastTime && (time() - $lastTime) < $cooldownSeconds) {
        echo json_encode(["success" => false, "message" => "⚠️ 重複點擊（已忽略）"]);
        exit;
    }

    // 加上新的 viewed log
    $oldViewed[] = ["type" => $action, "time" => $nowISO];
    $newViewed = json_encode($oldViewed, JSON_UNESCAPED_UNICODE);

    // ===== 更新 student_answers =====
    $sql = "
        UPDATE student_answers
        SET $field = $field + 1,
            viewed_types = ?,
            answered_at = ?
        WHERE id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $newViewed, $timestamp, $id);
    $stmt->execute();
    $stmt->close();

    $studentAnswerId = $id;

} else {

    // ===== 第一次點擊 → 建立一筆 student_answers =====
    $newViewed = json_encode([["type" => $action, "time" => $nowISO]], JSON_UNESCAPED_UNICODE);

    $sql = "
        INSERT INTO student_answers
        (user_id, question_id, is_correct, attempts, $field, viewed_types, answered_at, pass_status)
        VALUES (?, ?, 0, 0, 1, ?, NOW(), '未評定')
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $userId, $questionId, $newViewed);
    $stmt->execute();

    $studentAnswerId = $stmt->insert_id;
    $stmt->close();
}

// ==================================================================
// 🎯 只有 AI 提示 → 才記錄 student_code_history
// ==================================================================
if ($action === 'aihint') {
    $stmt = $conn->prepare("
        INSERT INTO student_code_history (student_answer_id, code, ai_comment)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iss", $studentAnswerId, $code, $aiComment);
    $stmt->execute();
    $stmt->close();
}

// =============================
echo json_encode([
    "success" => true,
    "message" => "✅ 已記錄動作 + AI 提示處理（如適用）",
    "student_answer_id" => $studentAnswerId,
    "field" => $field
]);
?>
