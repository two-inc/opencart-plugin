<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The merchant record from Two (Build Guide sections 16 and 18): terms grid,
 * buyer countries, surcharge settings. Cached through the SettingsStore with
 * no expiry, refreshed by the scheduled job and on settings save, so checkout
 * never waits on Two for it.
 *
 * - The cache is bound to the credential fingerprint: a record fetched for
 *   other credentials is not served, but it is not dropped either.
 * - A failed refresh, on any path, leaves the cached record in place. Only a
 *   successful fetch replaces it.
 * - A 200 that answers none of the record's questions (a captive portal, a
 *   proxy page) is a failed fetch, never a merchant with no restrictions.
 *
 * Readers of individual fields (terms, buyer countries, minimum order) land
 * with the phases that use them (P6, P7); this class holds the record whole.
 */
final class RecordProvider
{
    public const CACHE_KEY = 'merchant_record';
    public const CRON_LAST_RUN_KEY = 'cron_last_run';
    public const MERCHANT_PATH = '/v1/merchant/';
    public const TIMEOUT_SECONDS = 10;

    public const CRON_REFRESHED = 'refreshed';
    public const CRON_FAILED = 'failed';
    public const CRON_UNCONFIGURED = 'unconfigured';

    /** Root fields of which at least one must be present for a body to be the record. */
    private const RECORD_FIELDS = ['id', 'available_terms', 'due_in_days', 'invoice_distributed_by_merchant', 'min_order_amount', 'supported_buyer_countries'];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Client $client,
        private readonly SettingsProvider $settings,
        private readonly ApiKeyVerdict $verdict,
        private readonly Logger $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The cached record for the credentials in force, or null before the
     * first successful fetch for them.
     *
     * @return array<string, mixed>|null
     */
    public function record(): ?array
    {
        $slot = $this->slot();

        return $slot !== null && $slot['fingerprint'] === $this->settings->credentialFingerprint() ? $slot['record'] : null;
    }

    /** When the record served by record() was fetched, or null when none is. */
    public function fetchedAt(): ?int
    {
        $slot = $this->slot();

        return $slot !== null && $slot['fingerprint'] === $this->settings->credentialFingerprint() ? $slot['fetched_at'] : null;
    }

    /**
     * Fetches the record from Two and replaces the cache. The key names its
     * merchant (the verification call, which also refreshes the heartbeat);
     * the record is then read by that id. Whether the cache was replaced.
     */
    public function refresh(): bool
    {
        if ($this->settings->apiKey() === '') {
            return false;
        }
        $fingerprint = $this->settings->credentialFingerprint();

        $verified = $this->verdict->verify(self::TIMEOUT_SECONDS);
        $merchantId = is_scalar($verified['merchant']['id'] ?? null) ? trim((string) $verified['merchant']['id']) : '';
        if ($merchantId === '') {
            $this->logger->error('Two merchant record not refreshed: the API key resolved no merchant (' . $verified['status'] . '). The cached record stays in use.');

            return false;
        }

        $response = $this->client->request('GET', self::MERCHANT_PATH . rawurlencode($merchantId), null, self::TIMEOUT_SECONDS);
        $record = $response->json();
        if ($response->status !== 200 || $record === null || !self::isRecord($record)) {
            $this->logger->error('Two merchant record refresh failed (HTTP ' . $response->status . '). The cached record stays in use.');

            return false;
        }
        // The merchant may have saved other credentials while the calls were out.
        if ($fingerprint !== $this->settings->credentialFingerprint()) {
            $this->logger->error('Two merchant record not refreshed: the credentials changed during the refresh.');

            return false;
        }

        $this->settings->store()->setMany([self::CACHE_KEY => (string) json_encode([
            'fingerprint' => $fingerprint,
            'fetched_at' => ($this->clock)(),
            'record' => $record,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        return true;
    }

    /**
     * The scheduled refresh's entry point (the shop's cron calls it): stamps
     * the run, so the admin can tell a schedule that stopped calling from one
     * that calls and fails, then refreshes. One of the CRON_* values.
     */
    public function runScheduledRefresh(): string
    {
        if ($this->settings->apiKey() === '') {
            return self::CRON_UNCONFIGURED;
        }
        $this->settings->store()->setMany([self::CRON_LAST_RUN_KEY => (string) ($this->clock)()]);

        return $this->refresh() ? self::CRON_REFRESHED : self::CRON_FAILED;
    }

    /** @param array<string, mixed> $body */
    private static function isRecord(array $body): bool
    {
        return array_intersect(self::RECORD_FIELDS, array_keys($body)) !== [];
    }

    /** @return array{fingerprint: string, fetched_at: int, record: array<string, mixed>}|null */
    private function slot(): ?array
    {
        $slot = json_decode((string) $this->settings->store()->get(self::CACHE_KEY), true);
        if (!is_array($slot) || !is_string($slot['fingerprint'] ?? null) || !is_array($slot['record'] ?? null)) {
            return null;
        }

        return [
            'fingerprint' => $slot['fingerprint'],
            'fetched_at' => is_int($slot['fetched_at'] ?? null) ? $slot['fetched_at'] : 0,
            'record' => $slot['record'],
        ];
    }
}
