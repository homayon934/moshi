<?php
/**
 * اقدام‌های آماده ساخت تصویر و ویدیو (نسخه ۴۲)
 * هر اقدام = یک دکمه برای کاربر (استودیو) و مخاطب (چت‌بات): عنوان + دستور آماده + مدل‌های مناسب به ترتیب اولویت
 */
require_once __DIR__ . '/_bootstrap.php';
ga_ensure_schema($pdo);

$back = function ($msg, $err = false, $extra = '') { header('Location: gen_actions.php?' . ($err ? 'err=' : 'msg=') . urlencode($msg) . $extra); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) $back('نشست منقضی شده؛ صفحه را تازه کنید.', true);
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    if ($act === 'save') {
        $kind = ($_POST['kind'] ?? '') === 'video' ? 'video' : 'image';
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 120));
        $prompt = trim(mb_substr((string)($_POST['prompt'] ?? ''), 0, 4000));
        if ($title === '' || mb_strlen($prompt) < 5) $back('عنوان و دستور (پرامپت) را کامل بنویسید.', true, $id ? '&edit=' . $id : '&new=1');
        $ref = in_array($_POST['ref'] ?? '', ['required', 'optional', 'none'], true) ? $_POST['ref'] : 'required';
        $size = in_array($_POST['size_mode'] ?? '', ['keep', 'auto', 'square', 'landscape', 'portrait'], true) ? $_POST['size_mode'] : 'keep';
        // مدل‌ها به ترتیب اولویت (عدد کمتر = اول)
        $pick = [];
        foreach ((array)($_POST['m'][$kind] ?? []) as $mid => $on) {
            if (!$on) continue;
            $pick[(int)$mid] = (int)($_POST['mp'][$kind][$mid] ?? 99);
        }
        asort($pick);
        $model_ids = implode(',', array_slice(array_keys($pick), 0, 20));
        $ask = trim(mb_substr((string)($_POST['ask_label'] ?? ''), 0, 120));
        if ($ask === '' && (strpos($prompt, '{input}') !== false || strpos($prompt, '{متن}') !== false)) $ask = 'توضیح';
        $vals = [$kind, $title, trim(mb_substr((string)($_POST['icon'] ?? '⚡'), 0, 8)) ?: '⚡', trim(mb_substr((string)($_POST['description'] ?? ''), 0, 255)), $prompt, $ref,
                 $ask, trim(mb_substr((string)($_POST['ask_hint'] ?? ''), 0, 200)), $model_ids, $size,
                 !empty($_POST['keep_dims']) ? 1 : 0, !empty($_POST['transparent']) ? 1 : 0, max(2, min(20, (int)($_POST['seconds'] ?? 4))),
                 !empty($_POST['in_studio']) ? 1 : 0, !empty($_POST['in_bot']) ? 1 : 0, !empty($_POST['is_active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0), $now];
        if ($id) {
            $vals[] = $id;
            $pdo->prepare("UPDATE saas_gen_actions SET kind=?, title=?, icon=?, description=?, prompt=?, ref=?, ask_label=?, ask_hint=?, model_ids=?, size_mode=?, keep_dims=?, transparent=?, seconds=?, in_studio=?, in_bot=?, is_active=?, sort_order=?, updated_at=? WHERE id=?")->execute($vals);
        } else {
            $vals[] = $now;
            $pdo->prepare("INSERT INTO saas_gen_actions (kind, title, icon, description, prompt, ref, ask_label, ask_hint, model_ids, size_mode, keep_dims, transparent, seconds, in_studio, in_bot, is_active, sort_order, updated_at, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($vals);
        }
        $back('«' . $title . '» ذخیره شد.');
    }
    if ($act === 'toggle' && $id) {
        $pdo->prepare("UPDATE saas_gen_actions SET is_active = 1 - is_active, updated_at=? WHERE id=?")->execute([$now, $id]);
        $back('وضعیت تغییر کرد.');
    }
    if ($act === 'delete' && $id) {
        $pdo->prepare("DELETE FROM saas_gen_actions WHERE id=?")->execute([$id]);
        $back('حذف شد.');
    }
    if ($act === 'reset_stats') {
        $pdo->exec("UPDATE saas_gen_actions SET runs=0, fails=0");
        $back('آمار صفر شد.');
    }
    if ($act === 'restore') {
        // اقدام‌های پیش‌فرضی که حذف شده‌اند دوباره اضافه می‌شوند (موارد موجود دست نمی‌خورند)
        $have = array_map(fn($r) => $r['kind'] . '|' . $r['title'], ga_list($pdo, null, 'all', false));
        $st = $pdo->prepare("INSERT INTO saas_gen_actions (kind, icon, title, description, prompt, ref, ask_label, ask_hint, size_mode, keep_dims, transparent, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $n = 0;
        foreach (ga_defaults() as $i => $d) {
            if (in_array($d[0] . '|' . $d[2], $have, true)) continue;
            $st->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9], $d[10], ($i + 1) * 10, $now, $now]); $n++;
        }
        $back($n ? $n . ' اقدام پیش‌فرض اضافه شد.' : 'همه اقدام‌های پیش‌فرض موجودند.');
    }
    $back('درخواست نامعتبر است.', true);
}

