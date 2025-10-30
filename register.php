<?php
require 'db.php';

$student_id = $_POST['student_id'] ?? '';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$class_name = $_POST['class_name'] ?? '';

if (!$student_id || !$username || !$password || !$class_name) {
    echo json_encode(['success' => false, 'message' => '所有欄位皆為必填']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM Users WHERE StudentID=?");
$stmt->bind_param("s", $student_id);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    echo json_encode(['success'=>false, 'message'=>'學號已存在']);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO Users (StudentID, Username, PasswordHash, ClassName) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $student_id, $username, $hash, $class_name);
$stmt->execute();

echo json_encode(['success'=>true]);
