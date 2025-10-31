<?php
require 'db.php';
require 'openai.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '只接受 POST 請求']);
    exit;
}

$chapter = $_POST['chapter'] ?? '';
$difficulty = $_POST['difficulty'] ?? '';

if (!$chapter || !$difficulty) {
    echo json_encode(['error' => '缺少章節或難度參數']);
    exit;
}

// 取得章節名稱
$stmt = $conn->prepare("SELECT title FROM chapters WHERE id = ?");
$stmt->bind_param("i", $chapter);
$stmt->execute();
$stmt->bind_result($title);
if (!$stmt->fetch()) {
    $title = "章節不存在";
}
$stmt->close();

$chapterLabel = "第{$chapter}章：{$title}";

// 🔍 檢查是否與現有題目相似
function is_similar_to_existing($conn, $chapter, $title, $desc) {
    $stmt = $conn->prepare("SELECT title, description FROM questions WHERE chapter=?");
    $stmt->bind_param("i", $chapter);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        similar_text($title, $row['title'], $titleSim);
        similar_text($desc, $row['description'], $descSim);
        if ($titleSim > 90 || $descSim > 90) { // 相似度過高
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

$prompt = <<<EOD
請幫我生成一個 Python 題目：
章節: "{$chapterLabel}"
難度: "{$difficulty}"

請依照以下規範設計題目：
- 本章為「輸入與輸出」，題目必須使用標準輸入與輸出 (input, print)。
- 不得包含 if 判斷、for 迴圈或 while 迴圈。
- 程式碼應短於 6 行，屬於入門練習型題目。
- 題目應具生活情境或具體任務（例如單位換算、計算面積、問候輸出等）。
- 每題要與既有題目不同（避免如「輸入兩數相加」這種重複題材）。

請輸出以下格式的 JSON（不需包含心智圖與流程圖）：

{
  "title": "題目標題",
  "description": "題目說明文字（自然語言描述題意與任務）",
  "test_cases": [
    {"input": "範例輸入1", "output": "預期輸出1"},
    {"input": "範例輸入2", "output": "預期輸出2"}
  ],
  "code_lines": [
    "第1行程式碼",
    "第2行程式碼",
    "第3行程式碼"
  ]
}

請確保：
- test_cases 至少有 2 組。
- 輸出結果完全符合說明。
- 程式能正確執行。
- 僅使用 Python 標準輸入輸出，禁止使用外部函式庫。
- 不要包含心智圖或流程圖。

請只輸出 JSON。
EOD;

// === 嘗試生成題目，最多 3 次 ===
$maxRetries = 3;
for ($i = 0; $i < $maxRetries; $i++) {
    $result = chat_with_openai($prompt);

    if (isset($result['error'])) {
        echo json_encode(['error' => $result['error']]);
        exit;
    }

    $content = $result['choices'][0]['message']['content'] ?? '';
    if (empty($content)) continue;

    // 清理 AI 回傳格式
    $content = trim($content);
    $content = preg_replace('/^```json\s*/', '', $content);
    $content = preg_replace('/^```/', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);

    $json = json_decode($content, true);
    if ($json === null) continue; // JSON 格式錯誤，重試

    $newTitle = $json['title'] ?? '';
    $newDesc  = $json['description'] ?? '';

    // 如果題目不相似 → 回傳成功
    if (!is_similar_to_existing($conn, $chapter, $newTitle, $newDesc)) {
        echo json_encode([
            'title' => $newTitle,
            'description' => $newDesc,
            'test_cases' => $json['test_cases'] ?? [],
            'mindmap' => $json['mindmap'] ?? null,
            'flowchart' => $json['flowchart'] ?? null,
            'code_lines' => $json['code_lines'] ?? []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 如果多次嘗試後還是重複
echo json_encode(['error' => '⚠️ 多次生成仍然與現有題目相似，請手動換題']);
exit;
?>
