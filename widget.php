<?php
/**
 * ویجت چت چندکاربره - AI Chat Widget SaaS
 * این فایل جاوا اسکریپت و HTML ویجت را برمی‌گرداند
 *
 * استفاده:
 *   <script src="https://domain.com/ai-chat/widget.php?key=SITE_KEY"></script>
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/saas_lib.php';

// --- دریافت site_key ---
$site_key = trim($_GET['key'] ?? '');

if (!$site_key) {
    echo "console.error('[AI Widget] کلید سایت (key) مشخص نشده است.');";
    exit;
}

// --- بارگذاری PDO ---
$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}

// --- بارگذاری کاربر و تنظیمات ---
$user = saas_get_user_by_site_key($pdo, $site_key);
if (!$user || $user['status'] !== 'active') {
    echo "console.error('[AI Widget] کلید سایت نامعتبر یا غیرفعال است.');";
    exit;
}

$user_id = (int)$user['id'];
$ws = saas_get_widget_settings($pdo, $user_id);

if (empty($ws['enabled'])) {
    echo "console.warn('[AI Widget] ویجت توسط کاربر غیرفعال شده است.');";
    exit;
}

$plan = $user['plan_id'] ? saas_get_plan($pdo, (int)$user['plan_id']) : null;

// --- تنظیمات ویجت ---
$widget_title   = (string)($ws['widget_title'] ?? 'دستیار هوشمند');   // در JS با escapeHtml امن می‌شود
$welcome_msg    = (string)($ws['welcome_message'] ?? 'سلام! چطور می‌تونم کمکتون کنم؟');
$primary_color  = preg_match('/^#[0-9a-fA-F]{6}$/', $ws['primary_color'] ?? '') ? $ws['primary_color'] : '#7c3aed';
$secondary_color = preg_match('/^#[0-9a-fA-F]{6}$/', $ws['secondary_color'] ?? '') ? $ws['secondary_color'] : '#ede9fe';
$text_color     = preg_match('/^#[0-9a-fA-F]{6}$/', $ws['text_color'] ?? '') ? $ws['text_color'] : '#ffffff';
$position       = in_array($ws['position'] ?? '', ['right', 'left']) ? $ws['position'] : 'right';
$show_lead_form = !empty($ws['show_lead_form']) ? 'true' : 'false';
$contact_on     = (!isset($ws['contact_on']) || !empty($ws['contact_on'])) && function_exists('cx_contact_save') ? 'true' : 'false';
$contact_text   = (string)($ws['contact_text'] ?? '');

$api_url = rtrim(AICHAT_BASE_URL, '/') . '/api.php';
$favicon_url = rtrim(AICHAT_BASE_URL, '/') . '/favicon.php?d=';

// --- آیکون‌های انتخابی کاربر (دکمه ویجت و دکمه ارسال) ---
$presets = saas_widget_icon_presets();
$own_prefix = rtrim(AICHAT_BASE_URL, '/') . '/uploads/icons/';
$launcher_custom = false;
$lk = $ws['launcher_icon'] ?? 'chat';
if ($lk === 'custom' && strpos((string)$ws['launcher_icon_url'], $own_prefix) === 0) {
    $launcher_icon_html = '<img src="' . htmlspecialchars($ws['launcher_icon_url'], ENT_QUOTES) . '" alt="">';
    $launcher_custom = true;
} else {
    $launcher_icon_html = $presets['launcher'][$lk]['svg'] ?? $presets['launcher']['chat']['svg'];
}
$sk = $ws['send_icon'] ?? 'plane';
if ($sk === 'custom' && strpos((string)$ws['send_icon_url'], $own_prefix) === 0) {
    $send_icon_html = '<img src="' . htmlspecialchars($ws['send_icon_url'], ENT_QUOTES) . '" alt="">';
} else {
    $send_icon_html = $presets['send'][$sk]['svg'] ?? $presets['send']['plane']['svg'];
}
$auto_peek = !isset($ws['auto_peek']) || !empty($ws['auto_peek']) ? 'true' : 'false';

// --- رنگ دکمه شماره تماس (قابل تغییر در پنل کاربر) ---
$phone_color = (isset($ws['phone_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $ws['phone_color'])) ? $ws['phone_color'] : '#10b981';
$pr = hexdec(substr($phone_color, 1, 2)); $pg = hexdec(substr($phone_color, 3, 2)); $pb = hexdec(substr($phone_color, 5, 2));
$phone_rgb  = "$pr,$pg,$pb";
$phone_lum  = (0.299 * $pr + 0.587 * $pg + 0.114 * $pb) / 255;
// رنگ متن شماره: کمی تیره‌تر از رنگ اصلی تا روی پس‌زمینه روشن خوانا باشد
$dk = $phone_lum > 0.6 ? 0.45 : 0.25;
$phone_text = sprintf('#%02x%02x%02x', (int)($pr * (1 - $dk)), (int)($pg * (1 - $dk)), (int)($pb * (1 - $dk)));
$phone_icon_fg = $phone_lum > 0.7 ? '#1e293b' : '#ffffff';

?>
(function() {
    'use strict';

    const WIDGET_KEY      = <?php echo json_encode($site_key); ?>;
    const API_URL         = <?php echo json_encode($api_url); ?>;
    const WIDGET_TITLE    = <?php echo json_encode($widget_title, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const WELCOME_MSG     = <?php echo json_encode($welcome_msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const PRIMARY_COLOR   = <?php echo json_encode($primary_color); ?>;
    const SECONDARY_COLOR = <?php echo json_encode($secondary_color); ?>;
    const TEXT_COLOR      = <?php echo json_encode($text_color); ?>;
    const POSITION        = <?php echo json_encode($position); ?>;
    const SHOW_LEAD_FORM  = <?php echo $show_lead_form; ?>;
    const CONTACT_ON      = <?php echo $contact_on; ?>;
    const CONTACT_TEXT    = <?php echo json_encode($contact_text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    const STORAGE_KEY     = 'ai_chat_' + WIDGET_KEY;
    const NET_DOWN_MSG    = 'با توجه به قطعی شبکه، فعلاً امکان پاسخگویی نیست.';
    const FAVICON_URL     = <?php echo json_encode($favicon_url); ?>;
    const LAUNCHER_ICON   = <?php echo json_encode($launcher_icon_html); ?>;
    const LAUNCHER_CUSTOM = <?php echo $launcher_custom ? 'true' : 'false'; ?>;
    const SEND_ICON       = <?php echo json_encode($send_icon_html); ?>;
    const AUTO_PEEK       = <?php echo $auto_peek; ?>;
    const PHONE_COLOR     = <?php echo json_encode($phone_color); ?>;
    const PHONE_RGB       = <?php echo json_encode($phone_rgb); ?>;
    const PHONE_TEXT      = <?php echo json_encode($phone_text); ?>;
    const PHONE_ICON_FG   = <?php echo json_encode($phone_icon_fg); ?>;
    const SVG_ATTR        = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    const ICON_CLOSE      = '<svg ' + SVG_ATTR + '><path d="M18 6L6 18M6 6l12 12"/></svg>';
    const ICON_EXPAND     = '<svg ' + SVG_ATTR + '><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>';
    const ICON_SHRINK     = '<svg ' + SVG_ATTR + '><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/></svg>';
    const ICON_RESET      = '<svg ' + SVG_ATTR + '><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>';
    const ICON_SMILE      = '<svg ' + SVG_ATTR + '><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><circle cx="9" cy="9.5" r=".8" fill="currentColor"/><circle cx="15" cy="9.5" r=".8" fill="currentColor"/></svg>';
    const ICON_PHONE      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg>';
    const GLOBE_ICON      = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>');
    const EMOJIS = ['😊','😃','😂','🤣','😍','🥰','😘','😉','😎','🤩','🙂','🙃','😇','🤔','🤗','😅','😢','😭','😡','😮','😴','🙏','👍','👎','👌','👏','🙌','💪','✌️','🤝','❤️','💙','💚','💯','🔥','⭐','🌹','🎉','🎁','✅','❌','⚡','☕','📞','📍','🛒','💰','📦','⏰','❓'];

    // جلوگیری از بارگذاری مجدد
    if (window.__aiChatWidgetLoaded) return;
    window.__aiChatWidgetLoaded = true;
    try { console.info('[AI Widget] نسخه 3.0 بارگذاری شد'); } catch (e) {}

    // ===== استایل =====
    const style = document.createElement('style');
    style.textContent = `
        #ai-chat-widget * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; direction: rtl; }
        #ai-chat-btn {
            position: fixed; ${POSITION}: 20px; bottom: 20px;
            width: 56px; height: 56px; border-radius: 50%;
            background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR};
            border: none; cursor: pointer; font-size: 24px; padding: 0; overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,.25); z-index: 9998;
            transition: transform .2s, box-shadow .2s; display: flex;
            align-items: center; justify-content: center;
        }
        #ai-chat-btn:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(0,0,0,.3); }
        .ai-btn-ic { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; }
        .ai-btn-ic.custom { width: 56px; height: 56px; }
        .ai-btn-ic img, .ai-avatar img, .ai-send img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
        .ai-send img { border-radius: 6px; object-fit: contain; }
        /* ویجت نیمه‌باز (پیش‌نمایش ۵ ثانیه‌ای) */
        #ai-chat-peek {
            position: fixed; ${POSITION}: 20px; bottom: 88px; z-index: 9999;
            width: 300px; max-width: calc(100vw - 32px);
            background: #fff; border-radius: 16px; overflow: hidden; cursor: pointer;
            box-shadow: 0 8px 32px rgba(0,0,0,.2); border: 1px solid rgba(0,0,0,.06);
            transform: translateY(24px); opacity: 0; pointer-events: none;
            transition: transform .45s cubic-bezier(.2,.9,.3,1.2), opacity .35s;
        }
        #ai-chat-peek.show { transform: translateY(0); opacity: 1; pointer-events: auto; }
        .ai-peek-head { background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR}; padding: 10px 14px; display: flex; align-items: center; gap: 10px; }
        .ai-peek-head .ai-avatar { width: 30px; height: 30px; padding: 6px; }
        .ai-peek-title { font-size: 13px; font-weight: 700; flex: 1; }
        .ai-peek-x { background: rgba(255,255,255,.2); border: none; color: ${TEXT_COLOR}; width: 24px; height: 24px; border-radius: 50%; cursor: pointer; padding: 5px; display: flex; }
        .ai-peek-body { padding: 12px 14px 14px; }
        .ai-peek-msg { background: #f1f5f9; color: #1e293b; border-radius: 12px; border-bottom-right-radius: 4px; padding: 9px 12px; font-size: 13px; line-height: 1.7; }
        .ai-peek-cta { margin-top: 10px; font-size: 12px; color: ${PRIMARY_COLOR}; font-weight: 700; text-align: center; }
        #ai-chat-box {
            position: fixed; ${POSITION}: 20px; bottom: 88px;
            width: 360px; max-width: calc(100vw - 32px);
            height: 520px; max-height: calc(100vh - 120px);
            background: #fff; border-radius: 16px; z-index: 9999;
            box-shadow: 0 8px 40px rgba(0,0,0,.18); display: none;
            flex-direction: column; overflow: hidden;
            border: 1px solid rgba(0,0,0,.08);
            transition: width .25s ease, height .25s ease;
        }
        #ai-chat-box.open { display: flex; animation: aiPop .28s ease-out; }
        @keyframes aiPop { from { opacity: 0; transform: translateY(16px) scale(.98); } to { opacity: 1; transform: none; } }
        /* حالت بزرگ‌نمایی */
        #ai-chat-box.expanded {
            width: min(760px, calc(100vw - 32px)); height: calc(100vh - 110px); max-height: none;
        }
        #ai-chat-box.expanded .ai-bubble { font-size: 14px; max-width: 80%; }
        .ai-header {
            background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR};
            padding: 14px 16px; display: flex; align-items: center;
            justify-content: space-between; flex-shrink: 0;
        }
        .ai-header-left { display: flex; align-items: center; gap: 10px; }
        .ai-avatar {
            width: 36px; height: 36px; border-radius: 50%; padding: 7px; overflow: hidden;
            background: rgba(255,255,255,.25); display: flex; color: ${TEXT_COLOR};
            align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
        }
        .ai-avatar.custom { padding: 0; }
        .ai-title { font-size: 14px; font-weight: 700; }
        .ai-status { font-size: 11px; opacity: .8; }
        .ai-header-actions { display: flex; gap: 6px; }
        .ai-btn-icon {
            background: rgba(255,255,255,.15); border: none; color: ${TEXT_COLOR};
            width: 30px; height: 30px; border-radius: 50%; cursor: pointer; padding: 7px;
            font-size: 15px; display: flex; align-items: center; justify-content: center;
        }
        .ai-btn-icon:hover { background: rgba(255,255,255,.3); }
        .ai-messages {
            flex: 1; overflow-y: auto; padding: 16px;
            display: flex; flex-direction: column; gap: 12px;
            scroll-behavior: smooth;
        }
        .ai-messages::-webkit-scrollbar { width: 4px; }
        .ai-messages::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 2px; }
        .ai-bubble {
            max-width: 85%; padding: 10px 14px; border-radius: 14px;
            font-size: 13px; line-height: 1.6; word-break: break-word;
        }
        .ai-bubble.bot {
            background: #f1f5f9; color: #1e293b;
            border-bottom-right-radius: 4px; align-self: flex-start;
        }
        .ai-bubble.user {
            background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR};
            border-bottom-left-radius: 4px; align-self: flex-end;
            white-space: pre-wrap;
        }
        /* لینک‌ها: به‌جای آدرس، آیکون سایت (favicon) یا تصویر محصول + عنوان — کل آن قابل کلیک */
        .ai-link {
            display: inline-flex; align-items: center; gap: 7px; vertical-align: middle;
            color: #1e293b; text-decoration: none; font-weight: 600; font-size: 12.5px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 999px;
            padding: 3px 12px 3px 4px; margin: 3px 0; max-width: 100%;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
            transition: border-color .15s, box-shadow .15s, transform .15s;
        }
        .ai-link span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px; }
        .ai-link:hover { border-color: ${PRIMARY_COLOR}; box-shadow: 0 2px 8px rgba(0,0,0,.1); transform: translateY(-1px); }
        .ai-favicon {
            width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0; object-fit: contain;
            background: #f8fafc; padding: 2px; border: 1px solid #f1f5f9;
        }
        .ai-link-product { border-radius: 12px; padding: 4px 12px 4px 4px; }
        .ai-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: 9px; flex-shrink: 0; background: #f1f5f9; }
        /* شماره تلفن: آیکون تماس + شماره، هر کدام در یک خط؛ با لمس، تماس برقرار می‌شود */
        .ai-phone {
            display: flex; align-items: center; gap: 10px; width: fit-content; max-width: 100%;
            direction: ltr; color: ${PHONE_TEXT}; text-decoration: none; font-weight: 700;
            margin: 6px 0; padding: 4px 14px 4px 4px; letter-spacing: .5px;
            background: rgba(${PHONE_RGB},0.09); border-radius: 999px;
            font-size: 13.5px; border: 1px solid rgba(${PHONE_RGB},0.35);
            transition: background .15s, transform .15s;
        }
        .ai-phone:hover { background: rgba(${PHONE_RGB},0.18); transform: translateY(-1px); }
        .ai-phone-ic {
            width: 30px; height: 30px; border-radius: 50%; background: ${PHONE_COLOR}; color: ${PHONE_ICON_FG};
            display: flex; align-items: center; justify-content: center; padding: 7px; flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(${PHONE_RGB},.4);
        }
        /* آیتم‌ها با دایره توپر مشکی */
        .ai-list-item { display: flex; align-items: flex-start; gap: 8px; margin: 6px 0; }
        .ai-bullet {
            width: 7px; height: 7px; min-width: 7px; border-radius: 50%;
            background: #111827; margin-top: 8px;
        }
        .ai-item-text { flex: 1; min-width: 0; }
        .ai-line { margin: 3px 0; }
        .ai-heading { font-weight: 700; margin-top: 6px; }
        .ai-gap { height: 6px; }
        .ai-bubble.bot strong { font-weight: 700; color: #0f172a; }
        .ai-typing {
            display: flex; gap: 4px; padding: 12px 14px;
            background: #f1f5f9; border-radius: 14px; border-bottom-right-radius: 4px;
            align-self: flex-start; width: 60px;
        }
        .ai-typing span {
            width: 8px; height: 8px; background: #94a3b8; border-radius: 50%;
            animation: typing 1.2s infinite;
        }
        .ai-typing span:nth-child(2) { animation-delay: .2s; }
        .ai-typing span:nth-child(3) { animation-delay: .4s; }
        @keyframes typing { 0%,80%,100%{transform:scale(.6);opacity:.4} 40%{transform:scale(1);opacity:1} }
        /* فرم اطلاعات تماس (فشرده) */
        .ai-lead-form {
            background: #f8fafc; padding: 8px 10px 9px; border-top: 1px solid #e2e8f0; flex-shrink: 0;
            position: relative;
        }
        .ai-lead-form p { font-size: 11px; color: #64748b; margin: 0 0 6px; padding-left: 22px; }
        .ai-lead-row { display: flex; gap: 6px; }
        .ai-lead-form input {
            flex: 1; min-width: 0; width: 100%; padding: 5px 8px; height: 30px; border: 1px solid #d1d5db;
            border-radius: 7px; font-size: 11.5px; font-family: inherit;
            margin-bottom: 5px; color: #1e293b; background: #fff;
        }
        .ai-lead-form input:focus { outline: none; border-color: ${PRIMARY_COLOR}; }
        .ai-lead-submit {
            width: 100%; padding: 5px; height: 30px; background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR};
            border: none; border-radius: 7px; font-size: 11.5px; cursor: pointer; font-family: inherit;
            font-weight: 600;
        }
        .ai-lead-x {
            position: absolute; left: 8px; top: 6px; width: 18px; height: 18px; border: none; padding: 3px;
            background: #e2e8f0; color: #64748b; border-radius: 50%; cursor: pointer; display: flex;
        }
        /* تماس با کارشناس */
        .ai-contact-link { display: flex; justify-content: center; padding: 4px 0 0; flex-shrink: 0; background: #fff; }
        .ai-contact-link button { border: 1px solid #e2e8f0; background: #f8fafc; color: #334155; border-radius: 16px; padding: 3px 12px; font-size: 11.5px; cursor: pointer; font-family: inherit; }
        .ai-contact-link button:hover { border-color: ${PRIMARY_COLOR}; color: ${PRIMARY_COLOR}; }
        .ai-contact { position: absolute; inset: 0; top: 64px; background: #fff; z-index: 5; display: none; flex-direction: column; padding: 16px; overflow-y: auto; }
        .ai-contact.open { display: flex; }
        .ai-contact h4 { margin: 0 0 6px; font-size: 14px; color: #1e293b; display: flex; justify-content: space-between; align-items: center; }
        .ai-contact h4 button { border: none; background: #f1f5f9; color: #64748b; border-radius: 50%; width: 26px; height: 26px; cursor: pointer; padding: 6px; display: flex; }
        .ai-contact p { font-size: 12px; color: #64748b; line-height: 1.9; margin: 0 0 10px; }
        .ai-contact input, .ai-contact textarea { width: 100%; box-sizing: border-box; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 13px; margin-bottom: 8px; font-family: inherit; outline: none; }
        .ai-contact input:focus, .ai-contact textarea:focus { border-color: ${PRIMARY_COLOR}; }
        .ai-contact .ai-c-go { background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR}; border: none; border-radius: 10px; padding: 10px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: 13px; }
        .ai-contact .ai-c-go:disabled { opacity: .6; }
        .ai-c-msg { font-size: 12.5px; margin-top: 8px; line-height: 1.9; }
        .ai-footer {
            padding: 10px 12px; border-top: 1px solid #f1f5f9; position: relative;
            display: flex; gap: 8px; flex-shrink: 0; background: #fff; align-items: flex-end;
        }
        .ai-emoji-btn {
            width: 38px; height: 38px; flex-shrink: 0; border: none; background: #f1f5f9;
            color: #64748b; border-radius: 10px; cursor: pointer; padding: 8px; display: flex;
        }
        .ai-emoji-btn:hover, .ai-emoji-btn.on { background: #e2e8f0; color: ${PRIMARY_COLOR}; }
        .ai-emoji-panel {
            position: absolute; bottom: 58px; right: 10px; left: 10px; z-index: 2;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,.12); padding: 8px;
            display: none; grid-template-columns: repeat(8, 1fr); gap: 2px;
            max-height: 190px; overflow-y: auto;
        }
        .ai-emoji-panel.open { display: grid; }
        .ai-emoji-panel button {
            border: none; background: none; font-size: 21px; line-height: 1; padding: 6px 0;
            cursor: pointer; border-radius: 8px; font-family: 'Segoe UI Emoji','Apple Color Emoji','Noto Color Emoji',sans-serif;
        }
        .ai-emoji-panel button:hover { background: #f1f5f9; }
        .ai-input {
            flex: 1; padding: 9px 12px; border: 1.5px solid #e2e8f0;
            border-radius: 10px; font-size: 13px; font-family: inherit;
            resize: none; max-height: 80px; min-height: 38px; color: #1e293b;
            direction: rtl;
        }
        .ai-input:focus { outline: none; border-color: ${PRIMARY_COLOR}; }
        .ai-send {
            width: 38px; height: 38px; background: ${PRIMARY_COLOR}; color: ${TEXT_COLOR};
            border: none; border-radius: 10px; cursor: pointer; font-size: 16px; padding: 9px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .ai-send:hover { opacity: .88; }
        .ai-send:disabled { opacity: .4; cursor: default; }
        .ai-credit-bar {
            font-size: 11px; color: #94a3b8; text-align: center;
            padding: 2px 8px 6px; direction: rtl;
        }
        @media(max-width: 480px) {
            #ai-chat-box { width: calc(100vw - 24px); ${POSITION}: 12px; bottom: 80px; }
            #ai-chat-box.expanded { width: 100vw; height: 100%; max-height: none; max-width: none; top: 0; bottom: 0; ${POSITION}: 0; border-radius: 0; }
            #ai-chat-peek { ${POSITION}: 12px; }
        }
        /* ===== جداسازی ظاهر ویجت از قالب سایت میزبان (فونت و اندازه متن‌ها ثابت می‌ماند) ===== */
        #ai-chat-widget, #ai-chat-widget * {
            font-family: 'Vazirmatn', 'Vazir', 'Shabnam', 'IRANSans', Tahoma, 'Segoe UI', sans-serif !important;
            letter-spacing: normal !important; text-transform: none !important; text-shadow: none !important;
        }
        #ai-chat-widget .ai-bubble { font-size: 13px !important; line-height: 1.8 !important; font-weight: 400 !important; }
        #ai-chat-widget #ai-chat-box.expanded .ai-bubble { font-size: 14px !important; }
        #ai-chat-widget .ai-bubble div, #ai-chat-widget .ai-bubble span, #ai-chat-widget .ai-bubble strong,
        #ai-chat-widget .ai-bubble b, #ai-chat-widget .ai-bubble em, #ai-chat-widget .ai-bubble i, #ai-chat-widget .ai-bubble p {
            font-size: inherit !important; line-height: inherit !important; font-style: normal !important;
            float: none !important; text-decoration: none !important; background-image: none !important;
        }
        #ai-chat-widget .ai-bubble strong, #ai-chat-widget .ai-bubble b {
            font-weight: 700 !important; display: inline !important; margin: 0 !important; padding: 0 !important; background: none !important;
        }
        #ai-chat-widget .ai-bubble.bot strong, #ai-chat-widget .ai-bubble.bot b { color: #0f172a !important; }
        #ai-chat-widget .ai-heading { font-weight: 700 !important; }
        #ai-chat-widget .ai-link { font-size: 12.5px !important; line-height: 1.5 !important; }
        #ai-chat-widget .ai-phone { font-size: 13.5px !important; line-height: 1.5 !important; letter-spacing: .5px !important; }
        #ai-chat-widget .ai-bullet { width: 6px !important; height: 6px !important; min-width: 6px !important; margin-top: .68em !important; }
        #ai-chat-widget .ai-emoji-panel button { font-family: 'Segoe UI Emoji', 'Apple Color Emoji', 'Noto Color Emoji', sans-serif !important; }
        /* افکت ساده روی لینک‌ها و دکمه‌های داخل ویجت */
        #ai-chat-widget :where(a, button) { transition: color .18s ease, background-color .18s ease, border-color .18s ease, box-shadow .2s ease, translate .18s ease, filter .18s ease, opacity .18s ease; }
        #ai-chat-box :where(a[href], button:not(:disabled)):hover { translate: 0 -1px; }
        #ai-chat-box :where(a[href], button:not(:disabled)):active { translate: 0 0; filter: brightness(.96); }
        #ai-chat-box :where(a.ai-link, a[href]:not([class])):hover { text-decoration: underline; text-underline-offset: 3px; }
        @media (prefers-reduced-motion: reduce) { #ai-chat-widget :where(a, button):hover { translate: none !important; } }
    `;
    document.head.appendChild(style);
    // فونت فارسی یکسان در همه دستگاه‌ها (اگر در سایت از قبل بارگذاری نشده باشد)
    if (!document.getElementById('ai-chat-font')) {
        var fl = document.createElement('link');
        fl.id = 'ai-chat-font'; fl.rel = 'stylesheet';
        fl.href = 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css';
        document.head.appendChild(fl);
    }

    // ===== توابع کمکی =====
    function escapeHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function escapeAttr(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
            .replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ===== پردازش inline: لینک‌ها، تلفن‌ها و متن پررنگ =====
    var FA_DIGITS = { '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
                      '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9' };
    function toEnDigits(s) { return String(s).replace(/[۰-۹٠-٩]/g, function(d){ return FA_DIGITS[d] || d; }); }

    function domainOf(url) {
        var m = String(url).match(/^https?:\/\/([^\/?#:]+)/i);
        return m ? m[1].replace(/^www\./i, '') : '';
    }

    // ساخت لینک: تصویر کوچک محصول (اگر موجود باشد) یا favicon سایت + عنوان
    // نام آشنای برخی سایت‌ها (وقتی عنوان لینک فقط آدرس است)
    var KNOWN_SITES = {
        'neshan.org': 'مسیریابی در نشان', 'balad.ir': 'مسیریابی در بلد', 'google.com': 'گوگل',
        'maps.google.com': 'نقشه گوگل', 'goo.gl': 'نقشه گوگل', 'maps.app.goo.gl': 'نقشه گوگل',
        'instagram.com': 'اینستاگرام', 't.me': 'تلگرام', 'telegram.me': 'تلگرام',
        'wa.me': 'واتساپ', 'whatsapp.com': 'واتساپ', 'api.whatsapp.com': 'واتساپ',
        'eitaa.com': 'ایتا', 'rubika.ir': 'روبیکا', 'ble.ir': 'بله', 'aparat.com': 'آپارات',
        'youtube.com': 'یوتیوب', 'youtu.be': 'یوتیوب', 'linkedin.com': 'لینکدین', 'divar.ir': 'دیوار'
    };
    function friendlyLabel(label, url) {
        var dom = domainOf(url);
        var l = String(label || '').trim();
        if (!l || /^https?:\/\//i.test(l) || l.replace(/^www\./, '') === dom) {
            return KNOWN_SITES[dom] || dom || 'مشاهده لینک';
        }
        return l;
    }

    // ساخت لینک: تصویر کوچک محصول (اگر موجود باشد) یا favicon سایت + عنوان (بدون نمایش آدرس)
    function buildLink(url, label, images) {
        var dom = domainOf(url);
        var img = images && images[url] ? images[url] : '';
        var icon = img
            ? '<img src="' + escapeAttr(img) + '" class="ai-thumb" alt="" loading="lazy" onerror="this.onerror=null;this.className=\'ai-favicon\';this.src=\'' + escapeAttr(FAVICON_URL + encodeURIComponent(dom)) + '\'">'
            : '<img src="' + escapeAttr(FAVICON_URL + encodeURIComponent(dom)) + '" class="ai-favicon" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'' + GLOBE_ICON + '\'">';
        return '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener noreferrer" class="ai-link' + (img ? ' ai-link-product' : '') + '" title="' + escapeAttr(url) + '">'
             + icon + '<span>' + escapeHtml(friendlyLabel(label, url)) + '</span></a>';
    }

    // شماره تلفن ایرانی (ارقام فارسی یا انگلیسی، با یا بدون فاصله/خط تیره) + آیکون تماس
    function buildPhone(raw) {
        var digits = toEnDigits(raw).replace(/[^\d+]/g, '');
        if (digits.indexOf('0098') === 0) digits = '+98' + digits.slice(4);
        var ok = /^0\d{10}$/.test(digits) || /^\+98\d{10}$/.test(digits);
        if (!ok) return null;
        return '<a href="tel:' + escapeAttr(digits) + '" class="ai-phone" title="تماس با ' + escapeAttr(digits) + '">'
             + '<span class="ai-phone-ic">' + ICON_PHONE + '</span>'
             + '<span>' + escapeHtml(toEnDigits(raw).trim()) + '</span></a>';
    }

    function processInlineHtml(text, images) {
        var D = '[0-9۰-۹٠-٩]';
        var re = new RegExp(
            '(\\*\\*([^*]+?)\\*\\*)' +                                     // 1,2  **bold**
            '|(\\[([^\\]]+)\\]\\s*\\((https?:\\/\\/(?:[^()\\s]|\\([^()\\s]*\\))+)\\))' +   // 3,4,5 [label](url) — پرانتز داخل آدرس مجاز
            '|(https?:\\/\\/[^\\s<>"\'،؛]+)' +                               // 6  URL
            '|((?:\\+|00)(?:98|۹۸)[\\s\\-]?' + D + '{2,3}[\\s\\-]?' + D + '{3,4}[\\s\\-]?' + D + '{3,4}' +
              '|[0۰٠]' + D + '{2,3}[\\s\\-]?' + D + '{3,4}[\\s\\-]?' + D + '{3,4})', // 7 phone
            'g');
        var result = '';
        var lastIndex = 0;
        var m;
        while ((m = re.exec(text)) !== null) {
            var before = text.slice(lastIndex, m.index);
            var html = null;
            if (m[1]) {
                html = '<strong>' + processInlineHtml(m[2], images) + '</strong>';
            } else if (m[3]) {
                html = buildLink(m[5], m[4], images);
            } else if (m[6]) {
                var url = m[6], trail = '';
                while (/[.,!?:;)\]]$/.test(url)) { trail = url.slice(-1) + trail; url = url.slice(0, -1); }
                html = buildLink(url, domainOf(url), images) + escapeHtml(trail);
            } else if (m[7]) {
                var prevCh = m.index > 0 ? text.charAt(m.index - 1) : '';
                if (!/[0-9۰-۹٠-٩]/.test(prevCh)) html = buildPhone(m[7]);
            }
            result += escapeHtml(before) + (html !== null ? html : escapeHtml(m[0]));
            lastIndex = re.lastIndex;
        }
        result += escapeHtml(text.slice(lastIndex));
        return result;
    }

    // ===== رندر کامل پیام ربات: آیتم‌ها با دایره توپر مشکی =====
    var LIST_RE = /^(?:[-*•●⬤▪■◆‣–—]|[0-9۰-۹]{1,2}[.)\-–]|[0-9۰-۹]{1,2}\s*[-–])\s*/;
    function renderBotMessage(rawText, images) {
        if (!rawText) return '';
        var lines = String(rawText).replace(/\r/g, '').split('\n');
        var htmlParts = [];
        for (var i = 0; i < lines.length; i++) {
            var t = lines[i].trim();
            if (!t) {
                if (htmlParts.length && htmlParts[htmlParts.length - 1] !== '<div class="ai-gap"></div>') {
                    htmlParts.push('<div class="ai-gap"></div>');
                }
                continue;
            }
            if (/^#{1,6}\s+/.test(t)) {                       // عنوان Markdown
                htmlParts.push('<div class="ai-line ai-heading">' + processInlineHtml(t.replace(/^#{1,6}\s+/, '').replace(/\*\*/g, ''), images) + '</div>');
                continue;
            }
            if (/^[-*_]{3,}$/.test(t)) continue;                // خط جداکننده
            if (LIST_RE.test(t) && !/^[0۰]\d{9,}/.test(toEnDigits(t))) {
                var c = t.replace(LIST_RE, '');
                htmlParts.push('<div class="ai-list-item"><span class="ai-bullet"></span><div class="ai-item-text">' + processInlineHtml(c, images) + '</div></div>');
            } else {
                htmlParts.push('<div class="ai-line">' + processInlineHtml(t, images) + '</div>');
            }
        }
        while (htmlParts.length && htmlParts[htmlParts.length - 1] === '<div class="ai-gap"></div>') htmlParts.pop();
        return htmlParts.join('');
    }

    // ===== HTML =====
    const wrapper = document.createElement('div');
    wrapper.id = 'ai-chat-widget';
    wrapper.innerHTML = `
        <button id="ai-chat-btn" title="${escapeAttr(WIDGET_TITLE)}" aria-label="${escapeAttr(WIDGET_TITLE)}"><span class="ai-btn-ic${LAUNCHER_CUSTOM ? ' custom' : ''}" id="ai-btn-ic">${LAUNCHER_ICON}</span></button>
        <div id="ai-chat-peek" role="dialog" aria-label="${escapeAttr(WIDGET_TITLE)}">
            <div class="ai-peek-head">
                <div class="ai-avatar${LAUNCHER_CUSTOM ? ' custom' : ''}">${LAUNCHER_ICON}</div>
                <div class="ai-peek-title">${escapeHtml(WIDGET_TITLE)}</div>
                <button class="ai-peek-x" id="ai-peek-x" title="بستن">${ICON_CLOSE}</button>
            </div>
            <div class="ai-peek-body">
                <div class="ai-peek-msg">${escapeHtml(WELCOME_MSG)}</div>
                <div class="ai-peek-cta">برای گفتگو کلیک کنید 👆</div>
            </div>
        </div>
        <div id="ai-chat-box">
            <div class="ai-header">
                <div class="ai-header-left">
                    <div class="ai-avatar${LAUNCHER_CUSTOM ? ' custom' : ''}">${LAUNCHER_ICON}</div>
                    <div>
                        <div class="ai-title">${escapeHtml(WIDGET_TITLE)}</div>
                        <div class="ai-status">آنلاین</div>
                    </div>
                </div>
                <div class="ai-header-actions">
                    ${CONTACT_ON ? '<button class="ai-btn-icon" id="ai-contact-btn" title="تماس با کارشناس">📞</button>' : ''}
                    <button class="ai-btn-icon" id="ai-expand-btn" title="بزرگ‌نمایی">${ICON_EXPAND}</button>
                    <button class="ai-btn-icon" id="ai-reset-btn" title="مکالمه جدید">${ICON_RESET}</button>
                    <button class="ai-btn-icon" id="ai-close-btn" title="بستن">${ICON_CLOSE}</button>
                </div>
            </div>
            <div class="ai-messages" id="ai-messages"></div>
            ${SHOW_LEAD_FORM ? `
            <div class="ai-lead-form" id="ai-lead-form">
                <button class="ai-lead-x" id="ai-lead-x" title="بعداً">${ICON_CLOSE}</button>
                <p>📋 اطلاعات تماس شما (اختیاری):</p>
                <div class="ai-lead-row">
                    <input type="text" id="ai-lead-name" placeholder="نام شما">
                    <input type="tel" id="ai-lead-phone" placeholder="شماره موبایل" dir="ltr">
                </div>
                <button class="ai-lead-submit" id="ai-lead-submit">ارسال اطلاعات ✓</button>
            </div>` : ''}
            ${CONTACT_ON ? `
            <div class="ai-contact" id="ai-contact">
                <h4>📞 تماس با کارشناس <button type="button" id="ai-c-x" title="بازگشت به گفتگو">${ICON_CLOSE}</button></h4>
                <p>${escapeHtml(CONTACT_TEXT || 'نام، شماره موبایل و پیامتان را بگذارید؛ کارشناسان ما در اولین فرصت با شما تماس می‌گیرند.')}</p>
                <input type="text" id="ai-c-name" placeholder="نام شما" maxlength="100">
                <input type="tel" id="ai-c-phone" placeholder="شماره موبایل" dir="ltr" maxlength="14">
                <textarea id="ai-c-msg" rows="4" placeholder="پیام شما برای کارشناس…" maxlength="2000"></textarea>
                <button type="button" class="ai-c-go" id="ai-c-go">ارسال برای کارشناس</button>
                <div class="ai-c-msg" id="ai-c-res"></div>
            </div>
            <div class="ai-contact-link"><button type="button" id="ai-c-open">📞 تماس با کارشناس</button></div>` : ''}
            <div class="ai-footer">
                <div class="ai-emoji-panel" id="ai-emoji-panel"></div>
                <button class="ai-emoji-btn" id="ai-emoji-btn" title="شکلک">${ICON_SMILE}</button>
                <textarea class="ai-input" id="ai-input" placeholder="پیام بنویسید..." rows="1"></textarea>
                <button class="ai-send" id="ai-send-btn" title="ارسال">${SEND_ICON}</button>
            </div>
            <div class="ai-credit-bar" id="ai-credit-bar"></div>
        </div>
    `;
    document.body.appendChild(wrapper);

    // ===== متغیرها =====
    const chatBtn    = document.getElementById('ai-chat-btn');
    const chatBox    = document.getElementById('ai-chat-box');
    const closeBtn   = document.getElementById('ai-close-btn');
    const resetBtn   = document.getElementById('ai-reset-btn');
    const messages   = document.getElementById('ai-messages');
    const input      = document.getElementById('ai-input');
    const sendBtn    = document.getElementById('ai-send-btn');
    const creditBar  = document.getElementById('ai-credit-bar');
    const leadForm   = document.getElementById('ai-lead-form');
    const leadSubmit = document.getElementById('ai-lead-submit');

    let isOpen      = false;
    let isLoading   = false;
    let chatReady   = false;
    let convToken   = '';    // توکن مکالمه جاری
    let totalTokens = 0;    // مجموع توکن‌های این مکالمه
    let chatHistory = [];   // لیست پیام‌ها برای localStorage

    // ===== مدیریت localStorage (ذخیره ۲۴ ساعته) =====
    function loadChatState() {
        try {
            const s = localStorage.getItem(STORAGE_KEY);
            if (!s) return null;
            const st = JSON.parse(s);
            if (!st || !st.saved_at || !st.conv_token) return null;
            if (Date.now() - st.saved_at > 24 * 60 * 60 * 1000) {
                localStorage.removeItem(STORAGE_KEY);
                return null;
            }
            return st;
        } catch(e) { return null; }
    }

    function saveChatState() {
        if (!convToken) return;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                saved_at:     Date.now(),
                conv_token:   convToken,
                total_tokens: totalTokens,
                messages:     chatHistory
            }));
        } catch(e) {}
    }

    function clearChatState() {
        try { localStorage.removeItem(STORAGE_KEY); } catch(e) {}
    }

    // ===== نمایش پیام =====
    function addMessage(role, text, tokens, images) {
        if (role === 'assistant') role = 'bot';   // نقش ذخیره‌شده در سرور
        var div = document.createElement('div');
        div.className = 'ai-bubble ' + role;
        if (role === 'bot') {
            // رندر غنی: آیتم‌ها، لینک با favicon/تصویر محصول، تلفن قابل تماس
            div.innerHTML = renderBotMessage(text, images || {});
        } else {
            div.textContent = text;
        }
        // تعداد توکن در ویجت نمایش داده نمی‌شود (فقط در پنل کاربری)
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        chatHistory.push({ role: role, text: text, images: images || {} });
        return div;
    }

    function updateCreditBar(credit) {
        if (credit !== undefined && credit !== null) {
            // اعتبار/توکن به بازدیدکننده نمایش داده نمی‌شود؛ فقط هشدار اتمام اعتبار
            creditBar.textContent = Number(credit) <= 0 ? 'پاسخ‌گویی موقتاً در دسترس نیست.' : '';
        }
    }

    function showTyping() {
        const t = document.createElement('div');
        t.className = 'ai-typing';
        t.id = 'ai-typing';
        t.innerHTML = '<span></span><span></span><span></span>';
        messages.appendChild(t);
        messages.scrollTop = messages.scrollHeight;
    }

    function hideTyping() {
        const t = document.getElementById('ai-typing');
        if (t) t.remove();
    }

    // ===== بارگذاری تاریخچه =====
    function loadHistory() {
        // اول localStorage چک می‌شود (سریع، بدون شبکه)
        var state = loadChatState();
        if (state && state.messages && state.messages.length > 0) {
            convToken    = state.conv_token || '';
            totalTokens  = state.total_tokens || 0;
            chatHistory  = [];
            state.messages.forEach(function(m) {
                addMessage(m.role, m.text, 0, m.images || {});
            });
            messages.scrollTop = messages.scrollHeight;
            chatReady = true;
            return;
        }

        // fallback: بارگذاری از سرور
        fetch(API_URL + '?key=' + encodeURIComponent(WIDGET_KEY) + '&action=history')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.messages && data.messages.length > 0) {
                    if (data.conv_token) convToken = data.conv_token;
                    data.messages.forEach(function(m) { addMessage(m.role, m.content, 0); });
                    saveChatState();
                } else {
                    addMessage('bot', WELCOME_MSG, 0);
                }
                chatReady = true;
            })
            .catch(function() {
                addMessage('bot', WELCOME_MSG, 0);
                chatReady = true;
            });
    }

    // ===== باز و بسته کردن =====
    const btnIc     = document.getElementById('ai-btn-ic');
    const expandBtn = document.getElementById('ai-expand-btn');
    const peek      = document.getElementById('ai-chat-peek');
    const peekX     = document.getElementById('ai-peek-x');
    const emojiBtn  = document.getElementById('ai-emoji-btn');
    const emojiPanel= document.getElementById('ai-emoji-panel');
    let peekTimer   = null;

    function hidePeek() {
        if (peekTimer) { clearTimeout(peekTimer); peekTimer = null; }
        peek.classList.remove('show');
    }

    function openChat() {
        hidePeek();
        isOpen = true;
        chatBox.classList.add('open');
        btnIc.className = 'ai-btn-ic';
        btnIc.innerHTML = ICON_CLOSE;
        if (!chatReady) loadHistory();
        setTimeout(function() { input.focus(); }, 120);
    }

    function closeChat() {
        isOpen = false;
        chatBox.classList.remove('open');
        emojiPanel.classList.remove('open');
        emojiBtn.classList.remove('on');
        btnIc.className = 'ai-btn-ic' + (LAUNCHER_CUSTOM ? ' custom' : '');
        btnIc.innerHTML = LAUNCHER_ICON;
    }

    chatBtn.addEventListener('click', function() { isOpen ? closeChat() : openChat(); });
    closeBtn.addEventListener('click', closeChat);

    // بزرگ‌نمایی / کوچک‌نمایی
    expandBtn.addEventListener('click', function() {
        var ex = chatBox.classList.toggle('expanded');
        expandBtn.innerHTML = ex ? ICON_SHRINK : ICON_EXPAND;
        expandBtn.title = ex ? 'کوچک‌نمایی' : 'بزرگ‌نمایی';
        messages.scrollTop = messages.scrollHeight;
    });

    // ===== حالت نیمه‌باز: ۵ ثانیه پس از باز شدن سایت (یک بار در هر بازدید) =====
    peek.addEventListener('click', function(e) {
        if (e.target.closest && e.target.closest('#ai-peek-x')) return;
        openChat();
    });
    peekX.addEventListener('click', function(e) { e.stopPropagation(); hidePeek(); });
    peek.addEventListener('mouseenter', function() { if (peekTimer) { clearTimeout(peekTimer); peekTimer = null; } });
    peek.addEventListener('mouseleave', function() { if (peek.classList.contains('show')) peekTimer = setTimeout(hidePeek, 2000); });

    (function schedulePeek() {
        if (!AUTO_PEEK) return;
        var shown = false;
        try { shown = sessionStorage.getItem('ai_peek_' + WIDGET_KEY) === '1'; } catch (e) {}
        if (shown) return;
        setTimeout(function() {
            if (isOpen) return;
            peek.classList.add('show');
            try { sessionStorage.setItem('ai_peek_' + WIDGET_KEY, '1'); } catch (e) {}
            peekTimer = setTimeout(hidePeek, 15000);   // حالت نیمه‌باز ۱۵ ثانیه
        }, 1500);
    })();

    // ===== شکلک‌ها =====
    EMOJIS.forEach(function(em) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = em;
        b.addEventListener('click', function() {
            var st = input.selectionStart != null ? input.selectionStart : input.value.length;
            var en = input.selectionEnd != null ? input.selectionEnd : input.value.length;
            input.value = input.value.slice(0, st) + em + input.value.slice(en);
            var pos = st + em.length;
            input.focus();
            try { input.setSelectionRange(pos, pos); } catch (e) {}
            input.dispatchEvent(new Event('input'));
        });
        emojiPanel.appendChild(b);
    });
    emojiBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        var on = emojiPanel.classList.toggle('open');
        emojiBtn.classList.toggle('on', on);
    });
    document.addEventListener('click', function(e) {
        if (!emojiPanel.classList.contains('open')) return;
        if (e.target.closest && (e.target.closest('#ai-emoji-panel') || e.target.closest('#ai-emoji-btn'))) return;
        emojiPanel.classList.remove('open');
        emojiBtn.classList.remove('on');
    });

    // ===== ریست مکالمه =====
    resetBtn.addEventListener('click', function() {
        if (!confirm('مکالمه جدید شروع شود؟')) return;
        clearChatState();
        var oldToken = convToken;
        convToken   = '';
        totalTokens = 0;
        chatHistory = [];
        var fd = new FormData();
        fd.append('action', 'reset');
        if (oldToken) fd.append('_chat_token', oldToken);
        fetch(API_URL + '?key=' + encodeURIComponent(WIDGET_KEY), { method: 'POST', body: fd })
            .then(function() {
                messages.innerHTML = '';
                addMessage('bot', WELCOME_MSG, 0);
                creditBar.textContent = '';
            }).catch(function() {
                messages.innerHTML = '';
                addMessage('bot', WELCOME_MSG, 0);
            });
    });

    // ===== ارسال پیام =====
    function sendMessage() {
        var text = input.value.trim();
        if (!text || isLoading) return;

        input.value = '';
        input.style.height = 'auto';
        emojiPanel.classList.remove('open');
        emojiBtn.classList.remove('on');
        addMessage('user', text, 0);
        isLoading = true;
        sendBtn.disabled = true;
        showTyping();

        var formData = new FormData();
        formData.append('message', text);
        formData.append('action', 'send');
        if (convToken) formData.append('_chat_token', convToken);

        fetch(API_URL + '?key=' + encodeURIComponent(WIDGET_KEY), {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideTyping();
            if (data.ok) {
                var msgTokens = data.tokens || 0;
                addMessage('bot', data.reply, msgTokens, data.images || {});

                if (data.conv_token) convToken = data.conv_token;
                if (window.__aiLeadAttach) window.__aiLeadAttach();
                totalTokens = data.total_tokens || (totalTokens + msgTokens);
                updateCreditBar(data.credit);
                saveChatState();
            } else {
                // فقط پیام‌های کوتاه و فارسی سرور نمایش داده می‌شوند؛ هر پیام فنی/انگلیسی با پیام ثابت جایگزین می‌شود
                var em = String(data.error || '');
                if (!em || /[A-Za-z]{3,}/.test(em) || em.length > 160) em = NET_DOWN_MSG;
                addMessage('bot', em, 0);
            }
        })
        .catch(function() {
            hideTyping();
            addMessage('bot', NET_DOWN_MSG, 0);
        })
        .finally(function() {
            isLoading = false;
            sendBtn.disabled = false;
            input.focus();
        });
    }

    sendBtn.addEventListener('click', sendMessage);

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Auto-resize textarea
    input.addEventListener('input', function() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 80) + 'px';
    });

    // ===== فرم لید (اطلاعات تماس) =====
    // پس از یک بار ارسال (یا بستن)، تا ۳ روز دوباره پرسیده نمی‌شود.
    // اطلاعات ذخیره‌شده به مکالمه‌های جدید همین بازدیدکننده هم خودکار پیوست می‌شود.
    var LEAD_KEY  = 'ai_lead_' + WIDGET_KEY;
    var LEAD_DAYS = 3;
    function getSavedLead() {
        try {
            var v = JSON.parse(localStorage.getItem(LEAD_KEY) || 'null');
            if (v && v.t && Date.now() - v.t < LEAD_DAYS * 86400000) return v;
            localStorage.removeItem(LEAD_KEY);
        } catch (e) {}
        return null;
    }
    function saveLead(name, phone) {
        try { localStorage.setItem(LEAD_KEY, JSON.stringify({ t: Date.now(), name: name || '', phone: phone || '', conv: convToken || '' })); } catch (e) {}
    }
    function removeLeadForm() { if (leadForm && leadForm.parentNode) leadForm.parentNode.removeChild(leadForm); }

    // اگر در ۳ روز گذشته اطلاعات گرفته شده، فرم نمایش داده نمی‌شود
    if (leadForm && getSavedLead()) removeLeadForm();

    // پیوست خودکار اطلاعات ذخیره‌شده به مکالمه جدید (بعد از اولین پاسخ)
    window.__aiLeadAttach = function() {
        var sl = getSavedLead();
        if (!sl || (!sl.name && !sl.phone) || !convToken || sl.conv === convToken) return;
        var fd = new FormData();
        fd.append('action', 'lead');
        fd.append('name', sl.name);
        fd.append('phone', sl.phone);
        fd.append('_chat_token', convToken);
        fetch(API_URL + '?key=' + encodeURIComponent(WIDGET_KEY), { method: 'POST', body: fd })
            .then(function() {
                try { sl.conv = convToken; localStorage.setItem(LEAD_KEY, JSON.stringify(sl)); } catch (e) {}
            }).catch(function() {});
    };

    var leadX = document.getElementById('ai-lead-x');
    if (leadX) leadX.addEventListener('click', function() { saveLead('', ''); removeLeadForm(); });

    if (leadSubmit) {
        leadSubmit.addEventListener('click', function() {
            var name  = (document.getElementById('ai-lead-name') || {}).value || '';
            var phone = (document.getElementById('ai-lead-phone') || {}).value || '';
            name  = name.trim();
            phone = phone.trim();
            if (!name && !phone) {
                saveLead('', '');
                removeLeadForm();
                return;
            }
            var fd = new FormData();
            fd.append('action', 'lead');
            fd.append('name', name);
            fd.append('phone', phone);
            if (convToken) fd.append('_chat_token', convToken);
            fetch(API_URL + '?key=' + encodeURIComponent(WIDGET_KEY), { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    removeLeadForm();
                    if (data && data.conv_token) convToken = data.conv_token;
                    saveLead(name, phone);
                    if (name) addMessage('bot', name + ' عزیز، ممنون از شما 🌹 چطور می‌تونم کمکتون کنم؟', 0);
                    saveChatState();
                })
                .catch(function() { saveLead(name, phone); removeLeadForm(); });
        });
    }

    // ===== تماس با کارشناس =====
    (function(){
        var box = document.getElementById('ai-contact'); if (!box) return;
        function openC(){
            var sl = getSavedLead();
            if (sl) { if (sl.name && !document.getElementById('ai-c-name').value) document.getElementById('ai-c-name').value = sl.name; if (sl.phone && !document.getElementById('ai-c-phone').value) document.getElementById('ai-c-phone').value = sl.phone; }
            box.classList.add('open'); document.getElementById('ai-c-res').textContent = '';
            (document.getElementById('ai-c-name').value ? document.getElementById('ai-c-msg') : document.getElementById('ai-c-name')).focus();
        }
        document.getElementById('ai-c-open').addEventListener('click', openC);
        var hb = document.getElementById('ai-contact-btn'); if (hb) hb.addEventListener('click', openC);
        document.getElementById('ai-c-x').addEventListener('click', function(){ box.classList.remove('open'); });
        var go = document.getElementById('ai-c-go'), res = document.getElementById('ai-c-res');
        go.addEventListener('click', function(){
            var n = document.getElementById('ai-c-name').value.trim(), ph = document.getElementById('ai-c-phone').value.trim(), m = document.getElementById('ai-c-msg').value.trim();
            if (n.length < 2 || !ph || m.length < 3) { res.style.color = '#b91c1c'; res.textContent = 'نام، شماره موبایل و پیام را کامل بنویسید.'; return; }
            go.disabled = true; res.style.color = '#64748b'; res.textContent = '⏳ در حال ارسال…';
            var fd = new FormData(); fd.append('action', 'contact'); fd.append('name', n); fd.append('phone', toEnDigits(ph)); fd.append('message', m); fd.append('page', location.href.slice(0, 480));
            if (convToken) fd.append('_chat_token', convToken);
            fetch(API_URL + '?key=' + encodeURIComponent(WIDGET_KEY), { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    go.disabled = false;
                    if (d && d.ok) { res.style.color = '#047857'; res.textContent = d.message || 'پیام شما ثبت شد ✅'; document.getElementById('ai-c-msg').value = ''; saveLead(n, ph); removeLeadForm(); }
                    else { res.style.color = '#b91c1c'; res.textContent = (d && d.error) || 'ارسال انجام نشد؛ دوباره تلاش کنید.'; }
                })
                .catch(function(){ go.disabled = false; res.style.color = '#b91c1c'; res.textContent = 'ارتباط برقرار نشد؛ دوباره تلاش کنید.'; });
        });
    })();

})();
<?php
// پاپ‌آپ‌های کاربر برای بازدیدکنندگان سایت (includes/extras_lib.php)
if (function_exists('ex_popups_for') && saas_plan_allows($plan, 'has_popups')) {
    try {
        $ex_pp = ex_popups_for($pdo, 'widget', ['owner_id' => $user_id]);
        if ($ex_pp) {
            echo "\n(function(P,TU){function run(){" . ex_popup_js_core() . "}if(document.body)run();else document.addEventListener('DOMContentLoaded',run);})("
               . json_encode(array_values($ex_pp), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ','
               . json_encode(rtrim(AICHAT_BASE_URL, '/') . '/pp.php', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ");\n";
        }
    } catch (\Throwable $e) {}
}
