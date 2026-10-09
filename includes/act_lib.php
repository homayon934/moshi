<?php
/**
 * گزارش ورود و بازدید (نسخه ۴۷)
 *
 *  ثبت می‌شود: ورود مدیر، ورود کاربران و همکاران پنل، ورود مخاطبان (چت‌بات و پنل مخاطب)، صفحه‌های بازدیدشده
 *  (پنل کاربر، پنل مخاطب، باز کردن چت) و بازدید صفحه‌های عمومی سایت (بازدیدکنندگان ناشناس با شناسه کوکی).
 *  شهر و کشور از روی IP با سرویس رایگان ip-api.com (دسته‌ای، فقط هنگام نمایش گزارش) و ذخیره در saas_ip_geo.
 *  گزارش‌ها: مدیریت ← «ورود و بازدیدها» (admin/activity.php) و پنل کاربر ← «ورود و بازدید مخاطبان» (user/activity.php).
 *  داده‌های قدیمی‌تر از ۱۸۰ روز خودکار پاک می‌شود.
 * این فایل از انتهای biz_lib.php بارگذاری می‌شود.
 */

if (!defined('ACT_SCHEMA_VERSION')) define('ACT_SCHEMA_VERSION', 1);
if (!defined('ACT_KEEP_DAYS')) define('ACT_KEEP_DAYS', 180);

function act_ensure_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) return;
    $done = true;
    $flag = defined('AICHAT_UPLOAD_DIR') ? rtrim(AICHAT_UPLOAD_DIR, '/') . '/.act_schema_v' . ACT_SCHEMA_VERSION : '';
    if ($flag !== '' && is_file($flag)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(10) NOT NULL,
            actor_id INT NOT NULL DEFAULT 0,
            vid VARCHAR(32) NOT NULL DEFAULT '',
            owner_uid INT NOT NULL DEFAULT 0,
            bot_id INT NOT NULL DEFAULT 0,
            event VARCHAR(10) NOT NULL DEFAULT 'page',
            path VARCHAR(255) NOT NULL DEFAULT '',
            title VARCHAR(200) NOT NULL DEFAULT '',
            name VARCHAR(150) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            ua VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            INDEX idx_actor (kind, actor_id, id),
            INDEX idx_owner (owner_uid, kind, id),
            INDEX idx_vid (vid, id),
            INDEX idx_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS saas_ip_geo (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            country VARCHAR(80) NOT NULL DEFAULT '',
            cc VARCHAR(4) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            ok TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($flag !== '') @file_put_contents($flag, date('c'));
    } catch (\Throwable $e) { $done = false; error_log('[ACT] schema: ' . $e->getMessage()); }
}

function act_now($plus = 0) { return date('Y-m-d H:i:s', time() + (int)$plus); }

function act_kinds() { return ['admin' => 'مدیر کل', 'user' => 'کاربر', 'team' => 'همکار', 'member' => 'مخاطب', 'maccount' => 'پنل مخاطب', 'visitor' => 'بازدیدکننده']; }

/** آیا عامل کاربر یک ربات/خزنده است؟ (بازدید آن ثبت نمی‌شود) */
function act_is_bot($ua)
{
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|curl|wget|python|httpclient|headless|monitor|uptime|facebookexternalhit|preview/i', (string)$ua);
}

/**
 * ثبت یک رویداد
 * @param string $kind admin|user|team|member|maccount|visitor
 * @param string $event login|page|chat
 * @param array $o owner, bot, path, title, name, ip, ua, vid
 */
