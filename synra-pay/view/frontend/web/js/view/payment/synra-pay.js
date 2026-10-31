/* Registers the SynraPay renderer with the checkout's payment method list. */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'synra_pay',
        component: 'Synra_Pay/js/view/payment/method-renderer/synra-pay'
    });

    return Component.extend({});
});
