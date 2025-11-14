<?php
session_start();

require 'db.php';



// ======================================
// 1. 使用者資訊與模式判斷
// ======================================
$userId = $_SESSION['user_id'] ?? 1;
$isExamMode = (isset($_GET['test_group_id']) && (int)$_GET['test_group_id'] > 0);
$testGroupId = $isExamMode ? (int)$_GET['test_group_id'] : null;

// 需要題目 ID
if (!isset($_GET['question_id'])) {
    die("❌ 請提供題目 ID，例如：practice_drag.php?question_id=1");
}
$questionId = (int)$_GET['question_id'];

// ======================================
// 2. 讀取題目內容
// ======================================
$stmt = $conn->prepare("SELECT * FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) {
    die("❌ 找不到這個題目 (ID: $questionId)");
}

$chapterId     = (int)$question['chapter'];
$testCases     = json_decode($question['test_cases'], true) ?? [];
$codeLines     = json_decode($question['code_lines'], true) ?? [];
$mindmapJson   = $question['mindmap_json'] ?? null;
$flowchartJson = $question['flowchart_json'] ?? null;

// ======================================
// 3. 找上一題
// ======================================
$stmt = $conn->prepare("
    SELECT id FROM questions 
    WHERE chapter=? AND id<? 
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$prevId = $row['id'] ?? null;

// ======================================
// 4. 找下一題
// ======================================
$stmt = $conn->prepare("
    SELECT id FROM questions 
    WHERE chapter=? AND id>? 
    ORDER BY id ASC LIMIT 1
");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextId = $row['id'] ?? null;

// ======================================
// 5. 找下一章節的第一題
// ======================================
$nextChap = $chapterId + 1;

$stmt = $conn->prepare("
    SELECT id FROM questions 
    WHERE chapter=? 
    ORDER BY id ASC LIMIT 1
");
$stmt->bind_param("i", $nextChap);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextChapterFirstQId = $row['id'] ?? null;

// ======================================
// 6. 章節題目進度（僅練習模式）
// ======================================
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM questions WHERE chapter=?) AS total,
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
    WHERE q.chapter = ?
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
    <audio id="soundClick" src="sounds/click.mp3" preload="auto"></audio>
    <audio id="soundClick2" src="sounds/click2.mp3?v=1" preload="auto"></audio>
    <audio id="soundSuccess" src="sounds/success.mp3" preload="auto"></audio>
    <audio id="soundError" src="sounds/error.mp3?v=1" preload="auto"></audio>
    <audio id="soundHover" src="sounds/hover.mp3?v=1" preload="auto"></audio>
    <audio id="soundSelect" src="sounds/select.mp3" preload="auto"></audio>
    <audio id="soundIndent" src="sounds/indent.mp3?v=1" preload="auto"></audio>
    <audio id="soundOutdent" src="sounds/outdent.mp3" preload="auto"></audio>
    <audio id="soundCorrect" src="sounds/correct.mp3" preload="auto"></audio>
    <audio id="soundMove" src="sounds/move.mp3?v=1" preload="auto"></audio>
    <link rel="stylesheet" href="style_practice_drag.css?v=2.0">
    <script src="feedback_modal.js?v=1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="anime-yellow-theme.css?v=3.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.legacy.min.js"></script>

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
                    <p class="text-muted small">
                        你的章節平均嘗試次數：<?= round($avgAttempts,2) ?>  
                        → 本次打亂<strong><?= $linesToShuffle ?></strong> 行
                    </p>
                    <ul id="codeList" class="list-group mb-3"></ul>
                    <div class="d-flex flex-wrap gap-2">
                        <button id="submitOrder" class="btn  btn-submitting">✅ 提交答案</button>
                        <?php if (!$isExamMode): ?>
                            <button id="aiHintBtn" class="btn btn-warning">🤖 AI提示</button>
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
                                    <a href="practice_drag.php?question_id=<?= $prevId ?>" class="btn-cute btn-nav">⬅上一題</a>
                                <?php endif; ?>

                                <?php if ($nextId): ?>
                                    <a href="practice_drag.php?question_id=<?= $nextId ?>" class="btn-cute btn-nav">下一題➡</a>
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
console.log("行號對應表:", lineMap);
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

function addButtonEffect(btnId) {
    const btn = document.getElementById(btnId);
    btn.classList.add("btn-animate");
    setTimeout(() => btn.classList.remove("btn-animate"), 300); // 移除動畫 class
}

indentBtn.addEventListener("click", () => {
    if (!selectedLine) return;
    addButtonEffect("indentBtn"); // 🪄 動畫＋音效
    playSound("soundOutdent", 0.5);

    let indent = parseInt(selectedLine.getAttribute("data-indent")) || 0;
    if (indent >= 5) {
        Swal.fire({
            icon: "info",
            title: "縮排已達上限",
            text: "最多只能縮排 5 層喔！",
            timer: 1500,
            showConfirmButton: false
        });
        return;
    }
    selectedLine.setAttribute("data-indent", indent + 1);
});

outdentBtn.addEventListener("click", () => {
    if (!selectedLine) return;
    addButtonEffect("outdentBtn"); // 🪄 動畫＋音效
    playSound("soundIndent", 0.5);

    let indent = parseInt(selectedLine.getAttribute("data-indent")) || 0;
    if (indent <= 0) {
        Swal.fire({
            icon: "info",
            title: "已經在最左邊囉！",
            text: "縮排層級不能小於 0。",
            timer: 1500,
            showConfirmButton: false
        });
        return;
    }
    selectedLine.setAttribute("data-indent", indent - 1);
});


document.addEventListener("keydown", e => {
    if (!selectedLine) return;

    if (e.key === "Tab") {
        e.preventDefault();
        if (e.shiftKey) {
            // 反縮排
            let indent = parseInt(selectedLine.getAttribute("data-indent"));
            playSound("soundOutdent", 0.5);
            if (indent > 0) selectedLine.setAttribute("data-indent", indent - 1);
        } else {
            // 縮排
            let indent = parseInt(selectedLine.getAttribute("data-indent"));
            playSound("soundIndent", 0.5);
            selectedLine.setAttribute("data-indent", indent + 1);
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
function renderMindmap(data){
    const container = document.getElementById("mindmapArea");
    container.innerHTML = "";

    if(!data){
        container.innerHTML = "⚠️ 沒有心智圖資料";
        return;
    }

    const options = { 
        container:'mindmapArea', 
        editable:false, 
        theme:'primary' 
    };
    const jm = new jsMind(options);
    jm.show(data);

    // 🔹 讓節點支援換行
    container.querySelectorAll("jmnode").forEach(node => {
        node.style.whiteSpace = "normal";
        node.style.wordBreak = "break-word";
        node.style.maxWidth = "220px";
        node.style.lineHeight = "1.4";
        node.style.padding = "4px 8px";
    });
    const mindmapTab = document.getElementById("mindmap-tab");
    mindmapTab.addEventListener("shown.bs.tab", () => {
        setTimeout(() => jm.resize(), 300);
    });

    // 🔹 根據容器大小自動縮放
    setTimeout(() => {
        const svg = container.querySelector("svg");
        if (svg) {
            const bbox = svg.getBBox();
            const newHeight = bbox.height + 80; // 給一點 padding
            container.style.height = newHeight + "px";

            // 同時讓外層 card-body 自適應
            const cardBody = container.closest(".card-body");
            if (cardBody) {
                cardBody.style.height = "auto";
            }
        }
    }, 300);
}





// 流程圖互動 + 程式碼高亮  ===
function renderFlowchartWithInteraction(rawData) {
  
  const area = document.getElementById("flowchartArea");
  area.innerHTML = "";
  const data = typeof rawData === "string" ? JSON.parse(rawData) : rawData;
  if (!data?.nodes?.length) return (area.innerHTML = "⚠️ 沒有流程圖資料");

  // === 生成 flowchart 定義 ===
  let def = "";
  data.nodes.forEach(n => {
    const t = (n.type || "").toLowerCase();
    def += `${n.id}=>${t === "start" ? "start" :
      t === "end" ? "end" :
      t === "io" ? "inputoutput" :
      t === "decision" ? "condition" : "operation"}: ${n.text}\n`;
  });
  data.edges.forEach(e => {
    const lbl = (e.label || "").toLowerCase();
    def += `${e.from}${lbl === "yes" || lbl === "是" ? "(yes)" :
      lbl === "no" || lbl === "否" ? "(no)" : ""}->${e.to}\n`;
  });

  try {
    const chart = flowchart.parse(def);
    chart.drawSVG("flowchartArea", {
      "line-width": 2, "font-size": 14,
      "arrow-end": "block", "line-color": "#444",
      "element-color": "#2196F3", "fill": "#fff",
      "symbols": {
        "start": { "fill": "#5cb85c", "font-color": "#fff" },
        "end": { "fill": "#d9534f", "font-color": "#fff" },
        "condition": { "fill": "#FFDE63" },
        "inputoutput": { "fill": "#BFD7FF" },
        "operation": { "fill": "#E3F2FD" }
      }
    });
  } catch (err) {
    return (area.innerHTML = `<div class='text-danger p-3'>繪製錯誤：${err.message}</div>`);
  }

  // === 綁定互動 ===
    setTimeout(() => {
    const svg = area.querySelector("svg");
    if (!svg) {
        svg.setAttribute("width", "100%");
        svg.style.maxWidth = "100%";}
    const rects = svg.querySelectorAll("rect, path, polygon");
    rects.forEach(shape => {
        const id = shape.getAttribute("id");
        const textEl = svg.querySelector(`[id='${id}t']`);
        const label = textEl ? textEl.textContent.trim() : "";
        if (!label || label === "是" || label === "否") return;

        // 對應 flowchartData 中的節點
        const node = data.nodes.find(n => label.includes(n.text.slice(0, 4)));
        if (!node || !node.line) return;

        // hover 效果
        [shape, textEl].forEach(el => {
        if (!el) return;
        el.style.cursor = "pointer";
        el.addEventListener("mouseenter", () => {
            if (!node.line) return; // 沒有對應行 → 不做 highlight
            shape.style.stroke = "#FFC107";
            shape.style.strokeWidth = "3px";
            shape.style.filter = "drop-shadow(0 0 6px rgba(255,193,7,0.8))";

            // 🟡 滑過時暫時高亮對應行
            // ✅ 根據 lineMap 對應「原始行 → 打亂後行」
            // 若 node.line 不存在（例如開始/結束），則僅顯示節點高亮，不比對程式碼
            const map = window.lineMap || {};
            if (!node.line) {
                // 只亮節點，不找 code
                shape.style.stroke = "#FFD54F";
                shape.style.strokeWidth = "4px";
                shape.style.filter = "drop-shadow(0 0 10px rgba(255,215,0,0.9))";
                shape.style.transition = "all 0.25s ease";
                return; // 🚫 不執行下面程式碼
            }

            const correctLine = parseInt(node.line);
            const targetLine = map[correctLine];

            if (targetLine) {
            const li = document.querySelector(`#codeList li:nth-child(${targetLine})`);
            if (li) li.classList.add("highlight-temp");
            }
        });
        el.addEventListener("mouseleave", () => {
            shape.style.stroke = "";
            shape.style.strokeWidth = "";
            shape.style.filter = "";
            document.querySelectorAll(".highlight-temp").forEach(li => li.classList.remove("highlight-temp"));
        });
        });

        // 點擊節點事件
        [shape, textEl].forEach(el => {
        if (!el) return;
        el.addEventListener("click", () => {
            playSound("soundClick", 0.6);

            // 🧹 清除舊高亮（流程圖 + 程式碼）
            svg.querySelectorAll("rect, path, polygon").forEach(s => {
            s.style.stroke = "";
            s.style.strokeWidth = "";
            s.style.filter = "";
            });
            document.querySelectorAll(".code-line").forEach(li => li.classList.remove("highlight"));

            // 🌟 高亮目前節點
            shape.style.stroke = "#FFD54F";
            shape.style.strokeWidth = "4px";
            shape.style.filter = "drop-shadow(0 0 10px rgba(255,215,0,0.9))";
            shape.style.transition = "all 0.25s ease";

            // ✅ 根據 lineMap 對應「原始行 → 打亂後行」
            const map = window.lineMap || {};
            const correctLine = parseInt(node.line);
            const targetLine = map[correctLine];

            console.log(`🔗 節點對應：原始行 ${correctLine} → 顯示行 ${targetLine}`, map);

            if (targetLine) {
            const li = document.querySelector(`#codeList li:nth-child(${targetLine})`);
            if (li) {
                li.classList.add("highlight");
                li.scrollIntoView({ behavior: "smooth", block: "center" });
                playSound("soundSelect", 0.7);
            }
            } else {
            Swal.fire({
                icon: "warning",
                title: "對應不到程式碼",
                text: `此節點（原始行 ${correctLine}）在目前打亂後找不到對應的程式碼。`,
                timer: 1600,
                showConfirmButton: false
            });
            console.warn(`⚠️ 找不到 lineMap 對應行：${correctLine}`, map);
            }
        });
        });
    });

    // ✅ 點程式碼 → 清除流程圖亮光
    const codeListEl = document.getElementById("codeList");
    if (!codeListEl._flowBound) {
        codeListEl.addEventListener("click", e => {
        const clicked = e.target.closest(".code-line");
        if (!clicked) return;

        // 清除流程圖的高亮
        svg.querySelectorAll("rect, path, polygon").forEach(s => {
            s.style.stroke = "";
            s.style.strokeWidth = "";
            s.style.filter = "";
        });

        // 僅保留當前選取程式碼的高亮
        document.querySelectorAll(".code-line.highlight").forEach(li => {
            if (li !== clicked) li.classList.remove("highlight");
        });
        });
        codeListEl._flowBound = true;
    }
    }, 400);
}




// 監聽 Tab 切換 → 紀錄學生操作
function logAction(action) {
    fetch("log_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            question_id: <?= $questionId ?>,
            action: action
        })
    });
}
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


// === 🤖 AI 提示按鈕 ===
// === 🤖 AI 提示按鈕 ===
const aiHintBtn = document.getElementById("aiHintBtn");
if (<?= $isExamMode ? 'true' : 'false' ?>) {
    // 測驗模式：完全停用所有 AI 功能
    if (aiHintBtn) aiHintBtn.style.display = "none";
}
const aiHintArea = document.getElementById("aiHintArea");

if (aiHintBtn && !window._clickBound.aihint) {
  window._clickBound.aihint = true;

  aiHintBtn.addEventListener("click", async () => {
    recordAction("aihint");
    playSound("soundClick", 0.6);

    // 🔹 一按下就自動切換到 AI提示 分頁
    const aiTab = new bootstrap.Tab(document.getElementById("aihint-tab"));
    aiTab.show();

    // 🔹 顯示載入動畫
    aiHintArea.innerHTML = `
      <div class="text-center text-secondary p-4">
        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"></div>
        <p class="mt-3 fw-bold">AI 助教正在生成提示中...</p>
      </div>
    `;

    // 🔹 準備要送出的程式碼
    const studentCode = Array.from(codeList.children)
      .map(li => " ".repeat((parseInt(li.getAttribute("data-indent")) || 0) * 4) + li.innerText.trim())
      .join("\n");
    const correctCode = codeLines.join("\n");

    try {
      const res = await fetch("ai_feedback_step.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          question_title: <?= json_encode($question['title'] ?? '') ?>,
          question_desc: <?= json_encode($question['description'] ?? '') ?>,
          student_code: studentCode,
          correct_code: correctCode,
          avg_attempts: <?= json_encode($avgAttempts ?? 2.0) ?>
        })
      });

      const text = await res.text();
      const clean = text.trim().replace(/^\uFEFF/, "");
      const data = clean.startsWith("{") ? JSON.parse(clean) : null;

      if (data) {
        playSound("soundSuccess", 0.8);

        // 第一步提示（直接顯示）
        const step1 = data.step1
          ? `<h6>🪜 第一步：</h6>
             <pre class="bg-white p-2 border rounded">${data.step1}</pre>`
          : "";

        // 第二步提示（初始隱藏，需按按鈕展開）
        let step2 = "";
        if (data.step2) {
          step2 = `
            <div id="step2Container" style="display:none;">
              <h6>💡 第二步：</h6>
              <pre class="bg-white p-2 border rounded">${data.step2}</pre>
            </div>
            <div class="text-center mt-2">
              <button id="showMoreHintBtn" class="btn btn-outline-primary btn-sm">
                👉 顯示更多提示
              </button>
            </div>
          `;
        } else {
          step2 = `<p class="text-muted">AI 僅提供一階段提示。</p>`;
        }

        // 顯示結果並淡入
        aiHintArea.innerHTML = `
          <div class="text-start fade-in">
            ${step1}
            ${step2}
          </div>
        `;

        aiHintArea.style.opacity = 0;
        setTimeout(() => {
          aiHintArea.style.transition = "opacity 0.6s ease";
          aiHintArea.style.opacity = 1;
        }, 100);

        // 綁定顯示更多提示按鈕
        const showMoreBtn = document.getElementById("showMoreHintBtn");
        if (showMoreBtn) {
          showMoreBtn.addEventListener("click", () => {
            const secondPart = document.getElementById("step2Container");
            if (secondPart) {
              secondPart.style.display = "block";
              showMoreBtn.remove();
              playSound("soundClick2", 0.7);
            }
          });
        }

      } else {
        aiHintArea.innerHTML = `<p class="text-danger">⚠️ 無法取得 AI 提示，請稍後再試。</p>`;
        playSound("soundError", 0.8);
      }
    } catch (err) {
      aiHintArea.innerHTML = `<p class="text-danger">💥 發生錯誤：${err.message}</p>`;
      playSound("soundError", 0.8);
    }
  });
}



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

        // ❌ 答錯時（Swal 已顯示，不再重複）
        if (!isCorrect) return;

        // ✅ 答對
        playSound("soundCorrect", 1);
        await Swal.fire({
            icon: "success",
            title: "✅ 正確",
            text: humanMsg,
            timer: 1000,
            showConfirmButton: false
        });

        // === 🧠 問卷流程 ===
        const usedTools = [];
        if (viewedTypesSet.has("mindmap")) usedTools.push("mindmap");
        if (viewedTypesSet.has("flowchart")) usedTools.push("flowchart");

        <?php if ($testGroupId): ?>
            <?php
                // 🧩 題組模式：由題組 question_ids 控制跳題順序
                $questionIds = json_decode($groupData['question_ids'], true) ?? [];
                $currentIndex = array_search($questionId, $questionIds);
                $nextIdInGroup = $questionIds[$currentIndex + 1] ?? null;
            ?>
            const nextUrl = <?= $nextIdInGroup 
                ? json_encode("practice_drag.php?question_id={$nextIdInGroup}&test_group_id={$testGroupId}") 
                : json_encode("quiz.php?set={$testGroupId}&done=1") ?>;
        <?php else: ?>
            const nextUrl = <?= $nextId 
                ? json_encode("practice_drag.php?question_id={$nextId}") 
                : ($nextChapterFirstQId 
                    ? json_encode("practice_drag.php?question_id={$nextChapterFirstQId}") 
                    : json_encode("practice_list.php?chapter={$chapterId}&done=1")) ?>;
        <?php endif; ?>


        if (usedTools.length > 0) {
            try {
            const feedbackCheck = await fetch(`check_feedback.php?question_id=<?= $questionId ?>`);
            const feedbackData = await feedbackCheck.json();
            const remainingTools = usedTools.filter(t => !(feedbackData.answered || []).includes(t));

            if (remainingTools.length === 0) {
                window.location.href = nextUrl;
                return;
            }

            for (const toolType of remainingTools) {
                await showFeedbackModal(toolType, <?= $questionId ?>);
            }

            await Swal.fire({
                icon: "success",
                title: "✅ 已完成所有問卷",
                text: "感謝你的回饋！即將進入下一題～",
                timer: 1200,
                showConfirmButton: false
            });

            window.location.href = nextUrl;

            } catch (err) {
            console.error("💥 問卷流程錯誤：", err);
            await Swal.fire({
                icon: "error",
                title: "💥 無法載入問卷",
                text: "伺服器錯誤，將直接跳至下一題。"
            });
            window.location.href = nextUrl;
            }

        } else {
            // 🧩 未使用輔助工具 → 直接跳轉
            window.location.href = nextUrl;
        }
        } catch (err) {
        console.error("💥 儲存錯誤：", err);
        Swal.fire({
            icon: "error",
            title: "💥 系統錯誤",
            text: err.message
        });
        }
    });
    }









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

        console.group("🔍 縮排比對檢查");
        console.log("使用者縮排層級：", userIndentLevels);
        console.log("正確縮排層級：", correctIndentLevels);
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









