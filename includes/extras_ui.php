<?php
/**
 * بخش‌های مشترک رابط کاربری امکانات تکمیلی (پنل مدیریت و پنل کاربر):
 * راهنما / سوالات متداول، رشته گفتگوی تیکت، فهرست تیکت‌ها، فرم پاپ‌آپ
 */
require_once __DIR__ . '/extras_lib.php';

/** عملیات فرم راهنما/سوالات متداول — خروجی پیام ok:/error: یا null */
function ex_articles_handle($pdo, $scope, $bot_id, array $in)
{
    $act = (string)($in['act'] ?? '');
    if ($act === 'art_save') {
        $r = ex_article_save($pdo, $scope, $bot_id, $in, (int)($in['art_id'] ?? 0));
        return $r['ok'] ? 'ok:ذخیره شد؛ پشتیبان هوشمند از این به بعد از آن استفاده می‌کند.' : 'error:' . $r['error'];
    }
    if ($act === 'art_del') { ex_article_del($pdo, $scope, $bot_id, (int)($in['art_id'] ?? 0)); return 'ok:حذف شد.'; }
    if ($act === 'art_toggle') {
        $pdo->prepare("UPDATE ex_articles SET is_active = 1 - is_active WHERE id=? AND scope=? AND bot_id=?")->execute([(int)($in['art_id'] ?? 0), $scope, (int)$bot_id]);
        return 'ok:وضعیت تغییر کرد.';
    }
    return null;
}

