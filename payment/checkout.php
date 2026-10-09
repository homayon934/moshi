<?php
/**
 * پرداخت:
 *  plan   — خرید / تمدید اشتراک یک‌ساله (نقدی؛ تمدید زودهنگام با تخفیف) + هزینه راه‌اندازی اختیاری
 *  inst   — پرداخت اقساط باقی‌مانده خریدهای قسطی قدیمی
 *  credit — شارژ اعتبار (تومانی)      sms — خرید بسته پیامک
 *  setup  — درخواست راه‌اندازی برای پلن فعلی
 *  domain — هزینه راه‌اندازی زیردامنه اختصاصی چت‌بات
 */
require_once dirname(__DIR__) . '/includes/sec_lib.php'; sec_boot('public');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';

$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}

if (empty($_SESSION['saas_user_id'])) {
    header('Location: ../user/login.php'); exit;
}
$user_id = (int)$_SESSION['saas_user_id'];
$user = saas_get_user($pdo, $user_id);
if (!$user) { header('Location: ../user/login.php'); exit; }
// همکار: فقط با دسترسی «اعتبار و پرداخت» (زیردامنه ربات: دسترسی مدیریت چت‌بات)
if (!empty($_SESSION['saas_team_id'])) {
    $GLOBALS['current_team'] = biz_team_get($pdo, (int)$_SESSION['saas_team_id']);
    $ok_perm = saas_can('billing') || (($_GET['type'] ?? '') === 'domain' && saas_can('bots_manage'));
    if (!$GLOBALS['current_team'] || !$ok_perm) { header('Location: ../user/index.php?denied=1'); exit; }
}
$cfg = saas_get_api_config($pdo);
$bs  = biz_settings($pdo);

