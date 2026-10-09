<?php
require_once __DIR__ . '/_bootstrap.php';
$page_title = 'اعتبار و پرداخت';

$cfg = saas_get_api_config($pdo);
$bs = biz_settings($pdo);
$plans = saas_get_plans($pdo, true);
$payments = saas_payments_log($pdo, $current_user['id'], 15);
$credit_log = saas_credit_log($pdo, $current_user['id'], 15);
$sub = biz_subscription($current_user, $pdo);
$offer = biz_renew_offer($pdo, $current_user);
$sms = biz_sms_status($pdo, (int)$current_user['id']);
$cur_plan = !empty($current_user['plan_id']) ? saas_get_plan($pdo, (int)$current_user['plan_id']) : null;
$sms_log = $pdo->prepare("SELECT COUNT(*) FROM saas_sms_log WHERE user_id=? AND source IN ('free','paid') AND created_at >= ?");
$sms_log->execute([(int)$current_user['id'], date('Y-m-d H:i:s', time() - 30 * 86400)]);
$sms_used_30 = (int)$sms_log->fetchColumn();

include __DIR__ . '/_header.php';
$state_label = ['none' => 'بدون پلن', 'active' => 'فعال', 'grace' => 'منقضی (در مهلت ۷ روزه)', 'expired' => 'متوقف'];
$inst = biz_inst_status($pdo, (int)$current_user['id']);
$order = biz_active_inst_order($pdo, (int)$current_user['id']);
$state_color = ['none' => '#64748b', 'active' => '#059669', 'grace' => '#d97706', 'expired' => '#dc2626'];
?>

<div class="topbar"><h1>اعتبار و پرداخت</h1></div>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success">✅ <?php echo saas_h($_GET['msg']); ?></div><?php endif; ?>
<?php
// فیش‌های کارت به کارت (نسخه ۵۶): وضعیت فیش‌های اخیر و پرداخت‌های کارت به کارتِ بدون فیش
if (function_exists('c2c_list')) {
    $my_rc = array_values(array_filter(c2c_list($pdo, ['scope' => 'user', 'user_id' => (int)$current_user['id']], 10), fn($r) => $r['source'] === 'self' && ($r['status'] !== 'approved' || strtotime((string)$r['decided_at']) > time() - 7 * 86400)));
    if ($my_rc): ?>
<div class="card">
    <div class="card-title">🧾 فیش‌های کارت به کارت شما</div>
    <?php foreach ($my_rc as $r): ?>
    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px">
        <span><?php echo saas_h($r['note'] ?: $r['plan_name']); ?> — <?php echo number_format((int)$r['amount']); ?> تومان <span class="muted">(<?php echo biz_jdate($r['created_at'], true); ?>)</span></span>
        <b><?php echo c2c_status_label($r['status']); ?><?php echo $r['status'] === 'rejected' && $r['reason'] !== '' ? ' — ' . saas_h($r['reason']) : ''; ?></b>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; } ?>

