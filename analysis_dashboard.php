<?php
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo "<script>
        alert('您沒有權限進入此頁面');
        window.location.href = 'index.php';
    </script>";
    exit;
}

/* 取得所有班級 */
$classList = $conn->query("
    SELECT DISTINCT ClassName 
    FROM users 
    WHERE role='student'
    ORDER BY ClassName
")->fetch_all(MYSQLI_ASSOC);

/* 被選擇的班級（預設第一個） */
$selectClass = $_GET['class'] ?? ($classList[0]['ClassName'] ?? null);

/* 排行榜日期 (預設今日) */
$today = date("Y-m-d");
$selectDate = $_GET['rank_date'] ?? $today;

$start = $selectDate . " 00:00:00";
$end   = $selectDate . " 23:59:59";


/* ===============================
   1. 章節完成率（排除隱藏題目）
================================ */

/* 該班學生數 */
$sql_student_count = "
    SELECT COUNT(*) AS total_students 
    FROM users 
    WHERE ClassName = ? AND role='student'
";
$stmt = $conn->prepare($sql_student_count);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$totalStudents = $stmt->get_result()->fetch_assoc()['total_students'];
$stmt->close();


/* 各章節題數（排除隱藏題目） */
$sql_chapter_questions = "
    SELECT chapter, COUNT(*) AS q_count
    FROM questions
    WHERE is_hidden = 0
    GROUP BY chapter
";
$chapterQuestions = [];
$res = $conn->query($sql_chapter_questions);
while ($row = $res->fetch_assoc()) {
    $chapterQuestions[$row['chapter']] = $row['q_count'];
}


/* 該班每題是否正確（每題每生最多計一次，排除隱藏題目） */
$sql_chapter_done = "
    SELECT 
        q.chapter,
        sa.user_id,
        sa.question_id,
        MAX(sa.is_correct) AS correct_once
    FROM questions q
    LEFT JOIN student_answers sa ON sa.question_id = q.id
    LEFT JOIN users u ON sa.user_id = u.UserID
    WHERE q.is_hidden = 0
      AND u.ClassName = ?
    GROUP BY q.chapter, sa.user_id, sa.question_id
";
$stmt = $conn->prepare($sql_chapter_done);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$result = $stmt->get_result();

$chapterDone = [];
while ($row = $result->fetch_assoc()) {
    if (!isset($chapterDone[$row['chapter']])) {
        $chapterDone[$row['chapter']] = 0;
    }
    if ($row['correct_once'] == 1) {
        $chapterDone[$row['chapter']]++;
    }
}
$stmt->close();


/* 組合章節完成率 */
$chapters = [];
foreach ($chapterQuestions as $chapterId => $qCount) {

    $should = $totalStudents * $qCount;
    $done   = $chapterDone[$chapterId] ?? 0;

    $percent = $should > 0
        ? round(($done / $should) * 100, 1)
        : 0;

    $chapters[] = [
        "id" => $chapterId,
        "title" => "章節 $chapterId",
        "should" => $should,
        "done" => $done,
        "percent" => $percent
    ];
}

/* ===============================
   各章節視覺化工具使用量
================================ */
$sql_visual_chapter = "
    SELECT
        chapter_id,
        SUM(mindmap_clicks)   AS mindmap_total,
        SUM(flowchart_clicks) AS flowchart_total
    FROM student_answers sa
    JOIN users u ON sa.user_id = u.UserID
    WHERE u.ClassName = ?
      AND sa.answer_mode = 'practice'
      AND sa.chapter_id IS NOT NULL
    GROUP BY chapter_id
    ORDER BY chapter_id
";

$stmt = $conn->prepare($sql_visual_chapter);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$res = $stmt->get_result();

$visualChapter = [];
while ($row = $res->fetch_assoc()) {
    $visualChapter[$row['chapter_id']] = [
        'mindmap'   => (int)$row['mindmap_total'],
        'flowchart' => (int)$row['flowchart_total']
    ];
}
$stmt->close();

/* ===============================
   學生平台使用狀況
================================ */
$sql_usage = "
    SELECT
        COUNT(*) AS total_students,
        SUM(has_practice) AS active_students
    FROM (
        SELECT
            u.UserID,
            CASE WHEN COUNT(sa.id) > 0 THEN 1 ELSE 0 END AS has_practice
        FROM users u
        LEFT JOIN student_answers sa
            ON sa.user_id = u.UserID
            AND sa.answer_mode = 'practice'
        WHERE u.ClassName = ?
          AND u.role = 'student'
        GROUP BY u.UserID
    ) t
";

$stmt = $conn->prepare($sql_usage);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalStudents    = (int)$row['total_students'];
$activeStudents   = (int)$row['active_students'];
$inactiveStudents = $totalStudents - $activeStudents;


/* ===============================
   2. 題目完成度總覽
================================ */
$sql_questions = "
    SELECT 
        q.id,
        q.title,
        COUNT(sa.id) AS attempts,
        SUM(sa.is_correct) AS correct_attempts
    FROM questions q
    LEFT JOIN student_answers sa ON sa.question_id = q.id
    LEFT JOIN users u ON sa.user_id = u.UserID

    WHERE u.ClassName = ?
       OR u.ClassName IS NULL

    GROUP BY q.id
";
$stmt = $conn->prepare($sql_questions);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$questionStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


/* ===============================
   3. 班級表現
================================ */


/* 取得：該班學生人數 */
$sql_student_count = "
    SELECT COUNT(*) AS total_students
    FROM users
    WHERE ClassName = ? AND role = 'student'
";
$stmt = $conn->prepare($sql_student_count);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$totalStudents = $stmt->get_result()->fetch_assoc()['total_students'];
$stmt->close();

/* 取得：題目總數 */
$sql_total_questions = "SELECT COUNT(*) AS total_questions FROM questions WHERE is_hidden = 0";
$totalQuestions = $conn->query($sql_total_questions)->fetch_assoc()['total_questions'];

/* 應繳題數 */
$shouldSubmit = $totalStudents * $totalQuestions;

/* 取得：已繳題數（每生每題只算一次）＋正確題數 */
$sql_submit_correct = "
   SELECT 
        COUNT(DISTINCT CONCAT(sa.user_id,'-',sa.question_id)) AS submitted,
        SUM(CASE WHEN sa.is_correct = 1 THEN 1 ELSE 0 END) AS correct
    FROM student_answers sa
    JOIN users u ON sa.user_id = u.UserID
    JOIN questions q ON sa.question_id = q.id
    WHERE u.ClassName = ?
    AND q.is_hidden = 0
";
$stmt = $conn->prepare($sql_submit_correct);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$submitted = $row['submitted'] ?? 0;
$correct   = $row['correct']   ?? 0;

/* 計算比率 */
$submitRate = ($shouldSubmit > 0) ? round(($submitted / $shouldSubmit) * 100, 1) : 0;
$accuracy   = ($submitted > 0) ? round(($correct / $submitted) * 100, 1) : 0;

/* 組裝成陣列給前端使用 */
$classPerformance = [
    "class_name"   => $selectClass,
    "students"     => $totalStudents,
    "questions"    => $totalQuestions,
    "shouldSubmit" => $shouldSubmit,
    "submitted"    => $submitted,
    "correct"      => $correct,
    "submitRate"   => $submitRate,
    "accuracy"     => $accuracy
];



/* ===============================
   4. 常錯題排行榜
================================ */
$sql_wrong = "
    SELECT 
        q.id,
        q.title,
        SUM(CASE WHEN sa.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_count
    FROM questions q
    LEFT JOIN student_answers sa ON sa.question_id = q.id
    LEFT JOIN users u ON sa.user_id = u.UserID
    WHERE u.ClassName = ?
    GROUP BY q.id
    HAVING wrong_count > 0
    ORDER BY wrong_count DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql_wrong);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$wrongList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


/* ===============================
   5. 花費時間統計
================================ */
$sql_time = "
    SELECT 
        u.Username,
        SUM(sa.time_spent) AS total_time
    FROM student_answers sa
    JOIN users u ON sa.user_id = u.UserID
    WHERE u.ClassName = ?
    GROUP BY sa.user_id
    ORDER BY total_time DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql_time);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$timeRank = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


