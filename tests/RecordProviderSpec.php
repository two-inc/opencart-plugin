<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The merchant-record cache: no expiry, never evicted by a failed refresh,
 * bound to the credentials it was fetched for. Each case is what is cached,
 * what Two answers to the key check and the record fetch, and what is served
 * afterwards.
 */
final class RecordProviderSpec
{
    private const NOW = 1_800_000_000;
    private const OLD = ['id' => 'm1', 'available_terms' => [30]];
    private const NEW = ['id' => 'm1', 'available_terms' => [14, 30]];

    public static function runAll(): void
    {
        $ok = new HttpResponse(200, '{"id":"m1"}');
        $record = new HttpResponse(200, (string) json_encode(self::NEW));
        $cases = [
            ['cached' => null, 'responses' => [$ok, $record], 'refreshed' => true, 'served' => self::NEW, 'calls' => 2, 'description' => 'a cold cache is filled'],
            ['cached' => self::OLD, 'responses' => [$ok, $record], 'refreshed' => true, 'served' => self::NEW, 'calls' => 2, 'description' => 'a refresh replaces the record'],
            ['cached' => self::OLD, 'responses' => [new HttpResponse(401, '')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 1, 'description' => 'a rejected key keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [HttpResponse::unreachable('timeout')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 1, 'description' => 'no answer keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [$ok, new HttpResponse(500, '')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 2, 'description' => 'a failed fetch keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [$ok, new HttpResponse(200, '<html>portal</html>')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 2, 'description' => 'a 200 that is not JSON keeps the cached record'],
            ['cached' => self::OLD, 'responses' => [$ok, new HttpResponse(200, '{"unrelated":true}')], 'refreshed' => false, 'served' => self::OLD, 'calls' => 2, 'description' => 'a 200 answering none of the record fields keeps the cached record'],
            ['cached' => null, 'responses' => [new HttpResponse(503, '')], 'refreshed' => false, 'served' => null, 'calls' => 1, 'description' => 'a cold cache stays empty on failure'],
        ];
        foreach ($cases as $case) {
            [$provider, $transport] = self::provider(['api_key' => 'k'], $case['responses'], $case['cached']);
            TinyAssert::same($case['refreshed'], $provider->refresh(), $case['description']);
            TinyAssert::same($case['served'], $provider->record(), $case['description'] . ' (served)');
            TinyAssert::same($case['calls'], count($transport->calls), $case['description'] . ' (calls)');
        }

        self::testRecordIsReadByTheKeysMerchant();
        self::testRecordIsBoundToCredentials();
        self::testScheduledRefresh();
    }

    private static function testRecordIsReadByTheKeysMerchant(): void
    {
        [$provider, $transport] = self::provider(['api_key' => 'k'], [new HttpResponse(200, '{"id":"m 1/x"}'), new HttpResponse(200, '{"id":"m 1/x"}')], null);
        $provider->refresh();
        TinyAssert::same('https://api.sandbox.two.inc/v1/merchant/m%201%2Fx?client=OC&client_v=1.0.0', $transport->calls[1]['url'], 'the record is fetched by the id the key resolved, encoded');
    }

    private static function testRecordIsBoundToCredentials(): void
    {
        [$provider, , $store] = self::provider(['api_key' => 'k'], [], self::OLD);
        $store->values['api_key'] = 'another';
        TinyAssert::same(null, $provider->record(), 'a record fetched for another key is not served');
        $store->values['api_key'] = 'k';
        TinyAssert::same(self::OLD, $provider->record(), 'nor is it dropped: the original key still reads it');
        $store->values['environment'] = 'production';
        TinyAssert::same(null, $provider->record(), 'a record fetched in another environment is not served');
    }

    private static function testScheduledRefresh(): void
    {
        $cases = [
            ['stored' => [], 'responses' => [], 'result' => RecordProvider::CRON_UNCONFIGURED, 'stamped' => null, 'description' => 'no key: nothing to refresh, no stamp'],
            ['stored' => ['api_key' => 'k'], 'responses' => [new HttpResponse(200, '{"id":"m1"}'), new HttpResponse(200, '{"id":"m1"}')], 'result' => RecordProvider::CRON_REFRESHED, 'stamped' => (string) self::NOW, 'description' => 'a run that refreshes is stamped'],
            ['stored' => ['api_key' => 'k'], 'responses' => [new HttpResponse(503, '')], 'result' => RecordProvider::CRON_FAILED, 'stamped' => (string) self::NOW, 'description' => 'a run that fails is stamped too (the schedule is running)'],
        ];
        foreach ($cases as $case) {
            [$provider, , $store] = self::provider($case['stored'], $case['responses'], null);
            TinyAssert::same($case['result'], $provider->runScheduledRefresh(), 'cron: ' . $case['description']);
            TinyAssert::same($case['stamped'], $store->get(RecordProvider::CRON_LAST_RUN_KEY), 'cron: ' . $case['description'] . ' (stamp)');
        }
    }

    /**
     * @param array<string, string>     $stored
     * @param list<HttpResponse>        $responses
     * @param array<string, mixed>|null $cached
     * @return array{0: RecordProvider, 1: ScriptedTransport, 2: MemoryStore}
     */
    private static function provider(array $stored, array $responses, ?array $cached): array
    {
        $store = new MemoryStore($stored);
        $settings = new SettingsProvider($store);
        if ($cached !== null) {
            $store->values[RecordProvider::CACHE_KEY] = (string) json_encode(['fingerprint' => $settings->credentialFingerprint(), 'fetched_at' => self::NOW - 86400 * 30, 'record' => $cached]);
        }
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), $transport, $logger, '1.0.0');
        $clock = static fn (): int => self::NOW;

        return [new RecordProvider($client, $settings, new ApiKeyVerdict($client, $settings, $clock), $logger, $clock), $transport, $store];
    }
}
