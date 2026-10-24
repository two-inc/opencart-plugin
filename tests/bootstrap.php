<?php

declare(strict_types=1);

require dirname(__DIR__) . '/dev/autoload.php';

/** The harness's assertions. Every message names the case that failed. */
final class TinyAssert
{
    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(bool $value, string $message): void
    {
        self::same(true, $value, $message);
    }

    public static function throws(callable $callback, string $expectedClass, string $message): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new RuntimeException($message . ': expected ' . $expectedClass . ', got ' . get_class($e) . ' (' . $e->getMessage() . ')');
            }

            return;
        }

        throw new RuntimeException($message . ': expected ' . $expectedClass . ', nothing was thrown');
    }
}
