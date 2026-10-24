<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: one outbound HTTP call. The adapter implements it (cURL in practice);
 * the Client owns everything above the wire: hosts, headers, keys, logging.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        bool $verifyTls,
        int $timeoutSeconds,
    ): HttpResponse;
}
