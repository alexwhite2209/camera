<?php
// Общая страница документа: политика или согласие. Перед подключением задаётся $DOC_KIND.
declare(strict_types=1);
require __DIR__ . '/boot.php';
security_headers();

$titles = [
    'privacy' => 'Политика обработки персональных данных',
    'consent' => 'Согласие на обработку персональных данных',
];
$kind = $DOC_KIND ?? 'privacy';
$ver  = isset($_GET['v']) && ctype_digit((string)$_GET['v']) ? (int)$_GET['v'] : null;
$doc  = doc_get($kind, $ver);
if (!$doc) {
    http_response_code(404);
}
$c    = setting('contacts');
$bp   = base_path();
$date = $doc ? date('d.m.Y', strtotime($doc['created_at'])) : '';
$other = $kind === 'privacy' ? ['consent', 'Согласие на обработку персональных данных'] : ['privacy', 'Политика обработки персональных данных'];
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titles[$kind]) ?> · АЙРИС</title>
<meta name="robots" content="noindex, follow">
<meta name="theme-color" content="#06080E">
<link rel="icon" href="<?= $bp ?>/assets/img/favicon-64.png" type="image/png">
<link rel="stylesheet" href="<?= $bp ?>/assets/css/fonts.css">
<link rel="stylesheet" href="<?= $bp ?>/assets/css/site.css">
</head>
<body class="doc-page">
<a class="skip" href="#main">Перейти к тексту</a>
<div class="env" aria-hidden="true"><div class="env__glow"></div><div class="env__dots"></div></div>
<header class="doc-head">
  <a class="doc-head__logo" href="<?= $bp ?>/"><img src="<?= $bp ?>/assets/img/logo-160.png" alt="" width="28" height="32"><span>АЙРИС</span></a>
  <a class="doc-head__back" href="<?= $bp ?>/">На главную</a>
</header>
<main id="main" class="doc" tabindex="-1">
  <p class="kicker">Документы</p>
  <h1><?= e($titles[$kind]) ?></h1>
  <?php if ($doc): ?>
    <p class="doc__meta">Редакция <?= (int)$doc['version'] ?> от <?= e($date) ?></p>
    <div class="doc__body"><?= doc_html($doc['rendered']) ?></div>
  <?php else: ?>
    <p>Документ не найден.</p>
  <?php endif; ?>
  <p class="doc__see">Смотрите также: <a href="<?= $bp ?>/<?= $other[0] ?>"><?= e($other[1]) ?></a></p>
</main>
<footer class="doc-foot">
  <span>© <?= date('Y') ?> АЙРИС</span>
  <a href="<?= e(tel_href($c['phone'])) ?>"><?= e($c['phone']) ?></a>
</footer>
</body>
</html>
