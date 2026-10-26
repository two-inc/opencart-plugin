<?php

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Two\Total;

/**
 * The payment terms fee as an OpenCart order total (Build Guide section 5),
 * the way core's handling total adds a taxed charge: the net is the buyer's
 * share Two quotes for the chosen term, and its tax is what the shop's own
 * tax engine gives the tax class the merchant selected for the fee. The
 * plugin never picks a rate.
 *
 * Added only while Two is the chosen payment method. Its sort order must
 * come before the Taxes total, which lists the taxes added so far.
 *
 * @property-read \Opencart\System\Engine\Loader $load
 * @property-read \Opencart\System\Engine\Config $config
 * @property-read \Opencart\System\Library\Cart\Tax $tax
 * @property-read \Opencart\Catalog\Model\Extension\Two\Payment\Two $model_extension_two_payment_two
 */
class TwoFee extends \Opencart\System\Engine\Model
{
    /**
     * @param array<int, array<string, mixed>> $totals
     * @param array<int, float>                $taxes
     */
    public function getTotal(array &$totals, array &$taxes, float &$total): void
    {
        $this->load->model('extension/two/payment/two');
        if (\Opencart\Catalog\Model\Extension\Two\Payment\Two::feeSuspended()) {
            return;
        }
        $fee = $this->model_extension_two_payment_two->feeTotal();
        if ($fee === null) {
            return;
        }
        $totals[] = [
            'extension' => 'two',
            'code' => 'two_fee',
            'title' => $fee['title'],
            'value' => $fee['value'],
            'sort_order' => (int) $this->config->get('total_two_fee_sort_order'),
        ];
        if ($fee['tax_class_id'] > 0) {
            foreach ($this->tax->getRates($fee['value'], $fee['tax_class_id']) as $rate) {
                $id = (int) $rate['tax_rate_id'];
                $taxes[$id] = ($taxes[$id] ?? 0.0) + (float) $rate['amount'];
            }
        }
        $total += $fee['value'];
    }
}
