<?php
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';
security_headers();
header('Cache-Control: no-cache');

$c       = setting('contacts');
$prices  = setting('prices');
$works   = works_public();
$reviews = (array)setting('reviews');
$faq     = (array)setting('faq');
$bp      = base_path();
$site    = site_url();

$phone = (string)$c['phone'];
$tel   = tel_href($phone);
$waNum = preg_replace('/\D+/', '', (string)$c['whatsapp']);
$tgNick = ltrim((string)$c['telegram'], '@');
$wa    = 'https://wa.me/' . $waNum;
$tg    = 'https://t.me/' . $tgNick;
$static = is_static_build();
try { $token = $static ? '' : form_token(); } catch (Throwable $e) { $token = ''; }

$cfg = [
    'prices'  => $prices,
    'phone'   => $phone,
    'wa'      => $waNum,
    'tg'      => $tgNick,
    'metrika' => 108236860,
    'base'    => $bp,
    'static'  => $static,
];

$ld = [
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => 'АЙРИС',
    'description' => 'Монтаж видеонаблюдения, СКУД, охранной и пожарной сигнализации и систем оповещения под ключ',
    'url' => $site . '/',
    'image' => $site . '/assets/img/og.jpg',
    'logo' => $site . '/assets/img/logo-512.png',
    'telephone' => preg_replace('/[^\d+]/', '', $tel),
    'areaServed' => 'Нижегородская область',
    'openingHours' => 'Mo-Su 08:00-19:00',
];

$icon = fn(string $name) => '<svg class="i" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
?><!doctype html>
<html lang="ru" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>АЙРИС: видеонаблюдение и СКУД под ключ в Нижнем Новгороде</title>
<meta name="description" content="Подбираем, привозим, монтируем и настраиваем видеонаблюдение, СКУД, охранную и пожарную сигнализацию, системы оповещения. Больше 500 объектов, гарантия 2 года, выезд инженера за 24 часа. Нижний Новгород и область.">
<meta name="theme-color" content="#06080E">
<?php if ($static): ?><meta name="robots" content="noindex, follow">
<?php endif; ?><link rel="canonical" href="<?= e($site) ?>/">
<meta property="og:type" content="website">
<meta property="og:locale" content="ru_RU">
<meta property="og:title" content="АЙРИС: видеонаблюдение и СКУД под ключ">
<meta property="og:description" content="Видеонаблюдение, СКУД, охранная и пожарная сигнализация, оповещение. Нижний Новгород и область. Гарантия 2 года.">
<meta property="og:url" content="<?= e($site) ?>/">
<meta property="og:image" content="<?= e($site) ?>/assets/img/og.jpg">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= $bp ?>/assets/img/favicon-64.png" type="image/png">
<link rel="apple-touch-icon" href="<?= $bp ?>/assets/img/apple-touch-icon.png">
<link rel="preload" href="<?= $bp ?>/assets/fonts/Tektur-500-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= $bp ?>/assets/img/logo-512.png" as="image">
<link rel="stylesheet" href="<?= $bp ?>/assets/css/fonts.css">
<link rel="stylesheet" href="<?= $bp ?>/assets/css/site.css?v=<?= asset_ver('assets/css/site.css') ?>">
<script>document.documentElement.className='js';</script>
<noscript><style>.intro{display:none!important}.hero{height:auto!important}.stage{position:relative!important}.plate[data-plate="1"]{opacity:1!important;visibility:visible!important}</style></noscript>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script type="application/json" id="cfg"><?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="is-intro">

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-wa" viewBox="0 0 24 24"><path d="M3 21l1.65-3.8A9 9 0 1 1 7.8 19.6L3 21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-tg" viewBox="0 0 24 24"><path d="M15 10l-4 4 6 6 4-16-18 7 4 2 2 6 3-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
</svg>

<a class="skip" href="#main">Перейти к содержанию</a>

<div class="env" aria-hidden="true">
  <div class="env__glow"></div>
  <canvas class="env__drift" id="drift"></canvas>
  <div class="env__scan"></div>
  <div class="env__grain"></div>
</div>

