<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Turns a Two error response into what the buyer may see (Build Guide
 * section 6.4): a language key for a field-mapped message or the generic
 * sentence, never Two's own text. The full reason goes to the log instead.
 *
 * Behaviour lands in P3.
 */
final class ErrorMapper
{
    /**
     * @param array<string, mixed>|null $errorBody the decoded response, null when it was not JSON
     */
    public function buyerMessageKey(int $status, ?array $errorBody): string
    {
        throw NotImplemented::inPhase('ErrorMapper::buyerMessageKey', 'P3');
    }
}
