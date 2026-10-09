<?php
/**
 * پایگاه دانش یکپارچه:
 *  - خدمات (مثل محصولات) برای پاسخگوی هوشمند
 *  - موضوعات «همیشه خوانده شود» (سنجاق‌شده)
 *  - آرشیو خودکار سوالات: سوال (یا مشابهش) پس از حداقل ۵ بار تکرار ذخیره می‌شود؛ بدون انقضای تاریخی
 *  - پایگاه دانش مرکزی بر اساس نوع چت‌بات (خودکار با امکان خاموش کردن توسط صاحب ربات)
 *  - اطمینان از خوانده شدن پایگاه دانش چت‌بات (دانش کوچک = کامل)
 * ساختار دیتابیس سازگار با MySQL (بدون IF NOT EXISTS در ALTER).
 */
if (defined('KB_LIB_LOADED')) return;
define('KB_LIB_LOADED', 1);
define('KB_SCHEMA_VERSION', 1);
define('KB_REPEAT_MIN', 5);              // حداقل تکرار سوال برای آرشیو خودکار
define('KB_FULL_BOT_CHARS', 14000);      // دانش چت‌بات تا این اندازه کامل خوانده می‌شود

function kb_now() { return date('Y-m-d H:i:s'); }

function kb_ensure_schema($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.kb_schema_v' . KB_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('[KB] schema: ' . $e->getMessage()); } };
    $col = function ($t, $c, $def) use ($pdo) {
        try {
            if (function_exists('saas_add_column_if_missing')) saas_add_column_if_missing($pdo, $t, $c, $def);
            else $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            return true;
        } catch (\Throwable $e) { error_log('[KB] column ' . $t . '.' . $c . ': ' . $e->getMessage()); return false; }
    };
    $has = function ($t) use ($pdo) { try { return $pdo->query("SELECT 1 FROM `$t` LIMIT 1") !== false; } catch (\Throwable $e) { return false; } };
    $q("CREATE TABLE IF NOT EXISTS saas_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description MEDIUMTEXT NULL,
        price VARCHAR(120) NOT NULL DEFAULT '',
        duration VARCHAR(120) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_question_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        q_key VARCHAR(500) NOT NULL,
        question VARCHAR(1000) NOT NULL,
        answer MEDIUMTEXT NULL,
        images TEXT NULL,
        cnt INT NOT NULL DEFAULT 1,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        INDEX idx_user (user_id, cnt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = $has('saas_services') && $has('saas_question_log');
    if ($has('saas_knowledge')) $ok = $col('saas_knowledge', 'is_pinned', 'TINYINT(1) NOT NULL DEFAULT 0') && $ok; else $ok = false;
    if ($has('hd_central_sets') && $has('hd_bots')) {
        $ok = $col('hd_central_sets', 'templates', "VARCHAR(300) NOT NULL DEFAULT ''") && $ok;
        $ok = $col('hd_bots', 'central_off', "VARCHAR(500) NOT NULL DEFAULT ''") && $ok;
    } else $ok = false;
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;   // جدول‌های پایه هنوز ساخته نشده‌اند؛ بعداً دوباره
}

// =============================================================================
// خدمات
// =============================================================================
function kb_services($pdo, $user_id, $active_only = false)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_services WHERE user_id=?" . ($active_only ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, id DESC LIMIT 1000");
        $s->execute([(int)$user_id]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

function kb_service_save($pdo, $user_id, array $in)
{
    $id = (int)($in['service_id'] ?? 0);
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 255);
    if (mb_strlen($title) < 2) return 'error:نام خدمت را وارد کنید.';
    $url = trim((string)($in['url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) return 'error:آدرس لینک معتبر نیست.';
    $v = [$title, mb_substr(trim((string)($in['description'] ?? '')), 0, 20000), mb_substr(trim((string)($in['price'] ?? '')), 0, 120),
          mb_substr(trim((string)($in['duration'] ?? '')), 0, 120), mb_substr($url, 0, 500), !empty($in['is_active']) ? 1 : 0, (int)($in['sort_order'] ?? 0)];
    if ($id) {
        $pdo->prepare("UPDATE saas_services SET title=?, description=?, price=?, duration=?, url=?, is_active=?, sort_order=? WHERE id=? AND user_id=?")->execute(array_merge($v, [$id, (int)$user_id]));
        return 'ok:خدمت ویرایش شد.';
    }
    $pdo->prepare("INSERT INTO saas_services (title, description, price, duration, url, is_active, sort_order, user_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute(array_merge($v, [(int)$user_id, kb_now()]));
    return 'ok:خدمت اضافه شد.';
}

/**
 * خدمات مرتبط با پرسش. اگر تعداد خدمات کم است، همه برگردانده می‌شوند تا هوش مصنوعی همه را بشناسد.
 * @return array [matched[], others[]]
 */
function kb_search_services($pdo, $user_id, $query, $limit = 6)
{
    $all = kb_services($pdo, $user_id, true);
    if (!$all) return [[], []];
    $terms = function_exists('saas_search_terms') ? saas_search_terms($query) : [];
    $scored = [];
    foreach ($all as $i => $s) {
        $sc = saas_text_score($terms, saas_fa_normalize($s['title']), 5) + saas_text_score($terms, saas_fa_normalize((string)$s['description']), 1);
        if ($sc > 0) { $s['_s'] = $sc; $s['_i'] = $i; $scored[] = $s; }
    }
    usort($scored, fn($a, $b) => $a['_s'] === $b['_s'] ? $a['_i'] <=> $b['_i'] : $b['_s'] <=> $a['_s']);
    $matched = array_slice($scored, 0, $limit);
    $ids = array_column($matched, 'id');
    $others = [];
    $general = preg_match('/خدمت|خدمات|سرویس|هزینه|قیمت|تعرفه|نوبت|رزرو|وقت|چه کار|چه کارهایی|انجام می/u', saas_fa_normalize((string)$query));
    if (count($all) <= 20 || (!$matched && $general)) {
        foreach ($all as $s) if (!in_array($s['id'], $ids, true)) $others[] = $s;
        $others = array_slice($others, 0, 20);
    }
    return [$matched, $others];
}

/** متن بخش خدمات برای پرامپت */
function kb_services_prompt($pdo, $user_id, $query)
{
    [$matched, $others] = kb_search_services($pdo, $user_id, $query);
    if (!$matched && !$others) return '';
    $terms = saas_search_terms($query);
    $line = function ($s, $full) use ($terms) {
        $t = '- ' . $s['title'];
        if (trim((string)$s['price']) !== '') $t .= ' | هزینه: ' . trim($s['price']);
        if (trim((string)$s['duration']) !== '') $t .= ' | مدت/زمان: ' . trim($s['duration']);
        $d = trim((string)$s['description']);
        if ($d !== '') $t .= "\n  توضیحات: " . ($full ? saas_best_snippet($d, $terms, 1000, 1500) : mb_substr(preg_replace('/\s+/u', ' ', $d), 0, 220));
        if (trim((string)$s['url']) !== '') $t .= "\n  لینک: " . trim($s['url']);
        return $t;
    };
    $out = ["\n=== خدمات این کسب‌وکار ==="];
    foreach ($matched as $s) $out[] = $line($s, true);
    foreach ($others as $s) $out[] = $line($s, false);
    $out[] = "=== پایان خدمات ===\nاگر کاربر درباره خدمات، هزینه یا زمان انجام پرسید، دقیقاً از فهرست بالا پاسخ بده و در صورت وجود لینک، آن را معرفی کن. هزینه‌ای را که نیامده حدس نزن.";
    return implode("\n", $out);
}

// =============================================================================
// موضوعات «همیشه خوانده شود»
// =============================================================================
function kb_pinned_topics($pdo, $user_id)
{
    try {
        $s = $pdo->prepare("SELECT title, content FROM saas_knowledge WHERE user_id=? AND is_pinned=1 AND (is_active=1 OR is_active IS NULL) ORDER BY id ASC LIMIT 20");
        $s->execute([(int)$user_id]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

// =============================================================================
// آرشیو خودکار سوالات پرتکرار
// =============================================================================
function kb_repeat_min($pdo)
{
    $v = function_exists('biz_get') ? (int)biz_get($pdo, 'archive_repeat_min') : 0;
    return $v >= 2 ? min(50, $v) : KB_REPEAT_MIN;
}

/**
 * ثبت یک پاسخ هوش مصنوعی: سوال (یا مشابهش) شمرده می‌شود و وقتی به حداقل تکرار رسید، در آرشیو ذخیره می‌شود.
 * @return int تعداد تکرار فعلی (۰ = قابل آرشیو نیست)
 */
function kb_question_hit($pdo, $user_id, $msg, $answer, $images = [], $visitor_name = '')
{
    if (!saas_cache_eligible($msg)) return 0;
    $answer = trim((string)$answer);
    if (mb_strlen($answer) < 15 || strpos($answer, '⚠️') !== false) return 0;
    $key = saas_question_key($msg);
    if ($key === '') return 0;
    $words = explode(' ', $key);
    $row = null;
    $s = $pdo->prepare("SELECT id, q_key, cnt, archived FROM saas_question_log WHERE user_id=? ORDER BY updated_at DESC LIMIT 800");
    $s->execute([(int)$user_id]);
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
        if ($r['q_key'] === $key) { $row = $r; break; }
        $cw = explode(' ', $r['q_key']);
        $union = count(array_unique(array_merge($words, $cw)));
        if ($union && min(count($words), count($cw)) >= 2 && count(array_intersect($words, $cw)) / $union >= 0.85) { $row = $r; break; }
    }
    $img = json_encode($images ?: new \stdClass(), JSON_UNESCAPED_UNICODE);
    if ($row) {
        $cnt = (int)$row['cnt'] + 1;
        $pdo->prepare("UPDATE saas_question_log SET cnt=?, answer=?, images=?, updated_at=? WHERE id=?")->execute([$cnt, $answer, $img, kb_now(), (int)$row['id']]);
        $id = (int)$row['id'];
    } else {
        $cnt = 1;
        $pdo->prepare("INSERT INTO saas_question_log (user_id, q_key, question, answer, images, cnt, archived, updated_at) VALUES(?,?,?,?,?,1,0,?)")
            ->execute([(int)$user_id, $key, mb_substr(trim((string)$msg), 0, 1000), $answer, $img, kb_now()]);
        $id = (int)$pdo->lastInsertId();
    }
    if ($cnt >= kb_repeat_min($pdo)) {
        if (saas_cache_store($pdo, $user_id, $msg, $answer, $images, $visitor_name)) {
            $pdo->prepare("UPDATE saas_question_log SET archived=1 WHERE id=?")->execute([$id]);
        }
    }
    return $cnt;
}

/** سوالات پرتکرار هنوز آرشیونشده (برای نمایش در پنل) */
function kb_frequent_questions($pdo, $user_id, $limit = 20)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_question_log WHERE user_id=? AND archived=0 AND cnt>=2 ORDER BY cnt DESC, updated_at DESC LIMIT " . (int)$limit);
        $s->execute([(int)$user_id]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

// =============================================================================
// پایگاه دانش مرکزی بر اساس نوع چت‌بات
// =============================================================================
function kb_set_templates($set)
{
    return array_values(array_filter(explode(',', (string)($set['templates'] ?? ''))));
}

/** مجموعه‌هایی که به‌صورت خودکار برای نوع این ربات فعال‌اند */
function kb_auto_sets_for_bot($pdo, $bot)
{
    $out = [];
    if (!function_exists('hd_central_sets')) return $out;
    foreach (hd_central_sets($pdo, true) as $s) if (in_array((string)($bot['template'] ?? 'custom'), kb_set_templates($s), true)) $out[] = (int)$s['id'];
    return $out;
}

/** مجموعه‌هایی که صاحب ربات خاموش کرده */
function kb_bot_off_sets($bot)
{
    return array_values(array_filter(array_map('intval', explode(',', (string)($bot['central_off'] ?? '')))));
}

// =============================================================================
// اطمینان از خوانده شدن دانش چت‌بات
// =============================================================================
/** اگر کل دانش ربات کوچک است، همه تکه‌ها (به ترتیب) برگردانده می‌شود؛ وگرنه null */
function kb_bot_all_chunks($pdo, array $kb_ids, $max_chars = KB_FULL_BOT_CHARS)
{
    $kb_ids = array_values(array_unique(array_map('intval', $kb_ids)));
    if (!$kb_ids) return null;
    try {
        $in = implode(',', array_fill(0, count($kb_ids), '?'));
        $c = $pdo->prepare("SELECT COALESCE(SUM(CHAR_LENGTH(chunk)),0) FROM hd_chunks WHERE bot_id IN ($in) AND source_id NOT IN (SELECT id FROM hd_sources WHERE is_active=0)");
        $c->execute($kb_ids);
        $total = (int)$c->fetchColumn();
        if ($total <= 0 || $total > $max_chars) return null;
        $s = $pdo->prepare("SELECT id, source_id, page_id, title, url, chunk, norm FROM hd_chunks WHERE bot_id IN ($in) AND source_id NOT IN (SELECT id FROM hd_sources WHERE is_active=0) ORDER BY bot_id DESC, source_id ASC, id ASC LIMIT 400");
        $s->execute($kb_ids);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
}

/** عنوان منابع فعال (برای اینکه مدل بداند چه موضوعاتی در دانش هست) */
function kb_bot_source_titles($pdo, array $kb_ids, $max = 40)
{
    $kb_ids = array_values(array_unique(array_map('intval', $kb_ids)));
    if (!$kb_ids) return [];
    try {
        $in = implode(',', array_fill(0, count($kb_ids), '?'));
        $s = $pdo->prepare("SELECT DISTINCT title FROM hd_sources WHERE bot_id IN ($in) AND is_active=1 AND title<>'' ORDER BY id DESC LIMIT " . (int)$max);
        $s->execute($kb_ids);
        return array_values(array_filter(array_map('trim', $s->fetchAll(\PDO::FETCH_COLUMN) ?: [])));
    } catch (\Throwable $e) { return []; }
}

/** همه پاسخ‌های تأییدشده وقتی تعدادشان کم است */
function kb_bot_all_qa($pdo, $bot_id, $max_chars = 5000)
{
    try {
        $s = $pdo->prepare("SELECT question, answer FROM hd_qa WHERE bot_id=? AND is_active=1 ORDER BY id DESC LIMIT 40");
        $s->execute([(int)$bot_id]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return null; }
    $n = 0;
    foreach ($rows as $r) $n += mb_strlen($r['question']) + mb_strlen($r['answer']);
    return ($rows && $n <= $max_chars) ? $rows : null;
}
