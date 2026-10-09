<?php
/**
 * چت‌بات صفحه اصلی سایت در «حالت کامل» (نسخه ۴۳)
 *
 * چت صفحه اصلی با همه امکانات چت‌بات‌های تخصصی کار می‌کند (ورود با موبایل/گوگل، پیام رایگان، سقف روزانه،
 * بسته‌ها و خرید با درگاه سایت، انتخاب مدل، ساخت تصویر و ویدیو و اقدام‌های آماده، فایل و صوت، حافظه بلندمدت، یادآور).
 *
 *  - یک «حساب سیستمی» پنهان (saas_users) صاحب این چت‌بات است؛ پلن ندارد (همه امکانات مجاز)، اعتبارش نامحدود است
 *    و هزینه‌ها فقط برای گزارش ثبت می‌شود. در فهرست کاربران مدیریت نمایش داده نمی‌شود.
 *  - مدیر کل از «تنظیمات سایت ← دستیار گفتگوی صفحه اصلی ← مدیریت چت‌بات سایت» وارد صفحه مدیریت همین چت‌بات
 *    (همان صفحه مدیریت چت‌بات‌های تخصصی، بدون بخش‌های مربوط به همکاران/مشاوران) می‌شود.
 *  - بازدیدکننده بدون ورود چند پیام «مهمان» دارد (sitechat_guest)؛ بعد از ورود، گفتگوی مهمان به گفتگوهای او منتقل می‌شود.
 *  - کاربرانی که در پنل کاربری سایت وارد هستند، خودکار (با همان شماره موبایل) وارد چت می‌شوند.
 *
 * شناسه‌ها در saas_biz_settings: site_bot_id، site_bot_uid
 */

const SB_EMAIL = 'site-assistant@system.invalid';
const SB_CREDIT = 2000000000;   // اعتبار «نامحدود» حساب سیستمی (هر بار کمتر از یک دهم شد، دوباره پر می‌شود)

function sb_setting($pdo, $k)
{
    if (!function_exists('biz_settings')) return 0;
    return (int)(biz_settings($pdo)[$k] ?? 0);
}

/** شناسه حساب سیستمی صاحب چت‌بات سایت (۰ = هنوز ساخته نشده) */
function sb_uid($pdo) { return sb_setting($pdo, 'site_bot_uid'); }
function sb_bot_id($pdo) { return sb_setting($pdo, 'site_bot_id'); }

function sb_is_uid($pdo, $uid) { $s = sb_uid($pdo); return $s > 0 && (int)$uid === $s; }
function sb_is_bot($pdo, $bot) { $s = sb_bot_id($pdo); return $s > 0 && is_array($bot) && (int)($bot['id'] ?? 0) === $s; }

