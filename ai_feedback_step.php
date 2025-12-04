<?php
session_start();

require 'openai.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode([
        'step1' => '⚠️ 無法讀取前端資料，請重新再試一次。',
        'step2' => '請確認 fetch 已使用 JSON.stringify(...)。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$questionTitle = $input['question_title'] ?? '';
$questionDesc  = $input['question_desc'] ?? '';
$studentCode   = $input['student_code'] ?? '';
$correctCode   = $input['correct_code'] ?? '';

$prompt = <<<PROMPT
你是一位專業的 Python 程式教學助教，負責協助學生完成「拖曳排序＋縮排練習」。  
你的目標是：根據學生與正確程式碼的差異，給出精準、聚焦、易懂的兩段式提示。

請比較：
1. 【正確程式碼】
2. 【學生目前的程式碼】

並根據實際差異，自動判斷錯誤類型。（不要硬套每一條）

【可能的錯誤類型】（自動比對後才選擇）
- 程式碼順序錯置，導致邏輯流程被打斷
- 應屬於同一區塊的語句被拆開
- 控制結構（if / while / for）縮排層級不正確
- 條件或迴圈的子區塊放錯層級，邏輯斷裂
- 輸入 → 處理 → 輸出 的流程顛倒或穿插錯誤
- 同組資料的輸出或作業沒有被放在一起
- 計算流程的先後順序混淆
- 輸出格式規則（對齊方向、欄位位置、組合順序）被破壞

（⚠ 你應根據實際差異挑出真正的錯誤，而不是全部逐條提及。）

【回覆格式】

✔ 若學生完全正確：
Step 1：簡短肯定（例如：順序與縮排完全正確，符合題意）。  
Step 2：鼓勵語（例如：已掌握重點，可以進入下一題）。

✔ 若學生有錯誤：
Step 1：  
指出真正的錯誤「類型」，用自然、具體且可理解的語氣，不抽象、不重複題目敘述。  
（例：某組資料的輸出被拆開、處理流程順序顛倒、縮排層級比正確答案多一層…）

Step 2：  
給方向性的修正建議，但不能爆雷。  
（例：應將同一組輸出保持在一起、讓主要流程依題目順序排列、保持子區塊一致縮排…）

【禁止事項】
- 禁止提供行號
- 禁止給完整程式碼
- 禁止說明「哪一行應移到哪裡」
- 禁止超過每段 60 字
- 語氣不可公式化，要像真人老師

請依上述規則，以繁體中文生成 Step 1 與 Step 2。

----------------------------------------

【題目標題】
{$questionTitle}

【題目說明】
{$questionDesc}

【正確程式碼】
{$correctCode}

【學生程式碼】
{$studentCode}


PROMPT;




$responseText = chat_with_openai($prompt, "Python 助教", "gpt-4o-mini", 0.7);

// ----------- 解析 Step1 / Step2 ------------
$step1 = "⚠️ 未找到 Step 1 回覆。";
$step2 = "⚠️ 未找到 Step 2 回覆。";

if ($responseText && is_string($responseText)) {
    $parts = preg_split('/Step\s*2[:：]/u', $responseText);

    if (count($parts) == 2) {
        // 前半段（含 Step 1）
        $step1_full = trim($parts[0]);

        // 去掉 "Step 1：" 文字
        $step1 = preg_replace('/Step\s*1[:：]/u', '', $step1_full);
        $step1 = trim($step1);

        // Step 2 內容
        $step2 = trim($parts[1]);
    }
}

echo json_encode([
    'step1' => $step1,
    'step2' => $step2
], JSON_UNESCAPED_UNICODE);

exit;
