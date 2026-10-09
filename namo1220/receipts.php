<?php
/**
 * فیش‌های واریزی کاربران (کارت به کارت برای خرید پلن، اعتبار و پیامک) — نسخه ۵۶
 *  - بررسی فیش: تأیید (همان مسیر پرداخت موفق: فعال‌سازی پلن، اعتبار، پیامک، کمیسیون) یا رد با ذکر علت
 *  - شماره کارت سایت برای پرداخت کارت به کارت کاربران
 */
require_once __DIR__ . '/_bootstrap.php';
c2c_schema($pdo);
$tab = in_array($_GET['t'] ?? '', ['pending', 'history', 'card'], true) ? $_GET['t'] : 'pending';

if (isset($_GET['img'])) {
    $r = c2c_get($pdo, (int)$_GET['img']);
    if (!$r) { http_response_code(404); exit; }
    c2c_serve($r);   // مدیر همه فیش‌ها (کاربران و مخاطبان) را می‌بیند
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $back = 'receipts.php?t=' . ($act === 'card' ? 'card' : 'pending');
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: ' . $back . '&msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $msg = '';
    if ($act === 'approve') { $r = c2c_user_approve($pdo, (int)($_POST['id'] ?? 0), 'مدیر'); $msg = $r['ok'] ? 'ok:' . $r['message'] : 'error:' . $r['error']; }
    elseif ($act === 'reject') { $r = c2c_reject($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''), 'مدیر'); $msg = $r['ok'] ? 'ok:' . $r['message'] : 'error:' . $r['error']; }
    elseif ($act === 'card') { $e = c2c_cfg_save($pdo, 'site', $_POST); $msg = $e === '' ? 'ok:اطلاعات کارت ذخیره شد.' : 'error:' . $e; }
    header('Location: ' . $back . '&msg=' . urlencode($msg)); exit;
}
$msg = (string)($_GET['msg'] ?? '');
$cfg = c2c_cfg($pdo, 'site');
$pending = c2c_list($pdo, ['scope' => 'user', 'status' => 'pending'], 300);
$uname = [];
$user = function ($id) use ($pdo, &$uname) {
    if (!isset($uname[$id])) { $u = saas_get_user($pdo, (int)$id); $uname[$id] = $u ?: ['full_name' => '—', 'phone' => '', 'email' => '']; }
    return $uname[$id];
};
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🧾 فیش‌های واریزی</h2>';
?>
<style>
.gt{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.gt a{padding:8px 14px;border-radius:10px;background:#fff;border:1px solid #e2e8f0;color:#475569;text-decoration:none;font-weight:600;font-size:13px}.gt a.on{background:#2563eb;color:#fff;border-color:#2563eb}
.rc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.rc{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.rc .img{display:block;background:#f1f5f9;height:220px}.rc .img img{width:100%;height:100%;object-fit:contain}
.rc .b{padding:10px 12px;font-size:13px;line-height:2;flex:1}.rc .a{display:flex;gap:6px;padding:0 12px 12px;flex-wrap:wrap}.rc .a input{flex:1;min-width:120px}
</style>
<?php if ($msg !== ''): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo $h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>
<div class="gt">
    <a href="receipts.php?t=pending" class="<?php echo $tab === 'pending' ? 'on' : ''; ?>">⏳ در انتظار بررسی<?php echo $pending ? ' (' . count($pending) . ')' : ''; ?></a>
    <a href="receipts.php?t=history" class="<?php echo $tab === 'history' ? 'on' : ''; ?>">📜 سوابق</a>
    <a href="receipts.php?t=card" class="<?php echo $tab === 'card' ? 'on' : ''; ?>">💳 کارت به کارت<?php echo $cfg['on'] ? ' ✓' : ''; ?></a>
</div>

<?php if ($tab === 'pending'): ?>
<div class="card">
    <?php if (!$cfg['on']): ?><p class="muted" style="margin-bottom:10px">کارت به کارت برای کاربران خاموش است (زبانه «💳 کارت به کارت»).</p><?php endif; ?>
    <?php if (!$pending): ?><p class="muted" style="text-align:center;padding:16px">فیش در انتظار بررسی نیست.</p><?php else: ?>
    <div class="rc-grid">
    <?php foreach ($pending as $r): $u = $user((int)$r['user_id']); ?>
        <div class="rc">
            <a class="img" href="receipts.php?img=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener"><img src="receipts.php?img=<?php echo (int)$r['id']; ?>" alt="فیش" loading="lazy"></a>
            <div class="b">
                <a href="users.php?view=<?php echo (int)$r['user_id']; ?>"><b><?php echo $h($u['full_name']); ?></b></a> <span dir="ltr" style="color:#64748b"><?php echo $h($u['phone']); ?></span><br>
                <?php echo $h($r['note'] ?: $r['plan_name']); ?><br>
                مبلغ: <b><?php echo number_format((int)$r['amount']); ?></b> تومان<br>
                <?php if ($r['tracking'] !== ''): ?>کد پیگیری: <span dir="ltr"><?php echo $h($r['tracking']); ?></span><br><?php endif; ?>
                <?php if ($r['payer'] !== ''): ?>واریزکننده: <?php echo $h($r['payer']); ?><br><?php endif; ?>
                <span class="muted"><?php echo biz_jdate($r['created_at'], true); ?></span>
            </div>
            <div class="a">
                <form method="post" style="display:inline" onsubmit="return confirm('واریز را در حساب بررسی کرده‌اید؟ با تأیید، خرید کاربر انجام می‌شود.');"><input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>"><input type="hidden" name="act" value="approve"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-primary btn-sm">✅ تأیید</button></form>
                <form method="post" style="display:flex;gap:6px;flex:1"><input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>"><input type="hidden" name="act" value="reject"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="text" name="reason" maxlength="250" placeholder="علت رد (برای کاربر)"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">رد</button></form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'history'): $hist = c2c_list($pdo, ['scope' => 'user'], 500); ?>
<div class="card">
    <?php if (!$hist): ?><p class="muted" style="text-align:center;padding:16px">فیشی ثبت نشده است.</p><?php else: ?>
    <div style="overflow-x:auto"><table><thead><tr><th>تاریخ</th><th>کاربر</th><th>شرح</th><th>مبلغ</th><th>کد پیگیری</th><th>وضعیت</th><th>فیش</th></tr></thead><tbody>
    <?php foreach ($hist as $r): $u = $user((int)$r['user_id']); ?>
        <tr><td style="white-space:nowrap"><?php echo biz_jdate($r['created_at'], true); ?></td>
            <td><a href="users.php?view=<?php echo (int)$r['user_id']; ?>"><?php echo $h($u['full_name']); ?></a></td>
            <td><?php echo $h($r['note'] ?: $r['plan_name']); ?></td><td><?php echo number_format((int)$r['amount']); ?></td><td dir="ltr"><?php echo $h($r['tracking']); ?></td>
            <td><?php echo c2c_status_label($r['status']); ?><?php echo $r['source'] === 'staff' ? '<br><span style="font-size:11px;color:#64748b">ثبت دستی مدیر</span>' : ''; ?><?php echo $r['status'] === 'rejected' && $r['reason'] !== '' ? '<br><span style="font-size:11px;color:#64748b">' . $h($r['reason']) . '</span>' : ''; ?></td>
            <td><a href="receipts.php?img=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener">مشاهده</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">💳 کارت به کارت برای کاربران سایت</h3>
    <p class="muted" style="line-height:2;margin-bottom:10px">با فعال کردن این گزینه، در صفحه پرداخت (خرید پلن، شارژ اعتبار، پیامک و ...) دکمه «کارت به کارت» کنار درگاه آنلاین نمایش داده می‌شود؛ کاربر مبلغ را واریز می‌کند و تصویر فیش را می‌فرستد و بعد از تأیید شما در همین صفحه، خرید انجام می‌شود.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>"><input type="hidden" name="act" value="card">
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="on" value="1" <?php echo $cfg['on'] ? 'checked' : ''; ?> style="width:auto"> پرداخت کارت به کارت فعال باشد</label>
        <div class="row2" style="margin-top:8px">
            <div><label>شماره کارت (۱۶ رقم)</label><input type="text" name="card" dir="ltr" maxlength="25" value="<?php echo $h(c2c_card_fmt($cfg['card'])); ?>" placeholder="6037-9912-3456-7890"></div>
            <div><label>به نام</label><input type="text" name="name" maxlength="80" value="<?php echo $h($cfg['name']); ?>"></div>
        </div>
        <div class="row2">
            <div><label>بانک</label><input type="text" name="bank" maxlength="60" value="<?php echo $h($cfg['bank']); ?>"></div>
            <div><label>شماره شبا (اختیاری)</label><input type="text" name="sheba" dir="ltr" maxlength="30" value="<?php echo $h($cfg['sheba']); ?>"></div>
        </div>
        <label>توضیح برای کاربر (اختیاری)</label><input type="text" name="note" maxlength="400" value="<?php echo $h($cfg['note']); ?>">
        <div style="margin-top:10px"><button class="btn btn-primary btn-sm">💾 ذخیره</button></div>
    </form>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
