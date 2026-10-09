<?php
/**
 * همدم — امکانات تکمیلی چت‌بات تخصصی
 *  - پرونده (پروفایل) مراجع: تکمیل خودکار از گفتگو + ویرایش/حذف
 *  - پرسش‌های شناخت اولیه هر ربات (پیش از مشاوره)
 *  - مشاور: رضایت مراجع، یادداشت و نتیجه جلسه (خوانده‌شده توسط هوش مصنوعی)
 *  - حافظه گفتگوهای قبلی
 *  - پایگاه دانش مرکزی (ساخت مدیر سامانه؛ وصل/قطع توسط کاربر)
 *  - درصد واقع‌گرایی و پایبندی به منابع (مدیر سامانه)
 *  - فایل پیوست، پیام صوتی و پخش صوتی پاسخ، انتخاب مدل توسط مراجع
 *
 * این فایل از انتهای hamdam_lib.php بارگذاری می‌شود.
 */

if (!defined('HD_EXT_LOADED')) {
define('HD_EXT_LOADED', 1);

define('HD_MAX_FILES', 10);                    // حداکثر فایل در هر پیام
define('HD_FILE_MAX_BYTES', 5 * 1024 * 1024);  // حداکثر حجم هر فایل
define('HD_PROFILE_MAX_KEYS', 60);
define('HD_HIST_KEEP', 16);        // تعداد پیام‌های آخر گفتگو که همیشه کامل (بدون خلاصه) به هوش مصنوعی داده می‌شود
define('HD_HIST_MAX', 40);         // اگر پیام‌های خلاصه‌نشده از این بیشتر شد، پیش از پاسخ خلاصه می‌شود
define('HD_SUM_BATCH', 6);         // خلاصه‌سازی وقتی حداقل این تعداد پیام از پنجره بیرون رفته باشد
define('HD_MEM_THREADS', 10);      // تعداد گفتگوهای قبلی که در حافظه مراجع آورده می‌شود

function hd_ext_schema($pdo)
{
    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) {} };

    $addColumnIfNotExists = function ($table, $column, $definition) use ($pdo) {
        try {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) return;
            $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
            $st->execute([$table, $column]);
            if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {}
    };
    $addColumnIfNotExists('hd_bots', 'intake_questions', "TEXT NULL");
    $addColumnIfNotExists('hd_bots', 'intake_strict', "TINYINT(1) NOT NULL DEFAULT 1");
    $addColumnIfNotExists('hd_bots', 'central_sets', "VARCHAR(500) NOT NULL DEFAULT ''");
    $addColumnIfNotExists('hd_bots', 'allow_model_choice', "TINYINT(1) NOT NULL DEFAULT 1");
    $addColumnIfNotExists('hd_bots', 'voice_on', "TINYINT(1) NOT NULL DEFAULT 1");
    $addColumnIfNotExists('hd_bots', 'files_on', "TINYINT(1) NOT NULL DEFAULT 1");
    $addColumnIfNotExists('hd_bots', 'realism', "INT NOT NULL DEFAULT -1");
    $addColumnIfNotExists('hd_bots', 'grounding', "INT NOT NULL DEFAULT -1");
    $addColumnIfNotExists('hd_members', 'profile', "MEDIUMTEXT NULL");
    $addColumnIfNotExists('hd_members', 'consent_share', "TINYINT(1) NOT NULL DEFAULT 0");
    $addColumnIfNotExists('hd_members', 'consent_at', "DATETIME NULL");
    $addColumnIfNotExists('hd_members', 'chosen_model', "INT NOT NULL DEFAULT 0");
    $addColumnIfNotExists('hd_messages', 'attachments', "TEXT NULL");
    $addColumnIfNotExists('hd_messages', 'model_id', "INT NOT NULL DEFAULT 0");
    $addColumnIfNotExists('hd_sources', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
    $addColumnIfNotExists('hd_qa', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
    $addColumnIfNotExists('hd_threads', 'summary', "MEDIUMTEXT NULL");
    $addColumnIfNotExists('hd_threads', 'summary_upto', "INT NOT NULL DEFAULT 0");
    $addColumnIfNotExists('hd_threads', 'summary_lock', "DATETIME NULL");
    $addColumnIfNotExists('hd_messages', 'tok_detail', "VARCHAR(600) NULL");
    $addColumnIfNotExists('hd_members', 'voice_pending', "INT NOT NULL DEFAULT 0");
    $q("CREATE TABLE IF NOT EXISTS hd_member_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        author VARCHAR(150) NOT NULL DEFAULT '',
        author_team INT NOT NULL DEFAULT 0,
        kind VARCHAR(10) NOT NULL DEFAULT 'note',
        title VARCHAR(200) NOT NULL DEFAULT '',
        content MEDIUMTEXT NOT NULL,
        show_member TINYINT(1) NOT NULL DEFAULT 0,
        use_ai TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_member (bot_id, member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS hd_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        message_id INT NOT NULL DEFAULT 0,
        name VARCHAR(200) NOT NULL DEFAULT '',
        kind VARCHAR(8) NOT NULL DEFAULT 'doc',
        mime VARCHAR(60) NOT NULL DEFAULT '',
        path VARCHAR(300) NOT NULL DEFAULT '',
        size INT NOT NULL DEFAULT 0,
        text MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_member (bot_id, member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS hd_central_sets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('hd_auth_schema')) hd_auth_schema($pdo);
}

// =============================================================================
// تنظیمات سامانه (مدیر اصلی)
// =============================================================================
function hd_sys($pdo)
{
    $d = [
        'hd_realism' => 70, 'hd_grounding' => 80,
        'hd_stt_model' => 'gpt-4o-mini-transcribe', 'hd_tts_model' => 'gpt-4o-mini-tts', 'hd_tts_voice' => 'nova',
        'hd_voice_cost' => 300,  // تومان برای هر پیام صوتی (فقط وقتی مدل «صوت به متن» قیمت ندارد)
        'hd_tts_cost' => 800,    // تومان برای هر ۱۰۰۰ کاراکتر پخش صوتی (فقط وقتی مدل «متن به صوت» قیمت ندارد)
        'hd_file_cost' => 0,     // تومان اضافه برای هر فایل پیوست
        'hd_voice_on' => 1, 'hd_files_on' => 1,
    ];
    $s = function_exists('biz_settings') ? biz_settings($pdo) : [];
    foreach ($d as $k => $v) if (isset($s[$k]) && $s[$k] !== '') $d[$k] = is_int($v) ? (int)$s[$k] : (string)$s[$k];
    return $d;
}

/** درصد واقع‌گرایی و پایبندی به منابع مؤثر برای یک ربات */
function hd_bot_levels($pdo, $bot)
{
    $s = hd_sys($pdo);
    $r = (int)($bot['realism'] ?? -1);
    $g = (int)($bot['grounding'] ?? -1);
    return [
        'realism'   => max(0, min(100, $r >= 0 ? $r : $s['hd_realism'])),
        'grounding' => max(0, min(100, $g >= 0 ? $g : $s['hd_grounding'])),
    ];
}

// =============================================================================
// پرسش‌های شناخت اولیه
// =============================================================================
function hd_default_intake($template)
{
    $m = [
        'medical'   => ['نام' => 'اسم شما چیست؟', 'سن' => 'چند سال دارید؟', 'جنسیت' => 'جنسیت شما؟', 'قد و وزن' => 'قد و وزن شما چقدر است؟',
                        'بیماری‌های زمینه‌ای' => 'بیماری زمینه‌ای یا سابقه بیماری خاصی دارید؟', 'داروهای مصرفی' => 'در حال حاضر دارویی مصرف می‌کنید؟',
                        'مشکل فعلی' => 'مشکل یا علائم فعلی شما چیست و از کی شروع شده؟'],
        'business'  => ['نام' => 'اسم شما چیست؟', 'نوع کسب‌وکار' => 'در چه زمینه‌ای فعالیت می‌کنید؟', 'سابقه فعالیت' => 'چند وقت است این کار را انجام می‌دهید؟',
                        'اندازه تیم' => 'چند نفر در مجموعه شما کار می‌کنند؟', 'هدف یا مشکل اصلی' => 'مهم‌ترین مشکل یا هدفی که دارید چیست؟'],
        'legal'     => ['نام' => 'اسم شما چیست؟', 'موضوع' => 'موضوع حقوقی شما دقیقاً چیست؟', 'شهر' => 'در کدام شهر هستید؟',
                        'مدارک' => 'چه مدارک یا قراردادی در دست دارید؟', 'وضعیت پرونده' => 'آیا پرونده‌ای تشکیل شده است؟'],
        'education' => ['نام' => 'اسم شما چیست؟', 'سن یا مقطع تحصیلی' => 'چند سال دارید یا در چه مقطعی هستید؟', 'موضوع' => 'چه موضوعی را می‌خواهید یاد بگیرید؟',
                        'سطح فعلی' => 'الان در این موضوع در چه سطحی هستید؟', 'هدف' => 'هدفتان از یادگیری چیست؟'],
        'sales'     => ['نام' => 'اسم شما چیست؟'],
    ];
    return $m[$template] ?? ['نام' => 'اسم شما چیست؟'];
}

/** فهرست پرسش‌های شناخت ربات: [label => question] */
function hd_intake_list($bot)
{
    if (!isset($bot['intake_questions']) || $bot['intake_questions'] === null) return hd_default_intake($bot['template'] ?? 'custom');
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$bot['intake_questions']) as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $p = array_map('trim', explode('|', $ln, 2));
        $label = mb_substr($p[0], 0, 60);
        if ($label === '') continue;
        $out[$label] = mb_substr($p[1] ?? '', 0, 200);
    }
    return $out;
}

/** متن فهرست برای ویرایش در پنل */
function hd_intake_text($bot)
{
    $l = [];
    foreach (hd_intake_list($bot) as $k => $v) $l[] = $k . ($v !== '' ? ' | ' . $v : '');
    return implode("\n", $l);
}

// =============================================================================
// پرونده (پروفایل) مراجع
// =============================================================================
function hd_profile_get($member)
{
    $p = json_decode((string)($member['profile'] ?? ''), true);
    $p = is_array($p) ? $p : [];
    if (!isset($p['نام']) && trim((string)($member['name'] ?? '')) !== '' && empty($member['is_owner'])) $p = ['نام' => (string)$member['name']] + $p;
    return $p;
}

function hd_profile_clean($arr)
{
    $out = [];
    foreach ((array)$arr as $k => $v) {
        if (is_array($v)) $v = implode('، ', array_filter(array_map('strval', $v)));
        $k = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$k)));
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v)));
        if ($k === '' || mb_strlen($k) > 60) continue;
        if ($v === '' || in_array(mb_strtolower($v), ['null', 'نامشخص', 'unknown', '-', '؟'], true)) continue;
        $out[$k] = mb_substr($v, 0, 300);
        if (count($out) >= HD_PROFILE_MAX_KEYS) break;
    }
    return $out;
}