if (!function_exists('saas_h')) { function saas_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$type = in_array($_GET['type'] ?? '', ['plan', 'credit', 'sms', 'inst', 'setup', 'domain'], true) ? $_GET['type'] : 'credit';
$plan = null;
$error = '';
$base_amount = 0;
$credit_tokens = 0;
$sms_count = 0;
$fixed = null;          // خریدهای بدون کد تخفیف (قسط، راه‌اندازی، دامنه): ['amount','desc','meta','rows']
$cost1k = max(0.01, (float)$cfg['cost_per_1k_tokens']);
$sms_price = max(1, (int)$bs['sms_price_toman']);
$sms_min = max(1, (int)$bs['sms_min_purchase']);
$active_order = biz_active_inst_order($pdo, $user_id);

if ($type === 'plan') {
    $plan = saas_get_plan($pdo, (int)($_GET['plan_id'] ?? 0));
    // پلن دلخواهی که خود کاربر ساخته (غیرفعال برای عموم) هم قابل خرید است
    $own_custom = $plan && !empty($plan['is_custom']) && (int)($plan['owner_user_id'] ?? 0) === (int)$user_id;
    if ($plan && !empty($plan['is_custom']) && !$own_custom) $plan = null;
    if (!$plan || (empty($plan['is_active']) && !$own_custom && (int)$user['plan_id'] !== (int)$plan['id'])) { $error = 'پلن یافت نشد.'; $plan = null; }
    elseif ((int)$user['plan_id'] === (int)$plan['id'] && (int)$plan['price_toman'] <= 0 && ($fs = biz_subscription($user, $pdo))['state'] === 'active' && $fs['days_left'] > 30) { $error = 'پلن رایگان در ۳۰ روز پایانی اشتراک قابل تمدید است.'; }
    elseif ($active_order) { $error = 'تا تسویه اقساط پلن فعلی، امکان خرید پلن دیگر نیست. می‌توانید همه اقساط باقی‌مانده را یکجا پرداخت کنید.'; }
    else { $base_amount = (int)$plan['price_toman']; $credit_tokens = (int)$plan['credit_tokens']; }
} elseif ($type === 'sms') {
    $sms_count = (int)($_POST['sms_count'] ?? ($_GET['count'] ?? $sms_min));
    $sms_count = max($sms_min, min(1000000, $sms_count));
    $base_amount = $sms_count * $sms_price;
} elseif ($type === 'credit') {
    $amount = (int)str_replace(',', '', (string)($_POST['custom_amount'] ?? ($_GET['amount'] ?? 100000)));
    $base_amount = max(10000, min(100000000, $amount));
    $credit_tokens = $base_amount;   // اعتبار تومانی: هر تومان پرداخت = یک تومان اعتبار
} elseif ($type === 'inst') {
    $all = !empty($_GET['all']);
    $s = $pdo->prepare("SELECT i.*, p.name AS plan_name FROM saas_installments i JOIN saas_inst_orders o ON o.id=i.order_id LEFT JOIN saas_plans p ON p.id=o.plan_id
        WHERE i.user_id=? AND i.status='unpaid' AND o.status='active' ORDER BY i.due_at ASC, i.seq ASC");
    $s->execute([$user_id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) $error = 'قسط پرداخت‌نشده‌ای ندارید.';
    else {
        if (!$all) $rows = [$rows[0]];
        $sum = array_sum(array_map(fn($r) => (int)$r['amount'], $rows));
        $fixed = ['amount' => $sum, 'rows' => $rows, 'meta' => ['inst_ids' => array_map(fn($r) => (int)$r['id'], $rows)],
                  'desc' => $all ? 'پرداخت یکجای ' . count($rows) . ' قسط باقی‌مانده' : 'قسط ' . (int)$rows[0]['seq'] . ' — پلن ' . ($rows[0]['plan_name'] ?? '')];
    }
} elseif ($type === 'setup') {
    $cur = !empty($user['plan_id']) ? saas_get_plan($pdo, (int)$user['plan_id']) : null;
    if (!$cur || (int)($cur['setup_fee'] ?? 0) <= 0) $error = 'برای پلن شما خدمات راه‌اندازی تعریف نشده است.';
    else $fixed = ['amount' => (int)$cur['setup_fee'], 'rows' => [], 'meta' => ['detail' => 'راه‌اندازی پنل — پلن ' . $cur['name']], 'desc' => 'راه‌اندازی پنل توسط تیم ما'];
} elseif ($type === 'domain') {
    require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
    $bot = hd_get_bot($pdo, (int)($_GET['bot'] ?? 0), $user_id);
    $dom = strtolower(trim((string)($_GET['domain'] ?? '')));
    $fee = (int)$bs['domain_fee'];
    if (!$bot) $error = 'ربات یافت نشد.';
    elseif (!preg_match('/^([a-z0-9-]+\.){2,}[a-z]{2,}$/', $dom)) $error = 'زیردامنه معتبر نیست.';
    elseif ($fee <= 0) $error = 'راه‌اندازی زیردامنه رایگان است؛ از صفحه ربات ثبت کنید.';
    elseif (!empty($bot['domain_paid'])) $error = 'هزینه زیردامنه این ربات قبلاً پرداخت شده است؛ از صفحه ربات ثبت کنید.';
    else {
        $du = $pdo->prepare("SELECT COUNT(*) FROM hd_bots WHERE custom_domain=? AND id<>?");
        $du->execute([$dom, (int)$bot['id']]);
        if ((int)$du->fetchColumn() > 0) $error = 'این دامنه قبلاً برای ربات دیگری ثبت شده است.';
        else $fixed = ['amount' => $fee, 'rows' => [], 'meta' => ['bot_id' => (int)$bot['id'], 'domain' => $dom], 'desc' => 'راه‌اندازی زیردامنه ' . $dom . ' برای «' . $bot['name'] . '»'];
    }
}

$fatal = $error !== '';   // خطایی که اجازه ادامه خرید نمی‌دهد

// کد تخفیف: واردشده توسط کاربر، یا کد معرف (اگر از لینک همکار آمده باشد) — فقط برای پلن/توکن/پیامک
$code = '';
$quote = ['original' => 0, 'final' => 0, 'auto_off' => 0, 'auto_percent' => 0, 'code_off' => 0, 'code_id' => 0, 'code' => '', 'code_error' => ''];
if (in_array($type, ['plan', 'credit', 'sms'], true) && !$error) {
    $code = strtoupper(trim((string)($_POST['discount_code'] ?? ($_COOKIE['aff_ref'] ?? ''))));
    $code_from_cookie = !isset($_POST['discount_code']) && $code !== '';
    $quote = biz_quote($pdo, $user_id, $type, $base_amount, $plan, $code);
    if ($code_from_cookie && $quote['code_error'] !== '') { $code = ''; $quote = biz_quote($pdo, $user_id, $type, $base_amount, $plan, ''); }
}

$is_free_plan = ($type === 'plan' && $plan && (int)$plan['price_toman'] <= 0);
$n_inst = 1;              // پرداخت فقط نقدی
$pay_mode = 'cash';
$is_renew = ($type === 'plan' && $plan && (int)$user['plan_id'] === (int)$plan['id']);
$renew_offer = $is_renew ? biz_renew_offer($pdo, $user, (int)$plan['id']) : null;
$new_exp = ($type === 'plan' && $plan) ? biz_new_expiry($user, (int)$plan['id']) : null;
$setup_fee = $plan ? (int)($plan['setup_fee'] ?? 0) : 0;
$want_setup = $setup_fee > 0 && !empty($_POST['want_setup']);
$inst_list = ($type === 'plan' && $plan && $pay_mode === 'inst') ? biz_split_installments((int)$quote['final'], $n_inst) : [];

// مبلغ همین پرداخت
if ($fixed) $pay_now = (int)$fixed['amount'];
elseif ($type === 'plan') $pay_now = ($pay_mode === 'inst' ? ($inst_list[0] ?? 0) : (int)$quote['final']) + ($want_setup ? $setup_fee : 0);
else $pay_now = (int)$quote['final'];

// ---------------------------------------------------------------------
// پرداخت
// ---------------------------------------------------------------------
$card_on = function_exists('c2c_site_on') && c2c_site_on($pdo);   // کارت به کارت (نسخه ۵۶)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_card']) && $card_on) $_POST['pay'] = 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay']) && !$error) {
    if ($quote['code_error'] !== '' && $code !== '') {
        $error = $quote['code_error'];
    } elseif ($is_free_plan && !$want_setup) {
        $res = saas_activate_plan($pdo, $user_id, (int)$plan['id'], 'اعتبار اولیه پلن ' . $plan['name']);
        $m = 'پلن «' . $plan['name'] . '» تا ' . biz_jdate($new_exp) . ' فعال شد.' . ($res['tokens'] > 0 ? ' ' . number_format($res['tokens']) . ' تومان به اعتبار شما اضافه شد.' : '');
        biz_notify($pdo, $user_id, '✅ پلن شما فعال شد', $m, 'success', 'billing.php');
        header('Location: ../user/billing.php?msg=' . urlencode($m)); exit;
    } else {
        $meta = null; $tokens_now = $credit_tokens;
        if ($fixed) {
            $desc = $fixed['desc']; $meta = $fixed['meta']; $tokens_now = 0;
            $q = ['original' => $pay_now, 'final' => $pay_now, 'code_id' => 0];
        } else {
            $desc = $type === 'plan' ? ('خرید پلن ' . $plan['name'] . ($pay_mode === 'inst' ? ' (قسط ۱ از ' . $n_inst . ')' : ''))
                  : ($type === 'sms' ? 'خرید ' . number_format($sms_count) . ' پیامک' : 'شارژ اعتبار');
            if ($type === 'plan' && $pay_mode === 'inst') {
                $meta = ['inst' => $n_inst, 'list' => $inst_list, 'days' => max(7, (int)($plan['installment_days'] ?? 30))];
                $tokens_now = (int)floor($credit_tokens / $n_inst);
            }
            $q = $quote;
        }
        $pid = biz_create_payment($pdo, $user_id, $type, $q, $plan['id'] ?? 0, $tokens_now, $sms_count, $desc, $meta, $want_setup ? $setup_fee : 0, $pay_now);
        if ($pay_now <= 0) {
            $r = biz_complete_payment($pdo, $pid, 'FREE');
            header('Location: ../user/billing.php?msg=' . urlencode($r['message'])); exit;
        }
        // کارت به کارت: پرداخت در انتظار → صفحه شماره کارت و ارسال فیش
        if (!empty($_POST['pay_card']) && $card_on) { header('Location: card.php?pid=' . $pid); exit; }
        $r = biz_zp_request($pdo, $pid, $pay_now, $desc, (string)$user['phone'], (string)$user['email']);
        if ($r['ok']) { header('Location: ' . $r['url']); exit; }
        $pdo->prepare("UPDATE saas_payments SET status='failed' WHERE id=?")->execute([$pid]);
        $error = $r['error'];
    }
}

$titles = ['plan' => $is_renew ? 'تمدید اشتراک' : 'خرید پلن', 'sms' => 'خرید بسته پیامک', 'credit' => 'شارژ اعتبار', 'inst' => 'پرداخت قسط', 'setup' => 'راه‌اندازی پنل', 'domain' => 'زیردامنه اختصاصی'];
$title = $titles[$type];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo saas_h($title); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Vazirmatn,'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,#1e1b4b,#312e81);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;direction:rtl}
.box{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:480px}
h1{font-size:20px;color:#1e1b4b;margin-bottom:4px}
.sub{font-size:13px;color:#64748b;margin-bottom:20px}
.detail-row{display:flex;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:14px}
.detail-row b{color:#1e293b;text-align:left}
.detail-row.off,.detail-row b.off{color:#059669}
.total{background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;margin:16px 0;font-size:16px;font-weight:700;color:#7c3aed}
.btn{width:100%;padding:13px;background:#7c3aed;color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:15px;font-weight:700;font-family:inherit;margin-top:6px}
.btn:hover{background:#6d28d9}
.btn2{padding:10px 14px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;border-radius:9px;cursor:pointer;font-family:inherit;font-size:13px;white-space:nowrap}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;line-height:1.9}
.ok{padding:8px 12px;border-radius:8px;margin:8px 0;font-size:12.5px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.back{text-align:center;margin-top:16px;font-size:13px;color:#64748b}
.back a{color:#7c3aed}
input[type=text],input[type=number]{width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;direction:ltr;text-align:center}
input:focus{outline:none;border-color:#7c3aed}
label{display:block;font-size:13px;font-weight:600;color:#374151;margin:12px 0 6px}
.chips{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:8px}
.chips button{padding:7px 4px;border:1px solid #e2e8f0;border-radius:7px;cursor:pointer;font-size:12px;background:#fff;font-family:inherit}
.chips button:hover{border-color:#7c3aed;color:#7c3aed}
.hint{font-size:12px;color:#7c3aed;text-align:center;margin-top:6px}
.opt{display:flex;gap:10px;align-items:flex-start;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-top:8px;cursor:pointer;font-weight:500;font-size:13.5px;line-height:1.8}
.opt input{margin-top:5px;width:16px;height:16px;flex-shrink:0}
.opt.on{border-color:#7c3aed;background:#faf5ff}
.inst-list{font-size:12.5px;color:#475569;margin-top:6px;line-height:2}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<div class="box">
    <h1>💳 <?php echo saas_h($title); ?></h1>
    <p class="sub">پرداخت امن با درگاه زرین‌پال</p>

    <?php if ($error): ?><div class="alert"><?php echo saas_h($error); ?>
        <?php if ($active_order && $type === 'plan'): ?><br><a href="checkout.php?type=inst&all=1" style="color:#7c3aed">پرداخت یکجای اقساط باقی‌مانده ←</a><?php endif; ?></div><?php endif; ?>

    <?php if ($fatal): ?>
        <div class="back"><a href="../user/billing.php">← بازگشت به اعتبار و پرداخت</a></div></div></body></html><?php exit; ?>
    <?php endif; ?>

    <form method="post" id="f">
        <?php if ($type === 'credit'): ?>
            <label>مبلغ شارژ (تومان)</label>
            <input type="text" name="custom_amount" id="amt" value="<?php echo (int)$base_amount; ?>" inputmode="numeric">
            <div class="chips">
                <?php foreach ([50000, 100000, 200000, 500000] as $a): ?>
                <button type="button" onclick="setv('amt',<?php echo $a; ?>)"><?php echo number_format($a); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="hint">همین مبلغ به اعتبار تومانی حساب شما اضافه می‌شود.</div>
        <?php elseif ($type === 'sms'): ?>
            <label>تعداد پیامک (حداقل <?php echo number_format($sms_min); ?>)</label>
            <input type="number" name="sms_count" id="cnt" min="<?php echo $sms_min; ?>" step="1" value="<?php echo (int)$sms_count; ?>">
            <div class="chips">
                <?php foreach ([100, 500, 1000, 5000] as $c): if ($c < $sms_min) continue; ?>
                <button type="button" onclick="setv('cnt',<?php echo $c; ?>)"><?php echo number_format($c); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="hint">هر پیامک <?php echo number_format($sms_price); ?> تومان</div>
        <?php endif; ?>

        <div style="margin-top:14px">
        <?php if ($fixed): ?>
            <div class="detail-row"><span>شرح:</span><b><?php echo saas_h($fixed['desc']); ?></b></div>
            <?php foreach ($fixed['rows'] as $r): ?>
            <div class="detail-row"><span>قسط <?php echo (int)$r['seq']; ?> — سررسید <?php echo biz_jdate($r['due_at']); ?></span><b><?php echo number_format((int)$r['amount']); ?> تومان</b></div>
            <?php endforeach; ?>
        <?php elseif ($type === 'plan'): ?>
            <div class="detail-row"><span>پلن:</span><b><?php echo saas_h($plan['name']); ?></b></div>
            <div class="detail-row"><span>مدت اشتراک:</span><b>یک سال<?php echo $is_renew ? ' (تمدید)' : ''; ?></b></div>
            <div class="detail-row"><span>اعتبار تا:</span><b><?php echo biz_jdate($new_exp); ?></b></div>
            <?php if ($is_renew && $renew_offer && $renew_offer['percent'] > 0): ?>
            <div class="ok">🎁 تمدید زودهنگام: <b><?php echo (int)$renew_offer['percent']; ?>٪ تخفیف</b> تا <?php echo biz_jdate($renew_offer['until']); ?><?php if ($renew_offer['next']): ?> — پس از آن <?php echo (int)$renew_offer['next'][1]; ?>٪<?php else: ?> — پس از آن بدون تخفیف<?php endif; ?>. روزهای باقی‌مانده اشتراک فعلی هم حفظ می‌شود.</div>
            <?php endif; ?>
            <?php if ((int)$plan['credit_tokens'] > 0): ?><div class="detail-row"><span>اعتبار اولیه:</span><b><?php echo number_format($plan['credit_tokens']); ?> تومان</b></div><?php endif; ?>
            <?php if ((int)($plan['free_sms'] ?? 0) > 0): ?><div class="detail-row"><span>پیامک رایگان:</span><b><?php echo number_format($plan['free_sms']); ?> پیامک</b></div><?php endif; ?>
        <?php elseif ($type === 'sms'): ?>
            <div class="detail-row"><span>تعداد پیامک:</span><b><?php echo number_format($sms_count); ?></b></div>
        <?php else: ?>
            <div class="detail-row"><span>اعتبار دریافتی:</span><b><?php echo number_format($credit_tokens); ?> تومان</b></div>
        <?php endif; ?>

        <?php if (!$fixed && !$is_free_plan): ?>
            <div class="detail-row"><span>قیمت:</span><b><?php echo number_format($quote['original']); ?> تومان</b></div>
            <?php if ($quote['auto_off'] > 0): ?><div class="detail-row off"><span><?php echo !empty($quote['renew_percent']) ? '🎁 تخفیف تمدید زودهنگام' : '🔥 تخفیف ویژه'; ?> (<?php echo (int)$quote['auto_percent']; ?>٪):</span><b class="off">− <?php echo number_format($quote['auto_off']); ?></b></div><?php endif; ?>
            <?php if ($quote['code_off'] > 0): ?><div class="detail-row off"><span>🎟️ کد تخفیف <?php echo saas_h($quote['code']); ?>:</span><b class="off">− <?php echo number_format($quote['code_off']); ?></b></div><?php endif; ?>
            <?php if ($type === 'plan' && ($quote['auto_off'] > 0 || $quote['code_off'] > 0)): ?><div class="detail-row"><span>قیمت پلن پس از تخفیف:</span><b><?php echo number_format($quote['final']); ?> تومان</b></div><?php endif; ?>

            <label>کد تخفیف (اختیاری)</label>
            <div style="display:flex;gap:6px">
                <input type="text" name="discount_code" value="<?php echo saas_h($code); ?>" style="text-transform:uppercase" placeholder="کد تخفیف یا کد معرف">
                <button type="submit" name="apply" value="1" class="btn2">اعمال</button>
            </div>
            <?php if ($quote['code_error'] !== '' && $code !== ''): ?><div class="alert" style="margin:8px 0 0"><?php echo saas_h($quote['code_error']); ?></div>
            <?php elseif ($quote['code_off'] > 0): ?><div class="ok">✓ کد تخفیف اعمال شد.</div><?php endif; ?>
        <?php endif; ?>

        <?php if ($type === 'plan' && $setup_fee > 0): ?>
            <label class="opt <?php echo $want_setup ? 'on' : ''; ?>" style="margin-top:12px"><input type="checkbox" name="want_setup" value="1" <?php echo $want_setup ? 'checked' : ''; ?> onchange="this.form.submit()">
                <span>🛠 راه‌اندازی کامل پنل توسط تیم ما (اختیاری) — <?php echo number_format($setup_fee); ?> تومان<br>
                <small style="color:#64748b">تنظیم ویجت، پایگاه دانش و نصب روی سایت شما انجام می‌شود.</small></span></label>
        <?php endif; ?>
        </div>

        <div class="total"><span><?php echo $type === 'plan' && $pay_mode === 'inst' ? 'مبلغ قابل پرداخت اکنون:' : 'مبلغ پرداختی:'; ?></span><span><?php echo $pay_now > 0 ? number_format($pay_now) . ' تومان' : 'رایگان'; ?></span></div>
        <button type="submit" name="pay" value="1" class="btn"><?php echo $pay_now > 0 ? 'پرداخت با زرین‌پال 🔒' : 'فعال‌سازی ✓'; ?></button>
        <?php if ($card_on && $pay_now > 0): ?>
        <button type="submit" name="pay_card" value="1" class="btn" style="background:#fff;color:#1e3a8a;border:1.5px solid #93c5fd">💳 کارت به کارت و ارسال فیش</button>
        <?php endif; ?>
    </form>
    <div class="back"><a href="../user/<?php echo $type === 'domain' ? 'bots.php' : 'billing.php'; ?>">← بازگشت</a></div>
</div>
<script>
function setv(id, v){ var e = document.getElementById(id); e.value = v; document.getElementById('f').submit(); }
['amt','cnt'].forEach(function(id){
  var e = document.getElementById(id); if (!e) return;
  e.addEventListener('change', function(){ document.getElementById('f').submit(); });
});
</script>
</body>
</html>
