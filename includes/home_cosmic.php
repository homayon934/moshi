<?php
/**
 * صفحه اصلی «کیهانی» (نسخه ۴۰): منظومه شمسی و شبکه ستارگان متحرک (Canvas، بدون کتابخانه بیرونی)
 *  - بالا: منوهای اصلی سایت + «امکانات»
 *  - زیر صحنه (اختیاری): امکانات، مطالب، پلن‌ها
 *  - پایین: سه لوگو/نماد (تصویر + لینک یا کد نماد مثل اینماد) و پایین‌ترین بخش: متن کپی‌رایت
 * همه چیز از «مدیریت ← تنظیمات سایت ← صفحه اصلی» قابل تغییر است. متغیرها در index.php آماده می‌شوند.
 * همه لینک‌ها مطلق‌اند تا این صفحه از ریشه دامنه هم (با فایل root-index) قابل نمایش باشد.
 */
if (!defined('AICHAT_HOME')) { http_response_code(404); exit; }

$U = fn($p = '') => $site_url . ltrim((string)$p, '/');
$hero_title = trim((string)($s['home_title'] ?? ''));
$hero_sub   = trim((string)($s['home_subtitle'] ?? ''));
$btn1 = [trim((string)($s['home_btn1_text'] ?? '')), trim((string)($s['home_btn1_url'] ?? ''))];
$btn2 = [trim((string)($s['home_btn2_text'] ?? '')), trim((string)($s['home_btn2_url'] ?? ''))];
$sections_on = ($s['home_sections'] ?? '1') !== '0';
$copyright = trim((string)($s['footer_text'] ?? ''));
if ($copyright === '') $copyright = '© ' . (function_exists('rem_jdate') ? mb_substr(rem_jdate(date('Y-m-d')), 0, 4) : date('Y')) . ' ' . $site_name . ' — کلیه حقوق محفوظ است.';
// لینک نسبی تنظیمات → مطلق
$abs = function ($u) use ($U) {
    $u = trim((string)$u);
    if ($u === '' || $u[0] === '#' || preg_match('#^(https?:)?//#i', $u) || preg_match('#^(mailto|tel):#i', $u)) return $u;
    if (preg_match('#^javascript:#i', $u)) return '#';
    return $U($u);
};
// منوهای دلخواه مدیر (هر خط: عنوان | لینک)
$extra_menu = [];
foreach (preg_split('/\r?\n/', (string)($s['home_menu_extra'] ?? '')) as $ln) {
    $p = array_map('trim', explode('|', $ln, 2));
    if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') $extra_menu[] = [$p[0], $abs($p[1])];
}
// سه لوگو / نماد
$badges = [];
for ($i = 1; $i <= 3; $i++) {
    $code = trim((string)($s['badge_' . $i . '_code'] ?? ''));
    $img  = trim((string)($s['badge_' . $i . '_img'] ?? ''));
    if ($code === '' && $img === '') continue;
    $badges[] = ['code' => $code, 'img' => $img, 'url' => $abs($s['badge_' . $i . '_url'] ?? ''), 'alt' => trim((string)($s['badge_' . $i . '_alt'] ?? '')) ?: 'نماد ' . $i];
}
$feat_link = $sections_on && $tiles ? '#features' : '';
// سه منوی شیشه‌ای پایین صحنه (نسخه ۴۶)
$glass = [];
for ($i = 1; $i <= 3; $i++) {
    $gt = trim(mb_substr((string)($s['glass_' . $i . '_text'] ?? ''), 0, 60));
    if ($gt !== '') $glass[] = [$gt, $abs($s['glass_' . $i . '_url'] ?? '') ?: '#'];
}
// دستیار گفتگوی صفحه اصلی (نسخه ۴۱)
require_once __DIR__ . '/sitechat_lib.php';
$sch = sch_conf($s);
// پلن‌ها به تفکیک دسته (نسخه ۴۵)
$pgroups = function_exists('pc_group') ? pc_group($pdo, $plans) : ($plans ? [['cat' => ['id' => 0, 'slug' => 'all', 'title' => 'پلن‌ها', 'icon' => '', 'description' => ''], 'plans' => $plans]] : []);
$plan_link = fn($slug) => $sections_on ? '#plans-' . $slug : $U('plans.php?c=' . rawurlencode($slug));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#000003">
<?php echo $page_meta; ?>
<?php echo $schema_lb; ?>
<?php echo $schema_web; ?>
<?php if ($favi_url): ?><link rel="icon" href="<?php echo site_h($favi_url); ?>" type="image/png"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#000003;--bg2:#050a1f;--gold:#fbbf24;--cyan:#38bdf8;--text:#e8eaf6;--muted:#9fa8da;--dim:#7986cb;--card:rgba(255,255,255,.04);--line:rgba(255,255,255,.09);--accent:#2563eb;--accent2:#3b82f6;--r:16px}
html{scroll-behavior:smooth;background:var(--bg)}
body{background:var(--bg);color:var(--text);font-family:'Vazirmatn',Tahoma,sans-serif;line-height:1.7;overflow-x:hidden}
a{color:var(--accent2);text-decoration:none}a:hover{color:#fff}
img{max-width:100%;display:block}
.container{max-width:1200px;margin:0 auto;padding:0 20px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 22px;border-radius:12px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;border:0;transition:all .2s;white-space:nowrap}
.btn-o{background:rgba(255,255,255,.04);color:#e2e8f0;border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(6px)}
.btn-o:hover{background:rgba(255,255,255,.1);color:#fff}
.btn-p{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#1a1205;box-shadow:0 0 22px rgba(251,191,36,.35)}
.btn-p:hover{transform:translateY(-1px);box-shadow:0 0 30px rgba(251,191,36,.55);color:#000}

/* ── نوار اطلاعیه ── */
.notice{position:relative;z-index:60;background:linear-gradient(90deg,#b45309,#f59e0b);color:#fff;text-align:center;padding:8px 16px;font-size:13.5px;font-weight:600}
.notice a{color:#fff;text-decoration:underline}

/* ── هدر ── */
.hdr{position:fixed;top:0;left:0;right:0;z-index:50;transition:background .3s,box-shadow .3s,top .3s}
.hdr.has-notice{top:38px}
.hdr.sc{background:rgba(0,0,6,.78);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);box-shadow:0 1px 0 rgba(255,255,255,.06),0 10px 30px rgba(0,0,0,.4);top:0}
.nav{display:flex;align-items:center;gap:14px;height:70px}
.logo{display:flex;align-items:center;gap:10px;flex-shrink:0}
.logo img{height:38px;width:auto}
.logo b{font-size:19px;font-weight:800;background:linear-gradient(135deg,#fff 30%,var(--gold));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.menu{display:flex;align-items:center;gap:2px;list-style:none;margin-right:12px;flex:1;min-width:0;flex-wrap:nowrap}
.menu>li{position:relative}
.menu>li>a,.menu>li>button{display:flex;align-items:center;gap:4px;padding:8px 12px;border-radius:10px;font-size:14px;font-weight:500;color:#cbd5e1;background:none;border:0;font-family:inherit;cursor:pointer;white-space:nowrap}
.menu>li>a:hover,.menu>li>button:hover,.menu>li.open>button{color:#fff;background:rgba(255,255,255,.07)}
.menu .car{font-size:10px;opacity:.7;transition:transform .2s}.menu li.open .car{transform:rotate(180deg)}
.dd{position:absolute;top:calc(100% + 8px);right:0;min-width:260px;background:rgba(6,10,30,.96);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:8px;box-shadow:0 20px 50px rgba(0,0,0,.55);backdrop-filter:blur(14px);opacity:0;visibility:hidden;transform:translateY(6px);transition:all .18s}
.menu li.open .dd,.menu li.dd-h:hover .dd{opacity:1;visibility:visible;transform:none}
.dd a{display:flex;gap:10px;align-items:flex-start;padding:9px 10px;border-radius:10px;color:#e2e8f0;font-size:13.5px}
.dd a:hover{background:rgba(255,255,255,.07);color:#fff}
.dd a small{display:block;color:var(--dim);font-size:12px;line-height:1.6}
.dd .ic{flex:0 0 30px;height:30px;border-radius:9px;background:linear-gradient(135deg,rgba(56,189,248,.25),rgba(251,191,36,.2));display:flex;align-items:center;justify-content:center;font-size:15px;overflow:hidden}
.dd .ic img{width:100%;height:100%;object-fit:cover}
.menu .m-chat{color:#fde68a!important;border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.08)}
.menu .m-chat:hover{background:rgba(251,191,36,.18)!important;color:#fff!important}
.acts{display:flex;gap:8px;align-items:center;margin-right:auto;flex-shrink:0}
.burger{display:none;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.16);color:#fff;cursor:pointer;margin-right:auto}
.mob{position:fixed;inset:0;z-index:80;background:rgba(0,0,6,.6);backdrop-filter:blur(3px)}
.mob[hidden]{display:none}
.mob-in{position:absolute;top:0;right:0;bottom:0;width:min(330px,88vw);background:#04071a;border-left:1px solid rgba(255,255,255,.08);padding:64px 14px calc(20px + env(safe-area-inset-bottom));overflow-y:auto;display:flex;flex-direction:column;gap:2px;animation:mi .22s ease}
@keyframes mi{from{transform:translateX(100%)}to{transform:none}}
.mob-in a{display:block;padding:13px 12px;border-radius:12px;color:#e2e8f0;font-size:15px;border-bottom:1px solid rgba(255,255,255,.05)}
.mob-in a:hover{background:rgba(255,255,255,.06)}
.mob-in .grp{color:var(--gold);font-size:12.5px;font-weight:700;padding:14px 12px 4px}
.mob-in .sub{padding-right:24px;font-size:14px;color:#cbd5e1}
.mob-x{position:absolute;top:12px;left:12px;width:42px;height:42px;border-radius:50%;border:0;background:rgba(255,255,255,.08);color:#fff;font-size:18px;cursor:pointer}
.mob-act{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.mob-act .btn{justify-content:center;border-bottom:0;padding:13px}.mob-act .btn-p{color:#1a1205}.mob-act .btn-o{color:#fff}
@media(max-width:1060px){.menu>li>a,.menu>li>button{padding:8px 9px;font-size:13.5px}}
@media(max-width:900px){.menu,.acts{display:none}.burger{display:inline-flex}}

/* ── صحنه کیهانی ── */
.hero{position:relative;height:100vh;height:100svh;min-height:560px;overflow:hidden;background:radial-gradient(ellipse at 50% 60%,#07051a 0%,#000003 70%)}
#sky{position:absolute;inset:0;width:100%;height:100%;display:block}
.vig{position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at center,transparent 45%,rgba(0,0,3,.35) 75%,rgba(0,0,3,.9) 100%)}
.hero-tx{position:absolute;left:0;right:0;top:20%;z-index:5;text-align:center;padding:0 20px;pointer-events:none}
.hero-tx h1{font-size:clamp(26px,4.6vw,56px);font-weight:800;color:#fff;letter-spacing:.01em;line-height:1.35;text-shadow:0 0 20px rgba(255,255,255,.7),0 0 42px rgba(251,191,36,.4),0 0 80px rgba(56,189,248,.35)}
.hero-tx p{margin-top:12px;font-size:clamp(14px,1.8vw,19px);color:#cbd5e1;text-shadow:0 2px 12px rgba(0,0,0,.8)}
.hero-tx .ln{margin:18px auto 0;display:flex;align-items:center;justify-content:center;gap:10px;opacity:.85}
.hero-tx .ln i{display:block;width:90px;height:1px;background:linear-gradient(90deg,transparent,rgba(251,191,36,.85))}.hero-tx .ln i:last-child{transform:scaleX(-1)}
.hero-tx .ln b{width:6px;height:6px;border-radius:50%;background:var(--gold);box-shadow:0 0 12px #f59e0b}
.hero-chat{position:absolute;top:max(16.7%,128px);left:50%;transform:translate(-50%,-50%);z-index:6;width:33.33vw;min-width:320px;max-width:calc(100% - 32px);display:flex;align-items:center;gap:12px;padding:12px 14px 12px 12px;border-radius:999px;text-decoration:none;color:#fff;
  background:linear-gradient(135deg,rgba(15,18,48,.78),rgba(8,10,30,.78));border:1px solid rgba(251,191,36,.55);box-shadow:0 0 0 4px rgba(251,191,36,.08),0 10px 40px rgba(0,0,0,.55),0 0 36px rgba(251,191,36,.18);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);transition:box-shadow .25s,border-color .25s,transform .25s}
.hero-chat:hover,.hero-chat:focus-visible{border-color:#fbbf24;box-shadow:0 0 0 5px rgba(251,191,36,.16),0 12px 46px rgba(0,0,0,.6),0 0 52px rgba(251,191,36,.32);transform:translate(-50%,-50%) translateY(-2px);outline:none}
.hero-chat .hc-ic{flex:0 0 42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:19px;background:rgba(251,191,36,.12)}
.hero-chat .hc-tx{flex:1;font-size:clamp(15px,1.25vw,18px);font-weight:700;color:#fde68a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-shadow:0 0 14px rgba(251,191,36,.35)}
.hero-chat .hc-go{flex:0 0 40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#1a1205}
.hero.has-chat .hero-tx{top:30%}
.glass{position:absolute;left:0;right:0;bottom:78px;z-index:6;display:flex;justify-content:center;gap:16px;padding:0 16px;flex-wrap:wrap}
.glass a{flex:0 1 220px;min-width:150px;display:flex;align-items:center;justify-content:center;text-align:center;padding:14px 18px;border-radius:18px;color:#fff;font-size:15px;font-weight:700;text-decoration:none;line-height:1.6;
  background:linear-gradient(135deg,rgba(255,255,255,.14),rgba(255,255,255,.04));border:1px solid rgba(255,255,255,.22);box-shadow:inset 0 1px 0 rgba(255,255,255,.25),0 8px 30px rgba(0,0,0,.35);backdrop-filter:blur(14px) saturate(140%);-webkit-backdrop-filter:blur(14px) saturate(140%);transition:background .25s,border-color .25s,transform .25s}
.glass a:hover,.glass a:focus-visible{background:linear-gradient(135deg,rgba(255,255,255,.22),rgba(251,191,36,.08));border-color:rgba(251,191,36,.55);transform:translateY(-3px);outline:none}
@media(max-width:560px){.glass{bottom:74px;gap:8px}.glass a{flex:1 1 0;min-width:0;padding:11px 8px;font-size:13px;border-radius:14px}}
@media (prefers-reduced-motion:reduce){.glass a{transition:none}}
@media(max-width:560px){.hero-chat{top:max(20%,128px);min-width:0;width:calc(100% - 32px)}.hero.has-chat .hero-tx{top:34%}}
@media (prefers-reduced-motion:reduce){.hero-chat{transition:none}}
.hero-tx .bt{margin-top:22px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;pointer-events:auto}
.down{position:absolute;bottom:22px;left:50%;transform:translateX(-50%);z-index:5;width:40px;height:40px;border-radius:50%;border:1px solid rgba(125,211,252,.35);color:#7dd3fc;display:flex;align-items:center;justify-content:center;animation:bob 2.2s ease-in-out infinite;background:rgba(0,0,0,.25)}
@keyframes bob{50%{transform:translate(-50%,6px)}}

/* ── بخش‌ها ── */
.sec{padding:80px 0;position:relative}
.sec-h{text-align:center;margin-bottom:46px}
.sec-l{display:inline-block;border:1px solid rgba(251,191,36,.3);background:rgba(251,191,36,.08);color:#fde68a;padding:5px 16px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.06em;margin-bottom:14px}
.sec-t{font-size:clamp(24px,3.6vw,38px);font-weight:800;line-height:1.3;margin-bottom:10px;color:#fff}
.sec-s{font-size:15.5px;color:var(--muted);max-width:560px;margin:0 auto}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px}
.tile{background:linear-gradient(160deg,rgba(56,189,248,.06),rgba(255,255,255,.02));border:1px solid var(--line);border-radius:var(--r);overflow:hidden;transition:transform .3s,box-shadow .3s,border-color .3s;color:var(--text);display:block}
.tile:hover{transform:translateY(-5px);box-shadow:0 18px 50px rgba(0,0,0,.5);border-color:rgba(251,191,36,.35);color:var(--text)}
.tile img{width:100%;aspect-ratio:16/10;object-fit:cover}
.tile .ph{width:100%;aspect-ratio:16/10;display:flex;align-items:center;justify-content:center;font-size:44px;background:radial-gradient(circle at 30% 30%,rgba(251,191,36,.25),transparent 60%),radial-gradient(circle at 70% 70%,rgba(56,189,248,.25),transparent 60%),#070b22}
.tile .tb{padding:16px 18px}
.tile h3{font-size:16px;font-weight:700;margin-bottom:6px;color:#fff}
.tile p{font-size:13px;color:var(--muted);line-height:1.8}
.posts{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}
.post{background:var(--card);border:1px solid var(--line);border-radius:var(--r);overflow:hidden;transition:transform .3s}
.post:hover{transform:translateY(-4px)}
.post img{width:100%;height:190px;object-fit:cover}
.post .pb{padding:18px}
.post h3{font-size:16px;font-weight:700;margin-bottom:6px}.post h3 a{color:#fff}
.post p{font-size:13px;color:var(--muted);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.post .more{display:inline-block;margin-top:10px;font-size:13px;font-weight:700}
.plans{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:20px}
.plan{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:28px 24px;text-align:center;position:relative;transition:transform .3s}
.plan:hover{transform:translateY(-4px)}
.plan.pop{border-color:rgba(251,191,36,.55);background:linear-gradient(160deg,rgba(251,191,36,.09),rgba(56,189,248,.05))}
.plan .pbd{position:absolute;top:14px;left:14px;background:var(--gold);color:#1a1205;padding:3px 11px;border-radius:999px;font-size:11px;font-weight:800}
.plan-name{font-size:21px;font-weight:800;margin-bottom:4px;color:#fff}
.plan-desc{font-size:13px;color:var(--muted);margin-bottom:18px}
.plan-price{font-size:32px;font-weight:800;color:#fff;margin-bottom:4px}
.plan-price span{font-size:15px;font-weight:400;color:var(--muted)}
.plan ul{list-style:none;margin:18px 0;text-align:right}
.plan li{font-size:13px;color:#cbd5e1;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:8px;align-items:flex-start}
.plan li::before{content:'✦';color:var(--gold);flex-shrink:0}
.plan-feat-note{display:block;font-size:11.5px;color:var(--muted);opacity:.8;line-height:1.8}
.cta{padding:70px 0;text-align:center}
.cta-box{max-width:560px;margin:0 auto;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:36px 24px}
.cta-box h2{font-size:24px;font-weight:800;color:#fff;margin-bottom:6px}.cta-box p{color:var(--muted);margin-bottom:22px}
.cta-box .bt{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

/* ── نمادها و کپی‌رایت ── */
.foot{position:relative;z-index:2;border-top:1px solid var(--line);background:linear-gradient(180deg,rgba(5,10,31,.6),#000003)}
.foot-menu{display:flex;flex-wrap:wrap;gap:6px 18px;justify-content:center;padding:22px 20px 0;font-size:13px}
.foot-menu a{color:var(--muted)}.foot-menu a:hover{color:#fff}
.badges{display:flex;justify-content:center;align-items:center;gap:22px;flex-wrap:wrap;padding:26px 20px}
.badge{width:118px;min-height:118px;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:16px;padding:8px;box-shadow:0 8px 28px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.08);transition:transform .25s}
.badge:hover{transform:translateY(-3px)}
.badge img{max-width:100%;max-height:102px;width:auto;height:auto;object-fit:contain;cursor:pointer}
.badge.code{background:#fff}
.badge.code>*{max-width:100%}
.copy{border-top:1px solid rgba(255,255,255,.06);text-align:center;padding:16px 20px calc(16px + env(safe-area-inset-bottom));font-size:12.5px;color:var(--dim)}
@media(max-width:560px){.badge{width:96px;min-height:96px}.badge img{max-height:82px}.sec{padding:60px 0}}
@media (prefers-reduced-motion:reduce){.down{animation:none}.mob-in{animation:none}}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<?php if ($notice_on && ($s['notice_bar_text'] ?? '')): ?>
<div class="notice" id="notice"><?php echo site_h($s['notice_bar_text']); ?><?php if ($s['notice_bar_link'] ?? ''): ?>&nbsp;<a href="<?php echo site_h($abs($s['notice_bar_link'])); ?>">بیشتر بدانید ←</a><?php endif; ?></div>
<?php endif; ?>

<header class="hdr<?php echo $notice_on && ($s['notice_bar_text'] ?? '') ? ' has-notice' : ''; ?>" id="hdr">
  <div class="container">
    <nav class="nav" aria-label="منوی اصلی">
      <a class="logo" href="<?php echo site_h($site_url); ?>" aria-label="<?php echo site_h($site_name); ?>">
        <?php if ($logo_url): ?><img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>"><?php else: ?><b><?php echo site_h($site_name); ?></b><?php endif; ?>
      </a>
      <ul class="menu" id="menu">
        <li><a href="<?php echo site_h($site_url); ?>">خانه</a></li>
        <?php if ($tiles): ?>
        <li class="dd-h"><button type="button" aria-haspopup="true" aria-expanded="false">امکانات <span class="car">▼</span></button>
          <div class="dd" role="menu">
            <?php foreach ($tiles as $t): $tl = $t['link_url'] ? $abs($t['link_url']) : ($feat_link ?: '#'); ?>
            <a href="<?php echo site_h($tl); ?>" role="menuitem"><span class="ic"><?php if ($t['image_url']): ?><img src="<?php echo site_h($t['image_url']); ?>" alt="" loading="lazy"><?php else: ?>✦<?php endif; ?></span><span><?php echo site_h($t['title']); ?><?php if ($t['description']): ?><small><?php echo site_h(mb_strimwidth((string)$t['description'], 0, 70, '…')); ?></small><?php endif; ?></span></a>
            <?php endforeach; ?>
          </div>
        </li>
        <?php endif; ?>
        <?php foreach ($menu_pages as $mp): ?><li><a href="<?php echo site_h($U('page.php?slug=' . urlencode($mp['slug']))); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a></li><?php endforeach; ?>
        <?php foreach ($menu_posts as $mp): ?><li><a href="<?php echo site_h($U('post.php?slug=' . urlencode($mp['slug']))); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a></li><?php endforeach; ?>
        <?php if ($posts && $sections_on): ?><li><a href="#blog">مطالب</a></li><?php endif; ?>
        <?php if (count($pgroups) > 1): ?>
        <li class="dd-h"><button type="button" aria-haspopup="true" aria-expanded="false">پلن‌ها <span class="car">▼</span></button>
          <div class="dd" role="menu">
            <?php foreach ($pgroups as $g): $c = $g['cat']; ?>
            <a href="<?php echo site_h($plan_link($c['slug'])); ?>" role="menuitem"><span class="ic"><?php echo $c['icon'] !== '' ? site_h($c['icon']) : '✦'; ?></span><span><?php echo site_h($c['title']); ?><?php if ($c['description'] !== ''): ?><small><?php echo site_h(mb_strimwidth((string)$c['description'], 0, 70, '…')); ?></small><?php endif; ?></span></a>
            <?php endforeach; ?>
          </div>
        </li>
        <?php elseif ($plans): ?><li><a href="<?php echo $sections_on ? '#plans' : site_h($U('plans.php')); ?>">پلن‌ها</a></li><?php endif; ?>
        <?php foreach ($extra_menu as $em): ?><li><a href="<?php echo site_h($em[1]); ?>"><?php echo site_h($em[0]); ?></a></li><?php endforeach; ?>
      </ul>
      <div class="acts">
        <a class="btn btn-o" href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>">ورود</a>
        <a class="btn btn-p" href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>">ثبت‌نام رایگان</a>
      </div>
      <button type="button" class="burger" id="burger" aria-label="منو" aria-controls="mob" aria-expanded="false"><?php echo site_burger_svg(); ?></button>
    </nav>
  </div>
</header>

<div class="mob" id="mob" role="dialog" aria-modal="true" aria-label="منو" hidden>
  <div class="mob-in">
    <button type="button" class="mob-x" aria-label="بستن منو">✕</button>
    <a href="<?php echo site_h($site_url); ?>">خانه</a>
    <?php if ($sch['on']): ?><a href="#chat" data-sitechat>💬 <?php echo site_h($sch['label']); ?></a><?php endif; ?>
    <?php if ($tiles): ?><div class="grp">امکانات</div><?php foreach ($tiles as $t): ?><a class="sub" href="<?php echo site_h($t['link_url'] ? $abs($t['link_url']) : ($feat_link ?: '#')); ?>">✦ <?php echo site_h($t['title']); ?></a><?php endforeach; ?><?php endif; ?>
    <?php foreach ($menu_pages as $mp): ?><a href="<?php echo site_h($U('page.php?slug=' . urlencode($mp['slug']))); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a><?php endforeach; ?>
    <?php foreach ($menu_posts as $mp): ?><a href="<?php echo site_h($U('post.php?slug=' . urlencode($mp['slug']))); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a><?php endforeach; ?>
    <?php if ($posts && $sections_on): ?><a href="#blog">مطالب</a><?php endif; ?>
    <?php if (count($pgroups) > 1): ?><div class="grp">پلن‌ها</div><?php foreach ($pgroups as $g): ?><a class="sub" href="<?php echo site_h($plan_link($g['cat']['slug'])); ?>"><?php echo site_h(($g['cat']['icon'] !== '' ? $g['cat']['icon'] : '✦') . ' ' . $g['cat']['title']); ?></a><?php endforeach; ?>
    <?php elseif ($plans): ?><a href="<?php echo $sections_on ? '#plans' : site_h($U('plans.php')); ?>">پلن‌ها</a><?php endif; ?>
    <?php foreach ($extra_menu as $em): ?><a href="<?php echo site_h($em[1]); ?>"><?php echo site_h($em[0]); ?></a><?php endforeach; ?>
    <a href="<?php echo site_h($U('member/')); ?>">🙋 پنل مخاطبان</a>
    <div class="mob-act"><a class="btn btn-o" href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>">ورود</a><a class="btn btn-p" href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>">ثبت‌نام رایگان</a></div>
  </div>
</div>

<main>
<section class="hero<?php echo $sch['on'] ? ' has-chat' : ''; ?>" id="home" aria-label="<?php echo site_h($site_name); ?>">
  <canvas id="sky" aria-hidden="true"></canvas>
  <div class="vig"></div>
  <?php if ($sch['on']): ?>
  <a href="#chat" data-sitechat class="hero-chat" aria-label="<?php echo site_h($sch['label']); ?>">
    <span class="hc-ic" aria-hidden="true">💬</span>
    <span class="hc-tx"><?php echo site_h($sch['label']); ?></span>
    <span class="hc-go" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg></span>
  </a>
  <?php endif; ?>
  <?php if ($hero_title !== '' || $hero_sub !== '' || $btn1[0] !== '' || $btn2[0] !== ''): ?>
  <div class="hero-tx">
    <?php if ($hero_title !== ''): ?><h1><?php echo site_h($hero_title); ?></h1><?php else: ?><h1 style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)"><?php echo site_h($site_name); ?></h1><?php endif; ?>
    <?php if ($hero_sub !== ''): ?><p><?php echo site_h($hero_sub); ?></p><?php endif; ?>
    <?php if ($hero_title !== ''): ?><div class="ln"><i></i><b></b><i></i></div><?php endif; ?>
    <?php if (($btn1[0] !== '' && $btn1[1] !== '') || ($btn2[0] !== '' && $btn2[1] !== '')): ?>
    <div class="bt">
      <?php if ($btn1[0] !== '' && $btn1[1] !== ''): ?><a class="btn btn-p" href="<?php echo site_h($abs($btn1[1])); ?>"><?php echo site_h($btn1[0]); ?></a><?php endif; ?>
      <?php if ($btn2[0] !== '' && $btn2[1] !== ''): ?><a class="btn btn-o" href="<?php echo site_h($abs($btn2[1])); ?>"><?php echo site_h($btn2[0]); ?></a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <h1 style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)"><?php echo site_h($site_name); ?></h1>
  <?php endif; ?>
  <?php if ($glass): ?><nav class="glass" aria-label="دسترسی سریع"><?php foreach ($glass as $gl): ?><a href="<?php echo site_h($gl[1]); ?>"><?php echo site_h($gl[0]); ?></a><?php endforeach; ?></nav><?php endif; ?>
  <?php if ($sections_on && ($tiles || $posts || $plans)): ?><a class="down" href="<?php echo $tiles ? '#features' : ($posts ? '#blog' : '#plans'); ?>" aria-label="ادامه صفحه">⌄</a><?php endif; ?>
</section>

<?php if ($sections_on): ?>
<?php if ($tiles): ?>
<section class="sec" id="features">
  <div class="container">
    <div class="sec-h"><div class="sec-l">امکانات</div><h2 class="sec-t">هر آنچه برای کار هوشمند لازم دارید</h2></div>
    <div class="tiles">
      <?php foreach ($tiles as $t): ?>
      <a class="tile" href="<?php echo site_h($t['link_url'] ? $abs($t['link_url']) : '#features'); ?>">
        <?php if ($t['image_url']): ?><img src="<?php echo site_h($t['image_url']); ?>" alt="<?php echo site_h($t['title']); ?>" loading="lazy"><?php else: ?><div class="ph">✦</div><?php endif; ?>
        <div class="tb"><h3><?php echo site_h($t['title']); ?></h3><?php if ($t['description']): ?><p><?php echo site_h($t['description']); ?></p><?php endif; ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($posts): ?>
<section class="sec" id="blog">
  <div class="container">
    <div class="sec-h"><div class="sec-l">مطالب</div><h2 class="sec-t">آخرین مطالب</h2></div>
    <div class="posts">
      <?php foreach ($posts as $p): $pu = $U('post.php?slug=' . urlencode($p['slug'])); ?>
      <article class="post" itemscope itemtype="https://schema.org/BlogPosting">
        <?php if ($p['image_url']): ?><a href="<?php echo site_h($pu); ?>"><img src="<?php echo site_h($p['image_url']); ?>" alt="<?php echo site_h($p['title']); ?>" loading="lazy" itemprop="image"></a><?php endif; ?>
        <div class="pb">
          <h3 itemprop="headline"><a href="<?php echo site_h($pu); ?>"><?php echo site_h($p['title']); ?></a></h3>
          <?php if ($p['excerpt']): ?><p itemprop="description"><?php echo site_h($p['excerpt']); ?></p><?php endif; ?>
          <a class="more" href="<?php echo site_h($pu); ?>">ادامه مطلب ←</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($pgroups): ?>
<section class="sec" id="plans">
  <div class="container">
    <div class="sec-h"><div class="sec-l">اشتراک</div><h2 class="sec-t">پلن‌ها</h2><p class="sec-s">پلن مناسب کار خود را انتخاب کنید</p></div>
    <?php echo function_exists('pc_tabs_html') ? pc_tabs_html($pgroups, 'dark') : ''; ?>
    <?php foreach ($pgroups as $gi => $g): ?>
    <div data-pcg="<?php echo site_h($g['cat']['slug']); ?>"<?php echo $gi > 0 ? ' hidden' : ''; ?>>
      <?php if (count($pgroups) > 1 && $g['cat']['description'] !== ''): ?><p class="pcg-desc d"><?php echo site_h($g['cat']['description']); ?></p><?php endif; ?>
      <div class="plans">
          <?php foreach ($g['plans'] as $plan): ?>
          <div class="plan<?php echo !empty($plan['is_popular']) ? ' pop' : ''; ?>">
            <?php if (!empty($plan['is_popular'])): ?><div class="pbd">⭐ پرفروش</div><?php endif; ?>
            <?php $plan_head = function_exists('biz_plan_head_html') ? biz_plan_head_html($plan, 'dark') : ''; ?>
            <?php if ($plan_head !== ''): echo $plan_head; else: ?>
            <div class="plan-name"><?php echo site_h($plan['name']); ?></div>
            <?php if ($plan['description']): ?><div class="plan-desc"><?php echo site_h($plan['description']); ?></div><?php endif; ?>
            <?php endif; ?>
            <div class="plan-price"><?php if (function_exists('biz_price_html')): echo biz_price_html($plan, 'color:var(--muted)'); else: echo number_format((int)$plan['price_toman']); ?> <span>تومان</span><?php endif; ?></div>
            <ul>
              <?php foreach ((function_exists('ex_plan_feature_list_sorted') ? ex_plan_feature_list_sorted($plan) : saas_plan_feature_list($plan)) as $pf): ?>
              <li><span><?php echo site_h($pf['text']); ?><?php if ($pf['note'] !== ''): ?><span class="plan-feat-note"><?php echo site_h($pf['note']); ?></span><?php endif; ?></span></li>
              <?php endforeach; ?>
            </ul>
            <a class="btn btn-p" style="width:100%" href="<?php echo site_h($U('plan.php?id=' . (int)$plan['id'] . '#buy')); ?>"><?php echo (int)$plan['price_toman'] === 0 ? 'شروع رایگان' : 'خرید اشتراک'; ?></a>
            <a href="<?php echo site_h($U('plan.php?id=' . (int)$plan['id'])); ?>" style="display:block;margin-top:10px;font-size:13px">جزئیات پلن ←</a>
          </div>
          <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="cta">
  <div class="container"><div class="cta-box">
    <h2>همین الان شروع کنید</h2><p>ثبت‌نام رایگان است و چند دقیقه بیشتر طول نمی‌کشد.</p>
    <div class="bt"><a class="btn btn-p" href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>">ثبت‌نام رایگان</a><a class="btn btn-o" href="<?php echo site_h(AICHAT_USER_URL . 'login.php'); ?>">ورود به حساب</a></div>
  </div></div>
</section>
<?php endif; ?>
</main>

<footer class="foot" role="contentinfo">
  <nav class="foot-menu" aria-label="پیوندها">
    <?php foreach ($menu_pages as $mp): ?><a href="<?php echo site_h($U('page.php?slug=' . urlencode($mp['slug']))); ?>"><?php echo site_h($mp['menu_label'] ?: $mp['title']); ?></a><?php endforeach; ?>
    <?php foreach ($extra_menu as $em): ?><a href="<?php echo site_h($em[1]); ?>"><?php echo site_h($em[0]); ?></a><?php endforeach; ?>
    <a href="<?php echo site_h($U('member/')); ?>">پنل مخاطبان</a>
  </nav>
  <?php if ($badges): ?>
  <div class="badges" aria-label="نمادها">
    <?php foreach ($badges as $b): ?>
      <?php if ($b['code'] !== ''): ?>
      <div class="badge code"><?php echo $b['code']; /* کد نماد (مثل اینماد) که مدیر سایت وارد کرده — عمداً بدون تغییر */ ?></div>
      <?php elseif ($b['url'] !== ''): ?>
      <a class="badge" href="<?php echo site_h($b['url']); ?>" target="_blank" rel="noopener" referrerpolicy="origin"><img src="<?php echo site_h($b['img']); ?>" alt="<?php echo site_h($b['alt']); ?>" loading="lazy"></a>
      <?php else: ?>
      <div class="badge"><img src="<?php echo site_h($b['img']); ?>" alt="<?php echo site_h($b['alt']); ?>" loading="lazy"></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="copy"><?php echo site_h($copyright); ?></div>
</footer>

<?php if ($ga_id): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo site_h($ga_id); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo site_h($ga_id); ?>');</script>
<?php endif; ?>
<script>
(function(){
  // ── هدر و منو ──
  var hdr = document.getElementById('hdr');
  function onScroll(){ hdr.classList.toggle('sc', window.scrollY > 40); }
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  Array.prototype.forEach.call(document.querySelectorAll('.menu .dd-h > button'), function(b){
    var li = b.parentNode;
    b.addEventListener('click', function(e){ e.stopPropagation(); var o = !li.classList.contains('open'); li.classList.toggle('open', o); b.setAttribute('aria-expanded', o ? 'true' : 'false'); });
  });
  document.addEventListener('click', function(){ Array.prototype.forEach.call(document.querySelectorAll('.menu li.open'), function(li){ li.classList.remove('open'); }); });
  var bb = document.getElementById('burger'), mob = document.getElementById('mob');
  function setMob(o){ mob.hidden = !o; bb.setAttribute('aria-expanded', o ? 'true' : 'false'); document.body.style.overflow = o ? 'hidden' : ''; }
  bb.addEventListener('click', function(){ setMob(mob.hidden); });
  mob.addEventListener('click', function(e){ if (e.target === mob || e.target.closest('.mob-x') || e.target.closest('a')) setMob(false); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { if (!mob.hidden) setMob(false); Array.prototype.forEach.call(document.querySelectorAll('.menu li.open'), function(li){ li.classList.remove('open'); }); } });
  Array.prototype.forEach.call(document.querySelectorAll('a[href^="#"]'), function(a){
    a.addEventListener('click', function(e){ var id = a.getAttribute('href'); if (id.length < 2) return; var t = document.querySelector(id); if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
  });

  // ── صحنه کیهانی: خورشید، سیارات، شبکه ستارگان (Canvas 2D) ──
  var cv = document.getElementById('sky'); if (!cv || !cv.getContext) return;
  var ctx = cv.getContext('2d');
  var small = Math.min(window.innerWidth, window.innerHeight) < 700;
  var DPR = Math.min(window.devicePixelRatio || 1, small ? 1.5 : 2);
  var W = 0, H = 0, F = 1;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function rnd(a, b){ return a + Math.random() * (b - a); }
  function glow(r, g, b){
    var c = document.createElement('canvas'); c.width = c.height = 64; var x = c.getContext('2d');
    var gr = x.createRadialGradient(32, 32, 0, 32, 32, 32);
    gr.addColorStop(0, 'rgba(255,255,255,1)'); gr.addColorStop(.15, 'rgba(' + r + ',' + g + ',' + b + ',1)');
    gr.addColorStop(.45, 'rgba(' + r + ',' + g + ',' + b + ',.35)'); gr.addColorStop(.8, 'rgba(' + r + ',' + g + ',' + b + ',.08)'); gr.addColorStop(1, 'rgba(0,0,0,0)');
    x.fillStyle = gr; x.fillRect(0, 0, 64, 64); return c;
  }
  var G_BLUE = glow(160, 210, 255), G_GOLD = glow(255, 210, 140), G_WHITE = glow(245, 248, 255), G_RED = glow(255, 136, 85);
  var G_COR = glow(255, 150, 40), G_HALO = glow(255, 80, 20);
  // بافت سطح خورشید
  var SUN = document.createElement('canvas'); SUN.width = 512; SUN.height = 256;
  (function(){ var x = SUN.getContext('2d'); var g = x.createLinearGradient(0, 0, 0, 256);
    g.addColorStop(0, '#fff088'); g.addColorStop(.25, '#ff9900'); g.addColorStop(.65, '#ff4500'); g.addColorStop(1, '#bb1100'); x.fillStyle = g; x.fillRect(0, 0, 512, 256);
    for (var i = 0; i < 1800; i++) { x.beginPath(); x.arc(Math.random() * 512, Math.random() * 256, 1 + Math.random() * 6, 0, 6.283); var a = .05 + Math.random() * .22; x.fillStyle = Math.random() > .4 ? 'rgba(255,245,200,' + a + ')' : 'rgba(180,30,0,' + (a * .8) + ')'; x.fill(); }
  })();

  // داده‌های صحنه (مشابه طرح: خورشید، ۸ سیاره، ستاره‌های بازوی مارپیچ، خطوط شبکه)
  var PL = [[22, .85, '#9e9e9e', 1.4], [29, 1.35, '#e6b87d', 1.05], [38, 1.5, '#4da6ff', .82], [48, 1.1, '#d65333', .68], [62, 3.4, '#dcb886', .45], [78, 2.8, '#f5d799', .32, 1], [93, 2.1, '#7de3e6', .22], [107, 2.0, '#3d70f0', .16]]
    .map(function(p, i){ return { d: p[0], s: p[1], c: p[2], v: p[3], ring: !!p[4], a: i * 6.283 / 8 + Math.random() * .5 }; });
  var NS = small ? 380 : 650, ST = [];
  for (var i = 0; i < NS; i++) {
    var arm = i % 4, df = Math.pow(Math.random(), .75), dist = 26 + df * 135, th = arm * Math.PI / 2 + dist * .038 + (Math.random() - .5) * .75;
    var r = Math.random(), kind = r < .2 ? 0 : r < .65 ? 1 : r < .9 ? 2 : 3;
    ST.push({ x: Math.cos(th) * dist + rnd(-7, 7), y: (Math.random() - .5) * 26 * Math.exp(-dist / 110), z: Math.sin(th) * dist + rnd(-7, 7),
              k: kind, s: [2.2, 1.8, 2.0, 2.5][kind] * rnd(.65, 1.55), b: rnd(.4, 2), o: rnd(0, 6.283), al: rnd(.75, 1) });
  }
  var LN = [], LG = [];   // خطوط فیروزه‌ای / طلایی و خطوط از خورشید
  for (var i = 0; i < ST.length; i++) {
    if (i % 7 === 0) LG.push([-1, i]);
    for (var j = i + 1; j < Math.min(i + 28, ST.length); j++) {
      var dx = ST[i].x - ST[j].x, dy = ST[i].y - ST[j].y, dz = ST[i].z - ST[j].z;
      if (dx * dx + dy * dy + dz * dz < 22 * 22) ((i + j) % 3 === 0 ? LG : LN).push([i, j]);
    }
  }
  var NB = small ? 900 : 1800, BG = new Float32Array(NB * 4);
  for (var i = 0; i < NB; i++) { var rad = 200 + Math.random() * 500, th = Math.random() * 6.283, ph = Math.acos(2 * Math.random() - 1);
    BG[i * 4] = rad * Math.sin(ph) * Math.cos(th); BG[i * 4 + 1] = rad * Math.sin(ph) * Math.sin(th); BG[i * 4 + 2] = rad * Math.cos(ph); BG[i * 4 + 3] = Math.random(); }
  var ORB = PL.map(function(p){ var a = []; for (var k = 0; k <= 72; k++) { var t = k / 72 * 6.283; a.push([Math.cos(t) * p.d, 0, Math.sin(t) * p.d * .94]); } return a; });

  function resize(){
    var r = cv.getBoundingClientRect(); W = Math.max(1, r.width); H = Math.max(1, r.height);
    cv.width = Math.round(W * DPR); cv.height = Math.round(H * DPR); ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    F = (H / 2) / Math.tan(25 * Math.PI / 180);
    if (W < H) F *= Math.max(.55, W / H * 1.15);   // گوشی عمودی: کل منظومه دیده شود
  }
  window.addEventListener('resize', resize); resize();
  var mx = 0, my = 0, tx = 0, ty = 0;
  window.addEventListener('mousemove', function(e){ mx = (e.clientX - innerWidth / 2) * .0008; my = (e.clientY - innerHeight / 2) * .0008; }, { passive: true });

  // دوربین و تبدیل‌ها
  var cam = [0, 15, 145], R = [1, 0, 0], Uv = [0, 1, 0], Fv = [0, 0, -1], gy = 0, gx = 0, cy = 1, sy = 0, cx = 1, sx = 0;
  function look(){
    var f = [0 - cam[0], -15 - cam[1], 0 - cam[2]], l = Math.hypot(f[0], f[1], f[2]); f = [f[0] / l, f[1] / l, f[2] / l];
    // راست = f × بالا (0,1,0) = [-f.z, 0, f.x]
    var r = [-f[2], 0, f[0]]; l = Math.hypot(r[0], r[2]); r = [r[0] / l, 0, r[2] / l];
    var u = [r[1] * f[2] - r[2] * f[1], r[2] * f[0] - r[0] * f[2], r[0] * f[1] - r[1] * f[0]];
    R = r; Uv = u; Fv = f;
  }
  var P = { x: 0, y: 0, z: 0 };
  // نقطه داخل گروه چرخان (spaceGroup) → صفحه
  function proj(x, y, z, grp){
    if (grp) { var x1 = x * cy + z * sy, z1 = -x * sy + z * cy; var y2 = y * cx - z1 * sx, z2 = y * sx + z1 * cx; x = x1; y = y2 - 15; z = z2; }
    var dx = x - cam[0], dy = y - cam[1], dz = z - cam[2];
    var zc = dx * Fv[0] + dy * Fv[1] + dz * Fv[2];
    if (zc < .5) { P.z = -1; return P; }
    P.x = W / 2 + (dx * R[0] + dy * R[1] + dz * R[2]) * F / zc; P.y = H / 2 - (dx * Uv[0] + dy * Uv[1] + dz * Uv[2]) * F / zc; P.z = zc; return P;
  }
  var SP = new Float32Array(ST.length * 3), t0 = performance.now(), visible = true, raf = 0;
  if ('IntersectionObserver' in window) new IntersectionObserver(function(es){ visible = es[0].isIntersecting; if (visible && !raf) raf = requestAnimationFrame(frame); }).observe(cv);

  function frame(now){
    raf = 0;
    var t = (now - t0) / 1000 * (reduce ? .25 : 1);
    tx += (mx - tx) * .04; ty += (my - ty) * .04;
    cam[0] = Math.sin(t * .06) * 12 + tx * 60; cam[1] = 12 + Math.cos(t * .05) * 8 - ty * 40; cam[2] = 145; look();
    gy = t * .035; gx = Math.sin(t * .02) * .06; cy = Math.cos(gy); sy = Math.sin(gy); cx = Math.cos(gx); sx = Math.sin(gx);
    ctx.clearRect(0, 0, W, H);
    // ستاره‌های دور
    for (var i = 0; i < NB; i++) { var p = proj(BG[i * 4], BG[i * 4 + 1], BG[i * 4 + 2], false); if (p.z < 0 || p.x < 0 || p.y < 0 || p.x > W || p.y > H) continue;
      var b = BG[i * 4 + 3]; ctx.fillStyle = b > .8 ? 'rgba(160,200,255,' + (.35 + b * .5) + ')' : b > .45 ? 'rgba(255,220,150,' + (.3 + b * .5) + ')' : 'rgba(255,255,255,' + (.3 + b * .55) + ')'; var sz = b > .9 ? 1.6 : 1.1; ctx.fillRect(p.x, p.y, sz, sz); }
    ctx.globalCompositeOperation = 'lighter';
    // مدارها
    ctx.strokeStyle = 'rgba(56,189,248,.17)'; ctx.lineWidth = 1;
    for (var k = 0; k < ORB.length; k++) { ctx.beginPath(); var st = false; for (var q = 0; q < ORB[k].length; q++) { var o = ORB[k][q], p = proj(o[0], o[1], o[2], true); if (p.z < 0) { st = false; continue; } if (!st) { ctx.moveTo(p.x, p.y); st = true; } else ctx.lineTo(p.x, p.y); } ctx.stroke(); }
    // ستاره‌ها
    for (var i = 0; i < ST.length; i++) { var s = ST[i], p = proj(s.x, s.y, s.z, true); SP[i * 3] = p.x; SP[i * 3 + 1] = p.y; SP[i * 3 + 2] = p.z; }
    var sun = proj(0, 0, 0, true), sunX = sun.x, sunY = sun.y, sunZ = sun.z;
    var pulse = .35 + Math.sin(t * 2) * .15;
    ctx.lineWidth = .8;
    ctx.strokeStyle = 'rgba(56,189,248,' + (pulse * .9) + ')'; ctx.beginPath();
    for (var i = 0; i < LN.length; i++) { var a = LN[i][0] * 3, c = LN[i][1] * 3; if (SP[a + 2] < 0 || SP[c + 2] < 0) continue; ctx.moveTo(SP[a], SP[a + 1]); ctx.lineTo(SP[c], SP[c + 1]); } ctx.stroke();
    ctx.strokeStyle = 'rgba(251,191,36,' + (pulse * .75) + ')'; ctx.beginPath();
    for (var i = 0; i < LG.length; i++) { var a = LG[i][0], c = LG[i][1] * 3; if (SP[c + 2] < 0) continue; if (a < 0) { if (sunZ < 0) continue; ctx.moveTo(sunX, sunY); } else { a *= 3; if (SP[a + 2] < 0) continue; ctx.moveTo(SP[a], SP[a + 1]); } ctx.lineTo(SP[c], SP[c + 1]); } ctx.stroke();
    for (var i = 0; i < ST.length; i++) { var z = SP[i * 3 + 2]; if (z < 0) continue; var s = ST[i], tw = .75 + Math.sin(t * s.b * 3 + s.o) * .35, sz = s.s * tw * F / z * 1.6;
      if (sz < .6) sz = .6; ctx.globalAlpha = s.al; ctx.drawImage(s.k === 0 ? G_BLUE : s.k === 1 ? G_WHITE : s.k === 2 ? G_GOLD : G_RED, SP[i * 3] - sz / 2, SP[i * 3 + 1] - sz / 2, sz, sz); }
    ctx.globalAlpha = 1;
    // سیارات و خورشید (به ترتیب عمق)
    var items = [];
    for (var k = 0; k < PL.length; k++) { var pl = PL[k], an = pl.a + t * pl.v * .12, px = Math.cos(an) * pl.d, pz = Math.sin(an) * pl.d * .94, pyy = Math.sin(an * 2) * 2; var p = proj(px, pyy, pz, true); if (p.z > 0) items.push({ z: p.z, x: p.x, y: p.y, pl: pl }); }
    if (sunZ > 0) items.push({ z: sunZ, x: sunX, y: sunY, sun: 1 });
    items.sort(function(a, b){ return b.z - a.z; });
    for (var n = 0; n < items.length; n++) {
      var it = items[n], k2 = F / it.z;
      if (it.sun) {
        ctx.globalCompositeOperation = 'lighter';
        var hs = 130 * (1 + Math.sin(t * 1.5) * .08) * k2; ctx.globalAlpha = .45; ctx.drawImage(G_HALO, it.x - hs / 2, it.y - hs / 2, hs, hs);
        var cs = 75 * (1 + Math.sin(t * 2.2) * .05) * k2; ctx.globalAlpha = .88; ctx.drawImage(G_COR, it.x - cs / 2, it.y - cs / 2, cs, cs);
        ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over';
        var rr = 14.5 * k2;
        ctx.save(); ctx.beginPath(); ctx.arc(it.x, it.y, rr, 0, 6.283); ctx.clip();
        var off = (t * 0.015 % 1) * rr * 4; ctx.drawImage(SUN, it.x - rr - off, it.y - rr, rr * 4, rr * 2); ctx.drawImage(SUN, it.x - rr - off + rr * 4, it.y - rr, rr * 4, rr * 2);
        var sh = ctx.createRadialGradient(it.x - rr * .3, it.y - rr * .3, rr * .1, it.x, it.y, rr); sh.addColorStop(0, 'rgba(255,250,210,.55)'); sh.addColorStop(.7, 'rgba(255,140,0,0)'); sh.addColorStop(1, 'rgba(120,10,0,.45)');
        ctx.fillStyle = sh; ctx.fillRect(it.x - rr, it.y - rr, rr * 2, rr * 2); ctx.restore();
        ctx.globalCompositeOperation = 'lighter'; ctx.lineCap = 'round';
        // زبانه‌های خورشیدی دور لبه
        for (var q = 0; q < 4; q++) { ctx.strokeStyle = q % 2 ? 'rgba(255,204,51,' + (.32 - q * .05) + ')' : 'rgba(255,85,0,' + (.38 - q * .05) + ')'; ctx.lineWidth = (.35 + q * .12) * k2;
          var a0 = t * (q % 2 ? -.25 : .3) + q * 1.7, rq = (15.0 + q * .55) * k2; ctx.beginPath(); ctx.arc(it.x, it.y, rq, a0, a0 + .7 + q * .2); ctx.stroke(); }
        ctx.globalCompositeOperation = 'source-over';
      } else {
        var pl = it.pl, pr = Math.max(1.2, pl.s * k2);
        ctx.globalCompositeOperation = 'lighter'; ctx.globalAlpha = .45; var gs = pr * 3.6; ctx.drawImage(G_BLUE, it.x - gs, it.y - gs, gs * 2, gs * 2); ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over';
        if (pl.ring) { ctx.strokeStyle = 'rgba(217,194,142,.55)'; ctx.lineWidth = pr * .55; ctx.beginPath(); ctx.ellipse(it.x, it.y, pr * 1.9, pr * .55, -.25, Math.PI, 6.283); ctx.stroke(); }
        var lx = (sunX - it.x), ly = (sunY - it.y), ll = Math.hypot(lx, ly) || 1; lx /= ll; ly /= ll;
        var g = ctx.createRadialGradient(it.x + lx * pr * .45, it.y + ly * pr * .45, pr * .1, it.x, it.y, pr); g.addColorStop(0, '#ffffff'); g.addColorStop(.25, pl.c); g.addColorStop(1, 'rgba(0,0,0,.85)');
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(it.x, it.y, pr, 0, 6.283); ctx.fill();
        if (pl.ring) { ctx.strokeStyle = 'rgba(217,194,142,.7)'; ctx.lineWidth = pr * .55; ctx.beginPath(); ctx.ellipse(it.x, it.y, pr * 1.9, pr * .55, -.25, 0, Math.PI); ctx.stroke(); }
      }
    }
    ctx.globalCompositeOperation = 'source-over';
    if (visible && !document.hidden) raf = requestAnimationFrame(frame);
  }
  document.addEventListener('visibilitychange', function(){ if (!document.hidden && visible && !raf) raf = requestAnimationFrame(frame); });
  raf = requestAnimationFrame(frame);
})();
</script>
<?php if ($sch['on']) echo sch_home_html($pdo, $sch); ?>
<?php if (function_exists('ex_popups_for')) { try { echo ex_popup_script(ex_popups_for($pdo, 'site'), rtrim(AICHAT_BASE_URL, '/') . '/pp.php'); } catch (\Throwable $e) {} } ?>
</body>
</html>
