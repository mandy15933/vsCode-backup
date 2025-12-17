<?php
session_start();

require 'openai.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode([
        'step1' => '⚠️ 無法讀取前端資料。',
        'step2' => '請確認 fetch 是否正確傳送 JSON。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$questionTitle = $input['question_title'] ?? '';
$questionDesc  = $input['question_desc'] ?? '';
$studentCode   = $input['student_code'] ?? '';
$correctCode   = $input['correct_code'] ?? '';


// ------------------------------------------------------
// ① Step 類型判定（修正：只認「真正語法上的迴圈」）
// ------------------------------------------------------
function classify_step_type($line) {
    $t = trim($line);

    if ($t === '' || strpos($t, '#') === 0) return 'blank_or_comment';

    // ✅ 僅接受真正的 Python 迴圈語法
    if (preg_match('/^(while|for)\s+.*:\s*$/', $t)) return 'loop';

    if (preg_match('/^(if|elif)\s+.*:\s*$|^else:\s*$/', $t)) return 'condition';
    if (preg_match('/\binput\s*\(/', $t))  return 'input';
    if (preg_match('/\bprint\s*\(/', $t))  return 'output';

    if (preg_match('/\+=|-=|\*=|\/=/', $t)) return 'update_accumulate';
    if (preg_match('/=\s*0\b/', $t))        return 'init_zero';
    if (preg_match('/=\s*1\b/', $t))        return 'init_one';

    if (preg_match('/%\s*10\b/', $t))       return 'mod_10';
    if (preg_match('/\/\/\s*10\b/', $t))    return 'div_10';
    if (preg_match('/\*\*/', $t))           return 'power';
    if (preg_match('/==|!=|>=|<=|>|</', $t)) return 'compare';

    if (preg_match('/\bbreak\b|\bcontinue\b/', $t)) return 'loop_control';

    if (preg_match('/^[A-Za-z_]\w*\s*=/', $t)) return 'assign';

    return 'other';
}

// ------------------------------------------------------
// ② 是否「理論上應該在迴圈內」
// ------------------------------------------------------
function should_be_in_loop($type) {
    return in_array($type, ['update_accumulate', 'mod_10', 'div_10', 'power']);
}

// ------------------------------------------------------
// ③ Diff 分析（核心修正點都在這）
// ------------------------------------------------------
function analyze_input_order($correctLines, $studentLines) {

    // 抓出 input 的變數順序
    $extractInputs = function($lines) {
        $inputs = [];
        foreach ($lines as $line) {
            $t = trim($line);
            // 只抓「變數 = input(...)」
            if (preg_match('/^([A-Za-z_]\w*)\s*=\s*(float|int|eval)?\s*\(?\s*input\s*\(/', $t, $m)) {
                $inputs[] = $m[1]; // 變數名稱
            }
        }
        return $inputs;
    };

    $correctInputs = $extractInputs($correctLines);
    $studentInputs = $extractInputs($studentLines);

    // 只處理「兩邊 input 次數相同，且 >=2」
    if (count($correctInputs) < 2 || count($correctInputs) !== count($studentInputs)) {
        return null;
    }

    $mismatches = [];

    foreach ($correctInputs as $i => $var) {
        if (!isset($studentInputs[$i])) continue;
        if ($studentInputs[$i] !== $var) {
            $mismatches[] = [
                'order' => $i + 1,
                'correct' => $var,
                'student' => $studentInputs[$i]
            ];
        }
    }

    if (empty($mismatches)) return null;

    return [
        'type' => 'input_order_mismatch',
        'count' => count($mismatches),
        'details' => $mismatches
    ];
}

