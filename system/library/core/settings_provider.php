<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Typed, validated view of the merchant's settings (Build Guide section 16,
 * the Magento layering). Reads go through the SettingsStore port, so this
 * class holds the rules and the adapter holds the storage.
 *
 * Getters land in P1, the full settings set in P2.
 */
final class SettingsProvider
{
    /**
     * Fresh-install defaults, unprefixed. The adapter seeds these on install
     * alongside its own platform keys.
     */
    public const DEFAULTS = [
        'environment' => Environment::DEFAULT,
        'api_key' => '',
    ];

    public function __construct(private readonly SettingsStore $store)
    {
    }

    public function apiKey(): string
    {
        throw NotImplemented::inPhase('SettingsProvider::apiKey', 'P1');
    }

    /** The stored environment value, unresolved. */
    public function environment(): string
    {
        throw NotImplemented::inPhase('SettingsProvider::environment', 'P1');
    }

    /** TLS verification is on unless the merchant switched it off (section 13.2). */
    public function verifyTls(): bool
    {
        throw NotImplemented::inPhase('SettingsProvider::verifyTls', 'P1');
    }

    public function debugLogging(): bool
    {
        throw NotImplemented::inPhase('SettingsProvider::debugLogging', 'P1');
    }

    /**
     * The stored custom request-header rows, unvalidated (section 3.5).
     *
     * @return list<array{name: string, value: string}>
     */
    public function customHeaderRows(): array
    {
        throw NotImplemented::inPhase('SettingsProvider::customHeaderRows', 'P1');
    }

    public function store(): SettingsStore
    {
        return $this->store;
    }
}
