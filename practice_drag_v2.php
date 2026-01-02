<?php
session_start();
require 'session_protect.php';
require 'db.php';



// ================================
// 0. 相容舊連結：question_id -> guid
// ================================
if (isset($_GET['question_id']) && !isset($_GET['guid'])) {
    $qid = (int)$_GET['question_id'];

    $stmt = $conn->prepare("SELECT guid FROM questions WHERE id=?");
    $stmt->bind_param("i", $qid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $guid = $row['guid'] ?? null;

    if ($guid) {
        header("Location: practice_drag.php?guid={$guid}", true, 301);
        exit;
    } else {
        die("❌ 找不到 GUID (ID: $qid)");
    }
}

// ======================================
// 1. 使用者資訊與模式判斷
// ======================================
$userId = $_SESSION['user_id'] ?? 1;
$isExamMode = (isset($_GET['test_group_id']) && (int)$_GET['test_group_id'] > 0);
$testGroupId = $isExamMode ? (int)$_GET['test_group_id'] : null;

// 必須有 guid
if (!isset($_GET['guid'])) {
    die("❌ 請提供題目 GUID，例如：practice_drag.php?guid=xxxx");
}
$guid = $_GET['guid'];

// 讀題目
$stmt = $conn->prepare("SELECT * FROM questions WHERE guid=?");
$stmt->bind_param("s", $guid);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) die("❌ 找不到這題 (GUID: $guid)");

$questionId = (int)$question["id"];

// ======================================

$chapterId     = (int)$question['chapter'];
$testCases     = json_decode($question['test_cases'], true) ?? [];
$codeLines     = json_decode($question['code_lines'], true) ?? [];
$mindmapJson   = $question['mindmap_json'] ?? null;
$flowchartJson = $question['flowchart_json'] ?? null;

