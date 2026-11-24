<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The decline return's rules for the adapter (TWO-27391): what to do with
 * OrderFlow::declined's answer, and where the decline message goes on the
 * checkout page, which, unlike the cart, shows no notice of its own.
 *
 * A buyer whose basket is in this browser lands on the checkout with the
 * message, to choose another payment method for the same basket. A buyer
 * on another device or browser gets the same housekeeping and the message
 * on the cart, with nothing restored.
 */
final class DeclineReturn
{
    /**
     * @param string $as      OrderFlow::declined's `as`: the route the outcome is settled as
     * @param bool   $placed  whether the shop order is placed now (read again after the flow ran)
     * @param bool   $basket  whether the order's basket is in this browser and OpenCart's checkout will take it
     * @return array{place: bool, note: bool, goto: 'success'|'cart'|'checkout', notice: 'declined'|'unanswered'|'pending'|'cancelled'|'changed'|'generic'|null}
     */
    public static function after(string $as, string $outcome, bool $placed, bool $basket): array
    {
        if ($as === OrderFlow::CALLBACK_CONFIRM) {
            return OrderFlow::afterCallback(OrderFlow::CALLBACK_CONFIRM, $outcome, $placed);
        }
        if ($placed || !in_array($outcome, [OrderFlow::CANCELLED, OrderFlow::REJECTED, OrderFlow::CLOSED, OrderFlow::REFUSED, OrderFlow::WAITING], true)) {
            return ['place' => false, 'note' => false, 'goto' => 'cart', 'notice' => 'generic'];
        }
        if ($outcome === OrderFlow::REFUSED || $outcome === OrderFlow::WAITING) {
            // Two's answer is not known: claim no decline, and no payment in progress either.
            return ['place' => false, 'note' => $outcome === OrderFlow::REFUSED, 'goto' => 'cart', 'notice' => 'unanswered'];
        }

        return ['place' => false, 'note' => $outcome !== OrderFlow::CLOSED, 'goto' => $basket ? 'checkout' : 'cart', 'notice' => 'declined'];
    }

    /**
     * Puts the alert at the top of OpenCart's content column (`id="content"`)
     * on the checkout page, else in the header's alert container
     * (`id="alert"`). Null when the page has neither: a theme that replaced
     * both is left alone rather than guessed at.
     */
    public static function placeNotice(string $page, string $alert): ?string
    {
        foreach (['content', 'alert'] as $id) {
            if (preg_match('/<div\b[^>]*\bid\s*=\s*["\']' . $id . '["\'][^>]*>/i', $page, $match, PREG_OFFSET_CAPTURE) === 1) {
                $at = $match[0][1] + strlen($match[0][0]);

                return substr($page, 0, $at) . $alert . substr($page, $at);
            }
        }

        return null;
    }

    /** OpenCart's own dismissible alert, as its cart shows a notice, around the escaped text, and the decline landing's marker. */
    public static function alert(string $text): string
    {
        return '<div class="alert alert-danger alert-dismissible"><i class="fa-solid fa-circle-exclamation"></i> '
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
            // Outside the alert, which OpenCart removes on the next form post: the checkout script keeps Two unchosen on this page view.
            . '<span id="two-declined" hidden></span>';
    }
}
