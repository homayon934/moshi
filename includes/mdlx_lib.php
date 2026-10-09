<?php
/**
 * دسترسی به مدل‌ها (نسخه ۵۹)
 *  - پلن‌های سایت: به‌جای «فهرست مجاز» (که مدل‌های تازه را نمی‌گرفت) «فهرست بسته‌شده» نگه داشته می‌شود؛
 *    هر مدلی که مدیر تعریف کند خودکار در همه پلن‌ها و چت‌بات‌ها فعال است، مگر مدیر آن را برای پلنی بردارد.
 *    (پلن‌های دلخواه که کاربر خودش مدل‌هایش را انتخاب و پرداخت کرده، همچنان فهرست مجاز دارند.)
 *  - بسته‌های فروش صاحب چت‌بات (hd_member_plans.model_cfg): برای هر مدل «غیرفعال» یا «سقف تعداد استفاده» در هر خرید
 *    (پیش‌فرض = فعال و نامحدود؛ پس مدل تازه خودکار در بسته‌ها هم فعال است)
 *  - شمارش استفاده هر مدل در هر خرید (hd_model_use)
 *  - مدل پیش‌فرض ساخت تصویر/ویدیو هر چت‌بات (hd_bots.img_model_id / vid_model_id) و انتخاب مدل توسط مخاطب
 *  - اعلان «مدل‌های جدید اضافه شد» برای صاحبان چت‌بات
 */

function mx_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $dir = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') : '';
    $flag = $dir !== '' ? $dir . '/.mx_schema_v1' : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        saas_add_column_if_missing($pdo, 'saas_plans', 'blocked_models', "VARCHAR(2000) NOT NULL DEFAULT ''");
        foreach ([['hd_member_plans', 'model_cfg', "VARCHAR(3000) NOT NULL DEFAULT ''"], ['hd_bots', 'img_model_id', 'INT NOT NULL DEFAULT 0'], ['hd_bots', 'vid_model_id', 'INT NOT NULL DEFAULT 0']] as $c) {
            try { saas_add_column_if_missing($pdo, $c[0], $c[1], $c[2]); } catch (\Throwable $e) { error_log('[MX] ' . $c[0] . '.' . $c[1] . ': ' . $e->getMessage()); }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS hd_model_use (
            purchase_id INT NOT NULL,
            model_id INT NOT NULL,
            n INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL,
            PRIMARY KEY (purchase_id, model_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // تبدیل یک‌باره «فهرست مجاز» پلن‌های عادی به «فهرست بسته‌شده»: همان مدل‌هایی که الان مجازند مجاز می‌مانند و مدل‌های بعدی خودکار اضافه می‌شوند
        $ids = [];
        foreach ($pdo->query("SELECT id, category, parent_id FROM saas_ai_models")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $m)
            if ((int)$m['parent_id'] === 0 && in_array($m['category'], ['text', 'image', 'video'], true)) $ids[] = (int)$m['id'];
        foreach ($pdo->query("SELECT * FROM saas_plans")->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $p) {
            if (!empty($p['is_custom'])) continue;
            $al = array_filter(array_map('intval', explode(',', (string)($p['allowed_models'] ?? ''))));
            if (!$al) continue;
            $bl = array_values(array_diff($ids, $al));
            $pdo->prepare("UPDATE saas_plans SET blocked_models=?, allowed_models='' WHERE id=?")->execute([implode(',', $bl), (int)$p['id']]);
        }
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[MX] schema: ' . $e->getMessage()); }
}

function mx_ids($s) { return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$s))))); }

/** شناسه مدل اصلی (مدل جایگزین → مدل اصلی‌اش) */
function mx_main_id($m) { return $m ? ((int)($m['parent_id'] ?? 0) ?: (int)$m['id']) : 0; }

// ---------------------------------------------------------------------------
// تنظیم مدل‌های بسته فروش: {"12":{"off":1},"15":{"max":20}}
// ---------------------------------------------------------------------------
function mx_cfg_parse($json)
{
    $j = is_array($json) ? $json : json_decode((string)$json, true);
    $out = [];
    if (is_array($j)) foreach ($j as $k => $v) {
        if ((int)$k <= 0 || !is_array($v)) continue;
        $off = !empty($v['off']); $max = max(0, (int)($v['max'] ?? 0));
        if ($off || $max > 0) $out[(int)$k] = ['off' => $off, 'max' => $max];
    }
    return $out;
}

/** از فرم صفحه بسته: mcfg_on[id]=1 و mcfg_max[id]=N برای مدل‌های نمایش‌داده‌شده (mcfg_ids) */
function mx_cfg_from_post(array $post)
{
    $out = [];
    foreach (array_map('intval', (array)($post['mcfg_ids'] ?? [])) as $id) {
        if ($id <= 0) continue;
        $off = empty($post['mcfg_on'][$id]);
        $max = max(0, min(1000000, (int)($post['mcfg_max'][$id] ?? 0)));
        if ($off) $out[$id] = ['off' => 1];
        elseif ($max > 0) $out[$id] = ['max' => $max];
    }
    $s = json_encode($out ?: new \stdClass());
    return strlen($s) > 2900 ? '{}' : $s;
}

