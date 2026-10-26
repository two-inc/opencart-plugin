<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RefundRecords;
use Opencart\System\Library\Extension\Two\Core\RateLimiter;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;
use Opencart\System\Library\Extension\Two\Opencart\DbOrderRecords;
use Opencart\System\Library\Extension\Two\Opencart\DbRateLimits;
use Opencart\System\Library\Extension\Two\Opencart\DbRefundRecords;
use Opencart\System\Library\Extension\Two\Opencart\DbSettingsStore;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Admin adapter for the Two payment extension (Extensions > Payments > Two).
 *
 * Thin by design: it reads the shop (languages, order statuses, the posted
 * form), hands plain values to the core and turns the core's codes into
 * copy. The save rules live in SettingsSave, the key check in ApiKeyVerdict.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Language $language
 * @property-read \Opencart\System\Library\Document $document
 * @property-read \Opencart\System\Library\Url $url
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\System\Library\Response $response
 * @property-read \Opencart\System\Library\Cart\User $user
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 * @property-read \Opencart\Admin\Model\Setting\Cron $model_setting_cron
 * @property-read \Opencart\Admin\Model\Setting\Event $model_setting_event
 * @property-read \Opencart\Admin\Model\Sale\Order $model_sale_order
 * @property-read \Opencart\Admin\Model\Setting\Store $model_setting_store
 * @property-read \Opencart\System\Engine\Event $event
 * @property-read \Opencart\Admin\Model\Localisation\Language $model_localisation_language
 * @property-read \Opencart\Admin\Model\Localisation\OrderStatus $model_localisation_order_status
 * @property-read \Opencart\Admin\Model\Localisation\Currency $model_localisation_currency
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';
    private const CODE = 'payment_two';

    /** OpenCart's own keys, saved as posted, and seeded next to the core defaults on install. */
    private const PLATFORM_DEFAULTS = ['status' => '0', 'sort_order' => '0'];

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        $this->document->addScript('../extension/two/admin/view/javascript/two_admin.js');
        $services = new Services($this->db, $this->log);
        $settings = $services->settings;
        $brand = Brand::default()->productName;
        $token = 'user_token=' . $this->session->data['user_token'];

        $data = [];
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $token)],
        ];
        $data['save'] = $this->url->link(self::ROUTE . '.save', $token);
        $data['verify_key'] = $this->url->link(self::ROUTE . '.verifyKey', $token);
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

        foreach (array_keys(self::PLATFORM_DEFAULTS) as $key) {
            $data[self::CODE . '_' . $key] = $this->config->get(self::CODE . '_' . $key);
        }

        // General: environment offered per Environment::options, the key never sent back to the browser.
        $stored = $settings->environment();
        $data['environments'] = array_map(fn (string $environment): array => [
            'value' => $environment,
            'text' => $this->language->get('text_environment_' . $environment),
        ], $services->environment->options($stored));
        $data['environment'] = $services->environment->resolve($stored);
        $data['environment_unhealthy'] = $services->environment->isHealthy($stored) ? '' : $this->language->get('error_environment_unhealthy');
        $data['api_key_saved'] = $settings->apiKey() !== '';
        $status = $services->verdict->status();
        $data['key_status'] = $status;
        $data['key_message'] = $this->keyMessage($status, null);

        // The merchant record, read here so a shop with no crontab still refreshes it.
        $record = $services->records->record();
        $fetchedAt = $services->records->fetchedAt();
        $data['merchant_id'] = is_scalar($record['id'] ?? null) ? (string) $record['id'] : '';
        $data['merchant_short_name'] = is_scalar($record['short_name'] ?? null) ? (string) $record['short_name'] : '';
        $data['record_fetched_at'] = $fetchedAt === null ? '' : gmdate('Y-m-d H:i', $fetchedAt) . 'Z';

        // Checkout fields.
        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages();
        $data['titles'] = [];
        foreach ($data['languages'] as $language) {
            $data['titles'][$language['language_id']] = $settings->title((int) $language['language_id']);
        }

        // Checkout fields: the placement switch, the optional fields and the minimum order.
        $data['company_search_in_address'] = $settings->companySearchInAddressArea();
        $data['optional_fields'] = [];
        foreach (SettingsProvider::OPTIONAL_FIELDS as $field => $key) {
            $data['optional_fields'][] = ['key' => $key, 'on' => in_array($field, $settings->optionalFields(), true), 'label' => $this->language->get('entry_' . $key), 'help' => sprintf($this->language->get('help_' . $key), $brand)];
        }
        $data['min_order_amount'] = trim((string) $settings->store()->get('min_order_amount'));
        $data['min_order_basis'] = $settings->merchantMinimumOrderBasis() ?? '';
        $data['trusted_proxies'] = trim((string) $settings->store()->get('trusted_proxies'));
        $data['disable_rate_limit'] = $settings->rateLimitDisabled();
        $shopCurrency = (string) $this->config->get('config_currency');
        $data['entry_min_order_amount'] = sprintf($this->language->get('entry_min_order_amount'), $shopCurrency);

        // The health row (section 18): why Two is not offered, or the constraints that may still withhold it.
        $data['health'] = $this->healthRow($services, $shopCurrency);

        // Order management.
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['fulfil_status_ids'] = $settings->fulfilStatusIds();
        // Status names come from OpenCart's own table, stored escaped as OpenCart stores every admin value.
        $active = array_column(array_filter($data['order_statuses'], static fn (array $status): bool => in_array((int) $status['order_status_id'], $data['fulfil_status_ids'], true)), 'name');
        $data['fulfil_active'] = implode(', ', $active);
        $data['cancel_status_id'] = $settings->cancelStatusId();
        $data['refund_status_id'] = $settings->refundStatusId();

        foreach (['help_company_search_in_address', 'help_environment', 'help_api_key', 'help_fulfil', 'help_cancel', 'help_refund', 'entry_fulfil', 'entry_cancel', 'entry_refund', 'text_status_mapping_intro', 'text_default_fulfil', 'text_default_cancel', 'text_default_refund'] as $key) {
            $data[$key] = sprintf($this->language->get($key), $brand);
        }
        $data['brand_name'] = $brand;
        $data['version'] = Services::pluginVersion();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    /**
     * Saves the form selectively: OpenCart's own keys as posted, the core's
     * through SettingsSave, which keeps a refused field as stored and names
     * it. Only posted fields are written, so a key the form does not carry
     * is never wiped.
     */
    public function save(): void
    {
        $this->load->language(self::ROUTE);
        $json = [];

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error']['warning'] = $this->language->get('error_permission');
            $this->json($json);

            return;
        }

        $post = $this->request->post;
        $platform = [];
        foreach (array_keys(self::PLATFORM_DEFAULTS) as $key) {
            if (isset($post[self::CODE . '_' . $key]) && is_scalar($post[self::CODE . '_' . $key])) {
                $platform[$key] = (string) $post[self::CODE . '_' . $key];
            }
        }
        if ($platform !== []) {
            // Upserts: a key with no row yet (added after this shop installed) is inserted, not dropped.
            (new DbSettingsStore($this->db))->setMany($platform);
        }

        $services = new Services($this->db, $this->log);
        $result = $services->save->save($this->submitted($post), $this->orderStatusIds(), (int) $this->config->get('config_void_status_id'));

        foreach ($result['errors'] as $field => $code) {
            $json['error'][$field] = $this->language->get('error_' . $code);
        }
        if (isset($result['errors']['trusted_proxies'])) {
            // Name the first entry that is not an IP or a range, as the reference plugin does.
            $bad = array_values(array_filter(RateLimiter::parseProxies(self::raw($post[self::CODE . '_trusted_proxies'] ?? '')), static fn (string $entry): bool => !RateLimiter::isValidProxyEntry($entry)));
            $json['error']['trusted_proxies'] = htmlspecialchars(sprintf($this->language->get('error_proxy_invalid'), $bad[0] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        $key = $this->credentialsMessage($result);
        if ($key !== null && $key['refused']) {
            $json['error'][$key['field']] = $key['text'];
        }
        if (isset($json['error'])) {
            $json['error']['warning'] = $this->language->get('error_partial');
        }
        $json['success'] = $this->language->get(isset($json['error']) ? 'text_success_partial' : 'text_success')
            . ($key !== null && !$key['refused'] && $key['text'] !== '' ? ' ' . $key['text'] : '');

        $this->json($json);
    }

    /**
     * The live check behind the API key field: the typed key (or, when the
     * field is empty, the stored one) against the chosen environment. Asks
     * Two and caches nothing.
     */
    public function verifyKey(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['status' => '', 'ok' => false, 'definitive' => false, 'message' => $this->language->get('error_permission')]);

            return;
        }
        $services = new Services($this->db, $this->log);
        $typed = trim(self::raw($this->request->post['api_key'] ?? null));
        $environment = self::raw($this->request->post['environment'] ?? null);
        $key = $typed === '' ? $services->settings->apiKey() : $typed;

        if (!$services->environment->acceptsSave($environment, $services->settings->environment())) {
            $this->json(['status' => '', 'ok' => false, 'definitive' => false, 'message' => $this->language->get('error_kept_environment')]);

            return;
        }
        if ($key !== '' && !SettingsSave::isWellFormedKey($key)) {
            $this->json(['status' => '', 'ok' => false, 'definitive' => true, 'message' => $this->language->get('error_kept_malformed')]);

            return;
        }
        $checked = $services->verdict->check($key, $environment);
        $this->json([
            'status' => $checked['status'],
            'ok' => $checked['status'] === ApiKeyVerdict::OK,
            'definitive' => ApiKeyVerdict::isDefinitive($checked['status']),
            'message' => $this->keyMessage($checked['status'], $checked['code']),
            'merchant_id' => is_scalar($checked['merchant']['id'] ?? null) ? (string) $checked['merchant']['id'] : '',
            'merchant_short_name' => is_scalar($checked['merchant']['short_name'] ?? null) ? (string) $checked['merchant']['short_name'] : '',
        ]);
    }

    /**
     * Called by Extensions > Payments > Install. Seeds the fresh-install
     * defaults (the status maps by the shop's status names) and registers
     * the daily merchant-record refresh with OpenCart's cron.
     */
    public function install(): void
    {
        $this->load->model('localisation/order_status');
        $byName = [];
        foreach ($this->model_localisation_order_status->getOrderStatuses() as $status) {
            $byName[$status['name']] ??= (int) $status['order_status_id'];
        }
        $statuses = [];
        foreach (SettingsProvider::DEFAULT_STATUS_NAMES as $key => $name) {
            if (isset($byName[$name])) {
                $statuses[$key] = $key === 'fulfil_status_ids' ? (string) json_encode([$byName[$name]]) : (string) $byName[$name];
            }
        }

        $defaults = [];
        foreach ($statuses + self::PLATFORM_DEFAULTS + SettingsProvider::DEFAULTS as $key => $value) {
            $defaults[self::CODE . '_' . $key] = $value;
        }
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(self::CODE, $defaults);

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);
        $this->model_setting_cron->addCron(Services::CRON_CODE, Services::CRON_DESCRIPTION, Services::CRON_CYCLE, Services::CRON_ACTION, true);

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(Services::EVENT_CODE);
        $this->model_setting_event->addEvent(['code' => Services::EVENT_CODE, 'description' => Services::EVENT_DESCRIPTION, 'trigger' => Services::EVENT_TRIGGER, 'action' => Services::EVENT_ACTION, 'status' => true, 'sort_order' => 0]);
        $this->model_setting_event->deleteEventByCode(Services::CHECKOUT_EVENT_CODE);
        $this->model_setting_event->addEvent(['code' => Services::CHECKOUT_EVENT_CODE, 'description' => Services::CHECKOUT_EVENT_DESCRIPTION, 'trigger' => Services::CHECKOUT_EVENT_TRIGGER, 'action' => Services::CHECKOUT_EVENT_ACTION, 'status' => true, 'sort_order' => 0]);

        // The order-scoped table. Kept on uninstall: it holds the Two order ids of placed orders.
        DbOrderRecords::install(function (string $sql): array {
            $result = $this->db->query($sql);

            return is_object($result) ? $result->rows : [];
        }, DB_PREFIX);
        // The checkout relays' per-caller rate-limit counts.
        $this->db->query(DbRateLimits::createTableSql(DB_PREFIX));
        DbRefundRecords::install(function (string $sql): array {
            $result = $this->db->query($sql);

            return is_object($result) ? $result->rows : [];
        }, DB_PREFIX);
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(self::CODE);

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(Services::EVENT_CODE);
        $this->model_setting_event->deleteEventByCode(Services::CHECKOUT_EVENT_CODE);
    }

    /**
     * The Two tab on the admin order page (Sales > Orders > the order),
     * which OpenCart shows for orders paid with this extension. OpenCart
     * renders it inline with the page, so it is only a shell: its content
     * (the Two order, the downloads, the refund form and the refunds sent)
     * reads Two, and is loaded from `two.panel` when the tab is opened, so
     * a slow or unreachable Two never holds up the order page.
     */
    public function order(): string
    {
        $this->load->language(self::ROUTE);

        return $this->load->view(self::ROUTE . '_order_tab', [
            'panel_action' => $this->orderLink('panel', (int) ($this->request->get['order_id'] ?? 0)),
            'text_panel_loading' => $this->language->get('text_panel_loading'),
            'error_panel' => $this->language->get('error_panel'),
        ]);
    }

    /**
     * The Two tab's content, loaded when the tab is opened and again after
     * a refund or retry. Same guard as the downloads.
     */
    public function panel(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', 'sale/order') || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->response->setOutput('<p>' . htmlspecialchars($this->language->get('error_invoice_permission'), ENT_QUOTES, 'UTF-8') . '</p>');

            return;
        }
        $this->response->setOutput($this->panelHtml());
    }

    private function panelHtml(): string
    {
        $this->load->language(self::ROUTE);
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        // The order's own store: settings, the API key among them, are kept per store.
        $order = $this->twoOrder($orderId);
        $services = new Services($this->db, $this->log, (int) ($order['store_id'] ?? 0));
        $record = $services->orders->get($orderId) ?? [];
        ['two' => $two, 'state' => $state, 'refundable' => $refundable] = $services->orderRefunds($this->event)->panel($orderId);
        $link = fn (string $action): string => $this->orderLink($action, $orderId);

        $remaining = $two === null ? null : OrderRefunds::remainingCents($two);
        $refunds = $services->refunds->forOrder($orderId);
        $earlier = OrderRefunds::refundedPerLine($refunds);
        $lines = [];
        foreach ($two === null ? [] : OrderRefunds::refundableLines($two) as $line) {
            $lines[] = $line + ['left' => max(0, $line['quantity'] - ($earlier[$line['id']]['quantity'] ?? 0))];
        }
        $text = [];
        foreach (['text_two_order', 'text_two_state', 'text_no_two_order', 'button_invoice', 'help_invoice', 'button_credit_note', 'help_credit_note', 'text_refund', 'text_refunded', 'text_remaining',
            'text_refund_help', 'text_refund_unavailable', 'text_refund_unread', 'text_refunds', 'column_line', 'column_quantity', 'column_net', 'column_tax', 'column_rate', 'column_refund_quantity',
            'column_date', 'column_amount', 'column_state', 'entry_refund_net', 'entry_refund_tax', 'entry_refund_rate', 'entry_refund_reason', 'button_refund', 'button_retry', 'text_refund_blocked', 'column_left', 'error_ajax'] as $key) {
            $text[$key] = $this->language->get($key);
        }
        $unresolved = false;
        foreach ($refunds as &$refund) {
            $refund['state_text'] = $this->language->get('text_refund_state_' . $refund['state']);
            $refund['retry'] = in_array($refund['state'], [RefundRecords::UNKNOWN, RefundRecords::PENDING], true);
            $unresolved = $unresolved || $refund['retry'];
        }
        unset($refund);

        return $this->load->view(self::ROUTE . '_order', $text + [
            'two_order_id' => $record['two_order_id'] ?? '',
            'two_order_state' => $state,
            'invoice' => in_array($state, OrderLifecycle::FULFILLED_STATES, true) ? $link('invoice') : '',
            'credit_note' => $state === 'REFUNDED' || in_array(RefundRecords::DONE, array_column($refunds, 'state'), true) ? $link('creditNote') : '',
            'refundable' => $refundable,
            'read' => $two !== null,
            'refund_action' => $link('refund'),
            'retry_action' => $link('refundRetry'),
            'unresolved' => $unresolved,
            'currency' => (string) ($two['currency'] ?? ''),
            'refunded' => $two === null ? '' : Postprocessing::money(OrderRefunds::refundedCents($two)),
            'remaining' => $remaining === null ? '' : Postprocessing::money($remaining),
            'lines' => $lines,
            'panel_action' => $link('panel'),
            'rates' => $two === null ? [] : OrderRefunds::rates($two),
            'refunds' => $refunds,
        ]);
    }

    private function orderLink(string $action, int $orderId): string
    {
        return $this->url->link(self::ROUTE . '.' . $action, 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $orderId, true);
    }

    /**
     * The partial refund form's action: refunds the picked lines and the
     * amount line at Two and notes the outcome in the order history.
     * Needs the right to modify orders and this extension.
     */
    public function refund(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', 'sale/order') || !$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['error' => $this->language->get('error_refund_permission')]);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->json(['error' => $this->language->get('error_invoice_no_reference')]);

            return;
        }
        $post = $this->request->post;
        $lines = [];
        foreach (is_array($post['two_refund_line'] ?? null) ? $post['two_refund_line'] : [] as $id => $quantity) {
            if (is_scalar($quantity) && trim((string) $quantity) !== '') {
                $lines[(string) $id] = ctype_digit(trim((string) $quantity)) ? (int) $quantity : -1;
            }
        }
        if (in_array(-1, $lines, true)) {
            $this->json(['error' => 'Not refunded: a quantity is not a whole number.']);

            return;
        }
        $request = ['lines' => $lines];
        foreach (['net', 'tax', 'rate', 'reason'] as $field) {
            $request[$field] = self::raw($post['two_refund_' . $field] ?? '');
        }

        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderRefunds($this->catalogEvent((int) $order['store_id']))->partial($orderId, $request);
        $this->historyNote($orderId, (int) $order['order_status_id'], $result['reason']);
        $this->json($result['outcome'] === OrderLifecycle::DONE ? ['success' => $result['reason']] : ['error' => $result['reason']]);
    }

    /**
     * Sends a refund whose outcome is unknown again, under its own key, and
     * notes the outcome in the order history. Same rights as a refund.
     */
    public function refundRetry(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('modify', 'sale/order') || !$this->user->hasPermission('modify', self::ROUTE)) {
            $this->json(['error' => $this->language->get('error_refund_permission')]);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->json(['error' => $this->language->get('error_invoice_no_reference')]);

            return;
        }
        $refundId = (int) ($this->request->post['two_refund_id'] ?? 0);
        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderRefunds($this->catalogEvent((int) $order['store_id']))->retry($orderId, $refundId);
        $this->historyNote($orderId, (int) $order['order_status_id'], $result['reason']);
        $this->json($result['outcome'] === OrderLifecycle::DONE ? ['success' => $result['reason']] : ['error' => $result['reason']]);
    }

    /**
     * Streams the order's latest Two credit note, behind the same guard as
     * the invoice.
     */
    public function creditNote(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', 'sale/order') || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->invoiceNotice($this->language->get('error_invoice_permission'), 403);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->invoiceNotice($this->language->get('error_invoice_no_reference'), 404);

            return;
        }
        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderRefunds($this->event)->creditNote($orderId);
        if (isset($result['pdf'])) {
            $this->pdf($result['pdf'], 'credit-note-' . $orderId . '.pdf');

            return;
        }
        $this->invoiceNotice($this->language->get($result['notice'] === OrderRefunds::CREDIT_NOTE_NO_REFERENCE ? 'error_invoice_no_reference' : 'error_credit_note'), 200);
    }

    /**
     * The event registry of the order's store front, as OpenCart's own admin
     * order edits run it (a store instance). The admin registers only admin/
     * and system/ triggers, so a write sent from here fires the order
     * postprocessing event there, where the merchant's subscribers on
     * `catalog/extension/two/order_postprocessing` are registered.
     */
    private function catalogEvent(int $storeId): \Opencart\System\Engine\Event
    {
        $this->load->model('setting/store');
        $store = $this->model_setting_store->createStoreInstance($storeId, (string) $this->config->get('config_language'));
        $config = $store->get('config');
        $event = $store->get('event');
        if (!$config instanceof \Opencart\System\Engine\Config || !$event instanceof \Opencart\System\Engine\Event) {
            throw new \RuntimeException('The store instance has no config or event registry.');
        }
        // The instance takes its store from the admin's host name (so store 0);
        // set the order's store, as OpenCart's own order edit does.
        $config->set('config_store_id', $storeId);

        return $event;
    }

    /**
     * The shop order when it was paid with this extension, else null.
     *
     * @return array<string, mixed>|null
     */
    private function twoOrder(int $orderId): ?array
    {
        $this->load->model('sale/order');
        $order = $orderId > 0 ? $this->model_sale_order->getOrder($orderId) : [];

        return $order && (string) ($order['payment_method']['code'] ?? '') === 'two.two' ? $order : null;
    }

    /**
     * A line in the order history at the order's current status, not
     * notified. Written directly: the admin has no order history model, and
     * the status does not change, so no order event is due.
     */
    private function historyNote(int $orderId, int $statusId, string $reason): void
    {
        $this->db->query('INSERT INTO `' . DB_PREFIX . "order_history` SET `order_id` = '" . $orderId . "', `order_status_id` = '" . $statusId . "', `notify` = '0', `comment` = '"
            . $this->db->escape('Two: ' . $reason) . "', `date_added` = NOW()");
    }

    private function pdf(string $body, string $filename): void
    {
        $this->response->addHeader('Content-Type: application/pdf');
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->response->addHeader('Cache-Control: private, no-store');
        $this->response->setOutput($body);
    }

    /**
     * Streams the order's Two invoice. Admin only: the route sits behind the
     * admin login and its user_token, and the user must also be allowed to
     * see orders and this extension.
     */
    public function invoice(): void
    {
        $this->load->language(self::ROUTE);
        if (!$this->user->hasPermission('access', 'sale/order') || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->invoiceNotice($this->language->get('error_invoice_permission'), 403);

            return;
        }
        $orderId = (int) ($this->request->get['order_id'] ?? 0);
        $order = $this->twoOrder($orderId);
        if ($order === null) {
            $this->invoiceNotice($this->language->get('error_invoice_no_reference'), 404);

            return;
        }

        $result = (new Services($this->db, $this->log, (int) $order['store_id']))->orderLifecycle($this->event)->invoice($orderId);
        if (isset($result['pdf'])) {
            $this->pdf($result['pdf'], 'invoice-' . $orderId . '.pdf');

            return;
        }
        $message = match ($result['notice']) {
            OrderLifecycle::INVOICE_NOT_READY => $this->language->get('error_invoice_not_ready'),
            OrderLifecycle::INVOICE_UNAVAILABLE_STATE => sprintf($this->language->get('error_invoice_state'), $result['state']),
            OrderLifecycle::INVOICE_NO_REFERENCE => $this->language->get('error_invoice_no_reference'),
            default => $this->language->get('error_invoice'),
        };
        $this->invoiceNotice($message, 200);
    }

    /** Why there is no invoice, as a short page the download link opened. */
    private function invoiceNotice(string $message, int $status): void
    {
        if ($status !== 200) {
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $status . ($status === 403 ? ' Forbidden' : ' Not Found'));
        }
        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($this->language->get('heading_title'), ENT_QUOTES, 'UTF-8')
            . '</title></head><body><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>');
    }

    /**
     * The posted core fields, as plain values. A field the form did not post
     * is left out, so SettingsSave leaves it alone. OpenCart HTML-escapes
     * every posted value; the core holds values as typed, so they are
     * decoded here and escaped again when rendered.
     *
     * @param array<string, mixed> $post
     * @return array{api_key?: string, environment?: string, title?: array<int, string>, fulfil_status_ids?: list<string>, cancel_status_id?: string, refund_status_id?: string, company_search_in_address?: string, enable_invoice_email?: string, enable_po_number?: string, enable_project?: string, enable_department?: string, min_order_amount?: string, min_order_basis?: string, trusted_proxies?: string, disable_rate_limit?: string}
     */
    private function submitted(array $post): array
    {
        $field = static fn (string $key): mixed => $post[self::CODE . '_' . $key] ?? null;
        $submitted = [];
        foreach (array_merge(['api_key', 'environment', 'cancel_status_id', 'refund_status_id', 'min_order_amount', 'min_order_basis', 'trusted_proxies'], SettingsSave::SWITCHES) as $key) {
            if (is_scalar($field($key))) {
                $submitted[$key] = self::raw($field($key));
            }
        }
        if (is_array($field('title'))) {
            // One title per shop language: a language the form left out reads as blank, which is refused.
            $this->load->model('localisation/language');
            $titles = [];
            foreach ($this->model_localisation_language->getLanguages() as $language) {
                $title = $field('title')[$language['language_id']] ?? '';
                $titles[(int) $language['language_id']] = self::raw($title);
            }
            $submitted['title'] = $titles;
        }
        if (is_array($field('fulfil_status_ids'))) {
            $submitted['fulfil_status_ids'] = array_values(array_map(static fn (mixed $id): string => self::raw($id), $field('fulfil_status_ids')));
        }

        return $submitted;
    }

    /** A posted value as typed: Request::clean ran htmlspecialchars (ENT_COMPAT) on it, which this undoes exactly. */
    private static function raw(mixed $value): string
    {
        return is_scalar($value) ? htmlspecialchars_decode((string) $value, ENT_COMPAT) : '';
    }

    /** @return list<int> */
    private function orderStatusIds(): array
    {
        $this->load->model('localisation/order_status');

        return array_map(static fn (array $status): int => (int) $status['order_status_id'], $this->model_localisation_order_status->getOrderStatuses());
    }

    /**
     * The health row's sentence: "Not shown at checkout" and the first
     * reason that holds for every cart, or "Shown at checkout" and the
     * per-cart constraints.
     *
     * @return array{ok: bool, text: string}
     */
    private function healthRow(Services $services, string $shopCurrency): array
    {
        $this->load->model('localisation/currency');
        $currencies = array_values(array_map(static fn (array $row): string => (string) $row['code'], array_filter($this->model_localisation_currency->getCurrencies(), static fn (array $row): bool => (bool) $row['status'])));
        $health = $services->availability->health((bool) $this->config->get(self::CODE . '_status'), $shopCurrency, $currencies);
        $brand = Brand::default();
        if (!$health['shown']) {
            return ['ok' => false, 'text' => $this->language->get('text_health_hidden') . ' - ' . sprintf($this->language->get('text_withheld_' . $health['reason']), $brand->providerFullName, $shopCurrency)];
        }
        $clauses = [];
        $floors = array_map(fn (array $floor): string => sprintf($this->language->get('text_health_floor'), number_format($floor['amount'], 2, '.', ''), $floor['currency'], $this->language->get('text_basis_' . $floor['basis'])), $health['floors']);
        if ($floors !== []) {
            $clauses[] = sprintf($this->language->get('text_health_below'), implode(' ' . $this->language->get('text_or') . ' ', $floors));
        }
        if ($health['buyer_countries'] !== []) {
            $clauses[] = sprintf($this->language->get('text_health_countries'), implode(', ', $health['buyer_countries']));
        }
        if ($health['refused_currencies'] !== []) {
            $clauses[] = sprintf($this->language->get('text_health_currencies'), implode(', ', $health['refused_currencies']));
        }

        return ['ok' => true, 'text' => $this->language->get('text_health_shown') . ($clauses === [] ? '' : ' - ' . implode('; ', $clauses))];
    }

    /** The merchant-facing sentence for a verdict, empty for ok. */
    private function keyMessage(string $status, ?int $code): string
    {
        $brand = Brand::default()->productName;

        return match ($status) {
            ApiKeyVerdict::OK => '',
            ApiKeyVerdict::INVALID => sprintf($this->language->get('error_key_invalid'), $brand),
            ApiKeyVerdict::SERVICE_ERROR => sprintf($this->language->get('error_key_service'), $brand, (int) $code),
            ApiKeyVerdict::UNREACHABLE => sprintf($this->language->get('error_key_unreachable'), $brand),
            ApiKeyVerdict::NOT_CONFIGURED => sprintf($this->language->get('error_key_not_configured'), $brand),
            ApiKeyVerdict::VERIFYING => $this->language->get('text_key_verifying'),
            default => $code !== null
                ? sprintf($this->language->get('error_key_unexpected'), $brand, $code)
                : $this->language->get('error_key_unverified'),
        };
    }

    /**
     * What the save says about the credentials: refused (shown on the field
     * at fault) or a note on the success line. Null when they were not posted.
     *
     * @param array{credentials: string|null, status: string|null, code: int|null} $result
     * @return array{refused: bool, field: string, text: string}|null
     */
    private function credentialsMessage(array $result): ?array
    {
        $verdict = $result['status'] === null ? '' : $this->keyMessage($result['status'], $result['code']);

        return match ($result['credentials']) {
            null => null,
            SettingsSave::SAVED, SettingsSave::SAVED_UNVERIFIED, SettingsSave::UNCHANGED => ['refused' => false, 'field' => 'api_key', 'text' => $verdict],
            SettingsSave::MISSING => ['refused' => true, 'field' => 'api_key', 'text' => $this->language->get('error_key_missing')],
            SettingsSave::KEPT_REJECTED, SettingsSave::KEPT_UNVERIFIED => ['refused' => true, 'field' => 'api_key', 'text' => trim($verdict . ' ' . $this->language->get('text_key_kept'))],
            SettingsSave::KEPT_ENVIRONMENT => ['refused' => true, 'field' => 'environment', 'text' => $this->language->get('error_kept_environment')],
            default => ['refused' => true, 'field' => 'api_key', 'text' => $this->language->get('error_' . $result['credentials'])],
        };
    }

    /** @param array<string, mixed> $json */
    private function json(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }
}
