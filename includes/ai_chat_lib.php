<?php
/**
 * کتابخانه هوش مصنوعی - AI Chat Widget SaaS (چندکاربره)
 * مدیریت فراخوانی AI، ساخت پرامپت، جستجوی دانش و محصولات
 */

if (!defined('AICHAT_AI_DOWN_MSG')) {
    // پیام ثابت برای بازدیدکننده هنگام در دسترس نبودن سرویس هوش مصنوعی (جزئیات فنی فقط در لاگ سرور)
    define('AICHAT_AI_DOWN_MSG', 'با توجه به قطعی شبکه، فعلاً امکان پاسخگویی نیست.');
}

if (!function_exists('aichat_call_ai')) {

    /**
     * فراخوانی سرویس AI با پشتیبانی از دو پروایدر و failover خودکار
     */
    function aichat_call_ai(array $cfg, array $messages, int $max_tokens = 600, float $temperature = 0.7): array
    {
        $priority = $cfg['provider_priority'] ?? 'avalai';

        $providers = $priority === 'openrouter'
            ? ['openrouter', 'avalai']
            : ['avalai', 'openrouter'];

        $last_error = 'هیچ سرویس AI فعالی پیکربندی نشده است.';

        foreach ($providers as $provider) {
            if ($provider === 'avalai' && !empty($cfg['avalai_enabled']) && !empty($cfg['avalai_api_key'])) {
                $result = _aichat_call_avalai($cfg, $messages, $max_tokens, $temperature);
                if ($result['ok']) return $result;
                $last_error = $result['error'] ?? 'خطا در AvalAI';
            }
            if ($provider === 'openrouter' && !empty($cfg['openrouter_enabled']) && !empty($cfg['openrouter_api_key'])) {
                $result = _aichat_call_openrouter($cfg, $messages, $max_tokens, $temperature);
                if ($result['ok']) return $result;
                $last_error = $result['error'] ?? 'خطا در OpenRouter';
            }
        }

        // جزئیات فنی (مثل کلید نامعتبر) فقط در error_log سرور ثبت می‌شود و هرگز به بازدیدکننده نمایش داده نمی‌شود
        error_log('[AI] all providers failed: ' . $last_error);
        return ['ok' => false, 'error' => AICHAT_AI_DOWN_MSG, 'detail' => $last_error];
    }

    /**
     * فراخوانی AvalAI (OpenAI-compatible endpoint)
     */
    function _aichat_call_avalai(array $cfg, array $messages, int $max_tokens = 600, float $temperature = 0.7): array
    {
        $base_url = rtrim($cfg['avalai_api_base'] ?? 'https://api.avalai.ir/v1', '/');
        return _aichat_openai_request(
            $base_url . '/chat/completions',
            $cfg['avalai_api_key'],
            $cfg['avalai_model'] ?? 'gpt-4o-mini',
            $messages,
            $max_tokens,
            [],
            $temperature
        );
    }

    /**
     * فراخوانی OpenRouter
     */
    function _aichat_call_openrouter(array $cfg, array $messages, int $max_tokens = 600, float $temperature = 0.7): array
    {
        $base_url = rtrim($cfg['openrouter_api_base'] ?? 'https://openrouter.ai/api/v1', '/');
        return _aichat_openai_request(
            $base_url . '/chat/completions',
            $cfg['openrouter_api_key'],
            $cfg['openrouter_model'] ?? 'google/gemini-2.5-flash',
            $messages,
            $max_tokens,
            ['HTTP-Referer: ' . (defined('AICHAT_BASE_URL') ? AICHAT_BASE_URL : 'https://localhost'), 'X-Title: AI Chat Widget'],
            $temperature
        );
    }

    /**
     * درخواست HTTP به API سازگار با OpenAI
     */
    function _aichat_openai_request(string $url, string $api_key, string $model, array $messages, int $max_tokens, array $extra_headers = [], float $temperature = 0.7): array
    {
        $payload = json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => max(0.0, min(1.5, $temperature)),
        ], JSON_UNESCAPED_UNICODE);

        $headers = array_merge(
            ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            $extra_headers
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            error_log('[AI] CURL: ' . $curl_err . ' url=' . $url);
            return ['ok' => false, 'error' => 'خطا در اتصال شبکه‌ای به سرویس AI.'];
        }

        $data = json_decode($response, true);

        if (!isset($data['choices'][0]['message']['content'])) {
            $err = $data['error']['message'] ?? ('HTTP ' . $http_code);
            error_log('[AI] API error: ' . $err . ' | ' . substr($response, 0, 500));
            return ['ok' => false, 'error' => 'خطا از سرویس AI: ' . $err];
        }

        return [
            'ok'      => true,
            'content' => trim($data['choices'][0]['message']['content']),
            'tokens'  => (int)($data['usage']['total_tokens'] ?? 0),
            'prompt_tokens'     => (int)($data['usage']['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($data['usage']['completion_tokens'] ?? 0),
            'model'   => (string)($data['model'] ?? $model),
        ];
    }

    /**
     * ساخت پرامپت سیستمی بر اساس تنظیمات کاربر SaaS
     *
     * @param \PDO   $pdo
     * @param int    $user_id
     * @param array  $user         — داده کاربر (از saas_get_user_by_site_key)
     * @param array  $ws           — تنظیمات ویجت (از saas_get_widget_settings)
     * @param array|null $plan     — پلن فعال کاربر
     * @param string $user_message — پیام ورودی (برای جستجوی contextual)
     * @param array|null $conv     — مکالمه جاری (برای نام بازدیدکننده)
     * @param string $search_text  — متن جستجو (پیام فعلی + پیام‌های اخیر کاربر)
     * @return string
     *
     * پس از اجرا، $GLOBALS['aichat_link_images'] نقشه «لینک محصول ← تصویر» را نگه می‌دارد
     * تا ویجت به‌جای favicon، تصویر کوچک محصول را نمایش دهد.
     */
    /** واقع‌گرایی و پایبندی به منابع ویجت: تنظیم اختصاصی مدیر برای این کاربر یا پیش‌فرض سامانه */
    function aichat_widget_levels($pdo, array $ws): array
    {
        $s = function_exists('biz_settings') ? biz_settings($pdo) : [];
        $dr = isset($s['ws_realism']) && $s['ws_realism'] !== '' ? (int)$s['ws_realism'] : 70;
        $dg = isset($s['ws_grounding']) && $s['ws_grounding'] !== '' ? (int)$s['ws_grounding'] : 80;
        $r = (int)($ws['realism'] ?? -1);
        $g = (int)($ws['grounding'] ?? -1);
        return ['realism' => max(0, min(100, $r >= 0 ? $r : $dr)), 'grounding' => max(0, min(100, $g >= 0 ? $g : $dg))];
    }

    /**
     * انتخاب هوشمند موضوعات پایگاه دانش وقتی جستجوی کلمه‌ای نتیجه روشنی ندارد
     * (پرسش با کلمات متفاوت از متن موضوع). فهرست کوتاه عنوان‌ها و کلیدواژه‌ها به مدل داده می‌شود.
     * هزینه به $GLOBALS['aichat_extra_tokens'] اضافه می‌شود تا با پیام کسر شود.
     */
    function aichat_knowledge_ai_pick($pdo, array $catalog, string $question, $model = null): array
    {
        if (count($catalog) < 2 || trim($question) === '') return [];
        $lines = [];
        foreach (array_slice($catalog, 0, 150) as $i => $c) {
            $lines[] = ($i + 1) . ') ' . $c['title'] . ($c['keywords'] !== '' ? ' — کلیدواژه: ' . mb_substr($c['keywords'], 0, 120) : '') . ' — ' . mb_substr(preg_replace('/\s+/u', ' ', $c['text']), 0, 120);
        }
        $msgs = [
            ['role' => 'system', 'content' => "از فهرست موضوعات پایگاه دانش یک کسب‌وکار، شماره موضوعاتی را که برای پاسخ به پرسش کاربر لازم است انتخاب کن (حداکثر ۴ مورد، مرتبط‌ترین اول). فقط شماره‌ها را با ویرگول بنویس (مثال: 3,7). اگر هیچ موضوعی مرتبط نیست بنویس: 0"],
            ['role' => 'user', 'content' => "پرسش کاربر:\n" . mb_substr($question, 0, 1200) . "\n\nموضوعات:\n" . implode("\n", $lines)],
        ];
        try {
            $cfg = saas_get_api_config($pdo);
            $r = function_exists('biz_ai_call') ? biz_ai_call($pdo, $cfg, $model, $msgs, 30, 0.0) : aichat_call_ai($cfg, $msgs, 30, 0.0);
        } catch (\Throwable $e) { return []; }
        if (empty($r['ok'])) return [];
        $t = function_exists('biz_charge_tokens') ? biz_charge_tokens($pdo, $r['model_row'] ?? $model, $r) : (int)($r['tokens'] ?? 0);
        $GLOBALS['aichat_extra_tokens'] = (int)($GLOBALS['aichat_extra_tokens'] ?? 0) + $t;
        $out = [];
        if (preg_match_all('/\d+/', strtr((string)$r['content'], ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $mm)) {
            foreach ($mm[0] as $n) { $n = (int)$n - 1; if (isset($catalog[$n]) && !in_array($n, $out, true)) $out[] = $n; if (count($out) >= 4) break; }
        }
        return $out;
    }

    function aichat_build_system_prompt_saas($pdo, int $user_id, array $user, array $ws, ?array $plan, string $user_message = '', ?array $conv = null, string $search_text = ''): string
    {
        $parts = [];
        $GLOBALS['aichat_link_images'] = [];
        if ($search_text === '') $search_text = $user_message;

        // ۱. هویت دستیار و قوانین کلی
        $bot_name = $ws['widget_title'] ?? 'دستیار هوشمند';
        $parts[] = "تو «{$bot_name}» هستی، دستیار هوشمند اختصاصی این کسب‌وکار. به زبان فارسی، مودبانه، گرم و حرفه‌ای پاسخ بده.\n"
            . "منبع اصلی پاسخ‌های تو «پایگاه دانش»، «محصولات» و «اطلاعات کسب‌وکار» است که در ادامه آمده. همیشه اول این اطلاعات را کامل و با دقت بخوان و پاسخ را از آن‌ها بده؛ اگر پاسخ در آن‌ها هست، هرگز نگو اطلاعی نداری.\n"
            . "سابقه گفتگو (پیام‌های قبلی همین مکالمه) در اختیار توست: حتماً آن را در نظر بگیر، سوال‌های تکراری نپرس، به موضوعات قبلی ارجاع بده و اگر کاربر با «این»، «اون» یا «همین» به مورد قبلی اشاره کرد، از سابقه بفهم منظورش چیست.";

        // ۱-ب. واقع‌گرایی و پایبندی به منابع (تنظیم مدیر سامانه)
        $lv = aichat_widget_levels($pdo, $ws);
        $GLOBALS['aichat_widget_levels'] = $lv;
        if ($lv['grounding'] >= 90) {
            $parts[] = "پایبندی به اطلاعات (اجباری): فقط از اطلاعاتی که در ادامه آمده (پایگاه دانش، محصولات، مطالب سایت و اطلاعات کسب‌وکار) پاسخ بده. اگر پاسخ در آن‌ها نیست، حدس نزن و چیزی نساز؛ کوتاه بگو اطلاعات دقیقی در این مورد نداری و در صورت وجود، راه تماس را معرفی کن.";
        } elseif ($lv['grounding'] >= 60) {
            $parts[] = "پایبندی به اطلاعات: اولویت کامل با اطلاعاتی است که در ادامه آمده. اگر پاسخ در آن‌ها نیست، می‌توانی از دانش عمومی کوتاه کمک بگیری، اما صریح بگو این بخش از اطلاعات رسمی ما نیست. قیمت، موجودی، شرایط، شماره و آدرس را هرگز حدس نزن.";
        } else {
            $parts[] = "پایبندی به اطلاعات: از اطلاعاتی که در ادامه آمده استفاده کن و در صورت نیاز آزادانه از دانش عمومی کمک بگیر؛ ولی قیمت، موجودی، شرایط، شماره و آدرس را فقط از اطلاعات همین کسب‌وکار بگو و هرگز حدس نزن.";
        }
        if ($lv['realism'] >= 80) $parts[] = "لحن: کاملاً واقع‌بین و صریح؛ محدودیت‌ها و نکات منفی را بی‌پرده ولی محترمانه بگو و وعده غیرواقعی نده.";
        elseif ($lv['realism'] >= 50) $parts[] = "لحن: واقع‌بین و صادق، همراه با همدلی و لحن حمایتگر؛ واقعیت‌ها را پنهان نکن.";
        else $parts[] = "لحن: گرم، مثبت و امیدبخش؛ نکات منفی را با ملایمت و همراه با راهکار بگو (ولی هرگز اطلاعات نادرست نده).";

        // ۲. نام بازدیدکننده
        $visitor_name = trim((string)($conv['visitor_name'] ?? ''));
        if ($visitor_name !== '') {
            $parts[] = "نام کاربری که با او صحبت می‌کنی «{$visitor_name}» است. او را با نامش صدا بزن (مثلاً «{$visitor_name} عزیز») — در ابتدای پاسخ‌ها به‌صورت طبیعی، نه در تک‌تک جمله‌ها.";
        } else {
            $parts[] = "نام کاربر هنوز مشخص نیست. اگر در سابقه گفتگو نامش را گفته، از همان استفاده کن و او را با نامش صدا بزن. اگر هنوز نامش را نمی‌دانی، در اولین پاسخ بعد از جواب‌دادن به سوالش، خیلی کوتاه و مودبانه نامش را بپرس (فقط یک بار).";
        }

        // ۳. قوانین قالب‌بندی
        $parts[] = "قوانین قالب‌بندی پاسخ (اجباری):\n"
            . "- پاسخ را به‌صورت آیتم‌وار بنویس: هر نکته، گزینه، ویژگی یا مرحله در یک خط جداگانه که با «- » شروع شود. فقط یک جمله مقدمه کوتاه (در صورت نیاز) بدون «- » بنویس.\n"
            . "- از شماره‌گذاری (1. 2.)، ستاره، هشتگ، جدول و علامت‌های Markdown دیگر استفاده نکن؛ فقط «- ». برای تأکید می‌توانی **متن** بنویسی.\n"
            . "- شماره تلفن را همیشه به‌تنهایی در یک خط جداگانه و با ارقام انگلیسی بنویس (مثال: 02112345678 یا 09121234567).\n"
            . "- هر لینک را فقط به شکل [عنوان کوتاه](https://...) بنویس و آدرس لینک را دقیقاً همان‌طور که در اطلاعات آمده کپی کن. هرگز آدرس لینک نساز.\n"
            . "- وقتی محصولی را معرفی می‌کنی، لینک آن را هم به شکل [نام محصول](لینک) بیاور.\n"
            . "- آدرس لینک را هرگز جداگانه یا به‌صورت متن ننویس؛ فقط داخل پرانتزِ [عنوان](آدرس). عنوان کوتاه و گویا باشد (مثلاً [مسیریابی در نشان](...)).";
        // لحن هم‌سو با مخاطب، شکلک فقط وقتی مخاطب فرستاد، توضیح اصطلاحات تخصصی، پاسخ متناسب با سؤال
        $parts[] = function_exists('cx_tone_rules') ? cx_tone_rules() . (function_exists('cx_tone_hint') ? cx_tone_hint($user_message) : '')
            : "- برای صمیمی‌تر شدن پاسخ، در هر پاسخ ۱ تا ۳ شکلک (ایموجی) مناسب و مرتبط استفاده کن؛ زیاده‌روی نکن.";

        // ۳-ب. پاسخ دقیق و بدون حاشیه
        $parts[] = "قوانین محتوای پاسخ (اجباری):\n"
            . "- فقط به همان چیزی که کاربر پرسیده پاسخ بده؛ مقدمه‌چینی، تکرار سؤال، جمع‌بندی، توضیح اضافه و پیشنهادهای نامرتبط ننویس.\n"
            . "- شماره تماس، آدرس یا دعوت به تماس/مراجعه را به پایان پاسخ اضافه نکن؛ فقط وقتی بده که کاربر خودش خواست، قصد خرید یا ثبت سفارش دارد، یا پاسخ سؤالش را نداری.\n"
            . "- اگر پاسخ به شرایط کاربر بستگی دارد (مثلاً نوع کسب‌وکارش) و آن را نمی‌دانی، فهرست همه حالت‌ها را ننویس؛ کوتاه و در یک جمله همان نکته را بپرس (حداکثر با ۲ مثال).\n"
            . "- اطلاعاتی را که در همین گفتگو داده‌ای دوباره تکرار نکن.";

        // ۴-۵. («فایل راهنما» و «اطلاعات کلی سایت» حذف شدند؛ همه اطلاعات در پایگاه دانش است)
        if (!empty($ws['contact_phone'])) {
            $parts[] = "شماره تماس پشتیبانی (فقط وقتی لازم است، طبق قوانین بالا): " . $ws['contact_phone'];
        }

        // ۶. طول پاسخ
        $length = $ws['response_length'] ?? ($plan['response_length'] ?? 'medium');
        $length_guide = [
            'short'  => 'پاسخ‌هایت کوتاه باشد (حداکثر ۳ آیتم یا ۳ جمله).',
            'medium' => 'پاسخ‌هایت کامل اما خلاصه باشد (حداکثر حدود ۶ آیتم).',
            'long'   => 'پاسخ‌هایت جامع و کامل باشد.',
        ];
        if (isset($length_guide[$length])) $parts[] = $length_guide[$length];

        // ۷. الگوی پاسخ‌گویی (پایگاه دانش ← الگوی پاسخ‌گویی)
        $tmpl = trim($ws['response_template'] ?? '');
        // راهنما و سوالات متداول کاربر برای مخاطبانش (بخش پشتیبانی پنل)
        if (function_exists('cx_user_articles')) {
            try { $ux = cx_articles_text(cx_user_articles($pdo, $user_id, 'w'), $search_text, 4000); if ($ux !== '') $parts[] = "\n=== راهنما و سوالات متداول ما (برای پاسخ به سوال‌های مشابه) ===\n" . $ux . "\n=== پایان راهنما ==="; } catch (\Throwable $e) {}
        }
        if ($tmpl) $parts[] = "\n=== الگوی پاسخ‌گویی صاحب کسب‌وکار (اجباری؛ در همه پاسخ‌ها رعایت کن مگر با قوانین دقت و قالب بالا تناقض داشته باشد) ===\n" . mb_substr($tmpl, 0, 4000) . "\n=== پایان الگوی پاسخ‌گویی ===";

        // ۷-ب. موضوعات «همیشه خوانده شود» پایگاه دانش
        $pinned_titles = [];
        if (saas_plan_allows($plan, 'has_knowledge_base') && function_exists('kb_pinned_topics')) {
            $pins = kb_pinned_topics($pdo, $user_id);
            if ($pins) {
                $parts[] = "\n=== اطلاعات ثابت کسب‌وکار (همیشه معتبر؛ کامل بخوان) ===";
                foreach ($pins as $pt) {
                    $pinned_titles[] = trim((string)$pt['title']);
                    $parts[] = "【{$pt['title']}】\n" . mb_substr(trim(strip_tags((string)$pt['content'])), 0, 3000);
                }
                $parts[] = "=== پایان اطلاعات ثابت ===";
            }
        }

        // ۸. پایگاه دانش — همیشه جستجو می‌شود
        $knowledge = [];
        $kb_allowed = saas_plan_allows($plan, 'has_knowledge_base');
        try {
            $knowledge = $kb_allowed ? saas_search_knowledge($pdo, $user_id, $search_text) : [];
        } catch (\Throwable $e) {
            error_log('[AI] knowledge search: ' . $e->getMessage());
            $knowledge = [];
        }
        // اگر پایگاه دانش بزرگ است و جستجوی کلمه‌ای موضوع روشنی پیدا نکرد، موضوع با هوش مصنوعی انتخاب می‌شود
        if ($kb_allowed && $user_message !== '') {
            try {
                $catalog = saas_knowledge_catalog($pdo, $user_id);
                $total = 0; foreach ($catalog as $c) $total += mb_strlen($c['title']) + mb_strlen($c['text']);
                $best = 0; foreach ($knowledge as $k) $best = max($best, (int)($k['score'] ?? 0));
                if (count($catalog) > 3 && $total > 10000 && $best < 5) {
                    $model = function_exists('biz_resolve_model') ? biz_resolve_model($pdo, $plan, (int)($ws['ai_model_id'] ?? 0), 'widget') : null;
                    $picks = aichat_knowledge_ai_pick($pdo, $catalog, $search_text, $model);
                    if ($picks) {
                        $have = array_map(fn($k) => $k['title'], $knowledge);
                        $add = [];
                        foreach ($picks as $i) {
                            $c = $catalog[$i];
                            if (in_array($c['title'], $have, true)) continue;
                            $add[] = ['title' => $c['title'], 'keywords' => $c['keywords'], 'text' => saas_best_snippet($c['text'], saas_search_terms($search_text), 1500, 3000), 'score' => 10];
                        }
                        $knowledge = array_merge($add, array_values(array_filter($knowledge, fn($k) => (int)($k['score'] ?? 0) > 0)));
                    }
                }
            } catch (\Throwable $e) { error_log('[AI] knowledge pick: ' . $e->getMessage()); }
        }
        if ($pinned_titles) $knowledge = array_values(array_filter($knowledge, fn($k) => !in_array(trim((string)$k['title']), $pinned_titles, true)));
        if ($knowledge) {
            $parts[] = "\n=== پایگاه دانش (منبع اصلی پاسخ؛ پیش از پاسخ کامل بخوان) ===";
            foreach ($knowledge as $k) {
                $parts[] = "【موضوع: {$k['title']}】\n{$k['text']}";
            }
            $parts[] = "=== پایان پایگاه دانش ===";
        }
        if ($kb_allowed) {
            try {
                $titles = saas_knowledge_titles($pdo, $user_id);
                $shown  = array_map(fn($k) => $k['title'], $knowledge);
                $others = array_values(array_diff($titles, $shown, $pinned_titles));
                if ($others) {
                    $parts[] = "موضوعات دیگری هم در پایگاه دانش وجود دارد (اگر کاربر درباره آن‌ها پرسید، بگو که در این زمینه خدمات/اطلاعات داریم و از او بخواه سوالش را دقیق‌تر بپرسد): " . implode('، ', array_slice($others, 0, 40));
                }
            } catch (\Throwable $e) {}
        }

        // ۹. محصولات داخلی
        $products = [];
        try {
            $products = saas_plan_allows($plan, 'has_products') ? saas_search_products($pdo, $user_id, $search_text, 5) : [];
        } catch (\Throwable $e) {
            error_log('[AI] product search: ' . $e->getMessage());
        }
        if ($products) {
            $parts[] = "\n=== محصولات مرتبط ===";
            foreach ($products as $p) {
                $prod = "- {$p['title']}";
                if (!empty($p['price'])) $prod .= " | قیمت: " . number_format((int)$p['price']) . " تومان";
                if (!empty($p['description'])) {
                    // تا ۱۰۰۰ کاراکتر از مرتبط‌ترین بخش توضیحات محصول
                    $prod .= "\n  توضیحات: " . saas_best_snippet(trim($p['description']), saas_search_terms($search_text), 1000, 1000);
                }
                if (!empty($p['url'])) {
                    $prod .= "\n  لینک: {$p['url']}";
                    if (!empty($p['image_url'])) $GLOBALS['aichat_link_images'][$p['url']] = $p['image_url'];
                }
                $parts[] = $prod;
            }
            $parts[] = "=== پایان محصولات ===\nاگر کاربر درباره محصولی پرسید، اطلاعات، قیمت و لینک آن را دقیقاً از لیست بالا بده.";
        }
        // ۹-الف. اگر تعداد محصولات کم است، بقیه هم به‌صورت فهرست کوتاه (تا مدل همه محصولات را بشناسد)
        if (saas_plan_allows($plan, 'has_products')) {
            try {
                $st_all = $pdo->prepare("SELECT id, title, price, url FROM saas_products WHERE user_id=? ORDER BY id DESC LIMIT 31");
                $st_all->execute([$user_id]);
                $allp = $st_all->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                if ($allp && count($allp) <= 30) {
                    $shown_p = array_map(fn($p) => (int)($p['id'] ?? 0), $products);
                    $rest = array_values(array_filter($allp, fn($p) => !in_array((int)$p['id'], $shown_p, true)));
                    if ($rest) {
                        $lines = [];
                        foreach ($rest as $p) $lines[] = '- ' . $p['title'] . (!empty($p['price']) ? ' | قیمت: ' . number_format((int)$p['price']) . ' تومان' : '') . (!empty($p['url']) ? ' | لینک: ' . $p['url'] : '');
                        $parts[] = ($products ? "سایر محصولات:\n" : "\n=== فهرست محصولات ===\n") . implode("\n", $lines);
                        $products = $products ?: $rest;
                    }
                }
            } catch (\Throwable $e) {}
        }

        // ۹-ب. خدمات
        $services_txt = '';
        if (saas_plan_allows($plan, 'has_knowledge_base') && function_exists('kb_services_prompt')) {
            try { $services_txt = kb_services_prompt($pdo, $user_id, $search_text); } catch (\Throwable $e) { error_log('[AI] services: ' . $e->getMessage()); }
            if ($services_txt !== '') $parts[] = $services_txt;
        }

        // ۱۰. API محصولات سایت (ووکامرس / وردپرس / JSON / صفحه جستجو — تشخیص خودکار)
        $api_products = [];
        if (!empty($ws['product_api_url']) && $user_message !== '' && saas_plan_allows($plan, 'has_product_api')) {
            try {
                $api_products = saas_fetch_product_from_api(
                    $ws['product_api_url'], $ws['product_api_key'] ?? '', $search_text,
                    $pdo, $user_id, $ws['product_api_mode'] ?? ''
                );
            } catch (\Throwable $e) {
                error_log('[AI] product API: ' . $e->getMessage());
                $api_products = [];
            }
            if ($api_products) {
                $parts[] = "\n=== محصولات موجود در سایت (اطلاعات زنده از سایت) ===";
                foreach (array_slice($api_products, 0, 12) as $i => $ap) {
                    $pi = "- " . $ap['title'];
                    if (!empty($ap['price_text'])) $pi .= " | قیمت: " . $ap['price_text'];
                    if ($ap['stock'] !== null) $pi .= " | " . ($ap['stock'] ? 'موجود' : 'ناموجود');
                    if (!empty($ap['description']) && $i < 5) {
                        // ۱۰۰۰ کاراکتر برای مرتبط‌ترین محصول، ۵۰۰ برای بقیه
                        $lim = $i === 0 ? 1000 : 500;
                        $pi .= "\n  توضیحات: " . saas_best_snippet($ap['description'], saas_search_terms($search_text), $lim, $lim);
                    }
                    if (!empty($ap['url'])) {
                        $pi .= "\n  لینک: {$ap['url']}";
                        if (!empty($ap['image'])) $GLOBALS['aichat_link_images'][$ap['url']] = $ap['image'];
                    }
                    $parts[] = $pi;
                }
                $parts[] = "=== پایان محصولات سایت ===\nاین محصولات مستقیماً از سایت خوانده شده‌اند؛ قیمت و موجودی را از همین‌جا بگو و لینک محصول را بده. قیمت را دقیقاً با همان عدد و واحدی که آمده (ریال یا تومان) بگو و خودت تبدیل نکن. اگر کاربر فهرست محصولات یا قیمت‌ها را خواست، محصولات بالا را آیتم‌وار با قیمت و لینک معرفی کن.";
            }
        }

        // ۱۱. مطالب (وبلاگ) و صفحات ثابت سایت
        $site_found = false;
        foreach (['page' => ['pages', 'has_site_pages', 'صفحات سایت'], 'post' => ['posts', 'has_site_posts', 'مطالب مرتبط از وبلاگ سایت']] as $kind => [$ck, $feat, $label]) {
            $c_url = trim((string)($ws['site_' . $ck . '_url'] ?? ''));
            $c_ext = trim((string)($ws['site_' . $ck . '_extra'] ?? ''));
            if (($c_url === '' && $c_ext === '') || $user_message === '' || !saas_plan_allows($plan, $feat)) continue;
            try {
                $items = saas_site_content_search($pdo, $user_id, $kind, $search_text, $kind === 'page' ? 2 : 3, $c_url, $c_ext);
            } catch (\Throwable $e) {
                error_log('[AI] site content: ' . $e->getMessage());
                $items = [];
            }
            if (!$items) continue;
            $site_found = true;
            $parts[] = "\n=== {$label} ===";
            foreach ($items as $it) {
                $parts[] = "【{$it['title']}】\nلینک: {$it['url']}\n{$it['text']}";
            }
            $parts[] = "=== پایان {$label} ===";
        }
        if ($site_found) {
            $parts[] = "اگر پاسخ از مطالب یا صفحات سایت است، در پایان لینک همان مطلب/صفحه را به شکل [عنوان](لینک) معرفی کن.";
        }

        if (!$knowledge && !$products && !$api_products && !$site_found && !$pinned_titles && $services_txt === '') {
            $parts[] = "توجه: هنوز اطلاعاتی برای این کسب‌وکار ثبت نشده است. فقط به‌صورت کلی و مودبانه پاسخ بده و اطلاعات تخصصی نساز.";
        }

        return implode("\n", $parts);
    }

} // end if !function_exists