<!-- وضعیت فعلی -->
<div class="card" id="renew">
    <div class="card-title">💳 وضعیت حساب</div>
    <div class="stats-grid">
        <div class="stat-card">
            <span class="val"><?php echo $current_user['plan_name'] ? saas_h($current_user['plan_name']) : 'ندارد'; ?></span>
            <div class="lbl">پلن فعال</div>
        </div>
        <div class="stat-card">
            <span class="val" style="font-size:16px;color:<?php echo $state_color[$sub['state']]; ?>"><?php echo $state_label[$sub['state']]; ?></span>
            <div class="lbl"><?php echo $sub['expires_at'] ? 'تا ' . biz_jdate($sub['expires_at']) . ($sub['state'] === 'active' ? ' (' . (int)$sub['days_left'] . ' روز)' : '') : 'وضعیت اشتراک'; ?></div>
        </div>
        <div class="stat-card">
            <span class="val"><?php echo number_format((int)$current_user['credit_tokens']); ?></span>
            <div class="lbl">اعتبار (تومان)</div>
        </div>
        <div class="stat-card">
            <span class="val"><?php echo number_format($sms['total_left']); ?></span>
            <div class="lbl">پیامک باقیمانده</div>
        </div>
    </div>
    <?php if ($cur_plan && $sub['state'] !== 'none'): ?>
    <?php if ($offer['percent'] > 0): ?>
    <div style="margin-top:14px;padding:14px 16px;border-radius:12px;background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid #f59e0b">
        <div style="font-size:15px;font-weight:800;color:#92400e">🎁 <?php echo (int)$offer['percent']; ?>٪ تخفیف برای تمدید زودهنگام!</div>
        <div style="font-size:13px;color:#78350f;line-height:2;margin-top:4px">
            اگر تا <b><?php echo biz_jdate($offer['until']); ?></b> اشتراک خود را تمدید کنید، <?php echo (int)$offer['percent']; ?>٪ تخفیف می‌گیرید<?php echo $offer['next'] ? ' — بعد از آن فقط ' . (int)$offer['next'][1] . '٪' : ' — بعد از آن تخفیفی نیست'; ?>.
            روزهای باقی‌مانده اشتراک فعلی حفظ می‌شود و یک سال کامل به آن اضافه می‌شود.
        </div>
        <?php $pp = biz_plan_price($cur_plan); $rp = max(1000, (int)(round((int)$cur_plan['price_toman'] * (100 - $offer['percent']) / 100 / 1000) * 1000)); ?>
        <a href="../payment/checkout.php?type=plan&plan_id=<?php echo (int)$cur_plan['id']; ?>" class="btn btn-primary" style="margin-top:10px">تمدید با <?php echo number_format(min($rp, $pp['price'])); ?> تومان <s style="opacity:.7;font-weight:400"><?php echo number_format((int)$cur_plan['price_toman']); ?></s></a>
    </div>
    <?php elseif ($offer['tiers'] && $sub['state'] === 'active'): ?>
    <div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:#f8fafc;font-size:12.5px;color:#475569;line-height:2">
        🎁 تخفیف تمدید زودهنگام: <?php echo implode(' — ', array_map(fn($t) => $t[0] . ' روز مانده: ' . $t[1] . '٪', $offer['tiers'])); ?>
    </div>
    <?php endif; ?>
    <?php if (in_array($sub['state'], ['grace', 'expired'], true) || ($sub['state'] === 'active' && $sub['days_left'] <= 30 && $offer['percent'] === 0)): ?>
    <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 14px;border-radius:12px;background:<?php echo $sub['state'] === 'active' ? '#fffbeb' : '#fef2f2'; ?>">
        <span style="font-size:13px;line-height:1.9"><?php echo $sub['state'] === 'active' ? '⏳ اشتراک شما ' . (int)$sub['days_left'] . ' روز دیگر به پایان می‌رسد.' : ($sub['state'] === 'grace' ? '⚠️ اشتراک منقضی شده؛ سرویس تا ' . biz_jdate($sub['grace_until']) . ' فعال است.' : '⛔ سرویس متوقف است؛ با تمدید فوراً فعال می‌شود.'); ?></span>
        <a href="../payment/checkout.php?type=plan&plan_id=<?php echo (int)$cur_plan['id']; ?>" class="btn btn-primary">🔄 تمدید اشتراک</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($cur_plan && (int)($cur_plan['setup_fee'] ?? 0) > 0): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;padding:12px 14px;border-radius:12px;background:#f8fafc">
        <span style="font-size:13px;line-height:1.9">🛠 می‌خواهید تیم ما پنل شما را کامل راه‌اندازی کند (تنظیم ویجت، پایگاه دانش و نصب روی سایت)؟</span>
        <a href="../payment/checkout.php?type=setup" class="btn btn-outline">درخواست راه‌اندازی — <?php echo number_format((int)$cur_plan['setup_fee']); ?> تومان</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($order): ?>
