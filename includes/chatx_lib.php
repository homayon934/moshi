<?php
/**
 * چت‌بات تخصصی — امکانات تکمیلی (نسخه ۳۲)
 *  - دو روش جذب مخاطب: «شارژ هدیه» با مدل‌های تخصصی + «چت رایگان محدود» با مدل‌های ارزان
 *  - امکانات هر بسته (ارسال فایل/تصویر، پیام صوتی، ساخت تصویر، ساخت ویدیو)
 *  - اتصال یک بسته به چند چت‌بات (اعتبار مشترک با همان شماره موبایل)
 *  - بسته رایگان (فعال‌سازی بدون درگاه، یک بار برای هر مخاطب)
 *  - آرشیو گفتگوها با پوشه‌بندی، پاسخ (ریپلای) و ویرایش پیام
 *  - کامنت همکاران روی پیام‌ها (با اجازه مراجع) — خوانده‌شده توسط هوش مصنوعی
 *  - ساخت تصویر و ویدیو در چت‌بات (طبق بسته مخاطب)
 *  - صفحه یکپارچه چند چت‌بات یک صاحب حساب (ورود یک‌باره)
 *  - قوانین لحن مشترک چت‌بات و ویجت
 *  - پلن دلخواه کاربران سایت (قیمت هر امکان توسط مدیر)
 * ساختار دیتابیس سازگار با MySQL (بدون IF NOT EXISTS در ALTER).
 */
if (defined('CX_LIB_LOADED')) return;
define('CX_LIB_LOADED', 1);
define('CX_SCHEMA_VERSION', 2);
define('CX_FLAGS_ALL', ['files', 'voice', 'image', 'video']);

function cx_now($o = 0) { return date('Y-m-d H:i:s', time() + (int)$o); }

// =============================================================================
// ساختار دیتابیس
// =============================================================================
function cx_ensure_schema($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.cx_schema_v' . CX_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('[CX] schema: ' . $e->getMessage()); } };
    $has = function ($t) use ($pdo) { try { return $pdo->query("SELECT 1 FROM `$t` LIMIT 1") !== false; } catch (\Throwable $e) { return false; } };
    $col = function ($t, $c, $def) use ($pdo) {
        try {
            if (function_exists('saas_add_column_if_missing')) saas_add_column_if_missing($pdo, $t, $c, $def);
            else $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            return true;
        } catch (\Throwable $e) { error_log('[CX] column ' . $t . '.' . $c . ': ' . $e->getMessage()); return false; }
    };
    // پلن‌های سایت (پلن دلخواه کاربر)
    $ok = true;
    // نسخه ۳۳: پیام «تماس با کارشناس» ویجت، راهنمای مخاطبان کاربر، سقف دوره‌ای
    $q("CREATE TABLE IF NOT EXISTS saas_contact_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        conv_id INT NOT NULL DEFAULT 0,
        name VARCHAR(120) NOT NULL DEFAULT '',
        phone VARCHAR(20) NOT NULL DEFAULT '',
        message TEXT NULL,
        page_url VARCHAR(500) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'new',
        note TEXT NULL,
        sms_count INT NOT NULL DEFAULT 0,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        INDEX idx_user (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = $has('saas_contact_requests') && $ok;
    if ($has('saas_widget_settings')) {
        $ok = $col('saas_widget_settings', 'contact_on', 'TINYINT(1) NOT NULL DEFAULT 1') && $ok;
        $ok = $col('saas_widget_settings', 'contact_text', "VARCHAR(300) NOT NULL DEFAULT ''") && $ok;
    } else $ok = false;
    if ($has('ex_articles')) $ok = $col('ex_articles', 'targets', "VARCHAR(300) NOT NULL DEFAULT ''") && $ok; else $ok = false;
    if ($has('saas_plans')) {
        $ok = $col('saas_plans', 'is_custom', 'TINYINT(1) NOT NULL DEFAULT 0') && $ok;
        $ok = $col('saas_plans', 'owner_user_id', 'INT NOT NULL DEFAULT 0') && $ok;
    } else $ok = false;
    // جدول‌های چت‌بات تا وقتی ساخته نشده‌اند، بقیه بعداً انجام می‌شود
    if (!$has('hd_bots') || !$has('hd_members') || !$has('hd_messages') || !$has('hd_member_plans') || !$has('hd_purchases') || !$has('hd_threads')) { $done = false; return; }
    foreach ([
        ['hd_bots', 'acq_free_on', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['hd_bots', 'gift_on', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['hd_bots', 'gift_messages', 'INT NOT NULL DEFAULT 0'],
        ['hd_bots', 'free_media', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['hd_bots', 'hub_show', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['hd_bots', 'gen_image_cost', 'INT NOT NULL DEFAULT 5'],
        ['hd_bots', 'gen_video_cost', 'INT NOT NULL DEFAULT 20'],
        ['hd_bots', 'upgrade_text', "VARCHAR(300) NOT NULL DEFAULT ''"],
        ['hd_bots', 'win_hours', 'INT NOT NULL DEFAULT 0'],
        ['hd_bots', 'win_free', 'INT NOT NULL DEFAULT 0'],
        ['hd_member_plans', 'win_msgs', 'INT NOT NULL DEFAULT 0'],
        ['hd_purchases', 'win_msgs', 'INT NOT NULL DEFAULT 0'],
        ['hd_members', 'gift_used', 'INT NOT NULL DEFAULT 0'],
        ['hd_members', 'thread_folders', 'TEXT NULL'],
        ['hd_members', 'imp_actor', "VARCHAR(150) NOT NULL DEFAULT ''"],
        ['hd_threads', 'folder', "VARCHAR(60) NOT NULL DEFAULT ''"],
        ['hd_messages', 'reply_to', 'INT NOT NULL DEFAULT 0'],
        ['hd_messages', 'media', 'TEXT NULL'],
        ['hd_member_plans', 'flags', "VARCHAR(100) NOT NULL DEFAULT 'files,voice'"],
        ['hd_member_plans', 'bot_ids', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['hd_purchases', 'flags', "VARCHAR(100) NOT NULL DEFAULT 'files,voice'"],
        ['hd_purchases', 'shared_bots', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['hd_purchases', 'mobile', "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['hd_purchases', 'owner_id', 'INT NOT NULL DEFAULT 0'],
    ] as [$t, $c, $d]) $ok = $col($t, $c, $d) && $ok;
    $q("CREATE TABLE IF NOT EXISTS hd_msg_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        message_id INT NOT NULL,
        author VARCHAR(150) NOT NULL DEFAULT '',
        author_team INT NOT NULL DEFAULT 0,
        body TEXT NOT NULL,
        show_member TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        INDEX idx_member (bot_id, member_id),
        INDEX idx_msg (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS hd_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        thread_id INT NOT NULL DEFAULT 0,
        message_id INT NOT NULL DEFAULT 0,
        kind VARCHAR(8) NOT NULL DEFAULT 'image',
        prompt TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'queued',
        job_id VARCHAR(120) NOT NULL DEFAULT '',
        model_id INT NOT NULL DEFAULT 0,
        path VARCHAR(300) NOT NULL DEFAULT '',
        mime VARCHAR(40) NOT NULL DEFAULT '',
        cost_msgs INT NOT NULL DEFAULT 0,
        cost_toman INT NOT NULL DEFAULT 0,
        charged TINYINT(1) NOT NULL DEFAULT 0,
        error VARCHAR(300) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_member (bot_id, member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = $ok && $has('hd_msg_comments') && $has('hd_media');
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;
}

// =============================================================================
// قوانین لحن (مشترک چت‌بات تخصصی و ویجت سایت)
// =============================================================================
function cx_has_emoji($text)
{
    return (bool)preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F000}-\x{1F2FF}\x{2B50}\x{2764}]/u', (string)$text);
}

function cx_tone_rules()
{
    return "سبک و لحن پاسخ (اجباری):\n"
        . "- لحن خودت را با لحن مخاطب هماهنگ کن: اگر رسمی نوشت، رسمی؛ اگر خودمانی و صمیمی نوشت، صمیمی و خودمانی (ولی همیشه محترمانه) پاسخ بده.\n"
        . "- اگر مخاطب در پیامش شکلک (ایموجی) فرستاد، تو هم ۱ تا ۳ شکلک مناسب و هم‌حس در پاسخ بگذار؛ اگر شکلک نفرستاد، شکلک نگذار (حداکثر یکی و فقط اگر خیلی به‌جاست).\n"
        . "- هر اصطلاح تخصصی، علمی یا انگلیسی که به کار می‌بری، بلافاصله کنارش داخل پرانتز با فارسی روان و ساده توضیح بده؛ مثال: «متابولیسم (سوخت‌وساز بدن)».\n"
        . "- طول پاسخ متناسب با سؤال باشد: سؤال کوتاه و ساده = پاسخ کوتاه (یک تا چند جمله)؛ فقط وقتی سؤال مفصل است یا مخاطب توضیح بیشتر خواست، مفصل بنویس. هیچ مطلبی خارج از آنچه پرسیده شده اضافه نکن.";
}

/** یادآوری کوتاه برای پیام فعلی (شکلک) */
function cx_tone_hint($message)
{
    return cx_has_emoji($message) ? "\n(پیام فعلی مخاطب شکلک دارد؛ پاسخ را با ۱ تا ۳ شکلک مناسب همراه کن.)" : '';
}

// =============================================================================
// امکانات بسته‌ها و روش‌های جذب مخاطب
// =============================================================================
function cx_flag_labels()
{
    return ['files' => '📎 ارسال تصویر و فایل', 'voice' => '🎙 پیام صوتی و پخش صوتی پاسخ‌ها', 'image' => '🎨 ساخت تصویر', 'video' => '🎬 ساخت ویدیو'];
}

function cx_flags_parse($s)
{
    $s = (string)$s;
    return array_values(array_intersect(CX_FLAGS_ALL, array_map('trim', explode(',', $s))));
}

function cx_flags_str(array $a)
{
    return implode(',', array_values(array_intersect(CX_FLAGS_ALL, $a)));
}

/** شناسه‌های ربات به شکل ,1,2, برای جستجوی سازگار */
function cx_ids_str(array $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ? ',' . implode(',', $ids) . ',' : '';
}

function cx_ids_parse($s)
{
    return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$s)))));
}

/** پلن صاحب ربات (با کش درون‌حافظه) */
function cx_owner_plan($pdo, $bot)
{
    $k = 'op' . (int)$bot['user_id'];
    if (!array_key_exists($k, $GLOBALS['__cx_cache'] ?? [])) {
        $ou = $pdo->prepare("SELECT plan_id FROM saas_users WHERE id=?");
        $ou->execute([(int)$bot['user_id']]);
        $pid = (int)$ou->fetchColumn();
        $GLOBALS['__cx_cache'][$k] = $pid ? (saas_get_plan($pdo, $pid) ?: null) : null;
    }
    return $GLOBALS['__cx_cache'][$k];
}

function cx_cache_reset() { $GLOBALS['__cx_cache'] = []; }

/** مدل‌های ساخت تصویر / ویدیو پلن صاحب ربات */
function cx_gen_models($pdo, $bot, $kind)
{
    if (!function_exists('biz_plan_models')) return [];
    try { return biz_plan_models($pdo, cx_owner_plan($pdo, $bot), $kind === 'video' ? 'video' : 'image'); } catch (\Throwable $e) { return []; }
}

/**
 * امکانات فعال مخاطب: owner = همه؛ بسته خریداری‌شده = امکانات همان بسته؛ شارژ هدیه = فایل و صوت؛ رایگان = هیچ (مگر صاحب ربات اجازه داده باشد)
 * @return array ['files'=>bool,'voice'=>bool,'image'=>bool,'video'=>bool]
 */
function cx_member_flags($pdo, $bot, $member, $quota = null)
{
    $quota = $quota ?: hd_quota($pdo, $bot, $member);
    $f = [];
    if (!empty($member['is_owner'])) $f = CX_FLAGS_ALL;
    else {
        if (($quota['paid_left'] ?? 0) > 0) {
            foreach (hd_active_purchases($pdo, $bot['id'], $member['id']) as $p) {
                if ((int)$p['used'] >= (int)$p['messages']) continue;
                $f = array_merge($f, cx_flags_parse(array_key_exists('flags', $p) ? $p['flags'] : 'files,voice'));
            }
        }
        if (($quota['credit_left'] ?? 0) > 0) {   // بسته شارژ تومانی
            foreach (hd_active_purchases($pdo, $bot['id'], $member['id']) as $p)
                if ((int)($p['credit_toman'] ?? 0) > (int)($p['credit_used'] ?? 0)) $f = array_merge($f, cx_flags_parse(array_key_exists('flags', $p) ? $p['flags'] : 'files,voice'));
        }
        if (($quota['gift_left'] ?? 0) > 0) $f = array_merge($f, ['files', 'voice']);
        if (!empty($bot['free_media'])) $f = array_merge($f, ['files', 'voice']);
    }
    $f = array_unique($f);
    return ['files' => in_array('files', $f, true), 'voice' => in_array('voice', $f, true), 'image' => in_array('image', $f, true), 'video' => in_array('video', $f, true)];
}

// =============================================================================
// بسته‌های یک ربات (بسته‌های خودش + بسته‌های مشترک ربات‌های دیگر همان صاحب)
// =============================================================================
function cx_bot_plans($pdo, $bot, $only_active = true)
{
    $st = $pdo->prepare("SELECT p.* FROM hd_member_plans p JOIN hd_bots b ON b.id=p.bot_id WHERE b.user_id=? AND (p.bot_id=? OR p.bot_ids LIKE ?)" . ($only_active ? " AND p.is_active=1" : "") . " ORDER BY p.sort_order ASC, p.price_toman ASC, p.id ASC LIMIT 80");
    try {
        $st->execute([(int)$bot['user_id'], (int)$bot['id'], '%,' . (int)$bot['id'] . ',%']);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return hd_member_plans($pdo, $bot['id'], $only_active);
    }
}

/** یک بسته قابل خرید در این ربات */
function cx_plan_for_bot($pdo, $bot, $plan_id)
{
    foreach (cx_bot_plans($pdo, $bot) as $p) if ((int)$p['id'] === (int)$plan_id) return $p;
    return null;
}

/** ربات‌هایی که یک بسته در آن‌ها معتبر است */
function cx_plan_bots($plan)
{
    return array_values(array_unique(array_merge([(int)$plan['bot_id']], cx_ids_parse($plan['bot_ids'] ?? ''))));
}

/** آیا در این ربات بسته رایگان فعال هست؟ */
function cx_has_free_plan($pdo, $bot)
{
    foreach (cx_bot_plans($pdo, $bot) as $p) if ((int)$p['price_toman'] === 0) return true;
    return false;
}

/** فعال‌سازی بسته رایگان (یک بار برای هر مخاطب) */
function cx_free_plan_take($pdo, $bot, $member, $plan)
{
    $mobile = (string)($member['mobile'] ?? '');
    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_purchases WHERE plan_id=? AND status='paid' AND ((bot_id=? AND member_id=?) OR (mobile<>'' AND mobile=? AND owner_id=?))");
    $c->execute([(int)$plan['id'], (int)$bot['id'], (int)$member['id'], $mobile, (int)$bot['user_id']]);
    if ((int)$c->fetchColumn() > 0) return ['ok' => false, 'error' => 'این بسته رایگان را قبلاً فعال کرده‌اید.'];
    $pdo->prepare("INSERT INTO hd_purchases (bot_id, member_id, plan_id, plan_name, amount_toman, original_amount, discount_amount, discount_code_id, messages, daily_limit, days, status, return_url, created_at, flags, shared_bots, mobile, owner_id) VALUES(?,?,?,?,0,0,0,0,?,?,?,'pending','',?,?,?,?,?)")
        ->execute([(int)$bot['id'], (int)$member['id'], (int)$plan['id'], $plan['name'], (int)$plan['messages'], (int)$plan['daily_limit'], (int)$plan['days'], cx_now(),
                   (string)($plan['flags'] ?? 'files,voice'), cx_ids_str(cx_plan_bots($plan)), $mobile, (int)$bot['user_id']]);
    $pid = (int)$pdo->lastInsertId();
    try { $pdo->prepare("UPDATE hd_purchases SET win_msgs=? WHERE id=?")->execute([(int)($plan['win_msgs'] ?? 0), $pid]); } catch (\Throwable $e) {}
    if (function_exists('lim_ensure_schema')) { lim_ensure_schema($pdo); try { $pdo->prepare("UPDATE hd_purchases SET week_limit=?, month_limit=? WHERE id=?")->execute([(int)($plan['week_limit'] ?? 0), (int)($plan['month_limit'] ?? 0), $pid]); } catch (\Throwable $e) {} }
    try { $pdo->prepare("UPDATE hd_purchases SET rem_sms=? WHERE id=?")->execute([(int)($plan['rem_sms'] ?? 0), $pid]); } catch (\Throwable $e) {}
    $st = $pdo->prepare("SELECT * FROM hd_purchases WHERE id=?");
    $st->execute([$pid]);
    hd_activate_purchase($pdo, $bot, $st->fetch(\PDO::FETCH_ASSOC), 'FREE');
    return ['ok' => true, 'free' => true, 'message' => 'بسته «' . $plan['name'] . '» فعال شد.', 'quota' => hd_quota($pdo, $bot, $member)];
}

/** اطلاعات عمومی بسته‌ها برای صفحه خرید مخاطب */
function cx_plans_public($pdo, $bot, $member = null)
{
    $sells = hd_bot_sells($pdo, $bot);
    $labels = cx_flag_labels();
    $out = [];
    // تعداد مدل‌های هر بسته (نسخه ۵۹): مدل‌های پلن صاحب ربات منهای مدل‌هایی که در بسته غیرفعال شده‌اند
    $mx = function_exists('mx_count_models');
    $tm = $mx && function_exists('biz_plan_models') ? biz_plan_models($pdo, cx_owner_plan($pdo, $bot), 'text', 'bot') : [];
    $gm = $mx ? ['image' => cx_gen_models($pdo, $bot, 'image'), 'video' => cx_gen_models($pdo, $bot, 'video')] : ['image' => [], 'video' => []];
    foreach (cx_bot_plans($pdo, $bot) as $p) {
        $price = (int)$p['price_toman'];
        if ($price > 0 && ($price < 1000 || !$sells)) continue;
        $pp = hd_plan_price($p);
        $feats = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($p['features'] ?? ''))), 'strlen'));
        $fl = cx_flags_parse($p['flags'] ?? 'files,voice');
        $pc = $mx ? mx_cfg_parse($p['model_cfg'] ?? '') : [];
        $on = fn($list) => array_values(array_filter($list, fn($m) => empty($pc[(int)$m['id']]['off'])));
        foreach (array_reverse($fl) as $k) if (!in_array($labels[$k], $feats, true)) array_unshift($feats, $labels[$k]);
        // فقط تعداد مدل‌ها (اصلی و جایگزین) نمایش داده می‌شود، نه نام آن‌ها
        $gx = [];
        foreach (['image' => 'ساخت تصویر', 'video' => 'ساخت ویدیو'] as $k => $kl) if ($mx && in_array($k, $fl, true) && ($gl = $on($gm[$k]))) $gx[] = mx_count_text(mx_count_models($pdo, $gl), 'مدل ' . $kl);
        if ($gx) array_splice($feats, count($fl), 0, ['🧠 ' . implode('، ', $gx)]);
        array_unshift($feats, $mx && ($tl = $on($tm)) ? 'پاسخ‌گویی با ' . mx_count_text(mx_count_models($pdo, $tl), 'مدل تخصصی') : 'پاسخ‌گویی با مدل‌های تخصصی');
        if ((int)($p['rem_sms'] ?? 0) > 0) $feats[] = '📩 ' . number_format((int)$p['rem_sms']) . ' پیامک یادآور';
        $is_cr = function_exists('mcr_is_credit_plan') && mcr_is_credit_plan($p);
        if ($is_cr) array_unshift($feats, '💰 ' . number_format(mcr_plan_credit($p)) . ' تومان شارژ — هزینه هر پاسخ، تصویر یا ویدیو به اندازه مصرف واقعی کم می‌شود');
        $lims = array_filter([(int)($p['daily_limit'] ?? 0) > 0 ? 'روزانه ' . number_format((int)$p['daily_limit']) : '', (int)($p['week_limit'] ?? 0) > 0 ? 'هفتگی ' . number_format((int)$p['week_limit']) : '', (int)($p['month_limit'] ?? 0) > 0 ? 'ماهانه ' . number_format((int)$p['month_limit']) : '']);
        if ($lims) $feats[] = '⏳ سقف پیام: ' . implode('، ', $lims);
        $bots = cx_plan_bots($p);
        $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'description' => $p['description'], 'price' => $pp['price'], 'original' => $pp['original'],
                  'percent' => $pp['percent'], 'until' => $pp['until'] ? substr((string)$pp['until'], 0, 10) : '', 'free' => $price === 0,
                  'messages' => $is_cr ? 0 : (int)$p['messages'], 'kind' => $is_cr ? 'credit' : 'msgs', 'credit' => $is_cr ? mcr_plan_credit($p) : 0,
                  'days' => (int)$p['days'], 'daily_limit' => (int)$p['daily_limit'], 'flags' => $fl,
                  'bots' => count($bots), 'features' => $feats];
    }
    return $out;
}

