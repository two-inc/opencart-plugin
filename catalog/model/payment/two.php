<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Opencart\OrderTax;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Checkout adapter for the Two payment method: whether the tile is offered,
 * and the shop's stored order mapped into OrderBuilder's neutral shapes.
 *
 * Every amount is read from the order's own rows (order, order_product,
 * order_total). OpenCart stores tax per product line and per rate for the
 * order, but none for a charge total such as shipping: there the tax is
 * what the shop's own tax engine gives the stored tax class at the order's
 * addresses and customer group (OrderTax, never the request's session),
 * the computation OpenCart itself made at checkout. The default
 * handler's shop-match check then refuses the order unless the lines add up
 * to the tax and total the shop actually charged, so nothing is invented.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 */
class Two extends \Opencart\System\Engine\Model
{
    /** Tax classes of the charge totals the shop's own extensions add, by total code. */
    private const CHARGE_TAX_CLASS_KEYS = ['handling' => 'total_handling_tax_class_id', 'low_order_fee' => 'total_low_order_fee_tax_class_id'];

    /**
     * The tile, offered while the extension is enabled with an API key. The
     * full availability chain (Build Guide section 18) lands in P6.
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        if (!$this->config->get('payment_two_status')) {
            return [];
        }
        $services = new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
        if ($services->settings->apiKey() === '') {
            return [];
        }
        // OpenCart 4.1 renders method names unescaped, and the title is stored as typed.
        $title = htmlspecialchars($services->settings->title((int) $this->config->get('config_language_id')), ENT_QUOTES, 'UTF-8');

        return [
            'code' => 'two',
            'name' => $title,
            'option' => ['two' => ['code' => 'two.two', 'name' => $title]],
            'sort_order' => $this->config->get('payment_two_sort_order'),
        ];
    }

    /**
     * The stored order as OrderBuilder's inputs, or null when there is no
     * such order.
     *
     * @param array{company_name: string, organization_number: string, country_code: string} $company
     * @param array<string, string>                                                           $urls
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context: array<string, mixed>}|null
     */
    public function orderInput(int $orderId, array $company, array $urls): ?array
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!$order) {
            return null;
        }
        $tax = OrderTax::engine($this->db, OrderTax::scope($order, OrderTax::storeSettings($this->db, (int) $order['store_id'])));

        $lines = [];
        foreach ($this->model_checkout_order->getProducts($orderId) as $row) {
            $product = $this->db->query('SELECT `tax_class_id`, `shipping` FROM `' . DB_PREFIX . "product` WHERE `product_id` = '" . (int) $row['product_id'] . "'")->row;
            $lines[] = [
                'name' => (string) $row['name'],
                'quantity' => (int) $row['quantity'],
                'price' => (float) $row['price'],
                'total' => (float) $row['total'],
                'tax' => (float) $row['tax'],
                'type' => !isset($product['shipping']) || $product['shipping'] ? 'PHYSICAL' : 'DIGITAL',
            ] + OrderTax::rateOf($tax, (float) $row['price'], (int) ($product['tax_class_id'] ?? 0));
        }

        $taxTotal = 0.0;
        $totals = [];
        foreach ($this->model_checkout_order->getTotals($orderId) as $row) {
            $total = ['code' => (string) $row['code'], 'title' => (string) $row['title'], 'value' => (float) $row['value']];
            if ($total['code'] === 'tax') {
                $taxTotal += $total['value'];
            }
            $class = $this->chargeTaxClass($total['code'], $order);
            if ($class !== null) {
                $rate = OrderTax::rateOf($tax, $total['value'], $class);
                $total += $rate + ['tax' => $class > 0 ? $tax->getTax($total['value'], $class) : 0.0];
            }
            $totals[] = $total;
        }

        $shipping = $order['shipping_method'] ? $this->address($order, 'shipping') : null;
        $method = is_array($order['shipping_method']) ? $order['shipping_method'] : [];
        // Section 21.3: what the shop's shipping tax setting (here the shipping method's
        // tax class) applies at the order's addresses, whether or not the line was taxed.
        $shippingClass = (int) ($method['tax_class_id'] ?? 0);
        $shippingRate = $shippingClass > 0 ? OrderTax::rateOf($tax, (float) ($method['cost'] ?? 0), $shippingClass)['rate'] : null;

        return [
            'order' => [
                'order_id' => $orderId,
                'currency' => (string) $order['currency_code'],
                'currency_value' => (float) $order['currency_value'],
                'total' => (float) $order['total'],
                'tax_total' => $taxTotal,
                'date_added' => (string) $order['date_added'],
                'comment' => (string) $order['comment'],
                'carrier_name' => (string) ($method['name'] ?? ''),
                'company' => $company,
                'buyer' => [
                    'email' => (string) $order['email'],
                    'first_name' => (string) $order['firstname'],
                    'last_name' => (string) $order['lastname'],
                    'phone_number' => (string) $order['telephone'],
                ],
                'billing_address' => $this->address($order, 'payment'),
                'shipping_address' => $shipping,
                'merchant_urls' => $urls,
            ],
            'lines' => $lines,
            'totals' => $totals,
            'context' => ['order' => $order, 'shipping_tax_rate' => $shippingRate],
        ];
    }

    /**
     * The tax class of a charge total, or null for a total that is not one.
     *
     * @param array<string, mixed> $order
     */
    private function chargeTaxClass(string $code, array $order): ?int
    {
        if ($code === 'shipping') {
            return (int) (is_array($order['shipping_method']) ? ($order['shipping_method']['tax_class_id'] ?? 0) : 0);
        }
        if (isset(self::CHARGE_TAX_CLASS_KEYS[$code])) {
            return (int) $this->config->get(self::CHARGE_TAX_CLASS_KEYS[$code]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    private function address(array $order, string $role): array
    {
        $country = $this->db->query('SELECT `iso_code_2` FROM `' . DB_PREFIX . "country` WHERE `country_id` = '" . (int) $order[$role . '_country_id'] . "'")->row;

        return [
            'street_address' => trim($order[$role . '_address_1'] . ' ' . $order[$role . '_address_2']),
            'postal_code' => (string) $order[$role . '_postcode'],
            'city' => (string) $order[$role . '_city'],
            'region' => (string) $order[$role . '_zone'],
            'country' => (string) ($country['iso_code_2'] ?? ''),
        ];
    }
}
