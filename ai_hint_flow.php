<?php
session_start();
require 'db.php';
require 'openai_service.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents("php://input"), true);
$mistakes = $input['mistakes'] ?? [];

if (empty($mistakes)) {
    echo json_encode(["hint" => "目前沒有可分析的流程錯誤。"]);
    exit;
}

// 將錯誤轉為「教學描述」
$analysis = "";
foreach ($mistakes as $m) {

    if ($m['problem_type'] === 'too_early') {
        $analysis .=
            "步驟「{$m['step_text']}」出現在第 {$m['user_position']} 步，"
          . "但通常需要在取得前置資料後再執行。\n";
    } else {
        $analysis .=
            "步驟「{$m['step_text']}」目前放在較後面，"
          . "但它的結果會影響後續流程。\n";
    }
}

// AI Prompt（這裡是靈魂）
$prompt = <<<PROMPT
學生正在學習「流程排序」。

以下是學生目前流程中出現的邏輯問題：
{$analysis}

請你：
1. 說明這些步驟在流程中的角色（例如：輸入、處理、輸出）
2. 解釋為什麼目前位置不合理
3. 提供調整方向的提示
4. 不可給出正確順序
5. 不可列出完整流程

請用教學引導語氣回答。
PROMPT;

$hint = openai_call([
    ['role' => 'system', 'content' => '你是程式設計與流程教學專家'],
    ['role' => 'user', 'content' => $prompt]
]);

echo json_encode([
    "hint" => $hint ?: "⚠️ AI 暫時無法產生提示，請稍後再試。"
]);
