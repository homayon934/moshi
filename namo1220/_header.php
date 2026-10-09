<?php if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; } if (!function_exists('ui_mobile_nav') && is_file(dirname(__DIR__) . '/includes/ui_lib.php')) require_once dirname(__DIR__) . '/includes/ui_lib.php'; ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?php echo admin_h($csrf_token ?? ''); ?>">
<title>پنل ادمین — ویجت هوشمند</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- Quill Snow -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<style>
:root {
    --primary:         #0f2042;
    --primary-mid:     #1e3a5f;
    --secondary:       #0051d5;
    --secondary-light: #316bf3;
    --accent-red:      #dc2626;
    --bg:              #f0f4ff;
    --surface:         #ffffff;
    --surface-low:     #e8eeff;
    --surface-mid:     #dce8ff;
    --text-on:         #0d1c2e;
    --text-muted:      #45464e;
    --text-dim:        #75777f;
    --border:          #c5c6d0;
    --border-light:    #e2e8f0;
    --error:           #ba1a1a;
    --sidebar-w:       240px;
    --header-h:        60px;
    --radius:          12px;
    --shadow-sm:       0 1px 6px rgba(0,0,0,.06);
    --shadow-md:       0 2px 12px rgba(0,0,0,.09);
    --transition:      .18s ease;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Vazirmatn', 'Segoe UI', Tahoma, sans-serif;
    background: var(--bg);
    color: var(--text-on);
    font-size: 14px;
    direction: rtl;
    min-height: 100vh;
}
a { color: var(--secondary); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Sidebar ─────────────────────────────── */
.sidebar {
    position: fixed; top: 0; right: 0;
    width: var(--sidebar-w); height: 100vh;
    background: var(--surface);
    border-left: 1px solid var(--border-light);
    display: flex; flex-direction: column;
    z-index: 200; overflow-y: auto;
    box-shadow: var(--shadow-md);
}
.sidebar-logo {
    padding: 18px 18px 16px;
    border-bottom: 1px solid var(--border-light);
    display: flex; align-items: center; gap: 12px;
}
.sidebar-logo-icon {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0; color: #fff;
}
.sidebar-logo strong { font-size: 14px; font-weight: 700; color: var(--text-on); display: block; }
.sidebar-logo small  { font-size: 11px; color: var(--text-dim); }

.nav-section {
    padding: 14px 18px 4px;
    font-size: 10px; font-weight: 700;
    color: var(--text-dim); text-transform: uppercase; letter-spacing: .6px;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 14px; margin: 1px 8px;
    border-radius: 8px; color: var(--text-muted);
    font-size: 13px; font-weight: 500;
    text-decoration: none; transition: var(--transition);
}
.nav-item:hover { background: var(--surface-low); color: var(--text-on); text-decoration: none; }
.nav-item.active { background: var(--surface-mid); color: var(--secondary); font-weight: 600; }
.nav-item .icon { font-size: 17px; width: 22px; text-align: center; flex-shrink: 0; }

.sidebar-footer {
    margin-top: auto; padding: 14px 16px;
    border-top: 1px solid var(--border-light);
    font-size: 12px; color: var(--text-dim);
    display: flex; align-items: center; justify-content: space-between;
}
.sidebar-footer a { color: var(--error); font-size: 12px; font-weight: 600; }
.sidebar-footer a:hover { text-decoration: none; color: #991b1b; }

/* ── Top Header ───────────────────────────── */
.topbar-header {
    position: fixed; top: 0; right: var(--sidebar-w); left: 0;
    height: var(--header-h);
    background: rgba(255,255,255,.92); backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border-light);
    z-index: 100; display: flex; align-items: center;
    justify-content: space-between; padding: 0 28px;
    box-shadow: var(--shadow-sm);
}
.topbar-header .breadcrumb {
    font-size: 13px; color: var(--text-dim);
    display: flex; align-items: center; gap: 6px; background: none; padding: 0; margin: 0;
}
.topbar-header .breadcrumb strong { color: var(--text-on); font-weight: 600; }
.topbar-status {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface-low); border-radius: 20px;
    padding: 5px 14px; font-size: 12px; color: var(--text-muted);
}
.topbar-status .dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; }

/* ── Main ─────────────────────────────────── */
.main { margin-right: var(--sidebar-w); padding: calc(var(--header-h) + 24px) 28px 28px; min-height: 100vh; }

