<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'openai.php';

$desc = $_POST['description'] ?? '';
$test_cases_raw = $_POST['test_cases'] ?? '';

if (trim($desc) === '' || trim($test_cases_raw) === '') {
    echo json_encode(['error' => '❌ 缺少題目描述或測資']);
    exit;
}

$test_cases = json_decode($test_cases_raw, true);
if (!is_array($test_cases)) {
    $test_cases = [['input' => $test_cases_raw, 'output' => '']];
}

$prompt = <<<EOT
你是一位 Python 教學助教，請根據以下題目描述與測資生成「心智圖 (Mindmap)」。
每個節點的 id 必須唯一，可使用英文字加流水號（如 input_1, output_2）

請以 jsMind node_tree 格式輸出 JSON，格式如下：
{
  "meta": {"name": "Mindmap", "author": "AI", "version": "1.0"},
  "format": "node_tree",
  "data": {
    "id": "root",
    "topic": "題目理解",
    "children": [
      {"id": "cond", "topic": "已知條件", "children": [...]},
      {"id": "goal", "topic": "需求目標", "children": [...]},
      {"id": "explain", "topic": "名詞解釋", "children": [...]}
    ]
  }
}

- 「已知條件」列出輸入變數或限制。
- 「需求目標」列出題目要達成的任務。
- 「名詞解釋」列出題目中出現的特殊名詞或數學術語（如階乘、完美數、質數）。
- 請使用繁體中文。
- 不要輸出多餘的文字或 Markdown，只要 JSON。

題目描述：
{$desc}

測資範例：
EOT;

foreach ($test_cases as $tc) {
    $prompt .= "\n🟢 Input: " . trim($tc['input']) .
               "\n🔵 Output: " . trim($tc['output']) . "\n";
}

// === ✔ 使用新版 openai.php（回傳純字串）===
$reply = chat_with_openai($prompt, "python 心智圖生成專家");

// === ✔ 清理 Markdown / 雜訊 ===
$clean = trim($reply);
$clean = preg_replace('/```json/i', '', $clean);
$clean = preg_replace('/```/i', '', $clean);
$clean = trim($clean);

// === ✔ 轉回 JSON ===
$json = json_decode($clean, true);

if (!$json) {
    echo json_encode([
        'error' => '⚠️ JSON 格式錯誤',
        'raw'   => $reply
    ]);
    exit;
}

echo json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);