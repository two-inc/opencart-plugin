<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\TelephoneSetting;

/**
 * The Diagnostics buyer telephone row (TWO-27369): ok only when checkout
 * shows the telephone field and requires it; otherwise a warning naming the
 * product that needs the phone and the two settings to turn on. In every
 * admin language.
 */
final class TelephoneSettingSpec
{
    private const LOCALES = ['en-gb', 'nb-no', 'sv-se', 'nl-nl', 'es-es'];

    public static function runAll(): void
    {
        $cases = [
            [true, true, TelephoneSetting::STATE_OK, 'shown and required: ok'],
            [true, false, TelephoneSetting::STATE_WARNING, 'shown but optional: a buyer can leave it empty, so a warning'],
            [false, true, TelephoneSetting::STATE_WARNING, 'required but hidden: OpenCart asks for nothing, so a warning'],
            [false, false, TelephoneSetting::STATE_WARNING, 'neither: a warning'],
        ];
        $root = dirname(__DIR__);
        foreach (self::LOCALES as $locale) {
            $copy = self::copy($root . '/admin/language/' . $locale . '/payment/two.php');
            $text = static fn (string $key): string => $copy[$key] ?? throw new RuntimeException($locale . ': no ' . $key);
            foreach ($cases as [$display, $required, $state, $description]) {
                $label = $locale . ', ' . $description;
                $row = TelephoneSetting::row($display, $required, 'Two', $text);
                $expected = $state === TelephoneSetting::STATE_OK ? $copy['text_telephone_required'] : sprintf($copy['text_telephone_missing'], 'Two');
                TinyAssert::same([$state, $expected], [$row['state'], $row['text']], $label);
            }
        }
    }

    /** @return array<string, string> */
    private static function copy(string $file): array
    {
        $_ = [];
        require $file;

        return $_;
    }
}
