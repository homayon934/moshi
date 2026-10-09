<?php
require_once __DIR__ . '/_bootstrap.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $msg = 'error|خطای امنیتی. صفحه را رفرش کنید.';
    } else {
        $keys = [
            // اطلاعات پایه
            'site_name','site_tagline','site_description',
            'site_logo_url','site_favicon_url','footer_text',
            // اینترو
            'intro_enabled','intro_image_url','intro_title','intro_subtitle',
            // نوار اطلاعیه
            'notice_bar_text','notice_bar_link','notice_bar_show',
            // سئو پایه
            'og_image','twitter_handle','ga_id',
            'meta_robots','site_verification_google',
            // کسب‌وکار محلی (Local SEO)
            'business_type','business_address','business_city',
            'business_phone','business_email','business_opening_hours',
            'business_area_served',
            // صفحه اصلی (نسخه ۴۰)
            'home_style','home_title','home_subtitle','home_btn1_text','home_btn1_url','home_btn2_text','home_btn2_url','home_sections','home_menu_extra',
            'glass_1_text','glass_1_url','glass_2_text','glass_2_url','glass_3_text','glass_3_url',
            'sitechat_on','sitechat_label','sitechat_title','sitechat_welcome','sitechat_suggest','sitechat_knowledge','sitechat_general','sitechat_daily','sitechat_model_id',
            'badge_1_img','badge_1_url','badge_1_alt','badge_1_code','badge_2_img','badge_2_url','badge_2_alt','badge_2_code','badge_3_img','badge_3_url','badge_3_alt','badge_3_code',
        ];
        foreach ($keys as $k) {
            if (in_array($k, ['intro_enabled', 'notice_bar_show', 'home_sections', 'sitechat_on', 'sitechat_general'])) {
                $val = isset($_POST[$k]) ? '1' : '0';
            } elseif ($k === 'home_style') {
                $val = ($_POST[$k] ?? '') === 'classic' ? 'classic' : 'cosmic';
            } elseif ($k === 'sitechat_daily' || $k === 'sitechat_model_id') {
                $val = (string)max(0, min(1000, (int)($_POST[$k] ?? 0)));
            } elseif ($k === 'sitechat_knowledge') {
                $val = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 12000);
            } elseif (preg_match('/^badge_\d_code$/', $k)) {
                $val = mb_substr(trim((string)($_POST[$k] ?? '')), 0, 6000);   // کد نماد (HTML) — فقط مدیر کل
            } else {
                $val = isset($_POST[$k]) ? trim($_POST[$k]) : '';
            }
            site_save_setting($pdo, $k, $val);
        }
        $msg = 'ok|تنظیمات با موفقیت ذخیره شد.';
    }
}

$s = site_settings($pdo);
?>
<?php include __DIR__ . '/_header.php'; ?>
<div class="container-fluid py-4 px-4">
<h2 class="fw-bold mb-4" style="font-size:20px">تنظیمات سایت</h2>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
<div class="<?php echo $type === 'ok' ? 'msg-ok' : 'msg-err'; ?>"><?php echo admin_h($text); ?></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="save_settings" value="1">
<input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">

