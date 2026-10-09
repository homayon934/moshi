<?php
/**
 * کارت به کارت + فیش واریزی (نسخه ۵۶)
 *  - مخاطب چت‌بات: خرید بسته با کارت به کارت (شماره کارت صاحب چت‌بات) → بارگذاری فیش → تأیید صاحب چت‌بات → فعال شدن بسته
 *  - کاربر سایت: خرید پلن/اعتبار/پیامک با کارت به کارت (کارت سایت) → بارگذاری فیش → تأیید مدیر → همان مسیر پرداخت موفق
 *  - ثبت دستی: صاحب چت‌بات مخاطب ثبت می‌کند و بسته پولی را فقط با الصاق فیش فعال می‌کند؛
 *    مدیر کاربر ثبت می‌کند و پلن پولی را فقط با الصاق فیش فعال می‌کند (پلن/بسته رایگان بدون فیش)
 *  - فایل فیش‌ها خصوصی است (uploads/receipts با دسترسی مستقیم بسته) و فقط از صفحه‌های مجاز نمایش داده می‌شود
 */

if (!defined('C2C_MAX_BYTES')) define('C2C_MAX_BYTES', 8 * 1024 * 1024);

function c2c_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.c2c_schema_v1' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(10) NOT NULL DEFAULT 'member',
            owner_uid INT NOT NULL DEFAULT 0,
            bot_id INT NOT NULL DEFAULT 0,
            member_id INT NOT NULL DEFAULT 0,
            user_id INT NOT NULL DEFAULT 0,
            plan_id INT NOT NULL DEFAULT 0,
            plan_name VARCHAR(190) NOT NULL DEFAULT '',
            payment_id INT NOT NULL DEFAULT 0,
            purchase_id INT NOT NULL DEFAULT 0,
            amount INT NOT NULL DEFAULT 0,
            original_amount INT NOT NULL DEFAULT 0,
            code_id INT NOT NULL DEFAULT 0,
            tracking VARCHAR(60) NOT NULL DEFAULT '',
            payer VARCHAR(120) NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            file VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            source VARCHAR(10) NOT NULL DEFAULT 'self',
            reason VARCHAR(255) NOT NULL DEFAULT '',
            decided_by VARCHAR(120) NOT NULL DEFAULT '',
            decided_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY k_owner (owner_uid, status),
            KEY k_scope (scope, status),
            KEY k_member (bot_id, member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[C2C] schema: ' . $e->getMessage()); }
}

function c2c_now($o = 0) { return date('Y-m-d H:i:s', time() + (int)$o); }

// ---------------------------------------------------------------------------
// تنظیمات کارت (سایت: 'site' — چت‌بات: شناسه چت‌بات)
// ---------------------------------------------------------------------------
function c2c_cfg($pdo, $key)
{
    $raw = (string)(biz_settings($pdo)['c2c_' . ($key === 'site' ? 'site' : 'bot_' . (int)$key)] ?? '');
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $j = is_array($j) ? $j : [];
    return ['on' => !empty($j['on']), 'card' => preg_replace('/\D/', '', (string)($j['card'] ?? '')), 'name' => (string)($j['name'] ?? ''),
            'bank' => (string)($j['bank'] ?? ''), 'sheba' => strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($j['sheba'] ?? ''))), 'note' => (string)($j['note'] ?? '')];
}

/** @return string خطا ('' = ذخیره شد) */
function c2c_cfg_save($pdo, $key, array $in)
{
    $fa = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
    $card = preg_replace('/\D/', '', strtr((string)($in['card'] ?? ''), $fa));
    $on = !empty($in['on']);
    if ($card !== '' && strlen($card) !== 16) return 'شماره کارت باید ۱۶ رقم باشد.';
    if ($on && $card === '') return 'برای فعال کردن کارت به کارت، شماره کارت را وارد کنید.';
    $sheba = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', strtr((string)($in['sheba'] ?? ''), $fa)));
    if ($sheba !== '' && !preg_match('/^(IR)?\d{24}$/', $sheba)) return 'شماره شبا معتبر نیست (۲۴ رقم، با یا بدون IR).';
    if ($sheba !== '' && strpos($sheba, 'IR') !== 0) $sheba = 'IR' . $sheba;
    $v = ['on' => $on ? 1 : 0, 'card' => $card, 'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 80), 'bank' => mb_substr(trim((string)($in['bank'] ?? '')), 0, 60),
          'sheba' => $sheba, 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 400)];
    biz_set($pdo, 'c2c_' . ($key === 'site' ? 'site' : 'bot_' . (int)$key), json_encode($v, JSON_UNESCAPED_UNICODE));
    return '';
}

