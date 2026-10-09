<?php
/**
 * مدیریت یک چت‌بات تخصصی (همدم)
 * تب‌ها: تنظیمات، منابع دانش، پاسخ‌های تأییدشده، مخاطبان، گفتگوها، پلن‌ها و فروش، گزارش‌ها، نصب، آزمایش
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
$page_title = 'چت‌بات تخصصی';

try { hd_ensure_schema($pdo); } catch (\Throwable $e) { error_log('[HD] schema: ' . $e->getMessage()); }

$uid  = (int)$current_user['id'];
$plan = saas_get_plan($pdo, (int)($current_user['plan_id'] ?? 0)) ?: null;
if (!saas_plan_allows($plan, 'has_expert_bot')) { header('Location: bots.php'); exit; }

$bot = hd_get_bot($pdo, (int)($_GET['id'] ?? 0), $uid);
if (!$bot) { header('Location: bots.php'); exit; }
$bid  = (int)$bot['id'];
$page_title = 'چت‌بات: ' . $bot['name'];
// اشتراک‌های تمام‌شده/تمدیدشده: دسترسی همکاران به پرونده مخاطبان بدون اشتراک معلق می‌شود (includes/sup_lib.php)
if (function_exists('sup_sweep_bot_throttled')) sup_sweep_bot_throttled($pdo, $bid);
$tabs = ['settings' => '⚙️ تنظیمات', 'sources' => '📚 منابع دانش', 'qa' => '✅ پاسخ‌های تأییدشده', 'members' => '👥 مخاطبان',
         'chats' => '💬 گفتگوها', 'sales' => '💳 پلن‌ها و فروش', 'reports' => '📊 گزارش‌ها', 'install' => '🔌 نصب روی سایت', 'test' => '🧪 آزمایش'];
$api_cfg = saas_get_api_config($pdo);
$platform_host = strtolower((string)parse_url(AICHAT_BASE_URL, PHP_URL_HOST));
// همکار بدون دسترسی «مدیریت چت‌بات»: فقط گفتگوها، مخاطبان، گزارش‌ها و آزمایش
$can_manage = saas_can('bots_manage');
$is_team = !empty($current_team);
$can_consult = !$is_team || saas_can('consult');
$team_perms = $is_team ? ($current_team['perm_list'] ?? []) : [];
if (!$can_manage) foreach (['settings', 'sources', 'qa', 'sales', 'install'] as $tk) unset($tabs[$tk]);
if ($is_team && !$can_manage && !in_array('bots_view', $team_perms, true)) foreach (['reports', 'test'] as $tk) unset($tabs[$tk]);   // فقط مشاور
/** پرونده، یادداشت‌ها و گفتگوهای هر مراجع فقط با اجازه خود او دیده می‌شود (برای صاحب حساب و همکاران)؛ اگر مشاور اختصاصی تعیین شده، فقط همان مشاور */
$member_visible = function ($m) use ($is_team, $current_team) { return function_exists('ex_member_visible') ? ex_member_visible($m, $is_team ? $current_team : null) : (!empty($m['is_owner']) || !empty($m['consent_share'])); };
// زبانه‌های تکمیلی: تبلیغ و اعلان، پشتیبانی مخاطبان، دسته‌بندی موضوعی
require_once __DIR__ . '/bot_more.php';
$bm_tabs = bm_tabs($pdo, $uid, $can_manage, $can_consult);
if ($bm_tabs) {
    $nt = [];
    foreach ($tabs as $k => $v) { if ($k === 'reports') $nt += $bm_tabs; $nt[$k] = $v; }
    $tabs = $nt + $bm_tabs;
}
$bm_ctx = ['is_team' => $is_team, 'team' => $current_team ?? null, 'can_manage' => $can_manage, 'can_consult' => $can_consult];
$author_name = $is_team ? trim($current_team['name'] . ($current_team['title'] !== '' ? ' (' . $current_team['title'] . ')' : '')) : (string)$current_user['full_name'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : array_key_first($tabs);
$self = 'bot.php?id=' . $bid;

// =====================================================================
// آزمایش ربات (AJAX) — با حساب «صاحب ربات» (بدون محدودیت پیام؛ توکن مصرف می‌شود)
// =====================================================================
if (($_GET['ajax'] ?? '') === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ms = $pdo->prepare("SELECT * FROM hd_members WHERE bot_id=? AND is_owner=1 LIMIT 1");
    $ms->execute([$bid]);
    $owner = $ms->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
        $oid = hd_create_member($pdo, $bid, '', 'مدیر (آزمایش)', true, 1);
        $ms->execute([$bid]);
        $owner = $ms->fetch(PDO::FETCH_ASSOC);
    }
    if (!empty($in['reset'])) { echo json_encode(['ok' => true]); exit; }
    $res = hd_answer($pdo, $bot, $owner, (int)($in['thread_id'] ?? 0), (string)($in['message'] ?? ''));
    if (empty($res['ok'])) {
        $r = '';
        if (!hd_owner_ok($pdo, $bot, $r)) {
            $res['error'] = $r === 'credit' ? 'اعتبار حساب شما تمام شده است؛ حساب را شارژ کنید.' : ($r === 'plan' ? 'امکان چت‌بات در پلن شما فعال نیست.' : ($r === 'expired' ? 'سرویس شما به دلیل پایان اشتراک متوقف است؛ از بخش «اعتبار و پرداخت» اشتراک را تمدید کنید.' : $res['error']));
        } elseif (empty($bot['is_active'])) {
            $res['error'] = 'ربات غیرفعال است؛ از تب تنظیمات فعالش کنید.';
        }
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// دانلود «فایل رابط» (zip) با تنظیمات آماده
// =====================================================================
function hd_connector_files($bot, $dir = 'chat/')
{
    $server = rtrim(AICHAT_BASE_URL, '/') . '/hamdam/api.php';
    $config = "<?php\n// تنظیمات فایل رابط چت‌بات — این فایل محرمانه است؛ آن را با کسی به اشتراک نگذارید.\n"
            . "define('HD_SERVER', " . var_export($server, true) . ");\n"
            . "define('HD_KEY', " . var_export($bot['bot_key'], true) . ");\n";
    $ht = "# محافظت از فایل‌های محرمانه\nOptions -Indexes\n"
        . "<FilesMatch \"^(config\\.php)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n"
        . "DirectoryIndex index.php\n";
    $cache_ht = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $readme = "راهنمای نصب چت‌بات\n====================\n\n"
        . "۱) کل پوشه chat را در ریشه هاست سایت خود آپلود کنید (مثلاً public_html/chat).\n"
        . "۲) آدرس صفحه چت: https://yoursite.com/chat/\n"
        . "۳) برای دکمه شناور در همه صفحات سایت، این کد را قبل از </body> قرار دهید:\n"
        . "   <script src=\"https://yoursite.com/chat/?a=embed\" defer></script>\n"
        . "   (برای نمایش در سمت چپ: ?a=embed&pos=left)\n"
        . "۴) فایل config.php حاوی کلید محرمانه است؛ آن را منتشر نکنید.\n"
        . "۵) پیش‌نیاز: PHP 7.2 یا بالاتر با افزونه curl.\n";
    $files = [
        $dir . 'index.php'        => file_get_contents(dirname(__DIR__) . '/hamdam/connector/index.php'),
        $dir . 'config.php'       => $config,
        $dir . '.htaccess'        => $ht,
        $dir . 'cache/.htaccess'  => $cache_ht,
        $dir . 'cache/index.html' => '',
    ];
    if ($dir === 'chat/') $files['chat/README.txt'] = $readme;
    return $files;
}

/** افزونه وردپرس (بدون هیچ نام یا آدرسی از پلتفرم) */
function hd_wp_plugin_files($bot)
{
    $name = str_replace(['*/', "\n", "\r"], ' ', (string)$bot['name']);
    $main = <<<'PHPWP'
<?php
/*
Plugin Name: Chat Assistant — %NAME%
Description: دستیار هوشمند گفتگو (صفحه چت، دکمه شناور و کد کوتاه [chat_assistant])
Version: 1.0.0
Requires PHP: 7.2
*/
if (!defined('ABSPATH')) exit;

define('CHAT_ASSISTANT_URL', plugin_dir_url(__FILE__) . 'chat/');

function chat_assistant_opts()
{
    $o = get_option('chat_assistant_opts');
    return wp_parse_args(is_array($o) ? $o : [], ['floating' => 1, 'position' => 'right']);
}

// دکمه شناور در همه صفحات
add_action('wp_footer', function () {
    $o = chat_assistant_opts();
    if (empty($o['floating'])) return;
    $src = CHAT_ASSISTANT_URL . '?a=embed' . ($o['position'] === 'left' ? '&pos=left' : '');
    echo '<script src="' . esc_url($src) . '" defer></script>' . "
";
});

// کد کوتاه: [chat_assistant height="650"]
add_shortcode('chat_assistant', function ($atts) {
    $a = shortcode_atts(['height' => '650'], $atts);
    $h = max(300, min(2000, intval($a['height'])));
    return '<iframe src="' . esc_url(CHAT_ASSISTANT_URL . '?embed=2') . '" style="width:100%;height:' . $h . 'px;border:0;border-radius:16px" loading="lazy" allow="clipboard-write; microphone; autoplay" title="chat"></iframe>';
});

// صفحه تنظیمات
add_action('admin_menu', function () {
    add_options_page('دستیار گفتگو', 'دستیار گفتگو', 'manage_options', 'chat-assistant', 'chat_assistant_settings_page');
});
add_action('admin_init', function () {
    register_setting('chat_assistant', 'chat_assistant_opts', function ($in) {
        return [
            'floating' => empty($in['floating']) ? 0 : 1,
            'position' => (isset($in['position']) && $in['position'] === 'left') ? 'left' : 'right',
        ];
    });
});
function chat_assistant_settings_page()
{
    $o = chat_assistant_opts();
    ?>
    <div class="wrap" dir="rtl">
        <h1>دستیار گفتگو</h1>
        <form method="post" action="options.php">
            <?php settings_fields('chat_assistant'); ?>
            <table class="form-table">
                <tr><th>دکمه شناور</th><td><label><input type="checkbox" name="chat_assistant_opts[floating]" value="1" <?php checked($o['floating'], 1); ?>> نمایش دکمه گفتگو در همه صفحات سایت</label></td></tr>
                <tr><th>محل دکمه</th><td><select name="chat_assistant_opts[position]">
                    <option value="right" <?php selected($o['position'], 'right'); ?>>پایین راست</option>
                    <option value="left" <?php selected($o['position'], 'left'); ?>>پایین چپ</option></select></td></tr>
            </table>
            <?php submit_button('ذخیره'); ?>
        </form>
        <h2>روش‌های نمایش</h2>
        <p>صفحه کامل چت: <a href="<?php echo esc_url(CHAT_ASSISTANT_URL); ?>" target="_blank"><?php echo esc_html(CHAT_ASSISTANT_URL); ?></a></p>
        <p>نمایش داخل یک برگه یا نوشته: کد کوتاه <code>[chat_assistant]</code> یا <code>[chat_assistant height="700"]</code></p>
    </div>
    <?php
}
PHPWP;
    $main = str_replace('%NAME%', $name, $main);
    $files = ['chat-assistant/chat-assistant.php' => $main, 'chat-assistant/index.php' => "<?php // Silence is golden.\n"];
    foreach (hd_connector_files($bot, 'chat-assistant/chat/') as $k => $v) $files[$k] = $v;
    return $files;
}
if (!empty($_GET['download']) && $can_manage) {
    while (ob_get_level() > 0) ob_end_clean();   // هیچ خروجی دیگری قبل از فایل ارسال نشود
    $is_wp = $_GET['download'] === 'wp';
    $files = $is_wp ? hd_wp_plugin_files($bot) : hd_connector_files($bot);
    $zipname = $is_wp ? 'chat-assistant.zip' : 'chat.zip';
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'hdz');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $content) $zip->addFromString($name, $content);
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipname . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
    }
    // جایگزین: دانلود تک‌تک فایل‌ها
    $f = $_GET['file'] ?? '';
    if (isset($files[$f])) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        echo $files[$f];
        exit;
    }
    header('Location: ' . $self . '&tab=install&nozip=1'); exit;
}

