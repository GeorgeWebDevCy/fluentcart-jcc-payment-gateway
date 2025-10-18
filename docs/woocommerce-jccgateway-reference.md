# WooCommerce JCC Gateway Functional Reference

This document captures the behaviour of the WooCommerce JCC payment gateway plugin (`woocommerce-jccgateway`) so the same feature set can be reproduced inside the FluentCart gateway.

## Architecture Overview
- **Entry point:** `woocommerce-gateway-jccgateway.php` bootstraps the plugin, defines constants (see `includes/include.php`), and wires the gateway into WooCommerce via `WC_JCCGateway__Payments::init()`.
- **Gateway class:** `includes/class-wc-gateway-jccgateway.php` extends `WC_Payment_Gateway` and delivers the full runtime behaviour (settings, checkout flow, callbacks, refunds, Google Pay, etc.).
- **Blocks integration:** `includes/blocks/class-wc-jccgateway-payments-blocks.php` registers the gateway with WooCommerce Blocks.
- **Ancillary helpers:** `includes/form-fields.php` builds the admin settings schema; `includes/libs/FesHelper.php` adds per-product FES fields when enabled.

## Core Bootstrapping (`woocommerce-gateway-jccgateway.php`)
- Registers the payment method (`woocommerce_payment_gateways`) and block support (`woocommerce_blocks_payment_method_type_registration`).
- Loads translation files (`load_jccgateway_textdomain`) and declares compatibility with WooCommerce custom order tables.
- Autoloads helpers from `includes/libs/`.

## Configuration Flags (`includes/include.php`)
Several compile-time constants toggle optional behaviour:
- `JCCGATEWAY_PROD_URL` / `JCCGATEWAY_TEST_URL`: REST API base URLs.
- Feature flags: logging, callback enablement, back URL, refunds, mandatory currency, Google Pay, etc.
- Google Pay specific: `JCCGATEWAY_GOOGLE_PAY_GATEWAY_NAME`.
Replicating plugin behaviour requires respecting these switches; most are `true` by default.

## Admin Settings Schema (`includes/form-fields.php`)
`FormFieldsGenerator::generate()` returns all fields displayed in WooCommerce → Settings → Payments → JCC. Key groups:
- **Base settings:** enable, title, API credentials (`merchant` and `password` or combined `token`), test mode toggle, and one-stage vs two-stage (pre-auth) capture.
- **Payment presentation:** description, order completion status, optional success/failure redirect URLs.
- **Cart data & fiscalisation (guarded by `JCCGATEWAY_ENABLE_CART_OPTIONS`):**
  - `send_order` (enable cart payload),
  - tax system defaults,
  - fiscal document format (`versionFfd`),
  - payment method/object types (including delivery-specific values).
- **Google Pay (if `JCCGATEWAY_ENABLE_FAST_CHECKOUT`):** merchant IDs, mode (TEST/PRODUCTION), sections where the fast checkout button appears (product page, checkout), button styling.
- **Miscellaneous controls:** min/max order thresholds, allowed/disallowed product categories, optional “Back to shop” URL, FES cashbox configuration.

## Gateway Instantiation (`__construct` in `class-wc-gateway-jccgateway.php`)
On creation the gateway:
- Loads settings, decodes optional credential token, and saves feature flags (`test_mode`, `stage_mode`, callbacks, logging).
- Registers WooCommerce hooks:
  - `woocommerce_update_options_payment_gateways_{id}` → `process_admin_options`
  - `woocommerce_receipt_{id}` → `receipt_page`
  - `woocommerce_api_jccgateway` → `webhook_result`
  - `woocommerce_before_checkout_form` → `display_custom_error_message`
  - Subscription hook `woocommerce_scheduled_subscription_payment_jccgateway`
  - Google Pay hooks (scripts, buttons, AJAX) when enabled.
- Prepares REST endpoints based on test/production and merchant prefix overrides.

## Availability Logic (`is_available`)
- Respects global availability (`parent::is_available()`), min/max order total settings, and category allow/deny lists (including ancestor categories).

## Checkout & Payment Flow
1. **process_payment($order_id):**
   - For “pay for order” links it immediately renders the gateway form (`generate_form`).
   - Otherwise redirects customers to the standard WooCommerce payment URL.
2. **generate_form($order_id):**
   - Builds registration payload for JCC REST API (`register.do` for one-stage, `registerPreAuth.do` for two-stage).
   - Derives currency (if `JCCGATEWAY_MANDATORY_CURRENCY`), amount (minor units), language, and JSON metadata (`CMS`, module version, Google Pay enabled flag).
   - Optionally adds fiscal/cart bundle:
     - `_createOrderBundle()` converts order items, shipping, and FES codes into JCC’s `cartItems` format with tax, quantity, and measurement metadata.
     - Applies `paymentMethodType`, `paymentObjectType`, and delivery-specific values per item.
   - When callbacks are dynamic, injects a `dynamicCallbackUrl`.
   - If customer is logged in, sets a `clientId` hash to support tokenised or recurring flows.
   - Calls `_sendGatewayData()` to register the order and outputs an auto-submitting HTML form via `receipt_page`.
