<?php
/**
 * یادآور هوشمند (نسخه ۳۶)
 *
 *  - برای: مدیر کل (خودش، کاربران، همکاران کاربران)، کاربر (خودش، همکارانش، مخاطبان چت‌بات‌هایش)،
 *    همکار (خودش، مخاطبان)، مخاطب چت‌بات (خودش)
 *  - روش اطلاع: اعلان داخل سایت/پنل، اعلان مرورگر روی گوشی و کامپیوتر (Web Push)، پیامک — هر کدام قابل روشن/خاموش
 *  - دو روش ثبت: فرم (دسته، تاریخ، ساعت، تکرار، یادآوری قبل از موعد، …) و هوشمند (نوشتن یک جمله فارسی)
 *  - تکرار روزانه/هفتگی/ماهانه/سالانه (ماه و سال شمسی)، چند یادآوری پیش از موعد، تعویق (Snooze)، انجام شد
 *  - زمان‌ها به وقت ایران (مستقل از تنظیم ساعت سرور) ذخیره و مقایسه می‌شوند
 *  - پیامک مخاطبان چت‌بات: از سهمیه «پیامک یادآور» بسته‌هایی که خریده‌اند؛ هزینه واقعی از اعتبار پیامک صاحب چت‌بات
 */

if (!defined('REM_SCHEMA_VERSION')) define('REM_SCHEMA_VERSION', 1);

function rem_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.rem_schema_v' . REM_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $ok = true;
    $q = function ($sql) use ($pdo, &$ok) { try { $pdo->exec($sql); } catch (\Throwable $e) { $ok = false; error_log('[REM] schema: ' . $e->getMessage()); } };
    $has = function ($t) use ($pdo) { try { return $pdo->query("SELECT 1 FROM `$t` LIMIT 1") !== false; } catch (\Throwable $e) { return false; } };
    $col = function ($t, $c, $d) use ($pdo, &$ok) { try { saas_add_column_if_missing($pdo, $t, $c, $d); } catch (\Throwable $e) { $ok = false; error_log('[REM] column ' . $t . '.' . $c . ': ' . $e->getMessage()); } };
    $q("CREATE TABLE IF NOT EXISTS saas_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        creator_kind VARCHAR(10) NOT NULL DEFAULT 'user',
        creator_id INT NOT NULL DEFAULT 0,
        creator_name VARCHAR(150) NOT NULL DEFAULT '',
        owner_uid INT NOT NULL DEFAULT 0,
        bot_id INT NOT NULL DEFAULT 0,
        target_kind VARCHAR(10) NOT NULL DEFAULT 'user',
        target_id INT NOT NULL DEFAULT 0,
        target_name VARCHAR(150) NOT NULL DEFAULT '',
        title VARCHAR(200) NOT NULL,
        note TEXT NULL,
        category VARCHAR(20) NOT NULL DEFAULT 'task',
        person VARCHAR(150) NOT NULL DEFAULT '',
        place VARCHAR(150) NOT NULL DEFAULT '',
        due_at DATETIME NOT NULL,
        all_day TINYINT(1) NOT NULL DEFAULT 0,
        leads VARCHAR(80) NOT NULL DEFAULT '0',
        fired VARCHAR(80) NOT NULL DEFAULT '',
        repeat_kind VARCHAR(10) NOT NULL DEFAULT 'none',
        repeat_until DATETIME NULL,
        ch_site TINYINT(1) NOT NULL DEFAULT 1,
        ch_push TINYINT(1) NOT NULL DEFAULT 1,
        ch_sms TINYINT(1) NOT NULL DEFAULT 0,
        priority TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        source VARCHAR(10) NOT NULL DEFAULT 'form',
        raw_text VARCHAR(500) NOT NULL DEFAULT '',
        snooze_at DATETIME NULL,
        next_at DATETIME NULL,
        fire_count INT NOT NULL DEFAULT 0,
        last_fired DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        done_at DATETIME NULL,
        INDEX idx_next (status, next_at),
        INDEX idx_target (target_kind, target_id),
        INDEX idx_owner (owner_uid),
        INDEX idx_creator (creator_kind, creator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_rem_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reminder_id INT NOT NULL,
        target_kind VARCHAR(10) NOT NULL,
        target_id INT NOT NULL DEFAULT 0,
        title VARCHAR(200) NOT NULL DEFAULT '',
        body VARCHAR(500) NOT NULL DEFAULT '',
        lead_min INT NOT NULL DEFAULT 0,
        show_site TINYINT(1) NOT NULL DEFAULT 1,
        push_n INT NOT NULL DEFAULT 0,
        sms TINYINT NOT NULL DEFAULT 0,
        seen TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_t (target_kind, target_id, seen),
        INDEX idx_r (reminder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_push_subs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(10) NOT NULL,
        ref_id INT NOT NULL DEFAULT 0,
        bot_id INT NOT NULL DEFAULT 0,
        endpoint TEXT NOT NULL,
        ehash CHAR(64) NOT NULL,
        ua VARCHAR(200) NOT NULL DEFAULT '',
        fails INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        last_at DATETIME NULL,
        UNIQUE KEY uq_e (ehash),
        INDEX idx_ref (kind, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS saas_push_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sub_id INT NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT '',
        body VARCHAR(500) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        tag VARCHAR(60) NOT NULL DEFAULT '',
        high TINYINT(1) NOT NULL DEFAULT 0,
        delivered TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_s (sub_id, delivered)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // سهمیه پیامک یادآور مخاطبان (در بسته‌ها) و روشن/خاموش یادآور در هر چت‌بات
    if ($has('hd_member_plans')) $col('hd_member_plans', 'rem_sms', 'INT NOT NULL DEFAULT 0'); else $ok = false;
    if ($has('hd_purchases')) { $col('hd_purchases', 'rem_sms', 'INT NOT NULL DEFAULT 0'); $col('hd_purchases', 'rem_sms_used', 'INT NOT NULL DEFAULT 0'); } else $ok = false;
    if ($has('hd_bots')) $col('hd_bots', 'rem_on', 'TINYINT(1) NOT NULL DEFAULT 1'); else $ok = false;
    if ($has('hd_members')) $col('hd_members', 'rem_pref', "VARCHAR(30) NOT NULL DEFAULT 'site,push'"); else $ok = false;
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;
}

// =============================================================================
// زمان (وقت ایران) و تاریخ شمسی
// =============================================================================
function rem_tz()
{
    static $tz = null;
    if ($tz === null) {
        $name = 'Asia/Tehran';
        try { $s = function_exists('biz_settings') && isset($GLOBALS['pdo']) ? (string)(biz_settings($GLOBALS['pdo'])['rem_tz'] ?? '') : ''; if ($s !== '') $name = $s; } catch (\Throwable $e) {}
        try { $tz = new \DateTimeZone($name); } catch (\Throwable $e) { $tz = new \DateTimeZone('Asia/Tehran'); }
    }
    return $tz;
}

/** زمان فعلی (یا با اختلاف ثانیه) به وقت ایران: Y-m-d H:i:s */
function rem_now($plus = 0) { return rem_fmt(time() + (int)$plus); }
function rem_fmt($ts) { $d = new \DateTime('@' . (int)$ts); $d->setTimezone(rem_tz()); return $d->format('Y-m-d H:i:s'); }
/** تبدیل زمان ذخیره‌شده (وقت ایران) به timestamp */
function rem_ts($s)
{
    if (!$s) return 0;
    try { $d = new \DateTime((string)$s, rem_tz()); return $d->getTimestamp(); } catch (\Throwable $e) { return 0; }
}

function rem_g2j($gy, $gm, $gd)
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}

function rem_j2g($jy, $jm, $jd)
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) { $days--; $gy += 100 * intdiv($days, 36524); $days %= 36524; if ($days >= 365) $days++; }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    $gd = $days + 1;
    $sal = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13 && $gd > $sal[$gm]; $gm++) $gd -= $sal[$gm];
    return [$gy, $gm, $gd];
}

