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
$prompt = <<<EOD
請設計一個「生活化且多元化」的 Python 程式練習題，並符合以下要求：

【題目風格要求】
- 題目必須與現有常見題型不同，不能只是基本的字串反轉、加減乘除、迴圈印東西。
- 題目需要加入「真實生活場景」或「故事背景」，例如：
  - 購物結帳、飲食健康、交通、社群媒體、遊戲模擬、行事曆、感測器數據、票務、寵物管理等。
- 內容要具有"情境描述"，讓學生覺得題目是生活中會遇到的問題。
- 請主動變化題目類型，例如資料過濾、條件邏輯、集合運算、模擬計算、序列分析等，而不是傳統教科書式題目。

【輸入參數】
章節: "{$chapterLabel}"
難度: "{$difficulty}"

【產出格式（JSON）】
{
  "title": "題目標題",
  "description": "故事化、生活化的題目內容描述",
  "test_cases": [
    {"input": "輸入範例1", "output": "輸出範例1"},
    {"input": "輸入範例2", "output": "輸出範例2"}
  ],
  "code_lines": [
    "完整且可執行的 Python 解答程式碼，每行一個字串"
  ]
}

【限制】
- 不要輸出心智圖或流程圖。
- test_cases 至少 2 組。
- 程式碼需是完整解答。
- 題目必須具有生活化的具體情境，避免抽象與模板化。

請直接輸出 JSON，不要加入說明文字。
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
