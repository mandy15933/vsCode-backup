<?php
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "python_learning"; // 你資料庫名稱

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("連線失敗：" . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>
