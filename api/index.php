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
        case $path === 'auth/register' && $method === 'POST':
            handle_register();
        case $path === 'auth/student-login' && $method === 'POST':
            handle_student_login();

        // ── التقدم ──
        case $path === 'progress' && $method === 'POST':
            handle_progress_mark();
        case $path === 'progress' && $method === 'GET':
            handle_progress_get();
        case $path === 'progress/summary' && $method === 'GET':
            handle_progress_summary();

        // ── الأسئلة ──
        case $path === 'questions/counts' && $method === 'GET':
            handle_questions_counts();
        case $path === 'questions/bulk' && $method === 'POST':
            handle_questions_bulk();
        case $path === 'questions/random' && $method === 'GET':
            handle_questions_random();
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

        // ── إحصائيات الإدارة ──
        case $path === 'admin/stats' && $method === 'GET':
            handle_admin_stats();
        case preg_match('#^admin/students/(\d+)/progress$#', $path, $m) && $method === 'GET':
            handle_admin_student_progress((int)$m[1]);
        case preg_match('#^admin/students/(\d+)$#', $path, $m) && $method === 'DELETE':
            handle_admin_student_delete((int)$m[1]);

        // ── المنهج الدراسي (وحدات/دروس مخصصة) ──
        case $path === 'curriculum' && $method === 'GET':
            handle_curriculum_get();
        case $path === 'curriculum/units' && $method === 'PUT':
            handle_curriculum_unit_upsert();
        case $path === 'curriculum/lessons' && $method === 'PUT':
            handle_curriculum_lesson_upsert();

        // ── فيديو الدرس ──
        case $path === 'progress/video' && $method === 'POST':
            handle_progress_video();

        // ── رفع الصور ──
        case $path === 'upload' && $method === 'POST':
            handle_upload();

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
    $isAdmin = !empty($_SESSION['is_admin']);
    $studentId = $_SESSION['student_id'] ?? null;
    if ($studentId) {
        $stmt = db()->prepare("SELECT id, name, grade_level, total_points FROM users WHERE id = ?");
        $stmt->execute([$studentId]);
        $u = $stmt->fetch();
        send_json(['isAdmin' => $isAdmin, 'user' => $u ? [
            'id'           => (int)$u['id'],
            'name'         => $u['name'],
            'grade_level'  => $u['grade_level'],
            'total_points' => (int)$u['total_points'],
        ] : null]);
    }
    send_json(['isAdmin' => $isAdmin, 'user' => null]);
}

function handle_register() {
    $b = read_json_body();
    $name     = trim($b['name'] ?? '');
    $username = strtolower(trim($b['username'] ?? ''));
    $password = $b['password'] ?? '';
    $grade    = $b['grade_level'] ?? '';
    if (!$name || !$username || !$password || !$grade) {
        send_json(['error' => 'جميع الحقول مطلوبة'], 400);
    }
    if (!in_array($grade, ['first', 'second'])) {
        send_json(['error' => 'المرحلة الدراسية غير صحيحة'], 400);
    }
    if (mb_strlen($password) < 6) {
        send_json(['error' => 'كلمة المرور يجب أن تكون ٦ أحرف على الأقل'], 400);
    }
    $check = db()->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        send_json(['error' => 'اسم المستخدم مستخدم بالفعل، جرّبي اسماً آخر'], 409);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        "INSERT INTO users (name, username, password_hash, grade_level) VALUES (?,?,?,?)"
    );
    $stmt->execute([$name, $username, $hash, $grade]);
    $id = (int)db()->lastInsertId();
    start_session_safe();
    $_SESSION['student_id']    = $id;
    $_SESSION['student_name']  = $name;
    $_SESSION['student_grade'] = $grade;
    send_json(['success' => true, 'user' => [
        'id'           => $id,
        'name'         => $name,
        'grade_level'  => $grade,
        'total_points' => 0,
    ]], 201);
}

