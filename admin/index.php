<?php
// Админка сайта АЙРИС. Один вход, разделы через ?p=
declare(strict_types=1);
require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/admin.php';
security_headers(true);
admin_session();

if (!admins_exist()) redirect(base_path() . '/admin/setup.php');

$p = (string)($_GET['p'] ?? 'leads');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($isPost && !csrf_ok()) {
    flash('error', 'Форма устарела. Попробуйте ещё раз.');
    redirect(admin_url($p));
}

/* ---------- вход и выход ---------- */
if ($p === 'logout') {
    if ($isPost) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('info', 'Вы вышли из админки.');
    }
    redirect(admin_url('login'));
}

if (!admin_user()) {
    if ($p !== 'login') redirect(admin_url('login'));
    $ip = client_ip();
    $err = '';
    $locked = throttle_count('login:' . $ip, 900) >= 5;
    if ($isPost) {
        if ($locked) {
            $err = 'Слишком много попыток. Подождите 15 минут.';
        } else {
            $login = trim((string)($_POST['login'] ?? ''));
            $pass = (string)($_POST['pass'] ?? '');
            $st = db()->prepare('SELECT id, pass FROM admins WHERE login = ?');
            $st->execute([$login]);
            $u = $st->fetch();
            if ($u && password_verify($pass, $u['pass'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['last'] = time();
                unset($_SESSION['csrf']);
                throttle_clear('login:' . $ip);
                if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE admins SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                }
                redirect(admin_url('leads'));
            }
            throttle_hit('login:' . $ip);
            usleep(400000);
            $locked = throttle_count('login:' . $ip, 900) >= 5;
            $err = $locked ? 'Слишком много попыток. Подождите 15 минут.' : 'Неверный логин или пароль.';
        }
    }
    admin_head('Вход');
    ?>
    <section class="card login">
      <img src="<?= base_path() ?>/assets/img/logo-160.png" alt="" width="56" height="64">
      <h1>Админка АЙРИС</h1>
      <?php if ($err): ?><p class="note note--error" role="alert"><?= e($err) ?></p><?php endif; ?>
      <form method="post" action="<?= e(admin_url('login')) ?>">
        <?= csrf_field() ?>
        <label for="login">Логин</label>
        <input id="login" name="login" type="text" autocomplete="username" required autofocus>
        <label for="pass">Пароль</label>
        <input id="pass" name="pass" type="password" autocomplete="current-password" required>
        <button class="btn btn--pri btn--block" type="submit"<?= $locked ? ' disabled' : '' ?>>Войти</button>
      </form>
      <p class="muted">5 неверных попыток подряд дают паузу на 15 минут.</p>
    </section>
    <?php
    admin_foot();
    exit;
}

if ($p === 'login') redirect(admin_url('leads'));

/* ---------- общие помощники для заявок ---------- */
function leads_filter(): array
{
    $s = (string)($_GET['s'] ?? 'all');
    if ($s !== 'all' && !isset(LEAD_STATUSES[$s])) $s = 'all';
    $q = trim((string)($_GET['q'] ?? ''));
    $where = [];
    $args = [];
    if ($s === 'all') $where[] = "status != 'spam'";
    else { $where[] = 'status = ?'; $args[] = $s; }
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) >= 3) {
            if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) $digits = substr($digits, 1);
            $where[] = "(name LIKE ? ESCAPE '\\' OR phone LIKE ?)";
            $args[] = $like;
            $args[] = '%' . $digits . '%';
        } else {
            $where[] = "(name LIKE ? ESCAPE '\\' OR comment LIKE ? ESCAPE '\\')";
            $args[] = $like;
            $args[] = $like;
        }
    }
    return [$s, $q, 'WHERE ' . implode(' AND ', $where), $args];
}

function csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/* ================= разделы ================= */
switch ($p) {

/* ---------- заявки ---------- */
case 'leads':
    $purged = purge_old_leads();
    [$s, $q, $where, $args] = leads_filter();
    $counts = ['all' => 0];
    foreach (db()->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $r) {
        $counts[$r['status']] = (int)$r['c'];
        if ($r['status'] !== 'spam') $counts['all'] += (int)$r['c'];
    }
    $per = 30;
    $pg = max(1, (int)($_GET['pg'] ?? 1));
    $total = db()->prepare("SELECT COUNT(*) FROM leads $where");
    $total->execute($args);
    $total = (int)$total->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $pg = min($pg, $pages);
    $st = db()->prepare("SELECT * FROM leads $where ORDER BY id DESC LIMIT $per OFFSET " . (($pg - 1) * $per));
    $st->execute($args);
    $rows = $st->fetchAll();

    admin_head('Заявки', 'leads');
    ?>
    <header class="head">
      <h1>Заявки</h1>
      <a class="btn" href="<?= e(admin_url('export', ['s' => $s, 'q' => $q])) ?>">Выгрузить в Excel</a>
    </header>
    <?php if ($purged): ?><p class="note note--info">Удалено старых заявок по сроку хранения: <?= $purged ?>.</p><?php endif; ?>
    <nav class="tabs" aria-label="Статусы">
      <?php foreach (['all' => 'Все', 'new' => 'Новые', 'work' => 'В работе', 'measure' => 'Замер назначен', 'done' => 'Закрытые', 'spam' => 'Спам'] as $k => $label): ?>
        <a class="<?= $s === $k ? 'on' : '' ?>" href="<?= e(admin_url('leads', ['s' => $k, 'q' => $q])) ?>"><?= e($label) ?> <b><?= (int)($counts[$k] ?? 0) ?></b></a>
      <?php endforeach; ?>
    </nav>
    <form class="search" method="get" action="<?= base_path() ?>/admin/">
      <input type="hidden" name="p" value="leads"><input type="hidden" name="s" value="<?= e($s) ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Имя или телефон" aria-label="Поиск по имени или телефону">
      <button class="btn" type="submit">Найти</button>
    </form>
    <?php if (!$rows): ?>
      <div class="empty"><p><?= $q !== '' ? 'Ничего не нашлось.' : 'Здесь появятся заявки с сайта.' ?></p></div>
    <?php else: ?>
      <div class="leads">
        <div class="leads__row leads__row--head" aria-hidden="true"><span>№</span><span>Когда</span><span>Имя</span><span>Телефон</span><span>Запрос</span><span>Статус</span></div>
        <?php foreach ($rows as $r): $calc = lead_calc_text($r); ?>
          <a class="leads__row<?= $r['status'] === 'new' ? ' is-new' : '' ?>" href="<?= e(admin_url('lead', ['id' => $r['id']])) ?>">
            <span class="c-id">№ <?= (int)$r['id'] ?></span>
            <span class="c-when"><?= e(date('d.m H:i', strtotime($r['created_at']))) ?></span>
            <span class="c-name"><?= e($r['name']) ?></span>
            <span class="c-phone"><?= e(format_phone($r['phone'])) ?></span>
            <span class="c-req"><?= e($calc ?: (object_label($r['object_type']) ?: mb_strimwidth($r['comment'], 0, 70, '…'))) ?></span>
            <span class="c-st"><?= status_badge($r['status']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Страницы">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="<?= $i === $pg ? 'on' : '' ?>" href="<?= e(admin_url('leads', ['s' => $s, 'q' => $q, 'pg' => $i])) ?>"><?= $i ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
    <?php
    admin_foot();
    break;

/* ---------- одна заявка ---------- */
case 'lead':
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM leads WHERE id = ?');
    $st->execute([$id]);
    $lead = $st->fetch();
    if (!$lead) { flash('error', 'Заявка не найдена. Возможно, её уже удалили.'); redirect(admin_url('leads')); }

    if ($isPost) {
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'delete') {
            db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
            flash('ok', "Заявка № $id удалена вместе с персональными данными.");
            redirect(admin_url('leads'));
        }
        $status = (string)($_POST['status'] ?? $lead['status']);
        if (!isset(LEAD_STATUSES[$status])) $status = $lead['status'];
        $note = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['note'] ?? ''))), 0, 2000);
        db()->prepare('UPDATE leads SET status = ?, note = ?, updated_at = ? WHERE id = ?')->execute([$status, $note, now(), $id]);
        flash('ok', 'Сохранено.');
        redirect(admin_url('lead', ['id' => $id]));
    }

    $calc = lead_calc_text($lead);
    $utm = $lead['utm_json'] ? (json_decode($lead['utm_json'], true) ?: []) : [];
    $src = ['form' => 'форма на сайте', 'calc' => 'калькулятор', 'cta' => 'кнопка в ролике'][$lead['source']] ?? $lead['source'];
    $digits = preg_replace('/\D+/', '', $lead['phone']);
    admin_head('Заявка № ' . $id, 'leads');
    ?>
    <p class="back"><a href="<?= e(admin_url('leads')) ?>">← Все заявки</a></p>
    <header class="head">
      <h1>Заявка № <?= $id ?></h1>
      <?= status_badge($lead['status']) ?>
    </header>
    <div class="lead">
      <section class="card">
        <dl class="dl">
          <dt>Имя</dt><dd><?= e($lead['name']) ?></dd>
          <dt>Телефон</dt>
          <dd class="dd-phone">
            <b><?= e(format_phone($lead['phone'])) ?></b>
            <a class="btn btn--sm" href="<?= e(tel_href($lead['phone'])) ?>">Позвонить</a>
            <a class="btn btn--sm" href="https://wa.me/<?= e($digits) ?>" target="_blank" rel="noopener">WhatsApp</a>
          </dd>
          <dt>Когда</dt><dd><?= e(date('d.m.Y H:i', strtotime($lead['created_at']))) ?></dd>
          <?php if ($lead['object_type']): ?><dt>Объект</dt><dd><?= e(object_label($lead['object_type'])) ?></dd><?php endif; ?>
          <?php if ($calc): ?><dt>Расчёт</dt><dd><?= e($calc) ?></dd><?php endif; ?>
          <?php if ($lead['comment'] !== ''): ?><dt>Комментарий</dt><dd class="pre"><?= e($lead['comment']) ?></dd><?php endif; ?>
          <dt>Источник</dt><dd><?= e($src) ?><?php if ($utm): ?> · <?= e(implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($utm), $utm))) ?><?php endif; ?></dd>
          <dt>Согласие</dt><dd>дано <?= e(date('d.m.Y H:i', strtotime($lead['consent_at']))) ?>, <a href="<?= base_path() ?>/consent?v=<?= (int)$lead['consent_version'] ?>" target="_blank" rel="noopener">согласие ред. <?= (int)$lead['consent_version'] ?></a>, <a href="<?= base_path() ?>/privacy?v=<?= (int)$lead['privacy_version'] ?>" target="_blank" rel="noopener">политика ред. <?= (int)$lead['privacy_version'] ?></a></dd>
          <dt>IP</dt><dd><?= e($lead['ip'] ?: 'нет') ?></dd>
        </dl>
      </section>
      <section class="card">
        <form method="post">
          <?= csrf_field() ?>
          <label for="status">Статус</label>
          <select id="status" name="status">
            <?php foreach (LEAD_STATUSES as $k => $label): ?>
              <option value="<?= $k ?>"<?= $lead['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <label for="note">Заметка</label>
          <textarea id="note" name="note" rows="5" placeholder="Например: замер в субботу в 11:00"><?= e($lead['note']) ?></textarea>
          <button class="btn btn--pri btn--block" type="submit" name="op" value="save">Сохранить</button>
        </form>
        <form method="post" class="danger-zone">
          <?= csrf_field() ?>
          <p class="muted">Если клиент попросил удалить его данные или отозвал согласие, удалите заявку целиком.</p>
          <button class="btn btn--danger btn--block" type="submit" name="op" value="delete" data-confirm="Удалить заявку № <?= $id ?> вместе с персональными данными? Отменить нельзя.">Удалить по просьбе клиента</button>
        </form>
      </section>
    </div>
    <?php
    admin_foot();
    break;

/* ---------- выгрузка ---------- */
case 'export':
    [$s, $q, $where, $args] = leads_filter();
    $st = db()->prepare("SELECT * FROM leads $where ORDER BY id DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="iriseye-leads-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['№', 'Дата', 'Имя', 'Телефон', 'Тип объекта', 'Расчёт', 'Комментарий', 'Статус', 'Заметка', 'Источник', 'UTM', 'Согласие', 'Редакция согласия', 'IP'], ';');
    foreach ($st as $r) {
        fputcsv($out, array_map(fn($v) => csv_safe((string)$v), [
            $r['id'], date('d.m.Y H:i', strtotime($r['created_at'])), $r['name'], format_phone($r['phone']),
            object_label($r['object_type']), lead_calc_text($r), $r['comment'], LEAD_STATUSES[$r['status']] ?? $r['status'],
            $r['note'], $r['source'], $r['utm_json'], date('d.m.Y H:i', strtotime($r['consent_at'])), $r['consent_version'], $r['ip'],
        ]), ';');
    }
    fclose($out);
    exit;

/* ---------- контакты ---------- */
case 'contacts':
    $c = setting('contacts');
    if ($isPost) {
        $phone = normalize_phone((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($phone === '') { flash('error', 'Проверьте телефон: нужно 10 цифр после +7.'); redirect(admin_url('contacts')); }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Проверьте email.'); redirect(admin_url('contacts')); }
        $c = [
            'phone'    => format_phone($phone),
            'whatsapp' => preg_replace('/\D+/', '', (string)($_POST['whatsapp'] ?? '')) ?: preg_replace('/\D+/', '', $phone),
            'telegram' => ltrim(preg_replace('/[^A-Za-z0-9_@]/', '', (string)($_POST['telegram'] ?? '')), '@'),
            'hours'    => mb_substr(trim((string)($_POST['hours'] ?? '')), 0, 80),
            'email'    => $email,
            'region'   => mb_substr(trim((string)($_POST['region'] ?? '')), 0, 120),
        ];
        set_setting('contacts', $c);
        docs_sync();
        flash('ok', 'Контакты сохранены и уже на сайте.');
        redirect(admin_url('contacts'));
    }
    admin_head('Контакты и часы', 'contacts');
    ?>
    <header class="head"><h1>Контакты и часы</h1></header>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <label for="phone">Телефон на сайте</label>
      <input id="phone" name="phone" value="<?= e($c['phone']) ?>" required inputmode="tel">
      <label for="whatsapp">WhatsApp (номер цифрами)</label>
      <input id="whatsapp" name="whatsapp" value="<?= e($c['whatsapp']) ?>" inputmode="numeric" placeholder="79867484446">
      <label for="telegram">Telegram (имя пользователя без @)</label>
      <input id="telegram" name="telegram" value="<?= e($c['telegram']) ?>" placeholder="airis_security">
      <label for="hours">Часы работы</label>
      <input id="hours" name="hours" value="<?= e($c['hours']) ?>" placeholder="Ежедневно с 8:00 до 19:00">
      <label for="email">Email для запросов по персональным данным</label>
      <input id="email" name="email" type="email" value="<?= e($c['email']) ?>" placeholder="info@iriseye.ru">
      <label for="region">Где работаете</label>
      <input id="region" name="region" value="<?= e($c['region']) ?>">
      <div class="bar"><button class="btn btn--pri" type="submit">Сохранить</button></div>
    </form>
    <?php
    admin_foot();
    break;

/* ---------- цены ---------- */
case 'prices':
    $pr = setting('prices');
    if ($isPost) {
        $num = fn($v) => max(0, min(10000000, (int)preg_replace('/\D+/', '', (string)$v)));
        foreach (array_keys(CALC_OBJECTS) as $o) for ($i = 0; $i < 4; $i++) $pr['cctv'][$o][$i] = $num($_POST['cctv'][$o][$i] ?? 0);
        for ($i = 0; $i < 4; $i++) $pr['access'][$i] = $num($_POST['access'][$i] ?? 0);
        $pr['both_discount'] = min(90, $num($_POST['both_discount'] ?? 0));
        $pr['night'] = $num($_POST['night'] ?? 0);
        $pr['archive'] = $num($_POST['archive'] ?? 0);
        foreach (['fire', 'soue'] as $sys) foreach (array_keys(CALC_OBJECTS) as $o) $pr[$sys][$o] = $num($_POST[$sys][$o] ?? 0);
        set_setting('prices', $pr);
        flash('ok', 'Цены сохранены. Калькулятор на сайте уже считает по-новому.');
        redirect(admin_url('prices'));
    }
    admin_head('Цены калькулятора', 'prices');
    ?>
    <header class="head"><h1>Цены калькулятора</h1></header>
    <p class="muted lead-p">Цены «от», в рублях, вместе с оборудованием и монтажом. Столбцы: сколько камер или дверей.</p>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <h2>Камеры</h2>
      <div class="ptable" role="table" aria-label="Цены на камеры">
        <div role="row" class="ptable__h"><span role="columnheader">Объект</span><?php foreach (CALC_POINTS as $lbl): ?><span role="columnheader"><?= e($lbl) ?></span><?php endforeach; ?></div>
        <?php foreach (CALC_OBJECTS as $o => $label): ?>
          <div role="row"><span role="rowheader"><?= e($label) ?></span>
            <?php for ($i = 0; $i < 4; $i++): ?><input role="cell" name="cctv[<?= $o ?>][<?= $i ?>]" value="<?= (int)$pr['cctv'][$o][$i] ?>" inputmode="numeric" aria-label="<?= e($label . ', ' . CALC_POINTS[$i]) ?>"><?php endfor; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <h2>СКУД и домофон</h2>
      <div class="ptable">
        <div class="ptable__h"><span>Дверей</span><?php foreach (CALC_POINTS as $lbl): ?><span><?= e($lbl) ?></span><?php endforeach; ?></div>
        <div><span>Любой объект</span><?php for ($i = 0; $i < 4; $i++): ?><input name="access[<?= $i ?>]" value="<?= (int)$pr['access'][$i] ?>" inputmode="numeric" aria-label="СКУД, <?= e(CALC_POINTS[$i]) ?>"><?php endfor; ?></div>
      </div>
      <h2>Пожарная сигнализация и оповещение</h2>
      <p class="muted">Цена «от» для типового объекта. 0 значит «по смете»: калькулятор так и напишет и не прибавит сумму к итогу.</p>
      <div class="ptable">
        <div class="ptable__h"><span>Система</span><?php foreach (CALC_OBJECTS as $label): ?><span><?= e($label) ?></span><?php endforeach; ?></div>
        <?php foreach (['fire' => 'Пожарная сигнализация (АПС)', 'soue' => 'Оповещение (СОУЭ)'] as $sys => $sysLabel): ?>
          <div><span><?= e($sysLabel) ?></span><?php foreach (CALC_OBJECTS as $o => $label): ?><input name="<?= $sys ?>[<?= $o ?>]" value="<?= (int)($pr[$sys][$o] ?? 0) ?>" inputmode="numeric" aria-label="<?= e($sysLabel . ', ' . $label) ?>"><?php endforeach; ?></div>
        <?php endforeach; ?>
      </div>
      <h2>Надбавки и скидка</h2>
      <div class="grid3">
        <div><label for="bd">Скидка за камеры и СКУД вместе, %</label><input id="bd" name="both_discount" value="<?= (int)$pr['both_discount'] ?>" inputmode="numeric"></div>
        <div><label for="nv">Цветное ночное видение, ₽</label><input id="nv" name="night" value="<?= (int)$pr['night'] ?>" inputmode="numeric"></div>
        <div><label for="ar">Архив больше 30 дней, ₽</label><input id="ar" name="archive" value="<?= (int)$pr['archive'] ?>" inputmode="numeric"></div>
      </div>
      <div class="bar"><button class="btn btn--pri" type="submit">Сохранить цены</button></div>
    </form>
    <?php
    admin_foot();
    break;

/* ---------- объекты (фото) ---------- */
case 'works':
    $works = (array)setting('works');
    $fields = ['title' => ['Название', 'text'], 'sub' => ['Подпись', 'text']];
    if ($isPost) {
        $newExtra = null;
        $up = save_upload($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!$up['ok'] && empty($up['none'])) { flash('error', $up['msg']); redirect(admin_url('works')); }
        $newTitle = trim((string)($_POST['new']['title'] ?? ''));
        if ($up['ok']) {
            $newExtra = ['media' => $up['name']];
            if ($newTitle === '') $_POST['new']['title'] = 'Объект';
        } elseif ($newTitle !== '' || trim((string)($_POST['new']['sub'] ?? '')) !== '') {
            flash('error', 'Чтобы добавить объект, прикрепите фото.');
            $_POST['new'] = [];
        }
        $before = array_filter(array_column($works, 'media'));
        $works = list_apply($works, $fields, $newExtra);
        $after = array_filter(array_column($works, 'media'));
        foreach (array_diff($before, $after) as $gone) {
            if (preg_match('/^[a-f0-9]{16,40}\.(webp|jpg)$/', $gone)) @unlink(data_dir() . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $gone);
            db()->prepare('DELETE FROM media WHERE name = ?')->execute([$gone]);
        }
        set_setting('works', array_values($works));
        if (empty($_SESSION['flash'])) flash('ok', 'Объекты сохранены.');
        redirect(admin_url('works'));
    }
    admin_head('Наши объекты', 'works');
    ?>
    <header class="head"><h1>Наши объекты</h1></header>
    <p class="muted lead-p">Первое фото показывается на сайте крупно. Порядок меняется стрелками.</p>
    <?php list_editor('works', $works, $fields, 'Добавить объект', function ($it) {
        $url = !empty($it['media']) ? media_url((string)$it['media']) : base_path() . '/' . ltrim((string)($it['src'] ?? ''), '/');
        echo '<img class="thumb" src="' . e($url) . '" alt="" loading="lazy">';
    }); ?>
    <?php
    admin_foot();
    break;

/* ---------- отзывы ---------- */
case 'reviews':
    $items = (array)setting('reviews');
    $fields = ['text' => ['Текст отзыва', 'textarea'], 'name' => ['Имя', 'text'], 'type' => ['Объект (например, «Офис»)', 'text']];
    if ($isPost) {
        set_setting('reviews', array_values(array_filter(list_apply($items, $fields), fn($r) => trim($r['text'] ?? '') !== '')));
        flash('ok', 'Отзывы сохранены.');
        redirect(admin_url('reviews'));
    }
    admin_head('Отзывы', 'reviews');
    ?>
    <header class="head"><h1>Отзывы</h1></header>
    <p class="muted lead-p">Публикуйте только настоящие отзывы клиентов и с их разрешения.</p>
    <?php list_editor('reviews', $items, $fields, 'Добавить отзыв'); ?>
    <?php
    admin_foot();
    break;

/* ---------- вопросы ---------- */
case 'faq':
    $items = (array)setting('faq');
    $fields = ['q' => ['Вопрос', 'text'], 'a' => ['Ответ', 'textarea']];
    if ($isPost) {
        set_setting('faq', array_values(array_filter(list_apply($items, $fields), fn($r) => trim($r['q'] ?? '') !== '')));
        flash('ok', 'Вопросы сохранены.');
        redirect(admin_url('faq'));
    }
    admin_head('Вопросы', 'faq');
    ?>
    <header class="head"><h1>Вопросы</h1></header>
    <?php list_editor('faq', $items, $fields, 'Добавить вопрос'); ?>
    <?php
    admin_foot();
    break;

/* ---------- документы и реквизиты ---------- */
case 'docs':
    $l = setting('legal');
    if ($isPost) {
        $op = (string)($_POST['op'] ?? 'save');
        if (preg_match('/^reset:(privacy|consent)$/', $op, $m)) {
            set_setting('doc_tpl_' . $m[1], '');
            docs_sync();
            flash('ok', 'Текст вернули к заготовке.');
            redirect(admin_url('docs'));
        }
        $inn = preg_replace('/\D+/', '', (string)($_POST['inn'] ?? ''));
        $ogrn = preg_replace('/\D+/', '', (string)($_POST['ogrn'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $err = [];
        if ($inn !== '' && !in_array(strlen($inn), [10, 12], true)) $err[] = 'ИНН: 10 цифр у ООО или 12 у ИП';
        if ($ogrn !== '' && !in_array(strlen($ogrn), [13, 15], true)) $err[] = 'ОГРН: 13 цифр у ООО или 15 (ОГРНИП) у ИП';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'проверьте email';
        if ($err) { flash('error', 'Не сохранено: ' . implode('; ', $err) . '.'); redirect(admin_url('docs')); }
        set_setting('legal', [
            'operator' => mb_substr(trim((string)($_POST['operator'] ?? '')), 0, 200),
            'inn' => $inn, 'ogrn' => $ogrn,
            'address' => mb_substr(trim((string)($_POST['address'] ?? '')), 0, 300),
            'email' => $email,
        ]);
        set_setting('retention_months', max(1, min(60, (int)($_POST['retention'] ?? 12))));
        foreach (['privacy', 'consent'] as $k) {
            $txt = trim(str_replace("\r\n", "\n", (string)($_POST['tpl_' . $k] ?? '')));
            $def = (require APP_ROOT . '/lib/docs.php')[$k];
            set_setting('doc_tpl_' . $k, $txt === trim($def) ? '' : $txt);
        }
        docs_sync();
        flash('ok', 'Сохранено. Если текст изменился, у документов появилась новая редакция.');
        redirect(admin_url('docs'));
    }
    $vp = doc_versions('privacy');
    $vc = doc_versions('consent');
    admin_head('Документы и реквизиты', 'docs');
    ?>
    <header class="head"><h1>Документы и реквизиты</h1></header>
    <?php if (!legal_filled()): ?><div class="note note--warn">Заполните название, ИНН и email. Без них документы неполные, и сайт рано выкладывать.</div><?php endif; ?>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <h2>Оператор персональных данных</h2>
      <label for="operator">ИП или ООО полностью</label>
      <input id="operator" name="operator" value="<?= e($l['operator']) ?>" placeholder="ИП Иванов Иван Иванович">
      <div class="grid2">
        <div><label for="inn">ИНН</label><input id="inn" name="inn" value="<?= e($l['inn']) ?>" inputmode="numeric"></div>
        <div><label for="ogrn">ОГРН или ОГРНИП</label><input id="ogrn" name="ogrn" value="<?= e($l['ogrn']) ?>" inputmode="numeric"></div>
      </div>
      <label for="address">Адрес для обращений</label>
      <input id="address" name="address" value="<?= e($l['address']) ?>" placeholder="603000, г. Нижний Новгород, ...">
      <div class="grid2">
        <div><label for="email">Email для запросов</label><input id="email" name="email" type="email" value="<?= e($l['email']) ?>"></div>
        <div><label for="retention">Сколько хранить заявки, месяцев</label><input id="retention" name="retention" value="<?= (int)setting('retention_months') ?>" inputmode="numeric"></div>
      </div>

      <h2>Тексты</h2>
      <p class="muted">Метки подставятся сами: {operator} {inn} {ogrn} {address} {email} {phone} {retention} {site}. «## » в начале строки делает заголовок, «- » делает пункт списка. Перед запуском тексты стоит показать юристу.</p>
      <?php foreach (['privacy' => 'Политика обработки персональных данных', 'consent' => 'Согласие на обработку персональных данных'] as $k => $title): ?>
        <label for="tpl_<?= $k ?>"><?= e($title) ?></label>
        <textarea id="tpl_<?= $k ?>" name="tpl_<?= $k ?>" rows="14" class="mono"><?= e(doc_template($k)) ?></textarea>
        <p class="row-links">
          <a href="<?= base_path() ?>/<?= $k ?>" target="_blank" rel="noopener">Открыть на сайте</a>
          <button class="linkbtn" type="submit" name="op" value="reset:<?= $k ?>" data-confirm="Вернуть текст к заготовке?">Вернуть заготовку</button>
        </p>
      <?php endforeach; ?>
      <div class="bar"><button class="btn btn--pri" type="submit" name="op" value="save">Сохранить</button></div>
    </form>
    <section class="card">
      <h2>Редакции</h2>
      <p class="muted">С каждой заявкой хранится номер редакции согласия, которую видел клиент.</p>
      <div class="grid2">
        <?php foreach (['privacy' => ['Политика', $vp], 'consent' => ['Согласие', $vc]] as $k => [$t, $vs]): ?>
          <div><h3><?= e($t) ?></h3><ul class="versions">
            <?php foreach ($vs as $v): ?><li><a href="<?= base_path() ?>/<?= $k ?>?v=<?= (int)$v['version'] ?>" target="_blank" rel="noopener">ред. <?= (int)$v['version'] ?></a> от <?= e(date('d.m.Y H:i', strtotime($v['created_at']))) ?></li><?php endforeach; ?>
          </ul></div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
    admin_foot();
    break;

/* ---------- уведомления ---------- */
case 'notify':
    $n = setting('notify');
    $chats = [];
    if ($isPost) {
        $op = (string)($_POST['op'] ?? 'save');
        $tok = trim((string)($_POST['tg_token'] ?? ''));
        if ($tok !== '') $n['tg_token'] = $tok;
        if (isset($_POST['tg_chat'])) $n['tg_chat'] = preg_replace('/[^0-9\-@A-Za-z_]/', '', (string)$_POST['tg_chat']);
        $n['tg_with_pd'] = !empty($_POST['tg_with_pd']);
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Проверьте email.'); redirect(admin_url('notify')); }
        $n['email'] = $email;
        if ($op === 'forget') $n['tg_token'] = '';
        set_setting('notify', $n);
        docs_sync();
        if ($op === 'test') {
            if (!$n['tg_token'] || !$n['tg_chat']) { flash('error', 'Сначала укажите токен бота и chat id.'); redirect(admin_url('notify')); }
            $r = tg_api($n['tg_token'], 'sendMessage', ['chat_id' => $n['tg_chat'], 'text' => 'Проверка уведомлений сайта АЙРИС. Если вы это видите, всё работает.']);
            flash(($r['ok'] ?? false) ? 'ok' : 'error', ($r['ok'] ?? false) ? 'Сообщение отправлено. Проверьте Telegram.' : 'Telegram ответил ошибкой: ' . ($r['description'] ?? 'неизвестно'));
            redirect(admin_url('notify'));
        }
        if ($op === 'find') {
            if (!$n['tg_token']) { flash('error', 'Сначала вставьте токен бота.'); redirect(admin_url('notify')); }
            $r = tg_api($n['tg_token'], 'getUpdates', ['limit' => 50]);
            if (!($r['ok'] ?? false)) { flash('error', 'Telegram ответил ошибкой: ' . ($r['description'] ?? 'неизвестно')); redirect(admin_url('notify')); }
            foreach ($r['result'] ?? [] as $u) {
                $ch = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null;
                if ($ch) $chats[(string)$ch['id']] = trim(($ch['title'] ?? '') ?: (($ch['first_name'] ?? '') . ' ' . ($ch['last_name'] ?? '') . (isset($ch['username']) ? ' @' . $ch['username'] : '')));
            }
            if (!$chats) flash('info', 'Бот пока не видит чатов. Напишите боту любое сообщение и нажмите «Найти chat id» ещё раз.');
            else $_SESSION['tg_chats'] = $chats;
            redirect(admin_url('notify'));
        }
        flash('ok', 'Сохранено.');
        redirect(admin_url('notify'));
    }
    $chats = $_SESSION['tg_chats'] ?? [];
    unset($_SESSION['tg_chats']);
    admin_head('Уведомления', 'notify');
    ?>
    <header class="head"><h1>Уведомления о заявках</h1></header>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <h2>Telegram</h2>
      <ol class="steps">
        <li>В Telegram откройте @BotFather, отправьте /newbot и получите токен.</li>
        <li>Вставьте токен ниже и нажмите «Сохранить».</li>
        <li>Напишите своему боту любое сообщение (или добавьте его в рабочую группу).</li>
        <li>Нажмите «Найти chat id», выберите чат, потом «Отправить проверку».</li>
      </ol>
      <label for="tg_token">Токен бота</label>
      <input id="tg_token" name="tg_token" type="password" autocomplete="off" placeholder="<?= $n['tg_token'] ? 'Токен сохранён. Введите новый, чтобы заменить' : '123456789:AA...' ?>">
      <label for="tg_chat">Chat id</label>
      <input id="tg_chat" name="tg_chat" value="<?= e($n['tg_chat']) ?>" placeholder="например, 123456789">
      <?php if ($chats): ?>
        <div class="chats">
          <p class="muted">Бот видит эти чаты. Нажмите на нужный:</p>
          <?php foreach ($chats as $id => $title): ?><button type="button" class="chip" data-chat="<?= e($id) ?>"><?= e($title ?: 'чат') ?> · <?= e($id) ?></button><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <label class="checkline"><input type="checkbox" name="tg_with_pd" value="1"<?= !empty($n['tg_with_pd']) ? ' checked' : '' ?>> Присылать имя и телефон прямо в Telegram</label>
      <p class="muted small">По умолчанию в Telegram приходит только номер заявки и ссылка в админку. Имя и телефон в сообщении означают передачу персональных данных на зарубежные серверы Telegram. По 152-ФЗ для этого нужно уведомить Роскомнадзор. Включайте, только если это сделано.</p>

      <h2>Почта (по желанию)</h2>
      <label for="nemail">Email для копии уведомлений</label>
      <input id="nemail" name="email" type="email" value="<?= e($n['email']) ?>" placeholder="info@iriseye.ru">
      <p class="muted small">Письма отправляет сам хостинг. Если они попадают в спам, пользуйтесь Telegram.</p>

      <div class="bar">
        <button class="btn btn--pri" type="submit" name="op" value="save">Сохранить</button>
        <button class="btn" type="submit" name="op" value="find">Найти chat id</button>
        <button class="btn" type="submit" name="op" value="test">Отправить проверку</button>
        <?php if ($n['tg_token']): ?><button class="btn btn--ghost-danger" type="submit" name="op" value="forget" data-confirm="Удалить сохранённый токен?">Удалить токен</button><?php endif; ?>
      </div>
    </form>
    <?php
    admin_foot();
    break;

/* ---------- пароль ---------- */
case 'password':
    if ($isPost) {
        $u = admin_user();
        $st = db()->prepare('SELECT pass FROM admins WHERE id = ?');
        $st->execute([$u['id']]);
        $hash = (string)$st->fetchColumn();
        $cur = (string)($_POST['cur'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $rep = (string)($_POST['rep'] ?? '');
        if (!password_verify($cur, $hash)) { flash('error', 'Текущий пароль неверный.'); redirect(admin_url('password')); }
        if (mb_strlen($new) < 10) { flash('error', 'Новый пароль короче 10 символов.'); redirect(admin_url('password')); }
        if ($new !== $rep) { flash('error', 'Пароли не совпадают.'); redirect(admin_url('password')); }
        db()->prepare('UPDATE admins SET pass = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        flash('ok', 'Пароль изменён.');
        redirect(admin_url('password'));
    }
    admin_head('Пароль', 'password');
    ?>
    <header class="head"><h1>Смена пароля</h1></header>
    <form method="post" class="card form narrow">
      <?= csrf_field() ?>
      <label for="cur">Текущий пароль</label>
      <input id="cur" name="cur" type="password" autocomplete="current-password" required>
      <label for="new">Новый пароль, от 10 символов</label>
      <input id="new" name="new" type="password" autocomplete="new-password" minlength="10" required>
      <label for="rep">Новый пароль ещё раз</label>
      <input id="rep" name="rep" type="password" autocomplete="new-password" minlength="10" required>
      <div class="bar"><button class="btn btn--pri" type="submit">Сменить пароль</button></div>
    </form>
    <?php
    admin_foot();
    break;

default:
    redirect(admin_url('leads'));
}
