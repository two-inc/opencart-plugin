<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\Client;
use Opencart\System\Library\Extension\Two\Core\CustomHeaders;
use Opencart\System\Library\Extension\Two\Core\Environment;
use Opencart\System\Library\Extension\Two\Core\ErrorMapper;
use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;
use Opencart\System\Library\Extension\Two\Core\OrderLifecycle;
use Opencart\System\Library\Extension\Two\Core\OrderRefunds;
use Opencart\System\Library\Extension\Two\Core\Postprocessing;
use Opencart\System\Library\Extension\Two\Core\RefundRecords;
use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * Refunds against a scripted Two: the rest of the order off the status map,
 * the panel's partial refund, the retry of a refund whose outcome is
 * unknown, and the credit note. The Two order is 2 Widgets at 100.00 net and
 * 25.00 tax each (250.00 gross, 25%). Each case gives Two's answers, then the
 * outcome, the requests that reached Two and the refund row's state.
 */
final class OrderRefundsSpec
{
    private const ID = '5f1c2a7e-0000-4000-8000-000000000001';
    private const LINE = '9a0b1c2d-0000-4000-8000-0000000000aa';

    public static function runAll(): void
    {
        self::testUntaxed();
        self::testRemainder();
        self::testPartial();
        self::testRetry();
        self::testCreditNote();
    }

    private static function testUntaxed(): void
    {
        $cases = [
            [1000, 0, '0.25', true, 'a net amount at 25% with no tax'],
            [8, 0, '0.25', false, 'tax under 2 cents may round to nothing'],
            [1000, 0, '0', false, 'a zero-rated line carries no tax'],
            [1000, 1, '0.25', false, 'a line carrying tax is the shop\'s to state'],
        ];
        foreach ($cases as [$net, $tax, $rate, $expected, $description]) {
            TinyAssert::same($expected, OrderRefunds::untaxed($net, $tax, $rate), 'untaxed: ' . $description);
        }
    }

