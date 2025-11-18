<?php
session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

// 讀取 JSON
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["success" => false, "message" => "無效的請求"]);
    exit;
}

$userId = $_SESSION['user_id'] ?? 1;

$questionId   = (int)($input['question_id'] ?? 0);
$isCorrect    = (int)($input['is_correct'] ?? 0);
$timeSpent    = (int)($input['time_spent'] ?? 0);
$code         = $input['code'] ?? '';
$aiComment    = $input['ai_comment'] ?? null;
$testGroupId  = isset($input['test_group_id']) ? (int)$input['test_group_id'] : null;
$answerMode   = $testGroupId ? 'exam' : 'practice';
$now          = date('Y-m-d H:i:s');

if (!$questionId) {
    echo json_encode(["success" => false, "message" => "缺少題目 ID"]);
    exit;
}

// 查題目章節
$stmt = $conn->prepare("SELECT chapter FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(["success" => false, "message" => "找不到題目"]);
    exit;
}
$chapterId = (int)$row['chapter'];

// 查是否已有紀錄
$stmt = $conn->prepare("
    SELECT id, attempts, is_correct, first_correct_time
    FROM student_answers
    WHERE user_id=? AND question_id=?
");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================================================================
// 1️⃣ 沒舊紀錄 → INSERT
// ==================================================================
if (!$existing) {

    $passStatus = $isCorrect ? '通過' : '未通過';
    $firstCorrectTime = $isCorrect ? $now : null;

    $stmt = $conn->prepare("
        INSERT INTO student_answers
            (user_id, question_id, chapter_id, is_correct, attempts, time_spent,
             mindmap_clicks, flowchart_clicks, aiHint_clicks,
             viewed_types, test_group_id, answer_mode, pass_status,
             first_correct_time, answered_at)
        VALUES (?, ?, ?, ?, 1, ?, 0, 0, 0, '[]', ?, ?, ?, ?, NOW())
    ");

    // 9 個參數 → 完整正確版
    $stmt->bind_param(
        "iiiiissss",
        $userId,
        $questionId,
        $chapterId,
        $isCorrect,
        $timeSpent,
        $testGroupId,
        $answerMode,
        $passStatus,
        $firstCorrectTime
    );

    $stmt->execute();
    $studentAnswerId = $stmt->insert_id;
    $stmt->close();

    $attempts = 1;
}

// ==================================================================
// 2️⃣ 有舊紀錄 → UPDATE
// ==================================================================
else {

    $attempts = $existing['attempts'] + 1;
    $isCorrectFinal = ($existing['is_correct'] == 1) ? 1 : $isCorrect;

    if ($existing['is_correct'] == 1) {
        $firstCorrectTime = $existing['first_correct_time'];
    } elseif ($isCorrectFinal == 1) {
        $firstCorrectTime = $now;
    } else {
        $firstCorrectTime = null;
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

    // 9 個參數 → 正確
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

// ==================================================================
// 3️⃣ 儲存程式碼歷史
// ==================================================================
$stmt = $conn->prepare("
    INSERT INTO student_code_history (student_answer_id, code, ai_comment)
    VALUES (?, ?, ?)
");
$stmt->bind_param("iss", $studentAnswerId, $code, $aiComment);
$stmt->execute();
$stmt->close();

echo json_encode([
    "success" => true,
    "message" => "作答結果已儲存",
    "student_answer_id" => $studentAnswerId,
    "attempts" => $attempts,
    "is_correct" => $isCorrect
]);