// ======================================
// 3. 找上一題
// ======================================
$stmt = $conn->prepare("
    SELECT id, guid FROM questions 
    WHERE chapter=? AND id<? AND is_hidden = 0
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$prevId   = $row['id']   ?? null;
$prevGuid = $row['guid'] ?? null;

// ======================================
// 4. 找下一題
// ======================================
$stmt = $conn->prepare("
    SELECT id, guid FROM questions 
    WHERE chapter=? AND id>? AND is_hidden = 0
    ORDER BY id ASC LIMIT 1
");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextId   = $row['id']   ?? null;
$nextGuid = $row['guid'] ?? null;

// ======================================
// 5. 找下一章節的第一題
// ======================================
$nextChap = $chapterId + 1;

$stmt = $conn->prepare("
    SELECT id, guid FROM questions 
    WHERE chapter=? AND is_hidden = 0
    ORDER BY id ASC LIMIT 1
");
$stmt->bind_param("i", $nextChap);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextChapterFirstQId   = $row['id']   ?? null;   // 如果後面 SQL 用得到 id 可以留著
$nextChapterFirstGuid  = $row['guid'] ?? null;   // 網址用這個


// ======================================
// 6. 章節題目進度（僅練習模式）
// ======================================
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM questions WHERE chapter=? AND is_hidden = 0) AS total,
        (SELECT COUNT(DISTINCT q.id)
           FROM questions q
           JOIN student_answers sa
                ON sa.question_id = q.id
               AND sa.user_id = ?
               AND sa.is_correct = 1
               AND (sa.test_group_id IS NULL OR sa.answer_mode='practice')
           WHERE q.chapter=?) AS done
");
$stmt->bind_param("iii", $chapterId, $userId, $chapterId);
$stmt->execute();
$progress = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalQuestions = (int)($progress['total'] ?? 0);
$doneQuestions  = (int)($progress['done']  ?? 0);
$chapterFinished = ($doneQuestions >= $totalQuestions);

// ======================================
// 7. 查詢學生該章節的平均嘗試次數
// ======================================
$stmt = $conn->prepare("
    SELECT 
        SUM(is_correct=1) AS correct_count,
        COUNT(*) AS total_submissions,
        SUM(attempts) / COUNT(DISTINCT question_id) AS avg_attempts
    FROM student_answers
    WHERE user_id=? 
      AND question_id IN (SELECT id FROM questions WHERE chapter=?)
");
$stmt->bind_param("ii", $userId, $chapterId);
$stmt->execute();
$chapterStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$avgAttempts = $chapterStats['avg_attempts'] ?? 1;

// 根據表現動態調整題目難度
if ($avgAttempts <= 1.2) {
    $linesToShuffle = rand(5, 6); // 高掌握 → 難
} elseif ($avgAttempts <= 2.0) {
    $linesToShuffle = rand(3, 4); // 中等
} else {
    $linesToShuffle = rand(2, 3); // 低掌握 → 簡單
}

// ======================================
// 8. 取得章節名稱
// ======================================
$stmt = $conn->prepare("SELECT title FROM chapters WHERE id=?");
$stmt->bind_param("i", $chapterId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

$chapterTitle = $result['title'] ?? '';

// ======================================
// 9. 查詢目前題目是否已通過
// ======================================
$stmt = $conn->prepare("
    SELECT is_correct 
    FROM student_answers 
    WHERE user_id=? AND question_id=? 
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("ii", $userId, $questionId);
$stmt->execute();
$isPassedRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isPassed = ($isPassedRow && $isPassedRow['is_correct'] == 1);

// ======================================
// 10. 檢查章節剩餘題目
// ======================================
$stmt = $conn->prepare("
    SELECT COUNT(*) AS remaining
    FROM questions q
    WHERE q.chapter = ? AND q.is_hidden = 0
      AND q.id NOT IN (
          SELECT sa.question_id
          FROM student_answers sa
          WHERE sa.user_id = ? AND sa.is_correct = 1
      )
");
$stmt->bind_param("ii", $chapterId, $userId);
$stmt->execute();
$remainRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$remaining = (int)($remainRow['remaining'] ?? 0);



?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>拖曳排序題：<?= htmlspecialchars($question['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsmind/style/jsmind.css" />
    <script src="https://cdn.jsdelivr.net/npm/jsmind/es6/jsmind.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.3.0/raphael.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowchart/1.18.0/flowchart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css">


    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/python.min.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chiron+GoRound+TC:wght@200..900&display=swap" rel="stylesheet">
    <audio id="soundClick" src="sounds/click.mp3" preload="none"></audio>
    <audio id="soundClick2" src="sounds/click2.mp3?v=1" preload="none"></audio>
    <audio id="soundSuccess" src="sounds/success.mp3" preload="none"></audio>
    <audio id="soundError" src="sounds/error.mp3?v=1" preload="none"></audio>
    <audio id="soundHover" src="sounds/hover.mp3?v=1" preload="none"></audio>
    <audio id="soundSelect" src="sounds/select.mp3" preload="none"></audio>
    <audio id="soundIndent" src="sounds/indent.mp3?v=1" preload="none"></audio>
    <audio id="soundOutdent" src="sounds/outdent.mp3" preload="none"></audio>
    <audio id="soundCorrect" src="sounds/correct.mp3" preload="none"></audio>
    <audio id="soundMove" src="sounds/move.mp3?v=1" preload="none"></audio>
    
    <script src="feedback_modal.js?v=1.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <link rel="stylesheet" href="anime-yellow-theme.css?v=3.0">
    <link rel="stylesheet" href="style_practice_drag.css?v=3.0">
    

</head>
<body>
<?php include 'Navbar.php'; ?>

<div class="container mt-3">
    <div class="card shadow-sm mb-4 border-warning">
        <?php if ($testGroupId): ?>
        <?php endif; ?>
        <div class="card-body">
            <?php if ($testGroupId): ?>
                <?php
                    // 讀取測驗題組名稱與題目數量
                    $stmt = $conn->prepare("SELECT name, question_ids FROM test_groups WHERE id=?");
                    $stmt->bind_param("i", $testGroupId);
                    $stmt->execute();
                    $groupData = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $testGroupName = $groupData['name'] ?? '未命名題組';
                    $questionIds = json_decode($groupData['question_ids'], true) ?? [];
                    $totalInGroup = count($questionIds);

                    // 🔹 計算學生已通過題數
                    $placeholders = implode(',', array_fill(0, $totalInGroup, '?'));
                    $sql = "SELECT COUNT(DISTINCT question_id) AS passed_count
                            FROM student_answers
                            WHERE user_id=? AND is_correct=1 AND answer_mode='exam' AND question_id IN ($placeholders)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('i' . str_repeat('i', $totalInGroup), $userId, ...$questionIds);
                    $stmt->execute();
                    $passData = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $passedCount = (int)($passData['passed_count'] ?? 0);
                    $percent = $totalInGroup > 0 ? round(($passedCount / $totalInGroup) * 100, 1) : 0;
                ?>

                <h5 class="mb-3 text-dark">
                    🧩 測驗模式：<?= htmlspecialchars($testGroupName) ?>
                </h5>

                <div class="progress" style="height: 25px; border-radius: 8px;">
                    <div class="progress-bar <?= $passedCount >= $totalInGroup ? 'bg-success' : 'bg-info' ?>" 
                        role="progressbar" 
                        style="width: <?= $percent ?>%;" 
                        aria-valuenow="<?= $passedCount ?>" 
                        aria-valuemin="0" 
                        aria-valuemax="<?= $totalInGroup ?>">
                        <?= $passedCount ?> / <?= $totalInGroup ?> 題已通過
                    </div>
                </div>
            <?php else: ?>
                <h5 class="mb-3 text-dark">📖 目前練習：第 <?= $chapterId ?> 章 <?= htmlspecialchars($chapterTitle) ?></h5>
                <div class="progress" style="height: 25px; border-radius: 8px;">
                    <div class="progress-bar 
                        <?= $doneQuestions >= $totalQuestions ? 'bg-success' : 'bg-warning' ?>" 
                        role="progressbar" 
                        style="width: <?= $totalQuestions > 0 ? round(($doneQuestions/$totalQuestions)*100,1) : 0 ?>%;" 
                        aria-valuenow="<?= $doneQuestions ?>" 
                        aria-valuemin="0" 
                        aria-valuemax="<?= $totalQuestions ?>">
                        <?= $doneQuestions ?>/<?= $totalQuestions ?> 已完成
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>




<div class="container-fluid mt-4">
    <?php if (!empty($timeLimit)): ?>
        <div id="timerBox" class="text-center mb-3 fs-5 fw-bold text-danger"></div>
    <?php endif; ?>
    <div class="row">
        <!-- 題目區 -->
          <div class="col-12 mb-3">
            <div class="card border-warning shadow-sm">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <h4 class="mb-0">📝 題目：<?= htmlspecialchars($question['title']) ?></h4>
                    <?php if ($isPassed): ?>
                        <span class="badge bg-success fs-6">✅ 已通過</span>
                    <?php else: ?>
                        <span class="badge bg-secondary fs-6">⏳ 尚未通過</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="fs-5 mt-2"><?= nl2br(htmlspecialchars($question['description'])) ?></p>
                </div>
            </div>
        </div>


        <!-- 左側：拖曳排序 -->
    <div class="col-12 col-lg-6 mb-3">
        <div class="card border-dark shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">💻 拖曳程式碼區域</h5>
            </div>
            <div class="card-body">
                    <!-- <p class="text-muted small">
                        你的章節平均嘗試次數：<?= round($avgAttempts,2) ?>  
                        → 本次打亂<strong><?= $linesToShuffle ?></strong> 行
                    </p> -->
                    <ul id="codeList" class="list-group mb-3"></ul>
                    <div class="d-flex flex-wrap gap-2">
                        <button id="submitOrder" class="btn  btn-submitting">✅ 提交答案</button>
                        <?php if (!$isExamMode): ?>
                            <button id="aiHintBtn" class="btn btn-warning">
                                🤖 AI提示 <span id="aiHintCountLabel" class="text-dark fw-bold"></span>
                            </button>
                        <?php endif; ?>
                        <button id="indentBtn" class="btn btn-cute btn-dent">➡ 縮排</button>
                        <button id="outdentBtn" class="btn btn-cute btn-dent">⬅ 反縮排</button>
                        <?php if (empty($testGroupId)): ?>
                            <a href="practice_list.php?chapter=<?= $chapterId ?>" class="btn btn-secondary">📘 返回列表</a>
                        <?php endif; ?>
                        <?php if ($isExamMode): ?>
                            <!-- 🚩 測驗模式下：只顯示返回題組與題組選單 -->
                            <a href="quiz.php?set=<?= $testGroupId ?>" class="btn btn-secondary">📘 返回題組</a>
                        <?php else: ?>  <!-- 🚫 測驗模式不顯示上下題 -->
                                <?php if ($prevId): ?>
                                    <a href="practice_drag.php?guid=<?= $prevGuid ?>" class="btn-cute btn-nav">⬅上一題</a>
                                <?php endif; ?>

                                <?php if ($nextId): ?>
                                    <a href="practice_drag.php?guid=<?= $nextGuid ?>" class="btn-cute btn-nav">下一題➡</a>
                                <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 右側：提示區 -->
        <div class="col-12 col-lg-6 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-header bg-light text-dark border-warning">
                    <h5 class="mb-0">📚 輔助提示</h5>
                </div>
                <div class="card-body">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs" id="hintTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active text-dark" id="test-tab" data-bs-toggle="tab"
                                data-bs-target="#testPane" type="button" role="tab">📑 測資</button>
                        </li>

                        <?php if (!$isExamMode): ?>
                            <li class="nav-item">
                                <button class="nav-link text-dark" id="mindmap-tab" data-bs-toggle="tab"
                                    data-bs-target="#mindmapPane" type="button" role="tab">🧠 心智圖</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link text-dark" id="flowchart-tab" data-bs-toggle="tab"
                                    data-bs-target="#flowchartPane" type="button" role="tab">🔄 流程圖</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link text-dark" id="aihint-tab" data-bs-toggle="tab"
                                    data-bs-target="#aihintPane" type="button" role="tab">💬 AI提示</button>
                            </li>
                        <?php endif; ?>
                    </ul>

                    <div class="tab-content mt-3">
                        <!-- 測資 -->
                        <div class="tab-pane fade show active" id="testPane" role="tabpanel">
                            <?php foreach ($testCases as $i=>$tc): ?>
                            <div class="border p-2 mb-2 rounded bg-light">
                                <b>測資 <?= $i+1 ?>：</b><br>
                                <span class="text-muted">輸入：</span><pre><?= htmlspecialchars($tc['input']) ?></pre>
                                <span class="text-muted">預期輸出：</span><pre><?= htmlspecialchars($tc['output']) ?></pre>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- 心智圖 -->
                        <div class="tab-pane fade" id="mindmapPane" role="tabpanel">
                            <div id="mindmapArea" class="mindmap-box"></div>
                            <div class="mt-2 text-center">
                                <button id="mmZoomOutBtn" class="btn btn-outline-secondary btn-sm">➖ 縮小</button>
                                <button id="mmZoomInBtn"  class="btn btn-outline-secondary btn-sm">➕ 放大</button>
                                <button id="mmZoomResetBtn" class="btn btn-outline-secondary btn-sm">🔄 重設</button>
                            </div>

                        </div>
                        <!-- 流程圖 -->
                        <div class="tab-pane fade" id="flowchartPane" role="tabpanel">
                            <div class="d-flex flex-column align-items-center">
                                <div id="flowchartWrapper" class="card shadow-sm border-warning d-inline-block">
                                <div class="card-body text-center">
                                    <div id="flowchartArea"></div>
                                </div>
                                </div>

                                <!-- 🔍 縮放控制 -->
                                <div class="mt-2">
                                <button id="zoomOutBtn" class="btn btn-outline-secondary btn-sm">➖ 縮小</button>
                                <button id="zoomInBtn" class="btn btn-outline-secondary btn-sm">➕ 放大</button>
                                <button id="zoomResetBtn" class="btn btn-outline-secondary btn-sm">🔄 重設</button>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="aihintPane" role="tabpanel">
                            <div id="aiHintArea" class="border rounded p-3 bg-light" style="min-height: 200px;">
                                <p class="text-muted">尚未產生 AI 提示。</p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
const codeLines = <?= json_encode($codeLines, JSON_UNESCAPED_UNICODE) ?>;
const mindmapData   = <?= $mindmapJson ? $mindmapJson : 'null' ?>;
const flowchartData = <?= $flowchartJson ? $flowchartJson : 'null' ?>;
const nextChapterFirstQId = <?= $nextChapterFirstQId ? $nextChapterFirstQId : 'null' ?>;
const linesToShuffle = <?= $linesToShuffle ?>;
const startTime = Date.now();  // ✅ 記錄開始時間



window._clickBound = window._clickBound || {
  mindmap: false,
  flowchart: false,
  aihint: false
};



// === 🔹 建立打亂後的程式碼與行號對應 ===
const withIndex = codeLines.map((text, i) => ({ text, orig: i + 1 }));
let toShuffle = withIndex.slice(0, linesToShuffle);
let remain    = withIndex.slice(linesToShuffle);

// 打亂前幾行
toShuffle = toShuffle.sort(() => Math.random() - 0.5);
const shuffled = toShuffle.concat(remain);

// 行號映射：原始行 → 打亂後位置
const lineMap = {};
shuffled.forEach((row, idx) => { lineMap[row.orig] = idx + 1; });
// console.log("行號對應表:", lineMap);
window.lineMap = lineMap; // ✅ 讓流程圖能全域取用

// === 畫出程式碼 ===
// === 畫出程式碼 ===
const codeList = document.getElementById("codeList");

shuffled.forEach(row => {
  const clean = row.text.replace(/^\s+/, "");
  const li = document.createElement("li");
  li.className = "list-group-item code-line";
  li.setAttribute("data-indent", "0");

  const pre = document.createElement("pre");
  const code = document.createElement("code");
  code.className = "language-python";
  code.textContent = clean;

  pre.appendChild(code);
  li.appendChild(pre);
  codeList.appendChild(li);
});

// 啟動 Highlight.js
hljs.highlightAll();

// === 拖曳設定 + 音效 ===
let selectedLine = null;
let sortableInstance = null;
let lastHoverTime = 0; // 防止 hover 音效太頻繁

function initSortable() {
  if (sortableInstance) sortableInstance.destroy();

  sortableInstance = new Sortable(codeList, {
    animation: 150,
    handle: ".code-line",
    ghostClass: "dragging",
    touchStartThreshold: 5,

    // 📌 僅限「選取的行」才能拖曳（手機）
    onMove: (evt) => {
      const dragged = evt.dragged;

      // 判斷是不是手機
      const isMobile = window.innerWidth <= 768;

      if (isMobile) {
        // 若目前拖曳的不是使用者選取的那一行 → 不允許移動
        if (!dragged.classList.contains("selected")) {
          return false;  // ⛔ 阻止拖曳
        }
      }

      // 若是桌機或選取的行 → 允許移動
      return true;
    },

    onStart: (evt) => {
      // 手機：開始拖曳時，若沒有選取，就取消
      const isMobile = window.innerWidth <= 768;
      if (isMobile) {
        const dragged = evt.item;
        if (!dragged.classList.contains("selected")) {
          evt.preventDefault();
          return false;
        }
      }

      playSound("soundHover", 0.6);
    },

    onEnd: (evt) => {
      if (evt.oldIndex !== evt.newIndex) {
        playSound("soundMove", 0.4);
      }
    }
  });
}
initSortable();


// ================================
// 🎬 按鈕動畫工具（全域）
// ================================
function addButtonEffect(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;

    // 讓連續點擊也能重播動畫
    btn.classList.remove("btn-animate");
    void btn.offsetWidth; // 強制 reflow
    btn.classList.add("btn-animate");

    setTimeout(() => {
        btn.classList.remove("btn-animate");
    }, 300);
}



codeList.addEventListener("click", e => {
  const li = e.target.closest("li");
  if (!li) return;
  document.querySelectorAll(".code-line").forEach(l => l.classList.remove("selected"));
  selectedLine = li;
  li.classList.add("selected");
  playSound("soundSelect", 0.6);
});

const indentBtn = document.getElementById("indentBtn");
const outdentBtn = document.getElementById("outdentBtn");

function getPrevIndent(line) {
    const prev = line.previousElementSibling;
    return prev ? parseInt(prev.getAttribute("data-indent")) || 0 : 0;
}

function getCurrentIndent(line) {
    return parseInt(line.getAttribute("data-indent")) || 0;
}

function canIndent(line, maxLevel = 5) {
    const indent = getCurrentIndent(line);
    const prevIndent = getPrevIndent(line);
    const maxIndent = Math.min(prevIndent + 1, maxLevel);
    return indent < maxIndent;
}
let indentHintLocked = false;

function showIndentTeachingHint(line) {
    if (indentHintLocked) return;
    indentHintLocked = true;

    const prev = line.previousElementSibling;

    // 🔴 本行
    line.classList.add("indent-warning");

    // 🔵 上一行
    if (prev) {
        prev.classList.add("indent-reference");
    }

    // 💬 教學文字
    const tip = document.createElement("div");
    tip.className = "indent-tooltip";
    tip.innerText = "📌 這一行不能比上一行多縮排超過 1 層";
    codeList.appendChild(tip);

    tip.style.left = line.offsetLeft + "px";
    tip.style.top  = (line.offsetTop + line.offsetHeight + 6) + "px";

    // ⏱️【關鍵】動畫結束後，清掉所有狀態
    setTimeout(() => {
        line.classList.remove("indent-warning");
        if (prev) prev.classList.remove("indent-reference");
        tip.remove();
        indentHintLocked = false;
    }, 900); // ⬅ 和 animation 時間一致
}






function flashIndentWarning(line) {
    line.classList.remove("indent-warning");
    void line.offsetWidth; // reflow
    line.classList.add("indent-warning");
}

indentBtn.addEventListener("click", () => {
    if (!selectedLine) return;

    addButtonEffect("indentBtn");
    playSound("soundIndent", 0.5);

    if (!canIndent(selectedLine)) {
        playSound("soundError", 0.3);
        showIndentTeachingHint(selectedLine);
        return;
    }


    const indent = getCurrentIndent(selectedLine);
    selectedLine.setAttribute("data-indent", indent + 1);
});

outdentBtn.addEventListener("click", () => {
    if (!selectedLine) return;

    addButtonEffect("outdentBtn");
    playSound("soundOutdent", 0.5);

    const indent = getCurrentIndent(selectedLine);
    if (indent <= 0) {
        flashIndentWarning(selectedLine);
        return;
    }

    selectedLine.setAttribute("data-indent", indent - 1);
});

document.addEventListener("keydown", e => {
    if (!selectedLine) return;

    if (e.key === "Tab") {
        e.preventDefault();

        const indent = getCurrentIndent(selectedLine);

        if (e.shiftKey) {
            // 反縮排
            if (indent > 0) {
                playSound("soundOutdent", 0.5);
                selectedLine.setAttribute("data-indent", indent - 1);
            } else {
                flashIndentWarning(selectedLine);
            }
        } else {
            // 縮排（同樣使用 canIndent）
            if (canIndent(selectedLine)) {
                playSound("soundIndent", 0.5);
                selectedLine.setAttribute("data-indent", indent + 1);
            } else {
                playSound("soundError", 0.3);
                showIndentTeachingHint(selectedLine);
            }
        }
    }
});


// 啟動 Highlight.js
hljs.highlightAll();


function playSound(id, volume = 1) {
    const audio = document.getElementById(id);
    if (audio) {
        audio.currentTime = 0;
        audio.play();
    }
}




// 初始化心智圖
function renderMindmap(data) {
    const container = document.getElementById("mindmapArea");
    container.innerHTML = "";

    if (!data) {
        container.innerHTML = "<p class='text-muted'>⚠️ 沒有心智圖資料</p>";
        return;
    }

    // 固定視窗
    container.style.minHeight = "450px";   // ⭐ 讓框可以變大
    container.style.overflow = "auto";     // ⭐ 出現捲軸，避免被裁掉
    container.style.position = "relative";

    const jm = new jsMind({
        container: "mindmapArea",
        theme: "primary",
        editable: false
    });

    jm.show(data);

    // 自動換行
    container.querySelectorAll("jmnode").forEach(node => {
        node.style.whiteSpace = "normal";
        node.style.wordBreak = "break-word";
        node.style.maxWidth = "240px";
        node.style.lineHeight = "1.4";
        node.style.padding = "4px 8px";
        node.style.fontSize = "15px";
    });

    setTimeout(() => jm.resize(), 200);

    // ================================
    // 🎯 核心：抓取 jsMind 的真正主容器
    // ================================
    const mindmapRoot = container.querySelector(".jsmind-inner");
    if (!mindmapRoot) return;

    mindmapRoot.style.transformOrigin = "0 0";

    // ⭐ 初次載入自動放大 ⭐
    let scale = 1.3;  // ← 你可改成 1.5、1.8
    let offsetX = 0, offsetY = 0;

    mindmapRoot.style.transform = `translate(0px, 0px) scale(${scale})`;

    // ==================================================
    // 🖱️ 滑鼠滾輪縮放
    // ==================================================
    container.addEventListener("wheel", (e) => {
        e.preventDefault();
        scale += (e.deltaY < 0 ? 0.1 : -0.1);
        scale = Math.max(0.3, Math.min(3, scale)); // 限制範圍

        mindmapRoot.style.transform =
            `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    });

    // ==================================================
    // 🖱️ 滑鼠拖曳平移
    // ==================================================
    container.addEventListener("mousedown", (e) => {
        isDragging = true;
        startX = e.clientX - offsetX;
        startY = e.clientY - offsetY;
    });

    container.addEventListener("mousemove", (e) => {
        if (!isDragging) return;
        offsetX = e.clientX - startX;
        offsetY = e.clientY - startY;

        mindmapRoot.style.transform =
            `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    });

    document.addEventListener("mouseup", () => { isDragging = false; });

    // ==================================================
    // 📱 手機觸控平移
    // ==================================================
    container.addEventListener("touchstart", (e) => {
        const t = e.touches[0];
        isDragging = true;
        startX = t.clientX - offsetX;
        startY = t.clientY - offsetY;
    });

    container.addEventListener("touchmove", (e) => {
        if (!isDragging) return;
        const t = e.touches[0];

        offsetX = t.clientX - startX;
        offsetY = t.clientY - startY;

        mindmapRoot.style.transform =
            `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    });

    container.addEventListener("touchend", () => { isDragging = false; });

    // ==================================================
    // 🔘 按鈕縮放控制（你的 UI 專用）
    // ==================================================
    const zoomIn = document.getElementById("mmZoomInBtn");
    const zoomOut = document.getElementById("mmZoomOutBtn");
    const zoomReset = document.getElementById("mmZoomResetBtn");

    if (zoomIn) {
        zoomIn.onclick = () => {
            scale = Math.min(scale + 0.1, 3);
            mindmapRoot.style.transform =
                `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
        };
    }

    if (zoomOut) {
        zoomOut.onclick = () => {
            scale = Math.max(scale - 0.1, 0.3);
            mindmapRoot.style.transform =
                `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
        };
    }

    if (zoomReset) {
        zoomReset.onclick = () => {
            scale = 1;
            offsetX = 0;
            offsetY = 0;
            mindmapRoot.style.transform =
                `translate(0px, 0px) scale(1)`;
        };
    }
}








// 流程圖
function renderFlowchartWithInteraction(rawData) {
    const area = document.getElementById("flowchartArea");
    area.innerHTML = "";

    // -----------------------------
    // 🔥 最佳化文字換行（不破中文字）
    // -----------------------------
   function wrapText(text) {
        return text || "";
    }

    const data = typeof rawData === "string" ? JSON.parse(rawData) : rawData;
    if (!data?.nodes?.length) {
        area.innerHTML = "<p class='text-muted'>⚠️ 沒有流程圖資料</p>";
        return;
    }

    const wrapper = document.getElementById("flowchartWrapper");
    wrapper.style.minHeight = "420px";
    wrapper.style.minWidth = "100%";

    let def = "";
    data.nodes.forEach(n => {
        const t = (n.type || "").toLowerCase();
        const typ =
            t === "start" ? "start" :
            t === "end" ? "end" :
            t === "io" ? "inputoutput" :
            t === "decision" ? "condition" : "operation";

        // 使用 wrapText 進行自動換行
        def += `${n.id}=>${typ}: ${wrapText(n.text)}\n`;
    });

    data.edges.forEach(e => {
        const lbl = (e.label || "").toLowerCase();
        def += `${e.from}${
            lbl.includes("yes") || lbl.includes("是") ? "(yes)" :
            lbl.includes("no")  || lbl.includes("否") ? "(no)" : ""
        }->${e.to}\n`;
    });

    const chart = flowchart.parse(def);
    area.innerHTML = "";

    chart.drawSVG("flowchartArea", {
        "line-width": 2,
        "font-size": 14,
        "line-color": "#444",
        "element-color": "#2196F3",
        "fill": "#fff",
        "arrow-end": "block",
        "symbols": {
            "start": { "fill": "#5cb85c", "font-color": "#fff" },
            "end": { "fill": "#d9534f", "font-color": "#fff" },
            "condition": { "fill": "#FFDE63" },
            "inputoutput": { "fill": "#BFD7FF" },
            "operation": { "fill": "#E3F2FD" }
        }
    });

    // 移除連結點擊
    setTimeout(() => {
        const anchors = area.querySelectorAll("svg a");
        anchors.forEach(a => {
            a.removeAttribute("href");
            a.style.cursor = "default";
            a.onclick = (e) => e.preventDefault();
        });
    }, 50);

    // ====== 🎚️ 縮放控制 ======
    let scale = 1;
    const svg = area.querySelector("svg");
    let offsetX = 0, offsetY = 0;

    svg.style.transformOrigin = "0 0";

    document.getElementById("zoomInBtn").onclick = () => {
        scale += 0.1;
        svg.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    };
    document.getElementById("zoomOutBtn").onclick = () => {
        scale = Math.max(0.2, scale - 0.1);
        svg.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    };
    document.getElementById("zoomResetBtn").onclick = () => {
        scale = 1;
        offsetX = 0;
        offsetY = 0;
        svg.style.transform = `translate(0px, 0px) scale(1)`;
    };

    // ====== 🖱️ 拖曳 Pan ======
    let isPanning = false;
    let startX = 0, startY = 0;

    area.onmousedown = (e) => {
        isPanning = true;
        startX = e.clientX - offsetX;
        startY = e.clientY - offsetY;
    };

    area.onmousemove = (e) => {
        if (!isPanning) return;
        offsetX = e.clientX - startX;
        offsetY = e.clientY - startY;
        svg.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    };

    document.onmouseup = () => {
        isPanning = false;
    };

    // 手機支援
    area.ontouchstart = (e) => {
        isPanning = true;
        const t = e.touches[0];
        startX = t.clientX - offsetX;
        startY = t.clientY - offsetY;
    };

    area.ontouchmove = (e) => {
        if (!isPanning) return;
        const t = e.touches[0];
        offsetX = t.clientX - startX;
        offsetY = t.clientY - startY;
        svg.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    };

    area.ontouchend = () => {
        isPanning = false;
    };
}



document.getElementById("mindmap-tab").addEventListener("shown.bs.tab", () => {
    renderMindmap(mindmapData);
});

document.getElementById("flowchart-tab").addEventListener("shown.bs.tab", () => {
    renderFlowchartWithInteraction(flowchartData);
});



// 監聽 Tab 切換 → 紀錄學生操作


// === 🪄 初始化 ===
let mindmapClicks = 0;
let flowchartClicks = 0;
let aiHintClicks = 0;
const viewedTypesSet = new Set();

// 防止事件重複綁定
window._clickBound = window._clickBound || { mindmap: false, flowchart: false, aihint: false };

window.actionCooldown = window.actionCooldown || {};

// 🧩 封裝統一紀錄函式（防止短時間重複紀錄）
function recordAction(type) {
  const now = Date.now();

  // 若同類型行為在 1 秒內重複觸發，就忽略
  if (actionCooldown[type] && now - actionCooldown[type] < 1000) return;
  actionCooldown[type] = now;

  viewedTypesSet.add(type);
  if (type === "mindmap") mindmapClicks++;
  if (type === "flowchart") flowchartClicks++;
  if (type === "aihint") aiHintClicks++;

  // ✅ 送出後端 log_action.php
  fetch("log_action.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      question_id: <?= $questionId ?>,
      user_id: <?= $userId ?? 0 ?>,
      action: type,
      timestamp: new Date().toISOString()
    })
  }).catch(err => console.warn("⚠️ log_action 傳送失敗", err));
}

// 🪩 小動畫
function bounceTab(tabEl) {
  tabEl.style.transition = "transform 0.2s ease";
  tabEl.style.transform = "scale(1.1)";
  setTimeout(() => (tabEl.style.transform = "scale(1)"), 200);
}

// === 📘 心智圖 Tab ===
const mindmapTab = document.getElementById("mindmap-tab");
if (mindmapTab && !window._clickBound.mindmap) {
  window._clickBound.mindmap = true;
  mindmapTab.addEventListener("shown.bs.tab", (e) => {
    playSound("soundClick2", 0.6);
    bounceTab(e.target);
    renderMindmap(mindmapData);
    recordAction("mindmap");
  });
}

// === 🔄 流程圖 Tab ===
const flowchartTab = document.getElementById("flowchart-tab");
if (flowchartTab && !window._clickBound.flowchart) {
  window._clickBound.flowchart = true;
  flowchartTab.addEventListener("shown.bs.tab", (e) => {
    playSound("soundClick2", 0.6);
    bounceTab(e.target);
    renderFlowchartWithInteraction(flowchartData);
    recordAction("flowchart");
  });
}

// === 🤖 AI 提示按鈕 + 限制 3 次 ===
const aiHintBtn = document.getElementById("aiHintBtn");
const aiHintArea = document.getElementById("aiHintArea");
const CURRENT_QUESTION_ID = <?= $questionId ?>;

// 取得目前 AI 提示使用次數
async function updateAIHintCount() {
    const res = await fetch("check_aihint_count.php?question_id=" + CURRENT_QUESTION_ID);
    const data = await res.json();
    if (handleSessionExpired(data)) return;


    const used = data.used ?? 0;
    const limit = 3;

    const label = document.getElementById("aiHintCountLabel");
    if (label) label.innerText = `（${used}/${limit}）`;

    // 已達上限 → 停用按鈕
    if (used >= limit) {
        aiHintBtn.disabled = true;
        aiHintBtn.classList.remove("btn-warning");
        aiHintBtn.classList.add("btn-secondary");
        aiHintBtn.innerHTML = "🤖 AI提示（已達上限）";
    }
}

// 頁面載入先更新一次
updateAIHintCount();


if (aiHintBtn && !window._clickBound.aihint) {
    window._clickBound.aihint = true;

    aiHintBtn.addEventListener("click", async () => {

        // 再次確認剩餘次數
        const res = await fetch("check_aihint_count.php?question_id=" + CURRENT_QUESTION_ID);
        const data = await res.json();
        if (handleSessionExpired(data)) return;
        const used = data.used ?? 0;
        const limit = 3;

        if (used >= limit) {
            aiHintBtn.disabled = true;
            aiHintBtn.innerHTML = "🤖 AI提示（已達上限）";
            playSound("soundError", 0.8);
            return;
        }

        playSound("soundClick", 0.6);

        // 切換到 AI 提示頁籤
        const aiTab = new bootstrap.Tab(document.getElementById("aihint-tab"));
        aiTab.show();

        // 顯示 loading UI
        aiHintArea.innerHTML = `
            <div class="text-center text-secondary p-4">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"></div>
                <p class="mt-3 fw-bold">AI 助教正在生成提示中...</p>
            </div>
        `;

        // 收集學生程式碼
        const studentCode = Array.from(codeList.children)
            .map(li =>
                " ".repeat((parseInt(li.getAttribute("data-indent")) || 0) * 4) +
                li.innerText.replace(/\u200B/g, "").trim()
            )
            .join("\n");

        const correctCode = codeLines.join("\n");

        try {
            // 呼叫 AI
            const resp = await fetch("ai_feedback_step.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    question_title: <?= json_encode($question['title'] ?? '') ?>,
                    question_desc: <?= json_encode($question['description'] ?? '') ?>,
                    student_code: studentCode,
                    correct_code: correctCode,
                    avg_attempts: <?= json_encode($avgAttempts ?? 2.0) ?>,
                    hint_count: used + 1 
                })
            });

            const json = await resp.json().catch(() => null);
            if (json && handleSessionExpired(json)) return;

            const result = json;


            if (result) {
                playSound("soundSuccess", 0.8);

                if (result.step1)
                    result.step1 = result.step1.replace(/^step\s*1[:：\-\.]\s*/i, "");
                if (result.step2)
                    result.step2 = result.step2.replace(/^step\s*2[:：\-\.]\s*/i, "");

                aiHintArea.innerHTML = `
                    <div class="aihint-wrapper fade-in">
                        <h6>🪜 第一步</h6>
                        <pre>${result.step1}</pre>

                        <div id="step2Container" style="display:none;">
                            <h6>💡 第二步</h6>
                            <pre>${result.step2}</pre>
                        </div>

                        <div class="text-center mt-2">
                            <button id="showMoreHintBtn" class="btn btn-outline-primary btn-sm">
                                顯示更多提示
                            </button>
                        </div>
                    </div>
                `;

                document.getElementById("showMoreHintBtn")?.addEventListener("click", () => {
                    document.getElementById("step2Container").style.display = "block";
                    playSound("soundClick2", 0.7);
                    document.getElementById("showMoreHintBtn").remove();
                });

                // ⭐⭐⭐ 這裡才是唯一一次記錄（含 ai_comment） ⭐⭐⭐
                fetch("log_action.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        question_id: CURRENT_QUESTION_ID,
                        action: "aihint",
                        code: studentCode,
                        ai_comment: result.step1 + "\n\n" + (result.step2 || "")
                    })
                })
                .then(res => res.json())
                .then(json => {
                    if (handleSessionExpired(json)) return;
                });


                // 更新次數顯示
                setTimeout(updateAIHintCount, 200);
            }

        } catch (err) {
            aiHintArea.innerHTML = `<p class="text-danger">💥 發生錯誤：${err.message}</p>`;
            playSound("soundError", 0.8);
        }
    });

}






// === ✅ 提交答案 ===
// === ✅ 提交答案 ===
const submitBtn = document.getElementById("submitOrder");
if (submitBtn) {
submitBtn.addEventListener("click", async () => {

    const checkResult = await compareCodeOrder();
    if (!checkResult || typeof checkResult.result === "undefined") return;

    const isCorrect = checkResult.result;
    const humanMsg = checkResult.message || "";
    playSound("soundClick", 0.6);

    const timeSpent = Math.floor((Date.now() - startTime) / 1000);
    const studentCode = Array.from(codeList.children)
        .map(li => " ".repeat((parseInt(li.getAttribute("data-indent")) || 0) * 4)
        + li.innerText.replace(/\u200B/g, "").trim())
        .join("\n");
    const aiComment = aiHintArea?.innerText?.trim() || "";
    const viewedTypes = Array.from(viewedTypesSet);

    const payload = {
        question_id: <?= $questionId ?>,
        is_correct: isCorrect ? 1 : 0,
        time_spent: timeSpent,
        code: studentCode,
        mindmap_clicks: mindmapClicks,
        flowchart_clicks: flowchartClicks,
        aiHint_clicks: aiHintClicks,
        viewed_types: JSON.stringify(viewedTypes),
        used_ai_visual: viewedTypes.includes("mindmap") || viewedTypes.includes("flowchart"),
        ai_comment: aiComment,
        test_group_id: <?= isset($testGroupId) ? (int)$testGroupId : 'null' ?>
    };

    try {
        const res = await fetch("save_answer.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        console.log("✅ 儲存結果：", data);

        if (!isCorrect) return;

        playSound("soundCorrect", 1);
        await Swal.fire({
            icon: "success",
            title: "✅ 正確",
            text: humanMsg,
            timer: 1000,
            showConfirmButton: false
        });

        const usedTools = [];
        if (viewedTypesSet.has("mindmap")) usedTools.push("mindmap");
        if (viewedTypesSet.has("flowchart")) usedTools.push("flowchart");

        <?php if ($testGroupId): ?>
            const nextUrl = <?= $nextGuidInGroup
                ? json_encode("practice_drag.php?guid={$nextGuidInGroup}&test_group_id={$testGroupId}") 
                : json_encode("quiz.php?set={$testGroupId}&done=1")
            ?>;
        <?php else: ?>
            const nextUrl = <?= $nextGuid
                ? json_encode("practice_drag.php?guid={$nextGuid}") 
                : ($nextChapterFirstGuid
                    ? json_encode("practice_drag.php?guid={$nextChapterFirstGuid}") 
                    : json_encode("practice_list.php?chapter={$chapterId}&done=1"))
            ?>;
        <?php endif; ?>

        if (usedTools.length > 0) {
            try {
                const feedbackCheck = await fetch(`check_feedback.php?question_id=<?= $questionId ?>`);
                const feedbackData = await feedbackCheck.json();

                const remainingTools = usedTools.filter(
                    t => !(feedbackData.answered || []).includes(t)
                );

                const lockedQid = localStorage.getItem("feedback_lock_question");
                if (lockedQid && Number(lockedQid) === <?= $questionId ?>) {
                    await Swal.fire({
                        icon: "warning",
                        title: "⚠️ 尚未完成問卷",
                        text: "請先完成問卷才能進入下一題！"
                    });
                    return;
                }

                for (const toolType of remainingTools) {
                    await showFeedbackModal(toolType, <?= $questionId ?>);

                    const stillLocked = localStorage.getItem("feedback_lock_question");
                    if (stillLocked) {
                        await Swal.fire({
                            icon: "warning",
                            title: "⚠️ 尚未完成問卷",
                            text: "請先完成問卷才能繼續。"
                        });
                        return;
                    }
                }

                await Swal.fire({
                    icon: "success",
                    title: "✅ 已完成所有問卷",
                    text: "感謝你的回饋！即將進入下一題～",
                    timer: 1200,
                    showConfirmButton: false
                });

                window.location.href = nextUrl;
                return;

            } catch (err) {
                console.error("💥 問卷流程錯誤：", err);
                await Swal.fire({
                    icon: "error",
                    title: "💥 無法載入問卷",
                    text: "伺服器錯誤，將直接跳至下一題。"
                });
                window.location.href = nextUrl;
                return;
            }
        }

        window.location.href = nextUrl;

    } catch (err) {
        console.error("💥 儲存錯誤：", err);
        Swal.fire({
            icon: "error",
            title: "💥 系統錯誤",
            text: err.message
        });
    }

});  // 🔥 addEventListener 結束
}     // 🔥 if (submitBtn) 結束







async function compareCodeOrder() {
    try {
        // === Step 1~4. 取得使用者程式結構 ===
        const currentLines = Array.from(codeList.children).map(li => ({
            text: li.innerText.trim(),
            indent: parseInt(li.getAttribute("data-indent")) || 0
        }));

        const correctLines = codeLines.map(line => {
            const spaceCount = line.match(/^\s*/)[0].length;
            const indentLevel = Math.floor(spaceCount / 4);
            return { text: line.trim(), indent: indentLevel };
        });

        const currentTexts = currentLines.map(l => l.text);
        const correctTexts = correctLines.map(l => l.text);
        const orderCorrect = JSON.stringify(currentTexts) === JSON.stringify(correctTexts);

        const userIndentLevels = currentLines.map(l => l.indent);
        const correctIndentLevels = correctLines.map(l => l.indent);
        const indentCorrect = JSON.stringify(userIndentLevels) === JSON.stringify(correctIndentLevels);
        console.groupEnd();

        // === Step 5. 全部正確 ===
        if (orderCorrect && indentCorrect) {
            return { result: true, message: "✅ 排序與縮排都正確！" };
        }

        // === Step 6. 組合完整學生與正確程式 ===
        const studentCode = currentLines.map(l => " ".repeat(l.indent * 4) + l.text).join("\n");
        const correctCode = codeLines.join("\n");
        if (!studentCode.trim() || !correctCode.trim()) {
            Swal.fire({
                icon: "warning",
                title: "⚠️ 無法送出程式碼",
                text: "偵測不到你的程式內容，請重新整理後再試一次。"
            });
            return { result: false, message: "⚠️ 程式內容遺失，請重新整理。" };
        }

        // === Step 7. 人工提示 ===
        let humanMsg = "";
        if (!orderCorrect && indentCorrect) humanMsg = "⚠️ 程式順序錯了幾行，再檢查一下吧！";
        else if (orderCorrect && !indentCorrect) humanMsg = "⚠️ 順序正確，但縮排層級不對喔！";
        else humanMsg = "💡 程式順序與縮排都有錯誤，請再試一次！";

        // === Step 8. 顯示人工提示 ===
        playSound("soundError", 0.8);
        await Swal.fire({
            icon: "error",
            title: "❌ 錯誤",
            text: humanMsg,
            confirmButtonText: "知道了"
        });

        // === Step 9. 回傳人工檢查結果 ===
        return { result: false, message: humanMsg };

    } catch (err) {
        console.error("💥 compareCodeOrder 錯誤：", err);
        Swal.close();
        Swal.fire({
            icon: "error",
            title: "💥 系統錯誤",
            text: err.message
        });
        return { result: false, message: "💥 compareCodeOrder 錯誤：" + err.message };
    }
}


// === ⏱️ 限時僅在測驗模式啟用 ===
const isExamMode = <?= $isExamMode ? 'true' : 'false' ?>;

if (isExamMode) {
  const storageKey = "quiz_timer_<?= (int)($_GET['test_group_id'] ?? 0) ?>";
  let timeLeft = parseInt(localStorage.getItem(storageKey) || 0);

  if (timeLeft > 0) {
    const timerBox = document.createElement("div");
    timerBox.id = "timerBox";
    timerBox.className = "text-center mb-3 fs-5 fw-bold text-danger";
    document.body.prepend(timerBox);

    function updateTimer() {
      const min = Math.floor(timeLeft / 60);
      const sec = timeLeft % 60;
      timerBox.textContent = `⏰ 剩餘時間：${min}:${sec.toString().padStart(2, "0")}`;
      localStorage.setItem(storageKey, timeLeft);

      if (timeLeft <= 0) {
        clearInterval(timer);
        localStorage.setItem(storageKey, 0);
        Swal.fire({
          icon: "warning",
          title: "時間到！",
          text: "測驗時間已結束，系統將返回題組頁。",
        }).then(() => {
          window.location.href = "quiz_select.php";
        });
      }
      timeLeft--;
    }

    updateTimer();
    const timer = setInterval(updateTimer, 1000);
  } else {
    // 測驗模式下若已超時，直接提示並返回題組
    Swal.fire({
      icon: "error",
      title: "測驗已超時",
      text: "此題組的限時已結束。",
    }).then(() => {
      window.location.href = "quiz_select.php";
    });
  }
}

// =============================
// 🔐 統一 Session 過期檢查功能
// =============================
function handleSessionExpired(json) {
    if (json && json.session_expired) {
        Swal.fire({
            icon: "warning",
            title: "登入已失效",
            text: "請重新登入後再繼續使用。",
            allowOutsideClick: false
        }).then(() => {
            window.location.href = "index.php";
        });
        return true; // 表示已處理
    }
    return false; // session 正常
}





                
</script>
</body>
</html>


