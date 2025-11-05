<?php
session_start();
require 'db.php';

// 🔹 取得登入資訊
$userId = $_SESSION['user_id'] ?? 0;

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

// 🔹 解析 JSON
$data = json_decode(file_get_contents("php://input"), true);

$questionId = (int)($data['question_id'] ?? 0);
$isCorrect = (int)($data['is_correct'] ?? 0);
$timeSpent = (int)($data['time_spent'] ?? 0);
$code = $data['code'] ?? '';
$aiComment = $data['ai_comment'] ?? null;
$mindmapClicks = (int)($data['mindmap_clicks'] ?? 0);
$flowchartClicks = (int)($data['flowchart_clicks'] ?? 0);
$viewedTypes = json_encode($data['viewed_types'] ?? [], JSON_UNESCAPED_UNICODE);
$testGroupId = $data['test_group_id'] ?? null;

// 🔹 查章節 ID
$stmt = $conn->prepare("SELECT chapter FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$chapterRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$chapterId = $chapterRow['chapter'] ?? null;

if (!$chapterId) {
    echo json_encode(['success' => false, 'message' => '找不到對應章節']);
    exit;
}

// 🔹 查學生該題歷史紀錄（用來計算 attempts）
$stmt = $conn->prepare("SELECT MAX(attempts) AS last_attempts, MAX(is_correct) AS has_passed 
                        FROM student_answers 
                        WHERE user_id=? AND question_id=?");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$attempts = ($row['last_attempts'] ?? 0) + 1;
$hasPassedBefore = ($row['has_passed'] ?? 0);

// 🔹 若之前已通過 → 不再記錄
if ($hasPassedBefore && $isCorrect == 0) {
    echo json_encode(['success' => true, 'message' => '已通過，不再計入錯誤紀錄']);
    exit;
}

// 🔹 寫入 student_answers
$firstCorrectTime = null;
if ($isCorrect == 1 && !$hasPassedBefore) {
    $firstCorrectTime = date('Y-m-d H:i:s');
}

$stmt = $conn->prepare("
    INSERT INTO student_answers 
    (user_id, question_id, is_correct, attempts, first_correct_time, time_spent, 
     mindmap_clicks, flowchart_clicks, viewed_types, chapter_id, test_group_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    "iiiisiiisii",
    $userId,
    $questionId,
    $isCorrect,
    $attempts,
    $firstCorrectTime,
    $timeSpent,
    $mindmapClicks,
    $flowchartClicks,
    $viewedTypes,
    $chapterId,
    $testGroupId
);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => '插入 student_answers 失敗', 'error' => $stmt->error]);
    exit;
}

$studentAnswerId = $conn->insert_id;
$stmt->close();

// 🔹 寫入 student_code_history
$stmt = $conn->prepare("
    INSERT INTO student_code_history (student_answer_id, code, ai_comment)
    VALUES (?, ?, ?)
");
$stmt->bind_param("iss", $studentAnswerId, $code, $aiComment);
$stmt->execute();
$stmt->close();

// ✅ 回傳結果
echo json_encode([
    'success' => true,
    'message' => '作答紀錄已儲存',
    'answer_id' => $studentAnswerId,
    'attempts' => $attempts,
    'is_correct' => $isCorrect
]);
?>
