<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

// 讀取 JSON 請求
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["success" => false, "message" => "無效的請求格式"]);
    exit;
}

// ✅ 使用者資訊（預設用 Session）
$userId = $_SESSION['user_id'] ?? 1;

// ✅ 接收參數
$questionId  = (int)($input['question_id'] ?? 0);
$isCorrect   = (int)($input['is_correct'] ?? 0);
$timeSpent   = (int)($input['time_spent'] ?? 0);
$code        = $input['code'] ?? '';
$aiComment   = $input['ai_comment'] ?? null;
$testGroupId = isset($input['test_group_id']) ? (int)$input['test_group_id'] : null;
$answerMode  = $testGroupId ? 'exam' : 'practice';

// 點擊次數改由 log_action.php 負責，不從前端接也不在這裡更新
$viewedTypes = '[]';

// ✅ 查詢題目所屬章節
$stmt = $conn->prepare("SELECT chapter FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(["success" => false, "message" => "找不到題目 ID"]);
    exit;
}
$chapterId = (int)$row['chapter'];

// ✅ 取得現有紀錄（不抓點擊欄位，避免誤用）
$stmt = $conn->prepare("
    SELECT id, attempts, is_correct, first_correct_time
    FROM student_answers
    WHERE user_id=? AND question_id=?
");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

$now = date('Y-m-d H:i:s');
$attempts = 1;
$firstCorrectTime = null;

if ($existing) {

    // 次數累積
    $attempts = ($existing['attempts'] ?? 0) + 1;

    // ❗ 若已有通過紀錄，不可再被覆蓋
    $isCorrectFinal = $existing['is_correct'] == 1 ? 1 : $isCorrect;

    // ❗ 首次通過時間只記第一次
    if ($existing['is_correct'] == 1 && !empty($existing['first_correct_time'])) {
        $firstCorrectTime = $existing['first_correct_time'];
    } elseif ($isCorrectFinal == 1 && $existing['is_correct'] == 0) {
        $firstCorrectTime = $now;
    } else {
        $firstCorrectTime = $existing['first_correct_time'];
    }

    $passStatus = $isCorrectFinal ? '通過' : '未通過';

    $stmt = $conn->prepare("
        UPDATE student_answers
        SET 
            is_correct=?,
            attempts=?,
            time_spent=?,
            chapter_id=?,
            test_group_id=?,
            answer_mode=?,
            pass_status=?,
            first_correct_time=?,
            answered_at=NOW()
        WHERE id=?
    ");

    $stmt->bind_param(
        "iiisisssi",
        $isCorrectFinal,
        $attempts,
        $timeSpent,
        $chapterId,
        $testGroupId,
        $answerMode,
        $passStatus,
        $firstCorrectTime,
        $existing['id']
    );

    $stmt->execute();
    $studentAnswerId = $existing['id'];
    $stmt->close();
}


// ✅ 紀錄學生當次程式碼歷史
$stmt = $conn->prepare("
    INSERT INTO student_code_history (student_answer_id, code, ai_comment)
    VALUES (?, ?, ?)
");
$stmt->bind_param("iss", $studentAnswerId, $code, $aiComment);
$stmt->execute();
$stmt->close();

echo json_encode([
    "success" => true,
    "message" => "作答結果已儲存（點擊由 log_action 累加）",
    "student_answer_id" => $studentAnswerId,
    "attempts" => $attempts,
    "is_correct" => $isCorrect
]);
