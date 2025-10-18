# fluentcart-jcc-payment-gateway

Custom FluentCart payment gateway that replicates the WooCommerce JCC integration, including hosted checkout, callbacks, refunds, optional Google Pay, and fiscal cart payloads. See `docs/` for reference material and implementation notes.

## Getting Started

1. Copy this plugin directory into your WordPress installation (`wp-content/plugins/fluentcart-jcc-payment-gateway`).
2. Activate **FluentCart** (and FluentCart Pro if available), then activate **FluentCart JCC Payment Gateway**.
3. Visit `FluentCart → Settings → Payments → JCC` to enter your merchant credentials and configure stage mode, callbacks, fiscalisation, and Google Pay options.
4. Supply the callback URL shown in the settings page to your JCC account (static callbacks) or rely on per-transaction dynamic callbacks.
5. Place a test order in FluentCart using the JCC gateway to confirm the hosted checkout redirect, return, and callback flows. Refer to `docs/testing-plan.md` for manual verification steps.
