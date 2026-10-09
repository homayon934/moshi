<?php
/**
 * قیمت‌گذاری مدل‌ها بر اساس قیمت دلاری سایت مرجع + درصد سود (اعتبار کاربران به تومان)
 *
 * قیمت پایه هر مدل (دلار) از این منابع گرفته می‌شود (به ترتیب):
 *   ۱) فهرست مدل‌های AvalAI (اگر قیمت در پاسخ آن باشد)
 *   ۲) فهرست عمومی مدل‌های OpenRouter (برای مدل‌های AvalAI با تطبیق نام)
 *   ۳) جدول داخلی قیمت رسمی مدل‌های صوتی/تصویری/ویدیویی رایج
 *   ۴) ورود دستی توسط مدیر
 * قیمت تومانی = قیمت دلاری × نرخ دلار × (۱ + درصد سود ÷ ۱۰۰)
 *
 * واحدها: متن/ایجنت: دلار برای هر ۱ میلیون توکن (ورودی و خروجی جدا)
 *         تصویر: هر تصویر — ویدیو: هر ثانیه — صوت به متن: هر دقیقه — متن به صوت: هر ۱۰۰۰ کاراکتر
 */

if (!defined('BIZ_PRICE_CACHE_HOURS')) define('BIZ_PRICE_CACHE_HOURS', 24);   // روزی یک بار (نسخه ۵۴)

/** واحد قیمت هر دسته */
function biz_price_units()
{
    return [
        'text'  => ['kind' => 'tokens', 'label' => 'هر ۱ میلیون توکن'],
        'agent' => ['kind' => 'tokens', 'label' => 'هر ۱ میلیون توکن'],
        'image' => ['kind' => 'unit', 'label' => 'هر تصویر'],
        'video' => ['kind' => 'unit', 'label' => 'هر ثانیه ویدیو'],
        'stt'   => ['kind' => 'unit', 'label' => 'هر دقیقه صدا'],
        'tts'   => ['kind' => 'unit', 'label' => 'هر ۱۰۰۰ کاراکتر'],
    ];
}

function biz_price_is_token_cat($cat) { return in_array($cat, ['text', 'agent'], true); }

/** جدول داخلی قیمت رسمی (دلار) برای مدل‌هایی که در فهرست‌های عمومی قیمت ندارند */
function biz_price_table()
{
    return [
        'stt' => ['whisper-1' => 0.006, 'gpt-4o-transcribe' => 0.006, 'gpt-4o-mini-transcribe' => 0.003],
        'tts' => ['tts-1' => 0.015, 'tts-1-hd' => 0.03, 'gpt-4o-mini-tts' => 0.015],
        'image' => ['dall-e-3' => 0.04, 'dall-e-2' => 0.02, 'gpt-image-1' => 0.042, 'gpt-image-1-mini' => 0.011],
        'video' => ['sora-2' => 0.10, 'sora-2-pro' => 0.30],
    ];
}

/**
 * قیمت توکنی رسمی مدل‌های تصویری (دلار برای هر ۱ میلیون توکن): [ورودی متن، ورودی تصویر، خروجی]
 * هزینه واقعی هر تصویر از روی توکن‌هایی که سرویس در پاسخ گزارش می‌کند حساب می‌شود (نسخه ۵۳)
 */
function biz_price_image_tokens()
{
    return [
        'gpt-image-1'                    => [5.00, 10.00, 40.00],
        'gpt-image-1-mini'               => [2.00, 2.50, 8.00],
        'gpt-image-1.5'                  => [5.00, 8.00, 32.00],
        'gemini-2.5-flash-image'         => [0.30, 0.30, 30.00],
        'gemini-2.5-flash-image-preview' => [0.30, 0.30, 30.00],
        'gemini-3-pro-image-preview'     => [2.00, 2.00, 120.00],
    ];
}

/** توکن خروجی معمول یک تصویر (فقط برای برآورد پیش از ساخت و وقتی سرویس توکن گزارش نکند) */
function biz_price_image_typical_out($model_id)
{
    $n = biz_price_norm($model_id);
    if (strpos($n, 'gpt-image') === 0) return 4160;   // کیفیت بالا ۱۰۲۴×۱۰۲۴ (پیش‌فرض auto معمولاً بالا)
    if (strpos($n, 'gemini-3') === 0) return 1120;
    return 1290;                                        // Gemini 2.5 Flash Image و مشابه
}

/** ستون قیمت ورودی تصویر (نسخه ۵۳) */
function biz_price_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.price_tok_v1' : '';
    $flag2 = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.price_tok_v2' : '';
    if ($flag !== '' && is_file($flag)) {
        // نسخه ۵۷: یک بار دیگر قیمت مدل‌های تصویری «خودکار» با خواندن درست قیمت توکن تصویر OpenRouter به‌روز شود
        if ($flag2 !== '' && !is_file($flag2)) {
            @file_put_contents($flag2, date('c'));
            try { if (function_exists('biz_models')) foreach (biz_models($pdo, false, 'image', true) as $m) if (($m['price_src'] ?? 'auto') === 'auto') biz_model_refresh_price($pdo, $m); }
            catch (\Throwable $e) { error_log('[PRICE] tok_v2: ' . $e->getMessage()); }
        }
        return;
    }
    if ($flag2 !== '') @file_put_contents($flag2, date('c'));
    try {
        saas_add_column_if_missing($pdo, 'saas_ai_models', 'usd_img_in', 'DECIMAL(14,6) NOT NULL DEFAULT 0');
        if ($flag !== '') @file_put_contents($flag, date('c'));
        // یک بار: قیمت مدل‌های تصویری «خودکار» به قیمت توکنی رسمی به‌روز شود
        if (function_exists('biz_models')) foreach (biz_models($pdo, false, 'image', true) as $m)
            if (($m['price_src'] ?? 'auto') === 'auto') biz_model_refresh_price($pdo, $m);
    } catch (\Throwable $e) { $done = false; error_log('[PRICE] schema: ' . $e->getMessage()); }
}

/** آیا مدل تصویری قیمت توکنی دارد؟ */
function biz_image_token_priced($m)
{
    return (float)($m['usd_out'] ?? 0) > 0;
}

/** نرخ دلار (تومان؛ حاشیه اطمینان نرخ از قبل روی آن اعمال شده) */
function biz_price_rate($pdo)
{
    return (float)(biz_settings($pdo)['usd_rate'] ?? 0);
}

