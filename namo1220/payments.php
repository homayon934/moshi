<?php
require_once __DIR__ . '/_bootstrap.php';

$search = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($search) {
    $like = '%' . $search . '%';
    $where .= " AND (u.email LIKE ? OR u.full_name LIKE ? OR py.ref_code LIKE ?)";
    $params = array_merge($params, [$like, $like, $like]);
}
if ($status_filter && in_array($status_filter, ['pending','paid','failed','refunded'])) {
    $where .= " AND py.status=?";
    $params[] = $status_filter;
}
$day_filter = (($_GET['day'] ?? '') === 'today') ? 'today' : '';
if ($day_filter) {
    $where .= " AND DATE(COALESCE(py.paid_at, py.created_at))=CURDATE()";
}

$stmt = $pdo->prepare("SELECT py.*, u.email, u.full_name, pl.name as plan_name
    FROM saas_payments py
    LEFT JOIN saas_users u ON u.id=py.user_id
    LEFT JOIN saas_plans pl ON pl.id=py.plan_id
    $where ORDER BY py.id DESC LIMIT 100");
$stmt->execute($params);
$payments = $stmt->fetchAll();

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">💳 مدیریت پرداخت‌ها</h2>';
?>

<div class="card">
    <form method="get" style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <input type="text" name="q" value="<?php echo admin_h($search); ?>" placeholder="جستجو (ایمیل، نام، کد پیگیری)..." style="flex:1;min-width:200px">
        <select name="status" style="width:170px !important">
            <option value="">همه وضعیت‌ها</option>
            <option value="paid" <?php echo $status_filter==='paid'?'selected':''; ?>>موفق</option>
            <option value="pending" <?php echo $status_filter==='pending'?'selected':''; ?>>معلق</option>
            <option value="failed" <?php echo $status_filter==='failed'?'selected':''; ?>>ناموفق</option>
        </select>
        <?php if ($day_filter): ?><input type="hidden" name="day" value="today"><span class="badge badge-blue" style="align-self:center">فقط امروز</span><?php endif; ?>
        <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff">جستجو</button>
        <?php if ($search || $status_filter || $day_filter): ?><a href="payments.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">پاک کردن</a><?php endif; ?>
    </form>

    <table>
        <thead><tr><th>#</th><th>کاربر</th><th>نوع</th><th>مبلغ (تومان)</th><th>اعتبار (تومان)</th><th>وضعیت</th><th>کد پیگیری</th><th>تاریخ</th></tr></thead>
        <tbody>
        <?php if (empty($payments)): ?>
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:30px">پرداختی یافت نشد.</td></tr>
        <?php else: ?>
        <?php foreach ($payments as $py): ?>
        <tr>
            <td style="color:#94a3b8">#<?php echo $py['id']; ?></td>
            <td>
                <div style="font-size:13px"><?php echo admin_h($py['full_name']); ?></div>
                <div style="font-size:11px;color:#94a3b8;direction:ltr;text-align:right"><?php echo admin_h($py['email']); ?></div>
            </td>
            <td style="font-size:12px"><?php echo $py['type']==='plan' ? 'پلن: '.admin_h($py['plan_name']??'') : 'شارژ اعتبار'; ?></td>
            <td><?php echo number_format($py['amount_toman']); ?></td>
            <td><?php echo number_format($py['credit_tokens']); ?></td>
            <td>
                <span class="<?php echo $py['status']==='paid'?'badge-ok':($py['status']==='failed'?'badge-err':'badge-off'); ?>">
                    <?php echo ['pending'=>'معلق','paid'=>'موفق','failed'=>'ناموفق','refunded'=>'بازگشت'][$py['status']]??$py['status']; ?>
                </span>
            </td>
            <td style="font-size:11px;direction:ltr;text-align:right;color:#64748b"><?php echo admin_h($py['ref_code'] ?? '—'); ?></td>
            <td style="font-size:12px;color:#94a3b8"><?php echo substr($py['created_at'],0,10); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
