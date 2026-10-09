<?php
/**
 * پایگاه دانش مرکزی چت‌بات‌ها
 * مدیر سامانه مجموعه‌های دانش می‌سازد (متن، فایل، صفحه یا سایت) و کاربران آن‌ها را به ربات خود وصل می‌کنند.
 * منابع هر مجموعه در hd_sources / hd_chunks با bot_id = -شناسه مجموعه ذخیره می‌شوند.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
try { hd_ensure_schema($pdo); if (function_exists('biz_ensure_schema')) biz_ensure_schema($pdo); if (function_exists('kb_ensure_schema')) kb_ensure_schema($pdo); } catch (\Throwable $e) {}
$tpls = hd_templates();
$tpl_names = [];
foreach ($tpls as $k => $t) $tpl_names[$k] = $t['label'];

$msg = '';
$set_id = (int)($_GET['set'] ?? ($_POST['set_id'] ?? 0));
$back = 'kb.php' . ($set_id ? '?set=' . $set_id . '&' : '?');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: ' . $back . 'msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $act = $_POST['act'] ?? '';
    $kb = -abs($set_id);   // شناسه دانش مجموعه
    if ($act === 'set_save') {
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 190);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 490);
        $active = !empty($_POST['is_active']) ? 1 : 0;
        $sort = (int)($_POST['sort_order'] ?? 0);
        $tsel = implode(',', array_values(array_intersect(array_keys($tpl_names), (array)($_POST['templates'] ?? []))));
        if (mb_strlen($title) < 2) $msg = 'error:عنوان مجموعه را وارد کنید.';
        elseif ($set_id) { $pdo->prepare("UPDATE hd_central_sets SET title=?, description=?, is_active=?, sort_order=? WHERE id=?")->execute([$title, $desc, $active, $sort, $set_id]); $msg = 'ok:مجموعه ذخیره شد.'; }
        else {
            $pdo->prepare("INSERT INTO hd_central_sets (title, description, is_active, sort_order, created_at) VALUES(?,?,?,?,?)")->execute([$title, $desc, $active, $sort, hd_now()]);
            $set_id = (int)$pdo->lastInsertId(); $back = 'kb.php?set=' . $set_id . '&';
            $msg = 'ok:مجموعه ساخته شد؛ حالا منابع آن را اضافه کنید.';
        }
        if ($set_id && mb_strlen($title) >= 2) { try { $pdo->prepare("UPDATE hd_central_sets SET templates=? WHERE id=?")->execute([$tsel, $set_id]); } catch (\Throwable $e) {} }
    } elseif (($act === 'set_del' || $act === 'del_source') && !(function_exists('ui_del2_ok') && ui_del2_ok())) {
        // حذف دومرحله‌ای: بدون تأیید نهایی (نوشتن «حذف») چیزی پاک نمی‌شود
        $msg = 'error:حذف تأیید نشد؛ برای حذف باید در مرحله دوم کلمه «حذف» را بنویسید.';
    } elseif ($act === 'set_del' && $set_id) {
        foreach ($pdo->query("SELECT id FROM hd_sources WHERE bot_id=" . $kb)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) hd_delete_source($pdo, (int)$sid, $kb);
        $pdo->prepare("DELETE FROM hd_central_sets WHERE id=?")->execute([$set_id]);
        header('Location: kb.php?msg=' . urlencode('ok:مجموعه و منابع آن حذف شد.')); exit;
    } elseif ($set_id && $act === 'add_text') {
        $title = trim($_POST['title'] ?? ''); $content = trim($_POST['content'] ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($content) < 30) $msg = 'error:عنوان و متن (حداقل ۳۰ کاراکتر) را وارد کنید.';
        else { $sid = hd_add_source($pdo, $kb, 'text', $title, '', $content); hd_sync_source($pdo, $sid); $msg = 'ok:متن اضافه شد.'; }
    } elseif ($set_id && $act === 'add_file') {
        $f = $_FILES['doc'] ?? null;
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) $msg = 'error:فایلی انتخاب نشده است.';
        elseif ($f['size'] > 20 * 1024 * 1024) $msg = 'error:حجم فایل بیشتر از ۲۰ مگابایت است.';
        elseif (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['pdf', 'docx', 'txt'], true)) $msg = 'error:فقط PDF، Word (.docx) و .txt مجاز است.';
        else {
            @set_time_limit(180);
            $text = hd_extract_file($f['tmp_name'], $f['name']);
            if (mb_strlen(trim($text)) < 30) $msg = 'error:متنی از این فایل خوانده نشد (PDF اسکن‌شده قابل خواندن نیست).';
            else {
                $title = trim($_POST['title'] ?? '') ?: pathinfo($f['name'], PATHINFO_FILENAME);
                $sid = hd_add_source($pdo, $kb, 'file', $title, '', mb_substr($text, 0, 3000000));
                hd_sync_source($pdo, $sid);
                $msg = 'ok:فایل خوانده شد (' . number_format(mb_strlen($text)) . ' کاراکتر).';
            }
        }
    } elseif ($set_id && $act === 'add_url') {
        $url = trim($_POST['url'] ?? '');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) $msg = 'error:آدرس معتبر نیست.';
        else {
            $kind = ($_POST['kind'] ?? 'page') === 'site' ? 'site' : 'page';
            $sid = hd_add_source($pdo, $kb, $kind, trim($_POST['title'] ?? '') ?: (string)parse_url($url, PHP_URL_HOST), $url);
            @set_time_limit(120);
            $r = hd_sync_source($pdo, $sid, 30, 40);
            $msg = $r['error'] !== '' ? 'error:' . $r['error'] : 'ok:' . $r['done'] . ' صفحه خوانده شد' . ($r['remaining'] > 0 ? '؛ ' . $r['remaining'] . ' صفحه باقی مانده (همگام‌سازی را بزنید).' : '.');
        }
    } elseif ($set_id && $act === 'sync') {
        @set_time_limit(120);
        $r = hd_sync_source($pdo, (int)($_POST['source_id'] ?? 0), 40, 45);
        $msg = $r['error'] !== '' ? 'error:' . $r['error'] : 'ok:' . $r['done'] . ' صفحه خوانده شد' . ($r['remaining'] > 0 ? '؛ ' . $r['remaining'] . ' صفحه باقی مانده.' : '.');
    } elseif ($act === 'set_toggle' && $set_id) {
        $pdo->prepare("UPDATE hd_central_sets SET is_active = 1 - is_active WHERE id=?")->execute([$set_id]);
        header('Location: kb.php?msg=' . urlencode('ok:وضعیت مجموعه تغییر کرد.')); exit;
    } elseif ($set_id && $act === 'src_toggle') {
        $v = hd_source_toggle($pdo, (int)($_POST['source_id'] ?? 0), $kb);
        $msg = $v === null ? 'error:منبع یافت نشد.' : ($v ? 'ok:منبع فعال شد.' : 'ok:منبع غیرفعال شد و تا فعال‌سازی دوباره در پاسخ‌ها استفاده نمی‌شود.');
    } elseif ($set_id && $act === 'src_edit') {
        $sid = (int)($_POST['source_id'] ?? 0);
        $r = hd_source_update($pdo, $sid, $kb, $_POST);
        if (!$r['ok']) { header('Location: ' . $back . 'edit_src=' . $sid . '&msg=' . urlencode('error:' . $r['error'])); exit; }
        $msg = 'ok:' . $r['msg'];
    } elseif ($set_id && $act === 'del_source') {
        hd_delete_source($pdo, (int)($_POST['source_id'] ?? 0), $kb);
        $msg = 'ok:منبع حذف شد.';
    }
    header('Location: ' . $back . 'msg=' . urlencode($msg)); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];

$sets = hd_central_sets($pdo, false);
$kset = null;
foreach ($sets as $x) if ((int)$x['id'] === $set_id) $kset = $x;
// تعداد ربات‌هایی که هر مجموعه را استفاده می‌کنند (انتخاب دستی + خودکار بر اساس نوع ربات)
$usage = [];
try {
    foreach ($pdo->query("SELECT id, template, central_sets, central_off FROM hd_bots")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b)
        foreach ($sets as $x) {
            $sid = (int)$x['id'];
            $man = in_array($sid, array_map('intval', array_filter(explode(',', (string)$b['central_sets']))), true);
            $auto = in_array((string)$b['template'], kb_set_templates($x), true) && !in_array($sid, kb_bot_off_sets($b), true);
            if ($man || $auto) $usage[$sid] = ($usage[$sid] ?? 0) + 1;
        }
} catch (\Throwable $e) {}
$cat = (string)($_GET['cat'] ?? '');
$tpl_checks = function ($cur) use ($tpl_names) {
    $h = '<label style="margin-top:10px">مخصوص کدام نوع چت‌بات‌ها؟ <span class="muted">(برای ربات‌های این نوع خودکار فعال می‌شود؛ صاحب ربات می‌تواند خاموشش کند. اگر هیچ‌کدام انتخاب نشود، «عمومی» است و کاربر خودش تیک می‌زند.)</span></label><div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:4px">';
    foreach ($tpl_names as $k => $l) $h .= '<label style="display:flex;gap:6px;align-items:center;font-weight:normal"><input type="checkbox" name="templates[]" value="' . admin_h($k) . '" ' . (in_array($k, $cur, true) ? 'checked' : '') . ' style="width:16px!important;height:16px"> ' . admin_h($l) . '</label>';
    return $h . '</div>';
};

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">📚 پایگاه دانش مرکزی چت‌بات‌ها</h2>';
?>
<?php if ($msg): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>

<?php if (!$set_id || !$kset): ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">➕ مجموعه دانش جدید</h3>
    <p class="muted" style="margin-bottom:10px;line-height:2">مثلاً «قوانین کار ایران»، «راهنمای تغذیه»، «دانش پایه روان‌شناسی». اگر مجموعه را مخصوص یک نوع چت‌بات (مثلاً مشاور پزشکی) کنید، برای همه ربات‌های آن نوع (فعلی و جدید) خودکار فعال می‌شود. مجموعه‌های «عمومی» را کاربران خودشان با یک تیک به ربات وصل می‌کنند.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="set_save">
        <div class="row2">
            <div><label>عنوان *</label><input type="text" name="title" maxlength="190" required></div>
            <div><label>ترتیب نمایش</label><input type="number" name="sort_order" value="0"></div>
        </div>
        <label>توضیح کوتاه برای کاربران</label><input type="text" name="description" maxlength="490">
        <?php echo $tpl_checks($cat !== '' && isset($tpl_names[$cat]) ? [$cat] : []); ?>
        <label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="is_active" value="1" checked style="width:17px!important;height:17px"> فعال (قابل انتخاب برای کاربران)</label>
        <button class="btn btn-primary btn-sm" style="margin-top:10px">➕ ساخت مجموعه</button>
    </form>
</div>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">📋 مجموعه‌ها بر اساس نوع چت‌بات</h3>
    <?php
    $cnt_cat = ['' => count($sets), 'general' => 0];
    foreach ($sets as $x) { $ts = kb_set_templates($x); if (!$ts) $cnt_cat['general']++; foreach ($ts as $t) $cnt_cat[$t] = ($cnt_cat[$t] ?? 0) + 1; }
    $shown_sets = array_values(array_filter($sets, function ($x) use ($cat) { $ts = kb_set_templates($x); return $cat === '' || ($cat === 'general' ? !$ts : in_array($cat, $ts, true)); }));
    ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
        <?php foreach (['' => 'همه'] + $tpl_names + ['general' => '🌐 عمومی (بدون نوع)'] as $k => $l): ?>
        <a href="kb.php<?php echo $k !== '' ? '?cat=' . urlencode($k) : ''; ?>" style="padding:5px 12px;border-radius:14px;font-size:12.5px;text-decoration:none;<?php echo $cat === $k ? 'background:#7c3aed;color:#fff' : 'background:#f1f5f9;color:#475569'; ?>"><?php echo admin_h($l); ?> <small>(<?php echo (int)($cnt_cat[$k] ?? 0); ?>)</small></a>
        <?php endforeach; ?>
    </div>
    <?php $sets = $shown_sets; if (!$sets): ?><p class="muted" style="text-align:center;padding:14px">هنوز مجموعه‌ای ساخته نشده است.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>عنوان</th><th>منابع</th><th>بخش‌های دانش</th><th>ربات‌های متصل</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($sets as $x): ?>
        <tr><td><b><?php echo admin_h($x['title']); ?></b><div style="font-size:11px;color:#64748b"><?php echo admin_h($x['description']); ?></div>
            <div style="margin-top:3px"><?php $ts = kb_set_templates($x); if (!$ts): ?><span style="font-size:11px;background:#f1f5f9;border-radius:8px;padding:1px 7px">🌐 عمومی</span><?php else: foreach ($ts as $t): ?><span style="font-size:11px;background:#ede9fe;color:#6d28d9;border-radius:8px;padding:1px 7px;margin-left:3px"><?php echo admin_h($tpl_names[$t] ?? $t); ?></span><?php endforeach; endif; ?></div></td>
            <td><?php echo (int)$x['sources']; ?></td><td><?php echo number_format((int)$x['chunks']); ?></td><td><?php echo (int)($usage[(int)$x['id']] ?? 0); ?></td>
            <td><span class="<?php echo $x['is_active'] ? 'badge-ok' : 'badge-off'; ?>"><?php echo $x['is_active'] ? 'فعال' : 'غیرفعال'; ?></span></td>
            <td style="white-space:nowrap"><a href="kb.php?set=<?php echo (int)$x['id']; ?>" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">✏️ ویرایش و منابع</a>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="set_id" value="<?php echo (int)$x['id']; ?>"><input type="hidden" name="act" value="set_toggle"><button class="btn btn-sm btn-outline"><?php echo $x['is_active'] ? '⏸ غیرفعال' : '▶ فعال'; ?></button></form>
                <form method="post" style="display:inline" data-del2-title="<?php echo admin_h($x['title']); ?>" data-del2-info="<?php echo admin_h('مجموعه «' . $x['title'] . '» با همه ' . (int)$x['sources'] . ' منبع و فایل آن (' . number_format((int)$x['chunks']) . ' بخش دانش) حذف می‌شود و از ' . (int)($usage[(int)$x['id']] ?? 0) . ' ربات متصل هم جدا می‌شود.'); ?>"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="set_id" value="<?php echo (int)$x['id']; ?>"><input type="hidden" name="act" value="set_del"><input type="hidden" name="confirm_del" value=""><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">🗑 حذف</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php else:
    $kb = -(int)$kset['id'];
    $srcs = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM hd_chunks c WHERE c.source_id=s.id) AS chunks FROM hd_sources s WHERE s.bot_id=? ORDER BY s.id DESC");
    $srcs->execute([$kb]);
    $srcs = $srcs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stl = ['ready' => 'آماده', 'syncing' => 'در حال خواندن', 'pending' => 'در انتظار', 'error' => 'خطا'];
    $hid = '<input type="hidden" name="csrf_token" value="' . admin_h($csrf_token) . '"><input type="hidden" name="set_id" value="' . (int)$kset['id'] . '">';
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
        <h3 style="font-size:15px">✏️ مجموعه: <?php echo admin_h($kset['title']); ?> <span class="muted" style="font-weight:normal;font-size:12px">— متصل به <?php echo (int)($usage[(int)$kset['id']] ?? 0); ?> ربات</span></h3>
        <a href="kb.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">← همه مجموعه‌ها</a>
    </div>
    <form method="post">
        <?php echo $hid; ?><input type="hidden" name="act" value="set_save">
        <div class="row2">
            <div><label>عنوان</label><input type="text" name="title" maxlength="190" value="<?php echo admin_h($kset['title']); ?>" required></div>
            <div><label>ترتیب نمایش</label><input type="number" name="sort_order" value="<?php echo (int)$kset['sort_order']; ?>"></div>
        </div>
        <label>توضیح</label><input type="text" name="description" maxlength="490" value="<?php echo admin_h($kset['description']); ?>">
        <?php echo $tpl_checks(kb_set_templates($kset)); ?>
        <label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="is_active" value="1" <?php echo $kset['is_active'] ? 'checked' : ''; ?> style="width:17px!important;height:17px"> فعال</label>
        <div style="display:flex;gap:8px;margin-top:10px">
            <button class="btn btn-primary btn-sm">💾 ذخیره</button>
        </div>
    </form>
    <form method="post" style="margin-top:8px" data-del2-title="<?php echo admin_h($kset['title']); ?>" data-del2-info="<?php echo admin_h('مجموعه «' . $kset['title'] . '» با همه ' . count($srcs) . ' منبع و فایل آن حذف می‌شود و از ' . (int)($usage[(int)$kset['id']] ?? 0) . ' ربات متصل هم جدا می‌شود.'); ?>"><?php echo $hid; ?><input type="hidden" name="act" value="set_del"><input type="hidden" name="confirm_del" value=""><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">🗑 حذف مجموعه</button></form>
</div>

<?php $edit_src = !empty($_GET['edit_src']) ? hd_source_get($pdo, (int)$_GET['edit_src'], $kb) : null; if ($edit_src): ?>
<div class="card" id="srcedit">
    <h3 style="font-size:15px;margin-bottom:10px">✏️ ویرایش منبع: <?php echo admin_h($edit_src['title']); ?></h3>
    <?php echo hd_source_edit_form($edit_src, $hid, 'kb.php?set=' . (int)$kset['id']); ?>
</div>
<?php endif; ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">➕ افزودن منبع</h3>
    <div class="row2">
        <form method="post" enctype="multipart/form-data" style="background:#f8fafc;border-radius:10px;padding:12px">
            <?php echo $hid; ?><input type="hidden" name="act" value="add_file">
            <p style="font-weight:700;font-size:13px;margin-bottom:6px">📄 فایل (PDF، Word، متن — تا ۲۰ مگابایت)</p>
            <input type="file" name="doc" accept=".pdf,.docx,.txt" required>
            <input type="text" name="title" placeholder="عنوان (اختیاری)" style="margin-top:6px">
            <button class="btn btn-primary btn-sm" style="margin-top:8px" onclick="this.textContent='⏳ در حال خواندن...'">افزودن</button>
        </form>
        <form method="post" style="background:#f8fafc;border-radius:10px;padding:12px">
            <?php echo $hid; ?><input type="hidden" name="act" value="add_url">
            <p style="font-weight:700;font-size:13px;margin-bottom:6px">🌐 صفحه یا سایت</p>
            <input type="text" name="url" dir="ltr" placeholder="https://..." required>
            <input type="text" name="title" placeholder="عنوان (اختیاری)" style="margin-top:6px">
            <select name="kind" style="margin-top:6px"><option value="site">کل سایت / بخش (تا ۳۰۰ صفحه)</option><option value="page">فقط همین صفحه</option></select>
            <button class="btn btn-primary btn-sm" style="margin-top:8px" onclick="this.textContent='⏳ در حال خواندن... (تا یک دقیقه)'">افزودن</button>
        </form>
    </div>
    <form method="post" style="background:#f8fafc;border-radius:10px;padding:12px;margin-top:10px">
        <?php echo $hid; ?><input type="hidden" name="act" value="add_text">
        <p style="font-weight:700;font-size:13px;margin-bottom:6px">✍️ متن</p>
        <input type="text" name="title" placeholder="عنوان *" required>
        <textarea name="content" rows="5" placeholder="متن دانش..." style="margin-top:6px" required></textarea>
        <button class="btn btn-primary btn-sm" style="margin-top:8px">افزودن</button>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px">📋 منابع این مجموعه (<?php echo count($srcs); ?>)</h3>
    <?php if (!$srcs): ?><p class="muted" style="text-align:center;padding:14px">منبعی اضافه نشده است.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>عنوان</th><th>نوع</th><th>صفحات</th><th>بخش‌ها</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($srcs as $x): $x_on = !isset($x['is_active']) || (int)$x['is_active'] === 1; ?>
        <tr<?php echo $x_on ? '' : ' style="opacity:.55"'; ?>><td><b><?php echo admin_h($x['title']); ?></b><?php if ($x['url'] !== ''): ?><div dir="ltr" style="font-size:11px;color:#64748b;text-align:right"><?php echo admin_h($x['url']); ?></div><?php endif; ?><?php if ($x['last_error'] !== ''): ?><div style="font-size:11px;color:#dc2626"><?php echo admin_h($x['last_error']); ?></div><?php endif; ?></td>
            <td style="font-size:12px"><?php echo ['text' => 'متن', 'file' => 'فایل', 'page' => 'صفحه', 'site' => 'سایت'][$x['kind']] ?? $x['kind']; ?></td>
            <td><?php echo (int)$x['pages_ok']; ?>/<?php echo (int)$x['pages_total']; ?></td><td><?php echo number_format((int)$x['chunks']); ?></td>
            <td style="font-size:12px"><?php echo $stl[$x['status']] ?? $x['status']; ?><?php echo $x_on ? '' : '<div><span class="badge-off">غیرفعال</span></div>'; ?></td>
            <td style="white-space:nowrap">
                <?php if (in_array($x['kind'], ['site', 'page'], true)): ?><form method="post" style="display:inline"><?php echo $hid; ?><input type="hidden" name="act" value="sync"><input type="hidden" name="source_id" value="<?php echo (int)$x['id']; ?>"><button class="btn btn-sm" style="background:#f1f5f9;color:#374151">🔄 همگام‌سازی</button></form><?php endif; ?>
                <a class="btn btn-sm btn-outline" href="kb.php?set=<?php echo (int)$kset['id']; ?>&edit_src=<?php echo (int)$x['id']; ?>#srcedit">✏️ ویرایش</a>
                <form method="post" style="display:inline"><?php echo $hid; ?><input type="hidden" name="act" value="src_toggle"><input type="hidden" name="source_id" value="<?php echo (int)$x['id']; ?>"><button class="btn btn-sm btn-outline"><?php echo $x_on ? '⏸ غیرفعال' : '▶ فعال'; ?></button></form>
                <form method="post" style="display:inline" data-del2-title="<?php echo admin_h($x['title']); ?>" data-del2-info="<?php echo admin_h(($x['kind'] === 'file' ? 'فایل' : 'منبع') . ' «' . $x['title'] . '» و ' . number_format((int)$x['chunks']) . ' بخش دانشِ آن از این مجموعه (و همه ربات‌های متصل) حذف می‌شود.'); ?>"><?php echo $hid; ?><input type="hidden" name="act" value="del_source"><input type="hidden" name="source_id" value="<?php echo (int)$x['id']; ?>"><input type="hidden" name="confirm_del" value=""><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">🗑 حذف</button></form>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php echo function_exists('ui_del2_assets') ? ui_del2_assets() : ''; ?>
<?php include __DIR__ . '/_footer.php'; ?>
