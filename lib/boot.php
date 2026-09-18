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
    $candidates = [dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'iriseye-data', APP_ROOT . DIRECTORY_SEPARATOR . 'data'];
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
    if ($v >= 1) return;
    $pdo->beginTransaction();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            object_type TEXT NOT NULL DEFAULT '',
            comment TEXT NOT NULL DEFAULT '',
            calc_json TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT '',
            page TEXT NOT NULL DEFAULT '',
            utm_json TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'new',
            note TEXT NOT NULL DEFAULT '',
            consent_at TEXT NOT NULL,
            consent_version INTEGER NOT NULL,
            privacy_version INTEGER NOT NULL,
            ip TEXT NOT NULL DEFAULT '',
            ua TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS leads_status ON leads(status, created_at);
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

/** Путь сайта от корня домена ('' если сайт лежит в корне). */
function base_path(): string
{
    static $bp = null;
    if ($bp !== null) return $bp;
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $bp = '';
    if ($doc && str_starts_with(APP_ROOT, $doc)) {
        $bp = str_replace('\\', '/', substr(APP_ROOT, strlen($doc)));
    }
    return $bp = rtrim($bp, '/');
}

function site_url(): string
{
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
function form_token(): string
{
    $t = (string)time();
    return $t . '.' . substr(hash_hmac('sha256', $t, secret()), 0, 32);
}

function form_token_ok(string $tok, int $minAge = 3, int $maxAge = 86400): bool
{
    $parts = explode('.', $tok, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return false;
    $sig = substr(hash_hmac('sha256', $parts[0], secret()), 0, 32);
    if (!hash_equals($sig, $parts[1])) return false;
    $age = time() - (int)$parts[0];
    return $age >= $minAge && $age <= $maxAge;
}

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

const OBJECT_TYPES = [
    'house'     => 'Частный дом или квартира',
    'office'    => 'Офис или магазин',
    'warehouse' => 'Склад или производство',
    'other'     => 'Другое',
];

const LEAD_STATUSES = [
    'new'     => 'Новая',
    'work'    => 'В работе',
    'measure' => 'Замер назначен',
    'done'    => 'Закрыта',
    'spam'    => 'Спам',
];

const CALC_OBJECTS = ['house' => 'Жилой дом', 'flat' => 'Квартира', 'office' => 'Офис', 'warehouse' => 'Склад или цех'];
const CALC_SYSTEMS = ['cctv' => 'камеры', 'access' => 'СКУД', 'fire' => 'АПС', 'soue' => 'СОУЭ'];
const CALC_POINTS  = ['2-4', '5-8', '9-16', '17+'];

/** Приводит выбор в калькуляторе к одному виду. Понимает и старый формат со svc. */
function calc_norm(array $c): ?array
{
    $obj = (string)($c['obj'] ?? '');
    if (!isset(CALC_OBJECTS[$obj])) return null;
    $sel = [];
    if (isset($c['svc'])) {
        $svc = (string)$c['svc'];
        $sel = ['cctv' => in_array($svc, ['cctv', 'both'], true), 'access' => in_array($svc, ['access', 'both'], true)];
    } else {
        foreach (array_keys(CALC_SYSTEMS) as $k) $sel[$k] = !empty($c['sel'][$k]);
    }
    $sel += ['cctv' => false, 'access' => false, 'fire' => false, 'soue' => false];
    if (!in_array(true, $sel, true)) return null;
    $pts = (int)($c['pts'] ?? 0);
    if ($pts < 0 || $pts > 3) $pts = 0;
    return [
        'obj' => $obj, 'sel' => $sel, 'pts' => $pts,
        'night' => $sel['cctv'] && !empty($c['night']),
        'archive' => $sel['cctv'] && !empty($c['archive']),
    ];
}

/**
 * Та же формула, что на сайте: сервер пересчитывает цену сам и не верит браузеру.
 * АПС и СОУЭ с ценой 0 считаются «по смете» и в сумму не входят.
 */
function calc_eval(array $c): ?array
{
    $c = calc_norm($c);
    if (!$c) return null;
    $p = setting('prices');
    $s = $c['sel']; $o = $c['obj']; $pt = $c['pts'];
    $sum = 0;
    if ($s['cctv']) $sum += (int)($p['cctv'][$o][$pt] ?? 0);
    if ($s['access']) $sum += (int)($p['access'][$pt] ?? 0);
    if ($s['cctv'] && $s['access']) $sum = (int)round($sum * (100 - (int)$p['both_discount']) / 100);
    if ($c['night']) $sum += (int)$p['night'];
    if ($c['archive']) $sum += (int)$p['archive'];
    $est = [];
    foreach (['fire' => 'АПС', 'soue' => 'СОУЭ'] as $k => $label) {
        if (!$s[$k]) continue;
        $v = (int)($p[$k][$o] ?? 0);
        if ($v > 0) $sum += $v;
        else $est[] = $label;
    }
    return ['sum' => $sum, 'estimate' => $est, 'c' => $c];
}

function calc_price(array $c): ?int
{
    $r = calc_eval($c);
    return $r ? $r['sum'] : null;
}

function calc_summary(array $c): string
{
    $r = calc_eval($c);
    if (!$r) return '';
    $n = $r['c'];
    $sys = [];
    foreach (CALC_SYSTEMS as $k => $label) if ($n['sel'][$k]) $sys[] = $label;
    $parts = [CALC_OBJECTS[$n['obj']] . ': ' . implode(', ', $sys)];
    if ($n['sel']['cctv'] || $n['sel']['access']) $parts[] = CALC_POINTS[$n['pts']] . ($n['pts'] === 0 ? ' точки' : ' точек');
    if ($n['night']) $parts[] = 'цветное ночное видение';
    if ($n['archive']) $parts[] = 'архив больше 30 дней';
    $txt = implode(', ', $parts);
    if ($r['sum'] > 0) $txt .= ': от ' . number_format($r['sum'], 0, ',', ' ') . ' ₽';
    if ($r['estimate']) $txt .= $r['sum'] > 0 ? ' + ' . implode(' и ', $r['estimate']) . ' по смете' : ', по смете';
    return $txt;
}

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
        '{retention}' => (string)(int)setting('retention_months'),
        '{tg_note}'   => !empty(setting('notify')['tg_with_pd'])
            ? 'в уведомлении передаются имя, телефон и суть заявки, поэтому они проходят через серверы Telegram'
            : 'в уведомлении есть только номер заявки, без имени и телефона',
    ];
}

