<?php
/**
 * ارتباطات و ورود:
 *  - الگوهای پیامک قابل تعریف در پنل مدیر (متن آزاد یا الگوی اعتبارسنجی کاوه‌نگار)، ارسال مرکزی و گزارش ارسال
 *  - کد یک‌بارمصرف (OTP) عمومی برای ورود، ثبت‌نام و بازیابی رمز
 *  - روش‌های ورود (پیامک، گوگل، رمز ثابت) برای هر بخش با امکان فعال/غیرفعال کردن و اولویت‌بندی
 *  - ارسال ایمیل (SMTP با TLS/SSL یا mail سرور) با گزارش دقیق خطا
 *  - توضیح خطاهای درگاه زرین‌پال و آزمایش درگاه
 * ساختار دیتابیس با روش سازگار با MySQL (بدون IF NOT EXISTS در ALTER).
 */
if (defined('COMM_LIB_LOADED')) return;
define('COMM_LIB_LOADED', 1);
define('COMM_SCHEMA_VERSION', 1);

function comm_now($off = 0) { return date('Y-m-d H:i:s', time() + (int)$off); }
function comm_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =============================================================================
// ساختار دیتابیس
// =============================================================================
function comm_ensure_schema($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.comm_schema_v' . COMM_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    $q = function ($sql) use ($pdo) { try { $pdo->exec($sql); } catch (\Throwable $e) { error_log('[COMM] schema: ' . $e->getMessage()); } };
    $col = function ($t, $c, $def) use ($pdo) {
        try {
            if (function_exists('saas_add_column_if_missing')) saas_add_column_if_missing($pdo, $t, $c, $def);
            else $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            return true;
        } catch (\Throwable $e) { error_log('[COMM] column ' . $t . '.' . $c . ': ' . $e->getMessage()); return false; }
    };
    $q("CREATE TABLE IF NOT EXISTS saas_otps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(12) NOT NULL DEFAULT 'user',
        purpose VARCHAR(12) NOT NULL DEFAULT 'login',
        mobile VARCHAR(20) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_m (scope, mobile, purpose),
        INDEX idx_ip (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
    $has = function ($t) use ($pdo) { try { return $pdo->query("SELECT 1 FROM `$t` LIMIT 1") !== false; } catch (\Throwable $e) { return false; } };
    if ($has('saas_sms_log') && $has('saas_affiliates') && $has('saas_api_config')) {
        foreach ([
            ['saas_sms_log', 'ok', 'TINYINT(1) NOT NULL DEFAULT 1'],
            ['saas_sms_log', 'tpl', "VARCHAR(40) NOT NULL DEFAULT ''"],
            ['saas_sms_log', 'err', "VARCHAR(255) NOT NULL DEFAULT ''"],
            ['saas_affiliates', 'email', "VARCHAR(190) NOT NULL DEFAULT ''"],
            ['saas_affiliates', 'password_hash', "VARCHAR(255) NOT NULL DEFAULT ''"],
            ['saas_affiliates', 'google_sub', "VARCHAR(64) NOT NULL DEFAULT ''"],
            ['saas_affiliates', 'pw_fail', 'INT NOT NULL DEFAULT 0'],
            ['saas_affiliates', 'pw_lock_until', 'DATETIME NULL'],
            ['saas_api_config', 'smtp_secure', "VARCHAR(8) NOT NULL DEFAULT 'auto'"],
        ] as [$t, $c, $d]) $ok = $col($t, $c, $d) && $ok;
    } else {
        $ok = false;
    }
    if ($ok && $flag !== '') @file_put_contents($flag, date('c'));
    elseif (!$ok) $done = false;
}

function comm_setting($pdo, $k, $def = '')
{
    if (!function_exists('biz_settings')) return $def;
    $s = biz_settings($pdo);
    return array_key_exists($k, $s) ? $s[$k] : $def;
}

function comm_ip()
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// =============================================================================
// الگوهای پیامک
// =============================================================================
/** همه انواع پیامک سامانه: عنوان، گروه، متغیرها (با نمونه) و متن پیش‌فرض */
function comm_sms_types()
{
    return [
        'otp_user_login'    => ['g' => 'otp', 'title' => 'کد ورود پنل کاربران و همکاران', 'vars' => ['code' => '48213'], 'text' => 'کد ورود شما: {code}'],
        'otp_user_register' => ['g' => 'otp', 'title' => 'کد تأیید شماره در ثبت‌نام', 'vars' => ['code' => '48213'], 'text' => 'کد تأیید ثبت‌نام: {code}'],
        'otp_user_reset'    => ['g' => 'otp', 'title' => 'کد بازیابی رمز عبور پنل', 'vars' => ['code' => '48213'], 'text' => 'کد بازیابی رمز عبور: {code}'],
        'otp_admin'         => ['g' => 'otp', 'title' => 'کد ورود مدیر کل', 'vars' => ['code' => '48213'], 'text' => 'کد ورود مدیریت: {code}'],
        'otp_aff'           => ['g' => 'otp', 'title' => 'کد ورود پنل همکاری در فروش', 'vars' => ['code' => '48213'], 'text' => 'کد ورود پنل همکاری در فروش: {code}'],
        'otp_member'        => ['g' => 'otp', 'title' => 'کد ورود مخاطبان چت‌بات', 'vars' => ['code' => '48213', 'bot' => 'مشاور سلامت'], 'text' => 'کد ورود شما به «{bot}»: {code}'],
        'otp_member_reset'  => ['g' => 'otp', 'title' => 'کد بازیابی رمز مخاطبان چت‌بات', 'vars' => ['code' => '48213', 'bot' => 'مشاور سلامت'], 'text' => 'کد بازیابی رمز شما در «{bot}»: {code}'],
        'user_exp_soon'     => ['g' => 'note', 'title' => 'یادآوری نزدیک شدن پایان اشتراک', 'vars' => ['name' => 'مریم احمدی', 'days' => '7', 'date' => '1405/07/14', 'offer' => ' تمدید تا 1405/07/07 با 10٪ تخفیف.', 'link' => 'https://site.ir/user/billing.php'],
                                'text' => '{name} عزیز، اشتراک پنل شما {days} روز دیگر ({date}) به پایان می‌رسد. لطفاً از بخش اعتبار و پرداخت پنل خود تمدید کنید.{offer}\nتمدید: {link}'],
        'user_exp_grace'    => ['g' => 'note', 'title' => 'پایان اشتراک (مهلت ۷ روزه)', 'vars' => ['name' => 'مریم احمدی', 'date' => '1405/07/21', 'link' => 'https://site.ir/user/billing.php'],
                                'text' => '{name} عزیز، اشتراک پنل شما به پایان رسید. سرویس تا {date} فعال است؛ لطفاً تمدید کنید.\nتمدید: {link}'],
        'user_exp_stopped'  => ['g' => 'note', 'title' => 'توقف سرویس به دلیل عدم تمدید', 'vars' => ['name' => 'مریم احمدی', 'link' => 'https://site.ir/user/billing.php'],
                                'text' => '{name} عزیز، سرویس پنل شما به دلیل عدم تمدید اشتراک متوقف شد. با تمدید، فوراً فعال می‌شود.\nتمدید: {link}'],
        'inst_pre'          => ['g' => 'note', 'title' => 'یادآوری سررسید قسط', 'vars' => ['name' => 'مریم احمدی', 'seq' => '2', 'amount' => '500,000 تومان', 'date' => '1405/07/14'],
                                'text' => '{name} عزیز، قسط {seq} اشتراک پنل شما ({amount}) در تاریخ {date} سررسید می‌شود.'],
        'inst_late'         => ['g' => 'note', 'title' => 'قسط سررسیدشده', 'vars' => ['name' => 'مریم احمدی', 'seq' => '2', 'amount' => '500,000 تومان', 'date' => '1405/07/21'],
                                'text' => '{name} عزیز، قسط {seq} اشتراک پنل شما ({amount}) سررسید شده است. لطفاً تا {date} پرداخت کنید تا سرویس متوقف نشود.'],
        'inst_blocked'      => ['g' => 'note', 'title' => 'توقف سرویس به دلیل قسط پرداخت‌نشده', 'vars' => ['name' => 'مریم احمدی', 'seq' => '2'],
                                'text' => '{name} عزیز، سرویس پنل شما به دلیل پرداخت‌نشدن قسط متوقف شد. با پرداخت قسط، فوراً فعال می‌شود.'],
        'low_credit'        => ['g' => 'note', 'title' => 'کم شدن اعتبار حساب کاربر', 'vars' => ['name' => 'مریم احمدی', 'link' => 'https://site.ir/user/billing.php'],
                                'text' => 'کاربر گرامی {name}، اعتبار حساب شما رو به اتمام است. برای ادامه استفاده، از پنل شارژ کنید.\nشارژ: {link}'],
        'member_plan_remind' => ['g' => 'note', 'title' => 'یادآوری پایان بسته مخاطب چت‌بات', 'vars' => ['name' => 'رضا', 'plan' => 'ماهانه', 'bot' => 'مشاور سلامت', 'days' => '3', 'date' => '1405/07/14', 'link' => 'https://example.com/chat/?plans=1', 'renew' => "\nتمدید: https://example.com/chat/?plans=1"],
                                'text' => '{name} عزیز، اعتبار بسته «{plan}» شما در «{bot}» {days} روز دیگر ({date}) به پایان می‌رسد.{renew}'],
        'member_notice'     => ['g' => 'note', 'title' => 'پیام و اطلاع‌رسانی به مخاطبان و همکاران (ارسال گروهی، تغییر/لغو جلسه)', 'vars' => ['name' => 'رضا', 'text' => 'جلسه فردا ساعت ۱۰ برگزار می‌شود.', 'title' => 'اطلاعیه', 'bot' => 'مشاور سلامت'],
                                'text' => '{name} عزیز،\n{text}'],
        'reminder'          => ['g' => 'note', 'title' => 'یادآور (یادآورهای کاربران، همکاران و مخاطبان)', 'vars' => ['name' => 'رضا', 'title' => 'جلسه با آقای احمدی', 'time' => 'فردا (پنجشنبه ۱۰ مهر)، ساعت ۱۰:۰۰'],
                                'text' => '⏰ یادآور: {title}\n{time}'],
    ];
}

function comm_sms_groups() { return ['otp' => '🔑 کدهای ورود و تأیید', 'note' => '🔔 یادآوری‌ها و اطلاع‌رسانی']; }

/** پارامترهای الگوی کاوه‌نگار */
function comm_lookup_tokens() { return ['token', 'token2', 'token3', 'token10', 'token20']; }

/** تنظیم ذخیره‌شده یک نوع پیامک (با مقادیر پیش‌فرض) */
function comm_sms_tpl($pdo, $key)
{
    $types = comm_sms_types();
    if (!isset($types[$key])) return null;
    $t = $types[$key];
    $saved = json_decode((string)comm_setting($pdo, 'sms_tpl_' . $key, ''), true);
    $is_otp = $t['g'] === 'otp';
    // پیش‌فرض کدهای پنل (ورود، ثبت‌نام، بازیابی، مدیر): همان الگوی اعتبارسنجی قبلی کاوه‌نگار (sms_otp_template یا «login»)
    // با ارسال متنی پشتیبان؛ کدهای چت‌بات و همکاری در فروش مثل قبل متنی هستند.
    $legacy = '';
    $lookup_def = in_array($key, ['otp_user_login', 'otp_user_register', 'otp_user_reset', 'otp_admin'], true);
    if ($lookup_def) {
        static $legacy_cache = null;
        if ($legacy_cache === null) {
            $legacy_cache = '';
            try { $row = $pdo->query("SELECT * FROM saas_api_config WHERE id=1")->fetch(\PDO::FETCH_ASSOC) ?: []; $legacy_cache = trim((string)($row['sms_otp_template'] ?? '')); } catch (\Throwable $e) {}
            if ($legacy_cache === '') $legacy_cache = 'login';
        }
        $legacy = $legacy_cache;
    }
    $def = [
        'on' => 1,
        'mode' => $lookup_def ? 'lookup' : 'text',
        'text' => $t['text'],
        'lookup' => $legacy,
        'map' => $is_otp ? ['token' => 'code'] : [],
        'fallback' => 1,
    ];
    $v = is_array($saved) ? array_merge($def, array_intersect_key($saved, $def)) : $def;
    if ($is_otp) $v['on'] = 1;   // کد ورود همیشه فعال است
    if (!in_array($v['mode'], ['text', 'lookup'], true)) $v['mode'] = 'text';
    if (!is_array($v['map'])) $v['map'] = [];
    $v['map'] = array_intersect_key(array_filter($v['map'], fn($x) => is_string($x) && isset($t['vars'][$x])), array_flip(comm_lookup_tokens()));
    return $v + ['key' => $key, 'title' => $t['title'], 'vars' => $t['vars'], 'g' => $t['g'], 'default_text' => $t['text']];
}

function comm_sms_tpl_save($pdo, $key, array $in)
{
    $types = comm_sms_types();
    if (!isset($types[$key])) return 'error:نوع پیامک نامعتبر است.';
    $mode = ($in['mode'] ?? '') === 'lookup' ? 'lookup' : 'text';
    $text = mb_substr(trim(str_replace("\r", '', (string)($in['text'] ?? ''))), 0, 600);
    $lookup = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['lookup'] ?? ''));
    $map = [];
    foreach (comm_lookup_tokens() as $tk) {
        $var = (string)($in['map_' . $tk] ?? '');
        if ($var !== '' && isset($types[$key]['vars'][$var])) $map[$tk] = $var;
    }
    if ($mode === 'text' && mb_strlen($text) < 2) return 'error:متن پیامک را بنویسید.';
    if ($mode === 'lookup' && $lookup === '') return 'error:نام الگو (Template) را همان‌طور که در پنل کاوه‌نگار تأیید شده وارد کنید.';
    if ($mode === 'lookup' && !isset($map['token'])) return 'error:مقدار پارامتر اول الگو (token) را انتخاب کنید.';
    if ($text === '') $text = $types[$key]['text'];
    biz_set($pdo, 'sms_tpl_' . $key, json_encode(['on' => !empty($in['on']) ? 1 : 0, 'mode' => $mode, 'text' => $text, 'lookup' => $lookup, 'map' => $map, 'fallback' => !empty($in['fallback']) ? 1 : 0], JSON_UNESCAPED_UNICODE));
    return 'ok:الگوی «' . $types[$key]['title'] . '» ذخیره شد.';
}

