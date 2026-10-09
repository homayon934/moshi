<?php
/**
 * امنیت ورود (محافظ IP):
 *  - تنظیم تعداد ورود ناموفق مجاز و مدت قفل‌ها
 *  - فهرست IPهای قفل/مسدود با رفع مسدودی
 *  - فهرست IPهای امن (هرگز قفل نمی‌شوند)
 *  - گزارش ورودهای ناموفق با نمودار
 */
require_once __DIR__ . '/_bootstrap.php';
ipg_ensure_schema($pdo);
ipg_recovery($pdo);

$self = 'security.php';
$areas = ipg_areas();
$my_ip = ipg_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: ' . $self . '?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $act = (string)($_POST['act'] ?? '');
    $ip = trim((string)($_POST['ip'] ?? ''));
    $msg = 'error:درخواست نامعتبر است.';
    if ($act === 'admin_pw') {
        $cur = (string)($_POST['cur'] ?? ''); $n1 = (string)($_POST['new1'] ?? ''); $n2 = (string)($_POST['new2'] ?? '');
        if (!sec_admin_password_ok($pdo, $cur)) { $msg = 'error:رمز فعلی اشتباه است.'; if (function_exists('ipg_fail')) ipg_fail($pdo, 'admin', 'تغییر رمز مدیر'); }
        elseif ($n1 !== $n2) $msg = 'error:تکرار رمز جدید یکسان نیست.';
        elseif (strlen($n1) < 12 || sec_weak_password($n1)) $msg = 'error:رمز جدید باید حداقل ۱۲ کاراکتر و ترکیبی از حروف بزرگ و کوچک، عدد یا نماد باشد.';
        elseif (hash_equals($cur, $n1)) $msg = 'error:رمز جدید باید با رمز فعلی فرق داشته باشد.';
        else {
            biz_set($pdo, 'admin_pw_hash', password_hash($n1, PASSWORD_DEFAULT));
            unset($_SESSION['adm_pw_weak']);
            session_regenerate_id(true);
            $msg = 'ok:رمز مدیر تغییر کرد. از این پس فقط رمز جدید پذیرفته می‌شود (رمز config.php دیگر کار نمی‌کند).';
        }
        header('Location: ' . $self . '?msg=' . urlencode($msg) . '#pw'); exit;
    }
    if ($act === 'conf') {
        biz_set($pdo, 'ipg_on', empty($_POST['on']) ? 0 : 1);
        biz_set($pdo, 'ipg_max', max(2, min(50, (int)($_POST['max'] ?? 5))));
        biz_set($pdo, 'ipg_lock1', max(1, min(1440, (int)($_POST['lock1'] ?? 3))));
        biz_set($pdo, 'ipg_lock2', max(1, min(10080, (int)($_POST['lock2'] ?? 15))));
        biz_set($pdo, 'ipg_decay', max(1, min(720, (int)($_POST['decay'] ?? 24))));
        $msg = 'ok:تنظیمات امنیت ورود ذخیره شد.';
    } elseif (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $msg = 'error:آدرس IP معتبر نیست.';
    } elseif ($act === 'unblock') {
        ipg_unblock($pdo, $ip);
        $msg = 'ok:قفل/مسدودی IP ' . $ip . ' برداشته شد.';
    } elseif ($act === 'block') {
        if ($ip === $my_ip) $msg = 'error:نمی‌توانید IP فعلی خودتان را مسدود کنید.';
        else { ipg_admin_set($pdo, $ip, ['blocked' => 1, 'level' => 3, 'whitelist' => 0, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 190)]); $msg = 'ok:IP ' . $ip . ' مسدود شد.'; }
    } elseif ($act === 'wl_add') {
        ipg_admin_set($pdo, $ip, ['whitelist' => 1, 'blocked' => 0, 'locked_until' => null, 'fails' => 0, 'level' => 0, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 190)]);
        $msg = 'ok:IP ' . $ip . ' به فهرست امن اضافه شد و هرگز قفل نمی‌شود.';
    } elseif ($act === 'wl_del') {
        ipg_admin_set($pdo, $ip, ['whitelist' => 0]);
        $msg = 'ok:IP ' . $ip . ' از فهرست امن حذف شد.';
    } elseif ($act === 'del') {
        $pdo->prepare("DELETE FROM saas_ip_guard WHERE ip=?")->execute([$ip]);
        $msg = 'ok:سابقه IP ' . $ip . ' پاک شد.';
    }
    header('Location: ' . $self . '?msg=' . urlencode($msg)); exit;
}

