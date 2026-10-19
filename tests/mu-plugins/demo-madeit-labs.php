<?php

/**
 * Point the demo store at a WCO Labs server.
 *
 * Production by default. To test against the local WCO Labs container instead, set
 * WCO_LABS_API_URL=http://wco-labs-web-1 in the WordPress container environment.
 */
define('WCO_LABS_API_URL', getenv('WCO_LABS_API_URL') ?: 'https://labs.made-it.pl');

add_filter('http_request_host_is_external', static fn ($external, $host) => $host === 'wco-labs-web-1' ? true : $external, 10, 2);
