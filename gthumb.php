<?php
/**
 * تصویر کوچک نمونه‌های گالری (برای بارگذاری سریع گالری در پنل مدیریت و استودیو)
 * آدرس: gthumb.php?id=12   (نسخه ۵۱)
 */
require_once __DIR__ . '/includes/sec_lib.php'; sec_boot('public');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/saas_lib.php';

$pdo = aichat_connect();
$it = biz_gallery_item($pdo, (int)($_GET['id'] ?? 0));
$p = $it ? gal_thumb_path($it) : '';
$mime = $p !== '' ? gal_mime($p) : '';
if ($mime === '') { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($p));
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($p);