/** درصد سود مؤثر یک مدل (−۱ در مدل = درصد پیش‌فرض) */
function biz_model_margin($pdo, $m)
{
    $own = isset($m['margin']) ? (float)$m['margin'] : -1;
    if ($own >= 0) return $own;
    return max(0, (float)(biz_settings($pdo)['model_margin'] ?? 30));
}

/** آیا مدل قیمت دلاری معتبر دارد؟ */
function biz_model_has_price($m)
{
    if (!$m) return false;
    $cat = $m['category'] ?? 'text';
    if (biz_price_is_token_cat($cat)) return (float)($m['usd_in'] ?? 0) > 0 || (float)($m['usd_out'] ?? 0) > 0;
    if ($cat === 'image' && biz_image_token_priced($m)) return true;
    return (float)($m['usd_unit'] ?? 0) > 0;
}

/** دلار → تومان نهایی (با نرخ روز و درصد سود مدل) */
function biz_model_usd_toman($pdo, $m, $usd)
{
    return (float)$usd * biz_price_rate($pdo) * (1 + biz_model_margin($pdo, $m) / 100);
}

/** قیمت پشتیبان هر ۱۰۰۰ توکن (تومان) برای مدل‌های بدون قیمت دلاری */
function biz_price_fallback_1k($pdo)
{
    $cfg = saas_get_api_config($pdo);
    return max(0.0, (float)$cfg['cost_per_1k_tokens']);
}

/**
 * قیمت‌های تومانی یک مدل
 * @return array ['in' => تومان/۱م توکن, 'out' => ..., 'unit' => تومان/واحد, 'per1k' => تومان/۱۰۰۰ توکن ترکیبی, 'priced' => bool]
 */
function biz_model_toman_prices($pdo, $m)
{
    $cat = $m['category'] ?? 'text';
    $rate = biz_price_rate($pdo);
    $priced = biz_model_has_price($m) && $rate > 0;
    if (biz_price_is_token_cat($cat)) {
        if ($priced) {
            $in = biz_model_usd_toman($pdo, $m, $m['usd_in']);
            $out = biz_model_usd_toman($pdo, $m, $m['usd_out']);
            return ['in' => $in, 'out' => $out, 'unit' => 0, 'per1k' => ($in * 0.75 + $out * 0.25) / 1000, 'priced' => true];
        }
        $p = biz_price_fallback_1k($pdo) * max(0.01, (float)($m['multiplier'] ?? 1));
        return ['in' => $p * 1000, 'out' => $p * 1000, 'unit' => 0, 'per1k' => $p, 'priced' => false];
    }
    if ($priced && $cat === 'image' && biz_image_token_priced($m)) {
        // تصویر با قیمت توکنی: قیمت هر ۱ میلیون توکن + برآورد هر تصویر (قیمت واحد ثبت‌شده یا توکن معمول)
        $in = biz_model_usd_toman($pdo, $m, $m['usd_in']); $out = biz_model_usd_toman($pdo, $m, $m['usd_out']);
        $img = biz_model_usd_toman($pdo, $m, (float)($m['usd_img_in'] ?? 0) > 0 ? $m['usd_img_in'] : $m['usd_in']);
        $unit = (float)($m['usd_unit'] ?? 0) > 0 ? biz_model_usd_toman($pdo, $m, $m['usd_unit'])
              : ($in * 300 + $out * biz_price_image_typical_out($m['model_id'] ?? '')) / 1000000;
        return ['in' => $in, 'out' => $out, 'img_in' => $img, 'unit' => $unit, 'per1k' => 0, 'priced' => true, 'tokens' => true];
    }
    if ($priced) return ['in' => 0, 'out' => 0, 'unit' => biz_model_usd_toman($pdo, $m, $m['usd_unit']), 'per1k' => 0, 'priced' => true];
    // قیمت پشتیبان (تومان) برای مدل‌های بدون قیمت دلاری — از زبانه «تنظیمات قیمت»
    $s = biz_settings($pdo);
    $unit = 0.0;
    if ($cat === 'image') $unit = (float)$s['image_fallback_toman'] * max(0.01, (float)($m['multiplier'] ?? 1));
    if ($cat === 'video') $unit = (float)$s['video_fallback_toman'] * max(0.01, (float)($m['multiplier'] ?? 1));
    if ($cat === 'stt') $unit = (float)(function_exists('hd_sys') ? hd_sys($pdo)['hd_voice_cost'] : ($s['hd_voice_cost'] ?? 300));
    if ($cat === 'tts') $unit = (float)(function_exists('hd_sys') ? hd_sys($pdo)['hd_tts_cost'] : ($s['hd_tts_cost'] ?? 800));
    return ['in' => 0, 'out' => 0, 'unit' => $unit, 'per1k' => 0, 'priced' => false];
}

/** هزینه متنی (تومان) از روی توکن‌های ورودی/خروجی */
function biz_price_text_cost($pdo, $m, $in, $out)
{
    $in = max(0, (int)$in); $out = max(0, (int)$out);
    if ($in + $out <= 0) return 0;
    if ($m) {
        $p = biz_model_toman_prices($pdo, $m);
        $cost = ($in * $p['in'] + $out * $p['out']) / 1000000;
    } else {
        $cost = ($in + $out) * biz_price_fallback_1k($pdo) / 1000;
    }
    return max(1, (int)ceil($cost - 0.0000001));
}

/** نمایش مبلغ تومانی (اعشار برای مبالغ خیلی کوچک) */
function biz_toman_fmt($v)
{
    $v = (float)$v;
    if ($v <= 0) return '۰';
    if ($v < 10) return rtrim(rtrim(number_format($v, 2), '0'), '.');
    return number_format((int)round($v));
}

// =============================================================================
// دریافت قیمت از سایت مرجع
// =============================================================================
function biz_price_http_json($url, $key = '', $timeout = 25)
{
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($key !== '') $h[] = 'Authorization: Bearer ' . $key;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $h, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PriceSync/1.0)']);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') return ['ok' => false, 'error' => 'اتصال برقرار نشد: ' . $err];
    $j = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($j)) return ['ok' => false, 'error' => 'پاسخ نامعتبر (HTTP ' . $code . ')'];
    return ['ok' => true, 'json' => $j];
}

