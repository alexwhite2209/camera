<?php
// Отдаёт фото, загруженные через админку. Они лежат в папке данных, вне веб-папки.
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';

$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{16,40}\.(webp|jpg)$/', $f)) {
    http_response_code(404);
    exit;
}
$path = data_dir() . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$etag = '"' . md5($f . filemtime($path)) . '"';
header('Content-Type: ' . (str_ends_with($f, '.webp') ? 'image/webp' : 'image/jpeg'));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($path));
readfile($path);
