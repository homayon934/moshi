<?php
/**
 * همدم — سازنده چت‌بات تخصصی (بدون نام پلتفرم / White-label)
 *
 * هر کاربر پنل می‌تواند چند ربات تخصصی بسازد، منابع معتبر به آن بدهد
 * و آن را با «فایل رابط» روی دامنه خودش در اختیار مخاطبانش بگذارد.
 *
 * نکته فنی: در این کتابخانه از NOW()/INTERVAL/ON DUPLICATE استفاده نمی‌شود
 * (زمان در PHP ساخته می‌شود) و LIMIT همیشه عدد صحیح درج می‌شود.
 */

if (!defined('HD_LOADED')) {
define('HD_LOADED', 1);
define('HD_SCHEMA_VERSION', 1);   // با هر تغییر ساختار دیتابیس چت‌بات یک واحد اضافه شود

// پیام‌های ثابت برای مخاطب (بدون جزئیات فنی و بدون نام پلتفرم)
define('HD_MSG_DOWN', 'با توجه به قطعی شبکه، فعلاً امکان پاسخگویی نیست.');
define('HD_MSG_UNAVAILABLE', 'این سرویس در حال حاضر در دسترس نیست. لطفاً بعداً تلاش کنید.');

function hd_now($offset_sec = 0) { return date('Y-m-d H:i:s', time() + (int)$offset_sec); }

// =============================================================================
// ساختار دیتابیس (MySQL / MariaDB)
// =============================================================================
function hd_ensure_schema($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    // اگر نسخه جاری قبلاً ساخته شده، بررسی‌های سنگین ساختار انجام نمی‌شود (سرعت بیشتر همه درخواست‌ها)
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.hd_schema_v' . HD_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) {
        if (function_exists('cx_ensure_schema')) { try { cx_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[CX] ' . $e->getMessage()); } }
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_bots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        bot_key CHAR(48) NOT NULL,
        template VARCHAR(30) NOT NULL DEFAULT 'custom',
        specialty VARCHAR(200) NOT NULL DEFAULT '',
        avatar_url VARCHAR(500) NOT NULL DEFAULT '',
        primary_color VARCHAR(20) NOT NULL DEFAULT '#2563eb',
        welcome_message TEXT NULL,
        instructions MEDIUMTEXT NULL,
        disclaimer TEXT NULL,
        strict_mode TINYINT(1) NOT NULL DEFAULT 1,
        show_sources TINYINT(1) NOT NULL DEFAULT 1,
        verify_mobile TINYINT(1) NOT NULL DEFAULT 0,
        free_messages INT NOT NULL DEFAULT 20,
        daily_limit INT NOT NULL DEFAULT 20,
        response_length VARCHAR(10) NOT NULL DEFAULT 'medium',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        tokens_used BIGINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_key (bot_key),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL,
        title VARCHAR(300) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        content MEDIUMTEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        pages_total INT NOT NULL DEFAULT 0,
        pages_ok INT NOT NULL DEFAULT 0,
        last_sync DATETIME NULL,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        INDEX idx_bot (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        bot_id INT NOT NULL,
        url VARCHAR(500) NOT NULL,
        title VARCHAR(500) NOT NULL DEFAULT '',
        lastmod VARCHAR(40) NOT NULL DEFAULT '',
        status TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        INDEX idx_source (source_id),
        INDEX idx_bot (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_chunks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        source_id INT NOT NULL,
        page_id INT NOT NULL DEFAULT 0,
        title VARCHAR(500) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        chunk TEXT NOT NULL,
        norm TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_bot (bot_id),
        INDEX idx_source (source_id),
        INDEX idx_page (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_qa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        question VARCHAR(500) NOT NULL,
        answer TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_bot (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        mobile VARCHAR(20) NOT NULL DEFAULT '',
        name VARCHAR(100) NOT NULL DEFAULT '',
        token_hash CHAR(64) NOT NULL,
        verified TINYINT(1) NOT NULL DEFAULT 0,
        extra_messages INT NOT NULL DEFAULT 0,
        used_messages INT NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        is_owner TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        last_seen DATETIME NULL,
        INDEX idx_bot (bot_id),
        INDEX idx_token (token_hash),
        INDEX idx_mobile (bot_id, mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        mobile VARCHAR(20) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_mobile (bot_id, mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_threads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_member (bot_id, member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thread_id INT NOT NULL,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        role VARCHAR(12) NOT NULL,
        content MEDIUMTEXT NOT NULL,
        sources TEXT NULL,
        tokens INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_thread (thread_id),
        INDEX idx_member_time (bot_id, member_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- فاز ۲: پلن‌های فروش پیام به مخاطبان و خریدها ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_member_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(300) NOT NULL DEFAULT '',
        price_toman INT NOT NULL DEFAULT 0,
        messages INT NOT NULL DEFAULT 100,
        days INT NOT NULL DEFAULT 30,
        daily_limit INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_bot (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        member_id INT NOT NULL,
        plan_id INT NOT NULL DEFAULT 0,
        plan_name VARCHAR(100) NOT NULL DEFAULT '',
        amount_toman INT NOT NULL DEFAULT 0,
        messages INT NOT NULL DEFAULT 0,
        used INT NOT NULL DEFAULT 0,
        daily_limit INT NOT NULL DEFAULT 0,
        days INT NOT NULL DEFAULT 0,
        expires_at DATETIME NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        authority VARCHAR(100) NOT NULL DEFAULT '',
        ref_code VARCHAR(100) NOT NULL DEFAULT '',
        return_url VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        paid_at DATETIME NULL,
        INDEX idx_member (bot_id, member_id),
        INDEX idx_status (bot_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- کدهای تخفیف بسته‌های مخاطبان ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS hd_discount_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        code VARCHAR(40) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'percent',
        value INT NOT NULL DEFAULT 0,
        plan_id INT NOT NULL DEFAULT 0,
        max_uses INT NOT NULL DEFAULT 0,
        per_member_limit INT NOT NULL DEFAULT 1,
        ends_at DATETIME NULL,
        used_count INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_code (bot_id, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- ستون‌های جدید (فاز ۲ و ۳) ---
    $alters = [
        "ALTER TABLE hd_bots ADD COLUMN zp_merchant VARCHAR(64) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_bots ADD COLUMN zp_sandbox TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE hd_bots ADD COLUMN sms_api_key VARCHAR(255) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_bots ADD COLUMN sms_sender VARCHAR(50) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_bots ADD COLUMN verify_answers TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE hd_bots ADD COLUMN live_search TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE hd_bots ADD COLUMN allowed_domains TEXT NULL",
        "ALTER TABLE hd_bots ADD COLUMN custom_domain VARCHAR(190) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_bots ADD COLUMN support_text VARCHAR(300) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_messages ADD COLUMN rating TINYINT NOT NULL DEFAULT 0",
        "ALTER TABLE hd_messages ADD COLUMN no_answer TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE hd_bots ADD INDEX idx_domain (custom_domain)",
        // تخفیف بسته‌ها، کد تخفیف و یادآوری انقضا
        "ALTER TABLE hd_bots ADD COLUMN remind_members TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE hd_member_plans ADD COLUMN discount_percent INT NOT NULL DEFAULT 0",
        "ALTER TABLE hd_member_plans ADD COLUMN discount_until DATETIME NULL",
        "ALTER TABLE hd_purchases ADD COLUMN original_amount INT NOT NULL DEFAULT 0",
        "ALTER TABLE hd_purchases ADD COLUMN discount_amount INT NOT NULL DEFAULT 0",
        "ALTER TABLE hd_purchases ADD COLUMN discount_code_id INT NOT NULL DEFAULT 0",
        // مدل هوش مصنوعی هر ربات و هزینه زیردامنه
        "ALTER TABLE hd_bots ADD COLUMN ai_model_id INT NOT NULL DEFAULT 0",
        "ALTER TABLE hd_bots ADD COLUMN domain_paid TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE hd_bots ADD COLUMN free_model_id INT NOT NULL DEFAULT 0",
        // ورود صاحب ربات به‌جای مخاطب (لینک یک‌بارمصرف + توکن جداگانه، بدون خارج کردن خود مخاطب)
        "ALTER TABLE hd_bots ADD COLUMN chat_url VARCHAR(300) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_members ADD COLUMN magic_hash CHAR(64) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_members ADD COLUMN magic_until DATETIME NULL",
        "ALTER TABLE hd_members ADD COLUMN imp_hash CHAR(64) NOT NULL DEFAULT ''",
        "ALTER TABLE hd_members ADD COLUMN imp_until DATETIME NULL",
    ];
    // Older MySQL/MariaDB versions reject ADD ... IF NOT EXISTS (SQLSTATE 42000 / 1064).
    // Check metadata first and execute only when the named object is absent.
    $hd_schema_object_exists = function ($table, $name, $kind) use ($pdo) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return false;
        }
        $table_sql = '`' . $table . '`';
        $name_sql = $pdo->quote($name);
        if ($kind === 'column') {
            $stmt = $pdo->query("SHOW COLUMNS FROM {$table_sql} WHERE Field = {$name_sql}");
        } else {
            $stmt = $pdo->query("SHOW INDEX FROM {$table_sql} WHERE Key_name = {$name_sql}");
        }
        return $stmt && $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    };
    foreach ($alters as $q) {
        try {
            if (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([A-Za-z0-9_]+)/i', $q, $m)) {
                if (!$hd_schema_object_exists($m[1], $m[2], 'column')) $pdo->exec($q);
            } elseif (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+(?:UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)/i', $q, $m)) {
                if (!$hd_schema_object_exists($m[1], $m[2], 'index')) $pdo->exec($q);
            } else {
                // Fail closed: skip statements not matching the expected migration forms.
            }
        } catch (\Throwable $e) {}
    }
    if (function_exists('hd_ext_schema')) hd_ext_schema($pdo);
    if (function_exists('ex_ensure_schema')) { try { ex_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[EX] ' . $e->getMessage()); } }
    if (function_exists('kb_ensure_schema')) { try { kb_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[KB] ' . $e->getMessage()); } }
    if (function_exists('cx_ensure_schema')) { try { cx_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[CX] ' . $e->getMessage()); } }
    if ($flag !== '') @file_put_contents($flag, date('c'));
}

// =============================================================================
// قالب‌های آماده ربات
// =============================================================================
function hd_templates()
{
    return [
        'medical' => [
            'label' => '🩺 مشاور سلامت و پزشکی',
            'specialty' => 'مشاور سلامت و پزشکی',
            'instructions' => "به سوالات سلامت و پزشکی با زبان ساده، دقیق و دلسوزانه پاسخ بده.\nهرگز تشخیص قطعی نده و دوز دارو تجویز نکن مگر اینکه دقیقاً در منابع آمده باشد.\nدر صورت وجود علائم خطرناک (درد قفسه سینه، تنگی نفس شدید، خونریزی، کاهش هوشیاری و...) فوراً توصیه کن با اورژانس ۱۱۵ تماس بگیرد.\nدر پایان پاسخ‌های درمانی، مراجعه به پزشک را یادآوری کن.",
            'disclaimer' => 'اطلاعات این گفتگو جنبه آموزشی دارد و جایگزین معاینه و تشخیص پزشک نیست. در شرایط اورژانسی با ۱۱۵ تماس بگیرید.',
            'welcome' => 'سلام 👋 من مشاور سلامت شما هستم. سوالتان را بپرسید تا بر اساس منابع معتبر راهنمایی‌تان کنم.',
        ],
        'business' => [
            'label' => '📈 مشاور کسب‌وکار',
            'specialty' => 'مشاور کسب‌وکار و مدیریت',
            'instructions' => "به سوالات کسب‌وکار، بازاریابی، فروش و مدیریت به‌صورت کاربردی و گام‌به‌گام پاسخ بده.\nدر صورت امکان مثال عملی بزن و نکات اجرایی را آیتم‌وار بنویس.\nاعداد و آمار را فقط از منابع بیاور.",
            'disclaimer' => 'پیشنهادها جنبه مشاوره‌ای دارند؛ تصمیم‌های مالی مهم را با بررسی بیشتر بگیرید.',
            'welcome' => 'سلام 👋 من مشاور کسب‌وکار شما هستم. درباره فروش، بازاریابی یا مدیریت بپرسید.',
        ],
        'legal' => [
            'label' => '⚖️ مشاور حقوقی',
            'specialty' => 'مشاور حقوقی',
            'instructions' => "به سوالات حقوقی دقیق و مستند پاسخ بده و در صورت وجود، شماره ماده قانون را از منابع ذکر کن.\nهرگز قانون یا شماره ماده را حدس نزن.\nبرای پرونده‌های مهم، مراجعه به وکیل را توصیه کن.",
            'disclaimer' => 'این پاسخ‌ها جنبه اطلاع‌رسانی دارند و جایگزین مشاوره رسمی وکیل نیستند.',
            'welcome' => 'سلام 👋 من مشاور حقوقی شما هستم. سوال حقوقی‌تان را بپرسید.',
        ],
        'education' => [
            'label' => '🎓 آموزشی',
            'specialty' => 'مدرس و راهنمای آموزشی',
            'instructions' => "مفاهیم را ساده، مرحله‌به‌مرحله و با مثال توضیح بده.\nدر پایان در صورت مناسب بودن، یک سوال کوتاه برای سنجش یادگیری بپرس.",
            'disclaimer' => '',
            'welcome' => 'سلام 👋 آماده‌ام تا در یادگیری کمکتان کنم. چه موضوعی را می‌خواهید یاد بگیرید؟',
        ],
        'sales' => [
            'label' => '🛒 پشتیبانی و فروش',
            'specialty' => 'کارشناس پشتیبانی و فروش',
            'instructions' => "به سوالات مشتریان درباره محصولات، خدمات، قیمت‌ها، ارسال و گارانتی مؤدبانه پاسخ بده.\nقیمت و موجودی را فقط از منابع بگو.",
            'disclaimer' => '',
            'welcome' => 'سلام 👋 چطور می‌توانم کمکتان کنم؟',
        ],
        'custom' => [
            'label' => '✨ سفارشی',
            'specialty' => 'دستیار تخصصی',
            'instructions' => '',
            'disclaimer' => '',
            'welcome' => 'سلام 👋 چطور می‌توانم کمکتان کنم؟',
        ],
    ];
}

// =============================================================================
// ربات‌ها
// =============================================================================
function hd_new_key() { return bin2hex(random_bytes(24)); }

function hd_get_bot($pdo, $bot_id, $user_id = null)
{
    if ($user_id === null) {
        $st = $pdo->prepare("SELECT * FROM hd_bots WHERE id=?");
        $st->execute([(int)$bot_id]);
    } else {
        $st = $pdo->prepare("SELECT * FROM hd_bots WHERE id=? AND user_id=?");
        $st->execute([(int)$bot_id, (int)$user_id]);
    }
    $b = $st->fetch(\PDO::FETCH_ASSOC);
    return $b ?: null;
}

function hd_get_bot_by_key($pdo, $key)
{
    $key = strtolower(trim((string)$key));
    if (!preg_match('/^[a-f0-9]{48}$/', $key)) return null;
    $st = $pdo->prepare("SELECT * FROM hd_bots WHERE bot_key=?");
    $st->execute([$key]);
    $b = $st->fetch(\PDO::FETCH_ASSOC);
    return $b ?: null;
}

function hd_user_bots($pdo, $user_id)
{
    $st = $pdo->prepare("SELECT b.*,
        (SELECT COUNT(*) FROM hd_sources s WHERE s.bot_id=b.id) AS sources_count,
        (SELECT COUNT(*) FROM hd_members m WHERE m.bot_id=b.id AND m.is_owner=0) AS members_count
        FROM hd_bots b WHERE b.user_id=? ORDER BY b.id DESC");
    $st->execute([(int)$user_id]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function hd_create_bot($pdo, $user_id, $name, $template = 'custom')
{
    $tpls = hd_templates();
    $t = $tpls[$template] ?? $tpls['custom'];
    $now = hd_now();
    $pdo->prepare("INSERT INTO hd_bots (user_id, name, bot_key, template, specialty, welcome_message, instructions, disclaimer, created_at, updated_at)
        VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$user_id, mb_substr($name, 0, 150), hd_new_key(), isset($tpls[$template]) ? $template : 'custom',
                   $t['specialty'], $t['welcome'], $t['instructions'], $t['disclaimer'], $now, $now]);
    return (int)$pdo->lastInsertId();
}

function hd_delete_bot($pdo, $bot_id, $user_id)
{
    $bot = hd_get_bot($pdo, $bot_id, $user_id);
    if (!$bot) return false;
    if (function_exists('sb_is_bot') && sb_is_bot($pdo, $bot)) return false;   // چت‌بات صفحه اصلی سایت حذف نمی‌شود
    $id = (int)$bot['id'];
    foreach (['hd_chunks', 'hd_pages', 'hd_sources', 'hd_qa', 'hd_messages', 'hd_threads', 'hd_members', 'hd_otps'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE bot_id=?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM hd_bots WHERE id=?")->execute([$id]);
    return true;
}

/** آیا صاحب ربات اجازه استفاده دارد؟ (پلن + اعتبار) */
function hd_owner_ok($pdo, $bot, &$reason = '')
{
    // چت‌بات صفحه اصلی سایت: صاحبش حساب سیستمی است (همیشه مجاز؛ هزینه از سامانه)
    if (function_exists('sb_is_bot') && sb_is_bot($pdo, $bot)) { sb_topup($pdo); return true; }
    $u = function_exists('saas_get_user') ? saas_get_user($pdo, (int)$bot['user_id']) : null;
    if (!$u || ($u['status'] ?? 'active') !== 'active') { $reason = 'owner_inactive'; return false; }
    if (function_exists('biz_service_blocked') && biz_service_blocked($pdo, $u)) { $reason = 'expired'; return false; }
    $plan = !empty($u['plan_id']) ? (saas_get_plan($pdo, (int)$u['plan_id']) ?: null) : null;
    if (function_exists('saas_plan_allows') && !saas_plan_allows($plan, 'has_expert_bot')) { $reason = 'plan'; return false; }
    if ((int)$u['credit_tokens'] <= 0) { $reason = 'credit'; return false; }
    return true;
}

// =============================================================================
// تکه‌تکه کردن متن (برای جستجوی دقیق)
// =============================================================================
function hd_chunk_text($text, $size = 750)
{
    $text = trim(preg_replace("/\r/u", '', (string)$text));
    if ($text === '') return [];
    $paras = preg_split("/\n+/u", $text);
    $chunks = [];
    $cur = '';
    foreach ($paras as $p) {
        $p = trim(preg_replace('/[ \t]+/u', ' ', $p));
        if ($p === '') continue;
        // پاراگراف خیلی بلند → جمله‌به‌جمله
        $pieces = mb_strlen($p) > $size ? preg_split('/(?<=[.!؟?؛])\s+/u', $p) : [$p];
        foreach ($pieces as $piece) {
            while (mb_strlen($piece) > $size) {
                if ($cur !== '') { $chunks[] = $cur; $cur = ''; }
                $chunks[] = mb_substr($piece, 0, $size);
                $piece = mb_substr($piece, $size);
            }
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($piece) + 1 > $size) {
                $chunks[] = $cur;
                // هم‌پوشانی کوتاه برای حفظ پیوستگی
                $tail = mb_substr($cur, -120);
                $cut = mb_strpos($tail, ' ');
                $cur = ($cut !== false ? mb_substr($tail, $cut + 1) : '') . "\n" . $piece;
            } else {
                $cur = $cur === '' ? $piece : $cur . "\n" . $piece;
            }
        }
    }
    if (trim($cur) !== '') $chunks[] = $cur;
    return array_values(array_filter(array_map('trim', $chunks), fn($c) => mb_strlen($c) >= 15));
}

function hd_store_chunks($pdo, $bot_id, $source_id, $page_id, $title, $url, $text)
{
    $pdo->prepare("DELETE FROM hd_chunks WHERE source_id=? AND page_id=?")->execute([(int)$source_id, (int)$page_id]);
    $ins = $pdo->prepare("INSERT INTO hd_chunks (bot_id, source_id, page_id, title, url, chunk, norm, created_at) VALUES(?,?,?,?,?,?,?,?)");
    $now = hd_now();
    $n = 0;
    foreach (hd_chunk_text($text) as $c) {
        $ins->execute([(int)$bot_id, (int)$source_id, (int)$page_id, mb_substr($title, 0, 490), mb_substr($url, 0, 500), $c, saas_fa_normalize($title . ' ' . $c), $now]);
        $n++;
        if ($n >= 400) break;   // سقف هر صفحه/متن
    }
    return $n;
}

// =============================================================================
// منابع: متن، صفحه، سایت
// =============================================================================
function hd_add_source($pdo, $bot_id, $kind, $title, $url = '', $content = '')
{
    $kind = in_array($kind, ['text', 'page', 'site', 'file'], true) ? $kind : 'text';
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $pdo->prepare("INSERT INTO hd_sources (bot_id, kind, title, url, content, status, created_at) VALUES(?,?,?,?,?,'pending',?)")
        ->execute([(int)$bot_id, $kind, mb_substr($title, 0, 300), mb_substr($url, 0, 500), $content, hd_now()]);
    return (int)$pdo->lastInsertId();
}

function hd_delete_source($pdo, $source_id, $bot_id)
{
    foreach (['hd_chunks', 'hd_pages'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE source_id=? AND bot_id=?")->execute([(int)$source_id, (int)$bot_id]);
    }
    $pdo->prepare("DELETE FROM hd_sources WHERE id=? AND bot_id=?")->execute([(int)$source_id, (int)$bot_id]);
}

/** آدرس امن برای curl (حروف فارسی و فاصله کدگذاری می‌شوند) */
function hd_url_encode($u)
{
    return preg_replace_callback('/[^\x21-\x7E]/', fn($m) => rawurlencode($m[0]), (string)$u);
}

/** دریافت صفحه با مشخصات مرورگر معمولی (برخی سایت‌ها ربات‌ها را مسدود می‌کنند) */
/**
 * دریافت یک صفحه برای منابع دانش؛ اگر سایت درخواست را رد کرد (۴۰۳/۴۲۹/...) یک بار دیگر با
 * سرآیندهای ساده‌تر (مثل مرورگر موبایل) امتحان می‌شود.
 */
function hd_fetch_page($url, $timeout = 12)
{
    $r = hd_http_get($url, $timeout);
    if (in_array($r['code'], [0, 403, 406, 429, 503], true) || ($r['code'] === 200 && trim($r['body']) === '')) {
        $p = parse_url($url);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/';
        $r2 = hd_http_get($url, $timeout, [
            'ua' => 'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
            'headers' => ['Accept: text/html,*/*;q=0.8', 'Referer: ' . $origin],
        ]);
        if ($r2['code'] === 200 && trim($r2['body']) !== '') return $r2;
        if ($r['code'] === 0 && $r2['code'] !== 0) return $r2;
    }
    return $r;
}

/** پیام خطای قابل فهم برای خواندن یک صفحه */
function hd_fetch_reason($code, $err = '')
{
    if ($code === 'short') return 'متن قابل خواندنی در صفحه پیدا نشد (ممکن است محتوای صفحه با جاوااسکریپت ساخته شود). اگر این صفحه فهرست مطالب است نوع «کل سایت / بخش» را انتخاب کنید؛ در غیر این صورت متن صفحه را کپی و به‌صورت «متن» اضافه کنید.';
    $code = (int)$code;
    if ($code === 0) return 'اتصال به سایت برقرار نشد؛ سایت در دسترس نیست یا سرور ما به آن دسترسی ندارد.' . ($err !== '' ? ' (' . mb_substr($err, 0, 60) . ')' : '');
    if (in_array($code, [401, 403, 406, 429, 503], true)) return "سایت مقصد اجازه خواندن خودکار صفحه را نمی‌دهد (خطای {$code}؛ معمولاً محافظ ضدربات مانند آروان‌کلاد یا کلادفلر). متن صفحه را کپی و به‌صورت «متن» اضافه کنید، یا از مدیر آن سایت بخواهید دسترسی را باز کند.";
    if ($code === 404 || $code === 410) return 'این صفحه در سایت پیدا نشد (خطای ' . $code . '). آدرس را بررسی کنید.';
    return 'سایت مقصد پاسخ درستی نداد (خطای ' . $code . ').';
}

function hd_http_get($url, $timeout = 10, $opt = [])
{
    $url = hd_url_encode($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $opt['ua'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: fa-IR,fa;q=0.9,en;q=0.6'],
        CURLOPT_COOKIEFILE     => '',   // نگه‌داشتن کوکی‌ها بین تغییر مسیرها
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body === false ? '' : (string)$body, 'url' => $final, 'error' => $err];
}

/** آخرین خطای کشف صفحات (برای نمایش پیام دقیق به کاربر) */
function hd_discover_error($set = null)
{
    static $e = '';
    if ($set !== null) $e = $set;
    return $e;
}

/** آیا این آدرس صفحه محتوایی است؟ (ورود، سبد خرید، فایل‌ها و ... حذف می‌شوند) */
function hd_is_content_url($u)
{
    $path = strtolower(rawurldecode((string)parse_url($u, PHP_URL_PATH)));
    if (preg_match('#\.(jpe?g|png|gif|webp|svg|ico|css|js|pdf|zip|rar|mp3|mp4|avi|mkv|xml|json|txt|docx?|xlsx?)$#i', $path)) return false;
    if (preg_match('#/(login|logout|register|signup|sign-in|sign-up|cart|checkout|basket|account|my-account|profile|wp-admin|wp-login\.php|wp-json|feed|admin|dashboard|password|lost-password|search)(/|$)#i', $path)) return false;
    if (preg_match('#/(tag|author|attachment)/#i', $path)) return false;
    return true;
}

/**
 * کشف صفحات یک سایت برای منبع «کل سایت / بخش»:
 * ۱) وردپرس (REST)  ۲) همه آدرس‌های نقشه سایت (هم‌دامنه، با یا بدون www / http)
 * ۳) خزش لینک‌های داخلی صفحه اصلی و صفحات فهرست (دو سطح)
 * اگر آدرس یک بخش داده شده باشد (مثلاً site.com/blog) فقط همان بخش.
 */
function hd_discover_site_urls($url, $max = 300)
{
    hd_discover_error('');
    $root = saas_site_root($url);
    if ($root === '') { hd_discover_error('آدرس سایت معتبر نیست.'); return []; }
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($root, PHP_URL_HOST)));
    $same = fn($u) => preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST))) === $host;
    $key = fn($u) => preg_replace('#^https?://(www\.)?#i', '', rtrim(rawurldecode(strtok($u, '#')), '/'));   // برای حذف تکراری‌ها
    $urls = []; $keys = [];
    $add = function ($u, $lm = '') use (&$urls, &$keys, $same, $key) {
        $u = trim(html_entity_decode($u, ENT_QUOTES, 'UTF-8'));
        if (!preg_match('#^https?://#i', $u) || !$same($u) || !hd_is_content_url($u)) return;
        $k = $key($u);
        if (isset($keys[$k])) return;
        $keys[$k] = true;
        $urls[$u] = (string)$lm;
    };

    // دسترسی به سایت
    $home = hd_http_get($root . '/', 12);
    if ($home['code'] === 0 || $home['code'] >= 400) {
        $alt = hd_http_get(preg_replace('#^https://#i', 'http://', $root) . '/', 12);
        if ($alt['code'] > 0 && $alt['code'] < 400) $home = $alt;
    }
    if ($home['code'] === 0 || $home['code'] >= 400) {
        hd_discover_error('سایت از سرور ما در دسترس نیست' . ($home['code'] ? ' (کد پاسخ: ' . $home['code'] . ')' : ($home['error'] !== '' ? ' (' . mb_substr($home['error'], 0, 80) . ')' : '')) . '. اگر سایت فایروال یا محدودیت کشور دارد، دسترسی سرور را باز کنید؛ یا صفحات را جداگانه با گزینه «فقط همین صفحه» اضافه کنید.');
        return [];
    }
    if (!empty($home['url'])) {   // اگر سایت به دامنه/پروتکل دیگری منتقل می‌کند
        $r2 = saas_site_root($home['url']);
        if ($r2 !== '' && $same($r2)) $root = $r2;
    }

    // ۱) وردپرس
    foreach (['posts', 'pages'] as $t) {
        for ($pg = 1; $pg <= 3 && count($urls) < $max; $pg++) {
            $wp = hd_http_get($root . '/wp-json/wp/v2/' . $t . '?per_page=100&page=' . $pg . '&_fields=link,modified', 10);
            $d = $wp['code'] === 200 ? json_decode($wp['body'], true) : null;
            if (!is_array($d) || !$d || !array_is_list($d)) break;
            foreach ($d as $it) if (!empty($it['link'])) $add($it['link'], $it['modified'] ?? '');
            if (count($d) < 100) break;
        }
    }

    // ۲) نقشه سایت
    $sitemaps = [];
    $rb = hd_http_get($root . '/robots.txt', 8);
    if ($rb['code'] === 200 && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $rb['body'], $m)) foreach ($m[1] as $u) $sitemaps[] = trim($u);
    foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $sp) $sitemaps[] = $root . $sp;
    $seen = []; $fetched = 0;
    while ($sitemaps && $fetched < 20 && count($urls) < $max * 2) {
        $sm = array_shift($sitemaps);
        if (isset($seen[$sm])) continue;
        $seen[$sm] = true;
        $r = hd_http_get($sm, 12); $fetched++;
        if ($r['code'] !== 200 || stripos($r['body'], '<loc>') === false) continue;
        if (stripos($r['body'], '<sitemapindex') !== false) {
            preg_match_all('#<sitemap>.*?<loc>\s*([^<\s]+)\s*</loc>#is', $r['body'], $mm);
            foreach ($mm[1] as $c) { $c = html_entity_decode($c); if (stripos($c, 'image') === false) $sitemaps[] = $c; }
            continue;
        }
        if (preg_match_all('#<url>(.*?)</url>#is', $r['body'], $um)) {
            foreach ($um[1] as $b) {
                if (!preg_match('#<loc>\s*([^<\s]+)\s*</loc>#i', $b, $lm)) continue;
                $add($lm[1], preg_match('#<lastmod>\s*([^<\s]+)#i', $b, $x) ? $x[1] : '');
            }
        }
    }

    // ۳) خزش لینک‌های داخلی (وقتی نقشه سایت نبود یا کم بود)
    if (count($urls) < 10) {
        $queue = [[$root . '/', 0]]; $visited = []; $pages = 0;
        $start = microtime(true);
        while ($queue && $pages < 25 && microtime(true) - $start < 25 && count($urls) < $max) {
            [$pu, $depth] = array_shift($queue);
            if (isset($visited[$pu])) continue;
            $visited[$pu] = true;
            $h = ($pages === 0) ? $home : hd_http_get($pu, 8);
            $pages++;
            if ($h['code'] !== 200 || !preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\'\#]+)["\']#i', $h['body'], $lm)) continue;
            foreach ($lm[1] as $href) {
                $href = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');
                if (strpos($href, '//') === 0) $href = 'https:' . $href;
                elseif ($href !== '' && $href[0] === '/') $href = $root . $href;
                elseif (!preg_match('#^https?://#i', $href)) continue;
                $href = strtok($href, '?');
                if (!$same($href) || !hd_is_content_url($href)) continue;
                $before = count($urls);
                $add($href);
                if (count($urls) > $before && $depth < 1) $queue[] = [$href, $depth + 1];
            }
        }
    }

    // اگر کاربر آدرس یک بخش خاص داده (مثلاً site.com/articles) فقط همان بخش
    $path = rtrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
    if ($path !== '' && $path !== '/') {
        $inside = array_filter($urls, fn($u) => strpos(rtrim(rawurldecode((string)parse_url($u, PHP_URL_PATH)), '/') . '/', $path . '/') === 0, ARRAY_FILTER_USE_KEY);
        if ($inside) $urls = $inside;
    }
    if (!$urls) hd_discover_error('در این سایت نقشه سایت (sitemap) یا لینک داخلی قابل خواندن پیدا نشد. صفحات مهم را جداگانه با گزینه «فقط همین صفحه» اضافه کنید.');
    return array_slice($urls, 0, $max, true);
}

/**
 * همگام‌سازی یک منبع (دسته‌ای با سقف زمان)
 * @return array ['total','done','remaining','error']
 */
function hd_sync_source($pdo, $source_id, $max_pages = 25, $time_budget = 30)
{
    $st = $pdo->prepare("SELECT * FROM hd_sources WHERE id=?");
    $st->execute([(int)$source_id]);
    $src = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$src) return ['total' => 0, 'done' => 0, 'remaining' => 0, 'error' => 'منبع یافت نشد.'];
    $sid = (int)$src['id'];
    $bid = (int)$src['bot_id'];
    $start = microtime(true);
    $now = hd_now();

    // --- متن ---
    if ($src['kind'] === 'text' || $src['kind'] === 'file') {
        $n = hd_store_chunks($pdo, $bid, $sid, 0, $src['title'], '', (string)$src['content']);
        $pdo->prepare("UPDATE hd_sources SET status=?, pages_total=1, pages_ok=?, last_sync=?, last_error='' WHERE id=?")
            ->execute([$n > 0 ? 'ready' : 'error', $n > 0 ? 1 : 0, $now, $sid]);
        return ['total' => 1, 'done' => 1, 'remaining' => 0, 'error' => $n > 0 ? '' : 'متن خیلی کوتاه است.'];
    }

    // --- فهرست صفحات ---
    $urls = $src['kind'] === 'page' ? [$src['url'] => ''] : hd_discover_site_urls($src['url']);
    if (!$urls) {
        $why = hd_discover_error() ?: 'هیچ صفحه‌ای در این آدرس پیدا نشد.';
        $pdo->prepare("UPDATE hd_sources SET status='error', last_sync=?, last_error=? WHERE id=?")
            ->execute([$now, mb_substr($why, 0, 250), $sid]);
        return ['total' => 0, 'done' => 0, 'remaining' => 0, 'error' => $why];
    }
    $pst = $pdo->prepare("SELECT id, url, lastmod, status, updated_at FROM hd_pages WHERE source_id=?");
    $pst->execute([$sid]);
    $have = [];
    foreach ($pst->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $have[$r['url']] = $r;

    // حذف صفحاتی که دیگر وجود ندارند
    foreach (array_diff(array_keys($have), array_keys($urls)) as $gone) {
        $pid = (int)$have[$gone]['id'];
        $pdo->prepare("DELETE FROM hd_chunks WHERE page_id=?")->execute([$pid]);
        $pdo->prepare("DELETE FROM hd_pages WHERE id=?")->execute([$pid]);
        unset($have[$gone]);
    }
    // افزودن صفحات جدید
    $insp = $pdo->prepare("INSERT INTO hd_pages (source_id, bot_id, url, lastmod, status, updated_at) VALUES(?,?,?,?,0,?)");
    foreach ($urls as $u => $lm) {
        if (!isset($have[$u])) {
            $insp->execute([$sid, $bid, mb_substr($u, 0, 500), (string)$lm, '2000-01-01 00:00:00']);
            $have[$u] = ['id' => (int)$pdo->lastInsertId(), 'url' => $u, 'lastmod' => (string)$lm, 'status' => 0, 'updated_at' => '2000-01-01 00:00:00'];
        }
    }
    // صف: جدید/خطادار/تغییرکرده/قدیمی‌تر از ۷ روز
    $week_ago = time() - 7 * 86400;
    $todo = [];
    foreach ($have as $u => $r) {
        $lm = $urls[$u] ?? '';
        if ((int)$r['status'] !== 1 || ($lm !== '' && $lm !== $r['lastmod']) || strtotime($r['updated_at']) < $week_ago) {
            $todo[$u] = strtotime($r['updated_at']);
        }
    }
    asort($todo);
    $upd = $pdo->prepare("UPDATE hd_pages SET title=?, lastmod=?, status=?, updated_at=? WHERE id=?");
    $done = 0;
    $reasons = [];
    foreach (array_keys($todo) as $u) {
        if ($done >= $max_pages || microtime(true) - $start > $time_budget) break;
        $pid = (int)$have[$u]['id'];
        $r = hd_fetch_page($u, 12);
        $ok = ($r['code'] === 200 && $r['body'] !== '');
        $title = '';
        if ($ok) {
            $d = saas_parse_content_page($r['body'], $r['url'] !== '' ? $r['url'] : $u);
            $title = $d['title'];
            $n = mb_strlen($d['content']) >= 40 ? hd_store_chunks($pdo, $bid, $sid, $pid, $d['title'], $u, $d['content']) : 0;
            $ok = $n > 0;
            if (!$ok) $reasons['short'] = ($reasons['short'] ?? 0) + 1;
        } else {
            $k = (string)$r['code'];
            $reasons[$k] = ($reasons[$k] ?? 0) + 1;
            if ($k === '0' && !isset($reasons['_err'])) $reasons['_err'] = $r['error'];
        }
        $upd->execute([mb_substr($title, 0, 490), (string)($urls[$u] ?? ''), $ok ? 1 : 2, hd_now(), $pid]);
        $done++;
    }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM hd_pages WHERE source_id=? AND status=1");
    $cnt->execute([$sid]);
    $okc = (int)$cnt->fetchColumn();
    $remaining = max(0, count($todo) - $done);
    $status = $remaining > 0 ? 'syncing' : ($okc > 0 ? 'ready' : 'error');
    $pdo->prepare("UPDATE hd_sources SET status=?, pages_total=?, pages_ok=?, last_sync=?, last_error=? WHERE id=?")
        ->execute([$status, count($have), $okc, hd_now(), $okc > 0 ? '' : mb_substr(hd_sync_reason($reasons), 0, 250), $sid]);
    return ['total' => count($have), 'done' => $done, 'remaining' => $remaining, 'error' => ($okc === 0 && $remaining === 0) ? hd_sync_reason($reasons) : ''];
}

/** دلیل اصلی خوانده‌نشدن صفحات یک منبع */
function hd_sync_reason($reasons)
{
    $err = (string)($reasons['_err'] ?? '');
    unset($reasons['_err']);
    if (!$reasons) return 'محتوایی از صفحات خوانده نشد.';
    arsort($reasons);
    $top = (string)array_key_first($reasons);
    return hd_fetch_reason($top === 'short' ? 'short' : (int)$top, $err);
}

/** به‌روزرسانی خودکار منابع قدیمی بعد از ارسال پاسخ (حداکثر یک منبع در هر بار) */
function hd_schedule_refresh($pdo, $bot_id)
{
    static $scheduled = false;
    if ($scheduled) return;
    $st = $pdo->prepare("SELECT id FROM hd_sources WHERE bot_id=? AND kind IN ('page','site') AND is_active=1 AND (status='syncing' OR last_sync IS NULL OR last_sync < ?) ORDER BY last_sync ASC LIMIT 1");
    $st->execute([(int)$bot_id, hd_now(-7 * 86400)]);
    $sid = (int)$st->fetchColumn();
    if (!$sid) return;
    $lock = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.hd_sync_' . $sid;
    if (is_file($lock) && filemtime($lock) > time() - 1800) return;
    @touch($lock);
    $scheduled = true;
    register_shutdown_function(function () use ($pdo, $sid) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @ignore_user_abort(true);
        @set_time_limit(120);
        try { hd_sync_source($pdo, $sid, 15, 60); } catch (\Throwable $e) { error_log('[HD] refresh: ' . $e->getMessage()); }
    });
}

// =============================================================================
// بازیابی منابع مرتبط
// =============================================================================
function hd_retrieve($pdo, $bot_id, $query, $max_chunks = 6)
{
    $ids = array_values(array_unique(array_map('intval', (array)$bot_id)));
    if (!$ids) return [];
    $terms = saas_search_terms($query);
    if (!$terms) return [];
    // کلمات بلندتر معنادارترند
    $sorted = $terms;
    usort($sorted, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $like_terms = array_slice($sorted, 0, 8);
    $conds = [];
    $params = $ids;
    foreach ($like_terms as $t) {
        $conds[] = "norm LIKE ?";
        $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $t) . '%';
    }
    $st = $pdo->prepare("SELECT id, source_id, page_id, title, url, chunk, norm FROM hd_chunks WHERE bot_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND (" . implode(' OR ', $conds) . ")"
        . " AND source_id NOT IN (SELECT id FROM hd_sources WHERE is_active=0) LIMIT 800");
    $st->execute($params);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    foreach ($rows as &$r) {
        $nt = saas_fa_normalize($r['title']);
        $hits = 0; $score = 0;
        foreach ($terms as $t) {
            $c = mb_substr_count($r['norm'], $t);
            if ($c > 0) { $hits++; $score += min($c, 4) * (mb_strlen($t) >= 4 ? 1.5 : 1); }
            if ($nt !== '' && mb_strpos($nt, $t) !== false) $score += 3;
        }
        // پوشش بیشتر کلمات سوال → ارتباط بیشتر
        $r['score'] = $score * (1 + $hits / max(1, count($terms)));
        $r['hits'] = $hits;
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    $best = $rows[0]['score'];
    $out = [];
    $per_page = [];
    $seen_txt = [];
    foreach ($rows as $r) {
        if ($r['score'] < $best * 0.25 || $r['hits'] < 1) break;
        $h = md5($r['norm']);
        if (isset($seen_txt[$h])) continue;          // تکه تکراری (همان صفحه از دو منبع)
        $seen_txt[$h] = true;
        $key = $r['url'] !== '' ? $r['url'] : ($r['source_id'] . ':' . $r['page_id']);
        if (($per_page[$key] ?? 0) >= 3) continue;
        $per_page[$key] = ($per_page[$key] ?? 0) + 1;
        $out[] = $r;
        if (count($out) >= $max_chunks) break;
    }
    return $out;
}

function hd_retrieve_qa($pdo, $bot_id, $query, $limit = 3)
{
    $st = $pdo->prepare("SELECT question, answer FROM hd_qa WHERE bot_id=? AND is_active=1 ORDER BY id DESC LIMIT 500");
    $st->execute([(int)$bot_id]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];
    $terms = saas_search_terms($query);
    if (!$terms) return [];
    $out = [];
    foreach ($rows as $r) {
        $qt = saas_search_terms($r['question']);
        if (!$qt) continue;
        $common = count(array_intersect($terms, $qt));
        $ratio = $common / max(1, count(array_unique($qt)));
        if ($common >= 1 && $ratio >= 0.5) { $r['_s'] = $ratio + $common * 0.1; $out[] = $r; }
    }
    usort($out, fn($a, $b) => $b['_s'] <=> $a['_s']);
    return array_slice($out, 0, $limit);
}

// =============================================================================
// اعضا (مخاطبان ربات)
// =============================================================================
function hd_normalize_mobile($m)
{
    $m = preg_replace('/\D+/', '', saas_fa_normalize((string)$m));
    if (strpos($m, '0098') === 0) $m = '0' . substr($m, 4);
    elseif (strpos($m, '98') === 0 && strlen($m) === 12) $m = '0' . substr($m, 2);
    elseif (strlen($m) === 10 && $m[0] === '9') $m = '0' . $m;
    return preg_match('/^09\d{9}$/', $m) ? $m : '';
}

function hd_issue_token($pdo, $member_id)
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE hd_members SET token_hash=?, last_seen=? WHERE id=?")->execute([hash('sha256', $token), hd_now(), (int)$member_id]);
    return $token;
}

function hd_member_by_token($pdo, $bot_id, $token)
{
    $token = (string)$token;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND token_hash=? LIMIT 1");
    $st->execute([(int)$bot_id, hash('sha256', $token)]);
    $m = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$m) {
        // پنل مرکزی مخاطبان (نسخه ۴۷): توکن جداگانه سمت سرور، تا ورود چت در دستگاه‌های دیگر باطل نشود
        try {
            $st = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND panel_hash=? LIMIT 1");
            $st->execute([(int)$bot_id, hash('sha256', $token)]);
            $m = $st->fetch(\PDO::FETCH_ASSOC);
            if ($m) $m['_panel'] = 1;
        } catch (\Throwable $e) { $m = null; }
    }
    if (!$m) {
        // ورود صاحب ربات به‌جای مخاطب (توکن موقت)
        try {
            $st = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND imp_hash=? AND imp_until > ? LIMIT 1");
            $st->execute([(int)$bot_id, hash('sha256', $token), hd_now()]);
            $m = $st->fetch(\PDO::FETCH_ASSOC);
            if ($m) $m['_imp'] = 1;
        } catch (\Throwable $e) { $m = null; }
    }
    return $m ?: null;
}

/** لینک یک‌بارمصرف ورود صاحب ربات به‌جای مخاطب (۵ دقیقه اعتبار) */
function hd_member_magic_code($pdo, $bot_id, $member_id, $actor = '')
{
    $code = bin2hex(random_bytes(20));
    $pdo->prepare("UPDATE hd_members SET magic_hash=?, magic_until=? WHERE id=? AND bot_id=?")->execute([hash('sha256', $code), hd_now(300), (int)$member_id, (int)$bot_id]);
    if ($actor !== '') { try { $pdo->prepare("UPDATE hd_members SET imp_actor=? WHERE id=?")->execute([mb_substr((string)$actor, 0, 140), (int)$member_id]); } catch (\Throwable $e) {} }
    return $code;
}

function hd_create_member($pdo, $bot_id, $mobile, $name, $verified, $is_owner = 0)
{
    $pdo->prepare("INSERT INTO hd_members (bot_id, mobile, name, token_hash, verified, is_owner, created_at, last_seen) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([(int)$bot_id, $mobile, mb_substr($name, 0, 100), str_repeat('0', 64), $verified ? 1 : 0, $is_owner ? 1 : 0, hd_now(), hd_now()]);
    return (int)$pdo->lastInsertId();
}

/** بسته‌های خریداری‌شده فعال مخاطب */
function hd_active_purchases($pdo, $bot_id, $member_id)
{
    // بسته‌های مشترک (خریداری‌شده در ربات دیگرِ همان صاحب، با همین شماره موبایل) هم حساب می‌شوند
    try {
        $mi = $pdo->prepare("SELECT m.mobile, b.user_id FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE m.id=? AND m.bot_id=?");
        $mi->execute([(int)$member_id, (int)$bot_id]);
        $row = $mi->fetch(\PDO::FETCH_ASSOC) ?: ['mobile' => '', 'user_id' => 0];
        $st = $pdo->prepare("SELECT * FROM hd_purchases WHERE status='paid' AND (expires_at IS NULL OR expires_at > ?) AND ((bot_id=? AND member_id=?) OR (mobile<>'' AND mobile=? AND owner_id=? AND shared_bots LIKE ?)) ORDER BY (expires_at IS NULL), expires_at ASC, id ASC");
        $st->execute([hd_now(), (int)$bot_id, (int)$member_id, (string)$row['mobile'], (int)$row['user_id'], '%,' . (int)$bot_id . ',%']);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $st = $pdo->prepare("SELECT * FROM hd_purchases WHERE bot_id=? AND member_id=? AND status='paid' AND (expires_at IS NULL OR expires_at > ?) ORDER BY (expires_at IS NULL), expires_at ASC, id ASC");
        $st->execute([(int)$bot_id, (int)$member_id, hd_now()]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}

/**
 * وضعیت سهمیه پیام مخاطب
 * - پیام رایگان (free_messages؛ ۰ = نامحدود) + پیام اضافه‌شده توسط مدیر
 * - بسته‌های خریداری‌شده فعال (تعداد پیام و تاریخ انقضا)
 * - سقف روزانه ربات (یا سقف بالاتر بسته فعال)
 */
function hd_quota($pdo, $bot, $member)
{
    if (!empty($member['is_owner'])) return ['allowed' => true, 'remaining' => -1, 'today_left' => -1, 'reason' => '', 'free_left' => -1, 'paid_left' => 0, 'gift_left' => 0, 'paid_until' => null, 'mode' => 'owner'];
    // روش ۱: چت رایگان محدود با مدل‌های ارزان (قابل خاموش کردن توسط صاحب ربات)
    $free_on = !isset($bot['acq_free_on']) || !empty($bot['acq_free_on']);
    $free  = (int)$bot['free_messages'];
    if ($free_on) $free_left = $free > 0 ? max(0, $free + (int)$member['extra_messages'] - (int)$member['used_messages']) : -1;
    else $free_left = max(0, (int)$member['extra_messages'] - (int)$member['used_messages']);
    // روش ۲: شارژ هدیه با مدل‌های تخصصی
    $gift_left = (!empty($bot['gift_on']) && (int)($bot['gift_messages'] ?? 0) > 0) ? max(0, (int)$bot['gift_messages'] - (int)($member['gift_used'] ?? 0)) : 0;
    $paid_left = 0; $paid_daily = 0; $paid_until = null;
    if (function_exists('mcr_schema')) mcr_schema($pdo);
    $purch = hd_active_purchases($pdo, $bot['id'], $member['id']);
    foreach ($purch as $p) {
        $paid_left += max(0, (int)$p['messages'] - (int)$p['used']);
        if ((int)$p['used'] < (int)$p['messages']) $paid_daily = max($paid_daily, (int)$p['daily_limit']);
        if ($p['expires_at'] && ($paid_until === null || $p['expires_at'] > $paid_until)) $paid_until = $p['expires_at'];
    }
    // شارژ تومانی (نسخه ۶۲): تا وقتی شارژ دارد، سقف تعدادی ندارد
    $credit_left = function_exists('mcr_left') ? mcr_left($purch) : 0;
    $remaining = ($free_left < 0 || $credit_left > 0) ? -1 : $free_left + $paid_left + $gift_left;
    $mode = $paid_left > 0 ? 'paid' : ($credit_left > 0 ? 'credit' : ($gift_left > 0 ? 'gift' : ($free_left !== 0 ? 'free' : 'none')));
    // سقف روزانه: در حالت اشتراکی، سقف خود بسته (اگر تعیین شده)، وگرنه سقف عمومی ربات
    $daily = (int)$bot['daily_limit'];
    if ($mode === 'paid' && $paid_daily > 0) $daily = $paid_daily;
    $borrow_day = $mode === 'paid' && (string)($member['borrow_day'] ?? '') === date('Y-m-d');   // «ریست رایگان» امروز
    $st = $pdo->prepare("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND member_id=? AND role='user' AND created_at >= ?");
    $st->execute([(int)$bot['id'], (int)$member['id'], date('Y-m-d 00:00:00')]);
    $today = (int)$st->fetchColumn();
    $today_left = ($daily > 0 && !$borrow_day) ? max(0, $daily - $today) : -1;
    // سقف دوره‌ای (مثلاً هر ۵ ساعت) — مثل Claude
    $win = function_exists('cx_window') ? cx_window($pdo, $bot, $member, $mode, $purch) : null;
    // سقف هفتگی و ماهانه بسته‌های اشتراکی (includes/lim_lib.php)
    $lim = ($mode === 'paid' && function_exists('lim_paid_limits')) ? lim_paid_limits($pdo, $bot, $member, $purch) : ['week' => null, 'month' => null, 'reason' => ''];
    $reason = '';
    $reset_at = null;
    if ($remaining === 0) $reason = 'total';
    elseif ($win && $win['left'] === 0) { $reason = 'window'; $reset_at = $win['full_at']; }
    elseif ($lim['reason'] === 'monthly') { $reason = 'monthly'; $reset_at = $lim['month']['reset_at']; }
    elseif ($lim['reason'] === 'weekly') { $reason = 'weekly'; $reset_at = $lim['week']['reset_at']; }
    elseif ($today_left === 0) { $reason = 'daily'; $reset_at = strtotime(date('Y-m-d 00:00:00', time() + 86400)); }
    $paid_days_left = $paid_until ? (int)ceil((strtotime($paid_until) - time()) / 86400) : null;
    $total_paid = 0;
    foreach ($purch as $p) if ((int)$p['used'] < (int)$p['messages']) $total_paid += (int)$p['messages'];
    $lt = function ($x) { return $x ? ['limit' => (int)$x['limit'], 'used' => (int)$x['used'], 'left' => (int)$x['left'], 'next' => date('c', (int)$x['reset_at']), 'next_text' => function_exists('lim_when') ? lim_when((int)$x['reset_at']) : '', 'borrowed' => !empty($x['borrowed'])] : null; };
    $out = ['allowed' => $reason === '', 'remaining' => $remaining, 'today_left' => $today_left, 'reason' => $reason,
            'reset_at' => $reset_at ? date('c', $reset_at) : null, 'reset_text' => $reset_at && function_exists('cx_fa_time') ? (in_array($reason, ['weekly', 'monthly'], true) && function_exists('lim_when') ? lim_when($reset_at) : cx_fa_time($reset_at)) : '',
            'win' => $win ? ['limit' => $win['limit'], 'hours' => $win['hours'], 'left' => $win['left'], 'next' => $win['reset_at'] ? date('c', $win['reset_at']) : null, 'next_text' => $win['reset_at'] ? cx_fa_time($win['reset_at']) : ''] : null,
            'day' => $daily > 0 ? ['limit' => $daily, 'used' => $today, 'left' => max(0, $daily - $today), 'next' => date('c', strtotime(date('Y-m-d 00:00:00', time() + 86400))), 'next_text' => 'فردا', 'borrowed' => $borrow_day] : null,
            'week' => $lt($lim['week']), 'month' => $lt($lim['month']), 'paid_total' => $total_paid,
            'free_left' => $free_left, 'paid_left' => $paid_left, 'gift_left' => $gift_left, 'credit_left' => $credit_left, 'mode' => $mode, 'paid_until' => $paid_until, 'paid_days_left' => $paid_days_left];
    $out['can_borrow'] = function_exists('lim_can_borrow') && lim_can_borrow($out);
    if ($out['can_borrow']) $out['borrow_warning'] = lim_borrow_warning($reason);
    return $out;
}

/**
 * کسر پیام از سهمیه
 * $use = 'bot' (مدل‌های تخصصی): اول شارژ هدیه، بعد قدیمی‌ترین بسته فعال، بعد رایگان
 * $use = 'free' (مدل‌های ارزان): اول رایگان، بعد هدیه و بسته
 */
function hd_consume_message($pdo, $bot, $member, $n = 1, $use = 'free', $toman = 0)
{
    // $toman (نسخه ۶۲): هزینه‌ای که از صاحب چت‌بات کم شد؛ اگر پیام از شارژ تومانی مخاطب باشد، همین هزینه + درصد مدیر کسر می‌شود
    $now = hd_now();
    if (!empty($member['is_owner'])) { $pdo->prepare("UPDATE hd_members SET last_seen=? WHERE id=?")->execute([$now, (int)$member['id']]); return; }
    for ($i = 0; $i < max(1, (int)$n); $i++) {
        $fm = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
        $fm->execute([(int)$member['id']]);
        $member = $fm->fetch(\PDO::FETCH_ASSOC) ?: $member;
        $q = hd_quota($pdo, $bot, $member);
        $order = $use === 'bot' ? ['gift', 'paid', 'credit', 'free'] : ['free', 'gift', 'paid', 'credit'];
        $cr = false;
        foreach ($order as $src) {
            if ($src === 'credit' && (int)($q['credit_left'] ?? 0) > 0) { if (function_exists('mcr_charge')) mcr_charge($pdo, $bot, $member, $toman); $cr = true; break; }
            if ($src === 'free' && $q['free_left'] !== 0) { $pdo->prepare("UPDATE hd_members SET used_messages = used_messages + 1 WHERE id=?")->execute([(int)$member['id']]); break; }
            if ($src === 'gift' && ($q['gift_left'] ?? 0) > 0) { $pdo->prepare("UPDATE hd_members SET gift_used = gift_used + 1 WHERE id=?")->execute([(int)$member['id']]); break; }
            if ($src === 'paid' && $q['paid_left'] > 0) {
                $done = false;
                foreach (hd_active_purchases($pdo, $bot['id'], $member['id']) as $p) {
                    if ((int)$p['used'] < (int)$p['messages']) { $pdo->prepare("UPDATE hd_purchases SET used = used + 1 WHERE id=?")->execute([(int)$p['id']]); $done = true; break; }
                }
                if ($done) break;
            }
        }
        if ($cr) break;   // شارژ تومانی یک بار برای کل هزینه کسر می‌شود
    }
    $pdo->prepare("UPDATE hd_members SET last_seen=? WHERE id=?")->execute([$now, (int)$member['id']]);
}

// =============================================================================
// گفتگوها
// =============================================================================
function hd_threads($pdo, $bot_id, $member_id, $limit = 50)
{
    $st = $pdo->prepare("SELECT id, title, updated_at FROM hd_threads WHERE bot_id=? AND member_id=? ORDER BY updated_at DESC, id DESC LIMIT " . (int)$limit);
    $st->execute([(int)$bot_id, (int)$member_id]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function hd_thread_messages($pdo, $thread_id, $limit = 200, $with_cost = false)
{
    $st = $pdo->prepare("SELECT id, role, content, sources, rating, attachments, created_at" . ($with_cost ? ", tokens, tok_detail, model_id" : "") . " FROM hd_messages WHERE thread_id=? ORDER BY id ASC LIMIT " . (int)$limit);
    $st->execute([(int)$thread_id]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['sources'] = $r['sources'] ? (json_decode($r['sources'], true) ?: []) : [];
        $r['files'] = $r['attachments'] ? array_map(fn($a) => ['name' => $a['name'] ?? '', 'kind' => $a['kind'] ?? 'doc'], json_decode($r['attachments'], true) ?: []) : [];
        unset($r['attachments']);
    }
    return $rows;
}

function hd_get_thread($pdo, $thread_id, $bot_id, $member_id)
{
    $st = $pdo->prepare("SELECT * FROM hd_threads WHERE id=? AND bot_id=? AND member_id=?");
    $st->execute([(int)$thread_id, (int)$bot_id, (int)$member_id]);
    $t = $st->fetch(\PDO::FETCH_ASSOC);
    return $t ?: null;
}

function hd_delete_thread($pdo, $thread_id, $bot_id, $member_id)
{
    $t = hd_get_thread($pdo, $thread_id, $bot_id, $member_id);
    if (!$t) return false;
    try {
        $fs = $pdo->prepare("SELECT f.id, f.path FROM hd_files f JOIN hd_messages m ON m.id=f.message_id WHERE m.thread_id=?");
        $fs->execute([(int)$t['id']]);
        foreach ($fs->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $f) { @unlink(hd_files_dir() . '/' . $f['path']); $pdo->prepare("DELETE FROM hd_files WHERE id=?")->execute([(int)$f['id']]); }
    } catch (\Throwable $e) {}
    $pdo->prepare("DELETE FROM hd_messages WHERE thread_id=?")->execute([(int)$t['id']]);
    $pdo->prepare("DELETE FROM hd_threads WHERE id=?")->execute([(int)$t['id']]);
    if (function_exists('mem_forget_thread')) mem_forget_thread($pdo, (int)$t['id']);
    return true;
}

// =============================================================================
// پاسخ‌گویی دقیق و مستند
// =============================================================================
function hd_build_prompt($bot, $chunks, $qas, $ctx = [])
{
    $name = trim($bot['name']);
    $spec = trim((string)$bot['specialty']);
    $lv = $ctx['levels'] ?? ['realism' => 70, 'grounding' => !empty($bot['strict_mode']) ? 95 : 60];
    $g = (int)$lv['grounding'];
    $r = (int)$lv['realism'];
    $profile = $ctx['profile'] ?? [];
    $intake = $ctx['intake'] ?? [];
    $p = [];
    $p[] = "تو «{$name}» هستی" . ($spec !== '' ? "، {$spec}" : '') . ". به زبان فارسی، مؤدبانه و حرفه‌ای با مراجع گفتگو کن.";
    $inst = trim((string)$bot['instructions']);
    if ($inst !== '') $p[] = "دستورالعمل تخصصی:\n" . mb_substr($inst, 0, 4000);

    // --- روش گفتگو: اول شناخت، بعد مشاوره ---
    $missing = [];
    foreach ($intake as $label => $q) if (!isset($profile[$label]) || trim((string)$profile[$label]) === '') $missing[$label] = $q;
    $style = "روش گفتگو (اجباری):\n"
        . "- مثل یک مشاور حرفه‌ای رفتار کن: اول مراجع و مسئله‌اش را کامل بشناس، بعد مشاوره تخصصی و شخصی بده.\n"
        . "- هر اطلاعاتی که در «پرونده مراجع»، «یادداشت‌های مشاور» یا همین گفتگو آمده را می‌دانی؛ هرگز دوباره آن را نپرس و در پاسخ‌ها از آن استفاده کن.\n"
        . "- در هر پیام حداکثر ۲ سوال مرتبط بپرس. سوال‌ها کوتاه و روشن باشند.\n"
        . "- اگر پاسخ مراجع مبهم یا ناقص بود، فقط همان نکته را کوتاه دوباره بپرس.\n"
        . "- کوتاه و دقیق بنویس: بدون مقدمه، تعارف، تکرار صورت سوال، یا توضیحات اضافه و کلی. فقط آنچه برای این مراجع لازم است.\n";
    if ($missing) {
        $style .= "- مرحله فعلی: «شناخت». هنوز این اطلاعات را نداری (به همین ترتیب و طبیعی بپرس):\n";
        $i = 0;
        foreach ($missing as $label => $q) { $i++; $style .= "  {$i}) {$label}" . ($q !== '' ? " — مثلاً: «{$q}»" : '') . "\n"; }
        $style .= !empty($bot['intake_strict'])
            ? "- تا این اطلاعات کامل نشده، مشاوره و راه‌حل تخصصی نده (پاسخ به پرسش مراجع درباره یادداشت‌ها و نظر مشاورش مجاز است). اگر مراجع فوراً سوالی پرسید، در یک جمله بگو برای پاسخ دقیق باید چند نکته را بدانی و سوال بعدی را بپرس. (در موارد اورژانسی/خطرناک، فوراً راهنمایی ایمنی بده.)\n"
            : "- اگر مراجع سوال مشخصی پرسید، کوتاه پاسخ بده و بعد سوالات شناخت را ادامه بده.\n";
    } elseif ($intake) {
        $style .= "- مرحله فعلی: «مشاوره». اطلاعات اولیه کامل است؛ با تحلیل پرونده و گفتگو، مشاوره تخصصی، شخصی‌سازی‌شده و عملی بده. اگر برای پاسخ دقیق به اطلاعات بیشتری نیاز داری، اول همان را بپرس.\n";
    }
    $p[] = $style;
    if (function_exists('cx_tone_rules')) $p[] = cx_tone_rules();

    // --- واقع‌گرایی ---
    if ($r >= 80) $p[] = "لحن: کاملاً واقع‌بین و صریح باش؛ ریسک‌ها، محدودیت‌ها و خبرهای ناخوشایند را بی‌پرده ولی محترمانه بگو. امید واهی نده و تعارف نکن.";
    elseif ($r >= 50) $p[] = "لحن: واقع‌بین و صادق، همراه با همدلی و لحن حمایتگر. واقعیت‌ها را پنهان نکن.";
    else $p[] = "لحن: گرم، حمایتگر و امیدبخش؛ نکات منفی را با ملایمت و همراه با راهکار بگو (ولی هرگز اطلاعات نادرست نده).";

    // --- پایبندی به منابع ---
    $rules = "قوانین دقت (اجباری):\n"
        . "- هر مطلبی که از «منابع» زیر می‌آوری، بلافاصله بعدش شماره همان منبع را به شکل [1] بنویس.\n"
        . "- هرگز عدد، دوز دارو، قیمت، تاریخ، نام قانون یا شماره ماده، یا آماری را که مطمئن نیستی حدس نزن و نساز.\n"
        . "- سوال‌های شناخت، احوال‌پرسی و تشکر نیازی به منبع ندارند.\n";
    if ($g >= 90) {
        $rules .= "- پاسخ تخصصی را فقط بر اساس «پاسخ‌های تأییدشده»، «منابع»، «پرونده» و «یادداشت‌های مشاور» بده. اگر پاسخ در آن‌ها نیست، صادقانه بگو «در منابع معتبر من پاسخ دقیقی برای این سوال پیدا نکردم» و در صورت امکان بگو از چه کسی بپرسد.\n";
    } elseif ($g >= 60) {
        $rules .= "- اولویت با منابع است. اگر پاسخ در منابع نیست، می‌توانی از دانش تخصصی عمومی کمک بگیری اما صریح بگو «این بخش از منابع تأییدشده نیست».\n";
    } else {
        $rules .= "- از منابع و دانش تخصصی خودت آزادانه استفاده کن؛ هر جا از منابع گفتی شماره منبع را بنویس.\n";
    }
    $rules .= "- قالب: پاراگراف‌های کوتاه؛ برای موارد متعدد از «- » استفاده کن؛ برای تأکید **متن**. لینک‌ها به شکل [عنوان](آدرس).";
    $p[] = $rules;

    $len = ['short' => 'پاسخ‌ها کوتاه و فشرده باشند (حداکثر چند جمله).', 'medium' => 'پاسخ‌ها کامل ولی خلاصه باشند.', 'long' => 'در مرحله مشاوره می‌توانی جامع‌تر بنویسی، ولی بدون حاشیه.'][$bot['response_length'] ?? 'medium'] ?? '';
    if ($len) $p[] = $len;

    $disc = trim((string)$bot['disclaimer']);
    if ($disc !== '') $p[] = "هشدار حوزه تخصصی (فقط وقتی توصیه تخصصی می‌دهی، در یک خط کوتاه در پایان): " . $disc;

    // --- ثبت اطلاعات تازه پرونده (دستور) ---
    $labels = $intake ? implode('، ', array_keys($intake)) : '';
    $p[] = "ثبت پرونده (مخفی از مراجع): اگر در پیام‌های مراجع اطلاعات تازه یا تغییر‌یافته‌ای درباره خودش گفت (مثل مشخصات فردی، سن، شغل، تحصیلات، وضعیت تأهل، مذهب، ورزش و علایق، سابقه بیماری، هدف، مشکل اصلی و...)، در انتهای پاسخ یک خط به این شکل اضافه کن:\n"
        . "[[PROFILE]]{\"عنوان کوتاه\": \"مقدار\"}[[/PROFILE]]\n"
        . ($labels !== '' ? "برای موارد فهرست شناخت دقیقاً از همین عنوان‌ها استفاده کن: {$labels}.\n" : '')
        . "فقط اطلاعاتی که خود مراجع صریحاً گفته؛ حدس نزن. اگر اطلاعات تازه‌ای نبود، این خط را ننویس. هرگز درباره این خط با مراجع صحبت نکن.";

    // --- اطلاعات مراجع: پرونده و یادداشت‌های مشاور ---
    if ($profile) {
        $p[] = "\n=== پرونده مراجع (این‌ها را می‌دانی؛ دوباره نپرس) ===";
        foreach ($profile as $k => $v) $p[] = "- {$k}: {$v}";
        $p[] = "=== پایان پرونده ===";
    }
    if (!empty($ctx['notes'])) {
        $kinds = function_exists('hd_note_kinds') ? hd_note_kinds() : [];
        $p[] = "\n=== یادداشت‌ها و نتایج جلسات مشاور انسانی همین مراجع (معتبر و با بالاترین اولویت بعد از پاسخ‌های تأییدشده) ===\n"
            . "این‌ها را مشاور متخصص پس از بررسی یا جلسه حضوری با همین مراجع نوشته است. در همه پاسخ‌ها آن‌ها را اعمال کن، با آن‌ها تناقض نداشته باش و توصیه‌هایت را بر پایه آن‌ها ادامه بده.\n"
            . "اگر مراجع درباره نظر، توصیه یا نتیجه جلسه مشاورش پرسید، از روی همین یادداشت‌ها پاسخ بده (نگو که اطلاعی نداری). یادداشت‌های «محرمانه» را در مشاوره اعمال کن ولی متن آن‌ها را عیناً نقل نکن.";
        foreach ($ctx['notes'] as $n) {
            $lbl = trim(preg_replace('/^\S+\s/u', '', (string)($kinds[$n['kind']] ?? 'یادداشت')));
            $p[] = "[" . substr((string)$n['created_at'], 0, 10) . " — " . $lbl . " — " . ($n['author'] ?: 'مشاور') . (empty($n['show_member']) ? " — محرمانه" : "") . "] "
                . ($n['title'] !== '' ? $n['title'] . ": " : '') . mb_substr((string)$n['content'], 0, 1500);
        }
        $p[] = "=== پایان یادداشت‌ها ===";
    }
    if (!empty($ctx['mem_core'])) {
        $p[] = "\n=== حافظه بلندمدت این مراجع (خلاصه همه گفتگوها از اولین روز؛ معتبر است، همیشه به خاطر داشته باش و مشاوره را در ادامه همین سابقه بده) ===\n" . $ctx['mem_core'] . "\n=== پایان حافظه بلندمدت ===";
    }
    if (!empty($ctx['mem_recall'])) {
        $p[] = "\n=== یادآوری از گفتگوهای قدیمی‌تر که به پیام فعلی مربوط است (با تاریخ؛ اگر مراجع به گذشته اشاره کرد یا پرسید قبلاً چه گفته، از همین‌ها پاسخ بده) ===\n" . $ctx['mem_recall'] . "\n=== پایان یادآوری ===";
    }
    if (!empty($ctx['memory'])) {
        $p[] = "\n=== گفتگوهای قبلی همین مراجع (سابقه مشاوره؛ همه را به خاطر داشته باش، با آن‌ها تناقض نداشته باش و مشاوره را در ادامه همین سابقه بده) ===";
        foreach ($ctx['memory'] as $mline) $p[] = $mline;
        $p[] = "=== پایان گفتگوهای قبلی ===";
    }
    if (!empty($ctx['thread_summary'])) {
        $p[] = "\n=== خلاصه کامل بخش‌های قبلی همین گفتگو (پیام‌های قدیمی‌تر؛ معتبر است و باید همه را به خاطر داشته باشی) ===\n" . $ctx['thread_summary'] . "\n=== پایان خلاصه ===";
    }
    $p[] = "حافظه: هرچه در این گفتگو، خلاصه‌ها و گفتگوهای قبلی آمده را می‌دانی. هرگز نگو که به یاد نداری یا به گفتگوهای قبلی دسترسی نداری؛ اگر چیزی واقعاً در این اطلاعات نیست، محترمانه از مراجع بپرس.";
    if (!empty($ctx['files_text'])) {
        $p[] = "\n=== متن فایل‌هایی که مراجع در این پیام فرستاده ===\n" . $ctx['files_text'] . "\n=== پایان فایل‌ها ===";
    }

    if ($qas) {
        $p[] = "\n=== پاسخ‌های تأییدشده متخصص (بالاترین اولویت؛ دقیقاً مطابق این‌ها پاسخ بده) ===";
        foreach ($qas as $q) $p[] = "سوال: {$q['question']}\nپاسخ تأییدشده: {$q['answer']}";
        $p[] = "=== پایان پاسخ‌های تأییدشده ===";
    }

    $sources = [];
    if ($chunks) {
        $groups = [];
        foreach ($chunks as $c) {
            $k = $c['url'] !== '' ? $c['url'] : ($c['source_id'] . ':' . $c['page_id']);
            if (!isset($groups[$k])) $groups[$k] = ['title' => $c['title'], 'url' => $c['url'], 'texts' => []];
            $groups[$k]['texts'][] = $c['chunk'];
        }
        $p[] = "\n=== منابع ===";
        $i = 0;
        foreach ($groups as $gg) {
            $i++;
            $sources[$i] = ['n' => $i, 'title' => $gg['title'] !== '' ? $gg['title'] : ('منبع ' . $i), 'url' => $gg['url']];
            $p[] = "[{$i}] " . ($gg['title'] !== '' ? $gg['title'] : 'بدون عنوان') . ($gg['url'] !== '' ? " — {$gg['url']}" : '') . "\n" . implode("\n…\n", $gg['texts']);
        }
        $p[] = "=== پایان منابع ===";
    } elseif (!$qas) {
        $p[] = "\n(برای این پیام منبع مرتبطی پیدا نشد.)";
    }
    return [implode("\n", $p), $sources];
}

/**
 * پاسخ به پیام مخاطب
 * @param array $opts ['file_ids' => [...], 'model_id' => int]
 * @return array ['ok'=>bool, 'reply'=>..., 'sources'=>[...], 'thread_id'=>..., 'error'=>..., 'quota'=>[...]]
 */
function hd_answer($pdo, $bot, $member, $thread_id, $message, $opts = [])
{
    $message = trim((string)$message);
    $files = hd_files_for_message($pdo, $bot['id'], $member['id'], $opts['file_ids'] ?? []);
    // ویرایش پیام قبلی: پیام و پاسخ‌های بعد از آن با پاسخ تازه جایگزین می‌شود (فایل‌های همان پیام حفظ می‌شود)
    $edit_id = (int)($opts['edit_id'] ?? 0);
    $edit_row = null;
    if ($edit_id) {
        $er = $pdo->prepare("SELECT * FROM hd_messages WHERE id=? AND thread_id=? AND bot_id=? AND member_id=? AND role='user'");
        $er->execute([$edit_id, (int)$thread_id, (int)$bot['id'], (int)$member['id']]);
        $edit_row = $er->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$edit_row) return ['ok' => false, 'error' => 'پیام برای ویرایش یافت نشد.'];
        try {
            $ef = $pdo->prepare("SELECT * FROM hd_files WHERE bot_id=? AND member_id=? AND message_id=?");
            $ef->execute([(int)$bot['id'], (int)$member['id'], $edit_id]);
            $have = array_map(fn($f) => (int)$f['id'], $files);
            foreach ($ef->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $f) if (!in_array((int)$f['id'], $have, true)) $files[] = $f;
        } catch (\Throwable $e) {}
    }
    if ($message === '' && !$files) return ['ok' => false, 'error' => 'پیام خالی است.'];
    if ($message === '') $message = 'فایل‌های پیوست را بررسی کن.';
    if (mb_strlen($message) > 3000) return ['ok' => false, 'error' => 'پیام بیش از حد طولانی است.'];
    if (empty($bot['is_active'])) return ['ok' => false, 'error' => HD_MSG_UNAVAILABLE];

    $reason = '';
    if (!hd_owner_ok($pdo, $bot, $reason)) return ['ok' => false, 'error' => HD_MSG_UNAVAILABLE];

    $quota = hd_quota($pdo, $bot, $member);
    if (!$quota['allowed']) {
        $can_buy = hd_bot_sells($pdo, $bot);
        $support = trim((string)($bot['support_text'] ?? ''));
        if (function_exists('cx_has_free_plan') && !$can_buy) $can_buy = cx_has_free_plan($pdo, $bot);
        if ($quota['reason'] === 'window') $msg = 'به سقف پیام‌های این بازه رسیده‌اید؛ از ' . $quota['reset_text'] . ' دوباره می‌توانید پیام بدهید.' . ($can_buy ? ' با ارتقای بسته، سقف بیشتری دارید.' : '');
        elseif ($quota['reason'] === 'daily') $msg = 'به سقف پیام‌های امروز رسیده‌اید؛ از ' . ($quota['reset_text'] ?: 'فردا') . ' دوباره می‌توانید پیام بدهید.' . (!empty($quota['can_borrow']) ? ' (یا با «ریست رایگان» از سهمیه روزهای بعد استفاده کنید)' : '');
        elseif ($quota['reason'] === 'weekly') $msg = 'به سقف پیام‌های این هفته رسیده‌اید؛ از ' . $quota['reset_text'] . ' دوباره می‌توانید پیام بدهید.' . (!empty($quota['can_borrow']) ? ' (یا با «ریست رایگان» از سهمیه هفته‌های بعد استفاده کنید)' : '');
        elseif ($quota['reason'] === 'monthly') $msg = 'به سقف پیام‌های ماهانه اشتراک رسیده‌اید؛ از ' . $quota['reset_text'] . ' دوباره می‌توانید پیام بدهید یا پلن را ارتقا دهید.';
        else $msg = $can_buy ? 'پیام‌های شما تمام شده است. برای ادامه، یکی از بسته‌ها را تهیه کنید.' : 'پیام‌های شما تمام شده است.';
        if ($support !== '' && $quota['reason'] === 'total') $msg .= "\n" . $support;
        return ['ok' => false, 'error' => $msg, 'quota' => $quota, 'can_buy' => $can_buy, 'limited' => $quota['reason']];
    }
    // محدودیت نرخ: حداکثر ۸ پیام در دقیقه
    $rl = $pdo->prepare("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND member_id=? AND role='user' AND created_at >= ?");
    $rl->execute([(int)$bot['id'], (int)$member['id'], hd_now(-60)]);
    if ((int)$rl->fetchColumn() >= 8) return ['ok' => false, 'error' => 'تعداد پیام‌ها زیاد است؛ لطفاً کمی صبر کنید.'];

    // مدل: انتخاب مراجع (در صورت اجازه) یا پیش‌فرض ربات؛ مصرف سهمیه به نسبت هزینه مدل
    $mm = hd_member_models($pdo, $bot, $member, $quota);
    $hd_model = null; $msg_cost = 1;
    if (!empty($mm['blocked'])) return ['ok' => false, 'error' => 'سقف استفاده از مدل‌های گفتگوی بسته شما تمام شده یا مدلی در بسته فعال نیست؛ بسته را تمدید یا ارتقا دهید.', 'quota' => $quota, 'can_buy' => true, 'upgrade' => true];
    // ارسال فایل/تصویر فقط در بسته‌هایی که این امکان را دارند
    if ($files && !$edit_row && function_exists('cx_member_flags') && empty(cx_member_flags($pdo, $bot, $member, $quota)['files'])) {
        return ['ok' => false, 'error' => 'ارسال تصویر و فایل در بسته فعلی شما نیست؛ با ارتقای بسته می‌توانید فایل بفرستید.', 'can_buy' => true, 'upgrade' => true];
    }
    $want = (int)($opts['model_id'] ?? 0) ?: (int)($member['chosen_model'] ?? 0);
    foreach ($mm['list'] as $x) if ($x['id'] === $mm['default']) { $hd_model = $x['row']; }
    foreach ($mm['list'] as $x) if ($want && $x['id'] === $want) { $hd_model = $x['row']; $msg_cost = $x['cost']; }
    if (!empty($opts['model_id']) && (int)$opts['model_id'] !== (int)($member['chosen_model'] ?? 0) && $hd_model && (int)$hd_model['id'] === (int)$opts['model_id']) {
        $pdo->prepare("UPDATE hd_members SET chosen_model=? WHERE id=?")->execute([(int)$opts['model_id'], (int)$member['id']]);
    }
    if ($msg_cost > 1 && empty($member['is_owner']) && $quota['remaining'] >= 0 && $quota['remaining'] < $msg_cost) {
        return ['ok' => false, 'error' => 'پیام‌های باقی‌مانده برای این مدل کافی نیست (هر پیام = ' . $msg_cost . ' پیام). مدل دیگری انتخاب کنید.', 'quota' => $quota];
    }

    // گفتگو و حافظه
    $thread = $thread_id ? hd_get_thread($pdo, $thread_id, $bot['id'], $member['id']) : null;
    $history = [];
    $thread_summary = '';
    if ($thread && $edit_row) {
        // ویرایش: فقط پیام‌های پیش از پیام ویرایش‌شده (خلاصه فقط اگر مربوط به قبل از آن باشد)
        $hs = $pdo->prepare("SELECT id, role, content, attachments FROM hd_messages WHERE thread_id=? AND id<? ORDER BY id DESC LIMIT " . (int)HD_HIST_MAX);
        $hs->execute([(int)$thread['id'], $edit_id]);
        $history = array_reverse($hs->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        $thread_summary = ((int)($thread['summary_upto'] ?? 0) > 0 && (int)$thread['summary_upto'] < $edit_id) ? trim((string)($thread['summary'] ?? '')) : '';
        if ($thread_summary !== '') { $cut = (int)$thread['summary_upto']; $history = array_values(array_filter($history, fn($h) => (int)$h['id'] > $cut)); }
    } elseif ($thread_id && $edit_id) {
        return ['ok' => false, 'error' => 'گفتگو یافت نشد.'];
    } elseif ($thread) {
        // همه پیام‌های خلاصه‌نشده + خلاصه کامل بخش‌های قبلی؛ هیچ بخشی از گفتگو حذف نمی‌شود
        $history = hd_thread_history($pdo, $thread);
        if (count($history) > HD_HIST_MAX) {
            hd_summarize_thread($pdo, $bot, $hd_model, (int)$thread['id'], (int)$history[count($history) - HD_HIST_KEEP - 1]['id']);
            $thread = hd_get_thread($pdo, $thread['id'], $bot['id'], $member['id']);
            $history = hd_thread_history($pdo, $thread);
            if (count($history) > HD_HIST_MAX) $history = array_slice($history, -HD_HIST_MAX);   // خلاصه‌سازی ممکن نشد
        }
        if (count($history) < HD_HIST_KEEP) {
            // چند پیام آخر همیشه کامل در اختیار مدل باشد (حتی اگر قبلاً خلاصه شده باشند)
            $hs = $pdo->prepare("SELECT id, role, content, attachments FROM hd_messages WHERE thread_id=? ORDER BY id DESC LIMIT " . (int)HD_HIST_KEEP);
            $hs->execute([(int)$thread['id']]);
            $history = array_reverse($hs->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        }
        $thread_summary = trim((string)($thread['summary'] ?? ''));
    }
    $profile = hd_profile_get($member);
    $intake = hd_intake_list($bot);
    $notes = hd_notes($pdo, $bot['id'], $member['id'], false, true, 10);
    $memory = hd_member_memory($pdo, $bot['id'], $member['id'], $thread ? (int)$thread['id'] : 0);

    // فایل‌ها: متن اسناد در پرامپت، تصاویر به‌صورت پیوست پیام
    $files_text = ''; $images = []; $att = [];
    foreach ($files as $f) {
        $att[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'kind' => $f['kind']];
        if ($f['kind'] === 'doc') $files_text .= "--- فایل: {$f['name']} ---\n" . mb_substr((string)$f['text'], 0, max(2000, (int)(24000 / max(1, count($files))))) . "\n";
        else {
            $path = hd_files_dir() . '/' . $f['path'];
            if (is_file($path)) $images[] = ['name' => $f['name'], 'url' => 'data:' . $f['mime'] . ';base64,' . base64_encode((string)file_get_contents($path))];
        }
    }

    // جستجو: پیام فعلی + آخرین پیام کاربر + متن فایل‌ها
    $search = $message . ' ' . mb_substr($files_text, 0, 600);
    for ($i = count($history) - 1; $i >= 0; $i--) {
        if ($history[$i]['role'] === 'user') { $search .= ' ' . mb_substr($history[$i]['content'], 0, 300); break; }
    }
    // نسخه ۴۱: حافظه بلندمدت مراجع + یادآوری هدفمند از همه گفتگوهای قدیمی (حتی هزاران پیام قبل)
    $mem_core = ''; $mem_recall = '';
    if (function_exists('mem_core_get')) {
        try {
            $mem_core = mem_core_get($pdo, (int)$member['id']);
            $first_vis = $history ? (int)$history[0]['id'] : 0;
            $mem_recall = mem_recall($pdo, (int)$bot['id'], (int)$member['id'], $message . ' ' . mb_substr($files_text, 0, 300), $thread ? (int)$thread['id'] : 0, $first_vis ?: PHP_INT_MAX, 7000);
        } catch (\Throwable $e) { error_log('[MEM] answer: ' . $e->getMessage()); }
    }
    $kb_ids = hd_bot_kb_ids($pdo, $bot);
    $chunks = hd_retrieve($pdo, $kb_ids, $search, 6);
    // دانش کوچک → همه منابع کامل خوانده می‌شود (مرتبط‌ترین‌ها اول) تا هیچ اطلاعاتی از قلم نیفتد
    if (function_exists('kb_bot_all_chunks') && ($all_chunks = kb_bot_all_chunks($pdo, $kb_ids))) {
        $have = array_map(fn($c) => (int)$c['id'], $chunks);
        foreach ($all_chunks as $ac) if (!in_array((int)$ac['id'], $have, true)) $chunks[] = $ac;
    }
    $qas = hd_retrieve_qa($pdo, $bot['id'], $message, 3);
    if (function_exists('kb_bot_all_qa') && ($all_qa = kb_bot_all_qa($pdo, $bot['id']))) {
        $hq = array_map(fn($q) => $q['question'], $qas);
        foreach ($all_qa as $aq) if (!in_array($aq['question'], $hq, true)) $qas[] = $aq;
    }
    $levels = hd_bot_levels($pdo, $bot);
    [$system, $sources] = hd_build_prompt($bot, $chunks, $qas, [
        'levels' => $levels, 'profile' => $profile, 'intake' => $intake, 'notes' => $notes, 'memory' => $memory, 'files_text' => $files_text,
        'thread_summary' => $thread_summary, 'mem_core' => $mem_core, 'mem_recall' => $mem_recall,
    ]);
    // چت صفحه اصلی سایت: نقش دستیار سایت + اطلاعات خدمات و پلن‌ها
    if (function_exists('sb_is_bot') && sb_is_bot($pdo, $bot)) $system .= sb_prompt_block($pdo, $message);
    // لینک‌های مفید و راهنما/سوالات متداول مرتبط این چت‌بات
    if (function_exists('ex_links_block')) {
        try {
            $system .= ex_links_block($pdo, (int)$bot['user_id'], (int)$bot['id'], $search);
            $faq_all = ex_articles($pdo, 'bot', (int)$bot['id']);
            $faq_len = 0; foreach ($faq_all as $fa) $faq_len += mb_strlen($fa['title'] . $fa['body']);
            $faq_ctx = ex_articles_context($pdo, 'bot', (int)$bot['id'], $message, 3000, $faq_len <= 3000 ? 0 : 3);   // کم = همه
            if (function_exists('cx_user_articles')) { $ux = cx_articles_text(cx_user_articles($pdo, (int)$bot['user_id'], (string)(int)$bot['id']), $message, 3000); if ($ux !== '') $faq_ctx = trim($faq_ctx . "\n\n" . $ux); }
            if ($faq_ctx !== '') $system .= "\n\n=== راهنما و سوالات متداول استفاده از همین سرویس (فقط اگر سوال درباره خود سرویس است) ===\n" . $faq_ctx;
            if (trim((string)($bot['support_text'] ?? '')) !== '') $system .= "\n\nراه ارتباط با پشتیبانی/مشاور انسانی (فقط وقتی لازم است): " . trim((string)$bot['support_text']);
            // فهرست موضوعات دانش (برای وقتی پرسش با کلمات دیگری بیان شده)
            if (function_exists('kb_bot_source_titles') && count($chunks) < 3) {
                $stt = kb_bot_source_titles($pdo, $kb_ids);
                if ($stt) $system .= "\n\nموضوعات موجود در دانش این مشاور: " . implode('، ', array_slice($stt, 0, 40)) . "\n(اگر پرسش به یکی از این موضوعات مربوط است ولی منبعش در بالا نیامده، از مراجع بخواهد سوالش را دقیق‌تر یا با کلمات دیگر بپرسد؛ اطلاعات نساز.)";
            }
        } catch (\Throwable $e) {}
    }

    $msgs = [['role' => 'system', 'content' => $system]];
    foreach ($history as $h) {
        $c = mb_substr($h['content'], 0, $h['role'] === 'user' ? 6000 : 4000);
        if ($h['role'] === 'user' && !empty($h['attachments'])) {
            $an = array_column(json_decode((string)$h['attachments'], true) ?: [], 'name');
            if ($an) $c .= "\n(فایل‌های پیوست: " . implode('، ', $an) . ')';
        }
        $msgs[] = ['role' => $h['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $c];
    }
    // پاسخ (ریپلای) به یک پیام مشخص
    $reply_to = 0; $reply_ctx = '';
    if (!empty($opts['reply_to']) && $thread) {
        $rq = $pdo->prepare("SELECT id, role, content FROM hd_messages WHERE id=? AND thread_id=? AND bot_id=? AND member_id=?" . ($edit_row ? " AND id<" . (int)$edit_id : ''));
        $rq->execute([(int)$opts['reply_to'], (int)$thread['id'], (int)$bot['id'], (int)$member['id']]);
        if ($rr = $rq->fetch(\PDO::FETCH_ASSOC)) {
            $reply_to = (int)$rr['id'];
            $reply_ctx = '(این پیام در پاسخ به ' . ($rr['role'] === 'user' ? 'پیام قبلی خودم' : 'این بخش از پاسخ تو') . ' است: «' . mb_substr(preg_replace('/\s+/u', ' ', hd_plain_for_speech($rr['content'])), 0, 600) . "»)\n";
        }
    }
    if (function_exists('cx_comments_prompt')) $system .= cx_comments_prompt($pdo, (int)$bot['id'], (int)$member['id']);
    if (function_exists('cx_tone_hint')) $system .= cx_tone_hint($message);
    $msgs[0]['content'] = $system;
    $user_text = $reply_ctx . $message . ($att ? "\n(فایل‌های پیوست: " . implode('، ', array_column($att, 'name')) . ')' : '');
    if ($images) {
        $parts = [['type' => 'text', 'text' => $user_text]];
        foreach ($images as $im) $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $im['url']]];
        $msgs[] = ['role' => 'user', 'content' => $parts];
    } else {
        $msgs[] = ['role' => 'user', 'content' => $user_text];
    }

    $max_out = ['short' => 450, 'medium' => 800, 'long' => 1500][$bot['response_length'] ?? 'medium'] ?? 800;
    // سقف توکن ورودی/خروجی (پلن صاحب ربات و تنظیم ربات)
    if (function_exists('ex_bot_limits')) {
        [$lim_in, $lim_out] = ex_bot_limits($pdo, $bot);
        if ($lim_out > 0) $max_out = max(64, min($max_out, $lim_out));
        if ($lim_in > 0) $msgs = ex_fit_input($msgs, $lim_in);
    }
    $temp = round(0.15 + (100 - $levels['grounding']) / 100 * 0.45, 2);
    $cfg = saas_get_api_config($pdo);
    $ai = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $hd_model, $msgs, $max_out, $temp) : aichat_call_ai($cfg, $msgs, $max_out, $temp);
    if (empty($ai['ok']) && $images) {
        // مدل از تصویر پشتیبانی نمی‌کند → بدون تصویر، با اطلاع به مدل
        $msgs[count($msgs) - 1] = ['role' => 'user', 'content' => $user_text . "\n(مراجع " . count($images) . " تصویر فرستاده که قابل مشاهده نیست؛ اگر لازم است از او بخواه محتوای تصویر را توضیح دهد.)"];
        $ai = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $hd_model, $msgs, $max_out, $temp) : aichat_call_ai($cfg, $msgs, $max_out, $temp);
    }
    $hd_model = $ai['model_row'] ?? $hd_model;   // مدلی که واقعاً پاسخ داد (ممکن است جایگزین باشد)
    if (empty($ai['ok'])) {
        error_log('[HD] AI failed bot=' . $bot['id'] . ' ' . ($ai['detail'] ?? ''));
        return ['ok' => false, 'error' => HD_MSG_DOWN];
    }
    $reply = trim($ai['content']);
    $new_facts = hd_extract_profile_block($reply);
    if ($reply === '') $reply = 'متوجه شدم. لطفاً بیشتر توضیح دهید.';
    $tokens = function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $hd_model, $ai) : (int)($ai['tokens'] ?? 0);
    $row_used = !empty($ai['model_row']) ? $ai['model_row'] : $hd_model;
    $tok_det = ['m' => (string)($row_used['title'] ?? ''), 'u' => 't',
                'in' => (int)($ai['prompt_tokens'] ?? 0), 'out' => (int)($ai['completion_tokens'] ?? 0), 'ai' => $tokens];
    $fc = count($files) * (int)hd_sys($pdo)['hd_file_cost'];
    if ($fc > 0) { $tokens += $fc; $tok_det['f'] = $fc; }

    // حالت دقت بالا: بازبینی پاسخ با منابع و حذف ادعاهای بدون پشتوانه
    if (!empty($bot['verify_answers']) && ($sources || $qas)) {
        $recent = '';
        foreach (array_slice($history, -6) as $h) $recent .= ($h['role'] === 'user' ? 'مراجع' : 'پاسخ قبلی') . ': ' . mb_substr((string)$h['content'], 0, 600) . "\n";
        $chk = hd_verify_answer(function_exists('biz_cfg_for_model') ? biz_cfg_for_model($cfg, $hd_model) : $cfg, $system, $message, $reply, $max_out, $recent);
        if ($chk['ok']) { $reply = $chk['content']; hd_extract_profile_block($reply); $vt = function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $hd_model, $chk) : $chk['tokens']; $tokens += $vt; $tok_det['v'] = $vt; }
    }

    // تکمیل پرونده مراجع
    if ($new_facts) {
        try { hd_profile_merge($pdo, $member, $new_facts); } catch (\Throwable $e) { error_log('[HD] profile: ' . $e->getMessage()); }
    }

    // منابعی که واقعاً در پاسخ به آن‌ها ارجاع شده
    $used = [];
    if ($sources && preg_match_all('/\[(\d{1,2})\]/u', strtr($reply, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $mm2)) {
        foreach (array_unique($mm2[1]) as $n) if (isset($sources[(int)$n])) $used[] = $sources[(int)$n];
        usort($used, fn($a, $b) => $a['n'] <=> $b['n']);
    }
    if (empty($bot['show_sources'])) $used = [];

    // ذخیره
    $now = hd_now();
    if ($edit_row && $thread) {
        // حذف پیام ویرایش‌شده و همه پیام‌های بعد از آن (فایل‌های همان پیام به پیام جدید منتقل می‌شود)
        $old = $pdo->prepare("SELECT id FROM hd_messages WHERE thread_id=? AND id>=?");
        $old->execute([(int)$thread['id'], $edit_id]);
        $old_ids = array_map('intval', $old->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (function_exists('cx_media_delete_for_messages')) cx_media_delete_for_messages($pdo, (int)$bot['id'], (int)$member['id'], $old_ids);
        if ($old_ids) {
            try { $pdo->prepare("UPDATE hd_files SET message_id=0 WHERE message_id IN (" . implode(',', $old_ids) . ") AND member_id=?")->execute([(int)$member['id']]); } catch (\Throwable $e) {}
            $pdo->prepare("DELETE FROM hd_messages WHERE thread_id=? AND id>=?")->execute([(int)$thread['id'], $edit_id]);
        }
        if ((int)($thread['summary_upto'] ?? 0) >= $edit_id) {
            $pdo->prepare("UPDATE hd_threads SET summary='', summary_upto=0 WHERE id=?")->execute([(int)$thread['id']]);
            if (function_exists('mem_forget_thread')) mem_forget_thread($pdo, (int)$thread['id']);   // دوباره از ابتدا خلاصه می‌شود
        }
    }
    if (!$thread) {
        $title = mb_substr(preg_replace('/\s+/u', ' ', $message), 0, 60);
        $pdo->prepare("INSERT INTO hd_threads (bot_id, member_id, title, created_at, updated_at) VALUES(?,?,?,?,?)")
            ->execute([(int)$bot['id'], (int)$member['id'], $title, $now, $now]);
        $thread = ['id' => (int)$pdo->lastInsertId()];
    } else {
        $pdo->prepare("UPDATE hd_threads SET updated_at=? WHERE id=?")->execute([$now, (int)$thread['id']]);
    }
    $ins = $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, attachments, created_at) VALUES(?,?,?,?,?,?,?,?,?)");
    $ins->execute([(int)$thread['id'], (int)$bot['id'], (int)$member['id'], 'user', $message, null, 0, $att ? json_encode($att, JSON_UNESCAPED_UNICODE) : null, $now]);
    $user_msg_id = (int)$pdo->lastInsertId();
    if ($reply_to) { try { $pdo->prepare("UPDATE hd_messages SET reply_to=? WHERE id=?")->execute([$reply_to, $user_msg_id]); } catch (\Throwable $e) {} }
    if ($att) $pdo->prepare("UPDATE hd_files SET message_id=? WHERE id IN (" . implode(',', array_map('intval', array_column($att, 'id'))) . ") AND member_id=?")->execute([$user_msg_id, (int)$member['id']]);
    $no_answer = (!$used && !$qas && mb_strlen($message) > 8 && !preg_match('/^(سلام|درود|ممنون|مرسی|متشکر|خداحافظ|ok|hi|hello)/iu', trim($message))) ? 1 : 0;
    $ins2 = $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, no_answer, model_id, tok_detail, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $ins2->execute([(int)$thread['id'], (int)$bot['id'], (int)$member['id'], 'assistant', $reply, $used ? json_encode($used, JSON_UNESCAPED_UNICODE) : null, $tokens, $no_answer, (int)($hd_model['id'] ?? 0), json_encode($tok_det, JSON_UNESCAPED_UNICODE), $now]);
    $assistant_id = (int)$pdo->lastInsertId();
    hd_consume_message($pdo, $bot, $member, $msg_cost, ($mm['use'] ?? 'bot') === 'free' ? 'free' : 'bot', $tokens);
    if (!empty($mm['gate']) && $hd_model && function_exists('mx_count')) mx_count($pdo, $mm['gate'], mx_main_id($hd_model));   // سقف استفاده مدل در بسته (نسخه ۵۹)
    $pdo->prepare("UPDATE hd_bots SET tokens_used = tokens_used + ? WHERE id=?")->execute([$tokens, (int)$bot['id']]);
    if ($tokens > 0) saas_deduct_credit($pdo, (int)$bot['user_id'], $tokens, 'چت‌بات تخصصی: ' . $bot['name']);
    if (!empty($member['voice_pending']) && function_exists('hd_msg_cost_add')) {
        // هزینه تبدیل صدا به متن (قبلاً کسر شده) در جزئیات همین پاسخ
        hd_msg_cost_add($pdo, $assistant_id, 'voice', (int)$member['voice_pending']);
        $pdo->prepare("UPDATE hd_members SET voice_pending=0 WHERE id=?")->execute([(int)$member['id']]);
    }

    try { hd_schedule_refresh($pdo, $bot['id']); } catch (\Throwable $e) {}
    try { hd_memory_schedule($pdo, $bot, $hd_model, (int)$member['id'], (int)$thread['id']); } catch (\Throwable $e) {}
    try { if (function_exists('ex_topics_schedule') && !empty($member['consent_share'])) ex_topics_schedule($pdo, $bot); } catch (\Throwable $e) {}

    $fresh = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
    $fresh->execute([(int)$member['id']]);
    $member = $fresh->fetch(\PDO::FETCH_ASSOC) ?: $member;
    $res = ['ok' => true, 'reply' => $reply, 'sources' => $used, 'thread_id' => (int)$thread['id'], 'message_id' => $assistant_id, 'user_message_id' => $user_msg_id, 'reply_to' => $reply_to, 'quota' => hd_quota($pdo, $bot, $member)];
    // تبلیغ (چت‌بات رایگان) و پیشنهاد مدل بهتر
    if (function_exists('ex_after_answer')) { try { $res += ex_after_answer($pdo, $bot, $member, $message, (int)$no_answer, $mm, $hd_model, $res['quota']); } catch (\Throwable $e) { error_log('[EX] after answer: ' . $e->getMessage()); } }
    return $res;
}

/** اطلاعات عمومی ربات برای فایل رابط (بدون هیچ اطلاعات پلتفرم) */
function hd_public_config($bot, $pdo = null)
{
    // رنگ چت‌بات = رنگ ویجت صاحب ربات (یک تنظیم برای هر دو)
    $color = (string)$bot['primary_color'];
    if ($pdo && function_exists('saas_get_widget_settings')) {
        try { $wc = (string)(saas_get_widget_settings($pdo, (int)$bot['user_id'])['primary_color'] ?? ''); if ($wc !== '') $color = $wc; } catch (\Throwable $e) {}
    }
    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $color, $c3)) $color = '#' . $c3[1] . $c3[1] . $c3[2] . $c3[2] . $c3[3] . $c3[3];
    return [
        'name'          => $bot['name'],
        'specialty'     => $bot['specialty'],
        'color'         => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#2563eb',
        'welcome'       => (string)$bot['welcome_message'],
        'disclaimer'    => (string)$bot['disclaimer'],
        'has_avatar'    => $bot['avatar_url'] !== '',
        'avatar_v'      => substr(md5((string)$bot['avatar_url']), 0, 8),
        'verify_mobile' => (bool)$bot['verify_mobile'],
        'free_messages' => (int)$bot['free_messages'],
        'daily_limit'   => (int)$bot['daily_limit'],
        'can_buy'       => false,   // در hd_api_dispatch مقداردهی می‌شود
        'support'       => (string)($bot['support_text'] ?? ''),
    ];
}


// =============================================================================
// فاز ۲ — فروش بسته پیام به مخاطبان (درگاه زرین‌پال خود صاحب ربات)
// =============================================================================
function hd_member_plans($pdo, $bot_id, $only_active = true)
{
    $st = $pdo->prepare("SELECT * FROM hd_member_plans WHERE bot_id=?" . ($only_active ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, price_toman ASC, id ASC LIMIT 50");
    $st->execute([(int)$bot_id]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/** آیا ربات بسته پولی قابل فروش دارد؟ */
function hd_bot_sells($pdo, $bot)
{
    // درگاه زرین‌پال یا کارت به کارت (نسخه ۵۶)
    if (trim((string)($bot['zp_merchant'] ?? '')) === '' && !(function_exists('c2c_bot_on') && c2c_bot_on($pdo, $bot))) return false;
    foreach (function_exists('cx_bot_plans') ? cx_bot_plans($pdo, $bot) : hd_member_plans($pdo, $bot['id']) as $p) if ((int)$p['price_toman'] >= 1000) return true;
    return false;
}

/** آدرس پایه زرین‌پال (HD_ZP_TEST_BASE فقط برای محیط آزمایش) */
function hd_zp_base($bot, $kind)
{
    if (defined('HD_ZP_TEST_BASE')) return HD_ZP_TEST_BASE;
    if (!empty($bot['zp_sandbox'])) return 'https://sandbox.zarinpal.com';
    return $kind === 'api' ? 'https://api.zarinpal.com' : 'https://www.zarinpal.com';
}

function hd_zp_post($url, $data)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = $res ? json_decode($res, true) : null;
    return is_array($j) ? $j : [];
}

/** شروع خرید بسته → آدرس درگاه */
function hd_buy($pdo, $bot, $member, $plan_id, $return_url, $code = '')
{
    if (!empty($member['is_owner'])) return ['ok' => false, 'error' => 'حساب آزمایشی مدیر نیاز به خرید ندارد.'];
    if (function_exists('cx_plan_for_bot')) $plan = cx_plan_for_bot($pdo, $bot, (int)$plan_id);
    else {
        $st = $pdo->prepare("SELECT * FROM hd_member_plans WHERE id=? AND bot_id=? AND is_active=1");
        $st->execute([(int)$plan_id, (int)$bot['id']]);
        $plan = $st->fetch(\PDO::FETCH_ASSOC);
    }
    // بسته رایگان: بدون درگاه (یک بار برای هر مخاطب)
    if ($plan && (int)$plan['price_toman'] === 0 && function_exists('cx_free_plan_take')) return cx_free_plan_take($pdo, $bot, $member, $plan);
    if (!hd_bot_sells($pdo, $bot)) return ['ok' => false, 'error' => 'خرید آنلاین در حال حاضر فعال نیست.'];
    if (!$plan || (int)$plan['price_toman'] < 1000) return ['ok' => false, 'error' => 'بسته انتخابی معتبر نیست.'];
    if (trim((string)($bot['zp_merchant'] ?? '')) === '') return ['ok' => false, 'c2c' => true, 'error' => 'پرداخت آنلاین فعال نیست؛ از «کارت به کارت» استفاده کنید.'];
    if (!preg_match('#^https?://[^\s]+$#i', (string)$return_url)) return ['ok' => false, 'error' => 'bad return url'];

    $q = hd_quote($pdo, $bot, (int)$member['id'], $plan, $code);
    if ($q['error'] !== '') return ['ok' => false, 'error' => $q['error']];
    $amount = (int)$q['final'];

    $pdo->prepare("INSERT INTO hd_purchases (bot_id, member_id, plan_id, plan_name, amount_toman, original_amount, discount_amount, discount_code_id, messages, daily_limit, days, status, return_url, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)")
        ->execute([(int)$bot['id'], (int)$member['id'], (int)$plan['id'], $plan['name'], $amount, (int)$q['original'], (int)$q['original'] - $amount, (int)$q['code_id'],
                   (int)$plan['messages'], (int)$plan['daily_limit'], (int)$plan['days'], mb_substr($return_url, 0, 480), hd_now()]);
    $pid = (int)$pdo->lastInsertId();
    if (function_exists('cx_plan_bots')) {
        try { $pdo->prepare("UPDATE hd_purchases SET flags=?, shared_bots=?, mobile=?, owner_id=? WHERE id=?")->execute([(string)($plan['flags'] ?? 'files,voice'), cx_ids_str(cx_plan_bots($plan)), (string)$member['mobile'], (int)$bot['user_id'], $pid]); } catch (\Throwable $e) {}
        try { $pdo->prepare("UPDATE hd_purchases SET win_msgs=? WHERE id=?")->execute([(int)($plan['win_msgs'] ?? 0), $pid]); } catch (\Throwable $e) {}
        if (function_exists('lim_ensure_schema')) { lim_ensure_schema($pdo); try { $pdo->prepare("UPDATE hd_purchases SET week_limit=?, month_limit=? WHERE id=?")->execute([(int)($plan['week_limit'] ?? 0), (int)($plan['month_limit'] ?? 0), $pid]); } catch (\Throwable $e) {} }
    }
    try { $pdo->prepare("UPDATE hd_purchases SET rem_sms=? WHERE id=?")->execute([(int)($plan['rem_sms'] ?? 0), $pid]); } catch (\Throwable $e) {}

    // تخفیف ۱۰۰٪: بدون درگاه فعال می‌شود
    if ($amount <= 0) {
        $st2 = $pdo->prepare("SELECT * FROM hd_purchases WHERE id=?");
        $st2->execute([$pid]);
        hd_activate_purchase($pdo, $bot, $st2->fetch(\PDO::FETCH_ASSOC), 'FREE');
        return ['ok' => true, 'free' => true, 'message' => 'بسته «' . $plan['name'] . '» با کد تخفیف به‌صورت رایگان فعال شد.', 'quota' => hd_quota($pdo, $bot, $member)];
    }

    $base = hd_zp_base($bot, 'api');
    $callback = $return_url . (strpos($return_url, '?') === false ? '?' : '&') . 'pid=' . $pid;
    $r = hd_zp_post($base . '/pg/v4/payment/request.json', function_exists('comm_zp_payload')
        ? comm_zp_payload($bot['zp_merchant'], $amount * 10, $bot['name'] . ' — ' . $plan['name'], $callback, (string)$member['mobile'])
        : ['merchant_id' => trim($bot['zp_merchant']), 'amount' => $amount * 10, 'description' => mb_substr($bot['name'] . ' — ' . $plan['name'], 0, 250), 'callback_url' => $callback]);
    if (empty($r['data']['authority'])) {
        $pdo->prepare("UPDATE hd_purchases SET status='failed' WHERE id=?")->execute([$pid]);
        error_log('[HD] zarinpal request failed bot=' . $bot['id'] . ' ' . json_encode($r['errors'] ?? $r));
        // اطلاع به صاحب ربات با علت خطا (روزی یک بار)
        if (function_exists('biz_notify_once') && function_exists('comm_zp_error') && isset($r['errors']['code'])) {
            biz_notify_once($pdo, (int)$bot['user_id'], 'zp_err_' . (int)$bot['id'], date('Y-m-d'), '💳 خطای درگاه پرداخت «' . $bot['name'] . '»',
                'خرید بسته مخاطبان انجام نشد (کد ' . (int)$r['errors']['code'] . '): ' . comm_zp_error((int)$r['errors']['code']), 'danger', 'bot.php?id=' . (int)$bot['id'] . '&tab=sales');
        }
        return ['ok' => false, 'error' => 'اتصال به درگاه پرداخت ممکن نشد. لطفاً بعداً تلاش کنید.'];
    }
    $auth = (string)$r['data']['authority'];
    $pdo->prepare("UPDATE hd_purchases SET authority=? WHERE id=?")->execute([$auth, $pid]);
    $start = hd_zp_base($bot, 'www') . '/pg/StartPay/';
    return ['ok' => true, 'pay_url' => $start . $auth];
}

/** تأیید پرداخت پس از بازگشت از درگاه */
function hd_pay_verify($pdo, $bot, $pid, $authority, $status)
{
    $st = $pdo->prepare("SELECT * FROM hd_purchases WHERE id=? AND bot_id=?");
    $st->execute([(int)$pid, (int)$bot['id']]);
    $p = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$p) return ['ok' => false, 'error' => 'پرداخت یافت نشد.'];
    if ($p['status'] === 'paid') return ['ok' => true, 'paid' => true, 'message' => 'این پرداخت قبلاً تأیید شده است.'];
    if ($p['status'] !== 'pending' || $authority === '' || !hash_equals((string)$p['authority'], (string)$authority)) {
        return ['ok' => false, 'error' => 'اطلاعات پرداخت نامعتبر است.'];
    }
    if ($status !== 'OK') {
        $pdo->prepare("UPDATE hd_purchases SET status='failed' WHERE id=?")->execute([(int)$p['id']]);
        return ['ok' => true, 'paid' => false, 'message' => 'پرداخت لغو شد یا ناموفق بود.'];
    }
    $base = hd_zp_base($bot, 'api');
    $r = hd_zp_post($base . '/pg/v4/payment/verify.json', [
        'merchant_id' => function_exists('comm_zp_clean_merchant') ? comm_zp_clean_merchant($bot['zp_merchant']) : trim($bot['zp_merchant']),
        'amount'      => (int)$p['amount_toman'] * 10,
        'authority'   => $authority,
    ]);
    $code = $r['data']['code'] ?? ($r['errors']['code'] ?? -1);
    if ($code == 100 || $code == 101) {
        hd_activate_purchase($pdo, $bot, $p, (string)($r['data']['ref_id'] ?? ''));
        return ['ok' => true, 'paid' => true, 'message' => 'پرداخت موفق بود. بسته «' . $p['plan_name'] . '» فعال شد. کد پیگیری: ' . ($r['data']['ref_id'] ?? '')];
    }
    $pdo->prepare("UPDATE hd_purchases SET status='failed' WHERE id=?")->execute([(int)$p['id']]);
    return ['ok' => true, 'paid' => false, 'message' => 'تأیید پرداخت ناموفق بود. اگر مبلغی کسر شده، ظرف ۷۲ ساعت برگشت داده می‌شود.'];
}

// =============================================================================
// پیامک (پنل پیامک خود صاحب ربات؛ در غیر این صورت پیامک پلتفرم)
// =============================================================================
function hd_sms_ready($pdo, $bot)
{
    if (trim((string)($bot['sms_api_key'] ?? '')) !== '') return true;
    // پیامک سامانه: فقط اگر صاحب ربات اعتبار پیامک (رایگان پلن یا خریداری‌شده) داشته باشد
    if (function_exists('biz_user_can_sms')) return biz_user_can_sms($pdo, (int)$bot['user_id']);
    $cfg = saas_get_api_config($pdo);
    return !empty($cfg['sms_enabled']) && !empty($cfg['sms_api_key']);
}

/** @return bool ارسال شد یا نه */
function hd_send_sms($pdo, $bot, $mobile, $text, $kind = 'otp', $tpl = '', array $vars = [])
{
    if (trim((string)($bot['sms_api_key'] ?? '')) !== '') {
        // پنل پیامک خود صاحب ربات (بدون کسر از اعتبار پیامک؛ همیشه متنی، با متن الگوی تعریف‌شده)
        $cfg = ['sms_provider' => 'kavenegar', 'sms_api_key' => trim($bot['sms_api_key']), 'sms_sender' => trim((string)$bot['sms_sender'])];
        if ($tpl !== '' && function_exists('comm_sms_send')) {
            $r = comm_sms_send($pdo, $mobile, $tpl, $vars, ['cfg' => $cfg, 'kind' => $kind, 'user_id' => (int)$bot['user_id'], 'bot_id' => (int)$bot['id'], 'source' => 'own']);
            return $r['ok'];
        }
        $ok = saas_send_sms($cfg, $mobile, $text);
        if (function_exists('comm_sms_log')) comm_sms_log($pdo, (int)$bot['user_id'], (int)$bot['id'], (string)$mobile, $kind, 'own', (bool)$ok);
        return (bool)$ok;
    }
    if (function_exists('biz_sms_for_user')) return biz_sms_for_user($pdo, (int)$bot['user_id'], $mobile, $text, $kind, (int)$bot['id'], $tpl, $vars);
    return (bool)saas_send_sms(saas_get_api_config($pdo), $mobile, $text);
}

// =============================================================================
// تخفیف بسته‌های مخاطبان
// =============================================================================
function hd_plan_price($plan)
{
    $orig = (int)($plan['price_toman'] ?? 0);
    $pct = (int)($plan['discount_percent'] ?? 0);
    $until = $plan['discount_until'] ?? null;
    $active = $until === null || $until === '' || strtotime((string)$until) > time();
    if ($orig > 0 && $pct > 0 && $pct < 100 && $active) {
        $final = max(1000, (int)(round($orig * (100 - $pct) / 100 / 100) * 100));
        return ['price' => $final, 'original' => $orig, 'percent' => $pct, 'until' => $until ?: null];
    }
    return ['price' => $orig, 'original' => $orig, 'percent' => 0, 'until' => null];
}

/** @return array ['ok','error','row','off'] */
function hd_check_code($pdo, $bot, $member_id, $plan, $code, $amount)
{
    $code = strtoupper(preg_replace('/\s+/', '', (string)$code));
    if ($code === '') return ['ok' => false, 'error' => 'کد تخفیف را وارد کنید.'];
    $s = $pdo->prepare("SELECT * FROM hd_discount_codes WHERE bot_id=? AND code=? LIMIT 1");
    $s->execute([(int)$bot['id'], $code]);
    $c = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$c || empty($c['is_active'])) return ['ok' => false, 'error' => 'کد تخفیف معتبر نیست.'];
    if (!empty($c['ends_at']) && strtotime($c['ends_at']) < time()) return ['ok' => false, 'error' => 'مهلت این کد تخفیف تمام شده است.'];
    if ((int)$c['plan_id'] > 0 && (int)$c['plan_id'] !== (int)$plan['id']) return ['ok' => false, 'error' => 'این کد برای بسته دیگری است.'];
    if ((int)$c['max_uses'] > 0 && (int)$c['used_count'] >= (int)$c['max_uses']) return ['ok' => false, 'error' => 'ظرفیت این کد تکمیل شده است.'];
    if ((int)$c['per_member_limit'] > 0 && $member_id) {
        $u = $pdo->prepare("SELECT COUNT(*) FROM hd_purchases WHERE bot_id=? AND member_id=? AND discount_code_id=? AND status='paid'");
        $u->execute([(int)$bot['id'], (int)$member_id, (int)$c['id']]);
        if ((int)$u->fetchColumn() >= (int)$c['per_member_limit']) return ['ok' => false, 'error' => 'شما قبلاً از این کد استفاده کرده‌اید.'];
    }
    $off = $c['kind'] === 'fixed' ? (int)$c['value'] : (int)floor($amount * min(100, (int)$c['value']) / 100);
    return ['ok' => true, 'error' => '', 'row' => $c, 'off' => max(0, min($amount, $off))];
}

/** قیمت نهایی یک بسته برای مخاطب (تخفیف خودکار + کد) */
function hd_quote($pdo, $bot, $member_id, $plan, $code = '')
{
    $pp = hd_plan_price($plan);
    $q = ['original' => $pp['original'], 'price' => $pp['price'], 'percent' => $pp['percent'], 'code_off' => 0, 'code_id' => 0, 'error' => '', 'final' => $pp['price']];
    if (trim((string)$code) !== '') {
        $c = hd_check_code($pdo, $bot, $member_id, $plan, $code, $pp['price']);
        if ($c['ok']) { $q['code_off'] = $c['off']; $q['code_id'] = (int)$c['row']['id']; $q['final'] = $pp['price'] - $c['off']; }
        else $q['error'] = $c['error'];
    }
    if ($q['final'] > 0 && $q['final'] < 1000) $q['final'] = 1000;   // حداقل مبلغ درگاه
    return $q;
}

/** فعال‌سازی بسته پرداخت‌شده + ثبت کد تخفیف + اعلان به صاحب ربات */
function hd_activate_purchase($pdo, $bot, $p, $ref_code)
{
    $exp = (int)$p['days'] > 0 ? hd_now((int)$p['days'] * 86400) : null;
    $u = $pdo->prepare("UPDATE hd_purchases SET status='paid', ref_code=?, paid_at=?, expires_at=? WHERE id=? AND status='pending'");
    $u->execute([(string)$ref_code, hd_now(), $exp, (int)$p['id']]);
    if ($u->rowCount() === 0) return false;
    if (function_exists('mcr_on_activate')) mcr_on_activate($pdo, $p);   // بسته شارژ تومانی (نسخه ۶۲)
    if ((int)($p['discount_code_id'] ?? 0) > 0) {
        $pdo->prepare("UPDATE hd_discount_codes SET used_count = used_count + 1 WHERE id=?")->execute([(int)$p['discount_code_id']]);
    }
    if (function_exists('biz_notify')) {
        $m = $pdo->prepare("SELECT name, mobile FROM hd_members WHERE id=?");
        $m->execute([(int)$p['member_id']]);
        $mem = $m->fetch(\PDO::FETCH_ASSOC) ?: ['name' => '', 'mobile' => ''];
        biz_notify($pdo, (int)$bot['user_id'], '💰 فروش جدید در «' . $bot['name'] . '»',
            ($mem['name'] ?: 'مخاطب') . ' بسته «' . $p['plan_name'] . '» را به مبلغ ' . number_format((int)$p['amount_toman']) . ' تومان خرید.', 'success',
            'bot.php?id=' . (int)$bot['id'] . '&tab=sales');
    }
    return true;
}

// =============================================================================
// فاز ۳ — حالت دقت بالا (بازبینی پاسخ با منابع)
// =============================================================================
function hd_verify_answer($cfg, $system_with_sources, $question, $draft, $max_out, $recent = '')
{
    // اطلاعات مجاز برای پاسخ: پرونده مراجع، یادداشت‌های مشاور، گفتگوهای قبلی، فایل‌ها، پاسخ‌های تأییدشده و منابع
    $p = false;
    foreach (['=== پرونده مراجع', '=== یادداشت‌ها و نتایج جلسات', '=== گفتگوهای قبلی همین مراجع', '=== خلاصه کامل بخش‌های قبلی', '=== متن فایل‌هایی', '=== پاسخ‌های تأییدشده', '=== منابع ==='] as $mk) {
        $x = strpos($system_with_sources, $mk);
        if ($x !== false && ($p === false || $x < $p)) $p = $x;
    }
    $sources = $p !== false ? substr($system_with_sources, $p) : '';
    if (trim((string)$recent) !== '') $sources .= "\n\n=== گفتگوی جاری با مراجع (اطلاعاتی که خود مراجع گفته معتبر است) ===\n" . $recent;
    $msgs = [
        ['role' => 'system', 'content' => "تو ویراستار دقت هستی. یک «پیش‌نویس پاسخ» و «اطلاعات معتبر» (پرونده مراجع، یادداشت‌های مشاور انسانی، گفتگوها، فایل‌ها، پاسخ‌های تأییدشده و منابع) به تو داده می‌شود.\n"
            . "وظیفه: هر جمله پیش‌نویس را با این اطلاعات مقایسه کن. جمله‌هایی که هیچ پشتوانه‌ای در آن‌ها ندارند یا با آن‌ها تناقض دارند را حذف یا اصلاح کن.\n"
            . "مطالبی که از پرونده مراجع یا یادداشت‌های مشاور آمده کاملاً معتبرند؛ آن‌ها را حذف نکن. سوال‌های شناخت، احوال‌پرسی و راهنمایی عمومی کوتاه را هم نگه دار.\n"
            . "شماره‌های ارجاع [n] را حفظ و در صورت نیاز اصلاح کن. سبک، لحن و قالب‌بندی را حفظ کن. چیزی از خودت اضافه نکن.\n"
            . "اگر بعد از حذف چیزی باقی نماند، بنویس: «در منابع معتبر من پاسخ دقیقی برای این سوال پیدا نکردم.»\n"
            . "فقط متن نهایی پاسخ را بنویس؛ هیچ توضیح، مقدمه یا یادداشت درباره ویرایش ننویس.\n\n" . $sources],
        ['role' => 'user', 'content' => "سوال کاربر:\n{$question}\n\nپیش‌نویس پاسخ:\n{$draft}"],
    ];
    $r = aichat_call_ai($cfg, $msgs, $max_out, 0.0);
    if (empty($r['ok']) || trim((string)$r['content']) === '') return ['ok' => false];
    return ['ok' => true, 'content' => trim($r['content']), 'tokens' => (int)($r['tokens'] ?? 0), 'prompt_tokens' => (int)($r['prompt_tokens'] ?? 0), 'completion_tokens' => (int)($r['completion_tokens'] ?? 0)];
}

// =============================================================================
// فاز ۳ — استخراج متن از فایل‌های PDF و Word (docx)
// =============================================================================
function hd_extract_docx($path)
{
    if (!class_exists('ZipArchive')) return '';
    $z = new \ZipArchive();
    if ($z->open($path) !== true) return '';
    $xml = (string)$z->getFromName('word/document.xml');
    $z->close();
    if ($xml === '') return '';
    $xml = preg_replace('#<w:tab[^>]*/>#', "\t", $xml);
    $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);
    $xml = preg_replace('#</w:p>#', "\n", $xml);
    $txt = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/u', ' ', $txt)));
}

/** تبدیل کد به کاراکتر UTF-8 */
function hd_cp_utf8($cp)
{
    return mb_convert_encoding(pack('N', $cp), 'UTF-8', 'UTF-32BE');
}

/** خواندن نقشه ToUnicode فونت‌های PDF */
function hd_pdf_parse_cmap($data)
{
    $map = [];
    $bytes = 1;
    if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $data, $m)) $bytes = max(1, (int)(strlen($m[1]) / 2));
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $data, $blocks)) {
        foreach ($blocks[1] as $b) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $b, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $pr) $map[hexdec($pr[1])] = mb_convert_encoding(hex2bin(strlen($pr[2]) % 2 ? '0' . $pr[2] : $pr[2]), 'UTF-8', 'UTF-16BE');
            }
        }
    }
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $data, $blocks)) {
        foreach ($blocks[1] as $b) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $b, $rs, PREG_SET_ORDER)) {
                foreach ($rs as $r) {
                    $lo = hexdec($r[1]); $hi = hexdec($r[2]);
                    if ($hi - $lo > 5000) continue;
                    if ($r[3][0] === '<') {
                        $start = hexdec(trim($r[3], '<>'));
                        for ($c = $lo; $c <= $hi; $c++) $map[$c] = hd_cp_utf8($start + ($c - $lo));
                    } elseif (preg_match_all('/<([0-9A-Fa-f]+)>/', $r[3], $arr)) {
                        foreach ($arr[1] as $k => $h) $map[$lo + $k] = mb_convert_encoding(hex2bin(strlen($h) % 2 ? '0' . $h : $h), 'UTF-8', 'UTF-16BE');
                    }
                }
            }
        }
    }
    return ['map' => $map, 'bytes' => $bytes];
}

function hd_pdf_unescape($s)
{
    $out = ''; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c !== '\\') { $out .= $c; continue; }
        $i++; if ($i >= $n) break;
        $d = $s[$i];
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($map[$d])) { $out .= $map[$d]; continue; }
        if (ctype_digit($d)) { $o = $d; for ($k = 0; $k < 2 && $i + 1 < $n && ctype_digit($s[$i + 1]); $k++) $o .= $s[++$i]; $out .= chr(octdec($o) & 255); continue; }
        if ($d === "\r" || $d === "\n") continue;
        $out .= $d;
    }
    return $out;
}

