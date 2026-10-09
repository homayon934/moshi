<?php
/**
 * پنل همکاری در فروش (همکاران / بازاریاب‌ها)
 * ورود و ثبت‌نام با موبایل + کد پیامکی
 */
require_once dirname(__DIR__) . '/includes/sec_lib.php';
sec_boot('auth');   // امنیت: نشست امن، WAF، محدودیت درخواست
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';
require_once dirname(__DIR__) . '/includes/comm_ui.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}

function aff_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['aff_csrf'])) $_SESSION['aff_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['aff_csrf'];
$bs = biz_settings($pdo);
$enabled = !empty($bs['aff_enabled']);
$error = ''; $info = '';
try { if (function_exists('biz_ensure_schema')) biz_ensure_schema($pdo); } catch (\Throwable $e) {}
// روش‌های ورود (پیامک، گوگل، رمز) به ترتیب تعیین‌شده در پنل مدیر
$methods = comm_login_active($pdo, 'aff');
$lm = in_array($_REQUEST['m'] ?? '', $methods, true) ? $_REQUEST['m'] : $methods[0];
if (!empty($_SESSION['google_err'])) { $error = (string)$_SESSION['google_err']; unset($_SESSION['google_err']); }

if (isset($_GET['logout'])) { unset($_SESSION['aff_id'], $_SESSION['aff_pending']); header('Location: ./'); exit; }

$aff = !empty($_SESSION['aff_id']) ? biz_affiliate($pdo, (int)$_SESSION['aff_id']) : null;
if (!$aff) unset($_SESSION['aff_id']);

// ---------------------------------------------------------------------
// ورود / ثبت‌نام
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $error = 'نشست منقضی شده است؛ دوباره تلاش کنید.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$aff) {
    $step = $_POST['step'] ?? '';
    // محافظ IP: بعد از ورودهای ناموفق پیاپی، این IP موقتاً قفل یا مسدود می‌شود
    $ipb = function_exists('ipg_blocked_msg') ? ipg_blocked_msg($pdo) : '';
    if ($ipb !== '' && in_array($step, ['send', 'verify', 'pw'], true)) { $error = $ipb; $step = '_ip_locked'; }
    if ($step === 'send') {
        $mobile = biz_mobile($_POST['mobile'] ?? '');
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $ex = $pdo->prepare("SELECT id FROM saas_affiliates WHERE mobile=?");
        $ex->execute([$mobile]);
        $exists = (bool)$ex->fetchColumn();
        if ($mobile === '') $error = 'شماره موبایل معتبر نیست (مثال: 09121234567).';
        elseif (!$exists && !$enabled) $error = 'ثبت‌نام همکار جدید در حال حاضر فعال نیست.';
        elseif (!$exists && mb_strlen($name) < 2) { $error = 'برای ثبت‌نام، نام و نام خانوادگی خود را وارد کنید.'; $_SESSION['aff_pending'] = ['mobile' => $mobile, 'need_name' => true]; }
        elseif (!in_array('sms', $methods, true)) $error = 'ورود با کد پیامکی فعال نیست.';
        else {
            $e = comm_otp_send($pdo, 'aff', $mobile, 'login', 'otp_aff');
            if ($e !== '') $error = $e;
            else {
                $_SESSION['aff_pending'] = ['mobile' => $mobile, 'name' => $name, 'new' => !$exists];
                $info = 'کد ۵ رقمی به ' . $mobile . ' پیامک شد.';
            }
        }
    } elseif ($step === 'verify' && !empty($_SESSION['aff_pending']['mobile'])) {
        $pend = $_SESSION['aff_pending'];
        $mobile = $pend['mobile'];
        $oe = comm_otp_check($pdo, 'aff', $mobile, 'login', $_POST['code'] ?? '');
        if ($oe !== '') $error = $oe;
        else {
            {
                $r = biz_create_affiliate($pdo, (string)($pend['name'] ?? ''), $mobile);
                if (!$r['ok']) $error = $r['error'];
                else {
                    session_regenerate_id(true);
                    $_SESSION['aff_id'] = $r['id'];
                    unset($_SESSION['aff_pending']);
                    $pdo->prepare("UPDATE saas_affiliates SET last_login=? WHERE id=?")->execute([biz_now(), $r['id']]);
                    header('Location: ./'); exit;
                }
            }
        }
        $info = '';
    } elseif ($step === 'pw' && in_array('password', $methods, true)) {
        // ورود با موبایل و رمز ثابت
        $mobile = biz_mobile($_POST['mobile'] ?? '');
        if (!saas_verify_captcha($_POST['captcha_answer'] ?? '', $_POST['captcha_token'] ?? '')) $error = 'پاسخ تأیید امنیتی اشتباه است.';
        else {
            $a = $pdo->prepare("SELECT * FROM saas_affiliates WHERE mobile=? LIMIT 1");
            $a->execute([$mobile]);
            $af = $a->fetch(PDO::FETCH_ASSOC);
            if (!$af || trim((string)($af['password_hash'] ?? '')) === '') {
                $error = 'شماره موبایل یا رمز عبور نادرست است (اگر رمز نگذاشته‌اید، با کد پیامکی وارد شوید و از «اطلاعات حساب» رمز بسازید).';
                if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'aff', $mobile)) !== '') $error = $ipl;
            }
            elseif (!empty($af['pw_lock_until']) && strtotime((string)$af['pw_lock_until']) > time()) $error = 'به‌دلیل تلاش‌های ناموفق، ورود با رمز تا ۱۵ دقیقه ممکن نیست.';
            elseif (!password_verify((string)($_POST['password'] ?? ''), (string)$af['password_hash'])) {
                $f = (int)$af['pw_fail'] + 1;
                $pdo->prepare("UPDATE saas_affiliates SET pw_fail=?, pw_lock_until=? WHERE id=?")->execute([$f >= 5 ? 0 : $f, $f >= 5 ? biz_now(900) : null, (int)$af['id']]);
                $error = 'شماره موبایل یا رمز عبور نادرست است.';
                if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'aff', $mobile)) !== '') $error = $ipl;
            } else {
                if (function_exists('ipg_success')) ipg_success($pdo);
                $pdo->prepare("UPDATE saas_affiliates SET pw_fail=0, pw_lock_until=NULL, last_login=? WHERE id=?")->execute([biz_now(), (int)$af['id']]);
                session_regenerate_id(true);
                $_SESSION['aff_id'] = (int)$af['id'];
                unset($_SESSION['aff_pending']);
                header('Location: ./'); exit;
            }
        }
    } elseif ($step === 'back') {
        unset($_SESSION['aff_pending']);
        header('Location: ./'); exit;
    }
}

