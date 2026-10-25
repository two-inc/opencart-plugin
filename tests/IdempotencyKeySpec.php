<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\IdempotencyKey;

/**
 * The Idempotency-Key shapes, one per write operation, and the guard on
 * what a key may hold (lowercase ASCII, at most 100 characters).
 */
final class IdempotencyKeySpec
{
    private const ID = '5F1C2A7E-0000-4000-8000-000000000001';

    public static function runAll(): void
    {
        $hash = hash('sha256', 'token');
        $shapes = [
            [IdempotencyKey::orderCreate(42, $hash), 'create_42_' . substr($hash, 0, 16), 'order create: shop order id and the attempt\'s token hash'],
            [IdempotencyKey::forTwoOrder(IdempotencyKey::CONFIRM, self::ID), 'confirm_5f1c2a7e-0000-4000-8000-000000000001', 'confirm: the Two order id, lowercased'],
            [IdempotencyKey::forTwoOrder(IdempotencyKey::FULFIL, self::ID), 'fulfil_5f1c2a7e-0000-4000-8000-000000000001', 'whole-order fulfil'],
            [IdempotencyKey::forTwoOrder(IdempotencyKey::CANCEL, self::ID), 'cancel_5f1c2a7e-0000-4000-8000-000000000001', 'cancel'],
            [IdempotencyKey::header('fulfil_x'), ['Idempotency-Key' => 'fulfil_x'], 'the header name, never X-Idempotency-Key'],
            [IdempotencyKey::in(['idempotency-key' => 'a']), 'a', 'found whatever the header name\'s case'],
            [IdempotencyKey::in(['Accept' => 'application/pdf']), null, 'none among other headers'],
        ];
        foreach ($shapes as [$actual, $expected, $description]) {
            TinyAssert::same($expected, $actual, 'idempotency key: ' . $description);
        }

        $refused = [
            [static fn () => IdempotencyKey::header(''), 'an empty key'],
            [static fn () => IdempotencyKey::header('Fulfil_x'), 'upper case'],
            [static fn () => IdempotencyKey::header('fulfil x'), 'a space'],
            [static fn () => IdempotencyKey::header(str_repeat('a', 101)), 'over 100 characters'],
            [static fn () => IdempotencyKey::forTwoOrder('refund', self::ID), 'an operation that is not whole-order'],
            [static fn () => IdempotencyKey::forTwoOrder(IdempotencyKey::CANCEL, 'a/b'), 'a Two order id that is not an id'],
        ];
        foreach ($refused as [$call, $description]) {
            TinyAssert::throws($call, InvalidArgumentException::class, 'idempotency key refuses ' . $description);
        }
        TinyAssert::same(100, strlen(IdempotencyKey::header(str_repeat('a', 100))['Idempotency-Key']), 'idempotency key: exactly 100 characters is allowed');
    }
}
