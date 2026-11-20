<?php
// Settings you may want to change. Every one has a safe default, so the site works without touching this file.

// FORCE_HTTPS: sends everyone who opens the site with http:// to the secure https:// address, so logins and
// passwords cannot be read on public Wi-Fi.
//   ->  Turn it on (true) ONLY AFTER your hosting has an SSL certificate and https://your-site already opens in the browser.
//   ->  If the site ever stops loading after you turn it on, set it back to false and upload this file again.
//   ->  Testing on your own computer (localhost, 127.0.0.1, 192.168.x.x) is never redirected, so XAMPP keeps working.
const FORCE_HTTPS = false;

// SITE_URL (optional): only needed if you want to force one fixed address for link previews, for example
// when the site sits behind a proxy. Normally leave it commented out; the address is found automatically.
// const SITE_URL = 'https://your-domain.com';