<!-- Заставка: она же загрузка видео -->
<div class="intro" id="intro" aria-hidden="true">
  <svg class="aperture" id="aperture" viewBox="-100 -100 200 200" preserveAspectRatio="xMidYMid slice">
    <?php for ($i = 0; $i < 8; $i++):
        $a = deg2rad($i * 45); $b = deg2rad($i * 45 + 62);
        $ax = round(cos($a) * 175, 2); $ay = round(sin($a) * 175, 2);
        $bx = round(cos($b) * 175, 2); $by = round(sin($b) * 175, 2); ?>
      <path class="blade" data-px="<?= $ax ?>" data-py="<?= $ay ?>" d="M0 0 L<?= $ax ?> <?= $ay ?> A175 175 0 0 1 <?= $bx ?> <?= $by ?> Z"/>
    <?php endfor; ?>
  </svg>
  <div class="intro__line"></div>
  <div class="intro__vf"><i></i><i></i><i></i><i></i></div>
  <div class="intro__osd intro__osd--tl"><b class="rec"></b>REC</div>
  <div class="intro__osd intro__osd--tr" id="introTc">00:00:00:00</div>
  <div class="intro__osd intro__osd--bl">CAM 00 · АЙРИС</div>
  <div class="intro__osd intro__osd--br"><span id="introPct">0</span>%</div>
  <div class="intro__core">
    <svg class="intro__rings" viewBox="0 0 200 200">
      <circle class="ring ring--track" cx="100" cy="100" r="92"/>
      <circle class="ring ring--load" id="introLoad" cx="100" cy="100" r="92" pathLength="100" transform="rotate(-90 100 100)"/>
      <g class="ring-spin"><circle class="ring ring--dash" cx="100" cy="100" r="79"/></g>
      <circle class="ring ring--in" cx="100" cy="100" r="68" pathLength="100"/>
      <g class="ticks"><?php for ($i = 0; $i < 24; $i++): $t = deg2rad($i * 15); $r1 = $i % 6 === 0 ? 96 : 98.5; ?><line x1="<?= round(100 + cos($t) * $r1, 2) ?>" y1="<?= round(100 + sin($t) * $r1, 2) ?>" x2="<?= round(100 + cos($t) * 103, 2) ?>" y2="<?= round(100 + sin($t) * 103, 2) ?>"/><?php endfor; ?></g>
    </svg>
    <div class="intro__logo" id="introLogoWrap">
      <img class="intro__soft" src="<?= $bp ?>/assets/img/logo-512.png" alt="" width="450" height="512">
      <img class="intro__sharp" id="introLogo" src="<?= $bp ?>/assets/img/logo-512.png" alt="" width="450" height="512">
      <span class="intro__glint"></span>
    </div>
  </div>
  <p class="intro__sub" id="introSub">СИСТЕМЫ БЕЗОПАСНОСТИ</p>
  <p class="intro__hint">Нажмите, чтобы пропустить</p>
</div>

<header class="nav" id="nav">
  <a class="nav__logo" href="#top" aria-label="АЙРИС, в начало">
    <img id="navLogo" src="<?= $bp ?>/assets/img/logo-160.png" alt="" width="32" height="36">
    <span class="nav__word">АЙРИС</span>
  </a>
  <nav class="nav__links" aria-label="Разделы">
    <a href="#services">Услуги</a>
    <a href="#calc">Цены</a>
    <a href="#works">Работы</a>
    <a href="#reviews">Отзывы</a>
    <a href="#contact">Контакты</a>
  </nav>
  <a class="nav__phone" href="<?= e($tel) ?>"><?= $icon('phone') ?><span><?= e($phone) ?></span></a>
  <a class="btn btn--primary btn--sm nav__cta" href="#calc">Рассчитать</a>
  <button class="nav__burger" id="burger" type="button" aria-expanded="false" aria-controls="menu" aria-label="Меню"><i></i><i></i></button>
</header>

<div class="menu" id="menu" hidden>
  <nav aria-label="Меню">
    <a href="#services">Услуги</a>
    <a href="#calc">Цены</a>
    <a href="#works">Работы</a>
    <a href="#reviews">Отзывы</a>
    <a href="#faq">Вопросы</a>
    <a href="#contact">Контакты</a>
  </nav>
  <a class="menu__phone" href="<?= e($tel) ?>"><?= e($phone) ?></a>
  <p class="menu__hours"><?= e($c['hours']) ?></p>
</div>

<main id="main" tabindex="-1">
<span id="top"></span>

