<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\BuyerFields;
use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CompanyLookup;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * Checkout capture (Build Guide sections 1.1, 6.1 and 6.3): the server-side
 * order intent, the optional buyer fields, the company search relay and the
 * sole-trader token mint, against a scripted Two.
 */
final class CheckoutCaptureSpec
{
    private const SESSION = '0123456789abcdef0123456789abcdef';

    public static function runAll(): void
    {
        self::testIntent();
        self::testOptionalFields();
        self::testSearch();
        self::testSoleTrader();
        self::testKeys();
        self::testSettings();
    }

    private static function testIntent(): void
    {
        $cases = [
            [[self::json(200, ['approved' => true])], [], self::SESSION, OrderFlow::APPROVED, [], 1, 'approved'],
            [[self::json(200, ['approved' => false])], [], self::SESSION, OrderFlow::DECLINED, [], 1, 'declined'],
            [[HttpResponse::unreachable('timeout')], [], self::SESSION, OrderFlow::UNDECIDED, [], 1, 'no answer is no decision'],
            [[self::json(500, [])], [], self::SESSION, OrderFlow::UNDECIDED, [], 1, 'an error is no decision'],
            [[self::json(200, ['approved' => 'yes'])], [], self::SESSION, OrderFlow::UNDECIDED, [], 1, 'an answer without a boolean decision is none'],
            [[], [], '', OrderFlow::UNDECIDED, [], 0, 'no session: nothing sent, so no key is ever shared'],
            [[], [], 'not-a-hash', OrderFlow::UNDECIDED, [], 0, 'a session value that is not a hash: nothing sent'],
            [[], ['company' => ['company_name' => 'Acme AS', 'organization_number' => '', 'country_code' => 'NO']], self::SESSION, OrderFlow::REFUSED, [ErrorMapper::COMPANY_REQUIRED], 0, 'no organisation number: asked for, nothing sent'],
            [[], ['total' => 300], self::SESSION, OrderFlow::REFUSED, [ErrorMapper::GENERIC], 0, 'a failed shop-match check: nothing sent'],
        ];
        foreach ($cases as [$responses, $order, $session, $outcome, $buyer, $calls, $description]) {
            [$flow, $transport] = self::flow($responses);
            $result = $flow->intent($order + self::order(), self::lines(), [], $session, ['merchant_short_name' => 'acme']);
            TinyAssert::same([$outcome, $buyer, $calls], [$result['outcome'], $result['buyer'], count($transport->calls)], 'order intent: ' . $description);
        }

        [$flow, $transport] = self::flow([self::json(200, ['approved' => true]), self::json(200, ['approved' => true]), self::json(200, ['approved' => true])]);
        $flow->intent(self::order(), self::lines(), [], self::SESSION, ['merchant_short_name' => 'acme']);
        $sent = json_decode((string) $transport->calls[0]['body'], true);
        TinyAssert::same('/v1/order_intent', parse_url($transport->calls[0]['url'], PHP_URL_PATH), 'order intent: sent to the intent endpoint');
        TinyAssert::same([], array_diff(array_keys($sent), array_merge(OrderFlow::INTENT_KEYS, ['merchant_short_name'])), 'order intent: carries only what the intent asks about');
        TinyAssert::same(['acme', '123456789'], [$sent['merchant_short_name'], $sent['buyer']['company']['organization_number']], 'order intent: names the merchant and the buyer company');
        $flow->intent(self::order(), self::lines(), [], self::SESSION, ['merchant_short_name' => 'acme']);
        $flow->intent(['company' => ['company_name' => 'Other AS', 'organization_number' => '987654321', 'country_code' => 'NO']] + self::order(), self::lines(), [], self::SESSION, ['merchant_short_name' => 'acme']);
        $keys = array_map(static fn (array $call): ?string => IdempotencyKey::in($call['headers']), $transport->calls);
        TinyAssert::true(str_starts_with((string) $keys[0], 'intent_0123456789abcdef_'), 'order intent: keyed on the buyer\'s session');
        TinyAssert::same([true, false], [$keys[0] === $keys[1], $keys[1] === $keys[2]], 'order intent: the same question repeats its key, another company asks a new one');
    }

