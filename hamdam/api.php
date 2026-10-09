<?php
/**
 * API چت‌بات تخصصی (همدم) — فقط برای «فایل رابط» روی سرور مشتری
 *
 * ارتباط فقط سرور-به-سرور است و مرورگر مخاطب هرگز به این آدرس وصل نمی‌شود.
 * احراز هویت: هدر X-HD-Key (کلید محرمانه ربات) + توکن مخاطب.
 * ورودی: JSON (POST)   خروجی: JSON
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';

function hd_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function hd_fail($msg, $code = 200, $extra = []) { hd_out(['ok' => false, 'error' => $msg] + $extra, $code); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') hd_fail('bad request', 405);

$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
try { hd_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[HD] schema: ' . $e->getMessage()); }
@set_time_limit(90);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$key = $_SERVER['HTTP_X_HD_KEY'] ?? ($in['key'] ?? '');
$bot = hd_get_bot_by_key($pdo, $key);
if (!$bot) hd_fail('invalid key', 403);
if (function_exists('rem_maybe_run')) rem_maybe_run($pdo);   // یادآورهای موعدرسیده

// همه عملیات در hd_api_dispatch (includes/hamdam_lib.php) پردازش می‌شود
try {
    $res = hd_api_dispatch($pdo, $bot, $in);
} catch (\Throwable $e) {
    error_log('[HD] api: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $res = ['ok' => false, 'error' => 'با توجه به قطعی شبکه، فعلاً امکان پاسخگویی نیست.'];
}
hd_out($res);
