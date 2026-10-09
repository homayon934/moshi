<?php
/**
 * ارسال پیام و یادآور به مخاطبان (فردی یا گروهی) + جلسات مشاوره (نسخه ۴۰)
 *
 *  - پیام/یادآور به یک یا چند مخاطب چت‌بات‌ها، همزمان برای همکار مرتبط (مشاور اختصاصی همان مخاطب) و همکاران انتخابی.
 *    روش‌ها: اعلان داخل سایت/چت، اعلان مرورگر (Web Push) و پیامک (از اعتبار پیامک صاحب حساب).
 *    «ارسال الان» یا «یادآور در زمان مشخص» (با هشدارهای پیش از موعد). ارسال واقعی با موتور یادآورها (rem_lib).
 *  - جلسه مشاوره: برای مخاطب و مشاور (صاحب حساب یا همکار)؛ یادآوری خودکار به هر دو؛ تغییر زمان و لغو با اطلاع‌رسانی.
 *  - امکانات پلن: has_bulk_msg (ارسال پیام و یادآور به مخاطبان) و has_sessions (جلسات مشاوره).
 *
 * این فایل از انتهای biz_lib.php بارگذاری می‌شود.
 */

if (!defined('MSG_SCHEMA_VERSION')) define('MSG_SCHEMA_VERSION', 1);
if (!defined('MSG_MAX_RECIPIENTS')) define('MSG_MAX_RECIPIENTS', 500);   // حداکثر گیرنده در هر ارسال
if (!defined('MSG_MAX_DAY')) define('MSG_MAX_DAY', 3000);               // حداکثر گیرنده در روز برای هر حساب

function cm_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.msg_schema_v' . MSG_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    if (function_exists('rem_ensure_schema')) rem_ensure_schema($pdo);
    $ok = true;
    $has = function ($t, $c = '') use ($pdo) { try { return $pdo->query("SELECT " . ($c !== '' ? "`$c`" : '1') . " FROM `$t` LIMIT 1") !== false; } catch (\Throwable $e) { return false; } };
    $col = function ($t, $c, $d) use ($pdo, &$ok) { try { saas_add_column_if_missing($pdo, $t, $c, $d); } catch (\Throwable $e) { $ok = false; error_log('[MSG] column ' . $t . '.' . $c . ': ' . $e->getMessage()); } };
    if ($has('saas_reminders')) {
        $col('saas_reminders', 'batch', "VARCHAR(24) NOT NULL DEFAULT ''");
        $col('saas_reminders', 'is_msg', 'TINYINT(1) NOT NULL DEFAULT 0');
    } else $ok = false;
    if ($has('saas_plans')) {
        $new_sess = !$has('saas_plans', 'has_sessions');
        $col('saas_plans', 'has_bulk_msg', 'TINYINT(1) NOT NULL DEFAULT 1');
        $col('saas_plans', 'has_sessions', 'TINYINT(1) NOT NULL DEFAULT 1');
        // پیش‌فرض: جلسات مشاوره فقط در پلن‌هایی که چت‌بات تخصصی (مشاوره) دارند؛ مدیر می‌تواند تغییر دهد
        if ($new_sess && $has('saas_plans', 'has_expert_bot')) { try { $pdo->exec("UPDATE saas_plans SET has_sessions = has_expert_bot"); } catch (\Throwable $e) {} }
    } else $ok = false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_uid INT NOT NULL,
            bot_id INT NOT NULL DEFAULT 0,
            member_id INT NOT NULL,
            member_name VARCHAR(150) NOT NULL DEFAULT '',
            cons_kind VARCHAR(10) NOT NULL DEFAULT 'user',
            cons_id INT NOT NULL DEFAULT 0,
            cons_name VARCHAR(150) NOT NULL DEFAULT '',
            starts_at DATETIME NOT NULL,
            minutes INT NOT NULL DEFAULT 60,
            place VARCHAR(200) NOT NULL DEFAULT '',
            member_note VARCHAR(500) NOT NULL DEFAULT '',
            cons_note VARCHAR(1000) NOT NULL DEFAULT '',
            leads VARCHAR(80) NOT NULL DEFAULT '1440,60',
            ch_push TINYINT(1) NOT NULL DEFAULT 1,
            sms_member TINYINT(1) NOT NULL DEFAULT 0,
            sms_cons TINYINT(1) NOT NULL DEFAULT 0,
            rem_member INT NOT NULL DEFAULT 0,
            rem_cons INT NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
            creator_kind VARCHAR(10) NOT NULL DEFAULT 'user',
            creator_id INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_owner (owner_uid, starts_at),
            INDEX idx_member (member_id),
            INDEX idx_cons (cons_kind, cons_id, starts_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { $ok = false; error_log('[MSG] table: ' . $e->getMessage()); }
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;
}

// =============================================================================
// دسترسی
// =============================================================================
/** امکان پلن صاحب حساب: 'has_bulk_msg' | 'has_sessions' */
function cm_plan_ok($pdo, array $ctx, $feature)
{
    cm_ensure_schema($pdo);
    return function_exists('ex_owner_allows') ? ex_owner_allows($pdo, (int)$ctx['owner_uid'], $feature) : true;
}

/** این نقش به مخاطبان دسترسی دارد؟ (صاحب حساب؛ همکار با مجوز مدیریت چت‌بات یا مشاوره) */
function cm_role_ok(array $ctx)
{
    if ($ctx['kind'] === 'user') return true;
    if ($ctx['kind'] !== 'team') return false;
    $p = (array)($ctx['perms'] ?? []);
    return in_array('bots_manage', $p, true) || in_array('consult', $p, true);
}

