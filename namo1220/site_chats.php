<?php
/**
 * گزارش گفتگوهای بازدیدکنندگان با دستیار صفحه اصلی (نسخه ۴۱)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/sitechat_lib.php';
sch_ensure_schema($pdo);

$sid = preg_replace('/[^a-f0-9]/', '', (string)($_GET['sid'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'purge' && verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $days = max(1, min(3650, (int)($_POST['days'] ?? 90)));
    $pdo->prepare("DELETE FROM site_chat_log WHERE created_at < ?")->execute([date('Y-m-d H:i:s', time() - $days * 86400)]);
    header('Location: site_chats.php?msg=' . urlencode('گفتگوهای قدیمی‌تر از ' . $days . ' روز حذف شد.')); exit;
}
$stats = ['today' => 0, 'week' => 0, 'msgs' => 0];
try {
    $q = $pdo->prepare("SELECT COUNT(DISTINCT sid) FROM site_chat_log WHERE created_at >= ?");
    $q->execute([date('Y-m-d 00:00:00')]); $stats['today'] = (int)$q->fetchColumn();
    $q->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]); $stats['week'] = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM site_chat_log WHERE role='user' AND created_at >= ?");
    $q->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]); $stats['msgs'] = (int)$q->fetchColumn();
} catch (\Throwable $e) {}
$rows = [];
$conv = [];
try {
    if ($sid !== '') {
        $q = $pdo->prepare("SELECT * FROM site_chat_log WHERE sid=? ORDER BY id ASC LIMIT 400");
        $q->execute([$sid]); $conv = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = $pdo->query("SELECT sid, MIN(created_at) AS first_at, MAX(created_at) AS last_at, SUM(CASE WHEN role='user' THEN 1 ELSE 0 END) AS n, MAX(ip) AS ip,
                                    (SELECT content FROM site_chat_log x WHERE x.sid=l.sid AND x.role='user' ORDER BY x.id ASC LIMIT 1) AS first_msg
                             FROM site_chat_log l GROUP BY sid ORDER BY MAX(id) DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable $e) { error_log('[SCH] admin: ' . $e->getMessage()); }
include __DIR__ . '/_header.php';
?>
<h2 class="page-title">💬 گفتگوهای صفحه اصلی</h2>
<?php if (!empty($_GET['msg'])): ?><div class="msg-ok"><?php echo admin_h($_GET['msg']); ?></div><?php endif; ?>
<p style="color:#64748b;font-size:13px;margin:-6px 0 14px">گفتگوی بازدیدکنندگان با دستیار صفحه اصلی سایت. تنظیمات و «دانش اضافه» دستیار: <a href="site_settings.php#sitechat-settings">تنظیمات سایت</a>. اگر دستیار سؤالی را بد جواب داده، پاسخ درست را به «دانش اضافه» یا «راهنما و سوالات متداول» اضافه کنید.</p>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
    <div class="card" style="padding:12px 16px;margin:0"><b><?php echo number_format($stats['today']); ?></b> گفتگو امروز</div>
    <div class="card" style="padding:12px 16px;margin:0"><b><?php echo number_format($stats['week']); ?></b> گفتگو در ۷ روز</div>
    <div class="card" style="padding:12px 16px;margin:0"><b><?php echo number_format($stats['msgs']); ?></b> پرسش در ۷ روز</div>
</div>
<?php if ($sid !== ''): ?>
<div class="card">
    <div class="card-header"><a href="site_chats.php">→ همه گفتگوها</a></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
        <?php if (!$conv): ?><p class="muted">پیامی نیست.</p><?php endif; ?>
        <?php foreach ($conv as $c): ?>
        <div style="max-width:85%;<?php echo $c['role'] === 'user' ? 'align-self:flex-start;background:#eff6ff;border:1px solid #bfdbfe' : 'align-self:flex-end;background:#f8fafc;border:1px solid #e2e8f0'; ?>;border-radius:12px;padding:8px 12px;font-size:13.5px;line-height:1.9;white-space:pre-wrap"><b style="font-size:11.5px;color:#64748b"><?php echo $c['role'] === 'user' ? '👤 بازدیدکننده' : '🤖 دستیار'; ?> — <?php echo admin_h(substr($c['created_at'], 0, 16)); ?></b>
<?php echo admin_h($c['content']); ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body" style="overflow-x:auto">
        <?php if (!$rows): ?><p class="muted">هنوز گفتگویی ثبت نشده است.</p><?php else: ?>
        <table class="table"><thead><tr><th>شروع</th><th>آخرین پیام</th><th>پرسش‌ها</th><th>اولین پرسش</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td style="direction:ltr;text-align:right;font-size:12px"><?php echo admin_h(substr($r['first_at'], 0, 16)); ?></td><td style="direction:ltr;text-align:right;font-size:12px"><?php echo admin_h(substr($r['last_at'], 0, 16)); ?></td>
                <td><?php echo (int)$r['n']; ?></td><td><?php echo admin_h(mb_strimwidth((string)$r['first_msg'], 0, 90, '…')); ?></td><td><a class="btn btn-sm" href="site_chats.php?sid=<?php echo admin_h($r['sid']); ?>">مشاهده</a></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <form method="post" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap" onsubmit="return confirm('گفتگوهای قدیمی حذف شوند؟')">
            <input type="hidden" name="act" value="purge"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
            <span style="font-size:13px">حذف گفتگوهای قدیمی‌تر از</span><input type="number" name="days" value="90" min="1" style="width:90px"><span style="font-size:13px">روز</span><button class="btn btn-sm">حذف</button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