function c2c_card_fmt($card)
{
    $c = preg_replace('/\D/', '', (string)$card);
    return strlen($c) === 16 ? implode('-', str_split($c, 4)) : $c;
}

function c2c_bot_on($pdo, $bot)
{
    if (!$bot || empty($bot['id'])) return false;
    $c = c2c_cfg($pdo, (int)$bot['id']);
    return $c['on'] && strlen($c['card']) === 16;
}

function c2c_site_on($pdo)
{
    $c = c2c_cfg($pdo, 'site');
    return $c['on'] && strlen($c['card']) === 16;
}

/** اطلاعات نمایشی کارت (برای مخاطب / کاربر) */
function c2c_public($pdo, $key)
{
    $c = c2c_cfg($pdo, $key);
    if (!$c['on'] || strlen($c['card']) !== 16) return null;
    return ['card' => c2c_card_fmt($c['card']), 'name' => $c['name'], 'bank' => $c['bank'], 'sheba' => $c['sheba'], 'note' => $c['note']];
}

// ---------------------------------------------------------------------------
// فایل فیش (خصوصی)
// ---------------------------------------------------------------------------
function c2c_dir()
{
    $d = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/') . '/receipts';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
    return $d;
}

/**
 * بررسی و ذخیره تصویر فیش: فقط تصویر واقعی (JPG/PNG/WEBP/GIF)؛ با GD دوباره ساخته می‌شود (حذف اطلاعات اضافه و کد مخرب) و حداکثر ۲۰۰۰ پیکسل
 * @return array ['ok', 'file', 'error']
 */
function c2c_store_image($bytes)
{
    $bytes = (string)$bytes;
    if ($bytes === '' || strlen($bytes) < 200) return ['ok' => false, 'error' => 'تصویر فیش را انتخاب کنید.'];
    if (strlen($bytes) > C2C_MAX_BYTES) return ['ok' => false, 'error' => 'حجم تصویر فیش زیاد است (حداکثر ۸ مگابایت).'];
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return ['ok' => false, 'error' => 'فایل فیش باید تصویر باشد (JPG، PNG یا WEBP).'];
    $out = $bytes; $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime']];
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bytes))) {
        $w = imagesx($im); $h = imagesy($im);
        $k = min(1, 2000 / max(1, max($w, $h)));
        $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagejpeg($dst, null, 85); $jpg = ob_get_clean();
        imagedestroy($im); imagedestroy($dst);
        if ($jpg) { $out = $jpg; $ext = 'jpg'; }
    }
    $name = date('Ym') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (@file_put_contents(c2c_dir() . '/' . $name, $out) === false) return ['ok' => false, 'error' => 'ذخیره فیش ممکن نشد (دسترسی پوشه uploads را بررسی کنید).'];
    return ['ok' => true, 'file' => $name, 'error' => ''];
}

/** خواندن فایل آپلودشده فرم ($_FILES) → رشته بایت‌ها؛ null = فایلی انتخاب نشده؛ ['error' => ...] = خطا */
function c2c_upload_bytes($f)
{
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) return ['error' => in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'حجم تصویر فیش از سقف آپلود هاست بیشتر است.' : 'آپلود فیش انجام نشد.'];
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return ['error' => 'آپلود فیش انجام نشد.'];
    return (string)file_get_contents($f['tmp_name']);
}

/** نمایش تصویر فیش (پس از بررسی دسترسی توسط صفحه فراخواننده) */
function c2c_serve($row)
{
    $f = $row ? c2c_dir() . '/' . basename((string)$row['file']) : '';
    if (!$row || $row['file'] === '' || !is_file($f)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'image/jpeg'));
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($f);
    exit;
}

