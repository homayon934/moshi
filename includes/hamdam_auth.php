<?php
/**
 * همدم — حساب کاربری مخاطبان (مراجعان) چت‌بات
 *  - هر شماره موبایل در هر ربات فقط یک حساب دارد (ادغام خودکار حساب‌های تکراری قدیمی)
 *  - ورود دوباره به حساب موجود: کد پیامکی، رمز عبور (با کپچا و قفل موقت) یا حساب گوگل
 *  - فراموشی رمز با کد پیامکی
 *  - به‌روزرسانی خودکار «فایل رابط» نصب‌شده روی سایت مشتری
 *
 * این فایل از انتهای hamdam_ext.php بارگذاری می‌شود.
 */

if (!defined('HD_AUTH_LOADED')) {
define('HD_AUTH_LOADED', 1);

define('HD_PW_MIN', 6);           // حداقل طول رمز
define('HD_PW_MAX_FAIL', 5);      // تعداد تلاش ناموفق پیش از قفل
define('HD_PW_LOCK_MIN', 15);     // مدت قفل (دقیقه)

// =============================================================================
// جداول
// =============================================================================
function hd_auth_schema($pdo)
{
    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) {} };

    $addColumnIfNotExists = function ($table, $column, $definition) use ($pdo) {
        try {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) return;
            $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
            $st->execute([$table, $column]);
            if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {}
    };
    $addColumnIfNotExists('hd_members', 'password_hash', "VARCHAR(255) NOT NULL DEFAULT ''");
    $addColumnIfNotExists('hd_members', 'google_sub', "VARCHAR(64) NOT NULL DEFAULT ''");
    $addColumnIfNotExists('hd_members', 'email', "VARCHAR(190) NOT NULL DEFAULT ''");
    $addColumnIfNotExists('hd_members', 'pw_fail', "INT NOT NULL DEFAULT 0");
    $addColumnIfNotExists('hd_members', 'pw_lock_until', "DATETIME NULL");
    $addColumnIfNotExists('hd_otps', 'purpose', "VARCHAR(10) NOT NULL DEFAULT 'login'");
    $addColumnIfNotExists('hd_bots', 'connector_cv', "VARCHAR(64) NOT NULL DEFAULT ''");
    $addColumnIfNotExists('hd_bots', 'connector_seen', "DATETIME NULL");
    $q("CREATE TABLE IF NOT EXISTS hd_captchas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        token CHAR(32) NOT NULL,
        answer_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS hd_glogin (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bot_id INT NOT NULL,
        state CHAR(40) NOT NULL,
        code_hash CHAR(64) NOT NULL DEFAULT '',
        mode VARCHAR(10) NOT NULL DEFAULT 'login',
        member_id INT NOT NULL DEFAULT 0,
        return_url VARCHAR(500) NOT NULL DEFAULT '',
        sub VARCHAR(64) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        name VARCHAR(100) NOT NULL DEFAULT '',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_state (state),
        INDEX idx_code (code_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    hd_merge_duplicate_members($pdo);
}

/**
 * ادغام حساب‌های تکراری (یک شماره در یک ربات) — یک بار اجرا می‌شود.
 * گفتگوها، پیام‌ها، یادداشت‌ها، فایل‌ها و خریدها به حساب اصلی منتقل می‌شوند و
 * پیام‌های مصرفی جمع می‌شوند تا پیام رایگان دوباره داده نشود.
 */
function hd_merge_duplicate_members($pdo, $force = false)
{
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.hd_merge_v1' : '';
    if (!$force && $flag !== '' && is_file($flag)) return 0;
    $merged = 0;
    try {
        $groups = $pdo->query("SELECT bot_id, mobile FROM hd_members WHERE is_owner=0 AND mobile<>'' GROUP BY bot_id, mobile HAVING COUNT(*) > 1")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($groups as $g) {
            $st = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND mobile=? AND is_owner=0 ORDER BY verified DESC, id ASC");
            $st->execute([(int)$g['bot_id'], $g['mobile']]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if (count($rows) < 2) continue;
            $main = array_shift($rows);
            $mid = (int)$main['id'];
            $profile = hd_profile_get($main);
            $latest = $main; $consent_row = $main;
            $used = (int)$main['used_messages']; $extra = (int)$main['extra_messages']; $verified = (int)$main['verified'];
            foreach ($rows as $d) {
                $did = (int)$d['id'];
                foreach (['hd_threads', 'hd_messages', 'hd_member_notes', 'hd_files', 'hd_purchases'] as $t) {
                    try { $pdo->prepare("UPDATE {$t} SET member_id=? WHERE member_id=? AND bot_id=?")->execute([$mid, $did, (int)$g['bot_id']]); } catch (\Throwable $e) {}
                }
                foreach (hd_profile_get($d) as $k => $v) if (!isset($profile[$k]) || trim((string)$profile[$k]) === '') $profile[$k] = $v;
                $used += (int)$d['used_messages']; $extra += (int)$d['extra_messages']; $verified = max($verified, (int)$d['verified']);
                if ((string)$d['last_seen'] > (string)$latest['last_seen']) $latest = $d;
                if (!empty($d['consent_at']) && (string)$d['consent_at'] > (string)($consent_row['consent_at'] ?? '')) $consent_row = $d;
                $pdo->prepare("DELETE FROM hd_members WHERE id=?")->execute([$did]);
                $merged++;
            }
            // آخرین دستگاه واردشده وارد می‌ماند؛ آخرین تصمیم «اجازه مشاور» حفظ می‌شود
            $pdo->prepare("UPDATE hd_members SET used_messages=?, extra_messages=?, verified=?, token_hash=?, name=?, last_seen=?, consent_share=?, consent_at=? WHERE id=?")
                ->execute([$used, $extra, $verified, $latest['token_hash'], $latest['name'], $latest['last_seen'], (int)($consent_row['consent_share'] ?? 0), $consent_row['consent_at'] ?? null, $mid]);
            hd_profile_save($pdo, $mid, $profile);
        }
        if ($flag !== '') @file_put_contents($flag, date('c') . " merged={$merged}\n");
    } catch (\Throwable $e) {
        error_log('[HD] merge members: ' . $e->getMessage());
    }
    return $merged;
}

// =============================================================================
// کمکی‌ها
// =============================================================================
function hd_member_by_mobile($pdo, $bot_id, $mobile)
{
    if ($mobile === '') return null;
    $st = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND mobile=? AND is_owner=0 ORDER BY id ASC LIMIT 1");
    $st->execute([(int)$bot_id, $mobile]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function hd_google_ready($pdo)
{
    if (function_exists('comm_login_is')) return comm_login_is($pdo, 'member', 'google');
    if (!function_exists('biz_settings')) return false;
    $s = biz_settings($pdo);
    return !empty($s['hd_google_login']) && trim((string)$s['google_client_id']) !== '' && trim((string)$s['google_client_secret']) !== '';
}

function hd_google_redirect_uri()
{
    return rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/hamdam/google.php';
}

/** روش‌های ورود ممکن برای یک حساب موجود */
function hd_login_methods($pdo, $bot, $m)
{
    $act = function_exists('comm_login_active') ? comm_login_active($pdo, 'member') : ['sms', 'google', 'password'];
    return [
        'sms'      => in_array('sms', $act, true) && hd_sms_ready($pdo, $bot),
        'password' => in_array('password', $act, true) && trim((string)($m['password_hash'] ?? '')) !== '',
        'google'   => trim((string)($m['google_sub'] ?? '')) !== '' && hd_google_ready($pdo),
        'order'    => array_values($act),
    ];
}

/** آیا ورود/ثبت‌نام مخاطبان با پیامک (طبق تنظیم مدیر) مجاز و ممکن است؟ */
function hd_member_sms_on($pdo, $bot)
{
    if (function_exists('comm_login_is') && !comm_login_is($pdo, 'member', 'sms')) return false;
    return hd_sms_ready($pdo, $bot);
}

function hd_mask_mobile($m)
{
    return strlen($m) >= 11 ? substr($m, 0, 4) . '***' . substr($m, -4) : $m;
}

function hd_digits($s)
{
    return preg_replace('/\D+/', '', saas_fa_normalize((string)$s));
}

/** ارسال کد یک‌بارمصرف (ورود یا بازیابی رمز) */
function hd_otp_send($pdo, $bot, $mobile, $purpose = 'login')
{
    $purpose = $purpose === 'reset' ? 'reset' : 'login';
    if (function_exists('ipg_blocked_msg') && ($ipb = ipg_blocked_msg($pdo)) !== '') return $ipb;   // IP قفل/مسدود
    if (!hd_sms_ready($pdo, $bot)) return 'ارسال پیامک در حال حاضر ممکن نیست.';
    $c = $pdo->prepare("SELECT COUNT(*) FROM hd_otps WHERE bot_id=? AND mobile=? AND created_at >= ?");
    $c->execute([(int)$bot['id'], $mobile, hd_now(-600)]);
    if ((int)$c->fetchColumn() >= 3) return 'تعداد درخواست کد زیاد است؛ ۱۰ دقیقه دیگر تلاش کنید.';
    $code = (string)random_int(10000, 99999);
    $pdo->prepare("INSERT INTO hd_otps (bot_id, mobile, code_hash, expires_at, created_at, purpose) VALUES(?,?,?,?,?,?)")
        ->execute([(int)$bot['id'], $mobile, hash('sha256', $bot['id'] . '|' . $mobile . '|' . $purpose . '|' . $code), hd_now(300), hd_now(), $purpose]);
    $txt = $purpose === 'reset' ? "کد بازیابی رمز شما در «{$bot['name']}»: {$code}" : "کد ورود شما به «{$bot['name']}»: {$code}";
    if (!hd_send_sms($pdo, $bot, $mobile, $txt, 'otp', $purpose === 'reset' ? 'otp_member_reset' : 'otp_member', ['code' => $code, 'bot' => (string)$bot['name']])) return 'ارسال پیامک ممکن نشد؛ کمی بعد دوباره تلاش کنید.';
    return '';
}

/** بررسی کد یک‌بارمصرف؛ خروجی: '' یعنی درست */
function hd_otp_check($pdo, $bot, $mobile, $code, $purpose = 'login')
{
    $purpose = $purpose === 'reset' ? 'reset' : 'login';
    $code = hd_digits($code);
    if (function_exists('ipg_blocked_msg') && ($ipb = ipg_blocked_msg($pdo)) !== '') return $ipb;
    if ($mobile === '' || strlen($code) !== 5) return 'کد تأیید نادرست است.';
    $st = $pdo->prepare("SELECT * FROM hd_otps WHERE bot_id=? AND mobile=? AND purpose=? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$bot['id'], $mobile, $purpose]);
    $otp = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$otp || strtotime($otp['expires_at']) < time()) return 'کد منقضی شده است؛ دوباره درخواست کنید.';
    if ((int)$otp['attempts'] >= 5) return 'تعداد تلاش‌ها زیاد است؛ کد جدید بگیرید.';
    $pdo->prepare("UPDATE hd_otps SET attempts = attempts + 1 WHERE id=?")->execute([(int)$otp['id']]);
    if (!hash_equals($otp['code_hash'], hash('sha256', $bot['id'] . '|' . $mobile . '|' . $purpose . '|' . $code))) {
        if (function_exists('ipg_fail') && ($ipl = ipg_fail($pdo, 'bot', $mobile)) !== '') return $ipl;
        return 'کد تأیید نادرست است.';
    }
    $pdo->prepare("DELETE FROM hd_otps WHERE bot_id=? AND mobile=? AND purpose=?")->execute([(int)$bot['id'], $mobile, $purpose]);
    if (function_exists('ipg_success')) ipg_success($pdo);
    return '';
}

function hd_password_error($pw)
{
    $pw = (string)$pw;
    if (mb_strlen($pw) < HD_PW_MIN) return 'رمز عبور باید حداقل ' . HD_PW_MIN . ' کاراکتر باشد.';
    if (strlen($pw) > 72) return 'رمز عبور خیلی طولانی است.';
    return '';
}

function hd_login_ok($pdo, $m, $name = '')
{
    if ($name !== '' && $name !== $m['name']) $pdo->prepare("UPDATE hd_members SET name=? WHERE id=?")->execute([$name, (int)$m['id']]);
    return ['ok' => true, 'token' => hd_issue_token($pdo, (int)$m['id']), 'name' => $name !== '' ? $name : $m['name']];
}

// =============================================================================
// کپچا (تصویر عدد ۵ رقمی)
// =============================================================================
function hd_captcha_new($pdo, $bot)
{
    try { $pdo->prepare("DELETE FROM hd_captchas WHERE expires_at < ?")->execute([hd_now()]); } catch (\Throwable $e) {}
    $ans = '';
    for ($i = 0; $i < 5; $i++) $ans .= (string)random_int(0, 9);
    $tok = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO hd_captchas (bot_id, token, answer_hash, expires_at) VALUES(?,?,?,?)")
        ->execute([(int)$bot['id'], $tok, hash('sha256', $tok . '|' . $ans), hd_now(600)]);
    return ['ok' => true, 'id' => $tok, 'img' => hd_captcha_image($ans)];
}

/** بررسی و مصرف کپچا (یک‌بارمصرف) */
function hd_captcha_check($pdo, $bot, $id, $answer)
{
    $id = preg_replace('/[^a-f0-9]/', '', (string)$id);
    if (strlen($id) !== 32) return false;
    $st = $pdo->prepare("SELECT * FROM hd_captchas WHERE token=? AND bot_id=? LIMIT 1");
    $st->execute([$id, (int)$bot['id']]);
    $c = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$c) return false;
    $pdo->prepare("DELETE FROM hd_captchas WHERE id=?")->execute([(int)$c['id']]);
    if (strtotime($c['expires_at']) < time()) return false;
    return hash_equals($c['answer_hash'], hash('sha256', $id . '|' . hd_digits($answer)));
}

/** تصویر کپچا: PNG با GD (نویز و اعوجاج)، در نبود GD تصویر برداری */
function hd_captcha_image($ans)
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        $sw = 90; $sh = 24;
        $small = imagecreatetruecolor($sw, $sh);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        for ($i = 0; $i < 5; $i++) {
            $col = imagecolorallocate($small, random_int(10, 90), random_int(10, 90), random_int(60, 140));
            imagestring($small, 5, 8 + $i * 16 + random_int(-2, 2), random_int(1, 7), $ans[$i], $col);
        }
        $W = 180; $H = 60;
        $img = imagecreatetruecolor($W, $H);
        imagefill($img, 0, 0, imagecolorallocate($img, 248, 250, 252));
        // بزرگ‌نمایی با موج (هر ستون جابه‌جایی عمودی)
        $amp = random_int(3, 5); $ph = random_int(0, 100) / 10;
        for ($x = 0; $x < $W; $x++) {
            $dy = (int)round(sin($x / 11 + $ph) * $amp);
            imagecopyresized($img, $small, $x, $dy + 6, (int)($x * $sw / $W), 0, 1, $H - 12, 1, $sh);
        }
        for ($i = 0; $i < 6; $i++) {
            $lc = imagecolorallocate($img, random_int(120, 200), random_int(120, 200), random_int(120, 200));
            imageline($img, random_int(0, $W), random_int(0, $H), random_int(0, $W), random_int(0, $H), $lc);
        }
        for ($i = 0; $i < 350; $i++) imagesetpixel($img, random_int(0, $W - 1), random_int(0, $H - 1), imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220)));
        ob_start(); imagepng($img); $png = ob_get_clean();
        imagedestroy($small); imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($png);
    }
    // جایگزین: رقم‌ها به‌صورت خطوط (نه متن قابل انتخاب)
    $seg = ['0' => 'abcdef', '1' => 'bc', '2' => 'abged', '3' => 'abgcd', '4' => 'fgbc', '5' => 'afgcd', '6' => 'afgedc', '7' => 'abc', '8' => 'abcdefg', '9' => 'abcdfg'];
    $pts = ['a' => [0, 0, 1, 0], 'b' => [1, 0, 1, 1], 'c' => [1, 1, 1, 2], 'd' => [0, 2, 1, 2], 'e' => [0, 1, 0, 2], 'f' => [0, 0, 0, 1], 'g' => [0, 1, 1, 1]];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="60" viewBox="0 0 180 60"><rect width="180" height="60" fill="#f8fafc"/>';
    for ($i = 0; $i < 6; $i++) $svg .= '<line x1="' . random_int(0, 180) . '" y1="' . random_int(0, 60) . '" x2="' . random_int(0, 180) . '" y2="' . random_int(0, 60) . '" stroke="#cbd5e1" stroke-width="1"/>';
    for ($i = 0; $i < 5; $i++) {
        $ox = 16 + $i * 32 + random_int(-2, 2); $oy = 12 + random_int(-3, 3); $w = 16; $h = 16; $sk = random_int(-4, 4);
        foreach (str_split($seg[$ans[$i]]) as $s) {
            [$x1, $y1, $x2, $y2] = $pts[$s];
            $svg .= '<line x1="' . ($ox + $x1 * $w + $sk * (1 - $y1)) . '" y1="' . ($oy + $y1 * $h + random_int(-1, 1)) . '" x2="' . ($ox + $x2 * $w + $sk * (1 - $y2)) . '" y2="' . ($oy + $y2 * $h + random_int(-1, 1)) . '" stroke="#1e3a8a" stroke-width="3" stroke-linecap="round"/>';
        }
    }
    return 'data:image/svg+xml;base64,' . base64_encode($svg . '</svg>');
}

