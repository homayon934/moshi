<?php
/**
 * مدیریت تخفیف‌ها: تخفیف خودکار شارژ اعتبار و پیامک + کدهای تخفیف
 * (تخفیف هر پلن در صفحه «پلن‌ها» تنظیم می‌شود)
 */
require_once __DIR__ . '/_bootstrap.php';

$msg = '';
$now = date('Y-m-d H:i:s');
$date_or_null = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v . ' 23:59:59' : ''; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: discounts.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $act = $_POST['act'] ?? '';
    if ($act === 'auto') {
        biz_set($pdo, 'credit_discount_percent', max(0, min(90, (int)($_POST['credit_discount_percent'] ?? 0))));
        biz_set($pdo, 'credit_discount_until', $date_or_null($_POST['credit_discount_until'] ?? ''));
        biz_set($pdo, 'sms_discount_percent', max(0, min(90, (int)($_POST['sms_discount_percent'] ?? 0))));
        biz_set($pdo, 'sms_discount_until', $date_or_null($_POST['sms_discount_until'] ?? ''));
        $msg = 'ok:تخفیف‌های خودکار ذخیره شد.';
    } elseif ($act === 'code_save') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['code'] ?? '')));
        $kind = ($_POST['kind'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $val = max(1, (int)str_replace(',', '', (string)($_POST['value'] ?? '0')));
        if ($kind === 'percent') $val = min(100, $val);
        $applies = in_array($_POST['applies_to'] ?? '', ['all', 'plan', 'credit', 'sms'], true) ? $_POST['applies_to'] : 'all';
        $edit_id = (int)($_POST['code_id'] ?? 0);
        $vals = [
            $code, mb_substr(trim($_POST['title'] ?? ''), 0, 150), $kind, $val, $applies,
            $applies === 'plan' || $applies === 'all' ? (int)($_POST['plan_id'] ?? 0) : 0,
            max(0, (int)($_POST['max_uses'] ?? 0)), max(0, (int)($_POST['per_user_limit'] ?? 1)),
            max(0, (int)str_replace(',', '', (string)($_POST['min_amount'] ?? '0'))),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['starts_at'] ?? '')) ? $_POST['starts_at'] . ' 00:00:00' : null,
            $date_or_null($_POST['ends_at'] ?? '') ?: null,
            max(0, (int)($_POST['affiliate_id'] ?? 0)),
            !empty($_POST['is_active']) ? 1 : 0,
        ];
        if (strlen($code) < 3) $msg = 'error:کد باید حداقل ۳ حرف یا عدد انگلیسی باشد.';
        else {
            $du = $pdo->prepare("SELECT id FROM saas_discount_codes WHERE code=? AND id<>?");
            $du->execute([$code, $edit_id]);
            if ($du->fetchColumn()) $msg = 'error:این کد قبلاً ساخته شده است.';
            elseif ($edit_id) {
                $pdo->prepare("UPDATE saas_discount_codes SET code=?, title=?, kind=?, value=?, applies_to=?, plan_id=?, max_uses=?, per_user_limit=?, min_amount=?, starts_at=?, ends_at=?, affiliate_id=?, is_active=? WHERE id=?")
                    ->execute(array_merge($vals, [$edit_id]));
                $msg = 'ok:کد تخفیف ویرایش شد.';
            } else {
                $pdo->prepare("INSERT INTO saas_discount_codes (code, title, kind, value, applies_to, plan_id, max_uses, per_user_limit, min_amount, starts_at, ends_at, affiliate_id, is_active, used_count, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)")
                    ->execute(array_merge($vals, [$now]));
                $msg = 'ok:کد تخفیف «' . $code . '» ساخته شد.';
            }
        }
    } elseif ($act === 'code_toggle') {
        $pdo->prepare("UPDATE saas_discount_codes SET is_active = 1 - is_active WHERE id=?")->execute([(int)($_POST['code_id'] ?? 0)]);
        $msg = 'ok:وضعیت کد تغییر کرد.';
    } elseif ($act === 'code_del') {
        $pdo->prepare("DELETE FROM saas_discount_codes WHERE id=?")->execute([(int)($_POST['code_id'] ?? 0)]);
        $msg = 'ok:کد حذف شد.';
    }
    header('Location: discounts.php?msg=' . urlencode($msg)); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];

