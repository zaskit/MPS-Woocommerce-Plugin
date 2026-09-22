/**
 * MPS Gateway — D-Processor on the Block checkout (v2.9.0).
 *
 * Kept apart from mps-blocks.js on purpose: that file renders our own card inputs, this one renders
 * three empty slots that NMI Collect.js fills with its own iframes (window.MPSD, mps-dprocessor.js).
 * Its data globals use a different prefix (mps_dblocks_data_) so mps-blocks.js never picks a D
 * gateway up and draws plain card inputs for it.
 */
(function () {
    'use strict';

    var state = window.__mpsDBlocks = window.__mpsDBlocks || { registered: {}, attempts: 0, timer: null };

    function ready() {
        return window.wc && window.wc.wcBlocksRegistry && window.wc.wcBlocksRegistry.registerPaymentMethod
            && window.wp && window.wp.element && window.wp.htmlEntities && window.MPSD;
    }

    function keys() {
        return Object.keys(window).filter(function (k) { return k.indexOf('mps_dblocks_data_') === 0; });
    }

    function register(varName) {
        var cfg = window[varName];
        if (!cfg || !cfg.id || state.registered[cfg.id]) return;
        var el = window.wp.element.createElement;
        var useEffect = window.wp.element.useEffect;
        var useState = window.wp.element.useState;
        var useRef = window.wp.element.useRef;
        var decode = window.wp.htmlEntities.decodeEntities;

        var ticketLimitMessage = function (billing) {
            var min = cfg.ticket_min, max = cfg.ticket_max;
            var ct = billing && billing.cartTotal;
            if (!ct || ct.value === undefined) return '';
            var minor = billing.currency && billing.currency.minorUnit !== undefined ? billing.currency.minorUnit : 2;
            var total = Number(ct.value) / Math.pow(10, minor);
            if (min !== null && min !== undefined && total > 0 && total < Number(min)) return cfg.ticket_min_message || '';
            if (max !== null && max !== undefined && total > Number(max)) return cfg.ticket_max_message || '';
            return '';
        };

        var Content = function (props) {
            var reg = props.eventRegistration;
            var emit = props.emitResponse;
            var onPaymentSetup = reg.onPaymentSetup || reg.onPaymentProcessing;
            var errState = useState('');
            var err = errState[0], setErr = errState[1];
            var limit = ticketLimitMessage(props.billing);
            var limitRef = useRef(limit);
            limitRef.current = limit;

            useEffect(function () {
                if (limit) return;
                window.MPSD.mount(cfg).catch(function (e) { setErr(e.message); });
            }, [limit]);

            useEffect(function () {
                if (!onPaymentSetup) return;
                return onPaymentSetup(function () {
                    if (limitRef.current) return { type: emit.responseTypes.ERROR, message: limitRef.current };
                    setErr('');
                    return window.MPSD.tokenize(cfg).then(function (card) {
                        return {
                            type: emit.responseTypes.SUCCESS,
                            meta: { paymentMethodData: {
                                payment_token: card.token, card_last_four: card.last_four,
                                card_brand: card.brand, card_bin: card.bin, charge_ack: '1'
                            } }
                        };
                    }).catch(function (e) {
                        setErr(e.message);
                        return { type: emit.responseTypes.ERROR, message: e.message };
                    });
                });
            }, [onPaymentSetup, emit]);

            var desc = cfg.description ? el('div', { className: 'mps-desc', dangerouslySetInnerHTML: { __html: cfg.description } }) : null;
            if (limit) {
                return el('div', null, desc, el('div', { className: 'mps-ticket-limit', role: 'alert',
                    style: { padding: '10px 12px', borderRadius: '6px', background: '#fef3c7', color: '#92400e', fontSize: '14px' } }, limit));
            }
            var slot = function (name, label) {
                return el('div', { className: 'mps-field' },
                    el('label', { htmlFor: cfg.id + '-' + name }, label),
                    el('div', { className: 'mps-d-slot', id: cfg.id + '-' + name }));
            };
            return el('div', { className: 'mps-card-form mps-d-form', id: cfg.id + '-form' },
                desc,
                slot('ccnumber', 'Card Number'),
                el('div', { className: 'mps-row' }, slot('ccexp', 'Expiry'), slot('cvv', 'CVC')),
                err ? el('div', { className: 'mps-d-error mps-bin-blocked', role: 'alert' }, err) : null,
                cfg.disclosure ? el('div', { dangerouslySetInnerHTML: { __html: cfg.disclosure } }) : null,
                cfg.ack_text ? el('label', { className: 'mps-ack-label' },
                    el('input', { type: 'checkbox', className: 'mps-ack-checkbox', checked: true, readOnly: true, onClick: function (e) { e.preventDefault(); } }),
                    el('span', { className: 'mps-ack-text' }, cfg.ack_text)) : null,
                el('div', { className: 'mps-secure-badge' }, el('span', null, 'Secured with 256-bit encryption'))
            );
        };

        try {
            window.wc.wcBlocksRegistry.registerPaymentMethod({
                name: cfg.id,
                label: el('span', null, decode(cfg.title || 'Pay with Card')),
                content: el(Content),
                edit: el(Content),
                canMakePayment: function () { return !!cfg.tokenization_key; },
                ariaLabel: cfg.title || 'Pay with Card',
                supports: { features: cfg.supports || ['products'] }
            });
            state.registered[cfg.id] = true;
        } catch (e) {
            if (window.console) console.error('[MPS Gateway] Could not register D-Processor on Block checkout:', e);
        }
    }

    function attempt() {
        state.attempts++;
        if (ready()) keys().forEach(register);
        if ((Object.keys(state.registered).length && document.readyState === 'complete') || state.attempts > 150) {
            clearInterval(state.timer); state.timer = null;
        }
    }
    attempt();
    if (!state.timer) state.timer = setInterval(attempt, 100);
})();
