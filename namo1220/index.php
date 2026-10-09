<?php
require_once __DIR__ . '/_bootstrap.php';

// آمار کلی
$total_users   = (int)$pdo->query("SELECT COUNT(*) FROM saas_users WHERE email <> 'site-assistant@system.invalid'")->fetchColumn();
$active_users  = (int)$pdo->query("SELECT COUNT(*) FROM saas_users WHERE status='active' AND email <> 'site-assistant@system.invalid'")->fetchColumn();
$total_revenue = (int)($pdo->query("SELECT SUM(amount_toman) FROM saas_payments WHERE status='paid'")->fetchColumn() ?? 0);
$total_plans   = (int)$pdo->query("SELECT COUNT(*) FROM saas_plans WHERE is_active=1")->fetchColumn();
$today_pays    = (int)($pdo->query("SELECT SUM(amount_toman) FROM saas_payments WHERE status='paid' AND DATE(paid_at)=CURDATE()")->fetchColumn() ?? 0);
$pending_pays  = (int)$pdo->query("SELECT COUNT(*) FROM saas_payments WHERE status='pending'")->fetchColumn();

// آخرین کاربران
$new_users = $pdo->query("SELECT u.*, p.name as plan_name FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id WHERE u.email <> 'site-assistant@system.invalid' ORDER BY u.id DESC LIMIT 8")->fetchAll();

// آخرین پرداخت‌ها
$last_pays = $pdo->query("SELECT py.*, u.email, u.full_name FROM saas_payments py LEFT JOIN saas_users u ON u.id=py.user_id ORDER BY py.id DESC LIMIT 6")->fetchAll();

