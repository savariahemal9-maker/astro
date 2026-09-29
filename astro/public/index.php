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
<meta name="theme-color" content="#0f4c3a">
<meta name="color-scheme" content="light">
<title>GrahaSetu — Vedic Kundali &amp; Panchang</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48' aria-hidden='true'><defs><linearGradient id='lg' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='%23f3c969'/><stop offset='1' stop-color='%23c8892a'/></linearGradient></defs><circle cx='24' cy='24' r='22' fill='%23221a5c'/><ellipse cx='24' cy='24' rx='17' ry='7' fill='none' stroke='url(%23lg)' stroke-width='1.6' transform='rotate(-28 24 24)'/><path d='M24 11l2.6 8.1 8.4.2-6.7 5 2.4 8.1-6.7-4.9-6.7 4.9 2.4-8.1-6.7-5 8.4-.2z' fill='url(%23lg)'/><circle cx='38' cy='15' r='2.2' fill='%23f3c969'/></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Hind+Vadodara:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,500,1,0&display=block" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars($root) ?>/public/assets/app.css?v=<?= $v('app.css') ?>">
</head>
<body>
<div id="progress" aria-hidden="true"></div>
<header class="topbar">
  <button class="icon-btn" id="railToggle" aria-label="Menu"><span class="ms">menu</span></button>
  <a class="brand" href="#/dashboard"><svg class="logo" viewBox="0 0 48 48" aria-hidden="true"><defs><linearGradient id="lg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f2c75c"/><stop offset="1" stop-color="#c9952b"/></linearGradient></defs><circle cx="24" cy="24" r="22" fill="#0f4c3a"/><ellipse cx="24" cy="24" rx="17" ry="7" fill="none" stroke="url(#lg)" stroke-width="1.6" transform="rotate(-28 24 24)"/><path d="M24 11l2.6 8.1 8.4.2-6.7 5 2.4 8.1-6.7-4.9-6.7 4.9 2.4-8.1-6.7-5 8.4-.2z" fill="url(#lg)"/><circle cx="38" cy="15" r="2.2" fill="#f2c75c"/></svg><span data-t="ui.app_name">GrahaSetu</span></a>
  <span class="grow"></span>
  <select id="lang" aria-label="Language"><option value="en">English</option><option value="hi">हिन्दी</option><option value="gu">ગુજરાતી</option></select>
</header>
<nav id="rail" class="rail" aria-label="Main"></nav>
<main id="app" tabindex="-1"></main>
<nav id="bnav" class="bnav" aria-label="Main"></nav>
<div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<div id="dialog" class="dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><p id="dialogMsg"></p><div class="row end"><button class="ghost" data-d="0"></button><button data-d="1"></button></div></div></div>
<script>window.API_BASE = <?= json_encode($root . '/api/v1') ?>; window.ADMIN_APP = <?= defined('ADMIN_APP') ? 'true' : 'false' ?>;</script>
<script src="<?= htmlspecialchars($root) ?>/public/assets/app.js?v=<?= $v('app.js') ?>"></script>
</body>
</html>
