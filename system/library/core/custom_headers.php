<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Merchant-defined request headers (Build Guide section 3.5): validated on
 * save with fully anchored rules, and validated again on read so a row edited
 * in the database can never reach the wire. Reserved names cover what the
 * Client itself sets.
 *
 * Behaviour lands in P1 (read path) and P2 (save path).
 */
final class CustomHeaders
{
    /**
     * Rows that pass validation, as header name => value.
     *
     * @param list<array{name: string, value: string}> $rows
     * @return array<string, string>
     */
    public function forRequest(array $rows): array
    {
        throw NotImplemented::inPhase('CustomHeaders::forRequest', 'P1');
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
}
