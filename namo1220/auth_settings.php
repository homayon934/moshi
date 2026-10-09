<?php
/**
 * پیامک، ورود و ایمیل:
 *  - روش‌های ورود هر بخش (پیامک، گوگل، رمز) با فعال/غیرفعال و اولویت
 *  - الگوهای پیامک (متن آزاد یا الگوی اعتبارسنجی کاوه‌نگار) + ارسال آزمایشی + گزارش ارسال
 *  - تنظیمات ایمیل (SMTP) + ارسال آزمایشی با گزارش کامل
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/comm_ui.php';
try { if (function_exists('biz_ensure_schema')) biz_ensure_schema($pdo); comm_ensure_schema($pdo); } catch (\Throwable $e) {}

$tabs = ['login' => '🔐 روش‌های ورود', 'sms' => '📱 الگوهای پیامک', 'email' => '📧 ایمیل'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'login';
$self = 'auth_settings.php?tab=' . $tab;
$sections = comm_login_sections();
$mnames = comm_login_methods();
$test_out = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: ' . $self . '&msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $act = (string)($_POST['act'] ?? '');
    $msg = '';
    $extra = '';
    if ($act === 'login_save') {
        $sec = (string)($_POST['sec'] ?? '');
        if ($sec === 'admin') {
            $mobs = [];
            foreach (preg_split('/[\s,،]+/u', (string)($_POST['admin_mobiles'] ?? '')) as $x) if (($x = biz_mobile($x)) !== '') $mobs[] = $x;
            $ems = [];
            foreach (preg_split('/[\s,،]+/u', strtolower((string)($_POST['admin_emails'] ?? ''))) as $x) if (filter_var($x, FILTER_VALIDATE_EMAIL)) $ems[] = $x;
            biz_set($pdo, 'admin_mobiles', implode(',', array_unique($mobs)));
            biz_set($pdo, 'admin_emails', implode(',', array_unique($ems)));
        }
        $order = array_filter(explode(',', (string)($_POST['order'] ?? '')));
        $on = [];
        foreach (array_keys($mnames) as $mm) $on[$mm] = !empty($_POST['on_' . $mm]);
        $msg = comm_login_conf_save($pdo, $sec, $order, $on);
        $extra = '#sec_' . $sec;
    } elseif ($act === 'tfa_test' || $act === 'tfa_on' || $act === 'tfa_off') {
        // نسخه ۷۸: ورود دو مرحله‌ای مدیر (رمز/گوگل + کد پیامکی) — روشن کردن فقط با تأیید یک کد آزمایشی
        $extra = '#sec_tfa';
        if ($act === 'tfa_off') {
            biz_set($pdo, 'admin_2fa', '');
            unset($_SESSION['tfa_setup']);
            $msg = 'ok:ورود دو مرحله‌ای مدیر خاموش شد.';
            if (function_exists('biz_notify_admin')) { try { biz_notify_admin($pdo, '⚠️ ورود دو مرحله‌ای خاموش شد', 'ورود دو مرحله‌ای مدیر کل از پنل خاموش شد. IP: ' . comm_ip()); } catch (\Throwable $e) {} }
        } elseif (!comm_admin_2fa_ready($pdo)) {
            $msg = 'error:اول سرویس پیامک (تنظیمات API) و شماره موبایل مدیر (همین صفحه، بخش مدیر کل) را تنظیم کنید.';
        } elseif ($act === 'tfa_test') {
            $mob = biz_mobile($_POST['tfa_mobile'] ?? '');
            if ($mob === '' || !in_array($mob, comm_admin_mobiles($pdo), true)) $msg = 'error:شماره انتخاب‌شده جزو شماره‌های مدیر نیست.';
            else {
                $err = comm_otp_send($pdo, 'admin', $mob, '2fa_on', 'otp_admin');
                if ($err !== '') $msg = 'error:ارسال کد ممکن نشد: ' . $err;
                else { $_SESSION['tfa_setup'] = ['mobile' => $mob, 'at' => time()]; $msg = 'ok:کد آزمایشی به ' . comm_mask_mobile($mob) . ' پیامک شد؛ آن را وارد کنید تا ورود دو مرحله‌ای روشن شود.'; }
            }
        } else {
            $ts = $_SESSION['tfa_setup'] ?? null;
            if (!$ts || time() - (int)$ts['at'] > 600) $msg = 'error:مهلت کد تمام شده است؛ دوباره کد آزمایشی بگیرید.';
            else {
                $err = comm_otp_check($pdo, 'admin', $ts['mobile'], '2fa_on', $_POST['tfa_code'] ?? '');
                if ($err !== '') $msg = 'error:' . $err;
                else {
                    biz_set($pdo, 'admin_2fa', '1');
                    unset($_SESSION['tfa_setup']);
                    $msg = 'ok:✅ ورود دو مرحله‌ای مدیر روشن شد. از این به بعد بعد از رمز (یا گوگل)، کد پیامکی هم لازم است.';
                }
            }
        }
    } elseif ($act === 'tpl_save') {
        $key = (string)($_POST['key'] ?? '');
        $msg = comm_sms_tpl_save($pdo, $key, $_POST);
        $extra = '#t_' . $key;
    } elseif ($act === 'tpl_reset') {
        $key = (string)($_POST['key'] ?? '');
        if (isset(comm_sms_types()[$key])) { $pdo->prepare("DELETE FROM saas_biz_settings WHERE k=?")->execute(['sms_tpl_' . $key]); biz_settings($pdo, true); $msg = 'ok:الگو به حالت پیش‌فرض برگشت.'; }
        $extra = '#t_' . $key;
    } elseif ($act === 'tpl_test') {
        $key = (string)($_POST['key'] ?? '');
        $types = comm_sms_types();
        if (!isset($types[$key])) $msg = 'error:نوع پیامک نامعتبر است.';
        elseif (biz_mobile($_POST['test_mobile'] ?? '') === '') $msg = 'error:شماره موبایل آزمایشی معتبر نیست.';
        else {
            biz_set($pdo, 'sms_test_mobile', biz_mobile($_POST['test_mobile']));
            $r = comm_sms_send($pdo, $_POST['test_mobile'], $key, $types[$key]['vars'], ['kind' => 'test']);
            $msg = $r['ok'] ? 'ok:پیامک آزمایشی ارسال شد' . ($r['error'] !== '' ? ' — ' . $r['error'] : '.') : 'error:ارسال نشد: ' . $r['error'];
        }
        $extra = '#t_' . $key;
    } elseif ($act === 'mail_save' || $act === 'mail_test') {
        $data = [
            'email_enabled'  => !empty($_POST['email_enabled']) ? 1 : 0,
            'smtp_host'      => trim((string)($_POST['smtp_host'] ?? '')),
            'smtp_port'      => max(1, min(65535, (int)($_POST['smtp_port'] ?? 587))),
            'smtp_secure'    => in_array($_POST['smtp_secure'] ?? '', ['auto', 'tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'auto',
            'smtp_user'      => trim((string)($_POST['smtp_user'] ?? '')),
            'smtp_from'      => trim((string)($_POST['smtp_from'] ?? '')),
            'smtp_from_name' => mb_substr(trim((string)($_POST['smtp_from_name'] ?? '')), 0, 100),
        ];
        if ((string)($_POST['smtp_pass'] ?? '') !== '') $data['smtp_pass'] = (string)$_POST['smtp_pass'];
        if ($data['smtp_from'] !== '' && !filter_var($data['smtp_from'], FILTER_VALIDATE_EMAIL)) $msg = 'error:آدرس ایمیل فرستنده معتبر نیست.';
        else {
            $sets = array_map(fn($k) => "`$k`=?", array_keys($data));
            try { $pdo->prepare("UPDATE saas_api_config SET " . implode(',', $sets) . " WHERE id=1")->execute(array_values($data)); } catch (\Throwable $e) { $msg = 'error:ذخیره نشد: ' . $e->getMessage(); }
            if ($msg === '' && $act === 'mail_test') {
                $to = trim((string)($_POST['test_to'] ?? ''));
                $mc = comm_mail_cfg($pdo);
                $r = comm_mail_send($pdo, $to, 'ایمیل آزمایشی — ' . $mc['from_name'], "این یک ایمیل آزمایشی است.\nاگر آن را دریافت کرده‌اید، تنظیمات ایمیل درست است.", comm_mail_html('ایمیل آزمایشی', "این یک ایمیل آزمایشی است.\nاگر آن را دریافت کرده‌اید، تنظیمات ایمیل درست است و ایمیل‌های بازیابی رمز هم ارسال می‌شوند."), $mc);
                $_SESSION['mail_test_log'] = $r['log'];
                if ($to !== '') biz_set($pdo, 'mail_test_to', $to);
                $msg = $r['ok'] ? 'ok:ایمیل آزمایشی ارسال شد. صندوق ورودی (و پوشه Spam) را بررسی کنید.' : 'error:ارسال نشد: ' . $r['error'];
            } elseif ($msg === '') $msg = 'ok:تنظیمات ایمیل ذخیره شد.';
        }
    }
    header('Location: ' . $self . '&msg=' . urlencode((string)$msg) . $extra); exit;
}
$msg = (string)($_GET['msg'] ?? '');

include __DIR__ . '/_header.php';
$sms_ready = comm_platform_sms_ready($pdo);
$g_ready = comm_google_configured($pdo);
?>
<style>
.stabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px;border-bottom:2px solid #ede9fe}
.stabs a{padding:9px 14px;border-radius:10px 10px 0 0;font-size:13.5px;font-weight:600;color:#475569;text-decoration:none;background:#f8fafc;border:1px solid #e2e8f0;border-bottom:none;margin-bottom:-2px}
.stabs a.on{background:#fff;color:#6d28d9;border-color:#c4b5fd;border-bottom:2px solid #fff}
.mrow{display:flex;align-items:center;gap:10px;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;margin-bottom:6px;background:#fff}
.mrow .nm{flex:1;font-weight:600;font-size:13.5px}
.mrow .st{font-size:11.5px;color:#64748b}
.mrow button{border:1px solid #e2e8f0;background:#f8fafc;border-radius:7px;padding:2px 9px;cursor:pointer;font-size:12px}
.mrow .pr{background:#7c3aed;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.tplc{border:1px solid #e2e8f0;border-radius:12px;margin-bottom:10px;background:#fff}
.tplc summary{padding:12px 14px;cursor:pointer;display:flex;gap:8px;align-items:center;font-weight:600;font-size:13.5px}
.tplc summary .bd{margin-right:auto}
.tplc .in{padding:4px 14px 14px;border-top:1px solid #f1f5f9}
.vchip{display:inline-block;background:#ede9fe;color:#5b21b6;border-radius:7px;padding:1px 8px;font-size:12px;margin:2px;cursor:pointer;direction:ltr}
.log td{font-size:12px}
.pill{display:inline-block;border-radius:10px;padding:1px 8px;font-size:11.5px}
.pill.ok{background:#dcfce7;color:#166534}.pill.no{background:#fee2e2;color:#991b1b}.pill.gr{background:#f1f5f9;color:#475569}
</style>
<h2 class="page-title">🔐 پیامک، ورود و ایمیل</h2>
<?php if ($msg !== ''): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>
<nav class="stabs"><?php foreach ($tabs as $k => $l): ?><a href="auth_settings.php?tab=<?php echo $k; ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><?php echo $l; ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'login'): ?>
<div class="card" style="font-size:13px;line-height:2.1">
    برای هر بخش مشخص کنید کاربران با کدام روش‌ها وارد شوند و کدام روش <b>اول</b> نمایش داده شود (اولویت ۱ = روش پیش‌فرض صفحه ورود).
    <br>وضعیت فنی: پیامک سامانه <?php echo $sms_ready ? '<span class="pill ok">آماده</span>' : '<span class="pill no">تنظیم نشده</span> (از <a href="api_settings.php">تنظیمات API</a>)'; ?>
    — ورود با گوگل <?php echo $g_ready ? '<span class="pill ok">آماده</span>' : '<span class="pill no">Client ID/Secret تنظیم نشده</span> (از <a href="api_settings.php">تنظیمات API</a>)'; ?>
    <br><span class="muted">روشی که فعال است ولی از نظر فنی آماده نیست، در صفحه ورود نمایش داده نمی‌شود. اگر هیچ روشی آماده نباشد، ورود با رمز نمایش داده می‌شود تا ورود قطع نشود.</span>
</div>
<?php foreach ($sections as $sec => $info): $c = comm_login_conf($pdo, $sec); $act = comm_login_active($pdo, $sec); ?>
<div class="card" id="sec_<?php echo $sec; ?>">
    <h3 style="font-size:15px;margin-bottom:4px"><?php echo $info['icon'] . ' ' . admin_h($info['title']); ?></h3>
    <p class="muted" style="margin-bottom:10px"><?php echo admin_h($info['note']); ?> — نمایش فعلی: <b><?php echo admin_h(implode('، ', array_map(fn($x) => strip_tags($mnames[$x]), $act)) ?: '—'); ?></b></p>
    <form method="post" data-pg="login_<?php echo $sec; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="login_save"><input type="hidden" name="sec" value="<?php echo $sec; ?>">
        <input type="hidden" name="order" class="ord" value="<?php echo admin_h(implode(',', $c['order'])); ?>">
        <div class="mlist">
        <?php foreach ($c['order'] as $i => $mm):
            $ready = comm_login_ready($pdo, $sec, $mm);
            $why = '';
            if (!$ready) $why = $mm === 'sms' ? ($sec === 'admin' && $sms_ready ? 'شماره موبایل مدیر ثبت نشده' : 'سرویس پیامک تنظیم نشده') : ($mm === 'google' ? ($sec === 'admin' && $g_ready ? 'ایمیل گوگل مدیر ثبت نشده' : 'اطلاعات گوگل تنظیم نشده') : '');
            if ($sec === 'member' && $mm === 'sms') $why = 'برای هر چت‌بات: پنل پیامک اختصاصی یا اعتبار پیامک صاحب ربات لازم است';
        ?>
            <div class="mrow" data-m="<?php echo $mm; ?>">
                <span class="pr"><?php echo $i + 1; ?></span>
                <label style="display:flex;gap:8px;align-items:center;flex:1;margin:0"><input type="checkbox" name="on_<?php echo $mm; ?>" value="1" <?php echo !empty($c['on'][$mm]) ? 'checked' : ''; ?> style="width:17px;height:17px"> <span class="nm"><?php echo $mnames[$mm]; ?></span></label>
                <span class="st"><?php echo $why !== '' ? '⚠️ ' . admin_h($why) : ($ready ? '✅ آماده' : ''); ?></span>
                <button type="button" onclick="mvM(this,-1)" title="اولویت بالاتر">▲</button><button type="button" onclick="mvM(this,1)" title="اولویت پایین‌تر">▼</button>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($sec === 'admin'): ?>
        <div class="row2" style="margin-top:10px">
            <div><label>شماره(های) موبایل مدیر برای ورود با پیامک</label><input type="text" name="admin_mobiles" dir="ltr" value="<?php echo admin_h(implode(', ', comm_admin_mobiles($pdo))); ?>" placeholder="09121234567, 09351234567"></div>
            <div><label>ایمیل(های) گوگل مدیر برای ورود با گوگل</label><input type="text" name="admin_emails" dir="ltr" value="<?php echo admin_h(implode(', ', comm_admin_emails($pdo))); ?>" placeholder="admin@gmail.com"></div>
        </div>
        <p class="muted" style="margin-top:6px">رمز ثابت مدیر همان رمز فایل config.php است. خاموش کردن آن فقط وقتی ممکن است که روش دیگری آماده باشد.</p>
        <?php endif; ?>
        <?php if ($sec === 'member'): ?><p class="muted" style="margin-top:6px">ثبت‌نام مخاطب جدید: اگر «تأیید موبایل» در تنظیمات ربات روشن باشد و پیامک فعال باشد، کد تأیید ارسال می‌شود. مخاطبی که هیچ روش فعالی ندارد (مثلاً رمز نگذاشته و پیامک خاموش است) با همان شماره وارد می‌شود و پیشنهاد ساخت رمز می‌گیرد.</p><?php endif; ?>
        <button class="btn btn-primary btn-sm" style="margin-top:10px">💾 ذخیره</button>
    </form>
</div>
<?php endforeach; ?>
<?php $tfa_ready = comm_admin_2fa_ready($pdo); $tfa_is = (string)biz_get($pdo, 'admin_2fa') === '1'; $tfa_setup = $_SESSION['tfa_setup'] ?? null; if ($tfa_setup && time() - (int)$tfa_setup['at'] > 600) $tfa_setup = null; ?>
<div class="card" id="sec_tfa">
    <h3 style="font-size:15px;margin-bottom:4px">🔐 ورود دو مرحله‌ای مدیر کل (رمز ثابت + کد پیامکی)</h3>
    <p class="muted" style="margin-bottom:10px;line-height:2">وقتی روشن باشد، بعد از رمز درست (یا ورود با گوگل) یک کد ۵ رقمی به شماره مدیر پیامک می‌شود و بدون آن کد ورود ممکن نیست. ورود «فقط با کد پیامکی» هم در این حالت نمایش داده نمی‌شود، چون یک‌مرحله‌ای است.
        <br>وضعیت: <?php echo $tfa_is && $tfa_ready ? '<span class="pill ok">روشن</span>' : ($tfa_is ? '<span class="pill no">روشن، ولی پیامک یا شماره مدیر تنظیم نیست؛ فعلاً فقط با رمز وارد می‌شوید</span>' : '<span class="pill no">خاموش</span>'); ?>
        <br><span style="color:#b45309">راه نجات اگر پیامک نرسد: با File Manager هاست یک فایل خالی با نام <b dir="ltr">admin_2fa_off.txt</b> داخل پوشه <b dir="ltr">uploads</b> بسازید؛ ورود دو مرحله‌ای خاموش می‌شود.</span></p>
    <?php if (!$tfa_ready): ?>
        <div class="msg-err">پیش‌نیاز: سرویس پیامک فعال در <a href="api_settings.php">تنظیمات API</a> و ثبت شماره موبایل مدیر در بخش «مدیر کل» همین صفحه.</div>
    <?php elseif ($tfa_is): ?>
        <form method="post" data-pg="tfa_off" onsubmit="return confirm('ورود دو مرحله‌ای مدیر خاموش شود؟');"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tfa_off">
            <button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">خاموش کردن ورود دو مرحله‌ای</button></form>
    <?php elseif ($tfa_setup): ?>
        <form method="post" data-pg="tfa_on" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tfa_on">
            <div><label>کد پیامک‌شده به <span dir="ltr"><?php echo admin_h(comm_mask_mobile($tfa_setup['mobile'])); ?></span></label><input type="text" name="tfa_code" inputmode="numeric" maxlength="5" autocomplete="one-time-code" style="width:140px" dir="ltr" required></div>
            <button class="btn btn-primary btn-sm">✅ تأیید و روشن کردن</button></form>
        <form method="post" data-pg="tfa_test2" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tfa_test"><input type="hidden" name="tfa_mobile" value="<?php echo admin_h($tfa_setup['mobile']); ?>"><button class="btn btn-sm" style="background:#f1f5f9;color:#374151">ارسال دوباره کد</button></form>
    <?php else: ?>
        <form method="post" data-pg="tfa_test" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tfa_test">
            <div><label>برای روشن کردن، یک کد آزمایشی به این شماره فرستاده می‌شود</label><select name="tfa_mobile" dir="ltr"><?php foreach (comm_admin_mobiles($pdo) as $tm): ?><option value="<?php echo admin_h($tm); ?>"><?php echo admin_h(comm_mask_mobile($tm)); ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-primary btn-sm">📩 ارسال کد آزمایشی</button></form>
    <?php endif; ?>
</div>
<script>
function mvM(b, d){
  var row = b.closest('.mrow'), list = row.parentNode;
  if (d < 0 && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
  if (d > 0 && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
  var ids = [];
  Array.prototype.forEach.call(list.querySelectorAll('.mrow'), function(r, i){ ids.push(r.getAttribute('data-m')); r.querySelector('.pr').textContent = i + 1; });
  list.closest('form').querySelector('.ord').value = ids.join(',');
}
</script>

<?php elseif ($tab === 'sms'): $tm = (string)biz_get($pdo, 'sms_test_mobile'); ?>
<div class="card" style="font-size:13px;line-height:2.1">
    هر پیامک سامانه یک <b>الگو</b> دارد. دو حالت ارسال:
    <b>متن آزاد</b> (با خط فرستنده «<?php echo admin_h((string)(saas_get_api_config($pdo)['sms_sender'] ?? '—')); ?>») — متغیرها داخل آکولاد مثل <span class="vchip">{code}</span> جایگزین می‌شوند؛
    یا <b>الگوی اعتبارسنجی کاوه‌نگار</b> (Verify Lookup) که سریع‌تر و بدون محدودیت خط تبلیغاتی است: الگو را در پنل کاوه‌نگار بسازید (مثلاً «کد ورود شما: %token»)، پس از تأیید نام آن را اینجا وارد و مشخص کنید هر پارامتر (token، token2، …) کدام متغیر باشد.
    <br><span class="muted">در الگوی کاوه‌نگار، token تا token3 نباید فاصله داشته باشند (فاصله‌ها خودکار به نیم‌فاصله تبدیل می‌شوند)؛ برای متن‌های چندکلمه‌ای از token10 یا token20 استفاده کنید. اگر «ارسال متنی در صورت خطا» روشن باشد و الگو ناموفق شود، همان پیام با متن آزاد فرستاده می‌شود. پیامک‌هایی که چت‌بات‌ها با پنل پیامک اختصاصی خودشان می‌فرستند همیشه متنی هستند.</span>
    <?php if (!$sms_ready): ?><div class="msg-err" style="margin-top:8px">سرویس پیامک در <a href="api_settings.php">تنظیمات API</a> فعال نیست؛ هیچ پیامکی ارسال نمی‌شود.</div><?php endif; ?>
</div>
<?php foreach (comm_sms_groups() as $g => $gl): ?>
<h3 style="font-size:14.5px;margin:18px 0 8px"><?php echo $gl; ?></h3>
<?php foreach (comm_sms_types() as $key => $t): if ($t['g'] !== $g) continue; $v = comm_sms_tpl($pdo, $key); ?>
<details class="tplc" id="t_<?php echo $key; ?>">
    <summary><?php echo admin_h($t['title']); ?>
        <span class="bd"><?php echo empty($v['on']) ? '<span class="pill gr">خاموش</span>' : ($v['mode'] === 'lookup' ? '<span class="pill ok">الگو: ' . admin_h($v['lookup']) . '</span>' : '<span class="pill gr">متن آزاد</span>'); ?></span></summary>
    <div class="in">
        <form method="post" data-pg="tpl_<?php echo $key; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tpl_save"><input type="hidden" name="key" value="<?php echo $key; ?>">
            <?php if ($g !== 'otp'): ?><label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" name="on" value="1" <?php echo !empty($v['on']) ? 'checked' : ''; ?> style="width:17px;height:17px"> ارسال این پیامک فعال باشد</label><?php else: ?><input type="hidden" name="on" value="1"><?php endif; ?>
            <div style="margin:8px 0">متغیرها: <?php foreach ($t['vars'] as $vn => $vs): ?><span class="vchip" title="نمونه: <?php echo admin_h($vs); ?>" onclick="insV(this,'{<?php echo $vn; ?>}')">{<?php echo $vn; ?>}</span><?php endforeach; ?></div>
            <div style="display:flex;gap:16px;margin:6px 0">
                <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="mode" value="text" <?php echo $v['mode'] === 'text' ? 'checked' : ''; ?> onchange="tplMode(this)"> متن آزاد</label>
                <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="mode" value="lookup" <?php echo $v['mode'] === 'lookup' ? 'checked' : ''; ?> onchange="tplMode(this)"> الگوی کاوه‌نگار</label>
            </div>
            <label>متن پیامک <?php echo $v['mode'] === 'lookup' ? '(برای ارسال متنی پشتیبان و پنل‌های اختصاصی)' : ''; ?></label>
            <textarea name="text" rows="3" maxlength="600" style="width:100%"><?php echo admin_h($v['text']); ?></textarea>
            <div class="lk" style="<?php echo $v['mode'] === 'lookup' ? '' : 'display:none'; ?>;margin-top:8px">
                <div class="row2">
                    <div><label>نام الگو (Template) در کاوه‌نگار</label><input type="text" name="lookup" dir="ltr" value="<?php echo admin_h($v['lookup']); ?>" placeholder="login"></div>
                    <div><label style="display:flex;gap:8px;align-items:center;margin-top:28px"><input type="checkbox" name="fallback" value="1" <?php echo !empty($v['fallback']) ? 'checked' : ''; ?> style="width:17px;height:17px"> ارسال متنی در صورت خطای الگو</label></div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                <?php foreach (comm_lookup_tokens() as $tk): ?>
                    <div style="min-width:130px"><label style="direction:ltr;text-align:left">%<?php echo $tk; ?></label>
                        <select name="map_<?php echo $tk; ?>"><option value="">—</option><?php foreach ($t['vars'] as $vn => $vs): ?><option value="<?php echo $vn; ?>" <?php echo ($v['map'][$tk] ?? '') === $vn ? 'selected' : ''; ?>><?php echo '{' . $vn . '}'; ?></option><?php endforeach; ?></select></div>
                <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center">
                <button class="btn btn-primary btn-sm">💾 ذخیره الگو</button>
            </div>
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center;border-top:1px dashed #e2e8f0;padding-top:8px">
            <form method="post" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tpl_test"><input type="hidden" name="key" value="<?php echo $key; ?>">
                <input type="text" name="test_mobile" dir="ltr" placeholder="09121234567" value="<?php echo admin_h($tm); ?>" style="width:150px !important">
                <button class="btn btn-outline btn-sm">📤 ارسال آزمایشی (با مقادیر نمونه)</button>
            </form>
            <form method="post" onsubmit="return confirm('الگو به حالت پیش‌فرض برگردد؟');">
                <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="tpl_reset"><input type="hidden" name="key" value="<?php echo $key; ?>">
                <button class="btn btn-outline btn-sm">↺ پیش‌فرض</button>
            </form>
            <span class="muted">پیش‌نمایش: <?php echo admin_h(comm_sms_render($v['text'], $t['vars'])); ?></span>
        </div>
    </div>
</details>
<?php endforeach; endforeach; ?>
<?php
$logs = [];
try { $logs = $pdo->query("SELECT * FROM saas_sms_log ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) {}
$tt = comm_sms_types();
?>
<div class="card log" style="margin-top:16px">
    <h3 style="font-size:14px;margin-bottom:8px">📋 آخرین پیامک‌های ارسالی</h3>
    <?php if (!$logs): ?><p class="muted">هنوز پیامکی ثبت نشده است.</p><?php else: ?>
    <div class="table-wrap"><table style="width:100%"><thead><tr><th>زمان</th><th>گیرنده</th><th>نوع</th><th>منبع</th><th>نتیجه</th></tr></thead><tbody>
    <?php foreach ($logs as $l): $okk = !isset($l['ok']) || (int)$l['ok'] === 1; ?>
        <tr><td><?php echo admin_h(biz_jdate($l['created_at'], true)); ?></td><td dir="ltr"><?php echo admin_h(comm_mask_mobile($l['mobile'])); ?></td>
            <td><?php echo admin_h(isset($l['tpl']) && isset($tt[$l['tpl']]) ? $tt[$l['tpl']]['title'] : $l['kind']); ?></td>
            <td><?php echo admin_h(['platform' => 'سامانه', 'own' => 'پنل اختصاصی ربات', 'free' => 'سهمیه رایگان کاربر', 'paid' => 'بسته پیامک کاربر'][$l['source']] ?? $l['source']); ?></td>
            <td><?php echo $okk ? '<span class="pill ok">ارسال شد</span>' : '<span class="pill no">ناموفق</span> <small style="color:#991b1b">' . admin_h($l['err'] ?? '') . '</small>'; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<script>
function tplMode(r){ var f = r.form, lk = f.querySelector('input[name=mode][value=lookup]'); f.querySelector('.lk').style.display = lk && lk.checked ? '' : 'none'; }
function insV(ch, v){ var ta = ch.closest('form').querySelector('textarea[name=text]'); var p = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, p) + v + ta.value.slice(p); ta.focus(); }
(function(){ var h = location.hash; if (h && h.indexOf('#t_') === 0) { var d = document.querySelector(h); if (d) d.open = true; } })();
</script>

<?php else: $mc = comm_mail_cfg($pdo); $row = saas_get_api_config($pdo); $last = json_decode((string)biz_get($pdo, 'mail_last'), true); $log = $_SESSION['mail_test_log'] ?? null; unset($_SESSION['mail_test_log']); ?>
<div class="card" style="font-size:13px;line-height:2.1">
    ایمیل برای <b>بازیابی رمز عبور</b> کاربران استفاده می‌شود.
    <br>💡 بهترین روش: یک ایمیل روی همین هاست بسازید (cPanel ← Email Accounts، مثلاً <span dir="ltr">noreply@<?php echo admin_h($mc['domain']); ?></span>) و اطلاعات SMTP آن را وارد کنید: سرور <span dir="ltr">mail.<?php echo admin_h($mc['domain']); ?></span>، پورت ۴۶۵ (SSL) یا ۵۸۷ (TLS)، نام کاربری = همان ایمیل.
    <br><span class="muted">سرورهای داخل ایران معمولاً به SMTP گوگل (Gmail) دسترسی ندارند. اگر سرور SMTP خالی بماند، از تابع mail خود هاست استفاده می‌شود که ممکن است به پوشه Spam برود.</span>
    <?php if (is_array($last)): ?><br>آخرین ارسال: <?php echo !empty($last['ok']) ? '<span class="pill ok">موفق</span>' : '<span class="pill no">ناموفق</span> <small style="color:#991b1b">' . admin_h($last['error'] ?? '') . '</small>'; ?> <span class="muted">(<?php echo admin_h(biz_jdate($last['at'] ?? '', true)); ?>)</span><?php endif; ?>
</div>
<div class="card">
    <form method="post" data-pg="mail">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><input type="checkbox" name="email_enabled" value="1" <?php echo !empty($row['email_enabled']) ? 'checked' : ''; ?> style="width:17px;height:17px"> فعال‌سازی بازیابی رمز با ایمیل</label>
        <div class="row2">
            <div><label>سرور SMTP</label><input type="text" name="smtp_host" dir="ltr" value="<?php echo admin_h($row['smtp_host'] ?? ''); ?>" placeholder="mail.<?php echo admin_h($mc['domain']); ?>"></div>
            <div style="display:flex;gap:8px">
                <div style="flex:1"><label>پورت</label><input type="number" name="smtp_port" dir="ltr" value="<?php echo (int)($row['smtp_port'] ?? 587) ?: 587; ?>"></div>
                <div style="flex:1.3"><label>نوع اتصال</label><select name="smtp_secure">
                    <?php foreach (['auto' => 'خودکار (۴۶۵=SSL، بقیه TLS)', 'ssl' => 'SSL', 'tls' => 'TLS (STARTTLS)', 'none' => 'بدون رمزنگاری'] as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $mc['secure'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
            </div>
        </div>
        <div class="row2">
            <div><label>نام کاربری SMTP</label><input type="text" name="smtp_user" dir="ltr" value="<?php echo admin_h($row['smtp_user'] ?? ''); ?>" autocomplete="off" placeholder="noreply@<?php echo admin_h($mc['domain']); ?>"></div>
            <div><label>رمز SMTP <?php echo ($row['smtp_pass'] ?? '') !== '' ? '(ذخیره شده — برای تغییر پر کنید)' : ''; ?></label><input type="password" name="smtp_pass" dir="ltr" value="" autocomplete="new-password"></div>
        </div>
        <div class="row2">
            <div><label>ایمیل فرستنده (From)</label><input type="email" name="smtp_from" dir="ltr" value="<?php echo admin_h($row['smtp_from'] ?? ''); ?>" placeholder="همان نام کاربری SMTP"></div>
            <div><label>نام فرستنده</label><input type="text" name="smtp_from_name" value="<?php echo admin_h($row['smtp_from_name'] ?? ''); ?>" placeholder="<?php echo admin_h($mc['from_name']); ?>"></div>
        </div>
        <p class="muted">فرستنده فعلی: <span dir="ltr"><?php echo admin_h($mc['from_name'] . ' <' . $mc['from'] . '>'); ?></span></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
            <button class="btn btn-primary btn-sm" name="act" value="mail_save">💾 ذخیره</button>
            <input type="email" name="test_to" dir="ltr" placeholder="ایمیل گیرنده آزمایشی" value="<?php echo admin_h((string)biz_get($pdo, 'mail_test_to')); ?>" style="width:230px !important">
            <button class="btn btn-outline btn-sm" name="act" value="mail_test">📤 ذخیره و ارسال ایمیل آزمایشی</button>
        </div>
    </form>
    <?php if (is_array($log) && $log): ?>
    <details open style="margin-top:12px"><summary style="cursor:pointer;font-size:13px;font-weight:600">گزارش گفتگو با سرور ایمیل</summary>
        <pre dir="ltr" style="background:#0f172a;color:#e2e8f0;border-radius:10px;padding:10px;font-size:11.5px;white-space:pre-wrap;max-height:300px;overflow:auto;margin-top:6px"><?php echo admin_h(implode("\n", $log)); ?></pre></details>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_footer.php'; ?>