$models = ['image' => biz_models($pdo, false, 'image'), 'video' => biz_models($pdo, false, 'video')];
$mname = [];
foreach ($models as $k => $l) foreach ($l as $m) $mname[(int)$m['id']] = $m['title'] ?? ('#' . $m['id']);
$rows = ga_list($pdo, null, 'all', false);
$edit = null;
if (!empty($_GET['edit'])) $edit = ga_get($pdo, (int)$_GET['edit']);
if (!$edit && !empty($_GET['new'])) $edit = ['id' => 0, 'kind' => ($_GET['kind'] ?? '') === 'video' ? 'video' : 'image', 'title' => '', 'icon' => '⚡', 'description' => '', 'prompt' => '', 'ref' => 'required',
    'ask_label' => '', 'ask_hint' => '', 'model_ids' => '', 'size_mode' => 'keep', 'keep_dims' => 0, 'transparent' => 0, 'seconds' => 4, 'in_studio' => 1, 'in_bot' => 1, 'is_active' => 1, 'sort_order' => 100];
$refs = ['required' => 'لازم است (کار روی تصویر کاربر)', 'optional' => 'اختیاری', 'none' => 'ندارد (ساخت از صفر)'];
$sizes = ['keep' => 'هم‌نسبت تصویر ورودی', 'auto' => 'خودکار', 'square' => 'مربع', 'landscape' => 'افقی', 'portrait' => 'عمودی'];

include __DIR__ . '/_header.php';
?>
<h2 class="page-title">⚡ اقدام‌های آماده تصویر و ویدیو</h2>
<?php if (!empty($_GET['msg'])): ?><div class="msg-ok"><?php echo admin_h($_GET['msg']); ?></div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="msg-err"><?php echo admin_h($_GET['err']); ?></div><?php endif; ?>
<p style="color:#64748b;font-size:13px;margin:-6px 0 14px;line-height:2">هر اقدام یک دکمه است که کاربر در «استودیو تصویر و ویدیو» و مخاطب در چت‌بات می‌بیند؛ با یک کلیک، تصویرِ انتخاب‌شده با دستور آماده‌ی شما و بهترین مدل پردازش می‌شود.
مدل‌ها به ترتیب اولویت امتحان می‌شوند (اگر اولی جواب نداد، دومی). اگر هیچ مدلی انتخاب نکنید، همه مدل‌های پلن کاربر استفاده می‌شوند (مدل‌های مناسب ویرایش تصویر اول).
در دستور می‌توانید <code>{input}</code> بگذارید تا جای «سؤال از کاربر» نوشته شود. دستورها را انگلیسی بنویسید تا مدل‌ها دقیق‌تر اجرا کنند.</p>

