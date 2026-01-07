/**
 * mindmap-viewer.js
 * 通用心智圖顯示模組（jsMind）
 * 適用：程式拖曳 / 白話流程 / 積木拖曳
 */

/* ===== 全域狀態 ===== */
let mmIsDragging = false;
let mmStartX = 0;
let mmStartY = 0;
let mmOffsetX = 0;
let mmOffsetY = 0;
let mmScale = 1;

/**
 * 渲染心智圖
 * @param {Object|null} data jsMind JSON
 * @param {Object} options 可選設定
 */
function renderMindmap(data, options = {}) {
    const {
        containerId = "mindmapArea",
        initialScale = 1.3,
        minScale = 0.3,
        maxScale = 3,
        enableWheelZoom = true,
        enableDrag = true
    } = options;

    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = "";

    if (!data) {
        container.innerHTML = "<p class='text-muted'>⚠️ 尚未提供心智圖資料</p>";
        return;
    }

    /* === 基本樣式 === */
    container.style.minHeight = "420px";
    container.style.overflow = "auto";
    container.style.position = "relative";

    /* === 初始化 jsMind === */
    const jm = new jsMind({
        container: containerId,
        theme: "primary",
        editable: false
    });
    jm.show(data);

    /* === 節點可讀性優化 === */
    container.querySelectorAll("jmnode").forEach(node => {
        node.style.whiteSpace = "normal";
        node.style.wordBreak = "break-word";
        node.style.maxWidth = "240px";
        node.style.lineHeight = "1.4";
        node.style.padding = "4px 8px";
        node.style.fontSize = "15px";
    });

    setTimeout(() => jm.resize(), 200);

    /* === 取得真正圖層 === */
    const root = container.querySelector(".jsmind-inner");
    if (!root) return;

    root.style.transformOrigin = "0 0";

    mmScale = initialScale;
    mmOffsetX = 0;
    mmOffsetY = 0;

    root.style.transform = `translate(0px, 0px) scale(${mmScale})`;

    /* ===============================
       🔍 滾輪縮放
       =============================== */
    if (enableWheelZoom) {
        container.addEventListener("wheel", e => {
            e.preventDefault();
            mmScale += (e.deltaY < 0 ? 0.1 : -0.1);
            mmScale = Math.max(minScale, Math.min(maxScale, mmScale));
            root.style.transform =
                `translate(${mmOffsetX}px, ${mmOffsetY}px) scale(${mmScale})`;
        });
    }

    /* ===============================
       🖱️ 拖曳平移（桌機）
       =============================== */
    if (enableDrag) {
        container.addEventListener("mousedown", e => {
            mmIsDragging = true;
            mmStartX = e.clientX - mmOffsetX;
            mmStartY = e.clientY - mmOffsetY;
        });

        container.addEventListener("mousemove", e => {
            if (!mmIsDragging) return;
            mmOffsetX = e.clientX - mmStartX;
            mmOffsetY = e.clientY - mmStartY;
            root.style.transform =
                `translate(${mmOffsetX}px, ${mmOffsetY}px) scale(${mmScale})`;
        });

        document.addEventListener("mouseup", () => {
            mmIsDragging = false;
        });
    }

    /* ===============================
       📱 手機觸控拖曳
       =============================== */
    container.addEventListener("touchstart", e => {
        const t = e.touches[0];
        mmIsDragging = true;
        mmStartX = t.clientX - mmOffsetX;
        mmStartY = t.clientY - mmOffsetY;
    });

    container.addEventListener("touchmove", e => {
        if (!mmIsDragging) return;
        const t = e.touches[0];
        mmOffsetX = t.clientX - mmStartX;
        mmOffsetY = t.clientY - mmStartY;
        root.style.transform =
            `translate(${mmOffsetX}px, ${mmOffsetY}px) scale(${mmScale})`;
    });

    container.addEventListener("touchend", () => {
        mmIsDragging = false;
    });
}
