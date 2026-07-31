define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'flutterwave',
                component: 'Flutterwave_Payment/js/view/payment/method-renderer/flutterwave-method'
            }
        );

        console.log('Flutterwave added to rendererList', rendererList);

        return Component.extend({});
    }
);
