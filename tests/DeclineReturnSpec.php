<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Core\DeclineReturn;
use Opencart\System\Library\Extension\Two\Core\OrderFlow;

/**
 * What the decline return does with OrderFlow::declined's answer (TWO-27391):
 * whether the order is placed or noted, where the buyer lands and with which
 * notice, for a buyer whose basket is in this browser and for one on another
 * device or browser. And where the notice goes on OpenCart's checkout page.
 */
final class DeclineReturnSpec
{
    public static function runAll(): void
    {
        self::testAfter();
        self::testPlaceNotice();
        self::testAlert();
    }

    private static function testAfter(): void
    {
        $conf = OrderFlow::CALLBACK_CONFIRM;
        $canc = OrderFlow::CALLBACK_CANCEL;
        $cases = [
            // [settled as, outcome, shop order placed, basket in this browser, expected, description]
            [$conf, OrderFlow::CONFIRMED, false, true, 'place|success|', 'Two holds it VERIFIED or later: placed, the success page'],
            [$conf, OrderFlow::CONFIRMED, false, false, 'place|success|', 'VERIFIED from another browser: placed all the same'],
            [$conf, OrderFlow::CHANGED, false, true, 'note|cart|changed', 'VERIFIED but the cart changed: as the confirmation return'],
            [$conf, OrderFlow::REFUSED, false, true, 'note|cart|generic', 'VERIFIED but the confirm failed: as the confirmation return'],
            [$conf, OrderFlow::CONFIRMED, true, true, 'none|success|', 'VERIFIED, placed meanwhile by another hit: nothing written'],
            [$canc, OrderFlow::CANCELLED, false, true, 'note|checkout|declined', 'closed, same session: the payment step with the decline message'],
            [$canc, OrderFlow::REJECTED, false, true, 'note|checkout|declined', 'declined at Two, same session: the payment step'],
            [$canc, OrderFlow::CANCELLED, false, false, 'note|cart|declined', 'closed, no session: housekeeping and the message, nothing restored'],
            [$canc, OrderFlow::REJECTED, false, false, 'note|cart|declined', 'declined at Two, no session: the message on the cart'],
            [$canc, OrderFlow::CLOSED, false, true, 'none|checkout|declined', 'a second click, same session: no second note'],
            [$canc, OrderFlow::CLOSED, false, false, 'none|cart|declined', 'a second click, no session: no second note'],
            [$canc, OrderFlow::REFUSED, false, true, 'note|cart|unanswered', 'Two refused the cancel: noted, no decline claimed'],
            [$canc, OrderFlow::WAITING, false, true, 'none|cart|unanswered', 'Two could not be read: nothing changed, no decline claimed'],
            [$canc, OrderFlow::UNAUTHORISED, false, true, 'none|cart|generic', 'a stale or foreign token: nothing changed'],
            [$canc, OrderFlow::CANCELLED, true, true, 'none|cart|generic', 'placed meanwhile: nothing written over it'],
        ];
        foreach ($cases as [$as, $outcome, $placed, $basket, $expected, $description]) {
            $after = DeclineReturn::after($as, $outcome, $placed, $basket);
            $action = $after['place'] ? 'place' : ($after['note'] ? 'note' : 'none');
            TinyAssert::same($expected, $action . '|' . $after['goto'] . '|' . ($after['notice'] ?? ''), 'decline after: ' . $description);
        }
    }

    private static function testPlaceNotice(): void
    {
        $alert = '<div class="alert">x</div>';
        $cases = [
            ['<div id="alert"></div><div class="row"><div id="content" class="col"><h1>Checkout</h1>', '<div id="alert"></div><div class="row"><div id="content" class="col">' . $alert . '<h1>Checkout</h1>', 'OpenCart\'s content column: at its top'],
            ["<div id='content'><h1>Checkout</h1>", "<div id='content'>" . $alert . '<h1>Checkout</h1>', 'single quotes'],
            ['<header><div id="alert" class="toast"></div></header><main>x</main>', '<header><div id="alert" class="toast">' . $alert . '</div></header><main>x</main>', 'no content column: OpenCart\'s alert container'],
            ['<div id="content-top">x</div><div id="alert"></div>', '<div id="content-top">x</div><div id="alert">' . $alert . '</div>', 'an id that only starts with content is not the column'],
            ['<main>x</main>', null, 'neither: left alone'],
        ];
        foreach ($cases as [$page, $expected, $description]) {
            TinyAssert::same($expected, DeclineReturn::placeNotice($page, $alert), 'place notice: ' . $description);
        }
    }

    private static function testAlert(): void
    {
        $cases = [
            ['Two could not approve this purchase.', 'Two could not approve this purchase.', 'plain text'],
            ['A <b>&"\'', 'A &lt;b&gt;&amp;&quot;&#039;', 'escaped'],
        ];
        foreach ($cases as [$text, $inner, $description]) {
            $html = DeclineReturn::alert($text);
            TinyAssert::true(str_contains($html, ' ' . $inner . ' '), 'alert: ' . $description);
            TinyAssert::true(str_starts_with($html, '<div class="alert alert-danger alert-dismissible">'), 'alert: OpenCart\'s own alert markup: ' . $description);
            TinyAssert::true(str_ends_with($html, '</div><span id="two-declined" hidden></span>'), 'alert: the landing marker, outside the alert: ' . $description);
        }
    }
}
