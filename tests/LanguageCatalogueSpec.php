<?php

declare(strict_types=1);

/**
 * Build Guide section 10: the catalogue completeness gate. Every language
 * file en-gb has exists in every shipped locale with exactly en-gb's keys,
 * each value filled in, with the same placeholders as en-gb's (positional
 * ones in any order, the rest in en-gb's order), and no em-dash.
 */
final class LanguageCatalogueSpec
{
    /** The locales shipped besides en-gb, the reference catalogue. */
    private const LOCALES = [
        ['code' => 'nb-no', 'description' => 'Norwegian (Bokmål)'],
        ['code' => 'sv-se', 'description' => 'Swedish'],
        ['code' => 'nl-nl', 'description' => 'Dutch'],
        ['code' => 'es-es', 'description' => 'Spanish'],
    ];

    /** printf conversions (%s, %d, %1$s, %%) and {name} tokens. */
    private const PLACEHOLDER = '/%(?:\d+\$)?[sdf]|%%|\{[a-z_]+\}/';

    public static function runAll(): void
    {
        $root = dirname(__DIR__);
        $reference = [];
        foreach (['admin', 'catalog'] as $side) {
            foreach (glob($root . '/' . $side . '/language/en-gb/*/*.php') ?: [] as $file) {
                $reference[substr($file, strlen($root . '/'))] = self::load($file);
            }
        }
        TinyAssert::true(count($reference) === 3, 'en-gb has the admin payment, admin total and catalog payment files');

        $shipped = array_merge(['en-gb'], array_column(self::LOCALES, 'code'));
        foreach (['admin', 'catalog'] as $side) {
            foreach (glob($root . '/' . $side . '/language/*', GLOB_ONLYDIR) ?: [] as $dir) {
                TinyAssert::true(in_array(basename($dir), $shipped, true), $side . ' locale ' . basename($dir) . ' is in the gate\'s list');
            }
        }

        foreach (self::LOCALES as $locale) {
            foreach ($reference as $path => $english) {
                $relative = str_replace('/en-gb/', '/' . $locale['code'] . '/', $path);
                $case = $locale['description'] . ' ' . $relative;
                $file = $root . '/' . $relative;
                TinyAssert::true(is_file($file), $case . ': the file exists');
                $translated = self::load($file);
                TinyAssert::same([], array_values(array_diff(array_keys($english), array_keys($translated))), $case . ': no key missing');
                TinyAssert::same([], array_values(array_diff(array_keys($translated), array_keys($english))), $case . ': no extra key');
                foreach ($english as $key => $value) {
                    self::checkValue($value, (string) ($translated[$key] ?? ''), $case . ' ' . $key);
                }
            }
        }

        foreach ($reference as $path => $english) {
            foreach ($english as $key => $value) {
                TinyAssert::true(!str_contains($value, "\u{2014}"), 'en-gb ' . $path . ' ' . $key . ': no em-dash');
            }
        }
    }

    private static function checkValue(string $english, string $translated, string $case): void
    {
        TinyAssert::true(trim($translated) !== '' || trim($english) === '', $case . ': filled in');
        TinyAssert::true(!str_contains($translated, "\u{2014}"), $case . ': no em-dash');
        $expected = self::placeholders($english);
        $actual = self::placeholders($translated);
        $ordered = static fn (array $list): array => array_values(array_filter($list, static fn (string $p): bool => !str_contains($p, '$')));
        TinyAssert::same($ordered($expected), $ordered($actual), $case . ': unnumbered placeholders in en-gb\'s order');
        sort($expected);
        sort($actual);
        TinyAssert::same($expected, $actual, $case . ': the same placeholders');
    }

    /** @return list<string> */
    private static function placeholders(string $value): array
    {
        preg_match_all(self::PLACEHOLDER, $value, $matches);

        return $matches[0];
    }

    /** @return array<string, string> */
    private static function load(string $file): array
    {
        $_ = [];
        require $file;

        return $_;
    }
}
