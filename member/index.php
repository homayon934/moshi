<?php
/**
 * پنل مخاطبان (نسخه ۴۷) — ثبت‌نام و ورود با موبایل، بدون نیاز به چت
 * بخش‌ها برای هر چت‌بات: خلاصه، پرونده، گفتگوها، یادآورها، جلسات، بسته‌ها و خرید، پشتیبانی
 */
require_once dirname(__DIR__) . '/includes/sec_lib.php';
sec_boot('user');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/site_lib.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';

site_security_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$pdo = aichat_connect();
site_ensure_schema($pdo);
try { saas_ensure_schema($pdo); hd_ensure_schema($pdo); macc_ensure_schema($pdo); } catch (\Throwable $e) {}
$s = site_settings($pdo);
$site_name = trim((string)($s['site_name'] ?? '')) ?: 'پنل مخاطبان';
$logo_url = (string)($s['site_logo_url'] ?? '');
$favi_url = (string)($s['site_favicon_url'] ?? '');
$base = rtrim(AICHAT_BASE_URL, '/') . '/member/';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['macc_csrf'])) $_SESSION['macc_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['macc_csrf'];
$csrf_ok = fn() => hash_equals($csrf, (string)($_POST['csrf'] ?? ''));
$go = function ($q = '', $msg = '', $err = false) use ($base) { header('Location: ' . $base . ($q !== '' ? '?' . $q : '') . ($msg !== '' ? ($q !== '' ? '&' : '?') . ($err ? 'e=' : 'm=') . rawurlencode($msg) : '')); exit; };
$sms_ok = function_exists('comm_login_is') ? (comm_login_is($pdo, 'member', 'sms') || comm_login_is($pdo, 'user', 'sms')) && biz_platform_sms_ready($pdo) : false;

$a = (string)($_GET['a'] ?? '');
$mobile = (string)($_SESSION['macc_mobile'] ?? '');
$acc = $mobile !== '' ? macc_get($pdo, $mobile) : null;
if ($mobile !== '' && (!$acc || $acc['status'] !== 'active')) { unset($_SESSION['macc_mobile'], $_SESSION['macc_tok']); $mobile = ''; $acc = null; }

if ($a === 'logout') {
    if ($csrf_ok() || ($_GET['t'] ?? '') === $csrf) { unset($_SESSION['macc_mobile'], $_SESSION['macc_tok'], $_SESSION['macc_otp']); session_regenerate_id(true); }
    $go();
}

// ── ورود / ثبت‌نام ─────────────────────────────────────────────────────────
$err = ''; $info = '';
if (!$acc) {
    $otp = $_SESSION['macc_otp'] ?? null;
    if ($otp && time() - (int)$otp['at'] > 600) { unset($_SESSION['macc_otp']); $otp = null; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)($_POST['act'] ?? '');
        if (!$csrf_ok()) $err = 'صفحه را تازه کنید و دوباره تلاش کنید.';
        elseif ($act === 'send') {
            $mob = biz_mobile($_POST['mobile'] ?? '');
            $name = trim(mb_substr(strip_tags((string)($_POST['name'] ?? '')), 0, 100));
            if (!saas_verify_captcha((string)($_POST['cap'] ?? ''), (string)($_POST['cap_t'] ?? ''))) $err = 'پاسخ سؤال امنیتی اشتباه است.';
            elseif ($mob === '') $err = 'شماره موبایل معتبر نیست (مثال: 09121234567).';
            elseif (!$sms_ok) $err = 'ورود با پیامک فعلاً ممکن نیست؛ کمی بعد دوباره تلاش کنید.';
            elseif (!macc_get($pdo, $mob) && !macc_memberships($pdo, $mob) && mb_strlen($name) < 2) $err = 'برای ثبت‌نام، نام خود را هم بنویسید.';
            else {
                $e = comm_otp_send($pdo, 'macc', $mob, 'login', 'otp_member', ['bot' => $site_name]);
                if ($e !== '') $err = $e;
                else { $_SESSION['macc_otp'] = ['mobile' => $mob, 'name' => $name, 'at' => time()]; $otp = $_SESSION['macc_otp']; $info = 'کد ۵ رقمی به ' . $mob . ' پیامک شد.'; }
            }
        } elseif ($act === 'verify' && $otp) {
            $e = comm_otp_check($pdo, 'macc', $otp['mobile'], 'login', (string)($_POST['code'] ?? ''));
            if ($e !== '') $err = $e;
            else {
                session_regenerate_id(true);
                macc_login($pdo, $otp['mobile'], $otp['name']);
                $_SESSION['macc_mobile'] = $otp['mobile'];
                $_SESSION['macc_tok'] = [];
                unset($_SESSION['macc_otp']);
                $go();
            }
        } elseif ($act === 'change') { unset($_SESSION['macc_otp']); $otp = null; }
    }
    $cap = saas_generate_captcha();
}

// ── پنل ────────────────────────────────────────────────────────────────────
$ms_all = $acc ? macc_memberships($pdo, $mobile) : [];
$bid = (int)($_GET['bot'] ?? 0);
$ms = null;
foreach ($ms_all as $x) if ((int)$x['id'] === $bid) $ms = $x;
if (!$ms && $ms_all) $ms = $ms_all[0];
$tab = (string)($_GET['t'] ?? 'home');
$Q = fn($t, $extra = '') => 'bot=' . (int)($ms['id'] ?? 0) . '&t=' . $t . $extra;