function hd_profile_save($pdo, $member_id, $profile)
{
    $profile = hd_profile_clean($profile);
    $pdo->prepare("UPDATE hd_members SET profile=? WHERE id=?")->execute([$profile ? json_encode($profile, JSON_UNESCAPED_UNICODE) : null, (int)$member_id]);
    return $profile;
}

/** ادغام اطلاعات تازه (از پاسخ هوش مصنوعی) با پرونده */
function hd_profile_merge($pdo, $member, $new)
{
    $new = hd_profile_clean($new);
    if (!$new) return hd_profile_get($member);
    $p = hd_profile_get($member);
    foreach ($new as $k => $v) $p[$k] = $v;
    return hd_profile_save($pdo, (int)$member['id'], $p);
}

/** جدا کردن بلوک مخفی اطلاعات پرونده از متن پاسخ */
function hd_extract_profile_block(&$reply)
{
    $data = [];
    if (preg_match_all('/\[\[PROFILE\]\](.*?)(\[\[\/PROFILE\]\]|$)/su', $reply, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $j = trim($m[1]);
            $j = preg_replace('/^```(json)?|```$/u', '', $j);
            $d = json_decode(trim($j), true);
            if (is_array($d)) $data = array_merge($data, $d);
        }
    }
    $reply = trim(preg_replace('/\[\[PROFILE\]\].*?(\[\[\/PROFILE\]\]|$)/su', '', $reply));
    return $data;
}

