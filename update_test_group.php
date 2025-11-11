<?php
require 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(["success" => false, "message" => "缺少必要參數"]);
    exit;
}

$id = (int)$data['id'];
$name = trim($data['name'] ?? '');
$description = trim($data['description'] ?? '');
$chapter_range = trim($data['chapter_range'] ?? '');
$time_limit = $data['time_limit'] !== '' ? (int)$data['time_limit'] : null;
$question_ids = $data['question_ids'] ?? [];

if ($name === '' || empty($question_ids)) {
    echo json_encode(["success" => false, "message" => "題組名稱或題目清單不可為空"]);
    exit;
}

// ✅ 編碼 JSON
$json = json_encode($question_ids, JSON_UNESCAPED_UNICODE);

// ✅ 更新資料
$stmt = $conn->prepare("
    UPDATE test_groups 
    SET name=?, description=?, chapter_range=?, question_ids=?, time_limit=? 
    WHERE id=?
");
$stmt->bind_param("ssssii", $name, $description, $chapter_range, $json, $time_limit, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => $stmt->error]);
}
$stmt->close();
$conn->close();
