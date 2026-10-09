<?php
require_once __DIR__ . '/_bootstrap.php';

$msg = '';

// تغییر وضعیت
if (isset($_GET['status']) && isset($_GET['uid'])) {
    $uid = (int)$_GET['uid'];
    $st = in_array($_GET['status'], ['active','inactive','suspended']) ? $_GET['status'] : 'active';
    $pdo->prepare("UPDATE saas_users SET status=? WHERE id=?")->execute([$st, $uid]);
    header('Location: users.php?msg=ok:وضعیت تغییر یافت.'); exit;
}

// اضافه کردن اعتبار دستی
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_credit'])) {
    $uid    = (int)$_POST['user_id'];
    $tokens = (int)str_replace(',', '', $_POST['tokens'] ?? '0');
    $desc   = trim($_POST['desc'] ?? 'شارژ دستی توسط ادمین');
    if ($tokens > 0 && $uid > 0) {
        saas_add_credit($pdo, $uid, $tokens, $desc);
        header('Location: users.php?msg=ok:' . $tokens . ' تومان به اعتبار کاربر افزوده شد.'); exit;
    }
}

/**
 * فعال‌سازی دستی پلن توسط مدیر (نسخه ۵۶): پلن پولی فقط با الصاق تصویر فیش واریزی؛ پلن رایگان و «بدون پلن» بدون فیش
 * @return string پیام (ok:/error:)
 */
function adm_plan_with_receipt($pdo, $uid, $plan_id, $add_credit, $file, array $in)
{
    $plan = $plan_id > 0 ? saas_get_plan($pdo, $plan_id) : null;
    $paid = $plan && (int)$plan['price_toman'] > 0;
    $bytes = null;
    if ($paid) {
        $bytes = c2c_upload_bytes($file);
        if (is_array($bytes)) return 'error:' . $bytes['error'];
        if ($bytes === null) return 'error:برای فعال کردن پلن پولی «' . $plan['name'] . '»، تصویر فیش واریزی را الصاق کنید.';
        $rc = c2c_admin_plan_receipt($pdo, $uid, $plan, $bytes, $in, 'مدیر');
        if (!$rc['ok']) return 'error:' . $rc['error'];
    }
    $res = saas_activate_plan($pdo, $uid, $plan_id, 'اعتبار پلن (فعال‌سازی توسط مدیر)', null, $add_credit);
    return $res['ok'] ? ('ok:پلن تغییر یافت' . ($paid ? ' (فیش ثبت شد)' : '') . '.' . ($res['tokens'] > 0 ? ' ' . number_format($res['tokens']) . ' تومان به اعتبار کاربر اضافه شد.' : '')) : ('error:' . $res['error']);
}

// تغییر پلن
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_plan'])) {
    $uid     = (int)$_POST['user_id'];
    $plan_id = (int)$_POST['plan_id'];
    // فعال‌سازی پلن + (اختیاری) افزودن توکن اولیه پلن به اعتبار کاربر — پلن پولی فقط با فیش
    $m = adm_plan_with_receipt($pdo, $uid, $plan_id, !empty($_POST['add_plan_credit']), $_FILES['receipt'] ?? null, $_POST);
    header('Location: users.php?view=' . $uid . '&msg=' . urlencode($m)); exit;
}

