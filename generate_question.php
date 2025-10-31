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
請幫我生成一個與上次不同的 Python 題目：
章節: "{$chapterLabel}"
難度: "{$difficulty}"

請依照以下規範輸出：

1. 基本題目資料：
   - "title": 題目標題  
   - "description": 題目描述（自然語言說明）  
   - "test_cases": 至少 2 組測試資料（input / output 格式）  
   - "code_lines": 標準解答程式碼  
     範例：
     [
       "n = int(input())",
       "sum = 0",
       "for i in range(1, n):",
       "    if n % i == 0:",
       "        sum += i",
       "if sum == n:",
       "    print(n, '是完美數')"
     ]

2. 心智圖 (JSON 格式)
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

3. 流程圖 (JSON 格式)
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

⚠️ 請只輸出一個 JSON 物件，頂層必須同時包含：
- title  
- description  
- test_cases  
- code_lines  
- mindmap  
- flowchart
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
