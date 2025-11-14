<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

// 讀取前端傳入資料
$input = json_decode(file_get_contents("php://input"), true);

$userId      = $_SESSION['user_id'] ?? 0;
$questionId  = (int)($input['question_id'] ?? 0);
$toolType    = $input['tool_type'] ?? '';
$usefulness  = $input['usefulness'] ?? [];   // PU
$easeOfUse   = $input['ease_of_use'] ?? [];  // PEOU
$usability   = $input['usability'] ?? [];    // US
$comment     = trim($input['comment'] ?? '');

if (!$userId || !$questionId || !in_array($toolType, ['mindmap', 'flowchart'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "❌ 缺少必要參數"]);
    exit;
}

// 轉成 JSON
$usefulnessJson = json_encode($usefulness, JSON_UNESCAPED_UNICODE);
$easeOfUseJson  = json_encode($easeOfUse, JSON_UNESCAPED_UNICODE);
$usabilityJson  = json_encode($usability, JSON_UNESCAPED_UNICODE);

// 檢查是否已有紀錄
$stmt = $conn->prepare("
    SELECT id FROM visual_feedback 
    WHERE user_id = ? AND question_id = ? AND tool_type = ?
");
$stmt->bind_param("iis", $userId, $questionId, $toolType);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    // 已存在 → 更新
    $stmt = $conn->prepare("
        UPDATE visual_feedback 
        SET usefulness = ?, 
            ease_of_use = ?, 
            usability = ?, 
            comment = ?, 
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $usefulnessJson, $easeOfUseJson, $usabilityJson, $comment, $row['id']);
    $stmt->execute();
    $stmt->close();

    $message = "問卷資料已更新";
} else {
    // 不存在 → 新增
    $stmt = $conn->prepare("
        INSERT INTO visual_feedback 
        (user_id, question_id, tool_type, usefulness, ease_of_use, usability, comment, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("iisssss", 
        $userId, 
        $questionId, 
        $toolType, 
        $usefulnessJson, 
        $easeOfUseJson, 
        $usabilityJson, 
        $comment
    );
    $stmt->execute();
    $stmt->close();

    $message = "問卷資料已儲存";
}

// 回傳結果
echo json_encode([
    "success" => true,
    "message" => $message,
    "tool_type" => $toolType
]);
?>