/** وضعیت فعلی مخاطب برای صفحه «بسته‌ها و ارتقا» */
function cx_member_status($pdo, $bot, $member, $quota = null)
{
    $quota = $quota ?: hd_quota($pdo, $bot, $member);
    $cur = [];
    foreach (hd_active_purchases($pdo, $bot['id'], $member['id']) as $p) {
        $cur[] = ['name' => $p['plan_name'], 'left' => max(0, (int)$p['messages'] - (int)$p['used']), 'total' => (int)$p['messages'],
                  'credit' => (int)($p['credit_toman'] ?? 0), 'credit_left' => max(0, (int)($p['credit_toman'] ?? 0) - (int)($p['credit_used'] ?? 0)),
                  'expires' => $p['expires_at'] ? substr((string)$p['expires_at'], 0, 10) : ''];
    }
    return ['mode' => $quota['mode'] ?? '', 'free_left' => $quota['free_left'] ?? 0, 'gift_left' => $quota['gift_left'] ?? 0, 'paid_left' => $quota['paid_left'] ?? 0, 'credit_left' => $quota['credit_left'] ?? 0, 'current' => $cur];
}

// =============================================================================
// گفتگوها: پوشه‌ها، ریپلای، کامنت و رسانه
// =============================================================================
function cx_threads($pdo, $bot_id, $member_id, $limit = 300)
{
    try {
        $st = $pdo->prepare("SELECT id, title, folder, created_at, updated_at FROM hd_threads WHERE bot_id=? AND member_id=? ORDER BY updated_at DESC, id DESC LIMIT " . (int)$limit);
        $st->execute([(int)$bot_id, (int)$member_id]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return hd_threads($pdo, $bot_id, $member_id, $limit); }
}

function cx_folder_clean($name)
{
    $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$name)));
    return mb_substr($name, 0, 40);
}

function cx_folders($member)
{
    $f = json_decode((string)($member['thread_folders'] ?? ''), true);
    return is_array($f) ? array_values(array_filter(array_map('cx_folder_clean', $f), 'strlen')) : [];
}