function comm_sms_render($text, array $vars)
{
    $r = [];
    foreach ($vars as $k => $v) $r['{' . $k . '}'] = (string)$v;
    // «\n» نوشته‌شده در متن الگو = خط جدید
    return trim(strtr(str_replace('\\n', "\n", (string)$text), $r));
}

/** درخواست HTTP به کاوه‌نگار؛ خروجی: ['ok','error'] */
function comm_kavenegar($api_key, $method, array $data)
{
    $api_key = trim((string)$api_key);
    if ($api_key === '') return ['ok' => false, 'error' => 'کلید API پیامک تنظیم نشده است.'];
    if (defined('COMM_SMS_TEST_URL')) $url = COMM_SMS_TEST_URL . '/' . rawurlencode($api_key) . '/' . $method;   // فقط محیط آزمایش
    else $url = 'https://api.kavenegar.com/v1/' . rawurlencode($api_key) . '/' . $method . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $err !== '') return ['ok' => false, 'error' => 'اتصال به سرویس پیامک برقرار نشد (' . mb_substr($err, 0, 80) . ')'];
    $j = json_decode((string)$resp, true);
    $st = (int)($j['return']['status'] ?? $code);
    if ($st === 200) return ['ok' => true, 'error' => ''];
    $msg = trim((string)($j['return']['message'] ?? ''));
    return ['ok' => false, 'error' => comm_kavenegar_error($st, $msg)];
}

