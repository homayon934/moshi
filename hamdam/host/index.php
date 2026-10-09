<?php
/**
 * حالت «دامنه اختصاصی» چت‌بات تخصصی
 *
 * مشتری یک زیردامنه (مثلاً chat.customer.com) را با رکورد CNAME به سرور ما وصل می‌کند؛
 * در cPanel همان زیردامنه به‌عنوان «Addon/Alias Domain» با پوشه ریشه همین پوشه ثبت می‌شود.
 * این فایل ربات مربوط به دامنه را پیدا می‌کند و صفحه چت را بدون هیچ نام یا آدرسی از ما نمایش می‌دهد.
 */
error_reporting(0);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saas_lib.php';
require_once dirname(__DIR__, 2) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__, 2) . '/includes/hamdam_lib.php';

function hd_host_404()
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>404</title></head>'
       . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px;color:#64748b">صفحه مورد نظر یافت نشد.</body></html>';
    exit;
}

try {
    $pdo = aichat_connect();
    try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
    try { hd_ensure_schema($pdo); } catch (\Throwable $e) {}
    $bot = hd_get_bot_by_domain($pdo, $_SERVER['HTTP_HOST'] ?? '');
} catch (\Throwable $e) {
    error_log('[HD] host: ' . $e->getMessage());
    $bot = null;
}
if (!$bot) hd_host_404();

@set_time_limit(90);
$GLOBALS['hdc_local_pdo'] = $pdo;
$GLOBALS['hdc_local_bot'] = $bot;
define('HD_LOCAL', true);
define('HD_SERVER', 'local');
define('HD_KEY', $bot['bot_key']);
define('HDC_CACHE_DIR', __DIR__ . '/cache');

require dirname(__DIR__) . '/connector/index.php';
