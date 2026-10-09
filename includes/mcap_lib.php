<?php
/**
 * کارایی مدل‌ها، تاریخ حذف مدل و تعداد درخواست روزانه (نسخه ۷۲) — صفحه «مدل‌ها و قیمت‌ها»
 *  - کارایی: چند مورد بسیار کوتاه فارسی که دقیقاً می‌گوید مدل برای چه کارهایی است (از روی توضیح رسمی مدل با مدل کارهای داخلی ساخته می‌شود؛
 *    مدیر می‌تواند در فرم ویرایش دستی بنویسد) + برچسب‌های دقیق از مشخصات رسمی (ورودی/خروجی، طول متن، تصویر مرجع، لایه، وکتور و …)
 *  - تاریخ حذف: expiration_date فهرست OpenRouter (برای مدل AvalAI از همان مدل در OpenRouter)
 *  - درخواست روزانه: شمارش درخواست‌های موفق هر مدل در همین سامانه (saas_model_daily)
 */

function mcap_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.mcap_schema_v1' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        foreach ([['cap_text', "VARCHAR(400) NOT NULL DEFAULT ''"], ['cap_src', "VARCHAR(8) NOT NULL DEFAULT 'auto'"], ['cap_meta', "VARCHAR(1500) NOT NULL DEFAULT ''"],
                  ['expire_date', "VARCHAR(10) NOT NULL DEFAULT ''"], ['cap_at', 'DATETIME NULL']] as $c)
            saas_add_column_if_missing($pdo, 'saas_ai_models', $c[0], $c[1]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_model_daily (
            model_id INT NOT NULL,
            day CHAR(10) NOT NULL,
            n INT NOT NULL DEFAULT 0,
            PRIMARY KEY (model_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[MCAP] schema: ' . $e->getMessage()); }
}

/** نسخه ۷۳: توضیح کامل مدل (فارسی + متن اصلی سازنده) */
function mcap_schema2($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    mcap_schema($pdo);
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.mcap_schema_v2' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        saas_add_column_if_missing($pdo, 'saas_ai_models', 'cap_desc', 'TEXT NULL');
        saas_add_column_if_missing($pdo, 'saas_ai_models', 'cap_desc_en', 'TEXT NULL');
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[MCAP] schema2: ' . $e->getMessage()); }
}

/** نسخه کارایی خودکار؛ با افزایش آن کارایی‌های خودکار قبلی یک بار از نو ساخته می‌شوند */
const MCAP_VER = 3;   // ۳: کارایی فقط از واژه‌نامه ثابت (برای فیلتر یکسان)

/** یک درخواست موفق برای این مدل (شمارش روزانه) */
function mcap_count($pdo, $model_id)
{
    $id = (int)$model_id;
    if ($id <= 0 || !$pdo) return;
    mcap_schema2($pdo);
    try {
        $day = date('Y-m-d');
        $u = $pdo->prepare("UPDATE saas_model_daily SET n = n + 1 WHERE model_id=? AND day=?");
        $u->execute([$id, $day]);
        if ($u->rowCount() < 1) {
            try { $pdo->prepare("INSERT INTO saas_model_daily (model_id, day, n) VALUES(?,?,1)")->execute([$id, $day]); }
            catch (\Throwable $e) { $pdo->prepare("UPDATE saas_model_daily SET n = n + 1 WHERE model_id=? AND day=?")->execute([$id, $day]); }   // هم‌زمانی
        }
    } catch (\Throwable $e) { error_log('[MCAP] count: ' . $e->getMessage()); }
}

/** آمار درخواست همه مدل‌ها: [id => ['today' => n, 'avg' => میانگین روزانه ۷ روز اخیر]] */
function mcap_daily_stats($pdo)
{
    mcap_schema($pdo);
    $o = [];
    try {
        $s = $pdo->prepare("SELECT model_id, day, n FROM saas_model_daily WHERE day >= ?");
        $s->execute([date('Y-m-d', time() - 6 * 86400)]);
        $today = date('Y-m-d');
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int)$r['model_id'];
            $o[$id] = $o[$id] ?? ['today' => 0, 'sum' => 0];
            $o[$id]['sum'] += (int)$r['n'];
            if ($r['day'] === $today) $o[$id]['today'] = (int)$r['n'];
        }
        // پاک‌سازی آمار قدیمی (بیش از ۹۰ روز)
        if (mt_rand(1, 50) === 1) $pdo->prepare("DELETE FROM saas_model_daily WHERE day < ?")->execute([date('Y-m-d', time() - 90 * 86400)]);
    } catch (\Throwable $e) {}
    foreach ($o as $id => $x) $o[$id]['avg'] = round($x['sum'] / 7, 1);
    return $o;
}

