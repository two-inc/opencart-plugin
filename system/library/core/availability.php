<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Whether Two may be offered at checkout (Build Guide section 18): an ordered
 * chain of silent checks. The first that fails names the withhold reason;
 * the buyer sees nothing, the log and the admin's health row say why.
 *
 * Only a definitive rejection of the API key withholds on Two's account: an
 * unreachable or erroring Two falls through to the cached merchant record,
 * which has no expiry, so an incident at Two never empties the checkout.
 * The fee-quote gate joins the chain with the surcharge (P7).
 */
final class Availability
{
    public const DISABLED = 'disabled';
    public const NO_API_KEY = 'no_api_key';
    public const KEY_REJECTED = 'key_rejected';
    public const BUYER_COUNTRIES_EMPTY = 'buyer_countries_empty';
    public const BUYER_COUNTRIES_MALFORMED = 'buyer_countries_malformed';
    public const BUYER_COUNTRY = 'buyer_country';
    public const CURRENCY = 'currency';
    public const NO_COUNTRY = 'no_country';
    public const BELOW_MINIMUM = 'below_minimum';

    /** The currencies Two invoices in. */
    public const SUPPORTED_CURRENCIES = ['NOK', 'GBP', 'SEK', 'USD', 'DKK', 'EUR'];

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly RecordProvider $records,
        private readonly ApiKeyVerdict $verdict,
    ) {
    }

    /**
     * Null when Two may be offered for this cart, otherwise the withhold
     * reason (one of the constants) and its detail for the log.
     *
     * The cart's gross and net are in value_currency when given (a shop that
     * keeps its amounts in its default currency), else in the cart's currency.
     *
     * @param array{enabled: bool, currency: string, shop_currency: string, gross: float, net: float, buyer_country: string|null, value_currency?: string} $checkout
     * @param \Closure(float, string, string): ?float $convert puts an amount from one currency into another, null when it cannot
     * @return array{reason: string, detail: string}|null
     */
    public function withholdReason(array $checkout, \Closure $convert): ?array
    {
        $shop = $this->shopReason($checkout['enabled'], $checkout['shop_currency']);
        if ($shop !== null && $shop !== self::CURRENCY) {
            return ['reason' => $shop, 'detail' => $shop];
        }
        $record = $this->records->record();
        $country = $checkout['buyer_country'] === null || $checkout['buyer_country'] === '' ? null : strtoupper($checkout['buyer_country']);
        $countries = BuyerCountries::fromRecord($record);
        if (!$countries->allows($country)) {
            return ['reason' => self::BUYER_COUNTRY, 'detail' => 'buyer country ' . ($country ?? 'unresolved') . ', supported buyer country list ' . $countries->state];
        }
        $currency = strtoupper(trim($checkout['currency']));
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return ['reason' => self::CURRENCY, 'detail' => 'cart currency ' . ($currency === '' ? 'unresolved' : $currency) . ' is not one Two invoices in'];
        }
        if (!$this->settings->companySearchInAddressArea() && $country === null) {
            return ['reason' => self::NO_COUNTRY, 'detail' => 'no country resolves from the billing or shipping address, so the tile\'s search could not run'];
        }
        $shortfall = MinimumOrder::from($record, $this->settings, $checkout['shop_currency'])->shortfall($checkout['gross'], $checkout['net'], $checkout['value_currency'] ?? $currency, $convert);
        if ($shortfall !== null) {
            return ['reason' => self::BELOW_MINIMUM, 'detail' => $shortfall];
        }

        return null;
    }

    /**
     * The withhold reasons that hold for every cart, in chain order: the
     * health row names the first. CURRENCY here means the shop's default
     * currency is one Two does not invoice in.
     */
    public function shopReason(bool $enabled, string $shopCurrency): ?string
    {
        if (!$enabled) {
            return self::DISABLED;
        }
        if ($this->settings->apiKey() === '') {
            return self::NO_API_KEY;
        }
        // The heartbeat: the cached verdict, checked live once it is due.
        if (ApiKeyVerdict::isDefinitive($this->verdict->status())) {
            return self::KEY_REJECTED;
        }
        $state = BuyerCountries::fromRecord($this->records->record())->state;
        if ($state === BuyerCountries::EMPTY) {
            return self::BUYER_COUNTRIES_EMPTY;
        }
        if ($state === BuyerCountries::MALFORMED) {
            return self::BUYER_COUNTRIES_MALFORMED;
        }
        if (!in_array(strtoupper(trim($shopCurrency)), self::SUPPORTED_CURRENCIES, true)) {
            return self::CURRENCY;
        }

        return null;
    }

    /**
     * The admin's health row: why Two is not shown, or that it is, with the
     * per-cart constraints that may still withhold it.
     *
     * @param list<string> $shopCurrencies the shop's enabled currencies
     * @return array{shown: bool, reason: string|null, record_known: bool, floors: list<array{amount: float, currency: string, basis: string}>, buyer_countries: list<string>, refused_currencies: list<string>}
     */
    public function health(bool $enabled, string $shopCurrency, array $shopCurrencies): array
    {
        $reason = $this->shopReason($enabled, $shopCurrency);
        $record = $reason === null ? $this->records->record() : null;
        $refused = [];
        foreach ($shopCurrencies as $code) {
            $code = strtoupper(trim($code));
            if ($code !== '' && !in_array($code, self::SUPPORTED_CURRENCIES, true) && !in_array($code, $refused, true)) {
                $refused[] = $code;
            }
        }

        return [
            'shown' => $reason === null,
            'reason' => $reason,
            'record_known' => $record !== null,
            'floors' => $reason === null ? MinimumOrder::from($record, $this->settings, $shopCurrency)->bindingFloors() : [],
            'buyer_countries' => $reason === null ? BuyerCountries::fromRecord($record)->allowed : [],
            'refused_currencies' => $reason === null ? $refused : [],
        ];
    }
}