    private static function testOptionalFields(): void
    {
        $enabled = ['invoice_email', 'purchase_order_number', 'project', 'department'];
        $cases = [
            [['invoice_email' => ' ap@example.com ', 'project' => 'Roof'], $enabled, ['invoice_email' => 'ap@example.com', 'project' => 'Roof'], [], 'filled fields, trimmed'],
            [['invoice_email' => 'not-an-email'], $enabled, [], ['invoice_email'], 'an invoice email that is not one is refused'],
            [['department' => str_repeat('d', 256)], $enabled, [], ['department'], 'longer than 255 characters is refused'],
            [['purchase_order_number' => "PO\x01"], $enabled, [], ['purchase_order_number'], 'a control character is refused'],
            [['project' => 'Roof', 'department' => 'Ops'], ['department'], ['department' => 'Ops'], [], 'a field switched off is ignored'],
        ];
        foreach ($cases as [$posted, $on, $fields, $refused, $description]) {
            TinyAssert::same(['fields' => $fields, 'refused' => $refused], BuyerFields::fromPost($posted, $on), 'optional fields: ' . $description);
        }
        $fields = ['invoice_email' => 'ap@example.com', 'purchase_order_number' => 'PO-1', 'project' => 'Roof', 'department' => 'Ops'];
        TinyAssert::same($fields, BuyerFields::decode(BuyerFields::encode($fields)), 'optional fields: stored and read back');
        TinyAssert::same('', BuyerFields::encode(['project' => ' ']), 'optional fields: none stores nothing');

        $payload = (new OrderBuilder())->build(['buyer_fields' => $fields] + self::order(), self::lines(), []);
        TinyAssert::same(
            ['Ops', 'Roof', 'PO-1', ['invoice_emails' => ['ap@example.com']]],
            [$payload['buyer_department'], $payload['buyer_project'], $payload['buyer_purchase_order_number'], $payload['invoice_details']],
            'optional fields: on the order create payload'
        );
        $plain = (new OrderBuilder())->build(self::order(), self::lines(), []);
        TinyAssert::same([], array_intersect(['buyer_department', 'buyer_project', 'buyer_purchase_order_number', 'invoice_details'], array_keys($plain)), 'optional fields: none given, none sent');

        $records = new MemoryRecords();
        [$flow] = self::flow([self::json(201, ['id' => '5f1c2a7e-0000-4000-8000-000000000001', 'state' => 'UNVERIFIED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1'])], $records);
        $flow->create(['buyer_fields' => $fields] + self::order(), self::lines(), [], str_repeat('a', 48));
        TinyAssert::same($fields, BuyerFields::decode($records->rows[42]['buyer_fields'] ?? ''), 'optional fields: kept with the order for the confirm rebuild');
    }

