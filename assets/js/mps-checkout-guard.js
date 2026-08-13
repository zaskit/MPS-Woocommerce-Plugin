/**
 * MPS Gateway — checkout submit guard (classic checkout).
 *
 * Two jobs, both requested 2026-08-13:
 *
 *  1. vSafe: "disable/block the Pay button immediately after the first click to prevent duplicate
 *     submissions." Real problem — seven live orders carried two APPROVED charges, five of them
 *     seconds apart with identical amounts.
 *
 *  2. Salman: on a decline, clear the card number/CVV and put the error in front of the customer,
 *     so a retry is a deliberate re-entry rather than another jab at the same button. That is aimed
 *     at retry volume (2,579 of 9,071 live orders have multiple attempts), not at duplicates.
 *
 * 🛑 This file is a convenience, NOT the guarantee. The authoritative protection is the per-order
 * lock in MPS_Base_Gateway::process_payment(); anything here dies with a JS error or a script
 * optimizer. Never treat the button state as the thing preventing double charges.
 *
 * 🔑 The button MUST be re-enabled on failure. Customers legitimately retry declined cards, and a
 * button stuck disabled would convert a recoverable decline into a lost order — which would cost
 * far more than the duplicates this prevents.
 */
(function ($) {
    'use strict';

    if (typeof $ === 'undefined') return;

    var SELECTOR = '#place_order, button[name="woocommerce_checkout_place_order"]';

    function isMps() {
        var m = $('input[name="payment_method"]:checked').val() || '';
        return m.indexOf('mps') === 0;
    }

    function lock() {
        var $btn = $(SELECTOR);
        if (!$btn.length || $btn.prop('disabled')) return;
        // `data-mps-label` preserves the original text so we can restore it verbatim on failure —
        // themes customise this string and we must not overwrite it with a guessed default.
        $btn.data('mps-label', $btn.is('input') ? $btn.val() : $btn.html());
        $btn.prop('disabled', true).attr('aria-busy', 'true').addClass('mps-submitting');
        var wait = (window.mps_guard_i18n && window.mps_guard_i18n.processing) || 'Processing…';
        if ($btn.is('input')) { $btn.val(wait); } else { $btn.html(wait); }
    }

    function unlock() {
        var $btn = $(SELECTOR);
        if (!$btn.length) return;
        $btn.prop('disabled', false).removeAttr('aria-busy').removeClass('mps-submitting');
        var label = $btn.data('mps-label');
        if (typeof label !== 'undefined' && label !== null) {
            if ($btn.is('input')) { $btn.val(label); } else { $btn.html(label); }
        }
    }

    /**
     * Clear the card number and CVV after a decline. Expiry is deliberately left alone: the CVV must
     * never linger and the number is what was asked for, but re-typing an expiry adds friction to a
     * legitimate retry for no security gain.
     */
    function clearCardFields() {
        var $form = $('form.checkout');
        var $number = $form.find('input[name$="_card_number"]');
        $number.val('');
        $form.find('input[name$="_card_cvv"]').val('');
        if ($number.length) {
            $number.first().trigger('change').trigger('focus');
        }
    }

    // Lock on submit. Bound on the form so it runs for whatever markup the theme renders, and only
    // for MPS methods — locking another gateway's button is not ours to do.
    $(document).on('submit', 'form.checkout', function () {
        if (isMps()) lock();
    });
    $(document).on('click', SELECTOR, function () {
        if (isMps() && $('form.checkout').length) lock();
    });

    // WooCommerce fires this after a failed checkout POST — the decline path.
    $(document.body).on('checkout_error', function () {
        unlock();
        if (isMps()) {
            clearCardFields();
            var $err = $('.woocommerce-error, .wc-block-components-notice-banner.is-error').first();
            if ($err.length && $err.offset()) {
                $('html, body').animate({ scrollTop: $err.offset().top - 100 }, 300);
            }
        }
    });

    // Safety nets: any checkout re-render, or the customer returning via the back button (bfcache),
    // must leave a usable button behind.
    $(document.body).on('updated_checkout', unlock);
    $(window).on('pageshow', function (e) {
        if (e.originalEvent && e.originalEvent.persisted) unlock();
    });
})(window.jQuery);
