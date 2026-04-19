<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';

// ==============================
// 1. 讀取輸入
// ==============================
$rawInput = file_get_contents("php://input");
$jsonInput = json_decode($rawInput, true);

$problemText = $jsonInput['problem_text'] ?? $_POST['problem_text'] ?? '';
$answerCode  = $jsonInput['answer_code']  ?? $_POST['answer_code']  ?? '';
$studentCode = $jsonInput['student_code'] ?? $_POST['student_code'] ?? '';
// $sourceCode  = $jsonInput['source_code']  ?? $_POST['source_code']  ?? '';
$language    = $jsonInput['language']     ?? $_POST['language']     ?? 'python';
$mode        = $jsonInput['mode']         ?? $_POST['mode']         ?? 'student';

$problemText = trim((string)$problemText);
$answerCode  = trim((string)$answerCode);
$studentCode = trim((string)$studentCode);
$language    = trim((string)$language);
$mode        = trim((string)$mode);

// mode 防呆
if (!in_array($mode, ['answer', 'student'])) {
    $mode = 'student';
}



if ($mode === 'answer' && $answerCode === '') {
    echo json_encode([
        'success' => false,
        'error' => '❌ answer 模式需要提供 answer_code'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'student' && $studentCode === '') {
    echo json_encode([
        'success' => false,
        'error' => '❌ student 模式需要提供 student_code'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// ==============================
// 3. 組合輸入
// ==============================
$contextParts = [];

if ($mode === 'answer' && $answerCode !== '') {
    $contextParts[] = "程式碼（流程圖必須完全依照此程式碼產生）：\n```{$language}\n{$answerCode}\n```";
}

if ($mode === 'student' && $studentCode !== '') {
    $contextParts[] = "程式碼（流程圖必須完全依照此程式碼產生，不可自動修正）：\n```{$language}\n{$studentCode}\n```";
}

if ($problemText !== '') {
    $contextParts[] = "題目描述（僅供補充參考，不可覆蓋程式碼實際邏輯）：\n" . $problemText;
}

$context = implode("\n\n", $contextParts);

$answerCodeBlock = "";

if ($mode === 'student' && $answerCode !== '') {
    $answerCodeBlock = "```{$language}\n{$answerCode}\n```";
}
// ==============================
// 4. 根據模式給不同指示
// ==============================
$modeInstruction = '';

if ($mode === 'answer') {
    $modeInstruction = <<<TXT
這次輸入的是「正確解答程式碼」。
請務必遵守以下規則：
1. nodes type 只能用：
   start, end, io, operation, decision
2. decision 分支 label 必須 yes / no
3. 所有文字使用繁體中文
4. line 若無法判定可填 null
5. 若無錯誤：
   "has_error": false
   "error_nodes": []
6. 不要輸出任何 JSON 以外內容
7. flowchart 一定要包含 nodes 與 edges
8. 若提供程式碼，flowchart 必須完全依照程式碼內容，不可改寫為自然語言
9. decision 節點必須使用原始條件式，例如: n % 2 == 0

若程式本身沒有明顯錯誤，請回傳：
"has_error": false,
"error_nodes": []

不要為了湊錯誤而硬判定有錯。
TXT;
} else {
    $modeInstruction = <<<TXT
這次輸入的是「學生程式碼」。

請務必遵守以下規則：

1. nodes type 只能用：
   start, end, io, operation, decision
2. decision 分支 label 必須 yes / no
3. 所有文字使用繁體中文
4. line 若無法判定可填 null
5. 若無錯誤：
   "has_error": false
   "error_nodes": []
6. 不要輸出任何 JSON 以外內容
7. flowchart 一定要包含 nodes 與 edges
8. 若本次模式為 student，flowchart 必須忠實反映學生原始程式碼，不可自動修正成正確答案
9. 若有提供「參考正確解答」，只能用來分析學生錯誤，不可直接用來產生學生流程圖
10. 錯誤修正只能出現在 feedback，不可直接反映在 flowchart
11. 若學生程式碼與參考正確解答不同，必須先判斷是否為真正錯誤，不可僅因不同寫法就判錯

若提供參考正確解答：
1. 只能用於錯誤分析與比對
2. 不可直接以參考正確解答取代學生程式碼
3. 學生流程圖必須仍然依學生原始程式碼產生
4. 若學生與正解不同，但語意等價，則不可判為錯誤

請除了產生流程圖外，也要特別檢查：
- 邏輯錯誤
- 條件判斷錯誤
- 迴圈條件錯誤
- 輸入輸出錯誤
- 明顯語意錯誤

若有錯誤，請盡量指出：
- 錯在哪一行
- 對應哪個流程節點
- 錯誤原因
- 修正建議
TXT;
}

// ==============================
// 5. Prompt
// ==============================
$prompt = <<<EOT
你是一位「程式流程圖分析助教」。

請完成兩件事：

=====================
【任務一：產生流程圖】
=====================
根據「程式碼(優先)」或「題目描述」，產生 flowchart JSON。
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
=====================
【任務二：檢查程式錯誤】
=====================
1. type_error（型別錯誤）
2. logic_error（邏輯錯誤）
3. syntax_error（語法錯誤）
4. runtime_error（潛在執行錯誤）

若有錯誤，請提供：
- node_id（對應流程圖節點）
- line（錯誤行數）
- type（錯誤類型）
- message（錯誤說明）
- suggestion（修正建議）

=====================
【本次模式】
=====================
{$modeInstruction}

=====================
【參考正確解答（僅供錯誤分析，不可用於產生流程圖）】
=====================
{$answerCodeBlock}

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
    "has_error": false,
    "error_nodes": []
  }
}

若有錯誤，error_nodes 格式如下：
{
  "error_nodes": [
    {
      "node_id": "2",
      "line": 1,
      "type": "type_error",
      "message": "n 為字串，無法進行 % 運算",
      "suggestion": "請改為 n = int(input())"
    },
    {
      "node_id": "3",
      "line": 2,
      "type": "logic_error",
      "message": "條件判斷錯誤",
      "suggestion": "應改為 n % 2 == 0"
    }
  ]
}



EOT;

// ==============================
// 6. 呼叫 AI
// ==============================
$reply = chat_with_openai($prompt, "流程圖生成專家");

// ==============================
// 7. 清理 Markdown / 擷取 JSON
// ==============================
$clean = trim((string)$reply);
$clean = preg_replace('/```json/i', '', $clean);
$clean = preg_replace('/```/', '', $clean);
$clean = trim($clean);

$start = strpos($clean, '{');
$end   = strrpos($clean, '}');

if ($start !== false && $end !== false && $end > $start) {
    $clean = substr($clean, $start, $end - $start + 1);
}

// ==============================
// 8. 解析 JSON
// ==============================
$data = json_decode($clean, true);

if (!is_array($data) || !isset($data['flowchart']) || !is_array($data['flowchart'])) {
    echo json_encode([
        'success' => false,
        'error' => '⚠️ JSON 格式錯誤',
        'raw' => $reply
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ==============================
// 9. 補齊 feedback 預設值
// ==============================
if (!isset($data['feedback']) || !is_array($data['feedback'])) {
    $data['feedback'] = [
        'has_error' => false,
        'error_nodes' => []
    ];
}

if (!isset($data['feedback']['has_error'])) {
    $data['feedback']['has_error'] = false;
}

if (!isset($data['feedback']['error_nodes']) || !is_array($data['feedback']['error_nodes'])) {
    $data['feedback']['error_nodes'] = [];
}

// 補齊每個 error node 的欄位
$normalizedErrors = [];

foreach ($data['feedback']['error_nodes'] as $err) {
    if (!is_array($err)) continue;

    $normalizedErrors[] = [
        'node_id'    => $err['node_id'] ?? null,
        'line'       => $err['line'] ?? null,
        'type'       => $err['type'] ?? 'logic_error',
        'message'    => $err['message'] ?? '未提供錯誤說明',
        'suggestion' => $err['suggestion'] ?? '未提供修正建議'
    ];
}

$data['feedback']['error_nodes'] = $normalizedErrors;

// 若 error_nodes 為空，強制 has_error = false
if (count($data['feedback']['error_nodes']) === 0) {
    $data['feedback']['has_error'] = false;
}

// ==============================
// 10. 回傳
// ==============================
echo json_encode([
    'success' => true,
    'mode' => $mode,
    'flowchart' => $data['flowchart'],
    'feedback' => $data['feedback']
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>