<?php
/**
 * چت صفحه اصلی سایت — حالت کامل (نسخه ۴۳)
 * روشن/خاموش کردن حالت کامل و ورود به صفحه مدیریت چت‌بات سایت (همان صفحه مدیریت چت‌بات‌های تخصصی)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
require_once dirname(__DIR__) . '/includes/site_lib.php';
require_once dirname(__DIR__) . '/includes/sitechat_lib.php';
site_ensure_schema($pdo);
hd_ensure_schema($pdo);

$back = function ($m, $err = false) { header('Location: site_bot.php?' . ($err ? 'err=' : 'msg=') . urlencode($m)); exit; };
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) $back('نشست منقضی شده؛ صفحه را تازه کنید.', true);
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'mode') {
        $on = ($_POST['full'] ?? '') === '1';
        if ($on) sb_ensure($pdo);
        site_save_setting($pdo, 'sitechat_full', $on ? '1' : '0');
        site_save_setting($pdo, 'sitechat_guest', (string)max(0, min(100, (int)($_POST['guest'] ?? 5))));
        $back($on ? 'حالت کامل فعال شد.' : 'حالت ساده فعال شد.');
    }
    if ($act === 'manage') {
        $bot = sb_ensure($pdo);
        if (!$bot) $back('ساخت چت‌بات سایت ممکن نشد؛ گزارش خطای سرور را بررسی کنید.', true);
        unset($_SESSION['saas_team_id'], $_SESSION['saas_team_login']);
        $_SESSION['saas_user_id'] = sb_uid($pdo);
        $_SESSION['saas_admin_imp'] = 1;
        header('Location: ../user/bot.php?id=' . (int)$bot['id']); exit;
    }
    $back('درخواست نامعتبر است.', true);
}

$s = site_settings($pdo);
$c = sch_conf($s);
$bot = sb_bot($pdo);
$st = ['members' => 0, 'threads' => 0, 'today' => 0, 'paid' => 0, 'sales' => 0];
if ($bot) {
    $q = fn($sql, $p = []) => (function () use ($pdo, $sql, $p) { try { $x = $pdo->prepare($sql); $x->execute($p); return (int)$x->fetchColumn(); } catch (\Throwable $e) { return 0; } })();
    $bid = (int)$bot['id'];
    $st['members'] = $q("SELECT COUNT(*) FROM hd_members WHERE bot_id=?", [$bid]);
    $st['threads'] = $q("SELECT COUNT(*) FROM hd_threads WHERE bot_id=?", [$bid]);
    $st['today'] = $q("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND role='user' AND created_at >= ?", [$bid, date('Y-m-d 00:00:00')]);
    $st['paid'] = $q("SELECT COUNT(*) FROM hd_purchases WHERE bot_id=? AND status='paid' AND amount_toman > 0", [$bid]);
    $st['sales'] = $q("SELECT COALESCE(SUM(amount_toman),0) FROM hd_purchases WHERE bot_id=? AND status='paid'", [$bid]);
}
$chat_url = rtrim(AICHAT_BASE_URL, '/') . '/sitechat/';
$zp = trim((string)(saas_get_api_config($pdo)['zarinpal_merchant_id'] ?? ''));
include __DIR__ . '/_header.php';
?>
<h2 class="page-title">🌐 چت صفحه اصلی سایت</h2>
<?php if (!empty($_GET['msg'])): ?><div class="msg-ok"><?php echo admin_h($_GET['msg']); ?></div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="msg-err"><?php echo admin_h($_GET['err']); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><b>حالت چت صفحه اصلی</b></div>
    <div class="card-body">
        <form method="post" style="display:flex;flex-direction:column;gap:12px">
            <input type="hidden" name="act" value="mode"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
            <label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;line-height:1.9;cursor:pointer"><input type="radio" name="full" value="0" style="width:auto;margin-top:6px" <?php echo !$c['full'] ? 'checked' : ''; ?>>
                <span><b>ساده:</b> پرسش و پاسخ درباره خدمات سایت و گفتگوی عمومی، بدون ورود (سقف روزانه هر بازدیدکننده در «تنظیمات سایت»).</span></label>
            <label style="display:flex;gap:8px;align-items:flex-start;font-size:13.5px;line-height:1.9;cursor:pointer"><input type="radio" name="full" value="1" style="width:auto;margin-top:6px" <?php echo $c['full'] ? 'checked' : ''; ?>>
                <span><b>کامل (مثل چت‌بات‌های تخصصی):</b> ورود با موبایل یا گوگل، پیام رایگان و سقف روزانه، بسته‌های پولی و رایگان با درگاه سایت، انتخاب مدل، ساخت تصویر و ویدیو و «اقدام‌های آماده»، ارسال فایل و پیام صوتی، حافظه بلندمدت، یادآور و فهرست گفتگوها. کاربرانی که در پنل کاربری سایت وارد هستند، خودکار وارد چت می‌شوند.</span></label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:13.5px">
                <span>در حالت کامل، تعداد پیام مهمان (بدون ورود) در روز:</span>
                <input type="number" name="guest" min="0" max="100" value="<?php echo (int)$c['guest']; ?>" style="width:90px">
                <span class="muted" style="font-size:12px">۰ = از اول باید وارد شود. گفتگوی مهمان بعد از ورود در گفتگوهای او ذخیره می‌شود.</span>
            </div>
            <div><button type="submit" class="btn">💾 ذخیره</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><b>چت‌بات سایت (حالت کامل)</b></div>
    <div class="card-body">
        <?php if ($bot): ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
            <div class="card" style="padding:10px 14px;margin:0"><b><?php echo number_format($st['members']); ?></b> مخاطب</div>
            <div class="card" style="padding:10px 14px;margin:0"><b><?php echo number_format($st['threads']); ?></b> گفتگو</div>
            <div class="card" style="padding:10px 14px;margin:0"><b><?php echo number_format($st['today']); ?></b> پیام امروز</div>
            <div class="card" style="padding:10px 14px;margin:0"><b><?php echo number_format($st['paid']); ?></b> خرید بسته — <?php echo number_format($st['sales']); ?> تومان</div>
        </div>
        <?php else: ?>
        <p class="muted">هنوز ساخته نشده است؛ با «ورود به مدیریت» یا فعال کردن حالت کامل، خودکار ساخته می‌شود.</p>
        <?php endif; ?>
        <p style="font-size:13px;line-height:2;color:#475569">در صفحه مدیریت، همه تنظیمات چت‌بات‌های تخصصی در دسترس است: نام و پیام خوش‌آمد، <b>تعداد پیام رایگان و سقف روزانه</b>، <b>بسته‌ها (رایگان یا پولی) و امکانات هر بسته</b> مثل ساخت تصویر و ویدیو، <b>مدل هوش مصنوعی</b> (مدل جداگانه برای کاربران رایگان و بسته‌دار، و اجازه انتخاب مدل)، هزینه هر تصویر/ویدیو، دانش (فایل، سایت، پرسش و پاسخ)، مخاطبان و گفتگوها و فروش.
        پاسخ‌ها علاوه بر این‌ها از «اطلاعات سایت» (امکانات، پلن‌ها، صفحه‌ها، راهنما و «دانش اضافه» تنظیمات سایت) هم استفاده می‌کنند. هزینه هوش مصنوعی و پیامک از حساب سامانه است.</p>
        <?php if ($zp === ''): ?><div class="msg-err" style="margin-top:8px">برای فروش بسته‌ها، کد درگاه زرین‌پال را در «تنظیمات API» وارد کنید (یا در صفحه مدیریت چت‌بات، بخش فروش).</div><?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            <form method="post" style="margin:0"><input type="hidden" name="act" value="manage"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><button class="btn">🔧 ورود به مدیریت چت‌بات سایت</button></form>
            <?php if ($bot && $c['full']): ?><a class="btn btn-sm" href="<?php echo admin_h($chat_url); ?>" target="_blank" rel="noopener" style="background:#64748b;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none">👁 مشاهده چت</a><?php endif; ?>
            <a class="btn btn-sm" href="site_settings.php#sitechat-settings" style="background:#64748b;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none">⚙️ متن‌ها و دانش اضافه</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
