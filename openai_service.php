<?php

// =======================================
// 0️⃣ 載入 API Key（沿用你現有 .env）
// =======================================
if (!function_exists('load_env')) {
    function load_env($path = __DIR__ . '/.env') {
        if (!file_exists($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v = trim($v, "\"'");
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
}
load_env();



// =======================================
// 1️⃣ 底層：通用 OpenAI 呼叫器（唯一）
// =======================================
function openai_call(array $messages, array $options = []): ?string {

    $apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
    if (!$apiKey) return null;

    $payload = array_merge([
        'model'       => 'gpt-4o-mini',
        'temperature' => 0.7,
        'messages'    => $messages,
    ], $options);

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}"
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;

    if (curl_errno($ch) || $httpCode !== 200) {
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

// =======================================
// 2️⃣ 工具：安全 JSON 解析
// =======================================
function parse_json_safely(string $raw): ?array {
    // 嘗試直接 decode
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;

    // 嘗試擷取第一個 { ... }
    if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
        $json = json_decode($matches[0], true);
        if (is_array($json)) return $json;
    }

    return null;
}


// =======================================
// 3️⃣ 用途 ①：題目生成（強制 JSON）
// =======================================
function ai_generate_question(string $prompt): ?array {

    $raw = openai_call(
        [
            ['role' => 'system', 'content' =>
                '你是 Python 教學專家，請嚴格只輸出 JSON，不得加入任何說明文字。'
            ],
            ['role' => 'user', 'content' => $prompt]
        ],
        [
            'temperature'     => 0.3,
            'max_tokens'      => 600,
            'response_format' => ['type' => 'json_object']
        ]
    );
    return $raw ? parse_json_safely($raw) : null;
}

// =======================================
// 4️⃣ 用途 ②：白話流程步驟（flow_steps_json）
// =======================================
function ai_generate_flow_steps(string $prompt): ?array {

    $raw = openai_call(
        [
            ['role' => 'system', 'content' =>
                '你是教學流程設計專家，請輸出 JSON，需包含 steps 陣列。'
            ],
            ['role' => 'user', 'content' => $prompt]
        ],
        [
            'temperature'     => 0.4,
            'max_tokens'      => 400,
            'response_format' => ['type' => 'json_object']
        ]
    );

    $json = $raw ? parse_json_safely($raw) : null;
    return $json['steps'] ?? null;
}

// =======================================
// 5️⃣ 用途 ③：心智圖 / 流程圖（半結構）
// =======================================
function ai_generate_visual(string $prompt): ?array {

    $raw = openai_call(
        [
            ['role' => 'system', 'content' =>
                '你是視覺化教學設計專家，請輸出 JSON 結構，不要加入說明。'
            ],
            ['role' => 'user', 'content' => $prompt]
        ],
        [
            'temperature' => 0.6,
            'max_tokens'  => 800
        ]
    );

    return $raw ? parse_json_safely($raw) : null;
}
