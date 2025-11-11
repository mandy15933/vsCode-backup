<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents("php://input"), true);

$userId     = $_SESSION['user_id'] ?? 0;
$questionId = (int)($data['question_id'] ?? 0);
$action     = trim($data['action'] ?? '');
$timestamp  = date('Y-m-d H:i:s');

if (!$userId || !$questionId || !$action) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "❌ 缺少必要參數"]);
    exit;
}

// ✅ 對應資料庫欄位
switch ($action) {
    case 'mindmap':   $field = 'mindmap_clicks'; break;
    case 'flowchart': $field = 'flowchart_clicks'; break;
    case 'aihint':    $field = 'aiHint_clicks'; break;
    default:
        echo json_encode(["success" => false, "message" => "❌ 無效的 action 類型"]);
        exit;
}

// ✅ 查詢是否已有紀錄
$stmt = $conn->prepare("SELECT id, viewed_types, answered_at FROM student_answers WHERE user_id=? AND question_id=?");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nowISO = date('c'); // ISO8601 格式
$cooldownSeconds = 1; // 1秒內不重複

if ($row) {
    $id = $row['id'];

    // 取出舊 viewed_types
    $oldViewed = json_decode($row['viewed_types'] ?: '[]', true);
    if (!is_array($oldViewed)) $oldViewed = [];

    // 🔹 檢查上次同類型行為時間（防止 1 秒內重複）
    $lastTime = null;
    for ($i = count($oldViewed) - 1; $i >= 0; $i--) {
        if ($oldViewed[$i]['type'] === $action) {
            $lastTime = strtotime($oldViewed[$i]['time']);
            break;
        }
    }
    $nowTime = time();
    if ($lastTime && ($nowTime - $lastTime) < $cooldownSeconds) {
        echo json_encode(["success" => false, "message" => "⚠️ 重複點擊（已忽略）"]);
        exit;
    }

    // 🔹 附加新行為紀錄
    $oldViewed[] = ["type" => $action, "time" => $nowISO];
    $newViewed = json_encode($oldViewed, JSON_UNESCAPED_UNICODE);

    // 🔹 更新資料：點擊數 +1、更新 viewed_types
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

    echo json_encode([
        "success" => true,
        "message" => "✅ 點擊紀錄已更新（+1）",
        "field" => $field
    ]);
} else {
    // 🆕 沒紀錄 → 建立一筆初始資料
    $newViewed = json_encode([[ "type" => $action, "time" => $nowISO ]], JSON_UNESCAPED_UNICODE);
    $sql = "
        INSERT INTO student_answers
        (user_id, question_id, is_correct, attempts, $field, viewed_types, answered_at, pass_status)
        VALUES (?, ?, 0, 0, 1, ?, NOW(), '未評定')
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $userId, $questionId, $newViewed);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "success" => true,
        "message" => "🆕 首次點擊已建立紀錄",
        "field" => $field
    ]);
}
?>
