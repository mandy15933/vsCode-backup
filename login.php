<?php
session_start();
require 'db.php';

$login_id = $_POST['login_id'] ?? '';
$password = $_POST['password'] ?? '';

$stmt = $conn->prepare("SELECT * FROM Users WHERE StudentID=? OR Username=?");
$stmt->bind_param("ss", $login_id, $login_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($password, $user['PasswordHash'])) {
    $_SESSION['user_id'] = $user['UserID'];
    $_SESSION['username'] = $user['Username'];
    $_SESSION['class_name'] = $user['ClassName'];
    echo json_encode([
        'success'=>true, 
        'username'=>$user['Username'],
        'class_name'=>$user['ClassName']
    ]);
} else {
    echo json_encode(['success'=>false, 'message'=>'學號或密碼錯誤']);
}
