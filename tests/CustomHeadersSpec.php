<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\CustomHeaders;

/**
 * The read path of the merchant's custom headers: a stored row is re-checked
 * every time it is read, so a row edited in the database never reaches the
 * wire. Each case is a set of stored rows and the headers that may be sent.
 */
final class CustomHeadersSpec
{
    /** @var list<array{rows: list<array{name: string, value: string}>, expected: array<string, string>, description: string}> */
    private const CASES = [
        ['rows' => [], 'expected' => [], 'description' => 'no rows, no headers'],
        ['rows' => [['name' => 'X-Firewall-Token', 'value' => 'abc123']], 'expected' => ['X-Firewall-Token' => 'abc123'], 'description' => 'a valid row is sent'],
        ['rows' => [['name' => ' X-Trim ', 'value' => ' v ']], 'expected' => ['X-Trim' => 'v'], 'description' => 'name and value are trimmed'],
        ['rows' => [['name' => 'X-Empty', 'value' => '']], 'expected' => [], 'description' => 'an empty value is dropped'],
        ['rows' => [['name' => 'X-Empty', 'value' => '   ']], 'expected' => [], 'description' => 'a blank value is dropped'],
        ['rows' => [['name' => '', 'value' => 'v']], 'expected' => [], 'description' => 'an empty name is dropped'],
        ['rows' => [['name' => 'Bad Name', 'value' => 'v']], 'expected' => [], 'description' => 'a name with a space is dropped'],
        ['rows' => [['name' => 'X-Colon:', 'value' => 'v']], 'expected' => [], 'description' => 'a name with a colon is dropped'],
        ['rows' => [['name' => "X-Split\n", 'value' => 'v']], 'expected' => ['X-Split' => 'v'], 'description' => 'a trailing newline is trimmed, not smuggled'],
        ['rows' => [['name' => "X-A\nX-B", 'value' => 'v']], 'expected' => [], 'description' => 'a newline inside the name is dropped'],
        ['rows' => [['name' => 'X-Inject', 'value' => "a\r\nX-API-Key: stolen"]], 'expected' => [], 'description' => 'CR LF in the value is dropped'],
        ['rows' => [['name' => 'X-Nul', 'value' => "a\0b"]], 'expected' => [], 'description' => 'a control byte in the value is dropped'],
        ['rows' => [['name' => 'X-Utf8', 'value' => 'caf' . "\u{e9}"]], 'expected' => [], 'description' => 'a non-ASCII value is dropped'],
        ['rows' => [['name' => 'X-API-Key', 'value' => 'override']], 'expected' => [], 'description' => 'the API key header is reserved'],
        ['rows' => [['name' => 'x-api-key', 'value' => 'override']], 'expected' => [], 'description' => 'reserved names match case-insensitively'],
        ['rows' => [['name' => 'Authorization', 'value' => 'Bearer x']], 'expected' => [], 'description' => 'authorization is reserved'],
        ['rows' => [['name' => 'Content-Type', 'value' => 'text/plain']], 'expected' => [], 'description' => 'content-type is reserved'],
        ['rows' => [['name' => 'Host', 'value' => 'evil.example']], 'expected' => [], 'description' => 'host is reserved'],
        ['rows' => [['name' => 'Transfer-Encoding', 'value' => 'chunked']], 'expected' => [], 'description' => 'hop-by-hop headers are reserved'],
        ['rows' => [['name' => 'X-Dup', 'value' => 'first'], ['name' => 'x-dup', 'value' => 'second']], 'expected' => ['X-Dup' => 'first'], 'description' => 'the first of two rows with one name wins'],
        ['rows' => [['name' => 'X-Bad', 'value' => "\n"], ['name' => 'X-Good', 'value' => 'ok']], 'expected' => ['X-Good' => 'ok'], 'description' => 'a bad row does not stop the good ones'],
    ];

    public static function runAll(): void
    {
        $headers = new CustomHeaders();
        foreach (self::CASES as $case) {
            TinyAssert::same($case['expected'], $headers->forRequest($case['rows']), $case['description']);
        }
    }
}