function comm_kavenegar_error($st, $msg = '')
{
    $map = [
        400 => 'پارامترها ناقص است.', 401 => 'حساب پیامک غیرفعال شده است.', 402 => 'عملیات ناموفق بود.',
        403 => 'کلید API پیامک معتبر نیست.', 404 => 'متد نامعتبر است.', 405 => 'نوع درخواست نامعتبر است.',
        406 => 'پارامترهای اجباری خالی است.', 407 => 'دسترسی به این اطلاعات/خط ارسال مجاز نیست.',
        409 => 'سرور پیامک قادر به پاسخ نیست؛ بعداً تلاش کنید.', 411 => 'شماره گیرنده نامعتبر است.',
        412 => 'خط فرستنده نامعتبر است یا متعلق به این حساب نیست.', 413 => 'متن پیام خالی یا بیش از حد طولانی است.',
        414 => 'تعداد درخواست بیش از حد مجاز است.', 415 => 'بازه زمانی نامعتبر است.', 416 => 'IP سرور با تنظیمات پنل پیامک مطابقت ندارد.',
        417 => 'تاریخ ارسال نامعتبر است.', 418 => 'اعتبار حساب پیامک کافی نیست.', 419 => 'طول آرایه‌ها یکسان نیست.',
        422 => 'داده‌ها کاراکتر نامناسب دارند (در الگو، پارامترها نباید فاصله داشته باشند).', 424 => 'الگوی (Template) مورد نظر پیدا نشد یا هنوز تأیید نشده است.',
        426 => 'استفاده از این متد نیاز به سرویس پیشرفته دارد.', 427 => 'ارسال از این خط نیاز به خط اختصاصی/خدماتی دارد.',
        428 => 'ارسال کد از طریق تماس تلفنی ممکن نیست.', 431 => 'ساختار کد صحیح نیست.', 432 => 'پارامتر کد در متن پیام پیدا نشد.',
        451 => 'فراخوانی بیش از حد در بازه زمانی مشخص.', 501 => 'پیامک فقط به شماره صاحب حساب قابل ارسال است (حساب آزمایشی).',
    ];
    return ($map[$st] ?? ('خطای سرویس پیامک' . ($msg !== '' ? ': ' . $msg : ''))) . ' (کد ' . $st . ')';
}

/**
 * ارسال پیامک با الگوی تعریف‌شده
 * @param array $opts cfg (پنل پیامک اختصاصی: فقط متن)، user_id، bot_id، kind، source، log
 * @return array ['ok','error','text']
 */
function comm_sms_send($pdo, $mobile, $key, array $vars = [], array $opts = [])
{
    $tpl = comm_sms_tpl($pdo, $key);
    if (!$tpl) return ['ok' => false, 'error' => 'نوع پیامک نامعتبر است.', 'text' => ''];
    $m = function_exists('biz_mobile') ? biz_mobile($mobile) : (string)$mobile;
    if ($m === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.', 'text' => ''];
    if (empty($tpl['on'])) return ['ok' => false, 'error' => 'ارسال این نوع پیامک در تنظیمات غیرفعال است.', 'text' => '', 'off' => true];
    $own = !empty($opts['cfg']);
    $cfg = $own ? $opts['cfg'] : (function_exists('saas_get_api_config') ? saas_get_api_config($pdo) : []);
    if (!$own && (empty($cfg['sms_enabled']) || trim((string)($cfg['sms_api_key'] ?? '')) === '')) return ['ok' => false, 'error' => 'سرویس پیامک فعال نیست.', 'text' => ''];
    $text = comm_sms_render($tpl['text'], $vars);
    $r = ['ok' => false, 'error' => ''];
    if ($tpl['mode'] === 'lookup' && !$own && $tpl['lookup'] !== '') {
        $data = ['receptor' => $m, 'template' => $tpl['lookup']];
        foreach ($tpl['map'] as $tk => $var) {
            $val = trim((string)($vars[$var] ?? ''));
            // token تا token3 نباید فاصله داشته باشند (کاوه‌نگار) → نیم‌فاصله
            if (in_array($tk, ['token', 'token2', 'token3'], true)) $val = preg_replace('/\s+/u', "\u{200C}", $val);
            if ($val !== '') $data[$tk] = mb_substr($val, 0, 100);
        }
        $r = comm_kavenegar($cfg['sms_api_key'], 'verify/lookup', $data);
        if (!$r['ok'] && !empty($tpl['fallback']) && $text !== '') {
            $r2 = comm_kavenegar($cfg['sms_api_key'], 'sms/send', ['receptor' => $m, 'sender' => (string)($cfg['sms_sender'] ?? ''), 'message' => $text]);
            if ($r2['ok']) $r = ['ok' => true, 'error' => 'الگو ناموفق (' . $r['error'] . ')؛ با متن ارسال شد.'];
            else $r['error'] .= ' | ارسال متنی: ' . $r2['error'];
        }
    } else {
        $r = comm_kavenegar($cfg['sms_api_key'], 'sms/send', ['receptor' => $m, 'sender' => (string)($cfg['sms_sender'] ?? ''), 'message' => $text]);
    }
    if (!$r['ok']) error_log('[SMS] ' . $key . ' → ' . $m . ': ' . $r['error']);
    if (!isset($opts['log']) || $opts['log']) comm_sms_log($pdo, (int)($opts['user_id'] ?? 0), (int)($opts['bot_id'] ?? 0), $m, (string)($opts['kind'] ?? $key), (string)($opts['source'] ?? ($own ? 'own' : 'platform')), $r['ok'], $key, $r['ok'] ? '' : $r['error']);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'text' => $text];
}