    private static function testSearch(): void
    {
        $items = ['items' => [
            ['name' => 'Acme AS', 'national_identifier' => ['id' => '123456789'], 'highlight' => '<mark>Acme</mark> AS', 'id' => 'L1'],
            ['name' => 'Acme Ltd', 'company_number' => '01234567'],
            ['name' => ' ', 'national_identifier' => ['id' => '1']],
        ]];
        $cases = [
            [[self::json(200, $items)], 'acme', 'no', [
                ['name' => 'Acme AS', 'organization_number' => '123456789', 'highlight' => '<mark>Acme</mark> AS', 'lookup_id' => 'L1'],
                ['name' => 'Acme Ltd', 'organization_number' => '01234567', 'highlight' => '', 'lookup_id' => ''],
            ], ['/companies/v2/company?country=NO&q=acme&limit=50&offset=0'], 'results mapped, a nameless row dropped'],
            [[self::json(500, [])], 'acme', 'NO', null, ['/companies/v2/company?country=NO&q=acme&limit=50&offset=0'], 'a failed search is not an empty one'],
            [[self::json(200, ['unexpected' => true])], 'acme', 'NO', null, ['/companies/v2/company?country=NO&q=acme&limit=50&offset=0'], 'an answer without items is a failure'],
            [[], 'ac ', 'NO', [], [], 'a term under three characters asks nothing'],
            [[], 'acme', 'Norway', [], [], 'a country that is not a code asks nothing'],
        ];
        foreach ($cases as [$responses, $term, $country, $expected, $paths, $description]) {
            [$lookup, $transport] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->search($term, $country), 'company search: ' . $description);
            TinyAssert::same($paths, self::paths($transport), 'company search: ' . $description . ' (requests)');
        }
        $countries = [
            [[self::json(200, ['supported_countries' => ['gb', 'NO', 'x', 'GB']])], ['GB', 'NO'], 'the covered countries, uppercased and deduplicated'],
            [[self::json(503, [])], null, 'no answer is unknown, not none'],
        ];
        foreach ($countries as [$responses, $expected, $description]) {
            [$lookup] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->supportedCountries(), 'supported countries: ' . $description);
        }
    }

    private static function testSoleTrader(): void
    {
        $cases = [
            [[self::json(200, ['supported_company_types' => ['LIMITED_COMPANY', 'SOLE_TRADER']])], 'gb', true, 'a country the registry lists sole traders for'],
            [[self::json(200, ['supported_company_types' => ['LIMITED_COMPANY']])], 'NO', false, 'a business-only country'],
            [[HttpResponse::unreachable('timeout')], 'GB', null, 'no answer is unknown'],
            [[], 'GBR', false, 'not a country code: nothing asked'],
        ];
        foreach ($cases as [$responses, $country, $expected, $description]) {
            [$lookup] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->soleTraderAvailable($country), 'sole trader availability: ' . $description);
        }

        $minted = static fn (string $token): HttpResponse => new HttpResponse(201, '{}', ['two-delegated-authority-token' => $token]);
        $base = IdempotencyKey::soleTraderAttempt(self::SESSION, 2);
        $mints = [
            [[$minted('d1'), $minted('a1')], ['delegation_token' => 'd1', 'autofill_token' => 'a1'], 2, 'both tokens minted'],
            [[$minted('d1'), new HttpResponse(403, '{}')], null, 2, 'one mint refused: neither is handed out'],
            [[new HttpResponse(201, '{}')], null, 1, 'a mint answered without its token header: refused'],
        ];
        foreach ($mints as [$responses, $expected, $calls, $description]) {
            [$lookup, $transport] = self::lookup($responses);
            TinyAssert::same($expected, $lookup->soleTraderTokens($base), 'sole trader tokens: ' . $description);
            TinyAssert::same($calls, count($transport->calls), 'sole trader tokens: ' . $description . ' (requests)');
        }
        [$lookup, $transport] = self::lookup([$minted('d1'), $minted('a1')]);
        $lookup->soleTraderTokens($base);
        TinyAssert::same(
            [['/registry/v1/delegation', 'soletrader_delegation_0123456789abcdef_2', ['create_proposal' => true, 'read_current_business' => true]], ['/autofill/v1/delegation', 'soletrader_autofill_0123456789abcdef_2', ['read_current_buyer' => true, 'write_current_buyer' => true]]],
            array_map(static fn (array $call): array => [parse_url($call['url'], PHP_URL_PATH), IdempotencyKey::in($call['headers']), json_decode((string) $call['body'], true)], $transport->calls),
            'sole trader tokens: each mint keyed on the attempt, with its scopes'
        );
        TinyAssert::same('https://checkout.sandbox.two.inc/soletrader/signup', $lookup->signupUrl(), 'sole trader: the signup page for the environment');
    }

    private static function testKeys(): void
    {
        $refused = [
            [static fn () => IdempotencyKey::orderIntent('', str_repeat('a', 64)), 'an intent without a session'],
            [static fn () => IdempotencyKey::orderIntent('session-id', str_repeat('a', 64)), 'an intent keyed on a raw session id'],
            [static fn () => IdempotencyKey::soleTraderMint('other', 'x'), 'a token that is not one of the two'],
        ];
        foreach ($refused as [$call, $description]) {
            TinyAssert::throws($call, InvalidArgumentException::class, 'idempotency key refuses ' . $description);
        }
        TinyAssert::same('0123456789abcdef_1', IdempotencyKey::soleTraderAttempt(self::SESSION, 0), 'idempotency key: an attempt number below one counts as the first');
    }

    private static function testSettings(): void
    {
        $cases = [
            [[], true, BuyerFields::FIELDS, null, null, 'nothing stored: search in the address forms, every field on, no minimum'],
            [['company_search_in_address' => '0', 'enable_po_number' => '0', 'enable_department' => '0'], false, ['invoice_email', 'project'], null, null, 'the tile placement, two fields off'],
            [['min_order_amount' => '99.50', 'min_order_basis' => 'net'], true, BuyerFields::FIELDS, 99.5, 'net', 'a merchant minimum on net'],
            [['min_order_amount' => 'abc', 'min_order_basis' => 'both'], true, BuyerFields::FIELDS, null, null, 'unreadable minimum and basis are none'],
        ];
        foreach ($cases as [$stored, $address, $fields, $minimum, $basis, $description]) {
            $settings = new SettingsProvider(new MemoryStore($stored));
            TinyAssert::same(
                [$address, $fields, $minimum, $basis],
                [$settings->companySearchInAddressArea(), $settings->optionalFields(), $settings->merchantMinimumOrder(), $settings->merchantMinimumOrderBasis()],
                'checkout settings: ' . $description
            );
        }
    }

    /**
     * @param list<HttpResponse> $responses
     * @return array{0: OrderFlow, 1: ScriptedTransport}
     */
    private static function flow(array $responses, ?MemoryRecords $records = null): array
    {
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $flow = new OrderFlow(self::client($transport, $logger), new OrderBuilder(), new Postprocessing(new ScriptedSubscribers([]), $logger), new ErrorMapper(), $records ?? new MemoryRecords(), $logger);

        return [$flow, $transport];
    }

    /**
     * @param list<HttpResponse> $responses
     * @return array{0: CompanyLookup, 1: ScriptedTransport}
     */
    private static function lookup(array $responses): array
    {
        $transport = new ScriptedTransport($responses);
        $settings = new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox']));

        return [new CompanyLookup(self::client($transport, new RecordingLogger()), new Environment(false), $settings), $transport];
    }

    private static function client(ScriptedTransport $transport, RecordingLogger $logger): Client
    {
        return new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
    }

    /** @return list<string> path and query, without the client parameters */
    private static function paths(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => (string) preg_replace('~[?&]client=.*\z~', '', (string) parse_url($call['url'], PHP_URL_PATH) . '?' . (string) parse_url($call['url'], PHP_URL_QUERY)), $transport->calls);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /** @return list<array<string, mixed>> */
    private static function lines(): array
    {
        return [['name' => 'Widget', 'quantity' => 2, 'price' => 100, 'total' => 200, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']];
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return [
            'order_id' => 42, 'currency' => 'NOK', 'currency_value' => 1.0, 'total' => 250, 'tax_total' => 50, 'date_added' => '2026-10-24 10:00:00',
            'company' => ['company_name' => 'Acme AS', 'organization_number' => '123456789', 'country_code' => 'NO'],
            'buyer' => ['email' => 'buyer@example.com', 'first_name' => 'Kari', 'last_name' => 'Nordmann', 'phone_number' => '+4799999999'],
            'billing_address' => ['street_address' => 'Gate 1', 'postal_code' => '0150', 'city' => 'Oslo', 'region' => '', 'country' => 'NO'],
            'merchant_urls' => ['merchant_confirmation_url' => 'https://shop.example/confirm'],
        ];
    }
}
