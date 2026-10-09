<?php
/**
 * ساخت و ویرایش تصویر/ویدیو به سبک گفتگو (نسخه ۳۵) — مشترک بین «استودیو» پنل کاربر و چت‌بات تخصصی
 *
 *  - مترجم ارزان: درخواست فارسی پیش از ارسال به مدل تصویر/ویدیو با یک مدل متنی ارزان به پرامپت دقیق انگلیسی
 *    تبدیل می‌شود (مدل در «مدل‌ها و قیمت‌ها»ی مدیر قابل انتخاب است؛ پیش‌فرض ارزان‌ترین مدل متنی فعال).
 *    همان درخواست تشخیص می‌دهد: ویرایش تصویر قبلی است یا طرح تازه، و ابعاد (مربع/افقی/عمودی).
 *  - ویرایش: تصویر قبلی / ریپلای‌شده / پیوست‌شده به‌عنوان تصویر مرجع به مدل داده می‌شود.
 *  - توکن واقعی (ترجمه + ساخت، اگر سرویس گزارش دهد) و حجم فایل ذخیره می‌شود.
 */

if (!defined('GEN_SCHEMA_VERSION')) define('GEN_SCHEMA_VERSION', 1);

function gen_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.gen_schema_v' . GEN_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $has = function ($t) use ($pdo) { try { return $pdo->query("SELECT 1 FROM `$t` LIMIT 1") !== false; } catch (\Throwable $e) { return false; } };
    $ok = true;
    $col = function ($t, $c, $d) use ($pdo, &$ok) {
        try { saas_add_column_if_missing($pdo, $t, $c, $d); } catch (\Throwable $e) { $ok = false; error_log('[GEN] column ' . $t . '.' . $c . ': ' . $e->getMessage()); }
    };
    if ($has('saas_media')) {
        foreach ([['bytes', 'INT NOT NULL DEFAULT 0'], ['tokens', 'INT NOT NULL DEFAULT 0'], ['tr_tokens', 'INT NOT NULL DEFAULT 0'], ['final_prompt', 'TEXT NULL'],
                  ['user_text', 'TEXT NULL'], ['ref_id', 'INT NOT NULL DEFAULT 0'], ['ref_url', "VARCHAR(500) NOT NULL DEFAULT ''"], ['is_edit', 'TINYINT(1) NOT NULL DEFAULT 0']] as [$c, $d]) $col('saas_media', $c, $d);
    } else $ok = false;
    if ($has('hd_media')) {
        foreach ([['bytes', 'INT NOT NULL DEFAULT 0'], ['tokens', 'INT NOT NULL DEFAULT 0'], ['tr_tokens', 'INT NOT NULL DEFAULT 0'], ['final_prompt', 'TEXT NULL'],
                  ['ref_media_id', 'INT NOT NULL DEFAULT 0'], ['size', "VARCHAR(20) NOT NULL DEFAULT ''"], ['is_edit', 'TINYINT(1) NOT NULL DEFAULT 0']] as [$c, $d]) $col('hd_media', $c, $d);
    } else $ok = false;
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;
}

/** مدل مترجم: انتخاب مدیر؛ وگرنه ارزان‌ترین مدل متنی فعال */
function gen_tr_model($pdo)
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = (int)(biz_settings($pdo)['gen_tr_model_id'] ?? 0);
    $best = null; $bp = INF;
    foreach (biz_models($pdo, true, 'text') as $m) {
        if ($id > 0 && (int)$m['id'] === $id) return $cache = $m;
        $p = biz_model_toman_prices($pdo, $m);
        $c = (float)$p['in'] * 0.6 + (float)$p['out'] * 0.4;
        if ($c < $bp) { $bp = $c; $best = $m; }
    }
    return $cache = ($best ?: (function_exists('biz_system_model') ? biz_system_model($pdo) : null));
}

/** اندازه نهایی بر اساس جهت تشخیص داده‌شده یا انتخاب کاربر */
function gen_size($kind, $aspect, $explicit = '')
{
    $img = ['1024x1024', '1536x1024', '1024x1536'];
    $vid = ['1280x720', '720x1280'];
    if ($kind === 'video') {
        if (in_array($explicit, $vid, true)) return $explicit;
        return $aspect === 'portrait' ? '720x1280' : '1280x720';
    }
    if (in_array($explicit, $img, true)) return $explicit;
    return $aspect === 'landscape' ? '1536x1024' : ($aspect === 'portrait' ? '1024x1536' : '1024x1024');
}

