<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\OrderBuilder;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * Whole-order fulfil and cancel off the status maps, and the invoice
 * download, against a scripted Two. Each case is the stored Two state,
 * Two's answers, the outcome, the requests that reached Two in order
 * ("METHOD /path") and the state stored afterwards.
 */
final class OrderLifecycleSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';

    public static function runAll(): void
    {
        self::testAction();
        self::testFulfil();
        self::testCancel();
        self::testInvoice();
    }

    private static function testAction(): void
    {
        $cases = [
            [3, [3, 5], 7, OrderLifecycle::FULFIL, 'a mapped fulfilment status'],
            [5, [3, 5], 7, OrderLifecycle::FULFIL, 'the second mapped fulfilment status'],
            [7, [3, 5], 7, OrderLifecycle::CANCEL, 'the mapped cancel status'],
            [3, [3], 3, OrderLifecycle::FULFIL, 'a status in both maps fulfils'],
            [2, [3, 5], 7, null, 'an unmapped status asks nothing'],
            [7, [3], null, null, 'no cancel status mapped'],
            [0, [0], 0, null, 'status 0, an unplaced order, never acts'],
        ];
        foreach ($cases as [$status, $fulfil, $cancel, $expected, $description]) {
            TinyAssert::same($expected, OrderLifecycle::action($status, $fulfil, $cancel), 'action: ' . $description);
        }
    }

    private static function testFulfil(): void
    {
        $get = self::get(...);
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/fulfillments';
        $throws = ['m' => static function (array $p, array $c): array {
            if ($c['request_type'] === Postprocessing::CAPTURE) {
                throw new \RuntimeException('x');
            }

            return $p;
        }];
        $cases = [
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), self::json(201, []), $get('FULFILLED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => 'FULFILLED', 'description' => 'confirmed: fulfilled and read back'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), self::json(201, []), self::json(500, [])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => 'FULFILLING', 'description' => 'fulfilled, the read back fails: marked as asked for, so a repeat skips'],
            ['stored' => 'FULFILLED', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'FULFILLED', 'description' => 'already fulfilled: a repeat event or re-save sends nothing'],
            ['stored' => 'FULFILLING', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'FULFILLING', 'description' => 'fulfilment already asked for: nothing sent'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('FULFILLED')], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [$read], 'state' => 'FULFILLED', 'description' => 'fulfilled at Two already (stored state stale): nothing sent'],
            ['stored' => 'CANCELLED', 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'CANCELLED', 'description' => 'cancelled: refused, nothing sent'],
            ['stored' => null, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => null, 'description' => 'no Two order linked: refused, nothing sent'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(500, [])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'CONFIRMED', 'description' => 'Two\'s state cannot be read: refused, nothing sent'],
            ['stored' => 'VERIFIED', 'responses' => [$get('VERIFIED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'VERIFIED', 'description' => 'not confirmed at Two: refused'],
            ['stored' => 'CONFIRMED', 'rebuild' => 3, 'responses' => [$get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'CONFIRMED', 'description' => 'the order changed since create: refused, nothing sent'],
            ['stored' => 'CONFIRMED', 'rebuild' => null, 'responses' => [$get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'CONFIRMED', 'description' => 'the shop order cannot be rebuilt: refused'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'state' => 'CONFIRMED', 'description' => 'Two refuses the fulfil: refused, state kept so a re-save retries'],
            ['stored' => 'CONFIRMED', 'responses' => [$get('CONFIRMED'), HttpResponse::unreachable('timeout')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'state' => 'CONFIRMED', 'description' => 'Two not reached: refused'],
            ['stored' => 'CONFIRMED', 'handlers' => $throws, 'responses' => [$get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'state' => 'CONFIRMED', 'description' => 'the hook refuses the capture: nothing sent'],
        ];
        foreach ($cases as $case) {
            [$lifecycle, $transport, $records, $logger] = self::lifecycle($case['responses'], $case['stored'], $case['handlers'] ?? []);
            $quantity = array_key_exists('rebuild', $case) ? $case['rebuild'] : 2;
            $rebuild = static fn (array $record): ?array => $quantity === null ? null : ['order' => self::order(), 'lines' => self::lines($quantity), 'totals' => []];
            $result = $lifecycle->fulfil(42, $rebuild);
            self::check('fulfil: ' . $case['description'], $case, $result, $transport, $records, $logger);
            if (in_array($post, self::calls($transport), true)) {
                TinyAssert::same('oc-fulfil-' . self::ID, $transport->calls[1]['headers']['Idempotency-Key'] ?? null, 'fulfil: ' . $case['description'] . ': idempotency key');
            }
        }
    }

    private static function testCancel(): void
    {
        $get = self::get(...);
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/cancel';
        $cases = [
            ['stored' => 'CONFIRMED', 'responses' => [self::json(200, []), $get('CANCELLED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$post, $read], 'state' => 'CANCELLED', 'description' => 'confirmed: cancelled and read back'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(409, []), $get('CANCELLED')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$post, $read], 'state' => 'CANCELLED', 'description' => 'already cancelled at Two: counts as cancelled'],
            ['stored' => 'CANCELLED', 'responses' => [], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [], 'state' => 'CANCELLED', 'description' => 'already cancelled: a repeat event sends nothing'],
            ['stored' => 'FULFILLED', 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => 'FULFILLED', 'description' => 'fulfilled: refused, nothing sent'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(200, []), $get('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$post, $read], 'state' => 'CONFIRMED', 'description' => 'Two still holds it confirmed: refused'],
            ['stored' => 'CONFIRMED', 'responses' => [self::json(400, ['error_code' => 'X']), $get('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$post, $read], 'state' => 'FULFILLED', 'description' => 'fulfilled at Two meanwhile: refused'],
            ['stored' => null, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => null, 'description' => 'no Two order linked: refused, nothing sent'],
        ];
        foreach ($cases as $case) {
            [$lifecycle, $transport, $records, $logger] = self::lifecycle($case['responses'], $case['stored']);
            $result = $lifecycle->cancel(42);
            self::check('cancel: ' . $case['description'], $case, $result, $transport, $records, $logger);
            if (in_array($post, self::calls($transport), true)) {
                TinyAssert::same('oc-cancel-' . self::ID, $transport->calls[0]['headers']['Idempotency-Key'] ?? null, 'cancel: ' . $case['description'] . ': idempotency key');
            }
        }
    }

    private static function testInvoice(): void
    {
        $pdf = new HttpResponse(200, "%PDF-1.7\n...");
        $notFulfilled = self::json(400, ['error_code' => 'ORDER_NOT_FULFILLED']);
        $read = 'GET /v1/order/' . self::ID;
        $fetch = 'GET /v1/invoice/' . self::ID . '/pdf';
        $cases = [
            ['stored' => 'FULFILLED', 'responses' => [$pdf], 'expected' => 'pdf', 'calls' => [$fetch], 'description' => 'fulfilled: the PDF'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILLING')], 'expected' => OrderLifecycle::INVOICE_NOT_READY, 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED while fulfilling: not ready yet'],
            ['stored' => 'CONFIRMED', 'responses' => [$notFulfilled, self::get('CONFIRMED')], 'expected' => OrderLifecycle::INVOICE_UNAVAILABLE_STATE . ':CONFIRMED', 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED, not fulfilled: names the state'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILLED'), $pdf], 'expected' => 'pdf', 'calls' => [$fetch, $read, $fetch], 'description' => 'ORDER_NOT_FULFILLED, fulfilled just now: asked once more'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::get('FULFILLED'), $notFulfilled], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch, $read, $fetch], 'description' => 'fulfilled but still no invoice on the retry: an error, no loop'],
            ['stored' => 'FULFILLING', 'responses' => [$notFulfilled, self::json(500, [])], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch, $read], 'description' => 'ORDER_NOT_FULFILLED and the state cannot be read: an error'],
            ['stored' => 'FULFILLED', 'responses' => [self::json(500, [])], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch], 'description' => 'Two fails: an error'],
            ['stored' => 'FULFILLED', 'responses' => [new HttpResponse(200, '<html>login</html>')], 'expected' => OrderLifecycle::INVOICE_ERROR, 'calls' => [$fetch], 'description' => 'a 200 that is not a PDF is never served as one'],
            ['stored' => null, 'responses' => [], 'expected' => OrderLifecycle::INVOICE_NO_REFERENCE, 'calls' => [], 'description' => 'no Two order linked: nothing asked'],
        ];
        foreach ($cases as $case) {
            [$lifecycle, $transport] = self::lifecycle($case['responses'], $case['stored']);
            $result = $lifecycle->invoice(42);
            $actual = isset($result['pdf']) ? 'pdf' : $result['notice'] . ($result['notice'] === OrderLifecycle::INVOICE_UNAVAILABLE_STATE ? ':' . $result['state'] : '');
            $d = 'invoice: ' . $case['description'];
            TinyAssert::same($case['expected'], $actual, $d . ': outcome');
            TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
            foreach ($transport->calls as $call) {
                if (str_contains($call['url'], '/v1/invoice/')) {
                    TinyAssert::same('application/pdf', $call['headers']['Accept'] ?? null, $d . ': asks for a PDF');
                }
            }
        }
    }

    /**
     * @param array<string, mixed>                                    $case
     * @param array{outcome: string, reason: string}                  $result
     */
    private static function check(string $d, array $case, array $result, ScriptedTransport $transport, MemoryRecords $records, RecordingLogger $logger): void
    {
        TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome');
        TinyAssert::same($case['calls'], self::calls($transport), $d . ': requests');
        TinyAssert::same($case['state'], $records->rows[42]['two_order_state'] ?? null, $d . ': stored state');
        TinyAssert::true($result['outcome'] === OrderLifecycle::SKIPPED || $result['reason'] !== '', $d . ': a reason for the order history');
        TinyAssert::true($result['outcome'] !== OrderLifecycle::REFUSED || $logger->at('error') !== [], $d . ': a refusal is logged');
    }

    /**
     * @param list<HttpResponse>                                       $responses
     * @param array<string, \Closure(array<string, mixed>, array<string, mixed>): mixed> $handlers
     * @return array{0: OrderLifecycle, 1: ScriptedTransport, 2: MemoryRecords, 3: RecordingLogger}
     */
    private static function lifecycle(array $responses, ?string $stored, array $handlers = []): array
    {
        $rows = $stored === null ? [] : [42 => [
            'token_hash' => OrderFlow::tokenHash(str_repeat('a', 48)),
            'payload_hash' => OrderFlow::payloadHash((new OrderBuilder())->build(self::order(), self::lines(2), [])),
            'two_order_id' => self::ID, 'two_order_state' => $stored, 'company_name' => 'Acme AS',
        ]];
        $records = new MemoryRecords($rows);
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $postprocessing = new Postprocessing(new ScriptedSubscribers($handlers), $logger);
        $flow = new OrderFlow($client, new OrderBuilder(), $postprocessing, new ErrorMapper(), $records, $logger);

        return [new OrderLifecycle($flow, $client, $postprocessing, new ErrorMapper(), $records, $logger), $transport, $records, $logger];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    private static function get(string $state): HttpResponse
    {
        return self::json(200, ['id' => self::ID, 'state' => $state, 'status' => 'APPROVED']);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }

    /** @return list<array<string, mixed>> */
    private static function lines(int $quantity): array
    {
        return [['name' => 'Widget', 'quantity' => $quantity, 'price' => 100, 'total' => 100 * $quantity, 'tax' => 25, 'rate' => 0.25, 'type' => 'PHYSICAL']];
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
