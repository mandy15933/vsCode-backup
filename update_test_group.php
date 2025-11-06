<?php
require 'db.php';
session_start();

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['id'] ?? 0;
$name = $data['name'] ?? '';
$description = $data['description'] ?? '';
$chapter_range = $data['chapter_range'] ?? '';
$question_ids = json_encode($data['question_ids'] ?? [], JSON_UNESCAPED_UNICODE);

if (!$id || !$name) {
    echo json_encode(["success" => false, "message" => "缺少必要資料"]);
    exit;
}

$stmt = $conn->prepare("UPDATE test_groups SET name=?, description=?, chapter_range=?, question_ids=? WHERE id=?");
$stmt->bind_param("ssssi", $name, $description, $chapter_range, $question_ids, $id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(["success" => $ok]);
?>