$bs = biz_settings($pdo, true);
$plans = saas_get_plans($pdo, false);
$plan_names = [];
foreach ($plans as $p) $plan_names[(int)$p['id']] = $p['name'];
$affs = $pdo->query("SELECT id, name, ref_code FROM saas_affiliates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$aff_names = [];
foreach ($affs as $a) $aff_names[(int)$a['id']] = $a['name'] . ' (' . $a['ref_code'] . ')';
$edit = null;
if (!empty($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM saas_discount_codes WHERE id=?");
    $e->execute([(int)$_GET['edit']]);
    $edit = $e->fetch(PDO::FETCH_ASSOC) ?: null;
}
$codes = $pdo->query("SELECT * FROM saas_discount_codes ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$applies_label = ['all' => 'همه خریدها', 'plan' => 'خرید پلن', 'credit' => 'شارژ اعتبار', 'sms' => 'خرید پیامک'];

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🎟️ تخفیف‌ها و کدهای تخفیف</h2>';
?>
<?php if ($msg): ?>
<div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">🔥 تخفیف خودکار (بدون نیاز به کد)</h3>
    <p class="muted" style="margin-bottom:12px">تخفیف هر پلن در صفحه <a href="plans.php">پلن‌ها</a> (ویرایش پلن) تنظیم می‌شود. اینجا تخفیف عمومی شارژ اعتبار و خرید پیامک را تعیین کنید.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="auto">
        <div class="row2">
            <div><label>تخفیف شارژ اعتبار (درصد)</label><input type="number" name="credit_discount_percent" min="0" max="90" value="<?php echo (int)$bs['credit_discount_percent']; ?>"></div>
            <div><label>تا تاریخ (خالی = بدون محدودیت)</label><input type="date" name="credit_discount_until" value="<?php echo $bs['credit_discount_until'] ? substr($bs['credit_discount_until'], 0, 10) : ''; ?>"></div>
        </div>
        <div class="row2">
            <div><label>تخفیف خرید پیامک (درصد)</label><input type="number" name="sms_discount_percent" min="0" max="90" value="<?php echo (int)$bs['sms_discount_percent']; ?>"></div>
            <div><label>تا تاریخ (خالی = بدون محدودیت)</label><input type="date" name="sms_discount_until" value="<?php echo $bs['sms_discount_until'] ? substr($bs['sms_discount_until'], 0, 10) : ''; ?>"></div>
        </div>
        <button class="btn btn-primary btn-sm" style="margin-top:12px">💾 ذخیره</button>
    </form>
</div>

<div class="card" id="form">
    <h3 style="font-size:15px;margin-bottom:12px"><?php echo $edit ? '✏️ ویرایش کد تخفیف' : '➕ کد تخفیف جدید'; ?></h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="code_save">
        <?php if ($edit): ?><input type="hidden" name="code_id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
        <div class="row2">
            <div><label>کد (حروف و اعداد انگلیسی) *</label><input type="text" name="code" required dir="ltr" maxlength="40" style="text-transform:uppercase" value="<?php echo admin_h($edit['code'] ?? ''); ?>" placeholder="YALDA1405"></div>
            <div><label>عنوان داخلی (اختیاری)</label><input type="text" name="title" maxlength="150" value="<?php echo admin_h($edit['title'] ?? ''); ?>" placeholder="مثلاً: کمپین شب یلدا"></div>
        </div>
        <div class="row2">
            <div><label>مقدار تخفیف *</label>
                <div style="display:flex;gap:6px"><input type="number" name="value" min="1" required value="<?php echo (int)($edit['value'] ?? 20); ?>" style="flex:1">
                    <select name="kind" style="width:130px !important"><option value="percent" <?php echo ($edit['kind'] ?? '') !== 'fixed' ? 'selected' : ''; ?>>درصد</option><option value="fixed" <?php echo ($edit['kind'] ?? '') === 'fixed' ? 'selected' : ''; ?>>تومان</option></select></div></div>
            <div><label>قابل استفاده برای</label>
                <select name="applies_to"><?php foreach ($applies_label as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($edit['applies_to'] ?? 'all') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="row2">
            <div><label>فقط برای پلن</label>
                <select name="plan_id"><option value="0">همه پلن‌ها</option><?php foreach ($plans as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo (int)($edit['plan_id'] ?? 0) === (int)$p['id'] ? 'selected' : ''; ?>><?php echo admin_h($p['name']); ?></option><?php endforeach; ?></select></div>
            <div><label>حداقل مبلغ خرید (تومان؛ ۰ = ندارد)</label><input type="number" name="min_amount" min="0" value="<?php echo (int)($edit['min_amount'] ?? 0); ?>"></div>
        </div>
        <div class="row2">
            <div><label>حداکثر تعداد کل استفاده (۰ = نامحدود)</label><input type="number" name="max_uses" min="0" value="<?php echo (int)($edit['max_uses'] ?? 0); ?>"></div>
            <div><label>دفعات مجاز برای هر کاربر (۰ = نامحدود)</label><input type="number" name="per_user_limit" min="0" value="<?php echo (int)($edit['per_user_limit'] ?? 1); ?>"></div>
        </div>
        <div class="row2">
            <div><label>شروع از تاریخ (خالی = از الان)</label><input type="date" name="starts_at" value="<?php echo !empty($edit['starts_at']) ? substr($edit['starts_at'], 0, 10) : ''; ?>"></div>
            <div><label>پایان در تاریخ (خالی = بدون محدودیت)</label><input type="date" name="ends_at" value="<?php echo !empty($edit['ends_at']) ? substr($edit['ends_at'], 0, 10) : ''; ?>"></div>
        </div>
        <div class="row2">
            <div><label>متعلق به همکار فروش (پورسانت خریدهای این کد به او می‌رسد)</label>
                <select name="affiliate_id"><option value="0">— هیچ‌کدام —</option><?php foreach ($affs as $a): ?><option value="<?php echo (int)$a['id']; ?>" <?php echo (int)($edit['affiliate_id'] ?? 0) === (int)$a['id'] ? 'selected' : ''; ?>><?php echo admin_h($a['name'] . ' (' . $a['ref_code'] . ')'); ?></option><?php endforeach; ?></select></div>
            <div style="display:flex;align-items:flex-end"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" style="width:17px!important;height:17px" <?php echo !$edit || !empty($edit['is_active']) ? 'checked' : ''; ?>> فعال</label></div>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px">
            <button class="btn btn-primary btn-sm"><?php echo $edit ? '💾 ذخیره' : '➕ ساخت کد'; ?></button>
            <?php if ($edit): ?><a href="discounts.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">انصراف</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:12px">📋 کدهای تخفیف (<?php echo count($codes); ?>)</h3>
    <?php if (!$codes): ?><p class="muted" style="text-align:center;padding:16px">هنوز کدی ساخته نشده است.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>کد</th><th>تخفیف</th><th>برای</th><th>استفاده</th><th>مهلت</th><th>همکار</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($codes as $c): $expired = $c['ends_at'] && strtotime($c['ends_at']) < time(); ?>
        <tr>
            <td><b dir="ltr"><?php echo admin_h($c['code']); ?></b><?php if ($c['title'] !== ''): ?><div style="font-size:11px;color:#64748b"><?php echo admin_h($c['title']); ?></div><?php endif; ?></td>
            <td><?php echo $c['kind'] === 'fixed' ? number_format((int)$c['value']) . ' تومان' : (int)$c['value'] . '٪'; ?></td>
            <td style="font-size:12px"><?php echo $applies_label[$c['applies_to']] ?? $c['applies_to']; ?><?php echo (int)$c['plan_id'] ? '<br><small>' . admin_h($plan_names[(int)$c['plan_id']] ?? '') . '</small>' : ''; ?></td>
            <td><?php echo (int)$c['used_count'] . ((int)$c['max_uses'] ? ' / ' . (int)$c['max_uses'] : ''); ?></td>
            <td style="font-size:12px"><?php echo $c['ends_at'] ? biz_jdate($c['ends_at']) : '—'; ?><?php echo $expired ? ' <span style="color:#dc2626">(تمام‌شده)</span>' : ''; ?></td>
            <td style="font-size:12px"><?php echo (int)$c['affiliate_id'] ? admin_h($aff_names[(int)$c['affiliate_id']] ?? '#' . $c['affiliate_id']) : '—'; ?></td>
            <td><span class="<?php echo $c['is_active'] ? 'badge-ok' : 'badge-off'; ?>"><?php echo $c['is_active'] ? 'فعال' : 'غیرفعال'; ?></span></td>
            <td style="white-space:nowrap">
                <a href="discounts.php?edit=<?php echo (int)$c['id']; ?>#form" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">ویرایش</a>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="code_toggle"><input type="hidden" name="code_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm" style="background:#f1f5f9;color:#475569"><?php echo $c['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="code_del"><input type="hidden" name="code_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
