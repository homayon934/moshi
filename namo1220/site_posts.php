<?php
require_once __DIR__ . '/_bootstrap.php';

$msg    = '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $msg = 'error|خطای امنیتی. دوباره تلاش کنید.';
    } else {
        $id   = (int)($_POST['id'] ?? 0) ?: null;
        $data = [
            'title'            => trim($_POST['title'] ?? ''),
            'slug'             => trim($_POST['slug'] ?? ''),
            'excerpt'          => trim($_POST['excerpt'] ?? ''),
            'content'          => $_POST['content'] ?? '',
            'image_url'        => trim($_POST['image_url'] ?? ''),
            'show_on_homepage' => isset($_POST['show_on_homepage']) ? 1 : 0,
            'show_in_menu'     => isset($_POST['show_in_menu'])     ? 1 : 0,
            'menu_label'       => trim($_POST['menu_label'] ?? ''),
            'menu_sort'        => (int)($_POST['menu_sort'] ?? 0),
            'is_published'     => isset($_POST['is_published'])     ? 1 : 0,
            'meta_title'       => trim($_POST['meta_title'] ?? ''),
            'meta_desc'        => trim($_POST['meta_desc'] ?? ''),
            'meta_keywords'    => trim($_POST['meta_keywords'] ?? ''),
        ];
        if (!$data['slug']) $data['slug'] = site_slug($data['title']);
        site_save_post($pdo, $data, $id);
        header('Location: site_posts.php?saved=1'); exit;
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    site_delete_post($pdo, (int)$_GET['id']);
    header('Location: site_posts.php?deleted=1'); exit;
}

if (isset($_GET['saved']))   $msg = 'ok|مطلب با موفقیت ذخیره شد.';
if (isset($_GET['deleted'])) $msg = 'ok|مطلب حذف شد.';

$posts = site_get_posts($pdo, false);
$edit  = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $editId = (int)$_GET['edit'];
    foreach ($posts as $p) { if ($p['id'] == $editId) { $edit = $p; break; } }
}
?>
<?php include __DIR__ . '/_header.php'; ?>
<div class="container-fluid py-4 px-4">

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="fw-bold mb-0" style="font-size:20px">مدیریت مطالب</h2>
    <a href="?edit=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> مطلب جدید</a>
