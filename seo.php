<?php
// How the site shows up in Google results, Facebook / Messenger / Viber link previews, browser tabs and phone home screens.
//   site_url()   - the address of this site (works on any domain or sub-folder, nothing to configure)
//   seo_head()   - title, description, share preview (Open Graph / Twitter) and Google business information for the landing page
//   Every OTHER page is marked "noindex" by includes/head-common.php so logins, receipts, the admin area etc. never show up in search.
//
// To change the wording, edit the SEO_* values below. Optional: if you ever want to force one address
// (for example behind a proxy), set SITE_URL in includes/settings.php.

const SEO_NAME        = 'Yiouyi Cafe & Billiards';
const SEO_ALT_NAME    = 'YiouYi Fresh Tea & Coffee - Sta. Maria';
const SEO_TITLE       = 'Yiouyi Cafe & Billiards • Sta. Maria, Bulacan';
const SEO_DESCRIPTION = 'Coffee, milk tea, rice meals and pastries plus billiards. Open daily 11 AM to 12 MN on Santa Maria Bypass Rd, Bulacan. Order and reserve a table online.';
const SEO_TAGLINE     = 'Creating memories one sip at a time';
const SEO_PHONE       = '+63 44 230 2313';
const SEO_SAME_AS     = [
    'https://www.facebook.com/share/1FPajkepMF/',
    'https://www.tiktok.com/@yiouyi.ph',
    'https://yiouyiphilippines.com',
];

/** "https://your-domain.com" or "https://your-domain.com/folder"  (no trailing slash) */
function site_url(): string {
    if (defined('SITE_URL') && SITE_URL !== '') {
        return rtrim((string)SITE_URL, '/');
    }
    $ok = '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?(:\d{1,5})?$/';           // a real host name; anything odd in the Host header is ignored
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match($ok, $host)) {
        $host = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
        if (!preg_match($ok, $host)) { $host = 'localhost'; }
    }
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = preg_replace('~[^A-Za-z0-9/_.\-]~', '', $dir);
    $dir = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
    return (function_exists('is_https') && is_https() ? 'https' : 'http') . '://' . $host . $dir;
}

/** Print everything search engines and link previews look for. Call it inside <head> of the landing page. */
function seo_head(): void {
    $base  = site_url();
    $home  = $base . '/';
    $image = $base . '/photos/share.jpg';
    $title = SEO_TITLE; $desc = SEO_DESCRIPTION;
    $esc   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    echo '<title>' . $esc($title) . '</title>', "\n";
    echo '<meta name="description" content="' . $esc($desc) . '"/>', "\n";
    echo '<link rel="canonical" href="' . $esc($home) . '"/>', "\n";
    echo '<meta property="og:type" content="website"/>', "\n";
    echo '<meta property="og:site_name" content="' . $esc(SEO_NAME) . '"/>', "\n";
    echo '<meta property="og:title" content="' . $esc($title) . '"/>', "\n";
    echo '<meta property="og:description" content="' . $esc($desc) . '"/>', "\n";
    echo '<meta property="og:url" content="' . $esc($home) . '"/>', "\n";
    echo '<meta property="og:image" content="' . $esc($image) . '"/>', "\n";
    echo '<meta property="og:image:width" content="1200"/>', "\n", '<meta property="og:image:height" content="630"/>', "\n";
    echo '<meta property="og:image:alt" content="' . $esc('Seating at ' . SEO_NAME . ' in Sta. Maria, Bulacan') . '"/>', "\n";
    echo '<meta property="og:locale" content="en_PH"/>', "\n";
    echo '<meta name="twitter:card" content="summary_large_image"/>', "\n";
    echo '<meta name="twitter:title" content="' . $esc($title) . '"/>', "\n";
    echo '<meta name="twitter:description" content="' . $esc($desc) . '"/>', "\n";
    echo '<meta name="twitter:image" content="' . $esc($image) . '"/>', "\n";

    // Google business information (name, address, phone, hours) so search can show them
    $data = [
        '@context'      => 'https://schema.org',
        '@type'         => ['CafeOrCoffeeShop', 'SportsActivityLocation'],
        '@id'           => $home . '#business',
        'name'          => SEO_NAME,
        'alternateName' => SEO_ALT_NAME,
        'description'   => $desc,
        'slogan'        => SEO_TAGLINE,
        'url'           => $home,
        'logo'          => $base . '/logo.png',
        'image'         => [$image, $base . '/photos/seating.jpg', $base . '/photos/coffee-bar.jpg'],
        'telephone'     => SEO_PHONE,
        'address'       => [
            '@type' => 'PostalAddress', 'streetAddress' => 'Santa Maria Bypass Rd', 'addressLocality' => 'Santa Maria',
            'addressRegion' => 'Bulacan', 'postalCode' => '3022', 'addressCountry' => 'PH',
        ],
        'openingHoursSpecification' => [[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'opens' => '11:00', 'closes' => '23:59',
        ]],
        'hasMap'            => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(SEO_ALT_NAME . ', Santa Maria Bypass Rd, Santa Maria, 3022 Bulacan'),
        'sameAs'            => SEO_SAME_AS,
        'currenciesAccepted' => 'PHP',
        'paymentAccepted'   => 'Cash, GCash',
    ];
    echo '<script type="application/ld+json">',
         json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
         '</script>', "\n";
}