<!-- Герой: прокрутка кадрами -->
<section class="hero" id="hero" aria-label="Как работает система безопасности АЙРИС">
  <div class="stage is-full" id="stage">
    <canvas class="stage__amb" id="amb" width="8" height="14" aria-hidden="true"></canvas>
    <div class="stage__frame" id="frame" aria-hidden="true">
      <picture><source media="(min-width: 901px) and (orientation: landscape), (min-width: 901px) and (pointer: fine)" srcset="<?= $bp ?>/assets/img/poster-d.jpg"><img class="stage__poster" src="<?= $bp ?>/assets/img/poster.jpg" alt="" decoding="async"></picture>
      <video class="stage__v v-fwd" id="vFwd" muted playsinline preload="none" disablepictureinpicture tabindex="-1"></video>
      <video class="stage__v v-rev" id="vRev" muted playsinline preload="none" disablepictureinpicture tabindex="-1"></video>
      <div class="stage__shade"></div>
      <div class="stage__alarm"></div>
      <div class="osd">
        <span class="osd__tl"><b class="rec"></b><span id="osdCam">CAM 01 · ФАСАД</span></span>
        <span class="osd__tr" id="osdTc">00:00:00:00</span>
        <span class="osd__bl" id="osdClock"></span>
        <span class="osd__flash" id="osdFlash">● ТРЕВОГА · ЗОНА 3 · ДЫМ</span>
      </div>
    </div>

    <div class="stage__ui" id="stageUi">
      <article class="plate plate--left" data-plate="1">
        <p class="plate__kicker"><b class="rec"></b>АЙРИС · НИЖНИЙ НОВГОРОД И ОБЛАСТЬ</p>
        <h1 class="plate__title plate__title--xl fx-blur">Видеонаблюдение и контроль доступа под ключ</h1>
        <p class="plate__text fx-sub">Подберём, привезём, смонтируем и настроим. Вам останется смотреть в телефон.</p>
        <div class="plate__static-cta">
          <a class="btn btn--primary" href="#calc">Рассчитать стоимость</a>
          <a class="btn btn--ghost" href="<?= e($tel) ?>"><?= $icon('phone') ?>Позвонить</a>
        </div>
      </article>

      <article class="plate plate--right" data-plate="2">
        <span class="trail" aria-hidden="true"><i></i></span>
        <p class="plate__kicker">01 · ВИДЕОНАБЛЮДЕНИЕ</p>
        <h2 class="plate__title fx-cut">Каждый угол в кадре</h2>
        <p class="plate__text fx-sub">IP и AHD камеры, регистратор и аккуратная прокладка кабеля. Ночное видение и детекция движения.</p>
        <ul class="chips fx-chips"><li>4K</li><li>Цвет ночью</li><li>Архив от 30 дней</li></ul>
      </article>

      <article class="plate plate--left" data-plate="3">
        <span class="trail" aria-hidden="true"><i></i></span>
        <p class="plate__kicker">02 · СКУД И ДОМОФОНИЯ</p>
        <h2 class="plate__title fx-door">Дверь откроется только своим</h2>
        <p class="plate__text fx-sub">Биометрия и карты, видеодомофоны, замки, турникеты и шлагбаумы. Плюс учёт рабочего времени.</p>
        <ul class="chips fx-chips"><li>Биометрия</li><li>Карты</li><li>Турникеты</li></ul>
      </article>

      <article class="plate plate--right plate--alarm" data-plate="4">
        <span class="trail" aria-hidden="true"><i></i></span>
        <p class="plate__kicker">03 · ПОЖАРНАЯ СИГНАЛИЗАЦИЯ И СОУЭ</p>
        <div class="plate__fold"><div>
          <h2 class="plate__title fx-punch">Узнаете первым</h2>
          <p class="plate__text fx-sub">Датчики дыма и тепла замечают пожар в самом начале. Сирена и речевое оповещение включаются сразу.</p>
        </div></div>
        <ul class="status fx-chips" id="status">
          <li data-at="4.35" data-on="тревога"><span>Дым</span><b>норма</b></li>
          <li><span>Тепло</span><b>норма</b></li>
          <li data-at="6.25" data-on="включена" data-off="ждёт"><span>Сирена</span><b>ждёт</b></li>
          <li data-at="6.3" data-on="идёт" data-off="ждёт"><span>Оповещение</span><b>ждёт</b></li>
        </ul>
      </article>

      <article class="plate plate--left" data-plate="5">
        <span class="trail" aria-hidden="true"><i></i></span>
        <p class="plate__kicker">ОПЫТ</p>
        <h2 class="plate__title fx-cut"><span class="tick" data-to="95">95</span> камер в одной системе</h2>
        <p class="plate__text fx-sub">Столько мы поставили в гипермаркете «Перекрёсток». Всего больше 500 объектов по Нижегородской области.</p>
        <ul class="chips fx-chips"><li>500+ объектов</li><li>TRASSIR</li><li>Dahua</li></ul>
      </article>

      <article class="plate plate--right" data-plate="6">
        <span class="trail" aria-hidden="true"><i></i></span>
        <p class="plate__kicker">УДАЛЁННЫЙ ДОСТУП</p>
        <h2 class="plate__title fx-drop">Дом и офис в вашем телефоне</h2>
        <p class="plate__text fx-sub">Настроим просмотр со смартфона бесплатно. Камеры и архив откуда угодно.</p>
        <blockquote class="plate__quote fx-chips">
          <p>«Смотрю через телефон, очень удобно и спокойно за дом.»</p>
          <footer>Дмитрий В.</footer>
        </blockquote>
      </article>

      <article class="plate plate--cta" data-plate="7">
        <h2 class="plate__title fx-rise">Сколько стоит защитить ваш объект?</h2>
        <p class="plate__text fx-sub">Консультация и предварительная смета бесплатно. Выезжаем в течение 24 часов.</p>
        <div class="plate__actions fx-btns">
          <a class="btn btn--primary btn--shine" href="#calc">Рассчитать стоимость</a>
          <a class="btn btn--ghost" href="<?= e($tel) ?>"><?= $icon('phone') ?>Позвонить</a>
        </div>
        <p class="plate__msg fx-btns">
          <a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= $icon('wa') ?>WhatsApp</a>
          <a href="<?= e($tg) ?>" target="_blank" rel="noopener"><?= $icon('tg') ?>Telegram</a>
        </p>
      </article>

      <div class="stage__load" id="stageLoad" aria-hidden="true">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" pathLength="100"/><circle class="v" id="stageLoadRing" cx="12" cy="12" r="9" pathLength="100" transform="rotate(-90 12 12)"/></svg>
        <span>КАДРЫ <b id="stageLoadPct">0</b>%</span>
      </div>
      <div class="stage__cue" id="cue" aria-hidden="true"><span>Листайте</span><i></i></div>
    </div>
  </div>
