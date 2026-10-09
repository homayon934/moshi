<?php
/**
 * محدودیت‌های روزانه، هفتگی و ماهانه بسته‌های اشتراکی چت‌بات (نسخه ۳۹)
 *
 *  - هر بسته: سقف روزانه (daily_limit، از قبل)، سقف هفتگی (week_limit) و سقف ماهانه (month_limit)؛ ۰ = بدون سقف
 *  - روزانه و هفتگی «نرم» هستند: مخاطب با «ریست رایگان» از سهمیه روزها/هفته‌های بعد استفاده می‌کند
 *    (پیش از آن هشدار: اشتراک زودتر از یک ماه تمام می‌شود). ماهانه و کل پیام‌های بسته «سخت» است.
 *  - هفته از شنبه شروع می‌شود؛ ماه = دوره‌های ۳۰ روزه از تاریخ خرید بسته.
 *
 * این فایل از انتهای biz_lib.php بارگذاری می‌شود و hd_quota() از آن استفاده می‌کند.
 */

if (!defined('LIM_SCHEMA_VERSION')) define('LIM_SCHEMA_VERSION', 1);

function lim_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.lim_schema_v' . LIM_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $ok = true;
    foreach ([['hd_member_plans', 'week_limit', 'INT NOT NULL DEFAULT 0'], ['hd_member_plans', 'month_limit', 'INT NOT NULL DEFAULT 0'],
              ['hd_purchases', 'week_limit', 'INT NOT NULL DEFAULT 0'], ['hd_purchases', 'month_limit', 'INT NOT NULL DEFAULT 0'],
              ['hd_members', 'borrow_day', "VARCHAR(10) NOT NULL DEFAULT ''"], ['hd_members', 'borrow_week', "VARCHAR(10) NOT NULL DEFAULT ''"],
              ['hd_members', 'borrow_count', 'INT NOT NULL DEFAULT 0']] as [$t, $c, $d]) {
        try { saas_add_column_if_missing($pdo, $t, $c, $d); } catch (\Throwable $e) { $ok = false; error_log('[LIM] ' . $t . '.' . $c . ': ' . $e->getMessage()); }
    }
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
}

/** شروع هفته جاری (شنبه ۰۰:۰۰) به‌صورت timestamp */
function lim_week_start($ts = null)
{
    $ts = $ts ?? time();
    $w = (int)date('w', $ts);              // 0=یکشنبه … 6=شنبه
    $back = ($w + 1) % 7;                  // روزهای گذشته از شنبه
    return strtotime(date('Y-m-d 00:00:00', $ts)) - $back * 86400;
}
function lim_week_key($ts = null) { return date('Y-m-d', lim_week_start($ts)); }

function lim_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }

/** متن زمان آزاد شدن: «فردا ساعت ۰۰:۰۰» یا تاریخ شمسی برای فاصله‌های طولانی */
function lim_when($ts)
{
    $days = (int)floor((strtotime(date('Y-m-d 00:00:00', $ts)) - strtotime(date('Y-m-d 00:00:00'))) / 86400);
    $jd = function_exists('rem_jdate') ? rem_jdate(date('Y-m-d', $ts)) : date('Y-m-d', $ts);
    $wd = function_exists('rem_wdays') ? rem_wdays()[(int)date('w', $ts)] : '';
    if ($days <= 0) return 'امروز ساعت ' . lim_fa(date('H:i', $ts));
    if ($days === 1) return 'فردا (' . $wd . ' ' . lim_fa($jd) . ')';
    return $wd . ' ' . lim_fa($jd);
}

function lim_count($pdo, $bot_id, $member_id, $from_ts)
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM hd_messages WHERE bot_id=? AND member_id=? AND role='user' AND created_at >= ?");
    $s->execute([(int)$bot_id, (int)$member_id, date('Y-m-d H:i:s', $from_ts)]);
    return (int)$s->fetchColumn();
}

/**
 * وضعیت سقف‌های هفتگی و ماهانه بسته‌های فعال (فقط حالت اشتراکی)
 * @return array ['week' => ?[limit,used,left,reset_at,borrowed], 'month' => ?[...], 'reason' => ''|'weekly'|'monthly']
 */
