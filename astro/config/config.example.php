<?php
// Copy to config/config.php and fill in. Never commit config.php.
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=jyotish;charset=utf8mb4',
        'user' => 'jyotish',
        'pass' => 'CHANGE_ME',
    ],
    'engine' => [
        'backend' => 'php',   // php = pure PHP (shared hosting) | binary = compiled engine (VPS)
        'data'    => __DIR__ . '/../data/ephem',
        'binary'  => __DIR__ . '/../engine/bin/astro_calc',
        'ephe'    => __DIR__ . '/../engine/ephe',
        'timeout' => 10, // seconds
    ],
    // Calculation settings. Changing any of these invalidates cached kundalis automatically.
    'calc' => [
        'ayanamsa' => 'lahiri',       // lahiri | raman | kp | true_chitra
        'node'     => 'mean',         // mean | true  (Rahu/Ketu)
        'rise'     => 'conventional', // conventional (upper limb + refraction) | hindu (disc centre, no refraction)
        'dasha_year_days' => 365.2425,
    ],
    'geo' => [
        'nominatim_url'     => 'https://nominatim.openstreetmap.org/search',
        'user_agent'        => 'JyotishApp/1.0 (contact@example.com)', // required by Nominatim policy
        'geonames_username' => '', // free account at geonames.org; needed for timezones outside India
    ],
    'auth' => ['token_ttl_days' => 30],
    'mail' => ['from' => 'no-reply@example.com'], // sender for password-reset emails (PHP mail())
    'app'  => ['base_url' => 'https://example.com', 'debug' => false, 'cors_origins' => ['*'], 'admins' => ['your-admin-email@example.com']], // admins can edit remedy rules at #/admin
];
