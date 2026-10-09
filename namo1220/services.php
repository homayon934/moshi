<?php
/**
 * درخواست‌های خدمات: راه‌اندازی پنل و زیردامنه اختصاصی (CNAME)، تخفیف تمدید زودهنگام، اشتراک‌های رو به پایان
 */
require_once __DIR__ . '/_bootstrap.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: services.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $act = $_POST['act'] ?? '';
    if ($act === 'fees') {
        biz_set($pdo, 'domain_fee', max(0, (int)str_replace(',', '', (string)($_POST['domain_fee'] ?? '0'))));
        $msg = 'ok:هزینه‌ها ذخیره شد.';
    } elseif ($act === 'renew') {
        $tiers = [];
        foreach ((array)($_POST['rd'] ?? []) as $i => $d) {
            $d = (int)$d; $p = (int)($_POST['rp'][$i] ?? 0);
            if ($d > 0 && $p > 0 && $p < 100) $tiers[$d] = $p;
        }
        krsort($tiers);
        biz_set($pdo, 'renew_tiers', implode(',', array_map(fn($d, $p) => $d . ':' . $p, array_keys($tiers), $tiers)));
        $msg = 'ok:پله‌های تخفیف تمدید ذخیره شد.';
    } elseif ($act === 'status') {
        $id = (int)($_POST['req_id'] ?? 0);
        $done = ($_POST['status'] ?? '') === 'done';
        $note = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 500);
        $pdo->prepare("UPDATE saas_service_requests SET status=?, admin_note=?, done_at=? WHERE id=?")->execute([$done ? 'done' : 'open', $note, $done ? date('Y-m-d H:i:s') : null, $id]);
        if ($done) {
            $r = $pdo->prepare("SELECT * FROM saas_service_requests WHERE id=?");
            $r->execute([$id]);
            if ($req = $r->fetch(PDO::FETCH_ASSOC)) {
                $t = $req['type'] === 'domain' ? '🌐 زیردامنه اختصاصی شما فعال شد' : '🛠 راه‌اندازی پنل شما انجام شد';
                biz_notify($pdo, (int)$req['user_id'], $t, trim($req['detail'] . ($note !== '' ? "\n" . $note : '')), 'success', $req['type'] === 'domain' ? 'bot.php?id=' . (int)$req['bot_id'] . '&tab=install' : 'index.php');
            }
        }
        $msg = 'ok:وضعیت درخواست به‌روز شد.';
    }
    header('Location: services.php?msg=' . urlencode($msg)); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];