// ثبت کاربر توسط مدیر (نسخه ۵۶)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: users.php?add=1&msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $name = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 120);
    $phone = c2c_mobile($_POST['phone'] ?? '');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pw = (string)($_POST['password'] ?? '');
    $gen = $pw === '';
    if ($gen) $pw = 'Aa' . random_int(100000, 999999) . '#' . substr(bin2hex(random_bytes(3)), 0, 4);
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $plan = $plan_id > 0 ? saas_get_plan($pdo, $plan_id) : null;
    $m = '';
    if (mb_strlen($name) < 2) $m = 'error:نام کاربر را وارد کنید.';
    elseif ($phone === '') $m = 'error:شماره موبایل معتبر نیست (مثال: 09123456789).';
    elseif ($plan && (int)$plan['price_toman'] > 0 && (($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) $m = 'error:برای فعال کردن پلن پولی «' . $plan['name'] . '»، تصویر فیش واریزی را الصاق کنید.';
    else {
        if ($email === '') $email = $phone . '@users.invalid';   // ورود با موبایل؛ ایمیل بعداً در پروفایل قابل تغییر است
        $r = saas_register_user($pdo, $email, $pw, $name, $phone);
        if (!$r['ok']) $m = 'error:' . $r['error'];
        else {
            $uid = (int)$r['user_id'];
            $m = 'ok:کاربر «' . $name . '» ثبت شد.' . ($gen ? ' رمز ورود موقت: ' . $pw . ' (همین حالا به کاربر بدهید؛ دوباره نمایش داده نمی‌شود)' : '');
            if ($plan) {
                $pm = adm_plan_with_receipt($pdo, $uid, $plan_id, !empty($_POST['add_plan_credit']), $_FILES['receipt'] ?? null, $_POST);
                $m .= ' ' . preg_replace('/^(ok|error):/', '', $pm);
            }
            header('Location: users.php?view=' . $uid . '&msg=' . urlencode($m)); exit;
        }
    }
    header('Location: users.php?add=1&msg=' . urlencode($m)); exit;
}

// افزودن/کسر پیامک
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sms'])) {
    $uid = (int)$_POST['user_id'];
    $n = (int)($_POST['sms'] ?? 0);
    $pdo->prepare("UPDATE saas_users SET sms_balance = GREATEST(0, sms_balance + ?) WHERE id=?")->execute([$n, $uid]);
    if ($n > 0) biz_notify($pdo, $uid, '✉️ ' . number_format($n) . ' پیامک به کیف پیامک شما اضافه شد', '', 'success', 'billing.php#sms');
    header('Location: users.php?view=' . $uid . '&msg=' . urlencode('ok:اعتبار پیامک به‌روز شد.')); exit;
}

// تغییر مدت اشتراک (افزودن/کسر روز)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_days'])) {
    $uid = (int)$_POST['user_id'];
    $days = (int)($_POST['days'] ?? 0);
    $cu = $pdo->prepare("SELECT plan_id, plan_expires_at FROM saas_users WHERE id=?");
    $cu->execute([$uid]);
    $row = $cu->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['plan_id'] > 0 && $days !== 0) {
        $base = strtotime((string)$row['plan_expires_at']) ?: time();
        if ($days > 0 && $base < time()) $base = time();
        $pdo->prepare("UPDATE saas_users SET plan_expires_at=? WHERE id=?")->execute([date('Y-m-d H:i:s', $base + $days * 86400), $uid]);
        $m = 'ok:مدت اشتراک به‌روز شد.';
    } else $m = 'error:کاربر پلن فعال ندارد یا تعداد روز صفر است.';
    header('Location: users.php?view=' . $uid . '&msg=' . urlencode($m)); exit;
}

