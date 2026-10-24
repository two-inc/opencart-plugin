<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Opencart\DbSettingsStore;
use Opencart\System\Library\Extension\Two\Opencart\Services;

/**
 * Admin adapter for the Two payment extension (Extensions > Payments > Two).
 *
 * Thin by design: it reads and writes OpenCart settings and renders the form.
 * Rules live in the core (system/library/core). P0 carries status and sort
 * order only; P2 builds the five-tab settings page.
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
 * @property-read \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 * @property-read \Opencart\Admin\Model\Setting\Cron $model_setting_cron
 */
class Two extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/two/payment/two';
    private const CODE = 'payment_two';

    /** Platform keys the admin form may save. Anything else posted is ignored. */
    private const FORM_KEYS = ['status', 'sort_order'];

    /** OpenCart's own keys, seeded next to the core defaults on install. */
    private const PLATFORM_DEFAULTS = ['status' => '0', 'sort_order' => '0'];

    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));

        $token = 'user_token=' . $this->session->data['user_token'];

        $data = [];
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $token)],
        ];
        $data['save'] = $this->url->link(self::ROUTE . '.save', $token);
        $data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

        foreach (self::FORM_KEYS as $key) {
            $data[self::CODE . '_' . $key] = $this->config->get(self::CODE . '_' . $key);
        }

        $data['brand_name'] = Brand::default()->productName;
        $data['version'] = Services::pluginVersion();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    /** Saves the form keys one by one, so a key the form does not carry is never wiped. */
    public function save(): void
    {
        $this->load->language(self::ROUTE);

        $json = [];

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $values = [];
            foreach (self::FORM_KEYS as $key) {
                $values[$key] = (string) ($this->request->post[self::CODE . '_' . $key] ?? '');
            }
            // Upserts: a key with no row yet (added after this shop installed) is inserted, not dropped.
            (new DbSettingsStore($this->db))->setMany($values);

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    /**
     * Called by Extensions > Payments > Install. Seeds the fresh-install
     * defaults and registers the daily merchant-record refresh with
     * OpenCart's cron.
     */
    public function install(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting(self::CODE, self::defaults());

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);
        $this->model_setting_cron->addCron(Services::CRON_CODE, Services::CRON_DESCRIPTION, Services::CRON_CYCLE, Services::CRON_ACTION, true);
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(self::CODE);

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);
    }

    /** @return array<string, string> */
    private static function defaults(): array
    {
        $defaults = [];
        foreach (self::PLATFORM_DEFAULTS + SettingsProvider::DEFAULTS as $key => $value) {
            $defaults[self::CODE . '_' . $key] = $value;
        }

        return $defaults;
    }
}
