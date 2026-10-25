<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The hosted checkout flow (Build Guide sections 6.3, 6.4, 11 and 21): create
 * the Two order from the shop's stored order and send the buyer to Two's
 * payment URL; then, when Two redirects back, confirm or cancel it.
 *
 * - Every order request goes through Postprocessing just before it is sent.
 * - The callbacks are guarded by a per-order token: its hash is stored with
 *   the order, the token itself only travels in the callback URLs Two holds.
 * - The order Two confirms is the order the shop will place: the create
 *   payload's hash is stored, and confirm rebuilds the payload from the
 *   shop's stored order (hook included, section 21.1) and refuses on any
 *   difference. OpenCart rewrites an unplaced order on every checkout
 *   render, so the cart can change after the Two order was created.
 * - Two's order state decides the outcome, never the URL the buyer arrived on.
 * - Outcomes are plain arrays the adapter turns into order history, notices
 *   and redirects. A refusal carries the buyer's language keys and the
 *   merchant's reason separately, so Two's text never reaches the buyer.
 */
final class OrderFlow
{
    public const TOKEN_BYTES = 24;

    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const REFUSED = 'refused';
    /** Refused because the shop order changed after the Two order was created. */
    public const CHANGED = 'changed';
    public const UNAUTHORISED = 'unauthorised';

    public const CALLBACK_CONFIRM = 'confirmation';
    public const CALLBACK_CANCEL = 'cancel';

    /** Two order states in which the buyer has verified and the order stands. */
    public const PLACED_STATES = ['CONFIRMED', 'FULFILLING', 'FULFILLED', 'PARTIALLY_FULFILLED', 'REFUNDED', 'PARTIALLY_REFUNDED'];

    public function __construct(
        private readonly Client $client,
        private readonly OrderBuilder $builder,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly Logger $logger,
    ) {
    }

    /** A fresh callback token, hex, for the merchant URLs of one order create. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Creates the Two order. On success the buyer goes to the redirect URL.
     *
     * @param array<string, mixed>       $order   OrderBuilder's order shape, merchant_urls carrying the token
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $totals
     * @param array<string, mixed>       $context platform objects and request facts for the hook
     * @return array{ok: true, redirect: string, two_order_id: string}|array{ok: false, buyer: non-empty-list<string>, reason: string}
     */
    public function create(array $order, array $lines, array $totals, string $token, array $context = []): array
    {
        $orderId = (int) $order['order_id'];
        $company = is_array($order['company'] ?? null) ? $order['company'] : [];
        foreach (['company_name', 'organization_number', 'country_code'] as $field) {
            if (trim((string) ($company[$field] ?? '')) === '') {
                return ['ok' => false, 'buyer' => [ErrorMapper::COMPANY_REQUIRED], 'reason' => 'No company was entered.'];
            }
        }
        $tooLong = [];
        foreach (OrderRecords::MAX_LENGTHS as $field => $max) {
            if (mb_strlen(trim((string) $company[$field])) > $max) {
                $tooLong[] = $field === 'company_name' ? ErrorMapper::FIELDS['buyer.company.company_name'] : ($field === 'organization_number' ? ErrorMapper::FIELDS['buyer.company.organization_number'] : ErrorMapper::FIELDS['billing_address.country']);
            }
        }
        if ($tooLong !== []) {
            return ['ok' => false, 'buyer' => $tooLong, 'reason' => 'A company field is longer than the plugin stores.'];
        }

        try {
            $payload = $this->payload($order, $lines, $totals, $context, 'checkout');
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, 'Two order not created: ' . $refused->detail);
        }

        // A re-create (the buyer placed the order again) replaces the earlier
        // Two order, so cancel that first rather than leave it open at Two.
        $earlier = $this->records->get($orderId) ?? [];
        $earlierId = $earlier['two_order_id'] ?? '';
        if ($earlierId !== '' && self::validTwoOrderId($earlierId) && ($earlier['two_order_state'] ?? '') !== 'CANCELLED') {
            $failure = $this->sendCancel($earlierId, 'order_recreate', $orderId);
            if ($failure !== null && $this->readState($orderId, $earlierId) !== 'CANCELLED') {
                return $this->refused($orderId, 'Two order not created: the earlier Two order ' . $earlierId . ' for this order could not be cancelled (' . $failure . ').');
            }
        }