// ---------------------------------------------------------------------
// پروفایل (اطلاعات واریز)
// ---------------------------------------------------------------------
if ($aff && $_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && ($_POST['step'] ?? '') === 'profile') {
    $sheba = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['sheba'] ?? '')));
    if ($sheba !== '' && strpos($sheba, 'IR') !== 0) $sheba = 'IR' . $sheba;
    $card = preg_replace('/\D+/', '', (string)($_POST['card_no'] ?? ''));
    if ($sheba !== '' && !preg_match('/^IR\d{24}$/', $sheba)) $error = 'شماره شبا باید ۲۴ رقم بعد از IR باشد.';
    elseif ($card !== '' && strlen($card) !== 16) $error = 'شماره کارت باید ۱۶ رقم باشد.';
    elseif (($em = strtolower(trim((string)($_POST['email'] ?? '')))) !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) $error = 'ایمیل معتبر نیست.';
    elseif (($np = (string)($_POST['new_password'] ?? '')) !== '' && mb_strlen($np) < 8) $error = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
    elseif ($np !== '' && $np !== (string)($_POST['new_password2'] ?? '')) $error = 'تکرار رمز با رمز جدید یکسان نیست.';
    else {
        $du = $pdo->prepare("SELECT COUNT(*) FROM saas_affiliates WHERE email=? AND email<>'' AND id<>?");
        $du->execute([$em, (int)$aff['id']]);
        if ((int)$du->fetchColumn() > 0) { $error = 'این ایمیل برای همکار دیگری ثبت شده است.'; }
    }
    if ($error === '') {
        if ($em !== strtolower((string)($aff['email'] ?? ''))) $pdo->prepare("UPDATE saas_affiliates SET email=?, google_sub='' WHERE id=?")->execute([$em, (int)$aff['id']]);
        if ($np !== '') $pdo->prepare("UPDATE saas_affiliates SET password_hash=?, pw_fail=0, pw_lock_until=NULL WHERE id=?")->execute([password_hash($np, PASSWORD_DEFAULT), (int)$aff['id']]);
        $pdo->prepare("UPDATE saas_affiliates SET name=?, sheba=?, card_no=?, account_owner=? WHERE id=?")
            ->execute([mb_substr(trim($_POST['name'] ?? ''), 0, 120) ?: $aff['name'], $sheba, $card, mb_substr(trim($_POST['account_owner'] ?? ''), 0, 120), (int)$aff['id']]);
        header('Location: ./?saved=1#profile'); exit;
    }
}