/** مشخصات مدل‌های OpenRouter (از همان فهرست قیمت؛ اگر هنوز ذخیره نشده، یک بار فهرست تازه گرفته می‌شود) */
function mcap_or_meta($pdo)
{
    static $mem = null;
    if ($mem !== null) return $mem;
    $file = biz_price_cache_file('ormeta2');
    if (!is_file($file) && function_exists('biz_price_openrouter_catalog')) {
        static $tried = false;
        if (!$tried) { $tried = true; biz_price_openrouter_catalog($pdo, true); }
    }
    $c = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    return $mem = is_array($c['items'] ?? null) ? $c['items'] : [];
}

/** پیدا کردن همین مدل در فهرست OpenRouter (برای مدل AvalAI هم: همان مدل با نام سازنده) → [id, meta] */
function mcap_or_match($pdo, $m)
{
    $meta = mcap_or_meta($pdo);
    if (!$meta) return ['', null];
    $mid = trim((string)($m['model_id'] ?? ''));
    $lk = strtolower($mid);
    foreach ([$mid, (string)($m['price_ref'] ?? '')] as $try) if ($try !== '' && isset($meta[$try])) return [$try, $meta[$try]];
    foreach ($meta as $id => $x) if (strtolower($id) === $lk) return [$id, $x];
    $norm = biz_price_norm($mid);
    $pref = ['openai', 'anthropic', 'google', 'x-ai', 'deepseek', 'meta-llama', 'meta', 'mistralai', 'qwen', 'moonshotai', 'z-ai'];
    $best = 99; $hit = ['', null];
    foreach ($meta as $id => $x) {
        if (strpos($id, ':free') !== false || biz_price_norm($id) !== $norm) continue;
        $vendor = strpos($id, '/') !== false ? substr($id, 0, strpos($id, '/')) : '';
        $rank = ($i = array_search($vendor, $pref, true)) === false ? 50 : $i;
        if ($rank < $best) { $best = $rank; $hit = [$id, $x]; }
    }
    return $hit;
}

/** طول متن قابل‌فهم: 128000 → 128K، 1048576 → 1M */
function mcap_ctx_label($n)
{
    $n = (int)$n;
    if ($n <= 0) return '';
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.') . 'M';
    return round($n / 1000) . 'K';
}

/**
 * برچسب‌های دقیق از مشخصات رسمی
 * @return array ['tags' => [..], 'exp' => 'YYYY-MM-DD'|'', 'ref' => شناسه OpenRouter, 'desc' => توضیح رسمی, 'name' => نام رسمی, 'cap' => سقف روزانه]
 */
function mcap_spec($pdo, $m)
{
    [$rid, $x] = mcap_or_match($pdo, $m);
    $cat = (string)($m['category'] ?? 'text');
    $in = (array)($x['in'] ?? []); $sp = (array)($x['sp'] ?? []);
    $t = [];
    $cap = '';
    if (in_array($cat, ['text', 'agent'], true)) {
        if (($c = mcap_ctx_label($x['ctx'] ?? 0)) !== '') $t[] = 'حافظه ' . $c;
        if (in_array('image', $in, true)) $t[] = 'دیدن تصویر';
        if (in_array('file', $in, true)) $t[] = 'خواندن فایل/PDF';
        if (in_array('audio', $in, true)) $t[] = 'شنیدن صوت';
        if (in_array('video', $in, true)) $t[] = 'دیدن ویدیو';
        if (in_array('reasoning', $sp, true)) $t[] = 'استدلال';
        if (in_array('tools', $sp, true)) $t[] = 'ابزار';
        if (in_array('structured_outputs', $sp, true) || in_array('response_format', $sp, true)) $t[] = 'خروجی JSON';
    } elseif ($cat === 'image') {
        $caps = ($m['provider'] ?? '') === 'openrouter' && function_exists('biz_or_img_caps') ? biz_or_img_caps(saas_get_api_config($pdo), (string)$m['model_id']) : null;
        $p = $caps['p'] ?? [];
        $rmin = (int)($p['input_references']['min'] ?? 0);
        $rmax = isset($p['input_references']) ? (int)($p['input_references']['max'] ?? 0) : (in_array('image', $in, true) || in_array('image', (array)($caps['in'] ?? []), true) ? 1 : 0);
        $t[] = $rmin >= 1 ? 'فقط با تصویر ورودی' : 'متن به تصویر';
        if ($rmax > 0 && $rmin < 1) $t[] = 'ویرایش با تصویر' . ($rmax > 1 ? ' (تا ' . $rmax . ')' : '');
        $fmts = array_map('strtolower', (array)($p['output_format']['values'] ?? []));
        if (in_array('svg', $fmts, true) || preg_match('/vector|svg/i', (string)$m['model_id'])) $t[] = 'وکتور SVG';
        if (preg_match('/layer/i', (string)$m['model_id']) || preg_match('/\bRGBA layers?\b|separate layers/i', (string)($x['desc'] ?? ''))) $t[] = 'لایه‌های جدا';
        if ((int)($p['n']['max'] ?? 1) > 1) $t[] = 'چند تصویر';
        $res = array_map('strtoupper', array_map('strval', (array)($p['resolution']['values'] ?? [])));
        foreach (['4K', '2K'] as $rk) if (in_array($rk, $res, true)) { $t[] = 'کیفیت تا ' . $rk; break; }
        if (isset($p['aspect_ratio'])) $t[] = 'نسبت ابعاد';
        elseif ($caps && isset($p['size'])) $t[] = 'ابعاد خودکار';
    } elseif ($cat === 'video') {
        if (in_array('image', $in, true)) $t[] = 'تصویر به ویدیو';
    }
    if (strpos((string)$m['model_id'], ':free') !== false) { $t[] = 'رایگان'; $cap = '۵۰ درخواست در روز (سقف مدل‌های رایگان OpenRouter)'; }
    // مدل‌هایی که پیش از استفاده تأیید ۱۸+ می‌خواهند (از خطای ثبت‌شده)
    if (preg_match('/age confirmation|18\+/i', (string)($m['last_error'] ?? '') . ' ' . (string)(biz_settings($pdo)['img_err_' . (int)$m['id']] ?? ''))) $t[] = '۱۸+';
    return ['tags' => array_values(array_unique($t)), 'exp' => (string)($x['exp'] ?? ''), 'ref' => $rid, 'desc' => (string)($x['desc'] ?? ''), 'name' => (string)($x['name'] ?? ''), 'cap' => $cap];
}

