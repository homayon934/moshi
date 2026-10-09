<?php
/**
 * رابط کاربری مشترک یادآورها در پنل مدیر کل و پنل کاربران/همکاران
 *  - rem_ui_ajax(): درخواست‌های AJAX همان صفحه (تحلیل هوشمند، ثبت، عملیات، فهرست، جستجوی مخاطب)
 *  - rem_ui_render(): صفحه کامل (ثبت هوشمند، فرم، فهرست، فعال‌سازی اعلان روی دستگاه)
 *  - rem_toast_html(): پنجره‌های کوچک یادآور در همه صفحه‌های پنل
 */
require_once __DIR__ . '/rem_lib.php';

function rem_ui_csrf()
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['rem_csrf'])) $_SESSION['rem_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['rem_csrf'];
}

function rem_ui_json($d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** کسر هزینه تحلیل هوشمند از صاحب حساب (مدیر: از سامانه) */
function rem_ui_charge($pdo, array $ctx, $toman)
{
    if ($toman > 0 && (int)$ctx['owner_uid'] > 0 && function_exists('saas_deduct_credit')) saas_deduct_credit($pdo, (int)$ctx['owner_uid'], (int)$toman, 'یادآور هوشمند (تحلیل متن)');
}

function rem_ui_ajax($pdo, array $ctx)
{
    $a = (string)($_REQUEST['rem_ajax'] ?? '');
    if ($a === '') return;
    rem_ensure_schema($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(rem_ui_csrf(), (string)($_POST['csrf'] ?? ''))) rem_ui_json(['ok' => false, 'error' => 'نشست منقضی شده؛ صفحه را دوباره باز کنید.']);
    switch ($a) {
        case 'list':
            $scope = in_array($_GET['scope'] ?? '', ['upcoming', 'today', 'overdue', 'done', 'all'], true) ? $_GET['scope'] : 'upcoming';
            rem_ui_json(['ok' => true, 'items' => rem_list($pdo, $ctx, $scope), 'counts' => rem_counts($pdo, $ctx)]);
        case 'members':
            rem_ui_json(['ok' => true, 'items' => rem_member_search($pdo, $ctx, (string)($_GET['q'] ?? ''))]);
        case 'parse':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') rem_ui_json(['ok' => false]);
            if (function_exists('ex_rate_limited') && ex_rate_limited('rem_parse:' . $ctx['kind'] . ':' . $ctx['id'], 60, 3600)) rem_ui_json(['ok' => false, 'error' => 'درخواست‌های ثبت هوشمند زیاد است؛ کمی بعد دوباره تلاش کنید.']);
            $text = trim((string)($_POST['text'] ?? ''));
            $p = rem_parse($pdo, $text);
            rem_ui_charge($pdo, $ctx, $p['toman']);
            if (!$p['ok']) rem_ui_json(['ok' => false, 'error' => $p['error'] ?: 'متن را متوجه نشدم؛ کمی دقیق‌تر بنویسید.']);
            $fields = ['title' => $p['title'], 'category' => $p['category'], 'jdate' => $p['jdate'], 'time' => $p['all_day'] ? '' : $p['time'], 'all_day' => $p['all_day'],
                       'leads' => $p['leads'], 'repeat' => $p['repeat'], 'person' => $p['person'], 'place' => $p['place'], 'note' => $p['note'], 'raw_text' => $text, 'source' => 'smart'];
            // صبح یا عصر مشخص نیست: اول از کاربر پرسیده می‌شود، بعد ثبت نهایی
            if (!empty($p['ampm'])) rem_ui_json(['ok' => true, 'ask' => $p['ampm'], 'fields' => $fields, 'save' => !empty($_POST['save'])]);
            if (!empty($_POST['save'])) {
                $in = $fields + ['date' => $p['date'], 'target' => (string)($_POST['target'] ?? 'self'), 'ch_site' => 1, 'ch_push' => 1, 'ch_sms' => !empty($_POST['ch_sms']) || $p['sms'] ? 1 : 0];
                $r = rem_save($pdo, $ctx, $in);
                if (!$r['ok']) rem_ui_json(['ok' => false, 'error' => $r['error'], 'fields' => $fields]);
                rem_ui_json(['ok' => true, 'saved' => true, 'item' => rem_public($r['row'], $ctx), 'warn' => $r['warn'], 'counts' => rem_counts($pdo, $ctx)]);
            }
            rem_ui_json(['ok' => true, 'fields' => $fields, 'when' => rem_human($p['date'] . ' ' . $p['time'] . ':00', $p['all_day'])]);
        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') rem_ui_json(['ok' => false]);
            $in = $_POST;
            $in['leads'] = array_map('intval', (array)($_POST['leads'] ?? []));
            $r = rem_save($pdo, $ctx, $in, (int)($_POST['id'] ?? 0));
            if (!$r['ok']) rem_ui_json($r);
            rem_ui_json(['ok' => true, 'item' => rem_public($r['row'], $ctx), 'warn' => $r['warn'], 'counts' => rem_counts($pdo, $ctx)]);
        case 'act':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') rem_ui_json(['ok' => false]);
            $r = rem_act($pdo, $ctx, (int)($_POST['id'] ?? 0), (string)($_POST['act'] ?? ''), (int)($_POST['arg'] ?? 0));
            $row = rem_get($pdo, (int)($_POST['id'] ?? 0));
            rem_ui_json($r + ['item' => $row ? rem_public($row, $ctx) : null, 'counts' => rem_counts($pdo, $ctx)]);
    }
    rem_ui_json(['ok' => false, 'error' => 'درخواست نامعتبر است.']);
}

/**
 * $o: push_url (آدرس ثبت اشتراک اعلان)، sw_url، as (admin|user)، sms_note (توضیح هزینه پیامک)، members (امکان انتخاب مخاطب)
 */
function rem_ui_render($pdo, array $ctx, array $o = [])
{
    rem_ensure_schema($pdo);
    $vap = rem_vapid($pdo);
    $data = [
        'csrf' => rem_ui_csrf(), 'items' => rem_list($pdo, $ctx, 'upcoming'), 'counts' => rem_counts($pdo, $ctx), 'targets' => rem_targets($pdo, $ctx),
        'members' => !empty($o['members']), 'cats' => rem_cats(), 'leads' => rem_lead_opts(), 'repeats' => rem_repeat_opts(),
        'vapid' => $vap['pub'] ?? '', 'push_url' => (string)($o['push_url'] ?? ''), 'sw_url' => (string)($o['sw_url'] ?? ''), 'pushes' => rem_push_count($pdo, $ctx['kind'], $ctx['id']),
        'today_j' => rem_jdate(rem_now()), 'days' => rem_ui_days(),
    ];
    ob_start();
?>
<style>
.rm{--rc:#2563eb}
.rm .rm-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.rm .chip{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:6px 12px;font-size:13px;color:#334155;cursor:pointer;font-family:inherit}
.rm .chip b{font-weight:800}.rm .chip.on{background:var(--rc);border-color:var(--rc);color:#fff}
.rm .chip.warn b{color:#dc2626}.rm .chip.on.warn b{color:#fff}
.rm .pushb{margin-right:auto}.rm .pushb.on{background:#ecfdf5;border-color:#a7f3d0;color:#047857}
.rm .pbar{display:none;align-items:center;gap:10px;flex-wrap:wrap;background:linear-gradient(90deg,#eff6ff,#f5f3ff);border:1px solid #c7d2fe;border-radius:14px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#1e3a8a;line-height:1.9}
.rm .pbar.show{display:flex}.rm .pbar b{font-weight:800}.rm .pbar .sp{flex:1;min-width:200px}.rm .pbar.den{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.rm .ask{display:none;margin-top:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:10px 12px}.rm .ask.show{display:block}
.rm .ask p{margin:0 0 8px;font-weight:700;color:#92400e;font-size:13.5px}.rm .ask .ob{display:flex;gap:8px;flex-wrap:wrap}
.rm .ask .ob button{border:1.5px solid #f59e0b;background:#fff;color:#92400e;border-radius:10px;padding:7px 14px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer}.rm .ask .ob button small{display:block;font-weight:400;font-size:11px;color:#a16207}
.rm .ask .ob button:hover{background:#fef3c7}
.rm .card2{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:14px 16px;margin-bottom:14px}
.rm .smart{display:flex;gap:8px;align-items:flex-end}
.rm .smart textarea{flex:1;min-height:52px;resize:vertical;border:1.5px solid #c7d2fe;border-radius:12px;padding:10px 12px;font-family:inherit;font-size:14px;line-height:1.8;background:#f8faff}
.rm .smart textarea:focus{outline:none;border-color:var(--rc)}
.rm .sbtns{display:flex;flex-direction:column;gap:6px}
.rm .hint{font-size:12px;color:#64748b;margin-top:6px;line-height:1.9}
.rm .hint span{background:#f1f5f9;border-radius:8px;padding:1px 8px;margin-left:4px;cursor:pointer;display:inline-block;margin-bottom:3px}
.rm .frm{display:none}.rm .frm.open{display:block}
.rm .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px}
.rm label.l{display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:4px}
.rm input[type=text],.rm input[type=time],.rm select,.rm textarea.n{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 10px;font-family:inherit;font-size:13.5px;background:#fff}
.rm .cats{display:flex;flex-wrap:wrap;gap:6px}
.rm .cats label{border:1.5px solid #e2e8f0;border-radius:999px;padding:4px 11px;font-size:12.5px;cursor:pointer;background:#fff}
.rm .cats input{display:none}.rm .cats label.on{border-color:var(--rc);background:#eff6ff;color:#1d4ed8;font-weight:700}
.rm .qd{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}.rm .qd button{border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;padding:3px 8px;font-size:12px;cursor:pointer;font-family:inherit}
.rm .chk{display:flex;flex-wrap:wrap;gap:6px}.rm .chk label{display:inline-flex;align-items:center;gap:5px;border:1px solid #e2e8f0;border-radius:9px;padding:5px 9px;font-size:12.5px;cursor:pointer;background:#fff}
.rm .acts{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
.rm .day{font-size:12.5px;font-weight:800;color:#64748b;margin:14px 0 6px}
.rm .it{display:flex;gap:10px;align-items:flex-start;border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;margin-bottom:8px;background:#fff;border-right:4px solid var(--c)}
.rm .it.hi{box-shadow:0 0 0 2px #fecaca}.rm .it.od{background:#fff7ed}.rm .it.dn{opacity:.6}
.rm .it .ic{font-size:22px;line-height:1}
.rm .it .bd{flex:1;min-width:0}
.rm .it .t{font-weight:800;font-size:14px;color:#0f172a}
.rm .it .w{font-size:12.5px;color:#475569;margin-top:2px}
.rm .it .bg{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.rm .it .bg span{font-size:11px;background:#f1f5f9;color:#475569;border-radius:7px;padding:1px 7px}
.rm .it .nt{font-size:12.5px;color:#475569;margin-top:4px;white-space:pre-wrap}
.rm .it .ab{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;max-width:210px}
.rm .it .ab button{border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:3px 8px;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap}
.rm .it .ab .ok{border-color:#bbf7d0;background:#f0fdf4;color:#15803d}
.rm .empty{text-align:center;color:#94a3b8;padding:22px;font-size:13.5px}
.rm .msg{border-radius:10px;padding:8px 12px;font-size:13px;margin-bottom:10px;display:none}
.rm .msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;display:block}.rm .msg.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;display:block}.rm .msg.wr{background:#fffbeb;color:#92400e;border:1px solid #fde68a;display:block}
.rm .menu{position:relative;display:inline-block}.rm .menu .dd{position:absolute;left:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 24px rgba(0,0,0,.1);padding:4px;display:none;z-index:20;min-width:130px}
.rm .menu .dd.open{display:block}.rm .menu .dd button{display:block;width:100%;text-align:right;border:0;background:none;padding:6px 8px;font-size:12.5px;cursor:pointer;border-radius:6px}.rm .menu .dd button:hover{background:#f1f5f9}
@media(max-width:640px){.rm .smart{flex-direction:column;align-items:stretch}.rm .sbtns{flex-direction:row}.rm .it{flex-wrap:wrap}.rm .it .ab{max-width:none;width:100%;justify-content:flex-start}}
</style>
<div class="rm" id="rm">
    <div class="rm-top">
        <button type="button" class="chip on" data-sc="upcoming">📋 پیش رو <b id="c-up">0</b></button>
        <button type="button" class="chip" data-sc="today">☀️ امروز <b id="c-to">0</b></button>
        <button type="button" class="chip warn" data-sc="overdue">⚠️ موعد گذشته <b id="c-od">0</b></button>
        <button type="button" class="chip" data-sc="done">✔ انجام‌شده</button>
        <button type="button" class="chip pushb" id="pushb">🔔 اعلان روی این دستگاه</button>
        <button type="button" class="chip" id="pusht" style="display:none" title="ارسال یک اعلان آزمایشی به همین دستگاه">📨 آزمایش اعلان</button>
        <button type="button" class="chip" id="newb" style="background:#0f172a;color:#fff;border-color:#0f172a">➕ یادآور جدید</button>
    </div>
    <div class="pbar" id="pbar"><span class="sp" id="pbtx"></span><button type="button" class="btn btn-primary btn-sm" id="pbgo">🔔 فعال‌سازی اعلان</button></div>
    <div class="msg" id="rmsg"></div>

    <div class="card2">
        <div style="font-weight:800;margin-bottom:8px">✨ ثبت هوشمند — فقط بنویسید</div>
        <div class="smart">
            <textarea id="stext" rows="2" placeholder="مثلاً: فردا ساعت ۱۰ جلسه با آقای احمدی در دفتر، یک ساعت قبل یادم بنداز"></textarea>
            <div class="sbtns">
                <button type="button" class="btn btn-primary btn-sm" id="squick">⚡ ثبت</button>
                <button type="button" class="btn btn-outline btn-sm" id="sparse" title="اول اقلام را ببینید و اصلاح کنید">🔎 بررسی و ویرایش</button>
            </div>
        </div>
        <div class="hint">نمونه‌ها (کلیک کنید):
            <span>پس‌فردا عصر ۵ تماس با خانم رضایی</span><span>۱۵ آبان قسط وام، یک روز قبل هم بگو</span><span>هر شنبه ساعت ۹ صبح جلسه هفتگی تیم</span><span>تولد مریم ۲۰ دی</span>
            <label style="margin-right:6px;font-size:12px;white-space:nowrap"><input type="checkbox" id="ssms"> 📩 پیامک هم بفرست</label>
        </div>
        <div class="ask" id="sask"><p id="saskq"></p><div class="ob" id="sasko"></div></div>
    </div>

    <div class="card2 frm" id="frm">
        <div style="font-weight:800;margin-bottom:10px" id="ftitle">➕ یادآور جدید</div>
        <form id="rf" data-noajax onsubmit="return false">
            <input type="hidden" name="id" value="0"><input type="hidden" name="source" value="form"><input type="hidden" name="raw_text" value="">
            <div class="grid">
                <div style="grid-column:1/-1"><label class="l">عنوان *</label><input type="text" name="title" maxlength="200" placeholder="مثلاً: جلسه با آقای احمدی"></div>
                <div style="grid-column:1/-1"><label class="l">دسته</label><div class="cats" id="cats"></div></div>
                <div><label class="l">تاریخ (شمسی)</label><input type="text" name="jdate" dir="ltr" placeholder="1405/07/15" maxlength="10"><div class="qd" id="qd"></div></div>
                <div><label class="l">ساعت</label><input type="time" name="time" dir="ltr"><label style="font-size:12px;margin-top:6px;display:block"><input type="checkbox" name="all_day" value="1"> تمام روز (یادآوری ساعت ۹ صبح)</label></div>
                <div><label class="l">تکرار</label><select name="repeat" id="rep"></select><input type="text" name="repeat_until" dir="ltr" placeholder="تا تاریخ (اختیاری) 1405/12/29" style="margin-top:6px;display:none" id="runtil"></div>
                <div style="grid-column:1/-1"><label class="l">چه زمانی خبر بدهم؟</label><div class="chk" id="leads"></div></div>
                <div><label class="l">برای</label><select name="target" id="tgt"></select>
                    <input type="text" id="msearch" placeholder="🔎 جستجوی مخاطب چت‌بات (نام یا موبایل)" style="margin-top:6px;display:none"></div>
                <div><label class="l">روش اطلاع</label><div class="chk">
                    <label><input type="checkbox" name="ch_site" value="1" checked> 🖥 اعلان در سایت</label>
                    <label><input type="checkbox" name="ch_push" value="1" checked> 📱 اعلان گوشی/مرورگر</label>
                    <label><input type="checkbox" name="ch_sms" value="1"> 📩 پیامک</label></div>
                    <?php if (!empty($o['sms_note'])): ?><div class="hint"><?php echo htmlspecialchars($o['sms_note'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?></div>
                <div><label class="l">با چه کسی؟</label><input type="text" name="person" maxlength="150" placeholder="اختیاری"></div>
                <div><label class="l">کجا؟</label><input type="text" name="place" maxlength="150" placeholder="اختیاری"></div>
                <div style="grid-column:1/-1"><label class="l">توضیحات</label><textarea class="n" name="note" rows="2" maxlength="2000"></textarea></div>
                <div><label style="font-size:13px"><input type="checkbox" name="priority" value="1"> ❗ مهم (اعلان تا باز شدن روی صفحه می‌ماند)</label></div>
            </div>
            <div class="acts"><button type="button" class="btn btn-primary" id="fsave">💾 ذخیره یادآور</button><button type="button" class="btn btn-outline" id="fcancel">انصراف</button></div>
        </form>
    </div>

    <div class="card2"><div id="rlist"></div></div>
</div>
<script>
(function(){
var D = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
var SELF = location.pathname.split('/').pop() || 'reminders.php';
var root = document.getElementById('rm'); if (!root || root.__init) return; root.__init = 1;
var scope = 'upcoming', F = document.getElementById('rf');
function fa(n){ return String(n).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function msg(t, k){ var m = document.getElementById('rmsg'); m.className = 'msg ' + (k || 'ok'); m.textContent = t; clearTimeout(msg.t); msg.t = setTimeout(function(){ m.className = 'msg'; }, 7000); }
function post(a, data){
  var fd = data instanceof FormData ? data : new FormData(); if (!(data instanceof FormData)) for (var k in (data || {})) { var v = data[k]; if (Array.isArray(v)) v.forEach(function(x){ fd.append(k + '[]', x); }); else fd.append(k, v); }
  fd.append('csrf', D.csrf);
  return fetch(SELF + '?rem_ajax=' + a, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); }).catch(function(){ return { ok: false, error: 'ارتباط برقرار نشد.' }; });
}
function counts(c){ if (!c) return; document.getElementById('c-up').textContent = fa(c.upcoming); document.getElementById('c-to').textContent = fa(c.today); document.getElementById('c-od').textContent = fa(c.overdue); }
// ---------- فهرست ----------
function render(items){
  var box = document.getElementById('rlist');
  if (!items.length) { box.innerHTML = '<div class="empty">' + ({ upcoming: 'یادآور پیش رویی ندارید. با «✨ ثبت هوشمند» یک جمله بنویسید.', today: 'امروز یادآوری ندارید ☀️', overdue: 'موردی عقب نیفتاده 👌', done: 'هنوز چیزی انجام‌شده نیست.' }[scope] || '') + '</div>'; return; }
  var h = '', last = '';
  items.forEach(function(it){
    var day = it.when.split('،')[0];
    if (scope !== 'done' && day !== last) { h += '<div class="day">' + esc(day) + '</div>'; last = day; }
    var bg = [];
    if (it.repeat_text) bg.push('🔁 ' + it.repeat_text + (it.repeat_until ? ' تا ' + fa(it.repeat_until) : ''));
    if (it.leads_text) bg.push('🔔 ' + it.leads_text + ' + سر موعد');
    bg.push([it.ch_site ? '🖥' : '', it.ch_push ? '📱' : '', it.ch_sms ? '📩' : ''].join(''));
    if (it.target_label) bg.push('👉 برای: ' + it.target_label);
    if (it.from) bg.push('از طرف: ' + it.from);
    if (it.snooze) bg.push('⏰ تعویق تا ' + it.snooze);
    if (it.source === 'smart' || it.source === 'chat') bg.push('✨ هوشمند');
    var cls = 'it' + (it.priority ? ' hi' : '') + (it.overdue ? ' od' : '') + (it.status !== 'active' ? ' dn' : '');
    h += '<div class="' + cls + '" style="--c:' + it.color + '" data-id="' + it.id + '"><div class="ic">' + it.icon + '</div><div class="bd">'
      + '<div class="t">' + (it.priority ? '❗ ' : '') + esc(it.title) + '</div><div class="w">🕒 ' + esc(it.when) + (it.person ? ' — 👤 ' + esc(it.person) : '') + (it.place ? ' — 📍 ' + esc(it.place) : '') + (it.overdue ? ' <b style="color:#c2410c">(موعد گذشته)</b>' : '') + '</div>'
      + '<div class="bg">' + bg.filter(Boolean).map(function(x){ return '<span>' + esc(x) + '</span>'; }).join('') + '</div>'
      + (it.note ? '<div class="nt">' + esc(it.note) + '</div>' : '') + '</div><div class="ab">'
      + (it.status === 'active' ? '<button type="button" class="ok" data-a="done">✔ ' + (it.repeat !== 'none' ? 'این نوبت انجام شد' : 'انجام شد') + '</button><span class="menu"><button type="button" data-a="snz">⏰ تعویق</button><span class="dd"><button data-s="10">۱۰ دقیقه</button><button data-s="60">۱ ساعت</button><button data-s="180">۳ ساعت</button><button data-s="1440">فردا همین موقع</button></span></span>' : (it.can_edit ? '<button type="button" data-a="reopen">↺ فعال‌سازی</button>' : ''))
      + (it.can_edit ? '<button type="button" data-a="edit">✏️</button>' : '') + '<button type="button" data-a="ics" title="افزودن به تقویم گوشی">📅</button>'
      + (it.can_edit ? '<button type="button" data-a="delete" title="حذف">🗑</button>' : '') + '</div></div>';
  });
  box.innerHTML = h;
  Array.prototype.forEach.call(box.querySelectorAll('.it'), function(el){
    var id = +el.getAttribute('data-id'), it = items.filter(function(x){ return x.id === id; })[0];
    Array.prototype.forEach.call(el.querySelectorAll('[data-a]'), function(b){
      b.onclick = function(ev){
        var a = b.getAttribute('data-a');
        if (a === 'edit') return edit(it);
        if (a === 'ics') return ics(it);
        if (a === 'snz') { ev.stopPropagation(); var dd = b.nextElementSibling; dd.classList.toggle('open'); return; }
        if (a === 'delete' && !confirm('این یادآور حذف شود؟')) return;
        act(id, a, 0);
      };
    });
    Array.prototype.forEach.call(el.querySelectorAll('[data-s]'), function(b){ b.onclick = function(){ act(id, 'snooze', +b.getAttribute('data-s')); }; });
  });
}
document.addEventListener('click', function(){ Array.prototype.forEach.call(root.querySelectorAll('.dd.open'), function(d){ d.classList.remove('open'); }); });
function act(id, a, arg){ post('act', { id: id, act: a, arg: arg }).then(function(r){ if (!r.ok) return msg(r.error || 'خطا', 'er'); msg(r.msg || 'انجام شد'); counts(r.counts); load(); }); }
function load(){ fetch(SELF + '?rem_ajax=list&scope=' + scope, { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){ if (d.ok) { render(d.items); counts(d.counts); } }); }
Array.prototype.forEach.call(root.querySelectorAll('[data-sc]'), function(b){ b.onclick = function(){ scope = b.getAttribute('data-sc'); Array.prototype.forEach.call(root.querySelectorAll('[data-sc]'), function(x){ x.classList.toggle('on', x === b); }); load(); }; });
// ---------- فایل تقویم (ics) ----------
function pad(n){ return (n < 10 ? '0' : '') + n; }
function ics(it){
  var d = new Date(it.ts * 1000), z = function(x){ return x.getUTCFullYear() + pad(x.getUTCMonth() + 1) + pad(x.getUTCDate()) + 'T' + pad(x.getUTCHours()) + pad(x.getUTCMinutes()) + '00Z'; };
  var e = new Date(d.getTime() + 3600000), L = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//reminder//fa','BEGIN:VEVENT','UID:rem' + it.id + '@' + location.host, 'DTSTAMP:' + z(new Date()), 'DTSTART:' + z(d), 'DTEND:' + z(e), 'SUMMARY:' + it.title.replace(/[,;]/g, ' ')];
  if (it.place) L.push('LOCATION:' + it.place.replace(/[,;]/g, ' '));
  if (it.note || it.person) L.push('DESCRIPTION:' + ((it.person ? 'با: ' + it.person + ' ' : '') + (it.note || '')).replace(/\n/g, '\\n').replace(/[,;]/g, ' '));
  if (it.repeat === 'daily' || it.repeat === 'weekly') L.push('RRULE:FREQ=' + it.repeat.toUpperCase());
  (it.leads || [0]).forEach(function(l){ L.push('BEGIN:VALARM','ACTION:DISPLAY','DESCRIPTION:' + it.title.replace(/[,;]/g, ' '),'TRIGGER:-PT' + l + 'M','END:VALARM'); });
  L.push('END:VEVENT','END:VCALENDAR');
  var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([L.join('\r\n')], { type: 'text/calendar' })); a.download = 'reminder-' + it.id + '.ics'; document.body.appendChild(a); a.click(); a.remove();
}
// ---------- فرم ----------
var catBox = document.getElementById('cats');
catBox.innerHTML = Object.keys(D.cats).map(function(k, i){ return '<label' + (k === 'task' ? ' class="on"' : '') + '><input type="radio" name="category" value="' + k + '"' + (k === 'task' ? ' checked' : '') + '>' + D.cats[k][1] + ' ' + esc(D.cats[k][0]) + '</label>'; }).join('');
catBox.onchange = function(){ Array.prototype.forEach.call(catBox.querySelectorAll('label'), function(l){ l.classList.toggle('on', l.querySelector('input').checked); }); };
document.getElementById('leads').innerHTML = Object.keys(D.leads).map(function(k){ return '<label><input type="checkbox" name="leads" value="' + k + '"' + (k === '0' ? ' checked disabled' : '') + '> ' + esc(D.leads[k]) + '</label>'; }).join('');
document.getElementById('rep').innerHTML = Object.keys(D.repeats).map(function(k){ return '<option value="' + k + '">' + esc(D.repeats[k]) + '</option>'; }).join('');
document.getElementById('rep').onchange = function(){ document.getElementById('runtil').style.display = this.value === 'none' ? 'none' : ''; };
document.getElementById('qd').innerHTML = D.days.map(function(d){ return '<button type="button" data-j="' + d[0] + '">' + esc(d[1]) + '</button>'; }).join('');
Array.prototype.forEach.call(document.querySelectorAll('#qd button'), function(b){ b.onclick = function(){ F.jdate.value = b.getAttribute('data-j'); }; });
var tgt = document.getElementById('tgt');
function fillTargets(extra){
  var groups = {}, h = '';
  D.targets.concat(extra || []).forEach(function(t){ var g = t.g || ''; (groups[g] = groups[g] || []).push(t); });
  Object.keys(groups).forEach(function(g){ var o = groups[g].map(function(t){ return '<option value="' + esc(t.key) + '">' + esc(t.label) + '</option>'; }).join(''); h += g ? '<optgroup label="' + esc(g) + '">' + o + '</optgroup>' : o; });
  if (D.members) h += '<option value="__m">💬 یک مخاطب چت‌بات…</option>';
  tgt.innerHTML = h;
}
fillTargets();
var ms = document.getElementById('msearch'), mExtra = [];
tgt.onchange = function(){ ms.style.display = tgt.value === '__m' || /^member:/.test(tgt.value) ? '' : 'none'; if (tgt.value === '__m') ms.focus(); };
ms.oninput = function(){
  clearTimeout(ms.t); ms.t = setTimeout(function(){
    fetch(SELF + '?rem_ajax=members&q=' + encodeURIComponent(ms.value), { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
      mExtra = (d.items || []).map(function(x){ x.g = 'مخاطبان'; return x; }); var cur = tgt.value; fillTargets(mExtra);
      if (mExtra.length) tgt.value = mExtra[0].key; else tgt.value = cur === '__m' ? '__m' : cur;
    });
  }, 300);
};
function openForm(t){ document.getElementById('frm').classList.add('open'); document.getElementById('ftitle').textContent = t || '➕ یادآور جدید'; document.getElementById('frm').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
function resetForm(){ F.reset(); F.id.value = 0; F.source.value = 'form'; F.raw_text.value = ''; F.jdate.value = D.today_j; catBox.onchange(); document.getElementById('rep').onchange(); fillTargets(); ms.style.display = 'none'; }
function fill(f){
  F.title.value = f.title || ''; F.jdate.value = f.jdate || ''; F.time.value = f.all_day ? '' : (f.time || ''); F.all_day.checked = !!f.all_day;
  Array.prototype.forEach.call(F.querySelectorAll('[name=category]'), function(r){ r.checked = r.value === (f.category || 'task'); }); catBox.onchange();
  Array.prototype.forEach.call(F.querySelectorAll('[name=leads]'), function(c){ c.checked = c.value === '0' || (f.leads || []).map(String).indexOf(c.value) >= 0; });
  document.getElementById('rep').value = f.repeat || 'none'; document.getElementById('rep').onchange(); F.repeat_until.value = f.repeat_until || '';
  F.person.value = f.person || ''; F.place.value = f.place || ''; F.note.value = f.note || ''; F.raw_text.value = f.raw_text || ''; F.source.value = f.source || 'form';
  if (f.priority != null) F.priority.checked = !!f.priority;
  if (f.ch_site != null) { F.ch_site.checked = !!f.ch_site; F.ch_push.checked = !!f.ch_push; F.ch_sms.checked = !!f.ch_sms; }
}
function edit(it){ resetForm(); fill(it); F.id.value = it.id; if (it.target && it.target !== 'self') { if (!Array.prototype.some.call(tgt.options, function(o){ return o.value === it.target; })) fillTargets([{ key: it.target, label: it.target_label }]); tgt.value = it.target; } openForm('✏️ ویرایش یادآور'); }
document.getElementById('newb').onclick = function(){ resetForm(); openForm(); F.title.focus(); };
document.getElementById('fcancel').onclick = function(){ document.getElementById('frm').classList.remove('open'); };
document.getElementById('fsave').onclick = function(){
  if (tgt.value === '__m') return msg('مخاطب را جستجو و انتخاب کنید.', 'er');
  if (F.ch_push.checked) pushAuto();
  var fd = new FormData(F); var b = this; b.disabled = true;
  Array.prototype.forEach.call(F.querySelectorAll('[name=leads]:checked'), function(c){ fd.append('leads[]', c.value); }); fd.delete('leads');
  post('save', fd).then(function(r){ b.disabled = false; if (!r.ok) return msg(r.error || 'خطا', 'er'); msg('✅ ذخیره شد: ' + r.item.title + ' — ' + r.item.when); if (r.warn) setTimeout(function(){ msg(r.warn, 'wr'); }, 2500); document.getElementById('frm').classList.remove('open'); counts(r.counts); load(); });
};
// ---------- ثبت هوشمند ----------
var st = document.getElementById('stext');
Array.prototype.forEach.call(root.querySelectorAll('.hint span'), function(s){ s.onclick = function(){ st.value = s.textContent; st.focus(); }; });
st.addEventListener('keydown', function(e){ if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); document.getElementById('squick').click(); } });
function smart(save, btn){
  var t = st.value.trim(); if (t.length < 4) { msg('یک جمله بنویسید؛ مثلاً «فردا ساعت ۱۰ جلسه با آقای احمدی».', 'er'); return; }
  btn.disabled = true; var o = btn.textContent; btn.textContent = '⏳ …';
  post('parse', { text: t, save: save ? 1 : 0, ch_sms: document.getElementById('ssms').checked ? 1 : 0 }).then(function(r){
    btn.disabled = false; btn.textContent = o;
    if (!r.ok) { msg(r.error || 'خطا', 'er'); if (r.fields) { resetForm(); fill(r.fields); openForm('🔎 بررسی یادآور'); } return; }
    if (r.ask) return ask(r);
    if (r.saved) { st.value = ''; msg('✅ ثبت شد: ' + r.item.icon + ' ' + r.item.title + ' — ' + r.item.when + (r.item.leads_text ? ' (+ ' + r.item.leads_text + ')' : '')); if (r.warn) setTimeout(function(){ msg(r.warn, 'wr'); }, 3000); counts(r.counts); load(); return; }
    resetForm(); fill(r.fields); F.ch_sms.checked = document.getElementById('ssms').checked; openForm('🔎 بررسی یادآور — اقلام را ببینید و ذخیره کنید'); st.value = '';
  });
}
// صبح یا عصر؟ — بعد از انتخاب، ثبت نهایی (یا باز شدن فرم برای بررسی)
function ask(r){
  var box = document.getElementById('sask'), ob = document.getElementById('sasko');
  document.getElementById('saskq').textContent = '🕒 ' + r.ask.q;
  ob.innerHTML = r.ask.opts.map(function(o, i){ return '<button type="button" data-i="' + i + '">' + esc(o.label) + '<small>' + esc(o.when) + '</small></button>'; }).join('') + '<button type="button" data-i="x" style="border-color:#e2e8f0;color:#64748b">انصراف</button>';
  box.classList.add('show');
  Array.prototype.forEach.call(ob.querySelectorAll('button'), function(b){ b.onclick = function(){
    box.classList.remove('show');
    var i = b.getAttribute('data-i'); if (i === 'x') return;
    var o = r.ask.opts[+i], f = r.fields; f.time = o.time; f.jdate = o.jdate; f.all_day = false;
    resetForm(); fill(f); F.ch_sms.checked = document.getElementById('ssms').checked;
    if (r.save) { st.value = ''; document.getElementById('fsave').click(); }
    else { openForm('🔎 بررسی یادآور — اقلام را ببینید و ذخیره کنید'); st.value = ''; }
  }; });
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
document.getElementById('squick').onclick = function(){ pushAuto(); smart(true, this); };
document.getElementById('sparse').onclick = function(){ smart(false, this); };
// ---------- اعلان روی دستگاه (حتی وقتی سایت بسته است) ----------
var pb = document.getElementById('pushb'), pt = document.getElementById('pusht'), bar = document.getElementById('pbar'), btx = document.getElementById('pbtx'), bgo = document.getElementById('pbgo');
function b64(s){ s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var r = atob(s), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a; }
var PUSH = { ok: !!(('serviceWorker' in navigator) && ('PushManager' in window) && window.Notification && D.vapid), sub: null };
var secure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
var ios = /iPhone|iPad|iPod/.test(navigator.userAgent), standalone = window.navigator.standalone || (window.matchMedia && matchMedia('(display-mode: standalone)').matches);
function showBar(t, btn, den){ btx.innerHTML = t; bgo.style.display = btn ? '' : 'none'; bar.classList.toggle('den', !!den); bar.classList.add('show'); }
function pushState(){
  bar.classList.remove('show');
  if (!secure) { pb.textContent = '🔕 اعلان (فقط روی https)'; showBar('⚠️ اعلان مرورگر فقط روی آدرس امن <b>https</b> کار می‌کند؛ گواهی SSL سایت را فعال کنید.', false, true); return; }
  if (!PUSH.ok) { pb.textContent = '🔕 اعلان پشتیبانی نمی‌شود'; pb.disabled = true; if (ios && !standalone) showBar('📱 روی آیفون: در Safari دکمه <b>اشتراک‌گذاری ⬆️</b> ← <b>Add to Home Screen</b> را بزنید و سایت را از همان آیکون باز کنید تا اعلان فعال شود.', false); return; }
  if (Notification.permission === 'denied') { pb.textContent = '🔕 اعلان در مرورگر مسدود است'; showBar('🔕 اعلان این سایت در تنظیمات مرورگر <b>مسدود</b> است. روی آیکون 🔒 کنار آدرس سایت بزنید ← <b>Notifications / اعلان‌ها</b> ← <b>Allow / اجازه</b>، سپس صفحه را تازه کنید.', false, true); return; }
  navigator.serviceWorker.getRegistration(D.sw_url).then(function(reg){
    return reg ? reg.pushManager.getSubscription() : null;
  }).then(function(s){
    PUSH.sub = s || null;
    if (s && Notification.permission === 'granted') { pb.textContent = '🔔 اعلان این دستگاه فعال است'; pb.classList.add('on'); pt.style.display = ''; return; }
    if (Notification.permission === 'granted') { pushSub(true); return; }   // اجازه هست ولی ثبت نشده: بی‌صدا ثبت شود
    pb.classList.remove('on'); pt.style.display = 'none';
    showBar('🔔 <b>یادآورها را حتی وقتی سایت بسته است دریافت کنید:</b> اعلان را فعال کنید و در پنجره مرورگر «<b>Allow / اجازه</b>» را بزنید.', true);
  }).catch(function(){});
}
function pushSub(silent){
  if (!PUSH.ok || !secure) { if (!silent) msg('این مرورگر از اعلان پشتیبانی نمی‌کند' + (ios ? ' (روی آیفون: «Add to Home Screen» و باز کردن از همان آیکون).' : '.'), 'wr'); return Promise.resolve(false); }
  return Notification.requestPermission().then(function(p){
    if (p !== 'granted') { if (!silent) msg('اجازه نمایش اعلان داده نشد؛ از آیکون 🔒 کنار آدرس سایت، اعلان‌ها را «Allow» کنید.', 'wr'); pushState(); return false; }
    return navigator.serviceWorker.register(D.sw_url).then(function(){ return navigator.serviceWorker.ready; }).then(function(reg){
      return reg.pushManager.getSubscription().then(function(s){ return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(D.vapid) }); });
    }).then(function(sub){
      PUSH.sub = sub;
      return fetch(D.push_url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ sub: sub.toJSON(), ua: navigator.userAgent }) }).then(function(r){ return r.json(); });
    }).then(function(r){
      if (r && r.ok) { if (!silent) msg('🔔 اعلان روی این دستگاه فعال شد؛ یادآورها حتی وقتی سایت بسته است نمایش داده می‌شوند. برای اطمینان «📨 آزمایش اعلان» را بزنید.'); pushState(); return true; }
      if (!silent) msg((r && r.error) || 'فعال‌سازی اعلان ممکن نشد.', 'er'); return false;
    });
  }).catch(function(e){ if (!silent) msg('فعال‌سازی اعلان ممکن نشد: ' + (e && e.message ? e.message : ''), 'er'); return false; });
}
// هنگام ذخیره یادآوری که «اعلان گوشی» دارد: اگر هنوز اجازه داده نشده، همان لحظه پرسیده می‌شود
function pushAuto(){ if (PUSH.ok && secure && !PUSH.sub && window.Notification && Notification.permission === 'default') pushSub(true); }
pb.onclick = function(){ pb.disabled = true; pushSub(false).then(function(){ pb.disabled = false; }); };
bgo.onclick = function(){ bgo.disabled = true; pushSub(false).then(function(){ bgo.disabled = false; }); };
pt.onclick = function(){
  if (!PUSH.sub) return msg('اول اعلان را فعال کنید.', 'wr');
  pt.disabled = true;
  var tu = D.push_url.replace('act=sub', 'act=test');
  fetch(tu, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: PUSH.sub.endpoint }) }).then(function(r){ return r.json(); })
    .then(function(r){ pt.disabled = false; msg(r.ok ? '📨 ' + r.msg : '⚠️ ' + (r.error || 'ارسال ممکن نشد.'), r.ok ? 'ok' : 'er'); })
    .catch(function(){ pt.disabled = false; msg('ارتباط برقرار نشد.', 'er'); });
};
pushState();
counts(D.counts); render(D.items); resetForm();
})();
</script>
<?php
    return ob_get_clean();
}

/** پنجره‌های کوچک یادآور در پنل‌ها (هر ۶۰ ثانیه بررسی) — $as: admin | user */
function rem_toast_html($as)
{
    $url = rtrim(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', '/') . '/push.php';
    $csrf = rem_ui_csrf();
    return '<style>#rmt{position:fixed;left:16px;bottom:16px;z-index:9998;display:flex;flex-direction:column;gap:8px;max-width:min(360px,calc(100vw - 32px));direction:rtl;font-family:inherit}'
        . '#rmt .tt{background:#fff;border:1px solid #e2e8f0;border-right:4px solid #2563eb;border-radius:14px;box-shadow:0 12px 30px rgba(15,23,42,.18);padding:10px 12px;animation:rmin .25s ease-out}'
        . '#rmt .tt.hi{border-right-color:#dc2626}#rmt .tt b{display:block;font-size:14px;color:#0f172a}#rmt .tt p{margin:3px 0 8px;font-size:12.5px;color:#475569;line-height:1.8}'
        . '#rmt .tt div{display:flex;gap:5px;flex-wrap:wrap}#rmt .tt button{border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:3px 8px;font-size:12px;cursor:pointer;font-family:inherit}'
        . '#rmt .tt button.k{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}@keyframes rmin{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}</style>'
        . '<div id="rmt"></div><script>(function(){if(window.__rmt)return;window.__rmt=1;var U=' . json_encode($url) . ',AS=' . json_encode($as) . ',CS=' . json_encode($csrf) . ',shown={};'
        . 'function esc(s){return String(s||"").replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",\'"\':"&quot;"}[c];});}'
        . 'function ack(id,a,arg,el){var f=new FormData();f.append("id",id);f.append("act",a||"");f.append("arg",arg||0);f.append("csrf",CS);fetch(U+"?act=ack&as="+AS,{method:"POST",body:f,credentials:"same-origin"});if(el)el.remove();}'
        . 'function show(e){if(shown[e.id])return;shown[e.id]=1;var b=document.getElementById("rmt"),d=document.createElement("div");d.className="tt"+(e.high?" hi":"");'
        . 'd.innerHTML="<b>"+esc(e.title)+"</b><p>"+esc(e.body)+"</p><div>"+(e.active?"<button class=k data-a=done>✔ "+(e.repeat?"این نوبت انجام شد":"انجام شد")+"</button><button data-a=snooze data-g=10>⏰ ۱۰ دقیقه</button><button data-a=snooze data-g=60>⏰ ۱ ساعت</button>":"")+"<button data-a=\'\'>✕ بستن</button></div>";'
        . 'Array.prototype.forEach.call(d.querySelectorAll("button"),function(x){x.onclick=function(){ack(e.id,x.getAttribute("data-a"),x.getAttribute("data-g"),d);};});b.appendChild(d);'
        . 'try{if(document.hidden&&window.Notification&&Notification.permission==="granted")new Notification(e.title,{body:e.body,dir:"rtl"});}catch(x){}}'
        . 'function poll(){fetch(U+"?act=due&as="+AS,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){(d.items||[]).reverse().forEach(show);}).catch(function(){});}'
        . 'setTimeout(poll,1500);setInterval(poll,60000);})();</script>';
}