function gen_size_label($size)
{
    return ['1024x1024' => 'مربع ۱:۱', '1536x1024' => 'افقی ۳:۲', '1024x1536' => 'عمودی ۲:۳', '1280x720' => 'افقی ۱۶:۹', '720x1280' => 'عمودی ۹:۱۶'][$size] ?? $size;
}

/**
 * تبدیل درخواست (معمولاً فارسی) به پرامپت انگلیسی + تشخیص ویرایش و ابعاد
 * $ctx: prev (پرامپت انگلیسی تصویر قبلی)، has_ref (تصویر مرجع دارد)، force_edit (ریپلای/دکمه ویرایش)
 * @return array ['ok','prompt','edit','aspect','is_request','tokens','toman','model']
 */
function gen_translate($pdo, $text, $kind, array $ctx = [])
{
    $text = trim(mb_substr((string)$text, 0, max(200, min(4000, (int)($ctx['max_in'] ?? 1500)))));
    $out = ['ok' => false, 'prompt' => $text, 'edit' => !empty($ctx['force_edit']), 'aspect' => '', 'is_request' => true, 'tokens' => 0, 'toman' => 0, 'model' => null];
    if ($text === '') return $out;
    $kw = $kind === 'video' ? 'video' : 'image';
    // نسخه ۴۰: مدل‌هایی که فارسی را می‌فهمند → بدون مترجم (رایگان؛ کیفیت با دستور کوتاه انگلیسی حفظ می‌شود)
    if (!empty($ctx['direct'])) return gen_direct($text, $kw, $ctx);
    $prev = trim((string)($ctx['prev'] ?? ''));
    $has_img = $prev !== '' || !empty($ctx['has_ref']);
    $long = (int)($ctx['max_in'] ?? 1500) > 1500;   // دستورهای بلند گالری
    // دستور کوتاه‌تر (نسخه ۴۰): توکن ورودی و خروجی مترجم تقریباً نصف شد
    $sys = "Turn the user's (usually Persian) request into a prompt for an AI {$kw} model. Reply ONLY with JSON: "
         . "{\"prompt\":\"English prompt, max " . ($long ? 150 : 70) . " words\",\"edit\":bool,\"aspect\":\"square|landscape|portrait|\",\"is_request\":bool}. "
         . "Keep any text to be written in the {$kw} EXACTLY as given, in double quotes. aspect only if size/orientation is mentioned, else \"\". "
         . "is_request=false only if it is clearly not a request to create/change a {$kw}.";
    if ($has_img) {
        $sys .= " A previous/reference image exists" . ($prev !== '' ? " (" . mb_substr($prev, 0, 300) . ")" : '') . ". "
              . "edit=true if the user wants to modify it, false for an unrelated new {$kw}. "
              . "If edit=true, the model receives the image: the prompt must start with \"Edit the provided image:\", list ONLY the requested changes, and end with \"Keep everything else exactly the same.\"";
        if (!empty($ctx['force_edit'])) $sys .= " The user chose to edit this image, so edit must be true.";
    } else {
        $sys .= " No previous image: edit=false. Make the prompt vivid (subject, style, colors, lighting, composition" . ($kw === 'video' ? ', camera movement' : '') . ").";
    }
    try {
        $cfg = saas_get_api_config($pdo);
        $model = gen_tr_model($pdo);
        $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $text]];
        if (!function_exists('aichat_call_ai') && is_file(__DIR__ . '/ai_chat_lib.php')) require_once __DIR__ . '/ai_chat_lib.php';
        $r = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, $long ? 500 : 300, 0.3) : aichat_call_ai($cfg, $msgs, $long ? 500 : 300, 0.3);
        if (empty($r['ok'])) return gen_direct($text, $kw, $ctx);   // مترجم در دسترس نیست → متن اصلی با دستور کوتاه
        $used = $r['model_row'] ?? $model;
        $out['model'] = $used;
        $out['tokens'] = (int)($r['tokens'] ?? 0) ?: ((int)($r['prompt_tokens'] ?? 0) + (int)($r['completion_tokens'] ?? 0));
        $out['toman'] = function_exists('biz_charge_tokens') ? (int)biz_charge_tokens($pdo, $used, $r) : 0;
        $c = trim((string)($r['content'] ?? ''));
        $c = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $c);
        if (preg_match('/\{.*\}/s', $c, $mm)) $c = $mm[0];
        $j = json_decode($c, true);
        if (is_array($j) && trim((string)($j['prompt'] ?? '')) !== '') {
            $out['prompt'] = trim(mb_substr((string)$j['prompt'], 0, 1800));
            $out['edit'] = !empty($ctx['force_edit']) || (empty($ctx['no_edit']) && $has_img && !empty($j['edit']));
            $a = (string)($j['aspect'] ?? '');
            $out['aspect'] = in_array($a, ['square', 'landscape', 'portrait'], true) ? $a : '';
            $out['is_request'] = !isset($j['is_request']) || (bool)$j['is_request'];
            if ($out['edit'] && !preg_match('/\bedit\b/i', $out['prompt'])) $out['prompt'] = 'Edit the provided image: ' . $out['prompt'] . ' Keep everything else exactly the same.';
            $out['ok'] = true;
        } elseif ($c !== '' && $c[0] !== '{') {
            $out['prompt'] = trim(mb_substr($c, 0, 1800));
            $out['ok'] = true;
        }
    } catch (\Throwable $e) { error_log('[GEN] translate: ' . $e->getMessage()); }
    if (!$out['ok']) { $d = gen_direct($text, $kw, $ctx); $d['tokens'] = $out['tokens']; $d['toman'] = $out['toman']; $d['model'] = $out['model']; return $d; }
    return $out;
}

