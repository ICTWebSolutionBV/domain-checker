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
        'whois_server_ttl' => 86400, // 24 hours — IANA's TLD -> WHOIS server map
        'unknown_ttl'   => 60,      // a non-answer is worth retrying soon
    ],

    'realtime_register' => [
        'api_key' => env('REALTIME_REGISTER_API_KEY', ''),
        'host'    => env('REALTIME_REGISTER_HOST', 'is.yoursrs.com'),
        'port'    => env('REALTIME_REGISTER_PORT', 2001),
    ],

    'timeouts' => [
        'rdap'              => 5,
        'whois'             => 8,
        'realtime_register' => 10,  // includes TLS handshake + batch
    ],

    /*
     * TLDs per RDAP batch. One batch shares one connection pool, so everything
     * in it can reuse a connection to a registry that serves several TLDs
     * (Verisign answers .com, .net, .cc, ...). It was 10, which meant 5
     * sequential rounds for the 46 popular TLDs and 129 for the full IANA list,
     * each round paying for the slowest of its ten requests and fresh TLS
     * handshakes. The only reason not to send the whole list as one batch is
     * that the framework sorts the completed batch by request order at the end,
     * which is quadratic in the batch size.
     */
    'batch_size' => (int) env('DOMAIN_CHECKER_RDAP_BATCH_SIZE', 250),

    /*
     * How many lookups may be in flight at once, within a batch.
     *
     * RDAP is plain HTTPS, so it takes the higher ceiling; WHOIS is a raw
     * port-43 socket per TLD, so it takes a lower one.
     */
    'concurrency' => [
        'rdap'  => (int) env('DOMAIN_CHECKER_RDAP_CONCURRENCY', 64),
        'whois' => (int) env('DOMAIN_CHECKER_WHOIS_CONCURRENCY', 24),
    ],

    /*
     * Wall-clock budget for one WHOIS wave. A registry that trickles a byte at a
     * time just under the per-read timeout can otherwise keep a socket -- and
     * the request -- alive indefinitely.
     */
    'whois_wave_budget' => (int) env('DOMAIN_CHECKER_WHOIS_BUDGET', 30),

    /* Largest WHOIS response we will read before giving up on the socket. */
    'whois_max_response' => 65536,

    /*
     * IsProxy read discipline: a per-read timeout so a missing reply cannot park
     * the request in fgets(), and a budget for the whole batch.
     */
    'realtime_register_read_timeout' => 5,
    'realtime_register_budget' => 60,
];