// =============================================================================
// یادداشت‌ها و نتیجه جلسات مشاور
// =============================================================================
function hd_notes($pdo, $bot_id, $member_id, $only_member_visible = false, $only_ai = false, $limit = 100)
{
    $w = "bot_id=? AND member_id=?";
    if ($only_member_visible) $w .= " AND show_member=1";
    if ($only_ai) $w .= " AND use_ai=1";
    $s = $pdo->prepare("SELECT * FROM hd_member_notes WHERE {$w} ORDER BY id DESC LIMIT " . (int)$limit);
    $s->execute([(int)$bot_id, (int)$member_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function hd_note_kinds() { return ['note' => '📝 نظر مشاور', 'session' => '🤝 نتیجه جلسه حضوری', 'plan' => '📋 برنامه / توصیه']; }

// =============================================================================
// پایگاه دانش مرکزی (منابع با bot_id منفی = -شناسه مجموعه)
// =============================================================================
function hd_central_sets($pdo, $active_only = true)
{
    try {
        $rows = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM hd_chunks c WHERE c.bot_id = -s.id) AS chunks, (SELECT COUNT(*) FROM hd_sources x WHERE x.bot_id = -s.id) AS sources
            FROM hd_central_sets s" . ($active_only ? " WHERE s.is_active=1" : "") . " ORDER BY s.sort_order ASC, s.id ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $rows = []; }
    return $rows;
}

/** شناسه‌های دانش قابل جستجو برای یک ربات: خود ربات + مجموعه‌های مرکزی متصل و فعال */
function hd_bot_kb_ids($pdo, $bot)
{
    $ids = [(int)$bot['id']];
    $want = array_filter(array_map('intval', explode(',', (string)($bot['central_sets'] ?? ''))));
    // مجموعه‌هایی که مدیر برای نوع این ربات تعیین کرده (خودکار؛ مگر صاحب ربات خاموش کرده باشد)
    $off = function_exists('kb_bot_off_sets') ? kb_bot_off_sets($bot) : [];
    $tpl = (string)($bot['template'] ?? 'custom');
    foreach (hd_central_sets($pdo, true) as $s) {
        $sid = (int)$s['id'];
        $auto = function_exists('kb_set_templates') && in_array($tpl, kb_set_templates($s), true);
        if (in_array($sid, $want, true) || ($auto && !in_array($sid, $off, true))) $ids[] = -$sid;
    }
    return array_values(array_unique($ids));
}

// =============================================================================
// حافظه بلندمدت مشاوره
//  - در هر گفتگو، پیام‌های قدیمی (بیرون از ۱۶ پیام آخر) با هوش مصنوعی «خلاصه کامل» می‌شوند
//    و خلاصه + همه پیام‌های خلاصه‌نشده به مدل داده می‌شود؛ پس هیچ بخشی از گفتگو گم نمی‌شود.
//  - گفتگوهای قبلی همان مراجع هم (خلاصه + پیام‌های آخر) در هر پاسخ در اختیار مدل است.
//  - خلاصه‌سازی بعد از ارسال پاسخ (در پس‌زمینه) انجام می‌شود تا پاسخ کند نشود.
// =============================================================================
/** پیام‌های خلاصه‌نشده گفتگو (به ترتیب زمان) */
function hd_thread_history($pdo, $thread)
{
    $st = $pdo->prepare("SELECT id, role, content, attachments FROM hd_messages WHERE thread_id=? AND id>? ORDER BY id ASC");
    $st->execute([(int)$thread['id'], (int)($thread['summary_upto'] ?? 0)]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/** متن یک پیام برای خلاصه‌سازی / حافظه */
function hd_mem_line($r, $max)
{
    $t = trim(preg_replace('/[ \t]+/u', ' ', (string)$r['content']));
    $t = preg_replace('/\n{3,}/u', "\n\n", $t);
    $who = $r['role'] === 'user' ? 'مراجع' : 'مشاور (تو)';
    if ($r['role'] === 'user' && !empty($r['attachments'])) {
        $an = array_column(json_decode((string)$r['attachments'], true) ?: [], 'name');
        if ($an) $t .= ' (فایل‌های پیوست: ' . implode('، ', $an) . ')';
    }
    return $who . ': ' . (mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t);
}

/**
 * خلاصه‌سازی گفتگو تا پیام $upto_id (شامل آن) و ادغام با خلاصه قبلی.
 * هزینه به حساب صاحب ربات ثبت و روی آخرین پاسخِ خلاصه‌شده نمایش داده می‌شود.
 * خروجی: تعداد توکن مصرفی (۰ = انجام نشد)
 */
function hd_summarize_thread($pdo, $bot, $model, $thread_id, $upto_id = 0)
{
    $st = $pdo->prepare("SELECT * FROM hd_threads WHERE id=? AND bot_id=?");
    $st->execute([(int)$thread_id, (int)$bot['id']]);
    $th = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$th) return 0;
    // قفل ساده تا دو درخواست هم‌زمان یک گفتگو را دو بار خلاصه نکنند
    $lk = $pdo->prepare("UPDATE hd_threads SET summary_lock=? WHERE id=? AND (summary_lock IS NULL OR summary_lock < ?)");
    $lk->execute([hd_now(), (int)$th['id'], hd_now(-180)]);
    if ($lk->rowCount() < 1) return 0;
    $unlock = function () use ($pdo, $th) { $pdo->prepare("UPDATE hd_threads SET summary_lock=NULL WHERE id=?")->execute([(int)$th['id']]); };

    $w = "thread_id=? AND id>?" . ($upto_id ? " AND id<=?" : "");
    $ms = $pdo->prepare("SELECT id, role, content, attachments, created_at FROM hd_messages WHERE {$w} ORDER BY id ASC");
    $ms->execute($upto_id ? [(int)$th['id'], (int)$th['summary_upto'], (int)$upto_id] : [(int)$th['id'], (int)$th['summary_upto']]);
    $rows = $ms->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$rows) { $unlock(); return 0; }

    // نسخه ۴۱: خلاصه دقیق «همین بخش» → بایگانی دائمی (hd_mem_segments) + افزوده به خلاصه گفتگو
    $member_id = (int)$th['member_id'];
    $mem_on = function_exists('mem_segment_add');
    if ($mem_on) { try { mem_bootstrap($pdo, $bot, $model, $member_id); } catch (\Throwable $e) { error_log('[MEM] boot: ' . $e->getMessage()); } }
    $conv = '';
    foreach ($rows as $r) $conv .= '[' . substr((string)$r['created_at'], 0, 10) . '] ' . hd_mem_line($r, 6000) . "\n\n";
    $prev = trim((string)($th['summary'] ?? ''));
    $sys = "تو منشی ثبت پرونده مشاوره تخصصی هستی. از این بخش گفتگوی مراجع و مشاور، یک «خلاصه کامل و دقیق» به فارسی بنویس که جایگزین متن کامل این پیام‌ها شود و مشاور بعداً فقط با خواندن آن، همه‌چیز را به یاد بیاورد.\n"
        . "هیچ اطلاعات مهمی حذف نشود، به‌ویژه:\n"
        . "- مشخصات، شرایط، سوابق و وضعیت مراجع (با اعداد و جزئیات دقیق)\n"
        . "- مشکلات، نگرانی‌ها و سؤال‌هایی که مراجع مطرح کرد\n"
        . "- پاسخ‌ها، تحلیل‌ها و توصیه‌های داده‌شده با جزئیات (اعداد، دوزها، زمان‌بندی، نام‌ها، تاریخ‌ها، منابع)\n"
        . "- تصمیم‌ها، برنامه‌ها، تعهدها و کارهایی که مراجع قرار است انجام دهد\n"
        . "- موارد نیازمند پیگیری و سؤال‌های بی‌پاسخ\n"
        . "قالب: فهرست «- » به ترتیب زمانی (تاریخ‌ها را نگه دار)، کوتاه و فشرده ولی کامل. چیزی از خودت اضافه یا حدس نزن.\n"
        . "فقط متن خلاصه را بنویس.";
    $user = "=== پیام‌های گفتگو «" . $th['title'] . "» ===\n" . $conv;
    $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]];
    $cfg = saas_get_api_config($pdo);
    try {
        $ai = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, 3000, 0.2) : aichat_call_ai($cfg, $msgs, 3000, 0.2);
    } catch (\Throwable $e) { $ai = ['ok' => false]; }
    $seg = trim((string)($ai['content'] ?? ''));
    if (empty($ai['ok']) || mb_strlen($seg) < 5) { $unlock(); error_log('[HD] summary failed thread=' . $th['id']); return 0; }

    $first = $rows[0]; $last = end($rows);
    $last_id = (int)$last['id'];
    if ($mem_on) mem_segment_add($pdo, $bot['id'], $member_id, $th['id'], (int)$first['id'], $last_id, $first['created_at'], $last['created_at'], $seg);
    // خلاصه گفتگو = خلاصه قبلی + خلاصه این بخش (بدون فشرده‌سازی مکرر؛ جزئیات در بایگانی هم می‌ماند)
    $sum = trim($prev . ($prev !== '' ? "\n" : '') . $seg);
    $t = 0;
    $used_model = $ai['model_row'] ?? $model;
    $t += function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $used_model, $ai) : (int)($ai['tokens'] ?? 0);
    if (mb_strlen($sum) > (defined('MEM_THREAD_SUM_MAX') ? MEM_THREAD_SUM_MAX : 14000)) {
        // خلاصه گفتگو خیلی بلند شد → فشرده (بخش‌های قدیمی خلاصه‌تر، جدیدها کامل)؛ جزئیات کامل در بایگانی می‌ماند
        $sys2 = "این خلاصه یک گفتگوی مشاوره طولانی است. آن را به حدود نصف کوتاه کن: موارد قدیمی‌تر را ادغام و فشرده کن، موارد جدیدتر، تصمیم‌ها، برنامه‌ها، اعداد و پیگیری‌های باز را کامل نگه دار. ترتیب زمانی و تاریخ‌ها حفظ شود. چیزی اضافه نکن. فقط متن خلاصه.";
        try { $ai2 = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, [['role' => 'system', 'content' => $sys2], ['role' => 'user', 'content' => $sum]], 3800, 0.2) : ['ok' => false]; } catch (\Throwable $e) { $ai2 = ['ok' => false]; }
        $c2 = trim((string)($ai2['content'] ?? ''));
        if (!empty($ai2['ok']) && mb_strlen($c2) > 50) {
            $sum = $c2;
            $t += function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $ai2['model_row'] ?? $model, $ai2) : (int)($ai2['tokens'] ?? 0);
        } else $sum = mb_substr($sum, -(int)(defined('MEM_THREAD_SUM_MAX') ? MEM_THREAD_SUM_MAX : 14000));
    }
    $pdo->prepare("UPDATE hd_threads SET summary=?, summary_upto=?, summary_lock=NULL WHERE id=?")->execute([mb_substr($sum, 0, 60000), $last_id, (int)$th['id']]);

    // هزینه
    if ($t > 0) {
        $pdo->prepare("UPDATE hd_bots SET tokens_used = tokens_used + ? WHERE id=?")->execute([$t, (int)$bot['id']]);
        saas_deduct_credit($pdo, (int)$bot['user_id'], $t, 'حافظه چت‌بات (خلاصه گفتگو): ' . $bot['name']);
        $la = $pdo->prepare("SELECT id FROM hd_messages WHERE thread_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");
        $la->execute([(int)$th['id']]);
        if ($mid = (int)$la->fetchColumn()) hd_msg_cost_add($pdo, $mid, 'mem', $t);
    }
    return max(1, $t);
}