function biz_price_cache_file($name)
{
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/');
    return $dir . '/.price_' . preg_replace('/[^a-z0-9_]/', '', $name) . '.json';
}

/** شناسه ساده‌شده مدل برای تطبیق بین سرویس‌دهنده‌ها */
function biz_price_norm($id)
{
    $id = strtolower(trim((string)$id));
    $id = preg_replace('/:(free|beta|extended|nitro|floor|online|thinking)$/', '', $id);
    if (strpos($id, '/') !== false) $id = substr($id, strrpos($id, '/') + 1);
    $id = preg_replace('/-(\d{4}-\d{2}-\d{2}|\d{8}|\d{4}|latest)$/', '', $id);
    $id = str_replace(['_', ' '], '-', $id);
    return $id;
}

/**
 * فهرست قیمت OpenRouter (عمومی، بدون نیاز به کلید) — ذخیره موقت ۱۲ ساعته
 * @return array ['ok', 'items' => [id => ['in','out','img','mod']], 'error']
 */
function biz_price_openrouter_catalog($pdo, $force = false)
{
    static $mem = null;
    if ($mem !== null && !$force) return $mem;
    $file = biz_price_cache_file('openrouter3');   // نسخه ۶۴: همه مدل‌ها (تصویر/ویدیو هم) + قیمت هر تصویر — فهرست قدیمی دوباره گرفته می‌شود
    if (!$force && is_file($file) && filemtime($file) > time() - BIZ_PRICE_CACHE_HOURS * 3600) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && !empty($c['items'])) return $mem = ['ok' => true, 'items' => $c['items'], 'error' => ''];
    }
    $cfg = saas_get_api_config($pdo);
    // نسخه ۶۳: اول از همان آدرس و کلیدی که چت با OpenRouter از آن انجام می‌شود (تنظیمات API؛ اگر از پراکسی/آدرس واسط استفاده شود، آدرس عمومی openrouter.ai
    // ممکن است از هاست در دسترس نباشد)، بعد آدرس عمومی
    $urls = [];
    if (defined('BIZ_PRICE_OR_URL')) $urls[] = [BIZ_PRICE_OR_URL, ''];
    else {
        $cb = rtrim((string)($cfg['openrouter_api_base'] ?? ''), '/');
        $key = trim((string)($cfg['openrouter_api_key'] ?? ''));
        if ($cb !== '' && preg_match('#^https?://#i', $cb)) $urls[] = [$cb . '/models', $key];
        if (!$urls || stripos($cb, 'openrouter.ai/api/v1') === false || $key !== '') $urls[] = ['https://openrouter.ai/api/v1/models', ''];
    }
    $r = ['ok' => false, 'error' => 'آدرسی برای دریافت فهرست نیست'];
    $errs = [];
    // نسخه ۶۴: OpenRouter به‌طور پیش‌فرض فقط مدل‌های «خروجی متن» را برمی‌گرداند (و حداکثر ۵۰۰ مدل)؛
    // مدل‌های تصویر/ویدیو/صوت فقط با output_modalities=all می‌آیند → همه صفحه‌ها گرفته می‌شود
    $page = defined('BIZ_PRICE_OR_PAGE') ? max(1, (int)BIZ_PRICE_OR_PAGE) : 1000;
    foreach ($urls as $u) {
        $all = []; $ok = false;
        for ($off = 0, $n = 0; $n < 10; $n++, $off += $page) {
            $url = $u[0] . (strpos($u[0], '?') === false ? '?' : '&') . 'output_modalities=all&limit=' . $page . '&offset=' . $off;
            $rr = biz_price_http_json($url, $u[1], 40);
            if (!$rr['ok'] || !isset($rr['json']['data']) || !is_array($rr['json']['data'])) {
                if ($n === 0) { $r = $rr; break; }
                break;   // صفحه‌های بعدی: همان که گرفته شد
            }
            $ok = true;
            $before = count($all);
            foreach ($rr['json']['data'] as $it) if (!empty($it['id'])) $all[(string)$it['id']] = $it;
            if (count($rr['json']['data']) < $page || count($all) === $before) break;   // صفحه آخر (یا سرور صفحه‌بندی را نادیده می‌گیرد)
        }
        if ($ok && $all) { $r = ['ok' => true, 'json' => ['data' => array_values($all)]]; break; }
        $errs[] = $u[0] . ' → ' . ($r['error'] ?? 'پاسخ خالی');
        $r['ok'] = false;
    }
    if ($errs) $r['error'] = implode(' | ', array_unique($errs));
    @file_put_contents(biz_price_cache_file('openrouter_meta'), json_encode(['at' => time(), 'ok' => !empty($r['ok']), 'error' => $r['ok'] ? '' : (string)($r['error'] ?? ''), 'total' => $r['ok'] ? count($r['json']['data']) : 0], JSON_UNESCAPED_UNICODE));
    if (!$r['ok'] || empty($r['json']['data']) || !is_array($r['json']['data'])) {
        // نسخه قدیمی ذخیره‌شده بهتر از هیچ است
        $c = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (is_array($c) && !empty($c['items'])) return $mem = ['ok' => true, 'items' => $c['items'], 'error' => '', 'stale' => true];
        return $mem = ['ok' => false, 'items' => [], 'error' => 'فهرست قیمت OpenRouter دریافت نشد: ' . ($r['error'] ?? 'پاسخ خالی')];
    }
    $items = [];
    foreach ($r['json']['data'] as $it) {
        if (empty($it['id']) || !isset($it['pricing'])) continue;
        $p = $it['pricing'];
        $in = (float)($p['prompt'] ?? 0) * 1000000;
        $out = (float)($p['completion'] ?? 0) * 1000000;
        if ($in < 0 || $out < 0) continue;   // قیمت متغیر (−۱)
        $items[(string)$it['id']] = [
            'in' => round($in, 6), 'out' => round($out, 6),
            // image_output در OpenRouter قیمت «هر توکن تصویر خروجی» است (مثلاً 0.00003 = ۳۰ دلار برای ۱ میلیون توکن)، نه قیمت هر تصویر؛
            // completion قیمت توکن «متن» خروجی است. image قیمت هر تصویر ورودی است و در محاسبه ما استفاده نمی‌شود.
            // نسخه ۶۴: image_output کوچک‌تر از ۰٫۰۰۱ = قیمت هر توکن تصویر؛ بزرگ‌تر = قیمت هر تصویر (مثل Recraft: 0.007 دلار)
            'img_out' => round(((float)($p['image_output'] ?? 0) > 0 && (float)$p['image_output'] < 0.001 ? (float)$p['image_output'] : max(0, (float)($p['image_token'] ?? 0))) * 1000000, 6),
            'img_unit' => (float)($p['image_output'] ?? 0) >= 0.001 ? round((float)$p['image_output'], 6) : 0,
            'req' => round(max(0, (float)($p['request'] ?? 0)), 6),
            'mod' => (string)($it['architecture']['modality'] ?? ''),
        ];
    }
    if ($items) @file_put_contents($file, json_encode(['at' => time(), 'items' => $items]));
    // نسخه ۷۲: مشخصات مدل‌ها (کارایی، تاریخ حذف، طول متن، ورودی/خروجی) از همین فهرست — برای صفحه مدل‌ها
    $meta = [];
    $keep = ['tools', 'reasoning', 'structured_outputs', 'response_format', 'web_search_options'];
    foreach ($r['json']['data'] as $it) {
        if (empty($it['id']) || !is_array($it)) continue;
        $meta[(string)$it['id']] = [
            'name' => mb_substr((string)($it['name'] ?? ''), 0, 120),
            'desc' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($it['description'] ?? ''))), 0, 3000),
            'exp' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($it['expiration_date'] ?? ''), $em) ? $em[0] : '',
            'ctx' => (int)($it['context_length'] ?? ($it['top_provider']['context_length'] ?? 0)),
            'in' => array_values(array_map('strval', (array)($it['architecture']['input_modalities'] ?? []))),
            'out' => array_values(array_map('strval', (array)($it['architecture']['output_modalities'] ?? []))),
            'sp' => array_values(array_intersect($keep, array_map('strval', (array)($it['supported_parameters'] ?? [])))),
        ];
    }
    if ($meta) @file_put_contents(biz_price_cache_file('ormeta2'), json_encode(['at' => time(), 'items' => $meta], JSON_UNESCAPED_UNICODE));
    return $mem = ['ok' => (bool)$items, 'items' => $items, 'error' => $items ? '' : 'فهرست OpenRouter خالی بود.'];
}

