<?php
/**
 * حافظه بلندمدت چت‌بات تخصصی (نسخه ۴۱) — حتی با هزاران پیام، تاریخچه مخاطب فراموش نمی‌شود
 *
 *  سه لایه حافظه (علاوه بر «خلاصه گفتگو» و پیام‌های اخیر که از قبل بود):
 *   ۱) حافظه بلندمدت مخاطب (hd_members.mem_core): خلاصه فشرده و همیشه‌حاضرِ همه گفتگوها از ابتدا
 *      (مشخصات، شرایط، مشکلات، توصیه‌ها، تصمیم‌ها، برنامه‌ها، رویدادهای مهم با تاریخ). در هر پاسخ به مدل داده می‌شود.
 *   ۲) بایگانی بخش‌ها (hd_mem_segments): خلاصه دقیق هر بخش از گفتگو که هرگز فشرده یا حذف نمی‌شود.
 *   ۳) یادآوری هدفمند: برای هر پیام، مرتبط‌ترین بخش‌های بایگانی و جمله‌های اصلی پیام‌های قدیمی (حتی ماه‌ها قبل)
 *      با جستجوی کلمات کلیدی پیدا و به مدل داده می‌شود.
 *  به‌روزرسانی در همان فراخوانی خلاصه‌سازی (هزینه اضافه ناچیز) و بعد از ارسال پاسخ (بدون کند شدن پاسخ).
 *
 * این فایل از انتهای hamdam_ext.php بارگذاری می‌شود.
 */

if (!defined('MEM_SCHEMA_VERSION')) define('MEM_SCHEMA_VERSION', 1);
if (!defined('MEM_CORE_MAX')) define('MEM_CORE_MAX', 7000);          // سقف طول حافظه بلندمدت (کاراکتر)
if (!defined('MEM_THREAD_SUM_MAX')) define('MEM_THREAD_SUM_MAX', 14000);  // اگر خلاصه یک گفتگو از این بلندتر شد، فشرده می‌شود (جزئیات در بایگانی می‌ماند)

function mem_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.mem_schema_v' . MEM_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hd_mem_segments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id INT NOT NULL,
            member_id INT NOT NULL,
            thread_id INT NOT NULL DEFAULT 0,
            from_msg INT NOT NULL DEFAULT 0,
            to_msg INT NOT NULL DEFAULT 0,
            period_from DATETIME NULL,
            period_to DATETIME NULL,
            content MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_member (bot_id, member_id, id),
            INDEX idx_thread (thread_id, to_msg)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { $ok = false; error_log('[MEM] table: ' . $e->getMessage()); }
    foreach ([['mem_core', 'MEDIUMTEXT NULL'], ['mem_core_at', 'DATETIME NULL'], ['mem_core_seg', 'INT NOT NULL DEFAULT 0'], ['mem_boot', 'TINYINT(1) NOT NULL DEFAULT 0']] as [$c, $d]) {
        try { saas_add_column_if_missing($pdo, 'hd_members', $c, $d); } catch (\Throwable $e) { $ok = false; error_log('[MEM] column ' . $c . ': ' . $e->getMessage()); }
    }
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;
}