/** افزودن هزینه جانبی (حافظه، پخش صوتی و...) به جزئیات مصرف یک پیام */
function hd_msg_cost_add($pdo, $message_id, $key, $tokens)
{
    $st = $pdo->prepare("SELECT tok_detail FROM hd_messages WHERE id=?");
    $st->execute([(int)$message_id]);
    $d = json_decode((string)$st->fetchColumn(), true);
    $d = is_array($d) ? $d : [];
    $d[$key] = (int)($d[$key] ?? 0) + (int)$tokens;
    $pdo->prepare("UPDATE hd_messages SET tokens = tokens + ?, tok_detail=? WHERE id=?")->execute([(int)$tokens, json_encode($d, JSON_UNESCAPED_UNICODE), (int)$message_id]);
}

/**
 * نگهداری حافظه (بعد از هر پاسخ):
 *  ۱) گفتگوی جاری: اگر پیام‌های بیرون از پنجره ۱۶تایی به اندازه کافی جمع شد، خلاصه شوند
 *  ۲) گفتگوهای قبلی همان مراجع که پیام خلاصه‌نشده دارند، کامل خلاصه شوند (حداکثر ۲ مورد در هر بار)
 */
function hd_memory_maintain($pdo, $bot, $model, $member_id, $current_thread_id)
{
    $done = 0;
    // نسخه ۴۱: داده‌های قبلی (خلاصه‌های موجود) پیش از هر خلاصه‌سازی تازه به بایگانی منتقل شوند
    if (function_exists('mem_bootstrap')) { try { mem_bootstrap($pdo, $bot, $model, (int)$member_id); } catch (\Throwable $e) { error_log('[MEM] boot: ' . $e->getMessage()); } }
    if ($current_thread_id) {
        $th = $pdo->prepare("SELECT * FROM hd_threads WHERE id=?");
        $th->execute([(int)$current_thread_id]);
        if ($t = $th->fetch(\PDO::FETCH_ASSOC)) {
            $ids = array_column(hd_thread_history($pdo, $t), 'id');
            if (count($ids) >= HD_HIST_KEEP + HD_SUM_BATCH) {
                if (hd_summarize_thread($pdo, $bot, $model, (int)$t['id'], (int)$ids[count($ids) - HD_HIST_KEEP - 1])) $done++;
            }
        }
    }
    $os = $pdo->prepare("SELECT t.id FROM hd_threads t WHERE t.bot_id=? AND t.member_id=? AND t.id<>?
        AND EXISTS (SELECT 1 FROM hd_messages m WHERE m.thread_id=t.id AND m.id>t.summary_upto) ORDER BY t.updated_at DESC LIMIT " . (int)HD_MEM_THREADS);
    $os->execute([(int)$bot['id'], (int)$member_id, (int)$current_thread_id]);
    $n = 0;
    foreach ($os->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $tid) {
        if ($n >= 2) break;
        if (hd_summarize_thread($pdo, $bot, $model, (int)$tid, 0)) { $done++; $n++; }
    }
    // نسخه ۴۱: حافظه بلندمدت مراجع (راه‌اندازی برای داده‌های قدیمی + ادغام بخش‌های تازه)
    if (function_exists('mem_core_update')) {
        try { if (mem_core_update($pdo, $bot, $model, (int)$member_id)) $done++; } catch (\Throwable $e) { error_log('[MEM] maintain: ' . $e->getMessage()); }
    }
    return $done;
}

/** اجرای نگهداری حافظه بعد از ارسال پاسخ به کاربر (بدون کند کردن پاسخ) */
function hd_memory_schedule($pdo, $bot, $model, $member_id, $current_thread_id)
{
    static $scheduled = false;
    if ($scheduled) return;
    $scheduled = true;
    if (PHP_SAPI === 'cli') return;   // در اجرای خط فرمان (آزمایش‌ها) مستقیماً فراخوانی می‌شود
    register_shutdown_function(function () use ($pdo, $bot, $model, $member_id, $current_thread_id) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @ignore_user_abort(true);
        @set_time_limit(180);
        try { hd_memory_maintain($pdo, $bot, $model, $member_id, $current_thread_id); } catch (\Throwable $e) { error_log('[HD] memory: ' . $e->getMessage()); }
    });
}

/**
 * حافظه گفتگوهای قبلی مراجع: برای هر گفتگو «خلاصه کامل» + پیام‌های خلاصه‌نشده.
 * خروجی: آرایه‌ای از خطوط متن برای پرامپت
 */
function hd_member_memory($pdo, $bot_id, $member_id, $current_thread_id, $budget = 14000)
{
    $ts = $pdo->prepare("SELECT * FROM hd_threads WHERE bot_id=? AND member_id=? AND id<>? ORDER BY updated_at DESC LIMIT " . (int)HD_MEM_THREADS);
    $ts->execute([(int)$bot_id, (int)$member_id, (int)$current_thread_id]);
    $threads = $ts->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $blocks = [];
    $used = 0;
    foreach ($threads as $t) {
        $b = "■ گفتگو «" . $t['title'] . "» — " . substr((string)$t['created_at'], 0, 10) . ($t['updated_at'] !== $t['created_at'] ? ' تا ' . substr((string)$t['updated_at'], 0, 10) : '') . ":\n";
        if (trim((string)($t['summary'] ?? '')) !== '') $b .= trim($t['summary']) . "\n";
        $tail = hd_thread_history($pdo, $t);
        if ($tail) {
            $tail = array_slice($tail, -12);
            if (trim((string)($t['summary'] ?? '')) !== '') $b .= "(ادامه گفتگو:)\n";
            foreach ($tail as $r) $b .= hd_mem_line($r, $r['role'] === 'user' ? 700 : 500) . "\n";
        }
        $len = mb_strlen($b);
        if ($used + $len > $budget) {
            if (!$blocks) $blocks[] = mb_substr($b, 0, $budget);   // دست‌کم جدیدترین گفتگو
            break;
        }
        $blocks[] = $b;
        $used += $len;
    }
    return array_reverse($blocks);   // قدیمی‌تر اول
}

/** سازگاری با نسخه قبلی */
function hd_prior_memory($pdo, $bot_id, $member_id, $current_thread_id, $limit = 12)
{
    return hd_member_memory($pdo, $bot_id, $member_id, $current_thread_id);
}

