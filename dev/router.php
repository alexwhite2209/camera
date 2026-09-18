<?php
// Роутер для локального просмотра: из корня репозитория
//   php -S 127.0.0.1:8080 -t . dev/router.php
// Повторяет правила .htaccess, которых встроенный сервер PHP не знает. На хостинг не нужен.
$root = dirname(__DIR__);
$uri  = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('#^/(lib|data|dev)(/|$)#', $uri) || preg_match('#\.(sqlite|key|log|md)$#', $uri)) { http_response_code(403); echo 'Forbidden'; return true; }
if (preg_match('#^/(index|privacy|consent)\.html$#', $uri, $m)) { $_SERVER['SCRIPT_NAME'] = "/{$m[1]}.php"; require "$root/{$m[1]}.php"; return true; }
if (preg_match('#^/(privacy|consent)/?$#', $uri, $m)) { $_SERVER['SCRIPT_NAME'] = "/{$m[1]}.php"; require "$root/{$m[1]}.php"; return true; }
if ($uri === '/admin') { header('Location: /admin/'); return true; }
if (str_ends_with($uri, '/') && is_file("$root{$uri}index.php")) { $_SERVER['SCRIPT_NAME'] = "{$uri}index.php"; chdir("$root$uri"); require "$root{$uri}index.php"; return true; }
return false;
