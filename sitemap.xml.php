<?php
/**
 * sitemap.xml — تولید داینامیک نقشه سایت
 * آدرس دسترسی: tum.ir/ai-chat/sitemap.xml.php
 * می‌توانید با Rewrite Rule آدرس را به sitemap.xml تبدیل کنید
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_lib.php';

site_security_headers();
header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

$pdo      = aichat_connect();
site_ensure_schema($pdo);
$s        = site_settings($pdo);
$base_url = rtrim(AICHAT_BASE_URL, '/') . '/';
$today    = date('Y-m-d');

$pages = site_get_pages($pdo, true);
$posts = site_get_posts($pdo, true);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9
        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">

  <!-- صفحه اصلی -->
  <url>
    <loc><?php echo htmlspecialchars($base_url, ENT_XML1); ?></loc>
    <lastmod><?php echo $today; ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>

  <!-- صفحات ثابت -->
  <?php foreach ($pages as $pg): ?>
  <url>
    <loc><?php echo htmlspecialchars($base_url . 'page.php?slug=' . urlencode($pg['slug']), ENT_XML1); ?></loc>
    <lastmod><?php echo htmlspecialchars(substr($pg['created_at'], 0, 10), ENT_XML1); ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>
  <?php endforeach; ?>

  <!-- مطالب -->
  <?php foreach ($posts as $po): ?>
  <url>
    <loc><?php echo htmlspecialchars($base_url . 'post.php?slug=' . urlencode($po['slug']), ENT_XML1); ?></loc>
    <lastmod><?php echo htmlspecialchars(substr($po['created_at'], 0, 10), ENT_XML1); ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
  <?php endforeach; ?>

</urlset>
