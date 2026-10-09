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
            'title'       => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'image_url'   => trim($_POST['image_url'] ?? ''),
            'link_url'    => trim($_POST['link_url'] ?? '#'),
            'icon'        => trim($_POST['icon'] ?? 'star'),
            'sort_order'  => (int)($_POST['sort_order'] ?? 0),
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ];
        site_save_tile($pdo, $data, $id);
        header('Location: feature_tiles.php?saved=1'); exit;
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    site_delete_tile($pdo, (int)$_GET['id']);
    header('Location: feature_tiles.php?deleted=1'); exit;
}

if (isset($_GET['saved']))   $msg = 'ok|تایل با موفقیت ذخیره شد.';
if (isset($_GET['deleted'])) $msg = 'ok|تایل حذف شد.';

$tiles      = site_get_feature_tiles($pdo, false);
$icons_list = ['support_agent','psychology','integration_instructions','star','shield','bolt',
               'rocket_launch','hub','auto_awesome','smart_toy','bar_chart','chat',
               'verified','groups','emoji_events','trending_up'];
$edit = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $editId = (int)$_GET['edit'];
    foreach ($tiles as $t) { if ($t['id'] == $editId) { $edit = $t; break; } }
}
?>
<?php include __DIR__ . '/_header.php'; ?>
<div class="container-fluid py-4 px-4">

<div class="d-flex align-items-center justify-content-between mb-4">
    <h2 class="fw-bold mb-0" style="font-size:20px">تایل‌های ویژگی <small style="font-size:14px;font-weight:400;color:var(--text-dim)">(۳ تصویر مربعی زیر اسلاید)</small></h2>
    <a href="?edit=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> تایل جدید</a>
</div>

<?php if ($msg): [$type, $text] = explode('|', $msg, 2); ?>
<div class="<?php echo $type === 'ok' ? 'msg-ok' : 'msg-err'; ?>"><?php echo admin_h($text); ?></div>
<?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<div class="card">
    <div class="card-header"><?php echo $edit ? 'ویرایش تایل #' . $edit['id'] : 'تایل جدید'; ?></div>
    <div class="card-body">
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label>عنوان *</label>
                <input type="text" name="title" required value="<?php echo admin_h($edit['title'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label>لینک (اختیاری)</label>
                <input type="text" name="link_url" value="<?php echo admin_h($edit['link_url'] ?? '#'); ?>">
            </div>
            <div class="col-12">
                <label>توضیح کوتاه</label>
                <textarea name="description" rows="2"><?php echo admin_h($edit['description'] ?? ''); ?></textarea>
            </div>

            <!-- آپلود تصویر -->
            <div class="col-md-8">
                <label>تصویر مربعی تایل</label>
                <?php $tImg = admin_h($edit['image_url'] ?? ''); ?>
                <input type="hidden" name="image_url" id="tile_image_url" value="<?php echo $tImg; ?>">
                <div class="upload-box">
                    <?php if ($tImg): ?>
                    <img id="tile_img_preview" src="<?php echo $tImg; ?>" class="upload-preview-img" alt="" style="width:56px;height:56px">
                    <?php else: ?>
                    <img id="tile_img_preview" src="" class="upload-preview-img" alt="" style="width:56px;height:56px;display:none">
                    <?php endif; ?>
                    <label class="upload-label">
                        <i class="bi bi-image me-1"></i>انتخاب تصویر
                        <input type="file" accept="image/*" onchange="adminUploadImage(this,'tile_img_preview','tile_image_url')">
                    </label>
                </div>
            </div>

            <!-- آیکون -->
            <div class="col-md-4">
                <label>آیکون (Material Symbol)</label>
                <select name="icon" id="icon_select">
                    <?php foreach ($icons_list as $ic): ?>
                    <option value="<?php echo $ic; ?>" <?php echo ($edit['icon'] ?? '') === $ic ? 'selected' : ''; ?>><?php echo $ic; ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top:8px;padding:10px;background:var(--bg);border-radius:8px;text-align:center">
                    <span class="material-symbols-outlined" id="icon-preview" style="font-size:32px;color:#0051d5"><?php echo admin_h($edit['icon'] ?? 'star'); ?></span>
                </div>
            </div>

            <div class="col-md-2">
                <label>ترتیب نمایش</label>
                <input type="number" name="sort_order" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>">
            </div>
            <div class="col-12">
                <div class="form-check" style="display:flex;align-items:center;gap:8px">
                    <input type="checkbox" name="is_active" id="chk_act" style="width:auto"
                        <?php echo ($edit['is_active'] ?? 1) ? 'checked' : ''; ?>>
                    <label for="chk_act" style="margin:0;cursor:pointer">فعال</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>ذخیره</button>
                <a href="feature_tiles.php" class="btn btn-outline ms-2">انصراف</a>
            </div>
        </div>
    </form>
    </div>
</div>
<script>
document.getElementById('icon_select')?.addEventListener('change', function () {
    document.getElementById('icon-preview').textContent = this.value;
});
</script>
<?php endif; ?>

<div class="card">
    <div class="card-header">لیست تایل‌ها</div>
    <div class="card-body p-0 table-wrap">
    <table>
        <thead>
            <tr><th>#</th><th>تصویر</th><th>عنوان</th><th>آیکون</th><th>ترتیب</th><th>وضعیت</th><th style="text-align:left">عملیات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tiles as $t): ?>
        <tr>
            <td><?php echo $t['id']; ?></td>
            <td>
                <?php if ($t['image_url']): ?>
                <img src="<?php echo admin_h($t['image_url']); ?>" alt="" style="height:40px;width:40px;border-radius:8px;object-fit:cover">
                <?php else: ?><span style="color:var(--text-dim);font-size:11px">—</span><?php endif; ?>
            </td>
            <td>
                <div style="font-weight:600"><?php echo admin_h($t['title']); ?></div>
                <div style="font-size:11px;color:var(--text-dim)"><?php echo admin_h(mb_substr($t['description'], 0, 50)); ?></div>
            </td>
            <td>
                <span class="material-symbols-outlined" style="font-size:20px;color:#0051d5;vertical-align:middle"><?php echo admin_h($t['icon']); ?></span>
                <code style="font-size:10px"><?php echo admin_h($t['icon']); ?></code>
            </td>
            <td><span class="badge badge-gray"><?php echo $t['sort_order']; ?></span></td>
            <td><?php echo $t['is_active'] ? '<span class="badge-ok">فعال</span>' : '<span class="badge-off">غیرفعال</span>'; ?></td>
            <td style="text-align:left">
                <a href="?edit=<?php echo $t['id']; ?>" class="btn btn-outline btn-sm">ویرایش</a>
                <a href="?action=delete&id=<?php echo $t['id']; ?>" class="btn btn-danger btn-sm ms-1"
                   onclick="return confirm('تایل حذف شود؟')">حذف</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$tiles): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-dim)">تایلی ثبت نشده</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

</div>
<?php include __DIR__ . '/_footer.php'; ?>
