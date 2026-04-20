<?php

return [
    'popular_tlds' => [
        'nl', 'com', 'be', 'de', 'net', 'org', 'io', 'co', 'eu', 'app',
        'dev', 'ai', 'shop', 'online', 'store', 'tech', 'info', 'biz', 'me',
        'us', 'uk', 'fr', 'es', 'it', 'ca', 'au', 'nz', 'ch', 'at', 'se',
        'no', 'dk', 'fi', 'pl', 'cz', 'pt', 'ie', 'website', 'site',
        'studio', 'agency', 'digital', 'cloud', 'media', 'news', 'blog',
    ],

    'rdap_bootstrap_url' => 'https://data.iana.org/rdap/dns.json',
    'iana_tld_list_url' => 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt',

    'cache' => [
        'bootstrap_ttl' => 86400,   // 24 hours
        'tld_list_ttl'  => 86400,   // 24 hours
        'result_ttl'    => 900,     // 15 minutes
    ],

    'realtime_register' => [
        'api_key'  => env('REALTIME_REGISTER_API_KEY', ''),
        'base_url' => env('REALTIME_REGISTER_URL', 'https://api.yoursrs.com'),
    ],

    'timeouts' => [
        'rdap'              => 5,
        'whois'             => 8,
        'realtime_register' => 5,
    ],

    'batch_size' => 10,
];