<!-- ── اطلاعات پایه ── -->
<div class="card">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>اطلاعات پایه سایت</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label>نام سایت</label>
                <input type="text" name="site_name" value="<?php echo admin_h($s['site_name'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label>شعار (Tagline)</label>
                <input type="text" name="site_tagline" value="<?php echo admin_h($s['site_tagline'] ?? ''); ?>">
            </div>
            <div class="col-12">
                <label>توضیح سایت</label>
                <textarea name="site_description" rows="2"><?php echo admin_h($s['site_description'] ?? ''); ?></textarea>
            </div>

            <!-- لوگو -->
            <div class="col-md-6">
                <label>لوگو سایت</label>
                <?php $logoUrl = admin_h($s['site_logo_url'] ?? ''); ?>
                <input type="hidden" name="site_logo_url" id="logo_url_hidden" value="<?php echo $logoUrl; ?>">
                <div class="upload-box">
                    <?php if ($logoUrl): ?>
                    <img id="logo_preview" src="<?php echo $logoUrl; ?>" class="upload-preview-img" alt="" style="height:40px;width:auto;max-width:120px">
                    <?php else: ?>
                    <img id="logo_preview" src="" class="upload-preview-img" alt="" style="display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>آپلود لوگو (PNG/SVG شفاف)
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'logo_preview','logo_url_hidden')">
                    </label>
                </div>
            </div>

            <!-- فاویکون -->
            <div class="col-md-6">
                <label>فاویکون سایت</label>
                <?php $faviUrl = admin_h($s['site_favicon_url'] ?? ''); ?>
                <input type="hidden" name="site_favicon_url" id="favicon_url_hidden" value="<?php echo $faviUrl; ?>">
                <div class="upload-box">
                    <?php if ($faviUrl): ?>
                    <img id="favi_preview" src="<?php echo $faviUrl; ?>" class="upload-preview-img" alt="" style="height:32px;width:32px">
                    <?php else: ?>
                    <img id="favi_preview" src="" class="upload-preview-img" alt="" style="height:32px;width:32px;display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>آپلود فاویکون (۳۲×۳۲ px)
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'favi_preview','favicon_url_hidden')">
                    </label>
                </div>
            </div>

            <div class="col-md-8">
                <label>متن کپی‌رایت (پایین‌ترین قسمت صفحه اصلی)</label>
                <input type="text" name="footer_text" value="<?php echo admin_h($s['footer_text'] ?? ''); ?>" placeholder="© ۱۴۰۴ — نام شما. کلیه حقوق محفوظ.">
            </div>
        </div>
    </div>
</div>

<!-- ── دستیار گفتگوی صفحه اصلی (نسخه ۴۱) ── -->
<?php $scm = []; try { $scm = function_exists('biz_models') ? biz_models($pdo, true, 'text') : []; } catch (\Throwable $e) {} $scsel = (int)($s['sitechat_model_id'] ?? 0); ?>
<div class="card" id="sitechat-settings">
    <div class="card-header"><i class="bi bi-chat-dots me-2"></i>دستیار گفتگوی صفحه اصلی <a href="site_chats.php" style="font-size:12.5px;margin-right:10px">📜 گفتگوهای بازدیدکنندگان</a> <a href="site_bot.php" style="font-size:12.5px;margin-right:10px">🌐 حالت کامل (پیام رایگان، بسته‌ها، مدل، ساخت تصویر…)</a></div>
    <div class="card-body">
        <p class="muted" style="font-size:12.5px;line-height:2;margin-bottom:10px">بازدیدکننده با کلیک روی منوی «💬 …» بالای صفحه اصلی، پنجره گفتگو را وسط صفحه باز می‌کند. دستیار به هر سؤالی پاسخ می‌دهد و درباره خدمات سایت، فقط از روی امکانات (کاشی‌ها)، پلن‌ها، صفحه‌ها، «راهنما و سوالات متداول» پشتیبانی و «دانش اضافه» زیر جواب می‌دهد. هزینه از حساب سامانه است.<br><b>حالت کامل</b> (مثل چت‌بات‌های تخصصی: ورود، پیام رایگان، بسته‌ها، انتخاب مدل، ساخت تصویر و ویدیو، حافظه): <a href="site_bot.php">چت صفحه اصلی سایت</a><?php echo (($s['sitechat_full'] ?? '0') === '1') ? ' — <b style="color:#16a34a">فعال است</b>' : ''; ?></p>
        <div class="row g-3">
            <div class="col-md-4" style="display:flex;align-items:flex-end;gap:8px"><input type="checkbox" name="sitechat_on" id="sitechat_on" style="width:auto" <?php echo ($s['sitechat_on'] ?? '1') !== '0' ? 'checked' : ''; ?>><label for="sitechat_on" style="margin:0;cursor:pointer">فعال باشد</label></div>
            <div class="col-md-4" style="display:flex;align-items:flex-end;gap:8px"><input type="checkbox" name="sitechat_general" id="sitechat_general" style="width:auto" <?php echo ($s['sitechat_general'] ?? '1') !== '0' ? 'checked' : ''; ?>><label for="sitechat_general" style="margin:0;cursor:pointer">پاسخ به سؤال‌های عمومی (هر موضوعی)</label></div>
            <div class="col-md-4"><label>سقف پیام روزانه هر بازدیدکننده</label><input type="number" name="sitechat_daily" min="3" max="500" value="<?php echo (int)($s['sitechat_daily'] ?? 40) ?: 40; ?>"></div>
            <div class="col-md-4"><label>متن منو</label><input type="text" name="sitechat_label" value="<?php echo admin_h($s['sitechat_label'] ?? ''); ?>" placeholder="چت با ابزار مشاور من"></div>
            <div class="col-md-4"><label>عنوان پنجره</label><input type="text" name="sitechat_title" value="<?php echo admin_h($s['sitechat_title'] ?? ''); ?>" placeholder="دستیار هوشمند"></div>
            <div class="col-md-4"><label>مدل هوش مصنوعی</label><select name="sitechat_model_id"><option value="0">— مدل کارهای داخلی سامانه —</option><?php foreach ($scm as $mm): ?><option value="<?php echo (int)$mm['id']; ?>" <?php echo $scsel === (int)$mm['id'] ? 'selected' : ''; ?>><?php echo admin_h($mm['title'] . ' — ' . $mm['model_id']); ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label>پیام خوش‌آمد</label><input type="text" name="sitechat_welcome" value="<?php echo admin_h($s['sitechat_welcome'] ?? ''); ?>" placeholder="سلام 👋 هر سؤالی دارید بپرسید؛ درباره خدمات و پلن‌های سایت یا هر موضوع دیگری."></div>
            <div class="col-md-5"><label>پیشنهادهای آماده (هر خط یک سؤال)</label><textarea name="sitechat_suggest" rows="4" placeholder="چه خدماتی ارائه می‌دهید؟&#10;قیمت پلن‌ها چقدر است؟"><?php echo admin_h($s['sitechat_suggest'] ?? ''); ?></textarea></div>
            <div class="col-md-7"><label>دانش اضافه درباره کسب‌وکار و خدمات (اختیاری)</label><textarea name="sitechat_knowledge" rows="4" placeholder="مثلاً: نحوه پشتیبانی، شرایط بازگشت وجه، تخفیف‌های فعلی، پاسخ سؤال‌های رایج…"><?php echo admin_h($s['sitechat_knowledge'] ?? ''); ?></textarea></div>
        </div>
    </div>
