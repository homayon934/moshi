<?php
require_once __DIR__ . '/sec_lib.php';   // لایه امنیتی (کپچا یک‌بارمصرف، کلید مخفی، ...)
/**
 * کتابخانه SaaS - مدیریت کاربران، پلن‌ها، اعتبار و تنظیمات
 * AI Chat Widget - Multi-Tenant SaaS
 */

if (!function_exists('saas_add_column_if_missing')) {
    function saas_add_column_if_missing($pdo, $table, $column, $definition)
    {
        foreach ([$table, $column] as $identifier) {
            if (!is_string($identifier) || !preg_match('/\\A[A-Za-z0-9_]+\\z/', $identifier)) {
                throw new InvalidArgumentException('Invalid schema identifier');
            }
        }
        $previous_mode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute([$table, $column]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, $previous_mode);
        }
    }
}

if (!function_exists('saas_add_unique_index_if_missing')) {
    function saas_add_unique_index_if_missing($pdo, $table, $index, $columns)
    {
        foreach ([$table, $index] as $identifier) {
            if (!is_string($identifier) || !preg_match('/\\A[A-Za-z0-9_]+\\z/', $identifier)) {
                throw new InvalidArgumentException('Invalid schema identifier');
            }
        }
        $previous_mode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $check->execute([$table, $index]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD UNIQUE INDEX `' . $index . '` ' . $columns);
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, $previous_mode);
        }
    }
}

if (!function_exists('saas_ensure_schema')) {
    function saas_ensure_schema($pdo)
    {
        static $done = false;
        if ($done) return;
        $done = true;
        // نسخه ساختار دیتابیس: با هر تغییر ساختار این تابع، عدد را یک واحد بیشتر کنید.
        // اگر نسخه جاری قبلاً ساخته شده، بررسی‌های سنگین تکرار نمی‌شود (سرعت بیشتر همه صفحه‌ها)
        $saas_flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.saas_schema_v1' : '';
        if ($saas_flag !== '' && is_file($saas_flag)) {
            if (function_exists('biz_ensure_schema')) { try { biz_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[BIZ] schema: ' . $e->getMessage()); } }
            return;
        }
        // --- جدول تنظیمات مرکزی API (فقط ادمین) ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_api_config (
            id INT PRIMARY KEY DEFAULT 1,
            avalai_enabled TINYINT(1) NOT NULL DEFAULT 1,
            avalai_api_key VARCHAR(255) NOT NULL DEFAULT '',
            avalai_api_base VARCHAR(255) NOT NULL DEFAULT 'https://api.avalai.ir/v1',
            avalai_model VARCHAR(100) NOT NULL DEFAULT 'gpt-4o-mini',
            openrouter_enabled TINYINT(1) NOT NULL DEFAULT 0,
            openrouter_api_key VARCHAR(255) NOT NULL DEFAULT '',
            openrouter_api_base VARCHAR(255) NOT NULL DEFAULT 'https://openrouter.ai/api/v1',
            openrouter_model VARCHAR(100) NOT NULL DEFAULT 'google/gemini-2.5-flash',
            provider_priority VARCHAR(20) NOT NULL DEFAULT 'avalai',
            cost_per_1k_tokens DECIMAL(10,2) NOT NULL DEFAULT 1000,
            sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sms_provider VARCHAR(50) NOT NULL DEFAULT 'kavenegar',
            sms_api_key VARCHAR(255) NOT NULL DEFAULT '',
            sms_sender VARCHAR(50) NOT NULL DEFAULT '',
            sms_low_credit_percent INT NOT NULL DEFAULT 20,
            zarinpal_merchant_id VARCHAR(100) NOT NULL DEFAULT '',
            zarinpal_sandbox TINYINT(1) NOT NULL DEFAULT 1,
            email_enabled TINYINT(1) NOT NULL DEFAULT 0,
            smtp_host VARCHAR(255) NOT NULL DEFAULT '',
            smtp_port SMALLINT NOT NULL DEFAULT 587,
            smtp_user VARCHAR(255) NOT NULL DEFAULT '',
            smtp_pass VARCHAR(255) NOT NULL DEFAULT '',
            smtp_from VARCHAR(255) NOT NULL DEFAULT '',
            smtp_from_name VARCHAR(100) NOT NULL DEFAULT 'ویجت هوشمند',
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // جستجوی زنده گوگل (برای چت‌بات تخصصی — فقط در دامنه‌های مجاز)
        try {
            saas_add_column_if_missing($pdo, 'saas_api_config', 'google_cse_key', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'google_cse_cx', 'VARCHAR(255) NOT NULL DEFAULT \'\'');
        } catch (\Throwable $e) {}

        $exists = $pdo->query("SELECT COUNT(*) FROM saas_api_config WHERE id=1")->fetchColumn();
        if (!$exists) {
            $pdo->exec("INSERT INTO saas_api_config (id) VALUES (1)");
        }

        // --- جدول پلن‌ها ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            price_toman INT NOT NULL DEFAULT 0,
            credit_tokens INT NOT NULL DEFAULT 50000,
            max_requests_per_day INT NOT NULL DEFAULT 100,
            max_tokens_per_request INT NOT NULL DEFAULT 500,
            response_length ENUM('short','medium','long') NOT NULL DEFAULT 'medium',
            has_knowledge_base TINYINT(1) NOT NULL DEFAULT 1,
            max_knowledge_items INT NOT NULL DEFAULT 10,
            has_products TINYINT(1) NOT NULL DEFAULT 0,
            max_products INT NOT NULL DEFAULT 0,
            has_color_customize TINYINT(1) NOT NULL DEFAULT 1,
            has_product_api TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- امکانات جدید پلن‌ها: مکالمات کاربران، آرشیو پاسخ‌ها، توضیح هر امکان ---
        try {
            $had_conv_col = $pdo->query("SHOW COLUMNS FROM saas_plans LIKE 'has_conversations'")->rowCount() > 0;
            if (!$had_conv_col) {
                saas_add_column_if_missing($pdo, 'saas_plans', 'has_conversations', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER has_product_api');
                saas_add_column_if_missing($pdo, 'saas_plans', 'has_answer_archive', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER has_conversations');
                saas_add_column_if_missing($pdo, 'saas_plans', 'feature_notes', 'TEXT NULL AFTER has_answer_archive');
                // یک بار: پلن‌های موجود امکانات محصولات و API را از دست ندهند (قبلاً بدون محدودیت فعال بودند)
                $pdo->exec("UPDATE saas_plans SET has_products=1, has_product_api=1");
            }
        } catch (\Throwable $e) {}

        try {
            saas_add_column_if_missing($pdo, 'saas_plans', 'has_site_posts', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER has_product_api');
            saas_add_column_if_missing($pdo, 'saas_plans', 'has_site_pages', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER has_site_posts');
            // چت‌بات تخصصی (همدم): پیش‌فرض خاموش — مدیر برای هر پلن فعال می‌کند
            saas_add_column_if_missing($pdo, 'saas_plans', 'has_expert_bot', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER has_site_pages');
            saas_add_column_if_missing($pdo, 'saas_plans', 'max_bots', 'INT NOT NULL DEFAULT 1 AFTER has_expert_bot');
        } catch (\Throwable $e) {}

        // --- جدول کاربران SaaS ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(200) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            site_key VARCHAR(64) NOT NULL UNIQUE,
            plan_id INT NULL,
            plan_expires_at DATETIME NULL,
            credit_tokens BIGINT NOT NULL DEFAULT 0,
            status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
            sms_notified_low TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL,
            reset_token VARCHAR(64) NULL,
            reset_expires DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- جدول پرداخت‌ها ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount_toman INT NOT NULL,
            type ENUM('plan','credit') NOT NULL DEFAULT 'credit',
            plan_id INT NULL,
            credit_tokens INT NOT NULL DEFAULT 0,
            status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
            authority VARCHAR(100) NULL,
            ref_code VARCHAR(100) NULL,
            description VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            INDEX idx_user (user_id),
            INDEX idx_authority (authority),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- جدول لاگ اعتبار ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_credit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            tokens_delta BIGINT NOT NULL,
            description VARCHAR(255) NULL,
            ref_id INT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- جدول تنظیمات ویجت هر کاربر ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_widget_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            store_info TEXT NULL,
            guide_text MEDIUMTEXT NULL,
            guide_filename VARCHAR(255) NOT NULL DEFAULT '',
            response_template TEXT NULL,
            primary_color VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
            secondary_color VARCHAR(20) NOT NULL DEFAULT '#ede9fe',
            text_color VARCHAR(20) NOT NULL DEFAULT '#ffffff',
            widget_title VARCHAR(150) NOT NULL DEFAULT 'دستیار هوشمند',
            welcome_message VARCHAR(500) NOT NULL DEFAULT 'سلام! چطور می‌تونم کمکتون کنم؟',
            contact_phone VARCHAR(30) NOT NULL DEFAULT '',
            position ENUM('right','left') NOT NULL DEFAULT 'right',
            show_lead_form TINYINT(1) NOT NULL DEFAULT 0,
            max_questions_session INT NOT NULL DEFAULT 0,
            response_length ENUM('short','medium','long') NOT NULL DEFAULT 'medium',
            product_api_url VARCHAR(500) NOT NULL DEFAULT '',
            product_api_key VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // ستون‌های جدید به جدول قدیمی (در صورت آپگرید)
        try {
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'text_color', 'VARCHAR(20) NOT NULL DEFAULT \'#ffffff\' AFTER secondary_color');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'position', 'ENUM(\'right\',\'left\') NOT NULL DEFAULT \'right\' AFTER contact_phone');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'show_lead_form', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER position');
        } catch(\Throwable $e) {}

        // ستون‌های reset_token و reset_expires (در صورت آپگرید از نسخه قدیمی‌تر که این ستون‌ها نداشت)
        try {
            saas_add_column_if_missing($pdo, 'saas_users', 'reset_token', 'VARCHAR(64) NULL AFTER last_login');
            saas_add_column_if_missing($pdo, 'saas_users', 'reset_expires', 'DATETIME NULL AFTER reset_token');
        } catch(\Throwable $e) {}

        // ستون‌های بازیابی رمز و سوال محرمانه در saas_users
        try {
            saas_add_column_if_missing($pdo, 'saas_users', 'secret_question', 'VARCHAR(255) NULL AFTER reset_expires');
            saas_add_column_if_missing($pdo, 'saas_users', 'secret_answer_hash', 'VARCHAR(255) NULL AFTER secret_question');
            saas_add_column_if_missing($pdo, 'saas_users', 'otp_code', 'VARCHAR(10) NULL AFTER secret_answer_hash');
            saas_add_column_if_missing($pdo, 'saas_users', 'otp_expires', 'DATETIME NULL AFTER otp_code');
            // یکتا بودن شماره موبایل
            saas_add_unique_index_if_missing($pdo, 'saas_users', 'uniq_phone', '(phone)');
        } catch(\Throwable $e) {}

        // ستون‌های ایمیل در saas_api_config (در صورت آپگرید از نسخه قدیمی‌تر)
        try {
            saas_add_column_if_missing($pdo, 'saas_api_config', 'email_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER zarinpal_sandbox');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'smtp_host', 'VARCHAR(255) NOT NULL DEFAULT \'\' AFTER email_enabled');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'smtp_port', 'SMALLINT NOT NULL DEFAULT 587 AFTER smtp_host');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'smtp_user', 'VARCHAR(255) NOT NULL DEFAULT \'\' AFTER smtp_port');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'smtp_pass', 'VARCHAR(255) NOT NULL DEFAULT \'\' AFTER smtp_user');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'smtp_from', 'VARCHAR(255) NOT NULL DEFAULT \'\' AFTER smtp_pass');
            saas_add_column_if_missing($pdo, 'saas_api_config', 'smtp_from_name', 'VARCHAR(100) NOT NULL DEFAULT \'ویجت هوشمند\' AFTER smtp_from');
        } catch(\Throwable $e) {}

        // --- جدول پایگاه دانش هر کاربر ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_knowledge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            keywords VARCHAR(500) NOT NULL DEFAULT '',
            content MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // افزودن ستون keywords به جداول قدیمی (در صورت آپگرید)
        try {
            saas_add_column_if_missing($pdo, 'saas_knowledge', 'keywords', 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER title');
        } catch(\Throwable $e) {}

        // --- جدول محصولات هر کاربر ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description MEDIUMTEXT NULL,
            price DECIMAL(14,0) NULL,
            url VARCHAR(500) NULL,
            image_url VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- جدول مکالمات (چندکاربره) ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL DEFAULT '',
            visitor_name VARCHAR(255) NULL,
            visitor_phone VARCHAR(20) NULL,
            visitor_email VARCHAR(255) NULL,
            ip VARCHAR(45) NULL,
            message_count INT NOT NULL DEFAULT 0,
            last_message_at DATETIME NULL,
            ended_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_user (user_id),
            INDEX idx_token (token),
            INDEX idx_phone (visitor_phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- جدول پیام‌ها (چندکاربره) ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            message MEDIUMTEXT NOT NULL,
            tokens_used INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_conv (conversation_id),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- افزودن ستون‌های جدید به جداول موجود (در صورت نیاز) ---
        try {
            saas_add_column_if_missing($pdo, 'saas_conversations', 'visitor_email', 'VARCHAR(255) NULL AFTER visitor_phone');
            saas_add_column_if_missing($pdo, 'saas_conversations', 'message_count', 'INT NOT NULL DEFAULT 0 AFTER visitor_email');
            saas_add_column_if_missing($pdo, 'saas_conversations', 'last_message_at', 'DATETIME NULL AFTER message_count');
            saas_add_column_if_missing($pdo, 'saas_conversations', 'ended_at', 'DATETIME NULL AFTER last_message_at');
        } catch(\Throwable $e) { /* نادیده گرفتن خطاهای ALTER در صورتی که ستون وجود داشته باشد */ }

        // --- آرشیو پاسخ‌های تکراری (بدون مصرف توکن) ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_answer_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            q_key VARCHAR(500) NOT NULL,
            question VARCHAR(1000) NOT NULL,
            answer MEDIUMTEXT NOT NULL,
            images TEXT NULL,
            hits INT NOT NULL DEFAULT 0,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_hit_at DATETIME NULL,
            INDEX idx_user_key (user_id, q_key(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- کش نتایج API محصولات سایت (برای سرعت و کاهش درخواست) ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_api_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            q_hash CHAR(32) NOT NULL,
            data MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_q (user_id, q_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- مطالب (وبلاگ) و صفحات ثابت سایت کاربر ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_site_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            kind VARCHAR(10) NOT NULL,
            url VARCHAR(500) NOT NULL,
            title VARCHAR(500) NOT NULL DEFAULT '',
            content MEDIUMTEXT NULL,
            lastmod VARCHAR(40) NOT NULL DEFAULT '',
            fetched_ok TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_kind_url (user_id, kind, url(180)),
            INDEX idx_user_kind (user_id, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // --- فهرست محصولات سایت کاربر (ساخته‌شده از نقشه سایت / sitemap) ---
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_site_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            url VARCHAR(500) NOT NULL,
            title VARCHAR(500) NOT NULL DEFAULT '',
            description MEDIUMTEXT NULL,
            price_text VARCHAR(255) NOT NULL DEFAULT '',
            image VARCHAR(500) NOT NULL DEFAULT '',
            stock TINYINT(1) NULL,
            lastmod VARCHAR(40) NOT NULL DEFAULT '',
            fetched_ok TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_url (user_id, url(191)),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // حالت قدیمی «صفحه جستجو» دوباره تشخیص داده شود (ممکن است نقشه سایت پشتیبانی شود)
        try { $pdo->exec("UPDATE saas_widget_settings SET product_api_mode='' WHERE product_api_mode='html'"); } catch (\Throwable $e) {}

        try {
            saas_add_column_if_missing($pdo, 'saas_messages', 'from_cache', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER tokens_used');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'product_api_mode', 'VARCHAR(20) NOT NULL DEFAULT \'\' AFTER product_api_key');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'cache_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER product_api_mode');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'cache_days', 'INT NOT NULL DEFAULT 7 AFTER cache_enabled');
            // آیکون‌های ویجت و نمایش نیمه‌باز خودکار
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'launcher_icon', 'VARCHAR(20) NOT NULL DEFAULT \'chat\' AFTER cache_days');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'launcher_icon_url', 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER launcher_icon');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'send_icon', 'VARCHAR(20) NOT NULL DEFAULT \'plane\' AFTER launcher_icon_url');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'send_icon_url', 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER send_icon');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'auto_peek', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER send_icon_url');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'phone_color', 'VARCHAR(20) NOT NULL DEFAULT \'#10b981\' AFTER auto_peek');
            // اتصال مطالب (وبلاگ) و صفحات ثابت سایت
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'site_posts_url', 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER phone_color');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'site_posts_extra', 'TEXT NULL AFTER site_posts_url');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'site_pages_url', 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER site_posts_extra');
            saas_add_column_if_missing($pdo, 'saas_widget_settings', 'site_pages_extra', 'TEXT NULL AFTER site_pages_url');
        } catch(\Throwable $e) {}

        if ($saas_flag !== '') @file_put_contents($saas_flag, date('c'));

        // امکانات تجاری: اشتراک، پیامک، تخفیف، همکاری در فروش، اعلان‌ها (includes/biz_lib.php)
        if (function_exists('biz_ensure_schema')) {
            try { biz_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[BIZ] schema: ' . $e->getMessage()); }
        }
    }
}