$bs = biz_settings($pdo, true);
$filter = ($_GET['f'] ?? 'open') === 'all' ? 'all' : 'open';
$reqs = $pdo->query("SELECT r.*, u.full_name, u.email, u.phone FROM saas_service_requests r LEFT JOIN saas_users u ON u.id=r.user_id " . ($filter === 'open' ? "WHERE r.status='open' " : '') . "ORDER BY r.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$host = strtolower((string)parse_url(AICHAT_BASE_URL, PHP_URL_HOST));
$host_ip = @gethostbyname($host);

// اشتراک‌های رو به پایان (۳۰ روز آینده) و منقضی در مهلت
$exps = $pdo->prepare("SELECT u.id, u.full_name, u.phone, u.plan_expires_at, p.name AS plan_name FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id
    WHERE u.status='active' AND u.plan_id>0 AND u.plan_expires_at IS NOT NULL AND u.plan_expires_at BETWEEN ? AND ? ORDER BY u.plan_expires_at ASC LIMIT 300");
$exps->execute([date('Y-m-d H:i:s', time() - (BIZ_GRACE_DAYS + 30) * 86400), date('Y-m-d H:i:s', time() + 30 * 86400)]);
$exps = $exps->fetchAll(PDO::FETCH_ASSOC) ?: [];
$tiers = biz_renew_tiers($pdo);

// اقساط عقب‌افتاده و نزدیک (خریدهای قسطی قدیمی)
$late = $pdo->prepare("SELECT i.*, u.full_name, u.phone, p.name AS plan_name FROM saas_installments i JOIN saas_inst_orders o ON o.id=i.order_id JOIN saas_users u ON u.id=i.user_id LEFT JOIN saas_plans p ON p.id=o.plan_id
    WHERE i.status='unpaid' AND o.status='active' AND i.due_at < ? ORDER BY i.due_at ASC LIMIT 200");
$late->execute([date('Y-m-d H:i:s', time() + 7 * 86400)]);
$lates = $late->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🛠 خدمات و تمدید</h2>';
?>
<?php if ($msg): ?>
<div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">💰 هزینه خدمات</h3>
    <p class="muted" style="margin-bottom:10px;line-height:2">هزینه راه‌اندازی هر پلن در صفحه <a href="plans.php">پلن‌ها</a> تعیین می‌شود. هزینه راه‌اندازی زیردامنه اختصاصی (CNAME) برای هر چت‌بات یک بار دریافت می‌شود.</p>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="fees">
        <div><label>هزینه راه‌اندازی زیردامنه اختصاصی (تومان؛ ۰ = رایگان)</label><input type="text" name="domain_fee" dir="ltr" value="<?php echo (int)$bs['domain_fee']; ?>" style="width:220px !important"></div>
        <button class="btn btn-primary btn-sm">💾 ذخیره</button>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">🎁 تخفیف تمدید زودهنگام</h3>
    <p class="muted" style="margin-bottom:10px;line-height:2">اگر کاربر اشتراک فعلی خود را زودتر تمدید کند، این تخفیف روی قیمت پلن اعمال می‌شود و در پنل او با تاریخ پایان هر پله نمایش داده می‌شود تا انگیزه تمدید زودتر ایجاد شود. (بین تخفیف پلن و تخفیف تمدید، هرکدام بیشتر باشد اعمال می‌شود.)</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="renew">
        <?php $rows = $tiers; while (count($rows) < 5) $rows[] = [0, 0]; foreach ($rows as [$d, $p]): ?>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;font-size:13px">
            حداقل <input type="number" name="rd[]" min="0" max="365" value="<?php echo $d ?: ''; ?>" style="width:90px !important"> روز مانده به پایان اشتراک ←
            <input type="number" name="rp[]" min="0" max="99" value="<?php echo $p ?: ''; ?>" style="width:80px !important"> درصد تخفیف
        </div>
        <?php endforeach; ?>
        <p class="muted">پیش‌فرض: ۹۰ روز (۳ ماه) زودتر ۵۰٪ — ۶۰ روز (۲ ماه) ۳۰٪ — ۷ روز (یک ماه تا یک هفته) ۱۰٪. ردیف خالی نادیده گرفته می‌شود.</p>
        <button class="btn btn-primary btn-sm" style="margin-top:8px">💾 ذخیره</button>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">⏳ اشتراک‌های رو به پایان و منقضی‌شده</h3>
    <?php if (!$exps): ?><p class="muted" style="text-align:center;padding:14px">موردی نیست.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>کاربر</th><th>پلن</th><th>انقضا</th><th>وضعیت</th></tr></thead><tbody>
        <?php foreach ($exps as $e): $dl = (int)ceil((strtotime($e['plan_expires_at']) - time()) / 86400); ?>
        <tr>
            <td style="font-size:12px"><a href="users.php?view=<?php echo (int)$e['id']; ?>"><?php echo admin_h($e['full_name']); ?></a> <span dir="ltr"><?php echo admin_h($e['phone']); ?></span></td>
            <td><?php echo admin_h($e['plan_name'] ?? ''); ?></td>
            <td style="font-size:12px"><?php echo biz_jdate($e['plan_expires_at']); ?></td>
            <td style="font-size:12px"><?php echo $dl > 0 ? $dl . ' روز مانده' : ($dl > -BIZ_GRACE_DAYS ? '<span style="color:#d97706">در مهلت ۷ روزه</span>' : '<span style="color:#dc2626">⛔ متوقف</span>'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
        <h3 style="font-size:15px">📥 درخواست‌ها</h3>
        <div><a href="services.php?f=open" class="btn btn-sm" style="background:<?php echo $filter === 'open' ? '#ede9fe' : '#f1f5f9'; ?>;color:#374151">باز</a>
            <a href="services.php?f=all" class="btn btn-sm" style="background:<?php echo $filter === 'all' ? '#ede9fe' : '#f1f5f9'; ?>;color:#374151">همه</a></div>
    </div>
    <p class="muted" style="margin-bottom:10px;line-height:2">
        برای زیردامنه: در cPanel بخش <b>Domains</b> دامنه را با Document Root = <code dir="ltr">public_html/ai-chat/hamdam/host</code> اضافه کنید و <b>Run AutoSSL</b> بزنید؛ سپس «انجام شد» را بزنید تا به کاربر اطلاع داده شود.
    </p>
    <?php if (!$reqs): ?><p class="muted" style="text-align:center;padding:14px">درخواستی وجود ندارد.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>تاریخ</th><th>نوع</th><th>کاربر</th><th>جزئیات</th><th>مبلغ</th><th>وضعیت / اقدام</th></tr></thead><tbody>
        <?php foreach ($reqs as $r):
            $dns = '';
            if ($r['type'] === 'domain' && $r['detail'] !== '') { $ip = @gethostbyname($r['detail']); $dns = ($ip !== $r['detail'] && $ip === $host_ip) ? '<span style="color:#059669">✅ DNS آماده</span>' : '<span style="color:#d97706">⏳ DNS هنوز به سرور اشاره نمی‌کند</span>'; }
        ?>
        <tr>
            <td style="font-size:12px;white-space:nowrap"><?php echo biz_jdate($r['created_at'], true); ?></td>
            <td><?php echo $r['type'] === 'domain' ? '🌐 زیردامنه' : '🛠 راه‌اندازی'; ?></td>
            <td style="font-size:12px"><a href="users.php?view=<?php echo (int)$r['user_id']; ?>"><?php echo admin_h($r['full_name'] ?: '#' . $r['user_id']); ?></a><br><span dir="ltr"><?php echo admin_h($r['phone'] ?? ''); ?></span></td>
            <td style="font-size:12.5px"><span dir="ltr"><?php echo admin_h($r['detail']); ?></span><?php echo $dns ? '<br>' . $dns : ''; ?></td>
            <td style="font-size:12px"><?php echo (int)$r['amount'] ? number_format((int)$r['amount']) : 'رایگان'; ?></td>
            <td>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
                    <input type="hidden" name="act" value="status"><input type="hidden" name="req_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="text" name="admin_note" value="<?php echo admin_h($r['admin_note']); ?>" placeholder="توضیح برای کاربر (اختیاری)" style="width:180px !important;padding:6px 8px;font-size:12px">
                    <?php if ($r['status'] === 'open'): ?>
                        <button name="status" value="done" class="btn btn-sm" style="background:#dcfce7;color:#059669">✓ انجام شد</button>
                    <?php else: ?>
                        <span style="color:#059669;font-size:12px">انجام شده <?php echo biz_jdate($r['done_at']); ?></span>
                        <button name="status" value="open" class="btn btn-sm" style="background:#f1f5f9;color:#475569">بازگشایی</button>
                    <?php endif; ?>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php if ($lates): ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">📅 اقساط باقی‌مانده خریدهای قسطی قبلی</h3>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>کاربر</th><th>پلن</th><th>قسط</th><th>مبلغ</th><th>سررسید</th><th>وضعیت</th></tr></thead><tbody>
        <?php foreach ($lates as $l): $d = (int)floor((time() - strtotime($l['due_at'])) / 86400); ?>
        <tr>
            <td style="font-size:12px"><a href="users.php?view=<?php echo (int)$l['user_id']; ?>"><?php echo admin_h($l['full_name']); ?></a> <span dir="ltr"><?php echo admin_h($l['phone']); ?></span></td>
            <td><?php echo admin_h($l['plan_name'] ?? ''); ?></td>
            <td><?php echo (int)$l['seq']; ?></td>
            <td><?php echo number_format((int)$l['amount']); ?></td>
            <td style="font-size:12px"><?php echo biz_jdate($l['due_at']); ?></td>
            <td style="font-size:12px"><?php echo $d < 0 ? 'در راه' : ($d < BIZ_GRACE_DAYS ? '<span style="color:#d97706">' . $d . ' روز تأخیر</span>' : '<span style="color:#dc2626">⛔ متوقف (' . $d . ' روز)</span>'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