/* ===============================
   6. 工具使用分析
================================ */
$sql_tool = "
    SELECT 
        vf.tool_type,
        COUNT(*) AS count
    FROM visual_feedback vf
    JOIN users u ON vf.user_id = u.UserID
    WHERE u.ClassName = ?
    GROUP BY vf.tool_type
";
$stmt = $conn->prepare($sql_tool);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$toolStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ===============================
7. 歷次每日排行榜
================================ */
$sql_rank = "
    SELECT 
        sa.user_id,
        u.Username AS name,
        COUNT(sa.id) AS attempts,
        SUM(sa.is_correct) AS correct_count,
        MAX(CASE WHEN sa.is_correct=1 THEN sa.answered_at END) AS finish_time
    FROM student_answers sa
    JOIN users u ON sa.user_id = u.UserID
    WHERE u.ClassName = ?
      AND sa.answered_at BETWEEN ? AND ?
    GROUP BY sa.user_id
    ORDER BY correct_count DESC, finish_time ASC, attempts ASC
";
$stmt = $conn->prepare($sql_rank);
$stmt->bind_param("sss", $selectClass, $start, $end);
$stmt->execute();
$rankList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
/* ===============================
8. 每位學生學習概況
================================ */
$sql_student_overview = "
    SELECT
        u.UserID,
        u.StudentID,        -- ⭐ 真正的學號
        u.Username,

        COUNT(DISTINCT sa.question_id) AS completed_questions,
        COUNT(DISTINCT CASE WHEN sa.is_correct = 1 THEN sa.question_id END) AS correct_questions,
        SUM(sa.attempts) AS attempts,
        SUM(sa.time_spent) AS total_time,
        SUM(sa.mindmap_clicks) AS mindmap_clicks,
        SUM(sa.flowchart_clicks) AS flowchart_clicks
    FROM users u
    LEFT JOIN student_answers sa
        ON sa.user_id = u.UserID
        AND sa.answer_mode = 'practice'
    WHERE u.ClassName = ?
      AND u.role = 'student'
    GROUP BY u.UserID
    ORDER BY completed_questions DESC
