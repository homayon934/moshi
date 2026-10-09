<?php
    require_once __DIR__ . '/_bootstrap.php';

    $error = null;
    $success = null;

    // ویرایش یا افزودن
    $edit_id = (int)($_GET['edit'] ?? 0);
    $edit_topic = null;

    if ($edit_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM aichat_topics WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_topic = $stmt->fetch();
    }

    // حذف موضوع
    if (isset($_GET['delete'])) {
        $del_id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM aichat_topics WHERE id = ?");
        $stmt->execute([$del_id]);
        header("Location: topics.php?msg=deleted");
        exit;
    }

    if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
        $success = "موضوع با موفقیت حذف شد.";
    }

    // ثبت یا ویرایش
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $topic_id = (int)($_POST['topic_id'] ?? 0);

        if ($title === '' || $content === '') {
            $error = "لطفاً هم عنوان و هم محتوا را وارد کنید.";
        } else {
            if ($topic_id > 0) {
                $stmt = $pdo->prepare("UPDATE aichat_topics SET title = ?, content = ? WHERE id = ?");
                $stmt->execute([$title, $content, $topic_id]);
                $success = "موضوع با موفقیت ویرایش شد.";
                $edit_topic = null;
            } else {
                $stmt = $pdo->prepare("INSERT INTO aichat_topics (title, content, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$title, $content]);
                $success = "موضوع جدید با موفقیت اضافه شد.";
            }
        }
    }

    // دریافت لیست تمامی موضوعات
    $stmt = $pdo->query("SELECT * FROM aichat_topics ORDER BY id DESC");
    $topics = $stmt ? $stmt->fetchAll() : [];

    include __DIR__ . '/_header.php';
?>