</div>

<!-- ── صفحه اصلی (نسخه ۴۰) ── -->
<?php $hs = $s['home_style'] ?? 'cosmic'; ?>
<div class="card" id="home-settings">
    <div class="card-header"><i class="bi bi-stars me-2"></i>صفحه اصلی سایت</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label>طرح صفحه اصلی</label>
                <select name="home_style">
                    <option value="cosmic" <?php echo $hs !== 'classic' ? 'selected' : ''; ?>>🌌 کیهانی (منظومه شمسی متحرک)</option>
                    <option value="classic" <?php echo $hs === 'classic' ? 'selected' : ''; ?>>🖼 کلاسیک (اسلایدر قبلی)</option>
                </select>
            </div>
            <div class="col-md-6" style="display:flex;align-items:flex-end;gap:8px">
                <input type="checkbox" name="home_sections" id="home_sections" style="width:auto" <?php echo ($s['home_sections'] ?? '1') !== '0' ? 'checked' : ''; ?>>
                <label for="home_sections" style="margin:0;cursor:pointer">زیر صحنه، بخش‌های امکانات، مطالب و پلن‌ها هم نمایش داده شوند</label>
            </div>
            <div class="col-md-6"><label>عنوان وسط صحنه (اختیاری)</label><input type="text" name="home_title" value="<?php echo admin_h($s['home_title'] ?? ''); ?>" placeholder="خالی = بدون متن (فقط صحنه)"></div>
            <div class="col-md-6"><label>زیرعنوان (اختیاری)</label><input type="text" name="home_subtitle" value="<?php echo admin_h($s['home_subtitle'] ?? ''); ?>"></div>
            <div class="col-md-3"><label>دکمه ۱ — متن</label><input type="text" name="home_btn1_text" value="<?php echo admin_h($s['home_btn1_text'] ?? ''); ?>" placeholder="مثلاً: شروع رایگان"></div>
            <div class="col-md-3"><label>دکمه ۱ — لینک</label><input type="text" name="home_btn1_url" dir="ltr" value="<?php echo admin_h($s['home_btn1_url'] ?? ''); ?>" placeholder="user/register.php"></div>
            <div class="col-md-3"><label>دکمه ۲ — متن</label><input type="text" name="home_btn2_text" value="<?php echo admin_h($s['home_btn2_text'] ?? ''); ?>" placeholder="مثلاً: امکانات"></div>
            <div class="col-md-3"><label>دکمه ۲ — لینک</label><input type="text" name="home_btn2_url" dir="ltr" value="<?php echo admin_h($s['home_btn2_url'] ?? ''); ?>" placeholder="#features"></div>
            <div class="col-12"><label style="font-weight:700">🪟 سه منوی شیشه‌ای پایین تصویر کهکشان <small class="muted" style="font-weight:normal">(منوی بدون متن نمایش داده نمی‌شود؛ لینک می‌تواند آدرس کامل، مسیر داخل سایت مثل plans.php یا بخش صفحه مثل #plans باشد)</small></label></div>
            <?php for ($gi = 1; $gi <= 3; $gi++): ?>
            <div class="col-md-4" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px">
                <label>منو <?php echo $gi; ?> — متن</label><input type="text" name="glass_<?php echo $gi; ?>_text" maxlength="60" value="<?php echo admin_h($s['glass_' . $gi . '_text'] ?? ''); ?>" placeholder="<?php echo ['', 'مثلاً: 🩺 پزشک من', 'مثلاً: ⚖️ وکیل من', 'مثلاً: 🎨 گرافیست من'][$gi]; ?>">
                <label style="margin-top:6px">منو <?php echo $gi; ?> — لینک</label><input type="text" name="glass_<?php echo $gi; ?>_url" dir="ltr" value="<?php echo admin_h($s['glass_' . $gi . '_url'] ?? ''); ?>" placeholder="plans.php?c=doctor">
            </div>
            <?php endfor; ?>
            <div class="col-12">
                <label>منوهای اضافه بالای صفحه (هر خط: <b>عنوان | لینک</b>)</label>
                <textarea name="home_menu_extra" rows="3" placeholder="درباره ما | page.php?slug=about&#10;تماس با ما | page.php?slug=contact&#10;همکاری در فروش | affiliate/"><?php echo admin_h($s['home_menu_extra'] ?? ''); ?></textarea>
                <div class="muted" style="font-size:12px;margin-top:4px">منوهای اصلی (خانه، «امکانات» از بخش «کاشی‌های امکانات»، صفحه‌ها و مطالبِ «نمایش در منو»، مطالب و پلن‌ها) خودکار ساخته می‌شوند.</div>
            </div>
        </div>
        <h4 style="font-size:14px;font-weight:700;margin:22px 0 6px">🏅 سه لوگو / نماد پایین صفحه (بالای متن کپی‌رایت)</h4>
        <p class="muted" style="font-size:12.5px;line-height:2;margin-bottom:10px">برای هر جایگاه یا «تصویر + لینک» بدهید، یا «کد نماد» (مثل کد HTML اینماد، ساماندهی یا نشان اعتماد که سایت صادرکننده می‌دهد) را کامل جای‌گذاری کنید. اگر کد وارد شود، همان کد نمایش داده می‌شود. جایگاه خالی نمایش داده نمی‌شود.</p>
        <div class="row g-3">
        <?php for ($bi = 1; $bi <= 3; $bi++): $bimg = admin_h($s['badge_' . $bi . '_img'] ?? ''); ?>
            <div class="col-md-4">
                <div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px">
                    <b style="font-size:13px">جایگاه <?php echo $bi; ?></b>
                    <input type="hidden" name="badge_<?php echo $bi; ?>_img" id="badge_<?php echo $bi; ?>_img" value="<?php echo $bimg; ?>">
                    <div class="upload-box" style="margin-top:8px">
                        <img id="badge_<?php echo $bi; ?>_prev" src="<?php echo $bimg; ?>" class="upload-preview-img" alt="" style="height:60px;width:auto;max-width:120px;<?php echo $bimg ? '' : 'display:none'; ?>">
                        <label class="upload-label"><i class="bi bi-image me-1"></i>آپلود تصویر لوگو
                            <input type="file" accept="image/*" onchange="adminUploadImage(this,'badge_<?php echo $bi; ?>_prev','badge_<?php echo $bi; ?>_img')"></label>
                        <?php if ($bimg): ?><button type="button" class="btn btn-sm" style="margin-top:6px" onclick="document.getElementById('badge_<?php echo $bi; ?>_img').value='';document.getElementById('badge_<?php echo $bi; ?>_prev').style.display='none'">حذف تصویر</button><?php endif; ?>
                    </div>
                    <label style="margin-top:8px">لینک (با کلیک روی لوگو)</label>
                    <input type="text" name="badge_<?php echo $bi; ?>_url" dir="ltr" value="<?php echo admin_h($s['badge_' . $bi . '_url'] ?? ''); ?>" placeholder="https://...">
                    <label style="margin-top:8px">متن جایگزین (alt)</label>
                    <input type="text" name="badge_<?php echo $bi; ?>_alt" value="<?php echo admin_h($s['badge_' . $bi . '_alt'] ?? ''); ?>" placeholder="مثلاً: نماد اعتماد الکترونیکی">
                    <label style="margin-top:8px">یا کد نماد (HTML)</label>
                    <textarea name="badge_<?php echo $bi; ?>_code" rows="3" dir="ltr" style="font-family:monospace;font-size:12px" placeholder="&lt;a referrerpolicy=&quot;origin&quot; target=&quot;_blank&quot; href=&quot;https://trustseal.enamad.ir/?id=...&quot;&gt;&lt;img ...&gt;&lt;/a&gt;"><?php echo admin_h($s['badge_' . $bi . '_code'] ?? ''); ?></textarea>
                </div>
            </div>
        <?php endfor; ?>
        </div>
    </div>
</div>

<!-- ── اینترو ── -->
<div class="card">
    <div class="card-header"><i class="bi bi-play-circle me-2"></i>اینترو سینماتیک</div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
            <input type="checkbox" name="intro_enabled" id="intro_enabled" style="width:auto"
                <?php echo ($s['intro_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
            <label for="intro_enabled" style="margin:0;cursor:pointer;font-weight:500">نمایش اینترو در ورود به سایت</label>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label>عنوان اینترو</label>
                <input type="text" name="intro_title" value="<?php echo admin_h($s['intro_title'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label>زیرعنوان اینترو</label>
                <input type="text" name="intro_subtitle" value="<?php echo admin_h($s['intro_subtitle'] ?? ''); ?>">
            </div>

            <!-- تصویر پس‌زمینه اینترو -->
            <div class="col-12">
                <label>تصویر پس‌زمینه اینترو</label>
                <?php $introImg = admin_h($s['intro_image_url'] ?? ''); ?>
                <input type="hidden" name="intro_image_url" id="intro_image_url_hidden" value="<?php echo $introImg; ?>">
                <div class="upload-box">
                    <?php if ($introImg): ?>
                    <img id="intro_img_preview" src="<?php echo $introImg; ?>" class="upload-preview-img" alt="" style="width:120px;height:60px">
                    <?php else: ?>
                    <img id="intro_img_preview" src="" class="upload-preview-img" alt="" style="width:120px;height:60px;display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>آپلود تصویر پس‌زمینه
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'intro_img_preview','intro_image_url_hidden')">
                    </label>
                    <span class="form-text">اگر آپلود نشود، گرادیان رنگی پیش‌فرض نمایش داده می‌شود</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── نوار اطلاعیه ── -->
<div class="card">
    <div class="card-header"><i class="bi bi-bell me-2"></i>نوار اطلاعیه (بالای سایت)</div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
            <input type="checkbox" name="notice_bar_show" id="notice_bar_show" style="width:auto"
                <?php echo ($s['notice_bar_show'] ?? '0') === '1' ? 'checked' : ''; ?>>
            <label for="notice_bar_show" style="margin:0;cursor:pointer;font-weight:500">نمایش نوار اطلاعیه</label>
        </div>
        <div class="row g-3">
            <div class="col-md-8">
                <label>متن اطلاعیه</label>
                <input type="text" name="notice_bar_text" value="<?php echo admin_h($s['notice_bar_text'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label>لینک (اختیاری)</label>
                <input type="text" name="notice_bar_link" value="<?php echo admin_h($s['notice_bar_link'] ?? ''); ?>">
            </div>
        </div>
    </div>
</div>

<!-- ── SEO پایه ── -->
<div class="card">
    <div class="card-header"><i class="bi bi-search me-2"></i>تنظیمات SEO</div>
    <div class="card-body">
        <div class="row g-3">
            <!-- تصویر OG -->
            <div class="col-12">
                <label>تصویر OG (Open Graph) — هنگام اشتراک‌گذاری در شبکه‌های اجتماعی</label>
                <?php $ogImg = admin_h($s['og_image'] ?? ''); ?>
                <input type="hidden" name="og_image" id="og_image_hidden" value="<?php echo $ogImg; ?>">
                <div class="upload-box">
                    <?php if ($ogImg): ?>
                    <img id="og_preview" src="<?php echo $ogImg; ?>" class="upload-preview-img" alt="" style="width:120px;height:63px">
                    <?php else: ?>
                    <img id="og_preview" src="" class="upload-preview-img" alt="" style="width:120px;height:63px;display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>آپلود تصویر اشتراک‌گذاری (۱۲۰۰×۶۳۰ px)
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'og_preview','og_image_hidden')">
                    </label>
                </div>
            </div>

            <div class="col-md-4">
                <label>Twitter Handle</label>
                <input type="text" name="twitter_handle" value="<?php echo admin_h($s['twitter_handle'] ?? ''); ?>" placeholder="@handle">
            </div>
            <div class="col-md-4">
                <label>کد Google Analytics (GA4)</label>
                <input type="text" name="ga_id" value="<?php echo admin_h($s['ga_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX">
            </div>
            <div class="col-md-4">
                <label>Robots Meta</label>
                <select name="meta_robots">
                    <?php foreach (['index, follow','noindex, follow','index, nofollow','noindex, nofollow'] as $r): ?>
                    <option value="<?php echo $r; ?>" <?php echo ($s['meta_robots'] ?? 'index, follow') === $r ? 'selected' : ''; ?>><?php echo $r; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label>کد تأیید Google Search Console</label>
                <input type="text" name="site_verification_google" value="<?php echo admin_h($s['site_verification_google'] ?? ''); ?>" placeholder="کد مقدار meta content از Google">
                <span class="form-text">از Google Search Console → «تأیید مالکیت» → روش HTML Tag دریافت کنید</span>
            </div>
        </div>
    </div>
</div>

<!-- ── سئو محلی (Local SEO) ── -->
<div class="card">
    <div class="card-header"><i class="bi bi-geo-alt me-2"></i>سئو محلی (Local SEO / Schema.org)</div>
    <div class="card-body">
        <p style="font-size:12px;color:var(--text-dim);margin-bottom:14px">
            این اطلاعات در قالب داده‌های ساختاریافته Schema.org به گوگل ارائه می‌شود و به بهبود نمایش سایت در نتایج جستجوی محلی کمک می‌کند.
        </p>
        <div class="row g-3">
            <div class="col-md-4">
                <label>نوع کسب‌وکار (Schema Type)</label>
                <select name="business_type">
                    <?php
                    $btypes = ['Organization','LocalBusiness','ProfessionalService','LegalService',
                               'FinancialService','TechCompany','SoftwareApplication','WebSite'];
                    foreach ($btypes as $bt):
                    ?>
                    <option value="<?php echo $bt; ?>" <?php echo ($s['business_type'] ?? 'Organization') === $bt ? 'selected' : ''; ?>><?php echo $bt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label>شماره تلفن</label>
                <input type="text" name="business_phone" value="<?php echo admin_h($s['business_phone'] ?? ''); ?>" placeholder="+98 21 XXXX XXXX">
            </div>
            <div class="col-md-4">
                <label>ایمیل کسب‌وکار</label>
                <input type="email" name="business_email" value="<?php echo admin_h($s['business_email'] ?? ''); ?>">
            </div>
            <div class="col-md-8">
                <label>آدرس کامل</label>
                <input type="text" name="business_address" value="<?php echo admin_h($s['business_address'] ?? ''); ?>" placeholder="خیابان، پلاک، ساختمان">
            </div>
            <div class="col-md-4">
                <label>شهر / استان</label>
                <input type="text" name="business_city" value="<?php echo admin_h($s['business_city'] ?? ''); ?>" placeholder="تهران">
            </div>
            <div class="col-md-6">
                <label>ساعات کار (Opening Hours)</label>
                <input type="text" name="business_opening_hours" value="<?php echo admin_h($s['business_opening_hours'] ?? ''); ?>" placeholder="Mo-Fr 09:00-17:00">
                <span class="form-text">قالب استاندارد: Mo-Fr 09:00-17:00 یا Sa-Su Closed</span>
            </div>
            <div class="col-md-6">
                <label>حوزه سرویس‌دهی</label>
                <input type="text" name="business_area_served" value="<?php echo admin_h($s['business_area_served'] ?? ''); ?>" placeholder="تهران، ایران">
            </div>
        </div>
    </div>
</div>

<div style="margin-bottom:30px">
    <button type="submit" class="btn btn-primary" style="padding:12px 32px">
        <i class="bi bi-check2-circle me-2"></i>ذخیره همه تنظیمات
    </button>
</div>
</form>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
