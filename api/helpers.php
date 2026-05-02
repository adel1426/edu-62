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

// ── التحقق من تسجيل دخول الإدارة ──
function require_admin(): void {
    start_session_safe();
    if (empty($_SESSION['is_admin'])) {
        send_json(['error' => 'غير مصرح. يرجى تسجيل الدخول أولاً.'], 401);
    }
}

// ── الحصول على معطى من الاستعلام أو الجسم ──
function param(string $key, $default = null) {
    if (isset($_GET[$key])) return $_GET[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    return $default;
}
