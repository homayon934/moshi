<?php
require_once __DIR__ . '/_bootstrap.php';
$cfg = saas_get_api_config($pdo);
$saved = false;
$form_error = '';
$zp_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $form_error = 'نشست منقضی شده است؛ صفحه را دوباره باز کنید و ذخیره کنید.';
    $_SESSION['api_flash'] = ['saved' => false, 'form_error' => $form_error, 'zp' => null];
    header('Location: api_settings.php'); exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // کد پذیرنده زرین‌پال: حذف فاصله و کاراکترهای نامرئی (کپی از پنل)
    $zp_m = comm_zp_clean_merchant($_POST['zarinpal_merchant_id'] ?? '');
    if ($zp_m !== '' && !comm_zp_valid_merchant($zp_m)) $form_error = 'کد پذیرنده زرین‌پال (Merchant ID) باید ۳۶ کاراکتر به شکل xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx باشد. سایر تنظیمات ذخیره شد.';
    $data = [
        'avalai_enabled'         => !empty($_POST['avalai_enabled']) ? 1 : 0,
        'avalai_api_key'         => trim($_POST['avalai_api_key'] ?? ''),
        'avalai_api_base'        => trim($_POST['avalai_api_base'] ?? 'https://api.avalai.ir/v1'),
        'avalai_model'           => (string)$cfg['avalai_model'],        // از صفحه «مدل‌ها» تنظیم می‌شود
        'openrouter_enabled'     => !empty($_POST['openrouter_enabled']) ? 1 : 0,
        'openrouter_api_key'     => trim($_POST['openrouter_api_key'] ?? ''),
        'openrouter_api_base'    => trim($_POST['openrouter_api_base'] ?? 'https://openrouter.ai/api/v1'),
        'openrouter_model'       => (string)$cfg['openrouter_model'],
        'provider_priority'      => (string)$cfg['provider_priority'],
        'cost_per_1k_tokens'     => (float)$cfg['cost_per_1k_tokens'],   // از صفحه «مدل‌ها و قیمت توکن» تنظیم می‌شود
        'sms_enabled'            => !empty($_POST['sms_enabled']) ? 1 : 0,
        'sms_provider'           => trim($_POST['sms_provider'] ?? 'kavenegar'),
        'sms_api_key'            => trim($_POST['sms_api_key'] ?? ''),
        'sms_sender'             => trim($_POST['sms_sender'] ?? ''),
        'sms_low_credit_percent' => max(5, min(50, (int)($_POST['sms_low_credit_percent'] ?? 20))),
        'zarinpal_merchant_id'   => ($zp_m === '' || comm_zp_valid_merchant($zp_m)) ? $zp_m : (string)$cfg['zarinpal_merchant_id'],
        'zarinpal_sandbox'       => !empty($_POST['zarinpal_sandbox']) ? 1 : 0,
        'updated_at'             => date('Y-m-d H:i:s'),
    ];
    // فروش پیامک و یادآوری‌ها (تنظیمات تجاری)
    biz_set($pdo, 'sms_price_toman', max(1, (int)str_replace(',', '', (string)($_POST['sms_price_toman'] ?? 150))));
    biz_set($pdo, 'sms_min_purchase', max(1, (int)($_POST['sms_min_purchase'] ?? 100)));
    biz_set($pdo, 'user_remind_sms', !empty($_POST['user_remind_sms']) ? 1 : 0);
    // ورود با گوگل (روشن/خاموش و اولویت در «پیامک، ورود و ایمیل»)
    biz_set($pdo, 'google_client_id', mb_substr(trim($_POST['google_client_id'] ?? ''), 0, 200));
    if (trim($_POST['google_client_secret'] ?? '') !== '') biz_set($pdo, 'google_client_secret', mb_substr(trim($_POST['google_client_secret']), 0, 200));
    $sets = array_map(fn($k) => "`$k`=?", array_keys($data));
    $pdo->prepare("UPDATE saas_api_config SET " . implode(',', $sets) . " WHERE id=1")->execute(array_values($data));
    $cfg = saas_get_api_config($pdo);
    $saved = true;
    if (!empty($_POST['zp_test'])) $zp_result = comm_zp_test($pdo, (string)$cfg['zarinpal_merchant_id'], !empty($cfg['zarinpal_sandbox']));
    // نمایش نتیجه پس از بازگشت (جلوگیری از ارسال دوباره فرم با رفرش)
    $_SESSION['api_flash'] = ['saved' => $saved, 'form_error' => $form_error, 'zp' => $zp_result];
    header('Location: api_settings.php' . ($zp_result ? '#zp' : '')); exit;
}
if (!empty($_SESSION['api_flash'])) {
    $saved = !empty($_SESSION['api_flash']['saved']);
    $form_error = (string)($_SESSION['api_flash']['form_error'] ?? $form_error);
    $zp_result = $_SESSION['api_flash']['zp'] ?? null;
    unset($_SESSION['api_flash']);
}

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">⚙️ تنظیمات مرکزی API</h2>';
?>