// =============================================================================
// کلمات کلیدی (فارسی)
// =============================================================================
function mem_norm($t)
{
    $t = mb_strtolower((string)$t);
    $t = strtr($t, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', "\u{200C}" => ' ', '‌' => ' ',
                    '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $t);   // اعراب
    return $t;
}

function mem_stopwords()
{
    static $s = null;
    if ($s !== null) return $s;
    $w = 'از به با در که این آن را برای تا و یا هم اما ولی اگر چه چی چطور چرا کجا کی کی چند چقدر است هست نیست بود شد شده می نمی هر همه خیلی یک یه من تو او ما شما ایشان آنها اونا اون این‌ها '
       . 'بر روی زیر پیش بعد قبل دیگه دیگر باید نباید میشه می‌شود شود کنم کنی کند کنیم کنید کنند کرد کردم کردی کردیم کردند دارم داری دارد داره داریم دارید دارند '
       . 'خوب خب سلام ممنون مرسی لطفا لطفاً بله نه آره چون پس الان حالا امروز دیروز فردا وقتی همین همون چیزی کسی جایی خودم خودت خودش مثل درباره مورد '
       . 'the a an and or of to in on for is are was were be it this that with what how why when you i my me';
    $s = array_fill_keys(preg_split('/\s+/u', mem_norm($w)), 1);
    return $s;
}

/** کلمات کلیدی یک متن (حداکثر $max، بلندترها اول) */
function mem_keywords($text, $max = 8)
{
    $t = mem_norm($text);
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = mem_stopwords();
    $out = [];
    foreach ($parts as $p) {
        if (isset($stop[$p]) || mb_strlen($p) < 3 || preg_match('/^\d{1,2}$/', $p)) continue;
        // پسوندهای رایج جمع/مالکیت → ریشه ساده برای جستجو
        $root = preg_replace('/(های|ها|هایم|هایت|هایش|ام|ات|اش|مان|تان|شان|ترین|تر)$/u', '', $p);
        if (mb_strlen($root) >= 3) $p = $root;
        $out[$p] = max($out[$p] ?? 0, mb_strlen($p));
    }
    arsort($out);
    return array_slice(array_keys($out), 0, $max);
}

// =============================================================================
// ثبت بایگانی و حافظه بلندمدت
// =============================================================================
/** ذخیره یک بخش در بایگانی */
function mem_segment_add($pdo, $bot_id, $member_id, $thread_id, $from_msg, $to_msg, $from_at, $to_at, $text)
{
    mem_ensure_schema($pdo);
    $text = trim((string)$text);
    if (mb_strlen($text) < 5) return 0;
    $pdo->prepare("INSERT INTO hd_mem_segments (bot_id, member_id, thread_id, from_msg, to_msg, period_from, period_to, content, created_at) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$bot_id, (int)$member_id, (int)$thread_id, (int)$from_msg, (int)$to_msg, $from_at ?: null, $to_at ?: null, mb_substr($text, 0, 30000), hd_now()]);
    return (int)$pdo->lastInsertId();
}

function mem_core_get($pdo, $member_id)
{
    mem_ensure_schema($pdo);
    try { $s = $pdo->prepare("SELECT mem_core FROM hd_members WHERE id=?"); $s->execute([(int)$member_id]); return trim((string)$s->fetchColumn()); } catch (\Throwable $e) { return ''; }
}

function mem_core_set($pdo, $member_id, $text)
{
    $pdo->prepare("UPDATE hd_members SET mem_core=?, mem_core_at=? WHERE id=?")->execute([mb_substr(trim((string)$text), 0, MEM_CORE_MAX + 2000), hd_now(), (int)$member_id]);
}

/** دستورالعمل حافظه بلندمدت (مشترک) */
function mem_core_rules()
{
    return "«حافظه بلندمدت» پرونده دائمی این مراجع است که در همه گفتگوهای آینده جلوی چشم مشاور است. فقط اطلاعات ماندگار و مهم را نگه دار:\n"
         . "- مشخصات و شرایط ثابت (سن، شغل، خانواده، بیماری‌ها، داروها، محدودیت‌ها، اعداد مهم)\n"
         . "- مشکلات و اهداف اصلی، و سیر تغییر آن‌ها\n"
         . "- مهم‌ترین توصیه‌ها، تحلیل‌ها و تصمیم‌ها (با جزئیات کلیدی: عدد، دوز، زمان‌بندی)\n"
         . "- برنامه‌ها، تعهدها، پیگیری‌ها و نتیجه آن‌ها\n"
         . "- رویدادهای مهم با تاریخ (در یک بخش «سیر زمانی» به ترتیب)\n"
         . "- ترجیحات مراجع (لحن، چیزهایی که دوست ندارد، روش ارتباط)\n"
         . "هرگز اطلاعات معتبر قبلی را حذف نکن مگر اینکه اصلاح/منسوخ شده باشد (در این صورت به‌روز کن و تاریخ تغییر را بنویس). "
         . "اگر طولانی شد، موارد قدیمی و کم‌اهمیت‌تر سیر زمانی را ادغام و کوتاه کن، نه مشخصات و تصمیم‌های مهم را. حداکثر حدود " . (int)(MEM_CORE_MAX / 1000) . " هزار کاراکتر. چیزی از خودت اضافه یا حدس نزن.";
}

/**
 * ادغام بخش‌های تازه بایگانی در حافظه بلندمدت مراجع.
 * برای صرفه‌جویی: فقط وقتی دست‌کم $min بخش تازه جمع شده باشد (یا $force). ورودی بزرگ در چند مرحله.
 * @return int توکن مصرفی (۰ = کاری لازم نبود یا انجام نشد)
 */
function mem_core_update($pdo, $bot, $model, $member_id, $force = false, $min = 3)
{
    mem_ensure_schema($pdo);
    $m = $pdo->prepare("SELECT mem_core, mem_core_seg FROM hd_members WHERE id=?");
    $m->execute([(int)$member_id]);
    $row = $m->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return 0;
    $s = $pdo->prepare("SELECT id, content, period_from, created_at FROM hd_mem_segments WHERE bot_id=? AND member_id=? AND id>? ORDER BY id ASC LIMIT 200");
    $s->execute([(int)$bot['id'], (int)$member_id, (int)$row['mem_core_seg']]);
    $segs = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$segs || (!$force && count($segs) < $min && trim((string)$row['mem_core']) !== '')) return 0;
    $core = trim((string)$row['mem_core']); $tok = 0; $buf = ''; $buf_last = 0;
    $flush = function () use (&$core, &$buf, &$buf_last, &$tok, $pdo, $bot, $model, $member_id) {
        if ($buf === '') return true;
        $sys = "تو منشی ثبت پرونده مشاوره تخصصی هستی. حافظه بلندمدت فعلی مراجع (اگر هست) را با خلاصه گفتگوهای تازه ادغام کن.\n" . mem_core_rules() . "\nفقط متن حافظه بلندمدت به‌روزشده را بنویس.";
        $user = ($core !== '' ? "=== حافظه بلندمدت فعلی ===\n{$core}\n\n" : '') . "=== خلاصه گفتگوهای تازه (به ترتیب زمان) ===\n" . $buf;
        $ai = mem_ai($pdo, $bot, $model, $sys, $user, 2600);
        $c = trim((string)($ai['content'] ?? ''));
        if (empty($ai['ok']) || mb_strlen($c) < 20) return false;
        $core = $c; $tok += (int)$ai['_t'];
        $pdo->prepare("UPDATE hd_members SET mem_core=?, mem_core_at=?, mem_core_seg=? WHERE id=?")->execute([mb_substr($core, 0, MEM_CORE_MAX + 2000), hd_now(), (int)$buf_last, (int)$member_id]);
        $buf = '';
        return true;
    };
    foreach ($segs as $g) {
        $line = '■ ' . substr((string)($g['period_from'] ?: $g['created_at']), 0, 10) . ":\n" . trim($g['content']) . "\n\n";
        if ($buf !== '' && mb_strlen($buf) + mb_strlen($line) > 24000) { if (!$flush()) return $tok; }
        $buf .= mb_substr($line, 0, 24000); $buf_last = (int)$g['id'];
    }
    $flush();
    return $tok;
}

/** فراخوانی مدل برای کارهای حافظه + ثبت هزینه برای صاحب ربات */
function mem_ai($pdo, $bot, $model, $sys, $user, $max_tokens)
{
    $cfg = saas_get_api_config($pdo);
    $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]];
    try { $ai = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, $max_tokens, 0.2) : aichat_call_ai($cfg, $msgs, $max_tokens, 0.2); }
    catch (\Throwable $e) { return ['ok' => false, '_t' => 0]; }
    $ai['_t'] = 0;
    if (!empty($ai['ok'])) {
        $used = $ai['model_row'] ?? $model;
        $t = function_exists('biz_charge_tokens') ? (int)biz_charge_tokens($pdo, $used, $ai) : (int)($ai['tokens'] ?? 0);
        if ($t > 0) {
            $pdo->prepare("UPDATE hd_bots SET tokens_used = tokens_used + ? WHERE id=?")->execute([$t, (int)$bot['id']]);
            saas_deduct_credit($pdo, (int)$bot['user_id'], $t, 'حافظه چت‌بات (حافظه بلندمدت): ' . $bot['name']);
        }
        $ai['_t'] = $t;
    }
    return $ai;
}

