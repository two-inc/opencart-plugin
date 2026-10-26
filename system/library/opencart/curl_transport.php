<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\HttpResponse;
use Opencart\System\Library\Extension\Two\Core\Transport;

/**
 * The core's Transport port over cURL. TLS verification is on (peer and host)
 * unless the Client passes the merchant's bypass. A call with no HTTP answer
 * returns HttpResponse::unreachable() with cURL's reason.
 */
final class CurlTransport implements Transport
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public function send(string $method, string $url, array $headers, ?string $body, bool $verifyTls, int $timeoutSeconds): HttpResponse
    {
        return $this->sendAll([['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body]], $verifyTls, $timeoutSeconds)[0];
    }

    /** Every call on one cURL multi handle, so they run at once. */
    public function sendAll(array $requests, bool $verifyTls, int $timeoutSeconds): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $received = [];
        foreach ($requests as $index => $request) {
            $lines = [];
            foreach ($request['headers'] as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $received[$index] = [];
            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $request['method'],
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received, $index): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $received[$index][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($line);
                },
            ]);
            if ($request['body'] !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
            }
            curl_multi_add_handle($multi, $ch);
            $handles[$index] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $index => $ch) {
            $response = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            $responses[] = !is_string($response) || $error !== '' || $code === 0 ? HttpResponse::unreachable($error) : new HttpResponse($code, $response, $received[$index]);
        }
        curl_multi_close($multi);

        return $responses;
    }
}
