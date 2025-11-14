<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';

$code = $_POST['code_lines'] ?? '';

if (trim($code) === '') {
    echo json_encode(['error' => '❌ 缺少 Python 程式碼']);
    exit;
}
// --- 2️⃣ 拆解程式碼行 ---
$code_arr = preg_split('/\r\n|\r|\n/', trim($code));
$max_line = count($code_arr);

$prompt = <<<EOT
你是一位 Python 教學助教。
請依照下方 Python 程式碼，逐行生成對應的流程圖 JSON（flowchart.js 格式）。

規範如下：
1. 節點類型僅能使用：start、end、io、operation、decision。
2. 每個節點需有：
   - id（字串）
   - type（節點類型）
   - text（繁體中文說明）
   - line（對應原始 Python 程式的行號，從 1 開始）
3. 若節點無對應（如開始、結束），line 設為 null。
4. 若有 if / for / while 結構，需展開 decision 節點。
5. 若有 print()，應有對應的輸出節點。
6. decision 節點的分支需標記 label "yes"/"no"。
7. 所有節點文字請用繁體中文。
8. 最終輸出格式如下：
{
  "flowchart": {
    "nodes": [
      {"id":"1","type":"start","text":"開始","line":null},
      {"id":"2","type":"io","text":"輸入 n","line":1},
      {"id":"3","type":"decision","text":"n 是否為偶數？","line":2},
      {"id":"4","type":"operation","text":"輸出偶數","line":3},
      {"id":"5","type":"operation","text":"輸出奇數","line":4},
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

Python 程式碼：
```python{$code}
請直接輸出流程圖JSON，不要加入解釋、文字或 Markdown。
EOT;

$response = chat_with_openai($prompt);

if (!isset($response['choices'][0]['message']['content'])) {
echo json_encode(['error' => '❌ AI 回傳異常', 'raw' => $response]);
exit;
}

$reply = trim($response['choices'][0]['message']['content']);
$clean = preg_replace('/(?:json)?/i', '', $reply); $clean = preg_replace('//', '', $clean);
$clean = trim($clean);

$json = json_decode($clean, true);
if (!$json || !isset($json['flowchart'])) {
echo json_encode(['error' => '⚠️ JSON 格式錯誤', 'raw' => $reply]);
exit;
}

echo json_encode($json['flowchart'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>