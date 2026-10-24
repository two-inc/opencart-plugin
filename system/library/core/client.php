<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The one outbound client to Two (Build Guide section 13.3): environment
 * host, API key header, client and version query parameters, custom headers
 * re-validated on every request, TLS on unless the merchant bypasses it,
 * timeout and structured logging. No other class talks to Two.
 *
 * Behaviour lands in P1.
 */
final class Client
{
    /** Sent as ?client= on every call so Two can tell OpenCart traffic apart. */
    public const CLIENT_CODE = 'OC';
    public const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Environment $environment,
        private readonly SettingsProvider $settings,
        private readonly CustomHeaders $customHeaders,
        private readonly Transport $transport,
        private readonly Logger $logger,
        private readonly string $pluginVersion,
    ) {
    }

    /**
     * @param string                    $path starting with a slash, for example /v1/order
     * @param array<string, mixed>|null $body sent as JSON when not null
     */
    public function request(string $method, string $path, ?array $body = null): HttpResponse
    {
        throw NotImplemented::inPhase('Client::request', 'P1');
    }

    public function pluginVersion(): string
    {
        return $this->pluginVersion;
    }
}
