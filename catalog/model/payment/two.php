<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Opencart\OrderTax;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Checkout adapter for the Two payment method: whether the tile is offered
 * (the availability chain over the session's cart),
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
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Cart\Currency $currency
 * @property-read \Opencart\System\Library\Cart\Tax $tax
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read object{getTotals: \Closure} $model_checkout_cart the loader's proxy: getTotals is a closure, so its arguments pass by reference
 */
class Two extends \Opencart\System\Engine\Model
{
    /** Tax classes of the charge totals the shop's own extensions add, by total code. */
    private const CHARGE_TAX_CLASS_KEYS = ['handling' => 'total_handling_tax_class_id', 'low_order_fee' => 'total_low_order_fee_tax_class_id', 'two_fee' => 'payment_two_surcharge_tax_class_id'];

    /** How long a charged fee quote is reused across requests while nothing it depends on changes. */
    public const FEE_CACHE_SECONDS = 60;

    /** Set while the cart's gross without the fee is read, so the fee total adds nothing. */
    private static bool $feeSuspended = false;

    private ?Services $services = null;

    /** Whether this request already logged why Two was withheld: OpenCart asks for the methods more than once per render. */
    private static bool $withholdLogged = false;

    /**
     * The tile, when the availability chain (Build Guide section 18) offers
     * Two for this cart. A withheld tile is silent for the buyer; the log
     * names the reason once per request.
     *
     * @param array<string, mixed> $address the payment address OpenCart passes, empty when it collects none
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        $services = $this->services();
        $withheld = $services->availability->withholdReason($this->checkoutFacts($address), $this->converter(), fn (): ?string => $this->feeFailure($address));
        if ($withheld !== null) {
            if (!self::$withholdLogged) {
                self::$withholdLogged = true;
                $this->log->write('Two: payment method hidden (' . $withheld['reason'] . '): ' . $withheld['detail']);
            }

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
     * The cart as the availability chain reads it. OpenCart keeps every
     * amount in the shop's default currency and shows the session's, so the
     * gate checks the session currency and the minimum judges the default.
     *
     * @param array<string, mixed> $address
     * @return array{enabled: bool, currency: string, shop_currency: string, gross: float, net: float, buyer_country: string|null, value_currency: string}
     */
    public function checkoutFacts(array $address = []): array
    {
        [$total, $taxes] = $this->totalsWithoutFee();
        $shop = (string) $this->config->get('config_currency');

        return [
            'enabled' => (bool) $this->config->get('payment_two_status'),
            'currency' => (string) ($this->session->data['currency'] ?? $shop),
            'shop_currency' => $shop,
            'gross' => (float) $total,
            'net' => (float) $total - (float) array_sum($taxes),
            'buyer_country' => $this->buyerCountry($address),
            'value_currency' => $shop,
        ];
    }

    /**
     * The buyer's country: the payment address, else the shipping address.
     *
     * @param array<string, mixed> $address
     */
    public function buyerCountry(array $address = []): ?string
    {
        foreach ([$address, $this->session->data['payment_address'] ?? [], $this->session->data['shipping_address'] ?? []] as $candidate) {
            $code = is_array($candidate) ? strtoupper(trim((string) ($candidate['iso_code_2'] ?? ''))) : '';
            if ($code === '' && is_array($candidate) && (int) ($candidate['country_id'] ?? 0) > 0) {
                $row = $this->db->query('SELECT `iso_code_2` FROM `' . DB_PREFIX . "country` WHERE `country_id` = '" . (int) $candidate['country_id'] . "'")->row;
                $code = strtoupper(trim((string) ($row['iso_code_2'] ?? '')));
            }
            if (preg_match('~^[A-Z]{2}\z~', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Converts at Two's own FX rates, the ones Two judges the minimum with,
     * or null when Two's table holds no rate for one of the two currencies.
     *
     * @return \Closure(float, string, string): ?float
     */
    public function converter(): \Closure
    {
        $fx = $this->services()->fx;

        return static fn (float $amount, string $from, string $to): ?float => $fx->convert($amount, $from, $to);
    }

    /** Whether the fee total must add nothing: the cart's gross without the fee is being read. */
    public static function feeSuspended(): bool
    {
        return self::$feeSuspended;
    }

    /**
     * The fee line for the cart, when Two is the chosen method and its term
     * prices one: the title, and the net in the shop's default currency (as
     * OpenCart keeps every total). Null when no fee applies, or when it
     * cannot be quoted: the method list then withholds Two, and placement
     * refuses an order whose fee is missing.
     *
     * @return array{title: string, value: float, tax_class_id: int}|null
     */
    public function feeTotal(): ?array
    {
        if (self::$feeSuspended || ($this->session->data['payment_method']['code'] ?? '') !== 'two.two') {
            return null;
        }
        $fee = $this->cartFee();
        $value = (float) $this->currency->getValue($this->sessionCurrency());
        if ($fee['net'] === null || $fee['net'] <= 0 || $fee['days'] === null || $value <= 0) {
            return null;
        }
        $this->load->language('extension/two/payment/two');
        $services = $this->services();

        return [
            'title' => $services->surcharge()->label($fee['days'], $services->paymentTerms()->isEndOfMonth(), $this->language->get('text_fee_line'), $this->language->get('text_fee_line_eom')),
            'value' => $fee['net'] / $value,
            'tax_class_id' => $services->surcharge()->taxClassId,
        ];
    }

    /**
     * The fee the cart is charged (FeeQuote::charged) with the term it is
     * for, and why it cannot be quoted when it cannot. The term is the
     * buyer's choice, else the one stored with the order being edited, else
     * the default. A quote is reused for FEE_CACHE_SECONDS while its request
     * is unchanged, so a checkout's many renders ask Two once.
     *
     * @param array<string, mixed> $address
     * @return array{applies: bool, days: int|null, net: float|null, failure: string}
     */
    public function cartFee(array $address = []): array
    {
        $services = $this->services();
        $terms = $services->paymentTerms();
        $surcharge = $services->surcharge();
        $currency = $this->sessionCurrency();
        $shop = (string) $this->config->get('config_currency');
        $gross = $this->grossWithoutFee();
        $country = $this->buyerCountry($address) ?? '';
        $chosen = $this->chosenTerm();
        $days = $terms->selected($chosen);
        $payload = $days !== null && $surcharge->isKnown() && $surcharge->enabled() && $gross > 0 ? $services->fees->payload($surcharge, $terms, $days, $gross, $country, $currency, $shop) : null;
        $key = $payload === null ? '' : hash('sha256', (string) json_encode($payload));
        $cached = $this->session->data['two_fee_quote'] ?? null;
        if ($key !== '' && is_array($cached) && ($cached['key'] ?? '') === $key && (int) ($cached['at'] ?? 0) > time() - self::FEE_CACHE_SECONDS && $surcharge->pricesFee($days, $terms->defaultTerm(), $terms->isEndOfMonth())) {
            return ['applies' => true, 'days' => $days, 'net' => (float) $cached['net'], 'failure' => ''];
        }
        $charged = $services->fees->charged($surcharge, $terms, $chosen, $gross, $country, $currency, $shop);
        if ($key !== '' && $charged['net'] !== null) {
            $this->session->data['two_fee_quote'] = ['key' => $key, 'net' => $charged['net'], 'at' => time()];
        }

        return $charged + ['failure' => $services->fees->failure];
    }

    /**
     * The fee-quote gate (Build Guide section 18): why the cart cannot be
     * offered Two on the fee's account, or null.
     *
     * @param array<string, mixed> $address
     */
    public function feeFailure(array $address = []): ?string
    {
        $services = $this->services();
        $currencyFailure = $services->fees->currencyFailure($services->surcharge(), $services->paymentTerms(), $this->sessionCurrency(), (string) $this->config->get('config_currency'));
        if ($currencyFailure !== null) {
            return $currencyFailure;
        }
        $fee = $this->cartFee($address);

        return $fee['applies'] && $fee['net'] === null ? $fee['failure'] : null;
    }

    /**
     * Placement's term and fee check: why the session's order may not be
     * placed on its term, or null. Refused when the merchant record offers
     * no term, when the buyer's term is one it does not offer, when the fee
     * cannot be quoted, and when the stored order's fee is not the fee for
     * the term (the order was written before the buyer changed term).
     *
     * @return array{reason: string, detail: string}|null
     */
    public function placementTermRefusal(int $orderId): ?array
    {
        $terms = $this->services()->paymentTerms();
        if ($terms->offered() === []) {
            return ['reason' => 'terms', 'detail' => 'the merchant record offers no payment term'];
        }
        $chosen = $this->chosenTerm();
        if ($chosen !== null && !$terms->offers($chosen)) {
            return ['reason' => 'terms', 'detail' => 'the chosen ' . $chosen . '-day term is not one the merchant record offers'];
        }
        $fee = $this->cartFee();
        if ($fee['applies'] && $fee['net'] === null) {
            return ['reason' => 'fee', 'detail' => $fee['failure']];
        }
        $stored = 0.0;
        foreach ($this->db->query('SELECT `value` FROM `' . DB_PREFIX . "order_total` WHERE `order_id` = '" . $orderId . "' AND `code` = 'two_fee'")->rows as $row) {
            $stored += (float) $row['value'];
        }
        $expected = $fee['applies'] ? (int) round((float) $fee['net'] * 100) : 0;
        $value = (float) $this->currency->getValue($this->sessionCurrency());
        if ((int) round($stored * $value * 100) !== $expected) {
            return ['reason' => 'fee_changed', 'detail' => 'the order\'s payment terms fee is not the fee for the ' . (int) $fee['days'] . '-day term'];
        }

        return null;
    }

    /**
     * The `terms` block for the session's order, or null when no term is offered.
     *
     * @return array{type: string, duration_days: int, duration_days_calculated_from?: string}|null
     */
    public function termsBlock(): ?array
    {
        $terms = $this->services()->paymentTerms();
        $days = $terms->selected($this->chosenTerm());

        return $days === null ? null : PaymentTerms::block($days, $terms->isEndOfMonth());
    }

    /** The buyer's chosen term from the session, else the term stored with the order being edited, else null. */
    public function chosenTerm(): ?int
    {
        $chosen = (int) ($this->session->data['two_payment_term'] ?? 0);
        if ($chosen > 0) {
            return $chosen;
        }
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $stored = $orderId > 0 ? PaymentTerms::decode((string) ($this->services()->orders->get($orderId)['payment_terms'] ?? '')) : null;

        return $stored['duration_days'] ?? null;
    }

    /** The cart's gross without the fee, in the session currency at the shop's own currency value (the factor the order is sent at). */
    public function grossWithoutFee(): float
    {
        [$total] = $this->totalsWithoutFee();

        return round($total * (float) $this->currency->getValue($this->sessionCurrency()), 2);
    }

    public function sessionCurrency(): string
    {
        return (string) ($this->session->data['currency'] ?? $this->config->get('config_currency'));
    }

    /**
     * The cart's total and taxes as OpenCart computes them, without the fee.
     *
     * @return array{0: float, 1: array<int, float>}
     */
    private function totalsWithoutFee(): array
    {
        $this->load->model('checkout/cart');
        $totals = [];
        $taxes = [];
        $total = 0;
        $suspended = self::$feeSuspended;
        self::$feeSuspended = true;
        try {
            ($this->model_checkout_cart->getTotals)($totals, $taxes, $total);
        } finally {
            self::$feeSuspended = $suspended;
        }

        return [(float) $total, $taxes];
    }

    private function services(): Services
    {
        return $this->services ??= new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
    }

    /** The address columns the order keeps for each role, hashed as stored. */
    private const ADDRESS_FIELDS = ['firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country_id', 'zone_id'];

    /**
     * The order's own rows, as stored: its products (price, tax and
     * quantity), its totals, and its billing and shipping addresses. Nothing
     * from the catalogue or the tax setup, so only an edit of the order
     * itself changes them. Null when there is no such order.
     *
     * @return array{products: list<array<string, mixed>>, totals: list<array<string, mixed>>, addresses: array<string, mixed>}|null
     */
    public function orderRows(int $orderId): ?array
    {
        $order = $this->db->query('SELECT * FROM `' . DB_PREFIX . "order` WHERE `order_id` = '" . $orderId . "'")->row;
        if (!$order) {
            return null;
        }
        $addresses = [];
        foreach (['payment', 'shipping'] as $role) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $addresses[$role . '_' . $field] = $order[$role . '_' . $field] ?? '';
            }
        }

        return [
            'products' => array_values($this->db->query('SELECT `product_id`, `name`, `model`, `quantity`, `price`, `total`, `tax` FROM `' . DB_PREFIX . "order_product` WHERE `order_id` = '" . $orderId . "'")->rows),
            'totals' => array_values($this->db->query('SELECT `code`, `title`, `value` FROM `' . DB_PREFIX . "order_total` WHERE `order_id` = '" . $orderId . "'")->rows),
            'addresses' => $addresses,
        ];
    }

    /**
     * The stored order as OrderBuilder's inputs, or null when there is no
     * such order.
     *
     * @param array{company_name: string, organization_number: string, country_code: string} $company
     * @param array<string, string>                                                           $urls
     * @param array<string, string>                                                           $buyerFields the optional buyer fields (BuyerFields)
     * @param array<string, mixed>|null                                                       $terms the payment terms block the order is booked on: null for the session's, empty for none (an order placed before terms were kept)
     * @return array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context: array<string, mixed>}|null
     */
    public function orderInput(int $orderId, array $company, array $urls, array $buyerFields = [], ?array $terms = null): ?array
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
                'buyer_fields' => $buyerFields,
                'terms' => $terms ?? $this->termsBlock(),
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
