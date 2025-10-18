(function(window) {
    'use strict';

    if (window.FluentCartJccGooglePay) {
        return;
    }

    /**
     * Lightweight helper that exposes a mount hook the checkout can call.
     * Actual Google Pay orchestration should be implemented in the theme/app,
     * this helper only provides a consistent entry point and event emitter.
     */
    var state = {
        onToken: null
    };

    function emit(eventName, payload) {
        var event;
        try {
            event = new CustomEvent(eventName, { detail: payload });
        } catch (error) {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(eventName, false, false, payload);
        }
        document.dispatchEvent(event);
    }

    window.FluentCartJccGooglePay = {
        mount: function(config) {
            emit('fluentcart-jcc-google-pay-mount', config || {});
        },
        onToken: function(callback) {
            state.onToken = callback;
        },
        submitToken: function(payload) {
            if (typeof state.onToken === 'function') {
                state.onToken(payload);
            }
            emit('fluentcart-jcc-google-pay-token', payload);
        }
    };
})(window);