";



$stmt = $conn->prepare($sql_student_overview);
$stmt->bind_param("s", $selectClass);
$stmt->execute();
$studentOverview = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>學習分析儀表板</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="anime-yellow-theme.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>


<style>
body { background: #f8f9fa; }
.card { border-radius: 16px; }
.section-title { font-size: 1.4rem; font-weight:bold; margin-top:20px; }
#studentTable th {
    cursor: pointer;
    user-select: none;
}
#studentTable th:hover {
    background-color: #fff3cd;
}
</style>
</head>
<body>
<?php include 'Navbar.php'; ?>

<div class="p-4">

    <h2 class="mb-4">📊 學習分析儀表板</h2>
    <form class="row mb-4" method="get">
        <div class="col-md-4">
            <label class="form-label fw-bold">選擇班級</label>
            <select name="class" class="form-select" onchange="this.form.submit()">
                <?php foreach ($classList as $c): ?>
                    <option value="<?= $c['ClassName'] ?>" 
                        <?= $selectClass == $c['ClassName'] ? 'selected' : '' ?>>
                        <?= $c['ClassName'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>


<!-- ========== 1. 章節完成率 ========== -->
<div class="section-title">📘 章節完成率</div>
<div class="row">

<?php foreach ($chapters as $ch): ?>
    <div class="col-md-3 mb-3">
        <div class="card p-3 shadow-sm">
            <h5><?= $ch['title'] ?></h5>

            <div>應繳：<?= $ch['should'] ?></div>
            <div>已完成：<?= $ch['done'] ?></div>

            <div class="progress mb-1">
                <div class="progress-bar bg-success" style="width: <?= $ch['percent'] ?>%"></div>
            </div>

            <small><?= $ch['percent'] ?>%</small>
        </div>
    </div>
<?php endforeach; ?>

</div>
<!-- ========== 各章節視覺化工具使用量 ========== -->
<div class="section-title">🧠 各章節視覺化工具使用量</div>
<div class="card p-3 mb-4 shadow-sm">
    <canvas id="chapterToolChart" style="max-height: 300px;"></canvas>
</div>
<!-- ========== 學生平台使用狀況 ========== -->
<div class="section-title">👥 學生平台使用狀況</div>
<div class="card p-3 mb-5 shadow-sm">
    <canvas id="usageChart" style="max-height: 260px;"></canvas>
</div>

<!-- ========== 2. 班級表現 ========== -->
<div class="section-title">🏫 班級表現比較（<?= $classPerformance["class_name"] ?>）</div>

<div class="card p-3 mb-4 shadow-sm">

<table class="table table-bordered table-hover text-center">
<thead class="table-light fw-bold">
<tr>
    <th>班級</th>
    <th>學生數</th>
    <th>題目數</th>
    <th>應繳題數</th>
    <th>已繳題數</th>
    <th>繳交率</th>
    <th>正確題數</th>
    <!-- <th>正確率</th> -->
</tr>
</thead>
<tbody>
<tr>
    <td><?= $classPerformance["class_name"] ?></td>
    <td><?= $classPerformance["students"] ?></td>
    <td><?= $classPerformance["questions"] ?></td>
    <td><?= $classPerformance["shouldSubmit"] ?></td>
    <td><?= $classPerformance["submitted"] ?></td>
    <td><?= $classPerformance["submitRate"] ?>%</td>
    <td><?= $classPerformance["correct"] ?></td>
    <!-- <td><?= $classPerformance["accuracy"] ?>%</td> -->
</tr>
</tbody>
</table>

</div>


<!-- ========== 3. 常錯題 ========== -->
<div class="section-title">❗ 常錯題排行榜</div>
<div class="card p-3 mb-4 shadow-sm">
<table class="table table-bordered">
<thead class="table-light">
<tr><th>題目</th><th>錯誤次數</th></tr>
</thead>
<tbody>
<?php foreach ($wrongList as $w): ?>
<tr>
    <td><?= $w['title'] ?></td>
    <td><?= $w['wrong_count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ========== 4. 花費時間 ========== -->
<div class="section-title">⌛ 最長學習時間排行</div>
<div class="card p-3 mb-4 shadow-sm">
<table class="table table-bordered">
<thead class="table-light">
<tr><th>學生</th><th>總秒數</th></tr>
</thead>
<tbody>
<?php foreach ($timeRank as $t): ?>
<tr>
    <td><?= $t['Username'] ?></td>
    <td><?= $t['total_time'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ========== 5. 工具使用 ========== -->
<div class="section-title">🧠 視覺化工具使用次數</div>
<div class="card p-5 mb-10 shadow-sm">
<table class="table table-bordered">
<thead class="table-light">
<tr><th>工具</th><th>使用次數</th></tr>
</thead>
<tbody>
<?php foreach ($toolStats as $t): ?>
<tr>
    <td><?= $t['tool_type'] == 'mindmap' ? '心智圖' : '流程圖' ?></td>
    <td><?= $t['count'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ============================================================
     7. 加入：歷次每日排行榜查詢（日期 + 班級篩選）
=============================================================== -->
<div class="section-title">🏆 歷次每日排行榜查詢（班級：<?= $selectClass ?>）</div>

<div class="card p-3 mb-4 shadow-sm">

<form method="get" class="row g-3 mb-3">

    <!-- 保留班級篩選，避免表單送出時遺失 -->
    <input type="hidden" name="class" value="<?= $selectClass ?>">

    <div class="col-md-6">
        <label class="form-label">選擇日期</label>
        <input type="date" name="rank_date" class="form-control"
               value="<?= htmlspecialchars($selectDate) ?>">
    </div>

    <div class="col-md-6 d-flex align-items-end">
        <button class="btn btn-warning w-100">查詢</button>
    </div>
</form>

<table class="table table-bordered text-center align-middle">
<thead class="table-light">
<tr>
    <th>名次</th>
    <th>學生</th>
    <th>答對題數</th>
    <th>完成時間</th>
</tr>
</thead>
<tbody>
<?php if (empty($rankList)): ?>
<tr><td colspan="4">📭 無資料</td></tr>
<?php else: $r = 1; foreach ($rankList as $row): ?>
<tr>
    <td><?= $r ?></td>
    <td><?= htmlspecialchars($row['name']) ?></td>
    <td><?= $row['correct_count'] ?></td>
    <td><?= $row['finish_time'] ?: '-' ?></td>
</tr>
<?php $r++; endforeach; endif; ?>
</tbody>
</table>

</div>
<!-- ========== 8. 每位學生學習概況 ========== -->
<div class="section-title">👤 學生學習概況（<?= htmlspecialchars($selectClass) ?>）</div>

<div class="card p-3 mb-4 shadow-sm">
<table id="studentTable" class="table table-bordered table-hover text-center align-middle">
<thead class="table-light fw-bold">
<tr>
    <th onclick="sortTable(0)">學生</th>
    <th onclick="sortTable(1)">學號</th>
    <th onclick="sortTable(2)">完成題數</th>
    <th onclick="sortTable(3)">題目完成率</th>
    <th onclick="sortTable(4)">嘗試成功率</th>
    <th onclick="sortTable(5)">作答次數</th>
    <th onclick="sortTable(6)">學習時間（秒）</th>
    <th onclick="sortTable(7)">心智圖</th>
    <th onclick="sortTable(8)">流程圖</th>
    <th onclick="sortTable(9)">學習型態</th>
</tr>
</thead>
<tbody>

<?php if (empty($studentOverview)): ?>
<tr><td colspan="9">📭 尚無學生資料</td></tr>
<?php else: foreach ($studentOverview as $s): 
    $completionRate = $totalQuestions > 0
        ? round(($s['completed_questions'] / $totalQuestions) * 100, 1)
        : 0;
    $attemptSuccessRate = $s['attempts'] > 0
        ? round(($s['correct_questions'] / $s['attempts']) * 100, 1)
        : 0;

    if ($s['mindmap_clicks'] > $s['flowchart_clicks']) {
        $type = '心智圖導向';
    } elseif ($s['flowchart_clicks'] > $s['mindmap_clicks']) {
        $type = '流程圖導向';
    } elseif ($s['flowchart_clicks'] == 0 && $s['mindmap_clicks'] == 0) {
        $type = '無使用紀錄';
    } else {
        $type = '混合型';
    }
?>
<tr>
    <td><?= htmlspecialchars($s['Username']) ?></td>
    <td><?= $s['StudentID'] ?></td>
    <td><?= $s['completed_questions'] ?></td>
    <td><?= $completionRate ?>%</td>
    <td><?= $attemptSuccessRate ?>%</td>
    <td><?= $s['attempts'] ?></td>
    <td><?= $s['total_time'] ?? 0 ?></td>
    <td><?= $s['mindmap_clicks'] ?? 0 ?></td>
    <td><?= $s['flowchart_clicks'] ?? 0 ?></td>
    <td><?= $type ?></td>
</tr>
<?php endforeach; endif; ?>

</tbody>
</table>
</div>

</body>
</html>

<script>
    const chapterLabels = <?= json_encode(array_map(fn($c) => "第 {$c} 章", array_keys($visualChapter))) ?>;
    const mindmapData   = <?= json_encode(array_column($visualChapter, 'mindmap')) ?>;
    const flowchartData = <?= json_encode(array_column($visualChapter, 'flowchart')) ?>;

    new Chart(document.getElementById('chapterToolChart'), {
        type: 'bar',
        data: {
            labels: chapterLabels,
            datasets: [
                {
                    label: '心智圖',
                    data: mindmapData,
                    backgroundColor: '#ffc107'
                },
                {
                    label: '流程圖',
                    data: flowchartData,
                    backgroundColor: '#0d6efd'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                datalabels: {
                    color: '#000',
                    anchor: 'center',
                    align: 'center',
                    font: {
                        weight: 'bold',
                        size: 12
                    },
                    formatter: value => value > 0 ? value : ''
                }
            },
            scales: {
                x: { stacked: true },
                y: {
                    stacked: true,
                    beginAtZero: true
                }
            }
        },
        plugins: [ChartDataLabels] // ⭐ 必加
    });

    const activeCount   = <?= $activeStudents ?>;
    const inactiveCount = <?= $inactiveStudents ?>;
    new Chart(document.getElementById('usageChart'), {
        type: 'pie',
        data: {
            labels: ['有上平台練習', '完全沒上'],
            datasets: [{
                data: [activeCount, inactiveCount],
                backgroundColor: ['#8adf77ff', '#57635dff']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                datalabels: {
                    color: '#fff',
                    font: {
                        weight: 'bold',
                        size: 14
                    },
                    formatter: (value, ctx) => {
                        const total = ctx.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                        const percent = ((value / total) * 100).toFixed(1);
                        return `${value}\n(${percent}%)`;
                    }
                },
                legend: {
                    position: 'bottom'
                }
            }
        },
        plugins: [ChartDataLabels]
    });


let sortDirection = {}; // 紀錄每一欄目前排序方向

function sortTable(colIndex) {
    const table = document.getElementById("studentTable");
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);

    // 預設：第一次點是由大到小
    const dir = sortDirection[colIndex] === "desc" ? "asc" : "desc";
    sortDirection[colIndex] = dir;

    rows.sort((a, b) => {
        let A = a.cells[colIndex].innerText.trim();
        let B = b.cells[colIndex].innerText.trim();

        // 處理百分比
        if (A.includes('%')) A = parseFloat(A);
        if (B.includes('%')) B = parseFloat(B);

        // 處理數字
        if (!isNaN(A) && !isNaN(B)) {
            return dir === "desc" ? B - A : A - B;
        }

        // 文字（學生姓名、學習型態）
        return dir === "desc"
            ? B.localeCompare(A, 'zh-Hant')
            : A.localeCompare(B, 'zh-Hant');
    });

    // 重畫 tbody
    rows.forEach(row => tbody.appendChild(row));
}

</script>