function cx_folders_save($pdo, $member_id, array $list)
{
    $list = array_slice(array_values(array_unique(array_filter(array_map('cx_folder_clean', $list), 'strlen'))), 0, 30);
    $pdo->prepare("UPDATE hd_members SET thread_folders=? WHERE id=?")->execute([json_encode($list, JSON_UNESCAPED_UNICODE), (int)$member_id]);
    return $list;
}

/** پوشه‌های مخاطب (ذخیره‌شده + پوشه‌هایی که گفتگو دارند) */
function cx_member_folders($pdo, $bot_id, $member)
{
    $list = cx_folders($member);
    try {
        $s = $pdo->prepare("SELECT DISTINCT folder FROM hd_threads WHERE bot_id=? AND member_id=? AND folder<>''");
        $s->execute([(int)$bot_id, (int)$member['id']]);
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $f) if (!in_array($f, $list, true)) $list[] = $f;
    } catch (\Throwable $e) {}
    return $list;
}

/** پیام‌های یک گفتگو + متن پیام مرجع ریپلای، کامنت‌های قابل نمایش و رسانه */
function cx_thread_messages($pdo, $thread_id, $bot_id, $member_id, $for_member = true)
{
    $rows = hd_thread_messages($pdo, $thread_id, 500);
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $by = [];
    foreach ($rows as $r) $by[(int)$r['id']] = $r;
    $extra = [];
    try {
        $s = $pdo->prepare("SELECT id, reply_to, media FROM hd_messages WHERE thread_id=?");
        $s->execute([(int)$thread_id]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $x) $extra[(int)$x['id']] = $x;
    } catch (\Throwable $e) {}
    $comments = cx_comments($pdo, $bot_id, $member_id, $ids, $for_member);
    $staff = !$for_member;
    if (!$staff) { try { $oq = $pdo->prepare("SELECT is_owner FROM hd_members WHERE id=?"); $oq->execute([(int)$member_id]); $staff = (bool)$oq->fetchColumn(); } catch (\Throwable $e) {} }
    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $rt = (int)($extra[$id]['reply_to'] ?? 0);
        $r['reply_to'] = $rt;
        if ($rt) {
            $t = $by[$rt] ?? null;
            if (!$t) { try { $q = $pdo->prepare("SELECT role, content FROM hd_messages WHERE id=? AND bot_id=? AND member_id=?"); $q->execute([$rt, (int)$bot_id, (int)$member_id]); $t = $q->fetch(\PDO::FETCH_ASSOC) ?: null; } catch (\Throwable $e) {} }
            $r['reply'] = $t ? ['role' => $t['role'], 'text' => mb_substr(hd_plain_for_speech($t['content']), 0, 160)] : null;
        }
        $md = json_decode((string)($extra[$id]['media'] ?? ''), true);
        $r['media'] = is_array($md) ? cx_media_public($pdo, $md, $bot_id, $member_id, $staff) : [];
        $r['comments'] = $comments[$id] ?? [];
    }
    unset($r);
    return $rows;
}

// --- کامنت همکاران ---
function cx_comments($pdo, $bot_id, $member_id, array $message_ids, $for_member = true)
{
    $message_ids = array_values(array_filter(array_map('intval', $message_ids)));
    if (!$message_ids) return [];
    $out = [];
    try {
        $s = $pdo->prepare("SELECT * FROM hd_msg_comments WHERE bot_id=? AND member_id=? AND message_id IN (" . implode(',', $message_ids) . ")" . ($for_member ? " AND show_member=1" : "") . " ORDER BY id ASC");
        $s->execute([(int)$bot_id, (int)$member_id]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $c) {
            $out[(int)$c['message_id']][] = ['id' => (int)$c['id'], 'author' => $c['author'] !== '' ? $c['author'] : 'همکار', 'body' => $c['body'],
                                              'private' => empty($c['show_member']), 'at' => $c['created_at']];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** ثبت کامنت همکار (فقط با اجازه مراجع) */
function cx_comment_add($pdo, $bot, $member_id, $message_id, $body, $show_member, $author, $team_id = 0)
{
    $body = trim(mb_substr((string)$body, 0, 3000));
    if (mb_strlen($body) < 2) return ['ok' => false, 'error' => 'متن کامنت را بنویسید.'];
    $m = $pdo->prepare("SELECT m.id FROM hd_messages x JOIN hd_members m ON m.id=x.member_id WHERE x.id=? AND x.bot_id=? AND x.member_id=? AND (m.consent_share=1 OR m.is_owner=1)");
    $m->execute([(int)$message_id, (int)$bot['id'], (int)$member_id]);
    if (!$m->fetchColumn()) return ['ok' => false, 'error' => 'این پیام یافت نشد یا مراجع اجازه دسترسی نداده است.'];
    $pdo->prepare("INSERT INTO hd_msg_comments (bot_id, member_id, message_id, author, author_team, body, show_member, created_at) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([(int)$bot['id'], (int)$member_id, (int)$message_id, mb_substr((string)$author, 0, 140), (int)$team_id, $body, $show_member ? 1 : 0, cx_now()]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
}

function cx_comment_del($pdo, $bot_id, $member_id, $id)
{
    $pdo->prepare("DELETE FROM hd_msg_comments WHERE id=? AND bot_id=? AND member_id=?")->execute([(int)$id, (int)$bot_id, (int)$member_id]);
}

/** کامنت‌های همکاران برای پرامپت (همه، حتی محرمانه‌ها) */
function cx_comments_prompt($pdo, $bot_id, $member_id, $limit = 25)
{
    try {
        $s = $pdo->prepare("SELECT c.body, c.author, c.show_member, c.created_at, x.role, x.content FROM hd_msg_comments c JOIN hd_messages x ON x.id=c.message_id WHERE c.bot_id=? AND c.member_id=? ORDER BY c.id DESC LIMIT " . (int)$limit);
        $s->execute([(int)$bot_id, (int)$member_id]);
        $rows = array_reverse($s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { return ''; }
    if (!$rows) return '';
    $o = "\n\n=== کامنت‌های همکاران (کارشناسان انسانی همین مجموعه) روی پیام‌های این مراجع — معتبر؛ در پاسخ‌های بعدی حتماً اعمال کن ===\n"
       . "اگر کامنتی روی پاسخ هوش مصنوعی آمده، یعنی همکار آن پاسخ را اصلاح یا تکمیل کرده است؛ از این پس مطابق کامنت پاسخ بده و در صورت لزوم اصلاح را به مراجع هم بگو. کامنت‌های «محرمانه» را اعمال کن ولی عیناً نقل نکن.\n";
    foreach ($rows as $r) {
        $o .= '[' . substr((string)$r['created_at'], 0, 10) . ' — ' . ($r['author'] ?: 'همکار') . (empty($r['show_member']) ? ' — محرمانه' : '') . '] روی '
            . ($r['role'] === 'user' ? 'پیام مراجع' : 'پاسخ هوش مصنوعی') . ' «' . mb_substr(preg_replace('/\s+/u', ' ', hd_plain_for_speech($r['content'])), 0, 180) . '»: '
            . mb_substr((string)$r['body'], 0, 1200) . "\n";
    }
    return $o . "=== پایان کامنت‌ها ===";
}

// =============================================================================
// ساخت تصویر و ویدیو در چت‌بات
// =============================================================================
function cx_media_public($pdo, array $refs, $bot_id, $member_id, $staff = false)
{
    $ids = array_values(array_filter(array_map(fn($x) => (int)($x['id'] ?? 0), $refs)));
    if (!$ids) return [];
    try {
        $s = $pdo->prepare("SELECT * FROM hd_media WHERE bot_id=? AND member_id=? AND id IN (" . implode(',', $ids) . ")");
        $s->execute([(int)$bot_id, (int)$member_id]);
        return array_map(function ($m) use ($staff) {
            $b = (int)($m['bytes'] ?? 0);
            $o = ['id' => (int)$m['id'], 'kind' => $m['kind'], 'status' => $m['status'], 'error' => $m['status'] === 'failed' ? ($m['error'] ?: 'ساخت ناموفق بود.') : '',
                  'bytes' => $b, 'size' => function_exists('gen_fmt_bytes') ? gen_fmt_bytes($b) : '', 'edit' => !empty($m['is_edit'])];
            if ($staff) $o['tokens'] = (int)($m['tokens'] ?? 0) + (int)($m['tr_tokens'] ?? 0);
            return $o;
        }, $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { return []; }
}

function cx_media_dir($bot_id)
{
    $d = hd_files_dir() . '/' . (int)$bot_id . '/gen';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/** بازنویسی درخواست فارسی به پرامپت دقیق انگلیسی (در صورت خطا همان متن) */
function cx_gen_prompt($pdo, $bot, $text, $kind)
{
    $text = trim((string)$text);
    try {
        $cfg = saas_get_api_config($pdo);
        $mm = hd_member_models($pdo, $bot, ['is_owner' => 1, 'id' => 0, 'chosen_model' => 0]);
        $model = null;
        foreach ($mm['list'] as $x) if ($x['id'] === $mm['default']) $model = $x['row'];
        $sys = "Rewrite the user's request (usually Persian) as one detailed English prompt for an AI " . ($kind === 'video' ? 'video' : 'image')
             . " generation model: subject, style, lighting, composition" . ($kind === 'video' ? ', camera movement' : '') . ". If the user wants specific text written in the "
             . ($kind === 'video' ? 'video' : 'image') . ", keep that text exactly (in its original language) inside double quotes. Output ONLY the prompt, max 120 words.";
        $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => mb_substr($text, 0, 1500)]];
        $r = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, 300, 0.4) : aichat_call_ai($cfg, $msgs, 300, 0.4);
        if (!empty($r['ok']) && trim((string)$r['content']) !== '') {
            $t = function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $r['model_row'] ?? $model, $r) : (int)($r['tokens'] ?? 0);
            return [trim(mb_substr((string)$r['content'], 0, 1500)), (int)$t];
        }
    } catch (\Throwable $e) { error_log('[CX] gen prompt: ' . $e->getMessage()); }
    return [$text, 0];
}

/** ذخیره پیام درخواست و پاسخ رسانه‌ای در گفتگو → [thread_id, assistant_message_id, user_message_id] */
function cx_media_messages($pdo, $bot, $member, $thread_id, $prompt, $kind, $reply, $media_id, $tokens, $det, array $x = [])
{
    $now = cx_now();
    $label = !empty($x['edit']) ? '✏️ ویرایش تصویر: ' : ($kind === 'video' ? '🎬 ساخت ویدیو: ' : '🖼 ساخت تصویر: ');
    $thread = $thread_id ? hd_get_thread($pdo, $thread_id, $bot['id'], $member['id']) : null;
    if (!$thread) {
        $pdo->prepare("INSERT INTO hd_threads (bot_id, member_id, title, created_at, updated_at) VALUES(?,?,?,?,?)")
            ->execute([(int)$bot['id'], (int)$member['id'], mb_substr(($kind === 'video' ? '🎬 ' : '🖼 ') . preg_replace('/\s+/u', ' ', $prompt), 0, 60), $now, $now]);
        $tid = (int)$pdo->lastInsertId();
    } else {
        $tid = (int)$thread['id'];
        $pdo->prepare("UPDATE hd_threads SET updated_at=? WHERE id=?")->execute([$now, $tid]);
    }
    $att = $x['att'] ?? [];
    $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, attachments, created_at) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$tid, (int)$bot['id'], (int)$member['id'], 'user', $label . $prompt, null, 0, $att ? json_encode($att, JSON_UNESCAPED_UNICODE) : null, $now]);
    $uid_msg = (int)$pdo->lastInsertId();
    if (!empty($x['reply_to'])) { try { $pdo->prepare("UPDATE hd_messages SET reply_to=? WHERE id=?")->execute([(int)$x['reply_to'], $uid_msg]); } catch (\Throwable $e) {} }
    if ($att) $pdo->prepare("UPDATE hd_files SET message_id=? WHERE id IN (" . implode(',', array_map('intval', array_column($att, 'id'))) . ") AND member_id=?")->execute([$uid_msg, (int)$member['id']]);
    $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, model_id, tok_detail, media, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$tid, (int)$bot['id'], (int)$member['id'], 'assistant', $reply, null, (int)$tokens, (int)($det['model'] ?? 0), json_encode($det['d'] ?? [], JSON_UNESCAPED_UNICODE), json_encode([['id' => (int)$media_id, 'kind' => $kind]]), $now]);
    $mid = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE hd_media SET thread_id=?, message_id=? WHERE id=?")->execute([$tid, $mid, (int)$media_id]);
    return [$tid, $mid, $uid_msg];
}