// ورود مدیر به پنل کاربر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_as'])) {
    $uid = (int)$_POST['user_id'];
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { header('Location: users.php?view=' . $uid . '&msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit; }
    $u = saas_get_user($pdo, $uid);
    if ($u) {
        unset($_SESSION['saas_team_id'], $_SESSION['saas_team_login']);
        $_SESSION['saas_user_id'] = $uid;
        $_SESSION['saas_admin_imp'] = 1;
        header('Location: ../user/index.php'); exit;
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];

// لیست کاربران
$search = trim($_GET['q'] ?? '');
$ufilter = in_array($_GET['filter'] ?? '', ['active', 'inactive', 'suspended'], true) ? $_GET['filter'] : '';
$uwhere = []; $uparams = [];
if ($search) {
    $like = '%' . $search . '%';
    $uwhere[] = "(u.email LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)";
    array_push($uparams, $like, $like, $like);
}
if ($ufilter) { $uwhere[] = "u.status=?"; $uparams[] = $ufilter; }
$uwhere[] = "u.email <> 'site-assistant@system.invalid'";   // حساب سیستمی چت صفحه اصلی سایت (نسخه ۴۳)
$users_stmt = $pdo->prepare("SELECT u.*, p.name as plan_name FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id"
    . ($uwhere ? ' WHERE ' . implode(' AND ', $uwhere) : '') . " ORDER BY u.id DESC LIMIT " . ($search ? 50 : 100));
$users_stmt->execute($uparams);
$users = $users_stmt ? $users_stmt->fetchAll() : [];

$plans = saas_get_plans($pdo, false);

// نمایش جزئیات کاربر
$detail_user = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $ds = $pdo->prepare("SELECT u.*, p.name as plan_name FROM saas_users u LEFT JOIN saas_plans p ON p.id=u.plan_id WHERE u.id=?");
    $ds->execute([(int)$_GET['view']]);
    $detail_user = $ds->fetch();
}

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">👥 مدیریت کاربران</h2>';
?>

<?php if ($msg): ?>
<div class="msg-<?php echo str_starts_with($msg,'ok:') ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>

<?php if ($detail_user): ?>
<!-- جزئیات کاربر -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3>کاربر: <?php echo htmlspecialchars($detail_user['full_name']); ?></h3>
        <div style="display:flex;gap:6px">
            <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><input type="hidden" name="user_id" value="<?php echo (int)$detail_user['id']; ?>"><button name="login_as" value="1" class="btn btn-sm" style="background:#0f2042;color:#fff">🔑 ورود به پنل کاربر</button></form>
            <a href="users.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">← بازگشت</a>
        </div>
    </div>
    <?php
    // ورود و بازدید (نسخه ۴۷)
    if (function_exists('act_people')) {
        $ap = act_people($pdo, ['kinds' => ['user'], 'where' => 'a.actor_id=?', 'params' => [(int)$detail_user['id']]], 1);
        if ($ap) { $ag = act_geo($pdo, [$ap[0]['ip']]); $apl = act_place($ag, $ap[0]['ip']);
            echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;margin-bottom:12px;font-size:13px">📈 ' . number_format($ap[0]['logins']) . ' ورود، ' . number_format($ap[0]['pages']) . ' صفحه — آخرین فعالیت: ' . htmlspecialchars(act_jdt($ap[0]['last'])) . ' — <span dir="ltr">' . htmlspecialchars($ap[0]['ip']) . '</span>' . ($apl !== '' ? ' (' . htmlspecialchars($apl) . ')' : '') . ' ' . htmlspecialchars(act_device($ap[0]['ua'])) . ' — <a href="activity.php?tab=users&days=180&who=user:' . (int)$detail_user['id'] . '">جزئیات</a></div>';
        }
    }
    ?>
    <div class="row2">
        <div>
            <table style="width:100%;font-size:13px">
                <tr><td style="padding:5px 0;color:#64748b">ایمیل:</td><td><?php echo htmlspecialchars($detail_user['email']); ?></td></tr>
                <tr><td style="padding:5px 0;color:#64748b">موبایل:</td><td><?php echo htmlspecialchars($detail_user['phone']); ?></td></tr>
                <tr><td style="padding:5px 0;color:#64748b">پلن:</td><td><?php echo htmlspecialchars($detail_user['plan_name'] ?? 'ندارد'); ?></td></tr>
                <tr><td style="padding:5px 0;color:#64748b">اعتبار:</td><td><b><?php echo number_format((int)$detail_user['credit_tokens']); ?></b> تومان</td></tr>
                <tr><td style="padding:5px 0;color:#64748b">وضعیت:</td><td><?php echo $detail_user['status']; ?></td></tr>
                <?php $dsub = biz_subscription($detail_user, $pdo); $dsms = biz_sms_status($pdo, (int)$detail_user['id']); ?>
                <tr><td style="padding:5px 0;color:#64748b">اشتراک:</td><td><?php
                    $ist = $dsub['inst'] ?? null;
                    echo ['none' => '—', 'active' => '✅ فعال تا ' . biz_jdate($dsub['expires_at']) . ' (' . (int)$dsub['days_left'] . ' روز)', 'grace' => '⚠️ منقضی — در مهلت تا ' . biz_jdate($dsub['grace_until']), 'expired' => '⛔ متوقف'][$dsub['state']] ?? $dsub['state'];
                    if ($ist && $ist['next']) echo '<br><small>قسط بعدی: ' . number_format((int)$ist['next']['amount']) . ' تومان — ' . biz_jdate($ist['next']['due_at']) . ' (' . $ist['unpaid'] . ' قسط باقی‌مانده)</small>';
                ?></td></tr>
                <tr><td style="padding:5px 0;color:#64748b">پیامک:</td><td>رایگان <?php echo $dsms['free_left']; ?>/<?php echo $dsms['free_total']; ?> — خریداری‌شده <?php echo number_format($dsms['balance']); ?></td></tr>
                <?php if ((int)($detail_user['affiliate_id'] ?? 0) > 0): $da = biz_affiliate($pdo, (int)$detail_user['affiliate_id']); ?>
                <tr><td style="padding:5px 0;color:#64748b">معرف:</td><td><a href="affiliates.php?view=<?php echo (int)$detail_user['affiliate_id']; ?>"><?php echo htmlspecialchars($da['name'] ?? ('#' . $detail_user['affiliate_id'])); ?></a></td></tr>
                <?php endif; ?>
                <tr><td style="padding:5px 0;color:#64748b">ثبت‌نام:</td><td><?php echo substr($detail_user['created_at'],0,10); ?></td></tr>
                <tr><td style="padding:5px 0;color:#64748b">آخرین ورود:</td><td><?php echo $detail_user['last_login'] ? substr($detail_user['last_login'],0,16) : '—'; ?></td></tr>
            </table>
        </div>
        <div>
            <!-- شارژ دستی -->
            <form method="post" style="background:#f0fdf4;border-radius:10px;padding:14px;margin-bottom:14px">
                <input type="hidden" name="user_id" value="<?php echo $detail_user['id']; ?>">
                <p style="font-size:13px;font-weight:600;margin-bottom:10px">➕ افزودن اعتبار</p>
                <input type="number" name="tokens" placeholder="مبلغ اعتبار (تومان)" min="1" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:8px">
                <input type="text" name="desc" placeholder="توضیح (اختیاری)" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:8px">
                <button type="submit" name="add_credit" class="btn btn-sm" style="background:#059669;color:#fff;width:100%">افزودن اعتبار</button>
            </form>
            <!-- تغییر پلن -->
            <form method="post" enctype="multipart/form-data" style="background:#eff6ff;border-radius:10px;padding:14px" id="cpf">
                <input type="hidden" name="user_id" value="<?php echo $detail_user['id']; ?>">
                <p style="font-size:13px;font-weight:600;margin-bottom:10px">📦 تغییر پلن</p>
                <select name="plan_id" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-bottom:8px">
                    <option value="0">بدون پلن</option>
                    <?php if (!empty($detail_user['plan_id']) && !in_array((int)$detail_user['plan_id'], array_map('intval', array_column($plans, 'id')), true)): ?>
                    <option value="<?php echo (int)$detail_user['plan_id']; ?>" selected><?php echo htmlspecialchars((string)($detail_user['plan_name'] ?? 'پلن فعلی')); ?> (پلن دلخواه)</option>
                    <?php endif; ?>
                    <?php foreach ($plans as $pl): ?>
                    <option value="<?php echo $pl['id']; ?>" data-p="<?php echo (int)$pl['price_toman']; ?>" <?php echo $detail_user['plan_id']==$pl['id']?'selected':''; ?>><?php echo htmlspecialchars($pl['name']); ?> — <?php echo (int)$pl['price_toman'] > 0 ? number_format((int)$pl['price_toman']) . ' تومان' : 'رایگان'; ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="rcp" style="display:none;background:#fff;border:1px dashed #93c5fd;border-radius:8px;padding:8px;margin-bottom:8px">
                    <p style="font-size:12px;font-weight:600;margin-bottom:6px">🧾 فیش واریزی (برای پلن پولی الزامی است)</p>
                    <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" style="width:100%;font-size:12px;margin-bottom:6px">
                    <input type="text" name="amount" placeholder="مبلغ واریزی (تومان)" dir="ltr" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;margin-bottom:6px">
                    <input type="text" name="tracking" placeholder="کد پیگیری (اختیاری)" dir="ltr" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:8px;cursor:pointer">
                    <input type="checkbox" name="add_plan_credit" value="1" checked> اعتبار اولیه پلن به حساب کاربر اضافه شود
                </label>
                <button type="submit" name="change_plan" class="btn btn-sm" style="background:#2563eb;color:#fff;width:100%">تغییر پلن</button>
                <script>(function(){ var f = document.getElementById('cpf'), s = f.querySelector('select[name=plan_id]'), b = f.querySelector('.rcp'), cur = s.value;
                  function u(){ var o = s.options[s.selectedIndex], p = +(o.getAttribute('data-p') || 0), need = p > 0 && s.value !== cur; b.style.display = p > 0 ? '' : 'none'; f.querySelector('input[name=receipt]').required = p > 0; }
                  s.onchange = u; u(); })();</script>
                <p style="font-size:11px;color:#64748b;margin-top:6px">اشتراک یک‌ساله؛ تغییر به همان پلن فعلی، یک سال به انقضا اضافه می‌کند.</p>
            </form>
            <form method="post" style="background:#fffbeb;border-radius:10px;padding:14px;margin-top:14px">
                <input type="hidden" name="user_id" value="<?php echo $detail_user['id']; ?>">
                <p style="font-size:13px;font-weight:600;margin-bottom:10px">📅 تغییر مدت اشتراک (روز؛ منفی = کم کردن)</p>
                <input type="number" name="days" value="30" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;margin-bottom:8px">
                <button type="submit" name="add_days" class="btn btn-sm" style="background:#d97706;color:#fff;width:100%">اعمال</button>
            </form>
            <form method="post" style="background:#f5f3ff;border-radius:10px;padding:14px;margin-top:14px">
                <input type="hidden" name="user_id" value="<?php echo $detail_user['id']; ?>">
                <p style="font-size:13px;font-weight:600;margin-bottom:10px">✉️ افزودن/کسر پیامک (عدد منفی = کسر)</p>
                <input type="number" name="sms" value="100" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;margin-bottom:8px">
                <button type="submit" name="add_sms" class="btn btn-sm" style="background:#7c3aed;color:#fff;width:100%">ثبت</button>
            </form>
        </div>
    </div>
</div>

<?php
// تاریخچه پرداخت
$pays = saas_payments_log($pdo, $detail_user['id'], 10);
if ($pays):
?>
<div class="card">
    <h3 style="margin-bottom:14px;font-size:14px">پرداخت‌ها</h3>
    <table>
        <thead><tr><th>نوع</th><th>مبلغ</th><th>اعتبار (تومان)</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
        <?php foreach ($pays as $pay): ?>
        <tr>
            <td><?php echo $pay['type']==='plan' ? 'پلن ' . htmlspecialchars($pay['plan_name']??'') : 'شارژ'; ?></td>
            <td><?php echo number_format($pay['amount_toman']); ?></td>
            <td><?php echo number_format($pay['credit_tokens']); ?></td>
            <td><?php echo $pay['status']; ?></td>
            <td style="font-size:12px;direction:ltr;text-align:right"><?php echo substr($pay['created_at'],0,10); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ثبت کاربر (نسخه ۵۶) -->
<details class="card" <?php echo !empty($_GET['add']) ? 'open' : ''; ?> style="padding:14px 18px">
    <summary style="cursor:pointer;font-weight:700;font-size:14px">➕ ثبت کاربر جدید</summary>
    <form method="post" enctype="multipart/form-data" style="margin-top:12px" id="auf">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><input type="hidden" name="add_user" value="1">
        <div class="row2">
            <div><label>نام و نام خانوادگی *</label><input type="text" name="full_name" maxlength="120" required></div>
            <div><label>شماره موبایل *</label><input type="text" name="phone" dir="ltr" required placeholder="09121234567"></div>
        </div>
        <div class="row2">
            <div><label>ایمیل (اختیاری)</label><input type="email" name="email" dir="ltr" placeholder="خالی = ورود با موبایل"></div>
            <div><label>رمز ورود (اختیاری)</label><input type="text" name="password" dir="ltr" placeholder="خالی = رمز موقت ساخته و نمایش داده می‌شود" autocomplete="new-password"></div>
        </div>
        <label>پلن</label>
        <select name="plan_id">
            <option value="0" data-p="0">— بدون پلن —</option>
            <?php foreach ($plans as $pl): ?><option value="<?php echo (int)$pl['id']; ?>" data-p="<?php echo (int)$pl['price_toman']; ?>"><?php echo htmlspecialchars($pl['name']); ?> — <?php echo (int)$pl['price_toman'] > 0 ? number_format((int)$pl['price_toman']) . ' تومان' : 'رایگان'; ?></option><?php endforeach; ?>
        </select>
        <div class="rcp" style="display:none;background:#f8fafc;border:1px dashed #93c5fd;border-radius:10px;padding:10px;margin-top:8px">
            <label style="margin-top:0">🧾 تصویر فیش واریزی * (برای پلن پولی الزامی است)</label><input type="file" name="receipt" accept="image/jpeg,image/png,image/webp">
            <div class="row2"><div><label>مبلغ واریزی (تومان)</label><input type="text" name="amount" dir="ltr"></div><div><label>کد پیگیری (اختیاری)</label><input type="text" name="tracking" dir="ltr"></div></div>
            <label style="display:flex;gap:6px;align-items:center;font-weight:500"><input type="checkbox" name="add_plan_credit" value="1" checked style="width:auto"> اعتبار اولیه پلن به حساب کاربر اضافه شود</label>
        </div>
        <div style="margin-top:10px"><button class="btn btn-primary btn-sm">💾 ثبت کاربر</button></div>
    </form>
    <script>(function(){ var f = document.getElementById('auf'), s = f.querySelector('select[name=plan_id]'), b = f.querySelector('.rcp');
      function u(){ var p = +(s.options[s.selectedIndex].getAttribute('data-p') || 0); b.style.display = +s.value ? '' : 'none'; f.querySelector('input[name=receipt]').required = p > 0; b.querySelector('label').style.display = f.querySelector('input[name=receipt]').style.display = p > 0 ? '' : 'none'; }
      s.onchange = u; u(); })();</script>
</details>
<!-- لیست کاربران -->
<div class="card">
    <form method="get" style="display:flex;gap:8px;margin-bottom:16px">
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="جستجوی کاربر (ایمیل، نام، موبایل)..." style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px">
        <select name="filter" style="width:160px !important">
            <option value="">همه وضعیت‌ها</option>
            <option value="active" <?php echo $ufilter === 'active' ? 'selected' : ''; ?>>فعال</option>
            <option value="inactive" <?php echo $ufilter === 'inactive' ? 'selected' : ''; ?>>غیرفعال</option>
            <option value="suspended" <?php echo $ufilter === 'suspended' ? 'selected' : ''; ?>>تعلیق</option>
        </select>
        <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff">جستجو</button>
        <?php if ($search || $ufilter): ?><a href="users.php" class="btn btn-sm" style="background:#f1f5f9;color:#374151">پاک کردن</a><?php endif; ?>
    </form>
    <table>
        <thead><tr><th>#</th><th>نام</th><th>ایمیل</th><th>پلن</th><th>انقضا</th><th>اعتبار</th><th>وضعیت</th><th>ثبت‌نام</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php if (empty($users)): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:30px">کاربری یافت نشد.</td></tr>
        <?php else: ?>
        <?php foreach ($users as $u): ?>
        <tr>
            <td style="color:#94a3b8">#<?php echo $u['id']; ?></td>
            <td><a href="users.php?view=<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></a></td>
            <td style="font-size:12px;direction:ltr;text-align:right"><?php echo htmlspecialchars($u['email']); ?></td>
            <td><?php echo htmlspecialchars($u['plan_name'] ?? '—'); ?></td>
            <td style="font-size:12px"><?php $us = biz_subscription($u, $pdo); echo $us['expires_at'] ? '<span style="color:' . (['active' => ($us['days_left'] <= 30 ? '#d97706' : '#059669'), 'grace' => '#dc2626', 'expired' => '#dc2626'][$us['state']] ?? '#64748b') . '">' . biz_jdate($us['expires_at']) . ($us['state'] === 'expired' ? ' ⛔' : '') . '</span>' : '—'; ?></td>
            <td><?php echo number_format((int)$u['credit_tokens']); ?></td>
            <td>
                <span class="<?php echo $u['status']==='active'?'badge-ok':($u['status']==='suspended'?'badge-err':'badge-off'); ?>">
                    <?php echo ['active'=>'فعال','inactive'=>'غیرفعال','suspended'=>'تعلیق'][$u['status']]??$u['status']; ?>
                </span>
            </td>
            <td style="font-size:12px;color:#94a3b8"><?php echo substr($u['created_at'],0,10); ?></td>
            <td style="white-space:nowrap">
                <a href="users.php?view=<?php echo $u['id']; ?>" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">مدیریت</a>
                <?php if ($u['status']==='active'): ?>
                <a href="users.php?uid=<?php echo $u['id']; ?>&status=suspended" class="btn btn-sm" style="background:#fee2e2;color:#dc2626" onclick="return confirm('تعلیق شود؟')">تعلیق</a>
                <?php else: ?>
                <a href="users.php?uid=<?php echo $u['id']; ?>&status=active" class="btn btn-sm" style="background:#dcfce7;color:#059669">فعال</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