// =====================================================================
// عملیات فرم‌ها
// =====================================================================
$msg = '';
$sync_report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if (!$can_manage && !in_array($act, ['member_add', 'member_toggle', 'member_login', 'note_save', 'note_del', 'mprofile_save', 'member_consultant', 'msg_comment', 'msg_comment_del'], true)) { header('Location: ' . $self . '&msg=' . urlencode('error:شما به این بخش دسترسی ندارید.')); exit; }
    bm_handle_post($pdo, $bot, $act, $self, $bm_ctx);

    // --- کامنت همکار روی پیام‌های گفتگو (با اجازه مراجع) ---
    if (in_array($act, ['msg_comment', 'msg_comment_del'], true) && function_exists('cx_comment_add')) {
        $tv = (int)($_POST['thread_id'] ?? 0);
        $tq = $pdo->prepare("SELECT t.id, t.member_id, m.consent_share, m.is_owner, m.consultant_id FROM hd_threads t JOIN hd_members m ON m.id=t.member_id WHERE t.id=? AND t.bot_id=?");
        $tq->execute([$tv, $bid]);
        $trow = $tq->fetch(PDO::FETCH_ASSOC);
        $back = $self . '&tab=chats&view=' . $tv;
        if (!$trow || !$can_consult || !$member_visible($trow)) { header('Location: ' . $self . '&tab=chats&msg=' . urlencode('error:دسترسی به این گفتگو ندارید (مراجع باید اجازه دسترسی همکاران را فعال کند).')); exit; }
        if ($act === 'msg_comment') {
            $r = cx_comment_add($pdo, $bot, (int)$trow['member_id'], (int)($_POST['message_id'] ?? 0), (string)($_POST['body'] ?? ''), !empty($_POST['show_member']), $author_name, (int)($current_team['id'] ?? 0));
            $msg = $r['ok'] ? 'ok:کامنت ثبت شد' . (!empty($_POST['show_member']) ? ' و زیر همان پیام به مراجع نمایش داده می‌شود' : ' (محرمانه)') . '؛ هوش مصنوعی در پاسخ‌های بعدی آن را اعمال می‌کند.' : 'error:' . $r['error'];
        } else {
            cx_comment_del($pdo, $bid, (int)$trow['member_id'], (int)($_POST['comment_id'] ?? 0));
            $msg = 'ok:کامنت حذف شد.';
        }
        header('Location: ' . $back . '&msg=' . urlencode($msg) . '#m' . (int)($_POST['message_id'] ?? 0)); exit;
    }

    // --- پرونده و یادداشت‌های مشاور ---
    if (in_array($act, ['note_save', 'note_del', 'mprofile_save'], true)) {
        $mid = (int)($_POST['member_id'] ?? 0);
        $mr = $pdo->prepare("SELECT * FROM hd_members WHERE id=? AND bot_id=?");
        $mr->execute([$mid, $bid]);
        $mrow = $mr->fetch(PDO::FETCH_ASSOC);
        $back = $self . '&tab=members&mid=' . $mid;
        if (!$mrow || !$can_consult || !$member_visible($mrow)) { header('Location: ' . $self . '&tab=members&msg=' . urlencode('error:دسترسی به پرونده این مراجع ندارید (مراجع باید اجازه دسترسی مشاوران را فعال کند).')); exit; }
        if ($act === 'note_save') {
            $content = trim((string)($_POST['content'] ?? ''));
            $nid = (int)($_POST['note_id'] ?? 0);
            $kind = array_key_exists($_POST['kind'] ?? '', hd_note_kinds()) ? $_POST['kind'] : 'note';
            $vals = [$kind, mb_substr(trim($_POST['title'] ?? ''), 0, 190), mb_substr($content, 0, 20000), !empty($_POST['show_member']) ? 1 : 0, !empty($_POST['use_ai']) ? 1 : 0, hd_now()];
            if (mb_strlen($content) < 2) $msg = 'error:متن یادداشت را بنویسید.';
            elseif ($nid) {
                $pdo->prepare("UPDATE hd_member_notes SET kind=?, title=?, content=?, show_member=?, use_ai=?, updated_at=? WHERE id=? AND bot_id=? AND member_id=?")->execute(array_merge($vals, [$nid, $bid, $mid]));
                $msg = 'ok:یادداشت ویرایش شد.';
            } else {
                $pdo->prepare("INSERT INTO hd_member_notes (kind, title, content, show_member, use_ai, updated_at, bot_id, member_id, author, author_team, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array_merge($vals, [$bid, $mid, mb_substr($author_name, 0, 140), (int)($current_team['id'] ?? 0), hd_now()]));
                $msg = 'ok:یادداشت ثبت شد' . (!empty($_POST['use_ai']) ? ' و از این پس در پاسخ‌های هوش مصنوعی در نظر گرفته می‌شود.' : '.');
            }
        } elseif ($act === 'note_del') {
            $pdo->prepare("DELETE FROM hd_member_notes WHERE id=? AND bot_id=? AND member_id=?")->execute([(int)($_POST['note_id'] ?? 0), $bid, $mid]);
            $msg = 'ok:یادداشت حذف شد.';
        } else {
            $pr = [];
            foreach ((array)($_POST['pk'] ?? []) as $i => $k) { $v = (string)($_POST['pv'][$i] ?? ''); if (trim($k) !== '' && trim($v) !== '') $pr[$k] = $v; }
            hd_profile_save($pdo, $mid, $pr);
            $msg = 'ok:پرونده ذخیره شد.';
        }
        header('Location: ' . $back . '&msg=' . urlencode($msg)); exit;
    }

    // --- تنظیمات ---
    if ($act === 'settings') {
        $data = [
            'name'            => mb_substr(trim($_POST['name'] ?? $bot['name']), 0, 150) ?: $bot['name'],
            'specialty'       => mb_substr(trim($_POST['specialty'] ?? ''), 0, 200),
            'welcome_message' => mb_substr(trim($_POST['welcome_message'] ?? ''), 0, 1000),
            'instructions'    => mb_substr(trim($_POST['instructions'] ?? ''), 0, 6000),
            'disclaimer'      => mb_substr(trim($_POST['disclaimer'] ?? ''), 0, 500),
            'response_length' => in_array($_POST['response_length'] ?? '', ['short', 'medium', 'long'], true) ? $_POST['response_length'] : 'medium',
            'intake_questions'=> mb_substr(trim(str_replace("\r", '', (string)($_POST['intake_questions'] ?? ''))), 0, 5000),
            'intake_strict'   => !empty($_POST['intake_strict']) ? 1 : 0,
            'allow_model_choice' => !empty($_POST['allow_model_choice']) ? 1 : 0,
            'voice_on'        => !empty($_POST['voice_on']) ? 1 : 0,
            'files_on'        => !empty($_POST['files_on']) ? 1 : 0,
            'show_sources'    => !empty($_POST['show_sources']) ? 1 : 0,
            'verify_mobile'   => !empty($_POST['verify_mobile']) ? 1 : 0,
            'free_messages'   => max(0, min(100000, (int)($_POST['free_messages'] ?? 20))),
            'daily_limit'     => max(0, min(10000, (int)($_POST['daily_limit'] ?? 20))),
            'is_active'       => !empty($_POST['is_active']) ? 1 : 0,
            'verify_answers'  => !empty($_POST['verify_answers']) ? 1 : 0,
            'live_search'     => 0,
            'support_text'    => mb_substr(trim($_POST['support_text'] ?? ''), 0, 300),
            'sms_api_key'     => mb_substr(trim($_POST['sms_api_key'] ?? ''), 0, 200),
            'sms_sender'      => mb_substr(preg_replace('/[^0-9+]/', '', (string)($_POST['sms_sender'] ?? '')), 0, 20),
            'updated_at'      => hd_now(),
        ];
        if (isset($_POST['limit_in'])) { $data['limit_in'] = max(0, (int)$_POST['limit_in']); $data['limit_out'] = max(0, (int)($_POST['limit_out'] ?? 0)); }
        // روش‌های جذب مخاطب، صفحه یکپارچه و ساخت تصویر/ویدیو (نسخه ۳۲)
        if (array_key_exists('acq_free_on', $bot)) {
            $data['acq_free_on'] = !empty($_POST['acq_free_on']) ? 1 : 0;
            $data['gift_on'] = !empty($_POST['gift_on']) ? 1 : 0;
            $data['gift_messages'] = max(0, min(100000, (int)($_POST['gift_messages'] ?? 0)));
            $data['free_media'] = !empty($_POST['free_media']) ? 1 : 0;
            $data['hub_show'] = !empty($_POST['hub_show']) ? 1 : 0;
            $data['gen_image_cost'] = max(1, min(1000, (int)($_POST['gen_image_cost'] ?? ($bot['gen_image_cost'] ?? 5))));
            $data['gen_video_cost'] = max(1, min(5000, (int)($_POST['gen_video_cost'] ?? ($bot['gen_video_cost'] ?? 20))));
            $data['upgrade_text'] = mb_substr(trim((string)($_POST['upgrade_text'] ?? '')), 0, 300);
            // گالری طرح‌ها در این چت‌بات (نسخه ۵۱) — در تنظیمات عمومی ذخیره می‌شود
            if (isset($_POST['gal_form']) && function_exists('biz_set')) biz_set($pdo, 'gal_off_' . (int)$bot['id'], !empty($_POST['gal_on']) ? '0' : '1');
            if (array_key_exists('rem_on', $bot)) $data['rem_on'] = !empty($_POST['rem_on']) ? 1 : 0;
            if (array_key_exists('win_hours', $bot)) {
                $data['win_hours'] = in_array((int)($_POST['win_hours'] ?? 0), [0, 1, 2, 3, 4, 5, 6, 8, 12, 24], true) ? (int)$_POST['win_hours'] : 0;
                $data['win_free'] = max(0, min(10000, (int)($_POST['win_free'] ?? 0)));
            }
            if (!$data['acq_free_on'] && !$data['gift_on'] && !hd_bot_sells($pdo, $bot) && !(function_exists('cx_has_free_plan') && cx_has_free_plan($pdo, $bot))) {
                $msg = 'error:دست‌کم یکی از روش‌ها (چت رایگان، شارژ هدیه یا فروش بسته) باید فعال باشد؛ وگرنه مخاطبان نمی‌توانند پیامی بفرستند.';
            }
        }
        if (isset($_POST['ai_model_id'])) {
            $mid = (int)$_POST['ai_model_id'];
            $data['ai_model_id'] = biz_model_allowed($pdo, $plan, $mid, 'bot') ? $mid : 0;
        }
        if (isset($_POST['free_model_id'])) {
            $mid = (int)$_POST['free_model_id'];
            $data['free_model_id'] = biz_model_allowed($pdo, $plan, $mid, 'free') ? $mid : 0;
        }
        // مدل پیش‌فرض ساخت تصویر / ویدیو (نسخه ۵۹)
        if (function_exists('mx_schema')) mx_schema($pdo);
        foreach (['img_model_id' => 'image', 'vid_model_id' => 'video'] as $fk => $kk) if (isset($_POST[$fk]) && function_exists('cx_gen_models')) {
            $mid = (int)$_POST[$fk];
            $data[$fk] = in_array($mid, array_map(fn($m) => (int)$m['id'], cx_gen_models($pdo, $bot, $kk)), true) ? $mid : 0;
        }
        if (!empty($_FILES['avatar']['name'])) {
            $up = saas_save_icon_upload($_FILES['avatar'], $uid, 'bot');
            if ($up['ok']) $data['avatar_url'] = $up['url']; else $msg = 'error:' . $up['error'];
        } elseif (!empty($_POST['avatar_remove'])) {
            $data['avatar_url'] = '';
        }
        if ($msg === '') {
            $sets = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($data)));
            $pdo->prepare("UPDATE hd_bots SET {$sets} WHERE id=? AND user_id=?")->execute(array_merge(array_values($data), [$bid, $uid]));
            $msg = 'ok:تنظیمات ذخیره شد. (تغییرات ظاهری حداکثر تا ۵ دقیقه در صفحه چت اعمال می‌شود)';
        }
        header('Location: ' . $self . '&tab=settings&msg=' . urlencode($msg)); exit;
    }

    // --- پایگاه دانش مرکزی ---
    if ($act === 'central_sets') {
        $avail = array_map('intval', array_column(hd_central_sets($pdo, true), 'id'));
        $posted = array_map('intval', (array)($_POST['sets'] ?? []));
        $auto = function_exists('kb_auto_sets_for_bot') ? kb_auto_sets_for_bot($pdo, $bot) : [];
        // مجموعه‌های خودکار نوع ربات: تیک‌نخورده = خاموش برای این ربات؛ بقیه: انتخاب دستی
        $sel = array_values(array_diff(array_intersect($avail, $posted), $auto));
        $off = array_values(array_diff($auto, $posted));
        $pdo->prepare("UPDATE hd_bots SET central_sets=?, updated_at=? WHERE id=? AND user_id=?")->execute([implode(',', $sel), hd_now(), $bid, $uid]);
        try { $pdo->prepare("UPDATE hd_bots SET central_off=? WHERE id=? AND user_id=?")->execute([implode(',', $off), $bid, $uid]); } catch (\Throwable $e) {}
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode('ok:پایگاه‌های دانش مرکزی به‌روز شد.')); exit;
    }

    // --- منابع ---
    if ($act === 'add_text') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if (!empty($_FILES['txt']['tmp_name']) && is_uploaded_file($_FILES['txt']['tmp_name'])) {
            if ($_FILES['txt']['size'] > 3 * 1024 * 1024) $msg = 'error:حجم فایل بیشتر از ۳ مگابایت است.';
            elseif (strtolower(pathinfo($_FILES['txt']['name'], PATHINFO_EXTENSION)) !== 'txt') $msg = 'error:فقط فایل .txt مجاز است.';
            else {
                $content = trim((string)file_get_contents($_FILES['txt']['tmp_name']));
                if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1256');
                if ($title === '') $title = pathinfo($_FILES['txt']['name'], PATHINFO_FILENAME);
            }
        }
        if ($msg === '') {
            if (mb_strlen($title) < 2) $msg = 'error:عنوان منبع را وارد کنید.';
            elseif (mb_strlen($content) < 30) $msg = 'error:متن منبع خیلی کوتاه است.';
            else {
                $sid = hd_add_source($pdo, $bid, 'text', $title, '', $content);
                hd_sync_source($pdo, $sid);
                $msg = 'ok:متن به منابع اضافه شد.';
            }
        }
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode($msg)); exit;
    }
    if ($act === 'add_file') {
        $f = $_FILES['doc'] ?? null;
        $title = trim($_POST['title'] ?? '');
        if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            $msg = 'error:فایلی انتخاب نشده است.';
        } elseif ($f['size'] > 10 * 1024 * 1024) {
            $msg = 'error:حجم فایل بیشتر از ۱۰ مگابایت است.';
        } elseif (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['pdf', 'docx', 'txt'], true)) {
            $msg = 'error:فقط فایل‌های PDF، Word (.docx) و متنی (.txt) مجاز است.';
        } else {
            @set_time_limit(120);
            $text = hd_extract_file($f['tmp_name'], $f['name']);
            if (mb_strlen(trim($text)) < 30) {
                $msg = 'error:متنی از این فایل خوانده نشد. اگر PDF اسکن‌شده (تصویری) است، متن آن قابل استخراج نیست؛ متن را به‌صورت تایپی وارد کنید.';
            } else {
                if ($title === '') $title = pathinfo($f['name'], PATHINFO_FILENAME);
                $sid = hd_add_source($pdo, $bid, 'file', $title, '', mb_substr($text, 0, 2000000));
                hd_sync_source($pdo, $sid);
                $msg = 'ok:فایل «' . $f['name'] . '» خوانده شد و به منابع اضافه شد (' . number_format(mb_strlen($text)) . ' کاراکتر).';
            }
        }
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode($msg)); exit;
    }
    if ($act === 'add_url') {
        $kind = ($_POST['kind'] ?? 'page') === 'site' ? 'site' : 'page';
        $url = trim($_POST['url'] ?? '');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $msg = 'error:آدرس معتبر نیست.';
        } else {
            $title = trim($_POST['title'] ?? '') ?: (string)parse_url($url, PHP_URL_HOST);
            $sid = hd_add_source($pdo, $bid, $kind, $title, $url);
            @set_time_limit(120);
            $sync_report = hd_sync_source($pdo, $sid, 30, 40);
            $msg = $sync_report['error'] !== ''
                ? 'error:' . $sync_report['error']
                : 'ok:منبع اضافه شد — ' . $sync_report['done'] . ' صفحه خوانده شد' . ($sync_report['remaining'] > 0 ? ' و ' . $sync_report['remaining'] . ' صفحه باقی مانده (دکمه «همگام‌سازی» را بزنید یا صبر کنید تا خودکار خوانده شود).' : '.');
        }
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode($msg)); exit;
    }
    if ($act === 'sync_source') {
        $sid = (int)($_POST['source_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM hd_sources WHERE id=? AND bot_id=?");
        $chk->execute([$sid, $bid]);
        if ($chk->fetchColumn()) {
            @set_time_limit(120);
            $r = hd_sync_source($pdo, $sid, 40, 45);
            $msg = $r['error'] !== '' ? 'error:' . $r['error'] : 'ok:' . $r['done'] . ' صفحه خوانده شد' . ($r['remaining'] > 0 ? '؛ ' . $r['remaining'] . ' صفحه باقی مانده — دوباره «همگام‌سازی» را بزنید.' : '؛ منبع کامل است.');
        }
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode($msg)); exit;
    }
    if ($act === 'src_toggle') {
        $v = hd_source_toggle($pdo, (int)($_POST['source_id'] ?? 0), $bid);
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode($v === null ? 'error:منبع یافت نشد.' : ($v ? 'ok:منبع فعال شد و در پاسخ‌ها استفاده می‌شود.' : 'ok:منبع غیرفعال شد و تا فعال‌سازی دوباره در پاسخ‌ها استفاده نمی‌شود.'))); exit;
    }
    if ($act === 'src_edit') {
        $sid = (int)($_POST['source_id'] ?? 0);
        $r = hd_source_update($pdo, $sid, $bid, $_POST);
        header('Location: ' . $self . '&tab=sources' . ($r['ok'] ? '' : '&edit_src=' . $sid) . '&msg=' . urlencode($r['ok'] ? 'ok:' . $r['msg'] : 'error:' . $r['error'])); exit;
    }
    if ($act === 'del_source') {
        // حذف دومرحله‌ای: بدون تأیید نهایی (نوشتن «حذف») چیزی پاک نمی‌شود
        if (function_exists('ui_del2_ok') && !ui_del2_ok()) { header('Location: ' . $self . '&tab=sources&msg=' . urlencode('error:حذف تأیید نشد؛ برای حذف باید در مرحله دوم کلمه «حذف» را بنویسید.')); exit; }
        hd_delete_source($pdo, (int)($_POST['source_id'] ?? 0), $bid);
        header('Location: ' . $self . '&tab=sources&msg=' . urlencode('ok:منبع حذف شد.')); exit;
    }

    // --- پاسخ‌های تأییدشده ---
    if ($act === 'qa_save') {
        $q = mb_substr(trim($_POST['question'] ?? ''), 0, 500);
        $a = trim($_POST['answer'] ?? '');
        $qid = (int)($_POST['qa_id'] ?? 0);
        if (mb_strlen($q) < 3 || mb_strlen($a) < 2) $msg = 'error:سوال و پاسخ را کامل وارد کنید.';
        elseif ($qid) {
            $pdo->prepare("UPDATE hd_qa SET question=?, answer=? WHERE id=? AND bot_id=?")->execute([$q, $a, $qid, $bid]);
            $msg = 'ok:پاسخ ویرایش شد.';
        } else {
            $pdo->prepare("INSERT INTO hd_qa (bot_id, question, answer, created_at) VALUES(?,?,?,?)")->execute([$bid, $q, $a, hd_now()]);
            $msg = 'ok:پاسخ تأییدشده اضافه شد.';
        }
        header('Location: ' . $self . '&tab=qa&msg=' . urlencode($msg)); exit;
    }
    if ($act === 'qa_toggle') {
        $pdo->prepare("UPDATE hd_qa SET is_active = 1 - is_active WHERE id=? AND bot_id=?")->execute([(int)($_POST['qa_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=qa&msg=' . urlencode('ok:وضعیت پاسخ تغییر کرد.')); exit;
    }
    if ($act === 'qa_del') {
        $pdo->prepare("DELETE FROM hd_qa WHERE id=? AND bot_id=?")->execute([(int)($_POST['qa_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=qa&msg=' . urlencode('ok:حذف شد.')); exit;
    }

    // --- مخاطبان ---
    if ($act === 'member_add') {
        $n = max(-100000, min(100000, (int)($_POST['messages'] ?? 0)));
        $pdo->prepare("UPDATE hd_members SET extra_messages = extra_messages + ? WHERE id=? AND bot_id=?")->execute([$n, (int)($_POST['member_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=members&msg=' . urlencode('ok:سهمیه پیام به‌روز شد.')); exit;
    }
    if ($act === 'member_login') {
        // ورود به صفحه چت به‌جای مخاطب (لینک یک‌بارمصرف)
        $mid = (int)($_POST['member_id'] ?? 0);
        $url = trim((string)($_POST['chat_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $url = mb_substr(preg_replace('/[?#].*$/', '', $url), 0, 300);
            $pdo->prepare("UPDATE hd_bots SET chat_url=? WHERE id=?")->execute([$url, $bid]);
        } else {
            $url = (string)($bot['custom_domain'] ?? '') !== '' ? 'https://' . $bot['custom_domain'] . '/' : (string)($bot['chat_url'] ?? '');
        }
        $chk = $pdo->prepare("SELECT * FROM hd_members WHERE id=? AND bot_id=? AND consent_share=1");
        $chk->execute([$mid, $bid]);
        $lrow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$lrow) { header('Location: ' . $self . '&tab=members&msg=' . urlencode('error:این مراجع اجازه دسترسی به پرونده و گفتگوهایش را نداده است.')); exit; }
        if (!$can_consult || !$member_visible($lrow)) { header('Location: ' . $self . '&tab=members&msg=' . urlencode('error:این مراجع مشاور اختصاصی دیگری دارد.')); exit; }
        if ($url === '') { header('Location: ' . $self . '&tab=members&msg=' . urlencode('error:آدرس صفحه چت روی سایت خود را وارد کنید.')); exit; }
        $code = hd_member_magic_code($pdo, $bid, $mid, $author_name);
        if (function_exists('ex_supervision_log')) ex_supervision_log($pdo, $bot, $mid, (int)($current_team['id'] ?? 0), $author_name);   // ثبت نظارت مشاور
        header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . 'a=as&c=' . $code); exit;
    }
    if ($act === 'member_toggle') {
        $pdo->prepare("UPDATE hd_members SET status = CASE WHEN status='active' THEN 'blocked' ELSE 'active' END WHERE id=? AND bot_id=? AND is_owner=0")
            ->execute([(int)($_POST['member_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=members'); exit;
    }

    // --- فروش: درگاه ---
    if ($act === 'sales_settings') {
        $mer = function_exists('comm_zp_clean_merchant') ? comm_zp_clean_merchant($_POST['zp_merchant'] ?? '') : trim($_POST['zp_merchant'] ?? '');
        if ($mer !== '' && !(function_exists('comm_zp_valid_merchant') ? comm_zp_valid_merchant($mer) : preg_match('/^[A-Za-z0-9-]{20,40}$/', $mer))) {
            $msg = 'error:کد مرچنت زرین‌پال معتبر نیست (۳۶ کاراکتر، مانند xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).';
        } else {
            $pdo->prepare("UPDATE hd_bots SET zp_merchant=?, zp_sandbox=?, remind_members=?, updated_at=? WHERE id=? AND user_id=?")
                ->execute([$mer, !empty($_POST['zp_sandbox']) ? 1 : 0, !empty($_POST['remind_members']) ? 1 : 0, hd_now(), $bid, $uid]);
            $msg = 'ok:تنظیمات درگاه ذخیره شد.';
            if (!empty($_POST['zp_test']) && $mer !== '' && function_exists('comm_zp_test')) {
                $zt = comm_zp_test($pdo, $mer, !empty($_POST['zp_sandbox']));
                $msg = $zt['ok'] ? 'ok:ذخیره شد. ' . $zt['msg'] : 'error:ذخیره شد، اما آزمایش درگاه ناموفق بود — ' . $zt['error'];
            }
        }
        header('Location: ' . $self . '&tab=sales&msg=' . urlencode($msg)); exit;
    }
    // --- فروش: بسته‌ها ---
    if ($act === 'plan_save') {
        $pid = (int)($_POST['plan_id'] ?? 0);
        $d = [
            mb_substr(trim($_POST['name'] ?? ''), 0, 100),
            mb_substr(trim($_POST['description'] ?? ''), 0, 300),
            max(0, min(100000000, (int)($_POST['price_toman'] ?? 0))),
            max(1, min(1000000, (int)($_POST['messages'] ?? 100))),
            max(0, min(3650, (int)($_POST['days'] ?? 30))),
            max(0, min(10000, (int)($_POST['daily_limit'] ?? 0))),
            (int)($_POST['sort_order'] ?? 0),
            !empty($_POST['is_active']) ? 1 : 0,
            max(0, min(90, (int)($_POST['discount_percent'] ?? 0))),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['discount_until'] ?? '')) ? $_POST['discount_until'] . ' 23:59:59' : null,
        ];
        if (mb_strlen($d[0]) < 2) $msg = 'error:نام بسته را وارد کنید.';
        elseif ($d[2] > 0 && $d[2] < 1000) $msg = 'error:قیمت بسته حداقل ۱٬۰۰۰ تومان است (یا ۰ برای بسته رایگان).';
        elseif ($pid) {
            $pdo->prepare("UPDATE hd_member_plans SET name=?, description=?, price_toman=?, messages=?, days=?, daily_limit=?, sort_order=?, is_active=?, discount_percent=?, discount_until=? WHERE id=? AND bot_id=?")
                ->execute(array_merge($d, [$pid, $bid]));
            $msg = 'ok:بسته ویرایش شد.';
        } else {
            $pdo->prepare("INSERT INTO hd_member_plans (name, description, price_toman, messages, days, daily_limit, sort_order, is_active, discount_percent, discount_until, bot_id, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($d, [$bid, hd_now()]));
            $pid = (int)$pdo->lastInsertId();
            $msg = 'ok:بسته اضافه شد.';
        }
        if ($pid && str_starts_with($msg, 'ok:')) {
            // امکانات بسته (هر خط یک مورد؛ ترتیب خطوط = اولویت نمایش)
            $feat = implode("\n", array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 200), preg_split('/\r\n|\r|\n/', (string)($_POST['features'] ?? ''))), fn($x) => $x !== '')), 0, 30));
            try { $pdo->prepare("UPDATE hd_member_plans SET features=? WHERE id=? AND bot_id=?")->execute([$feat, $pid, $bid]); } catch (\Throwable $e) {}
            // امکانات بسته (فایل، صوت، ساخت تصویر/ویدیو) و چت‌بات‌های دیگری که این بسته در آن‌ها هم معتبر است
            if (function_exists('cx_flags_str')) {
                $mine = array_map('intval', array_column(hd_user_bots($pdo, $uid), 'id'));
                $others = array_values(array_intersect($mine, array_map('intval', (array)($_POST['plan_bots'] ?? []))));
                $others = array_values(array_diff($others, [$bid]));
                try { $pdo->prepare("UPDATE hd_member_plans SET flags=?, bot_ids=? WHERE id=? AND bot_id=?")->execute([cx_flags_str((array)($_POST['flags'] ?? [])), cx_ids_str($others), $pid, $bid]); } catch (\Throwable $e) {}
                try { $pdo->prepare("UPDATE hd_member_plans SET win_msgs=? WHERE id=? AND bot_id=?")->execute([max(0, min(100000, (int)($_POST['win_msgs'] ?? 0))), $pid, $bid]); } catch (\Throwable $e) {}
                try { $pdo->prepare("UPDATE hd_member_plans SET rem_sms=? WHERE id=? AND bot_id=?")->execute([max(0, min(100000, (int)($_POST['rem_sms'] ?? 0))), $pid, $bid]); } catch (\Throwable $e) {}
                if (function_exists('lim_ensure_schema')) { lim_ensure_schema($pdo); try { $pdo->prepare("UPDATE hd_member_plans SET week_limit=?, month_limit=? WHERE id=? AND bot_id=?")->execute([max(0, min(1000000, (int)($_POST['week_limit'] ?? 0))), max(0, min(1000000, (int)($_POST['month_limit'] ?? 0))), $pid, $bid]); } catch (\Throwable $e) {} }
            }
            // نوع بسته (نسخه ۶۲): پیامی یا شارژ تومانی
            if (function_exists('mcr_schema') && isset($_POST['pkind'])) {
                mcr_schema($pdo);
                $pk = ($_POST['pkind'] ?? '') === 'credit' ? 'credit' : 'msgs';
                $cr = max(0, min(1000000000, (int)preg_replace('/\D/', '', strtr((string)($_POST['credit_toman'] ?? ''), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']))));
                if ($pk === 'credit' && $cr <= 0) $cr = $d[2];
                if ($pk === 'credit' && $cr <= 0) { $msg = 'error:مبلغ شارژ بسته تومانی را وارد کنید.'; $pk = 'msgs'; }
                try { $pdo->prepare("UPDATE hd_member_plans SET pkind=?, credit_toman=? WHERE id=? AND bot_id=?")->execute([$pk, $pk === 'credit' ? $cr : 0, $pid, $bid]); } catch (\Throwable $e) { error_log('[MCR] plan: ' . $e->getMessage()); }
            }
            // مدل‌های بسته (نسخه ۵۹): فعال/غیرفعال و سقف تعداد استفاده هر مدل در هر خرید
            if (function_exists('mx_cfg_from_post') && isset($_POST['mcfg_ids'])) {
                mx_schema($pdo);
                try { $pdo->prepare("UPDATE hd_member_plans SET model_cfg=? WHERE id=? AND bot_id=?")->execute([mx_cfg_from_post($_POST), $pid, $bid]); } catch (\Throwable $e) { error_log('[MX] plan cfg: ' . $e->getMessage()); }
            }
        }
        header('Location: ' . $self . '&tab=sales&msg=' . urlencode($msg)); exit;
    }
    // --- کدهای تخفیف بسته‌ها ---
    if ($act === 'code_save') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['code'] ?? '')));
        $kind = ($_POST['kind'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $val = max(1, (int)($_POST['value'] ?? 0));
        if ($kind === 'percent') $val = min(100, $val);
        $ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['ends_at'] ?? '')) ? $_POST['ends_at'] . ' 23:59:59' : null;
        if (strlen($code) < 3) $msg = 'error:کد تخفیف حداقل ۳ حرف یا عدد انگلیسی باشد.';
        else {
            $du = $pdo->prepare("SELECT COUNT(*) FROM hd_discount_codes WHERE bot_id=? AND code=?");
            $du->execute([$bid, $code]);
            if ((int)$du->fetchColumn() > 0) $msg = 'error:این کد قبلاً ساخته شده است.';
            else {
                $pdo->prepare("INSERT INTO hd_discount_codes (bot_id, code, kind, value, plan_id, max_uses, per_member_limit, ends_at, used_count, is_active, created_at) VALUES(?,?,?,?,?,?,?,?,0,1,?)")
                    ->execute([$bid, $code, $kind, $val, (int)($_POST['plan_id'] ?? 0), max(0, (int)($_POST['max_uses'] ?? 0)), max(0, (int)($_POST['per_member_limit'] ?? 1)), $ends, hd_now()]);
                $msg = 'ok:کد تخفیف «' . $code . '» ساخته شد.';
            }
        }
        header('Location: ' . $self . '&tab=sales&msg=' . urlencode($msg) . '#codes'); exit;
    }
    if ($act === 'code_toggle') {
        $pdo->prepare("UPDATE hd_discount_codes SET is_active = 1 - is_active WHERE id=? AND bot_id=?")->execute([(int)($_POST['code_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=sales#codes'); exit;
    }
    if ($act === 'code_del') {
        $pdo->prepare("DELETE FROM hd_discount_codes WHERE id=? AND bot_id=?")->execute([(int)($_POST['code_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=sales&msg=' . urlencode('ok:کد تخفیف حذف شد.') . '#codes'); exit;
    }
    if ($act === 'plan_del') {
        $pdo->prepare("DELETE FROM hd_member_plans WHERE id=? AND bot_id=?")->execute([(int)($_POST['plan_id'] ?? 0), $bid]);
        header('Location: ' . $self . '&tab=sales&msg=' . urlencode('ok:بسته حذف شد. (خریدهای قبلی مخاطبان معتبر می‌ماند)')); exit;
    }

    // --- دامنه اختصاصی ---
    if ($act === 'set_domain') {
        $dom = strtolower(trim(preg_replace('#^https?://#i', '', trim($_POST['custom_domain'] ?? '')), ' /'));
        $dom = preg_replace('#/.*$#', '', $dom);
        if ($dom !== '' && !preg_match('/^([a-z0-9-]+\.){2,}[a-z]{2,}$/', $dom)) {
            $msg = 'error:یک زیردامنه معتبر وارد کنید (مثلاً chat.yoursite.com).';
        } elseif ($dom !== '' && ($dom === $platform_host || substr($dom, -strlen('.' . $platform_host)) === '.' . $platform_host)) {
            $msg = 'error:این دامنه قابل استفاده نیست.';
        } else {
            $du = $pdo->prepare("SELECT COUNT(*) FROM hd_bots WHERE custom_domain=? AND id<>?");
            $du->execute([$dom, $bid]);
            if ($dom !== '' && (int)$du->fetchColumn() > 0) $msg = 'error:این دامنه قبلاً برای ربات دیگری ثبت شده است.';
            else {
                $dom_fee = (int)biz_get($pdo, 'domain_fee');
                if ($dom !== '' && $dom_fee > 0 && empty($bot['domain_paid'])) {
                    // هزینه راه‌اندازی زیردامنه → ابتدا پرداخت، سپس ثبت خودکار دامنه
                    header('Location: ../payment/checkout.php?type=domain&bot=' . $bid . '&domain=' . urlencode($dom)); exit;
                }
                $pdo->prepare("UPDATE hd_bots SET custom_domain=?, updated_at=? WHERE id=? AND user_id=?")->execute([$dom, hd_now(), $bid, $uid]);
                if ($dom !== '' && $dom !== (string)($bot['custom_domain'] ?? '')) {
                    biz_service_request($pdo, $uid, 'domain', $dom, 0, 0, $bid);   // اطلاع به مدیر برای فعال‌سازی روی سرور
                    $pdo->prepare("UPDATE hd_bots SET domain_paid=1 WHERE id=?")->execute([$bid]);   // راه‌اندازی رایگان ثبت شد
                }
                $msg = $dom === '' ? 'ok:دامنه اختصاصی حذف شد.' : 'ok:دامنه ثبت شد. مراحل زیر را انجام دهید.';
            }
        }
        header('Location: ' . $self . '&tab=install&msg=' . urlencode($msg)); exit;
    }

    // --- کلید ---
    if ($act === 'regen_key') {
        $pdo->prepare("UPDATE hd_bots SET bot_key=?, updated_at=? WHERE id=? AND user_id=?")->execute([hd_new_key(), hd_now(), $bid, $uid]);
        header('Location: ' . $self . '&tab=install&msg=' . urlencode('ok:کلید جدید ساخته شد. «فایل رابط» را دوباره دانلود و روی سایت جایگزین کنید (فقط config.php تغییر کرده است).')); exit;
    }
}
if (isset($_GET['msg'])) $msg = $_GET['msg'];
$bot = hd_get_bot($pdo, $bid, $uid);

include __DIR__ . '/_header.php';
?>
<style>
.hd-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.hd-tabs a{padding:8px 14px;border-radius:10px;background:#fff;border:1px solid #e2e8f0;color:#475569;font-size:13px;text-decoration:none;font-weight:600}
.hd-tabs a.on{background:#2563eb;color:#fff;border-color:#2563eb}
.hd-chk{display:flex;align-items:flex-start;gap:8px;font-size:13px;margin:8px 0;cursor:pointer;line-height:1.8}
.hd-chk input{width:17px !important;height:17px;margin-top:4px;flex-shrink:0}
.hd-note{font-size:12px;color:#64748b;line-height:1.9}
.hd-st{font-size:11px;padding:2px 8px;border-radius:10px;font-weight:700}
.hd-st.ready{background:#dcfce7;color:#166534}.hd-st.syncing{background:#fef9c3;color:#854d0e}.hd-st.pending{background:#e0e7ff;color:#3730a3}.hd-st.error{background:#fee2e2;color:#991b1b}
.hd-code{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px 14px;font-family:monospace;font-size:12.5px;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;margin:8px 0}
.hd-msg{padding:10px 13px;border-radius:12px;margin:8px 0;font-size:13px;line-height:1.9;max-width:85%;white-space:pre-wrap}
.hd-msg.user{background:#2563eb;color:#fff;margin-right:auto;margin-left:0}
.hd-msg.assistant{background:#f1f5f9;color:#1e293b}
.hd-src{font-size:11.5px;color:#64748b;margin-top:6px;border-top:1px dashed #cbd5e1;padding-top:5px;white-space:normal}
.hd-src a{color:#2563eb}
</style>

<div class="topbar">
    <h1>🤖 <?php echo saas_h($bot['name']); ?></h1>
    <a href="bots.php" class="btn btn-outline btn-sm">← همه ربات‌ها</a>
</div>

<?php if ($msg): ?>
<div class="alert <?php echo str_starts_with($msg, 'ok:') ? 'alert-success' : 'alert-danger'; ?>"><?php echo saas_h(preg_replace("/^(ok|error):/", "", $msg)); ?></div>
<?php endif; ?>

<div class="hd-tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="<?php echo $self; ?>&tab=<?php echo $k; ?>" class="<?php echo $tab === $k ? 'on' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'settings'): ?>
<!-- ================= تنظیمات ================= -->
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="act" value="settings">
    <?php $model_sel = biz_model_select_html($pdo, $plan, $bot['ai_model_id'] ?? 0, 'ai_model_id', 'bot'); $free_sel = biz_model_select_html($pdo, $plan, $bot['free_model_id'] ?? 0, 'free_model_id', 'free'); if ($model_sel !== '' || $free_sel !== ''): ?>
    <div class="card">
        <div class="card-title">🧠 مدل هوش مصنوعی این ربات</div>
        <?php if ($model_sel !== ''): ?><div class="form-group"><label>مدل پاسخ‌گویی (مخاطبانی که بسته خریده‌اند و آزمایش شما)</label><?php echo $model_sel; ?></div><?php endif; ?>
        <?php if ($free_sel !== ''): ?><div class="form-group" style="margin-bottom:0"><label>مدل چت‌های رایگان (وقتی مخاطب از پیام‌های رایگان استفاده می‌کند)</label><?php echo $free_sel; ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title">🪪 هویت ربات (با نام و برند خودتان)</div>
        <div class="row2">
            <div class="form-group"><label>نام ربات</label><input type="text" name="name" maxlength="150" value="<?php echo saas_h($bot['name']); ?>" required></div>
            <div class="form-group"><label>عنوان تخصص (زیر نام نمایش داده می‌شود)</label><input type="text" name="specialty" maxlength="200" value="<?php echo saas_h($bot['specialty']); ?>"></div>
        </div>
        <div class="row2">
            <div class="form-group">
                <label>رنگ</label>
                <?php $wcol = (string)(saas_get_widget_settings($pdo, $uid)['primary_color'] ?? '#2563eb'); ?>
                <div style="display:flex;gap:8px;align-items:center;font-size:13px"><span style="width:28px;height:28px;border-radius:8px;background:<?php echo saas_h($wcol); ?>;display:inline-block"></span> رنگ چت‌بات‌ها همان رنگ ویجت است — <a href="settings.php">تغییر در تنظیمات ظاهر</a></div>
            </div>
            <div class="form-group">
                <label>آواتار / لوگو (PNG، JPG، WEBP — مربعی، حداکثر ۳۰۰ کیلوبایت)</label>
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif">
                <?php if ($bot['avatar_url']): ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:6px"><img src="<?php echo saas_h($bot['avatar_url']); ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover"> <label class="hd-chk" style="margin:0"><input type="checkbox" name="avatar_remove" value="1"> حذف آواتار</label></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-group"><label>پیام خوش‌آمدگویی</label><textarea name="welcome_message" rows="2" maxlength="1000"><?php echo saas_h($bot['welcome_message']); ?></textarea></div>
    </div>

    <div class="card">
        <div class="card-title">🧠 رفتار و تخصص</div>
        <div class="form-group">
            <label>دستورالعمل تخصصی (نقش، لحن، محدودیت‌ها)</label>
            <textarea name="instructions" rows="7" maxlength="6000"><?php echo saas_h($bot['instructions']); ?></textarea>
            <div class="hd-note">مثلاً: «فقط درباره تغذیه و رژیم غذایی پاسخ بده؛ سوالات نامرتبط را مؤدبانه رد کن.»</div>
        </div>
        <div class="form-group">
            <label>متن هشدار / سلب مسئولیت (بالای صفحه چت نمایش داده می‌شود)</label>
            <textarea name="disclaimer" rows="2" maxlength="500"><?php echo saas_h($bot['disclaimer']); ?></textarea>
        </div>
        <div class="row2">
            <div class="form-group">
                <label>طول پاسخ‌ها</label>
                <select name="response_length">
                    <?php foreach (['short' => 'کوتاه', 'medium' => 'متوسط', 'long' => 'مفصل'] as $k => $l): ?>
                        <option value="<?php echo $k; ?>" <?php echo $bot['response_length'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div></div>
        </div>
        <?php $pl_in = (int)($plan['limit_in'] ?? 0); $pl_out = (int)($plan['limit_out'] ?? 0); ?>
        <div class="row2">
            <div class="form-group"><label>🔢 سقف توکن ورودی هر پیام (۰ = <?php echo $pl_in ? 'سقف پلن: ' . number_format($pl_in) : 'بدون سقف'; ?>)</label><input type="number" name="limit_in" min="0" step="100" value="<?php echo (int)($bot['limit_in'] ?? 0); ?>"></div>
            <div class="form-group"><label>🔢 سقف توکن خروجی هر پیام (۰ = <?php echo $pl_out ? 'سقف پلن: ' . number_format($pl_out) : 'بدون سقف'; ?>)</label><input type="number" name="limit_out" min="0" step="50" value="<?php echo (int)($bot['limit_out'] ?? 0); ?>"></div>
        </div>
        <p class="hd-note" style="margin:-4px 0 10px">برای کنترل هزینه: ورودی = سوال + سابقه گفتگو + اطلاعات منابع؛ خروجی = طول پاسخ. اگر ورودی بیشتر شود، قدیمی‌ترین بخش‌ها کوتاه می‌شود. سقف پلن شما همیشه رعایت می‌شود.</p>
        <?php $lv = hd_bot_levels($pdo, $bot); ?>
        <div class="hd-note" style="margin:6px 0 8px">🎚 واقع‌گرایی پاسخ‌ها: <b><?php echo $lv['realism']; ?>٪</b> — پایبندی به منابع: <b><?php echo $lv['grounding']; ?>٪</b> (توسط مدیر سامانه تنظیم می‌شود)</div>
        <label class="hd-chk"><input type="checkbox" name="show_sources" value="1" <?php echo $bot['show_sources'] ? 'checked' : ''; ?>>
            <span>نمایش منابع (عنوان و لینک) زیر هر پاسخ</span></label>
    </div>

    <div class="card">
        <div class="card-title">🧭 شناخت مراجع پیش از مشاوره</div>
        <p class="hd-note" style="margin-bottom:8px">ربات اول این اطلاعات را (هر بار حداکثر ۲ سوال) از مراجع می‌پرسد و در «پرونده» او ذخیره می‌کند؛ چیزی را که قبلاً گفته دوباره نمی‌پرسد و بعد با توجه به آن مشاوره می‌دهد. هر خط یک مورد: <code>عنوان | سوال نمونه</code></p>
        <textarea name="intake_questions" rows="7" dir="rtl" placeholder="نام | اسم شما چیست؟&#10;سن | چند سال دارید؟&#10;شغل | شغل شما چیست؟"><?php echo saas_h(hd_intake_text($bot)); ?></textarea>
        <label class="hd-chk"><input type="checkbox" name="intake_strict" value="1" <?php echo !empty($bot['intake_strict']) ? 'checked' : ''; ?>>
            <span>تا این اطلاعات کامل نشده، مشاوره تخصصی ندهد (پیشنهادی). بدون این گزینه، به سوال‌های فوری کوتاه پاسخ می‌دهد و بعد پرسش‌ها را ادامه می‌دهد.</span></label>
        <p class="hd-note">خالی گذاشتن فهرست = بدون مرحله شناخت. اطلاعات دیگری که مراجع درباره خودش بگوید (مذهب، ورزش، شغل و...) هم خودکار به پرونده اضافه می‌شود.</p>
    </div>

    <div class="card">
        <div class="card-title">💬 امکانات صفحه چت</div>
        <?php $hs = hd_sys($pdo); ?>
        <label class="hd-chk"><input type="checkbox" name="allow_model_choice" value="1" <?php echo !empty($bot['allow_model_choice']) ? 'checked' : ''; ?>>
            <span>مراجع بتواند مدل پاسخ‌گو را انتخاب کند (منوی کنار کادر تایپ؛ مدل گران‌تر = مصرف بیشتر از سهمیه پیام او به همان نسبت)</span></label>
        <label class="hd-chk"><input type="checkbox" name="files_on" value="1" <?php echo !empty($bot['files_on']) ? 'checked' : ''; ?> <?php echo empty($hs['hd_files_on']) ? 'disabled' : ''; ?>>
            <span>ارسال فایل در چت (تصویر، PDF، Word، متن — حداکثر <?php echo HD_MAX_FILES; ?> فایل در هر پیام)<?php echo empty($hs['hd_files_on']) ? ' — توسط مدیر سامانه غیرفعال است' : ''; ?></span></label>
        <label class="hd-chk"><input type="checkbox" name="voice_on" value="1" <?php echo !empty($bot['voice_on']) ? 'checked' : ''; ?> <?php echo !hd_audio_ready($pdo) ? 'disabled' : ''; ?>>
            <span>پیام صوتی (تبدیل صدای مراجع به متن) و دکمه «🔊 پخش» برای شنیدن پاسخ‌ها<?php echo !hd_audio_ready($pdo) ? ' — توسط مدیر سامانه فعال نشده است' : ''; ?></span></label>
        <p class="hd-note">هزینه پیام صوتی و پخش صوتی از اعتبار (تومانی) حساب شما کم می‌شود.</p>
    </div>

    <div class="card">
        <div class="card-title">🎯 روش‌های جذب مخاطب (رایگان)</div>
        <p class="hd-note" style="margin-bottom:8px">هر دو روش را می‌توانید هم‌زمان یا جداگانه فعال کنید. پس از تمام شدن سهمیه رایگان، مخاطب می‌تواند از صفحه «⭐ بسته‌ها و ارتقا» بسته بخرد.</p>
        <label class="hd-chk"><input type="checkbox" name="acq_free_on" value="1" <?php echo !isset($bot['acq_free_on']) || !empty($bot['acq_free_on']) ? 'checked' : ''; ?>>
            <span><b>۱) چت رایگان محدود با مدل‌های ارزان:</b> مخاطب با «مدل چت‌های رایگان» (بالا) گفتگو می‌کند؛ مدل‌های تخصصی به‌صورت کم‌رنگ با پیشنهاد ارتقا نمایش داده می‌شوند.</span></label>
        <div class="row2" style="margin-right:26px">
            <div class="form-group"><label>تعداد پیام رایگان هر مخاطب (۰ = نامحدود)</label><input type="number" name="free_messages" min="0" value="<?php echo (int)$bot['free_messages']; ?>"></div>
            <div class="form-group"><label>سقف پیام روزانه هر مخاطب (۰ = نامحدود)</label><input type="number" name="daily_limit" min="0" value="<?php echo (int)$bot['daily_limit']; ?>"></div>
        </div>
        <label class="hd-chk"><input type="checkbox" name="gift_on" value="1" <?php echo !empty($bot['gift_on']) ? 'checked' : ''; ?>>
            <span><b>۲) شارژ هدیه با مدل‌های تخصصی:</b> هر مخاطب جدید تعدادی پیام هدیه می‌گیرد که با «مدل پاسخ‌گویی» تخصصی (و امکان ارسال فایل و پیام صوتی) استفاده می‌شود؛ بعد از آن به چت رایگان (اگر فعال باشد) برمی‌گردد.</span></label>
        <div class="row2" style="margin-right:26px">
            <div class="form-group"><label>تعداد پیام هدیه هر مخاطب</label><input type="number" name="gift_messages" min="0" value="<?php echo (int)($bot['gift_messages'] ?? 0); ?>"></div>
            <div></div>
        </div>
        <label class="hd-chk"><input type="checkbox" name="free_media" value="1" <?php echo !empty($bot['free_media']) ? 'checked' : ''; ?>>
            <span>در چت رایگان هم ارسال تصویر/فایل و پیام صوتی مجاز باشد (پیش‌فرض: فقط در بسته‌های تخصصی)</span></label>
        <?php if (array_key_exists('win_hours', $bot)): ?>
        <div style="border:1px dashed #c4b5fd;border-radius:12px;padding:10px 12px;margin-top:10px">
            <b style="font-size:13px">⏱ سقف دوره‌ای پیام (مثل Claude)</b>
            <p class="hd-note" style="margin:4px 0 8px">مثلاً «هر ۵ ساعت حداکثر ۲۰ پیام». وقتی مخاطب به سقف برسد، کنار کادر تایپ دقیقاً اعلام می‌شود از چه ساعتی دوباره می‌تواند پیام بدهد (با دکمه ارتقا). سقف چت رایگان و شارژ هدیه اینجا؛ سقف هر بسته در تب «پلن‌ها و فروش».</p>
            <div class="row2">
                <div class="form-group"><label>طول هر بازه</label><select name="win_hours">
                    <?php foreach ([0 => 'خاموش (بدون سقف دوره‌ای)', 1 => 'هر ۱ ساعت', 2 => 'هر ۲ ساعت', 3 => 'هر ۳ ساعت', 4 => 'هر ۴ ساعت', 5 => 'هر ۵ ساعت', 6 => 'هر ۶ ساعت', 8 => 'هر ۸ ساعت', 12 => 'هر ۱۲ ساعت', 24 => 'هر ۲۴ ساعت'] as $wk => $wl): ?><option value="<?php echo $wk; ?>" <?php echo (int)($bot['win_hours'] ?? 0) === $wk ? 'selected' : ''; ?>><?php echo $wl; ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>حداکثر پیام در هر بازه — چت رایگان و هدیه (۰ = بدون سقف)</label><input type="number" name="win_free" min="0" value="<?php echo (int)($bot['win_free'] ?? 0); ?>"></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin-top:8px"><label>متن پیشنهاد ارتقا (وقتی مخاطب روی امکان قفل‌شده کلیک می‌کند — خالی = متن پیش‌فرض)</label>
            <input type="text" name="upgrade_text" maxlength="300" value="<?php echo saas_h($bot['upgrade_text'] ?? ''); ?>" placeholder="مثلاً: با بسته طلایی، به مدل‌های تخصصی، تحلیل تصویر و پیام صوتی دسترسی دارید 🚀"></div>
    </div>

    <?php $cx_img = function_exists('cx_gen_models') && cx_gen_models($pdo, $bot, 'image'); $cx_vid = function_exists('cx_gen_models') && cx_gen_models($pdo, $bot, 'video'); ?>
    <div class="card">
        <div class="card-title">🎨 ساخت تصویر و ویدیو در چت‌بات</div>
        <?php if (!$cx_img && !$cx_vid): ?>
        <p class="hd-note">در پلن فعلی شما مدل ساخت تصویر یا ویدیو وجود ندارد.</p>
        <input type="hidden" name="gen_image_cost" value="<?php echo (int)($bot['gen_image_cost'] ?? 5); ?>"><input type="hidden" name="gen_video_cost" value="<?php echo (int)($bot['gen_video_cost'] ?? 20); ?>">
        <?php else: ?>
        <p class="hd-note" style="margin-bottom:8px">مخاطبانی که بسته‌ای با امکان «ساخت تصویر» یا «ساخت ویدیو» خریده‌اند (تب «پلن‌ها و فروش»)، از دکمه ✨ کنار کادر پیام استفاده می‌کنند، یا فقط در گفتگو می‌نویسند «یک پوستر … بساز»؛ بعد می‌توانند در همان گفتگو تغییرات را بگویند («رنگش را آبی کن»، «نوشته را بزرگ‌تر کن») یا روی تصویر «✏️ ویرایش» بزنند و با 📎 عکس خودشان را برای ویرایش بفرستند. درخواست فارسی پیش از ارسال با مدل مترجم ارزان به انگلیسی تبدیل می‌شود. هزینه واقعی ساخت از اعتبار تومانی حساب شما کم می‌شود؛ اینجا تعیین کنید هر مورد چند پیام از سهمیه مخاطب کم کند.</p>
        <?php $gsel = function ($kk, $fk) use ($pdo, $bot) { $ms = cx_gen_models($pdo, $bot, $kk); if (!$ms) return ''; $cur = (int)($bot[$fk] ?? 0);
            $h = '<select name="' . $fk . '"><option value="0">— اولین مدل (' . saas_h($ms[0]['title']) . ') —</option>';
            foreach (biz_models_by_price($pdo, $ms) as $m) $h .= '<option value="' . (int)$m['id'] . '"' . ((int)$m['id'] === $cur ? ' selected' : '') . '>' . saas_h($m['title']) . ' — ' . saas_h(biz_model_cost_label($pdo, $m)) . '</option>';
            return $h . '</select>'; }; $gi = $gsel('image', 'img_model_id'); $gv = $gsel('video', 'vid_model_id'); ?>
        <?php if ($gi !== '' || $gv !== ''): ?>
        <div class="row2">
            <div class="form-group"><label>🖼 مدل پیش‌فرض ساخت تصویر</label><?php echo $gi ?: '<div class="hd-note">—</div>'; ?></div>
            <div class="form-group"><label>🎬 مدل پیش‌فرض ساخت ویدیو</label><?php echo $gv ?: '<div class="hd-note">—</div>'; ?></div>
        </div>
        <p class="hd-note" style="margin:-6px 0 10px">اگر «مخاطب بتواند مدل را انتخاب کند» روشن باشد، مخاطب هنگام ساخت تصویر/ویدیو از بین همه مدل‌های فعال بسته‌اش انتخاب می‌کند. مدل‌هایی که بعداً به سایت اضافه شوند خودکار در این فهرست قرار می‌گیرند؛ در تب «پلن‌ها و فروش» می‌توانید برای هر بسته، هر مدل را غیرفعال کنید یا تعداد استفاده‌اش را محدود کنید.</p>
        <?php endif; ?>
        <div class="row2">
            <div class="form-group"><label>هر تصویر = چند پیام از سهمیه مخاطب</label><input type="number" name="gen_image_cost" min="1" value="<?php echo (int)($bot['gen_image_cost'] ?? 5); ?>" <?php echo $cx_img ? '' : 'disabled'; ?>></div>
            <div class="form-group"><label>هر ویدیو (۴ ثانیه) = چند پیام از سهمیه مخاطب</label><input type="number" name="gen_video_cost" min="1" value="<?php echo (int)($bot['gen_video_cost'] ?? 20); ?>" <?php echo $cx_vid ? '' : 'disabled'; ?>></div>
        </div>
        <?php if (function_exists('gal_kind_ready') && (($cx_img && gal_kind_ready($pdo, 'image')) || ($cx_vid && gal_kind_ready($pdo, 'video'))) && (biz_settings($pdo)['gal_bot_off'] ?? '') !== '1'): ?>
        <input type="hidden" name="gal_form" value="1">
        <label class="hd-chk"><input type="checkbox" name="gal_on" value="1" <?php echo (biz_settings($pdo)['gal_off_' . (int)$bot['id']] ?? '') !== '1' ? 'checked' : ''; ?>> 🖼 گالری طرح‌های آماده نمایش داده شود</label>
        <p class="hd-note">مخاطب از دکمه ✨ گزینه «از روی نمونه‌ها» را می‌زند، در دسته‌ها و زیردسته‌ها می‌گردد یا جستجو می‌کند (جستجوی هوشمند هم دارد)، طرح را انتخاب می‌کند و فقط اطلاعات خودش (نام، شعار، رنگ…) را می‌نویسد. هزینه هر طرح مثل ساخت تصویر/ویدیو است.</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (array_key_exists('rem_on', $bot)): ?>
    <div class="card">
        <div class="card-title">⏰ یادآور برای مخاطبان</div>
        <label class="hd-chk"><input type="checkbox" name="rem_on" value="1" <?php echo !empty($bot['rem_on']) ? 'checked' : ''; ?>> مخاطبان بتوانند برای خودشان یادآور بگذارند</label>
        <p class="hd-note">مخاطب در گفتگو می‌نویسد «یادم بنداز فردا ساعت ۱۰ جلسه با آقای احمدی» یا از بخش «⏰ یادآورها» ثبت می‌کند؛ یادآور با اعلان داخل صفحه چت و اعلان روی گوشی (رایگان) و در صورت داشتن سهمیه، با پیامک ارسال می‌شود. سهمیه پیامک یادآور را در بسته‌ها (تب «پلن‌ها و فروش») تعیین کنید. شما هم می‌توانید از منوی «یادآورها و برنامه‌ها» برای مخاطبانتان یادآور بگذارید.</p>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">👥 دسترسی مخاطبان</div>
        <label class="hd-chk"><input type="checkbox" name="hub_show" value="1" <?php echo !isset($bot['hub_show']) || !empty($bot['hub_show']) ? 'checked' : ''; ?>>
            <span>نمایش این چت‌بات در <b>صفحه یکپارچه همه چت‌بات‌های شما</b> (مخاطب با یک ورود، همه چت‌بات‌هایی را که دارد یا خریده در یک صفحه می‌بیند — لینک در تب «نصب روی سایت»)</span></label>
        <div class="hd-note">هزینه هر پیام به تومان از اعتبار حساب شما کم می‌شود. از تب «مخاطبان» می‌توانید به هر مخاطب پیام اضافه بدهید.</div>
        <label class="hd-chk"><input type="checkbox" name="verify_mobile" value="1" <?php echo $bot['verify_mobile'] ? 'checked' : ''; ?>>
            <span>تأیید شماره موبایل با کد پیامکی هنگام ورود (پیامک با نام ربات شما ارسال می‌شود؛ برای امنیت، اگر ارسال پیامک ممکن نباشد ثبت‌نام بدون کد انجام نمی‌شود)</span></label>
        <label class="hd-chk"><input type="checkbox" name="is_active" value="1" <?php echo $bot['is_active'] ? 'checked' : ''; ?>> <span>ربات فعال باشد</span></label>
        <div class="form-group" style="margin-top:10px">
            <label>متن پشتیبانی / راه ارتباطی (در کنار صفحه چت و هنگام اتمام پیام‌ها نمایش داده می‌شود)</label>
            <textarea name="support_text" rows="2" maxlength="300" placeholder="مثلاً: پشتیبانی: ۰۲۱-۱۲۳۴۵۶۷۸ (شنبه تا چهارشنبه ۹ تا ۱۷)"><?php echo saas_h($bot['support_text'] ?? ''); ?></textarea>
        </div>
    </div>

    <div class="card">
        <div class="card-title">🎯 دقت پاسخ</div>
        <label class="hd-chk"><input type="checkbox" name="verify_answers" value="1" <?php echo !empty($bot['verify_answers']) ? 'checked' : ''; ?>>
            <span><b>حالت دقت بالا (بازبینی دوم):</b> هر پاسخ پیش از ارسال یک بار دیگر با منابع مقایسه می‌شود و هر ادعای بدون پشتوانه حذف می‌شود. دقت بیشتر، ولی پاسخ کمی کندتر است و تقریباً دو برابر توکن مصرف می‌کند.</span></label>
    </div>

    <div class="card">
        <div class="card-title">✉️ پنل پیامک اختصاصی (اختیاری)</div>
        <p class="hd-note">اگر پنل پیامک «کاوه‌نگار» دارید، کلید آن را وارد کنید تا کد ورود مخاطبان از <b>شماره اختصاصی خودتان</b> ارسال شود. در غیر این صورت پیامک‌ها از <b>اعتبار پیامک حساب شما</b> (پیامک رایگان پلن + بسته پیامک خریداری‌شده) ارسال می‌شود.</p>
        <?php $smsq = biz_sms_status($pdo, $uid); ?>
        <div class="alert <?php echo $smsq['total_left'] > 0 ? 'alert-info' : 'alert-danger'; ?>" style="margin:8px 0">
            ✉️ اعتبار پیامک شما: <b><?php echo number_format($smsq['total_left']); ?></b> پیامک
            (رایگان پلن: <?php echo number_format($smsq['free_left']); ?> از <?php echo number_format($smsq['free_total']); ?> — خریداری‌شده: <?php echo number_format($smsq['balance']); ?>)
            — <a href="billing.php#sms">خرید بسته پیامک</a>
            <?php if ($smsq['total_left'] <= 0): ?><br>اعتبار پیامک ندارید؛ تا زمان خرید، ورود مخاطبان بدون کد تأیید انجام می‌شود.<?php endif; ?>
        </div>
        <div class="row2">
            <div class="form-group"><label>کلید API کاوه‌نگار</label><input type="text" name="sms_api_key" dir="ltr" maxlength="200" value="<?php echo saas_h($bot['sms_api_key'] ?? ''); ?>" autocomplete="off"></div>
            <div class="form-group"><label>شماره فرستنده</label><input type="text" name="sms_sender" dir="ltr" maxlength="20" value="<?php echo saas_h($bot['sms_sender'] ?? ''); ?>" placeholder="10008663"></div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-bottom:30px">💾 ذخیره تنظیمات</button>
</form>

<?php elseif ($tab === 'sources'): ?>
<!-- ================= منابع ================= -->
<?php
$src_st = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM hd_chunks c WHERE c.source_id=s.id) AS chunks FROM hd_sources s WHERE s.bot_id=? ORDER BY s.id DESC");
$src_st->execute([$bid]);
$sources = $src_st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$st_label = ['ready' => 'آماده', 'syncing' => 'در حال خواندن', 'pending' => 'در انتظار', 'error' => 'خطا'];
?>
<?php $edit_src = !empty($_GET['edit_src']) ? hd_source_get($pdo, (int)$_GET['edit_src'], $bid) : null; if ($edit_src): ?>
<div class="card" id="srcedit">
    <div class="card-title">✏️ ویرایش منبع: <?php echo saas_h($edit_src['title']); ?></div>
    <?php echo hd_source_edit_form($edit_src, '', $self . '&tab=sources'); ?>
</div>
<?php endif; ?>
<?php $csets = hd_central_sets($pdo, true); if ($csets):
    $cur_sets = array_map('intval', array_filter(explode(',', (string)($bot['central_sets'] ?? ''))));
    $auto_sets = function_exists('kb_auto_sets_for_bot') ? kb_auto_sets_for_bot($pdo, $bot) : [];
    $off_sets = function_exists('kb_bot_off_sets') ? kb_bot_off_sets($bot) : [];
    usort($csets, fn($a, $b) => (int)in_array((int)$b['id'], $auto_sets, true) <=> (int)in_array((int)$a['id'], $auto_sets, true));
    $tpl_label = trim(preg_replace('/^\S+\s/u', '', (string)(hd_templates()[$bot['template'] ?? 'custom']['label'] ?? ''))); ?>
<div class="card">
    <div class="card-title">📚 پایگاه دانش مرکزی (آماده)</div>
    <p class="hd-note" style="margin-bottom:8px">این مجموعه‌ها آماده و به‌روز هستند. هر مجموعه تیک‌خورده در کنار منابع خودتان برای پاسخ استفاده می‌شود. مجموعه‌های «✨ مخصوص» این نوع ربات خودکار فعال‌اند؛ اگر نمی‌خواهید، تیکشان را بردارید و ذخیره کنید.</p>
    <form method="post">
        <input type="hidden" name="act" value="central_sets">
        <?php foreach ($csets as $cs): ?>
        <?php $is_auto = in_array((int)$cs['id'], $auto_sets, true); $is_on = $is_auto ? !in_array((int)$cs['id'], $off_sets, true) : in_array((int)$cs['id'], $cur_sets, true); ?>
        <label class="hd-chk"><input type="checkbox" name="sets[]" value="<?php echo (int)$cs['id']; ?>" <?php echo $is_on ? 'checked' : ''; ?>>
            <span><b><?php echo saas_h($cs['title']); ?></b><?php if ($is_auto): ?> <span class="badge badge-green" style="font-size:11px">✨ مخصوص «<?php echo saas_h($tpl_label); ?>» — خودکار فعال</span><?php endif; ?> <span style="color:#94a3b8;font-size:12px">(<?php echo number_format((int)$cs['chunks']); ?> بخش دانش)</span><?php if ($cs['description'] !== ''): ?><br><span class="hd-note"><?php echo saas_h($cs['description']); ?></span><?php endif; ?></span></label>
        <?php endforeach; ?>
        <button class="btn btn-primary btn-sm">💾 ذخیره</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">🌐 افزودن سایت یا صفحه معتبر</div>
    <form method="post">
        <input type="hidden" name="act" value="add_url">
        <div class="row2">
            <div class="form-group"><label>آدرس</label><input type="text" name="url" dir="ltr" placeholder="https://example.com/articles" required></div>
            <div class="form-group"><label>عنوان (اختیاری)</label><input type="text" name="title" maxlength="300" placeholder="مثلاً: وب‌سایت وزارت بهداشت"></div>
        </div>
        <label class="hd-chk"><input type="radio" name="kind" value="site" checked style="width:auto"> <span><b>کل سایت / بخش:</b> همه مقالات و صفحات سایت (یا فقط همان بخشی که آدرس داده‌اید، مثلاً /articles) خوانده می‌شود — حداکثر ۳۰۰ صفحه، به‌روزرسانی خودکار هفتگی.</span></label>
        <label class="hd-chk"><input type="radio" name="kind" value="page" style="width:auto"> <span><b>فقط همین صفحه:</b> یک مقاله یا صفحه مشخص.</span></label>
        <button type="submit" class="btn btn-primary" onclick="this.textContent='⏳ در حال خواندن... (تا یک دقیقه)';">➕ افزودن و خواندن</button>
    </form>
</div>

<div class="card">
    <div class="card-title">📄 افزودن فایل (PDF یا Word)</div>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="act" value="add_file">
        <div class="row2">
            <div class="form-group"><label>فایل (.pdf، .docx یا .txt — حداکثر ۱۰ مگابایت)</label><input type="file" name="doc" accept=".pdf,.docx,.txt" required></div>
            <div class="form-group"><label>عنوان (اختیاری)</label><input type="text" name="title" maxlength="300" placeholder="در صورت خالی بودن، نام فایل"></div>
        </div>
        <p class="hd-note">متن فایل استخراج و ذخیره می‌شود. فایل‌های PDF اسکن‌شده (عکس صفحه) متن قابل خواندن ندارند. فایل‌های قدیمی Word (.doc) را ابتدا با Word به .docx تبدیل کنید.</p>
        <button type="submit" class="btn btn-primary" onclick="this.textContent='⏳ در حال خواندن فایل...';">➕ افزودن فایل</button>
    </form>
</div>

<div class="card">
    <div class="card-title">📝 افزودن متن (پایگاه دانش اختصاصی)</div>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="act" value="add_text">
        <div class="form-group"><label>عنوان</label><input type="text" name="title" maxlength="300" placeholder="مثلاً: پروتکل درمان دیابت نوع ۲"></div>
        <div class="form-group"><label>متن</label><textarea name="content" rows="6" placeholder="متن کامل را اینجا بچسبانید..."></textarea></div>
        <div class="form-group"><label>یا فایل متنی (.txt، حداکثر ۳ مگابایت)</label><input type="file" name="txt" accept=".txt"></div>
        <button type="submit" class="btn btn-primary">➕ افزودن متن</button>
    </form>
</div>

<div class="card">
    <div class="card-title">📚 منابع ربات (<?php echo count($sources); ?>)</div>
    <?php if (!$sources): ?>
        <p style="color:#94a3b8;text-align:center;padding:20px;font-size:13px">هنوز منبعی اضافه نشده. بدون منبع، ربات در حالت دقت کامل پاسخ تخصصی نمی‌دهد.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>عنوان</th><th>نوع</th><th>وضعیت</th><th>صفحات</th><th>تکه‌های متن</th><th>آخرین به‌روزرسانی</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($sources as $s): $s_on = !isset($s['is_active']) || (int)$s['is_active'] === 1; ?>
        <tr<?php echo $s_on ? '' : ' style="opacity:.55"'; ?>>
            <td style="max-width:260px"><b><?php echo saas_h($s['title']); ?></b><?php if ($s['url']): ?><div style="font-size:11px;direction:ltr;text-align:right;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo saas_h($s['url']); ?></div><?php endif; ?>
                <?php if ($s['last_error'] !== ''): ?><div style="font-size:11px;color:#b91c1c"><?php echo saas_h($s['last_error']); ?></div><?php endif; ?></td>
            <td style="font-size:12px"><?php echo ['text' => 'متن', 'page' => 'صفحه', 'site' => 'سایت', 'file' => 'فایل'][$s['kind']] ?? $s['kind']; ?></td>
            <td><span class="hd-st <?php echo saas_h($s['status']); ?>"><?php echo $st_label[$s['status']] ?? $s['status']; ?></span><?php if (!$s_on): ?><div><span class="hd-st error" style="background:#f1f5f9;color:#64748b">غیرفعال</span></div><?php endif; ?></td>
            <td style="font-size:12px"><?php echo in_array($s['kind'], ['text', 'file'], true) ? '—' : ((int)$s['pages_ok'] . ' / ' . (int)$s['pages_total']); ?></td>
            <td style="font-size:12px"><?php echo number_format((int)$s['chunks']); ?></td>
            <td style="font-size:11px;color:#94a3b8;direction:ltr;text-align:right"><?php echo $s['last_sync'] ? substr($s['last_sync'], 0, 16) : '—'; ?></td>
            <td style="white-space:nowrap">
                <?php if (!in_array($s['kind'], ['text', 'file'], true)): ?>
                <form method="post" style="display:inline"><input type="hidden" name="act" value="sync_source"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>">
                    <button type="submit" class="btn btn-outline btn-sm" onclick="this.textContent='⏳';">🔄 همگام‌سازی</button></form>
                <?php endif; ?>
                <a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=sources&edit_src=<?php echo (int)$s['id']; ?>#srcedit">✏️ ویرایش</a>
                <form method="post" style="display:inline"><input type="hidden" name="act" value="src_toggle"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>">
                    <button type="submit" class="btn btn-outline btn-sm"><?php echo $s_on ? '⏸ غیرفعال' : '▶ فعال'; ?></button></form>
                <form method="post" style="display:inline" data-del2-title="<?php echo saas_h($s['title']); ?>" data-del2-info="<?php echo saas_h(($s['kind'] === 'file' ? 'فایل' : 'منبع') . ' «' . $s['title'] . '» و ' . number_format((int)$s['chunks']) . ' تکه متنِ آن از دانش این ربات حذف می‌شود و دیگر در پاسخ‌ها استفاده نمی‌شود. (اگر فقط موقتاً نمی‌خواهید استفاده شود، «غیرفعال» را بزنید.)'); ?>"><input type="hidden" name="act" value="del_source"><input type="hidden" name="source_id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="confirm_del" value="">
                    <button type="submit" class="btn btn-danger btn-sm">🗑 حذف</button></form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php echo ex_links_ui($pdo, $uid, $bid, $self . '&tab=sources'); ?>
<?php echo function_exists('ui_del2_assets') ? ui_del2_assets() : ''; ?>

<?php elseif ($tab === 'qa'): ?>
<!-- ================= پاسخ‌های تأییدشده ================= -->
<?php
$edit = null;
if (!empty($_GET['edit'])) { $e = $pdo->prepare("SELECT * FROM hd_qa WHERE id=? AND bot_id=?"); $e->execute([(int)$_GET['edit'], $bid]); $edit = $e->fetch(PDO::FETCH_ASSOC) ?: null; }
$qa_st = $pdo->prepare("SELECT * FROM hd_qa WHERE bot_id=? ORDER BY id DESC LIMIT 300");
$qa_st->execute([$bid]);
$qas = $qa_st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="card">
    <div class="card-title"><?php echo $edit ? '✏️ ویرایش پاسخ تأییدشده' : '➕ پاسخ تأییدشده جدید'; ?></div>
    <p class="hd-note" style="margin-bottom:10px">پاسخ‌هایی که خودتان تأیید می‌کنید <b>بالاترین اولویت</b> را دارند. اگر ربات جایی اشتباه پاسخ داد، پاسخ درست را اینجا ثبت کنید.</p>
    <form method="post">
        <input type="hidden" name="act" value="qa_save">
        <?php if ($edit): ?><input type="hidden" name="qa_id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
        <div class="form-group"><label>سوال</label><input type="text" name="question" maxlength="500" required value="<?php echo saas_h($edit['question'] ?? mb_substr((string)($_GET['prefill_q'] ?? ''), 0, 500)); ?>" <?php echo !$edit && !empty($_GET['prefill_q']) ? 'style="border-color:#2563eb"' : ''; ?>></div>
        <div class="form-group"><label>پاسخ صحیح</label><textarea name="answer" rows="4" required><?php echo saas_h($edit['answer'] ?? ''); ?></textarea></div>
        <button type="submit" class="btn btn-primary"><?php echo $edit ? '💾 ذخیره' : '➕ افزودن'; ?></button>
        <?php if ($edit): ?><a href="<?php echo $self; ?>&tab=qa" class="btn btn-outline">انصراف</a><?php endif; ?>
    </form>
</div>
<div class="card">
    <div class="card-title">✅ پاسخ‌های تأییدشده (<?php echo count($qas); ?>)</div>
    <?php if (!$qas): ?><p style="color:#94a3b8;text-align:center;padding:16px;font-size:13px">موردی ثبت نشده است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>سوال</th><th>پاسخ</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($qas as $q): $q_on = !isset($q['is_active']) || (int)$q['is_active'] === 1; ?>
        <tr<?php echo $q_on ? '' : ' style="opacity:.55"'; ?>><td style="font-weight:600;max-width:240px"><?php echo saas_h($q['question']); ?></td>
            <td style="font-size:12px;color:#475569;max-width:380px"><?php echo saas_h(mb_substr($q['answer'], 0, 160)); ?><?php echo mb_strlen($q['answer']) > 160 ? '…' : ''; ?></td>
            <td><?php echo $q_on ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-gray">غیرفعال</span>'; ?></td>
            <td style="white-space:nowrap"><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=qa&edit=<?php echo (int)$q['id']; ?>">ویرایش</a>
                <form method="post" style="display:inline"><input type="hidden" name="act" value="qa_toggle"><input type="hidden" name="qa_id" value="<?php echo (int)$q['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $q_on ? '⏸ غیرفعال' : '▶ فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><input type="hidden" name="act" value="qa_del"><input type="hidden" name="qa_id" value="<?php echo (int)$q['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'members' && !empty($_GET['mid'])): ?>
<!-- ================= پرونده مراجع ================= -->
<?php
$mr = $pdo->prepare("SELECT * FROM hd_members WHERE id=? AND bot_id=? AND is_owner=0");
$mr->execute([(int)$_GET['mid'], $bid]);
$mrow = $mr->fetch(PDO::FETCH_ASSOC);
?>
<?php if (!$mrow): ?>
<div class="alert alert-danger">مراجع یافت نشد. <a href="<?php echo $self; ?>&tab=members">بازگشت</a></div>
<?php elseif (!$can_consult || !$member_visible($mrow)): ?>
<div class="card"><p style="text-align:center;padding:20px;line-height:2">🔒 <?php echo !empty($mrow['consent_at']) ? 'این مراجع اجازه دسترسی به پرونده و گفتگوهایش را نداده است.' : 'این مراجع هنوز درباره اجازه دسترسی به پرونده و گفتگوهایش تصمیم نگرفته است.'; ?><br><span class="hd-note">پرونده، یادداشت‌ها و گفتگوهای هر مراجع فقط با اجازه خود او قابل مشاهده است. مراجع می‌تواند در صفحه چت از «📋 پرونده من» این اجازه را تأیید یا لغو کند.</span></p>
<div style="text-align:center"><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=members">← بازگشت</a></div></div>
<?php else:
    $mprof = hd_profile_get($mrow);
    $mintake = hd_intake_list($bot);
    $mnotes = hd_notes($pdo, $bid, (int)$mrow['id'], false, false, 200);
    $mth = $pdo->prepare("SELECT t.id, t.title, t.updated_at, (SELECT COUNT(*) FROM hd_messages x WHERE x.thread_id=t.id) AS cnt FROM hd_threads t WHERE t.bot_id=? AND t.member_id=? ORDER BY t.updated_at DESC LIMIT 100");
    $mth->execute([$bid, (int)$mrow['id']]);
    $mthreads = $mth->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $edit_note = null;
    foreach ($mnotes as $n) if ((int)$n['id'] === (int)($_GET['note'] ?? 0)) $edit_note = $n;
    $kinds = hd_note_kinds();
?>
<div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span>📋 پرونده: <?php echo saas_h($mrow['name']); ?> <span style="font-size:12px;color:#94a3b8;direction:ltr"><?php echo saas_h($mrow['mobile']); ?></span>
            <?php echo !empty($mrow['consent_share']) ? '<span class="badge badge-green" style="margin-right:6px">اجازه دسترسی مشاور داده</span>' : (!empty($mrow['consent_hold']) ? '<span class="badge badge-gray" style="margin-right:6px">اجازه معلق (اشتراک ندارد)</span>' : '<span class="badge badge-gray" style="margin-right:6px">اجازه مشاور نداده</span>'); ?></span>
        <a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=members">← همه مراجعان</a>
    </div>
    <?php $miss = array_diff(array_keys($mintake), array_keys($mprof)); ?>
    <?php if ($mintake): ?><p class="hd-note" style="margin-bottom:8px">اطلاعات شناخت اولیه: <?php echo count($mintake) - count($miss); ?> از <?php echo count($mintake); ?><?php echo $miss ? ' — هنوز نامشخص: ' . saas_h(implode('، ', $miss)) : ' ✅ کامل'; ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="act" value="mprofile_save"><input type="hidden" name="member_id" value="<?php echo (int)$mrow['id']; ?>">
        <div id="pfl">
        <?php foreach ($mprof + array_fill_keys($miss, '') as $k => $v): ?>
            <div style="display:flex;gap:6px;margin-bottom:6px"><input type="text" name="pk[]" value="<?php echo saas_h($k); ?>" style="flex:0 0 32%" maxlength="60"><input type="text" name="pv[]" value="<?php echo saas_h($v); ?>" style="flex:1" maxlength="300" placeholder="نامشخص"><button type="button" class="btn btn-danger btn-sm" onclick="this.parentNode.remove()">×</button></div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline btn-sm" onclick="hdAddPf()">+ افزودن مورد</button>
        <script>function hdAddPf(){var d=document.createElement('div');d.style.cssText='display:flex;gap:6px;margin-bottom:6px';d.innerHTML='<input type="text" name="pk[]" style="flex:0 0 32%" maxlength="60" placeholder="عنوان"><input type="text" name="pv[]" style="flex:1" maxlength="300" placeholder="مقدار"><button type="button" class="btn btn-danger btn-sm">×</button>';d.querySelector('button').onclick=function(){d.remove();};document.getElementById('pfl').appendChild(d);}</script>
        <button class="btn btn-primary btn-sm">💾 ذخیره پرونده</button>
        <p class="hd-note" style="margin-top:6px">این پرونده از گفتگوهای مراجع خودکار تکمیل می‌شود و هوش مصنوعی در همه پاسخ‌ها از آن استفاده می‌کند. مراجع هم می‌تواند آن را در صفحه چت ببیند و ویرایش کند.</p>
    </form>
</div>

<div class="card" id="notes">
    <div class="card-title"><?php echo $edit_note ? '✏️ ویرایش یادداشت' : '➕ ثبت نظر / نتیجه جلسه'; ?></div>
    <form method="post">
        <input type="hidden" name="act" value="note_save"><input type="hidden" name="member_id" value="<?php echo (int)$mrow['id']; ?>">
        <?php if ($edit_note): ?><input type="hidden" name="note_id" value="<?php echo (int)$edit_note['id']; ?>"><?php endif; ?>
        <div class="row2">
            <div class="form-group"><label>نوع</label><select name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo ($edit_note['kind'] ?? 'note') === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>عنوان (اختیاری)</label><input type="text" name="title" maxlength="190" value="<?php echo saas_h($edit_note['title'] ?? ''); ?>" placeholder="مثلاً: جلسه حضوری ۱۲ مهر"></div>
        </div>
        <div class="form-group"><label>متن</label><textarea name="content" rows="5" maxlength="20000" required><?php echo saas_h($edit_note['content'] ?? ''); ?></textarea></div>
        <label class="hd-chk"><input type="checkbox" name="use_ai" value="1" <?php echo !$edit_note || !empty($edit_note['use_ai']) ? 'checked' : ''; ?>> <span>هوش مصنوعی این یادداشت را بخواند و در پاسخ‌ها در نظر بگیرد</span></label>
        <label class="hd-chk"><input type="checkbox" name="show_member" value="1" <?php echo !empty($edit_note['show_member']) ? 'checked' : ''; ?>> <span>مراجع هم این یادداشت را در «پرونده من» ببیند</span></label>
        <button class="btn btn-primary btn-sm"><?php echo $edit_note ? '💾 ذخیره' : '➕ ثبت'; ?></button>
        <?php if ($edit_note): ?><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=members&mid=<?php echo (int)$mrow['id']; ?>#notes">انصراف</a><?php endif; ?>
    </form>
    <?php if ($mnotes): ?>
    <div style="margin-top:16px">
        <?php foreach ($mnotes as $n): ?>
        <div style="border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;margin-bottom:8px">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:12px;color:#64748b">
                <span><?php echo $kinds[$n['kind']] ?? $n['kind']; ?><?php echo $n['title'] !== '' ? ' — <b>' . saas_h($n['title']) . '</b>' : ''; ?> • <?php echo saas_h($n['author']); ?> • <?php echo biz_jdate($n['created_at'], true); ?>
                    <?php echo $n['use_ai'] ? ' • 🧠 در پاسخ‌ها' : ''; ?><?php echo $n['show_member'] ? ' • 👁 قابل مشاهده برای مراجع' : ''; ?></span>
                <span><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=members&mid=<?php echo (int)$mrow['id']; ?>&note=<?php echo (int)$n['id']; ?>#notes">ویرایش</a>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><input type="hidden" name="act" value="note_del"><input type="hidden" name="member_id" value="<?php echo (int)$mrow['id']; ?>"><input type="hidden" name="note_id" value="<?php echo (int)$n['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form></span>
            </div>
            <div style="white-space:pre-wrap;font-size:13px;line-height:1.9;margin-top:6px"><?php echo saas_h($n['content']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php bm_member_extra($pdo, $bot, $mrow, $self, $bm_ctx); ?>
<div class="card">
    <div class="card-title">💬 گفتگوهای این مراجع (<?php echo count($mthreads); ?>)</div>
    <?php if (!$mthreads): ?><p class="hd-note" style="text-align:center;padding:10px">گفتگویی ندارد.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>عنوان</th><th>پیام</th><th>آخرین فعالیت</th><th></th></tr></thead><tbody>
    <?php foreach ($mthreads as $t): ?>
        <tr><td><?php echo saas_h($t['title']); ?></td><td><?php echo (int)$t['cnt']; ?></td><td style="font-size:12px"><?php echo biz_jdate($t['updated_at'], true); ?></td>
            <td><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=chats&view=<?php echo (int)$t['id']; ?>">مشاهده</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'members'): ?>
<!-- ================= مخاطبان ================= -->
<?php
$page = max(1, (int)($_GET['p'] ?? 1)); $lim = 50; $off = ($page - 1) * $lim;
$q = trim($_GET['q'] ?? '');
$where = "bot_id=? AND is_owner=0"; $params = [$bid];
if ($q !== '') { $where .= " AND (name LIKE ? OR mobile LIKE ?)"; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
$cnt = $pdo->prepare("SELECT COUNT(*) FROM hd_members WHERE {$where}"); $cnt->execute($params); $total = (int)$cnt->fetchColumn();
$ms = $pdo->prepare("SELECT * FROM hd_members WHERE {$where} ORDER BY id DESC LIMIT {$lim} OFFSET {$off}"); $ms->execute($params);
$members = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<?php $chat_home = (string)($bot['custom_domain'] ?? '') !== '' ? 'https://' . $bot['custom_domain'] . '/' : (string)($bot['chat_url'] ?? ''); ?>
<div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span>👥 مخاطبان (<?php echo number_format($total); ?>)</span>
        <form method="get" style="display:flex;gap:6px"><input type="hidden" name="id" value="<?php echo $bid; ?>"><input type="hidden" name="tab" value="members">
            <input type="text" name="q" value="<?php echo saas_h($q); ?>" placeholder="جستجوی نام یا موبایل" style="width:200px"><button class="btn btn-outline btn-sm">جستجو</button></form>
    </div>
    <?php if (!$members): ?><p style="color:#94a3b8;text-align:center;padding:16px;font-size:13px">هنوز مخاطبی وارد نشده است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>نام</th><th>موبایل</th><th>پرونده</th><th>پیام مصرفی</th><th>پیام اضافه</th><th>آخرین فعالیت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach ($members as $m): ?>
        <tr>
            <td style="font-weight:600"><?php echo saas_h($m['name']); ?></td>
            <td style="direction:ltr;text-align:right;font-size:12px"><?php echo saas_h($m['mobile']); ?> <?php echo $m['verified'] ? '<span title="موبایل با پیامک تأیید شده">✅</span>' : ''; ?><?php echo trim((string)($m['password_hash'] ?? '')) !== '' ? ' <span title="رمز عبور دارد">🔑</span>' : ''; ?><?php echo trim((string)($m['google_sub'] ?? '')) !== '' ? ' <span title="حساب گوگل متصل: ' . saas_h($m['email'] ?? '') . '" style="font-weight:700;color:#4285f4">G</span>' : ''; ?></td>
            <td style="white-space:nowrap"><?php if ($can_consult && $member_visible($m)): ?><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=members&mid=<?php echo (int)$m['id']; ?>">📋 پرونده<?php $pc = count(hd_profile_get($m)); echo $pc ? ' (' . $pc . ')' : ''; ?></a><?php elseif (!$can_consult): ?><span style="font-size:11px;color:#94a3b8">—</span><?php elseif (!empty($m['consent_share'])): ?><span style="font-size:11px;color:#94a3b8" title="مدیر حساب، مشاور اختصاصی دیگری برای این مراجع تعیین کرده است">🔒 مشاور اختصاصی دیگر</span><?php else: ?><?php if (!empty($m['consent_hold'])): ?><span style="font-size:11px;color:#b45309" title="مراجع اجازه داده اما اشتراکش فعال نیست؛ با خرید یا تمدید اشتراک، دسترسی خودکار برمی‌گردد">⏸ اشتراک ندارد (اجازه معلق)</span><?php else: ?><span style="font-size:11px;color:#94a3b8" title="پرونده فقط با اجازه خود مراجع قابل مشاهده است">🔒 <?php echo !empty($m['consent_at']) ? 'اجازه نداده' : 'هنوز تصمیم نگرفته'; ?></span><?php endif; ?><?php endif; ?><?php echo !empty($m['consent_share']) ? ' <span title="اجازه دسترسی مشاور داده" style="font-size:12px">🤝</span>' : ''; ?></td>
            <td><?php echo number_format((int)$m['used_messages']); ?></td>
            <td><?php echo number_format((int)$m['extra_messages']); ?></td>
            <td style="font-size:11px;color:#94a3b8;direction:ltr;text-align:right"><?php echo $m['last_seen'] ? substr($m['last_seen'], 0, 16) : '—'; ?></td>
            <td><?php echo $m['status'] === 'active' ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-red">مسدود</span>'; ?></td>
            <td style="white-space:nowrap">
                <form method="post" style="display:inline-flex;gap:4px"><input type="hidden" name="act" value="member_add"><input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                    <input type="number" name="messages" value="20" style="width:70px;padding:4px 6px"><button class="btn btn-outline btn-sm">+ پیام</button></form>
                <form method="post" style="display:inline"><input type="hidden" name="act" value="member_toggle"><input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                    <button class="btn btn-sm <?php echo $m['status'] === 'active' ? 'btn-danger' : 'btn-outline'; ?>"><?php echo $m['status'] === 'active' ? 'مسدود' : 'فعال'; ?></button></form>
                <?php if (empty($m['is_owner']) && $member_visible($m)): ?>
                <form method="post" target="_blank" style="display:inline" onsubmit="<?php echo $chat_home === '' ? "var u=prompt('آدرس صفحه چت روی سایت شما (مثلاً https://yoursite.com/chat/):');if(!u)return false;this.chat_url.value=u;" : ''; ?>"><input type="hidden" name="act" value="member_login"><input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>"><input type="hidden" name="chat_url" value="">
                    <button class="btn btn-primary btn-sm" title="صفحه چت را همان‌طور که این مخاطب می‌بیند باز کنید">👁 ورود به‌جای مخاطب</button></form>
                <?php endif; ?>
                <?php if (empty($m['is_owner']) && function_exists('cm_role_ok') && cm_role_ok(rem_ctx_user($current_user, $current_team ?? null))): ?>
                <a class="btn btn-outline btn-sm" href="messages.php?tab=send&member=<?php echo (int)$m['id']; ?>" title="ارسال پیامک/اعلان یا یادآور به این مخاطب">📣</a>
                <a class="btn btn-outline btn-sm" href="messages.php?tab=sessions&member=<?php echo (int)$m['id']; ?>" title="ثبت جلسه مشاوره با یادآوری به مشاور و مخاطب">📅</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <p class="hd-note" style="margin-top:10px">👁 «ورود به‌جای مخاطب» صفحه چت را (روی سایت شما<?php echo $chat_home !== '' ? ': <span dir="ltr">' . saas_h($chat_home) . '</span>' : ''; ?>) با حساب همان مخاطب باز می‌کند تا گفتگوها و سهمیه او را ببینید؛ پیام‌هایی که بفرستید از سهمیه او کم می‌شود. این ورود ۲ ساعت اعتبار دارد و مخاطب از حساب خود خارج نمی‌شود. اگر فایل رابط را پیش از این نسخه نصب کرده‌اید، یک بار دوباره دانلود و جایگزین کنید.</p>
    <?php if ($total > $lim): ?>
        <div style="margin-top:12px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
        <?php for ($i = 1; $i <= (int)ceil($total / $lim); $i++): ?>
            <a class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo $self; ?>&tab=members&p=<?php echo $i; ?>&q=<?php echo urlencode($q); ?>"><?php echo $i; ?></a>
        <?php endfor; ?></div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'chats'): ?>
<!-- ================= گفتگوها ================= -->
<?php
$view = (int)($_GET['view'] ?? 0);
if ($view) {
    $t = $pdo->prepare("SELECT t.*, m.name, m.mobile, m.consent_share, m.is_owner, m.consultant_id FROM hd_threads t LEFT JOIN hd_members m ON m.id=t.member_id WHERE t.id=? AND t.bot_id=?");
    $t->execute([$view, $bid]);
    $thread = $t->fetch(PDO::FETCH_ASSOC);
    if ($thread && !$member_visible($thread)) $thread = null;   // فقط با اجازه مراجع (و فقط مشاور اختصاصی او)
}
?>
<?php if (!empty($thread)):
    $msgs = hd_thread_messages($pdo, $thread['id'], 500, true);
    $cmts = function_exists('cx_comments') ? cx_comments($pdo, $bid, (int)$thread['member_id'], array_column($msgs, 'id'), false) : [];
    $tpk = function_exists('biz_toman_per_1k') ? biz_toman_per_1k($pdo) : 0;
    $mtitles = [];
    try { foreach ($pdo->query("SELECT id, title FROM saas_ai_models")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mt) $mtitles[(int)$mt['id']] = $mt['title']; } catch (\Throwable $e) {}
    $sum_tok = 0; $sum_mem = 0; $n_ans = 0;
    foreach ($msgs as $m) if ($m['role'] === 'assistant') { $sum_tok += (int)$m['tokens']; $n_ans++; $d = json_decode((string)$m['tok_detail'], true); $sum_mem += (int)($d['mem'] ?? 0); }
    $fmt_t = function ($n) { return number_format((int)$n) . ' تومان'; };
?>
<style>
.hd-cost{white-space:normal;margin-top:8px;padding-top:6px;border-top:1px dashed #cbd5e1;font-size:11.5px;color:#475569;line-height:1.9}
.hd-cost b{color:#0f172a}.hd-cost .c{display:inline-block;background:#f1f5f9;border-radius:6px;padding:0 6px;margin:1px 2px}
.hd-sumbox{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:12.5px}
.hd-sumbox summary{cursor:pointer;font-weight:700;color:#334155}
.hd-sumbox div{white-space:pre-wrap;line-height:1.9;margin-top:8px;color:#475569}
</style>
<div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px"><span>💬 <?php echo saas_h($thread['title']); ?> — <?php echo saas_h($thread['name']); ?></span>
        <a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=chats">← بازگشت</a></div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;font-size:12.5px">
        <span class="badge badge-blue" style="padding:5px 10px">🔢 مصرف کل این گفتگو: <?php echo $fmt_t($sum_tok); ?></span>
        <span class="badge badge-gray" style="padding:5px 10px">💬 <?php echo number_format($n_ans); ?> پاسخ — میانگین هر پاسخ: <?php echo number_format($n_ans ? round($sum_tok / $n_ans) : 0); ?> تومان</span>
        <?php if ($sum_mem): ?><span class="badge badge-gray" style="padding:5px 10px">🧠 حافظه (خلاصه‌سازی): <?php echo number_format($sum_mem); ?> تومان</span><?php endif; ?>
    </div>
    <?php if (trim((string)($thread['summary'] ?? '')) !== ''): ?>
    <details class="hd-sumbox"><summary>🧠 خلاصه حافظه این گفتگو (آنچه هوش مصنوعی از بخش‌های قدیمی‌تر به یاد دارد)</summary><div><?php echo saas_h($thread['summary']); ?></div></details>
    <?php endif; ?>
    <?php foreach ($msgs as $m): ?>
        <div class="hd-msg <?php echo $m['role'] === 'user' ? 'user' : 'assistant'; ?>"><?php echo saas_h($m['content']); ?><?php if (!empty($m['files'])): ?><div style="font-size:11.5px;margin-top:4px;opacity:.85">📎 <?php echo saas_h(implode('، ', array_column($m['files'], 'name'))); ?></div><?php endif; ?><?php if ($m['role'] === 'assistant' && (int)$m['rating'] !== 0): ?><div style="font-size:12px;margin-top:4px"><?php echo (int)$m['rating'] > 0 ? '👍 مخاطب این پاسخ را مفید دانست' : '👎 مخاطب این پاسخ را مفید ندانست'; ?></div><?php endif; ?>
            <?php if ($m['sources']): ?><div class="hd-src">منابع: <?php foreach ($m['sources'] as $s): ?>[<?php echo (int)$s['n']; ?>] <?php echo $s['url'] ? '<a href="' . saas_h($s['url']) . '" target="_blank">' . saas_h($s['title']) . '</a>' : saas_h($s['title']); ?>&nbsp; <?php endforeach; ?></div><?php endif; ?>
<?php if ($m['role'] === 'assistant') {
                $d = json_decode((string)$m['tok_detail'], true); $d = is_array($d) ? $d : [];
                $mt = ($d['m'] ?? '') !== '' ? $d['m'] : ($mtitles[(int)$m['model_id']] ?? '');
                $parts = [];
                if (isset($d['ai'])) $parts[] = 'پاسخ هوش مصنوعی: ' . number_format((int)$d['ai']) . ' تومان' . (!empty($d['in']) || !empty($d['out']) ? ' <span style="color:#94a3b8">(' . number_format((int)$d['in']) . ' توکن ورودی + ' . number_format((int)$d['out']) . ' توکن خروجی)</span>' : '');
                if (!empty($d['v'])) $parts[] = 'بازبینی دقت: ' . number_format((int)$d['v']);
                if (!empty($d['f'])) $parts[] = 'فایل پیوست: ' . number_format((int)$d['f']);
                if (!empty($d['voice'])) $parts[] = 'پیام صوتی: ' . number_format((int)$d['voice']);
                if (!empty($d['tts'])) $parts[] = 'پخش صوتی: ' . number_format((int)$d['tts']);
                if (!empty($d['img'])) $parts[] = 'ساخت تصویر: ' . number_format((int)$d['img']);
                if (!empty($d['vid'])) $parts[] = 'ساخت ویدیو: ' . number_format((int)$d['vid']);
                if (!empty($d['mem'])) $parts[] = 'حافظه (خلاصه‌سازی): ' . number_format((int)$d['mem']);
                echo '<div class="hd-cost">🔢 مصرف این پاسخ: <b>' . $fmt_t((int)$m['tokens']) . '</b>' . ($mt !== '' ? ' • مدل: ' . saas_h($mt) : '')
                    . ($parts ? '<br><span class="c">' . implode('</span><span class="c">', $parts) . '</span>' : '') . '</div>';
            } ?></div>
        <?php if (function_exists('cx_comment_add') && $can_consult): $mc = $cmts[(int)$m['id']] ?? []; ?>
        <div id="m<?php echo (int)$m['id']; ?>" style="max-width:85%;<?php echo $m['role'] === 'user' ? 'margin-right:auto;margin-left:0' : ''; ?>">
            <?php foreach ($mc as $c): ?>
            <div style="background:<?php echo $c['private'] ? '#fffbeb' : '#faf5ff'; ?>;border:1px solid <?php echo $c['private'] ? '#fde68a' : '#e9d5ff'; ?>;border-radius:10px;padding:6px 10px;margin:4px 0;font-size:12.5px;white-space:pre-wrap;line-height:1.9"><b style="font-size:11px;color:#7c3aed">💬 <?php echo saas_h($c['author']); ?> — <?php echo $c['private'] ? '🔒 محرمانه' : '👁 نمایش به مراجع'; ?> — <?php echo biz_jdate($c['at']); ?></b>
                <form method="post" style="display:inline" onsubmit="return confirm('کامنت حذف شود؟');"><input type="hidden" name="act" value="msg_comment_del"><input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>"><input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>"><input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-outline btn-sm" style="padding:0 6px;font-size:11px">حذف</button></form>
                <div><?php echo saas_h($c['body']); ?></div></div>
            <?php endforeach; ?>
            <details style="font-size:12.5px;margin:2px 0 10px"><summary style="cursor:pointer;color:#7c3aed;font-weight:600">💬 کامنت روی این <?php echo $m['role'] === 'user' ? 'پیام مراجع' : 'پاسخ هوش مصنوعی'; ?></summary>
                <form method="post" style="margin-top:6px"><input type="hidden" name="act" value="msg_comment"><input type="hidden" name="thread_id" value="<?php echo (int)$thread['id']; ?>"><input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                    <textarea name="body" rows="3" maxlength="3000" required placeholder="نظر، اصلاح یا تکمیل شما… (هوش مصنوعی در پاسخ‌های بعدی به این مراجع اعمال می‌کند)"></textarea>
                    <label class="hd-chk"><input type="checkbox" name="show_member" value="1" checked> <span>نمایش به مراجع (زیر همین پیام در صفحه چت او)</span></label>
                    <button class="btn btn-primary btn-sm">ثبت کامنت</button></form></details>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php else:
    $ts = $pdo->prepare("SELECT t.id, t.title, t.updated_at, m.name, m.mobile, m.is_owner, (SELECT COUNT(*) FROM hd_messages x WHERE x.thread_id=t.id) AS cnt,
        (SELECT COALESCE(SUM(x.tokens),0) FROM hd_messages x WHERE x.thread_id=t.id) AS tok
        , m.consent_share, m.consultant_id FROM hd_threads t LEFT JOIN hd_members m ON m.id=t.member_id WHERE t.bot_id=? AND (m.consent_share=1 OR m.is_owner=1) ORDER BY t.updated_at DESC LIMIT 200");
    $ts->execute([$bid]);
    $threads = array_values(array_filter($ts->fetchAll(PDO::FETCH_ASSOC) ?: [], $member_visible));
    $hid_c = $pdo->prepare("SELECT COUNT(*) FROM hd_threads t JOIN hd_members m ON m.id=t.member_id WHERE t.bot_id=? AND m.is_owner=0 AND m.consent_share=0");
    $hid_c->execute([$bid]);
    $hidden_threads = (int)$hid_c->fetchColumn();
?>
<div class="card">
    <div class="card-title">💬 گفتگوهای اخیر</div>
    <?php if ($hidden_threads): ?><p class="hd-note" style="margin-bottom:10px">🔒 <?php echo number_format($hidden_threads); ?> گفتگو از مراجعانی که اجازه دسترسی نداده‌اند نمایش داده نمی‌شود.</p><?php endif; ?>
    <?php if (!$threads): ?><p style="color:#94a3b8;text-align:center;padding:16px;font-size:13px">هنوز گفتگویی ثبت نشده است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>عنوان</th><th>مخاطب</th><th>پیام</th><th>هزینه (تومان)</th><th>تاریخ</th><th></th></tr></thead><tbody>
        <?php foreach ($threads as $t): ?>
        <tr><td style="max-width:280px"><?php echo saas_h($t['title']); ?></td>
            <td style="font-size:12px"><?php echo $t['is_owner'] ? '🧪 آزمایش مدیر' : saas_h($t['name']) . ' <span style="color:#94a3b8;direction:ltr">' . saas_h($t['mobile']) . '</span>'; ?></td>
            <td><?php echo (int)$t['cnt']; ?></td><td style="font-size:12px"><?php echo number_format((int)$t['tok']); ?></td>
            <td style="font-size:11px;color:#94a3b8;direction:ltr;text-align:right"><?php echo substr($t['updated_at'], 0, 16); ?></td>
            <td><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=chats&view=<?php echo (int)$t['id']; ?>">مشاهده</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'sales'): ?>
<!-- ================= پلن‌ها و فروش ================= -->
<?php
$plans = hd_member_plans($pdo, $bid, false);
$pe = null;
if (!empty($_GET['edit_plan'])) foreach ($plans as $p) if ((int)$p['id'] === (int)$_GET['edit_plan']) $pe = $p;
$pu = $pdo->prepare("SELECT p.*, m.name AS mname, m.mobile FROM hd_purchases p LEFT JOIN hd_members m ON m.id=p.member_id WHERE p.bot_id=? AND p.status='paid' ORDER BY p.id DESC LIMIT 100");
$pu->execute([$bid]);
$purchases = $pu->fetchAll(PDO::FETCH_ASSOC) ?: [];
$sum = function ($from) use ($pdo, $bid) {
    $q = $pdo->prepare("SELECT COALESCE(SUM(amount_toman),0) AS s, COUNT(*) AS c FROM hd_purchases WHERE bot_id=? AND status='paid' AND paid_at >= ?");
    $q->execute([$bid, $from]);
    return $q->fetch(PDO::FETCH_ASSOC);
};
$rev_today = $sum(date('Y-m-d 00:00:00'));
$rev_month = $sum(date('Y-m-d 00:00:00', time() - 29 * 86400));
$rev_all   = $sum('2000-01-01 00:00:00');
$sells = hd_bot_sells($pdo, $bot);
?>
<div class="card">
    <div class="card-title">💳 فروش بسته پیام به مخاطبان</div>
    <p class="hd-note">مخاطبان پس از تمام شدن پیام‌های رایگان، می‌توانند بسته پیام بخرند. پرداخت مستقیم به <b>حساب زرین‌پال خودتان</b> واریز می‌شود و در صفحه پرداخت فقط نام شما دیده می‌شود.
        <?php echo $sells ? '<span class="badge badge-green">فروش فعال است</span>' : '<span class="badge badge-red">فروش غیرفعال (کد مرچنت و حداقل یک بسته فعال لازم است)</span>'; ?>
        <br>بسته رایگان (قیمت ۰) بدون درگاه هم قابل فعال‌سازی است (هر مخاطب یک بار). مخاطبان از دکمه «⭐ ارتقا» در صفحه چت، همه بسته‌ها، وضعیت فعلی و امکانات هر بسته را می‌بینند و خرید یا ارتقا می‌دهند.</p>
    <form method="post" style="margin-top:12px">
        <input type="hidden" name="act" value="sales_settings">
        <div class="row2">
            <div class="form-group"><label>کد مرچنت زرین‌پال</label><input type="text" name="zp_merchant" dir="ltr" maxlength="40" value="<?php echo saas_h($bot['zp_merchant'] ?? ''); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off"></div>
            <div class="form-group" style="display:flex;align-items:flex-end"><label class="hd-chk"><input type="checkbox" name="zp_sandbox" value="1" <?php echo !empty($bot['zp_sandbox']) ? 'checked' : ''; ?>> <span>حالت آزمایشی (Sandbox) — فقط برای تست، پولی جابه‌جا نمی‌شود</span></label></div>
        </div>
        <label class="hd-chk"><input type="checkbox" name="remind_members" value="1" <?php echo !isset($bot['remind_members']) || !empty($bot['remind_members']) ? 'checked' : ''; ?>>
            <span>یادآوری پیامکی انقضای بسته به مخاطبان (۳۰، ۷، ۳ و ۱ روز قبل از پایان — با نام ربات شما؛ از اعتبار پیامک شما کم می‌شود مگر پنل پیامک اختصاصی داشته باشید). در صفحه چت هم ۷ روز مانده هشدار نمایش داده می‌شود.</span></label>
        <button class="btn btn-primary btn-sm">💾 ذخیره درگاه</button>
        <button class="btn btn-outline btn-sm" name="zp_test" value="1">🔌 ذخیره و آزمایش اتصال درگاه</button>
    </form>
</div>

<div class="card">
    <div class="card-title"><?php echo $pe ? '✏️ ویرایش بسته' : '➕ بسته جدید'; ?></div>
    <form method="post">
        <input type="hidden" name="act" value="plan_save">
        <?php if ($pe): ?><input type="hidden" name="plan_id" value="<?php echo (int)$pe['id']; ?>"><?php endif; ?>
        <div class="row2">
            <div class="form-group"><label>نام بسته</label><input type="text" name="name" maxlength="100" required value="<?php echo saas_h($pe['name'] ?? ''); ?>" placeholder="مثلاً: بسته ماهانه"></div>
            <div class="form-group"><label>قیمت (تومان؛ ۰ = بسته رایگان — هر مخاطب یک بار)</label><input type="number" name="price_toman" min="0" step="1000" required value="<?php echo (int)($pe['price_toman'] ?? 50000); ?>"></div>
        </div>
        <?php $pk_cr = function_exists('mcr_is_credit_plan') && mcr_is_credit_plan($pe); $mk = function_exists('mcr_markup') ? mcr_markup($pdo) : 10; ?>
        <div class="form-group"><label>نوع بسته</label>
            <div style="display:flex;gap:6px 18px;flex-wrap:wrap">
                <label class="hd-chk" style="margin:0"><input type="radio" name="pkind" value="msgs" <?php echo $pk_cr ? '' : 'checked'; ?> onchange="pkT()"> <span>📨 تعداد پیام (مثل قبل)</span></label>
                <label class="hd-chk" style="margin:0"><input type="radio" name="pkind" value="credit" <?php echo $pk_cr ? 'checked' : ''; ?> onchange="pkT()"> <span>💰 شارژ تومانی — هزینه به اندازه مصرف واقعی</span></label>
            </div>
            <div class="hd-note" id="pk_cr_note" style="<?php echo $pk_cr ? '' : 'display:none'; ?>">مخاطب مبلغی شارژ می‌خرد و هزینه هر پاسخ، تصویر، ویدیو و پیام صوتی دقیقاً به اندازه مصرف از آن کم می‌شود: <b>قیمت مدل‌ها برای مخاطب = قیمت شما + <?php echo rtrim(rtrim(number_format($mk, 2, '.', ''), '0'), '.'); ?>٪</b> (قیمت هر مدل در جدول «مدل‌های هوش مصنوعی این بسته» پایین همین فرم). هزینه شما مثل همیشه از اعتبار حسابتان کم می‌شود و مبلغ فروش بسته به حساب خودتان واریز می‌شود.</div>
        </div>
        <div class="form-group" id="pk_cr_box" style="<?php echo $pk_cr ? '' : 'display:none'; ?>"><label>💰 مبلغ شارژ این بسته (تومان؛ خالی = برابر قیمت بسته)</label><input type="text" name="credit_toman" dir="ltr" inputmode="numeric" value="<?php echo $pk_cr && (int)($pe['credit_toman'] ?? 0) > 0 ? (int)$pe['credit_toman'] : ''; ?>" placeholder="مثلاً 100000"></div>
        <script>function pkT(){var c=document.querySelector('input[name=pkind]:checked').value==='credit';['pk_cr_box','pk_cr_note'].forEach(function(i){document.getElementById(i).style.display=c?'':'none';});var m=document.getElementById('pk_msgs');if(m)m.style.display=c?'none':'';}</script>
        <div class="row2">
            <div class="form-group" id="pk_msgs" style="<?php echo $pk_cr ? 'display:none' : ''; ?>"><label>تعداد پیام</label><input type="number" name="messages" min="1" required value="<?php echo (int)($pe['messages'] ?? 100); ?>"></div>
            <div class="form-group"><label>مدت اعتبار (روز؛ ۰ = بدون انقضا)</label><input type="number" name="days" min="0" value="<?php echo (int)($pe['days'] ?? 30); ?>"></div>
        </div>
        <div class="row2">
            <div class="form-group"><label>📅 سقف پیام روزانه (۰ = همان سقف عمومی ربات)</label><input type="number" name="daily_limit" min="0" value="<?php echo (int)($pe['daily_limit'] ?? 0); ?>"></div>
            <div class="form-group"><label>🗓 سقف پیام هفتگی (۰ = بدون سقف؛ هفته از شنبه)</label><input type="number" name="week_limit" min="0" value="<?php echo (int)($pe['week_limit'] ?? 0); ?>"></div>
        </div>
        <div class="row2">
            <div class="form-group"><label>📆 سقف پیام ماهانه (۰ = بدون سقف؛ هر ۳۰ روز از تاریخ خرید)</label><input type="number" name="month_limit" min="0" value="<?php echo (int)($pe['month_limit'] ?? 0); ?>"></div>
            <div class="form-group"><label>ترتیب نمایش</label><input type="number" name="sort_order" value="<?php echo (int)($pe['sort_order'] ?? 0); ?>"></div>
        </div>
        <p class="hd-note" style="margin:-4px 0 12px">مخاطب وقتی به سقف <b>روزانه</b> یا <b>هفتگی</b> برسد، می‌تواند با «♻️ ریست رایگان» از سهمیه روزها/هفته‌های بعد استفاده کند (قبلش به او هشدار داده می‌شود که اشتراکش زودتر از یک ماه تمام می‌شود). سقف <b>ماهانه</b> و تعداد کل پیام‌ها قابل ریست نیست.</p>
        <div class="row2">
            <div class="form-group"><label>⏱ حداکثر پیام در هر بازه <?php echo (int)($bot['win_hours'] ?? 0) > 0 ? (int)$bot['win_hours'] . ' ساعته' : '(بازه در تب تنظیمات خاموش است)'; ?> — ۰ = بدون سقف</label><input type="number" name="win_msgs" min="0" value="<?php echo (int)($pe['win_msgs'] ?? 0); ?>"></div>
            <div class="form-group"><label>📩 پیامک یادآور (تعداد) — مخاطب با این بسته می‌تواند یادآورهایش را با پیامک هم بگیرد</label><input type="number" name="rem_sms" min="0" value="<?php echo (int)($pe['rem_sms'] ?? 0); ?>"><small class="hd-note">هزینه واقعی هر پیامک از اعتبار پیامک حساب شما کم می‌شود. برای فروش «بسته پیامک اضافه»، بسته‌ای با پیام کم و پیامک یادآور زیاد بسازید.</small></div>
        </div>
        <div class="form-group"><label>توضیح کوتاه (اختیاری)</label><input type="text" name="description" maxlength="300" value="<?php echo saas_h($pe['description'] ?? ''); ?>"></div>
        <div class="form-group"><label>امکانات بسته <span style="font-weight:normal;color:#64748b;font-size:12px">(هر خط یک مورد؛ ترتیب خطوط = اولویت نمایش در صفحه بسته — با خرید، خودکار فعال می‌شود)</span></label>
            <textarea name="features" id="pfeat" rows="4" maxlength="6000" placeholder="مثلاً:&#10;پاسخ‌گویی با مدل قوی‌تر&#10;تحلیل پرونده توسط مشاور&#10;پیام صوتی"><?php echo saas_h($pe['features'] ?? ''); ?></textarea>
            <div style="margin-top:4px;display:flex;gap:6px"><button type="button" class="btn btn-outline btn-sm" onclick="pfMove(-1)" title="انتقال خط فعلی به بالا">▲ بالا</button><button type="button" class="btn btn-outline btn-sm" onclick="pfMove(1)" title="انتقال خط فعلی به پایین">▼ پایین</button></div>
            <script>function pfMove(d){var t=document.getElementById('pfeat');var L=t.value.split('\n');var pos=t.selectionStart,acc=0,i=0;for(;i<L.length;i++){if(pos<=acc+L[i].length)break;acc+=L[i].length+1;}var j=i+d;if(j<0||j>=L.length)return;var x=L[i];L[i]=L[j];L[j]=x;t.value=L.join('\n');var np=0;for(var k=0;k<j;k++)np+=L[k].length+1;t.focus();t.setSelectionRange(np,np+L[j].length);}</script></div>
        <div class="row2">
            <div class="form-group"><label>🔥 تخفیف (درصد؛ ۰ = بدون تخفیف)</label><input type="number" name="discount_percent" min="0" max="90" value="<?php echo (int)($pe['discount_percent'] ?? 0); ?>"></div>
            <div class="form-group"><label>تخفیف تا تاریخ (خالی = بدون محدودیت)</label><input type="date" name="discount_until" value="<?php echo !empty($pe['discount_until']) ? substr($pe['discount_until'], 0, 10) : ''; ?>"></div>
        </div>
        <?php if (function_exists('cx_flag_labels')): $pfl = cx_flags_parse($pe['flags'] ?? 'files,voice'); ?>
        <div class="form-group"><label>امکانات این بسته در صفحه چت (با خرید، خودکار فعال می‌شود)</label>
            <div style="display:flex;gap:4px 16px;flex-wrap:wrap">
            <?php foreach (cx_flag_labels() as $fk => $fl): $dis = in_array($fk, ['image', 'video'], true) && !cx_gen_models($pdo, $bot, $fk); ?>
                <label class="hd-chk" style="margin:4px 0"><input type="checkbox" name="flags[]" value="<?php echo $fk; ?>" <?php echo in_array($fk, $pfl, true) ? 'checked' : ''; ?> <?php echo $dis ? 'disabled' : ''; ?>> <span><?php echo $fl . ($dis ? ' <small style="color:#94a3b8">(در پلن شما نیست)</small>' : ''); ?></span></label>
            <?php endforeach; ?>
            </div>
            <div class="hd-note">پاسخ‌گویی با «مدل پاسخ‌گویی» تخصصی در همه بسته‌ها فعال است. در چت رایگان، این امکانات به‌صورت کم‌رنگ با پیشنهاد ارتقا نمایش داده می‌شوند.</div></div>
        <?php if (function_exists('mx_cfg_parse')):
            $mx_cfg = mx_cfg_parse($pe['model_cfg'] ?? '');
            $mx_groups = array_filter(['text' => ['💬 مدل‌های گفتگو', biz_plan_models($pdo, $plan, 'text', 'bot')], 'image' => ['🖼 مدل‌های ساخت تصویر', cx_gen_models($pdo, $bot, 'image')], 'video' => ['🎬 مدل‌های ساخت ویدیو', cx_gen_models($pdo, $bot, 'video')]], fn($g) => (bool)$g[1]);
            if ($mx_groups): ?>
        <div class="form-group"><label>🧠 مدل‌های هوش مصنوعی این بسته</label>
            <div class="hd-note" style="margin-bottom:6px">همه مدل‌ها به‌طور پیش‌فرض در بسته فعال‌اند و مدل‌هایی که بعداً به سایت اضافه شوند هم خودکار فعال می‌شوند. برای هر مدل می‌توانید تیک را بردارید (غیرفعال) یا <b>سقف تعداد استفاده</b> در هر خرید تعیین کنید (۰ = نامحدود؛ برای مدل گفتگو = تعداد پاسخ، برای تصویر/ویدیو = تعداد ساخت). به مخاطب فقط تعداد مدل‌ها نمایش داده می‌شود.</div>
            <div class="table-wrap"><table class="mx-t">
                <thead><tr><th>مدل</th><th>قیمت شما</th><th>قیمت برای مخاطب (شارژ تومانی)</th><th style="width:90px">فعال</th><th style="width:170px">سقف استفاده در هر خرید</th></tr></thead><tbody>
                <?php foreach ($mx_groups as $gk => $g): ?>
                <tr><td colspan="5" style="background:#f8fafc;font-weight:700;font-size:12.5px"><?php echo $g[0]; ?></td></tr>
                <?php foreach (biz_models_by_price($pdo, $g[1]) as $m): $mi = (int)$m['id']; $c = $mx_cfg[$mi] ?? ['off' => false, 'max' => 0]; ?>
                <?php $ml = function_exists('mcr_model_labels') ? mcr_model_labels($pdo, $m) : [biz_model_cost_label($pdo, $m), '']; ?>
                <tr><td><input type="hidden" name="mcfg_ids[]" value="<?php echo $mi; ?>"><?php echo saas_h($m['title']); ?></td>
                    <td style="font-size:12px;color:#64748b;white-space:nowrap"><?php echo saas_h($ml[0]); ?></td><td style="font-size:12px;color:#047857;white-space:nowrap"><?php echo saas_h($ml[1]); ?></td>
                    <td><label class="hd-chk" style="margin:0"><input type="checkbox" name="mcfg_on[<?php echo $mi; ?>]" value="1" <?php echo $c['off'] ? '' : 'checked'; ?>> <span>فعال</span></label></td>
                    <td><input type="number" name="mcfg_max[<?php echo $mi; ?>]" min="0" value="<?php echo (int)$c['max']; ?>" style="width:110px" title="۰ = نامحدود"></td></tr>
                <?php endforeach; endforeach; ?>
                </tbody></table></div></div>
        <?php endif; endif; ?>
        <?php $obots = array_values(array_filter(hd_user_bots($pdo, $uid), fn($b) => (int)$b['id'] !== $bid)); if ($obots): $pbs = cx_ids_parse($pe['bot_ids'] ?? ''); ?>
        <div class="form-group"><label>🔗 این بسته برای چت‌بات‌های دیگر شما هم معتبر باشد (اعتبار مشترک با همان شماره موبایل)</label>
            <div style="display:flex;gap:4px 16px;flex-wrap:wrap">
            <?php foreach ($obots as $ob): ?>
                <label class="hd-chk" style="margin:4px 0"><input type="checkbox" name="plan_bots[]" value="<?php echo (int)$ob['id']; ?>" <?php echo in_array((int)$ob['id'], $pbs, true) ? 'checked' : ''; ?>> <span>🤖 <?php echo saas_h($ob['name']); ?></span></label>
            <?php endforeach; ?>
            </div>
            <div class="hd-note">بسته در صفحه خرید همه این چت‌بات‌ها نمایش داده می‌شود و مخاطب با یک خرید در همه آن‌ها پیام دارد. اگر برای هر چت‌بات بسته جدا می‌خواهید، تیک نزنید.</div></div>
        <?php endif; endif; ?>
        <label class="hd-chk"><input type="checkbox" name="is_active" value="1" <?php echo ($pe === null || !empty($pe['is_active'])) ? 'checked' : ''; ?>> <span>بسته فعال باشد (به مخاطبان نمایش داده شود)</span></label>
        <button class="btn btn-primary"><?php echo $pe ? '💾 ذخیره' : '➕ افزودن بسته'; ?></button>
        <?php if ($pe): ?><a href="<?php echo $self; ?>&tab=sales" class="btn btn-outline">انصراف</a><?php endif; ?>
    </form>
</div>

<?php $shared_in = function_exists('cx_bot_plans') ? array_values(array_filter(cx_bot_plans($pdo, $bot, false), fn($p) => (int)$p['bot_id'] !== $bid)) : []; if ($shared_in): ?>
<div class="alert alert-info" style="line-height:2">🔗 این بسته‌های مشترک از چت‌بات‌های دیگر شما هم در این چت‌بات فروخته می‌شوند: <?php echo saas_h(implode('، ', array_column($shared_in, 'name'))); ?> (برای ویرایش، به تب «پلن‌ها و فروش» همان چت‌بات بروید).</div>
<?php endif; ?>
<div class="card">
    <div class="card-title">📦 بسته‌ها (<?php echo count($plans); ?>)</div>
    <?php if (!$plans): ?><p style="color:#94a3b8;text-align:center;padding:16px;font-size:13px">هنوز بسته‌ای تعریف نشده است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>نام</th><th>قیمت</th><th>پیام</th><th>اعتبار</th><th>سقف روزانه / هفتگی / ماهانه</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php foreach ($plans as $p): ?>
        <tr><td style="font-weight:600"><?php echo saas_h($p['name']); ?><?php if ($p['description'] !== ''): ?><div style="font-size:11px;color:#64748b;font-weight:normal"><?php echo saas_h($p['description']); ?></div><?php endif; ?>
            <?php if (function_exists('cx_flag_labels')): ?><div style="font-size:11px;color:#475569;font-weight:normal"><?php foreach (cx_flags_parse($p['flags'] ?? 'files,voice') as $fk) echo '<span class="badge badge-gray" style="margin:1px">' . cx_flag_labels()[$fk] . '</span>'; ?><?php $nb = count(cx_ids_parse($p['bot_ids'] ?? '')); if ($nb) echo ' <span class="badge badge-blue">🔗 + ' . $nb . ' چت‌بات دیگر</span>'; ?><?php if (function_exists('mx_cfg_parse') && ($mc = mx_cfg_parse($p['model_cfg'] ?? ''))) { $mo = count(array_filter($mc, fn($x) => $x['off'])); $ml = count($mc) - $mo; echo ' <span class="badge badge-gray">🧠 ' . ($mo ? $mo . ' مدل غیرفعال' : '') . ($mo && $ml ? '، ' : '') . ($ml ? $ml . ' مدل با سقف استفاده' : '') . '</span>'; } ?></div><?php endif; ?></td>
            <td><?php $hp = hd_plan_price($p); echo (int)$p['price_toman'] === 0 ? '<span class="badge badge-green">رایگان</span>' : ($hp['percent'] ? '<s style="color:#94a3b8">' . number_format($hp['original']) . '</s> ' . number_format($hp['price']) . ' تومان <span class="badge badge-red">' . $hp['percent'] . '٪</span>' : number_format((int)$p['price_toman']) . ' تومان'); ?></td>
            <td><?php echo function_exists('mcr_is_credit_plan') && mcr_is_credit_plan($p) ? '💰 ' . number_format(mcr_plan_credit($p)) . ' تومان شارژ' : number_format((int)$p['messages']); ?></td>
            <td><?php echo (int)$p['days'] > 0 ? (int)$p['days'] . ' روز' : 'بدون انقضا'; ?></td>
            <td><?php echo implode(' / ', array_map(fn($v) => (int)$v > 0 ? number_format((int)$v) : '—', [$p['daily_limit'] ?? 0, $p['week_limit'] ?? 0, $p['month_limit'] ?? 0])); ?></td>
            <td><?php echo $p['is_active'] ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-red">غیرفعال</span>'; ?></td>
            <td style="white-space:nowrap"><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=sales&edit_plan=<?php echo (int)$p['id']; ?>">ویرایش</a>
                <form method="post" style="display:inline" onsubmit="return confirm('این بسته حذف شود؟');"><input type="hidden" name="act" value="plan_del"><input type="hidden" name="plan_id" value="<?php echo (int)$p['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php
$cst = $pdo->prepare("SELECT * FROM hd_discount_codes WHERE bot_id=? ORDER BY id DESC LIMIT 200");
$cst->execute([$bid]);
$bcodes = $cst->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="card" id="codes">
    <div class="card-title">🎟️ کدهای تخفیف مخاطبان</div>
    <form method="post">
        <input type="hidden" name="act" value="code_save">
        <div class="row2">
            <div class="form-group"><label>کد (حروف و اعداد انگلیسی)</label><input type="text" name="code" maxlength="40" required dir="ltr" placeholder="NOWRUZ" style="text-transform:uppercase"></div>
            <div class="form-group"><label>مقدار تخفیف</label>
                <div style="display:flex;gap:6px"><input type="number" name="value" min="1" required value="20" style="flex:1">
                <select name="kind" style="width:120px"><option value="percent">درصد</option><option value="fixed">تومان</option></select></div></div>
        </div>
        <div class="row2">
            <div class="form-group"><label>برای بسته</label><select name="plan_id"><option value="0">همه بسته‌ها</option>
                <?php foreach ($plans as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo saas_h($p['name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>اعتبار تا تاریخ (خالی = بدون محدودیت)</label><input type="date" name="ends_at"></div>
        </div>
        <div class="row2">
            <div class="form-group"><label>حداکثر تعداد کل استفاده (۰ = نامحدود)</label><input type="number" name="max_uses" min="0" value="0"></div>
            <div class="form-group"><label>دفعات مجاز برای هر مخاطب (۰ = نامحدود)</label><input type="number" name="per_member_limit" min="0" value="1"></div>
        </div>
        <button class="btn btn-primary btn-sm">➕ ساخت کد تخفیف</button>
    </form>
    <?php if ($bcodes): ?>
    <div class="table-wrap" style="margin-top:14px"><table>
        <thead><tr><th>کد</th><th>تخفیف</th><th>بسته</th><th>استفاده</th><th>انقضا</th><th>وضعیت</th><th></th></tr></thead><tbody>
        <?php $pn = []; foreach ($plans as $p) $pn[(int)$p['id']] = $p['name'];
        foreach ($bcodes as $c): ?>
        <tr><td style="font-weight:700;direction:ltr;text-align:right"><?php echo saas_h($c['code']); ?></td>
            <td><?php echo $c['kind'] === 'fixed' ? number_format((int)$c['value']) . ' تومان' : (int)$c['value'] . '٪'; ?></td>
            <td style="font-size:12px"><?php echo (int)$c['plan_id'] ? saas_h($pn[(int)$c['plan_id']] ?? '—') : 'همه'; ?></td>
            <td><?php echo (int)$c['used_count'] . ((int)$c['max_uses'] ? ' / ' . (int)$c['max_uses'] : ''); ?></td>
            <td style="font-size:12px"><?php echo $c['ends_at'] ? biz_jdate($c['ends_at']) : '—'; ?></td>
            <td><?php echo $c['is_active'] ? '<span class="badge badge-green">فعال</span>' : '<span class="badge badge-red">غیرفعال</span>'; ?></td>
            <td style="white-space:nowrap">
                <form method="post" style="display:inline"><input type="hidden" name="act" value="code_toggle"><input type="hidden" name="code_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-outline btn-sm"><?php echo $c['is_active'] ? 'غیرفعال' : 'فعال'; ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟');"><input type="hidden" name="act" value="code_del"><input type="hidden" name="code_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">💰 درآمد</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px">
        <?php foreach ([['امروز', $rev_today], ['۳۰ روز اخیر', $rev_month], ['کل', $rev_all]] as $r): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;text-align:center">
            <div style="font-size:12px;color:#64748b"><?php echo $r[0]; ?></div>
            <div style="font-size:18px;font-weight:800;color:#16a34a"><?php echo number_format((int)$r[1]['s']); ?> <span style="font-size:11px">تومان</span></div>
            <div style="font-size:11px;color:#94a3b8"><?php echo (int)$r[1]['c']; ?> خرید</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$purchases): ?><p style="color:#94a3b8;text-align:center;padding:10px;font-size:13px">هنوز خریدی انجام نشده است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>مخاطب</th><th>بسته</th><th>مبلغ</th><th>مصرف</th><th>انقضا</th><th>کد پیگیری</th><th>تاریخ</th></tr></thead><tbody>
        <?php foreach ($purchases as $p): ?>
        <tr><td style="font-size:12px"><?php echo saas_h((string)$p['mname']); ?> <span style="color:#94a3b8;direction:ltr"><?php echo saas_h((string)$p['mobile']); ?></span></td>
            <td style="font-size:12px"><?php echo saas_h($p['plan_name']); ?></td>
            <td style="font-size:12px"><?php echo number_format((int)$p['amount_toman']); ?></td>
            <td style="font-size:12px"><?php echo (int)$p['used'] . ' / ' . (int)$p['messages']; ?></td>
            <td style="font-size:11px;direction:ltr;text-align:right;color:#64748b"><?php echo $p['expires_at'] ? substr($p['expires_at'], 0, 10) : '—'; ?></td>
            <td style="font-size:11px;direction:ltr;text-align:right"><?php echo saas_h($p['ref_code']); ?></td>
            <td style="font-size:11px;direction:ltr;text-align:right;color:#94a3b8"><?php echo substr((string)$p['paid_at'], 0, 16); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'reports'): ?>
<!-- ================= گزارش‌ها ================= -->
<?php
$stt = hd_stats($pdo, $bid);
$top = hd_top_questions($pdo, $bid, 15);
$prevq = $pdo->prepare("SELECT content FROM hd_messages WHERE thread_id=? AND id<? AND role='user' ORDER BY id DESC LIMIT 1");
$hd_ok_members = " AND x.member_id IN (SELECT id FROM hd_members WHERE bot_id=x.bot_id AND (consent_share=1 OR is_owner=1))";   // فقط مراجعانی که اجازه داده‌اند
if ($is_team) $hd_ok_members = " AND x.member_id IN (SELECT id FROM hd_members WHERE bot_id=x.bot_id AND (is_owner=1 OR (consent_share=1 AND (consultant_id=0 OR consultant_id=" . (int)($current_team['id'] ?? 0) . "))))";   // مراجع دارای مشاور اختصاصی دیگر، برای این همکار دیده نمی‌شود
$dl = $pdo->prepare("SELECT x.id, x.thread_id, x.content, x.created_at FROM hd_messages x WHERE x.bot_id=? AND x.rating=-1{$hd_ok_members} ORDER BY x.id DESC LIMIT 50");
$dl->execute([$bid]);
$dislikes = $dl->fetchAll(PDO::FETCH_ASSOC) ?: [];
$un = $pdo->prepare("SELECT x.id, x.thread_id, x.content, x.created_at FROM hd_messages x WHERE x.bot_id=? AND x.no_answer=1{$hd_ok_members} ORDER BY x.id DESC LIMIT 50");
$un->execute([$bid]);
$unans = $un->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ([&$dislikes, &$unans] as &$list) {
    foreach ($list as &$r) { $prevq->execute([(int)$r['thread_id'], (int)$r['id']]); $r['q'] = (string)$prevq->fetchColumn(); }
    unset($r);
}
unset($list);
$maxd = max(1, max($stt['daily']));
$rated = $stt['likes'] + $stt['dislikes'];
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px">
    <?php foreach ([
        ['👥 مخاطبان', number_format($stt['members'])],
        ['💬 پیام امروز', number_format($stt['msgs_today'])],
        ['📅 پیام ۷ روز', number_format($stt['msgs_week'])],
        ['👍 رضایت', $rated ? round($stt['likes'] * 100 / $rated) . '٪ (' . $rated . ' رأی)' : '—'],
        ['❓ بی‌پاسخ', number_format($stt['unanswered'])],
        ['💰 درآمد کل', number_format($stt['revenue']) . ' ت'],
    ] as $c): ?>
    <div class="card" style="margin:0;padding:14px;text-align:center">
        <div style="font-size:12px;color:#64748b"><?php echo $c[0]; ?></div>
        <div style="font-size:19px;font-weight:800;margin-top:4px"><?php echo $c[1]; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php
$bd = ch_days(30);
$b_msgs = ch_daily($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM hd_messages WHERE bot_id=? AND role='user' AND created_at>=? GROUP BY DATE(created_at)", [$bid], $bd['keys']);
$b_newm = ch_daily($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM hd_members WHERE bot_id=? AND created_at>=? GROUP BY DATE(created_at)", [$bid], $bd['keys']);
$b_ans = ['ok' => 0, 'no' => 0];
try {
    $qa = $pdo->prepare("SELECT no_answer AS n, COUNT(*) AS c FROM hd_messages WHERE bot_id=? AND role='assistant' AND created_at>=? GROUP BY no_answer");
    $qa->execute([$bid, $bd['keys'][0] . ' 00:00:00']);
    foreach ($qa->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $b_ans[(int)$r['n'] ? 'no' : 'ok'] += (int)$r['c'];
} catch (\Throwable $e) {}
?>
<div class="chx-grid">
    <?php
    echo ch_card('📈 پرسش‌های مخاطبان', ch_line($bd['labels'], [['name' => 'پرسش', 'data' => $b_msgs]], ['unit' => 'پیام', 'label' => 'پرسش‌های روزانه مخاطبان']), '۳۰ روز اخیر — هزینه ۷ روز اخیر: ' . number_format($stt['tokens_week']) . ' تومان', 'wide');
    echo ch_card('🍩 وضعیت پاسخ‌ها', ch_donut([['label' => 'پاسخ داده شد', 'value' => $b_ans['ok'], 'color' => '#1baf7a'], ['label' => 'در منابع پیدا نشد', 'value' => $b_ans['no'], 'color' => '#eda100']], ['unit' => 'پاسخ', 'center' => 'پاسخ در ۳۰ روز']), '۳۰ روز اخیر');
    echo ch_card('📈 مخاطبان جدید', ch_line($bd['labels'], [['name' => 'مخاطب جدید', 'data' => $b_newm, 'color' => '#4a3aa7']], ['unit' => 'نفر', 'label' => 'ثبت‌نام روزانه مخاطبان']), '۳۰ روز اخیر — ' . number_format(array_sum($b_newm)) . ' نفر', 'wide');
    echo ch_card('🥧 رضایت مخاطبان', ch_pie([['label' => '👍 مفید بود', 'value' => $stt['likes'], 'color' => '#2a78d6'], ['label' => '👎 مفید نبود', 'value' => $stt['dislikes'], 'color' => '#e34948']], ['unit' => 'رأی', 'empty' => 'هنوز رأیی ثبت نشده است.']), 'همه رأی‌ها');
    ?>
</div>

<div class="card">
    <div class="card-title">👎 پاسخ‌هایی که مخاطب مفید ندانست</div>
    <p class="hd-note" style="margin-bottom:8px">با «ثبت پاسخ درست»، پاسخ صحیح را به «پاسخ‌های تأییدشده» اضافه کنید تا دفعه بعد درست جواب داده شود.</p>
    <?php if (!$dislikes): ?><p style="color:#94a3b8;text-align:center;padding:12px;font-size:13px">موردی نیست 🎉</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>سوال مخاطب</th><th>پاسخ ربات</th><th></th></tr></thead><tbody>
        <?php foreach ($dislikes as $r): ?>
        <tr><td style="font-weight:600;max-width:240px;font-size:12.5px"><?php echo saas_h(mb_substr($r['q'], 0, 200)); ?></td>
            <td style="font-size:12px;color:#475569;max-width:360px"><?php echo saas_h(mb_substr($r['content'], 0, 220)); ?>…</td>
            <td style="white-space:nowrap"><a class="btn btn-primary btn-sm" href="<?php echo $self; ?>&tab=qa&prefill_q=<?php echo urlencode(mb_substr($r['q'], 0, 400)); ?>">✅ ثبت پاسخ درست</a>
                <a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=chats&view=<?php echo (int)$r['thread_id']; ?>">گفتگو</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">❓ سوال‌های بی‌پاسخ (در منابع پیدا نشد)</div>
    <p class="hd-note" style="margin-bottom:8px">این سوال‌ها نشان می‌دهد چه منابعی کم دارید. منبع مرتبط اضافه کنید یا پاسخ تأییدشده ثبت کنید.</p>
    <?php if (!$unans): ?><p style="color:#94a3b8;text-align:center;padding:12px;font-size:13px">موردی نیست.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>سوال</th><th>تاریخ</th><th></th></tr></thead><tbody>
        <?php foreach ($unans as $r): ?>
        <tr><td style="font-size:12.5px;max-width:420px"><?php echo saas_h(mb_substr($r['q'], 0, 250)); ?></td>
            <td style="font-size:11px;direction:ltr;text-align:right;color:#94a3b8"><?php echo substr($r['created_at'], 0, 16); ?></td>
            <td style="white-space:nowrap"><a class="btn btn-primary btn-sm" href="<?php echo $self; ?>&tab=qa&prefill_q=<?php echo urlencode(mb_substr($r['q'], 0, 400)); ?>">✅ ثبت پاسخ</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">🔁 پرتکرارترین سوال‌ها</div>
    <?php if (!$top): ?><p style="color:#94a3b8;text-align:center;padding:12px;font-size:13px">هنوز سوال تکراری ثبت نشده است.</p><?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>سوال (نمونه)</th><th>تکرار</th><th></th></tr></thead><tbody>
        <?php foreach ($top as $r): ?>
        <tr><td style="font-size:12.5px"><?php echo saas_h($r['q']); ?></td><td style="font-weight:700"><?php echo (int)$r['n']; ?></td>
            <td><a class="btn btn-outline btn-sm" href="<?php echo $self; ?>&tab=qa&prefill_q=<?php echo urlencode($r['q']); ?>">پاسخ تأییدشده</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'install'): ?>
<!-- ================= نصب ================= -->
<div class="card">
    <div class="card-title">🔌 نصب روی سایت خودتان (بدون نمایش نام ما)</div>
    <p class="hd-note">ربات روی <b>دامنه سایت شما</b> اجرا می‌شود. مخاطب فقط نام و لوگوی شما را می‌بیند و حتی در کد صفحه هم آدرسی از ما وجود ندارد.</p>
    <?php
    $cv_now = function_exists('hd_connector_hash') ? hd_connector_hash() : '';
    $cv_site = (string)($bot['connector_cv'] ?? '');
    $cv_seen = (string)($bot['connector_seen'] ?? '');
    $cv_recent = $cv_seen !== '' && strtotime($cv_seen) > time() - 30 * 86400;
    ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:10px;font-size:12.5px;line-height:2;<?php echo ($cv_site !== '' && $cv_site === $cv_now) ? 'background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46' : 'background:#fffbeb;border:1px solid #fde68a;color:#92400e'; ?>">
        <?php if ($cv_site !== '' && $cv_site === $cv_now): ?>
            ✅ فایل رابط نصب‌شده روی سایت شما <b>به‌روز</b> است (آخرین اتصال: <?php echo biz_jdate($cv_seen, true); ?>). نسخه‌های بعدی هم خودکار روی سایت شما به‌روز می‌شوند.
        <?php elseif ($cv_site !== '' && $cv_recent): ?>
            ⏳ نسخه جدیدتری از فایل رابط آماده است و حداکثر تا ۶ ساعت دیگر به‌طور خودکار روی سایت شما نصب می‌شود. اگر پس از آن هم این پیام را دیدید، هاست شما اجازه به‌روزرسانی خودکار نمی‌دهد (دسترسی نوشتن پوشه chat یا اتصال امن HTTPS)؛ در این صورت یک بار فایل را دوباره دانلود و جایگزین کنید.
        <?php else: ?>
            ⚠️ فایل رابط روی سایت شما <b>قدیمی</b> است یا هنوز نصب نشده. لطفاً <b>یک بار</b> فایل chat.zip را دوباره دانلود و جایگزین پوشه chat کنید (فایل config.php و پوشه cache را نگه دارید یا همه را جایگزین کنید). از این نسخه به بعد، تغییرات بعدی <b>خودکار</b> روی سایت شما اعمال می‌شود.
        <?php endif; ?>
    </div>
    <ol style="line-height:2.3;font-size:13px;padding-right:20px;margin-top:10px">
        <li>فایل رابط را دانلود کنید: <a class="btn btn-primary btn-sm" href="<?php echo $self; ?>&download=1">⬇️ دانلود chat.zip</a></li>
        <li>فایل zip را در ریشه هاست سایت خود (مثلاً <code>public_html</code>) آپلود و از حالت فشرده خارج کنید تا پوشه <code>chat</code> ساخته شود.</li>
        <li>صفحه چت آماده است: <code dir="ltr">https://yoursite.com/chat/</code> — این لینک را در منو، شبکه‌های اجتماعی یا پیامک برای مخاطبان قرار دهید.</li>
        <li>(اختیاری) برای <b>دکمه شناور</b> در همه صفحات سایت، این کد را قبل از <code>&lt;/body&gt;</code> قرار دهید:
            <div class="hd-code">&lt;script src="https://yoursite.com/chat/?a=embed" defer&gt;&lt;/script&gt;</div>
            <span class="hd-note">برای نمایش در سمت چپ: <code dir="ltr">?a=embed&amp;pos=left</code></span></li>
        <li>(اختیاری) نمایش داخل یک صفحه با iframe:
            <div class="hd-code">&lt;iframe src="https://yoursite.com/chat/?embed=2" style="width:100%;height:650px;border:0;border-radius:16px" allow="microphone; clipboard-write; autoplay"&gt;&lt;/iframe&gt;</div>
            <span class="hd-note">بخش <code dir="ltr">allow="microphone ..."</code> برای کار کردن پیام صوتی داخل iframe لازم است.</span></li>
        <?php $hubn = count(array_filter(hd_user_bots($pdo, $uid), fn($b) => !isset($b['hub_show']) || !empty($b['hub_show']))); ?>
        <li><b>🤖 صفحه یکپارچه همه چت‌بات‌های شما:</b> اگر چند چت‌بات دارید، لازم نیست برای هر کدام لینک جدا بسازید؛ با همین فایل رابط، آدرس زیر همه چت‌بات‌هایی را که تیک «نمایش در صفحه یکپارچه» دارند (اکنون <?php echo (int)$hubn; ?> چت‌بات) در یک صفحه نشان می‌دهد. مخاطب با یک بار ورود، چت‌بات‌هایی را که دارد یا خریده در بخش «چت‌بات‌های من» و بقیه را در «سایر چت‌بات‌ها» می‌بیند:
            <div class="hd-code">https://yoursite.com/chat/?bots=1</div></li>
    </ol>
    <p class="hd-note">پیش‌نیاز هاست: PHP 7.2 یا بالاتر با افزونه curl (در تقریباً همه هاست‌ها فعال است). اگر خودتان نمی‌توانید نصب کنید، با پشتیبانی تماس بگیرید تا برایتان راه‌اندازی کنیم.</p>
</div>
<div class="card">
    <div class="card-title">🧩 سایت وردپرسی دارید؟ افزونه آماده</div>
    <ol style="line-height:2.3;font-size:13px;padding-right:20px">
        <li>افزونه را دانلود کنید: <a class="btn btn-primary btn-sm" href="<?php echo $self; ?>&download=wp">⬇️ دانلود chat-assistant.zip</a></li>
        <li>در پیشخوان وردپرس: <b>افزونه‌ها ← افزودن ← بارگذاری افزونه</b>، فایل را انتخاب و «فعال‌سازی» را بزنید.</li>
        <li>دکمه شناور به‌طور خودکار در همه صفحات نمایش داده می‌شود (از <b>تنظیمات ← دستیار گفتگو</b> قابل تغییر است).</li>
        <li>برای نمایش داخل یک برگه، این کد کوتاه را در برگه قرار دهید: <code>[chat_assistant]</code></li>
    </ol>
    <p class="hd-note">در افزونه هم هیچ نام یا آدرسی از ما وجود ندارد. اگر کلید ربات را عوض کنید، افزونه را دوباره دانلود و نصب کنید.</p>
</div>

<?php
$cd = (string)($bot['custom_domain'] ?? '');
$dns_ok = null;
if ($cd !== '') {
    $ip_a = @gethostbyname($cd);
    $ip_b = @gethostbyname($platform_host);
    $dns_ok = ($ip_a !== $cd && $ip_a === $ip_b);
}
?>
<div class="card">
    <div class="card-title">🌐 زیردامنه اختصاصی (بدون نیاز به هاست)</div>
    <p class="hd-note">هاست ندارید یا نمی‌خواهید فایلی آپلود کنید؟ ربات را روی یک زیردامنه از دامنه خودتان (مثلاً <code dir="ltr">chat.yoursite.com</code>) اجرا کنید. مخاطب فقط دامنه شما را می‌بیند.</p>
    <?php $dom_fee = (int)biz_get($pdo, 'domain_fee'); if ($dom_fee > 0): ?>
        <p class="hd-note" style="margin-top:6px"><?php if (empty($bot['domain_paid'])): ?>💳 هزینه راه‌اندازی زیردامنه اختصاصی: <b><?php echo number_format($dom_fee); ?> تومان</b> (یک‌بار برای این ربات). پس از زدن «ثبت» به صفحه پرداخت منتقل می‌شوید و بعد از پرداخت، دامنه خودکار ثبت می‌شود.<?php else: ?>✅ هزینه راه‌اندازی زیردامنه برای این ربات پرداخت شده است؛ تغییر دامنه هزینه جدیدی ندارد.<?php endif; ?></p>
    <?php endif; ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:10px 0">
        <input type="hidden" name="act" value="set_domain">
        <div class="form-group" style="margin:0;flex:1;min-width:220px"><label>زیردامنه</label><input type="text" name="custom_domain" dir="ltr" value="<?php echo saas_h($cd); ?>" placeholder="chat.yoursite.com"></div>
        <button class="btn btn-primary"><?php echo ($dom_fee > 0 && empty($bot['domain_paid'])) ? '💳 ثبت و پرداخت' : '💾 ثبت'; ?></button>
    </form>
    <?php if ($cd !== ''): ?>
        <div class="alert <?php echo $dns_ok ? 'alert-success' : 'alert-info'; ?>" style="margin-top:8px">
            <?php echo $dns_ok ? '✅ رکورد DNS درست تنظیم شده است.' : '⏳ رکورد DNS هنوز به سرور ما اشاره نمی‌کند (تغییرات DNS ممکن است تا چند ساعت طول بکشد).'; ?>
        </div>
        <ol style="line-height:2.3;font-size:13px;padding-right:20px">
            <li>در پنل DNS دامنه خود یک رکورد <b>CNAME</b> بسازید:
                <div class="hd-code">Name: <?php echo saas_h(explode('.', $cd)[0]); ?>    Type: CNAME    Value: <?php echo saas_h($platform_host); ?></div>
                <span class="hd-note">(اگر از Cloudflare استفاده می‌کنید، حالت پروکسی را خاموش کنید — ابر خاکستری.)</span></li>
            <li>درخواست فعال‌سازی به‌طور خودکار برای پشتیبانی ارسال شده است؛ پس از فعال شدن زیردامنه روی سرور و صدور گواهی SSL، به شما اطلاع داده می‌شود.</li>
            <li>آدرس صفحه چت: <code dir="ltr">https://<?php echo saas_h($cd); ?>/</code> — کد دکمه شناور:
                <div class="hd-code">&lt;script src="https://<?php echo saas_h($cd); ?>/?a=embed" defer&gt;&lt;/script&gt;</div></li>
        </ol>
    <?php endif; ?>
</div>

<?php if (!empty($_GET['nozip'])): ?>
<div class="card">
    <div class="card-title">📁 دانلود جداگانه فایل‌ها</div>
    <p class="hd-note">ساخت فایل zip روی سرور ممکن نشد. فایل‌ها را جداگانه دانلود کنید و در پوشه <code>chat</code> قرار دهید (فایل <code>.htaccess</code> را هم با همین نام ذخیره کنید):</p>
    <?php foreach (array_keys(hd_connector_files($bot)) as $fn): ?>
        <div><a href="<?php echo $self; ?>&download=1&file=<?php echo urlencode($fn); ?>"><?php echo saas_h($fn); ?></a></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-title">🔑 کلید محرمانه ربات</div>
    <p class="hd-note">این کلید داخل فایل <code>config.php</code> فایل رابط قرار دارد. اگر فکر می‌کنید کلید فاش شده، کلید جدید بسازید و فایل رابط را دوباره دانلود کنید.</p>
    <div class="hd-code"><?php echo saas_h(substr($bot['bot_key'], 0, 6) . str_repeat('•', 30) . substr($bot['bot_key'], -6)); ?></div>
    <form method="post" onsubmit="return confirm('با ساخت کلید جدید، فایل رابط فعلی از کار می‌افتد تا نسخه جدید را نصب کنید. ادامه می‌دهید؟');">
        <input type="hidden" name="act" value="regen_key"><button class="btn btn-danger btn-sm">🔄 ساخت کلید جدید</button></form>
</div>

<?php elseif ($tab === 'test'): ?>
<!-- ================= آزمایش ================= -->
<div class="card">
    <div class="card-title">🧪 آزمایش ربات <span style="font-weight:normal;font-size:12px;color:#64748b">(هزینه از اعتبار شما کم می‌شود)</span></div>
    <div id="hdt" style="min-height:260px;max-height:520px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fafbfc"></div>
    <div style="display:flex;gap:8px;margin-top:10px">
        <textarea id="hdi" rows="2" style="flex:1" placeholder="یک سوال تخصصی بپرسید…"></textarea>
        <div style="display:flex;flex-direction:column;gap:6px">
            <button class="btn btn-primary" id="hds">ارسال</button>
            <button class="btn btn-outline btn-sm" id="hdn">گفتگوی جدید</button>
        </div>
    </div>
</div>
<script>
(function(){
    var box = document.getElementById('hdt'), inp = document.getElementById('hdi'), btn = document.getElementById('hds'), tid = 0;
    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function add(role, text, src){
        var d = document.createElement('div'); d.className = 'hd-msg ' + role;
        d.innerHTML = esc(text)
            .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
            .replace(/\[(\d{1,2})\]/g, '<sup style="color:#2563eb;font-weight:700">[$1]</sup>');
        if (src && src.length) {
            d.innerHTML += '<div class="hd-src">منابع: ' + src.map(function(s){ return '[' + s.n + '] ' + (s.url ? '<a target="_blank" href="' + esc(s.url) + '">' + esc(s.title) + '</a>' : esc(s.title)); }).join(' &nbsp; ') + '</div>';
        }
        box.appendChild(d); box.scrollTop = box.scrollHeight; return d;
    }
    function send(){
        var t = inp.value.trim(); if (!t) return;
        inp.value = ''; btn.disabled = true; add('user', t);
        var w = add('assistant', '⏳ در حال بررسی منابع و نوشتن پاسخ...');
        fetch('<?php echo $self; ?>&ajax=test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: t, thread_id: tid }) })
            .then(function(r){ return r.json(); })
            .then(function(r){ w.remove(); if (r.ok) { tid = r.thread_id; add('assistant', r.reply, r.sources); } else add('assistant', '⚠️ ' + (r.error || 'خطا')); })
            .catch(function(){ w.remove(); add('assistant', '⚠️ خطا در ارتباط'); })
            .finally(function(){ btn.disabled = false; inp.focus(); });
    }
    btn.onclick = send;
    inp.onkeydown = function(e){ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } };
    document.getElementById('hdn').onclick = function(){ tid = 0; box.innerHTML = ''; inp.focus(); };
})();
</script>
<?php elseif (isset($bm_tabs[$tab])): ?>
<?php bm_render($tab, $pdo, $bot, $self, $bm_ctx); ?>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
