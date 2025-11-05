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
        if ($titleSim > 90 || $descSim > 90) {
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

// 🧠 第一步：只生成題目、描述、測資與程式碼
$prompt = <<<EOD
請生成一個與之前不同的 Python 題目，適合學生練習，
章節: "{$chapterLabel}"
難度: "{$difficulty}"

請只輸出以下結構（JSON 格式）：

{
  "title": "題目標題",
  "description": "題目描述",
  "test_cases": [
    {"input": "輸入範例1", "output": "輸出範例1"},
    {"input": "輸入範例2", "output": "輸出範例2"}
  ],
  "code_lines": [
    "print('Hello')",
    "..."
  ]
}

⚠️ 不要輸出心智圖或流程圖。
請確保 test_cases 至少兩組，程式碼為完整可執行解答。
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
    $content = preg_replace('/```$/', '', $content);

    $json = json_decode($content, true);
    if ($json === null) continue;

    $newTitle = $json['title'] ?? '';
    $newDesc  = $json['description'] ?? '';

    // 檢查相似度
    if (!is_similar_to_existing($conn, $chapter, $newTitle, $newDesc)) {
        echo json_encode([
            'title' => $newTitle,
            'description' => $newDesc,
            'test_cases' => $json['test_cases'] ?? [],
            'code_lines' => $json['code_lines'] ?? []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 多次嘗試仍重複
echo json_encode(['error' => '⚠️ 多次生成仍與現有題目相似，請手動換題']);
exit;
?>