// =============================================================================
// فایل‌های پیوست
// =============================================================================
function hd_files_dir()
{
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/hd_files';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}

function hd_file_types()
{
    return ['jpg' => ['image', 'image/jpeg'], 'jpeg' => ['image', 'image/jpeg'], 'png' => ['image', 'image/png'], 'webp' => ['image', 'image/webp'], 'gif' => ['image', 'image/gif'],
            'pdf' => ['doc', 'application/pdf'], 'docx' => ['doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'txt' => ['doc', 'text/plain']];
}

/** دریافت فایل مراجع (base64) و استخراج متن */
function hd_file_upload($pdo, $bot, $member, $name, $b64)
{
    $s = hd_sys($pdo);
    if (empty($s['hd_files_on']) || empty($bot['files_on'])) return ['ok' => false, 'error' => 'ارسال فایل در این گفتگو فعال نیست.'];
    $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', (string)$name));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = hd_file_types();
    if (!isset($types[$ext])) return ['ok' => false, 'error' => 'فقط تصویر (JPG، PNG، WEBP)، PDF، Word (.docx) و فایل متنی (.txt) مجاز است.'];
    $b64 = preg_replace('#^data:[^,]*,#', '', (string)$b64);
    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') return ['ok' => false, 'error' => 'فایل خوانده نشد.'];
    if (strlen($bytes) > HD_FILE_MAX_BYTES) return ['ok' => false, 'error' => 'حجم فایل بیشتر از ۵ مگابایت است.'];
    [$kind, $mime] = $types[$ext];
    if ($kind === 'image' && function_exists('getimagesizefromstring') && !@getimagesizefromstring($bytes)) return ['ok' => false, 'error' => 'فایل تصویر معتبر نیست.'];
    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_files WHERE bot_id=? AND member_id=? AND created_at >= ?");
    $c->execute([(int)$bot['id'], (int)$member['id'], hd_now(-3600)]);
    if ((int)$c->fetchColumn() >= 60) return ['ok' => false, 'error' => 'تعداد فایل‌های ارسالی زیاد است؛ کمی بعد تلاش کنید.'];
    $dir = hd_files_dir() . '/' . (int)$bot['id'];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = (int)$member['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $fname, $bytes) === false) return ['ok' => false, 'error' => 'ذخیره فایل ممکن نشد.'];
    $text = '';
    if ($kind === 'doc') {
        $text = trim((string)hd_extract_file($dir . '/' . $fname, $name));
        if (mb_strlen($text) < 5) { @unlink($dir . '/' . $fname); return ['ok' => false, 'error' => 'متنی از این فایل خوانده نشد (اگر PDF اسکن‌شده است، از صفحه‌هایش عکس بفرستید).']; }
    }
    $pdo->prepare("INSERT INTO hd_files (bot_id, member_id, name, kind, mime, path, size, text, created_at) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$bot['id'], (int)$member['id'], mb_substr($name, 0, 190), $kind, $mime, (int)$bot['id'] . '/' . $fname, strlen($bytes), $text !== '' ? mb_substr($text, 0, 200000) : null, hd_now()]);
    return ['ok' => true, 'file' => ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'kind' => $kind, 'size' => strlen($bytes)]];
}

function hd_files_for_message($pdo, $bot_id, $member_id, $ids)
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)$ids)))), 0, HD_MAX_FILES);
    if (!$ids) return [];
    $s = $pdo->prepare("SELECT * FROM hd_files WHERE bot_id=? AND member_id=? AND message_id=0 AND id IN (" . implode(',', $ids) . ")");
    $s->execute([(int)$bot_id, (int)$member_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

// =============================================================================
// صدا: تبدیل پیام صوتی به متن و خواندن پاسخ
// =============================================================================
function hd_audio_ready($pdo)
{
    $cfg = saas_get_api_config($pdo);
    if (empty($cfg['avalai_api_key']) || empty(hd_sys($pdo)['hd_voice_on'])) return false;
    // مدل «صوت به متن» فعال در صفحه مدل‌ها لازم است (اگر هیچ مدل صوتی تعریف نشده، تنظیم قدیمی استفاده می‌شود)
    if (function_exists('biz_models') && biz_models($pdo, false, 'stt')) return (bool)hd_audio_model($pdo, 'stt');
    return !empty($cfg['avalai_enabled']);
}

/** مدل فعال صوت به متن / متن به صوت (از صفحه مدل‌ها؛ null = تنظیم قدیمی) */
function hd_audio_model($pdo, $kind)
{
    if (!function_exists('biz_first_model')) return null;
    $m = biz_first_model($pdo, $kind, 'bot');
    return ($m && $m['provider'] === 'avalai') ? $m : null;
}

/** زنجیره مدل‌های صوتی (اصلی + جایگزین‌ها)؛ اگر مدلی تعریف نشده، مدل تنظیمات قدیمی */
function hd_audio_chain($pdo, $kind)
{
    $m = hd_audio_model($pdo, $kind);
    if ($m) return array_values(array_filter(biz_model_chain($pdo, $m), fn($c) => $c['provider'] === 'avalai'));
    if (function_exists('biz_models') && biz_models($pdo, false, $kind)) return [];
    $s = hd_sys($pdo);
    return [['id' => 0, 'provider' => 'avalai', 'category' => $kind, 'model_id' => $kind === 'stt' ? $s['hd_stt_model'] : $s['hd_tts_model'], 'title' => '', 'multiplier' => 1]];
}

function hd_stt($pdo, $bytes, $mime, $client_sec = 0)
{
    $cfg = saas_get_api_config($pdo);
    $chain = hd_audio_chain($pdo, 'stt');
    if (!$chain) return ['ok' => false, 'error' => 'پیام صوتی فعلاً در دسترس نیست.'];
    // نوع فایل بدون پارامتر (مثلاً audio/webm;codecs=opus → audio/webm) و تشخیص از محتوای فایل
    $mime = strtolower(trim(explode(';', (string)$mime)[0]));
    $head = substr((string)$bytes, 0, 12);
    if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) $mime = 'audio/webm';
    elseif (strncmp($head, 'OggS', 4) === 0) $mime = 'audio/ogg';
    elseif (substr($head, 4, 4) === 'ftyp') $mime = 'audio/mp4';
    elseif (strncmp($head, 'RIFF', 4) === 0) $mime = 'audio/wav';
    elseif (strncmp($head, 'ID3', 3) === 0 || (ord($head[0] ?? "\0") === 0xFF && (ord($head[1] ?? "\0") & 0xE0) === 0xE0)) $mime = 'audio/mpeg';
    $ext = strpos($mime, 'ogg') !== false ? 'ogg' : (strpos($mime, 'mp4') !== false || strpos($mime, 'm4a') !== false || strpos($mime, 'aac') !== false ? 'm4a' : (strpos($mime, 'wav') !== false ? 'wav' : (strpos($mime, 'mpeg') !== false ? 'mp3' : 'webm')));
    $clean_mime = ['ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'webm' => 'audio/webm'][$ext];
    $tmp = tempnam(sys_get_temp_dir(), 'hdv');
    $tf = $tmp . '.' . $ext;
    @rename($tmp, $tf);
    file_put_contents($tf, $bytes);
    $base = rtrim($cfg['avalai_api_base'] ?: 'https://api.avalai.ir/v1', '/');
    $text = ''; $j = null; $used = null;
    foreach (array_slice($chain, 0, 4) as $m) {
        $ch = curl_init($base . '/audio/transcriptions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['avalai_api_key']],
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($tf, $clean_mime, 'voice.' . $ext), 'model' => $m['model_id'], 'language' => 'fa', 'response_format' => 'json',
                'prompt' => 'متن فارسی گفتگوی یک مراجع با مشاور؛ با املای درست فارسی و علائم نگارشی بنویس.']]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $j = json_decode((string)$res, true);
        $text = trim((string)($j['text'] ?? ''));
        if (!empty($m['id']) && function_exists('biz_model_mark')) biz_model_mark($pdo, (int)$m['id'], $text !== '', $text === '' ? 'HTTP ' . $code . ' ' . substr((string)$res, 0, 150) : '');
        if ($text !== '') { $used = $m; if (!empty($m['id']) && function_exists('mcap_count')) mcap_count($pdo, (int)$m['id']); break; }
        error_log('[HD] stt ' . $m['model_id'] . ': ' . $code . ' ' . $err . ' ' . substr((string)$res, 0, 300));
    }
    @unlink($tf);
    if ($text === '') return ['ok' => false, 'error' => 'تبدیل صدا به متن انجام نشد؛ دوباره تلاش کنید یا تایپ کنید.'];
    // یکسان‌سازی حروف عربی به فارسی (ي ك ة → ی ک ه) و حذف متن راهنما اگر برگشت داده شد
    $text = strtr($text, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ۀ' => 'هٔ']);
    if (mb_strpos($text, 'متن فارسی گفتگوی یک مراجع') !== false) return ['ok' => false, 'error' => 'صدایی تشخیص داده نشد؛ نزدیک‌تر به میکروفون و واضح‌تر صحبت کنید.'];
    // مدت صدا (برای هزینه): از پاسخ سرویس، وگرنه زمان ضبط مرورگر (محدود به اندازه فایل)، وگرنه تخمین از حجم
    $est = strlen($bytes) / 3500;
    $sec = (float)($j['usage']['seconds'] ?? ($j['duration'] ?? 0));
    if ($sec <= 0) $sec = $client_sec > 0 ? min(max($client_sec, strlen($bytes) / 8000), 200) : $est;
    return ['ok' => true, 'text' => $text, 'seconds' => round($sec, 1), 'model' => (!empty($used['id']) ? $used : null)];
}

/** متن ساده برای خواندن (حذف قالب‌بندی و ارجاع‌ها) */
function hd_plain_for_speech($t)
{
    $t = preg_replace('/\[\[PROFILE\]\].*?\[\[\/PROFILE\]\]/su', '', (string)$t);
    $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $t);   // شکلک‌ها خوانده نشوند
    $t = preg_replace('/\[(\d{1,2}|[۰-۹]{1,2})\]/u', '', (string)$t);
    $t = preg_replace('/\[([^\]]+)\]\((https?:[^)]+)\)/u', '$1', $t);
    $t = preg_replace('#https?://\S+#u', '', $t);
    $t = str_replace(['**', '__', '`', '#', '|', '>'], '', $t);
    $t = preg_replace('/(?<!\w)[*_](\S[^*_]*?)[*_](?!\w)/u', '$1', $t);
    $t = preg_replace('/^\s*[-*•]\s+/mu', '', $t);
    return trim(preg_replace('/[ \t]+/u', ' ', $t));
}

function hd_tts($pdo, $text)
{
    $cfg = saas_get_api_config($pdo);
    $s = hd_sys($pdo);
    $chain = hd_audio_chain($pdo, 'tts');
    if (!$chain) return ['ok' => false, 'error' => 'پخش صوتی فعلاً در دسترس نیست.'];
    $text = trim((string)$text);
    if ($text === '') return ['ok' => false, 'error' => 'متنی برای خواندن وجود ندارد.'];
    $base = rtrim($cfg['avalai_api_base'] ?: 'https://api.avalai.ir/v1', '/');
    // متن طولانی: تقسیم در مرز جمله‌ها (هر بخش حداکثر حدود ۳۵۰۰ کاراکتر) و اتصال صداها
    $parts = [];
    $cur = '';
    foreach (preg_split('/(?<=[.!?؟!\n])\s+/u', mb_substr($text, 0, 12000)) as $sent) {
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($sent) > 3500) { $parts[] = $cur; $cur = ''; }
        $cur .= ($cur === '' ? '' : ' ') . $sent;
        while (mb_strlen($cur) > 3500) { $parts[] = mb_substr($cur, 0, 3500); $cur = mb_substr($cur, 3500); }
    }
    if (trim($cur) !== '') $parts[] = $cur;
    $parts = array_slice($parts, 0, 4);
    foreach (array_slice($chain, 0, 4) as $m) {
        $all = '';
        $ok = true;
        foreach ($parts as $part) {
            $body = ['model' => $m['model_id'], 'voice' => $s['hd_tts_voice'], 'input' => $part, 'response_format' => 'mp3'];
            if (strpos($m['model_id'], 'gpt-4o') !== false) $body['instructions'] = 'The text is Persian (Farsi). Read it in fluent, native Persian pronunciation (Tehrani accent), warm and clear, at a calm natural pace. Read numbers in Persian. Do not translate.';
            $ch = curl_init($base . '/audio/speech');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['avalai_api_key'], 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            $ok = !($code !== 200 || strlen((string)$res) < 500 || stripos($ctype, 'json') !== false);
            if (!$ok) { error_log('[HD] tts ' . $m['model_id'] . ': ' . $code . ' ' . substr((string)$res, 0, 200)); break; }
            $all .= $res;
        }
        if (!empty($m['id']) && function_exists('biz_model_mark')) biz_model_mark($pdo, (int)$m['id'], $ok, $ok ? '' : 'HTTP error');
        if ($ok && $all !== '' && !empty($m['id']) && function_exists('mcap_count')) mcap_count($pdo, (int)$m['id']);
        if ($ok && $all !== '') return ['ok' => true, 'bytes' => $all, 'model' => (!empty($m['id']) ? $m : null), 'chars' => array_sum(array_map('mb_strlen', $parts))];   // فقط متنی که واقعاً خوانده شد (نسخه ۶۲)
    }
    return ['ok' => false, 'error' => 'پخش صوتی ممکن نشد.'];
}

