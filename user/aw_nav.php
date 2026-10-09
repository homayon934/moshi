<?php if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }
/**
 * بخش یکپارچه «پاسخگوی هوشمند» (ویجت سایت): همه صفحه‌های مربوط زیر یک عنوان با زبانه‌ها
 * تنظیمات، پایگاه دانش (با زیرزبانه‌ها)، مکالمات، پیام‌های تماس، کد نصب
 */
function aw_pages()
{
    return ['settings.php', 'knowledge.php', 'products.php', 'answers.php', 'conversations.php', 'leads.php', 'embed.php'];
}

function aw_tabs_list()
{
    $t = [];
    if (saas_can('widget')) $t['settings'] = ['settings.php', '⚙️ تنظیمات'];
    $kb = saas_can('knowledge') ? 'knowledge.php' : (saas_can('products') ? 'products.php' : (saas_can('conversations') ? 'answers.php' : ''));
    if ($kb !== '') $t['kb'] = [$kb, '🧠 پایگاه دانش'];
    if (saas_can('conversations')) $t['conv'] = ['conversations.php', '💬 مکالمات'];
    if (saas_can('widget')) $t['leads'] = ['leads.php', '📞 پیام‌های تماس'];
    if (saas_can('widget')) $t['embed'] = ['embed.php', '🔌 کد نصب'];
    return $t;
}

/** اولین صفحه مجاز این بخش (برای منوی کناری) */
function aw_first_link()
{
    $l = aw_tabs_list();
    return $l ? reset($l)[0] : '';
}

/** سربرگ و زبانه‌های بخش. $sub = توضیح کوتاه کنار عنوان */
function aw_head($current, $sub = '')
{
    global $pdo, $current_user;
    $badge = 0;
    if (function_exists('cx_contact_new_count') && isset($pdo, $current_user['id'])) $badge = cx_contact_new_count($pdo, (int)$current_user['id']);
    $h = '<style>.awt{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.awt a{padding:9px 15px;border-radius:12px;font-size:13.5px;font-weight:700;color:#475569;text-decoration:none;background:#fff;border:1px solid #e2e8f0;white-space:nowrap}.awt a.on{background:var(--primary,#7c3aed);color:#fff;border-color:var(--primary,#7c3aed)}.awt .bd{background:#dc2626;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-right:4px}</style>';
    $h .= '<div class="topbar"><h1>🤖 پاسخگوی هوشمند</h1>' . ($sub !== '' ? '<span style="font-size:13px;color:#64748b">' . $sub . '</span>' : '') . '</div><nav class="awt">';
    foreach (aw_tabs_list() as $k => [$u, $l]) {
        $h .= '<a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '" class="' . ($k === $current ? 'on' : '') . '">' . $l . ($k === 'leads' && $badge ? '<span class="bd">' . (int)$badge . '</span>' : '') . '</a>';
    }
    return $h . '</nav>';
}
