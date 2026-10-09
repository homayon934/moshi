<?php
/** ثبت بازدید / کلیک پاپ‌آپ‌ها (تصویر ۱×۱) */
header('Content-Type: image/gif');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
$id = (int)($_GET['pp'] ?? 0);
$ev = ($_GET['ev'] ?? '') === 'click' ? 'clicks' : 'views';
if ($id > 0) {
    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/saas_lib.php';
        $pdo = aichat_connect();
        if (function_exists('ex_popup_hit')) ex_popup_hit($pdo, $id, $ev);
    } catch (\Throwable $e) {}
}
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
