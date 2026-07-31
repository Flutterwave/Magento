define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Flutterwave_Payment/payment/flutterwave',
            redirectAfterPlaceOrder: false
        },

        getCode: function() {
            return 'flutterwave';
        },

        getData: function() {
            return {
                'method': this.item.method,
                'additional_data': {}
            };
        },

        afterPlaceOrder: function() {
            window.location.replace(url.build('flutterwave/redirect'));
        }
    });
});