/** ارسال متن آزاد (برای پیام‌هایی که الگو ندارند) */
function comm_sms_text($pdo, $mobile, $text, array $opts = [])
{
    $own = !empty($opts['cfg']);
    $cfg = $own ? $opts['cfg'] : saas_get_api_config($pdo);
    $m = function_exists('biz_mobile') ? biz_mobile($mobile) : (string)$mobile;
    if ($m === '') return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.'];
    if (!$own && (empty($cfg['sms_enabled']) || trim((string)($cfg['sms_api_key'] ?? '')) === '')) return ['ok' => false, 'error' => 'سرویس پیامک فعال نیست.'];
    $r = comm_kavenegar($cfg['sms_api_key'], 'sms/send', ['receptor' => $m, 'sender' => (string)($cfg['sms_sender'] ?? ''), 'message' => (string)$text]);
    if (!$r['ok']) error_log('[SMS] text → ' . $m . ': ' . $r['error']);
    return $r;
}

function comm_sms_log($pdo, $user_id, $bot_id, $mobile, $kind, $source, $ok, $tpl = '', $err = '')
{
    try {
        $pdo->prepare("INSERT INTO saas_sms_log (user_id, bot_id, mobile, kind, source, created_at, ok, tpl, err) VALUES(?,?,?,?,?,?,?,?,?)")
            ->execute([(int)$user_id, (int)$bot_id, (string)$mobile, mb_substr((string)$kind, 0, 20), mb_substr((string)$source, 0, 10), comm_now(), $ok ? 1 : 0, mb_substr((string)$tpl, 0, 40), mb_substr((string)$err, 0, 255)]);
    } catch (\Throwable $e) {
        // ستون‌های جدید هنوز ساخته نشده‌اند
        try { $pdo->prepare("INSERT INTO saas_sms_log (user_id, bot_id, mobile, kind, source, created_at) VALUES(?,?,?,?,?,?)")->execute([(int)$user_id, (int)$bot_id, (string)$mobile, mb_substr((string)$kind, 0, 20), mb_substr((string)$source, 0, 10), comm_now()]); } catch (\Throwable $e2) {}
    }
}

// =============================================================================
// کد یک‌بارمصرف (OTP) عمومی
// =============================================================================
/**
 * ساخت و ارسال کد
 * @param string $scope user | admin | aff
 * @param string $purpose login | register | reset
 * @return string '' یعنی ارسال شد، وگرنه متن خطا
 */
function comm_otp_send($pdo, $scope, $mobile, $purpose, $tpl_key, array $vars = [])
{
    $mobile = biz_mobile($mobile);
    if ($mobile === '') return 'شماره موبایل معتبر نیست (مثال: 09121234567).';
    if (function_exists('ipg_blocked_msg') && ($ipb = ipg_blocked_msg($pdo)) !== '') return $ipb;   // IP قفل/مسدود
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM saas_otps WHERE scope=? AND mobile=? AND created_at >= ?");
        $c->execute([$scope, $mobile, comm_now(-600)]);
        if ((int)$c->fetchColumn() >= 3) return 'تعداد درخواست کد زیاد است؛ ۱۰ دقیقه دیگر تلاش کنید.';
        $ip = comm_ip();
        if ($ip !== '') {
            $c = $pdo->prepare("SELECT COUNT(*) FROM saas_otps WHERE ip=? AND created_at >= ?");
            $c->execute([$ip, comm_now(-3600)]);
            if ((int)$c->fetchColumn() >= 15) return 'تعداد درخواست کد از این دستگاه زیاد است؛ کمی بعد تلاش کنید.';
        }
    } catch (\Throwable $e) { return 'ارسال کد در حال حاضر ممکن نیست.'; }
    $code = (string)random_int(10000, 99999);
    $r = comm_sms_send($pdo, $mobile, $tpl_key, $vars + ['code' => $code], ['kind' => mb_substr($scope . '_otp', 0, 20)]);
    if (!$r['ok']) return 'ارسال پیامک ممکن نشد؛ کمی بعد دوباره تلاش کنید.';
    $pdo->prepare("INSERT INTO saas_otps (scope, purpose, mobile, code_hash, attempts, ip, expires_at, created_at) VALUES(?,?,?,?,0,?,?,?)")
        ->execute([$scope, $purpose, $mobile, comm_otp_hash($scope, $purpose, $mobile, $code), comm_ip(), comm_now(300), comm_now()]);
    return '';
}

function comm_otp_hash($scope, $purpose, $mobile, $code)
{
    $salt = defined('AICHAT_DB_PASS') ? (string)AICHAT_DB_PASS : 'otp';
    return hash('sha256', $scope . '|' . $purpose . '|' . $mobile . '|' . $code . '|' . $salt);
}

