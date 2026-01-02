<?php
require 'db.php';
require 'openai_service.php';

header('Content-Type: application/json; charset=utf-8');

// =======================================
// 0️⃣ 基本檢查
// =======================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => '只接受 POST']);
    exit;
}

$chapter    = (int)($_POST['chapter'] ?? 0);
$difficulty = trim($_POST['difficulty'] ?? '');

if ($chapter <= 0 || $difficulty === '') {
    echo json_encode(['error' => '缺少章節或難度']);
    exit;
}

// =======================================
// 1️⃣ 取得章節名稱
// =======================================
$stmt = $conn->prepare("SELECT title FROM chapters WHERE id=?");
$stmt->bind_param("i", $chapter);
$stmt->execute();
$stmt->bind_result($chapterTitle);
$stmt->fetch();
$stmt->close();

if (!$chapterTitle) {
    echo json_encode(['error' => '章節不存在']);
    exit;
}

$chapterLabel = "第{$chapter}章：{$chapterTitle}";

// =======================================
// 2️⃣ Prompt（只負責「教學內容」）
// =======================================
$basePrompt = <<<PROMPT
你是一位專門為「Python 初學者」設計生活化程式題目的教學專家。

請依照以下條件，設計一題 Python 程式練習題。

【教學目標】
- 題目必須有明確的生活情境或故事背景
- 避免制式題型（例如：單純加法、反轉字串、印星星）
- 讓學生需要「理解問題 → 思考邏輯 → 撰寫程式」

【課程條件】
- 章節：{$chapterLabel}
- 難度：{$difficulty}

【輸出 JSON 格式】
{
  "title": "題目標題",
  "description": "清楚、生活化的題目敘述",
  "test_cases": [
    { "input": "輸入範例1", "output": "輸出範例1" },
    { "input": "輸入範例2", "output": "輸出範例2" }
  ],
  "code_lines": [
    "Python 解答程式碼（每行一行）"
  ]
}
PROMPT;

// =======================================
// 3️⃣ 嘗試生成（呼叫 AI Service）
// =======================================
$maxRetries = 3;

for ($i = 0; $i < $maxRetries; $i++) {

    $prompt = $basePrompt;
    if ($i > 0) {
        $prompt .= "\n\n【額外要求】請使用不同的生活情境或故事背景。";
    }

    // ⭐ 核心差異：走 service 層
    $data = ai_generate_question($prompt);

    if (!$data) {
        continue;
    }

    // 最低限度驗證（service 已保證 JSON）
    if (
        empty($data['title']) ||
        empty($data['description']) ||
        !is_array($data['test_cases']) ||
        !is_array($data['code_lines'])
    ) {
        continue;
    }

    // 成功
    echo json_encode([
        'title'       => $data['title'],
        'description' => $data['description'],
        'test_cases'  => $data['test_cases'],
        'code_lines'  => $data['code_lines']
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =======================================
// 4️⃣ 全部失敗
// =======================================
echo json_encode([
    'error' => '⚠️ AI 目前無法生成合適題目，請稍後再試'
]);
exit;
