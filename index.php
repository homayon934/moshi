<?php
/**
 * صفحه اصلی عمومی سایت
 *  - طرح «کیهانی» (پیش‌فرض از نسخه ۴۰): includes/home_cosmic.php
 *  - طرح «کلاسیک» (اسلایدر قبلی): includes/home_classic.php
 * انتخاب طرح و همه متن‌ها/لوگوها: مدیریت ← تنظیمات سایت ← «صفحه اصلی»
 */
if (!defined('AICHAT_HOME')) define('AICHAT_HOME', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_lib.php';
require_once __DIR__ . '/includes/saas_lib.php';   // نمایش امکانات پلن‌ها

// ── امنیت ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/sec_lib.php';
sec_boot('public');   // امنیت: نشست امن، WAF، محدودیت درخواست، هدرها

// ── دیتابیس و اسکیما ────────────────────────────────────────────────────────
$pdo = aichat_connect();
site_ensure_schema($pdo);
if (function_exists('act_visit')) { try { act_visit($pdo, 'صفحه اصلی'); } catch (\Throwable $e) {} }   // گزارش بازدید (نسخه ۴۷)

// ── داده‌های سایت ────────────────────────────────────────────────────────────
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}
// لینک همکاری در فروش (?ref=CODE)
if (function_exists('biz_capture_ref')) { try { biz_capture_ref($pdo); } catch (\Throwable $e) {} }

$s      = site_settings($pdo);
$slides = site_get_slides($pdo, true);
$tiles  = site_get_feature_tiles($pdo, true);
$posts  = site_get_homepage_posts($pdo, 6);
$menu_pages = site_get_menu_pages($pdo);
$menu_posts = $pdo->query("SELECT id,title,slug,menu_label FROM aisite_posts WHERE is_published=1 AND show_in_menu=1 ORDER BY menu_sort,id")->fetchAll(PDO::FETCH_ASSOC);

// پلن‌ها از جداول SaaS
$plans = [];
try {
    if (function_exists('pc_ensure_schema')) pc_ensure_schema($pdo);
    $plans = function_exists('saas_get_plans') ? saas_get_plans($pdo, true) : $pdo->query("SELECT * FROM saas_plans WHERE is_active=1 ORDER BY price_toman")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* جدول هنوز نیست */ }

// تنظیمات پایه
$site_name  = $s['site_name']  ?? 'منشی هوشمند';
$site_desc  = $s['site_description'] ?? '';
$site_url   = rtrim(AICHAT_BASE_URL, '/') . '/';
$og_image   = $s['og_image']   ?? '';
$tw_handle  = $s['twitter_handle'] ?? '';
$ga_id      = $s['ga_id']      ?? '';
$robots     = $s['meta_robots'] ?? 'index, follow';
$gsc_code   = $s['site_verification_google'] ?? '';
$intro_on   = ($s['intro_enabled'] ?? '0') === '1';
$notice_on  = ($s['notice_bar_show'] ?? '0') === '1';
$logo_url   = $s['site_logo_url'] ?? '';
$favi_url   = $s['site_favicon_url'] ?? '';
$intro_img  = $s['intro_image_url'] ?? '';

$page_meta  = site_seo_tags([
    'title'     => $site_name . ' — ' . ($s['site_tagline'] ?? 'دستیار هوشمند'),
    'desc'      => $site_desc,
    'canonical' => $site_url,
    'og_image'  => $og_image,
    'site_name' => $site_name,
    'tw_handle' => $tw_handle,
    'robots'    => $robots,
    'gsc_code'  => $gsc_code,
]);

$schema_lb  = site_local_business_schema($s, $site_url);
$schema_web = site_schema_org('WebSite', [
    'name'            => $site_name,
    'url'             => $site_url,
    'description'     => $site_desc,
    'potentialAction' => ['@type'=>'SearchAction','target'=>$site_url.'?q={search_term_string}','query-input'=>'required name=search_term_string'],
]);

$home_style = ($s['home_style'] ?? 'cosmic') === 'classic' ? 'classic' : 'cosmic';
// نمایش از ریشه دامنه (root-index/index.php): طرح کلاسیک لینک‌های نسبی دارد → انتقال به آدرس برنامه
if ($home_style === 'classic' && defined('AICHAT_HOME_ROOT')) { header('Location: ' . $site_url, true, 302); exit; }
include __DIR__ . '/includes/home_' . $home_style . '.php';
