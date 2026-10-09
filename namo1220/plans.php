<?php
require_once __DIR__ . '/_bootstrap.php';

$msg = '';
pc_ensure_schema($pdo);   // دسته‌بندی پلن‌ها (نسخه ۴۵)

// ── دسته‌های پلن: ذخیره / حذف ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pc_act'])) {
    $pc_act = (string)$_POST['pc_act'];
    $cid = (int)($_POST['cid'] ?? 0);
    if ($pc_act === 'cat_save') {
        $title = trim(mb_substr((string)($_POST['c_title'] ?? ''), 0, 100));
        if (mb_strlen($title) < 2) { header('Location: plans.php?cat=cats&ce=' . $cid . '&msg=' . urlencode('error:عنوان دسته را بنویسید.')); exit; }
        $slug = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', trim((string)($_POST['c_slug'] ?? ''))));
        $slug = trim(substr($slug, 0, 40), '-');
        foreach (pc_cats($pdo, false, true) as $oc) if ($slug !== '' && $oc['slug'] === $slug && (int)$oc['id'] !== $cid) $slug = '';   // تکراری
        // فیلدهای اختصاصی
        $fields = [];
        $fk = (array)($_POST['f_k'] ?? []); $fl = (array)($_POST['f_label'] ?? []); $ft = (array)($_POST['f_type'] ?? []); $fu = (array)($_POST['f_unit'] ?? []);
        foreach ($fl as $i => $lab) {
            $lab = trim(mb_substr((string)$lab, 0, 80));
            if ($lab === '') continue;
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($fk[$i] ?? '')));
            if ($k === '') $k = 'f' . substr(md5($lab . microtime(true) . $i), 0, 7);
            $type = array_key_exists((string)($ft[$i] ?? ''), pc_field_types()) ? (string)$ft[$i] : 'text';
            $fields[] = ['k' => $k, 'label' => $lab, 'type' => $type, 'unit' => trim(mb_substr((string)($fu[$i] ?? ''), 0, 20))];
            if (count($fields) >= 20) break;
        }
        $all_f = array_keys(saas_plan_features());
        $feat = array_values(array_intersect($all_f, array_map('strval', (array)($_POST['c_feat'] ?? []))));
        $feat_s = count($feat) === count($all_f) ? '' : implode(',', $feat);   // همه = خالی (امکانات جدید آینده هم مجاز)
        $tpl = preg_replace('/[^a-z_]/', '', (string)($_POST['c_tpl'] ?? ''));
        $gen = !empty($_POST['c_general']) ? 1 : 0;
        $vals = [$title, trim(mb_substr((string)($_POST['c_icon'] ?? ''), 0, 8)), $slug, trim(mb_substr((string)($_POST['c_desc'] ?? ''), 0, 400)),
                 $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE) : null, $feat_s, $tpl, $gen, !empty($_POST['c_active']) || $gen ? 1 : 0, (int)($_POST['c_sort'] ?? 0)];
        if ($cid) {
            $vals[] = $cid;
            $pdo->prepare("UPDATE saas_plan_cats SET title=?, icon=?, slug=?, description=?, fields=?, features=?, bot_template=?, is_general=?, is_active=?, sort_order=? WHERE id=?")->execute($vals);
        } else {
            $vals[] = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO saas_plan_cats (title, icon, slug, description, fields, features, bot_template, is_general, is_active, sort_order, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")->execute($vals);
            $cid = (int)$pdo->lastInsertId();
        }
        if ($slug === '') $pdo->prepare("UPDATE saas_plan_cats SET slug=? WHERE id=?")->execute(['c' . $cid, $cid]);
        if ($gen) $pdo->prepare("UPDATE saas_plan_cats SET is_general=0 WHERE id<>?")->execute([$cid]);   // فقط یک دسته عمومی
        pc_cats($pdo, false, true);   // به‌روزرسانی کش
        pc_enforce_features($pdo, pc_cat($pdo, $cid));
        header('Location: plans.php?cat=cats&msg=' . urlencode('ok:دسته «' . $title . '» ذخیره شد.')); exit;
    }
    if ($pc_act === 'cat_del' && $cid) {
        $c = pc_cat($pdo, $cid);
        if ($c && !empty($c['is_general'])) { header('Location: plans.php?cat=cats&msg=' . urlencode('error:دسته عمومی حذف نمی‌شود؛ ابتدا دسته دیگری را «عمومی» کنید.')); exit; }
        $pdo->prepare("UPDATE saas_plans SET cat_id=0 WHERE cat_id=?")->execute([$cid]);   // پلن‌هایش به «عمومی» منتقل می‌شوند
        $pdo->prepare("DELETE FROM saas_plan_cats WHERE id=?")->execute([$cid]);
        header('Location: plans.php?cat=cats&msg=' . urlencode('ok:دسته حذف شد؛ پلن‌های آن به دسته عمومی منتقل شدند.')); exit;
    }
    header('Location: plans.php?cat=cats'); exit;
}

