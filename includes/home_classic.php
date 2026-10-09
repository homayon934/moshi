<?php
/**
 * صفحه اصلی «کلاسیک» (طرح قبلی با اسلایدر) — از index.php وقتی در تنظیمات سایت «طرح صفحه اصلی: کلاسیک» انتخاب شده باشد
 * متغیرها در index.php آماده می‌شوند.
 */
if (!defined('AICHAT_HOME')) { http_response_code(404); exit; }
require_once __DIR__ . '/sitechat_lib.php';
$sch = sch_conf($s);
$pgroups = function_exists('pc_group') ? pc_group($pdo, $plans) : ($plans ? [['cat' => ['id' => 0, 'slug' => 'all', 'title' => 'پلن‌ها', 'icon' => '', 'description' => ''], 'plans' => $plans]] : []);   // نسخه ۴۵
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php echo $page_meta; ?>
<?php echo $schema_lb; ?>
<?php echo $schema_web; ?>
<?php if ($favi_url): ?>
<link rel="icon" href="<?php echo site_h($favi_url); ?>" type="image/png">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --primary:#000922;--primary-2:#0f2042;
  --accent:#0051d5;--accent-2:#316bf3;
  --text:#e8eaf6;--text-dim:#7986cb;--text-muted:#9fa8da;
  --card-bg:rgba(255,255,255,.04);--card-border:rgba(255,255,255,.08);
  --radius:16px;
}
html{scroll-behavior:smooth}
body{background:var(--primary);color:var(--text);font-family:'Vazirmatn',sans-serif;line-height:1.7;direction:rtl;overflow-x:hidden}
a{color:var(--accent-2);text-decoration:none}
a:hover{color:#fff}
img{max-width:100%;display:block}
.container{max-width:1200px;margin:0 auto;padding:0 24px}

/* ── نوار اطلاعیه ── */
.notice-bar{background:linear-gradient(90deg,var(--accent),var(--accent-2));text-align:center;padding:10px 20px;font-size:14px;font-weight:600;position:relative;z-index:200}
.notice-bar a{color:#fff;text-decoration:underline}

/* ── هدر / ناوبری ── */
.site-header{position:fixed;top:0;left:0;right:0;z-index:100;transition:background .3s,box-shadow .3s;padding:0}
.site-header.scrolled{background:rgba(0,9,34,.92);backdrop-filter:blur(12px);box-shadow:0 2px 24px rgba(0,0,0,.5)}
.nav-inner{display:flex;align-items:center;justify-content:space-between;height:72px;gap:16px}
.nav-logo img{height:40px;width:auto}
.nav-logo span{font-size:20px;font-weight:800;background:linear-gradient(135deg,#fff 0%,var(--accent-2) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.nav-menu{display:flex;align-items:center;gap:4px;list-style:none}
.nav-menu a{padding:8px 14px;border-radius:8px;font-size:14px;font-weight:500;color:var(--text-muted);transition:color .2s,background .2s}
.nav-menu a:hover{color:#fff;background:rgba(255,255,255,.07)}
.nav-actions{display:flex;gap:10px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:10px;font-size:14px;font-weight:600;font-family:inherit;cursor:pointer;border:none;transition:all .2s}
.btn-outline{background:transparent;color:var(--text-muted);border:1px solid rgba(255,255,255,.15)}
.btn-outline:hover{background:rgba(255,255,255,.07);color:#fff;border-color:rgba(255,255,255,.3)}
.btn-primary{background:var(--accent);color:#fff;box-shadow:0 0 20px rgba(0,81,213,.4)}
.btn-primary:hover{background:var(--accent-2);transform:translateY(-1px)}
.hamburger{display:none;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);color:#fff;cursor:pointer;padding:0;position:relative;z-index:101}
.no-msym .material-symbols-outlined{display:none!important}
.mobile-menu{display:none;position:fixed;inset:0;z-index:99;background:rgba(0,9,34,.97);padding:90px 24px 24px;flex-direction:column;gap:8px}
.mobile-menu.open{display:flex}
.mobile-menu a{padding:14px 16px;border-radius:10px;font-size:16px;color:var(--text);font-weight:500;border-bottom:1px solid rgba(255,255,255,.05)}

/* ── اینترو ── */
.intro-overlay{position:fixed;inset:0;z-index:500;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;text-align:center;background:var(--primary)}
.intro-overlay.has-img{background-size:cover;background-position:center}
.intro-overlay::before{content:'';position:absolute;inset:0;background:rgba(0,9,34,.7)}
.intro-content{position:relative;z-index:1;padding:24px}
.intro-logo{width:80px;height:80px;margin:0 auto 16px;background:linear-gradient(135deg,var(--accent),var(--accent-2));border-radius:20px;display:flex;align-items:center;justify-content:center}
.intro-logo span{font-size:40px;color:#fff}
.intro-title{font-size:clamp(20px,5vw,36px);font-weight:800;color:#fff;margin-bottom:8px}
.intro-subtitle{font-size:16px;color:var(--text-muted)}
.intro-progress{width:200px;height:3px;background:rgba(255,255,255,.15);border-radius:50px;overflow:hidden;margin-top:24px}
.intro-progress-bar{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-2));animation:intro-load 2.5s ease-in-out forwards}
@keyframes intro-load{0%{width:0}100%{width:100%}}

/* ── هیرو / اسلایدر ── */
.hero{position:relative;min-height:100vh;display:flex;align-items:center;overflow:hidden;padding-top:72px}
.slider{position:relative;width:100%;height:100vh;overflow:hidden}
.slide{position:absolute;inset:0;opacity:0;transition:opacity .8s ease;background-size:cover;background-position:center}
.slide.active{opacity:1;z-index:1}
.slide-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(0,9,34,.9) 0%,rgba(15,32,66,.7) 60%,transparent 100%)}
.slide-content{position:relative;z-index:2;height:100%;display:flex;align-items:center}
.slide-badge{display:inline-block;background:rgba(0,81,213,.3);border:1px solid rgba(49,107,243,.5);color:var(--accent-2);padding:6px 16px;border-radius:50px;font-size:13px;font-weight:600;margin-bottom:20px;backdrop-filter:blur(8px)}
.slide-title{font-size:clamp(28px,5vw,60px);font-weight:800;line-height:1.2;margin-bottom:16px;background:linear-gradient(135deg,#fff 0%,var(--accent-2) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.slide-sub{font-size:clamp(14px,2vw,20px);color:var(--text-muted);margin-bottom:12px;font-weight:500}
.slide-desc{font-size:15px;color:rgba(255,255,255,.6);margin-bottom:32px;max-width:480px}
.slide-btns{display:flex;gap:14px;flex-wrap:wrap}
.slider-dots{position:absolute;bottom:32px;right:50%;transform:translateX(50%);z-index:5;display:flex;gap:8px}
.slider-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.3);cursor:pointer;transition:all .3s}
.slider-dot.active{background:var(--accent-2);width:24px;border-radius:50px}
.slider-prev,.slider-next{position:absolute;top:50%;transform:translateY(-50%);z-index:5;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;width:44px;height:44px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .3s;font-size:20px;backdrop-filter:blur(4px)}
.slider-prev{right:20px}.slider-next{left:20px}
.slider-prev:hover,.slider-next:hover{background:var(--accent)}

/* ── تایل‌های ویژگی ── */
.features-tiles{padding:0 0 60px}
.tiles-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:-60px;position:relative;z-index:10}
.tile-card{background:var(--primary-2);border:1px solid var(--card-border);border-radius:var(--radius);overflow:hidden;transition:transform .3s,box-shadow .3s;cursor:pointer}
.tile-card:hover{transform:translateY(-6px);box-shadow:0 20px 60px rgba(0,0,0,.4)}
.tile-img{width:100%;aspect-ratio:1;object-fit:cover}
.tile-img-placeholder{width:100%;aspect-ratio:1;background:linear-gradient(135deg,var(--primary-2),var(--accent) 150%);display:flex;align-items:center;justify-content:center}
.tile-img-placeholder span{font-size:56px;color:rgba(255,255,255,.2)}
.tile-body{padding:20px}
.tile-title{font-size:16px;font-weight:700;margin-bottom:8px}
.tile-desc{font-size:13px;color:var(--text-muted);line-height:1.6}

/* ── بخش‌های عمومی ── */
.section{padding:80px 0}
.section-header{text-align:center;margin-bottom:56px}
.section-label{display:inline-block;background:rgba(0,81,213,.2);border:1px solid rgba(49,107,243,.3);color:var(--accent-2);padding:6px 18px;border-radius:50px;font-size:12px;font-weight:700;letter-spacing:.08em;margin-bottom:16px;text-transform:uppercase}
.section-title{font-size:clamp(24px,4vw,40px);font-weight:800;line-height:1.25;margin-bottom:12px}
.section-sub{font-size:16px;color:var(--text-muted);max-width:520px;margin:0 auto}

/* ── کارت‌های مطالب ── */
.posts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:24px}
.post-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);overflow:hidden;transition:transform .3s,box-shadow .3s}
.post-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,.35)}
.post-card img{width:100%;height:200px;object-fit:cover}
.post-card-body{padding:20px}
.post-card-title{font-size:16px;font-weight:700;margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.post-card-excerpt{font-size:13px;color:var(--text-muted);line-height:1.7;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.post-card-meta{font-size:11px;color:var(--text-dim);margin-top:12px;display:flex;gap:8px;align-items:center}
.post-card-link{display:inline-flex;align-items:center;gap:4px;margin-top:14px;font-size:13px;font-weight:600;color:var(--accent-2)}
.post-card-link:hover{color:#fff}

/* ── پلن‌ها ── */
.plans-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px}
.plan-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);padding:32px 28px;text-align:center;transition:transform .3s,box-shadow .3s;position:relative;overflow:hidden}
.plan-card:hover{transform:translateY(-4px);box-shadow:0 20px 60px rgba(0,0,0,.4)}
.plan-card.popular{border-color:var(--accent);background:linear-gradient(135deg,rgba(0,81,213,.12),rgba(49,107,243,.08))}
.plan-popular-badge{position:absolute;top:16px;left:16px;background:var(--accent);color:#fff;padding:4px 12px;border-radius:50px;font-size:11px;font-weight:700}
.plan-name{font-size:22px;font-weight:800;margin-bottom:4px}
.plan-desc{font-size:13px;color:var(--text-muted);margin-bottom:24px}
.plan-price{font-size:36px;font-weight:800;color:#fff;margin-bottom:4px}
.plan-price span{font-size:16px;font-weight:400;color:var(--text-muted)}
.plan-features{list-style:none;margin:20px 0;text-align:right}
.plan-features li{font-size:13px;color:var(--text-muted);padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;align-items:center;gap:8px}
.plan-features li::before{content:'✓';color:var(--accent-2);font-weight:700;flex-shrink:0}
.plan-features li{align-items:flex-start}
.plan-feat-note{display:block;font-size:11.5px;color:var(--text-muted);opacity:.75;line-height:1.8;margin-top:3px}

/* ── ثبت‌نام / ورود ── */
.auth-section{padding:80px 0;background:linear-gradient(135deg,rgba(0,81,213,.1) 0%,transparent 60%);border-top:1px solid var(--card-border)}
.auth-box{max-width:480px;margin:0 auto;background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);padding:40px;text-align:center}
.auth-box h2{font-size:24px;font-weight:800;margin-bottom:8px}
.auth-box p{font-size:15px;color:var(--text-muted);margin-bottom:28px}
.auth-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}

