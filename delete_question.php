<?php
require 'db.php';
session_start();

// 檢查是否為 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "無效的請求方式"]);
    exit;
}

$id = $_POST['id'] ?? 0;
if (!$id || !is_numeric($id)) {
    echo json_encode(["success" => false, "message" => "缺少題目 ID"]);
    exit;
}

// 檢查題目是否存在
$stmt = $conn->prepare("SELECT id FROM questions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "找不到此題目"]);
    exit;
}
$stmt->close();

// 執行刪除
$stmt = $conn->prepare("DELETE FROM questions WHERE id = ?");
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "SQL 準備失敗: " . $conn->error]);
    exit;
}
$stmt->bind_param("i", $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => "刪除失敗: " . $conn->error]);
}
?>