</div>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
<div class="<?php echo $type === 'ok' ? 'msg-ok' : 'msg-err'; ?>"><?php echo admin_h($text); ?></div>
<?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<div class="card">
    <div class="card-header"><?php echo $edit ? 'ویرایش: ' . admin_h($edit['title']) : 'مطلب جدید'; ?></div>
    <div class="card-body">
    <form method="post" id="post-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

        <div class="row g-3">
            <div class="col-md-8">
                <label>عنوان مطلب *</label>
                <input type="text" name="title" required value="<?php echo admin_h($edit['title'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label>Slug (آدرس)</label>
                <input type="text" name="slug" value="<?php echo admin_h($edit['slug'] ?? ''); ?>" placeholder="خودکار تولید می‌شود">
            </div>

            <!-- تصویر شاخص -->
            <div class="col-12">
                <label>تصویر شاخص مطلب</label>
                <?php $pImg = admin_h($edit['image_url'] ?? ''); ?>
                <input type="hidden" name="image_url" id="post_image_url" value="<?php echo $pImg; ?>">
                <div class="upload-box">
                    <?php if ($pImg): ?>
                    <img id="post_img_preview" src="<?php echo $pImg; ?>" class="upload-preview-img" alt="" style="width:100px;height:60px">
                    <?php else: ?>
                    <img id="post_img_preview" src="" class="upload-preview-img" alt="" style="width:100px;height:60px;display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>انتخاب / تغییر تصویر
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'post_img_preview','post_image_url')">
                    </label>
                    <span class="form-text">نسبت پیشنهادی: ۱۶:۹ — حداکثر ۵ مگابایت</span>
                </div>
            </div>

            <div class="col-12">
                <label>خلاصه مطلب <small style="font-weight:400;color:var(--text-dim)">(نمایش در صفحه اول)</small></label>
                <textarea name="excerpt" rows="3"><?php echo admin_h($edit['excerpt'] ?? ''); ?></textarea>
            </div>

            <!-- Quill Editor -->
            <div class="col-12">
                <label>محتوای کامل مطلب</label>
                <div id="quill-post-editor" style="background:#fff"></div>
                <textarea name="content" id="post_content_hidden" style="display:none"><?php echo htmlspecialchars($edit['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- گزینه‌ها -->
            <div class="col-12">
                <hr style="margin:8px 0">
                <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:center">
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="is_published" id="chk_pub" style="width:auto"
                            <?php echo ($edit['is_published'] ?? 1) ? 'checked' : ''; ?>>
                        <label for="chk_pub" style="margin:0;cursor:pointer">منتشر شده</label>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="show_on_homepage" id="chk_hp" style="width:auto"
                            <?php echo ($edit['show_on_homepage'] ?? 1) ? 'checked' : ''; ?>>
                        <label for="chk_hp" style="margin:0;cursor:pointer">نمایش در صفحه اول</label>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="checkbox" name="show_in_menu" id="chk_mn" style="width:auto"
                            <?php echo ($edit['show_in_menu'] ?? 0) ? 'checked' : ''; ?>>
                        <label for="chk_mn" style="margin:0;cursor:pointer">نمایش در منو</label>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <label>برچسب منو</label>
                <input type="text" name="menu_label" value="<?php echo admin_h($edit['menu_label'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label>ترتیب منو</label>
                <input type="number" name="menu_sort" value="<?php echo (int)($edit['menu_sort'] ?? 0); ?>">
            </div>

            <!-- SEO -->
            <div class="col-12"><hr style="margin:8px 0"><p style="font-weight:600;font-size:12px;color:var(--text-dim);margin-bottom:6px">تنظیمات SEO</p></div>
            <div class="col-md-6">
                <label>Meta Title</label>
                <input type="text" name="meta_title" value="<?php echo admin_h($edit['meta_title'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label>Meta Keywords</label>
                <input type="text" name="meta_keywords" value="<?php echo admin_h($edit['meta_keywords'] ?? ''); ?>">
            </div>
            <div class="col-12">
                <label>Meta Description</label>
                <textarea name="meta_desc" rows="2"><?php echo admin_h($edit['meta_desc'] ?? ''); ?></textarea>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>ذخیره مطلب</button>
                <a href="site_posts.php" class="btn btn-outline ms-2">انصراف</a>
            </div>
        </div>
    </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    initQuill('quill-post-editor', 'post_content_hidden');
});
</script>
<?php endif; ?>

<!-- ── جدول مطالب ── -->
<div class="card">
    <div class="card-header">لیست مطالب (<?php echo count($posts); ?> مورد)</div>
    <div class="card-body p-0 table-wrap">
    <table>
        <thead>
            <tr><th>#</th><th>عنوان</th><th>تصویر</th><th>صفحه اول</th><th>منو</th><th>وضعیت</th><th style="text-align:left">عملیات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($posts as $p): ?>
        <tr>
            <td><?php echo $p['id']; ?></td>
            <td>
                <div style="font-weight:600"><?php echo admin_h($p['title']); ?></div>
                <code style="font-size:10px"><?php echo admin_h($p['slug']); ?></code>
            </td>
            <td>
                <?php if ($p['image_url']): ?>
                <img src="<?php echo admin_h($p['image_url']); ?>" alt="" style="height:32px;width:50px;border-radius:4px;object-fit:cover">
                <?php else: ?><span style="color:var(--text-dim);font-size:11px">—</span><?php endif; ?>
            </td>
            <td><?php echo $p['show_on_homepage'] ? '<span class="badge-blue badge">بله</span>' : '<span style="color:var(--text-dim)">—</span>'; ?></td>
            <td><?php echo $p['show_in_menu']     ? '<span class="badge-blue badge">بله</span>' : '<span style="color:var(--text-dim)">—</span>'; ?></td>
            <td><?php echo $p['is_published'] ? '<span class="badge-ok">منتشر</span>' : '<span class="badge-yellow badge">پیش‌نویس</span>'; ?></td>
            <td style="text-align:left">
                <a href="?edit=<?php echo $p['id']; ?>" class="btn btn-outline btn-sm">ویرایش</a>
                <a href="?action=delete&id=<?php echo $p['id']; ?>" class="btn btn-danger btn-sm ms-1"
                   onclick="return confirm('مطلب حذف شود؟')">حذف</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$posts): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-dim)">مطلبی ثبت نشده</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
<?php include __DIR__ . '/_footer.php'; ?>