function handle_student_login() {
    $b        = read_json_body();
    $username = strtolower(trim($b['username'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$username || !$password) {
        send_json(['error' => 'اسم المستخدم وكلمة المرور مطلوبان'], 400);
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        send_json(['error' => 'اسم المستخدم أو كلمة المرور غير صحيحة'], 401);
    }
    start_session_safe();
    $_SESSION['student_id']    = (int)$u['id'];
    $_SESSION['student_name']  = $u['name'];
    $_SESSION['student_grade'] = $u['grade_level'];
    send_json(['success' => true, 'user' => [
        'id'           => (int)$u['id'],
        'name'         => $u['name'],
        'grade_level'  => $u['grade_level'],
        'total_points' => (int)$u['total_points'],
    ]]);
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

function handle_questions_random() {
    $grade = $_GET['gradeKey'] ?? '';
    $unit  = isset($_GET['unitIndex']) ? (int)$_GET['unitIndex'] : null;
    $count = min((int)($_GET['count'] ?? 2), 20);
    if (!$grade) send_json(['error' => 'gradeKey مطلوب'], 400);
    $sql    = "SELECT * FROM questions WHERE grade_key = ?";
    $params = [$grade];
    if ($unit !== null) { $sql .= " AND unit_index = ?"; $params[] = $unit; }
    $sql .= " ORDER BY RAND() LIMIT ?";
    $params[] = $count;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r = cast_question($r);
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
            "INSERT INTO questions (grade_key, unit_index, lesson_index, question_text, question_hash, option_a, option_b, option_c, option_d, correct_answer, explanation, image_url)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $b['grade_key'], (int)$b['unit_index'], (int)$b['lesson_index'],
            $b['question_text'], hash('sha256', $b['question_text']),
            $b['option_a'], $b['option_b'], $b['option_c'], $b['option_d'],
            (int)$b['correct_answer'], $b['explanation'] ?? null, $b['image_url'] ?? null
        ]);
        $id = (int)db()->lastInsertId();
        $row = db()->prepare("SELECT * FROM questions WHERE id=?")->execute([$id]);
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
        "UPDATE questions SET question_text=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_answer=?, explanation=?, image_url=?
         WHERE id=?"
    );
    $stmt->execute([
        $b['question_text'] ?? '', $b['option_a'] ?? '', $b['option_b'] ?? '',
        $b['option_c'] ?? '', $b['option_d'] ?? '',
        (int)($b['correct_answer'] ?? 0), $b['explanation'] ?? null, $b['image_url'] ?? null, $id
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
    start_session_safe();
    $b      = read_json_body();
    $grade  = $b['gradeKey'] ?? '';
    $unit   = $b['unitIndex'] ?? null;
    $lesson = $b['lessonIndex'] ?? null;
    $score  = $b['score'] ?? null;
    $total  = $b['total'] ?? null;

    $studentId = $_SESSION['student_id'] ?? null;

    // اسم الطالبة: إما من الحساب أو من الحقل المرسل
    if ($studentId) {
        $name = $_SESSION['student_name'] ?? '';
    } else {
        $name = trim((string)($b['studentName'] ?? ''));
    }

    if ($name === '' || !$grade || $unit === null || $lesson === null || $score === null || $total === null) {
        send_json(['error' => 'بيانات ناقصة'], 400);
    }
    $name = mb_substr($name, 0, 100);

    $stmt = db()->prepare(
        "INSERT INTO student_scores (student_name, grade_key, unit_index, lesson_index, score, total)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           score = GREATEST(score, score + 0),
           total = total,
           created_at = NOW()"
    );
    $stmt->execute([$name, $grade, (int)$unit, (int)$lesson, (int)$score, (int)$total]);

    $pointsEarned = 0;
    if ($studentId) {
        // نقاط = ١٠ لكل إجابة صحيحة
        $pointsEarned = (int)$score * 10;
        db()->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")
            ->execute([$pointsEarned, $studentId]);
        // تسجيل إتمام الدرس
        db()->prepare(
            "INSERT IGNORE INTO lesson_progress (user_id, grade_key, unit_index, lesson_index) VALUES (?,?,?,?)"
        )->execute([$studentId, $grade, (int)$unit, (int)$lesson]);
    }

    send_json(['success' => true, 'points_earned' => $pointsEarned]);
}

// ── التقدم ──

function handle_progress_mark() {
    start_session_safe();
    $studentId = $_SESSION['student_id'] ?? null;
    if (!$studentId) send_json(['error' => 'يجب تسجيل الدخول أولاً'], 401);
    $b      = read_json_body();
    $grade  = $b['grade_key'] ?? '';
    $unit   = isset($b['unit_index'])   ? (int)$b['unit_index']   : null;
    $lesson = isset($b['lesson_index']) ? (int)$b['lesson_index'] : null;
    if (!$grade || $unit === null || $lesson === null) {
        send_json(['error' => 'بيانات ناقصة'], 400);
    }
    db()->prepare(
        "INSERT IGNORE INTO lesson_progress (user_id, grade_key, unit_index, lesson_index) VALUES (?,?,?,?)"
    )->execute([$studentId, $grade, $unit, $lesson]);
    send_json(['success' => true]);
}

function handle_progress_get() {
    start_session_safe();
    $studentId = $_SESSION['student_id'] ?? null;
    if (!$studentId) send_json(['completed' => [], 'videos' => [], 'total_points' => 0]);
    $grade = $_GET['gradeKey'] ?? null;

    $sql = "SELECT grade_key, unit_index, lesson_index FROM lesson_progress WHERE user_id = ?";
    $params = [$studentId];
    if ($grade) { $sql .= " AND grade_key = ?"; $params[] = $grade; }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $completed = array_map(
        fn($r) => $r['grade_key'] . '|' . $r['unit_index'] . '|' . $r['lesson_index'],
        $stmt->fetchAll()
    );

    // مشاهدات الفيديو
    $videos = [];
    try {
        $vSql = "SELECT grade_key, unit_index, lesson_index FROM video_progress WHERE user_id = ?";
        $vParams = [$studentId];
        if ($grade) { $vSql .= " AND grade_key = ?"; $vParams[] = $grade; }
        $vStmt = db()->prepare($vSql);
        $vStmt->execute($vParams);
        $videos = array_map(
            fn($r) => $r['grade_key'] . '|' . $r['unit_index'] . '|' . $r['lesson_index'],
            $vStmt->fetchAll()
        );
    } catch (Throwable $e) { /* جدول قد لا يوجد بعد */ }

    $pts = db()->prepare("SELECT total_points FROM users WHERE id = ?");
    $pts->execute([$studentId]);
    $row = $pts->fetch();
    send_json([
        'completed'    => $completed,
        'videos'       => $videos,
        'total_points' => $row ? (int)$row['total_points'] : 0,
    ]);
}

function handle_progress_summary() {
    start_session_safe();
    $studentId = $_SESSION['student_id'] ?? null;
    if (!$studentId) send_json(['error' => 'غير مسجّل'], 401);
    $stmt = db()->prepare(
        "SELECT grade_key, unit_index, lesson_index FROM lesson_progress WHERE user_id = ? ORDER BY completed_at DESC"
    );
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll();
    $byGrade = [];
    foreach ($rows as $r) {
        $byGrade[$r['grade_key']][] = ['unit' => (int)$r['unit_index'], 'lesson' => (int)$r['lesson_index']];
    }
    $u = db()->prepare("SELECT name, grade_level, total_points FROM users WHERE id = ?");
    $u->execute([$studentId]);
    $user = $u->fetch();
    send_json([
        'user'     => $user,
        'progress' => $byGrade,
        'total'    => count($rows),
    ]);
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
    if (!$g || $u === null || $l === null) send_json(['content' => null, 'video_url' => null]);
    $stmt = db()->prepare(
        "SELECT content, video_url, updated_at FROM lesson_content WHERE grade_key=? AND unit_index=? AND lesson_index=?"
    );
    $stmt->execute([$g, (int)$u, (int)$l]);
    $row = $stmt->fetch();
    if (!$row) send_json(['content' => null, 'video_url' => null]);
    send_json(['content' => $row['content'], 'video_url' => $row['video_url'], 'updated_at' => $row['updated_at']]);
}

function handle_lesson_content_put() {
    require_admin();
    $b = read_json_body();
    $g   = $b['grade_key'] ?? '';
    $u   = $b['unit_index'] ?? null;
    $l   = $b['lesson_index'] ?? null;
    $c   = $b['content'] ?? '';
    $vid = $b['video_url'] ?? null;
    if (!$g || $u === null || $l === null) {
        send_json(['error' => 'Missing required fields'], 400);
    }
    $stmt = db()->prepare(
        "INSERT INTO lesson_content (grade_key, unit_index, lesson_index, content, video_url)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE content=VALUES(content), video_url=VALUES(video_url), updated_at=NOW()"
    );
    $stmt->execute([$g, (int)$u, (int)$l, $c ?: '', $vid]);
    send_json(['success' => true]);
}

// ── المنهج الدراسي ──

function handle_curriculum_get() {
    $gradeKey = $_GET['gradeKey'] ?? '';
    if (!in_array($gradeKey, ['first', 'second'])) {
        send_json(['units' => []]);
    }
    $pdo = db();
    $uStmt = $pdo->prepare(
        "SELECT id, unit_index, title, emoji FROM curriculum_units WHERE grade_key=? ORDER BY unit_index"
    );
    $uStmt->execute([$gradeKey]);
    $units = $uStmt->fetchAll(PDO::FETCH_ASSOC);

    $lStmt = $pdo->prepare(
        "SELECT id, unit_index, lesson_index, title FROM curriculum_lessons WHERE grade_key=? ORDER BY unit_index, lesson_index"
    );
    $lStmt->execute([$gradeKey]);
    $allLessons = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $lessonsByUnit = [];
    foreach ($allLessons as $l) {
        $lessonsByUnit[(int)$l['unit_index']][] = [
            'id'           => (int)$l['id'],
            'lesson_index' => (int)$l['lesson_index'],
            'title'        => $l['title'],
        ];
    }

    $result = [];
    foreach ($units as $u) {
        $ui = (int)$u['unit_index'];
        $result[] = [
            'id'         => (int)$u['id'],
            'unit_index' => $ui,
            'title'      => $u['title'],
            'emoji'      => $u['emoji'],
            'lessons'    => $lessonsByUnit[$ui] ?? [],
        ];
    }
    send_json(['units' => $result]);
}

function handle_curriculum_unit_upsert() {
    require_admin();
    $b         = read_json_body();
    $gradeKey  = $b['grade_key'] ?? '';
    $title     = trim($b['title'] ?? '');
    $emoji     = trim($b['emoji'] ?? '📚');
    if (!$title || !in_array($gradeKey, ['first', 'second'])) {
        send_json(['error' => 'بيانات غير صالحة'], 400);
    }
    $pdo = db();
    if (isset($b['unit_index'])) {
        $unitIndex = (int)$b['unit_index'];
    } else {
        $maxDb = (int)$pdo->prepare("SELECT COALESCE(MAX(unit_index),-1) FROM curriculum_units WHERE grade_key=?")
                           ->execute([$gradeKey]) ? $pdo->query("SELECT COALESCE(MAX(unit_index),-1) FROM curriculum_units WHERE grade_key='$gradeKey'")->fetchColumn() : -1;
        $hardcoded = (int)($b['hardcoded_count'] ?? 0);
        $unitIndex = max((int)$maxDb + 1, $hardcoded);
    }
    $pdo->prepare(
        "INSERT INTO curriculum_units (grade_key, unit_index, title, emoji) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE title=VALUES(title), emoji=VALUES(emoji)"
    )->execute([$gradeKey, $unitIndex, $title, $emoji]);
    send_json(['ok' => true, 'unit_index' => $unitIndex]);
}

function handle_curriculum_lesson_upsert() {
    require_admin();
    $b           = read_json_body();
    $gradeKey    = $b['grade_key'] ?? '';
    $unitIndex   = isset($b['unit_index']) ? (int)$b['unit_index'] : null;
    $title       = trim($b['title'] ?? '');
    if (!$title || !in_array($gradeKey, ['first', 'second']) || $unitIndex === null) {
        send_json(['error' => 'بيانات غير صالحة'], 400);
    }
    $pdo = db();
    if (isset($b['lesson_index'])) {
        $lessonIndex = (int)$b['lesson_index'];
    } else {
        $maxRow = $pdo->prepare("SELECT COALESCE(MAX(lesson_index),-1) FROM curriculum_lessons WHERE grade_key=? AND unit_index=?");
        $maxRow->execute([$gradeKey, $unitIndex]);
        $maxDb     = (int)$maxRow->fetchColumn();
        $hardcoded = (int)($b['hardcoded_count'] ?? 0);
        $lessonIndex = max($maxDb + 1, $hardcoded);
    }
    $pdo->prepare(
        "INSERT INTO curriculum_lessons (grade_key, unit_index, lesson_index, title) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE title=VALUES(title)"
    )->execute([$gradeKey, $unitIndex, $lessonIndex, $title]);
    send_json(['ok' => true, 'lesson_index' => $lessonIndex]);
}

// ── مشاهدة الفيديو ──

function handle_progress_video() {
    start_session_safe();
    $userId = $_SESSION['student_id'] ?? null;
    if (!$userId) send_json(['error' => 'غير مسجّل'], 401);

    $b           = read_json_body();
    $gradeKey    = $b['gradeKey']    ?? '';
    $unitIndex   = isset($b['unitIndex'])   ? (int)$b['unitIndex']   : null;
    $lessonIndex = isset($b['lessonIndex']) ? (int)$b['lessonIndex'] : null;
    if (!$gradeKey || $unitIndex === null || $lessonIndex === null) {
        send_json(['error' => 'بيانات ناقصة'], 400);
    }

    $pdo = db();
    // إذا سبق المشاهدة لا نعطي نقاط ثانية
    $check = $pdo->prepare(
        "SELECT id FROM video_progress WHERE user_id=? AND grade_key=? AND unit_index=? AND lesson_index=?"
    );
    $check->execute([$userId, $gradeKey, $unitIndex, $lessonIndex]);
    if ($check->fetch()) {
        send_json(['ok' => true, 'points_earned' => 0, 'already_watched' => true]);
    }

    $pdo->prepare(
        "INSERT IGNORE INTO video_progress (user_id, grade_key, unit_index, lesson_index) VALUES (?,?,?,?)"
    )->execute([$userId, $gradeKey, $unitIndex, $lessonIndex]);

    $points = 5;
    $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")
        ->execute([$points, $userId]);

    send_json(['ok' => true, 'points_earned' => $points]);
}

// ── إحصائيات الإدارة ──

function handle_admin_stats() {
    require_admin();
    $pdo = db();

    $students_count    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_completions = (int)$pdo->query("SELECT COUNT(*) FROM lesson_progress")->fetchColumn();
    $total_videos      = 0;
    try { $total_videos = (int)$pdo->query("SELECT COUNT(*) FROM video_progress")->fetchColumn(); } catch (Throwable $e) {}
    $total_points      = (int)($pdo->query("SELECT COALESCE(SUM(total_points),0) FROM users")->fetchColumn());

    // قائمة الطالبات مع إجمالياتهن
    $stmt = $pdo->query(
        "SELECT u.id, u.name, u.username, u.grade_level, u.total_points, u.created_at,
                COUNT(DISTINCT lp.id) AS lessons_done
         FROM users u
         LEFT JOIN lesson_progress lp ON lp.user_id = u.id
         GROUP BY u.id, u.name, u.username, u.grade_level, u.total_points, u.created_at
         ORDER BY u.total_points DESC, u.name ASC"
    );
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // إضافة مشاهدات الفيديو لكل طالبة
    $vCounts = [];
    try {
        $vStmt = $pdo->query("SELECT user_id, COUNT(*) AS vc FROM video_progress GROUP BY user_id");
        while ($row = $vStmt->fetch()) $vCounts[(int)$row['user_id']] = (int)$row['vc'];
    } catch (Throwable $e) {}

    foreach ($students as &$s) {
        $s['id']           = (int)$s['id'];
        $s['total_points'] = (int)$s['total_points'];
        $s['lessons_done'] = (int)$s['lessons_done'];
        $s['videos_done']  = $vCounts[(int)$s['id']] ?? 0;
    }
    unset($s);

    // أكثر الدروس اكتمالاً
    $topStmt = $pdo->query(
        "SELECT grade_key, unit_index, lesson_index, COUNT(*) AS cnt
         FROM lesson_progress
         GROUP BY grade_key, unit_index, lesson_index
         ORDER BY cnt DESC LIMIT 5"
    );
    $top_lessons = $topStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($top_lessons as &$l) { $l['cnt'] = (int)$l['cnt']; $l['unit_index'] = (int)$l['unit_index']; $l['lesson_index'] = (int)$l['lesson_index']; }

    // الدروس الأقل اكتمالاً (بين الدروس التي لها اكتمال ≤ 2)
    $lowStmt = $pdo->query(
        "SELECT grade_key, unit_index, lesson_index, COUNT(*) AS cnt
         FROM lesson_progress
         GROUP BY grade_key, unit_index, lesson_index
         HAVING cnt <= 2
         ORDER BY cnt ASC LIMIT 5"
    );
    $low_lessons = $lowStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($low_lessons as &$l) { $l['cnt'] = (int)$l['cnt']; $l['unit_index'] = (int)$l['unit_index']; $l['lesson_index'] = (int)$l['lesson_index']; }

    send_json([
        'students_count'    => $students_count,
        'total_completions' => $total_completions,
        'total_videos'      => $total_videos,
        'total_points'      => $total_points,
        'students'          => $students,
        'top_lessons'       => $top_lessons,
        'low_lessons'       => $low_lessons,
    ]);
}

function handle_admin_student_progress(int $userId) {
    require_admin();
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT grade_key, unit_index, lesson_index FROM lesson_progress WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $completed = array_map(
        fn($r) => $r['grade_key'] . '|' . $r['unit_index'] . '|' . $r['lesson_index'],
        $stmt->fetchAll()
    );

    $videos = [];
    try {
        $vStmt = $pdo->prepare("SELECT grade_key, unit_index, lesson_index FROM video_progress WHERE user_id = ?");
        $vStmt->execute([$userId]);
        $videos = array_map(
            fn($r) => $r['grade_key'] . '|' . $r['unit_index'] . '|' . $r['lesson_index'],
            $vStmt->fetchAll()
        );
    } catch (Throwable $e) {}

    send_json(['completed' => $completed, 'videos' => $videos]);
}

function handle_admin_student_delete(int $userId) {
    require_admin();
    if (!$userId) send_json(['error' => 'معرّف غير صالح'], 400);
    db()->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    send_json(['ok' => true]);
}

// ── رفع الصور ──
function handle_upload() {
    require_admin();

    if (empty($_FILES['image'])) {
        send_json(['error' => 'لم يُرسَل أي ملف'], 400);
    }

    $file = $_FILES['image'];

    // أخطاء رفع PHP
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحد المسموح في الخادم',
        UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحد المسموح في الفورم',
        UPLOAD_ERR_PARTIAL    => 'رُفع الملف جزئياً فقط',
        UPLOAD_ERR_NO_FILE    => 'لم يُختر أي ملف',
        UPLOAD_ERR_NO_TMP_DIR => 'مجلد temp غير موجود',
        UPLOAD_ERR_CANT_WRITE => 'فشل الكتابة على القرص',
    ];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        send_json(['error' => $uploadErrors[$file['error']] ?? 'خطأ في الرفع'], 400);
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        send_json(['error' => 'حجم الصورة يتجاوز 5MB'], 413);
    }

    // التحقق من أن الملف صورة حقيقية (getimagesize لا يحتاج finfo)
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        send_json(['error' => 'الملف ليس صورة صالحة'], 415);
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $mime = $info['mime'];
    if (!isset($mimeToExt[$mime])) {
        send_json(['error' => 'نوع غير مدعوم — يُسمح بـ JPG, PNG, GIF, WebP'], 415);
    }

    $uploadDir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            send_json(['error' => 'تعذّر إنشاء مجلد الصور على الخادم'], 500);
        }
    }

    $ext      = $mimeToExt[$mime];
    $filename = 'q_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        send_json(['error' => 'فشل نقل الصورة — تحقق من صلاحيات مجلد uploads/'], 500);
    }

    send_json(['url' => '/uploads/' . $filename]);
}
