<?php
/**
 * دسته‌بندی پلن‌های اشتراک (نسخه ۴۵)
 *
 *  - هر پلن یک دسته دارد: «دستیار من (فقط ویجت)»، «مشاور من»، «گرافیست من»، «پزشک من»، «وکیل من»، … و «پلن‌های عمومی».
 *  - هر دسته: عنوان، آیکن، نامک (برای آدرس)، توضیح، ترتیب، فعال/غیرفعال،
 *    «امکانات مرتبط» (امکانات فنی که در پلن‌های این دسته قابل انتخاب و نمایش است؛ بقیه پنهان و خاموش)،
 *    «فیلدهای اختصاصی» (مثلاً برای پزشک من: تعداد بیماران فعال؛ هر پلن مقدار خودش را می‌گیرد و روی کارت پلن نمایش داده می‌شود)
 *    و قالب پیش‌فرض چت‌بات تخصصی برای کاربران این دسته.
 *  - مدیریت: «پلن‌ها» در پنل مدیر کل (زبانه‌های دسته + «⚙️ دسته‌ها»). نمایش: صفحه اصلی، plans.php، plan.php، خرید پلن و ثبت‌نام.
 *  - پلنی که دسته ندارد (یا دسته‌اش حذف شده) در دسته «عمومی» نمایش داده می‌شود.
 * این فایل از انتهای biz_lib.php بارگذاری می‌شود.
 */

if (!defined('PC_SCHEMA_VERSION')) define('PC_SCHEMA_VERSION', 1);

function pc_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.pc_schema_v' . PC_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_plan_cats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            icon VARCHAR(16) NOT NULL DEFAULT '',
            slug VARCHAR(40) NOT NULL DEFAULT '',
            description VARCHAR(400) NOT NULL DEFAULT '',
            fields TEXT NULL,
            features TEXT NULL,
            bot_template VARCHAR(30) NOT NULL DEFAULT '',
            is_general TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        saas_add_column_if_missing($pdo, 'saas_plans', 'cat_id', 'INT NOT NULL DEFAULT 0');
        saas_add_column_if_missing($pdo, 'saas_plans', 'cat_values', 'TEXT NULL');
        if ((int)$pdo->query("SELECT COUNT(*) FROM saas_plan_cats")->fetchColumn() === 0) pc_seed($pdo);
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[PC] schema: ' . $e->getMessage()); }
}

/** دسته‌های پیش‌فرض (فقط بار اول؛ مدیر می‌تواند ویرایش/حذف کند). پلن‌های موجود در «عمومی» می‌مانند. */
function pc_seed($pdo)
{
    $widget = 'has_knowledge_base,has_products,has_product_api,has_conversations,has_answer_archive,has_site_posts,has_site_pages,has_color_customize,has_popups';
    $f = fn(...$rows) => json_encode(array_map(fn($r) => ['k' => $r[0], 'label' => $r[1], 'type' => $r[2], 'unit' => $r[3] ?? ''], $rows), JSON_UNESCAPED_UNICODE);
    $rows = [
        ['دستیار من', '💬', 'assistant', 'فقط ویجت پاسخگوی هوشمند برای سایت و فروشگاه شما', $f(['sites', 'تعداد سایت قابل اتصال', 'number', 'سایت']), $widget, '', 0, 10],
        ['مشاور من', '🧭', 'consultant', 'چت‌بات تخصصی با نام خودتان برای مشاوره به مراجعان، با پرونده، جلسات و یادآور', $f(['clients', 'تعداد مراجع فعال', 'number', 'نفر'], ['sessions', 'جلسات مشاوره در ماه', 'number', 'جلسه']), '', 'business', 0, 20],
        ['گرافیست من', '🎨', 'designer', 'ساخت و ویرایش تصویر و ویدیو با هوش مصنوعی و اقدام‌های آماده', $f(['images', 'تعداد تصویر در ماه', 'number', 'تصویر'], ['video', 'ساخت ویدیو', 'yesno', '']), '', '', 0, 30],
        ['پزشک من', '🩺', 'doctor', 'دستیار هوشمند پزشکان و کلینیک‌ها برای پاسخ به بیماران، پرونده و یادآور ویزیت', $f(['patients', 'تعداد بیماران فعال', 'number', 'نفر'], ['visit_rem', 'یادآور ویزیت و مصرف دارو', 'yesno', '']), '', 'medical', 0, 40],
        ['وکیل من', '⚖️', 'lawyer', 'دستیار هوشمند وکلا و دفاتر حقوقی برای پاسخ به موکلان و پیگیری پرونده‌ها', $f(['cases', 'تعداد پرونده فعال', 'number', 'پرونده'], ['docs', 'بررسی قرارداد و مدارک', 'yesno', '']), '', 'legal', 0, 50],
        ['عمومی (افراد و شرکت‌ها)', '🏢', 'general', 'پلن‌های همه‌کاره برای افراد، کسب‌وکارها و شرکت‌ها', null, '', '', 1, 100],
    ];
    $st = $pdo->prepare("INSERT INTO saas_plan_cats (title, icon, slug, description, fields, features, bot_template, is_general, is_active, sort_order, created_at) VALUES(?,?,?,?,?,?,?,?,1,?,?)");
    foreach ($rows as $r) $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], date('Y-m-d H:i:s')]);
}

