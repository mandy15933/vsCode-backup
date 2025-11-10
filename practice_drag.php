<?php
session_start();

require 'db.php';

// 🔹 手動控制實驗週次
$week = 2;   // 第一週：只顯示拖曳 + 測資
// $week = 2;   // 第二週：開放心智圖與流程圖


// 假設已登入
$userId = $_SESSION['user_id'] ?? 1;
$testGroupId = $_GET['test_group_id'] ?? null;

// 取得指定題目 ID
if (!isset($_GET['question_id'])) {
    die("❌ 請提供題目 ID，例如：practice_drag.php?question_id=1");
}
$questionId = (int)$_GET['question_id'];

// 讀取題目
$stmt = $conn->prepare("SELECT * FROM questions WHERE id=?");
$stmt->bind_param("i", $questionId);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) {
    die("❌ 找不到這個題目 (ID: $questionId)");
}

$chapterId = $question['chapter'];
$testCases = json_decode($question['test_cases'], true) ?? [];
$codeLines = json_decode($question['code_lines'], true) ?? [];
$mindmapJson   = $question['mindmap_json'] ?? null;
$flowchartJson = $question['flowchart_json'] ?? null;

// 找上一題
$stmt = $conn->prepare("SELECT id FROM questions WHERE chapter=? AND id<? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$prevQuestion = $stmt->get_result()->fetch_assoc();
$stmt->close();
$prevId = $prevQuestion['id'] ?? null;

// 找下一題
$stmt = $conn->prepare("SELECT id FROM questions WHERE chapter=? AND id>? ORDER BY id ASC LIMIT 1");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$nextQuestion = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nextId = $nextQuestion['id'] ?? null;

// 找下一章節的第一題
$stmt = $conn->prepare("
    SELECT id FROM questions 
    WHERE chapter = ? 
    ORDER BY id ASC LIMIT 1
");
$nextChap = $chapterId + 1;
$stmt->bind_param("i", $nextChap);
$stmt->execute();
$nextChapRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextChapterFirstQId = $nextChapRow['id'] ?? null;

// 查詢是否還有未完成題目
$stmt = $conn->prepare("
    SELECT id FROM questions 
    WHERE chapter=? AND id>? 
    ORDER BY id ASC LIMIT 1
");
$stmt->bind_param("ii", $chapterId, $questionId);
$stmt->execute();
$nextQuestion = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextId = $nextQuestion['id'] ?? null;

// 🔹 判斷章節題目總數 & 學生已完成題數
$stmt = $conn->prepare("
    SELECT 
      (SELECT COUNT(*) 
         FROM questions 
        WHERE chapter = ?)                                AS total,
      (SELECT COUNT(DISTINCT q.id)
         FROM questions q
         JOIN student_answers sa
           ON sa.question_id = q.id
          AND sa.user_id = ?
          AND sa.is_correct = 1
        WHERE q.chapter = ?)                              AS done
");
$stmt->bind_param("iii", $chapterId, $userId, $chapterId);
$stmt->execute();
$progress = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalQuestions = (int)($progress['total'] ?? 0);
$doneQuestions  = (int)($progress['done'] ?? 0);

$chapterFinished = ($doneQuestions >= $totalQuestions);



// 🔹 查詢學生該章節的表現（平均嘗試次數）
$stmt = $conn->prepare("
    SELECT 
        SUM(is_correct=1) AS correct_count,
        COUNT(*) AS total_submissions,
        SUM(attempts) / COUNT(DISTINCT question_id) AS avg_attempts
    FROM student_answers
    WHERE user_id=? AND question_id IN (
        SELECT id FROM questions WHERE chapter=?
    )
");



$stmt->bind_param("ii", $userId, $chapterId);
$stmt->execute();
$chapterStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$avgAttempts = $chapterStats['avg_attempts'] ?? 1;

// 🔹 根據表現決定要打亂的行數
if ($avgAttempts <= 1.2) {
    $linesToShuffle = rand(5, 6); // 高掌握 → 難
} elseif ($avgAttempts <= 2.0) {
    $linesToShuffle = rand(3, 4); // 中等
} else {
    $linesToShuffle = rand(2, 3); // 低掌握 → 簡單
}

// 🔹 取得章節名稱
$stmt = $conn->prepare("SELECT title FROM chapters WHERE id=?");
$stmt->bind_param("i", $chapterId);
$stmt->execute();
$stmt->bind_result($chapterTitle);
$stmt->fetch();
$stmt->close();
// 查詢目前題目是否已通過
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
// ✅ 檢查章節剩餘題目
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
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
                            WHERE user_id=? AND is_correct=1 AND question_id IN ($placeholders)";
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
        <div class="col-lg-6 mb-3">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">💻 拖曳程式碼區域</h5>
                    <button id="themeToggle" class="btn btn-outline-light btn-sm" type="button">
                        🌙 深色
                    </button>
                </div>

                <div class="card-body">
                    <p class="text-muted small">
                        你的章節平均嘗試次數：<?= round($avgAttempts,2) ?>  
                        → 本次打亂<strong><?= $linesToShuffle ?></strong> 行
                    </p>
                    <ul id="codeList" class="list-group mb-3"></ul>
                    <div class="d-flex gap-2">
                        <button id="submitOrder" class="btn btn-cute btn-submit">✅ 提交答案</button>
                        <button id="indentBtn" class="btn btn-cute btn-outdent">➡ 縮排</button>
                        <button id="outdentBtn" class="btn btn-cute btn-indent">⬅ 反縮排</button>
                        <?php if ($testGroupId): ?>
                            <!-- 🚩 測驗模式下：只顯示返回題組與題組選單 -->
                            <a href="quiz.php?set=<?= $testGroupId ?>" 
                               class="btn btn-outline-success">📘 返回題組</a>
                        <?php else: ?>  <!-- 🚫 測驗模式不顯示上下題 -->
                            <?php if ($prevId): ?>
                                <a href="practice_drag.php?question_id=<?= $prevId ?>" class="btn-cute btn-nav">⬅ 上一題</a>
                            <?php endif; ?>
                            <?php if ($nextId): ?>
                                <a href="practice_drag.php?question_id=<?= $nextId ?>" class="btn-cute btn-nav">下一題 ➡</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 右側：提示區 -->
        <div class="col-lg-6 mb-3">
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

                        <?php if ($week >= 2): ?>
                            <li class="nav-item">
                                <button class="nav-link text-dark" id="mindmap-tab" data-bs-toggle="tab"
                                    data-bs-target="#mindmapPane" type="button" role="tab">🌐 心智圖</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link text-dark" id="flowchart-tab" data-bs-toggle="tab"
                                    data-bs-target="#flowchartPane" type="button" role="tab">🔄 流程圖</button>
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
                            <div id="mindmapArea" style="width:100%;height:400px;border:1px solid #ddd;"></div>
                        </div>
                        <!-- 流程圖 -->
                        <div class="tab-pane fade" id="flowchartPane" role="tabpanel">
                            <div class="d-flex justify-content-center">
                                <div id="flowchartWrapper" class="card shadow-sm border-warning d-inline-block">
                                    <div class="card-body">
                                        <div id="flowchartArea"></div>
                                    </div>
                                </div>
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
const codeList = document.getElementById("codeList");
shuffled.forEach(row => {
  const clean = row.text.replace(/^\s+/, "");
  const li = document.createElement("li");
  li.className = "list-group-item code-line";
  li.setAttribute("data-indent", "0");
  li.innerHTML = `<pre><code class="language-python">${clean}</code></pre>`;
  codeList.appendChild(li);
});

hljs.highlightAll();

// === 拖曳設定 ===
let selectedLine = null;
new Sortable(codeList, {
  animation: 150,
  onEnd: () => playSound("soundMove", 0.3)
});
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

// 啟用拖曳排序
let lastHoverTime = 0; // 防止 hover 音效太密集

new Sortable(codeList, { 
    animation: 150,
    onStart: () => playSound("soundHover"), // 拖曳開始音效

    onMove: (evt) => {
        // 限制音效播放頻率，避免過於頻繁
        const now = Date.now();
        if (now - lastHoverTime > 120) { // 每 0.12 秒才允許播放一次
            playSound("soundMove", 0.25);
            lastHoverTime = now;
        }
    },

    onEnd: (evt) => {
        if (evt.oldIndex !== evt.newIndex) {
            playSound("soundMove", 0.4); // 交換成功音效
        }
    }
});

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
    if (!svg) return;

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
// --- 點擊紀錄 ---
let mindmapClicks = 0;
let flowchartClicks = 0;
let viewedTypes = []; // 用陣列記錄完整操作
let startTime = Date.now(); // 記錄開始時間

// === 🪄 Tabs 切換動畫 + 音效 ===

// 小彈跳動畫效果
function bounceTab(tabEl) {
    tabEl.style.transition = "transform 0.2s ease";
    tabEl.style.transform = "scale(1.1)";
    setTimeout(() => { tabEl.style.transform = "scale(1)"; }, 200);
}

// 📑 測資 tab（一定存在）
const testTab = document.getElementById("test-tab");
if (testTab) {
    testTab.addEventListener("shown.bs.tab", (e) => {
        playSound("soundClick2", 0.6);
        bounceTab(e.target);
    });
}

// 🌐 心智圖 tab（第二週才存在）
const mindmapTab = document.getElementById("mindmap-tab");
if (mindmapTab) {
    mindmapTab.addEventListener("shown.bs.tab", (e) => {
        playSound("soundClick2", 0.6);
        bounceTab(e.target);
        renderMindmap(mindmapData);
        mindmapClicks++;
        viewedTypes.push("mindmap");
    });
}

// 🔄 流程圖 tab（第二週才存在）
const flowchartTab = document.getElementById("flowchart-tab");
if (flowchartTab) {
    flowchartTab.addEventListener("shown.bs.tab", (e) => {
        playSound("soundClick2", 0.6);
        bounceTab(e.target);
        renderFlowchartWithInteraction(flowchartData);
        flowchartClicks++;
        viewedTypes.push("flowchart");
    });
}





const submitBtn = document.getElementById("submitOrder");

if (submitBtn) {
    submitBtn.addEventListener("click", async () => {
        const checkResult = await compareCodeOrder();  // ✅ 等結果回來
        if (!checkResult || typeof checkResult.result === "undefined") return;

        const isCorrect = checkResult.result;
        const humanMsg  = checkResult.message || "";

        playSound("soundClick", 0.6);

        // 🕒 計算作答時間（秒）
        const timeSpent = Math.floor((Date.now() - startTime) / 1000);
        const studentCode = Array.from(codeList.children)
            .map(li => " ".repeat((parseInt(li.getAttribute("data-indent")) || 0) * 4) + li.innerText.trim())
            .join("\n");

        // 📦 組 payload
        const payload = {
            question_id: <?= $questionId ?>,
            is_correct: isCorrect ? 1 : 0,
            time_spent: timeSpent,
            code: studentCode,
            mindmap_clicks: mindmapClicks,
            flowchart_clicks: flowchartClicks,
            viewed_types: viewedTypes,
            test_group_id: <?= $testGroupId ? (int)$testGroupId : 'null' ?>
        };

        // 💾 儲存作答紀錄
        fetch("save_answer.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            console.log("✅ 作答紀錄已儲存：", data);

            if (isCorrect) {
                playSound("soundCorrect", 1);
                <?php if ($testGroupId): ?>
                    Swal.fire({
                        icon: "success",
                        title: "✅ 正確",
                        html: `
                            <p>恭喜答對！</p>
                            <a href="quiz.php?set=<?= $testGroupId ?>" 
                               class="btn btn-outline-success mt-2">返回題組題目列表</a>
                        `,
                        showConfirmButton: false
                    });
                <?php else: ?>
                    Swal.fire({
                        icon: "success",
                        title: "✅ 正確",
                        text: humanMsg,
                        timer: 1500,
                        showConfirmButton: false,
                        willClose: () => {
                            <?php if (!$chapterFinished && $nextId): ?>
                                window.location.href = "practice_drag.php?question_id=<?= $nextId ?>";
                            <?php else: ?>
                                Swal.fire({
                                    icon: "success",
                                    title: "🎉 恭喜",
                                    text: "本章題目已全部完成！",
                                    showDenyButton: true,
                                    confirmButtonText: "確定",
                                    denyButtonText: "➡ 前往下一章節"
                                }).then((result) => {
                                    if (result.isDenied && nextChapterFirstQId) {
                                        window.location.href = "practice_drag.php?question_id=" + nextChapterFirstQId;
                                    }
                                });
                            <?php endif; ?>
                        }
                    });
                <?php endif; ?>
            }
        })
        .catch(err => {
            console.error("💥 儲存作答紀錄失敗：", err);
            Swal.fire({
                icon: "error",
                title: "💥 發生錯誤",
                text: "無法儲存作答紀錄，請稍後再試。"
            });
        });
    });
}

// ✅ 非同步 compareCodeOrder
// ✅ 最終版 compareCodeOrder（含 AI Loading 動畫）
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

        // === Step 8. 顯示人工提示（先） ===
        await Swal.fire({
            icon: "error",
            title: "❌ 錯誤",
            text: humanMsg,
            confirmButtonText: "知道了"
        });

        // === Step 9. 第二週才顯示 AI 提示 ===
        if (<?= $week ?> >= 2) {
            // 🧠 顯示 AI 助教思考中...
            Swal.fire({
                title: "🧠 AI 助教思考中...",
                html: "<b>請稍候，AI 正在分析你的程式邏輯 ⚙️</b>",
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

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

                const raw = await res.text();
                const clean = raw.trim().replace(/^\uFEFF/, "");
                Swal.close(); // ✅ 關閉 loading 畫面

                let data = null;
                if (clean.startsWith("{")) {
                    data = JSON.parse(clean);
                } else {
                    console.warn("⚠️ AI 回傳非 JSON：", clean);
                }

                if (data) {
                    playSound("soundSelect", 0.6);
                    Swal.fire({
                        title: "💭 第一步提示",
                        html: `<pre style="text-align:left;white-space:pre-wrap;">${data.step1 || "AI 暫時無法提供提示"}</pre>`,
                        icon: "question",
                        showDenyButton: true,
                        confirmButtonText: "再給我更多提示 💡",
                        denyButtonText: "我自己想想 💭"
                    }).then(result => {
                        if (result.isConfirmed && data.step2) {
                            playSound("soundClick2", 0.6);
                            Swal.fire({
                                title: "💡 第二步提示",
                                html: `<pre style="text-align:left;white-space:pre-wrap;">${data.step2}</pre>`,
                                icon: "info",
                                width: 600
                            });
                        }
                    });
                } else {
                    Swal.fire({
                        icon: "warning",
                        title: "⚠️ AI 無法提供提示",
                        text: "AI 回傳格式有誤或內容為空，請稍後再試。"
                    });
                }

            } catch (err) {
                Swal.close(); // 保險關閉
                console.error("💥 AI 回饋錯誤：", err);
                Swal.fire({
                    icon: "error",
                    title: "💥 AI 提示發生錯誤",
                    text: "伺服器連線或格式錯誤，請稍後再試。"
                });
            }
        }

        // === Step 10. 最終回傳人工結果 ===
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






// === 🌗 深色模式切換功能 (最終版) ===
(function(){
  const STORAGE_KEY = 'theme';
  const btn = document.getElementById('themeToggle');
  const htmlEl = document.documentElement; // 切在 <html>

  // 套用主題
  function applyTheme(mode){
    if(mode === 'dark'){
      htmlEl.setAttribute('data-theme', 'dark');
      if(btn){
        btn.classList.remove('btn-outline-dark');
        btn.classList.add('btn-outline-light');
        btn.innerText = '☀️ 淺色';
      }
    } else {
      htmlEl.removeAttribute('data-theme');
      if(btn){
        btn.classList.remove('btn-outline-light');
        btn.classList.add('btn-outline-dark');
        btn.innerText = '🌙 深色';
      }
    }
  }

  // 初始載入（localStorage > 系統偏好 > 預設亮）
  const saved = localStorage.getItem(STORAGE_KEY);
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const theme = saved || (prefersDark ? 'dark' : 'light');
  applyTheme(theme);

  // 切換
  btn?.addEventListener('click', () => {
    const now = htmlEl.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = now === 'dark' ? 'light' : 'dark';
    localStorage.setItem(STORAGE_KEY, next);
    applyTheme(next);
  });

  // 跟隨系統偏好變化（如果使用者沒手動選過）
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
    if(!localStorage.getItem(STORAGE_KEY)){
      applyTheme(e.matches ? 'dark' : 'light');
    }
  });
})();




                
</script>
</body>
</html>