function act_log($pdo, $kind, $actor_id, $event, array $o = [])
{
    if (!$pdo || !isset(act_kinds()[$kind])) return;
    try {
        act_ensure_schema($pdo);
        $ip = (string)($o['ip'] ?? (function_exists('ipg_ip') ? ipg_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')));
        $ua = (string)($o['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $path = (string)($o['path'] ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $path = preg_replace('/([?&])(token|code|c|pid|Authority|authority|csrf_token|_ft|tok)=[^&]*/i', '$1$2=…', $path);   // اطلاعات حساس در گزارش نمی‌آید
        $pdo->prepare("INSERT INTO saas_activity (kind, actor_id, vid, owner_uid, bot_id, event, path, title, name, ip, ua, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$kind, (int)$actor_id, substr((string)($o['vid'] ?? ''), 0, 32), (int)($o['owner'] ?? 0), (int)($o['bot'] ?? 0), substr($event, 0, 10),
                       mb_substr($path, 0, 255), mb_substr((string)($o['title'] ?? ''), 0, 200), mb_substr((string)($o['name'] ?? ''), 0, 150),
                       substr(filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '', 0, 45), mb_substr($ua, 0, 255), act_now()]);
        if (random_int(1, 400) === 1) $pdo->prepare("DELETE FROM saas_activity WHERE created_at < ?")->execute([act_now(-ACT_KEEP_DAYS * 86400)]);
    } catch (\Throwable $e) { error_log('[ACT] log: ' . $e->getMessage()); }
}

/** بازدید صفحه عمومی سایت (بازدیدکننده ناشناس؛ شناسه در کوکی یک‌ساله) */
function act_visit($pdo, $title = '')
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || act_is_bot((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) return;
    $vid = preg_replace('/[^a-f0-9]/', '', (string)($_COOKIE['_vid'] ?? ''));
    if (strlen($vid) !== 20) {
        $vid = bin2hex(random_bytes(10));
        if (!headers_sent()) setcookie('_vid', $vid, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    }
    $uid = (int)($_SESSION['saas_user_id'] ?? 0);
    act_log($pdo, 'visitor', $uid, 'page', ['vid' => $vid, 'title' => $title, 'name' => $uid ? 'کاربر #' . $uid : '']);
}

/** ورود یک‌باره در هر نشست (پنل کاربر/مدیر): اولین صفحه پس از ورود */
function act_session_login($pdo, $kind, $actor_id, array $o = [])
{
    $k = 'act_li_' . $kind . '_' . (int)$actor_id;
    if (!empty($_SESSION[$k])) return false;
    $_SESSION[$k] = 1;
    act_log($pdo, $kind, $actor_id, 'login', $o + ['path' => '']);
    return true;
}

// =============================================================================
// شهر و کشور از روی IP
// =============================================================================
function act_ip_private($ip)
{
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** نام فارسی کشورهای رایج */
function act_country_fa($cc, $name)
{
    $m = ['IR' => 'ایران', 'TR' => 'ترکیه', 'AE' => 'امارات', 'DE' => 'آلمان', 'US' => 'آمریکا', 'GB' => 'انگلستان', 'CA' => 'کانادا', 'FR' => 'فرانسه', 'NL' => 'هلند',
          'IQ' => 'عراق', 'AF' => 'افغانستان', 'AM' => 'ارمنستان', 'AZ' => 'آذربایجان', 'GE' => 'گرجستان', 'RU' => 'روسیه', 'SE' => 'سوئد', 'IT' => 'ایتالیا', 'AU' => 'استرالیا',
          'FI' => 'فنلاند', 'OM' => 'عمان', 'QA' => 'قطر', 'SA' => 'عربستان', 'KW' => 'کویت', 'BH' => 'بحرین', 'PK' => 'پاکستان', 'IN' => 'هند', 'CN' => 'چین', 'JP' => 'ژاپن',
          'AT' => 'اتریش', 'CH' => 'سوئیس', 'ES' => 'اسپانیا', 'NO' => 'نروژ', 'DK' => 'دانمارک', 'BE' => 'بلژیک', 'MY' => 'مالزی', 'SG' => 'سنگاپور', 'CY' => 'قبرس'];
    return $m[strtoupper((string)$cc)] ?? (string)$name;
}

/** شهرهای پرتکرار ایران به فارسی */
function act_city_fa($city)
{
    $m = ['Tehran' => 'تهران', 'Mashhad' => 'مشهد', 'Isfahan' => 'اصفهان', 'Esfahan' => 'اصفهان', 'Karaj' => 'کرج', 'Shiraz' => 'شیراز', 'Tabriz' => 'تبریز', 'Qom' => 'قم',
          'Ahvaz' => 'اهواز', 'Ahwaz' => 'اهواز', 'Kermanshah' => 'کرمانشاه', 'Urmia' => 'ارومیه', 'Rasht' => 'رشت', 'Zahedan' => 'زاهدان', 'Kerman' => 'کرمان', 'Hamadan' => 'همدان',
          'Yazd' => 'یزد', 'Ardabil' => 'اردبیل', 'Bandar Abbas' => 'بندرعباس', 'Arak' => 'اراک', 'Zanjan' => 'زنجان', 'Sanandaj' => 'سنندج', 'Qazvin' => 'قزوین', 'Khorramabad' => 'خرم‌آباد',
          'Gorgan' => 'گرگان', 'Sari' => 'ساری', 'Bushehr' => 'بوشهر', 'Birjand' => 'بیرجند', 'Ilam' => 'ایلام', 'Semnan' => 'سمنان', 'Yasuj' => 'یاسوج', 'Shahr-e Kord' => 'شهرکرد',
          'Bojnurd' => 'بجنورد', 'Kish' => 'کیش', 'Babol' => 'بابل', 'Amol' => 'آمل', 'Kashan' => 'کاشان', 'Najafabad' => 'نجف‌آباد', 'Dezful' => 'دزفول', 'Sabzevar' => 'سبزوار', 'Neyshabur' => 'نیشابور'];
    return $m[(string)$city] ?? (string)$city;
}

/** آدرس سرویس (قابل تغییر در config با ACT_GEO_URL؛ برای آزمایش) */
function act_geo_url() { return defined('ACT_GEO_URL') ? ACT_GEO_URL : 'http://ip-api.com/batch?fields=status,country,countryCode,city,query'; }

/**
 * یافتن شهر و کشور IPها (فقط موارد ذخیره‌نشده؛ حداکثر ۱۰۰ IP در یک درخواست ۴ ثانیه‌ای)
 * @return array ip => ['country','city','cc','ok']
 */
function act_geo($pdo, array $ips)
{
    act_ensure_schema($pdo);
    $ips = array_values(array_unique(array_filter($ips, fn($i) => filter_var($i, FILTER_VALIDATE_IP))));
    $out = [];
    if (!$ips) return $out;
    foreach (array_chunk($ips, 300) as $ch) {
        $s = $pdo->prepare("SELECT * FROM saas_ip_geo WHERE ip IN (" . implode(',', array_fill(0, count($ch), '?')) . ")");
        $s->execute($ch);
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $out[$r['ip']] = $r;
    }
    $need = [];
    foreach ($ips as $ip) {
        if (isset($out[$ip]) && ($out[$ip]['ok'] || strtotime((string)$out[$ip]['updated_at']) > time() - 86400)) continue;   // ناموفق‌ها روزی یک بار دوباره
        if (act_ip_private($ip)) { $out[$ip] = ['ip' => $ip, 'country' => 'شبکه داخلی', 'cc' => '', 'city' => '', 'ok' => 1]; continue; }
        $need[] = $ip;
    }
    $need = array_slice($need, 0, 100);
    if ($need && function_exists('curl_init') && empty($GLOBALS['__act_geo_down'])) {
        $ch = curl_init(act_geo_url());
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($need), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $code === 200 ? json_decode((string)$res, true) : null;
        if (!is_array($data)) $GLOBALS['__act_geo_down'] = 1;   // سرویس در دسترس نیست (مثلاً هاست اتصال بیرونی را بسته)
        foreach ($need as $i => $ip) {
            $d = is_array($data) ? ($data[$i] ?? null) : null;
            if (is_array($d) && ($d['query'] ?? $ip) !== $ip) foreach ($data as $dd) if (($dd['query'] ?? '') === $ip) $d = $dd;
            $ok = is_array($d) && ($d['status'] ?? '') === 'success';
            $row = ['ip' => $ip, 'country' => $ok ? mb_substr(act_country_fa($d['countryCode'] ?? '', $d['country'] ?? ''), 0, 80) : '', 'cc' => $ok ? substr((string)($d['countryCode'] ?? ''), 0, 4) : '',
                    'city' => $ok ? mb_substr(act_city_fa($d['city'] ?? ''), 0, 100) : '', 'ok' => $ok ? 1 : 0];
            if ($data !== null) {
                try {
                    $pdo->prepare("DELETE FROM saas_ip_geo WHERE ip=?")->execute([$ip]);
                    $pdo->prepare("INSERT INTO saas_ip_geo (ip, country, cc, city, ok, updated_at) VALUES(?,?,?,?,?,?)")->execute([$ip, $row['country'], $row['cc'], $row['city'], $row['ok'], act_now()]);
                } catch (\Throwable $e) {}
            }
            $out[$ip] = $row;
        }
    }
    return $out;
}

function act_place($geo, $ip)
{
    $g = $geo[$ip] ?? null;
    if (!$g || empty($g['ok'])) return '';
    return implode('، ', array_filter([(string)$g['city'], (string)$g['country']], fn($x) => $x !== ''));
}

/** دستگاه و مرورگر از روی User-Agent */
function act_device($ua)
{
    $ua = (string)$ua;
    if ($ua === '') return '';
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'اندروید' : (preg_match('/Windows/i', $ua) ? 'ویندوز' : (preg_match('/Mac OS/i', $ua) ? 'مک' : (preg_match('/Linux/i', $ua) ? 'لینوکس' : ''))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : '')))));
    $mob = preg_match('/Mobile|Android|iPhone/i', $ua) ? '📱' : '💻';
    return trim($mob . ' ' . trim($os . ' ' . $br));
}

// =============================================================================
// گزارش‌ها
// =============================================================================
/**
 * خلاصه به ازای هر شخص
 * @param array $f kind (یا kinds)، owner، days، q (جستجوی نام)، where/params اضافه (برای محدوده همکار)
 * @return array [ ['key','kind','actor_id','vid','name','logins','pages','first','last','ip','ua','bot_id'], ... ]
 */
function act_people($pdo, array $f, $limit = 200)
{
    act_ensure_schema($pdo);
    $w = []; $p = [];
    $kinds = (array)($f['kinds'] ?? [$f['kind'] ?? 'user']);
    $w[] = 'a.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')'; array_push($p, ...$kinds);
    if (isset($f['owner'])) { $w[] = 'a.owner_uid=?'; $p[] = (int)$f['owner']; }
    if (!empty($f['days'])) { $w[] = 'a.created_at >= ?'; $p[] = act_now(-(int)$f['days'] * 86400); }
    if (!empty($f['q'])) { $w[] = '(a.name LIKE ? OR a.ip LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = $f['q'] . '%'; }
    if (!empty($f['where'])) { $w[] = $f['where']; array_push($p, ...(array)($f['params'] ?? [])); }
    $vis = in_array('visitor', $kinds, true) && count($kinds) === 1;
    $key = $vis ? 'a.vid' : "CONCAT(a.kind, ':', a.actor_id)";
    try {
        $s = $pdo->prepare("SELECT $key AS k, MAX(a.id) AS last_id, MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at,
                SUM(CASE WHEN a.event='login' THEN 1 ELSE 0 END) AS logins, SUM(CASE WHEN a.event<>'login' THEN 1 ELSE 0 END) AS pages
            FROM saas_activity a " . ($f['join'] ?? '') . " WHERE " . implode(' AND ', $w) . " GROUP BY $key ORDER BY MAX(a.id) DESC LIMIT " . (int)$limit);
        $s->execute($p);
        $rows = $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { error_log('[ACT] people: ' . $e->getMessage()); return []; }
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['last_id'], $rows);
    $last = [];
    $s = $pdo->prepare("SELECT * FROM saas_activity WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $s->execute($ids);
    foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) $last[(int)$r['id']] = $r;
    $out = [];
    foreach ($rows as $r) {
        $l = $last[(int)$r['last_id']] ?? [];
        $out[] = ['key' => (string)$r['k'], 'kind' => $l['kind'] ?? '', 'actor_id' => (int)($l['actor_id'] ?? 0), 'vid' => (string)($l['vid'] ?? ''), 'name' => (string)($l['name'] ?? ''),
                  'owner' => (int)($l['owner_uid'] ?? 0), 'bot_id' => (int)($l['bot_id'] ?? 0),
                  'logins' => (int)$r['logins'], 'pages' => (int)$r['pages'], 'first' => (string)$r['first_at'], 'last' => (string)$r['last_at'],
                  'ip' => (string)($l['ip'] ?? ''), 'ua' => (string)($l['ua'] ?? ''), 'title' => (string)($l['title'] ?? '')];
    }
    return $out;
}

/** رویدادهای یک شخص (کلید از act_people) */
function act_events($pdo, $key, array $f = [], $limit = 300)
{
    act_ensure_schema($pdo);
    $w = []; $p = [];
    if (preg_match('/^([a-z]+):(\d+)$/', (string)$key, $m)) { $w[] = 'kind=? AND actor_id=?'; $p[] = $m[1]; $p[] = (int)$m[2]; }
    elseif (preg_match('/^[a-f0-9]{20}$/', (string)$key)) { $w[] = "kind='visitor' AND vid=?"; $p[] = $key; }
    else return [];
    if (isset($f['owner'])) { $w[] = 'owner_uid=?'; $p[] = (int)$f['owner']; }
    if (!empty($f['days'])) { $w[] = 'created_at >= ?'; $p[] = act_now(-(int)$f['days'] * 86400); }
    try {
        $s = $pdo->prepare("SELECT * FROM saas_activity WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT " . (int)$limit);
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

/** آمار کلی یک بازه */
function act_stats($pdo, array $kinds, $days, $owner = null)
{
    act_ensure_schema($pdo);
    $w = 'kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ') AND created_at >= ?';
    $p = array_merge($kinds, [act_now(-(int)$days * 86400)]);
    if ($owner !== null) { $w .= ' AND owner_uid=?'; $p[] = (int)$owner; }
    try {
        $s = $pdo->prepare("SELECT SUM(CASE WHEN event='login' THEN 1 ELSE 0 END) AS logins, SUM(CASE WHEN event<>'login' THEN 1 ELSE 0 END) AS pages, COUNT(DISTINCT CASE WHEN kind='visitor' THEN vid ELSE CONCAT(kind, ':', actor_id) END) AS people FROM saas_activity WHERE $w");
        $s->execute($p);
        $r = $s->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['logins' => (int)($r['logins'] ?? 0), 'pages' => (int)($r['pages'] ?? 0), 'people' => (int)($r['people'] ?? 0)];
    } catch (\Throwable $e) { return ['logins' => 0, 'pages' => 0, 'people' => 0]; }
}

/** پربازدیدترین صفحه‌ها */
function act_top_pages($pdo, array $kinds, $days, $owner = null, $limit = 12)
{
    $w = "kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ") AND event<>'login' AND created_at >= ?";
    $p = array_merge($kinds, [act_now(-(int)$days * 86400)]);
    if ($owner !== null) { $w .= ' AND owner_uid=?'; $p[] = (int)$owner; }
    try {
        $s = $pdo->prepare("SELECT title, MIN(path) AS path, COUNT(*) AS n FROM saas_activity WHERE $w GROUP BY title ORDER BY n DESC LIMIT " . (int)$limit);
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { return []; }
}

/** عنوان رویداد برای نمایش */
function act_event_label(array $e)
{
    if ($e['event'] === 'login') return '🔑 ورود' . ($e['title'] !== '' ? ' — ' . $e['title'] : '');
    if ($e['event'] === 'chat') return '💬 ' . ($e['title'] !== '' ? $e['title'] : 'باز کردن چت');
    return '📄 ' . ($e['title'] !== '' ? $e['title'] : ($e['path'] !== '' ? $e['path'] : 'صفحه'));
}

/** تاریخ شمسی کوتاه */
function act_jdt($dt)
{
    $dt = (string)$dt;
    if ($dt === '') return '';
    return (function_exists('rem_jdate') ? rem_jdate($dt) : substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5);
}

/**
 * صفحه گزارش (مشترک مدیر و کاربر)
 * $o: tabs => [key => ['label', 'kinds' => [...], 'owner' => int|null, 'where' => sql, 'params' => [], 'join' => sql]], url => آدرس پایه صفحه
 */
function act_report_html($pdo, array $o)
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tabs = $o['tabs'];
    $tab = (string)($_GET['tab'] ?? '');
    if (!isset($tabs[$tab])) $tab = (string)array_key_first($tabs);
    $T = $tabs[$tab];
    $days = (int)($_GET['days'] ?? 30); if (!in_array($days, [1, 7, 30, 90, 180], true)) $days = 30;
    $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
    $who = (string)($_GET['who'] ?? '');
    $url = function (array $p) use ($o, $tab, $days, $q) { $p += ['tab' => $tab, 'days' => $days, 'q' => $q]; return $o['url'] . '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null)); };
    $f = ['kinds' => $T['kinds'], 'days' => $days, 'q' => $q];
    if (array_key_exists('owner', $T) && $T['owner'] !== null) $f['owner'] = $T['owner'];
    foreach (['where', 'params', 'join'] as $k) if (isset($T[$k])) $f[$k] = $T[$k];
    $kl = act_kinds();
    ob_start();
    ?>
<style>
.act-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.act-tabs a{padding:7px 14px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;color:#475569;font-size:13px;font-weight:600;text-decoration:none}
.act-tabs a.on{background:#4f46e5;border-color:#4f46e5;color:#fff}
.act-f{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.act-f select,.act-f input{width:auto!important;min-width:140px;padding:7px 10px!important;font-size:13px!important}
.act-st{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.act-st div{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 16px;font-size:13px}.act-st b{font-size:18px;display:block}
.act-tb{width:100%;border-collapse:collapse;font-size:13px}.act-tb th,.act-tb td{padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.act-tb th{background:#f8fafc;font-size:12px;color:#475569}.act-tb small{color:#94a3b8}
.act-wrap{overflow-x:auto}
</style>
<div class="act-tabs"><?php foreach ($tabs as $k => $t): ?><a href="<?php echo $h($o['url'] . '?tab=' . $k . '&days=' . $days); ?>" class="<?php echo $k === $tab ? 'on' : ''; ?>"><?php echo $h($t['label']); ?></a><?php endforeach; ?></div>
<?php if ($who !== ''):
    $ev = act_events($pdo, $who, $f + ['days' => 0], 400);
    $geo = act_geo($pdo, array_column($ev, 'ip'));
    $nm = ''; foreach ($ev as $e) if ($e['name'] !== '') { $nm = $e['name']; break; }
    $logins = count(array_filter($ev, fn($e) => $e['event'] === 'login'));
?>
<div class="card"><p style="margin-bottom:10px"><a href="<?php echo $h($url([])); ?>">→ بازگشت به فهرست</a></p>
  <h3 style="font-size:15px;margin-bottom:6px"><?php echo $h($nm !== '' ? $nm : 'بازدیدکننده ' . substr($who, 0, 8)); ?> <small style="color:#64748b;font-weight:normal">— <?php echo number_format($logins); ?> ورود، <?php echo number_format(count($ev) - $logins); ?> صفحه (آخرین ۴۰۰ رویداد)</small></h3>
  <?php if (!$ev): ?><p class="muted">رویدادی ثبت نشده است.</p><?php else: ?>
  <div class="act-wrap"><table class="act-tb"><thead><tr><th>زمان</th><th>رویداد</th><th>IP</th><th>شهر و کشور</th><th>دستگاه</th></tr></thead><tbody>
  <?php foreach ($ev as $e): ?><tr><td style="white-space:nowrap"><?php echo $h(act_jdt($e['created_at'])); ?></td><td><?php echo $h(act_event_label($e)); ?><?php if ($e['event'] === 'page' && $e['path'] !== '' && $e['title'] !== ''): ?><br><small dir="ltr"><?php echo $h(mb_strimwidth($e['path'], 0, 70, '…')); ?></small><?php endif; ?></td>
    <td dir="ltr" style="font-size:12px"><?php echo $h($e['ip']); ?></td><td><?php echo $h(act_place($geo, $e['ip'])); ?></td><td style="font-size:12px"><?php echo $h(act_device($e['ua'])); ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php else:
    $st = act_stats($pdo, $T['kinds'], $days, $f['owner'] ?? null);
    $people = act_people($pdo, $f, 300);
    $geo = act_geo($pdo, array_column($people, 'ip'));
    $top = act_top_pages($pdo, $T['kinds'], $days, $f['owner'] ?? null, 10);
?>
<form class="act-f" method="get"><input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
  <select name="days" onchange="this.form.submit()"><?php foreach ([1 => 'امروز', 7 => '۷ روز اخیر', 30 => '۳۰ روز اخیر', 90 => '۹۰ روز اخیر', 180 => '۶ ماه اخیر'] as $d => $l): ?><option value="<?php echo $d; ?>" <?php echo $days === $d ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
  <input type="text" name="q" value="<?php echo $h($q); ?>" placeholder="جستجوی نام یا IP"><button class="btn btn-sm">جستجو</button>
</form>
<div class="act-st"><div><b><?php echo number_format($st['people']); ?></b><?php echo in_array('visitor', $T['kinds'], true) ? 'بازدیدکننده' : 'نفر'; ?></div><div><b><?php echo number_format($st['logins']); ?></b>ورود</div><div><b><?php echo number_format($st['pages']); ?></b>صفحه بازدیدشده</div></div>
<div class="card"><div class="act-wrap">
  <?php if (!$people): ?><p class="muted" style="padding:10px">در این بازه چیزی ثبت نشده است.</p><?php else: ?>
  <table class="act-tb"><thead><tr><th><?php echo in_array('visitor', $T['kinds'], true) ? 'بازدیدکننده' : 'نام'; ?></th><th>ورود</th><th>صفحه</th><th>آخرین فعالیت</th><th>IP آخر</th><th>شهر و کشور</th><th>دستگاه</th><th></th></tr></thead><tbody>
  <?php foreach ($people as $p): ?><tr>
    <td><b><?php echo $h($p['name'] !== '' ? $p['name'] : ($p['kind'] === 'visitor' ? 'ناشناس ' . substr($p['vid'], 0, 6) : '#' . $p['actor_id'])); ?></b><?php if (count($T['kinds']) > 1): ?> <small>(<?php echo $h($kl[$p['kind']] ?? $p['kind']); ?>)</small><?php endif; ?><?php if (!empty($T['label_fn'])): echo ' <small>' . $h(($T['label_fn'])($p)) . '</small>'; endif; ?><br><small><?php echo $h(mb_strimwidth($p['title'], 0, 60, '…')); ?></small></td>
    <td><?php echo number_format($p['logins']); ?></td><td><?php echo number_format($p['pages']); ?></td>
    <td style="white-space:nowrap;font-size:12px"><?php echo $h(act_jdt($p['last'])); ?></td><td dir="ltr" style="font-size:12px"><?php echo $h($p['ip']); ?></td><td><?php echo $h(act_place($geo, $p['ip'])); ?></td><td style="font-size:12px"><?php echo $h(act_device($p['ua'])); ?></td>
    <td><a class="btn btn-sm" href="<?php echo $h($url(['who' => $p['key']])); ?>">جزئیات</a></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div></div>
<?php if ($top): ?><div class="card"><h3 style="font-size:14px;margin-bottom:8px">📄 پربازدیدترین صفحه‌ها</h3><table class="act-tb"><tbody><?php foreach ($top as $t): ?><tr><td><?php echo $h($t['title'] !== '' ? $t['title'] : $t['path']); ?></td><td style="width:90px"><?php echo number_format((int)$t['n']); ?> بار</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if (!empty($GLOBALS['__act_geo_down'])): ?><p class="muted" style="font-size:12px">شهر و کشور فعلاً پیدا نشد (سرویس موقعیت IP در دسترس نبود یا هاست اتصال بیرونی را بسته است).</p><?php endif; ?>
<?php endif;
    return ob_get_clean();
}