/**
 * نسخه ۷۴: واژه‌نامه ثابت کارایی هر دسته — همه مدل‌ها فقط با همین عبارت‌ها توصیف می‌شوند تا فیلتر دقیق باشد
 * (عبارت‌های هم‌معنی همیشه یکسان نوشته می‌شوند)
 */
function mcap_vocab($cat)
{
    $v = [
        'text' => ['گفتگوی عمومی', 'پاسخ سریع و ارزان', 'کدنویسی', 'استدلال و حل مسئله', 'ریاضی و محاسبات', 'تحلیل اسناد طولانی', 'خلاصه‌سازی',
                   'ترجمه', 'نویسندگی و تولید محتوا', 'نویسندگی خلاق', 'پشتیبانی و پاسخ به مشتری', 'تحلیل داده و جدول', 'زبان فارسی قوی',
                   'خواندن تصویر', 'خواندن PDF و فایل', 'شنیدن صوت', 'دیدن ویدیو', 'استفاده از ابزار', 'خروجی JSON ساختاریافته', 'جستجوی وب', 'حافظه طولانی'],
        'image' => ['ساخت تصویر از متن', 'ویرایش تصویر', 'فقط با تصویر ورودی', 'ترکیب چند تصویر', 'عکس واقع‌گرایانه', 'تصویرسازی و نقاشی', 'انیمه و کارتون',
                    'چهره و پرتره', 'عکس محصول', 'لوگو', 'پوستر و بنر', 'طراحی گرافیکی', 'متن خوانا در تصویر', 'متن فارسی در تصویر', 'ساخت وکتور SVG',
                    'پلان و نقشه ساختمان', 'معماری و طراحی داخلی', 'جدا کردن لایه‌ها', 'حذف پس‌زمینه', 'پس‌زمینه شفاف', 'کیفیت تا 2K', 'کیفیت تا 4K',
                    'چند تصویر در یک درخواست', 'ساخت سریع و ارزان', 'نیاز به تأیید ۱۸+'],
        'video' => ['ساخت ویدیو از متن', 'ساخت ویدیو از تصویر', 'ویدیو با صدا', 'حرکت دوربین سینمایی', 'انیمیشن', 'ویدیوی تبلیغاتی کوتاه',
                    'کیفیت 1080p', 'کیفیت 4K', 'ویدیوی طولانی‌تر', 'ساخت سریع و ارزان'],
        'tts' => ['خواندن متن فارسی', 'صدای طبیعی', 'چند صدای مختلف', 'کنترل لحن و احساس', 'چندزبانه', 'ساخت سریع و ارزان'],
        'stt' => ['گفتار فارسی به متن', 'چندزبانه', 'دقت بالا', 'زمان‌بندی کلمات', 'تشخیص گوینده', 'ساخت سریع و ارزان'],
    ];
    if ($cat === 'agent') $cat = 'text';
    return $v[$cat] ?? $v['text'];
}