/** Создаёт новую редакцию документа, если текст с подставленными реквизитами изменился. */
function docs_sync(?PDO $pdo = null): void
{
    $pdo ??= db();
    $vars = doc_vars();
    foreach (['privacy', 'consent'] as $kind) {
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

// ---------- хранение заявок ----------

function purge_old_leads(): int
{
    $months = max(1, (int)setting('retention_months'));
    $cut = date('Y-m-d H:i:s', strtotime("-$months months"));
    $st = db()->prepare('DELETE FROM leads WHERE created_at < ?');
    $st->execute([$cut]);
    return $st->rowCount();
}

// ---------- уведомления ----------

function tg_api(string $token, string $method, array $params = []): array
{
    if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) return ['ok' => false, 'description' => 'Неверный формат токена'];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'description' => $err ?: 'Нет связи с Telegram'];
    $j = json_decode((string)$res, true);
    return is_array($j) ? $j : ['ok' => false, 'description' => 'Непонятный ответ Telegram'];
}

function notify_lead(int $id, array $lead): void
{
    $n = setting('notify');
    $lines = ["Новая заявка №{$id} на сайте АЙРИС"];
    if (!empty($lead['object_type']) && isset(OBJECT_TYPES[$lead['object_type']])) $lines[] = 'Объект: ' . OBJECT_TYPES[$lead['object_type']];
    if (!empty($lead['calc_text'])) $lines[] = 'Расчёт: ' . $lead['calc_text'];
    if (!empty($n['tg_with_pd'])) {
        $lines[] = 'Имя: ' . $lead['name'];
        $lines[] = 'Телефон: ' . format_phone($lead['phone']);
        if (!empty($lead['comment'])) $lines[] = 'Комментарий: ' . $lead['comment'];
    }
    $lines[] = 'Открыть: ' . site_url() . '/admin/?p=lead&id=' . $id;
    $text = implode("\n", $lines);

    if (!empty($n['tg_token']) && !empty($n['tg_chat'])) {
        tg_api((string)$n['tg_token'], 'sendMessage', ['chat_id' => (string)$n['tg_chat'], 'text' => $text, 'disable_web_page_preview' => 'true']);
    }
    if (!empty($n['email']) && filter_var($n['email'], FILTER_VALIDATE_EMAIL)) {
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: =?UTF-8?B?" . base64_encode('Сайт АЙРИС') . "?= <noreply@{$host}>\r\n";
        @mail((string)$n['email'], '=?UTF-8?B?' . base64_encode("Новая заявка №{$id}") . '?=', $text, $headers);
    }
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
