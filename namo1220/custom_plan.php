<?php
/**
 * پلن دلخواه کاربران: قیمت پیش‌فرض هر امکان (یک بار تنظیم می‌شود)
 * کاربر در پنل خود امکانات را انتخاب می‌کند و قیمت نهایی را همان لحظه می‌بیند.
 */
require_once __DIR__ . '/_bootstrap.php';
if (function_exists('cx_ensure_schema')) { try { cx_ensure_schema($pdo); } catch (\Throwable $e) {} }

$num = fn($v) => max(0, (int)str_replace([',', '٬', ' '], '', saas_fa_normalize((string)$v)));
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: custom_plan.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $cfg = cx_cplan_cfg($pdo);
    $out = ['on' => !empty($_POST['on']) ? 1 : 0, 'base' => $num($_POST['base'] ?? 0), 'days' => max(30, min(730, (int)($_POST['days'] ?? 365))), 'feat' => [], 'models' => [], 'qty' => []];
    foreach (array_keys(saas_plan_features()) as $k) $out['feat'][$k] = $num($_POST['feat'][$k] ?? 0);
    foreach (array_keys(cx_cplan_models($pdo)) as $id) $out['models'][$id] = $num($_POST['model'][$id] ?? 0);
    foreach ($cfg['qty'] as $k => $q) {
        $out['qty'][$k] = ['unit' => max(1, $num($_POST['qty'][$k]['unit'] ?? $q['unit'])), 'price' => $num($_POST['qty'][$k]['price'] ?? $q['price']), 'max' => max(1, $num($_POST['qty'][$k]['max'] ?? $q['max']))];
    }
    biz_set($pdo, 'cplan_cfg', json_encode($out));
    header('Location: custom_plan.php?msg=' . urlencode('ok:قیمت‌های پلن دلخواه ذخیره شد.')); exit;
}
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];

