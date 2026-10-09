<?php
/**
 * محافظ ورود بر اساس IP (همه بخش‌ها: مدیر کل، پنل کاربران/همکاران، همکاری در فروش، مخاطبان چت‌بات‌ها، خرید سریع پلن)
 *
 *  - هر IP جداگانه شمرده می‌شود. اگر از یک IP بیش از «حد مجاز» (پیش‌فرض ۵) ورود ناموفق ثبت شود:
 *      بار اول  ← قفل موقت (پیش‌فرض ۳ دقیقه)
 *      بار دوم  ← قفل موقت (پیش‌فرض ۱۵ دقیقه)
 *      بار سوم  ← مسدود دائمی (تا وقتی مدیر کل رفع مسدودی کند)
 *  - اگر تا «مدت فراموشی» (پیش‌فرض ۲۴ ساعت) قفل جدیدی رخ ندهد، مرحله به صفر برمی‌گردد.
 *  - ورود ناموفق = رمز اشتباه یا کد پیامکی اشتباه (نه اشتباه در کپچا).
 *  - IPهای «امن» (فهرست سفید) هرگز قفل نمی‌شوند.
 *  - راه نجات مدیر: اگر IP خود مدیر مسدود شد، یک فایل خالی با نام ipguard_reset.txt
 *    در پوشه uploads بسازید؛ در اولین بازدید همه قفل‌ها و مسدودی‌ها پاک و فایل حذف می‌شود.
 */

if (!defined('IPG_SCHEMA_VERSION')) define('IPG_SCHEMA_VERSION', 1);

function ipg_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.ipg_schema_v' . IPG_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $ok = true;
    $q = function ($sql) use ($pdo, &$ok) { try { $pdo->exec($sql); } catch (\Throwable $e) { $ok = false; error_log('[IPG] schema: ' . $e->getMessage()); } };
    $q("CREATE TABLE IF NOT EXISTS saas_ip_guard (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        fails INT NOT NULL DEFAULT 0,
        total_fails INT NOT NULL DEFAULT 0,
        level INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        blocked TINYINT(1) NOT NULL DEFAULT 0,
        whitelist TINYINT(1) NOT NULL DEFAULT 0,
        last_fail DATETIME NULL,
        last_lock DATETIME NULL,
        last_area VARCHAR(20) NOT NULL DEFAULT '',
        last_ident VARCHAR(120) NOT NULL DEFAULT '',
        note VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_blocked (blocked),
        INDEX idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_ip_guard_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        area VARCHAR(20) NOT NULL DEFAULT '',
        ident VARCHAR(120) NOT NULL DEFAULT '',
        event VARCHAR(10) NOT NULL DEFAULT 'fail',
        created_at DATETIME NOT NULL,
        INDEX idx_ip (ip),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
}

/** تنظیمات (از بخش «امنیت ورود» پنل مدیر کل) */
function ipg_conf($pdo)
{
    $g = function ($k, $d) use ($pdo) {
        if (!$pdo || !function_exists('biz_settings')) return $d;
        $s = biz_settings($pdo);
        return (isset($s[$k]) && $s[$k] !== '') ? $s[$k] : $d;
    };
    return [
        'on'    => (int)$g('ipg_on', 1) ? 1 : 0,
        'max'   => max(2, min(50, (int)$g('ipg_max', 5))),       // تعداد ورود ناموفق مجاز؛ بیش از این ← قفل
        'lock1' => max(1, min(1440, (int)$g('ipg_lock1', 3))),    // دقیقه
        'lock2' => max(1, min(10080, (int)$g('ipg_lock2', 15))),  // دقیقه
        'decay' => max(1, min(720, (int)$g('ipg_decay', 24))),    // ساعت
    ];
}

function ipg_areas()
{
    return ['admin' => 'مدیر کل', 'user' => 'پنل کاربران/همکاران', 'aff' => 'همکاری در فروش', 'bot' => 'مخاطب چت‌بات', 'plan' => 'خرید پلن', 'reset' => 'بازیابی رمز'];
}

/**
 * IP درخواست‌کننده واقعی. در درخواست‌های چت‌بات (که از سرور سایت مشتری می‌آید) IP بازدیدکننده
 * توسط فایل رابط فرستاده می‌شود و با ipg_set_client تنظیم می‌شود؛ اگر نامعتبر باشد محافظ غیرفعال است
 * (تا IP سرور سایت مشتری به‌اشتباه قفل نشود).
 */