    private static function testRemainder(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/refund';
        $partly = [self::refund('r1', '100.00', '80.00', '20.00')];
        $cases = [
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'body' => '{}', 'key' => IdempotencyKey::refundRemainder(self::ID, [], '250.00'), 'description' => 'nothing refunded yet: an empty body refunds in full'],
            ['stored' => 'REFUNDED', 'responses' => [self::two('REFUNDED', $partly), self::json(201, ['id' => 'r2']), self::two('REFUNDED', [...$partly, self::refund('r2', '150.00', '120.00', '30.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '150.00', 'key' => IdempotencyKey::refundRemainder(self::ID, ['r1'], '150.00'), 'description' => 'after a partial refund: only the rest, one line a rate'],
            ['stored' => 'REFUNDED', 'responses' => [self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::SKIPPED, 'calls' => [$read], 'row' => null, 'description' => 'already refunded in full and stored so: a repeat event sends nothing, no note'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::ALREADY, 'calls' => [$read], 'row' => null, 'description' => 'refunded in full at Two meanwhile: nothing sent, noted'],
            ['stored' => 'CONFIRMED', 'responses' => [self::two('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'not fulfilled at Two: refused, nothing sent'],
            ['stored' => 'FULFILLED', 'responses' => [self::json(500, [])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'Two cannot be read: refused'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('REFUNDED', [self::refund('r1', '100.00', '0.00', '0.00')])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'Two\'s subtotals do not split the rest: refused, nothing guessed'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(400, ['error_code' => 'X'])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read, $post], 'row' => RefundRecords::REFUSED, 'description' => 'Two refuses: refused, the row says so'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('REFUNDED', [self::refund('r1', '250.00', '200.00', '50.00')])], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'description' => 'a timeout, but the refunded total shows it: done'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'row' => RefundRecords::UNKNOWN, 'description' => 'a timeout and no rise: unknown, awaiting a retry'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(502, []), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'row' => RefundRecords::UNKNOWN, 'description' => 'a 5xx and no rise: unknown'],
            ['stored' => 'FULFILLED', 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r0']), self::two('FULFILLED')], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'row' => RefundRecords::NOT_APPLIED, 'description' => 'Two answers 201 but the total did not rise: an earlier refund under this key, not applied'],
            ['stored' => 'FULFILLED', 'blocked' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'row' => RefundRecords::UNKNOWN, 'description' => 'a refund awaits a retry: refused before asking Two'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], $case['stored'], blocked: !empty($case['blocked']));
            $result = $shop['refunds']->remainder(42);
            self::check('remainder: ' . $case['description'], $case, $result, $shop);
        }
    }

    private static function testPartial(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/refund';
        $after = static fn (string $gross, string $net, string $tax): HttpResponse => self::two('REFUNDED', [self::refund('r1', $gross, $net, $tax)]);
        $cases = [
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('125.00', '100.00', '25.00')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '125.00', 'line' => ['id' => self::LINE, 'quantity' => 1, 'net_amount' => '100.00', 'tax_amount' => '25.00', 'tax_rate' => '0.25'], 'description' => 'one of two Widgets: the line\'s own net and tax, referenced by id'],
            ['request' => ['net' => '10.00', 'tax' => '2.50', 'rate' => '0.25', 'reason' => 'Damaged box'], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('12.50', '10.00', '2.50')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'amount' => '12.50', 'line' => ['net_amount' => '10.00', 'tax_amount' => '2.50', 'tax_rate' => '0.25'], 'description' => 'an amount line at an order rate, with a reason'],
            ['request' => ['net' => '10.00', 'tax' => '0', 'rate' => '0.25'], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'net but no tax at 25% (12.2.1): refused, nothing sent'],
            ['request' => ['net' => '10.00', 'tax' => '1.50', 'rate' => '0.15'], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'a rate no order line carries: refused'],
            ['request' => ['net' => '1,50', 'tax' => '0.30', 'rate' => '0.25'], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'an amount that is not one: refused'],
            ['request' => ['lines' => [self::LINE => 3]], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'more of a line than the order holds: refused'],
            ['request' => ['lines' => ['00000000-0000-4000-8000-000000000000' => 1]], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'a line not on the Two order: refused'],
            ['request' => ['lines' => [self::LINE => 0]], 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'nothing picked: refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('REFUNDED', [self::refund('r0', '200.00', '160.00', '40.00')])], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'more than remains (125.00 of 50.00): refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('CONFIRMED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'not fulfilled at Two: refused'],
            ['request' => ['lines' => [self::LINE => 1]], 'store_fails' => true, 'responses' => [self::two('FULFILLED')], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [$read], 'row' => null, 'description' => 'the row cannot be stored: no key, so nothing sent'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'row' => RefundRecords::UNKNOWN, 'description' => 'a timeout and no rise: the row is unknown'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r0']), self::two('FULFILLED')], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'row' => RefundRecords::NOT_APPLIED, 'description' => 'Two answers with an earlier refund under this key: not applied'],
            ['request' => ['lines' => [self::LINE => 1]], 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r1']), $after('200.00', '160.00', '40.00')], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'row' => RefundRecords::DONE, 'description' => 'a larger rise (a refund made meanwhile elsewhere) is fine'],
            ['request' => ['lines' => [self::LINE => 1]], 'blocked' => true, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'row' => RefundRecords::UNKNOWN, 'reason' => 'Retry it on the Two tab', 'description' => 'a refund awaits a retry: refused with a pointer to it'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], 'FULFILLED', !empty($case['store_fails']), !empty($case['blocked']));
            $result = $shop['refunds']->partial(42, $case['request']);
            $d = 'partial: ' . $case['description'];
            self::check($d, $case, $result, $shop);
            if (in_array($post, self::calls($shop['transport']), true)) {
                $rowId = count($shop['rows']->rows);
                TinyAssert::same('refund_' . self::ID . '_' . $rowId, $shop['transport']->calls[1]['headers']['Idempotency-Key'] ?? null, $d . ': keyed on the row created before the call');
            }
            if (isset($case['line'])) {
                $line = json_decode((string) $shop['transport']->calls[1]['body'], true)['line_items'][0] ?? [];
                TinyAssert::same($case['line'], array_intersect_key($line, $case['line']), $d . ': the line sent');
            }
        }
    }

    private static function testRetry(): void
    {
        $read = 'GET /v1/order/' . self::ID;
        $post = 'POST /v1/order/' . self::ID . '/refund';
        $applied = self::two('REFUNDED', [self::refund('r9', '125.00', '100.00', '25.00')]);
        $cases = [
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'unknown, not applied before: sent again and applied'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [$applied, self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'the earlier attempt was applied after all: Two answers with it, done, never refunded twice'],
            ['row' => RefundRecords::PENDING, 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r9']), $applied], 'outcome' => OrderLifecycle::DONE, 'calls' => [$read, $post, $read], 'state' => RefundRecords::DONE, 'description' => 'a row that never settled is retried the same way'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('FULFILLED'), self::json(201, ['id' => 'r5']), self::two('FULFILLED')], 'outcome' => OrderRefunds::NOT_APPLIED, 'calls' => [$read, $post, $read], 'state' => RefundRecords::NOT_APPLIED, 'description' => 'Two answers with a refund it did not hold before, and no rise: not applied'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::two('FULFILLED'), HttpResponse::unreachable('timeout'), self::two('FULFILLED')], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read, $post, $read], 'state' => RefundRecords::UNKNOWN, 'description' => 'no answer again: still unknown'],
            ['row' => RefundRecords::UNKNOWN, 'responses' => [self::json(500, [])], 'outcome' => OrderRefunds::UNKNOWN, 'calls' => [$read], 'state' => RefundRecords::UNKNOWN, 'description' => 'Two cannot be read: nothing sent, still unknown'],
            ['row' => RefundRecords::DONE, 'responses' => [], 'outcome' => OrderLifecycle::REFUSED, 'calls' => [], 'state' => RefundRecords::DONE, 'description' => 'a settled refund is never retried'],
        ];
        $key = 'refund_' . self::ID . '_1';
        $body = '{"amount":"125.00","currency":"NOK"}';
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], 'FULFILLED');
            $shop['rows']->create(42, ['two_order_id' => self::ID, 'kind' => RefundRecords::KIND_PARTIAL, 'amount' => '125.00', 'currency' => 'NOK', 'payload' => $body, 'idempotency_key' => $key, 'state' => $case['row']]);
            $result = $shop['refunds']->retry(42, 1);
            $d = 'retry: ' . $case['description'];
            TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome');
            TinyAssert::same($case['calls'], self::calls($shop['transport']), $d . ': requests');
            TinyAssert::same($case['state'], $shop['rows']->rows[1]['state'], $d . ': row state');
            if (in_array($post, $case['calls'], true)) {
                TinyAssert::same($key, $shop['transport']->calls[1]['headers']['Idempotency-Key'] ?? null, $d . ': the same key');
                TinyAssert::same($body, $shop['transport']->calls[1]['body'], $d . ': the same payload');
            }
            TinyAssert::same(1, count($shop['rows']->rows), $d . ': no new row');
        }
    }

    private static function testCreditNote(): void
    {
        $cases = [
            ['stored' => 'REFUNDED', 'responses' => [new HttpResponse(200, "%PDF-1.7\n...")], 'expected' => 'pdf', 'description' => 'the PDF, streamed by Two'],
            ['stored' => 'REFUNDED', 'responses' => [self::json(409, ['error_code' => 'REFUND_STILL_PROCESSING'])], 'expected' => OrderRefunds::CREDIT_NOTE_ERROR, 'description' => 'still processing: an error notice'],
            ['stored' => 'REFUNDED', 'responses' => [new HttpResponse(200, '<html></html>')], 'expected' => OrderRefunds::CREDIT_NOTE_ERROR, 'description' => 'a 200 that is not a PDF is never served'],
            ['stored' => null, 'responses' => [], 'expected' => OrderRefunds::CREDIT_NOTE_NO_REFERENCE, 'description' => 'no Two order linked: nothing asked'],
        ];
        foreach ($cases as $case) {
            $shop = self::shop($case['responses'], $case['stored']);
            $result = $shop['refunds']->creditNote(42);
            $d = 'credit note: ' . $case['description'];
            TinyAssert::same($case['expected'], isset($result['pdf']) ? 'pdf' : $result['notice'], $d);
            foreach ($shop['transport']->calls as $call) {
                TinyAssert::true(str_contains($call['url'], '/v1/credit_note/' . self::ID . '/pdf?proxy=true'), $d . ': asks Two to stream it (no redirect)');
            }
        }
    }

    /**
     * @param array<string, mixed>                          $case
     * @param array{outcome: string, reason: string}        $result
     * @param array{transport: ScriptedTransport, rows: MemoryRefunds, logger: RecordingLogger} $shop
     */
    private static function check(string $d, array $case, array $result, array $shop): void
    {
        TinyAssert::same($case['outcome'], $result['outcome'], $d . ': outcome (' . $result['reason'] . ')');
        TinyAssert::same($case['calls'], self::calls($shop['transport']), $d . ': requests');
        $last = $shop['rows']->rows === [] ? null : end($shop['rows']->rows)['state'];
        TinyAssert::same($case['row'], $last, $d . ': refund row state');
        TinyAssert::true($result['outcome'] === OrderLifecycle::SKIPPED || $result['reason'] !== '', $d . ': a reason for the order history');
        if (isset($case['reason'])) {
            TinyAssert::true(str_contains($result['reason'], $case['reason']), $d . ': the message says what to do');
        }
        foreach ($shop['transport']->calls as $call) {
            if ($call['method'] === 'POST') {
                $body = json_decode((string) $call['body'], true);
                if (isset($case['body'])) {
                    TinyAssert::same($case['body'], $call['body'], $d . ': body');
                }
                if (isset($case['amount'])) {
                    TinyAssert::same($case['amount'], $body['amount'] ?? null, $d . ': amount sent');
                }
                if (isset($case['key'])) {
                    TinyAssert::same($case['key'], $call['headers']['Idempotency-Key'] ?? null, $d . ': key');
                }
                TinyAssert::true(($call['headers']['Idempotency-Key'] ?? '') !== '', $d . ': every refund carries a key');
            }
        }
    }

    /**
     * @param list<HttpResponse> $responses
     * @return array{refunds: OrderRefunds, transport: ScriptedTransport, rows: MemoryRefunds, logger: RecordingLogger}
     */
    private static function shop(array $responses, ?string $stored, bool $storeFails = false, bool $blocked = false): array
    {
        $records = new MemoryRecords($stored === null ? [] : [42 => ['two_order_id' => self::ID, 'two_order_state' => $stored]]);
        $rows = new MemoryRefunds($storeFails);
        if ($blocked) {
            $rows->rows[1] = ['refund_id' => '1', 'order_id' => '42', 'date_added' => '2026-10-25 12:00:00', 'two_order_id' => self::ID, 'amount' => '10.00', 'currency' => 'NOK', 'state' => RefundRecords::UNKNOWN, 'payload' => '{}', 'idempotency_key' => 'refund_' . self::ID . '_1'];
        }
        $transport = new ScriptedTransport($responses);
        $logger = new RecordingLogger();
        $client = new Client(new Environment(false), new SettingsProvider(new MemoryStore(['api_key' => 'key-0123456789', 'environment' => 'sandbox'])), new CustomHeaders(), $transport, $logger, '1.0.0');
        $refunds = new OrderRefunds($client, new Postprocessing(new ScriptedSubscribers([]), $logger), new ErrorMapper(), $records, $rows, $logger);

        return ['refunds' => $refunds, 'transport' => $transport, 'rows' => $rows, 'logger' => $logger];
    }

    /** @return list<string> */
    private static function calls(ScriptedTransport $transport): array
    {
        return array_map(static fn (array $call): string => $call['method'] . ' ' . (string) parse_url($call['url'], PHP_URL_PATH), $transport->calls);
    }

    /** @param list<array<string, mixed>> $refunds */
    private static function two(string $state, array $refunds = []): HttpResponse
    {
        return self::json(200, [
            'id' => self::ID, 'state' => $state, 'status' => 'APPROVED', 'currency' => 'NOK', 'gross_amount' => '250.00',
            'tax_subtotals' => [['tax_rate' => '0.25', 'taxable_amount' => '200.00', 'tax_amount' => '50.00']],
            'line_items' => [['id' => self::LINE, 'name' => 'Widget', 'quantity' => 2, 'unit_price' => '100.00', 'net_amount' => '200.00', 'tax_amount' => '50.00', 'tax_rate' => '0.25']],
            'refunds' => $refunds,
        ]);
    }

    /** @return array<string, mixed> a refund as Two lists it, its total negative */
    private static function refund(string $id, string $gross, string $net, string $tax): array
    {
        return ['id' => $id, 'total_amount' => '-' . $gross, 'tax_subtotals' => [['tax_rate' => '0.25', 'taxable_amount' => $net, 'tax_amount' => $tax]]];
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($body));
    }
}
