<?php

// Strings with %s take the brand's product name.

// Heading
$_['heading_title'] = 'Two';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Settings saved.';
$_['text_success_partial'] = 'The other settings were saved.';
$_['text_edit'] = 'Edit Two settings';
$_['text_version'] = 'Version';
$_['text_coming_later'] = 'These settings are coming in a later release.';
$_['text_environment_production'] = 'Production';
$_['text_environment_sandbox'] = 'Sandbox';
$_['text_environment_staging'] = 'Staging';
$_['text_key_saved'] = 'A key is saved. Leave this blank to keep it.';
$_['text_key_verified'] = 'API key verified successfully';
$_['text_key_verifying'] = 'The API key is being verified. Reload the page in a moment.';
$_['text_key_checking'] = 'Checking the API key...';
$_['text_key_kept'] = 'The previously saved key and environment were kept.';
$_['text_merchant_id'] = 'Merchant ID';
$_['text_merchant_short_name'] = 'Merchant short name';
$_['text_record_fetched_at'] = 'Merchant profile fetched';
$_['text_status_mapping_intro'] = 'Map %s payment states to OpenCart order statuses for workflow integration.';
$_['text_default_mappings'] = 'Default mappings:';
$_['text_default_fulfil'] = '%s: Order Fulfilled → Shipped';
$_['text_default_cancel'] = '%s: Order Cancelled → Canceled';
$_['text_default_refund'] = '%s: Order Refunded → Refunded';
$_['text_currently_active'] = 'Currently active:';
$_['text_none_selected'] = 'None selected';

// Tab
$_['tab_general'] = 'General';
$_['tab_checkout_fields'] = 'Checkout fields';
$_['tab_payment_terms'] = 'Payment terms';
$_['tab_order_management'] = 'Order management';
$_['tab_diagnostics'] = 'Diagnostics';

// Legend
$_['legend_status_mapping'] = 'Status mapping';

// Entry
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort order';
$_['entry_environment'] = 'Environment';
$_['entry_api_key'] = 'API key';
$_['entry_title'] = 'Title';
$_['entry_fulfil'] = '%s: Order Fulfilled - Trigger Statuses';
$_['entry_cancel'] = '%s: Order Cancelled';
$_['entry_refund'] = '%s: Order Refunded';

// Help
$_['help_environment'] = 'Select the %s API environment to use. Production for live transactions, Sandbox for testing.';
$_['help_api_key'] = 'Enter your API key which is provided by %s.';
$_['help_title'] = 'Enter a title which will appear on the checkout page as the payment method title.';
$_['help_fulfil'] = 'Select one or more order statuses that should trigger %1$s fulfillment. When any of these statuses are set, the order will be marked as fulfilled with %1$s. Buyer payment terms become active and payout cycle begins. You can select multiple statuses (Hold Ctrl/Cmd to select multiple). Default: Shipped';
$_['help_cancel'] = 'Order has been cancelled with %s. This prevents fulfillment and stops the payment process. Default: Canceled';
$_['help_refund'] = 'Moving an order into this status refunds it in full with %s and issues a credit note to the buyer. Default: Refunded. Refunds are sent from a later release.';

// Error
$_['error_permission'] = 'You do not have permission to modify the Two payment settings.';
$_['error_partial'] = 'Some settings were not saved. See the highlighted fields.';
$_['error_title_missing'] = 'Enter a title.';
$_['error_status_missing'] = 'Select an order status.';
$_['error_status_unknown'] = 'Select an order status this shop has.';
$_['error_status_void'] = 'OpenCart uses this status for orders that are being edited, so it cannot be mapped. Select another order status.';
$_['error_key_missing'] = 'Enter an API key.';
$_['error_kept_malformed'] = 'This is not a valid API key: it may only contain printable characters, with no spaces or line breaks. The previously saved key was kept.';
$_['error_kept_environment'] = 'Please select a valid environment.';
$_['error_environment_unhealthy'] = 'The stored environment cannot be used on this shop, so sandbox is used. Select an environment and save.';
$_['error_key_invalid'] = 'This API key was rejected by %1$s. It may be invalid or expired - check the key in your %1$s portal.';
$_['error_key_service'] = '%s could not verify the API key right now (HTTP %d). This is usually temporary - try again shortly.';
$_['error_key_unreachable'] = 'This shop could not reach the %s API at all (network, DNS or firewall). The API key itself has not been judged.';
$_['error_key_not_configured'] = 'Enter your %1$s API key to enable %1$s.';
$_['error_key_unexpected'] = '%s returned an unexpected response while verifying the API key (HTTP %d).';
$_['error_key_unverified'] = 'The API key could not be verified.';

// Order page: the Two tab and the invoice download
$_['text_two_order'] = 'Two order';
$_['text_two_state'] = 'State at Two';
$_['text_no_two_order'] = 'No Two order is linked to this order.';
$_['button_invoice'] = 'Download invoice';
$_['help_invoice'] = 'The invoice Two issued when the order was fulfilled.';
$_['error_invoice_permission'] = 'You do not have permission to see this invoice.';
$_['error_invoice_not_ready'] = 'The invoice is not ready yet because the order is still being fulfilled. Please try again later.';
$_['error_invoice_state'] = 'No invoice is available because the order is in state: %s.';
$_['error_invoice_no_reference'] = 'No Two order is linked to this order.';
$_['error_invoice'] = 'The invoice could not be retrieved. Please try again later.';

// Order page: refunds and the credit note
$_['button_credit_note'] = 'Download credit note';
$_['help_credit_note'] = 'The latest credit note Two issued for this order.';
$_['error_credit_note'] = 'The credit note could not be retrieved. It may still be processing. Please try again later.';
$_['text_refund'] = 'Refund';
$_['text_refunded'] = 'Refunded at Two';
$_['text_remaining'] = 'Left to refund';
$_['text_refund_help'] = 'Pick how many of each line to refund, or enter an amount line, or both. The full refund status refunds whatever is left.';
$_['text_refund_unavailable'] = 'Refunds open once Two has invoiced the order.';
$_['text_refund_unread'] = 'Two could not be asked for this order. Reload the page to try again.';
$_['text_refunds'] = 'Refunds sent from this shop';
$_['column_line'] = 'Line';
$_['column_quantity'] = 'Ordered';
$_['column_net'] = 'Net';
$_['column_tax'] = 'Tax';
$_['column_rate'] = 'Tax rate';
$_['column_refund_quantity'] = 'Refund';
$_['column_date'] = 'Date';
$_['column_amount'] = 'Amount';
$_['column_state'] = 'Outcome';
$_['entry_refund_net'] = 'Amount line, net';
$_['entry_refund_tax'] = 'Amount line, tax';
$_['entry_refund_rate'] = 'Amount line, tax rate';
$_['entry_refund_reason'] = 'Reason (on the credit note)';
$_['button_refund'] = 'Send refund to Two';
$_['error_refund_permission'] = 'You do not have permission to refund this order.';
$_['text_refund_state_pending'] = 'Not answered';
$_['text_refund_state_done'] = 'Refunded';
$_['text_refund_state_refused'] = 'Refused';
$_['text_refund_state_unknown'] = 'Unknown';
$_['text_refund_state_not_applied'] = 'Not applied';
$_['button_retry'] = 'Retry';
$_['text_refund_blocked'] = 'A refund below has an unknown outcome. Retry it before sending another refund: it is sent again under the same key, so Two refunds it at most once.';
$_['column_left'] = 'Left to refund';
