<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Whether Two may be offered at checkout (Build Guide section 18): an ordered
 * chain of silent checks (API key verdict, merchant record, currency, buyer
 * country, minimum order, fee quote). The first failing check names the
 * withhold reason, which the admin health row shows; the buyer sees nothing.
 *
 * Behaviour lands in P6.
 */
final class Availability
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly RecordProvider $records,
    ) {
    }

    /**
     * Null when Two may be offered, otherwise the withhold reason code.
     *
     * @param array{currency: string, gross: float, net: float, buyer_country: string|null} $checkout
     */
    public function withholdReason(array $checkout): ?string
    {
        throw NotImplemented::inPhase('Availability::withholdReason', 'P6');
    }
}
