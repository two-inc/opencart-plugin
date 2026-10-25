<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The placed order's lifecycle at Two, driven by the merchant's status maps
 * (Build Guide section 12): moving an order into a mapped fulfilment status
 * fulfils the whole Two order, into the mapped cancel status cancels it.
 * Plus the invoice download once the order is fulfilled.
 *
 * - Whole orders only. Partial fulfilment, refunds and order-edit sync are
 *   later work; the refund status map is not acted on yet.
 * - Idempotent: the stored Two state and then Two's live state decide
 *   whether anything is sent, so a repeated event or a re-save never fulfils
 *   or cancels twice. Each call carries an idempotency key as well.
 * - Before fulfilling, the shop's stored order must still rebuild the
 *   payload Two holds (the create payload's hash), so Two never invoices an
 *   order the merchant has since changed.
 * - Outcomes are plain arrays. Every outcome but SKIPPED carries a reason
 *   for the order history, so the merchant always sees what happened; the
 *   merchant's status change itself is never undone.
 */
final class OrderLifecycle
{
    public const FULFIL = 'fulfil';
    public const CANCEL = 'cancel';

    /** Two accepted the request. */
    public const DONE = 'done';
    /** Nothing to send: Two already holds the order that way. */
    public const SKIPPED = 'skipped';
    /** Not sent, or Two refused it. */
    public const REFUSED = 'refused';

    /** Two order states in which fulfilment has been asked for already. */
    public const FULFILLED_STATES = ['FULFILLING', 'FULFILLED', 'PARTIALLY_FULFILLED', 'REFUNDED', 'PARTIALLY_REFUNDED'];

    public const INVOICE_NOT_READY = 'not_ready';
    public const INVOICE_UNAVAILABLE_STATE = 'unavailable_state';
    public const INVOICE_NO_REFERENCE = 'no_reference';
    public const INVOICE_ERROR = 'error';

    /** The invoice fetch runs inside an admin page request, so it never waits the API default. */
    public const INVOICE_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly OrderFlow $flow,
        private readonly Client $client,
        private readonly Postprocessing $postprocessing,
        private readonly ErrorMapper $errors,
        private readonly OrderRecords $records,
        private readonly Logger $logger,
    ) {
    }

    /**
     * What moving an order into a status asks of Two: FULFIL, CANCEL or null.
     * A status in both maps fulfils, the order the settings page lists them.
     *
     * @param list<int> $fulfilStatusIds
     */
    public static function action(int $statusId, array $fulfilStatusIds, ?int $cancelStatusId): ?string
    {
        if ($statusId <= 0) {
            return null;
        }
        if (in_array($statusId, $fulfilStatusIds, true)) {
            return self::FULFIL;
        }

        return $statusId === $cancelStatusId ? self::CANCEL : null;
    }

    /**
     * Fulfils the whole Two order.
     *
     * @param \Closure(array<string, string>): (array{order: array<string, mixed>, lines: list<array<string, mixed>>, totals: list<array<string, mixed>>, context?: array<string, mixed>}|null) $rebuild
     *        the shop's stored order as create's inputs, as OrderFlow::confirm takes it
     * @return array{outcome: string, reason: string}
     */
    public function fulfil(int $orderId, \Closure $rebuild): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, 'Not fulfilled: no Two order is linked to this order.');
        }
        $stored = $record['two_order_state'] ?? '';
        if (in_array($stored, self::FULFILLED_STATES, true)) {
            return self::outcome(self::SKIPPED, 'Two order ' . $twoOrderId . ' is already ' . $stored . '.');
        }
        if ($stored === 'CANCELLED') {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' is cancelled at Two, so it was not fulfilled.');
        }

        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === null) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not fulfilled: Two could not be asked for its state. Save the status again to retry.');
        }
        if (in_array($state, self::FULFILLED_STATES, true)) {
            return self::outcome(self::SKIPPED, 'Two order ' . $twoOrderId . ' is already ' . $state . '.');
        }
        if ($state !== 'CONFIRMED') {
            return $this->refused($orderId, 'Two holds order ' . $twoOrderId . ' as ' . $state . ', so it was not fulfilled.');
        }

        $mismatch = $this->flow->snapshotMismatch($record, $rebuild);
        if ($mismatch !== null) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not fulfilled: ' . $mismatch[1] . ' Two still holds the order as it was placed.');
        }

        $path = '/v1/order/' . $twoOrderId . '/fulfillments';
        try {
            $body = $this->postprocessing->apply(Postprocessing::CAPTURE, 'status_change', $path, [], ['order_id' => $orderId]);
        } catch (OrderRefused $refused) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not fulfilled: ' . $refused->detail);
        }
        $response = $this->client->request('POST', $path, $body, Client::TIMEOUT_SECONDS, true, OrderFlow::idempotencyHeader($twoOrderId, self::FULFIL));
        if (!$response->reachedTwo() || $response->status >= 400) {
            return $this->refused($orderId, 'Two refused to fulfil order ' . $twoOrderId . ': ' . $this->errors->reason($response) . ' Save the status again to retry.');
        }
        // Marks the fulfilment as asked for before the read back, so a repeat skips even when the read fails.
        $this->records->put($orderId, ['two_order_state' => 'FULFILLING']);
        $after = $this->flow->readState($orderId, $twoOrderId) ?? 'FULFILLING';

        return self::outcome(self::DONE, 'Two order ' . $twoOrderId . ' fulfilled (' . $after . '). The invoice is on the order page under Two.');
    }

    /**
     * Cancels the whole Two order, and reads it back whatever the cancel
     * answered: it counts as cancelled once Two says so.
     *
     * @return array{outcome: string, reason: string}
     */
    public function cancel(int $orderId): array
    {
        $record = $this->records->get($orderId);
        $twoOrderId = $record['two_order_id'] ?? '';
        if ($record === null || !OrderFlow::validTwoOrderId($twoOrderId)) {
            return $this->refused($orderId, 'Not cancelled: no Two order is linked to this order.');
        }
        $stored = $record['two_order_state'] ?? '';
        if ($stored === 'CANCELLED') {
            return self::outcome(self::SKIPPED, 'Two order ' . $twoOrderId . ' is already cancelled.');
        }
        if (in_array($stored, self::FULFILLED_STATES, true)) {
            return $this->refused($orderId, 'Two order ' . $twoOrderId . ' is ' . $stored . ', so it was not cancelled. A fulfilled order is refunded, not cancelled.');
        }

        $failure = $this->flow->sendCancel($twoOrderId, 'status_change', $orderId);
        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === 'CANCELLED') {
            return self::outcome(self::DONE, 'Two order ' . $twoOrderId . ' cancelled.');
        }

        return $this->refused($orderId, 'Two order ' . $twoOrderId . ' not cancelled' . ($failure === null ? '' : ': ' . $failure) . '. Two holds it as ' . ($state ?? 'unknown') . '.'
            . (in_array($state, self::FULFILLED_STATES, true) ? ' A fulfilled order is refunded, not cancelled.' : ' Save the status again to retry.'));
    }

    /**
     * The order's invoice PDF, or why there is none. When Two answers that
     * the order is not fulfilled, its state decides the notice, and an order
     * that has just become FULFILLED is asked once more.
     *
     * @return array{pdf: string}|array{notice: string, state: string}
     */
    public function invoice(int $orderId): array
    {
        $twoOrderId = $this->records->get($orderId)['two_order_id'] ?? '';
        if (!OrderFlow::validTwoOrderId($twoOrderId)) {
            return ['notice' => self::INVOICE_NO_REFERENCE, 'state' => ''];
        }
        $response = $this->fetchInvoice($twoOrderId);
        if (self::isPdf($response)) {
            return ['pdf' => $response->body];
        }
        if ($response->status !== 400 || strtoupper((string) ($response->json()['error_code'] ?? '')) !== 'ORDER_NOT_FULFILLED') {
            $this->logger->error('Order ' . $orderId . ': the invoice for Two order ' . $twoOrderId . ' could not be fetched: ' . $this->errors->reason($response));

            return ['notice' => self::INVOICE_ERROR, 'state' => ''];
        }

        $state = $this->flow->readState($orderId, $twoOrderId);
        if ($state === null) {
            return ['notice' => self::INVOICE_ERROR, 'state' => ''];
        }
        if ($state === 'FULFILLING') {
            return ['notice' => self::INVOICE_NOT_READY, 'state' => $state];
        }
        if ($state !== 'FULFILLED') {
            return ['notice' => self::INVOICE_UNAVAILABLE_STATE, 'state' => $state];
        }
        $retry = $this->fetchInvoice($twoOrderId);
        if (self::isPdf($retry)) {
            return ['pdf' => $retry->body];
        }
        $this->logger->error('Order ' . $orderId . ': the invoice for fulfilled Two order ' . $twoOrderId . ' could not be fetched: ' . $this->errors->reason($retry));

        return ['notice' => self::INVOICE_ERROR, 'state' => $state];
    }

    private function fetchInvoice(string $twoOrderId): HttpResponse
    {
        return $this->client->request('GET', '/v1/invoice/' . $twoOrderId . '/pdf', null, self::INVOICE_TIMEOUT_SECONDS, true, ['Accept' => 'application/pdf']);
    }

    /** A 200 that really is a PDF: an error page is never served as one. */
    private static function isPdf(HttpResponse $response): bool
    {
        return $response->reachedTwo() && $response->status === 200 && str_starts_with($response->body, '%PDF-');
    }

    /** @return array{outcome: string, reason: string} */
    private function refused(int $orderId, string $reason): array
    {
        $this->logger->error('Order ' . $orderId . ': ' . $reason);

        return self::outcome(self::REFUSED, $reason);
    }

    /** @return array{outcome: string, reason: string} */
    private static function outcome(string $outcome, string $reason): array
    {
        return ['outcome' => $outcome, 'reason' => $reason];
    }
}
