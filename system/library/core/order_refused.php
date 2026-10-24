<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * An order request the plugin will not send: it cannot be built from the
 * shop's order, a shop-match check failed, or a postprocessing subscriber
 * broke it (Build Guide section 21.4). The buyer sees the generic refusal
 * (section 6.4); the reason code and detail go to the log and the merchant's
 * order history. The detail never carries a credential.
 */
final class OrderRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct($reason . ': ' . $detail);
    }

    public static function because(string $reason, string $detail): self
    {
        return new self($reason, $detail);
    }
}
