<?php

declare(strict_types=1);

/**
 * Sets the E2E shop up for the specs, after dev/configure.php: run inside
 * the shop container by tests/e2e/bin/prepare.sh. Idempotent.
 *
 * - Drops the demo data's fixed-amount tax (Eco Tax) from its tax classes.
 *   A Two line carries one percentage rate, so the extension refuses an
 *   order with a fixed-amount rate; the specs check out with UK VAT alone.
 */

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = rtrim((string) getenv('OPENCART_DIR'), '/');
if ($dir === '' || !is_file($dir . '/config.php')) {
    fwrite(STDERR, "[seed] OPENCART_DIR does not hold an installed OpenCart\n");
    exit(1);
}
require $dir . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$prefix = DB_PREFIX;

$db->query("DELETE tr FROM `{$prefix}tax_rule` tr JOIN `{$prefix}tax_rate` r ON r.tax_rate_id = tr.tax_rate_id WHERE r.type = 'F'");
echo '[seed] removed ' . $db->affected_rows . " fixed-amount tax rule(s)\n";