/* ── Cards ────────────────────────────────── */
.card {
    background: var(--surface) !important;
    border-radius: var(--radius) !important;
    border: 1px solid var(--border-light) !important;
    padding: 20px 22px !important;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}
/* سربرگ/بدنه/پانویس بوت‌استرپی داخل کارت: فاصله دوبل نشود و سربرگ تا لبه کارت کشیده شود */
.card > .card-header, .card > .card-title { margin: -20px -22px 16px !important; }
.card > .card-body { padding: 0 !important; }
.card > .card-body.p-0 { margin: -16px -22px -20px; }
.main > .container-fluid.py-4 { padding-top: 0 !important; padding-left: 0 !important; padding-right: 0 !important; }
.card > .card-footer { margin: 16px -22px -20px !important; }
.card > h2:first-child, .card > h3:first-child, .card > h4:first-child { margin-top: 0; }
.card > :last-child { margin-bottom: 0; }
.card-title, .card-header {
    font-size: 14px; font-weight: 700; color: var(--text-on);
    padding: 14px 22px; margin: 0;
    border-bottom: 1px solid var(--border-light) !important;
    background: var(--bg) !important;
    border-radius: var(--radius) var(--radius) 0 0 !important;
}
.card-body { padding: 20px; background: transparent; }
.card-footer { padding: 14px 20px; background: var(--bg) !important; border-top: 1px solid var(--border-light) !important; border-radius: 0 0 var(--radius) var(--radius) !important; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px; }
.row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

/* ── Stat Grid ────────────────────────────── */
.stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
a.stat-box { display: block; text-decoration: none !important; color: inherit; cursor: pointer; position: relative; }
a.stat-box::after { content: "←"; position: absolute; left: 16px; top: 16px; font-size: 14px; color: var(--text-dim); opacity: .5; transition: var(--transition); }
a.stat-box:hover { border-color: var(--secondary); }
a.stat-box:hover::after { opacity: 1; color: var(--secondary); transform: translateX(-3px); }
.stat-box {
    background: var(--surface); border: 1px solid var(--border-light);
    border-radius: var(--radius); padding: 18px;
    box-shadow: var(--shadow-sm); transition: var(--transition);
}
.stat-box:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.stat-box .icon { font-size: 26px; margin-bottom: 10px; display: block; }
.stat-box .val  { font-size: 26px; font-weight: 800; color: var(--secondary); margin-bottom: 4px; display: block; }
.stat-box .lbl  { font-size: 12px; color: var(--text-dim); font-weight: 500; }

/* ── Forms (override Bootstrap) ──────────── */
label {
    display: block; font-size: 13px; font-weight: 600;
    color: var(--text-muted); margin-bottom: 5px; margin-top: 12px;
}
label:first-child { margin-top: 0; }
.form-group, .mb-3 { margin-bottom: 16px; }

input[type=text], input[type=number], input[type=email],
input[type=password], input[type=url], textarea, select,
.form-control, .form-select {
    width: 100% !important; padding: 9px 12px !important;
    border: 1.5px solid var(--border) !important;
    border-radius: 8px !important; font-size: 13px !important;
    font-family: inherit !important; color: var(--text-on) !important;
    background: var(--surface) !important; transition: var(--transition) !important;
    line-height: 1.5 !important;
}
input, select, textarea { max-width: 100%; box-sizing: border-box; }
input:focus, textarea:focus, select:focus,
.form-control:focus, .form-select:focus {
    outline: none !important;
    border-color: var(--secondary) !important;
    box-shadow: 0 0 0 3px rgba(0,81,213,.1) !important;
}
textarea, .form-control[rows] { resize: vertical; min-height: 80px; }
.form-text, .muted { font-size: 12px; color: var(--text-dim); margin-top: 4px; display: block; }
.form-label { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; }

