<?php
// Ядро сайта АЙРИС: база, настройки, документы, защита форм, уведомления.
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');
mb_internal_encoding('UTF-8');

const APP_VERSION = '1.0.0';
define('APP_ROOT', realpath(__DIR__ . '/..'));

// ---------- пути и база ----------

/** Папка данных: над веб-папкой, чтобы повторная выкладка сайта её не стирала. */
function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    // своя папка задаётся окружением, например при сборке статической копии
    $own = (string)getenv('IRIS_DATA_DIR');
    $candidates = $own !== '' ? [$own] : [dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'iriseye-data', APP_ROOT . DIRECTORY_SEPARATOR . 'data'];
    foreach ($candidates as $c) {
        if (!is_dir($c)) @mkdir($c, 0750, true);
        if (is_dir($c) && is_writable($c)) {
            if (str_ends_with($c, DIRECTORY_SEPARATOR . 'data') && !is_file($c . '/.htaccess')) {
                @file_put_contents($c . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            foreach (['media', 'sessions'] as $sub) {
                if (!is_dir("$c/$sub")) @mkdir("$c/$sub", 0750, true);
            }
            return $dir = $c;
        }
    }
    throw new RuntimeException('Нет папки для данных с правом записи');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . data_dir() . '/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=4000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= 6) return;
    if ($v < 1) {
    $pdo->beginTransaction();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS docs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            version INTEGER NOT NULL,
            template TEXT NOT NULL,
            rendered TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(kind, version)
        );
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            login TEXT NOT NULL UNIQUE,
            pass TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS throttle (k TEXT NOT NULL, t INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS throttle_k ON throttle(k, t);
        CREATE TABLE IF NOT EXISTS media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            w INTEGER NOT NULL DEFAULT 0,
            h INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
    ");
    $defaults = defaults();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach ($defaults as $k => $val) {
        $ins->execute([$k, json_encode($val, JSON_UNESCAPED_UNICODE)]);
    }
    $pdo->exec('PRAGMA user_version = 1');
    $pdo->commit();
    docs_sync($pdo);
    }
    // шаг 2 настраивал уведомления о заявках, их больше нет: просто отмечаем версию
    if ($v < 2) {
        $pdo->exec('PRAGMA user_version = 2');
    }
    if ($v < 3) {
        $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute(['contacts']);
        $raw = $st->fetchColumn();
        $c = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if (empty($c['email'])) {
            $c['email'] = defaults()['contacts']['email'];
            $pdo->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
                ->execute(['contacts', json_encode($c, JSON_UNESCAPED_UNICODE)]);
            $cache = &settings_cache();
            unset($cache['contacts']);
            docs_sync($pdo); // в документах появился адрес для запросов по персональным данным
        }
        $pdo->exec('PRAGMA user_version = 3');
    }
    // тексты документов переписаны под требования, действующие с 1 сентября 2025 года
    if ($v < 4) {
        docs_sync($pdo);
        $pdo->exec('PRAGMA user_version = 4');
    }
    // Форма заявки убрана с сайта: согласие больше не собирается, значит хранить
    // его редакции и сами заявки незачем, а держать персональные данные без цели нельзя.
    if ($v < 5) {
        $pdo->exec('DROP TABLE IF EXISTS leads');
        $pdo->exec("DELETE FROM docs WHERE kind = 'consent'");
        docs_sync($pdo);
        $pdo->exec('PRAGMA user_version = 5');
    }
    // настройки уведомлений и срока хранения заявок остались от формы, они больше не читаются
    if ($v < 6) {
        $pdo->exec("DELETE FROM settings WHERE key IN ('notify', 'retention_months', 'doc_tpl_consent')");
        $pdo->exec('PRAGMA user_version = 6');
    }
}

function defaults(): array
{
    static $d = null;
    return $d ??= require __DIR__ . '/defaults.php';
}

function now(): string { return date('Y-m-d H:i:s'); }

// ---------- настройки ----------

function &settings_cache(): array
{
    static $c = [];
    return $c;
}

function setting(string $key)
{
    $cache = &settings_cache();
    if (array_key_exists($key, $cache)) return $cache[$key];
    $def = defaults()[$key] ?? null;
    try {
        $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $raw = $st->fetchColumn();
        $val = $raw === false ? $def : json_decode((string)$raw, true);
    } catch (Throwable $e) {
        $val = $def;
    }
    // недостающие ключи ассоциативных массивов берём из значений по умолчанию
    if (is_array($def) && is_array($val) && !array_is_list($def)) {
        $val = array_replace($def, $val);
    }
    return $cache[$key] = $val ?? $def;
}

function set_setting(string $key, $value): void
{
    $st = db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $st->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    $cache = &settings_cache();
    unset($cache[$key]);
}

// ---------- мелочи ----------

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

/** Сборка статической копии для хостинга без PHP (GitHub Pages): php lib/cli.php build-static */
function is_static_build(): bool
{
    return getenv('IRIS_STATIC') === '1';
}

/** Путь сайта от корня домена ('' если сайт лежит в корне). В статической копии пути относительные. */
function base_path(): string
{
    static $bp = null;
    if ($bp !== null) return $bp;
    if (is_static_build()) return $bp = '.';
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $bp = '';
    if ($doc && str_starts_with(APP_ROOT, $doc)) {
        $bp = str_replace('\\', '/', substr(APP_ROOT, strlen($doc)));
    }
    return $bp = rtrim($bp, '/');
}

/** Адрес страницы документа: /privacy на хостинге, privacy.html в статической копии. */
function page_url(string $name): string
{
    return base_path() . '/' . $name . (is_static_build() ? '.html' : '');
}

/** Метка версии файла по его содержимому, чтобы браузер не держал старый CSS и JS. */
function asset_ver(string $rel): string
{
    $f = APP_ROOT . '/' . $rel;
    return is_file($f) ? substr(md5_file($f), 0, 8) : APP_VERSION;
}

function site_url(): string
{
    if (is_static_build()) return 'https://iriseye.ru';
    $host = $_SERVER['HTTP_HOST'] ?? 'iriseye.ru';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?: 'iriseye.ru';
    return (is_https() ? 'https' : 'http') . '://' . $host . base_path();
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function secret(): string
{
    static $s = null;
    if ($s) return $s;
    $f = data_dir() . '/secret.key';
    if (!is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    return $s = trim((string)file_get_contents($f));
}

/** Подписанная метка времени для формы: отсекает ботов, которые отправляют форму мгновенно. */
function throttle_count(string $key, int $window): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM throttle WHERE k = ? AND t > ?');
    $st->execute([$key, time() - $window]);
    return (int)$st->fetchColumn();
}

function throttle_hit(string $key): void
{
    db()->prepare('INSERT INTO throttle(k, t) VALUES(?, ?)')->execute([$key, time()]);
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM throttle WHERE t < ?')->execute([time() - 86400]);
    }
}

function throttle_clear(string $key): void
{
    db()->prepare('DELETE FROM throttle WHERE k = ?')->execute([$key]);
}

// ---------- телефоны ----------

/** Приводит номер к виду +7XXXXXXXXXX или возвращает '' если номер неверный. */
function normalize_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) $d = substr($d, 1);
    if (strlen($d) !== 10) return '';
    return '+7' . $d;
}

