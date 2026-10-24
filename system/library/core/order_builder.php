<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Builds Two order payloads from the shop's stored order (Build Guide
 * sections 5.4, 6.3 and 21.4): real net, tax and rate per line from the
 * shop's own tax rows, shipping on its tax class, discount and voucher lines,
 * tax subtotals, and a deterministic result for the same input. Intent and
 * order create share it. The adapter maps its rows into the neutral shapes
 * below; this class never reads the shop.
 *
 * Behaviour lands in P3.
 */
final class OrderBuilder
{
    public function __construct(private readonly Postprocessing $postprocessing)
    {
    }

    /**
     * @param array<string, mixed>       $order  order header: ids, currency, buyer, addresses
     * @param list<array<string, mixed>> $lines  product lines with stored net, tax and rate
     * @param list<array<string, mixed>> $totals order totals: shipping, discounts, vouchers, fees
     * @return array<string, mixed>
     */
    public function build(array $order, array $lines, array $totals): array
    {
        throw NotImplemented::inPhase('OrderBuilder::build', 'P3');
    }
}