// ---------------------------------------------------------------------------
// ثبت و خواندن
// ---------------------------------------------------------------------------
function c2c_create($pdo, array $r)
{
    c2c_schema($pdo);
    $cols = ['scope', 'owner_uid', 'bot_id', 'member_id', 'user_id', 'plan_id', 'plan_name', 'payment_id', 'purchase_id', 'amount', 'original_amount', 'code_id',
             'tracking', 'payer', 'note', 'file', 'status', 'source', 'reason', 'decided_by', 'decided_at', 'created_at'];
    $def = ['scope' => 'member', 'owner_uid' => 0, 'bot_id' => 0, 'member_id' => 0, 'user_id' => 0, 'plan_id' => 0, 'plan_name' => '', 'payment_id' => 0, 'purchase_id' => 0,
            'amount' => 0, 'original_amount' => 0, 'code_id' => 0, 'tracking' => '', 'payer' => '', 'note' => '', 'file' => '', 'status' => 'pending', 'source' => 'self',
            'reason' => '', 'decided_by' => '', 'decided_at' => null, 'created_at' => c2c_now()];
    $r = array_merge($def, array_intersect_key($r, $def));
    $r['tracking'] = mb_substr(trim((string)$r['tracking']), 0, 60);
    $r['payer'] = mb_substr(trim((string)$r['payer']), 0, 120);
    $r['note'] = mb_substr(trim((string)$r['note']), 0, 500);
    $r['plan_name'] = mb_substr((string)$r['plan_name'], 0, 190);
    $pdo->prepare("INSERT INTO saas_receipts (" . implode(',', $cols) . ") VALUES(" . implode(',', array_fill(0, count($cols), '?')) . ")")
        ->execute(array_map(fn($c) => $r[$c], $cols));
    return (int)$pdo->lastInsertId();
}

