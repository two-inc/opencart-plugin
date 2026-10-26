<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\RateLimiter;

/**
 * The per-caller rate-limit counts (RateLimiter::CALLER_LIMITS) in the
 * shop's database: one row per hashed caller and action. install() and
 * configure.php create the table.
 */
final class DbRateLimits
{
    public const TABLE = 'two_rate_limit';

    public function __construct(private readonly \Opencart\System\Library\DB $db)
    {
    }

    public static function createTableSql(string $prefix): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE . '` ('
            . '`rate_key` CHAR(64) NOT NULL,'
            . '`window_start` INT(11) UNSIGNED NOT NULL,'
            . '`hit_count` INT(11) UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`rate_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    }

    /** Counts one request by this caller; whether it is within the action's limit. */
    public function allow(string $action, string $address, int $now): bool
    {
        $key = RateLimiter::key($action, $address);
        $query = $this->db->query('SELECT `window_start`, `hit_count` FROM `' . DB_PREFIX . self::TABLE . "` WHERE `rate_key` = '" . $this->db->escape($key) . "'");
        $row = is_object($query) && $query->num_rows ? ['window_start' => (int) $query->row['window_start'], 'hit_count' => (int) $query->row['hit_count']] : null;
        $hit = RateLimiter::hit($action, $row, $now);
        $this->db->query('REPLACE INTO `' . DB_PREFIX . self::TABLE . "` (`rate_key`, `window_start`, `hit_count`) VALUES ('" . $this->db->escape($key) . "', " . $hit['window_start'] . ', ' . $hit['hit_count'] . ')');

        return $hit['allowed'];
    }
}