/* ── Buttons (override Bootstrap) ────────── */
.btn {
    display: inline-flex !important; align-items: center; gap: 6px;
    padding: 9px 18px !important; border: none !important;
    border-radius: 8px !important; font-size: 13px !important;
    font-weight: 600 !important; cursor: pointer; font-family: inherit !important;
    text-decoration: none !important; transition: var(--transition) !important;
    white-space: nowrap; line-height: 1.4 !important;
}
.btn-sm { padding: 5px 12px !important; font-size: 12px !important; }
.btn-primary, .btn-primary:hover   { background: var(--secondary) !important; color: #fff !important; border: none !important; }
.btn-primary:hover                  { background: #0040a8 !important; }
.btn-success, .btn-success:hover   { background: #0a6640 !important; color: #fff !important; border: none !important; }
.btn-danger                        { background: #fee2e2 !important; color: var(--error) !important; border: 1px solid #fecaca !important; }
.btn-danger:hover                  { background: #fca5a5 !important; }
.btn-outline, .btn-outline-secondary, .btn-light, .btn-secondary {
    background: var(--surface) !important; border: 1.5px solid var(--border) !important;
    color: var(--text-muted) !important;
}
.btn-outline-secondary:hover, .btn-light:hover { background: var(--surface-low) !important; }
.btn-outline-danger { background: transparent !important; border: 1px solid #fecaca !important; color: var(--error) !important; }
.btn-outline-danger:hover { background: #fee2e2 !important; }

/* ── Tables ───────────────────────────────── */
table, .table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table-wrap { overflow-x: auto; }
thead tr, .table-light tr { background: var(--bg) !important; }
th {
    padding: 10px 14px; text-align: right;
    font-size: 11px; font-weight: 700; color: var(--text-dim);
    border-bottom: 2px solid var(--border-light); white-space: nowrap;
}
td { padding: 10px 14px; border-bottom: 1px solid var(--border-light); color: var(--text-muted); vertical-align: middle; }
tr:hover td { background: var(--bg); }
tr:last-child td { border-bottom: none; }

/* ── Badges ───────────────────────────────── */
.badge, .badge-ok, .badge-off, .badge-err, .badge-blue, .badge-yellow, .badge-purple, .badge-gray {
    display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600;
}
.badge-ok,  .bg-success-subtle { background: #dcfce7 !important; color: #166534 !important; }
.badge-off, .bg-secondary-subtle { background: var(--surface-low) !important; color: var(--text-dim) !important; }
.badge-err, .bg-danger-subtle  { background: #fee2e2 !important; color: var(--error) !important; }
.badge-blue,  .bg-primary-subtle { background: #dbeafe !important; color: #1e40af !important; }
.badge-yellow, .bg-warning-subtle { background: #fef9c3 !important; color: #854d0e !important; }
.badge-purple { background: #ede9fe !important; color: #6d28d9 !important; }
.badge-gray,  .bg-light { background: var(--surface-low) !important; color: var(--text-muted) !important; }
.bg-info-subtle { background: #e0f2fe !important; color: #0369a1 !important; }

/* ── Alerts ───────────────────────────────── */
.msg-ok, .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
.msg-err, .alert-danger  { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
.alert-info, .alert-info-box { background: #eff6ff; border: 1px solid #93c5fd; color: #1d4ed8; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; font-weight: 500; }

/* ── Page title ───────────────────────────── */
.page-title { font-size: 20px; font-weight: 700; color: var(--text-on); margin-bottom: 20px; }

/* ── Upload UI ────────────────────────────── */
.upload-box { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.upload-preview-img {
    height: 56px; width: 80px; object-fit: cover;
    border-radius: 8px; border: 1px solid var(--border-light);
}
.upload-label {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: var(--surface-low);
    border: 1.5px solid var(--border); border-radius: 8px;
    font-size: 12px; font-weight: 500; cursor: pointer;
    color: var(--text-muted); transition: var(--transition);
}
.upload-label:hover { background: var(--surface-mid); border-color: var(--secondary); }
.upload-label input[type=file] { display: none; }
.upload-url-hidden { width: 1px !important; height: 1px !important; padding: 0 !important; opacity: 0; position: absolute; }

/* ── Quill override ───────────────────────── */
.ql-toolbar.ql-snow { direction: ltr; border-radius: 8px 8px 0 0 !important; border-color: var(--border) !important; background: var(--bg); }
.ql-container.ql-snow { border-color: var(--border) !important; border-radius: 0 0 8px 8px !important; font-family: 'Vazirmatn', sans-serif !important; font-size: 13px; }
.ql-editor { min-height: 280px; direction: rtl; text-align: right; }
.ql-editor h1, .ql-editor h2, .ql-editor h3 { font-weight: 700; margin: 0.8em 0 0.4em; }
.ql-editor p { margin-bottom: 0.6em; line-height: 1.7; }

/* ── Misc ─────────────────────────────────── */
.text-muted, .text-secondary { color: var(--text-dim) !important; }
.fw-semibold, .fw-bold { font-weight: 700; }
.fw-medium { font-weight: 500; }
.small, small { font-size: 12px; }
hr { border-color: var(--border-light); margin: 16px 0; }
code { background: var(--surface-low); padding: 2px 6px; border-radius: 4px; font-size: 12px; }
.text-dark { color: var(--text-on) !important; }
.p-0 { padding: 0 !important; }
.py-4 { padding-top: 1rem !important; padding-bottom: 1rem !important; }


@media(max-width: 900px) {
    :root { --sidebar-w: 0px; }
    .sidebar { display: none; }
    .main { margin-right: 0; padding-right: 16px; padding-left: 16px; }
    .topbar-header { right: 0; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .card { padding: 16px !important; }
    .card > .card-header, .card > .card-title { margin: -16px -16px 14px !important; }
    .card > .card-body.p-0 { margin: -14px -16px -16px; }
    .card > .card-footer { margin: 14px -16px -16px !important; }
    .row2 { grid-template-columns: 1fr; }
}
</style>
<?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
<?php if (function_exists('sec_csrf_js')) echo sec_csrf_js(); ?>
</head>
<body>

<!-- ── Sidebar ── -->
<div class="sidebar">
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon">🛡️</div>
        <div>
            <strong>پنل ادمین</strong>
            <small>ویجت هوشمند</small>
        </div>
    </div>

    <div class="nav-section">مدیریت</div>
    <a href="index.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='index.php'?'active':''; ?>">
        <span class="icon">📊</span> داشبورد
    </a>
    <?php $rem_badge = 0; if (isset($pdo) && function_exists('rem_counts')) { try { $rc_ = rem_counts($pdo, rem_ctx_admin($pdo)); $rem_badge = $rc_['today'] + $rc_['overdue']; } catch (\Throwable $e) {} } ?>
    <a href="reminders.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='reminders.php'?'active':''; ?>">
        <span class="icon">⏰</span> یادآورها<?php if ($rem_badge): ?> <span style="background:#2563eb;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-right:auto"><?php echo (int)$rem_badge; ?></span><?php endif; ?>
    </a>
    <a href="users.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='users.php'?'active':''; ?>">
        <span class="icon">👥</span> کاربران
    </a>
    <a href="activity.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='activity.php'?'active':''; ?>">
        <span class="icon">📈</span> ورود و بازدیدها
    </a>
    <?php $c2c_badge = 0; if (isset($pdo) && function_exists('c2c_pending_count')) { try { $c2c_badge = c2c_pending_count($pdo, ['scope' => 'user']); } catch (\Throwable $e) {} } ?>
    <a href="receipts.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='receipts.php'?'active':''; ?>">
        <span class="icon">🧾</span> فیش‌های واریزی<?php if ($c2c_badge): ?> <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-right:auto"><?php echo (int)$c2c_badge; ?></span><?php endif; ?>
    </a>
    <a href="plans.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='plans.php'?'active':''; ?>">
        <span class="icon">📦</span> پلن‌ها
    </a>
    <a href="custom_plan.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='custom_plan.php'?'active':''; ?>">
        <span class="icon">🧩</span> پلن دلخواه کاربران
    </a>
    <a href="discounts.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='discounts.php'?'active':''; ?>">
        <span class="icon">🎟️</span> تخفیف‌ها
    </a>
    <a href="affiliates.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='affiliates.php'?'active':''; ?>">
        <span class="icon">🤝</span> همکاری در فروش
    </a>
    <?php $svc_open = function_exists('biz_open_services_count') ? biz_open_services_count($pdo) : 0; ?>
    <a href="services.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='services.php'?'active':''; ?>">
        <span class="icon">🛠</span> خدمات و تمدید<?php if ($svc_open): ?> <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-right:6px"><?php echo $svc_open; ?></span><?php endif; ?>
    </a>
    <a href="models.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='models.php'?'active':''; ?>">
        <span class="icon">🧠</span> مدل‌ها و قیمت‌ها
    </a>
    <a href="gen_actions.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='gen_actions.php'?'active':''; ?>">
        <span class="icon">⚡</span> اقدام‌های آماده تصویر/ویدیو
    </a>
    <a href="chatbots.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='chatbots.php'?'active':''; ?>">
        <span class="icon">🤖</span> تنظیمات چت‌بات و ویجت
    </a>
    <a href="gallery.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='gallery.php'?'active':''; ?>">
        <span class="icon">🖼</span> گالری طراحی و ویدیو
    </a>
    <a href="kb.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='kb.php'?'active':''; ?>">
        <span class="icon">📚</span> پایگاه دانش مرکزی
    </a>
    <?php $sup_unread = function_exists('ex_unread_count') ? ex_unread_count($pdo, 'admin') : 0; ?>
    <a href="support.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='support.php'?'active':''; ?>">
        <span class="icon">🎧</span> پشتیبانی و تیکت‌ها<?php if ($sup_unread): ?> <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-right:6px"><?php echo $sup_unread; ?></span><?php endif; ?>
    </a>
    <a href="popups.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='popups.php'?'active':''; ?>">
        <span class="icon">🪧</span> پاپ‌آپ‌ها
    </a>

    <div class="nav-section">مدیریت سایت</div>
    <a href="slides.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='slides.php'?'active':''; ?>">
        <span class="icon">🖼️</span> اسلایدر
    </a>
    <a href="feature_tiles.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='feature_tiles.php'?'active':''; ?>">
        <span class="icon">🔲</span> تایل‌های ویژگی
    </a>
    <a href="site_pages.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='site_pages.php'?'active':''; ?>">
        <span class="icon">📄</span> صفحات ثابت
    </a>
    <a href="site_posts.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='site_posts.php'?'active':''; ?>">
        <span class="icon">📝</span> مطالب و اخبار
    </a>
    <a href="site_settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='site_settings.php'?'active':''; ?>">
        <span class="icon">🌐</span> تنظیمات سایت
    </a>
    <a href="site_chats.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='site_chats.php'?'active':''; ?>">
        <span class="icon">💬</span> گفتگوهای صفحه اصلی
    </a>
    <a href="site_bot.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='site_bot.php'?'active':''; ?>">
        <span class="icon">🌐</span> چت صفحه اصلی (حالت کامل)
    </a>

    <div class="nav-section">تنظیمات</div>
    <a href="api_settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='api_settings.php'?'active':''; ?>">
        <span class="icon">⚙️</span> تنظیمات API
    </a>
    <a href="auth_settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='auth_settings.php'?'active':''; ?>">
        <span class="icon">🔐</span> پیامک، ورود و ایمیل
    </a>
    <a href="payments.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='payments.php'?'active':''; ?>">
        <span class="icon">💳</span> پرداخت‌ها
    </a>
    <?php $ipg_nb = (isset($pdo) && function_exists('ipg_blocked_count')) ? ipg_blocked_count($pdo) : 0; ?>
    <a href="security.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF'])==='security.php'?'active':''; ?>">
        <span class="icon">🛡️</span> امنیت ورود<?php if ($ipg_nb): ?> <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-right:auto"><?php echo (int)$ipg_nb; ?></span><?php endif; ?>
    </a>

    <div class="sidebar-footer">
        <span>مدیر کل سیستم</span>
        <a href="?logout=1">⬡ خروج</a>
    </div>
</div>

<!-- ── Top Header Bar ── -->
<header class="topbar-header">
    <?php echo function_exists('ui_mobile_burger') ? ui_mobile_burger() : ''; ?>
    <div class="breadcrumb">
        <span>میزکار مدیریت</span>
        <span>›</span>
        <strong><?php
            $titles = [
                'index.php'        => 'داشبورد',
                'users.php'        => 'مدیریت کاربران',
                'activity.php'     => 'ورود و بازدیدها',
                'receipts.php'     => 'فیش‌های واریزی',
                'plans.php'        => 'مدیریت پلن‌ها',
                'custom_plan.php'  => 'پلن دلخواه کاربران',
                'api_settings.php' => 'تنظیمات API',
                'payments.php'     => 'پرداخت‌ها',
                'slides.php'       => 'مدیریت اسلایدر',
                'feature_tiles.php'=> 'تایل‌های ویژگی',
                'site_pages.php'   => 'صفحات ثابت',
                'site_posts.php'   => 'مطالب و اخبار',
                'site_settings.php'=> 'تنظیمات سایت',
                'site_chats.php'   => 'گفتگوهای صفحه اصلی',
                'gen_actions.php'  => 'اقدام‌های آماده تصویر و ویدیو',
                'site_bot.php'     => 'چت صفحه اصلی سایت',
                'support.php'      => 'پشتیبانی و تیکت‌ها',
                'popups.php'       => 'پاپ‌آپ‌ها',
                'auth_settings.php'=> 'پیامک، ورود و ایمیل',
            ];
            $cur = basename($_SERVER['PHP_SELF']);
            echo isset($titles[$cur]) ? saas_h($titles[$cur]) : 'پنل ادمین';
        ?></strong>
    </div>
    <div class="topbar-status">
        <span class="dot"></span>
        <span>پنل آنلاین</span>
    </div>
</header>
<?php
// منوی موبایل: همبرگری + نوار پایین (includes/ui_lib.php)
if (function_exists('ui_mobile_nav')) echo ui_mobile_nav([
    ['href' => 'index.php', 'icon' => '📊', 'label' => 'داشبورد'],
    ['href' => 'users.php', 'icon' => '👥', 'label' => 'کاربران'],
    ['href' => 'reminders.php', 'icon' => '⏰', 'label' => 'یادآورها', 'badge' => (int)($rem_badge ?? 0)],
    ['href' => 'support.php', 'icon' => '🎧', 'label' => 'پشتیبانی'],
]);
?>

<!-- ── Main ── -->
<div class="main">

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Quill JS -->
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
// ── Global helpers ───────────────────────────────────────────
window.CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * آپلود تصویر و نمایش preview
 * @param {HTMLInputElement} fileInput
 * @param {string|null}      previewId   - id تگ <img> preview
 * @param {string}           hiddenId    - id فیلد مخفی URL
 */
function adminUploadImage(fileInput, previewId, hiddenId) {
    const file = fileInput.files[0];
    if (!file) return;
    const label = fileInput.closest('label');
    if (label) label.textContent = 'در حال آپلود…';

    const fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', window.CSRF_TOKEN);

    fetch('upload_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                document.getElementById(hiddenId).value = d.url;
                if (previewId) {
                    const img = document.getElementById(previewId);
                    if (img) { img.src = d.url; img.style.display = 'block'; }
                }
            } else {
                alert('خطا در آپلود: ' + (d.error || 'نامشخص'));
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'))
        .finally(() => {
            if (label) label.innerHTML = '<i class="bi bi-image me-1"></i>انتخاب / تغییر تصویر <input type="file" accept="image/*" onchange="' + label.dataset.onchange + '" style="display:none">';
        });
}

/**
 * مقداردهی Quill روی یک تکست‌ایریا
 * @param {string} editorId   - id div ویرایشگر
 * @param {string} textareaId - id textarea مخفی (name دارد برای فرم)
 */
function initQuill(editorId, textareaId) {
    const editorEl   = document.getElementById(editorId);
    const textareaEl = document.getElementById(textareaId);
    if (!editorEl || !textareaEl) return null;

    const quill = new Quill(editorEl, {
        theme: 'snow',
        modules: {
            toolbar: {
                container: [
                    [{ header: [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ color: [] }, { background: [] }],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    [{ align: [] }],
                    ['link', 'image'],
                    ['blockquote', 'code-block'],
                    ['clean']
                ],
                handlers: {
                    image: function () {
                        const inp = document.createElement('input');
                        inp.type = 'file'; inp.accept = 'image/*';
                        inp.onchange = function () {
                            const f = inp.files[0]; if (!f) return;
                            const fd = new FormData();
                            fd.append('file', f);
                            fd.append('csrf_token', window.CSRF_TOKEN);
                            fetch('upload_handler.php', { method: 'POST', body: fd })
                                .then(r => r.json())
                                .then(d => {
                                    if (d.success) {
                                        const range = quill.getSelection(true);
                                        quill.insertEmbed(range.index, 'image', d.url);
                                        quill.setSelection(range.index + 1);
                                    } else alert(d.error || 'خطا در آپلود');
                                });
                        };
                        inp.click();
                    }
                }
            }
        }
    });

    // بارگذاری محتوای موجود
    if (textareaEl.value) {
        quill.clipboard.dangerouslyPasteHTML(textareaEl.value);
    }

    // همگام‌سازی با فرم هنگام ارسال
    const form = editorEl.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            textareaEl.value = quill.root.innerHTML;
        });
    }
    return quill;
}
</script>
<?php if (function_exists('panel_ajax_script')) panel_ajax_script(); ?>
<?php if (!empty($_SESSION['aichat_admin'])) { require_once dirname(__DIR__) . '/includes/rem_ui.php'; echo rem_toast_html('admin'); } ?>
<div id="pgc">