if ($acc && $ms) {
    $rem_on = function_exists('rem_bot_on') && rem_bot_on($ms);
    $sup_on = (!isset($ms['support_on']) || !empty($ms['support_on'])) && function_exists('ex_owner_allows') && ex_owner_allows($pdo, (int)$ms['user_id'], 'has_member_support');
    $sessions = function_exists('cm_member_sessions') ? array_merge(cm_member_sessions($pdo, (int)$ms['member_id'], true), []) : [];
    $sessions_all = [];
    if (function_exists('cm_ensure_schema')) {
        try { cm_ensure_schema($pdo); $q = $pdo->prepare("SELECT * FROM saas_sessions WHERE member_id=? ORDER BY starts_at DESC LIMIT 50"); $q->execute([(int)$ms['member_id']]); $sessions_all = array_map('cm_session_public', $q->fetchAll(\PDO::FETCH_ASSOC) ?: []); } catch (\Throwable $e) {}
    }
    $tabs = ['home' => '🏠 خلاصه', 'profile' => '📋 پرونده', 'threads' => '💬 گفتگوها'];
    if ($rem_on) $tabs['rem'] = '⏰ یادآورها';
    if ($sessions_all) $tabs['sessions'] = '📅 جلسات';
    $tabs['plans'] = '⭐ بسته‌ها';
    if ($sup_on) $tabs['support'] = '🎧 پشتیبانی';
    if (!isset($tabs[$tab])) $tab = 'home';

    // بازگشت از درگاه پرداخت
    if ($a === 'pay_back') {
        $r = macc_call($pdo, $ms, 'pay_verify', ['pid' => (string)(int)($_GET['pid'] ?? 0), 'authority' => substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['Authority'] ?? '')), 0, 64), 'status' => (($_GET['Status'] ?? '') === 'OK') ? 'OK' : 'NOK']);
        $go($Q('plans'), (string)($r['message'] ?? ($r['error'] ?? '')), empty($r['paid']));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$csrf_ok()) $go($Q($tab), 'صفحه را تازه کنید و دوباره تلاش کنید.', true);
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'profile_save') {
            $pr = [];
            foreach ((array)($_POST['pk'] ?? []) as $i => $k) { $k = trim(mb_substr((string)$k, 0, 60)); $v = trim(mb_substr((string)($_POST['pv'][$i] ?? ''), 0, 300)); if ($k !== '') $pr[$k] = $v; }
            $r = macc_call($pdo, $ms, 'profile_save', ['profile' => $pr]);
            $go($Q('profile'), !empty($r['ok']) ? 'پرونده ذخیره شد.' : ($r['error'] ?? 'خطا'), empty($r['ok']));
        }
        if ($act === 'consent') {
            $r = macc_call($pdo, $ms, 'consent', ['value' => ($_POST['v'] ?? '') === '1' ? '1' : '0']);
            $go($Q('profile'), !empty($r['ok']) ? (!empty($r['consent']) ? 'اجازه دسترسی مشاور داده شد.' : 'اجازه دسترسی مشاور لغو شد.') : ($r['error'] ?? 'خطا'), empty($r['ok']));
        }
        if ($act === 'rem_add') {
            $r = macc_call($pdo, $ms, 'rem_parse', ['rem' => ['text' => mb_substr((string)($_POST['text'] ?? ''), 0, 300), 'save' => 1]]);
            if (!empty($r['ask'])) $go($Q('rem'), 'ساعت را دقیق‌تر بنویسید (صبح یا عصر)؛ مثلاً «فردا ساعت ۵ عصر …».', true);
            $go($Q('rem'), !empty($r['saved']) ? 'یادآور ثبت شد: ' . ($r['item']['title'] ?? '') . ' — ' . ($r['item']['when'] ?? '') : ($r['error'] ?? 'خطا'), empty($r['saved']));
        }
        if ($act === 'rem_act') {
            $r = macc_call($pdo, $ms, 'rem_act', ['rem' => ['id' => (int)($_POST['id'] ?? 0), 'act' => in_array($_POST['do'] ?? '', ['done', 'delete', 'reopen'], true) ? $_POST['do'] : 'done']]);
            $go($Q('rem'), $r['msg'] ?? ($r['error'] ?? ''), empty($r['ok']));
        }
        if ($act === 'c2c') {   // کارت به کارت: ارسال فیش (نسخه ۵۶)
            $cb = function_exists('c2c_upload_bytes') ? c2c_upload_bytes($_FILES['receipt'] ?? null) : null;
            if (is_array($cb)) $go($Q('plans'), $cb['error'], true);
            if ($cb === null) $go($Q('plans'), 'تصویر فیش واریزی را انتخاب کنید.', true);
            $r = macc_call($pdo, $ms, 'c2c_submit', ['plan_id' => (int)($_POST['plan_id'] ?? 0), 'code' => mb_substr((string)($_POST['code'] ?? ''), 0, 40), 'data' => base64_encode($cb),
                'value' => mb_substr((string)($_POST['tracking'] ?? ''), 0, 60), 'title' => mb_substr((string)($_POST['payer'] ?? ''), 0, 120)]);
            $go($Q('plans'), (string)($r['message'] ?? ($r['error'] ?? 'خطا')), empty($r['ok']));
        }
        if ($act === 'buy') {
            $r = macc_call($pdo, $ms, 'buy', ['plan_id' => (int)($_POST['plan_id'] ?? 0), 'code' => mb_substr((string)($_POST['code'] ?? ''), 0, 40), 'return_url' => $base . '?a=pay_back&bot=' . (int)$ms['id']]);
            if (!empty($r['pay_url'])) { header('Location: ' . $r['pay_url']); exit; }
            $go($Q('plans'), (string)($r['message'] ?? ($r['error'] ?? 'خطا')), empty($r['ok']));
        }
        if ($act === 'ticket_new') {
            $r = macc_call($pdo, $ms, 'ticket_new', ['title' => mb_substr((string)($_POST['title'] ?? ''), 0, 150), 'message' => mb_substr((string)($_POST['message'] ?? ''), 0, 3000)]);
            $go($Q('support', !empty($r['ticket_id']) ? '&tk=' . (int)$r['ticket_id'] : ''), !empty($r['ok']) ? 'تیکت ثبت شد.' : ($r['error'] ?? 'خطا'), empty($r['ok']));
        }
        if ($act === 'ticket_reply') {
            $tk = (int)($_POST['tk'] ?? 0);
            $r = macc_call($pdo, $ms, 'ticket_reply', ['ticket_id' => $tk, 'message' => mb_substr((string)($_POST['message'] ?? ''), 0, 3000)]);
            $go($Q('support', '&tk=' . $tk), !empty($r['ok']) ? 'پاسخ شما ثبت شد.' : ($r['error'] ?? 'خطا'), empty($r['ok']));
        }
        $go($Q($tab));
    }
    macc_page($pdo, $ms, strip_tags(preg_replace('/^\S+\s/u', '', $tabs[$tab])) . (!empty($_GET['th']) ? ' (گفتگو)' : ''));
}
$msg = (string)($_GET['m'] ?? ''); $emsg = (string)($_GET['e'] ?? '');

