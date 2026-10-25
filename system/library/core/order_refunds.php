<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Refunds at Two (Build Guide section 12.2) and the credit note.
 *
 * - The rest of the order, off the refund status map: what Two's order
 *   total leaves once the refunds Two already holds are taken off. With no
 *   earlier refund the body is empty (Two refunds the order in full);
 *   after one, the rest goes as one line per tax rate, taken from Two's own
 *   tax subtotals less its refunds'. Keyed on the earlier refunds' ids and
 *   the amount, so a repeated status event never refunds twice.
 * - A partial refund from the order panel: lines of the Two order with a
 *   quantity, and an amount line the merchant types (net and tax at one of
 *   the order's rates). Each is stored as a plugin row before the call and
 *   keyed on that row's id.
 * - Section 12.2.1: a line keeps the tax the shop recorded, never one the
 *   plugin computes; a line with a net amount but no tax at a taxed rate is
 *   refused, and nothing is sent that is more than remains.
 * - After every refund Two's refunded total is read back. It must have risen
 *   by at least the amount sent; less means Two answered with an earlier
 *   refund under the same key and this one was not applied. More is fine (a
 *   refund made meanwhile elsewhere).
 */
final class OrderRefunds
{
    /** Two refunds only an invoiced order; REFUNDED is what Two reports after any refund, partial included. */
    public const REFUNDABLE_STATES = ['FULFILLED', 'DELIVERED', 'REFUNDED'];

    /** Sent, but Two's refunded total could not be read back, or did not show it and Two did not answer. */
    public const UNKNOWN = 'unknown';
    /** Two answered with an earlier refund under this key; nothing new was refunded. */
    public const NOT_APPLIED = 'not_applied';

    public const CREDIT_NOTE_NO_REFERENCE = 'no_reference';
    public const CREDIT_NOTE_ERROR = 'error';

    /** Two's own reason field is at most this long. */
    public const REASON_MAX = 255;

    public function __construct(
        private readonly Client $client,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly RefundRecords $refunds,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Two's order as it stands, its state stored; null when Two did not answer with one.
     *
     * @return array<string, mixed>|null
     */
    public function read(int $orderId, string $twoOrderId, int $timeout = Client::TIMEOUT_SECONDS): ?array
    {
        $response = $this->client->request('GET', '/v1/order/' . $twoOrderId, null, $timeout);
        $body = $response->json();
        if (!$response->reachedTwo() || $response->status >= 400 || !is_string($body['state'] ?? null)) {
            $this->logger->error('Two order ' . $twoOrderId . ' could not be read: ' . $this->errors->reason($response));

            return null;
        }
        $this->records->put($orderId, OrderFlow::stateFields($body));

        return $body;
    }

    /**
     * What Two has refunded on the order, in cents. Two reports each refund's total as a negative amount.
     *
     * @param array<string, mixed> $twoOrder
     */
    public static function refundedCents(array $twoOrder): int
    {
        $total = 0;
        foreach (self::list($twoOrder['refunds'] ?? null) as $refund) {
            $total += abs(Postprocessing::cents($refund['total_amount'] ?? 0));
        }

        return $total;
    }

    /**
     * What is left to refund, in cents; null when Two's order total cannot be read.
     *
     * @param array<string, mixed> $twoOrder
     */
    public static function remainingCents(array $twoOrder): ?int
    {
        $gross = Postprocessing::cents($twoOrder['gross_amount'] ?? null);

        return $gross > 0 ? max(0, $gross - self::refundedCents($twoOrder)) : null;
    }

    /**
     * The Two order's lines the panel offers: those with a positive net (a
     * discount line is never refunded on its own).
     *
     * @param array<string, mixed> $twoOrder
     * @return list<array{id: string, name: string, quantity: int, net_amount: string, tax_amount: string, tax_rate: string}>
     */
    public static function refundableLines(array $twoOrder): array
    {
        $lines = [];
        foreach (self::list($twoOrder['line_items'] ?? null) as $line) {
            $id = is_string($line['id'] ?? null) ? $line['id'] : '';
            $quantity = (int) ($line['quantity'] ?? 0);
            if (!OrderFlow::validTwoOrderId($id) || $quantity <= 0 || Postprocessing::cents($line['net_amount'] ?? 0) <= 0) {
                continue;
            }
            $lines[] = [
                'id' => $id,
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => $quantity,
                'net_amount' => Postprocessing::money(Postprocessing::cents($line['net_amount'])),
                'tax_amount' => Postprocessing::money(Postprocessing::cents($line['tax_amount'] ?? 0)),
                'tax_rate' => OrderBuilder::rate((float) ($line['tax_rate'] ?? 0)),
            ];
        }

        return $lines;
    }

    /**
     * The tax rates of the Two order's lines, the only rates an amount line may carry.
     *
     * @param array<string, mixed> $twoOrder
     * @return list<string>
     */
    public static function rates(array $twoOrder): array
    {
        $rates = [];
        foreach (self::list($twoOrder['line_items'] ?? null) as $line) {
            $rates[OrderBuilder::rate((float) ($line['tax_rate'] ?? 0))] = true;
        }
        $rates = array_map('strval', array_keys($rates));
        sort($rates);

        return $rates;
    }

    /**
     * Section 12.2.1: a line with a net amount but no tax at a rate that
     * taxes it. The rounding allowance is Two's own: tax under 2 cents at
     * that rate may round to nothing.
     */
    public static function untaxed(int $netCents, int $taxCents, string $rate): bool
    {
        return $taxCents === 0 && (int) round(abs($netCents) * (float) $rate) > 2;
    }

    /**
     * Refunds what is left of the order, from the refund status map.
     *
     * @return array{outcome: string, reason: string}
     */
    public function remainder(int $orderId): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, 'Not refunded: no Two order is linked to this order.');
        }
        $storedState = $record['two_order_state'] ?? '';
        $two = $this->read($orderId, $twoOrderId);
        if ($two === null) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not refunded: Two could not be asked for the order. Save the status again to retry.');
        }
        $state = strtoupper((string) $two['state']);
        if (!in_array($state, self::REFUNDABLE_STATES, true)) {
            return $this->refused($orderId, 'Two holds order ' . $twoOrderId . ' as ' . $state . ', so it was not refunded. Two refunds a fulfilled order; an order not fulfilled yet is cancelled.');
        }
        $remaining = self::remainingCents($two);
        if ($remaining === null) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not refunded: its total could not be read from Two.');
        }
        if ($remaining === 0) {
            $reason = 'Two order ' . $twoOrderId . ' is already refunded in full.';

            return self::outcome($storedState === 'REFUNDED' ? OrderLifecycle::SKIPPED : OrderLifecycle::ALREADY, $reason);
        }

        $prior = [];
        foreach (self::list($two['refunds'] ?? null) as $refund) {
            $prior[] = (string) ($refund['id'] ?? '');
        }
        if ($prior === []) {
            // Nothing refunded yet: an empty body refunds the order in full, Two copying its own lines.
            $payload = [];
            $amount = $remaining;
        } else {
            $payload = $this->restPayload($orderId, $two, $remaining);
            if ($payload === null) {
                return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not refunded: the rest (' . Postprocessing::money($remaining) . ') could not be split across the order\'s tax rates as Two holds them. Refund it in the Two merchant portal.');
            }
            $amount = Postprocessing::cents($payload['amount']);
        }
        $key = IdempotencyKey::refundRemainder($twoOrderId, $prior, Postprocessing::money($amount));

        return $this->send($orderId, $twoOrderId, $two, $payload, $amount, $key, 'status_change', RefundRecords::KIND_REMAINDER);
    }

    /**
     * A partial refund from the order panel.
     *
     * @param array{lines?: array<string, int>, net?: string, tax?: string, rate?: string, reason?: string} $request
     *        quantities by Two line id; the amount line's net, tax and rate as typed
     * @return array{outcome: string, reason: string}
     */
    public function partial(int $orderId, array $request): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, 'Not refunded: no Two order is linked to this order.');
        }
        $two = $this->read($orderId, $twoOrderId);
        if ($two === null) {
            return $this->refused($orderId, 'Not refunded: Two could not be asked for order ' . $twoOrderId . '. Try again.');
        }
        $state = strtoupper((string) $two['state']);
        if (!in_array($state, self::REFUNDABLE_STATES, true)) {
            return $this->refused($orderId, 'Not refunded: Two holds order ' . $twoOrderId . ' as ' . $state . '. Two refunds a fulfilled order only.');
        }

        try {
            $lines = $this->requestLines($two, $request, $orderId);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, 'Not refunded: ' . $refused->detail);
        }
        $totals = Postprocessing::recomputeTotals(['line_items' => $lines]);
        $payload = ['amount' => $totals['gross_amount'], 'currency' => (string) ($two['currency'] ?? ''), 'line_items' => $lines, 'tax_subtotals' => $totals['tax_subtotals']];
        $reason = trim((string) ($request['reason'] ?? ''));
        if ($reason !== '') {
            $payload['reason'] = mb_substr($reason, 0, self::REASON_MAX);
        }

        return $this->send($orderId, $twoOrderId, $two, $payload, Postprocessing::cents($payload['amount']), null, 'refund_form', RefundRecords::KIND_PARTIAL);
    }

    /**
     * The order's latest credit note, streamed by Two itself.
     *
     * @return array{pdf: string}|array{notice: string}
     */
    public function creditNote(int $orderId): array
    {
        $twoOrderId = $this->records->get($orderId)['two_order_id'] ?? '';
        if (!OrderFlow::validTwoOrderId($twoOrderId)) {
            return ['notice' => self::CREDIT_NOTE_NO_REFERENCE];
        }
        $response = $this->client->request('GET', '/v1/credit_note/' . $twoOrderId . '/pdf?proxy=true', null, OrderLifecycle::INVOICE_TIMEOUT_SECONDS, true, ['Accept' => 'application/pdf']);
        if ($response->reachedTwo() && $response->status === 200 && str_starts_with($response->body, '%PDF-')) {
            return ['pdf' => $response->body];
        }
        $this->logger->error('Order ' . $orderId . ': the credit note for Two order ' . $twoOrderId . ' could not be fetched: ' . $this->errors->reason($response));

        return ['notice' => self::CREDIT_NOTE_ERROR];
    }

    /**
     * The panel's request as refund lines, each checked.
     *
     * @param array<string, mixed>                                                                     $two
     * @param array{lines?: array<string, int>, net?: string, tax?: string, rate?: string, reason?: string} $request
     * @return list<array<string, mixed>>
     *
     * @throws OrderRefused
     */
    private function requestLines(array $two, array $request, int $orderId): array
    {
        $byId = [];
        foreach (self::refundableLines($two) as $line) {
            $byId[$line['id']] = $line;
        }
        $lines = [];
        foreach ($request['lines'] ?? [] as $id => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            $line = $byId[(string) $id] ?? null;
            if ($line === null) {
                throw OrderRefused::because('refund_line_unknown', 'a picked line is not on the Two order.');
            }
            if ($quantity > $line['quantity']) {
                throw OrderRefused::because('refund_line_quantity', 'more of "' . $line['name'] . '" than the order holds (' . $line['quantity'] . ').');
            }
            // The share of the line's own net and tax: the whole line refunds exactly what Two holds.
            $net = intdiv(Postprocessing::cents($line['net_amount']) * $quantity * 2 + $line['quantity'], $line['quantity'] * 2);
            $tax = intdiv(Postprocessing::cents($line['tax_amount']) * $quantity * 2 + $line['quantity'], $line['quantity'] * 2);
            $lines[] = ['id' => $line['id']] + self::checkedLine($line['name'], $quantity, $net, $tax, $line['tax_rate'], 'pcs');
        }

        $netTyped = trim((string) ($request['net'] ?? ''));
        $taxTyped = trim((string) ($request['tax'] ?? ''));
        if ($netTyped !== '' || $taxTyped !== '') {
            foreach (['net' => $netTyped, 'tax' => $taxTyped === '' ? '0' : $taxTyped] as $field => $typed) {
                if (preg_match('~^\d{1,9}(\.\d{1,2})?\z~', $typed) !== 1) {
                    throw OrderRefused::because('refund_amount_format', 'the amount line\'s ' . $field . ' is not an amount (digits, and up to two decimals after a dot).');
                }
            }
            $rate = OrderBuilder::rate((float) ($request['rate'] ?? 0));
            if (!in_array($rate, self::rates($two), true)) {
                throw OrderRefused::because('refund_amount_rate', 'the amount line\'s tax rate is not one of the order\'s.');
            }
            $net = Postprocessing::cents($netTyped);
            if ($net <= 0) {
                throw OrderRefused::because('refund_amount_zero', 'the amount line has no net amount.');
            }
            $name = 'Refund on order ' . $orderId;
            $lines[] = self::checkedLine($name, 1, $net, Postprocessing::cents($taxTyped === '' ? '0' : $taxTyped), $rate, 'item');
        }
        if ($lines === []) {
            throw OrderRefused::because('refund_empty', 'nothing to refund. Pick a quantity on a line, or enter an amount.');
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OrderRefused
     */
    private static function checkedLine(string $name, int $quantity, int $net, int $tax, string $rate, string $unit): array
    {
        if (self::untaxed($net, $tax, $rate)) {
            throw OrderRefused::because('refund_line_untaxed', '"' . $name . '" has a net amount but no tax at ' . $rate . '. Enter the tax the shop charged.');
        }

        return OrderBuilder::line($name, 'PHYSICAL', $quantity, intdiv($net, max(1, $quantity)), $net, $tax, (float) $rate, $unit);
    }

    /**
     * The rest of a partly refunded order, one line per tax rate: Two's tax
     * subtotals for the order less those of each refund it holds. Null when
     * those do not add up to what remains (within a cent a rate).
     *
     * @param array<string, mixed> $two
     * @return array<string, mixed>|null
     */
    private function restPayload(int $orderId, array $two, int $remaining): ?array
    {
        $buckets = [];
        $add = static function (mixed $subtotals, int $sign) use (&$buckets): void {
            foreach (self::list($subtotals) as $subtotal) {
                $rate = OrderBuilder::rate((float) ($subtotal['tax_rate'] ?? 0));
                $buckets[$rate] ??= ['net' => 0, 'tax' => 0];
                $buckets[$rate]['net'] += $sign * abs(Postprocessing::cents($subtotal['taxable_amount'] ?? 0));
                $buckets[$rate]['tax'] += $sign * abs(Postprocessing::cents($subtotal['tax_amount'] ?? 0));
            }
        };
        $add($two['tax_subtotals'] ?? null, 1);
        foreach (self::list($two['refunds'] ?? null) as $refund) {
            $add($refund['tax_subtotals'] ?? null, -1);
        }
        $lines = [];
        foreach ($buckets as $rate => $bucket) {
            if ($bucket['net'] + $bucket['tax'] <= 0) {
                continue;
            }
            if ($bucket['net'] < 0 || $bucket['tax'] < 0 || self::untaxed($bucket['net'], $bucket['tax'], (string) $rate)) {
                return null;
            }
            $lines[] = OrderBuilder::line('Refund of the rest of order ' . $orderId, 'PHYSICAL', 1, $bucket['net'], $bucket['net'], $bucket['tax'], (float) $rate, 'item');
        }
        if ($lines === []) {
            return null;
        }
        $totals = Postprocessing::recomputeTotals(['line_items' => $lines]);
        $gross = Postprocessing::cents($totals['gross_amount']);
        if ($gross > $remaining || $remaining - $gross > count($lines)) {
            return null;
        }

        return ['amount' => $totals['gross_amount'], 'currency' => (string) ($two['currency'] ?? ''), 'line_items' => $lines, 'tax_subtotals' => $totals['tax_subtotals']];
    }

    /**
     * Stores the refund, sends it through the hook and checks Two applied it.
     *
     * @param array<string, mixed> $two     Two's order as read just before
     * @param array<string, mixed> $payload
     * @param string|null          $key     null for a partial refund, keyed on its new row
     * @return array{outcome: string, reason: string}
     */
    private function send(int $orderId, string $twoOrderId, array $two, array $payload, int $amount, ?string $key, string $trigger, string $kind): array
    {
        $path = '/v1/order/' . $twoOrderId . '/refund';
        try {
            $body = $this->postprocessing->apply(Postprocessing::REFUND, $trigger, $path, $payload, ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not refunded: ' . $refused->detail);
        }
        if ($body !== []) {
            $amount = Postprocessing::cents($body['amount'] ?? 0);
        }
        $before = self::refundedCents($two);
        $remaining = self::remainingCents($two) ?? 0;
        if ($amount <= 0 || $amount > $remaining) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not refunded: ' . Postprocessing::money($amount) . ' is more than remains to refund (' . Postprocessing::money($remaining) . ').');
        }

        $fields = ['two_order_id' => $twoOrderId, 'kind' => $kind, 'amount' => Postprocessing::money($amount), 'currency' => (string) ($two['currency'] ?? ''),
            'payload' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'state' => RefundRecords::PENDING, 'idempotency_key' => $key ?? ''];
        $rowId = $this->refunds->create($orderId, $fields);
        if ($key === null) {
            if ($rowId <= 0) {
                return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not refunded: the refund could not be stored, so it was not sent. Run the extension install again.');
            }
            $key = IdempotencyKey::refund($twoOrderId, $rowId);
            $this->refunds->update($rowId, ['idempotency_key' => $key]);
        }

        $response = $this->client->request('POST', $path, $body, Client::TIMEOUT_SECONDS, true, IdempotencyKey::header($key));
        $money = Postprocessing::money($amount) . ' ' . $fields['currency'];
        if ($response->reachedTwo() && $response->status >= 400 && $response->status < 500) {
            $result = $this->refused($orderId, 'Two refused the refund of ' . $money . ' on order ' . $twoOrderId . ': ' . $this->errors->reason($response));

            return $this->settled($rowId, RefundRecords::REFUSED, $result);
        }
        $after = $this->read($orderId, $twoOrderId);
        $answered = $response->reachedTwo() && $response->status < 300;
        if ($after !== null && self::refundedCents($after) - $before >= $amount) {
            return $this->settled($rowId, RefundRecords::DONE, self::outcome(OrderLifecycle::DONE, 'Two refunded ' . $money . ' on order ' . $twoOrderId . '. The credit note is on the order page under Two.'));
        }
        if ($answered && $after !== null) {
            $result = $this->refused($orderId, 'Two answered the refund of ' . $money . ' on order ' . $twoOrderId . ' with an earlier refund made under the same key (' . $key . '), so this refund was not applied. Two\'s refunded total did not rise by the amount sent.', self::NOT_APPLIED);

            return $this->settled($rowId, RefundRecords::NOT_APPLIED, $result);
        }
        $result = $this->refused($orderId, 'The refund of ' . $money . ' on order ' . $twoOrderId . ' may not have reached Two (' . ($answered ? 'the order could not be read back' : $this->errors->reason($response))
            . '). Check the refunded total on the order page under Two before refunding again.', self::UNKNOWN);

        return $this->settled($rowId, RefundRecords::UNKNOWN, $result);
    }

    /**
     * @param array{outcome: string, reason: string} $result
     * @return array{outcome: string, reason: string}
     */
    private function settled(int $rowId, string $state, array $result): array
    {
        if ($rowId > 0) {
            $this->refunds->update($rowId, ['state' => $state, 'reason' => $result['reason']]);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @return array{outcome: string, reason: string} */
    private function refused(int $orderId, string $reason, string $outcome = OrderLifecycle::REFUSED): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $reason);

        return self::outcome($outcome, $reason);
    }

    /** @return array{outcome: string, reason: string} */
    private static function outcome(string $outcome, string $reason): array
    {
        return ['outcome' => $outcome, 'reason' => $reason];
    }
}
