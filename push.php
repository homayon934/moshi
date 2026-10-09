<?php
/**
 * اعلان‌های یادآور پنل‌ها:
 *  ?act=key                    کلید عمومی اعلان مرورگر
 *  ?act=sub&as=admin|user      ثبت اشتراک اعلان این دستگاه (نیاز به ورود)
 *  ?act=unsub                  لغو اشتراک
 *  ?act=pull                   سرویس‌کارگر: متن اعلان‌های تازه این دستگاه
 *  ?act=due&as=admin|user      یادآورهای موعدرسیده برای پنجره‌های کوچک پنل
 *  ?act=ack&as=admin|user      دیده شد / انجام شد / تعویق
 *  ?act=test&as=admin|user     اعلان آزمایشی به همین دستگاه
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/saas_lib.php';
require_once __DIR__ . '/includes/rem_ui.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function pu_out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
rem_ensure_schema($pdo);
$act = (string)($_GET['act'] ?? '');
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if ($act === 'key') { $v = rem_vapid($pdo); pu_out(['ok' => (bool)$v, 'key' => $v['pub'] ?? '']); }
if ($act === 'pull') pu_out(rem_push_pull($pdo, (string)($in['endpoint'] ?? '')));
if ($act === 'unsub') pu_out(rem_push_unsubscribe($pdo, (string)($in['endpoint'] ?? '')));

// بقیه: نیاز به ورود
if (function_exists('sec_session_start')) sec_session_start(); elseif (session_status() !== PHP_SESSION_ACTIVE) session_start();
$as = ($_GET['as'] ?? '') === 'admin' ? 'admin' : 'user';
$ctx = null;
if ($as === 'admin') {
    if (!empty($_SESSION['aichat_admin'])) $ctx = rem_ctx_admin($pdo);
} elseif (!empty($_SESSION['saas_user_id'])) {
    $u = saas_get_user($pdo, (int)$_SESSION['saas_user_id']);
    if ($u && $u['status'] === 'active') {
        $team = null;
        if (!empty($_SESSION['saas_team_id']) && function_exists('biz_team_get')) {
            $team = biz_team_get($pdo, (int)$_SESSION['saas_team_id']);
            if (!$team || (int)$team['owner_id'] !== (int)$u['id'] || empty($team['is_active'])) $team = null;
        }
        $ctx = rem_ctx_user($u, $team);
    }
}
if (!$ctx) { http_response_code(401); pu_out(['ok' => false, 'error' => 'login']); }
session_write_close();
rem_maybe_run($pdo);

if ($act === 'due') pu_out(['ok' => true, 'items' => rem_events_due($pdo, $ctx)]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pu_out(['ok' => false, 'error' => 'bad request']);
// فقط از همین سایت
$src = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if ($src !== '' && strtolower((string)parse_url($src, PHP_URL_HOST)) !== strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')))) pu_out(['ok' => false, 'error' => 'bad origin']);
if ($act === 'sub') pu_out(rem_push_subscribe($pdo, $ctx['kind'], $ctx['id'], 0, $in['sub'] ?? [], (string)($in['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))));
if ($act === 'test') pu_out(rem_push_test($pdo, $ctx['kind'], $ctx['id'], (string)($in['endpoint'] ?? '')));
if ($act === 'ack') {
    if (!hash_equals((string)($_SESSION['rem_csrf'] ?? ''), (string)($in['csrf'] ?? ''))) pu_out(['ok' => false, 'error' => 'csrf']);
    pu_out(rem_event_ack($pdo, $ctx, (int)($in['id'] ?? 0), (string)($in['act'] ?? ''), (int)($in['arg'] ?? 0)));
}
pu_out(['ok' => false, 'error' => 'bad request']);
