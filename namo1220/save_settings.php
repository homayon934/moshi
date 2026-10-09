<?php
    require_once __DIR__ . '/_bootstrap.php';

    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $avalai_enabled = isset($_POST['avalai_enabled']) ? 1 : 0;
    $openrouter_enabled = isset($_POST['openrouter_enabled']) ? 1 : 0;
    $provider_priority = trim((string)($_POST['provider_priority'] ?? 'avalai'));
    if (!in_array($provider_priority, ['avalai', 'openrouter'])) {
        $provider_priority = 'avalai';
    }

    // تنظیمات AvalAI
    $api_key = trim((string)($_POST['api_key'] ?? ''));
    $api_base = trim((string)($_POST['api_base'] ?? ''));
    if ($api_base === '') $api_base = 'https://api.avalai.ir/v1';
    $model = trim((string)($_POST['model'] ?? ''));
    if ($model === '') $model = 'gpt-4o-mini';

    // تنظیمات OpenRouter
    $openrouter_api_key = trim((string)($_POST['openrouter_api_key'] ?? ''));
    $openrouter_api_base = trim((string)($_POST['openrouter_api_base'] ?? ''));
    if ($openrouter_api_base === '') $openrouter_api_base = 'https://openrouter.ai/api/v1';
    $openrouter_model = trim((string)($_POST['openrouter_model'] ?? ''));
    if ($openrouter_model === '') $openrouter_model = 'google/gemini-2.5-flash';

    $store_info = strip_tags((string)($_POST['store_info'] ?? ''));
    $contact_phone = trim((string)($_POST['contact_phone'] ?? ''));
    $contact_phone = preg_replace('/[^0-9+\-\s()]/', '', $contact_phone);
    $contact_phone = mb_substr($contact_phone, 0, 30);
    $max_questions = (int)($_POST['max_questions'] ?? 0);
    if ($max_questions < 0) $max_questions = 0;
    $response_template = strip_tags((string)($_POST['response_template'] ?? ''));
    $widget_title = mb_substr(strip_tags((string)($_POST['widget_title'] ?? 'پرسش و پاسخ هوشمند')), 0, 150);
    $welcome_message = mb_substr(strip_tags((string)($_POST['welcome_message'] ?? '')), 0, 500);

    // امنیت رنگ‌ها
    $primary_color = trim((string)($_POST['primary_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary_color)) $primary_color = '#8F00FF';
    $secondary_color = trim((string)($_POST['secondary_color'] ?? ''));
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $secondary_color)) $secondary_color = '#4B0082';

    $current = aichat_get_settings($pdo);
    $guide_text = $current['guide_text'];
    $guide_filename = $current['guide_filename'];

    if (!empty($_POST['guide_file_remove'])) {
        $guide_text = '';
        $guide_filename = '';
    }

    if (!empty($_FILES['guide_file']['name']) && $_FILES['guide_file']['error'] === UPLOAD_ERR_OK) {
        if (!is_uploaded_file($_FILES['guide_file']['tmp_name'])) {
            die('خطای امنیتی در آپلود فایل!');
        }
        $ext = strtolower(pathinfo($_FILES['guide_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            die('فقط فایل متنی با پسوند txt پشتیبانی می‌شود؛ لطفاً فایل Word/PDF را ابتدا به txt تبدیل کنید.');
        }
        if ($_FILES['guide_file']['size'] > 2 * 1024 * 1024) {
            die('حجم فایل نباید بیشتر از ۲ مگابایت باشد.');
        }
        $content = file_get_contents($_FILES['guide_file']['tmp_name']);
        if ($content === false) {
            die('خطا در خواندن فایل!');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1256, ISO-8859-1');
        }
        $guide_text = $content;
        $guide_filename = mb_substr(basename($_FILES['guide_file']['name']), 0, 255);
    }

    $stmt = $pdo->prepare(
        "UPDATE aichat_settings SET enabled=?, avalai_enabled=?, openrouter_enabled=?, provider_priority=?,
            api_key=?, api_base=?, model=?, openrouter_api_key=?, openrouter_api_base=?, openrouter_model=?,
            store_info=?, contact_phone=?, max_questions=?, response_template=?, guide_text=?, guide_filename=?,
            widget_title=?, welcome_message=?, primary_color=?, secondary_color=?, updated_at=NOW() WHERE id=1"
    );
    $stmt->execute([
        $enabled, $avalai_enabled, $openrouter_enabled, $provider_priority,
        $api_key, $api_base, $model, $openrouter_api_key, $openrouter_api_base, $openrouter_model,
        $store_info, $contact_phone, $max_questions, $response_template, $guide_text, $guide_filename,
        $widget_title, $welcome_message, $primary_color, $secondary_color,
    ]);

    header('Location: settings.php?saved=1');