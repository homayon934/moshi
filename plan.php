<?php
/**
 * صفحه معرفی هر پلن (امکانات به ترتیب اولویت تعیین‌شده در پنل مدیریت)
 * آدرس: plan.php?id=شناسه
 */
require_once __DIR__ . '/includes/sec_lib.php'; sec_boot('auth'); sec_form_guard();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_lib.php';
require_once __DIR__ . '/includes/saas_lib.php';

site_security_headers();
$pdo = aichat_connect();
site_ensure_schema($pdo);
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
$s = site_settings($pdo);

$plan = saas_get_plan($pdo, (int)($_GET['id'] ?? 0));
if ($plan && empty($plan['is_active'])) $plan = null;
if (!$plan) http_response_code(404);
elseif (function_exists('act_visit')) act_visit($pdo, 'پلن ' . $plan['name']);   // گزارش بازدید (نسخه ۴۷)
$all = array_values(array_filter(saas_get_plans($pdo), fn($p) => !empty($p['is_active'])));
// دسته پلن (نسخه ۴۵): فقط پلن‌های همان دسته در «سایر پلن‌ها»
$pcat = ($plan && function_exists('pc_plan_cat')) ? pc_plan_cat($pdo, $plan) : null;
if ($pcat) $all = array_values(array_filter($all, fn($p) => ($c = pc_plan_cat($pdo, $p)) && (int)$c['id'] === (int)$pcat['id']));

// --- خرید سریع: نام + موبایل → کد پیامکی → ساخت/ورود حساب → پرداخت همین پلن ---
if (function_exists('biz_capture_ref')) { try { biz_capture_ref($pdo); } catch (\Throwable $e) {} }
$checkout = rtrim(AICHAT_BASE_URL, '/') . '/payment/checkout.php?type=plan&plan_id=' . (int)($plan['id'] ?? 0);
$logged = !empty($_SESSION['saas_user_id']);
$qs_err = ''; $qs_info = '';
$qs = $_SESSION['qs_plan'] ?? null;
if ($qs && (time() - (int)$qs['at'] > 900 || (int)$qs['plan'] !== (int)($plan['id'] ?? 0))) { unset($_SESSION['qs_plan']); $qs = null; }
$sms_ok = function_exists('comm_login_is') && comm_login_is($pdo, 'user', 'sms');
if ($plan && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    $same = $src === '' || strtolower((string)parse_url($src, PHP_URL_HOST)) === strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $act = (string)($_POST['act'] ?? '');
    if (!$same) $qs_err = 'درخواست نامعتبر است.';
    elseif ($act === 'qs_change') { unset($_SESSION['qs_plan']); $qs = null; }
    elseif ($act === 'qs_send' && !$logged) {
        $name = trim(mb_substr(strip_tags((string)($_POST['name'] ?? '')), 0, 100));
        $mob = function_exists('biz_mobile') ? biz_mobile($_POST['mobile'] ?? '') : '';
        if (!saas_verify_captcha($_POST['captcha_answer'] ?? '', $_POST['captcha_token'] ?? '')) $qs_err = 'پاسخ سوال امنیتی اشتباه است.';
        elseif (mb_strlen($name) < 2) $qs_err = 'نام خود را بنویسید.';
        elseif ($mob === '') $qs_err = 'شماره موبایل معتبر نیست (مثال: 09121234567).';
        elseif (!$sms_ok) $qs_err = 'ثبت‌نام با پیامک فعلاً ممکن نیست؛ از «ثبت‌نام کامل» استفاده کنید.';
        else {
            $ex = $pdo->prepare("SELECT id FROM saas_users WHERE phone=? LIMIT 1");
            $ex->execute([$mob]);
            $purpose = $ex->fetchColumn() ? 'login' : 'register';
            $err = comm_otp_send($pdo, 'user', $mob, $purpose, $purpose === 'login' ? 'otp_user_login' : 'otp_user_register');
            if ($err !== '') $qs_err = $err;
            else { $_SESSION['qs_plan'] = ['mobile' => $mob, 'name' => $name, 'plan' => (int)$plan['id'], 'purpose' => $purpose, 'at' => time()]; $qs = $_SESSION['qs_plan']; $qs_info = 'کد ۵ رقمی به ' . $mob . ' پیامک شد.'; }
        }
    } elseif ($act === 'qs_verify' && $qs && !$logged) {
        $err = comm_otp_check($pdo, 'user', $qs['mobile'], $qs['purpose'], $_POST['code'] ?? '');
        if ($err !== '') $qs_err = $err;
        else {
            $acc = cx_quick_account($pdo, $qs['mobile'], $qs['name']);
            if (!$acc['ok']) $qs_err = $acc['error'];
            else {
                unset($_SESSION['qs_plan']);
                session_regenerate_id(true);
                $_SESSION['saas_user_id'] = (int)$acc['user_id'];
                if ($acc['new'] && function_exists('biz_attach_referral')) { try { biz_attach_referral($pdo, (int)$acc['user_id']); } catch (\Throwable $e) {} }
                header('Location: ' . $checkout); exit;
            }
        }
    }
}
$cap = saas_generate_captcha();

