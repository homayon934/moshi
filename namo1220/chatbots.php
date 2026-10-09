<?php
/**
 * تنظیمات سامانه‌ای چت‌بات‌های تخصصی: واقع‌گرایی، پایبندی به منابع، صدا، فایل
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
try { hd_ensure_schema($pdo); } catch (\Throwable $e) {}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: chatbots.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $act = $_POST['act'] ?? '';
    $pc = fn($k, $d) => max(0, min(100, (int)($_POST[$k] ?? $d)));
    if ($act === 'global') {
        biz_set($pdo, 'hd_realism', $pc('hd_realism', 70));
        biz_set($pdo, 'hd_grounding', $pc('hd_grounding', 80));
        biz_set($pdo, 'hd_files_on', !empty($_POST['hd_files_on']) ? 1 : 0);
        biz_set($pdo, 'hd_file_cost', max(0, (int)($_POST['hd_file_cost'] ?? 0)));
        biz_set($pdo, 'hd_voice_on', !empty($_POST['hd_voice_on']) ? 1 : 0);
        $v = preg_replace('/[^A-Za-z0-9._\-\/]/', '', (string)($_POST['hd_tts_voice'] ?? ''));
        if ($v !== '') biz_set($pdo, 'hd_tts_voice', mb_substr($v, 0, 80));
        $msg = 'ok:تنظیمات ذخیره شد.';
    } elseif ($act === 'bots') {
        $up = $pdo->prepare("UPDATE hd_bots SET realism=?, grounding=? WHERE id=?");
        foreach ((array)($_POST['r'] ?? []) as $id => $r) {
            $g = $_POST['g'][$id] ?? '';
            $up->execute([$r === '' ? -1 : max(0, min(100, (int)$r)), $g === '' ? -1 : max(0, min(100, (int)$g)), (int)$id]);
        }
        $msg = 'ok:تنظیمات ربات‌ها ذخیره شد.';
    } elseif ($act === 'widget') {
        biz_set($pdo, 'ws_realism', $pc('ws_realism', 70));
        biz_set($pdo, 'ws_grounding', $pc('ws_grounding', 80));
        $up = $pdo->prepare("UPDATE saas_widget_settings SET realism=?, grounding=? WHERE user_id=?");
        foreach ((array)($_POST['wr'] ?? []) as $uid => $r) {
            $g = $_POST['wg'][$uid] ?? '';
            $up->execute([$r === '' ? -1 : max(0, min(100, (int)$r)), $g === '' ? -1 : max(0, min(100, (int)$g)), (int)$uid]);
        }
        $msg = 'ok:تنظیمات ویجت‌ها ذخیره شد.';
    }
    header('Location: chatbots.php?msg=' . urlencode($msg)); exit;
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];

$s = hd_sys($pdo);
$q = trim($_GET['q'] ?? '');
$sql = "SELECT b.id, b.name, b.realism, b.grounding, b.is_active, u.full_name, u.email FROM hd_bots b LEFT JOIN saas_users u ON u.id=b.user_id";
$params = [];
if ($q !== '') { $sql .= " WHERE b.name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?"; $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']; }
$st = $pdo->prepare($sql . " ORDER BY b.id DESC LIMIT 300");
$st->execute($params);
$bots = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$cfg = saas_get_api_config($pdo);
$bs = biz_settings($pdo);
$ws_r = isset($bs['ws_realism']) && $bs['ws_realism'] !== '' ? (int)$bs['ws_realism'] : 70;
$ws_g = isset($bs['ws_grounding']) && $bs['ws_grounding'] !== '' ? (int)$bs['ws_grounding'] : 80;
$wq = trim($_GET['wq'] ?? '');
$wsql = "SELECT w.user_id, w.realism, w.grounding, w.widget_title, u.full_name, u.email FROM saas_widget_settings w JOIN saas_users u ON u.id=w.user_id";
$wparams = [];
if ($wq !== '') { $wsql .= " WHERE u.full_name LIKE ? OR u.email LIKE ? OR w.widget_title LIKE ?"; $wparams = ['%' . $wq . '%', '%' . $wq . '%', '%' . $wq . '%']; }
try { $wst = $pdo->prepare($wsql . " ORDER BY w.user_id DESC LIMIT 300"); $wst->execute($wparams); $widgets = $wst->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { $widgets = []; }

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🤖 تنظیمات چت‌بات‌ها و ویجت</h2>';
?>
<style>.rng{display:flex;align-items:center;gap:10px}.rng input[type=range]{flex:1}.rng b{min-width:44px;text-align:center}.lvl{font-size:12px;color:#64748b;line-height:1.9;margin-top:4px}</style>
<?php if ($msg): ?><div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>

<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
    <input type="hidden" name="act" value="global">
    <h3 style="font-size:15px;margin-bottom:10px">🎚 رفتار پاسخ‌ها (پیش‌فرض همه چت‌بات‌ها)</h3>
    <div class="row2">
        <div>
            <label>درصد واقع‌گرایی و صراحت</label>
            <div class="rng"><input type="range" name="hd_realism" min="0" max="100" step="5" value="<?php echo (int)$s['hd_realism']; ?>" oninput="this.nextElementSibling.textContent=this.value+'٪'"><b><?php echo (int)$s['hd_realism']; ?>٪</b></div>
            <div class="lvl">۸۰٪ به بالا: کاملاً صریح و بی‌تعارف (ریسک‌ها و خبرهای ناخوشایند بی‌پرده گفته می‌شود) — ۵۰ تا ۸۰٪: واقع‌بین با همدلی — زیر ۵۰٪: لحن گرم و امیدبخش.</div>
        </div>
        <div>
            <label>درصد پایبندی به منابع</label>
            <div class="rng"><input type="range" name="hd_grounding" min="0" max="100" step="5" value="<?php echo (int)$s['hd_grounding']; ?>" oninput="this.nextElementSibling.textContent=this.value+'٪'"><b><?php echo (int)$s['hd_grounding']; ?>٪</b></div>
            <div class="lvl">۹۰٪ به بالا: فقط از منابع، پرونده و یادداشت‌های مشاور — ۶۰ تا ۹۰٪: اولویت با منابع؛ دانش عمومی با ذکر «از منابع نیست» — زیر ۶۰٪: آزادتر. (هرچه بالاتر، پاسخ‌ها دقیق‌تر و محافظه‌کارتر.)</div>
        </div>
    </div>

    <h3 style="font-size:15px;margin:18px 0 10px">📎 فایل در چت</h3>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="hd_files_on" value="1" style="width:17px!important;height:17px" <?php echo $s['hd_files_on'] ? 'checked' : ''; ?>> مراجعان بتوانند فایل بفرستند (حداکثر <?php echo HD_MAX_FILES; ?> فایل در هر پیام، هر فایل تا ۵ مگابایت — تصویر، PDF، Word، متن)</label>
    <div class="row2" style="margin-top:8px"><div><label>هزینه اضافه هر فایل (تومان؛ ۰ = فقط هزینه متن)</label><input type="number" name="hd_file_cost" min="0" value="<?php echo (int)$s['hd_file_cost']; ?>"></div><div></div></div>
    <p class="muted" style="margin-top:4px">برای خواندن تصویر، مدل انتخابی باید «بینایی» داشته باشد (مثل gpt-4o-mini، gemini). اگر نداشته باشد، از مراجع خواسته می‌شود محتوای تصویر را توضیح دهد.</p>

    <h3 style="font-size:15px;margin:18px 0 10px">🎤 صدا (از طریق AvalAI)</h3>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="hd_voice_on" value="1" style="width:17px!important;height:17px" <?php echo $s['hd_voice_on'] ? 'checked' : ''; ?>> پیام صوتی مراجع (تبدیل صدا به متن) و پخش صوتی پاسخ‌ها فعال باشد</label>
    <?php if (empty($cfg['avalai_api_key'])): ?><p style="color:#dc2626;font-size:12.5px;margin-top:4px">⚠️ کلید AvalAI در «تنظیمات API» وارد نشده؛ امکانات صوتی کار نمی‌کند.</p><?php endif; ?>
    <div class="row2" style="margin-top:8px">
        <div><label>صدا (voice) برای خواندن پاسخ‌ها</label><input type="text" name="hd_tts_voice" dir="ltr" value="<?php echo admin_h($s['hd_tts_voice']); ?>"><div class="lvl">nova، alloy، coral، shimmer، onyx، echo …</div></div>
        <div></div>
    </div>
    <p class="muted">مدل‌های «صوت به متن» و «متن به صوت»، مدل‌های جایگزین و قیمت آن‌ها از صفحه <a href="models.php?tab=stt">🧠 مدل‌ها</a> تعیین می‌شود. پخش هر پاسخ فقط یک بار هزینه دارد (فایل صوتی ذخیره می‌شود). هزینه‌ها از اعتبار صاحب چت‌بات کم می‌شود.</p>
    <button class="btn btn-primary btn-sm" style="margin-top:10px">💾 ذخیره</button>
</form>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <h3 style="font-size:15px">🎯 تنظیم جداگانه برای هر چت‌بات</h3>
        <form method="get" style="display:flex;gap:6px"><input type="text" name="q" value="<?php echo admin_h($q); ?>" placeholder="جستجوی ربات یا کاربر" style="width:220px !important"><button class="btn btn-sm" style="background:#f1f5f9;color:#374151">جستجو</button></form>
    </div>
    <p class="muted" style="margin-bottom:10px">خالی = استفاده از مقدار پیش‌فرض بالا.</p>
    <?php if (!$bots): ?><p class="muted" style="text-align:center;padding:14px">چت‌باتی وجود ندارد.</p><?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="bots">
        <div style="overflow-x:auto"><table>
            <thead><tr><th>ربات</th><th>صاحب</th><th>واقع‌گرایی ٪</th><th>پایبندی به منابع ٪</th><th>وضعیت</th></tr></thead><tbody>
            <?php foreach ($bots as $b): ?>
            <tr>
                <td><b><?php echo admin_h($b['name']); ?></b></td>
                <td style="font-size:12px"><?php echo admin_h($b['full_name'] ?? ''); ?> <span dir="ltr" style="color:#94a3b8"><?php echo admin_h($b['email'] ?? ''); ?></span></td>
                <td><input type="number" name="r[<?php echo (int)$b['id']; ?>]" min="0" max="100" value="<?php echo (int)$b['realism'] >= 0 ? (int)$b['realism'] : ''; ?>" placeholder="<?php echo (int)$s['hd_realism']; ?>" style="width:90px !important"></td>
                <td><input type="number" name="g[<?php echo (int)$b['id']; ?>]" min="0" max="100" value="<?php echo (int)$b['grounding'] >= 0 ? (int)$b['grounding'] : ''; ?>" placeholder="<?php echo (int)$s['hd_grounding']; ?>" style="width:90px !important"></td>
                <td><?php echo $b['is_active'] ? '<span class="badge-ok">فعال</span>' : '<span class="badge-off">غیرفعال</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <button class="btn btn-primary btn-sm" style="margin-top:10px">💾 ذخیره</button>
    </form>
    <?php endif; ?>
</div>
<form method="post" class="card" id="widget">
    <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
    <input type="hidden" name="act" value="widget">
    <h3 style="font-size:15px;margin-bottom:10px">💬 ویجت پاسخگوی هوشمند سایت — رفتار پاسخ‌ها</h3>
    <div class="row2">
        <div>
            <label>درصد واقع‌گرایی و صراحت (پیش‌فرض همه ویجت‌ها)</label>
            <div class="rng"><input type="range" name="ws_realism" min="0" max="100" step="5" value="<?php echo $ws_r; ?>" oninput="this.nextElementSibling.textContent=this.value+'٪'"><b><?php echo $ws_r; ?>٪</b></div>
            <div class="lvl">۸۰٪ به بالا: کاملاً صریح — ۵۰ تا ۸۰٪: واقع‌بین با همدلی — زیر ۵۰٪: گرم و مثبت.</div>
        </div>
        <div>
            <label>درصد پایبندی به اطلاعات کسب‌وکار (پیش‌فرض همه ویجت‌ها)</label>
            <div class="rng"><input type="range" name="ws_grounding" min="0" max="100" step="5" value="<?php echo $ws_g; ?>" oninput="this.nextElementSibling.textContent=this.value+'٪'"><b><?php echo $ws_g; ?>٪</b></div>
            <div class="lvl">۹۰٪ به بالا: فقط از پایگاه دانش، محصولات و اطلاعات سایت — ۶۰ تا ۹۰٪: اولویت با اطلاعات کسب‌وکار؛ دانش عمومی با ذکر اینکه از اطلاعات رسمی نیست — زیر ۶۰٪: آزادتر. قیمت، موجودی، آدرس و شماره در هیچ حالتی حدس زده نمی‌شود.</div>
        </div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin:16px 0 8px">
        <b style="font-size:13px">🎯 تنظیم جداگانه برای ویجت هر کاربر (خالی = پیش‌فرض بالا)</b>
        <span style="display:flex;gap:6px"><input type="text" id="wqi" value="<?php echo admin_h($wq); ?>" placeholder="جستجوی کاربر" style="width:200px !important"><a class="btn btn-sm" style="background:#f1f5f9;color:#374151" href="#" onclick="location.href='chatbots.php?wq='+encodeURIComponent(document.getElementById('wqi').value)+'#widget';return false;">جستجو</a></span>
    </div>
    <?php if (!$widgets): ?><p class="muted" style="text-align:center;padding:14px">ویجتی پیدا نشد.</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>کاربر</th><th>عنوان ویجت</th><th>واقع‌گرایی ٪</th><th>پایبندی ٪</th></tr></thead><tbody>
        <?php foreach ($widgets as $w): ?>
        <tr>
            <td style="font-size:12px"><b><?php echo admin_h($w['full_name'] ?? ''); ?></b> <span dir="ltr" style="color:#94a3b8"><?php echo admin_h($w['email'] ?? ''); ?></span></td>
            <td style="font-size:12px"><?php echo admin_h($w['widget_title'] ?? ''); ?></td>
            <td><input type="number" name="wr[<?php echo (int)$w['user_id']; ?>]" min="0" max="100" value="<?php echo (int)($w['realism'] ?? -1) >= 0 ? (int)$w['realism'] : ''; ?>" placeholder="<?php echo $ws_r; ?>" style="width:90px !important"></td>
            <td><input type="number" name="wg[<?php echo (int)$w['user_id']; ?>]" min="0" max="100" value="<?php echo (int)($w['grounding'] ?? -1) >= 0 ? (int)$w['grounding'] : ''; ?>" placeholder="<?php echo $ws_g; ?>" style="width:90px !important"></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" style="margin-top:10px">💾 ذخیره</button>
</form>
<?php include __DIR__ . '/_footer.php'; ?>
