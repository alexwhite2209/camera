<?php
// Админка сайта АЙРИС. Один вход, разделы через ?p=
declare(strict_types=1);
require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/admin.php';
security_headers(true);
admin_session();

if (!admins_exist()) redirect(base_path() . '/admin/setup.php');

$p = (string)($_GET['p'] ?? 'contacts');
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
                redirect(admin_url('contacts'));
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

if ($p === 'login') redirect(admin_url('contacts'));

/* ================= разделы ================= */
switch ($p) {

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
        if (preg_match('/^reset:(privacy)$/', $op, $m)) {
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
            'license' => mb_substr(trim((string)($_POST['license'] ?? '')), 0, 200),
        ]);
        foreach (['privacy'] as $k) {
            $txt = trim(str_replace("\r\n", "\n", (string)($_POST['tpl_' . $k] ?? '')));
            $def = (require APP_ROOT . '/lib/docs.php')[$k];
            set_setting('doc_tpl_' . $k, $txt === trim($def) ? '' : $txt);
        }
        docs_sync();
        flash('ok', 'Сохранено. Если текст изменился, у документов появилась новая редакция.');
        redirect(admin_url('docs'));
    }
    $vp = doc_versions('privacy');
    admin_head('Документы и реквизиты', 'docs');
    ?>
    <header class="head"><h1>Документы и реквизиты</h1></header>
    <?php if (!legal_filled()): ?><div class="note note--warn">Заполните название, ИНН и email. Без них документы неполные, и сайт рано выкладывать.</div><?php endif; ?>
    <section class="card">
      <h2>Что нужно сделать по закону</h2>
      <p class="muted">Формы заявки на сайте нет, поэтому согласие на обработку данных не собирается: люди звонят или пишут сами, а это другое основание (пункт 5 части 1 статьи 6 закона № 152-ФЗ). Сайт закрывает свою часть требований: политика опубликована, реквизиты в подвале, Метрика ждёт согласия на cookie. Остальное делает владелец, один раз.</p>
      <ul class="checklist">
        <li>Заполнить реквизиты выше. Они подставляются в документы и в подвал сайта.</li>
        <li>Подать уведомление об обработке персональных данных в Роскомнадзор до начала работы с клиентами: <a href="https://pd.rkn.gov.ru/operators-registry/notification/" target="_blank" rel="noopener">pd.rkn.gov.ru</a>. Без уведомления штраф до 300 000 ₽ (часть 10 статьи 13.11 КоАП).</li>
        <li>Держать сайт и базу на российском хостинге: базы с персональными данными должны находиться в России (часть 5 статьи 18 закона № 152-ФЗ).</li>
        <li>Оформить внутренние документы: приказ о назначении ответственного (для ООО), правила обработки, оценку вреда субъектам (приказ Роскомнадзора от 27.10.2022 № 178), порядок уничтожения данных.</li>
        <li>Если данные утекли, сообщить в Роскомнадзор в течение 24 часов, а о результатах расследования в течение 72 часов (часть 3.1 статьи 21 закона № 152-ФЗ).</li>
      </ul>
    </section>
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
      <label for="license">Лицензия МЧС на монтаж пожарной сигнализации и оповещения</label>
      <input id="license" name="license" value="<?= e($l['license'] ?? '') ?>" placeholder="Лицензия МЧС № 52-Б/00123 от 01.01.2024">
      <p class="muted">Монтаж АПС и СОУЭ лицензируется. Номер попадёт в подвал сайта. Если лицензии нет, уберите пожарные услуги из описаний: за рекламу лицензируемых работ без лицензии штрафуют отдельно.</p>
      <label for="email">Email для запросов по персональным данным</label>
      <input id="email" name="email" type="email" value="<?= e($l['email']) ?>">

      <h2>Тексты</h2>
      <p class="muted">Метки подставятся сами: {operator} {inn} {ogrn} {address} {email} {phone} {site}. «## » в начале строки делает заголовок, «- » делает пункт списка. Перед запуском тексты стоит показать юристу.</p>
      <?php foreach (['privacy' => 'Политика обработки персональных данных'] as $k => $title): ?>
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
      <p class="muted">Каждая правка текста создаёт новую редакцию, старые остаются доступными по ссылке.</p>
      <div class="grid2">
        <?php foreach (['privacy' => ['Политика', $vp]] as $k => [$t, $vs]): ?>
          <div><h3><?= e($t) ?></h3><ul class="versions">
            <?php foreach ($vs as $v): ?><li><a href="<?= base_path() ?>/<?= $k ?>?v=<?= (int)$v['version'] ?>" target="_blank" rel="noopener">ред. <?= (int)$v['version'] ?></a> от <?= e(date('d.m.Y H:i', strtotime($v['created_at']))) ?></li><?php endforeach; ?>
          </ul></div>
        <?php endforeach; ?>
      </div>
    </section>
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
    redirect(admin_url('contacts'));
}