function analyze_diff($correct, $student) {
    

    $cLines = array_map('rtrim', explode("\n", str_replace(["\r\n", "\r"], "\n", trim($correct))));
    $sLines = array_map('rtrim', explode("\n", str_replace(["\r\n", "\r"], "\n", trim($student))));
    if ($cLines === $sLines) {
        return null;
    }
    $summary = [];
    $inputOrderResult = analyze_input_order($cLines, $sLines);

    if ($inputOrderResult && $inputOrderResult['type'] === 'input_order_mismatch') {

        if ($inputOrderResult['count'] === 1) {
            $d = $inputOrderResult['details'][0];
            $summary[] =
                "第 {$d['order']} 次輸入所對應的變數與題目描述不一致，可能會讓對應輸入的值在後續計算中出錯。";
        } else {
            $orders = array_column($inputOrderResult['details'], 'order');
            $summary[] =
                "第 " . implode(' 與 ', $orders) . " 次輸入所對應的變數順序與題目描述不一致，可能影響後續計算。";
        }

        // ⚠️ 直接回傳，不再做後面的順序檢查（避免提示變雜）
        return implode("\n", $summary);
    }

    $cTypes = array_map('classify_step_type', $cLines);
    $sTypes = array_map('classify_step_type', $sLines);

    // ✅ 關鍵：先判定這題「是否真的有迴圈」
    $hasLoopInCorrect = in_array('loop', $cTypes);

    // --------------------------------------------------
    // ① 流程順序檢查（沒有迴圈就不檢查 loop）
    // --------------------------------------------------
    $keyFlow = $hasLoopInCorrect
        ? ['input', 'loop', 'condition', 'output']
        : ['input', 'condition', 'output'];

        $reportedOrderIssue = false;
        foreach ($keyFlow as $keyType) {
        if ($reportedOrderIssue) break;
        $cIdx = array_search($keyType, $cTypes);
        $sIdx = array_search($keyType, $sTypes);

        if ($cIdx !== false && $sIdx !== false && $cIdx !== $sIdx) {

            $cLineNo = $cIdx + 1;
            $sLineNo = $sIdx + 1;

            if ($keyType === 'input') {
                $summary[] = "讀取輸入的動作在學生程式第 {$sLineNo} 行，但在正解中應該較早出現（約第 {$cLineNo} 行）。";
            } elseif ($keyType === 'output') {
                $summary[] = "輸出的動作在學生程式第 {$sLineNo} 行，但在正解中是在計算完成後才出現（約第 {$cLineNo} 行）。";
            } elseif ($keyType === 'loop') {
                $summary[] = "重複處理的結構在學生程式第 {$sLineNo} 行，與正解中安排的位置不同（約第 {$cLineNo} 行）。";
            }
            $reportedOrderIssue = true;
        }
    }

    // --------------------------------------------------
    // ② 只有「有迴圈題」才做迴圈內外檢查
    // --------------------------------------------------
    if ($hasLoopInCorrect) {

        foreach ($cLines as $i => $line) {
            $type = classify_step_type($line);
            if (!should_be_in_loop($type)) continue;

            $cIndent = strlen($line) - strlen(ltrim($line));
            $cInLoop = $cIndent > 0;

            foreach ($sLines as $sLine) {
                if (classify_step_type($sLine) !== $type) continue;

                $sIndent = strlen($sLine) - strlen(ltrim($sLine));
                $sInLoop = $sIndent > 0;

                if ($cInLoop && !$sInLoop) {
                    if ($type === 'update_accumulate' || $type === 'power') {
                        $summary[] = "需要每次重複處理的計算只執行了一次，導致結果不完整。";
                    } else {
                        $summary[] = "應該反覆進行的動作沒有隨著每次處理一起執行。";
                    }
                }
            }
        }
    }

    // --------------------------------------------------
    // ③ 縮排差異（保留，因為 if 題也會用到）
    // --------------------------------------------------
    $max = max(count($cLines), count($sLines));
    for ($i = 0; $i < $max; $i++) {
        $cIndent = strlen($cLines[$i] ?? '') - strlen(ltrim($cLines[$i] ?? ''));
        $sIndent = strlen($sLines[$i] ?? '') - strlen(ltrim($sLines[$i] ?? ''));
        if ($cIndent !== $sIndent) {
            $summary[] = "程式的縮排結構與正解不同，可能讓原本應該一起執行的動作被分開。";
            break;
        }
    }

    if (empty($summary)) {
        $summary[] = "整體寫法與正解相近，但仍存在細節上的安排差異。";
    }

    return implode("\n", array_unique($summary));
}

// ------------------------------------------------------
// ④ JSON 擷取
// ------------------------------------------------------
function extract_json($text) {
    $start = strpos($text, "{");
    $end   = strrpos($text, "}");
    if ($start === false || $end === false) return null;
    return json_decode(substr($text, $start, $end - $start + 1), true);
}


// ------------------------------------------------------
// ⑤ 產生 Diff 摘要 → 丟給 AI
// ------------------------------------------------------
$diffSummary = analyze_diff($correctCode, $studentCode);
if ($diffSummary === null) {
    echo json_encode([
        "step1" => "✅ 程式的排列順序與縮排皆正確，已符合題目要求。",
        "step2" => "可以安心進行繳交與批閱。"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt = <<<PROMPT
你是一位擅長教導初學者的 Python 助教。
請根據「題目敘述」與「差異摘要」，產生兩階段提示，**只能輸出 JSON**。

{
  "step1": "...",
  "step2": "..."
}

【教學目標（非常重要）】
- 幫助學生「看懂自己哪個地方沒有符合題目描述」
- 提示要讓學生「知道該檢查哪一類動作」，而不是直接給答案

--------------------------------------------------
【題目敘述】
$questionDesc

【差異摘要】
$diffSummary
--------------------------------------------------

【Step 1（概念說明）】
- 用白話說明「目前哪一個學習概念沒有符合題目敘述」
- 說明該不符合之處在實際執行時會造成什麼結果或影響
- 若題目描述中包含順序語意（例如：依序、先後、第一、第二、第三），
  且差異摘要涉及 input 或執行順序，
  請從「順序與題目描述不一致所造成的影響」角度說明
- ❌ 不得提到任何具體程式行為（如：先執行、後執行、讀取時機）
- ❌ 不得暗示實際程式中動作發生的時間點或位置


【Step 2（具體引導，重教學效果）】
- 只能聚焦一個重點
- 才可以指出「哪一類動作的執行順序需要被檢查」
- 可以說明該動作是出現得太早或太晚
- 可以提到大約第幾行或前後位置，協助學生定位
- 不得提供正確程式碼或直接給答案
- 50 字以內

只輸出 JSON，不要有任何其他文字。
PROMPT;


// ------------------------------------------------------
// ⑥ 呼叫 AI
// ------------------------------------------------------
$responseText = chat_with_openai_hint($prompt, "Python 助教", "gpt-4o-mini");
$response = extract_json($responseText);

echo json_encode([
    "step1" => $response["step1"] ?? "⚠️ Step1 缺失",
    "step2" => $response["step2"] ?? "⚠️ Step2 缺失"
], JSON_UNESCAPED_UNICODE);

exit;