/** شرط SQL مخاطبان قابل‌مشاهده برای این نقش (جدول با نام m) */
function cm_member_scope(array $ctx, array &$p)
{
    $p[] = (int)$ctx['owner_uid'];
    $w = "b.user_id=? AND m.is_owner=0";
    if ($ctx['kind'] === 'team' && !in_array('bots_manage', (array)($ctx['perms'] ?? []), true)) {
        // مشاور: فقط مراجعان خودش و مراجعان بدون مشاور اختصاصی که اجازه نظارت داده‌اند
        $p[] = (int)$ctx['id'];
        $w .= " AND (m.consultant_id=? OR (m.consultant_id=0 AND m.consent_share=1))";
    }
    return $w;
}

/** چت‌بات‌های صاحب حساب */
function cm_bots($pdo, array $ctx)
{
    try {
        $s = $pdo->prepare("SELECT id, name FROM hd_bots WHERE user_id=? ORDER BY id");
        $s->execute([(int)$ctx['owner_uid']]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

/** همکاران فعال صاحب حساب */
function cm_colleagues($pdo, array $ctx)
{
    try {
        $s = $pdo->prepare("SELECT id, name, title, mobile FROM saas_team WHERE owner_id=? AND is_active=1 ORDER BY name");
        $s->execute([(int)$ctx['owner_uid']]);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

/**
 * فهرست مخاطبان برای انتخاب
 * $f: bot_id، q (نام/موبایل)، cons (شناسه همکار؛ -1 = بدون مشاور)، sub (۱ = فقط دارای اشتراک فعال)
 */
function cm_members($pdo, array $ctx, array $f = [], $limit = 1000)
{
    if (!cm_role_ok($ctx)) return [];
    $p = [];
    $w = cm_member_scope($ctx, $p);
    if ((int)($f['bot_id'] ?? 0) > 0) { $w .= " AND m.bot_id=?"; $p[] = (int)$f['bot_id']; }
    $q = trim(function_exists('rem_en') ? rem_en((string)($f['q'] ?? '')) : (string)($f['q'] ?? ''));
    if ($q !== '') { $w .= " AND (m.name LIKE ? OR m.mobile LIKE ?)"; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; }
    if (isset($f['cons']) && $f['cons'] !== '' && $f['cons'] !== null) {
        if ((int)$f['cons'] === -1) $w .= " AND m.consultant_id=0";
        elseif ((int)$f['cons'] > 0) { $w .= " AND m.consultant_id=?"; $p[] = (int)$f['cons']; }
    }
    try {
        $s = $pdo->prepare("SELECT m.id, m.name, m.mobile, m.bot_id, m.consultant_id, m.consent_share, b.name AS bot_name, t.name AS cons_name
                            FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id LEFT JOIN saas_team t ON t.id=m.consultant_id AND t.owner_id=b.user_id
                            WHERE $w ORDER BY m.id DESC LIMIT " . (int)$limit);
        $s->execute($p);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { error_log('[MSG] members: ' . $e->getMessage()); return []; }
    $out = [];
    foreach ($rows as $r) {
        $item = ['id' => (int)$r['id'], 'name' => (string)$r['name'] !== '' ? (string)$r['name'] : 'مخاطب #' . $r['id'], 'mobile' => (string)$r['mobile'],
                 'bot_id' => (int)$r['bot_id'], 'bot' => (string)$r['bot_name'], 'cons_id' => (int)$r['consultant_id'], 'cons' => (string)($r['cons_name'] ?? ''), 'sub' => null];
        if (!empty($f['sub']) || !empty($f['with_sub'])) {
            $item['sub'] = function_exists('sup_has_subscription') ? sup_has_subscription($pdo, ['id' => $r['bot_id']], ['id' => $r['id']]) : false;
            if (!empty($f['sub']) && !$item['sub']) continue;
        }
        $out[] = $item;
    }
    return $out;
}

/** یک مخاطب قابل‌مشاهده برای این نقش (یا null) */
function cm_member_get($pdo, array $ctx, $member_id)
{
    if (!cm_role_ok($ctx)) return null;
    $p = [];
    $w = cm_member_scope($ctx, $p);
    $p[] = (int)$member_id;
    try {
        $s = $pdo->prepare("SELECT m.*, b.name AS bot_name FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE $w AND m.id=?");
        $s->execute($p);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
}

// =============================================================================
// ارسال پیام / یادآور (فردی یا گروهی)
// =============================================================================
/**
 * $in: members[] (شناسه مخاطبان)، colleagues[] (شناسه همکاران گیرنده)، mode now|remind، title، text،
 *      jdate/date + time + leads[] (برای remind)، ch_site، ch_push، ch_sms، category
 * @return array ['ok','error','batch','n_members','n_colleagues','sms_need','warn']
 */
function cm_send($pdo, array $ctx, array $in)
{
    cm_ensure_schema($pdo);
    if (!cm_role_ok($ctx)) return ['ok' => false, 'error' => 'اجازه ارسال پیام به مخاطبان را ندارید.'];
    if (!cm_plan_ok($pdo, $ctx, 'has_bulk_msg')) return ['ok' => false, 'error' => 'ارسال پیام و یادآور به مخاطبان در پلن فعلی شما نیست؛ پلن را ارتقا دهید.', 'upgrade' => true];
    $mode = ($in['mode'] ?? 'now') === 'remind' ? 'remind' : 'now';
    $text = trim(mb_substr(strip_tags((string)($in['text'] ?? '')), 0, 450));
    $title = trim(mb_substr(strip_tags((string)($in['title'] ?? '')), 0, 120));
    if ($mode === 'now' && mb_strlen($text) < 2) return ['ok' => false, 'error' => 'متن پیام را بنویسید.'];
    if ($title === '') $title = mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    if (mb_strlen($title) < 2) return ['ok' => false, 'error' => 'عنوان یادآور را بنویسید.'];
    $ch = ['site' => !empty($in['ch_site']) ? 1 : 0, 'push' => !empty($in['ch_push']) ? 1 : 0, 'sms' => !empty($in['ch_sms']) ? 1 : 0];
    if (!$ch['site'] && !$ch['push'] && !$ch['sms']) return ['ok' => false, 'error' => 'حداقل یک روش ارسال (اعلان یا پیامک) را انتخاب کنید.'];

    // گیرنده‌ها
    $mids = array_values(array_unique(array_filter(array_map('intval', (array)($in['members'] ?? [])))));
    $cids = array_values(array_unique(array_filter(array_map('intval', (array)($in['colleagues'] ?? [])))));
    if (!$mids && !$cids) return ['ok' => false, 'error' => 'حداقل یک مخاطب یا همکار را انتخاب کنید.'];
    if (count($mids) + count($cids) > MSG_MAX_RECIPIENTS) return ['ok' => false, 'error' => 'در هر ارسال حداکثر ' . MSG_MAX_RECIPIENTS . ' گیرنده مجاز است؛ گیرنده‌ها را در چند نوبت بفرستید.'];
    $members = [];
    foreach ($mids as $id) { $m = cm_member_get($pdo, $ctx, $id); if ($m) $members[] = $m; }
    if (count($members) !== count($mids)) return ['ok' => false, 'error' => 'برخی مخاطبان انتخاب‌شده پیدا نشدند یا به آن‌ها دسترسی ندارید؛ صفحه را تازه کنید.'];
    $team = [];
    foreach (cm_colleagues($pdo, $ctx) as $t) $team[(int)$t['id']] = $t;
    foreach ($cids as $id) if (!isset($team[$id])) return ['ok' => false, 'error' => 'همکار انتخاب‌شده معتبر نیست.'];
    if ($ctx['kind'] === 'team') {
        // همکار فقط برای خودش (به‌عنوان همکار مرتبط) می‌تواند نسخه بگیرد
        $cids = array_values(array_filter($cids, fn($id) => $id === (int)$ctx['id']));
    }
    // زمان
    if ($mode === 'now') {
        $due = rem_now(); $date = substr($due, 0, 10); $time = substr($due, 11, 5); $leads = [0];
    } else {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? (string)$in['date'] : rem_jparse((string)($in['jdate'] ?? ''));
        if ($date === '') return ['ok' => false, 'error' => 'تاریخ معتبر نیست (نمونه: ۱۴۰۵/۰۷/۱۵).'];
        $time = rem_en(trim((string)($in['time'] ?? '')));
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) return ['ok' => false, 'error' => 'ساعت معتبر نیست (نمونه: ۱۰:۳۰).'];
        $time = sprintf('%02d:%02d', $tm[1], $tm[2]);
        if (rem_ts($date . ' ' . $time . ':00') < time() - 60) return ['ok' => false, 'error' => 'زمان انتخاب‌شده گذشته است؛ زمانی در آینده انتخاب کنید.'];
        $leads = array_values(array_unique(array_map('intval', (array)($in['leads'] ?? [0])))) ?: [0];
    }
    // سقف روزانه
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM saas_reminders WHERE owner_uid=? AND batch LIKE 'B%' AND created_at >= ?");
        $c->execute([(int)$ctx['owner_uid'], rem_now(-86400)]);
        if ((int)$c->fetchColumn() + count($members) + count($cids) > MSG_MAX_DAY) return ['ok' => false, 'error' => 'سقف ارسال روزانه (' . number_format(MSG_MAX_DAY) . ' گیرنده) پر شده است؛ فردا دوباره تلاش کنید.'];
    } catch (\Throwable $e) {}
    // پیامک: اعتبار کافی؟
    $sms_need = 0;
    if ($ch['sms']) {
        if (!function_exists('biz_platform_sms_ready') || !biz_platform_sms_ready($pdo)) return ['ok' => false, 'error' => 'سرویس پیامک سامانه فعال نیست؛ فقط اعلان را انتخاب کنید.'];
        foreach ($members as $m) if (trim((string)$m['mobile']) !== '') $sms_need++;
        foreach ($cids as $id) if (trim((string)$team[$id]['mobile']) !== '') $sms_need++;
        $left = biz_sms_status($pdo, (int)$ctx['owner_uid'])['total_left'];
        if ($sms_need > $left) return ['ok' => false, 'error' => 'اعتبار پیامک کافی نیست: ' . number_format($sms_need) . ' پیامک لازم است و ' . number_format($left) . ' پیامک دارید. اعتبار پیامک بخرید یا فقط «اعلان» را انتخاب کنید.', 'sms_need' => $sms_need, 'sms_left' => $left, 'buy_sms' => true];
    }

    $batch = 'B' . date('ymdHis') . bin2hex(random_bytes(3));
    $cat = isset(rem_cats()[$in['category'] ?? '']) ? $in['category'] : ($mode === 'now' ? 'other' : 'meeting');
    $base = ['title' => $title, 'note' => $text, 'category' => $cat, 'date' => $date, 'time' => $time, 'leads' => $leads, 'repeat' => 'none',
             'ch_site' => $ch['site'], 'ch_push' => $ch['push'], 'ch_sms' => $ch['sms'], 'priority' => !empty($in['priority']) ? 1 : 0,
             'place' => mb_substr(trim(strip_tags((string)($in['place'] ?? ''))), 0, 150), 'source' => 'form', '_nocap' => 1];
    $ids = []; $errs = [];
    foreach ($members as $m) {
        $r = rem_save($pdo, $ctx, ['target' => 'member:' . $m['id'], 'ch_sms' => $ch['sms'] && trim((string)$m['mobile']) !== '' ? 1 : 0] + $base);
        if ($r['ok']) $ids[] = (int)$r['id']; else $errs[] = $m['name'] . ': ' . $r['error'];
    }
    // همکاران: یک نسخه برای هر همکار + نام مخاطبان مرتبط با او
    $rel = [];
    foreach ($members as $m) if ((int)$m['consultant_id'] > 0) $rel[(int)$m['consultant_id']][] = $m['name'] !== '' ? $m['name'] : 'مخاطب #' . $m['id'];
    foreach ($cids as $id) {
        $names = $rel[$id] ?? [];
        $person = $names ? mb_substr('مخاطبان: ' . implode('، ', array_slice($names, 0, 12)) . (count($names) > 12 ? ' و ' . (count($names) - 12) . ' نفر دیگر' : ''), 0, 150) : '';
        $key = ($ctx['kind'] === 'team' && $id === (int)$ctx['id']) ? 'self' : 'team:' . $id;
        $r = rem_save($pdo, $ctx, ['target' => $key, 'person' => $person, 'ch_sms' => $ch['sms'] && trim((string)$team[$id]['mobile']) !== '' ? 1 : 0] + $base);
        if ($r['ok']) $ids[] = (int)$r['id']; else $errs[] = $team[$id]['name'] . ': ' . $r['error'];
    }
    if (!$ids) return ['ok' => false, 'error' => $errs ? implode(' | ', array_slice($errs, 0, 3)) : 'ارسال ممکن نشد.'];
    $in_ids = implode(',', array_map('intval', $ids));
    $pdo->prepare("UPDATE saas_reminders SET batch=?, is_msg=? WHERE id IN ($in_ids)")->execute([$batch, $mode === 'now' ? 1 : 0]);
    if ($mode === 'now') cm_dispatch_soon($pdo);
    return ['ok' => true, 'batch' => $batch, 'n' => count($ids), 'n_members' => count($members), 'n_colleagues' => count($cids), 'sms_need' => $sms_need,
            'warn' => $errs ? 'برای ' . count($errs) . ' گیرنده ثبت نشد: ' . implode(' | ', array_slice($errs, 0, 3)) : ''];
}

/** ارسال پیام‌های «الان» بلافاصله پس از پاسخ به مرورگر (بدون منتظر ماندن کاربر) */
function cm_dispatch_soon($pdo)
{
    static $reg = false;
    if (PHP_SAPI === 'cli') { rem_run_due($pdo, 600); return; }   // کرون / آزمایش: همان لحظه
    if ($reg) return;   // در هر درخواست یک بار؛ همه موارد موعدرسیده با هم ارسال می‌شوند
    $reg = true;
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        @set_time_limit(300);
        try { rem_run_due($pdo, 600); } catch (\Throwable $e) { error_log('[MSG] dispatch: ' . $e->getMessage()); }
    });
}

/** سوابق ارسال‌های گروهی */
function cm_batches($pdo, array $ctx, $limit = 30)
{
    cm_ensure_schema($pdo);
    $p = [(int)$ctx['owner_uid']];
    $w = "owner_uid=? AND batch LIKE 'B%'";
    if ($ctx['kind'] === 'team') { $w .= " AND creator_kind='team' AND creator_id=?"; $p[] = (int)$ctx['id']; }
    try {
        $s = $pdo->prepare("SELECT batch, MIN(title) AS title, MIN(note) AS note, MIN(created_at) AS created_at, MIN(due_at) AS due_at, MAX(is_msg) AS is_msg, MAX(ch_sms) AS ch_sms, MAX(ch_push) AS ch_push,
                                   COUNT(*) AS n, SUM(CASE WHEN target_kind='member' THEN 1 ELSE 0 END) AS n_m, SUM(CASE WHEN fire_count > 0 THEN 1 ELSE 0 END) AS sent,
                                   SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS n_cancel, MIN(creator_name) AS creator
                            FROM saas_reminders WHERE $w GROUP BY batch ORDER BY MIN(id) DESC LIMIT " . (int)$limit);
        $s->execute($p);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { error_log('[MSG] batches: ' . $e->getMessage()); return []; }
    $out = [];
    foreach ($rows as $r) {
        $sms = 0;
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM saas_rem_events e JOIN saas_reminders r ON r.id=e.reminder_id WHERE r.batch=? AND e.sms=1");
            $q->execute([$r['batch']]); $sms = (int)$q->fetchColumn();
        } catch (\Throwable $e) {}
        $out[] = ['batch' => $r['batch'], 'title' => $r['title'], 'text' => (string)$r['note'], 'created' => rem_human($r['created_at'], false, false), 'when' => rem_human($r['due_at']),
                  'is_msg' => (bool)$r['is_msg'], 'n' => (int)$r['n'], 'n_members' => (int)$r['n_m'], 'n_colleagues' => (int)$r['n'] - (int)$r['n_m'], 'sent' => (int)$r['sent'],
                  'sms' => $sms, 'ch_sms' => (bool)$r['ch_sms'], 'cancelled' => (int)$r['n_cancel'], 'creator' => (string)$r['creator'],
                  'pending' => (int)$r['n'] - (int)$r['sent'] - (int)$r['n_cancel']];
    }
    return $out;
}

/** لغو باقی‌مانده یک ارسال زمان‌بندی‌شده */
function cm_batch_cancel($pdo, array $ctx, $batch)
{
    if (!preg_match('/^B[0-9a-f]{12,22}$/', (string)$batch)) return ['ok' => false, 'error' => 'درخواست نامعتبر است.'];
    $p = [rem_now(), (string)$batch, (int)$ctx['owner_uid']];
    $w = "batch=? AND owner_uid=? AND status='active' AND fire_count=0";
    if ($ctx['kind'] === 'team') { $w .= " AND creator_kind='team' AND creator_id=?"; $p[] = (int)$ctx['id']; }
    $u = $pdo->prepare("UPDATE saas_reminders SET status='cancelled', next_at=NULL, snooze_at=NULL, updated_at=? WHERE $w");
    $u->execute($p);
    return ['ok' => true, 'n' => $u->rowCount(), 'msg' => $u->rowCount() ? $u->rowCount() . ' ارسالِ انجام‌نشده لغو شد.' : 'موردی برای لغو نبود (همه ارسال شده‌اند).'];
}

// =============================================================================
// جلسات مشاوره
// =============================================================================
/** مشاورهای قابل انتخاب: صاحب حساب + همکاران (برای همکار: فقط خودش) */
function cm_consultants($pdo, array $ctx)
{
    if ($ctx['kind'] === 'team') return [['key' => 'team:' . (int)$ctx['id'], 'name' => (string)$ctx['name']]];
    $out = [['key' => 'user:' . (int)$ctx['owner_uid'], 'name' => ((string)$ctx['name'] !== '' ? (string)$ctx['name'] : 'خودم') . ' (خودم)']];
    foreach (cm_colleagues($pdo, $ctx) as $t) $out[] = ['key' => 'team:' . (int)$t['id'], 'name' => $t['name'] . ($t['title'] ? ' — ' . $t['title'] : '')];
    return $out;
}

function cm_session_get($pdo, array $ctx, $id)
{
    cm_ensure_schema($pdo);
    $p = [(int)$id, (int)$ctx['owner_uid']];
    $w = "id=? AND owner_uid=?";
    if ($ctx['kind'] === 'team') { $w .= " AND cons_kind='team' AND cons_id=?"; $p[] = (int)$ctx['id']; }
    $s = $pdo->prepare("SELECT * FROM saas_sessions WHERE $w");
    $s->execute($p);
    return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/**
 * ثبت یا تغییر جلسه مشاوره + یادآور برای مخاطب و مشاور
 * $in: member_id، consultant (user:ID | team:ID)، jdate/date، time، minutes، place، member_note، cons_note، leads[]، ch_push، sms_member، sms_cons، force (بدون بررسی تداخل)
 */
function cm_session_save($pdo, array $ctx, array $in, $id = 0)
{
    cm_ensure_schema($pdo);
    if (!cm_role_ok($ctx)) return ['ok' => false, 'error' => 'اجازه ثبت جلسه مشاوره را ندارید.'];
    if (!cm_plan_ok($pdo, $ctx, 'has_sessions')) return ['ok' => false, 'error' => 'جلسات مشاوره در پلن فعلی شما نیست؛ پلن را ارتقا دهید.', 'upgrade' => true];
    $old = $id ? cm_session_get($pdo, $ctx, $id) : null;
    if ($id && (!$old || $old['status'] !== 'scheduled')) return ['ok' => false, 'error' => 'جلسه پیدا نشد یا قابل تغییر نیست.'];
    $member = cm_member_get($pdo, $ctx, $old ? (int)$old['member_id'] : (int)($in['member_id'] ?? 0));
    if (!$member) return ['ok' => false, 'error' => 'مخاطب را انتخاب کنید.'];
    // مشاور
    $ck = (string)($in['consultant'] ?? '');
    if ($ck === '') $ck = (int)$member['consultant_id'] > 0 ? 'team:' . (int)$member['consultant_id'] : ($ctx['kind'] === 'team' ? 'team:' . (int)$ctx['id'] : 'user:' . (int)$ctx['owner_uid']);
    $cons = null;
    foreach (cm_consultants($pdo, $ctx) as $c) if ($c['key'] === $ck) $cons = $c;
    if (!$cons) return ['ok' => false, 'error' => 'مشاور انتخاب‌شده معتبر نیست.'];
    [$cons_kind, $cons_id] = explode(':', $cons['key']);
    $cons_id = (int)$cons_id;
    $cons_rc = rem_recipient($pdo, $cons_kind, $cons_id);
    $cons_name = $cons_rc ? (string)$cons_rc['name'] : $cons['name'];
    // زمان
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? (string)$in['date'] : rem_jparse((string)($in['jdate'] ?? ''));
    if ($date === '') return ['ok' => false, 'error' => 'تاریخ جلسه معتبر نیست (نمونه: ۱۴۰۵/۰۷/۱۵).'];
    $time = rem_en(trim((string)($in['time'] ?? '')));
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) return ['ok' => false, 'error' => 'ساعت جلسه معتبر نیست (نمونه: ۱۶:۳۰).'];
    $time = sprintf('%02d:%02d', $tm[1], $tm[2]);
    $starts = $date . ' ' . $time . ':00';
    if (rem_ts($starts) < time() - 60) return ['ok' => false, 'error' => 'زمان جلسه گذشته است؛ زمانی در آینده انتخاب کنید.'];
    $minutes = max(10, min(480, (int)($in['minutes'] ?? 60) ?: 60));
    $leads = array_values(array_unique(array_map('intval', (array)($in['leads'] ?? [1440, 60]))));
    $leads = $leads ? array_values(array_filter($leads, fn($x) => isset(rem_lead_opts()[$x]))) : [];
    if (!$leads) $leads = [60];
    $place = mb_substr(trim(strip_tags((string)($in['place'] ?? ''))), 0, 150);
    $mnote = mb_substr(trim(strip_tags((string)($in['member_note'] ?? ''))), 0, 450);
    $cnote = mb_substr(trim(strip_tags((string)($in['cons_note'] ?? ''))), 0, 900);
    $push = !empty($in['ch_push']) ? 1 : 0;
    $sms_m = !empty($in['sms_member']) && trim((string)$member['mobile']) !== '' ? 1 : 0;
    $sms_c = !empty($in['sms_cons']) && $cons_rc && trim((string)$cons_rc['mobile']) !== '' ? 1 : 0;
    // تداخل با جلسه دیگر همین مشاور
    if (empty($in['force'])) {
        try {
            $q = $pdo->prepare("SELECT starts_at, minutes, member_name FROM saas_sessions WHERE owner_uid=? AND cons_kind=? AND cons_id=? AND status='scheduled' AND id<>? AND starts_at BETWEEN ? AND ?");
            $q->execute([(int)$ctx['owner_uid'], $cons_kind, $cons_id, (int)$id, rem_fmt(rem_ts($starts) - 8 * 3600), rem_fmt(rem_ts($starts) + $minutes * 60)]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $o) {
                $os = rem_ts($o['starts_at']); $oe = $os + (int)$o['minutes'] * 60;
                if ($os < rem_ts($starts) + $minutes * 60 && rem_ts($starts) < $oe)
                    return ['ok' => false, 'conflict' => true, 'error' => 'این مشاور در همین زمان جلسه دیگری دارد (' . ($o['member_name'] ?: 'مخاطب') . ' — ' . rem_human($o['starts_at'], false, false) . '). اگر عمداً همزمان است، «ثبت با وجود تداخل» را بزنید.'];
            }
        } catch (\Throwable $e) {}
    }
    if ($sms_m + $sms_c > 0) {
        if (!function_exists('biz_platform_sms_ready') || !biz_platform_sms_ready($pdo)) return ['ok' => false, 'error' => 'سرویس پیامک سامانه فعال نیست؛ پیامک را خاموش کنید.'];
        if (biz_sms_status($pdo, (int)$ctx['owner_uid'])['total_left'] < $sms_m + $sms_c) return ['ok' => false, 'error' => 'اعتبار پیامک کافی نیست؛ اعتبار پیامک بخرید یا پیامک را خاموش کنید.', 'buy_sms' => true];
    }
    $mname = (string)$member['name'] !== '' ? (string)$member['name'] : 'مخاطب #' . $member['id'];
    $dur = $minutes . ' دقیقه';
    $base = ['date' => $date, 'time' => $time, 'leads' => $leads, 'repeat' => 'none', 'category' => 'meeting', 'place' => $place, 'ch_site' => 1, 'ch_push' => $push, 'priority' => 1, 'source' => 'form', '_nocap' => 1];
    $rm = $base + ['title' => 'جلسه مشاوره با ' . $cons_name, 'person' => $cons_name, 'note' => trim('مدت جلسه: ' . $dur . ($mnote !== '' ? "\n" . $mnote : '')), 'ch_sms' => $sms_m, 'target' => 'member:' . (int)$member['id']];
    $rcn = $base + ['title' => 'جلسه مشاوره با ' . $mname, 'person' => $mname . ($member['mobile'] ? ' (' . $member['mobile'] . ')' : ''), 'note' => trim('مدت جلسه: ' . $dur . ' | چت‌بات: ' . $member['bot_name'] . ($cnote !== '' ? "\n" . $cnote : '')), 'ch_sms' => $sms_c,
                    'target' => ($ctx['kind'] === $cons_kind && (int)$ctx['id'] === $cons_id) || ($ctx['kind'] === 'user' && $cons_kind === 'user') ? 'self' : $cons_kind . ':' . $cons_id];
    $now = rem_now();
    if ($old) {
        // مشاور عوض شد → یادآور قبلی مشاور لغو و یادآور تازه
        $same_cons = $old['cons_kind'] === $cons_kind && (int)$old['cons_id'] === $cons_id;
        $r1 = rem_get($pdo, (int)$old['rem_member']) ? rem_save($pdo, $ctx, $rm, (int)$old['rem_member']) : rem_save($pdo, $ctx, $rm);
        if (!$r1['ok']) return $r1;
        if (!$same_cons && $old['rem_cons']) $pdo->prepare("UPDATE saas_reminders SET status='cancelled', next_at=NULL, updated_at=? WHERE id=?")->execute([$now, (int)$old['rem_cons']]);
        $r2 = ($same_cons && rem_get($pdo, (int)$old['rem_cons'])) ? rem_save($pdo, $ctx, $rcn, (int)$old['rem_cons']) : rem_save($pdo, $ctx, $rcn);
        if (!$r2['ok']) return $r2;
        $pdo->prepare("UPDATE saas_sessions SET cons_kind=?, cons_id=?, cons_name=?, starts_at=?, minutes=?, place=?, member_note=?, cons_note=?, leads=?, ch_push=?, sms_member=?, sms_cons=?, rem_member=?, rem_cons=?, updated_at=? WHERE id=?")
            ->execute([$cons_kind, $cons_id, mb_substr($cons_name, 0, 150), $starts, $minutes, $place, $mnote, $cnote, implode(',', $leads), $push, $sms_m, $sms_c, (int)$r1['id'], (int)$r2['id'], $now, (int)$id]);
        $sid = (int)$id;
        // اطلاع تغییر زمان
        if ($old['starts_at'] !== $starts) cm_session_notice($pdo, $ctx, cm_session_get($pdo, $ctx, $sid), 'تغییر زمان جلسه مشاوره', 'زمان جلسه مشاوره شما تغییر کرد: ' . rem_human($starts, false, false) . ($place !== '' ? ' — ' . $place : ''), $sms_m, $sms_c);
    } else {
        $r1 = rem_save($pdo, $ctx, $rm);
        if (!$r1['ok']) return $r1;
        $r2 = rem_save($pdo, $ctx, $rcn);
        if (!$r2['ok']) { $pdo->prepare("DELETE FROM saas_reminders WHERE id=?")->execute([(int)$r1['id']]); return $r2; }
        $pdo->prepare("INSERT INTO saas_sessions (owner_uid, bot_id, member_id, member_name, cons_kind, cons_id, cons_name, starts_at, minutes, place, member_note, cons_note, leads, ch_push, sms_member, sms_cons, rem_member, rem_cons, status, creator_kind, creator_id, created_at, updated_at)
                       VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'scheduled',?,?,?,?)")
            ->execute([(int)$ctx['owner_uid'], (int)$member['bot_id'], (int)$member['id'], mb_substr($mname, 0, 150), $cons_kind, $cons_id, mb_substr($cons_name, 0, 150), $starts, $minutes, $place, $mnote, $cnote, implode(',', $leads), $push, $sms_m, $sms_c, (int)$r1['id'], (int)$r2['id'], $ctx['kind'], (int)$ctx['id'], $now, $now]);
        $sid = (int)$pdo->lastInsertId();
        // تأیید ثبت جلسه برای مخاطب (اعلان؛ پیامک فقط اگر انتخاب شده)
        if (!empty($in['notify_now'])) cm_session_notice($pdo, $ctx, cm_session_get($pdo, $ctx, $sid), 'جلسه مشاوره برای شما ثبت شد', 'جلسه مشاوره شما با ' . $cons_name . ': ' . rem_human($starts, false, false) . ($place !== '' ? ' — ' . $place : ''), $sms_m, 0, false);
    }
    $pdo->prepare("UPDATE saas_reminders SET batch=? WHERE id IN (?, ?)")->execute(['S' . $sid, (int)$r1['id'], (int)$r2['id']]);
    $warn = trim(($r1['warn'] ?? '') !== '' ? 'مخاطب: ' . $r1['warn'] : '');
    return ['ok' => true, 'id' => $sid, 'session' => cm_session_public(cm_session_get($pdo, $ctx, $sid)), 'warn' => $warn];
}

/** اطلاع فوری به مخاطب (و مشاور) درباره جلسه (تغییر/لغو/ثبت) */
function cm_session_notice($pdo, array $ctx, $s, $title, $text, $sms_m = 0, $sms_c = 0, $to_cons = true)
{
    if (!$s) return;
    $base = ['title' => $title, 'note' => $text, 'category' => 'meeting', 'date' => substr(rem_now(), 0, 10), 'time' => substr(rem_now(), 11, 5), 'leads' => [0], 'ch_site' => 1, 'ch_push' => (int)$s['ch_push'], 'priority' => 1, '_nocap' => 1];
    $ids = [];
    $r = rem_save($pdo, $ctx, $base + ['target' => 'member:' . (int)$s['member_id'], 'ch_sms' => $sms_m ? 1 : 0, 'person' => $s['cons_name']]);
    if ($r['ok']) $ids[] = (int)$r['id'];
    if ($to_cons) {
        $key = ($ctx['kind'] === $s['cons_kind'] && (int)$ctx['id'] === (int)$s['cons_id']) || ($ctx['kind'] === 'user' && $s['cons_kind'] === 'user') ? 'self' : $s['cons_kind'] . ':' . (int)$s['cons_id'];
        $r = rem_save($pdo, $ctx, $base + ['target' => $key, 'ch_sms' => $sms_c ? 1 : 0, 'person' => $s['member_name']]);
        if ($r['ok']) $ids[] = (int)$r['id'];
    }
    if ($ids) {
        $pdo->prepare("UPDATE saas_reminders SET batch=?, is_msg=1 WHERE id IN (" . implode(',', $ids) . ")")->execute(['S' . (int)$s['id']]);
        cm_dispatch_soon($pdo);
    }
}

/** لغو / انجام شد / غیبت — با لغو یادآورهای باقی‌مانده */
function cm_session_act($pdo, array $ctx, $id, $act, array $o = [])
{
    $s = cm_session_get($pdo, $ctx, $id);
    if (!$s) return ['ok' => false, 'error' => 'جلسه پیدا نشد.'];
    $now = rem_now();
    $map = ['cancel' => 'cancelled', 'done' => 'done', 'noshow' => 'noshow'];
    if (!isset($map[$act])) return ['ok' => false, 'error' => 'عملیات نامعتبر است.'];
    if ($s['status'] !== 'scheduled') return ['ok' => false, 'error' => 'وضعیت این جلسه قبلاً ثبت شده است.'];
    $pdo->prepare("UPDATE saas_sessions SET status=?, updated_at=? WHERE id=?")->execute([$map[$act], $now, (int)$id]);
    $pdo->prepare("UPDATE saas_reminders SET status=?, next_at=NULL, snooze_at=NULL, done_at=?, updated_at=? WHERE id IN (?, ?) AND status='active'")
        ->execute([$act === 'cancel' ? 'cancelled' : 'done', $now, $now, (int)$s['rem_member'], (int)$s['rem_cons']]);
    if ($act === 'cancel' && !empty($o['notify'])) {
        $why = mb_substr(trim(strip_tags((string)($o['reason'] ?? ''))), 0, 200);
        cm_session_notice($pdo, $ctx, $s, 'لغو جلسه مشاوره', 'جلسه مشاوره ' . rem_human($s['starts_at'], false, false) . ' لغو شد.' . ($why !== '' ? ' ' . $why : ''), !empty($o['sms']) && $s['sms_member'], !empty($o['sms']) && $s['sms_cons']);
    }
    return ['ok' => true, 'msg' => ['cancel' => 'جلسه لغو شد' . (!empty($o['notify']) ? ' و به مخاطب و مشاور اطلاع داده شد.' : '.'), 'done' => 'جلسه «انجام شد» ثبت شد ✔', 'noshow' => 'غیبت مخاطب ثبت شد.'][$act]];
}

function cm_session_public($s)
{
    if (!$s) return null;
    $st = ['scheduled' => 'برنامه‌ریزی‌شده', 'done' => 'انجام شد', 'cancelled' => 'لغو شد', 'noshow' => 'غیبت'][$s['status']] ?? $s['status'];
    return ['id' => (int)$s['id'], 'member_id' => (int)$s['member_id'], 'member' => $s['member_name'], 'bot_id' => (int)$s['bot_id'], 'consultant' => $s['cons_kind'] . ':' . (int)$s['cons_id'], 'cons_name' => $s['cons_name'],
            'starts_at' => $s['starts_at'], 'ts' => rem_ts($s['starts_at']), 'jdate' => rem_jdate($s['starts_at']), 'time' => substr($s['starts_at'], 11, 5), 'when' => rem_human($s['starts_at'], false, true),
            'minutes' => (int)$s['minutes'], 'place' => $s['place'], 'member_note' => $s['member_note'], 'cons_note' => $s['cons_note'], 'leads' => array_map('intval', array_filter(explode(',', (string)$s['leads']), fn($x) => $x !== '')),
            'ch_push' => (bool)$s['ch_push'], 'sms_member' => (bool)$s['sms_member'], 'sms_cons' => (bool)$s['sms_cons'], 'status' => $s['status'], 'status_text' => $st,
            'past' => rem_ts($s['starts_at']) + (int)$s['minutes'] * 60 < time()];
}

/** فهرست جلسات: scope upcoming | past | all */
function cm_sessions($pdo, array $ctx, $scope = 'upcoming', $limit = 200)
{
    cm_ensure_schema($pdo);
    $p = [(int)$ctx['owner_uid']];
    $w = "owner_uid=?";
    if ($ctx['kind'] === 'team') { $w .= " AND cons_kind='team' AND cons_id=?"; $p[] = (int)$ctx['id']; }
    if ($scope === 'upcoming') { $w .= " AND status='scheduled' AND starts_at >= ?"; $p[] = rem_now(-3 * 3600); $ord = 'starts_at ASC'; }
    elseif ($scope === 'past') { $w .= " AND (status<>'scheduled' OR starts_at < ?)"; $p[] = rem_now(-3 * 3600); $ord = 'starts_at DESC'; }
    else $ord = 'starts_at DESC';
    try {
        $s = $pdo->prepare("SELECT * FROM saas_sessions WHERE $w ORDER BY $ord LIMIT " . (int)$limit);
        $s->execute($p);
        return array_map('cm_session_public', $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { error_log('[MSG] sessions: ' . $e->getMessage()); return []; }
}

/** جلسات یک مخاطب (برای نمایش در چت‌بات و پرونده) */
function cm_member_sessions($pdo, $member_id, $upcoming = true)
{
    cm_ensure_schema($pdo);
    try {
        $s = $pdo->prepare("SELECT * FROM saas_sessions WHERE member_id=? " . ($upcoming ? "AND status='scheduled' AND starts_at >= ? " : "AND 1=? ") . "ORDER BY starts_at ASC LIMIT 20");
        $s->execute([(int)$member_id, $upcoming ? rem_now(-3600) : 1]);
        return array_map('cm_session_public', $s->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) { return []; }
}
