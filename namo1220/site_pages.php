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
            'title'        => trim($_POST['title'] ?? ''),
            'slug'         => trim($_POST['slug'] ?? ''),
            'content'      => $_POST['content'] ?? '',      // HTML از Quill
            'show_in_menu' => isset($_POST['show_in_menu']) ? 1 : 0,
            'menu_label'   => trim($_POST['menu_label'] ?? ''),
            'menu_sort'    => (int)($_POST['menu_sort'] ?? 0),
            'meta_title'   => trim($_POST['meta_title'] ?? ''),
            'meta_desc'    => trim($_POST['meta_desc'] ?? ''),
            'meta_keywords'=> trim($_POST['meta_keywords'] ?? ''),
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
        ];
        if (!$data['slug']) $data['slug'] = site_slug($data['title']);
        site_save_page($pdo, $data, $id);
        header('Location: site_pages.php?saved=1'); exit;
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    site_delete_page($pdo, (int)$_GET['id']);
    header('Location: site_pages.php?deleted=1'); exit;
}

if (isset($_GET['saved']))   $msg = 'ok|صفحه با موفقیت ذخیره شد.';
if (isset($_GET['deleted'])) $msg = 'ok|صفحه حذف شد.';

$pages = site_get_pages($pdo, false);
$edit  = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $editId = (int)$_GET['edit'];
    foreach ($pages as $p) { if ($p['id'] == $editId) { $edit = $p; break; } }
}
?>
<?php include __DIR__ . '/_header.php'; ?>
<div class="container-fluid py-4 px-4">

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="fw-bold mb-0" style="font-size:20px">صفحات ثابت</h2>
    <a href="?edit=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> صفحه جدید</a>
</div>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
<div class="<?php echo $type === 'ok' ? 'msg-ok' : 'msg-err'; ?>"><?php echo admin_h($text); ?></div>
<?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<div class="card">
    <div class="card-header"><?php echo $edit ? 'ویرایش: ' . admin_h($edit['title']) : 'صفحه جدید'; ?></div>
    <div class="card-body">
    <form method="post" id="page-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

        <div class="row g-3">
            <div class="col-md-8">
                <label>عنوان صفحه *</label>
                <input type="text" name="title" required value="<?php echo admin_h($edit['title'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label>Slug (آدرس صفحه)</label>
                <input type="text" name="slug" value="<?php echo admin_h($edit['slug'] ?? ''); ?>" placeholder="اگر خالی باشد، خودکار تولید می‌شود">
            </div>

            <!-- ویرایشگر Quill -->
            <div class="col-12">
                <label>محتوای صفحه</label>
                <div id="quill-page-editor" style="background:#fff"></div>
                <!-- textarea مخفی برای ارسال فرم -->
                <textarea name="content" id="page_content_hidden" style="display:none"><?php echo htmlspecialchars($edit['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- گزینه‌های منو -->
            <div class="col-12">
                <hr style="margin:8px 0">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                    <input type="checkbox" name="show_in_menu" id="chk_menu" style="width:auto"
                        <?php echo ($edit['show_in_menu'] ?? 0) ? 'checked' : ''; ?>>
                    <label for="chk_menu" style="margin:0;cursor:pointer;font-weight:500">نمایش در منوی سایت</label>
                </div>
            </div>
            <div class="col-md-6">
                <label>برچسب در منو</label>
                <input type="text" name="menu_label" value="<?php echo admin_h($edit['menu_label'] ?? ''); ?>" placeholder="اگر خالی باشد، از عنوان استفاده می‌شود">
            </div>
            <div class="col-md-2">
                <label>ترتیب منو</label>
                <input type="number" name="menu_sort" value="<?php echo (int)($edit['menu_sort'] ?? 0); ?>">
            </div>

            <!-- SEO -->
            <div class="col-12"><hr style="margin:8px 0"><p style="font-weight:600;font-size:12px;color:var(--text-dim);margin-bottom:6px">تنظیمات SEO</p></div>
            <div class="col-md-6">
                <label>Meta Title</label>
                <input type="text" name="meta_title" value="<?php echo admin_h($edit['meta_title'] ?? ''); ?>" placeholder="پیش‌فرض: عنوان صفحه — نام سایت">
            </div>
            <div class="col-md-6">
                <label>Meta Keywords</label>
                <input type="text" name="meta_keywords" value="<?php echo admin_h($edit['meta_keywords'] ?? ''); ?>">
            </div>
            <div class="col-12">
                <label>Meta Description</label>
                <textarea name="meta_desc" rows="2"><?php echo admin_h($edit['meta_desc'] ?? ''); ?></textarea>
            </div>

            <!-- وضعیت -->
            <div class="col-12">
                <div style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="is_active" id="chk_act" style="width:auto"
                        <?php echo ($edit['is_active'] ?? 1) ? 'checked' : ''; ?>>
                    <label for="chk_act" style="margin:0;cursor:pointer">صفحه فعال (قابل دسترس)</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>ذخیره صفحه</button>
                <a href="site_pages.php" class="btn btn-outline ms-2">انصراف</a>
            </div>
        </div>
    </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    initQuill('quill-page-editor', 'page_content_hidden');
});
</script>
<?php endif; ?>

<!-- ── جدول صفحات ── -->
<div class="card">
    <div class="card-header">لیست صفحات (<?php echo count($pages); ?> مورد)</div>
    <div class="card-body p-0 table-wrap">
    <table>
        <thead>
            <tr><th>#</th><th>عنوان</th><th>Slug</th><th>منو</th><th>وضعیت</th><th style="text-align:left">عملیات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $p): ?>
        <tr>
            <td><?php echo $p['id']; ?></td>
            <td style="font-weight:600"><?php echo admin_h($p['title']); ?></td>
            <td><code>/page.php?slug=<?php echo admin_h($p['slug']); ?></code></td>
            <td><?php echo $p['show_in_menu'] ? '<span class="badge-ok">بله</span>' : '<span class="badge-off">خیر</span>'; ?></td>
            <td><?php echo $p['is_active'] ? '<span class="badge-ok">فعال</span>' : '<span class="badge-err">غیرفعال</span>'; ?></td>
            <td style="text-align:left">
                <a href="?edit=<?php echo $p['id']; ?>" class="btn btn-outline btn-sm">ویرایش</a>
                <a href="?action=delete&id=<?php echo $p['id']; ?>" class="btn btn-danger btn-sm ms-1"
                   onclick="return confirm('صفحه حذف شود؟')">حذف</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pages): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-dim)">صفحه‌ای ثبت نشده</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
<?php include __DIR__ . '/_footer.php'; ?>
