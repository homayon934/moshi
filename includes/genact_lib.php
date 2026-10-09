<?php
/**
 * اقدام‌های آماده ساخت تصویر و ویدیو (نسخه ۴۲) — «با یک کلیک»
 *
 *  مدیر کل در «مدیریت ← اقدام‌های آماده تصویر/ویدیو» برای هر کار مشخص (مثل «حذف پس‌زمینه»، «بازسازی دقیق همین تصویر»،
 *  «متحرک کردن عکس») یک عنوان، دستور (پرامپت) و فهرست مدل‌های مناسب (به ترتیب اولویت) تعریف می‌کند.
 *  کاربر در استودیو و مخاطب در چت‌بات فقط روی دکمه می‌زند؛ تصویر انتخاب‌شده/پیوست‌شده با همان دستور و بهترین مدل اجرا می‌شود.
 *
 *  ستون‌ها: kind (image|video)، ref (required|optional|none)، ask_label (اگر پر باشد یک ورودی کوتاه از کاربر می‌گیرد و جای {input} می‌نشیند)،
 *  model_ids (شناسه مدل‌ها به ترتیب؛ خالی = همه مدل‌های پلن، مدل‌های مناسب ویرایش اول)، size_mode (keep|auto|square|landscape|portrait)،
 *  keep_dims (خروجی دقیقاً هم‌اندازه تصویر ورودی)، transparent (پس‌زمینه شفاف در مدل‌هایی که پشتیبانی می‌کنند)، in_studio/in_bot.
 *
 * این فایل از انتهای biz_lib.php بارگذاری می‌شود.
 */

if (!defined('GA_SCHEMA_VERSION')) define('GA_SCHEMA_VERSION', 1);

function ga_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.ga_schema_v' . GA_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_gen_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(8) NOT NULL DEFAULT 'image',
            title VARCHAR(120) NOT NULL,
            icon VARCHAR(16) NOT NULL DEFAULT '⚡',
            description VARCHAR(255) NOT NULL DEFAULT '',
            prompt TEXT NOT NULL,
            ref VARCHAR(10) NOT NULL DEFAULT 'required',
            ask_label VARCHAR(120) NOT NULL DEFAULT '',
            ask_hint VARCHAR(200) NOT NULL DEFAULT '',
            model_ids VARCHAR(255) NOT NULL DEFAULT '',
            size_mode VARCHAR(10) NOT NULL DEFAULT 'keep',
            keep_dims TINYINT(1) NOT NULL DEFAULT 0,
            transparent TINYINT(1) NOT NULL DEFAULT 0,
            seconds INT NOT NULL DEFAULT 4,
            in_studio TINYINT(1) NOT NULL DEFAULT 1,
            in_bot TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            runs INT NOT NULL DEFAULT 0,
            fails INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_kind (kind, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $n = (int)$pdo->query("SELECT COUNT(*) FROM saas_gen_actions")->fetchColumn();
        if ($n === 0) ga_seed($pdo);
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[GA] schema: ' . $e->getMessage()); }
}