// // === 🌗 深色模式切換功能 (最終版) ===
// (function(){
//   const STORAGE_KEY = 'theme';
//   const btn = document.getElementById('themeToggle');
//   const htmlEl = document.documentElement; // 切在 <html>

//   // 套用主題
//   function applyTheme(mode){
//     if(mode === 'dark'){
//       htmlEl.setAttribute('data-theme', 'dark');
//       if(btn){
//         btn.classList.remove('btn-outline-dark');
//         btn.classList.add('btn-outline-light');
//         btn.innerText = '☀️ 淺色';
//       }
//     } else {
//       htmlEl.removeAttribute('data-theme');
//       if(btn){
//         btn.classList.remove('btn-outline-light');
//         btn.classList.add('btn-outline-dark');
//         btn.innerText = '🌙 深色';
//       }
//     }
//   }

//   // 初始載入（localStorage > 系統偏好 > 預設亮）
//   const saved = localStorage.getItem(STORAGE_KEY);
//   const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
//   const theme = saved || (prefersDark ? 'dark' : 'light');
//   applyTheme(theme);

//   // 切換
//   btn?.addEventListener('click', () => {
//     const now = htmlEl.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
//     const next = now === 'dark' ? 'light' : 'dark';
//     localStorage.setItem(STORAGE_KEY, next);
//     applyTheme(next);
//   });

//   // 跟隨系統偏好變化（如果使用者沒手動選過）
//   window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
//     if(!localStorage.getItem(STORAGE_KEY)){
//       applyTheme(e.matches ? 'dark' : 'light');
//     }
//   });
// })();




                
</script>
</body>
</html>


