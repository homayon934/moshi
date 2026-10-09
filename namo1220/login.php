<?php
/**
 * ورود مدیر کل — روش‌ها و اولویت از «پیامک و ورود» (بخش مدیر کل):
 *  کد پیامکی به شماره‌های ثبت‌شده مدیر | حساب گوگل با ایمیل‌های ثبت‌شده | رمز ثابت (config.php)
 */
require_once dirname(__DIR__) . '/includes/sec_lib.php';
sec_boot('auth');   // امنیت: نشست امن، WAF، محدودیت درخواست
sec_form_guard();   // تله ربات: فیلد مخفی + زمان پر کردن فرم
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';
require_once dirname(__DIR__) . '/includes/comm_ui.php';

if (!empty($_SESSION['aichat_admin'])) {
    header('Location: index.php'); exit;
}

$pdo = null;
$methods = ['password'];
try {
    $pdo = aichat_connect();
    try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
    try { if (function_exists('biz_ensure_schema')) biz_ensure_schema($pdo); } catch (\Throwable $e) {}
    $methods = comm_login_active($pdo, 'admin');
} catch (\Throwable $e) { $pdo = null; }   // بدون دیتابیس فقط ورود با رمز

// نسخه ۷۸: ورود دو مرحله‌ای — «فقط کد پیامکی» یک‌مرحله‌ای است و در این حالت نمایش داده نمی‌شود (رمز/گوگل + کد پیامکی)
$tfa = comm_admin_2fa_on($pdo);
if ($tfa) { $methods = array_values(array_diff($methods, ['sms'])); if (!$methods) $methods = ['password']; }
$tfa_st = $tfa ? comm_admin_2fa_state() : null;
if (!$tfa) unset($_SESSION['adm_2fa']);
$m = in_array($_REQUEST['m'] ?? '', $methods, true) ? $_REQUEST['m'] : $methods[0];
if (($_REQUEST['m'] ?? '') === '2fa' && $tfa_st) $m = '2fa';
$forgot = ($_REQUEST['m'] ?? '') === 'forgot';   // فراموشی رمز مدیر (پیامک یا ایمیل)
$error = '';
$info = '';
$brand = $pdo ? comm_brand($pdo) : 'پنل مدیریت';
$captcha = saas_generate_captcha();

function adm_login_ok($second = false)
{
    global $pdo;
    // مرحله اول درست بود ← اگر ورود دو مرحله‌ای روشن است، اول کد پیامکی
    if (!$second && $pdo && comm_admin_2fa_on($pdo)) { comm_admin_2fa_begin($pdo, 'password'); header('Location: login.php?m=2fa'); exit; }
    unset($_SESSION['adm_2fa']);
    if ($pdo && function_exists('ipg_success')) ipg_success($pdo);
    session_regenerate_id(true);
    unset($_SESSION['adm_sms'], $_SESSION['adm_pw_checked'], $_SESSION['adm_pw_weak']);
    $_SESSION['aichat_admin'] = true;
    $_SESSION['aichat_admin_time'] = time();
    header('Location: index.php'); exit;
}

// ---------------------------------------------------------------------------
// فراموشی رمز مدیر: کد ۵ رقمی به شماره یا ایمیل ثبت‌شده مدیر (پیامک و ورود ← مدیر کل) + رمز جدید
// راه نجات بدون پیامک/ایمیل: فایل خالی uploads/admin_reset.txt ← رمز مدیر به رمز config.php برمی‌گردد
// ---------------------------------------------------------------------------
$fp_sms_ok = $pdo && comm_platform_sms_ready($pdo) && comm_admin_mobiles($pdo);
$fp_mail_ok = $pdo && !empty(comm_mail_cfg($pdo)['enabled']) && comm_admin_emails($pdo);
$fp = $_SESSION['adm_fp'] ?? null;
if ($fp && time() - (int)$fp['at'] > 900) { unset($_SESSION['adm_fp']); $fp = null; }
if ($forgot && isset($_GET['reset'])) { unset($_SESSION['adm_fp']); $fp = null; }
if ($pdo && function_exists('biz_set')) {
    $rf = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/');
    foreach (['admin_reset.txt', 'admin_reset'] as $n) {
        if (is_file($rf . '/' . $n)) {
            biz_set($pdo, 'admin_pw_hash', '');
            @unlink($rf . '/' . $n);
            if (function_exists('ipg_success')) ipg_success($pdo);
            $info = 'رمز مدیر به رمز تعریف‌شده در فایل config.php برگشت (فایل admin_reset.txt حذف شد). وارد شوید و از «امنیت» رمز جدید بگذارید.';
            break;
        }
    }
}
/** کلید ذخیره کد ایمیلی در جدول کدها (ستون موبایل ۲۰ کاراکتر است) */
function adm_fp_key($email) { return 'e' . substr(sha1(strtolower(trim((string)$email))), 0, 19); }

