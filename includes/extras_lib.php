<?php
/**
 * امکانات تکمیلی پنل‌ها و چت‌بات:
 *  - پشتیبانی: تیکت (مخاطب → کاربر، کاربر → مدیر)، راهنما و سوالات متداول، پشتیبان هوشمند
 *  - پاپ‌آپ (مدیر → پنل کاربران / سایت اصلی، کاربر → چت‌بات مخاطبان / ویجت سایت)
 *  - تبلیغ و اعلان در چت‌بات رایگان، پیشنهاد مدل بهتر
 *  - لینک‌های پایگاه دانش (چت‌بات و پاسخگوی هوشمند)
 *  - سقف توکن ورودی/خروجی (پلن، ویجت، ربات)
 *  - مشاور اختصاصی مراجع، ثبت دفعات نظارت، دسته‌بندی موضوعی هوشمند پیام‌ها (فقط برای مشاور)
 *  - اولویت‌بندی امکانات پلن‌ها
 * ساختار دیتابیس با روش سازگار با MySQL (بدون IF NOT EXISTS در ALTER).
 */
if (defined('EX_LIB_LOADED')) return;
define('EX_LIB_LOADED', 1);
define('EX_SCHEMA_VERSION', 1);

function ex_now($off = 0) { return date('Y-m-d H:i:s', time() + (int)$off); }
function ex_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =============================================================================
// ساختار دیتابیس
// =============================================================================
function ex_ensure_schema($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.ex_schema_v' . EX_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;

    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('[EX] schema: ' . $e->getMessage()); } };
    $col = function ($t, $c, $def) use ($pdo) {
        try {
            if (function_exists('saas_add_column_if_missing')) saas_add_column_if_missing($pdo, $t, $c, $def);
            else $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            return true;
        } catch (\Throwable $e) { error_log('[EX] column ' . $t . '.' . $c . ': ' . $e->getMessage()); return false; }
    };

    $q("CREATE TABLE IF NOT EXISTS ex_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(8) NOT NULL DEFAULT 'admin',
        user_id INT NOT NULL DEFAULT 0,
        bot_id INT NOT NULL DEFAULT 0,
        member_id INT NOT NULL DEFAULT 0,
        subject VARCHAR(200) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        unread_admin TINYINT(1) NOT NULL DEFAULT 0,
        unread_user TINYINT(1) NOT NULL DEFAULT 0,
        unread_member TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_scope (scope, status),
        INDEX idx_user (user_id),
        INDEX idx_bot (bot_id, member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_ticket_msgs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        sender VARCHAR(8) NOT NULL DEFAULT 'user',
        author VARCHAR(140) NOT NULL DEFAULT '',
        body TEXT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_articles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(8) NOT NULL DEFAULT 'admin',
        bot_id INT NOT NULL DEFAULT 0,
        kind VARCHAR(8) NOT NULL DEFAULT 'guide',
        title VARCHAR(250) NOT NULL DEFAULT '',
        body MEDIUMTEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        INDEX idx_scope (scope, bot_id, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_popups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL DEFAULT 0,
        target VARCHAR(10) NOT NULL DEFAULT 'panel',
        bot_id INT NOT NULL DEFAULT 0,
        audience VARCHAR(20) NOT NULL DEFAULT 'all',
        pages VARCHAR(255) NOT NULL DEFAULT '',
        title VARCHAR(200) NOT NULL DEFAULT '',
        body TEXT NULL,
        image_url VARCHAR(500) NOT NULL DEFAULT '',
        btn_text VARCHAR(80) NOT NULL DEFAULT '',
        btn_url VARCHAR(500) NOT NULL DEFAULT '',
        freq VARCHAR(10) NOT NULL DEFAULT 'once',
        delay_sec INT NOT NULL DEFAULT 2,
        start_at DATETIME NULL,
        end_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        version INT NOT NULL DEFAULT 1,
        views INT NOT NULL DEFAULT 0,
        clicks INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        INDEX idx_target (owner_id, target, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_ads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT '',
        body VARCHAR(600) NOT NULL DEFAULT '',
        image_url VARCHAR(500) NOT NULL DEFAULT '',
        btn_text VARCHAR(80) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        views INT NOT NULL DEFAULT 0,
        clicks INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_bot (bot_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        bot_id INT NOT NULL DEFAULT 0,
        title VARCHAR(200) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_owner (user_id, bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_supervisions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        user_id INT NOT NULL DEFAULT 0,
        team_id INT NOT NULL DEFAULT 0,
        actor VARCHAR(140) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        INDEX idx_member (bot_id, member_id),
        INDEX idx_team (user_id, team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_topic_cats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        name VARCHAR(120) NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_bot (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS ex_topic_hits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        cat_id INT NOT NULL,
        message_id INT NOT NULL,
        thread_id INT NOT NULL DEFAULT 0,
        excerpt TEXT NULL,
        msg_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_hit (message_id, cat_id),
        INDEX idx_member (bot_id, member_id, cat_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // پلن‌ها و ویجت
    $col('saas_plans', 'limit_in', 'INT NOT NULL DEFAULT 0');
    $col('saas_plans', 'limit_out', 'INT NOT NULL DEFAULT 0');
    $col('saas_plans', 'feature_order', 'TEXT NULL');
    $col('saas_plans', 'extra_features', 'TEXT NULL');
    foreach (array_keys(ex_plan_extra_flags()) as $fk) $col('saas_plans', $fk, 'TINYINT(1) NOT NULL DEFAULT 1');
    $col('saas_widget_settings', 'limit_in', 'INT NOT NULL DEFAULT 0');
    $col('saas_widget_settings', 'limit_out', 'INT NOT NULL DEFAULT 0');

    // جدول‌های چت‌بات (اگر هنوز ساخته نشده‌اند، بعداً دوباره تلاش می‌شود)
    $hd_ok = false;
    try { $hd_ok = $pdo->query("SELECT 1 FROM hd_bots LIMIT 1") !== false && $pdo->query("SELECT 1 FROM hd_members LIMIT 1") !== false && $pdo->query("SELECT 1 FROM hd_messages LIMIT 1") !== false; } catch (\Throwable $e) { $hd_ok = false; }
    if ($hd_ok) {
        foreach ([
            ['hd_bots', 'limit_in', 'INT NOT NULL DEFAULT 0'], ['hd_bots', 'limit_out', 'INT NOT NULL DEFAULT 0'],
            ['hd_bots', 'free_notice', 'TEXT NULL'], ['hd_bots', 'free_notice_on', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['hd_bots', 'ads_every', 'INT NOT NULL DEFAULT 3'], ['hd_bots', 'suggest_model', 'TINYINT(1) NOT NULL DEFAULT 1'],
            ['hd_bots', 'support_on', 'TINYINT(1) NOT NULL DEFAULT 1'],
            ['hd_members', 'consultant_id', 'INT NOT NULL DEFAULT 0'],
            ['hd_messages', 'topic_done', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['hd_member_plans', 'features', 'TEXT NULL'],
        ] as [$t, $c, $d]) $col($t, $c, $d);
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } else {
        $done = false;   // بعد از ساخت جدول‌های چت‌بات دوباره اجرا شود
    }
}

// =============================================================================
// امکانات جدید پلن (با خرید پلن خودکار فعال می‌شوند)
// =============================================================================
function ex_plan_extra_flags()
{
    return [
        'has_member_support' => ['label' => 'پشتیبانی، تیکت و راهنمای مخاطبان چت‌بات', 'icon' => '🎧'],
        'has_popups'         => ['label' => 'پاپ‌آپ برای مخاطبان و بازدیدکنندگان', 'icon' => '🪧'],
        'has_bot_ads'        => ['label' => 'تبلیغ و اعلان در چت‌بات رایگان', 'icon' => '📣'],
        'has_topic_ai'       => ['label' => 'دسته‌بندی موضوعی هوشمند گفتگوها (برای مشاور)', 'icon' => '🗂'],
        'has_bulk_msg'       => ['label' => 'ارسال پیامک، اعلان و یادآور به مخاطبان (فردی و گروهی، همراه همکار مرتبط)', 'icon' => '📣'],
        'has_sessions'       => ['label' => 'جلسات مشاوره با یادآوری خودکار به مشاور و مخاطب', 'icon' => '📅'],
    ];
}

/** آیا پلن صاحب ربات این امکان را دارد؟ */
function ex_owner_allows($pdo, $user_id, $feature)
{
    // نتیجه فقط در همین درخواست نگه‌داری می‌شود (ex_owner_allows_reset برای پاک کردن)
    $cache = &$GLOBALS['__ex_owner_allows'];
    if (!is_array($cache)) $cache = [];
    $k = (int)$user_id . ':' . $feature;
    if (isset($cache[$k])) return $cache[$k];
    try {
        $u = saas_get_user($pdo, (int)$user_id);
        $plan = $u && $u['plan_id'] ? saas_get_plan($pdo, (int)$u['plan_id']) : null;
        return $cache[$k] = saas_plan_allows($plan ?: null, $feature);
    } catch (\Throwable $e) { return $cache[$k] = true; }
}

function ex_owner_allows_reset() { $GLOBALS['__ex_owner_allows'] = []; }

// =============================================================================
// سقف توکن ورودی / خروجی
// =============================================================================
/** سقف مؤثر: کمترین مقدار غیرصفر (پلن، تنظیم کاربر) — ۰ = بدون سقف */
function ex_limit_pick(...$vals)
{
    $v = array_filter(array_map('intval', $vals), fn($x) => $x > 0);
    return $v ? min($v) : 0;
}

/** تخمین تعداد توکن یک متن (فارسی تقریباً هر ۲٫۵ کاراکتر یک توکن) */
function ex_est_tokens($text)
{
    if (is_array($text)) {
        $n = 0;
        foreach ($text as $p) $n += ($p['type'] ?? '') === 'image_url' ? 900 : ex_est_tokens((string)($p['text'] ?? ''));
        return $n;
    }
    return (int)ceil(mb_strlen((string)$text) / 2.5) + 4;
}

/**
 * پیام‌ها را در سقف توکن ورودی جا می‌دهد: اول قدیمی‌ترین پیام‌های گفتگو حذف می‌شوند،
 * سپس متن زمینه (پیام سیستمی) کوتاه می‌شود. پیام آخر کاربر همیشه حفظ می‌شود.
 */
function ex_fit_input(array $msgs, $max_in)
{
    $max_in = (int)$max_in;
    if ($max_in <= 0 || !$msgs) return $msgs;
    $max_in = max(300, $max_in);
    $total = function ($ms) { $n = 0; foreach ($ms as $m) $n += ex_est_tokens($m['content']); return $n; };
    if ($total($msgs) <= $max_in) return $msgs;
    $has_sys = ($msgs[0]['role'] ?? '') === 'system';
    // حذف پیام‌های قدیمی (به‌جز سیستمی و آخرین پیام)
    while (count($msgs) > ($has_sys ? 2 : 1) && $total($msgs) > $max_in) array_splice($msgs, $has_sys ? 1 : 0, 1);
    if ($total($msgs) <= $max_in || !$has_sys) return $msgs;
    // کوتاه کردن زمینه: ابتدای دستورالعمل‌ها حفظ و بقیه کوتاه می‌شود
    $rest = $total(array_slice($msgs, 1));
    $allow = max(120, $max_in - $rest);
    $chars = (int)floor($allow * 2.5) - 60;
    $sys = (string)$msgs[0]['content'];
    if (mb_strlen($sys) > $chars) $msgs[0]['content'] = mb_substr($sys, 0, max(200, $chars)) . "\n…(بخشی از اطلاعات زمینه به‌خاطر سقف مصرف کوتاه شد)";
    // اگر خود پیام کاربر از سقف بلندتر است، انتهای آن کوتاه می‌شود
    $li = count($msgs) - 1;
    if ($total($msgs) > $max_in && is_string($msgs[$li]['content'])) {
        $room = max(80, $max_in - ($li > 0 ? ex_est_tokens($msgs[0]['content']) : 0));
        if (ex_est_tokens($msgs[$li]['content']) > $room) $msgs[$li]['content'] = mb_substr($msgs[$li]['content'], 0, max(150, (int)floor(($room - 10) * 2.5))) . ' …';
    }
    return $msgs;
}

/** سقف‌های ویجت یک کاربر */
function ex_widget_limits($plan, $ws)
{
    return [ex_limit_pick($plan['limit_in'] ?? 0, $ws['limit_in'] ?? 0), ex_limit_pick($plan['limit_out'] ?? 0, $ws['limit_out'] ?? 0)];
}

/** سقف‌های یک چت‌بات (پلن صاحب + تنظیم ربات) */
function ex_bot_limits($pdo, $bot)
{
    $plan = null;
    try { $u = saas_get_user($pdo, (int)$bot['user_id']); $plan = $u && $u['plan_id'] ? saas_get_plan($pdo, (int)$u['plan_id']) : null; } catch (\Throwable $e) {}
    return [ex_limit_pick($plan['limit_in'] ?? 0, $bot['limit_in'] ?? 0), ex_limit_pick($plan['limit_out'] ?? 0, $bot['limit_out'] ?? 0)];
}

// =============================================================================
// تیکت‌ها
// =============================================================================
function ex_ticket_statuses() { return ['open' => 'در انتظار پاسخ', 'answered' => 'پاسخ داده شد', 'closed' => 'بسته شده']; }

/**
 * @param string $scope admin (کاربر → مدیر) | bot (مخاطب → صاحب ربات)
 * @param string $sender user | admin | member
 */
function ex_ticket_create($pdo, $scope, $user_id, $bot_id, $member_id, $subject, $body, $sender, $author)
{
    $subject = mb_substr(trim((string)$subject), 0, 200);
    $body = mb_substr(trim((string)$body), 0, 8000);
    if (mb_strlen($subject) < 2 || mb_strlen($body) < 2) return ['ok' => false, 'error' => 'موضوع و متن تیکت را بنویسید.'];
    $now = ex_now();
    $pdo->prepare("INSERT INTO ex_tickets (scope, user_id, bot_id, member_id, subject, status, unread_admin, unread_user, unread_member, created_at, updated_at) VALUES(?,?,?,?,?,'open',?,?,0,?,?)")
        ->execute([$scope === 'bot' ? 'bot' : 'admin', (int)$user_id, (int)$bot_id, (int)$member_id, $subject, $scope === 'bot' ? 0 : 1, $scope === 'bot' ? 1 : 0, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ex_ticket_msgs (ticket_id, sender, author, body, created_at) VALUES(?,?,?,?,?)")->execute([$id, $sender, mb_substr((string)$author, 0, 140), $body, $now]);
    if ($scope === 'bot' && function_exists('biz_notify')) biz_notify($pdo, (int)$user_id, 'تیکت جدید از مخاطب چت‌بات: ' . $subject, mb_substr($body, 0, 300), 'info', 'support.php?tab=bot&t=' . $id);
    return ['ok' => true, 'id' => $id];
}

function ex_ticket_get($pdo, $id)
{
    $s = $pdo->prepare("SELECT * FROM ex_tickets WHERE id=?");
    $s->execute([(int)$id]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function ex_ticket_msgs($pdo, $id)
{
    $s = $pdo->prepare("SELECT * FROM ex_ticket_msgs WHERE ticket_id=? ORDER BY id ASC");
    $s->execute([(int)$id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/** پاسخ به تیکت و به‌روزرسانی وضعیت و علامت خوانده‌نشده */
function ex_ticket_reply($pdo, $t, $sender, $author, $body)
{
    $body = mb_substr(trim((string)$body), 0, 8000);
    if (mb_strlen($body) < 1) return ['ok' => false, 'error' => 'متن پاسخ را بنویسید.'];
    $now = ex_now();
    $pdo->prepare("INSERT INTO ex_ticket_msgs (ticket_id, sender, author, body, created_at) VALUES(?,?,?,?,?)")->execute([(int)$t['id'], $sender, mb_substr((string)$author, 0, 140), $body, $now]);
    // پاسخ طرف پشتیبان → «پاسخ داده شد»؛ پیام درخواست‌کننده → «در انتظار پاسخ»
    $is_support = ($t['scope'] === 'admin' && $sender === 'admin') || ($t['scope'] === 'bot' && $sender === 'user');
    $status = $is_support ? 'answered' : 'open';
    if ($t['scope'] === 'admin') {
        $pdo->prepare("UPDATE ex_tickets SET status=?, unread_admin=?, unread_user=?, updated_at=? WHERE id=?")->execute([$status, $is_support ? 0 : 1, $is_support ? 1 : 0, $now, (int)$t['id']]);
        if ($is_support && function_exists('biz_notify')) biz_notify($pdo, (int)$t['user_id'], 'پاسخ پشتیبانی: ' . $t['subject'], mb_substr($body, 0, 300), 'info', 'support.php?tab=tickets&t=' . (int)$t['id']);
    } else {
        $pdo->prepare("UPDATE ex_tickets SET status=?, unread_user=?, unread_member=?, updated_at=? WHERE id=?")->execute([$status, $is_support ? 0 : 1, $is_support ? 1 : 0, $now, (int)$t['id']]);
        if (!$is_support && function_exists('biz_notify')) biz_notify($pdo, (int)$t['user_id'], 'پیام جدید در تیکت مخاطب: ' . $t['subject'], mb_substr($body, 0, 300), 'info', 'support.php?tab=bot&t=' . (int)$t['id']);
    }
    return ['ok' => true];
}

function ex_ticket_set_status($pdo, $id, $status)
{
    if (!isset(ex_ticket_statuses()[$status])) return;
    $pdo->prepare("UPDATE ex_tickets SET status=?, updated_at=? WHERE id=?")->execute([$status, ex_now(), (int)$id]);
}

/** تعداد تیکت‌های خوانده‌نشده (برای نشان در منو) */
function ex_unread_count($pdo, $who, $user_id = 0)
{
    try {
        if ($who === 'admin') return (int)$pdo->query("SELECT COUNT(*) FROM ex_tickets WHERE scope='admin' AND unread_admin=1")->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM ex_tickets WHERE user_id=? AND unread_user=1");
        $s->execute([(int)$user_id]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

// =============================================================================
// راهنما و سوالات متداول
// =============================================================================
function ex_articles($pdo, $scope, $bot_id = 0, $kind = null, $active_only = true)
{
    $sql = "SELECT * FROM ex_articles WHERE scope=? AND bot_id=?" . ($kind ? " AND kind=?" : "") . ($active_only ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, id ASC";
    $s = $pdo->prepare($sql);
    $s->execute($kind ? [$scope, (int)$bot_id, $kind] : [$scope, (int)$bot_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function ex_article_save($pdo, $scope, $bot_id, $data, $id = 0)
{
    $kind = ($data['kind'] ?? '') === 'faq' ? 'faq' : 'guide';
    $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 250);
    $body = mb_substr(trim((string)($data['body'] ?? '')), 0, 60000);
    if (mb_strlen($title) < 2 || mb_strlen($body) < 2) return ['ok' => false, 'error' => $kind === 'faq' ? 'سوال و پاسخ را بنویسید.' : 'عنوان و متن راهنما را بنویسید.'];
    $v = [$kind, $title, $body, (int)($data['sort_order'] ?? 0), !empty($data['is_active']) ? 1 : 0, ex_now()];
    if ($id) {
        $pdo->prepare("UPDATE ex_articles SET kind=?, title=?, body=?, sort_order=?, is_active=?, updated_at=? WHERE id=? AND scope=? AND bot_id=?")->execute(array_merge($v, [(int)$id, $scope, (int)$bot_id]));
    } else {
        $pdo->prepare("INSERT INTO ex_articles (kind, title, body, sort_order, is_active, updated_at, scope, bot_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute(array_merge($v, [$scope, (int)$bot_id, ex_now()]));
        $id = (int)$pdo->lastInsertId();
    }
    // مخاطبان هدف (راهنمای کاربر برای ویجت و چت‌بات‌ها): خالی = همه
    if (array_key_exists('targets', $data)) {
        $tg = array_values(array_unique(array_filter(array_map(fn($x) => preg_replace('/[^a-z0-9]/', '', (string)$x), (array)$data['targets']), 'strlen')));
        try { $pdo->prepare("UPDATE ex_articles SET targets=? WHERE id=? AND scope=? AND bot_id=?")->execute([$tg && empty($data['targets_all']) ? ',' . implode(',', $tg) . ',' : '', (int)$id, $scope, (int)$bot_id]); } catch (\Throwable $e) {}
    }
    return ['ok' => true, 'id' => (int)$id];
}

function ex_article_del($pdo, $scope, $bot_id, $id)
{
    $pdo->prepare("DELETE FROM ex_articles WHERE id=? AND scope=? AND bot_id=?")->execute([(int)$id, $scope, (int)$bot_id]);
}

/** کلمات کلیدی ساده برای امتیازدهی ارتباط متن با سوال */
function ex_words($text)
{
    $t = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string)$text));
    $t = strtr($t, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', '‌' => ' ']);
    $stop = array_flip(['و', 'در', 'به', 'از', 'که', 'این', 'آن', 'با', 'را', 'برای', 'یا', 'تا', 'هم', 'چه', 'چی', 'چطور', 'چگونه', 'آیا', 'است', 'هست', 'می', 'من', 'شما', 'ما', 'یک', 'کنم', 'کنید', 'کرد', 'شود', 'the', 'a', 'to', 'of', 'is', 'and']);
    $out = [];
    foreach (preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) as $w) if (mb_strlen($w) > 1 && !isset($stop[$w])) $out[] = $w;
    return array_values(array_unique($out));
}

function ex_score($words, $text)
{
    if (!$words) return 0;
    $t = mb_strtolower(strtr((string)$text, ['ي' => 'ی', 'ك' => 'ک']));
    $s = 0;
    foreach ($words as $w) if (mb_strpos($t, $w) !== false) $s += mb_strlen($w) > 3 ? 2 : 1;
    return $s;
}

/** متن راهنما/سوالات مرتبط برای پرامپت پشتیبان (یا چت‌بات) */
function ex_articles_context($pdo, $scope, $bot_id, $question, $max_chars = 12000, $min_score = 0)
{
    $arts = ex_articles($pdo, $scope, $bot_id);
    if (!$arts) return '';
    $words = ex_words($question);
    foreach ($arts as &$a) $a['_s'] = ex_score($words, $a['title']) * 3 + ex_score($words, $a['body']);
    unset($a);
    $total = array_sum(array_map(fn($a) => mb_strlen($a['title'] . $a['body']), $arts));
    if ($total > $max_chars) usort($arts, fn($x, $y) => $y['_s'] <=> $x['_s']);
    $out = ''; $used = 0;
    foreach ($arts as $a) {
        if ($a['_s'] < $min_score) continue;
        $body = $a['body'];
        $room = $max_chars - $used;
        if ($room < 300) break;
        if (mb_strlen($body) > $room) $body = mb_substr($body, 0, $room) . '…';
        $block = ($a['kind'] === 'faq' ? "سوال: {$a['title']}\nپاسخ: " : "راهنما: {$a['title']}\n") . $body . "\n\n";
        $out .= $block;
        $used += mb_strlen($block);
    }
    return trim($out);
}

// =============================================================================
// پشتیبان هوشمند
// =============================================================================
/**
 * پاسخ پشتیبان هوشمند بر اساس راهنما و سوالات متداول
 * @param string $scope admin (پشتیبانی سامانه برای کاربران) | bot (پشتیبانی چت‌بات برای مخاطبان)
 * @return array ['ok', 'reply', 'ticket' => پیشنهاد ثبت تیکت]
 */
function ex_support_answer($pdo, $scope, $bot, $question, array $history = [])
{
    $question = mb_substr(trim((string)$question), 0, 2000);
    if ($question === '') return ['ok' => false, 'error' => 'سوال خود را بنویسید.'];
    $bot_id = $scope === 'bot' ? (int)$bot['id'] : 0;
    $ctx = ex_articles_context($pdo, $scope, $bot_id, $question . ' ' . implode(' ', array_column(array_slice($history, -2), 'content')), 14000);
    // راهنما و سوالات عمومی صاحب ربات برای مخاطبانش (بخش پشتیبانی پنل کاربر)
    if ($scope === 'bot' && function_exists('cx_user_articles')) {
        $ux = cx_articles_text(cx_user_articles($pdo, (int)$bot['user_id'], (string)$bot_id), $question, 8000);
        if ($ux !== '') $ctx = trim($ctx . "\n\n" . $ux);
    }
    $extra = '';
    if ($scope === 'admin') {
        try {
            $pl = [];
            foreach (saas_get_plans($pdo) as $p) $pl[] = '- ' . $p['name'] . ': ' . number_format((int)$p['price_toman']) . ' تومان — ' . implode('، ', array_column(array_slice(ex_plan_feature_list_sorted($p), 0, 12), 'text'));
            if ($pl) $extra = "پلن‌های سامانه:\n" . implode("\n", $pl);
        } catch (\Throwable $e) {}
        $who = 'پشتیبان هوشمند سامانه (پنل کاربری، ویجت پاسخگوی هوشمند، چت‌بات تخصصی، اعتبار و پرداخت)';
    } else {
        $pl = [];
        foreach (function_exists('hd_member_plans') ? hd_member_plans($pdo, $bot_id) : [] as $p) $pl[] = '- ' . $p['name'] . ': ' . number_format((int)$p['price_toman']) . ' تومان، ' . ((($p['pkind'] ?? '') === 'credit' && function_exists('mcr_plan_credit')) ? number_format(mcr_plan_credit($p)) . ' تومان شارژ' : (int)$p['messages'] . ' پیام') . ((int)$p['days'] ? '، ' . (int)$p['days'] . ' روز' : '') . (trim((string)($p['features'] ?? '')) !== '' ? ' — ' . str_replace("\n", '، ', trim($p['features'])) : '');
        if ($pl) $extra = "بسته‌های قابل خرید:\n" . implode("\n", $pl);
        if (trim((string)($bot['support_text'] ?? '')) !== '') $extra .= "\nراه ارتباط با پشتیبانی: " . trim($bot['support_text']);
        $who = 'پشتیبان «' . $bot['name'] . '» (راهنمای استفاده از همین چت‌بات، بسته‌ها و پرداخت)';
    }
    $sys = "تو {$who} هستی. فقط بر اساس «راهنما و سوالات متداول» و اطلاعات زیر، کوتاه، دقیق و مؤدبانه به فارسی پاسخ بده.\n"
         . "- اگر پاسخ در اطلاعات نیست یا کار نیاز به بررسی انسانی دارد (مشکل پرداخت، خطای خاص، درخواست ویژه)، حدس نزن؛ صادقانه بگو و پیشنهاد کن «تیکت» ثبت شود و در انتهای پاسخ دقیقاً [TICKET] بنویس.\n"
         . "- هیچ نام سرویس‌دهنده فنی، مدل هوش مصنوعی یا جزئیات داخلی را نام نبر.\n\n"
         . "=== راهنما و سوالات متداول ===\n" . ($ctx !== '' ? $ctx : '(هنوز راهنمایی ثبت نشده است)') . ($extra !== '' ? "\n\n=== اطلاعات تکمیلی ===\n" . $extra : '');
    $msgs = [['role' => 'system', 'content' => $sys]];
    foreach (array_slice($history, -8) as $h) {
        $r = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $msgs[] = ['role' => $r, 'content' => mb_substr((string)($h['content'] ?? ''), 0, 2000)];
    }
    $msgs[] = ['role' => 'user', 'content' => $question];
    $cfg = saas_get_api_config($pdo);
    // نسخه ۶۲: پشتیبان چت‌بات با «مدل پاسخ‌گویی» همان ربات و به قیمت همان مدل (نه مدل سیستم)
    $sup_model = null;
    if ($scope === 'bot' && function_exists('biz_resolve_model') && function_exists('cx_owner_plan')) {
        try { $sup_model = biz_resolve_model($pdo, cx_owner_plan($pdo, $bot), (int)($bot['ai_model_id'] ?? 0), 'bot'); } catch (\Throwable $e) { $sup_model = null; }
    }
    $r = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $sup_model, $msgs, 700, 0.2) : aichat_call_ai($cfg, $msgs, 700, 0.2);
    if (empty($r['ok'])) return ['ok' => false, 'error' => 'پشتیبان هوشمند فعلاً در دسترس نیست؛ لطفاً تیکت ثبت کنید.', 'ticket' => true];
    // هزینه پشتیبانی چت‌بات از اعتبار صاحب ربات (پشتیبانی سامانه رایگان است)
    if ($scope === 'bot' && function_exists('biz_charge_tokens')) {
        $cost = biz_charge_tokens($pdo, $sup_model, $r);
        if ($cost > 0) saas_deduct_credit($pdo, (int)$bot['user_id'], $cost, 'پشتیبان هوشمند چت‌بات: ' . $bot['name']);
    }
    $reply = trim((string)$r['content']);
    $ticket = strpos($reply, '[TICKET]') !== false;
    $reply = trim(str_replace('[TICKET]', '', $reply));
    return ['ok' => true, 'reply' => $reply, 'ticket' => $ticket];
}

// =============================================================================
// پاپ‌آپ‌ها
// =============================================================================
function ex_popup_targets() { return ['panel' => 'پنل کاربران', 'site' => 'سایت اصلی', 'bot' => 'چت‌بات (مخاطبان)', 'widget' => 'ویجت سایت (بازدیدکنندگان)']; }
function ex_popup_freqs() { return ['once' => 'فقط یک بار', 'daily' => 'روزی یک بار', 'session' => 'هر بار ورود (هر نشست)', 'always' => 'هر بار باز شدن صفحه']; }

/** صفحه‌های قابل انتخاب پنل کاربر برای پاپ‌آپ مدیر */
function ex_panel_pages()
{
    return ['index.php' => 'داشبورد', 'settings.php' => 'تنظیمات ویجت', 'knowledge.php' => 'پایگاه دانش', 'bots.php' => 'چت‌بات‌ها', 'bot.php' => 'مدیریت چت‌بات',
            'billing.php' => 'اعتبار و پرداخت', 'studio.php' => 'استودیو', 'support.php' => 'پشتیبانی'];
}

/**
 * پاپ‌آپ‌های قابل نمایش در یک محل
 * @param array $ctx owner_id, bot_id, plan_id, page, free(bool)
 */
function ex_popups_for($pdo, $target, array $ctx = [])
{
    try {
        $s = $pdo->prepare("SELECT * FROM ex_popups WHERE owner_id=? AND target=? AND is_active=1 ORDER BY sort_order ASC, id DESC");
        $s->execute([(int)($ctx['owner_id'] ?? 0), $target]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
    $now = ex_now();
    $out = [];
    foreach ($rows as $p) {
        if ($p['start_at'] && $p['start_at'] > $now) continue;
        if ($p['end_at'] && $p['end_at'] < $now) continue;
        if ($target === 'bot' && (int)$p['bot_id'] > 0 && (int)$p['bot_id'] !== (int)($ctx['bot_id'] ?? 0)) continue;
        $aud = (string)$p['audience'];
        if ($target === 'panel') {
            if (str_starts_with($aud, 'plan:') && (int)substr($aud, 5) !== (int)($ctx['plan_id'] ?? 0)) continue;
            if ($aud === 'noplan' && (int)($ctx['plan_id'] ?? 0) > 0) continue;
            $pages = array_filter(explode(',', (string)$p['pages']));
            if ($pages && !in_array((string)($ctx['page'] ?? ''), $pages, true)) continue;
        }
        if ($target === 'bot') {
            if ($aud === 'free' && empty($ctx['free'])) continue;
            if ($aud === 'paid' && !empty($ctx['free'])) continue;
        }
        $out[] = ex_popup_public($p);
    }
    return $out;
}

function ex_popup_public($p)
{
    return ['id' => (int)$p['id'], 'v' => (int)$p['version'], 'title' => (string)$p['title'], 'body' => (string)$p['body'], 'image' => (string)$p['image_url'],
            'btn' => (string)$p['btn_text'], 'url' => (string)$p['btn_url'], 'freq' => (string)$p['freq'], 'delay' => max(0, min(60, (int)$p['delay_sec']))];
}

function ex_popup_hit($pdo, $id, $field)
{
    if (!in_array($field, ['views', 'clicks'], true)) return;
    try { $pdo->prepare("UPDATE ex_popups SET $field = $field + 1 WHERE id=?")->execute([(int)$id]); } catch (\Throwable $e) {}
}

/**
 * اسکریپت نمایش پاپ‌آپ (جدا از استایل صفحه با Shadow DOM)
 * @param string $track_url آدرس ثبت بازدید/کلیک (اختیاری؛ GET با pp=id&ev=view|click)
 */
function ex_popup_script(array $popups, $track_url = '')
{
    if (!$popups) return '';
    $json = json_encode(array_values($popups), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $tu = json_encode((string)$track_url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return '<script>(function(P,TU){' . ex_popup_js_core() . '})(' . $json . ',' . $tu . ');</script>';
}

/** هسته جاوااسکریپت پاپ‌آپ (در ویجت هم استفاده می‌شود) — ورودی: P (آرایه)، TU (آدرس ثبت) */
function ex_popup_js_core()
{
    return <<<'JS'
if(!P||!P.length||!document.body)return;
function K(p){return 'expp_'+p.id+'_'+p.v;}
function ok(p){try{var k=K(p),v=localStorage.getItem(k);if(p.freq==='once'&&v)return false;if(p.freq==='daily'&&v&&(Date.now()-(+v))<86400000)return false;if(p.freq==='session'&&sessionStorage.getItem(k))return false;}catch(e){}return true;}
function mark(p){try{localStorage.setItem(K(p),String(Date.now()));sessionStorage.setItem(K(p),'1');}catch(e){}}
function tr(p,ev){if(!TU)return;try{var i=new Image();i.src=TU+(TU.indexOf('?')<0?'?':'&')+'pp='+p.id+'&ev='+ev+'&_='+Date.now();}catch(e){}}
function es(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
var p=null;for(var i=0;i<P.length;i++){if(ok(P[i])){p=P[i];break;}}
if(!p)return;
setTimeout(function(){
 var host=document.createElement('div');host.setAttribute('data-expp',p.id);
 host.style.cssText='all:initial;position:fixed;inset:0;z-index:2147483600;';
 var root=host.attachShadow?host.attachShadow({mode:'open'}):host;
 var u=/^https?:\/\//i.test(p.url)?p.url:'';
 root.innerHTML='<style>*{box-sizing:border-box;font-family:Vazirmatn,Tahoma,sans-serif}.ov{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;animation:f .25s}.bx{background:#fff;border-radius:18px;max-width:440px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);direction:rtl;text-align:right;position:relative;color:#1e293b}.im{width:100%;display:block;border-radius:18px 18px 0 0;max-height:260px;object-fit:cover}.c{padding:18px 20px 20px}.t{font-size:17px;font-weight:800;margin:0 0 8px;line-height:1.7}.b{font-size:14px;line-height:2;white-space:pre-wrap;color:#475569;margin:0}.x{position:absolute;top:10px;left:10px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.92);font-size:20px;line-height:1;cursor:pointer;color:#334155;box-shadow:0 2px 8px rgba(0,0,0,.15)}.a{display:block;margin-top:14px;text-align:center;background:#7c3aed;color:#fff;text-decoration:none;padding:11px;border-radius:12px;font-weight:700;font-size:14px;border:none;width:100%;cursor:pointer}@keyframes f{from{opacity:0}to{opacity:1}}</style>'
 +'<div class="ov"><div class="bx" role="dialog" aria-modal="true"><button class="x" type="button" aria-label="بستن">×</button>'
 +(p.image?'<img class="im" src="'+es(p.image)+'" alt="">':'')
 +'<div class="c">'+(p.title?'<p class="t">'+es(p.title)+'</p>':'')+(p.body?'<p class="b">'+es(p.body)+'</p>':'')
 +(p.btn&&u?'<a class="a" href="'+es(u)+'" target="_blank" rel="noopener">'+es(p.btn)+'</a>':(p.btn?'<button class="a" type="button">'+es(p.btn)+'</button>':''))+'</div></div></div>';
 document.body.appendChild(host);mark(p);tr(p,'view');
 function close(){if(host.parentNode)host.parentNode.removeChild(host);}
 root.querySelector('.x').onclick=close;
 root.querySelector('.ov').onclick=function(e){if(e.target===this)close();};
 var a=root.querySelector('.a');if(a)a.onclick=function(){tr(p,'click');setTimeout(close,50);};
},(p.delay||0)*1000);
JS;
}

/** تصویر آپلودشده روی سامانه → مرجع «exi:نام» تا از طریق فایل واسط سایت مشتری نمایش داده شود (بدون نمایش آدرس سامانه) */
function ex_img_ref($url)
{
    $url = (string)$url;
    $prefix = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/ex/';
    if ($prefix !== '/uploads/ex/' && strpos($url, $prefix) === 0) {
        $name = basename(substr($url, strlen($prefix)));
        if (preg_match('/^\d+_\d{8}_[a-f0-9]{12}\.(jpg|png|gif|webp)$/', $name)) return 'exi:' . $name;
    }
    return $url;
}

/** ارسال فایل تصویر برای فایل واسط (فقط تصاویر متعلق به صاحب همین ربات) */
function ex_file_public($bot, $name)
{
    $name = basename((string)$name);
    if (!preg_match('/^(\d+)_\d{8}_[a-f0-9]{12}\.(jpg|png|gif|webp)$/', $name, $m) || (int)$m[1] !== (int)$bot['user_id']) return ['ok' => false, 'error' => 'not found'];
    $file = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/ex/' . $name;
    if (!is_file($file)) return ['ok' => false, 'error' => 'not found'];
    $info = @getimagesize($file);
    $mime = (string)($info['mime'] ?? '');
    if (!preg_match('#^image/(png|jpeg|gif|webp)$#', $mime)) return ['ok' => false, 'error' => 'not found'];
    return ['ok' => true, 'mime' => $mime, 'data' => base64_encode((string)file_get_contents($file))];
}

/** آیا کاربر فایلی انتخاب کرده است؟ */
function ex_file_given($file)
{
    return is_array($file) && isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE;
}

/** ذخیره تصویر آپلودشده (پاپ‌آپ، تبلیغ) — خروجی: آدرس عمومی یا '' */
function ex_upload_image($file, $owner_id = 0)
{
    if (empty($file) || !is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return '';
    if ((int)$file['size'] > 2 * 1024 * 1024) return '';
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? '';
    if ($ext === '') return '';
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/ex';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = (int)$owner_id . '_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return '';
    return rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/ex/' . $name;
}

/** ذخیره پاپ‌آپ از فرم */
function ex_popup_save($pdo, $owner_id, array $in, array $allowed_targets, $file = null)
{
    $id = (int)($in['popup_id'] ?? 0);
    $target = in_array($in['target'] ?? '', $allowed_targets, true) ? $in['target'] : $allowed_targets[0];
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 3000);
    $img = trim((string)($in['image_url'] ?? ''));
    $up = ex_upload_image($file, $owner_id);
    if ($up === '' && ex_file_given($file)) return ['ok' => false, 'error' => 'تصویر قابل قبول نیست (فقط jpg، png، gif یا webp تا ۲ مگابایت).'];
    if ($up !== '') $img = $up;
    if ($img !== '' && !preg_match('#^https?://#i', $img)) $img = '';
    $url = trim((string)($in['btn_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) return ['ok' => false, 'error' => 'آدرس لینک دکمه معتبر نیست.'];
    if ($title === '' && $body === '' && $img === '') return ['ok' => false, 'error' => 'عنوان، متن یا تصویر پاپ‌آپ را وارد کنید.'];
    $aud = (string)($in['audience'] ?? 'all');
    if (!preg_match('/^(all|free|paid|noplan|plan:\d+)$/', $aud)) $aud = 'all';
    $pages = implode(',', array_intersect(array_keys(ex_panel_pages()), (array)($in['pages'] ?? [])));
    $dt = function ($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
    $v = [$target, $target === 'bot' ? (int)($in['bot_id'] ?? 0) : 0, $aud, $pages, $title, $body, mb_substr($img, 0, 500), mb_substr(trim((string)($in['btn_text'] ?? '')), 0, 80), mb_substr($url, 0, 500),
          isset(ex_popup_freqs()[$in['freq'] ?? '']) ? $in['freq'] : 'once', max(0, min(60, (int)($in['delay_sec'] ?? 2))),
          $dt($in['start_at'] ?? '') ? $dt($in['start_at']) . ' 00:00:00' : null, $dt($in['end_at'] ?? '') ? $dt($in['end_at']) . ' 23:59:59' : null,
          !empty($in['is_active']) ? 1 : 0, (int)($in['sort_order'] ?? 0), ex_now()];
    if ($id) {
        // هر ویرایش = نسخه جدید (دوباره به کسانی که قبلاً دیده‌اند نمایش داده می‌شود)
        $pdo->prepare("UPDATE ex_popups SET target=?, bot_id=?, audience=?, pages=?, title=?, body=?, image_url=?, btn_text=?, btn_url=?, freq=?, delay_sec=?, start_at=?, end_at=?, is_active=?, sort_order=?, updated_at=?, version=version+1 WHERE id=? AND owner_id=?")
            ->execute(array_merge($v, [$id, (int)$owner_id]));
    } else {
        $pdo->prepare("INSERT INTO ex_popups (target, bot_id, audience, pages, title, body, image_url, btn_text, btn_url, freq, delay_sec, start_at, end_at, is_active, sort_order, updated_at, owner_id, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array_merge($v, [(int)$owner_id, ex_now()]));
        $id = (int)$pdo->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}

function ex_popups_list($pdo, $owner_id)
{
    $s = $pdo->prepare("SELECT * FROM ex_popups WHERE owner_id=? ORDER BY is_active DESC, sort_order ASC, id DESC");
    $s->execute([(int)$owner_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

// =============================================================================
// تبلیغ در چت‌بات رایگان
// =============================================================================
function ex_ads($pdo, $bot_id, $active_only = true)
{
    $s = $pdo->prepare("SELECT * FROM ex_ads WHERE bot_id=?" . ($active_only ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, id ASC");
    $s->execute([(int)$bot_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function ex_ad_save($pdo, $bot_id, $user_id, array $in, $file = null)
{
    $id = (int)($in['ad_id'] ?? 0);
    $url = trim((string)($in['url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) return ['ok' => false, 'error' => 'آدرس لینک تبلیغ معتبر نیست.'];
    $img = trim((string)($in['image_url'] ?? ''));
    $up = ex_upload_image($file, $user_id);
    if ($up === '' && ex_file_given($file)) return ['ok' => false, 'error' => 'تصویر قابل قبول نیست (فقط jpg، png، gif یا webp تا ۲ مگابایت).'];
    if ($up !== '') $img = $up;
    if ($img !== '' && !preg_match('#^https?://#i', $img)) $img = '';
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 600);
    if ($title === '' && $body === '' && $img === '') return ['ok' => false, 'error' => 'عنوان، متن یا تصویر تبلیغ را وارد کنید.'];
    $v = [$title, $body, mb_substr($img, 0, 500), mb_substr(trim((string)($in['btn_text'] ?? '')), 0, 80), mb_substr($url, 0, 500), !empty($in['is_active']) ? 1 : 0, (int)($in['sort_order'] ?? 0)];
    if ($id) $pdo->prepare("UPDATE ex_ads SET title=?, body=?, image_url=?, btn_text=?, url=?, is_active=?, sort_order=? WHERE id=? AND bot_id=?")->execute(array_merge($v, [$id, (int)$bot_id]));
    else { $pdo->prepare("INSERT INTO ex_ads (title, body, image_url, btn_text, url, is_active, sort_order, bot_id, created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute(array_merge($v, [(int)$bot_id, ex_now()])); $id = (int)$pdo->lastInsertId(); }
    return ['ok' => true, 'id' => $id];
}

/** تبلیغ نوبتی بعد از هر N پاسخ (فقط برای مخاطب در حالت رایگان) */
function ex_ad_pick($pdo, $bot, $member, $free)
{
    if (!$free || !empty($member['is_owner'])) return null;
    $every = (int)($bot['ads_every'] ?? 3);
    if ($every <= 0 || !ex_owner_allows($pdo, (int)$bot['user_id'], 'has_bot_ads')) return null;
    $ads = ex_ads($pdo, (int)$bot['id']);
    if (!$ads) return null;
    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND member_id=? AND role='assistant'");
    $c->execute([(int)$bot['id'], (int)$member['id']]);
    $n = (int)$c->fetchColumn();
    if ($n <= 0 || $n % $every !== 0) return null;
    usort($ads, fn($a, $b) => [(int)$a['views'], (int)$a['sort_order']] <=> [(int)$b['views'], (int)$b['sort_order']]);
    $ad = $ads[0];
    $pdo->prepare("UPDATE ex_ads SET views = views + 1 WHERE id=?")->execute([(int)$ad['id']]);
    return ['id' => (int)$ad['id'], 'title' => $ad['title'], 'body' => $ad['body'], 'image' => ex_img_ref($ad['image_url']), 'btn' => $ad['btn_text'], 'url' => $ad['url']];
}

// =============================================================================
// لینک‌های پایگاه دانش (ویجت: bot_id=0، چت‌بات: bot_id)
// =============================================================================
function ex_links($pdo, $user_id, $bot_id = 0, $active_only = true)
{
    $s = $pdo->prepare("SELECT * FROM ex_links WHERE user_id=? AND bot_id=?" . ($active_only ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, id ASC");
    $s->execute([(int)$user_id, (int)$bot_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/** بلوک لینک‌های مرتبط برای پرامپت */
function ex_links_block($pdo, $user_id, $bot_id, $query)
{
    $links = ex_links($pdo, $user_id, $bot_id);
    if (!$links) return '';
    if (count($links) > 12) {
        $w = ex_words($query);
        foreach ($links as &$l) $l['_s'] = ex_score($w, $l['title'] . ' ' . $l['description']);
        unset($l);
        usort($links, fn($a, $b) => $b['_s'] <=> $a['_s']);
        $links = array_slice($links, 0, 12);
    }
    $lines = [];
    foreach ($links as $l) $lines[] = '- ' . $l['title'] . ($l['description'] !== '' ? ' — ' . $l['description'] : '') . ': ' . $l['url'];
    return "\n\n=== لینک‌های مفید ===\nاگر یکی از این لینک‌ها دقیقاً به سوال مربوط است، آن را (با عنوانش) به کاربر پیشنهاد بده؛ در غیر این صورت نام نبر. هرگز لینکی خارج از این فهرست نساز.\n" . implode("\n", $lines);
}

/** عملیات فرم لینک‌ها — خروجی: پیام (ok:/error:) یا null اگر درخواست مربوط نبود */
function ex_links_handle($pdo, $user_id, $bot_id, array $in)
{
    $act = (string)($in['act'] ?? '');
    if ($act === 'link_save') {
        $id = (int)($in['link_id'] ?? 0);
        $url = trim((string)($in['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
        if (mb_strlen($title) < 2 || !filter_var($url, FILTER_VALIDATE_URL)) return 'error:عنوان و آدرس معتبر لینک را وارد کنید.';
        $v = [$title, mb_substr($url, 0, 500), mb_substr(trim((string)($in['description'] ?? '')), 0, 500), !empty($in['is_active']) ? 1 : 0, (int)($in['sort_order'] ?? 0)];
        if ($id) $pdo->prepare("UPDATE ex_links SET title=?, url=?, description=?, is_active=?, sort_order=? WHERE id=? AND user_id=? AND bot_id=?")->execute(array_merge($v, [$id, (int)$user_id, (int)$bot_id]));
        else $pdo->prepare("INSERT INTO ex_links (title, url, description, is_active, sort_order, user_id, bot_id, created_at) VALUES(?,?,?,?,?,?,?,?)")->execute(array_merge($v, [(int)$user_id, (int)$bot_id, ex_now()]));
        return 'ok:لینک ذخیره شد.';
    }
    if ($act === 'link_del') {
        $pdo->prepare("DELETE FROM ex_links WHERE id=? AND user_id=? AND bot_id=?")->execute([(int)($in['link_id'] ?? 0), (int)$user_id, (int)$bot_id]);
        return 'ok:لینک حذف شد.';
    }
    if ($act === 'link_toggle') {
        $pdo->prepare("UPDATE ex_links SET is_active = 1 - is_active WHERE id=? AND user_id=? AND bot_id=?")->execute([(int)($in['link_id'] ?? 0), (int)$user_id, (int)$bot_id]);
        return 'ok:وضعیت لینک تغییر کرد.';
    }
    return null;
}

/** فرم و فهرست لینک‌ها (مشترک ویجت و چت‌بات) */
function ex_links_ui($pdo, $user_id, $bot_id, $base_url, $csrf = '')
{
    $links = ex_links($pdo, $user_id, $bot_id, false);
    $edit = null;
    foreach ($links as $l) if ((int)$l['id'] === (int)($_GET['edit_link'] ?? 0)) $edit = $l;
    $tok = $csrf !== '' ? '<input type="hidden" name="csrf_token" value="' . ex_h($csrf) . '">' : '';
    ob_start(); ?>
<div class="card" id="links">
    <div class="card-title">🔗 لینک‌های مفید <span style="font-weight:normal;font-size:12px;color:#64748b">(<?php echo count($links); ?>)</span></div>
    <p style="font-size:12.5px;color:#64748b;line-height:2;margin-bottom:10px">هوش مصنوعی وقتی سوال به یکی از این لینک‌ها مربوط باشد، آن را با عنوانش پیشنهاد می‌دهد (مثل صفحه ثبت‌نام، فرم نوبت‌دهی، مقاله مرتبط). توضیح کوتاه کمک می‌کند لینک درست انتخاب شود.</p>
    <form method="post" data-pg="linkform">
        <?php echo $tok; ?><input type="hidden" name="act" value="link_save"><input type="hidden" name="link_id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <div class="row2">
            <div class="form-group"><label>عنوان لینک</label><input type="text" name="title" maxlength="200" required value="<?php echo ex_h($edit['title'] ?? ''); ?>" placeholder="مثلاً: فرم رزرو نوبت"></div>
            <div class="form-group"><label>آدرس</label><input type="text" name="url" dir="ltr" maxlength="500" required value="<?php echo ex_h($edit['url'] ?? ''); ?>" placeholder="https://example.com/booking"></div>
        </div>
        <div class="form-group"><label>توضیح مختصر (چه وقت این لینک پیشنهاد شود)</label><input type="text" name="description" maxlength="500" value="<?php echo ex_h($edit['description'] ?? ''); ?>" placeholder="مثلاً: برای گرفتن وقت مشاوره حضوری یا آنلاین"></div>
        <div class="row2">
            <div class="form-group"><label>ترتیب</label><input type="number" name="sort_order" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>"></div>
            <div class="form-group" style="display:flex;align-items:flex-end"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" style="width:17px;height:17px" <?php echo !$edit || !empty($edit['is_active']) ? 'checked' : ''; ?>> فعال</label></div>
        </div>
        <button class="btn btn-primary btn-sm"><?php echo $edit ? '💾 ذخیره لینک' : '➕ افزودن لینک'; ?></button>
        <?php if ($edit): ?><a class="btn btn-outline btn-sm" href="<?php echo ex_h($base_url); ?>#links">انصراف</a><?php endif; ?>
    </form>
    <?php if ($links): ?>
    <div class="table-wrap" style="margin-top:12px"><table><thead><tr><th>عنوان</th><th>آدرس</th><th>توضیح</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($links as $l): ?>
        <tr><td><b><?php echo ex_h($l['title']); ?></b></td>
            <td dir="ltr" style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis"><a href="<?php echo ex_h($l['url']); ?>" target="_blank" rel="noopener"><?php echo ex_h($l['url']); ?></a></td>
            <td style="font-size:12px;color:#64748b;max-width:260px"><?php echo ex_h($l['description']); ?></td>
            <td><?php echo $l['is_active'] ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>'; ?></td>
            <td style="white-space:nowrap">
                <a class="btn btn-outline btn-sm" href="<?php echo ex_h($base_url . (strpos($base_url, '?') === false ? '?' : '&') . 'edit_link=' . (int)$l['id']); ?>#links">ویرایش</a>
                <form method="post" style="display:inline"><?php echo $tok; ?><input type="hidden" name="act" value="link_toggle"><input type="hidden" name="link_id" value="<?php echo (int)$l['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $l['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><?php echo $tok; ?><input type="hidden" name="act" value="link_del"><input type="hidden" name="link_id" value="<?php echo (int)$l['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
            </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<?php
    return ob_get_clean();
}

// =============================================================================
// نظارت مشاور و مشاور اختصاصی
// =============================================================================
function ex_supervision_log($pdo, $bot, $member_id, $team_id, $actor)
{
    try {
        $pdo->prepare("INSERT INTO ex_supervisions (bot_id, member_id, user_id, team_id, actor, created_at) VALUES(?,?,?,?,?,?)")
            ->execute([(int)$bot['id'], (int)$member_id, (int)$bot['user_id'], (int)$team_id, mb_substr((string)$actor, 0, 140), ex_now()]);
    } catch (\Throwable $e) { error_log('[EX] supervision: ' . $e->getMessage()); }
}

/** سوابق نظارت (برای یک مراجع یا یک مشاور) */
function ex_supervisions($pdo, array $f, $limit = 100)
{
    $w = []; $a = [];
    foreach (['bot_id', 'member_id', 'user_id', 'team_id'] as $k) if (isset($f[$k])) { $w[] = "s.$k=?"; $a[] = (int)$f[$k]; }
    $sql = "SELECT s.*, m.name AS member_name, m.mobile AS member_mobile, b.name AS bot_name FROM ex_supervisions s LEFT JOIN hd_members m ON m.id=s.member_id LEFT JOIN hd_bots b ON b.id=s.bot_id"
         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY s.id DESC LIMIT " . (int)$limit;
    $s = $pdo->prepare($sql);
    $s->execute($a);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function ex_supervision_count($pdo, array $f)
{
    $w = []; $a = [];
    foreach (['bot_id', 'member_id', 'user_id', 'team_id'] as $k) if (isset($f[$k])) { $w[] = "$k=?"; $a[] = (int)$f[$k]; }
    $s = $pdo->prepare("SELECT COUNT(*) FROM ex_supervisions" . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
    $s->execute($a);
    return (int)$s->fetchColumn();
}

/** آیا این همکار (یا صاحب حساب) به پرونده این مراجع دسترسی دارد؟ (اجازه مراجع + مشاور اختصاصی) */
function ex_member_visible($member, $team)
{
    if (!empty($member['is_owner'])) return true;
    if (empty($member['consent_share'])) return false;
    $cid = (int)($member['consultant_id'] ?? 0);
    if ($cid <= 0 || !$team) return true;          // مشاور اختصاصی ندارد، یا صاحب حساب است
    return (int)$team['id'] === $cid;
}

// =============================================================================
// دسته‌بندی موضوعی هوشمند پیام‌های مراجع (فقط برای مشاور)
// =============================================================================
function ex_topic_cats($pdo, $bot_id, $active_only = true)
{
    $s = $pdo->prepare("SELECT * FROM ex_topic_cats WHERE bot_id=?" . ($active_only ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, id ASC");
    $s->execute([(int)$bot_id]);
    return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/** بعد از تعریف/ویرایش دسته: پیام‌های قبلی دوباره بررسی شوند */
function ex_topics_reset($pdo, $bot_id)
{
    $pdo->prepare("UPDATE hd_messages SET topic_done=0 WHERE bot_id=? AND role='user'")->execute([(int)$bot_id]);
}

/**
 * بررسی پیام‌های بررسی‌نشده مراجعانی که اجازه داده‌اند و ثبت در دسته‌ها
 * @return int تعداد پیام بررسی‌شده
 */
function ex_topics_run($pdo, $bot_id = 0, $limit = 60)
{
    $done = 0;
    $bots = [];
    if ($bot_id) { $b = function_exists('hd_get_bot') ? hd_get_bot($pdo, (int)$bot_id) : null; if ($b) $bots[] = $b; }
    else {
        try {
            $ids = $pdo->query("SELECT DISTINCT bot_id FROM ex_topic_cats WHERE is_active=1")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($ids as $bid) { $b = hd_get_bot($pdo, (int)$bid); if ($b) $bots[] = $b; }
        } catch (\Throwable $e) { return 0; }
    }
    foreach ($bots as $bot) {
        if ($done >= $limit) break;
        if (!ex_owner_allows($pdo, (int)$bot['user_id'], 'has_topic_ai')) continue;
        $cats = ex_topic_cats($pdo, (int)$bot['id']);
        if (!$cats) continue;
        $reason = '';
        if (function_exists('hd_owner_ok') && !hd_owner_ok($pdo, $bot, $reason)) continue;
        $s = $pdo->prepare("SELECT x.id, x.member_id, x.thread_id, x.content, x.created_at FROM hd_messages x JOIN hd_members m ON m.id=x.member_id
                            WHERE x.bot_id=? AND x.role='user' AND x.topic_done=0 AND m.consent_share=1 AND m.is_owner=0 ORDER BY x.id ASC LIMIT " . (int)min(20, $limit - $done));
        $s->execute([(int)$bot['id']]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (!$rows) continue;
        $done += ex_topics_classify($pdo, $bot, $cats, $rows);
    }
    return $done;
}

function ex_topics_classify($pdo, $bot, array $cats, array $rows)
{
    $cl = [];
    foreach ($cats as $i => $c) $cl[] = ($i + 1) . ') ' . $c['name'] . ($c['description'] !== '' ? ' — ' . $c['description'] : '');
    $ml = [];
    foreach ($rows as $r) $ml[] = '#' . (int)$r['id'] . ': ' . str_replace(["\r", "\n"], ' ', mb_substr((string)$r['content'], 0, 700));
    $sys = "تو دستیار تحلیل محرمانه یک مشاور هستی. برای هر پیام مراجع، شماره دسته‌هایی را که محتوای پیام «به‌روشنی» نشان‌دهنده آن است مشخص کن. اگر هیچ دسته‌ای روشن نیست، آرایه خالی بده. حدس نزن.\n"
         . "دسته‌ها:\n" . implode("\n", $cl) . "\n\nخروجی فقط JSON به این شکل باشد: {\"شناسه پیام\": [شماره دسته‌ها], ...} — مثال: {\"12\": [1], \"13\": []}";
    $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => implode("\n", $ml)]];
    $cfg = saas_get_api_config($pdo);
    $model = null;
    if (!empty($bot['ai_model_id']) && function_exists('biz_model_by_id')) $model = biz_model_by_id($pdo, (int)$bot['ai_model_id']);
    $r = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, 600, 0) : aichat_call_ai($cfg, $msgs, 600, 0);
    if (empty($r['ok'])) return 0;
    if (function_exists('biz_charge_tokens')) {
        $cost = biz_charge_tokens($pdo, $model, $r);
        if ($cost > 0) saas_deduct_credit($pdo, (int)$bot['user_id'], $cost, 'دسته‌بندی موضوعی گفتگوها: ' . $bot['name']);
    }
    $txt = (string)$r['content'];
    if (preg_match('/\{.*\}/su', $txt, $m)) $txt = $m[0];
    $map = json_decode($txt, true);
    if (!is_array($map)) $map = [];
    $by_id = [];
    foreach ($rows as $row) $by_id[(int)$row['id']] = $row;
    $ins = $pdo->prepare("INSERT INTO ex_topic_hits (bot_id, member_id, cat_id, message_id, thread_id, excerpt, msg_at, created_at) VALUES(?,?,?,?,?,?,?,?)");
    $chk = $pdo->prepare("SELECT COUNT(*) FROM ex_topic_hits WHERE message_id=? AND cat_id=?");
    foreach ($map as $mid => $nums) {
        $mid = (int)preg_replace('/\D+/', '', (string)$mid);
        if (!isset($by_id[$mid]) || !is_array($nums)) continue;
        foreach ($nums as $n) {
            $c = $cats[(int)$n - 1] ?? null;
            if (!$c) continue;
            $chk->execute([$mid, (int)$c['id']]);
            if ((int)$chk->fetchColumn()) continue;
            $row = $by_id[$mid];
            $ins->execute([(int)$bot['id'], (int)$row['member_id'], (int)$c['id'], $mid, (int)$row['thread_id'], mb_substr((string)$row['content'], 0, 3000), $row['created_at'], ex_now()]);
        }
    }
    $pdo->prepare("UPDATE hd_messages SET topic_done=1 WHERE id IN (" . implode(',', array_map('intval', array_keys($by_id))) . ")")->execute();
    return count($rows);
}

/** بعد از هر پاسخ چت‌بات، پیام‌های جدید در پس‌زمینه دسته‌بندی شوند */
function ex_topics_schedule($pdo, $bot)
{
    static $reg = [];
    if (isset($reg[(int)$bot['id']])) return;
    $reg[(int)$bot['id']] = 1;
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM ex_topic_cats WHERE bot_id=? AND is_active=1");
        $c->execute([(int)$bot['id']]);
        if (!(int)$c->fetchColumn()) return;
    } catch (\Throwable $e) { return; }
    register_shutdown_function(function () use ($pdo, $bot) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        try { ex_topics_run($pdo, (int)$bot['id'], 20); } catch (\Throwable $e) { error_log('[EX] topics: ' . $e->getMessage()); }
    });
}

/** پیام‌های دسته‌بندی‌شده یک مراجع (برای پنل مشاور) */
function ex_topic_hits_member($pdo, $bot_id, $member_id)
{
    $s = $pdo->prepare("SELECT h.*, c.name AS cat_name FROM ex_topic_hits h JOIN ex_topic_cats c ON c.id=h.cat_id WHERE h.bot_id=? AND h.member_id=? ORDER BY c.sort_order, c.id, h.msg_at DESC");
    $s->execute([(int)$bot_id, (int)$member_id]);
    $out = [];
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['cat_id']][] = $r;
    return $out;
}

// =============================================================================
// اولویت‌بندی امکانات پلن‌ها
// =============================================================================
/** امکانات پلن به ترتیب اولویت تعیین‌شده توسط مدیر + امکانات اضافه دلخواه */
function ex_plan_feature_list_sorted($plan)
{
    $items = function_exists('saas_plan_feature_list') ? saas_plan_feature_list($plan) : [];
    // دسته پلن (نسخه ۴۵): امکانات نامرتبط با دسته پنهان + فیلدهای اختصاصی دسته بعد از «اشتراک یک‌ساله»
    if (function_exists('pc_plan_cat') && function_exists('aichat_connect')) {
        try {
            $pc_cat = pc_plan_cat(aichat_connect(), $plan);
            if ($pc_cat) {
                $flags = function_exists('saas_plan_features') ? saas_plan_features() : [];
                $items = array_values(array_filter($items, fn($it) => !isset($flags[$it['key'] ?? '']) || pc_feature_allowed($pc_cat, $it['key'])));
                $cf = pc_field_items($pc_cat, $plan);
                if ($cf) array_splice($items, min(1, count($items)), 0, $cf);
            }
        } catch (\Throwable $e) {}
    }
    $li = (int)($plan['limit_in'] ?? 0); $lo = (int)($plan['limit_out'] ?? 0);
    if ($li > 0 || $lo > 0) $items[] = ['text' => 'سقف مصرف هر پیام: ' . ($li > 0 ? number_format($li) . ' توکن ورودی' : 'ورودی نامحدود') . ' / ' . ($lo > 0 ? number_format($lo) . ' توکن خروجی' : 'خروجی نامحدود'), 'note' => '', 'key' => 'limits'];
    $i = 0;
    foreach (preg_split('/\r\n|\r|\n/', trim((string)($plan['extra_features'] ?? ''))) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $items[] = ['text' => mb_substr($line, 0, 200), 'note' => '', 'key' => 'x' . (++$i)];
    }
    foreach ($items as $k => &$it) if (empty($it['key'])) $it['key'] = 'k' . md5($it['text']);
    unset($it);
    $order = array_values(array_filter(explode(',', (string)($plan['feature_order'] ?? ''))));
    if ($order) {
        $pos = array_flip($order);
        $idx = 0;
        foreach ($items as &$it) { $it['_o'] = isset($pos[$it['key']]) ? $pos[$it['key']] : (strpos((string)$it['key'], 'cf_') === 0 ? -100 + $idx : 1000 + $idx); $idx++; }
        unset($it);
        usort($items, fn($a, $b) => $a['_o'] <=> $b['_o']);
    }
    return $items;
}

// =============================================================================
// سمت مخاطب چت‌بات (فایل رابط)
// =============================================================================
/** محدودیت ساده تعداد درخواست (فایل در پوشه آپلود) — true یعنی بیش از حد */
function ex_rate_limited($key, $max, $window_sec)
{
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.rl';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/' . md5((string)$key) . '.json';
    $now = time();
    $list = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $list = array_values(array_filter($list, fn($t) => $t > $now - $window_sec));
    if (count($list) >= $max) return true;
    $list[] = $now;
    @file_put_contents($f, json_encode($list), LOCK_EX);
    return false;
}

/** اطلاعات اضافه برای صفحه چت مخاطب (در پاسخ me و send) */
function ex_member_ext($pdo, $bot, $member, $mm)
{
    $free = empty($member['is_owner']) && (($mm['use'] ?? 'bot') === 'free');
    $uid = (int)$bot['user_id'];
    $out = ['free' => $free, 'support' => (!isset($bot['support_on']) || !empty($bot['support_on'])) && ex_owner_allows($pdo, $uid, 'has_member_support'), 'notice' => '', 'popups' => [], 'unread_tickets' => 0];
    if ($free && !empty($bot['free_notice_on']) && trim((string)($bot['free_notice'] ?? '')) !== '' && ex_owner_allows($pdo, $uid, 'has_bot_ads')) $out['notice'] = trim((string)$bot['free_notice']);
    if (ex_owner_allows($pdo, $uid, 'has_popups') && empty($member['_imp'])) {
        $out['popups'] = ex_popups_for($pdo, 'bot', ['owner_id' => $uid, 'bot_id' => (int)$bot['id'], 'free' => $free]);
        foreach ($out['popups'] as &$pp) $pp['image'] = ex_img_ref($pp['image']);
        unset($pp);
    }
    if ($out['support']) {
        try { $s = $pdo->prepare("SELECT COUNT(*) FROM ex_tickets WHERE scope='bot' AND bot_id=? AND member_id=? AND unread_member=1"); $s->execute([(int)$bot['id'], (int)$member['id']]); $out['unread_tickets'] = (int)$s->fetchColumn(); } catch (\Throwable $e) {}
    }
    return $out;
}

/** تعداد نظارت مشاور در «پرونده من» مخاطب */
function ex_member_profile_extra($pdo, $bot, $member)
{
    try {
        $n = ex_supervision_count($pdo, ['bot_id' => (int)$bot['id'], 'member_id' => (int)$member['id']]);
        $last = $n ? (ex_supervisions($pdo, ['bot_id' => (int)$bot['id'], 'member_id' => (int)$member['id']], 1)[0]['created_at'] ?? '') : '';
        return ['supervisions' => $n, 'supervision_last' => (string)$last];
    } catch (\Throwable $e) { return []; }
}

/** بعد از هر پاسخ: تبلیغ نوبتی (رایگان) و پیشنهاد مدل بهتر */
function ex_after_answer($pdo, $bot, $member, $message, $no_answer, $mm, $used_model, $quota)
{
    $out = [];
    $free = empty($member['is_owner']) && (($mm['use'] ?? 'bot') === 'free');
    $ad = ex_ad_pick($pdo, $bot, $member, $free);
    if ($ad) $out['ad'] = $ad;
    if (isset($bot['suggest_model']) && empty($bot['suggest_model'])) return $out;
    $list = $mm['list'] ?? [];
    $complex = $no_answer || mb_strlen((string)$message) > 220 || preg_match('/(تحلیل|مقایسه|برنامه‌ریزی|برنامه ریزی|جزئیات|دقیق|کامل|توضیح بده|چرا|راهکار|استراتژی)/u', (string)$message);
    if (!$complex) return $out;
    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND member_id=? AND role='assistant'");
    $c->execute([(int)$bot['id'], (int)$member['id']]);
    $n = (int)$c->fetchColumn();
    if (!$no_answer && $n % 3 !== 1) return $out;   // هر چند پاسخ یک بار
    // چت رایگان و مدل تخصصی قفل: پیشنهاد ارتقای بسته
    $locked = $mm['locked'] ?? [];
    if ($free && $locked && ((function_exists('hd_bot_sells') && hd_bot_sells($pdo, $bot)) || (function_exists('cx_has_free_plan') && cx_has_free_plan($pdo, $bot)))) {
        $out['suggest'] = ['kind' => 'plan', 'text' => 'برای پاسخ دقیق‌تر با مدل تخصصی «' . $locked[count($locked) - 1]['title'] . '» و امکانات بیشتر، بسته خود را ارتقا دهید.'];
        return $out;
    }
    if (count($list) > 1) {
        $best = end($list);
        $cur = $used_model ? (int)$used_model['id'] : (int)($mm['default'] ?? 0);
        $cur_ratio = 0;
        foreach ($list as $x) if ((int)$x['id'] === $cur) $cur_ratio = (float)$x['ratio'];
        if ($best && (int)$best['id'] !== $cur && (float)$best['ratio'] > $cur_ratio) {
            $out['suggest'] = ['kind' => 'model', 'id' => (int)$best['id'], 'title' => (string)$best['title'],
                               'text' => 'برای پاسخ دقیق‌تر و کامل‌تر می‌توانید از مدل «' . $best['title'] . '» استفاده کنید' . ((int)$best['cost'] > 1 ? ' (هر پیام = ' . (int)$best['cost'] . ' پیام)' : '') . '.'];
        }
    } elseif ($free && function_exists('hd_bot_sells') && hd_bot_sells($pdo, $bot)) {
        $out['suggest'] = ['kind' => 'plan', 'text' => 'برای پاسخ‌های دقیق‌تر با مدل‌های قوی‌تر و امکانات بخش تخصصی، یکی از بسته‌ها را تهیه کنید.'];
    }
    return $out;
}

/** عملیات صفحه چت مخاطب: پشتیبانی، تیکت، راهنما، کلیک تبلیغ */
function ex_member_dispatch($pdo, $bot, $member, $action, $in)
{
    $acts = ['help', 'support_ask', 'tickets', 'ticket', 'ticket_new', 'ticket_reply', 'ticket_close', 'ad_click', 'popup_hit'];
    if (!in_array($action, $acts, true)) return null;
    $bid = (int)$bot['id'];
    if ($action === 'popup_hit') {
        if (!empty($member['_imp'])) return ['ok' => true];
        try {
            $s = $pdo->prepare("SELECT id FROM ex_popups WHERE id=? AND owner_id=? AND target='bot' AND (bot_id=0 OR bot_id=?)");
            $s->execute([(int)($in['value'] ?? 0), (int)$bot['user_id'], $bid]);
            if ($pid = (int)$s->fetchColumn()) ex_popup_hit($pdo, $pid, ($in['title'] ?? '') === 'click' ? 'clicks' : 'views');
        } catch (\Throwable $e) {}
        return ['ok' => true];
    }
    if ($action === 'ad_click') {
        try { $pdo->prepare("UPDATE ex_ads SET clicks = clicks + 1 WHERE id=? AND bot_id=?")->execute([(int)($in['ad_id'] ?? 0), $bid]); } catch (\Throwable $e) {}
        return ['ok' => true];
    }
    if ((isset($bot['support_on']) && empty($bot['support_on'])) || !ex_owner_allows($pdo, (int)$bot['user_id'], 'has_member_support')) return ['ok' => false, 'error' => 'بخش پشتیبانی فعال نیست.'];
    $mid = (int)$member['id'];
    switch ($action) {
        case 'help':
            $map = function ($a) { return ['id' => (int)$a['id'], 'title' => $a['title'], 'body' => $a['body']]; };
            $gl = function_exists('cx_bot_articles') ? cx_bot_articles($pdo, $bot, 'guide') : ex_articles($pdo, 'bot', $bid, 'guide');
            $fl = function_exists('cx_bot_articles') ? cx_bot_articles($pdo, $bot, 'faq') : ex_articles($pdo, 'bot', $bid, 'faq');
            return ['ok' => true, 'guide' => array_map($map, $gl), 'faq' => array_map($map, $fl)];
        case 'support_ask':
            $reason = '';
            if (function_exists('hd_owner_ok') && !hd_owner_ok($pdo, $bot, $reason)) return ['ok' => false, 'error' => 'پشتیبان هوشمند فعلاً در دسترس نیست؛ لطفاً تیکت ثبت کنید.', 'ticket' => true];
            if (ex_rate_limited('sup:' . $bid . ':' . $mid, 20, 3600)) return ['ok' => false, 'error' => 'تعداد سوال‌ها زیاد است؛ کمی بعد دوباره بپرسید یا تیکت ثبت کنید.', 'ticket' => true];
            $hist = json_decode((string)($in['value'] ?? '[]'), true);
            return ex_support_answer($pdo, 'bot', $bot, (string)($in['message'] ?? ''), is_array($hist) ? $hist : []);
        case 'tickets':
            $s = $pdo->prepare("SELECT id, subject, status, unread_member, updated_at FROM ex_tickets WHERE scope='bot' AND bot_id=? AND member_id=? ORDER BY updated_at DESC LIMIT 50");
            $s->execute([$bid, $mid]);
            return ['ok' => true, 'tickets' => $s->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'statuses' => ex_ticket_statuses()];
        case 'ticket':
            $t = ex_ticket_get($pdo, (int)($in['ticket_id'] ?? 0));
            if (!$t || $t['scope'] !== 'bot' || (int)$t['bot_id'] !== $bid || (int)$t['member_id'] !== $mid) return ['ok' => false, 'error' => 'تیکت یافت نشد.'];
            $pdo->prepare("UPDATE ex_tickets SET unread_member=0 WHERE id=?")->execute([(int)$t['id']]);
            $msgs = array_map(fn($m) => ['mine' => $m['sender'] === 'member', 'author' => $m['sender'] === 'member' ? 'شما' : ($m['author'] !== '' ? $m['author'] : 'پشتیبانی'), 'body' => $m['body'], 'at' => $m['created_at']], ex_ticket_msgs($pdo, $t['id']));
            return ['ok' => true, 'ticket' => ['id' => (int)$t['id'], 'subject' => $t['subject'], 'status' => $t['status'], 'status_label' => ex_ticket_statuses()[$t['status']] ?? ''], 'messages' => $msgs];
        case 'ticket_new':
            $c = $pdo->prepare("SELECT COUNT(*) FROM ex_tickets WHERE scope='bot' AND bot_id=? AND member_id=? AND created_at >= ?");
            $c->execute([$bid, $mid, ex_now(-86400)]);
            if ((int)$c->fetchColumn() >= 5) return ['ok' => false, 'error' => 'تعداد تیکت‌های امروز شما زیاد است؛ لطفاً در تیکت‌های قبلی پیگیری کنید.'];
            $r = ex_ticket_create($pdo, 'bot', (int)$bot['user_id'], $bid, $mid, $in['title'] ?? '', $in['message'] ?? '', 'member', (string)($member['name'] ?? 'مخاطب'));
            return $r['ok'] ? ['ok' => true, 'ticket_id' => $r['id']] : ['ok' => false, 'error' => $r['error']];
        case 'ticket_reply':
        case 'ticket_close':
            $t = ex_ticket_get($pdo, (int)($in['ticket_id'] ?? 0));
            if (!$t || $t['scope'] !== 'bot' || (int)$t['bot_id'] !== $bid || (int)$t['member_id'] !== $mid) return ['ok' => false, 'error' => 'تیکت یافت نشد.'];
            if ($action === 'ticket_close') { ex_ticket_set_status($pdo, $t['id'], 'closed'); return ['ok' => true]; }
            if ($t['status'] === 'closed') return ['ok' => false, 'error' => 'این تیکت بسته شده است؛ تیکت جدید ثبت کنید.'];
            return ex_ticket_reply($pdo, $t, 'member', (string)($member['name'] ?? 'مخاطب'), $in['message'] ?? '');
    }
    return null;
}

// =============================================================================
// زمان‌بندی (اجرا از cron سامانه)
// =============================================================================
function ex_cron($pdo)
{
    try { return ['topics' => ex_topics_run($pdo, 0, 200)]; } catch (\Throwable $e) { error_log('[EX] cron: ' . $e->getMessage()); return []; }
}
