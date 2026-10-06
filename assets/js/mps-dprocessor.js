/**
 * MPS Gateway — D-Processor card form (v2.9.0; NMI Payment Component since 2026-09-30).
 *
 * The card number, expiry and CVV are NMI iframes drawn by NMI's Payment Component
 * (assets/vendor/nmi-pay, window.mountNmiPayments): what the customer types goes straight to NMI and
 * never touches this store. The component hands back a one-time payment_token plus a masked lookup of
 * the card (BIN, last four, brand); the plugin sends the token to the MPS portal, which holds the
 * merchant's private key and runs the charge.
 *
 * `window.MPSD` is shared by the classic checkout (below) and the Block checkout (mps-d-blocks.js).
 * One component per D gateway, mounted into `#<gateway id>-card`. No onPay is passed, so the
 * component draws NO pay button of its own — the store's Place Order button submits.
 *
 * A token is single-use. After a charge attempt that did not approve, the component is reset
 * (fields cleared, new token session) and the customer re-enters the card.
 */
(function () {
    'use strict';

    if (window.MPSD) return;

    var LOAD_MS = 15000;
    var LOAD_ERROR = 'The card form could not be loaded. Please refresh the page or choose another payment method.';
    var INCOMPLETE = 'Please enter your full card number, expiry date and CVC.';
    var widgets = {};   // gateway id -> { host, w, el, ready: Promise, state: { complete, token, card } }

    function emptyState() { return { complete: false, token: '', card: null }; }

    function destroy(id) {
        var rec = widgets[id];
        delete widgets[id];
        if (rec && rec.w) { try { rec.w.destroy(); } catch (e) { /* already gone */ } }
    }

    /**
     * Mount the component into this gateway's slot. Safe to call again: a live widget in the same slot
     * is reused; a slot WooCommerce re-rendered gets a fresh one. Resolves true when NMI's fields are
     * ready, false when the slot is not on the page; rejects with the customer-facing load error.
     */
    function mount(cfg) {
        var id = cfg.id;
        var host = document.getElementById(id + '-card');
        if (!host) return Promise.resolve(false);
        var cur = widgets[id];
        if (cur && cur.host === host && cur.el && cur.el.isConnected) return cur.ready;
        if (cur) destroy(id);
        if (typeof window.mountNmiPayments !== 'function' || !cfg.tokenization_key) {
            return Promise.reject(new Error(LOAD_ERROR));
        }

        var rec = { host: host, w: null, el: null, state: emptyState() };
        widgets[id] = rec;
        rec.ready = new Promise(function (resolve, reject) {
            var settled = false;
            // NMI draws nothing usable when the key is wrong or NMI is unreachable (the component only
            // shows "Failed to initialize payment"). If the fields never arrive, remove it and tell
            // the customer in our own words.
            var timer = setTimeout(function () {
                if (settled) return;
                settled = true;
                if (widgets[id] === rec) destroy(id);
                reject(new Error(LOAD_ERROR));
            }, LOAD_MS);
            try {
                rec.w = window.mountNmiPayments(host, {
                    tokenizationKey: cfg.tokenization_key,
                    layout: 'multiLine',
                    paymentMethods: ['card'],
                    showDivider: false,
                    onFieldsAvailable: function () {
                        if (settled) return;
                        settled = true;
                        clearTimeout(timer);
                        resolve(true);
                    },
                    // Fires on every change. `complete` + `token` only once all three fields are valid
                    // (and after NMI's lookup, so lookupData.card is there when it succeeded).
                    onChange: function (ev) {
                        var done = !!(ev && ev.complete && ev.token);
                        rec.state.complete = done;
                        rec.state.token = done ? String(ev.token) : '';
                        rec.state.card = done && ev.lookupData && ev.lookupData.card ? ev.lookupData.card : null;
                        if (typeof cfg.onCardChange === 'function') cfg.onCardChange(done);
                    }
                });
                rec.el = rec.w && rec.w.element;
                // A refused key shows up fast as the component's own "Failed to initialize payment".
                (function watch() {
                    if (settled) return;
                    var sr = rec.el && rec.el.shadowRoot;
                    if (sr && /Failed to initialize/i.test(sr.textContent || '')) {
                        settled = true;
                        clearTimeout(timer);
                        if (widgets[id] === rec) destroy(id);
                        return reject(new Error(LOAD_ERROR));
                    }
                    setTimeout(watch, 300);
                })();
            } catch (e) {
                settled = true;
                clearTimeout(timer);
                if (widgets[id] === rec) destroy(id);
                reject(new Error(LOAD_ERROR));
            }
        });
        return rec.ready;
    }

    /** Wait briefly for `complete`: NMI's lookup runs right after the last keystroke. */
    function whenComplete(rec, ms) {
        return new Promise(function (resolve, reject) {
            var t0 = Date.now();
            (function check() {
                if (rec.state.complete && rec.state.token) return resolve(rec);
                if (Date.now() - t0 > ms) return reject(new Error(INCOMPLETE));
                setTimeout(check, 100);
            })();
        });
    }

    /** Resolve with { token, last_four, brand, bin } or reject with a customer-facing Error. */
    function tokenize(cfg) {
        return mount(cfg).then(function (ok) {
            var rec = widgets[cfg.id];
            if (!ok || !rec) throw new Error('Card form is not ready. Please try again.');
            return whenComplete(rec, 3000);
        }).then(function (rec) {
            var card = rec.state.card || {};
            // lookupData.card.number is masked ("411111******1111").
            var digits = String(card.number || '').replace(/\D/g, '');
            var out = {
                token: rec.state.token,
                last_four: digits.length >= 4 ? digits.slice(-4) : '',
                brand: String(card.type || '').toLowerCase(),
                bin: String(card.bin || '').replace(/\D/g, '')
            };
            var err = cardRuleError(cfg, out);
            if (err) throw new Error(err);
            return out;
        });
    }

    /** Token used by a charge attempt (or refused): clear the fields and start a new token session. */
    function reset(id) {
        var rec = widgets[id];
        if (!rec) return;
        rec.state = emptyState();
        try { if (rec.w) rec.w.resetFields(); } catch (e) { destroy(id); }
    }

    /** NMI card.type (lookup) → our allowed_cards keys. Same map as MPS_DProcessor::brand_key(). */
    function brandKey(t) {
        t = String(t || '').toLowerCase();
        if (t === 'mc' || t.indexOf('master') > -1) return 'mastercard';
        if (t.indexOf('amex') > -1 || t.indexOf('american') > -1) return 'amex';
        if (t.indexOf('disc') > -1) return 'discover';
        if (t.indexOf('visa') > -1) return 'visa';
        if (t.indexOf('diner') > -1) return 'diners';
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

    window.MPSD = { mount: mount, tokenize: tokenize, reset: reset, brandKey: brandKey };

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
            if (document.getElementById(id + '-card') && selected() === id) {
                var cfg = cfgFor(id);
                cfg.onCardChange = function (done) { if (done) showError(id, ''); };
                mount(cfg).catch(function (e) { showError(id, e.message); });
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
        // Pay-for-order page has no checkout_place_order event. A failed attempt there reloads the
        // page, which mounts a fresh component (new token) by itself.
        $('form#order_review').on('submit', function (ev) {
            if (!cfgFor(selected()) || field(selected(), 'payment_token').val()) return true;
            ev.preventDefault();
            ev.stopImmediatePropagation();
            beforeSubmit($(this));
            return false;
        });
        // After any failed attempt the next submit reads the token again. If the server says the
        // token went to a charge attempt (marker from MPS_DProcessor), it is spent: reset the fields.
        $(document.body).on('checkout_error', function (ev, html) {
            var spent = String(html || '').indexOf('mps-d-retype') > -1;
            Object.keys(classic.gateways).forEach(function (id) {
                clearToken(id);
                if (spent) reset(id);
            });
        });
        $(document.body).on('updated_checkout payment_method_selected', mountVisible);
        $(document.body).on('change', 'input[name="payment_method"]', mountVisible);
        mountVisible();
    });
})();
