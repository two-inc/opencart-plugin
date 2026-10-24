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
    public const UNAUTHORISED = 'unauthorised';

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
     * @param array<string, mixed>       $order  OrderBuilder's order shape, merchant_urls carrying the token
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $totals
     * @param array<string, mixed>       $context platform objects for the hook
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

        try {
            $payload = $this->builder->build($order, $lines, $totals);
            $failures = OrderBuilder::shopMatchFailures($payload, $order);
            $payload = $this->postprocessing->apply(Postprocessing::ORDER_CREATE, 'checkout', '/v1/order', $payload, $context + ['order_id' => $orderId], $failures);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, 'Two order not created: ' . $refused->detail);
        }

        $this->records->put($orderId, [
            'token_hash' => self::tokenHash($token),
            'two_order_id' => '',
            'two_order_state' => '',
            'two_order_status' => '',
            'company_name' => (string) $company['company_name'],
            'organization_number' => (string) $company['organization_number'],
            'country_code' => (string) $company['country_code'],
        ]);

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
     * order; an order Two already holds as placed confirms again harmlessly.
     *
     * @return array{outcome: string, reason: string, two_order_id: string}
     */
    public function confirm(int $orderId, string $token): array
    {
        $record = $this->authorised($orderId, $token);
        if ($record === null) {
            return self::outcome(self::UNAUTHORISED, 'The confirmation callback carried no valid token for this order.', '');
        }
        $twoOrderId = $record['two_order_id'];
        if ($twoOrderId === '') {
            return self::outcome(self::REFUSED, 'No Two order was created for this order, so there is nothing to confirm.', '');
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
            $this->postprocessing->apply(Postprocessing::ORDER_CONFIRM, 'buyer_return', $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            return self::outcome(self::REFUSED, 'Two order ' . $twoOrderId . ' not confirmed: ' . $refused->detail, $twoOrderId);
        }
        $response = $this->client->request('POST', $path, []);
        if (!$response->reachedTwo() || $response->status >= 400) {
            return self::outcome(self::REFUSED, 'Two refused to confirm order ' . $twoOrderId . ': ' . $this->errors->reason($response), $twoOrderId);
        }
        $this->records->put($orderId, self::stateFields($response->json() ?? []) + ['two_order_state' => 'CONFIRMED']);

        return self::outcome(self::CONFIRMED, 'Two order ' . $twoOrderId . ' confirmed.', $twoOrderId);
    }

    /**
     * Two sent the buyer back to the cancel URL. Cancels the Two order and
     * reads it back: the order counts as cancelled only once Two says so.
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
        if ($this->sendCancel($twoOrderId, 'buyer_cancel', $orderId) && $this->readState($orderId, $twoOrderId) === 'CANCELLED') {
            return self::outcome(self::CANCELLED, 'The buyer cancelled Two order ' . $twoOrderId . ' at Two.', $twoOrderId);
        }

        return self::outcome(self::REFUSED, 'Two order ' . $twoOrderId . ' could not be confirmed as cancelled.', $twoOrderId);
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
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
    private function readState(int $orderId, string $twoOrderId): ?string
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

    private function sendCancel(string $twoOrderId, string $trigger, int $orderId): bool
    {
        $path = '/v1/order/' . $twoOrderId . '/cancel';
        try {
            $this->postprocessing->apply(Postprocessing::CANCEL, $trigger, $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            $this->logger->error('Two order ' . $twoOrderId . ' cancel not sent: ' . $refused->detail);

            return false;
        }
        $response = $this->client->request('POST', $path, []);
        if (!$response->reachedTwo() || $response->status >= 400) {
            $this->logger->error('Two order ' . $twoOrderId . ' cancel refused: ' . $this->errors->reason($response));

            return false;
        }

        return true;
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
    private static function stateFields(array $body): array
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
    private static function validTwoOrderId(string $id): bool
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