</section>
<span class="hero-end" id="heroEnd"></span>

<!-- Вступительная фраза -->
<section class="manifesto" aria-label="Коротко о нас">
  <div class="wrap">
    <p class="manifesto__text" id="manifesto">Камеры видят. Двери узнают своих. Датчики сообщают сразу. А вы спокойно занимаетесь делами.</p>
  </div>
</section>

<!-- Услуги -->
<section class="section services" id="services" aria-labelledby="svcTitle">
  <div class="wrap">
    <header class="sec-head" data-reveal>
      <p class="kicker">Что мы ставим</p>
      <h2 class="h2" id="svcTitle">Пять систем, одна команда</h2>
      <p class="lead">Подбираем оборудование под объект и бюджет, привозим, монтируем и настраиваем. Работаем в Нижнем Новгороде и по области.</p>
    </header>
    <div class="svc" data-reveal>
      <div class="svc__tabs" role="tablist" aria-label="Услуги">
        <span class="svc__ind" aria-hidden="true"></span>
        <button class="svc__tab" role="tab" id="tab-video" aria-controls="pan-video" aria-selected="true"><span class="n">01</span>Видеонаблюдение</button>
        <button class="svc__tab" role="tab" id="tab-access" aria-controls="pan-access" aria-selected="false" tabindex="-1"><span class="n">02</span>СКУД и домофония</button>
        <button class="svc__tab" role="tab" id="tab-alarm" aria-controls="pan-alarm" aria-selected="false" tabindex="-1"><span class="n">03</span>Охранная сигнализация</button>
        <button class="svc__tab" role="tab" id="tab-fire" aria-controls="pan-fire" aria-selected="false" tabindex="-1"><span class="n">04</span>Автоматическая пожарная сигнализация (АПС)</button>
        <button class="svc__tab" role="tab" id="tab-soue" aria-controls="pan-soue" aria-selected="false" tabindex="-1"><span class="n">05</span>Система оповещения (СОУЭ)</button>
      </div>
      <div class="svc__card" id="svcCard">
        <span class="spot" aria-hidden="true"></span>
        <div class="svc__pan" role="tabpanel" id="pan-video" aria-labelledby="tab-video">
          <figure class="svc__img"><img src="<?= $bp ?>/assets/img/svc-video.jpg" alt="Стойка с регистратором и экранами камер" loading="lazy" width="900" height="1125"></figure>
          <div class="svc__body">
            <p class="svc__desc">Камеры для дома, офиса, склада и производства. Смотрите их со смартфона в любое время.</p>
            <p class="mini">Что входит</p>
            <ul class="checks">
              <li><?= $icon('check') ?>Подбор и установка IP и AHD камер</li>
              <li><?= $icon('check') ?>Монтаж видеорегистраторов NVR и DVR</li>
              <li><?= $icon('check') ?>Настройка просмотра со смартфона и ПК</li>
              <li><?= $icon('check') ?>Ночное видение и детекция движения</li>
              <li><?= $icon('check') ?>Прокладка кабельных трасс</li>
            </ul>
            <p class="mini">Где ставим</p>
            <ul class="chips"><li>Частный дом</li><li>Квартира</li><li>Офис</li><li>Магазин</li><li>Склад</li><li>Производство</li></ul>
          </div>
        </div>
        <div class="svc__pan" role="tabpanel" id="pan-access" aria-labelledby="tab-access" hidden>
          <figure class="svc__img"><img src="<?= $bp ?>/assets/img/svc-access.jpg" alt="Ладонь на считывателе, доступ разрешён" loading="lazy" width="900" height="1125"></figure>
          <div class="svc__body">
            <p class="svc__desc">Биометрия и карты доступа, видеодомофоны, турникеты и шлагбаумы. Чужой не пройдёт, свои не ждут.</p>
            <p class="mini">Что входит</p>
            <ul class="checks">
              <li><?= $icon('check') ?>Установка вызывных панелей и мониторов</li>
              <li><?= $icon('check') ?>Электромагнитные и электромеханические замки</li>
              <li><?= $icon('check') ?>Биометрические и карточные считыватели</li>
              <li><?= $icon('check') ?>Учёт рабочего времени сотрудников</li>
              <li><?= $icon('check') ?>Установка турникетов и шлагбаумов</li>
            </ul>
            <p class="mini">Где ставим</p>
            <ul class="chips"><li>Коттедж</li><li>Бизнес-центр</li><li>Офис</li><li>Завод</li><li>Парковка</li><li>Закрытая территория</li></ul>
          </div>
        </div>
        <div class="svc__pan" role="tabpanel" id="pan-alarm" aria-labelledby="tab-alarm" hidden>
          <figure class="svc__img"><img src="<?= $bp ?>/assets/img/svc-alarm.jpg" alt="Прибор охранной сигнализации с клавиатурой на стене" loading="lazy" width="900" height="1125"></figure>
          <div class="svc__body">
            <p class="svc__desc">Защита периметра и помещений. Датчики замечают движение, открытую дверь или разбитое стекло, и вы сразу получаете сигнал.</p>
            <p class="mini">Что входит</p>
            <ul class="checks">
              <li><?= $icon('check') ?>Датчики движения</li>
              <li><?= $icon('check') ?>Датчики открытия дверей и окон</li>
              <li><?= $icon('check') ?>Датчики разбития стекла</li>
              <li><?= $icon('check') ?>Мгновенное оповещение о тревоге</li>
              <li><?= $icon('check') ?>Проверка и настройка системы</li>
            </ul>
            <p class="mini">Где ставим</p>
            <ul class="chips"><li>Частный дом</li><li>Квартира</li><li>Офис</li><li>Магазин</li><li>Склад</li></ul>
          </div>
        </div>
        <div class="svc__pan" role="tabpanel" id="pan-fire" aria-labelledby="tab-fire" hidden>
          <figure class="svc__img"><img src="<?= $bp ?>/assets/img/svc-fire.jpg" alt="Пожарный датчик дыма под потолком горит красным" loading="lazy" width="900" height="1125"></figure>
          <div class="svc__body">
            <p class="svc__desc">Датчики дыма и тепла замечают возгорание в самом начале. Прибор сразу поднимает тревогу и включает оповещение.</p>
            <p class="mini">Что входит</p>
            <ul class="checks">
              <li><?= $icon('check') ?>Подбор оборудования под объект</li>
              <li><?= $icon('check') ?>Дымовые и тепловые извещатели</li>
              <li><?= $icon('check') ?>Ручные пожарные извещатели</li>
              <li><?= $icon('check') ?>Приёмно-контрольный прибор</li>
              <li><?= $icon('check') ?>Прокладка линий, настройка и проверка</li>
            </ul>
            <p class="mini">Где ставим</p>
            <ul class="chips"><li>Офис</li><li>Магазин</li><li>Склад</li><li>Производство</li><li>Бизнес-центр</li></ul>
          </div>
        </div>
        <div class="svc__pan" role="tabpanel" id="pan-soue" aria-labelledby="tab-soue" hidden>
          <figure class="svc__img"><img src="<?= $bp ?>/assets/img/svc-soue.jpg" alt="Речевой оповещатель с красной подсветкой" loading="lazy" width="900" height="1125"></figure>
          <div class="svc__body">
            <p class="svc__desc">Сирены, речевые оповещатели и световые табло «Выход». При тревоге люди слышат сигнал и видят, куда идти.</p>
            <p class="mini">Что входит</p>
            <ul class="checks">
              <li><?= $icon('check') ?>Звуковые и речевые оповещатели</li>
              <li><?= $icon('check') ?>Световые табло «Выход»</li>
              <li><?= $icon('check') ?>Связь с пожарной сигнализацией</li>
              <li><?= $icon('check') ?>Прокладка линий оповещения</li>
              <li><?= $icon('check') ?>Проверка слышимости и настройка</li>
            </ul>
            <p class="mini">Где ставим</p>
            <ul class="chips"><li>Офис</li><li>Магазин</li><li>Склад</li><li>Производство</li><li>Бизнес-центр</li></ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Почему мы -->
