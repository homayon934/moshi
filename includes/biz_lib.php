<?php
/**
 * امکانات تجاری سامانه
 *  - اشتراک یک‌ساله، تخفیف تمدید زودهنگام، مهلت ۷ روزه، یادآوری تمدید (پیامک + اعلان پنل)
 *  - کیف پیامک (پیامک رایگان هر پلن + خرید بسته پیامک)
 *  - تخفیف‌ها و کدهای تخفیف
 *  - همکاری در فروش (لینک/کد معرف) و پورسانت
 *  - تصویر معرفی پلن‌ها
 *  - کارهای زمان‌بندی‌شده (cron)
 *
 * نکته: در پیام‌ها و پیامک‌ها هیچ نامی از سامانه (پلتفرم) آورده نمی‌شود.
 * همه کوئری‌ها بدون توابع اختصاصی MySQL (زمان در PHP ساخته می‌شود).
 */

if (!defined('BIZ_LOADED')) {
define('BIZ_LOADED', 1);

define('BIZ_SCHEMA_VERSION', 10);
define('BIZ_GRACE_DAYS', 7);
define('BIZ_REMIND_STAGES', [30, 7, 3, 1]);

function biz_now($offset = 0) { return date('Y-m-d H:i:s', time() + (int)$offset); }
function biz_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =============================================================================
// ساختار دیتابیس
// =============================================================================
function biz_ensure_schema($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('ex_ensure_schema')) { try { ex_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[EX] ' . $e->getMessage()); } }
    if (function_exists('cm_ensure_schema')) { try { cm_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[MSG] ' . $e->getMessage()); } }
    if (function_exists('comm_ensure_schema')) { try { comm_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[COMM] ' . $e->getMessage()); } }
    if (function_exists('kb_ensure_schema')) { try { kb_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[KB] ' . $e->getMessage()); } }
    if (function_exists('cx_ensure_schema')) { try { cx_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[CX] ' . $e->getMessage()); } }

    // اگر نسخه جاری قبلاً ساخته شده، کاری نکن (فایل پرچم در پوشه آپلود)
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.biz_schema_v' . BIZ_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;

    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('[BIZ] schema: ' . $e->getMessage()); } };

    $q("CREATE TABLE IF NOT EXISTS saas_biz_settings (
        k VARCHAR(60) NOT NULL PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        body TEXT NULL,
        level VARCHAR(10) NOT NULL DEFAULT 'info',
        link VARCHAR(300) NOT NULL DEFAULT '',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_reminders_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(20) NOT NULL,
        target_id INT NOT NULL,
        ref VARCHAR(40) NOT NULL DEFAULT '',
        stage VARCHAR(12) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_rem (kind, target_id, ref, stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_sms_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL DEFAULT 0,
        bot_id INT NOT NULL DEFAULT 0,
        mobile VARCHAR(20) NOT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT '',
        source VARCHAR(10) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_discount_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NOT NULL,
        title VARCHAR(150) NOT NULL DEFAULT '',
        kind VARCHAR(10) NOT NULL DEFAULT 'percent',
        value INT NOT NULL DEFAULT 0,
        applies_to VARCHAR(10) NOT NULL DEFAULT 'all',
        plan_id INT NOT NULL DEFAULT 0,
        max_uses INT NOT NULL DEFAULT 0,
        per_user_limit INT NOT NULL DEFAULT 1,
        min_amount INT NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        affiliate_id INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_discount_uses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code_id INT NOT NULL,
        user_id INT NOT NULL,
        payment_id INT NOT NULL,
        amount_off INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_code (code_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_affiliates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        mobile VARCHAR(20) NOT NULL,
        ref_code VARCHAR(30) NOT NULL,
        commission_percent DECIMAL(5,2) NOT NULL DEFAULT 10,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        sheba VARCHAR(40) NOT NULL DEFAULT '',
        card_no VARCHAR(30) NOT NULL DEFAULT '',
        account_owner VARCHAR(120) NOT NULL DEFAULT '',
        note VARCHAR(300) NOT NULL DEFAULT '',
        clicks INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        last_login DATETIME NULL,
        UNIQUE KEY uniq_mobile (mobile),
        UNIQUE KEY uniq_ref (ref_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_aff_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mobile VARCHAR(20) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_mobile (mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_commissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        affiliate_id INT NOT NULL,
        user_id INT NOT NULL,
        payment_id INT NOT NULL,
        payment_type VARCHAR(10) NOT NULL DEFAULT '',
        base_amount INT NOT NULL DEFAULT 0,
        percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        amount INT NOT NULL DEFAULT 0,
        source VARCHAR(10) NOT NULL DEFAULT 'link',
        status VARCHAR(12) NOT NULL DEFAULT 'approved',
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_payment (payment_id),
        INDEX idx_aff (affiliate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_aff_payouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        affiliate_id INT NOT NULL,
        amount INT NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        INDEX idx_aff (affiliate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ستون‌های جدید
    foreach ([
        "ALTER TABLE saas_users ADD COLUMN sms_balance INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_users ADD COLUMN sms_free_used INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_users ADD COLUMN affiliate_id INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_plans ADD COLUMN free_sms INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_plans ADD COLUMN discount_percent INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_plans ADD COLUMN discount_until DATETIME NULL",
        "ALTER TABLE saas_plans ADD COLUMN banner_url VARCHAR(500) NOT NULL DEFAULT ''",
        "ALTER TABLE saas_plans ADD COLUMN banner_overlay TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE saas_payments MODIFY type VARCHAR(10) NOT NULL DEFAULT 'credit'",
        "ALTER TABLE saas_payments ADD COLUMN original_amount INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_payments ADD COLUMN discount_amount INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_payments ADD COLUMN discount_code_id INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_payments ADD COLUMN sms_count INT NOT NULL DEFAULT 0",
        // نسخه ۴: اقساط، هزینه راه‌اندازی، دامنه اختصاصی، مدل‌های هوش مصنوعی
        "ALTER TABLE saas_payments ADD COLUMN meta TEXT NULL",
        "ALTER TABLE saas_payments ADD COLUMN setup_fee INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_plans ADD COLUMN installments INT NOT NULL DEFAULT 1",
        "ALTER TABLE saas_plans ADD COLUMN installment_days INT NOT NULL DEFAULT 30",
        "ALTER TABLE saas_plans ADD COLUMN setup_fee INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_plans ADD COLUMN allowed_models VARCHAR(500) NOT NULL DEFAULT ''",
        "ALTER TABLE saas_plans ADD COLUMN default_model INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_widget_settings ADD COLUMN ai_model_id INT NOT NULL DEFAULT 0",
    ] as $sql) $q($sql);

    $q("CREATE TABLE IF NOT EXISTS saas_inst_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        plan_id INT NOT NULL,
        total INT NOT NULL DEFAULT 0,
        count INT NOT NULL DEFAULT 1,
        tokens_per INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        done_at DATETIME NULL,
        INDEX idx_user (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_installments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        user_id INT NOT NULL,
        seq INT NOT NULL,
        amount INT NOT NULL,
        due_at DATETIME NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'unpaid',
        payment_id INT NOT NULL DEFAULT 0,
        paid_at DATETIME NULL,
        INDEX idx_order (order_id),
        INDEX idx_user (user_id, status, due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_service_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(12) NOT NULL,
        bot_id INT NOT NULL DEFAULT 0,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        payment_id INT NOT NULL DEFAULT 0,
        amount INT NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        admin_note VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        done_at DATETIME NULL,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_ai_models (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        provider VARCHAR(20) NOT NULL DEFAULT 'avalai',
        model_id VARCHAR(150) NOT NULL,
        price_in DECIMAL(10,4) NOT NULL DEFAULT 0,
        price_out DECIMAL(10,4) NOT NULL DEFAULT 0,
        markup INT NOT NULL DEFAULT 0,
        description VARCHAR(300) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // نسخه ۵: دسته‌بندی مدل‌ها، ضریب هزینه، مدل‌های جایگزین، کاربردها
    foreach ([
        "ALTER TABLE saas_ai_models ADD COLUMN category VARCHAR(10) NOT NULL DEFAULT 'text'",
        "ALTER TABLE saas_ai_models ADD COLUMN multiplier DECIMAL(8,2) NOT NULL DEFAULT 1",
        "ALTER TABLE saas_ai_models ADD COLUMN fallbacks VARCHAR(500) NOT NULL DEFAULT ''",
        "ALTER TABLE saas_ai_models ADD COLUMN use_widget TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE saas_ai_models ADD COLUMN use_bot TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE saas_ai_models ADD COLUMN use_free TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE saas_ai_models ADD COLUMN down_until DATETIME NULL",
        "ALTER TABLE saas_ai_models ADD COLUMN fail_count INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_ai_models ADD COLUMN last_error VARCHAR(300) NOT NULL DEFAULT ''",
        "ALTER TABLE saas_ai_models ADD COLUMN last_ok DATETIME NULL",
        "ALTER TABLE saas_ai_models ADD COLUMN last_fail DATETIME NULL",
        // نسخه ۹: مدل‌های جایگزین به‌صورت زیرمجموعه مدل اصلی (در فهرست‌ها نمایش داده نمی‌شوند)
        "ALTER TABLE saas_ai_models ADD COLUMN parent_id INT NOT NULL DEFAULT 0",
        "ALTER TABLE saas_users ADD COLUMN google_sub VARCHAR(64) NULL",
    ] as $sql) $q($sql);

    $q("CREATE TABLE IF NOT EXISTS saas_team (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        title VARCHAR(120) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL,
        mobile VARCHAR(20) NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        perms TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        last_login DATETIME NULL,
        UNIQUE KEY uq_email (email),
        INDEX idx_owner (owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q("CREATE TABLE IF NOT EXISTS saas_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        team_id INT NOT NULL DEFAULT 0,
        kind VARCHAR(8) NOT NULL DEFAULT 'image',
        model_id INT NOT NULL DEFAULT 0,
        prompt TEXT NULL,
        options VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'queued',
        job_id VARCHAR(190) NOT NULL DEFAULT '',
        file_url VARCHAR(500) NOT NULL DEFAULT '',
        cost INT NOT NULL DEFAULT 0,
        error VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        INDEX idx_user (user_id, kind, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // نسخه ۶: گالری نمونه‌ها و پرامپت‌های پس‌زمینه (طراحی و ویدیو)
    $q("CREATE TABLE IF NOT EXISTS saas_gallery_cats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(8) NOT NULL DEFAULT 'image',
        title VARCHAR(120) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_gallery (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(8) NOT NULL DEFAULT 'image',
        cat_id INT NOT NULL DEFAULT 0,
        title VARCHAR(190) NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL DEFAULT '',
        prompt TEXT NULL,
        file_url VARCHAR(500) NOT NULL DEFAULT '',
        poster_url VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_kind (kind, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_prompt_presets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(8) NOT NULL DEFAULT 'image',
        title VARCHAR(120) NOT NULL,
        description VARCHAR(300) NOT NULL DEFAULT '',
        prompt TEXT NULL,
        use_ref TINYINT(1) NOT NULL DEFAULT 1,
        cat_ids VARCHAR(300) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("ALTER TABLE saas_media ADD COLUMN sample_id INT NOT NULL DEFAULT 0");
    // v7: فعال/غیرفعال کردن موضوعات پایگاه دانش ویجت
    $q("ALTER TABLE saas_knowledge ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    // v8: واقع‌گرایی و پایبندی به منابع ویجت (−۱ = پیش‌فرض سامانه)
    $q("ALTER TABLE saas_widget_settings ADD COLUMN realism INT NOT NULL DEFAULT -1");
    $q("ALTER TABLE saas_widget_settings ADD COLUMN grounding INT NOT NULL DEFAULT -1");
    $q("ALTER TABLE saas_media ADD COLUMN preset_id INT NOT NULL DEFAULT 0");
    if (empty(biz_settings($pdo, true)['gallery_seeded'])) {
        // دسته‌ها و پرامپت‌های پیش‌فرض (قابل ویرایش در پنل مدیریت)
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare("INSERT INTO saas_gallery_cats (kind, title, sort_order) VALUES(?,?,?)");
        foreach (['لوگو', 'کارت ویزیت', 'تراکت', 'بروشور', 'پست اینستاگرام', 'بنر'] as $i => $t) $ins->execute(['image', $t, $i]);
        foreach (['کارتونی', 'عاشقانه', 'تیزر تبلیغاتی', 'رئال', 'موشن گرافیک'] as $i => $t) $ins->execute(['video', $t, $i]);
        $pp = $pdo->prepare("INSERT INTO saas_prompt_presets (kind, title, description, prompt, use_ref, is_active, sort_order, created_at) VALUES(?,?,?,?,?,1,?,?)");
        $pp->execute(['image', 'اجرای دقیق طرح', 'همین طرح نمونه، با اطلاعات شما', "Recreate the reference design as faithfully as possible: keep the same layout, composition, colors, typography style and overall look. Only replace the content with the customer's details below.\nReference description: {نمونه}\nCustomer details: {درخواست}\nAll texts must be written exactly as given, in Persian (right-to-left) when Persian.", 1, 0, $now]);
        $pp->execute(['image', 'طرح مشابه', 'طرحی جدید با همین حال‌وهوا', "Create a NEW original design inspired by the style, mood and color palette of the reference, but with a different layout and composition.\nReference description: {نمونه}\nCustomer details: {درخواست}\nAll texts must be written exactly as given, in Persian (right-to-left) when Persian.", 1, 1, $now]);
        $pp->execute(['video', 'اجرای دقیق', 'همین ویدیو با موضوع شما', "Recreate the reference video's style, camera movement, pacing, lighting and mood as closely as possible, adapted to the customer's subject.\nReference: {نمونه}\nCustomer request: {درخواست}", 1, 0, $now]);
        $pp->execute(['video', 'ویدیو مشابه', 'ویدیویی جدید با همین سبک', "Create a new original video in a similar style and mood to the reference, with fresh scenes.\nReference: {نمونه}\nCustomer request: {درخواست}", 0, 1, $now]);
        biz_set($pdo, 'gallery_seeded', 1);
    }

    // همدم (در صورت وجود)
    $q("ALTER TABLE hd_bots ADD COLUMN ai_model_id INT NOT NULL DEFAULT 0");
    $q("ALTER TABLE hd_bots ADD COLUMN domain_paid TINYINT(1) NOT NULL DEFAULT 0");
    $q("ALTER TABLE hd_bots ADD COLUMN free_model_id INT NOT NULL DEFAULT 0");

    // اشتراک دوباره یک‌ساله شد: کاربرانی که (در نسخه دائمی) تاریخ انقضا ندارند، یک سال از امروز
    if (empty(biz_settings($pdo, true)['yearly_restored'])) {
        $q("UPDATE saas_users SET plan_expires_at='" . date('Y-m-d H:i:s', strtotime('+1 year')) . "' WHERE plan_id IS NOT NULL AND plan_id>0 AND plan_expires_at IS NULL");
        biz_set($pdo, 'yearly_restored', 1);
    }

    // v10: قیمت دلاری هر مدل از سایت مرجع + درصد سود؛ اعتبار کاربران به تومان
    foreach ([
        "ALTER TABLE saas_ai_models ADD COLUMN usd_in DECIMAL(14,6) NOT NULL DEFAULT 0",
        "ALTER TABLE saas_ai_models ADD COLUMN usd_out DECIMAL(14,6) NOT NULL DEFAULT 0",
        "ALTER TABLE saas_ai_models ADD COLUMN usd_unit DECIMAL(14,6) NOT NULL DEFAULT 0",
        "ALTER TABLE saas_ai_models ADD COLUMN margin DECIMAL(7,2) NOT NULL DEFAULT -1",
        "ALTER TABLE saas_ai_models ADD COLUMN price_src VARCHAR(10) NOT NULL DEFAULT 'auto'",
        "ALTER TABLE saas_ai_models ADD COLUMN price_ref VARCHAR(150) NOT NULL DEFAULT ''",
        "ALTER TABLE saas_ai_models ADD COLUMN price_note VARCHAR(255) NOT NULL DEFAULT ''",
        "ALTER TABLE saas_ai_models ADD COLUMN price_at DATETIME NULL",
        "ALTER TABLE saas_media MODIFY cost BIGINT NOT NULL DEFAULT 0",
    ] as $sql) $q($sql);
    try { biz_migrate_credit_toman($pdo); }
    catch (\Throwable $e) { error_log('[BIZ] toman migration: ' . $e->getMessage()); return; }   // پرچم نوشته نشود تا دوباره تلاش شود
    // مدل‌های صوتی چت‌بات که قبلاً در «تنظیمات چت‌بات» بودند به صفحه مدل‌ها منتقل می‌شوند
    try {
        $sset = biz_settings($pdo, true);
        foreach (['stt' => ['hd_stt_model', 'gpt-4o-mini-transcribe', 'تبدیل صدا به متن'], 'tts' => ['hd_tts_model', 'gpt-4o-mini-tts', 'خواندن پاسخ با صدا']] as $cat => [$key, $def, $title]) {
            $c = $pdo->prepare("SELECT COUNT(*) FROM saas_ai_models WHERE category=?");
            $c->execute([$cat]);
            if ((int)$c->fetchColumn() > 0 || !empty($sset['audio_seeded_' . $cat])) continue;
            $mid = trim((string)($sset[$key] ?? '')) !== '' ? trim((string)$sset[$key]) : $def;
            $pdo->prepare("INSERT INTO saas_ai_models (title, provider, model_id, category, multiplier, fallbacks, description, sort_order, is_active, use_widget, use_bot, use_free, created_at) VALUES(?,?,?,?,1,'','',0,1,0,1,0,?)")
                ->execute([$title, 'avalai', $mid, $cat, biz_now()]);
            biz_set($pdo, 'audio_seeded_' . $cat, 1);
        }
    } catch (\Throwable $e) { error_log('[BIZ] audio seed: ' . $e->getMessage()); }

    if ($flag !== '') @file_put_contents($flag, date('c'));
}

/**
 * تبدیل یک‌باره اعتبار از «توکن اعتبار» به «تومان» (با قیمت فعلی هر ۱۰۰۰ توکن)
 * موجودی کاربران، اعتبار پلن‌ها، سوابق پرداخت/مصرف و هزینه‌های ثابت چت‌بات.
 * فقط یک بار اجرا می‌شود (تنظیم credit_unit=toman).
 */
function biz_migrate_credit_toman($pdo)
{
    $s = biz_settings($pdo, true);
    if (($s['credit_unit'] ?? '') === 'toman') return false;
    $cfg = saas_get_api_config($pdo);
    $f = max(0.0, (float)$cfg['cost_per_1k_tokens']) / 1000;   // تومان برای هر توکن اعتبار
    if ($f <= 0) $f = 1.0;
    biz_set($pdo, 'credit_unit', 'toman');   // اول علامت بزن تا در صورت اجرای هم‌زمان دو بار ضرب نشود
    biz_set($pdo, 'credit_factor', $f);
    if (abs($f - 1.0) > 0.000001) {
        $x = rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.');
        foreach ([
            ['saas_users', 'credit_tokens'], ['saas_plans', 'credit_tokens'], ['saas_payments', 'credit_tokens'],
            ['saas_messages', 'tokens_used'], ['saas_credit_log', 'tokens_delta'], ['saas_inst_orders', 'tokens_per'],
            ['saas_media', 'cost'], ['hd_bots', 'tokens_used'], ['hd_members', 'voice_pending'], ['hd_messages', 'tokens'],
        ] as [$t, $c]) {
            try { $pdo->exec("UPDATE $t SET $c = ROUND($c * $x)"); } catch (\Throwable $e) { error_log('[BIZ] toman ' . $t . ': ' . $e->getMessage()); }
        }
        // جزئیات مصرف پیام‌های چت‌بات (JSON)
        try {
            $rows = $pdo->query("SELECT id, tok_detail FROM hd_messages WHERE tok_detail IS NOT NULL AND tok_detail <> ''")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $up = $pdo->prepare("UPDATE hd_messages SET tok_detail=? WHERE id=?");
            foreach ($rows as $r) {
                $d = json_decode((string)$r['tok_detail'], true);
                if (!is_array($d)) continue;
                foreach (['ai', 'v', 'f', 'voice', 'tts', 'mem'] as $k) if (isset($d[$k]) && is_numeric($d[$k])) $d[$k] = (int)round($d[$k] * $f);
                $up->execute([json_encode($d, JSON_UNESCAPED_UNICODE), (int)$r['id']]);
            }
        } catch (\Throwable $e) {}
    }
    // قیمت پشتیبان تصویر و ویدیو (قبلاً توکن اعتبار) به تومان
    biz_set($pdo, 'image_fallback_toman', (int)round((float)($s['image_base_tokens'] ?? 5000) * $f));
    biz_set($pdo, 'video_fallback_toman', (int)round((float)($s['video_base_tokens'] ?? 20000) * $f));
    biz_set($pdo, 'credit_usd_1k', 0);   // قیمت پشتیبان هر ۱۰۰۰ توکن از این پس ثابت تومانی است
    // هزینه‌های ثابت چت‌بات (پیام صوتی، پخش صوتی، فایل) به تومان
    $hd = ['hd_voice_cost' => 300, 'hd_tts_cost' => 800, 'hd_file_cost' => 0];
    foreach ($hd as $k => $def) {
        $v = isset($s[$k]) && $s[$k] !== '' ? (float)$s[$k] : $def;
        biz_set($pdo, $k, (int)round($v * $f));
    }
    return true;
}

// =============================================================================
// تنظیمات (کلید/مقدار)
// =============================================================================
function biz_defaults()
{
    return [
        'sms_price_toman'        => 150,   // قیمت هر پیامک (تومان)
        'sms_min_purchase'       => 100,   // حداقل تعداد خرید پیامک
        'credit_discount_percent'=> 0,
        'credit_discount_until'  => '',
        'sms_discount_percent'   => 0,
        'sms_discount_until'     => '',
        'aff_enabled'            => 1,
        'aff_default_percent'    => 10,    // پورسانت پیش‌فرض همکار (٪ مبلغ پرداختی)
        'aff_customer_discount'  => 10,    // تخفیف مشتری با کد همکار (٪)
        'aff_cookie_days'        => 60,
        'aff_auto_approve'       => 0,
        'aff_min_payout'         => 200000,
        'aff_terms'              => '',
        'user_remind_sms'        => 1,     // پیامک یادآوری تمدید اشتراک به کاربران
        'domain_fee'             => 0,     // هزینه راه‌اندازی دامنه اختصاصی (تومان؛ ۰ = رایگان)
        'usd_rate'               => 0,     // نرخ دلار (تومان)
        'usd_auto'               => 0,     // به‌روزرسانی خودکار نرخ دلار
        'usd_margin'             => 0,     // درصد افزوده روی نرخ دلار
        'usd_updated'            => '',
        'usd_error'              => '',
        'credit_usd_1k'          => 0,     // ارزش دلاری هر ۱۰۰۰ توکن اعتبار (در حالت خودکار)
        'yearly_restored'        => 0,
        'renew_tiers'            => '90:50,60:30,7:10',   // تخفیف تمدید زودهنگام: «روز مانده:درصد»
        'model_margin'           => 30,    // درصد سود پیش‌فرض روی قیمت مرجع مدل‌ها
        'sys_model_id'           => 0,     // مدل متنی کارهای داخلی سامانه (۰ = تنظیمات API)
        'gen_tr_model_id'        => 0,     // مدل مترجم درخواست‌های تصویر/ویدیو (۰ = ارزان‌ترین مدل متنی)
        'gen_tr_mode'            => 'auto', // مترجم: auto = فقط برای مدل‌هایی که فارسی نمی‌فهمند | always | never
        'price_updated'          => '',
        'credit_unit'            => '',    // toman پس از تبدیل اعتبار به تومان
        'image_fallback_toman'   => 5000,  // قیمت پشتیبان هر تصویر (تومان) برای مدل بدون قیمت
        'video_fallback_toman'   => 20000, // قیمت پشتیبان هر ثانیه ویدیو (تومان)
        'image_base_tokens'      => 5000,  // هزینه پایه ساخت هر تصویر (توکن اعتبار، ضریب ×۱)
        'video_base_tokens'      => 20000, // هزینه پایه هر ثانیه ویدیو (توکن اعتبار، ضریب ×۱)
        'google_login'           => 0,
        'google_client_id'       => '',
        'google_client_secret'   => '',
        'hd_google_login'        => 0,     // ورود مخاطبان چت‌بات‌ها با گوگل
        'cron_key'               => '',
        'cron_last_run'          => '',
    ];
}

function biz_settings($pdo, $fresh = false)
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $s = biz_defaults();
    try {
        foreach ($pdo->query("SELECT k, v FROM saas_biz_settings")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $s[$r['k']] = $r['v'];
    } catch (\Throwable $e) {}
    if ($s['cron_key'] === '') {
        $s['cron_key'] = bin2hex(random_bytes(12));
        biz_set($pdo, 'cron_key', $s['cron_key']);
    }
    return $cache = $s;
}

function biz_get($pdo, $k) { $s = biz_settings($pdo); return $s[$k] ?? null; }

function biz_set($pdo, $k, $v)
{
    try {
        $pdo->prepare("DELETE FROM saas_biz_settings WHERE k=?")->execute([$k]);
        $pdo->prepare("INSERT INTO saas_biz_settings (k, v) VALUES(?,?)")->execute([$k, (string)$v]);
    } catch (\Throwable $e) { error_log('[BIZ] set: ' . $e->getMessage()); }
    if ($k !== 'cron_key') biz_settings($pdo, true);   // به‌روزرسانی کش
}

// =============================================================================
// ابزارها
// =============================================================================
/** تاریخ شمسی (Y/m/d) */
function biz_jdate($datetime, $with_time = false)
{
    if (!$datetime) return '—';
    $ts = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
    if (!$ts) return '—';
    [$gy, $gm, $gd] = array_map('intval', explode('-', date('Y-m-d', $ts)));
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
    $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    if ($with_time) $out .= ' ' . date('H:i', $ts);
    return $out;
}

function biz_mobile($m)
{
    $m = strtr(trim((string)$m), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    $m = preg_replace('/\D+/', '', $m);
    if (strpos($m, '0098') === 0) $m = '0' . substr($m, 4);
    elseif (strpos($m, '98') === 0 && strlen($m) === 12) $m = '0' . substr($m, 2);
    elseif (strlen($m) === 10 && $m[0] === '9') $m = '0' . $m;
    return preg_match('/^09\d{9}$/', $m) ? $m : '';
}

function biz_active_until($until)
{
    return $until === null || $until === '' || strtotime((string)$until) > time();
}

// =============================================================================
// اعلان‌های پنل کاربری
// =============================================================================
function biz_notify($pdo, $user_id, $title, $body = '', $level = 'info', $link = '')
{
    try {
        $pdo->prepare("INSERT INTO saas_notifications (user_id, title, body, level, link, is_read, created_at) VALUES(?,?,?,?,?,0,?)")
            ->execute([(int)$user_id, mb_substr($title, 0, 200), $body, $level, mb_substr($link, 0, 300), biz_now()]);
    } catch (\Throwable $e) { error_log('[BIZ] notify: ' . $e->getMessage()); }
}

function biz_unread_count($pdo, $user_id)
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM saas_notifications WHERE user_id=? AND is_read=0");
        $s->execute([(int)$user_id]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

// =============================================================================
// اقساط خریدهای قسطی قدیمی (خرید جدید قسطی نیست)
// =============================================================================
/**
 * وضعیت اقساط کاربر:
 *  none    = قسط پرداخت‌نشده ندارد
 *  ok      = قسط بعدی بیش از ۳ روز دیگر
 *  due     = سررسید نزدیک (۳ روز مانده تا روز سررسید)
 *  grace   = سررسید گذشته، در مهلت ۷ روزه (سرویس کار می‌کند)
 *  blocked = بیش از ۷ روز تأخیر؛ سرویس متوقف تا پرداخت
 */
function biz_inst_status($pdo, $user_id)
{
    $out = ['state' => 'none', 'next' => null, 'days_left' => null, 'grace_until' => null, 'unpaid' => 0, 'unpaid_sum' => 0];
    try {
        $s = $pdo->prepare("SELECT i.* FROM saas_installments i JOIN saas_inst_orders o ON o.id=i.order_id WHERE i.user_id=? AND i.status='unpaid' AND o.status='active' ORDER BY i.due_at ASC, i.seq ASC");
        $s->execute([(int)$user_id]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return $out; }
    if (!$rows) return $out;
    $next = $rows[0];
    $due = strtotime($next['due_at']);
    $out['next'] = $next;
    $out['unpaid'] = count($rows);
    $out['unpaid_sum'] = array_sum(array_map(fn($r) => (int)$r['amount'], $rows));
    $out['days_left'] = (int)ceil(($due - time()) / 86400);
    $out['grace_until'] = date('Y-m-d H:i:s', $due + BIZ_GRACE_DAYS * 86400);
    if ($due > time() + 3 * 86400) $out['state'] = 'ok';
    elseif ($due > time()) $out['state'] = 'due';
    elseif ($due + BIZ_GRACE_DAYS * 86400 > time()) $out['state'] = 'grace';
    else $out['state'] = 'blocked';
    return $out;
}

/**
 * وضعیت اشتراک یک‌ساله:
 *  none    = بدون پلن یا بدون تاریخ انقضا
 *  active  = فعال
 *  grace   = منقضی شده ولی در مهلت ۷ روزه (سرویس کار می‌کند)
 *  expired = پایان مهلت؛ سرویس متوقف
 * (قسط عقب‌افتاده خریدهای قسطی قدیمی هم در نظر گرفته می‌شود)
 */
function biz_subscription($user, $pdo = null)
{
    $exp = (string)($user['plan_expires_at'] ?? '');
    if (empty($user['plan_id']) || $exp === '' || !strtotime($exp)) {
        $out = ['state' => 'none', 'expires_at' => null, 'days_left' => null, 'grace_until' => null, 'inst' => null];
    } else {
        $e = strtotime($exp);
        $grace = $e + BIZ_GRACE_DAYS * 86400;
        $out = ['state' => $e > time() ? 'active' : ($grace > time() ? 'grace' : 'expired'), 'expires_at' => $exp,
                'days_left' => (int)ceil(($e - time()) / 86400), 'grace_until' => date('Y-m-d H:i:s', $grace), 'inst' => null];
    }
    if (!empty($user['id'])) {
        if ($pdo === null && function_exists('aichat_connect')) { try { $pdo = aichat_connect(); } catch (\Throwable $e) { $pdo = null; } }
        if ($pdo) {
            $st = biz_inst_status($pdo, (int)$user['id']);
            if ($st['state'] !== 'none') $out['inst'] = $st;
            if ($st['state'] === 'blocked') $out['state'] = 'expired';
        }
    }
    return $out;
}

/** آیا سرویس (ویجت و چت‌بات‌ها) به‌خاطر انقضای اشتراک متوقف است؟ */
function biz_service_blocked($pdo, $user_or_id)
{
    $u = $user_or_id;
    if (!is_array($u)) {
        $s = $pdo->prepare("SELECT id, plan_id, plan_expires_at FROM saas_users WHERE id=?");
        $s->execute([(int)$user_or_id]);
        $u = $s->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
    return biz_subscription($u, $pdo)['state'] === 'expired';
}

/** تاریخ انقضای جدید هنگام خرید/تمدید پلن (یک سال) */
function biz_new_expiry($user, $plan_id)
{
    $base = time();
    $cur = strtotime((string)($user['plan_expires_at'] ?? ''));
    // تمدید همان پلن پیش از انقضا: یک سال به تاریخ انقضای فعلی اضافه می‌شود (روزهای باقی‌مانده از بین نمی‌رود)
    if ($cur && (int)($user['plan_id'] ?? 0) === (int)$plan_id && $cur > time()) $base = $cur;
    return date('Y-m-d H:i:s', strtotime('+1 year', $base));
}

/** پله‌های تخفیف تمدید زودهنگام: [[روز, درصد], ...] از بیشترین روز */
function biz_renew_tiers($pdo)
{
    $out = [];
    foreach (explode(',', (string)biz_get($pdo, 'renew_tiers')) as $t) {
        $p = array_map('intval', explode(':', trim($t)));
        if (count($p) === 2 && $p[0] > 0 && $p[1] > 0 && $p[1] < 100) $out[] = $p;
    }
    usort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return $out;
}

/**
 * تخفیف تمدید زودهنگام برای کاربر (فقط تمدید همان پلن در حالت فعال)
 * @return array ['percent', 'days_left', 'until' (پایان پله فعلی), 'next' => [روز, درصد] | null, 'tiers']
 */
function biz_renew_offer($pdo, $user, $plan_id = null)
{
    $out = ['percent' => 0, 'days_left' => null, 'until' => null, 'next' => null, 'tiers' => biz_renew_tiers($pdo)];
    if (empty($user['plan_id']) || ($plan_id !== null && (int)$plan_id !== (int)$user['plan_id'])) return $out;
    $sub = biz_subscription($user, $pdo);
    if ($sub['state'] !== 'active') return $out;
    $out['days_left'] = $sub['days_left'];
    $exp = strtotime($sub['expires_at']);
    foreach ($out['tiers'] as $i => [$d, $pct]) {
        if ($sub['days_left'] >= $d) {
            $out['percent'] = $pct;
            $out['until'] = date('Y-m-d H:i:s', $exp - $d * 86400);   // پایان این پله
            $out['next'] = $out['tiers'][$i + 1] ?? null;             // پله بعدی [روز, درصد] یا null
            break;
        }
    }
    return $out;
}

/** سفارش قسطی فعال (با قسط پرداخت‌نشده) */
function biz_active_inst_order($pdo, $user_id)
{
    $s = $pdo->prepare("SELECT * FROM saas_inst_orders WHERE user_id=? AND status='active' ORDER BY id DESC LIMIT 1");
    $s->execute([(int)$user_id]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** تقسیم مبلغ به اقساط مساوی (گرد به هزار تومان؛ اختلاف در قسط آخر) */
function biz_split_installments($total, $n)
{
    $n = max(1, (int)$n);
    $per = (int)(floor($total / $n / 1000) * 1000);
    if ($per < 1000) $per = (int)ceil($total / $n);
    $list = array_fill(0, $n, $per);
    $list[$n - 1] = (int)$total - $per * ($n - 1);
    return $list;
}

/** درخواست خدمات (راه‌اندازی، دامنه) برای مدیر */
function biz_service_request($pdo, $user_id, $type, $detail, $payment_id = 0, $amount = 0, $bot_id = 0)
{
    $pdo->prepare("INSERT INTO saas_service_requests (user_id, type, bot_id, detail, payment_id, amount, status, created_at) VALUES(?,?,?,?,?,?,'open',?)")
        ->execute([(int)$user_id, $type, (int)$bot_id, mb_substr($detail, 0, 500), (int)$payment_id, (int)$amount, biz_now()]);
    return (int)$pdo->lastInsertId();
}

function biz_open_services_count($pdo)
{
    try { return (int)$pdo->query("SELECT COUNT(*) FROM saas_service_requests WHERE status='open'")->fetchColumn(); } catch (\Throwable $e) { return 0; }
}

// =============================================================================
// کیف پیامک
// =============================================================================
function biz_sms_status($pdo, $user_id)
{
    $s = $pdo->prepare("SELECT u.sms_balance, u.sms_free_used, u.plan_id, p.free_sms FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id WHERE u.id=?");
    $s->execute([(int)$user_id]);
    $r = $s->fetch(\PDO::FETCH_ASSOC) ?: [];
    $free = (int)($r['free_sms'] ?? 0);
    $used = (int)($r['sms_free_used'] ?? 0);
    return [
        'free_total' => $free,
        'free_used'  => min($used, $free),
        'free_left'  => max(0, $free - $used),
        'balance'    => (int)($r['sms_balance'] ?? 0),
        'total_left' => max(0, $free - $used) + (int)($r['sms_balance'] ?? 0),
    ];
}

function biz_platform_sms_ready($pdo)
{
    $cfg = saas_get_api_config($pdo);
    return !empty($cfg['sms_enabled']) && !empty($cfg['sms_api_key']);
}

/** ارسال پیامک با پنل سامانه بدون کسر از سهمیه کسی (پیامک‌های خود سامانه به کاربران/همکاران) */
function biz_sms_platform($pdo, $mobile, $text, $kind = 'system', $user_id = 0)
{
    if (!biz_platform_sms_ready($pdo)) return false;
    $mobile = biz_mobile($mobile);
    if ($mobile === '') return false;
    $r = comm_sms_text($pdo, $mobile, $text);
    comm_sms_log($pdo, (int)$user_id, 0, $mobile, $kind, 'platform', $r['ok'], '', $r['ok'] ? '' : $r['error']);
    return $r['ok'];
}

/** ارسال پیامک سامانه با الگوی تعریف‌شده در «پیامک و ورود» (بدون کسر از سهمیه کسی) */
function biz_sms_platform_tpl($pdo, $mobile, $tpl, array $vars, $kind = 'system', $user_id = 0)
{
    if (!biz_platform_sms_ready($pdo)) return false;
    $r = comm_sms_send($pdo, $mobile, $tpl, $vars, ['kind' => $kind, 'user_id' => (int)$user_id, 'source' => 'platform']);
    return $r['ok'];
}

/**
 * ارسال پیامک از طرف کاربر برای مخاطبان او (کسر از سهمیه رایگان پلن، سپس کیف پیامک)
 * @return bool ارسال شد یا نه
 */
function biz_sms_for_user($pdo, $user_id, $mobile, $text, $kind = 'otp', $bot_id = 0, $tpl = '', array $vars = [])
{
    if (!biz_platform_sms_ready($pdo)) return false;
    $mobile = biz_mobile($mobile);
    if ($mobile === '') return false;
    $st = biz_sms_status($pdo, $user_id);
    $source = '';
    if ($st['free_left'] > 0) {
        $u = $pdo->prepare("UPDATE saas_users SET sms_free_used = sms_free_used + 1 WHERE id=? AND sms_free_used < ?");
        $u->execute([(int)$user_id, $st['free_total']]);
        if ($u->rowCount() > 0) $source = 'free';
    }
    if ($source === '') {
        $u = $pdo->prepare("UPDATE saas_users SET sms_balance = sms_balance - 1 WHERE id=? AND sms_balance > 0");
        $u->execute([(int)$user_id]);
        if ($u->rowCount() > 0) $source = 'paid';
    }
    if ($source === '') {
        biz_notify_once($pdo, $user_id, 'sms_empty', date('Y-m-d'), '✉️ اعتبار پیامک شما تمام شده است',
            'پیامک‌های مخاطبان شما (مثل کد ورود یا یادآوری) ارسال نمی‌شود. از بخش «اعتبار و پرداخت» بسته پیامک تهیه کنید.', 'danger', 'billing.php#sms');
        return false;
    }
    if ($tpl !== '') {
        $r = comm_sms_send($pdo, $mobile, $tpl, $vars, ['kind' => $kind, 'user_id' => (int)$user_id, 'bot_id' => (int)$bot_id, 'source' => $source]);
    } else {
        $r = comm_sms_text($pdo, $mobile, $text);
        comm_sms_log($pdo, (int)$user_id, (int)$bot_id, $mobile, $kind, $source, $r['ok'], '', $r['ok'] ? '' : $r['error']);
    }
    if (!$r['ok']) {
        // ارسال نشد: سهمیه برگردانده می‌شود
        if ($source === 'free') $pdo->prepare("UPDATE saas_users SET sms_free_used = GREATEST(0, sms_free_used - 1) WHERE id=?")->execute([(int)$user_id]);
        else $pdo->prepare("UPDATE saas_users SET sms_balance = sms_balance + 1 WHERE id=?")->execute([(int)$user_id]);
        return false;
    }
    // هشدار کم شدن اعتبار پیامک
    $left = biz_sms_status($pdo, $user_id)['total_left'];
    if ($left === 20 || $left === 5) {
        biz_notify_once($pdo, $user_id, 'sms_low', $left . '_' . date('Y-m'), '✉️ اعتبار پیامک رو به اتمام است',
            'فقط ' . $left . ' پیامک برای مخاطبان شما باقی مانده است.', 'warn', 'billing.php#sms');
    }
    return true;
}

/** آیا کاربر می‌تواند برای مخاطبانش پیامک بفرستد؟ */
function biz_user_can_sms($pdo, $user_id)
{
    return biz_platform_sms_ready($pdo) && biz_sms_status($pdo, $user_id)['total_left'] > 0;
}

/** اعلان/یادآوری فقط یک بار (بر اساس kind + ref + stage) */
function biz_once($pdo, $kind, $target_id, $ref, $stage)
{
    try {
        $pdo->prepare("INSERT INTO saas_reminders_sent (kind, target_id, ref, stage, created_at) VALUES(?,?,?,?,?)")
            ->execute([$kind, (int)$target_id, mb_substr((string)$ref, 0, 40), (string)$stage, biz_now()]);
        return true;
    } catch (\Throwable $e) {
        return false;   // قبلاً ثبت شده (کلید یکتا)
    }
}

function biz_notify_once($pdo, $user_id, $kind, $ref, $title, $body, $level = 'info', $link = '')
{
    // در حالت ERRMODE_WARNING، خطای کلید تکراری استثنا نمی‌دهد؛ پس اول بررسی می‌کنیم
    $c = $pdo->prepare("SELECT COUNT(*) FROM saas_reminders_sent WHERE kind=? AND target_id=? AND ref=? AND stage='n'");
    $c->execute([$kind, (int)$user_id, (string)$ref]);
    if ((int)$c->fetchColumn() > 0) return false;
    biz_once($pdo, $kind, $user_id, $ref, 'n');
    biz_notify($pdo, $user_id, $title, $body, $level, $link);
    return true;
}

/** قبلاً ارسال شده؟ */
function biz_was_sent($pdo, $kind, $target_id, $ref, $stage)
{
    $c = $pdo->prepare("SELECT COUNT(*) FROM saas_reminders_sent WHERE kind=? AND target_id=? AND ref=? AND stage=?");
    $c->execute([$kind, (int)$target_id, (string)$ref, (string)$stage]);
    return (int)$c->fetchColumn() > 0;
}

// =============================================================================
// قیمت‌گذاری و تخفیف (خرید پلن / شارژ توکن / بسته پیامک)
// =============================================================================
/** قیمت پلن با تخفیف خودکار */
function biz_plan_price($plan)
{
    $orig = (int)($plan['price_toman'] ?? 0);
    $pct = (int)($plan['discount_percent'] ?? 0);
    if ($orig > 0 && $pct > 0 && $pct < 100 && biz_active_until($plan['discount_until'] ?? null)) {
        $final = (int)(round($orig * (100 - $pct) / 100 / 1000) * 1000);   // گرد به هزار تومان
        if ($final < 1000) $final = 1000;
        return ['price' => $final, 'original' => $orig, 'percent' => $pct, 'until' => $plan['discount_until'] ?? null];
    }
    return ['price' => $orig, 'original' => $orig, 'percent' => 0, 'until' => null];
}

/** درصد تخفیف خودکار شارژ توکن / پیامک */
function biz_auto_discount($pdo, $type)
{
    $s = biz_settings($pdo);
    if ($type === 'credit' && (int)$s['credit_discount_percent'] > 0 && biz_active_until($s['credit_discount_until'])) return min(90, (int)$s['credit_discount_percent']);
    if ($type === 'sms' && (int)$s['sms_discount_percent'] > 0 && biz_active_until($s['sms_discount_until'])) return min(90, (int)$s['sms_discount_percent']);
    return 0;
}

function biz_find_code($pdo, $code)
{
    $code = strtoupper(trim(preg_replace('/\s+/', '', (string)$code)));
    if ($code === '') return null;
    $s = $pdo->prepare("SELECT * FROM saas_discount_codes WHERE code=? LIMIT 1");
    $s->execute([$code]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/**
 * بررسی کد تخفیف برای یک خرید
 * @return array ['ok', 'error', 'code' (ردیف), 'off' (مبلغ تخفیف)]
 */
function biz_check_code($pdo, $code, $user_id, $type, $plan_id, $amount)
{
    $c = biz_find_code($pdo, $code);
    if (!$c || empty($c['is_active'])) return ['ok' => false, 'error' => 'کد تخفیف معتبر نیست.'];
    if (!empty($c['starts_at']) && strtotime($c['starts_at']) > time()) return ['ok' => false, 'error' => 'زمان استفاده از این کد هنوز شروع نشده است.'];
    if (!empty($c['ends_at']) && strtotime($c['ends_at']) < time()) return ['ok' => false, 'error' => 'مهلت استفاده از این کد به پایان رسیده است.'];
    if ($c['applies_to'] !== 'all' && $c['applies_to'] !== $type) {
        $names = ['plan' => 'خرید پلن', 'credit' => 'شارژ اعتبار', 'sms' => 'خرید پیامک'];
        return ['ok' => false, 'error' => 'این کد فقط برای ' . ($names[$c['applies_to']] ?? '') . ' قابل استفاده است.'];
    }
    if ((int)$c['plan_id'] > 0 && ($type !== 'plan' || (int)$plan_id !== (int)$c['plan_id'])) return ['ok' => false, 'error' => 'این کد برای پلن دیگری است.'];
    if ((int)$c['max_uses'] > 0 && (int)$c['used_count'] >= (int)$c['max_uses']) return ['ok' => false, 'error' => 'ظرفیت استفاده از این کد تکمیل شده است.'];
    if ((int)$c['per_user_limit'] > 0) {
        $u = $pdo->prepare("SELECT COUNT(*) FROM saas_discount_uses WHERE code_id=? AND user_id=?");
        $u->execute([(int)$c['id'], (int)$user_id]);
        if ((int)$u->fetchColumn() >= (int)$c['per_user_limit']) return ['ok' => false, 'error' => 'شما قبلاً از این کد استفاده کرده‌اید.'];
    }
    if ((int)$c['min_amount'] > 0 && $amount < (int)$c['min_amount']) return ['ok' => false, 'error' => 'حداقل مبلغ خرید برای این کد ' . number_format((int)$c['min_amount']) . ' تومان است.'];
    if ((int)$c['affiliate_id'] > 0) {
        $a = biz_affiliate($pdo, (int)$c['affiliate_id']);
        if (!$a || $a['status'] !== 'active') return ['ok' => false, 'error' => 'کد تخفیف معتبر نیست.'];
        $me = $pdo->prepare("SELECT phone FROM saas_users WHERE id=?");
        $me->execute([(int)$user_id]);
        if (biz_mobile($me->fetchColumn()) === $a['mobile']) return ['ok' => false, 'error' => 'استفاده از کد معرفی خودتان مجاز نیست.'];
    }
    $off = $c['kind'] === 'fixed' ? (int)$c['value'] : (int)floor($amount * min(100, (int)$c['value']) / 100);
    $off = max(0, min($amount, $off));
    return ['ok' => true, 'error' => '', 'code' => $c, 'off' => $off];
}

/**
 * محاسبه نهایی مبلغ یک خرید
 * @param string $type plan | credit | sms
 * @param int $base_amount مبلغ پایه (قیمت پلن، مبلغ شارژ یا تعداد پیامک × قیمت)
 */
function biz_quote($pdo, $user_id, $type, $base_amount, $plan = null, $code = '')
{
    $orig = (int)$base_amount;
    $auto_pct = 0;
    $after_auto = $orig;
    $renew_pct = 0;
    if ($type === 'plan' && $plan) {
        $pp = biz_plan_price($plan);
        $orig = $pp['original'];
        $after_auto = $pp['price'];
        $auto_pct = $pp['percent'];
        // تمدید زودهنگام همان پلن: تخفیف بیشتر (بین تخفیف پلن و تخفیف تمدید، هرکدام بیشتر باشد)
        $st = $pdo->prepare("SELECT id, plan_id, plan_expires_at FROM saas_users WHERE id=?");
        $st->execute([(int)$user_id]);
        $u = $st->fetch(\PDO::FETCH_ASSOC);
        if ($u) {
            $ro = biz_renew_offer($pdo, $u, (int)$plan['id']);
            if ($ro['percent'] > $auto_pct && $orig > 0) {
                $renew_pct = $ro['percent'];
                $auto_pct = $ro['percent'];
                $after_auto = max(1000, (int)(round($orig * (100 - $renew_pct) / 100 / 1000) * 1000));
            }
        }
    } else {
        $auto_pct = biz_auto_discount($pdo, $type);
        if ($auto_pct > 0) $after_auto = (int)round($orig * (100 - $auto_pct) / 100);
    }
    $res = ['original' => $orig, 'auto_percent' => $auto_pct, 'renew_percent' => $renew_pct, 'auto_off' => $orig - $after_auto, 'code_off' => 0,
            'code_id' => 0, 'code' => '', 'code_error' => '', 'affiliate_id' => 0, 'final' => $after_auto];
    $code = trim((string)$code);
    if ($code !== '' && $after_auto > 0) {
        $chk = biz_check_code($pdo, $code, $user_id, $type, $plan['id'] ?? 0, $after_auto);
        if ($chk['ok']) {
            $res['code_off'] = $chk['off'];
            $res['code_id'] = (int)$chk['code']['id'];
            $res['code'] = $chk['code']['code'];
            $res['affiliate_id'] = (int)$chk['code']['affiliate_id'];
            $res['final'] = $after_auto - $chk['off'];
        } else {
            $res['code_error'] = $chk['error'];
        }
    }
    // درگاه زرین‌پال حداقل ۱۰۰۰ تومان می‌پذیرد؛ مبلغ کمتر (غیر صفر) به ۱۰۰۰ گرد می‌شود
    if ($res['final'] > 0 && $res['final'] < 1000) $res['final'] = 1000;
    return $res;
}

// =============================================================================
// تکمیل پرداخت (مشترک بین بازگشت از درگاه و خرید صفر تومانی)
// =============================================================================
/**
 * @return array ['ok', 'message']
 */
function biz_complete_payment($pdo, $payment_id, $ref_code)
{
    $st = $pdo->prepare("SELECT * FROM saas_payments WHERE id=?");
    $st->execute([(int)$payment_id]);
    $p = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$p) return ['ok' => false, 'message' => 'پرداخت یافت نشد.'];
    $up = $pdo->prepare("UPDATE saas_payments SET status='paid', ref_code=?, paid_at=? WHERE id=? AND status='pending'");
    $up->execute([(string)$ref_code, biz_now(), (int)$p['id']]);
    if ($up->rowCount() === 0) return ['ok' => true, 'message' => 'این پرداخت قبلاً تأیید شده است.'];

    $uid = (int)$p['user_id'];
    $msg = '';
    $meta = json_decode((string)($p['meta'] ?? ''), true) ?: [];
    if ($p['type'] === 'plan' && (int)$p['plan_id'] > 0) {
        $plan = saas_get_plan($pdo, (int)$p['plan_id']);
        // اشتراک یک‌ساله (تمدید همان پلن: از تاریخ انقضای فعلی)
        saas_activate_plan($pdo, $uid, (int)$p['plan_id'], '', (int)$p['id'], false);
        $ue = $pdo->prepare("SELECT plan_expires_at FROM saas_users WHERE id=?");
        $ue->execute([$uid]);
        $exp_new = (string)$ue->fetchColumn();
        if ((int)$p['credit_tokens'] > 0) saas_add_credit($pdo, $uid, (int)$p['credit_tokens'], 'اعتبار پلن ' . ($plan['name'] ?? '') . (!empty($meta['inst']) ? ' (قسط ۱)' : ''), (int)$p['id']);
        $msg = 'پلن «' . ($plan['name'] ?? '') . '» تا ' . biz_jdate($exp_new) . ' فعال شد.';
        if (!empty($meta['inst']) && (int)$meta['inst'] > 1) {
            $list = array_map('intval', (array)($meta['list'] ?? []));
            $days = max(1, (int)($meta['days'] ?? 30));
            $pdo->prepare("UPDATE saas_inst_orders SET status='cancelled' WHERE user_id=? AND status='active'")->execute([$uid]);
            $pdo->prepare("INSERT INTO saas_inst_orders (user_id, plan_id, total, count, tokens_per, status, created_at) VALUES(?,?,?,?,?,'active',?)")
                ->execute([$uid, (int)$p['plan_id'], array_sum($list), count($list), (int)$p['credit_tokens'], biz_now()]);
            $oid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO saas_installments (order_id, user_id, seq, amount, due_at, status, payment_id, paid_at) VALUES(?,?,?,?,?,?,?,?)");
            foreach ($list as $i => $amt) {
                $first = $i === 0;
                $ins->execute([$oid, $uid, $i + 1, $amt, biz_now($i * $days * 86400), $first ? 'paid' : 'unpaid', $first ? (int)$p['id'] : 0, $first ? biz_now() : null]);
            }
            $msg .= ' قسط ۱ از ' . count($list) . ' پرداخت شد؛ قسط بعدی ' . biz_jdate(time() + $days * 86400) . '.';
        }
        if ((int)($p['setup_fee'] ?? 0) > 0) {
            biz_service_request($pdo, $uid, 'setup', 'راه‌اندازی پنل — پلن ' . ($plan['name'] ?? ''), (int)$p['id'], (int)$p['setup_fee']);
            $msg .= ' درخواست راه‌اندازی ثبت شد و همکاران ما با شما تماس می‌گیرند.';
        }
        biz_notify($pdo, $uid, '✅ پلن شما فعال شد', $msg, 'success', 'billing.php');
    } elseif ($p['type'] === 'inst') {
        $ids = array_map('intval', (array)($meta['inst_ids'] ?? []));
        $tokens = 0; $order_id = 0;
        foreach ($ids as $iid) {
            $u2 = $pdo->prepare("UPDATE saas_installments SET status='paid', payment_id=?, paid_at=? WHERE id=? AND user_id=? AND status='unpaid'");
            $u2->execute([(int)$p['id'], biz_now(), $iid, $uid]);
            if ($u2->rowCount() > 0) {
                $o = $pdo->prepare("SELECT o.* FROM saas_inst_orders o JOIN saas_installments i ON i.order_id=o.id WHERE i.id=?");
                $o->execute([$iid]);
                if ($ord = $o->fetch(\PDO::FETCH_ASSOC)) { $tokens += (int)$ord['tokens_per']; $order_id = (int)$ord['id']; }
            }
        }
        if ($tokens > 0) saas_add_credit($pdo, $uid, $tokens, 'اعتبار پلن (پرداخت قسط)', (int)$p['id']);
        $msg = count($ids) > 1 ? 'همه اقساط باقی‌مانده پرداخت شد.' : 'قسط شما پرداخت شد.';
        if ($order_id) {
            $left = $pdo->prepare("SELECT COUNT(*) FROM saas_installments WHERE order_id=? AND status='unpaid'");
            $left->execute([$order_id]);
            if ((int)$left->fetchColumn() === 0) {
                $pdo->prepare("UPDATE saas_inst_orders SET status='done', done_at=? WHERE id=?")->execute([biz_now(), $order_id]);
                $msg .= ' همه اقساط تسویه شد. 🎉';
            }
        }
        biz_notify($pdo, $uid, '✅ پرداخت قسط', $msg, 'success', 'billing.php#inst');
    } elseif ($p['type'] === 'setup') {
        biz_service_request($pdo, $uid, 'setup', (string)($meta['detail'] ?? 'راه‌اندازی پنل'), (int)$p['id'], (int)$p['amount_toman']);
        $msg = 'درخواست راه‌اندازی ثبت شد و همکاران ما به‌زودی با شما تماس می‌گیرند.';
        biz_notify($pdo, $uid, '🛠 درخواست راه‌اندازی ثبت شد', $msg, 'success', 'billing.php');
    } elseif ($p['type'] === 'domain') {
        $bot_id = (int)($meta['bot_id'] ?? 0);
        $dom = (string)($meta['domain'] ?? '');
        try {
            $pdo->prepare("UPDATE hd_bots SET custom_domain=?, domain_paid=1 WHERE id=? AND user_id=?")->execute([$dom, $bot_id, $uid]);
        } catch (\Throwable $e) { error_log('[BIZ] domain: ' . $e->getMessage()); }
        biz_service_request($pdo, $uid, 'domain', $dom, (int)$p['id'], (int)$p['amount_toman'], $bot_id);
        $msg = 'زیردامنه ' . $dom . ' ثبت شد. پس از تنظیم رکورد CNAME، ظرف حداکثر ۲۴ ساعت کاری فعال می‌شود.';
        biz_notify($pdo, $uid, '🌐 زیردامنه اختصاصی ثبت شد', $msg, 'success', 'bot.php?id=' . $bot_id . '&tab=install');
    } elseif ($p['type'] === 'sms') {
        $n = (int)$p['sms_count'];
        $pdo->prepare("UPDATE saas_users SET sms_balance = sms_balance + ? WHERE id=?")->execute([$n, $uid]);
        $msg = number_format($n) . ' پیامک به کیف پیامک شما اضافه شد.';
        biz_notify($pdo, $uid, '✉️ بسته پیامک فعال شد', $msg, 'success', 'billing.php#sms');
    } else {
        if ((int)$p['credit_tokens'] > 0) saas_add_credit($pdo, $uid, (int)$p['credit_tokens'], 'شارژ اعتبار — پرداخت #' . $p['id'], (int)$p['id']);
        $msg = number_format((int)$p['credit_tokens']) . ' تومان به اعتبار شما اضافه شد.';
    }

    // ثبت استفاده از کد تخفیف
    if ((int)$p['discount_code_id'] > 0) {
        try {
            $pdo->prepare("INSERT INTO saas_discount_uses (code_id, user_id, payment_id, amount_off, created_at) VALUES(?,?,?,?,?)")
                ->execute([(int)$p['discount_code_id'], $uid, (int)$p['id'], (int)$p['discount_amount'], biz_now()]);
            $pdo->prepare("UPDATE saas_discount_codes SET used_count = used_count + 1 WHERE id=?")->execute([(int)$p['discount_code_id']]);
        } catch (\Throwable $e) { error_log('[BIZ] discount use: ' . $e->getMessage()); }
    }

    // پورسانت همکار فروش
    try { biz_affiliate_commission($pdo, $p); } catch (\Throwable $e) { error_log('[BIZ] commission: ' . $e->getMessage()); }

    return ['ok' => true, 'message' => $msg];
}

// =============================================================================
// همکاری در فروش
// =============================================================================
function biz_affiliate($pdo, $id)
{
    $s = $pdo->prepare("SELECT * FROM saas_affiliates WHERE id=?");
    $s->execute([(int)$id]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function biz_affiliate_by_ref($pdo, $ref)
{
    $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$ref));
    if ($ref === '') return null;
    $s = $pdo->prepare("SELECT * FROM saas_affiliates WHERE ref_code=? AND status='active' LIMIT 1");
    $s->execute([$ref]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function biz_new_ref_code($pdo)
{
    for ($i = 0; $i < 20; $i++) {
        $c = 'R' . strtoupper(substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 6));
        $s = $pdo->prepare("SELECT COUNT(*) FROM saas_affiliates WHERE ref_code=?");
        $s->execute([$c]);
        $d = $pdo->prepare("SELECT COUNT(*) FROM saas_discount_codes WHERE code=?");
        $d->execute([$c]);
        if ((int)$s->fetchColumn() === 0 && (int)$d->fetchColumn() === 0) return $c;
    }
    return 'R' . time();
}

/** ساخت همکار جدید */
function biz_create_affiliate($pdo, $name, $mobile, $status = null)
{
    $mobile = biz_mobile($mobile);
    if ($mobile === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.'];
    $e = $pdo->prepare("SELECT id FROM saas_affiliates WHERE mobile=?");
    $e->execute([$mobile]);
    if ($id = $e->fetchColumn()) return ['ok' => true, 'id' => (int)$id, 'existing' => true];
    $s = biz_settings($pdo);
    if ($status === null) $status = !empty($s['aff_auto_approve']) ? 'active' : 'pending';
    $pdo->prepare("INSERT INTO saas_affiliates (name, mobile, ref_code, commission_percent, status, created_at) VALUES(?,?,?,?,?,?)")
        ->execute([mb_substr(trim($name), 0, 120), $mobile, biz_new_ref_code($pdo), (float)$s['aff_default_percent'], $status, biz_now()]);
    $id = (int)$pdo->lastInsertId();
    if ($status === 'active') biz_affiliate_ensure_code($pdo, $id);
    return ['ok' => true, 'id' => $id, 'existing' => false];
}

/** کد تخفیف اختصاصی همکار (همان کد معرف) */
function biz_affiliate_ensure_code($pdo, $aff_id)
{
    $a = biz_affiliate($pdo, $aff_id);
    if (!$a) return null;
    $c = biz_find_code($pdo, $a['ref_code']);
    if ($c) return $c;
    $pct = (int)biz_get($pdo, 'aff_customer_discount');
    $pdo->prepare("INSERT INTO saas_discount_codes (code, title, kind, value, applies_to, plan_id, max_uses, per_user_limit, min_amount, affiliate_id, used_count, is_active, created_at) VALUES(?,?,?,?,?,0,0,1,0,?,0,1,?)")
        ->execute([$a['ref_code'], 'کد همکار: ' . $a['name'], 'percent', max(0, min(90, $pct)), 'all', (int)$a['id'], biz_now()]);
    return biz_find_code($pdo, $a['ref_code']);
}

/** ثبت ورود از لینک همکار (کوکی) — در صفحه اصلی و ثبت‌نام صدا زده می‌شود */
function biz_capture_ref($pdo)
{
    $ref = $_GET['ref'] ?? '';
    if ($ref === '' || headers_sent()) return;
    $a = biz_affiliate_by_ref($pdo, $ref);
    if (!$a) return;
    $days = max(1, (int)biz_get($pdo, 'aff_cookie_days'));
    if (($_COOKIE['aff_ref'] ?? '') !== $a['ref_code']) {
        $pdo->prepare("UPDATE saas_affiliates SET clicks = clicks + 1 WHERE id=?")->execute([(int)$a['id']]);
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('aff_ref', $a['ref_code'], ['expires' => time() + $days * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['aff_ref'] = $a['ref_code'];
}

/** اتصال کاربر تازه ثبت‌نام‌شده به همکار معرف */
function biz_attach_referral($pdo, $user_id)
{
    $ref = $_COOKIE['aff_ref'] ?? '';
    if ($ref === '') return;
    $a = biz_affiliate_by_ref($pdo, $ref);
    if (!$a) return;
    $s = $pdo->prepare("SELECT phone, affiliate_id FROM saas_users WHERE id=?");
    $s->execute([(int)$user_id]);
    $u = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$u || (int)$u['affiliate_id'] > 0 || biz_mobile($u['phone']) === $a['mobile']) return;
    $pdo->prepare("UPDATE saas_users SET affiliate_id=? WHERE id=? AND affiliate_id=0")->execute([(int)$a['id'], (int)$user_id]);
}

/** محاسبه پورسانت یک پرداخت موفق */
function biz_affiliate_commission($pdo, $payment)
{
    if (empty(biz_get($pdo, 'aff_enabled'))) return;
    if (in_array((string)$payment['type'], ['setup', 'domain'], true)) return;   // خدمات فنی پورسانت ندارد
    $amount = (int)$payment['amount_toman'] - (int)($payment['setup_fee'] ?? 0);
    if ($amount <= 0) return;
    $uid = (int)$payment['user_id'];
    $u = $pdo->prepare("SELECT phone, affiliate_id FROM saas_users WHERE id=?");
    $u->execute([$uid]);
    $user = $u->fetch(\PDO::FETCH_ASSOC);
    if (!$user) return;

    $aff = null; $source = 'link';
    if ((int)$payment['discount_code_id'] > 0) {
        $c = $pdo->prepare("SELECT affiliate_id FROM saas_discount_codes WHERE id=?");
        $c->execute([(int)$payment['discount_code_id']]);
        $caff = (int)$c->fetchColumn();
        if ($caff > 0) {
            $aff = biz_affiliate($pdo, $caff);
            $source = 'code';
            // مشتری برای همیشه به همین همکار وصل می‌شود (اگر قبلاً معرف نداشته)
            if ($aff && (int)$user['affiliate_id'] === 0 && biz_mobile($user['phone']) !== $aff['mobile']) {
                $pdo->prepare("UPDATE saas_users SET affiliate_id=? WHERE id=? AND affiliate_id=0")->execute([(int)$aff['id'], $uid]);
            }
        }
    }
    if (!$aff && (int)$user['affiliate_id'] > 0) $aff = biz_affiliate($pdo, (int)$user['affiliate_id']);
    if (!$aff || $aff['status'] !== 'active') return;
    if (biz_mobile($user['phone']) === $aff['mobile']) return;   // خرید خود همکار

    $pct = (float)$aff['commission_percent'];
    $com = (int)floor($amount * $pct / 100);
    if ($com <= 0) return;
    $ins = $pdo->prepare("INSERT INTO saas_commissions (affiliate_id, user_id, payment_id, payment_type, base_amount, percent, amount, source, status, created_at) VALUES(?,?,?,?,?,?,?,?, 'approved', ?)");
    $ins->execute([(int)$aff['id'], $uid, (int)$payment['id'], (string)$payment['type'], $amount, $pct, $com, $source, biz_now()]);
}

/** خلاصه حساب همکار */
function biz_affiliate_stats($pdo, $aff_id)
{
    $one = function ($sql, $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $id = (int)$aff_id;
    $earned = $one("SELECT COALESCE(SUM(amount),0) FROM saas_commissions WHERE affiliate_id=? AND status<>'cancelled'", [$id]);
    $paid = $one("SELECT COALESCE(SUM(amount),0) FROM saas_aff_payouts WHERE affiliate_id=?", [$id]);
    return [
        'signups'   => $one("SELECT COUNT(*) FROM saas_users WHERE affiliate_id=?", [$id]),
        'sales'     => $one("SELECT COUNT(*) FROM saas_commissions WHERE affiliate_id=? AND status<>'cancelled'", [$id]),
        'sales_sum' => $one("SELECT COALESCE(SUM(base_amount),0) FROM saas_commissions WHERE affiliate_id=? AND status<>'cancelled'", [$id]),
        'earned'    => $earned,
        'paid'      => $paid,
        'balance'   => $earned - $paid,
    ];
}

// =============================================================================
// تصویر معرفی پلن (ابعاد ثابت ۸۰۰×۳۰۰)
// =============================================================================
define('BIZ_BANNER_W', 800);
define('BIZ_BANNER_H', 300);

function biz_save_plan_banner($file, $plan_id)
{
    if (empty($file['tmp_name']) || ($file['error'] ?? 4) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'فایلی ارسال نشد.'];
    if ($file['size'] > 3 * 1024 * 1024) return ['ok' => false, 'error' => 'حجم تصویر نباید بیشتر از ۳ مگابایت باشد.'];
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!$info || !isset($allowed[$info['mime']])) return ['ok' => false, 'error' => 'فرمت تصویر باید JPG، PNG یا WEBP باشد.'];
    $dir = rtrim(AICHAT_UPLOAD_DIR, '/') . '/plan_banners/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['ok' => false, 'error' => 'پوشه آپلود قابل ساخت نیست.'];
    $base = 'plan' . (int)$plan_id . '_' . bin2hex(random_bytes(5));

    // برش و تغییر اندازه دقیق به ۸۰۰×۳۰۰ (در صورت وجود GD)
    $W = BIZ_BANNER_W; $H = BIZ_BANNER_H;
    $load = ['image/png' => 'imagecreatefrompng', 'image/jpeg' => 'imagecreatefromjpeg', 'image/webp' => 'imagecreatefromwebp'][$info['mime']];
    if (function_exists('imagecreatetruecolor') && function_exists($load)) {
        $src = @$load($file['tmp_name']);
        if ($src) {
            [$sw, $sh] = [$info[0], $info[1]];
            $ratio = $W / $H;
            if ($sw / $sh > $ratio) { $cw = (int)round($sh * $ratio); $ch = $sh; $cx = (int)(($sw - $cw) / 2); $cy = 0; }
            else { $cw = $sw; $ch = (int)round($sw / $ratio); $cx = 0; $cy = (int)(($sh - $ch) / 2); }
            $dst = imagecreatetruecolor($W, $H);
            $keep_alpha = $info['mime'] === 'image/png';
            if ($keep_alpha) { imagealphablending($dst, false); imagesavealpha($dst, true); }
            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $W, $H, $cw, $ch);
            $name = $base . ($keep_alpha ? '.png' : '.jpg');
            $ok = $keep_alpha ? imagepng($dst, $dir . $name, 6) : imagejpeg($dst, $dir . $name, 86);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) { @chmod($dir . $name, 0644); return ['ok' => true, 'url' => rtrim(AICHAT_BASE_URL, '/') . '/uploads/plan_banners/' . $name]; }
        }
    }
    // بدون GD: همان فایل ذخیره می‌شود و نمایش با نسبت ثابت (برش CSS) انجام می‌شود
    $name = $base . '.' . $allowed[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) return ['ok' => false, 'error' => 'ذخیره فایل ناموفق بود.'];
    @chmod($dir . $name, 0644);
    return ['ok' => true, 'url' => rtrim(AICHAT_BASE_URL, '/') . '/uploads/plan_banners/' . $name];
}

/**
 * سربرگ کارت پلن: تصویر طرح با ابعاد ثابت (عنوان و توضیحات روی تصویر یا داخل خود طرح)
 * @param string $theme dark | light
 */
function biz_plan_head_html($plan, $theme = 'light')
{
    $name = biz_h($plan['name'] ?? '');
    $desc = trim((string)($plan['description'] ?? ''));
    $url = (string)($plan['banner_url'] ?? '');
    if ($url === '') return '';
    $overlay = !isset($plan['banner_overlay']) || !empty($plan['banner_overlay']);
    $h = '<div class="plan-banner" style="position:relative;width:100%;aspect-ratio:' . BIZ_BANNER_W . '/' . BIZ_BANNER_H . ';border-radius:12px;overflow:hidden;margin-bottom:14px;background:#0f172a url(\'' . biz_h($url) . '\') center/cover no-repeat">';
    if ($overlay) {
        $h .= '<div style="position:absolute;inset:0;background:linear-gradient(0deg,rgba(0,0,0,.72) 0%,rgba(0,0,0,.25) 55%,rgba(0,0,0,0) 100%)"></div>'
            . '<div style="position:absolute;right:14px;left:14px;bottom:10px;text-align:right;color:#fff">'
            . '<div style="font-size:18px;font-weight:800;line-height:1.5;text-shadow:0 1px 3px rgba(0,0,0,.5)">' . $name . '</div>'
            . ($desc !== '' ? '<div style="font-size:12px;opacity:.92;line-height:1.7;max-height:3.4em;overflow:hidden">' . biz_h($desc) . '</div>' : '')
            . '</div>';
    } else {
        $h .= '<span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)">' . $name . ($desc !== '' ? ' — ' . biz_h($desc) : '') . '</span>';
    }
    return $h . '</div>';
}

/** HTML قیمت با تخفیف (خط‌خورده) */
function biz_price_html($plan, $currency_style = '')
{
    $pp = biz_plan_price($plan);
    if ($pp['original'] <= 0) return 'رایگان';
    $h = '<span style="white-space:nowrap;font-size:1em;color:inherit;font-weight:inherit">' . number_format($pp['price']) . '<small style="font-size:.45em;font-weight:400;' . $currency_style . '"> تومان</small></span>';
    if ($pp['percent'] > 0) {
        $h .= ' <span style="text-decoration:line-through;opacity:.55;font-size:.5em;font-weight:500;white-space:nowrap">' . number_format($pp['original']) . '</span>';
    }
    if ($pp['percent'] > 0) {
        $h .= '<div style="font-size:12px;font-weight:700;color:#dc2626;margin-top:4px">🔥 ' . $pp['percent'] . '٪ تخفیف' . ($pp['until'] ? ' تا ' . biz_jdate($pp['until']) : '') . '</div>';
    }
    $n = (int)($plan['installments'] ?? 1);
    if ($n > 1) {
        $list = biz_split_installments($pp['price'], $n);
        $h .= '<div style="font-size:12.5px;font-weight:600;margin-top:4px;opacity:.9">یا ' . $n . ' قسط ' . number_format($list[0]) . ' تومانی</div>';
    }
    return $h;
}

// =============================================================================
// کارهای زمان‌بندی‌شده: یادآوری انقضا (کاربران و مخاطبان چت‌بات)
// =============================================================================
/** اجرای خودکار حداکثر ساعتی یک بار (با قفل دیتابیسی) — بعد از ارسال پاسخ به مرورگر */
function biz_maybe_run_cron($pdo)
{
    static $registered = false;
    if ($registered) return;
    $registered = true;
    $last = (string)biz_get($pdo, 'cron_last_run');
    if ($last !== '' && strtotime($last) > time() - 3600) return;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        @set_time_limit(120);
        try { biz_run_cron($pdo); } catch (\Throwable $e) { error_log('[BIZ] cron: ' . $e->getMessage()); }
    });
}

function biz_run_cron($pdo, $force = false)
{
    // قفل: فقط یک اجرا در هر ساعت
    $last = (string)biz_settings($pdo, true)['cron_last_run'];
    if (!$force && $last !== '' && strtotime($last) > time() - 3600) return ['skipped' => true];
    biz_set($pdo, 'cron_last_run', biz_now());
    $r = ['users' => biz_cron_user_reminders($pdo), 'members' => 0, 'usd' => null];
    if (!empty(biz_get($pdo, 'usd_auto'))) {
        $upd = (string)biz_get($pdo, 'usd_updated');
        if ($upd === '' || strtotime($upd) < time() - 6 * 3600) {   // هر ۶ ساعت
            try { $r['usd'] = biz_update_usd($pdo); } catch (\Throwable $e) { error_log('[BIZ] usd: ' . $e->getMessage()); }
        }
    }
    // قیمت مدل‌ها از سرویس‌دهنده هر مدل (روزی یک بار — نسخه ۵۴)
    $pu = (string)biz_get($pdo, 'price_updated');
    if ($pu === '' || strtotime($pu) < time() - 24 * 3600) {
        try { $r['prices'] = biz_models_refresh_prices($pdo, true); } catch (\Throwable $e) { error_log('[BIZ] prices: ' . $e->getMessage()); }
    }
    if (!function_exists('hd_cron_member_reminders') && is_file(__DIR__ . '/hamdam_lib.php')) require_once __DIR__ . '/hamdam_lib.php';
    if (function_exists('ex_cron')) $r['extras'] = ex_cron($pdo);
    if (function_exists('comm_otp_cleanup')) comm_otp_cleanup($pdo);
    if (function_exists('sup_sweep')) {
        try { $r['sup'] = sup_sweep($pdo); } catch (\Throwable $e) { error_log('[BIZ] sup sweep: ' . $e->getMessage()); }
    }
    if (function_exists('hd_cron_member_reminders')) {
        try { $r['members'] = hd_cron_member_reminders($pdo); } catch (\Throwable $e) { error_log('[BIZ] members cron: ' . $e->getMessage()); }
    }
    return $r;
}

/** مرحله یادآوری مناسب برای n روز مانده (کوچک‌ترین مرحله ≥ n) */
function biz_stage_for($days_left, $max_days = null)
{
    $best = null;
    foreach (BIZ_REMIND_STAGES as $s) {
        if ($max_days !== null && $s >= $max_days) continue;   // مرحله‌ای بزرگ‌تر از کل مدت اشتراک معنی ندارد
        if ($days_left <= $s && ($best === null || $s < $best)) $best = $s;
    }
    return $best;
}

/** یادآوری تمدید اشتراک کاربران (پیامک + اعلان پنل) — بدون نام سامانه؛ همراه با پیشنهاد تخفیف تمدید زودهنگام */
function biz_cron_user_reminders($pdo)
{
    $n = biz_cron_inst_reminders($pdo);
    $from = biz_now(-(BIZ_GRACE_DAYS + 2) * 86400);
    $max_tier = 0;
    foreach (biz_renew_tiers($pdo) as [$d, $p]) $max_tier = max($max_tier, $d);
    $to = biz_now((max(31, $max_tier + 1)) * 86400);
    $s = $pdo->prepare("SELECT id, full_name, phone, plan_id, plan_expires_at FROM saas_users WHERE status='active' AND plan_id IS NOT NULL AND plan_id>0 AND plan_expires_at IS NOT NULL AND plan_expires_at BETWEEN ? AND ?");
    $s->execute([$from, $to]);
    $sms_on = !empty(biz_get($pdo, 'user_remind_sms'));
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $u) {
        $sub = biz_subscription($u, $pdo);
        $ref = substr((string)$u['plan_expires_at'], 0, 10);
        $name = trim((string)$u['full_name']) ?: 'کاربر';
        $stage = null; $title = ''; $body = ''; $sms = ''; $level = 'warn'; $tpl = ''; $tv = ['name' => $name];
        if ($sub['state'] === 'active') {
            $offer = biz_renew_offer($pdo, $u);
            $dl = max(1, (int)$sub['days_left']);
            $stage = biz_stage_for($sub['days_left']);
            if ($stage !== null) {
                $title = '⏳ ' . $dl . ' روز تا پایان اشتراک';
                $body = 'اشتراک پنل شما ' . $dl . ' روز دیگر (' . biz_jdate($u['plan_expires_at']) . ') به پایان می‌رسد. برای جلوگیری از توقف سرویس، از بخش «اعتبار و پرداخت» تمدید کنید.';
                $sms = 'x'; $tpl = 'user_exp_soon';
                $tv += ['days' => $dl, 'date' => biz_jdate($u['plan_expires_at']), 'offer' => ''];
                if ($offer['percent'] > 0) {
                    $body .= ' اگر تا ' . biz_jdate($offer['until']) . ' تمدید کنید ' . $offer['percent'] . '٪ تخفیف می‌گیرید.';
                    $tv['offer'] = ' تمدید تا ' . biz_jdate($offer['until']) . ' با ' . $offer['percent'] . '٪ تخفیف.';
                }
                if ($dl <= 3) $level = 'danger';
            } elseif ($offer['percent'] > 0) {
                // شروع هر پله تخفیف تمدید: یک اعلان (بدون پیامک) برای ایجاد انگیزه
                $stage = 'offer' . $offer['percent'];
                $level = 'success';
                $title = '🎁 ' . $offer['percent'] . '٪ تخفیف برای تمدید زودهنگام';
                $body = 'با تمدید اشتراک تا ' . biz_jdate($offer['until']) . '، ' . $offer['percent'] . '٪ تخفیف می‌گیرید'
                      . ($offer['next'] ? ' (پس از آن ' . $offer['next'][1] . '٪).' : '.') . ' روزهای باقی‌مانده اشتراک فعلی شما هم حفظ می‌شود.';
                $sms = '';
            } else continue;
        } elseif ($sub['state'] === 'grace') {
            $stage = 'grace';
            $title = '⚠️ اشتراک شما به پایان رسید';
            $body = 'اشتراک پنل شما منقضی شده است. سرویس فقط تا ' . biz_jdate($sub['grace_until']) . ' (۷ روز مهلت) فعال می‌ماند و پس از آن ویجت و چت‌بات‌های شما متوقف می‌شوند.';
            $sms = 'x'; $tpl = 'user_exp_grace'; $tv['date'] = biz_jdate($sub['grace_until']);
            $level = 'danger';
        } elseif ($sub['state'] === 'expired') {
            $stage = 'stopped';
            $title = '⛔ سرویس شما متوقف شد';
            $body = 'مهلت تمدید اشتراک به پایان رسید و ویجت و چت‌بات‌های شما متوقف شده‌اند. اطلاعات شما محفوظ است و با تمدید، سرویس فوراً فعال می‌شود.';
            $sms = 'x'; $tpl = 'user_exp_stopped';
            $level = 'danger';
        } else continue;
        if (biz_was_sent($pdo, 'user_exp', (int)$u['id'], $ref, (string)$stage)) continue;
        biz_once($pdo, 'user_exp', (int)$u['id'], $ref, (string)$stage);
        biz_notify($pdo, (int)$u['id'], $title, $body, $level, 'billing.php');
        $tv['link'] = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/user/billing.php';   // لینک تمدید در پیامک
        if ($sms_on && $sms !== '' && $tpl !== '') biz_sms_platform_tpl($pdo, $u['phone'], $tpl, $tv, 'user_exp', (int)$u['id']);
        $n++;
    }
    return $n;
}

/** یادآوری اقساط باقی‌مانده خریدهای قسطی قدیمی */
function biz_cron_inst_reminders($pdo)
{
    try {
        $s = $pdo->prepare("SELECT i.*, u.full_name, u.phone FROM saas_installments i JOIN saas_inst_orders o ON o.id=i.order_id JOIN saas_users u ON u.id=i.user_id
            WHERE i.status='unpaid' AND o.status='active' AND u.status='active' AND i.due_at < ? ORDER BY i.due_at ASC LIMIT 1000");
        $s->execute([biz_now(3 * 86400 + 3600)]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return 0; }
    $sms_on = !empty(biz_get($pdo, 'user_remind_sms'));
    $n = 0;
    foreach ($rows as $r) {
        $due = strtotime($r['due_at']);
        $late = (int)floor((time() - $due) / 86400);
        $name = trim((string)$r['full_name']) ?: 'کاربر';
        $amt = number_format((int)$r['amount']) . ' تومان';
        $jd = biz_jdate($r['due_at']);
        $grace = biz_jdate($due + BIZ_GRACE_DAYS * 86400);
        if ($due > time()) {
            $stage = 'pre'; $level = 'warn';
            $title = '📅 سررسید قسط نزدیک است';
            $body = 'قسط ' . (int)$r['seq'] . ' اشتراک شما به مبلغ ' . $amt . ' در تاریخ ' . $jd . ' سررسید می‌شود. از بخش «اعتبار و پرداخت» پرداخت کنید.';
            $tpl = 'inst_pre'; $tv = ['name' => $name, 'seq' => (int)$r['seq'], 'amount' => $amt, 'date' => $jd];
        } elseif ($late < BIZ_GRACE_DAYS) {
            $stage = 'late'; $level = 'danger';
            $title = '⚠️ قسط شما سررسید شده است';
            $body = 'قسط ' . (int)$r['seq'] . ' به مبلغ ' . $amt . ' پرداخت نشده است. اگر تا ' . $grace . ' پرداخت نشود، ویجت و چت‌بات‌های شما موقتاً متوقف می‌شوند.';
            $tpl = 'inst_late'; $tv = ['name' => $name, 'seq' => (int)$r['seq'], 'amount' => $amt, 'date' => $grace];
        } else {
            $stage = 'blocked'; $level = 'danger';
            $title = '⛔ سرویس شما به دلیل قسط پرداخت‌نشده متوقف شد';
            $body = 'قسط ' . (int)$r['seq'] . ' به مبلغ ' . $amt . ' بیش از ۷ روز عقب افتاده است. با پرداخت، سرویس فوراً فعال می‌شود. اطلاعات شما محفوظ است.';
            $tpl = 'inst_blocked'; $tv = ['name' => $name, 'seq' => (int)$r['seq']];
        }
        if (biz_was_sent($pdo, 'inst', (int)$r['id'], '', $stage)) continue;
        biz_once($pdo, 'inst', (int)$r['id'], '', $stage);
        biz_notify($pdo, (int)$r['user_id'], $title, $body, $level, 'billing.php#inst');
        if ($sms_on) biz_sms_platform_tpl($pdo, $r['phone'], $tpl, $tv, 'inst', (int)$r['user_id']);
        $n++;
    }
    return $n;
}

// =============================================================================
// مدل‌های هوش مصنوعی (متن / تصویر / ویدیو)، ضریب هزینه، مدل‌های جایگزین
// =============================================================================
/** دسته‌های مدل */
function biz_model_categories()
{
    return ['text' => '💬 مدل‌های متنی', 'stt' => '🎙 صوت به متن', 'tts' => '🔊 متن به صوت', 'image' => '🖼 ساخت تصویر', 'video' => '🎬 ساخت ویدیو', 'agent' => '🤖 ربات و ساخت ایجنت'];
}

/**
 * همه مدل‌ها (اختیاری: فقط فعال / یک دسته)
 * @param bool $with_children مدل‌های جایگزینِ تعریف‌شده زیر یک مدل اصلی هم برگردانده شوند؟ (پیش‌فرض: خیر)
 */
function biz_models($pdo, $active_only = true, $category = null, $with_children = false)
{
    try {
        $rows = $pdo->query("SELECT * FROM saas_ai_models" . ($active_only ? " WHERE is_active=1" : "") . " ORDER BY sort_order ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['category'] = isset(biz_model_categories()[$r['category'] ?? '']) ? $r['category'] : 'text';
        $r['multiplier'] = max(0.01, (float)($r['multiplier'] ?? 1));
        $r['parent_id'] = (int)($r['parent_id'] ?? 0);
    }
    unset($r);
    if (!$with_children) $rows = array_values(array_filter($rows, fn($m) => $m['parent_id'] === 0));
    // سرویس‌دهنده‌ای که در «تنظیمات API» خاموش است: مدل‌هایش هم غیرفعال حساب می‌شوند
    if ($active_only) {
        $cfg = saas_get_api_config($pdo);
        $rows = array_values(array_filter($rows, fn($m) => $m['provider'] === 'openrouter' ? !empty($cfg['openrouter_enabled']) : !empty($cfg['avalai_enabled'])));
    }
    if ($category !== null) $rows = array_values(array_filter($rows, fn($m) => $m['category'] === $category));
    return $rows;
}

function biz_model_by_id($pdo, $id)
{
    foreach (biz_models($pdo, false, null, true) as $m) if ((int)$m['id'] === (int)$id) return $m;
    return null;
}

/**
 * مدل‌های مجاز یک پلن
 *  - نسخه ۵۹: همه مدل‌های فعال، به‌جز مدل‌هایی که مدیر برای این پلن برداشته (blocked_models) — مدل تازه خودکار اضافه می‌شود
 *  - پلن دلخواه کاربر: فقط مدل‌هایی که خودش انتخاب کرده (allowed_models)
 * @param string $category text | image | video
 * @param string|null $use   widget | bot | free (فقط مدل‌های متنی مخصوص آن کاربرد)
 */
function biz_plan_models($pdo, $plan, $category = 'text', $use = null)
{
    if (function_exists('mx_schema')) mx_schema($pdo);
    $all = biz_models($pdo, true, $category);
    $ids = array_filter(array_map('intval', explode(',', (string)($plan['allowed_models'] ?? ''))));
    if ($ids) $all = array_values(array_filter($all, fn($m) => in_array((int)$m['id'], $ids, true)));
    $bl = array_filter(array_map('intval', explode(',', (string)($plan['blocked_models'] ?? ''))));
    if ($bl) $all = array_values(array_filter($all, fn($m) => !in_array((int)$m['id'], $bl, true)));
    if ($use !== null && $category === 'text') {
        $col = 'use_' . $use;
        $all = array_values(array_filter($all, fn($m) => !empty($m[$col])));
    }
    return $all;
}

/** مدل نهایی (انتخاب کاربر اگر مجاز باشد، وگرنه پیش‌فرض پلن، وگرنه اولین مدل مجاز) */
function biz_resolve_model($pdo, $plan, $chosen_id, $use = 'widget', $category = 'text')
{
    $list = biz_plan_models($pdo, $plan, $category, $use);
    if (!$list) return null;
    foreach ($list as $m) if ((int)$m['id'] === (int)$chosen_id) return $m;
    foreach ($list as $m) if ((int)$m['id'] === (int)($plan['default_model'] ?? 0)) return $m;
    return $list[0];
}

/** آیا شناسه مدل برای این پلن/کاربرد مجاز است؟ (۰ = پیش‌فرض) */
function biz_model_allowed($pdo, $plan, $id, $use = 'widget', $category = 'text')
{
    $id = (int)$id;
    if ($id === 0) return true;
    foreach (biz_plan_models($pdo, $plan, $category, $use) as $m) if ((int)$m['id'] === $id) return true;
    return false;
}

/** نمایش ضریب (×۲، ×۱٫۵) — قدیمی؛ برای سازگاری */
function biz_mult_label($m)
{
    $v = is_array($m) ? (float)$m['multiplier'] : (float)$m;
    return '×' . rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/** برچسب هزینه مدل برای کاربران (تومان) */
function biz_model_cost_label($pdo, $m)
{
    if (!is_array($m)) return '';
    $p = biz_model_toman_prices($pdo, $m);
    $cat = $m['category'] ?? 'text';
    if (biz_price_is_token_cat($cat)) return '≈ ' . biz_toman_fmt($p['per1k']) . ' تومان / ۱۰۰۰ توکن';
    return '≈ ' . biz_toman_fmt($p['unit']) . ' تومان ' . (biz_price_units()[$cat]['label'] ?? '');
}

/**
 * «وزن» هزینه مدل برای مقایسه مدل‌ها با هم (نسبت هزینه در سهمیه پیام مخاطبان چت‌بات)
 * = تومان تقریبی هر ۱۰۰۰ توکن
 */
function biz_model_factor($pdo, $model)
{
    if (!$model) return max(0.01, biz_price_fallback_1k($pdo));
    return max(0.0001, (float)biz_model_toman_prices($pdo, $model)['per1k']);
}

/**
 * فیلد انتخاب مدل (برای پنل کاربر)
 * @return string HTML (خالی اگر مدلی تعریف نشده باشد)
 */
function biz_model_select_html($pdo, $plan, $selected_id, $name = 'ai_model_id', $use = 'widget', $category = 'text')
{
    $list = biz_plan_models($pdo, $plan, $category, $use);
    if (!$list) return '';
    $cur = biz_resolve_model($pdo, $plan, (int)$selected_id, $use, $category);
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $id = $h($name);
    $list = function_exists('biz_models_by_price') ? biz_models_by_price($pdo, $list) : $list;   // نسخه ۷۶: ارزان‌ترین اول
    $o = '<select name="' . $id . '" id="' . $id . '_sel" onchange="var d=this.options[this.selectedIndex].getAttribute(\'data-d\');document.getElementById(\'' . $id . '_desc\').textContent=d||\'\'">';
    $desc = '';
    foreach ($list as $m) {
        $sel = $cur && (int)$cur['id'] === (int)$m['id'];
        if ($sel) $desc = (string)$m['description'];
        $o .= '<option value="' . (int)$m['id'] . '" data-d="' . $h($m['description']) . '"' . ($sel ? ' selected' : '') . '>'
            . $h($m['title']) . ' — ' . biz_model_cost_label($pdo, $m)
            . ((int)$m['id'] === (int)($plan['default_model'] ?? 0) ? ' (پیش‌فرض)' : '') . '</option>';
    }
    $o .= '</select><div id="' . $id . '_desc" style="font-size:12px;color:#6b7280;margin-top:6px;line-height:1.9">' . $h($desc) . '</div>';
    $o .= '<div style="font-size:11.5px;color:#9ca3af;margin-top:4px;line-height:1.9">هزینه هر پاسخ به اندازه متن پرسش و پاسخ (توکن) است و به تومان از اعتبار شما کم می‌شود؛ هر ۱۰۰۰ توکن تقریباً ۷۵۰ کلمه است.</div>';
    return $o;
}

/** تنظیمات فراخوانی AI برای یک مدل */
function biz_cfg_for_model($cfg, $model)
{
    if (!$model) return $cfg;
    if ($model['provider'] === 'openrouter') { $cfg['openrouter_model'] = $model['model_id']; $cfg['provider_priority'] = 'openrouter'; }
    else { $cfg['avalai_model'] = $model['model_id']; $cfg['provider_priority'] = 'avalai'; }
    return $cfg;
}

/** ارزش دلاری هر ۱۰۰۰ توکن اعتبار (پایه ×۱) */
function biz_credit_usd_1k($pdo)
{
    $s = biz_settings($pdo);
    if ((float)$s['credit_usd_1k'] > 0) return (float)$s['credit_usd_1k'];   // حالت دلاری
    $rate = (float)$s['usd_rate'];
    $cfg = saas_get_api_config($pdo);
    return $rate > 0 ? (float)$cfg['cost_per_1k_tokens'] / $rate : 0.0;
}

/**
 * هزینه یک فراخوانی مدل متنی به «تومان» (قابل کسر از اعتبار کاربر):
 * توکن ورودی × قیمت ورودی + توکن خروجی × قیمت خروجی (قیمت دلاری مرجع × نرخ دلار × درصد سود)
 * اگر مدل جایگزین پاسخ داده باشد، قیمت همان مدل حساب می‌شود.
 */
function biz_charge_tokens($pdo, $model, $ai)
{
    if (!empty($ai['model_row']) && is_array($ai['model_row'])) $model = $ai['model_row'];
    $in = (int)($ai['prompt_tokens'] ?? 0);
    $out = (int)($ai['completion_tokens'] ?? 0);
    $total = (int)($ai['tokens'] ?? 0);
    if ($in + $out <= 0 && $total > 0) { $in = (int)round($total * 0.75); $out = $total - $in; }
    elseif ($total > $in + $out) $in += $total - $in - $out;   // توکن‌های استدلال و ...
    return biz_price_text_cost($pdo, $model, $in, $out);
}

// ---------------------------------------------------------------------------
// سلامت مدل‌ها و جایگزینی خودکار
// ---------------------------------------------------------------------------
/** آیا مدل الان در دسترس فرض می‌شود؟ */
function biz_model_up($m)
{
    return empty($m['down_until']) || strtotime((string)$m['down_until']) <= time();
}

/** زنجیره مدل: خود مدل + جایگزین‌ها به ترتیب (فقط فعال و هم‌دسته) */
function biz_model_chain($pdo, $model)
{
    if (!$model) return [];
    $all = [];
    foreach (biz_models($pdo, true, $model['category'] ?? 'text', true) as $m) $all[(int)$m['id']] = $m;
    $chain = [(int)$model['id'] => $model];
    foreach (array_filter(array_map('intval', explode(',', (string)($model['fallbacks'] ?? '')))) as $fid) {
        if (isset($all[$fid]) && !isset($chain[$fid])) $chain[$fid] = $all[$fid];
    }
    return array_values($chain);
}

/** مدلی که الان به‌جای این مدل پاسخ می‌دهد (برای تیک سبز در پنل مدیریت) */
function biz_model_serving($pdo, $model)
{
    foreach (biz_model_chain($pdo, $model) as $m) if (biz_model_up($m)) return $m;
    return null;
}

/** ثبت موفقیت / خطای یک مدل (خطا: کنار گذاشتن موقت با افزایش تدریجی زمان) */
function biz_model_mark($pdo, $id, $ok, $error = '')
{
    try {
        if ($ok) {
            $pdo->prepare("UPDATE saas_ai_models SET down_until=NULL, fail_count=0, last_ok=? WHERE id=?")->execute([biz_now(), (int)$id]);
        } else {
            $s = $pdo->prepare("SELECT fail_count FROM saas_ai_models WHERE id=?");
            $s->execute([(int)$id]);
            $fc = (int)$s->fetchColumn() + 1;
            $min = min(30, 2 ** min(5, $fc - 1));   // ۱، ۲، ۴، ۸، ۱۶، ۳۰ دقیقه
            $pdo->prepare("UPDATE saas_ai_models SET fail_count=?, down_until=?, last_fail=?, last_error=? WHERE id=?")
                ->execute([$fc, biz_now($min * 60), biz_now(), mb_substr((string)$error, 0, 290), (int)$id]);
        }
    } catch (\Throwable $e) { error_log('[BIZ] model mark: ' . $e->getMessage()); }
}

/** فراخوانی مستقیم سرویس‌دهنده یک مدل متنی (بدون جابه‌جایی سرویس‌دهنده) */
function biz_provider_chat($cfg, $model, $messages, $max_tokens, $temperature)
{
    if (!function_exists('_aichat_call_avalai')) return aichat_call_ai(biz_cfg_for_model($cfg, $model), $messages, $max_tokens, $temperature);
    if ($model['provider'] === 'openrouter') {
        if (empty($cfg['openrouter_api_key'])) return ['ok' => false, 'error' => 'کلید OpenRouter تنظیم نشده است.'];
        $cfg['openrouter_model'] = $model['model_id'];
        return _aichat_call_openrouter($cfg, $messages, $max_tokens, $temperature);
    }
    if (empty($cfg['avalai_api_key'])) return ['ok' => false, 'error' => 'کلید AvalAI تنظیم نشده است.'];
    $cfg['avalai_model'] = $model['model_id'];
    return _aichat_call_avalai($cfg, $messages, $max_tokens, $temperature);
}

/**
 * فراخوانی هوش مصنوعی با مدل انتخابی و جایگزینی خودکار:
 * اگر مدل در دسترس نباشد (قطعی، محدودیت تعداد درخواست و...) مدل‌های جایگزین به ترتیب امتحان می‌شوند
 * و به‌محض در دسترس شدن مدل اصلی، دوباره از همان استفاده می‌شود.
 * خروجی: همان خروجی aichat_call_ai + 'model_row' (مدلی که واقعاً پاسخ داد؛ برای محاسبه هزینه)
 */
function biz_ai_call($pdo, $cfg, $model, $messages, $max_tokens = 600, $temperature = 0.7)
{
    if (!$model) $model = biz_system_model($pdo);   // مدل کارهای داخلی سامانه (صفحه مدل‌ها)
    if (!$model || !function_exists('_aichat_call_avalai')) {
        $r = aichat_call_ai(biz_cfg_for_model($cfg, $model), $messages, $max_tokens, $temperature);
        $r['model_row'] = $model;
        return $r;
    }
    $chain = biz_model_chain($pdo, $model);
    // اول مدل‌های در دسترس به ترتیب، سپس (اگر همه کنار گذاشته شده‌اند) بقیه به عنوان آخرین تلاش
    $order = array_merge(array_filter($chain, 'biz_model_up'), array_filter($chain, fn($m) => !biz_model_up($m)));
    $last = '';
    $tries = 0;
    foreach ($order as $m) {
        if (++$tries > 6) break;
        $r = biz_provider_chat($cfg, $m, $messages, $max_tokens, $temperature);
        if (!empty($r['ok'])) {
            if (!biz_model_up($m) || (int)($m['fail_count'] ?? 0) > 0 || empty($m['last_ok']) || strtotime((string)$m['last_ok']) < time() - 600) biz_model_mark($pdo, (int)$m['id'], true);
            $r['model_row'] = $m;
            if (function_exists('mcap_count')) mcap_count($pdo, (int)$m['id']);
            if ((int)$m['id'] !== (int)$model['id']) error_log('[AI] fallback used: ' . $model['model_id'] . ' -> ' . $m['model_id']);
            return $r;
        }
        $last = (string)($r['error'] ?? 'خطا');
        biz_model_mark($pdo, (int)$m['id'], false, $last);
    }
    error_log('[AI] all models failed for ' . $model['model_id'] . ': ' . $last);
    return ['ok' => false, 'error' => defined('AICHAT_AI_DOWN_MSG') ? AICHAT_AI_DOWN_MSG : 'سرویس در دسترس نیست.', 'detail' => $last, 'model_row' => $model];
}

/** مدل‌های جایگزینِ زیرمجموعه یک مدل اصلی (به ترتیب زنجیره) */
function biz_model_children($pdo, $model)
{
    if (!$model || empty($model['id'])) return [];
    $kids = [];
    foreach (biz_models($pdo, false, null, true) as $m) if ($m['parent_id'] === (int)$model['id']) $kids[(int)$m['id']] = $m;
    $out = [];
    foreach (array_filter(array_map('intval', explode(',', (string)($model['fallbacks'] ?? '')))) as $fid) {
        if (isset($kids[$fid])) { $out[] = $kids[$fid]; unset($kids[$fid]); }
    }
    return array_merge($out, array_values($kids));
}

/** مدل متنی پیش‌فرض کارهای داخلی (خلاصه‌سازی، بازبینی، انتخاب موضوع ...) — null = تنظیمات API */
function biz_system_model($pdo)
{
    $id = (int)(biz_settings($pdo)['sys_model_id'] ?? 0);
    if ($id <= 0) return null;
    foreach (biz_models($pdo, true, 'text') as $m) if ((int)$m['id'] === $id) return $m;
    return null;
}

/** اولین مدل فعال یک دسته برای یک کاربرد (مثلاً صوت به متن چت‌بات) */
function biz_first_model($pdo, $category, $use = null)
{
    foreach (biz_models($pdo, true, $category) as $m) {
        if ($use !== null && empty($m['use_' . $use])) continue;
        return $m;
    }
    return null;
}

/** بررسی سلامت یک مدل متنی (دکمه «بررسی» در پنل مدیریت) */
function biz_model_ping($pdo, $cfg, $model)
{
    if (!biz_price_is_token_cat($model['category'] ?? 'text')) return ['ok' => true, 'error' => 'بررسی خودکار فقط برای مدل‌های متنی ممکن است.'];
    $r = biz_provider_chat($cfg, $model, [['role' => 'user', 'content' => 'ping']], 5, 0);
    biz_model_mark($pdo, (int)$model['id'], !empty($r['ok']), $r['error'] ?? '');
    return ['ok' => !empty($r['ok']), 'error' => $r['error'] ?? ''];
}

// ---------------------------------------------------------------------------
// ساخت تصویر و ویدیو
// ---------------------------------------------------------------------------
function biz_media_base($cfg, $provider)
{
    return $provider === 'openrouter'
        ? rtrim($cfg['openrouter_api_base'] ?? 'https://openrouter.ai/api/v1', '/')
        : rtrim($cfg['avalai_api_base'] ?? 'https://api.avalai.ir/v1', '/');
}

function biz_media_key($cfg, $provider)
{
    return (string)($provider === 'openrouter' ? ($cfg['openrouter_api_key'] ?? '') : ($cfg['avalai_api_key'] ?? ''));
}

/** درخواست HTTP به سرویس‌دهنده (JSON) */
function biz_media_http($method, $url, $key, $body = null, $timeout = 120, $raw = false, array $extra_h = [])
{
    $ch = curl_init($url);
    $h = $key !== '' ? ['Authorization: Bearer ' . $key] : [];
    foreach ($extra_h as $xh) $h[] = $xh;
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true];
    if ($method === 'POST') {
        $opt[CURLOPT_POST] = true;
        if (is_array($body) && !empty($body['__multipart'])) {
            unset($body['__multipart']);
            $opt[CURLOPT_POSTFIELDS] = $body;   // multipart/form-data (CURLFile)
        } else {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $h[] = 'Content-Type: application/json';
        }
    }
    if (strpos($url, 'openrouter') !== false) { $h[] = 'HTTP-Referer: ' . (defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : 'https://localhost'); }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') return ['ok' => false, 'code' => 0, 'error' => 'خطای اتصال: ' . $err];
    if ($raw) return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $res, 'ctype' => $ctype, 'error' => $code >= 300 ? 'HTTP ' . $code : ''];
    $j = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) {
        $m = is_array($j) ? ($j['error']['message'] ?? ($j['message'] ?? '')) : '';
        return ['ok' => false, 'code' => $code, 'error' => 'HTTP ' . $code . ($m !== '' ? ' — ' . $m : ''), 'json' => $j];
    }
    return ['ok' => true, 'code' => $code, 'json' => $j, 'error' => ''];
}

/** ذخیره فایل تولیدشده در uploads/media/{user}/ و برگرداندن آدرس عمومی */
function biz_media_save($user_id, $bytes, $ext)
{
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/media/' . (int)$user_id;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $bytes) === false) return '';
    return rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/media/' . (int)$user_id . '/' . $name;
}

/** دانلود تصویر از آدرس یا data URL */
function biz_media_fetch_image($src)
{
    if (preg_match('#^data:image/(\w+);base64,(.+)$#s', $src, $m)) return [base64_decode($m[2]), $m[1] === 'jpeg' ? 'jpg' : $m[1]];
    $r = biz_media_http('GET', $src, '', null, 60, true);
    if (!$r['ok'] || $r['body'] === '') return [null, ''];
    $ext = strpos($r['ctype'], 'jpeg') !== false ? 'jpg' : (strpos($r['ctype'], 'webp') !== false ? 'webp' : 'png');
    return [$r['body'], $ext];
}

/** آیا مدل تصویر از خانواده Gemini (Nano Banana) است؟ این مدل‌ها تصویر را از مسیر گفتگو (chat/completions) می‌سازند و ویرایش می‌کنند */
function biz_img_is_gemini($model)
{
    return (bool)preg_match('/gemini|nano.?banana/i', (string)($model['model_id'] ?? ''));
}

/**
 * پیدا کردن تصویر در پاسخ سرویس (قالب‌های مختلف OpenAI / OpenRouter / Gemini)
 * @return string data URL یا آدرس http ('' = تصویری نبود)
 */
function biz_img_extract($j)
{
    if (!is_array($j)) return '';
    $d = $j['data'][0] ?? null;
    if (is_array($d)) {
        if (!empty($d['b64_json'])) return 'data:' . (preg_match('#^image/[a-z+.-]+$#', (string)($d['media_type'] ?? '')) ? $d['media_type'] : 'image/png') . ';base64,' . $d['b64_json'];
        if (!empty($d['url'])) return (string)$d['url'];
    }
    $fromPart = function ($p) {
        if (!is_array($p)) return '';
        $u = $p['image_url']['url'] ?? ($p['image_url'] ?? ($p['url'] ?? ''));
        if (is_string($u) && $u !== '') return $u;
        $in = $p['inline_data'] ?? ($p['inlineData'] ?? null);
        if (is_array($in) && !empty($in['data'])) return 'data:' . ($in['mime_type'] ?? ($in['mimeType'] ?? 'image/png')) . ';base64,' . $in['data'];
        if (!empty($p['b64_json'])) return 'data:image/png;base64,' . $p['b64_json'];
        return '';
    };
    $msg = $j['choices'][0]['message'] ?? null;
    if (is_array($msg)) {
        foreach ((array)($msg['images'] ?? []) as $im) { $u = $fromPart($im); if ($u !== '') return $u; }
        $c = $msg['content'] ?? '';
        if (is_array($c)) {
            foreach ($c as $p) { if (is_array($p) && ($p['type'] ?? '') !== 'text') { $u = $fromPart($p); if ($u !== '') return $u; } }
            $c = implode("\n", array_map(fn($p) => is_array($p) ? (string)($p['text'] ?? '') : (string)$p, $c));
        }
        $c = (string)$c;
        if (preg_match('#data:image/[a-z]+;base64,[A-Za-z0-9+/=\s]{200,}#', $c, $m)) return preg_replace('/\s+/', '', $m[0]);
        if (preg_match('#!\[[^\]]*\]\((https?://[^\s)]+)\)#', $c, $m)) return $m[1];
    }
    foreach ((array)($j['candidates'][0]['content']['parts'] ?? []) as $p) { $u = $fromPart($p); if ($u !== '') return $u; }
    return '';
}

/**
 * توکن مصرفی تفکیک‌شده از پاسخ سرویس تصویر (نسخه ۵۳)
 *  - OpenAI images (gpt-image): input_tokens (+ جزئیات متن/تصویر)، output_tokens
 *  - chat/completions (Gemini، OpenRouter): prompt_tokens، completion_tokens (+ cost در OpenRouter)
 *  - API بومی گوگل: usageMetadata
 * @return array ['in' => ورودی متن, 'img' => ورودی تصویر, 'out' => خروجی, 'total', 'usd' => هزینه گزارش‌شده یا 0]
 */
function biz_img_usage($j)
{
    $o = ['in' => 0, 'img' => 0, 'out' => 0, 'total' => 0, 'usd' => 0.0];
    if (!is_array($j)) return $o;
    $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
    if ($u) {
        $in = (int)($u['input_tokens'] ?? ($u['prompt_tokens'] ?? 0));
        $out = (int)($u['output_tokens'] ?? ($u['completion_tokens'] ?? 0));
        $d = is_array($u['input_tokens_details'] ?? null) ? $u['input_tokens_details'] : (is_array($u['prompt_tokens_details'] ?? null) ? $u['prompt_tokens_details'] : []);
        $img = (int)($d['image_tokens'] ?? 0);
        if ($img > 0 && $img <= $in) { $o['img'] = $img; $o['in'] = isset($d['text_tokens']) ? (int)$d['text_tokens'] : $in - $img; }
        else $o['in'] = $in;
        $o['out'] = $out;
        $o['total'] = (int)($u['total_tokens'] ?? 0) ?: ($in + $out);
        if (isset($u['cost']) && is_numeric($u['cost'])) $o['usd'] = max(0.0, (float)$u['cost']);
        return $o;
    }
    $m = is_array($j['usageMetadata'] ?? null) ? $j['usageMetadata'] : [];
    if ($m) {
        $in = (int)($m['promptTokenCount'] ?? 0);
        $img = 0;
        foreach ((array)($m['promptTokensDetails'] ?? []) as $x) if (is_array($x) && strtoupper((string)($x['modality'] ?? '')) === 'IMAGE') $img += (int)($x['tokenCount'] ?? 0);
        $o['img'] = min($img, $in); $o['in'] = $in - $o['img'];
        $o['out'] = (int)($m['candidatesTokenCount'] ?? 0) + (int)($m['thoughtsTokenCount'] ?? 0);
        $o['total'] = (int)($m['totalTokenCount'] ?? 0) ?: ($in + $o['out']);
    }
    return $o;
}

/**
 * هزینه واقعی (تومان) یک تصویر ساخته‌شده بر اساس توکن مصرفی گزارش‌شده توسط سرویس (نسخه ۵۳)
 *  ۱) OpenRouter هزینه دلاری دقیق را برمی‌گرداند → همان
 *  ۲) مدل با قیمت توکنی: ورودی متن × قیمت ورودی + ورودی تصویر × قیمت ورودی تصویر + خروجی × قیمت خروجی
 *  ۳) اگر سرویس توکن گزارش نکرد یا مدل قیمت توکنی ندارد → قیمت هر تصویر (مثل قبل)
 * سود و نرخ دلار مثل بقیه مدل‌ها اعمال می‌شود.
 */
function biz_image_cost($pdo, $model, array $r)
{
    if (function_exists('biz_price_schema')) biz_price_schema($pdo);
    if (!empty($model['id']) && ($fresh = biz_model_by_id($pdo, (int)$model['id']))) $model = $fresh;   // قیمت تازه (پس از به‌روزرسانی قیمت‌ها)
    $m = $model ?: ['category' => 'image', 'multiplier' => 1];
    $m['category'] = 'image';
    $u = is_array($r['usage'] ?? null) ? $r['usage'] : null;
    $usd = 0.0;
    if ($u && biz_price_rate($pdo) > 0) {
        if ($u['usd'] > 0 && ($m['provider'] ?? '') === 'openrouter') $usd = (float)$u['usd'];
        elseif (biz_image_token_priced($m) && ($u['in'] + $u['img'] + $u['out']) > 0) {
            $img_rate = (float)($m['usd_img_in'] ?? 0) > 0 ? (float)$m['usd_img_in'] : (float)$m['usd_in'];
            $usd = ($u['in'] * (float)$m['usd_in'] + $u['img'] * $img_rate + $u['out'] * (float)$m['usd_out']) / 1000000;
        }
    }
    if ($usd <= 0) return biz_media_cost($pdo, $model, 'image');
    $toman = max(1, (int)ceil(biz_model_usd_toman($pdo, $m, $usd) - 0.0000001));
    // میانگین هزینه واقعی این مدل برای برآورد پیش از ساخت
    if (!empty($m['id'])) {
        $k = 'img_avg_' . (int)$m['id'];
        $old = (int)(biz_settings($pdo)[$k] ?? 0);
        biz_set($pdo, $k, $old > 0 ? (int)round($old * 0.7 + $toman * 0.3) : $toman);
    }
    return $toman;
}

/** توکن مصرفی از پاسخ سرویس (قالب‌های مختلف) */
function biz_img_tokens($j)
{
    if (!is_array($j)) return 0;
    $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
    $t = (int)($u['total_tokens'] ?? 0);
    if ($t <= 0) $t = (int)($u['input_tokens'] ?? ($u['prompt_tokens'] ?? 0)) + (int)($u['output_tokens'] ?? ($u['completion_tokens'] ?? 0));
    if ($t <= 0) $t = (int)($j['usageMetadata']['totalTokenCount'] ?? 0);
    return $t;
}

/**
 * نسخه ۷۰: قابلیت‌های مدل‌های تصویری OpenRouter از GET /images/models (کش ۱۲ ساعته در uploads/.or_imgcaps.json)
 *  هر مدل پارامترهای مجاز خودش را دارد (مثلاً Ming Image Design نسبت ابعاد نمی‌پذیرد، Recraft فقط 1:1، 4:3، 16:9 و …،
 *  Ming Design Layer دقیقاً یک تصویر مرجع می‌خواهد). فقط پارامترهای مجاز هر مدل فرستاده می‌شود.
 * @return array|null ['p' => supported_parameters, 'in' => input_modalities] یا null اگر نامعلوم
 */
function biz_or_img_caps($cfg, $mid)
{
    static $map = null;
    if ($mid === null) return is_array($map) ? $map : [];   // کل فهرست (پس از یک بار دریافت)
    if ($map === null) {
        $map = [];
        $file = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.or_imgcaps.json' : '';
        $c = $file !== '' && is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (is_array($c) && (int)($c['at'] ?? 0) > time() - 43200 && is_array($c['m'] ?? null)) $map = $c['m'];
        else {
            $key = biz_media_key($cfg, 'openrouter');
            $base = rtrim((string)biz_media_base($cfg, 'openrouter'), '/');
            $urls = array_values(array_unique(array_filter([$base !== '' ? $base . '/images/models' : '', 'https://openrouter.ai/api/v1/images/models'])));
            $got = false;
            foreach ($urls as $u) {
                $r = biz_media_http('GET', $u, $key, null, 25);
                if (empty($r['ok']) || !is_array($r['json']['data'] ?? null)) continue;
                foreach ($r['json']['data'] as $d) {
                    if (!is_array($d) || empty($d['id'])) continue;
                    $map[strtolower((string)$d['id'])] = ['p' => is_array($d['supported_parameters'] ?? null) ? $d['supported_parameters'] : [],
                                                          'in' => (array)($d['architecture']['input_modalities'] ?? [])];
                }
                $got = true;
                break;
            }
            // اگر دریافت نشد، ۳۰ دقیقه بعد دوباره تلاش شود
            if ($file !== '') @file_put_contents($file, json_encode(['at' => $got ? time() : time() - 43200 + 1800, 'm' => $map]));
        }
    }
    $k = strtolower(trim((string)$mid));
    return $map[$k] ?? ($map[preg_replace('/:[a-z0-9_-]+$/', '', $k)] ?? null);
}

/** نسخه ۷۱: شناسه کامل OpenRouter برای شناسه بدون نام سازنده (مثلاً ming-image-0.1-design-layer → inclusionai/ming-image-0.1-design-layer) */
function biz_or_img_find($cfg, $mid)
{
    $k = strtolower(trim((string)$mid));
    if ($k === '') return '';
    biz_or_img_caps($cfg, $k);
    $all = biz_or_img_caps($cfg, null);
    if (isset($all[$k])) return $k;
    $tail = strpos($k, '/') !== false ? substr($k, strrpos($k, '/') + 1) : $k;
    foreach (array_keys($all) as $id) if (substr($id, -strlen('/' . $tail)) === '/' . $tail) return $id;
    return '';
}

/** یک طرح ساده آزمایشی (PNG) برای تست مدل‌هایی که تصویر ورودی لازم دارند */
function biz_test_design_png()
{
    if (!function_exists('imagecreatetruecolor')) return '';
    $im = imagecreatetruecolor(512, 512);
    imagefilledrectangle($im, 0, 0, 511, 511, imagecolorallocate($im, 245, 240, 225));
    imagefilledrectangle($im, 0, 0, 511, 90, imagecolorallocate($im, 37, 99, 235));
    imagefilledellipse($im, 256, 300, 260, 260, imagecolorallocate($im, 220, 38, 38));
    imagestring($im, 5, 180, 35, 'SAMPLE DESIGN', imagecolorallocate($im, 255, 255, 255));
    ob_start(); imagepng($im); $b = (string)ob_get_clean(); imagedestroy($im);
    return $b;
}

/** نزدیک‌ترین نسبت ابعاد مجاز مدل به نسبت درخواستی (مثلاً 3:2 → 4:3) */
function biz_ar_nearest($ar, array $values)
{
    $vals = array_values(array_filter(array_map('strval', $values), fn($v) => preg_match('/^\d+(\.\d+)?:\d+(\.\d+)?$/', $v)));
    if (!$vals || !preg_match('/^(\d+):(\d+)$/', (string)$ar, $m)) return '';
    if (in_array($ar, $vals, true)) return $ar;
    $want = log($m[1] / $m[2]); $best = ''; $bd = INF;
    foreach ($vals as $v) { [$a, $b] = array_map('floatval', explode(':', $v)); if ($a <= 0 || $b <= 0) continue; $d = abs(log($a / $b) - $want); if ($d < $bd) { $bd = $d; $best = $v; } }
    return $best;
}

/** همه تصویرهای یک پاسخ (مدل‌هایی که چند خروجی می‌دهند، مثل جداسازی لایه‌ها) */
function biz_img_extract_all($j)
{
    $out = [];
    if (is_array($j) && is_array($j['data'] ?? null)) {
        foreach ($j['data'] as $d) {
            if (!is_array($d)) continue;
            if (!empty($d['b64_json'])) $out[] = 'data:' . (preg_match('#^image/[a-z+.-]+$#', (string)($d['media_type'] ?? '')) ? $d['media_type'] : 'image/png') . ';base64,' . $d['b64_json'];
            elseif (!empty($d['url'])) $out[] = (string)$d['url'];
        }
    }
    if (!$out) { $one = biz_img_extract($j); if ($one !== '') $out[] = $one; }
    return array_slice($out, 0, 12);
}

/**
 * ساخت یک تصویر با یک مدل
 *  - بدون مرجع: AvalAI /images/generations — OpenRouter و Gemini: chat/completions با خروجی تصویر
 *  - با مرجع (ویرایش یا طرح بر اساس تصویر): چند قالب استاندارد به ترتیب امتحان می‌شود تا مدل تصویر را بپذیرد:
 *      Gemini: گفتگو (تصویر + متن) ← API بومی گوگل ← images/edits
 *      سایر مدل‌ها (gpt-image و …): images/edits JSON ← چندبخشی (image) ← image[] ← گفتگو
 *    اگر هیچ‌کدام مرجع را نپذیرفت، خطای روشن برمی‌گردد (ساخت تصویر بی‌ربط بدون مرجع انجام نمی‌شود).
 * @return array ['ok', 'bytes', 'ext', 'tokens', 'error', 'ref_rejected'?]
 */
function biz_image_generate_one($cfg, $model, $prompt, $size = '1024x1024', $ref = null, array $opt = [])
{
    $key = biz_media_key($cfg, $model['provider']);
    if ($key === '') return ['ok' => false, 'error' => 'کلید سرویس‌دهنده تنظیم نشده است.'];
    $base = biz_media_base($cfg, $model['provider']);
    $mid = (string)$model['model_id'];
    $ref_url = $ref ? 'data:' . $ref['mime'] . ';base64,' . base64_encode($ref['bytes']) : '';
    $gem = biz_img_is_gemini($model);
    // پس‌زمینه شفاف (فقط مدل‌های gpt-image از آن پشتیبانی می‌کنند)
    $xp = (!empty($opt['transparent']) && preg_match('/gpt-image/i', $mid)) ? ['background' => 'transparent', 'output_format' => 'png'] : [];
    $ar = ['1536x1024' => '3:2', '1024x1536' => '2:3', '1024x1024' => '1:1'][$size] ?? '';

    // روش‌های ارسال
    $chat = function () use ($base, $key, $mid, $prompt, $ref_url, $ar) {
        $txt = $prompt . ($ar !== '' && $ar !== '1:1' ? "\n(Aspect ratio: " . $ar . ')' : '');
        $content = $ref_url !== '' ? [['type' => 'image_url', 'image_url' => ['url' => $ref_url]], ['type' => 'text', 'text' => $txt]] : $txt;
        return biz_media_http('POST', $base . '/chat/completions', $key, [
            'model' => $mid, 'modalities' => ['image', 'text'],
            'messages' => [['role' => 'user', 'content' => $content]],
        ], 180);
    };
    $native = function () use ($base, $key, $mid, $prompt, $ref, $ar) {
        // API بومی گوگل (AvalAI آن را روی همان دامنه با مسیر v1beta پشتیبانی می‌کند)
        $root = preg_replace('#/v1$#', '', $base);
        $parts = [['text' => $prompt]];
        if ($ref) $parts[] = ['inline_data' => ['mime_type' => $ref['mime'], 'data' => base64_encode($ref['bytes'])]];
        $gc = ['responseModalities' => ['TEXT', 'IMAGE']];
        if ($ar !== '' && $ar !== '1:1') $gc['imageConfig'] = ['aspectRatio' => $ar];
        return biz_media_http('POST', $root . '/v1beta/models/' . rawurlencode($mid) . ':generateContent', $key,
            ['contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => $gc], 180, false, ['x-goog-api-key: ' . $key]);
    };
    $edits_mp = function ($field) use ($base, $key, $mid, $prompt, $ref, $size, $xp) {
        $tmp = tempnam(sys_get_temp_dir(), 'gref');
        @file_put_contents($tmp, $ref['bytes']);
        $ext = $ref['mime'] === 'image/jpeg' ? 'jpg' : ($ref['mime'] === 'image/webp' ? 'webp' : 'png');
        $r = biz_media_http('POST', $base . '/images/edits', $key, ['__multipart' => 1, 'model' => $mid, 'prompt' => mb_substr($prompt, 0, 3800), 'n' => '1', 'size' => $size,
            $field => new \CURLFile($tmp, $ref['mime'], 'reference.' . $ext)] + $xp, 180);
        @unlink($tmp);
        return $r;
    };
    $edits_json = function () use ($base, $key, $mid, $prompt, $ref_url, $size, $xp) {
        return biz_media_http('POST', $base . '/images/edits', $key, ['model' => $mid, 'prompt' => $prompt, 'images' => [['image_url' => $ref_url]], 'n' => 1, 'size' => $size] + $xp, 180);
    };
    $gen = function ($sz) use ($base, $key, $mid, $prompt, $xp) {
        return biz_media_http('POST', $base . '/images/generations', $key, ['model' => $mid, 'prompt' => $prompt, 'n' => 1] + ($sz !== '' ? ['size' => $sz] : []) + $xp, 150);
    };
    // نسخه ۶۵: API تصویر OpenRouter (/images) — مدل‌های فقط‌تصویری (Recraft، Seedream، FLUX، Muse و …) از چت پشتیبانی نمی‌کنند
    // نسخه ۷۰: فقط پارامترهایی که همین مدل می‌پذیرد (از /images/models)؛ $minimal = فقط مدل + متن (+ مرجع)
    $or_images = function ($minimal = false) use ($cfg, $base, $key, $mid, $prompt, $ref_url, $ar) {
        $caps = biz_or_img_caps($cfg, $mid);
        $b = ['model' => $mid, 'prompt' => mb_substr($prompt, 0, 4000)];
        if ($caps) {
            $p = $caps['p'];
            $rmax = isset($p['input_references']) ? (int)($p['input_references']['max'] ?? 0) : (in_array('image', $caps['in'], true) ? 1 : 0);
            $rmin = isset($p['input_references']) ? (int)($p['input_references']['min'] ?? 0) : 0;
            if ($ref_url !== '' && $rmax <= 0) return ['ok' => false, 'code' => 400, 'error' => 'HTTP 400 — this model does not accept reference images (text-to-image only)', 'no_ref' => true];
            if ($ref_url === '' && $rmin >= 1) return ['ok' => false, 'code' => 400, 'error' => 'HTTP 400 — this model requires ' . $rmin . ' reference image(s)', 'ref_required' => true];
            if (!$minimal) {
                if (isset($p['n'])) $b['n'] = 1;
                if ($ar !== '' && isset($p['aspect_ratio'])) { $v = biz_ar_nearest($ar, (array)($p['aspect_ratio']['values'] ?? [])); if ($v !== '') $b['aspect_ratio'] = $v; }
                if (isset($p['output_format']) && in_array('png', (array)($p['output_format']['values'] ?? []), true)) $b['output_format'] = 'png';
            }
        } elseif (!$minimal) {
            $b['n'] = 1;
            if ($ar !== '') $b['aspect_ratio'] = $ar;
        }
        if ($ref_url !== '') $b['input_references'] = [['type' => 'image_url', 'image_url' => ['url' => $ref_url]]];
        return biz_media_http('POST', $base . '/images', $key, $b, 240);
    };
    // چت با خروجی فقط تصویر (برخی مدل‌ها modalities ['image','text'] را نمی‌پذیرند)
    $chat_img = function () use ($base, $key, $mid, $prompt, $ref_url, $ar) {
        $txt = $prompt . ($ar !== '' && $ar !== '1:1' ? "\n(Aspect ratio: " . $ar . ')' : '');
        $content = $ref_url !== '' ? [['type' => 'image_url', 'image_url' => ['url' => $ref_url]], ['type' => 'text', 'text' => $txt]] : $txt;
        return biz_media_http('POST', $base . '/chat/completions', $key, ['model' => $mid, 'modalities' => ['image'], 'messages' => [['role' => 'user', 'content' => $content]]], 180);
    };

    if ($model['provider'] === 'openrouter') {
        $plan = $gem ? ['chat' => $chat, 'images' => $or_images, 'chat_img' => $chat_img]
                     : ['images' => $or_images, 'images_min' => fn() => $or_images(true), 'chat_img' => $chat_img, 'chat' => $chat];
    } elseif ($ref) {
        $plan = $gem
            ? ['chat' => $chat, 'native' => $native, 'edits' => fn() => $edits_mp('image'), 'edits_json' => $edits_json]
            : ['edits_json' => $edits_json, 'edits' => fn() => $edits_mp('image'), 'edits[]' => fn() => $edits_mp('image[]'), 'chat' => $chat];
    } else {
        $plan = ['gen' => fn() => $gen($size)];
        if ($size !== '1024x1024') $plan['gen_sq'] = fn() => $gen('1024x1024');   // برخی مدل‌ها (مثل dall-e) این ابعاد را نمی‌پذیرند
        $plan['gen_nosize'] = fn() => $gen('');   // نسخه ۶۵: مدل‌هایی که پارامتر size را نمی‌شناسند
        $plan['chat'] = $chat;                     // مدل‌هایی که فقط از مسیر چت تصویر می‌سازند
        $plan['chat_img'] = $chat_img;
    }

    $errs = []; $hard = false;
    $soft = '/HTTP (400|404|405|415|422)\b/';
    foreach ($plan as $name => $fn) {
        $r = $fn();
        if (!$r['ok'] && !empty($r['ref_required'])) return ['ok' => false, 'error' => $name . ': ' . $r['error'], 'ref_required' => true];
        if (!$r['ok'] && !empty($r['no_ref'])) return ['ok' => false, 'error' => $name . ': ' . $r['error'], 'ref_rejected' => true];
        if (!$r['ok']) {
            $errs[] = $name . ': ' . $r['error'];
            if (!preg_match($soft, (string)$r['error'])) { $hard = true; break; }   // قطعی سرویس / اعتبار / کلید → مدل بعدی زنجیره
            continue;
        }
        $src = biz_img_extract($r['json']);
        if ($src === '') { $errs[] = $name . ': تصویری در پاسخ نبود'; continue; }
        [$bytes, $ext] = biz_media_fetch_image($src);
        if (!$bytes) { $errs[] = $name . ': دریافت فایل تصویر ممکن نشد'; continue; }
        // نسخه ۷۰: خروجی‌های بیشتر (مثلاً لایه‌های جداشده یک طرح)
        $extra = [];
        foreach (array_slice(biz_img_extract_all($r['json']), 1) as $src2) {
            [$b2, $e2] = biz_media_fetch_image($src2);
            if ($b2) $extra[] = ['bytes' => $b2, 'ext' => $e2 ?: 'png'];
        }
        return ['ok' => true, 'bytes' => $bytes, 'ext' => $ext ?: 'png', 'tokens' => biz_img_tokens($r['json']), 'usage' => biz_img_usage($r['json']), 'error' => '', 'via' => $name, 'extra' => $extra];
    }
    $err = $errs ? implode(' | ', $errs) : 'تصویری ساخته نشد.';
    return ['ok' => false, 'error' => $err, 'ref_rejected' => (bool)$ref && !$hard];
}

/**
 * ساخت تصویر با جایگزینی خودکار مدل
 * @param array|null $ref تصویر مرجع ['bytes','mime'] (ویرایش / اجرای دقیق / طرح مشابه نمونه)
 * @param array $opt allow_noref: اگر هیچ مدلی مرجع را نپذیرفت، بدون مرجع ساخته شود (فقط برای «طرح مشابه نمونه» گالری)
 * @return array ['ok','bytes','ext','tokens','model_row','used_ref','error','ref_rejected']
 */
function biz_image_generate($pdo, $cfg, $model, $prompt, $size = '1024x1024', $ref = null, array $opt = [])
{
    // no_chain: فقط همین مدل (اقدام‌های آماده، فهرست مدل‌های خودشان را به ترتیب امتحان می‌کنند)
    $chain = !empty($opt['no_chain']) ? [$model] : biz_model_chain($pdo, $model);
    $order = array_merge(array_filter($chain, 'biz_model_up'), array_filter($chain, fn($m) => !biz_model_up($m)));
    if (!empty($opt['no_chain'])) $order = [$model];
    $last = ''; $ref_rej = false; $policy = null;
    foreach (array_slice($order, 0, 4) as $m) {
        $r = biz_image_generate_one($cfg, $m, $prompt, $size, $ref, $opt);
        if ($r['ok']) {
            biz_model_mark($pdo, (int)$m['id'], true); $r['model_row'] = $m; $r['used_ref'] = (bool)$ref;
            if (function_exists('mcap_count')) mcap_count($pdo, (int)$m['id']);
            if (!empty($model['id']) && function_exists('biz_set') && (biz_settings($pdo)['img_err_' . (int)$model['id']] ?? '') !== '') biz_set($pdo, 'img_err_' . (int)$model['id'], '');
            return $r;
        }
        if (!empty($r['ref_required'])) return ['ok' => false, 'error' => $r['error'], 'ref_required' => true, 'model_row' => $m];   // نسخه ۷۰: این مدل فقط روی یک تصویر کار می‌کند
        $last = $r['error'];
        error_log('[IMG] ' . ($m['model_id'] ?? '') . ($ref ? ' (ref)' : '') . ': ' . $last);
        if (preg_match('/HTTP (400|422)/', $last)) {
            if ($ref && !empty($r['ref_rejected'])) { $ref_rej = true; continue; }   // این مدل مرجع نمی‌پذیرد → مدل جایگزین
            if (!$ref) { $policy = ['ok' => false, 'error' => $last, 'model_row' => $m]; break; }   // درخواست رد شد (معمولاً قوانین محتوا)
            continue;
        }
        biz_model_mark($pdo, (int)$m['id'], false, $last);
    }
    // نسخه ۶۶: آخرین خطای ساخت هر مدل برای مدیر (صفحه مدل‌ها) — متن کامل پاسخ سرویس‌دهنده
    if (!empty($model['id']) && function_exists('biz_set')) {
        try { biz_set($pdo, 'img_err_' . (int)$model['id'], json_encode(['at' => date('Y-m-d H:i:s'), 'err' => mb_substr((function_exists('biz_provider_error_hint') ? biz_provider_error_hint($policy['error'] ?? $last) : '') . (string)($policy['error'] ?? $last), 0, 900), 'ref' => (bool)$ref, 'prov' => (string)($model['provider'] ?? ''), 'mid' => (string)($model['model_id'] ?? '')], JSON_UNESCAPED_UNICODE)); } catch (\Throwable $e) {}
    }
    if ($policy) return $policy;
    if ($ref && $ref_rej && !empty($opt['allow_noref'])) {
        $r = biz_image_generate($pdo, $cfg, $model, $prompt, $size, null);
        if ($r['ok']) $r['used_ref'] = false;
        return $r;
    }
    return ['ok' => false, 'error' => $last !== '' ? $last : 'سرویس در دسترس نیست.', 'model_row' => $model, 'ref_rejected' => $ref_rej];
}

/** آماده‌سازی تصویر مرجع ویدیو: هم‌اندازه با ابعاد ویدیو (برش از وسط) — JPEG */
function biz_fit_image($bytes, $size)
{
    if (!function_exists('imagecreatefromstring') || !preg_match('/^(\d+)x(\d+)$/', $size, $m)) return null;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    [$tw, $th] = [(int)$m[1], (int)$m[2]];
    $sw = imagesx($src); $sh = imagesy($src);
    $k = max($tw / $sw, $th / $sh);
    $cw = (int)round($tw / $k); $ch = (int)round($th / $k);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $tw, $th, $cw, $ch);
    ob_start(); imagejpeg($dst, null, 90); $out = ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return $out ?: null;
}

/** شروع ساخت ویدیو (غیرهمزمان) — فقط AvalAI (/v1/videos) */
function biz_video_create($pdo, $cfg, $model, $prompt, $seconds = 4, $size = '1280x720', $ref = null)
{
    $ref_file = null;
    if ($ref) {
        $fit = biz_fit_image($ref['bytes'], $size);
        if ($fit) { $ref_file = tempnam(sys_get_temp_dir(), 'vref') . '.jpg'; file_put_contents($ref_file, $fit); }
    }
    $chain = biz_model_chain($pdo, $model);
    $order = array_merge(array_filter($chain, 'biz_model_up'), array_filter($chain, fn($m) => !biz_model_up($m)));
    $last = '';
    foreach (array_slice($order, 0, 4) as $m) {
        $key = biz_media_key($cfg, $m['provider']);
        if ($key === '') { $last = 'کلید سرویس‌دهنده تنظیم نشده است.'; continue; }
        $fields = ['model' => $m['model_id'], 'prompt' => mb_substr($prompt, 0, 1000), 'seconds' => (string)(int)$seconds, 'size' => $size];
        $r = $ref_file
            ? biz_media_http('POST', biz_media_base($cfg, $m['provider']) . '/videos', $key, $fields + ['__multipart' => 1, 'input_reference' => new \CURLFile($ref_file, 'image/jpeg', 'reference.jpg')], 90)
            : biz_media_http('POST', biz_media_base($cfg, $m['provider']) . '/videos', $key, $fields, 60);
        if (!$r['ok'] && $ref_file && preg_match('/HTTP (400|415|422)/', (string)$r['error'])) {
            $r = biz_media_http('POST', biz_media_base($cfg, $m['provider']) . '/videos', $key, $fields, 60);   // بدون تصویر مرجع
        }
        if ($r['ok'] && !empty($r['json']['id'])) {
            biz_model_mark($pdo, (int)$m['id'], true);
            if (function_exists('mcap_count')) mcap_count($pdo, (int)$m['id']);
            if ($ref_file) @unlink($ref_file);
            return ['ok' => true, 'job_id' => (string)$r['json']['id'], 'status' => (string)($r['json']['status'] ?? 'queued'), 'model_row' => $m, 'error' => ''];
        }
        $last = $r['error'] ?: 'پاسخ نامعتبر';
        if (preg_match('/HTTP (400|422)/', $last)) { if ($ref_file) @unlink($ref_file); return ['ok' => false, 'error' => $last, 'model_row' => $m]; }
        biz_model_mark($pdo, (int)$m['id'], false, $last);
    }
    if ($ref_file) @unlink($ref_file);
    return ['ok' => false, 'error' => $last, 'model_row' => $model];
}

/** وضعیت ساخت ویدیو؛ در صورت تکمیل، فایل ذخیره می‌شود */
function biz_video_poll($pdo, $cfg, $media)
{
    $m = biz_model_by_id($pdo, (int)$media['model_id']);
    $prov = $m['provider'] ?? 'avalai';
    $key = biz_media_key($cfg, $prov);
    $base = biz_media_base($cfg, $prov);
    $r = biz_media_http('GET', $base . '/videos/' . rawurlencode($media['job_id']), $key, null, 30);
    if (!$r['ok']) return ['status' => $media['status'], 'error' => $r['error']];
    $st = (string)($r['json']['status'] ?? '');
    if ($st === 'failed') return ['status' => 'failed', 'error' => (string)($r['json']['error']['message'] ?? 'ساخت ویدیو ناموفق بود.')];
    if ($st !== 'completed') return ['status' => $st === 'processing' ? 'processing' : 'queued', 'progress' => (int)($r['json']['progress'] ?? 0), 'error' => ''];
    $c = biz_media_http('GET', $base . '/videos/' . rawurlencode($media['job_id']) . '/content', $key, null, 300, true);
    if (!$c['ok'] || strlen((string)$c['body']) < 1000) return ['status' => 'processing', 'error' => 'دریافت فایل ویدیو: ' . $c['error']];
    $url = biz_media_save((int)$media['user_id'], $c['body'], 'mp4');
    if ($url === '') return ['status' => 'processing', 'error' => 'ذخیره فایل ممکن نشد (دسترسی پوشه uploads).'];
    return ['status' => 'done', 'file_url' => $url, 'error' => ''];
}

/** هزینه (تومان) ساخت تصویر / ویدیو */
function biz_media_cost($pdo, $model, $kind, $seconds = 0)
{
    if ($kind !== 'video' && function_exists('biz_price_schema')) biz_price_schema($pdo);
    $m = $model ?: ['category' => $kind, 'multiplier' => 1];
    $m['category'] = $kind === 'video' ? 'video' : 'image';
    $p = biz_model_toman_prices($pdo, $m);
    // تصویر با قیمت توکنی: برآورد = میانگین هزینه واقعی اخیر همین مدل (اگر هست)؛ هزینه نهایی با biz_image_cost از توکن واقعی
    if ($kind !== 'video' && !empty($p['tokens']) && !empty($m['id']) && ($avg = (int)(biz_settings($pdo)['img_avg_' . (int)$m['id']] ?? 0)) > 0) return $avg;
    $unit = $p['unit'];
    return max(1, (int)ceil($unit * ($kind === 'video' ? max(1, (int)$seconds) : 1) - 0.0000001));
}

/** هزینه (تومان) تبدیل صوت به متن (بر اساس مدت) یا متن به صوت (بر اساس تعداد کاراکتر) */
function biz_audio_cost($pdo, $model, $kind, $amount)
{
    $m = $model ?: ['category' => $kind, 'multiplier' => 1];
    $m['category'] = $kind === 'tts' ? 'tts' : 'stt';
    $unit = biz_model_toman_prices($pdo, $m)['unit'];
    if (!biz_model_has_price($m)) return (int)ceil($kind === 'tts' ? $unit * max(0, (int)$amount) / 1000 : $unit);   // هزینه ثابت قدیمی
    $q = $kind === 'tts' ? max(0, (int)$amount) / 1000 : max(1, (float)$amount) / 60;   // هر ۱۰۰۰ کاراکتر / هر دقیقه
    return max(1, (int)ceil($unit * $q - 0.0000001));
}

/** قیمت تومانی تقریبی هر ۱۰۰۰ توکن اعتبار (برای نمایش) */
function biz_toman_per_1k($pdo)
{
    $cfg = saas_get_api_config($pdo);
    return (float)$cfg['cost_per_1k_tokens'];
}

/** دریافت نرخ دلار از tgju.org (ریال → تومان) */
function biz_fetch_usd_rate()
{
    $ch = curl_init(defined('BIZ_USD_TEST_URL') ? BIZ_USD_TEST_URL : 'https://call1.tgju.org/ajax.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0 Safari/537.36', CURLOPT_ENCODING => '']);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $j = $res ? json_decode($res, true) : null;
    $p = $j['current']['price_dollar_rl']['p'] ?? null;
    if ($p === null) return ['ok' => false, 'error' => $err !== '' ? $err : 'پاسخ نامعتبر از منبع نرخ دلار'];
    $rial = (int)preg_replace('/\D+/', '', (string)$p);
    $toman = (int)round($rial / 10);
    if ($toman < 1000 || $toman > 100000000) return ['ok' => false, 'error' => 'نرخ دریافتی غیرعادی است: ' . $toman];
    return ['ok' => true, 'rate' => $toman];
}

/**
 * به‌روزرسانی نرخ دلار و قیمت هر ۱۰۰۰ توکن اعتبار
 * @return array ['ok', 'rate', 'price', 'error']
 */
function biz_update_usd($pdo, $force = false)
{
    $s = biz_settings($pdo, true);
    if (!$force && empty($s['usd_auto'])) return ['ok' => false, 'error' => 'به‌روزرسانی خودکار خاموش است.'];
    $r = biz_fetch_usd_rate();
    if (!$r['ok']) { biz_set($pdo, 'usd_error', mb_substr($r['error'], 0, 200) . ' — ' . biz_now()); return $r; }
    $old = (float)$s['usd_rate'];
    // محافظت: تغییر بیش از ۳۰٪ در یک مرحله پذیرفته نمی‌شود (احتمال خطای منبع)
    if ($old > 0 && abs($r['rate'] - $old) / $old > 0.3 && !$force) {
        biz_set($pdo, 'usd_error', 'تغییر غیرعادی نرخ (' . number_format($r['rate']) . ') رد شد — ' . biz_now());
        return ['ok' => false, 'error' => 'تغییر غیرعادی نرخ'];
    }
    $rate = (int)round($r['rate'] * (1 + max(0, (float)$s['usd_margin']) / 100));
    biz_set($pdo, 'usd_rate', $rate);
    biz_set($pdo, 'usd_updated', biz_now());
    biz_set($pdo, 'usd_error', '');
    $price = 0;
    if ((float)$s['credit_usd_1k'] > 0) {
        $price = max(1, (int)round((float)$s['credit_usd_1k'] * $rate));
        $pdo->prepare("UPDATE saas_api_config SET cost_per_1k_tokens=? WHERE id=1")->execute([$price]);
    }
    return ['ok' => true, 'rate' => $rate, 'price' => $price, 'error' => ''];
}

// =============================================================================
// درگاه زرین‌پال سامانه (خرید پلن، توکن و پیامک)
// =============================================================================
function biz_zp_base($cfg, $kind)
{
    if (defined('HD_ZP_TEST_BASE')) return HD_ZP_TEST_BASE;   // فقط محیط آزمایش
    if (!empty($cfg['zarinpal_sandbox'])) return 'https://sandbox.zarinpal.com';
    return $kind === 'api' ? 'https://api.zarinpal.com' : 'https://www.zarinpal.com';
}

function biz_zp_post($url, $data)
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

/** @return array ['ok', 'url' | 'error'] */
function biz_zp_request($pdo, $payment_id, $amount_toman, $desc, $mobile = '', $email = '')
{
    $cfg = saas_get_api_config($pdo);
    if (empty($cfg['zarinpal_merchant_id'])) return ['ok' => false, 'error' => 'درگاه پرداخت هنوز تنظیم نشده است. با پشتیبانی تماس بگیرید.'];
    // نسخه ۶۰: metadata فقط با موبایل/ایمیل معتبر (مقدار خالی یا ایمیل ساختگی مثل …@users.invalid باعث خطای -9 زرین‌پال می‌شد)
    $callback = rtrim(AICHAT_BASE_URL, '/') . '/payment/verify.php?payment_id=' . (int)$payment_id;
    $body = function_exists('comm_zp_payload') ? comm_zp_payload($cfg['zarinpal_merchant_id'], (int)$amount_toman * 10, $desc, $callback, $mobile, $email)
          : ['merchant_id' => $cfg['zarinpal_merchant_id'], 'amount' => (int)$amount_toman * 10, 'description' => mb_substr($desc, 0, 250), 'callback_url' => $callback];
    $r = biz_zp_post(biz_zp_base($cfg, 'api') . '/pg/v4/payment/request.json', $body);
    if (empty($r['data']['authority'])) {
        error_log('[BIZ] zarinpal request: ' . json_encode($r['errors'] ?? $r));
        $ex = function_exists('comm_zp_explain') ? comm_zp_explain($r) : ['code' => $r['errors']['code'] ?? '—', 'detail' => ''];
        $zc = $ex['code'];
        if (function_exists('biz_set')) biz_set($pdo, 'zp_last_error', json_encode(['code' => $zc, 'at' => biz_now(), 'detail' => mb_substr($ex['detail'], 0, 400)], JSON_UNESCAPED_UNICODE));
        return ['ok' => false, 'error' => 'اتصال به درگاه پرداخت ممکن نشد. لطفاً چند دقیقه دیگر دوباره تلاش کنید و اگر تکرار شد به پشتیبانی اطلاع دهید. (کد: ' . $zc . ')'];
    }
    $auth = (string)$r['data']['authority'];
    $pdo->prepare("UPDATE saas_payments SET authority=? WHERE id=?")->execute([$auth, (int)$payment_id]);
    return ['ok' => true, 'url' => biz_zp_base($cfg, 'www') . '/pg/StartPay/' . $auth];
}

/** @return array ['ok', 'ref', 'code'] */
function biz_zp_verify($pdo, $amount_toman, $authority)
{
    $cfg = saas_get_api_config($pdo);
    $r = biz_zp_post(biz_zp_base($cfg, 'api') . '/pg/v4/payment/verify.json', [
        'merchant_id' => function_exists('comm_zp_clean_merchant') ? comm_zp_clean_merchant($cfg['zarinpal_merchant_id']) : $cfg['zarinpal_merchant_id'],
        'amount'      => (int)$amount_toman * 10,
        'authority'   => $authority,
    ]);
    $code = $r['data']['code'] ?? ($r['errors']['code'] ?? -1);
    return ['ok' => ($code == 100 || $code == 101), 'ref' => (string)($r['data']['ref_id'] ?? ''), 'code' => $code];
}

/** ثبت یک پرداخت در انتظار */
function biz_create_payment($pdo, $user_id, $type, $quote, $plan_id = 0, $credit_tokens = 0, $sms_count = 0, $desc = '', $meta = null, $setup_fee = 0, $amount = null)
{
    $amt = $amount === null ? (int)$quote['final'] : (int)$amount;
    $pdo->prepare("INSERT INTO saas_payments (user_id, amount_toman, type, plan_id, credit_tokens, status, description, created_at, original_amount, discount_amount, discount_code_id, sms_count, meta, setup_fee)
        VALUES(?,?,?,?,?,'pending',?,?,?,?,?,?,?,?)")
        ->execute([(int)$user_id, $amt, $type, $plan_id ?: null, (int)$credit_tokens, mb_substr($desc, 0, 250), biz_now(),
                   (int)($quote['original'] ?? $amt), max(0, (int)($quote['original'] ?? $amt) - (int)($quote['final'] ?? $amt)), (int)($quote['code_id'] ?? 0), (int)$sms_count,
                   $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, (int)$setup_fee]);
    return (int)$pdo->lastInsertId();
}

} // BIZ_LOADED

// =============================================================================
// همکاران / مشاوران حساب کاربر (با سطح دسترسی)
// =============================================================================
/** فهرست سطوح دسترسی همکار */
function saas_team_perm_list()
{
    return [
        'widget'        => 'تنظیمات ویجت و کد نصب',
        'knowledge'     => 'پایگاه دانش',
        'products'      => 'مدیریت محصولات',
        'conversations' => 'مکالمات کاربران و آرشیو پاسخ‌ها',
        'bots_view'     => 'چت‌بات‌ها: مشاهده گفتگوها، مخاطبان و گزارش‌ها (و پاسخ آزمایشی)',
        'bots_manage'   => 'چت‌بات‌ها: تنظیمات، منابع دانش، پاسخ‌های تأییدشده، فروش و نصب',
        'consult'       => 'مشاور: پرونده و گفتگوهای مراجعانی که اجازه داده‌اند + ثبت نظر و نتیجه جلسه',
        'studio'        => 'ساخت تصویر و ویدیو (از اعتبار حساب)',
        'billing'       => 'اعتبار و پرداخت (خرید و تمدید)',
    ];
}

/** دسترسی لازم برای هر صفحه پنل کاربر (null = آزاد، owner = فقط صاحب حساب) */
function saas_page_perm($page)
{
    $map = [
        'settings.php' => 'widget', 'embed.php' => 'widget', 'knowledge.php' => 'knowledge', 'products.php' => 'products',
        'conversations.php' => 'conversations', 'answers.php' => 'conversations', 'bots.php' => 'bots_view', 'bot.php' => 'bots_view',
        'studio.php' => 'studio', 'billing.php' => 'billing', 'custom_plan.php' => 'billing', 'leads.php' => 'widget', 'team.php' => 'owner', 'popups.php' => 'bots_manage', 'messages.php' => 'bots_view', 'receipts.php' => 'bots_manage',
    ];
    return $map[$page] ?? null;
}

/** آیا کاربر فعلی (صاحب حساب یا همکار) این دسترسی را دارد؟ */
function saas_can($perm)
{
    $t = $GLOBALS['current_team'] ?? null;
    if (!$t || $perm === null) return true;
    if ($perm === 'owner') return false;
    $list = $t['perm_list'] ?? [];
    if ($perm === 'bots_view' && (in_array('bots_manage', $list, true) || in_array('consult', $list, true))) return true;
    return in_array($perm, $list, true);
}

function biz_team_row($r)
{
    if (!$r) return null;
    $r['perm_list'] = array_values(array_intersect(array_keys(saas_team_perm_list()), array_filter(explode(',', (string)$r['perms']))));
    return $r;
}

function biz_team_get($pdo, $id)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_team WHERE id=?");
        $s->execute([(int)$id]);
        return biz_team_row($s->fetch(\PDO::FETCH_ASSOC) ?: null);
    } catch (\Throwable $e) { return null; }
}

function biz_team_list($pdo, $owner_id)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_team WHERE owner_id=? ORDER BY id ASC");
        $s->execute([(int)$owner_id]);
        return array_map('biz_team_row', $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { return []; }
}

/**
 * افزودن / ویرایش همکار
 * @return array ['ok', 'error', 'id']
 */
function biz_team_save($pdo, $owner_id, $data, $id = 0)
{
    $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 120);
    $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 120);
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $mobile = biz_mobile($data['mobile'] ?? '');
    $pw = (string)($data['password'] ?? '');
    $perms = implode(',', array_values(array_intersect(array_keys(saas_team_perm_list()), (array)($data['perms'] ?? []))));
    $active = !empty($data['is_active']) ? 1 : 0;
    if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'نام همکار را وارد کنید.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'ایمیل معتبر نیست (همکار با این ایمیل وارد پنل می‌شود).'];
    $u = $pdo->prepare("SELECT COUNT(*) FROM saas_users WHERE email=?");
    $u->execute([$email]);
    if ((int)$u->fetchColumn() > 0) return ['ok' => false, 'error' => 'این ایمیل متعلق به یک حساب کاربری است؛ ایمیل دیگری وارد کنید.'];
    $t = $pdo->prepare("SELECT COUNT(*) FROM saas_team WHERE email=? AND id<>?");
    $t->execute([$email, (int)$id]);
    if ((int)$t->fetchColumn() > 0) return ['ok' => false, 'error' => 'این ایمیل قبلاً برای همکار دیگری ثبت شده است.'];
    if ($pw !== '' || !$id) {
        if (function_exists('saas_validate_password_strength') && ($pe = saas_validate_password_strength($pw))) return ['ok' => false, 'error' => $pe];
    }
    if ($id) {
        $own = $pdo->prepare("SELECT COUNT(*) FROM saas_team WHERE id=? AND owner_id=?");
        $own->execute([(int)$id, (int)$owner_id]);
        if (!(int)$own->fetchColumn()) return ['ok' => false, 'error' => 'همکار یافت نشد.'];
        $pdo->prepare("UPDATE saas_team SET name=?, title=?, email=?, mobile=?, perms=?, is_active=? WHERE id=?")->execute([$name, $title, $email, $mobile, $perms, $active, (int)$id]);
        if ($pw !== '') $pdo->prepare("UPDATE saas_team SET password_hash=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$id]);
        return ['ok' => true, 'error' => '', 'id' => (int)$id];
    }
    $pdo->prepare("INSERT INTO saas_team (owner_id, name, title, email, mobile, password_hash, perms, is_active, created_at) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([(int)$owner_id, $name, $title, $email, $mobile, password_hash($pw, PASSWORD_DEFAULT), $perms, $active, biz_now()]);
    return ['ok' => true, 'error' => '', 'id' => (int)$pdo->lastInsertId()];
}

/** ورود همکار با ایمیل و رمز */
function biz_team_login($pdo, $email, $password)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_team WHERE email=?");
        $s->execute([strtolower(trim((string)$email))]);
        $t = $s->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $t = null; }
    if (!$t || !password_verify((string)$password, $t['password_hash'])) return ['ok' => false, 'error' => 'ایمیل یا رمز عبور اشتباه است.'];
    if (empty($t['is_active'])) return ['ok' => false, 'error' => 'دسترسی شما توسط مدیر حساب غیرفعال شده است.'];
    $o = $pdo->prepare("SELECT status FROM saas_users WHERE id=?");
    $o->execute([(int)$t['owner_id']]);
    if ($o->fetchColumn() !== 'active') return ['ok' => false, 'error' => 'حساب کاربری مربوط غیرفعال است.'];
    $pdo->prepare("UPDATE saas_team SET last_login=? WHERE id=?")->execute([biz_now(), (int)$t['id']]);
    return ['ok' => true, 'error' => '', 'member' => biz_team_row($t)];
}

// =============================================================================
// ورود با گوگل (Gmail) — OAuth 2.0
// =============================================================================
function biz_google_enabled($pdo)
{
    if (function_exists('comm_login_is')) return comm_login_is($pdo, 'user', 'google');
    $s = biz_settings($pdo);
    return !empty($s['google_login']) && $s['google_client_id'] !== '' && $s['google_client_secret'] !== '';
}

function biz_google_redirect_uri()
{
    return rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/user/google.php';
}

function biz_google_auth_url($pdo, $state)
{
    $s = biz_settings($pdo);
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $s['google_client_id'], 'redirect_uri' => biz_google_redirect_uri(), 'response_type' => 'code',
        'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account', 'access_type' => 'online',
    ]);
}

/**
 * تبدیل code به اطلاعات کاربر گوگل
 * @return array ['ok', 'error', 'sub', 'email', 'name']
 */
function biz_google_exchange($pdo, $code, $redirect_uri = null)
{
    $s = biz_settings($pdo);
    $post = function ($url, $fields, $headers = []) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        if ($fields !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)); }
        $r = curl_exec($ch);
        $e = curl_error($ch);
        curl_close($ch);
        return [$r ? json_decode($r, true) : null, $e];
    };
    [$tok, $err] = $post('https://oauth2.googleapis.com/token', [
        'code' => $code, 'client_id' => $s['google_client_id'], 'client_secret' => $s['google_client_secret'],
        'redirect_uri' => $redirect_uri ?: biz_google_redirect_uri(), 'grant_type' => 'authorization_code',
    ]);
    if (empty($tok['access_token'])) {
        error_log('[GOOGLE] token: ' . $err . ' ' . json_encode($tok));
        return ['ok' => false, 'error' => $err !== '' ? 'سرور به گوگل دسترسی ندارد.' : 'ورود با گوگل تأیید نشد؛ دوباره تلاش کنید.'];
    }
    [$info, $err] = $post('https://openidconnect.googleapis.com/v1/userinfo', null, ['Authorization: Bearer ' . $tok['access_token']]);
    if (empty($info['sub']) || empty($info['email'])) return ['ok' => false, 'error' => 'اطلاعات حساب گوگل دریافت نشد.'];
    if (isset($info['email_verified']) && !$info['email_verified']) return ['ok' => false, 'error' => 'ایمیل حساب گوگل شما تأیید نشده است.'];
    return ['ok' => true, 'error' => '', 'sub' => (string)$info['sub'], 'email' => strtolower((string)$info['email']), 'name' => (string)($info['name'] ?? '')];
}

// =============================================================================
// گالری نمونه‌ها و پرامپت‌های پس‌زمینه (طراحی و ویدیو)
// =============================================================================
/** نسخه ۵۰: زیردسته (تا ۳ سطح) و پرامپت متصل به هر نمونه */
if (!defined('GAL_MAX_DEPTH')) define('GAL_MAX_DEPTH', 3);
function biz_gallery_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.gal_schema_v3' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        saas_add_column_if_missing($pdo, 'saas_gallery_cats', 'parent_id', 'INT NOT NULL DEFAULT 0');
        saas_add_column_if_missing($pdo, 'saas_gallery', 'preset_id', 'INT NOT NULL DEFAULT 0');
        saas_add_column_if_missing($pdo, 'saas_gallery', 'tags', "VARCHAR(500) NOT NULL DEFAULT ''");   // کلمات کلیدی جستجو (نسخه ۵۱)
        saas_add_column_if_missing($pdo, 'saas_gallery', 'action_id', 'INT NOT NULL DEFAULT 0');         // اتصال به «اقدام آماده تصویر/ویدیو» (نسخه ۵۲)
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[GAL] schema: ' . $e->getMessage()); }
}

/**
 * دسته‌ها به‌صورت درختی و مرتب (والد، سپس فرزندان) با depth (۱ تا ۳)، path (عنوان کامل) و ids زیرمجموعه
 * @return array id => row
 */
function biz_gallery_tree($pdo, $kind)
{
    $rows = biz_gallery_cats_flat($pdo, $kind);
    $by = []; $kids = [];
    foreach ($rows as $r) { $r['parent_id'] = (int)($r['parent_id'] ?? 0); $by[(int)$r['id']] = $r; }
    foreach ($by as $id => $r) { $p = $r['parent_id']; if ($p && !isset($by[$p])) $p = 0; $by[$id]['parent_id'] = $p; $kids[$p][] = $id; }
    $out = [];
    $walk = function ($pid, $depth, $path) use (&$walk, &$out, $by, $kids) {
        foreach ($kids[$pid] ?? [] as $id) {
            if (isset($out[$id])) continue;   // جلوگیری از حلقه
            $r = $by[$id];
            $r['depth'] = $depth;
            $r['path'] = $path === '' ? $r['title'] : $path . ' › ' . $r['title'];
            $out[$id] = $r;
            $walk($id, $depth + 1, $r['path']);
        }
    };
    $walk(0, 1, '');
    foreach ($out as $id => &$r) {
        $d = [$id]; $q = [$id];
        while ($q) { $x = array_shift($q); foreach ($kids[$x] ?? [] as $c) { $d[] = $c; $q[] = $c; } }
        $r['desc_ids'] = $d;
        $a = []; $x = $r['parent_id'];
        while ($x && isset($by[$x]) && count($a) < 10) { $a[] = $x; $x = $by[$x]['parent_id']; }
        $r['anc_ids'] = $a;
    }
    unset($r);
    return $out;
}

function biz_gallery_cats_flat($pdo, $kind)
{
    biz_gallery_schema($pdo);
    try {
        $s = $pdo->prepare("SELECT * FROM saas_gallery_cats WHERE kind=? ORDER BY sort_order ASC, id ASC");
        $s->execute([$kind]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

function biz_gallery_cats($pdo, $kind)
{
    biz_gallery_schema($pdo);
    try {
        $s = $pdo->prepare("SELECT * FROM saas_gallery_cats WHERE kind=? ORDER BY sort_order ASC, id ASC");
        $s->execute([$kind]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

function biz_gallery($pdo, $kind, $active_only = true, $cat_id = 0)
{
    try {
        $w = "kind=?"; $p = [$kind];
        if ($active_only) $w .= " AND is_active=1";
        if ($cat_id) {
            // دسته + همه زیردسته‌هایش
            $tree = biz_gallery_tree($pdo, $kind);
            $ids = $tree[(int)$cat_id]['desc_ids'] ?? [(int)$cat_id];
            $w .= " AND cat_id IN (" . implode(',', array_map('intval', $ids)) . ")";
        }
        $s = $pdo->prepare("SELECT * FROM saas_gallery WHERE {$w} ORDER BY sort_order ASC, id DESC LIMIT 2000");
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

function biz_gallery_item($pdo, $id)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_gallery WHERE id=?");
        $s->execute([(int)$id]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
}

/** پرامپت‌های پس‌زمینه یک نوع (در صورت تعیین دسته، فقط موارد مجاز آن دسته) */
function biz_presets($pdo, $kind, $active_only = true, $cat_id = 0)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_prompt_presets WHERE kind=?" . ($active_only ? " AND is_active=1" : "") . " ORDER BY sort_order ASC, id ASC");
        $s->execute([$kind]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
    if ($cat_id) {
        // پرامپت مجاز برای این دسته یا یکی از دسته‌های بالاتر آن
        $tree = biz_gallery_tree($pdo, $kind);
        $chain = array_merge([(int)$cat_id], $tree[(int)$cat_id]['anc_ids'] ?? []);
        $rows = array_values(array_filter($rows, function ($r) use ($chain) {
            $ids = array_filter(array_map('intval', explode(',', (string)$r['cat_ids'])));
            return !$ids || array_intersect($chain, $ids);
        }));
    }
    return $rows;
}

/** ساخت پرامپت نهایی از پرامپت پس‌زمینه + توضیح نمونه + درخواست کاربر */
function biz_compose_prompt($preset, $sample, $user_text)
{
    $user_text = trim((string)$user_text);
    $sample_desc = $sample ? trim(($sample['title'] ?? '') . '. ' . ($sample['prompt'] ?? '')) : '';
    $tpl = trim((string)($preset['prompt'] ?? ''));
    if ($tpl === '') return trim($sample_desc . "\n" . $user_text);
    $out = str_replace(['{نمونه}', '{sample}'], $sample_desc, $tpl);
    if (strpos($out, '{درخواست}') !== false || strpos($out, '{request}') !== false) $out = str_replace(['{درخواست}', '{request}'], $user_text !== '' ? $user_text : '-', $out);
    elseif ($user_text !== '') $out .= "\n" . $user_text;
    return trim($out);
}

/** فایل مرجع نمونه برای ساخت (تصویر نمونه یا پوستر ویدیو) از روی دیسک */
function biz_sample_ref($sample)
{
    if (!$sample) return null;
    $url = $sample['kind'] === 'video' ? (string)$sample['poster_url'] : (string)$sample['file_url'];
    $prefix = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/';
    if ($url === '' || strpos($url, $prefix) !== 0) return null;
    $path = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/' . ltrim(substr($url, strlen($prefix)), '/');
    if (strpos($path, '..') !== false || !is_file($path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? null;
    return $mime ? ['bytes' => (string)file_get_contents($path), 'mime' => $mime] : null;
}

/** ذخیره فایل آپلودی گالری (مدیر) */
function biz_gallery_upload($file, $kind, $allow)
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'error' => 'فایلی انتخاب نشده است.'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (!in_array($ext, $allow, true)) return ['ok' => false, 'error' => 'نوع فایل مجاز نیست (' . implode('، ', $allow) . ').'];
    $max = in_array($ext, ['mp4', 'webm'], true) ? 60 * 1024 * 1024 : 8 * 1024 * 1024;
    if ($file['size'] > $max) return ['ok' => false, 'error' => 'حجم فایل بیش از حد مجاز است.'];
    if (in_array($ext, ['jpg', 'png', 'webp'], true) && !@getimagesize($file['tmp_name'])) return ['ok' => false, 'error' => 'فایل تصویر معتبر نیست.'];
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/gallery/' . ($kind === 'video' ? 'video' : 'image');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name) && !@copy($file['tmp_name'], $dir . '/' . $name)) return ['ok' => false, 'error' => 'ذخیره فایل ممکن نشد.'];
    return ['ok' => true, 'url' => rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/gallery/' . ($kind === 'video' ? 'video' : 'image') . '/' . $name];
}

/** حذف فایل گالری از دیسک */
function biz_gallery_unlink($url)
{
    $prefix = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/uploads/gallery/';
    if ($url === '' || strpos($url, $prefix) !== 0) return;
    $rel = substr($url, strlen($prefix));
    if (strpos($rel, '..') !== false) return;
    @unlink(rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/gallery/' . $rel);
}

require_once __DIR__ . '/biz_price.php';
require_once __DIR__ . '/extras_lib.php';
require_once __DIR__ . '/comm_lib.php';
require_once __DIR__ . '/kb_lib.php';
require_once __DIR__ . '/chatx_lib.php';
require_once __DIR__ . '/ipguard_lib.php';
require_once __DIR__ . '/ui_lib.php';
require_once __DIR__ . '/gen_lib.php';
require_once __DIR__ . '/rem_lib.php';
require_once __DIR__ . '/sup_lib.php';
require_once __DIR__ . '/lim_lib.php';
require_once __DIR__ . '/msg_lib.php';
require_once __DIR__ . '/genact_lib.php';
require_once __DIR__ . '/plancat_lib.php';   // دسته‌بندی پلن‌ها (نسخه ۴۵)
require_once __DIR__ . '/act_lib.php';       // گزارش ورود و بازدید (نسخه ۴۷)
require_once __DIR__ . '/macc_lib.php';      // پنل مرکزی مخاطبان (نسخه ۴۷)
require_once __DIR__ . '/gal_lib.php';       // گالری: جستجوی هوشمند، دسته‌های آبشاری، گالری در چت‌بات (نسخه ۵۱)
require_once __DIR__ . '/c2c_lib.php';       // کارت به کارت و فیش واریزی، ثبت دستی مخاطب/کاربر (نسخه ۵۶)
require_once __DIR__ . '/mdlx_lib.php';
require_once __DIR__ . '/mcap_lib.php';      // کارایی مدل‌ها، تاریخ حذف و آمار درخواست روزانه (نسخه ۷۲)
require_once __DIR__ . '/mcr_lib.php';       // شارژ تومانی مخاطبان چت‌بات (قیمت مدیر + درصد ثابت) (نسخه ۶۲)      // دسترسی به مدل‌ها: مدل تازه خودکار در همه پلن‌ها و چت‌بات‌ها، سقف استفاده هر مدل در بسته‌ها (نسخه ۵۹)
