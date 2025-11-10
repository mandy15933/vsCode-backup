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

// 🔹 查是否已有 test_code
$stmt = $conn->prepare("SELECT test_code FROM test_groups WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$testCode = $row['test_code'] ?? null;

// 🔹 若沒有 test_code，則自動生成一個唯一代碼
if (!$testCode) {
    // 例如：TEST20251106_XXXX (日期 + 隨機4碼)
    $testCode = "TEST" . date("Ymd") . "_" . strtoupper(substr(md5(uniqid()), 0, 4));

    $stmt = $conn->prepare("UPDATE test_groups SET test_code=? WHERE id=?");
    $stmt->bind_param("si", $testCode, $id);
    $stmt->execute();
    $stmt->close();
}

// 🔹 更新題組其他欄位
$stmt = $conn->prepare("UPDATE test_groups SET name=?, description=?, chapter_range=?, question_ids=? WHERE id=?");
$stmt->bind_param("ssssi", $name, $description, $chapter_range, $question_ids, $id);
$ok = $stmt->execute();
$stmt->close();

// ✅ 回傳結果
echo json_encode([
    "success" => $ok,
    "test_code" => $testCode,
]);
?>
