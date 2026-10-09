<?php if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }
/**
 * بخش‌های تکمیلی مدیریت چت‌بات (در user/bot.php استفاده می‌شود):
 *  - 📣 تبلیغ و اعلان چت‌بات رایگان، پیشنهاد مدل بهتر
 *  - 🎧 پشتیبانی مخاطبان (راهنما، سوالات متداول، تیکت‌ها)
 *  - 🗂 دسته‌بندی موضوعی هوشمند (فقط برای مشاور)
 *  - مشاور اختصاصی، سوابق نظارت و دسته‌های موضوعی در پرونده مراجع
 *  - لینک‌های پایگاه دانش
 */
require_once dirname(__DIR__) . '/includes/extras_ui.php';

/** زبانه‌های اضافه (بسته به دسترسی و پلن) */
function bm_tabs($pdo, $uid, $can_manage, $can_consult)
{
    $t = [];
    if ($can_manage) $t['promo'] = '📣 تبلیغ و اعلان';
    if ($can_manage) $t['support'] = '🎧 پشتیبانی مخاطبان';
    if ($can_manage || $can_consult) $t['topics'] = '🗂 دسته‌بندی موضوعی';
    return $t;
}

/** بررسی ساده منشأ درخواست (جلوگیری از ارسال فرم از سایت دیگر) */
function bm_same_origin()
{
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($src === '') return true;
    $h = strtolower((string)parse_url($src, PHP_URL_HOST));
    return $h === '' || $h === strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
}

/** همکارانی که دسترسی «مشاور» دارند */
function bm_consultants($pdo, $owner_id)
{
    return array_values(array_filter(biz_team_list($pdo, $owner_id), fn($t) => in_array('consult', $t['perm_list'] ?? [], true)));
}

/**
 * عملیات فرم‌ها — اگر مربوط به این بخش باشد، انجام و به صفحه برمی‌گرداند
 */