<!-- اقساط -->
<?php
$ist = $pdo->prepare("SELECT * FROM saas_installments WHERE order_id=? ORDER BY seq ASC");
$ist->execute([(int)$order['id']]);
$irows = $ist->fetchAll(PDO::FETCH_ASSOC) ?: [];
$op = saas_get_plan($pdo, (int)$order['plan_id']);
?>
<div class="card" id="inst">
    <div class="card-title">🗓 اقساط پلن «<?php echo saas_h($op['name'] ?? ''); ?>»</div>
    <?php if (in_array($inst['state'], ['grace', 'blocked'], true)): ?>
    <div class="alert alert-danger"><?php echo $inst['state'] === 'blocked'
        ? '⛔ سرویس شما به دلیل پرداخت‌نشدن قسط متوقف است. با پرداخت، فوراً فعال می‌شود.'
        : '⚠️ قسط سررسید شده است. اگر تا ' . biz_jdate($inst['grace_until']) . ' پرداخت نشود، سرویس متوقف می‌شود.'; ?></div>
    <?php endif; ?>
    <div class="table-wrap"><table>
        <thead><tr><th>قسط</th><th>مبلغ</th><th>سررسید</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php $first_unpaid = true; foreach ($irows as $r): ?>
        <tr>
            <td><?php echo (int)$r['seq']; ?> از <?php echo count($irows); ?></td>
            <td><?php echo number_format((int)$r['amount']); ?> تومان</td>
            <td style="font-size:12px"><?php echo biz_jdate($r['due_at']); ?></td>
            <td><?php if ($r['status'] === 'paid'): ?><span class="badge badge-green">پرداخت شد</span>
                <?php elseif (strtotime($r['due_at']) < time()): ?><span class="badge badge-red">سررسید گذشته</span>
                <?php else: ?><span class="badge badge-yellow">در انتظار</span><?php endif; ?></td>
            <td><?php if ($r['status'] !== 'paid' && $first_unpaid): $first_unpaid = false; ?><a href="../payment/checkout.php?type=inst" class="btn btn-primary btn-sm">پرداخت</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php if ($inst['unpaid'] > 1): ?>
    <p style="margin-top:12px;font-size:13px">مانده کل: <b><?php echo number_format($inst['unpaid_sum']); ?> تومان</b> —
        <a href="../payment/checkout.php?type=inst&all=1" class="btn btn-outline btn-sm">پرداخت یکجای همه اقساط</a></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- خرید پلن -->
