<?php
/**
 * نمایش صفحه اصلی سایت روی «ریشه دامنه» (مثلاً https://moshaver.ai) وقتی برنامه داخل یک پوشه نصب شده است.
 *
 * فقط وقتی لازم است که برنامه در پوشه‌ای مثل public_html/ai-chat نصب شده باشد:
 *   ۱) این فایل را در public_html (پوشه اصلی دامنه) با نام index.php بگذارید
 *      (اگر آنجا index.php یا index.html دیگری هست، اول از آن نسخه پشتیبان بگیرید و حذفش کنید).
 *   ۲) اگر نام پوشه برنامه «ai-chat» نیست، خط زیر را تغییر دهید.
 * اگر برنامه مستقیم در public_html نصب شده، به این فایل نیازی نیست.
 */
$app_dir = 'ai-chat';

$f = __DIR__ . '/' . trim($app_dir, '/') . '/index.php';
if (!is_file($f)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('پوشه برنامه پیدا نشد؛ نام پوشه را در فایل index.php ریشه سایت درست کنید.');
}
define('AICHAT_HOME_ROOT', 1);
chdir(dirname($f));
require $f;
