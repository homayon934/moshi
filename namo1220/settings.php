<?php
require_once __DIR__ . '/_bootstrap.php';
$page_title = 'تنظیمات ویجت';

$ws = saas_get_widget_settings($pdo, $current_user['id']);
$plan = saas_get_plan($pdo, $current_user['plan_id'] ?? 0);
$has_color = !$plan || !empty($plan['has_color_customize']);

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'store_info'            => trim($_POST['store_info'] ?? ''),
        'response_template'     => trim($_POST['response_template'] ?? ''),
        'widget_title'          => trim($_POST['widget_title'] ?? 'پرسش و پاسخ هوشمند'),
        'welcome_message'       => trim($_POST['welcome_message'] ?? 'سلام! چطور می‌تونم کمکتون کنم؟'),
        'contact_phone'         => trim($_POST['contact_phone'] ?? ''),
        'max_questions_session' => max(0, (int)($_POST['max_questions_session'] ?? 0)),
        'response_length'       => in_array($_POST['response_length'] ?? '', ['short','medium','long']) ? $_POST['response_length'] : 'medium',
        'enabled'               => !empty($_POST['enabled']) ? 1 : 0,
    ];

    if ($has_color) {
        $pc = trim($_POST['primary_color'] ?? '');
        $tc = trim($_POST['text_color'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $pc)) $data['primary_color'] = $pc;
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $tc)) $data['text_color'] = $tc;
        $data['position']        = in_array($_POST['position'] ?? '', ['right','left']) ? $_POST['position'] : 'right';
        $data['show_lead_form']  = !empty($_POST['show_lead_form']) ? 1 : 0;
    }

    // اگر پلن API محصول دارد
    if ($plan && !empty($plan['has_product_api'])) {
        $data['product_api_url'] = trim($_POST['product_api_url'] ?? '');
        $data['product_api_key'] = trim($_POST['product_api_key'] ?? '');
    }

    // آپلود فایل راهنما
    if (!empty($_FILES['guide_file']['name'])) {
        $allowed_ext = ['txt'];
        $ext = strtolower(pathinfo($_FILES['guide_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            $error = 'فقط فایل .txt مجاز است.';
        } elseif ($_FILES['guide_file']['size'] > 2 * 1024 * 1024) {
            $error = 'حجم فایل بیشتر از ۲ مگابایت است.';
        } else {
            $content = file_get_contents($_FILES['guide_file']['tmp_name']);
            if ($content !== false) {
                $data['guide_text'] = $content;
                $data['guide_filename'] = htmlspecialchars($_FILES['guide_file']['name'], ENT_QUOTES);
            }
        }
    } elseif (!empty($_POST['guide_file_remove'])) {
        $data['guide_text'] = '';
        $data['guide_filename'] = '';
    }

    if (!$error) {
        // ساخت SET clause
        $sets = [];
        $vals = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $current_user['id'];
        $pdo->prepare("UPDATE saas_widget_settings SET " . implode(', ', $sets) . ", updated_at=NOW() WHERE user_id=?")->execute($vals);
        $ws = saas_get_widget_settings($pdo, $current_user['id']);
        $saved = true;
    }
}

include __DIR__ . '/_header.php';
?>

<div class="topbar"><h1>تنظیمات ویجت</h1></div>

<?php if ($saved): ?><div class="alert alert-success">✅ تنظیمات با موفقیت ذخیره شد.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger">⚠️ <?php echo saas_h($error); ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">

    <!-- فعال/غیرفعال -->
    <div class="card">
        <div class="card-title">⚙️ وضعیت ویجت</div>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
            <input type="checkbox" name="enabled" value="1" <?php echo $ws['enabled'] ? 'checked' : ''; ?> style="width:18px;height:18px">
            ویجت روی سایت فعال باشد
        </label>
    </div>

    <!-- ظاهر -->
    <div class="card">
        <div class="card-title">🎨 ظاهر ویجت</div>
        <div class="row2">
            <div class="form-group">
                <label>عنوان ویجت</label>
                <input type="text" name="widget_title" value="<?php echo saas_h($ws['widget_title']); ?>" maxlength="100">
            </div>
            <div class="form-group">
                <label>پیام خوش‌آمدگویی</label>
                <input type="text" name="welcome_message" value="<?php echo saas_h($ws['welcome_message']); ?>" maxlength="300">
            </div>
        </div>

        <?php if ($has_color): ?>
        <div class="row2">
            <div class="form-group">
                <label>رنگ اصلی (دکمه و هدر)</label>
                <div style="display:flex;align-items:center;gap:8px">
                    <input type="color" id="pc" value="<?php echo saas_h($ws['primary_color']); ?>" style="width:40px;height:36px;padding:2px;border-radius:6px;border:1px solid #d1d5db;cursor:pointer" oninput="syncColor('primary_color',this.value)">
                    <input type="text" name="primary_color" id="primary_color" value="<?php echo saas_h($ws['primary_color']); ?>" dir="ltr" maxlength="7" style="width:110px" oninput="document.getElementById('pc').value=this.value">
                </div>
            </div>
            <div class="form-group">
                <label>رنگ متن روی هدر</label>
                <div style="display:flex;align-items:center;gap:8px">
                    <input type="color" id="tc" value="<?php echo saas_h($ws['text_color']); ?>" style="width:40px;height:36px;padding:2px;border-radius:6px;border:1px solid #d1d5db;cursor:pointer" oninput="syncColor('text_color',this.value)">
                    <input type="text" name="text_color" id="text_color" value="<?php echo saas_h($ws['text_color']); ?>" dir="ltr" maxlength="7" style="width:110px" oninput="document.getElementById('tc').value=this.value">
                </div>
            </div>
        </div>
        <div class="row2" style="margin-top:10px">
            <div class="form-group">
                <label>موقعیت ویجت روی صفحه</label>
                <select name="position">
                    <option value="right" <?php echo ($ws['position']??'right')==='right'?'selected':''; ?>>راست (پیشنهادی برای RTL)</option>
                    <option value="left" <?php echo ($ws['position']??'right')==='left'?'selected':''; ?>>چپ</option>
                </select>
            </div>
            <div class="form-group">
                <label style="padding-top:28px;display:block">
                    <input type="checkbox" name="show_lead_form" value="1" <?php echo !empty($ws['show_lead_form'])?'checked':''; ?>>
                    نمایش فرم اطلاعات تماس در ویجت
                </label>
            </div>
        </div>
        <div id="preview-bar" style="background:<?php echo saas_h($ws['primary_color']); ?>;border-radius:10px;padding:14px 18px;color:<?php echo saas_h($ws['text_color']); ?>;font-size:14px;font-weight:600;margin-top:10px;display:flex;align-items:center;gap:10px">
            <span>🤖</span> <span id="preview-title"><?php echo saas_h($ws['widget_title']); ?></span>
        </div>
        <?php else: ?>
            <div class="alert alert-info">🎨 انتخاب رنگ در پلن شما موجود نیست. <a href="billing.php">ارتقاء پلن</a></div>
        <?php endif; ?>
    </div>

    <!-- محدودیت‌ها -->
    <div class="card">
        <div class="card-title">🚦 محدودیت‌های پیام</div>
        <div class="row2">
            <div class="form-group">
                <label>حداکثر پیام در هر مکالمه (۰ = بدون محدودیت)</label>
                <input type="number" name="max_questions_session" value="<?php echo (int)$ws['max_questions_session']; ?>" min="0" max="1000">
            </div>
            <div class="form-group">
                <label>طول پاسخ‌ها</label>
                <select name="response_length">
                    <option value="short" <?php echo $ws['response_length']==='short'?'selected':''; ?>>کوتاه (۱-۲ جمله)</option>
                    <option value="medium" <?php echo $ws['response_length']==='medium'?'selected':''; ?>>متوسط (۳-۵ جمله) — پیشنهادی</option>
                    <option value="long" <?php echo $ws['response_length']==='long'?'selected':''; ?>>بلند (تا ۱۰ جمله)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>شماره تماس پشتیبانی (نمایش وقتی اعتبار تمام شد)</label>
            <input type="text" name="contact_phone" value="<?php echo saas_h($ws['contact_phone']); ?>" placeholder="021xxxxxxxx" dir="ltr" style="max-width:200px">
        </div>
        <?php if ($plan): ?>
        <div style="background:#f8fafc;border-radius:8px;padding:12px;font-size:12px;color:#475569">
            محدودیت‌های پلن: حداکثر <?php echo $plan['max_requests_per_day']; ?> درخواست روزانه،
            حداکثر <?php echo $plan['max_tokens_per_request']; ?> توکن در هر پیام
        </div>
        <?php endif; ?>
    </div>

    <!-- اطلاعات سایت -->
    <div class="card">
        <div class="card-title">📋 اطلاعات کلی سایت</div>
        <div class="form-group">
            <label>اطلاعات پایه (پاسخ به سوالات کاربران)</label>
            <textarea name="store_info" rows="8" placeholder="مثلاً: ساعات کاری، نحوه ارسال، هزینه ارسال، شرایط مرجوعی، روش‌های پرداخت، آدرس و شماره تماس..."><?php echo saas_h($ws['store_info']); ?></textarea>
        </div>
    </div>

    <!-- پایگاه دانش راهنما -->
    <div class="card">
        <div class="card-title">📄 فایل راهنمای هوش مصنوعی</div>
        <div class="form-group">
            <label>فایل راهنما (فقط .txt، حداکثر ۲ مگابایت)</label>
            <input type="file" name="guide_file" accept=".txt">
            <?php if ($ws['guide_filename']): ?>
                <div class="muted" style="margin-top:6px">
                    فایل فعلی: <b><?php echo saas_h($ws['guide_filename']); ?></b>
                    &nbsp; <label style="display:inline"><input type="checkbox" name="guide_file_remove" value="1"> حذف فایل</label>
                </div>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label>الگوی پاسخ‌گویی (اختیاری)</label>
            <textarea name="response_template" rows="3" placeholder="مثلاً: همیشه با سلام شروع کن و در آخر بپرس آیا کمک دیگه‌ای لازم داری؟"><?php echo saas_h($ws['response_template']); ?></textarea>
        </div>
    </div>

    <?php if ($plan && !empty($plan['has_product_api'])): ?>
    <!-- API محصولات -->
    <div class="card">
        <div class="card-title">🔌 اتصال API محصولات سایت</div>
        <div class="row2">
            <div class="form-group">
                <label>آدرس API محصولات</label>
                <input type="text" name="product_api_url" value="<?php echo saas_h($ws['product_api_url']); ?>" dir="ltr" placeholder="https://yoursite.com/api/products">
            </div>
            <div class="form-group">
                <label>کلید API (اختیاری)</label>
                <input type="text" name="product_api_key" value="<?php echo saas_h($ws['product_api_key']); ?>" dir="ltr" placeholder="Bearer token">
            </div>
        </div>
        <p class="muted">این API باید لیستی از محصولات را با فیلد search برگرداند. فرمت: JSON با کلیدهای name/title, price, url</p>
    </div>
    <?php endif; ?>

    <div style="margin-bottom:30px">
        <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button>
    </div>
</form>

<script>
function syncColor(id, val) {
    const inp = document.getElementById(id);
    if (inp) inp.value = val;
    // بروزرسانی پیش‌نمایش
    const bar = document.getElementById('preview-bar');
    if (bar) {
        const pc = document.getElementById('primary_color')?.value || '#7c3aed';
        const tc = document.getElementById('text_color')?.value || '#ffffff';
        bar.style.background = pc;
        bar.style.color = tc;
    }
}
// بروزرسانی عنوان پیش‌نمایش
const titleInp = document.querySelector('input[name="widget_title"]');
const prevTitle = document.getElementById('preview-title');
if (titleInp && prevTitle) {
    titleInp.addEventListener('input', () => prevTitle.textContent = titleInp.value || 'دستیار هوشمند');
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