/** اقدام‌های پیش‌فرض (فقط بار اول؛ مدیر می‌تواند ویرایش/حذف کند) */
function ga_defaults()
{
    $keep = 'Keep everything else exactly the same: the same subject, identity, faces, composition, framing, colors, lighting, text and aspect ratio.';
    return [
        ['image', '🔁', 'بازسازی دقیق همین تصویر', 'همان تصویر با کیفیت و وضوح بالاتر؛ بدون تغییر ابعاد و محتوا',
         'Recreate the provided image exactly as it is, with higher quality, sharper details and cleaner textures. Do not add, remove or change anything. ' . $keep, 'required', '', '', 'keep', 1, 0],
        ['image', '✂️', 'حذف پس‌زمینه', 'سوژه اصلی بدون پس‌زمینه (شفاف در مدل‌هایی که پشتیبانی می‌کنند، وگرنه سفید)',
         'Remove the background completely and keep only the main subject, unchanged, with clean and precise edges (hair and fine details preserved). Output the subject on a transparent background; if transparency is not possible, use a pure white (#FFFFFF) background. Do not change the subject in any way.', 'required', '', '', 'keep', 1, 1],
        ['image', '🔍', 'افزایش کیفیت و وضوح', 'رفع تاری و نویز، جزئیات بیشتر',
         'Enhance this image: remove blur, noise and compression artifacts, increase sharpness and fine detail, improve dynamic range naturally. ' . $keep, 'required', '', '', 'keep', 1, 0],
        ['image', '🛍', 'عکس محصول استودیویی', 'محصول روی پس‌زمینه تمیز استودیویی با سایه نرم',
         'Turn this into a professional e-commerce product photo: place the exact same product on a clean seamless light-gray studio background with soft natural shadow and professional lighting. Do not change the product itself (shape, colors, logo, text).', 'required', '', '', 'keep', 0, 0],
        ['image', '🎨', 'تغییر رنگ پس‌زمینه', 'رنگ دلخواه برای پس‌زمینه',
         'Change only the background color to: {input}. ' . $keep, 'required', 'رنگ پس‌زمینه', 'مثلاً: آبی نفتی', 'keep', 1, 0],
        ['image', '🧽', 'حذف شیء یا نوشته', 'چیزی که نمی‌خواهید از تصویر پاک شود',
         'Remove this from the image: {input}. Fill the area naturally so it looks like it was never there. ' . $keep, 'required', 'چه چیزی حذف شود؟', 'مثلاً: نوشته گوشه پایین / آدم پشت سر', 'keep', 1, 0],
        ['image', '🖼', 'ترمیم و رنگی کردن عکس قدیمی', 'رفع خش و پارگی، رنگی کردن طبیعی',
         'Restore this old photo: repair scratches, tears, dust and fading, and colorize it with natural, realistic colors. Keep the people\'s faces and identity exactly the same. Keep composition and aspect ratio.', 'required', '', '', 'keep', 1, 0],
        ['image', '👔', 'عکس پرسنلی / پروفایل حرفه‌ای', 'پس‌زمینه ساده، نور و لباس رسمی',
         'Create a professional headshot from this photo: keep the person\'s face and identity exactly the same, neat formal attire, soft studio lighting, plain light background, head-and-shoulders framing.', 'required', '', '', 'portrait', 0, 0],
        ['image', '🖌', 'تبدیل به نقاشی', 'سبک نقاشی دلخواه',
         'Redraw this image as a {input} artwork, keeping the same composition, subjects and aspect ratio.', 'required', 'سبک نقاشی', 'مثلاً: آبرنگ / کارتونی / رنگ روغن', 'keep', 0, 0],
        ['image', '🏷', 'لوگو از روی نام', 'یک لوگوی مینیمال و حرفه‌ای',
         'Design a clean, modern, minimal vector-style logo for the brand name "{input}". Flat colors, simple memorable symbol, the brand name written exactly as given, centered on a plain white background.', 'none', 'نام برند (همان‌طور که باید نوشته شود)', 'مثلاً: کافه نارنج', 'square', 0, 0],
        ['video', '🎞', 'متحرک کردن عکس', 'حرکت آرام و طبیعی دوربین و سوژه',
         'Animate this photo with subtle, natural motion: slow cinematic camera push-in, gentle movement of hair, clothes, water or leaves where present. Keep the scene and subjects exactly as in the photo.', 'required', '', '', 'keep', 0, 0],
        ['video', '🔄', 'نمایش ۳۶۰ درجه محصول', 'چرخش آرام محصول روی سکو',
         'Create a smooth product showcase video: the exact same product from the photo rotates slowly 360 degrees on a clean studio turntable with soft lighting. Do not change the product.', 'required', '', '', 'keep', 0, 0],
        ['video', '👋', 'زنده کردن عکس قدیمی', 'لبخند و حرکت ملایم افراد',
         'Bring this old photo to life: the people gently smile, blink and move slightly, natural and respectful motion. Keep faces and identity exactly the same, keep the vintage look.', 'required', '', '', 'keep', 0, 0],
    ];
}

function ga_seed($pdo)
{
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO saas_gen_actions (kind, icon, title, description, prompt, ref, ask_label, ask_hint, size_mode, keep_dims, transparent, sort_order, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach (ga_defaults() as $i => $d) $st->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8], $d[9], $d[10], ($i + 1) * 10, $now, $now]);
}