/** چت‌بات سایت (بدون ساختن)؛ درگاه پرداخت سایت اگر برای ربات جدا تنظیم نشده باشد */
function sb_bot($pdo)
{
    $id = sb_bot_id($pdo);
    if ($id <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT * FROM hd_bots WHERE id=? AND user_id=?");
        $st->execute([$id, sb_uid($pdo)]);
        $bot = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
    if (!$bot) return null;
    if (trim((string)($bot['zp_merchant'] ?? '')) === '') {
        $cfg = saas_get_api_config($pdo);
        $bot['zp_merchant'] = trim((string)($cfg['zarinpal_merchant_id'] ?? ''));
        $bot['zp_sandbox'] = (int)($cfg['zarinpal_sandbox'] ?? 0);
    }
    sb_topup($pdo);
    return $bot;
}

/** پر نگه داشتن اعتبار و پیامک حساب سیستمی (هزینه‌ها فقط برای گزارش ثبت می‌شوند) */
function sb_topup($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $uid = sb_uid($pdo);
    if ($uid <= 0) return;
    try { $pdo->prepare("UPDATE saas_users SET credit_tokens=? WHERE id=? AND credit_tokens < ?")->execute([SB_CREDIT, $uid, (int)(SB_CREDIT / 10)]); } catch (\Throwable $e) {}
    try { $pdo->prepare("UPDATE saas_users SET sms_balance=1000000000 WHERE id=? AND sms_balance < 100000000")->execute([$uid]); } catch (\Throwable $e) {}
}

/**
 * ساخت حساب سیستمی و چت‌بات سایت (اگر نباشند) — فقط از پنل مدیریت
 * @return array|null چت‌بات
 */
function sb_ensure($pdo)
{
    if (function_exists('hd_ensure_schema')) hd_ensure_schema($pdo);
    $uid = sb_uid($pdo);
    $u = $uid > 0 ? saas_get_user($pdo, $uid) : null;
    if (!$u) {
        $q = $pdo->prepare("SELECT id FROM saas_users WHERE email=?");
        $q->execute([SB_EMAIL]);
        $uid = (int)$q->fetchColumn();
        if (!$uid) {
            $pdo->prepare("INSERT INTO saas_users (email, password_hash, full_name, phone, site_key, plan_id, credit_tokens, status, created_at) VALUES(?,?,?,?,?,NULL,?,'active',?)")
                ->execute([SB_EMAIL, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'چت صفحه اصلی سایت', '', bin2hex(random_bytes(16)), SB_CREDIT, date('Y-m-d H:i:s')]);
            $uid = (int)$pdo->lastInsertId();
        }
        biz_set($pdo, 'site_bot_uid', $uid);
    }
    // حساب سیستمی همیشه فعال و بدون پلن (= همه امکانات)
    $pdo->prepare("UPDATE saas_users SET status='active', plan_id=NULL WHERE id=?")->execute([$uid]);
    $bot = sb_bot($pdo);
    if (!$bot) {
        $s = function_exists('site_settings') ? site_settings($pdo) : [];
        $c = function_exists('sch_conf') ? sch_conf($s) : ['title' => 'دستیار هوشمند', 'welcome' => 'سلام 👋'];
        $id = hd_create_bot($pdo, $uid, $c['title'], 'custom');
        $cfg = saas_get_api_config($pdo);
        $pdo->prepare("UPDATE hd_bots SET specialty=?, welcome_message=?, instructions='', disclaimer='', intake_questions='', free_messages=10, daily_limit=0, grounding=30, verify_mobile=1, zp_merchant=?, zp_sandbox=? WHERE id=?")
            ->execute(['دستیار هوشمند سایت', $c['welcome'], trim((string)($cfg['zarinpal_merchant_id'] ?? '')), (int)($cfg['zarinpal_sandbox'] ?? 0), $id]);
        biz_set($pdo, 'site_bot_id', $id);
        $bot = sb_bot($pdo);
    }
    sb_topup($pdo);
    return $bot;
}

/** بخش پرامپت چت‌بات سایت: نقش دستیار سایت + اطلاعات خدمات، پلن‌ها و دانش اضافه */
function sb_prompt_block($pdo, $question = '')
{
    try {
        require_once __DIR__ . '/site_lib.php';
        require_once __DIR__ . '/sitechat_lib.php';
        $s = site_settings($pdo);
        $c = sch_conf($s);
        $name = trim((string)($s['site_name'] ?? '')) ?: 'سایت';
        $p = "\n\n=== نقش تو در این سایت (مهم‌تر از روش گفتگوی بالا) ===\n"
           . "تو «{$c['title']}» سایت «{$name}» هستی؛ دستیار عمومی سایت، نه مشاور یک مراجع. برای پاسخ دادن لازم نیست اول اطلاعات شخصی کاربر را بپرسی.\n"
           . "- درباره خدمات، امکانات، پلن‌ها، قیمت‌ها، ثبت‌نام و تماس فقط از «اطلاعات سایت» زیر استفاده کن؛ عدد یا امکانی را که آنجا نیست حدس نزن.\n"
           . "- وقتی مناسب است، پلن یا امکان مرتبط را پیشنهاد بده و لینک همان صفحه را بیاور.\n"
           . ($c['general'] ? "- به سؤال‌های عمومی (هر موضوعی) هم مفید و درست پاسخ بده؛ برای پاسخ‌های عمومی نیازی به ذکر «منبع» یا گفتن «از منابع نیست» نداری.\n"
                            : "- فقط درباره سایت، خدمات و موضوعات مرتبط پاسخ بده؛ برای موضوعات نامرتبط مؤدبانه بگو فقط درباره خدمات سایت کمک می‌کنی.\n")
           . "- نام شرکت یا مدل هوش مصنوعی پشت سرویس را نگو؛ تو دستیار هوشمند همین سایت هستی.\n";
        if ($c['know'] !== '') $p .= "\n=== دانش اضافه از مدیر سایت (معتبر؛ اولویت بالا) ===\n" . mb_substr($c['know'], 0, 12000) . "\n=== پایان ===\n";
        $p .= "\n=== اطلاعات سایت ===\n" . sch_site_context($pdo, $s, $question) . "\n=== پایان اطلاعات سایت ===";
        return $p;
    } catch (\Throwable $e) {
        error_log('[SB] prompt: ' . $e->getMessage());
        return '';
    }
}

/** شماره موبایل کاربر وارد شده به پنل کاربری (برای ورود خودکار به چت سایت) */
function sb_session_user_mobile($pdo, array $sess)
{
    $uid = (int)($sess['saas_user_id'] ?? 0);
    if ($uid <= 0 || !empty($sess['saas_admin_imp']) || !empty($sess['saas_team_id']) || sb_is_uid($pdo, $uid)) return null;
    $u = saas_get_user($pdo, $uid);
    if (!$u || ($u['status'] ?? '') !== 'active') return null;
    $mob = function_exists('hd_normalize_mobile') ? hd_normalize_mobile((string)($u['phone'] ?? '')) : '';
    return $mob !== '' ? ['mobile' => $mob, 'name' => (string)$u['full_name']] : null;
}

/** ورود خودکار کاربر سایت به چت‌بات سایت: مخاطب با همان موبایل (در صورت نبود ساخته می‌شود) + توکن تازه */
function sb_sso_member($pdo, array $bot, $mobile, $name)
{
    $m = hd_member_by_mobile($pdo, (int)$bot['id'], $mobile);
    if (!$m) {
        $mid = hd_create_member($pdo, (int)$bot['id'], $mobile, mb_substr(trim((string)$name) ?: 'کاربر سایت', 0, 100), true);
        $st = $pdo->prepare("SELECT * FROM hd_members WHERE id=?");
        $st->execute([$mid]);
        $m = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    if (!$m || ($m['status'] ?? 'active') !== 'active') return null;
    return ['member' => $m, 'token' => hd_issue_token($pdo, (int)$m['id'])];
}

/** انتقال گفتگوی مهمان (پیش از ورود) به یک گفتگوی تازه برای مخاطب */
function sb_import_guest($pdo, array $bot, array $member, array $hist)
{
    $hist = array_values(array_filter($hist, fn($h) => in_array($h['role'] ?? '', ['user', 'assistant'], true) && trim((string)($h['content'] ?? '')) !== ''));
    if (!$hist) return 0;
    $first = '';
    foreach ($hist as $h) if ($h['role'] === 'user') { $first = (string)$h['content']; break; }
    $now = function_exists('hd_now') ? hd_now() : date('Y-m-d H:i:s');
    try {
        $pdo->prepare("INSERT INTO hd_threads (bot_id, member_id, title, created_at, updated_at) VALUES(?,?,?,?,?)")
            ->execute([(int)$bot['id'], (int)$member['id'], mb_substr(preg_replace('/\s+/u', ' ', $first ?: 'گفتگو'), 0, 60), $now, $now]);
        $tid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO hd_messages (thread_id, bot_id, member_id, role, content, sources, tokens, created_at) VALUES(?,?,?,?,?,?,?,?)");
        foreach (array_slice($hist, -40) as $h) $ins->execute([$tid, (int)$bot['id'], (int)$member['id'], $h['role'], mb_substr((string)$h['content'], 0, 6000), null, 0, $now]);
        return $tid;
    } catch (\Throwable $e) { error_log('[SB] import: ' . $e->getMessage()); return 0; }
}
