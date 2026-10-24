<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: the plugin's own order-scoped store (Build Guide section 12.4), one
 * row per shop order id, holding what later requests need after the checkout
 * session is gone: the Two order id and state, the callback token's hash and
 * the company the buyer entered.
 */
interface OrderRecords
{
    public const FIELDS = ['two_order_id', 'two_order_state', 'two_order_status', 'token_hash', 'company_name', 'organization_number', 'country_code'];

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
