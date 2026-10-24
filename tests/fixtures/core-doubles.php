<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\Logger;
use Opencart\System\Library\Extension\Two\Core\SettingsStore;
use Opencart\System\Library\Extension\Two\Core\Transport;

// CLI only: the staging shops serve the whole repo under the web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** The SettingsStore port over an array, for the core specs. */
final class MemoryStore implements SettingsStore
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function setMany(array $values): void
    {
        $this->values = $values + $this->values;
    }
}

/** The Transport port answering from a script, one response per call, recording every call. */
class ScriptedTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, verifyTls: bool, timeout: int}> */
    public array $calls = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls, int $timeoutSeconds): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'verifyTls' => $verifyTls, 'timeout' => $timeoutSeconds];

        return array_shift($this->responses) ?? HttpResponse::unreachable('script exhausted');
    }
}

/** The Logger port recording every line, debug included. */
final class RecordingLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    public function error(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->lines[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
    }

    /** Everything logged, as one string, for "never logged" checks. */
    public function dump(): string
    {
        return (string) json_encode($this->lines);
    }
}
