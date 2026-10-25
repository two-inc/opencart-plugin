<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The `Idempotency-Key` every write request to Two carries (POST, PUT,
 * PATCH, DELETE; never a GET), whether or not the endpoint reads it today.
 * Two deduplicates on it where it does (refunds, partial fulfilments), and
 * the rest are ready when it starts to.
 *
 * A key names one logical operation: the same on every retry or re-trigger
 * of it (a repeated status event, an admin re-save), different for any
 * other. It is built from durable ids only, never from time, randomness or
 * an in-memory counter, as `<operation>_<ids>`, lowercase ASCII, at most
 * 100 characters. Client refuses a write without one, and logs the key
 * with the request.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';
    public const MAX_LENGTH = 100;
    public const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public const CONFIRM = 'confirm';
    public const FULFIL = 'fulfil';
    public const CANCEL = 'cancel';

    private const SHAPE = '~^[a-z0-9_-]{1,100}\z~';

    /**
     * Order create: one key per checkout attempt. Each attempt gets a fresh
     * callback token, so the key is the shop order id plus the start of the
     * token's stored hash (never the token itself): a new attempt on the same
     * cart, whatever its company, gets a new key.
     */
    public static function orderCreate(int $orderId, string $tokenHash): string
    {
        return self::checked('create_' . $orderId . '_' . substr(strtolower($tokenHash), 0, 16));
    }

    /** A whole-order write on one Two order: confirm, fulfil or cancel. */
    public static function forTwoOrder(string $operation, string $twoOrderId): string
    {
        if (!in_array($operation, [self::CONFIRM, self::FULFIL, self::CANCEL], true)) {
            throw new \InvalidArgumentException('Unknown whole-order operation "' . $operation . '".');
        }

        return self::checked($operation . '_' . strtolower($twoOrderId));
    }

    /**
     * The request header carrying a key.
     *
     * @return array<string, string>
     */
    public static function header(string $key): array
    {
        return [self::HEADER => self::checked($key)];
    }

    /**
     * The key among a request's headers, matched case-insensitively; null when there is none.
     *
     * @param array<string, string> $headers
     */
    public static function in(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, self::HEADER) === 0 && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function checked(string $key): string
    {
        if (preg_match(self::SHAPE, $key) !== 1) {
            throw new \InvalidArgumentException('An idempotency key is lowercase ASCII letters, digits, _ and -, at most ' . self::MAX_LENGTH . ' characters.');
        }

        return $key;
    }
}
