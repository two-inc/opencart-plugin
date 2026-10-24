<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\OrderRecords;

/**
 * The core's OrderRecords port over the plugin's own `two_order` table, one
 * InnoDB row per shop order id (Build Guide section 12.4).
 *
 * Writes are presence-conditional: only the keys given are written. A
 * column the table lacks (an upgrade whose schema change could not run) is
 * left out of the write rather than failing it, so the plugin only relies
 * on the columns it has confirmed exist.
 */
final class DbOrderRecords implements OrderRecords
{
    public const TABLE = 'two_order';

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(private readonly \Opencart\System\Library\DB $db)
    {
    }

    /** The table, for install() and configure.php. Idempotent. */
    public static function createTableSql(string $prefix): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE . '` ('
            . '`order_id` INT(11) NOT NULL,'
            . '`two_order_id` VARCHAR(64) NOT NULL DEFAULT \'\','
            . '`two_order_state` VARCHAR(32) NOT NULL DEFAULT \'\','
            . '`two_order_status` VARCHAR(32) NOT NULL DEFAULT \'\','
            . '`token_hash` CHAR(64) NOT NULL DEFAULT \'\','
            . '`company_name` VARCHAR(255) NOT NULL DEFAULT \'\','
            . '`organization_number` VARCHAR(64) NOT NULL DEFAULT \'\','
            . '`country_code` CHAR(2) NOT NULL DEFAULT \'\','
            . '`date_added` DATETIME NOT NULL,'
            . '`date_modified` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`order_id`),'
            . 'KEY `two_order_id` (`two_order_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    }

    public function get(int $orderId): ?array
    {
        $query = $this->db->query('SELECT * FROM `' . DB_PREFIX . self::TABLE . "` WHERE `order_id` = '" . $orderId . "'");
        if (!is_object($query) || $query->num_rows === 0) {
            return null;
        }
        $row = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $query->row)) {
                $row[$field] = (string) $query->row[$field];
            }
        }

        return $row;
    }

    public function put(int $orderId, array $fields): void
    {
        $columns = $this->columns();
        $sets = [];
        foreach ($fields as $field => $value) {
            if (in_array($field, self::FIELDS, true) && in_array($field, $columns, true)) {
                $sets[] = '`' . $field . "` = '" . $this->db->escape($value) . "'";
            }
        }
        if ($sets === []) {
            return;
        }
        $set = implode(', ', $sets);
        $this->db->query('INSERT INTO `' . DB_PREFIX . self::TABLE . "` SET `order_id` = '" . $orderId . "', " . $set . ', `date_added` = NOW(), `date_modified` = NOW()'
            . ' ON DUPLICATE KEY UPDATE ' . $set . ', `date_modified` = NOW()');
    }

    /** @return list<string> */
    private function columns(): array
    {
        if ($this->columns === null) {
            $this->columns = [];
            $query = $this->db->query('SHOW COLUMNS FROM `' . DB_PREFIX . self::TABLE . '`');
            foreach (is_object($query) ? $query->rows : [] as $row) {
                $this->columns[] = (string) $row['Field'];
            }
        }

        return $this->columns;
    }
}