/** یکسان‌سازی نوشتار فارسی برای مقایسه (ی/ک عربی، نیم‌فاصله، فاصله‌ها) */
function mcap_norm($s)
{
    $s = strtr(trim((string)$s), ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', "\u{200C}" => '', "\u{200F}" => '', ' ' => '', '-' => '', '_' => '']);
    return mb_strtolower($s);
}

/** تبدیل عبارت‌های آزاد/مشخصات به عبارت‌های واژه‌نامه (موارد خارج از واژه‌نامه حذف می‌شوند) */
function mcap_canon($items, $cat)
{
    $vocab = mcap_vocab($cat);
    $map = [];
    foreach ($vocab as $w) $map[mcap_norm($w)] = $w;
    $syn = [
        'متن به تصویر' => 'ساخت تصویر از متن', 'تولید تصویر از متن' => 'ساخت تصویر از متن', 'ساخت تصویر' => 'ساخت تصویر از متن', 'تولید تصویر' => 'ساخت تصویر از متن',
        'ویرایش با تصویر' => 'ویرایش تصویر', 'ویرایش عکس' => 'ویرایش تصویر', 'ویرایش تصویر با مرجع' => 'ویرایش تصویر',
        'وکتور svg' => 'ساخت وکتور SVG', 'وکتور' => 'ساخت وکتور SVG', 'ساخت وکتور' => 'ساخت وکتور SVG', 'svg' => 'ساخت وکتور SVG',
        'لایه‌های جدا' => 'جدا کردن لایه‌ها', 'جداسازی لایه‌ها' => 'جدا کردن لایه‌ها', 'چند تصویر' => 'چند تصویر در یک درخواست',
        '۱۸+' => 'نیاز به تأیید ۱۸+', 'پوستر و تایپوگرافی' => 'پوستر و بنر', 'پوستر' => 'پوستر و بنر', 'بنر' => 'پوستر و بنر',
        'تایپوگرافی' => 'متن خوانا در تصویر', 'متن داخل تصویر' => 'متن خوانا در تصویر', 'نقشه ساختمان' => 'پلان و نقشه ساختمان', 'پلان' => 'پلان و نقشه ساختمان',
        'عکس واقعی' => 'عکس واقع‌گرایانه', 'تصویرسازی' => 'تصویرسازی و نقاشی', 'نقاشی' => 'تصویرسازی و نقاشی', 'پرتره' => 'چهره و پرتره',
        'دیدن تصویر' => 'خواندن تصویر', 'تحلیل تصویر' => 'خواندن تصویر', 'خواندن فایل/pdf' => 'خواندن PDF و فایل', 'خواندن pdf' => 'خواندن PDF و فایل',
        'استدلال' => 'استدلال و حل مسئله', 'ابزار' => 'استفاده از ابزار', 'فراخوانی ابزار' => 'استفاده از ابزار', 'خروجی json' => 'خروجی JSON ساختاریافته',
        'برنامه‌نویسی' => 'کدنویسی', 'کدنویسی پیچیده' => 'کدنویسی', 'گفتگوی سریع و ارزان' => 'پاسخ سریع و ارزان', 'سریع و ارزان' => 'پاسخ سریع و ارزان',
        'گفتگو' => 'گفتگوی عمومی', 'تولید محتوا' => 'نویسندگی و تولید محتوا', 'نویسندگی' => 'نویسندگی و تولید محتوا', 'خلاصه‌سازی متن' => 'خلاصه‌سازی',
        'ریاضی' => 'ریاضی و محاسبات', 'تصویر به ویدیو' => 'ساخت ویدیو از تصویر', 'متن به ویدیو' => 'ساخت ویدیو از متن', 'متن به گفتار فارسی' => 'خواندن متن فارسی',
    ];
    foreach ($syn as $k => $w) if (in_array($w, $vocab, true)) $map[mcap_norm($k)] = $w;
    if (in_array($cat, ['image', 'video', 'tts', 'stt'], true)) $map[mcap_norm('ساخت سریع و ارزان')] = 'ساخت سریع و ارزان';
    $out = [];
    foreach ((array)$items as $it) {
        $k = mcap_norm(preg_replace('/\s*\(.*\)\s*$/u', '', (string)$it));   // «ویرایش با تصویر (تا ۴)» → «ویرایش با تصویر»
        if (isset($map[$k]) && !in_array($map[$k], $out, true)) $out[] = $map[$k];
    }
    return $out;
}

/** کارایی‌هایی که از مشخصات رسمی قطعی‌اند (برای فیلتر) — مثلاً «ویرایش تصویر» یا «حافظه طولانی» */
function mcap_spec_caps($m, array $spec)
{
    $cat = (string)($m['category'] ?? 'text');
    $c = mcap_canon($spec['tags'], $cat);
    foreach ($spec['tags'] as $t) if (preg_match('/^حافظه\s+(\d+(?:\.\d+)?)(K|M)$/u', $t, $mm) && ($mm[2] === 'M' || (float)$mm[1] >= 200)) $c[] = 'حافظه طولانی';
    return array_values(array_unique(array_filter($c, fn($x) => in_array($x, mcap_vocab($cat), true))));
}

/**
 * کارایی (فقط از واژه‌نامه) + توضیح فارسی با مدل کارهای داخلی
 * @return array ['caps' => [عبارت‌های واژه‌نامه], 'desc' => توضیح فارسی]
 */
function mcap_ai_text($pdo, $m, array $spec)
{
    if (!function_exists('biz_ai_call')) return ['caps' => [], 'desc' => ''];
    $cat = (string)($m['category'] ?? 'text');
    $cats = ['text' => 'text chat', 'agent' => 'AI agent', 'image' => 'image generation', 'video' => 'video generation', 'tts' => 'text to speech', 'stt' => 'speech to text'];
    $info = 'Model: ' . ($spec['name'] !== '' ? $spec['name'] : ($m['title'] ?? '')) . ' (' . ($m['model_id'] ?? '') . ")\nCategory: " . ($cats[$cat] ?? 'AI')
          . "\nSpecs: " . implode(', ', $spec['tags']) . "\nOfficial description: " . ($spec['desc'] !== '' ? $spec['desc'] : '(none — use your general knowledge of this model if you know it; if you do not know it, say so briefly)');
    $sys = "You write a Persian capability summary for an admin price list, so models can be compared and FILTERED. Return ONLY JSON: "
         . "{\"caps\": [3 to 8 items], \"desc_fa\": \"...\"}. "
         . "caps: choose ONLY from this fixed list and copy each item EXACTLY as written (no other wording, no new items): "
         . implode(' | ', mcap_vocab($cat)) . ". Pick the items that the official description/specs clearly support, most important first. "
         . "desc_fa: a faithful Persian translation of the official description (max 700 characters; if there is no description, one short sentence). No marketing words, no prices.";
    try {
        $r = biz_ai_call($pdo, saas_get_api_config($pdo), null, [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $info]], 900, 0.1);
    } catch (\Throwable $e) { return ['caps' => [], 'desc' => '']; }
    if (empty($r['ok'])) return ['caps' => [], 'desc' => ''];
    $c = trim((string)($r['content'] ?? ''));
    $j = null;
    if (preg_match('/\{.*\}/su', $c, $mm)) $j = json_decode($mm[0], true);
    if (is_array($j)) {
        $caps = is_array($j['caps'] ?? null) ? $j['caps'] : explode(' | ', mcap_clean_text((string)($j['caps'] ?? '')));
        return ['caps' => mcap_canon($caps, $cat), 'desc' => mb_substr(trim(strip_tags((string)($j['desc_fa'] ?? ''))), 0, 1200)];
    }
    return ['caps' => mcap_canon(explode(' | ', mcap_clean_text($c)), $cat), 'desc' => ''];   // پاسخ غیر JSON
}

