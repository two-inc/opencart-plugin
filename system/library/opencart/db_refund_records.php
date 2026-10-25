<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\RefundRecords;

/**
 * The core's RefundRecords port over the plugin's own `two_refund` table:
 * one InnoDB row per refund sent to Two, its auto-increment id the durable
 * id a partial refund's idempotency key is built from.
 */
final class DbRefundRecords implements RefundRecords
{
    public const TABLE = 'two_refund';

    public function __construct(private readonly \Opencart\System\Library\DB $db)
    {
    }

    /**
     * Creates the table, for install() and configure.php. Idempotent.
     *
     * @param \Closure(string): mixed $query runs one statement
     */
    public static function install(\Closure $query, string $prefix): void
    {
        $query(self::createTableSql($prefix));
    }

    public static function createTableSql(string $prefix): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE . '` ('
            . '`refund_id` INT(11) NOT NULL AUTO_INCREMENT,'
            . '`order_id` INT(11) NOT NULL,'
            . '`two_order_id` VARCHAR(64) NOT NULL DEFAULT \'\','
            . '`kind` VARCHAR(16) NOT NULL DEFAULT \'\','
            . '`amount` VARCHAR(32) NOT NULL DEFAULT \'\','
            . '`currency` VARCHAR(3) NOT NULL DEFAULT \'\','
            . '`payload` MEDIUMTEXT NOT NULL,'
            . '`idempotency_key` VARCHAR(100) NOT NULL DEFAULT \'\','
            . '`state` VARCHAR(16) NOT NULL DEFAULT \'\','
            . '`reason` TEXT NOT NULL,'
            . '`date_added` DATETIME NOT NULL,'
            . '`date_modified` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`refund_id`),'
            . 'KEY `order_id` (`order_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    }

    public function create(int $orderId, array $fields): int
    {
        $sets = ["`order_id` = '" . $orderId . "'"];
        foreach (self::only($fields) + ['payload' => '', 'reason' => ''] as $field => $value) {
            $sets[] = '`' . $field . "` = '" . $this->db->escape($value) . "'";
        }
        $this->db->query('INSERT INTO `' . DB_PREFIX . self::TABLE . '` SET ' . implode(', ', $sets) . ', `date_added` = NOW(), `date_modified` = NOW()');

        return $this->db->getLastId();
    }

    public function update(int $refundId, array $fields): void
    {
        $sets = [];
        foreach (self::only($fields) as $field => $value) {
            $sets[] = '`' . $field . "` = '" . $this->db->escape($value) . "'";
        }
        if ($sets !== []) {
            $this->db->query('UPDATE `' . DB_PREFIX . self::TABLE . '` SET ' . implode(', ', $sets) . ", `date_modified` = NOW() WHERE `refund_id` = '" . $refundId . "'");
        }
    }

    public function forOrder(int $orderId): array
    {
        $query = $this->db->query('SELECT * FROM `' . DB_PREFIX . self::TABLE . "` WHERE `order_id` = '" . $orderId . "' ORDER BY `refund_id`");
        $rows = [];
        foreach (is_object($query) ? $query->rows : [] as $row) {
            $out = ['refund_id' => (string) $row['refund_id'], 'date_added' => (string) $row['date_added']];
            foreach (self::FIELDS as $field) {
                $out[$field] = (string) ($row[$field] ?? '');
            }
            $rows[] = $out;
        }

        return $rows;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private static function only(array $fields): array
    {
        return array_intersect_key($fields, array_flip(self::FIELDS));
    }
}
