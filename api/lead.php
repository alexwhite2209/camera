<?php
// Приём заявки с сайта. Отвечает JSON. Проверки дублируют браузерные: браузеру сервер не верит.
declare(strict_types=1);
require __DIR__ . '/../lib/boot.php';
security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

// Заявки принимаем только со своего сайта.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $oh = parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
    if (strcasecmp($oh, (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
        json_out(['ok' => false, 'error' => 'origin'], 403);
    }
}

$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$in = str_contains($ct, 'application/json')
    ? (json_decode((string)file_get_contents('php://input'), true) ?: [])
    : $_POST;
if (!is_array($in)) $in = [];

// Скрытое поле-ловушка: люди его не видят, боты заполняют. Делаем вид, что всё хорошо.
if (trim((string)($in['website'] ?? '')) !== '') {
    json_out(['ok' => true, 'id' => 0]);
}

if (!form_token_ok((string)($in['token'] ?? ''))) {
    json_out(['ok' => false, 'error' => 'token', 'message' => 'Страница устарела. Обновите её и отправьте заявку ещё раз.'], 422);
}

$ip = client_ip();
if (throttle_count('lead:' . $ip, 3600) >= 5) {
    json_out(['ok' => false, 'error' => 'rate', 'message' => 'Слишком много заявок подряд. Позвоните нам или напишите в мессенджер.'], 429);
}

$name    = trim((string)preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
$phone   = normalize_phone((string)($in['phone'] ?? ''));
$obj     = (string)($in['object_type'] ?? '');
$comment = trim((string)($in['comment'] ?? ''));
$consent = filter_var($in['consent'] ?? false, FILTER_VALIDATE_BOOL);
if (!isset(OBJECT_TYPES[$obj])) $obj = '';

$errors = [];
$len = mb_strlen($name);
if ($len < 2 || $len > 60) $errors['name'] = 'Напишите, как к вам обращаться';
if ($phone === '') $errors['phone'] = 'Проверьте номер: нужно 10 цифр после +7';
if (mb_strlen($comment) > 1000) $errors['comment'] = 'Комментарий длиннее 1000 символов';
if (!$consent) $errors['consent'] = 'Отметьте согласие, без него мы не сможем принять заявку';
if ($errors) {
    json_out(['ok' => false, 'error' => 'validation', 'fields' => $errors], 422);
}

// Расчёт калькулятора: цену пересчитываем на сервере.
$calcJson = '';
$calcText = '';
if (is_array($in['calc'] ?? null)) {
    $r = calc_eval($in['calc']);
    if ($r) {
        $calcText = calc_summary($r['c']);
        // храним и выбор, и текст с ценой на момент заявки: цены потом могут поменяться
        $calcJson = json_encode($r['c'] + ['price' => $r['sum'], 'estimate' => $r['estimate'], 'text' => $calcText], JSON_UNESCAPED_UNICODE);
    }
}

$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
    $v = $in['utm'][$k] ?? '';
    if (is_string($v) && $v !== '') $utm[$k] = mb_substr($v, 0, 120);
}
$source = in_array($in['source'] ?? '', ['form', 'calc', 'cta'], true) ? (string)$in['source'] : 'form';
$page   = mb_substr((string)($in['page'] ?? ''), 0, 300);

$consentDoc = doc_get('consent');
$privacyDoc = doc_get('privacy');
$now = now();

try {
    $st = db()->prepare('INSERT INTO leads
        (created_at, name, phone, object_type, comment, calc_json, source, page, utm_json, status, note,
         consent_at, consent_version, privacy_version, ip, ua, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'new\', \'\', ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $now, $name, $phone, $obj, $comment, $calcJson, $source, $page,
        $utm ? json_encode($utm, JSON_UNESCAPED_UNICODE) : '',
        $now, (int)($consentDoc['version'] ?? 0), (int)($privacyDoc['version'] ?? 0),
        $ip, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), $now,
    ]);
    $id = (int)db()->lastInsertId();
    throttle_hit('lead:' . $ip);
} catch (Throwable $e) {
    error_log('lead insert failed: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'server', 'message' => 'Сервер не принял заявку.'], 500);
}

$lead = ['name' => $name, 'phone' => $phone, 'object_type' => $obj, 'comment' => $comment, 'calc_text' => $calcText];

// Сначала отвечаем посетителю, потом шлём уведомление, если сервер это умеет.
if (function_exists('fastcgi_finish_request')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'id' => $id]);
    fastcgi_finish_request();
    try { notify_lead($id, $lead); } catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
    exit;
}
try { notify_lead($id, $lead); } catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
json_out(['ok' => true, 'id' => $id]);
