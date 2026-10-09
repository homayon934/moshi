<?php
require_once dirname(__DIR__) . '/includes/sec_lib.php'; sec_boot('public');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';

$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch(\Throwable $e){}

$payment_id = (int)($_GET['payment_id'] ?? 0);
$authority  = trim($_GET['Authority'] ?? $_GET['authority'] ?? '');
$status     = $_GET['Status'] ?? '';

function show_result($ok, $msg, $redirect_url = '') {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $ok ? 'پرداخت موفق' : 'پرداخت ناموفق'; ?> — ویجت هوشمند</title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,#1e1b4b,#312e81);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;direction:rtl}
    .box{background:#fff;border-radius:16px;padding:40px;width:100%;max-width:400px;text-align:center}
    .icon{font-size:60px;margin-bottom:16px}
    h2{font-size:20px;color:<?php echo $ok ? '#059669' : '#dc2626'; ?>;margin-bottom:8px}
    p{font-size:14px;color:#64748b;line-height:1.7;margin-bottom:20px}
    a{display:inline-block;padding:11px 28px;background:#7c3aed;color:#fff;border-radius:9px;font-size:14px;font-weight:700;text-decoration:none}
    </style>
    <?php if ($redirect_url): ?>
    <meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars($redirect_url); ?>">
    <?php endif; ?>
    <?php if (function_exists('ui_fx_css')) echo ui_fx_css(); ?>
    </head>
    <body>
    <div class="box">
        <div class="icon"><?php echo $ok ? '✅' : '❌'; ?></div>
        <h2><?php echo $ok ? 'پرداخت موفق!' : 'پرداخت ناموفق'; ?></h2>
        <p><?php echo nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')); ?></p>
        <a href="../user/billing.php">رفتن به کیف پول</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// بررسی
if (!$payment_id || !$authority) show_result(false, 'اطلاعات پرداخت ناقص است.');
if ($status !== 'OK') show_result(false, 'پرداخت توسط کاربر لغو شد یا با خطا مواجه شد.');

// بارگذاری پرداخت
$pay_stmt = $pdo->prepare("SELECT * FROM saas_payments WHERE id=? AND authority=? AND status='pending'");
$pay_stmt->execute([$payment_id, $authority]);
$payment = $pay_stmt->fetch();

if (!$payment) {
    // شاید قبلاً تأیید شده
    $dup = $pdo->prepare("SELECT status FROM saas_payments WHERE id=?");
    $dup->execute([$payment_id]);
    $dup_pay = $dup->fetch();
    if ($dup_pay && $dup_pay['status'] === 'paid') {
        show_result(true, 'این پرداخت قبلاً با موفقیت تأیید شده است.');
    }
    show_result(false, 'اطلاعات پرداخت یافت نشد یا قبلاً پردازش شده است.');
}

// تأیید زرین‌پال
$v = biz_zp_verify($pdo, (int)$payment['amount_toman'], $authority);
if ($v['ok']) {
    $r = biz_complete_payment($pdo, $payment_id, $v['ref']);
    show_result(true, $r['message'] . "\nکد پیگیری: " . $v['ref'], '../user/billing.php');
} else {
    $pdo->prepare("UPDATE saas_payments SET status='failed' WHERE id=?")->execute([$payment_id]);
    show_result(false, 'تأیید پرداخت از سمت زرین‌پال ناموفق بود. در صورت کسر وجه، در ۷۲ ساعت برگشت می‌خورد. کد خطا: ' . $v['code']);
}
