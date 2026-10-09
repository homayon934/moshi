<?php
    require_once dirname(__DIR__) . '/includes/sec_lib.php'; sec_session_start();
    unset($_SESSION['aichat_admin']);
    session_destroy();
    header('Location: login.php');
