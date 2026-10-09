<?php
/**
 * آرشیو پاسخ‌ها — سوالات تکراری بدون مصرف توکن پاسخ داده می‌شوند
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/kb_nav.php';
$page_title = 'آرشیو پاسخ‌ها';

// --- بررسی امکان در پلن کاربر ---
$plan_chk = saas_get_plan($pdo, (int)($current_user['plan_id'] ?? 0));
if (!saas_plan_allows($plan_chk, 'has_answer_archive')) {
    include __DIR__ . '/_header.php';
    echo kb_topbar() . kb_tabs('archive') . saas_plan_locked_html('آرشیو پاسخ‌ها');
    include __DIR__ . '/_footer.php';
    exit;
}

$uid = (int)$current_user['id'];
$ws  = saas_get_widget_settings($pdo, $uid);
$msg = '';

// --- عملیات ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'settings') {
        $en   = !empty($_POST['cache_enabled']) ? 1 : 0;
        $pdo->prepare("UPDATE saas_widget_settings SET cache_enabled=?, updated_at=NOW() WHERE user_id=?")->execute([$en, $uid]);
        $msg = 'ok:تنظیمات آرشیو ذخیره شد.';
    }
    elseif ($act === 'save') {
        $question = trim($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');
        $id       = (int)($_POST['id'] ?? 0);
        $key      = saas_question_key($question);
        if (mb_strlen($question) < 3 || $key === '') {
            $msg = 'error:سوال خیلی کوتاه است یا کلمه معناداری ندارد.';
        } elseif (mb_strlen($answer) < 3) {
            $msg = 'error:پاسخ را وارد کنید.';
        } elseif ($id) {
            // ویرایش → پاسخ دستی می‌شود (دیگر خودکار پاک نمی‌شود)
            $pdo->prepare("UPDATE saas_answer_cache SET question=?, q_key=?, answer=?, is_manual=1, updated_at=NOW() WHERE id=? AND user_id=?")
                ->execute([mb_substr($question, 0, 1000), $key, $answer, $id, $uid]);
            $msg = 'ok:پاسخ ویرایش شد و به‌عنوان پاسخ ثابت ذخیره شد.';
        } else {
            $pdo->prepare("DELETE FROM saas_answer_cache WHERE user_id=? AND q_key=?")->execute([$uid, $key]);
            $pdo->prepare("INSERT INTO saas_answer_cache (user_id, q_key, question, answer, images, hits, is_manual, enabled, created_at, updated_at) VALUES(?,?,?,?,'{}',0,1,1,NOW(),NOW())")
                ->execute([$uid, $key, mb_substr($question, 0, 1000), $answer]);
            $msg = 'ok:سوال و پاسخ ثابت اضافه شد.';
        }
    }
    elseif ($act === 'freq_save') {
        // افزودن سوال پرتکرار به آرشیو (پیش از رسیدن به حد تکرار) به‌صورت پاسخ ثابت
        $f = $pdo->prepare("SELECT * FROM saas_question_log WHERE id=? AND user_id=?");
        $f->execute([(int)($_POST['log_id'] ?? 0), $uid]);
        if ($fr = $f->fetch()) {
            $pdo->prepare("DELETE FROM saas_answer_cache WHERE user_id=? AND q_key=?")->execute([$uid, $fr['q_key']]);
            $pdo->prepare("INSERT INTO saas_answer_cache (user_id, q_key, question, answer, images, hits, is_manual, enabled, created_at, updated_at) VALUES(?,?,?,?,?,0,1,1,NOW(),NOW())")
                ->execute([$uid, $fr['q_key'], $fr['question'], (string)$fr['answer'], (string)($fr['images'] ?: '{}')]);
            $pdo->prepare("UPDATE saas_question_log SET archived=1 WHERE id=?")->execute([(int)$fr['id']]);
            $msg = 'ok:به آرشیو اضافه شد (پاسخ ثابت).';
        }
    }
    elseif ($act === 'clear_auto') {
        saas_cache_clear_auto($pdo, $uid);
        $msg = 'ok:همه پاسخ‌های خودکار پاک شدند (پاسخ‌های ثابت باقی ماندند).';
    }
    header('Location: answers.php?msg=' . urlencode($msg)); exit;
}

if (isset($_GET['del']) && is_numeric($_GET['del'])) {
    $pdo->prepare("DELETE FROM saas_answer_cache WHERE id=? AND user_id=?")->execute([(int)$_GET['del'], $uid]);
    header('Location: answers.php?msg=' . urlencode('ok:حذف شد.')); exit;
}
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare("UPDATE saas_answer_cache SET enabled = 1 - enabled WHERE id=? AND user_id=?")->execute([(int)$_GET['toggle'], $uid]);
    header('Location: answers.php'); exit;
}
if (isset($_GET['pin']) && is_numeric($_GET['pin'])) {
    $pdo->prepare("UPDATE saas_answer_cache SET is_manual = 1, updated_at=NOW() WHERE id=? AND user_id=?")->execute([(int)$_GET['pin'], $uid]);
    header('Location: answers.php?msg=' . urlencode('ok:پاسخ ثابت شد و با تغییر پایگاه دانش پاک نمی‌شود.')); exit;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

$edit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM saas_answer_cache WHERE id=? AND user_id=?");
    $e->execute([(int)$_GET['edit'], $uid]);
    $edit = $e->fetch() ?: null;
}

// --- لیست ---
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM saas_answer_cache WHERE user_id=?");
$cnt->execute([$uid]);
$total = (int)$cnt->fetchColumn();
$pages = (int)ceil($total / $limit);

$st = $pdo->prepare("SELECT * FROM saas_answer_cache WHERE user_id=? ORDER BY is_manual DESC, hits DESC, id DESC LIMIT {$limit} OFFSET {$offset}");
$st->execute([$uid]);
$rows = $st->fetchAll() ?: [];

$sum = $pdo->prepare("SELECT COALESCE(SUM(hits),0) FROM saas_answer_cache WHERE user_id=?");
$sum->execute([$uid]);
$total_hits = (int)$sum->fetchColumn();

$days = (int)($ws['cache_days'] ?? 7);

include __DIR__ . '/_header.php';
?>

<?php echo kb_topbar(number_format($total) . ' سوال — ' . number_format($total_hits) . ' بار پاسخ بدون مصرف توکن') . kb_tabs('archive'); ?>

<?php if ($msg): ?>
<div class="alert <?php echo str_starts_with($msg, 'ok:') ? 'alert-success' : 'alert-danger'; ?>"><?php echo saas_h(preg_replace("/^(ok|error):/", "", $msg)); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-title">⚙️ تنظیمات آرشیو</div>
    <p class="muted" style="line-height:1.9;margin-bottom:12px">
        وقتی بازدیدکننده سوالی بپرسد که قبلاً (با همان کلمات اصلی) پرسیده شده، پاسخ از آرشیو داده می‌شود و <b>هیچ توکنی مصرف نمی‌شود</b>.
        <b>آرشیو خودکار:</b> هر سوال (یا سوال‌های مشابه آن) وقتی دست‌کم <b><?php echo (int)kb_repeat_min($pdo); ?> بار</b> پرسیده شود، خودکار در آرشیو ذخیره می‌شود. فقط سوال‌های مستقل شمرده می‌شوند (سوال‌هایی مثل «قیمت اینو بگو» که به پیام قبلی وابسته‌اند نه).
        با هر تغییر در پایگاه دانش، محصولات یا تنظیمات، پاسخ‌های خودکار پاک می‌شوند تا اطلاعات قدیمی داده نشود. «پاسخ‌های ثابت» هرگز پاک نمی‌شوند.
    </p>
    <form method="post" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="act" value="settings">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="cache_enabled" value="1" <?php echo !empty($ws['cache_enabled']) ? 'checked' : ''; ?> style="width:18px;height:18px">
            آرشیو پاسخ‌ها فعال باشد
        </label>
        <button type="submit" class="btn btn-primary btn-sm">💾 ذخیره</button>
    </form>
</div>

<div class="card">
    <div class="card-title"><?php echo $edit ? '✏️ ویرایش پاسخ' : '➕ افزودن سوال و پاسخ ثابت'; ?></div>
    <form method="post">
        <input type="hidden" name="act" value="save">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
        <div class="form-group">
            <label>سوال</label>
            <input type="text" name="question" value="<?php echo saas_h($edit['question'] ?? ''); ?>" maxlength="500" placeholder="مثلاً: ساعت کاری شما چیه؟" required>
        </div>
        <div class="form-group">
            <label>پاسخ</label>
            <textarea name="answer" rows="5" placeholder="پاسخ کامل… (برای آیتم‌بندی، هر مورد را در یک خط با «- » شروع کنید؛ لینک: [عنوان](https://...))" required><?php echo saas_h($edit['answer'] ?? ''); ?></textarea>
            <div class="muted">می‌توانید در متن پاسخ از <code dir="ltr">{{name}}</code> استفاده کنید تا نام کاربر (در صورت مشخص بودن) جایگزین شود.</div>
        </div>
        <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary"><?php echo $edit ? '💾 ذخیره' : '➕ افزودن'; ?></button>
            <?php if ($edit): ?><a href="answers.php" class="btn btn-outline">انصراف</a><?php endif; ?>
        </div>
    </form>
</div>

<?php $freq = function_exists('kb_frequent_questions') ? kb_frequent_questions($pdo, $uid, 20) : []; if ($freq): ?>
<div class="card">
    <div class="card-title">🔁 سوالات پرتکرار (در حال شمارش)</div>
    <p class="muted" style="margin-bottom:10px;font-size:12.5px">این سوال‌ها هنوز به <?php echo (int)kb_repeat_min($pdo); ?> بار نرسیده‌اند. اگر پاسخشان درست است، می‌توانید همین حالا آن‌ها را به آرشیو اضافه کنید.</p>
    <div class="table-wrap"><table><thead><tr><th>سوال</th><th>آخرین پاسخ</th><th>تکرار</th><th></th></tr></thead><tbody>
    <?php foreach ($freq as $f): ?>
        <tr><td style="font-weight:600;max-width:220px"><?php echo saas_h($f['question']); ?></td>
            <td style="color:#64748b;font-size:12px;max-width:300px"><?php echo saas_h(mb_substr((string)$f['answer'], 0, 140)); ?><?php echo mb_strlen((string)$f['answer']) > 140 ? '…' : ''; ?></td>
            <td style="font-size:12px"><?php echo (int)$f['cnt']; ?> / <?php echo (int)kb_repeat_min($pdo); ?></td>
            <td><form method="post" style="margin:0"><input type="hidden" name="act" value="freq_save"><input type="hidden" name="log_id" value="<?php echo (int)$f['id']; ?>"><button class="btn btn-outline btn-sm">📌 افزودن به آرشیو</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center">
        <span>📦 سوالات آرشیوشده</span>
        <form method="post" onsubmit="return confirm('همه پاسخ‌های خودکار پاک شوند؟');" style="margin:0">
            <input type="hidden" name="act" value="clear_auto">
            <button type="submit" class="btn btn-danger btn-sm">🗑 پاک‌کردن پاسخ‌های خودکار</button>
        </form>
    </div>
    <?php if (!$rows): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px;font-size:13px">هنوز پاسخی آرشیو نشده است. سوال‌هایی که چند بار تکرار شوند، خودکار اینجا ذخیره می‌شوند.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>سوال</th><th>پاسخ</th><th>نوع</th><th>استفاده</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $expired = false; /* بدون انقضای تاریخی */ ?>
            <tr style="<?php echo !$r['enabled'] || $expired ? 'opacity:.55' : ''; ?>">
                <td style="font-weight:600;max-width:220px"><?php echo saas_h($r['question']); ?></td>
                <td style="color:#64748b;font-size:12px;max-width:300px"><?php echo saas_h(mb_substr($r['answer'], 0, 140)); ?><?php echo mb_strlen($r['answer']) > 140 ? '…' : ''; ?></td>
                <td style="font-size:12px"><?php echo $r['is_manual'] ? '<span style="color:#059669;font-weight:600">📌 ثابت</span>' : '<span style="color:#64748b">خودکار</span>'; ?></td>
                <td style="font-size:12px"><?php echo number_format((int)$r['hits']); ?> بار</td>
                <td style="font-size:12px"><?php echo !$r['enabled'] ? 'غیرفعال' : ($expired ? 'منقضی' : '<span style="color:#059669">فعال</span>'); ?></td>
                <td style="white-space:nowrap">
                    <a href="answers.php?edit=<?php echo (int)$r['id']; ?>" class="btn btn-outline btn-sm">ویرایش</a>
                    <?php if (!$r['is_manual']): ?><a href="answers.php?pin=<?php echo (int)$r['id']; ?>" class="btn btn-outline btn-sm" title="ثابت کردن (با تغییر پایگاه دانش پاک نشود)">📌</a><?php endif; ?>
                    <a href="answers.php?toggle=<?php echo (int)$r['id']; ?>" class="btn btn-outline btn-sm"><?php echo $r['enabled'] ? 'غیرفعال' : 'فعال'; ?></a>
                    <a href="answers.php?del=<?php echo (int)$r['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف شود؟')">حذف</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div style="margin-top:16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="answers.php?page=<?php echo $i; ?>" style="padding:5px 12px;border-radius:6px;border:1px solid <?php echo $i == $page ? '#7c3aed' : '#e2e8f0'; ?>;background:<?php echo $i == $page ? '#7c3aed' : '#fff'; ?>;color:<?php echo $i == $page ? '#fff' : '#374151'; ?>;font-size:13px"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
