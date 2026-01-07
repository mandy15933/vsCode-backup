<?php
session_start();
// require 'session_protect.php';
require 'db.php';

// ======================================
// 1. 基本參數
// ======================================
$userId = $_SESSION['user_id'];
$learningMode = 'flow_drag';

if (!isset($_GET['guid'])) {
    die('❌ 缺少題目 GUID');
}
$guid = $_GET['guid'];

// ======================================
// 2. 讀取題目
// ======================================
$stmt = $conn->prepare("SELECT * FROM questions WHERE guid=? AND is_hidden=0");
$stmt->bind_param("s", $guid);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) {
    die('❌ 找不到題目');
}

$questionId   = (int)$question['id'];
$title        = $question['title'];
$description  = $question['description'];
$flowSteps    = json_decode($question['flow_steps_json'], true) ?? [];
$mindmapJson  = $question['mindmap_json'] ?? null;
$flowchartJson= $question['flowchart_json'] ?? null;
$testCases    = json_decode($question['test_cases'], true) ?? [];

if (count($flowSteps) < 2) {
    die('⚠️ 本題尚未設定流程步驟');
}

// 打亂流程順序
$shuffledSteps = $flowSteps;
shuffle($shuffledSteps);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>流程排序練習｜<?= htmlspecialchars($title) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- jsMind -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsmind/style/jsmind.css" />
<script src="https://cdn.jsdelivr.net/npm/jsmind/es6/jsmind.js"></script>

<!-- flowchart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.3.0/raphael.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowchart/1.18.0/flowchart.min.js"></script>
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
<link rel="stylesheet" href="font.css?v=1.0">
<link rel="stylesheet" href="anime-yellow-theme.css">
<link rel="stylesheet" href="style_practice_flow.css?v=1.0">

<style>
.flow-step {
    cursor: grab;
    background: #fff;
}
.flow-step.dragging {
    opacity: 0.6;
}
</style>
</head>

<body>  
<?php include 'Navbar.php'; ?>

<!-- 題目 -->
<div class="card mb-4 border-warning">
  <div class="card-header bg-warning">
    <h4 class="mb-0">🧩 <?= htmlspecialchars($title) ?></h4>
  </div>
  <div class="card-body">
    <p><?= nl2br(htmlspecialchars($description)) ?></p>
  </div>
</div>

<div class="row">
<!-- 左：流程拖曳 -->
<div class="col-lg-6 mb-3">
  <div class="card shadow-sm">
    <div class="card-header">
      <h5 class="mb-0">🔀 請拖曳流程步驟排序</h5>
    </div>
    <div class="card-body">

      <ul id="flowList" class="mb-3">
        <?php foreach ($shuffledSteps as $step): ?>
          <li class="flow-step">
             <?= htmlspecialchars($step) ?>
          </li>
        <?php endforeach; ?>
      </ul>

      <button id="submitFlow" class="btn btn-success">
        ✅ 提交答案
      </button>

      <button id="getAiHintBtn" class="btn btn-warning">

        🤖 AI 提示
      </button>

      <a href="practice_list.php?chapter=<?= (int)$question['chapter'] ?>"
         class="btn btn-secondary ms-2">
        返回列表
      </a>

    </div>
  </div>
</div>

<!-- 右：輔助提示 -->
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

<script>
let mindmapData  = <?= json_encode($mindmapJson, JSON_UNESCAPED_UNICODE) ?>;
let flowchartData = <?= json_encode($flowchartJson, JSON_UNESCAPED_UNICODE) ?>;
answer_mode= "flow_practice";
learning_mode= "flow_drag";

function playSound(id, volume = 1) {
    const audio = document.getElementById(id);
    if (audio) {
        audio.currentTime = 0;
        audio.play();
    }
}
// ===============================
// 拖曳排序
// ===============================
const correctOrder = <?= json_encode($flowSteps, JSON_UNESCAPED_UNICODE) ?>;
const list = document.getElementById("flowList");

Sortable.create(list, {
  animation: 150,
  ghostClass: "dragging"
});

// ===============================
// 提交答案
// ===============================
document.getElementById("submitFlow").addEventListener("click", async () => {
  const userOrder = Array.from(list.children)
      .map(li => li.innerText.replace(/^☰\s*/, "").trim());

  const isCorrect = JSON.stringify(userOrder) === JSON.stringify(correctOrder);

  await fetch("save_answer.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      question_id: <?= $questionId ?>,
      is_correct: isCorrect ? 1 : 0,
      answer_mode: "flow_practice",
      learning_mode: "flow_drag"
    })
  });

  if (isCorrect) {
    Swal.fire("✅ 正確", "流程順序完全正確！", "success");
  } else {
    Swal.fire("❌ 錯誤", "流程順序不正確，請再試一次", "error");
  }
});

// 初始化心智圖
function renderMindmap(data) {
    const container = document.getElementById("mindmapArea");
    container.innerHTML = "";
    let isDragging = false;
    let startX = 0;
    let startY = 0;

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

// ===============================
// 🔍 流程錯誤分析（核心）
// ===============================
function analyzeFlowMistakes(userOrder, correctOrder) {
    const mistakes = [];

    userOrder.forEach((step, userIndex) => {
        const correctIndex = correctOrder.indexOf(step);

        if (correctIndex !== userIndex) {
            mistakes.push({
                step_text: step,
                user_position: userIndex + 1,
                correct_position: correctIndex + 1,
                problem_type:
                    userIndex < correctIndex ? "too_early" : "too_late"
            });
        }
    });

    return mistakes;
}

async function requestFlowAIHint() {

    const userOrder = Array.from(document.querySelectorAll("#flowList li"))
        .map(li => li.innerText.trim());

    const mistakes = analyzeFlowMistakes(userOrder, correctOrder);

    // 沒錯就不要煩 AI
    if (mistakes.length === 0) {
        document.getElementById("aiHintArea").innerHTML =
            "<p class='text-success'>🎉 目前流程順序正確，不需要提示。</p>";
        return;
    }

    document.getElementById("aiHintArea").innerHTML =
        "<p class='text-muted'>🤖 AI 正在分析你的流程邏輯...</p>";

    const res = await fetch("ai_hint_flow.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            question_id: <?= $questionId ?>,
            mistakes
        })
    });

    const data = await res.json();

    document.getElementById("aiHintArea").innerHTML =
        `<pre style="white-space:pre-wrap">${data.hint}</pre>`;

    recordAction("aihint");
}

document.getElementById("getAiHintBtn")
    .addEventListener("click", requestFlowAIHint);


</script>

</body>
</html>
