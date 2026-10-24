<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Cron;

use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The scheduled merchant-record refresh, run by OpenCart 4's native cron
 * (the shop's cron.php runs every due row of the cron table; the admin
 * install() registers this one daily). The cron runner always passes a
 * cron id; a direct web request to this route has none and does nothing,
 * so the public route cannot be used to make the shop call Two.
 *
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 */
class Two extends \Opencart\System\Engine\Controller
{
    public function index(int $cron_id = 0, string $code = '', string $cycle = '', string $date_added = '', string $date_modified = ''): void
    {
        if ($cron_id <= 0 || $code !== Services::CRON_CODE) {
            return;
        }

        (new Services($this->db, $this->log))->records->runScheduledRefresh();
    }
}
