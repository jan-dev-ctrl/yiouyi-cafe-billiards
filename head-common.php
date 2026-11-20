<?php
// Shared <head> tags for EVERY page: browser-tab icon, phone home-screen icon, app manifest, browser colour.
// Pages that must not appear in Google (login, receipts, admin, ...) also get "noindex".
// The landing page sets  $SEO_INDEX = true;  before including this file, so it stays searchable.
?>
<link rel="icon" href="favicon.ico" sizes="any"/>
<link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32.png"/>
<link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16.png"/>
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png"/>
<link rel="manifest" href="site.webmanifest"/>
<meta name="theme-color" content="#9f3c16"/>
<?php if (empty($SEO_INDEX)): ?><meta name="robots" content="noindex, nofollow"/>
<?php endif; ?>