function hd_pdf_decode_bytes($bin, $cm)
{
    if (!$cm) {
        // بدون نقشه: احتمالاً متن لاتین/UTF-16
        if (substr($bin, 0, 2) === "\xFE\xFF") return mb_convert_encoding(substr($bin, 2), 'UTF-8', 'UTF-16BE');
        return mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1');
    }
    $out = ''; $b = $cm['bytes'];
    for ($i = 0; $i + $b <= strlen($bin); $i += $b) {
        $code = $b === 2 ? (ord($bin[$i]) << 8) | ord($bin[$i + 1]) : ord($bin[$i]);
        $u = $cm['map'][$code] ?? '';
        // لیگاتورها (مثل «لا») به‌صورت یک واحد علامت‌گذاری می‌شوند تا در اصلاح راست‌به‌چپ به‌هم نریزند
        $out .= (function_exists('mb_strlen') && mb_strlen($u, 'UTF-8') > 1) ? "\u{E000}" . $u . "\u{E001}" : $u;
    }
    return $out;
}

function hd_ascii85_decode($in)
{
    $in = preg_replace('/\s+/', '', $in);
    if (substr($in, 0, 2) === '<~') $in = substr($in, 2);
    $p = strpos($in, '~>');
    if ($p !== false) $in = substr($in, 0, $p);
    $out = ''; $tuple = []; $n = strlen($in);
    for ($i = 0; $i < $n; $i++) {
        $c = $in[$i];
        if ($c === 'z' && !$tuple) { $out .= "\0\0\0\0"; continue; }
        $o = ord($c) - 33;
        if ($o < 0 || $o > 84) continue;
        $tuple[] = $o;
        if (count($tuple) === 5) {
            $v = 0; foreach ($tuple as $t) $v = $v * 85 + $t;
            $out .= pack('N', $v & 0xFFFFFFFF); $tuple = [];
        }
    }
    if ($tuple) {
        $k = count($tuple);
        while (count($tuple) < 5) $tuple[] = 84;
        $v = 0; foreach ($tuple as $t) $v = $v * 85 + $t;
        $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $k - 1);
    }
    return $out;
}

