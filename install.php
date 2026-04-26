<?php
/**
 * صفحة التثبيت — تشغّل مرة واحدة فقط
 * هذه الصفحة:
 *   1. تنشئ جداول قاعدة البيانات
 *   2. تحمّل بيانات الأسئلة من data.sql
 *   3. تتأكد من نجاح كل شيء
 *
 * بعد نجاح التثبيت، احذفي ملفي install.php و data.sql من الخادم.
 */

require_once __DIR__ . '/api/db.php';

header('Content-Type: text/html; charset=utf-8');

$step = $_GET['step'] ?? 'check';
$messages = [];
$errors = [];

function ok($m) { global $messages; $messages[] = $m; }
function fail($m) { global $errors; $errors[] = $m; }

// ── خطوة 1: إنشاء الجداول ──
if ($step === 'install') {
    try {
        $pdo = db();

        // جدول الأسئلة — نستخدم question_hash (SHA-256 محسوب من PHP) لضمان تفرّد النص الكامل
        $pdo->exec("CREATE TABLE IF NOT EXISTS questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grade_key VARCHAR(20) NOT NULL,
            unit_index INT NOT NULL,
            lesson_index INT NOT NULL,
            question_text TEXT NOT NULL,
            question_hash CHAR(64) NOT NULL,
            option_a VARCHAR(500) NOT NULL,
            option_b VARCHAR(500) NOT NULL,
            option_c VARCHAR(500) NOT NULL,
            option_d VARCHAR(500) NOT NULL,
            correct_answer INT NOT NULL,
            image_url TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_q (grade_key, unit_index, lesson_index, question_hash),
            INDEX idx_lookup (grade_key, unit_index, lesson_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        ok("✅ جدول الأسئلة (questions) جاهز");

        // جدول النتائج
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_name VARCHAR(100) NOT NULL,
            grade_key VARCHAR(20) NOT NULL,
            unit_index INT NOT NULL,
            lesson_index INT NOT NULL,
            score INT NOT NULL,
            total INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_score (student_name, grade_key, unit_index, lesson_index),
            INDEX idx_grade (grade_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        ok("✅ جدول النتائج (student_scores) جاهز");

        // جدول محتوى الدروس
        $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grade_key VARCHAR(20) NOT NULL,
            unit_index INT NOT NULL,
            lesson_index INT NOT NULL,
            content LONGTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lesson (grade_key, unit_index, lesson_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        ok("✅ جدول محتوى الدروس (lesson_content) جاهز");

        // جدول الطالبات (حسابات شخصية)
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            grade_level VARCHAR(20) NOT NULL DEFAULT 'first',
            total_points INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        ok("✅ جدول الطالبات (users) جاهز");

        // جدول تتبع التقدم
        $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            grade_key VARCHAR(20) NOT NULL,
            unit_index INT NOT NULL,
            lesson_index INT NOT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_progress (user_id, grade_key, unit_index, lesson_index),
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        ok("✅ جدول التقدم (lesson_progress) جاهز");

        // ── خطوة 2: استيراد بيانات الأسئلة ──
        $sqlFile = __DIR__ . '/data.sql';
        if (!file_exists($sqlFile)) {
            fail("⚠️ ملف data.sql غير موجود — تأكدي من رفعه بجوار install.php");
        } else {
            $sql = file_get_contents($sqlFile);
            // قسّم على ;\n
            $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
            $inserted = 0;
            foreach ($statements as $s) {
                if (stripos($s, 'INSERT') !== 0) continue;
                try {
                    $pdo->exec($s);
                    $inserted++;
                } catch (Throwable $e) {
                    // تجاهل المكررات
                }
            }
            ok("✅ تم إدخال أو تخطّي $inserted سؤال");
        }

        // إحصائيات
        $count = $pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
        ok("📊 إجمالي الأسئلة في قاعدة البيانات: <strong>$count</strong>");

        $first = $pdo->query("SELECT COUNT(*) FROM questions WHERE grade_key='first'")->fetchColumn();
        $second = $pdo->query("SELECT COUNT(*) FROM questions WHERE grade_key='second'")->fetchColumn();
        ok("📚 أول متوسط: <strong>$first</strong> | ثاني متوسط: <strong>$second</strong>");

    } catch (Throwable $e) {
        fail("❌ خطأ: " . htmlspecialchars($e->getMessage()));
    }
}

// ── فحص الاتصال ──
$dbOk = false;
$dbMsg = '';
try {
    db();
    $dbOk = true;
    $dbMsg = "✅ الاتصال بقاعدة البيانات ناجح";
} catch (Throwable $e) {
    $dbMsg = "❌ فشل الاتصال: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>تثبيت منصة الثانية والستون التعليمية</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif; background: #FFFBF7; color: #333; max-width: 720px; margin: 30px auto; padding: 20px; line-height: 1.8; }
h1 { color: #7C3AED; border-bottom: 3px solid #EC4899; padding-bottom: 10px; }
h2 { color: #7C3AED; margin-top: 30px; }
.box { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(124,58,237,.1); margin: 15px 0; }
.success { background: #d1fae5; border-right: 4px solid #10b981; padding: 12px; border-radius: 8px; margin: 8px 0; }
.error { background: #fee2e2; border-right: 4px solid #ef4444; padding: 12px; border-radius: 8px; margin: 8px 0; }
.warning { background: #fef3c7; border-right: 4px solid #f59e0b; padding: 12px; border-radius: 8px; margin: 8px 0; }
.btn { display: inline-block; background: #7C3AED; color: white; padding: 12px 24px; border-radius: 10px; text-decoration: none; font-weight: bold; margin: 10px 0; }
.btn:hover { background: #6d28d9; }
.btn-pink { background: #EC4899; }
code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
pre { background: #1f2937; color: #e5e7eb; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 0.85em; }
ol li, ul li { margin: 6px 0; }
</style>
</head>
<body>
<h1>🌸 تثبيت منصة الثانية والستون التعليمية</h1>

<div class="box">
    <h2>الخطوة 1: فحص الاتصال بقاعدة البيانات</h2>
    <?php if ($dbOk): ?>
        <div class="success"><?= $dbMsg ?></div>
    <?php else: ?>
        <div class="error"><?= $dbMsg ?></div>
        <div class="warning">
            <strong>للحل:</strong>
            <ol>
                <li>افتحي <code>api/config.php</code></li>
                <li>عدّلي قيم <code>DB_NAME</code> و <code>DB_USER</code> و <code>DB_PASS</code></li>
                <li>هذه القيم تحصلين عليها من <strong>Hostinger → Databases → Manage</strong></li>
                <li>أعيدي تحميل هذه الصفحة</li>
            </ol>
        </div>
    <?php endif; ?>
</div>

<?php if ($dbOk && $step === 'check'): ?>
<div class="box">
    <h2>الخطوة 2: تثبيت الجداول والبيانات</h2>
    <p>سنُنشئ الجداول الثلاثة (الأسئلة + النتائج + محتوى الدروس) ثم نستورد جميع الأسئلة من <code>data.sql</code>.</p>
    <a href="?step=install" class="btn">▶️ بدء التثبيت</a>
</div>
<?php endif; ?>

<?php if ($step === 'install'): ?>
<div class="box">
    <h2>نتائج التثبيت</h2>
    <?php foreach ($messages as $m): ?>
        <div class="success"><?= $m ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="error"><?= $e ?></div>
    <?php endforeach; ?>
</div>

<?php if (empty($errors)): ?>
<div class="box">
    <h2>✅ التثبيت مكتمل!</h2>
    <div class="warning">
        <strong>⚠️ مهم جداً للأمان:</strong> الآن احذفي هذين الملفين من الخادم:
        <ul>
            <li><code>install.php</code></li>
            <li><code>data.sql</code></li>
        </ul>
        من خلال <strong>File Manager</strong> في Hostinger.
    </div>
    <p>بعد الحذف:</p>
    <a href="/" class="btn">🏠 الذهاب للموقع</a>
    <a href="/admin.html" class="btn btn-pink">🔐 لوحة الإدارة</a>
    <p style="margin-top: 20px;"><strong>بيانات الإدارة:</strong> اسم المستخدم: <code>admin</code> — كلمة المرور: <code>62</code></p>
</div>
<?php endif; ?>
<?php endif; ?>

</body>
</html>
