<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderEdit;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;

/**
 * Wires the core for one OpenCart request: the shop's settings table, its
 * error log, cURL, the plugin version and the TWO_INTERNAL_ENVIRONMENTS flag.
 * The only place the adapter builds core objects, so every controller,
 * model and cron entry gets the same graph.
 */
final class Services
{
    /** The scheduled refresh's row in OpenCart's cron table. */
    public const CRON_CODE = 'two_record_refresh';
    public const CRON_ACTION = 'extension/two/cron/two';
    public const CRON_CYCLE = 'day';
    public const CRON_DESCRIPTION = 'Refreshes the cached Two merchant record.';

    /**
     * The order lifecycle's row in OpenCart's event table. Admin status
     * changes run the catalog's order model in a store instance, so this one
     * catalog event sees every status change: admin, storefront and the
     * extension's own.
     */
    public const EVENT_CODE = 'two_order_lifecycle';
    public const EVENT_TRIGGER = 'catalog/model/checkout/order.addHistory/after';
    public const EVENT_ACTION = 'extension/two/event/two.history';
    public const EVENT_DESCRIPTION = 'Sends order edits to Two, and fulfils, cancels or refunds the Two order when an order moves into a mapped status.';

    public readonly SettingsProvider $settings;
    public readonly Environment $environment;
    public readonly Client $client;
    public readonly ApiKeyVerdict $verdict;
    public readonly RecordProvider $records;
    public readonly SettingsSave $save;
    public readonly DbOrderRecords $orders;
    public readonly DbRefundRecords $refunds;
    private readonly LogLogger $logger;

    public function __construct(private readonly \Opencart\System\Library\DB $db, \Opencart\System\Library\Log $log, int $storeId = 0)
    {
        $this->settings = new SettingsProvider(new DbSettingsStore($db, $storeId));
        $this->environment = new Environment(self::internalEnvironmentsEnabled());
        $logger = $this->logger = new LogLogger($log, $this->settings->debugLogging());
        $this->client = new Client($this->environment, $this->settings, new CustomHeaders(), new CurlTransport(), $logger, self::pluginVersion());
        $this->verdict = new ApiKeyVerdict($this->client, $this->settings);
        $this->records = new RecordProvider($this->client, $this->settings, $this->verdict, $logger);
        $this->save = new SettingsSave($this->settings, $this->environment, $this->verdict, $this->records);
        $this->orders = new DbOrderRecords($db);
        $this->refunds = new DbRefundRecords($db);
    }

    /**
     * The hosted checkout flow. Every order request it sends passes through
     * the shop's order postprocessing event first.
     */
    public function orderFlow(\Opencart\System\Engine\Event $event): OrderFlow
    {
        $postprocessing = new Postprocessing(new EventSubscribers($this->db, $event), $this->logger);

        return new OrderFlow($this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);
    }

    /** Fulfil, cancel and the invoice download, sharing the checkout flow's Two reads and snapshot check. */
    public function orderLifecycle(\Opencart\System\Engine\Event $event): OrderLifecycle
    {
        $postprocessing = new Postprocessing(new EventSubscribers($this->db, $event), $this->logger);
        $flow = new OrderFlow($this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);

        return new OrderLifecycle($flow, $this->client, $postprocessing, new ErrorMapper(), $this->orders, $this->logger);
    }

    /** Refunds and the credit note. */
    public function orderRefunds(\Opencart\System\Engine\Event $event): OrderRefunds
    {
        return new OrderRefunds($this->client, new Postprocessing(new EventSubscribers($this->db, $event), $this->logger), new ErrorMapper(), $this->orders, $this->refunds, $this->logger);
    }

    /** Order-edit sync, sharing the checkout flow's rebuild and Two reads. */
    public function orderEdit(\Opencart\System\Engine\Event $event): OrderEdit
    {
        $postprocessing = new Postprocessing(new EventSubscribers($this->db, $event), $this->logger);
        $flow = new OrderFlow($this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);

        return new OrderEdit($flow, $this->client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $this->orders, $this->logger);
    }

    public static function internalEnvironmentsEnabled(): bool
    {
        $raw = getenv('TWO_INTERNAL_ENVIRONMENTS');

        return Environment::flagEnabled($raw === false ? null : $raw);
    }

    /** The version in install.json, the single source bumpver writes. */
    public static function pluginVersion(): string
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/install.json'), true);

        return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : '';
    }
}
