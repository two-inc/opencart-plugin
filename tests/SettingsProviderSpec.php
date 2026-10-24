<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\SettingsProvider;

/**
 * The typed settings view. Each case is what the store holds and what one
 * getter returns, so a missing or malformed value has a pinned reading.
 */
final class SettingsProviderSpec
{
    /** @var list<array{stored: array<string, string>, getter: string, expected: mixed, description: string}> */
    private const CASES = [
        ['stored' => [], 'getter' => 'apiKey', 'expected' => '', 'description' => 'no key stored reads as empty'],
        ['stored' => ['api_key' => ' secret '], 'getter' => 'apiKey', 'expected' => 'secret', 'description' => 'the key is trimmed'],
        ['stored' => [], 'getter' => 'environment', 'expected' => null, 'description' => 'no environment stored reads as null (fresh install)'],
        ['stored' => ['environment' => ' Staging '], 'getter' => 'environment', 'expected' => ' Staging ', 'description' => 'the environment is returned unresolved'],
        ['stored' => [], 'getter' => 'verifyTls', 'expected' => true, 'description' => 'TLS verification is on when nothing is stored'],
        ['stored' => ['disable_ssl_verify' => '0'], 'getter' => 'verifyTls', 'expected' => true, 'description' => 'TLS verification is on by default'],
        ['stored' => ['disable_ssl_verify' => '1'], 'getter' => 'verifyTls', 'expected' => false, 'description' => 'the bypass switches it off'],
        ['stored' => ['disable_ssl_verify' => 'yes'], 'getter' => 'verifyTls', 'expected' => true, 'description' => 'only exactly 1 switches it off'],
        ['stored' => [], 'getter' => 'debugLogging', 'expected' => false, 'description' => 'debug logging is off when nothing is stored'],
        ['stored' => ['debug_mode' => '1'], 'getter' => 'debugLogging', 'expected' => true, 'description' => 'debug logging on'],
        ['stored' => [], 'getter' => 'customHeaderRows', 'expected' => [], 'description' => 'no header rows stored'],
        ['stored' => ['custom_headers' => 'not json'], 'getter' => 'customHeaderRows', 'expected' => [], 'description' => 'malformed JSON reads as no rows'],
        ['stored' => ['custom_headers' => '{"name":"X"}'], 'getter' => 'customHeaderRows', 'expected' => [], 'description' => 'an object, not a list of rows, reads as no rows'],
        ['stored' => ['custom_headers' => '[{"name":"X-A","value":"1"},{"name":"X-B"},"junk",{"name":["x"],"value":"v"}]'], 'getter' => 'customHeaderRows', 'expected' => [['name' => 'X-A', 'value' => '1']], 'description' => 'rows without a scalar name and value are skipped'],
        ['stored' => ['custom_headers' => '[{"name":"X-N","value":42}]'], 'getter' => 'customHeaderRows', 'expected' => [['name' => 'X-N', 'value' => '42']], 'description' => 'a numeric value reads as a string'],
    ];

    /** @var list<array{a: array<string, string>, b: array<string, string>, same: bool, description: string}> */
    private const FINGERPRINTS = [
        ['a' => ['api_key' => 'k'], 'b' => ['api_key' => 'k', 'environment' => 'sandbox'], 'same' => true, 'description' => 'no environment and sandbox are the same credentials'],
        ['a' => ['api_key' => 'k', 'environment' => 'prod'], 'b' => ['api_key' => 'k', 'environment' => ' Production '], 'same' => true, 'description' => 'environment spellings that resolve alike match'],
        ['a' => ['api_key' => 'k', 'environment' => 'sandbox'], 'b' => ['api_key' => 'k', 'environment' => 'production'], 'same' => false, 'description' => 'another environment is other credentials'],
        ['a' => ['api_key' => 'k1'], 'b' => ['api_key' => 'k2'], 'same' => false, 'description' => 'another key is other credentials'],
    ];

    public static function runAll(): void
    {
        foreach (self::CASES as $case) {
            $settings = new SettingsProvider(new MemoryStore($case['stored']));
            TinyAssert::same($case['expected'], $settings->{$case['getter']}(), $case['description']);
        }

        foreach (self::FINGERPRINTS as $case) {
            $a = (new SettingsProvider(new MemoryStore($case['a'])))->credentialFingerprint();
            $b = (new SettingsProvider(new MemoryStore($case['b'])))->credentialFingerprint();
            TinyAssert::same($case['same'], $a === $b, 'fingerprint: ' . $case['description']);
        }
    }
}
