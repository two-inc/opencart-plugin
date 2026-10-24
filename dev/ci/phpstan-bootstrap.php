<?php

declare(strict_types=1);

// Constants a shop's config.php defines at runtime, declared here so PHPStan
// can type the code that reads them (dev/configure.php). Analysis only.
define('DIR_EXTENSION', '/var/www/html/extension/');
define('DB_HOSTNAME', 'localhost');
define('DB_USERNAME', 'opencart');
define('DB_PASSWORD', '');
define('DB_DATABASE', 'opencart');
define('DB_PORT', '3306');
define('DB_PREFIX', 'oc_');