/** معکوس‌کردن یک خط فارسی ذخیره‌شده به ترتیب دیداری (اعداد و کلمات لاتین و لیگاتورها سالم می‌مانند) */
function hd_pdf_reverse_line($line)
{
    if (!preg_match('/[\x{0600}-\x{06FF}]/u', $line)) return $line;
    preg_match_all('/\x{E000}[^\x{E001}]*\x{E001}|[0-9۰-۹٠-٩A-Za-z]+(?:[.,:\/%\-][0-9۰-۹٠-٩A-Za-z]+)*|./us', $line, $m);
    $units = array_reverse($m[0]);
    $map = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '«' => '»', '»' => '«', '<' => '>', '>' => '<'];
    foreach ($units as &$u) if (isset($map[$u])) $u = $map[$u];
    return implode('', $units);
}

/** تشخیص متن فارسی دیداری (معکوس) در PDF و اصلاح آن */
function hd_pdf_fix_rtl($text)
{
    $common = ['و', 'در', 'به', 'از', 'که', 'این', 'است', 'را', 'با', 'برای', 'یک', 'آن', 'تا', 'هم', 'شود', 'می'];
    $score = function ($t) use ($common) {
        $n = 0;
        foreach (preg_split('/[\s\x{200C}.,،:؛!؟?()]+/u', preg_replace('/[\x{E000}\x{E001}]/u', '', $t)) as $w) if (in_array($w, $common, true)) $n++;
        return $n;
    };
    $rev = implode("\n", array_map('hd_pdf_reverse_line', explode("\n", $text)));
    $out = $score($rev) > $score($text) ? $rev : $text;
    return preg_replace('/[\x{E000}\x{E001}]/u', '', $out);
}

