<?php
/**
 * إعدادات قاعدة البيانات والمسؤول
 * عدّلي القيم لتطابق إعداداتك في Hostinger
 */

// ── إعدادات قاعدة بيانات MySQL ──
// هذه القيم ستحصلين عليها من لوحة تحكم Hostinger > Databases > Manage
define('DB_HOST',     'localhost');
define('DB_NAME',     'u123456789_nour');     // غيّري هذا
define('DB_USER',     'u123456789_nour');     // غيّري هذا
define('DB_PASS',     'Your_Password_Here');  // غيّري هذا
define('DB_CHARSET',  'utf8mb4');

// ── إعدادات لوحة الإدارة ──
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', '62');

// ── إعدادات الجلسة ──
define('SESSION_NAME', 'nour_session');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 يوم
