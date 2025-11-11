<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

// 讀取前端傳入資料
$input = json_decode(file_get_contents("php://input"), true);

$userId      = $_SESSION['user_id'] ?? 0;
$questionId  = (int)($input['question_id'] ?? 0);
$toolType    = $input['tool_type'] ?? '';
$usefulness  = $input['usefulness'] ?? [];
$usability   = $input['usability'] ?? [];
$comment     = trim($input['comment'] ?? '');

if (!$userId || !$questionId || !in_array($toolType, ['mindmap', 'flowchart'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "❌ 缺少必要參數"]);
    exit;
}

// 將問卷內容轉成 JSON 格式存入資料庫
$usefulnessJson = json_encode($usefulness, JSON_UNESCAPED_UNICODE);
$usabilityJson  = json_encode($usability, JSON_UNESCAPED_UNICODE);

// ✅ 檢查學生是否已填過這題（同一 user + question + tool_type）
$stmt = $conn->prepare("
    SELECT id FROM visual_feedback 
    WHERE user_id = ? AND question_id = ? AND tool_type = ?
");
$stmt->bind_param("iis", $userId, $questionId, $toolType);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    // ✅ 若已有紀錄 → 更新
    $stmt = $conn->prepare("
        UPDATE visual_feedback 
        SET usefulness = ?, usability = ?, comment = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("sssi", $usefulnessJson, $usabilityJson, $comment, $row['id']);
    $stmt->execute();
    $stmt->close();
    $message = "問卷資料已更新";
} else {
    // ✅ 若沒有 → 新增
    $stmt = $conn->prepare("
        INSERT INTO visual_feedback 
        (user_id, question_id, tool_type, usefulness, usability, comment, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iissss", $userId, $questionId, $toolType, $usefulnessJson, $usabilityJson, $comment);
    $stmt->execute();
    $stmt->close();
    $message = "問卷資料已儲存";
}

// ✅ 回傳結果
echo json_encode([
    "success" => true,
    "message" => $message,
    "tool_type" => $toolType
]);
?>