/** تشخیص درخواست صریح ساخت تصویر/ویدیو در یک پیام عادی گفتگو ('image' | 'video' | '') */
function gen_intent($text)
{
    $t = mb_strtolower(trim((string)$text));
    if ($t === '' || mb_strlen($t) > 600) return '';
    $verb = '(بساز|بسازی|بسازید|بکش|بکشی|بکشید|طراحی\s*کن|طراحی\s*کنید|طراحی\s*بکن|درست\s*کن|درست\s*کنید|تولید\s*کن|تولید\s*کنید|ایجاد\s*کن|ایجاد\s*کنید|generate|draw|create|design|make)';
    if (preg_match('/(ویدیو|ویدئو|فیلم|کلیپ|انیمیشن|video|clip|animation)/u', $t) && preg_match('/' . $verb . '/u', $t)) return 'video';
    if (preg_match('/(تصویر|عکس|لوگو|لوگوی|پوستر|بنر|نقاشی|وکتور|آیکون|آیکن|کاریکاتور|ایلاستریشن|تامبنیل|کاور|image|picture|photo|logo|poster|banner|illustration|icon)/u', $t) && preg_match('/' . $verb . '/u', $t)) return 'image';
    return '';
}

/** آیا پیام شبیه دستور ویرایش تصویر است؟ (فقط وقتی تصویر قبلی در گفتگو هست؛ تصمیم نهایی با مترجم) */
function gen_edit_hint($text)
{
    $t = trim((string)$text);
    if ($t === '' || mb_strlen($t) > 400) return false;
    return (bool)preg_match('/(رنگ|سایز|اندازه|ابعاد|نوشته|متن|فونت|پس\s*زمینه|پس‌زمینه|بزرگ|کوچک|روشن|تیره|عوض|تغییر|حذف|اضافه|بردار|بذار|بگذار|قرار\s*بده|بکنش|کنش|ش\s*کن|تر\s*کن|تر\s*بشه|افقی|عمودی|مربع|استوری|لوگو|دوباره|یه\s*بار\s*دیگه|نسخه|ادیت|ویرایش|edit|change|make it|add|remove|bigger|smaller|color|background|text)/ui', $t);
}

/** نمایش حجم فایل به فارسی */
function gen_fmt_bytes($n)
{
    $n = (int)$n;
    if ($n <= 0) return '';
    $fa = fn($s) => strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', '.' => '٫']);
    if ($n >= 1048576) return $fa(number_format($n / 1048576, 1)) . ' مگابایت';
    return $fa(max(1, (int)round($n / 1024))) . ' کیلوبایت';
}

