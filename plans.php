<?php
/**
 * صفحه همه پلن‌ها به تفکیک دسته (نسخه ۴۵)
 * آدرس: plans.php      — همه دسته‌ها (زبانه)
 *       plans.php?c=doctor — باز شدن مستقیم زبانه یک دسته (برای لینک در منو، تبلیغ و شبکه‌های اجتماعی)
 */
require_once __DIR__ . '/includes/sec_lib.php'; sec_boot('public');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_lib.php';
require_once __DIR__ . '/includes/saas_lib.php';

site_security_headers();
$pdo = aichat_connect();
site_ensure_schema($pdo);
try { saas_ensure_schema($pdo); pc_ensure_schema($pdo); } catch (\Throwable $e) {}
$s = site_settings($pdo);
if (function_exists('act_visit')) act_visit($pdo, 'همه پلن‌ها' . (!empty($_GET['c']) ? ' (' . mb_substr((string)$_GET['c'], 0, 30) . ')' : ''));

$groups = pc_group($pdo, saas_get_plans($pdo, true));
$sel = trim((string)($_GET['c'] ?? ''));
$cur = null;
foreach ($groups as $g) if ($g['cat']['slug'] === $sel) $cur = $g['cat'];

$site_name = $s['site_name'] ?? 'منشی هوشمند';
$site_url  = rtrim(AICHAT_BASE_URL, '/') . '/';
$logo_url  = $s['site_logo_url'] ?? '';
$favi_url  = $s['site_favicon_url'] ?? '';
$title     = ($cur ? 'پلن‌های ' . $cur['title'] : 'پلن‌ها و قیمت‌ها') . ' — ' . $site_name;
$desc      = $cur ? (string)$cur['description'] : 'پلن‌های اشتراک ' . $site_name . ' به تفکیک کاربرد: ' . implode('، ', array_map(fn($g) => $g['cat']['title'], $groups));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo site_h($title); ?></title>
<meta name="description" content="<?php echo site_h(mb_substr($desc, 0, 160)); ?>">
<link rel="canonical" href="<?php echo site_h($site_url . 'plans.php' . ($cur ? '?c=' . rawurlencode($cur['slug']) : '')); ?>">
<?php if ($favi_url): ?><link rel="icon" href="<?php echo site_h($favi_url); ?>" type="image/png"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--primary:#000922;--accent:#0051d5;--accent-2:#316bf3;--gold:#fbbf24;--text:#e8eaf6;--muted:#9fa8da;--card:rgba(255,255,255,.04);--line:rgba(255,255,255,.08)}
body{background:var(--primary);color:var(--text);font-family:'Vazirmatn',sans-serif;line-height:1.8;direction:rtl}
a{color:var(--accent-2);text-decoration:none}
.container{max-width:1200px;margin:0 auto;padding:0 20px}
.site-header{background:rgba(0,9,34,.9);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50}
.nav-inner{display:flex;align-items:center;justify-content:space-between;height:64px;gap:16px}
.nav-logo img{height:36px}.nav-logo span{font-size:18px;font-weight:800;color:#fff}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 22px;border-radius:12px;font-size:14px;font-weight:700;border:none;cursor:pointer;font-family:inherit}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent-2)}
.hero{padding:44px 0 22px;text-align:center}
.crumb{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px}
.hero h1{font-size:clamp(24px,4.4vw,38px);font-weight:800;line-height:1.35}
.hero p{color:var(--muted);margin-top:8px;font-size:15px}
.plans{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:20px;margin-bottom:50px}
.plan{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:26px 22px;text-align:center;display:flex;flex-direction:column}
.plan-name{font-size:20px;font-weight:800;color:#fff;margin-bottom:4px}
.plan-desc{font-size:13px;color:var(--muted);margin-bottom:14px}
.plan-price{font-size:28px;font-weight:800;color:#fff;margin-bottom:4px}
.plan ul{list-style:none;margin:16px 0;text-align:right;flex:1}
.plan li{font-size:13px;color:#cbd5e1;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:8px}
.plan li::before{content:'✓';color:#34d399;flex-shrink:0}
.plan li small{display:block;font-size:11.5px;color:var(--muted)}
.empty{text-align:center;color:var(--muted);padding:40px 0 60px}
.site-footer{border-top:1px solid var(--line);padding:24px;text-align:center;font-size:12px;color:rgba(255,255,255,.3)}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
</head>
<body>
<header class="site-header"><div class="container"><nav class="nav-inner">
  <a href="index.php" class="nav-logo"><?php if ($logo_url): ?><img src="<?php echo site_h($logo_url); ?>" alt="<?php echo site_h($site_name); ?>"><?php else: ?><span><?php echo site_h($site_name); ?></span><?php endif; ?></a>
  <span style="display:flex;gap:8px;align-items:center"><a href="<?php echo site_h(AICHAT_USER_URL . 'register.php'); ?>" class="btn btn-primary" style="padding:8px 18px;font-size:13px">ثبت‌نام</a><?php echo site_mobile_burger(); ?></span>
</nav></div></header>
<?php echo site_mobile_menu($pdo); ?>
<main class="container">
  <div class="hero">
    <div class="crumb"><a href="index.php">خانه</a> › پلن‌ها</div>
    <h1>پلن‌ها و قیمت‌ها</h1>
    <p>پلن مناسب کار خود را انتخاب کنید</p>
  </div>
  <?php if (!$groups): ?><div class="empty">در حال حاضر پلنی تعریف نشده است.</div><?php endif; ?>
  <?php echo pc_tabs_html($groups, 'dark', $cur['slug'] ?? ''); ?>
  <?php foreach ($groups as $gi => $g): $on = $cur ? $g['cat']['slug'] === $cur['slug'] : $gi === 0; ?>
  <div data-pcg="<?php echo site_h($g['cat']['slug']); ?>"<?php echo $on ? '' : ' hidden'; ?>>
    <?php if (count($groups) > 1 && $g['cat']['description'] !== ''): ?><p class="pcg-desc d"><?php echo site_h($g['cat']['description']); ?></p><?php endif; ?>
    <div class="plans">
      <?php foreach ($g['plans'] as $plan): $head = function_exists('biz_plan_head_html') ? biz_plan_head_html($plan, 'dark') : ''; ?>
      <div class="plan">
        <?php if ($head !== ''): echo $head; else: ?>
        <div class="plan-name"><?php echo site_h($plan['name']); ?></div>
        <?php if (!empty($plan['description'])): ?><div class="plan-desc"><?php echo site_h($plan['description']); ?></div><?php endif; ?>
        <?php endif; ?>
        <div class="plan-price"><?php echo function_exists('biz_price_html') ? biz_price_html($plan, 'color:var(--muted)') : number_format((int)$plan['price_toman']) . ' تومان'; ?></div>
        <ul>
          <?php foreach (ex_plan_feature_list_sorted($plan) as $pf): ?>
          <li><span><?php echo site_h($pf['text']); ?><?php if (($pf['note'] ?? '') !== ''): ?><small><?php echo site_h($pf['note']); ?></small><?php endif; ?></span></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-primary" style="width:100%" href="plan.php?id=<?php echo (int)$plan['id']; ?>#buy"><?php echo (int)$plan['price_toman'] === 0 ? 'شروع رایگان' : 'خرید اشتراک'; ?></a>
        <a href="plan.php?id=<?php echo (int)$plan['id']; ?>" style="display:block;margin-top:10px;font-size:13px">جزئیات پلن ←</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</main>
<footer class="site-footer"><?php echo site_h($s['footer_text'] ?? '© ' . date('Y') . ' ' . $site_name . '. کلیه حقوق محفوظ.'); ?></footer>
<?php if (function_exists('ex_popups_for')) { try { echo ex_popup_script(ex_popups_for($pdo, 'site'), rtrim(AICHAT_BASE_URL, '/') . '/pp.php'); } catch (\Throwable $e) {} } ?>
</body>
</html>
