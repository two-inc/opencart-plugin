<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\ApiKeyVerdict;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\RecordProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\SettingsSave;

/**
 * The selective admin save. The first table pins the credentials: what is
 * stored (and whether it last verified), what is submitted, what Two answers,
 * and which pair is stored afterwards. The second pins the other fields.
 */
final class SettingsSaveSpec
{
    private const NOW = 1_800_000_000;
    private const GOOD = 'good-stored-key';
    private const STATUSES = [1, 3, 5, 7, 11];

    public static function runAll(): void
    {
        self::testCredentials();
        self::testFields();
    }

    private static function testCredentials(): void
    {
        $ok = new HttpResponse(200, '{"id":"m1","short_name":"shop"}');
        $record = new HttpResponse(200, '{"id":"m1","available_terms":[30]}');
        $rejected = new HttpResponse(401, '');
        $down = HttpResponse::unreachable('timeout');
        $good = ['api_key' => self::GOOD, 'environment' => 'sandbox'];
        // [stored, last verdict of the stored pair, submitted key, submitted environment, Two's answers, outcome, stored key and environment after, calls, description]
        $cases = [
            [[], null, 'new-key', 'sandbox', [$ok, $ok, $record], SettingsSave::SAVED, ['new-key', 'sandbox'], 3, 'a first key Two accepts is stored, and the record fetched'],
            [$good, 'ok', 'new-key', 'production', [$ok, $ok, $record], SettingsSave::SAVED, ['new-key', 'production'], 3, 'a new pair Two accepts replaces a good one'],
            [$good, 'ok', 'bad-key', 'sandbox', [$rejected], SettingsSave::KEPT_REJECTED, [self::GOOD, 'sandbox'], 1, 'a rejected key keeps the stored good key'],
            [[], null, 'bad-key', 'sandbox', [$rejected], SettingsSave::KEPT_REJECTED, [null, null], 1, 'a rejected key is never stored, even with nothing stored'],
            [$good, 'ok', 'new-key', 'sandbox', [$down], SettingsSave::KEPT_UNVERIFIED, [self::GOOD, 'sandbox'], 1, 'a key Two could not judge keeps the stored good key'],
            [$good, 'ok', 'new-key', 'sandbox', [new HttpResponse(503, '')], SettingsSave::KEPT_UNVERIFIED, [self::GOOD, 'sandbox'], 1, 'a 5xx keeps the stored good key'],
            [$good, 'invalid_key', 'new-key', 'sandbox', [$down], SettingsSave::SAVED_UNVERIFIED, ['new-key', 'sandbox'], 1, 'a key Two could not judge replaces a stored key that was rejected'],
            [[], null, 'new-key', 'sandbox', [$down], SettingsSave::SAVED_UNVERIFIED, ['new-key', 'sandbox'], 1, 'a key Two could not judge is stored when there is none'],
            [$good, 'ok', 'bad key', 'sandbox', [], SettingsSave::KEPT_MALFORMED, [self::GOOD, 'sandbox'], 0, 'a key with a space is refused before the wire'],
            [$good, 'ok', "key\r\nX-Other: 1", 'sandbox', [], SettingsSave::KEPT_MALFORMED, [self::GOOD, 'sandbox'], 0, 'a key with CR LF is refused before the wire'],
            [$good, 'ok', 'caf' . "\u{e9}", 'sandbox', [], SettingsSave::KEPT_MALFORMED, [self::GOOD, 'sandbox'], 0, 'a non-ASCII key is refused before the wire'],
            [$good, 'ok', '', 'sandbox', [$ok], SettingsSave::UNCHANGED, [self::GOOD, 'sandbox'], 1, 'an empty key field keeps the stored key, which is verified again'],
            [$good, 'ok', ' ' . self::GOOD . ' ', 'sandbox', [$rejected], SettingsSave::UNCHANGED, [self::GOOD, 'sandbox'], 1, 'the stored key typed again is unchanged'],
            [$good, 'ok', '', 'production', [$ok, $ok, $record], SettingsSave::SAVED, [self::GOOD, 'production'], 3, 'a new environment Two accepts the key in is stored'],
            [$good, 'ok', '', 'production', [$rejected], SettingsSave::KEPT_REJECTED, [self::GOOD, 'sandbox'], 1, 'a new environment the key is rejected in keeps both'],
            [$good, 'ok', 'new-key', 'staging', [], SettingsSave::KEPT_ENVIRONMENT, [self::GOOD, 'sandbox'], 0, 'staging on a shop without the flag keeps both'],
            [$good, 'ok', 'new-key', 'bogus', [], SettingsSave::KEPT_ENVIRONMENT, [self::GOOD, 'sandbox'], 0, 'an unknown environment keeps both'],
            [[], null, '', 'production', [], SettingsSave::MISSING, [null, 'production'], 0, 'no key at all keeps the environment chosen'],
            [$good, 'ok', null, null, [], null, [self::GOOD, 'sandbox'], 0, 'a form without the credentials leaves them alone'],
        ];
        foreach ($cases as [$stored, $last, $key, $environment, $responses, $outcome, $after, $calls, $description]) {
            [$save, $store, $transport] = self::save($stored, $last, $responses);
            $submitted = ['title' => [1 => 'Pay later']];
            if ($environment !== null) {
                $submitted += ['api_key' => (string) $key, 'environment' => $environment];
            }
            $result = $save->save($submitted, self::STATUSES);
            TinyAssert::same($outcome, $result['credentials'], $description);
            TinyAssert::same($after, [$store->get('api_key'), $store->get('environment')], $description . ' (stored pair)');
            TinyAssert::same($calls, count($transport->calls), $description . ' (calls)');
            TinyAssert::same('{"1":"Pay later"}', $store->get('title'), $description . ' (the other fields are saved)');
            if ($calls > 0 && $outcome !== SettingsSave::UNCHANGED) {
                $host = (new Environment(false))->apiBaseUrl($environment);
                TinyAssert::true(str_starts_with($transport->calls[0]['url'], $host . ApiKeyVerdict::VERIFY_PATH . '?'), $description . ' (checked in the submitted environment)');
                TinyAssert::same(trim((string) $key) === '' ? self::GOOD : trim((string) $key), $transport->calls[0]['headers']['X-API-Key'], $description . ' (checked with the submitted key)');
            }
        }
    }

