<?php
// Общие части админки: оформление, меню, редактор списков, загрузка фото.
declare(strict_types=1);

const ADMIN_PAGES = [
    'leads'    => ['Заявки', 'inbox'],
    'contacts' => ['Контакты и часы', 'phone'],
    'prices'   => ['Цены калькулятора', 'calc'],
    'works'    => ['Наши объекты', 'photo'],
    'reviews'  => ['Отзывы', 'quote'],
    'faq'      => ['Вопросы', 'help'],
    'docs'     => ['Документы и реквизиты', 'doc'],
    'notify'   => ['Уведомления', 'bell'],
    'password' => ['Пароль', 'lock'],
];

/** Одноразовый ключ первого запуска: без него страницу создания администратора не открыть. */
function setup_key(): string
{
    $f = data_dir() . DIRECTORY_SEPARATOR . 'setup.key';
    if (!is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(12)), LOCK_EX);
        @chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
}

function setup_key_drop(): void
{
    @unlink(data_dir() . DIRECTORY_SEPARATOR . 'setup.key');
}

function admin_url(string $page = 'leads', array $q = []): string
{
    $q = array_merge(['p' => $page], $q);
    return base_path() . '/admin/?' . http_build_query($q);
}

function admin_icon(string $name): string
{
    $p = [
        'inbox' => '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M5 5h14l1 8v6H4v-6z"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'calc'  => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h2M12 11h2M16 11v6M8 15h2M12 15h2"/>',
        'photo' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 8"/>',
        'quote' => '<path d="M5 17V11a4 4 0 0 1 4-4M13 17V11a4 4 0 0 1 4-4"/><path d="M5 17h4v-4H5M13 17h4v-4h-4"/>',
        'help'  => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17.5v.01"/>',
        'doc'   => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
        'bell'  => '<path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'lock'  => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'out'   => '<path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/>',
        'up'    => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'down'  => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'x'     => '<path d="M6 6l12 12M18 6L6 18"/>',
        'wa'    => '<path d="M3 21l1.65-3.8A9 9 0 1 1 7.8 19.6L3 21"/>',
        'ext'   => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
    ][$name] ?? '';
    return '<svg class="ai" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

function admin_head(string $title, string $active = ''): void
{
    $bp = base_path();
    $user = admin_user();
    $flash = take_flash();
    $newCount = 0;
    try { $newCount = (int)db()->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn(); } catch (Throwable $e) { }
    ?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#06080E">
<title><?= e($title) ?> · Админка АЙРИС</title>
<link rel="icon" href="<?= $bp ?>/assets/img/favicon-64.png" type="image/png">
<link rel="stylesheet" href="<?= $bp ?>/assets/css/fonts.css">
<link rel="stylesheet" href="<?= $bp ?>/admin/admin.css?v=<?= APP_VERSION ?>">
</head>
<body class="adm<?= $user ? '' : ' adm--guest' ?>">
<?php if ($user): ?>
<header class="top">
  <a class="brand" href="<?= e(admin_url()) ?>"><img src="<?= $bp ?>/assets/img/logo-160.png" alt="" width="24" height="27"><span>АЙРИС</span><em>админка</em></a>
  <a class="top__site" href="<?= $bp ?>/" target="_blank" rel="noopener"><?= admin_icon('ext') ?><span>Открыть сайт</span></a>
  <form method="post" action="<?= e(admin_url('logout')) ?>" class="top__out"><?= csrf_field() ?><button type="submit" title="Выйти"><?= admin_icon('out') ?><span><?= e($user['login']) ?></span></button></form>
</header>
<nav class="side" aria-label="Разделы админки">
  <?php foreach (ADMIN_PAGES as $key => [$label, $ico]): ?>
    <a href="<?= e(admin_url($key)) ?>" class="<?= $active === $key ? 'on' : '' ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= admin_icon($ico) ?><span><?= e($label) ?></span><?php if ($key === 'leads' && $newCount): ?><b class="badge"><?= $newCount ?></b><?php endif; ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="main" id="main">
<?php if ($user && !legal_filled() && $active !== 'docs'): ?>
  <div class="note note--warn">Заполните реквизиты в разделе <a href="<?= e(admin_url('docs')) ?>">«Документы и реквизиты»</a>. Без них политика и согласие неполные, и сайт рано выкладывать.</div>
<?php endif; ?>
<?php if ($flash): ?>
  <div class="note note--<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php
}

function admin_foot(): void
{
    $bp = base_path();
    ?>
</main>
<script src="<?= $bp ?>/admin/admin.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
<?php
}

function status_badge(string $s): string
{
    return '<span class="st st--' . e($s) . '">' . e(LEAD_STATUSES[$s] ?? $s) . '</span>';
}

function object_label(string $k): string { return OBJECT_TYPES[$k] ?? ''; }

function lead_calc_text(array $lead): string
{
    if ($lead['calc_json'] === '') return '';
    $c = json_decode($lead['calc_json'], true);
    if (!is_array($c)) return '';
    return isset($c['text']) ? (string)$c['text'] : calc_summary($c);
}

/**
 * Редактор простого списка (отзывы, вопросы, объекты).
 * $fields: ['text' => ['Текст', 'textarea'], 'name' => ['Имя', 'text']]
 */
function list_editor(string $page, array $items, array $fields, string $addLabel, ?callable $extra = null): void
{
    ?>
    <form method="post" class="list-ed" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <?php foreach ($items as $i => $it): ?>
        <fieldset class="item">
          <legend>№ <?= $i + 1 ?></legend>
          <?php if ($extra) $extra($it, $i); ?>
          <?php foreach ($fields as $f => [$label, $type]): $id = "f{$i}_{$f}"; ?>
            <label for="<?= $id ?>"><?= e($label) ?></label>
            <?php if ($type === 'textarea'): ?>
              <textarea id="<?= $id ?>" name="items[<?= $i ?>][<?= $f ?>]" rows="3"><?= e($it[$f] ?? '') ?></textarea>
            <?php else: ?>
              <input id="<?= $id ?>" type="text" name="items[<?= $i ?>][<?= $f ?>]" value="<?= e($it[$f] ?? '') ?>">
            <?php endif; ?>
          <?php endforeach; ?>
          <div class="item__tools">
            <button type="submit" name="op" value="up:<?= $i ?>" class="icon-btn" title="Выше" aria-label="Переместить выше"<?= $i === 0 ? ' disabled' : '' ?>><?= admin_icon('up') ?></button>
            <button type="submit" name="op" value="down:<?= $i ?>" class="icon-btn" title="Ниже" aria-label="Переместить ниже"<?= $i === count($items) - 1 ? ' disabled' : '' ?>><?= admin_icon('down') ?></button>
            <button type="submit" name="op" value="del:<?= $i ?>" class="icon-btn icon-btn--danger" data-confirm="Удалить № <?= $i + 1 ?>?" title="Удалить" aria-label="Удалить"><?= admin_icon('x') ?></button>
          </div>
        </fieldset>
      <?php endforeach; ?>
      <fieldset class="item item--new">
        <legend><?= e($addLabel) ?></legend>
        <?php if ($page === 'works'): ?>
          <label for="newPhoto">Фото (JPG, PNG или WebP, до 12 МБ)</label>
          <input id="newPhoto" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
        <?php endif; ?>
        <?php foreach ($fields as $f => [$label, $type]): $id = "new_{$f}"; ?>
          <label for="<?= $id ?>"><?= e($label) ?></label>
          <?php if ($type === 'textarea'): ?>
            <textarea id="<?= $id ?>" name="new[<?= $f ?>]" rows="3"></textarea>
          <?php else: ?>
            <input id="<?= $id ?>" type="text" name="new[<?= $f ?>]">
          <?php endif; ?>
        <?php endforeach; ?>
      </fieldset>
      <div class="bar"><button class="btn btn--pri" type="submit" name="op" value="save">Сохранить</button></div>
    </form>
    <?php
}

/** Разбирает отправку редактора списка: правки, перемещения, удаление, новый пункт. */
function list_apply(array $current, array $fields, ?array $newExtra = null): array
{
    $posted = $_POST['items'] ?? [];
    $items = [];
    foreach ($current as $i => $it) {
        $row = $it;
        foreach (array_keys($fields) as $f) {
            if (isset($posted[$i][$f])) $row[$f] = trim(str_replace("\r\n", "\n", (string)$posted[$i][$f]));
        }
        $items[] = $row;
    }
    $op = (string)($_POST['op'] ?? 'save');
    if (preg_match('/^(up|down|del):(\d+)$/', $op, $m)) {
        $i = (int)$m[2];
        if ($m[1] === 'del' && isset($items[$i])) array_splice($items, $i, 1);
        if ($m[1] === 'up' && $i > 0 && isset($items[$i])) [$items[$i - 1], $items[$i]] = [$items[$i], $items[$i - 1]];
        if ($m[1] === 'down' && isset($items[$i + 1])) [$items[$i + 1], $items[$i]] = [$items[$i], $items[$i + 1]];
    }
    $new = $_POST['new'] ?? [];
    $row = [];
    $filled = false;
    foreach (array_keys($fields) as $f) {
        $row[$f] = trim(str_replace("\r\n", "\n", (string)($new[$f] ?? '')));
        if ($row[$f] !== '') $filled = true;
    }
    if ($newExtra) { $row = array_merge($row, $newExtra); $filled = true; }
    if ($filled) $items[] = $row;
    return $items;
}

/** Принимает фото, сжимает до 1600 px по длинной стороне, сохраняет в WebP вне веб-папки. */
function save_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'none' => true];
    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => 'Файл не загрузился. Попробуйте ещё раз.'];
    if ($file['size'] > 12 * 1024 * 1024) return ['ok' => false, 'msg' => 'Файл больше 12 МБ.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default      => false,
    };
    if (!$src) return ['ok' => false, 'msg' => 'Нужен файл JPG, PNG или WebP.'];
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) $src = imagerotate($src, 180, 0);
        if ($o === 6) $src = imagerotate($src, -90, 0);
        if ($o === 8) $src = imagerotate($src, 90, 0);
    }
    $w = imagesx($src); $h = imagesy($src);
    $k = min(1, 1600 / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name = bin2hex(random_bytes(10)) . '.webp';
    $ok = imagewebp($dst, data_dir() . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $name, 82);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) return ['ok' => false, 'msg' => 'Не удалось сохранить фото.'];
    db()->prepare('INSERT INTO media(name, w, h, created_at) VALUES(?, ?, ?, ?)')->execute([$name, $nw, $nh, now()]);
    return ['ok' => true, 'name' => $name];
}
