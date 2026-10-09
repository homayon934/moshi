<?php
/**
 * تنظیمات اصلی - AI Chat Widget SaaS
 * این فایل را در سرور محافظت کنید (خارج از public_html یا با Deny from all)
 */

// ---------------------------------------------------------------
// اتصال دیتابیس
// ---------------------------------------------------------------
define('AICHAT_DB_HOST', 'localhost');
define('AICHAT_DB_NAME', 'moshai_ai-chat');
define('AICHAT_DB_USER', 'moshai_ai-chat');
define('AICHAT_DB_PASS', 'b#yCRFpQd9oq%=l5');

// ---------------------------------------------------------------
// رمز ورود پنل ادمین مرکزی (فقط یک مدیر کل)
// ---------------------------------------------------------------
define('AICHAT_ADMIN_PASSWORD', '12345678');

// ---------------------------------------------------------------
// آدرس پایه سایت (با اسلش انتهایی)
// مثال: https://example.com/ai-chat/
// ---------------------------------------------------------------
define('AICHAT_BASE_URL', 'https://moshaver.ai/ai-chat/');

// ---------------------------------------------------------------
// آدرس پنل کاربری (با اسلش انتهایی)
// ---------------------------------------------------------------
define('AICHAT_USER_URL', AICHAT_BASE_URL . 'user/');

// ---------------------------------------------------------------
// پوشه ذخیره فایل‌های راهنما (باید writable باشد)
// ---------------------------------------------------------------
define('AICHAT_UPLOAD_DIR', __DIR__ . '/uploads/');

// ---------------------------------------------------------------
// حالت دیباگ (false در محیط واقعی)
// ---------------------------------------------------------------
define('AICHAT_DEBUG', false);

// ---------------------------------------------------------------
// تابع اتصال به دیتابیس
// ---------------------------------------------------------------
if (!function_exists('aichat_connect')) {
    function aichat_connect()
    {
        static $pdo = null;
        if ($pdo !== null) return $pdo;

        try {
            $pdo = new PDO(
                'mysql:host=' . AICHAT_DB_HOST . ';dbname=' . AICHAT_DB_NAME . ';charset=utf8mb4',
                AICHAT_DB_USER,
                AICHAT_DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            die(json_encode(['ok' => false, 'error' => 'خطا در اتصال به پایگاه داده.']));
        }
        return $pdo;
    }
}

// ---------------------------------------------------------------
// ایجاد پوشه آپلود در صورت نبود
// ---------------------------------------------------------------
if (!is_dir(AICHAT_UPLOAD_DIR)) {
    @mkdir(AICHAT_UPLOAD_DIR, 0755, true);
}
