<?php
/**
 * پاپ‌آپ‌های مدیر: نمایش در پنل کاربران (همه / پلن خاص / صفحه خاص) یا سایت اصلی
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/extras_ui.php';
try { ex_ensure_schema($pdo); } catch (\Throwable $e) {}

$targets = ['panel' => '🧑‍💻 پنل کاربران', 'site' => '🌐 سایت اصلی (بازدیدکنندگان)'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: popups.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $msg = ex_popups_handle($pdo, 0, array_keys($targets), $_POST, $_FILES['image'] ?? null);
    $back = 'popups.php';
    if (($_POST['act'] ?? '') === 'popup_save' && str_starts_with((string)$msg, 'error:') && !empty($_POST['popup_id'])) $back .= '?edit=' . (int)$_POST['popup_id'];
    header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&') . 'msg=' . urlencode((string)$msg)); exit;
}
$msg = (string)($_GET['msg'] ?? '');
$rows = ex_popups_list($pdo, 0);
$edit = null;
foreach ($rows as $r) if ((int)$r['id'] === (int)($_GET['edit'] ?? 0)) $edit = $r;
$plans = saas_get_plans($pdo, false);

include __DIR__ . '/_header.php';
?>
<h2 class="page-title">🪧 پاپ‌آپ‌ها</h2>
<?php if ($msg !== ''): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>
<div class="card" id="popform">
    <h3 style="font-size:15px;margin-bottom:6px"><?php echo $edit ? '✏️ ویرایش پاپ‌آپ' : '➕ پاپ‌آپ جدید'; ?></h3>
    <p class="muted" style="line-height:2;margin-bottom:10px">پاپ‌آپ می‌تواند در <b>پنل کاربران</b> (برای همه، کاربران یک پلن خاص یا در صفحه‌های خاص) یا در <b>سایت اصلی</b> برای بازدیدکنندگان نمایش داده شود. کاربران هم در پنل خودشان می‌توانند برای مخاطبان چت‌بات و ویجت سایتشان پاپ‌آپ بسازند.</p>
    <?php echo ex_popup_form_ui($edit, $targets, $csrf_token, 'popups.php', ['plans' => $plans]); ?>
</div>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">📋 پاپ‌آپ‌های تعریف‌شده</h3>
    <?php echo ex_popups_table_ui($rows, 'popups.php', $csrf_token, [], $plans); ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
