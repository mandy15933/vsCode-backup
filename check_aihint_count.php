<?php
session_start();
require 'db.php';

header('Content-Type: application/json; charset=utf-8');

// 必須有登入
$userId = $_SESSION['user_id'] ?? 0;
if ($userId <= 0) {
    echo json_encode(["success" => false, "used" => 0, "message" => "not logged in"]);
    exit;
}

// 必須有題目 ID
$questionId = isset($_GET['question_id']) ? intval($_GET['question_id']) : 0;
if ($questionId <= 0) {
    echo json_encode(["success" => false, "used" => 0, "message" => "no question id"]);
    exit;
}

/*
 你在 save_answer.php 裡會存：
 aiHint_clicks => $aiHintClicks

 所以這裡只需查 student_answers 中該題的 aiHint_clicks 累計次數
*/

// 查詢使用次數
$stmt = $conn->prepare("
    SELECT SUM(aiHint_clicks) AS used
    FROM student_answers
    WHERE user_id = ? AND question_id = ?
");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$used = intval($row['used'] ?? 0);

echo json_encode([
    "success" => true,
    "used" => $used,
    "limit" => 3
]);
