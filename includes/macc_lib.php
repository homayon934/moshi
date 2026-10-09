<?php
/**
 * پنل مرکزی مخاطبان (نسخه ۴۷) — آدرس: member/
 *
 *  مخاطب (کسی که با چت‌بات‌های کاربران گفتگو می‌کند) بدون نیاز به چت، در سایت اصلی با شماره موبایل و کد پیامکی
 *  ثبت‌نام/وارد می‌شود و همه چت‌بات‌هایی را که با همین شماره عضو آن‌هاست یک‌جا می‌بیند:
 *  وضعیت و سهمیه، پرونده و یادداشت‌های مشاور، گفتگوها، یادآورها، جلسات، بسته‌ها و خرید، پشتیبانی (هر امکانی که صاحب چت‌بات فعال کرده).
 *
 *  همه عملیات از همان hd_api_dispatch چت‌بات انجام می‌شود (همان مجوزها و محدودیت‌ها)، با یک توکن جداگانه
 *  (hd_members.panel_hash) که فقط در نشست سرور می‌ماند و ورود چت در دستگاه‌های دیگر را باطل نمی‌کند.
 *  اگر «چت صفحه اصلی سایت» (حالت کامل) فعال باشد، ثبت‌نام‌کننده عضو آن هم می‌شود.
 */

if (!defined('MACC_SCHEMA_VERSION')) define('MACC_SCHEMA_VERSION', 1);

function macc_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.macc_schema_v' . MACC_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_member_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mobile VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            logins INT NOT NULL DEFAULT 0,
            last_login DATETIME NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (function_exists('saas_add_column_if_missing')) saas_add_column_if_missing($pdo, 'hd_members', 'panel_hash', "VARCHAR(64) NOT NULL DEFAULT ''");
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[MACC] schema: ' . $e->getMessage()); }
}

