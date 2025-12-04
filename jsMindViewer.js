// === jsMind Viewer 模組 ===
class MindmapViewer {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.jm = null;
        this.scale = 1.3;
        this.offsetX = 0;
        this.offsetY = 0;
        this.isDragging = false;
        this.startX = 0;
        this.startY = 0;
        this.initialized = false; // 防止重複渲染

        this.initEvents();
    }

    initEvents() {
        // 滾輪縮放
        this.container.addEventListener("wheel", (e) => {
            e.preventDefault();
            this.scale += e.deltaY < 0 ? 0.1 : -0.1;
            this.scale = Math.max(0.3, Math.min(3, this.scale));
            this.applyTransform();
        });

        // 滑鼠拖曳
        this.container.addEventListener("mousedown", (e) => {
            this.isDragging = true;
            this.startX = e.clientX - this.offsetX;
            this.startY = e.clientY - this.offsetY;
        });

        document.addEventListener("mouseup", () => {
            this.isDragging = false;
        });

        this.container.addEventListener("mousemove", (e) => {
            if (!this.isDragging) return;
            this.offsetX = e.clientX - this.startX;
            this.offsetY = e.clientY - this.startY;
            this.applyTransform();
        });

        // 觸控拖曳
        this.container.addEventListener("touchstart", (e) => {
            const t = e.touches[0];
            this.isDragging = true;
            this.startX = t.clientX - this.offsetX;
            this.startY = t.clientY - this.offsetY;
        });

        this.container.addEventListener("touchmove", (e) => {
            if (!this.isDragging) return;
            const t = e.touches[0];
            this.offsetX = t.clientX - this.startX;
            this.offsetY = t.clientY - this.startY;
            this.applyTransform();
        });

        this.container.addEventListener("touchend", () => {
            this.isDragging = false;
        });
    }

    applyTransform() {
        const inner = this.container.querySelector(".jsmind-inner");
        if (inner) {
            inner.style.transformOrigin = "0 0";
            inner.style.transform =
                `translate(${this.offsetX}px, ${this.offsetY}px) scale(${this.scale})`;
        }
    }

    render(data) {
        // 若已 render 過，直接顯示即可
        if (this.initialized) {
            this.applyTransform();
            return;
        }

        // 初始化 jsMind
        this.jm = new jsMind({
            container: this.container.id,
            theme: "primary",
            editable: false
        });

        this.jm.show(data);
        setTimeout(() => this.jm.resize(), 100);

        // 美化 node
        this.container.querySelectorAll("jmnode").forEach(node => {
            node.style.whiteSpace = "normal";
            node.style.wordBreak = "break-word";
            node.style.maxWidth = "240px";
            node.style.lineHeight = "1.4";
            node.style.padding = "4px 8px";
            node.style.fontSize = "15px";
        });

        // 初次置中＋放大
        this.autoCenter();

        this.initialized = true;
    }

    autoCenter() {
        const inner = this.container.querySelector(".jsmind-inner");
        if (!inner) return;

        const box = inner.getBoundingClientRect();
        const c = this.container.getBoundingClientRect();

        // 自動置中（水平 + 垂直）
        this.offsetX = (c.width - box.width * this.scale) / 2;
        this.offsetY = (c.height - box.height * this.scale) / 2;

        this.applyTransforms();
    }

    zoomIn() {
        this.scale = Math.min(this.scale + 0.1, 3);
        this.applyTransform();
    }

    zoomOut() {
        this.scale = Math.max(this.scale - 0.1, 0.3);
        this.applyTransform();
    }

    reset() {
        this.scale = 1.3;
        this.offsetX = 0;
        this.offsetY = 0;
        this.applyTransform();
    }
}
