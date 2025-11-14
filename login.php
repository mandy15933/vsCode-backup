<?php
session_start();
require 'db.php';

$studentID = $_POST['student_id'] ?? '';
$password  = $_POST['password'] ?? '';

if (empty($studentID) || empty($password)) {
    echo json_encode(["success" => false, "message" => "請輸入學號與密碼"]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE StudentID = ?");
$stmt->bind_param("s", $studentID);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user && $password === $user['PasswordHash']) {
    $_SESSION['user_id'] = $user['UserID'];
    $_SESSION['username'] = $user['Username'];
    $_SESSION['class_name'] = $user['ClassName'];
    $_SESSION['role'] = $user['role'];
    echo json_encode(["success" => true, "message" => "登入成功"]);
} else {
    echo json_encode(["success" => false, "message" => "學號或密碼錯誤"]);
}

