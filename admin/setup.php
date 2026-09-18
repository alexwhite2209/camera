<?php
// Первый запуск: владелец сам задаёт логин и пароль. Открывается только по ссылке с ключом.
declare(strict_types=1);
require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/admin.php';
security_headers(true);
admin_session();

if (admins_exist()) {
    setup_key_drop();
    redirect(admin_url('login'));
}

$key = (string)($_GET['key'] ?? $_POST['key'] ?? '');
$keyOk = $key !== '' && hash_equals(setup_key(), $key);
$err = '';

if ($keyOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $rep = (string)($_POST['rep'] ?? '');
    if (!csrf_ok()) $err = 'Форма устарела. Обновите страницу.';
    elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,40}$/', $login)) $err = 'Логин: от 3 до 40 латинских букв, цифр, точек, дефисов или подчёркиваний.';
    elseif (mb_strlen($pass) < 10) $err = 'Пароль короче 10 символов.';
    elseif ($pass !== $rep) $err = 'Пароли не совпадают.';
    else {
        db()->prepare('INSERT INTO admins(login, pass, created_at) VALUES(?, ?, ?)')->execute([$login, password_hash($pass, PASSWORD_DEFAULT), now()]);
        setup_key_drop();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)db()->lastInsertId();
        $_SESSION['last'] = time();
        unset($_SESSION['csrf']);
        flash('ok', 'Готово, вы администратор. Начните с реквизитов: без них политика и согласие неполные.');
        redirect(admin_url('docs'));
    }
}

admin_head('Первый запуск');
?>
<section class="card login">
  <img src="<?= base_path() ?>/assets/img/logo-160.png" alt="" width="56" height="64">
  <h1>Первый запуск админки</h1>
  <?php if (!$keyOk): ?>
    <p class="muted">Эта страница открывается только по ссылке первого запуска с секретным ключом. Попросите ссылку у того, кто выкладывал сайт.</p>
  <?php else: ?>
    <p class="muted">Придумайте логин и пароль. Их знаете только вы, восстановить пароль можно будет только через хостинг.</p>
    <?php if ($err): ?><p class="note note--error" role="alert"><?= e($err) ?></p><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="key" value="<?= e($key) ?>">
      <label for="login">Логин</label>
      <input id="login" name="login" type="text" autocomplete="username" required value="<?= e($_POST['login'] ?? '') ?>">
      <label for="pass">Пароль, от 10 символов</label>
      <input id="pass" name="pass" type="password" autocomplete="new-password" minlength="10" required>
      <label for="rep">Пароль ещё раз</label>
      <input id="rep" name="rep" type="password" autocomplete="new-password" minlength="10" required>
      <button class="btn btn--pri btn--block" type="submit">Создать администратора</button>
    </form>
  <?php endif; ?>
</section>
<?php
admin_foot();