<?php if ($form_error !== ''): ?><div class="msg-err">⚠️ <?php echo admin_h($form_error); ?></div><?php endif; ?>
<?php if ($saved && $form_error === ''): ?><div class="msg-ok">✅ تنظیمات با موفقیت ذخیره شد.</div><?php endif; ?>


<div class="alert-info-box" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#1d4ed8">
    🔒 این صفحه فقط در دسترس ادمین اصلی است. کلیدهای API اینجا تنظیم می‌شوند و کاربران دسترسی ندارند.
</div>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">

    <?php
    $cnt_models = function ($prov) use ($pdo) { $n = 0; foreach (biz_models($pdo, false, null, true) as $m) if ($m['provider'] === $prov) $n++; return $n; };
    ?>
    <div class="card">
        <h3 style="font-size:14px;margin-bottom:8px">🧠 مدل‌ها و قیمت‌ها</h3>
        <p class="muted" style="line-height:2">تعریف همه مدل‌ها (متنی، صوتی، تصویر، ویدیو)، مدل‌های جایگزین، قیمت هر مدل و اتصال به بخش‌ها از صفحه <a href="models.php">🧠 مدل‌ها و قیمت‌ها</a> انجام می‌شود. اینجا فقط کلید و آدرس سرویس‌دهنده‌ها را وارد کنید.
        <br>اگر یک سرویس‌دهنده را خاموش کنید، <b>همه مدل‌های آن</b> هم خودکار از کار می‌افتند و مدل‌های جایگزین سرویس‌دهنده دیگر پاسخ می‌دهند.</p>
    </div>

    <!-- AvalAI -->
    <div class="provider-box">
        <h3>🤖 سرویس AvalAI</h3>
        <label style="margin-bottom:12px;display:block"><input type="checkbox" name="avalai_enabled" value="1" <?php echo $cfg['avalai_enabled'] ? 'checked' : ''; ?>> فعال‌سازی AvalAI</label>
        <div class="row2">
            <div>
                <label>API Key</label>
                <input type="text" name="avalai_api_key" dir="ltr" value="<?php echo htmlspecialchars($cfg['avalai_api_key']); ?>" autocomplete="off">
            </div>
            <div>
                <label>API Base URL</label>
                <input type="text" name="avalai_api_base" dir="ltr" value="<?php echo htmlspecialchars($cfg['avalai_api_base']); ?>">
            </div>
        </div>
        <p class="muted" style="margin-top:6px">مدل‌های تعریف‌شده با این سرویس‌دهنده: <b><?php echo $cnt_models('avalai'); ?></b><?php echo !$cfg['avalai_enabled'] && $cnt_models('avalai') ? ' — <span style="color:#dc2626">⛔ خاموش است؛ این مدل‌ها استفاده نمی‌شوند.</span>' : ''; ?></p>
    </div>

    <!-- OpenRouter -->
    <div class="provider-box">
        <h3>🌐 سرویس OpenRouter</h3>
        <label style="margin-bottom:12px;display:block"><input type="checkbox" name="openrouter_enabled" value="1" <?php echo $cfg['openrouter_enabled'] ? 'checked' : ''; ?>> فعال‌سازی OpenRouter</label>
        <div class="row2">
            <div>
                <label>API Key</label>
                <input type="text" name="openrouter_api_key" dir="ltr" value="<?php echo htmlspecialchars($cfg['openrouter_api_key']); ?>" autocomplete="off" placeholder="sk-or-v1-...">
            </div>
            <div>
                <label>API Base URL</label>
                <input type="text" name="openrouter_api_base" dir="ltr" value="<?php echo htmlspecialchars($cfg['openrouter_api_base']); ?>">
            </div>
        </div>
        <p class="muted" style="margin-top:6px">مدل‌های تعریف‌شده با این سرویس‌دهنده: <b><?php echo $cnt_models('openrouter'); ?></b><?php echo !$cfg['openrouter_enabled'] && $cnt_models('openrouter') ? ' — <span style="color:#dc2626">⛔ خاموش است؛ این مدل‌ها استفاده نمی‌شوند.</span>' : ''; ?></p>
    </div>

    <!-- پرداخت زرین‌پال -->
    <div class="provider-box" style="border-color:#bbe0f7" id="zp">
        <h3>💳 درگاه پرداخت زرین‌پال</h3>
        <?php if ($zp_result): ?><div class="msg-<?php echo $zp_result['ok'] ? 'ok' : 'err'; ?>" style="margin-bottom:10px"><?php echo admin_h($zp_result['ok'] ? $zp_result['msg'] : $zp_result['error']); ?></div><?php endif; ?>
        <div class="row2">
            <div>
                <label>Merchant ID (کد ۳۶ کاراکتری)</label>
                <input type="text" name="zarinpal_merchant_id" dir="ltr" value="<?php echo htmlspecialchars($cfg['zarinpal_merchant_id']); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            </div>
            <div>
                <label style="padding-top:28px;display:block">
                    <input type="checkbox" name="zarinpal_sandbox" value="1" <?php echo $cfg['zarinpal_sandbox'] ? 'checked' : ''; ?>>
                    حالت آزمایشی (sandbox) — برای تست
                </label>
            </div>
        </div>
        <p class="muted" style="line-height:2">Merchant ID را از پنل زرین‌پال (zarinpal.com ← درگاه‌ها ← تنظیمات) کپی کنید. برای پرداخت واقعی تیک sandbox باید <b>خاموش</b> باشد.
            دامنه ثبت‌شده برای درگاه در زرین‌پال باید همین سایت (<span dir="ltr"><?php echo admin_h((string)parse_url(AICHAT_BASE_URL, PHP_URL_HOST)); ?></span>) باشد.
            <?php if (!empty($cfg['zarinpal_sandbox'])): ?><br><b style="color:#b45309">⚠️ حالت آزمایشی روشن است؛ پرداخت‌ها واقعی نیستند.</b><?php endif; ?></p>
        <button type="submit" name="zp_test" value="1" class="btn btn-outline btn-sm" style="margin-top:6px">🔌 ذخیره و آزمایش اتصال درگاه</button>
        <?php
        $zp_errs = [];
        try { $zp_errs = $pdo->query("SELECT COUNT(*) FROM saas_payments WHERE status='pending' AND (authority='' OR authority IS NULL) AND created_at >= '" . date('Y-m-d H:i:s', time() - 7 * 86400) . "'")->fetchColumn(); } catch (\Throwable $e) {}
        $zle = json_decode((string)biz_get($pdo, 'zp_last_error'), true);
        if (is_array($zle) && !empty($zle['at']) && strtotime($zle['at']) > time() - 7 * 86400): ?><p class="muted" style="margin-top:6px;color:#b91c1c">آخرین خطای درگاه (<?php echo admin_h(biz_jdate($zle['at'], true)); ?>): کد <?php echo admin_h($zle['code']); ?> — <?php echo admin_h(comm_zp_error((int)$zle['code'])); ?><?php if (!empty($zle['detail']) && mb_substr((string)$zle['detail'], 0, 1) !== '{'): ?><br><span dir="auto" style="font-size:12px"><?php echo admin_h($zle['detail']); ?></span><?php endif; ?></p><?php endif;
        if ((int)$zp_errs > 0): ?><p class="muted" style="margin-top:6px">در ۷ روز گذشته <?php echo (int)$zp_errs; ?> تلاش پرداخت به درگاه نرسید؛ با دکمه بالا علت را ببینید.</p><?php endif; ?>
    </div>

