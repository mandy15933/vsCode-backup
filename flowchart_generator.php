<?php
session_start();
require 'session_protect.php';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 流程圖產生器</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/raphael/2.3.0/raphael.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowchart/1.18.0/flowchart.min.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chiron+GoRound+TC:wght@200..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="flowchart_generator_color.css?v=1.0">

</head>
<body>
<?php include 'Navbar.php'; ?>

<div class="container-fluid py-4 page-wrap">
    <div class="row g-4 main-area">

        <!-- 左側輸入區 -->
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <h4 class="mb-0">🧠 AI 流程圖產生器</h4>
                </div>
                <div class="card-body d-flex flex-column gap-3">

                    <div class="hint-box">
                        你可以輸入：
                        <br>1. 題目描述
                        <br>2. 程式碼
                        <br>3. 或兩者都輸入
                        <br><br>
                        若兩者都有，系統會優先依照程式碼邏輯產生流程圖。
                    </div>

                    <div>
                        <label for="language" class="form-label">程式語言</label>
                        <select id="language" class="form-select">
                            <option value="python" selected>Python</option>
                            <option value="c">C</option>
                            <option value="cpp">C++</option>
                            <option value="java">Java</option>
                            <option value="javascript">JavaScript</option>
                            <option value="php">PHP</option>
                        </select>
                    </div>

                    <div>
                        <label for="problemText" class="form-label">題目描述</label>
                        <textarea id="problemText" class="form-control" placeholder="例如：請輸入一個整數，判斷它是否為偶數，並輸出結果。"></textarea>
                    </div>

                    <div>
                        <label for="sourceCode" class="form-label">程式碼</label>
                        <textarea id="sourceCode" class="form-control" placeholder="請貼上你的程式碼..."></textarea>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button id="generateBtn" class="btn btn-green">🚀 產生流程圖</button>
                        <button id="clearBtn" class="btn btn-soft">🧹 清空</button>
                        <button id="sampleBtn" class="btn btn-gold">📘 載入範例</button>
                        
                    </div>
                    

                    <div id="statusBox" class="mt-2"></div>
                </div>
            </div>
        </div>

        <!-- 右側流程圖區 -->
        <div class="col-12 col-lg-7">
            <div class="card h-100 preview-panel">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">🔄 流程圖預覽</h4>
                    <div class="flowchart-toolbar">
                        <button id="zoomOutBtn" class="btn btn-soft btn-sm">➖ 縮小</button>
                        <button id="zoomInBtn" class="btn btn-soft btn-sm">➕ 放大</button>
                        <button id="zoomResetBtn" class="btn btn-soft btn-sm">🔄 重設</button>
                    </div>
                    
                </div>
                <div id="errorBox" class="alert alert-warning mt-3" style="display:none;"></div>
                <div class="card-body d-flex flex-column">
                    <div class="flowchart-canvas-wrap">
                        <div id="flowchartArea"></div>
                        <div id="emptyState" class="empty-state">
                            尚未產生流程圖<br>
                            請先在左側輸入題目描述或程式碼
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
const generateBtn = document.getElementById("generateBtn");
const clearBtn = document.getElementById("clearBtn");
const sampleBtn = document.getElementById("sampleBtn");

const languageEl = document.getElementById("language");
const problemTextEl = document.getElementById("problemText");
const sourceCodeEl = document.getElementById("sourceCode");
const statusBox = document.getElementById("statusBox");
const flowchartArea = document.getElementById("flowchartArea");
const emptyState = document.getElementById("emptyState");

let currentChart = null;
let currentSvg = null;
let scale = 1;
let offsetX = 0;
let offsetY = 0;
let isPanning = false;
let startX = 0;
let startY = 0;

// =============================
// Session 過期檢查
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
        return true;
    }
    return false;
}

// =============================
// 狀態顯示
// =============================
function setStatus(message = "", type = "info") {
    if (!message) {
        statusBox.innerHTML = "";
        return;
    }

    let className = "alert alert-secondary";
    if (type === "success") className = "alert alert-success";
    if (type === "error") className = "alert alert-danger";
    if (type === "warning") className = "alert alert-warning";
    if (type === "info") className = "alert alert-info";

    statusBox.innerHTML = `<div class="${className} py-2 px-3 mb-0">${message}</div>`;
}

