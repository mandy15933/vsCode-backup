<?php
session_start();

require 'openai.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode([
        'step1' => '⚠️ 無法讀取前端資料。',
        'step2' => '請確認 fetch 是否正確傳送 JSON。'
    ]);
    exit;
}

$questionTitle = $input['question_title'] ?? '';
$questionDesc  = $input['question_desc'] ?? '';
$studentCode   = $input['student_code'] ?? '';
$correctCode   = $input['correct_code'] ?? '';


// ------------------------------------------------------
// ① Diff 比對：找出實際錯誤（順序 / 缺行 / 縮排 / 未更新變數）
// ------------------------------------------------------

function classify_step_type($line) {
    $t = trim($line);

    if ($t === '' || strpos($t, '#') === 0) return 'blank_or_comment';
    if (preg_match('/while\s+|for\s+/', $t))          return 'loop';
    if (preg_match('/if\s+|elif\s+|else:/', $t))      return 'condition';
    if (preg_match('/input\s*\(/', $t))               return 'input';
    if (preg_match('/print\s*\(/', $t))               return 'output';
    if (preg_match('/\+=|-=|\*=|\/=/', $t))           return 'update_accumulate';
    if (preg_match('/=\s*0\b/', $t))                  return 'init_zero';
    if (preg_match('/=\s*1\b/', $t))                  return 'init_one';
    if (preg_match('/len\s*\(\s*str\s*\(/', $t))      return 'digit_count';
    if (preg_match('/%\s*10\b/', $t))                 return 'mod_10';
    if (preg_match('/\/\/\s*10\b/', $t))             return 'div_10';
    if (preg_match('/\*\*/', $t))                     return 'power';
    if (preg_match('/==|!=|>=|<=|>|</', $t))          return 'compare';
    if (preg_match('/break\b|continue\b/', $t))       return 'loop_control';

    // 一般指定
    if (preg_match('/^\s*[A-Za-z_]\w*\s*=/', $t))     return 'assign';

    return 'other';
}

/**
 * 推測一行是否「應該在迴圈內」（根據正確程式）
 */
function should_be_in_loop($line, $type) {
    // 直覺上：累加、更新、處理每筆資料 → 應在迴圈內
    if (in_array($type, ['update_accumulate', 'mod_10', 'div_10', 'power']))
        return true;
    return false;
}

/**
 * 抓出 while 條件裡用到的變數名稱
 */
function extract_loop_control_vars($code) {
    $vars = [];
    if (preg_match_all('/while\s+(.+):/', $code, $matches)) {
        foreach ($matches[1] as $cond) {
            if (preg_match_all('/\b([A-Za-z_]\w*)\b/', $cond, $m2)) {
                foreach ($m2[1] as $v) {
                    // 排除保留字與數字
                    if (in_array($v, ['True','False','and','or','not'])) continue;
                    $vars[$v] = true;
                }
            }
        }
    }
    return array_keys($vars);
}

/**
 * 檢查某個變數在學生程式中是否有在迴圈內被更新
 */
function is_var_updated_inside_loop($varName, $studentLines) {
    foreach ($studentLines as $line) {
        $indent = strlen($line) - strlen(ltrim($line));
        if ($indent <= 0) continue; // 粗略視為不在迴圈內
        $t = trim($line);
        if (preg_match('/\b' . preg_quote($varName, '/') . '\b\s*(\+=|-=|\*=|\/=|=)/', $t)) {
            return true;
        }
    }
    return false;
}

/**
 * 萬用 Diff：輸出「給 AI 看得懂」的差異摘要（多行文字）
 */