<section class="section why" id="why" aria-labelledby="whyTitle">
  <div class="wrap">
    <header class="sec-head sec-head--center" data-reveal>
      <p class="kicker">Почему нам доверяют</p>
      <h2 class="h2" id="whyTitle">Ставим только то, что нужно вашему объекту</h2>
      <p class="lead">Сначала смотрим объект, потом предлагаем вариант под задачу и бюджет. Лишнего не продаём.</p>
    </header>
    <ul class="stats" data-reveal>
      <li><b class="count" data-to="500" data-suffix="+">500+</b><span>объектов в Нижегородской области</span></li>
      <li><b class="count" data-to="2" data-suffix=" года">2 года</b><span>гарантия на работы и оборудование</span></li>
      <li><b class="count" data-to="24" data-suffix=" ч">24 ч</b><span>до выезда инженера на объект</span></li>
      <li><b>1-3 дня</b><span>монтаж стандартной системы</span></li>
    </ul>
    <ul class="why__grid" data-reveal>
      <li class="tile">
        <svg class="iris" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" pathLength="100"/><circle cx="24" cy="24" r="13" pathLength="100"/><circle cx="24" cy="24" r="4.5"/></svg>
        <h3>Под ключ</h3>
        <p>Подбор, закупка, монтаж и настройка. Не нужно искать отдельно оборудование и мастеров.</p>
      </li>
      <li class="tile">
        <svg class="iris" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" pathLength="100"/><circle cx="24" cy="24" r="13" pathLength="100"/><circle cx="24" cy="24" r="4.5"/></svg>
        <h3>Проверенное оборудование</h3>
        <p>Ставим технику брендов, которым доверяем, например Dahua и TRASSIR.</p>
      </li>
      <li class="tile">
        <svg class="iris" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" pathLength="100"/><circle cx="24" cy="24" r="13" pathLength="100"/><circle cx="24" cy="24" r="4.5"/></svg>
        <h3>Гарантия и сервис</h3>
        <p>До 2 лет гарантии на работы и оборудование. После монтажа обслуживаем систему.</p>
      </li>
      <li class="tile">
        <svg class="iris" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" pathLength="100"/><circle cx="24" cy="24" r="13" pathLength="100"/><circle cx="24" cy="24" r="4.5"/></svg>
        <h3>Быстрые сроки</h3>
        <p>Инженер приезжает на оценку в течение суток. Стандартную систему монтируем за 1-3 дня.</p>
      </li>
    </ul>
  </div>
