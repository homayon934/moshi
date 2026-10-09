<?php
/**
 * API چندکاربره - AI Chat Widget SaaS
 * نقطه اتصال ویجت به سرویس‌های هوش مصنوعی
 *
 * استفاده:
 *   widget.php?key=SITE_KEY را در صفحه جاسازی کنید
 *   تمام درخواست‌ها باید key=SITE_KEY داشته باشند
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/includes/sec_lib.php';
// امنیت: فیلتر حمله‌ها و سقف درخواست هر IP (بدون نشست و بدون هدر قاب، چون ویجت روی سایت‌های دیگر است)
if (!defined('SEC_BOT_GUARD_OFF') && (sec_bad_agent() !== '' && sec_bad_agent() !== 'empty' || sec_waf_bad_uri() !== '')) { http_response_code(403); echo '{"ok":false,"error":"forbidden"}'; exit; }
if (sec_rate('widget_api', 240, 60)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'تعداد درخواست‌ها زیاد است؛ کمی صبر کنید.'], JSON_UNESCAPED_UNICODE); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/saas_lib.php';
require_once __DIR__ . '/includes/ai_chat_lib.php';

function api_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- بارگذاری PDO ---
$pdo = aichat_connect();
try { saas_ensure_schema($pdo); } catch (\Throwable $e) {}

// --- دریافت site_key ---
$site_key = trim($_REQUEST['key'] ?? '');
if (!$site_key) api_error('کلید سایت (key) ارسال نشده است.');

// --- بارگذاری کاربر ---
$user = saas_get_user_by_site_key($pdo, $site_key);
if (!$user) api_error('کلید سایت نامعتبر است.', 403);
if ($user['status'] !== 'active') api_error('حساب کاربری غیرفعال یا تعلیق شده است.', 403);
// اشتراک منقضی (پس از ۷ روز مهلت): سرویس متوقف — بدون ذکر علت به بازدیدکننده
if (function_exists('biz_service_blocked') && biz_service_blocked($pdo, $user)) {
    api_error('در حال حاضر امکان پاسخ‌گویی وجود ندارد. لطفاً بعداً تلاش کنید.', 403);
}
// کارهای زمان‌بندی‌شده (یادآوری‌ها) — حداکثر ساعتی یک بار، بعد از ارسال پاسخ
if (function_exists('biz_maybe_run_cron')) biz_maybe_run_cron($pdo);
if (function_exists('rem_maybe_run')) rem_maybe_run($pdo);

$user_id = (int)$user['id'];

// --- تنظیمات ویجت کاربر ---
$ws = saas_get_widget_settings($pdo, $user_id);
if (empty($ws['enabled'])) api_error('ویجت این کاربر فعال نیست.', 403);

// --- تنظیمات مرکزی API ---
$cfg = saas_get_api_config($pdo);

// --- Action ---
$action = $_REQUEST['action'] ?? 'send';

// =====================================================================
// Action: history — تاریخچه مکالمه جاری
// =====================================================================
if ($action === 'history') {
    $conv = saas_resolve_conversation($pdo, $user_id, $_SERVER['REMOTE_ADDR'] ?? '', false);
    if (!$conv) {
        echo json_encode(['ok' => true, 'messages' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msgs_stmt = $pdo->prepare("SELECT role, message as content FROM saas_messages WHERE conversation_id=? ORDER BY id ASC LIMIT 50");
    $msgs_stmt->execute([$conv['id']]);
    echo json_encode(['ok' => true, 'messages' => $msgs_stmt->fetchAll(), 'conv_token' => $conv['token']], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// Action: lead — ذخیره اطلاعات تماس
// =====================================================================
if ($action === 'lead') {
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if (!$name && !$phone) api_error('اطلاعاتی ارسال نشده است.');
    if (sec_rate('widget_lead', 10, 3600)) api_error('تعداد ارسال‌ها زیاد است؛ بعداً تلاش کنید.', 429);
    $phone = preg_replace('/[^0-9+]/', '', strtr($phone, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    $name = trim(strip_tags($name));
    $conv = saas_resolve_conversation($pdo, $user_id, $_SERVER['REMOTE_ADDR'] ?? '', true);
    $name  = mb_substr($name, 0, 100);
    $phone = mb_substr($phone, 0, 30);
    $pdo->prepare("UPDATE saas_conversations SET visitor_name=COALESCE(?, visitor_name), visitor_phone=COALESCE(?, visitor_phone) WHERE id=?")
        ->execute([$name !== '' ? $name : null, $phone !== '' ? $phone : null, $conv['id']]);
    echo json_encode(['ok' => true, 'conv_token' => $conv['token']], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// Action: contact — «تماس با کارشناس» (نام، موبایل و پیام بازدیدکننده برای کاربر)
// =====================================================================
if ($action === 'contact') {
    if (!function_exists('cx_contact_save')) api_error('این امکان فعال نیست.');
    $wsx = saas_get_widget_settings($pdo, $user_id);
    if (isset($wsx['contact_on']) && empty($wsx['contact_on'])) api_error('این امکان فعال نیست.');
    $conv = saas_resolve_conversation($pdo, $user_id, $_SERVER['REMOTE_ADDR'] ?? '', false);
    $r = cx_contact_save($pdo, $user_id, ['name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? '', 'message' => $_POST['message'] ?? '', 'page' => $_POST['page'] ?? ''], $conv ? (int)$conv['id'] : 0, $_SERVER['REMOTE_ADDR'] ?? '');
    if (!$r['ok']) api_error($r['error']);
    echo json_encode(['ok' => true, 'message' => 'پیام شما ثبت شد ✅ کارشناسان ما به‌زودی با شما تماس می‌گیرند.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// Action: reset — ریست مکالمه
// =====================================================================
if ($action === 'reset') {
    $conv = saas_resolve_conversation($pdo, $user_id, $_SERVER['REMOTE_ADDR'] ?? '', false);
    if ($conv) {
        $pdo->prepare("UPDATE saas_conversations SET ended_at=NOW() WHERE id=?")->execute([$conv['id']]);
    }
    setcookie('aichat_token_' . $user_id, '', time() - 3600, '/');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// Action: send — ارسال پیام
// =====================================================================
if ($action !== 'send') api_error('عمل نامعتبر است.');

$message = trim($_POST['message'] ?? $_GET['message'] ?? '');
if ($message === '') api_error('پیام خالی است.');
if (mb_strlen($message) > 2000) api_error('پیام بیش از حد طولانی است.');

@set_time_limit(90);

// --- Rate limiting IP ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (saas_rate_limited($pdo, $user_id, $ip, 20, 5)) {
    api_error('تعداد درخواست‌ها در کوتاه‌مدت زیاد است. لطفاً چند دقیقه صبر کنید.', 429);
}

// --- بارگذاری پلن ---
$plan = $user['plan_id'] ? (saas_get_plan($pdo, (int)$user['plan_id']) ?: null) : null;
$max_daily = $plan ? (int)$plan['max_requests_per_day'] : 50;
$max_tokens_per_req = $plan ? (int)$plan['max_tokens_per_request'] : 500;
$response_length = $plan ? ($plan['response_length'] ?? 'medium') : 'medium';

// --- مکالمه جاری ---
$conv = saas_resolve_conversation($pdo, $user_id, $ip, true);
$conv_id = (int)$conv['id'];

// --- تاریخچه پیام‌ها (۱۶ پیام آخر = ۸ تبادل) ---
$hist_stmt = $pdo->prepare("SELECT role, message as content FROM saas_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 16");
$hist_stmt->execute([$conv_id]);
$history = array_reverse($hist_stmt->fetchAll() ?: []);

// --- تشخیص نام کاربر از متن پیام (مثلاً «اسمم علی است» / «من مریم هستم») ---
if (empty($conv['visitor_name'])) {
    $detected = '';
    if (preg_match('/(?:اسم\s*(?:من|م)|نام\s*(?:من|م))\s*(?:هم)?\s*[:：]?\s*([\p{Arabic}]{2,20})/u', $message, $mm)) {
        $detected = $mm[1];
    } elseif (preg_match('/^\s*(?:سلام[،,!\s]*)?من\s+([\p{Arabic}]{2,20})\s+(?:هستم|ام)\b/u', $message, $mm)) {
        $detected = $mm[1];
    }
    $bad = ['هست','است','چیه','چی','رو','را','هستم','که','مشتری','کاربر','یک','یه','دنبال'];
    if ($detected !== '' && !in_array($detected, $bad, true)) {
        $pdo->prepare("UPDATE saas_conversations SET visitor_name=? WHERE id=? AND (visitor_name IS NULL OR visitor_name='')")
            ->execute([$detected, $conv_id]);
        $conv['visitor_name'] = $detected;
    }
}

// =====================================================================
// آرشیو پاسخ‌ها: اگر همین سوال قبلاً پاسخ داده شده، بدون مصرف توکن پاسخ بده
// =====================================================================
$archive_allowed = saas_plan_allows($plan, 'has_answer_archive');
if (!empty($ws['cache_enabled']) && $archive_allowed) {
    $cached = null;
    try { $cached = saas_cache_lookup($pdo, $user_id, $message, $ws['cache_days'] ?? 7); } catch (\Throwable $e) { $cached = null; }
    if ($cached) {
        $reply = saas_cache_render($cached['answer'], $conv['visitor_name'] ?? '');
        $ins = $pdo->prepare("INSERT INTO saas_messages (conversation_id, user_id, role, message, tokens_used, from_cache, created_at) VALUES(?,?,?,?,0,?,NOW())");
        $ins->execute([$conv_id, $user_id, 'user', $message, 0]);
        $ins->execute([$conv_id, $user_id, 'assistant', $reply, 1]);
        try {
            $pdo->prepare("UPDATE saas_conversations SET message_count = message_count + 2, last_message_at = NOW() WHERE id=?")->execute([$conv_id]);
        } catch (\Throwable $e) {}
        echo json_encode([
            'ok'         => true,
            'reply'      => $reply,
            'tokens'     => 0,
            'cached'     => true,
            'conv_token' => $conv['token'],
            'images'     => (object)$cached['images'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// --- بررسی اعتبار ---
if ((int)$user['credit_tokens'] <= 0) {
    api_error('در حال حاضر امکان پاسخ‌گویی وجود ندارد. لطفاً بعداً تلاش کنید یا با پشتیبانی تماس بگیرید.');
}

// --- بررسی محدودیت روزانه ---
if (saas_check_daily_limit($pdo, $user_id, $max_daily)) {
    api_error("به محدودیت روزانه ({$max_daily} درخواست) رسیده‌اید. فردا دوباره تلاش کنید.");
}

// --- متن جستجو: پیام فعلی + دو پیام قبلی کاربر (برای فهم سوال‌های ادامه‌دار) ---
$search_text = $message;
$prev_user = array_values(array_filter($history, fn($h) => $h['role'] === 'user'));
foreach (array_slice(array_reverse($prev_user), 0, 2) as $pu) {
    $search_text .= ' ' . $pu['content'];
}

// --- ساخت پرامپت سیستمی ---
$system_prompt = aichat_build_system_prompt_saas($pdo, $user_id, $user, $ws, $plan, $message, $conv, $search_text);
$link_images   = $GLOBALS['aichat_link_images'] ?? [];
// لینک‌های پایگاه دانش (عنوان، توضیح، آدرس) — includes/extras_lib.php
if (function_exists('ex_links_block')) {
    try { $lb = ex_links_block($pdo, $user_id, 0, $search_text !== '' ? $search_text : $message); if ($lb !== '') $system_prompt = trim((string)$system_prompt . "\n\n" . $lb); } catch (\Throwable $e) {}
}

// --- ساخت پیام‌ها ---
$ai_messages = [];
if ($system_prompt) $ai_messages[] = ['role' => 'system', 'content' => $system_prompt];
foreach ($history as $h) {
    $ai_messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$ai_messages[] = ['role' => 'user', 'content' => $message];

// --- تعیین max_tokens بر اساس طول پاسخ ---
$length_map = ['short' => 300, 'medium' => 600, 'long' => 1200];
$max_out_tokens = min($max_tokens_per_req, $length_map[$response_length] ?? 600);
// سقف توکن ورودی/خروجی (پلن مدیر + تنظیم کاربر؛ مقدار کمتر اعمال می‌شود)
if (function_exists('ex_widget_limits')) {
    [$lim_in, $lim_out] = ex_widget_limits($plan ?: [], $ws);
    if ($lim_out > 0) $max_out_tokens = max(64, min($max_out_tokens, $lim_out));
    if ($lim_in > 0) $ai_messages = ex_fit_input($ai_messages, $lim_in);
}

// --- مدل هوش مصنوعی (انتخاب کاربر از بین مدل‌های مجاز پلن) ---
$ai_model = function_exists('biz_resolve_model') ? biz_resolve_model($pdo, $plan, (int)($ws['ai_model_id'] ?? 0), 'widget') : null;

// --- فراخوانی AI (با جایگزینی خودکار مدل در صورت در دسترس نبودن) ---
// دمای پاسخ بر اساس «پایبندی به منابع» (پایبندی بیشتر = پاسخ دقیق‌تر و کم‌خلاقیت‌تر)
$lv = $GLOBALS['aichat_widget_levels'] ?? ['grounding' => 80];
$temperature = round(0.15 + (100 - (int)$lv['grounding']) / 100 * 0.55, 2);
$ai_response = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $ai_model, $ai_messages, $max_out_tokens, $temperature) : aichat_call_ai($cfg, $ai_messages, $max_out_tokens, $temperature);
$ai_model = $ai_response['model_row'] ?? $ai_model;   // مدلی که واقعاً پاسخ داد

if (!$ai_response['ok']) {
    // هرگز پیام فنی سرویس (کلید، شناسه درخواست و ...) به بازدیدکننده نمایش داده نمی‌شود
    api_error(AICHAT_AI_DOWN_MSG, 503);
}

$reply       = $ai_response['content'];
// هزینه (تومان) = توکن ورودی/خروجی × قیمت نهایی مدل (قیمت مرجع دلاری × نرخ دلار × درصد سود)
$tokens_used = function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $ai_model, $ai_response) : (int)($ai_response['tokens'] ?? 0);
$tokens_used += (int)($GLOBALS['aichat_extra_tokens'] ?? 0);   // انتخاب هوشمند موضوع پایگاه دانش

// --- ذخیره پیام‌ها در دیتابیس ---
$ins = $pdo->prepare("INSERT INTO saas_messages (conversation_id, user_id, role, message, tokens_used, created_at) VALUES(?,?,?,?,?,NOW())");
$ins->execute([$conv_id, $user_id, 'user', $message, 0]);
$ins->execute([$conv_id, $user_id, 'assistant', $reply, $tokens_used]);
try {
    $pdo->prepare("UPDATE saas_conversations SET message_count = message_count + 2, last_message_at = NOW() WHERE id=?")->execute([$conv_id]);
} catch (\Throwable $e) {}

// --- تصاویر محصولاتی که لینکشان در پاسخ آمده (برای نمایش تصویر کوچک در ویجت) ---
$reply_images = [];
foreach ($link_images as $lurl => $limg) {
    if ($lurl !== '' && $limg !== '' && strpos($reply, $lurl) !== false) {
        $reply_images[$lurl] = $limg;
    }
}

// --- ذخیره در آرشیو پاسخ‌ها (برای سوال‌های تکراری بعدی) ---
if (!empty($ws['cache_enabled']) && $archive_allowed) {
    // آرشیو خودکار: سوالی که (با مشابه‌هایش) حداقل ۵ بار پرسیده شده ذخیره می‌شود
    try {
        if (function_exists('kb_question_hit')) kb_question_hit($pdo, $user_id, $message, $reply, $reply_images, $conv['visitor_name'] ?? '');
        else saas_cache_store($pdo, $user_id, $message, $reply, $reply_images, $conv['visitor_name'] ?? '');
    } catch (\Throwable $e) {}
}

// --- کسر اعتبار ---
if ($tokens_used > 0) {
    saas_deduct_credit($pdo, $user_id, $tokens_used, 'مکالمه ویجت');
}

// --- اعتبار باقیمانده ---
$rem_stmt = $pdo->prepare("SELECT credit_tokens FROM saas_users WHERE id=?");
$rem_stmt->execute([$user_id]);
$remaining = (int)($rem_stmt->fetchColumn() ?? 0);

// --- مجموع توکن کل مکالمه ---
$total_stmt = $pdo->prepare("SELECT COALESCE(SUM(tokens_used), 0) FROM saas_messages WHERE conversation_id=?");
$total_stmt->execute([$conv_id]);
$total_conv_tokens = (int)$total_stmt->fetchColumn();

echo json_encode([
    'ok'           => true,
    'reply'        => $reply,
    'tokens'       => $tokens_used,
    'total_tokens' => $total_conv_tokens,
    'credit'       => $remaining > 0 ? 1 : 0,   // فقط وضعیت (نه مانده اعتبار صاحب سایت)
    'conv_token'   => $conv['token'],
    'images'       => (object)$reply_images,
], JSON_UNESCAPED_UNICODE);
