<?php
/**
 * دستیار گفتگوی صفحه اصلی — نقطه اتصال (AJAX)
 *  GET  ?a=history         تاریخچه همین بازدیدکننده (نشست)
 *  POST a=send, message    پاسخ دستیار
 *  POST a=reset            شروع گفتگوی تازه
 */
require_once __DIR__ . '/includes/sec_lib.php';
sec_boot('public');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_lib.php';
require_once __DIR__ . '/includes/saas_lib.php';
require_once __DIR__ . '/includes/ai_chat_lib.php';
require_once __DIR__ . '/includes/sitechat_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$out = function ($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

try { $pdo = aichat_connect(); site_ensure_schema($pdo); } catch (\Throwable $e) { $out(['ok' => false, 'error' => 'سرویس در دسترس نیست.'], 503); }
$s = site_settings($pdo);
$c = sch_conf($s);
if (!$c['on']) $out(['ok' => false, 'error' => 'گفتگو غیرفعال است.'], 403);

if (empty($_SESSION['sch_sid'])) $_SESSION['sch_sid'] = bin2hex(random_bytes(8));
$sid = (string)$_SESSION['sch_sid'];
$hist = is_array($_SESSION['sch_hist'] ?? null) ? $_SESSION['sch_hist'] : [];
$a = (string)($_REQUEST['a'] ?? '');

if ($a === 'history') $out(['ok' => true, 'items' => array_slice($hist, -20)]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 405);
// فقط از صفحه همین سایت (Origin/Referer) و با توکن امضاشده صفحه
if (sec_same_origin() === false || !sch_page_token_ok($_POST['tok'] ?? '')) $out(['ok' => false, 'error' => 'صفحه را تازه کنید و دوباره تلاش کنید.'], 403);

if ($a === 'reset') {
    $_SESSION['sch_hist'] = [];
    $_SESSION['sch_sid'] = bin2hex(random_bytes(8));
    $out(['ok' => true]);
}
if ($a !== 'send') $out(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 400);

$msg = trim((string)($_POST['message'] ?? ''));
if ($msg === '') $out(['ok' => false, 'error' => 'پیام خود را بنویسید.']);
if (mb_strlen($msg) > 800) $out(['ok' => false, 'error' => 'پیام طولانی است (حداکثر ۸۰۰ کاراکتر).']);
$ip = sec_ip();
// حالت کامل (نسخه ۴۳): مهمان فقط چند پیام دارد؛ بعد از آن باید وارد شود (پیام رایگان، بسته‌ها و امکانات کامل)
$full = $c['full'] && sb_bot_id($pdo) > 0;
if ($full) {
    $login_msg = 'برای ادامه گفتگو، لطفاً وارد شوید (با شماره موبایل)؛ بعد از ورود، پیام‌های رایگان بیشتر، ذخیره گفتگوها، ارسال فایل و ساخت تصویر هم در دسترس است. گفتگوی همین‌جا هم حفظ می‌شود.';
    if ($c['guest'] <= 0) $out(['ok' => false, 'error' => $login_msg, 'login' => true]);
    if (function_exists('ex_rate_limited') && (ex_rate_limited('sch_g:' . $sid, $c['guest'], 86400) || ex_rate_limited('sch_gip:' . $ip, $c['guest'] * 3, 86400)))
        $out(['ok' => false, 'error' => $login_msg, 'login' => true]);
}
// محدودیت‌ها: سرعت (۸ پیام در دقیقه)، سقف روزانه هر بازدیدکننده (نشست + IP)، سقف کل روزانه سایت
if (function_exists('ex_rate_limited')) {
    if (ex_rate_limited('sch_min:' . $ip, 8, 60)) $out(['ok' => false, 'error' => 'کمی آهسته‌تر 🙂 چند ثانیه بعد دوباره بفرستید.']);
    if (ex_rate_limited('sch_day:' . $sid, $c['daily'], 86400) || ex_rate_limited('sch_dayip:' . $ip, $c['daily'] * 3, 86400))
        $out(['ok' => false, 'error' => 'سقف گفتگوی امروز شما پر شده است. برای ادامه، ثبت‌نام کنید یا فردا دوباره سر بزنید.', 'limit' => true]);
    if (ex_rate_limited('sch_site_day', 5000, 86400)) $out(['ok' => false, 'error' => 'دستیار امروز پرمشغله است؛ کمی بعد دوباره تلاش کنید.']);
}

$r = sch_answer($pdo, $s, $msg, $hist);
if (empty($r['ok'])) $out(['ok' => false, 'error' => $r['error'] ?? 'خطا']);
$hist[] = ['role' => 'user', 'content' => $msg];
$hist[] = ['role' => 'assistant', 'content' => $r['reply']];
$_SESSION['sch_hist'] = array_slice($hist, -20);
sch_log($pdo, $sid, 'user', $msg);
sch_log($pdo, $sid, 'assistant', $r['reply'], (int)$r['tokens']);
$out(['ok' => true, 'reply' => $r['reply']]);