/** یکدست کردن متن کارایی: حداکثر ۸ مورد کوتاه با جداکننده « | » */
function mcap_clean_text($s)
{
    $s = trim(strip_tags((string)$s));
    $parts = preg_split('/\s*(?:\||\n|،|؛|;|•|\x{2022})\s*/u', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/^[\-\*\d\.\)\s«"\']+|[»"\'\.\s]+$/u', '', $p));
        if ($p === '' || mb_strlen($p) > 45) continue;
        $out[] = $p;
        if (count($out) >= 8) break;
    }
    return mb_substr(implode(' | ', $out), 0, 390);
}

/**
 * به‌روزرسانی کارایی و تاریخ حذف یک مدل
 * @param bool $ai ساخت دوباره خلاصه کارایی با هوش مصنوعی (فقط مدل‌هایی که کارایی دستی ندارند)
 */
function mcap_refresh($pdo, $m, $ai = true)
{
    mcap_schema2($pdo);
    if (!$m || empty($m['id'])) return null;
    $spec = mcap_spec($pdo, $m);
    $old = json_decode((string)($m['cap_meta'] ?? ''), true) ?: [];
    $text = (string)($m['cap_text'] ?? '');
    $desc = (string)($m['cap_desc'] ?? '');
    $ver = (int)($old['v'] ?? 0);
    $auto = ($m['cap_src'] ?? 'auto') !== 'manual';
    $from_spec = mcap_spec_caps($m, $spec);
    if ($ai) {
        $t = mcap_ai_text($pdo, $m, $spec);
        if ($t['desc'] !== '') $desc = $t['desc'];
        if ($t['caps'] || $t['desc'] !== '') $ver = MCAP_VER;
        if ($auto && ($t['caps'] || $from_spec)) $text = implode(' | ', array_slice(array_values(array_unique(array_merge($t['caps'], $from_spec))), 0, 10));
    } elseif ($auto && $text !== '') {
        // بدون هوش مصنوعی: کارایی فعلی یکسان‌سازی و موارد قطعی مشخصات اضافه می‌شود
        $text = implode(' | ', array_slice(array_values(array_unique(array_merge(mcap_canon(explode(' | ', $text), (string)($m['category'] ?? 'text')), $from_spec))), 0, 10));
    }
    $meta = json_encode(['tags' => $spec['tags'], 'ref' => $spec['ref'], 'cap' => $spec['cap'], 'v' => $ver, 'try' => $ai ? MCAP_VER : (int)($old['try'] ?? 0)], JSON_UNESCAPED_UNICODE);
    try {
        $pdo->prepare("UPDATE saas_ai_models SET cap_meta=?, expire_date=?, cap_text=?, cap_desc=?, cap_desc_en=?, cap_at=? WHERE id=?")
            ->execute([mb_substr($meta, 0, 1500), $spec['exp'], $text, $desc, mb_substr($spec['desc'], 0, 4000), biz_now(), (int)$m['id']]);
    } catch (\Throwable $e) { error_log('[MCAP] save: ' . $e->getMessage()); }
    return ['tags' => $spec['tags'], 'exp' => $spec['exp'], 'text' => $text, 'cap' => $spec['cap'], 'desc' => $desc, 'desc_en' => $spec['desc']];
}

