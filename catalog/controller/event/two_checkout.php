<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Two\Event;

use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Puts the checkout capture script on the checkout page: its settings as a
 * JSON island and the script itself, just before the body closes.
 * Registered on `catalog/view/checkout/checkout/after`
 * (Services::CHECKOUT_EVENT_TRIGGER). Nothing is added while Two is switched
 * off or has no API key; whether Two is offered for the cart is the method
 * list's question, and the script only acts on the panels it finds.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\DB $db
 * @property-read \Opencart\System\Library\Log $log
 * @property-read \Opencart\System\Library\Session $session
 * @property-read \Opencart\System\Library\Request $request
 * @property-read \Opencart\Catalog\Model\Account\Address $model_account_address
 */
class TwoCheckout extends \Opencart\System\Engine\Controller
{
    public const SCRIPT = 'extension/two/catalog/view/javascript/two_checkout.js';

    /**
     * The shared company search, loaded before the checkout script: the four
     * vendored modules (byte for byte the sibling plugins', checked in CI),
     * then this extension's host for them.
     */
    public const COMPANY_SEARCH = ['company-identity', 'company-search-panel', 'sole-trader', 'company-capture-component', 'company-search-host'];

    public const STYLESHEET = 'extension/two/catalog/view/stylesheet/two_checkout.css';

    /** @param array<int, mixed> $args the view's data */
    public function index(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        $pages = ['checkout/checkout' => null, 'account/address_form' => ['account']];
        if (!array_key_exists($route, $pages) || !is_string($output) || !$this->config->get('payment_two_status')) {
            return;
        }
        if ((new Services($this->db, $this->log, (int) $this->config->get('config_store_id')))->settings->apiKey() === '') {
            return;
        }
        $config = $this->load->controller('extension/two/payment/two.scriptConfig');
        if (!is_array($config)) {
            return;
        }
        if ($pages[$route] !== null) {
            // The address edit form: one control on its own company field, in
            // address placement only (the tile placement leaves it plain).
            if (!$config['in_address']) {
                return;
            }
            $config['roles'] = $pages[$route];
        }
        // JSON_HEX_* so no value can close the script element or open markup.
        $version = '?v=' . rawurlencode(Services::pluginVersion());
        $island = '<link rel="stylesheet" href="' . self::STYLESHEET . $version . '">'
            . '<script type="application/json" id="two-checkout-config">'
            . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)
            . '</script>';
        foreach (self::COMPANY_SEARCH as $module) {
            $island .= '<script src="extension/two/catalog/view/javascript/two/' . $module . '.js' . $version . '"></script>';
        }
        $island .= '<script src="' . self::SCRIPT . $version . '"></script>';
        $at = strripos($output, '</body>');
        $output = $at === false ? $output . $island : substr($output, 0, $at) . $island . substr($output, $at);
    }

    /**
     * Before OpenCart saves a customer's address: keep the company the buyer
     * captured for it in the address's own `custom_field` (`two_company`), so
     * a later checkout on the saved address reuses it. The capture is the
     * session's for the form being saved, and only while its name is still
     * the address's company; an edit with no fresh capture keeps the stored
     * one on the same terms.
     *
     * @param array<int, mixed> $args addAddress(customer_id, data) or editAddress(customer_id, address_id, data)
     */
    public function address(string &$route, array &$args = []): void
    {
        $edit = str_ends_with($route, 'editAddress');
        $at = $edit ? 2 : 1;
        if (!is_array($args[$at] ?? null)) {
            return;
        }
        $data = $args[$at];
        $name = trim((string) ($data['company'] ?? ''));
        $fields = is_array($data['custom_field'] ?? null) ? $data['custom_field'] : [];
        unset($fields[Services::ADDRESS_CUSTOM_FIELD]);
        $captures = (array) ($this->session->data['two_checkout']['companies'] ?? []);
        $routeRole = ['checkout/payment_address.save' => 'payment', 'checkout/shipping_address.save' => 'shipping', 'account/address.save' => 'account'][(string) ($this->request->get['route'] ?? '')] ?? '';
        $kept = null;
        foreach (array_unique(array_filter([$routeRole, 'payment', 'shipping', 'account'])) as $role) {
            $capture = $captures[$role] ?? null;
            if (is_array($capture) && $name !== '' && trim((string) ($capture['company_name'] ?? '')) === $name) {
                $kept = $capture;
                break;
            }
        }
        if ($kept === null && $edit) {
            $this->load->model('account/address');
            $stored = $this->model_account_address->getAddress((int) $args[0], (int) $args[1])['custom_field'][Services::ADDRESS_CUSTOM_FIELD] ?? null;
            $kept = is_array($stored) && $name !== '' && trim((string) ($stored['company_name'] ?? '')) === $name ? $stored : null;
        }
        if ($kept !== null) {
            $fields[Services::ADDRESS_CUSTOM_FIELD] = array_intersect_key($kept, array_flip(['company_name', 'organization_number', 'country_code', 'source']));
        }
        $data['custom_field'] = $fields;
        $args[$at] = $data;
    }
}
