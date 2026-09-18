<?php
// Служебные команды для хостинга (только из командной строки):
//   php lib/cli.php setup-link https://iriseye.ru   ссылка первого запуска админки
//   php lib/cli.php reset-admin                      сброс администратора, если забыли пароль
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/boot.php';
require __DIR__ . '/admin.php';

$cmd = $argv[1] ?? '';
if ($cmd === 'setup-link') {
    if (admins_exist()) { fwrite(STDERR, "Администратор уже создан. Для сброса: php lib/cli.php reset-admin\n"); exit(1); }
    $base = rtrim($argv[2] ?? 'http://127.0.0.1:8080', '/');
    echo $base . '/admin/setup.php?key=' . setup_key() . PHP_EOL;
    exit(0);
}
if ($cmd === 'reset-admin') {
    $n = db()->exec('DELETE FROM admins');
    setup_key_drop();
    echo "Удалено администраторов: $n. Получите новую ссылку: php lib/cli.php setup-link <адрес сайта>" . PHP_EOL;
    exit(0);
}
echo "Команды: setup-link <адрес сайта> | reset-admin" . PHP_EOL;
