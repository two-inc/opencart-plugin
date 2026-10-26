<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Port: one outbound HTTP call. The adapter implements it (cURL in practice);
 * the Client owns everything above the wire: hosts, headers, keys, logging.
 * A call that gets no HTTP answer at all returns HttpResponse::unreachable()
 * rather than throwing, so callers can tell "Two said no" from "nothing came
 * back".
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

    /**
     * Several calls at once, each answered as send() answers it, in the
     * order given. Every call shares the one timeout.
     *
     * @param list<array{method: string, url: string, headers: array<string, string>, body: string|null}> $requests
     * @return list<HttpResponse>
     */
    public function sendAll(array $requests, bool $verifyTls, int $timeoutSeconds): array;
}