/** مدیریت راهنما یا سوالات متداول */
function ex_articles_ui($pdo, $scope, $bot_id, $kind, $base_url, $csrf = '', array $targets = [])
{
    $items = ex_articles($pdo, $scope, $bot_id, $kind, false);
    $edit = null;
    foreach ($items as $a) if ((int)$a['id'] === (int)($_GET['edit_art'] ?? 0)) $edit = $a;
    $tok = $csrf !== '' ? '<input type="hidden" name="csrf_token" value="' . ex_h($csrf) . '">' : '';
    $faq = $kind === 'faq';
    $sep = strpos($base_url, '?') === false ? '?' : '&';
    ob_start(); ?>
<div class="card" id="artform">
    <div class="card-title" style="font-weight:700;margin-bottom:8px"><?php echo $edit ? '✏️ ویرایش' : ($faq ? '➕ سوال متداول جدید' : '➕ راهنمای جدید'); ?></div>
    <p style="font-size:12.5px;color:#64748b;line-height:2;margin-bottom:10px"><?php echo $faq ? 'سوال‌هایی که زیاد پرسیده می‌شود با پاسخ کامل. پشتیبان هوشمند این پاسخ‌ها را می‌خواند و به کاربران می‌گوید.' : 'راهنمای کامل بخش‌ها و کارها (مثلاً «چطور ربات را روی سایت نصب کنم»). پشتیبان هوشمند این متن‌ها را می‌خواند.'; ?></p>
    <form method="post" data-pg="artform">
        <?php echo $tok; ?><input type="hidden" name="act" value="art_save"><input type="hidden" name="kind" value="<?php echo $faq ? 'faq' : 'guide'; ?>"><input type="hidden" name="art_id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <div class="form-group" style="margin-bottom:10px"><label><?php echo $faq ? 'سوال' : 'عنوان راهنما'; ?></label><input type="text" name="title" maxlength="250" required value="<?php echo ex_h($edit['title'] ?? ''); ?>"></div>
        <div class="form-group" style="margin-bottom:10px"><label><?php echo $faq ? 'پاسخ' : 'متن کامل راهنما'; ?></label><textarea name="body" rows="<?php echo $faq ? 5 : 10; ?>" required><?php echo ex_h($edit['body'] ?? ''); ?></textarea></div>
        <div class="row2">
            <div class="form-group"><label>ترتیب نمایش</label><input type="number" name="sort_order" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>"></div>
            <div class="form-group" style="display:flex;align-items:flex-end"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" style="width:17px;height:17px" <?php echo !$edit || !empty($edit['is_active']) ? 'checked' : ''; ?>> نمایش داده شود و هوش مصنوعی بخواند</label></div>
        </div>
        <?php if ($targets): $cur_t = array_filter(explode(',', trim((string)($edit['targets'] ?? ''), ','))); ?>
        <div class="form-group" style="margin-top:6px"><label>برای کدام بخش‌ها؟</label>
            <input type="hidden" name="targets[]" value="">
            <label style="display:flex;gap:8px;align-items:center;margin:4px 0;font-weight:600"><input type="checkbox" name="targets_all" value="1" style="width:17px;height:17px" <?php echo !$cur_t ? 'checked' : ''; ?> onchange="this.closest('.form-group').querySelectorAll('.tgi').forEach(function(x){x.disabled=this.checked;}.bind(this))"> همه (پاسخگوی هوشمند سایت و همه چت‌بات‌ها)</label>
            <div style="display:flex;gap:4px 16px;flex-wrap:wrap;margin-right:24px">
            <?php foreach ($targets as $tk => $tl): ?><label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" class="tgi" name="targets[]" value="<?php echo ex_h($tk); ?>" style="width:16px;height:16px" <?php echo in_array((string)$tk, $cur_t, true) ? 'checked' : ''; ?> <?php echo !$cur_t ? 'disabled' : ''; ?>> <?php echo ex_h($tl); ?></label><?php endforeach; ?>
            </div></div>
        <?php endif; ?>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary btn-sm"><?php echo $edit ? '💾 ذخیره' : '➕ افزودن'; ?></button>
        <?php if ($edit): ?><a class="btn btn-outline btn-sm" href="<?php echo ex_h($base_url); ?>">انصراف</a><?php endif; ?></div>
    </form>
</div>
<div class="card">
    <div class="card-title" style="font-weight:700;margin-bottom:8px"><?php echo $faq ? '❓ سوالات متداول' : '📘 راهنماها'; ?> <span style="font-weight:normal;font-size:12px;color:#64748b">(<?php echo count($items); ?>)</span></div>
    <?php if (!$items): ?><p style="text-align:center;color:#94a3b8;padding:14px">هنوز موردی ثبت نشده است.</p><?php else: ?>
    <?php foreach ($items as $a): ?>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;margin-bottom:8px;<?php echo $a['is_active'] ? '' : 'opacity:.6'; ?>">
        <summary style="cursor:pointer;font-weight:600;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
            <span><?php echo ex_h($a['title']); ?><?php echo $a['is_active'] ? '' : ' <span style="font-size:11px;color:#dc2626">(غیرفعال)</span>'; ?>
            <?php if ($targets): $tt = array_filter(explode(',', trim((string)($a['targets'] ?? ''), ','))); ?><span style="font-size:11px;color:#64748b;font-weight:normal"> — <?php echo $tt ? ex_h(implode('، ', array_map(fn($x) => $targets[$x] ?? $x, $tt))) : 'همه'; ?></span><?php endif; ?></span>
            <span style="display:flex;gap:4px">
                <a class="btn btn-outline btn-sm" href="<?php echo ex_h($base_url . $sep . 'edit_art=' . (int)$a['id']); ?>#artform">ویرایش</a>
                <form method="post" style="display:inline"><?php echo $tok; ?><input type="hidden" name="act" value="art_toggle"><input type="hidden" name="art_id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $a['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><?php echo $tok; ?><input type="hidden" name="act" value="art_del"><input type="hidden" name="art_id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-danger btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form>
            </span>
        </summary>
        <div style="white-space:pre-wrap;line-height:2;font-size:13px;margin-top:8px;color:#475569"><?php echo ex_h($a['body']); ?></div>
    </details>
    <?php endforeach; endif; ?>
</div>
<?php
    return ob_get_clean();
}

/** نمایش فقط‌خواندنی راهنما و سوالات متداول (برای کاربران) */
function ex_articles_read_ui($pdo, $scope, $bot_id, $kind)
{
    $items = ex_articles($pdo, $scope, $bot_id, $kind);
    ob_start();
    if (!$items) { echo '<p style="text-align:center;color:#94a3b8;padding:16px">هنوز موردی ثبت نشده است.</p>'; return ob_get_clean(); } ?>
    <input type="search" placeholder="🔎 جستجو…" oninput="var q=this.value.trim().toLowerCase();this.parentNode.querySelectorAll('details').forEach(function(d){d.style.display=!q||d.textContent.toLowerCase().indexOf(q)>=0?'':'none';});" style="width:100%;margin-bottom:10px">
    <?php foreach ($items as $a): ?>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;margin-bottom:8px">
        <summary style="cursor:pointer;font-weight:600"><?php echo ($kind === 'faq' ? '❓ ' : '📘 ') . ex_h($a['title']); ?></summary>
        <div style="white-space:pre-wrap;line-height:2;font-size:13.5px;margin-top:8px;color:#334155"><?php echo ex_h($a['body']); ?></div>
    </details>
    <?php endforeach;
    return ob_get_clean();
}