// =============================
// 清空畫布
// =============================
function clearFlowchart() {
    flowchartArea.innerHTML = "";
    emptyState.style.display = "flex";
    currentChart = null;
    currentSvg = null;
    scale = 1;
    offsetX = 0;
    offsetY = 0;
}

// =============================
// 套用 SVG transform
// =============================
function applyTransform() {
    if (!currentSvg) return;
    currentSvg.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
}

// =============================
// 將 flowchart JSON 轉成 flowchart.js 定義
// =============================
let nodeTextMap = new Map();

function convertFlowchartJsonToDefinition(data) {
    if (!data || !Array.isArray(data.nodes) || !Array.isArray(data.edges)) {
        throw new Error("流程圖資料格式錯誤");
    }

    nodeTextMap.clear();
    let def = "";

    data.nodes.forEach(node => {
        const rawType = String(node.type || "").toLowerCase();
        const text = (node.text || "").replace(/\n/g, " ");

        let flowType = "operation";
        if (rawType === "start") flowType = "start";
        else if (rawType === "end") flowType = "end";
        else if (rawType === "io") flowType = "inputoutput";
        else if (rawType === "decision") flowType = "condition";

        nodeTextMap.set(String(node.id), text.trim());

        def += `${node.id}=>${flowType}: ${text}\n`;
    });

    data.edges.forEach(edge => {
        let branch = "";
        const label = String(edge.label || "").toLowerCase();

        if (label === "yes" || label === "是") branch = "(yes)";
        else if (label === "no" || label === "否") branch = "(no)";

        def += `${edge.from}${branch}->${edge.to}\n`;
    });

    return def;
}

// =============================
// 渲染流程圖
// =============================
function renderFlowchartWithInteraction(data, feedback = null) {
    clearFlowchart();

    if (!data || !Array.isArray(data.nodes) || data.nodes.length === 0) {
        setStatus("⚠️ 沒有可顯示的流程圖資料", "warning");
        return;
    }

    let definition = "";
    try {
        definition = convertFlowchartJsonToDefinition(data);
    } catch (err) {
        setStatus("⚠️ 流程圖格式錯誤：" + err.message, "error");
        return;
    }

    try {
        currentChart = flowchart.parse(definition);
        flowchartArea.innerHTML = "";
        emptyState.style.display = "none";

        currentChart.drawSVG("flowchartArea", {
            "line-width": 2,
            "font-size": 16,
            "line-color": "#444",
            "element-color": "#d6a400",
            "fill": "#fffef8",
            "arrow-end": "block",
            "symbols": {
                "start": { "fill": "#81c784", "font-color": "#fff" },
                "end": { "fill": "#e57373", "font-color": "#fff" },
                "condition": { "fill": "#FFECB3" },
                "inputoutput": { "fill": "#BBDEFB" },
                "operation": { "fill": "#FFF8E1" }
            }
        });

        currentSvg = flowchartArea.querySelector("svg");
        if (currentSvg) {
            currentSvg.style.transformOrigin = "0 0";
            scale = 1;
            offsetX = 20;
            offsetY = 20;
            applyTransform();
        }
        if (feedback && feedback.has_error) {
            console.log("收到的 error_nodes:", feedback?.error_nodes);
            setTimeout(() => {
                highlightErrorNodes(data, feedback);
            }, 300);
        }

        // 移除 SVG 內可能的連結預設行為
        setTimeout(() => {
            const anchors = flowchartArea.querySelectorAll("svg a");
            anchors.forEach(a => {
                a.removeAttribute("href");
                a.onclick = (e) => e.preventDefault();
            });
        }, 50);

        setStatus("✅ 流程圖產生成功", "success");

    } catch (err) {
        console.error(err);
        setStatus("❌ 流程圖渲染失敗：" + err.message, "error");
        clearFlowchart();
    }
}