<div class="card">
    <div class="card-title">📦 خرید / ارتقاء پلن <span style="font-weight:normal;font-size:12px;color:#64748b">(اشتراک یک‌ساله)</span></div>
    <?php
    // دسته‌بندی پلن‌ها (نسخه ۴۵): زبانه پیش‌فرض = دسته پلن فعلی کاربر
    $pgroups = function_exists('pc_group') ? pc_group($pdo, $plans) : [['cat' => ['id' => 0, 'slug' => 'all', 'title' => '', 'icon' => '', 'description' => ''], 'plans' => $plans]];
    $cur_plan = !empty($current_user['plan_id']) ? saas_get_plan($pdo, (int)$current_user['plan_id']) : null;
    $cur_cat = ($cur_plan && function_exists('pc_plan_cat')) ? pc_plan_cat($pdo, $cur_plan) : null;
    $act_slug = $cur_cat['slug'] ?? '';
    if (!in_array($act_slug, array_map(fn($g) => $g['cat']['slug'], $pgroups), true)) $act_slug = $pgroups[0]['cat']['slug'] ?? '';
    echo function_exists('pc_tabs_html') ? pc_tabs_html($pgroups, 'light', $act_slug, 'pct_bill') : '';
    foreach ($pgroups as $g):
    ?>
    <div data-pcg="<?php echo saas_h($g['cat']['slug']); ?>"<?php echo $g['cat']['slug'] === $act_slug ? '' : ' hidden'; ?>>
    <?php if (count($pgroups) > 1 && $g['cat']['description'] !== ''): ?><p class="pcg-desc l"><?php echo saas_h($g['cat']['description']); ?></p><?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),330px));justify-content:center;gap:16px">
        <?php foreach ($g['plans'] as $p): $is_cur = (int)$current_user['plan_id'] === (int)$p['id']; $head = biz_plan_head_html($p); ?>
        <div style="border:2px solid <?php echo $is_cur ? '#7c3aed' : '#e2e8f0'; ?>;border-radius:12px;padding:14px;text-align:center;background:<?php echo $is_cur ? '#faf5ff' : '#fff'; ?>;display:flex;flex-direction:column">
            <?php if ($is_cur): ?><div class="badge badge-blue" style="margin-bottom:8px;align-self:center">پلن فعلی</div><?php endif; ?>
            <?php if ($head !== ''): echo $head; else: ?>
            <h3 style="font-size:15px;color:#1e293b;margin-bottom:6px"><?php echo saas_h($p['name']); ?></h3>
            <?php if (!empty($p['description'])): ?><p style="font-size:12px;color:#64748b;margin-bottom:8px;line-height:1.8"><?php echo nl2br(saas_h($p['description'])); ?></p><?php endif; ?>
            <?php endif; ?>
            <div style="font-size:22px;font-weight:800;color:#7c3aed;margin:6px 0 10px"><?php echo biz_price_html($p, 'color:#64748b'); ?></div>
            <ul style="list-style:none;font-size:12px;color:#475569;text-align:right;line-height:2;margin-bottom:12px;padding:0;flex:1">
                <?php foreach ((function_exists('ex_plan_feature_list_sorted') ? ex_plan_feature_list_sorted($p) : saas_plan_feature_list($p)) as $pf): ?>
                <li style="border-bottom:1px solid #f1f5f9;padding:3px 0">✓ <?php echo saas_h($pf['text']); ?>
                    <?php if ($pf['note'] !== ''): ?><div style="font-size:11px;color:#94a3b8;line-height:1.7;margin-right:16px"><?php echo saas_h($pf['note']); ?></div><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($is_cur): ?>
                <?php if ((int)$p['price_toman'] > 0 || ($sub['days_left'] ?? 999) <= 30 || $sub['state'] !== 'active'): ?>
                <a href="../payment/checkout.php?type=plan&plan_id=<?php echo (int)$p['id']; ?>" class="btn btn-primary" style="width:100%;display:block;text-align:center">🔄 تمدید<?php echo $offer['percent'] > 0 ? ' با ' . (int)$offer['percent'] . '٪ تخفیف' : ''; ?></a>
                <?php else: ?>
                <span style="color:#7c3aed;font-size:13px;font-weight:600">✓ فعال تا <?php echo biz_jdate($sub['expires_at']); ?></span>
                <?php endif; ?>
            <?php elseif ($order): ?>
                <span style="color:#94a3b8;font-size:12px">پس از تسویه اقساط</span>
            <?php elseif ((int)$p['price_toman'] > 0): ?>
                <a href="../payment/checkout.php?type=plan&plan_id=<?php echo (int)$p['id']; ?>" class="btn btn-primary" style="width:100%;display:block;text-align:center">خرید این پلن</a>
            <?php else: ?>
                <a href="../payment/checkout.php?type=plan&plan_id=<?php echo (int)$p['id']; ?>" class="btn btn-outline" style="width:100%;display:block;text-align:center">فعال‌سازی</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    </div>
    <?php endforeach; ?>
</div>