/** رشته پیام‌های یک تیکت + فرم پاسخ */
function ex_ticket_thread_ui($t, array $msgs, $me, $csrf = '', $back_url = '', $can_status = false)
{
    $tok = $csrf !== '' ? '<input type="hidden" name="csrf_token" value="' . ex_h($csrf) . '">' : '';
    $st = ex_ticket_statuses();
    $names = ['user' => 'کاربر', 'admin' => 'پشتیبانی', 'member' => 'مخاطب', 'ai' => 'پشتیبان هوشمند'];
    ob_start(); ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        <div><b style="font-size:15px">🎫 <?php echo ex_h($t['subject']); ?></b> <span class="badge <?php echo $t['status'] === 'closed' ? 'badge-gray' : ($t['status'] === 'answered' ? 'badge-green' : 'badge-blue'); ?>" style="margin-right:6px"><?php echo $st[$t['status']] ?? $t['status']; ?></span>
            <div style="font-size:12px;color:#94a3b8;margin-top:3px">شماره #<?php echo (int)$t['id']; ?> — ایجاد: <?php echo function_exists('biz_jdate') ? biz_jdate($t['created_at'], true) : ex_h($t['created_at']); ?></div></div>
        <?php if ($back_url !== ''): ?><a class="btn btn-outline btn-sm" href="<?php echo ex_h($back_url); ?>">← همه تیکت‌ها</a><?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px">
    <?php foreach ($msgs as $m): $mine = $m['sender'] === $me; ?>
        <div style="max-width:85%;<?php echo $mine ? 'align-self:flex-start;background:#ede9fe' : 'align-self:flex-end;background:#f1f5f9'; ?>;border-radius:14px;padding:10px 14px">
            <div style="font-size:11.5px;color:#64748b;margin-bottom:4px"><?php echo ex_h(($m['author'] !== '' ? $m['author'] : ($names[$m['sender']] ?? '')) . ' — ' . (function_exists('biz_jdate') ? biz_jdate($m['created_at'], true) : $m['created_at'])); ?></div>
            <div style="white-space:pre-wrap;line-height:1.9;font-size:13.5px"><?php echo ex_h($m['body']); ?></div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if ($t['status'] !== 'closed' || $can_status): ?>
    <form method="post" style="margin-top:14px" data-pg="treply">
        <?php echo $tok; ?><input type="hidden" name="act" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
        <textarea name="body" rows="4" required placeholder="پاسخ خود را بنویسید…" style="width:100%"></textarea>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
            <button class="btn btn-primary btn-sm">📨 ارسال پاسخ</button>
            <?php if ($can_status): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="close_after" value="1" style="width:16px;height:16px"> بعد از ارسال، تیکت بسته شود</label>
            <?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
    <?php if ($can_status): ?>
    <form method="post" style="margin-top:10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap" data-pg="tstatus">
        <?php echo $tok; ?><input type="hidden" name="act" value="ticket_status"><input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
        <label style="font-size:13px;margin:0">وضعیت:</label>
        <select name="status" style="width:auto"><?php foreach ($st as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $t['status'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline btn-sm">تغییر وضعیت</button>
    </form>
    <?php elseif ($t['status'] !== 'closed'): ?>
    <form method="post" style="margin-top:10px" data-pg="tclose"><?php echo $tok; ?><input type="hidden" name="act" value="ticket_status"><input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>"><input type="hidden" name="status" value="closed"><button class="btn btn-outline btn-sm">✔ مشکل حل شد؛ تیکت بسته شود</button></form>
    <?php endif; ?>
</div>
<?php
    return ob_get_clean();
}

/** جدول فهرست تیکت‌ها */
function ex_tickets_table_ui(array $rows, $link_base, $unread_field, $who_col = null)
{
    $st = ex_ticket_statuses();
    $sep = strpos($link_base, '?') === false ? '?' : '&';
    ob_start();
    if (!$rows) { echo '<p style="text-align:center;color:#94a3b8;padding:16px">تیکتی وجود ندارد.</p>'; return ob_get_clean(); } ?>
    <div class="table-wrap" style="overflow-x:auto"><table><thead><tr><th>#</th><th>موضوع</th><?php if ($who_col): ?><th><?php echo ex_h($who_col); ?></th><?php endif; ?><th>وضعیت</th><th>آخرین به‌روزرسانی</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): $un = !empty($r[$unread_field]); ?>
        <tr style="<?php echo $un ? 'font-weight:700;background:#faf5ff' : ''; ?>">
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo ($un ? '🔴 ' : '') . ex_h($r['subject']); ?></td>
            <?php if ($who_col): ?><td style="font-size:12px"><?php echo ex_h($r['_who'] ?? ''); ?></td><?php endif; ?>
            <td><span class="badge <?php echo $r['status'] === 'closed' ? 'badge-gray' : ($r['status'] === 'answered' ? 'badge-green' : 'badge-blue'); ?>"><?php echo $st[$r['status']] ?? $r['status']; ?></span></td>
            <td style="font-size:12px"><?php echo function_exists('biz_jdate') ? biz_jdate($r['updated_at'], true) : ex_h($r['updated_at']); ?></td>
            <td><a class="btn btn-outline btn-sm" href="<?php echo ex_h($link_base . $sep . 't=' . (int)$r['id']); ?>">مشاهده</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
    return ob_get_clean();
}

/**
 * فرم پاپ‌آپ
 * @param array $targets [کلید => عنوان] مقصدهای مجاز
 * @param array $opts plans (برای پنل)، bots (برای چت‌بات)
 */
function ex_popup_form_ui($edit, array $targets, $csrf = '', $cancel_url = '', array $opts = [])
{
    $tok = $csrf !== '' ? '<input type="hidden" name="csrf_token" value="' . ex_h($csrf) . '">' : '';
    $tg = $edit['target'] ?? array_key_first($targets);
    $aud = (string)($edit['audience'] ?? 'all');
    $pages = array_filter(explode(',', (string)($edit['pages'] ?? '')));
    ob_start(); ?>
<form method="post" enctype="multipart/form-data" data-pg="popform">
    <?php echo $tok; ?><input type="hidden" name="act" value="popup_save"><input type="hidden" name="popup_id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
    <div class="row2">
        <div class="form-group"><label>محل نمایش</label><select name="target" id="pp_target" onchange="ppTarget()"><?php foreach ($targets as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $tg === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>عنوان</label><input type="text" name="title" maxlength="200" value="<?php echo ex_h($edit['title'] ?? ''); ?>" placeholder="مثلاً: 🎉 جشنواره تخفیف"></div>
    </div>
    <div class="form-group" style="margin-bottom:10px"><label>متن</label><textarea name="body" rows="4" maxlength="3000"><?php echo ex_h($edit['body'] ?? ''); ?></textarea></div>
    <div class="row2">
        <div class="form-group"><label>تصویر (اختیاری، حداکثر ۲ مگابایت)</label><input type="file" name="image" accept="image/*"><input type="text" name="image_url" dir="ltr" value="<?php echo ex_h($edit['image_url'] ?? ''); ?>" placeholder="یا آدرس تصویر: https://…" style="margin-top:6px"></div>
        <div class="form-group"><label>دکمه (اختیاری)</label><input type="text" name="btn_text" maxlength="80" value="<?php echo ex_h($edit['btn_text'] ?? ''); ?>" placeholder="متن دکمه، مثلاً: مشاهده"><input type="text" name="btn_url" dir="ltr" value="<?php echo ex_h($edit['btn_url'] ?? ''); ?>" placeholder="لینک دکمه: https://…" style="margin-top:6px"></div>
    </div>
    <?php if (isset($targets['panel'])): ?>
    <div class="pp-only" data-t="panel" style="margin:10px 0;padding:10px 12px;background:#f8fafc;border-radius:10px">
        <div class="row2">
            <div class="form-group"><label>برای کدام کاربران</label><select name="audience_panel">
                <option value="all" <?php echo $aud === 'all' ? 'selected' : ''; ?>>همه کاربران</option>
                <option value="noplan" <?php echo $aud === 'noplan' ? 'selected' : ''; ?>>کاربران بدون پلن</option>
                <?php foreach ((array)($opts['plans'] ?? []) as $p): ?><option value="plan:<?php echo (int)$p['id']; ?>" <?php echo $aud === 'plan:' . (int)$p['id'] ? 'selected' : ''; ?>>کاربران پلن «<?php echo ex_h($p['name']); ?>»</option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label>در کدام صفحه‌ها (هیچ‌کدام = همه صفحه‌ها)</label>
                <div style="display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12.5px"><?php foreach (ex_panel_pages() as $pk => $pl): ?><label style="display:flex;gap:4px;align-items:center;margin:0;font-weight:normal"><input type="checkbox" name="pages[]" value="<?php echo $pk; ?>" style="width:15px;height:15px" <?php echo in_array($pk, $pages, true) ? 'checked' : ''; ?>> <?php echo $pl; ?></label><?php endforeach; ?></div></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isset($targets['bot'])): ?>
    <div class="pp-only" data-t="bot" style="margin:10px 0;padding:10px 12px;background:#f8fafc;border-radius:10px">
        <div class="row2">
            <div class="form-group"><label>کدام چت‌بات</label><select name="bot_id"><option value="0">همه چت‌بات‌های من</option><?php foreach ((array)($opts['bots'] ?? []) as $b): ?><option value="<?php echo (int)$b['id']; ?>" <?php echo (int)($edit['bot_id'] ?? 0) === (int)$b['id'] ? 'selected' : ''; ?>><?php echo ex_h($b['name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>برای کدام مخاطبان</label><select name="audience_bot">
                <option value="all" <?php echo $aud === 'all' ? 'selected' : ''; ?>>همه مخاطبان</option>
                <option value="free" <?php echo $aud === 'free' ? 'selected' : ''; ?>>فقط مخاطبان رایگان (بدون بسته)</option>
                <option value="paid" <?php echo $aud === 'paid' ? 'selected' : ''; ?>>فقط مخاطبان دارای بسته</option>
            </select></div>
        </div>
    </div>
    <?php endif; ?>
    <div class="row2">
        <div class="form-group"><label>تعداد نمایش</label><select name="freq"><?php foreach (ex_popup_freqs() as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($edit['freq'] ?? 'once') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>تأخیر نمایش (ثانیه)</label><input type="number" name="delay_sec" min="0" max="60" value="<?php echo (int)($edit['delay_sec'] ?? 2); ?>"></div>
    </div>
    <div class="row2">
        <div class="form-group"><label>از تاریخ (اختیاری)</label><input type="date" name="start_at" value="<?php echo !empty($edit['start_at']) ? substr($edit['start_at'], 0, 10) : ''; ?>"></div>
        <div class="form-group"><label>تا تاریخ (اختیاری)</label><input type="date" name="end_at" value="<?php echo !empty($edit['end_at']) ? substr($edit['end_at'], 0, 10) : ''; ?>"></div>
    </div>
    <div class="row2">
        <div class="form-group"><label>اولویت (عدد کمتر = اول)</label><input type="number" name="sort_order" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>"></div>
        <div class="form-group" style="display:flex;align-items:flex-end"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" style="width:17px;height:17px" <?php echo !$edit || !empty($edit['is_active']) ? 'checked' : ''; ?>> فعال</label></div>
    </div>
    <p style="font-size:12px;color:#64748b;margin:6px 0 10px">در هر بار باز شدن صفحه فقط یک پاپ‌آپ (اولین مورد واجد شرایط) نمایش داده می‌شود. با هر ویرایش، پاپ‌آپ دوباره به همه نمایش داده می‌شود.</p>
    <button class="btn btn-primary btn-sm"><?php echo $edit ? '💾 ذخیره' : '➕ افزودن پاپ‌آپ'; ?></button>
    <?php if ($edit && $cancel_url !== ''): ?><a class="btn btn-outline btn-sm" href="<?php echo ex_h($cancel_url); ?>">انصراف</a><?php endif; ?>
</form>
<script>
function ppTarget(){ var t = document.getElementById('pp_target').value; document.querySelectorAll('.pp-only').forEach(function(e){ e.style.display = e.getAttribute('data-t') === t ? '' : 'none'; }); }
ppTarget();
</script>
<?php
    return ob_get_clean();
}

/** فهرست پاپ‌آپ‌ها */
function ex_popups_table_ui(array $rows, $base_url, $csrf = '', array $bots = [], array $plans = [])
{
    $tok = $csrf !== '' ? '<input type="hidden" name="csrf_token" value="' . ex_h($csrf) . '">' : '';
    $tg = ex_popup_targets();
    $sep = strpos($base_url, '?') === false ? '?' : '&';
    $bn = []; foreach ($bots as $b) $bn[(int)$b['id']] = $b['name'];
    $pn = []; foreach ($plans as $p) $pn[(int)$p['id']] = $p['name'];
    ob_start();
    if (!$rows) { echo '<p style="text-align:center;color:#94a3b8;padding:16px">هنوز پاپ‌آپی تعریف نشده است.</p>'; return ob_get_clean(); } ?>
    <div class="table-wrap" style="overflow-x:auto"><table><thead><tr><th>عنوان</th><th>محل</th><th>مخاطبان</th><th>نمایش</th><th>بازدید / کلیک</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $p):
        $a = (string)$p['audience'];
        $al = $a === 'all' ? 'همه' : ($a === 'free' ? 'رایگان' : ($a === 'paid' ? 'دارای بسته' : ($a === 'noplan' ? 'بدون پلن' : (str_starts_with($a, 'plan:') ? 'پلن ' . ($pn[(int)substr($a, 5)] ?? '?') : $a))));
    ?>
        <tr><td><b><?php echo ex_h($p['title'] !== '' ? $p['title'] : mb_substr((string)$p['body'], 0, 40)); ?></b><?php if ($p['start_at'] || $p['end_at']): ?><div style="font-size:11px;color:#64748b"><?php echo $p['start_at'] ? 'از ' . substr($p['start_at'], 0, 10) : ''; ?> <?php echo $p['end_at'] ? 'تا ' . substr($p['end_at'], 0, 10) : ''; ?></div><?php endif; ?></td>
            <td style="font-size:12px"><?php echo $tg[$p['target']] ?? $p['target']; ?><?php echo $p['target'] === 'bot' ? ' — ' . ex_h((int)$p['bot_id'] ? ($bn[(int)$p['bot_id']] ?? '?') : 'همه') : ''; ?></td>
            <td style="font-size:12px"><?php echo ex_h($al); ?></td>
            <td style="font-size:12px"><?php echo ex_popup_freqs()[$p['freq']] ?? $p['freq']; ?></td>
            <td style="font-size:12px"><?php echo number_format((int)$p['views']); ?> / <?php echo number_format((int)$p['clicks']); ?></td>
            <td><?php echo $p['is_active'] ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>'; ?></td>
            <td style="white-space:nowrap">
                <a class="btn btn-outline btn-sm" href="<?php echo ex_h($base_url . $sep . 'edit=' . (int)$p['id']); ?>#popform">ویرایش</a>
                <form method="post" style="display:inline"><?php echo $tok; ?><input type="hidden" name="act" value="popup_toggle"><input type="hidden" name="popup_id" value="<?php echo (int)$p['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $p['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><?php echo $tok; ?><input type="hidden" name="act" value="popup_del"><input type="hidden" name="popup_id" value="<?php echo (int)$p['id']; ?>"><button class="btn btn-danger btn-sm" style="background:#fee2e2;color:#dc2626">حذف</button></form>
            </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
    return ob_get_clean();
}

/** عملیات فرم پاپ‌آپ — خروجی پیام یا null */
function ex_popups_handle($pdo, $owner_id, array $allowed_targets, array $in, $file = null)
{
    $act = (string)($in['act'] ?? '');
    if ($act === 'popup_save') {
        $in['audience'] = ($in['target'] ?? '') === 'bot' ? ($in['audience_bot'] ?? 'all') : (($in['target'] ?? '') === 'panel' ? ($in['audience_panel'] ?? 'all') : 'all');
        $r = ex_popup_save($pdo, $owner_id, $in, $allowed_targets, $file);
        return $r['ok'] ? 'ok:پاپ‌آپ ذخیره شد.' : 'error:' . $r['error'];
    }
    if ($act === 'popup_toggle') { $pdo->prepare("UPDATE ex_popups SET is_active = 1 - is_active WHERE id=? AND owner_id=?")->execute([(int)($in['popup_id'] ?? 0), (int)$owner_id]); return 'ok:وضعیت تغییر کرد.'; }
    if ($act === 'popup_del') { $pdo->prepare("DELETE FROM ex_popups WHERE id=? AND owner_id=?")->execute([(int)($in['popup_id'] ?? 0), (int)$owner_id]); return 'ok:حذف شد.'; }
    return null;
}
