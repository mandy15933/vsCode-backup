<?php
session_start();
require 'openai.php';

header('Content-Type: application/json; charset=utf-8');

$code_lines = json_decode($_POST['code_lines'] ?? '[]', true);

if (!is_array($code_lines) || count($code_lines) === 0) {
    echo json_encode(['error' => '缺少程式碼']);
    exit;
}

// 將程式碼轉成單一字串，只給 AI 理解邏輯用
$codeText = implode("\n", $code_lines);

// ===== 🧠 AI Prompt（嚴格對齊程式碼行數）=====
$prompt = <<<PROMPT
你是一位協助學生「理解程式邏輯」的教學專家。

請根據下面提供的「標準解答程式碼」，將每一個
「實際會影響執行流程的程式邏輯行或判斷區塊」
轉換成一行【白話、生活化】的流程描述。

【非常重要的轉換規則（必須遵守）】
- 轉換後的「白話流程句子數量」，必須與程式碼中的邏輯行／判斷區塊數量完全一致
- 不可以新增、合併或拆分流程步驟
- 不要補「開始」「結束」等程式中不存在的行為
- 不要加入任何額外說明或總結

【語言限制】
- 不要出現任何程式語法（如 if、elif、else、print）
- 不要提到「程式碼」「變數」「函式」「縮排」
- 請使用一般人日常會說的話來描述

【輸出格式（請只輸出合法 JSON）】
請輸出「純字串陣列」，每一個元素對應一行程式邏輯。

範例格式：
[
  "第一行程式邏輯的白話描述",
  "第二行程式邏輯的白話描述"
]

【標準解答程式碼】
$codeText
PROMPT;

// 呼叫 OpenAI
$result = chat_with_openai(
    prompt: $prompt,
    systemRole: "Python 教學專家",
    model: "gpt-4o-mini",
    temperature: 0.2
);

// 嘗試解析 JSON
$json = json_decode($result, true);

if (!is_array($json)) {
    echo json_encode([
        'error' => 'AI 回傳格式錯誤',
        'raw'   => $result
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 成功回傳
echo json_encode([
    'flow_steps' => $json
], JSON_UNESCAPED_UNICODE);
