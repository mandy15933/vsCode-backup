<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(15);
session_start();

// 你自己的登入保護
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => '未登入'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

$studentCode = trim((string)($data['student_code'] ?? ''));
$testCases = $data['test_cases'] ?? [];

if ($studentCode === '') {
    echo json_encode([
        'success' => false,
        'error' => '未提供 student_code'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($testCases) || count($testCases) === 0) {
    echo json_encode([
        'success' => false,
        'error' => '未提供 test_cases'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 基本長度限制
if (mb_strlen($studentCode) > 10000) {
    echo json_encode([
        'success' => false,
        'error' => '程式碼過長'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 可再加速率限制、IP 限制、使用者配額等

$payload = [
    'student_code' => $studentCode,
    'test_cases' => $testCases,
];

// 僅呼叫內網 sandbox service，不直接執行 Python
$ch = curl_init('http://127.0.0.1:8081/run-python');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    echo json_encode([
        'success' => false,
        'error' => '判題服務暫時不可用',
        'detail' => $curlErr ?: ("HTTP " . $httpCode)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $response;