$sms = $_SESSION['adm_sms'] ?? null;
if ($sms && time() - (int)$sms['at'] > 600) { unset($_SESSION['adm_sms']); $sms = null; }
if (isset($_GET['reset'])) { unset($_SESSION['adm_sms']); $sms = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? 'password');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    // محافظ IP: بعد از ورودهای ناموفق پیاپی، این IP موقتاً قفل یا مسدود می‌شود
    $ipb = ($pdo && function_exists('ipg_blocked_msg')) ? ipg_blocked_msg($pdo) : '';
    if ($ipb !== '') { $error = $ipb; $act = '_ip_locked'; }
    if ($forgot && $act === 'fp_send' && $pdo) {
        $ch = ($_POST['ch'] ?? '') === 'email' ? 'email' : 'sms';
        $to = trim((string)($_POST['to'] ?? ''));
        if (function_exists('ex_rate_limited') && ex_rate_limited('admin_fp:' . $ip, 6, 3600)) $error = 'درخواست‌های بازیابی زیاد است؛ یک ساعت دیگر تلاش کنید.';
        elseif (!saas_verify_captcha($_POST['captcha_answer'] ?? '', $_POST['captcha_token'] ?? '')) $error = 'پاسخ تأیید امنیتی اشتباه است.';
        elseif ($ch === 'sms') {
            $mob = biz_mobile($to);
            if (!$fp_sms_ok) $error = 'بازیابی با پیامک تنظیم نشده است.';
            elseif ($mob === '' || !in_array($mob, comm_admin_mobiles($pdo), true)) {
                $error = 'این شماره به‌عنوان شماره مدیر ثبت نشده است.';
                if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'admin', 'بازیابی رمز: ' . $mob)) !== '') $error = $ipl;
            } else {
                $err = comm_otp_send($pdo, 'admin', $mob, 'reset', 'otp_admin');
                if ($err !== '') $error = $err;
                else { $_SESSION['adm_fp'] = ['ch' => 'sms', 'to' => $mob, 'at' => time()]; $fp = $_SESSION['adm_fp']; $info = 'کد بازیابی به ' . comm_mask_mobile($mob) . ' پیامک شد.'; }
            }
        } else {
            $em = strtolower($to);
            if (!$fp_mail_ok) $error = 'بازیابی با ایمیل تنظیم نشده است.';
            elseif (!filter_var($em, FILTER_VALIDATE_EMAIL) || !in_array($em, comm_admin_emails($pdo), true)) {
                $error = 'این ایمیل به‌عنوان ایمیل مدیر ثبت نشده است.';
                if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'admin', 'بازیابی رمز: ' . $em)) !== '') $error = $ipl;
            } else {
                $key = adm_fp_key($em);
                $c = $pdo->prepare("SELECT COUNT(*) FROM saas_otps WHERE scope='admin' AND mobile=? AND created_at >= ?");
                $c->execute([$key, comm_now(-600)]);
                if ((int)$c->fetchColumn() >= 3) $error = 'تعداد درخواست کد زیاد است؛ ۱۰ دقیقه دیگر تلاش کنید.';
                else {
                    $code = (string)random_int(10000, 99999);
                    $txt = "کد بازیابی رمز پنل مدیریت: {$code}\nاین کد ۱۰ دقیقه اعتبار دارد. اگر شما درخواست نداده‌اید، این ایمیل را نادیده بگیرید و رمز خود را عوض کنید.";
                    $r = comm_mail_send($pdo, $em, 'کد بازیابی رمز مدیر — ' . $brand, $txt, comm_mail_html('بازیابی رمز مدیر', $txt));
                    if (empty($r['ok'])) { $error = 'ارسال ایمیل ممکن نشد: ' . ($r['error'] ?? ''); }
                    else {
                        $pdo->prepare("INSERT INTO saas_otps (scope, purpose, mobile, code_hash, attempts, ip, expires_at, created_at) VALUES('admin','reset',?,?,0,?,?,?)")
                            ->execute([$key, comm_otp_hash('admin', 'reset', $key, $code), comm_ip(), comm_now(600), comm_now()]);
                        $_SESSION['adm_fp'] = ['ch' => 'email', 'to' => $em, 'at' => time()]; $fp = $_SESSION['adm_fp'];
                        $info = 'کد بازیابی به ایمیل ' . preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', $em) . ' فرستاده شد (پوشه اسپم را هم ببینید).';
                    }
                }
            }
        }
    } elseif ($forgot && $act === 'fp_reset' && $pdo && $fp) {
        $code = (string)($_POST['code'] ?? '');
        $n1 = (string)($_POST['new1'] ?? ''); $n2 = (string)($_POST['new2'] ?? '');
        if ($n1 !== $n2) $error = 'تکرار رمز جدید یکسان نیست.';
        elseif (strlen($n1) < 12 || sec_weak_password($n1)) $error = 'رمز جدید باید حداقل ۱۲ کاراکتر و ترکیبی از حروف بزرگ و کوچک، عدد یا نماد باشد.';
        else {
            if ($fp['ch'] === 'sms') $err = comm_otp_check($pdo, 'admin', $fp['to'], 'reset', $code);
            else {
                $key = adm_fp_key($fp['to']);
                $code = preg_replace('/\D+/', '', strtr($code, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
                $st = $pdo->prepare("SELECT * FROM saas_otps WHERE scope='admin' AND mobile=? AND purpose='reset' ORDER BY id DESC LIMIT 1");
                $st->execute([$key]);
                $otp = $st->fetch(PDO::FETCH_ASSOC);
                if (!$otp || strtotime((string)$otp['expires_at']) < time()) $err = 'کد منقضی شده است؛ دوباره درخواست کنید.';
                elseif ((int)$otp['attempts'] >= 5) $err = 'تعداد تلاش‌ها زیاد است؛ کد جدید بگیرید.';
                else {
                    $pdo->prepare("UPDATE saas_otps SET attempts = attempts + 1 WHERE id=?")->execute([(int)$otp['id']]);
                    if (strlen($code) !== 5 || !hash_equals((string)$otp['code_hash'], comm_otp_hash('admin', 'reset', $key, $code))) {
                        $err = 'کد تأیید نادرست است.';
                        if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'reset', $fp['to'])) !== '') $err = $ipl;
                    } else { $pdo->prepare("DELETE FROM saas_otps WHERE scope='admin' AND mobile=? AND purpose='reset'")->execute([$key]); $err = ''; }
                }
            }
            if ($err !== '') $error = $err;
            else {
                biz_set($pdo, 'admin_pw_hash', password_hash($n1, PASSWORD_DEFAULT));
                unset($_SESSION['adm_fp'], $_SESSION['adm_pw_weak']);
                if (function_exists('ipg_success')) ipg_success($pdo);
                if (function_exists('biz_notify_admin')) { try { biz_notify_admin($pdo, '🔑 رمز مدیر بازیابی شد', 'رمز مدیر کل با کد ' . ($fp['ch'] === 'sms' ? 'پیامکی' : 'ایمیلی') . ' تغییر کرد. IP: ' . $ip); } catch (\Throwable $e) {} }
                header('Location: login.php?m=password&msg=' . urlencode('✅ رمز مدیر تغییر کرد. با رمز جدید وارد شوید.')); exit;
            }
        }
    } elseif ($act === 'tfa_send' && $m === '2fa' && $tfa_st) {
        // چند شماره مدیر: انتخاب شماره و ارسال (یا ارسال دوباره) کد مرحله دوم
        $mobs = comm_admin_mobiles($pdo);
        $mob = $tfa_st['mobile'] !== '' && empty($_POST['mi']) ? $tfa_st['mobile'] : ($mobs[(int)($_POST['mi'] ?? 0)] ?? '');
        if ($mob === '') $error = 'شماره‌ای انتخاب نشده است.';
        else {
            $err = comm_otp_send($pdo, 'admin', $mob, 'login2', 'otp_admin');
            $_SESSION['adm_2fa']['mobile'] = $mob;
            if ($err !== '') $error = $err;
            else { $_SESSION['adm_2fa']['sent'] = time(); $_SESSION['adm_2fa']['err'] = ''; $info = 'کد ورود به ' . comm_mask_mobile($mob) . ' پیامک شد.'; }
            $tfa_st = comm_admin_2fa_state();
        }
    } elseif ($act === 'tfa_verify' && $m === '2fa' && $tfa_st && $tfa_st['mobile'] !== '') {
        $err = comm_otp_check($pdo, 'admin', $tfa_st['mobile'], 'login2', $_POST['code'] ?? '');
        if ($err === '' && in_array($tfa_st['mobile'], comm_admin_mobiles($pdo), true)) {
            if (function_exists('biz_notify_admin')) { try { biz_notify_admin($pdo, '🔐 ورود مدیر', 'ورود دو مرحله‌ای مدیر کل (' . ($tfa_st['via'] === 'google' ? 'گوگل' : 'رمز') . ' + پیامک به ' . comm_mask_mobile($tfa_st['mobile']) . '). IP: ' . $ip); } catch (\Throwable $e) {} }
            adm_login_ok(true);
        }
        $error = $err ?: 'این شماره دیگر مجاز نیست.';
        if ($err !== '' && $pdo && function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'admin', 'کد مرحله دوم مدیر')) !== '') $error = $ipl;
    } elseif ($act === 'password' && $m === 'password') {
        if (function_exists('ex_rate_limited') && ex_rate_limited('admin_pw:' . $ip, 10, 900)) $error = 'تلاش‌های ناموفق زیاد است؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.';
        elseif (!saas_verify_captcha($_POST['captcha_answer'] ?? '', $_POST['captcha_token'] ?? '')) $error = 'پاسخ تأیید امنیتی اشتباه است.';
        elseif (sec_admin_password_ok($pdo, (string)($_POST['password'] ?? ''))) { if (sec_admin_password_weak($pdo)) $_SESSION['adm_pw_weak'] = 1; adm_login_ok(); }
        else {
            $error = 'رمز عبور اشتباه است.';
            if ($pdo && function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'admin', 'رمز مدیر')) !== '') $error = $ipl;
        }
    } elseif ($act === 'sms_send' && $m === 'sms' && $pdo) {
        $mob = biz_mobile($_POST['mobile'] ?? '');
        if (!saas_verify_captcha($_POST['captcha_answer'] ?? '', $_POST['captcha_token'] ?? '')) $error = 'پاسخ تأیید امنیتی اشتباه است.';
        elseif ($mob === '' || !in_array($mob, comm_admin_mobiles($pdo), true)) {
            $error = 'این شماره به‌عنوان شماره مدیر ثبت نشده است.';
            if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'admin', $mob)) !== '') $error = $ipl;
        }
        else {
            $err = comm_otp_send($pdo, 'admin', $mob, 'login', 'otp_admin');
            if ($err !== '') $error = $err;
            else { $_SESSION['adm_sms'] = ['mobile' => $mob, 'at' => time()]; $sms = $_SESSION['adm_sms']; $info = 'کد ورود به ' . comm_mask_mobile($mob) . ' پیامک شد.'; }
        }
    } elseif ($act === 'sms_verify' && $m === 'sms' && $pdo && $sms) {
        $err = comm_otp_check($pdo, 'admin', $sms['mobile'], 'login', $_POST['code'] ?? '');
        if ($err === '' && in_array($sms['mobile'], comm_admin_mobiles($pdo), true)) adm_login_ok();
        $error = $err ?: 'این شماره دیگر مجاز نیست.';
    }
    $captcha = saas_generate_captcha();
}