<?php $bsx = biz_settings($pdo, true); $cron_url = rtrim(AICHAT_BASE_URL, '/') . '/cron.php?key=' . $bsx['cron_key']; ?>
    <!-- فروش پیامک و یادآوری اقساط -->
    <div class="provider-box" style="border-color:#c7d2fe">
        <h3>💬 فروش پیامک به کاربران و یادآوری‌ها</h3>
        <div class="row2">
            <div>
                <label>قیمت هر پیامک برای کاربران (تومان)</label>
                <input type="number" name="sms_price_toman" min="1" value="<?php echo (int)$bsx['sms_price_toman']; ?>">
            </div>
            <div>
                <label>حداقل تعداد خرید پیامک</label>
                <input type="number" name="sms_min_purchase" min="1" value="<?php echo (int)$bsx['sms_min_purchase']; ?>">
            </div>
        </div>
        <label style="margin-top:10px;display:block"><input type="checkbox" name="user_remind_sms" value="1" <?php echo !empty($bsx['user_remind_sms']) ? 'checked' : ''; ?>> پیامک یادآوری تمدید اشتراک به کاربران (۳۰، ۷، ۳ و ۱ روز مانده، پایان اشتراک و زمان توقف — همراه با پیشنهاد تخفیف تمدید زودهنگام) — اعلان داخل پنل همیشه ارسال می‌شود</label>
        <p class="muted" style="margin-top:8px;line-height:2">
            پیامک رایگان هر پلن در صفحه <a href="plans.php">پلن‌ها</a> تعیین می‌شود. پیامک‌های مخاطبانِ کاربران (کد ورود چت‌بات، یادآوری بسته‌ها) از سهمیه همان کاربر کم می‌شود؛ پیامک‌های سامانه به خود کاربران (یادآوری اشتراک) رایگان است.
            در هیچ پیامکی نام سامانه آورده نمی‌شود.
        </p>
        <p class="muted" style="margin-top:6px;line-height:2">
            ⏰ یادآوری‌ها به‌طور خودکار با بازدید سایت/پنل اجرا می‌شوند. برای دقت بیشتر، در cPanel بخش <b>Cron Jobs</b> یک کار روزانه (مثلاً هر ساعت) با این دستور بسازید:<br>
            <code dir="ltr" style="display:block;background:#0f172a;color:#e2e8f0;padding:8px 10px;border-radius:8px;margin-top:4px;font-size:12px;word-break:break-all">wget -q -O /dev/null "<?php echo admin_h($cron_url); ?>"</code>
        </p>
    </div>

    <!-- ورود با گوگل -->
    <div class="provider-box" style="border-color:#bfdbfe">
        <h3>🔐 ورود با حساب گوگل (Gmail)</h3>
        <p class="muted" style="margin-bottom:10px">روشن/خاموش کردن ورود با گوگل و اولویت آن برای هر بخش (کاربران، مخاطبان چت‌بات، همکاری در فروش، مدیر) در صفحه <a href="auth_settings.php">🔐 پیامک، ورود و ایمیل</a> است.</p>
        <div class="row2">
            <div><label>Client ID</label><input type="text" name="google_client_id" dir="ltr" value="<?php echo admin_h($bsx['google_client_id']); ?>" autocomplete="off" placeholder="xxxx.apps.googleusercontent.com"></div>
            <div><label>Client Secret <?php echo $bsx['google_client_secret'] !== '' ? '(ذخیره شده — برای تغییر پر کنید)' : ''; ?></label><input type="password" name="google_client_secret" dir="ltr" value="" autocomplete="new-password"></div>
        </div>
        <p class="muted" style="margin-top:8px;line-height:2">
            در <b dir="ltr">console.cloud.google.com</b> ← APIs &amp; Services ← Credentials ← Create OAuth client ID (نوع Web application) بسازید و این آدرس را در بخش <b>Authorized redirect URIs</b> وارد کنید:<br>
            <code dir="ltr" style="display:block;background:#0f172a;color:#e2e8f0;padding:8px 10px;border-radius:8px;margin-top:4px;font-size:12px;word-break:break-all"><?php echo admin_h(biz_google_redirect_uri()); ?></code>
            برای ورود مخاطبان چت‌بات‌ها، این آدرس را هم در همان بخش اضافه کنید:
            <code dir="ltr" style="display:block;background:#0f172a;color:#e2e8f0;padding:8px 10px;border-radius:8px;margin-top:4px;font-size:12px;word-break:break-all"><?php echo admin_h(rtrim(AICHAT_BASE_URL, '/') . '/hamdam/google.php'); ?></code>
            ⚠️ سرور سایت باید به <span dir="ltr">oauth2.googleapis.com</span> دسترسی داشته باشد (برخی سرورهای داخل ایران به سرویس‌های گوگل دسترسی ندارند).
        </p>
    </div>

    <!-- SMS -->
    <div class="provider-box" style="border-color:#d9f99d">
        <h3>📱 سرویس پیامک (کاوه‌نگار)</h3>
        <label style="margin-bottom:12px;display:block"><input type="checkbox" name="sms_enabled" value="1" <?php echo $cfg['sms_enabled'] ? 'checked' : ''; ?>> فعال‌سازی ارسال پیامک</label>
        <div class="row2">
            <div>
                <label>کلید API کاوه‌نگار</label>
                <input type="text" name="sms_api_key" dir="ltr" value="<?php echo htmlspecialchars($cfg['sms_api_key']); ?>" autocomplete="off">
            </div>
            <div>
                <label>شماره فرستنده</label>
                <input type="text" name="sms_sender" dir="ltr" value="<?php echo htmlspecialchars($cfg['sms_sender']); ?>" placeholder="10008663">
            </div>
        </div>
        <label>درصد اعتبار کم برای ارسال هشدار (%)</label>
        <input type="number" name="sms_low_credit_percent" value="<?php echo (int)$cfg['sms_low_credit_percent']; ?>" min="5" max="50" style="width:120px !important">
        <p class="muted" style="margin-top:6px">وقتی اعتبار کاربر به زیر این درصد از اعتبار اولیه پلن رسید، پیامک هشدار ارسال می‌شود.</p>
        <p class="muted" style="margin-top:6px">متن و الگوی هر پیامک (کد ورود، یادآوری‌ها و …) در صفحه <a href="auth_settings.php?tab=sms">📱 الگوهای پیامک</a> تعریف می‌شود.</p>
    </div>

    <div class="provider-box" style="border-color:#fde68a">
        <h3>📧 سرویس ایمیل</h3>
        <p class="muted">تنظیمات SMTP، ارسال ایمیل آزمایشی و گزارش خطا در صفحه <a href="auth_settings.php?tab=email">📧 ایمیل</a> است.</p>
    </div>

    <div style="margin-bottom:30px"><button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button></div>