        $stored = [
            'token_hash' => self::tokenHash($token),
            'payload_hash' => self::payloadHash($payload),
            'two_order_id' => '',
            'two_order_state' => '',
            'two_order_status' => '',
            'company_name' => trim((string) $company['company_name']),
            'organization_number' => trim((string) $company['organization_number']),
            'country_code' => trim((string) $company['country_code']),
        ];
        $this->records->put($orderId, $stored);
        $record = $this->records->get($orderId) ?? [];
        foreach ($stored as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                return $this->refused($orderId, 'Two order not created: the two_order table did not store ' . $field . ' as given. Run the extension install again.');
            }
        }

        $response = $this->client->request('POST', '/v1/order', $payload);
        $body = $response->json();
        if (!$response->reachedTwo() || $response->status >= 400) {
            return $this->refused($orderId, 'Two refused the order: ' . $this->errors->reason($response), $this->errors->buyerMessageKeys($response->status, $body));
        }
        $twoOrderId = is_scalar($body['id'] ?? null) ? (string) $body['id'] : '';
        $paymentUrl = is_string($body['payment_url'] ?? null) ? $body['payment_url'] : '';
        if (!self::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, 'Two answered the order create without an order id (HTTP ' . $response->status . ').');
        }
        $this->records->put($orderId, ['two_order_id' => $twoOrderId] + self::stateFields($body));
        if (preg_match('~^https://~', $paymentUrl) !== 1) {
            $this->sendCancel($twoOrderId, 'missing_payment_url', $orderId);

            return $this->refused($orderId, 'Two created order ' . $twoOrderId . ' without a payment URL, so it was cancelled.');
        }

        return ['ok' => true, 'redirect' => $paymentUrl, 'two_order_id' => $twoOrderId];
    }

    /**
     * Two sent the buyer back to the confirmation URL. Confirms a verified
     * order whose payload the shop's stored order still rebuilds exactly; an
     * order Two already holds as placed confirms again harmlessly.
     *
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     *        the shop's stored order as create's inputs, given the stored record (company included); null when it is gone
     * @return array{outcome: string, reason: string, two_order_id: string}
     */
    public function confirm(int $orderId, string $token, \Closure $rebuild): array
    {
        $record = $this->authorised($orderId, $token);
        if ($record === null) {
            return self::outcome(self::UNAUTHORISED, 'The confirmation callback carried no valid token for this order.', '');
        }
        $twoOrderId = $record['two_order_id'];
        if ($twoOrderId === '') {
            return self::outcome(self::REFUSED, 'No Two order was created for this order, so there is nothing to confirm.', '');
        }

        $changed = $this->snapshotMismatch($record, $rebuild);
        if ($changed !== null) {
            $this->sendCancel($twoOrderId, 'order_changed', $orderId);
            $this->readState($orderId, $twoOrderId);

            return self::outcome($changed[0], 'Two order ' . $twoOrderId . ' not confirmed and cancelled: ' . $changed[1], $twoOrderId);
        }

        $state = $this->readState($orderId, $twoOrderId);
        if ($state === null) {
            return self::outcome(self::REFUSED, 'Two could not be asked for the state of order ' . $twoOrderId . '.', $twoOrderId);
        }
        if ($state === 'CANCELLED') {
            return self::outcome(self::CANCELLED, 'Two holds order ' . $twoOrderId . ' as cancelled.', $twoOrderId);
        }
        if (in_array($state, self::PLACED_STATES, true)) {
            return self::outcome(self::CONFIRMED, 'Two order ' . $twoOrderId . ' is ' . $state . '.', $twoOrderId);
        }
        if ($state !== 'VERIFIED') {
            return self::outcome(self::REFUSED, 'Two holds order ' . $twoOrderId . ' as ' . $state . ', so it was not confirmed.', $twoOrderId);
        }

        $path = '/v1/order/' . $twoOrderId . '/confirm';
        try {
            $body = $this->postprocessing->apply(Postprocessing::ORDER_CONFIRM, 'buyer_return', $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            return self::outcome(self::REFUSED, 'Two order ' . $twoOrderId . ' not confirmed: ' . $refused->detail, $twoOrderId);
        }
        $response = $this->client->request('POST', $path, $body);
        if (!$response->reachedTwo() || $response->status >= 400) {
            return self::outcome(self::REFUSED, 'Two refused to confirm order ' . $twoOrderId . ': ' . $this->errors->reason($response), $twoOrderId);
        }
        $this->records->put($orderId, self::stateFields($response->json() ?? []) + ['two_order_state' => 'CONFIRMED']);

        return self::outcome(self::CONFIRMED, 'Two order ' . $twoOrderId . ' confirmed.', $twoOrderId);
    }

    /**
     * Two sent the buyer back to the cancel URL. Cancels the Two order and
     * reads it back whatever the cancel answered: the order counts as
     * cancelled once Two says so, including when it already was.
     *
     * @return array{outcome: string, reason: string, two_order_id: string}
     */
    public function cancel(int $orderId, string $token): array
    {
        $record = $this->authorised($orderId, $token);
        if ($record === null) {
            return self::outcome(self::UNAUTHORISED, 'The cancel callback carried no valid token for this order.', '');
        }
        $twoOrderId = $record['two_order_id'];
        if ($twoOrderId === '') {
            return self::outcome(self::CANCELLED, 'No Two order was created for this order.', '');
        }
        $this->sendCancel($twoOrderId, 'buyer_cancel', $orderId);
        if ($this->readState($orderId, $twoOrderId) === 'CANCELLED') {
            return self::outcome(self::CANCELLED, 'The buyer cancelled Two order ' . $twoOrderId . ' at Two.', $twoOrderId);
        }

        return self::outcome(self::REFUSED, 'Two order ' . $twoOrderId . ' could not be confirmed as cancelled.', $twoOrderId);
    }

    /** Whether the token is the order's own, without asking Two anything. */
    public function owns(int $orderId, string $token): bool
    {
        return $this->authorised($orderId, $token) !== null;
    }

    /**
     * What the adapter does with a callback's outcome, given whether the shop
     * order is placed now (read after the flow ran, so a second hit on an
     * order the first hit placed never writes over it): place the order,
     * note the reason on the unplaced order, and where the buyer goes.
     *
     * @return array{place: bool, note: bool, goto: 'success'|'cart', notice: 'cancelled'|'changed'|'generic'|null}
     */
    public static function afterCallback(string $kind, string $outcome, bool $placed): array
    {
        if ($outcome === self::UNAUTHORISED) {
            return ['place' => false, 'note' => false, 'goto' => 'cart', 'notice' => 'generic'];
        }
        if ($kind === self::CALLBACK_CONFIRM && $placed) {
            return ['place' => false, 'note' => false, 'goto' => 'success', 'notice' => null];
        }
        if ($placed) {
            return ['place' => false, 'note' => false, 'goto' => 'cart', 'notice' => 'generic'];
        }
        if ($kind === self::CALLBACK_CONFIRM && $outcome === self::CONFIRMED) {
            return ['place' => true, 'note' => false, 'goto' => 'success', 'notice' => null];
        }

        $notice = match ($outcome) {
            self::CANCELLED => 'cancelled',
            self::CHANGED => 'changed',
            default => 'generic',
        };

        return ['place' => false, 'note' => true, 'goto' => 'cart', 'notice' => $notice];
    }

    /**
     * Where the buyer goes when another hit on the same order still holds it
     * after the wait. That hit may yet place the order and mail the buyer, so
     * the buyer never sees the failure sentence here: the success page when
     * the order is placed by now, otherwise a neutral notice.
     *
     * @return array{goto: 'success'|'cart', notice: 'pending'|null}
     */
    public static function afterLockTimeout(bool $placed): array
    {
        return $placed ? ['goto' => 'success', 'notice' => null] : ['goto' => 'cart', 'notice' => 'pending'];
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The create payload's identity: everything Two is asked to buy, without
     * the callback URLs (which carry the token).
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadHash(array $payload): string
    {
        unset($payload['merchant_urls']);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The payload as it is sent: built, shop-matched, through the hook.
     *
     * @param array<string, mixed>       $order
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $totals
     * @param array<string, mixed>       $context
     * @return array<string, mixed>
     */
    private function payload(array $order, array $lines, array $totals, array $context, string $trigger): array
    {
        $payload = $this->builder->build($order, $lines, $totals);
        $failures = OrderBuilder::shopMatchFailures($payload, $order);

        return $this->postprocessing->apply(Postprocessing::ORDER_CREATE, $trigger, '/v1/order', $payload, $context + ['order_id' => (int) $order['order_id']], $failures);
    }

    /**
     * Why the shop's stored order no longer rebuilds the payload Two holds,
     * as the outcome (CHANGED when it rebuilds to a different payload) and
     * the reason, or null when it still does.
     *
     * @param array<string, string> $record
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     * @return array{0: string, 1: string}|null
     */
    public function snapshotMismatch(array $record, \Closure $rebuild): ?array
    {
        if (($record['payload_hash'] ?? '') === '') {
            return [self::REFUSED, 'no payload hash was stored at create.'];
        }
        $input = $rebuild($record);
        if ($input === null) {
            return [self::REFUSED, 'the shop order can no longer be read.'];
        }
        try {
            $payload = $this->payload($input['order'], $input['lines'], $input['totals'], $input['context'] ?? [], 'snapshot_hash');
        } catch (OrderRefused $refused) {
            return [self::REFUSED, 'the shop order no longer builds: ' . $refused->detail];
        }

        return hash_equals($record['payload_hash'], self::payloadHash($payload)) ? null : [self::CHANGED, 'the shop order changed after the Two order was created (cart, amounts or addresses).'];
    }

    /**
     * @return array<string, string>|null the order's record when the token is its own
     */
    private function authorised(int $orderId, string $token): ?array
    {
        $record = $this->records->get($orderId);
        if ($record === null || $token === '' || ($record['token_hash'] ?? '') === '') {
            return null;
        }
        if (!hash_equals($record['token_hash'], self::tokenHash($token))) {
            return null;
        }
        $twoOrderId = $record['two_order_id'] ?? '';

        return $twoOrderId === '' || self::validTwoOrderId($twoOrderId) ? $record + ['two_order_id' => ''] : null;
    }

    /** Two's state for the order, stored as read; null when Two did not answer with one. */
    public function readState(int $orderId, string $twoOrderId): ?string
    {
        $response = $this->client->request('GET', '/v1/order/' . $twoOrderId);
        $body = $response->json();
        if (!$response->reachedTwo() || $response->status >= 400 || !is_string($body['state'] ?? null)) {
            $this->logger->error('Two order ' . $twoOrderId . ' state could not be read: ' . $this->errors->reason($response));

            return null;
        }
        $this->records->put($orderId, self::stateFields($body));

        return strtoupper($body['state']);
    }

    /**
     * Sends the cancel of a whole Two order, through the hook, with an
     * idempotency key.
     *
     * @return string|null why it was not sent or Two refused it, null when Two accepted it
     */
    public function sendCancel(string $twoOrderId, string $trigger, int $orderId): ?string
    {
        $path = '/v1/order/' . $twoOrderId . '/cancel';
        try {
            $body = $this->postprocessing->apply(Postprocessing::CANCEL, $trigger, $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            $this->logger->error('Two order ' . $twoOrderId . ' cancel not sent: ' . $refused->detail);

            return 'not sent: ' . $refused->detail;
        }
        $response = $this->client->request('POST', $path, $body, Client::TIMEOUT_SECONDS, true, self::idempotencyHeader($twoOrderId, 'cancel'));
        if (!$response->reachedTwo() || $response->status >= 400) {
            $reason = $this->errors->reason($response);
            $this->logger->error('Two order ' . $twoOrderId . ' cancel refused: ' . $reason);

            return $reason;
        }

        return null;
    }

    /**
     * The idempotency key for one whole-order action on one Two order. It is
     * the same on every attempt, so a retried event is the same request; the
     * plugin's own guard against a repeat is the stored and live Two state.
     *
     * @return array<string, string>
     */
    public static function idempotencyHeader(string $twoOrderId, string $action): array
    {
        return ['Idempotency-Key' => 'oc-' . $action . '-' . $twoOrderId];
    }

    /**
     * @param list<string> $buyer
     * @return array{ok: false, buyer: non-empty-list<string>, reason: string}
     */
    private function refused(int $orderId, string $reason, array $buyer = []): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $reason);

        return ['ok' => false, 'buyer' => $buyer === [] ? [ErrorMapper::GENERIC] : $buyer, 'reason' => $reason];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    public static function stateFields(array $body): array
    {
        $fields = [];
        foreach (['state' => 'two_order_state', 'status' => 'two_order_status'] as $key => $field) {
            if (is_string($body[$key] ?? null)) {
                $fields[$field] = strtoupper($body[$key]);
            }
        }

        return $fields;
    }

    /** Two's order ids are UUIDs; anything else is never put in a path. */
    public static function validTwoOrderId(string $id): bool
    {
        return preg_match('~^[A-Za-z0-9-]{1,64}\z~', $id) === 1;
    }

    /**
     * @return array{outcome: string, reason: string, two_order_id: string}
     */
    private static function outcome(string $outcome, string $reason, string $twoOrderId): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'two_order_id' => $twoOrderId];
    }
}