$msg = $_GET['msg'] ?? '';
if (($_GET['m'] ?? '') === '2fa' && !$tfa_st && $error === '') $error = $tfa ? 'مهلت مرحله دوم تمام شده است؛ دوباره وارد شوید.' : '';
if ($m === '2fa' && $tfa_st && $tfa_st['err'] !== '' && $error === '' && $info === '') $error = 'ارسال کد ممکن نشد: ' . $tfa_st['err'];
if ($m === '2fa' && $tfa_st && $tfa_st['sent'] && $info === '' && $error === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') $info = 'کد ورود به ' . comm_mask_mobile($tfa_st['mobile']) . ' پیامک شد.';
if (!empty($_SESSION['google_err'])) { $error = (string)$_SESSION['google_err']; unset($_SESSION['google_err']); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ورود مدیر — <?php echo comm_h($brand); ?></title>
<?php echo comm_auth_css('#7c3aed', 'linear-gradient(135deg,#0f172a,#1e1b4b)'); ?>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<div class="box">
    <div style="text-align:center;font-size:44px;margin-bottom:6px">🛡️</div>
    <h1 style="justify-content:center">پنل مدیریت مرکزی</h1>
    <p class="sub" style="text-align:center"><?php echo comm_h($brand); ?></p>

    <?php if ($error): ?><div class="alert alert-danger"><?php echo comm_h($error); ?></div><?php endif; ?>
    <?php if ($error !== '' && isset($ipb) && ($ipb !== '' || (isset($ipl) && $ipl !== ''))): ?><div style="font-size:12px;color:#64748b;line-height:1.9;margin:-6px 0 12px">اگر مدیر سایت هستید و IP خودتان قفل شده: از طریق هاست (File Manager) یک فایل خالی با نام <b dir="ltr">ipguard_reset.txt</b> داخل پوشه <b dir="ltr">uploads</b> بسازید و صفحه را دوباره باز کنید؛ همه قفل‌ها پاک می‌شود.</div><?php endif; ?>
    <?php if ($info): ?><div class="alert alert-success"><?php echo comm_h($info); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo comm_h($msg); ?></div><?php endif; ?>

    <?php if (!$forgot && $m !== '2fa') echo comm_method_tabs($methods, $m, 'login.php'); ?>
    <?php if ($tfa && !$forgot && $m !== '2fa'): ?><p class="sub" style="text-align:center;font-size:12.5px">🔐 ورود دو مرحله‌ای فعال است: بعد از این مرحله، یک کد پیامکی هم لازم است.</p><?php endif; ?>

    <?php if ($m === '2fa' && $tfa_st): $tmobs = comm_admin_mobiles($pdo); ?>
    <h2 style="font-size:16px;text-align:center;margin:4px 0 12px">🔐 مرحله دوم ورود</h2>
    <?php if ($tfa_st['mobile'] === '' || !$tfa_st['sent']): ?>
    <form method="post" action="login.php?m=2fa" autocomplete="off">
        <input type="hidden" name="act" value="tfa_send">
        <?php if (count($tmobs) > 1 && $tfa_st['mobile'] === ''): ?>
        <div class="form-group"><label>کد به کدام شماره مدیر فرستاده شود؟</label>
            <?php foreach ($tmobs as $i => $tm): ?><label style="display:flex;gap:8px;align-items:center;margin:6px 0;cursor:pointer;font-size:14px"><input type="radio" name="mi" value="<?php echo $i; ?>" <?php echo $i === 0 ? 'checked' : ''; ?>> <span dir="ltr"><?php echo comm_h(comm_mask_mobile($tm)); ?></span></label><?php endforeach; ?>
        </div>
        <?php else: ?><p class="sub">کد ورود به <b dir="ltr"><?php echo comm_h(comm_mask_mobile($tfa_st['mobile'] ?: ($tmobs[0] ?? ''))); ?></b> فرستاده می‌شود.</p><?php endif; ?>
        <button type="submit" class="btn">📩 ارسال کد</button>
    </form>
    <?php else: ?>
    <form method="post" action="login.php?m=2fa" autocomplete="off">
        <input type="hidden" name="act" value="tfa_verify">
        <p class="sub">کد ۵ رقمی پیامک‌شده به <b dir="ltr"><?php echo comm_h(comm_mask_mobile($tfa_st['mobile'])); ?></b> را وارد کنید.</p>
        <div class="form-group"><input type="text" name="code" class="otp" required inputmode="numeric" maxlength="5" autocomplete="one-time-code" autofocus placeholder="•••••"></div>
        <button type="submit" class="btn">ورود به پنل</button>
    </form>
    <form method="post" action="login.php?m=2fa" style="margin-top:8px;text-align:center"><input type="hidden" name="act" value="tfa_send"><button type="submit" style="background:none;border:0;color:#7c3aed;cursor:pointer;font-family:inherit;font-size:13px">ارسال دوباره کد</button></form>
    <?php endif; ?>
    <p class="links" style="margin-top:10px;text-align:center"><a href="login.php?m=password">انصراف و بازگشت</a></p>
    <div style="font-size:12px;color:#64748b;line-height:1.9;margin-top:12px;border-top:1px solid #e2e8f0;padding-top:8px">اگر پیامک نمی‌رسد و به هاست دسترسی دارید: یک فایل خالی با نام <b dir="ltr">admin_2fa_off.txt</b> داخل پوشه <b dir="ltr">uploads</b> بسازید و دوباره وارد شوید؛ ورود دو مرحله‌ای خاموش می‌شود.</div>
    <?php elseif ($forgot): ?>
    <h2 style="font-size:16px;text-align:center;margin:4px 0 12px">🔑 فراموشی رمز مدیر</h2>
    <?php if ($fp): ?>
    <form method="post" action="login.php?m=forgot" autocomplete="off">
        <input type="hidden" name="act" value="fp_reset">
        <p class="sub">کد ۵ رقمی <?php echo $fp['ch'] === 'sms' ? 'پیامک‌شده به <b dir="ltr">' . comm_h(comm_mask_mobile($fp['to'])) . '</b>' : 'ایمیل‌شده'; ?> و رمز جدید را وارد کنید.</p>
        <div class="form-group"><label>کد بازیابی</label><input type="text" name="code" class="otp" required inputmode="numeric" maxlength="5" autocomplete="one-time-code" autofocus placeholder="•••••"></div>
        <input type="text" name="username" value="admin" autocomplete="username" readonly tabindex="-1" aria-hidden="true" style="display:none">
        <div class="form-group"><label>رمز جدید (حداقل ۱۲ کاراکتر، ترکیب حروف بزرگ و کوچک، عدد یا نماد)</label><input type="password" name="new1" required minlength="12" dir="ltr" autocomplete="new-password"></div>
        <div class="form-group"><label>تکرار رمز جدید</label><input type="password" name="new2" required minlength="12" dir="ltr" autocomplete="new-password"></div>
        <button type="submit" class="btn">ذخیره رمز جدید</button>
        <p class="links" style="margin-top:10px"><a href="login.php?m=forgot&reset=1">ارسال دوباره / تغییر روش</a> · <a href="login.php?m=password">بازگشت به ورود</a></p>
    </form>
    <?php elseif ($fp_sms_ok || $fp_mail_ok): ?>
    <form method="post" action="login.php?m=forgot" autocomplete="off">
        <input type="hidden" name="act" value="fp_send">
        <div class="form-group"><label>کد بازیابی به کجا فرستاده شود؟</label>
            <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:14px">
                <?php if ($fp_sms_ok): ?><label style="display:flex;gap:6px;align-items:center;cursor:pointer"><input type="radio" name="ch" value="sms" checked onchange="fpCh()"> 📩 پیامک</label><?php endif; ?>
                <?php if ($fp_mail_ok): ?><label style="display:flex;gap:6px;align-items:center;cursor:pointer"><input type="radio" name="ch" value="email" <?php echo $fp_sms_ok ? '' : 'checked'; ?> onchange="fpCh()"> ✉️ ایمیل</label><?php endif; ?>
            </div>
        </div>
        <div class="form-group"><label id="fpl"><?php echo $fp_sms_ok ? 'شماره موبایل مدیر' : 'ایمیل مدیر'; ?></label><input type="text" name="to" id="fpto" required dir="ltr" placeholder="<?php echo $fp_sms_ok ? '09121234567' : 'admin@example.com'; ?>"></div>
        <?php echo comm_captcha_field($captcha); ?>
        <button type="submit" class="btn">ارسال کد بازیابی</button>
        <p class="links" style="margin-top:10px"><a href="login.php?m=password">بازگشت به ورود</a></p>
        <script>function fpCh(){var e=document.querySelector('input[name=ch]:checked').value==='email';document.getElementById('fpl').textContent=e?'ایمیل مدیر':'شماره موبایل مدیر';var t=document.getElementById('fpto');t.placeholder=e?'admin@example.com':'09121234567';t.type=e?'email':'tel';}</script>
    </form>
    <?php endif; ?>
    <div style="font-size:12.5px;color:#64748b;line-height:2;margin-top:14px;border-top:1px solid #e2e8f0;padding-top:10px">
        <?php if (!$fp_sms_ok && !$fp_mail_ok): ?><b>بازیابی با پیامک یا ایمیل هنوز تنظیم نشده است</b> (شماره/ایمیل مدیر در «پیامک و ورود ← مدیر کل»، و سرویس پیامک یا ایمیل در «تنظیمات API»).<br><?php endif; ?>
        راه دیگر (از طریق هاست): با File Manager هاست یک فایل خالی با نام <b dir="ltr">admin_reset.txt</b> داخل پوشه <b dir="ltr">uploads</b> بسازید و همین صفحه را تازه کنید؛ رمز مدیر به رمز نوشته‌شده در فایل <b dir="ltr">config.php</b> (AICHAT_ADMIN_PASSWORD) برمی‌گردد. بعد از ورود، از بخش «امنیت» رمز جدید بگذارید.
    </div>
    <?php elseif ($m === 'sms' && !$sms): ?>
    <form method="post" action="login.php?m=sms" autocomplete="off">
        <input type="hidden" name="act" value="sms_send">
        <div class="form-group"><label>شماره موبایل مدیر</label><input type="tel" name="mobile" required dir="ltr" placeholder="09121234567" autofocus></div>
        <?php echo comm_captcha_field($captcha); ?>
        <button type="submit" class="btn">📩 دریافت کد ورود</button>
    </form>
    <?php elseif ($m === 'sms'): ?>
    <form method="post" action="login.php?m=sms" autocomplete="off">
        <input type="hidden" name="act" value="sms_verify">
        <p class="sub">کد پیامک‌شده به <b dir="ltr"><?php echo comm_h(comm_mask_mobile($sms['mobile'])); ?></b> را وارد کنید.</p>
        <div class="form-group"><input type="text" name="code" class="otp" required inputmode="numeric" maxlength="5" autocomplete="one-time-code" autofocus placeholder="•••••"></div>
        <button type="submit" class="btn">ورود به پنل</button>
        <p class="links" style="margin-top:10px"><a href="login.php?m=sms&reset=1">ارسال دوباره کد</a></p>
    </form>
    <?php elseif ($m === 'google'): ?>
        <a href="../user/google.php?for=admin" class="btn btn-o"><?php echo comm_google_icon(); ?> ورود با حساب گوگل مدیر</a>
    <?php else: ?>
    <form method="post" action="login.php?m=password">
        <input type="hidden" name="act" value="password">
        <div class="form-group"><label>رمز عبور ادمین</label>
        <input type="text" name="username" value="admin" autocomplete="username" readonly tabindex="-1" aria-hidden="true" style="display:none">
        <input type="password" name="password" autofocus placeholder="رمز عبور مدیر کل" dir="ltr" autocomplete="current-password"></div>
        <?php echo comm_captcha_field($captcha); ?>
        <button type="submit" class="btn">ورود به پنل</button>
        <p class="links" style="margin-top:10px;text-align:center"><a href="login.php?m=forgot">رمز را فراموش کرده‌اید؟</a></p>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
