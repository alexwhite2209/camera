<?php
// Служебные команды для хостинга (только из командной строки):
//   php lib/cli.php setup-link https://iriseye.ru   ссылка первого запуска админки
//   php lib/cli.php reset-admin                      сброс администратора, если забыли пароль
//   php lib/cli.php build-static                     статическая копия для GitHub Pages
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
if ($cmd === 'build-static') {
    // Страницы собираются с настройками по умолчанию во временной базе, рабочая база не трогается.
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iris-static-' . bin2hex(random_bytes(4));
    $env = array_merge(getenv(), ['IRIS_STATIC' => '1', 'IRIS_DATA_DIR' => $tmp]);
    $rmTmp = function (string $dir) use (&$rmTmp): void {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) is_dir($f) ? $rmTmp($f) : @unlink($f);
        @rmdir($dir);
    };
    $pages = ['index' => 'index.php', 'privacy' => 'privacy.php', 'consent' => 'consent.php'];
    foreach ($pages as $name => $script) {
        $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', APP_ROOT . '/' . $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, $env);
        $html = stream_get_contents($pipes[1]);
        $err  = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0 || trim($err) !== '' || !str_contains($html, '</html>')) {
            fwrite(STDERR, "Не собралась страница $script:\n$err\n");
            $rmTmp($tmp);
            exit(1);
        }
        file_put_contents(APP_ROOT . "/$name.html", $html);
        echo str_pad("$name.html", 16) . number_format(strlen($html) / 1024, 1, ',', ' ') . ' КБ' . PHP_EOL;
    }
    // без этого файла GitHub Pages прогоняет папку через Jekyll и показывает README
    file_put_contents(APP_ROOT . '/.nojekyll', '');
    $rmTmp($tmp);
    exit(0);
}
echo "Команды: setup-link <адрес сайта> | reset-admin | build-static" . PHP_EOL;