<style>
    .topics-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    .card-modern {
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        padding: 24px;
        margin-bottom: 24px;
        border: 1px solid #f0f0f5;
    }
    
    .card-header-title {
        font-size: 1.15rem;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-group {
        margin-bottom: 18px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #4a5568;
        font-size: 0.92rem;
    }

    .form-control-modern {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        background: #fdfdfd;
        box-sizing: border-box;
    }

    .form-control-modern:focus {
        border-color: #8F00FF;
        background: #fff;
        outline: none;
        box-shadow: 0 0 0 3px rgba(143, 0, 255, 0.12);
    }

    textarea.form-control-modern {
        min-height: 120px;
        resize: vertical;
        line-height: 1.6;
    }

    .btn-submit {
        background: linear-gradient(135deg, #8F00FF, #6b00c7);
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(143, 0, 255, 0.3);
    }

    .btn-cancel {
        background: #edf2f7;
        color: #4a5568;
        padding: 12px 20px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        margin-left: 10px;
        display: inline-block;
    }

    /* لیست موضوعات آکاردئونی */
    .topic-item {
        border: 1px solid #edf2f7;
        border-radius: 12px;
        margin-bottom: 12px;
        overflow: hidden;
        transition: all 0.2s ease;
        background: #fff;
    }

    .topic-item:hover {
        border-color: #cbd5e0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }

    .topic-header {
        padding: 16px 20px;
        background: #fafafa;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        user-select: none;
    }

    .topic-title {
        font-weight: 700;
        color: #2d3748;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .topic-arrow {
        font-size: 0.75rem;
        color: #a0aec0;
        transition: transform 0.2s ease;
        display: inline-block;
    }

    .topic-item.open .topic-arrow {
        transform: rotate(180deg);
        color: #8F00FF;
    }

    .topic-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .action-btn {
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .action-edit {
        background: #f0f3ff;
        color: #3182ce;
    }

    .action-edit:hover {
        background: #ebf8ff;
        color: #2b6cb0;
    }

    .action-delete {
        background: #fff5f5;
        color: #e53e3e;
    }

    .action-delete:hover {
        background: #fed7d7;
    }

    .topic-body {
        display: none; /* پیش‌فرض مخفی */
        padding: 18px 20px;
        color: #4a5568;
        line-height: 1.7;
        font-size: 0.95rem;
        border-top: 1px dashed #e2e8f0;
        background: #ffffff;
        white-space: pre-wrap;
    }

    .alert {
        padding: 12px 18px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-weight: 500;
    }
    .alert-danger { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; }
    .alert-success { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; }
</style>

<div class="topics-container">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- فرم افزودن / ویرایش -->
    <div class="card-modern">
        <div class="card-header-title">
            <span><?php echo $edit_topic ? '✏️ ویرایش موضوع' : '➕ افزودن موضوع دانش جدید'; ?></span>
        </div>
        <p style="color: #718096; font-size: 0.88rem; margin-top:0; margin-bottom: 20px;">
            هر موضوع شامل یک عنوان و متن کامل است. هوش مصنوعی هنگام پاسخ‌گویی به سوالات کاربران از این اطلاعات استفاده می‌کند.
        </p>

        <form method="POST" action="topics.php">
            <?php if ($edit_topic): ?>
                <input type="hidden" name="topic_id" value="<?php echo (int)$edit_topic['id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label>عنوان موضوع:</label>
                <input type="text" name="title" class="form-control-modern" placeholder="مثلاً: زمان و هزینه ارسال، شرایط مرجوعی، چاپ پرچم..." value="<?php echo htmlspecialchars($edit_topic['title'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label>محتوا / توضیحات کامل:</label>
                <textarea name="content" class="form-control-modern" placeholder="متن کامل توضیحات را اینجا بنویسید..." required><?php echo htmlspecialchars($edit_topic['content'] ?? ''); ?></textarea>
            </div>

            <div>
                <button type="submit" class="btn-submit">
                    <?php echo $edit_topic ? 'ذخیره تغییرات' : 'افزودن به پایگاه دانش'; ?>
                </button>
                <?php if ($edit_topic): ?>
                    <a href="topics.php" class="btn-cancel">انصراف</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- لیست موضوعات -->
    <div class="card-modern">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
            <div class="card-header-title" style="margin:0;">
                📚 موضوعات موجود (<?php echo count($topics); ?>)
            </div>
            <div style="width: 250px;">
                <input type="text" id="topicSearch" class="form-control-modern" style="padding: 8px 14px; font-size: 0.85rem;" placeholder="🔍 جستجو در موضوعات...">
            </div>
        </div>

        <?php if (empty($topics)): ?>
            <p style="text-align:center; color:#a0aec0; padding: 30px 0;">هنوز هیچ موضوعی در پایگاه دانش ثبت نشده است.</p>
        <?php else: ?>
            <div id="topicsList">
                <?php foreach ($topics as $t): ?>
                    <div class="topic-item">
                        <div class="topic-header" onclick="toggleTopic(this)">
                            <div class="topic-title">
                                <span class="topic-arrow">▼</span>
                                <span>📌 <?php echo htmlspecialchars($t['title']); ?></span>
                            </div>
                            <div class="topic-actions" onclick="event.stopPropagation();">
                                <a href="topics.php?edit=<?php echo (int)$t['id']; ?>" class="action-btn action-edit">ویرایش</a>
                                <a href="topics.php?delete=<?php echo (int)$t['id']; ?>" class="action-btn action-delete" onclick="return confirm('آیا از حذف این موضوع اطمینان دارید؟');">حذف</a>
                            </div>
                        </div>
                        <div class="topic-body">
                            <?php echo nl2br(htmlspecialchars($t['content'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
    // کلیک روی عنوان برای باز/بسته شدن توضیحات موضوع
    function toggleTopic(headerEl) {
        const item = headerEl.parentElement;
        const body = item.querySelector('.topic-body');
        
        if (body.style.display === 'block') {
            body.style.display = 'none';
            item.classList.remove('open');
        } else {
            body.style.display = 'block';
            item.classList.add('open');
        }
    }

    // جستجوی زنده در موضوعات
    document.getElementById('topicSearch').addEventListener('keyup', function() {
        const query = this.value.toLowerCase();
        const items = document.querySelectorAll('.topic-item');
        
        items.forEach(item => {
            const text = item.innerText.toLowerCase();
            if (text.includes(query)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });
</script>

<?php include __DIR__ . '/_footer.php'; ?>