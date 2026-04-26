<?php
/**
 * موجّه API الرئيسي
 * يستقبل جميع طلبات /api/* ويوزّعها على الدوال المناسبة
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// استخراج المسار من الطلب
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// إزالة /api من البداية
$path = preg_replace('#^/api/?#', '', $uri);
$path = trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// CORS (إن لزم)
header('Access-Control-Allow-Credentials: true');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// التوجيه
try {
    switch (true) {

        // ── الصحة ──
        case ($path === 'health' || $path === 'healthz') && $method === 'GET':
            send_json(['status' => 'ok', 'time' => date('c')]);

        // ── المصادقة ──
        case $path === 'auth/login' && $method === 'POST':
            handle_login();
        case $path === 'auth/logout' && $method === 'POST':
            handle_logout();
        case $path === 'auth/me' && $method === 'GET':
            handle_me();

        // ── الأسئلة ──
        case $path === 'questions/counts' && $method === 'GET':
            handle_questions_counts();
        case $path === 'questions/bulk' && $method === 'POST':
            handle_questions_bulk();
        case $path === 'questions' && $method === 'GET':
            handle_questions_list();
        case $path === 'questions' && $method === 'POST':
            handle_question_create();
        case preg_match('#^questions/(\d+)$#', $path, $m) && $method === 'PUT':
            handle_question_update((int)$m[1]);
        case preg_match('#^questions/(\d+)$#', $path, $m) && $method === 'DELETE':
            handle_question_delete((int)$m[1]);

        // ── النتائج ──
        case $path === 'scores' && $method === 'POST':
            handle_score_submit();
        case $path === 'scores/leaderboard' && $method === 'GET':
            handle_leaderboard();

        // ── محتوى الدروس ──
        case $path === 'lessons/content' && $method === 'GET':
            handle_lesson_content_get();
        case $path === 'lessons/content' && $method === 'PUT':
            handle_lesson_content_put();

        default:
            send_json(['error' => 'Route not found: ' . $method . ' /' . $path], 404);
    }
} catch (Throwable $e) {
    error_log('[API ERROR] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    send_json(['error' => 'Database error'], 500);
}

// ═══════════════════════════════════════════════════
// المعالجات (Handlers)
// ═══════════════════════════════════════════════════

// ── المصادقة ──

function handle_login() {
    $body = read_json_body();
    $u = $body['username'] ?? '';
    $p = $body['password'] ?? '';
    if ($u === ADMIN_USERNAME && $p === ADMIN_PASSWORD) {
        start_session_safe();
        $_SESSION['is_admin'] = true;
        send_json(['success' => true]);
    } else {
        send_json(['error' => 'اسم المستخدم أو كلمة المرور غير صحيحة'], 401);
    }
}

function handle_logout() {
    start_session_safe();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    send_json(['success' => true]);
}

function handle_me() {
    start_session_safe();
    send_json(['isAdmin' => !empty($_SESSION['is_admin'])]);
}

// ── الأسئلة ──

function handle_questions_counts() {
    $grade = $_GET['gradeKey'] ?? '';
    if (!$grade) send_json(['error' => 'gradeKey required'], 400);
    $stmt = db()->prepare(
        "SELECT unit_index, lesson_index, COUNT(*) AS count
         FROM questions WHERE grade_key = ?
         GROUP BY unit_index, lesson_index"
    );
    $stmt->execute([$grade]);
    $counts = [];
    foreach ($stmt->fetchAll() as $r) {
        $counts[$r['unit_index'] . '|' . $r['lesson_index']] = (int)$r['count'];
    }
    send_json($counts);
}

function handle_questions_list() {
    $where = [];
    $params = [];
    if (isset($_GET['gradeKey'])) {
        $where[] = 'grade_key = ?';
        $params[] = $_GET['gradeKey'];
    }
    if (isset($_GET['unitIndex'])) {
        $where[] = 'unit_index = ?';
        $params[] = (int)$_GET['unitIndex'];
    }
    if (isset($_GET['lessonIndex'])) {
        $where[] = 'lesson_index = ?';
        $params[] = (int)$_GET['lessonIndex'];
    }
    $sql = 'SELECT * FROM questions';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // تحويل بعض الحقول العددية
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['unit_index'] = (int)$r['unit_index'];
        $r['lesson_index'] = (int)$r['lesson_index'];
        $r['correct_answer'] = (int)$r['correct_answer'];
    }
    send_json($rows);
}

function handle_question_create() {
    require_admin();
    $b = read_json_body();
    $required = ['grade_key','unit_index','lesson_index','question_text','option_a','option_b','option_c','option_d','correct_answer'];
    foreach ($required as $f) {
        if (!isset($b[$f]) || $b[$f] === '') {
            send_json(['error' => 'Missing required fields'], 400);
        }
    }
    try {
        $stmt = db()->prepare(
            "INSERT INTO questions (grade_key, unit_index, lesson_index, question_text, question_hash, option_a, option_b, option_c, option_d, correct_answer, image_url)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $b['grade_key'], (int)$b['unit_index'], (int)$b['lesson_index'],
            $b['question_text'], hash('sha256', $b['question_text']),
            $b['option_a'], $b['option_b'], $b['option_c'], $b['option_d'],
            (int)$b['correct_answer'], $b['image_url'] ?? null
        ]);
        $id = (int)db()->lastInsertId();
        $row = db()->query("SELECT * FROM questions WHERE id=$id")->fetch();
        send_json(cast_question($row), 201);
    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
            send_json(['error' => 'السؤال موجود مسبقاً في هذا الدرس'], 409);
        }
        throw $e;
    }
}

function cast_question($row) {
    if (!$row) return $row;
    $row['id'] = (int)$row['id'];
    $row['unit_index'] = (int)$row['unit_index'];
    $row['lesson_index'] = (int)$row['lesson_index'];
    $row['correct_answer'] = (int)$row['correct_answer'];
    return $row;
}

function handle_question_update(int $id) {
    require_admin();
    $b = read_json_body();
    $stmt = db()->prepare(
        "UPDATE questions SET question_text=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_answer=?, image_url=?
         WHERE id=?"
    );
    $stmt->execute([
        $b['question_text'] ?? '', $b['option_a'] ?? '', $b['option_b'] ?? '',
        $b['option_c'] ?? '', $b['option_d'] ?? '',
        (int)($b['correct_answer'] ?? 0), $b['image_url'] ?? null, $id
    ]);
    if ($stmt->rowCount() === 0) {
        $check = db()->query("SELECT id FROM questions WHERE id=$id")->fetch();
        if (!$check) send_json(['error' => 'Not found'], 404);
    }
    $row = db()->query("SELECT * FROM questions WHERE id=$id")->fetch();
    send_json(cast_question($row));
}

function handle_question_delete(int $id) {
    require_admin();
    $stmt = db()->prepare("DELETE FROM questions WHERE id=?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) send_json(['error' => 'Not found'], 404);
    send_json(['success' => true]);
}

function handle_questions_bulk() {
    require_admin();
    $b = read_json_body();
    $grade = $b['grade_key'] ?? '';
    $unit  = isset($b['unit_index']) ? (int)$b['unit_index'] : null;
    $lesson = isset($b['lesson_index']) ? (int)$b['lesson_index'] : null;
    $questions = $b['questions'] ?? null;
    if (!$grade || $unit === null || $lesson === null || !is_array($questions) || empty($questions)) {
        send_json(['error' => 'Missing required fields'], 400);
    }
    $inserted = 0; $skipped = 0; $errors = [];
    $stmt = db()->prepare(
        "INSERT INTO questions (grade_key, unit_index, lesson_index, question_text, question_hash, option_a, option_b, option_c, option_d, correct_answer)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($questions as $i => $q) {
        if (!isset($q['question_text'], $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'], $q['correct_answer'])) {
            $errors[] = ['row' => $i + 2, 'error' => 'بيانات ناقصة'];
            continue;
        }
        try {
            $stmt->execute([
                $grade, $unit, $lesson,
                $q['question_text'], hash('sha256', $q['question_text']),
                $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'],
                (int)$q['correct_answer']
            ]);
            $inserted++;
        } catch (PDOException $e) {
            // 23000 = unique violation = duplicate, نتجاهلها بهدوء
            if ($e->getCode() == '23000') {
                $skipped++;
            } else {
                error_log('[BULK INSERT ROW ' . ($i+2) . '] ' . $e->getMessage());
                $errors[] = ['row' => $i + 2, 'error' => 'Database error'];
            }
        }
    }
    send_json(['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
}

// ── النتائج ──

function handle_score_submit() {
    $b = read_json_body();
    $name = trim((string)($b['studentName'] ?? ''));
    $grade = $b['gradeKey'] ?? '';
    $unit = $b['unitIndex'] ?? null;
    $lesson = $b['lessonIndex'] ?? null;
    $score = $b['score'] ?? null;
    $total = $b['total'] ?? null;
    if ($name === '' || !$grade || $unit === null || $lesson === null || $score === null || $total === null) {
        send_json(['error' => 'بيانات ناقصة'], 400);
    }
    $name = mb_substr($name, 0, 100);

    $stmt = db()->prepare(
        "INSERT INTO student_scores (student_name, grade_key, unit_index, lesson_index, score, total)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           score = GREATEST(score, VALUES(score)),
           total = VALUES(total),
           created_at = NOW()"
    );
    $stmt->execute([$name, $grade, (int)$unit, (int)$lesson, (int)$score, (int)$total]);
    send_json(['success' => true]);
}

function handle_leaderboard() {
    $grade = $_GET['gradeKey'] ?? '';
    if (!$grade) send_json(['error' => 'gradeKey مطلوب'], 400);
    $stmt = db()->prepare(
        "SELECT
           student_name,
           SUM(score) AS total_score,
           SUM(total) AS total_possible,
           COUNT(*) AS lessons_count,
           ROUND(SUM(score) / NULLIF(SUM(total),0) * 100, 1) AS pct
         FROM student_scores
         WHERE grade_key = ?
         GROUP BY student_name
         ORDER BY total_score DESC, pct DESC
         LIMIT 5"
    );
    $stmt->execute([$grade]);
    send_json($stmt->fetchAll());
}

// ── محتوى الدروس ──

function handle_lesson_content_get() {
    $g = $_GET['gradeKey'] ?? '';
    $u = $_GET['unitIndex'] ?? null;
    $l = $_GET['lessonIndex'] ?? null;
    if (!$g || $u === null || $l === null) send_json(['content' => null]);
    $stmt = db()->prepare(
        "SELECT content, updated_at FROM lesson_content WHERE grade_key=? AND unit_index=? AND lesson_index=?"
    );
    $stmt->execute([$g, (int)$u, (int)$l]);
    $row = $stmt->fetch();
    if (!$row) send_json(['content' => null]);
    send_json(['content' => $row['content'], 'updated_at' => $row['updated_at']]);
}

function handle_lesson_content_put() {
    require_admin();
    $b = read_json_body();
    $g = $b['grade_key'] ?? '';
    $u = $b['unit_index'] ?? null;
    $l = $b['lesson_index'] ?? null;
    $c = $b['content'] ?? '';
    if (!$g || $u === null || $l === null || !$c) {
        send_json(['error' => 'Missing required fields'], 400);
    }
    $stmt = db()->prepare(
        "INSERT INTO lesson_content (grade_key, unit_index, lesson_index, content)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE content=VALUES(content), updated_at=NOW()"
    );
    $stmt->execute([$g, (int)$u, (int)$l, $c]);
    send_json(['success' => true]);
}
