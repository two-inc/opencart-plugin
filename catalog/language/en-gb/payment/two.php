<?php

// Storefront copy. %s is the brand's product name (Brand::default()).
$_['heading_title'] = 'Two';

// Tile. %s is the brand's product name.
$_['entry_company_name'] = 'Company name';
$_['entry_organization_number'] = 'Organisation number';
$_['entry_country'] = 'Company country';
$_['text_company_intro'] = 'Search for the company you are buying for.';
// %s here is the company as the buyer reads it.
$_['text_company_from_address'] = 'Buying for %s.';
$_['text_company_missing'] = 'To pay with %s, search for your company name and select your company from the results to verify your business.';
$_['button_confirm'] = 'Confirm order';

// Optional fields (Build Guide section 6.1), in the order the tile shows them.
$_['entry_invoice_email'] = 'Invoice email address';
$_['entry_purchase_order_number'] = 'PO Number';
$_['entry_project'] = 'Project';
$_['entry_department'] = 'Department';
$_['text_optional'] = 'optional';
$_['error_field_too_long'] = '%1$s must be %2$d characters or fewer.';

// Order intent notices (section 6.3).
$_['text_intent_approved'] = 'Your invoice with %s is likely to be accepted, subject to additional checks.';
$_['text_intent_declined'] = 'Your invoice with %s cannot be approved at this time. Please select an alternative payment method.';

// Company search and sole trader, read by the checkout script. %1$s is the
// brand's product name, %2$d the shortest search term.
$_['js_search_label'] = 'Search for a company';
$_['js_search_hint'] = 'Enter %2$d or more characters';
$_['js_searching'] = 'Searching...';
$_['js_no_matches'] = 'No matches found';
$_['js_search_unavailable'] = 'Company search is temporarily unavailable. Please try again.';
$_['js_select_country'] = 'Select your country above to search for your company.';
$_['js_country_not_covered'] = 'Company search does not cover this country. Enter your company manually.';
$_['js_manual_entry'] = 'Enter manually';
$_['js_back_to_search'] = 'Search for company';
$_['js_sole_trader_entry'] = 'I\'m a sole trader';
$_['js_sole_trader_error'] = 'The sole trader sign-up could not be started. Please try again.';
$_['js_sole_trader_popup_blocked'] = 'Your browser blocked the sole trader sign-up window. Allow pop-ups for this site and try again.';
$_['js_change_company'] = 'Search for a different company';
$_['js_organization_number'] = 'Organisation number';
$_['js_checking'] = 'Checking %1$s payment eligibility...';
$_['js_selected'] = 'Selected';
$_['js_number_missing'] = 'Enter your organisation number to continue.';
$_['error_too_many'] = 'Too many requests. Please wait and try again.';

// Placement refused (section 6.3): the intent, and what changed since the method list.
$_['error_intent_connection'] = 'Connection error with payment provider. Please try again.';
$_['error_intent_unavailable'] = 'Payment provider temporarily unavailable. Please try again later.';
$_['error_intent_unprocessable'] = 'Unable to process your order with %s payment.';
$_['error_placement_currency'] = '%1$s is not available for orders in %2$s. Please choose another currency or payment method.';
$_['error_placement_buyer_country'] = 'This payment method is not available.';
$_['error_sole_trader'] = 'The sole trader sign-up could not be started. Please try again.';

// Callbacks.
$_['text_returning'] = 'Returning to the shop';
$_['text_continue'] = 'Continue';
$_['text_cancelled'] = 'Your order is cancelled.';
$_['text_pending'] = 'We\'re still confirming your payment. Please wait a minute before you try again, so that the order is not placed twice.';
$_['text_cart_changed'] = 'Your cart changed during payment verification. Please review your cart and try again.';
$_['text_history_prefix'] = 'Two: ';

$_['error_order'] = 'Your order could not be found. Please go back to checkout and try again.';
$_['error_payment_method'] = 'Please choose a payment method.';

// Build Guide section 6.4: the buyer never sees Two's own text.
$_['error_two_generic'] = 'Invoice purchase with %s is not available for this order.';
$_['error_two_same_company'] = 'Buyer and merchant may not be the same company';
$_['error_two_company_required'] = 'To pay with %s, enter your company name, organisation number and company country.';
$_['error_two_field_phone_number'] = 'Please enter a valid Phone number to pay on invoice';
$_['error_two_field_organization_number'] = 'Please enter a valid Organization number to pay on invoice';
$_['error_two_field_company_name'] = 'Please enter a valid Company name to pay on invoice';
$_['error_two_field_first_name'] = 'Please enter a valid First name to pay on invoice';
$_['error_two_field_last_name'] = 'Please enter a valid Last name to pay on invoice';
$_['error_two_field_email'] = 'Please enter a valid Email to pay on invoice';
$_['error_two_field_address'] = 'Please enter a valid Address to pay on invoice';
$_['error_two_field_city'] = 'Please enter a valid City to pay on invoice';
$_['error_two_field_country'] = 'Please enter a valid Country to pay on invoice';
$_['error_two_field_postal_code'] = 'Please enter a valid Postal code to pay on invoice';
$_['error_two_field_invoice_email'] = 'Please enter a valid Invoice email address to pay on invoice';
