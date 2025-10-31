<?php
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/openai.php';

// 🧠 建立 log 檔案方便除錯
$logFile = __DIR__ . '/ai_feedback_log.txt';
file_put_contents($logFile, date('H:i:s') . " 🚀 AI Feedback Step Start\n", FILE_APPEND);

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $questionTitle = $data['question_title'] ?? '';
    $questionDesc  = $data['question_desc'] ?? '';
    $studentCode   = $data['student_code'] ?? '';
    $correctCode   = $data['correct_code'] ?? '';
    $avgAttempts   = $data['avg_attempts'] ?? 2.0;

    if (empty($studentCode) || empty($correctCode)) {
        echo json_encode([
            'step1' => '⚠️ 無法取得程式內容，請重新整理頁面。',
            'step2' => ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 根據嘗試次數決定語氣
    if ($avgAttempts <= 1.2) {
        $stylePrompt = "請以啟發式提問為主，幫助學生自行發現邏輯錯誤。";
    } elseif ($avgAttempts <= 2.0) {
        $stylePrompt = "請使用兩步驟提示法：第一步指出錯在哪個區塊，第二步給修正方向但保留學生思考空間。";
    } else {
        $stylePrompt = "請直接指出錯誤行與修改建議，但不要給完整答案。";
    }

    $prompt = <<<EOD
你是一位友善的 Python 教學助理，根據學生程度提供分層回饋。

題目標題：{$questionTitle}
題目說明：{$questionDesc}

學生的程式：
{$studentCode}

正確的程式：
{$correctCode}

{$stylePrompt}

請用繁體中文回答，格式如下：
---
第一步：
（提示性問題或方向）
---
第二步：
（修正方向或具體建議）
---
EOD;

    $response = chat_with_openai($prompt);
    if (isset($response['error'])) {
        throw new Exception($response['error']);
    }

    $reply = $response['choices'][0]['message']['content'] ?? '';
    file_put_contents($logFile, "✅ OpenAI 回覆內容:\n$reply\n\n", FILE_APPEND);

    // 正規表示式提取「第一步」「第二步」
    preg_match('/第一步[:：]\s*(.*?)---/s', $reply, $m1);
    preg_match('/第二步[:：]\s*(.*)$/s', $reply, $m2);

    $step1 = trim($m1[1] ?? '');
    $step2 = trim($m2[1] ?? '');

    if (!$step1 && !$step2) {
        // fallback：AI 回覆格式錯誤
        file_put_contents($logFile, "⚠️ AI 回覆無法解析，啟用 fallback。\n", FILE_APPEND);
        $step1 = "⚠️ AI 回覆格式無法辨識，以下是原始內容：";
        $step2 = $reply ?: "（無回應）";
    }

    echo json_encode([
        'step1' => $step1,
        'step2' => $step2
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    file_put_contents($logFile, "💥 錯誤：" . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        'step1' => '💥 系統錯誤（AI 無法回應）',
        'step2' => '伺服器錯誤：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