/** آیا کارایی/تاریخ حذف این مدل باید (خودکار) ساخته یا تازه شود؟ */
function mcap_needs($m)
{
    if (empty($m['cap_at'])) return true;
    $meta = json_decode((string)($m['cap_meta'] ?? ''), true) ?: [];
    $old = strtotime((string)$m['cap_at']) < time() - 86400;
    if ((int)($meta['v'] ?? 0) < MCAP_VER) return $old || (int)($meta['try'] ?? 0) < MCAP_VER;   // نسخه قبلی کارایی: یک بار فوراً از نو (اگر ناموفق بود، روز بعد)
    return trim((string)($m['cap_text'] ?? '')) === '' && ($m['cap_src'] ?? 'auto') !== 'manual' && $old;
}

/** وضعیت تاریخ حذف برای نمایش: ['label', 'cls' => ok|soon|gone|none] */
function mcap_exp_view($exp)
{
    $exp = (string)$exp;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) return ['label' => 'بدون تاریخ حذف', 'cls' => 'none'];
    $days = (int)floor((strtotime($exp . ' 23:59:59') - time()) / 86400);
    $d = function_exists('biz_jdate') ? biz_jdate($exp . ' 00:00:00') : $exp;
    if ($days < 0) return ['label' => '⛔ حذف شده از ' . $d, 'cls' => 'gone'];
    if ($days <= 30) return ['label' => '⏳ حذف ' . $d . ' (' . $days . ' روز دیگر)', 'cls' => 'soon'];
    return ['label' => 'حذف ' . $d, 'cls' => 'ok'];
}

/** کارایی‌های یک مدل به‌صورت آرایه (برای فیلتر) */
function mcap_list($m)
{
    return array_values(array_filter(array_map('trim', explode(' | ', (string)($m['cap_text'] ?? '')))));
}

/** HTML ستون کارایی: برچسب‌های قابل فیلتر + چند مشخصه دیگر (حافظه، رایگان) */
function mcap_cell_html($m)
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $meta = json_decode((string)($m['cap_meta'] ?? ''), true) ?: [];
    $o = '';
    foreach (mcap_list($m) as $c) $o .= '<span class="cp' . ($c === 'نیاز به تأیید ۱۸+' ? ' warn' : '') . '" data-cap="' . $h($c) . '" title="فقط مدل‌هایی که این کارایی را دارند">' . $h($c) . '</span>';
    foreach ((array)($meta['tags'] ?? []) as $t) if (mb_strpos($t, 'حافظه') === 0 || $t === 'رایگان') $o .= '<span class="cs">' . $h($t) . '</span>';
    return $o !== '' ? $o : '<span class="cn">' . (empty($m['cap_at']) ? 'در حال تکمیل…' : '—') . '</span>';
}

