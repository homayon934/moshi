<?php
/**
 * upload_handler.php — آپلود امن تصویر برای پنل ادمین
 * فقط از طریق admin AJAX قابل فراخوانی است
 */
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── بررسی ورود ───────────────────────────────────────────────────────────
if (!$admin_logged_in) {
    http_response_code(403);
    echo json_encode(['error' => 'دسترسی غیر مجاز']); exit;
}

// ── بررسی CSRF ───────────────────────────────────────────────────────────
if (!verify_csrf($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'توکن امنیتی نامعتبر. صفحه را رفرش کنید.']); exit;
}

// ── بررسی وجود فایل ─────────────────────────────────────────────────────
if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $codes = [1=>'حداکثر اندازه فایل',2=>'فایل خیلی بزرگ',3=>'آپلود ناقص',4=>'فایلی ارسال نشد'];
    $code  = $_FILES['file']['error'] ?? 4;
    echo json_encode(['error' => $codes[$code] ?? 'خطا در آپلود (کد: '.$code.')']); exit;
}

$file = $_FILES['file'];

// ── محدودیت حجم: ۵ مگابایت ───────────────────────────────────────────────
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['error' => 'حجم فایل نباید از ۵ مگابایت بیشتر باشد']); exit;
}

// ── بررسی نوع واقعی فایل (MIME) ──────────────────────────────────────────
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

if (!isset($allowed[$mime])) {
    echo json_encode(['error' => 'فرمت مجاز: JPG، PNG، GIF، WebP']); exit;
}

// ── آماده‌سازی پوشه آپلود ────────────────────────────────────────────────
$upload_dir = dirname(__DIR__) . '/uploads/';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        echo json_encode(['error' => 'خطا در ایجاد پوشه آپلود']); exit;
    }
    // جلوگیری از اجرای PHP در پوشه uploads
    file_put_contents($upload_dir . '.htaccess',
        "Options -Indexes\nphp_flag engine off\n" .
        "<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n"
    );
    file_put_contents($upload_dir . 'index.php', '<?php // silence');
}

// ── نام‌گذاری تصادفی (امنیتی) ────────────────────────────────────────────
$filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
$filepath = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['error' => 'خطا در ذخیره فایل روی سرور']); exit;
}

chmod($filepath, 0644);

// ── ساخت URL عمومی فایل ──────────────────────────────────────────────────
$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
// مسیر نسبی: از admin به بالا، سپس uploads/
$script  = dirname(dirname($_SERVER['SCRIPT_NAME']));
$base    = $proto . '://' . $host . rtrim($script, '/');
$url     = $base . '/uploads/' . $filename;

echo json_encode(['success' => true, 'url' => $url, 'filename' => $filename]);