/** استخراج متن PDF: خواننده داخلی + ابزار pdftotext (در صورت وجود) — هر کدام نتیجه بهتری داد */
function hd_extract_pdf($path)
{
    $internal = '';
    try { $internal = hd_extract_pdf_internal($path); } catch (\Throwable $e) { $internal = ''; }
    $ext = '';
    $can_exec = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    if ($can_exec) {
        $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin !== '') {
            $ext = (string)@shell_exec(escapeshellcmd($bin) . ' -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
            $ext = trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\f]/u', '', $ext));
        }
    }
    $len = function ($t) { return (int)preg_match_all('/[\p{L}\p{N}]/u', $t); };
    // خواننده داخلی ترتیب فارسی را بهتر حفظ می‌کند؛ اگر متن کافی نداد، از pdftotext استفاده می‌شود
    if ($len($internal) >= 0.6 * $len($ext) && $len($internal) > 20) return $internal;
    return $ext !== '' ? $ext : $internal;
}

/** خواننده داخلی PDF (بدون نیاز به ابزار خارجی) */
function hd_extract_pdf_internal($path)
{
    $raw = (string)@file_get_contents($path);
    if ($raw === '' || strpos($raw, '%PDF') === false) return '';
    // اشیاء PDF
    $objs = [];
    if (preg_match_all('/(\d+)\s+(\d+)\s+obj(.*?)endobj/s', $raw, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) $objs[(int)$m[1]] = $m[3];
    }
    $stream = function ($body) {
        if (!preg_match('/stream\r?\n(.*)endstream/s', $body, $sm)) return null;
        $data = rtrim($sm[1], "\r\n");
        $filters = [];
        if (preg_match('#/Filter\s*\[([^\]]*)\]#', $body, $fm)) { preg_match_all('#/(\w+)#', $fm[1], $fl); $filters = $fl[1]; }
        elseif (preg_match('#/Filter\s*/(\w+)#', $body, $fm)) $filters = [$fm[1]];
        foreach ($filters as $f) {
            if ($f === 'ASCII85Decode' || $f === 'A85') {
                $data = hd_ascii85_decode($data);
            } elseif ($f === 'FlateDecode' || $f === 'Fl') {
                $d = @gzuncompress($data);
                if ($d === false) $d = @gzinflate($data);
                if ($d === false) $d = @gzinflate(substr($data, 2));
                if ($d === false) return null;
                $data = $d;
            } elseif ($f === 'ASCIIHexDecode' || $f === 'AHx') {
                $h = preg_replace('/[^0-9A-Fa-f]/', '', $data);
                if (strlen($h) % 2) $h .= '0';
                $data = (string)hex2bin($h);
            } else {
                return null;   // فیلترهای تصویری و غیره
            }
            if ($data === '') return null;
        }
        return $data;
    };
    // اشیاء فشرده داخل Object Stream (PDFهای جدید، مثلاً خروجی Word)
    foreach ($objs as $body) {
        if (!preg_match('#/Type\s*/ObjStm#', $body)) continue;
        if (!preg_match('#/First\s+(\d+)#', $body, $fm) || !preg_match('#/N\s+(\d+)#', $body, $nm)) continue;
        $d = $stream($body);
        if (!$d) continue;
        $head = preg_split('/\s+/', trim(substr($d, 0, (int)$fm[1])));
        $pairs = [];
        for ($i = 0; $i + 1 < count($head) && count($pairs) < (int)$nm[1]; $i += 2) $pairs[] = [(int)$head[$i], (int)$head[$i + 1]];
        foreach ($pairs as $k => $pr) {
            $start = (int)$fm[1] + $pr[1];
            $end = isset($pairs[$k + 1]) ? (int)$fm[1] + $pairs[$k + 1][1] : strlen($d);
            if (!isset($objs[$pr[0]])) $objs[$pr[0]] = substr($d, $start, $end - $start);
        }
    }
    // فونت‌ها → ToUnicode
    $font_cmaps = [];   // obj id فونت → cmap
    foreach ($objs as $id => $body) {
        if (preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $body, $tm) && isset($objs[(int)$tm[1]])) {
            $cd = $stream($objs[(int)$tm[1]]);
            if ($cd) $font_cmaps[$id] = hd_pdf_parse_cmap($cd);
        }
    }
    // نام فونت‌ها در منابع صفحه (/F1 12 0 R)
    $name_to_cmap = [];
    foreach ($objs as $body) {
        if (preg_match_all('#/([A-Za-z0-9_.+-]+)\s+(\d+)\s+0\s+R#', $body, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $f) if (isset($font_cmaps[(int)$f[2]])) $name_to_cmap[$f[1]] = $font_cmaps[(int)$f[2]];
        }
    }
    $text = '';
    foreach ($objs as $body) {
        if (strpos($body, 'stream') === false || preg_match('#/(Subtype\s*/Image|FontFile|Length1|CMapName)#', $body)) continue;
        $c = $stream($body);
        if (!$c || (strpos($c, 'Tj') === false && strpos($c, 'TJ') === false)) continue;
        $cm = null; $line = '';
        if (!preg_match_all('#/([A-Za-z0-9_.+-]+)\s+[\d.]+\s+Tf|\[((?:[^\]\\\\]|\\\\.)*)\]\s*TJ|\(((?:[^()\\\\]|\\\\.)*)\)\s*(?:Tj|\'|")|<([0-9A-Fa-f\s]+)>\s*Tj|(T\*|Td|TD|ET|Tm)#', $c, $ops, PREG_SET_ORDER)) continue;
        foreach ($ops as $op) {
            if (!empty($op[1])) { $cm = $name_to_cmap[$op[1]] ?? null; continue; }
            if (isset($op[5]) && $op[5] !== '') { if (trim($line) !== '') { $text .= trim($line) . "\n"; } $line = ''; continue; }
            if (isset($op[2]) && $op[2] !== '') {
                if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9A-Fa-f\s]+)>|(-?\d+(?:\.\d+)?)/', $op[2], $parts, PREG_SET_ORDER)) {
                    foreach ($parts as $pt) {
                        if (isset($pt[3]) && $pt[3] !== '') { if ((float)$pt[3] < -180) $line .= ' '; continue; }
                        $bin = (isset($pt[2]) && $pt[2] !== '') ? hex2bin(preg_replace('/\s+/', '', strlen(preg_replace('/\s+/', '', $pt[2])) % 2 ? $pt[2] . '0' : $pt[2])) : hd_pdf_unescape($pt[1]);
                        $line .= hd_pdf_decode_bytes($bin, $cm);
                    }
                }
                continue;
            }
            if (isset($op[3]) && $op[3] !== '') { $line .= hd_pdf_decode_bytes(hd_pdf_unescape($op[3]), $cm); continue; }
            if (isset($op[4]) && $op[4] !== '') { $h = preg_replace('/\s+/', '', $op[4]); if (strlen($h) % 2) $h .= '0'; $line .= hd_pdf_decode_bytes(hex2bin($h), $cm); }
        }
        if (trim($line) !== '') $text .= trim($line) . "\n";
    }
    $text = hd_pdf_fix_rtl($text);
    $text = preg_replace('/\n(\S{1,4})(?=\n|$)/u', ' $1', $text);   // اتصال تکه‌های خیلی کوتاه (نقطه، عدد) به خط قبل
    $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text);
    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}

