<?php
// إعدادات الاتصال بقاعدة البيانات
$host = 'localhost';
$dbname = 'u707475826_edu62'; // اسم القاعدة من ملفك
$user = 'اسم_المستخدم'; 
$pass = 'كلمة_المرور';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // بيانات الأسئلة (هنا نضع الأسئلة التي استخرجناها من ملفاتك)
    $questions = [
        [
            'subject' => 'رياضيات', 'grade' => 'أول متوسط', 'term' => 'الفصل الأول',
            'unit' => 'الجبر والدوال', 'lesson' => '1-1 خطوات حل المسألة', 'level' => 'فهم وتطبيق',
            'question' => 'ما الخطوة الأولى في حل المسألة الرياضية؟',
            'option_a' => 'فهم المسألة', 'option_b' => 'الحل مباشرة', 'option_c' => 'كتابة الإجابة', 'option_d' => 'التخمين',
            'correct_answer' => 'أ', 'feedback' => '1) فهم، 2) تخطيط، 3) حل، 4) تحقق'
        ],
        // ... سأقوم بتزويدك بكامل القائمة لإضافتها هنا
    ];

    $sql = "INSERT INTO questions (subject, grade, term, unit, lesson, level, question, option_a, option_b, option_c, option_d, correct_answer, feedback) 
            VALUES (:subject, :grade, :term, :unit, :lesson, :level, :question, :option_a, :option_b, :option_c, :option_d, :correct_answer, :feedback)";
    
    $stmt = $pdo->prepare($sql);
    $count = 0;

    foreach ($questions as $q) {
        $stmt->execute($q);
        $count++;
    }

    echo "تمت العملية بنجاح! تم إضافة $count سؤالاً إلى قاعدة البيانات.";

} catch (PDOException $e) {
    die("خطأ في الاتصال أو الإدخال: " . $e->getMessage());
}
?>