/**
 * فهرست قیمت AvalAI (نسخه ۵۴) — قیمت خودِ AvalAI برای مدل‌هایی که از آن گرفته می‌شوند
 * منبع: /v1/models با کلید API (و در صورت تنظیم، آدرس اختصاصی فهرست قیمت در «تنظیمات قیمت»)
 * ذخیره موقت ۲۴ ساعته؛ پاسخ خام هم برای «بررسی فهرست قیمت» نگه داشته می‌شود
 * @return array ['ok', 'items' => [id => ['in','out','img_in','unit','cur']], 'total', 'error']
 */
function biz_price_avalai_catalog($pdo, $force = false)
{
    static $mem = null;
    if ($mem !== null && !$force) return $mem;
    $file = biz_price_cache_file('avalai2');
    if (!$force && is_file($file) && filemtime($file) > time() - BIZ_PRICE_CACHE_HOURS * 3600) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['items'])) return $mem = ['ok' => true, 'items' => $c['items'], 'noprice' => $c['noprice'] ?? [], 'total' => (int)($c['total'] ?? 0), 'error' => ''];
    }
    $cfg = saas_get_api_config($pdo);
    $key = (string)($cfg['avalai_api_key'] ?? '');
    $urls = [];
    $custom = trim((string)(biz_settings($pdo)['avalai_price_url'] ?? ''));
    if ($custom !== '' && preg_match('#^https?://#i', $custom)) $urls[] = $custom;
    if ($key !== '') $urls[] = rtrim($cfg['avalai_api_base'] ?: 'https://api.avalai.ir/v1', '/') . '/models';
    if (!$urls) return $mem = ['ok' => false, 'items' => [], 'total' => 0, 'error' => 'کلید AvalAI در «تنظیمات API» وارد نشده است.'];
    $items = []; $total = 0; $errs = []; $raw = []; $noprice = [];
    foreach ($urls as $u) {
        $r = biz_price_http_json($u, $key, 40);
        if (!$r['ok']) { $errs[] = $r['error']; continue; }
        $list = $r['json']['data'] ?? ($r['json']['models'] ?? $r['json']);
        if (!is_array($list)) continue;
        foreach ($list as $k => $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? ($it['model'] ?? ($it['name'] ?? (is_string($k) ? $k : ''))));
            if ($id === '') continue;
            $total++;
            if (count($raw) < 3000) $raw[$id] = $it;
            if (isset($items[$id])) continue;
            $pr = biz_price_extract($it);
            if ($pr) { $items[$id] = $pr; unset($noprice[$id]); } else $noprice[$id] = 1;
        }
    }
    if (!$total) {
        $c = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;   // نسخه قبلی بهتر از هیچ
        if (is_array($c) && !empty($c['items'])) return $mem = ['ok' => true, 'items' => $c['items'], 'noprice' => $c['noprice'] ?? [], 'total' => (int)($c['total'] ?? 0), 'error' => '', 'stale' => true];
        return $mem = ['ok' => false, 'items' => [], 'total' => 0, 'error' => 'فهرست AvalAI دریافت نشد: ' . implode(' | ', $errs)];
    }
    $noprice = array_keys($noprice);
    @file_put_contents($file, json_encode(['at' => time(), 'total' => $total, 'items' => $items, 'noprice' => $noprice]));
    @file_put_contents(biz_price_cache_file('avalai_raw'), json_encode(['at' => time(), 'items' => $raw], JSON_UNESCAPED_UNICODE));
    return $mem = ['ok' => true, 'items' => $items, 'noprice' => $noprice, 'total' => $total, 'error' => ''];
}

/**
 * پیدا کردن قیمت در ساختارهای مختلف پاسخ سرویس‌دهنده (هر سطح و هر نام‌گذاری رایج)
 * قیمت توکنی: دلار برای هر ۱ میلیون توکن (مقدار خیلی کوچک = به ازای هر توکن → تبدیل می‌شود)
 * @return array|null ['in','out','img_in','unit','cur' => usd|toman|rial]
 */
