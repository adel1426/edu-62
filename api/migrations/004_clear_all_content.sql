-- الهجرة 004: مسح جميع الأسئلة والدروس والوحدات والمحتوى من كلا المرحلتين
-- ⚠️ هذا الإجراء لا يمكن التراجع عنه

-- مسح الأسئلة
DELETE FROM questions;

-- مسح محتوى الدروس (الشرح والفيديوهات)
DELETE FROM lesson_content;

-- مسح الدروس (يجب قبل الوحدات بسبب العلاقات)
DELETE FROM curriculum_lessons;

-- مسح الوحدات
DELETE FROM curriculum_units;

-- إعادة ضبط الـ AUTO_INCREMENT (اختياري)
ALTER TABLE questions          AUTO_INCREMENT = 1;
ALTER TABLE lesson_content     AUTO_INCREMENT = 1;
ALTER TABLE curriculum_lessons AUTO_INCREMENT = 1;
ALTER TABLE curriculum_units   AUTO_INCREMENT = 1;