/** تصویر رسانه‌ای ساخته‌شده (برای ویرایش) → ['row','ref'] یا null */
function cx_media_image($pdo, $bot_id, $member_id, $media_id)
{
    $s = $pdo->prepare("SELECT * FROM hd_media WHERE id=? AND bot_id=? AND member_id=? AND kind='image' AND status='done'");
    $s->execute([(int)$media_id, (int)$bot_id, (int)$member_id]);
    $m = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$m || $m['path'] === '' || strpos($m['path'], '..') !== false) return null;
    $f = hd_files_dir() . '/' . $m['path'];
    if (!is_file($f)) return null;
    $ref = gen_ref_prepare((string)file_get_contents($f), (string)$m['mime']);
    return $ref ? ['row' => $m, 'ref' => $ref] : null;
}

/** تصویر یک پیام (پیام پاسخ دارای رسانه تصویری) */
function cx_message_image($pdo, $bot_id, $member_id, $message_id)
{
    try {
        $s = $pdo->prepare("SELECT media FROM hd_messages WHERE id=? AND bot_id=? AND member_id=?");
        $s->execute([(int)$message_id, (int)$bot_id, (int)$member_id]);
        $md = json_decode((string)$s->fetchColumn(), true);
        foreach (is_array($md) ? $md : [] as $x) if (($x['kind'] ?? '') === 'image' && ($r = cx_media_image($pdo, $bot_id, $member_id, (int)($x['id'] ?? 0)))) return $r;
    } catch (\Throwable $e) {}
    return null;
}

/** آخرین تصویر ساخته‌شده در یک گفتگو، به شرط اینکه آخرین پاسخ گفتگو باشد */
function cx_thread_last_image($pdo, $bot_id, $member_id, $thread_id)
{
    if ((int)$thread_id <= 0) return null;
    try {
        $s = $pdo->prepare("SELECT id, media FROM hd_messages WHERE thread_id=? AND bot_id=? AND member_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");
        $s->execute([(int)$thread_id, (int)$bot_id, (int)$member_id]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$r || trim((string)$r['media']) === '') return null;
        return cx_message_image($pdo, $bot_id, $member_id, (int)$r['id']);
    } catch (\Throwable $e) { return null; }
}

/**
 * ساخت یا ویرایش تصویر / شروع ساخت ویدیو برای مخاطب (به سبک گفتگو)
 * $opt: file_ids (تصویر پیوست)، reply_to (پیامی که تصویرش ویرایش شود)، size ('auto' یا ابعاد)، ctx (بررسی آخرین تصویر گفتگو)
 * هزینه: از اعتبار تومانی صاحب ربات (ترجمه + ساخت) + از سهمیه پیام مخاطب (تنظیم ربات)
 */