function ipg_set_client($ip)
{
    $ip = trim((string)$ip);
    $GLOBALS['ipg_client_ip'] = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function ipg_ip()
{
    if (array_key_exists('ipg_client_ip', $GLOBALS)) return (string)$GLOBALS['ipg_client_ip'];
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? substr($ip, 0, 45) : '';
}

function ipg_now($plus = 0) { return date('Y-m-d H:i:s', time() + (int)$plus); }

/** راه نجات: فایل uploads/ipguard_reset.txt ← پاک شدن همه قفل‌ها و مسدودی‌ها */
function ipg_recovery($pdo)
{
    static $checked = false;
    if ($checked || !$pdo) return;
    $checked = true;
    $dir = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/');
    foreach (['ipguard_reset.txt', 'ipguard_reset', '.ipguard_reset'] as $n) {
        $f = $dir . '/' . $n;
        if (!is_file($f)) continue;
        try {
            ipg_ensure_schema($pdo);
            $pdo->prepare("UPDATE saas_ip_guard SET blocked=0, locked_until=NULL, fails=0, level=0, updated_at=? WHERE blocked=1 OR locked_until IS NOT NULL OR fails>0 OR level>0")->execute([ipg_now()]);
        } catch (\Throwable $e) { error_log('[IPG] reset: ' . $e->getMessage()); }
        @unlink($f);
    }
}

function ipg_row($pdo, $ip)
{
    try {
        $s = $pdo->prepare("SELECT * FROM saas_ip_guard WHERE ip=?");
        $s->execute([$ip]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
}

function ipg_fa_num($n) { return strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }

function ipg_wait_text($sec)
{
    $sec = max(1, (int)$sec);
    if ($sec < 60) return ipg_fa_num($sec) . ' ثانیه';
    $m = (int)ceil($sec / 60);
    if ($m < 60) return ipg_fa_num($m) . ' دقیقه';
    $h = intdiv($m, 60); $r = $m % 60;
    return ipg_fa_num($h) . ' ساعت' . ($r ? ' و ' . ipg_fa_num($r) . ' دقیقه' : '');
}

function ipg_msg_locked($left) { return 'به‌دلیل چند ورود ناموفق پشت‌سرهم، ورود از این اینترنت (IP) تا ' . ipg_wait_text($left) . ' دیگر ممکن نیست.'; }
function ipg_msg_blocked() { return 'به‌دلیل ورودهای ناموفق مکرر، ورود از این اینترنت (IP) مسدود شده است. برای رفع مسدودی با پشتیبانی تماس بگیرید.'; }

/**
 * وضعیت IP فعلی. اگر ورود مجاز نیست پیام خطا برمی‌گرداند؛ در غیر این صورت رشته خالی.
 * صفحه‌های ورود قبل از بررسی رمز/کد این را صدا می‌زنند.
 */
function ipg_blocked_msg($pdo, $ip = null)
{
    if (!$pdo) return '';
    $ip = $ip ?? ipg_ip();
    if ($ip === '') return '';
    ipg_ensure_schema($pdo);
    ipg_recovery($pdo);
    if (!ipg_conf($pdo)['on']) return '';
    $r = ipg_row($pdo, $ip);
    if (!$r || !empty($r['whitelist'])) return '';
    if (!empty($r['blocked'])) return ipg_msg_blocked();
    if (!empty($r['locked_until'])) {
        $left = strtotime((string)$r['locked_until']) - time();
        if ($left > 0) return ipg_msg_locked($left);
    }
    return '';
}

/**
 * ثبت یک ورود ناموفق. اگر همین تلاش باعث قفل/مسدودی شد پیام آن را برمی‌گرداند (وگرنه رشته خالی).
 * $area: admin | user | aff | bot | plan | reset   —   $ident: شناسه واردشده (موبایل/ایمیل) برای گزارش
 */
function ipg_fail($pdo, $area, $ident = '')
{
    if (!$pdo) return '';
    $ip = ipg_ip();
    if ($ip === '') return '';
    ipg_ensure_schema($pdo);
    $c = ipg_conf($pdo);
    if (!$c['on']) return '';
    $now = time();
    $area = substr(preg_replace('/[^a-z]/', '', (string)$area), 0, 20);
    $ident = function_exists('mb_substr') ? mb_substr(trim((string)$ident), 0, 120) : substr(trim((string)$ident), 0, 120);
    try {
        $r = ipg_row($pdo, $ip);
        if (!$r) {
            try {
                $pdo->prepare("INSERT INTO saas_ip_guard (ip, created_at, updated_at) VALUES(?,?,?)")->execute([$ip, ipg_now(), ipg_now()]);
            } catch (\Throwable $e) {}   // هم‌زمانی: ردیف را درخواست دیگری ساخته است
            $r = ipg_row($pdo, $ip);
            if (!$r) return '';
        }
        if (!empty($r['whitelist'])) return '';
        if (!empty($r['blocked'])) return ipg_msg_blocked();
        if (!empty($r['locked_until']) && strtotime((string)$r['locked_until']) > $now) return ipg_msg_locked(strtotime((string)$r['locked_until']) - $now);

        $fails = (int)$r['fails'];
        $level = (int)$r['level'];
        $decay = $c['decay'] * 3600;
        // فراموشی: اگر مدتی قفل/خطای جدیدی نبوده، از صفر
        if ($level > 0 && !empty($r['last_lock']) && $now - strtotime((string)$r['last_lock']) > $decay) $level = 0;
        if ($fails > 0 && !empty($r['last_fail']) && $now - strtotime((string)$r['last_fail']) > $decay) $fails = 0;
        $fails++;
        $locked_until = null;
        $blocked = 0;
        $event = 'fail';
        $msg = '';
        if ($fails > $c['max']) {
            $level++;
            $fails = 0;
            if ($level >= 3) {
                $blocked = 1; $event = 'block'; $msg = ipg_msg_blocked();
            } else {
                $min = $level === 1 ? $c['lock1'] : $c['lock2'];
                $locked_until = ipg_now($min * 60); $event = 'lock'; $msg = ipg_msg_locked($min * 60);
            }
        }
        $pdo->prepare("UPDATE saas_ip_guard SET fails=?, total_fails=total_fails+1, level=?, locked_until=?, blocked=?, last_fail=?, last_lock=?, last_area=?, last_ident=?, updated_at=? WHERE ip=?")
            ->execute([$fails, $level, $locked_until, $blocked, ipg_now(), $event !== 'fail' ? ipg_now() : $r['last_lock'], $area, $ident, ipg_now(), $ip]);
        $pdo->prepare("INSERT INTO saas_ip_guard_log (ip, area, ident, event, created_at) VALUES(?,?,?,?,?)")->execute([$ip, $area, $ident, $event, ipg_now()]);
        if ($event !== 'fail') {
            if (mt_rand(1, 20) === 1) { try { $pdo->prepare("DELETE FROM saas_ip_guard_log WHERE created_at < ?")->execute([ipg_now(-60 * 86400)]); } catch (\Throwable $e) {} }
        }
        return $msg;
    } catch (\Throwable $e) {
        error_log('[IPG] fail: ' . $e->getMessage());
        return '';
    }
}

/** ورود موفق: شمارنده خطاهای این IP صفر می‌شود (مرحله قفل تا مدت فراموشی می‌ماند) */
function ipg_success($pdo)
{
    if (!$pdo) return;
    $ip = ipg_ip();
    if ($ip === '') return;
    try {
        ipg_ensure_schema($pdo);
        $pdo->prepare("UPDATE saas_ip_guard SET fails=0, updated_at=? WHERE ip=? AND fails>0")->execute([ipg_now(), $ip]);
    } catch (\Throwable $e) {}
}

// ---------------------------------------------------------------------------
// مدیریت (پنل مدیر کل)
// ---------------------------------------------------------------------------
function ipg_admin_set($pdo, $ip, array $fields)
{
    $ip = trim((string)$ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    ipg_ensure_schema($pdo);
    if (!ipg_row($pdo, $ip)) {
        try { $pdo->prepare("INSERT INTO saas_ip_guard (ip, created_at, updated_at) VALUES(?,?,?)")->execute([$ip, ipg_now(), ipg_now()]); } catch (\Throwable $e) {}
    }
    $allowed = ['fails', 'level', 'locked_until', 'blocked', 'whitelist', 'note'];
    $set = []; $vals = [];
    foreach ($fields as $k => $v) if (in_array($k, $allowed, true)) { $set[] = "$k=?"; $vals[] = $v; }
    if (!$set) return false;
    $vals[] = ipg_now(); $vals[] = $ip;
    $pdo->prepare("UPDATE saas_ip_guard SET " . implode(', ', $set) . ", updated_at=? WHERE ip=?")->execute($vals);
    return true;
}

/** رفع کامل قفل/مسدودی یک IP */
function ipg_unblock($pdo, $ip)
{
    return ipg_admin_set($pdo, $ip, ['fails' => 0, 'level' => 0, 'locked_until' => null, 'blocked' => 0]);
}

/** تعداد IPهای مسدود دائمی (برای نشان در منو) */
function ipg_blocked_count($pdo)
{
    try { ipg_ensure_schema($pdo); return (int)$pdo->query("SELECT COUNT(*) FROM saas_ip_guard WHERE blocked=1")->fetchColumn(); } catch (\Throwable $e) { return 0; }
}
