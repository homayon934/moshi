<?php
/**
 * گالری نمونه‌ها و پرامپت‌های پس‌زمینه — بخش طراحی (تصویر) و بخش ویدیو
 *  - دسته‌بندی‌ها (لوگو، کارت ویزیت، ... / کارتونی، عاشقانه، ...)
 *  - نمونه‌ها: فایل نمونه + توضیح (پرامپت مخفی) + دسته
 *  - پرامپت‌های پس‌زمینه: عنوانی که کاربر می‌بیند (مثلاً «اجرای دقیق طرح») + متن مخفی
 */
require_once __DIR__ . '/_bootstrap.php';

$kind = ($_GET['k'] ?? ($_POST['k'] ?? 'image')) === 'video' ? 'video' : 'image';
$tab = in_array($_GET['t'] ?? '', ['samples', 'presets', 'cats'], true) ? $_GET['t'] : 'samples';
$KL = ['image' => '🎨 طراحی (تصویر)', 'video' => '🎬 ویدیو'];
$msg = '';
$now = date('Y-m-d H:i:s');
/** کلمات کلیدی: جداشده با کاما، بدون تکرار، حداکثر ۵۰۰ نویسه */
function gal_tags_clean($s)
{
    $a = [];
    foreach (preg_split('/[,،;\n]+/u', (string)$s) as $t) { $t = trim(preg_replace('/\s+/u', ' ', $t)); if ($t !== '' && !in_array($t, $a, true)) $a[] = mb_substr($t, 0, 40); }
    return mb_substr(implode('، ', $a), 0, 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = in_array($_POST['t'] ?? '', ['samples', 'presets', 'cats'], true) ? $_POST['t'] : 'samples';
    $back = 'gallery.php?k=' . $kind . '&t=' . $tab;
    $act = $_POST['act'] ?? '';
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        if ($act === 'ai_search') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'نشست منقضی شده؛ صفحه را دوباره باز کنید.'], JSON_UNESCAPED_UNICODE); exit; }
        header('Location: ' . $back . '&msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    // جستجوی هوشمند (نسخه ۵۱) — برای مدیر رایگان
    if ($act === 'ai_search') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(gal_ai_search($pdo, $kind, (string)($_POST['q'] ?? ''), ['type' => 'admin']), JSON_UNESCAPED_UNICODE); exit;
    }
    if ($act === 'bot_toggle') {
        biz_set($pdo, 'gal_bot_off', empty($_POST['on']) ? '1' : '0');
        header('Location: ' . $back . '&msg=' . urlencode(empty($_POST['on']) ? 'ok:گالری در چت‌بات‌ها نمایش داده نمی‌شود.' : 'ok:گالری در چت‌بات‌ها نمایش داده می‌شود.')); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    biz_gallery_schema($pdo);
    $tree0 = biz_gallery_tree($pdo, $kind);
    if ($act === 'cat_save') {
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 110);
        $parent = (int)($_POST['parent_id'] ?? 0);
        if ($parent && !isset($tree0[$parent])) $parent = 0;
        // عمق: والد حداکثر سطح ۲؛ زیرشاخه‌های خودِ دسته هم نباید از سطح ۳ بگذرند و دسته نمی‌تواند زیرمجموعه خودش شود
        $own_depth = 1;
        if ($id && isset($tree0[$id])) foreach ($tree0[$id]['desc_ids'] as $d) $own_depth = max($own_depth, $tree0[$d]['depth'] - $tree0[$id]['depth'] + 1);
        if (mb_strlen($title) < 2) $msg = 'error:عنوان دسته را وارد کنید.';
        elseif ($id && $parent && in_array($parent, $tree0[$id]['desc_ids'] ?? [], true)) $msg = 'error:دسته نمی‌تواند زیرمجموعه خودش یا زیردسته‌هایش باشد.';
        elseif ($parent && $tree0[$parent]['depth'] + $own_depth > GAL_MAX_DEPTH) $msg = 'error:حداکثر ۳ سطح دسته‌بندی مجاز است.';
        elseif ($id) { $pdo->prepare("UPDATE saas_gallery_cats SET title=?, sort_order=?, parent_id=? WHERE id=? AND kind=?")->execute([$title, (int)($_POST['sort_order'] ?? 0), $parent, $id, $kind]); $msg = 'ok:دسته ذخیره شد.'; }
        else { $pdo->prepare("INSERT INTO saas_gallery_cats (kind, title, sort_order, parent_id) VALUES(?,?,?,?)")->execute([$kind, $title, (int)($_POST['sort_order'] ?? 0), $parent]); $msg = 'ok:دسته اضافه شد.'; }
        if (str_starts_with($msg, 'error:')) $back .= $id ? '&edit=' . $id : '';
    } elseif ($act === 'cat_del') {
        // زیردسته‌ها و نمونه‌ها به دسته بالاتر منتقل می‌شوند
        $up = (int)($tree0[$id]['parent_id'] ?? 0);
        $pdo->prepare("UPDATE saas_gallery_cats SET parent_id=? WHERE parent_id=? AND kind=?")->execute([$up, $id, $kind]);
        $pdo->prepare("UPDATE saas_gallery SET cat_id=? WHERE cat_id=? AND kind=?")->execute([$up, $id, $kind]);
        $pdo->prepare("DELETE FROM saas_gallery_cats WHERE id=? AND kind=?")->execute([$id, $kind]);
        $msg = 'ok:دسته حذف شد (زیردسته‌ها و نمونه‌های آن به دسته بالاتر منتقل شدند).';
        // پرامپت‌هایی که به این دسته محدود بودند
        foreach (biz_presets($pdo, $kind, false) as $pp) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$pp['cat_ids'])), fn($x) => $x !== $id));
            $pdo->prepare("UPDATE saas_prompt_presets SET cat_ids=? WHERE id=?")->execute([implode(',', $ids), (int)$pp['id']]);
        }
    } elseif ($act === 'preset_save') {
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 110);
        $cats = implode(',', array_filter(array_map('intval', (array)($_POST['cat_ids'] ?? []))));
        $v = [$title, mb_substr(trim($_POST['description'] ?? ''), 0, 290), trim((string)($_POST['prompt'] ?? '')), !empty($_POST['use_ref']) ? 1 : 0, $cats, !empty($_POST['is_active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0)];
        if (mb_strlen($title) < 2 || mb_strlen($v[2]) < 5) $msg = 'error:عنوان و متن پرامپت را وارد کنید.';
        elseif ($id) { $pdo->prepare("UPDATE saas_prompt_presets SET title=?, description=?, prompt=?, use_ref=?, cat_ids=?, is_active=?, sort_order=? WHERE id=? AND kind=?")->execute(array_merge($v, [$id, $kind])); $msg = 'ok:پرامپت ذخیره شد.'; }
        else { $pdo->prepare("INSERT INTO saas_prompt_presets (title, description, prompt, use_ref, cat_ids, is_active, sort_order, kind, created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute(array_merge($v, [$kind, $now])); $msg = 'ok:پرامپت اضافه شد.'; }
    } elseif ($act === 'preset_del') {
        $pdo->prepare("DELETE FROM saas_prompt_presets WHERE id=? AND kind=?")->execute([$id, $kind]);
        $msg = 'ok:پرامپت حذف شد.';
    } elseif ($act === 'sample_save') {
        $old = $id ? biz_gallery_item($pdo, $id) : null;
        $file_url = $old['file_url'] ?? '';
        $poster = $old['poster_url'] ?? '';
        if (!empty($_FILES['file']['name'])) {
            $u = biz_gallery_upload($_FILES['file'], $kind, $kind === 'video' ? ['mp4', 'webm'] : ['jpg', 'png', 'webp']);
            if ($u['ok']) { if ($file_url !== '') biz_gallery_unlink($file_url); $file_url = $u['url']; } else $msg = 'error:' . $u['error'];
        }
        if ($msg === '' && $kind === 'video' && !empty($_FILES['poster']['name'])) {
            $u = biz_gallery_upload($_FILES['poster'], 'video', ['jpg', 'png', 'webp']);
            if ($u['ok']) { if ($poster !== '') biz_gallery_unlink($poster); $poster = $u['url']; } else $msg = 'error:' . $u['error'];
        }
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 190);
        if ($msg === '') {
            if (mb_strlen($title) < 2) $msg = 'error:عنوان نمونه را وارد کنید.';
            elseif ($file_url === '') $msg = 'error:فایل نمونه را انتخاب کنید.';
            else {
                $cid = (int)($_POST['cat_id'] ?? 0); if ($cid && !isset($tree0[$cid])) $cid = 0;
                [$pid, $aid] = gal_link_parse($_POST['preset_id'] ?? '');
                $v = [$cid, $title, mb_substr(trim($_POST['description'] ?? ''), 0, 490), trim((string)($_POST['prompt'] ?? '')), $file_url, $poster, !empty($_POST['is_active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0), $pid, $aid, gal_tags_clean($_POST['tags'] ?? '')];
                if ($id) { $pdo->prepare("UPDATE saas_gallery SET cat_id=?, title=?, description=?, prompt=?, file_url=?, poster_url=?, is_active=?, sort_order=?, preset_id=?, action_id=?, tags=? WHERE id=? AND kind=?")->execute(array_merge($v, [$id, $kind])); $msg = 'ok:نمونه ذخیره شد.'; }
                else { $pdo->prepare("INSERT INTO saas_gallery (cat_id, title, description, prompt, file_url, poster_url, is_active, sort_order, preset_id, action_id, tags, kind, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($v, [$kind, $now])); $msg = 'ok:نمونه به گالری اضافه شد.'; }
            }
        }
        if (str_starts_with($msg, 'error:')) $back .= $id ? '&edit=' . $id : '';
    } elseif ($act === 'bulk_upload') {
        // افزودن گروهی: چند فایل → یک دسته/زیردسته + پرامپت متصل
        $cid = (int)($_POST['cat_id'] ?? 0); if ($cid && !isset($tree0[$cid])) $cid = 0;
        [$pid, $aid] = gal_link_parse($_POST['preset_id'] ?? '');
        $prefix = mb_substr(trim($_POST['title_prefix'] ?? ''), 0, 120);
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 490);
        $mprompt = trim((string)($_POST['prompt'] ?? ''));
        $active = !empty($_POST['is_active']) ? 1 : 0;
        $tags = gal_tags_clean($_POST['tags'] ?? '');
        $F = $_FILES['files'] ?? null;
        $ok = 0; $errs = [];
        if (!$F || !is_array($F['name'] ?? null)) $msg = 'error:فایلی انتخاب نشده است (اگر فایل‌ها حجیم‌اند، ممکن است از سقف آپلود هاست بیشتر باشند).';
        else {
            $n = count($F['name']);
            for ($i = 0; $i < $n && $i < 100; $i++) {
                if (($F['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if (($F['error'][$i] ?? 0) !== UPLOAD_ERR_OK) { $errs[] = $F['name'][$i] . ' (خطای آپلود ' . (int)$F['error'][$i] . ')'; continue; }
                $one = ['name' => $F['name'][$i], 'tmp_name' => $F['tmp_name'][$i], 'size' => $F['size'][$i], 'type' => $F['type'][$i] ?? '', 'error' => 0];
                $u = biz_gallery_upload($one, $kind, $kind === 'video' ? ['mp4', 'webm'] : ['jpg', 'png', 'webp']);
                if (!$u['ok']) { $errs[] = $F['name'][$i] . ' (' . $u['error'] . ')'; continue; }
                $base = trim(preg_replace('/[_\-]+/u', ' ', pathinfo((string)$F['name'][$i], PATHINFO_FILENAME)));
                $title = mb_substr($prefix !== '' ? $prefix . ($n > 1 ? ' ' . ($ok + 1) : '') : ($base !== '' ? $base : 'نمونه ' . ($ok + 1)), 0, 190);
                $pdo->prepare("INSERT INTO saas_gallery (cat_id, title, description, prompt, file_url, poster_url, is_active, sort_order, preset_id, action_id, tags, kind, created_at) VALUES(?,?,?,?,?,'',?,0,?,?,?,?,?)")
                    ->execute([$cid, $title, $desc, $mprompt, $u['url'], $active, $pid, $aid, $tags, $kind, $now]);
                $ok++;
            }
            $msg = ($ok ? 'ok:' . $ok . ' نمونه اضافه شد.' : 'error:هیچ فایلی اضافه نشد.') . ($errs ? ' ناموفق: ' . implode('، ', array_slice($errs, 0, 8)) : '');
        }
        $back .= $cid ? '&c=' . $cid : '';
    } elseif ($act === 'bulk_edit') {
        // ویرایش گروهی نمونه‌های انتخاب‌شده
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $do = (string)($_POST['do'] ?? '');
        if (!$ids) $msg = 'error:هیچ نمونه‌ای انتخاب نشده است.';
        else {
            $in = implode(',', $ids);
            if ($do === 'move') { $cid = (int)($_POST['to_cat'] ?? 0); if ($cid && !isset($tree0[$cid])) $cid = 0; $pdo->prepare("UPDATE saas_gallery SET cat_id=? WHERE kind=? AND id IN ($in)")->execute([$cid, $kind]); $msg = 'ok:' . count($ids) . ' نمونه منتقل شد.'; }
            elseif ($do === 'preset') { [$pid, $aid] = gal_link_parse($_POST['to_preset'] ?? ''); $pdo->prepare("UPDATE saas_gallery SET preset_id=?, action_id=? WHERE kind=? AND id IN ($in)")->execute([$pid, $aid, $kind]); $msg = 'ok:اتصال ' . count($ids) . ' نمونه تنظیم شد.'; }
            elseif ($do === 'tags') {
                $add = gal_tags_clean($_POST['to_tags'] ?? '');
                if ($add === '') $msg = 'error:کلمات کلیدی را بنویسید.';
                else {
                    foreach ($ids as $gid) if (($old = biz_gallery_item($pdo, $gid)) && $old['kind'] === $kind) $pdo->prepare("UPDATE saas_gallery SET tags=? WHERE id=?")->execute([gal_tags_clean(($old['tags'] ?? '') . '،' . $add), $gid]);
                    $msg = 'ok:کلمات کلیدی به ' . count($ids) . ' نمونه اضافه شد.';
                }
            }
            elseif ($do === 'show' || $do === 'hide') { $pdo->prepare("UPDATE saas_gallery SET is_active=? WHERE kind=? AND id IN ($in)")->execute([$do === 'show' ? 1 : 0, $kind]); $msg = 'ok:وضعیت ' . count($ids) . ' نمونه تغییر کرد.'; }
            elseif ($do === 'delete') {
                foreach ($ids as $gid) if (($old = biz_gallery_item($pdo, $gid)) && $old['kind'] === $kind) { biz_gallery_unlink($old['file_url']); biz_gallery_unlink($old['poster_url']); }
                $pdo->prepare("DELETE FROM saas_gallery WHERE kind=? AND id IN ($in)")->execute([$kind]);
                $msg = 'ok:' . count($ids) . ' نمونه حذف شد.';
            } else $msg = 'error:عملیات را انتخاب کنید.';
        }
        if (!empty($_POST['c'])) $back .= '&c=' . (int)$_POST['c'];
    } elseif ($act === 'sample_del') {
        if ($old = biz_gallery_item($pdo, $id)) { biz_gallery_unlink($old['file_url']); biz_gallery_unlink($old['poster_url']); }
        $pdo->prepare("DELETE FROM saas_gallery WHERE id=? AND kind=?")->execute([$id, $kind]);
        $msg = 'ok:نمونه حذف شد.';
    }
    header('Location: ' . $back . '&msg=' . urlencode($msg)); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];

biz_gallery_schema($pdo);
$tree = biz_gallery_tree($pdo, $kind);
$cats = array_values($tree);
$catname = [];
foreach ($tree as $c) $catname[(int)$c['id']] = $c['path'];
$all_presets = biz_presets($pdo, $kind, false);
$preset_name = [];
foreach ($all_presets as $pp) $preset_name[(int)$pp['id']] = $pp['title'];
// گزینه‌های درختی دسته (با تورفتگی)
$cat_opts = function ($sel, $max_depth = GAL_MAX_DEPTH, $none = '— بدون دسته —', $skip = []) use ($tree) {
    $h = '<option value="0">' . $none . '</option>';
    foreach ($tree as $c) {
        if ($c['depth'] > $max_depth || in_array((int)$c['id'], $skip, true)) continue;
        $h .= '<option value="' . (int)$c['id'] . '"' . ((int)$sel === (int)$c['id'] ? ' selected' : '') . '>' . str_repeat('　', $c['depth'] - 1) . ($c['depth'] > 1 ? '└ ' : '') . admin_h($c['title']) . '</option>';
    }
    return $h;
};
// اتصال نمونه: «اقدام‌های آماده تصویر/ویدیو» (a12) یا پرامپت‌های پس‌زمینه گالری (p5) — نسخه ۵۲
$all_actions = function_exists('ga_list') ? ga_list($pdo, $kind, 'all', false) : [];
$action_name = [];
foreach ($all_actions as $g) $action_name[(int)$g['id']] = trim($g['icon'] . ' ' . $g['title']);
$preset_opts = function ($sel, $none = '— انتخاب در زمان ساخت (همه پرامپت‌های مجاز) —', $sel_action = 0) use ($all_presets, $all_actions) {
    $h = '<option value="0">' . $none . '</option>';
    if ($all_actions) {
        $h .= '<optgroup label="⚡ اقدام‌های آماده تصویر و ویدیو">';
        foreach ($all_actions as $g) {
            $w = [];
            if (empty($g['in_studio'])) $w[] = 'نه در استودیو';
            if (empty($g['in_bot'])) $w[] = 'نه در چت‌بات';
            if (empty($g['is_active'])) $w[] = 'خاموش';
            $h .= '<option value="a' . (int)$g['id'] . '"' . ((int)$sel_action === (int)$g['id'] ? ' selected' : '') . '>' . admin_h(trim($g['icon'] . ' ' . $g['title'])) . ($w ? ' (' . implode('، ', $w) . ')' : '') . '</option>';
        }
        $h .= '</optgroup>';
    }
    if ($all_presets) {
        $h .= '<optgroup label="🧩 پرامپت‌های پس‌زمینه گالری">';
        foreach ($all_presets as $p) $h .= '<option value="p' . (int)$p['id'] . '"' . (!$sel_action && (int)$sel === (int)$p['id'] ? ' selected' : '') . '>' . admin_h($p['title']) . ($p['is_active'] ? '' : ' (غیرفعال)') . '</option>';
        $h .= '</optgroup>';
    }
    return $h;
};
$edit = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;
$hid = function ($t) use ($csrf_token, $kind) { return '<input type="hidden" name="csrf_token" value="' . admin_h($csrf_token) . '"><input type="hidden" name="k" value="' . $kind . '"><input type="hidden" name="t" value="' . $t . '">'; };
$fcat = (int)($_GET['c'] ?? 0);

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🖼 گالری نمونه‌ها و پرامپت‌ها</h2>';
?>
<style>
.gt{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.gt a{padding:8px 14px;border-radius:10px;background:#fff;border:1px solid #e2e8f0;color:#475569;text-decoration:none;font-weight:600;font-size:13px}
.gt a.on{background:#2563eb;color:#fff;border-color:#2563eb}
.gg{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.gi{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.gi img,.gi video{width:100%;aspect-ratio:1/1;object-fit:cover;background:#0f172a;display:block}
.gi .b{padding:8px 10px;font-size:12px;line-height:1.8;flex:1}.gi .a{display:flex;gap:6px;padding:0 10px 10px}
.chk{display:flex;gap:8px;align-items:center;font-weight:500}.chk input{width:17px!important;height:17px}
code.ph{background:#eef2ff;color:#3730a3;padding:1px 6px;border-radius:6px}
</style>
<?php if ($msg): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>

<div class="gt"><?php foreach ($KL as $k => $l): ?><a href="gallery.php?k=<?php echo $k; ?>&t=<?php echo $tab; ?>" class="<?php echo $kind === $k ? 'on' : ''; ?>"><?php echo $l; ?></a><?php endforeach; ?></div>
<div class="gt">
    <a href="gallery.php?k=<?php echo $kind; ?>&t=samples" class="<?php echo $tab === 'samples' ? 'on' : ''; ?>">🖼 نمونه‌های گالری</a>
    <a href="gallery.php?k=<?php echo $kind; ?>&t=presets" class="<?php echo $tab === 'presets' ? 'on' : ''; ?>">🧩 پرامپت‌های پس‌زمینه</a>
    <a href="gallery.php?k=<?php echo $kind; ?>&t=cats" class="<?php echo $tab === 'cats' ? 'on' : ''; ?>">🗂 دسته‌بندی‌ها</a>
</div>

<?php if ($tab === 'cats'): $ec = null; foreach ($cats as $c) if ((int)$c['id'] === $edit) $ec = $c; ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:10px"><?php echo $ec ? '✏️ ویرایش دسته' : '➕ دسته جدید'; ?> — <?php echo $KL[$kind]; ?></h3>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <?php echo $hid('cats'); ?><input type="hidden" name="act" value="cat_save"><?php if ($ec): ?><input type="hidden" name="id" value="<?php echo (int)$ec['id']; ?>"><?php endif; ?>
        <div style="flex:1;min-width:200px"><label>عنوان</label><input type="text" name="title" maxlength="110" required value="<?php echo admin_h($ec['title'] ?? ''); ?>" placeholder="<?php echo $kind === 'video' ? 'مثلاً: کارتونی' : 'مثلاً: کارت ویزیت'; ?>"></div>
        <div style="min-width:200px"><label>زیرمجموعه</label><select name="parent_id"><?php echo $cat_opts((int)($ec['parent_id'] ?? (int)($_GET['parent'] ?? 0)), GAL_MAX_DEPTH - 1, '— دسته اصلی (سطح ۱) —', $ec ? $ec['desc_ids'] : []); ?></select></div>
        <div style="width:120px"><label>ترتیب</label><input type="number" name="sort_order" value="<?php echo (int)($ec['sort_order'] ?? 0); ?>"></div>
        <button class="btn btn-primary btn-sm"><?php echo $ec ? '💾 ذخیره' : '➕ افزودن'; ?></button>
        <?php if ($ec): ?><a href="gallery.php?k=<?php echo $kind; ?>&t=cats" class="btn btn-sm" style="background:#f1f5f9;color:#374151">انصراف</a><?php endif; ?>
    </form>
</div>
<div class="card">
    <?php if (!$cats): ?><p class="muted" style="text-align:center;padding:12px">دسته‌ای ندارید.</p><?php else: ?>
    <?php $cnt = []; try { $cq = $pdo->prepare("SELECT cat_id, COUNT(*) n FROM saas_gallery WHERE kind=? GROUP BY cat_id"); $cq->execute([$kind]); foreach ($cq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $cnt[(int)$r['cat_id']] = (int)$r['n']; } catch (\Throwable $e) {} ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="search" id="ctq" placeholder="🔍 جستجوی دسته…" style="flex:1;min-width:180px;max-width:320px" autocomplete="off">
        <button type="button" class="btn btn-sm" style="background:#f1f5f9;color:#475569" onclick="ctAll(true)">باز کردن همه</button>
        <button type="button" class="btn btn-sm" style="background:#f1f5f9;color:#475569" onclick="ctAll(false)">بستن همه</button>
        <span class="muted" style="font-size:12px">روی ◂ یا نام دسته بزنید تا زیردسته‌ها باز شوند.</span>
    </div>
    <table id="ctt"><thead><tr><th>عنوان</th><th>نمونه (با زیردسته‌ها)</th><th>ترتیب</th><th></th></tr></thead><tbody>
    <?php foreach ($cats as $c): $n = array_sum(array_map(fn($x) => $cnt[$x] ?? 0, $c['desc_ids'])); $hk = count($c['desc_ids']) > 1; ?>
        <tr data-id="<?php echo (int)$c['id']; ?>" data-p="<?php echo (int)$c['parent_id']; ?>" data-t="<?php echo admin_h($c['path']); ?>"<?php echo $c['depth'] > 1 ? ' style="display:none"' : ''; ?>><td style="padding-right:<?php echo 8 + ($c['depth'] - 1) * 24; ?>px;cursor:<?php echo $hk ? 'pointer' : 'default'; ?>" class="ctn"><span class="ctx" style="display:inline-block;width:18px;color:#64748b"><?php echo $hk ? '◂' : ($c['depth'] > 1 ? '<span style="color:#cbd5e1">└</span>' : ''); ?></span><b><?php echo admin_h($c['title']); ?></b> <span class="badge-off" style="font-size:10.5px">سطح <?php echo (int)$c['depth']; ?></span><?php if ($hk): ?> <span style="font-size:11px;color:#94a3b8"><?php echo count($c['desc_ids']) - 1; ?> زیردسته</span><?php endif; ?></td><td><?php echo $n; ?></td><td><?php echo (int)$c['sort_order']; ?></td>
            <td style="white-space:nowrap"><?php if ($c['depth'] < GAL_MAX_DEPTH): ?><a href="gallery.php?k=<?php echo $kind; ?>&t=cats&parent=<?php echo (int)$c['id']; ?>" class="btn btn-sm" style="background:#e0f2fe;color:#0369a1">+ زیردسته</a> <?php endif; ?><a href="gallery.php?k=<?php echo $kind; ?>&t=samples&c=<?php echo (int)$c['id']; ?>" class="btn btn-sm" style="background:#f1f5f9;color:#475569">نمونه‌ها</a> <a href="gallery.php?k=<?php echo $kind; ?>&t=cats&edit=<?php echo (int)$c['id']; ?>" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">ویرایش</a>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><?php echo $hid('cats'); ?><input type="hidden" name="act" value="cat_del"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <script>
    // دسته‌های آبشاری: زیردسته‌ها با کلیک روی دسته باز/بسته می‌شوند
    (function(){
      var rows = Array.prototype.slice.call(document.querySelectorAll('#ctt tbody tr')), by = {};
      rows.forEach(function(r){ by[r.getAttribute('data-id')] = r; });
      function kids(id){ return rows.filter(function(r){ return r.getAttribute('data-p') === id; }); }
      function setOpen(r, on){
        r.classList.toggle('open', on);
        var x = r.querySelector('.ctx'); if (x && kids(r.getAttribute('data-id')).length) x.textContent = on ? '▾' : '◂';
        kids(r.getAttribute('data-id')).forEach(function(k){ k.style.display = on ? '' : 'none'; if (!on) setOpen(k, false); });
      }
      rows.forEach(function(r){ r.querySelector('.ctn').onclick = function(){ if (kids(r.getAttribute('data-id')).length) setOpen(r, !r.classList.contains('open')); }; });
      window.ctAll = function(on){ document.getElementById('ctq').value = ''; rows.forEach(function(r){ if (r.getAttribute('data-p') === '0') { r.style.display = ''; setOpen(r, on); } }); if (on) rows.forEach(function(r){ r.style.display = ''; setOpen(r, true); }); };
      function nrm(s){ return String(s).toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\s]+/g, ' ').trim(); }
      document.getElementById('ctq').addEventListener('input', function(){
        var q = nrm(this.value);
        if (!q) { ctAll(false); return; }
        var show = {};
        rows.forEach(function(r){ if (nrm(r.getAttribute('data-t')).indexOf(q) >= 0) { var x = r; while (x) { show[x.getAttribute('data-id')] = 1; x = by[x.getAttribute('data-p')]; } } });
        rows.forEach(function(r){ var on = !!show[r.getAttribute('data-id')]; r.style.display = on ? '' : 'none'; var x = r.querySelector('.ctx'); if (x && kids(r.getAttribute('data-id')).length) x.textContent = on ? '▾' : '◂'; r.classList.toggle('open', on); });
      });
      <?php if (!empty($_GET['parent']) || $edit): $op = (int)($_GET['parent'] ?? 0) ?: $edit; ?>
      (function(){ var r = by['<?php echo $op; ?>'], ch = []; while (r) { ch.unshift(r); r = by[r.getAttribute('data-p')]; } ch.forEach(function(x){ x.style.display = ''; setOpen(x, true); }); })();
      <?php endif; ?>
    })();
    </script>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'presets'): $pres = biz_presets($pdo, $kind, false); $ep = null; foreach ($pres as $p) if ((int)$p['id'] === $edit) $ep = $p; $epc = array_filter(array_map('intval', explode(',', (string)($ep['cat_ids'] ?? '')))); ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px"><?php echo $ep ? '✏️ ویرایش پرامپت پس‌زمینه' : '➕ پرامپت پس‌زمینه جدید'; ?> — <?php echo $KL[$kind]; ?></h3>
    <p class="muted" style="line-height:2;margin-bottom:10px">کاربر فقط <b>عنوان</b> (مثلاً «اجرای دقیق طرح» یا «طرح مشابه») را می‌بیند و انتخاب می‌کند؛ متن پرامپت مخفی است. در متن می‌توانید از <code class="ph">{نمونه}</code> (توضیح نمونه انتخاب‌شده) و <code class="ph">{درخواست}</code> (اطلاعاتی که کاربر وارد می‌کند) استفاده کنید. پرامپت انگلیسی معمولاً نتیجه دقیق‌تری می‌دهد.</p>
    <form method="post">
        <?php echo $hid('presets'); ?><input type="hidden" name="act" value="preset_save"><?php if ($ep): ?><input type="hidden" name="id" value="<?php echo (int)$ep['id']; ?>"><?php endif; ?>
        <div class="row2">
            <div><label>عنوان (برای کاربر) *</label><input type="text" name="title" maxlength="110" required value="<?php echo admin_h($ep['title'] ?? ''); ?>" placeholder="مثلاً: اجرای دقیق طرح"></div>
            <div><label>توضیح کوتاه برای کاربر</label><input type="text" name="description" maxlength="290" value="<?php echo admin_h($ep['description'] ?? ''); ?>" placeholder="مثلاً: همین طرح، با اطلاعات شما"></div>
        </div>
        <label>متن پرامپت (مخفی) *</label>
        <textarea name="prompt" rows="6" dir="auto" required><?php echo admin_h($ep['prompt'] ?? ''); ?></textarea>
        <label class="chk" style="margin-top:8px"><input type="checkbox" name="use_ref" value="1" <?php echo !$ep || !empty($ep['use_ref']) ? 'checked' : ''; ?>> <?php echo $kind === 'video' ? 'تصویر پوستر نمونه به‌عنوان مرجع به مدل داده شود (برای اجرای دقیق)' : 'تصویر نمونه به‌عنوان مرجع به مدل داده شود (برای اجرای دقیق یا مشابه)'; ?></label>
        <?php if ($cats): ?>
        <label style="margin-top:8px">فقط برای این دسته‌ها (هیچ‌کدام = همه دسته‌ها؛ انتخاب یک دسته شامل زیردسته‌های آن هم می‌شود)</label>
        <div style="display:flex;gap:14px;flex-wrap:wrap"><?php foreach ($cats as $c): ?><label class="chk"><input type="checkbox" name="cat_ids[]" value="<?php echo (int)$c['id']; ?>" <?php echo in_array((int)$c['id'], $epc, true) ? 'checked' : ''; ?>> <?php echo admin_h($c['path']); ?></label><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="row2" style="margin-top:8px">
            <div><label>ترتیب</label><input type="number" name="sort_order" value="<?php echo (int)($ep['sort_order'] ?? 0); ?>"></div>
            <div><label class="chk" style="margin-top:28px"><input type="checkbox" name="is_active" value="1" <?php echo !$ep || !empty($ep['is_active']) ? 'checked' : ''; ?>> فعال</label></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px">
            <button class="btn btn-primary btn-sm"><?php echo $ep ? '💾 ذخیره' : '➕ افزودن'; ?></button>
            <?php if ($ep): ?><a href="gallery.php?k=<?php echo $kind; ?>&t=presets" class="btn btn-sm" style="background:#f1f5f9;color:#374151">انصراف</a><?php endif; ?>
        </div>
    </form>
</div>
<div class="card">
    <?php if (!$pres): ?><p class="muted" style="text-align:center;padding:12px">پرامپتی تعریف نشده است.</p><?php else: ?>
    <table><thead><tr><th>عنوان</th><th>متن (مخفی)</th><th>مرجع</th><th>دسته‌ها</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($pres as $p): $pc = array_filter(array_map('intval', explode(',', (string)$p['cat_ids']))); ?>
        <tr><td><b><?php echo admin_h($p['title']); ?></b><div style="font-size:11px;color:#64748b"><?php echo admin_h($p['description']); ?></div></td>
            <td style="font-size:11.5px;max-width:340px;color:#475569" dir="auto"><?php echo admin_h(mb_substr((string)$p['prompt'], 0, 160)); ?>…</td>
            <td><?php echo $p['use_ref'] ? '✅' : '—'; ?></td>
            <td style="font-size:12px"><?php echo $pc ? admin_h(implode('، ', array_map(fn($x) => $catname[$x] ?? '#' . $x, $pc))) : 'همه'; ?></td>
            <td><span class="<?php echo $p['is_active'] ? 'badge-ok' : 'badge-off'; ?>"><?php echo $p['is_active'] ? 'فعال' : 'غیرفعال'; ?></span></td>
            <td style="white-space:nowrap"><a href="gallery.php?k=<?php echo $kind; ?>&t=presets&edit=<?php echo (int)$p['id']; ?>" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">ویرایش</a>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><?php echo $hid('presets'); ?><input type="hidden" name="act" value="preset_del"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<?php else: $items = biz_gallery($pdo, $kind, false); $gcat = gal_catalog($pdo, $kind, true); $bot_on = (biz_settings($pdo)['gal_bot_off'] ?? '') !== '1'; $es = $edit ? biz_gallery_item($pdo, $edit) : null; if ($es && $es['kind'] !== $kind) $es = null; ?>
<div class="card" id="form">
    <h3 style="font-size:15px;margin-bottom:6px"><?php echo $es ? '✏️ ویرایش نمونه' : '➕ نمونه جدید'; ?> — <?php echo $KL[$kind]; ?></h3>
    <p class="muted" style="line-height:2;margin-bottom:10px">«توضیح برای مدل» مخفی است و در پرامپت جای <code class="ph">{نمونه}</code> قرار می‌گیرد؛ هرچه دقیق‌تر (سبک، رنگ‌ها، چیدمان، فونت، حال‌وهوا)، نتیجه «اجرای دقیق» بهتر.</p>
    <form method="post" enctype="multipart/form-data">
        <?php echo $hid('samples'); ?><input type="hidden" name="act" value="sample_save"><?php if ($es): ?><input type="hidden" name="id" value="<?php echo (int)$es['id']; ?>"><?php endif; ?>
        <div class="row2">
            <div><label>عنوان (برای کاربر) *</label><input type="text" name="title" maxlength="190" required value="<?php echo admin_h($es['title'] ?? ''); ?>"></div>
            <div><label>دسته / زیردسته</label><select name="cat_id"><?php echo $cat_opts((int)($es['cat_id'] ?? $fcat)); ?></select></div>
        </div>
        <div class="row2">
            <div><label><?php echo $kind === 'video' ? 'فایل ویدیو (MP4/WEBM تا ۶۰ مگابایت)' : 'تصویر نمونه (JPG/PNG/WEBP تا ۸ مگابایت)'; ?> <?php echo $es ? '— برای تغییر انتخاب کنید' : '*'; ?></label><input type="file" name="file" accept="<?php echo $kind === 'video' ? 'video/mp4,video/webm' : 'image/jpeg,image/png,image/webp'; ?>" <?php echo $es ? '' : 'required'; ?>></div>
            <?php if ($kind === 'video'): ?><div><label>تصویر پوستر (اختیاری؛ برای نمایش و «اجرای دقیق»)</label><input type="file" name="poster" accept="image/jpeg,image/png,image/webp"></div><?php else: ?><div></div><?php endif; ?>
        </div>
        <label>توضیح کوتاه برای کاربر</label><input type="text" name="description" maxlength="490" value="<?php echo admin_h($es['description'] ?? ''); ?>">
        <label>🏷 کلمات کلیدی جستجو (با کاما جدا کنید)</label><input type="text" name="tags" maxlength="500" value="<?php echo admin_h($es['tags'] ?? ''); ?>" placeholder="مثلاً: کافه، قهوه، رستوران، coffee، مینیمال، قهوه‌ای">
        <label>⚡ اقدام آماده / پرامپت متصل (نوع اجرا)</label><select name="preset_id"><?php echo $preset_opts((int)($es['preset_id'] ?? 0), '— انتخاب در زمان ساخت (همه پرامپت‌های مجاز) —', (int)($es['action_id'] ?? 0)); ?></select>
        <div class="muted" style="font-size:12px;margin-top:4px">با انتخاب یک «اقدام آماده»، تصویر همین نمونه به آن اقدام داده می‌شود و کاربر فقط اطلاعات خواسته‌شده را می‌نویسد (دستور و مدل‌ها همان تنظیمات صفحه «اقدام‌های آماده تصویر/ویدیو» است).</div>
        <label>توضیح برای مدل (مخفی)</label>
        <textarea name="prompt" rows="4" dir="auto" placeholder="<?php echo $kind === 'video' ? 'مثلاً: 2D cartoon style, bright pastel colors, smooth camera pan, cheerful mood...' : 'مثلاً: minimalist business card, navy and gold, centered logo, elegant serif typography...'; ?>"><?php echo admin_h($es['prompt'] ?? ''); ?></textarea>
        <div class="row2" style="margin-top:8px">
            <div><label>ترتیب</label><input type="number" name="sort_order" value="<?php echo (int)($es['sort_order'] ?? 0); ?>"></div>
            <div><label class="chk" style="margin-top:28px"><input type="checkbox" name="is_active" value="1" <?php echo !$es || !empty($es['is_active']) ? 'checked' : ''; ?>> نمایش در گالری کاربران</label></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:8px">
            <button class="btn btn-primary btn-sm"><?php echo $es ? '💾 ذخیره' : '➕ افزودن به گالری'; ?></button>
            <?php if ($es): ?><a href="gallery.php?k=<?php echo $kind; ?>&t=samples" class="btn btn-sm" style="background:#f1f5f9;color:#374151">انصراف</a><?php endif; ?>
        </div>
    </form>
</div>
<?php if (!$es): ?>
<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px">📦 افزودن گروهی — <?php echo $KL[$kind]; ?></h3>
    <p class="muted" style="line-height:2;margin-bottom:10px">چند <?php echo $kind === 'video' ? 'ویدیو' : 'تصویر'; ?> را با هم انتخاب کنید تا همه در یک دسته/زیردسته با یک اقدام آماده (یا پرامپت) متصل اضافه شوند. عنوان هر نمونه از نام فایل ساخته می‌شود (یا «عنوان پایه + شماره»). حداکثر تعداد و حجم در هر بار آپلود به تنظیمات هاست بستگی دارد (معمولاً ۲۰ فایل).</p>
    <form method="post" enctype="multipart/form-data">
        <?php echo $hid('samples'); ?><input type="hidden" name="act" value="bulk_upload">
        <div class="row2">
            <div><label>دسته / زیردسته *</label><select name="cat_id"><?php echo $cat_opts($fcat); ?></select></div>
            <div><label>⚡ اقدام آماده / پرامپت متصل</label><select name="preset_id"><?php echo $preset_opts(0); ?></select></div>
        </div>
        <div class="row2">
            <div><label>فایل‌ها (چندتایی) *</label><input type="file" name="files[]" multiple required accept="<?php echo $kind === 'video' ? 'video/mp4,video/webm' : 'image/jpeg,image/png,image/webp'; ?>"></div>
            <div><label>عنوان پایه (اختیاری)</label><input type="text" name="title_prefix" maxlength="120" placeholder="خالی = نام فایل"></div>
        </div>
        <label>توضیح کوتاه برای کاربر (برای همه)</label><input type="text" name="description" maxlength="490">
        <label>🏷 کلمات کلیدی جستجو (برای همه، با کاما)</label><input type="text" name="tags" maxlength="500" placeholder="مثلاً: لوگو، رستوران، فست‌فود، برگر">
        <label>توضیح برای مدل (مخفی، برای همه)</label><textarea name="prompt" rows="2" dir="auto"></textarea>
        <label class="chk" style="margin-top:8px"><input type="checkbox" name="is_active" value="1" checked> نمایش در گالری کاربران</label>
        <div style="margin-top:10px"><button class="btn btn-primary btn-sm">📦 افزودن همه</button></div>
    </form>
</div>
<?php endif; ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <h3 style="font-size:15px">📁 نمونه‌ها (<span id="gcnt"><?php echo count($items); ?></span>)</h3>
        <form method="post" style="display:flex;gap:6px;align-items:center;margin:0;font-size:12.5px"><?php echo $hid('samples'); ?><input type="hidden" name="act" value="bot_toggle">
            <label class="chk" title="مخاطبان چت‌بات‌هایی که ساخت تصویر/ویدیو دارند، از دکمه ✨ گالری را می‌بینند. صاحب هر چت‌بات هم می‌تواند آن را برای چت‌بات خودش خاموش کند."><input type="checkbox" name="on" value="1" <?php echo $bot_on ? 'checked' : ''; ?> onchange="this.form.submit()"> 💬 نمایش گالری در چت‌بات‌ها</label></form>
    </div>
    <div class="gwrap"><div id="gui"></div><div>
    <?php if ($items): ?>
    <form method="post" id="bulkf" onsubmit="if(!document.querySelector('input[form=bulkf]:checked')){alert('ابتدا نمونه‌ها را انتخاب کنید.');return false;} return this.do.value!=='delete'||confirm('نمونه‌های انتخاب‌شده حذف شوند؟');">
        <?php echo $hid('samples'); ?><input type="hidden" name="act" value="bulk_edit"><input type="hidden" name="c" value="<?php echo (int)$fcat; ?>">
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;margin-bottom:10px;font-size:13px">
            <label class="chk"><input type="checkbox" id="ball" onclick="var on=this.checked;document.querySelectorAll('.gi').forEach(function(g){var x=g.querySelector('input[form=bulkf]');if(x)x.checked=on&&g.style.display!=='none';});"> همه (نمایش‌داده‌شده‌ها)</label>
            <span style="color:#64748b">انتخاب‌شده‌ها:</span>
            <select name="do" id="bdo" style="width:auto" onchange="document.getElementById('bcat').style.display=this.value==='move'?'':'none';document.getElementById('bpre').style.display=this.value==='preset'?'':'none';document.getElementById('btag').style.display=this.value==='tags'?'':'none';">
                <option value="">— عملیات —</option><option value="move">انتقال به دسته</option><option value="preset">اتصال اقدام آماده / پرامپت</option><option value="tags">افزودن کلمات کلیدی</option><option value="show">نمایش</option><option value="hide">مخفی</option><option value="delete">حذف</option>
            </select>
            <input type="text" name="to_tags" id="btag" maxlength="300" placeholder="کلمات کلیدی، با کاما" style="display:none;width:auto;min-width:200px">
            <select name="to_cat" id="bcat" style="display:none;width:auto;min-width:200px"><?php echo $cat_opts($fcat); ?></select>
            <select name="to_preset" id="bpre" style="display:none;width:auto;min-width:200px"><?php echo $preset_opts(0, '— بدون اتصال —'); ?></select>
            <button class="btn btn-primary btn-sm">اعمال</button>
        </div>
    </form>
    <?php endif; ?>
    <?php if (!$items): ?><p class="muted" style="text-align:center;padding:12px">نمونه‌ای ندارید.</p><?php else: ?>
    <div class="gg" id="gg">
    <?php foreach ($items as $it): ?>
        <div class="gi" data-id="<?php echo (int)$it['id']; ?>" style="position:relative">
            <label style="position:absolute;top:6px;right:6px;z-index:2;background:rgba(255,255,255,.9);border-radius:6px;padding:2px 4px"><input type="checkbox" name="ids[]" value="<?php echo (int)$it['id']; ?>" form="bulkf" style="width:17px!important;height:17px"></label>
            <?php if ($kind === 'video'): ?><video src="<?php echo admin_h($it['file_url']); ?>" <?php echo $it['poster_url'] ? 'poster="' . admin_h(gal_thumb_url($it)) . '"' : ''; ?> controls preload="none"></video>
            <?php else: ?><a href="<?php echo admin_h($it['file_url']); ?>" target="_blank" rel="noopener"><img src="<?php echo admin_h(gal_thumb_url($it)); ?>" alt="" loading="lazy"></a><?php endif; ?>
            <div class="b"><b><?php echo admin_h($it['title']); ?></b> <?php echo $it['is_active'] ? '' : '<span class="badge-off">مخفی</span>'; ?><br><span style="color:#64748b"><?php echo admin_h($catname[(int)$it['cat_id']] ?? 'بدون دسته'); ?></span><?php if (!empty($it['action_id'])): ?><br><span style="color:#b45309">⚡ <?php echo admin_h($action_name[(int)$it['action_id']] ?? 'اقدام حذف‌شده'); ?></span><?php elseif (!empty($it['preset_id'])): ?><br><span style="color:#6d28d9">🧩 <?php echo admin_h($preset_name[(int)$it['preset_id']] ?? '—'); ?></span><?php endif; ?><?php if (($it['tags'] ?? '') !== ''): ?><br><span style="color:#94a3b8;font-size:11px">🏷 <?php echo admin_h(mb_substr($it['tags'], 0, 80)); ?></span><?php endif; ?></div>
            <div class="a"><a href="gallery.php?k=<?php echo $kind; ?>&t=samples&edit=<?php echo (int)$it['id']; ?>#form" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">ویرایش</a>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><?php echo $hid('samples'); ?><input type="hidden" name="act" value="sample_del"><input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form></div>
        </div>
    <?php endforeach; ?>
    </div>
    <p class="muted" id="gnone" style="text-align:center;padding:12px;display:none">نمونه‌ای پیدا نشد.</p>
    <?php endif; ?>
    </div></div>
</div>
<style><?php echo gal_ui_css(); ?>
.gwrap{display:grid;grid-template-columns:1fr;gap:14px}
@media (min-width:900px){.gwrap{grid-template-columns:260px 1fr}#gui{position:sticky;top:10px;align-self:start;max-height:calc(100vh - 20px);overflow-y:auto}}
</style>
<script><?php echo gal_ui_js(); ?></script>
<script>
(function(){
  var grid = document.getElementById('gg'), cnt = document.getElementById('gcnt'), none = document.getElementById('gnone');
  var els = {}; if (grid) Array.prototype.forEach.call(grid.querySelectorAll('.gi'), function(e){ els[e.getAttribute('data-id')] = e; });
  var order = grid ? Array.prototype.slice.call(grid.children) : [];
  var D = <?php echo json_encode($gcat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  GalUI.mount(document.getElementById('gui'), {
    cats: D.cats, items: D.items, side: true, sel: <?php echo (int)$fcat; ?>, noun: 'نمونه', all: 'همه نمونه‌ها',
    ph: '🔍 جستجو در عنوان، توضیح، کلمات کلیدی، دسته‌ها…',
    ai: function(q){
      var fd = new FormData(); fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>); fd.append('k', <?php echo json_encode($kind); ?>); fd.append('act', 'ai_search'); fd.append('q', q);
      return fetch('gallery.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); });
    },
    onFilter: function(ids){
      if (!grid) return;
      var ball = document.getElementById('ball'); if (ball) ball.checked = false;
      Array.prototype.forEach.call(grid.querySelectorAll('input[form=bulkf]'), function(x){ x.checked = false; });
      if (!ids) { order.forEach(function(e){ e.style.display = ''; grid.appendChild(e); }); cnt.textContent = order.length; none.style.display = 'none'; return; }
      var on = {}; ids.forEach(function(id){ on[id] = 1; });
      order.forEach(function(e){ e.style.display = on[e.getAttribute('data-id')] ? '' : 'none'; });
      ids.forEach(function(id){ if (els[id]) grid.appendChild(els[id]); });
      cnt.textContent = ids.length + ' از ' + order.length; none.style.display = ids.length ? 'none' : '';
    }
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
