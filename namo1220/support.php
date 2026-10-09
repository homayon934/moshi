<?php
/**
 * پشتیبانی سامانه: تیکت‌های کاربران، راهنما و سوالات متداول (خوانده‌شده توسط پشتیبان هوشمند)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/extras_ui.php';
try { ex_ensure_schema($pdo); } catch (\Throwable $e) {}

$tabs = ['tickets' => '🎫 تیکت‌ها', 'guide' => '📘 راهنما', 'faq' => '❓ سوالات متداول', 'test' => '🤖 آزمایش پشتیبان هوشمند'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'tickets';
$self = 'support.php?tab=' . $tab;
$ai_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: ' . $self . '&msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $act = (string)($_POST['act'] ?? '');
    $msg = ex_articles_handle($pdo, 'admin', 0, $_POST);
    $back = $self;
    if ($msg === null && in_array($act, ['ticket_reply', 'ticket_status'], true)) {
        $t = ex_ticket_get($pdo, (int)($_POST['ticket_id'] ?? 0));
        if (!$t || $t['scope'] !== 'admin') $msg = 'error:تیکت یافت نشد.';
        else {
            $back = 'support.php?tab=tickets&t=' . (int)$t['id'];
            if ($act === 'ticket_reply') {
                $r = ex_ticket_reply($pdo, $t, 'admin', 'پشتیبانی', $_POST['body'] ?? '');
                if ($r['ok'] && !empty($_POST['close_after'])) ex_ticket_set_status($pdo, $t['id'], 'closed');
                $msg = $r['ok'] ? 'ok:پاسخ ارسال شد و به کاربر اطلاع داده شد.' : 'error:' . $r['error'];
            } else {
                ex_ticket_set_status($pdo, $t['id'], (string)($_POST['status'] ?? 'open'));
                $msg = 'ok:وضعیت تیکت تغییر کرد.';
            }
        }
    }
    if ($act === 'ai_test') {
        $q = trim((string)($_POST['question'] ?? ''));
        $ai_result = ['q' => $q, 'r' => ex_support_answer($pdo, 'admin', null, $q)];
    } else {
        if (in_array($act, ['art_save', 'art_del', 'art_toggle'], true) && ($_POST['kind'] ?? '') !== '') $back = 'support.php?tab=' . (($_POST['kind'] ?? '') === 'faq' ? 'faq' : 'guide');
        header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&') . 'msg=' . urlencode((string)$msg)); exit;
    }
}
$msg = (string)($_GET['msg'] ?? '');

include __DIR__ . '/_header.php';
?>
<h2 class="page-title">🎧 پشتیبانی و تیکت‌ها</h2>
<style>
.stabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px;border-bottom:2px solid #ede9fe}
.stabs a{padding:9px 14px;border-radius:10px 10px 0 0;font-size:13.5px;font-weight:600;color:#475569;text-decoration:none;background:#f8fafc;border:1px solid #e2e8f0;border-bottom:none;margin-bottom:-2px}
.stabs a.on{background:#fff;color:#6d28d9;border-color:#c4b5fd;border-bottom:2px solid #fff}
.sfil{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.sfil a{padding:5px 12px;border-radius:14px;background:#f1f5f9;color:#475569;text-decoration:none;font-size:12.5px}
.sfil a.on{background:#7c3aed;color:#fff}
</style>
<?php if ($msg !== ''): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>
<nav class="stabs"><?php foreach ($tabs as $k => $l): ?><a href="support.php?tab=<?php echo $k; ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><?php echo $l; ?><?php if ($k === 'tickets' && ($n = ex_unread_count($pdo, 'admin'))): ?> <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:11px"><?php echo $n; ?></span><?php endif; ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'tickets'):
    $t = !empty($_GET['t']) ? ex_ticket_get($pdo, (int)$_GET['t']) : null;
    if ($t && $t['scope'] === 'admin'):
        $pdo->prepare("UPDATE ex_tickets SET unread_admin=0 WHERE id=?")->execute([(int)$t['id']]);
        $u = saas_get_user($pdo, (int)$t['user_id']);
?>
    <div class="card" style="font-size:13px;line-height:2">👤 <b><?php echo admin_h($u['full_name'] ?? '—'); ?></b> — <?php echo admin_h($u['email'] ?? ''); ?> <?php echo !empty($u['phone']) ? '— <span dir="ltr">' . admin_h($u['phone']) . '</span>' : ''; ?> — پلن: <?php echo admin_h($u['plan_name'] ?? 'ندارد'); ?> — <a href="users.php?view=<?php echo (int)$t['user_id']; ?>">مشاهده کاربر</a></div>
    <?php echo ex_ticket_thread_ui($t, ex_ticket_msgs($pdo, $t['id']), 'admin', $csrf_token, 'support.php?tab=tickets', true); ?>
<?php else:
    $f = $_GET['f'] ?? 'active';
    $where = ['active' => "t.status<>'closed'", 'open' => "t.status='open'", 'answered' => "t.status='answered'", 'closed' => "t.status='closed'", 'all' => '1=1'][$f] ?? "t.status<>'closed'";
    $rows = $pdo->query("SELECT t.*, u.full_name, u.email FROM ex_tickets t LEFT JOIN saas_users u ON u.id=t.user_id WHERE t.scope='admin' AND $where ORDER BY t.unread_admin DESC, t.updated_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) $r['_who'] = trim(($r['full_name'] ?? '') . ' — ' . ($r['email'] ?? ''), ' —');
    unset($r);
?>
    <div class="card">
        <div class="sfil"><?php foreach (['active' => 'باز و در جریان', 'open' => 'در انتظار پاسخ', 'answered' => 'پاسخ داده شده', 'closed' => 'بسته شده', 'all' => 'همه'] as $k => $l): ?><a href="support.php?tab=tickets&f=<?php echo $k; ?>" class="<?php echo $f === $k ? 'on' : ''; ?>"><?php echo $l; ?></a><?php endforeach; ?></div>
        <?php echo ex_tickets_table_ui($rows, 'support.php?tab=tickets', 'unread_admin', 'کاربر'); ?>
    </div>
<?php endif; ?>

<?php elseif ($tab === 'guide' || $tab === 'faq'): ?>
    <?php echo ex_articles_ui($pdo, 'admin', 0, $tab, 'support.php?tab=' . $tab, $csrf_token); ?>

<?php else: ?>
    <div class="card">
        <p class="muted" style="line-height:2;margin-bottom:10px">سوالی بپرسید تا ببینید پشتیبان هوشمند (بر اساس راهنما، سوالات متداول و پلن‌ها) به کاربران چه جوابی می‌دهد.</p>
        <form method="post" data-pg="aitest">
            <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="ai_test">
            <textarea name="question" rows="3" required placeholder="مثلاً: چطور چت‌بات را روی سایتم نصب کنم؟"><?php echo admin_h($ai_result['q'] ?? ''); ?></textarea>
            <button class="btn btn-primary btn-sm" style="margin-top:8px">🤖 پرسیدن</button>
        </form>
        <?php if ($ai_result): $rr = $ai_result['r']; ?>
        <div style="margin-top:14px;background:#f8fafc;border-radius:12px;padding:12px 14px;white-space:pre-wrap;line-height:2;font-size:13.5px"><?php echo admin_h($rr['ok'] ? $rr['reply'] : $rr['error']); ?></div>
        <?php if (!empty($rr['ticket'])): ?><p style="font-size:12px;color:#b45309;margin-top:6px">↳ در این حالت به کاربر پیشنهاد ثبت تیکت نمایش داده می‌شود.</p><?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
