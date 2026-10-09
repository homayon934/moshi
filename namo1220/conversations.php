<?php
    require_once __DIR__ . '/_bootstrap.php';

    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;

    $total = (int)$pdo->query("SELECT COUNT(*) FROM aichat_conversations")->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT c.*, (SELECT COUNT(*) FROM aichat_messages m WHERE m.conversation_id=c.id AND m.role='user') AS msg_count
         FROM aichat_conversations c ORDER BY c.id DESC LIMIT $per_page OFFSET $offset"
    );
    $stmt->execute();
    $conversations = $stmt->fetchAll();

    include __DIR__ . '/_header.php';
?>
    <div class="card">
        <h2>مکالمات (<?php echo number_format($total); ?>)</h2>
        <table>
            <thead><tr><th>#</th><th>نام</th><th>موبایل</th><th>IP</th><th>تعداد سوال</th><th>تاریخ</th><th>عملیات</th></tr></thead>
            <tbody>
                <?php if (empty($conversations)): ?>
                    <tr><td colspan="7" class="muted" style="text-align:center;">هنوز مکالمه‌ای ثبت نشده.</td></tr>
                <?php else: foreach ($conversations as $c): ?>
                    <tr>
                        <td><?php echo (int)$c['id']; ?></td>
                        <td><?php echo !empty($c['full_name']) ? htmlspecialchars((string)$c['full_name'], ENT_QUOTES, 'UTF-8') : '<span class="muted">ناشناس</span>'; ?></td>
                        <td dir="ltr"><?php echo !empty($c['phone']) ? htmlspecialchars((string)$c['phone'], ENT_QUOTES, 'UTF-8') : '<span class="muted">-</span>'; ?></td>
                        <td dir="ltr" class="muted"><?php echo htmlspecialchars((string)$c['ip'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo (int)$c['msg_count']; ?></td>
                        <td class="muted"><?php echo htmlspecialchars((string)$c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><a class="btn btn-outline" href="conversation.php?id=<?php echo (int)$c['id']; ?>">مشاهده</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php $pages = (int)ceil($total / $per_page); if ($pages > 1): ?>
            <div style="margin-top:14px;">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a class="<?php echo $p === $page ? 'btn' : 'btn btn-outline'; ?>" href="?page=<?php echo $p; ?>" style="margin-left:6px;"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
<?php include __DIR__ . '/_footer.php'; ?>