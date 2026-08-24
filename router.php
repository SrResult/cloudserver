<?php
// Router for PHP's built-in dev server ONLY (php -S ... router.php).
// Apache uses public/.htaccess instead — this file is not used there.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicRoot = __DIR__ . '/public';
$file = $publicRoot . $uri;

// Serve real static files as-is (css/js/img)
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// Clean extensionless URLs: /login -> public/login.php, /admin/orders -> public/admin/orders.php
$path = rtrim($uri, '/');
if ($path === '') {
    $path = '/dashboard';
}
$phpFile = $publicRoot . $path . '.php';
if (file_exists($phpFile)) {
    require $phpFile;
    return true;
}

// /admin -> /admin/orders
if ($path === '/admin') {
    require $publicRoot . '/admin/orders.php';
    return true;
}

if ($path === '/dashboard' || $uri === '/') {
    require $publicRoot . '/index.php';
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;
