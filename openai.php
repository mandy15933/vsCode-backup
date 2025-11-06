<?php
/**
 * =====================================================
 * 🔹 openai.php
 * 功能：提供 chat_with_openai() 給其他模組呼叫
 * =====================================================
 */

function chat_with_openai(string $prompt, string $model = 'gpt-4o-mini', float $temperature = 0.7): array
{
    // === 1️⃣ 嘗試載入 .env 檔案 ===
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if ($key && $value) putenv(trim($key) . '=' . trim($value));
        }
    }

    // === 2️⃣ 從環境變數中取得 API 金鑰 ===
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey || stripos($apiKey, 'sk-') !== 0) {
        return ['error' => '❌ 找不到有效的 OPENAI_API_KEY，請檢查 .env 檔案。'];
    }

    // === 3️⃣ 準備 API 請求資料 ===
    $postData = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => '你是一位耐心的 Python 教學助理，擅長提供兩步驟提示。'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => $temperature
    ];

    // === 4️⃣ 發送 cURL 請求 ===
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 40
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => '❌ cURL 連線失敗：' . $error];
    }

    if ($status !== 200) {
        return ['error' => "❌ HTTP $status 錯誤：$response"];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => '❌ JSON 解析失敗：' . json_last_error_msg()];
    }

    return $data;
}