/**
 * راه‌اندازی برای داده‌های قدیمی (یک بار برای هر مخاطب): خلاصه‌های موجود گفتگوها → بایگانی، سپس ساخت حافظه بلندمدت
 * @return int توکن مصرفی
 */
function mem_bootstrap($pdo, $bot, $model, $member_id)
{
    mem_ensure_schema($pdo);
    $m = $pdo->prepare("SELECT mem_boot, mem_core FROM hd_members WHERE id=?");
    $m->execute([(int)$member_id]);
    $row = $m->fetch(\PDO::FETCH_ASSOC);
    if (!$row || !empty($row['mem_boot'])) return 0;
    $pdo->prepare("UPDATE hd_members SET mem_boot=1 WHERE id=?")->execute([(int)$member_id]);
    $ts = $pdo->prepare("SELECT * FROM hd_threads WHERE bot_id=? AND member_id=? AND summary_upto > 0 ORDER BY id ASC");
    $ts->execute([(int)$bot['id'], (int)$member_id]);
    foreach ($ts->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $t) {
        $ex = $pdo->prepare("SELECT COUNT(*) FROM hd_mem_segments WHERE thread_id=?");
        $ex->execute([(int)$t['id']]);
        if ((int)$ex->fetchColumn() > 0 || trim((string)$t['summary']) === '') continue;
        mem_segment_add($pdo, $bot['id'], $member_id, $t['id'], 0, (int)$t['summary_upto'], $t['created_at'], $t['updated_at'], "گفتگو «" . $t['title'] . "»:\n" . $t['summary']);
    }
    return trim((string)$row['mem_core']) === '' ? mem_core_update($pdo, $bot, $model, $member_id, true) : 0;
}

