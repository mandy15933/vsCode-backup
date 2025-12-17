<?php
// openai.php
// ★ 不需要 Composer，含自動 .env 解析、標準 OpenAI API 呼叫 ★

// =======================================
// 1️⃣ 內建 .env 解析（不需 composer）
// =======================================
function load_env($path = __DIR__ . '/.env') {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;

        list($key, $value) = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'"); // 去除引號

        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}
load_env();


// =======================================
// 2️⃣ 通用 ChatGPT 呼叫（回傳字串）
// =======================================
function chat_with_openai(
    string $prompt,
    string $systemRole = "python 教學專家",
    string $model = "gpt-4o-mini",
    float $temperature = 0.7
): string {

    $apiKey = $_ENV["OPENAI_API_KEY"] ?? null;

    if (!$apiKey) {
        return "⚠️ 尚未設定 OPENAI_API_KEY（請編輯 .env 檔）。";
    }

    $url = "https://api.openai.com/v1/chat/completions";

    // 組合 payload
    $payload = [
        "model" => $model,
        "temperature" => $temperature,
        "messages" => [
            ["role" => "system", "content" => $systemRole],
            ["role" => "user", "content" => $prompt]
        ]
    ];

    // CURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0; // ← 確保永遠有值

    // curl 錯誤（伺服器無回應）
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return "❌ CURL 錯誤：$error（伺服器無回應）";
    }

    curl_close($ch);

    // HTTP 不是 200 → API 錯誤
    if ($httpCode !== 200) {
        return "❌ API 呼叫失敗（HTTP $httpCode）\n原始回應：\n$response";
    }

    // JSON 解析
    $data = json_decode($response, true);

    // 沒內容
    if (!isset($data["choices"][0]["message"]["content"])) {
        return "⚠️ AI 沒有回傳內容。\n原始回應：\n$response";
    }

    return $data["choices"][0]["message"]["content"];
}

function chat_with_openai_hint(
    string $prompt,
    string $systemRole = "Python 初學者教學助教",
    string $model = "gpt-4o-mini"
): string {

    $apiKey = $_ENV["OPENAI_API_KEY"] ?? null;
    if (!$apiKey) {
        return "⚠️ 尚未設定 OPENAI_API_KEY";
    }

    $url = "https://api.openai.com/v1/chat/completions";

    // ⭐ 教學提示專用設定（省流量核心）
    $payload = [
        "model" => $model,
        "temperature" => 0.3,          // 教學穩定，不發揮
        "max_tokens" => 180,            // Step1 + Step2 絕對夠
        "response_format" => [
            "type" => "json_object"     // ⭐ 強制 JSON（關鍵）
        ],
        "messages" => [
            [
                "role" => "system",
                "content" => $systemRole
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return "❌ CURL 錯誤：$err";
    }
    curl_close($ch);

    if ($httpCode !== 200) {
        return "❌ OpenAI API 失敗（HTTP $httpCode）\n$response";
    }

    $data = json_decode($response, true);

    if (!isset($data["choices"][0]["message"]["content"])) {
        return "⚠️ AI 未回傳內容。\n$response";
    }

    return $data["choices"][0]["message"]["content"];
}