/** متن فایل آپلودی (txt / pdf / docx) */
function hd_extract_file($tmp_path, $name)
{
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if ($ext === 'txt') {
        $t = (string)file_get_contents($tmp_path);
        if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1256');
        return trim($t);
    }
    if ($ext === 'docx') return hd_extract_docx($tmp_path);
    if ($ext === 'pdf')  return hd_extract_pdf($tmp_path);
    return '';
}

// =============================================================================
// بازخورد مخاطب (👍/👎)
// =============================================================================
function hd_rate($pdo, $bot_id, $member_id, $message_id, $value)
{
    $v = (int)$value; $v = $v > 0 ? 1 : ($v < 0 ? -1 : 0);
    $pdo->prepare("UPDATE hd_messages SET rating=? WHERE id=? AND bot_id=? AND member_id=? AND role='assistant'")
        ->execute([$v, (int)$message_id, (int)$bot_id, (int)$member_id]);
    return true;
}

// =============================================================================
// پردازش درخواست‌های «فایل رابط» (مشترک بین API و حالت دامنه اختصاصی)
// =============================================================================
function hd_api_dispatch($pdo, $bot, $in)
{
    $action = (string)($in['action'] ?? '');
    $token  = (string)($in['token'] ?? '');
    // IP واقعی مخاطب (فرستاده‌شده توسط فایل رابط) برای محافظ ورود
    if (function_exists('ipg_set_client') && !array_key_exists('ipg_client_ip', $GLOBALS)) ipg_set_client($in['client_ip'] ?? '');

    // صفحه یکپارچه: درخواست برای چت‌بات دیگرِ همان صاحب (via)
    if (!empty($in['via']) && empty($in['_via_done']) && function_exists('cx_hub_target') && (int)$in['via'] !== (int)$bot['id']) {
        if (in_array($action, ['magic', 'connector_version', 'connector_code', 'g_start', 'g_finish', 'g_register', 'g_link'], true)) return ['ok' => false, 'error' => 'bad request'];
        $target = cx_hub_target($pdo, $bot, (int)$in['via']);
        if (!$target) return ['ok' => false, 'error' => 'این چت‌بات در دسترس نیست.', 'hub_gone' => true];
        $in2 = $in;
        unset($in2['via'], $in2['cv']);
        $in2['_via_done'] = 1;
        $sso = null;
        $pre = ['config', 'avatar', 'exfile', 'galfile', 'plans', 'pay_verify', 'captcha', 'login', 'verify', 'send_code', 'pw_login', 'pw_reset', 'hub'];
        if (!in_array($action, $pre, true) && !hd_member_by_token($pdo, $target['id'], $token) && !empty($in['home_token'])) {
            $sso = cx_hub_sso($pdo, $target, $bot, (string)$in['home_token']);
            if ($sso) $in2['token'] = $sso['token'];
        }
        $r = hd_api_dispatch($pdo, $target, $in2);
        if ($sso && is_array($r) && empty($r['token']) && empty($r['login_required'])) $r['token'] = $sso['token'];
        if ($action === 'config' && is_array($r) && !empty($r['config'])) { $r['config']['google'] = false; $r['config']['hub'] = true; }
        return $r;
    }

    // ورود، ثبت‌نام، رمز، کپچا، گوگل و به‌روزرسانی فایل رابط (includes/hamdam_auth.php)
    if (function_exists('hd_auth_dispatch')) {
        $ar = hd_auth_dispatch($pdo, $bot, $action, $in);
        // گزارش ورود مخاطب (نسخه ۴۷)
        if (is_array($ar) && !empty($ar['token']) && function_exists('act_log') && in_array($action, ['login', 'verify', 'pw_login', 'g_finish', 'g_register', 'set_password'], true)) {
            $lm = hd_member_by_token($pdo, (int)$bot['id'], (string)$ar['token']);
            if ($lm && empty($lm['is_owner'])) act_log($pdo, 'member', (int)$lm['id'], 'login', ['owner' => (int)$bot['user_id'], 'bot' => (int)$bot['id'], 'name' => (string)$lm['name'], 'title' => 'چت‌بات «' . $bot['name'] . '»', 'path' => '', 'ua' => (string)($in['client_ua'] ?? '')]);
        }
        if ($ar !== null) return $ar;
    }

    if ($action === 'config') {
        $reason = '';
        $cfg = hd_public_config($bot, $pdo);
        $cfg['can_buy'] = hd_bot_sells($pdo, $bot);
        $sms_on = function_exists('hd_member_sms_on') ? hd_member_sms_on($pdo, $bot) : hd_sms_ready($pdo, $bot);
        $cfg['verify_mobile'] = !empty($bot['verify_mobile']) && $sms_on;
        $cfg['google'] = function_exists('hd_google_ready') && hd_google_ready($pdo);
        $cfg['sms'] = $sms_on;
        $cfg['login_order'] = function_exists('comm_login_active') ? comm_login_active($pdo, 'member') : ['sms', 'google', 'password'];
        if (function_exists('cx_has_free_plan') && !$cfg['can_buy']) $cfg['can_buy'] = cx_has_free_plan($pdo, $bot);
        $cfg['id'] = (int)$bot['id'];
        $cfg['hub'] = function_exists('cx_hub_bots') && count(cx_hub_bots($pdo, $bot)) > 1;
        if (function_exists('rem_bot_on')) { $cfg['rem'] = rem_bot_on($bot); $vk = $cfg['rem'] ? rem_vapid($pdo) : null; $cfg['vapid'] = $vk['pub'] ?? ''; }
        return ['ok' => true, 'config' => $cfg, 'available' => !empty($bot['is_active']) && hd_owner_ok($pdo, $bot, $reason)];
    }

    // سرویس‌کارگر اعلان: متن اعلان‌های تازه این دستگاه (بدون ورود)
    if ($action === 'push_pull' && function_exists('rem_push_pull')) return rem_push_pull($pdo, (string)(is_array($in['rem'] ?? null) ? ($in['rem']['endpoint'] ?? '') : ''));

    if ($action === 'avatar') {
        $url = (string)$bot['avatar_url'];
        $prefix = rtrim(AICHAT_BASE_URL, '/') . '/uploads/icons/';
        if ($url === '' || strpos($url, $prefix) !== 0) return ['ok' => false, 'error' => 'no avatar'];
        $file = rtrim(AICHAT_UPLOAD_DIR, '/') . '/icons/' . basename(substr($url, strlen($prefix)));
        if (!is_file($file)) return ['ok' => false, 'error' => 'no avatar'];
        $mime = function_exists('mime_content_type') ? mime_content_type($file) : 'image/png';
        return ['ok' => true, 'mime' => $mime, 'data' => base64_encode(file_get_contents($file))];
    }

    if ($action === 'exfile' && function_exists('ex_file_public')) return ex_file_public($bot, (string)($in['value'] ?? ''));
    // تصویر نمونه‌های گالری (از طریق فایل رابط، بدون نمایش آدرس سرویس) — نسخه ۵۱
    if ($action === 'galfile' && function_exists('gal_file_public')) return gal_file_public($pdo, $in);

    if ($action === 'magic') {
        $code = (string)($in['code'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $code)) return ['ok' => false, 'error' => 'لینک نامعتبر است.'];
        $st = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND magic_hash=? AND magic_until > ? LIMIT 1");
        $st->execute([(int)$bot['id'], hash('sha256', $code), hd_now()]);
        $m = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$m) return ['ok' => false, 'error' => 'لینک ورود منقضی شده است؛ دوباره از پنل بسازید.'];
        if (function_exists('sup_sync')) $m = sup_sync($pdo, $bot, $m);
        if (!empty($m['consent_hold'])) return ['ok' => false, 'error' => 'اشتراک این مراجع فعال نیست؛ نظارت مشاور با تهیه اشتراک فعال می‌شود.'];
        if (empty($m['consent_share'])) return ['ok' => false, 'error' => 'این مراجع اجازه دسترسی به پرونده و گفتگوهایش را نداده است.'];
        $tok = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE hd_members SET magic_hash='', magic_until=NULL, imp_hash=?, imp_until=? WHERE id=?")->execute([hash('sha256', $tok), hd_now(2 * 3600), (int)$m['id']]);
        return ['ok' => true, 'token' => $tok];
    }

    // فهرست چت‌بات‌های صفحه یکپارچه (پیش از ورود هم نمایش داده می‌شود)
    if ($action === 'hub' && function_exists('cx_hub_list')) {
        $hm = hd_member_by_token($pdo, $bot['id'], $token);
        return ['ok' => true, 'bots' => cx_hub_list($pdo, $bot, $hm ?: [])];
    }

    if ($action === 'plans' && function_exists('cx_plans_public')) {
        $hm = hd_member_by_token($pdo, $bot['id'], $token);
        return ['ok' => true, 'plans' => cx_plans_public($pdo, $bot, $hm), 'status' => $hm ? cx_member_status($pdo, $bot, $hm) : null,
                // کارت به کارت (نسخه ۵۶): شماره کارت صاحب چت‌بات، درگاه آنلاین، فیش‌های اخیر مخاطب
                'zp' => trim((string)($bot['zp_merchant'] ?? '')) !== '', 'c2c' => function_exists('c2c_public') ? c2c_public($pdo, (int)$bot['id']) : null,
                'receipts' => $hm && function_exists('c2c_member_public') ? c2c_member_public($pdo, $bot, $hm) : []];
    }
    if ($action === 'plans') {
        $out = [];
        foreach (hd_member_plans($pdo, $bot['id']) as $p) {
            if ((int)$p['price_toman'] < 1000) continue;
            $pp = hd_plan_price($p);
            $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'description' => $p['description'], 'price' => $pp['price'], 'original' => $pp['original'],
                      'percent' => $pp['percent'], 'until' => $pp['until'] ? substr((string)$pp['until'], 0, 10) : '',
                      'messages' => (int)$p['messages'], 'days' => (int)$p['days'], 'daily_limit' => (int)$p['daily_limit'],
                      'features' => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($p['features'] ?? ''))), 'strlen'))];
        }
        return ['ok' => true, 'plans' => hd_bot_sells($pdo, $bot) ? $out : []];
    }

    // تأیید پرداخت (بازگشت از درگاه؛ ممکن است کوکی ورود همراه نباشد)
    if ($action === 'pay_verify') {
        return hd_pay_verify($pdo, $bot, (int)($in['pid'] ?? 0), (string)($in['authority'] ?? ''), (string)($in['status'] ?? ''));
    }

    // --- نیاز به ورود ---
    $member = hd_member_by_token($pdo, $bot['id'], $token);
    if (!$member) return ['ok' => false, 'error' => 'login_required', 'login_required' => true];
    if ($member['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی شما محدود شده است.', 'login_required' => true];
    // ورود مشاور/صاحب ربات به‌جای مراجع فقط تا وقتی مراجع اجازه دسترسی داده باشد
    // نظارت مشاور فقط برای مخاطبان دارای اشتراک (includes/sup_lib.php)
    if (function_exists('sup_sync')) $member = sup_sync($pdo, $bot, $member);
    // آدرس صفحه چت روی سایت صاحب چت‌بات (برای لینک تمدید/خرید در پیامک‌ها) — فقط اگر قبلاً ثبت نشده
    if ($action === 'me' && empty($member['_imp']) && isset($in['chat_url']) && (string)($bot['chat_url'] ?? '') === '' && (string)($bot['custom_domain'] ?? '') === '') {
        $cu = trim((string)$in['chat_url']);
        $ch = strtolower((string)parse_url($cu, PHP_URL_HOST));
        $ph = defined('AICHAT_BASE_URL') ? strtolower((string)parse_url(AICHAT_BASE_URL, PHP_URL_HOST)) : '';
        if (strlen($cu) <= 300 && preg_match('#^https?://[a-z0-9.\-]+(:\d+)?(/[^\s"\'<>]*)?$#i', $cu) && $ch !== '' && $ch !== $ph && $ch !== 'localhost' && $ch !== '127.0.0.1') {
            try { $pdo->prepare("UPDATE hd_bots SET chat_url=? WHERE id=? AND chat_url=''")->execute([$cu, (int)$bot['id']]); } catch (\Throwable $e) {}
        }
    }
    if (!empty($member['_imp']) && empty($member['consent_share'])) {
        $pdo->prepare("UPDATE hd_members SET imp_hash='', imp_until=NULL WHERE id=?")->execute([(int)$member['id']]);
        return ['ok' => false, 'error' => !empty($member['consent_hold']) ? 'اشتراک مراجع فعال نیست؛ نظارت مشاور با تهیه اشتراک فعال می‌شود.' : 'مراجع اجازه دسترسی را لغو کرده است.', 'login_required' => true];
    }
    if (function_exists('hd_auth_member_dispatch')) {
        $ar = hd_auth_member_dispatch($pdo, $bot, $member, $action, $in);
        if ($ar !== null) return $ar;
    }
    // پشتیبانی، تیکت، راهنما، تبلیغ و پاپ‌آپ (includes/extras_lib.php)
    if (function_exists('ex_member_dispatch')) {
        $ar = ex_member_dispatch($pdo, $bot, $member, $action, $in);
        if ($ar !== null) return $ar;
    }

    // یادآورهای مخاطب (includes/rem_lib.php)
    if (function_exists('rem_member_dispatch') && ($rr = rem_member_dispatch($pdo, $bot, $member, $action, $in)) !== null) return $rr;

    switch ($action) {
        case 'me':
            if (function_exists('act_log') && empty($member['_imp']) && empty($member['_panel']) && empty($member['is_owner']))
                act_log($pdo, 'member', (int)$member['id'], 'chat', ['owner' => (int)$bot['user_id'], 'bot' => (int)$bot['id'], 'name' => (string)$member['name'], 'title' => 'باز کردن چت «' . $bot['name'] . '»', 'path' => '', 'ua' => (string)($in['client_ua'] ?? '')]);
            $q = hd_quota($pdo, $bot, $member);
            return ['ok' => true, 'name' => $member['name'], 'quota' => $q, 'ext' => hd_member_public($pdo, $bot, $member, $q)];
        case 'logout':
            if (!empty($member['_imp'])) $pdo->prepare("UPDATE hd_members SET imp_hash='', imp_until=NULL WHERE id=?")->execute([(int)$member['id']]);   // فقط ورود موقت مشاور بسته می‌شود
            else $pdo->prepare("UPDATE hd_members SET token_hash=? WHERE id=?")->execute([str_repeat('0', 64), (int)$member['id']]);
            return ['ok' => true];
        case 'threads':
            if (function_exists('cx_threads')) return ['ok' => true, 'threads' => cx_threads($pdo, $bot['id'], $member['id']), 'folders' => cx_member_folders($pdo, $bot['id'], $member)];
            return ['ok' => true, 'threads' => hd_threads($pdo, $bot['id'], $member['id'])];
        case 'thread':
            $t = hd_get_thread($pdo, (int)($in['thread_id'] ?? 0), $bot['id'], $member['id']);
            if (!$t) return ['ok' => false, 'error' => 'گفتگو یافت نشد.'];
            return ['ok' => true, 'thread' => ['id' => (int)$t['id'], 'title' => $t['title'], 'folder' => (string)($t['folder'] ?? '')],
                    'messages' => function_exists('cx_thread_messages') ? cx_thread_messages($pdo, $t['id'], $bot['id'], $member['id'], empty($member['_imp'])) : hd_thread_messages($pdo, $t['id']),
                    'imp' => !empty($member['_imp'])];
        case 'thread_delete':
            if (function_exists('cx_media_delete_for_messages')) {
                $tq = hd_get_thread($pdo, (int)($in['thread_id'] ?? 0), $bot['id'], $member['id']);
                if ($tq) { $mi = $pdo->prepare("SELECT id FROM hd_messages WHERE thread_id=?"); $mi->execute([(int)$tq['id']]); cx_media_delete_for_messages($pdo, $bot['id'], $member['id'], $mi->fetchAll(\PDO::FETCH_COLUMN) ?: []); }
            }
            hd_delete_thread($pdo, (int)($in['thread_id'] ?? 0), $bot['id'], $member['id']);
            return ['ok' => true];
        // --- پوشه‌های آرشیو گفتگوها ---
        case 'thread_move':
            $t = hd_get_thread($pdo, (int)($in['thread_id'] ?? 0), $bot['id'], $member['id']);
            if (!$t) return ['ok' => false, 'error' => 'گفتگو یافت نشد.'];
            $fd = cx_folder_clean($in['value'] ?? '');
            $pdo->prepare("UPDATE hd_threads SET folder=? WHERE id=?")->execute([$fd, (int)$t['id']]);
            if ($fd !== '' && !in_array($fd, cx_folders($member), true)) cx_folders_save($pdo, $member['id'], array_merge(cx_folders($member), [$fd]));
            return ['ok' => true];
        case 'folder_add':
            $fd = cx_folder_clean($in['title'] ?? '');
            if ($fd === '') return ['ok' => false, 'error' => 'نام پوشه را بنویسید.'];
            $all = cx_member_folders($pdo, $bot['id'], $member);
            if (count($all) >= 30) return ['ok' => false, 'error' => 'حداکثر ۳۰ پوشه می‌توانید بسازید.'];
            return ['ok' => true, 'folders' => cx_folders_save($pdo, $member['id'], array_merge($all, [$fd]))];
        case 'folder_rename':
            $old = cx_folder_clean($in['value'] ?? ''); $new = cx_folder_clean($in['title'] ?? '');
            if ($old === '' || $new === '') return ['ok' => false, 'error' => 'نام پوشه را بنویسید.'];
            $pdo->prepare("UPDATE hd_threads SET folder=? WHERE bot_id=? AND member_id=? AND folder=?")->execute([$new, (int)$bot['id'], (int)$member['id'], $old]);
            $all = array_map(fn($f) => $f === $old ? $new : $f, cx_member_folders($pdo, $bot['id'], $member));
            return ['ok' => true, 'folders' => cx_folders_save($pdo, $member['id'], $all)];
        case 'folder_delete':
            $old = cx_folder_clean($in['value'] ?? '');
            $pdo->prepare("UPDATE hd_threads SET folder='' WHERE bot_id=? AND member_id=? AND folder=?")->execute([(int)$bot['id'], (int)$member['id'], $old]);
            return ['ok' => true, 'folders' => cx_folders_save($pdo, $member['id'], array_values(array_filter(cx_member_folders($pdo, $bot['id'], $member), fn($f) => $f !== $old)))];
        // --- کامنت همکار (فقط هنگام ورود همکار با اجازه مراجع) ---
        case 'comment_add':
            if (empty($member['_imp'])) return ['ok' => false, 'error' => 'فقط همکاران مجموعه می‌توانند کامنت بگذارند.'];
            return cx_comment_add($pdo, $bot, (int)$member['id'], (int)($in['message_id'] ?? 0), (string)($in['message'] ?? ''), (string)($in['value'] ?? '1') === '1', (string)($member['imp_actor'] ?? '') ?: 'همکار');
        case 'comment_del':
            if (empty($member['_imp'])) return ['ok' => false, 'error' => 'دسترسی ندارید.'];
            cx_comment_del($pdo, $bot['id'], $member['id'], (int)($in['value'] ?? 0));
            return ['ok' => true];
        // --- ساخت تصویر و ویدیو ---
        case 'gen_image':
        case 'gen_video':
            return cx_generate($pdo, $bot, $member, $action === 'gen_video' ? 'video' : 'image', (string)($in['message'] ?? ''), (int)($in['thread_id'] ?? 0), [
                'file_ids' => is_array($in['file_ids'] ?? null) ? $in['file_ids'] : array_filter(explode(',', (string)($in['file_ids'] ?? ''))),
                'reply_to' => (int)($in['reply_to'] ?? 0), 'size' => (string)($in['size'] ?? 'auto'), 'ctx' => true,
                'action_id' => (int)($in['action_id'] ?? ($in['value'] ?? 0)),   // اقدام آماده (فایل رابط آن را در value می‌فرستد)
                'sample_id' => (int)($in['sample_id'] ?? 0), 'preset_id' => (int)($in['preset_id'] ?? 0),   // ساخت از روی نمونه گالری (نسخه ۵۱)
                'model_id' => (int)($in['model_id'] ?? 0),   // انتخاب مدل تصویر/ویدیو توسط مخاطب (نسخه ۵۹)
            ]);
        // --- گالری طرح‌ها (نسخه ۵۱) ---
        case 'gallery':
            if (!function_exists('gal_bot_kinds')) return ['ok' => false, 'error' => 'bad request'];
            $gk = gal_bot_kinds($pdo, $bot, (bool)cx_gen_models($pdo, $bot, 'image'), (bool)cx_gen_models($pdo, $bot, 'video'));
            if (!$gk) return ['ok' => false, 'error' => 'گالری طرح‌ها در این چت‌بات فعال نیست.'];
            $gfl = cx_member_flags($pdo, $bot, $member);
            $gout = [];
            foreach ($gk as $k) $gout[$k] = gal_catalog($pdo, $k, false, ['where' => 'bot', 'models' => cx_gen_models($pdo, $bot, $k)]) + ['lock' => empty($gfl[$k]), 'cost' => max(1, (int)($k === 'video' ? ($bot['gen_video_cost'] ?? 20) : ($bot['gen_image_cost'] ?? 5)))];
            return ['ok' => true, 'kinds' => $gout];
        case 'gallery_ai':
            $gkind = ($in['value'] ?? '') === 'video' ? 'video' : 'image';
            if (!function_exists('gal_bot_kinds') || !in_array($gkind, gal_bot_kinds($pdo, $bot, (bool)cx_gen_models($pdo, $bot, 'image'), (bool)cx_gen_models($pdo, $bot, 'video')), true)) return ['ok' => false, 'error' => 'گالری طرح‌ها در این چت‌بات فعال نیست.'];
            $greason = '';
            if (empty($bot['is_active']) || !hd_owner_ok($pdo, $bot, $greason)) return ['ok' => false, 'error' => HD_MSG_UNAVAILABLE];
            if (empty(cx_member_flags($pdo, $bot, $member)[$gkind])) return ['ok' => false, 'error' => 'جستجوی هوشمند برای مخاطبانی است که ساخت ' . ($gkind === 'video' ? 'ویدیو' : 'تصویر') . ' در بسته‌شان هست؛ از جستجوی معمولی استفاده کنید.', 'upgrade' => true];
            $gr = gal_ai_search($pdo, $gkind, (string)($in['message'] ?? ''), ['type' => 'bot', 'bot' => $bot, 'member' => $member]);
            unset($gr['toman']);
            return $gr;
        // --- کارت به کارت: ارسال فیش (نسخه ۵۶) ---
        case 'c2c_submit':
            if (!function_exists('c2c_member_submit')) return ['ok' => false, 'error' => 'bad request'];
            $cb = base64_decode((string)($in['data'] ?? ''), true);
            if ($cb === false || $cb === '') return ['ok' => false, 'error' => 'تصویر فیش را انتخاب کنید.'];
            $cr = c2c_member_submit($pdo, $bot, $member, (int)($in['plan_id'] ?? 0), (string)($in['code'] ?? ''), $cb, (string)($in['value'] ?? ''), (string)($in['title'] ?? ''));
            if (!empty($cr['ok'])) $cr['receipts'] = c2c_member_public($pdo, $bot, $member);
            return $cr;
        case 'media_status':
            return cx_media_status($pdo, $bot, $member, (int)($in['value'] ?? 0));
        case 'media_get':
            return cx_media_get($pdo, $bot, $member, (int)($in['value'] ?? 0));
        case 'status':
            return ['ok' => true, 'status' => cx_member_status($pdo, $bot, $member)];
        case 'thread_rename':
            $t = hd_get_thread($pdo, (int)($in['thread_id'] ?? 0), $bot['id'], $member['id']);
            $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 100));
            if (!$t || $title === '') return ['ok' => false, 'error' => 'نام نامعتبر است.'];
            $pdo->prepare("UPDATE hd_threads SET title=? WHERE id=?")->execute([$title, (int)$t['id']]);
            return ['ok' => true];
        case 'send':
            // یادآور از داخل گفتگو: «یادم بنداز فردا ساعت ۱۰ جلسه با آقای احمدی»
            if (function_exists('rem_chat_route') && ($rr = rem_chat_route($pdo, $bot, $member, $in)) !== null) return $rr;
            // ساخت/ویرایش تصویر از داخل گفتگو (بدون دکمه ✨): «یک تصویر … بساز»، ریپلای روی تصویر، «رنگش را آبی کن»
            if (function_exists('cx_gen_route')) {
                $gr = cx_gen_route($pdo, $bot, $member, $in);
                if ($gr !== null) return $gr;
            }
            $r = hd_answer($pdo, $bot, $member, (int)($in['thread_id'] ?? 0), (string)($in['message'] ?? ''), [
                'file_ids' => is_array($in['file_ids'] ?? null) ? $in['file_ids'] : array_filter(explode(',', (string)($in['file_ids'] ?? ''))),
                'model_id' => (int)($in['model_id'] ?? 0),
                'reply_to' => (int)($in['reply_to'] ?? 0),
                'edit_id'  => (int)($in['edit_id'] ?? 0),
            ]);
            if (!empty($r['ok'])) {
                $fm = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
                $fm->execute([(int)$member['id']]);
                $r['ext'] = hd_member_public($pdo, $bot, $fm->fetch(\PDO::FETCH_ASSOC) ?: $member, $r['quota'] ?? null);
            }
            return $r;
        case 'upload':
            if (function_exists('cx_member_flags') && empty(cx_member_flags($pdo, $bot, $member)['files'])) return ['ok' => false, 'error' => 'ارسال تصویر و فایل در بسته فعلی شما نیست؛ با ارتقای بسته فعال می‌شود.', 'upgrade' => true];
            return hd_file_upload($pdo, $bot, $member, (string)($in['name'] ?? ''), (string)($in['data'] ?? ''));
        case 'voice':
            if (empty($bot['voice_on']) || !hd_audio_ready($pdo)) return ['ok' => false, 'error' => 'پیام صوتی فعال نیست.'];
            if (function_exists('cx_member_flags') && empty(cx_member_flags($pdo, $bot, $member)['voice'])) return ['ok' => false, 'error' => 'پیام صوتی در بسته فعلی شما نیست؛ با ارتقای بسته فعال می‌شود.', 'upgrade' => true];
            $reason = '';
            if (!hd_owner_ok($pdo, $bot, $reason)) return ['ok' => false, 'error' => HD_MSG_UNAVAILABLE];
            $b = base64_decode(preg_replace('#^data:[^,]*,#', '', (string)($in['data'] ?? '')), true);
            if (!$b || strlen($b) < 800) return ['ok' => false, 'error' => 'صدایی ضبط نشد.'];
            if (strlen($b) > 8 * 1024 * 1024) return ['ok' => false, 'error' => 'پیام صوتی خیلی طولانی است (حداکثر حدود ۳ دقیقه).'];
            $st = hd_stt($pdo, $b, (string)($in['mime'] ?? 'audio/webm'), (float)($in['dur'] ?? 0));
            if ($st['ok']) {
                $vc = function_exists('biz_audio_cost') ? biz_audio_cost($pdo, $st['model'] ?? null, 'stt', $st['seconds'] ?? 0) : (int)hd_sys($pdo)['hd_voice_cost'];
                unset($st['model'], $st['seconds']);
                if ($vc > 0) {
                    saas_deduct_credit($pdo, (int)$bot['user_id'], $vc, 'پیام صوتی چت‌بات: ' . $bot['name']);
                    if (function_exists('mcr_charge') && (hd_quota($pdo, $bot, $member)['mode'] ?? '') === 'credit') mcr_charge($pdo, $bot, $member, $vc);   // شارژ تومانی مخاطب (نسخه ۶۲)
                    // در جزئیات مصرف پاسخ بعدی نمایش داده می‌شود
                    try { $pdo->prepare("UPDATE hd_members SET voice_pending = voice_pending + ? WHERE id=?")->execute([$vc, (int)$member['id']]); } catch (\Throwable $e) {}
                }
            }
            return $st;
        case 'tts':
            if (empty($bot['voice_on']) || !hd_audio_ready($pdo)) return ['ok' => false, 'error' => 'پخش صوتی فعال نیست.'];
            if (function_exists('cx_member_flags') && empty(cx_member_flags($pdo, $bot, $member)['voice'])) return ['ok' => false, 'error' => 'پخش صوتی در بسته فعلی شما نیست؛ با ارتقای بسته فعال می‌شود.', 'upgrade' => true];
            $ms = $pdo->prepare("SELECT id, content FROM hd_messages WHERE id=? AND bot_id=? AND member_id=? AND role='assistant'");
            $ms->execute([(int)($in['message_id'] ?? 0), (int)$bot['id'], (int)$member['id']]);
            $msg_row = $ms->fetch(\PDO::FETCH_ASSOC);
            if (!$msg_row) return ['ok' => false, 'error' => 'پیام یافت نشد.'];
            $cache = hd_files_dir() . '/tts';
            if (!is_dir($cache)) @mkdir($cache, 0755, true);
            $cf = $cache . '/' . (int)$bot['id'] . '_' . (int)$msg_row['id'] . '.mp3';
            if (!is_file($cf)) {
                $reason = '';
                if (!hd_owner_ok($pdo, $bot, $reason)) return ['ok' => false, 'error' => HD_MSG_UNAVAILABLE];
                $plain = hd_plain_for_speech($msg_row['content']);
                $t = hd_tts($pdo, $plain);
                if (!$t['ok']) return $t;
                @file_put_contents($cf, $t['bytes']);
                $tch = (int)($t['chars'] ?? mb_strlen($plain));
                $tc = function_exists('biz_audio_cost') ? biz_audio_cost($pdo, $t['model'] ?? null, 'tts', $tch) : (int)ceil($tch / 1000 * (int)hd_sys($pdo)['hd_tts_cost']);
                if ($tc > 0) {
                    saas_deduct_credit($pdo, (int)$bot['user_id'], $tc, 'پخش صوتی چت‌بات: ' . $bot['name']);
                    if (function_exists('mcr_charge') && (hd_quota($pdo, $bot, $member)['mode'] ?? '') === 'credit') mcr_charge($pdo, $bot, $member, $tc);   // شارژ تومانی مخاطب (نسخه ۶۲)
                    if (function_exists('hd_msg_cost_add')) hd_msg_cost_add($pdo, (int)$msg_row['id'], 'tts', $tc);
                }
                $bytes = $t['bytes'];
            } else $bytes = (string)file_get_contents($cf);
            return ['ok' => true, 'mime' => 'audio/mpeg', 'data' => base64_encode($bytes)];
        case 'profile':
            // در صفحه مخاطب، «مشاور» با عنوان «همکار» نمایش داده می‌شود
            $nts = array_map(fn($n) => ['kind' => str_replace('مشاور', 'همکار', hd_note_kinds()[$n['kind']] ?? $n['kind']), 'title' => $n['title'], 'content' => $n['content'], 'author' => $n['author'], 'date' => substr((string)$n['created_at'], 0, 10)],
                hd_notes($pdo, $bot['id'], $member['id'], true, false, 30));
            $acc = function_exists('hd_auth_member_dispatch') ? hd_auth_member_dispatch($pdo, $bot, $member, 'account', []) : null;
            return ['ok' => true, 'profile' => (object)hd_profile_get($member), 'intake' => array_keys(hd_intake_list($bot)),
                    'consent' => !empty($member['consent_share']), 'consent_asked' => !empty($member['consent_at']),
                    'consent_at' => substr((string)($member['consent_at'] ?? ''), 0, 10), 'imp' => !empty($member['_imp']), 'notes' => $nts, 'account' => $acc]
                   + (function_exists('sup_member_meta') ? sup_member_meta($pdo, $bot, $member) : [])
                   + (function_exists('ex_member_profile_extra') ? ex_member_profile_extra($pdo, $bot, $member) : []);
        case 'profile_save':
            $pr = is_array($in['profile'] ?? null) ? $in['profile'] : [];
            return ['ok' => true, 'profile' => (object)hd_profile_save($pdo, (int)$member['id'], $pr)];
        case 'consent':
            // فقط خود مراجع می‌تواند اجازه دسترسی را بدهد یا لغو کند
            if (!empty($member['_imp'])) return ['ok' => false, 'error' => 'اجازه دسترسی را فقط خود مراجع می‌تواند تغییر دهد.'];
            $v = (string)($in['value'] ?? '') === '1' ? 1 : 0;
            if ($v && function_exists('sup_allowed') && !sup_allowed($pdo, $bot, $member))
                return ['ok' => false, 'error' => 'نظارت مشاور با تهیه اشتراک فعال می‌شود.', 'need_plan' => true];
            if (function_exists('sup_ensure_schema')) { sup_ensure_schema($pdo); try { $pdo->prepare("UPDATE hd_members SET consent_hold=0 WHERE id=?")->execute([(int)$member['id']]); } catch (\Throwable $e) {} }
            $pdo->prepare("UPDATE hd_members SET consent_share=?, consent_at=? WHERE id=?")->execute([$v, hd_now(), (int)$member['id']]);
            if (!$v) $pdo->prepare("UPDATE hd_members SET imp_hash='', imp_until=NULL, magic_hash='', magic_until=NULL WHERE id=?")->execute([(int)$member['id']]);
            return ['ok' => true, 'consent' => (bool)$v, 'consent_asked' => true];
        case 'quota_borrow':
            // «ریست رایگان»: استفاده از سهمیه روز/هفته بعد (کل پیام‌های اشتراک تغییر نمی‌کند)
            if (!empty($member['_imp'])) return ['ok' => false, 'error' => 'در حالت مشاهده همکار امکان‌پذیر نیست.'];
            if (!function_exists('lim_borrow')) return ['ok' => false, 'error' => 'این امکان فعال نیست.'];
            $br = lim_borrow($pdo, $bot, $member, (string)($in['value'] ?? ''));
            if (!empty($br['quota'])) $br['ext'] = hd_member_public($pdo, $bot, $member, $br['quota']);
            return $br;
        case 'model':
            $mm = hd_member_models($pdo, $bot, $member);
            $mid = (int)($in['model_id'] ?? 0);
            if (!in_array($mid, array_column($mm['list'], 'id'), true)) return ['ok' => false, 'error' => 'مدل نامعتبر است.'];
            $pdo->prepare("UPDATE hd_members SET chosen_model=? WHERE id=?")->execute([$mid, (int)$member['id']]);
            return ['ok' => true];
        case 'rate':
            hd_rate($pdo, $bot['id'], $member['id'], (int)($in['message_id'] ?? 0), (int)($in['value'] ?? 0));
            return ['ok' => true];
        case 'buy':
            if (!empty($member['_imp'])) return ['ok' => false, 'error' => 'خرید را فقط خود مراجع می‌تواند انجام دهد.'];
            return hd_buy($pdo, $bot, $member, (int)($in['plan_id'] ?? 0), (string)($in['return_url'] ?? ''), (string)($in['code'] ?? ''));
        case 'check_code':
            if (function_exists('cx_plan_for_bot')) $plan = cx_plan_for_bot($pdo, $bot, (int)($in['plan_id'] ?? 0));
            else {
                $ps = $pdo->prepare("SELECT * FROM hd_member_plans WHERE id=? AND bot_id=? AND is_active=1");
                $ps->execute([(int)($in['plan_id'] ?? 0), (int)$bot['id']]);
                $plan = $ps->fetch(\PDO::FETCH_ASSOC);
            }
            if (!$plan) return ['ok' => false, 'error' => 'بسته یافت نشد.'];
            $q = hd_quote($pdo, $bot, (int)$member['id'], $plan, (string)($in['code'] ?? ''));
            if ($q['error'] !== '') return ['ok' => false, 'error' => $q['error']];
            return ['ok' => true, 'price' => $q['final'], 'off' => $q['code_off'], 'message' => number_format($q['code_off']) . ' تومان تخفیف اعمال شد.'];
    }
    return ['ok' => false, 'error' => 'unknown action'];
}