/** ردیف بسته فروش (تنظیم تازه مدل‌ها) */
function mx_member_plan($pdo, $plan_id)
{
    $plan_id = (int)$plan_id;
    if (!$plan_id) return null;
    try { $s = $pdo->prepare("SELECT * FROM hd_member_plans WHERE id=?"); $s->execute([$plan_id]); return $s->fetch(\PDO::FETCH_ASSOC) ?: null; }
    catch (\Throwable $e) { return null; }
}

function mx_use_map($pdo, $purchase_id)
{
    try {
        $s = $pdo->prepare("SELECT model_id, n FROM hd_model_use WHERE purchase_id=?");
        $s->execute([(int)$purchase_id]);
        $o = [];
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $o[(int)$r['model_id']] = (int)$r['n'];
        return $o;
    } catch (\Throwable $e) { return []; }
}

/**
 * قوانین مدل برای مخاطب: فقط وقتی با بسته خریداری‌شده پیام می‌دهد (صاحب ربات، چت رایگان و شارژ هدیه محدودیت بسته ندارند)
 * @return array|null  null = بدون محدودیت
 */
function mx_gate($pdo, $bot, $member, $quota = null)
{
    if (!$member || !empty($member['is_owner'])) return null;
    mx_schema($pdo);
    $quota = $quota ?: hd_quota($pdo, $bot, $member);
    if ((int)($quota['paid_left'] ?? 0) <= 0 && (int)($quota['credit_left'] ?? 0) <= 0) return null;
    $ps = [];
    foreach (hd_active_purchases($pdo, $bot['id'], $member['id']) as $p) {
        if (function_exists('mcr_purchase_live') ? !mcr_purchase_live($p) : (int)$p['used'] >= (int)$p['messages']) continue;
        $pl = mx_member_plan($pdo, (int)$p['plan_id']);
        $ps[] = ['id' => (int)$p['id'], 'name' => (string)$p['plan_name'], 'cfg' => mx_cfg_parse($pl['model_cfg'] ?? ''), 'use' => mx_use_map($pdo, (int)$p['id'])];
    }
    return $ps ?: null;
}

/**
 * آیا مخاطب می‌تواند از این مدل استفاده کند؟
 * @return array ['ok', 'pid' (خریدی که استفاده به حسابش ثبت می‌شود), 'left' (-1 = نامحدود), 'why' (off | limit)]
 */
function mx_check($gate, $mid)
{
    $mid = (int)$mid;
    if (!$gate) return ['ok' => true, 'pid' => 0, 'left' => -1, 'why' => ''];
    $why = 'off'; $best = null;
    foreach ($gate as $p) {
        $c = $p['cfg'][$mid] ?? ['off' => false, 'max' => 0];
        if ($c['off']) continue;
        if ($c['max'] <= 0) return ['ok' => true, 'pid' => $p['id'], 'left' => -1, 'why' => ''];
        $left = $c['max'] - (int)($p['use'][$mid] ?? 0);
        if ($left > 0) { if (!$best) $best = ['ok' => true, 'pid' => $p['id'], 'left' => $left, 'why' => '']; }
        else $why = 'limit';
    }
    return $best ?: ['ok' => false, 'pid' => 0, 'left' => 0, 'why' => $why];
}