/** بررسی کد؛ خروجی: '' یعنی درست */
function comm_otp_check($pdo, $scope, $mobile, $purpose, $code)
{
    $mobile = biz_mobile($mobile);
    $code = preg_replace('/\D+/', '', strtr((string)$code, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
    // محافظ IP: اگر این IP به‌خاطر ورودهای ناموفق قفل/مسدود است
    if (function_exists('ipg_blocked_msg') && ($ipb = ipg_blocked_msg($pdo)) !== '') return $ipb;
    if ($mobile === '' || strlen($code) !== 5) return 'کد تأیید نادرست است.';
    $st = $pdo->prepare("SELECT * FROM saas_otps WHERE scope=? AND mobile=? AND purpose=? ORDER BY id DESC LIMIT 1");
    $st->execute([$scope, $mobile, $purpose]);
    $otp = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$otp || strtotime((string)$otp['expires_at']) < time()) return 'کد منقضی شده است؛ دوباره درخواست کنید.';
    if ((int)$otp['attempts'] >= 5) return 'تعداد تلاش‌ها زیاد است؛ کد جدید بگیرید.';
    $pdo->prepare("UPDATE saas_otps SET attempts = attempts + 1 WHERE id=?")->execute([(int)$otp['id']]);
    if (!hash_equals((string)$otp['code_hash'], comm_otp_hash($scope, $purpose, $mobile, $code))) {
        if (function_exists('ipg_fail')) {
            $area = $purpose === 'reset' ? 'reset' : ($scope === 'admin' ? 'admin' : ($scope === 'aff' ? 'aff' : 'user'));
            if (($ipl = ipg_fail($pdo, $area, $mobile)) !== '') return $ipl;
        }
        return 'کد تأیید نادرست است.';
    }
    $pdo->prepare("DELETE FROM saas_otps WHERE scope=? AND mobile=? AND purpose=?")->execute([$scope, $mobile, $purpose]);
    if (function_exists('ipg_success')) ipg_success($pdo);
    return '';
}

function comm_otp_cleanup($pdo)
{
    try { $pdo->prepare("DELETE FROM saas_otps WHERE created_at < ?")->execute([comm_now(-86400)]); } catch (\Throwable $e) {}
}

// =============================================================================
// روش‌های ورود
// =============================================================================
function comm_login_sections()
{
    return [
        'user'   => ['title' => 'پنل کاربران و همکاران', 'icon' => '🧑‍💻', 'note' => 'ورود و ثبت‌نام کاربران سامانه و ورود همکاران/مشاوران آن‌ها'],
        'member' => ['title' => 'مخاطبان چت‌بات‌ها', 'icon' => '💬', 'note' => 'ورود و ثبت‌نام مراجعان در صفحه چت همه چت‌بات‌ها'],
        'aff'    => ['title' => 'پنل همکاری در فروش', 'icon' => '🤝', 'note' => 'ورود بازاریاب‌ها و همکاران فروش'],
        'admin'  => ['title' => 'مدیر کل سامانه', 'icon' => '🛡️', 'note' => 'ورود به همین پنل مدیریت'],
    ];
}

function comm_login_methods() { return ['sms' => '📩 کد پیامکی', 'google' => '🔐 حساب گوگل (Gmail)', 'password' => '🔑 رمز ثابت']; }

/** تنظیم ذخیره‌شده: ترتیب و روش‌های روشن */
function comm_login_conf($pdo, $sec)
{
    $all = array_keys(comm_login_methods());
    $order = array_values(array_intersect(array_filter(explode(',', (string)comm_setting($pdo, 'login_' . $sec . '_order', ''))), $all));
    foreach ($all as $m) if (!in_array($m, $order, true)) $order[] = $m;   // پیش‌فرض: پیامک، گوگل، رمز
    $off_raw = comm_setting($pdo, 'login_' . $sec . '_off', null);
    if ($off_raw === null) {
        // سازگاری با تنظیمات قبلی ورود با گوگل
        $off = [];
        if ($sec === 'user' && empty(comm_setting($pdo, 'google_login', 0))) $off[] = 'google';
        if ($sec === 'member' && empty(comm_setting($pdo, 'hd_google_login', 0))) $off[] = 'google';
    } else {
        $off = array_values(array_intersect(array_filter(explode(',', (string)$off_raw)), $all));
    }
    $on = [];
    foreach ($all as $m) $on[$m] = !in_array($m, $off, true);
    return ['order' => $order, 'on' => $on];
}

function comm_login_conf_save($pdo, $sec, array $order, array $on)
{
    if (!isset(comm_login_sections()[$sec])) return 'error:بخش نامعتبر است.';
    $all = array_keys(comm_login_methods());
    $order = array_values(array_unique(array_intersect($order, $all)));
    foreach ($all as $m) if (!in_array($m, $order, true)) $order[] = $m;
    $off = [];
    foreach ($all as $m) if (empty($on[$m])) $off[] = $m;
    if (count($off) === count($all)) return 'error:دست‌کم یک روش ورود باید فعال باشد.';
    if ($sec === 'admin' && in_array('password', $off, true)) {
        // رمز مدیر فقط وقتی خاموش می‌شود که روش دیگری واقعاً آماده باشد (جلوگیری از قفل شدن پنل)
        $ready = (!in_array('sms', $off, true) && comm_login_ready($pdo, 'admin', 'sms')) || (!in_array('google', $off, true) && comm_login_ready($pdo, 'admin', 'google'));
        if (!$ready) return 'error:برای خاموش کردن ورود مدیر با رمز، ابتدا شماره موبایل مدیر (با پیامک فعال) یا ایمیل گوگل مدیر را ثبت کنید.';
    }
    biz_set($pdo, 'login_' . $sec . '_order', implode(',', $order));
    biz_set($pdo, 'login_' . $sec . '_off', implode(',', $off));
    if ($sec === 'user') biz_set($pdo, 'google_login', in_array('google', $off, true) ? 0 : 1);
    if ($sec === 'member') biz_set($pdo, 'hd_google_login', in_array('google', $off, true) ? 0 : 1);
    return 'ok:روش‌های ورود «' . comm_login_sections()[$sec]['title'] . '» ذخیره شد.';
}

function comm_google_configured($pdo)
{
    return trim((string)comm_setting($pdo, 'google_client_id', '')) !== '' && trim((string)comm_setting($pdo, 'google_client_secret', '')) !== '';
}

function comm_platform_sms_ready($pdo)
{
    try { $cfg = saas_get_api_config($pdo); } catch (\Throwable $e) { return false; }
    return !empty($cfg['sms_enabled']) && trim((string)($cfg['sms_api_key'] ?? '')) !== '';
}

// =============================================================================
// نسخه ۷۸: ورود دو مرحله‌ای مدیر کل — مرحله اول: رمز ثابت (یا حساب گوگل)، مرحله دوم: کد پیامکی به شماره مدیر
//  - فعال‌سازی فقط با تأیید یک کد آزمایشی (تا مدیر بیرون نماند)
//  - راه نجات از طریق هاست: فایل خالی uploads/admin_2fa_off.txt ← ورود دو مرحله‌ای خاموش می‌شود
// =============================================================================

/** آیا پیش‌نیازهای ورود دو مرحله‌ای آماده است؟ (سرویس پیامک سامانه + شماره مدیر) */
function comm_admin_2fa_ready($pdo)
{
    return $pdo && comm_platform_sms_ready($pdo) && (bool)comm_admin_mobiles($pdo);
}

/** آیا ورود دو مرحله‌ای مدیر روشن است؟ */
function comm_admin_2fa_on($pdo)
{
    if (!$pdo || !function_exists('biz_set')) return false;
    static $checked = false;
    if (!$checked) {
        $checked = true;
        $rf = rtrim(defined('AICHAT_UPLOAD_DIR') ? AICHAT_UPLOAD_DIR : dirname(__DIR__) . '/uploads', '/');
        foreach (['admin_2fa_off.txt', 'admin_2fa_off'] as $n) {
            if (is_file($rf . '/' . $n)) {
                biz_set($pdo, 'admin_2fa', '');
                @unlink($rf . '/' . $n);
                error_log('[ADMIN] 2FA turned off by ' . $n);
                break;
            }
        }
    }
    // اگر پیامک سامانه یا شماره مدیر حذف شود، ورود فقط با مرحله اول انجام می‌شود (تا مدیر بیرون نماند)
    return (string)comm_setting($pdo, 'admin_2fa', '') === '1' && comm_admin_2fa_ready($pdo);
}

/**
 * شروع مرحله دوم (پس از رمز درست یا ورود گوگل): نشست مرحله دوم ساخته و اگر فقط یک شماره مدیر هست، کد همان‌جا فرستاده می‌شود
 * مدیر هنوز وارد نشده است؛ فقط پس از کد درست، $_SESSION['aichat_admin'] ساخته می‌شود.
 */
function comm_admin_2fa_begin($pdo, $via)
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    unset($_SESSION['aichat_admin'], $_SESSION['adm_sms']);
    $mobs = comm_admin_mobiles($pdo);
    $st = ['via' => (string)$via, 'at' => time(), 'mobile' => count($mobs) === 1 ? $mobs[0] : '', 'sent' => 0, 'err' => ''];
    if ($st['mobile'] !== '') {
        $err = comm_otp_send($pdo, 'admin', $st['mobile'], 'login2', 'otp_admin');
        if ($err === '') $st['sent'] = time(); else $st['err'] = $err;
    }
    $_SESSION['adm_2fa'] = $st;
}

