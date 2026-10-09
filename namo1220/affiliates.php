<?php
/**
 * همکاری در فروش: تنظیمات، همکاران، پورسانت‌ها و تسویه
 */
require_once __DIR__ . '/_bootstrap.php';

$msg = '';
$now = date('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: affiliates.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $act = $_POST['act'] ?? '';
    $aid = (int)($_POST['aff_id'] ?? 0);
    $back = 'affiliates.php';
    if ($act === 'settings') {
        biz_set($pdo, 'aff_enabled', !empty($_POST['aff_enabled']) ? 1 : 0);
        biz_set($pdo, 'aff_default_percent', max(0, min(90, (float)($_POST['aff_default_percent'] ?? 10))));
        biz_set($pdo, 'aff_customer_discount', max(0, min(90, (int)($_POST['aff_customer_discount'] ?? 0))));
        biz_set($pdo, 'aff_cookie_days', max(1, min(365, (int)($_POST['aff_cookie_days'] ?? 60))));
        biz_set($pdo, 'aff_auto_approve', !empty($_POST['aff_auto_approve']) ? 1 : 0);
        biz_set($pdo, 'aff_min_payout', max(0, (int)str_replace(',', '', (string)($_POST['aff_min_payout'] ?? 0))));
        biz_set($pdo, 'aff_terms', mb_substr(trim($_POST['aff_terms'] ?? ''), 0, 3000));
        $msg = 'ok:تنظیمات همکاری در فروش ذخیره شد.';
    } elseif ($act === 'add') {
        $r = biz_create_affiliate($pdo, trim($_POST['name'] ?? ''), $_POST['mobile'] ?? '', 'active');
        if (!$r['ok']) $msg = 'error:' . $r['error'];
        else {
            if (isset($_POST['percent']) && $_POST['percent'] !== '') $pdo->prepare("UPDATE saas_affiliates SET commission_percent=? WHERE id=?")->execute([max(0, min(90, (float)$_POST['percent'])), $r['id']]);
            $msg = $r['existing'] ? 'error:این شماره قبلاً به‌عنوان همکار ثبت شده است.' : 'ok:همکار اضافه شد.';
            $back = 'affiliates.php?view=' . $r['id'];
        }
    } elseif ($act === 'update' && $aid) {
        $status = in_array($_POST['status'] ?? '', ['pending', 'active', 'blocked'], true) ? $_POST['status'] : 'pending';
        $pdo->prepare("UPDATE saas_affiliates SET name=?, commission_percent=?, status=?, note=? WHERE id=?")
            ->execute([mb_substr(trim($_POST['name'] ?? ''), 0, 120), max(0, min(90, (float)($_POST['percent'] ?? 10))), $status, mb_substr(trim($_POST['note'] ?? ''), 0, 300), $aid]);
        if ($status === 'active') biz_affiliate_ensure_code($pdo, $aid);
        // غیرفعال/فعال‌سازی کد تخفیف همکار همراه با وضعیت او
        $a = biz_affiliate($pdo, $aid);
        if ($a) $pdo->prepare("UPDATE saas_discount_codes SET is_active=? WHERE code=? AND affiliate_id=?")->execute([$status === 'active' ? 1 : 0, $a['ref_code'], $aid]);
        $msg = 'ok:اطلاعات همکار ذخیره شد.';
        $back = 'affiliates.php?view=' . $aid;
    } elseif ($act === 'payout' && $aid) {
        $amount = (int)str_replace(',', '', (string)($_POST['amount'] ?? '0'));
        $st = biz_affiliate_stats($pdo, $aid);
        if ($amount <= 0) $msg = 'error:مبلغ معتبر نیست.';
        elseif ($amount > $st['balance']) $msg = 'error:مبلغ بیشتر از مانده حساب همکار (' . number_format($st['balance']) . ' تومان) است.';
        else {
            $pdo->prepare("INSERT INTO saas_aff_payouts (affiliate_id, amount, note, created_at) VALUES(?,?,?,?)")
                ->execute([$aid, $amount, mb_substr(trim($_POST['note'] ?? ''), 0, 255), $now]);
            $msg = 'ok:تسویه ' . number_format($amount) . ' تومانی ثبت شد.';
        }
        $back = 'affiliates.php?view=' . $aid;
    } elseif ($act === 'commission_status' && $aid) {
        $new = ($_POST['status'] ?? '') === 'cancelled' ? 'cancelled' : 'approved';
        $pdo->prepare("UPDATE saas_commissions SET status=? WHERE id=? AND affiliate_id=?")->execute([$new, (int)($_POST['commission_id'] ?? 0), $aid]);
        $msg = 'ok:وضعیت پورسانت تغییر کرد.';
        $back = 'affiliates.php?view=' . $aid;
    }
    header('Location: ' . $back . (strpos($back, '?') ? '&' : '?') . 'msg=' . urlencode($msg)); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];