$c = ipg_conf($pdo);
$now = date('Y-m-d H:i:s');
$q = function ($sql, $p = []) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { return []; } };
$blocked = $q("SELECT * FROM saas_ip_guard WHERE whitelist=0 AND (blocked=1 OR locked_until > ?) ORDER BY blocked DESC, updated_at DESC LIMIT 300", [$now]);
$watch = $q("SELECT * FROM saas_ip_guard WHERE whitelist=0 AND blocked=0 AND (locked_until IS NULL OR locked_until <= ?) AND (fails > 0 OR level > 0) ORDER BY updated_at DESC LIMIT 100", [$now]);
$white = $q("SELECT * FROM saas_ip_guard WHERE whitelist=1 ORDER BY updated_at DESC");
$log = $q("SELECT * FROM saas_ip_guard_log ORDER BY id DESC LIMIT 100");
$f24 = $q("SELECT COUNT(*) AS n FROM saas_ip_guard_log WHERE created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]);
$fail24 = (int)($f24[0]['n'] ?? 0);
$n_block = count(array_filter($blocked, fn($r) => !empty($r['blocked'])));
$n_lock = count($blocked) - $n_block;
$my_row = $my_ip !== '' ? ipg_row($pdo, $my_ip) : null;

// نمودارها
$sd = ch_days(30);
$s_fail = ch_daily($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM saas_ip_guard_log WHERE created_at>=? GROUP BY DATE(created_at)", [], $sd['keys']);
$s_lock = ch_daily($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM saas_ip_guard_log WHERE event<>'fail' AND created_at>=? GROUP BY DATE(created_at)", [], $sd['keys']);
$s_area = [];
$acol = ['admin' => '#e34948', 'user' => '#2a78d6', 'aff' => '#eda100', 'bot' => '#1baf7a', 'plan' => '#4a3aa7', 'reset' => '#eb6834'];
foreach ($q("SELECT area, COUNT(*) AS n FROM saas_ip_guard_log WHERE created_at >= ? GROUP BY area", [$sd['keys'][0] . ' 00:00:00']) as $r)
    $s_area[] = ['label' => $areas[$r['area']] ?? $r['area'], 'value' => (int)$r['n'], 'color' => $acol[$r['area']] ?? '#9aa3af'];

$fmt = fn($d) => $d ? (function_exists('biz_jdate') ? biz_jdate($d, true) : $d) : '—';
$state = function ($r) use ($now) {
    if (!empty($r['whitelist'])) return ['🟢 امن', 'badge-ok'];
    if (!empty($r['blocked'])) return ['⛔ مسدود دائمی', 'badge-err'];
    if (!empty($r['locked_until']) && $r['locked_until'] > $now) return ['⏳ قفل تا ' . ipg_wait_text(strtotime($r['locked_until']) - time()) . ' دیگر', 'badge-off'];
    return ['👀 زیر نظر', 'badge-off'];
};
$msg = (string)($_GET['msg'] ?? '');

include __DIR__ . '/_header.php';
?>
<h2 class="page-title">🛡️ امنیت ورود</h2>
<?php if ($msg !== ''): ?><div class="alert <?php echo str_starts_with($msg, 'ok:') ? 'alert-success' : 'alert-danger'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div><?php endif; ?>
<?php if (!$c['on']): ?><div class="alert alert-warning">⚠️ محافظ ورود خاموش است؛ ورودهای ناموفق شمرده نمی‌شوند.</div><?php endif; ?>

<div class="stat-grid">
    <a class="stat-box" href="#blocked"><div class="icon">⛔</div><div class="val"><?php echo number_format($n_block); ?></div><div class="lbl">IP مسدود دائمی</div></a>
    <a class="stat-box" href="#blocked"><div class="icon">⏳</div><div class="val"><?php echo number_format($n_lock); ?></div><div class="lbl">قفل موقت فعال</div></a>
    <a class="stat-box" href="#log"><div class="icon">🚫</div><div class="val"><?php echo number_format($fail24); ?></div><div class="lbl">ورود ناموفق در ۲۴ ساعت</div></a>
</div>

<div class="chx-grid">
    <?php
    echo ch_card('📈 ورودهای ناموفق', ch_line($sd['labels'], [['name' => 'ورود ناموفق', 'data' => $s_fail, 'color' => '#2a78d6'], ['name' => 'قفل یا مسدودی', 'data' => $s_lock, 'color' => '#e34948']], ['unit' => 'مورد', 'empty' => 'در ۳۰ روز اخیر ورود ناموفقی ثبت نشده است 🎉']), '۳۰ روز اخیر', 'wide');
    echo ch_card('🍩 بخش‌های هدف', ch_donut($s_area, ['unit' => 'تلاش', 'center' => 'تلاش ناموفق', 'empty' => 'موردی نیست.']), '۳۰ روز اخیر');
    ?>
</div>

<div class="card" id="pw" style="<?php echo sec_admin_password_weak($pdo) ? 'border:2px solid #dc2626' : ''; ?>">
    <h3 style="font-size:14px;margin-bottom:8px">🔑 رمز ورود مدیر کل</h3>
    <?php if (sec_admin_password_weak($pdo)): ?>
    <div class="alert alert-danger" style="margin-bottom:12px">⚠️ رمز فعلی مدیر (از فایل config.php) ضعیف یا پیش‌فرض است و به‌راحتی حدس زده می‌شود. برای ادامه کار با پنل، یک رمز قوی بگذارید.</div>
    <?php else: ?>
    <p style="font-size:12.5px;color:#475569;margin-bottom:10px">رمز مدیر به‌صورت رمزنگاری‌شده (هش) ذخیره شده است. برای امنیت بیشتر، در «پیامک، ورود و ایمیل» ورود با کد پیامکی مدیر را هم فعال کنید.</p>
    <?php endif; ?>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="admin_pw">
        <div><label class="form-label">رمز فعلی</label><input class="form-control" type="password" name="cur" required autocomplete="current-password" dir="ltr"></div>
        <div><label class="form-label">رمز جدید (حداقل ۱۲ کاراکتر)</label><input class="form-control" type="password" name="new1" required minlength="12" autocomplete="new-password" dir="ltr"></div>
        <div><label class="form-label">تکرار رمز جدید</label><input class="form-control" type="password" name="new2" required minlength="12" autocomplete="new-password" dir="ltr"></div>
        <div><button class="btn btn-primary">🔑 تغییر رمز</button></div>
    </form>
</div>

<div class="row2">
    <div class="card">
        <h3 style="font-size:14px;margin-bottom:12px">⚙️ قوانین قفل</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="conf">
            <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px;font-weight:600"><input type="checkbox" name="on" value="1" <?php echo $c['on'] ? 'checked' : ''; ?>> محافظ ورود روشن باشد</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div><label class="form-label">تعداد ورود ناموفق مجاز</label><input class="form-control" type="number" name="max" min="2" max="50" value="<?php echo (int)$c['max']; ?>"><small class="text-muted">بیش از این ← قفل</small></div>
                <div><label class="form-label">فراموشی سابقه (ساعت)</label><input class="form-control" type="number" name="decay" min="1" max="720" value="<?php echo (int)$c['decay']; ?>"><small class="text-muted">بدون قفل جدید ← از صفر</small></div>
                <div><label class="form-label">قفل مرحله اول (دقیقه)</label><input class="form-control" type="number" name="lock1" min="1" max="1440" value="<?php echo (int)$c['lock1']; ?>"></div>
                <div><label class="form-label">قفل مرحله دوم (دقیقه)</label><input class="form-control" type="number" name="lock2" min="1" max="10080" value="<?php echo (int)$c['lock2']; ?>"></div>
            </div>
            <p style="font-size:12.5px;color:#475569;line-height:2;margin:12px 0">
                اگر از یک IP بیش از <b><?php echo (int)$c['max']; ?></b> ورود ناموفق (رمز یا کد پیامکی اشتباه — در پنل مدیر، پنل کاربران و همکاران، همکاری در فروش، ورود مخاطبان همه چت‌بات‌ها و خرید پلن) ثبت شود:
                بار اول <b><?php echo (int)$c['lock1']; ?> دقیقه</b> قفل، بار دوم <b><?php echo (int)$c['lock2']; ?> دقیقه</b> قفل و بار سوم <b>مسدود دائمی</b> می‌شود.
            </p>
            <button class="btn btn-primary">💾 ذخیره</button>
        </form>
    </div>
    <div class="card">
        <h3 style="font-size:14px;margin-bottom:12px">🧭 IP فعلی شما</h3>
        <div style="font-size:18px;font-weight:800;direction:ltr;text-align:right;margin-bottom:8px"><?php echo admin_h($my_ip ?: '—'); ?></div>
        <?php if ($my_row && !empty($my_row['whitelist'])): ?>
            <p style="color:#0a6640;font-size:13px">✅ این IP در فهرست امن است و هرگز قفل نمی‌شود.</p>
        <?php elseif ($my_ip !== ''): ?>
            <form method="post" style="margin-bottom:10px"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="wl_add"><input type="hidden" name="ip" value="<?php echo admin_h($my_ip); ?>"><input type="hidden" name="note" value="IP مدیر">
                <button class="btn btn-success btn-sm">🟢 افزودن IP من به فهرست امن</button></form>
            <p style="font-size:12px;color:#64748b;line-height:1.9">اگر اینترنت شما IP ثابت دارد، آن را امن کنید تا هیچ‌وقت پشت قفل نمانید.</p>
        <?php endif; ?>
        <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:10px 12px;font-size:12.5px;color:#475569;line-height:2;margin-top:10px">
            🆘 <b>راه نجات:</b> اگر روزی IP خودتان قفل یا مسدود شد، از هاست (File Manager) یک فایل خالی با نام <b dir="ltr">ipguard_reset.txt</b> داخل پوشه <b dir="ltr">uploads</b> بسازید و صفحه ورود را دوباره باز کنید؛ همه قفل‌ها و مسدودی‌ها پاک می‌شود و فایل خودکار حذف می‌شود.
        </div>
    </div>
</div>

<div class="card" id="blocked">
    <h3 style="font-size:14px;margin-bottom:12px">⛔ IPهای قفل و مسدود (<?php echo count($blocked); ?>)</h3>
    <?php if (!$blocked): ?><p style="text-align:center;color:#94a3b8;padding:14px">هیچ IP قفل یا مسدودی نیست 🎉</p><?php else: ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>IP</th><th>وضعیت</th><th>بخش / شناسه آخر</th><th>کل تلاش ناموفق</th><th>آخرین تلاش</th><th></th></tr></thead><tbody>
        <?php foreach ($blocked as $r): [$sl, $sc] = $state($r); ?>
        <tr><td style="direction:ltr;text-align:right;font-weight:700"><?php echo admin_h($r['ip']); ?><?php if ($r['note'] !== ''): ?><div style="font-size:11px;color:#64748b;direction:rtl"><?php echo admin_h($r['note']); ?></div><?php endif; ?></td>
            <td><span class="<?php echo $sc; ?>"><?php echo $sl; ?></span></td>
            <td style="font-size:12px"><?php echo admin_h($areas[$r['last_area']] ?? $r['last_area']); ?><div style="color:#64748b;direction:ltr;text-align:right"><?php echo admin_h($r['last_ident']); ?></div></td>
            <td><?php echo number_format((int)$r['total_fails']); ?></td>
            <td style="font-size:12px"><?php echo admin_h($fmt($r['last_fail'])); ?></td>
            <td style="white-space:nowrap">
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="unblock"><input type="hidden" name="ip" value="<?php echo admin_h($r['ip']); ?>"><button class="btn btn-primary btn-sm">🔓 رفع مسدودی</button></form>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="wl_add"><input type="hidden" name="ip" value="<?php echo admin_h($r['ip']); ?>"><button class="btn btn-outline-secondary btn-sm">🟢 امن</button></form>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;align-items:center">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="block">
        <input class="form-control" name="ip" placeholder="IP برای مسدودسازی دستی" dir="ltr" style="max-width:220px" required>
        <input class="form-control" name="note" placeholder="یادداشت (اختیاری)" style="max-width:240px">
        <button class="btn btn-danger btn-sm">⛔ مسدود کن</button>
    </form>
</div>

<?php if ($watch): ?>
<div class="card">
    <h3 style="font-size:14px;margin-bottom:12px">👀 IPهای زیر نظر (خطا دارند ولی هنوز قفل نیستند)</h3>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>IP</th><th>خطای پیاپی</th><th>مرحله</th><th>بخش / شناسه آخر</th><th>آخرین تلاش</th><th></th></tr></thead><tbody>
        <?php foreach ($watch as $r): ?>
        <tr><td style="direction:ltr;text-align:right"><?php echo admin_h($r['ip']); ?></td><td><?php echo (int)$r['fails']; ?> از <?php echo (int)$c['max']; ?></td>
            <td style="font-size:12px"><?php echo ['0' => '—', '1' => 'یک بار قفل شده', '2' => 'دو بار قفل شده'][(string)(int)$r['level']] ?? (int)$r['level']; ?></td>
            <td style="font-size:12px"><?php echo admin_h($areas[$r['last_area']] ?? $r['last_area']); ?> <span style="color:#64748b" dir="ltr"><?php echo admin_h($r['last_ident']); ?></span></td>
            <td style="font-size:12px"><?php echo admin_h($fmt($r['last_fail'])); ?></td>
            <td><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="del"><input type="hidden" name="ip" value="<?php echo admin_h($r['ip']); ?>"><button class="btn btn-outline-secondary btn-sm">پاک کردن سابقه</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="font-size:14px;margin-bottom:12px">🟢 IPهای امن (<?php echo count($white); ?>)</h3>
    <?php if ($white): ?>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>IP</th><th>یادداشت</th><th></th></tr></thead><tbody>
        <?php foreach ($white as $r): ?>
        <tr><td style="direction:ltr;text-align:right;font-weight:700"><?php echo admin_h($r['ip']); ?><?php echo $r['ip'] === $my_ip ? ' <span class="badge-ok" style="direction:rtl">IP شما</span>' : ''; ?></td><td style="font-size:12.5px"><?php echo admin_h($r['note']); ?></td>
            <td><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="wl_del"><input type="hidden" name="ip" value="<?php echo admin_h($r['ip']); ?>"><button class="btn btn-outline-danger btn-sm">حذف از فهرست</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?><p style="color:#94a3b8;font-size:13px">هنوز IP امنی ثبت نشده است.</p><?php endif; ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="wl_add">
        <input class="form-control" name="ip" placeholder="IP (مثلاً IP ثابت دفتر)" dir="ltr" style="max-width:220px" required>
        <input class="form-control" name="note" placeholder="یادداشت (اختیاری)" style="max-width:240px">
        <button class="btn btn-success btn-sm">➕ افزودن به فهرست امن</button>
    </form>
</div>

<div class="card" id="log">
    <h3 style="font-size:14px;margin-bottom:12px">📜 آخرین ورودهای ناموفق</h3>
    <?php if (!$log): ?><p style="text-align:center;color:#94a3b8;padding:14px">موردی ثبت نشده است.</p><?php else: ?>
    <div style="overflow-x:auto;max-height:460px;overflow-y:auto"><table>
        <thead><tr><th>زمان</th><th>IP</th><th>بخش</th><th>شناسه واردشده</th><th>نتیجه</th></tr></thead><tbody>
        <?php foreach ($log as $r): ?>
        <tr><td style="font-size:12px;white-space:nowrap"><?php echo admin_h($fmt($r['created_at'])); ?></td><td style="direction:ltr;text-align:right"><?php echo admin_h($r['ip']); ?></td>
            <td style="font-size:12px"><?php echo admin_h($areas[$r['area']] ?? $r['area']); ?></td><td style="font-size:12px;direction:ltr;text-align:right"><?php echo admin_h($r['ident']); ?></td>
            <td><?php echo $r['event'] === 'block' ? '<span class="badge-err">⛔ مسدود شد</span>' : ($r['event'] === 'lock' ? '<span class="badge-off">⏳ قفل شد</span>' : '<span style="font-size:12px;color:#64748b">ناموفق</span>'); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
