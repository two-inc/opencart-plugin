<?php

declare(strict_types=1);

/**
 * Installs and enables the Two extension in an OpenCart 4 shop. Idempotent:
 * every step checks before it writes, so running it again changes nothing
 * unless the inputs changed.
 *
 * Callers:
 * - the staging shops, which deploy this repo to extension/two and then run
 *   `php <checkout>/dev/configure.php` after every new commit;
 * - `make install` and `make configure` in the local docker shop.
 *
 * It does what Extensions > Installer and Extensions > Payments > Install do,
 * straight against the database, because neither step has a CLI entry point:
 * the extension_install and extension rows, the admin permissions for the
 * extension's route, and the payment_two settings (status forced on).
 *
 * Environment:
 *   OPENCART_DIR     shop root holding config.php (default /var/www/html)
 *   TWO_ENVIRONMENT  production, sandbox or staging; stored when set
 *   TWO_API_KEY      merchant API key; PLUGIN_DEMO_STORE_API_KEY is the
 *                    fallback name the staging shops provide. Stored when set,
 *                    never printed.
 */

use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

// The staging shops deploy the whole repo under the web root, so refuse to
// run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/autoload.php';

const TWO_CODE = 'two';
const TWO_ROUTE = 'extension/two/payment/two';
const TWO_SETTING_CODE = 'payment_two';

function say(string $message): void
{
    echo '[configure] ' . $message . "\n";
}

function fail(string $message): never
{
    fwrite(STDERR, '[configure] ' . $message . "\n");
    exit(1);
}

/**
 * One prepared statement, every parameter bound as a string.
 *
 * @param list<string> $params
 * @return list<array<string, mixed>> the rows of a SELECT, empty otherwise
 */
function query(mysqli $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    if ($params !== []) {
        $statement->bind_param(str_repeat('s', count($params)), ...$params);
    }
    $statement->execute();
    $result = $statement->get_result();

    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
}

$shop = rtrim(getenv('OPENCART_DIR') ?: '/var/www/html', '/');
if (!is_file($shop . '/config.php')) {
    fail('no OpenCart config.php in ' . $shop . ' (set OPENCART_DIR)');
}
require $shop . '/config.php';
if (!defined('DB_DATABASE') || !defined('DIR_EXTENSION')) {
    fail('OpenCart is not installed yet in ' . $shop);
}

$manifestFile = DIR_EXTENSION . TWO_CODE . '/install.json';
$manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
if (!is_array($manifest)) {
    fail('the extension is not deployed: no readable ' . $manifestFile);
}
/** @var array<string, string> $manifest */
$manifest += ['name' => 'Two', 'description' => '', 'version' => '', 'author' => '', 'link' => ''];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$db->set_charset('utf8mb4');
$p = DB_PREFIX;

// 1. The installed-extension record (Extensions > Installer).
$install = query($db, "SELECT `extension_install_id` FROM `{$p}extension_install` WHERE `code` = ?", [TWO_CODE]);
$details = [$manifest['name'], $manifest['description'], $manifest['version'], $manifest['author'], $manifest['link']];
if ($install === []) {
    query($db, "INSERT INTO `{$p}extension_install` SET `extension_id` = 0, `extension_download_id` = 0, `name` = ?, `description` = ?, `version` = ?, `author` = ?, `link` = ?, `code` = ?, `status` = 1, `date_added` = NOW()", [...$details, TWO_CODE]);
    say('registered extension ' . TWO_CODE . ' ' . $manifest['version']);
} else {
    query($db, "UPDATE `{$p}extension_install` SET `name` = ?, `description` = ?, `version` = ?, `author` = ?, `link` = ?, `status` = 1 WHERE `code` = ?", [...$details, TWO_CODE]);
}

// 2. The payment method (Extensions > Payments > Install).
if (query($db, "SELECT `extension_id` FROM `{$p}extension` WHERE `type` = 'payment' AND `code` = ?", [TWO_CODE]) === []) {
    query($db, "INSERT INTO `{$p}extension` SET `extension` = ?, `type` = 'payment', `code` = ?", [TWO_CODE, TWO_CODE]);
    say('installed payment method ' . TWO_CODE);
}

// 3. Admin permissions, for every user group allowed to manage payment extensions.
foreach (query($db, "SELECT `user_group_id`, `permission` FROM `{$p}user_group`") as $group) {
    $permission = json_decode((string) $group['permission'], true);
    $permission = is_array($permission) ? $permission : [];
    if (!in_array('extension/payment', $permission['modify'] ?? [], true)) {
        continue;
    }
    $changed = false;
    foreach (['access', 'modify'] as $type) {
        if (!in_array(TWO_ROUTE, $permission[$type] ?? [], true)) {
            $permission[$type][] = TWO_ROUTE;
            $changed = true;
        }
    }
    if ($changed) {
        query($db, "UPDATE `{$p}user_group` SET `permission` = ? WHERE `user_group_id` = ?", [(string) json_encode($permission), (string) $group['user_group_id']]);
        say('granted ' . TWO_ROUTE . ' to user group ' . $group['user_group_id']);
    }
}

// 4. Settings: defaults only where nothing is stored, then the given inputs.
$defaults = ['sort_order' => '0'] + SettingsProvider::DEFAULTS;
$given = ['status' => '1'];
$environment = (string) getenv('TWO_ENVIRONMENT');
if ($environment !== '') {
    $known = [Environment::PRODUCTION, Environment::SANDBOX, Environment::STAGING];
    $given['environment'] = in_array($environment, $known, true) ? $environment : Environment::DEFAULT;
}
$apiKey = (string) (getenv('TWO_API_KEY') ?: getenv('PLUGIN_DEMO_STORE_API_KEY'));
if ($apiKey !== '') {
    $given['api_key'] = $apiKey;
}

foreach ($given + $defaults as $key => $value) {
    $settingKey = TWO_SETTING_CODE . '_' . $key;
    $stored = query($db, "SELECT `value` FROM `{$p}setting` WHERE `store_id` = 0 AND `code` = ? AND `key` = ?", [TWO_SETTING_CODE, $settingKey]);
    if ($stored === []) {
        query($db, "INSERT INTO `{$p}setting` SET `store_id` = 0, `code` = ?, `key` = ?, `value` = ?, `serialized` = 0", [TWO_SETTING_CODE, $settingKey, $value]);
    } elseif (isset($given[$key]) && $stored[0]['value'] !== $value) {
        query($db, "UPDATE `{$p}setting` SET `value` = ? WHERE `store_id` = 0 AND `code` = ? AND `key` = ?", [$value, TWO_SETTING_CODE, $settingKey]);
    } else {
        continue;
    }
    say('set ' . $settingKey . ($key === 'api_key' ? '' : ' = ' . $value));
}

say('extension ' . TWO_CODE . ' ' . $manifest['version'] . ' installed and enabled');