</section>

<!-- Калькулятор -->
<section class="section calc" id="calc" aria-labelledby="calcTitle">
  <div class="wrap">
    <header class="sec-head" data-reveal>
      <p class="kicker">Расчёт за минуту</p>
      <h2 class="h2" id="calcTitle">Сколько стоит ваша система</h2>
      <p class="lead">Четыре шага, и вы увидите примерную цену с оборудованием и монтажом. Точную смету составим после осмотра.</p>
    </header>
    <form class="calc__grid" id="calcForm" data-reveal onsubmit="return false">
      <div class="calc__steps">
        <fieldset class="step" data-step="obj">
          <legend><span class="step__n">1</span>Тип объекта</legend>
          <div class="opts">
            <?php foreach (CALC_OBJECTS as $k => $label): ?>
              <label class="opt"><input type="radio" name="obj" value="<?= $k ?>"<?= $k === 'house' ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset class="step" data-step="svc">
          <legend><span class="step__n">2</span>Что нужно</legend>
          <div class="opts opts--2">
            <label class="opt opt--check"><input type="checkbox" name="sys_cctv" checked><span><span class="opt__l">Камеры</span></span></label>
            <label class="opt opt--check"><input type="checkbox" name="sys_access"><span><span class="opt__l">СКУД и домофон</span></span></label>
            <?php foreach (['fire' => 'Пожарная сигнализация (АПС)', 'soue' => 'Оповещение (СОУЭ)'] as $sys => $sysLabel): $v = (int)($prices[$sys]['house'] ?? 0); ?>
              <label class="opt opt--check" data-sys="<?= $sys ?>"><input type="checkbox" name="sys_<?= $sys ?>"><span><span class="opt__l"><?= e($sysLabel) ?></span><em><?= $v > 0 ? 'от ' . number_format($v, 0, ',', ' ') . ' ₽' : 'по смете' ?></em></span></label>
            <?php endforeach; ?>
          </div>
          <p class="step__hint" id="calcHint">Камеры и СКУД вместе дешевле на <?= (int)$prices['both_discount'] ?>%</p>
        </fieldset>
        <fieldset class="step" data-step="pts">
          <legend><span class="step__n">3</span>Сколько камер или дверей</legend>
          <div class="opts opts--4">
            <?php foreach (CALC_POINTS as $i => $label): ?>
              <label class="opt"><input type="radio" name="pts" value="<?= $i ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset class="step" data-step="opt">
          <legend><span class="step__n">4</span>Дополнительно</legend>
          <div class="opts opts--col">
            <label class="opt opt--check"><input type="checkbox" name="remote" checked><span><span class="opt__l">Просмотр со смартфона</span><em>бесплатно</em></span></label>
            <label class="opt opt--check" data-cam><input type="checkbox" name="night"><span><span class="opt__l">Цветное ночное видение</span><em>+<?= number_format((int)$prices['night'], 0, ',', ' ') ?> ₽</em></span></label>
            <label class="opt opt--check" data-cam><input type="checkbox" name="archive"><span><span class="opt__l">Архив больше 30 дней</span><em>+<?= number_format((int)$prices['archive'], 0, ',', ' ') ?> ₽</em></span></label>
          </div>
        </fieldset>
      </div>
      <aside class="calc__res" aria-live="polite">
        <svg class="calc__iris" id="calcIris" viewBox="0 0 120 120" aria-hidden="true">
          <circle class="t" cx="60" cy="60" r="54"/>
          <circle class="v" id="calcRing" cx="60" cy="60" r="54" pathLength="100" transform="rotate(-90 60 60)"/>
          <g class="r2"><circle cx="60" cy="60" r="40" pathLength="100"/></g>
          <g class="r3"><circle cx="60" cy="60" r="27"/></g>
          <circle class="c" cx="60" cy="60" r="7"/>
        </svg>
        <p class="mini">Примерная стоимость</p>
        <p class="price"><span class="sr-only" id="priceSr">от 30 000 ₽</span><span aria-hidden="true" id="priceNum">от <span class="nf" id="nf">30 000</span> ₽</span><span aria-hidden="true" class="price__est" id="priceEst" hidden>по смете</span></p>
        <ul class="calc__lines" id="calcLines"></ul>
        <p class="calc__note">Оборудование среднего ценового сегмента и монтаж. Точная смета после осмотра объекта.</p>
        <button class="btn btn--primary btn--shine btn--block" type="button" id="calcGo">Записаться на замер</button>
      </aside>
    </form>
  </div>
