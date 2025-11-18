<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "沒有權限"]);
    exit;
}

$id = $_POST['id'] ?? 0;
$isHidden = $_POST['is_hidden'] ?? 0;

$stmt = $conn->prepare("UPDATE questions SET is_hidden=? WHERE id=?");
$stmt->bind_param("ii", $isHidden, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => "更新失敗"]);
}
$stmt->close();