function biz_price_extract(array $it)
{
    $src = null;
    foreach (['pricing', 'price', 'prices', 'cost', 'costs', 'rates'] as $k) if (isset($it[$k]) && is_array($it[$k])) { $src = $it[$k]; break; }
    if ($src === null) $src = $it;
    $flat = [];
    $walk = function ($a, $pre, $d) use (&$walk, &$flat) {
        if ($d > 3) return;
        foreach ($a as $k => $v) {
            $key = strtolower($pre === '' ? (string)$k : $pre . '.' . $k);
            if (is_array($v)) { $walk($v, $key, $d + 1); continue; }
            if (is_bool($v) || $v === null) continue;
            $s = trim((string)$v);
            if ($s === '') continue;
            if (preg_match('/^\$?\s*([0-9]+(?:\.[0-9]+)?(?:e-?[0-9]+)?)\s*$/i', str_replace([',', ' '], '', $s), $m)) $flat[$key] = (float)$m[1];
            elseif (preg_match('/currency|unit_name|unit$/', $key)) $flat[$key] = strtolower($s);
        }
    };
    $walk($src, '', 0);
    if (!$flat) return null;
    $find = function (array $yes, array $no = []) use ($flat) {
        foreach ($yes as $re) foreach ($flat as $k => $v) {
            if (!is_float($v) || $v < 0 || !preg_match($re, $k)) continue;
            foreach ($no as $nre) if (preg_match($nre, $k)) continue 2;
            return $v;
        }
        return null;
    };
    $img_in = $find(['/(image|img)[._]?(input|in|prompt)\b/', '/(input|prompt)[._]?(image|img)/']);
    $img_out = $find(['/(image|img)[._]?(output|out|completion|generation)\b/', '/(output|completion)[._]?(image|img)/']);
    $in = $find(['/(^|\.)(input|prompt|in|input_price|input_cost|prompt_price|input_per_million|input_per_1m|prompt_per_million)$/', '/(text[._])?(input|prompt)/'], ['/image|img|audio|cache|cached|reason|batch|tier/']);
    $out = $find(['/(^|\.)(output|completion|out|output_price|output_cost|completion_price|output_per_million|output_per_1m|completion_per_million)$/', '/(text[._])?(output|completion)/'], ['/image|img|audio|reason|batch|tier/']);
    $unit = $find(['/per[._]?(image|second|sec|minute|min|request|call|generation)/', '/(^|\.)(image|unit|request|per_unit|unit_price|second|minute)$/', '/image[._]?price/']);
    $cur = 'usd';
    foreach (['currency', 'pricing.currency'] as $ck) if (isset($it[$ck]) && is_string($it[$ck])) $flat['currency'] = strtolower($it[$ck]);
    foreach ($flat as $k => $v) if (is_string($v) && preg_match('/currency/', $k)) {
        if (preg_match('/irr|rial|ریال/u', $v)) $cur = 'rial';
        elseif (preg_match('/irt|toman|تومان/u', $v)) $cur = 'toman';
    }
    if ($img_out !== null && $img_out > 0) $out = $img_out;   // مدل تصویری: خروجی = توکن تصویر
    if ($in === null && $out === null && $unit === null && $img_in === null) return null;
    $norm = fn($v) => $v === null ? 0.0 : ($v > 0 && $v < 0.001 ? $v * 1000000 : (float)$v);
    $r = ['in' => $norm($in), 'out' => $norm($out), 'img_in' => $norm($img_in), 'unit' => (float)($unit ?? 0), 'cur' => $cur];
    // بدون واحد پول صریح: عددهای خیلی بزرگ قطعاً تومان‌اند (گران‌ترین مدل‌ها زیر ۲۰۰۰ دلار برای ۱ میلیون توکن و زیر ۵۰ دلار برای هر واحد)
    if ($cur === 'usd' && (max($r['in'], $r['out'], $r['img_in']) > 2000 || $r['unit'] > 50)) $r['cur'] = 'toman';
    return $r;
}

/** تبدیل قیمت ریالی/تومانی سرویس‌دهنده به دلار (برای یکسانی محاسبه با نرخ دلار و درصد سود) */
function biz_price_to_usd($pdo, array $r)
{
    if (($r['cur'] ?? 'usd') === 'usd') return $r;
    $rate = biz_price_rate($pdo);
    if ($rate <= 0) return null;
    $div = ($r['cur'] === 'rial' ? 10 : 1) * $rate;
    foreach (['in', 'out', 'img_in', 'unit'] as $k) $r[$k] = round((float)$r[$k] / $div, 8);
    $r['cur'] = 'usd';
    return $r;
}

/**
 * یافتن قیمت یک مدل (نسخه ۵۵)
 *  ۱) اول از همان سرویس‌دهنده‌ای که مدل از آن گرفته می‌شود (AvalAI → فهرست AvalAI ، OpenRouter → فهرست OpenRouter)
 *  ۲) اگر آن سرویس‌دهنده قیمت نداشت، مدل را نداشت یا فهرستش دریافت نشد → جایگزین:
 *       مدل‌های تصویری: قیمت رسمی سازنده (توکنی) ← OpenRouter
 *       بقیه: OpenRouter ← قیمت رسمی سازنده (صوت، تصویر، ویدیو)
 * @return array ['ok', 'in', 'out', 'img_in', 'unit', 'src', 'ref', 'approx', 'fallback', 'error']
 */
