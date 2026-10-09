<?php
/**
 * مدیریت یکپارچه مدل‌های هوش مصنوعی
 * زبانه‌ها: متنی، صوت به متن، متن به صوت، ساخت تصویر، ساخت ویدیو، ربات و ایجنت، تنظیمات قیمت
 * هر مدل: تعریف، مدل‌های جایگزین، قیمت (از سایت مرجع به دلار + درصد سود) و اتصال به بخش‌ها
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/ai_chat_lib.php';

biz_price_schema($pdo);   // ستون قیمت ورودی تصویر (نسخه ۵۳)
mcap_schema3($pdo);       // کارایی، توضیحات، تاریخ حذف، محدودیت درخواست و آمار روزانه مدل‌ها (نسخه ۷۲ تا ۷۵)
$cats = biz_model_categories();
$units = biz_price_units();
$tabs = $cats + ['settings' => '⚙️ تنظیمات قیمت'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'text';
$now = date('Y-m-d H:i:s');
$avalai_only = ['video', 'stt', 'tts'];   // این دسته‌ها فعلاً فقط از طریق AvalAI

/** کاربردهای قابل اتصال هر دسته */
function mdl_uses($cat)
{
    if ($cat === 'text') return ['use_widget' => 'پاسخگوی هوشمند (ویجت)', 'use_bot' => 'چت‌بات تخصصی', 'use_free' => 'چت‌بات رایگان'];
    if ($cat === 'stt') return ['use_bot' => 'پیام صوتی چت‌بات تخصصی'];
    if ($cat === 'tts') return ['use_bot' => 'پخش صوتی پاسخ‌های چت‌بات'];
    return [];
}
function mdl_num($v) { return (float)str_replace([',', '٬', '$', ' '], '', strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.'])); }
function mdl_usd($v) { return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $is_json = in_array($act, ['model_test', 'model_flag', 'model_margin', 'price_lookup', 'price_refresh', 'avalai_check', 'or_check', 'cap_fill', 'lim_fetch'], true);
    $reply = function ($ok, $text, $extra = []) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(array_merge(['ok' => (bool)$ok, 'msg' => (string)$text], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        if ($is_json) $reply(false, 'نشست منقضی شده؛ صفحه را یک بار تازه کنید.');
        header('Location: models.php?msg=' . urlencode('error:نشست منقضی شده؛ دوباره تلاش کنید.')); exit;
    }
    $cat_in = isset($cats[$_POST['category'] ?? '']) ? $_POST['category'] : 'text';
    $back = 'models.php?tab=' . ($act === 'settings_save' || $act === 'usd_now' || $act === 'prices_all' ? 'settings' : $cat_in);
    $msg = '';

    // ---------------- پاسخ‌های JSON (بدون رفرش) ----------------
    // نسخه ۷۲: ساخت/به‌روزرسانی کارایی و تاریخ حذف یک مدل (صفحه فهرست، خودکار برای مدل‌هایی که هنوز ندارند)
    // نسخه ۷۵: دریافت محدودیت درخواست ارائه‌دهنده‌ها (سطح حساب AvalAI + جدول مدل‌ها، وضعیت حساب OpenRouter)
    if ($act === 'lim_fetch') {
        @set_time_limit(90);
        $c = mcap_lim_fetch($pdo, !empty($_POST['force']));
        $o = [];
        foreach (biz_models($pdo, false, $cat_in) as $lm) { $l = mcap_limit($pdo, $lm); $o[(int)$lm['id']] = ['label' => $l['label'], 'sub' => $l['sub'], 'short' => $l['short'] ?? '', 'pd' => is_infinite($l['per_day']) ? 1e15 : $l['per_day']]; }
        $reply(true, $c['err'] ? implode(' | ', $c['err']) : 'به‌روز شد.', ['lims' => $o]);
    }
    if ($act === 'cap_fill') {
        $cm = biz_model_by_id($pdo, (int)($_POST['model_id'] ?? 0));
        if (!$cm || $cm['parent_id']) $reply(false, 'مدل یافت نشد.');
        @set_time_limit(90);
        $x = mcap_refresh($pdo, $cm, true);
        $cm = biz_model_by_id($pdo, (int)$cm['id']);
        $ev = mcap_exp_view($cm['expire_date'] ?? '');
        $reply((bool)$x, $x ? 'به‌روز شد.' : 'ناموفق', ['html' => mcap_cell_html($cm), 'exp' => $ev['label'], 'exp_cls' => $ev['cls'], 'tags' => implode(' · ', (array)($x['tags'] ?? [])), 'desc' => (string)($x['desc'] ?? ''), 'caps' => mcap_list($cm)]);
    }
    if ($act === 'model_test') {
        $prov = ($_POST['provider'] ?? '') === 'openrouter' ? 'openrouter' : 'avalai';
        $mid = mb_substr(trim((string)($_POST['mid'] ?? '')), 0, 150);
        if ($mid === '') $reply(false, 'اول شناسه مدل را وارد کنید.');
        if (!biz_provider_enabled($pdo, $prov)) $reply(false, ($prov === 'openrouter' ? 'OpenRouter' : 'AvalAI') . ' در «تنظیمات API» خاموش است.');
        if ($cat_in === 'image') {
            // نسخه ۶۵: تست واقعی ساخت تصویر (یک تصویر ساده؛ هزینه آن با سامانه است) + نمایش دقیق خطای سرویس‌دهنده
            @set_time_limit(300);
            $t0 = microtime(true);
            $tcfg = saas_get_api_config($pdo);
            $tm = ['id' => 0, 'provider' => $prov, 'model_id' => $mid, 'category' => 'image'];
            $r = biz_image_generate_one($tcfg, $tm, 'A simple red apple on a plain white background, studio photo', '1024x1024');
            $tnote = '';
            if (!empty($r['ref_required'])) {
                // نسخه ۷۰: مدل‌هایی که فقط روی یک تصویر کار می‌کنند (مثل جداسازی لایه‌ها) با یک طرح ساده آزمایشی تست می‌شوند
                $png = biz_test_design_png();
                if ($png !== '') { $r = biz_image_generate_one($tcfg, $tm, 'Separate this design into layers: background, red circle, blue title bar', '1024x1024', ['bytes' => $png, 'mime' => 'image/png']); $tnote = ' — این مدل فقط با تصویر ورودی کار می‌کند و با یک طرح آزمایشی تست شد'; }
            }
            if (!empty($r['ok']) && !empty($r['extra'])) $tnote .= ' — ' . (count($r['extra']) + 1) . ' خروجی (مثلاً لایه) برگرداند';
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $ref = biz_model_by_id($pdo, (int)($_POST['ref'] ?? 0));
            if ($ref && $ref['provider'] === $prov && $ref['model_id'] === $mid) biz_model_mark($pdo, (int)$ref['id'], !empty($r['ok']), $r['error'] ?? '');
            if (!empty($r['ok'])) $reply(true, 'تصویر ساخته شد ✅ (روش: ' . $r['via'] . '، ' . number_format(strlen($r['bytes']) / 1024) . ' کیلوبایت، ' . number_format($ms) . ' میلی‌ثانیه' . $tnote . ')');
            // نسخه ۷۱: شناسه در AvalAI نیست ولی همین مدل در OpenRouter هست → شناسه دقیق را پیشنهاد بده
            if ($prov === 'avalai' && preg_match('/does not exist|model[_ ]not[_ ]found|no such model|unknown model|invalid model/i', (string)($r['error'] ?? '')) && biz_provider_enabled($pdo, 'openrouter') && ($orid = biz_or_img_find($tcfg, $mid)) !== '')
                $reply(false, '⚠ این مدل در AvalAI نیست، ولی در OpenRouter با شناسه «' . $orid . '» موجود است. «سرویس‌دهنده» را OpenRouter و «شناسه مدل» را ' . $orid . ' بگذارید، ذخیره کنید و دوباره تست بزنید.');
            $reply(false, 'ساخت تصویر ناموفق — ' . biz_provider_error_hint($r['error'] ?? '') . 'پاسخ سرویس‌دهنده: ' . mb_substr((string)($r['error'] ?? 'خطای نامشخص'), 0, 600));
        }
        if (!biz_price_is_token_cat($cat_in)) $reply(false, 'تست خودکار برای مدل‌های صوتی/ویدیویی ممکن نیست (هزینه دارد).');
        $t0 = microtime(true);
        $r = biz_provider_chat(saas_get_api_config($pdo), ['id' => 0, 'provider' => $prov, 'model_id' => $mid, 'category' => 'text'], [['role' => 'user', 'content' => 'ping']], 5, 0);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $ref = biz_model_by_id($pdo, (int)($_POST['ref'] ?? 0));
        if ($ref && $ref['provider'] === $prov && $ref['model_id'] === $mid) biz_model_mark($pdo, (int)$ref['id'], !empty($r['ok']), $r['error'] ?? '');
        if (!empty($r['ok'])) $reply(true, 'متصل است (' . number_format($ms) . ' میلی‌ثانیه)');
        $reply(false, 'پاسخ نداد: ' . mb_substr((string)($r['error'] ?? 'خطای نامشخص'), 0, 200));
    }
    if ($act === 'or_check') {
        // بررسی فهرست قیمت OpenRouter (نسخه ۶۳): دریافت فهرست، و برای هر مدل OpenRouter شما: پیدا شد / با چه شناسه‌ای / قیمت
        $or = biz_price_openrouter_catalog($pdo, true);
        $meta = json_decode((string)@file_get_contents(biz_price_cache_file('openrouter_meta')), true) ?: [];
        if (!$or['ok']) $reply(false, ($or['error'] ?: 'فهرست OpenRouter دریافت نشد.') . ' — اگر هاست سایت در ایران است، ممکن است openrouter.ai از هاست در دسترس نباشد؛ در این حالت قیمت این مدل‌ها از AvalAI یا قیمت رسمی سازنده گرفته می‌شود (یا دستی وارد کنید).');
        $lines = [];
        foreach (biz_models($pdo, false, null, true) as $m) {   // نسخه ۶۴: همه مدل‌های شما (هر سرویس‌دهنده)
            $r = biz_price_lookup($pdo, $m['provider'], $m['model_id'], $m['category'] ?? 'text');
            $lines[] = ($m['provider'] === 'openrouter' ? 'OpenRouter' : 'AvalAI') . ' / ' . $m['model_id'] . (($m['price_src'] ?? 'auto') === 'manual' ? ' [قیمت دستی]' : '') . ' → ' . ($r['ok'] && ($r['in'] > 0 || $r['out'] > 0 || $r['unit'] > 0)
                ? $r['src'] . ($r['ref'] !== '' && $r['ref'] !== $m['model_id'] ? ' (' . $r['ref'] . ')' : '') . ' — ورودی ' . mdl_usd($r['in']) . ' / خروجی ' . mdl_usd($r['out']) . ($r['unit'] > 0 ? ' / واحد ' . mdl_usd($r['unit']) : '') . ' دلار'
                : '❌ ' . ($r['error'] ?: 'قیمت پیدا نشد'));
        }
        $reply(true, 'فهرست OpenRouter' . (!empty($or['stale']) ? ' (نسخه ذخیره‌شده قبلی؛ دریافت تازه ناموفق: ' . ($meta['error'] ?? '') . ')' : '') . ': ' . number_format(count($or['items'])) . ' مدل.' . ($lines ? '' : ' مدلی تعریف نکرده‌اید.'), ['sample' => implode("\n", $lines), 'mid' => 'قیمتی که برای هر مدل شما پیدا می‌شود', 'parsed' => null, 'list' => 1]);
    }
    if ($act === 'avalai_check') {
        // بررسی فهرست قیمت AvalAI (نسخه ۵۴): چند مدل، چند مدل با قیمت، و پاسخ خام یک مدل
        $av = biz_price_avalai_catalog($pdo, true);
        if (!$av['ok']) $reply(false, $av['error'] ?: 'فهرست AvalAI دریافت نشد.');
        $mid = trim((string)($_POST['mid'] ?? ''));
        $raw = json_decode((string)@file_get_contents(biz_price_cache_file('avalai_raw')), true);
        $one = $mid !== '' ? ($raw['items'][$mid] ?? null) : null;
        if ($mid !== '' && !$one) foreach ((array)($raw['items'] ?? []) as $id => $x) if (biz_price_norm($id) === biz_price_norm($mid)) { $one = $x; $mid = $id; break; }
        $mine = []; $miss = [];
        foreach (biz_models($pdo, false, null, true) as $m) if ($m['provider'] !== 'openrouter') {
            if (isset($av['items'][$m['model_id']])) $mine[] = $m['model_id']; else $miss[] = $m['model_id'];
        }
        $reply(true, 'فهرست AvalAI: ' . number_format((int)$av['total']) . ' مدل، ' . number_format(count($av['items'])) . ' مدل با قیمت. از مدل‌های شما: ' . count($mine) . ' مدل قیمت دارد' . ($miss ? '؛ بدون قیمت: ' . implode('، ', array_slice(array_unique($miss), 0, 12)) : '') . '.',
            ['sample' => $one !== null ? json_encode($one, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '', 'parsed' => $one !== null ? biz_price_extract($one) : null, 'mid' => $mid]);
    }
    if ($act === 'price_lookup') {
        // دریافت قیمت مرجع برای فرم (قبل از ذخیره)
        $prov = ($_POST['provider'] ?? '') === 'openrouter' ? 'openrouter' : 'avalai';
        $mid = trim((string)($_POST['mid'] ?? ''));
        if ($mid === '') $reply(false, 'اول شناسه مدل را وارد کنید.');
        $r = biz_price_lookup($pdo, $prov, $mid, $cat_in);
        if (!$r['ok'] || ($r['in'] <= 0 && $r['out'] <= 0 && $r['unit'] <= 0)) $reply(false, $r['error'] !== '' ? $r['error'] : 'قیمت پیدا نشد؛ قیمت را دستی وارد کنید.');
        $src = $r['src'] . ($r['ref'] !== '' && $r['ref'] !== $mid ? ' (' . $r['ref'] . ')' : '') . ($r['approx'] ? ' — تقریبی' : '');
        $iw = biz_model_id_warning(['provider' => $prov, 'model_id' => $mid, 'price_note' => $r['src'], 'price_ref' => $r['ref']]);
        $reply(true, 'قیمت از ' . $src . ' دریافت شد.' . ($iw !== '' ? ' ⚠ ' . $iw : ''), ['in' => mdl_usd($r['in']), 'out' => mdl_usd($r['out']), 'img_in' => mdl_usd($r['img_in'] ?? 0), 'unit' => mdl_usd($r['unit']), 'src' => $src]);
    }
    if ($act === 'model_flag') {
        $id = (int)($_POST['model_id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $m = biz_model_by_id($pdo, $id);
        if (!$m || $m['parent_id']) $reply(false, 'مدل یافت نشد.');
        if ($field !== 'is_active' && !isset(mdl_uses($m['category'])[$field])) $reply(false, 'درخواست نامعتبر است.');
        $pdo->prepare("UPDATE saas_ai_models SET `$field`=? WHERE id=?")->execute([!empty($_POST['val']) ? 1 : 0, $id]);
        $reply(true, 'ذخیره شد');
    }
    if ($act === 'model_margin') {
        $id = (int)($_POST['model_id'] ?? 0);
        $m = biz_model_by_id($pdo, $id);
        if (!$m || $m['parent_id']) $reply(false, 'مدل یافت نشد.');
        $raw = trim((string)($_POST['margin'] ?? ''));
        $mg = $raw === '' ? -1 : max(0, min(1000, round(mdl_num($raw), 2)));
        $pdo->prepare("UPDATE saas_ai_models SET margin=? WHERE id=? OR parent_id=?")->execute([$mg, $id, $id]);
        $m = biz_model_by_id($pdo, $id);
        $p = biz_model_price_html($pdo, $m);
        $reply(true, 'ذخیره شد', ['toman' => $p['toman'], 'unit' => $p['unit']]);
    }
    if ($act === 'price_refresh') {
        $m = biz_model_by_id($pdo, (int)($_POST['model_id'] ?? 0));
        if (!$m) $reply(false, 'مدل یافت نشد.');
        $r = biz_model_refresh_price($pdo, $m, true);
        foreach (biz_model_children($pdo, $m) as $c) biz_model_refresh_price($pdo, $c);
        $m = biz_model_by_id($pdo, (int)$m['id']);
        $p = biz_model_price_html($pdo, $m);
        $reply($r['ok'], $r['msg'], ['toman' => $p['toman'], 'usd' => $p['usd']]);
    }

    // ---------------- فرم‌ها ----------------
    if ($act === 'settings_save') {
        $rate = (int)mdl_num($_POST['usd_rate'] ?? 0);
        biz_set($pdo, 'usd_auto', !empty($_POST['usd_auto']) ? 1 : 0);
        biz_set($pdo, 'usd_margin', max(0, min(50, mdl_num($_POST['usd_margin'] ?? 0))));
        if ($rate > 0) biz_set($pdo, 'usd_rate', $rate);
        biz_set($pdo, 'model_margin', max(0, min(1000, mdl_num($_POST['model_margin'] ?? 30))));
        if (isset($_POST['member_markup'])) biz_set($pdo, 'member_markup', max(0, min(1000, mdl_num($_POST['member_markup']))));
        biz_set($pdo, 'credit_usd_1k', 0);
        $pdo->prepare("UPDATE saas_api_config SET cost_per_1k_tokens=? WHERE id=1")->execute([max(0, mdl_num($_POST['cost_per_1k'] ?? 1000))]);
        biz_set($pdo, 'image_fallback_toman', max(0, (int)mdl_num($_POST['image_fallback_toman'] ?? 0)));
        biz_set($pdo, 'video_fallback_toman', max(0, (int)mdl_num($_POST['video_fallback_toman'] ?? 0)));
        biz_set($pdo, 'hd_voice_cost', max(0, (int)mdl_num($_POST['hd_voice_cost'] ?? 0)));
        biz_set($pdo, 'hd_tts_cost', max(0, (int)mdl_num($_POST['hd_tts_cost'] ?? 0)));
        $sys = (int)($_POST['sys_model_id'] ?? 0);
        biz_set($pdo, 'sys_model_id', $sys > 0 && biz_model_by_id($pdo, $sys) ? $sys : 0);
        $trm = (int)($_POST['gen_tr_model_id'] ?? 0);
        biz_set($pdo, 'gen_tr_model_id', $trm > 0 && biz_model_by_id($pdo, $trm) ? $trm : 0);
        $pu = trim((string)($_POST['avalai_price_url'] ?? ''));
        biz_set($pdo, 'avalai_price_url', preg_match('#^https?://[^\s]+$#i', $pu) ? mb_substr($pu, 0, 300) : '');
        $trmode = (string)($_POST['gen_tr_mode'] ?? 'auto');
        biz_set($pdo, 'gen_tr_mode', in_array($trmode, ['auto', 'always', 'never'], true) ? $trmode : 'auto');
        // مدل پشتیبان نهایی (وقتی هیچ مدلی تعریف/فعال نیست)
        $am = preg_replace('/[^A-Za-z0-9._:\-\/]/', '', (string)($_POST['avalai_model'] ?? ''));
        $om = preg_replace('/[^A-Za-z0-9._:\-\/]/', '', (string)($_POST['openrouter_model'] ?? ''));
        $pp = ($_POST['provider_priority'] ?? '') === 'openrouter' ? 'openrouter' : 'avalai';
        $pdo->prepare("UPDATE saas_api_config SET avalai_model=?, openrouter_model=?, provider_priority=? WHERE id=1")->execute([$am !== '' ? $am : 'gpt-4o-mini', $om !== '' ? $om : 'google/gemini-2.5-flash', $pp]);
        $msg = 'ok:ذخیره شد.';
    } elseif ($act === 'usd_now') {
        $r = biz_update_usd($pdo, true);
        $msg = $r['ok'] ? 'ok:نرخ دلار به‌روز شد: ' . number_format($r['rate']) . ' تومان' : 'error:دریافت نرخ دلار ممکن نشد: ' . $r['error'];
    } elseif ($act === 'prices_all') {
        $r = biz_models_refresh_prices($pdo, true);
        $msg = ($r['fail'] ? 'error:' : 'ok:') . 'قیمت ' . $r['ok'] . ' مدل به‌روز شد' . ($r['fail'] ? '؛ ' . $r['fail'] . ' مدل قیمت پیدا نکرد (قیمت آن‌ها را دستی وارد کنید).' : '.');
    } elseif ($act === 'model_save') {
        $id = (int)($_POST['model_id'] ?? 0);
        // «ذخیره به‌عنوان مدل جدید» (نسخه ۵۸): مدل در حال ویرایش دست نمی‌خورد و یک مدل تازه با همین مشخصات ساخته می‌شود
        $as_new = $id > 0 && !empty($_POST['as_new']);
        if ($as_new) { $id = 0; unset($_POST['fb_id']); }
        $old = $id ? biz_model_by_id($pdo, $id) : null;
        if ($id && (!$old || $old['parent_id'])) { header('Location: ' . $back . '&msg=' . urlencode('error:مدل یافت نشد.')); exit; }
        $cat = $cat_in;
        $tok = biz_price_is_token_cat($cat);
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
        $mid = mb_substr(trim($_POST['mid'] ?? ''), 0, 150);
        $prov = ($_POST['provider'] ?? '') === 'openrouter' ? 'openrouter' : 'avalai';
        $src = ($_POST['price_src'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
        $u_in = max(0, mdl_num($_POST['usd_in'] ?? 0));
        $u_out = max(0, mdl_num($_POST['usd_out'] ?? 0));
        $u_unit = max(0, mdl_num($_POST['usd_unit'] ?? 0));
        $u_img = max(0, mdl_num($_POST['usd_img_in'] ?? 0));
        $imgtok = $cat === 'image';   // تصویر: قیمت توکنی (ورودی متن/تصویر، خروجی) + قیمت هر تصویر برای حالت بدون گزارش توکن
        $mraw = trim((string)($_POST['margin'] ?? ''));
        $margin = $mraw === '' ? -1 : max(0, min(1000, round(mdl_num($mraw), 2)));
        $uses = mdl_uses($cat);
        $flag = fn($k) => isset($uses[$k]) && !empty($_POST[$k]) ? 1 : 0;

        // ردیف‌های مدل جایگزین
        $fb_rows = [];
        $seen = [$prov . '|' . mb_strtolower($mid)];
        $fb_err = '';
        foreach ((array)($_POST['fb_mid'] ?? []) as $i => $fm) {
            $fm = mb_substr(trim((string)$fm), 0, 150);
            if ($fm === '') continue;
            $fp = (($_POST['fb_provider'][$i] ?? '') === 'openrouter') ? 'openrouter' : 'avalai';
            if (in_array($cat, $avalai_only, true) && $fp !== 'avalai') { $fb_err = 'مدل‌های این دسته (و جایگزین‌هایشان) فعلاً فقط از طریق AvalAI پشتیبانی می‌شوند.'; break; }
            $key = $fp . '|' . mb_strtolower($fm);
            if (in_array($key, $seen, true)) continue;
            $seen[] = $key;
            $fi = max(0, mdl_num($_POST['fb_in'][$i] ?? 0)); $fo = max(0, mdl_num($_POST['fb_out'][$i] ?? 0)); $fu = max(0, mdl_num($_POST['fb_unit'][$i] ?? 0));
            $manual = $tok ? ($fi > 0 || $fo > 0) : $fu > 0;
            $fb_rows[] = ['id' => (int)($_POST['fb_id'][$i] ?? 0), 'provider' => $fp, 'model_id' => $fm, 'in' => $fi, 'out' => $fo, 'unit' => $fu, 'src' => $manual ? 'manual' : 'auto'];
        }

        if (mb_strlen($title) < 2 || $mid === '') $msg = 'error:نام نمایشی و شناسه مدل را وارد کنید.';
        elseif (in_array($cat, $avalai_only, true) && $prov !== 'avalai') $msg = 'error:این دسته فعلاً فقط از طریق AvalAI پشتیبانی می‌شود.';
        elseif ($fb_err !== '') $msg = 'error:' . $fb_err;
        elseif ($src === 'manual' && ($tok ? ($u_in <= 0 && $u_out <= 0) : ($imgtok ? ($u_out <= 0 && $u_unit <= 0) : $u_unit <= 0))) $msg = 'error:در حالت «قیمت دستی» قیمت دلاری را وارد کنید.';
        else {
            try {
                $v = [$title, $prov, $mid, $cat, mb_substr(trim($_POST['description'] ?? ''), 0, 300), (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0,
                      $flag('use_widget'), $flag('use_bot'), $flag('use_free'), $src, $margin];
                // نسخه ۷۲: کارایی دستی (خالی = خودکار از روی توضیح رسمی مدل)
                // نسخه ۷۴: کارایی دستی فقط از واژه‌نامه ثابت (تیک‌ها) تا فیلتر یکسان بماند؛ حالت «خودکار» = از روی توضیح رسمی مدل
                $cap_in = ($_POST['cap_mode'] ?? 'auto') === 'manual' ? implode(' | ', mcap_canon((array)($_POST['cap_sel'] ?? []), $cat)) : '';
                if ($cap_in === '' && !isset($_POST['cap_mode']) && trim((string)($_POST['cap_text'] ?? '')) !== '') $cap_in = implode(' | ', mcap_canon(explode(' | ', mcap_clean_text((string)$_POST['cap_text'])), $cat));   // سازگاری با فرم قدیمی
                $cap_changed = !$id || $old['provider'] !== $prov || $old['model_id'] !== $mid;
                if ($id) {
                    $reset = ($old['provider'] !== $prov || $old['model_id'] !== $mid) ? ", down_until=NULL, fail_count=0, last_error=''" : '';
                    if ($reset !== '') biz_set($pdo, 'img_err_' . $id, '');   // نسخه ۶۸: خطای ساخت ثبت‌شده با تنظیمات قبلی پاک شود
                    $pdo->prepare("UPDATE saas_ai_models SET title=?, provider=?, model_id=?, category=?, description=?, sort_order=?, is_active=?, use_widget=?, use_bot=?, use_free=?, price_src=?, margin=?$reset WHERE id=?")->execute(array_merge($v, [$id]));
                    $msg = 'ok:تغییرات مدل «' . $title . '» ذخیره شد.';
                } else {
                    $pdo->prepare("INSERT INTO saas_ai_models (title, provider, model_id, category, description, sort_order, is_active, use_widget, use_bot, use_free, price_src, margin, multiplier, fallbacks, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,1,'',?)")->execute(array_merge($v, [$now]));
                    $id = (int)$pdo->lastInsertId();
                    $msg = 'ok:مدل «' . $title . '» ' . ($as_new ? 'به‌عنوان مدل جدید ' : '') . 'اضافه شد. برای افزودن مدل بعدی، فرم «افزودن مدل» را دوباره پر کنید.';
                }
                $pdo->prepare("UPDATE saas_ai_models SET req_limit=? WHERE id=?")->execute([mb_substr(trim((string)($_POST['req_limit'] ?? '')), 0, 80), $id]);   // نسخه ۷۵: محدودیت دستی (خالی = خودکار)
                if ($cap_in !== '') $pdo->prepare("UPDATE saas_ai_models SET cap_text=?, cap_src='manual'" . ($cap_changed ? ", cap_meta='', expire_date='', cap_desc=NULL, cap_desc_en=NULL, cap_at=NULL" : '') . " WHERE id=?")->execute([$cap_in, $id]);
                elseif ($cap_changed || ($old['cap_src'] ?? 'auto') === 'manual') $pdo->prepare("UPDATE saas_ai_models SET cap_text='', cap_src='auto', cap_meta='', expire_date='', cap_desc=NULL, cap_desc_en=NULL, cap_at=NULL WHERE id=?")->execute([$id]);   // خودکار دوباره ساخته می‌شود
                if ($src === 'manual') {
                    $pdo->prepare("UPDATE saas_ai_models SET usd_in=?, usd_out=?, usd_unit=?, usd_img_in=?, price_note=?, price_at=? WHERE id=?")
                        ->execute([($tok || $imgtok) ? $u_in : 0, ($tok || $imgtok) ? $u_out : 0, $tok ? 0 : $u_unit, $imgtok ? $u_img : 0, 'قیمت دستی', $now, $id]);
                }
                if ($imgtok) biz_set($pdo, 'img_avg_' . $id, 0);   // میانگین هزینه تصویر با قیمت تازه از نو حساب شود
                // مدل‌های جایگزین (زیرمجموعه همین مدل؛ درصد سود از مدل اصلی)
                $existing = [];
                foreach (biz_models($pdo, false, null, true) as $m) if ($m['parent_id'] === $id) $existing[(int)$m['id']] = $m;
                $order = [];
                foreach ($fb_rows as $row) {
                    $ex = $existing[$row['id']] ?? null;
                    if ($ex && !in_array((int)$ex['id'], $order, true)) {
                        $reset = ($ex['provider'] !== $row['provider'] || $ex['model_id'] !== $row['model_id']) ? ", down_until=NULL, fail_count=0, last_error=''" : '';
                        $pdo->prepare("UPDATE saas_ai_models SET title=?, provider=?, model_id=?, category=?, margin=?, price_src=?, is_active=1$reset WHERE id=?")
                            ->execute([$row['model_id'], $row['provider'], $row['model_id'], $cat, $margin, $row['src'], (int)$ex['id']]);
                        $cid = (int)$ex['id'];
                    } else {
                        $pdo->prepare("INSERT INTO saas_ai_models (title, provider, model_id, category, multiplier, fallbacks, description, sort_order, is_active, use_widget, use_bot, use_free, parent_id, margin, price_src, created_at) VALUES(?,?,?,?,1,'','',0,1,0,0,0,?,?,?,?)")
                            ->execute([$row['model_id'], $row['provider'], $row['model_id'], $cat, $id, $margin, $row['src'], $now]);
                        $cid = (int)$pdo->lastInsertId();
                    }
                    if ($row['src'] === 'manual') {
                        $pdo->prepare("UPDATE saas_ai_models SET usd_in=?, usd_out=?, usd_unit=?, price_note=?, price_at=? WHERE id=?")
                            ->execute([$tok ? $row['in'] : 0, $tok ? $row['out'] : 0, $tok ? 0 : $row['unit'], 'قیمت دستی', $now, $cid]);
                    }
                    $order[] = $cid;
                }
                foreach ($existing as $eid => $ex) if (!in_array($eid, $order, true)) $pdo->prepare("DELETE FROM saas_ai_models WHERE id=? AND parent_id=?")->execute([$eid, $id]);
                $pdo->prepare("UPDATE saas_ai_models SET fallbacks=? WHERE id=?")->execute([implode(',', $order), $id]);

                // قیمت خودکار از سایت مرجع
                $warn = [];
                $main = biz_model_by_id($pdo, $id);
                if ($src === 'auto') {
                    $pr = biz_model_refresh_price($pdo, $main);
                    if (!$pr['ok']) $warn[] = 'قیمت «' . $mid . '»: ' . $pr['msg'];
                    elseif (($iw = biz_model_id_warning(biz_model_by_id($pdo, $id))) !== '') $warn[] = $iw;   // نسخه ۶۷: شناسه در فهرست سرویس‌دهنده نیست
                }
                foreach (biz_model_children($pdo, $main) as $c) {
                    if (($c['price_src'] ?? 'auto') !== 'auto') continue;
                    $pr = biz_model_refresh_price($pdo, $c);
                    if (!$pr['ok']) $warn[] = 'قیمت «' . $c['model_id'] . '»: ' . $pr['msg'];
                }
                if ($order) $msg .= ' (' . count($order) . ' مدل جایگزین)';
                if ($warn) $msg = 'error:' . preg_replace('/^ok:/', '', $msg) . ' — ⚠ ' . implode(' | ', $warn);
                // پس از ذخیره، فرم «افزودن مدل» خالی نمایش داده می‌شود تا مدل بعدی به‌جای مدل قبلی ثبت نشود (تعداد مدل‌ها محدودیتی ندارد)؛
                // فقط اگر هشدار قیمت بود، همان مدل برای اصلاح باز می‌ماند
                $back .= $warn ? '&edit=' . $id : '&hl=' . $id;
            } catch (\Throwable $e) {
                error_log('[models] save: ' . $e->getMessage());
                $msg = 'error:ذخیره انجام نشد. اگر تازه فایل‌ها را آپلود کرده‌اید، یک بار صفحه را تازه کنید و دوباره ذخیره کنید.';
            }
        }
        if (str_starts_with($msg, 'error:') && $id && strpos($back, '&edit=') === false) $back .= '&edit=' . $id;
    } elseif ($act === 'model_del') {
        $id = (int)($_POST['model_id'] ?? 0);
        $pdo->prepare("DELETE FROM saas_ai_models WHERE id=?")->execute([$id]);
        try { $pdo->prepare("DELETE FROM saas_ai_models WHERE parent_id=?")->execute([$id]); } catch (\Throwable $e) {}
        $strip = fn($csv) => implode(',', array_values(array_filter(array_map('intval', explode(',', (string)$csv)), fn($x) => $x > 0 && $x !== $id)));
        foreach (biz_models($pdo, false) as $m) {
            $n = $strip($m['fallbacks']);
            if ($n !== (string)$m['fallbacks']) $pdo->prepare("UPDATE saas_ai_models SET fallbacks=? WHERE id=?")->execute([$n, (int)$m['id']]);
        }
        if (function_exists('mx_schema')) mx_schema($pdo);
        foreach ($pdo->query("SELECT * FROM saas_plans")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $n = $strip($p['allowed_models']);
            $nb = $strip($p['blocked_models'] ?? '');
            if ($n !== (string)$p['allowed_models'] || (int)$p['default_model'] === $id)
                $pdo->prepare("UPDATE saas_plans SET allowed_models=?, default_model=? WHERE id=?")->execute([$n, (int)$p['default_model'] === $id ? 0 : (int)$p['default_model'], (int)$p['id']]);
            if (array_key_exists('blocked_models', $p) && $nb !== (string)$p['blocked_models']) $pdo->prepare("UPDATE saas_plans SET blocked_models=? WHERE id=?")->execute([$nb, (int)$p['id']]);
        }
        if ((int)biz_get($pdo, 'sys_model_id') === $id) biz_set($pdo, 'sys_model_id', 0);
        $msg = 'ok:مدل حذف شد.';
    } elseif ($act === 'model_ping') {
        $m = biz_model_by_id($pdo, (int)($_POST['model_id'] ?? 0));
        if ($m) {
            $r = biz_model_ping($pdo, saas_get_api_config($pdo), $m);
            $msg = $r['ok'] ? 'ok:«' . $m['title'] . '» در دسترس است' : 'error:«' . $m['title'] . '» پاسخ نداد: ' . $r['error'];
        }
    } elseif ($act === 'model_reset') {
        $pdo->prepare("UPDATE saas_ai_models SET down_until=NULL, fail_count=0 WHERE id=?")->execute([(int)($_POST['model_id'] ?? 0)]);
        $msg = 'ok:بازنشانی شد؛ درخواست بعدی دوباره با این مدل امتحان می‌شود.';
    }
    header('Location: ' . $back . '&msg=' . urlencode($msg)); exit;
}
$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

$bs = biz_settings($pdo, true);
$cfg = saas_get_api_config($pdo);
$rate = biz_price_rate($pdo);
$def_margin = (float)$bs['model_margin'];
$all_rows = [];
foreach (biz_models($pdo, false, null, true) as $m) $all_rows[(int)$m['id']] = $m;
$edit = !empty($_GET['edit']) ? ($all_rows[(int)$_GET['edit']] ?? null) : null;
if ($edit && $edit['parent_id']) $edit = $all_rows[$edit['parent_id']] ?? null;
if ($edit && $tab !== 'settings' && $edit['category'] !== $tab) $tab = $edit['category'];
$prov_on = ['avalai' => !empty($cfg['avalai_enabled']), 'openrouter' => !empty($cfg['openrouter_enabled'])];
$prov_name = ['avalai' => 'AvalAI', 'openrouter' => 'OpenRouter'];

include __DIR__ . '/_header.php';
echo '<h2 class="page-title">🧠 مدل‌ها و قیمت‌ها</h2>';
?>
<style>
tr.m-hl td{background:#ecfdf5 !important;transition:background 2s}
/* نسخه ۷۳: ردیف اول = مقایسه سریع؛ کلیک روی ردیف → ردیف دوم (ارائه‌دهنده، توضیحات کامل، حذف) */
.mtbl td{vertical-align:top;font-size:12.5px}
.mtbl tr.mrow{cursor:pointer}
.mtbl tr.mrow:hover td{background:#f8fafc}
.mtbl tr.mrow.open td{background:#f5f3ff;border-bottom:none}
.mtbl .arr{display:inline-block;color:#94a3b8;margin-left:5px;transition:transform .15s}
.mtbl tr.mrow.open .arr{transform:rotate(-90deg);color:#7c3aed}
.mtbl .c-name{min-width:120px}
.mtbl .c-mid{font-size:11.5px;color:#475569;min-width:110px;max-width:190px;overflow-wrap:anywhere;text-align:left}
.mtbl .c-mg{white-space:nowrap}
.mtbl .c-cap{min-width:230px;max-width:380px}
.mtbl .caps{display:flex;flex-wrap:wrap;gap:4px}
.mtbl .caps .cp{background:#eef2ff;color:#3730a3;border-radius:999px;padding:2px 9px;font-size:11.5px;white-space:nowrap}
.mtbl .caps .cs{background:#f1f5f9;color:#475569;border-radius:999px;padding:2px 8px;font-size:11px;white-space:nowrap}
.mtbl .caps .cs.warn{background:#fee2e2;color:#991b1b;font-weight:700}
.mtbl .caps .cn{color:#94a3b8;font-size:11px}
.mtbl .sysm{font-size:11px;color:#6d28d9;margin-top:2px}
.mtbl .issue{display:inline-block;font-size:11px;color:#b45309;background:#fef3c7;border-radius:8px;padding:1px 7px;margin-top:3px}
.mtbl .c-mg .mgin{width:62px !important;padding:4px 6px !important;display:inline-block}
.mtbl .exp{font-size:12px}.mtbl .exp.none{color:#94a3b8}.mtbl .exp.ok{color:#334155}.mtbl .exp.soon{color:#b45309;font-weight:700}.mtbl .exp.gone{color:#dc2626;font-weight:700}
.mtbl .c-req,.mtbl .c-exp{white-space:nowrap}
.mtbl .c-req .pnote{white-space:nowrap;max-width:none}
.mtbl .c-req .lim{font-weight:700;color:#0f766e}.mtbl .c-req .sep{color:#94a3b8;margin:0 2px}
.mtbl .c-req .limsub{white-space:normal !important;max-width:210px !important;color:#94a3b8;font-size:10.5px}
.mtbl .c-chain{min-width:140px}
.mtbl tr.mdet td{background:#faf9ff;border-top:1px dashed #ddd6fe;padding:0}
.mtbl .mdet-in{padding:12px 16px;display:flex;flex-direction:column;gap:10px;cursor:default}
.mtbl .dcols{display:grid;grid-template-columns:150px 1fr auto;gap:18px;align-items:start}
.mtbl .dl{color:#64748b;font-weight:700;font-size:12px;margin-bottom:4px}
.mtbl .pv{font-weight:700;font-size:13.5px;color:#1e293b}
.mtbl .dtx{font-size:13px;line-height:2;color:#1e293b;max-width:900px}
.mtbl .den{font-size:12px;color:#475569;margin-top:4px}.mtbl .den summary{cursor:pointer;color:#6d28d9}
.mtbl .den div{text-align:left;line-height:1.7;margin-top:4px}
.mtbl .dact{display:flex;flex-direction:column;gap:6px}
.mtbl .dmore{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:12px;border-top:1px solid #ede9fe;padding-top:8px}
.mtbl .dmore .pnote{margin-top:0;display:inline}
@media (max-width:900px){.mtbl .dcols{grid-template-columns:1fr}}
/* نسخه ۷۴: مرتب‌سازی و فیلتر */
.mtools{display:flex;flex-direction:column;gap:8px;margin:0 0 10px}
.msort{display:flex;gap:8px;align-items:center;font-size:13px}.msort label{margin:0;white-space:nowrap}.msort select{width:auto !important;min-width:220px;max-width:320px;padding:5px 8px !important}
.mfilt{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.mfilt .fl{font-size:12.5px;color:#475569;font-weight:700;margin-left:4px}
.mfilt .fcap{border:1px solid #c7d2fe;background:#fff;color:#3730a3;border-radius:999px;padding:3px 10px;font-size:12px;font-family:inherit;cursor:pointer}
.mfilt .fcap small{color:#94a3b8;margin-right:3px}
.mfilt .fcap.on{background:#4f46e5 !important;color:#fff !important;border-color:#4f46e5 !important;opacity:1 !important}.mfilt .fcap.on small{color:#e0e7ff}
.mfilt .fclr{border:0;background:#fee2e2;color:#b91c1c;border-radius:999px;padding:3px 10px;font-size:12px;font-family:inherit;cursor:pointer}
.mfilt .fres{font-size:12px;color:#6d28d9;font-weight:700}
.mtbl th.srt{cursor:pointer;user-select:none;white-space:nowrap}
.mtbl th.srt:after{content:' ⇅';color:#cbd5e1;font-size:11px}
.mtbl th.srt.asc:after{content:' ▲';color:#4f46e5}.mtbl th.srt.desc:after{content:' ▼';color:#4f46e5}
.mtbl .caps .cp{cursor:pointer}.mtbl .caps .cp:hover{background:#c7d2fe}.mtbl .caps .cp.on{background:#4f46e5;color:#fff}
.mtbl .caps .cp.warn{background:#fee2e2;color:#991b1b;font-weight:700}
.capsel{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12.5px;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px}
.capsel label{display:flex;gap:4px;align-items:center;font-weight:normal;margin:0}
.capsel.off{opacity:.45;pointer-events:none}
.mtabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px;border-bottom:2px solid #ede9fe;padding-bottom:0}
.mtabs a{padding:9px 14px;border-radius:10px 10px 0 0;font-size:13.5px;font-weight:600;color:#475569;text-decoration:none;background:#f8fafc;border:1px solid #e2e8f0;border-bottom:none;margin-bottom:-2px}
.mtabs a.on{background:#fff;color:#6d28d9;border-color:#c4b5fd;border-bottom:2px solid #fff}
.mtabs a .n{display:inline-block;min-width:18px;padding:0 5px;margin-right:4px;border-radius:9px;background:#ede9fe;color:#6d28d9;font-size:11px;text-align:center}
.mchain{display:flex;flex-wrap:wrap;gap:4px;align-items:center;font-size:11.5px}
.mchain span{padding:2px 8px;border-radius:10px;background:#f1f5f9;color:#475569;white-space:nowrap}
.mchain span.on{background:#dcfce7;color:#166534;font-weight:700}
.mchain span.down{background:#fee2e2;color:#991b1b;text-decoration:line-through}
.mchain span.off{background:#f1f5f9;color:#94a3b8;text-decoration:line-through}
.mchain i{color:#94a3b8;font-style:normal}
.usechk{display:flex;gap:16px;flex-wrap:wrap;margin-top:6px}
.usechk label{display:flex;gap:6px;align-items:center;font-weight:500;margin:0}
.usechk input,.inl input[type=checkbox],.tchk input{width:17px!important;height:17px;margin:0}
.midrow{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.midrow input{flex:1;min-width:160px}
.pricebox{margin-top:14px;padding:12px 14px;border:1px solid #bae6fd;border-radius:10px;background:#f0f9ff}
.pricebox .modes{display:flex;gap:18px;flex-wrap:wrap;margin:4px 0 8px}
.pricebox .modes label{display:flex;gap:6px;align-items:center;margin:0;font-weight:600}
.pricebox .modes input{width:16px!important;height:16px}
.pricebox .final{margin-top:8px;padding:8px 12px;background:#fff;border-radius:8px;font-size:13px;line-height:2}
.pricebox .final b{color:#0f766e}
.usd-in{direction:ltr}
.fbbox{margin-top:14px;padding:12px 14px;border:1px dashed #c4b5fd;border-radius:10px;background:#faf8ff}
.fbrow{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px;padding:8px;background:#fff;border:1px solid #ede9fe;border-radius:8px}
.fbrow .fbn{min-width:22px;height:22px;border-radius:50%;background:#ede9fe;color:#6d28d9;font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}
.fbrow select{width:120px!important;flex:0 0 auto}
.fbrow .fbmid{flex:1;min-width:170px}
.fbrow .fbp{width:92px!important;flex:0 0 auto}
.fbrow .fbnote{flex-basis:100%;font-size:11.5px;color:#64748b}
.fbrow .pg-flash{flex-basis:100%;justify-content:flex-start;margin:4px 0 0}
.btn-test{background:#e0f2fe!important;color:#0369a1!important;white-space:nowrap}
.tchk{display:flex;flex-direction:column;gap:5px;font-size:11.5px;white-space:nowrap}
.tchk label{display:flex;gap:6px;align-items:center;margin:0;font-weight:500;cursor:pointer}
.tchk .pg-flash{margin:2px 0;font-size:11px;padding:1px 8px}
.mgin{width:70px!important;padding:5px 6px!important;text-align:center;direction:ltr}
.pnote{font-size:11px;color:#64748b;margin-top:3px;max-width:230px;white-space:normal}
.pnote.err{color:#b45309}
.provoff{display:inline-block;margin-top:4px;font-size:11px;color:#991b1b;background:#fee2e2;border-radius:8px;padding:1px 8px}
table td{vertical-align:top}
</style>
<?php if ($msg !== ''): ?>
<div class="msg-<?php echo str_starts_with($msg, 'ok:') ? 'ok' : 'err'; ?>"><?php echo admin_h(preg_replace('/^(ok|error):/', '', $msg)); ?></div>
<?php endif; ?>

<nav class="mtabs">
<?php foreach ($tabs as $tk => $tl): $cnt = $tk === 'settings' ? 0 : count(array_filter($all_rows, fn($m) => $m['category'] === $tk && !$m['parent_id'])); ?>
    <a href="models.php?tab=<?php echo $tk; ?>" class="<?php echo $tab === $tk ? 'on' : ''; ?>"><?php echo $tl; ?><?php if ($cnt): ?><span class="n"><?php echo $cnt; ?></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>

<?php if ($rate <= 0 && $tab !== 'settings'): ?>
<div class="msg-err">⚠️ نرخ دلار هنوز تعیین نشده؛ قیمت تومانی مدل‌ها حساب نمی‌شود. از زبانه <a href="models.php?tab=settings">⚙️ تنظیمات قیمت</a> نرخ دلار را وارد یا دریافت کنید.</div>
<?php endif; ?>

<?php if ($tab === 'settings'):
    $text_models = array_filter($all_rows, fn($m) => $m['category'] === 'text' && !$m['parent_id']);
    $sysm = (int)$bs['sys_model_id'];
    $hs = function_exists('hd_sys') ? hd_sys($pdo) : ['hd_voice_cost' => 300, 'hd_tts_cost' => 800];
?>
<form method="post" data-pg="settings">
    <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
    <input type="hidden" name="act" value="settings_save">
    <div class="card">
        <h3 style="font-size:15px;margin-bottom:6px">💵 نرخ دلار</h3>
        <p class="muted" style="line-height:2;margin-bottom:10px">قیمت همه مدل‌ها در سایت مرجع به دلار است و با این نرخ به تومان تبدیل می‌شود.</p>
        <div class="row2">
            <div><label>نرخ دلار (تومان)</label><input type="text" name="usd_rate" dir="ltr" value="<?php echo (int)$bs['usd_rate'] ?: ''; ?>" placeholder="مثلاً 95000"></div>
            <div><label>درصد افزوده روی نرخ دلار (حاشیه اطمینان نوسان)</label><input type="number" step="0.5" min="0" max="50" name="usd_margin" value="<?php echo (float)$bs['usd_margin']; ?>"></div>
        </div>
        <label class="inl" style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="usd_auto" value="1" <?php echo !empty($bs['usd_auto']) ? 'checked' : ''; ?>> به‌روزرسانی خودکار نرخ دلار هر ۶ ساعت (از سایت tgju.org — نرخ آزاد)</label>
        <p class="muted" style="margin-top:4px">آخرین به‌روزرسانی: <?php echo $bs['usd_updated'] ? biz_jdate($bs['usd_updated'], true) : '—'; ?><?php if ($bs['usd_error'] !== ''): ?><br><span style="color:#dc2626">⚠️ <?php echo admin_h($bs['usd_error']); ?></span><?php endif; ?></p>
    </div>

    <div class="card">
        <h3 style="font-size:15px;margin-bottom:6px">📈 قیمت‌گذاری مدل‌ها</h3>
        <p class="muted" style="line-height:2;margin-bottom:10px">
            قیمت نهایی هر مدل = <b>قیمت دلاری سایت مرجع × نرخ دلار × (۱ + درصد سود)</b>.
            قیمت هر مدل <b>فقط از همان سرویس‌دهنده‌ای که مدل از آن گرفته می‌شود</b> خوانده می‌شود (مدل‌های AvalAI از فهرست قیمت AvalAI، مدل‌های OpenRouter از OpenRouter) و <b>روزی یک بار</b> خودکار به‌روز می‌شود.
            اگر AvalAI قیمت مدلی را نداشت، آن مدل را نداشت یا فهرست قیمتش دریافت نشد، قیمت از <b>OpenRouter</b> یا <b>قیمت رسمی ارائه‌دهنده اصلی</b> گرفته می‌شود (برای مدل‌های تصویری اول قیمت توکنی رسمی سازنده) و منبع آن کنار مدل با عبارت «جایگزین» نوشته می‌شود.
            هر مدل می‌تواند درصد سود مخصوص خودش را داشته باشد؛ اگر خالی بماند همین درصد پیش‌فرض حساب می‌شود.
        </p>
        <div class="row2">
            <div><label>درصد سود پیش‌فرض</label><input type="number" step="0.5" min="0" name="model_margin" value="<?php echo $def_margin; ?>"></div>
            <div><label>آخرین به‌روزرسانی قیمت‌ها</label><div style="padding:9px 0;font-weight:600"><?php echo $bs['price_updated'] ? biz_jdate($bs['price_updated'], true) : '—'; ?></div></div>
        </div>
        <div class="row2" style="margin-top:8px">
            <div><label>درصد اضافه قیمت برای مخاطبان چت‌بات‌ها (بسته‌های شارژ تومانی)</label><input type="number" step="0.5" min="0" name="member_markup" value="<?php echo function_exists('mcr_markup') ? mcr_markup($pdo) : 10; ?>"></div>
            <div class="muted" style="font-size:12px;line-height:1.9;padding-top:6px">هزینه هر پاسخ/تصویر/ویدیو برای کاربر (صاحب چت‌بات) = قیمت بالا (با سود شما). برای مخاطبانی که از صاحب چت‌بات «شارژ تومانی» خریده‌اند، همان هزینه + این درصد از شارژشان کم می‌شود (پیش‌فرض ۱۰٪؛ برای همه چت‌بات‌ها یکسان).</div>
        </div>
        <details style="margin-top:10px"><summary style="cursor:pointer;font-weight:600;font-size:13px">🔍 بررسی فهرست قیمت AvalAI و OpenRouter</summary>
            <p class="muted" style="line-height:2;margin:8px 0">قیمت‌ها با کلید AvalAI از فهرست مدل‌های AvalAI خوانده می‌شود. اگر AvalAI آدرس جداگانه‌ای برای فهرست قیمت (JSON) به شما داده، اینجا وارد کنید (اختیاری).</p>
            <label>آدرس فهرست قیمت AvalAI (اختیاری)</label>
            <input type="text" name="avalai_price_url" dir="ltr" value="<?php echo admin_h($bs['avalai_price_url'] ?? ''); ?>" placeholder="https://...">
            <div style="display:flex;gap:6px;align-items:center;margin-top:8px;flex-wrap:wrap">
                <input type="text" id="avc_mid" dir="ltr" placeholder="شناسه یک مدل برای دیدن پاسخ خام، مثلاً gpt-image-1" style="flex:1;min-width:220px">
                <button type="button" class="btn btn-sm btn-test" onclick="avCheck(this)">🔍 بررسی فهرست قیمت AvalAI</button>
                <button type="button" class="btn btn-sm btn-test" onclick="avCheck(this, 'or_check')">🔍 بررسی فهرست OpenRouter و قیمت همه مدل‌ها</button>
            </div>
            <div id="avc_out" style="margin-top:8px;font-size:12.5px;line-height:2"></div>
        </details>
    </div>

    <div class="card">
        <h3 style="font-size:15px;margin-bottom:6px">🛟 قیمت پشتیبان (مدل‌هایی که قیمت دلاری ندارند)</h3>
        <p class="muted" style="line-height:2;margin-bottom:10px">اگر قیمت مدلی نه از سایت مرجع پیدا شود و نه دستی وارد شده باشد، این مبالغ (تومان) حساب می‌شود. بهتر است برای همه مدل‌ها قیمت دلاری داشته باشید؛ مدل‌های بدون قیمت در فهرست با ⚠ مشخص شده‌اند.</p>
        <div class="row2">
            <div><label>هر ۱۰۰۰ توکن مدل متنی (تومان)</label><input type="text" name="cost_per_1k" dir="ltr" value="<?php echo rtrim(rtrim(number_format((float)$cfg['cost_per_1k_tokens'], 2, '.', ''), '0'), '.'); ?>"></div>
            <div><label>هر تصویر (تومان)</label><input type="text" name="image_fallback_toman" dir="ltr" value="<?php echo (int)$bs['image_fallback_toman']; ?>"></div>
        </div>
        <div class="row2">
            <div><label>هر ثانیه ویدیو (تومان)</label><input type="text" name="video_fallback_toman" dir="ltr" value="<?php echo (int)$bs['video_fallback_toman']; ?>"></div>
            <div><label>هر پیام صوتی — صوت به متن (تومان)</label><input type="text" name="hd_voice_cost" dir="ltr" value="<?php echo (int)$hs['hd_voice_cost']; ?>"></div>
        </div>
        <div class="row2">
            <div><label>هر ۱۰۰۰ کاراکتر — متن به صوت (تومان)</label><input type="text" name="hd_tts_cost" dir="ltr" value="<?php echo (int)$hs['hd_tts_cost']; ?>"></div>
            <div></div>
        </div>
    </div>

    <div class="card">
        <h3 style="font-size:15px;margin-bottom:6px">⚙️ مدل کارهای داخلی سامانه</h3>
        <p class="muted" style="line-height:2;margin-bottom:10px">کارهایی که سامانه پشت صحنه انجام می‌دهد (مثل انتخاب موضوع پایگاه دانش، خلاصه حافظه گفتگو) وقتی مدل مشخصی ندارند، با این مدل انجام می‌شوند؛ مدل‌های جایگزین آن هم استفاده می‌شوند.</p>
        <select name="sys_model_id">
            <option value="0">— مدل پشتیبان نهایی (پایین) —</option>
            <?php foreach ($text_models as $m): ?><option value="<?php echo (int)$m['id']; ?>" <?php echo $sysm === (int)$m['id'] ? 'selected' : ''; ?>><?php echo admin_h($m['title'] . ' — ' . $m['model_id']); ?><?php echo $m['is_active'] && $prov_on[$m['provider']] ? '' : ' (غیرفعال)'; ?></option><?php endforeach; ?>
        </select>
        <h3 style="font-size:15px;margin:18px 0 6px">🌐 مترجم درخواست‌های تصویر و ویدیو</h3>
        <p class="muted" style="line-height:2;margin-bottom:10px">درخواست فارسی کاربران و مخاطبان پیش از ارسال به مدل‌های ساخت تصویر و ویدیو (که معمولاً گران‌اند) با این مدل ارزان به یک پرامپت دقیق انگلیسی تبدیل می‌شود؛ همین مرحله تشخیص می‌دهد درخواست «ویرایش تصویر قبلی» است یا «طرح تازه» و ابعاد (افقی/عمودی/مربع) را از متن درمی‌آورد. هزینه ناچیز آن از اعتبار صاحب حساب کم می‌شود.</p>
        <?php $trm = (int)($bs['gen_tr_model_id'] ?? 0); $tr_auto = function_exists('gen_tr_model') ? gen_tr_model($pdo) : null; ?>
        <?php $trmode = (string)($bs['gen_tr_mode'] ?? 'auto'); ?>
        <label style="display:block;margin-bottom:6px">چه زمانی ترجمه شود؟</label>
        <select name="gen_tr_mode" style="margin-bottom:6px">
            <option value="auto" <?php echo $trmode === 'auto' || !in_array($trmode, ['always', 'never'], true) ? 'selected' : ''; ?>>خودکار (پیشنهادی): فقط برای مدل‌هایی که فارسی را خوب نمی‌فهمند</option>
            <option value="always" <?php echo $trmode === 'always' ? 'selected' : ''; ?>>همیشه ترجمه شود</option>
            <option value="never" <?php echo $trmode === 'never' ? 'selected' : ''; ?>>هرگز ترجمه نشود (ارزان‌ترین)</option>
        </select>
        <p class="muted" style="line-height:2;margin-bottom:10px;font-size:12.5px">در حالت خودکار، مدل‌های Gemini (Nano Banana) و GPT-Image درخواست فارسی را مستقیم دریافت می‌کنند (همراه یک دستور کوتاه انگلیسی برای حفظ کیفیت و دقت ویرایش)؛ هزینه ترجمه صفر می‌شود و تصویر مرجع هم دقیق‌تر حفظ می‌شود. ترجمه برای ویدیو و سایر مدل‌ها (مثل DALL·E و Flux) انجام می‌شود.</p>
        <label style="display:block;margin-bottom:6px">مدل مترجم</label>
        <select name="gen_tr_model_id">
            <option value="0">— خودکار: ارزان‌ترین مدل متنی فعال<?php echo $tr_auto && !$trm ? ' (الان: ' . admin_h($tr_auto['title']) . ')' : ''; ?> —</option>
            <?php foreach ($text_models as $m): ?><option value="<?php echo (int)$m['id']; ?>" <?php echo $trm === (int)$m['id'] ? 'selected' : ''; ?>><?php echo admin_h($m['title'] . ' — ' . $m['model_id']); ?> — <?php echo biz_model_cost_label($pdo, $m); ?><?php echo $m['is_active'] && $prov_on[$m['provider']] ? '' : ' (غیرفعال)'; ?></option><?php endforeach; ?>
        </select>
        <details style="margin-top:12px"><summary style="cursor:pointer;font-weight:600;font-size:13px">مدل پشتیبان نهایی (وقتی هیچ مدلی تعریف یا فعال نیست)</summary>
            <div class="row2" style="margin-top:10px">
                <div><label>مدل AvalAI</label><input type="text" name="avalai_model" dir="ltr" value="<?php echo admin_h($cfg['avalai_model']); ?>"></div>
                <div><label>مدل OpenRouter</label><input type="text" name="openrouter_model" dir="ltr" value="<?php echo admin_h($cfg['openrouter_model']); ?>"></div>
            </div>
            <label>اولویت</label>
            <select name="provider_priority"><option value="avalai" <?php echo $cfg['provider_priority'] === 'avalai' ? 'selected' : ''; ?>>AvalAI (پشتیبان: OpenRouter)</option><option value="openrouter" <?php echo $cfg['provider_priority'] === 'openrouter' ? 'selected' : ''; ?>>OpenRouter (پشتیبان: AvalAI)</option></select>
        </details>
    </div>

    <div class="card">
        <h3 style="font-size:15px;margin-bottom:6px">💳 اعتبار کاربران</h3>
        <p class="muted" style="line-height:2">اعتبار کاربران به <b>تومان</b> است. هزینه هر پاسخ، تصویر، ویدیو یا پیام صوتی دقیقاً به اندازه قیمت نهایی همان مدل از اعتبار صاحب حساب کم می‌شود (اگر مدل جایگزین پاسخ دهد، قیمت همان مدل جایگزین).</p>
        <div style="margin-top:12px"><button class="btn btn-primary btn-sm">💾 ذخیره تنظیمات</button></div>
    </div>
</form>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="usd_now"><button class="btn btn-sm" style="background:#f1f5f9;color:#374151">🔄 دریافت نرخ دلار همین حالا</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="prices_all"><button class="btn btn-sm" style="background:#e0f2fe;color:#0369a1">💲 به‌روزرسانی قیمت همه مدل‌ها از سرویس‌دهنده هر مدل</button></form>
</div>
<script>
function avCheck(btn, act) {
  var out = document.getElementById('avc_out'), label = btn.textContent;
  btn.disabled = true; btn.textContent = '⏳ …'; out.textContent = '';
  var fd = new FormData(); fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>); fd.append('act', act || 'avalai_check'); fd.append('mid', document.getElementById('avc_mid').value.trim());
  fetch('models.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-PG-Ajax': '1' } }).then(function (r) { return r.json(); })
    .then(function (d) {
      out.innerHTML = '';
      var p = document.createElement('div'); p.style.color = d.ok ? '#065f46' : '#b91c1c'; p.textContent = d.msg; out.appendChild(p);
      if (d.sample) {
        var t = document.createElement('div'); t.textContent = d.list ? d.mid + ':' : 'پاسخ خام «' + d.mid + '»' + (d.parsed ? ' — خوانده‌شده: ' + JSON.stringify(d.parsed) : ' — قیمتی در آن پیدا نشد'); out.appendChild(t);
        var pre = document.createElement('pre'); pre.dir = 'ltr'; pre.style.cssText = 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;max-height:260px;overflow:auto;font-size:11.5px;text-align:left;white-space:pre-wrap'; pre.textContent = d.sample; out.appendChild(pre);
      }
    })
    .catch(function () { out.textContent = 'ارتباط با سرور برقرار نشد.'; })
    .then(function () { btn.disabled = false; btn.textContent = label; });
}
</script>

<?php else:
    $cat = $tab;
    $tok = biz_price_is_token_cat($cat);
    $uses = mdl_uses($cat);
    $only_av = in_array($cat, $avalai_only, true);
    $list = array_values(array_filter($all_rows, fn($m) => $m['category'] === $cat && !$m['parent_id']));
    $fb_form = [];
    if ($edit) {
        $used = [];
        foreach (array_filter(array_map('intval', explode(',', (string)$edit['fallbacks']))) as $fid) {
            if (!isset($all_rows[$fid]) || isset($used[$fid])) continue;
            $used[$fid] = 1; $fb_form[] = $all_rows[$fid];
        }
        foreach ($all_rows as $m) if ($m['parent_id'] === (int)$edit['id'] && !isset($used[(int)$m['id']])) $fb_form[] = $m;
    }
    $e_src = $edit['price_src'] ?? 'auto';
    $e_margin = isset($edit['margin']) && (float)$edit['margin'] >= 0 ? rtrim(rtrim(number_format((float)$edit['margin'], 2, '.', ''), '0'), '.') : '';
    $hints = [
        'text' => 'مدل‌های گفتگو برای پاسخگوی هوشمند سایت، چت‌بات تخصصی و چت‌بات رایگان.',
        'stt' => 'تبدیل پیام صوتی مخاطبان چت‌بات به متن. اولین مدل فعالِ متصل استفاده می‌شود (جایگزین‌ها در صورت خطا). فعلاً فقط AvalAI. مثال: gpt-4o-mini-transcribe، whisper-1',
        'tts' => 'خواندن پاسخ‌های چت‌بات با صدا. اولین مدل فعالِ متصل استفاده می‌شود. فعلاً فقط AvalAI. مثال: gpt-4o-mini-tts، tts-1',
        'image' => 'مدل‌های ساخت تصویر در «استودیو طراحی» کاربران (دسترسی هر پلن در صفحه پلن‌ها تعیین می‌شود).',
        'video' => 'مدل‌های ساخت ویدیو در «استودیو» کاربران. فعلاً فقط AvalAI.',
        'agent' => 'مدل‌های بخش «ساخت ایجنت» (به‌زودی). مدل‌هایی که اینجا تعریف می‌کنید برای آن بخش آماده می‌مانند.',
    ];
?>
<p class="muted" style="margin:-4px 0 12px;line-height:2"><?php echo $hints[$cat]; ?></p>

<div class="card" id="form">
    <h3 style="font-size:15px;margin-bottom:10px"><?php echo $edit ? '✏️ ویرایش «' . admin_h($edit['title']) . '»' : '➕ افزودن مدل ' . admin_h(preg_replace('/^\S+\s/u', '', $cats[$cat])); ?></h3>
    <?php if ($edit): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:9px 12px;margin-bottom:12px;font-size:13px;line-height:2;display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between">
        <span>⚠️ این فرم مدل «<?php echo admin_h($edit['title']); ?>» را <b>تغییر می‌دهد</b>. برای اضافه کردن مدل دیگر، «➕ افزودن مدل جدید» را بزنید یا پایین فرم «ذخیره به‌عنوان مدل جدید» را انتخاب کنید. تعداد مدل‌های هر بخش محدودیتی ندارد.</span>
        <a href="models.php?tab=<?php echo $cat; ?>#form" class="btn btn-sm" style="background:#2563eb;color:#fff;white-space:nowrap">➕ افزودن مدل جدید</a>
    </div>
    <?php endif; ?>
    <form method="post" id="mform" data-pg="mform">
        <input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>">
        <input type="hidden" name="act" value="model_save">
        <input type="hidden" name="category" id="mcat" value="<?php echo $cat; ?>">
        <input type="hidden" name="model_id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <div class="row2">
            <div><label>نام نمایشی برای کاربران *</label><input type="text" name="title" maxlength="120" required value="<?php echo admin_h($edit['title'] ?? ''); ?>" placeholder="مثلاً: دستیار سریع"></div>
            <div><label>سرویس‌دهنده</label><select name="provider" id="m_prov">
                <option value="avalai" <?php echo ($edit['provider'] ?? '') !== 'openrouter' ? 'selected' : ''; ?>>AvalAI<?php echo $prov_on['avalai'] ? '' : ' (در تنظیمات API خاموش است)'; ?></option>
                <?php if (!$only_av): ?><option value="openrouter" <?php echo ($edit['provider'] ?? '') === 'openrouter' ? 'selected' : ''; ?>>OpenRouter<?php echo $prov_on['openrouter'] ? '' : ' (در تنظیمات API خاموش است)'; ?></option><?php endif; ?>
            </select></div>
        </div>
        <div><label>شناسه مدل * <span class="muted" style="font-weight:normal">(دقیقاً از سایت سرویس‌دهنده کپی کنید)</span></label>
            <div class="midrow">
                <input type="text" name="mid" id="m_mid" dir="ltr" required value="<?php echo admin_h($edit['model_id'] ?? ''); ?>" placeholder="<?php echo ['text' => 'gpt-4o-mini', 'stt' => 'gpt-4o-mini-transcribe', 'tts' => 'gpt-4o-mini-tts', 'image' => 'dall-e-3', 'video' => 'sora-2', 'agent' => 'gpt-4.1'][$cat]; ?>">
                <?php if ($tok): ?><button type="button" class="btn btn-sm btn-test" onclick="mTest(this, document.getElementById('m_prov'), document.getElementById('m_mid'), <?php echo (int)($edit['id'] ?? 0); ?>)">🔌 تست اتصال</button><?php elseif ($cat === 'image'): ?><button type="button" class="btn btn-sm btn-test" onclick="if(confirm('یک تصویر آزمایشی ساخته می‌شود (هزینه کم آن با سامانه است). ادامه؟'))mTest(this, document.getElementById('m_prov'), document.getElementById('m_mid'), <?php echo (int)($edit['id'] ?? 0); ?>)">🖼 تست ساخت تصویر</button><?php endif; ?>
                <button type="button" class="btn btn-sm btn-test" onclick="mLookup(this)">💲 دریافت قیمت</button>
            </div>
        </div>

        <div class="pricebox">
            <label style="margin:0">💲 قیمت این مدل</label>
            <div class="modes">
                <label><input type="radio" name="price_src" value="auto" <?php echo $e_src !== 'manual' ? 'checked' : ''; ?> onchange="mPrice()"> خودکار از سرویس‌دهنده همین مدل (روزی یک بار)</label>
                <label><input type="radio" name="price_src" value="manual" <?php echo $e_src === 'manual' ? 'checked' : ''; ?> onchange="mPrice()"> دستی (دلار)</label>
            </div>
            <div class="row2">
                <?php if ($tok): ?>
                <div><label>قیمت ورودی — دلار <?php echo $units[$cat]['label']; ?></label><input type="text" name="usd_in" id="u_in" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_in']) : ''; ?>" oninput="mPrice()" placeholder="0.15"></div>
                <div><label>قیمت خروجی — دلار <?php echo $units[$cat]['label']; ?></label><input type="text" name="usd_out" id="u_out" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_out']) : ''; ?>" oninput="mPrice()" placeholder="0.60"></div>
                <?php elseif ($cat === 'image'): ?>
                <div><label>ورودی متن — دلار هر ۱ میلیون توکن</label><input type="text" name="usd_in" id="u_in" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_in']) : ''; ?>" oninput="mPrice()" placeholder="5"></div>
                <div><label>ورودی تصویر (مرجع/ویرایش) — دلار هر ۱ میلیون توکن <span class="muted" style="font-weight:normal">(خالی = مثل ورودی متن)</span></label><input type="text" name="usd_img_in" id="u_img" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_img_in'] ?? 0) : ''; ?>" oninput="mPrice()" placeholder="10"></div>
            </div>
            <div class="row2">
                <div><label>خروجی (تصویر ساخته‌شده) — دلار هر ۱ میلیون توکن</label><input type="text" name="usd_out" id="u_out" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_out']) : ''; ?>" oninput="mPrice()" placeholder="40"></div>
                <div><label>قیمت هر تصویر — دلار <span class="muted" style="font-weight:normal">(فقط وقتی سرویس توکن مصرفی را گزارش نکند، مثل dall-e)</span></label><input type="text" name="usd_unit" id="u_unit" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_unit']) : ''; ?>" oninput="mPrice()" placeholder="0.04"></div>
                <?php else: ?>
                <div><label>قیمت — دلار <?php echo $units[$cat]['label']; ?></label><input type="text" name="usd_unit" id="u_unit" class="usd-in" value="<?php echo $edit ? mdl_usd($edit['usd_unit']) : ''; ?>" oninput="mPrice()" placeholder="0.04"></div>
                <div></div>
                <?php endif; ?>
            </div>
            <div class="row2">
                <div><label>درصد سود این مدل <span class="muted" style="font-weight:normal">(خالی = پیش‌فرض <?php echo $def_margin; ?>٪)</span></label><input type="text" name="margin" id="u_mg" dir="ltr" value="<?php echo $e_margin; ?>" oninput="mPrice()" placeholder="<?php echo $def_margin; ?>"></div>
                <div><label>منبع قیمت</label><div id="u_src" style="padding:9px 0;font-size:12.5px;color:#475569"><?php echo $edit ? admin_h($edit['price_note'] ?: '—') . ($edit['price_at'] ? ' <span class="muted">(' . biz_jdate($edit['price_at'], true) . ')</span>' : '') : '—'; ?></div></div>
            </div>
            <div class="final" id="u_final"></div>
        </div>

        <div class="row2" style="margin-top:12px">
            <div><label>توضیح کوتاه برای کاربران</label><input type="text" name="description" maxlength="300" value="<?php echo admin_h($edit['description'] ?? ''); ?>" placeholder="مثلاً: مناسب پاسخ‌های کوتاه و سریع"></div>
            <div><label>ترتیب نمایش / اولویت</label><input type="number" name="sort_order" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>"></div>
        </div>
        <?php $cap_man = ($edit['cap_src'] ?? 'auto') === 'manual'; $cap_cur = $edit ? mcap_list($edit) : []; ?>
        <div style="margin-top:12px"><label>محدودیت درخواست ارائه‌دهنده (اختیاری — خالی = خودکار از AvalAI / OpenRouter)</label>
            <input type="text" name="req_limit" maxlength="80" value="<?php echo admin_h($edit['req_limit'] ?? ''); ?>" placeholder="مثلاً: ۲۰ در دقیقه  یا  ۵۰۰ در روز"></div>
        <div class="capbox" style="margin-top:12px"><label>کارایی مدل (برای مقایسه و فیلتر در فهرست)</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:4px 0 6px;font-size:13px">
                <label class="inl"><input type="radio" name="cap_mode" value="auto" <?php echo !$cap_man ? 'checked' : ''; ?> onchange="capMode()"> خودکار از روی توضیح رسمی سازنده (پیشنهادی)</label>
                <label class="inl"><input type="radio" name="cap_mode" value="manual" <?php echo $cap_man ? 'checked' : ''; ?> onchange="capMode()"> انتخاب دستی</label>
            </div>
            <div class="capsel" id="capsel"><?php foreach (mcap_vocab($cat) as $w): ?><label><input type="checkbox" name="cap_sel[]" value="<?php echo admin_h($w); ?>" <?php echo in_array($w, $cap_cur, true) ? 'checked' : ''; ?>> <?php echo admin_h($w); ?></label><?php endforeach; ?></div>
        </div>

        <?php if ($uses): ?>
        <div style="margin-top:12px">
            <label>اتصال به بخش‌ها</label>
            <div class="usechk">
                <?php foreach ($uses as $uk => $ul): ?>
                <label><input type="checkbox" name="<?php echo $uk; ?>" value="1" <?php echo !$edit ? ($uk !== 'use_free' ? 'checked' : '') : (!empty($edit[$uk]) ? 'checked' : ''); ?>> <?php echo $ul; ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="fbbox">
            <label style="margin:0">🔁 مدل‌های جایگزین همین مدل (به ترتیب اولویت)</label>
            <p class="muted" style="margin:4px 0 0;line-height:1.9">اگر مدل اصلی در دسترس نباشد، خودکار به‌ترتیب از این مدل‌ها استفاده می‌شود و به‌محض در دسترس شدن، دوباره خود مدل اصلی پاسخ می‌دهد. قیمت جایگزین‌ها خودکار از سایت مرجع گرفته می‌شود (درصد سود همان مدل اصلی)؛ اگر پیدا نشد، قیمت دلاری را در باکس «قیمت» بنویسید. ردیف خالی نادیده گرفته می‌شود.</p>
            <div id="fblist">
                <?php $rows = $fb_form ?: [null, null]; foreach ($rows as $fr): ?>
                <div class="fbrow">
                    <span class="fbn"></span>
                    <input type="hidden" name="fb_id[]" value="<?php echo (int)($fr['id'] ?? 0); ?>">
                    <select name="fb_provider[]"><option value="avalai" <?php echo ($fr['provider'] ?? '') !== 'openrouter' ? 'selected' : ''; ?>>AvalAI</option><?php if (!$only_av): ?><option value="openrouter" <?php echo ($fr['provider'] ?? '') === 'openrouter' ? 'selected' : ''; ?>>OpenRouter</option><?php endif; ?></select>
                    <input type="text" name="fb_mid[]" class="fbmid" dir="ltr" value="<?php echo admin_h($fr['model_id'] ?? ''); ?>" placeholder="شناسه مدل جایگزین">
                    <?php $man = $fr && ($fr['price_src'] ?? '') === 'manual'; ?>
                    <?php if ($tok): ?>
                    <input type="text" name="fb_in[]" class="fbp" dir="ltr" value="<?php echo $man ? mdl_usd($fr['usd_in']) : ''; ?>" placeholder="ورودی $" title="قیمت دستی ورودی (دلار برای هر ۱ میلیون توکن) — خالی = خودکار">
                    <input type="text" name="fb_out[]" class="fbp" dir="ltr" value="<?php echo $man ? mdl_usd($fr['usd_out']) : ''; ?>" placeholder="خروجی $" title="قیمت دستی خروجی (دلار برای هر ۱ میلیون توکن) — خالی = خودکار">
                    <input type="hidden" name="fb_unit[]" value="">
                    <?php else: ?>
                    <input type="hidden" name="fb_in[]" value=""><input type="hidden" name="fb_out[]" value="">
                    <input type="text" name="fb_unit[]" class="fbp" dir="ltr" value="<?php echo $man ? mdl_usd($fr['usd_unit']) : ''; ?>" placeholder="قیمت $" title="قیمت دستی (دلار <?php echo $units[$cat]['label']; ?>) — خالی = خودکار">
                    <?php endif; ?>
                    <?php if ($tok): ?><button type="button" class="btn btn-sm btn-test" onclick="fbTest(this)">🔌 تست</button><?php endif; ?>
                    <button type="button" class="btn btn-sm" style="background:#fee2e2;color:#dc2626" onclick="fbDel(this)" title="حذف این ردیف">✕</button>
                    <?php if ($fr): $pp = biz_model_price_html($pdo, $fr); ?>
                    <span class="fbnote"><?php echo $pp['priced'] ? 'قیمت نهایی: ' . $pp['toman'] . ' تومان ' . $pp['unit'] . ' — ' . admin_h($fr['price_note']) : '⚠ ' . admin_h($fr['price_note'] ?: 'قیمت ندارد (قیمت پشتیبان حساب می‌شود)'); ?><?php echo !biz_model_up($fr) ? ' — <b style="color:#dc2626">خارج از دسترس تا ' . date('H:i', strtotime($fr['down_until'])) . '</b>' : ''; ?><?php echo !$prov_on[$fr['provider']] ? ' — <b style="color:#dc2626">سرویس‌دهنده خاموش است</b>' : ''; ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm" style="background:#ede9fe;color:#6d28d9;margin-top:8px" onclick="fbAdd()">➕ باکس مدل جایگزین دیگر</button>
        </div>

        <label class="inl" style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" name="is_active" value="1" <?php echo !$edit || !empty($edit['is_active']) ? 'checked' : ''; ?>> فعال</label>
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm"><?php echo $edit ? '💾 ذخیره تغییرات همین مدل' : '➕ افزودن'; ?></button>
            <?php if ($edit): ?><button type="submit" name="as_new" value="1" class="btn btn-sm" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">📄 ذخیره به‌عنوان مدل جدید</button>
            <a href="models.php?tab=<?php echo $cat; ?>#form" class="btn btn-sm" style="background:#f1f5f9;color:#374151">✖ بستن ویرایش</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3 style="font-size:15px;margin-bottom:6px"><?php echo $cats[$cat]; ?> <span class="muted" style="font-weight:normal;font-size:12px">(<?php echo count($list); ?> مدل)</span></h3>
    <?php if (!$list): ?>
        <p class="muted" style="text-align:center;padding:14px"><?php echo $cat === 'text' ? 'هنوز مدل متنی تعریف نشده؛ فعلاً «مدل پشتیبان نهایی» (زبانه تنظیمات قیمت) استفاده می‌شود.' : 'مدلی در این دسته تعریف نشده است.'; ?></p>
    <?php else: ?>
    <p class="muted" style="margin:0 0 8px">تیک‌ها و درصد سود همان لحظه ذخیره می‌شوند؛ نیازی به ویرایش نیست.</p>
    <?php $dstat = mcap_daily_stats($pdo); ?>
    <p class="muted" style="margin:-4px 0 8px">برای دیدن ارائه‌دهنده، توضیحات کامل مدل و گزینه‌های بیشتر، روی ردیف هر مدل کلیک کنید. برای مرتب‌سازی روی عنوان ستون‌ها بزنید.</p>
    <?php $capcount = []; foreach ($list as $m) foreach (mcap_list($m) as $c) $capcount[$c] = ($capcount[$c] ?? 0) + 1; ?>
    <div class="mtools">
        <div class="msort"><label>مرتب‌سازی:</label>
            <select id="msortsel" onchange="mSortSel(this.value)">
                <option value="">اولویت (پیش‌فرض)</option>
                <option value="price:asc">قیمت: ارزان‌ترین</option><option value="price:desc">قیمت: گران‌ترین</option>
                <option value="lim:desc">بیشترین محدودیت درخواست</option><option value="req:desc">بیشترین درخواست امروز</option><option value="avg:desc">بیشترین میانگین ۷ روز</option>
                <option value="exp:asc">نزدیک‌ترین تاریخ انقضا</option>
                <option value="name:asc">نام (الفبا)</option><option value="mid:asc">شناسه مدل (الفبا)</option>
                <option value="mg:desc">بیشترین درصد سود</option><option value="caps:desc">بیشترین تعداد کارایی</option>
                <option value="act:desc">فعال‌ها اول</option>
            </select></div>
        <div class="mfilt" id="mfilt"><span class="fl">فیلتر کارایی:</span>
            <?php foreach (mcap_vocab($cat) as $w): if (empty($capcount[$w])) continue; ?><button type="button" class="fcap" data-cap="<?php echo admin_h($w); ?>" onclick="mFilt(this.getAttribute('data-cap'))"><?php echo admin_h($w); ?> <small><?php echo $capcount[$w]; ?></small></button><?php endforeach; ?>
            <?php if (!$capcount): ?><span class="muted" style="font-size:12px">پس از تکمیل کارایی مدل‌ها نمایش داده می‌شود.</span><?php endif; ?>
            <button type="button" class="fclr" id="mfclr" onclick="mFilt(null)" hidden>✖ نمایش همه</button>
            <span class="fres" id="mfres"></span></div>
    </div>
    <div style="overflow-x:auto"><table class="mtbl">
        <thead><tr><th class="srt" data-k="name">نام انتخابی</th><th class="srt" data-k="mid">شناسه مدل</th><th class="srt" data-k="caps">کارایی</th><th class="srt" data-k="mg">درصد سود</th><th class="srt" data-k="price">قیمت نهایی (تومان)</th><th class="srt" data-k="lim" title="محدودیتی که ارائه‌دهنده گذاشته / تعداد درخواست امروز این سایت">محدودیت درخواست / درخواست</th><th class="srt" data-k="exp">تاریخ انقضای مدل</th><th class="srt" data-k="act">فعال</th><th>قیمت</th><th>زنجیره پاسخ‌دهی (✅ = در حال پاسخ)</th></tr></thead><tbody>
        <?php foreach ($list as $m):
            $mid_ = (int)$m['id'];
            $pp = biz_model_price_html($pdo, $m);
            $serving = $m['is_active'] && $prov_on[$m['provider']] ? biz_model_serving($pdo, $m) : null;
            $chain = array_merge([$m], biz_model_children($pdo, $m));
            $mg = (float)$m['margin'] >= 0 ? rtrim(rtrim(number_format((float)$m['margin'], 2, '.', ''), '0'), '.') : '';
            $ev = mcap_exp_view($m['expire_date'] ?? '');
            $cmeta = json_decode((string)($m['cap_meta'] ?? ''), true) ?: [];
            $st = $dstat[$mid_] ?? ['today' => 0, 'avg' => 0];
            $iw = biz_model_id_warning($m);
            $ie = json_decode((string)($bs['img_err_' . $mid_] ?? ''), true);
            // نسخه ۶۸: خطای قدیمی که با سرویس‌دهنده/شناسه قبلی ثبت شده، بعد از اصلاح مدل نمایش داده نمی‌شود
            if (is_array($ie) && (isset($ie['prov']) ? ($ie['prov'] !== $m['provider'] || ($ie['mid'] ?? '') !== $m['model_id']) : stripos((string)($ie['err'] ?? ''), (string)$m['model_id']) === false && preg_match('/does not exist|not found|No endpoints/i', (string)($ie['err'] ?? '')))) $ie = null;
            if (!(is_array($ie) && !empty($ie['err']) && strtotime((string)$ie['at']) > time() - 7 * 86400)) $ie = null;
            $down = !biz_model_up($m);
            $issue = $iw !== '' || $ie || $down || !$prov_on[$m['provider']] || str_starts_with((string)$m['price_note'], '⚠');
            $open = (int)($_GET['hl'] ?? 0) === $mid_;
            $desc_fa = trim((string)($m['cap_desc'] ?? '')); $desc_en = trim((string)($m['cap_desc_en'] ?? ''));
            $lim = mcap_limit($pdo, $m);
            // نسخه ۷۴: مقادیر مرتب‌سازی و فیلتر
            $tp = biz_model_toman_prices($pdo, $m);
            $sprice = (float)$tp['per1k'] > 0 ? (float)$tp['per1k'] * 1000 : (float)$tp['unit'];
            $sexp = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($m['expire_date'] ?? '')) ? strtotime($m['expire_date']) : 9999999999;
            $sattr = ' data-caps="' . admin_h(implode('|', mcap_list($m))) . '" data-s-price="' . round($sprice, 4) . '" data-s-req="' . (int)$st['today'] . '" data-s-avg="' . (float)$st['avg'] . '" data-s-exp="' . $sexp
                   . '" data-s-lim="' . (is_infinite($lim['per_day']) ? 1e15 : $lim['per_day']) . '" data-s-name="' . admin_h($m['title']) . '" data-s-mid="' . admin_h($m['model_id']) . '" data-s-mg="' . ($mg === '' ? (float)$def_margin : (float)$mg) . '" data-s-caps="' . count(mcap_list($m)) . '" data-s-act="' . (int)$m['is_active'] . '"';
        ?>
        <tr id="m<?php echo $mid_; ?>" class="mrow<?php echo $open ? ' m-hl open' : ''; ?>" data-id="<?php echo $mid_; ?>" onclick="mRowClick(event, <?php echo $mid_; ?>)" title="برای جزئیات کلیک کنید"<?php echo $sattr; ?><?php echo mcap_needs($m) ? ' data-capneed="1"' : ''; ?>>
            <td class="c-name"><span class="arr">◂</span><b><?php echo admin_h($m['title']); ?></b>
                <?php if ((int)$bs['sys_model_id'] === $mid_): ?><div class="sysm">⚙️ کارهای داخلی</div><?php endif; ?>
                <?php if ($issue): ?><div><span class="issue">⚠ نیاز به بررسی</span></div><?php endif; ?></td>
            <td class="c-mid" dir="ltr"><?php echo admin_h($m['model_id']); ?></td>
            <td class="c-cap"><div class="caps"><?php echo mcap_cell_html($m); ?></div></td>
            <td class="c-mg"><input type="text" class="mgin" value="<?php echo $mg; ?>" placeholder="<?php echo $def_margin; ?>" title="درصد سود (خالی = پیش‌فرض)" onchange="mMargin(this, <?php echo $mid_; ?>)"> ٪</td>
            <td style="font-size:12.5px;white-space:nowrap" class="c-toman"><b><?php echo $pp['toman']; ?></b><div class="pnote"><?php echo $pp['unit']; ?><?php echo !$pp['priced'] ? ' — <span style="color:#b45309">⚠ قیمت پشتیبان</span>' : ''; ?></div></td>
            <td class="c-req"><span class="lim" title="<?php echo admin_h($lim['sub']); ?>"><?php echo admin_h($lim['label']); ?></span> <span class="sep">/</span> <b><?php echo number_format((int)$st['today']); ?></b> امروز
                <div class="pnote limsub" title="<?php echo admin_h($lim['sub']); ?>"><?php echo admin_h($lim['short'] ?? ''); ?></div>
                <div class="pnote">میانگین ۷ روز: <?php echo rtrim(rtrim(number_format((float)$st['avg'], 1), '0'), '.'); ?> در روز</div></td>
            <td class="c-exp"><span class="exp <?php echo $ev['cls']; ?>"><?php echo admin_h($ev['label']); ?></span></td>
            <td><div class="tchk"><label><input type="checkbox" <?php echo $m['is_active'] ? 'checked' : ''; ?> onchange="mFlag(this, <?php echo $mid_; ?>, 'is_active')"> فعال</label></div></td>
            <td><button type="button" class="btn btn-sm" style="background:#e0f2fe;color:#0369a1;white-space:nowrap" onclick="mRefresh(this, <?php echo $mid_; ?>)" title="دریافت دوباره قیمت از سایت مرجع">💲 به‌روز کردن</button></td>
            <td class="c-chain"><div class="mchain">
                <?php foreach ($chain as $i => $c): $cdown = !biz_model_up($c); $off = !$prov_on[$c['provider']]; $on = $serving && (int)$serving['id'] === (int)$c['id']; ?>
                    <?php if ($i): ?><i>←</i><?php endif; ?>
                    <span class="<?php echo $on ? 'on' : ($off ? 'off' : ($cdown ? 'down' : '')); ?>" title="<?php echo admin_h($prov_name[$c['provider']] . ' / ' . $c['model_id'] . ($off ? ' — سرویس‌دهنده خاموش' : '') . ($cdown ? ' — خارج از دسترس تا ' . date('H:i', strtotime($c['down_until'])) . ' — ' . $c['last_error'] : '')); ?>"><?php echo $on ? '✅ ' : ''; ?><?php echo admin_h($c['title']); ?></span>
                <?php endforeach; ?>
            </div>
            <?php if ($down): ?><div style="font-size:11px;color:#dc2626;margin-top:3px">⛔ تا ساعت <?php echo date('H:i', strtotime($m['down_until'])); ?> کنار گذاشته شده</div><?php endif; ?></td>
        </tr>
        <tr id="md<?php echo $mid_; ?>" class="mdet"<?php echo $open ? '' : ' hidden'; ?>><td colspan="10"><div class="mdet-in">
            <div class="dcols">
                <div class="dprov"><div class="dl">ارائه‌دهنده API</div>
                    <div class="pv"><?php echo admin_h($prov_name[$m['provider']]); ?></div>
                    <?php if (!$prov_on[$m['provider']]): ?><div class="provoff">⛔ در تنظیمات API خاموش است</div><?php endif; ?>
                    <?php if (!empty($cmeta['ref']) && $cmeta['ref'] !== $m['model_id']): ?><div class="pnote">مشخصات از: <span dir="ltr"><?php echo admin_h($cmeta['ref']); ?></span></div><?php endif; ?></div>
                <div class="ddesc"><div class="dl">توضیحات کامل مدل (از سایت سازنده)</div>
                    <div class="dtx"><?php echo $desc_fa !== '' ? nl2br(admin_h($desc_fa)) : ($desc_en !== '' ? '' : '<span class="muted">' . (empty($m['cap_at']) ? 'در حال دریافت…' : 'توضیحی از سازنده پیدا نشد.') . '</span>'); ?></div>
                    <?php if ($desc_en !== ''): ?><details class="den"<?php echo $desc_fa === '' ? ' open' : ''; ?>><summary>متن اصلی سازنده</summary><div dir="ltr"><?php echo nl2br(admin_h($desc_en)); ?></div></details><?php endif; ?>
                    <div class="pnote" style="margin-top:6px">مشخصات: <span class="dtags"><?php echo admin_h(implode(' · ', (array)($cmeta['tags'] ?? [])) ?: '—'); ?></span><?php echo ($m['cap_src'] ?? 'auto') === 'manual' ? ' — کارایی دستی' : ''; ?></div></div>
                <div class="dact">
                    <a href="models.php?tab=<?php echo $cat; ?>&edit=<?php echo $mid_; ?>#form" class="btn btn-sm" style="background:#ede9fe;color:#7c3aed">✏️ ویرایش</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟ مدل‌های جایگزین این مدل هم حذف می‌شوند و پلن‌ها و کاربرانی که این مدل را انتخاب کرده‌اند به مدل پیش‌فرض برمی‌گردند.');"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="model_del"><input type="hidden" name="category" value="<?php echo $cat; ?>"><input type="hidden" name="model_id" value="<?php echo $mid_; ?>"><button class="btn btn-sm" style="background:#fee2e2;color:#dc2626">🗑 حذف</button></form>
                </div>
            </div>
            <div class="dmore">
                <span class="c-usd">قیمت مرجع: <span class="v" dir="ltr"><?php echo $pp['usd'] !== '' ? admin_h($pp['usd']) : '—'; ?></span> <span class="pnote<?php echo str_starts_with((string)$m['price_note'], '⚠') ? ' err' : ''; ?>"><?php echo admin_h($m['price_note']); ?></span></span>
                <?php if ($uses): ?><span class="tchk" style="flex-direction:row;gap:10px;flex-wrap:wrap"><?php foreach ($uses as $uk => $ul): ?><label><input type="checkbox" <?php echo !empty($m[$uk]) ? 'checked' : ''; ?> onchange="mFlag(this, <?php echo $mid_; ?>, '<?php echo $uk; ?>')"> <?php echo $ul; ?></label><?php endforeach; ?></span><?php endif; ?>
                <button type="button" class="btn btn-sm" style="background:#f5f3ff;color:#6d28d9" onclick="mCap(<?php echo $mid_; ?>, this)" title="کارایی، توضیحات و تاریخ انقضا دوباره از منبع گرفته شود">🔄 به‌روزرسانی کارایی و توضیحات</button>
                <?php if ($tok): ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="model_ping"><input type="hidden" name="category" value="<?php echo $cat; ?>"><input type="hidden" name="model_id" value="<?php echo $mid_; ?>"><button class="btn btn-sm" style="background:#f1f5f9;color:#374151" title="ارسال یک پیام آزمایشی کوتاه">🔍 بررسی</button></form>
                <?php endif; ?>
                <?php foreach ($chain as $c): if (biz_model_up($c)) continue; ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?php echo admin_h($csrf_token); ?>"><input type="hidden" name="act" value="model_reset"><input type="hidden" name="category" value="<?php echo $cat; ?>"><input type="hidden" name="model_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm" style="background:#dcfce7;color:#166534">↺ امتحان دوباره<?php echo (int)$c['id'] !== $mid_ ? ' (' . admin_h(mb_substr($c['title'], 0, 18)) . ')' : ''; ?></button></form>
                <?php endforeach; ?>
            </div>
            <?php if ($iw !== ''): ?><div class="pnote err" style="font-weight:600;max-width:none">⚠ <?php echo admin_h($iw); ?></div><?php endif; ?>
            <?php if ($down): ?><div style="font-size:11.5px;color:#dc2626">⛔ <?php echo admin_h(mb_substr($m['last_error'], 0, 200)); ?> — بررسی دوباره ساعت <?php echo date('H:i', strtotime($m['down_until'])); ?></div><?php endif; ?>
            <?php if ($ie): ?><details class="pnote err" style="white-space:normal;max-width:none"><summary style="cursor:pointer">⚠ آخرین خطای ساخت (<?php echo admin_h(biz_jdate($ie['at'], true)); ?><?php echo isset($ie['prov']) ? ' — ' . admin_h(($prov_name[$ie['prov']] ?? $ie['prov']) . ' / ' . $ie['mid']) : ''; ?>)</summary><div dir="ltr" style="text-align:left;font-size:11px;word-break:break-word"><?php echo admin_h($ie['err']); ?></div></details><?php endif; ?>
        </div></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>

<script>
var M_CSRF = <?php echo json_encode($csrf_token); ?>;
var M_CAT = <?php echo json_encode($cat); ?>;
var M_TOK = <?php echo $tok ? 'true' : 'false'; ?>;
var M_IMG = <?php echo $cat === 'image' ? 'true' : 'false'; ?>;
var M_RATE = <?php echo json_encode($rate); ?>;
var M_DEFMG = <?php echo json_encode($def_margin); ?>;
var M_UNIT = <?php echo json_encode($units[$cat]['label']); ?>;
var M_AVONLY = <?php echo $only_av ? 'true' : 'false'; ?>;
function mNum(v) { v = String(v || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[,٬$\s]/g, '').replace('٫', '.'); var n = parseFloat(v); return isNaN(n) ? 0 : n; }
function mFmt(v) { if (!v) return '۰'; if (v < 10) return (Math.round(v * 100) / 100).toLocaleString('fa-IR'); return Math.round(v).toLocaleString('fa-IR'); }
function mPost(data) {
  var fd = new FormData(); fd.append('csrf_token', M_CSRF); fd.append('category', M_CAT);
  for (var k in data) fd.append(k, data[k]);
  return fetch('models.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-PG-Ajax': '1' } }).then(function (r) { return r.json(); });
}
// نسخه ۷۲: هر مدل دو ردیف دارد (اصلی + جزئیات آبشاری)
function mCell(id, sel) { var r = document.getElementById('m' + id), d = document.getElementById('md' + id); return (r && r.querySelector(sel)) || (d && d.querySelector(sel)); }
function mRowClick(e, id) {
  // کلیک روی دکمه، تیک، فیلد یا لینک ردیف، فقط کار خودش را انجام دهد
  if (e.target.closest('input,button,a,select,label,form,textarea')) return;
  if (window.getSelection && String(window.getSelection()).length > 0) return;
  var r = document.getElementById('m' + id), d = document.getElementById('md' + id); if (!d) return;
  var open = d.hasAttribute('hidden'); if (open) d.removeAttribute('hidden'); else d.setAttribute('hidden', '');
  if (r) r.classList.toggle('open', open);
}
// ---------- نسخه ۷۴: فیلتر کارایی و مرتب‌سازی ----------
var M_FILT = [];
function mPairs() { return Array.prototype.map.call(document.querySelectorAll('table.mtbl tr.mrow'), function (r) { return [r, document.getElementById('md' + r.getAttribute('data-id'))]; }); }
function mFilt(cap) {
  if (cap === null) M_FILT = []; else { var i = M_FILT.indexOf(cap); if (i >= 0) M_FILT.splice(i, 1); else M_FILT.push(cap); }
  var shown = 0, all = 0;
  mPairs().forEach(function (p) {
    var caps = (p[0].getAttribute('data-caps') || '').split('|');
    var ok = M_FILT.every(function (c) { return caps.indexOf(c) >= 0; });
    all++; if (ok) shown++;
    p[0].style.display = ok ? '' : 'none';
    if (p[1]) p[1].style.display = ok ? '' : 'none';
  });
  document.querySelectorAll('#mfilt .fcap').forEach(function (b) { b.classList.toggle('on', M_FILT.indexOf(b.getAttribute('data-cap')) >= 0); });
  document.querySelectorAll('table.mtbl .caps .cp').forEach(function (b) { b.classList.toggle('on', M_FILT.indexOf(b.getAttribute('data-cap')) >= 0); });
  var clr = document.getElementById('mfclr'), res = document.getElementById('mfres');
  if (clr) clr.hidden = !M_FILT.length;
  if (res) res.textContent = M_FILT.length ? (mFa(shown) + ' از ' + mFa(all) + ' مدل') : '';
}
function mFa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
var M_SORT = { k: '', d: 'asc' };
function mSort(k, d) {
  var tb = document.querySelector('table.mtbl tbody'); if (!tb) return;
  var pairs = mPairs();
  if (k) {
    var num = ['price', 'req', 'avg', 'exp', 'mg', 'caps', 'act', 'lim'].indexOf(k) >= 0;
    pairs.sort(function (a, b) {
      var x = a[0].getAttribute('data-s-' + k) || '', y = b[0].getAttribute('data-s-' + k) || '', r;
      r = num ? (parseFloat(x) || 0) - (parseFloat(y) || 0) : x.localeCompare(y, 'fa');
      return d === 'desc' ? -r : r;
    });
  } else pairs.sort(function (a, b) { return (+a[0].getAttribute('data-ord')) - (+b[0].getAttribute('data-ord')); });
  pairs.forEach(function (p) { tb.appendChild(p[0]); if (p[1]) tb.appendChild(p[1]); });
  M_SORT = { k: k, d: d };
  document.querySelectorAll('table.mtbl th.srt').forEach(function (th) { th.classList.remove('asc', 'desc'); if (th.getAttribute('data-k') === k) th.classList.add(d); });
  var sel = document.getElementById('msortsel'); if (sel) { var v = k ? k + ':' + d : ''; sel.value = Array.prototype.some.call(sel.options, function (o) { return o.value === v; }) ? v : ''; }
  try { localStorage.setItem('m_sort_' + M_CAT, k ? k + ':' + d : ''); } catch (e) {}
}
function mSortSel(v) { if (!v) return mSort('', 'asc'); var a = v.split(':'); mSort(a[0], a[1]); }
(function mSortInit() {
  Array.prototype.forEach.call(document.querySelectorAll('table.mtbl tr.mrow'), function (r, i) { r.setAttribute('data-ord', i); });
  document.querySelectorAll('table.mtbl th.srt').forEach(function (th) {
    th.title = 'مرتب‌سازی'; th.addEventListener('click', function () {
      var k = th.getAttribute('data-k');
      var d = M_SORT.k === k ? (M_SORT.d === 'asc' ? 'desc' : 'asc') : (['req', 'avg', 'mg', 'caps', 'act', 'lim'].indexOf(k) >= 0 ? 'desc' : 'asc');
      mSort(k, d);
    });
  });
  // کلیک روی برچسب کارایی در ردیف = فیلتر همان کارایی
  var tbl = document.querySelector('table.mtbl');
  if (tbl) tbl.addEventListener('click', function (e) { var c = e.target.closest('.caps .cp'); if (c) { e.stopPropagation(); mFilt(c.getAttribute('data-cap')); } }, true);
  var sv = ''; try { sv = localStorage.getItem('m_sort_' + M_CAT) || ''; } catch (e) {}
  if (sv) mSortSel(sv);
})();
// نسخه ۷۵: محدودیت درخواست ارائه‌دهنده‌ها (روزی یک بار خودکار؛ بدون معطل کردن صفحه)
function mLimFetch(force) {
  return mPost({ act: 'lim_fetch', force: force ? 1 : 0 }).then(function (d) {
    if (!d || !d.lims) return;
    for (var id in d.lims) {
      var r = document.getElementById('m' + id); if (!r) continue;
      var l = d.lims[id], a = r.querySelector('.c-req .lim'), b = r.querySelector('.c-req .limsub');
      if (a) { a.textContent = l.label; a.title = l.sub; } if (b) { b.textContent = l.short || ''; b.title = l.sub; }
      r.setAttribute('data-s-lim', l.pd);
    }
  }).catch(function () {});
}
if (<?php echo mcap_lim_stale() ? 'true' : 'false'; ?> && document.querySelector('table.mtbl')) mLimFetch(false);
function capMode() { var m = document.querySelector('input[name=cap_mode]:checked'); var b = document.getElementById('capsel'); if (b) b.classList.toggle('off', !m || m.value !== 'manual'); }
capMode();
function mCapApply(id, d) {
  var c = mCell(id, '.caps'); if (c && d.html) c.innerHTML = d.html;
  var r = document.getElementById('m' + id); if (r && d.caps) { r.setAttribute('data-caps', d.caps.join('|')); r.setAttribute('data-s-caps', d.caps.length); }
  var e = mCell(id, '.exp'); if (e && d.exp) { e.textContent = d.exp; e.className = 'exp ' + (d.exp_cls || ''); }
  var t = mCell(id, '.dtags'); if (t) t.textContent = d.tags || '—';
  var x = mCell(id, '.dtx'); if (x && d.desc) x.textContent = d.desc;
}
function mCap(id, btn) {
  if (btn) btn.disabled = true;
  return mPost({ act: 'cap_fill', model_id: id })
    .then(function (d) { mCapApply(id, d); if (btn) mShow(btn, d.ok ? 'کارایی و تاریخ حذف به‌روز شد.' : (d.msg || 'ناموفق'), d.ok); })
    .catch(function () { if (btn) mShow(btn, 'ارتباط با سرور برقرار نشد.', false); })
    .then(function () { if (btn) btn.disabled = false; });
}
// مدل‌هایی که هنوز کارایی/تاریخ حذف ندارند (مثلاً مدل تازه اضافه‌شده) یکی‌یکی خودکار تکمیل می‌شوند
(function mCapAuto() {
  var q = Array.prototype.map.call(document.querySelectorAll('tr.mrow[data-capneed]'), function (r) { return r.getAttribute('data-id'); }).slice(0, 25);
  (function next() { var id = q.shift(); if (!id) return; mCap(id).then(next); })();
})();
function mShow(anchor, text, ok, keep) { if (window.pgFlash) return pgFlash(anchor, text, ok, keep); alert(text); }
// پیش‌نمایش قیمت نهایی تومانی
function mPrice() {
  var manual = document.querySelector('input[name=price_src]:checked').value === 'manual';
  ['u_in', 'u_out', 'u_unit', 'u_img'].forEach(function (id) { var e = document.getElementById(id); if (e) { e.readOnly = !manual; e.style.background = manual ? '' : '#f1f5f9'; } });
  var mgRaw = document.getElementById('u_mg').value.trim(), mg = mgRaw === '' ? M_DEFMG : mNum(mgRaw);
  var f = M_RATE * (1 + mg / 100), box = document.getElementById('u_final');
  if (M_RATE <= 0) { box.innerHTML = '⚠️ نرخ دلار تعیین نشده؛ قیمت تومانی حساب نمی‌شود.'; return; }
  if (M_TOK) {
    var i = mNum(document.getElementById('u_in').value) * f, o = mNum(document.getElementById('u_out').value) * f;
    if (!i && !o) { box.innerHTML = manual ? 'قیمت دلاری را وارد کنید.' : 'با «دریافت قیمت» یا ذخیره، قیمت از سایت مرجع گرفته می‌شود.'; return; }
    box.innerHTML = 'قیمت نهایی ' + M_UNIT + ': ورودی <b>' + mFmt(i) + '</b> تومان / خروجی <b>' + mFmt(o) + '</b> تومان'
      + '<br>≈ <b>' + mFmt((i * 0.75 + o * 0.25) / 1000) + '</b> تومان برای هر ۱۰۰۰ توکن (با نرخ دلار ' + mFmt(M_RATE) + ' و سود ' + mg + '٪)';
  } else if (M_IMG) {
    var ti = mNum(document.getElementById('u_in').value) * f, tm = (mNum(document.getElementById('u_img').value) || mNum(document.getElementById('u_in').value)) * f, to = mNum(document.getElementById('u_out').value) * f, tu = mNum(document.getElementById('u_unit').value) * f;
    if (!to && !tu) { box.innerHTML = manual ? 'قیمت دلاری را وارد کنید.' : 'با «دریافت قیمت» یا ذخیره، قیمت از سایت مرجع گرفته می‌شود.'; return; }
    box.innerHTML = (to ? 'هزینه هر تصویر <b>دقیقاً از روی توکن مصرفی</b> همان تصویر حساب می‌شود — هر ۱ میلیون توکن: ورودی متن <b>' + mFmt(ti) + '</b>، ورودی تصویر <b>' + mFmt(tm) + '</b>، خروجی <b>' + mFmt(to) + '</b> تومان'
      + (function () {
          // توکن خروجی معمول یک تصویر همین مدل (Gemini 2.5 Flash Image ≈ ۱۲۹۰، Gemini 3 ≈ ۱۱۲۰، gpt-image کیفیت بالا ≈ ۴۱۶۰)
          var mid = ((document.getElementById('m_mid') || {}).value || '').toLowerCase().replace(/^.*\//, ''), n = /^gpt-image/.test(mid) ? 4160 : (/^gemini-3/.test(mid) ? 1120 : 1290);
          var raw = (mNum(document.getElementById('u_in').value) * 300 + mNum(document.getElementById('u_out').value) * n) / 1e6;
          return '<br>یک تصویر معمولی این مدل (۳۰۰ توکن متن + ' + n.toLocaleString('fa-IR') + ' توکن خروجی) ≈ <b>' + mFmt((ti * 300 + to * n) / 1e6) + '</b> تومان برای کاربر'
            + ' — هزینه واقعی شما نزد سرویس‌دهنده (بدون سود): ' + mFmt(raw * M_RATE) + ' تومان ($' + raw.toFixed(4) + ')';
        })() : '')
      + (tu ? (to ? '<br>' : '') + 'اگر سرویس توکن مصرفی را گزارش نکند: <b>' + mFmt(tu) + '</b> تومان هر تصویر' : '') + ' (نرخ دلار ' + mFmt(M_RATE) + '، سود ' + mg + '٪)';
  } else {
    var u = mNum(document.getElementById('u_unit').value) * f;
    if (!u) { box.innerHTML = manual ? 'قیمت دلاری را وارد کنید.' : 'با «دریافت قیمت» یا ذخیره، قیمت از سایت مرجع گرفته می‌شود.'; return; }
    box.innerHTML = 'قیمت نهایی: <b>' + mFmt(u) + '</b> تومان ' + M_UNIT + ' (با نرخ دلار ' + mFmt(M_RATE) + ' و سود ' + mg + '٪)';
  }
}
function mLookup(btn) {
  var mid = document.getElementById('m_mid').value.trim();
  if (!mid) { mShow(btn, 'اول شناسه مدل را وارد کنید.', false); return; }
  var label = btn.textContent; btn.disabled = true; btn.textContent = '⏳ …';
  mPost({ act: 'price_lookup', provider: document.getElementById('m_prov').value, mid: mid })
    .then(function (d) {
      if (d.ok) {
        if (M_TOK) { document.getElementById('u_in').value = d['in']; document.getElementById('u_out').value = d.out; }
        else if (M_IMG) { document.getElementById('u_in').value = d['in'] || ''; document.getElementById('u_img').value = d.img_in || ''; document.getElementById('u_out').value = d.out || ''; document.getElementById('u_unit').value = d.unit; }
        else document.getElementById('u_unit').value = d.unit;
        document.getElementById('u_src').textContent = d.src;
        mPrice();
      }
      mShow(btn, d.msg, d.ok, true);
    })
    .catch(function () { mShow(btn, 'ارتباط با سرور برقرار نشد.', false); })
    .then(function () { btn.disabled = false; btn.textContent = label; });
}
function mTest(btn, provEl, midEl, ref) {
  var mid = (midEl.value || '').trim();
  if (!mid) { mShow(btn, 'اول شناسه مدل را وارد کنید.', false); midEl.focus(); return; }
  var label = btn.textContent; btn.disabled = true; btn.textContent = '⏳ در حال تست…';
  mPost({ act: 'model_test', provider: provEl.value, mid: mid, ref: ref || 0 })
    .then(function (d) { mShow(btn, d.msg, d.ok, true); })
    .catch(function () { mShow(btn, 'ارتباط با سرور برقرار نشد.', false); })
    .then(function () { btn.disabled = false; btn.textContent = label; });
}
function fbTest(btn) { var row = btn.closest('.fbrow'); mTest(btn, row.querySelector('select'), row.querySelector('.fbmid'), parseInt(row.querySelector('input[type=hidden]').value, 10) || 0); }
function fbNum() { var rows = document.querySelectorAll('#fblist .fbrow'); for (var i = 0; i < rows.length; i++) rows[i].querySelector('.fbn').textContent = (i + 1); }
function fbAdd() {
  var d = document.createElement('div'); d.className = 'fbrow';
  var prov = '<select name="fb_provider[]"><option value="avalai">AvalAI</option>' + (M_AVONLY ? '' : '<option value="openrouter">OpenRouter</option>') + '</select>';
  var price = M_TOK
    ? '<input type="text" name="fb_in[]" class="fbp" dir="ltr" placeholder="ورودی $" title="قیمت دستی ورودی (دلار برای هر ۱ میلیون توکن) — خالی = خودکار"><input type="text" name="fb_out[]" class="fbp" dir="ltr" placeholder="خروجی $" title="قیمت دستی خروجی — خالی = خودکار"><input type="hidden" name="fb_unit[]" value="">'
    : '<input type="hidden" name="fb_in[]" value=""><input type="hidden" name="fb_out[]" value=""><input type="text" name="fb_unit[]" class="fbp" dir="ltr" placeholder="قیمت $" title="قیمت دستی — خالی = خودکار">';
  d.innerHTML = '<span class="fbn"></span><input type="hidden" name="fb_id[]" value="0">' + prov
    + '<input type="text" name="fb_mid[]" class="fbmid" dir="ltr" placeholder="شناسه مدل جایگزین">' + price
    + (M_TOK ? '<button type="button" class="btn btn-sm btn-test" onclick="fbTest(this)">🔌 تست</button>' : '')
    + '<button type="button" class="btn btn-sm" style="background:#fee2e2;color:#dc2626" onclick="fbDel(this)" title="حذف این ردیف">✕</button>';
  document.getElementById('fblist').appendChild(d); fbNum(); d.querySelector('.fbmid').focus();
}
function fbDel(btn) { btn.closest('.fbrow').remove(); fbNum(); }
function mFlag(cb, id, field) {
  var lab = cb.closest('label') || cb; cb.disabled = true;
  mPost({ act: 'model_flag', model_id: id, field: field, val: cb.checked ? 1 : 0 })
    .then(function (d) { if (!d.ok) cb.checked = !cb.checked; mShow(lab, d.msg, d.ok); })
    .catch(function () { cb.checked = !cb.checked; mShow(lab, 'ذخیره نشد؛ ارتباط با سرور برقرار نشد.', false); })
    .then(function () { cb.disabled = false; });
}
function mMargin(inp, id) {
  mPost({ act: 'model_margin', model_id: id, margin: inp.value })
    .then(function (d) {
      if (d.ok) { var td = mCell(id, '.c-toman b'); if (td) td.textContent = d.toman; }
      mShow(inp, d.msg, d.ok);
    })
    .catch(function () { mShow(inp, 'ذخیره نشد؛ ارتباط با سرور برقرار نشد.', false); });
}
function mRefresh(btn, id) {
  btn.disabled = true;
  mPost({ act: 'price_refresh', model_id: id })
    .then(function (d) {
      if (d.ok) { var a = mCell(id, '.c-toman b'), b = mCell(id, '.c-usd .v'); if (a) a.textContent = d.toman; if (b) b.textContent = d.usd || '—'; }
      mShow(btn, d.msg, d.ok, !d.ok);
    })
    .catch(function () { mShow(btn, 'ارتباط با سرور برقرار نشد.', false); })
    .then(function () { btn.disabled = false; });
}
mPrice(); fbNum();
</script>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