function bm_handle_post($pdo, $bot, $act, $self, $ctx)
{
    $bid = (int)$bot['id'];
    $uid = (int)$bot['user_id'];
    $mine = ['bm_promo', 'ad_save', 'ad_del', 'ad_toggle', 'bm_support', 'art_save', 'art_del', 'art_toggle', 'tcat_save', 'tcat_del', 'tcat_toggle', 'tcat_rescan', 'member_consultant', 'link_save', 'link_del', 'link_toggle'];
    if (!in_array($act, $mine, true)) return;
    if (!bm_same_origin()) { header('Location: ' . $self . '&msg=' . urlencode('error:درخواست نامعتبر است.')); exit; }
    $msg = ''; $tab = 'settings'; $extra = '';

    if ($act === 'bm_promo' || in_array($act, ['ad_save', 'ad_del', 'ad_toggle'], true)) {
        $tab = 'promo';
        if ($act === 'bm_promo') {
            $pdo->prepare("UPDATE hd_bots SET free_notice_on=?, free_notice=?, ads_every=?, suggest_model=? WHERE id=?")
                ->execute([!empty($_POST['free_notice_on']) ? 1 : 0, mb_substr(trim((string)($_POST['free_notice'] ?? '')), 0, 600), max(0, min(50, (int)($_POST['ads_every'] ?? 3))), !empty($_POST['suggest_model']) ? 1 : 0, $bid]);
            $msg = 'ok:ذخیره شد.';
        } elseif ($act === 'ad_save') {
            $r = ex_ad_save($pdo, $bid, $uid, $_POST, $_FILES['image'] ?? null);
            $msg = $r['ok'] ? 'ok:تبلیغ ذخیره شد.' : 'error:' . $r['error'];
            if (!$r['ok'] && !empty($_POST['ad_id'])) $extra = '&edit_ad=' . (int)$_POST['ad_id'];
        } elseif ($act === 'ad_del') {
            $pdo->prepare("DELETE FROM ex_ads WHERE id=? AND bot_id=?")->execute([(int)($_POST['ad_id'] ?? 0), $bid]);
            $msg = 'ok:تبلیغ حذف شد.';
        } else {
            $pdo->prepare("UPDATE ex_ads SET is_active = 1 - is_active WHERE id=? AND bot_id=?")->execute([(int)($_POST['ad_id'] ?? 0), $bid]);
            $msg = 'ok:وضعیت تبلیغ تغییر کرد.';
        }
    } elseif ($act === 'bm_support' || in_array($act, ['art_save', 'art_del', 'art_toggle'], true)) {
        $tab = 'support';
        if ($act === 'bm_support') {
            $pdo->prepare("UPDATE hd_bots SET support_on=? WHERE id=?")->execute([!empty($_POST['support_on']) ? 1 : 0, $bid]);
            $msg = 'ok:ذخیره شد.';
        } else {
            $msg = (string)ex_articles_handle($pdo, 'bot', $bid, $_POST);
            if (($_POST['kind'] ?? '') === 'faq' || ($_GET['sub'] ?? '') === 'faq') $extra = '&sub=faq';
        }
    } elseif (str_starts_with($act, 'tcat_')) {
        $tab = 'topics';
        if ($act === 'tcat_save') {
            $id = (int)($_POST['cat_id'] ?? 0);
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
            $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
            if (mb_strlen($name) < 2) $msg = 'error:نام دسته را بنویسید.';
            else {
                if ($id) $pdo->prepare("UPDATE ex_topic_cats SET name=?, description=?, sort_order=?, is_active=? WHERE id=? AND bot_id=?")->execute([$name, $desc, (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0, $id, $bid]);
                else $pdo->prepare("INSERT INTO ex_topic_cats (bot_id, name, description, sort_order, is_active, created_at) VALUES(?,?,?,?,?,?)")->execute([$bid, $name, $desc, (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0, hd_now()]);
                ex_topics_reset($pdo, $bid);   // پیام‌های قبلی هم با دسته جدید بررسی شوند
                $msg = 'ok:دسته ذخیره شد. پیام‌های قبلی و جدید مراجعانی که اجازه داده‌اند به‌تدریج بررسی و دسته‌بندی می‌شوند.';
            }
        } elseif ($act === 'tcat_del') {
            $cid = (int)($_POST['cat_id'] ?? 0);
            $pdo->prepare("DELETE FROM ex_topic_cats WHERE id=? AND bot_id=?")->execute([$cid, $bid]);
            $pdo->prepare("DELETE FROM ex_topic_hits WHERE cat_id=? AND bot_id=?")->execute([$cid, $bid]);
            $msg = 'ok:دسته حذف شد.';
        } elseif ($act === 'tcat_toggle') {
            $pdo->prepare("UPDATE ex_topic_cats SET is_active = 1 - is_active WHERE id=? AND bot_id=?")->execute([(int)($_POST['cat_id'] ?? 0), $bid]);
            $msg = 'ok:وضعیت دسته تغییر کرد.';
        } else {
            ex_topics_reset($pdo, $bid);
            $n = ex_topics_run($pdo, $bid, 60);
            $msg = 'ok:بررسی شروع شد (' . $n . ' پیام همین حالا بررسی شد؛ بقیه به‌تدریج در پس‌زمینه).';
        }
    } elseif ($act === 'member_consultant') {
        $mid = (int)($_POST['member_id'] ?? 0);
        $tab = 'members'; $extra = '&mid=' . $mid;
        if (!empty($ctx['is_team'])) $msg = 'error:فقط صاحب حساب می‌تواند مشاور اختصاصی تعیین کند.';
        else {
            $cid = (int)($_POST['consultant_id'] ?? 0);
            if ($cid && !in_array($cid, array_map('intval', array_column(bm_consultants($pdo, $uid), 'id')), true)) $cid = 0;
            $pdo->prepare("UPDATE hd_members SET consultant_id=? WHERE id=? AND bot_id=?")->execute([$cid, $mid, $bid]);
            $msg = 'ok:' . ($cid ? 'از این پس فقط همین مشاور (و شما) به پرونده این مراجع دسترسی دارد.' : 'محدودیت مشاور برداشته شد.');
        }
    } else {
        $tab = 'sources';
        $msg = (string)ex_links_handle($pdo, $uid, $bid, $_POST);
        $extra = '#links';
    }
    $hash = '';
    if (str_starts_with($extra, '#')) { $hash = $extra; $extra = ''; }
    header('Location: ' . $self . '&tab=' . $tab . $extra . '&msg=' . urlencode($msg) . $hash);
    exit;
}

// =============================================================================
// نمایش زبانه‌ها
// =============================================================================
function bm_render($tab, $pdo, $bot, $self, $ctx)
{
    if ($tab === 'promo') bm_render_promo($pdo, $bot, $self);
    elseif ($tab === 'support') bm_render_support($pdo, $bot, $self);
    elseif ($tab === 'topics') bm_render_topics($pdo, $bot, $self, $ctx);
}

function bm_locked($label)
{
    echo saas_plan_locked_html($label);
}

function bm_render_promo($pdo, $bot, $self)
{
    $bid = (int)$bot['id'];
    if (!ex_owner_allows($pdo, (int)$bot['user_id'], 'has_bot_ads')) { bm_locked('تبلیغ و اعلان در چت‌بات رایگان'); return; }
    $ads = ex_ads($pdo, $bid, false);
    $ea = null;
    foreach ($ads as $a) if ((int)$a['id'] === (int)($_GET['edit_ad'] ?? 0)) $ea = $a;
?>
<div class="card">
    <div class="card-title">📢 اعلان بالای باکس پیام (چت‌بات رایگان)</div>
    <p class="hd-note" style="margin-bottom:10px">این پیام برای مخاطبانی که از پیام‌های رایگان استفاده می‌کنند، بالای باکس تایپ نمایش داده می‌شود و با × موقتاً بسته می‌شود (در ورود بعدی دوباره نمایش داده می‌شود). مثلاً اعلان محدودیت استفاده یا معرفی امکانات بخش تخصصی.</p>
    <form method="post" data-pg="bmpromo">
        <input type="hidden" name="act" value="bm_promo">
        <label class="hd-chk"><input type="checkbox" name="free_notice_on" value="1" <?php echo !empty($bot['free_notice_on']) ? 'checked' : ''; ?>> <span>نمایش اعلان فعال باشد</span></label>
        <div class="form-group"><textarea name="free_notice" rows="3" maxlength="600" placeholder="مثلاً: در نسخه رایگان روزانه ۵ پیام دارید. با تهیه بسته، به پاسخ‌های کامل‌تر و پرونده اختصاصی دسترسی پیدا می‌کنید."><?php echo saas_h($bot['free_notice'] ?? ''); ?></textarea></div>
        <div class="row2">
            <div class="form-group"><label>نمایش یک تبلیغ بعد از هر چند پاسخ (۰ = خاموش)</label><input type="number" name="ads_every" min="0" max="50" value="<?php echo (int)($bot['ads_every'] ?? 3); ?>"></div>
            <div class="form-group" style="display:flex;align-items:flex-end"><label class="hd-chk"><input type="checkbox" name="suggest_model" value="1" <?php echo !isset($bot['suggest_model']) || !empty($bot['suggest_model']) ? 'checked' : ''; ?>> <span>پیشنهاد مدل بهتر به مخاطب (وقتی سوال پیچیده است یا پاسخ کامل پیدا نشد)</span></label></div>
        </div>
        <button class="btn btn-primary btn-sm">💾 ذخیره</button>
    </form>
</div>

<div class="card" id="adform">
    <div class="card-title"><?php echo $ea ? '✏️ ویرایش تبلیغ' : '➕ تبلیغ جدید'; ?></div>
    <p class="hd-note" style="margin-bottom:10px">تبلیغ به‌صورت یک کارت (تصویر، متن و دکمه لینک) جدا از پاسخ هوش مصنوعی و فقط برای مخاطبان رایگان نمایش داده می‌شود. اگر چند تبلیغ فعال باشد، به نوبت نمایش داده می‌شوند.</p>
    <form method="post" enctype="multipart/form-data" data-pg="adform">
        <input type="hidden" name="act" value="ad_save"><input type="hidden" name="ad_id" value="<?php echo (int)($ea['id'] ?? 0); ?>">
        <div class="row2">
            <div class="form-group"><label>عنوان</label><input type="text" name="title" maxlength="200" value="<?php echo saas_h($ea['title'] ?? ''); ?>"></div>
            <div class="form-group"><label>ترتیب</label><input type="number" name="sort_order" value="<?php echo (int)($ea['sort_order'] ?? 0); ?>"></div>
        </div>
        <div class="form-group"><label>متن کوتاه</label><textarea name="body" rows="2" maxlength="600"><?php echo saas_h($ea['body'] ?? ''); ?></textarea></div>
        <div class="row2">
            <div class="form-group"><label>تصویر (اختیاری، حداکثر ۲ مگابایت)</label><input type="file" name="image" accept="image/*"><input type="text" name="image_url" dir="ltr" value="<?php echo saas_h($ea['image_url'] ?? ''); ?>" placeholder="یا آدرس تصویر https://…" style="margin-top:6px"></div>
            <div class="form-group"><label>دکمه و لینک</label><input type="text" name="btn_text" maxlength="80" value="<?php echo saas_h($ea['btn_text'] ?? ''); ?>" placeholder="متن دکمه، مثلاً: مشاهده"><input type="text" name="url" dir="ltr" value="<?php echo saas_h($ea['url'] ?? ''); ?>" placeholder="https://…" style="margin-top:6px"></div>
        </div>
        <label class="hd-chk"><input type="checkbox" name="is_active" value="1" <?php echo !$ea || !empty($ea['is_active']) ? 'checked' : ''; ?>> <span>فعال</span></label>
        <button class="btn btn-primary btn-sm"><?php echo $ea ? '💾 ذخیره' : '➕ افزودن تبلیغ'; ?></button>
        <?php if ($ea): ?><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=promo">انصراف</a><?php endif; ?>
    </form>
    <?php if ($ads): ?>
    <div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>تبلیغ</th><th>نمایش</th><th>کلیک</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($ads as $a): ?>
        <tr><td><?php if ($a['image_url'] !== ''): ?><img src="<?php echo saas_h($a['image_url']); ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;vertical-align:middle;margin-left:6px"><?php endif; ?><b><?php echo saas_h($a['title'] !== '' ? $a['title'] : mb_substr($a['body'], 0, 40)); ?></b></td>
            <td><?php echo number_format((int)$a['views']); ?></td><td><?php echo number_format((int)$a['clicks']); ?></td>
            <td><?php echo $a['is_active'] ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>'; ?></td>
            <td style="white-space:nowrap"><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=promo&edit_ad=<?php echo (int)$a['id']; ?>#adform">ویرایش</a>
                <form method="post" style="display:inline"><input type="hidden" name="act" value="ad_toggle"><input type="hidden" name="ad_id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $a['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><input type="hidden" name="act" value="ad_del"><input type="hidden" name="ad_id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<p class="hd-note">پاپ‌آپ برای مخاطبان این چت‌بات از صفحه <a href="popups.php">🪧 پاپ‌آپ‌ها</a> ساخته می‌شود.</p>
<?php
}

function bm_render_support($pdo, $bot, $self)
{
    $bid = (int)$bot['id'];
    if (!ex_owner_allows($pdo, (int)$bot['user_id'], 'has_member_support')) { bm_locked('پشتیبانی، تیکت و راهنمای مخاطبان چت‌بات'); return; }
    $sub = ($_GET['sub'] ?? '') === 'faq' ? 'faq' : 'guide';
    $open = 0;
    try { $s = $pdo->prepare("SELECT COUNT(*) FROM ex_tickets WHERE scope='bot' AND bot_id=? AND status<>'closed'"); $s->execute([$bid]); $open = (int)$s->fetchColumn(); } catch (\Throwable $e) {}
?>
<div class="card">
    <div class="card-title">🎧 پشتیبانی مخاطبان</div>
    <p class="hd-note" style="margin-bottom:10px">مخاطبان در صفحه چت دکمه «پشتیبانی» دارند: از <b>پشتیبان هوشمند</b> (بر اساس راهنما و سوالات متداول زیر) سوال می‌پرسند، راهنما را می‌خوانند و در صورت نیاز برای شما <b>تیکت</b> می‌فرستند. هزینه پاسخ‌های پشتیبان هوشمند مثل پیام‌های عادی از اعتبار شما کم می‌شود.</p>
    <form method="post" data-pg="bmsup" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="act" value="bm_support">
        <label class="hd-chk" style="margin:0"><input type="checkbox" name="support_on" value="1" <?php echo !isset($bot['support_on']) || !empty($bot['support_on']) ? 'checked' : ''; ?>> <span>بخش پشتیبانی در صفحه چت فعال باشد</span></label>
        <button class="btn btn-primary btn-sm">💾 ذخیره</button>
        <a class="btn btn-outline btn-sm" href="support.php?tab=bot&bot=<?php echo $bid; ?>">🎫 تیکت‌های مخاطبان<?php echo $open ? ' (' . $open . ' باز)' : ''; ?></a>
    </form>
</div>
<div style="display:flex;gap:6px;margin-bottom:12px">
    <a class="btn btn-sm <?php echo $sub === 'guide' ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo $self; ?>&tab=support">📘 راهنما</a>
    <a class="btn btn-sm <?php echo $sub === 'faq' ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo $self; ?>&tab=support&sub=faq">❓ سوالات متداول</a>
</div>
<?php echo ex_articles_ui($pdo, 'bot', $bid, $sub, $self . '&tab=support' . ($sub === 'faq' ? '&sub=faq' : ''));
}

function bm_render_topics($pdo, $bot, $self, $ctx)
{
    $bid = (int)$bot['id'];
    if (!ex_owner_allows($pdo, (int)$bot['user_id'], 'has_topic_ai')) { bm_locked('دسته‌بندی موضوعی هوشمند گفتگوها'); return; }
    $cats = ex_topic_cats($pdo, $bid, false);
    $ec = null;
    foreach ($cats as $c) if ((int)$c['id'] === (int)($_GET['edit_cat'] ?? 0)) $ec = $c;
    $team = $ctx['is_team'] ? $ctx['team'] : null;
    // مراجعانی که این کاربر اجازه دیدن پرونده‌شان را دارد
    $ms = $pdo->prepare("SELECT id, name, mobile, consent_share, consultant_id, is_owner FROM hd_members WHERE bot_id=? AND is_owner=0 AND consent_share=1");
    $ms->execute([$bid]);
    $vis = [];
    foreach ($ms->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) if (ex_member_visible($m, $team)) $vis[(int)$m['id']] = $m;
    $stats = [];
    if ($vis) {
        $st = $pdo->query("SELECT cat_id, member_id, COUNT(*) AS n, MAX(msg_at) AS last FROM ex_topic_hits WHERE bot_id=" . $bid . " AND member_id IN (" . implode(',', array_keys($vis)) . ") GROUP BY cat_id, member_id");
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $r) $stats[(int)$r['cat_id']][] = $r;
    }
    $pending = 0;
    try { $p = $pdo->prepare("SELECT COUNT(*) FROM hd_messages x JOIN hd_members m ON m.id=x.member_id WHERE x.bot_id=? AND x.role='user' AND x.topic_done=0 AND m.consent_share=1 AND m.is_owner=0"); $p->execute([$bid]); $pending = (int)$p->fetchColumn(); } catch (\Throwable $e) {}
?>
<div class="card">
    <div class="card-title">🗂 دسته‌بندی موضوعی هوشمند (محرمانه)</div>
    <p class="hd-note" style="line-height:2">دسته‌هایی مثل «استرس»، «مشکلات خواب» یا «روابط خانوادگی» تعریف کنید. هوش مصنوعی پیام‌های قبلی و جدید مراجعانی را که <b>اجازه دسترسی مشاور</b> داده‌اند بررسی می‌کند و هر پیامی را که به‌روشنی نشان‌دهنده یک دسته است، در آن دسته ثبت می‌کند.
    این دسته‌بندی فقط در پنل مشاور دیده می‌شود و مراجع از وجود آن خبر ندارد (جدا از دسته‌بندی گفتگوها که خود مراجع انجام می‌دهد). هزینه بررسی جزئی است و از اعتبار شما کم می‌شود.
    <?php if ($pending && $cats): ?><br>⏳ <?php echo number_format($pending); ?> پیام در صف بررسی است (هر ساعت و بعد از هر پیام جدید به‌تدریج انجام می‌شود).<?php endif; ?></p>
</div>
<?php if ($ctx['can_manage']): ?>
<div class="card" id="catform">
    <div class="card-title"><?php echo $ec ? '✏️ ویرایش دسته' : '➕ دسته جدید'; ?></div>
    <form method="post" data-pg="catform">
        <input type="hidden" name="act" value="tcat_save"><input type="hidden" name="cat_id" value="<?php echo (int)($ec['id'] ?? 0); ?>">
        <div class="row2">
            <div class="form-group"><label>نام دسته</label><input type="text" name="name" maxlength="120" required value="<?php echo saas_h($ec['name'] ?? ''); ?>" placeholder="مثلاً: استرس"></div>
            <div class="form-group"><label>ترتیب</label><input type="number" name="sort_order" value="<?php echo (int)($ec['sort_order'] ?? 0); ?>"></div>
        </div>
        <div class="form-group"><label>توضیح (نشانه‌هایی که هوش مصنوعی باید دنبالش باشد)</label><input type="text" name="description" maxlength="500" value="<?php echo saas_h($ec['description'] ?? ''); ?>" placeholder="مثلاً: نگرانی، اضطراب، تپش قلب، فشار کاری، بی‌قراری"></div>
        <label class="hd-chk"><input type="checkbox" name="is_active" value="1" <?php echo !$ec || !empty($ec['is_active']) ? 'checked' : ''; ?>> <span>فعال</span></label>
        <button class="btn btn-primary btn-sm"><?php echo $ec ? '💾 ذخیره' : '➕ افزودن'; ?></button>
        <?php if ($ec): ?><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=topics">انصراف</a><?php endif; ?>
    </form>
    <?php if ($cats): ?>
    <form method="post" style="margin-top:10px" data-pg="catscan" onsubmit="return confirm('همه پیام‌های مراجعان دوباره بررسی شوند؟ (هزینه جزئی از اعتبار شما)');"><input type="hidden" name="act" value="tcat_rescan"><button class="btn btn-outline btn-sm">🔄 بررسی دوباره همه پیام‌ها</button></form>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-title">📊 دسته‌ها و مراجعان</div>
    <?php if (!$cats): ?><p class="hd-note" style="text-align:center;padding:12px">هنوز دسته‌ای تعریف نشده است.</p><?php else: ?>
    <?php foreach ($cats as $c): $rows = $stats[(int)$c['id']] ?? []; usort($rows, fn($a, $b) => strcmp((string)$b['last'], (string)$a['last'])); ?>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;margin-bottom:8px;<?php echo $c['is_active'] ? '' : 'opacity:.6'; ?>">
        <summary style="cursor:pointer;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
            <span><b><?php echo saas_h($c['name']); ?></b> <span style="font-size:12px;color:#64748b"><?php echo count($rows); ?> مراجع — <?php echo number_format(array_sum(array_column($rows, 'n'))); ?> پیام<?php echo $c['is_active'] ? '' : ' — غیرفعال'; ?></span></span>
            <?php if ($ctx['can_manage']): ?><span style="display:flex;gap:4px"><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=topics&edit_cat=<?php echo (int)$c['id']; ?>#catform">ویرایش</a>
                <form method="post" style="display:inline"><input type="hidden" name="act" value="tcat_toggle"><input type="hidden" name="cat_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $c['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('دسته و پیام‌های ثبت‌شده در آن حذف شود؟');"><input type="hidden" name="act" value="tcat_del"><input type="hidden" name="cat_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form></span><?php endif; ?>
        </summary>
        <?php if ($c['description'] !== ''): ?><div style="font-size:12px;color:#64748b;margin-top:6px"><?php echo saas_h($c['description']); ?></div><?php endif; ?>
        <?php if ($rows): ?>
        <div class="table-wrap" style="margin-top:8px"><table><thead><tr><th>مراجع</th><th>تعداد پیام</th><th>آخرین</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): $m = $vis[(int)$r['member_id']] ?? null; if (!$m) continue; ?>
            <tr><td><?php echo saas_h($m['name']); ?> <span style="font-size:11px;color:#94a3b8;direction:ltr"><?php echo saas_h($m['mobile']); ?></span></td><td><?php echo (int)$r['n']; ?></td>
                <td style="font-size:12px"><?php echo $r['last'] ? biz_jdate($r['last'], true) : '—'; ?></td>
                <td><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=members&mid=<?php echo (int)$m['id']; ?>#topics">📋 پرونده</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><p style="font-size:12px;color:#94a3b8;margin-top:6px">هنوز پیامی در این دسته ثبت نشده است.</p><?php endif; ?>
    </details>
    <?php endforeach; endif; ?>
</div>
<?php
}