<?php $cpc = function_exists('cx_cplan_cfg') ? cx_cplan_cfg($pdo) : ['on' => 0]; if (!empty($cpc['on'])): $cur_custom = $cur_plan && !empty($cur_plan['is_custom']); ?>
<!-- پلن دلخواه -->
<div class="card" style="background:linear-gradient(135deg,#faf5ff,#eff6ff);border:1.5px dashed #a78bfa">
    <div class="card-title">🧩 پلن دلخواه خودتان را بسازید</div>
    <p style="font-size:13px;color:#475569;line-height:2;margin-bottom:10px">هیچ‌کدام از پلن‌های بالا دقیقاً مناسب شما نیست؟ فقط امکاناتی را که لازم دارید انتخاب کنید (چت‌بات تخصصی، پایگاه دانش، محصولات، مدل‌های هوش مصنوعی، ساخت تصویر و…) و قیمت نهایی را همان لحظه ببینید.</p>
    <?php if ($cur_custom): ?><p style="font-size:12.5px;color:#7c3aed;margin-bottom:8px">✓ پلن فعلی شما دلخواه است؛ برای تغییر امکانات یا تمدید، دوباره آن را بسازید.</p><?php endif; ?>
    <a href="custom_plan.php" class="btn btn-primary">🧩 <?php echo $cur_custom ? 'ویرایش و تمدید پلن دلخواه' : 'ساخت پلن دلخواه'; ?></a>
</div>
<?php endif; ?>

<!-- شارژ اعتبار (تومانی) -->
<div class="card">
    <div class="card-title">🔋 شارژ اعتبار</div>
    <?php $cdisc = biz_auto_discount($pdo, 'credit'); ?>
    <p style="font-size:13px;color:#475569;margin-bottom:16px">
        اعتبار حساب به <b>تومان</b> است؛ هزینه هر پاسخ، تصویر یا ویدیو به اندازه قیمت همان مدل از اعتبار کم می‌شود.
        <?php if ($cdisc): ?><span class="badge badge-red" style="margin-right:6px">🔥 <?php echo $cdisc; ?>٪ تخفیف شارژ<?php echo $bs['credit_discount_until'] ? ' تا ' . biz_jdate($bs['credit_discount_until']) : ''; ?></span><?php endif; ?>
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php
        foreach ([50000, 100000, 200000, 500000] as $amt):
            $pay = $cdisc ? (int)round($amt * (100 - $cdisc) / 100) : $amt;
        ?>
        <a href="../payment/checkout.php?type=credit&amount=<?php echo $amt; ?>" class="btn btn-outline" style="text-align:center;display:flex;flex-direction:column;padding:12px 20px">
            <span style="font-weight:800;font-size:15px"><?php if ($cdisc): ?><s style="font-weight:400;color:#94a3b8;font-size:12px"><?php echo number_format($amt); ?></s> <?php endif; ?><?php echo number_format($pay); ?> تومان</span>
            <small style="color:#7c3aed"><?php echo number_format($amt); ?> تومان اعتبار</small>
        </a>
        <?php endforeach; ?>
    </div>
    <p class="muted" style="margin-top:12px">شارژ سفارشی و کد تخفیف: در صفحه پرداخت.</p>
</div>

