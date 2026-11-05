<?php
declare(strict_types=1);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#176353">
    <title>QREX | Finished Good Inspection</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="vendor/css/tabler-icons.min.css">
    <link rel="stylesheet" href="app.css?v=74">
    <link rel="stylesheet" href="inspection-layout-overrides.css?v=70">
    <link rel="stylesheet" href="recent-inspections-overrides.css?v=63">
    <link rel="stylesheet" href="overview.css?v=1"><link rel="stylesheet" href="sidebar.css?v=3">
    <link rel="stylesheet" href="inspection-print.css?v=1">
    <script src="app.js?v=87" defer></script>
    <script src="recent-inspections-overrides.js?v=69" defer></script>
    <script src="overview.js?v=2" defer></script>
    <script src="inspection.js?v=67" defer></script>
    <script src="inspection-layout-overrides.js?v=68" defer></script>
    <script src="management.js?v=74" defer></script>
    <script src="final-overrides.js?v=27" defer></script>
    <script src="efficiency-overrides.js?v=6" defer></script>
    <script src="inspection-print.js?v=1" defer></script>
    <script src="events.js?v=80" defer></script>
</head>
<body>
    <a href="#main" class="skip">Skip to workspace</a>
    <div id="app"><div class="startup" role="status">Opening quality workspace...</div></div>
    <dialog id="dialog" aria-labelledby="dialog-title"></dialog>
    <div id="toast" role="status" aria-live="polite"></div>
</body>
</html>