<?php if ($edit): $sel = array_values(array_filter(array_map('intval', explode(',', (string)$edit['model_ids'])))); ?>
<div class="card">
    <div class="card-header"><b><?php echo $edit['id'] ? '✏️ ویرایش «' . admin_h($edit['title']) . '»' : '+ اقدام تازه'; ?></b> — <a href="gen_actions.php">بازگشت به فهرست</a></div>
    <div class="card-body">
    <form method="post" id="gaf">
        <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
            <div><label>نوع</label><select name="kind" id="ga_kind"><option value="image" <?php echo $edit['kind'] === 'image' ? 'selected' : ''; ?>>🖼 تصویر</option><option value="video" <?php echo $edit['kind'] === 'video' ? 'selected' : ''; ?>>🎬 ویدیو</option></select></div>
            <div><label>آیکن (ایموجی)</label><input type="text" name="icon" value="<?php echo admin_h($edit['icon']); ?>" maxlength="8"></div>
            <div style="grid-column:span 2"><label>عنوان دکمه</label><input type="text" name="title" value="<?php echo admin_h($edit['title']); ?>" required maxlength="120" placeholder="مثلاً: حذف پس‌زمینه"></div>
            <div style="grid-column:1/-1"><label>توضیح کوتاه (زیر دکمه / راهنما)</label><input type="text" name="description" value="<?php echo admin_h($edit['description']); ?>" maxlength="255"></div>
            <div style="grid-column:1/-1"><label>دستور (پرامپت) — ترجیحاً انگلیسی</label><textarea name="prompt" rows="5" dir="ltr" required><?php echo admin_h($edit['prompt']); ?></textarea></div>
            <div><label>تصویر ورودی</label><select name="ref"><?php foreach ($refs as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $edit['ref'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
            <div><label>ابعاد خروجی</label><select name="size_mode"><?php foreach ($sizes as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $edit['size_mode'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
            <div><label>سؤال از کاربر (اختیاری)</label><input type="text" name="ask_label" value="<?php echo admin_h($edit['ask_label']); ?>" maxlength="120" placeholder="مثلاً: رنگ پس‌زمینه"></div>
            <div><label>مثال برای کاربر</label><input type="text" name="ask_hint" value="<?php echo admin_h($edit['ask_hint']); ?>" maxlength="200" placeholder="مثلاً: آبی نفتی"></div>
            <div class="vid-only"><label>مدت ویدیو (ثانیه)</label><input type="number" name="seconds" min="2" max="20" value="<?php echo (int)$edit['seconds']; ?>"></div>
            <div><label>ترتیب نمایش</label><input type="number" name="sort_order" value="<?php echo (int)$edit['sort_order']; ?>"></div>
        </div>
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin:14px 0;font-size:13.5px">
            <label class="img-only"><input type="checkbox" name="keep_dims" value="1" <?php echo $edit['keep_dims'] ? 'checked' : ''; ?>> خروجی دقیقاً هم‌اندازه تصویر ورودی (پیکسل به پیکسل)</label>
            <label class="img-only"><input type="checkbox" name="transparent" value="1" <?php echo $edit['transparent'] ? 'checked' : ''; ?>> پس‌زمینه شفاف (در مدل‌هایی که پشتیبانی می‌کنند)</label>
            <label><input type="checkbox" name="in_studio" value="1" <?php echo $edit['in_studio'] ? 'checked' : ''; ?>> در استودیو کاربران</label>
            <label><input type="checkbox" name="in_bot" value="1" <?php echo $edit['in_bot'] ? 'checked' : ''; ?>> در چت‌بات‌ها</label>
            <label><input type="checkbox" name="is_active" value="1" <?php echo $edit['is_active'] ? 'checked' : ''; ?>> فعال</label>
        </div>
        <?php foreach (['image' => 'مدل‌های تصویر', 'video' => 'مدل‌های ویدیو'] as $k => $lbl): ?>
        <div class="mlist" data-k="<?php echo $k; ?>">
            <label><b><?php echo $lbl; ?> مناسب این اقدام</b> <span style="color:#64748b;font-size:12px">— تیک بزنید و اولویت بدهید (۱ = اول). هیچ‌کدام = همه مدل‌های پلن.</span></label>
            <?php if (!$models[$k]): ?><p class="muted">هنوز مدلی در این دسته تعریف نشده است (<a href="models.php">مدل‌ها</a>).</p><?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px;margin-top:6px">
            <?php foreach ($models[$k] as $m): $mid = (int)$m['id']; $pos = array_search($mid, $sel, true); ?>
                <div style="display:flex;align-items:center;gap:8px;border:1px solid #e2e8f0;border-radius:10px;padding:6px 10px;<?php echo empty($m['is_active']) ? 'opacity:.55' : ''; ?>">
                    <input type="checkbox" name="m[<?php echo $k; ?>][<?php echo $mid; ?>]" value="1" <?php echo $pos !== false ? 'checked' : ''; ?>>
                    <span style="flex:1;font-size:13px"><?php echo admin_h($m['title'] ?? ''); ?><?php echo ga_edit_capable($m) ? ' <span class="badge badge-blue" title="ویرایش تصویر را پشتیبانی می‌کند">ویرایش</span>' : ''; ?><?php echo empty($m['is_active']) ? ' <span class="badge badge-off">خاموش</span>' : ''; ?></span>
                    <input type="number" name="mp[<?php echo $k; ?>][<?php echo $mid; ?>]" value="<?php echo $pos !== false ? $pos + 1 : ''; ?>" min="1" max="99" style="width:60px" title="اولویت" placeholder="اولویت">
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:14px"><button type="submit" class="btn">💾 ذخیره</button></div>
    </form>
    </div>
</div>
<script>
(function(){
  var k = document.getElementById('ga_kind');
  function sync(){
    var v = k.value;
    document.querySelectorAll('.mlist').forEach(function(e){ e.style.display = e.getAttribute('data-k') === v ? '' : 'none'; });
    document.querySelectorAll('.img-only').forEach(function(e){ e.style.display = v === 'image' ? '' : 'none'; });
    document.querySelectorAll('.vid-only').forEach(function(e){ e.style.display = v === 'video' ? '' : 'none'; });
  }
  k.addEventListener('change', sync); sync();
  // تیک زدن بدون اولویت: اولویت بعدی خودکار
  document.querySelectorAll('.mlist input[type=checkbox]').forEach(function(c){
    c.addEventListener('change', function(){
      var n = c.parentNode.querySelector('input[type=number]'); if (!c.checked) { n.value = ''; return; }
      if (n.value) return;
      var mx = 0; c.closest('.mlist').querySelectorAll('input[type=number]').forEach(function(x){ mx = Math.max(mx, +x.value || 0); }); n.value = mx + 1;
    });
  });
})();
</script>
<?php else: ?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <a class="btn" href="gen_actions.php?new=1&kind=image" style="background:#2563eb;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-size:13px">+ اقدام تصویر</a>
    <a class="btn" href="gen_actions.php?new=1&kind=video" style="background:#2563eb;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-size:13px">+ اقدام ویدیو</a>
    <form method="post" style="margin:0"><input type="hidden" name="act" value="restore"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><button class="btn btn-sm" style="background:#64748b">↺ افزودن پیش‌فرض‌های حذف‌شده</button></form>
    <form method="post" style="margin:0" onsubmit="return confirm('آمار اجرا صفر شود؟')"><input type="hidden" name="act" value="reset_stats"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><button class="btn btn-sm" style="background:#64748b">صفر کردن آمار</button></form>
</div>
<div class="card"><div class="card-body table-wrap">
<?php if (!$rows): ?><p class="muted">اقدامی تعریف نشده است.</p><?php else: ?>
<table class="table"><thead><tr><th>اقدام</th><th>نوع</th><th>تصویر ورودی</th><th>مدل‌ها (به ترتیب)</th><th>نمایش</th><th>اجرا / ناموفق</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $ids = array_values(array_filter(array_map('intval', explode(',', (string)$r['model_ids'])))); $runs = (int)$r['runs']; $fails = (int)$r['fails']; ?>
<tr style="<?php echo $r['is_active'] ? '' : 'opacity:.55'; ?>">
    <td style="min-width:190px"><b><?php echo admin_h($r['icon'] . ' ' . $r['title']); ?></b><div style="font-size:12px;color:#64748b"><?php echo admin_h($r['description']); ?><?php echo $r['ask_label'] !== '' ? ' — می‌پرسد: «' . admin_h($r['ask_label']) . '»' : ''; ?></div></td>
    <td><?php echo $r['kind'] === 'video' ? '🎬 ویدیو' : '🖼 تصویر'; ?></td>
    <td style="font-size:12px"><?php echo admin_h(['required' => 'لازم', 'optional' => 'اختیاری', 'none' => 'ندارد'][$r['ref']] ?? $r['ref']); ?><?php echo $r['keep_dims'] ? '<br><span class="badge badge-blue">هم‌اندازه</span>' : ''; ?><?php echo $r['transparent'] ? ' <span class="badge badge-purple">شفاف</span>' : ''; ?></td>
    <td style="font-size:12px"><?php echo $ids ? admin_h(implode(' ← ', array_map(fn($i) => $mname[$i] ?? ('#' . $i . ' (حذف‌شده)'), $ids))) : '<span class="muted">همه مدل‌های پلن</span>'; ?></td>
    <td style="font-size:12px"><?php echo $r['in_studio'] ? 'استودیو ' : ''; ?><?php echo $r['in_bot'] ? 'چت‌بات' : ''; ?><?php echo $r['is_active'] ? '' : '<br><span class="badge badge-off">غیرفعال</span>'; ?></td>
    <td style="font-size:12px"><?php echo number_format($runs); ?><?php if ($fails): ?> / <span style="color:#b91c1c"><?php echo number_format($fails); ?></span><?php endif; ?><?php if ($runs + $fails >= 5): ?><br><span class="badge <?php echo $fails / max(1, $runs + $fails) > .25 ? 'badge-err' : 'badge-ok'; ?>"><?php echo round(100 * $runs / max(1, $runs + $fails)); ?>٪ موفق</span><?php endif; ?></td>
    <td style="white-space:nowrap">
        <a class="btn btn-sm" href="gen_actions.php?edit=<?php echo (int)$r['id']; ?>">ویرایش</a>
        <form method="post" style="display:inline"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><button class="btn btn-sm" style="background:#64748b"><?php echo $r['is_active'] ? 'خاموش' : 'روشن'; ?></button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><button class="btn btn-sm" style="background:#dc2626">حذف</button></form>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
