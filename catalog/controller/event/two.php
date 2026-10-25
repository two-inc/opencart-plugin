<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Event;

use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * The order lifecycle off OpenCart's order history: after an order moves
 * into a status, fulfil or cancel its Two order as the status maps say.
 * Registered on `catalog/model/checkout/order.addHistory/after`
 * (Services::EVENT_TRIGGER). Admin status changes run the catalog's order
 * model in a store instance, so this sees them too.
 *
 * The status change has already been written: the outcome is added to the
 * order history at the same status, never by changing it back, and an
 * order the extension did not take is left alone.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';
    private const MODEL_ROUTE = 'checkout/order.addHistory';

    /** Set while this handler runs: its own history note goes through addHistory too. */
    private static bool $running = false;

    /**
     * @param array<int, mixed> $args addHistory's: order id, status id, comment, notify, override
     */
    public function history(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        // Also reachable as a URL route, with no arguments: only the model event acts.
        if (self::$running || $route !== self::MODEL_ROUTE || !is_numeric($args[0] ?? null)) {
            return;
        }
        $orderId = (int) $args[0];
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!$order || (string) ($order['payment_method']['code'] ?? '') !== 'two.two') {
            return;
        }
        $services = new Services($this->db, $this->log, (int) $order['store_id']);
        $statusId = (int) $order['order_status_id'];
        $action = OrderLifecycle::action($statusId, $services->settings->fulfilStatusIds(), $services->settings->cancelStatusId());
        if ($action === null) {
            return;
        }

        self::$running = true;
        try {
            $lifecycle = $services->orderLifecycle($this->event);
            if ($action === OrderLifecycle::FULFIL) {
                $this->load->model(self::ROUTE);
                $model = $this->model_extension_two_payment_two;
                $rebuild = static fn (array $record): ?array => $model->orderInput($orderId, [
                    'company_name' => $record['company_name'] ?? '',
                    'organization_number' => $record['organization_number'] ?? '',
                    'country_code' => $record['country_code'] ?? '',
                ], []);
                $result = $lifecycle->fulfil($orderId, $rebuild);
            } else {
                $result = $lifecycle->cancel($orderId);
            }
            if ($result['outcome'] !== OrderLifecycle::SKIPPED) {
                $this->note($orderId, $statusId, $result['reason']);
            }
        } catch (\Throwable $error) {
            $reason = 'The ' . $action . ' at Two failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . '.';
            $this->log->write('Two error: order ' . $orderId . ': ' . $reason . ' ' . $error->getMessage());
            $this->note($orderId, $statusId, $reason);
        } finally {
            self::$running = false;
        }
    }

    /** The outcome in the history at the status the merchant chose; override skips the fraud check a second time. */
    private function note(int $orderId, int $statusId, string $reason): void
    {
        $this->load->language(self::ROUTE);
        $this->model_checkout_order->addHistory($orderId, $statusId, $this->language->get('text_history_prefix') . $reason, false, true);
    }
}