// =============================================================================
// ورود با گوگل
// =============================================================================
function hd_google_start($pdo, $bot, $return_url, $mode = 'login', $member_id = 0)
{
    $return_url = (string)$return_url;
    if (!preg_match('#^https?://[^\s"\'<>]+$#i', $return_url) || strlen($return_url) > 480) return ['ok' => false, 'error' => 'آدرس بازگشت نامعتبر است.'];
    // امنیت: کد ورود فقط به دامنه ثبت‌شده همین چت‌بات برگردانده می‌شود (نه دامنه جعلی)
    $rh = strtolower((string)parse_url($return_url, PHP_URL_HOST));
    $allowed = [];
    if ((string)($bot['custom_domain'] ?? '') !== '') $allowed[] = strtolower((string)$bot['custom_domain']);
    if ((string)($bot['chat_url'] ?? '') !== '') $allowed[] = strtolower((string)parse_url((string)$bot['chat_url'], PHP_URL_HOST));
    $allowed = array_filter($allowed);
    if ($allowed && !in_array($rh, $allowed, true) && !in_array(preg_replace('/^www\./', '', $rh), array_map(fn($h) => preg_replace('/^www\./', '', $h), $allowed), true))
        return ['ok' => false, 'error' => 'آدرس بازگشت با دامنه این چت‌بات یکسان نیست.'];
    if (!hd_google_ready($pdo)) return ['ok' => false, 'error' => 'ورود با گوگل فعال نیست.'];
    try { $pdo->prepare("DELETE FROM hd_glogin WHERE expires_at < ?")->execute([hd_now()]); } catch (\Throwable $e) {}
    $state = bin2hex(random_bytes(20));
    $pdo->prepare("INSERT INTO hd_glogin (bot_id, state, mode, member_id, return_url, expires_at, created_at) VALUES(?,?,?,?,?,?,?)")
        ->execute([(int)$bot['id'], $state, $mode === 'link' ? 'link' : 'login', (int)$member_id, $return_url, hd_now(900), hd_now()]);
    $s = biz_settings($pdo);
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $s['google_client_id'], 'redirect_uri' => hd_google_redirect_uri(), 'response_type' => 'code',
        'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account', 'access_type' => 'online',
    ]);
    return ['ok' => true, 'url' => $url];
}

