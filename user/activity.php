<?php
/**
 * ورود و بازدید مخاطبان + ورودهای حساب من و همکاران (نسخه ۴۷)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/hamdam_lib.php';
$page_title = 'ورود و بازدیدها';
act_ensure_schema($pdo);
$ctx = function_exists('rem_ctx_user') ? rem_ctx_user($current_user, $current_team ?? null) : ['kind' => 'user', 'id' => (int)$current_user['id'], 'owner_uid' => (int)$current_user['id'], 'perms' => ['*']];
$uid = (int)$current_user['id'];
$bots = [];
try { $bq = $pdo->prepare("SELECT id, name FROM hd_bots WHERE user_id=?"); $bq->execute([$uid]); foreach ($bq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) $bots[(int)$b['id']] = $b['name']; } catch (\Throwable $e) {}
$tabs = [];
$can_members = function_exists('cm_role_ok') ? cm_role_ok($ctx) : empty($current_team);
if ($can_members && saas_can('bots_view')) {
    $mt = ['label' => '🙋 مخاطبان من', 'kinds' => ['member'], 'owner' => $uid, 'label_fn' => fn($p) => $p['bot_id'] && isset($bots[$p['bot_id']]) ? '— ' . $bots[$p['bot_id']] : ''];
    if (!empty($current_team) && function_exists('cm_member_scope')) {
        // مشاور: فقط مراجعان خودش
        $sp = [];
        $sw = cm_member_scope($ctx, $sp);
        $mt['where'] = "a.actor_id IN (SELECT m.id FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE $sw)";
        $mt['params'] = $sp;
        // جزئیات هم فقط برای همین افراد
        if (preg_match('/^member:(\d+)$/', (string)($_GET['who'] ?? ''), $wm)) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM hd_members m JOIN hd_bots b ON b.id=m.bot_id WHERE m.id=? AND $sw");
            $chk->execute(array_merge([(int)$wm[1]], $sp));
            if (!(int)$chk->fetchColumn()) $_GET['who'] = '';
        }
    }
    $tabs['members'] = $mt;
}
if (empty($current_team)) $tabs['me'] = ['label' => '🔑 ورودهای حساب من و همکاران', 'kinds' => ['user', 'team'], 'owner' => $uid];
if (!$tabs) { header('Location: index.php?denied=1'); exit; }
$html = act_report_html($pdo, ['url' => 'activity.php', 'tabs' => $tabs]);
include __DIR__ . '/_header.php';
?>
<div class="card" style="padding:12px 16px;font-size:13px;color:#64748b;line-height:2">ورود مخاطبان به چت‌بات‌ها و پنل مخاطب، صفحه‌های بازدیدشده، IP، شهر و کشور و دستگاه. شهر و کشور از روی IP تخمین زده می‌شود. اطلاعات قدیمی‌تر از ۶ ماه خودکار پاک می‌شود.</div>
<?php echo $html; ?>
<?php include __DIR__ . '/_footer.php'; ?>
