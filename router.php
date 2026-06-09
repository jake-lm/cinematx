<?php
/**
 * Dev-server router — mimics the .htaccess RewriteRules for `php -S`.
 * On Apache (production) this file is never used; .htaccess handles routing.
 * Usage: php -S localhost:8888 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// /posts/my-post-title-42 → /posts/index.php?id=42
if (preg_match('#^/posts/[a-z0-9-]+-(\d+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/posts/index.php';
    return true;
}

// Everything else — let the built-in server handle it normally
return false;
