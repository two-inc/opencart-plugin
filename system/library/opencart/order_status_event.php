<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;

/**
 * What the order history event does once an order has moved into a status:
 * fulfil or cancel its Two order as the status maps say. The controller
 * (`catalog/controller/event/two.php`) passes the shop in as closures, so
 * this runs offline in the specs.
 *
 * - Orders paid another way, and unplaced orders (status 0 or the shop's
 *   void status), are left alone: a history line written on an unplaced
 *   order, such as a refused confirm's note, never cancels a live Two order.
 * - The status change has already been written. The outcome is added to the
 *   history at the same status, never by changing it back.
 * - That note goes through addHistory too, so the handler does not re-enter
 *   itself while it runs.
 * - Anything thrown is caught, logged and noted: a failure at Two never
 *   breaks the merchant's status change.
 */
final class OrderStatusEvent
{
    public const PAYMENT_CODE = 'two.two';

    private static bool $running = false;

    /**
     * @param \Closure(int): (array<string, mixed>|null)                                                                $readOrder    the shop order, as OpenCart's getOrder gives it
     * @param \Closure(array<string, mixed>): array{lifecycle: OrderLifecycle, fulfil: list<int>, cancel: int|null}     $lifecycleFor the lifecycle and status maps of the order's store
     * @param \Closure(int): (array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>}|null) $rows the order's own rows
     * @param \Closure(int, int, string): void                                                                          $note         adds a history line at a status
     * @param \Closure(string): void                                                                                    $logError
     */
    public function __construct(
        private readonly \Closure $readOrder,
        private readonly \Closure $lifecycleFor,
        private readonly \Closure $rows,
        private readonly \Closure $note,
        private readonly \Closure $logError,
        private readonly int $voidStatusId,
    ) {
    }

    public function handle(int $orderId): void
    {
        if (self::$running) {
            return;
        }
        $order = ($this->readOrder)($orderId);
        if (!$order || (string) ($order['payment_method']['code'] ?? '') !== self::PAYMENT_CODE) {
            return;
        }
        $statusId = (int) $order['order_status_id'];
        if ($statusId === 0 || $statusId === $this->voidStatusId) {
            return;
        }

        self::$running = true;
        $action = null;
        try {
            $setup = ($this->lifecycleFor)($order);
            $action = OrderLifecycle::action($statusId, $setup['fulfil'], $setup['cancel']);
            if ($action === null) {
                return;
            }
            $result = $action === OrderLifecycle::FULFIL
                ? $setup['lifecycle']->fulfil($orderId, ($this->rows)($orderId))
                : $setup['lifecycle']->cancel($orderId);
            if ($result['outcome'] !== OrderLifecycle::SKIPPED) {
                ($this->note)($orderId, $statusId, $result['reason']);
            }
        } catch (\Throwable $error) {
            $reason = 'The ' . ($action ?? 'status') . ' at Two failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . '.';
            ($this->logError)('Two error: order ' . $orderId . ': ' . $reason . ' ' . $error->getMessage());
            ($this->note)($orderId, $statusId, $reason);
        } finally {
            self::$running = false;
        }
    }
}
