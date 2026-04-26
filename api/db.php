<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[DB CONNECT ERROR] ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            // أثناء التثبيت: أظهري التفاصيل لتسهيل الإعداد. عدا ذلك: رسالة عامة.
            $detail = (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'install.php')
                ? ': ' . $e->getMessage() : '';
            echo json_encode(['error' => 'فشل الاتصال بقاعدة البيانات' . $detail], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}