function cx_generate($pdo, $bot, $member, $kind, $prompt, $thread_id = 0, array $opt = [])
{
    $kind = $kind === 'video' ? 'video' : 'image';
    $prompt = trim(mb_substr((string)$prompt, 0, 1000));
    // اقدام آماده (نسخه ۴۲): مخاطب فقط دکمه را می‌زند؛ دستور و مدل‌ها از تعریف مدیر
    $ga = null; $ga_input = '';
    if (!empty($opt['action_id']) && function_exists('ga_get')) {
        $ga = ga_get($pdo, (int)$opt['action_id']);
        if (!$ga || empty($ga['is_active']) || $ga['kind'] !== $kind || empty($ga['in_bot'])) return ['ok' => false, 'error' => 'این اقدام آماده در دسترس نیست.'];
        if ($ga['ask_label'] !== '' && mb_strlen($prompt) < 1) return ['ok' => false, 'error' => '«' . $ga['ask_label'] . '» را بنویسید.'];
        $ga_input = $prompt;
        $prompt = $ga['icon'] . ' ' . $ga['title'] . ($ga_input !== '' ? ': ' . $ga_input : '');
    }
    // نمونه گالری متصل به همین اقدام آماده (نسخه ۵۲): تصویر نمونه مرجع اقدام می‌شود
    $gsa = null;
    if ($ga && !empty($opt['sample_id']) && function_exists('gal_pick_action')) {
        if (!gal_bot_on($pdo, $bot)) return ['ok' => false, 'error' => 'گالری طرح‌ها در این چت‌بات فعال نیست.'];
        [$gsa, $gx, $gerr] = gal_pick_action($pdo, $kind, (int)$opt['sample_id'], (int)$ga['id'], 'bot');
        if (!$gsa || !$gx) return ['ok' => false, 'error' => $gerr];
        $prompt = '«' . $gsa['title'] . '» — ' . $prompt;
    }
    // ساخت از روی نمونه گالری (نسخه ۵۱): نمونه + نوع اجرا (پرامپت مخفی مدیر) + اطلاعات مخاطب
    $gs = null; $gp = null; $g_text = '';
    if (!$ga && !empty($opt['sample_id']) && function_exists('gal_pick')) {
        if (!gal_bot_on($pdo, $bot)) return ['ok' => false, 'error' => 'گالری طرح‌ها در این چت‌بات فعال نیست.'];
        [$gs, $gp, $gerr] = gal_pick($pdo, $kind, (int)$opt['sample_id'], (int)($opt['preset_id'] ?? 0));
        if (!$gs || !$gp) return ['ok' => false, 'error' => $gerr];
        $g_text = $prompt;
        $prompt = '«' . $gs['title'] . '» — ' . $gp['title'] . ($g_text !== '' ? ': ' . $g_text : '');
    }
    if (!$ga && !$gs && mb_strlen($prompt) < 2) return ['ok' => false, 'error' => 'توضیح کوتاهی از ' . ($kind === 'video' ? 'ویدیوی' : 'تصویر') . ' مورد نظرتان یا تغییری که می‌خواهید بنویسید.'];
    $reason = '';
    if (empty($bot['is_active']) || !hd_owner_ok($pdo, $bot, $reason)) return ['ok' => false, 'error' => HD_MSG_UNAVAILABLE];
    gen_ensure_schema($pdo);
    $quota = hd_quota($pdo, $bot, $member);
    $flags = cx_member_flags($pdo, $bot, $member, $quota);
    if (empty($flags[$kind])) return ['ok' => false, 'error' => ($kind === 'video' ? 'ساخت ویدیو' : 'ساخت و ویرایش تصویر') . ' در بسته فعلی شما نیست؛ با ارتقای بسته می‌توانید از آن استفاده کنید.', 'upgrade' => true];
    $models = cx_gen_models($pdo, $bot, $kind);
    if (!$models) return ['ok' => false, 'error' => ($kind === 'video' ? 'ساخت ویدیو' : 'ساخت تصویر') . ' فعلاً در دسترس نیست.'];
    // نسخه ۵۹: مدل‌های فعال در بسته مخاطب (غیرفعال / سقف تمام‌شده حذف) + انتخاب مدل توسط مخاطب یا پیش‌فرض چت‌بات
    $mx_gate = function_exists('mx_gate') ? mx_gate($pdo, $bot, $member, $quota) : null;
    if ($mx_gate) {
        $want = (int)($opt['model_id'] ?? 0);
        if ($want) foreach ($models as $x) if ((int)$x['id'] === $want && !($wc = mx_check($mx_gate, $want))['ok']) return ['ok' => false, 'error' => mx_why_text($wc['why'], $x['title']), 'upgrade' => true];
        $models = mx_gen_models($pdo, $bot, $member, $kind, $mx_gate);
        if (!$models) return ['ok' => false, 'error' => 'سقف ' . ($kind === 'video' ? 'ساخت ویدیو' : 'ساخت تصویر') . ' در بسته شما تمام شده یا مدلی برای آن در بسته فعال نیست؛ بسته را تمدید یا ارتقا دهید.', 'upgrade' => true];
    }
    if (function_exists('mx_gen_order')) $models = mx_gen_order($bot, $member, $models, $kind, (int)($opt['model_id'] ?? 0));
    $ga_chain = [];
    if ($ga) {
        $ga_chain = ga_models($pdo, $ga, $models);
        if (!$ga_chain) return ['ok' => false, 'error' => 'این اقدام فعلاً در دسترس نیست.'];
    }
    $cost_msgs = max(1, (int)($kind === 'video' ? ($bot['gen_video_cost'] ?? 20) : ($bot['gen_image_cost'] ?? 5)));
    if (empty($member['is_owner']) && $quota['remaining'] >= 0 && $quota['remaining'] < $cost_msgs) {
        return ['ok' => false, 'error' => 'پیام‌های باقی‌مانده شما کافی نیست (هر ' . ($kind === 'video' ? 'ویدیو' : 'تصویر') . ' = ' . $cost_msgs . ' پیام).', 'quota' => $quota, 'upgrade' => true];
    }
    if (!$quota['allowed']) return ['ok' => false, 'error' => 'پیام‌های شما تمام شده است.', 'quota' => $quota, 'upgrade' => true];
    // شارژ تومانی (نسخه ۶۲): شارژ باید دست‌کم به اندازه برآورد هزینه این ساخت باشد
    if (empty($member['is_owner']) && ($quota['mode'] ?? '') === 'credit' && function_exists('mcr_price')) {
        $est = mcr_price($pdo, biz_media_cost($pdo, $models[0], $kind, $kind === 'video' ? 4 : 1));
        if ((int)$quota['credit_left'] < $est) return ['ok' => false, 'error' => 'شارژ شما برای ' . ($kind === 'video' ? 'ساخت ویدیو' : 'ساخت تصویر') . ' کافی نیست (حدود ' . number_format($est) . ' تومان لازم است؛ شارژ فعلی ' . number_format((int)$quota['credit_left']) . ' تومان).', 'quota' => $quota, 'upgrade' => true];
    }
    $lim = $kind === 'video' ? [3, 86400] : [10, 3600];
    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_media WHERE bot_id=? AND member_id=? AND kind=? AND created_at >= ?");
    $c->execute([(int)$bot['id'], (int)$member['id'], $kind, cx_now(-$lim[1])]);
    if ((int)$c->fetchColumn() >= $lim[0]) return ['ok' => false, 'error' => 'تعداد درخواست‌های ساخت ' . ($kind === 'video' ? 'ویدیو امروز' : 'تصویر در این ساعت') . ' زیاد است؛ کمی بعد دوباره تلاش کنید.'];
    if ($kind === 'video') {
        $b = $pdo->prepare("SELECT COUNT(*) FROM hd_media WHERE bot_id=? AND member_id=? AND kind='video' AND status IN ('queued','processing') AND created_at >= ?");
        $b->execute([(int)$bot['id'], (int)$member['id'], cx_now(-7200)]);
        if ((int)$b->fetchColumn() > 0) return ['ok' => false, 'error' => 'یک ویدیو در حال ساخت دارید؛ پس از آماده شدن آن دوباره تلاش کنید.'];
    }

    // تصویر مرجع: پیام ریپلای‌شده، فایل پیوست، یا آخرین تصویر همین گفتگو
    $ref = null; $ref_row = null; $force_edit = false; $att = []; $ctx = null;
    $reply_to = ($gs || $gsa) ? 0 : (int)($opt['reply_to'] ?? 0);
    if ($gs || $gsa) $opt['file_ids'] = [];
    if ($reply_to > 0 && ($ri = cx_message_image($pdo, $bot['id'], $member['id'], $reply_to))) {
        $ref = $ri['ref']; $ref_row = $ri['row']; $force_edit = $kind === 'image';
    }
    $fids = array_values(array_filter(array_map('intval', (array)($opt['file_ids'] ?? []))));
    if ($fids && function_exists('hd_files_for_message')) {
        foreach (hd_files_for_message($pdo, $bot['id'], $member['id'], $fids) as $f) {
            $att[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'kind' => $f['kind']];
            if (!$ref && $f['kind'] === 'image' && strpos((string)$f['path'], '..') === false && is_file(hd_files_dir() . '/' . $f['path'])) {
                $ref = gen_ref_prepare((string)file_get_contents(hd_files_dir() . '/' . $f['path']), (string)$f['mime']);
                $force_edit = false; $ref_row = null;
            }
        }
    }
    if ($gs && !empty($gp['use_ref'])) $ref = biz_sample_ref($gs);   // تصویر نمونه (یا پوستر ویدیو) مرجع مدل
    if (!$ref && $kind === 'image' && !empty($opt['ctx']) && !$ga && !$gs) $ctx = cx_thread_last_image($pdo, $bot['id'], $member['id'], $thread_id);
    if ($gsa && $ga['ref'] !== 'none' && ($sr = biz_sample_ref($gsa))) $ref = gen_ref_prepare($sr['bytes'], $sr['mime']) ?: $sr;
    if ($ga && !$gsa && !$ref && $ga['ref'] !== 'none' && ($lc = cx_thread_last_image($pdo, $bot['id'], $member['id'], $thread_id))) { $ref = $lc['ref']; $ref_row = $lc['row']; }   // آخرین تصویر همین گفتگو
    if ($gsa && $ga['ref'] === 'required' && !$ref) return ['ok' => false, 'error' => 'فایل تصویر این نمونه پیدا نشد؛ نمونه دیگری انتخاب کنید.'];
    if ($ga && $ga['ref'] === 'required' && !$ref) return ['ok' => false, 'error' => 'برای «' . $ga['title'] . '» اول یک تصویر بفرستید (📎) یا روی «⚡» زیر یکی از تصویرها بزنید.'];
    $prev_row = $ref_row ?: ($ctx['row'] ?? null);
    $prev = $prev_row ? (string)(($prev_row['final_prompt'] ?? '') ?: $prev_row['prompt']) : '';

    $cfg = saas_get_api_config($pdo);
    @set_time_limit(260);
    $tr = $ga ? ['ok' => true, 'prompt' => ga_prompt($ga, $ga_input), 'edit' => (bool)$ref, 'aspect' => '', 'is_request' => true, 'tokens' => 0, 'toman' => 0, 'model' => null]
        : ($gs ? gen_translate($pdo, mb_substr(biz_compose_prompt($gp, $gs, $g_text), 0, 3500), $kind, ['prev' => '', 'has_ref' => (bool)$ref, 'force_edit' => false, 'max_in' => 3500,
                 'direct' => !gen_need_translate($pdo, $models[0], $kind), 'ref_attached' => (bool)$ref, 'no_edit' => true])
        : gen_translate($pdo, $prompt, $kind, ['prev' => $prev, 'has_ref' => (bool)$ref || (bool)$ctx, 'force_edit' => $force_edit,
        'direct' => !gen_need_translate($pdo, $models[0], $kind), 'ref_attached' => (bool)$ref, 'need_request' => !empty($opt['need_request'])]));
    $pt = (int)$tr['toman'];
    if (!empty($opt['need_request']) && !$tr['is_request']) {
        if ($pt > 0) saas_deduct_credit($pdo, (int)$bot['user_id'], $pt, 'چت‌بات تخصصی (تشخیص درخواست تصویر): ' . $bot['name']);
        return ['ok' => false, 'not_request' => true];
    }
    $is_edit = $force_edit;
    if (!$ref && $ctx && $tr['edit']) { $ref = $ctx['ref']; $ref_row = $ctx['row']; $is_edit = true; }
    $explicit = (string)($opt['size'] ?? 'auto');
    if ($kind === 'image' && $explicit === 'auto' && $tr['aspect'] === '' && $is_edit && $ref_row && !empty($ref_row['size'])) $explicit = (string)$ref_row['size'];
    $size = gen_size($kind, $tr['aspect'], $explicit);
    $final = $tr['prompt'];
    $m = $models[0];
    $ref_dims = null;
    if ($ga) { $m = $ga_chain[0]; $is_edit = $kind === 'image' && (bool)$ref; $ref_dims = $ref ? ga_dims($ref['bytes']) : null; $size = ga_size($ga, $ref_dims, $kind, $size); }
    $now = cx_now();
    $bytes = 0; $img_tokens = 0; $note = '';
    if ($kind === 'image') {
        $r = $ga ? ga_run_image($pdo, $cfg, $ga_chain, $final, $size, $ref, $ga) : biz_image_generate($pdo, $cfg, $m, $final, $size, $ref, $gs ? ['allow_noref' => true] : []);
        if ($ga) ga_count($pdo, (int)$ga['id'], !empty($r['ok']));
        if ($ga && !empty($r['ok']) && !empty($ga['keep_dims']) && $ref_dims && ($fit = ga_fit($r['bytes'], $ref_dims[0], $ref_dims[1]))) { $r['bytes'] = $fit; $r['ext'] = 'png'; $size = $ref_dims[0] . 'x' . $ref_dims[1]; }
        if (empty($r['ok'])) {
            error_log('[CX] image: ' . ($r['error'] ?? ''));
            if ($pt > 0) saas_deduct_credit($pdo, (int)$bot['user_id'], $pt, 'چت‌بات تخصصی (ساخت تصویر): ' . $bot['name']);
            if (!empty($r['ref_required'])) return ['ok' => false, 'error' => 'این مدل روی یک تصویر کار می‌کند (مثلاً جدا کردن لایه‌های یک طرح)؛ اول تصویر را بفرستید (📎)، بعد بنویسید چه می‌خواهید. (از سهمیه شما کم نشد)'];
            if ($ref && !empty($r['ref_rejected'])) return ['ok' => false, 'error' => ($is_edit ? 'ویرایش این تصویر' : 'ساخت تصویر از روی تصویر شما') . ' انجام نشد؛ سرویس تصویر یا درخواست را نپذیرفت. درخواست را کمی تغییر دهید یا تصویر دیگری بفرستید. (از سهمیه شما کم نشد)'];
            return ['ok' => false, 'error' => ($is_edit ? 'ویرایش' : 'ساخت') . ' تصویر ممکن نشد. ' . (preg_match('/HTTP (400|422)/', (string)($r['error'] ?? '')) ? 'احتمالاً درخواست با قوانین محتوای سرویس سازگار نیست؛ آن را تغییر دهید.' : 'لطفاً کمی بعد دوباره تلاش کنید.') . ' (از سهمیه شما کم نشد)'];
        }
        $used = $r['model_row'] ?? $m;
        $ext = in_array($r['ext'] ?? 'png', ['png', 'jpg', 'webp'], true) ? $r['ext'] : 'png';
        $fname = (int)$member['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents(cx_media_dir($bot['id']) . '/' . $fname, $r['bytes']) === false) return ['ok' => false, 'error' => 'ذخیره تصویر ممکن نشد.'];
        $bytes = strlen($r['bytes']); $img_tokens = (int)($r['tokens'] ?? 0);
        if ($ref && empty($r['used_ref'])) $note = ' (تصویر مرجع توسط مدل پذیرفته نشد؛ بر اساس توضیح شما ساخته شد)';
        $toman = biz_image_cost($pdo, $used, $r);   // هزینه واقعی از توکن مصرفی (نسخه ۵۳)
        $pdo->prepare("INSERT INTO hd_media (bot_id, member_id, kind, prompt, status, model_id, path, mime, cost_msgs, cost_toman, charged, bytes, tokens, tr_tokens, final_prompt, ref_media_id, size, is_edit, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?)")
            ->execute([(int)$bot['id'], (int)$member['id'], 'image', $prompt, 'done', (int)$used['id'], (int)$bot['id'] . '/gen/' . $fname, $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext, $cost_msgs, $toman,
                       $bytes, $img_tokens, (int)$tr['tokens'], $final, (int)($ref_row['id'] ?? 0), $size, $is_edit ? 1 : 0, $now, $now]);
        $media_id = (int)$pdo->lastInsertId();
        $total = $toman + $pt;
        saas_deduct_credit($pdo, (int)$bot['user_id'], $total, 'چت‌بات تخصصی (' . ($is_edit ? 'ویرایش' : 'ساخت') . ' تصویر): ' . $bot['name']);
        $pdo->prepare("UPDATE hd_bots SET tokens_used = tokens_used + ? WHERE id=?")->execute([$total, (int)$bot['id']]);
        hd_consume_message($pdo, $bot, $member, $cost_msgs, 'bot', $total);
        if ($mx_gate) mx_count($pdo, $mx_gate, mx_main_id($used));
        $reply = $ga ? '«' . $ga['title'] . '» انجام شد ✨' : ($gs ? 'طرح شما از روی «' . $gs['title'] . '» آماده شد ✨ اگر تغییری می‌خواهید، همین‌جا بنویسید.' : (($is_edit ? 'تصویر با تغییرات درخواستی شما آماده شد ✨' : 'تصویر درخواستی شما آماده شد ✨') . $note));
        [$tid, $mid, $umid] = cx_media_messages($pdo, $bot, $member, $thread_id, $prompt, 'image', $reply, $media_id, $total,
            ['model' => (int)$used['id'], 'd' => ['m' => (string)($used['title'] ?? ''), 'u' => 't', 'img' => $toman, 'ai' => $pt]], ['edit' => $is_edit, 'reply_to' => $reply_to, 'att' => $att]);
        $status = 'done';
        // نسخه ۷۰: خروجی‌های بیشتر یک ساخت (مثل لایه‌های جداشده طرح) کنار تصویر اول در همان پیام؛ هزینه یک بار حساب شده است
        $extra_mp = [];
        if (!empty($r['extra'])) {
            $refs = [['id' => $media_id, 'kind' => 'image']];
            foreach ($r['extra'] as $xo) {
                $xext = in_array($xo['ext'] ?? 'png', ['png', 'jpg', 'webp'], true) ? $xo['ext'] : 'png';
                $xf = (int)$member['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $xext;
                if (@file_put_contents(cx_media_dir($bot['id']) . '/' . $xf, $xo['bytes']) === false) continue;
                $pdo->prepare("INSERT INTO hd_media (bot_id, member_id, kind, prompt, status, model_id, path, mime, cost_msgs, cost_toman, charged, bytes, tokens, tr_tokens, final_prompt, ref_media_id, size, is_edit, thread_id, message_id, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,0,0,1,?,0,0,?,?,?,?,?,?,?,?)")
                    ->execute([(int)$bot['id'], (int)$member['id'], 'image', $prompt, 'done', (int)$used['id'], (int)$bot['id'] . '/gen/' . $xf, $xext === 'jpg' ? 'image/jpeg' : 'image/' . $xext,
                               strlen($xo['bytes']), $final, (int)($ref_row['id'] ?? 0), $size, $is_edit ? 1 : 0, $tid, $mid, $now, $now]);
                $xid = (int)$pdo->lastInsertId();
                $refs[] = ['id' => $xid, 'kind' => 'image'];
                $extra_mp[] = ['id' => $xid, 'kind' => 'image', 'status' => 'done', 'error' => '', 'bytes' => strlen($xo['bytes']), 'size' => gen_fmt_bytes(strlen($xo['bytes']))];
            }
            if (count($refs) > 1) {
                $reply .= "\n(" . gen_fa_num(count($refs)) . ' خروجی ساخته شد؛ مثلاً لایه‌های جداگانه طرح — هر کدام جدا قابل دانلود است.)';
                $pdo->prepare("UPDATE hd_messages SET media=?, content=? WHERE id=?")->execute([json_encode($refs), $reply, $mid]);
            }
        }
    } else {
        $r = biz_video_create($pdo, $cfg, $m, $final, 4, $size, $ref);
        // اقدام آماده: اگر مدل اول نپذیرفت، مدل‌های بعدی همان اقدام (به ترتیب اولویت مدیر)
        if ($ga && empty($r['ok'])) foreach (array_slice($ga_chain, 1) as $m2) { $r2 = biz_video_create($pdo, $cfg, $m2, $final, 4, $size, $ref); if (!empty($r2['ok'])) { $r = $r2; $m = $m2; break; } }
        if ($ga) ga_count($pdo, (int)$ga['id'], !empty($r['ok']));
        if (empty($r['ok'])) {
            error_log('[CX] video: ' . ($r['error'] ?? ''));
            if ($pt > 0) saas_deduct_credit($pdo, (int)$bot['user_id'], $pt, 'چت‌بات تخصصی (ساخت ویدیو): ' . $bot['name']);
            return ['ok' => false, 'error' => 'شروع ساخت ویدیو ممکن نشد. ' . (preg_match('/HTTP (400|422)/', (string)($r['error'] ?? '')) ? 'احتمالاً درخواست با قوانین محتوای سرویس سازگار نیست.' : 'لطفاً کمی بعد دوباره تلاش کنید.') . ' (از سهمیه شما کم نشد)'];
        }
        $used = $r['model_row'] ?? $m;
        $toman = biz_media_cost($pdo, $used, 'video', 4);
        $pdo->prepare("INSERT INTO hd_media (bot_id, member_id, kind, prompt, status, job_id, model_id, mime, cost_msgs, cost_toman, charged, tr_tokens, final_prompt, ref_media_id, size, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?)")
            ->execute([(int)$bot['id'], (int)$member['id'], 'video', $prompt, 'queued', (string)$r['job_id'], (int)$used['id'], 'video/mp4', $cost_msgs, $toman, (int)$tr['tokens'], $final, (int)($ref_row['id'] ?? 0), $size, $now, $now]);
        $media_id = (int)$pdo->lastInsertId();
        if ($mx_gate) mx_count($pdo, $mx_gate, mx_main_id($used));
        $total = $toman + $pt;
        saas_deduct_credit($pdo, (int)$bot['user_id'], $total, 'چت‌بات تخصصی (ساخت ویدیو): ' . $bot['name']);
        $pdo->prepare("UPDATE hd_bots SET tokens_used = tokens_used + ? WHERE id=?")->execute([$total, (int)$bot['id']]);
        $reply = 'ویدیوی شما در حال ساخت است؛ معمولاً چند دقیقه طول می‌کشد. همین‌جا آماده می‌شود ⏳';
        [$tid, $mid, $umid] = cx_media_messages($pdo, $bot, $member, $thread_id, $prompt, 'video', $reply, $media_id, $total,
            ['model' => (int)$used['id'], 'd' => ['m' => (string)($used['title'] ?? ''), 'u' => 't', 'vid' => $toman, 'ai' => $pt]], ['reply_to' => $reply_to, 'att' => $att]);
        $status = 'queued';
    }
    $fm = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
    $fm->execute([(int)$member['id']]);
    $member2 = $fm->fetch(\PDO::FETCH_ASSOC) ?: $member;
    $q = hd_quota($pdo, $bot, $member2);
    $staff = !empty($member['is_owner']) || !empty($member['_imp']);
    $mp = ['id' => $media_id, 'kind' => $kind, 'status' => $status, 'error' => '', 'bytes' => $bytes, 'size' => gen_fmt_bytes($bytes)];
    if ($staff) $mp['tokens'] = $img_tokens + (int)$tr['tokens'];
    return ['ok' => true, 'reply' => $reply, 'thread_id' => $tid, 'message_id' => $mid, 'user_message_id' => $umid,
            'user_text' => $ga ? $prompt : ($gs ? '🖼 از روی نمونه ' . $prompt : (($is_edit ? '✏️ ویرایش تصویر: ' : ($kind === 'video' ? '🎬 ساخت ویدیو: ' : '🖼 ساخت تصویر: ')) . $prompt)), 'gen' => $kind, 'edit' => $is_edit,
            'media' => array_merge([$mp], $extra_mp ?? []), 'quota' => $q, 'ext' => hd_member_public($pdo, $bot, $member2, $q)];
}

/**
 * تشخیص خودکار در ارسال عادی پیام: «یک تصویر … بساز»، ریپلای روی تصویر، یا دستور تغییر بعد از یک تصویر
 * null = پیام عادی است و به پاسخگوی متنی می‌رود
 */
function cx_gen_route($pdo, $bot, $member, array $in)
{
    $text = trim((string)($in['message'] ?? ''));
    if ($text === '' || !empty($in['edit_id'])) return null;
    $tid = (int)($in['thread_id'] ?? 0);
    $reply_to = (int)($in['reply_to'] ?? 0);
    $fids = is_array($in['file_ids'] ?? null) ? $in['file_ids'] : array_filter(explode(',', (string)($in['file_ids'] ?? '')));
    $has_models = (bool)cx_gen_models($pdo, $bot, 'image');
    // ۱) ریپلای روی تصویر ساخته‌شده → ویرایش همان تصویر
    if ($reply_to > 0 && $has_models && cx_message_image($pdo, $bot['id'], $member['id'], $reply_to)) {
        return cx_generate($pdo, $bot, $member, 'image', $text, $tid, ['reply_to' => $reply_to, 'file_ids' => $fids, 'size' => (string)($in['size'] ?? 'auto')]);
    }
    // ۲) درخواست صریح ساخت تصویر / ویدیو
    $intent = gen_intent($text);
    if ($intent !== '' && cx_gen_models($pdo, $bot, $intent)) {
        $flags = cx_member_flags($pdo, $bot, $member);
        if (empty($flags[$intent])) return null;   // در بسته نیست: پاسخگوی متنی راهنمایی می‌کند
        $r = cx_generate($pdo, $bot, $member, $intent, $text, $tid, ['file_ids' => $fids, 'size' => (string)($in['size'] ?? 'auto'), 'ctx' => true, 'need_request' => true]);
        if (!empty($r['not_request'])) return null;
        return $r;
    }
    // ۳) دستور تغییر بلافاصله بعد از یک تصویر در همین گفتگو (تصمیم نهایی با مترجم)
    if ($tid > 0 && $has_models && !$fids && gen_edit_hint($text) && cx_thread_last_image($pdo, $bot['id'], $member['id'], $tid)) {
        $flags = cx_member_flags($pdo, $bot, $member);
        if (empty($flags['image'])) return null;
        $r = cx_generate($pdo, $bot, $member, 'image', $text, $tid, ['ctx' => true, 'need_request' => true, 'size' => (string)($in['size'] ?? 'auto')]);
        if (!empty($r['not_request'])) return null;
        return $r;
    }
    return null;
}

/** وضعیت ویدیو (و ذخیره فایل پس از آماده شدن) */
function cx_media_status($pdo, $bot, $member, $media_id)
{
    $s = $pdo->prepare("SELECT * FROM hd_media WHERE id=? AND bot_id=? AND member_id=?");
    $s->execute([(int)$media_id, (int)$bot['id'], (int)$member['id']]);
    $m = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$m) return ['ok' => false, 'error' => 'یافت نشد.'];
    if ($m['kind'] !== 'video' || in_array($m['status'], ['done', 'failed'], true)) return ['ok' => true, 'status' => $m['status'], 'error' => $m['status'] === 'failed' ? ($m['error'] ?: 'ساخت ناموفق بود.') : ''];
    if (strtotime((string)$m['updated_at']) > time() - 6 && $m['status'] !== 'queued') return ['ok' => true, 'status' => $m['status'], 'progress' => 0];
    $cfg = saas_get_api_config($pdo);
    $model = biz_model_by_id($pdo, (int)$m['model_id']);
    $prov = $model['provider'] ?? 'avalai';
    $key = biz_media_key($cfg, $prov);
    $base = biz_media_base($cfg, $prov);
    $r = biz_media_http('GET', $base . '/videos/' . rawurlencode($m['job_id']), $key, null, 30);
    $now = cx_now();
    // ویدیوی ناموفق (نسخه ۶۲): هزینه ساخت به شارژ صاحب چت‌بات برمی‌گردد (فقط یک بار)
    $fail = function ($err) use ($pdo, $bot, $m, $now) {
        $u = $pdo->prepare("UPDATE hd_media SET status='failed', error=?, updated_at=? WHERE id=? AND status<>'failed' AND charged=0");
        $u->execute([$err, $now, (int)$m['id']]);
        if ($u->rowCount() > 0 && (int)$m['cost_toman'] > 0 && function_exists('saas_add_credit'))
            saas_add_credit($pdo, (int)$bot['user_id'], (int)$m['cost_toman'], 'بازگشت هزینه ویدیوی ناموفق چت‌بات: ' . $bot['name']);
    };
    if (!$r['ok']) {
        $pdo->prepare("UPDATE hd_media SET updated_at=? WHERE id=?")->execute([$now, (int)$m['id']]);
        if (strtotime((string)$m['created_at']) < time() - 3 * 3600) { $fail('زمان ساخت ویدیو طولانی شد.'); return ['ok' => true, 'status' => 'failed', 'error' => 'زمان ساخت ویدیو طولانی شد.']; }
        return ['ok' => true, 'status' => $m['status'], 'progress' => 0];
    }
    $st = (string)($r['json']['status'] ?? '');
    if ($st === 'failed') {
        $err = mb_substr((string)($r['json']['error']['message'] ?? 'ساخت ویدیو ناموفق بود.'), 0, 290);
        $fail($err);
        return ['ok' => true, 'status' => 'failed', 'error' => 'ساخت ویدیو ناموفق بود (از سهمیه شما کم نشد).'];
    }
    if ($st !== 'completed') {
        $pdo->prepare("UPDATE hd_media SET status=?, updated_at=? WHERE id=?")->execute([$st === 'processing' || $st === 'in_progress' ? 'processing' : 'queued', $now, (int)$m['id']]);
        return ['ok' => true, 'status' => 'processing', 'progress' => (int)($r['json']['progress'] ?? 0)];
    }
    $c = biz_media_http('GET', $base . '/videos/' . rawurlencode($m['job_id']) . '/content', $key, null, 300, true);
    if (!$c['ok'] || strlen((string)$c['body']) < 1000) return ['ok' => true, 'status' => 'processing', 'progress' => 99];
    $fname = (int)$member['id'] . '_' . bin2hex(random_bytes(8)) . '.mp4';
    if (@file_put_contents(cx_media_dir($bot['id']) . '/' . $fname, $c['body']) === false) return ['ok' => true, 'status' => 'processing', 'progress' => 99];
    $u = $pdo->prepare("UPDATE hd_media SET status='done', path=?, charged=1, updated_at=? WHERE id=? AND charged=0");
    $u->execute([(int)$bot['id'] . '/gen/' . $fname, $now, (int)$m['id']]);
    try { $pdo->prepare("UPDATE hd_media SET bytes=? WHERE id=?")->execute([strlen($c['body']), (int)$m['id']]); } catch (\Throwable $e) {}
    if ($u->rowCount() > 0) {
        hd_consume_message($pdo, $bot, $member, max(1, (int)$m['cost_msgs']), 'bot', (int)$m['cost_toman']);
        if ($m['message_id']) $pdo->prepare("UPDATE hd_messages SET content=? WHERE id=?")->execute(['ویدیوی درخواستی شما آماده شد 🎬', (int)$m['message_id']]);
    }
    return ['ok' => true, 'status' => 'done', 'item' => ['bytes' => strlen($c['body']), 'size' => function_exists('gen_fmt_bytes') ? gen_fmt_bytes(strlen($c['body'])) : '']];
}

/** دریافت فایل رسانه (فقط برای صاحب همان گفتگو) */
function cx_media_get($pdo, $bot, $member, $media_id)
{
    $s = $pdo->prepare("SELECT * FROM hd_media WHERE id=? AND bot_id=? AND member_id=? AND status='done'");
    $s->execute([(int)$media_id, (int)$bot['id'], (int)$member['id']]);
    $m = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$m || $m['path'] === '' || strpos($m['path'], '..') !== false) return ['ok' => false, 'error' => 'یافت نشد.'];
    $f = hd_files_dir() . '/' . $m['path'];
    if (!is_file($f)) return ['ok' => false, 'error' => 'فایل یافت نشد.'];
    return ['ok' => true, 'mime' => $m['mime'] ?: 'application/octet-stream', 'data' => base64_encode((string)file_get_contents($f))];
}