// =============================================================================
// انتخاب مدل توسط مراجع
// =============================================================================
/** مدل‌های قابل انتخاب برای مراجع با نسبت هزینه نسبت به مدل پیش‌فرض */
function hd_member_models($pdo, $bot, $member, $quota = null)
{
    if (!function_exists('biz_plan_models')) return ['list' => [], 'default' => 0, 'use' => 'bot'];
    $ou = $pdo->prepare("SELECT plan_id FROM saas_users WHERE id=?");
    $ou->execute([(int)$bot['user_id']]);
    $oplan = ($opid = (int)$ou->fetchColumn()) ? (saas_get_plan($pdo, $opid) ?: null) : null;
    $quota = $quota ?: hd_quota($pdo, $bot, $member);
    $free = empty($member['is_owner']) && ((int)($quota['free_left'] ?? 0) !== 0) && (int)($quota['paid_left'] ?? 0) === 0 && (int)($quota['gift_left'] ?? 0) === 0 && (int)($quota['credit_left'] ?? 0) === 0;
    $use = ($free && biz_plan_models($pdo, $oplan, 'text', 'free')) ? 'free' : 'bot';
    $def = biz_resolve_model($pdo, $oplan, (int)($use === 'free' ? ($bot['free_model_id'] ?? 0) : ($bot['ai_model_id'] ?? 0)), $use);
    $list = [];
    if ($def) {
        $base = max(0.0001, biz_model_factor($pdo, $def));
        $models = !empty($bot['allow_model_choice']) ? biz_plan_models($pdo, $oplan, 'text', $use) : [$def];
        foreach ($models as $m) {
            $ratio = round(biz_model_factor($pdo, $m) / $base, 1);
            $list[] = ['id' => (int)$m['id'], 'title' => $m['title'], 'desc' => (string)$m['description'], 'ratio' => $ratio,
                       'cost' => max(1, (int)ceil($ratio - 0.001)), 'row' => $m];
        }
        // ارزان‌ترین اول (نسبت قیمت به مدل پیش‌فرض)؛ قیمت برابر = همان ترتیب اولویت مدیر
        $ord = array_flip(array_column($list, 'id'));
        $fac = [];
        foreach ($list as $x) $fac[$x['id']] = biz_model_factor($pdo, $x['row']);
        usort($list, fn($a, $b) => [$fac[$a['id']], $ord[$a['id']]] <=> [$fac[$b['id']], $ord[$b['id']]]);
    }
    // تنظیم مدل‌های بسته خریداری‌شده (نسخه ۵۹): مدل غیرفعال یا با سقف تمام‌شده از فهرست حذف می‌شود
    $gate = ($use === 'bot' && function_exists('mx_gate')) ? mx_gate($pdo, $bot, $member, $quota) : null;
    $blocked = false;
    if ($gate && $list) {
        $all = $list; $list = [];
        foreach ($all as $x) { $c = mx_check($gate, $x['id']); if ($c['ok']) { $x['left'] = $c['left']; $list[] = $x; } }
        if (!$list) {
            // مدل پیش‌فرض بسته در پلن صاحب ربات هست ولی در بسته غیرفعال است: اولین مدل مجاز پلن (حتی اگر صاحب ربات انتخاب مدل را بسته باشد)
            foreach (biz_plan_models($pdo, $oplan, 'text', 'bot') as $m) {
                $c = mx_check($gate, (int)$m['id']);
                if ($c['ok']) { $list[] = ['id' => (int)$m['id'], 'title' => $m['title'], 'desc' => (string)$m['description'], 'ratio' => 1, 'cost' => 1, 'row' => $m, 'left' => $c['left']]; break; }
            }
        }
        if (!$list) $blocked = true;
        elseif (!in_array($def ? (int)$def['id'] : 0, array_column($list, 'id'), true)) $def = $list[0]['row'];
    }
    // مدل‌های تخصصی (قفل در چت رایگان؛ با ارتقای بسته فعال می‌شوند)
    $locked = [];
    if ($use === 'free') {
        $have = array_column($list, 'id');
        $spec = !empty($bot['allow_model_choice']) ? biz_plan_models($pdo, $oplan, 'text', 'bot') : array_filter([biz_resolve_model($pdo, $oplan, (int)($bot['ai_model_id'] ?? 0), 'bot')]);
        if (function_exists('biz_models_by_price')) $spec = biz_models_by_price($pdo, $spec);   // نسخه ۷۶: ارزان‌ترین اول
        foreach ($spec as $m) if (!in_array((int)$m['id'], $have, true)) $locked[] = ['id' => (int)$m['id'], 'title' => $m['title'], 'desc' => (string)$m['description']];
    }
    return ['list' => $list, 'default' => $def && !$blocked ? (int)$def['id'] : 0, 'use' => $use, 'plan' => $oplan, 'locked' => $locked, 'gate' => $gate, 'blocked' => $blocked];
}