if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $pdo->prepare("DELETE FROM saas_plans WHERE id=?")->execute([(int)$_GET['del']]);
    header('Location: plans.php?msg=ok:پلن حذف شد.'); exit;
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare("UPDATE saas_plans SET is_active = 1 - is_active WHERE id=?")->execute([(int)$_GET['toggle']]);
    header('Location: plans.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    // مدل‌ها (نسخه ۵۹): تیک‌خورده = مجاز؛ مدل‌های تیک‌نخورده «بسته» می‌شوند و مدل‌هایی که بعداً تعریف شوند خودکار مجازند
    if (function_exists('mx_schema')) mx_schema($pdo);
    $m_checked = array_values(array_filter(array_map('intval', (array)($_POST['allowed_models'] ?? []))));
    $m_shown = array_values(array_filter(array_map('intval', (array)($_POST['mdl_shown'] ?? []))));
    $edit_custom = 0;
    if ($edit_id) { try { $ec = $pdo->prepare("SELECT is_custom FROM saas_plans WHERE id=?"); $ec->execute([$edit_id]); $edit_custom = (int)$ec->fetchColumn(); } catch (\Throwable $e) {} }
    $data = [
        'name'                  => trim($_POST['name'] ?? ''),
        'description'           => trim($_POST['description'] ?? ''),
        'price_toman'           => max(0, (int)str_replace(',', '', $_POST['price_toman'] ?? '0')),
        'credit_tokens'         => max(0, (int)str_replace(',', '', $_POST['credit_tokens'] ?? '0')),
        'max_requests_per_day'  => max(1, (int)($_POST['max_requests_per_day'] ?? 100)),
        'max_tokens_per_request'=> max(100, (int)($_POST['max_tokens_per_request'] ?? 500)),
        'response_length'       => in_array($_POST['response_length']??'', ['short','medium','long']) ? $_POST['response_length'] : 'medium',
        'has_knowledge_base'    => !empty($_POST['has_knowledge_base']) ? 1 : 0,
        'max_knowledge_items'   => max(0, (int)($_POST['max_knowledge_items'] ?? 10)),
        'has_products'          => !empty($_POST['has_products']) ? 1 : 0,
        'max_products'          => max(0, (int)($_POST['max_products'] ?? 0)),
        'max_bots'              => max(0, (int)($_POST['max_bots'] ?? 1)),
        'has_color_customize'   => !empty($_POST['has_color_customize']) ? 1 : 0,
        'has_product_api'       => !empty($_POST['has_product_api']) ? 1 : 0,
        'has_conversations'     => !empty($_POST['has_conversations']) ? 1 : 0,
        'has_answer_archive'    => !empty($_POST['has_answer_archive']) ? 1 : 0,
        'is_active'             => !empty($_POST['is_active']) ? 1 : 0,
        'sort_order'            => (int)($_POST['sort_order'] ?? 0),
        'free_sms'              => max(0, (int)($_POST['free_sms'] ?? 0)),
        'discount_percent'      => max(0, min(90, (int)($_POST['discount_percent'] ?? 0))),
        'discount_until'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['discount_until'] ?? '')) ? $_POST['discount_until'] . ' 23:59:59' : null,
        'banner_overlay'        => !empty($_POST['banner_overlay']) ? 1 : 0,
        'installments'          => max(1, min(24, (int)($_POST['installments'] ?? 1))),
        'installment_days'      => max(7, min(90, (int)($_POST['installment_days'] ?? 30))),
        'setup_fee'             => max(0, (int)str_replace(',', '', (string)($_POST['setup_fee'] ?? '0'))),
        'allowed_models'        => $edit_custom ? implode(',', $m_checked) : '',
        'blocked_models'        => $edit_custom ? '' : implode(',', array_values(array_diff($m_shown, $m_checked))),
        'default_model'         => max(0, (int)($_POST['default_model'] ?? 0)),
        'limit_in'              => max(0, (int)str_replace(',', '', (string)($_POST['limit_in'] ?? '0'))),
        'limit_out'             => max(0, (int)str_replace(',', '', (string)($_POST['limit_out'] ?? '0'))),
        'extra_features'        => mb_substr(trim((string)($_POST['extra_features'] ?? '')), 0, 3000),
        'feature_order'         => mb_substr(preg_replace('/[^a-z0-9_,]/', '', strtolower((string)($_POST['feature_order'] ?? ''))), 0, 2000),
    ];
    if (!empty($_POST['banner_remove'])) $data['banner_url'] = '';

    // توضیح کوتاه هر امکان/ردیف (حداکثر ۲۰۰ کاراکتر)
    $notes = [];
    foreach ((array)($_POST['note'] ?? []) as $nk => $nv) {
        $nk = preg_replace('/[^a-z_]/', '', (string)$nk);
        $nv = mb_substr(trim((string)$nv), 0, 200);
        if ($nk !== '' && $nv !== '') $notes[$nk] = $nv;
    }
    $data['feature_notes'] = $notes ? json_encode($notes, JSON_UNESCAPED_UNICODE) : null;

    // همه امکانات تعریف‌شده (از جمله مطالب و صفحات سایت)
    foreach (array_keys(saas_plan_features()) as $fk) {
        $data[$fk] = !empty($_POST[$fk]) ? 1 : 0;
    }

    // دسته پلن (نسخه ۴۵): امکانات نامرتبط با دسته خاموش + مقدار فیلدهای اختصاصی دسته
    $pcat = pc_cat($pdo, (int)($_POST['cat_id'] ?? 0));
    $data['cat_id'] = $pcat ? (int)$pcat['id'] : 0;
    $pcat = $pcat ?: pc_general($pdo);
    $cv = [];
    if ($pcat) {
        foreach (array_keys(saas_plan_features()) as $fk) if (!pc_feature_allowed($pcat, $fk)) $data[$fk] = 0;
        $in = (array)($_POST['cf'][(int)$pcat['id']] ?? []);
        foreach ($pcat['fields_list'] as $f) {
            $v = $f['type'] === 'yesno' ? (!empty($in[$f['k']]) ? '1' : '') : trim(mb_substr((string)($in[$f['k']] ?? ''), 0, 120));
            if ($f['type'] === 'number' && $v !== '') $v = preg_replace('/[^0-9.]/', '', strtr($v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',','=>'']));
            if ($v !== '') $cv[$f['k']] = $v;
        }
    }
    $data['cat_values'] = $cv ? json_encode($cv, JSON_UNESCAPED_UNICODE) : null;

    if (mb_strlen($data['name']) < 2) { $msg = 'error:نام پلن الزامی است.'; }
    else {
        if ($edit_id) {
            $sets = array_map(fn($k) => "`$k`=?", array_keys($data));
            $vals = array_values($data);
            $vals[] = $edit_id;
            $pdo->prepare("UPDATE saas_plans SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
            $msg = 'ok:پلن بروزرسانی شد.';
            $saved_id = $edit_id;
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
            $phs  = implode(',', array_fill(0, count($data), '?'));
            $pdo->prepare("INSERT INTO saas_plans ($cols) VALUES($phs)")->execute(array_values($data));
            $msg = 'ok:پلن جدید ایجاد شد.';
            $saved_id = (int)$pdo->lastInsertId();
        }
        // تصویر معرفی پلن (ابعاد ثابت ۸۰۰×۳۰۰)
        if (!empty($_FILES['banner']['name']) && $saved_id) {
            $up = biz_save_plan_banner($_FILES['banner'], $saved_id);
            if ($up['ok']) $pdo->prepare("UPDATE saas_plans SET banner_url=? WHERE id=?")->execute([$up['url'], $saved_id]);
            else $msg .= ' (تصویر ذخیره نشد: ' . $up['error'] . ')';
        }
        header('Location: plans.php?' . ($data['cat_id'] ? 'cat=' . $data['cat_id'] . '&' : '') . 'msg=' . urlencode($msg)); exit;
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

$edit_item = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM saas_plans WHERE id=?");
    $es->execute([(int)$_GET['edit']]);
    $edit_item = $es->fetch();
}

$plans_stmt = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM saas_users u WHERE u.plan_id=p.id) as user_count FROM saas_plans p ORDER BY sort_order, price_toman");
$plans = $plans_stmt ? $plans_stmt->fetchAll() : [];
// پلن‌های دلخواه کاربران در صفحه «پلن دلخواه» نمایش داده می‌شوند (مگر همان پلنی که در حال ویرایش است)
$custom_count = count(array_filter($plans, fn($p) => !empty($p['is_custom'])));
$plans = array_values(array_filter($plans, fn($p) => empty($p['is_custom'])));

// زبانه‌ها: همه | هر دسته | ⚙️ دسته‌ها
$pc_all = pc_cats($pdo, false, true);
$pc_gen = pc_general($pdo);
$pc_of = function ($p) use ($pc_all, $pc_gen) { foreach ($pc_all as $c) if ((int)$c['id'] === (int)($p['cat_id'] ?? 0)) return $c; return $pc_gen; };
$tab = (string)($_GET['cat'] ?? '');
$tab_cat = ctype_digit($tab) ? pc_cat($pdo, (int)$tab) : null;
if ($tab !== 'cats' && !$tab_cat) $tab = '';
$pc_count = [];
foreach ($plans as $p) { $c = $pc_of($p); $k = $c ? (int)$c['id'] : 0; $pc_count[$k] = ($pc_count[$k] ?? 0) + 1; }
if ($tab_cat) $plans = array_values(array_filter($plans, fn($p) => ($c = $pc_of($p)) && (int)$c['id'] === (int)$tab_cat['id']));
$form_cat = $edit_item ? ($pc_of($edit_item)['id'] ?? 0) : ($tab_cat['id'] ?? ($pc_gen['id'] ?? 0));

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">📦 مدیریت پلن‌ها</h2>';
if ($custom_count || (function_exists('cx_cplan_cfg') && cx_cplan_cfg($pdo)['on'])) echo '<div class="card" style="padding:10px 14px">🧩 <a href="custom_plan.php">پلن دلخواه کاربران</a> — قیمت هر امکان برای ساخت پلن توسط خود کاربر' . ($custom_count ? ' (' . (int)$custom_count . ' پلن ساخته شده)' : '') . '</div>';
?>

<?php if ($msg): ?>
<div class="msg-<?php echo str_starts_with($msg,'ok:') ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>

<style>
.pc-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.pc-tabs a{padding:7px 14px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#475569;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap}
.pc-tabs a.on{background:#7c3aed;border-color:#7c3aed;color:#fff}
.pc-tabs a small{opacity:.7;font-weight:400}
.pc-tabs .sp{flex:1}
</style>
<div class="pc-tabs">
    <a href="plans.php" class="<?php echo $tab === '' ? 'on' : ''; ?>">همه پلن‌ها <small>(<?php echo array_sum($pc_count); ?>)</small></a>
    <?php foreach ($pc_all as $c): ?><a href="plans.php?cat=<?php echo (int)$c['id']; ?>" class="<?php echo $tab_cat && (int)$tab_cat['id'] === (int)$c['id'] ? 'on' : ''; ?>"<?php echo empty($c['is_active']) ? ' style="opacity:.55" title="غیرفعال (در سایت نمایش داده نمی‌شود)"' : ''; ?>><?php echo htmlspecialchars($c['icon'] . ' ' . $c['title']); ?> <small>(<?php echo (int)($pc_count[(int)$c['id']] ?? 0); ?>)</small></a><?php endforeach; ?>
    <span class="sp"></span>
    <a href="plans.php?cat=cats" class="<?php echo $tab === 'cats' ? 'on' : ''; ?>">⚙️ دسته‌ها و فیلدها</a>
</div>

<?php if ($tab === 'cats'):
    $ce = isset($_GET['ce']) ? pc_cat($pdo, (int)$_GET['ce']) : null;
    $tpls = function_exists('hd_templates') ? hd_templates() : ['medical' => ['label' => '🩺 مشاور سلامت و پزشکی'], 'business' => ['label' => 'کسب‌وکار'], 'legal' => ['label' => '⚖️ حقوقی'], 'education' => ['label' => 'آموزش'], 'sales' => ['label' => 'فروش'], 'custom' => ['label' => 'دلخواه']];
    $cf_rows = $ce ? $ce['fields_list'] : [];
?>
<div class="card">
    <h3 style="margin-bottom:6px;font-size:15px">⚙️ دسته‌های پلن</h3>
    <p style="font-size:12.5px;color:#64748b;line-height:2;margin-bottom:12px">پلن‌ها در سایت، صفحه خرید و ثبت‌نام به تفکیک همین دسته‌ها (به‌صورت زبانه) نمایش داده می‌شوند. برای هر دسته «امکانات مرتبط» را انتخاب کنید (بقیه در فرم پلن‌های آن دسته پنهان و خاموش می‌شوند) و «فیلدهای اختصاصی» آن را بسازید تا هر پلن مقدار خودش را داشته باشد. پلن‌های بدون دسته در دسته «عمومی» نمایش داده می‌شوند.</p>
    <table>
        <thead><tr><th>دسته</th><th>نامک (آدرس)</th><th>پلن‌ها</th><th>فیلدهای اختصاصی</th><th>امکانات</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pc_all as $c): ?>
        <tr>
            <td><b><?php echo htmlspecialchars($c['icon'] . ' ' . $c['title']); ?></b><?php if (!empty($c['is_general'])): ?> <span class="badge-ok" style="font-size:10.5px">عمومی</span><?php endif; ?><div style="font-size:11.5px;color:#94a3b8"><?php echo htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')); ?></div></td>
            <td dir="ltr" style="font-size:12px"><a href="../plans.php?c=<?php echo urlencode($c['slug']); ?>" target="_blank"><?php echo htmlspecialchars($c['slug']); ?></a></td>
            <td><?php echo (int)($pc_count[(int)$c['id']] ?? 0); ?></td>
            <td style="font-size:12px;color:#475569"><?php echo $c['fields_list'] ? htmlspecialchars(implode('، ', array_column($c['fields_list'], 'label'))) : '—'; ?></td>
            <td style="font-size:12px;color:#475569"><?php echo $c['feature_list'] ? count($c['feature_list']) . ' از ' . count(saas_plan_features()) : 'همه'; ?></td>
            <td><span class="<?php echo $c['is_active'] ? 'badge-ok' : 'badge-off'; ?>"><?php echo $c['is_active'] ? 'فعال' : 'غیرفعال'; ?></span></td>
            <td style="white-space:nowrap">
                <a href="plans.php?cat=cats&ce=<?php echo (int)$c['id']; ?>#catf" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">ویرایش</a>
                <?php if (empty($c['is_general'])): ?><form method="post" style="display:inline" onsubmit="return confirm('دسته حذف شود؟ پلن‌های آن به دسته عمومی منتقل می‌شوند.')"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="pc_act" value="cat_del"><input type="hidden" name="cid" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" id="catf">
    <h3 style="margin-bottom:14px;font-size:15px"><?php echo $ce ? '✏️ ویرایش دسته «' . htmlspecialchars($ce['title']) . '»' : '➕ دسته تازه'; ?></h3>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="pc_act" value="cat_save"><input type="hidden" name="cid" value="<?php echo (int)($ce['id'] ?? 0); ?>">
        <div class="row2">
            <div><label>عنوان دسته *</label><input type="text" name="c_title" maxlength="100" required value="<?php echo htmlspecialchars($ce['title'] ?? ''); ?>" placeholder="مثلاً: پزشک من"></div>
            <div style="display:grid;grid-template-columns:90px 1fr 90px;gap:8px">
                <div><label>آیکن</label><input type="text" name="c_icon" maxlength="8" value="<?php echo htmlspecialchars($ce['icon'] ?? ''); ?>" placeholder="🩺"></div>
                <div><label>نامک (انگلیسی، برای آدرس)</label><input type="text" name="c_slug" maxlength="40" dir="ltr" value="<?php echo htmlspecialchars($ce['slug'] ?? ''); ?>" placeholder="doctor"></div>
                <div><label>ترتیب</label><input type="number" name="c_sort" value="<?php echo (int)($ce['sort_order'] ?? 60); ?>"></div>
            </div>
        </div>
        <label>توضیح کوتاه (زیر زبانه در سایت)</label>
        <input type="text" name="c_desc" maxlength="400" value="<?php echo htmlspecialchars($ce['description'] ?? ''); ?>" placeholder="برای چه کسانی است؟">
        <div class="row2" style="margin-top:8px">
            <div><label>قالب پیش‌فرض چت‌بات تخصصی کاربران این دسته</label>
                <select name="c_tpl"><option value="">— بدون پیش‌فرض —</option><?php foreach ($tpls as $tk => $tv): ?><option value="<?php echo htmlspecialchars($tk); ?>" <?php echo ($ce['bot_template'] ?? '') === $tk ? 'selected' : ''; ?>><?php echo htmlspecialchars($tv['label'] ?? $tk); ?></option><?php endforeach; ?></select></div>
            <div style="display:flex;gap:18px;align-items:flex-end;padding-bottom:8px;font-size:13px">
                <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="c_active" value="1" style="width:17px!important" <?php echo !$ce || !empty($ce['is_active']) ? 'checked' : ''; ?>> فعال (نمایش در سایت)</label>
                <label style="display:flex;gap:6px;align-items:center;margin:0" title="پلن‌های بدون دسته در این دسته نمایش داده می‌شوند"><input type="checkbox" name="c_general" value="1" style="width:17px!important" <?php echo !empty($ce['is_general']) ? 'checked' : ''; ?>> دسته عمومی</label>
            </div>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:4px">🧩 فیلدهای اختصاصی این دسته</p>
            <p style="font-size:12px;color:#64748b;margin-bottom:8px">مثلاً برای «پزشک من»: «تعداد بیماران فعال» (عدد، واحد: نفر) یا «یادآور ویزیت» (دارد/ندارد). مقدار هر فیلد در فرم هر پلن وارد می‌شود و روی کارت پلن در سایت نمایش داده می‌شود (فیلد خالی نمایش داده نمی‌شود).</p>
            <div id="cfrows">
                <?php foreach (array_merge($cf_rows, [[]]) as $f): ?>
                <div class="cfr" style="display:grid;grid-template-columns:1fr 130px 110px 34px;gap:6px;margin-bottom:6px">
                    <input type="hidden" name="f_k[]" value="<?php echo htmlspecialchars($f['k'] ?? ''); ?>">
                    <input type="text" name="f_label[]" maxlength="80" placeholder="عنوان فیلد" value="<?php echo htmlspecialchars($f['label'] ?? ''); ?>">
                    <select name="f_type[]"><?php foreach (pc_field_types() as $tk => $tl): ?><option value="<?php echo $tk; ?>" <?php echo ($f['type'] ?? 'number') === $tk ? 'selected' : ''; ?>><?php echo $tl; ?></option><?php endforeach; ?></select>
                    <input type="text" name="f_unit[]" maxlength="20" placeholder="واحد (اختیاری)" value="<?php echo htmlspecialchars($f['unit'] ?? ''); ?>">
                    <button type="button" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;padding:0" onclick="var r=this.closest('.cfr');if(document.querySelectorAll('.cfr').length>1)r.remove();else r.querySelectorAll('input[type=text]').forEach(function(i){i.value='';})" title="حذف">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm" style="background:#f1f5f9;color:#374151" onclick="var a=document.querySelectorAll('.cfr'),r=a[a.length-1].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value='';});document.getElementById('cfrows').appendChild(r);">+ فیلد دیگر</button>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:4px">✅ امکانات مرتبط با این دسته</p>
            <p style="font-size:12px;color:#64748b;margin-bottom:8px">فقط امکانات تیک‌خورده در فرم پلن‌های این دسته دیده می‌شوند؛ بقیه برای پلن‌های این دسته خاموش و پنهان است (مثلاً «دستیار من» بدون چت‌بات تخصصی).</p>
            <div class="feat-grid">
                <?php foreach (saas_plan_features() as $fk => $f): $on = !$ce || empty($ce['feature_list']) || in_array($fk, $ce['feature_list'], true); ?>
                <div class="feat-item"><label><input type="checkbox" name="c_feat[]" value="<?php echo htmlspecialchars($fk); ?>" <?php echo $on ? 'checked' : ''; ?>> <?php echo $f['icon'] . ' ' . htmlspecialchars($f['label']); ?></label></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $ce ? 'ذخیره دسته' : 'ایجاد دسته'; ?></button>
            <?php if ($ce): ?><a href="plans.php?cat=cats" class="btn btn-sm" style="background:#f1f5f9;color:#374151">دسته تازه</a><?php endif; ?>
        </div>
    </form>
</div>
<style>
.plan-row { background:#f8fafc; border:1px solid #eef2f7; border-radius:10px; padding:12px 14px; margin-top:10px; }
.feat-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.feat-item { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; }
.feat-item > label { display:flex; align-items:center; gap:8px; font-weight:600; font-size:13px; cursor:pointer; margin:0; }
.feat-item input[type=checkbox] { width:18px !important; height:18px; flex:0 0 18px; margin:0; padding:0; }
@media (max-width: 800px) { .feat-grid { grid-template-columns:1fr; } .cfr{grid-template-columns:1fr 1fr!important} }
</style>
<?php else: ?>

<!-- فرم پلن -->
<div class="card">
    <h3 style="margin-bottom:16px;font-size:15px"><?php echo $edit_item ? '✏️ ویرایش پلن' : '➕ افزودن پلن جدید'; ?></h3>
    <form method="post" enctype="multipart/form-data">
        <?php
        $notes = $edit_item ? saas_plan_feature_notes($edit_item) : [];
        $note_input = function ($key) use ($notes) {
            return '<input type="text" name="note[' . $key . ']" maxlength="200" class="plan-note" placeholder="توضیح کوتاه برای نمایش به کاربران (حداکثر ۲۰۰ کاراکتر)" value="' . htmlspecialchars($notes[$key] ?? '', ENT_QUOTES) . '" oninput="this.nextElementSibling.textContent=this.value.length+\'/200\'"><small class="note-count">' . mb_strlen($notes[$key] ?? '') . '/200</small>';
        };
        ?>
        <?php if ($edit_item): ?><input type="hidden" name="edit_id" value="<?php echo $edit_item['id']; ?>"><?php endif; ?>

        <div class="row2">
            <div>
                <label>نام پلن *</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($edit_item['name'] ?? '', ENT_QUOTES); ?>" required placeholder="مثلاً: پایه، حرفه‌ای، ویژه">
            </div>
            <div>
                <label>قیمت (تومان) - 0 = رایگان</label>
                <input type="text" name="price_toman" value="<?php echo htmlspecialchars($edit_item['price_toman'] ?? '0', ENT_QUOTES); ?>" dir="ltr">
            </div>
        </div>

        <label>توضیحات (اختیاری)</label>
        <textarea name="description" rows="2" placeholder="توضیح کوتاه این پلن..."><?php echo htmlspecialchars($edit_item['description'] ?? '', ENT_QUOTES); ?></textarea>

        <div class="plan-row" style="border-color:#ddd6fe;background:#faf5ff">
            <label style="font-weight:700">🗂 دسته پلن <small style="color:#64748b;font-weight:normal">— در سایت زیر همین دسته نمایش داده می‌شود (<a href="plans.php?cat=cats">مدیریت دسته‌ها</a>)</small></label>
            <select name="cat_id" id="pc_sel">
                <?php foreach ($pc_all as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$form_cat === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['icon'] . ' ' . $c['title'] . (empty($c['is_active']) ? ' (غیرفعال)' : '')); ?></option><?php endforeach; ?>
            </select>
            <?php $cvals = $edit_item ? pc_values($edit_item) : []; foreach ($pc_all as $c): if (!$c['fields_list']) continue; ?>
            <div class="pc-fields" data-cat="<?php echo (int)$c['id']; ?>" style="margin-top:10px">
                <p style="font-size:12.5px;font-weight:700;color:#6d28d9;margin-bottom:6px">🧩 مشخصات اختصاصی «<?php echo htmlspecialchars($c['title']); ?>» <small style="color:#64748b;font-weight:normal">(خالی = نمایش داده نمی‌شود)</small></p>
                <div class="feat-grid">
                <?php foreach ($c['fields_list'] as $f): $v = (int)$form_cat === (int)$c['id'] ? (string)($cvals[$f['k']] ?? '') : ''; $nm = 'cf[' . (int)$c['id'] . '][' . htmlspecialchars($f['k']) . ']'; ?>
                    <div class="feat-item">
                        <?php if ($f['type'] === 'yesno'): ?>
                        <label><input type="checkbox" name="<?php echo $nm; ?>" value="1" <?php echo $v === '1' ? 'checked' : ''; ?>> <?php echo htmlspecialchars($f['label']); ?></label>
                        <?php else: ?>
                        <label style="font-size:12.5px;margin-bottom:4px"><?php echo htmlspecialchars($f['label']); ?><?php echo $f['unit'] !== '' ? ' <small style="color:#94a3b8;font-weight:normal">(' . htmlspecialchars($f['unit']) . ')</small>' : ''; ?></label>
                        <input type="text" name="<?php echo $nm; ?>" maxlength="120" value="<?php echo htmlspecialchars($v); ?>" <?php echo $f['type'] === 'number' ? 'inputmode="numeric" dir="ltr"' : ''; ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:6px">🖼️ طرح معرفی پلن (جای عنوان و توضیحات)</p>
            <p style="font-size:12px;color:#64748b;line-height:1.9;margin-bottom:8px">ابعاد ثابت <b dir="ltr"><?php echo BIZ_BANNER_W . '×' . BIZ_BANNER_H; ?></b> پیکسل (نسبت ۸ به ۳). تصویر با ابعاد دیگر به‌طور خودکار از وسط برش می‌خورد. JPG / PNG / WEBP تا ۳ مگابایت.</p>
            <div class="row2">
                <div>
                    <input type="file" name="banner" accept="image/jpeg,image/png,image/webp">
                    <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:normal;font-size:12.5px"><input type="checkbox" name="banner_overlay" value="1" style="width:17px!important;height:17px" <?php echo !isset($edit_item['banner_overlay']) || !empty($edit_item['banner_overlay']) ? 'checked' : ''; ?>> عنوان و توضیحات پلن روی تصویر نوشته شود (اگر متن داخل خود طرح است، تیک را بردارید)</label>
                </div>
                <div>
                    <?php if (!empty($edit_item['banner_url'])): ?>
                        <div style="width:100%;max-width:320px;aspect-ratio:<?php echo BIZ_BANNER_W . '/' . BIZ_BANNER_H; ?>;border-radius:10px;background:url('<?php echo htmlspecialchars($edit_item['banner_url'], ENT_QUOTES); ?>') center/cover"></div>
                        <label style="display:flex;align-items:center;gap:8px;margin-top:6px;font-weight:normal;font-size:12.5px"><input type="checkbox" name="banner_remove" value="1" style="width:17px!important;height:17px"> حذف تصویر</label>
                    <?php else: ?><span style="font-size:12px;color:#94a3b8">تصویری انتخاب نشده است.</span><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:6px">💳 اشتراک یک‌ساله (پرداخت نقدی) — تخفیف تمدید زودهنگام در «تنظیمات تجاری» (صفحه خدمات)</p>
            <input type="hidden" name="installments" value="1"><input type="hidden" name="installment_days" value="30">
            <div class="row2">
                <div>
                    <label>🛠 هزینه راه‌اندازی توسط ما (تومان؛ ۰ = ارائه نمی‌شود) <small style="color:#64748b;font-weight:normal">— کاربر هنگام خرید با تیک انتخاب می‌کند</small></label>
                    <input type="text" name="setup_fee" dir="ltr" value="<?php echo (int)($edit_item['setup_fee'] ?? 0); ?>">
                    <?php echo $note_input('setup_fee'); ?>
                </div>
                <div></div>
            </div>
        </div>

        <?php $all_models = biz_models($pdo, false);
              $p_custom = !empty($edit_item['is_custom']);
              $am = array_filter(array_map('intval', explode(',', (string)($edit_item['allowed_models'] ?? ''))));
              $bm = array_filter(array_map('intval', explode(',', (string)($edit_item['blocked_models'] ?? ''))));
              $m_on = fn($id) => $p_custom ? in_array($id, $am, true) : (!in_array($id, $bm, true) && (!$am || in_array($id, $am, true))); ?>
        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:6px">🧠 مدل‌های هوش مصنوعی این پلن</p>
            <?php if (!$all_models): ?>
                <p style="font-size:12px;color:#64748b">هنوز مدلی تعریف نشده است. از صفحه <a href="models.php">مدل‌ها و قیمت‌ها</a> مدل‌ها را اضافه کنید.</p>
            <?php else: ?>
            <p style="font-size:12px;color:#64748b;margin-bottom:8px;line-height:1.9">تیک‌خورده = در اختیار کاربران این پلن و چت‌بات‌هایشان. <b>مدل‌هایی که بعداً در «مدل‌ها و قیمت‌ها» اضافه می‌کنید، خودکار در همه پلن‌ها فعال می‌شوند</b>؛ اگر مدلی را برای این پلن نمی‌خواهید، تیکش را بردارید (مثلاً برای نداشتن ساخت ویدیو، تیک همه مدل‌های ویدیو را بردارید).</p>
            <?php foreach (array_intersect_key(biz_model_categories(), ['text' => 1, 'image' => 1, 'video' => 1]) as $ck => $cl): $cms = array_values(array_filter($all_models, fn($m) => $m['category'] === $ck)); if (!$cms) continue; ?>
            <p style="font-size:12.5px;font-weight:700;color:#475569;margin:8px 0 4px"><?php echo $cl; ?></p>
            <div class="feat-grid">
                <?php foreach ($cms as $m): ?>
                <div class="feat-item"><label><input type="hidden" name="mdl_shown[]" value="<?php echo (int)$m['id']; ?>"><input type="checkbox" name="allowed_models[]" value="<?php echo (int)$m['id']; ?>" <?php echo $m_on((int)$m['id']) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($m['title']); ?> <small style="color:#64748b;font-weight:normal">(<?php echo biz_model_cost_label($pdo, $m); ?>)</small><?php echo $m['is_active'] ? '' : ' <small style="color:#dc2626">غیرفعال</small>'; ?></label></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <label style="margin-top:10px">مدل متنی پیش‌فرض</label>
            <select name="default_model"><option value="0">— اولین مدل مجاز —</option>
                <?php foreach ($all_models as $m): if ($m['category'] !== 'text') continue; ?><option value="<?php echo (int)$m['id']; ?>" <?php echo (int)($edit_item['default_model'] ?? 0) === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['title']); ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>

        <div class="plan-row">
            <div class="row2">
                <div>
                    <label>🔥 تخفیف پلن (درصد؛ ۰ = بدون تخفیف)</label>
                    <input type="number" name="discount_percent" min="0" max="90" value="<?php echo (int)($edit_item['discount_percent'] ?? 0); ?>">
                </div>
                <div>
                    <label>تخفیف تا تاریخ <small style="color:#64748b;font-weight:normal">(خالی = بدون محدودیت)</small></label>
                    <input type="date" name="discount_until" value="<?php echo !empty($edit_item['discount_until']) ? substr($edit_item['discount_until'], 0, 10) : ''; ?>">
                </div>
            </div>
        </div>

        <style>
            .plan-row { background:#f8fafc; border:1px solid #eef2f7; border-radius:10px; padding:12px 14px; margin-top:10px; }
            .plan-row .row2 { margin:0; }
            .plan-note { width:100%; margin-top:6px; font-size:12px !important; padding:7px 10px !important; background:#fff; }
            .note-count { display:block; text-align:left; color:#94a3b8; font-size:10px; margin-top:2px; }
            .feat-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
            .feat-item { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; }
            .feat-item > label { display:flex; align-items:center; gap:8px; font-weight:600; font-size:13px; cursor:pointer; margin:0; }
            .feat-item input[type=checkbox] { width:18px !important; height:18px; flex:0 0 18px; margin:0; padding:0; }
            @media (max-width: 800px) { .feat-grid { grid-template-columns:1fr; } }
        </style>

        <div class="plan-row">
            <div class="row2">
                <div>
                    <label>💰 مبلغ هدیه (اعتبار اولیه) — تومان <small style="color:#64748b;font-weight:normal">(با فعال شدن پلن به اعتبار تومانی کاربر اضافه می‌شود)</small></label>
                    <input type="text" name="credit_tokens" value="<?php echo $edit_item['credit_tokens'] ?? '50000'; ?>" dir="ltr">
                    <?php echo $note_input('credit_tokens'); ?>
                </div>
                <div>
                    <label>حداکثر درخواست روزانه</label>
                    <input type="number" name="max_requests_per_day" value="<?php echo $edit_item['max_requests_per_day'] ?? '100'; ?>" min="1">
                    <?php echo $note_input('max_requests_per_day'); ?>
                </div>
            </div>
        </div>

        <div class="plan-row">
            <div class="row2">
                <div>
                    <label>حداکثر توکن در هر پیام</label>
                    <input type="number" name="max_tokens_per_request" value="<?php echo $edit_item['max_tokens_per_request'] ?? '500'; ?>" min="100">
                    <?php echo $note_input('max_tokens_per_request'); ?>
                </div>
                <div>
                    <label>طول پاسخ‌ها</label>
                    <select name="response_length">
                        <?php foreach(['short'=>'کوتاه','medium'=>'متوسط','long'=>'بلند'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo ($edit_item['response_length']??'medium')===$v?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $note_input('response_length'); ?>
                </div>
            </div>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:10px">امکانات پلن:</p>
            <div class="feat-grid">
                <?php
                $defaults_on = ['has_knowledge_base', 'has_color_customize', 'has_conversations', 'has_answer_archive', 'has_site_posts', 'has_site_pages'];
                foreach (saas_plan_features() as $fk => $f):
                    $checked = $edit_item ? !empty($edit_item[$fk] ?? (in_array($fk, $defaults_on) ? 1 : 0)) : in_array($fk, $defaults_on);
                ?>
                <div class="feat-item" data-fk="<?php echo $fk; ?>">
                    <label><input type="checkbox" name="<?php echo $fk; ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>> <?php echo $f['icon'] . ' ' . $f['label']; ?></label>
                    <?php echo $note_input($fk); ?>
                </div>
                <?php endforeach; ?>
                <div class="feat-item">
                    <label><input type="checkbox" name="is_active" value="1" <?php echo !isset($edit_item['is_active'])||!empty($edit_item['is_active']) ? 'checked' : ''; ?>> ✅ فعال (نمایش به کاربران)</label>
                </div>
            </div>
        </div>

        <div class="plan-row">
            <div class="row2">
                <div>
                    <label>حداکثر آیتم پایگاه دانش</label>
                    <input type="number" name="max_knowledge_items" value="<?php echo $edit_item['max_knowledge_items'] ?? '10'; ?>" min="0">
                    <?php echo $note_input('max_knowledge_items'); ?>
                </div>
                <div>
                    <label>حداکثر محصول <small style="color:#64748b;font-weight:normal">(۰ = نامحدود)</small></label>
                    <input type="number" name="max_products" value="<?php echo $edit_item['max_products'] ?? '0'; ?>" min="0">
                    <?php echo $note_input('max_products'); ?>
                </div>
            </div>
        </div>

        <div class="plan-row">
            <div class="row2">
                <div>
                    <label>✉️ پیامک رایگان برای مخاطبان <small style="color:#64748b;font-weight:normal">(یک بار با فعال شدن پلن؛ ۰ = ندارد)</small></label>
                    <input type="number" name="free_sms" value="<?php echo (int)($edit_item['free_sms'] ?? 0); ?>" min="0">
                    <?php echo $note_input('free_sms'); ?>
                </div>
                <div>
                    <label>🤖 حداکثر تعداد چت‌بات تخصصی <small style="color:#64748b;font-weight:normal">(۰ = نامحدود؛ فقط اگر امکان «چت‌بات تخصصی» تیک خورده باشد)</small></label>
                    <input type="number" name="max_bots" value="<?php echo $edit_item['max_bots'] ?? '1'; ?>" min="0">
                    <?php echo $note_input('max_bots'); ?>
                </div>
            </div>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:6px">🔢 سقف مصرف هر پیام (ویجت و همه چت‌بات‌های کاربران این پلن)</p>
            <p style="font-size:12px;color:#64748b;margin-bottom:8px;line-height:1.9">توکن ورودی = متن سوال + سابقه گفتگو + اطلاعات پایگاه دانش؛ توکن خروجی = طول پاسخ. اگر ورودی بیشتر شود، قدیمی‌ترین بخش‌های گفتگو و سپس اطلاعات زمینه کوتاه می‌شود. کاربر می‌تواند در پنل خودش سقف کمتری بگذارد. ۰ = بدون سقف.</p>
            <div class="row2">
                <div><label>حداکثر توکن ورودی هر پیام</label><input type="number" name="limit_in" min="0" step="100" value="<?php echo (int)($edit_item['limit_in'] ?? 0); ?>"></div>
                <div><label>حداکثر توکن خروجی هر پیام</label><input type="number" name="limit_out" min="0" step="50" value="<?php echo (int)($edit_item['limit_out'] ?? 0); ?>"></div>
            </div>
        </div>

        <div class="plan-row">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:6px">➕ امکانات اضافه برای معرفی پلن <small style="color:#64748b;font-weight:normal">(هر خط یک مورد؛ فقط برای نمایش در صفحه پلن)</small></p>
            <textarea name="extra_features" rows="3" placeholder="مثلاً: پشتیبانی تلفنی اختصاصی"><?php echo htmlspecialchars((string)($edit_item['extra_features'] ?? '')); ?></textarea>
        </div>

        <div class="plan-row" id="forder">
            <p style="font-size:13px;font-weight:700;color:#374151;margin-bottom:6px">↕️ اولویت نمایش امکانات <small style="color:#64748b;font-weight:normal">(ترتیب نمایش در صفحه پلن‌ها؛ با فلش‌ها جابه‌جا کنید)</small></p>
            <?php if ($edit_item): $fl = function_exists('ex_plan_feature_list_sorted') ? ex_plan_feature_list_sorted($edit_item) : []; ?>
            <input type="hidden" name="feature_order" id="forder_val" value="<?php echo htmlspecialchars(implode(',', array_column($fl, 'key'))); ?>">
            <ol id="forder_list" style="margin:0;padding:0;list-style:none">
                <?php foreach ($fl as $it): ?><li data-k="<?php echo htmlspecialchars($it['key']); ?>" style="display:flex;justify-content:space-between;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:7px 10px;margin-bottom:6px;font-size:13px"><span><?php echo htmlspecialchars($it['text']); ?></span><span style="white-space:nowrap"><button type="button" class="btn btn-sm" style="background:#f1f5f9;padding:3px 9px" onclick="fMove(this,-1)">▲</button> <button type="button" class="btn btn-sm" style="background:#f1f5f9;padding:3px 9px" onclick="fMove(this,1)">▼</button></span></li><?php endforeach; ?>
            </ol>
            <p style="font-size:11.5px;color:#94a3b8">اگر امکانات را تغییر دادید، بعد از ذخیره ترتیب موارد جدید را هم تنظیم کنید.</p>
            <script>
            function fMove(b, d){ var li = b.closest('li'), ul = li.parentNode; if (d < 0 && li.previousElementSibling) ul.insertBefore(li, li.previousElementSibling); if (d > 0 && li.nextElementSibling) ul.insertBefore(li.nextElementSibling, li);
              document.getElementById('forder_val').value = Array.prototype.map.call(ul.children, function(x){ return x.getAttribute('data-k'); }).join(','); }
            </script>
            <?php else: ?>
            <p style="font-size:12px;color:#94a3b8">بعد از ایجاد پلن، از همین بخش (ویرایش پلن) ترتیب امکانات را تعیین کنید.</p>
            <?php endif; ?>
        </div>

        <script>
        (function(){
          var F = <?php echo json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'f' => $c['feature_list']], $pc_all)); ?>, sel = document.getElementById('pc_sel');
          function sync(){
            var id = +sel.value, al = null; F.forEach(function(c){ if (c.id === id) al = c.f; });
            document.querySelectorAll('.pc-fields').forEach(function(d){ d.style.display = +d.getAttribute('data-cat') === id ? '' : 'none'; });
            document.querySelectorAll('.feat-item[data-fk]').forEach(function(d){ var ok = !al || !al.length || al.indexOf(d.getAttribute('data-fk')) >= 0; d.style.display = ok ? '' : 'none'; });
          }
          if (sel) { sel.addEventListener('change', sync); sync(); }
        })();
        </script>

        <div style="margin-top:16px;display:flex;gap:10px">
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $edit_item ? 'ذخیره تغییرات' : 'ایجاد پلن'; ?></button>
            <?php if ($edit_item): ?><a href="plans.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">انصراف</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- لیست پلن‌ها -->
<div class="card">
    <h3 style="margin-bottom:16px;font-size:15px"><?php echo $tab_cat ? 'پلن‌های «' . htmlspecialchars($tab_cat['title']) . '»' : 'پلن‌های تعریف‌شده'; ?></h3>
    <?php if (empty($plans)): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px">هنوز پلنی تعریف نشده است.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>نام</th><?php if (!$tab_cat): ?><th>دسته</th><?php endif; ?><th>قیمت</th><th>اعتبار (تومان)</th><th>امکانات</th><th>کاربران</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p): ?>
        <tr>
            <td><b><?php echo htmlspecialchars($p['name']); ?></b><?php if (!empty($p['banner_url'])): ?> <span title="دارای تصویر معرفی">🖼️</span><?php endif; ?></td>
            <?php if (!$tab_cat): $pcx = $pc_of($p); ?><td style="font-size:12px;white-space:nowrap"><?php echo $pcx ? htmlspecialchars($pcx['icon'] . ' ' . $pcx['title']) : '—'; ?></td><?php endif; ?>
            <td><?php echo $p['price_toman'] > 0 ? number_format($p['price_toman']) . ' تومان' : '<span style="color:#059669">رایگان</span>'; ?>
                <?php $pp = biz_plan_price($p); if ($pp['percent']): ?><div style="font-size:11px;color:#dc2626">🔥 <?php echo $pp['percent']; ?>٪ → <?php echo number_format($pp['price']); ?></div><?php endif; ?></td>
            <td><?php echo number_format($p['credit_tokens']); ?></td>
            <td style="font-size:11px;color:#64748b">
                <?php
                $feats = [];
                if ($p['has_knowledge_base']) $feats[] = '📚 دانش';
                if ($p['has_products']) $feats[] = '🛒 محصولات';
                if ($p['has_color_customize']) $feats[] = '🎨 رنگ';
                if ($p['has_product_api']) $feats[] = '🔌 API';
                if (!isset($p['has_conversations']) || $p['has_conversations']) $feats[] = '💬 مکالمات';
                if (!isset($p['has_answer_archive']) || $p['has_answer_archive']) $feats[] = '📦 آرشیو';
                if (!isset($p['has_site_posts']) || $p['has_site_posts']) $feats[] = '📰 مطالب';
                if (!isset($p['has_site_pages']) || $p['has_site_pages']) $feats[] = '📄 صفحات';
                if (!empty($p['has_expert_bot'])) $feats[] = '🤖 چت‌بات (' . ((int)($p['max_bots'] ?? 0) ?: '∞') . ')';
                echo implode(' | ', $feats) ?: '—';
                ?>
            </td>
            <td><?php echo (int)$p['user_count']; ?></td>
            <td>
                <span class="<?php echo $p['is_active'] ? 'badge-ok' : 'badge-off'; ?>">
                    <?php echo $p['is_active'] ? 'فعال' : 'غیرفعال'; ?>
                </span>
            </td>
            <td>
                <a href="plans.php?edit=<?php echo $p['id']; ?>" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">ویرایش</a>
                <a href="../plan.php?id=<?php echo (int)$p['id']; ?>" target="_blank" class="btn btn-sm" style="background:#e0f2fe;color:#0369a1" title="صفحه معرفی این پلن در سایت">صفحه پلن</a>
                <a href="plans.php?toggle=<?php echo $p['id']; ?>" class="btn btn-sm" style="background:#f1f5f9;color:#475569"><?php echo $p['is_active']?'غیرفعال':'فعال'; ?></a>
                <?php if ($p['user_count'] == 0): ?>
                <a href="plans.php?del=<?php echo $p['id']; ?>" class="btn btn-sm" style="background:#fee2e2;color:#dc2626" onclick="return confirm('حذف شود؟')">حذف</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; // زبانه دسته‌ها ?>
<?php include __DIR__ . '/_footer.php'; ?>