function lim_paid_limits($pdo, $bot, $member, array $purch)
{
    lim_ensure_schema($pdo);
    $wk = 0; $mo = 0; $mo_start = null;
    foreach ($purch as $p) {
        if ((int)$p['used'] >= (int)$p['messages']) continue;
        $wk = max($wk, (int)($p['week_limit'] ?? 0));
        if ((int)($p['month_limit'] ?? 0) > 0 && (int)$p['month_limit'] >= $mo) {
            $mo = (int)$p['month_limit'];
            $start = strtotime((string)($p['paid_at'] ?? '') ?: (string)$p['created_at']) ?: time();
            $mo_start = $start + (int)floor((time() - $start) / (30 * 86400)) * 30 * 86400;
        }
    }
    $out = ['week' => null, 'month' => null, 'reason' => ''];
    if ($wk > 0) {
        $ws = lim_week_start();
        $used = lim_count($pdo, $bot['id'], $member['id'], $ws);
        $borrowed = (string)($member['borrow_week'] ?? '') === lim_week_key();
        $out['week'] = ['limit' => $wk, 'used' => $used, 'left' => max(0, $wk - $used), 'reset_at' => $ws + 7 * 86400, 'borrowed' => $borrowed];
        if (!$borrowed && $used >= $wk) $out['reason'] = 'weekly';
    }
    if ($mo > 0) {
        $used = lim_count($pdo, $bot['id'], $member['id'], $mo_start);
        $out['month'] = ['limit' => $mo, 'used' => $used, 'left' => max(0, $mo - $used), 'reset_at' => $mo_start + 30 * 86400, 'borrowed' => false];
        if ($used >= $mo) $out['reason'] = 'monthly';   // ماهانه مقدم است (قابل ریست نیست)
    }
    return $out;
}

/** آیا «ریست رایگان» (استفاده از سهمیه دوره بعد) برای این محدودیت مجاز است؟ */
function lim_can_borrow($quota)
{
    return ($quota['mode'] ?? '') === 'paid' && in_array($quota['reason'] ?? '', ['daily', 'weekly'], true) && (int)($quota['paid_left'] ?? 0) > 0;
}

/** متن هشدار پیش از ریست رایگان */
function lim_borrow_warning($kind)
{
    return $kind === 'weekly'
        ? 'با «ریست رایگان»، از سهمیه هفته‌های بعد استفاده می‌کنید. پیام‌هایی که از امروز تا پایان این هفته می‌فرستید از کل پیام‌های اشتراک کم می‌شود و در این صورت اشتراک شما زودتر از یک ماه به اتمام خواهد رسید.'
        : 'با «ریست رایگان»، از سهمیه روزهای بعد استفاده می‌کنید. پیام‌هایی که امروز می‌فرستید از کل پیام‌های اشتراک کم می‌شود و در این صورت اشتراک شما زودتر از یک ماه به اتمام خواهد رسید.';
}

/** ثبت «ریست رایگان»: سقف امروز (یا این هفته) برای این مخاطب برداشته می‌شود؛ کل پیام‌های اشتراک دست‌نخورده می‌ماند */
function lim_borrow($pdo, $bot, $member, $kind)
{
    lim_ensure_schema($pdo);
    $q = hd_quota($pdo, $bot, $member);
    if (!lim_can_borrow($q)) {
        if (($q['reason'] ?? '') === 'monthly') return ['ok' => false, 'error' => 'سقف ماهانه اشتراک قابل ریست نیست؛ برای ادامه، پلن را ارتقا دهید.', 'quota' => $q];
        if (($q['reason'] ?? '') === '') return ['ok' => true, 'quota' => $q];   // دیگر محدودیتی نیست
        return ['ok' => false, 'error' => 'ریست رایگان برای این محدودیت ممکن نیست.', 'quota' => $q];
    }
    $kind = $q['reason'];   // همان محدودیتی که الان فعال است
    if ($kind === 'daily') $pdo->prepare("UPDATE hd_members SET borrow_day=?, borrow_count=borrow_count+1 WHERE id=?")->execute([date('Y-m-d'), (int)$member['id']]);
    else $pdo->prepare("UPDATE hd_members SET borrow_week=?, borrow_count=borrow_count+1 WHERE id=?")->execute([lim_week_key(), (int)$member['id']]);
    $s = $pdo->prepare("SELECT * FROM hd_members WHERE id=?"); $s->execute([(int)$member['id']]);
    $m2 = ($s->fetch(\PDO::FETCH_ASSOC) ?: []) + $member;
    $q2 = hd_quota($pdo, $bot, $m2);
    return ['ok' => true, 'msg' => $kind === 'daily' ? '♻️ سقف امروز برداشته شد؛ ادامه دهید.' : '♻️ سقف این هفته برداشته شد؛ ادامه دهید.', 'quota' => $q2, 'kind' => $kind];
}
