<?php
/**
 * شارژ تومانی مخاطبان چت‌بات (نسخه ۶۲)
 *  - صاحب چت‌بات کنار «بسته تعداد پیام»، «بسته شارژ تومانی» هم می‌فروشد (hd_member_plans.pkind = 'credit')
 *  - هزینه هر پاسخ / تصویر / ویدیو برای مخاطب = همان هزینه‌ای که از شارژ صاحب چت‌بات کم می‌شود (قیمت مدل در پنل مدیر)
 *    + درصد ثابتی که مدیر تعیین می‌کند (پیش‌فرض ۱۰٪؛ biz: member_markup)
 *  - شارژ هر خرید در hd_purchases.credit_toman / credit_used نگه داشته می‌شود (با انقضا و اشتراک بین چت‌بات‌ها مثل بسته‌های پیامی)
 *  - ترتیب مصرف: بسته پیامی ← شارژ هدیه ← شارژ تومانی ← پیام رایگان (مدل‌های تخصصی)؛ برای مدل‌های ارزان چت رایگان اول است
 */

function mcr_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.mcr_schema_v1' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        foreach ([['hd_member_plans', 'pkind', "VARCHAR(8) NOT NULL DEFAULT 'msgs'"], ['hd_member_plans', 'credit_toman', 'INT NOT NULL DEFAULT 0'],
                  ['hd_purchases', 'credit_toman', 'INT NOT NULL DEFAULT 0'], ['hd_purchases', 'credit_used', 'INT NOT NULL DEFAULT 0']] as $c)
            saas_add_column_if_missing($pdo, $c[0], $c[1], $c[2]);
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[MCR] schema: ' . $e->getMessage()); }
}

/** درصد اضافه قیمت مخاطبان نسبت به قیمت مدیر (ثابت، از تنظیمات مدیر) */
function mcr_markup($pdo)
{
    $v = biz_settings($pdo)['member_markup'] ?? '';
    return $v === '' || $v === null ? 10.0 : max(0.0, min(1000.0, (float)$v));
}

/** قیمت مخاطب از روی هزینه صاحب چت‌بات (تومان) */
function mcr_price($pdo, $owner_toman)
{
    $t = (float)$owner_toman;
    return $t <= 0 ? 0 : (int)ceil($t * (1 + mcr_markup($pdo) / 100) - 0.0000001);
}

function mcr_is_credit_plan($plan) { return $plan && ($plan['pkind'] ?? 'msgs') === 'credit'; }

/** مبلغ شارژ بسته تومانی (اگر تعیین نشده = قیمت بسته) */
function mcr_plan_credit($plan)
{
    if (!mcr_is_credit_plan($plan)) return 0;
    $c = (int)($plan['credit_toman'] ?? 0);
    return $c > 0 ? $c : (int)($plan['price_toman'] ?? 0);
}

/** خرید فعال‌شده: اگر بسته از نوع شارژ تومانی است، شارژ به خرید اضافه می‌شود (تعداد پیام = ۰) */
function mcr_on_activate($pdo, $purchase)
{
    mcr_schema($pdo);
    try {
        $s = $pdo->prepare("SELECT * FROM hd_member_plans WHERE id=?");
        $s->execute([(int)($purchase['plan_id'] ?? 0)]);
        $plan = $s->fetch(\PDO::FETCH_ASSOC);
        if (!mcr_is_credit_plan($plan)) return;
        $pdo->prepare("UPDATE hd_purchases SET credit_toman=?, credit_used=0, messages=0 WHERE id=? AND credit_toman=0")->execute([mcr_plan_credit($plan), (int)$purchase['id']]);
    } catch (\Throwable $e) { error_log('[MCR] activate: ' . $e->getMessage()); }
}

/** شارژ باقی‌مانده خریدهای فعال */
function mcr_left(array $purchases)
{
    $l = 0;
    foreach ($purchases as $p) $l += max(0, (int)($p['credit_toman'] ?? 0) - (int)($p['credit_used'] ?? 0));
    return $l;
}

/** آیا این خرید هنوز قابل استفاده است؟ (پیام یا شارژ باقی‌مانده) */
function mcr_purchase_live($p)
{
    return (int)$p['used'] < (int)$p['messages'] || (int)($p['credit_toman'] ?? 0) > (int)($p['credit_used'] ?? 0);
}

/**
 * کسر هزینه از شارژ تومانی مخاطب
 * @param int $owner_toman هزینه‌ای که از صاحب چت‌بات کم شده (قیمت مدیر)
 * @return int مبلغ کسرشده از مخاطب
 */
function mcr_charge($pdo, $bot, $member, $owner_toman)
{
    if (!empty($member['is_owner'])) return 0;
    $price = mcr_price($pdo, $owner_toman);
    if ($price <= 0) return 0;
    mcr_schema($pdo);
    $left = $price; $done = 0;
    foreach (hd_active_purchases($pdo, $bot['id'], $member['id']) as $p) {
        $avail = (int)($p['credit_toman'] ?? 0) - (int)($p['credit_used'] ?? 0);
        if ($avail <= 0) continue;
        $take = min($avail, $left);
        $pdo->prepare("UPDATE hd_purchases SET credit_used = credit_used + ? WHERE id=?")->execute([$take, (int)$p['id']]);
        $left -= $take; $done += $take;
        if ($left <= 0) break;
    }
    return $done;
}

function mcr_fmt($n) { return number_format((int)$n); }

/** برچسب قیمت مدل: [قیمت صاحب چت‌بات (مدیر)، قیمت مخاطب] */
function mcr_model_labels($pdo, $m)
{
    if (!is_array($m) || !function_exists('biz_model_toman_prices')) return ['', ''];
    $p = biz_model_toman_prices($pdo, $m);
    $k = 1 + mcr_markup($pdo) / 100;
    $cat = $m['category'] ?? 'text';
    $f = fn($v) => function_exists('biz_toman_fmt') ? biz_toman_fmt($v) : number_format((float)$v);
    if (biz_price_is_token_cat($cat)) return ['≈ ' . $f($p['per1k']) . ' تومان / ۱۰۰۰ توکن', '≈ ' . $f($p['per1k'] * $k) . ' تومان / ۱۰۰۰ توکن'];
    $u = biz_price_units()[$cat]['label'] ?? '';
    return ['≈ ' . $f($p['unit']) . ' تومان ' . $u, '≈ ' . $f($p['unit'] * $k) . ' تومان ' . $u];
}