/* ── فوتر ── */
.site-footer{background:rgba(0,0,0,.3);border-top:1px solid var(--card-border);padding:40px 0 24px}
.footer-inner{display:grid;grid-template-columns:1fr 1fr 1fr;gap:32px;margin-bottom:32px}
.footer-logo{font-size:18px;font-weight:800;color:#fff;margin-bottom:12px}
.footer-logo img{height:36px;margin-bottom:12px}
.footer-desc{font-size:13px;color:var(--text-muted);line-height:1.8}
.footer-col h4{font-size:14px;font-weight:700;margin-bottom:16px;color:var(--text)}
.footer-col ul{list-style:none}
.footer-col ul li{margin-bottom:8px}
.footer-col ul li a{font-size:13px;color:var(--text-muted);transition:color .2s}
.footer-col ul li a:hover{color:var(--accent-2)}
.footer-bottom{border-top:1px solid var(--card-border);padding-top:20px;text-align:center;font-size:12px;color:var(--text-dim)}

/* ── ریسپانسیو ── */
@media(max-width:900px){
  .tiles-grid{grid-template-columns:repeat(3,1fr);gap:12px}
  .footer-inner{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
  .nav-menu,.nav-actions{display:none}
  .hamburger{display:flex}
  .tiles-grid{grid-template-columns:repeat(3,1fr);gap:8px}
  .tile-body{padding:12px}
  .tile-title{font-size:13px}
  .tile-desc{display:none}
  .footer-inner{grid-template-columns:1fr}
  .plans-grid{grid-template-columns:1fr}
}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>

<?php /* ── اینترو ── */
if ($intro_on): ?>
<div id="site-intro" class="intro-overlay<?php echo $intro_img ? ' has-img' : ''; ?>"
  <?php if ($intro_img): ?>style="background-image:url('<?php echo site_h($intro_img); ?>')"<?php endif; ?>>
  <div class="intro-content">
    <div class="intro-logo">
      <?php if ($logo_url): ?>
      <img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>" style="height:50px;width:auto">
      <?php else: ?><span style="font-size:44px;line-height:1">🤖</span><?php endif; ?>
    </div>
    <div class="intro-title"><?php echo site_h($s['intro_title'] ?? $site_name); ?></div>
    <div class="intro-subtitle"><?php echo site_h($s['intro_subtitle'] ?? ''); ?></div>
    <div class="intro-progress"><div class="intro-progress-bar"></div></div>
  </div>
</div>
<script>
  setTimeout(function(){
    var el = document.getElementById('site-intro');
    if(el){ el.style.transition='opacity .6s'; el.style.opacity=0; setTimeout(function(){ el.remove(); },650); }
  }, 2600);
</script>
<?php endif; ?>

<?php /* ── نوار اطلاعیه ── */
if ($notice_on && ($s['notice_bar_text'] ?? '')): ?>
<div class="notice-bar">
  <?php echo site_h($s['notice_bar_text']); ?>
  <?php if ($s['notice_bar_link'] ?? ''): ?>
  &nbsp;<a href="<?php echo site_h($s['notice_bar_link']); ?>">بیشتر بدانید ←</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── هدر ── -->
<header class="site-header" id="site-header">
<div class="container">
  <nav class="nav-inner" role="navigation" aria-label="منوی اصلی">
    <a href="<?php echo site_h($site_url); ?>" class="nav-logo" aria-label="<?php echo site_h($site_name); ?>">
      <?php if ($logo_url): ?>
      <img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>">
      <?php else: ?>
      <span><?php echo site_h($site_name); ?></span>
      <?php endif; ?>
    </a>
    <ul class="nav-menu" role="menubar">
      <li><a href="#home" role="menuitem">خانه</a></li>
      <?php if ($sch['on']): ?><li><a href="#chat" data-sitechat role="menuitem">💬 <?php echo site_h($sch['label']); ?></a></li><?php endif; ?>
      <li><a href="#features" role="menuitem">ویژگی‌ها</a></li>
      <?php foreach ($menu_pages as $mp): ?>
      <li><a href="page.php?slug=<?php echo urlencode($mp['slug']); ?>" role="menuitem">
        <?php echo site_h($mp['menu_label'] ?: $mp['title']); ?>
      </a></li>
      <?php endforeach; ?>
      <?php foreach ($menu_posts as $mp): ?>
      <li><a href="post.php?slug=<?php echo urlencode($mp['slug']); ?>" role="menuitem">
        <?php echo site_h($mp['menu_label'] ?: $mp['title']); ?>
      </a></li>
      <?php endforeach; ?>
      <?php if ($posts): ?><li><a href="#blog" role="menuitem">مطالب</a></li><?php endif; ?>
      <?php if ($plans): ?><li><a href="#plans" role="menuitem">پلن‌ها</a></li><?php endif; ?>
    </ul>
    <div class="nav-actions">
      <a href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>" class="btn btn-outline">ورود</a>
      <a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary"> ثبت‌نام رایگان</a>
    </div>
    <button class="hamburger" id="hamburger-btn" aria-label="منو" aria-controls="mobile-menu" aria-expanded="false">
      <?php echo site_burger_svg(); ?>
    </button>
  </nav>
</div>
</header>

<!-- ── منوی موبایل ── -->
<div class="mobile-menu" id="mobile-menu" role="dialog" aria-modal="true">
  <a href="#home">خانه</a>
  <?php if ($sch['on']): ?><a href="#chat" data-sitechat>💬 <?php echo site_h($sch['label']); ?></a><?php endif; ?>
  <a href="#features">ویژگی‌ها</a>
  <?php foreach ($menu_pages as $mp): ?>
  <a href="page.php?slug=<?php echo urlencode($mp['slug']); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a>
  <?php endforeach; ?>
  <?php foreach ($menu_posts as $mp): ?>
  <a href="post.php?slug=<?php echo urlencode($mp['slug']); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a>
  <?php endforeach; ?>
  <?php if ($posts): ?><a href="#blog">مطالب</a><?php endif; ?>
  <?php if ($plans): ?><a href="#plans">پلن‌ها</a><?php endif; ?>
  <div style="margin-top:20px;display:flex;flex-direction:column;gap:10px">
    <a href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>" class="btn btn-outline">ورود</a>
    <a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary">ثبت‌نام رایگان</a>
  </div>
</div>

<!-- ── اسلایدر ── -->
<section id="home" class="hero">
<?php if ($slides): ?>
<div class="slider" id="main-slider" aria-label="اسلایدشو اصلی" role="region">
  <?php foreach ($slides as $i => $sl): ?>
  <div class="slide <?php echo $i === 0 ? 'active' : ''; ?>"
    <?php if ($sl['image_url']): ?>style="background-image:url('<?php echo site_h($sl['image_url']); ?>')"<?php endif; ?>
    role="group" aria-label="اسلاید <?php echo $i+1; ?>">
    <div class="slide-overlay"></div>
    <div class="slide-content container">
      <div>
        <?php if ($sl['badge_text']): ?>
        <div class="slide-badge"><?php echo site_h($sl['badge_text']); ?></div>
        <?php endif; ?>
        <h1 class="slide-title"><?php echo site_h($sl['title']); ?></h1>
        <?php if ($sl['subtitle']): ?>
        <div class="slide-sub"><?php echo site_h($sl['subtitle']); ?></div>
        <?php endif; ?>
        <?php if ($sl['description']): ?>
        <div class="slide-desc"><?php echo site_h($sl['description']); ?></div>
        <?php endif; ?>
        <div class="slide-btns">
          <?php if ($sl['btn1_text'] && $sl['btn1_url']): ?>
          <a href="<?php echo site_h($sl['btn1_url']); ?>" class="btn btn-primary"><?php echo site_h($sl['btn1_text']); ?></a>
          <?php endif; ?>
          <?php if ($sl['btn2_text'] && $sl['btn2_url']): ?>
          <a href="<?php echo site_h($sl['btn2_url']); ?>" class="btn btn-outline"><?php echo site_h($sl['btn2_text']); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (count($slides) > 1): ?>
  <button class="slider-prev" onclick="sliderMove(-1)" aria-label="اسلاید قبلی"><span aria-hidden="true" style="font-size:26px;line-height:1">›</span></button>
  <button class="slider-next" onclick="sliderMove(1)"  aria-label="اسلاید بعدی"><span aria-hidden="true" style="font-size:26px;line-height:1">‹</span></button>
  <div class="slider-dots" role="tablist">
    <?php foreach ($slides as $i => $sl): ?>
    <button class="slider-dot <?php echo $i === 0 ? 'active' : ''; ?>" onclick="sliderGo(<?php echo $i; ?>)" role="tab" aria-label="اسلاید <?php echo $i+1; ?>"></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<!-- اسلاید پیش‌فرض -->
<div style="width:100%;height:100vh;display:flex;align-items:center;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-2) 100%)">
  <div class="container">
    <div class="slide-badge">هوش مصنوعی</div>
    <h1 class="slide-title"><?php echo site_h($site_name); ?></h1>
    <div class="slide-sub"><?php echo site_h($s['site_tagline'] ?? ''); ?></div>
    <div class="slide-desc"><?php echo site_h($site_desc); ?></div>
    <div class="slide-btns">
      <a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary">ثبت‌نام رایگان</a>
      <a href="#features" class="btn btn-outline">بیشتر بدانید</a>
    </div>
  </div>
</div>
<?php endif; ?>
</section>

<!-- ── تایل‌های ویژگی ── -->
<?php if ($tiles): ?>
<section id="features" class="features-tiles">
<div class="container">
  <div class="tiles-grid">
    <?php foreach ($tiles as $tile): ?>
    <a href="<?php echo site_h($tile['link_url'] ?: '#'); ?>" class="tile-card" style="text-decoration:none">
      <?php if ($tile['image_url']): ?>
      <img src="<?php echo site_h($tile['image_url']); ?>" alt="<?php echo site_h($tile['title']); ?>" class="tile-img" loading="lazy">
      <?php else: ?>
      <div class="tile-img-placeholder">
        <span class="material-symbols-outlined"><?php echo site_h($tile['icon'] ?: 'star'); ?></span>
      </div>
      <?php endif; ?>
      <div class="tile-body">
        <div class="tile-title"><?php echo site_h($tile['title']); ?></div>
        <div class="tile-desc"><?php echo site_h($tile['description']); ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
</section>
<?php endif; ?>

<!-- ── مطالب ── -->
<?php if ($posts): ?>
<section id="blog" class="section">
<div class="container">
  <div class="section-header">
    <div class="section-label">مطالب</div>
    <h2 class="section-title">آخرین مطالب</h2>
    <p class="section-sub">جدیدترین مقالات و اخبار را دنبال کنید</p>
  </div>
  <div class="posts-grid">
    <?php foreach ($posts as $p): ?>
    <article class="post-card" itemscope itemtype="https://schema.org/BlogPosting">
      <?php if ($p['image_url']): ?>
      <a href="post.php?slug=<?php echo urlencode($p['slug']); ?>">
        <img src="<?php echo site_h($p['image_url']); ?>" alt="<?php echo site_h($p['title']); ?>" loading="lazy" itemprop="image">
      </a>
      <?php endif; ?>
      <div class="post-card-body">
        <h3 class="post-card-title" itemprop="headline">
          <a href="post.php?slug=<?php echo urlencode($p['slug']); ?>" style="color:inherit"><?php echo site_h($p['title']); ?></a>
        </h3>
        <?php if ($p['excerpt']): ?>
        <p class="post-card-excerpt" itemprop="description"><?php echo site_h($p['excerpt']); ?></p>
        <?php endif; ?>
        <div class="post-card-meta">
          <span aria-hidden="true" style="font-size:13px">📅</span>
          <time itemprop="datePublished" datetime="<?php echo substr($p['created_at'],0,10); ?>"><?php echo substr($p['created_at'],0,10); ?></time>
        </div>
        <a href="post.php?slug=<?php echo urlencode($p['slug']); ?>" class="post-card-link">
          ادامه مطلب <span aria-hidden="true">←</span>
        </a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</div>
</section>
<?php endif; ?>

<!-- ── پلن‌ها ── -->
<?php if ($plans): ?>
<section id="plans" class="section" style="background:linear-gradient(180deg,rgba(0,81,213,.05) 0%,transparent 100%)">
<div class="container">
  <div class="section-header">
    <div class="section-label">قیمت‌گذاری</div>
    <h2 class="section-title">پلن‌های اشتراک</h2>
    <p class="section-sub">یک بار ثبت‌نام کنید، همیشه در دسترس داشته باشید</p>
  </div>
  <?php echo function_exists('pc_tabs_html') ? pc_tabs_html($pgroups, 'dark') : ''; ?>
  <?php foreach ($pgroups as $gi => $g): ?>
  <div data-pcg="<?php echo site_h($g['cat']['slug']); ?>"<?php echo $gi > 0 ? ' hidden' : ''; ?>>
  <?php if (count($pgroups) > 1 && $g['cat']['description'] !== ''): ?><p class="pcg-desc d"><?php echo site_h($g['cat']['description']); ?></p><?php endif; ?>
  <div class="plans-grid">
    <?php foreach ($g['plans'] as $plan): ?>
    <div class="plan-card <?php echo !empty($plan['is_popular']) ? 'popular' : ''; ?>">
      <?php if (!empty($plan['is_popular'])): ?>
      <div class="plan-popular-badge">⭐ پرفروش</div>
      <?php endif; ?>
      <?php $plan_head = function_exists('biz_plan_head_html') ? biz_plan_head_html($plan, 'dark') : ''; ?>
      <?php if ($plan_head !== ''): echo $plan_head; else: ?>
      <div class="plan-name"><?php echo site_h($plan['name']); ?></div>
      <?php if ($plan['description']): ?>
      <div class="plan-desc"><?php echo site_h($plan['description']); ?></div>
      <?php endif; ?>
      <?php endif; ?>
      <div class="plan-price">
        <?php if (function_exists('biz_price_html')): echo biz_price_html($plan, 'color:var(--text-muted)'); ?>
        <?php else: echo number_format((int)$plan['price_toman']); ?> <span>تومان</span><?php endif; ?>
        <div style="font-size:12px;font-weight:400;color:var(--text-muted);margin-top:2px">اشتراک یک‌ساله</div>
      </div>
      <ul class="plan-features">
        <?php foreach ((function_exists('ex_plan_feature_list_sorted') ? ex_plan_feature_list_sorted($plan) : saas_plan_feature_list($plan)) as $pf): ?>
        <li><span><?php echo site_h($pf['text']); ?><?php if ($pf['note'] !== ''): ?><span class="plan-feat-note"><?php echo site_h($pf['note']); ?></span><?php endif; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <a href="plan.php?id=<?php echo (int)$plan['id']; ?>#buy" class="btn btn-primary" style="width:100%;justify-content:center">
        <?php echo (int)$plan['price_toman'] === 0 ? 'شروع رایگان' : 'خرید اشتراک'; ?>
      </a>
      <a href="plan.php?id=<?php echo (int)$plan['id']; ?>" style="display:block;text-align:center;margin-top:10px;font-size:13px">مشاهده جزئیات پلن ←</a>
    </div>
    <?php endforeach; ?>
  </div>
  </div>
  <?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<!-- ── ثبت‌نام / ورود ── -->
<section class="auth-section">
<div class="container">
  <div class="auth-box">
    <h2>همین الان شروع کنید</h2>
    <p>ثبت‌نام رایگان — نیازی به کارت اعتباری نیست</p>
    <div class="auth-btns">
      <a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary">
        
        ثبت‌نام رایگان
      </a>
      <a href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>" class="btn btn-outline">ورود به حساب</a>
    </div>
  </div>
</div>
</section>

<!-- ── فوتر ── -->
<footer class="site-footer" role="contentinfo">
<div class="container">
  <div class="footer-inner">
    <div>
      <?php if ($logo_url): ?>
      <img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>" class="footer-logo">
      <?php else: ?>
      <div class="footer-logo"><?php echo site_h($site_name); ?></div>
      <?php endif; ?>
      <p class="footer-desc"><?php echo site_h($site_desc ?: $s['site_tagline'] ?? ''); ?></p>
      <?php if ($s['business_phone'] ?? ''): ?>
      <p style="font-size:13px;color:var(--text-dim);margin-top:12px;direction:ltr">
        <a href="tel:<?php echo site_h($s['business_phone']); ?>" style="color:var(--text-muted)">
          📞 <?php echo site_h($s['business_phone']); ?>
        </a>
      </p>
      <?php endif; ?>
    </div>
    <div class="footer-col">
      <h4>دسترسی سریع</h4>
      <ul>
        <li><a href="#home">خانه</a></li>
        <li><a href="#features">ویژگی‌ها</a></li>
        <?php foreach ($menu_pages as $mp): ?>
        <li><a href="page.php?slug=<?php echo urlencode($mp['slug']); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a></li>
        <?php endforeach; ?>
        <?php if ($plans): ?><li><a href="#plans">پلن‌ها</a></li><?php endif; ?>
        <li><a href="affiliate/">همکاری در فروش</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>حساب کاربری</h4>
      <ul>
        <li><a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>">ثبت‌نام رایگان</a></li>
        <li><a href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>">ورود به حساب</a></li>
        <?php foreach ($menu_posts as $mp): ?>
        <li><a href="post.php?slug=<?php echo urlencode($mp['slug']); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <?php echo site_h($s['footer_text'] ?? '© ' . date('Y') . ' ' . $site_name . '. کلیه حقوق محفوظ.'); ?>
  </div>
</div>
</footer>

<?php if ($ga_id): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo site_h($ga_id); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo site_h($ga_id); ?>');</script>
<?php endif; ?>

<script>
(function(){
  // ── اسکرول هدر ──
  var hdr = document.getElementById('site-header');
  window.addEventListener('scroll',function(){ hdr.classList.toggle('scrolled',window.scrollY>60); },{passive:true});

  // ── منوی موبایل ──
  var btn = document.getElementById('hamburger-btn');
  var mob = document.getElementById('mobile-menu');
  var burgerSvg = btn.innerHTML, closeSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  function setMob(open){
    mob.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'بستن منو' : 'منو');
    btn.innerHTML = open ? closeSvg : burgerSvg;
    document.body.style.overflow = open ? 'hidden' : '';
  }
  btn.addEventListener('click',function(){ setMob(!mob.classList.contains('open')); });
  mob.addEventListener('click',function(e){ if(e.target===mob) setMob(false); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape' && mob.classList.contains('open')) setMob(false); });
  document.querySelectorAll('.mobile-menu a').forEach(function(a){ a.addEventListener('click',function(){ setMob(false); }); });
  // اگر فونت آیکون‌ها (سرور گوگل) بارگذاری نشد، نام انگلیسی آیکون‌ها نمایش داده نشود
  function iconFallback(){ try { if (document.fonts && !document.fonts.check('24px "Material Symbols Outlined"')) document.documentElement.classList.add('no-msym'); } catch(e){} }
  setTimeout(iconFallback, 3500);

  // ── اسلایدر ──
  var slides = document.querySelectorAll('.slide');
  var dots   = document.querySelectorAll('.slider-dot');
  var cur    = 0, timer;
  function goTo(n){
    slides[cur].classList.remove('active'); dots[cur]?.classList.remove('active');
    cur = (n + slides.length) % slides.length;
    slides[cur].classList.add('active'); dots[cur]?.classList.add('active');
  }
  window.sliderGo  = function(n){ goTo(n); resetTimer(); };
  window.sliderMove= function(d){ goTo(cur+d); resetTimer(); };
  function resetTimer(){ clearInterval(timer); if(slides.length>1) timer=setInterval(function(){ goTo(cur+1); },5000); }
  resetTimer();

  // ── Smooth scroll ──
  document.querySelectorAll('a[href^="#"]').forEach(function(a){
    a.addEventListener('click',function(e){
      var t = document.querySelector(a.getAttribute('href'));
      if(t){ e.preventDefault(); t.scrollIntoView({behavior:'smooth',block:'start'}); }
    });
  });
})();
</script>
<?php if ($sch['on']) echo sch_home_html($pdo, $sch); ?>
<?php if (function_exists('ex_popups_for')) { try { echo ex_popup_script(ex_popups_for($pdo, 'site'), rtrim(AICHAT_BASE_URL, '/') . '/pp.php'); } catch (\Throwable $e) {} } ?>
</body>
</html>
