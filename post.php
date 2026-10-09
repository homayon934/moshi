<?php
/**
 * نمایش مطلب وبلاگ
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

$post = site_get_post_by_slug($pdo, $slug);
if ($post && function_exists('act_visit')) act_visit($pdo, 'مطلب: ' . ($post['title'] ?? $slug));   // گزارش بازدید (نسخه ۴۷)
if (!$post) { http_response_code(404); }

$site_name  = $s['site_name']  ?? 'منشی هوشمند';
$site_url   = rtrim(AICHAT_BASE_URL, '/') . '/';
$logo_url   = $s['site_logo_url'] ?? '';
$favi_url   = $s['site_favicon_url'] ?? '';
$og_image   = $s['og_image'] ?? '';
$menu_pages = site_get_menu_pages($pdo);
$menu_posts = $pdo->query("SELECT id,title,slug,menu_label FROM aisite_posts WHERE is_published=1 AND show_in_menu=1 ORDER BY menu_sort,id")->fetchAll(PDO::FETCH_ASSOC);

if ($post) {
    $pt  = $post['meta_title'] ?: ($post['title'] . ' — ' . $site_name);
    $pd  = $post['meta_desc']  ?: ($post['excerpt'] ?: '');
    $pk  = $post['meta_keywords'] ?? '';
    $poi = $post['image_url'] ?: $og_image;
    $can = $site_url . 'post.php?slug=' . urlencode($slug);
    $schema_bc = site_breadcrumb_schema([
        ['name'=>'خانه','url'=>$site_url],
        ['name'=>'مطالب','url'=>$site_url.'#blog'],
        ['name'=>$post['title'],'url'=>$can],
    ]);
    $schema_art = site_article_schema($post, $site_name, $can);
    $page_meta  = site_seo_tags([
        'title'     => $pt,
        'desc'      => $pd,
        'keywords'  => $pk,
        'canonical' => $can,
        'og_image'  => $poi,
        'og_type'   => 'article',
        'site_name' => $site_name,
        'robots'    => $s['meta_robots'] ?? 'index, follow',
        'gsc_code'  => $s['site_verification_google'] ?? '',
    ]);
} else {
    $page_meta = '<title>مطلب یافت نشد — ' . site_h($site_name) . '</title>';
    $schema_bc  = ''; $schema_art = '';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo $page_meta; ?>
<?php echo $schema_bc; ?>
<?php echo $schema_art; ?>
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
.site-header{background:rgba(0,9,34,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--card-border);position:sticky;top:0;z-index:50}
.nav-inner{display:flex;align-items:center;justify-content:space-between;height:64px;gap:16px}
.nav-logo img{height:36px}.nav-logo span{font-size:18px;font-weight:800;color:#fff}
.nav-menu{display:flex;align-items:center;gap:4px;list-style:none}
.nav-menu a{padding:7px 13px;border-radius:8px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);transition:all .2s}
.nav-menu a:hover{color:#fff;background:rgba(255,255,255,.07)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;font-family:inherit;transition:all .2s}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-2)}

/* هیرو مطلب */
.post-hero{position:relative;overflow:hidden}
.post-hero-img{width:100%;max-height:420px;object-fit:cover;display:block}
.post-hero-img-placeholder{height:220px;background:linear-gradient(135deg,var(--primary-2),var(--accent-2) 200%)}
.post-hero-overlay{position:absolute;bottom:0;left:0;right:0;padding:40px 0 30px;background:linear-gradient(0deg,rgba(0,9,34,1) 0%,transparent 100%)}
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.4);margin-bottom:16px;flex-wrap:wrap}
.breadcrumb a{color:var(--accent-2)}
.breadcrumb-sep{color:rgba(255,255,255,.2)}
.post-title{font-size:clamp(22px,4vw,40px);font-weight:800;line-height:1.25;color:#fff}
.post-meta{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:13px;color:var(--text-muted)}
.post-meta-item{display:flex;align-items:center;gap:4px}

/* محتوا */
.post-body{padding:48px 0 80px}
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

/* اشتراک‌گذاری */
.share-box{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);padding:20px 24px;margin-top:40px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.share-box span{font-size:14px;font-weight:600}
.share-btn{padding:6px 16px;border-radius:8px;font-size:13px;border:1px solid var(--card-border);background:transparent;color:var(--text);cursor:pointer;font-family:inherit;transition:all .2s}
.share-btn:hover{background:rgba(255,255,255,.07)}
/* نوت فاند */
.not-found{text-align:center;padding:80px 24px}
.not-found h2{font-size:32px;font-weight:800;margin-bottom:12px}
.not-found p{color:var(--text-muted);margin-bottom:24px}
/* فوتر */
.site-footer{border-top:1px solid var(--card-border);padding:24px;text-align:center;font-size:12px;color:rgba(255,255,255,.3)}
@media(max-width:640px){.nav-menu{display:none}.post-meta{gap:10px}}
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
      <li><a href="index.php#blog">مطالب</a></li>
    </ul>
    <a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary">ثبت‌نام</a>
    <?php echo site_mobile_burger(); ?>
  </nav>
</div>
</header>
<?php echo site_mobile_menu($pdo); ?>

<main itemscope itemtype="https://schema.org/BlogPosting">
<?php if ($post): ?>
<div class="post-hero">
  <?php if ($post['image_url']): ?>
  <img src="<?php echo site_h($post['image_url']); ?>" alt="<?php echo site_h($post['title']); ?>" class="post-hero-img" itemprop="image">
  <?php else: ?>
  <div class="post-hero-img-placeholder"></div>
  <?php endif; ?>
  <div class="post-hero-overlay">
    <div class="container">
      <nav class="breadcrumb" aria-label="مسیر">
        <a href="index.php">خانه</a>
        <span class="breadcrumb-sep">›</span>
        <a href="index.php#blog">مطالب</a>
        <span class="breadcrumb-sep">›</span>
        <span><?php echo site_h($post['title']); ?></span>
      </nav>
      <h1 class="post-title" itemprop="headline"><?php echo site_h($post['title']); ?></h1>
      <div class="post-meta">
        <div class="post-meta-item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
          <time datetime="<?php echo substr($post['created_at'],0,10); ?>" itemprop="datePublished">
            <?php echo substr($post['created_at'],0,10); ?>
          </time>
        </div>
        <meta itemprop="publisher" content="<?php echo site_h($site_name); ?>">
      </div>
    </div>
  </div>
</div>
<div class="post-body">
  <div class="container">
    <?php if ($post['excerpt']): ?>
    <p itemprop="description" style="font-size:18px;color:var(--text-muted);margin-bottom:32px;border-right:4px solid var(--accent-2);padding-right:16px;font-weight:500">
      <?php echo site_h($post['excerpt']); ?>
    </p>
    <?php endif; ?>
    <div class="prose" itemprop="articleBody">
      <?php echo $post['content']; ?>
    </div>

    <div class="share-box" aria-label="اشتراک‌گذاری">
      <span>اشتراک‌گذاری:</span>
      <button class="share-btn" onclick="sharePost('telegram')">تلگرام</button>
      <button class="share-btn" onclick="sharePost('whatsapp')">واتساپ</button>
      <button class="share-btn" onclick="copyLink()">کپی لینک</button>
    </div>

    <div style="margin-top:32px">
      <a href="index.php#blog" style="color:var(--accent-2);font-size:14px;font-weight:600">← بازگشت به لیست مطالب</a>
    </div>
  </div>
</div>
<?php else: ?>
<div class="not-found">
  <h2>مطلب یافت نشد</h2>
  <p>مطلبی که دنبال آن می‌گردید وجود ندارد یا منتشر نشده است.</p>
  <a href="index.php" class="btn btn-primary">بازگشت به خانه</a>
</div>
<?php endif; ?>
</main>

<footer class="site-footer">
  <?php echo site_h($s['footer_text'] ?? '© ' . date('Y') . ' ' . $site_name . '. کلیه حقوق محفوظ.'); ?>
</footer>

<script>
function sharePost(net){
  var url = encodeURIComponent(location.href);
  var title = encodeURIComponent(document.title);
  var link;
  if(net==='telegram')  link='https://t.me/share/url?url='+url+'&text='+title;
  if(net==='whatsapp')  link='https://api.whatsapp.com/send?text='+title+'%20'+url;
  if(link) window.open(link,'_blank','width=600,height=400');
}
function copyLink(){
  navigator.clipboard.writeText(location.href).then(function(){
    alert('لینک کپی شد!');
  }).catch(function(){ alert(location.href); });
}
</script>
<?php if (function_exists('ex_popups_for')) { try { echo ex_popup_script(ex_popups_for($pdo, 'site'), rtrim(AICHAT_BASE_URL, '/') . '/pp.php'); } catch (\Throwable $e) {} } ?>
</body>
</html>
