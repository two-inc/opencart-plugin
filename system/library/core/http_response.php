<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/** What came back from Two, as received. */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-cased names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /**
     * The body as a JSON object, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