/** انواع فیلد اختصاصی */
function pc_field_types() { return ['number' => 'عدد', 'text' => 'متن', 'yesno' => 'دارد/ندارد']; }

function pc_decode(array $c)
{
    $fl = json_decode((string)($c['fields'] ?? ''), true);
    $c['fields_list'] = is_array($fl) ? array_values(array_filter($fl, fn($x) => is_array($x) && ($x['k'] ?? '') !== '' && ($x['label'] ?? '') !== '')) : [];
    $c['feature_list'] = array_values(array_filter(explode(',', (string)($c['features'] ?? ''))));
    $c['slug'] = (string)($c['slug'] ?? '') !== '' ? $c['slug'] : 'c' . (int)$c['id'];
    return $c;
}

/** همه دسته‌ها به ترتیب (با کش درون‌حافظه) */
function pc_cats($pdo, $active_only = true, $fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        try {
            pc_ensure_schema($pdo);
            foreach ($pdo->query("SELECT * FROM saas_plan_cats ORDER BY sort_order ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $cache[] = pc_decode($r);
        } catch (\Throwable $e) { $cache = []; }
    }
    return $active_only ? array_values(array_filter($cache, fn($c) => !empty($c['is_active']))) : $cache;
}

function pc_cat($pdo, $id)
{
    foreach (pc_cats($pdo, false) as $c) if ((int)$c['id'] === (int)$id) return $c;
    return null;
}

function pc_cat_by_slug($pdo, $slug)
{
    foreach (pc_cats($pdo) as $c) if ($c['slug'] === (string)$slug) return $c;
    return null;
}

/** دسته «عمومی» (پلن‌های بدون دسته) */
function pc_general($pdo)
{
    $all = pc_cats($pdo, false);
    foreach ($all as $c) if (!empty($c['is_general'])) return $c;
    return null;
}

/** دسته یک پلن (دسته نامعتبر/حذف‌شده = عمومی) */
function pc_plan_cat($pdo, $plan)
{
    $c = !empty($plan['cat_id']) ? pc_cat($pdo, (int)$plan['cat_id']) : null;
    return $c ?: pc_general($pdo);
}

/** مقادیر فیلدهای اختصاصی پلن */
function pc_values($plan)
{
    $v = json_decode((string)($plan['cat_values'] ?? ''), true);
    return is_array($v) ? $v : [];
}

/** آیا امکان فنی در این دسته مجاز است؟ (فهرست خالی = همه امکانات) */
function pc_feature_allowed($cat, $fk)
{
    if (!$cat || empty($cat['feature_list'])) return true;
    return in_array($fk, $cat['feature_list'], true);
}

/** ردیف‌های نمایشی فیلدهای اختصاصی پلن */
function pc_field_items($cat, $plan)
{
    if (!$cat) return [];
    $vals = pc_values($plan);
    $out = [];
    foreach ($cat['fields_list'] as $f) {
        $v = trim((string)($vals[$f['k']] ?? ''));
        if ($v === '') continue;
        $type = $f['type'] ?? 'text';
        if ($type === 'yesno') { if ($v !== '1') continue; $text = $f['label']; }
        elseif ($type === 'number') { $text = $f['label'] . ': ' . (is_numeric($v) ? number_format((float)$v) : $v) . (($f['unit'] ?? '') !== '' ? ' ' . $f['unit'] : ''); }
        else $text = $f['label'] . ': ' . $v . (($f['unit'] ?? '') !== '' ? ' ' . $f['unit'] : '');
        $out[] = ['text' => mb_substr($text, 0, 200), 'note' => '', 'key' => 'cf_' . $f['k']];
    }
    return $out;
}

/**
 * گروه‌بندی پلن‌ها برای نمایش: [ ['cat' => دسته, 'plans' => [...]], ... ] به ترتیب دسته‌ها؛ فقط گروه‌های دارای پلن
 * پلن‌هایی که دسته‌شان غیرفعال است نمایش داده نمی‌شوند.
 */
function pc_group($pdo, array $plans)
{
    $cats = pc_cats($pdo, false);
    $gen = pc_general($pdo);
    $by = [];
    foreach ($plans as $p) {
        $c = null;
        if (!empty($p['cat_id'])) foreach ($cats as $x) if ((int)$x['id'] === (int)$p['cat_id']) $c = $x;
        if (!$c) $c = $gen;
        if (!$c) $c = ['id' => 0, 'title' => 'پلن‌ها', 'icon' => '', 'slug' => 'all', 'description' => '', 'is_active' => 1, 'fields_list' => [], 'feature_list' => [], 'sort_order' => 999];
        if (empty($c['is_active'])) continue;
        $k = (int)$c['id'];
        if (!isset($by[$k])) $by[$k] = ['cat' => $c, 'plans' => []];
        $by[$k]['plans'][] = $p;
    }
    $out = array_values($by);
    usort($out, fn($a, $b) => [(int)($a['cat']['sort_order'] ?? 0), (int)$a['cat']['id']] <=> [(int)($b['cat']['sort_order'] ?? 0), (int)$b['cat']['id']]);
    foreach ($out as &$g) usort($g['plans'], fn($a, $b) => [(int)($a['sort_order'] ?? 0), (int)$a['price_toman']] <=> [(int)($b['sort_order'] ?? 0), (int)$b['price_toman']]);
    unset($g);
    return $out;
}

/**
 * نوار زبانه دسته‌ها + اسکریپت نمایش گروه‌ها
 * هر گروه در صفحه باید داخل <div data-pcg="slug"> باشد. زبانه با آدرس #plans-slug هم باز می‌شود.
 * @param string $theme dark|light
 * @param string $active نامک زبانه پیش‌فرض
 */
function pc_tabs_html(array $groups, $theme = 'dark', $active = '', $id = 'pct')
{
    if (count($groups) < 2) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $slugs = array_map(fn($g) => $g['cat']['slug'], $groups);
    if (!in_array($active, $slugs, true)) $active = $slugs[0];
    $dark = $theme === 'dark';
    ob_start();
?>
<div class="pct pct-<?php echo $dark ? 'd' : 'l'; ?>" id="<?php echo $h($id); ?>" role="tablist" aria-label="دسته‌بندی پلن‌ها">
  <?php foreach ($groups as $g): $c = $g['cat']; ?>
  <button type="button" role="tab" data-pct="<?php echo $h($c['slug']); ?>" aria-selected="<?php echo $c['slug'] === $active ? 'true' : 'false'; ?>"><?php echo $c['icon'] !== '' ? '<span aria-hidden="true">' . $h($c['icon']) . '</span> ' : ''; ?><?php echo $h($c['title']); ?></button>
  <?php endforeach; ?>
</div>
<style>
.pct{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:0 auto 22px;max-width:100%}
.pct button{font-family:inherit;font-size:14px;font-weight:700;padding:9px 16px;border-radius:999px;cursor:pointer;transition:all .2s;white-space:nowrap;line-height:1.5}
.pct-d button{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.14);color:#cbd5e1}
.pct-d button:hover{border-color:rgba(251,191,36,.55);color:#fff}
.pct-d button[aria-selected=true]{background:linear-gradient(135deg,#f59e0b,#fbbf24);border-color:transparent;color:#1a1205}
.pct-l button{background:#fff;border:1px solid #e2e8f0;color:#475569}
.pct-l button:hover{border-color:#a78bfa;color:#6d28d9}
.pct-l button[aria-selected=true]{background:#7c3aed;border-color:#7c3aed;color:#fff}
.pcg-desc{text-align:center;font-size:13.5px;margin:-8px auto 20px;max-width:640px;line-height:1.9}
.pcg-desc.d{color:#9ca3af}.pcg-desc.l{color:#64748b}
@media(max-width:560px){.pct{flex-wrap:nowrap;overflow-x:auto;justify-content:flex-start;padding-bottom:4px;scrollbar-width:none}.pct::-webkit-scrollbar{display:none}.pct button{font-size:13px;padding:8px 13px}}
</style>
<script>
(function(){
  var bar = document.getElementById(<?php echo json_encode($id); ?>); if (!bar) return;
  function show(s, push){
    var ok = false;
    Array.prototype.forEach.call(bar.querySelectorAll('[data-pct]'), function(b){ var on = b.getAttribute('data-pct') === s; if (on) ok = true; b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    if (!ok) return false;
    Array.prototype.forEach.call(document.querySelectorAll('[data-pcg]'), function(g){ g.hidden = g.getAttribute('data-pcg') !== s; });
    if (push && history.replaceState) try { history.replaceState(null, '', '#plans-' + s); } catch (e) {}
    return true;
  }
  Array.prototype.forEach.call(bar.querySelectorAll('[data-pct]'), function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-pct'), true); }); });
  function fromHash(){ var m = location.hash.match(/^#plans-([\w-]+)$/); if (m && show(m[1], false)) { var t = bar.closest('section') || bar; if (t.scrollIntoView) t.scrollIntoView({ block: 'start' }); return true; } return false; }
  function init(){ if (!fromHash()) show(<?php echo json_encode($active); ?>, false); }
  // گروه‌ها بعد از نوار زبانه در صفحه می‌آیند: بعد از بارگذاری کامل صفحه اعمال شود
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  window.addEventListener('hashchange', fromHash);
})();
</script>
<?php
    return ob_get_clean();
}

/** قالب چت‌بات پیش‌فرض برای کاربر (از دسته پلن او) */
function pc_user_bot_template($pdo, $plan)
{
    if (!$plan) return '';
    $c = pc_plan_cat($pdo, $plan);
    return $c ? (string)($c['bot_template'] ?? '') : '';
}

/** خاموش کردن امکاناتی که در دسته مجاز نیستند (برای پلن‌های همان دسته) */
function pc_enforce_features($pdo, $cat)
{
    if (!$cat || empty($cat['feature_list']) || !function_exists('saas_plan_features')) return;
    $ids = array_map('intval', $pdo->query("SELECT id FROM saas_plans WHERE cat_id=" . (int)$cat['id'])->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    if (!empty($cat['is_general'])) $ids = array_merge($ids, array_map('intval', $pdo->query("SELECT id FROM saas_plans WHERE cat_id=0 OR cat_id NOT IN (SELECT id FROM saas_plan_cats)")->fetchAll(\PDO::FETCH_COLUMN) ?: []));
    if (!$ids) return;
    $off = array_values(array_filter(array_keys(saas_plan_features()), fn($k) => !in_array($k, $cat['feature_list'], true)));
    foreach ($off as $k) {
        if (!preg_match('/^[a-z_]+$/', $k)) continue;
        try { $pdo->exec("UPDATE saas_plans SET `$k`=0 WHERE id IN (" . implode(',', array_unique($ids)) . ")"); } catch (\Throwable $e) {}
    }
}