function analyze_diff($correct, $student) {

    $cLines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($correct)));
    $sLines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($student)));

    $cLines = array_map('rtrim', $cLines);
    $sLines = array_map('rtrim', $sLines);

    $summary = [];

    // ① 基本：輸入 / 處理 / 輸出 步驟順序
    $cTypes = array_map('classify_step_type', $cLines);
    $sTypes = array_map('classify_step_type', $sLines);

    $keyFlow = ['input', 'loop', 'condition', 'output'];
    foreach ($keyFlow as $keyType) {
        $cIdx = array_search($keyType, $cTypes);
        $sIdx = array_search($keyType, $sTypes);
        if ($cIdx !== false && $sIdx !== false && $cIdx !== $sIdx) {
            if ($keyType === 'input') {
                $summary[] = "讀取輸入的步驟在程式流程中的位置與正解不同，可能導致後續判斷使用到錯誤或過早的資料。";
            } elseif ($keyType === 'loop') {
                $summary[] = "迴圈在整體流程中的位置與正解不同，可能讓重複處理的時機點有所偏移。";
            } elseif ($keyType === 'output') {
                $summary[] = "輸出結果的步驟放在與正解不同的位置，可能尚未完成所有計算就印出結果。";
            }
        }
    }

    // ② 檢查「應在迴圈內」的語句是否被移出（或相反）
    foreach ($cLines as $i => $line) {
        $type = classify_step_type($line);
        if (!should_be_in_loop($line, $type)) continue;

        $cIndent = strlen($line) - strlen(ltrim($line));
        $cInLoop = $cIndent > 0;

        // 找對應類型的學生行（粗略匹配）
        foreach ($sLines as $j => $sLine) {
            if (classify_step_type($sLine) !== $type) continue;

            $sIndent = strlen($sLine) - strlen(ltrim($sLine));
            $sInLoop = $sIndent > 0;

            if ($cInLoop && !$sInLoop) {
                // 正解：在迴圈內；學生：在迴圈外
                if ($type === 'update_accumulate' || $type === 'power') {
                    $summary[] = "用來累加或計算每一項的語句被放在迴圈外，會讓這個動作只執行一次，而不是每輪都執行。";
                } elseif ($type === 'mod_10' || $type === 'div_10') {
                    $summary[] = "用來逐步拆解或更新數值的語句不在迴圈中，導致迴圈無法逐步推進或處理每一筆資料。";
                } else {
                    $summary[] = "部分應該跟著迴圈重複執行的語句被放在外面，造成邏輯只做一次。";
                }
            }
        }
    }

    // ③ while 條件用到的變數，學生程式是否有在迴圈內更新
    $loopVars = extract_loop_control_vars($correct);
    if (!empty($loopVars)) {
        $noUpdateVars = [];
        foreach ($loopVars as $v) {
            if (!is_var_updated_inside_loop($v, $sLines)) {
                $noUpdateVars[] = $v;
            }
        }
        if (!empty($noUpdateVars)) {
            $summary[] = "迴圈條件中使用的變數（例如：" . implode('、', $noUpdateVars) . "）在迴圈內沒有被更新，可能會讓條件永遠不改變。";
        }
    }

    // ④ 縮排結構差異（只給一條總結）
    $indentDiff = false;
    $max = max(count($cLines), count($sLines));
    for ($i = 0; $i < $max; $i++) {
        $cl = $cLines[$i] ?? '';
        $sl = $sLines[$i] ?? '';
        $cIndent = strlen($cl) - strlen(ltrim($cl));
        $sIndent = strlen($sl) - strlen(ltrim($sl));
        if ($cIndent !== $sIndent) {
            $indentDiff = true;
            break;
        }
    }
    if ($indentDiff) {
        $summary[] = "程式的縮排層級與正解不同，可能讓原本應該同一區塊的語句被拆開，影響 if / while 的邏輯。";
    }

    // ⑤ 若完全比對不出具體問題，給一個保底說明
    if (empty($summary)) {
        $summary[] = "學生程式與正解在大方向上相似，但在細部順序或縮排安排上仍有差異。";
    }

    // 去除重複訊息
    $summary = array_values(array_unique($summary));

    return implode("\n", $summary);
}
// 從 AI 回應中強制抽取 JSON（避免前後雜訊）
function extract_json($text) {
    $start = strpos($text, "{");
    $end = strrpos($text, "}");
    if ($start === false || $end === false) return null;

    $json = substr($text, $start, $end - $start + 1);
    return json_decode($json, true);
}



// ------------------------------------------------------
// ② 產生差異摘要（餵給 AI）
// ------------------------------------------------------
$diffSummary = analyze_diff($correctCode, $studentCode);


// ------------------------------------------------------
// ③ AI Prompt（C 模式：概念 + 思維引導）
// ------------------------------------------------------

$prompt = <<<PROMPT
你是一位擅長教導初學者的 Python 助教。
你的任務：根據「差異摘要」產生 **非常直覺、易懂、貼近學生視角** 的兩階段提示。

⚠ 必須只輸出 JSON：
{
  "step1": "...",
  "step2": "..."
}

【Step 1（具體描述問題）】
- 必須清楚講出「哪個概念」被破壞（例如：每位數都要累加、迴圈每輪要更新、條件依賴變數需要初始化…）
- 必須描述「錯誤造成的結果會是什麼」
  例如：「s 只會加一次」、「條件永遠不會改變」、「t 永遠不會變小」
- 說話要直覺，而不是抽象（禁止出現：流程錯誤、執行順序錯誤、資料未更新之類學術詞彙）

【Step 2（簡短引導）】
- 只能問學生「一個關鍵問題」
- 必須是直覺性問題，例如：
  -「這個動作應該要每次迴圈都執行嗎？」
  -「若某變數不更新，條件還會變化嗎？」
  -「每一位數是否都應該被處理一次？」
- 限 50 字內

【禁止事項】
- 不能給答案
- 不能說明程式碼位置
- 不能給行號
- 不能提供正確程式碼
- 不能輸出 JSON 以外格式

----------------------------------------
【差異摘要】
$diffSummary

PROMPT;





// ------------------------------------------------------
// ④ 呼叫 GPT
// ------------------------------------------------------

$responseText = chat_with_openai($prompt, "Python 助教", "gpt-4o-mini", 0.7);


// ------------------------------------------------------
// ⑤ JSON 解析
// ------------------------------------------------------

$response = extract_json($responseText);

echo json_encode([
    "step1" => $response["step1"] ?? "⚠️ Step1 缺失",
    "step2" => $response["step2"] ?? "⚠️ Step2 缺失"
], JSON_UNESCAPED_UNICODE);

exit;