// =============================
// 產生流程圖
// =============================
async function generateFlowchart() {
    const problemText = problemTextEl.value.trim();
    const sourceCode = sourceCodeEl.value.trim();
    const language = languageEl.value;
    console.log("feedback:", data.feedback);
    console.log("flowchart:", data.flowchart);

    if (!problemText && !sourceCode) {
        Swal.fire({
            icon: "warning",
            title: "請先輸入內容",
            text: "請至少輸入題目描述或程式碼。"
        });
        return;
    }

    setStatus(`
        <div class="loading-box">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            AI 正在分析並生成流程圖，請稍候...
        </div>
    `, "info");

    generateBtn.disabled = true;

    try {
        const response = await fetch("generate_flowchart_ai.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                problem_text: problemText,
                source_code: sourceCode,
                language: language
            })
        });

        const data = await response.json();

        if (handleSessionExpired(data)) return;

        if (!data.success) {
            console.error(data);
            setStatus(data.error || "產生失敗", "error");

            Swal.fire({
                icon: "error",
                title: "流程圖產生失敗",
                text: data.error || "請稍後再試"
            });
            return;
        }

        renderFlowchartWithInteraction(data.flowchart, data.feedback);
        showFeedback(data.feedback);

    } catch (err) {
        console.error(err);
        setStatus("❌ 系統錯誤：" + err.message, "error");

        Swal.fire({
            icon: "error",
            title: "系統錯誤",
            text: err.message
        });
    } finally {
        generateBtn.disabled = false;
    }
}
function showFeedback(feedback) {
    if (!errorBox) return;

    if (!feedback || !feedback.has_error || !Array.isArray(feedback.error_nodes) || feedback.error_nodes.length === 0) {
        errorBox.style.display = "none";
        errorBox.innerHTML = "";
        return;
    }

    let html = "⚠️ 偵測到程式邏輯問題：<br><br>";

    feedback.error_nodes.forEach(err => {
        html += `
            <div style="margin-bottom:12px;">
                <b>📍 第 ${err.line ?? "?"} 行</b><br>
                節點ID：${err.node_id ?? "未提供"}<br>
                問題：${err.message ?? "未提供"}<br>
                建議：${err.suggestion ?? "未提供"}
            </div>
        `;
    });

    errorBox.innerHTML = html;
    errorBox.style.display = "block";
}
function normalizeFlowText(s) {
    return String(s || "")
        .replace(/[「」"'＂]/g, "")   // 各種引號
        .replace(/print/g, "")        // 去掉 print（關鍵）
        .replace(/[()]/g, "")         // 去掉括號
        .replace(/\s+/g, "")          // 去掉空白
        .trim();
}

function highlightErrorNodes(flowchartData, feedback) {
    if (!currentSvg || !feedback || !Array.isArray(feedback.error_nodes)) {
        console.warn("highlightErrorNodes: currentSvg 或 feedback.error_nodes 不存在");
        return;
    }

    const errorIds = feedback.error_nodes
        .map(e => String(e.node_id || "").trim())
        .filter(Boolean);

    console.log("收到的 error_nodes:", feedback.error_nodes);
    console.log("要高亮的 node_id:", errorIds);

    if (errorIds.length === 0) {
        console.warn("沒有可用的 node_id，無法高亮");
        return;
    }

    const textNodes = currentSvg.querySelectorAll("text");
    console.log("SVG text 數量:", textNodes.length);

    let matchedCount = 0;

    textNodes.forEach(textEl => {
        const rawText = (textEl.textContent || "").trim();
        if (!rawText) return;

        const matchedId = errorIds.find(id => rawText.includes(`[ID:${id}]`));

        if (!matchedId) return;

        matchedCount++;
        console.log("命中節點:", matchedId, rawText);

        let group = textEl.closest("g");

        while (group) {
            const shapes = group.querySelectorAll("rect, path, polygon, ellipse");
            if (shapes.length > 0) {
                shapes.forEach(shape => {
                    shape.style.stroke = "#e53935";
                    shape.style.strokeWidth = "4";
                    shape.style.fill = "#ffebee";
                    shape.style.filter = "drop-shadow(0 0 6px rgba(229,57,53,0.4))";
                });

                group.querySelectorAll("text, tspan").forEach(t => {
                    t.style.fill = "#b71c1c";
                    t.style.fontWeight = "700";
                });

                break;
            }

            group = group.parentElement ? group.parentElement.closest("g") : null;
        }
    });

    // 若用 [ID:xxx] 沒找到，再退而求其次用文字內容比對
    if (matchedCount === 0) {
        console.warn("用 [ID:xxx] 沒命中，改用節點文字比對");

        const targetTexts = errorIds
            .map(id => nodeTextMap.get(id))
            .filter(Boolean)
            .map(t => normalizeText(t));

        console.log("改用文字比對:", targetTexts);

        textNodes.forEach(textEl => {
            const rawText = normalizeText(textEl.textContent || "");
            if (!rawText) return;

            const matched = targetTexts.some(t => rawText.includes(t));
            if (!matched) return;

            let group = textEl.closest("g");

            while (group) {
                const shapes = group.querySelectorAll("rect, path, polygon, ellipse");
                if (shapes.length > 0) {
                    shapes.forEach(shape => {
                        shape.style.stroke = "#e53935";
                        shape.style.strokeWidth = "4";
                        shape.style.fill = "#ffebee";
                        shape.style.filter = "drop-shadow(0 0 6px rgba(229,57,53,0.4))";
                    });

                    group.querySelectorAll("text, tspan").forEach(t => {
                        t.style.fill = "#b71c1c";
                        t.style.fontWeight = "700";
                    });

                    break;
                }

                group = group.parentElement ? group.parentElement.closest("g") : null;
            }
        });
    }
}
// =============================
// 範例載入
// =============================
function loadSample() {
    languageEl.value = "python";

    problemTextEl.value = "請輸入一個整數 n，判斷它是否為偶數，若是則輸出「偶數」，否則輸出「奇數」。";

    sourceCodeEl.value =
`n = int(input())
if n % 2 == 0:
    print("偶數")
else:
    print("奇數")`;

    setStatus("📘 已載入範例，可直接按下「產生流程圖」", "info");
}

// =============================
// 清空輸入
// =============================
function clearAllInputs() {
    problemTextEl.value = "";
    sourceCodeEl.value = "";
    languageEl.value = "python";
    clearFlowchart();
    setStatus("", "info");
}

// =============================
// 縮放按鈕
// =============================
document.getElementById("zoomInBtn").addEventListener("click", () => {
    if (!currentSvg) return;
    scale = Math.min(scale + 0.1, 3);
    applyTransform();
});

document.getElementById("zoomOutBtn").addEventListener("click", () => {
    if (!currentSvg) return;
    scale = Math.max(scale - 0.1, 0.2);
    applyTransform();
});

document.getElementById("zoomResetBtn").addEventListener("click", () => {
    if (!currentSvg) return;
    scale = 1;
    offsetX = 20;
    offsetY = 20;
    applyTransform();
});

// =============================
// 拖曳平移（滑鼠）
// =============================
flowchartArea.addEventListener("mousedown", (e) => {
    if (!currentSvg) return;
    isPanning = true;
    flowchartArea.style.cursor = "grabbing";
    startX = e.clientX - offsetX;
    startY = e.clientY - offsetY;
});

window.addEventListener("mousemove", (e) => {
    if (!isPanning || !currentSvg) return;
    offsetX = e.clientX - startX;
    offsetY = e.clientY - startY;
    applyTransform();
});

window.addEventListener("mouseup", () => {
    isPanning = false;
    flowchartArea.style.cursor = "grab";
});

// =============================
// 拖曳平移（觸控）
// =============================
flowchartArea.addEventListener("touchstart", (e) => {
    if (!currentSvg) return;
    const t = e.touches[0];
    isPanning = true;
    startX = t.clientX - offsetX;
    startY = t.clientY - offsetY;
}, { passive: true });

flowchartArea.addEventListener("touchmove", (e) => {
    if (!isPanning || !currentSvg) return;
    const t = e.touches[0];
    offsetX = t.clientX - startX;
    offsetY = t.clientY - startY;
    applyTransform();
}, { passive: true });

flowchartArea.addEventListener("touchend", () => {
    isPanning = false;
});

// =============================
// 滾輪縮放
// =============================
flowchartArea.addEventListener("wheel", (e) => {
    if (!currentSvg) return;
    e.preventDefault();

    const delta = e.deltaY < 0 ? 0.1 : -0.1;
    scale = Math.max(0.2, Math.min(3, scale + delta));
    applyTransform();
}, { passive: false });

// =============================
// 事件綁定
// =============================
generateBtn.addEventListener("click", generateFlowchart);
clearBtn.addEventListener("click", clearAllInputs);
sampleBtn.addEventListener("click", loadSample);
</script>

</body>
</html>