/** نشست مرحله دوم (۱۰ دقیقه اعتبار) */
function comm_admin_2fa_state()
{
    $st = $_SESSION['adm_2fa'] ?? null;
    if (!is_array($st) || time() - (int)($st['at'] ?? 0) > 600) { unset($_SESSION['adm_2fa']); return null; }
    return $st;
}

/** شماره‌ها و ایمیل‌های مجاز مدیر کل */
function comm_admin_mobiles($pdo)
{
    $out = [];
    foreach (preg_split('/[\s,،]+/u', (string)comm_setting($pdo, 'admin_mobiles', '')) as $m) if (($m = biz_mobile($m)) !== '') $out[] = $m;
    return array_values(array_unique($out));
}

function comm_admin_emails($pdo)
{
    $out = [];
    foreach (preg_split('/[\s,،]+/u', strtolower((string)comm_setting($pdo, 'admin_emails', ''))) as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    return array_values(array_unique($out));
}

/** آیا این روش برای این بخش از نظر فنی آماده است؟ (پیامک/گوگل تنظیم شده) */
function comm_login_ready($pdo, $sec, $method)
{
    if ($method === 'password') return true;
    if ($method === 'google') {
        if (!comm_google_configured($pdo)) return false;
        return $sec !== 'admin' || comm_admin_emails($pdo);
    }
    if ($method === 'sms') {
        if ($sec === 'member') return true;   // برای هر ربات جداگانه (پنل پیامک اختصاصی یا سامانه) بررسی می‌شود
        if (!comm_platform_sms_ready($pdo)) return false;
        return $sec !== 'admin' || comm_admin_mobiles($pdo);
    }
    return false;
}

/**
 * روش‌های قابل استفاده این بخش به ترتیب اولویت (روشن + آماده)
 * اگر هیچ روشی آماده نباشد، «رمز ثابت» نمایش داده می‌شود تا ورود قطع نشود.
 */
function comm_login_active($pdo, $sec)
{
    $c = comm_login_conf($pdo, $sec);
    $out = [];
    foreach ($c['order'] as $m) if (!empty($c['on'][$m]) && comm_login_ready($pdo, $sec, $m)) $out[] = $m;
    if (!$out && $sec !== 'member') $out = ['password'];
    return $out;
}

function comm_login_is($pdo, $sec, $method) { return in_array($method, comm_login_active($pdo, $sec), true); }

// =============================================================================
// ایمیل
// =============================================================================
function comm_mail_cfg($pdo)
{
    $row = [];
    try { $row = $pdo->query("SELECT * FROM saas_api_config WHERE id=1")->fetch(\PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) {}
    $host = strtolower((string)parse_url(defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : '', PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host ?: 'localhost');
    $site = '';
    try { if (function_exists('site_settings')) $site = (string)(site_settings($pdo)['site_name'] ?? ''); } catch (\Throwable $e) {}
    $from = trim((string)($row['smtp_from'] ?? ''));
    return [
        'enabled'   => !empty($row['email_enabled']),
        'host'      => trim((string)($row['smtp_host'] ?? '')),
        'port'      => (int)($row['smtp_port'] ?? 587) ?: 587,
        'secure'    => in_array($row['smtp_secure'] ?? 'auto', ['auto', 'tls', 'ssl', 'none'], true) ? (string)($row['smtp_secure'] ?? 'auto') : 'auto',
        'user'      => trim((string)($row['smtp_user'] ?? '')),
        'pass'      => (string)($row['smtp_pass'] ?? ''),
        'from'      => filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : (filter_var((string)($row['smtp_user'] ?? ''), FILTER_VALIDATE_EMAIL) && trim((string)($row['smtp_host'] ?? '')) !== '' ? trim((string)$row['smtp_user']) : 'noreply@' . $host),
        'from_name' => trim((string)($row['smtp_from_name'] ?? '')) !== '' && trim((string)$row['smtp_from_name']) !== 'ویجت هوشمند' ? trim((string)$row['smtp_from_name']) : ($site !== '' ? $site : $host),
        'domain'    => $host,
    ];
}

/** قالب HTML ساده و راست‌به‌چپ برای ایمیل */
function comm_mail_html($title, $text, $button = null)
{
    $body = nl2br(comm_h($text));
    $btn = $button ? '<p style="text-align:center;margin:26px 0"><a href="' . comm_h($button['url']) . '" style="background:#7c3aed;color:#fff;text-decoration:none;padding:12px 26px;border-radius:10px;font-weight:bold;display:inline-block">' . comm_h($button['label']) . '</a></p>'
         . '<p style="font-size:12px;color:#64748b;direction:ltr;text-align:left;word-break:break-all">' . comm_h($button['url']) . '</p>' : '';
    return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"></head><body style="margin:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif">'
         . '<div style="max-width:560px;margin:24px auto;background:#fff;border-radius:14px;padding:26px 28px;direction:rtl;text-align:right;color:#1e293b;line-height:2;font-size:14px">'
         . '<h2 style="margin:0 0 14px;font-size:18px;color:#1e1b4b">' . comm_h($title) . '</h2>' . $body . $btn . '</div></body></html>';
}

/**
 * ارسال ایمیل
 * @return array ['ok','error','log']
 */
function comm_mail_send($pdo, $to, $subject, $text, $html = null, $cfg = null)
{
    $cfg = $cfg ?: comm_mail_cfg($pdo);
    $to = trim((string)$to);
    $log = [];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'آدرس ایمیل گیرنده معتبر نیست.', 'log' => $log];
    $boundary = 'b' . bin2hex(random_bytes(8));
    $from = $cfg['from'];
    $enc = fn($s) => '=?UTF-8?B?' . base64_encode((string)$s) . '?=';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $enc($cfg['from_name']) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? $cfg['domain']) . '>',
        'MIME-Version: 1.0',
        'X-Mailer: PHP',
    ];
    if ($html !== null) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $content = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$text))
                 . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$html))
                 . "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $content = chunk_split(base64_encode((string)$text));
    }

    // بدون SMTP: تابع mail سرور (با فرستنده پاکت برای cPanel)
    if ($cfg['host'] === '') {
        if (!function_exists('mail')) return comm_mail_result($pdo, false, 'تابع mail روی سرور غیرفعال است؛ تنظیمات SMTP را وارد کنید.', $log);
        $params = preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/', $from) ? '-f' . $from : '';
        $ok = @mail($to, $enc($subject), $content, implode("\r\n", array_filter($headers, fn($h) => stripos($h, 'Date:') !== 0)), $params);
        $log[] = 'mail() → ' . ($ok ? 'پذیرفته شد' : 'رد شد');
        return comm_mail_result($pdo, (bool)$ok, $ok ? '' : 'سرور ایمیل را نپذیرفت (تابع mail). بهتر است SMTP همان دامنه (مثلاً mail.' . $cfg['domain'] . ') را تنظیم کنید.', $log);
    }

    $secure = $cfg['secure'];
    if ($secure === 'auto') $secure = $cfg['port'] === 465 ? 'ssl' : 'tls';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return comm_mail_result($pdo, false, 'اتصال به سرور SMTP (' . $cfg['host'] . ':' . $cfg['port'] . ') برقرار نشد: ' . ($errstr ?: 'بدون پاسخ') . '. اگر سرور سایت در ایران است، معمولاً اتصال به Gmail بسته است؛ از ایمیل همان هاست (mail.' . $cfg['domain'] . ') استفاده کنید.', $log);
    stream_set_timeout($sock, 15);
    $read = function () use ($sock, &$log) {
        $resp = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $log[] = 'S: ' . trim($resp);
        return $resp;
    };
    $cmd = function ($c, $hide = false) use ($sock, $read, &$log) { fwrite($sock, $c . "\r\n"); $log[] = 'C: ' . ($hide ? '***' : $c); return $read(); };
    $code = fn($r) => (int)substr(trim($r), 0, 3);
    $fail = function ($msg) use ($sock, $pdo, &$log) { @fwrite($sock, "QUIT\r\n"); @fclose($sock); return comm_mail_result($pdo, false, $msg, $log); };

    $r = $read();
    if ($code($r) !== 220) return $fail('سرور SMTP پاسخ خوش‌آمد نداد: ' . trim($r));
    $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', $cfg['domain']) ?: 'localhost';
    $r = $cmd('EHLO ' . $ehlo);
    if ($code($r) !== 250) { $r = $cmd('HELO ' . $ehlo); if ($code($r) !== 250) return $fail('سرور SMTP درخواست HELO را نپذیرفت: ' . trim($r)); }
    if ($secure === 'tls') {
        if (stripos($r, 'STARTTLS') === false) return $fail('سرور SMTP روی این پورت از TLS پشتیبانی نمی‌کند؛ نوع اتصال را SSL (پورت ۴۶۵) یا «بدون رمزنگاری» بگذارید.');
        $r = $cmd('STARTTLS');
        if ($code($r) !== 220) return $fail('STARTTLS پذیرفته نشد: ' . trim($r));
        $methods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (!@stream_socket_enable_crypto($sock, true, $methods)) return $fail('برقراری اتصال رمزنگاری‌شده (TLS) ناموفق بود.');
        $r = $cmd('EHLO ' . $ehlo);
    }
    if ($cfg['user'] !== '' && $cfg['pass'] !== '') {
        $r = $cmd('AUTH LOGIN');
        if ($code($r) !== 334) {
            $r = $cmd('AUTH PLAIN ' . base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']), true);
            if ($code($r) !== 235) return $fail('سرور SMTP روش ورود را نپذیرفت: ' . trim($r));
        } else {
            $cmd(base64_encode($cfg['user']), true);
            $r = $cmd(base64_encode($cfg['pass']), true);
            if ($code($r) !== 235) return $fail('نام کاربری یا رمز SMTP نادرست است' . (stripos($cfg['host'], 'gmail') !== false ? ' (برای Gmail باید «App Password» بسازید)' : '') . ': ' . trim($r));
        }
    }
    $r = $cmd('MAIL FROM:<' . $from . '>');
    if ($code($r) !== 250) return $fail('آدرس فرستنده پذیرفته نشد (فرستنده باید همان حساب SMTP یا دامنه آن باشد): ' . trim($r));
    $r = $cmd('RCPT TO:<' . $to . '>');
    if (!in_array($code($r), [250, 251], true)) return $fail('گیرنده پذیرفته نشد: ' . trim($r));
    $r = $cmd('DATA');
    if ($code($r) !== 354) return $fail('ارسال متن پذیرفته نشد: ' . trim($r));
    $msg = implode("\r\n", $headers) . "\r\nTo: <" . $to . ">\r\nSubject: " . $enc($subject) . "\r\n\r\n" . $content;
    $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msg));
    fwrite($sock, $msg . "\r\n.\r\n");
    $log[] = 'C: [متن ایمیل]';
    $r = $read();
    @fwrite($sock, "QUIT\r\n");
    @fclose($sock);
    if ($code($r) !== 250) return comm_mail_result($pdo, false, 'سرور ایمیل را نپذیرفت: ' . trim($r), $log);
    return comm_mail_result($pdo, true, '', $log);
}