// =============================================================================
// نسخه ۷۵: محدودیت درخواستی که خود ارائه‌دهنده برای هر مدل گذاشته است
//  - AvalAI: سطح حساب از GET /user/v1/credit (account_tier) + جدول «Model Rate Limits» همان سطح در docs.avalai.ir
//    (درخواست در دقیقه RPM برای هر مدل؛ AvalAI سقف روزانه جداگانه برای مدل‌ها منتشر نمی‌کند)
//  - OpenRouter: مدل‌های پولی سقف درخواست ندارند (فقط اعتبار حساب)؛ مدل‌های :free ← ۲۰ در دقیقه و ۵۰ در روز
//    (اگر حساب حداقل ۱۰ دلار اعتبار خریده باشد: ۱۰۰۰ در روز) — وضعیت حساب از GET /key
//  - مدیر می‌تواند برای هر مدل دستی هم وارد کند (اولویت با مقدار دستی)
// =============================================================================

function mcap_schema3($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    mcap_schema2($pdo);
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.mcap_schema_v3' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        saas_add_column_if_missing($pdo, 'saas_ai_models', 'req_limit', "VARCHAR(80) NOT NULL DEFAULT ''");
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[MCAP] schema3: ' . $e->getMessage()); }
}

/** اطلاعات محدودیت ذخیره‌شده (بدون اتصال به اینترنت) */
function mcap_lim_cache($set = null)
{
    static $c = null;
    if ($set !== null) return $c = $set;   // بعد از دریافت تازه
    if ($c !== null) return $c;
    $f = biz_price_cache_file('limits');
    $c = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    return $c;
}

/** آیا اطلاعات محدودیت باید دوباره گرفته شود؟ (هر ۲۴ ساعت) */
function mcap_lim_stale()
{
    $c = mcap_lim_cache();
    return (int)($c['at'] ?? 0) < time() - 86400;
}

/** دریافت سطح حساب AvalAI، جدول محدودیت مدل‌ها و وضعیت حساب OpenRouter */
function mcap_lim_fetch($pdo, $force = false)
{
    if (!$force && !mcap_lim_stale()) return mcap_lim_cache();
    $cfg = saas_get_api_config($pdo);
    $old = mcap_lim_cache();
    $out = ['at' => time(), 'av_tier' => $old['av_tier'] ?? null, 'av' => $old['av'] ?? [], 'or_free_tier' => $old['or_free_tier'] ?? null, 'err' => []];
    // --- AvalAI ---
    $akey = trim((string)($cfg['avalai_api_key'] ?? ''));
    if ($akey !== '' && !empty($cfg['avalai_enabled'])) {
        $root = preg_replace('#/v\d+/?$#', '', rtrim((string)($cfg['avalai_api_base'] ?: 'https://api.avalai.ir/v1'), '/'));
        $r = biz_media_http('GET', $root . '/user/v1/credit', $akey, null, 20);
        if (!empty($r['ok']) && isset($r['json']['account_tier'])) $out['av_tier'] = max(0, min(9, (int)$r['json']['account_tier']));
        else $out['err'][] = 'سطح حساب AvalAI: ' . ($r['error'] ?? 'نامشخص');
        if ($out['av_tier'] !== null) {
            $url = defined('MCAP_AV_LIMITS_URL') ? str_replace('{t}', (string)$out['av_tier'], MCAP_AV_LIMITS_URL) : 'https://docs.avalai.ir/en/rate-limits-tier' . $out['av_tier'];
            $h = biz_media_http('GET', $url, '', null, 40, true);
            $map = !empty($h['ok']) ? mcap_parse_av_limits((string)$h['body']) : [];
            if ($map) $out['av'] = $map; else $out['err'][] = 'جدول محدودیت AvalAI: ' . ($h['error'] ?? 'خوانده نشد');
        }
    }
    // --- OpenRouter ---
    $okey = trim((string)($cfg['openrouter_api_key'] ?? ''));
    if ($okey !== '' && !empty($cfg['openrouter_enabled'])) {
        $base = rtrim((string)($cfg['openrouter_api_base'] ?: 'https://openrouter.ai/api/v1'), '/');
        $r = biz_media_http('GET', $base . '/key', $okey, null, 20);
        if (!empty($r['ok']) && isset($r['json']['data'])) $out['or_free_tier'] = !empty($r['json']['data']['is_free_tier']);
        else $out['err'][] = 'وضعیت حساب OpenRouter: ' . ($r['error'] ?? 'نامشخص');
    }
    @file_put_contents(biz_price_cache_file('limits'), json_encode($out, JSON_UNESCAPED_UNICODE));
    return mcap_lim_cache($out);
}

