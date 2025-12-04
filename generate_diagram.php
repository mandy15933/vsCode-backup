<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';

// 關閉 PHP 錯誤輸出（防止污染 JSON）
ini_set('display_errors', 0);
error_reporting(0);

// --- 1️⃣ 驗證輸入 ---
$desc = $_POST['description'] ?? '';
$test_cases_raw = $_POST['test_cases'] ?? '';
$code_lines = $_POST['code_lines'] ?? '';

if (trim($desc) === '' || trim($test_cases_raw) === '') {
    echo json_encode(['error' => '❌ 缺少題目描述或測資']);
    exit;
}

// --- 2️⃣ 嘗試解析測資 ---
$test_cases = json_decode($test_cases_raw, true);
if (!is_array($test_cases)) {
    $test_cases = [['input' => $test_cases_raw, 'output' => '']];
}



// === 3️⃣ 組 Prompt ===
$prompt = <<<EOT
你是一位 Python 教學助教，請根據以下題目資訊生成「心智圖」與「流程圖」兩者，並一律輸出為 **單一 JSON 物件**：

{
  "mindmap": {...},
  "flowchart": {...}
}

---

### 📘 第一部分：心智圖 (Mindmap)
請根據「題目描述」與「測資」，生成 jsMind 的 node_tree 結構：
- meta 與 format 為固定格式。
- 根節點 topic 為「題目理解」。
- children 包含：
  - 已知條件
  - 需求目標
  - 名詞解釋（列出題目中出現的特殊數學或程式名詞）
- 所有文字使用繁體中文。
- 範例：
{
  "meta": {"name":"Mindmap","author":"AI","version":"1.0"},
  "format": "node_tree",
  "data": {
    "id":"root","topic":"題目理解",
    "children":[
      {"id":"cond","topic":"已知條件","children":[]},
      {"id":"goal","topic":"需求目標","children":[]},
      {"id":"explain","topic":"名詞解釋","children":[]}
    ]
  }
}

---

### 🔄 第二部分：流程圖 (Flowchart)
請「只根據下方 Python 程式碼」逐行生成 flowchart.js 專用的 JSON：
- 不可推理題目邏輯，只依實際程式結構。
- 每個節點皆需包含：
  - id（字串）
  - type（start, end, io, operation, decision）
  - text（繁體中文）
  - line（對應程式行號，若無則 null）
- 若有 if / elif / else / for / while，均需展開成 decision 節點。
- 每個 print() 都要有對應輸出節點。
- decision 節點需標記 label "yes" / "no"。
- 所有節點與連線明確列出。
- 結尾需有 end 節點。
- 結構範例如下：
{
  "flowchart": {
    "nodes": [
      {"id":"1","type":"start","text":"開始","line":null},
      {"id":"2","type":"io","text":"輸入數字 n","line":1},
      {"id":"3","type":"decision","text":"n 是否大於 0？","line":2},
      {"id":"4","type":"operation","text":"輸出正數","line":3},
      {"id":"5","type":"operation","text":"輸出非正數","line":4},
      {"id":"6","type":"end","text":"結束","line":null}
    ],
    "edges":[
      {"from":"1","to":"2"},
      {"from":"2","to":"3"},
      {"from":"3","to":"4","label":"yes"},
      {"from":"3","to":"5","label":"no"},
      {"from":"4","to":"6"},
      {"from":"5","to":"6"}
    ]
  }
}

---

### 題目描述：
{$desc}

### 測資範例：
EOT;

foreach ($test_cases as $tc) {
    $prompt .= "\n🟢 Input:\n" . trim($tc['input']) .
               "\n🔵 Output:\n" . trim($tc['output']) . "\n";
}

$prompt .= <<<EOT

--- Python 程式 ---
```python
{$code_lines}
請直接輸出 JSON，不要加入任何 Markdown、文字說明、三個反引號。
必須符合：
{
"mindmap": {...},
"flowchart": {...}
}
EOT;

// --- 4️⃣ 呼叫 OpenAI（新版：回傳字串） ---
$reply = chat_with_openai($prompt, "python 視覺化助教");

// --- 5️⃣ 清理 Markdown ---
$clean = $reply;
$clean = preg_replace('/json/i', '', $clean); $clean = preg_replace('//i', '', $clean);
$clean = trim($clean);

// --- 6️⃣ 解析 JSON ---
$json = json_decode($clean, true);

if (!$json || !isset($json['mindmap']) || !isset($json['flowchart'])) {
echo json_encode([
'error' => '⚠️ AI 回傳格式錯誤，找不到 mindmap 或 flowchart',
'raw' => $reply
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
}

// --- 7️⃣ 回傳成功結果 ---
echo json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>

