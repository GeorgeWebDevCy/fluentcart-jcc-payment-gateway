(function () {
    const config = window.fcJccGooglePay || {};

    function waitForGoogle(callback, retries = 0) {
        if (window.google && google.payments && google.payments.api) {
            callback();
            return;
        }

        if (retries > 20) {
            console.warn('Google Pay SDK not available for FluentCart JCC.');
            return;
        }

        setTimeout(function () {
            waitForGoogle(callback, retries + 1);
        }, 150);
    }

    function createClient() {
        return new google.payments.api.PaymentsClient({
            environment: config.environment || 'TEST'
        });
    }

    function buildPaymentRequest(args) {
        const amountMinor = args.amountMinor || 0;
        const currency = args.currency || config.currency || 'EUR';

        return {
            apiVersion: 2,
            apiVersionMinor: 0,
            allowedPaymentMethods: [{
                type: 'CARD',
                parameters: {
                    allowedAuthMethods: ['PAN_ONLY'],
                    allowedCardNetworks: ['MASTERCARD', 'VISA'],
                    billingAddressRequired: true,
                    billingAddressParameters: { format: 'FULL' }
                },
                tokenizationSpecification: {
                    type: 'PAYMENT_GATEWAY',
                    parameters: {
                        gateway: config.gateway || 'bpcpay',
                        gatewayMerchantId: config.gatewayMerchantId || ''
                    }
                }
            }],
            merchantInfo: {
                merchantId: config.merchantId || '',
                merchantName: config.merchantName || 'JCC Merchant'
            },
            transactionInfo: {
                totalPriceStatus: 'FINAL',
                totalPrice: (amountMinor / 100).toFixed(2),
                currencyCode: currency
            }
        };
    }

    function mountButton(container, args) {
        waitForGoogle(function () {
            const paymentsClient = createClient();
            const request = buildPaymentRequest(args);

            const button = paymentsClient.createButton({
                buttonType: config.buttonType || 'buy',
                buttonColor: config.buttonColor || 'default',
                onClick: function () {
                    paymentsClient.loadPaymentData(request)
                        .then(function (paymentData) {
                            submitToken(paymentData, args);
                        })
                        .catch(function (error) {
                            console.error('Google Pay error', error);
                        });
                }
            });

            container.innerHTML = '';
            container.appendChild(button);
        });
    }

    function submitToken(paymentData, args) {
        const token = paymentData.paymentMethodData?.tokenizationData?.token || '';
        if (!token) {
            return;
        }

        const payload = {
            action: 'fluent_cart_jcc_google_pay',
            paymentToken: btoa(token),
            amount: args.amountMinor,
            currency: args.currencyNumeric || config.currencyNumericDefault || '978',
            transaction: args.transactionUuid || ''
        };

        const body = Object.keys(payload).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(payload[key]);
        }).join('&');

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json.success && json.data && json.data.redirect) {
                    window.location.href = json.data.redirect;
                } else {
                    console.error('JCC Google Pay gateway error', json);
                }
            })
            .catch(function (error) {
                console.error('JCC Google Pay AJAX error', error);
            });
    }

    window.FluentCartJccGooglePay = {
        mount: function (selector, args) {
            const container = typeof selector === 'string' ? document.querySelector(selector) : selector;
            if (!container) {
                console.warn('JCC Google Pay container not found.');
                return;
            }

            mountButton(container, args || {});
        }
    };

    function ensureMountContainer(detail) {
        const root = detail?.paymentContainer
            || document.querySelector('[data-payment-method="jcc_gateway"]')
            || document.querySelector('.fluent-cart-payment-method[data-gateway="jcc_gateway"]')
            || document.querySelector('.fluent-cart-payment-method-jcc_gateway');

        if (!root) {
            console.warn('JCC Google Pay root container not found.');
            return null;
        }

        let holder = root.querySelector('.fc-jcc-google-pay');
        if (!holder) {
            holder = document.createElement('div');
            holder.className = 'fc-jcc-google-pay';
            holder.style.marginTop = '12px';
            root.appendChild(holder);
        }

        return holder;
    }

    function mountFromPaymentInfo(detail, response) {
        const args = response?.payment_args || {};
        const mountTarget = ensureMountContainer(detail);

        if (!mountTarget || !window.FluentCartJccGooglePay) {
            return;
        }

        const transactionUuid = args.transaction_uuid || '';
        if (mountTarget.dataset.fcJccMounted === transactionUuid) {
            return;
        }

        mountTarget.dataset.fcJccMounted = transactionUuid;
        mountTarget.innerHTML = '';

        window.FluentCartJccGooglePay.mount(mountTarget, {
            amountMinor: parseInt(args.amount_minor, 10) || 0,
            currency: args.currency || config.currency || 'EUR',
            currencyNumeric: args.currency_numeric || config.currencyNumericDefault || '978',
            transactionUuid
        });
    }

    function handleLoader(loader, message, isError) {
        if (!loader) {
            return;
        }

        if (isError) {
            if (typeof loader.changeLoaderStatus === 'function') {
                loader.changeLoaderStatus(message || 'error');
            }
            if (typeof loader.hideLoader === 'function') {
                loader.hideLoader();
            }
            return;
        }

        if (typeof loader.hideLoader === 'function') {
            loader.hideLoader();
        } else if (typeof loader.changeLoaderStatus === 'function') {
            loader.changeLoaderStatus('');
        }
    }

    window.addEventListener('fluent_cart_load_payments_jcc_gateway', function (event) {
        const detail = event.detail || {};
        const paymentInfoUrl = detail.paymentInfoUrl;
        if (!paymentInfoUrl) {
            return;
        }

        const loader = detail.paymentLoader;
        if (loader && typeof loader.changeLoaderStatus === 'function') {
            loader.changeLoaderStatus('processing');
        }

        fetch(paymentInfoUrl, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (!json || json.status !== 'success') {
                    handleLoader(loader, json?.message || 'Failed to prepare JCC payment.', true);
                    return;
                }

                handleLoader(loader);
                mountFromPaymentInfo(detail, json);
            })
            .catch(function (error) {
                console.error('JCC Google Pay info error', error);
                handleLoader(loader, 'Failed to load JCC payment.', true);
            });
    });
})();
