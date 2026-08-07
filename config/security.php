<?php

$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$trustedHosts = explode(',', (string) env('TRUSTED_HOSTS', $appHost ?: 'localhost'));

return [
    'trusted_hosts' => array_values(array_map(
        fn (string $host): string => '^'.preg_quote(trim($host), '/').'$',
        array_filter($trustedHosts, fn (string $host): bool => trim($host) !== ''),
    )),
];