function comm_mail_result($pdo, $ok, $error, $log)
{
    try {
        biz_set($pdo, 'mail_last', json_encode(['ok' => $ok ? 1 : 0, 'error' => (string)$error, 'at' => comm_now()], JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {}
    if (!$ok) error_log('[MAIL] ' . $error);
    return ['ok' => (bool)$ok, 'error' => (string)$error, 'log' => $log];
}

// =============================================================================
// درگاه زرین‌پال: توضیح خطاها و آزمایش
// =============================================================================
function comm_zp_error($code)
{
    $map = [
        -9  => 'اطلاعات ارسالی ناقص یا نامعتبر است (مثلاً مبلغ کمتر از ۱۰۰۰ تومان، آدرس بازگشت یا کد پذیرنده با قالب اشتباه).',
        -10 => 'کد پذیرنده (Merchant ID) یا IP سرور مورد قبول درگاه نیست. کد ۳۶ کاراکتری را دوباره از پنل زرین‌پال کپی کنید، حالت آزمایشی را خاموش کنید و مطمئن شوید دامنه ثبت‌شده در زرین‌پال همین سایت است.',
        -11 => 'درگاه پذیرنده فعال نیست (هنوز تأیید نشده یا غیرفعال شده). از پنل زرین‌پال وضعیت درگاه را بررسی کنید.',
        -12 => 'تعداد تلاش‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.',
        -15 => 'درگاه پذیرنده به حالت تعلیق درآمده است؛ با پشتیبانی زرین‌پال تماس بگیرید.',
        -16 => 'سطح تأیید پذیرنده کمتر از سطح نقره‌ای است.',
        -17 => 'محدودیت پذیرنده در سطح آبی.',
        -30 => 'پذیرنده اجازه دسترسی به تسویه اشتراکی ندارد.',
        -50 => 'مبلغ پرداخت‌شده با مبلغ درخواستی برابر نیست.',
        -51 => 'پرداخت ناموفق بود.',
        -54 => 'کد پیگیری (Authority) نامعتبر است.',
        101 => 'تراکنش قبلاً تأیید شده است.',
    ];
    return $map[(int)$code] ?? 'خطای ناشناخته درگاه (کد ' . $code . ').';
}

/** پاک‌سازی کد پذیرنده (حذف فاصله‌ها و کاراکترهای نامرئی راست‌به‌چپ، تبدیل ارقام فارسی) */
function comm_zp_clean_merchant($m)
{
    $m = strtr((string)$m, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '–' => '-', '—' => '-', '‐' => '-']);
    return strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', $m));
}

/**
 * metadata درخواست زرین‌پال (نسخه ۶۰): فقط موبایل ۰۹xxxxxxxxx و ایمیل واقعی؛ مقدار نامعتبر یا خالی باعث خطای -9 (اعتبارسنجی) می‌شد
 * @return array|null  null = بدون metadata
 */
function comm_zp_meta($mobile = '', $email = '')
{
    $m = preg_replace('/\D/', '', strtr((string)$mobile, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
    if (strpos($m, '0098') === 0) $m = '0' . substr($m, 4); elseif (strpos($m, '98') === 0 && strlen($m) === 12) $m = '0' . substr($m, 2); elseif (strlen($m) === 10 && $m[0] === '9') $m = '0' . $m;
    $e = strtolower(trim((string)$email));
    $out = [];
    if (preg_match('/^09\d{9}$/', $m)) $out['mobile'] = $m;
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !preg_match('/\.(invalid|local|test)$/', $e)) $out['email'] = $e;
    return $out ?: null;
}

/** بدنه درخواست پرداخت زرین‌پال با مقادیر پاک‌سازی‌شده */
function comm_zp_payload($merchant, $amount_rial, $desc, $callback, $mobile = '', $email = '')
{
    $desc = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$desc));
    $d = ['merchant_id' => comm_zp_clean_merchant($merchant), 'amount' => (int)$amount_rial,
          'description' => mb_substr($desc !== '' ? $desc : 'پرداخت', 0, 250), 'callback_url' => (string)$callback];
    if (($meta = comm_zp_meta($mobile, $email))) $d['metadata'] = $meta;
    return $d;
}

/** توضیح کامل پاسخ ناموفق زرین‌پال (کد + پیام خود زرین‌پال + فیلدهای ایراددار) */
function comm_zp_explain($r)
{
    $r = is_array($r) ? $r : [];
    $e = is_array($r['errors'] ?? null) ? $r['errors'] : [];
    $code = isset($e['code']) ? (int)$e['code'] : (isset($r['data']['code']) ? (int)$r['data']['code'] : 0);
    $parts = [];
    if (!empty($e['message'])) $parts[] = 'پیام زرین‌پال: ' . mb_substr((string)$e['message'], 0, 200);
    if (!empty($e['validations'])) {
        $v = [];
        foreach ((array)$e['validations'] as $k => $x) $v[] = is_array($x) ? implode('؛ ', array_map(fn($a, $b) => (is_string($a) ? $a . ': ' : '') . (is_array($b) ? implode(', ', $b) : $b), array_keys($x), $x)) : (is_string($k) ? $k . ': ' : '') . $x;
        $parts[] = 'فیلدها: ' . mb_substr(implode(' | ', $v), 0, 300);
    }
    if (!$r) $parts[] = 'پاسخی از زرین‌پال دریافت نشد (احتمالاً اتصال سرور به زرین‌پال برقرار نیست).';
    return ['code' => $code ?: '—', 'detail' => implode(' — ', $parts)];
}

function comm_zp_valid_merchant($m) { return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', (string)$m); }

/** آزمایش درگاه با یک درخواست واقعی ۱۰۰۰ تومانی (بدون پرداخت) */
function comm_zp_test($pdo, $merchant, $sandbox)
{
    $merchant = comm_zp_clean_merchant($merchant);
    if (!comm_zp_valid_merchant($merchant)) return ['ok' => false, 'error' => 'قالب کد پذیرنده درست نیست؛ باید ۳۶ کاراکتر به شکل xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx باشد.'];
    $base = defined('HD_ZP_TEST_BASE') ? HD_ZP_TEST_BASE : ($sandbox ? 'https://sandbox.zarinpal.com' : 'https://api.zarinpal.com');
    $ch = curl_init($base . '/pg/v4/payment/request.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(comm_zp_payload($merchant, 10000, 'آزمایش اتصال درگاه', rtrim(AICHAT_BASE_URL, '/') . '/payment/verify.php?payment_id=0')),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $err !== '') return ['ok' => false, 'error' => 'سرور سایت به زرین‌پال وصل نشد (' . mb_substr($err, 0, 100) . '). زرین‌پال فقط به سرورهای داخل ایران پاسخ می‌دهد؛ اگر هاست خارج از ایران است، درگاه کار نمی‌کند.'];
    $j = json_decode((string)$res, true) ?: [];
    if (!empty($j['data']['authority'])) return ['ok' => true, 'error' => '', 'msg' => 'اتصال به درگاه درست است (کد ' . (int)($j['data']['code'] ?? 100) . '). ' . ($sandbox ? 'توجه: حالت آزمایشی روشن است و پرداخت واقعی انجام نمی‌شود.' : '')];
    $ex = comm_zp_explain($j);
    $code = (int)$ex['code'];
    if (!$j) $ex['detail'] = 'پاسخ زرین‌پال قابل خواندن نبود (HTTP ' . $http . '): ' . mb_substr(trim(strip_tags((string)$res)), 0, 150);
    $host = (string)parse_url(AICHAT_BASE_URL, PHP_URL_HOST);
    $hint = in_array($code, [-9, -10], true) ? ' — بررسی کنید در پنل زرین‌پال، «آدرس وب‌سایت» این درگاه دقیقاً «' . $host . '» باشد (آدرس بازگشت از همین دامنه فرستاده می‌شود: ' . rtrim(AICHAT_BASE_URL, '/') . '/payment/verify.php) و هاست سایت در ایران باشد.' : '';
    if (function_exists('biz_set')) biz_set($pdo, 'zp_last_error', json_encode(['code' => $code ?: '—', 'at' => date('Y-m-d H:i:s'), 'detail' => mb_substr($ex['detail'], 0, 400)], JSON_UNESCAPED_UNICODE));
    return ['ok' => false, 'error' => 'کد ' . ($code ?: '—') . ': ' . ($code ? comm_zp_error($code) : 'پاسخ نامعتبر از درگاه.') . ($ex['detail'] !== '' ? ' (' . $ex['detail'] . ')' : '') . $hint, 'code' => $code];
}