// =============================================================================
// تنظیمات مرکزی API
// =============================================================================
if (!function_exists('saas_get_api_config')) {
    function saas_get_api_config($pdo)
    {
        $row = $pdo->query("SELECT * FROM saas_api_config WHERE id=1")->fetch();
        if (!$row) $row = [];
        return [
            'avalai_enabled'        => !empty($row['avalai_enabled']),
            'avalai_api_key'        => $row['avalai_api_key'] ?? '',
            'avalai_api_base'       => !empty($row['avalai_api_base']) ? $row['avalai_api_base'] : 'https://api.avalai.ir/v1',
            'avalai_model'          => !empty($row['avalai_model']) ? $row['avalai_model'] : 'gpt-4o-mini',
            'openrouter_enabled'    => !empty($row['openrouter_enabled']),
            'openrouter_api_key'    => $row['openrouter_api_key'] ?? '',
            'openrouter_api_base'   => !empty($row['openrouter_api_base']) ? $row['openrouter_api_base'] : 'https://openrouter.ai/api/v1',
            'openrouter_model'      => !empty($row['openrouter_model']) ? $row['openrouter_model'] : 'google/gemini-2.5-flash',
            'provider_priority'     => !empty($row['provider_priority']) ? $row['provider_priority'] : 'avalai',
            'cost_per_1k_tokens'    => isset($row['cost_per_1k_tokens']) ? (float)$row['cost_per_1k_tokens'] : 1000,
            'sms_enabled'           => !empty($row['sms_enabled']),
            'sms_provider'          => $row['sms_provider'] ?? 'kavenegar',
            'sms_api_key'           => $row['sms_api_key'] ?? '',
            'sms_sender'            => $row['sms_sender'] ?? '',
            'sms_low_credit_percent'=> isset($row['sms_low_credit_percent']) ? (int)$row['sms_low_credit_percent'] : 20,
            'zarinpal_merchant_id'  => $row['zarinpal_merchant_id'] ?? '',
            'zarinpal_sandbox'      => !empty($row['zarinpal_sandbox']),
            'email_enabled'         => !empty($row['email_enabled']),
            'smtp_host'             => $row['smtp_host'] ?? '',
            'smtp_port'             => isset($row['smtp_port']) ? (int)$row['smtp_port'] : 587,
            'smtp_user'             => $row['smtp_user'] ?? '',
            'smtp_pass'             => $row['smtp_pass'] ?? '',
            'smtp_from'             => $row['smtp_from'] ?? '',
            'smtp_from_name'        => !empty($row['smtp_from_name']) ? $row['smtp_from_name'] : 'ویجت هوشمند',
            'google_cse_key'        => $row['google_cse_key'] ?? '',
            'google_cse_cx'         => $row['google_cse_cx'] ?? '',
        ];
    }
}

// =============================================================================
// مدیریت پلن‌ها
// =============================================================================
if (!function_exists('saas_get_plans')) {
    function saas_get_plans($pdo, $active_only = true)
    {
        $where = $active_only ? 'WHERE is_active=1' : '';
        $stmt = $pdo->query("SELECT * FROM saas_plans $where ORDER BY sort_order, price_toman ASC");
        $rows = $stmt ? $stmt->fetchAll() : [];
        // پلن‌های دلخواهِ ساخته‌شده توسط کاربران در فهرست‌ها نمی‌آیند
        return array_values(array_filter($rows, fn($p) => empty($p['is_custom'])));
    }
}

if (!function_exists('saas_get_plan')) {
    function saas_get_plan($pdo, $plan_id)
    {
        $stmt = $pdo->prepare("SELECT * FROM saas_plans WHERE id=?");
        $stmt->execute([$plan_id]);
        return $stmt->fetch();
    }
}

// =============================================================================
// مدیریت کاربران
// =============================================================================
if (!function_exists('saas_register_user')) {
    /**
     * ثبت‌نام کاربر جدید
     * $secret_question / $secret_answer — اختیاری (بازیابی رمز از طریق سوال)
     */
    function saas_register_user($pdo, $email, $password, $full_name, $phone, $secret_question = '', $secret_answer = '')
    {
        $email = strtolower(trim($email));
        $phone = trim($phone);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'آدرس ایمیل معتبر نیست.'];
        }
        // قدرت رمز عبور
        $pwd_err = saas_validate_password_strength($password);
        if ($pwd_err) return ['ok' => false, 'error' => $pwd_err];

        if (!preg_match('/^09\d{9}$/', $phone)) {
            return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثال: 09123456789).'];
        }

        // یکتا بودن ایمیل
        $exists = $pdo->prepare("SELECT id FROM saas_users WHERE email=?");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            return ['ok' => false, 'error' => 'این ایمیل قبلاً ثبت‌نام شده است.'];
        }
        // یکتا بودن شماره موبایل
        $ph_exists = $pdo->prepare("SELECT id FROM saas_users WHERE phone=?");
        $ph_exists->execute([$phone]);
        if ($ph_exists->fetch()) {
            return ['ok' => false, 'error' => 'این شماره موبایل قبلاً ثبت‌نام شده است.'];
        }

        $site_key = bin2hex(random_bytes(24));
        $hash     = password_hash($password, PASSWORD_DEFAULT);
        $sq_hash  = $secret_answer ? password_hash(mb_strtolower(trim($secret_answer)), PASSWORD_DEFAULT) : null;
        $sq       = $secret_question ?: null;

        $stmt = $pdo->prepare("INSERT INTO saas_users
            (email, password_hash, full_name, phone, site_key, secret_question, secret_answer_hash, created_at)
            VALUES(?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$email, $hash, $full_name, $phone, $site_key, $sq, $sq_hash]);
        $user_id = (int)$pdo->lastInsertId();

        // ایجاد تنظیمات پیش‌فرض ویجت
        $pdo->prepare("INSERT INTO saas_widget_settings (user_id, updated_at) VALUES(?, NOW())")->execute([$user_id]);

        return ['ok' => true, 'user_id' => $user_id, 'site_key' => $site_key];
    }
}

if (!function_exists('saas_login_user')) {
    function saas_login_user($pdo, $email, $password)
    {
        $email = strtolower(trim($email));
        $stmt = $pdo->prepare("SELECT * FROM saas_users WHERE email=?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'ایمیل یا رمز عبور اشتباه است.'];
        }
        if ($user['status'] !== 'active') {
            return ['ok' => false, 'error' => 'حساب کاربری شما غیرفعال است. با پشتیبانی تماس بگیرید.'];
        }

        $pdo->prepare("UPDATE saas_users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
        return ['ok' => true, 'user' => $user];
    }
}


// =============================================================================
// اعتبارسنجی رمز عبور، کپچا، بازیابی رمز
// =============================================================================

if (!function_exists('saas_validate_password_strength')) {
    /**
     * بررسی قدرت رمز عبور
     * رمز باید: حداقل ۸ کاراکتر، حرف بزرگ، حرف کوچک، عدد، کاراکتر خاص داشته باشد
     * @return string پیام خطا (رشته خالی = قبول)
     */
    function saas_validate_password_strength(string $password): string
    {
        if (mb_strlen($password) < 8)
            return 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        if (!preg_match('/[A-Z]/', $password))
            return 'رمز عبور باید حداقل یک حرف بزرگ انگلیسی (A-Z) داشته باشد.';
        if (!preg_match('/[a-z]/', $password))
            return 'رمز عبور باید حداقل یک حرف کوچک انگلیسی (a-z) داشته باشد.';
        if (!preg_match('/[0-9]/', $password))
            return 'رمز عبور باید حداقل یک عدد داشته باشد.';
        if (!preg_match('/[^A-Za-z0-9]/', $password))
            return 'رمز عبور باید حداقل یک کاراکتر خاص (مثال: @، #، !) داشته باشد.';
        return '';
    }
}

if (!function_exists('saas_generate_captcha')) {
    /**
     * تولید CAPTCHA ریاضی با token هش‌شده — بدون نیاز به session
     * token در hidden field فرم قرار می‌گیرد
     */
    function saas_generate_captcha(): array
    {
        $a = rand(2, 15);
        $b = rand(1, 10);
        $op_index = rand(0, 2);
        $ops      = ['+', '-', 'x'];
        $op       = $ops[$op_index];
        switch ($op) {
            case '+': $answer = $a + $b; break;
            case '-': if ($b > $a) { $tmp=$a; $a=$b; $b=$tmp; } $answer = $a - $b; break;
            default:  $answer = $a * $b; break;
        }
        $op_display = ($op === 'x') ? '×' : $op;
        // token یک‌بارمصرف و زمان‌دار: شناسه تصادفی + امضای (پاسخ، شناسه، پنجره ۱۵ دقیقه‌ای) با کلید مخفی برنامه
        $secret  = function_exists('sec_secret') ? sec_secret() : (defined('AICHAT_DB_PASS') ? AICHAT_DB_PASS : 'aichat_cap_2024');
        $window  = (int)(time() / 900);
        $nonce   = bin2hex(random_bytes(8));
        $token   = $nonce . '.' . hash_hmac('sha256', $answer . ':' . $nonce . ':' . $window, $secret);
        return ['question' => "{$a} {$op_display} {$b}", 'token' => $token];
    }
}

if (!function_exists('saas_verify_captcha')) {
    /**
     * تأیید CAPTCHA با token هش‌شده (بدون session)
     * $user_input — عدد وارد‌شده توسط کاربر
     * $token      — مقدار hidden field captcha_token
     */
    function saas_verify_captcha(string $user_input, string $token = ''): bool
    {
        static $ok_tokens = [];   // در یک درخواست، همان token دوباره بررسی شود (نه دوباره مصرف)
        if ($token === '' || strlen($token) > 120) return false;
        $user_answer = (int)saas_fa2en(trim($user_input));
        if (trim($user_input) === '') return false;
        if (isset($ok_tokens[$token . '|' . $user_answer])) return true;
        $window_now  = (int)(time() / 900);
        if (defined('SEC_LEGACY_CAPTCHA_TEST')) {   // فقط محیط آزمایش خودکار
            for ($offset = 0; $offset <= 1; $offset++) if (hash_equals(hash_hmac('sha256', $user_answer . ':' . ($window_now - $offset), 'x'), $token)) return true;
        }
        if (!preg_match('/^([a-f0-9]{16})\.([a-f0-9]{64})$/', $token, $m)) return false;
        // یک‌بارمصرف: هر کپچا فقط یک بار (درست یا غلط) بررسی می‌شود؛ حدس زدن پیاپی پاسخ ممکن نیست
        static $tried = [];
        if (isset($tried[$m[1]])) return false;   // همین کپچا در همین درخواست قبلاً با پاسخ دیگری امتحان شده
        $tried[$m[1]] = 1;
        if (function_exists('sec_once') && !sec_once('captcha', $m[1], 2000)) return false;
        $secret = function_exists('sec_secret') ? sec_secret() : (defined('AICHAT_DB_PASS') ? AICHAT_DB_PASS : 'aichat_cap_2024');
        // پنجره جاری و پنجره قبلی (در مرز تغییر پنجره)
        for ($offset = 0; $offset <= 1; $offset++) {
            $expected = hash_hmac('sha256', $user_answer . ':' . $m[1] . ':' . ($window_now - $offset), $secret);
            if (hash_equals($expected, $m[2])) { $ok_tokens[$token . '|' . $user_answer] = 1; return true; }
        }
        return false;
    }
}

if (!function_exists('saas_fa2en')) {
    function saas_fa2en($s): string {
        return str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
                           ['0','1','2','3','4','5','6','7','8','9'], (string)$s);
    }
}

// --- بازیابی رمز: ایمیل -------------------------------------------------------