function biz_price_lookup($pdo, $provider, $model_id, $cat = 'text', $force = false)
{
    $model_id = trim((string)$model_id);
    $norm = biz_price_norm($model_id);
    $tokens = biz_price_is_token_cat($cat);
    $none = ['ok' => false, 'in' => 0, 'out' => 0, 'img_in' => 0, 'unit' => 0, 'src' => '', 'ref' => '', 'approx' => false, 'fallback' => false, 'error' => ''];
    $res = function ($in, $out, $img, $unit, $src, $ref, $approx = false, $err = '') {
        return ['ok' => true, 'in' => (float)$in, 'out' => (float)$out, 'img_in' => (float)$img, 'unit' => (float)$unit, 'src' => $src, 'ref' => $ref, 'approx' => $approx, 'fallback' => false, 'error' => $err];
    };
    // --- AvalAI ---
    $from_avalai = function (&$why) use ($pdo, $model_id, $norm, $tokens, $cat, $force, $res) {
        $av = biz_price_avalai_catalog($pdo, $force);
        if (!$av['ok']) { $why = $av['error'] ?: 'فهرست قیمت AvalAI دریافت نشد'; return null; }
        $row = null; $ref = '';
        if (isset($av['items'][$model_id])) { $row = $av['items'][$model_id]; $ref = $model_id; }
        else foreach ($av['items'] as $id => $x) if (biz_price_norm($id) === $norm) { $row = $x; $ref = $id; break; }
        if ($row) $row = biz_price_to_usd($pdo, $row);
        $ok = $row && ($tokens ? ($row['in'] > 0 || $row['out'] > 0) : ($cat === 'image' ? ($row['out'] > 0 || $row['unit'] > 0) : $row['unit'] > 0));
        if ($ok) {
            $unit = $row['unit'];
            if ($cat === 'image' && $row['out'] > 0 && $unit <= 0) $unit = round(($row['in'] * 300 + $row['out'] * biz_price_image_typical_out($model_id)) / 1000000, 6);
            return (!$tokens && $cat !== 'image') ? $res(0, 0, 0, $unit, 'AvalAI', $ref) : $res($row['in'], $row['out'], $tokens ? 0 : ($row['img_in'] ?? 0), $tokens ? 0 : $unit, 'AvalAI', $ref);
        }
        $listed = (bool)$row || in_array($model_id, (array)($av['noprice'] ?? []), true);
        if (!$listed) foreach ((array)($av['noprice'] ?? []) as $id) if (biz_price_norm($id) === $norm) { $listed = true; break; }
        $why = $listed ? 'AvalAI قیمت این مدل را اعلام نکرده' : 'این مدل در فهرست AvalAI نیست';
        return null;
    };
    // --- OpenRouter --- ($strict: فقط قیمت غیرصفر — قیمت «رایگان» OpenRouter جایگزین قیمت AvalAI نمی‌شود)
    $from_or = function (&$why, $strict) use ($pdo, $model_id, $norm, $tokens, $cat, $force, $res) {
        $or = biz_price_openrouter_catalog($pdo, $force);
        if (!$or['ok']) { $why = $or['error'] ?: 'فهرست قیمت OpenRouter دریافت نشد'; return null; }
        $hit = null; $ref = '';
        if (isset($or['items'][$model_id])) { $hit = $or['items'][$model_id]; $ref = $model_id; }
        if (!$hit) {   // نسخه ۶۳: بدون حساسیت به حروف بزرگ/کوچک و فاصله، و بدون پسوند «:free / :beta …»
            $lk = strtolower(preg_replace('/\s+/', '', $model_id));
            $base_id = preg_replace('/:[a-z]+$/', '', $lk);
            foreach ($or['items'] as $id => $row) { $li = strtolower($id); if ($li === $lk || ($li === $base_id && strpos($li, ':free') === false)) { $hit = $row; $ref = $id; break; } }
        }
        if (!$hit) {
            $pref = ['openai', 'anthropic', 'google', 'x-ai', 'deepseek', 'meta-llama', 'mistralai', 'qwen', 'moonshotai', 'z-ai'];
            $best = 99;
            foreach ($or['items'] as $id => $row) {
                if (biz_price_norm($id) !== $norm || strpos($id, ':free') !== false) continue;
                $vendor = strpos($id, '/') !== false ? substr($id, 0, strpos($id, '/')) : '';
                $rank = ($i = array_search($vendor, $pref, true)) === false ? 50 : $i;
                if ($rank < $best) { $best = $rank; $hit = $row; $ref = $id; }
            }
        }
        if (!$hit) { $why = 'این مدل در فهرست OpenRouter نیست'; return null; }
        if ($tokens) {
            if ($hit['in'] <= 0 && $hit['out'] <= 0) {
                if ($strict) { $why = 'OpenRouter این مدل را رایگان اعلام کرده'; return null; }
                return $res(0, 0, 0, 0, 'OpenRouter', $ref, false, 'این مدل در منبع رایگان است.');
            }
            return $res($hit['in'], $hit['out'], 0, 0, 'OpenRouter', $ref);
        }
        // مدل با قیمت ثابت هر تصویر یا هر درخواست (نسخه ۶۴)
        if (in_array($cat, ['image', 'video', 'tts', 'stt'], true) && (($u1 = (float)($hit['img_unit'] ?? 0)) > 0 || ($u1 = (float)($hit['req'] ?? 0)) > 0) && (float)($hit['img_out'] ?? 0) <= 0) {
            return $res(0, 0, 0, round($u1, 6), 'OpenRouter', $ref);
        }
        if ($cat === 'image') {
            $o = (float)($hit['img_out'] ?? 0);
            if ($o <= 0) {
                // OpenRouter قیمت توکن تصویر خروجی را جدا اعلام نکرده؛ قیمت توکن متنی برای تصویر درست نیست (مثلاً ۲٫۵ به‌جای ۳۰ دلار)
                if (isset(biz_price_image_tokens()[$norm])) { $why = 'OpenRouter قیمت توکن تصویر خروجی را اعلام نکرده'; return null; }
                $o = (float)$hit['out'];
            }
            if ($o > 0) {
                $u = ($o * biz_price_image_typical_out($model_id) + $hit['in'] * 300) / 1000000;
                return $res($hit['in'], $o, 0, round($u, 6), 'OpenRouter', $ref);
            }
        }
        $why = 'OpenRouter برای این نوع مدل قیمت واحد ندارد';
        return null;
    };
    // --- ارائه‌دهنده اصلی (قیمت رسمی سازنده) ---
    $from_official = function (&$why) use ($norm, $tokens, $cat, $res) {
        if ($cat === 'image' && isset(biz_price_image_tokens()[$norm])) {
            [$ti, $tm, $to] = biz_price_image_tokens()[$norm];
            return $res($ti, $to, $tm, round(($ti * 300 + $to * biz_price_image_typical_out($norm)) / 1000000, 6), 'قیمت رسمی سازنده', $norm);
        }
        $tbl = biz_price_table()[$cat] ?? [];
        if (!$tokens && isset($tbl[$norm])) return $res(0, 0, 0, $tbl[$norm], 'قیمت رسمی سازنده', $norm, $cat === 'tts' && $norm === 'gpt-4o-mini-tts');
        $why = 'قیمت رسمی سازنده در جدول نیست';
        return null;
    };

    $w1 = '';
    $first = $provider === 'openrouter' ? $from_or($w1, false) : $from_avalai($w1);
    if ($first) return $first;
    $pname = $provider === 'openrouter' ? 'OpenRouter' : 'AvalAI';
    // نسخه ۶۳: مدل OpenRouter اگر در فهرست OpenRouter پیدا نشد یا فهرست دریافت نشد → قیمت همان مدل در AvalAI، بعد قیمت رسمی سازنده
    $chain = $provider === 'openrouter' ? ['avalai', 'official'] : ($cat === 'image' ? ['official', 'or'] : ['or', 'official']);
    $whys = [$pname . ': ' . $w1];
    foreach ($chain as $c) {
        $w = '';
        $r = $c === 'or' ? $from_or($w, true) : ($c === 'avalai' ? $from_avalai($w) : $from_official($w));
        if ($r) {
            $r['fallback'] = true;
            $r['src'] .= ' (جایگزین؛ ' . $pname . ': ' . $w1 . ')';
            return $r;
        }
        $whys[] = ($c === 'or' ? 'OpenRouter' : ($c === 'avalai' ? 'AvalAI' : 'سازنده')) . ': ' . $w;
    }
    $none['error'] = 'قیمت پیدا نشد (' . implode(' — ', $whys) . '). قیمت را دستی وارد کنید.';
    return $none;
}

