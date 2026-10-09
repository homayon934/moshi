<?php
/**
 * گالری طرح‌ها — نسخه ۵۱
 *  - کاتالوگ فشرده برای رابط‌ها (پنل مدیریت، استودیوی کاربر، چت‌بات مخاطبان)
 *  - جستجوی فوری (در مرورگر، رایگان) + جستجوی هوشمند با مدل متنی ارزان (کش‌شده)
 *  - تصویر کوچک (thumbnail) برای بارگذاری سریع گالری
 *  - انتخاب نمونه + پرامپت (مشترک بین استودیو و چت‌بات)
 *  - رابط مشترک «دسته‌های آبشاری + جستجو» (GalUI) — همین کد در فایل رابط چت‌بات هم کپی شده است
 */

if (!defined('GAL_AI_MAX_ITEMS')) define('GAL_AI_MAX_ITEMS', 300);   // تا این تعداد نمونه، فهرست نمونه‌ها هم به مدل داده می‌شود

/** یکسان‌سازی متن فارسی برای جستجو (ی/ک عربی، اعراب، نیم‌فاصله، ارقام) — معادل nrm در GalUI */
function gal_norm($s)
{
    $s = mb_strtolower((string)$s, 'UTF-8');
    $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    $s = preg_replace('/[\x{200C}\x{200D}\x{200E}\x{200F}\x{060C}\x{061B}\x{061F}\x{066A}-\x{066D}\x{06D4}]/u', ' ', $s);
    $s = preg_replace('/[^0-9a-z\x{0600}-\x{06FF}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** گالری در چت‌بات‌ها: کلید کلی مدیر + انتخاب صاحب هر چت‌بات */
function gal_bot_on($pdo, $bot)
{
    $s = biz_settings($pdo);
    if (($s['gal_bot_off'] ?? '') === '1') return false;
    return ($s['gal_off_' . (int)($bot['id'] ?? 0)] ?? '') !== '1';
}

/** آیا برای این نوع، نمونه فعال و پرامپت فعال وجود دارد؟ */
function gal_kind_ready($pdo, $kind)
{
    static $c = [];
    if (isset($c[$kind])) return $c[$kind];
    try {
        $a = $pdo->prepare("SELECT COUNT(*) FROM saas_gallery WHERE kind=? AND is_active=1");
        $a->execute([$kind]);
        $b = $pdo->prepare("SELECT COUNT(*) FROM saas_prompt_presets WHERE kind=? AND is_active=1");
        $b->execute([$kind]);
        $na = (int)$a->fetchColumn(); $nb = (int)$b->fetchColumn();
        if ($na > 0 && $nb === 0) {   // فقط نمونه‌های متصل به «اقدام آماده»
            biz_gallery_schema($pdo);
            $x = $pdo->prepare("SELECT COUNT(*) FROM saas_gallery WHERE kind=? AND is_active=1 AND action_id > 0");
            $x->execute([$kind]);
            $nb = (int)$x->fetchColumn();
        }
        return $c[$kind] = $na > 0 && $nb > 0;
    } catch (\Throwable $e) { return $c[$kind] = false; }
}

/** انواعی که گالری‌شان در این چت‌بات نمایش داده می‌شود */
function gal_bot_kinds($pdo, $bot, $img_ok, $vid_ok)
{
    if (!gal_bot_on($pdo, $bot)) return [];
    $k = [];
    if ($img_ok && gal_kind_ready($pdo, 'image')) $k[] = 'image';
    if ($vid_ok && gal_kind_ready($pdo, 'video')) $k[] = 'video';
    return $k;
}

/**
 * کاتالوگ فشرده برای رابط‌ها
 * $admin=true: نمونه‌های مخفی و متن مخفی (برای جستجوی مدیر) هم می‌آید و دسته‌های خالی حذف نمی‌شوند
 * @return array ['cats'=>[{id,p,t,n}], 'items'=>[{id,c,t,d,g,pr,pt,v,a?,p?}], 'presets'=>[{id,t,d,c}]]
 */
function gal_catalog($pdo, $kind, $admin = false, array $ctx = [])
{
    $kind = $kind === 'video' ? 'video' : 'image';
    biz_gallery_schema($pdo);
    $rows = biz_gallery($pdo, $kind, !$admin);
    // «اقدام‌های آماده» قابل استفاده در این محل (استودیو / چت‌بات) با مدل‌های مجاز؛ مدیر: همه
    $acts = [];
    if (function_exists('ga_list')) {
        if ($admin) { foreach (ga_list($pdo, $kind, 'all', false) as $g) $acts[(int)$g['id']] = ['id' => (int)$g['id'], 't' => trim($g['icon'] . ' ' . $g['title']), 'd' => (string)$g['description'], 'ask' => (string)$g['ask_label'], 'hint' => (string)$g['ask_hint'], 'ref' => (string)$g['ref']]; }
        elseif (!empty($ctx['where'])) { foreach (ga_public($pdo, $kind, $ctx['where'], (array)($ctx['models'] ?? [])) as $g) $acts[(int)$g['id']] = ['id' => (int)$g['id'], 't' => trim($g['icon'] . ' ' . $g['title']), 'd' => (string)$g['desc'], 'ask' => (string)$g['ask'], 'hint' => (string)$g['hint'], 'ref' => (string)$g['ref']]; }
    }
    $tree = biz_gallery_tree($pdo, $kind);
    $presets = biz_presets($pdo, $kind, !$admin);
    $pname = [];
    foreach ($presets as $p) $pname[(int)$p['id']] = (string)$p['title'];
    $cnt = [];
    foreach ($rows as $r) { $c = (int)$r['cat_id']; if (!isset($tree[$c])) $c = 0; $cnt[$c] = ($cnt[$c] ?? 0) + 1; }
    $cats = [];
    foreach ($tree as $c) {
        $n = 0;
        foreach ($c['desc_ids'] as $d) $n += $cnt[$d] ?? 0;
        if (!$admin && $n === 0) continue;
        $cats[] = ['id' => (int)$c['id'], 'p' => (int)$c['parent_id'], 't' => (string)$c['title'], 'n' => $n];
    }
    $items = [];
    foreach ($rows as $r) {
        $c = (int)$r['cat_id']; if (!isset($tree[$c])) $c = 0;
        $pr = (int)($r['preset_id'] ?? 0); $ac = (int)($r['action_id'] ?? 0);
        if (!isset($acts[$ac])) $ac = 0;
        $it = ['id' => (int)$r['id'], 'c' => $c, 't' => (string)$r['title'], 'd' => (string)$r['description'], 'g' => (string)($r['tags'] ?? ''),
               'pr' => isset($pname[$pr]) ? $pr : 0, 'ac' => $ac, 'pt' => $ac ? $acts[$ac]['t'] : ($pname[$pr] ?? ''), 'v' => $kind === 'video' ? 1 : 0,
               'h' => substr(md5((string)$r['file_url'] . '|' . (string)$r['poster_url']), 0, 6)];
        if ($admin) { $it['a'] = (int)$r['is_active']; $it['p'] = mb_substr((string)$r['prompt'], 0, 400); }
        $items[] = $it;
    }
    $pl = [];
    foreach ($presets as $p) $pl[] = ['id' => (int)$p['id'], 't' => (string)$p['title'], 'd' => (string)$p['description'],
        'c' => array_values(array_filter(array_map('intval', explode(',', (string)$p['cat_ids']))))];
    $used = array_flip(array_column($items, 'ac'));
    $al = array_values(array_filter($acts, fn($a) => isset($used[$a['id']])));
    return ['cats' => $cats, 'items' => $items, 'presets' => $pl, 'actions' => $al];
}

/** مقدار انتخاب «اتصال» در فرم‌های مدیر: a12 = اقدام آماده، p5 (یا عدد) = پرامپت گالری → [preset_id, action_id] */
function gal_link_parse($v)
{
    $v = trim((string)$v);
    if (preg_match('/^a(\d+)$/', $v, $m)) return [0, (int)$m[1]];
    if (preg_match('/^p?(\d+)$/', $v, $m)) return [(int)$m[1], 0];
    return [0, 0];
}

/**
 * نمونه + «اقدام آماده» متصل به همان نمونه (استودیو: where=studio، چت‌بات: where=bot)
 * @return array [sample|null, action|null, error]
 */
function gal_pick_action($pdo, $kind, $sample_id, $action_id, $where)
{
    $s = biz_gallery_item($pdo, (int)$sample_id);
    if (!$s || $s['kind'] !== $kind || empty($s['is_active'])) return [null, null, 'این نمونه دیگر در گالری نیست؛ نمونه دیگری انتخاب کنید.'];
    $g = function_exists('ga_get') ? ga_get($pdo, (int)$action_id) : null;
    if (!$g || (int)($s['action_id'] ?? 0) !== (int)$g['id'] || empty($g['is_active']) || $g['kind'] !== $kind || empty($g[$where === 'bot' ? 'in_bot' : 'in_studio']))
        return [$s, null, 'این نوع اجرا برای این نمونه در دسترس نیست؛ نوع دیگری انتخاب کنید.'];
    return [$s, $g, ''];
}

/**
 * انتخاب نمونه و پرامپت (نوع اجرا) — مشترک بین استودیو و چت‌بات
 * پرامپت باید برای دسته نمونه (یا دسته‌های بالاتر) مجاز باشد، یا پرامپت متصل همان نمونه باشد
 * @return array [sample|null, preset|null, error]
 */
function gal_pick($pdo, $kind, $sample_id, $preset_id)
{
    $s = biz_gallery_item($pdo, (int)$sample_id);
    if (!$s || $s['kind'] !== $kind || empty($s['is_active'])) return [null, null, 'این نمونه دیگر در گالری نیست؛ نمونه دیگری انتخاب کنید.'];
    $p = null;
    foreach (biz_presets($pdo, $kind, true, (int)$s['cat_id']) as $pp) if ((int)$pp['id'] === (int)$preset_id) $p = $pp;
    if (!$p && (int)($s['preset_id'] ?? 0) > 0 && (int)$s['preset_id'] === (int)$preset_id)
        foreach (biz_presets($pdo, $kind, true) as $pp) if ((int)$pp['id'] === (int)$s['preset_id']) $p = $pp;
    if (!$p) return [$s, null, 'نوع اجرا را انتخاب کنید.'];
    return [$s, $p, ''];
}

// ---------------------------------------------------------------------------
// تصویر کوچک
// ---------------------------------------------------------------------------
/** آدرس تصویر کوچک روی سایت اصلی (پنل مدیریت و استودیو) */
function gal_thumb_url($item)
{
    return rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/gthumb.php?id=' . (int)$item['id'] . '&v=' . substr(md5((string)($item['file_url'] ?? '') . '|' . (string)($item['poster_url'] ?? '')), 0, 6);
}

/** مسیر فایل منبع نمونه روی دیسک (تصویر نمونه یا پوستر ویدیو) */
function gal_src_path($item)
{
    $url = ($item['kind'] ?? '') === 'video' ? (string)($item['poster_url'] ?? '') : (string)($item['file_url'] ?? '');
    $prefix = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/gallery/';
    if ($url === '' || strpos($url, $prefix) !== 0) return '';
    $rel = substr($url, strlen($prefix));
    if (strpos($rel, '..') !== false) return '';
    $p = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/gallery/' . $rel;
    return is_file($p) ? $p : '';
}

/** تصویر کوچک (حداکثر ۴۰۰ پیکسل، JPEG) — ساخته و کش می‌شود؛ بدون GD همان فایل اصلی */
function gal_thumb_path($item)
{
    $src = gal_src_path($item);
    if ($src === '') return '';
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/gallery/thumbs';
    $f = $dir . '/' . (int)$item['id'] . '_' . substr(md5($src . '|' . @filemtime($src)), 0, 10) . '.jpg';
    if (is_file($f)) return $f;
    if (!function_exists('imagecreatefromstring')) return $src;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $bytes = (string)@file_get_contents($src);
    $im = $bytes !== '' ? @imagecreatefromstring($bytes) : false;
    if (!$im) return $src;
    $w = imagesx($im); $h = imagesy($im);
    $k = min(1, 400 / max(1, max($w, $h)));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // پس‌زمینه سفید برای PNG شفاف
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = @imagejpeg($dst, $f, 82);
    imagedestroy($im); imagedestroy($dst);
    // پاک‌سازی نسخه‌های قدیمی همین نمونه
    foreach (glob($dir . '/' . (int)$item['id'] . '_*.jpg') ?: [] as $old) if ($old !== $f) @unlink($old);
    return $ok && is_file($f) ? $f : $src;
}

function gal_mime($path)
{
    $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$e] ?? '';
}

/** برای فایل رابط چت‌بات: تصویر کوچک (یا بزرگ تا ۴ مگابایت) نمونه فعال، به‌صورت base64 */
function gal_file_public($pdo, array $in)
{
    $it = biz_gallery_item($pdo, (int)($in['value'] ?? 0));
    if (!$it || empty($it['is_active'])) return ['ok' => false, 'error' => 'not found'];
    $p = '';
    if (($in['size'] ?? '') === 'full') { $p = gal_src_path($it); if ($p !== '' && filesize($p) > 4 * 1024 * 1024) $p = ''; }
    if ($p === '') $p = gal_thumb_path($it);
    $mime = $p !== '' ? gal_mime($p) : '';
    if ($mime === '') return ['ok' => false, 'error' => 'not found'];
    return ['ok' => true, 'mime' => $mime, 'data' => base64_encode((string)file_get_contents($p))];
}

// ---------------------------------------------------------------------------
// جستجوی هوشمند
// ---------------------------------------------------------------------------
/**
 * جستجوی هوشمند با مدل متنی ارزان (همان مدل مترجم)
 * $who: ['type'=>'user','uid'=>..] | ['type'=>'bot','bot'=>..,'member'=>..] | ['type'=>'admin']
 * نتیجه برای هر عبارت و هر نسخه گالری ۳۰ روز کش می‌شود (تکرار = رایگان)
 * @return array ['ok','ids'=>[],'cats'=>[],'kw'=>[],'toman','cached'] یا ['ok'=>false,'error']
 */
function gal_ai_search($pdo, $kind, $q, array $who)
{
    $kind = $kind === 'video' ? 'video' : 'image';
    $q = trim(mb_substr(preg_replace('/\s+/u', ' ', (string)$q), 0, 200));
    if (mb_strlen($q) < 2) return ['ok' => false, 'error' => 'چه طرحی می‌خواهید؟ چند کلمه بنویسید.'];
    $admin = ($who['type'] ?? '') === 'admin';
    $cat = gal_catalog($pdo, $kind, $admin);
    if (!$cat['items']) return ['ok' => false, 'error' => 'گالری خالی است.'];
    $paths = []; $byid = [];
    foreach ($cat['cats'] as $c) { $byid[$c['id']] = $c; }
    foreach ($cat['cats'] as $c) {
        $t = $c['t']; $x = $c['p']; $guard = 0;
        while ($x && isset($byid[$x]) && $guard++ < 5) { $t = $byid[$x]['t'] . ' › ' . $t; $x = $byid[$x]['p']; }
        $paths[$c['id']] = $t;
    }
    $lines = ['Categories:'];
    foreach ($paths as $id => $t) $lines[] = 'c' . $id . ': ' . $t;
    $with_items = count($cat['items']) <= GAL_AI_MAX_ITEMS;
    if ($with_items) {
        $lines[] = 'Items:';
        foreach ($cat['items'] as $it) $lines[] = 's' . $it['id'] . ': ' . mb_substr($it['t'], 0, 70)
            . ($it['d'] !== '' ? ' | ' . mb_substr($it['d'], 0, 80) : '') . ($it['g'] !== '' ? ' | ' . mb_substr($it['g'], 0, 80) : '') . ($it['c'] ? ' [c' . $it['c'] . ']' : '');
    }
    $catalog = implode("\n", $lines);
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/.gal_ai';
    $cf = $dir . '/' . sha1($kind . '|' . gal_norm($q) . '|' . md5($catalog)) . '.json';
    if (is_file($cf) && filemtime($cf) > time() - 30 * 86400) {
        $d = json_decode((string)@file_get_contents($cf), true);
        if (is_array($d)) return ['ok' => true, 'ids' => $d['ids'] ?? [], 'cats' => $d['cats'] ?? [], 'kw' => $d['kw'] ?? [], 'toman' => 0, 'cached' => true];
    }
    $key = $admin ? 'a' : (($who['type'] ?? '') === 'user' ? 'u' . (int)($who['uid'] ?? 0) : 'm' . (int)($who['member']['id'] ?? 0));
    if (function_exists('sec_rate') && sec_rate('galai|' . $key, $admin ? 200 : 40, 3600)) return ['ok' => false, 'error' => 'تعداد جستجوی هوشمند در این ساعت زیاد است؛ کمی بعد دوباره تلاش کنید یا از جستجوی معمولی استفاده کنید.'];
    if (($who['type'] ?? '') === 'user') {
        $u = saas_get_user($pdo, (int)$who['uid']);
        if (!$u || (int)$u['credit_tokens'] < 1) return ['ok' => false, 'error' => 'اعتبار کافی نیست؛ از جستجوی معمولی استفاده کنید یا حساب را شارژ کنید.'];
    }
    $sys = "You help users find designs in a " . ($kind === 'video' ? 'video' : 'design/image') . " sample gallery. The user's query is usually Persian and may be vague "
         . "(e.g. a business type, an occasion, a style or a color). Understand the meaning (synonyms, related businesses, English/Persian). "
         . "Reply ONLY with JSON: {\"ids\":[best matching item numbers, best first, max 30],\"cats\":[relevant category numbers, max 5],\"kw\":[up to 8 short search keywords, Persian and English]}. "
         . "Use ONLY numbers that exist in the lists (without the s/c prefix). If nothing fits, return empty arrays.";
    $cfg = saas_get_api_config($pdo);
    $model = function_exists('gen_tr_model') ? gen_tr_model($pdo) : null;
    if (!function_exists('aichat_call_ai') && is_file(__DIR__ . '/ai_chat_lib.php')) require_once __DIR__ . '/ai_chat_lib.php';
    $r = biz_ai_call($pdo, $cfg, $model, [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => 'Query: ' . $q . "\n\n" . $catalog]], 400, 0.2);
    if (empty($r['ok'])) return ['ok' => false, 'error' => 'جستجوی هوشمند الان در دسترس نیست؛ از جستجوی معمولی استفاده کنید.'];
    $toman = (int)biz_charge_tokens($pdo, $r['model_row'] ?? $model, $r);
    if ($toman > 0) {
        if (($who['type'] ?? '') === 'user') saas_deduct_credit($pdo, (int)$who['uid'], $toman, 'جستجوی هوشمند گالری');
        elseif (($who['type'] ?? '') === 'bot' && !empty($who['bot'])) {
            saas_deduct_credit($pdo, (int)$who['bot']['user_id'], $toman, 'چت‌بات تخصصی (جستجوی هوشمند گالری): ' . $who['bot']['name']);
            try { $pdo->prepare("UPDATE hd_bots SET tokens_used = tokens_used + ? WHERE id=?")->execute([$toman, (int)$who['bot']['id']]); } catch (\Throwable $e) {}
        }
    }
    $c = trim((string)($r['content'] ?? ''));
    $c = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $c);
    if (preg_match('/\{.*\}/s', $c, $mm)) $c = $mm[0];
    $j = json_decode($c, true);
    if (!is_array($j)) $j = [];
    $have = array_flip(array_column($cat['items'], 'id'));
    $num = fn($v) => (int)preg_replace('/\D/', '', (string)$v);
    $ids = []; foreach ((array)($j['ids'] ?? []) as $v) { $v = $num($v); if (isset($have[$v]) && !in_array($v, $ids, true)) $ids[] = $v; if (count($ids) >= 30) break; }
    $cats = []; foreach ((array)($j['cats'] ?? []) as $v) { $v = $num($v); if (isset($paths[$v]) && !in_array($v, $cats, true)) $cats[] = $v; if (count($cats) >= 5) break; }
    $kw = []; foreach ((array)($j['kw'] ?? []) as $v) { if (!is_scalar($v)) continue; $v = trim(mb_substr((string)$v, 0, 40)); if ($v !== '') $kw[] = $v; if (count($kw) >= 8) break; }
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents($cf, json_encode(['q' => $q, 'ids' => $ids, 'cats' => $cats, 'kw' => $kw], JSON_UNESCAPED_UNICODE));
    if (mt_rand(1, 50) === 1) foreach (glob($dir . '/*.json') ?: [] as $old) if (filemtime($old) < time() - 31 * 86400) @unlink($old);
    return ['ok' => true, 'ids' => $ids, 'cats' => $cats, 'kw' => $kw, 'toman' => $toman, 'cached' => false];
}

