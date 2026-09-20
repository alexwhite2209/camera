<?php
// Роутер для локального просмотра: из корня репозитория
//   php -S 127.0.0.1:8080 -t . dev/router.php
// Повторяет правила .htaccess, которых встроенный сервер PHP не знает. На хостинг не нужен.
$root = dirname(__DIR__);
$uri  = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('#^/(lib|data|dev)(/|$)#', $uri) || preg_match('#\.(sqlite|key|log|md)$#', $uri)) { http_response_code(403); echo 'Forbidden'; return true; }
if (preg_match('#^/(index|privacy|consent)\.html$#', $uri, $m)) { $_SERVER['SCRIPT_NAME'] = "/{$m[1]}.php"; require "$root/{$m[1]}.php"; return true; }
// Видео отдаём кусками, как настоящий хостинг: иначе браузер считает ролик неперематываемым.
if (preg_match('#\.(mp4|webm)$#', $uri) && is_file($root . $uri)) {
    $file = $root . $uri; $size = filesize($file); $start = 0; $end = $size - 1;
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . (str_ends_with($uri, '.mp4') ? 'video/mp4' : 'video/webm'));
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $r)) {
        if ($r[1] !== '') { $start = (int)$r[1]; if ($r[2] !== '') $end = min((int)$r[2], $end); }
        elseif ($r[2] !== '') { $start = max(0, $size - (int)$r[2]); }
        if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); return true; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fh = fopen($file, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $chunk = fread($fh, (int)min(262144, $left)); if ($chunk === false || $chunk === '') break; echo $chunk; $left -= strlen($chunk); }
    fclose($fh); return true;
}
if (preg_match('#^/(privacy|consent)/?$#', $uri, $m)) { $_SERVER['SCRIPT_NAME'] = "/{$m[1]}.php"; require "$root/{$m[1]}.php"; return true; }
if ($uri === '/admin') { header('Location: /admin/'); return true; }
if (str_ends_with($uri, '/') && is_file("$root{$uri}index.php")) { $_SERVER['SCRIPT_NAME'] = "{$uri}index.php"; chdir("$root$uri"); require "$root{$uri}index.php"; return true; }
return false;
