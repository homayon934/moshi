<?php
/**
 * نظارت مشاور و دسترسی همکاران — فقط برای مخاطبان دارای اشتراک (بسته پولی فعال)
 *
 *  - مخاطب بدون اشتراک: بخش «دسترسی همکاران» را می‌بیند اما غیرفعال است
 *    («نظارت مشاور با تهیه اشتراک فعال می‌شود» + دکمه خرید اشتراک).
 *  - اگر مخاطب قبلاً اجازه داده بود و اشتراکش تمام شد، اجازه «معلق» می‌شود (consent_hold=1)
 *    و با خرید/تمدید اشتراک، خودکار دوباره فعال می‌شود.
 *  - چت‌باتی که بسته پولی نمی‌فروشد مشمول این محدودیت نیست (وگرنه نظارت هرگز ممکن نبود).
 *
 * این فایل از انتهای biz_lib.php بارگذاری می‌شود.
 */

if (!defined('SUP_SCHEMA_VERSION')) define('SUP_SCHEMA_VERSION', 1);

function sup_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.sup_schema_v' . SUP_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $ok = true;
    try { saas_add_column_if_missing($pdo, 'hd_members', 'consent_hold', "TINYINT(1) NOT NULL DEFAULT 0"); }
    catch (\Throwable $e) { $ok = false; error_log('[SUP] column: ' . $e->getMessage()); }
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
}

/** آیا این چت‌بات بسته پولی می‌فروشد؟ (نتیجه در طول درخواست نگه داشته می‌شود) */
function sup_bot_sells($pdo, $bot)
{
    static $cache = [];
    $id = (int)($bot['id'] ?? 0);
    if (!array_key_exists($id, $cache)) {
        try { $cache[$id] = function_exists('hd_bot_sells') ? (bool)hd_bot_sells($pdo, $bot) : false; } catch (\Throwable $e) { $cache[$id] = false; }
    }
    return $cache[$id];
}

/** آیا مخاطب اشتراک پولی فعال دارد؟ (بسته رایگان «هدیه» اشتراک حساب نمی‌شود) */
function sup_has_subscription($pdo, $bot, $member)
{
    if (!function_exists('hd_active_purchases')) return false;
    foreach (hd_active_purchases($pdo, (int)$bot['id'], (int)$member['id']) as $p) {
        $price = max((int)($p['amount_toman'] ?? 0), (int)($p['original_amount'] ?? 0));
        if ($price < 1000) continue;
        $msgs = (int)($p['messages'] ?? 0);
        if ($msgs <= 0 || (int)($p['used'] ?? 0) < $msgs) return true;
    }
    return false;
}

/** آیا نظارت مشاور/دسترسی همکاران برای این مخاطب مجاز است؟ */
function sup_allowed($pdo, $bot, $member)
{
    if (!empty($member['is_owner'])) return true;
    if (!sup_bot_sells($pdo, $bot)) return true;
    return sup_has_subscription($pdo, $bot, $member);
}

/**
 * هماهنگ‌سازی اجازه مخاطب با وضعیت اشتراک؛ ردیف به‌روزشده را برمی‌گرداند.
 * فقط وقتی کاری لازم است پرس‌وجوی اشتراک انجام می‌شود.
 */
function sup_sync($pdo, $bot, array $member)
{
    if (!empty($member['is_owner'])) return $member;
    $share = !empty($member['consent_share']);
    $hold = !empty($member['consent_hold']);
    if (!$share && !$hold) return $member;
    sup_ensure_schema($pdo);
    $ok = sup_allowed($pdo, $bot, $member);
    try {
        if ($share && !$ok) {
            $pdo->prepare("UPDATE hd_members SET consent_share=0, consent_hold=1, imp_hash='', imp_until=NULL, magic_hash='', magic_until=NULL WHERE id=?")->execute([(int)$member['id']]);
            $member['consent_share'] = 0; $member['consent_hold'] = 1;
        } elseif ($hold && $ok) {
            $pdo->prepare("UPDATE hd_members SET consent_share=1, consent_hold=0 WHERE id=?")->execute([(int)$member['id']]);
            $member['consent_share'] = 1; $member['consent_hold'] = 0;
        }
    } catch (\Throwable $e) { error_log('[SUP] sync: ' . $e->getMessage()); }
    return $member;
}

/**
 * بررسی دسته‌ای (کرون ساعتی و ورود به صفحه چت‌بات در پنل): اشتراک‌های تمام‌شده و تمدیدشده
 * $bot_id = 0 یعنی همه چت‌بات‌ها
 */
function sup_sweep($pdo, $bot_id = 0, $limit = 400)
{
    sup_ensure_schema($pdo);
    $n = 0;
    try {
        $sql = "SELECT * FROM hd_members WHERE is_owner=0 AND (consent_share=1 OR consent_hold=1)" . ($bot_id ? " AND bot_id=" . (int)$bot_id : '') . " ORDER BY id ASC LIMIT " . (int)$limit;
        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $bots = [];
        foreach ($rows as $m) {
            $bid = (int)$m['bot_id'];
            if (!isset($bots[$bid])) {
                $s = $pdo->prepare("SELECT * FROM hd_bots WHERE id=?"); $s->execute([$bid]);
                $bots[$bid] = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            if (!$bots[$bid]) continue;
            $after = sup_sync($pdo, $bots[$bid], $m);
            if ((int)$after['consent_share'] !== (int)$m['consent_share']) $n++;
        }
    } catch (\Throwable $e) { error_log('[SUP] sweep: ' . $e->getMessage()); }
    return $n;
}

/** بررسی دسته‌ای یک چت‌بات حداکثر هر ۱۰ دقیقه یک بار (برای صفحه چت‌بات در پنل) */
function sup_sweep_bot_throttled($pdo, $bot_id)
{
    $dir = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') : '';
    $f = $dir !== '' ? $dir . '/.sup_' . (int)$bot_id : '';
    if ($f !== '' && is_file($f) && filemtime($f) > time() - 600) return 0;
    if ($f !== '') @touch($f);
    return sup_sweep($pdo, (int)$bot_id);
}

/** اطلاعات نمایشی برای رابط مخاطب */
function sup_member_meta($pdo, $bot, $member)
{
    if (!empty($member['is_owner']) || !empty($member['_imp'])) return ['sup_lock' => false, 'consent_hold' => false];
    $lock = !sup_allowed($pdo, $bot, $member);
    return ['sup_lock' => $lock, 'consent_hold' => !empty($member['consent_hold']),
            'sup_text' => $lock ? 'نظارت مشاور با تهیه اشتراک فعال می‌شود.' : ''];
}
