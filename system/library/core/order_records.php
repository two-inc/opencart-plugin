<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: the plugin's own order-scoped store (Build Guide section 12.4), one
 * row per shop order id, holding what later requests need after the checkout
 * session is gone: the Two order id and state, the callback token's hash, the create payload's hash,
 * the company the buyer entered and the hash of the order's own rows as confirmed.
 */
interface OrderRecords
{
    public const FIELDS = ['two_order_id', 'two_order_state', 'two_order_status', 'token_hash', 'payload_hash', 'company_name', 'organization_number', 'country_code', 'rows_hash'];

    /** Column widths of the buyer's fields: longer values are refused at entry, never truncated. */
    public const MAX_LENGTHS = ['company_name' => 255, 'organization_number' => 64, 'country_code' => 2];

    /**
     * The row for a shop order, or null when the plugin has none.
     *
     * @return array<string, string>|null
     */
    public function get(int $orderId): ?array;

    /**
     * Presence-conditional write: only the keys given are written, so an
     * absent key leaves the stored value unchanged. Creates the row when
     * there is none.
     *
     * @param array<string, string> $fields keys from FIELDS
     */
    public function put(int $orderId, array $fields): void;
}
