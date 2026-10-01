<?php
// Website shell. All data comes from the same /api/v1 used by the mobile app.
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$root = preg_replace('#/(public|admin)$#', '', $base);
$v = fn($f) => @filemtime(__DIR__ . '/assets/' . $f) ?: time(); // cache-busting
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#e8710a">
<meta name="color-scheme" content="light">
<title>KarmYog Astro · Vastu — Kundali, Rashifal &amp; Panchang</title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($root) ?>/public/assets/brand/favicon.png">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($root) ?>/public/assets/brand/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Hind+Vadodara:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,500,1,0&display=block" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($root) ?>/public/assets/app.css?v=<?= $v('app.css') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($root) ?>/public/assets/design.css?v=<?= $v('design.css') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($root) ?>/public/assets/m3.css?v=<?= $v('m3.css') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($root) ?>/public/assets/trad.css?v=<?= $v('trad.css') ?>">
</head>
<body>
<div id="progress" aria-hidden="true"></div>
<div class="tstrip" id="tstrip"><span class="ms">wb_sunny</span><span id="tsText"></span></div>
<header class="topbar appbar">
  <button class="icon-btn nav-menu" id="railToggle" aria-label="Menu"><span class="ms">menu</span></button>
  <a class="brand" href="#/home" aria-label="KarmYog Astro Vastu"><img class="brand-logo" src="<?= htmlspecialchars($root) ?>/public/assets/brand/logo-wide.webp" alt="KarmYog Astro · Vastu" width="200" height="100"></a>
  <nav id="rail" class="topnav" aria-label="Main"></nav>
  <span class="grow"></span>
  <label class="lang-pick"><span class="ms">translate</span><select id="lang" aria-label="Language"><option value="en">English</option><option value="hi">हिन्दी</option><option value="gu">ગુજરાતી</option></select></label>
  <div id="acct" class="acct"></div>
</header>
<aside id="drawer" class="drawer" aria-label="Menu"></aside><div class="scrim" id="scrim"></div>
<main id="app" tabindex="-1"></main>
<footer class="sfoot" id="sfoot"></footer>
<nav id="bnav" class="bnav" aria-label="Main"></nav>
<div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<div id="dialog" class="dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><p id="dialogMsg"></p><div class="row end"><button class="ghost" data-d="0"></button><button data-d="1"></button></div></div></div>
<script>window.API_BASE = <?= json_encode($root . '/api/v1') ?>; window.ADMIN_APP = <?= defined('ADMIN_APP') ? 'true' : 'false' ?>;</script>
<script src="<?= htmlspecialchars($root) ?>/public/assets/app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
