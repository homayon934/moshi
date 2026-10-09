<?php
/**
 * گزارش ورود و بازدید (نسخه ۴۷): کاربران و همکاران، مخاطبان، بازدیدکنندگان سایت، مدیر
 */
require_once __DIR__ . '/_bootstrap.php';
act_ensure_schema($pdo);
$bots = [];
try { foreach ($pdo->query("SELECT id, name FROM hd_bots")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) $bots[(int)$b['id']] = $b['name']; } catch (\Throwable $e) {}
$bot_label = fn($p) => $p['bot_id'] && isset($bots[$p['bot_id']]) ? '— ' . $bots[$p['bot_id']] : '';
$html = act_report_html($pdo, ['url' => 'activity.php', 'tabs' => [
    'users'    => ['label' => '👥 کاربران و همکاران', 'kinds' => ['user', 'team']],
    'members'  => ['label' => '🙋 مخاطبان', 'kinds' => ['member', 'maccount'], 'label_fn' => $bot_label],
    'visitors' => ['label' => '🌐 بازدیدکنندگان سایت', 'kinds' => ['visitor']],
    'admin'    => ['label' => '🛡 ورودهای مدیر', 'kinds' => ['admin']],
]]);
include __DIR__ . '/_header.php';
?>
<h2 class="page-title">📈 ورود و بازدیدها</h2>
<p style="color:#64748b;font-size:13px;margin:-6px 0 14px;line-height:2">ورود کاربران، همکاران و مخاطبان، صفحه‌های بازدیدشده، IP، شهر و کشور و دستگاه. شهر و کشور از روی IP تخمین زده می‌شود (با VPN یا اینترنت همراه ممکن است دقیق نباشد). اطلاعات قدیمی‌تر از ۶ ماه خودکار پاک می‌شود.</p>
<?php echo $html; ?>
<?php include __DIR__ . '/_footer.php'; ?>