function gen_fa_num($n) { return strtr(number_format((int)$n), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬']); }

/**
 * آماده‌سازی تصویر مرجع: اگر بزرگ است کوچک می‌شود (حداکثر ۱۵۳۶ پیکسل) تا ارسال سریع و ارزان باشد
 * @return array|null ['bytes','mime']
 */
function gen_ref_prepare($bytes, $mime = '')
{
    if (!is_string($bytes) || strlen($bytes) < 100) return null;
    $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
    if (!$info) return null;
    $mime = $info['mime'] ?? ($mime ?: 'image/png');
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || max($info[0], $info[1]) > 1536 || strlen($bytes) > 3 * 1024 * 1024) {
        if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bytes))) {
            $w = imagesx($src); $h = imagesy($src);
            $k = min(1, 1536 / max($w, $h));
            $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start(); imagepng($dst, null, 6); $png = ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
            if ($png && strlen($png) <= 4 * 1024 * 1024) return ['bytes' => $png, 'mime' => 'image/png'];
            if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bytes))) {
                $dst = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start(); imagejpeg($dst, null, 88); $jpg = ob_get_clean();
                imagedestroy($src); imagedestroy($dst);
                if ($jpg) return ['bytes' => $jpg, 'mime' => 'image/jpeg'];
            }
        }
    }
    return ['bytes' => $bytes, 'mime' => $mime];
}

/** بررسی فایل پیوست‌شده (آپلود فرم) → ['bytes','mime','ext'] یا پیام خطا */
function gen_upload_check(array $f)
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) return 'بارگذاری فایل ناموفق بود (حداکثر حجم مجاز هاست را بررسی کنید).';
    if ((int)$f['size'] > 10 * 1024 * 1024) return 'حجم تصویر پیوست حداکثر ۱۰ مگابایت است.';
    $bytes = (string)@file_get_contents((string)$f['tmp_name']);
    $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
    if (!$info || !in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) return 'فقط تصویر (PNG، JPG، WEBP) قابل پیوست است.';
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime']];
    return ['bytes' => $bytes, 'mime' => $info['mime'], 'ext' => $ext];
}

// ---------------------------------------------------------------------------
// نسخه ۴۰: کاهش هزینه ترجمه — مدل‌هایی که فارسی را مستقیم می‌فهمند بدون مترجم
// ---------------------------------------------------------------------------

/** مدل تصویر، درخواست فارسی را مستقیم و دقیق می‌فهمد؟ (Gemini / Nano Banana / GPT-Image) */
function gen_model_fa($model)
{
    return (bool)preg_match('/gemini|nano.?banana|gpt-image|chatgpt-image|gpt-4o|gpt-5/i', (string)($model['model_id'] ?? ''));
}

/** حالت مترجم: auto (پیشنهادی) | always | never */
function gen_tr_mode($pdo)
{
    $m = (string)(biz_settings($pdo)['gen_tr_mode'] ?? 'auto');
    return in_array($m, ['auto', 'always', 'never'], true) ? $m : 'auto';
}

/** برای این مدل، درخواست باید با مترجم به انگلیسی تبدیل شود؟ */
function gen_need_translate($pdo, $model, $kind)
{
    $mode = gen_tr_mode($pdo);
    if ($mode === 'always') return true;
    if ($mode === 'never') return false;
    return !($kind === 'image' && gen_model_fa($model));
}

/** تشخیص ابعاد از متن (بدون هوش مصنوعی) */
function gen_aspect_rule($text)
{
    $t = mb_strtolower((string)$text);
    if (preg_match('/(افقی|landscape|16\s*[:×x\/]\s*9|3\s*[:×x\/]\s*2|4\s*[:×x\/]\s*3|بنر|banner|کاور\s*یوتیوب|تامبنیل|thumbnail|wide)/u', $t)) return 'landscape';
    if (preg_match('/(عمودی|portrait|9\s*[:×x\/]\s*16|2\s*[:×x\/]\s*3|3\s*[:×x\/]\s*4|استوری|story|ریلز|reels)/u', $t)) return 'portrait';
    if (preg_match('/(مربع|مربعی|square|1\s*[:×x\/]\s*1|پست\s*اینستا|عکس\s*پروفایل|پروفایل)/u', $t)) return 'square';
    return '';
}

/** درخواست صریحاً «طرح تازه» است؟ */
function gen_new_hint($text)
{
    $t = (string)$text;
    if (preg_match('/(همین|این\s*(تصویر|عکس|طرح|لوگو)|اینو|اینرو|قبلی)/u', $t)) return false;
    return (bool)preg_match('/(جدید|تازه|دیگه\s*ای|دیگه‌ای|دیگری|(یک|یه)\s+(تصویر|عکس|لوگو|پوستر|بنر|نقاشی|طرح)|\bnew\b|\banother\b)/ui', $t);
}