/** ربات بر اساس دامنه اختصاصی (حالت زیردامنه) */
function hd_get_bot_by_domain($pdo, $host)
{
    $host = strtolower(trim((string)$host));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) return null;
    $st = $pdo->prepare("SELECT * FROM hd_bots WHERE custom_domain=? OR custom_domain=? LIMIT 1");
    $st->execute([$host, preg_replace('/^www\./', '', $host)]);
    $b = $st->fetch(\PDO::FETCH_ASSOC);
    return $b ?: null;
}

// =============================================================================
// گزارش‌ها
// =============================================================================
function hd_stats($pdo, $bot_id)
{
    $bid = (int)$bot_id;
    $one = function ($sql, $params) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn(); };
    $today = date('Y-m-d 00:00:00');
    $week  = date('Y-m-d 00:00:00', time() - 6 * 86400);
    $st = [
        'members'      => $one("SELECT COUNT(*) FROM hd_members WHERE bot_id=? AND is_owner=0", [$bid]),
        'msgs_today'   => $one("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND role='user' AND created_at >= ?", [$bid, $today]),
        'msgs_week'    => $one("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND role='user' AND created_at >= ?", [$bid, $week]),
        'msgs_total'   => $one("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND role='user'", [$bid]),
        'likes'        => $one("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND rating=1", [$bid]),
        'dislikes'     => $one("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND rating=-1", [$bid]),
        'unanswered'   => $one("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND no_answer=1", [$bid]),
        'revenue'      => $one("SELECT COALESCE(SUM(amount_toman),0) FROM hd_purchases WHERE bot_id=? AND status='paid'", [$bid]),
        'sales'        => $one("SELECT COUNT(*) FROM hd_purchases WHERE bot_id=? AND status='paid'", [$bid]),
        'tokens_week'  => $one("SELECT COALESCE(SUM(tokens),0) FROM hd_messages WHERE bot_id=? AND created_at >= ?", [$bid, $week]),
    ];
    // نمودار ۱۴ روز
    $days = [];
    for ($i = 13; $i >= 0; $i--) $days[date('Y-m-d', time() - $i * 86400)] = 0;
    $s = $pdo->prepare("SELECT created_at FROM hd_messages WHERE bot_id=? AND role='user' AND created_at >= ?");
    $s->execute([$bid, date('Y-m-d 00:00:00', time() - 13 * 86400)]);
    foreach ($s->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $c) { $d = substr($c, 0, 10); if (isset($days[$d])) $days[$d]++; }
    $st['daily'] = $days;
    return $st;
}

