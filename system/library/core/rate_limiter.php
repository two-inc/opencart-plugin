<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Limits on the checkout's relays to Two, so a visitor with a cart cannot
 * drive unlimited searches, token mints or order intents on the merchant's
 * key. Two kinds, as the reference plugin has:
 *
 * - per caller (the client address; an IPv6 address counts as its /64): a
 *   fixed window per action, counted in the shop's database (CALLER_LIMITS);
 * - per checkout session: the order intent, at most SESSION_INTENT_LIMIT in a
 *   sliding SESSION_INTENT_WINDOW, counted in the session.
 *
 * The counting is pure; the adapter keeps the counts.
 */
final class RateLimiter
{
    /** Per caller: action => [most requests, window in seconds]. */
    public const CALLER_LIMITS = [
        'intent' => [30, 60],
        'saveCompany' => [60, 60],
        'soleTrader' => [60, 60],
        'soleTraderTokens' => [60, 60],
        'companyDetails' => [60, 60],
        'search' => [300, 60],
    ];
    public const SESSION_INTENT_LIMIT = 5;
    public const SESSION_INTENT_WINDOW = 60;
    private const IPV6_BUCKET_BYTES = 8;

    /**
     * Counts one request in a fixed window: the window it belongs to, its
     * count, and whether it is within the limit.
     *
     * @param array{window_start: int, hit_count: int}|null $row the caller's stored window for the action
     * @return array{window_start: int, hit_count: int, allowed: bool}
     */
    public static function hit(string $action, ?array $row, int $now): array
    {
        [$max, $window] = self::CALLER_LIMITS[$action] ?? [PHP_INT_MAX, 60];
        $current = $row !== null && $now - $row['window_start'] < $window;
        $start = $current ? $row['window_start'] : $now;
        $count = ($current ? $row['hit_count'] : 0) + 1;

        return ['window_start' => $start, 'hit_count' => $count, 'allowed' => $count <= $max];
    }

    /**
     * Counts one intent in the session's sliding window: the timestamps to
     * keep, and whether this one is within the limit (a refused one is not kept).
     *
     * @param list<int> $stamps
     * @return array{stamps: list<int>, allowed: bool}
     */
    public static function sessionIntent(array $stamps, int $now): array
    {
        $recent = array_values(array_filter($stamps, static fn (int $at): bool => $at > 0 && $now - $at < self::SESSION_INTENT_WINDOW));
        if (count($recent) >= self::SESSION_INTENT_LIMIT) {
            return ['stamps' => $recent, 'allowed' => false];
        }
        $recent[] = $now;

        return ['stamps' => $recent, 'allowed' => true];
    }

    /** The key a caller's count is kept under for an action: hashed, so no address is stored. */
    public static function key(string $action, string $address): string
    {
        $address = trim($address);
        $packed = $address === '' ? false : @inet_pton($address);
        if ($packed !== false && strlen($packed) === 16) {
            $address = (string) inet_ntop(substr($packed, 0, self::IPV6_BUCKET_BYTES) . str_repeat("\0", 16 - self::IPV6_BUCKET_BYTES)) . '/64';
        }

        return hash('sha256', $action . "\0" . ($address === '' ? 'unknown' : $address));
    }
}