// ---------------------------------------------------------------------------
// رابط مشترک: دسته‌های آبشاری + جستجوی فوری + جستجوی هوشمند
// (همین CSS/JS در hamdam/connector/index.php کپی شده — در صورت تغییر، هر دو را یکسان نگه دارید)
// ---------------------------------------------------------------------------
function gal_ui_css()
{
    return <<<'CSS'
.gu{--gu-a:#2563eb;font-size:13px}
.gu-s{display:flex;gap:6px;margin-bottom:8px}
.gu-q{flex:1;min-width:0;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 11px;font:inherit;font-size:13.5px;background:#fff;color:inherit;height:auto}
.gu-q:focus{outline:none;border-color:var(--gu-a)}
.gu-ai{border:1.5px solid #ddd6fe;background:#f5f3ff;color:#6d28d9;border-radius:10px;padding:0 11px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.gu-ai:disabled{opacity:.6;cursor:wait}
.gu-r{font-size:12.5px;color:#475569;margin:0 0 8px;line-height:2}
.gu-r[hidden]{display:none}
.gu-cc{display:inline-block;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:14px;padding:0 9px;margin:2px 0 2px 4px;cursor:pointer;font-size:12px;line-height:1.9}
.gu-al{color:#6d28d9;cursor:pointer;font-weight:700;text-decoration:underline;white-space:nowrap}
.gu-h{display:flex;align-items:center;gap:6px;width:100%;border:1.5px solid #e2e8f0;background:#fff;border-radius:10px;padding:8px 11px;font:inherit;font-size:13px;cursor:pointer;color:#334155;text-align:right;margin-bottom:6px}
.gu-h b{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gu-h i{font-style:normal;color:#94a3b8;transition:transform .2s}
.gu.gu-open .gu-h i{transform:rotate(180deg)}
.gu-t{display:none;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:4px;max-height:340px;overflow-y:auto;margin-bottom:8px}
.gu.gu-open .gu-t{display:block}
.gu-n{display:flex;align-items:center;gap:4px;padding:6px 6px;border-radius:8px;cursor:pointer;color:#334155;user-select:none;line-height:1.6}
.gu-n:hover{background:#f1f5f9}
.gu-n.on{background:var(--gu-a);color:#fff}
.gu-n.on .gu-c,.gu-n.on .gu-x{color:rgba(255,255,255,.9)}
.gu-x{width:18px;flex:0 0 18px;text-align:center;color:#94a3b8;font-size:11px;border-radius:5px}
.gu-x:hover{background:rgba(148,163,184,.2)}
.gu-l{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gu-c{font-size:11px;color:#94a3b8}
.gu-k{border-right:1.5px solid #e2e8f0;margin-right:14px;padding-right:2px}
.gu-k[hidden]{display:none}
@media (min-width:900px){.gu.gu-side .gu-h{display:none}.gu.gu-side .gu-t{display:block;max-height:none}}
CSS;
}

function gal_ui_js()
{
    return <<<'JS'
(function(W){
if (W.GalUI) return;
var FD = '۰۱۲۳۴۵۶۷۸۹', AD = '٠١٢٣٤٥٦٧٨٩';
function nrm(s){
  s = String(s == null ? '' : s).toLowerCase();
  s = s.replace(/[يىئ]/g, 'ی').replace(/ك/g, 'ک').replace(/ة/g, 'ه').replace(/[أإآ]/g, 'ا').replace(/ؤ/g, 'و');
  s = s.replace(/[ً-ٰٟـ]/g, '');
  s = s.replace(/[۰-۹]/g, function(d){ return FD.indexOf(d); }).replace(/[٠-٩]/g, function(d){ return AD.indexOf(d); });
  s = s.replace(/[‌‍‎‏،؛؟٪-٭۔]/g, ' ');
  s = s.replace(/[^0-9a-z؀-ۿ]+/g, ' ');
  return s.replace(/\s+/g, ' ').trim();
}
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function fa(n){ return String(n).replace(/\d/g, function(d){ return FD[d]; }); }
function vars(t){
  var v = [t], suf = ['های', 'ها', 'ای', 'ی', 'ات'];
  for (var i = 0; i < suf.length; i++) if (t.length - suf[i].length >= 3 && t.slice(-suf[i].length) === suf[i]) { v.push(t.slice(0, -suf[i].length)); break; }
  return v;
}
function near1(a, b){
  if (a === b) return true;
  var la = a.length, lb = b.length; if (Math.abs(la - lb) > 1) return false;
  var i = 0, j = 0, e = 0;
  while (i < la && j < lb) {
    if (a[i] === b[j]) { i++; j++; continue; }
    if (++e > 1) return false;
    if (la > lb) i++; else if (lb > la) j++; else { i++; j++; }
  }
  return e + (la - i) + (lb - j) <= 1;
}
function mount(root, cfg){
  cfg = cfg || {};
  var cats = cfg.cats || [], items = cfg.items || [], by = {}, kids = { 0: [] }, sel = 0, mode = 'cat', lastQ = '', timer = 0;
  cats.forEach(function(c){ by[c.id] = { id: c.id, p: c.p, t: c.t, n: c.n }; });
  cats.forEach(function(c){ var p = by[c.p] ? c.p : 0; by[c.id].p = p; (kids[p] = kids[p] || []).push(c.id); });
  function path(id){ var a = [], x = id, g = 0; while (x && by[x] && g++ < 6) { a.unshift(by[x].t); x = by[x].p; } return a.join(' › '); }
  function desc(id){ var o = [id], q = [id]; while (q.length) { var x = q.shift(); (kids[x] || []).forEach(function(k){ o.push(k); q.push(k); }); } return o; }
  function anc(id){ var a = [], x = by[id] ? by[id].p : 0, g = 0; while (x && g++ < 6) { a.push(x); x = by[x] ? by[x].p : 0; } return a; }
  cats.forEach(function(c){ by[c.id].path = path(c.id); by[c.id].np = nrm(by[c.id].path); if (by[c.id].n == null) by[c.id].n = 0; });
  var cnt = {};
  items.forEach(function(it){
    if (!by[it.c]) it.c = 0;
    it._t = nrm(it.t); it._c = it.c ? by[it.c].np : ''; it._x = nrm([it.d, it.g, it.pt, it.p].join(' '));
    it._a = (it._t + ' ' + it._c + ' ' + it._x).trim(); it._m = it._a.replace(/ /g, '');
    it._w = it._a.split(' ').filter(function(w){ return w.length >= 3; });
    cnt[it.c] = (cnt[it.c] || 0) + 1;
  });
  if (cfg.count !== false) cats.forEach(function(c){ var n = 0; desc(c.id).forEach(function(d){ n += cnt[d] || 0; }); by[c.id].n = n; });
  var aiOn = typeof cfg.ai === 'function';
  root.innerHTML = '<div class="gu' + (cfg.side ? ' gu-side' : '') + '">'
    + '<div class="gu-s"><input type="search" class="gu-q" placeholder="' + esc(cfg.ph || '🔍 جستجو در طرح‌ها و دسته‌ها… (مثلاً: لوگو کافه)') + '" autocomplete="off">'
    + (aiOn ? '<button type="button" class="gu-ai" title="' + esc(cfg.aiTip || 'معنای درخواست شما را با هوش مصنوعی پیدا می‌کند') + '">🤖 هوشمند</button>' : '') + '</div>'
    + '<div class="gu-r" hidden></div>'
    + (cats.length ? '<button type="button" class="gu-h"><span>📂</span><b></b><i>▾</i></button><div class="gu-t"></div>' : '')
    + '</div>';
  var G = root.querySelector('.gu'), Q = root.querySelector('.gu-q'), R = root.querySelector('.gu-r'), H = root.querySelector('.gu-h'), T = root.querySelector('.gu-t'), AI = root.querySelector('.gu-ai');
  function nodeHtml(id){
    var c = by[id], ks = kids[id] || [];
    return '<div class="gu-n" data-id="' + id + '"><span class="gu-x">' + (ks.length ? '◂' : '') + '</span><span class="gu-l">' + esc(c.t) + '</span><span class="gu-c">' + fa(c.n) + '</span></div>'
      + (ks.length ? '<div class="gu-k" data-k="' + id + '" hidden>' + ks.map(nodeHtml).join('') + '</div>' : '');
  }
  if (T) T.innerHTML = '<div class="gu-n on" data-id="0"><span class="gu-x"></span><span class="gu-l">' + esc(cfg.all || 'همه طرح‌ها') + '</span><span class="gu-c">' + fa(items.length) + '</span></div>' + (kids[0] || []).map(nodeHtml).join('');
  function box(id){ return T ? T.querySelector('.gu-k[data-k="' + id + '"]') : null; }
  function setOpen(id, on){
    var b = box(id); if (!b) return; b.hidden = !on;
    var n = T.querySelector('.gu-n[data-id="' + id + '"] .gu-x'); if (n) n.textContent = on ? '▾' : '◂';
  }
  function openPath(id){
    // آبشاری: فقط شاخه انتخاب‌شده باز می‌ماند (هم‌سطح‌ها بسته می‌شوند)
    var keep = [id].concat(anc(id));
    Object.keys(kids).forEach(function(k){ k = +k; if (k && keep.indexOf(k) < 0) setOpen(k, false); });
    keep.forEach(function(k){ if (k) setOpen(k, true); });
  }
  function head(){
    if (!H) return;
    H.querySelector('b').textContent = sel ? by[sel].path : (cfg.allHead || 'همه دسته‌ها');
  }
  function mark(){ if (T) Array.prototype.forEach.call(T.querySelectorAll('.gu-n'), function(n){ n.classList.toggle('on', +n.getAttribute('data-id') === sel && mode === 'cat'); }); }
  function emit(ids, meta){ if (cfg.onFilter) cfg.onFilter(ids, meta); }
  function catIds(id){
    if (!id) return null;
    var d = desc(id), o = [];
    items.forEach(function(it){ if (d.indexOf(it.c) >= 0) o.push(it.id); });
    return o;
  }
  function select(id, fromUser){
    sel = by[id] ? id : 0; mode = 'cat'; lastQ = ''; Q.value = ''; R.hidden = true; R.innerHTML = '';
    if (sel) openPath(sel); mark(); head();
    if (fromUser && G.classList.contains('gu-open') && !(kids[sel] || []).length && W.innerWidth < 900) G.classList.remove('gu-open');
    emit(catIds(sel), { mode: 'cat', cat: sel, path: sel ? by[sel].path : '' });
  }
  function toks(q){ return nrm(q).split(' ').filter(function(t){ return t.length >= 2 || /\d/.test(t); }); }
  function scoreTok(it, t){
    var vs = vars(t), best = 0;
    for (var i = 0; i < vs.length; i++) {
      var v = vs[i], w = i ? 0.8 : 1, s = 0;
      if (it._t.indexOf(v) >= 0) s = (' ' + it._t).indexOf(' ' + v) >= 0 ? 4 : 3;
      else if (it._c.indexOf(v) >= 0) s = 2;
      else if (it._x.indexOf(v) >= 0) s = 1;
      else if (v.length >= 3 && it._m.indexOf(v) >= 0) s = 0.8;
      if (s * w > best) best = s * w;
    }
    if (!best && t.length >= 4) for (var j = 0; j < it._w.length; j++) if (near1(it._w[j], t)) { best = 0.6; break; }
    return best;
  }
  function find(q){
    var ts = toks(q), qc = nrm(q).replace(/ /g, '');
    if (!ts.length) return { ids: null, cats: [], partial: false };
    var res = items.map(function(it, ix){
      var s = 0, hit = 0;
      ts.forEach(function(t){ var x = scoreTok(it, t); if (x > 0) { hit++; s += x; } });
      if (qc.length >= 4 && it._m.indexOf(qc) >= 0) { s += 2; hit = Math.max(hit, ts.length); }
      return { id: it.id, s: s, h: hit, ix: ix };
    });
    var all = res.filter(function(r){ return r.h >= ts.length; }), partial = false;
    if (!all.length) { all = res.filter(function(r){ return r.h > 0; }); partial = all.length > 0; }
    all.sort(function(a, b){ return b.h - a.h || b.s - a.s || a.ix - b.ix; });
    var cm = cats.filter(function(c){ var p = by[c.id].np; return ts.every(function(t){ return vars(t).some(function(v){ return p.indexOf(v) >= 0; }); }); }).map(function(c){ return c.id; }).slice(0, 6);
    return { ids: all.map(function(r){ return r.id; }), cats: cm, partial: partial };
  }
  function chips(ids){ return ids.map(function(id){ return by[id] ? '<span class="gu-cc" data-c="' + id + '">📂 ' + esc(by[id].path) + ' (' + fa(by[id].n) + ')</span>' : ''; }).join(''); }
  function bindR(){
    Array.prototype.forEach.call(R.querySelectorAll('.gu-cc'), function(x){ x.onclick = function(){ select(+x.getAttribute('data-c'), true); }; });
    var al = R.querySelector('.gu-al'); if (al) al.onclick = runAI;
  }
  function search(q){
    q = String(q || '').trim(); lastQ = q;
    if (!q) { select(sel, false); return; }
    mode = 'search'; mark(); if (H) H.querySelector('b').textContent = '🔍 جستجو در همه دسته‌ها';
    var f = find(q), n = f.ids.length;
    R.hidden = false;
    R.innerHTML = (n ? '«' + esc(q) + '»: ' + fa(n) + ' ' + esc(cfg.noun || 'طرح') + (f.partial ? ' (نتایج نزدیک)' : '') : (cfg.none || 'طرحی با «' + esc(q) + '» پیدا نشد.'))
      + (f.cats.length ? '<div>' + chips(f.cats) + '</div>' : '')
      + (aiOn ? ' <span class="gu-al">' + (n ? 'نتیجه دلخواه نیست؟ ' : '') + '🤖 جستجوی هوشمند</span>' : '');
    bindR();
    emit(f.ids, { mode: 'search', q: q, partial: f.partial });
  }
  function runAI(){
    var q = Q.value.trim();
    if (q.length < 2) { Q.focus(); Q.placeholder = 'اول بنویسید چه طرحی می‌خواهید…'; return; }
    if (AI) AI.disabled = true;
    R.hidden = false; R.innerHTML = '🤖 در حال جستجوی هوشمند…';
    cfg.ai(q).then(function(r){
      if (AI) AI.disabled = false;
      if (Q.value.trim() !== q) return;
      if (!r || !r.ok) { R.innerHTML = esc((r && r.error) || 'جستجوی هوشمند انجام نشد.'); return; }
      var seen = {}, order = [];
      function add(id){ if (!seen[id]) { seen[id] = 1; order.push(id); } }
      var have = {}; items.forEach(function(it){ have[it.id] = it; });
      (r.ids || []).forEach(function(id){ if (have[id]) add(id); });
      (r.cats || []).forEach(function(c){ if (by[c]) { var d = desc(c); items.forEach(function(it){ if (d.indexOf(it.c) >= 0) add(it.id); }); } });
      (r.kw || []).forEach(function(k){ var f = find(k); (f.ids || []).slice(0, 40).forEach(add); });
      mode = 'ai'; mark(); if (H) H.querySelector('b').textContent = '🔍 جستجو در همه دسته‌ها';
      R.innerHTML = (order.length ? '🤖 نتایج هوشمند برای «' + esc(q) + '»: ' + fa(order.length) + ' ' + esc(cfg.noun || 'طرح') : '🤖 طرح مناسبی برای «' + esc(q) + '» پیدا نشد.')
        + ((r.cats || []).length ? '<div>' + chips(r.cats.filter(function(c){ return by[c]; })) + '</div>' : '');
      bindR();
      emit(order, { mode: 'ai', q: q });
    }, function(){ if (AI) AI.disabled = false; R.innerHTML = 'جستجوی هوشمند انجام نشد.'; });
  }
  Q.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ search(Q.value); }, 160); });
  Q.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(Q.value); } else if (e.key === 'Escape') { Q.value = ''; search(''); } });
  if (AI) AI.onclick = runAI;
  if (H) H.onclick = function(){ G.classList.toggle('gu-open'); };
  if (T) T.addEventListener('click', function(e){
    var n = e.target.closest ? e.target.closest('.gu-n') : null; if (!n) return;
    var id = +n.getAttribute('data-id');
    if (e.target.classList.contains('gu-x') && id && (kids[id] || []).length) { var b = box(id); setOpen(id, b.hidden); return; }
    if (id && id === sel && mode === 'cat' && (kids[id] || []).length) { var b2 = box(id); setOpen(id, b2.hidden); return; }
    select(id, true);
  });
  if (cfg.sel && by[cfg.sel]) select(cfg.sel, false); else head();
  if (cfg.open) G.classList.add('gu-open');
  return { select: function(id){ select(id, false); }, search: function(q){ Q.value = q; search(q); }, path: function(id){ return by[id] ? by[id].path : ''; },
           anc: anc, cat: function(){ return sel; }, find: find, norm: nrm };
}
W.GalUI = { mount: mount, norm: nrm };
})(window);
JS;
}
