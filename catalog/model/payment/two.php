<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Two\Payment;

/**
 * Checkout adapter for the Two payment method.
 *
 * OpenCart asks every enabled payment extension for its methods. Two is
 * withheld (an empty list) until the checkout phases wire the core's
 * Availability chain and order flow (rewrite plan P3 and P6), so enabling the
 * extension in P0 never offers a method that cannot complete an order.
 */
class Two extends \Opencart\System\Engine\Model
{
    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        return [];
    }
}