// =============================================================================
// یادآوری هدفمند (برای پرامپت)
// =============================================================================
/**
 * مرتبط‌ترین بخش‌های بایگانی و جمله‌های پیام‌های قدیمی برای پیام فعلی
 * $exclude_from: پیام‌های گفتگوی جاری از این شناسه به بعد (که کامل در پرامپت هستند) جستجو نمی‌شوند
 * @return string متن آماده پرامپت ('' = چیزی پیدا نشد)
 */
function mem_recall($pdo, $bot_id, $member_id, $query, $current_thread_id = 0, $exclude_from = 0, $budget = 7000)
{
    mem_ensure_schema($pdo);
    $kw = mem_keywords($query, 8);
    if (!$kw) return '';
    $score = function ($text) use ($kw) {
        $t = mem_norm($text); $s = 0;
        foreach ($kw as $i => $k) { $c = mb_substr_count($t, $k); if ($c) $s += (8 - $i * .5) + min(3, $c - 1) * .5 + mb_strlen($k) * .2; }
        return $s;
    };
    $likes = implode(' OR ', array_fill(0, count($kw), 'content LIKE ?'));
    $lv = array_map(fn($k) => '%' . $k . '%', $kw);
    $out = ''; $used = 0;
    // ۱) بخش‌های بایگانی
    try {
        $s = $pdo->prepare("SELECT id, thread_id, period_from, period_to, content FROM hd_mem_segments WHERE bot_id=? AND member_id=? AND ($likes) ORDER BY id DESC LIMIT 300");
        $s->execute(array_merge([(int)$bot_id, (int)$member_id], $lv));
        $segs = [];
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $g) { $g['_s'] = $score($g['content']); if ($g['_s'] > 0) $segs[] = $g; }
        usort($segs, fn($a, $b) => $b['_s'] <=> $a['_s']);
        foreach (array_slice($segs, 0, 4) as $g) {
            $b = '■ ' . substr((string)($g['period_from'] ?: ''), 0, 10) . (($g['period_to'] && substr($g['period_to'], 0, 10) !== substr((string)$g['period_from'], 0, 10)) ? ' تا ' . substr($g['period_to'], 0, 10) : '') . ":\n" . mb_substr(trim($g['content']), 0, 2600) . "\n";
            if ($used + mb_strlen($b) > $budget * .65) break;
            $out .= $b; $used += mb_strlen($b);
        }
    } catch (\Throwable $e) { error_log('[MEM] recall seg: ' . $e->getMessage()); }
    // ۲) جمله‌های عین پیام‌های قدیمی
    try {
        $w = "bot_id=? AND member_id=? AND role IN ('user','assistant') AND ($likes)";
        $p = array_merge([(int)$bot_id, (int)$member_id], $lv);
        if ($current_thread_id && $exclude_from) { $w .= " AND NOT (thread_id=? AND id>=?)"; $p[] = (int)$current_thread_id; $p[] = (int)$exclude_from; }
        $s = $pdo->prepare("SELECT id, thread_id, role, content, created_at FROM hd_messages WHERE $w ORDER BY id DESC LIMIT 250");
        $s->execute($p);
        $ms = [];
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) { $r['_s'] = $score($r['content']) + ($r['role'] === 'user' ? 1 : 0); if ($r['_s'] > 0) $ms[] = $r; }
        usort($ms, fn($a, $b) => $b['_s'] <=> $a['_s']);
        $q = '';
        foreach (array_slice($ms, 0, 6) as $r) {
            $c = trim(preg_replace('/\s+/u', ' ', (string)$r['content']));
            // پنجره متن اطراف اولین کلمه پیدا‌شده
            $pos = 0; $n = mem_norm($c);
            foreach ($kw as $k) { $x = mb_strpos($n, $k); if ($x !== false) { $pos = $x; break; } }
            $st = max(0, $pos - 160);
            $snip = ($st > 0 ? '…' : '') . mb_substr($c, $st, 420) . (mb_strlen($c) > $st + 420 ? '…' : '');
            $line = '- [' . substr((string)$r['created_at'], 0, 10) . ' — ' . ($r['role'] === 'user' ? 'مراجع' : 'مشاور (تو)') . '] ' . $snip . "\n";
            if ($used + mb_strlen($line) > $budget) break;
            $q .= $line; $used += mb_strlen($line);
        }
        if ($q !== '') $out .= ($out !== '' ? "\n" : '') . "جمله‌های عین گفتگوهای قبلی:\n" . $q;
    } catch (\Throwable $e) { error_log('[MEM] recall msg: ' . $e->getMessage()); }
    return trim($out);
}

