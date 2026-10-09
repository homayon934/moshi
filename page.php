<?php
/**
 * نمایش صفحات ثابت عمومی
 */
require_once __DIR__ . '/includes/sec_lib.php';
sec_boot('public', false);   // امنیت: WAF، محدودیت درخواست، هدرها
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_lib.php';
require_once __DIR__ . '/includes/saas_lib.php';   // پاپ‌آپ سایت

site_security_headers();

$pdo  = aichat_connect();
site_ensure_schema($pdo);
$s    = site_settings($pdo);

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: index.php'); exit; }

$page = site_get_page_by_slug($pdo, $slug);
if ($page && function_exists('act_visit')) act_visit($pdo, 'صفحه: ' . ($page['title'] ?? $slug));   // گزارش بازدید (نسخه ۴۷)
if (!$page) { http_response_code(404); }

$site_name  = $s['site_name']  ?? 'منشی هوشمند';
$site_url   = rtrim(AICHAT_BASE_URL, '/') . '/';
$logo_url   = $s['site_logo_url'] ?? '';
$favi_url   = $s['site_favicon_url'] ?? '';
$og_image   = $s['og_image'] ?? '';
$menu_pages = site_get_menu_pages($pdo);
$menu_posts = $pdo->query("SELECT id,title,slug,menu_label FROM aisite_posts WHERE is_published=1 AND show_in_menu=1 ORDER BY menu_sort,id")->fetchAll(PDO::FETCH_ASSOC);

