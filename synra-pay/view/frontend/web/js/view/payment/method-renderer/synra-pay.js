/* Magento checkout glue for the SynraPay hosted session.

   Keeps a session open for the billing email / phone and the quote total,
   mounts SynraPay's card fields inside the payment method, and on "Place
   Order" hands the store the Direct API order id. Magento places the order
   (pending_payment) and answers its id; synra-hs-core.js then pays + confirms
   and sends the shopper to the success page. */
define([
    'jquery',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/checkout-data',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/model/messageList',
    'Synra_Pay/js/synra-hs-core'
], function ($, ko, Component, quote, checkoutData, customer, fullScreenLoader, additionalValidators, globalMessages, HS) {
    'use strict';

    var cfg = (window.checkoutConfig && window.checkoutConfig.payment && window.checkoutConfig.payment.synra_pay) || {};
    var i18n = cfg.i18n || {};

    return Component.extend({
        defaults: {
            template: 'Synra_Pay/payment/synra-pay',
            redirectAfterPlaceOrder: false
        },
        syncTimer: null,
        hooked: false,

        initialize: function () {
            this._super();
            var self = this;
            // The session follows the shopper: billing details and the total.
            quote.billingAddress.subscribe(function () { self.syncSoon(600); });
            quote.shippingAddress.subscribe(function () { self.syncSoon(600); });
            quote.totals.subscribe(function () { self.syncSoon(600); });
            this.isChecked.subscribe(function (code) { if (code === self.getCode()) { self.syncSoon(100); } });
            this.hookEvents();
            return this;
        },

        hookEvents: function () {
            if (this.hooked) { return; }
            this.hooked = true;
            var self = this;
            HS.on('busy', function (e) {
                if (e.busy) { fullScreenLoader.startLoader(); } else { fullScreenLoader.stopLoader(); }
            });
            HS.on('fail', function (e) {
                fullScreenLoader.stopLoader();
                self.isPlaceOrderActionAllowed(true);
                if (e && e.message) { self.messageContainer.addErrorMessage({ message: e.message }); }
            });
        },

        getCode: function () { return 'synra_pay'; },
        getTitle: function () { return cfg.title || 'Credit / Debit card'; },
        getDescription: function () { return cfg.description || ''; },
        getIcon: function () { return cfg.icon || ''; },

        /** Called by the template once the mount element is in the DOM. */
        onMount: function (el) {
            if (el && el.nodeType === 1) { HS.setContainer(el); }
            this.syncSoon(0);
        },

        customer: function () {
            var b = quote.billingAddress() || quote.shippingAddress() || {};
            var email = '';
            if (customer.isLoggedIn()) {
                email = (window.checkoutConfig.customerData && window.checkoutConfig.customerData.email) || '';
            } else {
                email = quote.guestEmail || checkoutData.getValidatedEmailValue() || checkoutData.getInputFieldEmailValue() || '';
            }
            var totals = quote.totals() || {};
            return {
                email: email,
                phone: b.telephone || '',
                phone_country_code: HS.dialCode(b.countryId),
                country: b.countryId || '',
                first_name: b.firstname || '',
                last_name: b.lastname || '',
                total: String(totals.grand_total || '') + (totals.quote_currency_code || '')
            };
        },

        syncSoon: function (delay) {
            var self = this;
            clearTimeout(this.syncTimer);
            this.syncTimer = setTimeout(function () {
                if (self.isChecked() !== self.getCode()) { return; }
                var mount = document.querySelector('[data-synra-hs-mount]');
                if (!mount) { return; }
                HS.setContainer(mount);
                HS.sync(self.customer()).catch(function () { /* shown in the box */ });
            }, delay == null ? 500 : delay);
        },

        getData: function () {
            var data = { method: this.getCode(), po_number: null, additional_data: {} };
            data.additional_data[cfg.hsField || 'synra_hs_order_id'] = HS.orderId();
            return data;
        },

        validate: function () {
            if (HS.busy()) { return false; }
            if (!HS.ready()) {
                HS.setError(i18n.notReady || 'The card form is still loading.');
                this.syncSoon(0);
                return false;
            }
            if (!HS.complete()) {
                HS.setError(i18n.incomplete || 'Please complete your card details.');
                return false;
            }
            return true;
        },

        /**
         * Place Order: Magento validates and creates the order as usual (the
         * initialize command links it to the Direct API order), then the page
         * submits the card and confirms. No redirect from here — payFor() moves
         * the shopper to the success page once the ORDER is paid.
         */
        placeOrder: function (data, event) {
            var self = this;
            if (event) { event.preventDefault(); }
            if (!this.validate() || !additionalValidators.validate() || this.isPlaceOrderActionAllowed() !== true) {
                return false;
            }
            this.isPlaceOrderActionAllowed(false);
            this.getPlaceOrderDeferredObject()
                .done(function (orderId) {
                    HS.payFor(String(orderId || '').replace(/"/g, ''));
                })
                .fail(function () {
                    // Magento refused the order (validation, or the total changed):
                    // the store may have dropped the session, so ask again.
                    self.isPlaceOrderActionAllowed(true);
                    self.syncSoon(200);
                });
            return true;
        }
    });
});
