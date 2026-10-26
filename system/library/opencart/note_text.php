<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\Message;

/**
 * Renders a core Message from the notes language file (`payment/two_notes`,
 * the same file for the admin and the storefront) in one language: the
 * shop's for an order history line, the admin user's for an answer on the
 * admin page. Keys are loaded under a prefix, so the page's own copy stays.
 * A language without the file, or without the key, gives the English text.
 */
final class NoteText
{
    public const ROUTE = 'extension/two/payment/two_notes';

    private const PREFIX = 'two_notes';

    public static function render(Message $message, \Opencart\System\Engine\Loader $load, \Opencart\System\Library\Language $language, string $code): string
    {
        // en-gb first, as OpenCart loads it under every language, so no key is left from another language.
        $load->language(self::ROUTE, self::PREFIX, 'en-gb');
        if ($code !== 'en-gb') {
            $load->language(self::ROUTE, self::PREFIX, $code);
        }

        return $message->in(static function (string $key) use ($language): string {
            $text = $language->get(self::PREFIX . '_' . $key);

            return $text === self::PREFIX . '_' . $key ? '' : $text;
        });
    }

    /** A refund row's stored outcome: a Message rendered, or text stored before messages, as it stands. */
    public static function stored(string $stored, \Opencart\System\Engine\Loader $load, \Opencart\System\Library\Language $language, string $code): string
    {
        $message = Message::decode($stored);

        return $message === null ? $stored : self::render($message, $load, $language, $code);
    }
}
