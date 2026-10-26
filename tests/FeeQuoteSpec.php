<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\FeeQuote;
use Opencart\System\Library\Extension\Two\Core\FxRates;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\PaymentTerms;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;
use Opencart\System\Library\Extension\Two\Core\Surcharge;

/**
 * Two's FX rates and the fee quote (Build Guide section 5): conversion at
 * Two's EUR-pivot rates with serve-stale, the quote request and its key,
 * failing closed, the charged term, and the gate's currency half.
 */
final class FeeQuoteSpec
{
    private const NOW = 1_800_000_000;
    /** One EUR is 11.5 NOK and 0.85 GBP. */
    private const TABLE = '{"base":"EUR","as_of":"2026-10-25","rates":{"EUR":1,"NOK":0.0869565,"GBP":1.1764706}}';
    private const GRID = '{"30":{"percentage":"2","fixed":"10","cap":"50"},"60":{"percentage":"3","fixed":"","cap":""}}';

    public static function runAll(): void
    {
        self::testConvert();
        self::testRefresh();
        self::testQuote();
        self::testCharged();
        self::testCurrencyFailure();
    }

    private static function testConvert(): void
    {
        $cases = [
            [[], 100.0, 'NOK', 'NOK', [], 100.0, 'the same currency needs no rate'],
            [[FxRates::CACHE_KEY => self::TABLE, FxRates::STAMP_KEY => (string) self::NOW], 100.0, 'GBP', 'NOK', [], 1352.94, 'through the EUR pivot, rounded once'],
            [[FxRates::CACHE_KEY => self::TABLE, FxRates::STAMP_KEY => (string) (self::NOW - FxRates::TTL - 1)], 100.0, 'GBP', 'NOK', [new HttpResponse(503, '')], 1352.94, 'a stale table is served when a refresh fails'],
            [[FxRates::STAMP_KEY => (string) self::NOW], 100.0, 'GBP', 'NOK', [], null, 'no table and no refresh due: no rate'],
            [[], 100.0, 'GBP', 'NOK', [new HttpResponse(200, self::TABLE)], 1352.94, 'a missing table is fetched on demand'],
            [[FxRates::CACHE_KEY => self::TABLE, FxRates::STAMP_KEY => (string) self::NOW], 100.0, 'GBP', 'SEK', [], null, 'a currency the table lacks, with no refresh due'],
        ];
        foreach ($cases as [$stored, $amount, $from, $to, $responses, $expected, $description]) {
            [$fx] = self::fx($stored, $responses);
            TinyAssert::same($expected, $fx->convert($amount, $from, $to), 'convert: ' . $description);
        }
    }

    private static function testRefresh(): void
    {
        [$fx, $store] = self::fx([FxRates::CACHE_KEY => self::TABLE], [new HttpResponse(200, '{"rates":{"SEK":0.0869,"NOK":"junk"}}')]);
        TinyAssert::true($fx->refreshIfDue(), 'refresh: a fresh table is stored');
        TinyAssert::same(['EUR' => 1.0, 'NOK' => 0.0869565, 'GBP' => 1.1764706, 'SEK' => 0.0869], $fx->table()['rates'] ?? null, 'refresh: merged over the cached table, junk rates never displace good ones');
        [$fx, $store] = self::fx([], [new HttpResponse(500, '')]);
        TinyAssert::true(!$fx->refreshIfDue(), 'refresh: a failed fetch stores nothing');
        TinyAssert::same((string) (self::NOW - FxRates::TTL + FxRates::BACKOFF), $store->values[FxRates::STAMP_KEY], 'refresh: a failure retries after the backoff, not a whole TTL');
    }