/** پرتکرارترین سوال‌ها (بر اساس کلمات کلیدی) */
function hd_top_questions($pdo, $bot_id, $limit = 15)
{
    $s = $pdo->prepare("SELECT content FROM hd_messages WHERE bot_id=? AND role='user' ORDER BY id DESC LIMIT 3000");
    $s->execute([(int)$bot_id]);
    $groups = [];
    foreach ($s->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $q) {
        $k = function_exists('saas_question_key') ? saas_question_key($q) : '';
        if ($k === '') continue;
        if (!isset($groups[$k])) $groups[$k] = ['q' => mb_substr($q, 0, 200), 'n' => 0];
        $groups[$k]['n']++;
    }
    uasort($groups, fn($a, $b) => $b['n'] <=> $a['n']);
    return array_slice(array_values(array_filter($groups, fn($g) => $g['n'] >= 2)), 0, $limit);
}


// =============================================================================
// یادآوری انقضای بسته مخاطبان (پیامک با نام خود ربات — بدون نام سامانه)
// =============================================================================
function hd_cron_member_reminders($pdo)
{
    if (!function_exists('biz_stage_for')) return 0;
    $s = $pdo->prepare("SELECT p.*, m.name AS mname, m.mobile, m.status AS mstatus FROM hd_purchases p JOIN hd_members m ON m.id=p.member_id
        WHERE p.status='paid' AND p.expires_at IS NOT NULL AND p.expires_at > ? AND p.expires_at < ? AND p.used < p.messages ORDER BY p.id ASC LIMIT 500");
    $s->execute([hd_now(), hd_now(31 * 86400)]);
    $bots = [];
    $n = 0;
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $p) {
        $days_left = (int)ceil((strtotime($p['expires_at']) - time()) / 86400);
        $stage = biz_stage_for($days_left, (int)$p['days']);
        if ($stage === null) continue;
        $ref = substr((string)$p['expires_at'], 0, 10);
        if (biz_was_sent($pdo, 'hd_pur', (int)$p['id'], $ref, (string)$stage)) continue;
        biz_once($pdo, 'hd_pur', (int)$p['id'], $ref, (string)$stage);
        if (!isset($bots[$p['bot_id']])) {
            $b = $pdo->prepare("SELECT * FROM hd_bots WHERE id=?");
            $b->execute([(int)$p['bot_id']]);
            $bots[$p['bot_id']] = $b->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $bot = $bots[$p['bot_id']];
        if (!$bot || empty($bot['is_active']) || empty($bot['remind_members']) || $p['mstatus'] !== 'active') continue;
        if (biz_mobile($p['mobile']) === '') continue;
        $txt = (trim((string)$p['mname']) ?: 'کاربر') . ' عزیز، اعتبار بسته «' . $p['plan_name'] . '» شما در «' . $bot['name'] . '» '
             . max(1, $days_left) . ' روز دیگر (' . biz_jdate($p['expires_at']) . ') به پایان می‌رسد.';
        // لینک تمدید: صفحه چت روی سایت صاحب چت‌بات (با باز شدن مستقیم «بسته‌ها»)
        $home = (string)($bot['custom_domain'] ?? '') !== '' ? 'https://' . $bot['custom_domain'] . '/' : (string)($bot['chat_url'] ?? '');
        $link = $home !== '' ? $home . (strpos($home, '?') === false ? '?' : '&') . 'plans=1' : '';
        if ($link !== '') $txt .= "\nتمدید: " . $link;
        $tv = ['name' => trim((string)$p['mname']) ?: 'کاربر', 'plan' => (string)$p['plan_name'], 'bot' => (string)$bot['name'], 'days' => max(1, $days_left), 'date' => biz_jdate($p['expires_at']), 'link' => $link, 'renew' => $link !== '' ? "\nتمدید: " . $link : ''];
        if (hd_sms_ready($pdo, $bot) && hd_send_sms($pdo, $bot, $p['mobile'], $txt, 'remind', 'member_plan_remind', $tv)) $n++;
    }
    return $n;
}

require_once __DIR__ . '/hamdam_ext.php';
require_once __DIR__ . '/sitebot_lib.php';   // چت صفحه اصلی سایت در حالت کامل (نسخه ۴۳)

} // HD_LOADED