</section>

<!-- Наши объекты -->
<section class="section works" id="works" aria-labelledby="worksTitle">
  <div class="wrap">
    <header class="sec-head" data-reveal>
      <p class="kicker">Наши объекты</p>
      <h2 class="h2" id="worksTitle">Что мы уже сделали</h2>
      <p class="lead">Реальные объекты по Нижегородской области: от частных домов до гипермаркета.</p>
    </header>
    <ul class="works__grid" id="worksGrid">
      <?php foreach ($works as $i => $w): ?>
        <li class="work" data-reveal style="--i:<?= $i ?>">
          <button type="button" class="work__btn" data-src="<?= e($w['url']) ?>" data-title="<?= e($w['title']) ?>" data-sub="<?= e($w['sub']) ?>">
            <img src="<?= e($w['url']) ?>" alt="<?= e($w['title'] . ': ' . $w['sub']) ?>" loading="lazy" decoding="async">
            <span class="work__cap"><b><?= e($w['title']) ?></b><span><?= e($w['sub']) ?></span></span>
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- Отзывы -->
<section class="section reviews" id="reviews" aria-labelledby="revTitle">
  <div class="wrap">
    <header class="sec-head sec-head--center" data-reveal>
      <p class="kicker">Отзывы</p>
      <h2 class="h2" id="revTitle">Что говорят клиенты</h2>
    </header>
  </div>
  <?php
    $rows = [$reviews, array_reverse($reviews)];
    foreach ($rows as $ri => $row): ?>
    <div class="marquee<?= $ri ? ' marquee--rev' : '' ?>">
      <?php for ($copy = 0; $copy < 2; $copy++): ?>
        <ul class="marquee__track"<?= ($copy || $ri) ? ' aria-hidden="true"' : '' ?>>
          <?php foreach ($row as $r): ?>
            <li class="review">
              <p>«<?= e($r['text']) ?>»</p>
              <footer><b><?= e($r['name']) ?></b><span><?= e($r['type']) ?></span></footer>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endfor; ?>
    </div>
  <?php endforeach; ?>
</section>

<!-- Вопросы -->
<section class="section faq" id="faq" aria-labelledby="faqTitle">
  <div class="wrap faq__wrap">
    <header class="sec-head" data-reveal>
      <p class="kicker">Вопросы</p>
      <h2 class="h2" id="faqTitle">Коротко о главном</h2>
      <p class="lead">Не нашли ответ? Позвоните: <a href="<?= e($tel) ?>"><?= e($phone) ?></a></p>
    </header>
    <div class="faq__list" data-reveal>
      <?php foreach ($faq as $i => $q): ?>
        <details class="qa"<?= $i === 0 ? ' open' : '' ?>>
          <summary><span><?= e($q['q']) ?></span><?= $icon('plus') ?></summary>
          <div class="qa__a"><p><?= e($q['a']) ?></p></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Контакты и форма -->
