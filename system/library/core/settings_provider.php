<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Typed, validated view of the merchant's settings (Build Guide section 16,
 * the Magento layering). Reads go through the SettingsStore port, so this
 * class holds the rules and the adapter holds the storage.
 *
 * The getters the client and caches need land in P1, the full settings set
 * in P2.
 */
final class SettingsProvider
{
    /**
     * Fresh-install defaults, unprefixed. The adapter seeds these on install
     * alongside its own platform keys. TLS verification is on and debug
     * logging off unless the merchant changes them on the Diagnostics tab.
     */
    public const DEFAULTS = [
        'environment' => Environment::DEFAULT,
        'api_key' => '',
        'disable_ssl_verify' => '0',
        'debug_mode' => '0',
        'custom_headers' => '[]',
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

    private static function isOn(?string $value): bool
    {
        return trim((string) $value) === '1';
    }
}
