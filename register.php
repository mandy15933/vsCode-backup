<?php
require 'db.php';

// 取得表單資料
$studentID  = $_POST['student_id'] ?? '';
$username   = $_POST['username'] ?? '';
$password   = $_POST['password'] ?? '';
$class_name = $_POST['class_name'] ?? '';

if (empty($studentID) || empty($username) || empty($password)) {
    echo json_encode(["success" => false, "message" => "請完整填寫所有欄位"]);
    exit;
}

// 檢查學號是否重複
$check = $conn->prepare("SELECT * FROM users WHERE StudentID = ?");
$check->bind_param("s", $studentID);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "此學號已註冊"]);
    exit;
}

// 建立加密密碼
$hash = password_hash($password, PASSWORD_DEFAULT);

// 插入資料
$stmt = $conn->prepare("INSERT INTO users (StudentID, Username, PasswordHash, ClassName, role) VALUES (?, ?, ?, ?, 'student')");
$stmt->bind_param("ssss", $studentID, $username, $hash, $class_name);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "註冊成功"]);
} else {
    echo json_encode(["success" => false, "message" => "註冊失敗：" . $conn->error]);
}

$stmt->close();
$conn->close();
?>