if ($page) {
    $pt = $page['meta_title'] ?: ($page['title'] . ' — ' . $site_name);
    $pd = $page['meta_desc']  ?: '';
    $pk = $page['meta_keywords'] ?? '';
    $can = $site_url . 'page.php?slug=' . urlencode($slug);
    $schema_bc = site_breadcrumb_schema([
        ['name'=>'خانه','url'=>$site_url],
        ['name'=>$page['title'],'url'=>$can],
    ]);
    $page_meta = site_seo_tags([
        'title'     => $pt,
        'desc'      => $pd,
        'keywords'  => $pk,
        'canonical' => $can,
        'og_image'  => $og_image,
        'og_type'   => 'article',
        'site_name' => $site_name,
        'robots'    => $s['meta_robots'] ?? 'index, follow',
        'gsc_code'  => $s['site_verification_google'] ?? '',
    ]);
} else {
    $page_meta = '<title>صفحه یافت نشد — ' . site_h($site_name) . '</title>';
    $schema_bc = '';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo $page_meta; ?>
<?php echo $schema_bc ?? ''; ?>
<?php if ($favi_url): ?><link rel="icon" href="<?php echo site_h($favi_url); ?>" type="image/png"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--primary:#000922;--primary-2:#0f2042;--accent:#0051d5;--accent-2:#316bf3;--text:#e8eaf6;--text-muted:#9fa8da;--card-bg:rgba(255,255,255,.04);--card-border:rgba(255,255,255,.08);--radius:16px}
body{background:var(--primary);color:var(--text);font-family:'Vazirmatn',sans-serif;line-height:1.8;direction:rtl}
a{color:var(--accent-2);text-decoration:none}
.container{max-width:860px;margin:0 auto;padding:0 24px}
.site-header{background:rgba(0,9,34,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--card-border);padding:0;position:sticky;top:0;z-index:50}
.nav-inner{display:flex;align-items:center;justify-content:space-between;height:64px;gap:16px}
.nav-logo img{height:36px}.nav-logo span{font-size:18px;font-weight:800;color:#fff}
.nav-menu{display:flex;align-items:center;gap:4px;list-style:none}
.nav-menu a{padding:7px 13px;border-radius:8px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);transition:color .2s,background .2s}
.nav-menu a:hover{color:#fff;background:rgba(255,255,255,.07)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;font-family:inherit;transition:all .2s}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-2)}

/* صفحه */
.page-hero{padding:60px 0 40px;border-bottom:1px solid var(--card-border)}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.4);margin-bottom:20px;flex-wrap:wrap}
.breadcrumb a{color:var(--accent-2)}
.breadcrumb-sep{color:rgba(255,255,255,.2)}
.page-title{font-size:clamp(24px,4vw,42px);font-weight:800;line-height:1.25;margin-bottom:0}
.page-body{padding:48px 0 80px}
/* محتوای Quill */
.prose{font-size:16px;line-height:1.9;color:rgba(232,234,246,.9)}
.prose h1,.prose h2,.prose h3,.prose h4{font-weight:700;margin:36px 0 16px;color:#fff;line-height:1.3}
.prose h2{font-size:24px;border-bottom:1px solid var(--card-border);padding-bottom:8px}
.prose h3{font-size:20px}
.prose p{margin-bottom:18px}
.prose ul,.prose ol{margin:0 20px 18px;padding:0}
.prose li{margin-bottom:8px}
.prose blockquote{border-right:4px solid var(--accent-2);padding:12px 20px;margin:24px 0;background:rgba(49,107,243,.08);border-radius:0 8px 8px 0;color:var(--text-muted);font-style:italic}
.prose a{color:var(--accent-2);text-decoration:underline}
.prose code{background:rgba(255,255,255,.08);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:14px}
.prose pre{background:rgba(0,0,0,.4);padding:20px;border-radius:12px;overflow-x:auto;margin-bottom:18px}
.prose img{border-radius:12px;margin:20px 0;max-width:100%}
.prose table{width:100%;border-collapse:collapse;margin-bottom:18px}
.prose th,.prose td{border:1px solid var(--card-border);padding:10px 14px;font-size:14px;text-align:right}
.prose th{background:rgba(255,255,255,.06);font-weight:700}
/* نوت فاند */
.not-found{text-align:center;padding:80px 24px}
.not-found h2{font-size:32px;font-weight:800;margin-bottom:12px}
.not-found p{color:var(--text-muted);margin-bottom:24px}
/* فوتر */
.site-footer{border-top:1px solid var(--card-border);padding:24px;text-align:center;font-size:12px;color:rgba(255,255,255,.3)}
@media(max-width:640px){.nav-menu{display:none}}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<header class="site-header">
<div class="container" style="max-width:1200px">
  <nav class="nav-inner">
    <a href="index.php" class="nav-logo">
      <?php if ($logo_url): ?>
      <img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>">
      <?php else: ?><span><?php echo site_h($site_name); ?></span><?php endif; ?>
    </a>
    <ul class="nav-menu">
      <li><a href="index.php">خانه</a></li>
      <?php foreach ($menu_pages as $mp): ?>
      <li><a href="page.php?slug=<?php echo urlencode($mp['slug']); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a></li>
      <?php endforeach; ?>
      <?php foreach ($menu_posts as $mp): ?>
      <li><a href="post.php?slug=<?php echo urlencode($mp['slug']); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a></li>
      <?php endforeach; ?>
    </ul>
    <a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary">ثبت‌نام</a>
    <?php echo site_mobile_burger(); ?>
  </nav>
</div>
</header>
<?php echo site_mobile_menu($pdo); ?>

<main>
<?php if ($page): ?>
<div class="page-hero">
  <div class="container">
    <nav class="breadcrumb" aria-label="مسیر">
      <a href="index.php">خانه</a>
      <span class="breadcrumb-sep">›</span>
      <span><?php echo site_h($page['title']); ?></span>
    </nav>
    <h1 class="page-title"><?php echo site_h($page['title']); ?></h1>
  </div>
</div>
<div class="page-body">
  <div class="container">
    <div class="prose">
      <?php
      // محتوای HTML ذخیره‌شده توسط Quill — برای نمایش مستقیم است
      // XSS: محتوا توسط ادمین وارد می‌شود و مستقیم نمایش داده می‌شود
      echo $page['content'];
      ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="not-found">
  <h2>صفحه یافت نشد</h2>
  <p>صفحه‌ای که دنبال آن می‌گردید وجود ندارد یا حذف شده است.</p>
  <a href="index.php" class="btn btn-primary">بازگشت به خانه</a>
</div>
<?php endif; ?>
</main>

<footer class="site-footer">
  <?php echo site_h($s['footer_text'] ?? '© ' . date('Y') . ' ' . $site_name . '. کلیه حقوق محفوظ.'); ?>
</footer>
<?php if (function_exists('ex_popups_for')) { try { echo ex_popup_script(ex_popups_for($pdo, 'site'), rtrim(AICHAT_BASE_URL, '/') . '/pp.php'); } catch (\Throwable $e) {} } ?>
</body>
</html>