<section class="section contact" id="contact" aria-labelledby="contactTitle">
  <div class="wrap contact__grid">
    <div class="contact__info" data-reveal>
      <p class="kicker">Связаться</p>
      <h2 class="h2" id="contactTitle">Готовы обсудить ваш объект?</h2>
      <p class="lead">Оставьте заявку. Бесплатно проконсультируем и составим предварительную смету.</p>
      <a class="contact__phone" href="<?= e($tel) ?>"><?= e($phone) ?></a>
      <p class="contact__hours"><?= e($c['hours']) ?></p>
      <div class="contact__msg">
        <a class="btn btn--ghost" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= $icon('wa') ?>WhatsApp</a>
        <a class="btn btn--ghost" href="<?= e($tg) ?>" target="_blank" rel="noopener"><?= $icon('tg') ?>Telegram</a>
      </div>
    </div>

    <div class="formcard" data-reveal>
      <span class="spot" aria-hidden="true"></span>
      <form class="lead-form" id="leadForm" novalidate>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="hp" aria-hidden="true"><label>Сайт <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="calc-chip" id="calcChip" hidden>
          <span>Ваш расчёт: <b id="calcChipText"></b></span>
          <button type="button" id="calcChipX" aria-label="Убрать расчёт из заявки"><?= $icon('x') ?></button>
        </div>

        <div class="field">
          <label for="fName">Как к вам обращаться</label>
          <input id="fName" name="name" type="text" autocomplete="name" maxlength="60" required aria-describedby="eName">
          <p class="err" id="eName" role="alert"></p>
        </div>
        <div class="field">
          <label for="fPhone">Телефон</label>
          <input id="fPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 (___) ___-__-__" required aria-describedby="ePhone">
          <p class="err" id="ePhone" role="alert"></p>
        </div>
        <div class="field">
          <label for="fObj">Тип объекта</label>
          <select id="fObj" name="object_type">
            <option value="">Выберите, если знаете</option>
            <?php foreach (OBJECT_TYPES as $k => $label): ?>
              <option value="<?= $k ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="fComment">Комментарий</label>
          <textarea id="fComment" name="comment" rows="3" maxlength="1000" placeholder="Например: нужно видеть въезд и калитку"></textarea>
        </div>
        <div class="field field--check">
          <label class="check">
            <input type="checkbox" name="consent" id="fConsent" aria-describedby="eConsent">
            <span class="check__box" aria-hidden="true"><?= $icon('check') ?></span>
            <span>Я даю <a href="<?= page_url('consent') ?>" target="_blank">согласие на обработку персональных данных</a></span>
          </label>
          <p class="err" id="eConsent" role="alert"></p>
        </div>
        <button class="btn btn--primary btn--block btn--arrow" type="submit" id="leadSubmit"><span>Отправить заявку</span><?= $icon('arrow') ?></button>
        <p class="form-note">Перезвоним в рабочее время. Подробнее в <a href="<?= page_url('privacy') ?>" target="_blank">Политике обработки персональных данных</a>.</p>
      </form>

      <div class="form-state form-state--ok" id="formOk" hidden tabindex="-1">
        <svg class="iris iris--ok" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" pathLength="100"/><path d="M15 24.5l6 6 12-12" pathLength="100"/></svg>
        <h3>Заявка принята</h3>
        <p>Перезвоним <?= e(mb_strtolower($c['hours'])) ?>. Если срочно, звоните: <a href="<?= e($tel) ?>"><?= e($phone) ?></a></p>
      </div>
      <div class="form-state form-state--fail" id="formFail" hidden tabindex="-1">
        <h3 id="failTitle">Не удалось отправить</h3>
        <p id="failText">Отправьте ту же заявку в мессенджер, текст уже готов.</p>
        <div class="contact__msg">
          <a class="btn btn--primary" id="failWa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= $icon('wa') ?>WhatsApp</a>
          <a class="btn btn--ghost" id="failTg" href="<?= e($tg) ?>" target="_blank" rel="noopener"><?= $icon('tg') ?>Telegram</a>
        </div>
        <p class="form-note" id="failTgNote" hidden>Текст заявки скопирован. Вставьте его в чат.</p>
        <button class="linkbtn" type="button" id="failRetry">Попробовать ещё раз</button>
      </div>
    </div>
  </div>
</section>
</main>

<footer class="foot">
  <div class="wrap foot__grid">
    <div class="foot__brand">
      <a class="nav__logo" href="#top"><img src="<?= $bp ?>/assets/img/logo-160.png" alt="" width="32" height="36"><span class="nav__word">АЙРИС</span></a>
      <p>Видеонаблюдение, СКУД, охранная и пожарная сигнализация, оповещение. <?= e($c['region']) ?>.</p>
    </div>
    <div class="foot__col">
      <a class="foot__phone" href="<?= e($tel) ?>"><?= e($phone) ?></a>
      <p><?= e($c['hours']) ?></p>
      <p class="foot__msg"><a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a> · <a href="<?= e($tg) ?>" target="_blank" rel="noopener">Telegram</a></p>
    </div>
    <div class="foot__col">
      <a href="<?= page_url('privacy') ?>">Политика обработки персональных данных</a>
      <a href="<?= page_url('consent') ?>">Согласие на обработку персональных данных</a>
      <p class="foot__copy">© <?= date('Y') ?> АЙРИС</p>
    </div>
  </div>
</footer>

<nav class="mbar" id="mbar" aria-label="Быстрые действия">
  <a href="<?= e($tel) ?>"><?= $icon('phone') ?><span>Позвонить</span></a>
  <a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= $icon('wa') ?><span>WhatsApp</span></a>
  <a class="mbar__cta" href="#calc"><span>Расчёт</span></a>
</nav>

<div class="cookie" id="cookie" role="dialog" aria-live="polite" aria-label="Cookie и Яндекс Метрика" hidden>
  <p>Сайт использует cookie и Яндекс Метрику, чтобы понимать, чем он удобен. Метрика включится, только если вы согласны. <a href="<?= page_url('privacy') ?>">Подробнее</a></p>
  <div class="cookie__btns">
    <button class="btn btn--primary btn--sm" type="button" id="cookieYes">Согласен</button>
    <button class="btn btn--ghost btn--sm" type="button" id="cookieNo">Отказаться</button>
  </div>
</div>

<dialog class="lb" id="lb" aria-label="Фото объекта">
  <button class="lb__x" type="button" aria-label="Закрыть"><?= $icon('x') ?></button>
  <img id="lbImg" alt="">
  <p id="lbCap"></p>
</dialog>

<script src="<?= $bp ?>/assets/js/site.js?v=<?= asset_ver('assets/js/site.js') ?>" defer></script>
</body>
</html>
