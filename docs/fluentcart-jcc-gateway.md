# FluentCart JCC Gateway Implementation Notes

## Overview
- `fluentcart-jcc-payment-gateway.php` registers the JCC gateway when FluentCart is active.
- `includes/Payment/JccGateway.php` maps FluentCart checkout flows to the JCC REST API, supports hosted payments, webhook/callback handling, refunds, Google Pay, and optional fiscal data.
- `includes/Payment/JccSettings.php` exposes gateway settings (mode, credentials, callbacks, fiscalisation, Google Pay, etc.).
- `includes/Payment/JccApi.php` wraps JCC endpoints (`register*.do`, `getOrderStatusExtended.do`, `refund.do`, `reverse.do`, callback sync, Google Pay tokenisation).
- `includes/Payment/OrderPayloadBuilder.php` converts FluentCart order/line items into the structured payloads required by JCC (amounts in minor units, cart bundle, billing data).
- `includes/Logger.php` records gateway debugging output in `uploads/fluentcart-jcc-logs/` when logging is enabled.
- Front-end helpers live in `assets/js/google-pay.js` (Google Pay button helper) and `assets/images/logo.svg` (placeholder admin icon).

## Feature Parity with WooCommerce Reference
- **Hosted checkout:** Delegates to JCC `register.do` / `registerPreAuth.do` based on stage mode, redirecting customers to the hosted payment page with return URL tracking FluentCart transactions.
- **Dynamic/static callbacks:** Dynamic callbacks include a per-transaction URL with nonce; static callbacks register once per mode and reuse the shared endpoint.
- **Order bundle & fiscal fields:** When `send_order` is enabled, order lines, shipping, VAT defaults, and FES cashbox data mirror WooCommerce’s JSON schema.
- **Billing payload:** Optionally sends customer billing details (`billingPayerData`) when supplied and valid.
- **Refunds & reversals:** Implements the same two-step logic (reverse vs. refund) by re-checking gateway status before confirming success.
- **Google Pay:** Loads Google Pay SDK, exposes a JS helper to mount buttons, and proxies tokens to JCC’s `/payment/google/payment.do` endpoint.
- **Logging:** Structured UTC logs echo key request/response pairs for troubleshooting, similar to WooCommerce’s monthly log files.
- **Custom checkout channel:** Registers `jcc_gateway` with FluentCart’s custom checkout button list so the Google Pay UI mounts automatically, and persists enriched transaction metadata (amount, mode, gateway response) for reconciliation.
- **Webhook endpoint:** Supports both the legacy query endpoint and a REST route (`/wp-json/fluentcart/jcc/v1/webhook`) with basic-auth verification matching the configured merchant credentials.

## Integration Checklist
1. Enable the gateway and supply test merchant credentials in FluentCart → Payments → JCC.
2. Choose stage (one-stage/two-stage) and optional redirect URLs (`success_url`, `fail_url`).
3. Toggle “Send Cart Details” if fiscalisation or line-item detail is required.
4. Configure callbacks:
   - Static (default): gateway automatically syncs the callback URL (cached daily in admin).
   - Dynamic: JCC receives a per-transaction callback with nonce protection.
5. Optional Google Pay:
   - Provide merchant ID, name, mode, and call `FluentCartJccGooglePay.mount()` from your checkout UI with the current transaction UUID + totals.
6. Verify return and callback endpoints (`?fc_jcc_action=result|callback`) resolve correctly on the site front-end.
7. Confirm refunds from FluentCart push to JCC and record the adjustment in order history.

## Follow-Up / TODO
- Exercise callback, REST webhook, and refund flows against the JCC sandbox to confirm status mapping and note handling.
- Extend `OrderPayloadBuilder` once FluentCart exposes richer item-level metadata (e.g., discounts, FES TRU codes).
- Build automated tests (or expand the manual test suite in `docs/testing-plan.md`) when a FluentCart test harness is available.

## Implementation Plan (2024-05-09)
- Introduce a standalone plugin (`fluentcart-jcc-payment-gateway.php`) that boots once FluentCart fires `fluent_cart/init` and registers the gateway via `fluent_cart_api()->registerCustomPaymentMethod('jcc', …)`.
- Provide a lightweight namespaced autoloader (`FluentCartJcc\`) that maps to `includes/` for classes and uses constants for plugin path/URL resolution.
- Core classes:
  - `Payment\JccGateway` extending `AbstractPaymentGateway` to orchestrate checkout, callbacks, refunds, Google Pay, and metadata.
  - `Payment\JccSettings` extending `BaseGatewaySettings` to expose admin fields (mode, credentials, callbacks, Google Pay, fiscal data, logging controls).
  - `Payment\JccApi` encapsulating REST calls (register, status, refund, reverse, Google Pay tokenisation) with shared request helpers and error handling.
  - `Payment\OrderPayloadBuilder` to translate FluentCart order + transaction context into JCC payloads (amount conversion, bundle lines, billing data, fiscal extras).
  - `Logger` helper that writes structured JSON lines to `wp_upload_dir()/fluentcart-jcc-logs/` when enabled.
- Frontend assets:
  - `assets/js/google-pay.js` exposing `FluentCartJccGooglePay.mount()` to render the Google Pay button and bridge tokens back to the PHP controller.
  - `assets/images/logo.svg` for FluentCart admin/payment icon usage.
- Hook surface:
  - Register REST route + legacy listener endpoints (`?fc_jcc_action=callback|result`) during `boot()`.
  - Add filters for custom checkout buttons (`fluent_cart/payment_methods_with_custom_checkout_buttons`) when Google Pay is enabled.
  - Expose settings fields via `fields()` and localised checkout data (mode, merchant, Google Pay config, listener URLs).
- Logging/Documentation:
  - Record every significant implementation step in this document with timestamps.
  - Update `docs/testing-plan.md` as flows materialise (hosted checkout, callbacks, refunds, Google Pay).

## Implementation Log
- **2024-05-09:** Scaffolded WordPress plugin bootstrap, autoloader, and FluentCart registration flow (`fluentcart-jcc-payment-gateway.php`, `includes/Plugin.php`).
- **2024-05-09:** Added JCC settings container, API client, payload builder, and structured logger helpers under `includes/`.
- **2024-05-09:** Implemented `JccGateway` class with hosted checkout registration, callback handling, refund wiring, and Google Pay hooks; shipped placeholder Google Pay asset bundle and SVG logo.