/** ثبت یک بار استفاده از مدل (برای سقف تعداد بسته) */
function mx_count($pdo, $gate, $mid, $n = 1)
{
    $mid = (int)$mid;
    if (!$gate || $mid <= 0) return;
    $c = mx_check($gate, $mid);
    if (!$c['ok'] || !$c['pid']) return;
    try {
        $u = $pdo->prepare("UPDATE hd_model_use SET n = n + ?, updated_at=? WHERE purchase_id=? AND model_id=?");
        $u->execute([(int)$n, date('Y-m-d H:i:s'), $c['pid'], $mid]);
        if ($u->rowCount() === 0) $pdo->prepare("INSERT INTO hd_model_use (purchase_id, model_id, n, updated_at) VALUES(?,?,?,?)")->execute([$c['pid'], $mid, (int)$n, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) { error_log('[MX] count: ' . $e->getMessage()); }
}

/** پیام خطا برای مدلی که در بسته مجاز نیست */
function mx_why_text($why, $title = '')
{
    $t = $title !== '' ? '«' . $title . '»' : 'این مدل';
    return $why === 'limit' ? 'سقف استفاده از ' . $t . ' در بسته شما تمام شده است؛ مدل دیگری انتخاب کنید یا بسته را تمدید/ارتقا دهید.'
                            : $t . ' در بسته فعلی شما فعال نیست؛ مدل دیگری انتخاب کنید یا بسته را ارتقا دهید.';
}

// ---------------------------------------------------------------------------
// ساخت تصویر / ویدیو در چت‌بات: مدل‌های مجاز مخاطب و انتخاب مدل
// ---------------------------------------------------------------------------
/** مدل‌های تصویر/ویدیوی قابل استفاده مخاطب (پلن صاحب ربات + تنظیم بسته) — هر مورد با 'left' */
function mx_gen_models($pdo, $bot, $member, $kind, $gate = false)
{
    $models = function_exists('cx_gen_models') ? cx_gen_models($pdo, $bot, $kind) : [];
    if ($gate === false) $gate = mx_gate($pdo, $bot, $member);
    $out = [];
    foreach ($models as $m) { $c = mx_check($gate, (int)$m['id']); if ($c['ok']) { $m['_left'] = $c['left']; $out[] = $m; } }
    return $out;
}

/** مدل انتخابی: انتخاب مخاطب (اگر صاحب ربات اجازه داده) ← پیش‌فرض ربات ← اولین مدل؛ خروجی: فهرست با مدل انتخابی در ابتدا */
function mx_gen_order($bot, $member, array $models, $kind, $want = 0)
{
    if (!$models) return [];
    $want = (int)$want;
    $can = !empty($member['is_owner']) || !empty($bot['allow_model_choice']);
    $def = (int)($kind === 'video' ? ($bot['vid_model_id'] ?? 0) : ($bot['img_model_id'] ?? 0));
    $pick = 0;
    foreach ($models as $m) if ($can && $want && (int)$m['id'] === $want) $pick = $want;
    if (!$pick) foreach ($models as $m) if ($def && (int)$m['id'] === $def) $pick = $def;
    if (!$pick) return array_values($models);
    usort($models, fn($a, $b) => ((int)$b['id'] === $pick) <=> ((int)$a['id'] === $pick));
    return array_values($models);
}

/** فهرست انتخاب مدل تصویر/ویدیو برای صفحه چت */
function mx_gen_public($pdo, $bot, $member, $gate = false)
{
    $out = ['image' => [], 'video' => []];
    $def = ['image' => 0, 'video' => 0];
    if ($gate === false) $gate = mx_gate($pdo, $bot, $member);
    foreach (['image', 'video'] as $k) {
        $ms = mx_gen_order($bot, $member, mx_gen_models($pdo, $bot, $member, $k, $gate), $k);
        if (!$ms) continue;
        $def[$k] = (int)$ms[0]['id'];
        if (count($ms) < 2 || (empty($bot['allow_model_choice']) && empty($member['is_owner']))) continue;
        // نسخه ۷۶: فهرست انتخاب از ارزان‌ترین به گران‌ترین (مدل پیش‌فرض همان قبلی است و جداگانه علامت می‌خورد)
        foreach ((function_exists('biz_models_by_price') ? biz_models_by_price($pdo, $ms) : $ms) as $m) $out[$k][] = ['id' => (int)$m['id'], 'title' => $m['title'], 'desc' => (string)$m['description'], 'left' => (int)$m['_left']];
    }
    return ['gen_models' => $out, 'gen_model' => $def];
}

// ---------------------------------------------------------------------------
// تعداد مدل‌ها (اصلی و جایگزین) برای نمایش پلن
// ---------------------------------------------------------------------------
/** @return array ['main' => n, 'backup' => n] */
function mx_count_models($pdo, array $models)
{
    $main = count($models); $backup = 0;
    if ($models && function_exists('biz_model_children')) foreach ($models as $m) $backup += count(biz_model_children($pdo, $m));
    return ['main' => $main, 'backup' => $backup];
}

function mx_count_text(array $c, $unit = 'مدل')
{
    $fa = fn($n) => strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    return $fa($c['main']) . ' ' . $unit . ($c['backup'] > 0 ? ' (+' . $fa($c['backup']) . ' مدل جایگزین برای پایداری)' : '');
}

// ---------------------------------------------------------------------------
// اعلان «مدل‌های جدید» برای صاحبان چت‌بات
// ---------------------------------------------------------------------------
/** مدل‌های تازه‌ای که کاربر هنوز ندیده (فقط مدل‌های مجاز پلن خودش) */
function mx_new_models($pdo, $user, $plan)
{
    if (!$user || empty($user['id']) || !function_exists('biz_plan_models')) return [];
    $k = 'mseen_' . (int)$user['id'];
    $all = array_merge(biz_plan_models($pdo, $plan, 'text'), biz_plan_models($pdo, $plan, 'image'), biz_plan_models($pdo, $plan, 'video'));
    if (!$all) return [];
    $max = max(array_map(fn($m) => (int)$m['id'], $all));
    $seen = biz_settings($pdo)[$k] ?? null;
    if ($seen === null || $seen === '') { biz_set($pdo, $k, $max); return []; }   // کاربر تازه: از این به بعد
    return array_values(array_filter($all, fn($m) => (int)$m['id'] > (int)$seen));
}

function mx_seen($pdo, $uid, $plan)
{
    if (!function_exists('biz_plan_models')) return;
    $all = array_merge(biz_plan_models($pdo, $plan, 'text'), biz_plan_models($pdo, $plan, 'image'), biz_plan_models($pdo, $plan, 'video'));
    $max = $all ? max(array_map(fn($m) => (int)$m['id'], $all)) : 0;
    biz_set($pdo, 'mseen_' . (int)$uid, $max);
}