/** حذف رسانه‌های پیام‌های حذف‌شده */
function cx_media_delete_for_messages($pdo, $bot_id, $member_id, array $message_ids)
{
    $message_ids = array_values(array_filter(array_map('intval', $message_ids)));
    if (!$message_ids) return;
    try {
        $s = $pdo->prepare("SELECT id, path FROM hd_media WHERE bot_id=? AND member_id=? AND message_id IN (" . implode(',', $message_ids) . ")");
        $s->execute([(int)$bot_id, (int)$member_id]);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $m) {
            if ($m['path'] !== '' && strpos($m['path'], '..') === false) @unlink(hd_files_dir() . '/' . $m['path']);
            $pdo->prepare("DELETE FROM hd_media WHERE id=?")->execute([(int)$m['id']]);
        }
        $pdo->prepare("DELETE FROM hd_msg_comments WHERE bot_id=? AND member_id=? AND message_id IN (" . implode(',', $message_ids) . ")")->execute([(int)$bot_id, (int)$member_id]);
    } catch (\Throwable $e) {}
}

// =============================================================================
// صفحه یکپارچه چت‌بات‌های یک صاحب حساب (یک لینک برای همه)
// =============================================================================
/** ربات‌هایی که در صفحه یکپارچه نمایش داده می‌شوند */
function cx_hub_bots($pdo, $bot)
{
    try {
        $s = $pdo->prepare("SELECT * FROM hd_bots WHERE user_id=? AND is_active=1 AND (hub_show=1 OR id=?) ORDER BY id ASC LIMIT 30");
        $s->execute([(int)$bot['user_id'], (int)$bot['id']]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return [$bot]; }
}

/** ربات مقصد (همان صاحب، نمایش در صفحه یکپارچه) */
function cx_hub_target($pdo, $home, $via)
{
    $via = (int)$via;
    if ($via <= 0 || $via === (int)$home['id']) return null;
    $s = $pdo->prepare("SELECT * FROM hd_bots WHERE id=? AND user_id=? AND hub_show=1 AND is_active=1");
    $s->execute([$via, (int)$home['user_id']]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** فهرست برای مخاطب: «چت‌بات‌های من» (حساب دارد/خریده) و «سایر» */
function cx_hub_list($pdo, $home, $home_member)
{
    $mobile = (string)($home_member['mobile'] ?? '');
    $out = [];
    foreach (cx_hub_bots($pdo, $home) as $b) {
        $mine = (int)$b['id'] === (int)$home['id'];
        $paid = false;
        if ($mobile !== '') {
            $m = hd_member_by_mobile($pdo, $b['id'], $mobile);
            if ($m) {
                $mine = true;
                $paid = (bool)hd_active_purchases($pdo, $b['id'], $m['id']);
            } else {
                // بسته مشترک خریداری‌شده در ربات دیگر
                try {
                    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_purchases WHERE status='paid' AND mobile=? AND owner_id=? AND shared_bots LIKE ? AND (expires_at IS NULL OR expires_at > ?)");
                    $c->execute([$mobile, (int)$home['user_id'], '%,' . (int)$b['id'] . ',%', cx_now()]);
                    if ((int)$c->fetchColumn() > 0) { $mine = true; $paid = true; }
                } catch (\Throwable $e) {}
            }
        }
        $out[] = ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'specialty' => (string)$b['specialty'], 'avatar' => (string)$b['avatar_url'] !== '',
                  'avatar_v' => substr(md5((string)$b['avatar_url']), 0, 8), 'mine' => $mine, 'paid' => $paid, 'home' => (int)$b['id'] === (int)$home['id']];
    }
    usort($out, fn($a, $b) => [(int)!$a['home'], (int)!$a['mine']] <=> [(int)!$b['home'], (int)!$b['mine']]);
    return $out;
}

/**
 * ورود یک‌باره: مخاطبِ واردشده در ربات اصلی، در ربات دیگرِ همان صاحب با همان شماره وارد می‌شود.
 * اگر در ربات مقصد حسابی با این شماره هست، فقط وقتی شماره در ربات اصلی تأییدشده باشد (امنیت).
 */
function cx_hub_sso($pdo, $target, $home, $home_token)
{
    $hm = hd_member_by_token($pdo, $home['id'], $home_token);
    if (!$hm || !empty($hm['_imp']) || !empty($hm['is_owner']) || $hm['status'] !== 'active' || (string)$hm['mobile'] === '') return null;
    $m = hd_member_by_mobile($pdo, $target['id'], (string)$hm['mobile']);
    if ($m) {
        if ($m['status'] !== 'active') return null;
        // امنیت: ورود به حساب موجود فقط وقتی شماره در ربات اصلی با کد پیامکی تأیید شده باشد
        if (empty($hm['verified'])) return null;
    } else {
        $mid = hd_create_member($pdo, $target['id'], (string)$hm['mobile'], (string)$hm['name'], !empty($hm['verified']));
        $s = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
        $s->execute([$mid]);
        $m = $s->fetch(\PDO::FETCH_ASSOC);
    }
    return $m ? ['member' => $m, 'token' => hd_issue_token($pdo, (int)$m['id'])] : null;
}

/** اطلاعات عمومی ربات مقصد برای صفحه یکپارچه */
function cx_bot_config($pdo, $b)
{
    $cfg = hd_public_config($b, $pdo);
    $cfg['can_buy'] = hd_bot_sells($pdo, $b) || cx_has_free_plan($pdo, $b);
    $cfg['id'] = (int)$b['id'];
    return $cfg;
}

// =============================================================================
// پلن دلخواه کاربران سایت
// =============================================================================
/** تنظیمات قیمت‌گذاری پلن دلخواه (مدیر) */
function cx_cplan_cfg($pdo)
{
    $d = ['on' => 0, 'base' => 200000, 'days' => 365, 'feat' => [], 'models' => [],
          'qty' => [
              'max_bots' => ['label' => 'تعداد چت‌بات تخصصی', 'unit' => 1, 'price' => 150000, 'max' => 50, 'def' => 1, 'need' => 'has_expert_bot'],
              'max_knowledge_items' => ['label' => 'تعداد موضوع پایگاه دانش', 'unit' => 10, 'price' => 20000, 'max' => 2000, 'def' => 10, 'need' => 'has_knowledge_base'],
              'max_products' => ['label' => 'تعداد محصول', 'unit' => 50, 'price' => 30000, 'max' => 20000, 'def' => 50, 'need' => 'has_products'],
              'max_requests_per_day' => ['label' => 'تعداد پاسخ ویجت در روز', 'unit' => 100, 'price' => 25000, 'max' => 20000, 'def' => 100, 'need' => ''],
              'free_sms' => ['label' => 'پیامک رایگان مخاطبان', 'unit' => 100, 'price' => 20000, 'max' => 100000, 'def' => 0, 'need' => ''],
              'credit_tokens' => ['label' => 'اعتبار هدیه (تومان)', 'unit' => 10000, 'price' => 10000, 'max' => 50000000, 'def' => 0, 'need' => ''],
          ]];
    $s = function_exists('biz_get') ? json_decode((string)biz_get($pdo, 'cplan_cfg'), true) : null;
    if (is_array($s)) {
        foreach (['on', 'base', 'days'] as $k) if (isset($s[$k])) $d[$k] = (int)$s[$k];
        if (isset($s['feat']) && is_array($s['feat'])) $d['feat'] = array_map('intval', $s['feat']);
        if (isset($s['models']) && is_array($s['models'])) $d['models'] = array_map('intval', $s['models']);
        foreach ($d['qty'] as $k => $q) if (isset($s['qty'][$k]) && is_array($s['qty'][$k])) {
            foreach (['unit', 'price', 'max'] as $f) if (isset($s['qty'][$k][$f])) $d['qty'][$k][$f] = max(0, (int)$s['qty'][$k][$f]);
            $d['qty'][$k]['unit'] = max(1, $d['qty'][$k]['unit']);
        }
    }
    $d['days'] = max(30, min(730, $d['days']));
    return $d;
}

/** امکانات قابل انتخاب (همه امکانات پلن‌ها) با قیمت پیش‌فرض */
function cx_cplan_features($pdo)
{
    $cfg = cx_cplan_cfg($pdo);
    $out = [];
    foreach (saas_plan_features() as $k => $f) $out[$k] = ['label' => $f['label'], 'icon' => $f['icon'] ?? '•', 'price' => (int)($cfg['feat'][$k] ?? 50000)];
    return $out;
}

/** مدل‌های قابل انتخاب (متنی، تصویر، ویدیو) با قیمت */
function cx_cplan_models($pdo)
{
    $cfg = cx_cplan_cfg($pdo);
    $out = [];
    if (!function_exists('biz_models')) return $out;
    foreach (['text', 'image', 'video'] as $cat) {
        foreach (biz_models($pdo, true, $cat) as $m) {
            $out[(int)$m['id']] = ['id' => (int)$m['id'], 'title' => $m['title'], 'cat' => $cat, 'desc' => (string)($m['description'] ?? ''), 'price' => (int)($cfg['models'][(int)$m['id']] ?? 0)];
        }
    }
    return $out;
}

/**
 * محاسبه قیمت پلن دلخواه (سمت سرور؛ هرگز به قیمت ارسالی مرورگر اعتماد نمی‌شود)
 * @return array ['total','lines'=>[[label, price]], 'plan'=>ستون‌های پلن, 'error']
 */
function cx_cplan_quote($pdo, array $in)
{
    $cfg = cx_cplan_cfg($pdo);
    $feats = cx_cplan_features($pdo);
    $models = cx_cplan_models($pdo);
    $sel = array_values(array_intersect(array_keys($feats), (array)($in['feat'] ?? [])));
    $lines = [['پایه (ویجت پاسخگوی هوشمند سایت، ' . $cfg['days'] . ' روز)', $cfg['base']]];
    $total = $cfg['base'];
    $plan = ['response_length' => in_array($in['response_length'] ?? '', ['short', 'medium', 'long'], true) ? $in['response_length'] : 'medium'];
    foreach ($feats as $k => $f) {
        $on = in_array($k, $sel, true);
        $plan[$k] = $on ? 1 : 0;
        if ($on) { $lines[] = [$f['icon'] . ' ' . $f['label'], $f['price']]; $total += $f['price']; }
    }
    foreach ($cfg['qty'] as $k => $q) {
        if ($q['need'] !== '' && empty($plan[$q['need']])) { $plan[$k] = 0; continue; }
        $n = max(0, min($q['max'], (int)($in['qty'][$k] ?? $q['def'])));
        $units = (int)ceil($n / $q['unit']);
        $n = $units * $q['unit'];
        $plan[$k] = $n;
        if ($units > 0 && $q['price'] > 0) { $p = $units * $q['price']; $lines[] = [$q['label'] . ': ' . number_format($n), $p]; $total += $p; }
    }
    $mids = array_values(array_intersect(array_keys($models), array_map('intval', (array)($in['models'] ?? []))));
    $has_text = false;
    foreach ($mids as $id) {
        if ($models[$id]['cat'] === 'text') $has_text = true;
        if ($models[$id]['price'] > 0) { $lines[] = ['مدل «' . $models[$id]['title'] . '»', $models[$id]['price']]; $total += $models[$id]['price']; }
    }
    $error = '';
    if ($models && !$has_text) $error = 'حداقل یک مدل گفتگو (متنی) انتخاب کنید.';
    $plan['allowed_models'] = implode(',', $mids);
    $plan['max_tokens_per_request'] = ['short' => 400, 'medium' => 800, 'long' => 1500][$plan['response_length']];
    return ['total' => max(0, (int)$total), 'lines' => $lines, 'plan' => $plan, 'error' => $error];
}

/** ساخت یا به‌روزرسانی ردیف پلن دلخواه کاربر (غیرفعال برای عموم) */
function cx_cplan_save($pdo, $user, array $quote)
{
    $cols = $quote['plan'];
    $cols['name'] = 'پلن دلخواه ' . mb_substr(trim((string)($user['full_name'] ?? '')), 0, 60);
    $cols['description'] = 'ساخته‌شده توسط خود کاربر';
    $cols['price_toman'] = (int)$quote['total'];
    $cols['is_active'] = 0;
    $cols['is_custom'] = 1;
    $cols['owner_user_id'] = (int)$user['id'];
    $cols['sort_order'] = 999;
    // ستون‌هایی که در جدول وجود دارند
    $exist = [];
    try { $row = $pdo->query("SELECT * FROM saas_plans LIMIT 1")->fetch(\PDO::FETCH_ASSOC); if ($row) $exist = array_keys($row); } catch (\Throwable $e) {}
    if ($exist) $cols = array_intersect_key($cols, array_flip($exist));
    // ردیف قبلی پرداخت‌نشده همین کاربر (اگر پلن فعلی او نیست) دوباره استفاده می‌شود
    $s = $pdo->prepare("SELECT id FROM saas_plans WHERE is_custom=1 AND owner_user_id=? AND id<>? ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$user['id'], (int)($user['plan_id'] ?? 0)]);
    $pid = (int)$s->fetchColumn();
    if ($pid) {
        $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($cols)));
        $pdo->prepare("UPDATE saas_plans SET $set WHERE id=?")->execute(array_merge(array_values($cols), [$pid]));
        return $pid;
    }
    $cols['created_at'] = cx_now();
    $pdo->prepare("INSERT INTO saas_plans (`" . implode('`,`', array_keys($cols)) . "`) VALUES(" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($cols));
    return (int)$pdo->lastInsertId();
}


// =============================================================================
// نسخه ۳۳ — سقف دوره‌ای پیام (مثل Claude: «تا ساعت ۱۵:۳۰»)
// =============================================================================
/** ساعت فارسی برای پیام‌ها (امروز → «ساعت ۱۵:۳۰»، روزهای بعد → «فردا ساعت …» یا تاریخ) */
function cx_fa_time($ts)
{
    $fa = fn($s) => strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    $t = 'ساعت ' . $fa(date('H:i', $ts));
    $d = (int)floor((strtotime(date('Y-m-d 00:00:00', $ts)) - strtotime(date('Y-m-d 00:00:00'))) / 86400);
    if ($d === 1) return 'فردا ' . $t;
    if ($d > 1) return $fa($d) . ' روز دیگر ' . $t;
    return $t;
}

/**
 * سقف پیام در بازه زمانی (رایگان/هدیه: تنظیم ربات؛ بسته: تنظیم همان بسته — ۰ = بدون سقف)
 * @return array|null ['limit','hours','used','left','reset_at'(ts وقتی اولین پیام آزاد می‌شود),'full_at'(ts وقتی دوباره مجاز)]
 */
function cx_window($pdo, $bot, $member, $mode, array $purchases = [])
{
    $h = (int)($bot['win_hours'] ?? 0);
    if ($h <= 0 || !empty($member['is_owner']) || $mode === 'credit') return null;   // شارژ تومانی: سقف بازه ندارد
    $limit = 0;
    if ($mode === 'paid') {
        foreach ($purchases as $p) {
            if ((int)$p['used'] >= (int)$p['messages']) continue;
            $w = (int)($p['win_msgs'] ?? 0);
            if ($w === 0) return null;   // دست‌کم یک بسته بدون سقف
            $limit = max($limit, $w);
        }
    } else $limit = (int)($bot['win_free'] ?? 0);
    if ($limit <= 0) return null;
    $from = date('Y-m-d H:i:s', time() - $h * 3600);
    $c = $pdo->prepare("SELECT created_at FROM hd_messages WHERE bot_id=? AND member_id=? AND role='user' AND created_at >= ? ORDER BY created_at ASC, id ASC");
    $c->execute([(int)$bot['id'], (int)$member['id'], $from]);
    $times = array_map('strtotime', $c->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    $used = count($times);
    $left = max(0, $limit - $used);
    $reset = $times ? $times[0] + $h * 3600 : null;
    $full = $used >= $limit ? $times[$used - $limit] + $h * 3600 : null;
    return ['limit' => $limit, 'hours' => $h, 'used' => $used, 'left' => $left, 'reset_at' => $reset, 'full_at' => $full];
}

// =============================================================================
// نسخه ۳۳ — «تماس با کارشناس» در ویجت
// =============================================================================
function cx_contact_statuses() { return ['new' => 'جدید', 'called' => 'تماس گرفته شد', 'done' => 'انجام شد']; }

function cx_contact_save($pdo, $user_id, array $in, $conv_id = 0, $ip = '')
{
    $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 100);
    $phone = function_exists('biz_mobile') ? biz_mobile($in['phone'] ?? '') : preg_replace('/\D+/', '', (string)($in['phone'] ?? ''));
    $msg = mb_substr(trim(strip_tags((string)($in['message'] ?? ''))), 0, 2000);
    if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'نام خود را بنویسید.'];
    if ($phone === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثال: 09121234567).'];
    if (mb_strlen($msg) < 3) return ['ok' => false, 'error' => 'پیام خود را بنویسید.'];
    if (function_exists('ex_rate_limited') && ex_rate_limited('contact:' . (int)$user_id . ':' . $ip, 5, 3600)) return ['ok' => false, 'error' => 'تعداد پیام‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.'];
    $pdo->prepare("INSERT INTO saas_contact_requests (user_id, conv_id, name, phone, message, page_url, status, ip, created_at, updated_at) VALUES(?,?,?,?,?,?,'new',?,?,?)")
        ->execute([(int)$user_id, (int)$conv_id, $name, $phone, $msg, mb_substr((string)($in['page'] ?? ''), 0, 490), mb_substr((string)$ip, 0, 60), cx_now(), cx_now()]);
    $id = (int)$pdo->lastInsertId();
    if ($conv_id) { try { $pdo->prepare("UPDATE saas_conversations SET visitor_name=COALESCE(visitor_name, ?), visitor_phone=COALESCE(visitor_phone, ?) WHERE id=? AND user_id=?")->execute([$name, $phone, (int)$conv_id, (int)$user_id]); } catch (\Throwable $e) {} }
    if (function_exists('biz_notify')) biz_notify($pdo, (int)$user_id, '📞 درخواست تماس با کارشناس', $name . ' (' . $phone . '): ' . mb_substr($msg, 0, 180), 'info', 'leads.php');
    return ['ok' => true, 'id' => $id];
}