// متن پیام: امن + پررنگ و لینک ساده
$fmt = function ($t) use ($h) {
    $t = preg_replace('/\[\[PROFILE\]\].*?\[\[\/PROFILE\]\]/su', '', (string)$t);
    $x = $h(trim($t));
    $x = preg_replace('/\*\*([^*\n]+)\*\*/u', '<b>$1</b>', $x);
    $x = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/u', '<a href="$2" target="_blank" rel="noopener">$1</a>', $x);
    return nl2br($x);
};
$jd = fn($d) => function_exists('rem_jdate') && $d ? rem_jdate((string)$d) : substr((string)$d, 0, 10);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo $h(($acc ? 'پنل من' : 'ورود مخاطبان') . ' — ' . $site_name); ?></title>
<?php if ($favi_url): ?><link rel="icon" href="<?php echo $h($favi_url); ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#f4f6fb;--card:#fff;--line:#e5e9f2;--text:#1e293b;--mut:#64748b;--c:#4f46e5;--c2:#6366f1;--ok:#059669;--err:#dc2626}
body{background:var(--bg);color:var(--text);font-family:'Vazirmatn',Tahoma,sans-serif;line-height:1.9;font-size:14.5px}
a{color:var(--c);text-decoration:none}
.top{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
.top .in{max-width:1000px;margin:0 auto;padding:0 16px;height:60px;display:flex;align-items:center;gap:12px}
.top .logo{font-weight:800;font-size:16px;color:var(--text);display:flex;align-items:center;gap:8px}.top .logo img{height:32px}
.top .sp{flex:1}.top .who{font-size:13px;color:var(--mut)}
.wrap{max-width:1000px;margin:0 auto;padding:18px 16px 50px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:14px}
.card h2{font-size:16px;margin-bottom:10px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:11px;padding:10px 18px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;background:var(--c);color:#fff;text-decoration:none}
.btn:hover{background:var(--c2)}.btn.gh{background:#eef2ff;color:var(--c)}.btn.sm{padding:6px 12px;font-size:12.5px;border-radius:9px}.btn.rd{background:#fee2e2;color:var(--err)}
input,textarea,select{width:100%;border:1px solid var(--line);border-radius:11px;padding:10px 12px;font-family:inherit;font-size:14px;background:#fff;color:var(--text);outline:none}
input:focus,textarea:focus{border-color:var(--c)}
label{display:block;font-size:13px;font-weight:700;margin:8px 0 4px}
.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:11px;padding:9px 12px;margin-bottom:12px;font-size:13.5px}
.er{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:11px;padding:9px 12px;margin-bottom:12px;font-size:13.5px}
.mut{color:var(--mut);font-size:13px}
.login{max-width:420px;margin:40px auto}
.login h1{font-size:20px;text-align:center;margin-bottom:4px}.login p.sub{text-align:center;color:var(--mut);font-size:13px;margin-bottom:14px}
.cap{display:flex;gap:8px;align-items:center}.cap span{white-space:nowrap;background:#f1f5f9;border-radius:10px;padding:9px 12px;font-weight:700;direction:ltr}
.bots{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.bots a{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 14px 6px 8px;color:var(--text);font-size:13.5px;font-weight:600}
.bots a.on{border-color:var(--c);background:#eef2ff;color:var(--c)}
.bots img,.bots .av{width:26px;height:26px;border-radius:50%;object-fit:cover;background:var(--c);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px}
.tabs{display:flex;gap:4px;overflow-x:auto;border-bottom:1px solid var(--line);margin-bottom:14px;scrollbar-width:none}.tabs::-webkit-scrollbar{display:none}
.tabs a{padding:9px 14px;font-size:13.5px;font-weight:700;color:var(--mut);border-bottom:2.5px solid transparent;white-space:nowrap}
.tabs a.on{color:var(--c);border-color:var(--c)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.stat{background:#f8fafc;border:1px solid var(--line);border-radius:14px;padding:14px}.stat b{display:block;font-size:22px}.stat span{font-size:12.5px;color:var(--mut)}
.row{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;border-bottom:1px solid #f1f5f9;padding:10px 0}.row:last-child{border:0}
.pill{display:inline-block;font-size:11.5px;border-radius:999px;padding:1px 9px;background:#f1f5f9;color:var(--mut)}
.pill.g{background:#dcfce7;color:#166534}.pill.r{background:#fee2e2;color:#991b1b}.pill.b{background:#e0e7ff;color:#3730a3}
.msgs{display:flex;flex-direction:column;gap:8px}
.mg{max-width:85%;padding:9px 13px;border-radius:14px;font-size:14px;word-break:break-word}
.mg.u{align-self:flex-start;background:var(--c);color:#fff;border-radius:14px 14px 14px 4px}
.mg.a{align-self:flex-end;background:#f1f5f9;border-radius:14px 14px 4px 14px}
.mg small{display:block;font-size:11px;opacity:.7;margin-top:2px}
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.plan{border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;flex-direction:column}
.plan h3{font-size:15.5px}.plan .pr{font-size:20px;font-weight:800;color:var(--c);margin:6px 0}
.plan ul{list-style:none;font-size:13px;color:#475569;flex:1;margin-bottom:10px}.plan li::before{content:'✓ ';color:var(--ok)}
.kv{display:grid;grid-template-columns:minmax(120px,30%) 1fr 34px;gap:6px;margin-bottom:6px}
.empty{text-align:center;padding:30px 10px;color:var(--mut)}
@media(max-width:600px){.top .who{display:none}.kv{grid-template-columns:1fr 1fr 34px}}
</style>
</head>
<body>
<header class="top"><div class="in">
  <a class="logo" href="<?php echo $h(rtrim(AICHAT_BASE_URL, '/') . '/'); ?>"><?php if ($logo_url): ?><img src="<?php echo $h($logo_url); ?>" alt=""><?php endif; ?><span><?php echo $h($site_name); ?></span></a>
  <span class="sp"></span>
  <?php if ($acc): ?><span class="who">👤 <?php echo $h($acc['name'] !== '' ? $acc['name'] : $acc['mobile']); ?></span><a class="btn gh sm" href="<?php echo $h($base . '?a=logout&t=' . $csrf); ?>">خروج</a><?php endif; ?>
</div></header>
<main class="wrap">
<?php if (!$acc): ?>
  <div class="card login">
    <h1>👋 پنل مخاطبان</h1>
    <p class="sub">ثبت‌نام و ورود با شماره موبایل؛ پرونده، گفتگوها، یادآورها و بسته‌های شما در یک‌جا.</p>
    <?php if ($err): ?><div class="er"><?php echo $h($err); ?></div><?php elseif ($info): ?><div class="ok"><?php echo $h($info); ?></div><?php endif; ?>
    <?php if (!empty($otp)): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="verify">
      <label>کد پیامک‌شده به <span dir="ltr"><?php echo $h($otp['mobile']); ?></span></label>
      <input type="text" name="code" inputmode="numeric" maxlength="5" dir="ltr" autocomplete="one-time-code" required autofocus placeholder="کد ۵ رقمی">
      <button class="btn" style="width:100%;margin-top:12px">ورود</button>
    </form>
    <form method="post" style="text-align:center;margin-top:8px"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="change"><button style="background:none;border:0;color:var(--c);cursor:pointer;font-family:inherit">تغییر شماره</button></form>
    <?php else: ?>
    <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="send">
      <label>شماره موبایل</label><input type="tel" name="mobile" maxlength="14" dir="ltr" required placeholder="09121234567" value="<?php echo $h($_POST['mobile'] ?? ''); ?>">
      <label>نام <small class="mut" style="font-weight:normal">(برای ثبت‌نام بار اول)</small></label><input type="text" name="name" maxlength="100" value="<?php echo $h($_POST['name'] ?? ''); ?>">
      <label>سؤال امنیتی</label><div class="cap"><span><?php echo $h($cap['question']); ?> = ؟</span><input type="text" name="cap" inputmode="numeric" maxlength="4" dir="ltr" required></div>
      <input type="hidden" name="cap_t" value="<?php echo $h($cap['token']); ?>">
      <button class="btn" style="width:100%;margin-top:14px">📩 دریافت کد تأیید</button>
    </form>
    <?php endif; ?>
  </div>
<?php elseif (!$ms): ?>
  <?php if ($msg): ?><div class="ok"><?php echo $h($msg); ?></div><?php endif; ?>
  <div class="card empty">
    <div style="font-size:40px">🌱</div>
    <h2 style="margin:8px 0">حساب شما ساخته شد</h2>
    <p>هنوز با این شماره در هیچ چت‌باتی عضو نیستید. وقتی با چت‌بات مشاور یا کسب‌وکاری با همین شماره گفتگو کنید، پرونده و گفتگوهای آن اینجا نمایش داده می‌شود.</p>
    <p style="margin-top:12px"><a class="btn gh" href="<?php echo $h(rtrim(AICHAT_BASE_URL, '/') . '/'); ?>">بازگشت به سایت</a></p>
  </div>
<?php else: ?>
  <?php if ($msg): ?><div class="ok"><?php echo $h($msg); ?></div><?php endif; ?>
  <?php if ($emsg): ?><div class="er"><?php echo $h($emsg); ?></div><?php endif; ?>
  <?php if (count($ms_all) > 1): ?>
  <nav class="bots" aria-label="چت‌بات‌ها">
    <?php foreach ($ms_all as $x): $av = (string)($x['avatar_url'] ?? ''); ?>
    <a href="?bot=<?php echo (int)$x['id']; ?>" class="<?php echo (int)$x['id'] === (int)$ms['id'] ? 'on' : ''; ?>"><?php if ($av !== ''): ?><img src="<?php echo $h($av); ?>" alt=""><?php else: ?><span class="av"><?php echo $h(mb_substr($x['name'], 0, 1)); ?></span><?php endif; ?><?php echo $h($x['name']); ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <div class="card" style="padding:14px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:200px"><b style="font-size:16px"><?php echo $h($ms['name']); ?></b><?php if (trim((string)$ms['specialty']) !== ''): ?> <span class="mut">— <?php echo $h($ms['specialty']); ?></span><?php endif; ?><div class="mut">عضو از <?php echo $h($jd($ms['joined'])); ?></div></div>
    <?php $cu = macc_chat_url($pdo, $ms); if ($cu !== ''): ?><a class="btn" href="<?php echo $h($cu); ?>" target="_blank" rel="noopener">💬 ادامه گفتگو</a><?php endif; ?>
  </div>
  <nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="?<?php echo $Q($k); ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><?php echo $h($l); ?></a><?php endforeach; ?></nav>

  <?php if ($tab === 'home'):
    $pl = macc_call($pdo, $ms, 'plans'); $st = $pl['status'] ?? [];
    $tc = (int)(function () use ($pdo, $ms) { $q = $pdo->prepare("SELECT COUNT(*) FROM hd_threads WHERE bot_id=? AND member_id=?"); $q->execute([(int)$ms['id'], (int)$ms['member_id']]); return $q->fetchColumn(); })();
    $rl = $rem_on ? macc_call($pdo, $ms, 'rem_list', ['rem' => ['scope' => 'upcoming']]) : [];
  ?>
  <div class="grid" style="margin-bottom:14px">
    <div class="stat"><b><?php echo number_format((int)($st['paid_left'] ?? 0) + (int)($st['free_left'] ?? 0) + (int)($st['gift_left'] ?? 0)); ?></b><span>پیام باقی‌مانده<?php echo (int)($st['free_left'] ?? 0) ? ' (رایگان: ' . number_format((int)$st['free_left']) . ')' : ''; ?></span></div>
    <?php if ((int)($st['credit_left'] ?? 0) > 0): ?><div class="stat"><b><?php echo number_format((int)$st['credit_left']); ?></b><span>تومان شارژ</span></div><?php endif; ?>
    <div class="stat"><b><?php echo number_format($tc); ?></b><span>گفتگو</span></div>
    <?php if ($rem_on): ?><div class="stat"><b><?php echo count($rl['items'] ?? []); ?></b><span>یادآور پیش رو</span></div><?php endif; ?>
    <?php if ($sessions): ?><div class="stat"><b><?php echo count($sessions); ?></b><span>جلسه پیش رو</span></div><?php endif; ?>
  </div>
  <div class="card"><h2>⭐ بسته‌های فعال</h2>
    <?php if (empty($st['current'])): ?><p class="mut">بسته فعالی ندارید. <a href="?<?php echo $Q('plans'); ?>">مشاهده بسته‌ها ←</a></p>
    <?php else: foreach ($st['current'] as $c): ?><div class="row"><span><b><?php echo $h($c['name']); ?></b> <span class="mut"><?php echo (int)($c['credit'] ?? 0) > 0 ? '💰 ' . number_format((int)$c['credit_left']) . ' از ' . number_format((int)$c['credit']) . ' تومان شارژ' : number_format($c['left']) . ' از ' . number_format($c['total']) . ' پیام'; ?></span></span><span class="mut"><?php echo $c['expires'] ? 'تا ' . $h($jd($c['expires'])) : ''; ?></span></div><?php endforeach; endif; ?>
  </div>
  <?php if ($sessions): ?><div class="card"><h2>📅 جلسه‌های پیش رو</h2><?php foreach ($sessions as $se): ?><div class="row"><span><b><?php echo $h($se['when']); ?></b> <span class="mut"><?php echo $h($se['cons_name']); ?><?php echo $se['place'] !== '' ? ' — ' . $h($se['place']) : ''; ?></span></span><span class="pill b"><?php echo (int)$se['minutes']; ?> دقیقه</span></div><?php endforeach; ?></div><?php endif; ?>
  <?php if (!empty($rl['items'])): ?><div class="card"><h2>⏰ یادآورهای پیش رو</h2><?php foreach (array_slice($rl['items'], 0, 5) as $it): ?><div class="row"><span><?php echo $h($it['icon'] . ' ' . $it['title']); ?></span><span class="mut"><?php echo $h($it['when']); ?></span></div><?php endforeach; ?></div><?php endif; ?>

  <?php elseif ($tab === 'profile'):
    $pr = macc_call($pdo, $ms, 'profile'); $prof = (array)($pr['profile'] ?? []);
    foreach ((array)($pr['intake'] ?? []) as $ik) if (!array_key_exists($ik, $prof)) $prof[$ik] = '';
  ?>
  <div class="card"><h2>📋 پرونده من</h2>
    <p class="mut" style="margin-bottom:10px">اطلاعاتی که مشاور/دستیار برای پاسخ دقیق‌تر از شما می‌داند. می‌توانید آن را کامل یا اصلاح کنید.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="profile_save">
      <div id="kvs"><?php foreach (array_merge($prof, ['' => '']) as $k => $v): ?>
        <div class="kv"><input type="text" name="pk[]" maxlength="60" value="<?php echo $h($k); ?>" placeholder="عنوان (مثلاً: سن)"><input type="text" name="pv[]" maxlength="300" value="<?php echo $h(is_scalar($v) ? $v : ''); ?>" placeholder="مقدار"><button type="button" class="btn rd sm" style="padding:0" onclick="this.parentNode.remove()" title="حذف">✕</button></div>
      <?php endforeach; ?></div>
      <button type="button" class="btn gh sm" onclick="var d=document.querySelector('#kvs .kv:last-child'),c=d.cloneNode(true);c.querySelectorAll('input').forEach(function(i){i.value='';});document.getElementById('kvs').appendChild(c);">+ ردیف</button>
      <button class="btn" style="margin-top:10px">💾 ذخیره پرونده</button>
    </form>
  </div>
  <?php if (!empty($pr['notes'])): ?><div class="card"><h2>📝 یادداشت‌های مشاور برای شما</h2><?php foreach ($pr['notes'] as $n): ?><div class="row" style="display:block"><b><?php echo $h(($n['title'] !== '' ? $n['title'] : $n['kind'])); ?></b> <span class="mut"><?php echo $h($n['author'] . ' — ' . $jd($n['date'])); ?></span><div><?php echo $fmt($n['content']); ?></div></div><?php endforeach; ?></div><?php endif; ?>
  <div class="card"><h2>🔐 اجازه دسترسی مشاور</h2>
    <p class="mut">با اجازه شما، مشاور/صاحب این چت‌بات می‌تواند پرونده و گفتگوهای شما را ببیند و راهنمایی دقیق‌تری بدهد.</p>
    <form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="consent"><input type="hidden" name="v" value="<?php echo !empty($pr['consent']) ? '0' : '1'; ?>">
      <span class="pill <?php echo !empty($pr['consent']) ? 'g' : ''; ?>"><?php echo !empty($pr['consent']) ? 'اجازه داده‌اید' : 'اجازه نداده‌اید'; ?></span>
      <button class="btn sm <?php echo !empty($pr['consent']) ? 'rd' : ''; ?>" style="margin-right:8px"><?php echo !empty($pr['consent']) ? 'لغو اجازه' : 'اجازه می‌دهم'; ?></button></form>
  </div>

  <?php elseif ($tab === 'threads'):
    $th = (int)($_GET['th'] ?? 0);
    if ($th): $tr = macc_call($pdo, $ms, 'thread', ['thread_id' => $th]); ?>
  <div class="card"><p style="margin-bottom:10px"><a href="?<?php echo $Q('threads'); ?>">→ همه گفتگوها</a></p>
    <?php if (empty($tr['ok'])): ?><p class="mut"><?php echo $h($tr['error'] ?? 'یافت نشد.'); ?></p><?php else: ?>
    <h2><?php echo $h($tr['thread']['title']); ?></h2>
    <div class="msgs"><?php foreach ($tr['messages'] as $m): ?>
      <div class="mg <?php echo $m['role'] === 'user' ? 'u' : 'a'; ?>"><?php echo $fmt($m['content']); ?><?php if (!empty($m['media'])): ?><div class="mut">🖼 <?php echo count($m['media']); ?> فایل تصویر/ویدیو (در صفحه چت قابل مشاهده است)</div><?php endif; ?><small><?php echo $h($jd($m['created_at']) . ' ' . substr((string)$m['created_at'], 11, 5)); ?></small></div>
    <?php endforeach; ?></div>
    <?php if ($cu !== ''): ?><p style="margin-top:12px"><a class="btn sm" href="<?php echo $h($cu); ?>" target="_blank" rel="noopener">💬 ادامه این گفتگو در چت</a></p><?php endif; ?>
    <?php endif; ?></div>
    <?php else: $tl = macc_call($pdo, $ms, 'threads'); ?>
  <div class="card"><h2>💬 گفتگوها</h2>
    <?php if (empty($tl['threads'])): ?><p class="empty">هنوز گفتگویی ندارید.</p><?php else: foreach ($tl['threads'] as $t): ?>
    <div class="row"><a href="?<?php echo $Q('threads', '&th=' . (int)$t['id']); ?>"><?php echo $h($t['title'] !== '' ? $t['title'] : 'گفتگو'); ?></a><span class="mut"><?php echo !empty($t['folder']) ? '📁 ' . $h($t['folder']) . ' · ' : ''; ?><?php echo $h($jd($t['updated_at'] ?? $t['created_at'] ?? '')); ?></span></div>
    <?php endforeach; endif; ?></div>
    <?php endif; ?>

  <?php elseif ($tab === 'rem'):
    $sc = in_array($_GET['sc'] ?? '', ['upcoming', 'done', 'all'], true) ? $_GET['sc'] : 'upcoming';
    $rl = macc_call($pdo, $ms, 'rem_list', ['rem' => ['scope' => $sc]]); ?>
  <div class="card"><h2>⏰ یادآور تازه</h2>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="rem_add">
      <input type="text" name="text" maxlength="300" required placeholder="مثلاً: پس‌فردا ساعت ۱۰ صبح نوبت دندانپزشکی، یک ساعت قبل خبرم کن" style="flex:1;min-width:220px"><button class="btn">ثبت</button></form>
  </div>
  <div class="card"><div style="display:flex;gap:6px;margin-bottom:8px"><?php foreach (['upcoming' => 'پیش رو', 'done' => 'انجام‌شده', 'all' => 'همه'] as $k => $l): ?><a class="btn sm <?php echo $sc === $k ? '' : 'gh'; ?>" href="?<?php echo $Q('rem', '&sc=' . $k); ?>"><?php echo $l; ?></a><?php endforeach; ?></div>
    <?php if (empty($rl['items'])): ?><p class="empty"><?php echo $h($rl['error'] ?? 'یادآوری نیست.'); ?></p><?php else: foreach ($rl['items'] as $it): ?>
    <div class="row"><span><?php echo $h($it['icon'] . ' ' . $it['title']); ?><?php if ($it['from'] !== ''): ?> <span class="pill b">از <?php echo $h($it['from']); ?></span><?php endif; ?><?php if ($it['overdue']): ?> <span class="pill r">گذشته</span><?php endif; ?><div class="mut"><?php echo $h($it['when'] . ($it['repeat_text'] !== '' ? ' · ' . $it['repeat_text'] : '')); ?></div></span>
      <span style="white-space:nowrap"><?php if ($it['status'] === 'active'): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="rem_act"><input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>"><input type="hidden" name="do" value="done"><button class="btn sm gh">✔ انجام شد</button></form><?php endif; ?>
      <?php if ($it['can_edit']): ?><form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="rem_act"><input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>"><input type="hidden" name="do" value="delete"><button class="btn sm rd">حذف</button></form><?php endif; ?></span></div>
    <?php endforeach; endif; ?></div>

  <?php elseif ($tab === 'sessions'): ?>
  <div class="card"><h2>📅 جلسات مشاوره</h2>
    <?php foreach ($sessions_all as $se): ?><div class="row"><span><b><?php echo $h($se['when']); ?></b> <span class="mut">با <?php echo $h($se['cons_name']); ?><?php echo $se['place'] !== '' ? ' — ' . $h($se['place']) : ''; ?></span><?php if ($se['member_note'] !== ''): ?><div class="mut"><?php echo $h($se['member_note']); ?></div><?php endif; ?></span><span class="pill <?php echo $se['status'] === 'done' ? 'g' : ($se['status'] === 'cancelled' || $se['status'] === 'noshow' ? 'r' : 'b'); ?>"><?php echo $h($se['status_text']); ?></span></div><?php endforeach; ?>
  </div>

  <?php elseif ($tab === 'plans'): $pl = macc_call($pdo, $ms, 'plans'); ?>
  <div class="card"><h2>⭐ بسته‌ها</h2>
    <?php $c2c = $pl['c2c'] ?? null; $zp_on = ($pl['zp'] ?? true) !== false; ?>
    <?php if (!empty($pl['receipts'])): ?><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;margin-bottom:12px"><b>🧾 فیش‌های واریزی شما</b>
      <?php foreach ($pl['receipts'] as $x): ?><div class="row"><span><?php echo $h($x['plan']); ?> — <?php echo number_format((int)$x['amount']); ?> تومان</span><span class="pill <?php echo $x['status'] === 'approved' ? 'g' : ($x['status'] === 'rejected' ? 'r' : 'b'); ?>"><?php echo $h($x['label']); ?><?php echo $x['status'] === 'rejected' && $x['reason'] !== '' ? ' — ' . $h($x['reason']) : ''; ?></span></div><?php endforeach; ?>
    </div><?php endif; ?>
    <?php if (empty($pl['plans'])): ?><p class="empty">در حال حاضر بسته‌ای برای خرید تعریف نشده است.</p><?php else: ?>
    <div class="plans"><?php foreach ($pl['plans'] as $p): ?>
      <div class="plan"><h3><?php echo $h($p['name']); ?></h3><?php if ($p['description'] !== ''): ?><p class="mut"><?php echo $h($p['description']); ?></p><?php endif; ?>
        <div class="pr"><?php if (!empty($p['free'])): ?>رایگان<?php else: ?><?php if ($p['percent']): ?><s class="mut" style="font-size:13px"><?php echo number_format($p['original']); ?></s> <?php endif; ?><?php echo number_format($p['price']); ?> <small style="font-size:12px">تومان</small><?php endif; ?></div>
        <div class="mut" style="margin-bottom:6px"><?php echo ($p['kind'] ?? '') === 'credit' ? '💰 ' . number_format((int)$p['credit']) . ' تومان شارژ' : number_format($p['messages']) . ' پیام'; ?><?php echo $p['days'] ? ' · ' . (int)$p['days'] . ' روز' : ''; ?></div>
        <ul><?php foreach (array_slice($p['features'], 0, 8) as $f): ?><li><?php echo $h($f); ?></li><?php endforeach; ?></ul>
        <?php if (!empty($p['free']) || $zp_on): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="buy"><input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>">
          <?php if (empty($p['free'])): ?><input type="text" name="code" maxlength="40" placeholder="کد تخفیف (اختیاری)" style="margin-bottom:8px;font-size:13px;padding:7px 10px"><?php endif; ?>
          <button class="btn" style="width:100%"><?php echo !empty($p['free']) ? 'فعال‌سازی رایگان' : 'خرید آنلاین'; ?></button></form>
        <?php endif; ?>
        <?php if (empty($p['free']) && $c2c): ?>
        <details style="margin-top:8px"><summary class="btn" style="width:100%;display:block;text-align:center;background:#fff;color:#2563eb;border:1.5px solid #2563eb;list-style:none;cursor:pointer">💳 کارت به کارت و ارسال فیش</summary>
          <?php echo c2c_card_html($c2c, (int)$p['price']); ?>
          <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="c2c"><input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>">
            <label style="font-size:13px;font-weight:600">🧾 تصویر فیش واریزی *</label><input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required style="margin:4px 0 8px;font-size:13px">
            <input type="text" name="tracking" maxlength="60" dir="ltr" placeholder="کد پیگیری (اختیاری)" style="margin-bottom:8px;font-size:13px;padding:7px 10px">
            <input type="text" name="payer" maxlength="120" placeholder="نام واریزکننده یا ۴ رقم آخر کارت (اختیاری)" style="margin-bottom:8px;font-size:13px;padding:7px 10px">
            <input type="text" name="code" maxlength="40" placeholder="کد تخفیف (اختیاری)" style="margin-bottom:8px;font-size:13px;padding:7px 10px">
            <button class="btn" style="width:100%">ارسال فیش</button></form>
        </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>

  <?php elseif ($tab === 'support'):
    $tk = (int)($_GET['tk'] ?? 0);
    if ($tk): $t = macc_call($pdo, $ms, 'ticket', ['ticket_id' => $tk]); ?>
  <div class="card"><p style="margin-bottom:10px"><a href="?<?php echo $Q('support'); ?>">→ همه تیکت‌ها</a></p>
    <?php if (empty($t['ok'])): ?><p class="mut"><?php echo $h($t['error'] ?? ''); ?></p><?php else: ?>
    <h2><?php echo $h($t['ticket']['subject']); ?> <span class="pill"><?php echo $h($t['ticket']['status_label']); ?></span></h2>
    <div class="msgs"><?php foreach ($t['messages'] as $m): ?><div class="mg <?php echo $m['mine'] ? 'u' : 'a'; ?>"><?php echo $fmt($m['body']); ?><small><?php echo $h($m['author'] . ' — ' . $jd($m['at'])); ?></small></div><?php endforeach; ?></div>
    <?php if ($t['ticket']['status'] !== 'closed'): ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="ticket_reply"><input type="hidden" name="tk" value="<?php echo $tk; ?>"><textarea name="message" rows="3" required maxlength="3000" placeholder="پاسخ شما…"></textarea><button class="btn" style="margin-top:8px">ارسال</button></form><?php endif; ?>
    <?php endif; ?></div>
    <?php else: $tl = macc_call($pdo, $ms, 'tickets'); ?>
  <div class="card"><h2>🎧 تیکت تازه</h2>
    <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="act" value="ticket_new">
      <input type="text" name="title" maxlength="150" required placeholder="موضوع"><textarea name="message" rows="3" required maxlength="3000" placeholder="توضیح…" style="margin-top:8px"></textarea><button class="btn" style="margin-top:8px">ثبت تیکت</button></form>
  </div>
  <div class="card"><h2>تیکت‌های من</h2>
    <?php if (empty($tl['tickets'])): ?><p class="empty">تیکتی ندارید.</p><?php else: foreach ($tl['tickets'] as $t): ?>
    <div class="row"><a href="?<?php echo $Q('support', '&tk=' . (int)$t['id']); ?>"><?php echo $h($t['subject']); ?><?php echo !empty($t['unread_member']) ? ' 🔴' : ''; ?></a><span class="pill"><?php echo $h($tl['statuses'][$t['status']] ?? $t['status']); ?></span></div>
    <?php endforeach; endif; ?></div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