    private static function testQuote(): void
    {
        $ok = new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"25.5","total_fee":"40"}');
        $cases = [
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [$ok], ['net' => 25.5, 'currency' => 'NOK'], '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'the grid row, in the shop currency, needs no conversion'],
            [['surcharge_type' => 'fixed_and_percentage'], 'GBP', [new HttpResponse(200, '{"currency":"GBP","buyer_fee_share":"2"}')], ['net' => 2.0, 'currency' => 'GBP'], '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":0.74,"cap":3.7}', 'the fixed fee and cap converted at Two\'s rates'],
            [['surcharge_type' => 'fixed_and_percentage'], 'SEK', [], null, null, 'no FX rate fails closed, nothing sent'],
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [new HttpResponse(503, '')], null, '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'a failed quote is no quote'],
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [new HttpResponse(200, '{"currency":"EUR","buyer_fee_share":"2"}')], null, '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'an answer in another currency is refused, never converted again'],
            [['surcharge_type' => 'fixed_and_percentage'], 'NOK', [new HttpResponse(200, '{"currency":"NOK"}')], null, '{"percentage":2,"surcharge_basis":"buyer_pays","surcharge":10,"cap":50}', 'an answer with no fee is no quote'],
        ];
        foreach ($cases as [$settings, $currency, $responses, $expected, $share, $description]) {
            [$quotes, $transport, $settings] = self::quotes($settings, $responses);
            $quote = $quotes->quote(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30, 60]], $settings), 30, 1000.0, 'NO', $currency, 'NOK');
            TinyAssert::same($expected, $quote, 'quote: ' . $description);
            $body = json_decode((string) ($transport->calls[0]['body'] ?? 'null'), true);
            TinyAssert::same($share, $body === null ? null : json_encode($body['buyer_fee_share']), 'quote request: ' . $description);
            if ($body !== null) {
                TinyAssert::same(['currency' => $currency, 'gross_amount' => '1000.00', 'buyer_country_code' => 'NO', 'approved_on_recourse' => false, 'order_terms' => ['type' => 'NET_TERMS', 'duration_days' => 30]], array_diff_key($body, ['buyer_fee_share' => 1]), 'quote request shape: ' . $description);
                TinyAssert::same(IdempotencyKey::feeQuote(hash('sha256', (string) json_encode($body))), $transport->calls[0]['headers'][IdempotencyKey::HEADER] ?? null, 'quote key is the payload hash: ' . $description);
            }
        }
        [$quotes, $transport, $settings] = self::quotes(['surcharge_type' => 'percentage'], [new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"5"}')]);
        $terms = PaymentTerms::from(['available_terms' => [30]], $settings);
        $quotes->quote(Surcharge::from($settings), $terms, 30, 100.0, 'NO', 'NOK', 'NOK');
        $quotes->quote(Surcharge::from($settings), $terms, 30, 100.0, 'NO', 'NOK', 'NOK');
        TinyAssert::same(1, count($transport->calls), 'quote: the same request is asked once per request');
    }

    private static function testCharged(): void
    {
        $ok = new HttpResponse(200, '{"currency":"NOK","buyer_fee_share":"7"}');
        $cases = [
            [['surcharge_type' => 'percentage'], 60, 100.0, [$ok], ['applies' => true, 'days' => 60, 'net' => 7.0], 'the chosen term is quoted'],
            [['surcharge_type' => 'percentage'], 90, 100.0, [$ok], ['applies' => true, 'days' => 30, 'net' => 7.0], 'a term not offered falls back to the default'],
            [['surcharge_type' => 'none'], 30, 100.0, [], ['applies' => false, 'days' => 30, 'net' => null], 'no surcharge, no fee'],
            [['surcharge_type' => 'percentage'], 30, 0.0, [], ['applies' => false, 'days' => 30, 'net' => null], 'an empty cart is not quoted'],
            [['surcharge_type' => 'percentage', 'surcharge_grid' => '{"30":{"cap":"5"}}'], 30, 100.0, [], ['applies' => false, 'days' => 30, 'net' => null], 'a term that prices nothing is not quoted'],
            [['surcharge_type' => 'percentage'], 30, 100.0, [new HttpResponse(502, '')], ['applies' => true, 'days' => 30, 'net' => null], 'a fee that cannot be quoted'],
            [['surcharge_type' => 'tiered'], 30, 100.0, [], ['applies' => true, 'days' => 30, 'net' => null], 'an unknown stored method withholds, never charges nothing'],
        ];
        foreach ($cases as [$settings, $chosen, $gross, $responses, $expected, $description]) {
            [$quotes, , $settings] = self::quotes($settings, $responses);
            TinyAssert::same($expected, $quotes->charged(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30, 60]], $settings), $chosen, $gross, 'NO', 'NOK', 'NOK'), 'charged: ' . $description);
        }
    }

    private static function testCurrencyFailure(): void
    {
        $cases = [
            [['surcharge_type' => 'fixed'], 'SEK', true, 'a fixed fee with no rate into the cart currency fails every term'],
            [['surcharge_type' => 'percentage', 'surcharge_grid' => '{"30":{"percentage":"2"},"60":{"percentage":"3"}}'], 'SEK', false, 'a percentage-only grid needs no rate'],
            [['surcharge_type' => 'fixed'], 'NOK', false, 'the shop currency needs no rate'],
            [['surcharge_type' => 'fixed'], 'GBP', false, 'a rate Two holds'],
        ];
        foreach ($cases as [$settings, $currency, $fails, $description]) {
            [$quotes, , $settings] = self::quotes($settings, []);
            $failure = $quotes->currencyFailure(Surcharge::from($settings), PaymentTerms::from(['available_terms' => [30, 60]], $settings), $currency, 'NOK');
            TinyAssert::same($fails, $failure !== null, 'currency failure: ' . $description . ($failure === null ? '' : ' (' . $failure . ')'));
        }
    }

    /**
     * @param array<string, string> $stored
     * @param list<HttpResponse>    $responses
     * @return array{0: FxRates, 1: MemoryStore}
     */
    private static function fx(array $stored, array $responses): array
    {
        $store = new MemoryStore($stored + ['api_key' => 'k']);
        $settings = new SettingsProvider($store);
        $client = new Client(new Environment(false), $settings, new CustomHeaders(), new ScriptedTransport($responses), new RecordingLogger(), '1.0.0');

        return [new FxRates($client, $settings, new RecordingLogger(), static fn (): int => self::NOW), $store];
    }

    /**
     * @param array<string, string> $settings
     * @param list<HttpResponse>    $responses
     * @return array{0: FeeQuote, 1: ScriptedTransport, 2: SettingsProvider}
     */
    private static function quotes(array $settings, array $responses): array
    {
        $provider = new SettingsProvider(new MemoryStore($settings + ['api_key' => 'k', 'surcharge_grid' => self::GRID, FxRates::CACHE_KEY => self::TABLE, FxRates::STAMP_KEY => (string) self::NOW]));
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), $provider, new CustomHeaders(), $transport, $logger, '1.0.0');

        return [new FeeQuote($client, new FxRates($client, $provider, $logger, static fn (): int => self::NOW), $logger), $transport, $provider];
    }
}
