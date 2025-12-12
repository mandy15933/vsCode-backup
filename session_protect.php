<?php
session_start();
ob_clean(); // 清掉輸出避免破壞 JSON

// ======================================
// 你可以調整 Session 逾時秒數
// ======================================
$SESSION_TIMEOUT = 3600; // ← 1 小時（可改）


// ======================================
// 🔍 判斷是否為 API 請求（fetch）
// 若是 API 模式 → 回 JSON，而不是 HTML
// ======================================
$apiFiles = [
    'log_action.php',
    'save_answer.php',
    'ai_feedback_step.php',
    'check_aihint_count.php',
    'check_feedback.php'
];

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isApiRequest = false;

foreach ($apiFiles as $api) {
    if (strpos($requestUri, $api) !== false) {
        $isApiRequest = true;
        break;
    }
}


// ======================================
// 🔒 1️⃣ 尚未登入 → API 回 JSON / 一般頁面跳轉
// ======================================
if (!isset($_SESSION['user_id'])) {

    if ($isApiRequest) {
        echo json_encode([
            "success" => false,
            "session_expired" => true,
            "message" => "登入已失效，請重新登入。"
        ]);
        exit;
    }

    // 一般頁面 → 顯示 SweetAlert
    echo '
    <!DOCTYPE html>
    <html lang="zh-TW">
    <head>
        <meta charset="UTF-8">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: "warning",
                title: "尚未登入",
                text: "請先登入後再使用本系統。",
                allowOutsideClick: false
            }).then(() => {
                window.location.href = "index.php";
            });
        </script>
    </body>
    </html>';
    exit;
}


// ======================================
// 🔒 2️⃣ Session 是否逾時？
// ======================================
if (isset($_SESSION['last_active'])) {
    $inactive = time() - $_SESSION['last_active'];

    if ($inactive > $SESSION_TIMEOUT) {
        session_unset();
        session_destroy();

        if ($isApiRequest) {
            echo json_encode([
                "success" => false,
                "session_expired" => true,
                "message" => "登入逾時，請重新登入。"
            ]);
            exit;
        }

        echo '
        <!DOCTYPE html>
        <html lang="zh-TW">
        <head>
            <meta charset="UTF-8">
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: "info",
                    title: "登入逾時",
                    text: "因長時間未操作，已自動登出。",
                    allowOutsideClick: false
                }).then(() => {
                    window.location.href = "index.php";
                });
            </script>
        </body>
        </html>';
        exit;
    }
}

// ======================================
// 🔄 3️⃣ 更新 Session 活動時間
// ======================================
$_SESSION['last_active'] = time();
