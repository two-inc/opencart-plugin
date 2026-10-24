<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The one outbound client to Two (Build Guide section 13.3): environment
 * host, API key header, client and version query parameters, custom headers
 * re-validated on every request, TLS on unless the merchant bypasses it,
 * timeout and structured logging. No other class talks to Two.
 *
 * The API key travels only in the X-API-Key header. It is never put in a URL,
 * a log message or a log context: logged headers are redacted by name, and
 * every custom header value is redacted too, since a merchant may put a
 * secret there.
 */
final class Client
{
    /** Sent as ?client= on every call so Two can tell OpenCart traffic apart. */
    public const CLIENT_CODE = 'OC';
    public const TIMEOUT_SECONDS = 30;

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const REDACTED = '[REDACTED]';
    /**
     * Shorter values are not scrubbed from log text: replacing a one or two
     * character header value would mangle every debug line. Their header is
     * still redacted by name.
     */
    public const MIN_SCRUB_LENGTH = 4;
    private const SECRET_HEADERS = ['x-api-key', 'authorization', 'proxy-authorization'];

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
     * One call to Two's API in the configured environment. A transport failure
     * comes back as a response with status 0, never as an exception.
     *
     * @param string                    $path          starting with a slash, for example /v1/order
     * @param array<string, mixed>|null $body          sent as JSON when not null
     * @param bool                      $authenticated false for the calls Two takes without a key (the order intent), so the key is never sent where it is not needed
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        int $timeoutSeconds = self::TIMEOUT_SECONDS,
        bool $authenticated = true,
    ): HttpResponse {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method "' . $method . '".');
        }
        if (preg_match('~^/[^\s?#]*(\?[^\s#]*)?\z~', $path) !== 1) {
            throw new \InvalidArgumentException('A Two API path starts with a slash and holds no whitespace or fragment.');
        }

        $params = ['client' => self::CLIENT_CODE, 'client_v' => $this->pluginVersion];
        $url = $this->environment->apiBaseUrl($this->settings->environment())
            . $path . (str_contains($path, '?') ? '&' : '?') . http_build_query($params);
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $custom = $this->customHeaders->forRequest($this->settings->customHeaderRows());
        $headers = $this->headers($custom, $authenticated);
        $verifyTls = $this->settings->verifyTls();
        $timeoutSeconds = max(1, $timeoutSeconds);

        $response = $this->transport->send($method, $url, $headers, $payload, $verifyTls, $timeoutSeconds);

        $this->logExchange($method, $path, $params, $headers, array_keys($custom), $body, $verifyTls, $response);

        return $response;
    }

    public function pluginVersion(): string
    {
        return $this->pluginVersion;
    }

    /**
     * This client for other credentials (a key and environment the merchant
     * has typed but not saved), every other setting as stored. Its settings
     * are read-only, so nothing it learns is cached as the stored key's.
     */
    public function withCredentials(string $apiKey, string $environment): self
    {
        $settings = new SettingsProvider(new SettingsOverlay($this->settings->store(), ['api_key' => $apiKey, 'environment' => $environment]));

        return new self($this->environment, $settings, $this->customHeaders, $this->transport, $this->logger, $this->pluginVersion);
    }

    /**
     * Custom headers first, then what the Client owns, so a reserved name can
     * never be overridden even if a row slipped past CustomHeaders.
     *
     * @param array<string, string> $custom
     * @return array<string, string>
     */
    private function headers(array $custom, bool $authenticated): array
    {
        $headers = $custom;
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['Accept'] = 'application/json';
        $apiKey = $this->settings->apiKey();
        if ($authenticated && $apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        return $headers;
    }

    /**
     * Debug log of the exchange (written only with debug logging on, which
     * the Logger adapter decides), plus an error line for a transport
     * failure or an HTTP error whatever the debug setting. Secret headers are
     * redacted by name, then every secret value (the key, each custom header
     * value) is scrubbed from everything logged, response included, in case
     * anything echoes one back.
     *
     * @param array<string, string>     $params
     * @param array<string, string>     $headers
     * @param list<string>              $customNames
     * @param array<string, mixed>|null $body
     */
    private function logExchange(string $method, string $path, array $params, array $headers, array $customNames, ?array $body, bool $verifyTls, HttpResponse $response): void
    {
        $redact = array_merge(self::SECRET_HEADERS, array_map('strtolower', $customNames));
        $logged = [];
        $secrets = [];
        foreach ($headers as $name => $value) {
            $isSecret = in_array(strtolower($name), $redact, true);
            $logged[$name] = $isSecret ? self::REDACTED : $value;
            if ($isSecret) {
                $secrets[] = $value;
            }
        }
        $secrets[] = $this->settings->apiKey();
        $secrets = array_values(array_filter($secrets, static fn (string $secret): bool => strlen($secret) >= self::MIN_SCRUB_LENGTH));
        $line = 'Two API ' . $method . ' ' . $path;
        $context = [
            'request' => ['params' => $params, 'headers' => $logged, 'body' => $body, 'verify_tls' => $verifyTls],
            'response' => ['status' => $response->status, 'body' => $response->json() ?? $response->body, 'transport_error' => $response->transportError],
        ];

        $this->logger->debug($line, self::scrub($context, $secrets));

        if (!$response->reachedTwo()) {
            $this->logger->error(self::scrub([$line . ': no response (' . $response->transportError . ')'], $secrets)[0]);
        } elseif ($response->status >= 400) {
            $this->logger->error($line . ': HTTP ' . $response->status);
        }
    }

    /**
     * Every string in $data with each secret (at least MIN_SCRUB_LENGTH long)
     * replaced by the redaction marker.
     *
     * @template T of array
     * @param T            $data
     * @param list<string> $secrets
     * @return T
     */
    private static function scrub(array $data, array $secrets): array
    {
        if ($secrets === []) {
            return $data;
        }
        array_walk_recursive($data, static function (mixed &$value) use ($secrets): void {
            if (is_string($value)) {
                $value = str_replace($secrets, self::REDACTED, $value);
            }
        });

        return $data;
    }
}