function rem_jleap($jy) { [$gy, $gm, $gd] = rem_j2g($jy, 12, 30); return rem_g2j($gy, $gm, $gd) === [$jy, 12, 30]; }
function rem_jlen($jy, $jm) { return $jm <= 6 ? 31 : ($jm <= 11 ? 30 : (rem_jleap($jy) ? 30 : 29)); }
function rem_jmonths() { return [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند']; }
function rem_wdays() { return [0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه']; }
function rem_fa($s) { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
function rem_en($s) { return strtr((string)$s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', 'ي' => 'ی', 'ك' => 'ک']); }

/** 'Y-m-d' یا 'Y-m-d H:i:s' (میلادی، وقت ایران) → 'YYYY/MM/DD' شمسی */
function rem_jdate($dt)
{
    [$y, $m, $d] = array_map('intval', explode('-', substr((string)$dt, 0, 10)));
    if ($y < 1900) return '';
    [$jy, $jm, $jd] = rem_g2j($y, $m, $d);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/** 'YYYY/MM/DD' شمسی → 'Y-m-d' میلادی ('' اگر نامعتبر) */
function rem_jparse($s)
{
    $s = rem_en(trim((string)$s));
    if (!preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $s, $m)) return '';
    [$jy, $jm, $jd] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if ($jy > 1700) return checkdate($jm, $jd, $jy) ? sprintf('%04d-%02d-%02d', $jy, $jm, $jd) : '';   // میلادی وارد شده
    if ($jy < 1300 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > rem_jlen($jy, $jm)) return '';
    [$gy, $gm, $gd] = rem_j2g($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** نمایش خوانا: «امروز، ساعت ۱۰:۳۰» / «سه‌شنبه ۱۵ مهر ۱۴۰۵، ساعت …» */
function rem_human($due, $all_day = false, $with_rel = true)
{
    $ts = rem_ts($due);
    if (!$ts) return '';
    $day = substr((string)$due, 0, 10);
    [$y, $m, $d] = array_map('intval', explode('-', $day));
    [$jy, $jm, $jd] = rem_g2j($y, $m, $d);
    $w = rem_wdays()[(int)(new \DateTime($day, rem_tz()))->format('w')];
    $today = substr(rem_now(), 0, 10);
    $diff = (int)round((strtotime($day . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);
    $rel = $with_rel ? ([0 => 'امروز', 1 => 'فردا', 2 => 'پس‌فردا', -1 => 'دیروز'][$diff] ?? '') : '';
    $date = $w . ' ' . rem_fa($jd) . ' ' . rem_jmonths()[$jm] . ($jy !== rem_g2j(...array_map('intval', explode('-', $today)))[0] ? ' ' . rem_fa($jy) : '');
    $s = $rel !== '' ? $rel . ' (' . $date . ')' : $date;
    if (!$all_day) $s .= '، ساعت ' . rem_fa(substr((string)$due, 11, 5));
    return $s;
}

// =============================================================================
// ثابت‌ها
// =============================================================================
function rem_cats()
{
    return [
        'meeting'  => ['جلسه و قرار', '🤝', '#2a78d6'],
        'call'     => ['تماس', '📞', '#1baf7a'],
        'task'     => ['کار و وظیفه', '✅', '#4a3aa7'],
        'payment'  => ['پرداخت و قسط', '💳', '#eb6834'],
        'birthday' => ['تولد و مناسبت', '🎂', '#e87ba4'],
        'medicine' => ['دارو و سلامت', '💊', '#008300'],
        'personal' => ['شخصی', '🏠', '#eda100'],
        'other'    => ['سایر', '📌', '#64748b'],
    ];
}
function rem_lead_opts() { return [0 => 'سر موعد', 15 => '۱۵ دقیقه قبل', 60 => '۱ ساعت قبل', 180 => '۳ ساعت قبل', 1440 => '۱ روز قبل', 10080 => '۱ هفته قبل']; }
function rem_repeat_opts() { return ['none' => 'بدون تکرار', 'daily' => 'هر روز', 'weekly' => 'هر هفته', 'monthly' => 'هر ماه (شمسی)', 'yearly' => 'هر سال (شمسی)']; }
function rem_lead_label($m)
{
    $m = (int)$m;
    if ($m <= 0) return 'سر موعد';
    if ($m % 10080 === 0) return rem_fa($m / 10080) . ' هفته قبل';
    if ($m % 1440 === 0) return rem_fa($m / 1440) . ' روز قبل';
    if ($m % 60 === 0) return rem_fa($m / 60) . ' ساعت قبل';
    return rem_fa($m) . ' دقیقه قبل';
}
function rem_leads_arr($s)
{
    $a = array_values(array_unique(array_map('intval', array_filter(explode(',', (string)$s), fn($x) => $x !== ''))));
    $a = array_values(array_filter($a, fn($x) => $x >= 0 && $x <= 60 * 24 * 60));
    if (!in_array(0, $a, true)) $a[] = 0;
    rsort($a);
    return array_slice($a, 0, 5);
}

// =============================================================================
// ثبت هوشمند: تشخیص و استخراج از یک جمله فارسی
// =============================================================================
/** آیا پیام گفتگو درخواست یادآور است؟ 2 = قطعی، 1 = احتمالی (تصمیم با هوش مصنوعی)، 0 = نه */
function rem_intent($text)
{
    $t = rem_en(mb_strtolower(trim((string)$text)));
    if ($t === '' || mb_strlen($t) > 500) return 0;
    if (preg_match('/(یادم\s*(بنداز|بندازید|بیار|بیاور|باشه|نره|رفت)|یاد\s*آوری\s*(کن|بکن|کنید|بذار|بگذار|ثبت)|یادآوری\s*(کن|بکن|کنید|بذار|بگذار)|یادآور\s*(بذار|بگذار|ثبت|تنظیم|بساز)|یادت\s*باشه|خبرم\s*کن|بهم\s*(یادآوری|خبر\s*بده)|تو\s*تقویم|در\s*تقویم|ثبت\s*کن\s*که|remind\s*me|set\s*a\s*reminder)/u', $t)) return 2;
    $when = preg_match('/(ساعت\s*\d|\d{1,2}:\d{2}|امروز|فردا|پس\s*‌?فردا|شنبه|یکشنبه|دوشنبه|سه\s*‌?شنبه|چهارشنبه|پنج\s*‌?شنبه|جمعه|هفته\s*(بعد|آینده)|\d+\s*(فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند))/u', $t);
    $what = preg_match('/(جلسه|میتینگ|قرار\s*(ملاقات|دارم|داریم)|ملاقات|نوبت\s*(دکتر|دندان|پزشک)?|قسط|سررسید|چک\s|قبض|تولد|سالگرد|دارو|قرص|وقت\s*(دکتر|دندانپزشکی))/u', $t);
    return $when && $what ? 1 : 0;
}

/** تحلیل قاعده‌ای (بدون هوش مصنوعی) — پشتیبان و تکمیل‌کننده */
function rem_rule_parse($text, $now_ts = null)
{
    $now_ts = $now_ts ?: time();
    $t = rem_en(trim((string)$text));
    $t = str_replace(["\u{200C}", '‌'], ' ', $t);
    $today = substr(rem_fmt($now_ts), 0, 10);
    $out = ['date' => '', 'time' => '', 'repeat' => '', 'before' => [], 'sms' => false, 'category' => '', 'person' => '', 'explicit_date' => false, 'ampm' => null];
    $add = function ($days) use ($today) { return gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') + $days * 86400); };
    // تاریخ
    if (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})/', $t, $m)) {
        $g = rem_jparse($m[0]);
        if ($g !== '') { $out['date'] = $g; $out['explicit_date'] = true; }
    }
    if ($out['date'] === '' && preg_match('/(\d{1,2})\s*(?:ام|م)?\s*(فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند)(?:\s*(?:ماه)?\s*(\d{4}))?/u', $t, $m)) {
        $jm = array_search($m[2], rem_jmonths(), true);
        [$gy, $gm, $gd] = array_map('intval', explode('-', $today));
        [$cy] = rem_g2j($gy, $gm, $gd);
        $jy = !empty($m[3]) ? (int)$m[3] : $cy;
        $jd = min((int)$m[1], rem_jlen($jy, $jm));
        $g = rem_jparse(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
        if ($g !== '' && empty($m[3]) && $g < $today) $g = rem_jparse(sprintf('%04d/%02d/%02d', $jy + 1, $jm, min((int)$m[1], rem_jlen($jy + 1, $jm))));
        if ($g !== '') { $out['date'] = $g; $out['explicit_date'] = true; }
    }
    if ($out['date'] === '') {
        if (preg_match('/پس\s*فردا/u', $t)) $out['date'] = $add(2);
        elseif (preg_match('/فردا/u', $t)) $out['date'] = $add(1);
        elseif (preg_match('/امروز|امشب|امروزعصر/u', $t)) $out['date'] = $today;
        elseif (preg_match('/(\d+)\s*روز\s*(دیگه|دیگر|بعد)/u', $t, $m)) $out['date'] = $add((int)$m[1]);
        elseif (preg_match('/(\d+)\s*هفته\s*(دیگه|دیگر|بعد)/u', $t, $m)) $out['date'] = $add(7 * (int)$m[1]);
        elseif (preg_match('/(?<![\p{L}])(یکشنبه|دوشنبه|سه\s*شنبه|چهارشنبه|پنج\s*شنبه|پنجشنبه|جمعه|شنبه)(?:\s*(بعد|آینده|دیگه|هفته\s*بعد))?/u', $t, $m)) {
            $name = preg_replace('/\s+/u', '', $m[1]);
            $map = ['یکشنبه' => 0, 'دوشنبه' => 1, 'سهشنبه' => 2, 'چهارشنبه' => 3, 'پنجشنبه' => 4, 'جمعه' => 5, 'شنبه' => 6];
            $target = $map[$name] ?? null;
            if ($target !== null) {
                $w = (int)(new \DateTime($today, rem_tz()))->format('w');
                $diff = ($target - $w + 7) % 7;
                if ($diff === 0) $diff = 7;
                if (!empty($m[2]) && preg_match('/هفته/u', $m[2])) $diff += 7;
                $out['date'] = $add($diff);
            }
        }
        elseif (preg_match('/هفته\s*(بعد|آینده|دیگه)/u', $t)) $out['date'] = $add(7);
    }
    // ساعت
    $h = null; $mi = 0;
    if (preg_match('/(\d{1,2}):(\d{2})/', $t, $m)) { $h = (int)$m[1]; $mi = (int)$m[2]; }
    elseif (preg_match('/ساعت\s*(\d{1,2})(?:\s*و\s*(\d{1,2})\s*دقیقه|\s*و\s*(نیم|ربع)|\s*(\.)\s*(\d{1,2}))?/u', $t, $m)) {
        $h = (int)$m[1];
        if (!empty($m[2])) $mi = (int)$m[2];
        elseif (($m[3] ?? '') === 'نیم') $mi = 30;
        elseif (($m[3] ?? '') === 'ربع') $mi = 15;
        elseif (!empty($m[5])) $mi = (int)$m[5];
    } elseif (preg_match('/(\d{1,2})\s*(صبح|عصر|شب|بعد\s*از\s*ظهر|بعدازظهر|ظهر)/u', $t, $m)) { $h = (int)$m[1]; }
    elseif (preg_match('/(صبح|عصر|شب|بعد\s*از\s*ظهر|بعدازظهر|ظهر|غروب)\s*(?:ساعت\s*)?(\d{1,2})(?:[:\.](\d{2}))?(?!\s*(روز|هفته|ماه|دقیقه))/u', $t, $m)) { $h = (int)$m[2]; $mi = (int)($m[3] ?? 0); }
    if ($h !== null && $h <= 24 && $mi < 60) {
        $pm = preg_match('/(عصر|شب|بعد\s*از\s*ظهر|بعدازظهر|غروب)/u', $t);
        $am = preg_match('/(صبح|بامداد|صبحانه)/u', $t);
        $h0 = $h;   // ساعت همان‌طور که نوشته شده (برای تشخیص ابهام صبح/عصر)
        if ($pm && $h < 12) $h += 12;
        elseif (!$am && !$pm && $h >= 1 && $h <= 6) $h += 12;   // «ساعت ۵» در کارهای روزمره = ۵ بعدازظهر
        $noon = preg_match('/ظهر/u', $t);
        // صبح/عصر مشخص نیست (مثل «ساعت ۵» یا «۸:۳۰»): از کاربر پرسیده می‌شود
        if (!$am && !$pm && !$noon && $h0 >= 1 && $h0 <= 11 && !preg_match('/(^|[^\d])0\d:\d{2}/', $t)) $out['ampm'] = ['h' => $h0, 'mi' => $mi];
        if ($noon && !$pm && $h < 5) $h += 12;
        if ($h === 24) $h = 0;
        $out['time'] = sprintf('%02d:%02d', $h, $mi);
    }
    // تکرار
    if (preg_match('/(هر\s*روز|روزانه)/u', $t)) $out['repeat'] = 'daily';
    elseif (preg_match('/(هر\s*هفته|هفتگی|هر\s*(یکشنبه|دوشنبه|سه\s*شنبه|چهارشنبه|پنج\s*شنبه|پنجشنبه|جمعه|شنبه))/u', $t)) $out['repeat'] = 'weekly';
    elseif (preg_match('/(هر\s*ماه|ماهانه|ماهیانه)/u', $t)) $out['repeat'] = 'monthly';
    elseif (preg_match('/(هر\s*سال|سالانه|سالیانه)/u', $t)) $out['repeat'] = 'yearly';
    // چند وقت قبل
    if (preg_match_all('/(\d+|یک|یه|دو|نیم)\s*(دقیقه|ساعت|روز|هفته)\s*(قبل|زودتر|پیش|جلوتر)/u', $t, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $n = ['یک' => 1, 'یه' => 1, 'دو' => 2, 'نیم' => 0.5][$m[1]] ?? (int)$m[1];
            $out['before'][] = (int)round($n * ['دقیقه' => 1, 'ساعت' => 60, 'روز' => 1440, 'هفته' => 10080][$m[2]]);
        }
    }
    if (preg_match('/(پیامک|اس\s*ام\s*اس|sms)/iu', $t)) $out['sms'] = true;
    // دسته
    foreach (['meeting' => 'جلسه|میتینگ|ملاقات|قرار', 'call' => 'تماس|زنگ\s*بزن|زنگ\s*زدن', 'payment' => 'قسط|پرداخت|قبض|اجاره|چک|سررسید|واریز', 'birthday' => 'تولد|سالگرد|سالروز', 'medicine' => 'دارو|قرص|آمپول|دکتر|پزشک|دندان'] as $k => $re) {
        if (preg_match('/(' . $re . ')/u', $t)) { $out['category'] = $k; break; }
    }
    if (preg_match('/(?:با|به)\s+((?:آقای|خانم|دکتر|مهندس|استاد|حاج\s*آقا)\s*([\p{L}‌]+)(?:\s+([\p{L}‌]+))?)/u', $t, $m)) {
        $stop = ['یک', 'یه', 'ساعت', 'روز', 'را', 'رو', 'و', 'در', 'برای', 'که', 'فردا', 'امروز', 'جلسه', 'دارم', 'داریم', 'بزن', 'بگیر', 'یادم', 'قبل', 'بعد', 'هم', 'تماس', 'زنگ', 'درباره', 'راجع'];
        $p = trim(preg_replace('/\s+/u', ' ', $m[1]));
        if (!empty($m[3]) && in_array($m[3], $stop, true)) $p = trim(mb_substr($p, 0, mb_strlen($p) - mb_strlen($m[3])));
        $out['person'] = $p;
    }
    return $out;
}

/**
 * ثبت هوشمند: جمله فارسی → اقلام یادآور (عنوان، دسته، تاریخ، ساعت، فرد، مکان، تکرار، یادآوری پیش از موعد)
 * @return array ['ok','is_reminder','title','category','date'(Y-m-d),'jdate','time','all_day','person','place','repeat','leads'(array),'note','sms','error','tokens','toman','model']
 */
function rem_parse($pdo, $text, array $opt = [])
{
    $text = trim(mb_substr((string)$text, 0, 500));
    $now_ts = (int)($opt['now'] ?? time());
    $rule = rem_rule_parse($text, $now_ts);
    $res = ['ok' => false, 'is_reminder' => true, 'title' => '', 'category' => $rule['category'] ?: 'task', 'date' => $rule['date'], 'time' => $rule['time'],
            'all_day' => false, 'person' => $rule['person'], 'place' => '', 'repeat' => $rule['repeat'] ?: 'none', 'leads' => $rule['before'], 'note' => '',
            'sms' => $rule['sms'], 'error' => '', 'tokens' => 0, 'toman' => 0, 'model' => null, 'ai' => false];
    if ($text === '') { $res['error'] = 'متن یادآور را بنویسید.'; return $res; }
    // تقویم ۲۱ روز آینده برای مدل
    $today = substr(rem_fmt($now_ts), 0, 10);
    $cal = [];
    for ($i = 0; $i < 21; $i++) {
        $g = gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') + $i * 86400);
        $w = rem_wdays()[(int)(new \DateTime($g, rem_tz()))->format('w')];
        $cal[] = rem_jdate($g) . ' ' . $w . ($i === 0 ? ' (امروز)' : ($i === 1 ? ' (فردا)' : ''));
    }
    $sys = "You turn a Persian message into a reminder item. Now (Iran time): " . rem_jdate($today) . ' ' . substr(rem_fmt($now_ts), 11, 5) . ".\nJalali calendar of the next 21 days:\n" . implode("\n", $cal)
         . "\nReply with ONLY a JSON object: {\"is_reminder\": true|false, \"title\": \"short Persian title, e.g. جلسه با آقای احمدی\", \"category\": \"meeting|call|task|payment|birthday|medicine|personal|other\", "
         . "\"jdate\": \"YYYY/MM/DD Jalali or empty\", \"time\": \"HH:MM 24h or empty\", \"person\": \"\", \"place\": \"\", \"repeat\": \"none|daily|weekly|monthly|yearly\", \"before_minutes\": [numbers], \"note\": \"other useful details in Persian or empty\"}\n"
         . "Rules: resolve relative dates (امروز، فردا، پس‌فردا، weekday names = the next such day in the calendar, «۳ روز دیگه», «هفته بعد») using the calendar. For a Jalali date like «۱۵ آبان» use the current Jalali year unless it has passed. "
         . "عصر/بعدازظهر/شب = PM; صبح = AM; ظهر = 12:00; «ساعت ۵» without صبح means 17:00. before_minutes only if the user asks to be reminded earlier (e.g. «یک ساعت قبل» = 60, «یک روز قبل» = 1440). "
         . "is_reminder is false only if the message is clearly a question or not something to be reminded of. Do not invent dates.";
    try {
        if (!function_exists('aichat_call_ai') && is_file(__DIR__ . '/ai_chat_lib.php')) require_once __DIR__ . '/ai_chat_lib.php';
        $cfg = saas_get_api_config($pdo);
        $model = function_exists('gen_tr_model') ? gen_tr_model($pdo) : null;
        $msgs = [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $text]];
        $r = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, 400, 0.2) : aichat_call_ai($cfg, $msgs, 400, 0.2);
        if (!empty($r['ok'])) {
            $used = $r['model_row'] ?? $model;
            $res['model'] = $used;
            $res['tokens'] = (int)($r['tokens'] ?? 0);
            $res['toman'] = function_exists('biz_charge_tokens') ? (int)biz_charge_tokens($pdo, $used, $r) : 0;
            $c = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string)($r['content'] ?? '')));
            if (preg_match('/\{.*\}/s', $c, $mm)) $c = $mm[0];
            $j = json_decode($c, true);
            if (is_array($j)) {
                $res['ai'] = true;
                $res['is_reminder'] = !isset($j['is_reminder']) || (bool)$j['is_reminder'];
                if (trim((string)($j['title'] ?? '')) !== '') $res['title'] = trim(mb_substr((string)$j['title'], 0, 150));
                if (isset(rem_cats()[$j['category'] ?? ''])) $res['category'] = $j['category'];
                $g = rem_jparse((string)($j['jdate'] ?? ''));
                if ($g !== '' && !$rule['explicit_date']) $res['date'] = $g;
                elseif ($g !== '' && $res['date'] === '') $res['date'] = $g;
                if (preg_match('/^(\d{1,2}):(\d{2})$/', rem_en((string)($j['time'] ?? '')), $tm) && (int)$tm[1] < 24 && (int)$tm[2] < 60) $res['time'] = sprintf('%02d:%02d', $tm[1], $tm[2]);
                foreach (['person', 'place', 'note'] as $k) if (trim((string)($j[$k] ?? '')) !== '') $res[$k] = trim(mb_substr((string)$j[$k], 0, $k === 'note' ? 400 : 150));
                if (isset(rem_repeat_opts()[$j['repeat'] ?? '']) && ($j['repeat'] !== 'none' || $rule['repeat'] === '')) $res['repeat'] = $j['repeat'];
                if (is_array($j['before_minutes'] ?? null)) foreach ($j['before_minutes'] as $b) if ((int)$b > 0) $res['leads'][] = (int)$b;
            }
        }
    } catch (\Throwable $e) { error_log('[REM] parse: ' . $e->getMessage()); }
    if ($res['title'] === '') {
        $tt = preg_replace('/(یادم\s*(بنداز|بندازید|بیار|باشه)|یاد\s*آوری\s*(کن|بکن|کنید)|یادآوری\s*(کن|بکن|کنید|بذار|بگذار)|یادآور\s*(بذار|بگذار|ثبت)|یادت\s*باشه|لطفا|لطفاً|که|خبرم\s*کن)/u', ' ', $text);
        $wd = 'یکشنبه|دوشنبه|سه\s*‌?شنبه|چهارشنبه|پنج\s*‌?شنبه|پنجشنبه|جمعه|شنبه';
        $mn = 'فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند';
        $tt = preg_replace([
            '/ساعت\s*[\d۰-۹]{1,2}([:\.][\d۰-۹]{2})?(\s*و\s*(نیم|ربع|[\d۰-۹]{1,2}\s*دقیقه))?(\s*(صبح|عصر|شب|ظهر|بعد\s*از\s*ظهر|بعدازظهر))?/u',
            '/(هر\s*(روز|هفته|ماه|سال|' . $wd . ')|روزانه|ماهانه|ماهیانه|سالانه)/u',
            '/[\d۰-۹]{1,2}\s*(ام|م)?\s*(' . $mn . ')(\s*(ماه)?\s*[\d۰-۹]{4})?/u',
            '/[\d۰-۹]{4}\s*\/\s*[\d۰-۹]{1,2}\s*\/\s*[\d۰-۹]{1,2}/u',
            '/((یک|یه|دو|نیم|[\d۰-۹]+)\s*(دقیقه|ساعت|روز|هفته)\s*(قبل|زودتر|پیش|جلوتر)(\s*(هم|یادم\s*بنداز|خبرم\s*کن|بگو))*)/u',
            '/((صبح|عصر|شب|ظهر|غروب|بعد\s*از\s*ظهر|بعدازظهر)\s*(ساعت\s*)?[\d۰-۹]{1,2}([:\.][\d۰-۹]{2})?|[\d۰-۹]{1,2}([:\.][\d۰-۹]{2})?\s*(صبح|عصر|شب|ظهر|بعد\s*از\s*ظهر))/u',
            '/(امروز|امشب|فردا|پس\s*‌?فردا|ساعت\s*[\d۰-۹:\.]+(\s*و\s*(نیم|ربع))?|(صبح|عصر|شب|ظهر)|([\d۰-۹]+)\s*(روز|هفته)\s*(دیگه|دیگر|بعد)|هفته\s*(بعد|آینده))/u',
            '/(?<![\p{L}])(' . $wd . ')(?![\p{L}])(\s*(بعد|آینده))?/u',
            '/(پیامک|اس\s*ام\s*اس)\s*(هم)?\s*(بفرست|بزن|بده)?/u',
        ], ' ', $tt);
        $res['title'] = preg_replace('/^[\s،,.]+|[\s،,.]+$/u', '', mb_substr(preg_replace('/\s+/u', ' ', $tt), 0, 120));
        if ($res['title'] === '') $res['title'] = mb_substr($text, 0, 120);
    }
    $date_auto = $res['date'] === '';
    $auto_date = function ($tm) use ($today, $now_ts) { return rem_ts($today . ' ' . $tm . ':00') > $now_ts ? $today : gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') + 86400); };
    if ($res['date'] === '' && $res['time'] !== '') {
        // فقط ساعت گفته شده: امروز اگر هنوز نگذشته، وگرنه فردا
        $res['date'] = $auto_date($res['time']);
    }
    // صبح یا عصر؟ (فقط وقتی در متن مشخص نشده)
    $res['ampm'] = null;
    if (!empty($rule['ampm']) && $res['date'] !== '') {
        $h0 = (int)$rule['ampm']['h']; $mi0 = (int)$rule['ampm']['mi'];
        $ta = sprintf('%02d:%02d', $h0, $mi0); $tp = sprintf('%02d:%02d', $h0 + 12, $mi0);
        $lbl = function ($h, $mi) { return rem_fa($h) . ($mi ? ':' . rem_fa(sprintf('%02d', $mi)) : ''); };
        $pm_word = $h0 <= 5 ? 'بعدازظهر' : ($h0 <= 7 ? 'عصر' : 'شب');
        $opts = [];
        foreach ([[$ta, 'صبح'], [$tp, $pm_word]] as [$tm, $w]) {
            $d = $date_auto ? $auto_date($tm) : $res['date'];
            $opts[] = ['time' => $tm, 'label' => 'ساعت ' . $lbl($h0, $mi0) . ' ' . $w, 'jdate' => rem_jdate($d), 'date' => $d, 'when' => rem_human($d . ' ' . $tm . ':00', false)];
        }
        $res['ampm'] = ['q' => 'منظورتان ساعت ' . $lbl($h0, $mi0) . ' صبح است یا ' . $pm_word . '؟', 'opts' => $opts];
    }
    if ($res['time'] === '') { $res['all_day'] = true; $res['time'] = '09:00'; }
    if ($res['date'] === '') { $res['error'] = 'زمان یادآور مشخص نیست؛ بگویید چه روزی و چه ساعتی (مثلاً «فردا ساعت ۱۰»).'; return $res; }
    // یادآوری پیش‌فرض بر اساس دسته
    if (!$res['leads']) {
        if (in_array($res['category'], ['meeting', 'call'], true) && !$res['all_day']) $res['leads'] = [30];
        elseif (in_array($res['category'], ['payment', 'birthday'], true)) $res['leads'] = [1440];
    }
    $res['leads'] = rem_leads_arr(implode(',', $res['leads']));
    if ($res['category'] === 'birthday' && $res['repeat'] === 'none' && preg_match('/تولد|سالگرد/u', $text)) $res['repeat'] = 'yearly';
    $res['jdate'] = rem_jdate($res['date']);
    $res['ok'] = true;
    return $res;
}

// =============================================================================
// نقش‌ها (کسی که یادآور را می‌سازد/می‌بیند) و گیرنده‌ها
// =============================================================================
function rem_ctx_admin($pdo)
{
    $mobs = function_exists('comm_admin_mobiles') ? comm_admin_mobiles($pdo) : [];
    return ['kind' => 'admin', 'id' => 0, 'owner_uid' => 0, 'bot_id' => 0, 'name' => 'مدیر سامانه', 'mobile' => $mobs[0] ?? '', 'perms' => ['*']];
}

function rem_ctx_user($user, $team = null)
{
    if ($team) {
        $perms = $team['perm_list'] ?? (is_array($team['perms'] ?? null) ? $team['perms'] : array_values(array_filter(array_map('trim', explode(',', (string)($team['perms'] ?? ''))))));
        return ['kind' => 'team', 'id' => (int)$team['id'], 'owner_uid' => (int)$user['id'], 'bot_id' => 0, 'name' => (string)$team['name'], 'mobile' => (string)($team['mobile'] ?? ''), 'perms' => (array)$perms];
    }
    return ['kind' => 'user', 'id' => (int)$user['id'], 'owner_uid' => (int)$user['id'], 'bot_id' => 0, 'name' => (string)($user['full_name'] ?? ''), 'mobile' => (string)($user['phone'] ?? ''), 'perms' => ['*']];
}

function rem_ctx_member($bot, $member)
{
    return ['kind' => 'member', 'id' => (int)$member['id'], 'owner_uid' => (int)$bot['user_id'], 'bot_id' => (int)$bot['id'], 'name' => (string)($member['name'] ?? ''), 'mobile' => (string)($member['mobile'] ?? ''), 'perms' => []];
}

function rem_kind_label($k) { return ['admin' => 'مدیر', 'user' => 'کاربر', 'team' => 'همکار', 'member' => 'مخاطب'][$k] ?? $k; }

/** مشخصات یک گیرنده */
function rem_recipient($pdo, $kind, $id)
{
    try {
        if ($kind === 'admin') { $m = function_exists('comm_admin_mobiles') ? comm_admin_mobiles($pdo) : []; return ['kind' => 'admin', 'id' => 0, 'name' => 'مدیر سامانه', 'mobile' => $m[0] ?? '', 'owner_uid' => 0, 'bot_id' => 0]; }
        if ($kind === 'user') {
            $s = $pdo->prepare("SELECT id, full_name, phone, status FROM saas_users WHERE id=?"); $s->execute([(int)$id]); $r = $s->fetch(\PDO::FETCH_ASSOC);
            return $r ? ['kind' => 'user', 'id' => (int)$r['id'], 'name' => (string)$r['full_name'], 'mobile' => (string)$r['phone'], 'owner_uid' => (int)$r['id'], 'bot_id' => 0] : null;
        }
        if ($kind === 'team') {
            $s = $pdo->prepare("SELECT id, owner_id, name, mobile FROM saas_team WHERE id=?"); $s->execute([(int)$id]); $r = $s->fetch(\PDO::FETCH_ASSOC);
            return $r ? ['kind' => 'team', 'id' => (int)$r['id'], 'name' => (string)$r['name'], 'mobile' => (string)$r['mobile'], 'owner_uid' => (int)$r['owner_id'], 'bot_id' => 0] : null;
        }
        if ($kind === 'member') {
            $s = $pdo->prepare("SELECT m.id, m.name, m.mobile, m.bot_id, b.user_id, b.name AS bot_name FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE m.id=?"); $s->execute([(int)$id]); $r = $s->fetch(\PDO::FETCH_ASSOC);
            return $r ? ['kind' => 'member', 'id' => (int)$r['id'], 'name' => (string)$r['name'], 'mobile' => (string)$r['mobile'], 'owner_uid' => (int)$r['user_id'], 'bot_id' => (int)$r['bot_id'], 'bot_name' => (string)$r['bot_name']] : null;
        }
    } catch (\Throwable $e) {}
    return null;
}

/** گیرنده مجاز برای این نقش؟ کلید: self | user:ID | team:ID | member:ID */
function rem_target_resolve($pdo, array $ctx, $key)
{
    $key = trim((string)$key);
    if ($key === '' || $key === 'self') return rem_recipient($pdo, $ctx['kind'], $ctx['id']);
    if (!preg_match('/^(user|team|member):(\d+)$/', $key, $m)) return null;
    $rc = rem_recipient($pdo, $m[1], (int)$m[2]);
    if (!$rc) return null;
    switch ($ctx['kind']) {
        case 'admin': return in_array($rc['kind'], ['user', 'team'], true) ? $rc : null;
        case 'user':  return in_array($rc['kind'], ['team', 'member'], true) && $rc['owner_uid'] === (int)$ctx['owner_uid'] ? $rc : null;
        case 'team':
            $p = (array)($ctx['perms'] ?? []);
            return $rc['kind'] === 'member' && $rc['owner_uid'] === (int)$ctx['owner_uid'] && (in_array('bots_view', $p, true) || in_array('bots_manage', $p, true) || in_array('consult', $p, true)) ? $rc : null;
    }
    return null;
}

/** فهرست گیرنده‌های انتخابی برای فرم (بدون مخاطبان — مخاطبان با جستجو) */
function rem_targets($pdo, array $ctx)
{
    $out = [['key' => 'self', 'label' => '👤 خودم']];
    try {
        if ($ctx['kind'] === 'admin') {
            foreach ($pdo->query("SELECT id, full_name, phone FROM saas_users WHERE status='active' ORDER BY full_name LIMIT 500")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $u)
                $out[] = ['key' => 'user:' . $u['id'], 'label' => '🧑‍💻 ' . ($u['full_name'] ?: 'کاربر #' . $u['id']) . ($u['phone'] ? ' — ' . $u['phone'] : ''), 'g' => 'کاربران'];
            foreach ($pdo->query("SELECT t.id, t.name, t.mobile, u.full_name AS owner FROM saas_team t JOIN saas_users u ON u.id=t.owner_id WHERE t.is_active=1 ORDER BY u.full_name, t.name LIMIT 500")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $t)
                $out[] = ['key' => 'team:' . $t['id'], 'label' => '🤝 ' . $t['name'] . ' (همکار ' . $t['owner'] . ')', 'g' => 'همکاران کاربران'];
        } elseif ($ctx['kind'] === 'user') {
            $s = $pdo->prepare("SELECT id, name, title FROM saas_team WHERE owner_id=? AND is_active=1 ORDER BY name");
            $s->execute([(int)$ctx['owner_uid']]);
            foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $t) $out[] = ['key' => 'team:' . $t['id'], 'label' => '🤝 ' . $t['name'] . ($t['title'] ? ' — ' . $t['title'] : ''), 'g' => 'همکاران'];
        }
    } catch (\Throwable $e) {}
    return $out;
}

/** جستجوی مخاطبان چت‌بات‌های صاحب حساب (برای انتخاب گیرنده) */
function rem_member_search($pdo, array $ctx, $q)
{
    if (!in_array($ctx['kind'], ['user', 'team'], true) || !rem_target_resolve_ok_members($ctx)) return [];
    $q = trim(rem_en((string)$q));
    try {
        $sql = "SELECT m.id, m.name, m.mobile, b.name AS bot FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE b.user_id=? AND m.is_owner=0";
        $p = [(int)$ctx['owner_uid']];
        if ($q !== '') { $sql .= " AND (m.name LIKE ? OR m.mobile LIKE ?)"; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; }
        $s = $pdo->prepare($sql . " ORDER BY m.id DESC LIMIT 20");
        $s->execute($p);
        return array_map(fn($r) => ['key' => 'member:' . $r['id'], 'label' => '💬 ' . ($r['name'] ?: 'مخاطب') . ' — ' . $r['mobile'] . ' (' . $r['bot'] . ')'], $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { return []; }
}
function rem_target_resolve_ok_members(array $ctx)
{
    if ($ctx['kind'] === 'user') return true;
    $p = (array)($ctx['perms'] ?? []);
    return in_array('bots_view', $p, true) || in_array('bots_manage', $p, true) || in_array('consult', $p, true);
}

// =============================================================================
// ثبت، ویرایش، فهرست و عملیات
// =============================================================================
function rem_get($pdo, $id)
{
    rem_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM saas_reminders WHERE id=?");
    $s->execute([(int)$id]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** سطح دسترسی: 'edit' (سازنده یا صاحب حساب)، 'view' (گیرنده)، '' */
function rem_access(array $ctx, array $r)
{
    $mine = $r['creator_kind'] === $ctx['kind'] && (int)$r['creator_id'] === (int)$ctx['id'];
    if ($mine) return 'edit';
    if ($ctx['kind'] === 'user' && in_array($r['creator_kind'], ['user', 'team'], true) && (int)$r['owner_uid'] === (int)$ctx['owner_uid']) return 'edit';
    if ($r['target_kind'] === $ctx['kind'] && (int)$r['target_id'] === (int)$ctx['id']) return 'view';
    return '';
}

/** نوبت بعدی یک رویداد تکرارشونده (ماه و سال شمسی) */
function rem_next_occurrence($due, $kind)
{
    $ts = rem_ts($due);
    $time = substr((string)$due, 11, 8) ?: '09:00:00';
    if ($kind === 'daily' || $kind === 'weekly') {
        $d = new \DateTime((string)$due, rem_tz());
        $d->modify($kind === 'daily' ? '+1 day' : '+7 day');
        return $d->format('Y-m-d H:i:s');
    }
    [$gy, $gm, $gd] = array_map('intval', explode('-', substr((string)$due, 0, 10)));
    [$jy, $jm, $jd] = rem_g2j($gy, $gm, $gd);
    if ($kind === 'monthly') { $jm++; if ($jm > 12) { $jm = 1; $jy++; } }
    else $jy++;
    $jd2 = min($jd, rem_jlen($jy, $jm));
    [$ny, $nm, $nd] = rem_j2g($jy, $jm, $jd2);
    return sprintf('%04d-%02d-%02d %s', $ny, $nm, $nd, $time);
}

/** زمان اجرای بعدی (بر اساس یادآوری‌های پیش از موعدِ ارسال‌نشده و تعویق) */
function rem_compute_next(array $r)
{
    if ($r['status'] !== 'active') return null;
    $c = [];
    if (!empty($r['snooze_at'])) $c[] = rem_ts($r['snooze_at']);
    $due = rem_ts($r['due_at']);
    $fired = array_map('intval', array_filter(explode(',', (string)$r['fired']), fn($x) => $x !== ''));
    foreach (rem_leads_arr($r['leads']) as $l) if (!in_array($l, $fired, true)) $c[] = $due - $l * 60;
    return $c ? rem_fmt(min($c)) : null;
}

/** یادآوری‌های پیش از موعدی که زمانشان گذشته (هنگام ثبت) ارسال نمی‌شوند */
function rem_skip_past_leads(array $r, $now_ts)
{
    $due = rem_ts($r['due_at']);
    $f = [];
    foreach (rem_leads_arr($r['leads']) as $l) if ($l > 0 && $due - $l * 60 < $now_ts - 30) $f[] = $l;
    return implode(',', $f);
}

/**
 * ثبت یا ویرایش یادآور
 * $in: title, category, date (Y-m-d) یا jdate، time (HH:MM)، all_day، leads (آرایه/رشته)، repeat، repeat_until (شمسی)، target،
 *      ch_site، ch_push، ch_sms، priority، person، place، note، source، raw_text
 */
function rem_save($pdo, array $ctx, array $in, $id = 0)
{
    rem_ensure_schema($pdo);
    $now_ts = time();
    $old = null;
    if ($id) {
        $old = rem_get($pdo, $id);
        if (!$old || rem_access($ctx, $old) !== 'edit') return ['ok' => false, 'error' => 'یادآور پیدا نشد یا اجازه ویرایش ندارید.'];
    }
    $title = trim(mb_substr(strip_tags((string)($in['title'] ?? '')), 0, 200));
    if (mb_strlen($title) < 2) return ['ok' => false, 'error' => 'عنوان یادآور را بنویسید.'];
    $date = trim((string)($in['date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = rem_jparse((string)($in['jdate'] ?? ''));
    if ($date === '') return ['ok' => false, 'error' => 'تاریخ معتبر نیست (نمونه: ۱۴۰۵/۰۷/۱۵).'];
    $all_day = !empty($in['all_day']);
    $time = rem_en(trim((string)($in['time'] ?? '')));
    if ($all_day || !preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) { if (!$all_day && $time !== '') return ['ok' => false, 'error' => 'ساعت معتبر نیست (نمونه: ۱۰:۳۰).']; $all_day = true; $time = '09:00'; }
    else $time = sprintf('%02d:%02d', $tm[1], $tm[2]);
    $due = $date . ' ' . $time . ':00';
    $repeat = isset(rem_repeat_opts()[$in['repeat'] ?? '']) ? $in['repeat'] : 'none';
    $until = null;
    if ($repeat !== 'none' && trim((string)($in['repeat_until'] ?? '')) !== '') {
        $u = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['repeat_until']) ? (string)$in['repeat_until'] : rem_jparse((string)$in['repeat_until']);
        if ($u !== '') $until = $u . ' 23:59:59';
    }
    // زمان گذشته: رویداد تکرارشونده به نوبت بعدی می‌رود؛ غیرتکراری پذیرفته نمی‌شود
    $guard = 0;
    while (rem_ts($due) < $now_ts - 60 && $repeat !== 'none' && $guard++ < 1000) $due = rem_next_occurrence($due, $repeat);
    if (rem_ts($due) < $now_ts - 60 && (!$old || $old['due_at'] !== $due)) return ['ok' => false, 'error' => 'زمان انتخاب‌شده گذشته است؛ زمانی در آینده انتخاب کنید.'];
    $leads = is_array($in['leads'] ?? null) ? implode(',', $in['leads']) : (string)($in['leads'] ?? '0');
    $leads = implode(',', rem_leads_arr($leads));
    $cat = isset(rem_cats()[$in['category'] ?? '']) ? $in['category'] : 'task';
    $rc = $old && !isset($in['target']) ? rem_recipient($pdo, $old['target_kind'], $old['target_id']) : rem_target_resolve($pdo, $ctx, $in['target'] ?? 'self');
    if (!$rc) return ['ok' => false, 'error' => 'گیرنده یادآور معتبر نیست.'];
    $ch = ['site' => !empty($in['ch_site']) ? 1 : 0, 'push' => !empty($in['ch_push']) ? 1 : 0, 'sms' => !empty($in['ch_sms']) ? 1 : 0];
    if (!$ch['site'] && !$ch['push'] && !$ch['sms']) $ch['site'] = 1;
    if ($ch['sms'] && trim((string)$rc['mobile']) === '') return ['ok' => false, 'error' => 'برای گیرنده شماره موبایل ثبت نشده؛ پیامک ممکن نیست.'];
    // سقف‌ها (ارسال گروهی و جلسات مشاوره سقف جداگانه دارند: msg_lib)
    if (!$id && empty($in['_nocap'])) {
        $lim = $ctx['kind'] === 'member' ? [100, 40] : [1000, 300];
        $c = $pdo->prepare("SELECT COUNT(*) FROM saas_reminders WHERE creator_kind=? AND creator_id=? AND status='active'");
        $c->execute([$ctx['kind'], (int)$ctx['id']]);
        if ((int)$c->fetchColumn() >= $lim[0]) return ['ok' => false, 'error' => 'تعداد یادآورهای فعال شما به سقف رسیده است؛ موارد قدیمی را حذف یا «انجام شد» کنید.'];
        $c = $pdo->prepare("SELECT COUNT(*) FROM saas_reminders WHERE creator_kind=? AND creator_id=? AND created_at >= ?");
        $c->execute([$ctx['kind'], (int)$ctx['id'], rem_now(-86400)]);
        if ((int)$c->fetchColumn() >= $lim[1]) return ['ok' => false, 'error' => 'تعداد یادآورهای ثبت‌شده امروز زیاد است؛ فردا دوباره تلاش کنید.'];
    }
    $row = [
        'title' => $title, 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 2000), 'category' => $cat,
        'person' => mb_substr(trim(strip_tags((string)($in['person'] ?? ''))), 0, 150), 'place' => mb_substr(trim(strip_tags((string)($in['place'] ?? ''))), 0, 150),
        'due_at' => $due, 'all_day' => $all_day ? 1 : 0, 'leads' => $leads, 'repeat_kind' => $repeat, 'repeat_until' => $until,
        'ch_site' => $ch['site'], 'ch_push' => $ch['push'], 'ch_sms' => $ch['sms'], 'priority' => !empty($in['priority']) ? 1 : 0,
        'target_kind' => $rc['kind'], 'target_id' => (int)$rc['id'], 'target_name' => mb_substr((string)$rc['name'], 0, 150),
        'bot_id' => (int)($rc['bot_id'] ?: $ctx['bot_id']), 'status' => 'active', 'snooze_at' => null, 'updated_at' => rem_now(),
    ];
    $row['fired'] = rem_skip_past_leads($row, $now_ts);
    $row['next_at'] = rem_compute_next($row);
    if ($id) {
        $sets = []; $vals = [];
        foreach ($row as $k => $v) { $sets[] = "$k=?"; $vals[] = $v; }
        $vals[] = (int)$id;
        $pdo->prepare("UPDATE saas_reminders SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
    } else {
        $row += ['creator_kind' => $ctx['kind'], 'creator_id' => (int)$ctx['id'], 'creator_name' => mb_substr((string)$ctx['name'], 0, 150), 'owner_uid' => (int)($ctx['owner_uid'] ?: $rc['owner_uid']),
                 'source' => in_array($in['source'] ?? '', ['form', 'smart', 'chat'], true) ? $in['source'] : 'form', 'raw_text' => mb_substr((string)($in['raw_text'] ?? ''), 0, 500), 'created_at' => rem_now()];
        $pdo->prepare("INSERT INTO saas_reminders (" . implode(',', array_keys($row)) . ") VALUES(" . implode(',', array_fill(0, count($row), '?')) . ")")->execute(array_values($row));
        $id = (int)$pdo->lastInsertId();
    }
    $r = rem_get($pdo, $id);
    $warn = '';
    if ($ch['sms'] && $ctx['kind'] === 'member' && ($q = rem_member_sms_left($pdo, (int)$ctx['bot_id'], (int)$ctx['id'])) <= 0) $warn = 'سهمیه پیامک یادآور شما تمام شده؛ فقط اعلان ارسال می‌شود (برای پیامک، بسته تهیه کنید).';
    if ($ch['push'] && !rem_push_count($pdo, $rc['kind'], (int)$rc['id'])) $warn .= ($warn ? ' ' : '') . ($rc['kind'] === $ctx['kind'] && (int)$rc['id'] === (int)$ctx['id'] ? 'اعلان روی گوشی/کامپیوتر هنوز روی هیچ دستگاهی فعال نشده؛ دکمه 🔔 (اعلان روی گوشی / این دستگاه) را بزنید و اجازه دهید.' : 'گیرنده هنوز اعلان را روی دستگاهش فعال نکرده است.');
    return ['ok' => true, 'id' => $id, 'row' => $r, 'warn' => $warn];
}

/** عملیات: done (انجام شد)، snooze (تعویق دقیقه)، cancel، delete، reopen */
function rem_act($pdo, array $ctx, $id, $act, $arg = 0)
{
    $r = rem_get($pdo, $id);
    $acc = $r ? rem_access($ctx, $r) : '';
    if (!$acc) return ['ok' => false, 'error' => 'یادآور پیدا نشد.'];
    $now = rem_now();
    if ($act === 'done') {
        if ($r['repeat_kind'] !== 'none' && $r['status'] === 'active') {
            // این نوبت انجام شد → نوبت بعدی
            $due = rem_next_occurrence($r['due_at'], $r['repeat_kind']);
            $g = 0;
            while (rem_ts($due) < time() && $g++ < 1000) $due = rem_next_occurrence($due, $r['repeat_kind']);
            if ($r['repeat_until'] && rem_ts($due) > rem_ts($r['repeat_until'])) { $pdo->prepare("UPDATE saas_reminders SET status='done', done_at=?, next_at=NULL, snooze_at=NULL, updated_at=? WHERE id=?")->execute([$now, $now, (int)$id]); return ['ok' => true, 'msg' => 'انجام شد ✔ (آخرین نوبت)']; }
            $n = $r; $n['due_at'] = $due; $n['snooze_at'] = null; $n['fired'] = '';
            $n['fired'] = rem_skip_past_leads($n, time());
            $pdo->prepare("UPDATE saas_reminders SET due_at=?, fired=?, snooze_at=NULL, next_at=?, updated_at=? WHERE id=?")->execute([$due, $n['fired'], rem_compute_next($n), $now, (int)$id]);
            return ['ok' => true, 'msg' => 'این نوبت انجام شد ✔ — نوبت بعد: ' . rem_human($due, !empty($r['all_day']))];
        }
        $pdo->prepare("UPDATE saas_reminders SET status='done', done_at=?, next_at=NULL, snooze_at=NULL, updated_at=? WHERE id=?")->execute([$now, $now, (int)$id]);
        return ['ok' => true, 'msg' => 'انجام شد ✔'];
    }
    if ($act === 'snooze') {
        $min = max(5, min(10080, (int)$arg ?: 10));
        $n = $r; $n['status'] = 'active'; $n['snooze_at'] = rem_now($min * 60);
        $pdo->prepare("UPDATE saas_reminders SET status='active', snooze_at=?, next_at=?, updated_at=? WHERE id=?")->execute([$n['snooze_at'], rem_compute_next($n), $now, (int)$id]);
        return ['ok' => true, 'msg' => '⏰ دوباره یادآوری می‌کنم: ' . rem_human($n['snooze_at'])];
    }
    if ($acc !== 'edit') return ['ok' => false, 'error' => 'فقط سازنده یادآور می‌تواند آن را تغییر دهد.'];
    if ($act === 'cancel') { $pdo->prepare("UPDATE saas_reminders SET status='cancelled', next_at=NULL, snooze_at=NULL, updated_at=? WHERE id=?")->execute([$now, (int)$id]); return ['ok' => true, 'msg' => 'لغو شد.']; }
    if ($act === 'delete') { $pdo->prepare("DELETE FROM saas_reminders WHERE id=?")->execute([(int)$id]); $pdo->prepare("DELETE FROM saas_rem_events WHERE reminder_id=?")->execute([(int)$id]); return ['ok' => true, 'msg' => 'حذف شد.']; }
    if ($act === 'reopen') {
        $n = $r; $n['status'] = 'active'; $n['snooze_at'] = null;
        if (rem_ts($n['due_at']) < time()) return ['ok' => false, 'error' => 'زمان این یادآور گذشته؛ آن را ویرایش کنید و زمان تازه بدهید.'];
        $n['fired'] = rem_skip_past_leads($n, time());
        $pdo->prepare("UPDATE saas_reminders SET status='active', fired=?, snooze_at=NULL, done_at=NULL, next_at=?, updated_at=? WHERE id=?")->execute([$n['fired'], rem_compute_next($n), $now, (int)$id]);
        return ['ok' => true, 'msg' => 'دوباره فعال شد.'];
    }
    return ['ok' => false, 'error' => 'عملیات نامعتبر است.'];
}

/** شرط SQL یادآورهای قابل‌مشاهده برای این نقش */
function rem_scope_sql(array $ctx, array &$p)
{
    switch ($ctx['kind']) {
        case 'admin':
            return "(creator_kind='admin' OR target_kind='admin')";
        case 'user':
            $p[] = (int)$ctx['owner_uid']; $p[] = (int)$ctx['id'];
            return "((owner_uid=? AND creator_kind IN ('user','team')) OR (target_kind='user' AND target_id=?))";
        case 'team':
            $p[] = (int)$ctx['id']; $p[] = (int)$ctx['id'];
            return "((creator_kind='team' AND creator_id=?) OR (target_kind='team' AND target_id=?))";
        case 'member':
            $p[] = (int)$ctx['id'];
            return "(target_kind='member' AND target_id=?)";
    }
    return '0=1';
}

/** فهرست یادآورها: scope = upcoming | today | overdue | done | all */
function rem_list($pdo, array $ctx, $scope = 'upcoming', $limit = 300)
{
    rem_ensure_schema($pdo);
    $p = [];
    $w = rem_scope_sql($ctx, $p);
    $now = rem_now();
    $today = substr($now, 0, 10);
    switch ($scope) {
        case 'today':   $w .= " AND status='active' AND due_at BETWEEN ? AND ?"; $p[] = $today . ' 00:00:00'; $p[] = $today . ' 23:59:59'; $ord = 'due_at ASC'; break;
        case 'overdue': $w .= " AND status='active' AND due_at < ? AND next_at IS NULL"; $p[] = $now; $ord = 'due_at DESC'; break;
        case 'done':    $w .= " AND status IN ('done','cancelled')"; $ord = 'COALESCE(done_at, updated_at) DESC'; break;
        case 'all':     $ord = 'due_at DESC'; break;
        default:        $w .= " AND status='active' AND (due_at >= ? OR next_at IS NOT NULL)"; $p[] = $now; $ord = 'due_at ASC';
    }
    try {
        $s = $pdo->prepare("SELECT * FROM saas_reminders WHERE $w ORDER BY $ord LIMIT " . (int)$limit);
        $s->execute($p);
        return array_map(fn($r) => rem_public($r, $ctx), $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { error_log('[REM] list: ' . $e->getMessage()); return []; }
}

/** شمارش برای نشان‌ها */
function rem_counts($pdo, array $ctx)
{
    rem_ensure_schema($pdo);
    $p = [];
    $w = rem_scope_sql($ctx, $p);
    $now = rem_now(); $today = substr($now, 0, 10);
    $out = ['today' => 0, 'overdue' => 0, 'upcoming' => 0];
    try {
        $s = $pdo->prepare("SELECT SUM(CASE WHEN due_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS t, SUM(CASE WHEN due_at < ? AND next_at IS NULL THEN 1 ELSE 0 END) AS o, SUM(CASE WHEN due_at >= ? OR next_at IS NOT NULL THEN 1 ELSE 0 END) AS u FROM saas_reminders WHERE $w AND status='active'");
        $s->execute(array_merge([$today . ' 00:00:00', $today . ' 23:59:59', $now, $now], $p));
        $r = $s->fetch(\PDO::FETCH_ASSOC) ?: [];
        $out = ['today' => (int)($r['t'] ?? 0), 'overdue' => (int)($r['o'] ?? 0), 'upcoming' => (int)($r['u'] ?? 0)];
    } catch (\Throwable $e) {}
    return $out;
}

/** داده نمایشی یک یادآور */
function rem_public(array $r, array $ctx = [])
{
    $cat = rem_cats()[$r['category']] ?? rem_cats()['other'];
    $acc = $ctx ? rem_access($ctx, $r) : 'view';
    $mine_target = $ctx && $r['target_kind'] === $ctx['kind'] && (int)$r['target_id'] === (int)$ctx['id'];
    return [
        'id' => (int)$r['id'], 'ts' => rem_ts($r['due_at']), 'title' => $r['title'], 'note' => (string)$r['note'], 'category' => $r['category'], 'cat_label' => $cat[0], 'icon' => $cat[1], 'color' => $cat[2],
        'person' => $r['person'], 'place' => $r['place'], 'due_at' => $r['due_at'], 'date' => substr($r['due_at'], 0, 10), 'jdate' => rem_jdate($r['due_at']), 'time' => substr($r['due_at'], 11, 5),
        'all_day' => !empty($r['all_day']), 'when' => rem_human($r['due_at'], !empty($r['all_day'])), 'leads' => rem_leads_arr($r['leads']),
        'leads_text' => implode('، ', array_map('rem_lead_label', array_filter(rem_leads_arr($r['leads']), fn($x) => $x > 0))),
        'repeat' => $r['repeat_kind'], 'repeat_text' => $r['repeat_kind'] !== 'none' ? rem_repeat_opts()[$r['repeat_kind']] : '', 'repeat_until' => $r['repeat_until'] ? rem_jdate($r['repeat_until']) : '',
        'ch_site' => (bool)$r['ch_site'], 'ch_push' => (bool)$r['ch_push'], 'ch_sms' => (bool)$r['ch_sms'], 'priority' => (bool)$r['priority'],
        'status' => $r['status'], 'overdue' => $r['status'] === 'active' && rem_ts($r['due_at']) < time() && empty($r['next_at']),
        'snooze' => $r['snooze_at'] ? rem_human($r['snooze_at']) : '',
        'target' => $mine_target ? 'self' : $r['target_kind'] . ':' . $r['target_id'], 'target_label' => $mine_target ? '' : ($r['target_name'] !== '' ? $r['target_name'] : rem_kind_label($r['target_kind'])) . ' (' . rem_kind_label($r['target_kind']) . ')',
        'from' => (!$ctx || rem_access($ctx, $r) === 'edit' || ($r['creator_kind'] === ($ctx['kind'] ?? '') && (int)$r['creator_id'] === (int)($ctx['id'] ?? -1))) ? '' : ($r['creator_name'] !== '' ? $r['creator_name'] : rem_kind_label($r['creator_kind'])),
        'can_edit' => $acc === 'edit', 'source' => $r['source'],
    ];
}

// =============================================================================
// اجرای زمان‌بندی: ارسال یادآورهای موعدرسیده
// =============================================================================
/** اجرای خودکار حداکثر هر ۵۰ ثانیه (بعد از پاسخ به مرورگر) */
function rem_maybe_run($pdo)
{
    static $reg = false;
    if ($reg || !$pdo) return;
    $reg = true;
    $f = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.rem_tick';
    if (is_file($f) && filemtime($f) > time() - 50) return;
    @touch($f);
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        @set_time_limit(120);
        try { rem_run_due($pdo); } catch (\Throwable $e) { error_log('[REM] run: ' . $e->getMessage()); }
    });
}

function rem_run_due($pdo, $limit = 150)
{
    rem_ensure_schema($pdo);
    $now = rem_now();
    $n = 0;
    try {
        $s = $pdo->prepare("SELECT * FROM saas_reminders WHERE status='active' AND next_at IS NOT NULL AND next_at <= ? ORDER BY next_at ASC LIMIT " . (int)$limit);
        $s->execute([$now]);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { error_log('[REM] due: ' . $e->getMessage()); return 0; }
    foreach ($rows as $r) {
        // قفل خوش‌بینانه: فقط یک اجرا این یادآور را ارسال می‌کند
        $c = $pdo->prepare("UPDATE saas_reminders SET next_at=NULL WHERE id=? AND next_at=?");
        $c->execute([(int)$r['id'], $r['next_at']]);
        if ($c->rowCount() < 1) continue;
        $ts = time();
        $due = rem_ts($r['due_at']);
        $fired = array_map('intval', array_filter(explode(',', (string)$r['fired']), fn($x) => $x !== ''));
        try {
            if (!empty($r['snooze_at']) && rem_ts($r['snooze_at']) <= $ts) {
                rem_deliver($pdo, $r, 0, true);
                $r['snooze_at'] = null;
            } else {
                $passed = [];
                foreach (rem_leads_arr($r['leads']) as $l) if (!in_array($l, $fired, true) && $due - $l * 60 <= $ts) $passed[] = $l;
                if ($passed) {
                    rem_deliver($pdo, $r, min($passed));
                    $fired = array_values(array_unique(array_merge($fired, $passed)));
                }
            }
        } catch (\Throwable $e) { error_log('[REM] deliver #' . $r['id'] . ': ' . $e->getMessage()); }
        $r['fired'] = implode(',', $fired);
        // نوبت بعدی رویدادهای تکرارشونده
        if (in_array(0, $fired, true) && $r['repeat_kind'] !== 'none' && empty($r['snooze_at'])) {
            $nd = rem_next_occurrence($r['due_at'], $r['repeat_kind']);
            $g = 0;
            while (rem_ts($nd) < $ts && $g++ < 1000) $nd = rem_next_occurrence($nd, $r['repeat_kind']);
            if (!$r['repeat_until'] || rem_ts($nd) <= rem_ts($r['repeat_until'])) {
                $r['due_at'] = $nd; $r['fired'] = '';
                $r['fired'] = rem_skip_past_leads($r, $ts);
            }
        }
        $pdo->prepare("UPDATE saas_reminders SET fired=?, due_at=?, snooze_at=?, next_at=?, fire_count=fire_count+1, last_fired=?, updated_at=? WHERE id=?")
            ->execute([$r['fired'], $r['due_at'], $r['snooze_at'], rem_compute_next($r), rem_now(), rem_now(), (int)$r['id']]);
        $n++;
    }
    try { rem_digest($pdo); } catch (\Throwable $e) {}
    return $n;
}

/** متن اعلان */
function rem_texts(array $r, $lead, $again = false)
{
    if (!empty($r['is_msg']) && !$again) {
        // پیام فوری (ارسال گروهی / اطلاع جلسه): متن کامل پیام
        $x = array_filter([($r['person'] ?? '') !== '' ? '👤 ' . $r['person'] : '', ($r['place'] ?? '') !== '' ? '📍 ' . $r['place'] : '']);
        $body = trim((string)$r['note']) !== '' ? trim((string)$r['note']) : $r['title'];
        return [mb_substr('📣 ' . $r['title'], 0, 200), mb_substr($body . ($x ? ' | ' . implode(' ', $x) : ''), 0, 480), rem_human($r['due_at'], false)];
    }
    $cat = rem_cats()[$r['category']] ?? rem_cats()['other'];
    $title = ($r['priority'] ? '❗ ' : '') . $cat[1] . ' ' . $r['title'];
    $when = rem_human($r['due_at'], !empty($r['all_day']));
    if ($again) $b = 'یادآوری دوباره — ' . $when;
    elseif ($lead > 0) $b = rem_lead_label($lead) . ' از موعد: ' . $when;
    else $b = ($r['all_day'] ? 'امروز: ' : 'الان وقتش است — ') . $when;
    $x = array_filter([$r['person'] !== '' ? '👤 ' . $r['person'] : '', $r['place'] !== '' ? '📍 ' . $r['place'] : '']);
    if ($x) $b .= ' | ' . implode(' ', $x);
    return [$title, mb_substr($b, 0, 480), $when];
}

function rem_panel_url($kind)
{
    $base = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/');
    if ($kind === 'admin') return $base . '/' . (function_exists('aichat_admin_dir') ? aichat_admin_dir() : 'admin') . '/reminders.php';
    if ($kind === 'user' || $kind === 'team') return $base . '/user/reminders.php';
    return '';
}

/** ارسال یک یادآور به گیرنده با روش‌های انتخاب‌شده */
function rem_deliver($pdo, array $r, $lead, $again = false)
{
    [$title, $body, $when] = rem_texts($r, $lead, $again);
    $kind = $r['target_kind']; $tid = (int)$r['target_id'];
    $pdo->prepare("INSERT INTO saas_rem_events (reminder_id, target_kind, target_id, title, body, lead_min, show_site, created_at) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([(int)$r['id'], $kind, $tid, mb_substr($title, 0, 200), $body, (int)$lead, (int)$r['ch_site'], rem_now()]);
    $eid = (int)$pdo->lastInsertId();
    if ($r['ch_site'] && $kind === 'user' && function_exists('biz_notify')) biz_notify($pdo, $tid, $title, $body, $r['priority'] ? 'danger' : 'info', 'reminders.php');
    $pn = 0;
    if ($r['ch_push']) $pn = rem_push_to($pdo, $kind, $tid, $title, $body, rem_panel_url($kind), 'rem' . $r['id'], (bool)$r['priority']);
    $sms = 0;
    // پیامک: یک بار در هر نوبت (اولین یادآوری همان نوبت)؛ تعویق و یادآوری‌های بعدی فقط اعلان
    $first = !$again && trim((string)$r['fired']) === '';
    if ($r['ch_sms'] && $first) $sms = rem_send_sms($pdo, $r, $title, $when) ? 1 : -1;
    $pdo->prepare("UPDATE saas_rem_events SET push_n=?, sms=? WHERE id=?")->execute([$pn, $sms, $eid]);
    return $eid;
}

/** پیامک یادآور — پرداخت‌کننده: مدیر = سامانه؛ کاربر/همکار = اعتبار پیامک کاربر؛ مخاطب = سهمیه بسته مخاطب + اعتبار صاحب چت‌بات */
function rem_send_sms($pdo, array $r, $title, $when)
{
    $rc = rem_recipient($pdo, $r['target_kind'], (int)$r['target_id']);
    if (!$rc || trim((string)$rc['mobile']) === '' || !function_exists('biz_platform_sms_ready') || !biz_platform_sms_ready($pdo)) return false;
    $vars = ['name' => $rc['name'] !== '' ? $rc['name'] : 'کاربر', 'title' => trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $r['title'])), 'time' => $when];
    if (!empty($r['is_msg']) && $r['creator_kind'] !== 'member' && (int)$r['owner_uid'] > 0) {
        // پیام فوری: الگوی «پیام به مخاطبان» با متن کامل
        $bot = '';
        if ((int)$r['bot_id'] > 0) { try { $q = $pdo->prepare("SELECT name FROM hd_bots WHERE id=?"); $q->execute([(int)$r['bot_id']]); $bot = (string)$q->fetchColumn(); } catch (\Throwable $e) {} }
        $text = trim((string)$r['note']) !== '' ? trim((string)$r['note']) : $vars['title'];
        return biz_sms_for_user($pdo, (int)$r['owner_uid'], $rc['mobile'], '', 'notice', (int)$r['bot_id'], 'member_notice', $vars + ['text' => $text, 'bot' => $bot]);
    }
    if ($r['creator_kind'] === 'admin') return biz_sms_platform_tpl($pdo, $rc['mobile'], 'reminder', $vars, 'reminder', (int)$rc['owner_uid']);
    $owner = (int)$r['owner_uid'];
    if ($owner <= 0) return false;
    if ($r['creator_kind'] === 'member') {
        if (!rem_member_sms_take($pdo, (int)$r['bot_id'], (int)$r['creator_id'])) return false;
        $ok = biz_sms_for_user($pdo, $owner, $rc['mobile'], '', 'reminder', (int)$r['bot_id'], 'reminder', $vars);
        if (!$ok) rem_member_sms_refund($pdo, (int)$r['bot_id'], (int)$r['creator_id']);
        return $ok;
    }
    return biz_sms_for_user($pdo, $owner, $rc['mobile'], '', 'reminder', (int)$r['bot_id'], 'reminder', $vars);
}

// --- سهمیه پیامک یادآور مخاطبان (از بسته‌ها) ---
function rem_member_sms_left($pdo, $bot_id, $member_id)
{
    if (!function_exists('hd_active_purchases')) return 0;
    $left = 0;
    foreach (hd_active_purchases($pdo, $bot_id, $member_id) as $p) $left += max(0, (int)($p['rem_sms'] ?? 0) - (int)($p['rem_sms_used'] ?? 0));
    return $left;
}
function rem_member_sms_take($pdo, $bot_id, $member_id)
{
    if (!function_exists('hd_active_purchases')) return false;
    foreach (hd_active_purchases($pdo, $bot_id, $member_id) as $p) {
        if ((int)($p['rem_sms'] ?? 0) <= (int)($p['rem_sms_used'] ?? 0)) continue;
        $u = $pdo->prepare("UPDATE hd_purchases SET rem_sms_used = rem_sms_used + 1 WHERE id=? AND rem_sms_used < rem_sms");
        $u->execute([(int)$p['id']]);
        if ($u->rowCount() > 0) { $GLOBALS['__rem_sms_pid'] = (int)$p['id']; return true; }
    }
    return false;
}
function rem_member_sms_refund($pdo, $bot_id, $member_id)
{
    $pid = (int)($GLOBALS['__rem_sms_pid'] ?? 0);
    if ($pid) $pdo->prepare("UPDATE hd_purchases SET rem_sms_used = rem_sms_used - 1 WHERE id=? AND rem_sms_used > 0")->execute([$pid]);
}

/** خلاصه برنامه امروز (روزی یک بار، بعد از ساعت ۷:۳۰ صبح) برای کسانی که امروز یادآور دارند */
function rem_digest($pdo)
{
    $now = rem_now();
    if (substr($now, 11, 5) < '07:30') return 0;
    $today = substr($now, 0, 10);
    $flag = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.rem_digest_' . $today;
    if (is_file($flag)) return 0;
    @file_put_contents($flag, '1');
    foreach (glob(rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : sys_get_temp_dir(), '/') . '/.rem_digest_*') ?: [] as $old) if ($old !== $flag) @unlink($old);
    $s = $pdo->prepare("SELECT target_kind, target_id, COUNT(*) AS n, MIN(due_at) AS first_at FROM saas_reminders WHERE status='active' AND due_at BETWEEN ? AND ? GROUP BY target_kind, target_id");
    $s->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
    $k = 0;
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $g) {
        $title = '📅 برنامه امروز: ' . rem_fa($g['n']) . ' یادآور';
        $body = 'اولین مورد ساعت ' . rem_fa(substr($g['first_at'], 11, 5)) . '. برای دیدن فهرست باز کنید.';
        if ($g['target_kind'] === 'user' && function_exists('biz_notify')) biz_notify($pdo, (int)$g['target_id'], $title, $body, 'info', 'reminders.php');
        rem_push_to($pdo, $g['target_kind'], (int)$g['target_id'], $title, $body, rem_panel_url($g['target_kind']), 'rem-digest', false);
        $k++;
    }
    return $k;
}

// --- اعلان‌های داخل سایت (پنجره کوچک) ---
function rem_events_due($pdo, array $ctx, $limit = 8)
{
    rem_ensure_schema($pdo);
    try {
        $s = $pdo->prepare("SELECT e.id, e.reminder_id, e.title, e.body, e.lead_min, e.created_at, r.status, r.repeat_kind, r.priority FROM saas_rem_events e JOIN saas_reminders r ON r.id=e.reminder_id
            WHERE e.target_kind=? AND e.target_id=? AND e.seen=0 AND e.show_site=1 AND e.created_at >= ? ORDER BY e.id DESC LIMIT " . (int)$limit);
        $s->execute([$ctx['kind'], (int)$ctx['id'], rem_now(-2 * 86400)]);
        return array_map(fn($e) => ['id' => (int)$e['id'], 'rid' => (int)$e['reminder_id'], 'title' => $e['title'], 'body' => $e['body'], 'high' => (bool)$e['priority'], 'repeat' => $e['repeat_kind'] !== 'none', 'active' => $e['status'] === 'active'],
            $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { return []; }
}

/** دیده شد (+ عملیات روی خود یادآور: done / snooze) */
function rem_event_ack($pdo, array $ctx, $eid, $act = '', $arg = 0)
{
    $s = $pdo->prepare("SELECT * FROM saas_rem_events WHERE id=? AND target_kind=? AND target_id=?");
    $s->execute([(int)$eid, $ctx['kind'], (int)$ctx['id']]);
    $e = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$e) return ['ok' => false, 'error' => 'یافت نشد.'];
    $pdo->prepare("UPDATE saas_rem_events SET seen=1 WHERE id=?")->execute([(int)$eid]);
    if (in_array($act, ['done', 'snooze'], true)) return rem_act($pdo, $ctx, (int)$e['reminder_id'], $act, $arg);
    return ['ok' => true];
}

// =============================================================================
// اعلان مرورگر (Web Push با VAPID؛ بدون کتابخانه — پیام خالی، متن از سرور خوانده می‌شود)
// =============================================================================
function rem_b64u($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

/** کلیدهای VAPID (یک بار ساخته و ذخیره می‌شود) → ['pub' => base64url, 'priv' => PEM] */
function rem_vapid($pdo)
{
    static $v = null;
    if ($v !== null) return $v ?: null;
    $s = function_exists('biz_settings') ? biz_settings($pdo) : [];
    $pub = (string)($s['vapid_pub'] ?? ''); $priv = (string)($s['vapid_priv'] ?? '');
    if ($pub === '' || $priv === '') {
        if (!function_exists('openssl_pkey_new')) { $v = false; return null; }
        $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$k) { $v = false; return null; }
        @openssl_pkey_export($k, $priv);
        $d = openssl_pkey_get_details($k);
        if (empty($d['ec']['x']) || empty($d['ec']['y']) || !$priv) { $v = false; return null; }
        $pub = rem_b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
        biz_set($pdo, 'vapid_pub', $pub);
        biz_set($pdo, 'vapid_priv', $priv);
    }
    return $v = ['pub' => $pub, 'priv' => $priv];
}

/** امضای DER → r||s (۶۴ بایت) */
function rem_der2raw($der)
{
    $p = 2; if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $p++; $len = ord($der[$p]); $p++;
        $int = substr($der, $p, $len); $p += $len;
        $int = ltrim($int, "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

function rem_vapid_jwt($aud, array $vap)
{
    $host = parse_url(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : 'https://localhost', PHP_URL_HOST) ?: 'localhost';
    if (!preg_match('/\.[a-z]{2,}$/i', $host)) $host = 'example.com';
    $h = rem_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = rem_b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:push@' . $host]));
    $sig = '';
    if (!openssl_sign($h . '.' . $c, $sig, $vap['priv'], OPENSSL_ALGO_SHA256)) return '';
    return $h . '.' . $c . '.' . rem_b64u(rem_der2raw($sig));
}

/** ثبت/به‌روزرسانی اشتراک اعلان یک دستگاه */
function rem_push_subscribe($pdo, $kind, $ref, $bot_id, $sub, $ua = '')
{
    rem_ensure_schema($pdo);
    $sub = is_string($sub) ? json_decode($sub, true) : $sub;
    $ep = trim((string)($sub['endpoint'] ?? ''));
    // امنیت: فقط سرویس‌های اعلان مرورگرها (گوگل، موزیلا، اپل، مایکروسافت) — نه آدرس دلخواه/داخلی
    $eh = strtolower((string)parse_url($ep, PHP_URL_HOST));
    $push_host = (bool)preg_match('/(^|\.)(fcm\.googleapis\.com|android\.googleapis\.com|push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com|push\.api\.chrome\.google\.com)$/', $eh);
    $ok_url = (preg_match('#^https://[^\s]+$#', $ep) && $push_host) || (defined('SEC_ALLOW_LOCAL_URLS') && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#', $ep));
    if (!$ok_url || strlen($ep) > 1000) return ['ok' => false, 'error' => 'اشتراک اعلان معتبر نیست.'];
    $h = hash('sha256', $ep);
    $now = rem_now();
    $s = $pdo->prepare("SELECT id FROM saas_push_subs WHERE ehash=?");
    $s->execute([$h]);
    if ($id = (int)$s->fetchColumn()) $pdo->prepare("UPDATE saas_push_subs SET kind=?, ref_id=?, bot_id=?, ua=?, fails=0, last_at=? WHERE id=?")->execute([$kind, (int)$ref, (int)$bot_id, mb_substr((string)$ua, 0, 200), $now, $id]);
    else $pdo->prepare("INSERT INTO saas_push_subs (kind, ref_id, bot_id, endpoint, ehash, ua, created_at, last_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$kind, (int)$ref, (int)$bot_id, $ep, $h, mb_substr((string)$ua, 0, 200), $now, $now]);
    return ['ok' => true];
}

/**
 * اعلان آزمایشی به همین دستگاه (برای اطمینان از رسیدن اعلان حتی وقتی سایت بسته است)
 * نتیجه ارسال از سرور به سرویس اعلان مرورگر به زبان ساده گزارش می‌شود.
 */
function rem_push_test($pdo, $kind, $ref, $endpoint)
{
    rem_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM saas_push_subs WHERE ehash=? AND kind=? AND ref_id=?");
    $s->execute([hash('sha256', trim((string)$endpoint)), $kind, (int)$ref]);
    $sub = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$sub) return ['ok' => false, 'error' => 'اعلان روی این دستگاه هنوز ثبت نشده است؛ دکمه «فعال‌سازی اعلان» را بزنید.'];
    $vap = rem_vapid($pdo);
    if (!$vap) return ['ok' => false, 'error' => 'کلید اعلان سرور ساخته نشد (افزونه openssl روی هاست لازم است).'];
    $pdo->prepare("INSERT INTO saas_push_queue (sub_id, title, body, url, tag, high, created_at) VALUES(?,?,?,?,?,?,?)")
        ->execute([(int)$sub['id'], '🔔 اعلان آزمایشی', 'اعلان‌های یادآور روی این دستگاه فعال است ✅ (حتی وقتی سایت بسته است)', '', 'rem-test', 0, rem_now()]);
    $ok = rem_push_ping($pdo, $sub, $vap);
    $l = $GLOBALS['rem_push_last'] ?? ['code' => 0, 'err' => '', 'host' => ''];
    if ($ok) return ['ok' => true, 'msg' => 'اعلان آزمایشی فرستاده شد؛ تا چند ثانیه دیگر روی همین دستگاه نمایش داده می‌شود (برای آزمایش واقعی، قبل از زدن دکمه، چند ثانیه بعد سایت را ببندید).'];
    if ((int)$l['code'] === 0) return ['ok' => false, 'error' => 'سرور سایت نتوانست به سرویس اعلان مرورگر (' . $l['host'] . ') وصل شود. احتمالاً دسترسی خروجی هاست به این سرویس بسته است؛ از پشتیبانی هاست بخواهید اتصال خروجی به ' . $l['host'] . ' (پورت ۴۴۳) را باز کند.'];
    if (in_array((int)$l['code'], [404, 410], true)) return ['ok' => false, 'error' => 'اشتراک اعلان این دستگاه منقضی شده بود و حذف شد؛ یک بار دیگر «فعال‌سازی اعلان» را بزنید.'];
    return ['ok' => false, 'error' => 'سرویس اعلان مرورگر درخواست را نپذیرفت (کد ' . (int)$l['code'] . '). یک بار اعلان را خاموش و دوباره فعال کنید.'];
}

/** آخرین اجرای Cron (برای هشدار در پنل مدیر) */
function rem_cron_ok($pdo)
{
    $t = function_exists('biz_get') ? (string)biz_get($pdo, 'rem_cron_last') : '';
    if ($t === '') return false;
    // عدد = زمان یونیکس (مستقل از منطقه زمانی؛ PHP خط فرمان و وب ممکن است منطقه زمانی متفاوت داشته باشند)
    $ts = ctype_digit($t) ? (int)$t : (int)strtotime($t);
    return $ts > time() - 15 * 60;
}

function rem_push_unsubscribe($pdo, $endpoint)
{
    rem_ensure_schema($pdo);
    $pdo->prepare("DELETE FROM saas_push_subs WHERE ehash=?")->execute([hash('sha256', trim((string)$endpoint))]);
    return ['ok' => true];
}

function rem_push_count($pdo, $kind, $ref)
{
    try { $s = $pdo->prepare("SELECT COUNT(*) FROM saas_push_subs WHERE kind=? AND ref_id=?"); $s->execute([$kind, (int)$ref]); return (int)$s->fetchColumn(); } catch (\Throwable $e) { return 0; }
}

/** ارسال اعلان به همه دستگاه‌های یک گیرنده → تعداد ارسال موفق */
function rem_push_to($pdo, $kind, $ref, $title, $body, $url = '', $tag = '', $high = false)
{
    rem_ensure_schema($pdo);
    $vap = rem_vapid($pdo);
    if (!$vap) return 0;
    try { $s = $pdo->prepare("SELECT * FROM saas_push_subs WHERE kind=? AND ref_id=?"); $s->execute([$kind, (int)$ref]); $subs = $s->fetchAll(\PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { return 0; }
    $ok = 0;
    foreach ($subs as $sub) {
        $pdo->prepare("INSERT INTO saas_push_queue (sub_id, title, body, url, tag, high, created_at) VALUES(?,?,?,?,?,?,?)")
            ->execute([(int)$sub['id'], mb_substr($title, 0, 200), mb_substr($body, 0, 500), mb_substr((string)$url, 0, 500), mb_substr((string)$tag, 0, 60), $high ? 1 : 0, rem_now()]);
        if (rem_push_ping($pdo, $sub, $vap)) $ok++;
    }
    return $ok;
}

/** درخواست خالی به سرویس اعلان مرورگر (سرویس‌کارگر متن را از سرور می‌خواند) */
function rem_push_ping($pdo, array $sub, array $vap)
{
    $ep = (string)$sub['endpoint'];
    $u = parse_url($ep);
    $aud = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    $jwt = rem_vapid_jwt($aud, $vap);
    if ($jwt === '') return false;
    $ch = curl_init($ep);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['TTL: 43200', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . $jwt . ', k=' . $vap['pub']]]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $GLOBALS['rem_push_last'] = ['code' => $code, 'err' => (string)curl_error($ch), 'host' => (string)($u['host'] ?? '')];
    curl_close($ch);
    if ($code >= 200 && $code < 300) { $pdo->prepare("UPDATE saas_push_subs SET fails=0, last_at=? WHERE id=?")->execute([rem_now(), (int)$sub['id']]); return true; }
    if ($code === 404 || $code === 410 || (int)$sub['fails'] >= 20) { $pdo->prepare("DELETE FROM saas_push_subs WHERE id=?")->execute([(int)$sub['id']]); $pdo->prepare("DELETE FROM saas_push_queue WHERE sub_id=?")->execute([(int)$sub['id']]); }
    else $pdo->prepare("UPDATE saas_push_subs SET fails=fails+1 WHERE id=?")->execute([(int)$sub['id']]);
    error_log('[REM] push HTTP ' . $code . ' ' . substr($ep, 0, 60));
    return false;
}

/** سرویس‌کارگر متن اعلان‌ها را می‌گیرد */
function rem_push_pull($pdo, $endpoint)
{
    rem_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT id FROM saas_push_subs WHERE ehash=?");
    $s->execute([hash('sha256', trim((string)$endpoint))]);
    $sid = (int)$s->fetchColumn();
    if (!$sid) return ['ok' => true, 'items' => []];
    $q = $pdo->prepare("SELECT id, title, body, url, tag, high FROM saas_push_queue WHERE sub_id=? AND delivered=0 AND created_at >= ? ORDER BY id ASC LIMIT 5");
    $q->execute([$sid, rem_now(-86400)]);
    $items = $q->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if ($items) $pdo->prepare("UPDATE saas_push_queue SET delivered=1 WHERE sub_id=? AND id <= ?")->execute([$sid, (int)end($items)['id']]);
    if (mt_rand(1, 30) === 1) { try { $pdo->prepare("DELETE FROM saas_push_queue WHERE created_at < ?")->execute([rem_now(-7 * 86400)]); } catch (\Throwable $e) {} }
    return ['ok' => true, 'items' => array_map(fn($x) => ['id' => (int)$x['id'], 'title' => $x['title'], 'body' => $x['body'], 'url' => $x['url'], 'tag' => $x['tag'], 'high' => (bool)$x['high']], $items)];
}

/** کد سرویس‌کارگر (برای پنل‌ها و صفحه چت) */
function rem_sw_js($pull_url, $icon = '', $home = '')
{
    return "/* push service worker */\n"
        . "const PULL=" . json_encode($pull_url) . ",ICON=" . json_encode($icon) . ",HOME=" . json_encode($home) . ";\n"
        . "self.addEventListener('install',e=>self.skipWaiting());self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));\n"
        . "self.addEventListener('push',e=>{e.waitUntil(self.registration.pushManager.getSubscription().then(s=>fetch(PULL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({endpoint:s?s.endpoint:''})})).then(r=>r.json()).then(d=>{const it=(d&&d.items)||[];"
        . "if(!it.length)return self.registration.showNotification('⏰ یادآور',{body:'یک یادآور تازه دارید.',icon:ICON||undefined,dir:'rtl',lang:'fa'});"
        . "return Promise.all(it.map(x=>self.registration.showNotification(x.title,{body:x.body,tag:x.tag||('n'+x.id),renotify:true,requireInteraction:!!x.high,icon:ICON||undefined,dir:'rtl',lang:'fa',data:{url:x.url||HOME||self.registration.scope}})));})"
        . ".catch(()=>self.registration.showNotification('⏰ یادآور',{body:'یک یادآور تازه دارید.',dir:'rtl',lang:'fa'})));});\n"
        . "self.addEventListener('notificationclick',e=>{e.notification.close();const u=(e.notification.data&&e.notification.data.url)||HOME||self.registration.scope;"
        . "e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{for(const c of cs){if(c.url.indexOf(u.split('?')[0])===0&&'focus' in c)return c.focus();}return clients.openWindow(u);}));});\n";
}

// =============================================================================
// چت‌بات: یادآور مخاطبان (API فایل رابط + ثبت از داخل گفتگو)
// =============================================================================
function rem_bot_on($bot) { return !array_key_exists('rem_on', $bot) || !empty($bot['rem_on']); }

/** تنظیمات فرم برای صفحه چت */
function rem_member_meta($pdo, $bot, $member)
{
    $ctx = rem_ctx_member($bot, $member);
    $v = rem_vapid($pdo);
    return ['counts' => rem_counts($pdo, $ctx), 'sms_left' => rem_member_sms_left($pdo, (int)$bot['id'], (int)$member['id']), 'devices' => rem_push_count($pdo, 'member', (int)$member['id']),
            'vapid' => $v['pub'] ?? '', 'cats' => rem_cats(), 'leads' => rem_lead_opts(), 'repeats' => rem_repeat_opts(), 'days' => rem_ui_days(), 'today_j' => rem_jdate(rem_now()),
            'pref' => (string)($member['rem_pref'] ?? 'site,push')];
}

/** کسر هزینه تحلیل هوشمند از صاحب چت‌بات */
function rem_bot_charge($pdo, $bot, $toman)
{
    if ($toman > 0 && function_exists('saas_deduct_credit')) saas_deduct_credit($pdo, (int)$bot['user_id'], (int)$toman, 'چت‌بات تخصصی (یادآور هوشمند): ' . $bot['name']);
}

/** اکشن‌های یادآور در API چت‌بات؛ null = اکشن مربوط نیست */
function rem_member_dispatch($pdo, $bot, $member, $action, array $in)
{
    if (strpos($action, 'rem_') !== 0 && $action !== 'push_sub' && $action !== 'push_test') return null;
    rem_ensure_schema($pdo);
    if (!rem_bot_on($bot)) return ['ok' => false, 'error' => 'یادآور در این چت‌بات فعال نیست.'];
    $ctx = rem_ctx_member($bot, $member);
    $x = is_array($in['rem'] ?? null) ? $in['rem'] : [];
    $ro = !empty($member['_imp']);   // همکار در حال مشاهده: فقط خواندنی
    switch ($action) {
        case 'rem_list':
            $scope = in_array($x['scope'] ?? '', ['upcoming', 'today', 'overdue', 'done', 'all'], true) ? $x['scope'] : 'upcoming';
            return ['ok' => true, 'items' => rem_list($pdo, $ctx, $scope), 'meta' => rem_member_meta($pdo, $bot, $member)];
        case 'rem_due':
            return ['ok' => true, 'items' => $ro ? [] : rem_events_due($pdo, $ctx)];
    }
    if ($ro) return ['ok' => false, 'error' => 'در حالت مشاهده همکار امکان تغییر نیست.'];
    switch ($action) {
        case 'rem_parse':
            if (function_exists('ex_rate_limited') && ex_rate_limited('rem_parse:m' . (int)$member['id'], 40, 3600)) return ['ok' => false, 'error' => 'درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.'];
            $text = trim((string)($x['text'] ?? ''));
            $p = rem_parse($pdo, $text);
            rem_bot_charge($pdo, $bot, $p['toman']);
            if (!$p['ok']) return ['ok' => false, 'error' => $p['error'] ?: 'متن را متوجه نشدم؛ کمی دقیق‌تر بنویسید.'];
            $f = ['title' => $p['title'], 'category' => $p['category'], 'jdate' => $p['jdate'], 'time' => $p['all_day'] ? '' : $p['time'], 'all_day' => $p['all_day'], 'leads' => $p['leads'],
                  'repeat' => $p['repeat'], 'person' => $p['person'], 'place' => $p['place'], 'note' => $p['note'], 'raw_text' => $text, 'source' => 'smart'];
            $pref = explode(',', (string)($member['rem_pref'] ?? 'site,push'));
            // صبح یا عصر مشخص نیست: اول پرسیده می‌شود
            if (!empty($p['ampm'])) return ['ok' => true, 'ask' => $p['ampm'], 'fields' => $f + ['ch_site' => 1, 'ch_push' => in_array('push', $pref, true) ? 1 : 0, 'ch_sms' => (!empty($x['sms']) || $p['sms'] || in_array('sms', $pref, true)) ? 1 : 0], 'save' => !empty($x['save'])];
            if (empty($x['save'])) return ['ok' => true, 'fields' => $f];
            $r = rem_save($pdo, $ctx, $f + ['date' => $p['date'], 'target' => 'self', 'ch_site' => 1, 'ch_push' => in_array('push', $pref, true) ? 1 : 0, 'ch_sms' => (!empty($x['sms']) || $p['sms'] || in_array('sms', $pref, true)) ? 1 : 0]);
            return $r['ok'] ? ['ok' => true, 'saved' => true, 'item' => rem_public($r['row'], $ctx), 'warn' => $r['warn'], 'meta' => rem_member_meta($pdo, $bot, $member)] : $r;
        case 'rem_save':
            $f = [];
            foreach (['title', 'category', 'jdate', 'time', 'all_day', 'repeat', 'repeat_until', 'person', 'place', 'note', 'ch_site', 'ch_push', 'ch_sms', 'priority', 'source', 'raw_text'] as $k) if (isset($x[$k]) && is_scalar($x[$k])) $f[$k] = (string)$x[$k];
            $f['leads'] = array_map('intval', is_array($x['leads'] ?? null) ? $x['leads'] : explode(',', (string)($x['leads'] ?? '0')));
            $f['target'] = 'self';
            $r = rem_save($pdo, $ctx, $f, (int)($x['id'] ?? 0));
            if (!$r['ok']) return $r;
            // ترجیح روش اطلاع مخاطب برای ثبت‌های بعدی (هوشمند)
            $pref = implode(',', array_keys(array_filter(['site' => 1, 'push' => !empty($f['ch_push']), 'sms' => !empty($f['ch_sms'])])));
            try { $pdo->prepare("UPDATE hd_members SET rem_pref=? WHERE id=?")->execute([$pref, (int)$member['id']]); } catch (\Throwable $e) {}
            return ['ok' => true, 'item' => rem_public($r['row'], $ctx), 'warn' => $r['warn'], 'meta' => rem_member_meta($pdo, $bot, $member)];
        case 'rem_act':
            $r = rem_act($pdo, $ctx, (int)($x['id'] ?? 0), (string)($x['act'] ?? ''), (int)($x['arg'] ?? 0));
            return $r + ['meta' => rem_member_meta($pdo, $bot, $member)];
        case 'rem_ack':
            return rem_event_ack($pdo, $ctx, (int)($x['id'] ?? 0), (string)($x['act'] ?? ''), (int)($x['arg'] ?? 0));
        case 'push_test':
            return rem_push_test($pdo, 'member', (int)$member['id'], (string)($x['endpoint'] ?? ''));
        case 'push_sub':
            return rem_push_subscribe($pdo, 'member', (int)$member['id'], (int)$bot['id'], $x['sub'] ?? [], (string)($x['ua'] ?? ''));
    }
    return ['ok' => false, 'error' => 'درخواست نامعتبر است.'];
}

/**
 * ثبت یادآور از داخل گفتگو: «یادم بنداز فردا ساعت ۱۰ جلسه با آقای احمدی»
 * null = پیام عادی است و به پاسخگوی متنی می‌رود
 */
function rem_chat_route($pdo, $bot, $member, array $in)
{
    if (!rem_bot_on($bot) || !empty($member['_imp']) || !empty($in['edit_id']) || !empty($in['reply_to'])) return null;
    $text = trim((string)($in['message'] ?? ''));
    $force = !empty($in['rem_mode']);
    $intent = $force ? 2 : rem_intent($text);
    if ($intent === 0) return null;
    rem_ensure_schema($pdo);
    $p = rem_parse($pdo, $text);
    rem_bot_charge($pdo, $bot, $p['toman']);
    if ($intent === 1 && (!$p['ai'] || !$p['is_reminder'] || !$p['ok'])) return null;
    $ctx = rem_ctx_member($bot, $member);
    if (!$p['ok']) {
        $reply = '⏰ ' . ($p['error'] ?: 'زمان یادآور مشخص نیست.') . "\nمثلاً بنویسید: «یادم بنداز فردا ساعت ۱۰ جلسه با آقای احمدی».";
        return rem_chat_messages($pdo, $bot, $member, (int)($in['thread_id'] ?? 0), $text, $reply, null);
    }
    $pref = explode(',', (string)($member['rem_pref'] ?? 'site,push'));
    if (!empty($p['ampm'])) {
        // صبح یا عصر؟ — با دو دکمه زیر پیام پرسیده می‌شود و بعد ثبت نهایی انجام می‌شود
        $f = ['title' => $p['title'], 'category' => $p['category'], 'all_day' => 0, 'leads' => $p['leads'], 'repeat' => $p['repeat'], 'person' => $p['person'], 'place' => $p['place'],
              'note' => $p['note'], 'ch_site' => 1, 'ch_push' => in_array('push', $pref, true) ? 1 : 0, 'ch_sms' => ($p['sms'] || in_array('sms', $pref, true)) ? 1 : 0, 'source' => 'chat', 'raw_text' => $text];
        $out = rem_chat_messages($pdo, $bot, $member, (int)($in['thread_id'] ?? 0), $text, '⏰ ' . $p['ampm']['q'] . "\n**" . $p['title'] . '**', null);
        return $out + ['rem_ask' => ['q' => $p['ampm']['q'], 'opts' => $p['ampm']['opts'], 'fields' => $f]];
    }
    $r = rem_save($pdo, $ctx, ['title' => $p['title'], 'category' => $p['category'], 'date' => $p['date'], 'time' => $p['all_day'] ? '' : $p['time'], 'all_day' => $p['all_day'],
        'leads' => $p['leads'], 'repeat' => $p['repeat'], 'person' => $p['person'], 'place' => $p['place'], 'note' => $p['note'], 'target' => 'self', 'ch_site' => 1,
        'ch_push' => in_array('push', $pref, true) ? 1 : 0, 'ch_sms' => ($p['sms'] || in_array('sms', $pref, true)) ? 1 : 0, 'source' => 'chat', 'raw_text' => $text]);
    if (!$r['ok']) return rem_chat_messages($pdo, $bot, $member, (int)($in['thread_id'] ?? 0), $text, '⏰ ' . $r['error'], null);
    $it = rem_public($r['row'], $ctx);
    $lines = ['⏰ یادآور ثبت شد', '**' . $it['icon'] . ' ' . $it['title'] . '**', '🕒 ' . $it['when']];
    if ($it['person'] !== '') $lines[] = '👤 ' . $it['person'];
    if ($it['place'] !== '') $lines[] = '📍 ' . $it['place'];
    if ($it['leads_text'] !== '') $lines[] = '🔔 خبرتان می‌کنم: ' . $it['leads_text'] . ' و سر موعد';
    if ($it['repeat_text'] !== '') $lines[] = '🔁 ' . $it['repeat_text'];
    $lines[] = 'روش: ' . implode(' + ', array_filter([$it['ch_site'] ? 'اعلان در همین صفحه' : '', $it['ch_push'] ? 'اعلان گوشی' : '', $it['ch_sms'] ? 'پیامک' : '']));
    if ($r['warn'] !== '') $lines[] = 'ℹ️ ' . $r['warn'];
    $lines[] = 'برای تغییر یا لغو، از «⏰ یادآورها» استفاده کنید.';
    return rem_chat_messages($pdo, $bot, $member, (int)($in['thread_id'] ?? 0), $text, implode("\n", $lines), $it, (string)$r['warn']);
}

/** ذخیره پیام کاربر و پاسخ تأیید در گفتگو (بدون کسر از سهمیه پیام) */
function rem_chat_messages($pdo, $bot, $member, $thread_id, $text, $reply, $item, $warn = '')
{
    $now = hd_now();
    $thread = $thread_id ? hd_get_thread($pdo, $thread_id, $bot['id'], $member['id']) : null;
    if (!$thread) {
        $pdo->prepare("INSERT INTO hd_threads (bot_id, member_id, title, created_at, updated_at) VALUES(?,?,?,?,?)")->execute([(int)$bot['id'], (int)$member['id'], mb_substr('⏰ ' . preg_replace('/\s+/u', ' ', $text), 0, 60), $now, $now]);
        $tid = (int)$pdo->lastInsertId();
    } else {
        $tid = (int)$thread['id'];
        $pdo->prepare("UPDATE hd_threads SET updated_at=? WHERE id=?")->execute([$now, $tid]);
    }
    $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, created_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$tid, (int)$bot['id'], (int)$member['id'], 'user', $text, null, 0, $now]);
    $um = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, created_at) VALUES(?,?,?,?,?,?,?,?)")->execute([$tid, (int)$bot['id'], (int)$member['id'], 'assistant', $reply, null, 0, $now]);
    $am = (int)$pdo->lastInsertId();
    $q = hd_quota($pdo, $bot, $member);
    return ['ok' => true, 'reply' => $reply, 'thread_id' => $tid, 'message_id' => $am, 'user_message_id' => $um, 'reminder' => $item, 'quota' => $q, 'ext' => hd_member_public($pdo, $bot, $member, $q),
            'can_buy' => mb_strpos((string)$warn, 'سهمیه پیامک') !== false];
}

/** روزهای پیشنهادی فرم (امروز، فردا، … تا ۷ روز) */
function rem_ui_days()
{
    $out = [];
    $today = substr(rem_now(), 0, 10);
    for ($i = 0; $i < 8; $i++) {
        $g = gmdate('Y-m-d', strtotime($today . ' 12:00:00 UTC') + $i * 86400);
        $w = rem_wdays()[(int)(new \DateTime($g, rem_tz()))->format('w')];
        [$y, $m, $d] = array_map('intval', explode('-', $g));
        [$jy, $jm, $jd] = rem_g2j($y, $m, $d);
        $out[] = [rem_jdate($g), [0 => 'امروز', 1 => 'فردا', 2 => 'پس‌فردا'][$i] ?? ($w . ' ' . rem_fa($jd))];
    }
    return $out;
}

