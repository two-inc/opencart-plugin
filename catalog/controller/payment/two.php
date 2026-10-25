<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Storefront adapter for the Two payment method: the tile with manual
 * company entry, placing the order (create the Two order, send the buyer to
 * Two's hosted checkout), and the confirm and cancel callbacks Two sends the
 * buyer back to. The rules live in the core's OrderFlow.
 *
 * The order stays unplaced (status 0, OpenCart's missing order, or the void
 * status OpenCart 4.1.0.0 sets on every checkout edit) until Two confirms it, as OpenCart's own redirect payment methods leave it: moving
 * an order off status 0 is what sends the buyer the order confirmation.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\Url $url
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 * @property-read \Opencart\System\Library\Cart\Customer $customer
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\Catalog\Model\Checkout\Order $model_checkout_order
 * @property-read \Opencart\Catalog\Model\Localisation\Country $model_localisation_country
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';
    private const TOKEN_PATTERN = '~^[0-9a-f]{48}\z~';
    /** How long a callback waits for another hit on the same order to finish. */
    private const LOCK_SECONDS = 30;

    /** The named lock this request holds, released before every redirect (Response::redirect exits, so a finally never runs). */
    private ?string $lock = null;

    /** The tile under the payment step: company fields and the confirm button. */
    public function index(): string
    {
        $this->load->language(self::ROUTE);
        $this->load->model('localisation/country');
        $countries = [];
        foreach ($this->model_localisation_country->getCountries() as $country) {
            if ((string) $country['iso_code_2'] !== '') {
                $countries[] = ['code' => (string) $country['iso_code_2'], 'name' => (string) $country['name']];
            }
        }
        $address = $this->session->data['payment_address'] ?? $this->session->data['shipping_address'] ?? [];

        return $this->load->view(self::ROUTE, [
            'language' => $this->config->get('config_language'),
            'countries' => $countries,
            'country' => (string) ($address['iso_code_2'] ?? ''),
            'company_name' => (string) ($address['company'] ?? ''),
            'brand' => Brand::default()->productName,
        ]);
    }

    /** Places the order: creates the Two order and answers with Two's payment URL. */
    public function confirm(): void
    {
        $this->load->language(self::ROUTE);
        $json = [];
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $this->load->model('checkout/order');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : [];
        if (!$order || !$this->unplaced($order)) {
            $json['error'] = $this->language->get('error_order');
        } elseif (($this->session->data['payment_method']['code'] ?? '') !== 'two.two') {
            $json['error'] = $this->language->get('error_payment_method');
        }

        if (!$json) {
            $post = fn (string $key): string => trim(html_entity_decode((string) ($this->request->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'));
            $country = strtoupper($post('two_country'));
            $company = [
                'company_name' => $post('two_company_name'),
                'organization_number' => $post('two_organization_number'),
                'country_code' => preg_match('~^[A-Z]{2}\z~', $country) === 1 ? $country : '',
            ];
            $token = OrderFlow::newToken();
            $urls = [
                'merchant_confirmation_url' => $this->callbackUrl(OrderFlow::CALLBACK_CONFIRM, $orderId, $token, false),
                'merchant_cancel_order_url' => $this->callbackUrl(OrderFlow::CALLBACK_CANCEL, $orderId, $token, false),
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ];
            $this->load->model(self::ROUTE);
            $input = $this->model_extension_two_payment_two->orderInput($orderId, $company, $urls);
            $result = $input === null
                ? ['ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'reason' => 'The order could not be read.']
                : $this->services()->orderFlow($this->event)->create($input['order'], $input['lines'], $input['totals'], $token, $input['context']);
            if ($result['ok']) {
                $json['redirect'] = $result['redirect'];
            } else {
                if ($result['buyer'] !== [ErrorMapper::COMPANY_REQUIRED]) {
                    $this->note($orderId, $result['reason']);
                }
                $json['error'] = $this->buyerMessage($result['buyer']);
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    /** Two sent the buyer back after verifying: confirm, then the shop's success page. */
    public function confirmation(): void
    {
        $this->callback(OrderFlow::CALLBACK_CONFIRM);
    }

    /** Two sent the buyer back after cancelling: cancel, then back to the cart with a notice. */
    public function cancel(): void
    {
        $this->callback(OrderFlow::CALLBACK_CANCEL);
    }

    private function callback(string $kind): void
    {
        $this->load->language(self::ROUTE);
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $token = (string) ($this->request->get['token'] ?? '');
        if ($orderId <= 0 || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $this->leave('cart', 'generic');

            return;
        }
        if ($this->bounce($kind, $orderId, $token)) {
            return;
        }
        // The token first, so a request that does not own the order never holds
        // its lock. Not logged: anyone can send one, so it would only fill the log.
        if (!$this->services()->orderFlow($this->event)->owns($orderId, $token)) {
            $this->leave('cart', 'generic');

            return;
        }
        // One callback per order at a time, so the status read before a write
        // cannot go stale under a concurrent hit (the bounce page's link and its
        // refresh). A guarded UPDATE cannot do this: OpenCart's addHistory must
        // see the old status itself, since the order mail and stock keep off it.
        $lock = 'two_order_' . substr(sha1(DB_DATABASE . DB_PREFIX), 0, 12) . '_' . $orderId;
        $held = (int) ($this->db->query("SELECT GET_LOCK('" . $lock . "', " . self::LOCK_SECONDS . ") AS `held`")->row['held'] ?? 0) === 1;
        if (!$held) {
            // Another hit on this order is still talking to Two and may yet place it.
            $this->load->model('checkout/order');
            $order = $this->model_checkout_order->getOrder($orderId);
            $after = OrderFlow::afterLockTimeout($order && !$this->unplaced($order));
            $this->leave($after['goto'], $after['notice']);

            return;
        }
        $this->lock = $lock;
        try {
            $this->settle($kind, $orderId, $token);
        } finally {
            // Only reached when settle() throws: every normal path releases in leave().
            $this->releaseLock();
        }
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            $this->db->query("SELECT RELEASE_LOCK('" . $this->lock . "')");
            $this->lock = null;
        }
    }

    private function settle(string $kind, int $orderId, string $token): void
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($orderId);
        if (!$order) {
            $this->leave('cart', 'generic');

            return;
        }
        $flow = $this->services()->orderFlow($this->event);

        if ($kind === OrderFlow::CALLBACK_CANCEL) {
            $owner = (int) ($this->session->data['order_id'] ?? 0) === $orderId
                || ($this->customer->isLogged() && (int) $order['customer_id'] === $this->customer->getId());
            if (!$owner || !$this->unplaced($order)) {
                $reason = 'Cancel callback refused: ' . ($owner ? 'the order is already placed.' : 'it did not come from the buyer\'s session.');
                $this->log->write('Two error: order ' . $orderId . ': ' . $reason);
                $this->note($orderId, $reason);
                $this->leave('cart', 'generic');

                return;
            }
            $result = $flow->cancel($orderId, $token);
        } elseif (!$this->unplaced($order) && $flow->owns($orderId, $token)) {
            // A second hit (the bounce page's link and its refresh) on the order the first hit placed.
            $this->leave('success', null);

            return;
        } else {
            $this->load->model(self::ROUTE);
            $model = $this->model_extension_two_payment_two;
            $rebuild = static fn (array $record): ?array => $model->orderInput($orderId, [
                'company_name' => $record['company_name'] ?? '',
                'organization_number' => $record['organization_number'] ?? '',
                'country_code' => $record['country_code'] ?? '',
            ], []);
            $result = $flow->confirm($orderId, $token, $rebuild, $model->orderRows($orderId));
        }

        if ($result['outcome'] === OrderFlow::UNAUTHORISED) {
            $this->log->write('Two error: ' . $kind . ' callback for order ' . $orderId . ': ' . $result['reason']);
        }
        // Read again before any write, under the lock.
        $order = $this->model_checkout_order->getOrder($orderId);
        $after = OrderFlow::afterCallback($kind, $result['outcome'], $order && !$this->unplaced($order));
        if ($after['place']) {
            $this->model_checkout_order->addHistory($orderId, (int) $this->config->get('config_order_status_id'), $this->language->get('text_history_prefix') . $result['reason']);
        } elseif ($after['note']) {
            $this->note($orderId, $result['reason']);
        }
        $this->leave($after['goto'], $after['notice']);
    }

    /**
     * Not yet placed: status 0, or the shop's void status, which OpenCart
     * 4.1.0.0 sets on every checkout edit of an unplaced order.
     *
     * @param array<string, mixed> $order
     */
    private function unplaced(array $order): bool
    {
        $status = (int) $order['order_status_id'];

        return $status === 0 || $status === (int) $this->config->get('config_void_status_id');
    }

    /** The reason in the order history of an unplaced order, its status unchanged. Placed orders are left alone. */
    private function note(int $orderId, string $reason): void
    {
        $order = $this->model_checkout_order->getOrder($orderId);
        if ($order && $this->unplaced($order)) {
            $this->model_checkout_order->addHistory($orderId, (int) $order['order_status_id'], $this->language->get('text_history_prefix') . $reason);
        }
    }

    /**
     * OpenCart's session cookie is SameSite=Strict by default, so the redirect
     * from Two's checkout arrives without it, and the session startup would
     * replace the buyer's session (cart and login) with an empty one. The
     * first hop therefore drops that cookie and answers with a page that
     * reloads the callback from the shop itself, a same-site navigation that
     * carries the buyer's cookie. The target is rebuilt from the fixed route
     * and the validated order id and token, so it can never point elsewhere.
     */
    private function bounce(string $kind, int $orderId, string $token): bool
    {
        if (isset($this->request->cookie[(string) $this->config->get('session_name')]) || isset($this->request->get['two_bounce'])) {
            return false;
        }
        header_remove('Set-Cookie');
        $target = htmlspecialchars($this->callbackUrl($kind, $orderId, $token, true), ENT_QUOTES, 'UTF-8');
        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('Referrer-Policy: no-referrer');
        $this->response->setOutput('<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $target . '"><title>'
            . htmlspecialchars($this->language->get('text_returning'), ENT_QUOTES, 'UTF-8') . '</title></head><body><p><a href="' . $target . '">'
            . htmlspecialchars($this->language->get('text_continue'), ENT_QUOTES, 'UTF-8') . '</a></p></body></html>');

        return true;
    }

    private function callbackUrl(string $kind, int $orderId, string $token, bool $bounced): string
    {
        $args = 'language=' . $this->config->get('config_language') . '&order_id=' . $orderId . '&token=' . $token . ($bounced ? '&two_bounce=1' : '');

        return $this->url->link(self::ROUTE . '.' . $kind, $args, true);
    }

    /**
     * The success page, or the cart with a notice (where OpenCart shows it).
     *
     * @param 'cancelled'|'changed'|'pending'|'generic'|null $notice
     */
    private function leave(string $goto, ?string $notice): void
    {
        $this->releaseLock();
        if ($notice !== null) {
            $this->session->data['error'] = match ($notice) {
                'cancelled' => $this->language->get('text_cancelled'),
                'changed' => $this->language->get('text_cart_changed'),
                'pending' => $this->language->get('text_pending'),
                default => $this->buyerMessage([ErrorMapper::GENERIC]),
            };
        }
        $route = $goto === 'success' ? 'checkout/success' : 'checkout/cart';
        $this->response->redirect($this->url->link($route, 'language=' . $this->config->get('config_language'), true));
    }

    /**
     * The buyer's sentences as one notice, each closed with a full stop and
     * shown once, as the reference plugin joins them.
     *
     * @param list<string> $keys
     */
    private function buyerMessage(array $keys): string
    {
        $sentences = [];
        foreach ($keys as $key) {
            $sentence = trim(sprintf($this->language->get($key), Brand::default()->productName));
            if (preg_match('~[.!?]\z~u', $sentence) !== 1) {
                $sentence .= '.';
            }
            $sentences[$sentence] = true;
        }

        return htmlspecialchars(implode(' ', array_keys($sentences)), ENT_QUOTES, 'UTF-8');
    }

    private function services(): Services
    {
        return new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
    }
}
