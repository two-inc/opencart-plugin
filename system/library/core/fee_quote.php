<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The buyer's share of Two's fee for one term (Build Guide section 5):
 * POST /v1/pricing/order/fee with the merchant's surcharge grid row. Two
 * computes the fee; the answer's `buyer_fee_share` is the fee line's net.
 *
 * The quote is a calculation with no side effect, so its idempotency key is
 * the payload's hash alone. A null quote is a failure: the charge paths
 * withhold Two (the availability gate) or refuse the order rather than
 * charge nothing. Answers are kept for the request, keyed by the payload.
 */
final class FeeQuote
{
    public const PATH = '/v1/pricing/order/fee';
    /** One ceiling for the gate and the charge, so the gate never gives up on a quote the charge could price. */
    public const TIMEOUT_SECONDS = 30;

    /** The longest the chips' preview of every term may take, all terms together. */
    public const PREVIEW_SECONDS = 10;

    /** @var array<string, array{net: float, currency: string}|null> answers this request, by payload hash */
    private array $answers = [];

    /** @var array<string, array{net: float, currency: string}> quotes Two gave in this request, by payload hash, for the adapter to keep */
    private array $fresh = [];

    /** Why the last quote failed, for the log and the health row. */
    public string $failure = '';

    public function __construct(
        private readonly Client $client,
        private readonly FxRates $fx,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Quotes the adapter kept from earlier requests (the same payload has the
     * same answer), by payload hash.
     *
     * @param array<string, array{net: float, currency: string}> $known
     */
    public function remember(array $known): void
    {
        $this->answers = $known + $this->answers;
    }

    /**
     * The quotes Two gave in this request, by payload hash.
     *
     * @return array<string, array{net: float, currency: string}>
     */
    public function fresh(): array
    {
        return $this->fresh;
    }

    /**
     * The fee's net in $currency, or null when it cannot be quoted. $gross
     * is the order's gross without the fee, in $currency. Fixed fees and
     * caps are in $shopCurrency and converted at Two's rates; no rate fails
     * closed rather than send a figure in the wrong currency.
     *
     * @return array{net: float, currency: string}|null
     */
    public function quote(Surcharge $surcharge, PaymentTerms $terms, int $days, float $gross, string $buyerCountry, string $currency, string $shopCurrency): ?array
    {
        return $this->quoteAll($surcharge, $terms, [$days], $gross, $buyerCountry, $currency, $shopCurrency, self::TIMEOUT_SECONDS)[$days];
    }

    /**
     * Quotes for several terms at once: what this request or the adapter
     * already holds is reused, the rest are asked of Two in parallel, all
     * within $timeoutSeconds. A term that cannot be quoted maps to null.
     *
     * @param list<int> $days
     * @return array<int, array{net: float, currency: string}|null>
     */
    public function quoteAll(Surcharge $surcharge, PaymentTerms $terms, array $days, float $gross, string $buyerCountry, string $currency, string $shopCurrency, int $timeoutSeconds): array
    {
        $this->failure = '';
        $quotes = [];
        $ask = [];
        foreach ($days as $term) {
            $payload = $this->payload($surcharge, $terms, $term, $gross, $buyerCountry, $currency, $shopCurrency);
            if ($payload === null) {
                $quotes[$term] = null;
                continue;
            }
            $hash = hash('sha256', (string) json_encode($payload));
            if (array_key_exists($hash, $this->answers)) {
                $this->failure = $this->answers[$hash] === null ? 'the fee quote for the ' . $term . '-day term failed earlier in this request' : '';
                $quotes[$term] = $this->answers[$hash];
                continue;
            }
            $ask[$term] = ['hash' => $hash, 'payload' => $payload];
        }
        $calls = array_map(static fn (array $one): array => ['method' => 'POST', 'path' => self::PATH, 'body' => $one['payload'], 'headers' => IdempotencyKey::header(IdempotencyKey::feeQuote($one['hash']))], array_values($ask));
        $responses = $this->client->requestAll($calls, $timeoutSeconds);
        foreach (array_keys($ask) as $index => $term) {
            $quotes[$term] = $this->answer($term, $ask[$term]['hash'], $ask[$term]['payload']['currency'], $responses[$index]);
        }

        return $quotes;
    }

    /** @return array{net: float, currency: string}|null */
    private function answer(int $days, string $hash, string $currency, HttpResponse $response): ?array
    {
        $body = $response->json();
        $share = $body['buyer_fee_share'] ?? null;
        $answered = is_scalar($body['currency'] ?? null) ? strtoupper((string) $body['currency']) : $currency;
        $failure = '';
        if ($response->status < 200 || $response->status >= 300 || !is_numeric($share)) {
            $failure = 'the fee quote for the ' . $days . '-day term failed (HTTP ' . $response->status . ')';
        } elseif ($answered !== $currency) {
            // Two prices in the currency asked for; another is a contract breach, never converted again.
            $failure = 'the fee quote for the ' . $days . '-day term came back in ' . $answered . ', not ' . $currency;
        }
        if ($failure !== '') {
            $this->failure = $failure;
            $this->logger->error('Two ' . $failure . '.');

            return $this->answers[$hash] = null;
        }

        return $this->answers[$hash] = $this->fresh[$hash] = ['net' => round(max(0.0, (float) $share), 2), 'currency' => $currency];
    }

    /**
     * The fee line's value in the shop's default currency, as OpenCart keeps
     * every total: only while Two is the chosen method ($paymentCode) and a
     * fee applies and was quoted above zero. The quote is in the session
     * currency, whose value against the default is $currencyValue.
     *
     * @param array{applies: bool, days: int|null, net: float|null} $charged
     */
    public static function lineValue(array $charged, string $paymentCode, float $currencyValue): ?float
    {
        if ($paymentCode !== 'two.two' || !$charged['applies'] || $charged['days'] === null || $charged['net'] === null || $charged['net'] <= 0 || $currencyValue <= 0) {
            return null;
        }

        return $charged['net'] / $currencyValue;
    }

    /**
     * Placement's term and fee check (Build Guide sections 4 and 5): why the
     * order may not be placed on its term, or null. Refused when no term is
     * offered, when the chosen term is not offered, when the fee cannot be
     * quoted, and when the order's stored fee (in cents of the order's
     * currency) is not the fee quoted for the term.
     *
     * @param array{applies: bool, days: int|null, net: float|null} $charged
     * @return array{reason: string, detail: string}|null
     */
    public static function placementRefusal(PaymentTerms $terms, ?int $chosen, array $charged, string $failure, int $storedCents): ?array
    {
        if ($terms->offered() === []) {
            return ['reason' => 'terms', 'detail' => 'the merchant record offers no payment term'];
        }
        if ($chosen !== null && !$terms->offers($chosen)) {
            return ['reason' => 'terms', 'detail' => 'the chosen ' . $chosen . '-day term is not one the merchant record offers'];
        }
        if ($charged['applies'] && $charged['net'] === null) {
            return ['reason' => 'fee', 'detail' => $failure];
        }
        $expected = $charged['applies'] ? (int) round((float) $charged['net'] * 100) : 0;
        if ($storedCents !== $expected) {
            return ['reason' => 'fee_changed', 'detail' => 'the order\'s payment terms fee is not the fee for the ' . (int) $charged['days'] . '-day term'];
        }

        return null;
    }

    /**
     * The fee this cart is charged: for the buyer's term when offered, else
     * the default. `applies` is false when no fee applies (no surcharge, no
     * offered term, a term that prices nothing, an empty cart); `net` is
     * null when one applies but cannot be quoted (the failure says why).
     *
     * @return array{applies: bool, days: int|null, net: float|null}
     */
    public function charged(Surcharge $surcharge, PaymentTerms $terms, ?int $chosen, float $gross, string $buyerCountry, string $currency, string $shopCurrency): array
    {
        $this->failure = '';
        $days = $terms->selected($chosen);
        if (!$surcharge->isKnown()) {
            $this->failure = 'the stored surcharge method "' . $surcharge->type . '" is not one the plugin knows';

            return ['applies' => true, 'days' => $days, 'net' => null];
        }
        if (!$surcharge->enabled() || $days === null || $gross <= 0 || !$surcharge->pricesFee($days, $terms->defaultTerm(), $terms->isEndOfMonth())) {
            return ['applies' => false, 'days' => $days, 'net' => null];
        }
        $quote = $this->quote($surcharge, $terms, $days, $gross, $buyerCountry, $currency, $shopCurrency);

        return ['applies' => true, 'days' => $days, 'net' => $quote['net'] ?? null];
    }

    /**
     * The availability gate's currency half (Build Guide section 18): a fixed
     * fee or cap with no FX rate into the cart currency fails every term
     * alike, so it is checked across them. Null when every term converts.
     * The other half is charged(): a failed quote is judged for the one term
     * the cart would be charged, since another term may still quote.
     */
    public function currencyFailure(Surcharge $surcharge, PaymentTerms $terms, string $currency, string $shopCurrency): ?string
    {
        if (!$surcharge->isKnown() || !$surcharge->enabled() || strtoupper(trim($currency)) === strtoupper(trim($shopCurrency))) {
            return null;
        }
        foreach ($terms->offered() as $days) {
            if ($this->payload($surcharge, $terms, $days, 1.0, '', $currency, $shopCurrency) === null) {
                return $this->failure;
            }
        }

        return null;
    }

    /**
     * The quote request, or null (with the failure set) when the grid row
     * cannot be put in the quote currency.
     *
     * @return array<string, mixed>|null
     */
    public function payload(Surcharge $surcharge, PaymentTerms $terms, int $days, float $gross, string $buyerCountry, string $currency, string $shopCurrency): ?array
    {
        $currency = strtoupper(trim($currency));
        try {
            $share = $surcharge->feeShare($days, $terms->defaultTerm(), $terms->isEndOfMonth());
        } catch (\UnexpectedValueException $unknown) {
            $this->failure = $unknown->getMessage();

            return null;
        }
        if ($share === null) {
            $this->failure = 'no surcharge is set';

            return null;
        }
        foreach (['surcharge', 'cap'] as $member) {
            // A zero is zero in every currency, and needs no rate.
            if (!isset($share[$member]) || (float) $share[$member] === 0.0) {
                continue;
            }
            $converted = $this->fx->convert((float) $share[$member], $shopCurrency, $currency);
            if ($converted === null) {
                $this->failure = 'no FX rate from ' . strtoupper($shopCurrency) . ' to ' . $currency . ' for the ' . $days . '-day term\'s ' . ($member === 'cap' ? 'cap' : 'fixed fee');

                return null;
            }
            $share[$member] = $converted;
        }

        return [
            'currency' => $currency,
            'gross_amount' => number_format(round($gross, 2), 2, '.', ''),
            'buyer_country_code' => strtoupper(trim($buyerCountry)),
            'approved_on_recourse' => false,
            'order_terms' => PaymentTerms::block($days, $terms->isEndOfMonth()),
            'buyer_fee_share' => $share,
        ];
    }
}
