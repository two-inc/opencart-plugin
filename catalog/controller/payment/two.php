<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\BuyerFields;
use Opencart\System\Library\Extension\Two\Core\CompanyLookup;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\OrderRecords;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Storefront adapter for the Two payment method: the tile, the checkout
 * capture's endpoints (company search, sole-trader signup tokens, the saved
 * company per address role, the order intent), placing the order (create the Two order, send the buyer to
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

    /** The address roles a company is captured for, and the tile's own. */
    public const ROLES = ['payment', 'shipping', 'tile'];
    private const SESSION_KEY = 'two_checkout';
    /** How long the registry's per-country answers are kept in the session. */
    private const CACHE_SECONDS = 3600;

    /**
     * The tile under the payment step: the company (searched here, or the
     * one captured in the address form), the optional fields and the
     * confirm button. Rendered once Two is the chosen method, so the order
     * intent it asks never slows the method list.
     */
    public function index(): string
    {
        $this->load->language(self::ROUTE);
        $services = $this->services();
        $inTile = !$services->settings->companySearchInAddressArea();
        $company = $this->companyFor($this->buyerRole($inTile));
        $this->load->model(self::ROUTE);
        $this->load->model('localisation/country');
        $countries = [];
        foreach ($this->model_localisation_country->getCountries() as $country) {
            if ((string) $country['iso_code_2'] !== '') {
                $countries[] = ['code' => (string) $country['iso_code_2'], 'name' => (string) $country['name']];
            }
        }
        $fields = [];
        foreach ($services->settings->optionalFields() as $field) {
            $fields[] = ['name' => $field, 'label' => $this->language->get('entry_' . $field), 'type' => $field === 'invoice_email' ? 'email' : 'text'];
        }
        $brand = Brand::default()->productName;

        return $this->load->view(self::ROUTE, [
            'language' => $this->config->get('config_language'),
            'in_tile' => $inTile,
            'countries' => $countries,
            'country' => $company['country_code'] ?? $this->model_extension_two_payment_two->buyerCountry() ?? '',
            'company' => $company,
            'fields' => $fields,
            'text_company_intro' => sprintf($this->language->get('text_company_intro'), $brand),
            'text_company_from_address' => sprintf($this->language->get('text_company_from_address'), $brand),
            'text_company_missing' => sprintf($this->language->get('text_company_missing'), $brand),
            'entry_company_name' => $this->language->get('entry_company_name'),
            'entry_organization_number' => $this->language->get('entry_organization_number'),
            'entry_country' => $this->language->get('entry_country'),
            'text_optional' => $this->language->get('text_optional'),
            'button_confirm' => $this->language->get('button_confirm'),
        ]);
    }

    /** Places the order: asks the intent again, creates the Two order and answers with Two's payment URL. */
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
        $services = $this->services();
        $fields = BuyerFields::fromPost($this->postedFields(), $services->settings->optionalFields());
        if (!$json && $fields['refused'] !== []) {
            $json['error'] = $this->fieldMessage($fields['refused']);
        }

        if (!$json) {
            $company = $this->buyerCompany($services->settings->companySearchInAddressArea());
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
            $input = $this->model_extension_two_payment_two->orderInput($orderId, $company, $urls, $fields['fields']);
            $flow = $services->orderFlow($this->event);
            // Section 6.3: the intent is asked again at placement, and a decline refuses it here.
            $intent = $input === null ? null : $flow->intent($input['order'], $input['lines'], $input['totals'], $this->sessionHash(), $input['context'] + ['merchant_short_name' => $this->merchantShortName($services)]);
            if ($intent !== null && $intent['outcome'] === OrderFlow::DECLINED) {
                $this->note($orderId, $intent['reason']);
                $json['error'] = $this->declinedNotice();
            } else {
                $result = $input === null
                    ? ['ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'reason' => 'The order could not be read.']
                    : $flow->create($input['order'], $input['lines'], $input['totals'], $token, $input['context']);
                if ($result['ok']) {
                    $json['redirect'] = $result['redirect'];
                } else {
                    if ($result['buyer'] !== [ErrorMapper::COMPANY_REQUIRED]) {
                        $this->note($orderId, $result['reason']);
                    }
                    $json['error'] = $this->buyerMessage($result['buyer']);
                }
            }
        }

        $this->json($json);
    }

    /**
     * The order intent for the session's order, asked from the tile once the
     * company is known (never from the method list). Answers the outcome and
     * the notice to show, if the brand shows one.
     */
    public function intent(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(true)) {
            return;
        }
        $orderId = (int) ($this->session->data['order_id'] ?? 0);
        $this->load->model('checkout/order');
        $order = $orderId > 0 ? $this->model_checkout_order->getOrder($orderId) : [];
        if (!$order || !$this->unplaced($order) || ($this->session->data['payment_method']['code'] ?? '') !== 'two.two') {
            $this->json(['outcome' => OrderFlow::UNDECIDED, 'notice' => '']);

            return;
        }
        $services = $this->services();
        $this->load->model(self::ROUTE);
        $input = $this->model_extension_two_payment_two->orderInput($orderId, $this->buyerCompany($services->settings->companySearchInAddressArea()), []);
        if ($input === null) {
            $this->json(['outcome' => OrderFlow::UNDECIDED, 'notice' => '']);

            return;
        }
        $result = $services->orderFlow($this->event)->intent($input['order'], $input['lines'], $input['totals'], $this->sessionHash(), $input['context'] + ['merchant_short_name' => $this->merchantShortName($services)]);
        $brand = Brand::default();
        $notice = match (true) {
            $result['outcome'] === OrderFlow::APPROVED && $brand->intentApprovedNoticeEnabled => htmlspecialchars($brand->intentApprovedNotice ?? sprintf($this->language->get('text_intent_approved'), $brand->productName), ENT_QUOTES, 'UTF-8'),
            $result['outcome'] === OrderFlow::DECLINED && $brand->intentDeclinedNoticeEnabled => $this->declinedNotice(),
            $result['buyer'] !== [] => $this->buyerMessage($result['buyer']),
            default => '',
        };
        $this->json(['outcome' => $result['outcome'], 'notice' => $notice]);
    }

    /** The company search, relayed so the API key stays on the server. */
    public function search(): void
    {
        if (!$this->guarded(false)) {
            return;
        }
        $companies = $this->services()->companies->search((string) ($this->request->get['q'] ?? ''), (string) ($this->request->get['country'] ?? ''));
        $this->json($companies === null ? ['error' => 'unavailable'] : ['items' => $companies]);
    }

    /** The countries the company search covers, kept in the session for an hour. */
    public function countries(): void
    {
        if (!$this->guarded(false)) {
            return;
        }
        $cached = $this->state()['countries'] ?? null;
        if (!is_array($cached) || ($cached['at'] ?? 0) < time() - self::CACHE_SECONDS) {
            $countries = $this->services()->companies->supportedCountries();
            $cached = $countries === null ? null : ['at' => time(), 'list' => $countries];
            if ($cached !== null) {
                $this->remember('countries', $cached);
            }
        }
        $this->json(['countries' => $cached['list'] ?? null]);
    }

    /** Whether a buyer in a country may enrol as a sole trader; one country kept in the session. */
    public function soleTrader(): void
    {
        if (!$this->guarded(false)) {
            return;
        }
        $country = strtoupper(trim((string) ($this->request->get['country'] ?? '')));
        $cached = $this->state()['sole_trader'] ?? null;
        if (is_array($cached) && ($cached['country'] ?? '') === $country && ($cached['at'] ?? 0) >= time() - self::CACHE_SECONDS) {
            $this->json(['available' => (bool) $cached['available']]);

            return;
        }
        $available = $this->services()->companies->soleTraderAvailable($country);
        if ($available !== null) {
            // One slot, so a caller cannot grow the session one country at a time.
            $this->remember('sole_trader', ['country' => $country, 'available' => $available, 'at' => time()]);
        }
        $this->json(['available' => $available]);
    }

    /** Mints the hosted sole-trader signup's tokens for this buyer's enrolment attempt. */
    public function soleTraderTokens(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(true)) {
            return;
        }
        $country = strtoupper(trim((string) ($this->request->post['country'] ?? '')));
        if (preg_match('~^[A-Z]{2}\z~', $country) !== 1) {
            $this->json(['error' => $this->language->get('error_sole_trader')]);

            return;
        }
        $attempt = (int) ($this->state()['sole_trader_attempt'] ?? 0) + 1;
        $this->remember('sole_trader_attempt', $attempt);
        $companies = $this->services()->companies;
        $tokens = $companies->soleTraderTokens(IdempotencyKey::soleTraderAttempt($this->sessionHash(), $attempt));
        $this->json($tokens === null
            ? ['error' => $this->language->get('error_sole_trader')]
            : $tokens + ['signup_url' => $companies->signupUrl(), 'api_base' => $companies->apiBaseUrl(), 'country' => $country]);
    }

    /**
     * Keeps the company captured for one address role (or the tile) in the
     * session, each role on its own. An empty name clears the role.
     */
    public function saveCompany(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->guarded(true)) {
            return;
        }
        $post = fn (string $key): string => trim(html_entity_decode((string) ($this->request->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'));
        $role = $post('role');
        $company = [
            'company_name' => $post('company_name'),
            'organization_number' => $post('organization_number'),
            'country_code' => strtoupper($post('country')),
            'source' => $post('source'),
        ];
        $valid = in_array($role, self::ROLES, true)
            && in_array($company['source'], ['search', 'manual', 'sole_trader'], true)
            && preg_match('~^[A-Z]{2}\z~', $company['country_code']) === 1
            && preg_match('~[\x00-\x1F\x7F]~', $company['company_name'] . $company['organization_number']) !== 1;
        foreach (OrderRecords::MAX_LENGTHS as $field => $max) {
            $valid = $valid && mb_strlen($company[$field]) <= $max;
        }
        if (!$valid) {
            $this->json(['ok' => false]);

            return;
        }
        $companies = $this->state()['companies'] ?? [];
        if ($company['company_name'] === '') {
            unset($companies[$role]);
        } else {
            $companies[$role] = $company;
        }
        $this->remember('companies', $companies);
        $this->json(['ok' => true]);
    }

    /**
     * The settings the checkout script reads, for the checkout page event.
     *
     * @return array<string, mixed>
     */
    public function scriptConfig(): array
    {
        $this->load->language(self::ROUTE);
        $services = $this->services();
        $this->load->model('localisation/country');
        $isoById = [];
        foreach ($this->model_localisation_country->getCountries() as $country) {
            $isoById[(string) $country['country_id']] = (string) $country['iso_code_2'];
        }
        $link = fn (string $action): string => str_replace('&amp;', '&', $this->url->link(self::ROUTE . '.' . $action, 'language=' . $this->config->get('config_language')));
        $brand = Brand::default()->productName;
        $copy = [];
        foreach (['search_label', 'search_hint', 'searching', 'no_matches', 'search_unavailable', 'select_country', 'country_not_covered', 'manual_entry', 'back_to_search', 'sole_trader_entry', 'sole_trader_error', 'sole_trader_popup_blocked', 'change_company', 'organization_number', 'checking', 'selected'] as $key) {
            $copy[$key] = sprintf($this->language->get('js_' . $key), $brand, CompanyLookup::MIN_TERM_LENGTH);
        }

        return [
            'token' => $this->csrfToken(),
            'in_address' => $services->settings->companySearchInAddressArea(),
            'urls' => ['search' => $link('search'), 'countries' => $link('countries'), 'sole_trader' => $link('soleTrader'), 'sole_trader_tokens' => $link('soleTraderTokens'), 'save_company' => $link('saveCompany'), 'intent' => $link('intent')],
            'countries' => $isoById,
            'companies' => $this->state()['companies'] ?? (object) [],
            'min_length' => CompanyLookup::MIN_TERM_LENGTH,
            'i18n' => $copy,
        ];
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
            ], [], BuyerFields::decode($record['buyer_fields'] ?? ''));
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

    /** The role whose company is the buyer: the tile's own, else the billing address when the shop collects one, else the shipping address. */
    private function buyerRole(bool $inTile): string
    {
        return $inTile ? 'tile' : ($this->config->get('config_checkout_payment_address') ? 'payment' : 'shipping');
    }

    /**
     * The company the order is placed for. In the tile it is what the tile
     * posts; from the address forms, what the buyer captured there.
     *
     * @return array{company_name: string, organization_number: string, country_code: string}
     */
    private function buyerCompany(bool $inAddress): array
    {
        if ($inAddress) {
            $company = $this->companyFor($this->buyerRole(false)) ?? [];
        } else {
            $post = fn (string $key): string => trim(html_entity_decode((string) ($this->request->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'));
            $company = ['company_name' => $post('two_company_name'), 'organization_number' => $post('two_organization_number'), 'country_code' => strtoupper($post('two_country'))];
        }
        $country = (string) ($company['country_code'] ?? '');

        return [
            'company_name' => (string) ($company['company_name'] ?? ''),
            'organization_number' => (string) ($company['organization_number'] ?? ''),
            'country_code' => preg_match('~^[A-Z]{2}\z~', $country) === 1 ? $country : '',
        ];
    }

    /** @return array{company_name: string, organization_number: string, country_code: string, source: string}|null */
    private function companyFor(string $role): ?array
    {
        $company = $this->state()['companies'][$role] ?? null;

        return is_array($company) ? $company : null;
    }

    /** @return array<string, mixed> the posted optional fields, by field name */
    private function postedFields(): array
    {
        $fields = [];
        foreach (BuyerFields::FIELDS as $field) {
            $fields[$field] = html_entity_decode((string) ($this->request->post['two_' . $field] ?? ''), ENT_QUOTES, 'UTF-8');
        }

        return $fields;
    }

    /** @param list<string> $refused */
    private function fieldMessage(array $refused): string
    {
        $sentences = [];
        foreach ($refused as $field) {
            $sentences[] = $field === 'invoice_email'
                ? sprintf($this->language->get(ErrorMapper::INVOICE_EMAILS), Brand::default()->productName)
                : sprintf($this->language->get('error_field_too_long'), $this->language->get('entry_' . $field), BuyerFields::MAX_LENGTH);
        }

        return htmlspecialchars(implode(' ', $sentences), ENT_QUOTES, 'UTF-8');
    }

    private function declinedNotice(): string
    {
        $brand = Brand::default();

        return htmlspecialchars($brand->intentDeclinedNotice ?? sprintf($this->language->get('text_intent_declined'), $brand->productName), ENT_QUOTES, 'UTF-8');
    }

    private function merchantShortName(Services $services): string
    {
        $record = $services->records->record();

        return is_string($record['short_name'] ?? null) ? $record['short_name'] : '';
    }

    /**
     * Names the buyer's checkout session for idempotency keys: a hash, never
     * the session id. Empty without a session, so nothing keyed on it is sent.
     */
    private function sessionHash(): string
    {
        $id = $this->session->getId();

        return $id === '' ? '' : hash('sha256', 'two-checkout|' . $id);
    }

    /** The checkout's token against requests from other sites, one per session. */
    private function csrfToken(): string
    {
        $token = $this->state()['token'] ?? '';
        if (!is_string($token) || strlen($token) !== 32) {
            $token = bin2hex(random_bytes(16));
            $this->remember('token', $token);
        }

        return $token;
    }

    /** Whether the request carries this session's token (and, for a write, is a POST); answers the refusal itself. */
    private function guarded(bool $write): bool
    {
        $sent = (string) (($write ? $this->request->post : $this->request->get)['two_token'] ?? '');
        $token = $this->state()['token'] ?? '';
        if (($write && ($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') || !is_string($token) || $token === '' || !hash_equals($token, $sent)) {
            $this->json(['error' => 'forbidden']);

            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = $this->session->data[self::SESSION_KEY] ?? [];

        return is_array($state) ? $state : [];
    }

    private function remember(string $key, mixed $value): void
    {
        $state = $this->state();
        $state[$key] = $value;
        $this->session->data[self::SESSION_KEY] = $state;
    }

    /** @param array<string, mixed> $json */
    private function json(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput((string) json_encode($json));
    }

    private function services(): Services
    {
        return new Services($this->db, $this->log, (int) $this->config->get('config_store_id'));
    }
}