/** اطلاعات برای صفحه چت (بدون جزئیات داخلی) */
// =============================================================================
// ویرایش و فعال/غیرفعال کردن منابع دانش (ربات‌ها و پایگاه دانش مرکزی)
// =============================================================================
/** حداکثر طول متنی که در فرم ویرایش منبع نمایش داده می‌شود */
define('HD_SRC_EDIT_MAX', 400000);

function hd_source_get($pdo, $source_id, $bot_id)
{
    $st = $pdo->prepare("SELECT * FROM hd_sources WHERE id=? AND bot_id=?");
    $st->execute([(int)$source_id, (int)$bot_id]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** روشن/خاموش کردن یک منبع؛ منبع خاموش در جستجوی پاسخ‌ها استفاده نمی‌شود */
function hd_source_toggle($pdo, $source_id, $bot_id)
{
    $src = hd_source_get($pdo, $source_id, $bot_id);
    if (!$src) return null;
    $v = (isset($src['is_active']) && (int)$src['is_active'] === 0) ? 1 : 0;
    $pdo->prepare("UPDATE hd_sources SET is_active=? WHERE id=? AND bot_id=?")->execute([$v, (int)$source_id, (int)$bot_id]);
    return (bool)$v;
}

/**
 * ویرایش منبع.
 *  - متن و فایل: عنوان و متن (متن جدید دوباره تکه‌بندی می‌شود)
 *  - صفحه و سایت: عنوان و آدرس (با تغییر آدرس، صفحات قبلی پاک و دوباره خوانده می‌شود)
 * خروجی: ['ok'=>bool, 'error'=>string, 'msg'=>string]
 */
function hd_source_update($pdo, $source_id, $bot_id, $in)
{
    $src = hd_source_get($pdo, $source_id, $bot_id);
    if (!$src) return ['ok' => false, 'error' => 'منبع یافت نشد.'];
    $sid = (int)$src['id'];
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 300);
    if (mb_strlen($title) < 2) return ['ok' => false, 'error' => 'عنوان منبع را وارد کنید.'];

    if (in_array($src['kind'], ['text', 'file'], true)) {
        $content = array_key_exists('content', $in) ? trim(str_replace("\r\n", "\n", (string)$in['content'])) : null;
        $too_big = mb_strlen((string)$src['content']) > HD_SRC_EDIT_MAX;
        if ($content === null || $too_big) {
            // فقط عنوان (متن‌های خیلی بزرگ در فرم قابل ویرایش نیستند)
            $pdo->prepare("UPDATE hd_sources SET title=? WHERE id=?")->execute([$title, $sid]);
            $pdo->prepare("UPDATE hd_chunks SET title=? WHERE source_id=? AND bot_id=?")->execute([mb_substr($title, 0, 490), $sid, (int)$bot_id]);
            return ['ok' => true, 'error' => '', 'msg' => 'عنوان منبع ذخیره شد.'];
        }
        if (mb_strlen($content) < 30) return ['ok' => false, 'error' => 'متن منبع خیلی کوتاه است (حداقل ۳۰ کاراکتر).'];
        $pdo->prepare("UPDATE hd_sources SET title=?, content=? WHERE id=?")->execute([$title, mb_substr($content, 0, 3000000), $sid]);
        $r = hd_sync_source($pdo, $sid);
        return $r['error'] !== '' ? ['ok' => false, 'error' => $r['error']] : ['ok' => true, 'error' => '', 'msg' => 'منبع ویرایش شد و دوباره پردازش شد.'];
    }

    // صفحه / سایت
    $url = trim((string)($in['url'] ?? $src['url']));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return ['ok' => false, 'error' => 'آدرس معتبر نیست.'];
    $kind = in_array($in['kind'] ?? '', ['page', 'site'], true) ? $in['kind'] : $src['kind'];
    $changed = ($url !== $src['url']) || ($kind !== $src['kind']);
    $pdo->prepare("UPDATE hd_sources SET title=?, url=?, kind=? WHERE id=?")->execute([$title, mb_substr($url, 0, 500), $kind, $sid]);
    if (!$changed) return ['ok' => true, 'error' => '', 'msg' => 'عنوان منبع ذخیره شد.'];
    foreach (['hd_chunks', 'hd_pages'] as $t) $pdo->prepare("DELETE FROM {$t} WHERE source_id=? AND bot_id=?")->execute([$sid, (int)$bot_id]);
    $pdo->prepare("UPDATE hd_sources SET status='pending', pages_total=0, pages_ok=0, last_error='' WHERE id=?")->execute([$sid]);
    @set_time_limit(120);
    $r = hd_sync_source($pdo, $sid, 30, 40);
    if ($r['error'] !== '') return ['ok' => false, 'error' => $r['error']];
    return ['ok' => true, 'error' => '', 'msg' => 'آدرس منبع تغییر کرد — ' . $r['done'] . ' صفحه خوانده شد' . ($r['remaining'] > 0 ? '؛ ' . $r['remaining'] . ' صفحه باقی مانده (همگام‌سازی را بزنید).' : '.')];
}

/** فرم ویرایش منبع (مشترک پنل کاربر و پنل مدیریت). $hidden = فیلدهای مخفی فرم (HTML) */
function hd_source_edit_form($src, $hidden, $cancel_url)
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $kinds = ['text' => 'متن', 'file' => 'فایل', 'page' => 'صفحه', 'site' => 'سایت'];
    $o = '<form method="post">' . $hidden . '<input type="hidden" name="act" value="src_edit"><input type="hidden" name="source_id" value="' . (int)$src['id'] . '">';
    $o .= '<div class="form-group"><label>عنوان</label><input type="text" name="title" maxlength="300" required value="' . $h($src['title']) . '"></div>';
    if (in_array($src['kind'], ['text', 'file'], true)) {
        if (mb_strlen((string)$src['content']) > HD_SRC_EDIT_MAX) {
            $o .= '<p style="font-size:12.5px;color:#b45309;line-height:1.9;margin:6px 0 10px">متن این ' . $kinds[$src['kind']] . ' بسیار طولانی است (' . number_format(mb_strlen((string)$src['content'])) . ' کاراکتر) و در فرم قابل ویرایش نیست؛ فقط عنوان را می‌توانید تغییر دهید. برای تغییر متن، منبع را حذف و نسخه جدید را اضافه کنید.</p>';
        } else {
            $o .= '<div class="form-group"><label>متن' . ($src['kind'] === 'file' ? ' (متن استخراج‌شده از فایل)' : '') . '</label><textarea name="content" rows="14" style="font-size:13px;line-height:1.9">' . $h($src['content']) . '</textarea></div>';
            $o .= '<p style="font-size:12px;color:#64748b;margin-bottom:10px">با ذخیره، متن دوباره پردازش می‌شود و از همین لحظه در پاسخ‌ها استفاده می‌شود.</p>';
        }
    } else {
        $o .= '<div class="form-group"><label>آدرس</label><input type="text" name="url" dir="ltr" required value="' . $h($src['url']) . '"></div>';
        $o .= '<div class="form-group"><label>نوع</label><select name="kind"><option value="site"' . ($src['kind'] === 'site' ? ' selected' : '') . '>کل سایت / بخش</option><option value="page"' . ($src['kind'] === 'page' ? ' selected' : '') . '>فقط همین صفحه</option></select></div>';
        $o .= '<p style="font-size:12px;color:#64748b;margin-bottom:10px">اگر آدرس یا نوع را تغییر دهید، صفحات قبلی پاک و از آدرس جدید دوباره خوانده می‌شود.</p>';
    }
    $o .= '<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary btn-sm" onclick="this.textContent=\'⏳ در حال ذخیره...\'">💾 ذخیره تغییرات</button><a class="btn btn-outline btn-sm" href="' . $h($cancel_url) . '">انصراف</a></div></form>';
    return $o;
}

