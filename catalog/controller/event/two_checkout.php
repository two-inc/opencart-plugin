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
 */
class TwoCheckout extends \Opencart\System\Engine\Controller
{
    public const SCRIPT = 'extension/two/catalog/view/javascript/two_checkout.js';

    /** @param array<int, mixed> $args the view's data */
    public function index(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        if ($route !== 'checkout/checkout' || !is_string($output) || !$this->config->get('payment_two_status')) {
            return;
        }
        if ((new Services($this->db, $this->log, (int) $this->config->get('config_store_id')))->settings->apiKey() === '') {
            return;
        }
        $config = $this->load->controller('extension/two/payment/two.scriptConfig');
        if (!is_array($config)) {
            return;
        }
        // JSON_HEX_* so no value can close the script element or open markup.
        $island = '<script type="application/json" id="two-checkout-config">'
            . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)
            . '</script><script src="' . self::SCRIPT . '?v=' . rawurlencode(Services::pluginVersion()) . '"></script>';
        $at = strripos($output, '</body>');
        $output = $at === false ? $output . $island : substr($output, 0, $at) . $island . substr($output, $at);
    }
}