/** پاک کردن بایگانی یک گفتگو (حذف گفتگو) یا از یک پیام به بعد (ویرایش پیام) */
function mem_forget_thread($pdo, $thread_id, $from_msg = 0)
{
    try {
        mem_ensure_schema($pdo);
        if ($from_msg > 0) $pdo->prepare("DELETE FROM hd_mem_segments WHERE thread_id=? AND to_msg>=?")->execute([(int)$thread_id, (int)$from_msg]);
        else $pdo->prepare("DELETE FROM hd_mem_segments WHERE thread_id=?")->execute([(int)$thread_id]);
    } catch (\Throwable $e) {}
}

/** وضعیت حافظه برای نمایش به صاحب ربات/مشاور */
function mem_stats($pdo, $bot_id, $member_id)
{
    mem_ensure_schema($pdo);
    $o = ['segments' => 0, 'messages' => 0, 'core_len' => 0, 'core_at' => ''];
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM hd_mem_segments WHERE bot_id=? AND member_id=?"); $s->execute([(int)$bot_id, (int)$member_id]); $o['segments'] = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND member_id=?"); $s->execute([(int)$bot_id, (int)$member_id]); $o['messages'] = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT mem_core, mem_core_at FROM hd_members WHERE id=?"); $s->execute([(int)$member_id]); $r = $s->fetch(\PDO::FETCH_ASSOC) ?: [];
        $o['core_len'] = mb_strlen((string)($r['mem_core'] ?? '')); $o['core_at'] = (string)($r['mem_core_at'] ?? '');
    } catch (\Throwable $e) {}
    return $o;
}
