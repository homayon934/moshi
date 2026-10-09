<?php
/**
 * پرداخت کارت به کارت کاربر (نسخه ۵۶): نمایش شماره کارت سایت + ارسال تصویر فیش
 * آدرس: payment/card.php?pid=شناسه پرداخت در انتظار (ساخته‌شده در صفحه پرداخت)
 */
require_once dirname(__DIR__) . '/includes/sec_lib.php'; sec_boot('public');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/saas_lib.php';

$pdo = aichat_connect();
if (empty($_SESSION['saas_user_id'])) { header('Location: ../user/login.php'); exit; }
$user_id = (int)$_SESSION['saas_user_id'];
if (!empty($_SESSION['saas_team_id'])) {
    $GLOBALS['current_team'] = biz_team_get($pdo, (int)$_SESSION['saas_team_id']);
    if (!$GLOBALS['current_team'] || !saas_can('billing')) { header('Location: ../user/index.php?denied=1'); exit; }
}
c2c_schema($pdo);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$pid = (int)($_GET['pid'] ?? ($_POST['pid'] ?? 0));
$s = $pdo->prepare("SELECT * FROM saas_payments WHERE id=? AND user_id=?");
$s->execute([$pid, $user_id]);
$pay = $s->fetch(PDO::FETCH_ASSOC);
$pub = c2c_public($pdo, 'site');
$error = '';
if (!$pay) $error = 'پرداخت یافت نشد.';
elseif (!$pub) $error = 'پرداخت کارت به کارت در حال حاضر فعال نیست.';
elseif ($pay['status'] !== 'pending') $error = $pay['status'] === 'paid' ? 'این پرداخت قبلاً انجام و تأیید شده است.' : 'این پرداخت لغو شده است؛ از صفحه «اعتبار و پرداخت» دوباره اقدام کنید.';
$sent = $pay ? array_values(array_filter(c2c_list($pdo, ['scope' => 'user', 'payment_id' => (int)$pay['id']], 5), fn($r) => $r['status'] === 'pending')) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && !$sent) {
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($src !== '' && strtolower((string)parse_url($src, PHP_URL_HOST)) !== strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')))) { header('Location: card.php?pid=' . $pid); exit; }
    $bytes = c2c_upload_bytes($_FILES['receipt'] ?? null);
    if (is_array($bytes)) $error = $bytes['error'];
    elseif ($bytes === null) $error = 'تصویر فیش واریزی را انتخاب کنید.';
    else {
        $r = c2c_user_submit($pdo, $user_id, $pid, $bytes, (string)($_POST['tracking'] ?? ''), (string)($_POST['payer'] ?? ''));
        if ($r['ok']) { header('Location: ../user/billing.php?msg=' . urlencode($r['message'])); exit; }
        $error = $r['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>کارت به کارت</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Vazirmatn,'Segoe UI',Tahoma,sans-serif;background:linear-gradient(135deg,#1e1b4b,#312e81);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;direction:rtl}
.box{background:#fff;border-radius:16px;padding:26px;width:100%;max-width:480px}
h1{font-size:20px;color:#1e1b4b;margin-bottom:4px}.sub{font-size:13px;color:#64748b;margin-bottom:12px;line-height:1.9}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;line-height:1.9}
.ok{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;line-height:1.9}
label{display:block;font-size:13px;font-weight:600;color:#374151;margin:12px 0 6px}
input[type=text],input[type=file]{width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit}
.btn{width:100%;padding:13px;background:#7c3aed;color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:15px;font-weight:700;font-family:inherit;margin-top:14px}
.back{text-align:center;margin-top:16px;font-size:13px}.back a{color:#7c3aed}
.cp{border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3;border-radius:8px;padding:4px 10px;font-family:inherit;font-size:12px;cursor:pointer}
</style>
</head>
<body>
<div class="box">
    <h1>💳 کارت به کارت</h1>
    <?php if ($error && (!$pay || $pay['status'] !== 'pending' || !$pub)): ?>
        <div class="alert"><?php echo $h($error); ?></div>
    <?php else: ?>
        <p class="sub"><?php echo $h($pay['description']); ?> — مبلغ را به کارت زیر واریز کنید، سپس تصویر فیش (یا اسکرین‌شات رسید) را بفرستید. پس از بررسی و تأیید، خرید شما انجام می‌شود.</p>
        <?php echo c2c_card_html($pub, (int)$pay['amount_toman']); ?>
        <div style="text-align:center"><button type="button" class="cp" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo $h(preg_replace('/\D/', '', $pub['card'])); ?>');this.textContent='✓ کپی شد'">📋 کپی شماره کارت</button></div>
        <?php if ($error): ?><div class="alert" style="margin-top:12px"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if ($sent): ?>
        <div class="ok" style="margin-top:12px">⏳ فیش این پرداخت در <?php echo biz_jdate($sent[0]['created_at'], true); ?> ارسال شده و در انتظار بررسی است. نتیجه در بخش اعلان‌ها به شما اطلاع داده می‌شود.</div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="pid" value="<?php echo (int)$pay['id']; ?>">
            <label>🧾 تصویر فیش واریزی *</label>
            <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required>
            <label>کد پیگیری / شماره مرجع (اختیاری)</label>
            <input type="text" name="tracking" dir="ltr" maxlength="60">
            <label>نام واریزکننده یا ۴ رقم آخر کارت (اختیاری)</label>
            <input type="text" name="payer" maxlength="120">
            <button class="btn">ارسال فیش</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <div class="back"><a href="../user/billing.php">← بازگشت به اعتبار و پرداخت</a></div>
</div>
</body>
</html>