/**
 * به‌روزرسانی قیمت یک مدل (اول سرویس‌دهنده خودش، در صورت نبود: OpenRouter یا ارائه‌دهنده اصلی) — فقط مدل‌های «خودکار»
 * @return array ['ok', 'msg']
 */
function biz_model_refresh_price($pdo, $m, $force = false)
{
    if (!$m || ($m['price_src'] ?? 'auto') !== 'auto') return ['ok' => true, 'msg' => 'قیمت این مدل دستی است.'];
    $r = biz_price_lookup($pdo, $m['provider'], $m['model_id'], $m['category'] ?? 'text', $force);
    try {
        biz_price_schema($pdo);
        if ($r['ok'] && ($r['in'] > 0 || $r['out'] > 0 || $r['unit'] > 0)) {
            $note = $r['src'] . ($r['ref'] !== '' && $r['ref'] !== $m['model_id'] && empty($r['fallback']) ? ' (' . $r['ref'] . ')' : '') . ($r['approx'] ? ' — تقریبی' : '');
            $pdo->prepare("UPDATE saas_ai_models SET usd_in=?, usd_out=?, usd_unit=?, usd_img_in=?, price_ref=?, price_note=?, price_at=? WHERE id=?")
                ->execute([$r['in'], $r['out'], $r['unit'], (float)($r['img_in'] ?? 0), mb_substr($r['ref'], 0, 150), mb_substr($note, 0, 250), biz_now(), (int)$m['id']]);
            // قیمت تصویر عوض شد → میانگین هزینه قبلی (برای برآورد پیش از ساخت) کنار گذاشته شود
            if (($m['category'] ?? '') === 'image' && (abs((float)$m['usd_out'] - $r['out']) > 1e-9 || abs((float)$m['usd_in'] - $r['in']) > 1e-9 || abs((float)$m['usd_unit'] - $r['unit']) > 1e-9) && function_exists('biz_set'))
                biz_set($pdo, 'img_avg_' . (int)$m['id'], 0);
            return ['ok' => true, 'msg' => 'قیمت از ' . $note . ' دریافت شد.'];
        }
        $err = $r['error'] !== '' ? $r['error'] : 'قیمت پیدا نشد.';
        $pdo->prepare("UPDATE saas_ai_models SET price_note=?, price_at=? WHERE id=?")->execute(['⚠ ' . mb_substr($err, 0, 245), biz_now(), (int)$m['id']]);
        return ['ok' => false, 'msg' => $err];
    } catch (\Throwable $e) {
        error_log('[PRICE] ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'ذخیره قیمت انجام نشد.'];
    }
}

/** به‌روزرسانی قیمت همه مدل‌های خودکار (دکمه پنل و زمان‌بندی روزانه) — هر مدل از سرویس‌دهنده خودش */
function biz_models_refresh_prices($pdo, $force = false)
{
    $ok = 0; $fail = 0;
    // فقط فهرست سرویس‌دهنده‌هایی گرفته می‌شود که مدلی از آن‌ها استفاده می‌شود
    $used = [];
    foreach (biz_models($pdo, false, null, true) as $m) if (($m['price_src'] ?? 'auto') === 'auto') $used[$m['provider'] === 'openrouter' ? 'openrouter' : 'avalai'] = 1;
    if ($force && isset($used['openrouter'])) biz_price_openrouter_catalog($pdo, true);
    if ($force && isset($used['avalai'])) biz_price_avalai_catalog($pdo, true);
    foreach (biz_models($pdo, false, null, true) as $m) {
        if (($m['price_src'] ?? 'auto') !== 'auto') continue;
        $r = biz_model_refresh_price($pdo, $m);
        $r['ok'] ? $ok++ : $fail++;
    }
    if (function_exists('mcap_lim_fetch')) { try { mcap_lim_fetch($pdo, $force); } catch (\Throwable $e) {} }   // نسخه ۷۵: محدودیت درخواست ارائه‌دهنده‌ها
    // نسخه ۷۲: تاریخ حذف و مشخصات همه مدل‌های اصلی تازه شود؛ کارایی فقط برای مدل‌هایی که هنوز ندارند (حداکثر ۱۵ مدل در هر بار)
    if (function_exists('mcap_refresh')) {
        $ai_left = 15;
        foreach (biz_models($pdo, false, null, true) as $m) {
            if ((int)($m['parent_id'] ?? 0) > 0) continue;
            $need = mcap_needs($m) && $ai_left > 0;
            if ($need) $ai_left--;
            try { mcap_refresh($pdo, $m, $need); } catch (\Throwable $e) { error_log('[MCAP] ' . $e->getMessage()); }
        }
    }
    biz_set($pdo, 'price_updated', biz_now());
    return ['ok' => $ok, 'fail' => $fail];
}

/**
 * نسخه ۷۶: مرتب‌سازی مدل‌ها از ارزان‌ترین به گران‌ترین (برای فهرست‌هایی که به کاربر و مخاطب نشان داده می‌شود)
 *  متنی: قیمت ترکیبی هر ۱۰۰۰ توکن؛ تصویر: قیمت هر تصویر؛ ویدیو: هر ثانیه؛ صوت: هر واحد — قیمت برابر = همان ترتیب قبلی (اولویت مدیر)
 */
function biz_models_by_price($pdo, array $models)
{
    if (count($models) < 2) return array_values($models);
    $rows = [];
    $i = 0;
    foreach ($models as $m) {
        $p = biz_model_toman_prices($pdo, $m);
        $v = (float)($p['per1k'] ?? 0) > 0 ? (float)$p['per1k'] * 1000 : (float)($p['unit'] ?? 0);
        $rows[] = [$v, $i++, $m];
    }
    usort($rows, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return array_column($rows, 2);
}

/** متن قیمت نهایی برای نمایش در پنل مدیریت */
function biz_model_price_html($pdo, $m)
{
    $p = biz_model_toman_prices($pdo, $m);
    $u = biz_price_units()[$m['category'] ?? 'text'] ?? biz_price_units()['text'];
    $rate = biz_price_rate($pdo);
    if ($u['kind'] === 'tokens') {
        $usd = biz_model_has_price($m) ? '$' . rtrim(rtrim(number_format((float)$m['usd_in'], 4), '0'), '.') . ' / $' . rtrim(rtrim(number_format((float)$m['usd_out'], 4), '0'), '.') : '';
        return ['usd' => $usd, 'toman' => 'ورودی ' . biz_toman_fmt($p['in']) . ' — خروجی ' . biz_toman_fmt($p['out']), 'unit' => $u['label'], 'per1k' => $p['per1k'], 'priced' => $p['priced'], 'rate' => $rate];
    }
    $f4 = fn($v) => rtrim(rtrim(number_format((float)$v, 4), '0'), '.');
    if (!empty($p['tokens'])) {
        $usd = '$' . $f4($m['usd_in']) . ' / $' . $f4((float)($m['usd_img_in'] ?? 0) > 0 ? $m['usd_img_in'] : $m['usd_in']) . ' / $' . $f4($m['usd_out']) . ' (۱م توکن)';
        return ['usd' => $usd, 'toman' => 'بر اساس توکن مصرفی — هر تصویر حدود ' . biz_toman_fmt($p['unit']), 'unit' => 'ورودی متن / ورودی تصویر / خروجی', 'per1k' => 0, 'priced' => true, 'rate' => $rate];
    }
    $usd = biz_model_has_price($m) ? '$' . $f4($m['usd_unit']) : '';
    return ['usd' => $usd, 'toman' => biz_toman_fmt($p['unit']), 'unit' => $u['label'], 'per1k' => 0, 'priced' => $p['priced'], 'rate' => $rate];
}

/** آیا سرویس‌دهنده این مدل در «تنظیمات API» فعال است؟ */
function biz_provider_enabled($pdo, $provider)
{
    $cfg = saas_get_api_config($pdo);
    return $provider === 'openrouter' ? !empty($cfg['openrouter_enabled']) : !empty($cfg['avalai_enabled']);
}

/**
 * هشدار شناسه مدل (نسخه ۶۷): قیمت از منبع جایگزین آمده چون این شناسه در فهرست همان سرویس‌دهنده نیست
 * → به احتمال زیاد درخواست‌ها هم با خطای «مدل وجود ندارد» برمی‌گردند (مثال: muse-image در AvalAI؛ در OpenRouter = meta/muse-image)
 * @param array $m ردیف مدل (provider, model_id, price_note, price_ref) یا نتیجه biz_price_lookup + provider/model_id
 */
function biz_model_id_warning($m)
{
    $note = (string)($m['price_note'] ?? ($m['src'] ?? ''));
    $prov = ($m['provider'] ?? '') === 'openrouter' ? 'OpenRouter' : 'AvalAI';
    if (strpos($note, 'این مدل در فهرست ' . $prov . ' نیست') === false) return '';
    $w = 'شناسه «' . ($m['model_id'] ?? '') . '» در فهرست مدل‌های ' . $prov . ' نیست؛ احتمالاً ' . $prov . ' این مدل را با این شناسه ندارد و ساخت/پاسخ خطا می‌دهد.';
    $ref = (string)($m['price_ref'] ?? ($m['ref'] ?? ''));
    if ($prov === 'AvalAI' && strpos($note, 'OpenRouter') === 0 && strpos($ref, '/') !== false)
        $w .= ' این مدل در OpenRouter با شناسه «' . $ref . '» هست؛ سرویس‌دهنده را OpenRouter و شناسه را «' . $ref . '» بگذارید (یا شناسه درست را از سایت AvalAI کپی کنید).';
    else $w .= ' شناسه دقیق را از سایت ' . $prov . ' کپی کنید.';
    return $w;
}

/** توضیح فارسی کوتاه برای خطاهای رایج سرویس‌دهنده (برای مدیر) */
function biz_provider_error_hint($err)
{
    $e = (string)$err;
    // نسخه ۶۹: مدل‌هایی که تأیید یک تنظیم در حساب سرویس‌دهنده می‌خواهند (مثل تأیید سن ۱۸+ یا سیاست داده در OpenRouter) — کلید سالم است
    if (preg_match('/age confirmation|18\+|requires you to complete|data policy|settings\/(preferences|privacy)/i', $e)) return 'این مدل پیش از استفاده نیاز به تأیید یک تنظیم در حساب سرویس‌دهنده دارد (مثلاً تأیید سن ۱۸+ یا سیاست داده در صفحه Settings → Preferences/Privacy حساب OpenRouter). کلید API مشکلی ندارد؛ بعد از تأیید، «تست ساخت تصویر» را بزنید. ';
    if (preg_match('/does not exist|model[_ ]not[_ ]found|no such model|unknown model|is not a valid model|invalid model/i', $e)) return 'شناسه مدل در این سرویس‌دهنده وجود ندارد (شناسه یا سرویس‌دهنده را اصلاح کنید). ';
    if (preg_match('/No endpoints found/i', $e)) return 'این مدل از این روش ساخت پشتیبانی نمی‌کند یا در دسترس نیست. ';
    if (preg_match('/HTTP 401|HTTP 403|invalid api key|unauthori/i', $e)) return 'کلید API نامعتبر است یا دسترسی ندارد. ';
    if (preg_match('/HTTP 402|insufficient|credit|balance/i', $e)) return 'اعتبار حساب سرویس‌دهنده کافی نیست. ';
    if (preg_match('/HTTP 429|rate limit/i', $e)) return 'محدودیت تعداد درخواست سرویس‌دهنده؛ کمی بعد دوباره. ';
    return '';
}