</form>

<?php
// زیردامنه‌های اختصاصی چت‌بات‌ها (برای افزودن در cPanel)
$hd_domains = [];
try {
    require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
    hd_ensure_schema($pdo);
    $hd_domains = $pdo->query("SELECT b.id, b.name, b.custom_domain, u.email FROM hd_bots b LEFT JOIN saas_users u ON u.id=b.user_id WHERE b.custom_domain<>'' ORDER BY b.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}
$hd_host = strtolower((string)parse_url(AICHAT_BASE_URL, PHP_URL_HOST));
$hd_ip = @gethostbyname($hd_host);
?>
<div class="card">
    <h3 style="font-size:14px;margin-bottom:10px">🌐 زیردامنه‌های اختصاصی چت‌بات‌ها</h3>
    <p class="muted" style="line-height:2">برای هر زیردامنه با وضعیت «✅ آماده»: در cPanel بخش <b>Domains</b> (یا Addon Domains) دامنه را اضافه کنید و مسیر ریشه (Document Root) را
        <code dir="ltr">public_html/ai-chat/hamdam/host</code> بگذارید؛ سپس از بخش <b>SSL/TLS Status</b> گزینه <b>Run AutoSSL</b> را بزنید.</p>
    <?php if (!$hd_domains): ?>
        <p class="muted">هنوز زیردامنه‌ای ثبت نشده است.</p>
    <?php else: ?>
    <table style="width:100%;font-size:13px;border-collapse:collapse">
        <tr style="background:#f8fafc"><th style="padding:6px;text-align:right">زیردامنه</th><th style="text-align:right">ربات</th><th style="text-align:right">کاربر</th><th style="text-align:right">DNS</th></tr>
        <?php foreach ($hd_domains as $d): $ip = @gethostbyname($d['custom_domain']); $ok = ($ip !== $d['custom_domain'] && $ip === $hd_ip); ?>
        <tr style="border-top:1px solid #eef2f7"><td style="padding:6px;direction:ltr;text-align:right"><?php echo htmlspecialchars($d['custom_domain']); ?></td>
            <td><?php echo htmlspecialchars($d['name']); ?></td><td style="direction:ltr;text-align:right"><?php echo htmlspecialchars((string)$d['email']); ?></td>
            <td><?php echo $ok ? '✅ آماده' : '⏳ هنوز به سرور اشاره نمی‌کند'; ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<style>
.provider-box{background:#fdfdfd;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:18px}
.provider-box h3{margin-bottom:14px;font-size:14px;color:#2b2733}
</style>

<?php include __DIR__ . '/_footer.php'; ?>
