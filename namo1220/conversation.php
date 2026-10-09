<?php
    require_once __DIR__ . '/_bootstrap.php';

    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM aichat_conversations WHERE id=?");
    $stmt->execute([$id]);
    $conv = $stmt->fetch();

    if (!$conv) {
        include __DIR__ . '/_header.php';
        echo '<div class="card">مکالمه پیدا نشد.</div>';
        include __DIR__ . '/_footer.php';
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM aichat_messages WHERE conversation_id=? ORDER BY id ASC");
    $stmt->execute([$id]);
    $messages = $stmt->fetchAll();

    // مکالمات دیگر همین کاربر بر اساس شماره تلفن
    $other_convs = [];
    if (!empty($conv['phone'])) {
        $other_stmt = $pdo->prepare("SELECT id, created_at FROM aichat_conversations WHERE phone=? AND id != ? ORDER BY id DESC LIMIT 5");
        $other_stmt->execute([$conv['phone'], $id]);
        $other_convs = $other_stmt->fetchAll();
    }

    include __DIR__ . '/_header.php';
?>
    <div class="card">
        <a href="conversations.php" class="muted">← بازگشت به لیست مکالمات</a>
        <h2 style="margin-top:14px;">
            مکالمه‌ی #<?php echo (int)$conv['id']; ?>
            <?php if (!empty($conv['full_name'])): ?> - <?php echo htmlspecialchars($conv['full_name'], ENT_QUOTES, 'UTF-8'); ?><?php else: ?> - ناشناس<?php endif; ?>
            <?php if (!empty($conv['phone'])): ?> (<span dir="ltr"><?php echo htmlspecialchars($conv['phone'], ENT_QUOTES, 'UTF-8'); ?></span>)<?php endif; ?>
        </h2>
        <p class="muted">IP: <span dir="ltr"><?php echo htmlspecialchars((string)$conv['ip'], ENT_QUOTES, 'UTF-8'); ?></span> — شروع: <?php echo htmlspecialchars((string)$conv['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
        
        <?php if (!empty($other_convs)): ?>
            <div style="margin-top:10px; padding:8px 12px; background:#f8f9fa; border-radius:6px; font-size:0.9em;">
                <b>سایر مکالمات این کاربر:</b>
                <?php foreach ($other_convs as $oc): ?>
                    <a href="conversation.php?id=<?php echo (int)$oc['id']; ?>" style="margin-right:8px; text-decoration:none;">#<?php echo (int)$oc['id']; ?> (<?php echo htmlspecialchars($oc['created_at'], ENT_QUOTES, 'UTF-8'); ?>)</a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <?php if (empty($messages)): ?>
            <p class="muted">پیامی در این مکالمه ثبت نشده است.</p>
        <?php else: foreach ($messages as $m): $isUser = $m['role'] !== 'assistant'; ?>
            <div style="margin-bottom:14px; padding:10px 14px; border-radius:10px; background:<?php echo $isUser ? '#efe6fb' : '#f4f2f8'; ?>;">
                <div class="muted" style="margin-bottom:4px;"><?php echo $isUser ? 'کاربر' : 'هوش مصنوعی'; ?> — <?php echo htmlspecialchars((string)$m['created_at'], ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($m['tokens'])) echo ' — ' . (int)$m['tokens'] . ' توکن'; ?></div>
                <div style="white-space:pre-line;"><?php echo htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endforeach; endif; ?>
    </div>
<?php include __DIR__ . '/_footer.php'; ?>