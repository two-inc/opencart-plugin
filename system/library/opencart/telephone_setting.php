<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

/**
 * The settings page's buyer telephone row (TWO-27369). Two refuses an order
 * with no phone number, and OpenCart's checkout asks for one only when the
 * shop shows the field and requires it (Telephone Display and Telephone
 * Required under System > Settings > Option). With either off, a buyer can
 * reach the Two tile with no phone, and the tile asks them to choose another
 * payment method, so Diagnostics warns. Nothing is blocked.
 */
final class TelephoneSetting
{
    public const STATE_OK = 'ok';
    public const STATE_WARNING = 'warning';

    /**
     * The Diagnostics row: `ok` when checkout requires a phone number, else
     * `warning`, naming the product that needs one and the settings to turn on.
     *
     * @param callable(string): string $text the admin language's copy for a key
     * @return array{state: string, text: string}
     */
    public static function row(bool $display, bool $required, string $productName, callable $text): array
    {
        if ($display && $required) {
            return ['state' => self::STATE_OK, 'text' => $text('text_telephone_required')];
        }

        return ['state' => self::STATE_WARNING, 'text' => sprintf($text('text_telephone_missing'), $productName)];
    }
}