/** کارت‌های اضافه در پرونده مراجع: مشاور اختصاصی، سوابق نظارت، دسته‌های موضوعی */
function bm_member_extra($pdo, $bot, $mrow, $self, $ctx)
{
    $bid = (int)$bot['id'];
    $mid = (int)$mrow['id'];
    $sups = ex_supervisions($pdo, ['bot_id' => $bid, 'member_id' => $mid], 50);
    $nsup = ex_supervision_count($pdo, ['bot_id' => $bid, 'member_id' => $mid]);
    $cons = bm_consultants($pdo, (int)$bot['user_id']);
    $cur = (int)($mrow['consultant_id'] ?? 0);
    $cname = '';
    foreach ($cons as $c) if ((int)$c['id'] === $cur) $cname = $c['name'] . ($c['title'] !== '' ? ' (' . $c['title'] . ')' : '');
?>
<div class="card">
    <div class="card-title">👤 مشاور اختصاصی و سوابق نظارت</div>
    <?php if (!$ctx['is_team']): ?>
    <form method="post" data-pg="mcons" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px">
        <input type="hidden" name="act" value="member_consultant"><input type="hidden" name="member_id" value="<?php echo $mid; ?>">
        <div class="form-group" style="margin:0;flex:1;min-width:220px"><label>چه کسی به پرونده این مراجع دسترسی داشته باشد؟</label>
            <select name="consultant_id"><option value="0">همه مشاوران (بدون محدودیت)</option>
            <?php foreach ($cons as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $cur === (int)$c['id'] ? 'selected' : ''; ?>>فقط <?php echo saas_h($c['name'] . ($c['title'] !== '' ? ' (' . $c['title'] . ')' : '')); ?></option><?php endforeach; ?>
            </select></div>
        <button class="btn btn-primary btn-sm">💾 ذخیره</button>
    </form>
    <?php if (!$cons): ?><p class="hd-note">هنوز همکاری با دسترسی «مشاور» ندارید؛ از صفحه <a href="team.php">همکاران و مشاوران</a> اضافه کنید.</p><?php endif; ?>
    <?php else: ?>
    <p class="hd-note" style="margin-bottom:8px">مشاور اختصاصی: <b><?php echo $cur ? saas_h($cname ?: '—') : 'همه مشاوران'; ?></b></p>
    <?php endif; ?>
    <p style="font-size:13px;margin:6px 0">👁 تعداد دفعات نظارت (ورود مشاور به صفحه چت این مراجع): <b><?php echo number_format($nsup); ?></b> — این تعداد در «پرونده من» مراجع هم نمایش داده می‌شود.</p>
    <?php if ($sups): ?>
    <div class="table-wrap"><table><thead><tr><th>تاریخ و ساعت</th><th>مشاور</th></tr></thead><tbody>
    <?php foreach ($sups as $s): ?><tr><td style="font-size:12.5px"><?php echo biz_jdate($s['created_at'], true); ?></td><td style="font-size:12.5px"><?php echo saas_h($s['actor']); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<?php
    if (!ex_owner_allows($pdo, (int)$bot['user_id'], 'has_topic_ai')) return;
    $cats = ex_topic_cats($pdo, $bid, false);
    if (!$cats) return;
    $hits = ex_topic_hits_member($pdo, $bid, $mid);
?>
<div class="card" id="topics">
    <div class="card-title">🗂 دسته‌بندی موضوعی پیام‌ها <span style="font-weight:normal;font-size:12px;color:#94a3b8">(محرمانه — فقط برای مشاور؛ مراجع نمی‌بیند)</span></div>
    <?php foreach ($cats as $c): $hs = $hits[(int)$c['id']] ?? []; ?>
    <details style="border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;margin-bottom:8px" <?php echo $hs ? '' : ''; ?>>
        <summary style="cursor:pointer"><b><?php echo saas_h($c['name']); ?></b> <span style="font-size:12px;color:#64748b">(<?php echo count($hs); ?> پیام)</span></summary>
        <?php if (!$hs): ?><p style="font-size:12px;color:#94a3b8;margin-top:6px">پیامی در این دسته نیست.</p><?php endif; ?>
        <?php foreach ($hs as $h): ?>
        <div style="border-top:1px dashed #e2e8f0;padding:8px 0;font-size:13px;line-height:1.9">
            <div style="white-space:pre-wrap"><?php echo saas_h(mb_substr((string)$h['excerpt'], 0, 800)); ?></div>
            <div style="font-size:11.5px;color:#94a3b8"><?php echo $h['msg_at'] ? biz_jdate($h['msg_at'], true) : ''; ?><?php if ((int)$h['thread_id']): ?> — <a href="<?php echo $self; ?>&tab=chats&view=<?php echo (int)$h['thread_id']; ?>">مشاهده گفتگو</a><?php endif; ?></div>
        </div>
        <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
</div>
<?php
}