function ga_get($pdo, $id)
{
    ga_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM saas_gen_actions WHERE id=?");
    $s->execute([(int)$id]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** فهرست: $where = studio | bot | all */
function ga_list($pdo, $kind = null, $where = 'all', $active_only = true)
{
    ga_ensure_schema($pdo);
    $w = []; $p = [];
    if ($kind) { $w[] = 'kind=?'; $p[] = $kind; }
    if ($active_only) $w[] = 'is_active=1';
    if ($where === 'studio') $w[] = 'in_studio=1';
    if ($where === 'bot') $w[] = 'in_bot=1';
    try {
        $s = $pdo->prepare("SELECT * FROM saas_gen_actions" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY kind ASC, sort_order ASC, id ASC");
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

/** آیا مدل برای کار با تصویر مرجع مناسب است؟ (برای ترتیب پیش‌فرض) */
function ga_edit_capable($m)
{
    return (bool)preg_match('/gemini|nano.?banana|gpt-image|flux.*kontext|seededit|qwen.*edit/i', (string)($m['model_id'] ?? ''));
}

/**
 * مدل‌های اجرای این اقدام به ترتیب، فقط از میان مدل‌های مجاز (پلن)
 * model_ids خالی = همه مدل‌های مجاز (برای اقدام‌های دارای تصویر، مدل‌های مناسب ویرایش اول)
 */
function ga_models($pdo, array $ga, array $allowed)
{
    $by = [];
    foreach ($allowed as $m) $by[(int)$m['id']] = $m;
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$ga['model_ids']))));
    if ($ids) {
        $out = [];
        foreach ($ids as $id) if (isset($by[$id])) $out[] = $by[$id];
        return $out;
    }
    $list = array_values($by);
    if ($ga['ref'] !== 'none') usort($list, fn($a, $b) => (int)ga_edit_capable($b) <=> (int)ga_edit_capable($a));
    return $list;
}

/** متن نهایی دستور با ورودی کاربر */
function ga_prompt(array $ga, $input = '')
{
    $input = trim(mb_substr(strip_tags((string)$input), 0, 300));
    $p = (string)$ga['prompt'];
    if (strpos($p, '{input}') !== false || strpos($p, '{متن}') !== false) $p = str_replace(['{input}', '{متن}'], $input !== '' ? $input : '-', $p);
    elseif ($input !== '') $p .= "\nAdditional request from the user (may be in Persian; follow it): " . $input;
    return $p;
}

/** ابعاد تولید بر اساس حالت اقدام و ابعاد تصویر ورودی [w,h] */
function ga_size(array $ga, $dims, $kind, $fallback)
{
    $mode = (string)$ga['size_mode'];
    if ($kind === 'video') {
        if ($mode === 'portrait') return '720x1280';
        if ($mode === 'landscape' || $mode === 'square') return '1280x720';
        if ($mode === 'keep' && $dims) return $dims[0] >= $dims[1] ? '1280x720' : '720x1280';
        return $fallback;
    }
    if ($mode === 'square') return '1024x1024';
    if ($mode === 'landscape') return '1536x1024';
    if ($mode === 'portrait') return '1024x1536';
    if ($mode === 'keep' && $dims) {
        $r = $dims[0] / max(1, $dims[1]);
        return $r > 1.2 ? '1536x1024' : ($r < 0.83 ? '1024x1536' : '1024x1024');
    }
    return $fallback;
}

/** ابعاد یک تصویر [w,h] یا null */
function ga_dims($bytes)
{
    $i = is_string($bytes) && $bytes !== '' && function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
    return $i ? [(int)$i[0], (int)$i[1]] : null;
}

/**
 * خروجی دقیقاً هم‌اندازه تصویر ورودی (برش از وسط برای حفظ نسبت + تغییر اندازه). شفافیت حفظ می‌شود.
 * @return string|null بایت‌های PNG
 */
function ga_fit($bytes, $w, $h)
{
    if (!function_exists('imagecreatefromstring') || $w < 8 || $h < 8) return null;
    $w = min(4096, (int)$w); $h = min(4096, (int)$h);
    $src = @imagecreatefromstring((string)$bytes);
    if (!$src) return null;
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw === $w && $sh === $h) { imagedestroy($src); return null; }
    $k = max($w / $sw, $h / $sh);
    $cw = (int)round($w / $k); $ch = (int)round($h / $k);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $w, $h, $cw, $ch);
    ob_start(); imagepng($dst, null, 6); $out = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return $out ?: null;
}

/**
 * اجرای اقدام تصویری با مدل‌های اقدام به ترتیب (اگر یکی نتوانست، بعدی)
 * @return array خروجی biz_image_generate + model_row
 */
function ga_run_image($pdo, $cfg, array $chain, $prompt, $size, $ref, array $ga)
{
    $last = ['ok' => false, 'error' => 'مدلی برای این کار در دسترس نیست.'];
    foreach (array_slice($chain, 0, 4) as $m) {
        $r = biz_image_generate($pdo, $cfg, $m, $prompt, $size, $ref, ['transparent' => !empty($ga['transparent']), 'no_chain' => true]);
        if (!empty($r['ok'])) return $r;
        $last = $r;
        error_log('[GA] ' . $ga['title'] . ' / ' . ($m['model_id'] ?? '') . ': ' . ($r['error'] ?? ''));
    }
    return $last;
}

function ga_count($pdo, $id, $ok = true)
{
    try { $pdo->prepare("UPDATE saas_gen_actions SET " . ($ok ? 'runs = runs + 1' : 'fails = fails + 1') . " WHERE id=?")->execute([(int)$id]); } catch (\Throwable $e) {}
}

/** داده نمایشی برای رابط (استودیو / چت‌بات) با مدل‌های مجاز؛ اقدامی که مدل ندارد نمایش داده نمی‌شود */
function ga_public($pdo, $kind, $where, array $allowed, $with_cost = false)
{
    $out = [];
    foreach (ga_list($pdo, $kind, $where) as $g) {
        $ch = ga_models($pdo, $g, $allowed);
        if (!$ch) continue;
        $x = ['id' => (int)$g['id'], 'kind' => $g['kind'], 'icon' => $g['icon'], 'title' => $g['title'], 'desc' => $g['description'], 'ref' => $g['ref'],
              'ask' => $g['ask_label'], 'hint' => $g['ask_hint']];
        if ($with_cost && function_exists('biz_media_cost')) $x['cost'] = $kind === 'video' ? biz_media_cost($pdo, $ch[0], 'video', max(1, (int)$g['seconds'])) : biz_media_cost($pdo, $ch[0], 'image');
        $out[] = $x;
    }
    return $out;
}