$pend = $_SESSION['aff_pending'] ?? null;
$code_step = !$aff && $pend && empty($pend['need_name']) && ($info !== '' || ($error !== '' && ($_POST['step'] ?? '') === 'verify'));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>همکاری در فروش</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Vazirmatn,Tahoma,sans-serif;background:#f1f5f9;color:#1e293b;font-size:14px;line-height:1.8;min-height:100vh}
.wrap{max-width:980px;margin:0 auto;padding:20px 16px}
.top{background:linear-gradient(135deg,#1e1b4b,#4338ca);color:#fff;padding:18px 0}
.top .wrap{display:flex;justify-content:space-between;align-items:center;padding-top:0;padding-bottom:0}
.top h1{font-size:18px}.top a{color:#c7d2fe;font-size:13px}
.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.card h2{font-size:15px;margin-bottom:12px}
.login{max-width:400px;margin:40px auto}
input{width:100%;padding:11px 13px;border:1.5px solid #d1d5db;border-radius:10px;font-family:inherit;font-size:14px;margin-bottom:10px}
input:focus{outline:none;border-color:#4338ca}
.btn{display:inline-block;background:#4338ca;color:#fff;border:none;border-radius:10px;padding:11px 18px;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none}
.btn.w{width:100%}.btn.g{background:#e2e8f0;color:#334155}
.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:13px}
.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:13px}
.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px;margin-bottom:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}
.stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;text-align:center}
.stat b{display:block;font-size:19px}.stat span{font-size:12px;color:#64748b}
.copy{display:flex;gap:6px;margin-bottom:10px}
.copy input{margin:0;direction:ltr;text-align:left;font-size:13px;background:#f8fafc}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right}
th{color:#64748b;font-weight:600;background:#f8fafc}
.tw{overflow-x:auto}
.muted{color:#64748b;font-size:12.5px}
@media(max-width:600px){.top h1{font-size:15px}}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<div class="top"><div class="wrap"><h1>🤝 پنل همکاری در فروش</h1><?php if ($aff): ?><a href="?logout=1">خروج</a><?php endif; ?></div></div>
<div class="wrap">

<?php if (!$aff): ?>
<div class="card login">
    <?php if ($error): ?><div class="err"><?php echo aff_h($error); ?></div><?php endif; ?>
    <?php if ($info): ?><div class="ok"><?php echo aff_h($info); ?></div><?php endif; ?>
    <?php if ($code_step): ?>
        <h2>کد تأیید</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="step" value="verify">
            <input name="code" inputmode="numeric" maxlength="5" placeholder="کد ۵ رقمی" dir="ltr" autocomplete="one-time-code" autofocus required>
            <button class="btn w">ورود</button>
        </form>
        <form method="post" style="margin-top:10px;text-align:center"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="step" value="back"><button class="btn g" style="font-size:12px;padding:6px 12px">تغییر شماره</button></form>
    <?php else: ?>
        <h2>ورود / ثبت‌نام همکار</h2>
        <?php if (count($methods) > 1): ?><nav style="display:flex;gap:4px;background:#f1f5f9;border-radius:11px;padding:4px;margin-bottom:14px"><?php foreach ($methods as $mt): ?><a href="?m=<?php echo $mt; ?>" style="flex:1;text-align:center;padding:7px 4px;border-radius:8px;font-size:12.5px;font-weight:600;text-decoration:none;<?php echo $mt === $lm ? 'background:#fff;color:#4338ca;box-shadow:0 1px 4px rgba(0,0,0,.08)' : 'color:#475569'; ?>"><?php echo ['sms' => '📩 کد پیامکی', 'google' => 'گوگل', 'password' => '🔑 رمز عبور'][$mt]; ?></a><?php endforeach; ?></nav><?php endif; ?>
        <?php if ($lm === 'google'): ?>
        <p class="muted" style="margin-bottom:12px">اگر ایمیل گوگل خود را در «اطلاعات حساب» ثبت کرده‌اید، با آن وارد شوید.</p>
        <a class="btn w" href="../user/google.php?for=aff" style="display:flex;align-items:center;justify-content:center;gap:8px;background:#fff;color:#1f2937;border:1.5px solid #d1d5db"><?php echo comm_google_icon(); ?> ورود با حساب گوگل</a>
        <?php elseif ($lm === 'password'): $cap = saas_generate_captcha(); ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="step" value="pw"><input type="hidden" name="m" value="password">
            <input name="mobile" inputmode="tel" dir="ltr" maxlength="14" placeholder="شماره موبایل" required value="<?php echo aff_h($_POST['mobile'] ?? ''); ?>">
            <input type="password" name="password" dir="ltr" placeholder="رمز عبور" required autocomplete="current-password">
            <div style="display:flex;gap:8px;align-items:center"><span style="background:#f1f5f9;border-radius:10px;padding:10px 12px;font-weight:800;direction:ltr;white-space:nowrap;margin-bottom:10px"><?php echo aff_h($cap['question']); ?> = ?</span><input name="captcha_answer" inputmode="numeric" dir="ltr" placeholder="پاسخ" required><input type="hidden" name="captcha_token" value="<?php echo aff_h($cap['token']); ?>"></div>
            <button class="btn w">ورود</button>
        </form>
        <?php else: ?>
        <p class="muted" style="margin-bottom:12px">با معرفی سرویس به دیگران، از هر خرید آن‌ها پورسانت بگیرید. اگر قبلاً ثبت‌نام کرده‌اید فقط شماره موبایل را وارد کنید.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="step" value="send">
            <input name="name" maxlength="120" placeholder="نام و نام خانوادگی (برای ثبت‌نام)" value="<?php echo aff_h($_POST['name'] ?? ''); ?>">
            <input name="mobile" inputmode="tel" dir="ltr" maxlength="14" placeholder="شماره موبایل" required value="<?php echo aff_h($_POST['mobile'] ?? ($pend['mobile'] ?? '')); ?>">
            <button class="btn w">دریافت کد ورود</button>
        </form>
        <?php endif; ?>
        <?php if (trim((string)$bs['aff_terms']) !== ''): ?><div class="muted" style="margin-top:14px;white-space:pre-wrap;border-top:1px dashed #e2e8f0;padding-top:10px"><?php echo aff_h($bs['aff_terms']); ?></div><?php endif; ?>
    <?php endif; ?>
</div>

<?php else:
    $stt = biz_affiliate_stats($pdo, (int)$aff['id']);
    $link = rtrim(AICHAT_BASE_URL, '/') . '/?ref=' . $aff['ref_code'];
    $reg_link = rtrim(AICHAT_BASE_URL, '/') . '/user/register.php?ref=' . $aff['ref_code'];
    $dcode = biz_find_code($pdo, $aff['ref_code']);
    $cm = $pdo->prepare("SELECT c.*, u.full_name FROM saas_commissions c LEFT JOIN saas_users u ON u.id=c.user_id WHERE c.affiliate_id=? ORDER BY c.id DESC LIMIT 100");
    $cm->execute([(int)$aff['id']]);
    $coms = $cm->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $po = $pdo->prepare("SELECT * FROM saas_aff_payouts WHERE affiliate_id=? ORDER BY id DESC LIMIT 50");
    $po->execute([(int)$aff['id']]);
    $payouts = $po->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $mask = function ($n) { $n = trim((string)$n); if ($n === '') return 'مشتری'; $p = preg_split('/\s+/u', $n); return $p[0] . (count($p) > 1 ? ' ' . mb_substr($p[count($p) - 1], 0, 1) . '.' : ''); };
?>
    <?php if ($error): ?><div class="err"><?php echo aff_h($error); ?></div><?php endif; ?>
    <?php if (!empty($_GET['saved'])): ?><div class="ok">اطلاعات ذخیره شد.</div><?php endif; ?>
    <?php if ($aff['status'] === 'pending'): ?>
        <div class="warn">⏳ <b><?php echo aff_h($aff['name']); ?></b> عزیز، ثبت‌نام شما انجام شد و پس از تأیید مدیر، لینک و کد معرفی شما فعال می‌شود.</div>
    <?php elseif ($aff['status'] === 'blocked'): ?>
        <div class="err">حساب همکاری شما غیرفعال شده است. برای پیگیری با پشتیبانی تماس بگیرید.</div>
    <?php else: ?>
    <div class="card">
        <h2>🔗 لینک و کد معرفی شما</h2>
        <div class="copy"><input readonly value="<?php echo aff_h($link); ?>" id="l1"><button class="btn" type="button" onclick="cp('l1',this)">کپی</button></div>
        <div class="copy"><input readonly value="<?php echo aff_h($reg_link); ?>" id="l2"><button class="btn" type="button" onclick="cp('l2',this)">کپی</button></div>
        <div class="copy"><input readonly value="<?php echo aff_h($aff['ref_code']); ?>" id="l3" style="font-weight:800;font-size:16px;text-align:center"><button class="btn" type="button" onclick="cp('l3',this)">کپی کد</button></div>
        <p class="muted">
            هر کس از لینک شما وارد شود و ثبت‌نام کند، یا هنگام خرید کد <b dir="ltr"><?php echo aff_h($aff['ref_code']); ?></b> را وارد کند، برای همیشه مشتری شما می‌شود و از <b>همه خریدهای او</b> <?php echo (float)$aff['commission_percent']; ?>٪ پورسانت می‌گیرید.
            <?php if ($dcode && (int)$dcode['value'] > 0 && !empty($dcode['is_active'])): ?>مشتری هم با کد شما <b><?php echo $dcode['kind'] === 'fixed' ? number_format((int)$dcode['value']) . ' تومان' : (int)$dcode['value'] . '٪'; ?> تخفیف</b> می‌گیرد.<?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>📊 آمار</h2>
        <div class="grid">
            <div class="stat"><b><?php echo number_format((int)$aff['clicks']); ?></b><span>بازدید لینک</span></div>
            <div class="stat"><b><?php echo number_format($stt['signups']); ?></b><span>ثبت‌نام</span></div>
            <div class="stat"><b><?php echo number_format($stt['sales']); ?></b><span>خرید</span></div>
            <div class="stat"><b><?php echo number_format($stt['earned']); ?></b><span>پورسانت کل (تومان)</span></div>
            <div class="stat"><b><?php echo number_format($stt['paid']); ?></b><span>واریز شده</span></div>
            <div class="stat" style="background:#ecfdf5;border-color:#a7f3d0"><b style="color:#059669"><?php echo number_format($stt['balance']); ?></b><span>مانده قابل تسویه</span></div>
        </div>
        <?php if ((int)$bs['aff_min_payout'] > 0): ?><p class="muted" style="margin-top:8px">تسویه از مبلغ <?php echo number_format((int)$bs['aff_min_payout']); ?> تومان به بالا و به شماره شبا/کارت ثبت‌شده انجام می‌شود.</p><?php endif; ?>
    </div>

    <div class="card">
        <h2>🧾 پورسانت‌ها</h2>
        <?php if (!$coms): ?><p class="muted" style="text-align:center;padding:10px">هنوز خریدی از طریق شما ثبت نشده است.</p><?php else: ?>
        <div class="tw"><table>
            <thead><tr><th>تاریخ</th><th>مشتری</th><th>نوع</th><th>مبلغ خرید</th><th>پورسانت</th><th>وضعیت</th></tr></thead><tbody>
            <?php foreach ($coms as $c): ?>
            <tr style="<?php echo $c['status'] === 'cancelled' ? 'opacity:.5' : ''; ?>">
                <td><?php echo biz_jdate($c['created_at']); ?></td>
                <td><?php echo aff_h($mask($c['full_name'])); ?></td>
                <td><?php echo ['plan' => 'اشتراک', 'credit' => 'شارژ', 'sms' => 'پیامک'][$c['payment_type']] ?? '—'; ?></td>
                <td><?php echo number_format((int)$c['base_amount']); ?></td>
                <td><b><?php echo number_format((int)$c['amount']); ?></b></td>
                <td><?php echo $c['status'] === 'cancelled' ? 'لغو (مرجوعی)' : '✓'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>

    <?php if ($payouts): ?>
    <div class="card">
        <h2>💸 واریزها</h2>
        <div class="tw"><table><thead><tr><th>تاریخ</th><th>مبلغ (تومان)</th><th>توضیح</th></tr></thead><tbody>
            <?php foreach ($payouts as $p): ?><tr><td><?php echo biz_jdate($p['created_at']); ?></td><td><?php echo number_format((int)$p['amount']); ?></td><td><?php echo aff_h($p['note']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="card" id="profile">
        <h2>👤 اطلاعات حساب و واریز</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="step" value="profile">
            <input name="name" maxlength="120" placeholder="نام و نام خانوادگی" value="<?php echo aff_h($aff['name']); ?>">
            <input name="sheba" maxlength="30" dir="ltr" placeholder="شماره شبا: IR..." value="<?php echo aff_h($aff['sheba']); ?>">
            <input name="card_no" maxlength="19" dir="ltr" inputmode="numeric" placeholder="شماره کارت (اختیاری)" value="<?php echo aff_h($aff['card_no']); ?>">
            <input name="account_owner" maxlength="120" placeholder="نام صاحب حساب" value="<?php echo aff_h($aff['account_owner']); ?>">
            <p class="muted" style="margin:6px 0">🔐 روش‌های دیگر ورود (اختیاری)</p>
            <input name="email" type="email" maxlength="190" dir="ltr" placeholder="ایمیل گوگل (برای ورود با گوگل)" value="<?php echo aff_h($aff['email'] ?? ''); ?>">
            <input name="new_password" type="password" dir="ltr" autocomplete="new-password" placeholder="<?php echo trim((string)($aff['password_hash'] ?? '')) !== '' ? 'رمز جدید (برای تغییر پر کنید)' : 'ساخت رمز عبور (حداقل ۸ کاراکتر)'; ?>">
            <input name="new_password2" type="password" dir="ltr" autocomplete="new-password" placeholder="تکرار رمز">
            <button class="btn">ذخیره</button>
        </form>
        <p class="muted" style="margin-top:8px">موبایل: <span dir="ltr"><?php echo aff_h($aff['mobile']); ?></span></p>
    </div>
    <?php if (trim((string)$bs['aff_terms']) !== ''): ?><div class="card"><h2>📜 قوانین همکاری</h2><div class="muted" style="white-space:pre-wrap"><?php echo aff_h($bs['aff_terms']); ?></div></div><?php endif; ?>
<?php endif; ?>
</div>
<script>
function cp(id, b){ var e = document.getElementById(id); e.select(); (navigator.clipboard ? navigator.clipboard.writeText(e.value) : Promise.resolve(document.execCommand('copy'))).then(function(){ var t = b.textContent; b.textContent = '✓'; setTimeout(function(){ b.textContent = t; }, 1200); }); }
</script>
</body>
</html>
