<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Merchant-defined request headers (Build Guide section 3.5): validated on
 * save with fully anchored rules, and validated again on read so a row edited
 * in the database can never reach the wire. Reserved names cover what the
 * Client itself sets.
 *
 * The read path lands in P1, the save path (halt and show) in P2.
 */
final class CustomHeaders
{
    /**
     * Names a row may not take: what the Client or cURL sets, the hop-by-hop
     * headers, headers that change how a response or connection is framed,
     * and credentials or caller identity that must not be forgeable.
     */
    public const RESERVED = [
        'host', 'content-type', 'content-length', 'accept', 'accept-language', 'accept-encoding',
        'x-api-key', 'x-vendor-name', 'x-forwarded-for', 'x-real-ip',
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
        'transfer-encoding', 'upgrade', 'expect', 'authorization', 'cookie',
    ];

    /** RFC 9110 field-name token. \z, because $ also matches before a trailing newline. */
    private const NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/';

    /** Printable ASCII only: no CR or LF (request splitting), no control bytes, no non-ASCII. */
    private const VALUE = '/^[\x20-\x7E]+\z/';

    /**
     * Rows that pass validation, as header name => value. A row that fails is
     * dropped, never repaired. The first row wins when two share a name.
     *
     * @param list<array{name: string, value: string}> $rows
     * @return array<string, string>
     */
    public function forRequest(array $rows): array
    {
        $headers = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = trim($row['name']);
            $value = trim($row['value']);
            $lower = strtolower($name);
            if (!self::isSendable($name, $value) || isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * One error per rejected row, keyed by row index; empty when every row is
     * valid. The save is refused as a whole when this is not empty.
     *
     * @param list<array{name: string, value: string}> $rows
     * @return array<int, string>
     */
    public function validate(array $rows): array
    {
        throw NotImplemented::inPhase('CustomHeaders::validate', 'P2');
    }

    private static function isSendable(string $name, string $value): bool
    {
        return preg_match(self::NAME, $name) === 1
            && !in_array(strtolower($name), self::RESERVED, true)
            && preg_match(self::VALUE, $value) === 1;
    }
}