/** خواندن جدول «Model | RPM | TPM | Provider | Owner» از صفحه مستندات AvalAI → [model => ['rpm','tpm']] */
function mcap_parse_av_limits($html)
{
    $map = [];
    $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // ۱) داده جدول داخل صفحه: "cells":["gpt-4o-mini","500.0","500000.0","openai","openai"]
    if (preg_match_all('/"cells"\s*:\s*\[\s*"([^"]{1,200})"\s*,\s*"([\d.]+)"\s*,\s*"([\d.]*)"/u', $t, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) if (!isset($map[$x[1]])) $map[$x[1]] = ['rpm' => (float)$x[2], 'tpm' => (float)$x[3]];
    // ۲) پشتیبان: ردیف‌های جدول HTML
    if (!$map && preg_match_all('#<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>\s*([\d.,]+)\s*</td>\s*<td[^>]*>\s*([\d.,]*)\s*</td>#su', $t, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) { $id = trim(strip_tags($x[1])); if ($id !== '' && !isset($map[$id])) $map[$id] = ['rpm' => (float)str_replace(',', '', $x[2]), 'tpm' => (float)str_replace(',', '', $x[3])]; }
    return $map;
}

/** عدد فارسی کوتاه: 1500 → «۱٬۵۰۰» */
function mcap_fa_num($n)
{
    $n = (float)$n;
    $s = number_format($n, (floor($n) == $n) ? 0 : 1);
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬', '.' => '٫']);
}

/**
 * محدودیت درخواست یک مدل از سوی ارائه‌دهنده
 * @return array ['label' => متن کوتاه, 'sub' => توضیح, 'per_day' => معادل روزانه برای مرتب‌سازی (INF = بدون سقف، -1 = نامشخص)]
 */
function mcap_limit($pdo, $m)
{
    $manual = trim((string)($m['req_limit'] ?? ''));
    if ($manual !== '') {
        $pd = preg_match('/(\d[\d,٬]*)/u', strtr($manual, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']), $mm) ? (float)str_replace([',', '٬'], '', $mm[1]) : -1;
        if ($pd > 0 && preg_match('/دقیقه|min/u', $manual)) $pd *= 1440;
        return ['label' => $manual, 'sub' => 'ثبت دستی مدیر', 'short' => 'ثبت دستی', 'per_day' => $pd];
    }
    $c = mcap_lim_cache();
    $mid = (string)($m['model_id'] ?? '');
    if (($m['provider'] ?? '') === 'openrouter') {
        if (strpos($mid, ':free') !== false) {
            $free = $c['or_free_tier'] ?? null;
            $day = $free === false ? 1000 : 50;
            return ['label' => mcap_fa_num($day) . ' در روز', 'short' => 'رایگان OpenRouter — ۲۰ در دقیقه', 'sub' => '۲۰ در دقیقه — مدل رایگان OpenRouter' . ($free === null ? ' (۱۰۰۰ در روز اگر حساب حداقل ۱۰ دلار اعتبار خریده باشد)' : ''), 'per_day' => $day];
        }
        return ['label' => 'بدون سقف', 'short' => 'OpenRouter', 'sub' => 'OpenRouter برای مدل‌های پولی سقف درخواست ندارد (فقط به اندازه اعتبار حساب)', 'per_day' => INF];
    }
    if (!isset($c['av_tier'])) return ['label' => 'در حال دریافت…', 'sub' => '', 'short' => '', 'per_day' => -1];
    $av = (array)($c['av'] ?? []);
    $hit = $av[$mid] ?? null;
    if (!$hit) { $n = biz_price_norm($mid); foreach ($av as $id => $x) if (biz_price_norm($id) === $n) { $hit = $x; break; } }
    $tier = 'سطح ' . mcap_fa_num((int)$c['av_tier']) . ' حساب AvalAI';
    if (!$hit || (float)$hit['rpm'] <= 0) return ['label' => 'نامشخص', 'short' => 'در جدول AvalAI نیست', 'sub' => 'در جدول محدودیت ' . $tier . ' نیست؛ می‌توانید در فرم ویرایش دستی وارد کنید', 'per_day' => -1];
    return ['label' => mcap_fa_num($hit['rpm']) . ' در دقیقه', 'short' => $tier, 'sub' => $tier . ((float)$hit['tpm'] > 0 ? ' — ' . mcap_fa_num($hit['tpm']) . ' توکن در دقیقه' : '') . ' (AvalAI سقف روزانه جدا اعلام نمی‌کند)', 'per_day' => (float)$hit['rpm'] * 1440];
}