/** پیام، پرسش معمولی است (نه دستور ساخت/تغییر)؟ */
function gen_is_question($text)
{
    $t = trim((string)$text);
    $imp = '/(بکن|بکنش|کنش|\sکن(\s|$|!|\.)|بده|بذار|بگذار|بساز|بکش|بنویس|عوض|اضافه|حذف|بردار|change|make|add|remove|edit)/u';
    if (preg_match($imp, $t . ' ')) return false;
    return (bool)preg_match('/(\?|؟|^(چرا|چطور|چگونه|آیا|چه|چی|کدام|کدوم|کی|کجا)\b|معنی|یعنی\s*چه|توضیح\s*بده)/u', $t);
}

/**
 * آماده‌سازی دستور بدون مترجم (رایگان): متن فارسی کاربر + یک دستور کوتاه انگلیسی برای حفظ کیفیت و دقت ویرایش
 * خروجی هم‌شکل gen_translate
 */
function gen_direct($text, $kind, array $ctx = [])
{
    $prev = trim((string)($ctx['prev'] ?? ''));
    $has_ref = !empty($ctx['has_ref']);
    $edit = !empty($ctx['force_edit']);
    if (!$edit && empty($ctx['no_edit']) && $has_ref && gen_edit_hint($text) && !gen_new_hint($text)) $edit = true;
    $is_request = true;
    if (!empty($ctx['need_request'])) $is_request = gen_intent($text) !== '' || !gen_is_question($text);
    $q = "Any text the user wants written in the {$kind} must appear exactly as given (same language, spelling and script; Persian/Arabic is right-to-left).";
    if ($kind === 'video') {
        $p = "Create a high-quality, cinematic video for this request (written in Persian; follow it precisely). {$q}\nRequest: " . $text;
    } elseif ($edit) {
        $p = "Edit the provided image. Apply ONLY the change(s) requested below and keep everything else exactly as it is: subject, composition, framing, style, colors, lighting, text and layout. {$q}\nRequested change (in Persian): " . $text;
    } elseif (!empty($ctx['ref_attached'])) {
        $p = "Create a high-quality image based on the provided reference image, following this request precisely (written in Persian). {$q}\nRequest: " . $text;
    } else {
        $p = "Create a high-quality, detailed, professional image for this request (written in Persian; follow every detail precisely). {$q}\nRequest: " . $text;
    }
    return ['ok' => true, 'prompt' => $p, 'edit' => $edit, 'aspect' => gen_aspect_rule($text), 'is_request' => $is_request, 'tokens' => 0, 'toman' => 0, 'model' => null, 'direct' => true];
}

/**
 * برآورد هزینه پیش از ساخت (نزدیک به عدد نهایی): قیمت مدل + میانگین واقعی ترجمه‌های اخیر (اگر مترجم لازم است)
 * @return array ['img' => تومان ساخت (برای ویدیو: هر ثانیه), 'tr' => تومان ترجمه, 'direct' => bool]
 */
function gen_estimate($pdo, $model, $kind)
{
    $img = $kind === 'video' ? biz_model_toman_prices($pdo, ['category' => 'video'] + (array)$model)['unit'] : biz_media_cost($pdo, $model, 'image');
    if (!gen_need_translate($pdo, $model, $kind)) return ['img' => $img, 'tr' => 0, 'direct' => true];
    static $avg = null;
    if ($avg === null) {
        $avg = 0; $n = 0; $sum = 0;
        foreach (['saas_media', 'hd_media'] as $t) {
            try {
                $r = $pdo->query("SELECT tr_tokens FROM `$t` WHERE tr_tokens > 0 ORDER BY id DESC LIMIT 20")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                foreach ($r as $v) { $sum += (int)$v; $n++; }
            } catch (\Throwable $e) {}
        }
        $avg = $n ? (int)round($sum / $n) : 420;
    }
    $in = (int)round($avg * 0.8);
    $tr = function_exists('biz_price_text_cost') ? (int)biz_price_text_cost($pdo, gen_tr_model($pdo), $in, $avg - $in) : 0;
    return ['img' => $img, 'tr' => $tr, 'direct' => false];
}
