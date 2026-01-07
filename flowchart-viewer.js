/**
 * flowchart-viewer.js
 * 穩定版：支援中文、正常排版、縮放、拖曳
 */

function renderFlowchart(data, options = {}) {
    const {
        containerId = "flowchartArea",
        scaleInit = 1,
        minScale = 0.4,
        maxScale = 2.5
    } = options;

    const area = document.getElementById(containerId);
    if (!area) return;

    area.innerHTML = "";

    if (!data || !Array.isArray(data.nodes) || data.nodes.length === 0) {
        area.innerHTML = "<p class='text-muted'>⚠️ 尚未設定流程圖</p>";
        return;
    }

    /* ===============================
       建立 flowchart.js 定義
       =============================== */
    let def = "";

    // ① 定義節點（只做一次）
    data.nodes.forEach(n => {
        const t = (n.type || "operation").toLowerCase();
        const type =
            t === "start" ? "start" :
            t === "end" ? "end" :
            t === "decision" ? "condition" :
            t === "io" ? "inputoutput" : "operation";

        const text = (n.text || "")
            .replace(/=/g, "＝")
            .replace(/</g, "＜")
            .replace(/>/g, "＞");

        def += `${n.id}=>${type}: ${text}\n`;
    });

    // ② 定義連線（⭐ 關鍵）
    if (Array.isArray(data.edges)) {
        data.edges.forEach(e => {
            if (e.label) {
                def += `${e.from}(${e.label})->${e.to}\n`;
            } else {
                def += `${e.from}->${e.to}\n`;
            }
        });
    }

    // console.log(def); // 🔍 除錯用（可打開看）

    const chart = flowchart.parse(def);

    chart.drawSVG(containerId, {
        "line-width": 2,
        "line-color": "#8d6e63",
        "font-size": 14,
        "font-color": "#4e342e",
        "element-color": "#ffca28",
        "fill": "#fffde7",
        "arrow-end": "block",
        "symbols": {
            "start": { "fill": "#aed581", "font-color": "#1b5e20" },
            "end": { "fill": "#ef9a9a", "font-color": "#b71c1c" },
            "condition": { "fill": "#ffe082" },
            "inputoutput": { "fill": "#bbdefb" },
            "operation": { "fill": "#fff9c4" }
        }
    });

    /* ===============================
       縮放與拖曳
       =============================== */
    const svg = area.querySelector("svg");
    if (!svg) return;

    let scale = scaleInit;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = false;
    let startX = 0;
    let startY = 0;

    svg.style.transformOrigin = "0 0";

    area.addEventListener("wheel", e => {
        e.preventDefault();
        scale += (e.deltaY < 0 ? 0.1 : -0.1);
        scale = Math.max(minScale, Math.min(maxScale, scale));
        svg.style.transform =
            `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    });

    area.addEventListener("mousedown", e => {
        dragging = true;
        startX = e.clientX - offsetX;
        startY = e.clientY - offsetY;
    });

    area.addEventListener("mousemove", e => {
        if (!dragging) return;
        offsetX = e.clientX - startX;
        offsetY = e.clientY - startY;
        svg.style.transform =
            `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
    });

    document.addEventListener("mouseup", () => dragging = false);
}
