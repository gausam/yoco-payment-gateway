jQuery(document).ready(function () {
  var yocoSDKInstance, yocoForm;
  var yocoCardFrameID = '#card-frame';

  var YocoWommerce = {
    init: function () {
      yocoSDKInstance = new window.YocoSDK({
        publicKey: yoco_params.publicKey,
      });

      if (yoco_params.is_checkout === 'yes') {
        yoco_params.client_email = jQuery('#yoco-payment-data').data(
          'client-email'
        );
        yoco_params.client_fn = jQuery('#yoco-payment-data').data('client-fn');
        yoco_params.client_ln = jQuery('#yoco-payment-data').data('client-ln');
        yoco_params.amountInCents = jQuery('#yoco-payment-data').data(
          'amount-in-cents'
        );
      }
      YocoWommerce.subscribeToCheckoutUpdates();
      YocoWommerce.addFormListeners();
      YocoWommerce.mount();
    },
    subscribeToCheckoutUpdates: function () {
      if (yoco_params.is_checkout === 'yes') {
        jQuery(document.body).on('updated_checkout', function () {
          if (jQuery(yocoCardFrameID).children().length) {
            return;
          }
          if (yocoForm != null) {
            YocoWommerce.unmount();
          }
          YocoWommerce.mount();
        });
      }
    },
    addFormListeners() {
      jQuery('form.woocommerce-checkout').on('change', YocoWommerce.reset);
      jQuery('form.woocommerce-checkout').on(
        'checkout_place_order_class_yoco_wc_payment_gateway',
        this.onSubmit
      );
      jQuery('form#order_review').on('submit', this.onSubmit);
    },
    mount: function () {
      yocoForm = yocoSDKInstance.inline({
        showErrors: false,
        showSubmitButton: false,
        layout: 'basic',
        amountInCents: yoco_params.amountInCents,
        currency: yoco_params.currency,
        email: yoco_params.client_email,
        firstName: yoco_params.client_fn,
        lastName: yoco_params.client_ln,
        description: `checkout page order from ${yoco_params.client_fn} ${yoco_params.client_ln} (${yoco_params.client_email})`,
        metadata: {
          billNote: `checkout page order from ${yoco_params.client_fn} ${yoco_params.client_ln} (${yoco_params.client_email})`,
          productType: 'wc_plugin',
          customerFirstName: yoco_params.client_fn,
          customerLastName: yoco_params.client_ln,
        },
      });
      yocoForm.on('validity_change', YocoWommerce.reset);
      yocoForm.mount(yocoCardFrameID);
    },
    unmount: function () {
      if (yocoForm == null) {
        return;
      }
      yocoForm = null;
      jQuery(yocoCardFrameID).html('');
    },
    block: function () {
      jQuery('form.woocommerce-checkout')
        .addClass('processing')
        .block({
          message: null,
          overlayCSS: {
            background: '#fff',
            opacity: 0.6,
          },
        });
    },
    unblock: function () {
      jQuery('form.woocommerce-checkout').removeClass('processing').unblock();
    },
    reset: function () {
      jQuery('.yoco-wc-payment-gateway-error').remove();
      jQuery('.yoco-wc-payment-gateway-token').remove();
    },
    hasTokenResult: function () {
      return 0 < jQuery('input.yoco-wc-payment-gateway-token').length;
    },
    saveTokenResultAndSubmit: function (result) {
      jQuery('form.woocommerce-checkout')
        .append(
          jQuery('<input type="hidden" />')
            .addClass('yoco-wc-payment-gateway-token')
            .attr('name', 'yoco_create_token_result')
            .val(JSON.stringify(result))
        )
        .removeClass('processing')
        .submit();
    },
    onSubmit: function () {
      if (YocoWommerce.hasTokenResult()) {
        return true;
      }
      YocoWommerce.block();
      yocoForm.createToken().then(function (result) {
        if (result.error) {
          YocoWommerce.onError(result);
          YocoWommerce.unblock();
          return;
        }
        YocoWommerce.saveTokenResultAndSubmit(result);
      });
      return false;
    },
    onError: function (result) {
      YocoWommerce.reset();
      // Leave for troubleshooting.
      console.log(result);
      var errorContainer = jQuery('#yoco-wc-payment-gateway-errors');
      jQuery(errorContainer).html(
        '<ul class="woocommerce_error woocommerce-error yoco-wc-payment-gateway-error"><li /></ul>'
      );
      jQuery(errorContainer).find('li').text(result.error.message);
      jQuery('html, body').animate(
        {
          scrollTop:
            jQuery('.yoco-wc-payment-gateway-error').offset().top - 200,
        },
        200
      );
    },
  };

  YocoWommerce.init();
});