function cx_contact_new_count($pdo, $user_id)
{
    try { $s = $pdo->prepare("SELECT COUNT(*) FROM saas_contact_requests WHERE user_id=? AND status='new'"); $s->execute([(int)$user_id]); return (int)$s->fetchColumn(); } catch (\Throwable $e) { return 0; }
}

// =============================================================================
// نسخه ۳۳ — راهنما و سوالات متداول کاربر برای مخاطبان خودش (ویجت و چت‌بات‌ها)
// targets: '' = همه؛ وگرنه «w» (ویجت) و شناسه چت‌بات‌ها، مثلاً ,w,5,
// =============================================================================
function cx_user_articles($pdo, $user_id, $target, $kind = null)
{
    try { $all = ex_articles($pdo, 'user', (int)$user_id, $kind); } catch (\Throwable $e) { return []; }
    $t = ',' . $target . ',';
    return array_values(array_filter($all, fn($a) => trim((string)($a['targets'] ?? '')) === '' || strpos(',' . trim((string)$a['targets'], ',') . ',', $t) !== false));
}

/** راهنما/سوالات یک چت‌بات = موارد خود ربات + موارد عمومی صاحب ربات برای همین ربات */
function cx_bot_articles($pdo, $bot, $kind = null)
{
    $own = function_exists('ex_articles') ? ex_articles($pdo, 'bot', (int)$bot['id'], $kind) : [];
    return array_merge($own, cx_user_articles($pdo, (int)$bot['user_id'], (string)(int)$bot['id'], $kind));
}

