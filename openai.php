<?php
/**
 * =====================================================
 * 🔹 openai.php (cURL + .env 安全版)
 * 功能：提供 chat_with_openai() 給其他模組呼叫
 * 作者：ChatGPT 安全修正版
 * =====================================================
 */

function chat_with_openai(string $prompt, string $model = 'gpt-4o-mini', float $temperature = 0.7): array
{
    // === 1️⃣ 嘗試載入 .env 檔案 ===
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // 跳過註解或空行
            if (strpos(trim($line), '#') === 0 || trim($line) === '') continue;

            // 分割 key=value 組合
            $pair = explode('=', $line, 2);
            if (count($pair) === 2) {
                $key = trim($pair[0]);
                $value = trim($pair[1]);
                putenv("$key=$value");
            }
        }
    }

    // === 2️⃣ 從環境變數中取得 API 金鑰 ===
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey || stripos($apiKey, 'sk-') !== 0) {
        return ['error' => '❌ 找不到有效的 OPENAI_API_KEY，請檢查 .env 檔案是否正確。'];
    }

    // === 3️⃣ 準備 API 請求資料 ===
    $postData = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => '你是一位耐心的 Python 教學助理，擅長提供兩步驟提示。'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => $temperature,
    ];

    // === 4️⃣ 發送 cURL 請求 ===
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 40,
    ]);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // === 5️⃣ 錯誤處理 ===
    if ($response === false) {
        return ['error' => '❌ cURL 連線失敗：' . $curlError];
    }

    if ($statusCode !== 200) {
        return ['error' => "❌ HTTP $statusCode 錯誤：$response"];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => '❌ JSON 解析失敗：' . json_last_error_msg() . ' | 原始回應：' . $response];
    }

    // === 6️⃣ 成功回傳 ===
    return $data;
}
?>