$cfg = cx_cplan_cfg($pdo);
$feats = cx_cplan_features($pdo);
$models = cx_cplan_models($pdo);
$cats = ['text' => '💬 مدل‌های گفتگو', 'image' => '🖼 ساخت تصویر', 'video' => '🎬 ساخت ویدیو'];
$made = [];
try {
    $made = $pdo->query("SELECT p.id, p.name, p.price_toman, p.created_at, u.full_name, u.email, (SELECT COUNT(*) FROM saas_users x WHERE x.plan_id=p.id) AS users FROM saas_plans p LEFT JOIN saas_users u ON u.id=p.owner_user_id WHERE p.is_custom=1 ORDER BY p.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🧩 پلن دلخواه کاربران</h2>';
?>
<?php if ($msg): ?>
<div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>
<style>
.cp-t{width:100%;border-collapse:collapse;font-size:13px}.cp-t td,.cp-t th{padding:7px 8px;border-bottom:1px solid #f1f5f9;text-align:right}
.cp-t input{width:150px !important;direction:ltr;text-align:center}.cp-t th{color:#64748b;font-weight:600;font-size:12px}
</style>
<form method="post">
<input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">⚙️ تنظیمات کلی</h3>
    <p class="muted" style="line-height:2;margin-bottom:10px">در کنار پلن‌های آماده، کاربر می‌تواند از «اعتبار و پرداخت ← ساخت پلن دلخواه» امکانات مورد نیازش را انتخاب کند؛ قیمت هر امکان همان لحظه به جمع کل اضافه می‌شود. قیمت‌های زیر پیش‌فرض همه پلن‌های دلخواه است (لازم نیست برای هر پلن جدا وارد کنید). مبلغ نهایی همیشه در سرور محاسبه می‌شود.</p>
    <label style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="on" value="1" <?php echo $cfg['on'] ? 'checked' : ''; ?> style="width:18px !important;height:18px"> <b>ساخت پلن دلخواه برای کاربران فعال باشد</b></label>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div><label>قیمت پایه (ویجت پاسخگوی هوشمند سایت) — تومان</label><input type="text" name="base" dir="ltr" value="<?php echo (int)$cfg['base']; ?>" style="width:200px !important"></div>
        <div><label>مدت اشتراک (روز)</label><input type="number" name="days" min="30" max="730" value="<?php echo (int)$cfg['days']; ?>" style="width:120px !important"></div>
    </div>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">✅ امکانات (قیمت هر امکان — تومان؛ ۰ = رایگان)</h3>
    <table class="cp-t"><tr><th>امکان</th><th>قیمت</th></tr>
    <?php foreach ($feats as $k => $f): ?>
        <tr><td><?php echo admin_h($f['icon'] . ' ' . $f['label']); ?></td><td><input type="text" name="feat[<?php echo admin_h($k); ?>]" value="<?php echo (int)$f['price']; ?>"></td></tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">🔢 موارد تعدادی</h3>
    <p class="muted" style="margin-bottom:8px">قیمت برای هر «واحد» است؛ مثلاً اگر واحد ۱۰ و قیمت ۲۰٬۰۰۰ باشد، ۵۰ موضوع = ۵ واحد = ۱۰۰٬۰۰۰ تومان.</p>
    <table class="cp-t"><tr><th>مورد</th><th>واحد</th><th>قیمت هر واحد</th><th>حداکثر قابل انتخاب</th></tr>
    <?php foreach ($cfg['qty'] as $k => $q): ?>
        <tr><td><?php echo admin_h($q['label']); ?><?php echo $q['need'] !== '' ? '<div class="muted" style="font-size:11px">فقط با امکان «' . admin_h(saas_plan_features()[$q['need']]['label'] ?? '') . '»</div>' : ''; ?></td>
            <td><input type="text" name="qty[<?php echo $k; ?>][unit]" value="<?php echo (int)$q['unit']; ?>" style="width:90px !important"></td>
            <td><input type="text" name="qty[<?php echo $k; ?>][price]" value="<?php echo (int)$q['price']; ?>"></td>
            <td><input type="text" name="qty[<?php echo $k; ?>][max]" value="<?php echo (int)$q['max']; ?>" style="width:110px !important"></td></tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">🧠 مدل‌های هوش مصنوعی (قیمت دسترسی به هر مدل — تومان؛ ۰ = رایگان)</h3>
    <?php if (!$models): ?><p class="muted">مدلی در صفحه «مدل‌ها» تعریف نشده است.</p><?php else: ?>
    <table class="cp-t"><tr><th>مدل</th><th>نوع</th><th>قیمت</th></tr>
    <?php foreach ($models as $m): ?>
        <tr><td><?php echo admin_h($m['title']); ?></td><td style="font-size:12px"><?php echo $cats[$m['cat']]; ?></td><td><input type="text" name="model[<?php echo (int)$m['id']; ?>]" value="<?php echo (int)$m['price']; ?>"></td></tr>
    <?php endforeach; ?>
    </table>
    <p class="muted" style="margin-top:8px">هزینه مصرف هر پاسخ مثل همیشه از اعتبار تومانی کاربر کم می‌شود؛ این قیمت فقط برای «دسترسی» به مدل در پلن است.</p>
    <?php endif; ?>
</div>
<button class="btn btn-primary" style="margin-bottom:20px">💾 ذخیره قیمت‌ها</button>
</form>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">📋 پلن‌های دلخواه ساخته‌شده (<?php echo count($made); ?>)</h3>
    <?php if (!$made): ?><p class="muted">هنوز کاربری پلن دلخواه نساخته است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>کاربر</th><th>پلن</th><th>مبلغ</th><th>در حال استفاده</th><th>تاریخ</th><th></th></tr></thead><tbody>
        <?php foreach ($made as $p): ?>
        <tr><td style="font-size:12px"><?php echo admin_h((string)$p['full_name']); ?> <span class="muted" dir="ltr"><?php echo admin_h((string)$p['email']); ?></span></td>
            <td><?php echo admin_h($p['name']); ?></td><td><?php echo number_format((int)$p['price_toman']); ?></td>
            <td><?php echo (int)$p['users'] ? '✅' : '—'; ?></td><td style="font-size:12px"><?php echo function_exists('biz_jdate') ? biz_jdate($p['created_at']) : admin_h($p['created_at']); ?></td>
            <td><a class="btn btn-outline btn-sm" href="plans.php?edit=<?php echo (int)$p['id']; ?>">مشاهده</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
