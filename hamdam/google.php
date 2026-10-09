<?php
/**
 * بازگشت از گوگل برای ورود مخاطبان چت‌بات (مشترک همه ربات‌ها)
 * گوگل کاربر را با «state» به اینجا برمی‌گرداند؛ اطلاعات حساب گوگل ذخیره و
 * یک کد یک‌بارمصرف ساخته می‌شود و کاربر به صفحه چت (فایل رابط) برمی‌گردد.
 * هیچ نام یا نشانی از پلتفرم نمایش داده نمی‌شود.
 */
error_reporting(0);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';

function hdg_fail_page($msg)
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>ورود</title></head>'
       . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px 20px;color:#475569;line-height:2">'
       . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '<br><a href="javascript:history.go(-2)">بازگشت</a></body></html>';
    exit;
}

$state = preg_replace('/[^a-f0-9]/', '', (string)($_GET['state'] ?? ''));
if (strlen($state) !== 40) hdg_fail_page('درخواست نامعتبر است.');

try {
    $pdo = aichat_connect();
    try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
    try { hd_ensure_schema($pdo); } catch (\Throwable $e) {}
    $st = $pdo->prepare("SELECT * FROM hd_glogin WHERE state=? AND expires_at > ? AND code_hash='' LIMIT 1");
    $st->execute([$state, hd_now()]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[HD] google cb: ' . $e->getMessage());
    $row = null;
}
if (!$row) hdg_fail_page('زمان ورود با گوگل تمام شده است؛ دوباره تلاش کنید.');

$ret = (string)$row['return_url'];
$back = function ($params) use ($ret) {
    header('Location: ' . $ret . (strpos($ret, '?') === false ? '?' : '&') . http_build_query($params));
    exit;
};

if (isset($_GET['error']) || empty($_GET['code'])) {
    $pdo->prepare("DELETE FROM hd_glogin WHERE id=?")->execute([(int)$row['id']]);
    $back(['a' => 'g', 'e' => 'cancel']);
}

$g = biz_google_exchange($pdo, (string)$_GET['code'], hd_google_redirect_uri());
if (empty($g['ok'])) {
    $pdo->prepare("DELETE FROM hd_glogin WHERE id=?")->execute([(int)$row['id']]);
    $back(['a' => 'g', 'e' => 'fail']);
}

$code = bin2hex(random_bytes(20));
$pdo->prepare("UPDATE hd_glogin SET sub=?, email=?, name=?, code_hash=?, expires_at=? WHERE id=?")
    ->execute([$g['sub'], $g['email'], mb_substr((string)$g['name'], 0, 100), hash('sha256', $code), hd_now(900), (int)$row['id']]);
$back(['a' => 'g', 'c' => $code]);
