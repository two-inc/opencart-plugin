<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The order postprocessing hook (Build Guide section 21): one choke point that
 * every outbound order payload passes through, whatever the request type, so
 * a shop can adjust it. The adapter exposes it as an OpenCart event; handlers
 * registered here run in order.
 *
 * Behaviour lands in P3.
 */
final class Postprocessing
{
    /** @var list<callable(array<string, mixed>, string): array<string, mixed>> */
    private array $handlers = [];

    /**
     * @param callable(array<string, mixed>, string): array<string, mixed> $handler receives the payload and the request type
     */
    public function register(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(string $requestType, array $payload): array
    {
        throw NotImplemented::inPhase('Postprocessing::apply', 'P3');
    }
}