/** متن مرتبط برای پرامپت از یک فهرست راهنما/سوال (همان منطق ex_articles_context) */
function cx_articles_text(array $arts, $question, $max_chars = 4000)
{
    if (!$arts) return '';
    $words = function_exists('ex_words') ? ex_words($question) : [];
    foreach ($arts as &$a) $a['_s'] = function_exists('ex_score') ? ex_score($words, $a['title']) * 3 + ex_score($words, $a['body']) : 0;
    unset($a);
    $total = array_sum(array_map(fn($a) => mb_strlen($a['title'] . $a['body']), $arts));
    if ($total > $max_chars) { usort($arts, fn($x, $y) => $y['_s'] <=> $x['_s']); $arts = array_values(array_filter($arts, fn($a) => $a['_s'] > 0)); }
    $out = ''; $used = 0;
    foreach ($arts as $a) {
        $room = $max_chars - $used;
        if ($room < 200) break;
        $body = mb_strlen($a['body']) > $room ? mb_substr($a['body'], 0, $room) . '…' : $a['body'];
        $b = ($a['kind'] === 'faq' ? "سوال: {$a['title']}\nپاسخ: " : "راهنما: {$a['title']}\n") . $body . "\n\n";
        $out .= $b; $used += mb_strlen($b);
    }
    return trim($out);
}

// =============================================================================
// نسخه ۳۳ — ثبت‌نام سریع از صفحه پلن (نام + موبایل + کد پیامکی)
// =============================================================================
/** حساب کاربر با موبایل تأییدشده: اگر هست همان، وگرنه ساخت حساب (ایمیل موقت؛ بعداً در پروفایل قابل تغییر) */
function cx_quick_account($pdo, $mobile, $name)
{
    $s = $pdo->prepare("SELECT id, status FROM saas_users WHERE phone=? ORDER BY id ASC LIMIT 1");
    $s->execute([$mobile]);
    if ($u = $s->fetch(\PDO::FETCH_ASSOC)) {
        if (($u['status'] ?? 'active') !== 'active') return ['ok' => false, 'error' => 'این حساب غیرفعال است؛ با پشتیبانی تماس بگیرید.'];
        return ['ok' => true, 'user_id' => (int)$u['id'], 'new' => false];
    }
    $host = strtolower((string)parse_url(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : 'http://localhost', PHP_URL_HOST)) ?: 'localhost';
    if (strpos($host, '.') === false) $host .= '.local';
    $email = 'm' . $mobile . '@' . $host;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || filter_var($host, FILTER_VALIDATE_IP)) $email = 'm' . $mobile . '@mobile.local';
    $pw = 'Q' . bin2hex(random_bytes(10)) . 'a1!';
    $r = saas_register_user($pdo, $email, $pw, mb_substr(trim($name), 0, 100), $mobile);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'user_id' => (int)$r['user_id'], 'new' => true];
}
