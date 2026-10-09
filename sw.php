<?php
/** سرویس‌کارگر اعلان‌های یادآور پنل‌ها (Web Push) */
require_once __DIR__ . '/config.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('Service-Worker-Allowed: ' . rtrim((string)parse_url(AICHAT_BASE_URL, PHP_URL_PATH), '/') . '/');
require_once __DIR__ . '/includes/rem_lib.php';
$base = rtrim(AICHAT_BASE_URL, '/');
echo rem_sw_js($base . '/push.php?act=pull', $base . '/favicon.php', '');
