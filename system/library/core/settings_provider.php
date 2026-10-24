<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Typed, validated view of the merchant's settings (Build Guide section 16,
 * the Magento layering). Reads go through the SettingsStore port, so this
 * class holds the rules and the adapter holds the storage.
 *
 * Holds the settings the core flow uses; the rest of the merchant's set
 * lands with the phases that read it. Writes from the admin go through
 * SettingsSave, which holds the save rules.
 */
final class SettingsProvider
{
    /**
     * Fresh-install defaults, unprefixed. The adapter seeds these on install
     * alongside its own platform keys. TLS verification is on and debug
     * logging off unless the merchant changes them on the Diagnostics tab.
     * The status maps hold order status ids, which only the shop knows, so
     * the adapter seeds them by status name (DEFAULT_STATUS_NAMES); these are
     * the values when no such status exists.
     */
    public const DEFAULTS = [
        'environment' => Environment::DEFAULT,
        'api_key' => '',
        'title' => '{}',
        'fulfil_status_ids' => '[]',
        'cancel_status_id' => '0',
        'refund_status_id' => '0',
        'disable_ssl_verify' => '0',
        'debug_mode' => '0',
        'custom_headers' => '[]',
    ];

    /** The checkout title in every language the merchant has not set one for. */
    public const DEFAULT_TITLE = 'Business invoice';

    /** The shop's own status names the maps start on: fulfil on Shipped, and so on. */
    public const DEFAULT_STATUS_NAMES = [
        'fulfil_status_ids' => 'Shipped',
        'cancel_status_id' => 'Canceled',
        'refund_status_id' => 'Refunded',
    ];

    public function __construct(private readonly SettingsStore $store)
    {
    }

    public function apiKey(): string
    {
        return trim((string) $this->store->get('api_key'));
    }

    /** The stored environment value, unresolved; null when nothing is stored. */
    public function environment(): ?string
    {
        return $this->store->get('environment');
    }

    /** The payment method title at checkout in one language, trimmed, or DEFAULT_TITLE when it has none. */
    public function title(int $languageId): string
    {
        $titles = json_decode((string) $this->store->get('title'), true);
        $title = is_array($titles) && is_scalar($titles[(string) $languageId] ?? null) ? trim((string) $titles[(string) $languageId]) : '';

        return $title === '' ? self::DEFAULT_TITLE : $title;
    }

    /**
     * The order statuses that fulfil the order with Two. Empty is a real
     * setting: the shop never fulfils automatically.
     *
     * @return list<int>
     */
    public function fulfilStatusIds(): array
    {
        $decoded = json_decode((string) $this->store->get('fulfil_status_ids'), true);
        $ids = [];
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** The order status that cancels the order with Two, or null when none is mapped. */
    public function cancelStatusId(): ?int
    {
        return self::statusId($this->store->get('cancel_status_id'));
    }

    /** The order status that refunds the order in full with Two (used from P5), or null when none is mapped. */
    public function refundStatusId(): ?int
    {
        return self::statusId($this->store->get('refund_status_id'));
    }

    /** TLS verification is on unless the merchant switched it off (section 13.2). */
    public function verifyTls(): bool
    {
        return !self::isOn($this->store->get('disable_ssl_verify'));
    }

    public function debugLogging(): bool
    {
        return self::isOn($this->store->get('debug_mode'));
    }

    /**
     * The stored custom request-header rows, shape-checked but otherwise
     * unvalidated (section 3.5): CustomHeaders decides what may be sent.
     *
     * @return list<array{name: string, value: string}>
     */
    public function customHeaderRows(): array
    {
        $decoded = json_decode((string) $this->store->get('custom_headers'), true);
        $rows = [];
        foreach (is_array($decoded) ? $decoded : [] as $row) {
            if (is_array($row) && is_scalar($row['name'] ?? null) && is_scalar($row['value'] ?? null)) {
                $rows[] = ['name' => (string) $row['name'], 'value' => (string) $row['value']];
            }
        }

        return $rows;
    }

    /**
     * Identity of the credentials in force: the API key and the environment it
     * is used against. Cached verdicts and the merchant record are bound to it,
     * so a new key or environment, by any route, reads as a cold cache. Never
     * logged or shown; a one-way hash so the stored cache never holds the key.
     */
    public function credentialFingerprint(): string
    {
        return hash('sha256', $this->apiKey() . '|' . (Environment::recognise($this->environment()) ?? Environment::SANDBOX));
    }

    public function store(): SettingsStore
    {
        return $this->store;
    }

    private static function statusId(?string $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function isOn(?string $value): bool
    {
        return trim((string) $value) === '1';
    }
}
