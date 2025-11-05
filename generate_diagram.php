<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';

// 關閉錯誤輸出（防止 HTML 干擾 JSON）
ini_set('display_errors', 0);
error_reporting(0);

// --- 1️⃣ 驗證輸入 ---
$desc = $_POST['description'] ?? '';
$test_cases_raw = $_POST['test_cases'] ?? '';

if (trim($desc) === '' || trim($test_cases_raw) === '') {
    echo json_encode(['error' => '❌ 缺少題目描述或測資']);
    exit;
}

// --- 2️⃣ 嘗試解析測資 ---
$test_cases = json_decode($test_cases_raw, true);
if (!is_array($test_cases)) {
    $test_cases = [['input' => $test_cases_raw, 'output' => '']];
}

// --- 3️⃣ 組成 prompt ---
$prompt = "你是一位 Python 教學助教，請根據以下題目描述與測資，生成兩個結構化 JSON：\n";
$prompt .= "1️⃣ 心智圖（Mindmap）\n2️⃣ 流程圖（Flowchart）\n\n";
$prompt .= "### 題目描述：\n{$desc}\n\n### 測資範例：\n";

foreach ($test_cases as $tc) {
    $prompt .= "🟢 Input: " . trim($tc['input']) . "\n";
    $prompt .= "🔵 Output: " . trim($tc['output']) . "\n";
}

$prompt .= <<<EOT


---

請你「只輸出 JSON」，不要有任何解釋。
分別輸出兩段：

1.心智圖 (JSON 格式)
   - 必須使用 jsMind 的 node_tree 格式：
     {
       "meta": {"name": "Mindmap","author": "AI","version": "1.0"},
       "format": "node_tree",
       "data": {
         "id": "root", "topic": "題目理解",
         "children": [
           {"id":"cond","topic":"已知條件","children":[...]},
           {"id":"goal","topic":"需求目標","children":[...]},
           {"id":"explain","topic":"名詞解釋","children":[
             {"id":"def1","topic":"特殊名詞定義"},
             {"id":"def2","topic":"範例或補充"}
           ]}
         ]
       }
     }
   - 名詞解釋必須包含題目中出現的特殊數學或程式名詞，例如：
     - 「完美數」的定義
     - 「真因數」的定義
     - 範例數字（如 6、28）
2.流程圖 (JSON 格式)     
   - 必須輸出一個物件，格式固定如下：
     "flowchart": {
       "nodes": [
         { "id": "1", "type": "start", "text": "開始" },
         { "id": "2", "type": "io", "text": "讀取輸入 n" },
         { "id": "3", "type": "operation", "text": "初始化質數計數器" },
         { "id": "4", "type": "decision", "text": "i <= n ?" },
         { "id": "5", "type": "operation", "text": "檢查 i 是否為質數" },
         { "id": "6", "type": "operation", "text": "若質數，計數器 +1" },
         { "id": "7", "type": "operation", "text": "i = i + 1" },
         { "id": "8", "type": "operation", "text": "輸出質數計數器" },
         { "id": "9", "type": "end", "text": "結束" }
       ],
       "edges": [
         { "from": "1", "to": "2" },
         { "from": "2", "to": "3" },
         { "from": "3", "to": "4" },
         { "from": "4", "to": "5", "label": "yes" },
         { "from": "4", "to": "8", "label": "no" },
         { "from": "5", "to": "6" },
         { "from": "6", "to": "7" },
         { "from": "7", "to": "4" },
         { "from": "8", "to": "9" }
       ]
     }

   - 使用 flowchart.js 定義。
   - 節點類型：start、end、io、operation、decision。
   - 若題目涉及「for 迴圈」，流程圖必須包含以下結構：
     1. 初始化節點（設定計數變數與初始值，例如 i=1）。
     2. Decision 節點（判斷計數變數是否 ≤ 終止值）。
        - Yes/是 → 進入迴圈主體。
        - No/否 → 進入「輸出結果」。
     3. 迴圈主體（處理動作）。
     4. Increment 節點（i = i + 1）。
     5. Increment 必須連回 Decision 節點。
   - **輸出結果必須是 operation 節點，不可以直接用 end 節點。**
   - 結束 (end) 節點必須單獨存在，並且由輸出結果節點指向。
EOT;

// --- 4️⃣ 呼叫 OpenAI ---
$response = chat_with_openai($prompt, 'gpt-4o-mini', 0.6);

if (!isset($response['choices'][0]['message']['content'])) {
    echo json_encode(['error' => '❌ OpenAI 回傳異常', 'raw' => $response]);
    exit;
}

// --- 5️⃣ 擷取文字內容 ---
$reply = trim($response['choices'][0]['message']['content'] ?? '');

// ✅ 移除所有 ```json ... ``` 區塊標籤
$clean = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/i', '$1', $reply);
$clean = trim($clean);

// --- 6️⃣ 嘗試解析多段 JSON ---
$mindmap = null;
$flowchart = null;

// 找出所有可能的 JSON 區塊（含巢狀）
// 用 "```json" 先切段，再逐段解析
$parts = preg_split('/```(?:json)?/i', $reply);

foreach ($parts as $part) {
    $part = trim(preg_replace('/```/', '', $part)); // 移除尾端反引號
    if (strlen($part) < 5) continue; // 太短略過

    $parsed = json_decode($part, true);
    if (!$parsed) continue;

    // 判斷是哪一種結構
    if (isset($parsed['meta']) && isset($parsed['format'])) {
        $mindmap = $parsed;
    }
    if (isset($parsed['flowchart']) && is_array($parsed['flowchart'])) {
        $flowchart = $parsed['flowchart'];
    }
}

// --- 7️⃣ 結果驗證 ---
if (!$mindmap && !$flowchart) {
    echo json_encode([
        'error' => '⚠️ AI 回傳格式錯誤，無法解析',
        'raw' => $reply
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// --- 8️⃣ 最終輸出 ---
echo json_encode([
    'mindmap' => $mindmap,
    'flowchart' => $flowchart
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