if (!function_exists('saas_request_reset_by_email')) {
    /**
     * ارسال لینک بازیابی رمز به ایمیل کاربر
     */
    function saas_request_reset_by_email($pdo, string $email): array
    {
        $email = strtolower(trim($email));
        $generic = ['ok' => true, 'msg' => 'اگر این ایمیل در سامانه ثبت شده باشد، لینک بازیابی رمز به آن ارسال شد. پوشه Spam را هم بررسی کنید.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'آدرس ایمیل معتبر نیست.'];
        $stmt  = $pdo->prepare("SELECT id, full_name FROM saas_users WHERE email=? AND status='active'");
        $stmt->execute([$email]);
        $user  = $stmt->fetch();
        if (!$user) return $generic;   // جلوگیری از شناسایی ایمیل‌های ثبت‌شده
        // جلوگیری از ارسال پشت‌سرهم
        $last = $pdo->prepare("SELECT reset_expires FROM saas_users WHERE id=?");
        $last->execute([$user['id']]);
        $le = (string)$last->fetchColumn();
        if ($le !== '' && strtotime($le) - 3600 > time() - 60) return $generic;
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE saas_users SET reset_token=?, reset_expires=? WHERE id=?")
            ->execute([$token, date('Y-m-d H:i:s', time() + 3600), $user['id']]);

        $base_url = defined('AICHAT_BASE_URL') ? rtrim(AICHAT_BASE_URL, '/') : '';
        $link     = $base_url . '/user/reset_password.php?token=' . $token;
        $name     = trim((string)$user['full_name']) ?: 'کاربر';
        if (function_exists('comm_mail_send')) {
            $mc = comm_mail_cfg($pdo);
            $subject = 'بازیابی رمز عبور — ' . $mc['from_name'];
            $text = "{$name} عزیز،\n\nبرای ساختن رمز عبور جدید روی لینک زیر بزنید:\n{$link}\n\nاین لینک تا ۱ ساعت معتبر است.\nاگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.";
            $html = comm_mail_html('بازیابی رمز عبور', "{$name} عزیز،\nبرای ساختن رمز عبور جدید روی دکمه زیر بزنید. این لینک تا ۱ ساعت معتبر است.\nاگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.", ['url' => $link, 'label' => 'ساختن رمز جدید']);
            $r = comm_mail_send($pdo, $email, $subject, $text, $html, $mc);
            if (!$r['ok']) error_log('[RESET MAIL] ' . $email . ': ' . $r['error']);
        }
        return $generic;
    }
}

if (!function_exists('saas_verify_reset_token')) {
    /**
     * تأیید توکن بازیابی ایمیل
     */
    function saas_verify_reset_token($pdo, string $token): array
    {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM saas_users WHERE reset_token=? AND reset_expires > NOW() AND status='active'");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if (!$user) return ['ok' => false, 'error' => 'لینک بازیابی نامعتبر یا منقضی شده است.'];
        return ['ok' => true, 'user_id' => (int)$user['id'], 'name' => $user['full_name']];
    }
}
// --- بازیابی رمز: ارسال پیامک OTP (اعتبارسنجی کاوه‌نگار) ---------------------------------------------------------

if (!function_exists('saas_request_reset_by_sms')) {
    /**
     * ارسال کد OTP به شماره موبایل کاربر
     */
    function saas_request_reset_by_sms($pdo, string $phone): array
    {
        $phone = function_exists('biz_mobile') ? biz_mobile($phone) : trim($phone);
        if (!preg_match('/^09\d{9}$/', $phone)) {
            return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.'];
        }
        $stmt = $pdo->prepare("SELECT id FROM saas_users WHERE phone=? AND status='active' LIMIT 1");
        $stmt->execute([$phone]);
        if (!$stmt->fetch()) {
            return ['ok' => false, 'error' => 'حسابی با این شماره موبایل پیدا نشد.'];
        }
        $err = comm_otp_send($pdo, 'user', $phone, 'reset', 'otp_user_reset');
        if ($err !== '') return ['ok' => false, 'error' => $err];
        return ['ok' => true, 'msg' => 'کد تأیید به شماره موبایل شما ارسال شد.'];
    }
}

if (!function_exists('_saas_send_sms_lookup')) {
    /**
     * ارسال پیامک اعتبارسنجی (Lookup/OTP) از طریق کاوه‌نگار
     */
    function _saas_send_sms_lookup(array $cfg, string $phone, string $token, string $template = 'login'): bool
    {
        $provider = $cfg['sms_provider'] ?? 'kavenegar';
        $api_key  = trim($cfg['sms_api_key'] ?? '');
        if (!$api_key) return false;

        if ($provider === 'kavenegar') {
            $url = "https://api.kavenegar.com/v1/{$api_key}/verify/lookup.json";
            $data = http_build_query([
                'receptor' => $phone,
                'template' => $template,
                'token'    => $token
            ]);
        } else {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[SMS Lookup cURL Error]: " . $err);
            return false;
        }

        $res = json_decode($resp, true);
        if ($code === 200 && isset($res['return']['status']) && (int)$res['return']['status'] === 200) {
            return true;
        }

        error_log("[SMS Lookup Error] HTTP {$code}: " . $resp);
        return false;
    }
}

if (!function_exists('_saas_send_sms')) {
    /**
     * تابع ارسال پیامک متنی معمولی (جهت سازگاری با سایر بخش‌های سیستم)
     */
    function _saas_send_sms(array $cfg, string $phone, string $message): bool
    {
        $provider = $cfg['sms_provider'] ?? 'kavenegar';
        $api_key  = trim($cfg['sms_api_key'] ?? '');
        $sender   = trim($cfg['sms_sender'] ?? '');
        if (!$api_key) return false;

        if ($provider === 'kavenegar') {
            $url = "https://api.kavenegar.com/v1/{$api_key}/sms/send.json";
            $data = http_build_query([
                'receptor' => $phone,
                'sender'   => $sender,
                'message'  => $message
            ]);
        } else {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code === 200);
    }
}


if (!function_exists('_saas_send_email')) {
    /**
     * ارسال ایمیل از طریق SMTP (با STARTTLS/SSL) یا mail() محلی
     * اگر smtp_host تنظیم شده باشد از SMTP استفاده می‌کند؛ وگرنه mail() سرور
     */
    function _saas_send_email(array $cfg, string $to, string $subject, string $body): bool
    {
        if (!function_exists('comm_mail_send') || !function_exists('aichat_connect')) return false;
        $r = comm_mail_send(aichat_connect(), $to, $subject, $body);
        return $r['ok'];
    }
}

if (!function_exists('saas_verify_otp')) {
    /**
     * تأیید کد OTP وارد‌شده
     */
    function saas_verify_otp($pdo, string $phone, string $code): array
    {
        $err = comm_otp_check($pdo, 'user', $phone, 'reset', $code);
        if ($err !== '') return ['ok' => false, 'error' => $err];
        $stmt = $pdo->prepare("SELECT id FROM saas_users WHERE phone=? AND status='active' ORDER BY id ASC");
        $stmt->execute([biz_mobile($phone)]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$ids) return ['ok' => false, 'error' => 'حسابی با این شماره موبایل پیدا نشد.'];
        return ['ok' => true, 'user_id' => $ids[0], 'user_ids' => $ids];
    }
}

// --- بازیابی رمز: سوال محرمانه -----------------------------------------------

if (!function_exists('saas_get_secret_question')) {
    /**
     * دریافت سوال محرمانه کاربر با ایمیل
     */
    function saas_get_secret_question($pdo, string $email): array
    {
        $email = strtolower(trim($email));
        $stmt  = $pdo->prepare("SELECT id, secret_question FROM saas_users WHERE email=? AND status='active'");
        $stmt->execute([$email]);
        $user  = $stmt->fetch();
        if (!$user || empty($user['secret_question'])) {
            return ['ok' => false, 'error' => 'برای این حساب سوال محرمانه تنظیم نشده است.'];
        }
        return ['ok' => true, 'user_id' => (int)$user['id'], 'question' => $user['secret_question']];
    }
}

if (!function_exists('saas_verify_secret_answer')) {
    /**
     * تأیید پاسخ سوال محرمانه
     */
    function saas_verify_secret_answer($pdo, int $user_id, string $answer): array
    {
        if (function_exists('ipg_blocked_msg') && ($ipb = ipg_blocked_msg($pdo)) !== '') return ['ok' => false, 'error' => $ipb];
        $stmt = $pdo->prepare("SELECT secret_answer_hash FROM saas_users WHERE id=? AND status='active'");
        $stmt->execute([$user_id]);
        $row  = $stmt->fetch();
        if (!$row || !$row['secret_answer_hash']) {
            return ['ok' => false, 'error' => 'سوال محرمانه تنظیم نشده است.'];
        }
        if (!password_verify(mb_strtolower(trim($answer)), $row['secret_answer_hash'])) {
            $ipl = function_exists('ipg_fail') ? ipg_fail($pdo, 'reset', 'سوال محرمانه #' . $user_id) : '';
            return ['ok' => false, 'error' => $ipl !== '' ? $ipl : 'پاسخ اشتباه است.'];
        }
        if (function_exists('ipg_success')) ipg_success($pdo);
        return ['ok' => true];
    }
}

if (!function_exists('saas_update_secret_question')) {
    /**
     * ذخیره/به‌روزرسانی سوال محرمانه کاربر
     */
    function saas_update_secret_question($pdo, int $user_id, string $question, string $answer): void
    {
        $hash = password_hash(mb_strtolower(trim($answer)), PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE saas_users SET secret_question=?, secret_answer_hash=? WHERE id=?")
            ->execute([trim($question), $hash, $user_id]);
    }
}

// --- تغییر رمز ---------------------------------------------------------------

if (!function_exists('saas_reset_password')) {
    /**
     * تغییر رمز عبور (بعد از تأیید هویت)
     * توسط ادمین هم قابل استفاده است
     */
    function saas_reset_password($pdo, int $user_id, string $new_password): array
    {
        $err = saas_validate_password_strength($new_password);
        if ($err) return ['ok' => false, 'error' => $err];
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE saas_users SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
            ->execute([$hash, $user_id]);
        return ['ok' => true];
    }
}


if (!function_exists('saas_get_user')) {
    function saas_get_user($pdo, $user_id)
    {
        $stmt = $pdo->prepare("SELECT u.*, p.name as plan_name, p.credit_tokens as plan_credit_tokens, p.max_requests_per_day, p.max_tokens_per_request, p.response_length as plan_response_length, p.has_knowledge_base, p.max_knowledge_items, p.has_products, p.max_products, p.has_color_customize, p.has_product_api
            FROM saas_users u
            LEFT JOIN saas_plans p ON p.id = u.plan_id
            WHERE u.id=?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
}

if (!function_exists('saas_get_user_by_site_key')) {
    function saas_get_user_by_site_key($pdo, $site_key)
    {
        $stmt = $pdo->prepare("SELECT u.*, p.max_requests_per_day, p.max_tokens_per_request, p.response_length as plan_response_length, p.has_knowledge_base, p.has_products, p.has_product_api
            FROM saas_users u
            LEFT JOIN saas_plans p ON p.id = u.plan_id
            WHERE u.site_key=? AND u.status='active'");
        $stmt->execute([$site_key]);
        return $stmt->fetch();
    }
}

if (!function_exists('saas_get_widget_settings')) {
    function saas_get_widget_settings($pdo, $user_id)
    {
        $stmt = $pdo->prepare("SELECT * FROM saas_widget_settings WHERE user_id=?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if (!$row) {
            // ایجاد پیش‌فرض
            $pdo->prepare("INSERT INTO saas_widget_settings (user_id, updated_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE updated_at=NOW()")->execute([$user_id]);
            $stmt->execute([$user_id]);
            $row = $stmt->fetch();
        }
        $row = $row ?: [];
        return [
            'store_info'           => $row['store_info'] ?? '',
            'guide_text'           => $row['guide_text'] ?? '',
            'guide_filename'       => $row['guide_filename'] ?? '',
            'response_template'    => $row['response_template'] ?? '',
            'primary_color'        => !empty($row['primary_color']) ? $row['primary_color'] : '#7c3aed',
            'secondary_color'      => !empty($row['secondary_color']) ? $row['secondary_color'] : '#ede9fe',
            'text_color'           => !empty($row['text_color']) ? $row['text_color'] : '#ffffff',
            'widget_title'         => !empty($row['widget_title']) ? $row['widget_title'] : 'دستیار هوشمند',
            'welcome_message'      => !empty($row['welcome_message']) ? $row['welcome_message'] : 'سلام! چطور می‌تونم کمکتون کنم؟',
            'contact_phone'        => $row['contact_phone'] ?? '',
            'position'             => !empty($row['position']) ? $row['position'] : 'right',
            'show_lead_form'       => !empty($row['show_lead_form']),
            'max_questions_session'=> isset($row['max_questions_session']) ? (int)$row['max_questions_session'] : 0,
            'response_length'      => !empty($row['response_length']) ? $row['response_length'] : 'medium',
            'product_api_url'      => $row['product_api_url'] ?? '',
            'product_api_key'      => $row['product_api_key'] ?? '',
            'product_api_mode'     => $row['product_api_mode'] ?? '',
            'cache_enabled'        => isset($row['cache_enabled']) ? (bool)$row['cache_enabled'] : true,
            'cache_days'           => isset($row['cache_days']) ? max(1, (int)$row['cache_days']) : 7,
            'launcher_icon'        => !empty($row['launcher_icon']) ? $row['launcher_icon'] : 'chat',
            'launcher_icon_url'    => $row['launcher_icon_url'] ?? '',
            'send_icon'            => !empty($row['send_icon']) ? $row['send_icon'] : 'plane',
            'send_icon_url'        => $row['send_icon_url'] ?? '',
            'auto_peek'            => isset($row['auto_peek']) ? (bool)$row['auto_peek'] : true,
            'site_posts_url'       => $row['site_posts_url'] ?? '',
            'site_posts_extra'     => $row['site_posts_extra'] ?? '',
            'site_pages_url'       => $row['site_pages_url'] ?? '',
            'site_pages_extra'     => $row['site_pages_extra'] ?? '',
            'phone_color'          => (!empty($row['phone_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $row['phone_color'])) ? $row['phone_color'] : '#10b981',
            'enabled'              => isset($row['enabled']) ? (bool)$row['enabled'] : true,
            'ai_model_id'          => (int)($row['ai_model_id'] ?? 0),
            'realism'              => isset($row['realism']) ? (int)$row['realism'] : -1,     // −۱ = پیش‌فرض سامانه
            'limit_in'             => (int)($row['limit_in'] ?? 0),     // سقف توکن ورودی هر پیام (۰ = سقف پلن)
            'limit_out'            => (int)($row['limit_out'] ?? 0),    // سقف توکن خروجی هر پیام
            'grounding'            => isset($row['grounding']) ? (int)$row['grounding'] : -1,
            'contact_on'           => isset($row['contact_on']) ? (bool)$row['contact_on'] : true,   // «تماس با کارشناس» در ویجت
            'contact_text'         => (string)($row['contact_text'] ?? ''),
        ];
    }
}

// =============================================================================
// مدیریت اعتبار (توکن)
// =============================================================================
if (!function_exists('saas_add_credit')) {
    function saas_add_credit($pdo, $user_id, $tokens, $description = '', $ref_id = null)
    {
        $pdo->prepare("UPDATE saas_users SET credit_tokens = credit_tokens + ?, sms_notified_low = 0 WHERE id=?")->execute([$tokens, $user_id]);
        $pdo->prepare("INSERT INTO saas_credit_log (user_id, tokens_delta, description, ref_id, created_at) VALUES(?,?,?,?,NOW())")->execute([$user_id, $tokens, $description, $ref_id]);
    }
}

if (!function_exists('saas_deduct_credit')) {
    function saas_deduct_credit($pdo, $user_id, $tokens, $description = '')
    {
        if ($tokens <= 0) return true;
        $pdo->prepare("UPDATE saas_users SET credit_tokens = GREATEST(0, credit_tokens - ?) WHERE id=?")->execute([$tokens, $user_id]);
        $pdo->prepare("INSERT INTO saas_credit_log (user_id, tokens_delta, description, created_at) VALUES(?,?,?,NOW())")->execute([$user_id, -$tokens, $description]);
        // بررسی اعتبار کم
        saas_check_low_credit($pdo, $user_id);
        return true;
    }
}

if (!function_exists('saas_check_low_credit')) {
    function saas_check_low_credit($pdo, $user_id)
    {
        $user = $pdo->prepare("SELECT u.credit_tokens, u.sms_notified_low, u.phone, u.full_name, p.credit_tokens as plan_credit
            FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id WHERE u.id=?");
        $user->execute([$user_id]);
        $u = $user->fetch();
        if (!$u || $u['sms_notified_low']) return;

        $cfg = saas_get_api_config($pdo);
        $plan_credit = max((int)($u['plan_credit'] ?? 0), 10000);
        $threshold = $plan_credit * ($cfg['sms_low_credit_percent'] / 100);

        if ($u['credit_tokens'] <= $threshold) {
            if ($cfg['sms_enabled'] && $cfg['sms_api_key'] && $u['phone'] && function_exists('comm_sms_send')) {
                comm_sms_send($pdo, $u['phone'], 'low_credit', ['name' => trim((string)$u['full_name']) ?: 'کاربر', 'link' => rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/user/billing.php'], ['user_id' => (int)$user_id, 'kind' => 'low_credit']);
            }
            $pdo->prepare("UPDATE saas_users SET sms_notified_low=1 WHERE id=?")->execute([$user_id]);
        }
    }
}

if (!function_exists('saas_send_sms')) {
    function saas_send_sms($cfg, $phone, $message)
    {
        if (($cfg['sms_provider'] ?? 'kavenegar') !== 'kavenegar' || !function_exists('comm_kavenegar')) return false;
        $r = comm_kavenegar($cfg['sms_api_key'] ?? '', 'sms/send', ['receptor' => $phone, 'message' => $message, 'sender' => $cfg['sms_sender'] ?? '']);
        if (!$r['ok']) error_log('[SMS] ' . $phone . ': ' . $r['error']);
        return $r['ok'];
    }
}

// =============================================================================
// مکالمات چندکاربره
// =============================================================================
if (!function_exists('saas_resolve_conversation')) {
    function saas_resolve_conversation($pdo, $user_id, $ip, $create = false)
    {
        // اول توکن از پارامتر درخواست چک می‌کنیم (برای ویجت‌های cross-origin که کوکی ندارند)
        $req_token = trim((string)($_REQUEST['_chat_token'] ?? ''));
        if ($req_token !== '' && preg_match('/^[a-f0-9]{40,50}$/', $req_token)) {
            $token = $req_token;
        } else {
            // fallback به کوکی (برای استفاده هم‌دامنه)
            $token = isset($_COOKIE['aichat_token_' . $user_id]) ? trim((string)$_COOKIE['aichat_token_' . $user_id]) : '';
        }

        if ($token !== '' && preg_match('/^[a-f0-9]{40,50}$/', $token)) {
            $stmt = $pdo->prepare("SELECT * FROM saas_conversations WHERE token=? AND user_id=? AND created_at >= (NOW() - INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1");
            $stmt->execute([$token, $user_id]);
            $row = $stmt->fetch();
            if ($row) { $row['is_new'] = false; return $row; }
        }

        if (!$create) return null;

        $new_token = bin2hex(random_bytes(24));
        $stmt = $pdo->prepare("INSERT INTO saas_conversations (user_id, token, ip, created_at) VALUES(?,?,?,NOW())");
        $stmt->execute([$user_id, $new_token, $ip]);
        $id = (int)$pdo->lastInsertId();

        setcookie('aichat_token_' . $user_id, $new_token, [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return ['id' => $id, 'token' => $new_token, 'visitor_name' => null, 'visitor_phone' => null, 'is_new' => true];
    }
}

if (!function_exists('saas_rate_limited')) {
    function saas_rate_limited($pdo, $user_id, $ip, $max = 30, $window_min = 10)
    {
        if (!$ip || $ip === 'unknown') return false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM saas_messages m JOIN saas_conversations c ON c.id=m.conversation_id WHERE m.user_id=? AND c.ip=? AND m.role='user' AND m.created_at >= (NOW() - INTERVAL ? MINUTE)");
        $stmt->execute([$user_id, $ip, $window_min]);
        return (int)$stmt->fetchColumn() >= $max;
    }
}

if (!function_exists('saas_check_daily_limit')) {
    function saas_check_daily_limit($pdo, $user_id, $max_per_day)
    {
        if ($max_per_day <= 0) return false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM saas_messages WHERE user_id=? AND role='user' AND DATE(created_at)=CURDATE()");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn() >= $max_per_day;
    }
}

// =============================================================================
// توابع کمکی
// =============================================================================
if (!function_exists('saas_user_stats')) {
    function saas_user_stats($pdo, $user_id)
    {
        $convs = (int)$pdo->prepare("SELECT COUNT(*) FROM saas_conversations WHERE user_id=?")->execute([$user_id]) ? $pdo->prepare("SELECT COUNT(*) FROM saas_conversations WHERE user_id=?")->execute([$user_id]) : 0;

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT conversation_id) as convs, COUNT(*) as msgs, COALESCE(SUM(tokens_used),0) as tokens FROM saas_messages WHERE user_id=?");
        $stmt->execute([$user_id]);
        $stats = $stmt->fetch();

        $today_msgs = $pdo->prepare("SELECT COUNT(*) FROM saas_messages WHERE user_id=? AND role='user' AND DATE(created_at)=CURDATE()");
        $today_msgs->execute([$user_id]);
        $stats['today_requests'] = (int)$today_msgs->fetchColumn();

        $knowledge_count = $pdo->prepare("SELECT COUNT(*) FROM saas_knowledge WHERE user_id=?");
        $knowledge_count->execute([$user_id]);
        $stats['knowledge_items'] = (int)$knowledge_count->fetchColumn();

        return $stats;
    }
}

if (!function_exists('saas_credit_log')) {
    function saas_credit_log($pdo, $user_id, $limit = 20)
    {
        $limit = max(1, (int)$limit);
        $stmt = $pdo->prepare("SELECT * FROM saas_credit_log WHERE user_id=? ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('saas_payments_log')) {
    function saas_payments_log($pdo, $user_id, $limit = 20)
    {
        $stmt = $pdo->prepare("SELECT p.*, pl.name as plan_name FROM saas_payments p LEFT JOIN saas_plans pl ON pl.id=p.plan_id WHERE p.user_id=? ORDER BY p.id DESC LIMIT " . max(1, (int)$limit));
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('saas_fa_normalize')) {
    /**
     * یکسان‌سازی متن فارسی برای جستجو:
     * ی/ک عربی، اعداد فارسی/عربی، نیم‌فاصله، حروف کوچک
     */
    function saas_fa_normalize($s)
    {
        $s = (string)$s;
        $s = str_replace(
            ['ي', 'ك', 'ة', 'أ', 'إ', 'آ', 'ؤ', 'ـ', "\u{200C}", "\u{200F}", "\u{200E}"],
            ['ی', 'ک', 'ه', 'ا', 'ا', 'ا', 'و', '',  ' ',       '',         ''],
            $s
        );
        $s = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $s
        );
        return mb_strtolower($s, 'UTF-8');
    }
}

if (!function_exists('saas_search_terms')) {
    /**
     * استخراج کلمات معنادار از پیام کاربر (حذف کلمات پرتکرار بی‌معنا)
     */
    function saas_search_terms($query)
    {
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip([
                'از','به','با','در','که','را','رو','این','اون','آن','ان','برای','واسه','و','یا','هم','تا','چه','چی','چیه',
                'چطور','چطوری','چگونه','چجوری','کجا','کی','کدام','کدوم','چند','چقدر','هست','هستش','است','هستید','هستین',
                'می','میشه','میخوام','میخواستم','خواستم','من','شما','ما','تو','آیا','ایا','یک','یه','ها','های','کنم',
                'کنید','کنین','کن','دارید','دارین','دارم','داره','دارد','لطفا','لطفاً','سلام','ممنون','مرسی','خیلی',
                'بود','باشه','باشد','شده','شد','بعد','قبل','روی','توی','اگه','اگر','ولی','اما','پس','بله','نه','خب',
                'خوب','درباره','مورد','راجع','راجب','بگید','بگین','بگو','بدید','بدین','نیست','ندارید','چیست','کنه',
            ]);
        }
        $q = saas_fa_normalize($query);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $terms = [];
        foreach ($parts as $w) {
            if (mb_strlen($w) < 2 || isset($stop[$w])) continue;
            $terms[$w] = true;
            // ریشه ساده: حذف پسوندهای رایج (ها، های، ی، ای، ان، ات)
            if (mb_strlen($w) > 4) {
                $stem = preg_replace('/(های|ها|ات|ان|ای|ی)$/u', '', $w);
                if (mb_strlen($stem) >= 3) $terms[$stem] = true;
            }
        }
        $terms = array_slice(array_keys($terms), 0, 15);
        // هم‌معنی‌های رایج (برای پیدا کردن موضوع با کلمات متفاوت)
        $extra = [];
        foreach (saas_synonym_groups() as $g) {
            foreach ($terms as $t) {
                if (in_array($t, $g, true)) { foreach ($g as $w) if (!in_array($w, $terms, true)) $extra[$w] = true; break; }
            }
        }
        return array_merge($terms, array_slice(array_keys($extra), 0, 12));
    }
}

if (!function_exists('saas_synonym_groups')) {
    /** گروه‌های واژه‌های هم‌معنی پرکاربرد در پرسش مشتریان (نرمال‌شده) */
    function saas_synonym_groups()
    {
        static $g = null;
        if ($g !== null) return $g;
        $raw = [
            'قیمت هزینه تعرفه مبلغ نرخ بها',
            'ارسال حمل پست تحویل پیک باربری',
            'آدرس نشانی لوکیشن مکان محل',
            'تماس تلفن شماره موبایل',
            'ساعت ساعات زمان وقت',
            'گارانتی ضمانت وارانتی',
            'تخفیف آفر حراج',
            'پرداخت قسط اقساط قسطی',
            'مرجوع مرجوعی برگشت بازگشت عودت',
            'عضویت اشتراک',
            'مجوز پروانه جواز',
            'دوره کلاس آموزش کارگاه',
            'مشاوره راهنمایی',
            'سفارش خرید',
            'موجود موجودی',
        ];
        $g = [];
        foreach ($raw as $line) $g[] = array_values(array_filter(array_map('trim', explode(' ', saas_fa_normalize($line)))));
        return $g;
    }
}

if (!function_exists('saas_text_score')) {
    /** امتیاز تطبیق کلمات جستجو با یک متن نرمال‌شده */
    function saas_text_score($terms, $text, $weight)
    {
        if ($text === '' || !$terms) return 0;
        $score = 0;
        foreach ($terms as $t) {
            $c = mb_substr_count($text, $t);
            if ($c > 0) $score += $weight * min($c, 3);
        }
        return $score;
    }
}

if (!function_exists('saas_owner_user_id')) {
    /** شناسه اولین کاربر SaaS (مالک سایت) — موضوعات پنل ادمین (topics.php) به او تعلق دارد */
    function saas_owner_user_id($pdo)
    {
        static $id = null;
        if ($id === null) {
            $r = $pdo->query("SELECT MIN(id) FROM saas_users");
            $id = $r ? (int)$r->fetchColumn() : 0;
        }
        return $id;
    }
}

// =============================================================================
// جستجو در پایگاه دانش و محصولات
// نکته مهم: در این دیتابیس prepared-statement شبیه‌سازی می‌شود و «LIMIT ?»
// به شکل LIMIT '5' ارسال شده و خطا می‌دهد؛ بنابراین LIMIT همیشه عدد صحیح درج می‌شود.
// =============================================================================
if (!function_exists('saas_search_knowledge')) {
    /**
     * جستجوی پایگاه دانش کاربر
     * - همه موضوعات کاربر (saas_knowledge) + موضوعات پنل ادمین (aichat_topics) برای مالک سایت
     * - امتیازدهی بر اساس عنوان، کلیدواژه‌ها و محتوا
     * - اگر حجم کل پایگاه دانش کم باشد، همه آن به هوش مصنوعی داده می‌شود
     *
     * @return array [ ['title'=>..., 'keywords'=>..., 'text'=>...], ... ]
     */
    function saas_search_knowledge($pdo, $user_id, $query = '', $limit = 6, $max_chars = 2000, $budget = 10000)
    {
        $user_id = (int)$user_id;
        $rows = [];

        $st = $pdo->prepare("SELECT * FROM saas_knowledge WHERE user_id=? ORDER BY id DESC LIMIT 500");
        if ($st && $st->execute([$user_id])) {
            // موضوعات غیرفعال در پاسخ‌ها استفاده نمی‌شوند
            $rows = array_values(array_filter($st->fetchAll(\PDO::FETCH_ASSOC) ?: [], fn($r) => !isset($r['is_active']) || (int)$r['is_active'] === 1));
        }

        // موضوعات پنل ادمین قدیمی (topics.php) برای مالک سایت
        try {
            if ($user_id === saas_owner_user_id($pdo)) {
                $chk = $pdo->query("SHOW TABLES LIKE 'aichat_topics'");
                if ($chk && $chk->rowCount() > 0) {
                    $old = $pdo->query("SELECT title, content FROM aichat_topics ORDER BY id DESC LIMIT 500");
                    if ($old) {
                        foreach ($old->fetchAll(\PDO::FETCH_ASSOC) as $o) {
                            $rows[] = ['title' => $o['title'], 'keywords' => '', 'content' => $o['content']];
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* جدول قدیمی وجود ندارد */ }

        if (!$rows) return [];

        // حذف موارد تکراری و آماده‌سازی
        $items = [];
        $seen  = [];
        foreach ($rows as $r) {
            $text = trim(strip_tags((string)($r['content'] ?? '')));
            $text = preg_replace('/[ \t]+/u', ' ', $text);
            $text = preg_replace('/\n{3,}/u', "\n\n", $text);
            $title = trim((string)($r['title'] ?? ''));
            if ($text === '' && $title === '') continue;
            $h = md5(saas_fa_normalize($title . '|' . $text));
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            $items[] = [
                'title'    => $title,
                'keywords' => (string)($r['keywords'] ?? ''),
                'text'     => $text,
                'score'    => 0,
            ];
        }
        if (!$items) return [];

        // امتیازدهی
        $terms = saas_search_terms($query);
        foreach ($items as &$it) {
            $it['score'] =
                saas_text_score($terms, saas_fa_normalize($it['title']), 6) +
                saas_text_score($terms, saas_fa_normalize($it['keywords']), 5) +
                saas_text_score($terms, saas_fa_normalize($it['text']), 1);
        }
        unset($it);

        // مرتب‌سازی پایدار بر اساس امتیاز
        $idx = 0;
        foreach ($items as &$it) { $it['_i'] = $idx++; }
        unset($it);
        usort($items, function ($a, $b) {
            if ($a['score'] === $b['score']) return $a['_i'] <=> $b['_i'];
            return $b['score'] <=> $a['score'];
        });

        $total_chars = 0;
        foreach ($items as $it) $total_chars += mb_strlen($it['title']) + mb_strlen($it['text']);

        if ($total_chars <= $budget) {
            // پایگاه دانش کوچک است → همه آن ارسال می‌شود (مرتب‌شده بر اساس ارتباط)
            $selected = $items;
        } else {
            $matched = array_values(array_filter($items, fn($x) => $x['score'] > 0));
            $selected = $matched ? $matched : array_slice($items, 0, 3);
        }

        $out  = [];
        $used = 0;
        foreach ($selected as $it) {
            if (count($out) >= max($limit, 1) && $total_chars > $budget) break;
            // حداقل ۱۰۰۰ کاراکتر از مرتبط‌ترین بخش هر موضوع (تا سقف max_chars)
            $text = saas_best_snippet($it['text'], $terms, 1000, $max_chars);
            $len = mb_strlen($text) + mb_strlen($it['title']);
            if ($out && $used + $len > $budget) {
                // اگر جای کامل نیست، حداقل ۱۰۰۰ کاراکتر مرتبط از این موضوع هم اضافه شود (در صورت امتیاز داشتن)
                if ($it['score'] > 0 && $used + 1000 <= $budget + 3000) {
                    $text = saas_best_snippet($it['text'], $terms, 1000, 1000);
                    $used += mb_strlen($text);
                    $out[] = ['title' => $it['title'], 'keywords' => $it['keywords'], 'text' => $text, 'score' => $it['score']];
                    continue;
                }
                break;
            }
            $used += $len;
            $out[] = ['title' => $it['title'], 'keywords' => $it['keywords'], 'text' => $text, 'score' => $it['score']];
        }
        return $out;
    }
}

if (!function_exists('saas_knowledge_catalog')) {
    /** همه موضوعات فعال (عنوان، کلیدواژه، متن) برای انتخاب هوشمند موضوع */
    function saas_knowledge_catalog($pdo, $user_id)
    {
        $st = $pdo->prepare("SELECT * FROM saas_knowledge WHERE user_id=? ORDER BY id DESC LIMIT 300");
        $out = [];
        if ($st && $st->execute([(int)$user_id])) {
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                if (isset($r['is_active']) && (int)$r['is_active'] !== 1) continue;
                $out[] = ['title' => trim((string)$r['title']), 'keywords' => trim((string)$r['keywords']), 'text' => trim(strip_tags((string)$r['content']))];
            }
        }
        return $out;
    }
}

if (!function_exists('saas_knowledge_titles')) {
    /** فهرست عنوان همه موضوعات (تا هوش مصنوعی بداند چه موضوعاتی وجود دارد) */
    function saas_knowledge_titles($pdo, $user_id, $max = 60)
    {
        $st = $pdo->prepare("SELECT * FROM saas_knowledge WHERE user_id=? ORDER BY id DESC LIMIT " . (int)$max);
        $titles = [];
        if ($st && $st->execute([(int)$user_id])) {
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) if (!isset($r['is_active']) || (int)$r['is_active'] === 1) $titles[] = trim($r['title']);
        }
        return array_values(array_unique(array_filter($titles)));
    }
}

if (!function_exists('saas_search_products')) {
    /**
     * جستجوی محصولات ثبت‌شده کاربر با امتیازدهی کلمه‌به‌کلمه
     */
    function saas_search_products($pdo, $user_id, $query = '', $limit = 5)
    {
        $limit = max(1, (int)$limit);
        $st = $pdo->prepare("SELECT * FROM saas_products WHERE user_id=? ORDER BY id DESC LIMIT 1000");
        if (!$st || !$st->execute([(int)$user_id])) return [];
        $all = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$all) return [];

        $terms = saas_search_terms($query);
        $scored = [];
        foreach ($all as $i => $p) {
            $s = saas_text_score($terms, saas_fa_normalize($p['title'] ?? ''), 5)
               + saas_text_score($terms, saas_fa_normalize($p['description'] ?? ''), 1);
            if ($s > 0) { $p['_s'] = $s; $p['_i'] = $i; $scored[] = $p; }
        }
        if ($scored) {
            usort($scored, fn($a, $b) => $a['_s'] === $b['_s'] ? $a['_i'] <=> $b['_i'] : $b['_s'] <=> $a['_s']);
            return array_slice($scored, 0, $limit);
        }

        // سوال کلی درباره محصولات/قیمت → جدیدترین محصولات
        $nq = saas_fa_normalize($query);
        if (preg_match('/محصول|کالا|قیمت|خرید|فروش|موجود|لیست|فهرست|سفارش|تخفیف/u', $nq) || count($all) <= $limit) {
            return array_slice($all, 0, $limit);
        }
        return [];
    }
}

if (!function_exists('saas_best_snippet')) {
    /**
     * انتخاب مرتبط‌ترین بخش یک متن طولانی
     * - اگر متن کوتاه‌تر از max باشد، کامل برگردانده می‌شود.
     * - در غیر این صورت، پاراگراف/جمله‌ای که بیشترین تطبیق با سوال را دارد انتخاب
     *   و با جمله‌های قبل و بعد آن گسترش می‌یابد تا حداقل min و حداکثر max کاراکتر شود.
     */
    function saas_best_snippet($text, $terms, $min = 1000, $max = 2000)
    {
        $text = trim((string)$text);
        $max  = max($min, (int)$max);
        if (mb_strlen($text) <= $max) return $text;

        // تقسیم به تکه‌ها (پاراگراف، و اگر پاراگراف بلند بود، جمله)
        $chunks = [];
        foreach (preg_split('/\n+/u', $text) as $para) {
            $para = trim($para);
            if ($para === '') continue;
            if (mb_strlen($para) <= 400) { $chunks[] = $para; continue; }
            foreach (preg_split('/(?<=[.!؟?])\s+/u', $para) as $sent) {
                $sent = trim($sent);
                if ($sent === '') continue;
                while (mb_strlen($sent) > 400) {             // جمله خیلی بلند
                    $chunks[] = mb_substr($sent, 0, 400);
                    $sent = mb_substr($sent, 400);
                }
                if ($sent !== '') $chunks[] = $sent;
            }
        }
        if (!$chunks) return mb_substr($text, 0, $max) . '…';

        // تکه با بیشترین امتیاز
        $best = 0; $best_score = -1;
        foreach ($chunks as $i => $c) {
            $sc = saas_text_score($terms, saas_fa_normalize($c), 1);
            if ($sc > $best_score) { $best_score = $sc; $best = $i; }
        }
        if ($best_score <= 0) $best = 0;   // تطبیقی نبود → از ابتدای متن

        // گسترش به دو طرف
        $sel = [$best => true];
        $len = mb_strlen($chunks[$best]);
        $lo = $best - 1; $hi = $best + 1; $n = count($chunks);
        while ($len < $max && ($lo >= 0 || $hi < $n)) {
            if ($hi < $n && ($len + mb_strlen($chunks[$hi]) <= $max || $len < $min)) {
                $sel[$hi] = true; $len += mb_strlen($chunks[$hi]); $hi++;
            } elseif ($hi < $n) { $hi = $n; }
            if ($len >= $max) break;
            if ($lo >= 0 && ($len + mb_strlen($chunks[$lo]) <= $max || $len < $min)) {
                $sel[$lo] = true; $len += mb_strlen($chunks[$lo]); $lo--;
            } elseif ($lo >= 0) { $lo = -1; }
        }
        ksort($sel);
        $out = ''; $prev = null;
        foreach (array_keys($sel) as $i) {
            if ($prev !== null && $i !== $prev + 1) $out .= "\n…\n";
            elseif ($prev !== null) $out .= "\n";
            $out .= $chunks[$i];
            $prev = $i;
        }
        if (min(array_keys($sel)) > 0) $out = "…" . $out;
        if (max(array_keys($sel)) < $n - 1) $out .= "…";
        if (mb_strlen($out) > $max + 200) $out = mb_substr($out, 0, $max) . '…';
        return $out;
    }
}

// =============================================================================
// اتصال API محصولات سایت
// پشتیبانی خودکار از:
//   json      — API اختصاصی که JSON برمی‌گرداند:  {url}?search=...
//   wc_store  — ووکامرس (Store API عمومی):  /wp-json/wc/store/v1/products
//   wp_rest   — وردپرس (REST محصولات):      /wp-json/wp/v2/product
//   html      — صفحه جستجوی سایت:           /?s=...&post_type=product
// =============================================================================
if (!function_exists('saas_http_get')) {
    function saas_http_get($url, $api_key = '', $timeout = 8)
    {
        $headers = ['Accept: application/json, text/html;q=0.9, */*;q=0.5', 'Accept-Language: fa-IR,fa;q=0.9'];
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
            $headers[] = 'X-API-Key: ' . $api_key;
        }
        // امنیت (SSRF): فقط آدرس‌های عمومی اینترنت؛ هر تغییر مسیر (redirect) هم دوباره بررسی می‌شود
        for ($hop = 0; $hop <= 4; $hop++) {
            if (function_exists('sec_url_safe') && !sec_url_safe($url)) return ['code' => 0, 'body' => '', 'ctype' => '', 'url' => $url, 'error' => 'آدرس مجاز نیست (شبکه داخلی).'];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AIChatWidget/1.0)',
            ]);
            if (defined('CURLOPT_PROTOCOLS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            $body  = curl_exec($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $next  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err   = curl_error($ch);
            curl_close($ch);
            if ($code >= 300 && $code < 400 && $next !== '' && $hop < 4) { $url = $next; continue; }
            break;
        }
        return ['code' => $code, 'body' => $body === false ? '' : (string)$body, 'ctype' => $ctype, 'url' => $url, 'error' => $err];
    }
}

if (!function_exists('saas_site_root')) {
    function saas_site_root($url)
    {
        $p = parse_url(trim((string)$url));
        if (empty($p['host'])) return '';
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '');
    }
}

if (!function_exists('saas_clean_html_text')) {
    function saas_clean_html_text($html, $limit = 0)
    {
        $t = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h\d)[^>]*>/i', "\n", (string)$html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
        $t = trim(preg_replace('/\n\s*\n+/u', "\n", $t));
        if ($limit > 0 && mb_strlen($t) > $limit) $t = mb_substr($t, 0, $limit) . '…';
        return $t;
    }
}

if (!function_exists('saas_parse_products_json')) {
    /** تبدیل پاسخ JSON (هر فرمت رایج) به لیست یکسان محصولات */
    function saas_parse_products_json($data, $mode = 'json')
    {
        if (!is_array($data)) return [];
        $items = [];
        foreach (['data', 'products', 'items', 'results'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) { $items = $data[$k]; break; }
        }
        if (!$items && array_is_list($data)) $items = $data;
        if (isset($items['data']) && is_array($items['data'])) $items = $items['data'];

        $out = [];
        foreach (array_slice($items, 0, 6) as $item) {
            if (!is_array($item)) continue;

            // عنوان
            $title = $item['name'] ?? $item['title'] ?? '';
            if (is_array($title)) $title = $title['rendered'] ?? '';
            $title = saas_clean_html_text($title);
            if ($title === '') continue;

            // لینک
            $url = (string)($item['permalink'] ?? $item['url'] ?? $item['link'] ?? '');

            // تصویر
            $img = $item['image'] ?? $item['thumbnail'] ?? $item['image_url'] ?? '';
            if (is_array($img)) $img = $img['thumbnail'] ?? $img['src'] ?? $img['url'] ?? '';
            if (!$img && !empty($item['images'][0])) {
                $f = $item['images'][0];
                $img = is_array($f) ? ($f['thumbnail'] ?? $f['src'] ?? $f['url'] ?? '') : $f;
            }
            if (!$img && !empty($item['_embedded']['wp:featuredmedia'][0])) {
                $m = $item['_embedded']['wp:featuredmedia'][0];
                $img = $m['media_details']['sizes']['thumbnail']['source_url'] ?? ($m['source_url'] ?? '');
            }

            // قیمت
            $price_text = '';
            if (isset($item['prices']) && is_array($item['prices'])) {        // Store API ووکامرس
                $pr = $item['prices'];
                $minor = (int)($pr['currency_minor_unit'] ?? 0);
                $raw = $pr['sale_price'] ?? $pr['price'] ?? '';
                if ($raw !== '' && is_numeric($raw)) {
                    $val = (float)$raw / pow(10, $minor);
                    $cur = trim(($pr['currency_suffix'] ?? '') ?: ($pr['currency_prefix'] ?? ''));
                    if ($cur === '') {
                        $map = ['IRT' => 'تومان', 'IRR' => 'ریال', 'IRHT' => 'هزار تومان', 'IRHR' => 'هزار ریال'];
                        $cur = $map[$pr['currency_code'] ?? ''] ?? ($pr['currency_code'] ?? 'تومان');
                    }
                    $price_text = $val > 0 ? number_format($val) . ' ' . $cur : '';
                    if (!empty($pr['regular_price']) && !empty($pr['sale_price']) && $pr['sale_price'] !== $pr['regular_price'] && is_numeric($pr['regular_price'])) {
                        $price_text .= ' (قیمت قبل از تخفیف: ' . number_format((float)$pr['regular_price'] / pow(10, $minor)) . ' ' . $cur . ')';
                    }
                }
            } else {
                $raw = $item['sale_price'] ?? $item['price'] ?? $item['regular_price'] ?? '';
                if (is_array($raw)) $raw = $raw['amount'] ?? $raw['value'] ?? '';
                $raw = str_replace(',', '', (string)$raw);
                if ($raw !== '' && is_numeric($raw) && (float)$raw > 0) $price_text = number_format((float)$raw) . ' تومان';
                elseif (!empty($item['price_html'])) $price_text = saas_clean_html_text($item['price_html']);
            }

            // توضیحات (تا ۱۰۰۰ کاراکتر)
            $desc = '';
            foreach (['short_description', 'description', 'excerpt', 'content', 'summary'] as $dk) {
                if (empty($item[$dk])) continue;
                $d = is_array($item[$dk]) ? ($item[$dk]['rendered'] ?? '') : $item[$dk];
                $d = saas_clean_html_text($d);
                if (mb_strlen($d) > mb_strlen($desc)) $desc = $d;
                if (mb_strlen($desc) >= 1000) break;
            }

            // موجودی
            $stock = null;
            if (isset($item['is_in_stock']))       $stock = (bool)$item['is_in_stock'];
            elseif (isset($item['in_stock']))      $stock = (bool)$item['in_stock'];
            elseif (isset($item['stock_status']))  $stock = ($item['stock_status'] === 'instock');

            $out[] = [
                'title'       => $title,
                'price_text'  => $price_text,
                'url'         => $url,
                'image'       => (string)$img,
                'description' => $desc,
                'stock'       => $stock,
            ];
        }
        return $out;
    }
}

if (!function_exists('saas_parse_products_html')) {
    /** استخراج محصولات از صفحه HTML (فهرست ووکامرس یا صفحه یک محصول) */
    function saas_parse_products_html($html, $base_url)
    {
        if (!class_exists('DOMDocument') || trim((string)$html) === '') return [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $root = saas_site_root($base_url);
        $abs = function ($u) use ($root) {
            $u = trim((string)$u);
            if ($u === '' || preg_match('#^https?://#i', $u)) return $u;
            if (strpos($u, '//') === 0) return 'https:' . $u;
            return rtrim($root, '/') . '/' . ltrim($u, '/');
        };
        $meta = function ($prop) use ($xp) {
            $n = $xp->query("//meta[@property='{$prop}' or @name='{$prop}']/@content");
            return $n && $n->length ? trim($n->item(0)->nodeValue) : '';
        };

        $out = [];
        $nodes = $xp->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' product ')] | //div[contains(concat(' ', normalize-space(@class), ' '), ' product-small ')]");
        if ($nodes) {
            foreach ($nodes as $node) {
                if (count($out) >= 6) break;
                $a = $xp->query(".//a[contains(@class,'LoopProduct-link')] | .//a[contains(@class,'woocommerce-loop-product__link')] | .//a[@href]", $node);
                $href = $a && $a->length ? $a->item(0)->getAttribute('href') : '';
                $t = $xp->query(".//*[contains(@class,'woocommerce-loop-product__title')] | .//*[contains(@class,'product-title')] | .//h2 | .//h3", $node);
                $title = $t && $t->length ? trim($t->item(0)->textContent) : '';
                if ($title === '' || $href === '') continue;
                $p = $xp->query(".//*[contains(@class,'price')]", $node);
                $price = $p && $p->length ? trim(preg_replace('/\s+/u', ' ', $p->item(0)->textContent)) : '';
                $i = $xp->query(".//img", $node);
                $img = '';
                if ($i && $i->length) {
                    $im = $i->item(0);
                    foreach (['data-src', 'data-lazy-src', 'src'] as $att) {
                        $v = $im->getAttribute($att);
                        if ($v && strpos($v, 'data:') !== 0) { $img = $v; break; }
                    }
                }
                $out[] = ['title' => $title, 'price_text' => $price, 'url' => $abs($href), 'image' => $abs($img), 'description' => '', 'stock' => null];
            }
        }

        // صفحه یک محصول (ووکامرس وقتی فقط یک نتیجه باشد مستقیم به محصول می‌رود)
        if (!$out && ($meta('og:type') === 'product' || $meta('product:price:amount') !== '' || $xp->query("//body[contains(@class,'single-product')]")->length)) {
            $title = $meta('og:title');
            if ($title !== '') {
                $price = $meta('product:price:amount');
                $pn = $xp->query("//*[contains(@class,'summary')]//*[contains(@class,'price')]");
                $price_text = $pn && $pn->length ? trim(preg_replace('/\s+/u', ' ', $pn->item(0)->textContent)) : ($price !== '' ? number_format((float)$price) . ' تومان' : '');
                $dn = $xp->query("//*[contains(@class,'woocommerce-product-details__short-description')] | //*[@id='tab-description']");
                $desc = '';
                if ($dn) foreach ($dn as $d) { $desc .= trim($d->textContent) . "\n"; }
                $desc = trim(preg_replace('/[ \t]+/u', ' ', $desc)) ?: $meta('og:description');
                $out[] = ['title' => $title, 'price_text' => $price_text, 'url' => $meta('og:url') ?: $base_url, 'image' => $meta('og:image'), 'description' => mb_substr($desc, 0, 1000), 'stock' => null];
            }
        }
        return $out;
    }
}

if (!function_exists('saas_product_api_request')) {
    /**
     * یک درخواست جستجو در حالت مشخص
     * @return array ['ok'=>bool (پاسخ معتبر بود), 'items'=>[...], 'error'=>string]
     */
    function saas_product_api_request($mode, $api_url, $api_key, $q, $timeout = 8)
    {
        $root = saas_site_root($api_url);
        switch ($mode) {
            case 'wc_store':
                $url = $root . '/wp-json/wc/store/v1/products?per_page=5&search=' . rawurlencode($q);
                break;
            case 'wp_rest':
                $url = $root . '/wp-json/wp/v2/product?per_page=5&_embed=1&search=' . rawurlencode($q);
                break;
            case 'html':
                $url = $root . '/?post_type=product&s=' . rawurlencode($q);
                break;
            default: // json
                $sep = (strpos($api_url, '?') === false) ? '?' : '&';
                $url = $api_url . $sep . 'search=' . rawurlencode($q);
        }
        $r = saas_http_get($url, $mode === 'json' ? $api_key : '', $timeout);
        if ($r['body'] === '' || $r['code'] >= 400 || $r['code'] === 0) {
            return ['ok' => false, 'items' => [], 'error' => 'HTTP ' . $r['code'] . ($r['error'] ? ' — ' . $r['error'] : '')];
        }
        if ($mode === 'html') {
            if (stripos($r['body'], '<html') === false) return ['ok' => false, 'items' => [], 'error' => 'صفحه HTML نیست'];
            return ['ok' => true, 'items' => saas_parse_products_html($r['body'], $r['url'] ?: $url), 'error' => ''];
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) return ['ok' => false, 'items' => [], 'error' => 'پاسخ JSON نیست'];
        if (isset($data['code']) && isset($data['message']) && !isset($data[0])) {
            return ['ok' => false, 'items' => [], 'error' => (string)$data['message']];
        }
        return ['ok' => true, 'items' => saas_parse_products_json($data, $mode), 'error' => ''];
    }
}

if (!function_exists('saas_product_api_detect')) {
    /** تشخیص خودکار نوع اتصال برای آدرس واردشده */
    function saas_product_api_detect($api_url, $api_key, $q = 'a')
    {
        $modes = (stripos($api_url, 'wp-json') !== false) ? ['json'] : ['json', 'wc_store', 'wp_rest', 'sitemap', 'html'];
        $errors = [];
        foreach ($modes as $m) {
            if ($m === 'sitemap') {
                // سایت‌های غیر وردپرسی: فهرست محصولات از نقشه سایت خوانده می‌شود
                $urls = saas_sitemap_product_urls($api_url);
                if ($urls) return ['mode' => 'sitemap', 'items' => [], 'error' => '', 'count' => count($urls)];
                $errors[] = 'sitemap: نقشه سایت محصولات پیدا نشد';
                continue;
            }
            $r = saas_product_api_request($m, $api_url, $api_key, $q, 8);
            if ($r['ok'] && ($m !== 'html' || $r['items'])) return ['mode' => $m, 'items' => $r['items'], 'error' => ''];
            $errors[] = $m . ': ' . ($r['ok'] ? 'محصولی در صفحه جستجو پیدا نشد' : $r['error']);
        }
        return ['mode' => '', 'items' => [], 'error' => implode(' | ', $errors)];
    }
}

if (!function_exists('saas_fetch_product_from_api')) {
    /**
     * جستجوی محصولات در سایت کاربر (با تشخیص خودکار نوع API و کش یک‌ساعته)
     * @param \PDO|null $pdo  برای کش و ذخیره نوع API (اختیاری)
     */
    function saas_fetch_product_from_api($api_url, $api_key, $query, $pdo = null, $user_id = 0, $mode = '')
    {
        $api_url = trim((string)$api_url);
        if ($api_url === '' || !function_exists('curl_init') || !preg_match('#^https?://#i', $api_url)) return [];

        $terms = saas_search_terms($query);
        if (!$terms) return [];      // سلام و احوالپرسی و ... → نیاز به جستجو نیست
        $base_terms = array_values(array_filter($terms, function ($t) use ($terms) {
            foreach ($terms as $o) if ($o !== $t && mb_strlen($o) > mb_strlen($t) && mb_strpos($o, $t) === 0) return false;
            return true;
        }));
        $generic = ['قیمت','خرید','محصول','محصولات','دارید','موجود','چنده','چند','کالا','سفارش','ارسال','لطفا'];
        $specific = array_values(array_diff($base_terms, $generic));
        if (!$specific) $specific = $base_terms;

        // حالت نقشه سایت: جستجو در فهرست محلی محصولات (سریع، بدون درخواست به سایت)
        if ($mode === 'sitemap' && $pdo && $user_id) {
            return saas_site_index_search($pdo, $user_id, $api_url, $query);
        }

        // کش
        $cache_key = md5($api_url . '|' . implode(' ', $specific));
        if ($pdo && $user_id) {
            try {
                $c = $pdo->prepare("SELECT data FROM saas_api_cache WHERE user_id=? AND q_hash=? AND created_at >= (NOW() - INTERVAL 1 HOUR) LIMIT 1");
                if ($c && $c->execute([(int)$user_id, $cache_key])) {
                    $row = $c->fetch(\PDO::FETCH_ASSOC);
                    if ($row) { $d = json_decode($row['data'], true); if (is_array($d)) return $d; }
                }
            } catch (\Throwable $e) {}
        }

        // تشخیص نوع API (یک بار؛ نتیجه ذخیره می‌شود)
        if ($mode === '') {
            $det = saas_product_api_detect($api_url, $api_key, $specific[0]);
            $mode = $det['mode'];
            if ($mode !== '' && $pdo && $user_id) {
                try { $pdo->prepare("UPDATE saas_widget_settings SET product_api_mode=? WHERE user_id=?")->execute([$mode, (int)$user_id]); } catch (\Throwable $e) {}
            }
            if ($mode === '') {
                error_log('[AI] product API detect failed: ' . $det['error']);
                return [];
            }
            if ($mode === 'sitemap' && $pdo && $user_id) {
                return saas_site_index_search($pdo, $user_id, $api_url, $query);
            }
        }

        // جستجوها: ترکیب ۲ کلمه اصلی، سپس تک‌کلمه‌های بلندتر
        $tries = [];
        if (count($specific) >= 2) $tries[] = implode(' ', array_slice($specific, 0, 2));
        $by_len = $specific;
        usort($by_len, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_slice($by_len, 0, 2) as $t) $tries[] = $t;
        $tries = array_values(array_unique($tries));

        $start = microtime(true);
        $found = [];
        foreach ($tries as $q) {
            if (microtime(true) - $start > 12) break;      // سقف زمان کل
            $r = saas_product_api_request($mode, $api_url, $api_key, $q, 7);
            if ($r['items']) { $found = $r['items']; break; }
        }

        // تکمیل توضیحات اولین محصول از صفحه خودش (اگر توضیحی نداشت)
        if ($found && $found[0]['description'] === '' && $found[0]['url'] !== '' && microtime(true) - $start < 10) {
            $pg = saas_http_get($found[0]['url'], '', 6);
            if ($pg['code'] === 200 && $pg['body'] !== '') {
                $one = saas_parse_products_html($pg['body'], $found[0]['url']);
                if ($one && $one[0]['description'] !== '') {
                    $found[0]['description'] = $one[0]['description'];
                    if ($found[0]['price_text'] === '' && $one[0]['price_text'] !== '') $found[0]['price_text'] = $one[0]['price_text'];
                }
            }
        }

        if ($pdo && $user_id) {
            try {
                $pdo->prepare("INSERT INTO saas_api_cache (user_id, q_hash, data, created_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE data=VALUES(data), created_at=NOW()")
                    ->execute([(int)$user_id, $cache_key, json_encode($found, JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable $e) {}
        }
        return $found;
    }
}

// =============================================================================
// آرشیو پاسخ سوالات تکراری — پاسخ بدون مصرف توکن
// =============================================================================
if (!function_exists('saas_question_key')) {
    /** کلید یکتای سوال: کلمات معنادار، مرتب‌شده، بدون تکرار */
    function saas_question_key($q)
    {
        $terms = saas_search_terms($q);
        // فقط کلمات اصلی (نه ریشه‌های اضافه‌شده)
        $base = array_values(array_filter($terms, function ($t) use ($terms) {
            foreach ($terms as $o) if ($o !== $t && mb_strlen($o) > mb_strlen($t) && mb_strpos($o, $t) === 0) return false;
            return true;
        }));
        sort($base, SORT_STRING);
        return mb_substr(implode(' ', array_unique($base)), 0, 480);
    }
}

if (!function_exists('saas_cache_eligible')) {
    /** آیا این سوال مستقل از سابقه گفتگو و قابل آرشیو است؟ */
    function saas_cache_eligible($msg)
    {
        $n = saas_fa_normalize($msg);
        if (mb_strlen($n) > 200) return false;
        if (preg_match('/\d{5,}/', $n)) return false;   // اطلاعات شخصی (شماره تلفن، کد سفارش)
        // اشاره به پیام‌های قبلی
        if (preg_match('/(^|\s)(این|اون|آن|ان|همین|همون|همان|اینو|اونو|اینا|اونا|قبلی|بالا|گفتی|گفتید|گفتین|اولی|دومی|سومی|آخری|بیشتر|دیگه|دیگر|چرا|پس)(\s|$|[؟?!.،])/u', $n)) return false;
        // معرفی نام
        if (preg_match('/(اسم|نام)\s*(من|م)\b|من\s+\S+\s+هستم/u', $n)) return false;
        $key = saas_question_key($msg);
        return count(explode(' ', trim($key))) >= 2 && trim($key) !== '';
    }
}

if (!function_exists('saas_cache_render')) {
    /** جایگذاری نام کاربر در پاسخ آرشیوی */
    function saas_cache_render($answer, $name = '')
    {
        $name = trim((string)$name);
        if ($name !== '') return str_replace('{{name}}', $name, $answer);
        $a = preg_replace('/\{\{name\}\}\s*(جان|عزیز)?\s*[،,!]?\s*/u', '', $answer);
        return trim(preg_replace('/[ \t]{2,}/u', ' ', $a));
    }
}

if (!function_exists('saas_cache_lookup')) {
    /**
     * جستجوی پاسخ آرشیوشده
     * @return array|null  ['id', 'answer', 'images']
     */
    function saas_cache_lookup($pdo, $user_id, $msg, $days = 7)
    {
        $key = saas_question_key($msg);
        if ($key === '') return null;
        $days = max(1, (int)$days);
        $words = explode(' ', $key);
        $manual_only = !saas_cache_eligible($msg);

        // بدون انقضای تاریخی: پاسخ‌های خودکار فقط با تغییر پایگاه دانش پاک می‌شوند
        $st = $pdo->prepare("SELECT id, q_key, answer, images, is_manual FROM saas_answer_cache
            WHERE user_id=? AND enabled=1
            ORDER BY is_manual DESC, hits DESC, id DESC LIMIT 800");
        if (!$st || !$st->execute([(int)$user_id])) return null;

        $best = null; $best_sim = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($manual_only && !$r['is_manual']) continue;
            if ($r['q_key'] === $key) { $best = $r; $best_sim = 1; break; }
            $cw = explode(' ', $r['q_key']);
            $inter = count(array_intersect($words, $cw));
            $union = count(array_unique(array_merge($words, $cw)));
            if ($union === 0) continue;
            $sim = $inter / $union;
            $need = $r['is_manual'] ? 0.75 : 0.85;
            if ($sim >= $need && $sim > $best_sim && min(count($words), count($cw)) >= 2) { $best = $r; $best_sim = $sim; }
        }
        if (!$best) return null;
        $pdo->prepare("UPDATE saas_answer_cache SET hits = hits + 1, last_hit_at = NOW() WHERE id=?")->execute([$best['id']]);
        $imgs = json_decode((string)$best['images'], true);
        return ['id' => (int)$best['id'], 'answer' => $best['answer'], 'images' => is_array($imgs) ? $imgs : []];
    }
}

if (!function_exists('saas_cache_store')) {
    /** ذخیره پاسخ هوش مصنوعی در آرشیو (فقط سوال‌های مستقل و پاسخ‌های مناسب) */
    function saas_cache_store($pdo, $user_id, $msg, $answer, $images = [], $visitor_name = '')
    {
        if (!saas_cache_eligible($msg)) return false;
        $answer = trim((string)$answer);
        if ($answer === '' || mb_strlen($answer) < 15) return false;
        // جمله‌ای که نام کاربر را می‌پرسد از پاسخ آرشیوی حذف می‌شود
        $answer = preg_replace('/[^.!؟?\n]*(اسم|نام)\s*(تون|تان|شما|ت|ِ?\s*شما)[^.!؟?\n]*[.!؟?]?/u', '', $answer);
        $answer = trim(preg_replace("/\n{3,}/u", "\n\n", $answer));
        if ($answer === '' || mb_strlen($answer) < 15) return false;
        // پاسخ‌هایی که اطلاعات شخصی می‌خواهند یا خطا هستند آرشیو نمی‌شوند
        if (preg_match('/شماره\s*(تون|تان|شما|تماس\s*تون)|⚠️/u', $answer)) return false;
        $name = trim((string)$visitor_name);
        if ($name !== '' && mb_strlen($name) >= 2) $answer = str_replace($name, '{{name}}', $answer);

        $key = saas_question_key($msg);
        $ex = $pdo->prepare("SELECT id FROM saas_answer_cache WHERE user_id=? AND q_key=? LIMIT 1");
        $ex->execute([(int)$user_id, $key]);
        if ($ex->fetchColumn()) return false;

        $pdo->prepare("INSERT INTO saas_answer_cache (user_id, q_key, question, answer, images, hits, is_manual, enabled, created_at, updated_at)
            VALUES(?,?,?,?,?,0,0,1,NOW(),NOW())")
            ->execute([(int)$user_id, $key, mb_substr(trim($msg), 0, 1000), $answer, json_encode($images ?: new \stdClass(), JSON_UNESCAPED_UNICODE)]);
        return true;
    }
}

if (!function_exists('saas_cache_clear_auto')) {
    /** پاک‌کردن پاسخ‌های خودکار آرشیو (هنگام تغییر پایگاه دانش، محصولات یا تنظیمات) */
    function saas_cache_clear_auto($pdo, $user_id)
    {
        try {
            $pdo->prepare("DELETE FROM saas_answer_cache WHERE user_id=? AND is_manual=0")->execute([(int)$user_id]);
            $pdo->prepare("DELETE FROM saas_api_cache WHERE user_id=?")->execute([(int)$user_id]);
        } catch (\Throwable $e) {}
    }
}

// =============================================================================
// آیکون‌های آماده ویجت (دکمه باز کردن و دکمه ارسال)
// همه SVG با currentColor رسم می‌شوند تا رنگ متن ویجت روی آن‌ها اعمال شود.
// =============================================================================
if (!function_exists('saas_widget_icon_presets')) {
    function saas_widget_icon_presets()
    {
        $w = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100%" height="100%"';
        $st = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
        return [
            'launcher' => [
                'chat'    => ['label' => 'حباب گفتگو',  'svg' => "<svg $w $st><path d=\"M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z\"/></svg>"],
                'dots'    => ['label' => 'پیام در حال تایپ', 'svg' => "<svg $w $st><path d=\"M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.9-.9L3 21l1.9-5.1A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4z\"/><circle cx=\"8\" cy=\"12\" r=\".6\" fill=\"currentColor\"/><circle cx=\"12\" cy=\"12\" r=\".6\" fill=\"currentColor\"/><circle cx=\"16\" cy=\"12\" r=\".6\" fill=\"currentColor\"/></svg>"],
                'robot'   => ['label' => 'ربات',        'svg' => "<svg $w $st><rect x=\"4\" y=\"8\" width=\"16\" height=\"12\" rx=\"3\"/><path d=\"M12 8V4\"/><circle cx=\"12\" cy=\"3\" r=\"1\"/><circle cx=\"9\" cy=\"14\" r=\"1.2\" fill=\"currentColor\"/><circle cx=\"15\" cy=\"14\" r=\"1.2\" fill=\"currentColor\"/><path d=\"M2 13v3M22 13v3\"/></svg>"],
                'headset' => ['label' => 'پشتیبانی',    'svg' => "<svg $w $st><path d=\"M3 14v-2a9 9 0 0 1 18 0v2\"/><rect x=\"2\" y=\"14\" width=\"4\" height=\"6\" rx=\"1.5\"/><rect x=\"18\" y=\"14\" width=\"4\" height=\"6\" rx=\"1.5\"/><path d=\"M20 20a4 4 0 0 1-4 3h-2\"/></svg>"],
                'sparkle' => ['label' => 'هوش مصنوعی',  'svg' => "<svg $w $st><path d=\"M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z\"/><path d=\"M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z\"/></svg>"],
                'question'=> ['label' => 'سوال',        'svg' => "<svg $w $st><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3\"/><circle cx=\"12\" cy=\"17\" r=\".6\" fill=\"currentColor\"/></svg>"],
                'wave'    => ['label' => 'سلام (دست)',  'svg' => "<svg $w $st><path d=\"M7 11V6.5a1.5 1.5 0 0 1 3 0V11\"/><path d=\"M10 10V4.5a1.5 1.5 0 0 1 3 0V10\"/><path d=\"M13 10V5.5a1.5 1.5 0 0 1 3 0V12\"/><path d=\"M16 9.5a1.5 1.5 0 0 1 3 0V14a7 7 0 0 1-7 7h-1a7 7 0 0 1-5.4-2.6L3.3 15a1.6 1.6 0 0 1 2.4-2.1L7 14\"/></svg>"],
            ],
            'send' => [
                'plane'   => ['label' => 'هواپیمای کاغذی', 'svg' => "<svg $w $st style=\"transform:scaleX(-1)\"><path d=\"M22 2L11 13\"/><path d=\"M22 2l-7 20-4-9-9-4z\"/></svg>"],
                'arrow'   => ['label' => 'فلش',           'svg' => "<svg $w $st><path d=\"M19 12H5\"/><path d=\"M12 5l-7 7 7 7\"/></svg>"],
                'arrowup' => ['label' => 'فلش بالا',       'svg' => "<svg $w $st><path d=\"M12 19V5\"/><path d=\"M5 12l7-7 7 7\"/></svg>"],
                'triangle'=> ['label' => 'مثلث',          'svg' => "<svg $w fill=\"currentColor\"><path d=\"M20 4L4 12l16 8-3-8z\"/></svg>"],
                'circle'  => ['label' => 'دایره و فلش',    'svg' => "<svg $w $st><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"M16 12H8\"/><path d=\"M11 9l-3 3 3 3\"/></svg>"],
            ],
        ];
    }
}

if (!function_exists('saas_save_icon_upload')) {
    /**
     * ذخیره امن آیکون آپلودی (PNG/JPG/WEBP/GIF، حداکثر ۳۰۰ کیلوبایت)
     * @return array ['ok'=>bool, 'url'=>string, 'error'=>string]
     */
    function saas_save_icon_upload($file, $user_id, $kind)
    {
        if (empty($file['tmp_name']) || ($file['error'] ?? 4) !== UPLOAD_ERR_OK) return ['ok' => false, 'url' => '', 'error' => 'فایلی ارسال نشد.'];
        if ($file['size'] > 300 * 1024) return ['ok' => false, 'url' => '', 'error' => 'حجم آیکون نباید بیشتر از ۳۰۰ کیلوبایت باشد.'];
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = class_exists('finfo') ? (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : (getimagesize($file['tmp_name'])['mime'] ?? '');
        if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
            return ['ok' => false, 'url' => '', 'error' => 'فرمت آیکون باید PNG، JPG، WEBP یا GIF باشد.'];
        }
        $dir = rtrim(AICHAT_UPLOAD_DIR, '/') . '/icons/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['ok' => false, 'url' => '', 'error' => 'پوشه آپلود قابل ساخت نیست.'];
        $name = 'u' . (int)$user_id . '_' . preg_replace('/[^a-z]/', '', $kind) . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . $name)) return ['ok' => false, 'url' => '', 'error' => 'ذخیره فایل ناموفق بود.'];
        @chmod($dir . $name, 0644);
        return ['ok' => true, 'url' => rtrim(AICHAT_BASE_URL, '/') . '/uploads/icons/' . $name, 'error' => ''];
    }
}

// =============================================================================
// فهرست محصولات از روی نقشه سایت (برای سایت‌های اختصاصی / غیر وردپرسی)
// روند: robots.txt → Sitemap ها → آدرس صفحات محصول → خواندن اطلاعات هر صفحه
//       (JSON-LD نوع Product، متاتگ‌های og و product_*) → ذخیره در saas_site_products
// =============================================================================
if (!function_exists('saas_sitemap_product_urls')) {
    /** @return array [url => lastmod] */
    function saas_sitemap_product_urls($api_url)
    {
        $root = saas_site_root($api_url);
        if ($root === '') return [];
        $sitemaps = [];
        $rb = saas_http_get($root . '/robots.txt', '', 8);
        if ($rb['code'] === 200 && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $rb['body'], $m)) {
            foreach ($m[1] as $u) $sitemaps[] = trim($u);
        }
        if (!$sitemaps) {
            $sitemaps = [$root . '/sitemap.xml', $root . '/sitemap_index.xml', $root . '/product-sitemap.xml', $root . '/sitemap-products.xml'];
        }
        // نقشه‌های مخصوص محصول اول
        usort($sitemaps, fn($a, $b) => (int)(stripos($b, 'product') !== false) <=> (int)(stripos($a, 'product') !== false));

        $product_maps_found = false;
        $all = [];      // همه آدرس‌ها (برای حالت بدون نقشه مخصوص محصول)
        $products = [];
        $queue = array_slice($sitemaps, 0, 8);
        $seen = [];
        $fetched = 0;
        while ($queue && $fetched < 12) {
            $sm = array_shift($queue);
            if (isset($seen[$sm])) continue;
            $seen[$sm] = true;
            $r = saas_http_get($sm, '', 10);
            $fetched++;
            if ($r['code'] !== 200 || stripos($r['body'], '<loc>') === false) continue;
            $body = $r['body'];
            // نقشه فهرستی (sitemap index)
            if (stripos($body, '<sitemapindex') !== false) {
                preg_match_all('#<sitemap>.*?<loc>\s*([^<\s]+)\s*</loc>.*?</sitemap>#is', $body, $mm);
                foreach ($mm[1] as $child) {
                    $child = html_entity_decode($child);
                    if (stripos($child, 'product') !== false) array_unshift($queue, $child); else $queue[] = $child;
                }
                continue;
            }
            $is_product_map = stripos($sm, 'product') !== false;
            if (preg_match_all('#<url>(.*?)</url>#is', $body, $um)) {
                foreach ($um[1] as $block) {
                    if (!preg_match('#<loc>\s*([^<\s]+)\s*</loc>#i', $block, $lm)) continue;
                    $loc = html_entity_decode(trim($lm[1]));
                    $lastmod = preg_match('#<lastmod>\s*([^<\s]+)#i', $block, $lmm) ? $lmm[1] : '';
                    if ($is_product_map) { $products[$loc] = $lastmod; $product_maps_found = true; }
                    else $all[$loc] = $lastmod;
                }
            }
            if ($product_maps_found && count($products) > 0 && !$queue) break;
        }
        if (!$products) {
            // از بین همه آدرس‌ها، آن‌هایی که شبیه صفحه محصول هستند
            foreach ($all as $u => $lm) {
                if (preg_match('#/(product|products|shop|item|p)/[^/]+#i', parse_url($u, PHP_URL_PATH) ?? '')) $products[$u] = $lm;
            }
        }
        // فقط آدرس‌های همان سایت
        $host = parse_url($root, PHP_URL_HOST);
        foreach (array_keys($products) as $u) {
            $h = parse_url($u, PHP_URL_HOST);
            if ($h && preg_replace('/^www\./', '', $h) !== preg_replace('/^www\./', '', $host)) unset($products[$u]);
        }
        return array_slice($products, 0, 1000, true);
    }
}

if (!function_exists('saas_parse_product_page')) {
    /** استخراج اطلاعات یک صفحه محصول: JSON-LD → متاتگ‌ها → متن صفحه */
    function saas_parse_product_page($html, $url)
    {
        $out = ['title' => '', 'description' => '', 'price_text' => '', 'image' => '', 'stock' => null];
        if (trim((string)$html) === '') return $out;

        // --- JSON-LD ---
        $product = null;
        if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $ms)) {
            foreach ($ms[1] as $json) {
                $data = json_decode(trim($json), true);
                if (!is_array($data)) $data = json_decode(trim(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
                if (!is_array($data)) $data = json_decode(preg_replace('/[\x00-\x1F]+/', ' ', trim($json)), true);   // خط جدید داخل رشته‌ها
                if (!is_array($data)) continue;
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
                foreach ($nodes as $n) {
                    if (!is_array($n)) continue;
                    $t = $n['@type'] ?? '';
                    if ($t === 'Product' || (is_array($t) && in_array('Product', $t, true))) { $product = $n; break 2; }
                }
            }
        }
        $cur_label = function ($c) {
            $c = strtoupper(trim((string)$c));
            return ['IRR' => 'ریال', 'IRT' => 'تومان', 'TOMAN' => 'تومان', 'USD' => 'دلار', 'EUR' => 'یورو'][$c] ?? ($c ?: 'تومان');
        };
        $fmt_price = function ($val, $cur) use ($cur_label) {
            $v = (float)str_replace(',', '', (string)$val);
            if ($v <= 0) return '';
            $c = strtoupper(trim((string)$cur));
            if ($c === 'IRR') { $v = $v / 10; $c = 'IRT'; }        // ریال → تومان
            return number_format($v) . ' ' . $cur_label($c ?: 'IRT');
        };
        if ($product) {
            $out['title'] = trim(strip_tags((string)($product['name'] ?? '')));
            $img = $product['image'] ?? '';
            if (is_array($img)) $img = $img['url'] ?? ($img[0] ?? '');
            if (is_array($img)) $img = $img['url'] ?? '';
            $out['image'] = (string)$img;
            $out['description'] = saas_clean_html_text($product['description'] ?? '');
            $of = $product['offers'] ?? null;
            if (is_array($of)) {
                if (array_is_list($of)) $of = $of[0] ?? [];
                $cur = $of['priceCurrency'] ?? '';
                if (isset($of['lowPrice']) || isset($of['highPrice'])) {
                    $lo = $fmt_price($of['lowPrice'] ?? 0, $cur);
                    $hi = $fmt_price($of['highPrice'] ?? 0, $cur);
                    $out['price_text'] = ($lo && $hi && $lo !== $hi) ? "از $lo تا $hi" : ($lo ?: $hi);
                } else {
                    $out['price_text'] = $fmt_price($of['price'] ?? 0, $cur);
                }
                if (!empty($of['availability'])) $out['stock'] = stripos((string)$of['availability'], 'InStock') !== false;
            }
        }

        // --- متاتگ‌ها ---
        $meta = function ($name) use ($html) {
            $q = preg_quote($name, '#');
            if (preg_match('#<meta[^>]+(?:property|name)\s*=\s*["\']' . $q . '["\'][^>]*content\s*=\s*["\']([^"\']*)#i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if (preg_match('#<meta[^>]+content\s*=\s*["\']([^"\']*)["\'][^>]*(?:property|name)\s*=\s*["\']' . $q . '["\']#i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            return '';
        };
        if ($out['title'] === '') $out['title'] = trim($meta('product_name'));
        if ($out['title'] === '' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $h1)) $out['title'] = trim(saas_clean_html_text($h1[1]));
        if ($out['title'] === '') $out['title'] = trim($meta('og:title'));
        if ($out['image'] === '') $out['image'] = $meta('og:image');
        if ($out['price_text'] === '') {
            $pp = $meta('product_price') ?: $meta('product:price:amount');
            if ($pp !== '' && (float)str_replace(',', '', $pp) > 0) $out['price_text'] = number_format((float)str_replace(',', '', $pp)) . ' تومان';
        }
        if ($out['stock'] === null) {
            $av = $meta('availability') ?: $meta('product:availability');
            if ($av !== '') $out['stock'] = stripos($av, 'instock') !== false || stripos($av, 'in stock') !== false;
        }
        $desc = $out['description'] ?: $meta('og:description') ?: $meta('description');

        // --- قیمت دقیقاً همان‌طور که در صفحه نمایش داده می‌شود (با واحد ریال/تومان) ---
        // برخی سایت‌ها در داده ساختاریافته واحد را اشتباه می‌نویسند؛ قیمت قابل مشاهده ملاک است.
        $plain = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', str_replace(['ريال', "\u{00A0}"], ['ریال', ' '], $plain));
        $cands = [];
        foreach (['product_price', 'product:price:amount'] as $mk) { $v = $meta($mk); if ($v !== '') $cands[] = $v; }
        if ($product && is_array($product['offers'] ?? null)) {
            $of2 = array_is_list($product['offers']) ? ($product['offers'][0] ?? []) : $product['offers'];
            foreach (['price', 'lowPrice', 'highPrice'] as $k) if (isset($of2[$k])) $cands[] = (string)$of2[$k];
        }
        foreach ($cands as $cv) {
            $num = (float)str_replace(',', '', $cv);
            if ($num <= 0) continue;
            $fmt = number_format($num);
            $fa  = strtr($fmt, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',','=>'٬']);
            foreach ([$fmt, $fa, (string)(int)$num] as $needle) {
                if (preg_match('/(?<![\d۰-۹,٬])' . preg_quote($needle, '/') . '\s*(ریال|تومان)/u', $plain, $um)) {
                    $out['price_text'] = $fmt . ' ' . $um[1];
                    break 2;
                }
            }
        }

        // --- گزینه‌ها و قیمت‌های جانبی (مثلاً: پایه استیل 2,350,000 ریال) ---
        $options = [];
        if (preg_match_all('#<(label|option|tr)\b[^>]*>(.*?)</\1>#is', $html, $om)) {
            foreach ($om[2] as $o) {
                $t = saas_clean_html_text($o);
                $t = preg_replace('/\s+/u', ' ', $t);
                if (mb_strlen($t) < 4 || mb_strlen($t) > 200) continue;
                if (!preg_match('/[\d۰-۹][\d۰-۹,٬]*\s*(ریال|ريال|تومان)/u', $t)) continue;
                $options[$t] = true;
                if (count($options) >= 12) break;
            }
        }

        // --- متن اصلی صفحه: بخش توضیحات محصول (بدون منو، هدر، فوتر، فرم‌ها) ---
        $main_text = '';
        if (class_exists('DOMDocument')) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>' . preg_replace('#<(script|style|noscript|form)\b.*?</\1>#is', ' ', $html));
            libxml_clear_errors();
            $xp = new \DOMXPath($dom);
            $best = null; $best_len = 0;
            $q = "//*[@id='tab-description' or @id='description' or contains(concat(' ', normalize-space(@class), ' '), ' content ') or contains(@class,'product-description') or contains(@class,'entry-content') or contains(@class,'product-content') or contains(@class,'description')]";
            foreach ($xp->query($q) ?: [] as $node) {
                $len = mb_strlen(trim($node->textContent));
                if ($len > $best_len) { $best_len = $len; $best = $node; }
            }
            if ($best && $best_len > 200) {
                foreach ($xp->query('.//p|.//li|.//h2|.//h3', $best) as $n) {
                    $t = trim(preg_replace('/\s+/u', ' ', $n->textContent));
                    if (mb_strlen($t) < 20 || mb_strpos($main_text, $t) !== false) continue;
                    $main_text .= $t . "\n";
                    if (mb_strlen($main_text) > 2200) break;
                }
            }
        }
        if ($main_text === '') {
            // جایگزین: پاراگراف‌های صفحه بدون هدر/فوتر/منو
            $body = preg_replace('#<(script|style|noscript|header|footer|nav|form)\b.*?</\1>#is', ' ', $html);
            if (preg_match_all('#<(p|li|h2|h3)[^>]*>(.*?)</\1>#is', $body, $pm)) {
                foreach ($pm[2] as $para) {
                    $t = saas_clean_html_text($para);
                    if (mb_strlen($t) < 25 || mb_strpos($main_text, $t) !== false) continue;
                    $main_text .= $t . "\n";
                    if (mb_strlen($main_text) > 2000) break;
                }
            }
        }
        $parts_desc = [];
        // ترتیب: گزینه‌ها و قیمت‌ها (مهم‌ترین) → خلاصه → متن کامل توضیحات
        if ($options) $parts_desc[] = "گزینه‌ها و قیمت‌ها:\n- " . implode("\n- ", array_keys($options));
        $mt = trim($main_text);
        if ($desc !== '' && ($mt === '' || mb_strpos(saas_fa_normalize($mt), saas_fa_normalize(mb_substr($desc, 0, 60))) === false)) $parts_desc[] = $desc;
        if ($mt !== '') $parts_desc[] = $mt;
        $desc = implode("\n", $parts_desc);
        $out['description'] = mb_substr(trim($desc), 0, 2500);
        $out['title'] = mb_substr($out['title'], 0, 490);
        if ($out['image'] !== '' && !preg_match('#^https?://#i', $out['image'])) {
            $out['image'] = rtrim(saas_site_root($url), '/') . '/' . ltrim($out['image'], '/');
        }
        return $out;
    }
}

if (!function_exists('saas_site_index_sync')) {
    /**
     * به‌روزرسانی فهرست محصولات سایت (دسته‌ای، با سقف زمان)
     * @return array ['total'=>کل محصولات نقشه, 'done'=>تعداد خوانده‌شده, 'remaining'=>باقی‌مانده, 'error'=>'']
     */
    function saas_site_index_sync($pdo, $user_id, $api_url, $max_pages = 10, $time_budget = 20)
    {
        $user_id = (int)$user_id;
        $start = microtime(true);
        $urls = saas_sitemap_product_urls($api_url);
        if (!$urls) return ['total' => 0, 'done' => 0, 'remaining' => 0, 'error' => 'نقشه سایت محصولات پیدا نشد.'];

        $st = $pdo->prepare("SELECT url, lastmod, fetched_ok, updated_at FROM saas_site_products WHERE user_id=?");
        $st->execute([$user_id]);
        $have = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $have[$r['url']] = $r;

        // حذف محصولاتی که دیگر در نقشه سایت نیستند
        $gone = array_diff(array_keys($have), array_keys($urls));
        if ($gone) {
            $del = $pdo->prepare("DELETE FROM saas_site_products WHERE user_id=? AND url=?");
            foreach ($gone as $g) $del->execute([$user_id, $g]);
        }

        // صف: جدیدها، تغییرکرده‌ها، و قدیمی‌تر از ۲۴ ساعت
        $todo = [];
        $day_ago = time() - 86400;
        foreach ($urls as $u => $lm) {
            if (!isset($have[$u])) { $todo[$u] = 0; continue; }
            $h = $have[$u];
            if (!$h['fetched_ok'] || ($lm !== '' && $lm !== $h['lastmod']) || strtotime($h['updated_at']) < $day_ago) {
                $todo[$u] = strtotime($h['updated_at']);
            }
        }
        asort($todo);   // قدیمی‌ترها اول
        $ups = $pdo->prepare("INSERT INTO saas_site_products (user_id, url, title, description, price_text, image, stock, lastmod, fetched_ok, updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), price_text=VALUES(price_text),
                image=VALUES(image), stock=VALUES(stock), lastmod=VALUES(lastmod), fetched_ok=VALUES(fetched_ok), updated_at=NOW()");
        $done = 0;
        foreach (array_keys($todo) as $u) {
            if ($done >= $max_pages || microtime(true) - $start > $time_budget) break;
            $r = saas_http_get($u, '', 8);
            $ok = ($r['code'] === 200 && $r['body'] !== '');
            $d = $ok ? saas_parse_product_page($r['body'], $u) : ['title' => '', 'description' => '', 'price_text' => '', 'image' => '', 'stock' => null];
            if ($d['title'] === '') { $ok = false; }
            $ups->execute([
                $user_id, mb_substr($u, 0, 500), $d['title'], $d['description'], mb_substr($d['price_text'], 0, 250),
                mb_substr($d['image'], 0, 500), $d['stock'] === null ? null : ($d['stock'] ? 1 : 0), $urls[$u], $ok ? 1 : 0,
            ]);
            $done++;
        }
        return ['total' => count($urls), 'done' => $done, 'remaining' => max(0, count($todo) - $done), 'error' => ''];
    }
}

if (!function_exists('saas_site_index_search')) {
    /** جستجو در فهرست محصولات سایت (محلی) */
    function saas_site_index_search($pdo, $user_id, $api_url, $query, $limit = 5)
    {
        $user_id = (int)$user_id;
        $st = $pdo->prepare("SELECT url, title, description, price_text, image, stock, updated_at FROM saas_site_products WHERE user_id=? AND fetched_ok=1 ORDER BY id ASC LIMIT 2000");
        $st->execute([$user_id]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // فهرست خالی است → یک دسته کوچک همین حالا خوانده شود
        if (!$rows) {
            saas_site_index_sync($pdo, $user_id, $api_url, 8, 12);
            $st->execute([$user_id]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            // به‌روزرسانی خودکار در پس‌زمینه (پس از ارسال پاسخ به کاربر) — حداکثر یک بار در ساعت
            $oldest = min(array_map(fn($r) => strtotime($r['updated_at']), $rows));
            if ($oldest < time() - 86400) saas_site_index_schedule_refresh($pdo, $user_id, $api_url);
        }
        if (!$rows) return [];

        $terms = saas_search_terms($query);
        $generic = array_flip(['قیمت','خرید','محصول','محصولات','دارید','داری','موجود','چنده','کالا','سفارش','کدوم','کدام','لیست','فهرست','انواع','همه']);
        $spec_terms = array_values(array_filter($terms, fn($t) => !isset($generic[$t])));
        $scored = [];
        foreach ($rows as $i => $r) {
            $sc = saas_text_score($spec_terms, saas_fa_normalize($r['title']), 6)
                + saas_text_score($spec_terms, saas_fa_normalize(mb_substr((string)$r['description'], 0, 1500)), 1);
            if ($sc > 0) { $r['_s'] = $sc; $r['_i'] = $i; $scored[] = $r; }
        }
        $as_item = fn($r) => [
            'title' => $r['title'], 'price_text' => $r['price_text'], 'url' => $r['url'], 'image' => $r['image'],
            'description' => (string)$r['description'], 'stock' => $r['stock'] === null ? null : (bool)$r['stock'],
        ];
        if ($scored) {
            usort($scored, fn($a, $b) => $a['_s'] === $b['_s'] ? $a['_i'] <=> $b['_i'] : $b['_s'] <=> $a['_s']);
            return array_map($as_item, array_slice($scored, 0, $limit));
        }
        // سوال کلی (مثلاً «قیمت کدوم محصولات رو دارید؟») → فهرست کوتاه محصولات با قیمت
        $nq = saas_fa_normalize($query);
        if (preg_match('/محصول|کالا|قیمت|خرید|لیست|فهرست|انواع|چه\s*چیز|چی\s*دار/u', $nq)) {
            $list = array_map(function ($r) use ($as_item) { $x = $as_item($r); $x['description'] = ''; return $x; }, array_slice($rows, 0, 12));
            return $list;
        }
        return [];
    }
}

if (!function_exists('saas_site_index_schedule_refresh')) {
    /** به‌روزرسانی فهرست محصولات بعد از ارسال پاسخ (بدون معطل کردن بازدیدکننده) */
    function saas_site_index_schedule_refresh($pdo, $user_id, $api_url)
    {
        static $scheduled = false;
        if ($scheduled) return;
        $scheduled = true;
        $lock = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.sync_' . (int)$user_id;
        if (is_file($lock) && filemtime($lock) > time() - 3600) return;     // حداکثر یک بار در ساعت
        @touch($lock);
        register_shutdown_function(function () use ($pdo, $user_id, $api_url) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @ignore_user_abort(true);
            @set_time_limit(120);
            try { saas_site_index_sync($pdo, $user_id, $api_url, 15, 60); } catch (\Throwable $e) { error_log('[AI] site index refresh: ' . $e->getMessage()); }
        });
    }
}

// =============================================================================
// امکانات پلن‌ها
// =============================================================================
if (!function_exists('saas_plan_features')) {
    /** فهرست امکانات قابل تنظیم برای هر پلن: کلید ستون => [عنوان، آیکون] */
    function saas_plan_features()
    {
        return [
            'has_knowledge_base'  => ['label' => 'پایگاه دانش',          'icon' => '📚'],
            'has_products'        => ['label' => 'مدیریت محصولات',       'icon' => '🛒'],
            'has_product_api'     => ['label' => 'اتصال API محصولات سایت', 'icon' => '🔌'],
            'has_conversations'   => ['label' => 'مکالمات کاربران',       'icon' => '💬'],
            'has_answer_archive'  => ['label' => 'آرشیو پاسخ‌ها',         'icon' => '📦'],
            'has_site_posts'      => ['label' => 'اتصال مطالب (وبلاگ) سایت', 'icon' => '📰'],
            'has_site_pages'      => ['label' => 'اتصال صفحات ثابت سایت',  'icon' => '📄'],
            'has_expert_bot'      => ['label' => 'چت‌بات تخصصی (با نام خودتان)', 'icon' => '🤖'],
            'has_color_customize' => ['label' => 'انتخاب رنگ ویجت',       'icon' => '🎨'],
        ] + (function_exists('ex_plan_extra_flags') ? ex_plan_extra_flags() : []);
    }
}

if (!function_exists('saas_plan_allows')) {
    /** آیا پلن این امکان را دارد؟ (کاربر بدون پلن: همه امکانات آزاد) */
    function saas_plan_allows($plan, $feature)
    {
        if (!$plan || !is_array($plan)) return true;
        if (!array_key_exists($feature, $plan)) return true;   // ستون هنوز ساخته نشده
        return !empty($plan[$feature]);
    }
}

if (!function_exists('saas_plan_feature_notes')) {
    /** توضیح کوتاه (حداکثر ۲۰۰ کاراکتر) هر امکان پلن */
    function saas_plan_feature_notes($plan)
    {
        $n = json_decode((string)($plan['feature_notes'] ?? ''), true);
        return is_array($n) ? $n : [];
    }
}

if (!function_exists('saas_plan_locked_html')) {
    /** پیام «این امکان در پلن شما نیست» */
    function saas_plan_locked_html($feature_label)
    {
        return '<div class="alert alert-info" style="line-height:2">🔒 امکان «' . htmlspecialchars($feature_label, ENT_QUOTES, 'UTF-8')
             . '» در پلن فعلی شما فعال نیست. برای استفاده، پلن خود را <a href="billing.php">ارتقا دهید</a>.</div>';
    }
}

if (!function_exists('saas_activate_plan')) {
    /**
     * فعال‌سازی پلن برای کاربر + افزودن توکن اولیه پلن به اعتبار او
     * - توکن پلن‌های رایگان فقط یک بار برای هر کاربر داده می‌شود (جلوگیری از سوءاستفاده)
     * @return array ['ok'=>bool, 'tokens'=>توکن اضافه‌شده, 'error'=>string]
     */
    function saas_activate_plan($pdo, $user_id, $plan_id, $desc = '', $ref_id = null, $add_credit = true)
    {
        $user_id = (int)$user_id;
        $plan_id = (int)$plan_id;
        if ($plan_id <= 0) {
            $pdo->prepare("UPDATE saas_users SET plan_id=NULL, plan_expires_at=NULL WHERE id=?")->execute([$user_id]);
            return ['ok' => true, 'tokens' => 0, 'error' => ''];
        }
        $plan = saas_get_plan($pdo, $plan_id);
        if (!$plan) return ['ok' => false, 'tokens' => 0, 'error' => 'پلن یافت نشد.'];

        // اشتراک یک‌ساله (تمدید همان پلن پیش از انقضا: از تاریخ انقضای فعلی)
        $cur = $pdo->prepare("SELECT plan_id, plan_expires_at FROM saas_users WHERE id=?");
        $cur->execute([$user_id]);
        $cur_row = $cur->fetch(\PDO::FETCH_ASSOC) ?: [];
        $new_exp = function_exists('biz_new_expiry') ? biz_new_expiry($cur_row, $plan_id) : date('Y-m-d H:i:s', strtotime('+1 year'));
        $pdo->prepare("UPDATE saas_users SET plan_id=?, plan_expires_at=? WHERE id=?")->execute([$plan_id, $new_exp, $user_id]);
        // سهمیه پیامک رایگان برای دوره جدید از صفر شروع می‌شود
        try { $pdo->prepare("UPDATE saas_users SET sms_free_used=0 WHERE id=?")->execute([$user_id]); } catch (\Throwable $e) {}

        $tokens = 0;
        if ($add_credit && (int)$plan['credit_tokens'] > 0) {
            $give = true;
            if ((int)$plan['price_toman'] <= 0 && $ref_id === null) {
                // پلن رایگان: اگر قبلاً توکن همین پلن را گرفته، دوباره داده نمی‌شود
                $chk = $pdo->prepare("SELECT COUNT(*) FROM saas_payments WHERE user_id=? AND plan_id=? AND type='plan' AND status='paid' AND amount_toman=0");
                $chk->execute([$user_id, $plan_id]);
                if ((int)$chk->fetchColumn() > 0) $give = false;
                else {
                    $pdo->prepare("INSERT INTO saas_payments (user_id, amount_toman, type, plan_id, credit_tokens, status, description, created_at, paid_at) VALUES(?,0,'plan',?,?,'paid',?,NOW(),NOW())")
                        ->execute([$user_id, $plan_id, (int)$plan['credit_tokens'], 'فعال‌سازی پلن رایگان ' . $plan['name']]);
                }
            }
            if ($give) {
                $tokens = (int)$plan['credit_tokens'];
                saas_add_credit($pdo, $user_id, $tokens, $desc !== '' ? $desc : ('اعتبار پلن ' . $plan['name']), $ref_id);
            }
        }
        return ['ok' => true, 'tokens' => $tokens, 'error' => ''];
    }
}

if (!function_exists('saas_plan_feature_list')) {
    /**
     * همه موارد تیک‌خورده/تعیین‌شده یک پلن برای نمایش به کاربران
     * @return array [ ['text'=>..., 'note'=>...], ... ]
     */
    function saas_plan_feature_list($plan)
    {
        $notes = saas_plan_feature_notes($plan);
        $n = fn($k) => trim((string)($notes[$k] ?? ''));
        $out = [['text' => 'اشتراک یک‌ساله', 'note' => $n('duration'), 'key' => 'duration']];
        if (function_exists('biz_plan_models') && function_exists('aichat_connect')) {
            try {
                $pdo_f = aichat_connect();
                $cat_l = ['text' => 'مدل‌های گفتگو', 'image' => 'ساخت تصویر', 'video' => 'ساخت ویدیو'];
                foreach ($cat_l as $ck => $cl) {
                    $pm = biz_plan_models($pdo_f, $plan, $ck);
                    // نسخه ۵۹: فقط تعداد مدل‌ها (اصلی و جایگزین) — مدل‌های تازه هم خودکار اضافه می‌شوند
                    if ($pm) $out[] = ['text' => $cl . ': ' . (function_exists('mx_count_text') ? mx_count_text(mx_count_models($pdo_f, $pm)) : count($pm) . ' مدل'), 'note' => $ck === 'text' ? $n('models') : '', 'key' => 'models_' . $ck];
                }
                $rt = biz_renew_tiers($pdo_f);
                if ($rt && (int)($plan['price_toman'] ?? 0) > 0) $out[] = ['text' => 'تا ' . max(array_column($rt, 1)) . '٪ تخفیف برای تمدید زودهنگام', 'note' => '', 'key' => 'renew'];
            } catch (\Throwable $e) {}
        }
        if ((int)($plan['setup_fee'] ?? 0) > 0) $out[] = ['text' => 'راه‌اندازی توسط ما (اختیاری): ' . number_format((int)$plan['setup_fee']) . ' تومان', 'note' => $n('setup_fee'), 'key' => 'setup_fee'];
        if ((int)($plan['credit_tokens'] ?? 0) > 0) $out[] = ['text' => number_format((int)$plan['credit_tokens']) . ' تومان اعتبار هدیه', 'note' => $n('credit_tokens'), 'key' => 'credit_tokens'];
        if ((int)($plan['max_requests_per_day'] ?? 0) > 0) $out[] = ['text' => (int)$plan['max_requests_per_day'] . ' درخواست در روز', 'note' => $n('max_requests_per_day'), 'key' => 'max_requests_per_day'];
        if ((int)($plan['max_tokens_per_request'] ?? 0) > 0) $out[] = ['text' => 'حداکثر ' . number_format((int)$plan['max_tokens_per_request']) . ' توکن در هر پیام', 'note' => $n('max_tokens_per_request'), 'key' => 'max_tokens_per_request'];
        $rl = ['short' => 'کوتاه', 'medium' => 'متوسط', 'long' => 'بلند'][$plan['response_length'] ?? ''] ?? '';
        if ($rl !== '') $out[] = ['text' => 'پاسخ ' . $rl, 'note' => $n('response_length'), 'key' => 'response_length'];
        if ((int)($plan['free_sms'] ?? 0) > 0) $out[] = ['text' => number_format((int)$plan['free_sms']) . ' پیامک رایگان برای مخاطبان', 'note' => $n('free_sms'), 'key' => 'free_sms'];
        foreach (saas_plan_features() as $fk => $f) {
            if (!saas_plan_allows($plan, $fk)) continue;
            $text = $f['label'];
            $note = $n($fk);
            if ($fk === 'has_knowledge_base') {
                $mk = (int)($plan['max_knowledge_items'] ?? 0);
                if ($mk > 0) $text .= ' (' . number_format($mk) . ' مورد)';
                if ($note === '') $note = $n('max_knowledge_items');
            } elseif ($fk === 'has_expert_bot') {
                $mb = (int)($plan['max_bots'] ?? 0);
                $text .= $mb > 0 ? ' (' . number_format($mb) . ' ربات)' : ' (نامحدود)';
                if ($note === '') $note = $n('max_bots');
            } elseif ($fk === 'has_products') {
                $mp = (int)($plan['max_products'] ?? 0);
                $text .= $mp > 0 ? ' (' . number_format($mp) . ' محصول)' : ' (نامحدود)';
                if ($note === '') $note = $n('max_products');
            }
            $out[] = ['text' => $text, 'note' => $note, 'key' => $fk];
        }
        return $out;
    }
}

// =============================================================================
// اتصال مطالب (وبلاگ) و صفحات ثابت سایت
// کشف آدرس‌ها: وردپرس (REST) → نقشه سایت → لینک‌های صفحه اصلی + آدرس‌های دستی
// =============================================================================
if (!function_exists('saas_content_patterns')) {
    function saas_content_patterns($kind)
    {
        return $kind === 'post'
            ? ['map' => '/blog|post|article|news|mag|maghale|content/i', 'path' => '#/(?:[a-z]+[_-])?(blog|post|posts|article|articles|news|mag|maghale|maghalat|weblog)(?:[_-][a-z]+)?(/|$)#i']
            : ['map' => '/page/i', 'path' => '#/(page|pages)/[^/]+|/(about|about-us|contact|contact-us|terms|rules|faq|privacy|ghavanin|darbare-ma|tamas-ba-ma)/?$#i'];
    }
}

if (!function_exists('saas_content_discover_urls')) {
    /** @return array [url => lastmod] */
    function saas_content_discover_urls($site_url, $kind, $extra = '')
    {
        $urls = [];
        // آدرس‌های دستی (هر خط یک آدرس)
        foreach (preg_split('/[\r\n]+/', (string)$extra) as $line) {
            $line = trim($line);
            if (preg_match('#^https?://\S+$#i', $line)) $urls[$line] = '';
        }
        $root = saas_site_root($site_url);
        if ($root === '') return array_slice($urls, 0, 400, true);
        $host = preg_replace('/^www\./', '', (string)parse_url($root, PHP_URL_HOST));
        $same = fn($u) => preg_replace('/^www\./', '', (string)parse_url($u, PHP_URL_HOST)) === $host;
        $pat = saas_content_patterns($kind);
        $max = $kind === 'post' ? 300 : 60;

        // ۱) وردپرس
        $wp = saas_http_get($root . '/wp-json/wp/v2/' . ($kind === 'post' ? 'posts' : 'pages') . '?per_page=100&_fields=link,modified', '', 8);
        if ($wp['code'] === 200) {
            $d = json_decode($wp['body'], true);
            if (is_array($d) && array_is_list($d)) {
                foreach ($d as $it) if (!empty($it['link'])) $urls[$it['link']] = (string)($it['modified'] ?? '');
                if (count($urls) > 0) return array_slice($urls, 0, $max, true);
            }
        }

        // ۲) نقشه سایت
        $sitemaps = [];
        $rb = saas_http_get($root . '/robots.txt', '', 8);
        if ($rb['code'] === 200 && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $rb['body'], $m)) foreach ($m[1] as $u) $sitemaps[] = trim($u);
        if (!$sitemaps) $sitemaps = [$root . '/sitemap.xml', $root . '/sitemap_index.xml'];
        $queue = $sitemaps; $seen = []; $fetched = 0; $all = []; $from_kind_map = [];
        while ($queue && $fetched < 12) {
            $sm = array_shift($queue);
            if (isset($seen[$sm])) continue;
            $seen[$sm] = true;
            $r = saas_http_get($sm, '', 10); $fetched++;
            if ($r['code'] !== 200 || stripos($r['body'], '<loc>') === false) continue;
            if (stripos($r['body'], '<sitemapindex') !== false) {
                preg_match_all('#<sitemap>.*?<loc>\s*([^<\s]+)\s*</loc>#is', $r['body'], $mm);
                foreach ($mm[1] as $c) $queue[] = html_entity_decode($c);
                continue;
            }
            $is_kind_map = preg_match($pat['map'], (string)parse_url($sm, PHP_URL_PATH)) && stripos($sm, 'product') === false && stripos($sm, 'categor') === false;
            if (preg_match_all('#<url>(.*?)</url>#is', $r['body'], $um)) {
                foreach ($um[1] as $b) {
                    if (!preg_match('#<loc>\s*([^<\s]+)\s*</loc>#i', $b, $lm)) continue;
                    $loc = html_entity_decode(trim($lm[1]));
                    $lmod = preg_match('#<lastmod>\s*([^<\s]+)#i', $b, $x) ? $x[1] : '';
                    if ($is_kind_map) $from_kind_map[$loc] = $lmod; else $all[$loc] = $lmod;
                }
            }
        }
        if ($from_kind_map) {
            foreach ($from_kind_map as $u => $lm) if ($same($u)) $urls[$u] = $lm;
        } else {
            foreach ($all as $u => $lm) if ($same($u) && preg_match($pat['path'], (string)parse_url($u, PHP_URL_PATH))) $urls[$u] = $lm;
        }

        // ۳) لینک‌های صفحه اصلی (و صفحه وبلاگ)
        if ($kind === 'page' || count($urls) < 3) {
            $pages_to_scan = [$root . '/'];
            if ($kind === 'post') $pages_to_scan[] = $root . '/blog';
            foreach ($pages_to_scan as $pu) {
                $h = saas_http_get($pu, '', 8);
                if ($h['code'] !== 200) continue;
                if (preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\'\#]+)["\']#i', $h['body'], $lm)) {
                    foreach ($lm[1] as $href) {
                        $href = html_entity_decode(trim($href));
                        if (strpos($href, '//') === 0) $href = 'https:' . $href;
                        elseif ($href !== '' && $href[0] === '/') $href = $root . $href;
                        if (!preg_match('#^https?://#i', $href) || !$same($href)) continue;
                        $path = (string)parse_url($href, PHP_URL_PATH);
                        if (!preg_match($pat['path'], $path)) continue;
                        if ($kind === 'post' && preg_match('#^/(blog|news|mag|weblog)/?$#i', $path)) continue;  // خود صفحه فهرست
                        $urls[strtok($href, '?')] = $urls[strtok($href, '?')] ?? '';
                    }
                }
            }
        }
        return array_slice($urls, 0, $max, true);
    }
}

if (!function_exists('saas_parse_content_page')) {
    /** عنوان و متن اصلی یک مطلب/صفحه */
    function saas_parse_content_page($html, $url)
    {
        $out = ['title' => '', 'content' => ''];
        if (trim((string)$html) === '') return $out;
        if (preg_match('#<meta[^>]+property\s*=\s*["\']og:title["\'][^>]*content\s*=\s*["\']([^"\']*)#i', $html, $m)) $out['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) { $h1 = trim(saas_clean_html_text($m[1])); if ($h1 !== '') $out['title'] = $h1; }
        if ($out['title'] === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $out['title'] = trim(saas_clean_html_text($m[1]));

        $text = '';
        if (class_exists('DOMDocument')) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>' . preg_replace('#<(script|style|noscript|form|header|footer|nav|aside)\b.*?</\1>#is', ' ', $html));
            libxml_clear_errors();
            $xp = new \DOMXPath($dom);
            $best = null; $best_len = 0;
            $q = "//article | //main | //*[@id='content' or @id='main' or contains(concat(' ', normalize-space(@class), ' '), ' content ') or contains(@class,'entry-content') or contains(@class,'post-content') or contains(@class,'article') or contains(@class,'blog-content') or contains(@class,'page-content') or contains(@class,'single')]";
            foreach ($xp->query($q) ?: [] as $node) {
                $len = mb_strlen(trim($node->textContent));
                if ($len > $best_len) { $best_len = $len; $best = $node; }
            }
            if ($best && $best_len > 150) {
                foreach ($xp->query('.//p|.//li|.//h2|.//h3|.//h4|.//td', $best) as $n) {
                    $t = trim(preg_replace('/\s+/u', ' ', $n->textContent));
                    if (mb_strlen($t) < 3 || mb_strpos($text, $t) !== false) continue;
                    $text .= $t . "\n";
                    if (mb_strlen($text) > 8000) break;
                }
                // لینک‌های مهم داخل متن (مثل لوکیشن نقشه، شبکه‌های اجتماعی، فرم‌ها)
                $links = [];
                foreach ($xp->query('.//a[@href]', $best) as $a) {
                    $href = trim($a->getAttribute('href'));
                    $lt = trim(preg_replace('/\s+/u', ' ', $a->textContent));
                    if ($lt === '' || mb_strlen($lt) > 80 || !preg_match('#^(https?://|tel:)#i', $href)) continue;
                    $links[$lt . ': ' . $href] = true;
                    if (count($links) >= 12) break;
                }
                if ($links) $text .= "لینک‌ها:\n- " . implode("\n- ", array_keys($links)) . "\n";
            }
        }
        if (mb_strlen($text) < 150) {
            $body = preg_replace('#<(script|style|noscript|header|footer|nav|form|aside)\b.*?</\1>#is', ' ', $html);
            $text = '';
            if (preg_match_all('#<(p|li|h2|h3|td)[^>]*>(.*?)</\1>#is', $body, $pm)) {
                foreach ($pm[2] as $para) {
                    $t = saas_clean_html_text($para);
                    if (mb_strlen($t) < 3 || mb_strpos($text, $t) !== false) continue;
                    $text .= $t . "\n";
                    if (mb_strlen($text) > 8000) break;
                }
            }
        }
        if (mb_strlen($text) < 150) {
            // صفحات ساخته‌شده با جاوااسکریپت: داده‌های ساخت‌یافته (JSON-LD) و داده اولیه Next/Nuxt
            $js = [];
            if (preg_match_all('#<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $jm)) {
                foreach ($jm[1] as $j) { $d = json_decode(html_entity_decode(trim($j), ENT_QUOTES, 'UTF-8'), true); if (is_array($d)) saas_collect_json_text($d, $js); }
            }
            if (preg_match('#<script[^>]+id\s*=\s*["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $nm)) {
                $d = json_decode(trim($nm[1]), true); if (is_array($d)) saas_collect_json_text($d, $js);
            }
            if ($js) {
                $jt = implode("\n", array_keys($js));
                if (mb_strlen($jt) > mb_strlen($text)) $text = $jt;
            }
        }
        if (mb_strlen($text) < 150) {
            // صفحه فهرست یا قالب غیرمعمول: همه متن قابل مشاهده + فهرست مطالب لینک‌شده
            $body = preg_replace('#<(script|style|noscript|svg|header|footer|nav|form|aside|template)\b.*?</\1>#is', ' ', $html);
            if (preg_match('#<body[^>]*>(.*)</body>#is', $body, $bm)) $body = $bm[1];
            $body = preg_replace('#<(br|/div|/p|/li|/h[1-6]|/a|/span|/section|/article|/td|/tr)\b[^>]*>#i', "\n", $body);
            $lines = []; $vt = '';
            foreach (preg_split('/\n+/u', html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8')) as $ln) {
                $ln = trim(preg_replace('/\s+/u', ' ', $ln));
                if (mb_strlen($ln) < 12 || isset($lines[$ln])) continue;
                $lines[$ln] = true; $vt .= $ln . "\n";
                if (mb_strlen($vt) > 8000) break;
            }
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $items = [];
            if (preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#is', $html, $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $lt = trim(preg_replace('/\s+/u', ' ', saas_clean_html_text($a[2])));
                    if (mb_strlen($lt) < 12 || mb_strlen($lt) > 150) continue;
                    $href = html_entity_decode($a[1], ENT_QUOTES, 'UTF-8');
                    if (strpos($href, '//') === 0) $href = 'https:' . $href;
                    elseif (!preg_match('#^https?://#i', $href)) $href = rtrim(preg_replace('#^(https?://[^/]+).*$#i', '$1', $url), '/') . '/' . ltrim($href, '/');
                    if ($host !== '' && strtolower((string)parse_url($href, PHP_URL_HOST)) !== $host) continue;
                    $items[$lt . ': ' . $href] = true;
                    if (count($items) >= 40) break;
                }
            }
            if (count($items) >= 3) $vt .= "مطالب این صفحه:\n- " . implode("\n- ", array_keys($items)) . "\n";
            if (mb_strlen($vt) > mb_strlen($text)) $text = $vt;
        }
        if (mb_strlen($text) < 80 && preg_match('#<meta[^>]+name\s*=\s*["\']description["\'][^>]*content\s*=\s*["\']([^"\']*)#i', $html, $m)) {
            $text = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') . "\n" . $text);
        }
        $out['title'] = mb_substr(trim($out['title']), 0, 490);
        $out['content'] = mb_substr(trim($text), 0, 8000);
        return $out;
    }
}

if (!function_exists('saas_collect_json_text')) {
    /** جمع‌آوری متن‌های معنادار از داده JSON (برای صفحات جاوااسکریپتی) */
    function saas_collect_json_text($d, &$out, $depth = 0)
    {
        if ($depth > 12 || count($out) > 300) return;
        foreach ($d as $k => $v) {
            if (is_array($v)) { saas_collect_json_text($v, $out, $depth + 1); continue; }
            if (!is_string($v)) continue;
            if (is_string($k) && preg_match('/(url|href|src|image|id|slug|path|key|type|class|style|date|time|hash|token|locale|lang)$/i', $k)) continue;
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8')));
            if (mb_strlen($t) < 25 || preg_match('#^(https?:)?//#i', $t) || !preg_match('/\p{L}{3}/u', $t)) continue;
            $out[mb_substr($t, 0, 3000)] = true;
        }
    }
}

if (!function_exists('saas_site_content_sync')) {
    /** به‌روزرسانی مطالب/صفحات (دسته‌ای با سقف زمان) */
    function saas_site_content_sync($pdo, $user_id, $kind, $site_url, $extra = '', $max_pages = 20, $time_budget = 25)
    {
        $user_id = (int)$user_id;
        $kind = $kind === 'page' ? 'page' : 'post';
        $start = microtime(true);
        $urls = saas_content_discover_urls($site_url, $kind, $extra);
        if (!$urls) return ['total' => 0, 'done' => 0, 'remaining' => 0, 'error' => $kind === 'post' ? 'مطلبی در سایت پیدا نشد.' : 'صفحه‌ای در سایت پیدا نشد.'];

        $st = $pdo->prepare("SELECT url, lastmod, fetched_ok, updated_at FROM saas_site_content WHERE user_id=? AND kind=?");
        $st->execute([$user_id, $kind]);
        $have = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $have[$r['url']] = $r;
        $gone = array_diff(array_keys($have), array_keys($urls));
        if ($gone) {
            $del = $pdo->prepare("DELETE FROM saas_site_content WHERE user_id=? AND kind=? AND url=?");
            foreach ($gone as $g) $del->execute([$user_id, $kind, $g]);
        }
        $todo = [];
        $day_ago = time() - 86400;
        foreach ($urls as $u => $lm) {
            if (!isset($have[$u])) { $todo[$u] = 0; continue; }
            $h = $have[$u];
            if (!$h['fetched_ok'] || ($lm !== '' && $lm !== $h['lastmod']) || strtotime($h['updated_at']) < $day_ago) $todo[$u] = strtotime($h['updated_at']);
        }
        asort($todo);
        $ups = $pdo->prepare("INSERT INTO saas_site_content (user_id, kind, url, title, content, lastmod, fetched_ok, updated_at)
            VALUES(?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), lastmod=VALUES(lastmod), fetched_ok=VALUES(fetched_ok), updated_at=NOW()");
        $done = 0;
        foreach (array_keys($todo) as $u) {
            if ($done >= $max_pages || microtime(true) - $start > $time_budget) break;
            $r = saas_http_get($u, '', 8);
            $ok = ($r['code'] === 200 && $r['body'] !== '');
            $d = $ok ? saas_parse_content_page($r['body'], $u) : ['title' => '', 'content' => ''];
            if ($d['title'] === '' || mb_strlen($d['content']) < 20) $ok = false;
            $ups->execute([$user_id, $kind, mb_substr($u, 0, 500), $d['title'], $d['content'], $urls[$u], $ok ? 1 : 0]);
            $done++;
        }
        return ['total' => count($urls), 'done' => $done, 'remaining' => max(0, count($todo) - $done), 'error' => ''];
    }
}

if (!function_exists('saas_site_content_search')) {
    /** جستجو در مطالب/صفحات ذخیره‌شده؛ از هر مورد مرتبط حداقل ۱۰۰۰ کاراکتر */
    function saas_site_content_search($pdo, $user_id, $kind, $query, $limit = 3, $site_url = '', $extra = '')
    {
        $user_id = (int)$user_id;
        $kind = $kind === 'page' ? 'page' : 'post';
        $st = $pdo->prepare("SELECT url, title, content, updated_at FROM saas_site_content WHERE user_id=? AND kind=? AND fetched_ok=1 ORDER BY id ASC LIMIT 1000");
        $st->execute([$user_id, $kind]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($site_url !== '' || trim((string)$extra) !== '') {
            if (!$rows) {
                saas_site_content_sync($pdo, $user_id, $kind, $site_url, $extra, 6, 10);
                $st->execute([$user_id, $kind]);
                $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } else {
                $oldest = min(array_map(fn($r) => strtotime($r['updated_at']), $rows));
                if ($oldest < time() - 86400) saas_site_content_schedule_refresh($pdo, $user_id, $kind, $site_url, $extra);
            }
        }
        if (!$rows) return [];
        $terms = saas_search_terms($query);
        if (!$terms) return [];
        $scored = [];
        foreach ($rows as $i => $r) {
            $sc = saas_text_score($terms, saas_fa_normalize($r['title']), 6) + saas_text_score($terms, saas_fa_normalize($r['content']), 1);
            if ($sc >= 2) { $r['_s'] = $sc; $r['_i'] = $i; $scored[] = $r; }
        }
        if (!$scored) return [];
        usort($scored, fn($a, $b) => $a['_s'] === $b['_s'] ? $a['_i'] <=> $b['_i'] : $b['_s'] <=> $a['_s']);
        $out = [];
        foreach (array_slice($scored, 0, $limit) as $r) {
            $out[] = ['title' => $r['title'], 'url' => $r['url'], 'text' => saas_best_snippet($r['content'], $terms, 1000, 1500)];
        }
        return $out;
    }
}

if (!function_exists('saas_site_content_schedule_refresh')) {
    function saas_site_content_schedule_refresh($pdo, $user_id, $kind, $site_url, $extra)
    {
        static $scheduled = [];
        if (!empty($scheduled[$kind])) return;
        $scheduled[$kind] = true;
        $lock = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.sync_' . $kind . '_' . (int)$user_id;
        if (is_file($lock) && filemtime($lock) > time() - 3600) return;
        @touch($lock);
        register_shutdown_function(function () use ($pdo, $user_id, $kind, $site_url, $extra) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @ignore_user_abort(true);
            @set_time_limit(120);
            try { saas_site_content_sync($pdo, $user_id, $kind, $site_url, $extra, 15, 60); } catch (\Throwable $e) { error_log('[AI] content refresh: ' . $e->getMessage()); }
        });
    }
}

// امکانات تجاری (اشتراک، پیامک، تخفیف، همکاری در فروش، اعلان‌ها)
require_once __DIR__ . '/biz_lib.php';