/**
 * مخاطب شناخته‌شده برای ورود با گوگل (نسخه ۶۱) — بدون فرم «نام و موبایل»:
 *  ۱) مخاطب همین چت‌بات با همین ایمیل (ثبت‌نام قبلی با پیامک/رمز و ایمیل یکسان) → حساب گوگل به آن متصل می‌شود
 *  ۲) همین حساب گوگل قبلاً در چت‌بات دیگری (اول چت‌بات‌های همین صاحب) ثبت‌نام کرده → با همان موبایل و نام:
 *     اگر در این چت‌بات مخاطبی با آن موبایل هست، به آن متصل (فقط اگر موبایل قبلاً با پیامک تأیید شده باشد)؛ وگرنه مخاطب تازه ساخته می‌شود
 * @return array|null ردیف hd_members (با '_new' اگر تازه ساخته شد)
 */
function hd_google_known_member($pdo, $bot, $row)
{
    $sub = (string)($row['sub'] ?? '');
    $email = strtolower(trim((string)($row['email'] ?? '')));
    if ($sub === '') return null;
    $link = function ($mid) use ($pdo, $sub, $email) {
        $pdo->prepare("UPDATE hd_members SET google_sub=?" . ($email !== '' ? ", email=?" : '') . " WHERE id=?")->execute($email !== '' ? [$sub, $email, (int)$mid] : [$sub, (int)$mid]);
        $s = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
        $s->execute([(int)$mid]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    };
    try {
        // ۱) همین چت‌بات، همین ایمیل (بدون اتصال به حساب گوگل دیگر)
        if ($email !== '') {
            $s = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND is_owner=0 AND LOWER(email)=? AND (google_sub='' OR google_sub=?) ORDER BY id ASC LIMIT 1");
            $s->execute([(int)$bot['id'], $email, $sub]);
            if ($m = $s->fetch(\PDO::FETCH_ASSOC)) return $link((int)$m['id']);
        }
        // ۲) همین حساب گوگل در چت‌بات‌های دیگر (اول همین صاحب)
        $s = $pdo->prepare("SELECT m.*, b.user_id AS _owner FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE m.google_sub=? AND m.is_owner=0 AND m.bot_id<>? AND m.mobile<>'' AND m.status='active'
                            ORDER BY (b.user_id=?) DESC, m.last_seen DESC, m.id DESC LIMIT 1");
        $s->execute([$sub, (int)$bot['id'], (int)$bot['user_id']]);
        $o = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$o) return null;
        $mobile = hd_normalize_mobile($o['mobile']);
        if ($mobile === '') return null;
        $here = hd_member_by_mobile($pdo, $bot['id'], $mobile);
        if ($here) {
            if (trim((string)$here['google_sub']) !== '' && $here['google_sub'] !== $sub) return null;   // این موبایل به جیمیل دیگری وصل است
            if (empty($o['verified'])) return null;   // امنیت: فقط وقتی مالکیت موبایل قبلاً با کد پیامکی تأیید شده، به حساب موجود وصل می‌شود
            return $link((int)$here['id']);
        }
        $name = trim((string)$o['name']) !== '' ? (string)$o['name'] : (string)($row['name'] ?? '');
        $mid = hd_create_member($pdo, $bot['id'], $mobile, mb_substr($name, 0, 100), !empty($o['verified']));
        $m = $link($mid);
        if ($m) $m['_new'] = true;
        return $m;
    } catch (\Throwable $e) {
        error_log('[HD] google known: ' . $e->getMessage());
        return null;
    }
}

/** اطلاعات بازگشت از گوگل با کد یک‌بارمصرف (کد در hamdam/google.php ساخته می‌شود) */
function hd_google_row_by_code($pdo, $bot, $code)
{
    $code = preg_replace('/[^a-f0-9]/', '', (string)$code);
    if (strlen($code) !== 40) return null;
    $st = $pdo->prepare("SELECT * FROM hd_glogin WHERE bot_id=? AND code_hash=? AND expires_at > ? LIMIT 1");
    $st->execute([(int)$bot['id'], hash('sha256', $code), hd_now()]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    return ($r && $r['sub'] !== '') ? $r : null;
}

// =============================================================================
// به‌روزرسانی خودکار فایل رابط
// =============================================================================
function hd_connector_path()
{
    return dirname(__DIR__) . '/hamdam/connector/index.php';
}

function hd_connector_hash()
{
    $f = hd_connector_path();
    return is_file($f) ? hash_file('sha256', $f) : '';
}

// =============================================================================
// عملیات «فایل رابط» (پیش از ورود)
// خروجی null یعنی این عملیات مربوط به این بخش نیست
// =============================================================================
function hd_auth_dispatch($pdo, $bot, $action, $in)
{
    // ثبت نسخه فایل رابط سایت (برای نمایش «به‌روز / قدیمی» در پنل)
    if (!empty($in['cv']) && preg_match('/^[a-f0-9]{64}$/', (string)$in['cv'])) {
        if ($in['cv'] !== ($bot['connector_cv'] ?? '') || empty($bot['connector_seen']) || strtotime((string)$bot['connector_seen']) < time() - 3600) {
            try { $pdo->prepare("UPDATE hd_bots SET connector_cv=?, connector_seen=? WHERE id=?")->execute([(string)$in['cv'], hd_now(), (int)$bot['id']]); } catch (\Throwable $e) {}
        }
    }

    // محافظ IP: بعد از ورودهای ناموفق پیاپی، ورود/ثبت‌نام از این IP موقتاً قفل یا مسدود است
    if (in_array($action, ['login', 'send_code', 'verify', 'pw_login', 'pw_reset', 'g_start', 'g_finish', 'g_register', 'g_link'], true)
        && function_exists('ipg_blocked_msg') && ($ipb = ipg_blocked_msg($pdo)) !== '') {
        return ['ok' => false, 'error' => $ipb, 'ip_locked' => true];
    }

    switch ($action) {
        case 'connector_version':
            return ['ok' => true, 'hash' => hd_connector_hash()];

        case 'connector_code':
            $code = (string)@file_get_contents(hd_connector_path());
            if ($code === '') return ['ok' => false, 'error' => 'not available'];
            return ['ok' => true, 'hash' => hash('sha256', $code), 'sig' => hash_hmac('sha256', $code, (string)$bot['bot_key']), 'code' => base64_encode($code)];

        case 'captcha':
            return hd_captcha_new($pdo, $bot);

        case 'login': {
            $name   = trim(mb_substr((string)($in['name'] ?? ''), 0, 60));
            $mobile = hd_normalize_mobile($in['mobile'] ?? '');
            if ($mobile === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثال: 09121234567).'];
            $m = hd_member_by_mobile($pdo, $bot['id'], $mobile);
            if (!$m) {
                // ثبت‌نام: نام لازم است
                if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'لطفاً نام خود را وارد کنید.', 'need_name' => true];
                if (!empty($bot['verify_mobile']) && hd_member_sms_on($pdo, $bot)) {
                    $err = hd_otp_send($pdo, $bot, $mobile, 'login');
                    if ($err === '') return ['ok' => true, 'need_code' => true];
                    // امنیت: وقتی تأیید شماره فعال است، بدون کد ثبت‌نام انجام نمی‌شود
                    return ['ok' => false, 'error' => strpos($err, 'زیاد') !== false ? $err : 'ارسال کد تأیید ممکن نشد؛ چند دقیقه دیگر دوباره تلاش کنید.'];
                }
                $mid = hd_create_member($pdo, $bot['id'], $mobile, $name, false);
                return ['ok' => true, 'token' => hd_issue_token($pdo, $mid), 'name' => $name, 'new' => true];
            }
            if ($m['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی این حساب محدود شده است.'];
            $meth = hd_login_methods($pdo, $bot, $m);
            if (!$meth['sms'] && !$meth['password'] && !$meth['google']) {
                // هیچ روش امنی برای اثبات مالکیت شماره نیست
                $th = $pdo->prepare("SELECT COUNT(*) FROM hd_threads WHERE bot_id=? AND member_id=?");
                $th->execute([(int)$bot['id'], (int)$m['id']]);
                $has_data = (int)$th->fetchColumn() > 0 || trim((string)($m['profile'] ?? '')) !== '' && trim((string)$m['profile']) !== '[]' && trim((string)$m['profile']) !== '{}';
                if (!$has_data && empty($bot['verify_mobile'])) {
                    // حساب خالی (بدون گفتگو و پرونده): چیزی برای افشا ندارد؛ ورود + پیشنهاد گذاشتن رمز
                    $r = hd_login_ok($pdo, $m);
                    $r['secure_hint'] = true;
                    return $r;
                }
                // امنیت: ورود به حسابی که گفتگو یا پرونده دارد فقط با کد پیامکی/رمز/گوگل — به صاحب چت‌بات اطلاع داده می‌شود
                if (function_exists('biz_notify')) {
                    $flag = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.hd_nosms_' . (int)$bot['id'] . '_' . date('Ymd');
                    if (!is_file($flag)) { @file_put_contents($flag, '1'); try { biz_notify($pdo, (int)$bot['user_id'], '⚠️ مخاطبان «' . $bot['name'] . '» نمی‌توانند وارد شوند', 'برای حفظ امنیت پرونده مخاطبان، ورود فقط با کد پیامکی ممکن است اما ارسال پیامک برای این چت‌بات فعال نیست (اعتبار پیامک یا تنظیمات پیامک را بررسی کنید).', 'danger', 'billing.php'); } catch (\Throwable $e) {} }
                }
                return ['ok' => false, 'error' => 'برای حفظ امنیت پرونده شما، ورود فقط با کد پیامکی ممکن است و الان ارسال پیامک در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.'];
            }
            return ['ok' => true, 'exists' => true, 'methods' => $meth, 'mobile_masked' => hd_mask_mobile($mobile)];
        }

        case 'send_code': {
            $mobile = hd_normalize_mobile($in['mobile'] ?? '');
            $purpose = ($in['purpose'] ?? '') === 'reset' ? 'reset' : 'login';
            if ($mobile === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.'];
            $m = hd_member_by_mobile($pdo, $bot['id'], $mobile);
            if ($purpose === 'reset' && !$m) return ['ok' => false, 'error' => 'حسابی با این شماره پیدا نشد.'];
            if ($purpose === 'login' && !$m) return ['ok' => false, 'error' => 'ابتدا نام و شماره را وارد کنید.'];
            if ($purpose === 'login' && !hd_member_sms_on($pdo, $bot)) return ['ok' => false, 'error' => 'ورود با کد پیامکی فعال نیست.'];
            $err = hd_otp_send($pdo, $bot, $mobile, $purpose);
            return $err === '' ? ['ok' => true, 'need_code' => true] : ['ok' => false, 'error' => $err];
        }

        case 'verify': {
            $name   = trim(mb_substr((string)($in['name'] ?? ''), 0, 60));
            $mobile = hd_normalize_mobile($in['mobile'] ?? '');
            $err = hd_otp_check($pdo, $bot, $mobile, (string)($in['code'] ?? ''), 'login');
            if ($err !== '') return ['ok' => false, 'error' => $err];
            $m = hd_member_by_mobile($pdo, $bot['id'], $mobile);
            if ($m) {
                if ($m['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی این حساب محدود شده است.'];
                $pdo->prepare("UPDATE hd_members SET verified=1, pw_fail=0, pw_lock_until=NULL WHERE id=?")->execute([(int)$m['id']]);
                return hd_login_ok($pdo, $m, mb_strlen($name) >= 2 ? $name : '');
            }
            $mid = hd_create_member($pdo, $bot['id'], $mobile, mb_strlen($name) >= 2 ? $name : 'کاربر', true);
            return ['ok' => true, 'token' => hd_issue_token($pdo, $mid), 'name' => $name, 'new' => true];
        }

        case 'pw_login': {
            $mobile = hd_normalize_mobile($in['mobile'] ?? '');
            if (!hd_captcha_check($pdo, $bot, $in['captcha_id'] ?? '', $in['captcha'] ?? '')) return ['ok' => false, 'error' => 'کد امنیتی (عدد تصویر) نادرست است.', 'captcha_fail' => true];
            $m = hd_member_by_mobile($pdo, $bot['id'], $mobile);
            if (!$m || trim((string)$m['password_hash']) === '') {
                $ipl = function_exists('ipg_fail') ? ipg_fail($pdo, 'bot', $mobile) : '';
                return ['ok' => false, 'error' => $ipl !== '' ? $ipl : 'شماره موبایل یا رمز عبور نادرست است.'];
            }
            if ($m['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی این حساب محدود شده است.'];
            if (!empty($m['pw_lock_until']) && strtotime((string)$m['pw_lock_until']) > time()) {
                // تلاش روی حساب قفل‌شده هم برای IP ورود ناموفق حساب می‌شود
                $ipl = function_exists('ipg_fail') ? ipg_fail($pdo, 'bot', $mobile) : '';
                if ($ipl !== '') return ['ok' => false, 'error' => $ipl];
                $min = max(1, (int)ceil((strtotime((string)$m['pw_lock_until']) - time()) / 60));
                return ['ok' => false, 'error' => "به‌دلیل تلاش‌های ناموفق، ورود با رمز تا {$min} دقیقه دیگر ممکن نیست. می‌توانید از «فراموشی رمز» استفاده کنید."];
            }
            if (!password_verify((string)($in['password'] ?? ''), (string)$m['password_hash'])) {
                $f = (int)$m['pw_fail'] + 1;
                $lock = $f >= HD_PW_MAX_FAIL ? hd_now(HD_PW_LOCK_MIN * 60) : null;
                $pdo->prepare("UPDATE hd_members SET pw_fail=?, pw_lock_until=? WHERE id=?")->execute([$lock ? 0 : $f, $lock, (int)$m['id']]);
                $ipl = function_exists('ipg_fail') ? ipg_fail($pdo, 'bot', $mobile) : '';
                if ($ipl !== '') return ['ok' => false, 'error' => $ipl];
                return ['ok' => false, 'error' => $lock ? 'رمز نادرست است. ورود با رمز به مدت ' . HD_PW_LOCK_MIN . ' دقیقه قفل شد.' : 'شماره موبایل یا رمز عبور نادرست است.'];
            }
            $pdo->prepare("UPDATE hd_members SET pw_fail=0, pw_lock_until=NULL WHERE id=?")->execute([(int)$m['id']]);
            if (function_exists('ipg_success')) ipg_success($pdo);
            if (password_needs_rehash((string)$m['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE hd_members SET password_hash=? WHERE id=?")->execute([password_hash((string)$in['password'], PASSWORD_DEFAULT), (int)$m['id']]);
            }
            return hd_login_ok($pdo, $m);
        }

        case 'pw_reset': {
            $mobile = hd_normalize_mobile($in['mobile'] ?? '');
            $pw = (string)($in['password'] ?? '');
            if (($e = hd_password_error($pw)) !== '') return ['ok' => false, 'error' => $e];
            $m = hd_member_by_mobile($pdo, $bot['id'], $mobile);
            if (!$m) return ['ok' => false, 'error' => 'حسابی با این شماره پیدا نشد.'];
            $err = hd_otp_check($pdo, $bot, $mobile, (string)($in['code'] ?? ''), 'reset');
            if ($err !== '') return ['ok' => false, 'error' => $err];
            if ($m['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی این حساب محدود شده است.'];
            $pdo->prepare("UPDATE hd_members SET password_hash=?, verified=1, pw_fail=0, pw_lock_until=NULL WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$m['id']]);
            $r = hd_login_ok($pdo, $m);
            $r['msg'] = 'رمز عبور جدید ذخیره شد.';
            return $r;
        }

        case 'g_start':
            return hd_google_start($pdo, $bot, (string)($in['return_url'] ?? ''), 'login');

        case 'g_finish': {
            $row = hd_google_row_by_code($pdo, $bot, $in['code'] ?? '');
            if (!$row) return ['ok' => false, 'error' => 'ورود با گوگل منقضی شده است؛ دوباره تلاش کنید.'];
            $other = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND google_sub=? AND is_owner=0 LIMIT 1");
            $other->execute([(int)$bot['id'], $row['sub']]);
            $gm = $other->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($row['mode'] === 'link') {
                $pdo->prepare("DELETE FROM hd_glogin WHERE id=?")->execute([(int)$row['id']]);
                if ($gm && (int)$gm['id'] !== (int)$row['member_id']) return ['ok' => false, 'error' => 'این حساب گوگل به حساب دیگری متصل است.'];
                $pdo->prepare("UPDATE hd_members SET google_sub=?, email=? WHERE id=? AND bot_id=?")->execute([$row['sub'], $row['email'], (int)$row['member_id'], (int)$bot['id']]);
                return ['ok' => true, 'linked' => true];
            }
            if ($gm) {
                $pdo->prepare("DELETE FROM hd_glogin WHERE id=?")->execute([(int)$row['id']]);
                if ($gm['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی این حساب محدود شده است.'];
                return hd_login_ok($pdo, $gm);
            }
            // نسخه ۶۱: مخاطبی که قبلاً ثبت‌نام کرده یا با همین جیمیل وارد شده، بدون پرسیدن نام و موبایل وارد می‌شود
            $known = hd_google_known_member($pdo, $bot, $row);
            if ($known) {
                $pdo->prepare("DELETE FROM hd_glogin WHERE id=?")->execute([(int)$row['id']]);
                if ($known['status'] !== 'active') return ['ok' => false, 'error' => 'دسترسی این حساب محدود شده است.'];
                return hd_login_ok($pdo, $known) + (!empty($known['_new']) ? ['new' => true] : []);
            }
            // حساب گوگل تازه: برای ساخت حساب، شماره موبایل لازم است
            return ['ok' => true, 'need_mobile' => true, 'email' => $row['email'], 'name' => $row['name']];
        }

        case 'g_register': {
            $row = hd_google_row_by_code($pdo, $bot, $in['code'] ?? '');
            if (!$row || $row['mode'] !== 'login') return ['ok' => false, 'error' => 'ورود با گوگل منقضی شده است؛ دوباره تلاش کنید.'];
            $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 60));
            $mobile = hd_normalize_mobile($in['mobile'] ?? '');
            if (mb_strlen($name) < 2) return ['ok' => false, 'error' => 'لطفاً نام خود را وارد کنید.'];
            if ($mobile === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثال: 09121234567).'];
            if (hd_member_by_mobile($pdo, $bot['id'], $mobile)) {
                return ['ok' => false, 'error' => 'برای این شماره قبلاً حساب ساخته شده است. ابتدا با کد پیامکی یا رمز عبور وارد شوید، سپس از «پرونده من» حساب گوگل را متصل کنید.'];
            }
            $mid = hd_create_member($pdo, $bot['id'], $mobile, $name, false);
            $pdo->prepare("UPDATE hd_members SET google_sub=?, email=? WHERE id=?")->execute([$row['sub'], $row['email'], $mid]);
            $pdo->prepare("DELETE FROM hd_glogin WHERE id=?")->execute([(int)$row['id']]);
            return ['ok' => true, 'token' => hd_issue_token($pdo, $mid), 'name' => $name, 'new' => true];
        }
    }
    return null;
}

// =============================================================================
// عملیات حساب (پس از ورود)
// =============================================================================
function hd_auth_member_dispatch($pdo, $bot, $member, $action, $in)
{
    switch ($action) {
        case 'account':
            return ['ok' => true, 'mobile' => $member['mobile'], 'has_password' => trim((string)($member['password_hash'] ?? '')) !== '',
                    'email' => (string)($member['email'] ?? ''), 'google' => trim((string)($member['google_sub'] ?? '')) !== '',
                    'google_ready' => hd_google_ready($pdo), 'sms' => hd_sms_ready($pdo, $bot), 'imp' => !empty($member['_imp'])];

        case 'set_password': {
            if (!empty($member['_imp'])) return ['ok' => false, 'error' => 'رمز را فقط خود مراجع می‌تواند تغییر دهد.'];
            $new = (string)($in['password'] ?? '');
            if (($e = hd_password_error($new)) !== '') return ['ok' => false, 'error' => $e];
            if (trim((string)$member['password_hash']) !== '' && !password_verify((string)($in['current'] ?? ''), (string)$member['password_hash'])) {
                return ['ok' => false, 'error' => 'رمز فعلی نادرست است. اگر آن را فراموش کرده‌اید، خارج شوید و از «فراموشی رمز» استفاده کنید.'];
            }
            $pdo->prepare("UPDATE hd_members SET password_hash=?, pw_fail=0, pw_lock_until=NULL WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), (int)$member['id']]);
            return ['ok' => true, 'msg' => 'رمز عبور ذخیره شد. از این پس با شماره موبایل و این رمز هم می‌توانید وارد شوید.'];
        }

        case 'g_link':
            if (!empty($member['_imp'])) return ['ok' => false, 'error' => 'فقط خود مراجع می‌تواند حساب گوگل را متصل کند.'];
            return hd_google_start($pdo, $bot, (string)($in['return_url'] ?? ''), 'link', (int)$member['id']);

        case 'g_unlink':
            if (!empty($member['_imp'])) return ['ok' => false, 'error' => 'فقط خود مراجع می‌تواند این کار را انجام دهد.'];
            if (trim((string)$member['password_hash']) === '' && !hd_sms_ready($pdo, $bot)) {
                return ['ok' => false, 'error' => 'ابتدا یک رمز عبور بگذارید تا بعد از قطع اتصال گوگل بتوانید وارد حساب شوید.'];
            }
            $pdo->prepare("UPDATE hd_members SET google_sub='', email='' WHERE id=?")->execute([(int)$member['id']]);
            return ['ok' => true];
    }
    return null;
}

} // HD_AUTH_LOADED