$bs = biz_settings($pdo, true);
$aff_url = rtrim(AICHAT_BASE_URL, '/') . '/affiliate/';
$view = null;
if (!empty($_GET['view'])) $view = biz_affiliate($pdo, (int)$_GET['view']);

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🤝 همکاری در فروش</h2>';
$st_label = ['pending' => ['در انتظار تأیید', '#d97706'], 'active' => ['فعال', '#059669'], 'blocked' => ['مسدود', '#dc2626']];
?>
<?php if ($msg): ?>
<div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>

<?php if ($view): $vs = biz_affiliate_stats($pdo, (int)$view['id']); ?>
<!-- ===================== جزئیات همکار ===================== -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="font-size:15px">👤 <?php echo admin_h($view['name'] ?: $view['mobile']); ?> <span style="font-size:12px;color:<?php echo $st_label[$view['status']][1] ?? '#64748b'; ?>">(<?php echo $st_label[$view['status']][0] ?? $view['status']; ?>)</span></h3>
        <a href="affiliates.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">← بازگشت</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px">
        <?php foreach ([['کلیک لینک', number_format((int)$view['clicks'])], ['ثبت‌نام', number_format($vs['signups'])], ['خرید', number_format($vs['sales'])], ['مبلغ فروش', number_format($vs['sales_sum'])], ['پورسانت کل', number_format($vs['earned'])], ['تسویه‌شده', number_format($vs['paid'])], ['مانده', number_format($vs['balance'])]] as $c): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;text-align:center"><div style="font-size:11px;color:#64748b"><?php echo $c[0]; ?></div><div style="font-size:16px;font-weight:800"><?php echo $c[1]; ?></div></div>
        <?php endforeach; ?>
    </div>
    <table style="width:100%;font-size:13px;margin-bottom:12px">
        <tr><td style="color:#64748b;padding:4px 0;width:160px">موبایل:</td><td dir="ltr" style="text-align:right"><?php echo admin_h($view['mobile']); ?></td></tr>
        <tr><td style="color:#64748b;padding:4px 0">کد معرف / کد تخفیف:</td><td><b dir="ltr"><?php echo admin_h($view['ref_code']); ?></b></td></tr>
        <tr><td style="color:#64748b;padding:4px 0">لینک معرفی:</td><td dir="ltr" style="text-align:right;font-size:12px"><?php echo admin_h(rtrim(AICHAT_BASE_URL, '/') . '/?ref=' . $view['ref_code']); ?></td></tr>
        <tr><td style="color:#64748b;padding:4px 0">شبا / کارت:</td><td dir="ltr" style="text-align:right"><?php echo admin_h(trim($view['sheba'] . ' ' . $view['card_no']) ?: '—'); ?> <?php echo $view['account_owner'] !== '' ? '(' . admin_h($view['account_owner']) . ')' : ''; ?></td></tr>
        <tr><td style="color:#64748b;padding:4px 0">عضویت:</td><td><?php echo biz_jdate($view['created_at']); ?> — آخرین ورود: <?php echo biz_jdate($view['last_login'], true); ?></td></tr>
    </table>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="update"><input type="hidden" name="aff_id" value="<?php echo (int)$view['id']; ?>">
        <div class="row2">
            <div><label>نام</label><input type="text" name="name" maxlength="120" value="<?php echo admin_h($view['name']); ?>"></div>
            <div><label>درصد پورسانت (از مبلغ پرداختی مشتری)</label><input type="number" step="0.5" min="0" max="90" name="percent" value="<?php echo (float)$view['commission_percent']; ?>"></div>
        </div>
        <div class="row2">
            <div><label>وضعیت</label><select name="status"><?php foreach ($st_label as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $view['status'] === $k ? 'selected' : ''; ?>><?php echo $l[0]; ?></option><?php endforeach; ?></select></div>
            <div><label>یادداشت داخلی</label><input type="text" name="note" maxlength="300" value="<?php echo admin_h($view['note']); ?>"></div>
        </div>
        <button class="btn btn-primary btn-sm" style="margin-top:12px">💾 ذخیره</button>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">💸 ثبت تسویه (واریز به همکار)</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="payout"><input type="hidden" name="aff_id" value="<?php echo (int)$view['id']; ?>">
        <div><label>مبلغ (تومان)</label><input type="number" name="amount" min="1" value="<?php echo max(0, $vs['balance']); ?>" style="width:180px !important"></div>
        <div style="flex:1;min-width:200px"><label>توضیح / شماره پیگیری واریز</label><input type="text" name="note" maxlength="255"></div>
        <button class="btn btn-primary btn-sm" onclick="return confirm('تسویه ثبت شود؟');">ثبت تسویه</button>
    </form>
    <?php $po = $pdo->prepare("SELECT * FROM saas_aff_payouts WHERE affiliate_id=? ORDER BY id DESC LIMIT 50"); $po->execute([(int)$view['id']]); $payouts = $po->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
    <?php if ($payouts): ?>
    <table style="margin-top:12px"><thead><tr><th>تاریخ</th><th>مبلغ</th><th>توضیح</th></tr></thead><tbody>
        <?php foreach ($payouts as $p): ?><tr><td><?php echo biz_jdate($p['created_at'], true); ?></td><td><?php echo number_format((int)$p['amount']); ?></td><td><?php echo admin_h($p['note']); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">🧾 پورسانت‌ها</h3>
    <?php
    $cm = $pdo->prepare("SELECT c.*, u.full_name, u.email FROM saas_commissions c LEFT JOIN saas_users u ON u.id=c.user_id WHERE c.affiliate_id=? ORDER BY c.id DESC LIMIT 200");
    $cm->execute([(int)$view['id']]);
    $coms = $cm->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $tlabel = ['plan' => 'پلن', 'credit' => 'شارژ اعتبار', 'sms' => 'پیامک'];
    ?>
    <?php if (!$coms): ?><p class="muted" style="text-align:center;padding:12px">هنوز پورسانتی ثبت نشده است.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>تاریخ</th><th>مشتری</th><th>نوع خرید</th><th>مبلغ خرید</th><th>درصد</th><th>پورسانت</th><th>از طریق</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($coms as $c): ?>
        <tr style="<?php echo $c['status'] === 'cancelled' ? 'opacity:.5' : ''; ?>">
            <td style="font-size:12px"><?php echo biz_jdate($c['created_at'], true); ?></td>
            <td style="font-size:12px"><?php echo admin_h($c['full_name'] ?: '#' . $c['user_id']); ?><br><small><?php echo admin_h($c['email'] ?? ''); ?></small></td>
            <td><?php echo $tlabel[$c['payment_type']] ?? $c['payment_type']; ?></td>
            <td><?php echo number_format((int)$c['base_amount']); ?></td>
            <td><?php echo (float)$c['percent']; ?>٪</td>
            <td><b><?php echo number_format((int)$c['amount']); ?></b></td>
            <td style="font-size:12px"><?php echo $c['source'] === 'code' ? 'کد تخفیف' : 'لینک معرفی'; ?></td>
            <td><?php echo $c['status'] === 'cancelled' ? '<span class="badge-off">لغو شده</span>' : '<span class="badge-ok">تأیید</span>'; ?></td>
            <td><form method="post"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="commission_status"><input type="hidden" name="aff_id" value="<?php echo (int)$view['id']; ?>"><input type="hidden" name="commission_id" value="<?php echo (int)$c['id']; ?>">
                <input type="hidden" name="status" value="<?php echo $c['status'] === 'cancelled' ? 'approved' : 'cancelled'; ?>">
                <button class="btn btn-sm" style="background:#f1f5f9;color:#475569"><?php echo $c['status'] === 'cancelled' ? 'بازگردانی' : 'لغو (مرجوعی)'; ?></button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ===================== تنظیمات ===================== -->
<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">⚙️ تنظیمات همکاری در فروش</h3>
    <p class="muted" style="margin-bottom:12px;line-height:2">
        پنل همکاران: <a href="<?php echo admin_h($aff_url); ?>" target="_blank" dir="ltr"><?php echo admin_h($aff_url); ?></a> — ورود و ثبت‌نام با موبایل و کد پیامکی (سرویس پیامک در «تنظیمات API» باید فعال باشد).<br>
        مشتری‌ای که از لینک یا با کد همکار وارد/خرید کند، <b>برای همیشه</b> به آن همکار وصل می‌شود و از همه خریدهای بعدی او (پلن، تمدید، توکن، پیامک) پورسانت محاسبه می‌شود.
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="settings">
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="aff_enabled" value="1" style="width:17px!important;height:17px" <?php echo !empty($bs['aff_enabled']) ? 'checked' : ''; ?>> سیستم همکاری در فروش فعال باشد</label>
        <div class="row2">
            <div><label>درصد پورسانت پیش‌فرض همکاران جدید</label><input type="number" step="0.5" min="0" max="90" name="aff_default_percent" value="<?php echo (float)$bs['aff_default_percent']; ?>"></div>
            <div><label>تخفیف مشتری با کد همکار (درصد؛ ۰ = بدون تخفیف)</label><input type="number" min="0" max="90" name="aff_customer_discount" value="<?php echo (int)$bs['aff_customer_discount']; ?>"></div>
        </div>
        <div class="row2">
            <div><label>اعتبار لینک معرفی (روز) — اگر در این مدت ثبت‌نام کند</label><input type="number" min="1" max="365" name="aff_cookie_days" value="<?php echo (int)$bs['aff_cookie_days']; ?>"></div>
            <div><label>حداقل مبلغ قابل تسویه (تومان)</label><input type="number" min="0" name="aff_min_payout" value="<?php echo (int)$bs['aff_min_payout']; ?>"></div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="aff_auto_approve" value="1" style="width:17px!important;height:17px" <?php echo !empty($bs['aff_auto_approve']) ? 'checked' : ''; ?>> ثبت‌نام همکاران جدید بدون نیاز به تأیید مدیر فعال شود</label>
        <label>قوانین همکاری (در پنل همکار نمایش داده می‌شود)</label>
        <textarea name="aff_terms" rows="4"><?php echo admin_h($bs['aff_terms']); ?></textarea>
        <button class="btn btn-primary btn-sm" style="margin-top:12px">💾 ذخیره تنظیمات</button>
        <p class="muted" style="margin-top:8px">تغییر «تخفیف مشتری» فقط روی کد همکاران جدید اثر دارد؛ برای همکاران فعلی، کد آن‌ها را در صفحه <a href="discounts.php">تخفیف‌ها</a> ویرایش کنید.</p>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">➕ افزودن همکار</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="add">
        <div style="flex:1;min-width:160px"><label>نام</label><input type="text" name="name" maxlength="120" required></div>
        <div style="flex:1;min-width:160px"><label>موبایل</label><input type="text" name="mobile" dir="ltr" required placeholder="09xxxxxxxxx"></div>
        <div style="width:140px"><label>درصد پورسانت</label><input type="number" step="0.5" min="0" max="90" name="percent" value="<?php echo (float)$bs['aff_default_percent']; ?>"></div>
        <button class="btn btn-primary btn-sm">افزودن</button>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">👥 همکاران</h3>
    <?php $affs = $pdo->query("SELECT * FROM saas_affiliates ORDER BY (status='pending') DESC, id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
    <?php if (!$affs): ?><p class="muted" style="text-align:center;padding:16px">هنوز همکاری ثبت نشده است.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>نام</th><th>موبایل</th><th>کد</th><th>پورسانت</th><th>کلیک</th><th>ثبت‌نام</th><th>خرید</th><th>پورسانت کل</th><th>مانده</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($affs as $a): $s = biz_affiliate_stats($pdo, (int)$a['id']); ?>
        <tr>
            <td><b><?php echo admin_h($a['name'] ?: '—'); ?></b></td>
            <td dir="ltr" style="text-align:right;font-size:12px"><?php echo admin_h($a['mobile']); ?></td>
            <td dir="ltr" style="text-align:right"><?php echo admin_h($a['ref_code']); ?></td>
            <td><?php echo (float)$a['commission_percent']; ?>٪</td>
            <td><?php echo number_format((int)$a['clicks']); ?></td>
            <td><?php echo number_format($s['signups']); ?></td>
            <td><?php echo number_format($s['sales']); ?></td>
            <td><?php echo number_format($s['earned']); ?></td>
            <td style="font-weight:700;color:<?php echo $s['balance'] >= (int)$bs['aff_min_payout'] && $s['balance'] > 0 ? '#059669' : 'inherit'; ?>"><?php echo number_format($s['balance']); ?></td>
            <td style="color:<?php echo $st_label[$a['status']][1] ?? '#64748b'; ?>;font-size:12px;font-weight:700"><?php echo $st_label[$a['status']][0] ?? $a['status']; ?></td>
            <td><a href="affiliates.php?view=<?php echo (int)$a['id']; ?>" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">مدیریت</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