function hd_member_public($pdo, $bot, $member, $quota = null)
{
    $mm = hd_member_models($pdo, $bot, $member, $quota);
    $chosen = (int)($member['chosen_model'] ?? 0);
    $ids = array_column($mm['list'], 'id');
    if (!in_array($chosen, $ids, true)) $chosen = $mm['default'];
    $s = hd_sys($pdo);
    $quota = $quota ?: hd_quota($pdo, $bot, $member);
    $fl = function_exists('cx_member_flags') ? cx_member_flags($pdo, $bot, $member, $quota) : ['files' => true, 'voice' => true, 'image' => false, 'video' => false];
    $voice_ok = !empty($bot['voice_on']) && hd_audio_ready($pdo);
    $files_ok = !empty($bot['files_on']) && !empty($s['hd_files_on']);
    $img_ok = function_exists('cx_gen_models') && (bool)cx_gen_models($pdo, $bot, 'image');
    $vid_ok = function_exists('cx_gen_models') && (bool)cx_gen_models($pdo, $bot, 'video');
    $locked = $mm['locked'] ?? [];
    $list = count($mm['list']) > 1 || $locked ? $mm['list'] : [];
    return [
        'models' => array_map(fn($x) => ['id' => $x['id'], 'title' => $x['title'], 'desc' => $x['desc'], 'ratio' => $x['ratio'], 'cost' => $x['cost']], $list),
        'locked_models' => $locked,
        'model' => $chosen,
        'mode' => $quota['mode'] ?? '',
        // امکان فعال (true) / قفل با پیشنهاد ارتقا ('lock') / غیرفعال (false)
        'voice' => $voice_ok && $fl['voice'],
        'files' => $files_ok && $fl['files'],
        'voice_lock' => $voice_ok && !$fl['voice'],
        'files_lock' => $files_ok && !$fl['files'],
        'gen_image' => $img_ok ? ($fl['image'] ? true : 'lock') : false,
        'gen_video' => $vid_ok ? ($fl['video'] ? true : 'lock') : false,
        'gen_cost' => ['image' => max(1, (int)($bot['gen_image_cost'] ?? 5)), 'video' => max(1, (int)($bot['gen_video_cost'] ?? 20))],
        // نسخه ۵۹: انتخاب مدل ساخت تصویر/ویدیو (اگر صاحب ربات اجازه داده و بیش از یک مدل هست) + مدل پیش‌فرض
    ] + (function_exists('mx_gen_public') && ($img_ok || $vid_ok) ? mx_gen_public($pdo, $bot, $member, $mm['gate'] ?? false) : ['gen_models' => ['image' => [], 'video' => []], 'gen_model' => ['image' => 0, 'video' => 0]]) + [
        // اقدام‌های آماده ساخت تصویر/ویدیو (نسخه ۴۲) — فقط آن‌هایی که مدلشان در پلن صاحب ربات هست
        'gen_actions' => function_exists('ga_public') ? array_merge($img_ok ? ga_public($pdo, 'image', 'bot', cx_gen_models($pdo, $bot, 'image')) : [], $vid_ok ? ga_public($pdo, 'video', 'bot', cx_gen_models($pdo, $bot, 'video')) : []) : [],
        // گالری طرح‌ها (نسخه ۵۱): انواعی که نمونه دارند و در این چت‌بات فعال‌اند
        'gallery' => function_exists('gal_bot_kinds') ? gal_bot_kinds($pdo, $bot, $img_ok, $vid_ok) : [],
        'upgrade_text' => (string)($bot['upgrade_text'] ?? ''),
        'max_files' => HD_MAX_FILES,
        'consent' => !empty($member['consent_share']),
        'consent_asked' => !empty($member['consent_at']),
        'imp' => !empty($member['_imp']),
    ] + (function_exists('sup_member_meta') ? sup_member_meta($pdo, $bot, $member) : [])
      + (function_exists('ex_member_ext') ? ex_member_ext($pdo, $bot, $member, $mm) : []);
}

} // HD_EXT_LOADED

require_once __DIR__ . '/hamdam_auth.php';

require_once __DIR__ . '/mem_lib.php';
