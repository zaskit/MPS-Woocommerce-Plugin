/**
 * MPS Gateway — D-Processor card fields (v2.9.0).
 *
 * The card number, expiry and CVV are NMI Collect.js iframes: what the customer types goes straight
 * to NMI and never touches this store. Collect.js returns a one-time payment_token, which the plugin
 * sends to the MPS portal; the portal holds the merchant's private key and runs the charge.
 *
 * `window.MPSD` is shared by the classic checkout (below) and the Block checkout (mps-d-blocks.js).
 * One Collect.js per page: it is a single global, so every D gateway on the page reuses it and
 * re-points its fields at the gateway being paid with.
 */
(function () {
    'use strict';

    if (window.MPSD) return;

    var loadPromise = null;
    var pending = null;        // { resolve, reject } of the tokenize() in flight
    var mountedFor = null;     // gateway id whose slots Collect.js currently fills
    var valid = {};            // field -> bool, from validationCallback

    var FIELD_CSS = {
        'border': 'none',
        'outline': 'none',
        'box-shadow': 'none',
        'background': 'transparent',
        'font-size': '15px',
        'line-height': '20px',
        'padding': '10px 12px',
        'height': '42px',
        'width': '100%',
        'color': '#1f2937'
    };

    function loadCollect(cfg) {
        if (window.CollectJS) return Promise.resolve();
        if (loadPromise) return loadPromise;
        loadPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = cfg.collect_js_url || 'https://secure.nmi.com/token/Collect.js';
            s.setAttribute('data-tokenization-key', cfg.tokenization_key);
            s.async = true;
            s.onload = function () {
                // Collect.js defines its global synchronously; give it one tick to finish booting.
                var tries = 0;
                (function wait() {
                    if (window.CollectJS) return resolve();
                    if (++tries > 50) return reject(new Error('Card form did not load.'));
                    setTimeout(wait, 100);
                })();
            };
            s.onerror = function () { loadPromise = null; reject(new Error('Card form could not be loaded. Please check your connection and reload the page.')); };
            document.head.appendChild(s);
        });
        return loadPromise;
    }

    function finish(fn, arg) {
        var p = pending;
        pending = null;
        if (p) p[fn](arg);
    }

    /** Point Collect.js at this gateway's three slots. Safe to call again after a re-render. */
    function mount(cfg) {
        return loadCollect(cfg).then(function () {
            var id = cfg.id;
            var num = document.getElementById(id + '-ccnumber');
            if (!num) return false;
            // Already filled (iframe present) — nothing to do.
            if (mountedFor === id && num.querySelector('iframe')) return true;
            valid = {};
            window.CollectJS.configure({
                variant: 'inline',
                styleSniffer: false,
                paymentType: 'cc',
                customCss: FIELD_CSS,
                invalidCss: { 'color': '#b91c1c' },
                validCss: { 'color': '#1f2937' },
                placeholderCss: { 'color': '#9ca3af' },
                focusCss: { 'color': '#111827' },
                fields: {
                    ccnumber: { selector: '#' + id + '-ccnumber', title: 'Card Number', placeholder: '0000 0000 0000 0000' },
                    ccexp:    { selector: '#' + id + '-ccexp',    title: 'Expiry',      placeholder: 'MM / YY' },
                    cvv:      { selector: '#' + id + '-cvv',      title: 'CVC',         placeholder: '•••', display: 'required' }
                },
                validationCallback: function (field, status) { valid[field] = !!status; },
                timeoutDuration: 15000,
                timeoutCallback: function () {
                    finish('reject', new Error('Please check your card number, expiry date and CVC.'));
                },
                callback: function (response) {
                    if (response && response.token) finish('resolve', response);
                    else finish('reject', new Error('Your card could not be read. Please check the details and try again.'));
                }
            });
            mountedFor = id;
            return fieldsShown(id);
        });
    }

    /**
     * Collect.js draws nothing when the tokenization key is wrong or NMI is unreachable, and says so
     * only in the console. Wait for its iframes; if they never come, tell the customer instead of
     * leaving three empty boxes.
     */
    function fieldsShown(id) {
        return new Promise(function (resolve, reject) {
            var t0 = Date.now();
            (function check() {
                var slot = document.getElementById(id + '-ccnumber');
                if (slot && slot.querySelector('iframe')) return resolve(true);
                if (!slot) return resolve(false);
                if (Date.now() - t0 > 10000) {
                    mountedFor = null;
                    return reject(new Error('The card form could not be loaded. Please refresh the page or choose another payment method.'));
                }
                setTimeout(check, 200);
            })();
        });
    }

    /** Resolve with { token, last_four, brand, bin } or reject with a customer-facing Error. */
    function tokenize(cfg) {
        return mount(cfg).then(function (ok) {
            if (!ok) throw new Error('Card form is not ready. Please try again.');
            if (valid.ccnumber === false) throw new Error('Please check your card number.');
            if (valid.ccexp === false) throw new Error('Please check the expiry date.');
            if (valid.cvv === false) throw new Error('Please check the CVC.');
            if (pending) finish('reject', new Error('Superseded.'));
            return new Promise(function (resolve, reject) {
                pending = { resolve: resolve, reject: reject };
                try { window.CollectJS.startPaymentRequest(); }
                catch (e) { finish('reject', new Error('Your card could not be read. Please try again.')); }
            });
        }).then(function (r) {
            var card = r.card || {};
            var digits = String(card.number || '').replace(/\D/g, '');
            var out = { token: r.token, last_four: digits.slice(-4), brand: String(card.type || '').toLowerCase(), bin: String(card.bin || '').replace(/\D/g, '') };
            var err = cardRuleError(cfg, out);
            if (err) throw new Error(err);
            return out;
        });
    }

    function brandKey(t) {
        t = String(t || '').toLowerCase();
        if (t === 'mc' || t.indexOf('master') > -1) return 'mastercard';
        if (t.indexOf('amex') > -1 || t.indexOf('american') > -1) return 'amex';
        if (t.indexOf('disc') > -1) return 'discover';
        if (t.indexOf('visa') > -1) return 'visa';
        return t;
    }

    /** Same checks the server repeats: allowed schemes and blocked BINs. */
    function cardRuleError(cfg, card) {
        var allowed = cfg.allowed_cards || [];
        var b = brandKey(card.brand);
        if (b && allowed.length && allowed.indexOf(b) === -1) {
            return 'This store does not accept ' + b.charAt(0).toUpperCase() + b.slice(1) + ' cards. Please use a different card.';
        }
        var bins = cfg.blocked_bins || [];
        for (var i = 0; i < bins.length; i++) {
            var rule = String((bins[i] && bins[i].bin) || '').replace(/\D/g, '');
            if (rule && card.bin.length >= rule.length && card.bin.indexOf(rule) === 0) {
                return (bins[i].message || cfg.blocked_message || 'This card cannot be used at this store. Please try a different card.');
            }
        }
        return '';
    }

    window.MPSD = { mount: mount, tokenize: tokenize, brandKey: brandKey };

    // ─── Classic checkout + pay-for-order page ────────────────────────────────────────────────
    var $ = window.jQuery;
    var classic = window.mps_d_classic || { gateways: {} };
    if (!$ || !classic.gateways) return;

    function selected() {
        return $('input[name="payment_method"]:checked').val() || '';
    }

    function cfgFor(id) { return classic.gateways[id] || null; }

    function mountVisible() {
        Object.keys(classic.gateways).forEach(function (id) {
            if (document.getElementById(id + '-ccnumber') && selected() === id) {
                mount(cfgFor(id)).catch(function (e) { showError(id, e.message); });
            }
        });
    }

    function field(id, name) { return $('input[name="' + id + '_' + name + '"]'); }

    function showError(id, msg) {
        var $e = $('#' + id + '-form .mps-d-error');
        if (!msg) { $e.hide().text(''); return; }
        $e.text(msg).show();
    }

    function clearToken(id) {
        ['payment_token', 'card_last_four', 'card_brand', 'card_bin'].forEach(function (n) { field(id, n).val(''); });
    }

    // Returning false stops WooCommerce's submit; we submit again once the token is in.
    function beforeSubmit($form) {
        var id = selected();
        var cfg = cfgFor(id);
        if (!cfg) return true;
        if (field(id, 'payment_token').val()) return true;   // second pass: token present

        showError(id, '');
        tokenize(cfg).then(function (card) {
            field(id, 'payment_token').val(card.token);
            field(id, 'card_last_four').val(card.last_four);
            field(id, 'card_brand').val(card.brand);
            field(id, 'card_bin').val(card.bin);
            $form.trigger('submit');
        }).catch(function (e) {
            showError(id, e.message);
            // Releases the Pay button (mps-checkout-guard.js listens for this).
            $(document.body).trigger('checkout_error', [e.message]);
        });
        return false;
    }

    $(function () {
        $('form.checkout').on('checkout_place_order', function () { return beforeSubmit($(this)); });
        // Pay-for-order page has no checkout_place_order event.
        $('form#order_review').on('submit', function (ev) {
            if (!cfgFor(selected()) || field(selected(), 'payment_token').val()) return true;
            ev.preventDefault();
            ev.stopImmediatePropagation();
            beforeSubmit($(this));
            return false;
        });
        // A token is single-use: after any failed attempt the next submit must make a new one.
        $(document.body).on('checkout_error', function () { Object.keys(classic.gateways).forEach(clearToken); });
        $(document.body).on('updated_checkout payment_method_selected', mountVisible);
        $(document.body).on('change', 'input[name="payment_method"]', mountVisible);
        mountVisible();
    });
})();
