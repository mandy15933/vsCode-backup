<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(20);

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

$studentCode = trim((string)($data['student_code'] ?? ''));
$testCases = $data['test_cases'] ?? [];

if ($studentCode === '' || !is_array($testCases) || count($testCases) === 0) {
    echo json_encode([
        'success' => false,
        'error' => '輸入格式錯誤'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_output(string $text): string {
    return str_replace("\r\n", "\n", trim($text));
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'judge_' . uniqid();
if (!mkdir($baseDir, 0700, true)) {
    echo json_encode([
        'success' => false,
        'error' => '無法建立暫存目錄'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$codeFile = $baseDir . DIRECTORY_SEPARATOR . 'main.py';
file_put_contents($codeFile, $studentCode);

$results = [];
$allPassed = true;
$hasRuntimeError = false;

foreach ($testCases as $i => $case) {
    $input = (string)($case['input'] ?? '');
    $expected = normalize_output((string)($case['expected'] ?? ''));

    $inputFile = $baseDir . DIRECTORY_SEPARATOR . "input_{$i}.txt";
    file_put_contents($inputFile, $input);

    // 重點：在 Docker 容器中執行，不在 PHP 主機直接跑
    $cmd = sprintf(
        'docker run --rm ' .
        '--network none ' .
        '--cpus="0.5" ' .
        '--memory="128m" ' .
        '--pids-limit=64 ' .
        '--read-only ' .
        '--tmpfs /tmp:size=16m ' .
        '-v %s:/workspace:ro ' .
        '-v %s:/input:ro ' .
        '-w /workspace ' .
        '--user 1000:1000 ' .
        'python:3.11-alpine ' .
        'sh -c "timeout 3s python3 main.py < /input/%s"',
        escapeshellarg($baseDir),
        escapeshellarg($baseDir),
        basename($inputFile)
    );

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    $actual = normalize_output(implode("\n", $output));

    $passed = false;
    $errorType = null;

    if ($exitCode === 124) {
        $passed = false;
        $errorType = 'timeout';
        $allPassed = false;
        $hasRuntimeError = true;
    } elseif ($exitCode !== 0) {
        $passed = false;
        $errorType = 'runtime_error';
        $allPassed = false;
        $hasRuntimeError = true;
    } else {
        $passed = ($actual === $expected);
        if (!$passed) {
            $errorType = 'wrong_answer';
            $allPassed = false;
        }
    }

    $results[] = [
        'case_no' => $i + 1,
        'input' => $input,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
        'error_type' => $errorType,
        'exit_code' => $exitCode
    ];
}

@unlink($codeFile);
foreach (glob($baseDir . DIRECTORY_SEPARATOR . '*') as $f) {
    @unlink($f);
}
@rmdir($baseDir);

echo json_encode([
    'success' => true,
    'all_passed' => $allPassed,
    'has_runtime_error' => $hasRuntimeError,
    'summary' => [
        'total_cases' => count($results),
        'passed_cases' => count(array_filter($results, fn($r) => $r['passed'])),
        'failed_cases' => count(array_filter($results, fn($r) => !$r['passed']))
    ],
    'results' => $results
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);