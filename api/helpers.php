<?php
require_once __DIR__ . '/config.php';

// ── إعداد الجلسة ──
function start_session_safe() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// ── إرجاع JSON ──
function send_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── قراءة بيانات JSON من الطلب ──
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── التحقق من تسجيل دخول الإدارة مع CSRF ──
function require_admin(): void {
    start_session_safe();
    if (empty($_SESSION['is_admin'])) {
        send_json(['error' => 'غير مصرح. يرجى تسجيل الدخول أولاً.'], 401);
    }
    $reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($reqMethod, ['POST', 'PUT', 'DELETE'], true)) {
        if (!empty($_SESSION['is_viewer'])) {
            send_json(['error' => 'صلاحية عرض فقط — لا يمكن إجراء تعديلات.'], 403);
        }
        verify_csrf();
    }
}

// ── CSRF Token ──
function csrf_token(): string {
    start_session_safe();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    start_session_safe();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $token)) {
        send_json(['error' => 'طلب غير صالح (CSRF).'], 403);
    }
}

function handle_csrf_token(): void {
    send_json(['token' => csrf_token()]);
}

// ── التحقق من صحة عمود user_id في student_scores (migration guard) ──
function ensure_student_scores_user_id_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo = db();
    $col = $pdo->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'student_scores'
           AND COLUMN_NAME = 'user_id'"
    )->fetchColumn();

    if ((int)$col === 0) {
        $pdo->exec("ALTER TABLE student_scores ADD COLUMN user_id INT NULL AFTER id");
    }

    $idx = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'student_scores'
           AND INDEX_NAME = ?"
    );

    $idx->execute(['uniq_score']);
    if ((int)$idx->fetchColumn() > 0) {
        $pdo->exec("ALTER TABLE student_scores DROP INDEX uniq_score");
    }

    $idx->execute(['idx_score_user']);
    if ((int)$idx->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE student_scores ADD INDEX idx_score_user (user_id)");
    }

    $idx->execute(['uniq_score_user']);
    if ((int)$idx->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE student_scores
             ADD UNIQUE KEY uniq_score_user (user_id, grade_key, unit_index, lesson_index)"
        );
    }
}

/**
 * Rate limiting مبني على ملفات - يمنع الطلبات المتكررة
 * يُوقف الطلب تلقائياً بـ 429 إذا تجاوز الحد
 *
 * @param string $action  مفتاح العملية (مثل: 'login')
 * @param int    $limit   أقصى عدد محاولات مسموح
 * @param int    $window  نافذة الزمن بالثواني
 */
function rate_limit(string $action, int $limit = 5, int $window = 900): void {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key     = preg_replace('/[^a-z0-9_\-\.]/i', '_', $action . '_' . $ip);
    $dir     = dirname(__DIR__) . '/logs/rl';
    $file    = $dir . '/' . $key . '.json';

    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $now  = time();
    $data = ['hits' => [], 'blocked_until' => 0];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw) $data = json_decode($raw, true) ?? $data;
    }

    // إذا كان محظوراً لا تزال المهلة سارية
    if ($data['blocked_until'] > $now) {
        $retry = $data['blocked_until'] - $now;
        header('Retry-After: ' . $retry);
        send_json(['error' => 'محاولات كثيرة. حاول مجدداً بعد ' . ceil($retry / 60) . ' دقيقة.'], 429);
    }

    // إزالة الضربات القديمة خارج النافذة
    $data['hits'] = array_values(array_filter($data['hits'], fn($t) => $t > $now - $window));
    $data['hits'][] = $now;

    if (count($data['hits']) > $limit) {
        $data['blocked_until'] = $now + $window;
        file_put_contents($file, json_encode($data), LOCK_EX);
        header('Retry-After: ' . $window);
        send_json(['error' => 'محاولات كثيرة. حاول مجدداً بعد ' . ceil($window / 60) . ' دقيقة.'], 429);
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
}
