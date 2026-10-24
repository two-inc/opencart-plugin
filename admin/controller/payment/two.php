<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Two\Payment;

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Brand;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;
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
 * @property-read \Opencart\Admin\Model\Localisation\Language $model_localisation_language
 * @property-read \Opencart\Admin\Model\Localisation\OrderStatus $model_localisation_order_status
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

        // Order management.
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['fulfil_status_ids'] = $settings->fulfilStatusIds();
        $data['cancel_status_id'] = $settings->cancelStatusId();
        $data['refund_status_id'] = $settings->refundStatusId();

        foreach (['help_environment', 'help_api_key', 'help_fulfil', 'help_cancel', 'help_refund', 'entry_fulfil', 'entry_cancel', 'entry_refund', 'text_status_mapping_intro'] as $key) {
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
        $result = $services->save->save($this->submitted($post), $this->orderStatusIds());

        foreach ($result['errors'] as $field => $code) {
            $json['error'][$field] = $this->language->get('error_' . $code);
        }
        $key = $this->credentialsMessage($result);
        if ($key !== null && $key['refused']) {
            $json['error']['api_key'] = $key['text'];
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
        $typed = trim(is_scalar($this->request->post['api_key'] ?? null) ? (string) $this->request->post['api_key'] : '');
        $environment = is_scalar($this->request->post['environment'] ?? null) ? (string) $this->request->post['environment'] : '';
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
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(self::CODE);

        $this->load->model('setting/cron');
        $this->model_setting_cron->deleteCronByCode(Services::CRON_CODE);
    }

    /**
     * The posted core fields, as plain values. A field the form did not post
     * is left out, so SettingsSave leaves it alone.
     *
     * @param array<string, mixed> $post
     * @return array{api_key?: string, environment?: string, title?: array<int, string>, fulfil_status_ids?: list<string>, cancel_status_id?: string, refund_status_id?: string}
     */
    private function submitted(array $post): array
    {
        $field = static fn (string $key): mixed => $post[self::CODE . '_' . $key] ?? null;
        $submitted = [];
        foreach (['api_key', 'environment', 'cancel_status_id', 'refund_status_id'] as $key) {
            if (is_scalar($field($key))) {
                $submitted[$key] = (string) $field($key);
            }
        }
        if (is_array($field('title'))) {
            // One title per shop language: a language the form left out reads as blank, which is refused.
            $this->load->model('localisation/language');
            $titles = [];
            foreach ($this->model_localisation_language->getLanguages() as $language) {
                $title = $field('title')[$language['language_id']] ?? '';
                $titles[(int) $language['language_id']] = is_scalar($title) ? (string) $title : '';
            }
            $submitted['title'] = $titles;
        }
        if (is_array($field('fulfil_status_ids'))) {
            $submitted['fulfil_status_ids'] = array_values(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $field('fulfil_status_ids')));
        }

        return $submitted;
    }

    /** @return list<int> */
    private function orderStatusIds(): array
    {
        $this->load->model('localisation/order_status');

        return array_map(static fn (array $status): int => (int) $status['order_status_id'], $this->model_localisation_order_status->getOrderStatuses());
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
     * What the save says about the credentials: refused (shown on the key
     * field) or a note on the success line. Null when they were not posted.
     *
     * @param array{credentials: string|null, status: string|null, code: int|null} $result
     * @return array{refused: bool, text: string}|null
     */
    private function credentialsMessage(array $result): ?array
    {
        $verdict = $result['status'] === null ? '' : $this->keyMessage($result['status'], $result['code']);

        return match ($result['credentials']) {
            null => null,
            SettingsSave::SAVED, SettingsSave::SAVED_UNVERIFIED, SettingsSave::UNCHANGED => ['refused' => false, 'text' => $verdict],
            SettingsSave::MISSING => ['refused' => true, 'text' => $this->language->get('error_key_missing')],
            SettingsSave::KEPT_REJECTED, SettingsSave::KEPT_UNVERIFIED => ['refused' => true, 'text' => trim($verdict . ' ' . $this->language->get('text_key_kept'))],
            default => ['refused' => true, 'text' => $this->language->get('error_' . $result['credentials'])],
        };
    }

    /** @param array<string, mixed> $json */
    private function json(array $json): void
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }
}
