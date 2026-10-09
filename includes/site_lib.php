<?php
/**
 * site_lib.php — کتابخانه مدیریت محتوای سایت عمومی
 * جداول: aisite_slides, aisite_pages, aisite_posts, aisite_tiles, aisite_settings
 */

// ── Schema ──────────────────────────────────────────────────────────────────
function site_ensure_schema(PDO $pdo): void {
    // هر جدول جداگانه ایجاد می‌شود — PDO چند دستور را در یک exec نمی‌پذیرد
    $pdo->exec("CREATE TABLE IF NOT EXISTS aisite_settings (
        `key`   VARCHAR(80) PRIMARY KEY,
        `value` TEXT NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aisite_slides (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(255) NOT NULL DEFAULT '',
        badge_text  VARCHAR(80)  NOT NULL DEFAULT '',
        subtitle    VARCHAR(255) NOT NULL DEFAULT '',
        image_url   TEXT         NOT NULL DEFAULT '',
        description TEXT         NOT NULL DEFAULT '',
        btn1_text   VARCHAR(80)  NOT NULL DEFAULT '',
        btn1_url    VARCHAR(255) NOT NULL DEFAULT '',
        btn2_text   VARCHAR(80)  NOT NULL DEFAULT '',
        btn2_url    VARCHAR(255) NOT NULL DEFAULT '',
        sort_order  TINYINT      NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aisite_pages (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(255) NOT NULL DEFAULT '',
        slug          VARCHAR(120) NOT NULL DEFAULT '',
        content       LONGTEXT     NOT NULL DEFAULT '',
        show_in_menu  TINYINT(1)   NOT NULL DEFAULT 0,
        menu_label    VARCHAR(80)  NOT NULL DEFAULT '',
        menu_sort     TINYINT      NOT NULL DEFAULT 0,
        meta_title    VARCHAR(255) NOT NULL DEFAULT '',
        meta_desc     VARCHAR(320) NOT NULL DEFAULT '',
        meta_keywords VARCHAR(255) NOT NULL DEFAULT '',
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aisite_posts (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title            VARCHAR(255) NOT NULL DEFAULT '',
        slug             VARCHAR(120) NOT NULL UNIQUE,
        excerpt          TEXT         NOT NULL DEFAULT '',
        content          LONGTEXT     NOT NULL DEFAULT '',
        image_url        TEXT         NOT NULL DEFAULT '',
        show_on_homepage TINYINT(1)   NOT NULL DEFAULT 1,
        show_in_menu     TINYINT(1)   NOT NULL DEFAULT 0,
        menu_label       VARCHAR(80)  NOT NULL DEFAULT '',
        menu_sort        TINYINT      NOT NULL DEFAULT 0,
        is_published     TINYINT(1)   NOT NULL DEFAULT 0,
        meta_title       VARCHAR(255) NOT NULL DEFAULT '',
        meta_desc        VARCHAR(320) NOT NULL DEFAULT '',
        meta_keywords    VARCHAR(255) NOT NULL DEFAULT '',
        published_at     DATETIME     NULL,
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aisite_tiles (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(255) NOT NULL DEFAULT '',
        description TEXT         NOT NULL DEFAULT '',
        image_url   TEXT         NOT NULL DEFAULT '',
        link_url    VARCHAR(255) NOT NULL DEFAULT '#',
        icon        VARCHAR(80)  NOT NULL DEFAULT 'star',
        sort_order  TINYINT      NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── مقادیر پیش‌فرض ─────────────────────────────────────────────────────
    $defaults = [
        'site_name'                => 'ویجت هوشمند',
        'site_tagline'             => 'دستیار هوش مصنوعی برای کسب‌وکار شما',
        'site_description'         => 'پلتفرم چت‌بات هوشمند مبتنی بر هوش مصنوعی',
        'site_logo_url'            => '',
        'site_favicon_url'         => '',
        'footer_text'              => '© ۱۴۰۴ ویجت هوشمند. کلیه حقوق محفوظ.',
        'intro_enabled'            => '1',
        'intro_image_url'          => '',
        'intro_title'              => 'به دنیای هوش مصنوعی خوش آمدید',
        'intro_subtitle'           => 'لحظه‌ای صبر کنید...',
        'notice_bar_text'          => '🎉 ثبت‌نام رایگان — همین الان شروع کنید',
        'notice_bar_link'          => '',
        'notice_bar_show'          => '0',
        'og_image'                 => '',
        'twitter_handle'           => '',
        'ga_id'                    => '',
        'meta_robots'              => 'index, follow',
        'site_verification_google' => '',
        'business_type'            => 'Organization',
        'business_address'         => '',
        'business_city'            => '',
        'business_phone'           => '',
        'business_email'           => '',
        'business_opening_hours'   => '',
        'business_area_served'     => '',
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO aisite_settings (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

    // ── اسلاید نمونه ───────────────────────────────────────────────────────
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM aisite_slides")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO aisite_slides (title, badge_text, subtitle, description, btn1_text, btn1_url, btn2_text, btn2_url, sort_order, is_active)
            VALUES ('منشی هوشمند آینده', 'هوش مصنوعی', 'اتوماسیون کامل امور دفتری با قدرت AI', 'کارهای دفتری خود را به دستیار هوشمند بسپارید', 'شروع رایگان', '#register', 'بیشتر بدانید', '#features', 0, 1)");
    }

    // ── تایل‌های نمونه ─────────────────────────────────────────────────────
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM aisite_tiles")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO aisite_tiles (title, description, icon, sort_order, is_active) VALUES
            ('پشتیبانی ۲۴/۷', 'دستیار هوشمند همیشه در دسترس برای پاسخگویی به مشتریان', 'support_agent', 1, 1),
            ('پردازش هوشمند', 'تحلیل و پردازش درخواست‌ها با الگوریتم‌های پیشرفته', 'psychology', 2, 1),
            ('یکپارچه‌سازی', 'اتصال آسان به سیستم‌های موجود کسب‌وکار شما', 'integration_instructions', 3, 1)");
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function site_h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function site_slug(string $text): string {
    $text = mb_strtolower(trim($text));
    $fa   = ['آ','ا','ب','پ','ت','ث','ج','چ','ح','خ','د','ذ','ر','ز','ژ','س','ش','ص','ض','ط','ظ','ع','غ','ف','ق','ک','گ','ل','م','ن','و','ه','ی','ئ'];
    $en   = ['a','a','b','p','t','s','j','ch','h','kh','d','z','r','z','zh','s','sh','s','z','t','z','a','gh','f','gh','k','g','l','m','n','v','h','i','y'];
    $text = str_replace($fa, $en, $text);
    $text = preg_replace('/[^a-z0-9\-]+/', '-', $text);
    $text = trim(preg_replace('/-+/', '-', $text), '-');
    return $text ?: 'page-' . time();
}

// ── Settings ────────────────────────────────────────────────────────────────
function site_settings(PDO $pdo): array {
    $rows = $pdo->query("SELECT `key`, `value` FROM aisite_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows ?: [];
}
function site_setting(PDO $pdo, string $key, string $default = ''): string {
    $row = $pdo->prepare("SELECT `value` FROM aisite_settings WHERE `key`=?");
    $row->execute([$key]);
    $v = $row->fetchColumn();
    return ($v !== false) ? $v : $default;
}
function site_save_setting(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare("INSERT INTO aisite_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $st->execute([$key, $value]);
}

// ── Slides CRUD ─────────────────────────────────────────────────────────────
function site_get_slides(PDO $pdo, bool $active_only = true): array {
    $sql = "SELECT * FROM aisite_slides" . ($active_only ? " WHERE is_active=1" : "") . " ORDER BY sort_order, id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function site_save_slide(PDO $pdo, array $d, ?int $id = null): void {
    if ($id) {
        $st = $pdo->prepare("UPDATE aisite_slides SET title=?,badge_text=?,subtitle=?,image_url=?,description=?,btn1_text=?,btn1_url=?,btn2_text=?,btn2_url=?,sort_order=?,is_active=? WHERE id=?");
        $st->execute([$d['title'],$d['badge_text'],$d['subtitle'],$d['image_url'],$d['description'],$d['btn1_text'],$d['btn1_url'],$d['btn2_text'],$d['btn2_url'],$d['sort_order'],$d['is_active'],$id]);
    } else {
        $st = $pdo->prepare("INSERT INTO aisite_slides (title,badge_text,subtitle,image_url,description,btn1_text,btn1_url,btn2_text,btn2_url,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$d['title'],$d['badge_text'],$d['subtitle'],$d['image_url'],$d['description'],$d['btn1_text'],$d['btn1_url'],$d['btn2_text'],$d['btn2_url'],$d['sort_order'],$d['is_active']]);
    }
}
function site_delete_slide(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM aisite_slides WHERE id=?")->execute([$id]);
}

// ── Pages CRUD ───────────────────────────────────────────────────────────────
function site_get_pages(PDO $pdo, bool $active_only = true): array {
    $sql = "SELECT * FROM aisite_pages" . ($active_only ? " WHERE is_active=1" : "") . " ORDER BY menu_sort, id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function site_get_page_by_slug(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare("SELECT * FROM aisite_pages WHERE slug=? AND is_active=1 LIMIT 1");
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function site_get_menu_pages(PDO $pdo): array {
    return $pdo->query("SELECT * FROM aisite_pages WHERE is_active=1 AND show_in_menu=1 ORDER BY menu_sort, id")->fetchAll(PDO::FETCH_ASSOC);
}
function site_save_page(PDO $pdo, array $d, ?int $id = null): void {
    if ($id) {
        $st = $pdo->prepare("UPDATE aisite_pages SET title=?,slug=?,content=?,show_in_menu=?,menu_label=?,menu_sort=?,meta_title=?,meta_desc=?,meta_keywords=?,is_active=? WHERE id=?");
        $st->execute([$d['title'],$d['slug'],$d['content'],$d['show_in_menu'],$d['menu_label'],$d['menu_sort'],$d['meta_title'],$d['meta_desc'],$d['meta_keywords'],$d['is_active'],$id]);
    } else {
        $st = $pdo->prepare("INSERT INTO aisite_pages (title,slug,content,show_in_menu,menu_label,menu_sort,meta_title,meta_desc,meta_keywords,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$d['title'],$d['slug'],$d['content'],$d['show_in_menu'],$d['menu_label'],$d['menu_sort'],$d['meta_title'],$d['meta_desc'],$d['meta_keywords'],$d['is_active']]);
    }
}
function site_delete_page(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM aisite_pages WHERE id=?")->execute([$id]);
}

// ── Posts CRUD ───────────────────────────────────────────────────────────────
function site_get_posts(PDO $pdo, bool $published_only = true): array {
    $sql = "SELECT * FROM aisite_posts" . ($published_only ? " WHERE is_published=1" : "") . " ORDER BY created_at DESC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function site_get_post_by_slug(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare("SELECT * FROM aisite_posts WHERE slug=? AND is_published=1 LIMIT 1");
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function site_get_homepage_posts(PDO $pdo, int $limit = 6): array {
    $st = $pdo->prepare("SELECT * FROM aisite_posts WHERE is_published=1 AND show_on_homepage=1 ORDER BY created_at DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function site_save_post(PDO $pdo, array $d, ?int $id = null): void {
    $pub = !empty($d['is_published']) ? 1 : 0;
    if ($id) {
        $st = $pdo->prepare("UPDATE aisite_posts SET title=?,slug=?,excerpt=?,content=?,image_url=?,show_on_homepage=?,show_in_menu=?,menu_label=?,menu_sort=?,is_published=?,meta_title=?,meta_desc=?,meta_keywords=? WHERE id=?");
        $st->execute([$d['title'],$d['slug'],$d['excerpt'],$d['content'],$d['image_url'],$d['show_on_homepage'],$d['show_in_menu'],$d['menu_label'],$d['menu_sort'],$pub,$d['meta_title'],$d['meta_desc'],$d['meta_keywords'],$id]);
    } else {
        $st = $pdo->prepare("INSERT INTO aisite_posts (title,slug,excerpt,content,image_url,show_on_homepage,show_in_menu,menu_label,menu_sort,is_published,meta_title,meta_desc,meta_keywords) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$d['title'],$d['slug'],$d['excerpt'],$d['content'],$d['image_url'],$d['show_on_homepage'],$d['show_in_menu'],$d['menu_label'],$d['menu_sort'],$pub,$d['meta_title'],$d['meta_desc'],$d['meta_keywords']]);
    }
}
function site_delete_post(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM aisite_posts WHERE id=?")->execute([$id]);
}

// ── Feature Tiles CRUD ───────────────────────────────────────────────────────
function site_get_feature_tiles(PDO $pdo, bool $active_only = true): array {
    $sql = "SELECT * FROM aisite_tiles" . ($active_only ? " WHERE is_active=1" : "") . " ORDER BY sort_order, id LIMIT 3";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function site_save_tile(PDO $pdo, array $d, ?int $id = null): void {
    if ($id) {
        $st = $pdo->prepare("UPDATE aisite_tiles SET title=?,description=?,image_url=?,link_url=?,icon=?,sort_order=?,is_active=? WHERE id=?");
        $st->execute([$d['title'],$d['description'],$d['image_url'],$d['link_url'],$d['icon'],$d['sort_order'],$d['is_active'],$id]);
    } else {
        $st = $pdo->prepare("INSERT INTO aisite_tiles (title,description,image_url,link_url,icon,sort_order,is_active) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$d['title'],$d['description'],$d['image_url'],$d['link_url'],$d['icon'],$d['sort_order'],$d['is_active']]);
    }
}
function site_delete_tile(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM aisite_tiles WHERE id=?")->execute([$id]);
}

// ── SEO: Security Headers (public pages) ────────────────────────────────────
function site_security_headers(): void {
    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

// ── SEO: Meta Tags ───────────────────────────────────────────────────────────
function site_seo_tags(array $opts = []): string {
    $title       = $opts['title']       ?? '';
    $desc        = $opts['desc']        ?? '';
    $keywords    = $opts['keywords']    ?? '';
    $canonical   = $opts['canonical']   ?? '';
    $og_image    = $opts['og_image']    ?? '';
    $og_type     = $opts['og_type']     ?? 'website';
    $tw_handle   = $opts['tw_handle']   ?? '';
    $site_name   = $opts['site_name']   ?? '';
    $robots      = $opts['robots']      ?? 'index, follow';
    $gsc_code    = $opts['gsc_code']    ?? '';

    $out = '';
    if ($title)     $out .= '<title>' . site_h($title) . '</title>' . "\n";
    $out .= '<meta name="robots" content="' . site_h($robots) . '">' . "\n";
    if ($desc)      $out .= '<meta name="description" content="' . site_h($desc) . '">' . "\n";
    if ($keywords)  $out .= '<meta name="keywords"    content="' . site_h($keywords) . '">' . "\n";
    if ($canonical) $out .= '<link rel="canonical"    href="'   . site_h($canonical) . '">' . "\n";
    if ($gsc_code)  $out .= '<meta name="google-site-verification" content="' . site_h($gsc_code) . '">' . "\n";

    // Open Graph
    $out .= '<meta property="og:type"        content="' . site_h($og_type) . '">' . "\n";
    if ($title)     $out .= '<meta property="og:title"       content="' . site_h($title) . '">' . "\n";
    if ($desc)      $out .= '<meta property="og:description" content="' . site_h($desc) . '">' . "\n";
    if ($canonical) $out .= '<meta property="og:url"         content="' . site_h($canonical) . '">' . "\n";
    if ($og_image)  $out .= '<meta property="og:image"       content="' . site_h($og_image) . '">' . "\n";
    if ($site_name) $out .= '<meta property="og:site_name"   content="' . site_h($site_name) . '">' . "\n";

    // Twitter Card
    $out .= '<meta name="twitter:card" content="' . ($og_image ? 'summary_large_image' : 'summary') . '">' . "\n";
    if ($tw_handle) $out .= '<meta name="twitter:site"  content="' . site_h($tw_handle) . '">' . "\n";
    if ($title)     $out .= '<meta name="twitter:title" content="' . site_h($title) . '">' . "\n";
    if ($desc)      $out .= '<meta name="twitter:description" content="' . site_h($desc) . '">' . "\n";
    if ($og_image)  $out .= '<meta name="twitter:image" content="' . site_h($og_image) . '">' . "\n";

    return $out;
}

// ── SEO: Schema.org JSON-LD ──────────────────────────────────────────────────
function site_schema_org(string $type, array $data): string {
    $schema = array_merge(['@context' => 'https://schema.org', '@type' => $type], $data);
    return '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";
}

/**
 * سئو محلی: داده‌های ساختاریافته Organization / LocalBusiness
 */
function site_local_business_schema(array $s, string $site_url): string {
    $type = !empty($s['business_type']) ? $s['business_type'] : 'Organization';
    $data = [
        '@type' => $type,
        'name'  => $s['site_name'] ?? '',
        'url'   => $site_url,
    ];
    if (!empty($s['site_logo_url']))  $data['logo'] = $s['site_logo_url'];
    if (!empty($s['business_phone'])) $data['telephone'] = $s['business_phone'];
    if (!empty($s['business_email'])) $data['email'] = $s['business_email'];
    if (!empty($s['site_description'])) $data['description'] = $s['site_description'];

    // آدرس
    if (!empty($s['business_address']) || !empty($s['business_city'])) {
        $data['address'] = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $s['business_address'] ?? '',
            'addressLocality' => $s['business_city']    ?? '',
            'addressCountry'  => 'IR',
        ]);
    }

    if (!empty($s['business_opening_hours'])) $data['openingHours'] = $s['business_opening_hours'];
    if (!empty($s['business_area_served']))   $data['areaServed']   = $s['business_area_served'];
    if (!empty($s['og_image']))               $data['image']        = $s['og_image'];

    return site_schema_org($type, $data);
}

/**
 * Schema.org BreadcrumbList
 */
function site_breadcrumb_schema(array $items): string {
    // $items = [['name'=>'خانه','url'=>'https://...'], ['name'=>'درباره ما','url'=>'...']]
    $list = [];
    foreach ($items as $i => $item) {
        $list[] = ['@type'=>'ListItem','position'=>$i+1,'name'=>$item['name'],'item'=>$item['url']];
    }
    return site_schema_org('BreadcrumbList', ['itemListElement' => $list]);
}

/**
 * Schema.org Article برای مطالب
 */
function site_article_schema(array $post, string $site_name, string $post_url): string {
    $data = [
        'headline'    => $post['title'] ?? '',
        'description' => $post['excerpt'] ?? '',
        'url'         => $post_url,
        'publisher'   => ['@type' => 'Organization', 'name' => $site_name],
    ];
    if (!empty($post['image_url']))  $data['image']       = $post['image_url'];
    if (!empty($post['created_at'])) $data['datePublished']= $post['created_at'];
    return site_schema_org('Article', $data);
}

// ── منوی موبایل صفحه‌های عمومی (بدون وابستگی به فونت آیکون گوگل) ─────────────
function site_burger_svg(): string {
    return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
}
function site_mobile_burger(): string {
    return '<button type="button" class="smn-btn" id="smnBtn" aria-label="منو" aria-controls="smnMenu" aria-expanded="false">' . site_burger_svg() . '</button>';
}
/** کشوی منوی موبایل برای page.php / post.php / plan.php */
function site_mobile_menu(PDO $pdo): string {
    $links = [['index.php', 'خانه']];
    try {
        foreach (site_get_menu_pages($pdo) as $mp) $links[] = ['page.php?slug=' . urlencode($mp['slug']), $mp['menu_label'] ?: $mp['title']];
        foreach ($pdo->query("SELECT title, slug, menu_label FROM aisite_posts WHERE is_published=1 AND show_in_menu=1 ORDER BY menu_sort, id")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mp)
            $links[] = ['post.php?slug=' . urlencode($mp['slug']), $mp['menu_label'] ?: $mp['title']];
    } catch (\Throwable $e) {}
    $links[] = ['index.php#blog', 'مطالب'];
    $links[] = ['index.php#plans', 'پلن‌ها'];
    $h = '<div class="smn" id="smnMenu" role="dialog" aria-modal="true" aria-label="منو" hidden><div class="smn-in">'
       . '<button type="button" class="smn-x" aria-label="بستن منو">✕</button>';
    foreach ($links as $l) $h .= '<a href="' . site_h($l[0]) . '">' . site_h($l[1]) . '</a>';
    $u = defined('AICHAT_USER_URL') ? AICHAT_USER_URL : 'user/';
    $h .= '<div class="smn-act"><a class="smn-o" href="' . site_h($u . 'login.php') . '">ورود</a><a class="smn-p" href="' . site_h($u . 'register.php') . '">ثبت‌نام رایگان</a></div></div></div>';
    return $h . <<<'HTML'
<style>
.smn-btn{display:none;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;cursor:pointer;flex:0 0 auto;-webkit-tap-highlight-color:transparent}
@media(max-width:640px){.smn-btn{display:inline-flex}.nav-inner>.btn{padding:8px 12px!important;font-size:12.5px!important}}
.smn{position:fixed;inset:0;z-index:1000;background:rgba(0,9,34,.6);backdrop-filter:blur(3px)}
.smn[hidden]{display:none}
.smn-in{position:absolute;top:0;right:0;bottom:0;width:min(320px,86vw);background:#020b2d;border-left:1px solid rgba(255,255,255,.08);padding:64px 16px calc(20px + env(safe-area-inset-bottom));display:flex;flex-direction:column;gap:4px;overflow-y:auto;box-shadow:-12px 0 40px rgba(0,0,0,.4);animation:smnin .22s ease}
@keyframes smnin{from{transform:translateX(100%)}to{transform:none}}
.smn-in>a{display:block;padding:14px 14px;border-radius:12px;color:#e2e8f0;font-size:15.5px;font-weight:500;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.05)}
.smn-in>a:active,.smn-in>a:hover{background:rgba(255,255,255,.07)}
.smn-x{position:absolute;top:12px;left:12px;width:42px;height:42px;border-radius:50%;border:0;background:rgba(255,255,255,.08);color:#fff;font-size:18px;cursor:pointer}
.smn-act{margin-top:18px;display:flex;flex-direction:column;gap:10px}
.smn-act a{display:block;text-align:center;padding:13px;border-radius:12px;font-weight:700;text-decoration:none}
.smn-o{border:1px solid rgba(255,255,255,.2);color:#fff}.smn-p{background:#2563eb;color:#fff}
@media (prefers-reduced-motion:reduce){.smn-in{animation:none}}
</style>
<script>
(function(){
  var b = document.getElementById('smnBtn'), m = document.getElementById('smnMenu'); if (!b || !m) return;
  function set(o){ m.hidden = !o; b.setAttribute('aria-expanded', o ? 'true' : 'false'); document.body.style.overflow = o ? 'hidden' : ''; if (o) { var x = m.querySelector('.smn-x'); if (x) x.focus(); } else b.focus(); }
  b.onclick = function(){ set(m.hidden); };
  m.addEventListener('click', function(e){ if (e.target === m || e.target.closest('.smn-x') || e.target.closest('a')) set(false); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !m.hidden) set(false); });
})();
</script>
HTML;
}