function format_phone(string $p): string
{
    $d = preg_replace('/\D+/', '', $p) ?? '';
    if (strlen($d) === 11) $d = substr($d, 1);
    if (strlen($d) !== 10) return $p;
    return sprintf('+7 (%s) %s-%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 2), substr($d, 8, 2));
}

function tel_href(string $p): string
{
    $d = preg_replace('/\D+/', '', $p) ?? '';
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    return 'tel:+' . $d;
}

// ---------- справочники ----------

const CALC_OBJECTS = ['house' => 'Жилой дом', 'flat' => 'Магазин', 'office' => 'Офис', 'warehouse' => 'Склад или цех'];
const CALC_POINTS  = ['2-4', '5-8', '9-16', '17+'];

// ---------- объекты (фото) ----------

function media_url(string $name): string { return base_path() . '/media.php?f=' . rawurlencode($name); }

function works_public(): array
{
    $out = [];
    foreach ((array)setting('works') as $w) {
        $url = !empty($w['media']) ? media_url((string)$w['media']) : (string)($w['src'] ?? '');
        if ($url === '') continue;
        $out[] = ['url' => $url, 'title' => (string)($w['title'] ?? ''), 'sub' => (string)($w['sub'] ?? '')];
    }
    return $out;
}

// ---------- документы ----------

function doc_template(string $kind): string
{
    $custom = setting('doc_tpl_' . $kind);
    if (is_string($custom) && trim($custom) !== '') return $custom;
    $tpl = require __DIR__ . '/docs.php';
    return $tpl[$kind] ?? '';
}

function doc_vars(): array
{
    $l = setting('legal');
    $c = setting('contacts');
    $blank = '________';
    return [
        '{operator}'  => trim((string)$l['operator']) ?: $blank,
        '{inn}'       => trim((string)$l['inn']) ?: $blank,
        '{ogrn}'      => trim((string)$l['ogrn']) ?: $blank,
        '{address}'   => trim((string)$l['address']) ?: $blank,
        '{email}'     => trim((string)($l['email'] ?: $c['email'])) ?: $blank,
        '{phone}'     => (string)$c['phone'],
    ];
}

/** Описывает канал Telegram так, как он настроен сейчас. */
/** Строка реквизитов для подвала: без неё сайт нарушает требования к сведениям о владельце. */
function legal_line(): string
{
    $l = setting('legal');
    $parts = [];
    if (trim((string)$l['operator']) !== '') $parts[] = trim((string)$l['operator']);
    if (trim((string)$l['inn']) !== '')      $parts[] = 'ИНН ' . trim((string)$l['inn']);
    if (trim((string)$l['ogrn']) !== '')     $parts[] = 'ОГРН ' . trim((string)$l['ogrn']);
    if (trim((string)$l['address']) !== '')  $parts[] = trim((string)$l['address']);
    if (trim((string)($l['license'] ?? '')) !== '') $parts[] = trim((string)$l['license']);
    return $parts ? implode(' · ', $parts) : '';
}

/** Создаёт новую редакцию документа, если текст с подставленными реквизитами изменился. */
function docs_sync(?PDO $pdo = null): void
{
    $pdo ??= db();
    $vars = doc_vars();
    foreach (['privacy'] as $kind) {
        $tpl = doc_template($kind);
        $rendered = strtr($tpl, $vars);
        $st = $pdo->prepare('SELECT version, rendered FROM docs WHERE kind = ? ORDER BY version DESC LIMIT 1');
        $st->execute([$kind]);
        $last = $st->fetch();
        if ($last && $last['rendered'] === $rendered) continue;
        $ver = $last ? (int)$last['version'] + 1 : 1;
        $pdo->prepare('INSERT INTO docs(kind, version, template, rendered, created_at) VALUES(?, ?, ?, ?, ?)')
            ->execute([$kind, $ver, $tpl, $rendered, now()]);
    }
}

function doc_get(string $kind, ?int $version = null): ?array
{
    try {
        if ($version) {
            $st = db()->prepare('SELECT * FROM docs WHERE kind = ? AND version = ?');
            $st->execute([$kind, $version]);
        } else {
            $st = db()->prepare('SELECT * FROM docs WHERE kind = ? ORDER BY version DESC LIMIT 1');
            $st->execute([$kind]);
        }
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function doc_versions(string $kind): array
{
    $st = db()->prepare('SELECT version, created_at FROM docs WHERE kind = ? ORDER BY version DESC');
    $st->execute([$kind]);
    return $st->fetchAll();
}

/** Простая разметка документа в HTML: «## » заголовки, «- » списки, абзацы. */
function doc_html(string $text): string
{
    $text = str_replace('{site}', site_url(), $text);
    $lines = preg_split('/\R/u', trim($text)) ?: [];
    $html = ''; $para = []; $list = [];
    $flushP = function () use (&$para, &$html) { if ($para) { $html .= '<p>' . e(implode(' ', $para)) . "</p>\n"; $para = []; } };
    $flushL = function () use (&$list, &$html) { if ($list) { $html .= "<ul>\n" . implode('', array_map(fn($li) => '<li>' . e($li) . "</li>\n", $list)) . "</ul>\n"; $list = []; } };
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') { $flushP(); $flushL(); continue; }
        if (str_starts_with($t, '## ')) { $flushP(); $flushL(); $html .= '<h2>' . e(substr($t, 3)) . "</h2>\n"; continue; }
        if (str_starts_with($t, '- ')) { $flushP(); $list[] = substr($t, 2); continue; }
        $flushL(); $para[] = $t;
    }
    $flushP(); $flushL();
    return $html;
}

function legal_filled(): bool
{
    $l = setting('legal');
    return trim((string)$l['operator']) !== '' && trim((string)$l['inn']) !== '' && trim((string)($l['email'] ?: setting('contacts')['email'])) !== '';
}

// ---------- админка: сессия, вход, CSRF ----------

function admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '7200');
    session_save_path(data_dir() . DIRECTORY_SEPARATOR . 'sessions');
    session_name('iris_adm');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/admin',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    $idle = 7200;
    if (isset($_SESSION['uid'], $_SESSION['last']) && time() - (int)$_SESSION['last'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = ['info', 'Сессия закончилась после 2 часов бездействия. Войдите снова.'];
    }
    $_SESSION['last'] = time();
}

function admin_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, login FROM admins WHERE id = ?');
    $st->execute([(int)$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function admins_exist(): bool
{
    return (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }

function csrf_ok(): bool
{
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function security_headers(bool $admin = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($admin) {
        header('X-Frame-Options: DENY');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
}