3. **display_custom_error_message():** surfaces session-based checkout notices if previous attempts failed.

## Google Pay Fast Checkout
Enabled when `JCCGATEWAY_ENABLE_FAST_CHECKOUT` and `google_pay_merchantId` are set.
- **Scripts:** `add_google_pay_script()` enqueues Google’s Pay JS on product and checkout pages, localising order or product totals, currency, merchant IDs, and button styling (`googlePayParams`).
- **UI insertion:**
  - `add_google_pay_button_on_product_page()` renders the button for simple products when the user is logged in.
  - `add_google_pay_button_on_checkout_page()` moves the button near payment methods or order summary in cart/checkout blocks.
- **AJAX handler (`handle_google_pay_ajax`):**
  - Validates payload, optionally fabricates an order from a product ID (`create_woocommerce_order()`), or reuses the current cart draft order.
  - Calls JCC Google endpoint (`payment/google/payment.do`) with payment token, amount, currency, and metadata.
  - On success, redirects via the WooCommerce API callback endpoint with `mdOrder`.

## Callback Registration & Management
- **process_admin_options():**
  - Syncs the shop’s callback URL with the JCC merchant portal whenever settings are saved (unless callbacks disabled).
  - Builds `mportal/mvc/public/merchant/update{merchant-suffix}` endpoint and sends a JSON payload via `_updateGatewayCallback()`.
  - Supports alternate domains for prod/test via constants.
- **_updateGatewayCallback():** posts callback configuration (enabled, HTTP method, operations) to JCC.

## Webhook & Return Handling (`webhook_result`)
Triggered on `wc-api=jccgateway` with different `action` query vars:
- **`action=result`:** synchronous return after hosted payment page.
  - Fetches order status (`getOrderStatusExtended.do`).
  - For approved/deposited statuses (`1`/`2`) without callbacks, manually updates order status, reduces stock, stores transaction ID, and redirects to success URL or thank-you page.
  - For failures, moves the order to `failed`, records notice, and redirects to the payment page or custom fail URL.
- **`action=callback`:** asynchronous server callback.
  - Pulls authoritative order status from JCC and derives WooCommerce actions:
    - `1`/`2`: payment success → update order meta `orderId`, transaction ID, status, stock, and optionally redirect customer.
    - `4`: refund → auto-create WooCommerce refund records with amount and note.
    - `3`: reversal/cancellation → create refund entries to mirror reverse amount.
    - Otherwise: mark order as failed if payment ID missing.
  - Logs each transition when logging enabled.

## Refunds & Reversals (`process_refund`)
- Converts WooCommerce refund amounts to minor units.
- Queries current order state (`getOrderStatusExtended.do`) to decide between:
  - `refund.do` for deposited payments,
  - `reverse.do` for approved (but not captured) transactions.
- Handles gateway errors (e.g. error code `7` for invalid partial refund state) and re-queries final status to confirm success.

## Logging (`writeLog`)
- Writes diagnostic data to `logs/wc_jccgateway_{YYYY-MM}.log`.
- Obscures sensitive credentials from responses before logging.

## WooCommerce Blocks Support
- `WC_Gateway_JCCGateway__Blocks_Support` exposes the gateway to checkout blocks.
- Registers `assets/js/frontend/blocks.js` which renders label + logo and surfaces the gateway description and capabilities to the block-based checkout.

## Subscription Hook
- `process_subscription_payment()` (bound to `woocommerce_scheduled_subscription_payment_jccgateway`) currently marks orders complete or throws if the gateway result option is not `success`. The fluentcart port likely needs a more robust token-based charge flow.

## Utility Methods
- `find_order_by_number()` handles sequential order numbers and meta lookups.
- `get_order_number_for_gateway()` appends a timestamp suffix to keep gateway order numbers unique.
- `get_numeric_currency_code()` maps ISO alpha codes to numeric strings expected by JCC.
- `create_woocommerce_order()` constructs orders programmatically for Google Pay quick purchases.

## Optional FES Support (`includes/libs/FesHelper.php`)
- Adds a `_fes_truCode` field on the WooCommerce product admin screen and saves it to product meta.
- When cart data is sent, embeds the TRU code in each item’s details and exposes a `fes_cashboxId` field in gateway settings.

## Implementation Notes for FluentCart
To replicate behaviour in FluentCart:
- Mirror the settings surface (credentials, stage mode, redirect URLs, fiscal/cart options, Google Pay configuration, availability rules).
- Recreate the REST interactions:
  - Registration endpoints (`register*.do`), `payment/google/payment.do`, `getOrderStatusExtended.do`, `refund.do`, `reverse.do`, and callback management endpoints.
  - Maintain the same parameter structure (amounts in minor units, `jsonParams` metadata, optional `billingPayerData`, `clientId`, cart bundle schema).
- Support both synchronous returns and asynchronous callbacks, including automatic refund/reversal handling.
- Provide logging comparable to `writeLog` for troubleshooting.
- Ensure compatibility with WooCommerce Blocks-equivalent frontend components if FluentCart offers them.
- Consider how to map Google Pay fast checkout and product-level TRU codes into the FluentCart architecture.
