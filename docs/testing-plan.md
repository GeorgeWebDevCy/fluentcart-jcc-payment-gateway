# FluentCart JCC Gateway Testing Plan

Use this checklist to validate the FluentCart JCC gateway in the JCC sandbox once credentials are provisioned. Update or automate these scenarios when a formal testing harness becomes available.

## Environment Preparation
- Configure sandbox merchant credentials in FluentCart → Payments → JCC (test mode).
- Confirm the static callback URL is registered in the JCC back office after saving settings; monitor `wp_uploads/fluentcart-jcc-logs/` for `Callback sync failed` entries.
- Ensure the site’s `/` front-end is reachable externally so hosted checkout callbacks can hit `?fc_jcc_action=callback`.

## Manual Test Scenarios
1. **Hosted one-stage payment**
   - Place an order through FluentCart checkout using JCC (one-stage mode).
   - Verify redirect to JCC hosted page, complete payment with sandbox card.
   - Confirm WooCommerce order transitions to the configured paid status, stock reduces, and success URL flows correctly.
2. **Hosted two-stage payment**
   - Switch to two-stage in settings, repeat checkout.
   - Ensure the transaction remains authorised (`orderStatus = 1`) until manually captured in JCC, and FluentCart reflects the correct status.
3. **Failure redirect**
   - Use a card number/code that triggers a declined payment.
   - Confirm the order is marked failed and the customer is redirected to the fail URL with an error notice.
4. **Callback accuracy**
   - For static callbacks: check JCC logs that the configured callback URL is invoked and FluentCart updates order state without manual refresh.
   - For dynamic callbacks: toggle mode, place another order, and ensure the nonce-protected callback URL works (look for `Invalid callback nonce` in logs).
   - Hit the REST webhook endpoint (`/wp-json/fluentcart/jcc/v1/webhook`) with the same payload using Basic Auth (`merchant_id:password`) to confirm authentication and JSON responses.
5. **Refunds**
   - Complete a JCC-hosted payment.
   - Issue a partial and a full refund inside FluentCart; confirm gateway API responses succeed and the order displays refund notes.
   - Verify the refund log entries in `fluentcart-jcc-logs` and JCC merchant portal balances.
6. **Google Pay quick checkout**
   - Enable Google Pay, provide sandbox merchant ID, and load checkout.
   - Choose JCC payment – the Google Pay button should appear immediately inside the payment method block.
   - Complete a payment via Google Pay and confirm the customer returns through the `result` endpoint with a paid order.

## Regression Targets (Automate When Possible)
- Payment initialisation payload correctness (amount minor units, currency mapping, order bundle composition).
- Callback signature/nonce validation path.
- Refund routing (reverse vs. refund). Use mocks or fixture responses when a test harness is available.
- Google Pay token submission flow, including repeated renders of the payment method component.

## Verification Artifacts
- Capture screenshots of the checkout, success, and fail pages.
- Export JCC transaction logs showing callback invocations and refund operations.
- Archive FluentCart order notes and transaction meta for each scenario.
- Store the generated log files for debugging regressions.