function c2c_get($pdo, $id)
{
    c2c_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM saas_receipts WHERE id=?");
    $s->execute([(int)$id]);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** فهرست فیش‌ها: $f = scope, owner_uid, bot_id, member_id, user_id, status (رشته یا آرایه) */
function c2c_list($pdo, array $f, $limit = 200)
{
    c2c_schema($pdo);
    $w = []; $p = [];
    foreach (['scope', 'owner_uid', 'bot_id', 'member_id', 'user_id', 'payment_id'] as $k) if (isset($f[$k])) { $w[] = "$k=?"; $p[] = $f[$k]; }
    if (!empty($f['status'])) {
        $st = (array)$f['status'];
        $w[] = 'status IN (' . implode(',', array_fill(0, count($st), '?')) . ')';
        $p = array_merge($p, $st);
    }
    try {
        $s = $pdo->prepare("SELECT * FROM saas_receipts" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT " . max(1, min(2000, (int)$limit)));
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

function c2c_pending_count($pdo, array $f)
{
    c2c_schema($pdo);
    $w = ["status='pending'"]; $p = [];
    foreach (['scope', 'owner_uid', 'bot_id'] as $k) if (isset($f[$k])) { $w[] = "$k=?"; $p[] = $f[$k]; }
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM saas_receipts WHERE " . implode(' AND ', $w));
        $s->execute($p);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

function c2c_status_label($st)
{
    return ['pending' => '⏳ در انتظار بررسی', 'approved' => '✅ تأیید شد', 'rejected' => '❌ رد شد'][$st] ?? $st;
}

/** شماره موبایل یکسان (۰۹xxxxxxxxx) */
function c2c_mobile($m)
{
    $m = preg_replace('/\D/', '', strtr((string)$m, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
    if (strpos($m, '0098') === 0) $m = '0' . substr($m, 4);
    elseif (strpos($m, '98') === 0 && strlen($m) === 12) $m = '0' . substr($m, 2);
    elseif (strlen($m) === 10 && $m[0] === '9') $m = '0' . $m;
    return preg_match('/^09\d{9}$/', $m) ? $m : '';
}

// ---------------------------------------------------------------------------
// مخاطبان چت‌بات
// ---------------------------------------------------------------------------
/** ثبت ردیف خرید (مثل بسته رایگان) و فعال‌سازی */
function c2c_purchase_activate($pdo, $bot, $member, $plan, $amount, $original, $code_id, $ref)
{
    $mobile = (string)($member['mobile'] ?? '');
    $pdo->prepare("INSERT INTO hd_purchases (bot_id, member_id, plan_id, plan_name, amount_toman, original_amount, discount_amount, discount_code_id, messages, daily_limit, days, status, return_url, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending','',?)")
        ->execute([(int)$bot['id'], (int)$member['id'], (int)$plan['id'], $plan['name'], (int)$amount, (int)$original, max(0, (int)$original - (int)$amount), (int)$code_id,
                   (int)$plan['messages'], (int)$plan['daily_limit'], (int)$plan['days'], c2c_now()]);
    $pid = (int)$pdo->lastInsertId();
    try { $pdo->prepare("UPDATE hd_purchases SET flags=?, shared_bots=?, mobile=?, owner_id=? WHERE id=?")->execute([(string)($plan['flags'] ?? 'files,voice'), function_exists('cx_ids_str') ? cx_ids_str(cx_plan_bots($plan)) : '', $mobile, (int)$bot['user_id'], $pid]); } catch (\Throwable $e) {}
    try { $pdo->prepare("UPDATE hd_purchases SET win_msgs=? WHERE id=?")->execute([(int)($plan['win_msgs'] ?? 0), $pid]); } catch (\Throwable $e) {}
    if (function_exists('lim_ensure_schema')) { lim_ensure_schema($pdo); try { $pdo->prepare("UPDATE hd_purchases SET week_limit=?, month_limit=? WHERE id=?")->execute([(int)($plan['week_limit'] ?? 0), (int)($plan['month_limit'] ?? 0), $pid]); } catch (\Throwable $e) {} }
    try { $pdo->prepare("UPDATE hd_purchases SET rem_sms=? WHERE id=?")->execute([(int)($plan['rem_sms'] ?? 0), $pid]); } catch (\Throwable $e) {}
    $st = $pdo->prepare("SELECT * FROM hd_purchases WHERE id=?");
    $st->execute([$pid]);
    hd_activate_purchase($pdo, $bot, $st->fetch(\PDO::FETCH_ASSOC), $ref);
    return $pid;
}

/**
 * مخاطب: ارسال فیش کارت به کارت برای یک بسته (از چت‌بات یا پنل مخاطب)
 * @return array ['ok', 'message' | 'error', 'receipt']
 */
function c2c_member_submit($pdo, $bot, $member, $plan_id, $code, $bytes, $tracking = '', $payer = '')
{
    c2c_schema($pdo);
    if (!empty($member['is_owner'])) return ['ok' => false, 'error' => 'حساب آزمایشی مدیر نیاز به خرید ندارد.'];
    if (!c2c_bot_on($pdo, $bot)) return ['ok' => false, 'error' => 'پرداخت کارت به کارت برای این چت‌بات فعال نیست.'];
    $plan = function_exists('cx_plan_for_bot') ? cx_plan_for_bot($pdo, $bot, (int)$plan_id) : null;
    if (!$plan || (int)$plan['price_toman'] < 1000) return ['ok' => false, 'error' => 'بسته انتخابی معتبر نیست.'];
    $tracking = trim(strtr((string)$tracking, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    $pend = c2c_list($pdo, ['scope' => 'member', 'bot_id' => (int)$bot['id'], 'member_id' => (int)$member['id'], 'status' => 'pending'], 10);
    if (count($pend) >= 3) return ['ok' => false, 'error' => 'چند فیش شما در انتظار بررسی است؛ پس از بررسی آن‌ها فیش جدید بفرستید.'];
    if (function_exists('sec_rate') && sec_rate('c2c|m' . (int)$member['id'], 8, 3600)) return ['ok' => false, 'error' => 'تعداد ارسال فیش زیاد است؛ کمی بعد تلاش کنید.'];
    $q = hd_quote($pdo, $bot, (int)$member['id'], $plan, (string)$code);
    if ($q['error'] !== '') return ['ok' => false, 'error' => $q['error']];
    $img = c2c_store_image($bytes);
    if (!$img['ok']) return ['ok' => false, 'error' => $img['error']];
    $rid = c2c_create($pdo, ['scope' => 'member', 'owner_uid' => (int)$bot['user_id'], 'bot_id' => (int)$bot['id'], 'member_id' => (int)$member['id'],
        'plan_id' => (int)$plan['id'], 'plan_name' => $plan['name'], 'amount' => (int)$q['final'], 'original_amount' => (int)$q['original'], 'code_id' => (int)$q['code_id'],
        'tracking' => $tracking, 'payer' => $payer, 'file' => $img['file'], 'source' => 'self']);
    if (function_exists('biz_notify')) biz_notify($pdo, (int)$bot['user_id'], '🧾 فیش واریزی جدید در «' . $bot['name'] . '»',
        ($member['name'] ?: 'مخاطب') . ' برای بسته «' . $plan['name'] . '» مبلغ ' . number_format((int)$q['final']) . ' تومان کارت به کارت کرده است؛ لطفاً بررسی و تأیید کنید.', 'warning', 'receipts.php?bot=' . (int)$bot['id']);
    return ['ok' => true, 'receipt' => $rid, 'message' => 'فیش شما ثبت شد ✅ پس از بررسی و تأیید، بسته «' . $plan['name'] . '» فعال می‌شود.'];
}

/** فیش‌های اخیر مخاطب (برای نمایش وضعیت در چت‌بات و پنل مخاطب) */
function c2c_member_public($pdo, $bot, $member)
{
    if (!$member || empty($member['id'])) return [];
    $out = [];
    foreach (c2c_list($pdo, ['scope' => 'member', 'bot_id' => (int)$bot['id'], 'member_id' => (int)$member['id']], 10) as $r) {
        if ($r['status'] === 'approved' && strtotime($r['decided_at'] ?: $r['created_at']) < time() - 7 * 86400) continue;
        $out[] = ['id' => (int)$r['id'], 'plan' => $r['plan_name'], 'amount' => (int)$r['amount'], 'status' => $r['status'], 'label' => c2c_status_label($r['status']),
                  'reason' => (string)$r['reason'], 'date' => substr((string)$r['created_at'], 0, 16)];
    }
    return $out;
}

/** صاحب چت‌بات: تأیید فیش مخاطب → فعال شدن بسته */
function c2c_member_approve($pdo, $owner_uid, $rid, $actor = '')
{
    $r = c2c_get($pdo, $rid);
    if (!$r || $r['scope'] !== 'member' || (int)$r['owner_uid'] !== (int)$owner_uid) return ['ok' => false, 'error' => 'فیش یافت نشد.'];
    if ($r['status'] !== 'pending') return ['ok' => false, 'error' => 'این فیش قبلاً بررسی شده است.'];
    $bot = hd_get_bot($pdo, (int)$r['bot_id'], (int)$owner_uid);
    $m = $pdo->prepare("SELECT * FROM hd_members WHERE id=? AND bot_id=?");
    $m->execute([(int)$r['member_id'], (int)$r['bot_id']]);
    $member = $m->fetch(\PDO::FETCH_ASSOC);
    $plan = $bot && function_exists('cx_plan_for_bot') ? cx_plan_for_bot($pdo, $bot, (int)$r['plan_id']) : null;
    if (!$bot || !$member) return ['ok' => false, 'error' => 'چت‌بات یا مخاطب دیگر وجود ندارد.'];
    if (!$plan) return ['ok' => false, 'error' => 'بسته «' . $r['plan_name'] . '» دیگر فعال نیست؛ آن را دوباره فعال کنید یا فیش را رد کنید.'];
    // قفل: فقط یک بار
    $u = $pdo->prepare("UPDATE saas_receipts SET status='approved', decided_by=?, decided_at=? WHERE id=? AND status='pending'");
    $u->execute([mb_substr((string)$actor, 0, 120), c2c_now(), (int)$r['id']]);
    if ($u->rowCount() === 0) return ['ok' => false, 'error' => 'این فیش قبلاً بررسی شده است.'];
    $pid = c2c_purchase_activate($pdo, $bot, $member, $plan, (int)$r['amount'], (int)$r['original_amount'] ?: (int)$r['amount'], (int)$r['code_id'], 'CARD-' . (int)$r['id']);
    $pdo->prepare("UPDATE saas_receipts SET purchase_id=? WHERE id=?")->execute([$pid, (int)$r['id']]);
    return ['ok' => true, 'message' => 'فیش تأیید شد و بسته «' . $plan['name'] . '» برای ' . ($member['name'] ?: $member['mobile']) . ' فعال شد.'];
}

/**
 * صاحب چت‌بات: ثبت مخاطب (یا یافتن مخاطب موجود با همین موبایل) و در صورت انتخاب، فعال‌سازی بسته
 * بسته پولی فقط با الصاق فیش ($bytes) فعال می‌شود؛ بسته رایگان بدون فیش
 * @return array ['ok', 'message' | 'error', 'member_id', 'created']
 */
function c2c_owner_register($pdo, $bot, array $in, $bytes, $actor = '')
{
    c2c_schema($pdo);
    $mobile = c2c_mobile($in['mobile'] ?? '');
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
    if ($mobile === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست (مثلاً ۰۹۱۲۱۲۳۴۵۶۷).'];
    $plan = null;
    if ((int)($in['plan_id'] ?? 0) > 0) {
        $plan = function_exists('cx_plan_for_bot') ? cx_plan_for_bot($pdo, $bot, (int)$in['plan_id']) : null;
        if (!$plan) return ['ok' => false, 'error' => 'بسته انتخابی معتبر نیست.'];
    }
    $paid = $plan && (int)$plan['price_toman'] > 0;
    if ($paid && ($bytes === null || $bytes === '')) return ['ok' => false, 'error' => 'برای فعال کردن بسته پولی، تصویر فیش واریزی را الصاق کنید.'];
    $s = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND mobile=? AND is_owner=0 ORDER BY id ASC LIMIT 1");
    $s->execute([(int)$bot['id'], $mobile]);
    $member = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$member && mb_strlen($name) < 2) return ['ok' => false, 'error' => 'نام مخاطب را وارد کنید.'];
    $img = null;
    if ($paid) { $img = c2c_store_image($bytes); if (!$img['ok']) return ['ok' => false, 'error' => $img['error']]; }
    $created = false;
    if (!$member) {
        $mid = hd_create_member($pdo, (int)$bot['id'], $mobile, $name, true);
        $s->execute([(int)$bot['id'], $mobile]);
        $member = $s->fetch(\PDO::FETCH_ASSOC);
        $created = true;
    } elseif ($name !== '' && $name !== (string)$member['name']) {
        $pdo->prepare("UPDATE hd_members SET name=? WHERE id=?")->execute([$name, (int)$member['id']]);
        $member['name'] = $name;
    }
    $msg = $created ? 'مخاطب «' . $member['name'] . '» ثبت شد.' : 'مخاطب «' . ($member['name'] ?: $mobile) . '» از قبل وجود داشت.';
    if ($plan) {
        $amount = $paid ? max(0, (int)preg_replace('/\D/', '', strtr((string)($in['amount'] ?? ''), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '', '٬' => '']))) : 0;
        if ($paid && $amount <= 0) $amount = (int)hd_plan_price($plan)['price'];
        $pid = c2c_purchase_activate($pdo, $bot, $member, $plan, $amount, (int)$plan['price_toman'], 0, $paid ? 'CARD-MANUAL' : 'FREE-MANUAL');
        if ($paid) {
            $rid = c2c_create($pdo, ['scope' => 'member', 'owner_uid' => (int)$bot['user_id'], 'bot_id' => (int)$bot['id'], 'member_id' => (int)$member['id'],
                'plan_id' => (int)$plan['id'], 'plan_name' => $plan['name'], 'amount' => $amount, 'original_amount' => (int)$plan['price_toman'], 'purchase_id' => $pid,
                'tracking' => (string)($in['tracking'] ?? ''), 'note' => (string)($in['note'] ?? ''), 'file' => $img['file'], 'status' => 'approved', 'source' => 'staff',
                'decided_by' => $actor, 'decided_at' => c2c_now()]);
            $pdo->prepare("UPDATE hd_purchases SET ref_code=? WHERE id=?")->execute(['CARD-' . $rid, $pid]);
        }
        $msg .= ' بسته «' . $plan['name'] . '» فعال شد' . ($paid ? ' (با فیش ' . number_format($amount) . ' تومانی).' : '.');
    }
    return ['ok' => true, 'message' => $msg, 'member_id' => (int)$member['id'], 'created' => $created];
}

// ---------------------------------------------------------------------------
// کاربران سایت (پرداخت پلن/اعتبار/پیامک)
// ---------------------------------------------------------------------------
/** کاربر: ارسال فیش برای یک پرداخت در انتظار (ساخته‌شده در صفحه پرداخت) */
function c2c_user_submit($pdo, $uid, $payment_id, $bytes, $tracking = '', $payer = '')
{
    c2c_schema($pdo);
    if (!c2c_site_on($pdo)) return ['ok' => false, 'error' => 'پرداخت کارت به کارت فعال نیست.'];
    $s = $pdo->prepare("SELECT * FROM saas_payments WHERE id=? AND user_id=?");
    $s->execute([(int)$payment_id, (int)$uid]);
    $p = $s->fetch(\PDO::FETCH_ASSOC);
    if (!$p || $p['status'] !== 'pending') return ['ok' => false, 'error' => 'این پرداخت معتبر نیست یا قبلاً بررسی شده است.'];
    foreach (c2c_list($pdo, ['scope' => 'user', 'payment_id' => (int)$p['id']], 5) as $x) if ($x['status'] === 'pending') return ['ok' => false, 'error' => 'فیش این پرداخت قبلاً ارسال شده و در انتظار بررسی است.'];
    if (function_exists('sec_rate') && sec_rate('c2c|u' . (int)$uid, 8, 3600)) return ['ok' => false, 'error' => 'تعداد ارسال فیش زیاد است؛ کمی بعد تلاش کنید.'];
    $img = c2c_store_image($bytes);
    if (!$img['ok']) return ['ok' => false, 'error' => $img['error']];
    $plan = (int)($p['plan_id'] ?? 0) > 0 ? saas_get_plan($pdo, (int)$p['plan_id']) : null;
    $rid = c2c_create($pdo, ['scope' => 'user', 'owner_uid' => 0, 'user_id' => (int)$uid, 'plan_id' => (int)($p['plan_id'] ?? 0), 'plan_name' => $plan['name'] ?? (string)$p['description'],
        'payment_id' => (int)$p['id'], 'amount' => (int)$p['amount_toman'], 'original_amount' => (int)($p['original_amount'] ?? $p['amount_toman']),
        'tracking' => $tracking, 'payer' => $payer, 'file' => $img['file'], 'source' => 'self', 'note' => (string)$p['description']]);
    if (function_exists('biz_notify')) biz_notify($pdo, (int)$uid, '🧾 فیش شما ثبت شد', 'پس از بررسی و تأیید مدیر، «' . $p['description'] . '» انجام می‌شود.', 'info', 'billing.php');
    return ['ok' => true, 'receipt' => $rid, 'message' => 'فیش شما ثبت شد ✅ پس از بررسی و تأیید، «' . $p['description'] . '» انجام می‌شود.'];
}

/** مدیر: تأیید فیش کاربر → همان مسیر پرداخت موفق (فعال‌سازی پلن، اعتبار، پیامک، کمیسیون و ...) */
function c2c_user_approve($pdo, $rid, $actor = 'مدیر')
{
    $r = c2c_get($pdo, $rid);
    if (!$r || $r['scope'] !== 'user') return ['ok' => false, 'error' => 'فیش یافت نشد.'];
    if ($r['status'] !== 'pending') return ['ok' => false, 'error' => 'این فیش قبلاً بررسی شده است.'];
    $u = $pdo->prepare("UPDATE saas_receipts SET status='approved', decided_by=?, decided_at=? WHERE id=? AND status='pending'");
    $u->execute([mb_substr((string)$actor, 0, 120), c2c_now(), (int)$r['id']]);
    if ($u->rowCount() === 0) return ['ok' => false, 'error' => 'این فیش قبلاً بررسی شده است.'];
    $res = biz_complete_payment($pdo, (int)$r['payment_id'], 'CARD-' . (int)$r['id']);
    return ['ok' => !empty($res['ok']), 'message' => 'فیش تأیید شد. ' . ($res['message'] ?? ''), 'error' => $res['message'] ?? ''];
}

/** رد فیش (مخاطب: صاحب چت‌بات — کاربر: مدیر) */
function c2c_reject($pdo, $rid, $reason, $actor, $owner_uid = null)
{
    $r = c2c_get($pdo, $rid);
    if (!$r || ($owner_uid !== null && ($r['scope'] !== 'member' || (int)$r['owner_uid'] !== (int)$owner_uid)) || ($owner_uid === null && $r['scope'] !== 'user')) return ['ok' => false, 'error' => 'فیش یافت نشد.'];
    $reason = mb_substr(trim((string)$reason), 0, 250);
    if ($reason === '') $reason = 'واریز تأیید نشد';
    $u = $pdo->prepare("UPDATE saas_receipts SET status='rejected', reason=?, decided_by=?, decided_at=? WHERE id=? AND status='pending'");
    $u->execute([$reason, mb_substr((string)$actor, 0, 120), c2c_now(), (int)$r['id']]);
    if ($u->rowCount() === 0) return ['ok' => false, 'error' => 'این فیش قبلاً بررسی شده است.'];
    if ($r['scope'] === 'user') {
        $pdo->prepare("UPDATE saas_payments SET status='failed' WHERE id=? AND status='pending'")->execute([(int)$r['payment_id']]);
        if (function_exists('biz_notify')) biz_notify($pdo, (int)$r['user_id'], '❌ فیش واریزی شما تأیید نشد', 'علت: ' . $reason . ' — در صورت نیاز با پشتیبانی تماس بگیرید.', 'danger', 'billing.php');
    }
    return ['ok' => true, 'message' => 'فیش رد شد.'];
}

/** مدیر: ثبت فیش هنگام فعال کردن دستی پلن پولی برای کاربر (از صفحه کاربران) */
function c2c_admin_plan_receipt($pdo, $uid, $plan, $bytes, array $in, $actor = 'مدیر')
{
    c2c_schema($pdo);
    $img = c2c_store_image($bytes);
    if (!$img['ok']) return ['ok' => false, 'error' => $img['error']];
    $amount = (int)preg_replace('/\D/', '', strtr((string)($in['amount'] ?? ''), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    $rid = c2c_create($pdo, ['scope' => 'user', 'user_id' => (int)$uid, 'plan_id' => (int)$plan['id'], 'plan_name' => $plan['name'], 'amount' => $amount ?: (int)$plan['price_toman'],
        'original_amount' => (int)$plan['price_toman'], 'tracking' => (string)($in['tracking'] ?? ''), 'note' => 'فعال‌سازی دستی پلن توسط مدیر', 'file' => $img['file'],
        'status' => 'approved', 'source' => 'staff', 'decided_by' => $actor, 'decided_at' => c2c_now()]);
    return ['ok' => true, 'receipt' => $rid];
}

/** HTML جعبه شماره کارت (صفحه پرداخت و پنل‌ها) */
function c2c_card_html($pub, $amount = 0)
{
    if (!$pub) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<div class="c2c-card" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:14px;padding:16px 18px;margin:10px 0;line-height:2">'
        . ($amount > 0 ? '<div style="font-size:13px;opacity:.85">مبلغ قابل واریز</div><div style="font-size:20px;font-weight:800">' . number_format((int)$amount) . ' تومان</div>' : '')
        . '<div style="font-size:13px;opacity:.85;margin-top:6px">شماره کارت' . ($pub['bank'] !== '' ? ' — ' . $h($pub['bank']) : '') . '</div>'
        . '<div dir="ltr" style="font-size:21px;font-weight:800;letter-spacing:2px;text-align:center;font-family:monospace">' . $h($pub['card']) . '</div>'
        . ($pub['name'] !== '' ? '<div style="font-size:13px">به نام: <b>' . $h($pub['name']) . '</b></div>' : '')
        . ($pub['sheba'] !== '' ? '<div style="font-size:12px;opacity:.9" dir="ltr">' . $h($pub['sheba']) . '</div>' : '')
        . ($pub['note'] !== '' ? '<div style="font-size:12.5px;opacity:.9;margin-top:4px">' . nl2br($h($pub['note'])) . '</div>' : '')
        . '</div>';
}
