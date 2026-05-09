<?php
/**
 * Локальная раздача сайта через встроенный сервер PHP.
 * Запуск: php -S 127.0.0.1:8080 router.php
 */
declare(strict_types=1);

$documentRoot = realpath(__DIR__);
if ($documentRoot === false) {
    http_response_code(500);
    echo 'Router: invalid document root';
    return true;
}

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rawPath = is_string($rawPath) ? $rawPath : '/';
$decoded = rawurldecode($rawPath);
$decoded = '/' . trim(str_replace('\\', '/', $decoded), '/');

$rel = substr($decoded, 1); // без ведущего слэша
if ($rel !== '' && strpos($rel, '..') !== false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404';
    return true;
}

$requested = $rel === ''
    ? $documentRoot
    : $documentRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

if (!file_exists($requested)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    return true;
}

$resolved = realpath($requested);

if ($resolved === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    return true;
}

$docNorm = str_replace('\\', '/', $documentRoot);
$resNorm = str_replace('\\', '/', $resolved);
if ($resNorm !== $docNorm && !str_starts_with($resNorm, $docNorm . '/')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403';
    return true;
}

if (is_dir($resolved)) {
    $indexHtml = $resolved . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($indexHtml)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($indexHtml);
        return true;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Directory listing disabled';
    return true;
}

return false;
