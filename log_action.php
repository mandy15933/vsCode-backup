<?php
session_start();
require 'db.php';

$data = json_decode(file_get_contents("php://input"), true);

$userId = $_SESSION['user_id'] ?? 0;
$questionId = (int)($data['question_id'] ?? 0);
$action = trim($data['action'] ?? '');

if (!$userId || !$questionId || !$action) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "❌ 缺少必要參數"]);
    exit;
}

// 決定要更新哪個欄位
$field = '';
switch ($action) {
  case 'mindmap':  $field = 'mindmap_clicks'; break;
  case 'flowchart': $field = 'flowchart_clicks'; break;
  case 'aihint':   $field = 'aiHint_clicks'; break;
  default:
    echo json_encode(["success" => false, "message" => "❌ 無效的 action 類型"]);
    exit;
}

// 先確認是否已有 student_answers 紀錄
$stmt = $conn->prepare("SELECT id FROM student_answers WHERE user_id=? AND question_id=?");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($exists) {
    // ✅ 已有紀錄 → 累加該欄位
    $sql = "UPDATE student_answers 
            SET $field = $field + 1,
                answered_at = NOW()
            WHERE user_id=? AND question_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $questionId);
    $stmt->execute();
    $stmt->close();
} else {
    // 🆕 沒紀錄 → 自動新增一筆（只記點擊，不算作答）
    $stmt = $conn->prepare("
        INSERT INTO student_answers (user_id, question_id, is_correct, attempts, $field, answered_at, pass_status)
        VALUES (?, ?, 0, 0, 1, NOW(), '未評定')
    ");
    $stmt->bind_param("ii", $userId, $questionId);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(["success" => true, "message" => "✅ 點擊紀錄已更新"]);
