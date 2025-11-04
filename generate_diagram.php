<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';  // ✅ 使用共用的 OpenAI 函式

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
$prompt = <<<EOT
你是一位 Python 教學助教，請根據以下題目描述與測資，生成兩個結構化 JSON：
1️⃣ 心智圖（Mindmap）
2️⃣ 流程圖（Flowchart）

### 題目描述：
{$desc}

### 測資範例：
EOT;

foreach ($test_cases as $tc) {
    $prompt .= "\n🟢 Input: " . trim($tc['input']) . "\n🔵 Output: " . trim($tc['output']);
}

$prompt .= <<<EOT

---

請輸出以下格式的 JSON（不要額外解釋）：

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

// --- 4️⃣ 呼叫共用的 OpenAI 函式 ---
$response = chat_with_openai($prompt, 'gpt-4o-mini', 0.6);

// --- 5️⃣ 擷取 JSON 區塊並修正 ---
$reply = trim($response['choices'][0]['message']['content'] ?? '');

if (!$reply) {
    echo json_encode(['error' => '⚠️ 沒有取得 AI 回覆內容']);
    exit;
}

// 移除反引號與語法提示
$clean = preg_replace('/```(json)?/i', '', $reply);
$clean = trim($clean);

// 嘗試偵測多個 JSON 區塊
preg_match_all('/\{(?:[^{}]|(?R))*\}/m', $clean, $matches);

if (!$matches || count($matches[0]) === 0) {
    echo json_encode(['error' => '⚠️ 找不到任何 JSON 區塊', 'raw' => $reply]);
    exit;
}

// --- 6️⃣ 分別嘗試解析多個 JSON ---
$mindmap = null;
$flowchart = null;

foreach ($matches[0] as $json_str) {
    $parsed = json_decode($json_str, true);
    if (json_last_error() !== JSON_ERROR_NONE) continue;

    if (isset($parsed['meta']) && isset($parsed['format'])) {
        // ✅ 偵測為 jsMind 結構
        $mindmap = $parsed;
    } elseif (isset($parsed['flowchart'])) {
        // ✅ 偵測為 flowchart 結構
        $flowchart = $parsed['flowchart'];
    }
}

// --- 7️⃣ 檢查結果 ---
if (!$mindmap && !$flowchart) {
    echo json_encode(['error' => 'AI 回傳 JSON 解析失敗', 'raw' => $reply]);
    exit;
}

echo json_encode([
    'mindmap' => $mindmap,
    'flowchart' => $flowchart
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
// 清除多餘符號
$json_str = preg_replace('/```json|```|\\r|\\n/', '', $json_str);

// 嘗試解析 JSON
$output = json_decode($json_str, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'error' => 'AI 回傳 JSON 解析失敗: ' . json_last_error_msg(),
        'raw' => $reply
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
