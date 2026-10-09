<?php
/**
 * چت‌بات‌های تخصصی کاربر (همدم) — فهرست و ساخت ربات جدید
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
$page_title = 'چت‌بات تخصصی';

try { hd_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[HD] schema: ' . $e->getMessage()); }

$uid  = (int)$current_user['id'];
$plan = saas_get_plan($pdo, (int)($current_user['plan_id'] ?? 0)) ?: null;
if (!saas_plan_allows($plan, 'has_expert_bot')) {
    include __DIR__ . '/_header.php';
    echo '<div class="topbar"><h1>🤖 چت‌بات تخصصی</h1></div>' . saas_plan_locked_html('چت‌بات تخصصی');
    include __DIR__ . '/_footer.php';
    exit;
}
$max_bots = $plan ? (int)($plan['max_bots'] ?? 1) : 3;   // ۰ = نامحدود
$bots = hd_user_bots($pdo, $uid);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && saas_can('bots_manage')) {
    $act = $_POST['act'] ?? '';
    if ($act === 'create') {
        $name = trim($_POST['name'] ?? '');
        $tpl  = $_POST['template'] ?? 'custom';
        if (mb_strlen($name) < 2) {
            $msg = 'error:نام ربات را وارد کنید.';
        } elseif ($max_bots > 0 && count($bots) >= $max_bots) {
            $msg = 'error:به سقف تعداد ربات در پلن خود (' . $max_bots . ') رسیده‌اید.';
        } else {
            $id = hd_create_bot($pdo, $uid, $name, $tpl);
            header('Location: bot.php?id=' . $id . '&msg=' . urlencode('ok:ربات ساخته شد. حالا منابع دانش آن را اضافه کنید.') . '&tab=sources'); exit;
        }
    } elseif ($act === 'delete') {
        hd_delete_bot($pdo, (int)($_POST['bot_id'] ?? 0), $uid);
        header('Location: bots.php?msg=' . urlencode('ok:ربات و همه اطلاعات آن حذف شد.')); exit;
    }
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];
$templates = hd_templates();
// قالب پیش‌فرض از دسته پلن کاربر (نسخه ۴۵)
$tpl_def = function_exists('pc_user_bot_template') ? pc_user_bot_template($pdo, !empty($current_user['plan_id']) ? saas_get_plan($pdo, (int)$current_user['plan_id']) : null) : '';

include __DIR__ . '/_header.php';
?>
<div class="topbar">
    <h1>🤖 چت‌بات تخصصی</h1>
    <span style="font-size:13px;color:#64748b"><?php echo count($bots); ?><?php echo $max_bots > 0 ? ' / ' . $max_bots : ''; ?> ربات</span>
    <?php if (saas_can('studio') && (biz_plan_models($pdo, $plan, 'image') || biz_plan_models($pdo, $plan, 'video'))): ?><a href="studio.php" class="btn btn-outline btn-sm" style="margin-right:auto">🎨 ساخت و ویرایش تصویر و ویدیو</a><?php endif; ?>
</div>

<?php if ($msg): ?>
<div class="alert <?php echo str_starts_with($msg, 'ok:') ? 'alert-success' : 'alert-danger'; ?>"><?php echo saas_h(preg_replace("/^(ok|error):/", "", $msg)); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-title">💡 چت‌بات تخصصی چیست؟</div>
    <p class="muted" style="line-height:2">
        یک دستیار هوشمند تخصصی (مثلاً مشاور پزشکی یا مشاور کسب‌وکار) با <b>نام، لوگو و رنگ خودتان</b> بسازید،
        منابع معتبر (متن، صفحه یا سایت) به آن بدهید تا فقط بر اساس همان منابع و با ذکر منبع پاسخ دهد،
        و آن را روی <b>دامنه سایت خودتان</b> در اختیار مخاطبانتان بگذارید. هیچ نامی از ما در صفحه چت دیده نمی‌شود.
        هزینه پیام‌های مخاطبان از اعتبار (تومانی) شما کم می‌شود.
    </p>
</div>

<div class="card">
    <div class="card-title">➕ ساخت ربات جدید</div>
    <?php if ($max_bots > 0 && count($bots) >= $max_bots): ?>
        <div class="alert alert-info">به سقف تعداد ربات در پلن خود رسیده‌اید. برای ربات بیشتر <a href="billing.php">پلن را ارتقا دهید</a>.</div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="act" value="create">
        <div class="row2">
            <div class="form-group">
                <label>نام ربات (به مخاطبان نمایش داده می‌شود)</label>
                <input type="text" name="name" maxlength="150" required placeholder="مثلاً: مشاور سلامت دکتر احمدی">
            </div>
            <div class="form-group">
                <label>قالب آماده</label>
                <select name="template">
                    <?php foreach ($templates as $k => $t): ?>
                        <option value="<?php echo saas_h($k); ?>"<?php echo $k === $tpl_def ? ' selected' : ''; ?>><?php echo saas_h($t['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">➕ ساخت ربات</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">🤖 ربات‌های من</div>
    <?php if (!$bots): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px;font-size:13px">هنوز رباتی نساخته‌اید.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>نام</th><th>تخصص</th><th>منابع</th><th>مخاطبان</th><th>هزینه (تومان)</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach ($bots as $b): ?>
            <tr>
                <td style="font-weight:700"><a href="bot.php?id=<?php echo (int)$b['id']; ?>"><?php echo saas_h($b['name']); ?></a></td>
                <td style="font-size:12px;color:#64748b"><?php echo saas_h($b['specialty']); ?></td>
                <td><?php echo (int)$b['sources_count']; ?></td>
                <td><?php echo (int)$b['members_count']; ?></td>
                <td style="font-size:12px"><?php echo number_format((int)$b['tokens_used']); ?></td>
                <td><?php echo $b['is_active'] ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-red">غیرفعال</span>'; ?></td>
                <td style="white-space:nowrap">
                    <a href="bot.php?id=<?php echo (int)$b['id']; ?>" class="btn btn-outline btn-sm">مدیریت</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('ربات «<?php echo saas_h($b['name']); ?>» با همه منابع، مخاطبان و گفتگوها حذف شود؟ این کار برگشت‌پذیر نیست.');">
                        <input type="hidden" name="act" value="delete">
                        <input type="hidden" name="bot_id" value="<?php echo (int)$b['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