<!-- کیف پیامک -->
<div class="card" id="sms">
    <div class="card-title">✉️ کیف پیامک</div>
    <p style="font-size:13px;color:#475569;line-height:2;margin-bottom:12px">
        پیامک‌هایی که برای <b>مخاطبان شما</b> ارسال می‌شود (کد ورود چت‌بات، یادآوری انقضای بسته‌ها) ابتدا از پیامک رایگان پلن و سپس از بسته پیامک خریداری‌شده کم می‌شود.
        در این پیامک‌ها فقط نام خود شما (نام ربات) آورده می‌شود.
    </p>
    <div class="stats-grid">
        <div class="stat-card"><span class="val"><?php echo number_format($sms['free_left']); ?> <small style="font-size:12px;color:#94a3b8">/ <?php echo number_format($sms['free_total']); ?></small></span><div class="lbl">پیامک رایگان پلن (این دوره)</div></div>
        <div class="stat-card"><span class="val"><?php echo number_format($sms['balance']); ?></span><div class="lbl">پیامک خریداری‌شده</div></div>
        <div class="stat-card"><span class="val"><?php echo number_format($sms_used_30); ?></span><div class="lbl">مصرف ۳۰ روز اخیر</div></div>
    </div>
    <?php $sdisc = biz_auto_discount($pdo, 'sms'); $sp = max(1, (int)$bs['sms_price_toman']); ?>
    <p style="font-size:13px;color:#475569;margin:14px 0 10px">قیمت هر پیامک: <b><?php echo number_format($sp); ?> تومان</b>
        <?php if ($sdisc): ?><span class="badge badge-red" style="margin-right:6px">🔥 <?php echo $sdisc; ?>٪ تخفیف</span><?php endif; ?></p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php foreach ([100, 500, 1000, 5000] as $c): if ($c < (int)$bs['sms_min_purchase']) continue; $price = $c * $sp; $pay = $sdisc ? (int)round($price * (100 - $sdisc) / 100) : $price; ?>
        <a href="../payment/checkout.php?type=sms&count=<?php echo $c; ?>" class="btn btn-outline" style="text-align:center;display:flex;flex-direction:column;padding:12px 20px">
            <span style="font-weight:800;font-size:15px"><?php echo number_format($c); ?> پیامک</span>
            <small style="color:#7c3aed"><?php if ($sdisc): ?><s style="color:#94a3b8"><?php echo number_format($price); ?></s> <?php endif; ?><?php echo number_format($pay); ?> تومان</small>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- سابقه پرداخت -->
<div class="card">
    <div class="card-title">📜 سابقه پرداخت‌ها</div>
    <?php if (empty($payments)): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px;font-size:13px">هنوز پرداختی ثبت نشده است.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>شرح</th><th>مبلغ</th><th>تخفیف</th><th>وضعیت</th><th>کد پیگیری</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $pay): ?>
            <tr>
                <td><?php echo saas_h($pay['description'] ?: ($pay['type'] === 'plan' ? 'خرید پلن ' . ($pay['plan_name'] ?? '') : ($pay['type'] === 'sms' ? 'خرید پیامک' : 'شارژ اعتبار'))); ?></td>
                <td><?php echo number_format((int)$pay['amount_toman']); ?> تومان</td>
                <td style="font-size:12px;color:#059669"><?php echo (int)($pay['discount_amount'] ?? 0) > 0 ? number_format((int)$pay['discount_amount']) : '—'; ?></td>
                <td>
                    <?php
                    $badge = ['pending'=>'badge-yellow','paid'=>'badge-green','failed'=>'badge-red','refunded'=>'badge-gray'];
                    $label = ['pending'=>'در انتظار','paid'=>'پرداخت شد','failed'=>'ناموفق','refunded'=>'بازگشت'];
                    $st = $pay['status'];
                    ?>
                    <span class="badge <?php echo $badge[$st]??'badge-gray'; ?>"><?php echo $label[$st]??$st; ?></span>
                </td>
                <td style="font-size:12px;color:#64748b;direction:ltr"><?php echo saas_h($pay['ref_code'] ?? '—'); ?></td>
                <td style="font-size:12px;color:#94a3b8"><?php echo biz_jdate($pay['created_at'], true); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- لاگ اعتبار -->
<div class="card">
    <div class="card-title">🔄 تاریخچه اعتبار</div>
    <?php if (empty($credit_log)): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px;font-size:13px">تاریخچه‌ای وجود ندارد.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>توضیح</th><th>تغییر (تومان)</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php foreach ($credit_log as $log): ?>
            <tr>
                <td><?php echo saas_h($log['description'] ?? '—'); ?></td>
                <td style="font-weight:700;color:<?php echo $log['tokens_delta']>0 ? '#059669' : '#dc2626'; ?>">
                    <?php echo $log['tokens_delta'] > 0 ? '+' : ''; ?><?php echo number_format((int)$log['tokens_delta']); ?>
                </td>
                <td style="font-size:12px;color:#94a3b8"><?php echo biz_jdate($log['created_at'], true); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
