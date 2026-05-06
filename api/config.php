<?php
/**
 * إعدادات قاعدة البيانات والمسؤول
 * القيم تُقرأ من ملف .env في جذر المشروع إن وُجد،
 * وإلا تُستخدم القيم الافتراضية أدناه.
 */

// تحميل .env إن وُجد
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }
}

function _env(string $key, string $default): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ── إعدادات قاعدة بيانات MySQL ──
define('DB_HOST',    _env('DB_HOST',    'localhost'));
define('DB_NAME',    _env('DB_NAME',    'u123456789_nour'));
define('DB_USER',    _env('DB_USER',    'u123456789_nour'));
define('DB_PASS',    _env('DB_PASS',    'Your_Password_Here'));
define('DB_CHARSET', _env('DB_CHARSET', 'utf8mb4'));

// ── إعدادات لوحة الإدارة ──
define('ADMIN_USERNAME',  _env('ADMIN_USERNAME',  'admin'));
define('ADMIN_PASSWORD',  _env('ADMIN_PASSWORD',  '62'));

// ── مشرف عرض فقط (بدون صلاحيات تعديل) ──
define('VIEWER_USERNAME', _env('VIEWER_USERNAME', 'drop_color'));
define('VIEWER_PASSWORD', _env('VIEWER_PASSWORD', 'drop_color'));

// ── إعدادات الجلسة ──
define('SESSION_NAME',     _env('SESSION_NAME',     'nour_session'));
define('SESSION_LIFETIME', (int)_env('SESSION_LIFETIME', (string)(60 * 60 * 24 * 30)));
