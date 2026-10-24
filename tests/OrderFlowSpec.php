<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The hosted checkout flow against a scripted Two. Each case is Two's
 * answers, the outcome, and the requests that reached Two in order
 * ("METHOD /path").
 */
final class OrderFlowSpec
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';

    public static function runAll(): void
    {
        self::testCreate();
        self::testConfirm();
        self::testCancel();
        self::testNewToken();
    }

    private static function testCreate(): void
    {
        $created = self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED', 'status' => 'APPROVED', 'payment_url' => 'https://checkout.sandbox.two.inc/o/1']);
        $throws = ['m' => static function (array $p): array { throw new \RuntimeException('x'); }];
        $cases = [
            ['responses' => [$created], 'order' => [], 'handlers' => [], 'ok' => true, 'buyer' => null, 'calls' => ['POST /v1/order'], 'state' => 'UNVERIFIED', 'description' => 'created: the buyer goes to Two\'s payment URL'],
            ['responses' => [self::json(400, ['error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'bad']]])], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => ['error_two_field_phone_number'], 'calls' => ['POST /v1/order'], 'state' => '', 'description' => 'refused over a field: the field sentence'],
            ['responses' => [HttpResponse::unreachable('timeout')], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => ['POST /v1/order'], 'state' => '', 'description' => 'Two not reached: the generic sentence'],
            ['responses' => [self::json(201, ['id' => self::ID, 'state' => 'UNVERIFIED'])], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => ['POST /v1/order', 'POST /v1/order/' . self::ID . '/cancel'], 'state' => 'UNVERIFIED', 'description' => 'created without a payment URL: cancelled again'],
            ['responses' => [self::json(201, ['payment_url' => 'https://checkout.sandbox.two.inc/o/1'])], 'order' => [], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => ['POST /v1/order'], 'state' => '', 'description' => 'created without an order id: refused'],
            ['responses' => [], 'order' => ['company' => ['company_name' => 'Acme AS', 'organization_number' => ' ', 'country_code' => 'NO']], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::COMPANY_REQUIRED], 'calls' => [], 'state' => null, 'description' => 'no organisation number: asked for, nothing sent'],
            ['responses' => [], 'order' => ['total' => 300], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => null, 'description' => 'a failed shop-match check: nothing sent'],
            ['responses' => [$created], 'order' => ['total' => 300], 'handlers' => ['m' => static fn (array $p): array => $p], 'ok' => true, 'buyer' => null, 'calls' => ['POST /v1/order'], 'state' => 'UNVERIFIED', 'description' => 'a merchant handler owns the shop-match checks'],
            ['responses' => [], 'order' => [], 'handlers' => $throws, 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => null, 'description' => 'a faulting handler: nothing sent'],
            ['responses' => [], 'order' => ['currency_value' => 0], 'handlers' => [], 'ok' => false, 'buyer' => [ErrorMapper::GENERIC], 'calls' => [], 'state' => null, 'description' => 'an order that cannot be built: nothing sent'],
        ];
        foreach ($cases as $case) {
            $records = new MemoryRecords();
            [$flow, $transport] = self::flow($case['responses'], $records, $case['handlers']);
            $result = $flow->create($case['order'] + self::order(), [['name' => 'Widget', 'quantity' => 2, 'price' => 100, 'total' => 200, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']], [], self::TOKEN);
            $d = 'create: ' . $case['description'];
            TinyAssert::same($case['ok'], $result['ok'], $d . ': ok');
            TinyAssert::same($case['buyer'], $result['ok'] ? null : $result['buyer'], $d . ': buyer keys');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            TinyAssert::same($case['state'], $records->rows[42]['two_order_state'] ?? null, $d . ': stored state');
            if ($case['state'] !== null) {
                TinyAssert::same(OrderFlow::tokenHash(self::TOKEN), $records->rows[42]['token_hash'], $d . ': the token hash is stored, not the token');
            }
            if ($result['ok']) {
                TinyAssert::same('https://checkout.sandbox.two.inc/o/1', $result['redirect'], $d . ': redirect');
            }
        }
    }

    private static function testConfirm(): void
    {
        $get = static fn (string $state): HttpResponse => self::json(200, ['id' => self::ID, 'state' => $state, 'status' => 'APPROVED']);
        $paths = ['GET /v1/order/' . self::ID, 'POST /v1/order/' . self::ID . '/confirm'];
        $cases = [
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('VERIFIED'), self::json(200, ['state' => 'CONFIRMED'])], 'outcome' => OrderFlow::CONFIRMED, 'calls' => $paths, 'state' => 'CONFIRMED', 'description' => 'verified: confirmed with Two'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('CONFIRMED')], 'outcome' => OrderFlow::CONFIRMED, 'calls' => [$paths[0]], 'state' => 'CONFIRMED', 'description' => 'already confirmed: nothing more sent'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('CANCELLED')], 'outcome' => OrderFlow::CANCELLED, 'calls' => [$paths[0]], 'state' => 'CANCELLED', 'description' => 'cancelled at Two: the order is not confirmed'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('UNVERIFIED')], 'outcome' => OrderFlow::REFUSED, 'calls' => [$paths[0]], 'state' => 'UNVERIFIED', 'description' => 'not verified: the URL alone confirms nothing'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(500, [])], 'outcome' => OrderFlow::REFUSED, 'calls' => [$paths[0]], 'state' => '', 'description' => 'the state cannot be read'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [$get('VERIFIED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderFlow::REFUSED, 'calls' => $paths, 'state' => 'VERIFIED', 'description' => 'Two refuses the confirm'],
            ['row' => true, 'token' => str_repeat('b', 48), 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => '', 'description' => 'another token: unauthorised, nothing sent'],
            ['row' => true, 'token' => '', 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => '', 'description' => 'no token: unauthorised'],
            ['row' => false, 'token' => self::TOKEN, 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => null, 'description' => 'no record for the order: unauthorised'],
            ['row' => 'no-id', 'token' => self::TOKEN, 'responses' => [], 'outcome' => OrderFlow::REFUSED, 'calls' => [], 'state' => '', 'description' => 'no Two order was created: nothing to confirm'],
        ];
        self::runCallbackCases('confirm', $cases);
    }

    private static function testCancel(): void
    {
        $cancelled = self::json(200, ['id' => self::ID, 'state' => 'CANCELLED']);
        $paths = ['POST /v1/order/' . self::ID . '/cancel', 'GET /v1/order/' . self::ID];
        $cases = [
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(200, []), $cancelled], 'outcome' => OrderFlow::CANCELLED, 'calls' => $paths, 'state' => 'CANCELLED', 'description' => 'cancelled and read back as cancelled'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(200, []), self::json(200, ['state' => 'VERIFIED'])], 'outcome' => OrderFlow::REFUSED, 'calls' => $paths, 'state' => 'VERIFIED', 'description' => 'Two still holds it verified: not cancelled'],
            ['row' => true, 'token' => self::TOKEN, 'responses' => [self::json(409, [])], 'outcome' => OrderFlow::REFUSED, 'calls' => [$paths[0]], 'state' => '', 'description' => 'Two refuses the cancel'],
            ['row' => 'no-id', 'token' => self::TOKEN, 'responses' => [], 'outcome' => OrderFlow::CANCELLED, 'calls' => [], 'state' => '', 'description' => 'no Two order was created: nothing to cancel'],
            ['row' => true, 'token' => str_repeat('b', 48), 'responses' => [], 'outcome' => OrderFlow::UNAUTHORISED, 'calls' => [], 'state' => '', 'description' => 'another token: unauthorised, nothing sent'],
        ];
        self::runCallbackCases('cancel', $cases);
    }

    /** @param list<array<string, mixed>> $cases */
    private static function runCallbackCases(string $kind, array $cases): void
    {
        foreach ($cases as $case) {
            $rows = [];
            if ($case['row'] !== false) {
                $rows[42] = ['token_hash' => OrderFlow::tokenHash(self::TOKEN), 'two_order_id' => $case['row'] === 'no-id' ? '' : self::ID, 'two_order_state' => ''];
            }
            $records = new MemoryRecords($rows);
            [$flow, $transport] = self::flow($case['responses'], $records);
            $result = $kind === 'confirm' ? $flow->confirm(42, $case['token']) : $flow->cancel(42, $case['token']);
            $d = $kind . ': ' . $case['description'];
            TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            TinyAssert::same($case['state'], $records->rows[42]['two_order_state'] ?? null, $d . ': stored state');
        }
    }

    private static function testNewToken(): void
    {
        $token = OrderFlow::newToken();
        TinyAssert::true(preg_match('~^[0-9a-f]{48}\z~', $token) === 1, 'a token is 48 hex characters, the shape the callbacks accept');
        TinyAssert::true($token !== OrderFlow::newToken(), 'tokens are fresh each time');
    }

    /**
     * @param list<HttpResponse>                                       $responses
     * @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers
     * @return array{0: OrderFlow, 1: ScriptedTransport}
     */
    private static function flow(array $responses, MemoryRecords $records, array $handlers = []): array
    {
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $flow = new OrderFlow($client, new OrderBuilder(), new Postprocessing(new ScriptedSubscribers($handlers), $logger), new ErrorMapper(), $records, $logger);

        return [$flow, $transport];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
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
