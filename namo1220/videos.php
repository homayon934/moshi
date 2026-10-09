<?php
    require_once __DIR__ . '/_bootstrap.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
        $title = trim((string)($_POST['title'] ?? ''));
        $url = trim((string)($_POST['url'] ?? ''));
        // امنیت: فقط لینک‌های http/https مجازند (جلوگیری از javascript: و پروتکل‌های دیگه)
        if ($title !== '' && $url !== '' && preg_match('#^https?://#i', $url)) {
            $stmt = $pdo->prepare("INSERT INTO aichat_videos(title, url, sort_order, created_at) VALUES(?, ?, 0, NOW())");
            $stmt->execute([mb_substr($title, 0, 255), mb_substr($url, 0, 500)]);
        }
        header('Location: videos.php');
        exit;
    }

    if (isset($_GET['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM aichat_videos WHERE id=?");
        $stmt->execute([(int)$_GET['delete']]);
        header('Location: videos.php');
        exit;
    }

    $videos = $pdo->query("SELECT * FROM aichat_videos ORDER BY sort_order, id DESC")->fetchAll();

    include __DIR__ . '/_header.php';
?>
    <div class="card">
        <h2>افزودن لینک ویدیوی آموزشی</h2>
        <p class="muted" style="margin-top:-8px;">هر ردیف یک موضوع و یک لینک ویدیو داره؛ وقتی سوال کاربر با اون موضوع مرتبط باشه، هوش مصنوعی همین لینک رو در پاسخ معرفی می‌کنه.</p>
        <form class="inline" method="post">
            <div>
                <label>موضوع/عنوان</label>
                <input type="text" name="title" placeholder="مثلاً: نحوه‌ی ثبت سفارش" required>
            </div>
            <div>
                <label>لینک ویدیو</label>
                <input type="url" name="url" dir="ltr" placeholder="https://..." required>
            </div>
            <div style="flex:0 0 auto;"><button type="submit" name="add" value="1">افزودن</button></div>
        </form>
    </div>

    <div class="card">
        <h2>ویدیوهای موجود (<?php echo count($videos); ?>)</h2>
        <table>
            <thead><tr><th>موضوع/عنوان</th><th>لینک</th><th style="width:70px;">حذف</th></tr></thead>
            <tbody>
                <?php if (empty($videos)): ?>
                    <tr><td colspan="3" class="muted" style="text-align:center;">هنوز لینکی اضافه نشده.</td></tr>
                <?php else: foreach ($videos as $v): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($v['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr" style="text-align:left;"><a href="<?php echo htmlspecialchars($v['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($v['url'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td><a href="?delete=<?php echo (int)$v['id']; ?>" class="btn btn-danger" onclick="return confirm('حذف این لینک؟');">حذف</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php include __DIR__ . '/_footer.php'; ?>