// نمودارها (۳۰ روز اخیر)
$ch_d = ch_days(30);
$ch_rev = ch_daily($pdo, "SELECT DATE(paid_at) AS d, SUM(amount_toman) AS v FROM saas_payments WHERE status='paid' AND paid_at>=? GROUP BY DATE(paid_at)", [], $ch_d['keys']);
$ch_newu = ch_daily($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM saas_users WHERE created_at>=? GROUP BY DATE(created_at)", [], $ch_d['keys']);
$ch_plans = [];
try {
    foreach ($pdo->query("SELECT COALESCE(p.name, '') AS label, COUNT(u.id) AS value FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id WHERE u.status='active' AND u.email <> 'site-assistant@system.invalid' GROUP BY p.id, p.name")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r)
        $ch_plans[] = ['label' => $r['label'] !== '' ? $r['label'] : 'بدون پلن', 'value' => (int)$r['value']];
} catch (\Throwable $e) {}
$ch_types = [];
$ch_tl = ['plan' => 'خرید پلن', 'credit' => 'شارژ اعتبار', 'sms' => 'بسته پیامک', 'custom' => 'پلن سفارشی'];
try {
    $st = $pdo->prepare("SELECT type AS t, SUM(amount_toman) AS v FROM saas_payments WHERE status='paid' AND paid_at>=? GROUP BY type");
    $st->execute([$ch_d['keys'][0] . ' 00:00:00']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $ch_types[] = ['label' => $ch_tl[$r['t']] ?? (string)$r['t'], 'value' => (int)$r['v'], 'color' => ['plan' => '#2a78d6', 'credit' => '#eb6834', 'sms' => '#1baf7a', 'custom' => '#eda100'][$r['t']] ?? '#4a3aa7'];
} catch (\Throwable $e) {}

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">📊 داشبورد مدیریت</h2>';
?>

<div class="stat-grid">
    <a class="stat-box" href="users.php" title="مشاهده لیست کاربران">
        <div class="icon">👥</div>
        <div class="val"><?php echo number_format($total_users); ?></div>
        <div class="lbl">کل کاربران</div>
    </a>
    <a class="stat-box" href="users.php?filter=active" title="مشاهده کاربران فعال">
        <div class="icon">✅</div>
        <div class="val"><?php echo number_format($active_users); ?></div>
        <div class="lbl">کاربران فعال</div>
    </a>
    <a class="stat-box" href="payments.php?status=paid" title="مشاهده پرداخت‌های موفق">
        <div class="icon">💰</div>
        <div class="val"><?php echo number_format($total_revenue); ?></div>
        <div class="lbl">کل درآمد (تومان)</div>
    </a>
    <a class="stat-box" href="plans.php" title="مدیریت پلن‌ها">
        <div class="icon">📦</div>
        <div class="val"><?php echo number_format($total_plans); ?></div>
        <div class="lbl">پلن‌های فعال</div>
    </a>
    <a class="stat-box" href="payments.php?status=paid&amp;day=today" title="مشاهده پرداخت‌های امروز">
        <div class="icon">🎯</div>
        <div class="val"><?php echo number_format($today_pays); ?></div>
        <div class="lbl">درآمد امروز (تومان)</div>
    </a>
    <a class="stat-box" href="payments.php?status=pending" title="مشاهده پرداخت‌های معلق">
        <div class="icon">⏳</div>
        <div class="val"><?php echo number_format($pending_pays); ?></div>
        <div class="lbl">پرداخت‌های معلق</div>
    </a>
</div>

<div class="chx-grid">
    <?php
    echo ch_card('📈 درآمد روزانه', ch_line($ch_d['labels'], [['name' => 'درآمد', 'data' => $ch_rev]], ['unit' => 'تومان', 'label' => 'درآمد روزانه ۳۰ روز اخیر']), '۳۰ روز اخیر — مجموع ' . number_format(array_sum($ch_rev)) . ' تومان', 'wide');
    echo ch_card('🍩 کاربران فعال بر اساس پلن', ch_donut($ch_plans, ['unit' => 'کاربر', 'center' => 'کاربر فعال']));
    echo ch_card('📈 ثبت‌نام کاربران جدید', ch_line($ch_d['labels'], [['name' => 'کاربر جدید', 'data' => $ch_newu, 'color' => '#1baf7a']], ['unit' => 'کاربر', 'label' => 'ثبت‌نام روزانه ۳۰ روز اخیر']), '۳۰ روز اخیر — ' . number_format(array_sum($ch_newu)) . ' نفر', 'wide');
    echo ch_card('🥧 ترکیب درآمد', ch_pie($ch_types, ['unit' => 'تومان']), '۳۰ روز اخیر');
    ?>
</div>

<div class="row2">
    <div class="card">
        <h3 style="font-size:14px;margin-bottom:14px">👥 آخرین کاربران</h3>
        <table>
            <thead><tr><th>نام</th><th>ایمیل</th><th>پلن</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($new_users as $u): ?>
            <tr>
                <td><a href="users.php?view=<?php echo $u['id']; ?>" style="color:#7c3aed;text-decoration:none"><?php echo admin_h($u['full_name']); ?></a></td>
                <td style="font-size:11px;color:#64748b;direction:ltr;text-align:right"><?php echo admin_h($u['email']); ?></td>
                <td style="font-size:12px"><?php echo admin_h($u['plan_name'] ?? '—'); ?></td>
                <td><span class="<?php echo $u['status']==='active'?'badge-ok':($u['status']==='suspended'?'badge-err':'badge-off'); ?>">
                    <?php echo ['active'=>'فعال','inactive'=>'غیرفعال','suspended'=>'تعلیق'][$u['status']]??$u['status']; ?>
                </span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($new_users)): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:20px">هنوز کاربری ثبت‌نام نکرده.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div style="text-align:left;margin-top:12px"><a href="users.php" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">همه کاربران →</a></div>
    </div>

    <div class="card">
        <h3 style="font-size:14px;margin-bottom:14px">💳 آخرین پرداخت‌ها</h3>
        <table>
            <thead><tr><th>کاربر</th><th>مبلغ</th><th>نوع</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($last_pays as $py): ?>
            <tr>
                <td style="font-size:12px"><?php echo admin_h($py['full_name']); ?></td>
                <td><?php echo number_format($py['amount_toman']); ?></td>
                <td style="font-size:12px"><?php echo $py['type']==='plan'?'پلن':'شارژ'; ?></td>
                <td>
                    <span class="<?php echo $py['status']==='paid'?'badge-ok':($py['status']==='failed'?'badge-err':'badge-off'); ?>">
                        <?php echo ['pending'=>'معلق','paid'=>'موفق','failed'=>'ناموفق'][$py['status']]??$py['status']; ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($last_pays)): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:20px">پرداختی ثبت نشده.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div style="text-align:left;margin-top:12px"><a href="payments.php" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">همه پرداخت‌ها →</a></div>
    </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
