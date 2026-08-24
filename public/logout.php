<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
$_SESSION = [];
session_destroy();
header('Location: ' . APP_URL . '/login');
exit;