function macc_get($pdo, $mobile)
{
    macc_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM saas_member_accounts WHERE mobile=?");
    $s->execute([(string)$mobile]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** عضویت‌های فعال این شماره در چت‌بات‌ها (با مشخصات ربات) */
function macc_memberships($pdo, $mobile)
{
    if ((string)$mobile === '') return [];
    try {
        $s = $pdo->prepare("SELECT m.id AS member_id, m.name AS member_name, m.created_at AS joined, b.* FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id
                            WHERE m.mobile=? AND m.status='active' AND m.is_owner=0 AND b.is_active=1 ORDER BY m.last_seen DESC, m.id DESC LIMIT 50");
        $s->execute([(string)$mobile]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { error_log('[MACC] memberships: ' . $e->getMessage()); return []; }
}

/** چت‌بات و ردیف عضویت (اطمینان از تعلق به همین شماره) */
function macc_membership($pdo, $mobile, $bot_id)
{
    foreach (macc_memberships($pdo, $mobile) as $r) if ((int)$r['id'] === (int)$bot_id) return $r;
    return null;
}

/** آدرس صفحه چت هر چت‌بات (برای «ادامه گفتگو») */
function macc_chat_url($pdo, $bot)
{
    if (function_exists('sb_is_bot') && sb_is_bot($pdo, $bot)) return rtrim(AICHAT_BASE_URL, '/') . '/sitechat/';
    if (trim((string)($bot['custom_domain'] ?? '')) !== '' && !empty($bot['domain_paid'])) return 'https://' . trim($bot['custom_domain']) . '/';
    $u = trim((string)($bot['chat_url'] ?? ''));
    return preg_match('#^https?://#i', $u) ? $u : '';
}

/**
 * ورود/ثبت‌نام (پس از تأیید کد پیامکی)
 * @return array حساب
 */
function macc_login($pdo, $mobile, $name = '')
{
    macc_ensure_schema($pdo);
    $now = date('Y-m-d H:i:s');
    $a = macc_get($pdo, $mobile);
    $name = trim(mb_substr(strip_tags((string)$name), 0, 100));
    if (!$a) {
        if ($name === '') foreach (macc_memberships($pdo, $mobile) as $r) if (trim((string)$r['member_name']) !== '') { $name = $r['member_name']; break; }
        $pdo->prepare("INSERT INTO saas_member_accounts (mobile, name, logins, last_login, created_at) VALUES(?,?,1,?,?)")->execute([$mobile, $name, $now, $now]);
    } else {
        $pdo->prepare("UPDATE saas_member_accounts SET logins = logins + 1, last_login=?" . ($name !== '' && $a['name'] === '' ? ", name=?" : "") . " WHERE id=?")
            ->execute(array_merge([$now], $name !== '' && $a['name'] === '' ? [$name] : [], [(int)$a['id']]));
    }
    $a = macc_get($pdo, $mobile);
    // عضویت در «چت صفحه اصلی سایت» (اگر حالت کامل فعال است)
    if (function_exists('sb_bot') && ($sb = sb_bot($pdo)) && !empty($sb['is_active']) && !hd_member_by_mobile($pdo, (int)$sb['id'], $mobile)) {
        try { hd_create_member($pdo, (int)$sb['id'], $mobile, $a['name'] !== '' ? $a['name'] : 'کاربر', true); } catch (\Throwable $e) {}
    }
    // عضویت‌هایی که با این شماره تأیید نشده بودند، حالا تأیید شده‌اند (مالکیت شماره با کد پیامکی ثابت شد)
    try { $pdo->prepare("UPDATE hd_members SET verified=1 WHERE mobile=? AND verified=0")->execute([$mobile]); } catch (\Throwable $e) {}
    if (function_exists('act_log')) {
        act_log($pdo, 'maccount', (int)$a['id'], 'login', ['name' => $a['name'] !== '' ? $a['name'] : $mobile, 'title' => 'پنل مخاطب', 'path' => '']);
        foreach (macc_memberships($pdo, $mobile) as $r)
            act_log($pdo, 'member', (int)$r['member_id'], 'login', ['owner' => (int)$r['user_id'], 'bot' => (int)$r['id'], 'name' => (string)$r['member_name'], 'title' => 'پنل مخاطب', 'path' => '']);
    }
    return $a;
}

/**
 * اجرای یک عملیات چت‌بات به‌جای مخاطب (با توکن پنل)
 * توکن‌ها در $_SESSION['macc_tok'][member_id] نگه‌داری می‌شوند؛ اگر باطل شده باشد یک بار دوباره ساخته می‌شود.
 */
function macc_call($pdo, array $ms, $action, array $in = [])
{
    macc_ensure_schema($pdo);
    $mid = (int)$ms['member_id'];
    $bot = $ms;
    unset($bot['member_id'], $bot['member_name'], $bot['joined']);
    for ($try = 0; $try < 2; $try++) {
        $tok = (string)($_SESSION['macc_tok'][$mid] ?? '');
        if ($tok === '' || $try > 0) {
            $tok = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE hd_members SET panel_hash=? WHERE id=?")->execute([hash('sha256', $tok), $mid]);
            $_SESSION['macc_tok'][$mid] = $tok;
        }
        try { $r = hd_api_dispatch($pdo, $bot, ['action' => $action, 'token' => $tok, 'client_ip' => function_exists('ipg_ip') ? ipg_ip() : ''] + $in); }
        catch (\Throwable $e) { error_log('[MACC] ' . $action . ': ' . $e->getMessage()); return ['ok' => false, 'error' => 'خطای موقت؛ کمی بعد دوباره تلاش کنید.']; }
        if (empty($r['login_required']) || $try > 0) return is_array($r) ? $r : ['ok' => false, 'error' => 'خطا'];
    }
    return ['ok' => false, 'error' => 'خطا'];
}

/** ثبت بازدید بخش‌های پنل (برای گزارش صاحب چت‌بات و مدیر) */
function macc_page($pdo, array $ms, $title)
{
    if (function_exists('act_log')) act_log($pdo, 'member', (int)$ms['member_id'], 'page', ['owner' => (int)$ms['user_id'], 'bot' => (int)$ms['id'], 'name' => (string)$ms['member_name'], 'title' => 'پنل مخاطب: ' . $title]);
}
