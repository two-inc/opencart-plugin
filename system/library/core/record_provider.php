<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The merchant record from Two (Build Guide sections 16 and 18): terms grid,
 * buyer countries, surcharge settings. Cached through the SettingsStore with
 * no expiry, refreshed by the scheduled job and on settings save, so checkout
 * never waits on Two for it.
 *
 * Behaviour lands in P1.
 */
final class RecordProvider
{
    public const CACHE_KEY = 'merchant_record';

    public function __construct(
        private readonly Client $client,
        private readonly SettingsStore $store,
    ) {
    }

    /**
     * The cached record, or null before the first successful fetch.
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        throw NotImplemented::inPhase('RecordProvider::record', 'P1');
    }

    /** Fetches the record from Two and replaces the cache. */
    public function refresh(): void
    {
        throw NotImplemented::inPhase('RecordProvider::refresh', 'P1');
    }
}