    private static function testFields(): void
    {
        // [submitted, errors, stored after (null = not written), description]
        $cases = [
            [['title' => [1 => ' Pay later ', 2 => 'Faktura']], [], ['title' => '{"1":"Pay later","2":"Faktura"}'], 'every title is stored, trimmed'],
            [['title' => [1 => 'Pay later', 2 => ' ']], ['title' => SettingsSave::TITLE_MISSING], ['title' => null], 'a blank title in any language keeps the stored titles'],
            [['fulfil_status_ids' => ['', '3', '5', '3']], [], ['fulfil_status_ids' => '[3,5]'], 'the fulfilment map is stored once per status, the empty marker dropped'],
            [['fulfil_status_ids' => ['']], [], ['fulfil_status_ids' => '[]'], 'an empty fulfilment map is stored: never fulfil automatically'],
            [['fulfil_status_ids' => ['3', '99']], ['fulfil_status_ids' => SettingsSave::STATUS_UNKNOWN], ['fulfil_status_ids' => null], 'a status the shop does not have is refused'],
            [['cancel_status_id' => '7', 'refund_status_id' => '11'], [], ['cancel_status_id' => '7', 'refund_status_id' => '11'], 'the cancel and refund statuses are stored'],
            [['cancel_status_id' => '', 'refund_status_id' => '0'], ['cancel_status_id' => SettingsSave::STATUS_MISSING, 'refund_status_id' => SettingsSave::STATUS_MISSING], ['cancel_status_id' => null, 'refund_status_id' => null], 'the cancel and refund statuses are required'],
            [['cancel_status_id' => '99', 'refund_status_id' => '11'], ['cancel_status_id' => SettingsSave::STATUS_UNKNOWN], ['cancel_status_id' => null, 'refund_status_id' => '11'], 'one bad field does not stop the others'],
        ];
        foreach ($cases as [$submitted, $errors, $after, $description]) {
            [$save, $store] = self::save([], null, []);
            $result = $save->save($submitted, self::STATUSES);
            TinyAssert::same($errors, $result['errors'], 'fields: ' . $description);
            foreach ($after as $key => $value) {
                TinyAssert::same($value, $store->get($key), 'fields: ' . $description . ' (' . $key . ')');
            }
        }
    }

    /**
     * @param array<string, string> $stored
     * @param list<HttpResponse>    $responses
     * @return array{0: SettingsSave, 1: MemoryStore, 2: ScriptedTransport}
     */
    private static function save(array $stored, ?string $last, array $responses): array
    {
        $store = new MemoryStore($stored);
        $settings = new SettingsProvider($store);
        if ($last !== null) {
            $store->values[ApiKeyVerdict::CACHE_KEY] = (string) json_encode(['status' => $last, 'code' => 200, 'fingerprint' => $settings->credentialFingerprint(), 'claim' => false, 'verified_on' => self::NOW - 86_400, 'checked_on' => self::NOW - 86_400]);
        }
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $environment = new Environment(false);
        $client = new Client($environment, $settings, new CustomHeaders(), $transport, $logger, '1.0.0');
        $clock = static fn (): int => self::NOW;
        $verdict = new ApiKeyVerdict($client, $settings, $clock);

        return [new SettingsSave($settings, $environment, $verdict, new RecordProvider($client, $settings, $verdict, $logger, $clock)), $store, $transport];
    }
}
