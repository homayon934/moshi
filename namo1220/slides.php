<?php
require_once __DIR__ . '/_bootstrap.php';

$msg = '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── ذخیره ────────────────────────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $msg = 'error|خطای امنیتی. دوباره تلاش کنید.';
    } else {
        $id   = (int)($_POST['id'] ?? 0) ?: null;
        $data = [
            'title'       => trim($_POST['title'] ?? ''),
            'badge_text'  => trim($_POST['badge_text'] ?? ''),
            'subtitle'    => trim($_POST['subtitle'] ?? ''),
            'image_url'   => trim($_POST['image_url'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'btn1_text'   => trim($_POST['btn1_text'] ?? ''),
            'btn1_url'    => trim($_POST['btn1_url'] ?? ''),
            'btn2_text'   => trim($_POST['btn2_text'] ?? ''),
            'btn2_url'    => trim($_POST['btn2_url'] ?? ''),
            'sort_order'  => (int)($_POST['sort_order'] ?? 0),
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ];
        site_save_slide($pdo, $data, $id);
        header('Location: slides.php?saved=1'); exit;
    }
}

// ── حذف ──────────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    site_delete_slide($pdo, (int)$_GET['id']);
    header('Location: slides.php?deleted=1'); exit;
}

if (isset($_GET['saved']))   $msg = 'ok|اسلاید با موفقیت ذخیره شد.';
if (isset($_GET['deleted'])) $msg = 'ok|اسلاید حذف شد.';

$slides = site_get_slides($pdo, false);
$edit   = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $editId = (int)$_GET['edit'];
    foreach ($slides as $s) { if ($s['id'] == $editId) { $edit = $s; break; } }
}
?>
<?php include __DIR__ . '/_header.php'; ?>
<div class="container-fluid py-4 px-4">

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="fw-bold mb-0" style="font-size:20px">مدیریت اسلایدر</h2>
    <a href="?edit=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> اسلاید جدید</a>
</div>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
<div class="<?php echo $type === 'ok' ? 'msg-ok' : 'msg-err'; ?>"><?php echo admin_h($text); ?></div>
<?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<!-- ── فرم ویرایش / ایجاد ── -->
<div class="card">
    <div class="card-header"><?php echo $edit ? 'ویرایش اسلاید #' . $edit['id'] : 'اسلاید جدید'; ?></div>
    <div class="card-body">
    <form method="post" id="slide-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

        <div class="row g-3">
            <div class="col-md-8">
                <label>عنوان اسلاید *</label>
                <input type="text" name="title" required value="<?php echo admin_h($edit['title'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label>برچسب (Badge)</label>
                <input type="text" name="badge_text" value="<?php echo admin_h($edit['badge_text'] ?? ''); ?>" placeholder="مثال: جدید">
            </div>
            <div class="col-12">
                <label>زیرعنوان</label>
                <input type="text" name="subtitle" value="<?php echo admin_h($edit['subtitle'] ?? ''); ?>">
            </div>
            <div class="col-12">
                <label>تصویر پس‌زمینه اسلاید</label>
                <?php $imgUrl = admin_h($edit['image_url'] ?? ''); ?>
                <input type="hidden" name="image_url" id="slide_image_url" value="<?php echo $imgUrl; ?>">
                <div class="upload-box">
                    <?php if ($imgUrl): ?>
                    <img id="slide_img_preview" src="<?php echo $imgUrl; ?>" class="upload-preview-img" alt="">
                    <?php else: ?>
                    <img id="slide_img_preview" src="" class="upload-preview-img" alt="" style="display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>انتخاب / تغییر تصویر
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'slide_img_preview','slide_image_url')">
                    </label>
                    <span class="form-text">فرمت: JPG، PNG، WebP — حداکثر ۵ مگابایت</span>
                </div>
            </div>
            <div class="col-12">
                <label>توضیحات (اختیاری)</label>
                <textarea name="description" rows="3"><?php echo admin_h($edit['description'] ?? ''); ?></textarea>
            </div>
            <div class="col-md-3"><label>متن دکمه ۱</label><input type="text" name="btn1_text" value="<?php echo admin_h($edit['btn1_text'] ?? ''); ?>"></div>
            <div class="col-md-3"><label>لینک دکمه ۱</label><input type="text" name="btn1_url"  value="<?php echo admin_h($edit['btn1_url']  ?? ''); ?>"></div>
            <div class="col-md-3"><label>متن دکمه ۲</label><input type="text" name="btn2_text" value="<?php echo admin_h($edit['btn2_text'] ?? ''); ?>"></div>
            <div class="col-md-3"><label>لینک دکمه ۲</label><input type="text" name="btn2_url"  value="<?php echo admin_h($edit['btn2_url']  ?? ''); ?>"></div>
            <div class="col-md-2">
                <label>ترتیب</label>
                <input type="number" name="sort_order" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>">
            </div>
            <div class="col-12">
                <div class="form-check" style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="is_active" id="chk_act" style="width:auto"
                        <?php echo ($edit['is_active'] ?? 1) ? 'checked' : ''; ?>>
                    <label for="chk_act" style="margin:0;cursor:pointer">فعال (نمایش در سایت)</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>ذخیره اسلاید</button>
                <a href="slides.php" class="btn btn-outline ms-2">انصراف</a>
            </div>
        </div>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- ── جدول اسلایدها ── -->
<div class="card">
    <div class="card-header">لیست اسلایدها (<?php echo count($slides); ?> مورد)</div>
    <div class="card-body p-0 table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>تصویر</th><th>عنوان</th><th>دکمه‌ها</th>
                <th>ترتیب</th><th>وضعیت</th><th style="text-align:left">عملیات</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($slides as $s): ?>
        <tr>
            <td><?php echo $s['id']; ?></td>
            <td>
                <?php if ($s['image_url']): ?>
                <img src="<?php echo admin_h($s['image_url']); ?>" alt="" style="height:40px;width:60px;object-fit:cover;border-radius:6px">
                <?php else: ?><span style="color:var(--text-dim);font-size:11px">بدون تصویر</span><?php endif; ?>
            </td>
            <td>
                <div style="font-weight:600;font-size:13px"><?php echo admin_h($s['title']); ?></div>
                <?php if ($s['badge_text']): ?><span class="badge-blue badge"><?php echo admin_h($s['badge_text']); ?></span><?php endif; ?>
            </td>
            <td style="font-size:11px">
                <?php if ($s['btn1_text']): ?><div><?php echo admin_h($s['btn1_text']); ?></div><?php endif; ?>
                <?php if ($s['btn2_text']): ?><div style="color:var(--text-dim)"><?php echo admin_h($s['btn2_text']); ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge-gray"><?php echo $s['sort_order']; ?></span></td>
            <td><?php echo $s['is_active'] ? '<span class="badge-ok">فعال</span>' : '<span class="badge-off">غیرفعال</span>'; ?></td>
            <td style="text-align:left">
                <a href="?edit=<?php echo $s['id']; ?>" class="btn btn-outline btn-sm">ویرایش</a>
                <a href="?action=delete&id=<?php echo $s['id']; ?>" class="btn btn-danger btn-sm ms-1"
                   onclick="return confirm('اسلاید حذف شود؟')">حذف</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$slides): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-dim)">اسلایدی ثبت نشده</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
<?php include __DIR__ . '/_footer.php'; ?>
