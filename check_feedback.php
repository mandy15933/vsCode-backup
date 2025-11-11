<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $userId = $_SESSION['user_id'] ?? 0;
    $questionId = (int)($_GET['question_id'] ?? 0);

    if (!$userId || !$questionId) {
        echo json_encode([
            "success" => false,
            "message" => "缺少必要參數",
            "answered" => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("SELECT tool_type FROM visual_feedback WHERE user_id=? AND question_id=?");
    $stmt->bind_param("ii", $userId, $questionId);
    $stmt->execute();
    $res = $stmt->get_result();

    $answered = [];
    while ($r = $res->fetch_assoc()) {
        $answered[] = $r['tool_type'];
    }
    $stmt->close();

    echo json_encode([
        "success" => true,
        "answered" => $answered
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "伺服器錯誤：" . $e->getMessage(),
        "answered" => []
    ], JSON_UNESCAPED_UNICODE);
}
?>
