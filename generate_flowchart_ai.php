<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';

// ==============================
// 1. 讀取輸入
// ==============================
$rawInput = file_get_contents("php://input");
$jsonInput = json_decode($rawInput, true);

$problemText = $jsonInput['problem_text'] ?? $_POST['problem_text'] ?? '';
$sourceCode  = $jsonInput['source_code']  ?? $_POST['source_code']  ?? '';
$language    = $jsonInput['language']     ?? $_POST['language']     ?? 'python';

$problemText = trim($problemText);
$sourceCode  = trim($sourceCode);

// ==============================
// 2. 驗證
// ==============================
if ($problemText === '' && $sourceCode === '') {
    echo json_encode([
        'success' => false,
        'error' => '❌ 請至少輸入題目描述或程式碼'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==============================
// 3. 組合輸入
// ==============================
$context = "";

if ($problemText !== '') {
    $context .= "題目描述：\n{$problemText}\n\n";
}

if ($sourceCode !== '') {
    $context .= "程式碼：\n```{$language}\n{$sourceCode}\n```";
}

// ==============================
// 4. Prompt（🔥重點升級）
// ==============================
$prompt = <<<EOT
你是一位「程式流程圖分析助教」。

請完成兩件事：

=====================
【任務一：產生流程圖】
=====================
根據題目或程式碼，產生 flowchart JSON。

=====================
【任務二：檢查程式錯誤】
=====================
分析程式邏輯是否有錯，並指出：

- 錯在哪一行
- 對應流程圖節點
- 錯誤原因
- 修正建議

=====================
【使用者輸入】
=====================
{$context}

=====================
【輸出格式（嚴格遵守）】
=====================

{
  "flowchart": {
    "nodes": [
      {"id":"1","type":"start","text":"開始","line":null}
    ],
    "edges": [
      {"from":"1","to":"2"}
    ]
  },
  "feedback": {
    "has_error": true,
    "error_nodes": [
      {
        "node_id": "3",
        "line": 4,
        "type": "logic_error",
        "message": "說明錯誤原因（繁體中文）",
        "suggestion": "修正建議（繁體中文）"
      }
    ]
  }
}

=====================
【規則】
=====================
1. nodes type 只能用：
   start, end, io, operation, decision
2. decision 分支 label 必須 yes / no
3. 所有文字使用「繁體中文」
4. 若無錯誤：
   "has_error": false
   "error_nodes": []
5. 不要輸出任何 JSON 以外內容
EOT;

// ==============================
// 5. 呼叫 AI
// ==============================
$reply = chat_with_openai($prompt, "流程圖生成專家");

// ==============================
// 6. 清理 Markdown
// ==============================
$clean = trim($reply);
$clean = preg_replace('/```json/i', '', $clean);
$clean = preg_replace('/```/', '', $clean);
$clean = trim($clean);

// 擷取 JSON
$start = strpos($clean, '{');
$end = strrpos($clean, '}');

if ($start !== false && $end !== false) {
    $clean = substr($clean, $start, $end - $start + 1);
}

// ==============================
// 7. 解析 JSON
// ==============================
$data = json_decode($clean, true);

if (!$data || !isset($data['flowchart'])) {
    echo json_encode([
        'success' => false,
        'error' => '⚠️ JSON 格式錯誤',
        'raw' => $reply
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==============================
// 8. 回傳
// ==============================
echo json_encode([
    'success' => true,
    'flowchart' => $data['flowchart'],
    'feedback' => $data['feedback'] ?? [
        'has_error' => false,
        'error_nodes' => []
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>