$site_name = $s['site_name'] ?? 'منشی هوشمند';
$site_url  = rtrim(AICHAT_BASE_URL, '/') . '/';
$logo_url  = $s['site_logo_url'] ?? '';
$favi_url  = $s['site_favicon_url'] ?? '';
$features  = $plan ? (function_exists('ex_plan_feature_list_sorted') ? ex_plan_feature_list_sorted($plan) : saas_plan_feature_list($plan)) : [];
$title     = $plan ? ('پلن ' . $plan['name'] . ' — ' . $site_name) : ('پلن یافت نشد — ' . $site_name);
$desc      = $plan ? mb_substr(trim(($plan['description'] ?? '') . ' ' . implode('، ', array_column(array_slice($features, 0, 6), 'text'))), 0, 160) : '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo site_h($title); ?></title>
<?php if ($desc !== ''): ?><meta name="description" content="<?php echo site_h($desc); ?>"><?php endif; ?>
<?php if ($plan): ?><link rel="canonical" href="<?php echo site_h($site_url . 'plan.php?id=' . (int)$plan['id']); ?>"><?php endif; ?>
<?php if ($favi_url): ?><link rel="icon" href="<?php echo site_h($favi_url); ?>" type="image/png"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--primary:#000922;--accent:#0051d5;--accent-2:#316bf3;--text:#e8eaf6;--text-muted:#9fa8da;--card-bg:rgba(255,255,255,.04);--card-border:rgba(255,255,255,.08)}
body{background:var(--primary);color:var(--text);font-family:'Vazirmatn',sans-serif;line-height:1.8;direction:rtl}
a{color:var(--accent-2);text-decoration:none}
.container{max-width:900px;margin:0 auto;padding:0 20px}
.site-header{background:rgba(0,9,34,.9);border-bottom:1px solid var(--card-border);position:sticky;top:0;z-index:50}
.nav-inner{display:flex;align-items:center;justify-content:space-between;height:64px;gap:16px}
.nav-logo img{height:36px}.nav-logo span{font-size:18px;font-weight:800;color:#fff}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 22px;border-radius:12px;font-size:14px;font-weight:700;border:none;cursor:pointer;font-family:inherit}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent-2)}
.btn-ghost{background:rgba(255,255,255,.07);color:#fff}
.hero{padding:48px 0 24px;text-align:center}
.crumb{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:14px}
.hero h1{font-size:clamp(26px,5vw,42px);font-weight:800;line-height:1.3}
.hero p{color:var(--text-muted);margin-top:10px;font-size:15px}
.price{margin-top:18px;font-size:30px;font-weight:800;color:#fff}.price small{font-size:14px;color:var(--text-muted);font-weight:400}
.box{background:var(--card-bg);border:1px solid var(--card-border);border-radius:18px;padding:24px;margin:24px 0}
.box h2{font-size:18px;margin-bottom:14px}
.feat{list-style:none;display:grid;gap:10px}
.feat li{display:flex;gap:10px;align-items:flex-start;background:rgba(255,255,255,.03);border-radius:12px;padding:12px 14px}
.feat li b{color:#34d399;font-size:16px}
.feat .n{display:block;font-size:12.5px;color:var(--text-muted);margin-top:2px}
.cta{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin:10px 0 40px}
.others{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-bottom:50px}
.others a{padding:7px 14px;border-radius:20px;background:rgba(255,255,255,.06);color:#fff;font-size:13px}
.others a.on{background:var(--accent)}
.buybox{background:linear-gradient(135deg,rgba(0,81,213,.18),rgba(49,107,243,.08));border:1px solid rgba(49,107,243,.35);border-radius:18px;padding:22px;margin:24px auto 40px;max-width:520px;scroll-margin-top:80px}
.buybox h2{font-size:18px;margin-bottom:6px;text-align:center}.buybox p{color:var(--text-muted);font-size:13px;text-align:center;margin-bottom:14px}
.buybox input{width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:11px 14px;color:#fff;font-family:inherit;font-size:14px;margin-bottom:10px;outline:none}
.buybox input:focus{border-color:var(--accent-2)}.buybox .btn{width:100%}
.buybox .err{background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.4);color:#fecaca;border-radius:10px;padding:8px 12px;font-size:13px;margin-bottom:10px}
.buybox .ok{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.4);color:#a7f3d0;border-radius:10px;padding:8px 12px;font-size:13px;margin-bottom:10px}
.buybox .cap{display:flex;gap:8px;align-items:center}.buybox .cap span{white-space:nowrap;background:rgba(255,255,255,.08);border-radius:10px;padding:10px 12px;font-weight:700;margin-bottom:10px;direction:ltr}
.buybox .alt{text-align:center;font-size:12.5px;margin-top:10px;color:var(--text-muted)}
.site-footer{border-top:1px solid var(--card-border);padding:24px;text-align:center;font-size:12px;color:rgba(255,255,255,.3)}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<header class="site-header"><div class="container" style="max-width:1200px"><nav class="nav-inner">
  <a href="index.php" class="nav-logo"><?php if ($logo_url): ?><img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>"><?php else: ?><span><?php echo site_h($site_name); ?></span><?php endif; ?></a>
  <span style="display:flex;gap:8px;align-items:center"><a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary" style="padding:8px 18px;font-size:13px">ثبت‌نام</a><?php echo site_mobile_burger(); ?></span>
</nav></div></header>
<?php echo site_mobile_menu($pdo); ?>
<main class="container">
<?php if (!$plan): ?>
  <div class="hero"><h1>پلن یافت نشد</h1><p>این پلن وجود ندارد یا در حال حاضر فعال نیست.</p><div class="cta" style="margin-top:20px"><a class="btn btn-primary" href="plans.php">مشاهده همه پلن‌ها</a></div></div>
<?php else: ?>
  <div class="hero">
    <div class="crumb"><a href="index.php">خانه</a> › <a href="plans.php">پلن‌ها</a> › <?php if ($pcat): ?><a href="plans.php?c=<?php echo site_h(rawurlencode($pcat['slug'])); ?>"><?php echo site_h($pcat['title']); ?></a> › <?php endif; ?><?php echo site_h($plan['name']); ?></div>
    <h1>پلن <?php echo site_h($plan['name']); ?></h1>
    <?php if (trim((string)$plan['description']) !== ''): ?><p><?php echo site_h($plan['description']); ?></p><?php endif; ?>
    <div class="price"><?php if (function_exists('biz_price_html')): echo biz_price_html($plan, 'color:var(--text-muted)'); else: echo number_format((int)$plan['price_toman']); ?> <small>تومان</small><?php endif; ?>
      <div style="font-size:13px;font-weight:400;color:var(--text-muted)">اشتراک یک‌ساله</div></div>
  </div>
  <div class="box">
    <h2>✨ امکانات این پلن</h2>
    <ul class="feat">
      <?php foreach ($features as $f): ?>
      <li><b>✓</b><span><?php echo site_h($f['text']); ?><?php if (($f['note'] ?? '') !== ''): ?><span class="n"><?php echo site_h($f['note']); ?></span><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="buybox" id="buy">
    <?php $free = (int)$plan['price_toman'] === 0; ?>
    <h2><?php echo $free ? '🚀 شروع رایگان' : '🛒 خرید پلن ' . site_h($plan['name']); ?></h2>
    <?php if ($logged): ?>
      <p>شما وارد حساب خود شده‌اید.</p>
      <a class="btn btn-primary" href="<?php echo site_h($checkout); ?>"><?php echo $free ? 'فعال‌سازی پلن' : 'ادامه و پرداخت'; ?></a>
    <?php elseif (!$sms_ok): ?>
      <p>برای <?php echo $free ? 'شروع' : 'خرید'; ?>، ثبت‌نام کنید.</p>
      <a class="btn btn-primary" href="<?php echo site_h(AICHAT_USER_URL . 'register.php?plan=' . (int)$plan['id']); ?>">ثبت‌نام و <?php echo $free ? 'شروع' : 'خرید'; ?></a>
      <div class="alt"><a href="<?php echo site_h(AICHAT_USER_URL . 'login.php?next=' . urlencode('../payment/checkout.php?type=plan&plan_id=' . (int)$plan['id'])); ?>">قبلاً ثبت‌نام کرده‌ام</a></div>
    <?php elseif ($qs): ?>
      <p>کد ۵ رقمی پیامک‌شده به <b dir="ltr"><?php echo site_h($qs['mobile']); ?></b> را وارد کنید.</p>
      <?php if ($qs_err): ?><div class="err"><?php echo site_h($qs_err); ?></div><?php elseif ($qs_info): ?><div class="ok"><?php echo site_h($qs_info); ?></div><?php endif; ?>
      <form method="post" action="plan.php?id=<?php echo (int)$plan['id']; ?>#buy">
        <input type="hidden" name="act" value="qs_verify">
        <input type="text" name="code" inputmode="numeric" maxlength="5" dir="ltr" placeholder="کد تأیید" autocomplete="one-time-code" required autofocus>
        <button class="btn btn-primary"><?php echo $free ? 'تأیید و شروع' : 'تأیید و رفتن به پرداخت'; ?></button>
      </form>
      <form method="post" action="plan.php?id=<?php echo (int)$plan['id']; ?>#buy" class="alt"><input type="hidden" name="act" value="qs_change"><button style="background:none;border:none;color:var(--accent-2);cursor:pointer;font-family:inherit">تغییر شماره</button></form>
    <?php else: ?>
      <p>فقط نام و شماره موبایل؛ با کد پیامکی حساب شما ساخته می‌شود و مستقیم به پرداخت همین پلن می‌روید. (اگر قبلاً حساب دارید، با همین شماره وارد می‌شوید.)</p>
      <?php if ($qs_err): ?><div class="err"><?php echo site_h($qs_err); ?></div><?php endif; ?>
      <form method="post" action="plan.php?id=<?php echo (int)$plan['id']; ?>#buy">
        <input type="hidden" name="act" value="qs_send">
        <input type="text" name="name" maxlength="100" placeholder="نام و نام خانوادگی" required value="<?php echo site_h($_POST['name'] ?? ''); ?>">
        <input type="tel" name="mobile" maxlength="14" dir="ltr" placeholder="09121234567" required value="<?php echo site_h($_POST['mobile'] ?? ''); ?>">
        <div class="cap"><span><?php echo site_h($cap['question']); ?> = ؟</span><input type="text" name="captcha_answer" inputmode="numeric" maxlength="4" placeholder="پاسخ" required dir="ltr"></div>
        <input type="hidden" name="captcha_token" value="<?php echo site_h($cap['token']); ?>">
        <button class="btn btn-primary">📩 دریافت کد تأیید</button>
      </form>
      <div class="alt"><a href="<?php echo site_h(AICHAT_USER_URL . 'login.php?next=' . urlencode('../payment/checkout.php?type=plan&plan_id=' . (int)$plan['id'])); ?>">ورود با ایمیل/رمز</a></div>
    <?php endif; ?>
  </div>
  <?php if (count($all) > 1): ?>
  <div class="others"><?php foreach ($all as $p): ?><a href="plan.php?id=<?php echo (int)$p['id']; ?>" class="<?php echo (int)$p['id'] === (int)$plan['id'] ? 'on' : ''; ?>"><?php echo site_h($p['name']); ?></a><?php endforeach; ?></div>
  <?php endif; ?>
<?php endif; ?>
</main>
<footer class="site-footer"><?php echo site_h($s['footer_text'] ?? '© ' . date('Y') . ' ' . $site_name . '. کلیه حقوق محفوظ.'); ?></footer>
<?php if (function_exists('ex_popups_for')) { try { echo ex_popup_script(ex_popups_for($pdo, 'site'), rtrim(AICHAT_BASE_URL, '/') . '/pp.php'); } catch (\Throwable $e) {} } ?>
</body>
</html>
