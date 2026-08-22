(function(){
    'use strict';

    // ── Boot guard (v2.5.8) ────────────────────────────────────────────────────────────────────
    // Dependencies are resolved when we are ready to register, NOT at parse time. This file
    // declares wc-blocks-registry / wp-element / wp-html-entities as script dependencies and is
    // printed immediately after its own `…-js-extra` inline block, but a JS optimizer that
    // combines, defers or delays scripts can break either contract: run us before the registry
    // exists, or separate us from the inline globals we read. Dereferencing them at the top of
    // this IIFE threw a single TypeError and registered nothing — no tile at checkout, and no
    // error anywhere the merchant could see (Aeterna Peptides, 2026-08-06). We now wait for both,
    // re-scan for late-arriving gateway data, and say so loudly in the console if we give up.
    var registerPaymentMethod = null;
    var createElement = null;
    var decodeEntities = null;

    var RETRY_MS = 100;
    var MAX_ATTEMPTS = 150;   // ≈15s — long enough for a delayed-until-interaction optimizer

    // Shared across every copy of this file on the page: one script handle is registered per MPS
    // gateway, all pointing at this same URL, so the IIFE can run several times. One scan loop
    // serves them all — it re-reads every `mps_blocks_data_mps_*` global on each tick, so a copy
    // that loads later still gets picked up.
    var state = window.__mpsBlocks = window.__mpsBlocks || { registered: {}, attempts: 0, timer: null, warned: false };

    function missingDeps(){
        var missing = [];
        if(!(window.wc && window.wc.wcBlocksRegistry && window.wc.wcBlocksRegistry.registerPaymentMethod)) missing.push('wc.wcBlocksRegistry');
        if(!(window.wp && window.wp.element && window.wp.element.createElement && window.wp.element.useRef)) missing.push('wp.element');
        if(!(window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities)) missing.push('wp.htmlEntities');
        return missing;
    }

    function gatewayKeys(){
        return Object.keys(window).filter(function(k){ return k.indexOf('mps_blocks_data_mps_') === 0; });
    }

    function registeredCount(){
        return Object.keys(state.registered).length;
    }

    // Card fields (always shown)
    var cardFields = [
        { name: 'card_name', label: 'Cardholder Name', placeholder: 'Name on card', type: 'text', autocomplete: 'cc-name' },
        { name: 'card_number', label: 'Card Number', placeholder: '0000 0000 0000 0000', type: 'text', autocomplete: 'cc-number', maxLength: 23, inputMode: 'numeric' },
    ];

    var cardRow = [
        { name: 'card_expiry', label: 'Expiry', placeholder: 'MM / YY', maxLength: 7, inputMode: 'numeric', autocomplete: 'cc-exp' },
        { name: 'card_cvv', label: 'CVC', placeholder: '\u2022\u2022\u2022', maxLength: 4, inputMode: 'numeric', autocomplete: 'cc-csc' },
    ];

    // Billing fields removed — uses WC checkout billing details

    function formatCardNumber(val) {
        var d = val.replace(/\D/g,'').substring(0,16);
        var parts = d.match(/.{1,4}/g);
        return parts ? parts.join(' ') : d;
    }

    function formatExpiry(val, isDeleting) {
        var d = val.replace(/\D/g,'').substring(0,4);
        // Show the separator the moment the MONTH is complete, so it is obvious the next digits are
        // the year and the customer does not type their own slash (client 2026-07-22).
        if(d.length > 2) return d.substring(0,2) + ' / ' + d.substring(2);
        // ...but not while deleting, or backspace would re-add it and the field could never be cleared.
        if(d.length === 2 && !isDeleting) return d + ' / ';
        return d;
    }

    function numericOnly(val, max) {
        return val.replace(/\D/g,'').substring(0, max);
    }

    /**
     * Card number sanity: scheme, length, Luhn. Mirrors MPS_Card_Validator in PHP and the copy in
     * mps-card-formatting.js — change one, change all three. Returns a message or null.
     *
     * Block checkout never calls validate_fields(), so on a Blocks store this and the Store API
     * guard are the whole of the card validation.
     */
    var MPS_CARD_MESSAGE = "That card number doesn't look right. Please check the digits and try again.";

    var SCHEME_LABELS = {
        visa:'Visa', mastercard:'Mastercard', amex:'American Express',
        discover:'Discover', diners:'Diners Club', jcb:'JCB', unionpay:'UnionPay'
    };
    var SCHEME_LENGTHS = {
        visa:[13,16,19], mastercard:[16], amex:[15], discover:[16,19],
        diners:[14,16,19], jcb:[16,17,18,19], unionpay:[16,17,18,19]
    };

    function cardScheme(pan){
        if(pan.length < 4) return null;
        var p2 = parseInt(pan.slice(0,2), 10), p3 = parseInt(pan.slice(0,3), 10),
            p4 = parseInt(pan.slice(0,4), 10), p6 = parseInt(pan.slice(0,6), 10);
        if(pan.charAt(0) === '4') return 'visa';
        if((p2 >= 51 && p2 <= 55) || (p4 >= 2221 && p4 <= 2720)) return 'mastercard';
        if(p2 === 34 || p2 === 37) return 'amex';
        if(p4 === 6011 || p2 === 65 || (p3 >= 644 && p3 <= 649) || (p6 >= 622126 && p6 <= 622925)) return 'discover';
        if((p3 >= 300 && p3 <= 305) || p2 === 36 || p2 === 38) return 'diners';
        if(p4 >= 3528 && p4 <= 3589) return 'jcb';
        if(p2 === 62) return 'unionpay';
        return null;
    }

    /** Scheme allow-list from the portal. Unknown ranges pass — see MPS_Card_Validator::brand_error(). */
    function brandError(value, allowed){
        if(!allowed || !allowed.length) return null;
        var scheme = cardScheme(String(value || '').replace(/\D/g,''));
        if(!scheme || allowed.indexOf(scheme) !== -1) return null;
        var names = allowed.map(function(a){ return SCHEME_LABELS[a] || a; });
        var list = names.length > 1
            ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1]
            : names[0];
        return 'Only ' + list + ' are accepted here. Please use a different card.';
    }

    function luhn(pan){
        var sum = 0, dbl = false, d;
        for(var i = pan.length - 1; i >= 0; i--){
            d = parseInt(pan.charAt(i), 10);
            if(dbl){ d *= 2; if(d > 9){ d -= 9; } }
            sum += d;
            dbl = !dbl;
        }
        return sum % 10 === 0;
    }

    function cardNumberError(value){
        var pan = String(value || '').replace(/\D/g,'');
        if(!pan) return null;
        if(pan.length < 12 || pan.length > 19) return MPS_CARD_MESSAGE;
        if(/^(\d)\1+$/.test(pan)) return MPS_CARD_MESSAGE;
        var scheme = cardScheme(pan);
        if(scheme && SCHEME_LENGTHS[scheme].indexOf(pan.length) === -1) return MPS_CARD_MESSAGE;
        if(!luhn(pan)) return MPS_CARD_MESSAGE;
        return null;
    }

    function registerGateway(varName){
        var dataVar = window[varName];
        if(!dataVar || !dataVar.id) return;

        var gatewayId = dataVar.id;
        if(state.registered[gatewayId]) return;   // never register the same method twice

        var is3ds = !!dataVar.supports_3ds;
        var hasFields = !!dataVar.has_fields && dataVar.has_fields !== '0' && dataVar.has_fields !== '';

        var Content = function(props){
            var eventRegistration = props.eventRegistration;
            var emitResponse = props.emitResponse;
            // Support both old and new WC Blocks event names
            var onPaymentSetup = eventRegistration.onPaymentSetup || eventRegistration.onPaymentProcessing;

            // charge_ack starts set: the acknowledgment is mandatory and renders ticked, so the
            // consent is already given by the time the customer can submit.
            var stateRef = window.wp.element.useRef(dataVar.ack_text ? {charge_ack:'1'} : {});
            var ackBlockedState = window.wp.element.useState(false);
            var ackBlocked = ackBlockedState[0], setAckBlocked = ackBlockedState[1];
            // Blocked BIN typed into the card field. Immediate feedback only — the server checks
            // the same list again before processing (mps_block_blocked_bins), so a broken script
            // costs the instant message, not the protection.
            var binBlockState = window.wp.element.useState(null);
            var binBlocked = binBlockState[0], setBinBlocked = binBlockState[1];

            var declineState = window.wp.element.useState(null);
            var decline = declineState[0], setDecline = declineState[1];
            var declineMsg = decline ? decline.message : '';
            var declineFinal = decline ? !!decline.final : false;

            // Fetch the decline the server stored for this order (see remember_for_order) and show it
            // under the card fields. Keyed by order id so it does not depend on the WC session cookie.
            var getStoreOrderId = function(){
                try {
                    var s = window.wp.data.select('wc/store/checkout');
                    return s && s.getOrderId ? s.getOrderId() : 0;
                } catch(e){ return 0; }
            };
            var fetchDecline = function(orderId){
                if(!dataVar.rest_decline_url) return;
                var url = dataVar.rest_decline_url;
                if(orderId){ url += (url.indexOf('?') > -1 ? '&' : '?') + 'order=' + encodeURIComponent(orderId); }
                fetch(url, {credentials:'same-origin', headers:{'Accept':'application/json'}})
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(j){
                        if(j && j.decline && j.decline.message){
                            setDecline({ message: j.decline.message, final: !!j.decline.final });
                        }
                    })
                    .catch(function(){});
            };

            // Trigger 1 — the WC Blocks failure event, when the build exposes it.
            window.wp.element.useEffect(function(){
                var onFail = eventRegistration.onCheckoutFail || eventRegistration.onCheckoutAfterProcessingWithError;
                if(!onFail) return;
                var unsub = onFail(function(payload){
                    var oid = (payload && (payload.orderId || payload.order_id)) || getStoreOrderId();
                    fetchDecline(oid);
                    return true;
                });
                return unsub;
            },[eventRegistration]);

            // Trigger 2 — watch the checkout data store directly. When an attempt finishes and checkout
            // drops back to idle (i.e. it did NOT complete), look up any decline for the order. This is
            // independent of the payment-method event API, so it works even if onCheckoutFail never fires.
            window.wp.element.useEffect(function(){
                if(!(window.wp.data && window.wp.data.subscribe)) return;
                var prev = '';
                var unsub = window.wp.data.subscribe(function(){
                    try {
                        var s = window.wp.data.select('wc/store/checkout');
                        if(!s || !s.getCheckoutStatus) return;
                        var status = s.getCheckoutStatus();
                        if(status === prev) return;
                        var wasRunning = (prev === 'processing' || prev === 'after_processing' || prev === 'before_processing');
                        if(status === 'idle' && wasRunning){
                            fetchDecline(s.getOrderId ? s.getOrderId() : 0);
                        }
                        prev = status;
                    } catch(e){}
                });
                return unsub;
            },[]);

            window.wp.element.useEffect(function(){
                if(!onPaymentSetup) return;
                var unsub = onPaymentSetup(function(){
                    var paymentData = {};
                    var s = stateRef.current;
                    for(var key in s){
                        paymentData[key] = s[key] || '';
                    }

                    // Refuse to submit a card the processor will never approve. Read off the ref,
                    // not component state, so this is the value in the field right now rather than
                    // whatever it was when this callback was subscribed.
                    var blockedHit = matchBlockedBin(s.card_number);
                    if(blockedHit){
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: blockedHit.message || 'This card cannot be used at this store. Please try a different card.'
                        };
                    }

                    var wrongBrand = brandError(s.card_number, dataVar.allowed_cards);
                    if(wrongBrand){
                        setBinBlocked(wrongBrand);
                        return { type: emitResponse.responseTypes.ERROR, message: wrongBrand };
                    }

                    // Scheme/length/Luhn. A number that fails these cannot be approved by anyone,
                    // and sending it costs a Format Error on the MID that reads as a decline.
                    var invalid = cardNumberError(s.card_number);
                    if(invalid){
                        setBinBlocked(invalid);
                        return { type: emitResponse.responseTypes.ERROR, message: invalid };
                    }

                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: { paymentMethodData: paymentData }
                    };
                });
                return unsub;
            },[onPaymentSetup, emitResponse]);

            function handleChange(fieldName, formatter){
                return function(e){
                    var deleting = e.nativeEvent && /^delete/.test(e.nativeEvent.inputType || '');
                    var val = formatter ? formatter(e.target.value, deleting) : e.target.value;
                    e.target.value = val;
                    stateRef.current[fieldName] = val;
                };
            }

            // Longest-first, as the portal sends them, so a specific 8-digit rule wins over a
            // broad 6-digit one covering the same range.
            var matchBlockedBin = function(value){
                var rules = dataVar.blocked_bins || [];
                var digits = String(value || '').replace(/\D/g, '');
                for(var i = 0; i < rules.length; i++){
                    var bin = String(rules[i].bin || '').replace(/\D/g, '');
                    if(bin && digits.length >= bin.length && digits.indexOf(bin) === 0){ return rules[i]; }
                }
                return null;
            };

            var elements = [];

            // Description
            if(dataVar.description){
                elements.push(createElement('p', {key:'desc', style:{marginBottom:'12px',fontSize:'14px',color:'#6b7280'}}, decodeEntities(dataVar.description)));
            }

            // Card fields (skip for hosted gateways)
            if(hasFields) {
            // Full-width fields (name, card number)
            cardFields.forEach(function(f, i){
                var fmt = null;
                if(f.name === 'card_number') fmt = formatCardNumber;
                var onFieldChange = handleChange(f.name, fmt);
                if(f.name === 'card_number'){
                    var inner = onFieldChange;
                    onFieldChange = function(e){
                        var v = e && e.target ? e.target.value : '';
                        var hit = matchBlockedBin(v);
                        // Both of these are known from the first digits, so they show while typing.
                        setBinBlocked(hit
                            ? (hit.message || 'This card cannot be used at this store. Please try a different card.')
                            : brandError(v, dataVar.allowed_cards));
                        return inner(e);
                    };
                }
                var inputProps = {
                    type: f.type || 'text',
                    placeholder: f.placeholder,
                    maxLength: f.maxLength || undefined,
                    inputMode: f.inputMode || undefined,
                    autoComplete: f.autocomplete || undefined,
                    onChange: onFieldChange
                };
                if(f.name === 'card_number'){
                    // Validity is judged only when they leave the field — every partial number
                    // fails Luhn, so checking on each keystroke would flag a customer who is still
                    // typing. A blocked BIN is different and already showing by now.
                    inputProps.onBlur = function(e){
                        var v = e && e.target ? e.target.value : '';
                        if(matchBlockedBin(v)) return;
                        setBinBlocked(brandError(v, dataVar.allowed_cards) || cardNumberError(v));
                    };
                }
                elements.push(
                    createElement('div', {key:'f'+i, className:'mps-field'},
                        createElement('label', null, f.label),
                        createElement('input', inputProps)
                    )
                );
                if(f.name === 'card_number' && binBlocked){
                    elements.push(createElement('div', {
                        key: 'binblock', className: 'mps-bin-blocked', role: 'alert'
                    }, binBlocked));
                }
            });

            // Row fields (expiry + CVV)
            var rowChildren = cardRow.map(function(f, i){
                var fmt = null;
                if(f.name === 'card_expiry') fmt = formatExpiry;
                else if(f.name === 'card_cvv') fmt = function(v){ return numericOnly(v, f.maxLength || 4); };
                return createElement('div', {key:'r'+i, className:'mps-field'},
                    createElement('label', null, f.label),
                    createElement('input', {
                        type: 'text',
                        placeholder: f.placeholder,
                        maxLength: f.maxLength || undefined,
                        inputMode: f.inputMode || 'numeric',
                        autoComplete: f.autocomplete || undefined,
                        onChange: handleChange(f.name, fmt)
                    })
                );
            });
            elements.push(createElement('div', {key:'row', className:'mps-row'}, rowChildren));

            // Billing address fields removed — uses WC checkout billing details
            } // end hasFields

            // Decline message from the previous attempt, directly under the card fields
            // (client 2026-07-22). Block checkout does not reload, so we capture the failure via
            // onCheckoutFail and re-render here rather than relying on the notice banner.
            if(declineMsg){
                elements.push(createElement('div', {
                    key:'decline',
                    className: 'mps-decline-notice ' + (declineFinal ? 'mps-decline-final' : 'mps-decline-retry'),
                    role:'alert'
                }, declineMsg));
            }

            // Mandatory Cardholder Charge Acknowledgment (client 2026-07-29). Ticked on arrival and
            // held ticked: `checked` is a fixed prop, so React re-renders it ticked however the
            // customer clicks it, and we explain why instead of silently ignoring the click.
            if(dataVar.ack_text){
                var ackChildren = [];
                // Disclosure first, so it is read before the box that authorizes the charge.
                // Same markup classic checkout renders — see MPS_Monitor_Ack::disclosure_html().
                if(dataVar.disclosure){
                    ackChildren.push(createElement('div', {
                        key:'disclosure',
                        dangerouslySetInnerHTML:{ __html: dataVar.disclosure }
                    }));
                }
                ackChildren.push(
                    createElement('label', {key:'lbl', className:'mps-ack-label'},
                        createElement('input', {
                            type:'checkbox',
                            className:'mps-ack-checkbox',
                            checked: true,
                            onChange: function(){
                                stateRef.current.charge_ack = '1';
                                setAckBlocked(true);
                            }
                        }),
                        createElement('span', {
                            className:'mps-ack-text',
                            dangerouslySetInnerHTML:{ __html: dataVar.ack_text }
                        })
                    )
                );
                if(ackBlocked){
                    ackChildren.push(createElement('div', {
                        key:'req', className:'mps-ack-required', role:'alert'
                    }, 'This acknowledgment is required to complete your payment.'));
                }
                elements.push(createElement('div', {key:'ack', className:'mps-ack-field'}, ackChildren));
            }

            // Secure badge
            elements.push(
                createElement('div', {key:'badge', className:'mps-secure-badge'},
                    createElement('span', null, '\uD83D\uDD12 Secured with 256-bit encryption')
                )
            );

            return createElement('div', { className: 'mps-card-form' }, elements);
        };

        // Plain inline flow, NOT inline-flex: as a flex container the title becomes a shrinkable
        // flex item, so on narrow screens it is squeezed into a column and wraps mid-word while
        // any icons keep their full width (client report 2026-07-26). Inline text wraps normally.
        var Label = function(){
            var icons = (dataVar.icons || []).map(function(ic, i){
                return createElement('img', {key:i, src:ic.src, alt:ic.alt, style:{maxHeight:'24px',marginLeft:'6px',verticalAlign:'-6px',display:'inline-block'}});
            });
            return createElement('span', {style:{display:'inline'}},
                decodeEntities(dataVar.title || 'Pay by Card'),
                icons
            );
        };

        // One bad gateway payload must not stop the others from registering.
        try {
            registerPaymentMethod({
                name: gatewayId,
                label: createElement(Label),
                content: createElement(Content),
                edit: createElement(Content),
                canMakePayment: function(){ return true; },
                ariaLabel: dataVar.title || 'Pay by Card',
                supports: { features: dataVar.supports || ['products'] }
            });
            state.registered[gatewayId] = true;
        } catch(e){
            if(window.console && console.error){
                console.error('[MPS Gateway] Could not register the Block payment method "' + gatewayId + '":', e);
            }
        }
    }

    function stop(){
        if(state.timer){ clearInterval(state.timer); state.timer = null; }
    }

    function warnIfNothingRegistered(){
        if(state.warned || registeredCount() > 0) return;
        state.warned = true;
        if(!(window.console && console.warn)) return;
        console.warn(
            '[MPS Gateway] The card payment method was not registered on this Block checkout after ' +
            Math.round((MAX_ATTEMPTS * RETRY_MS) / 1000) + 's.\n' +
            '  Missing dependencies: ' + (missingDeps().join(', ') || 'none') + '\n' +
            '  Gateway data globals found: ' + gatewayKeys().length + ' (' + (gatewayKeys().join(', ') || 'none') + ')\n' +
            '  This is almost always a JS optimizer (LiteSpeed, WP Rocket, Autoptimize, Perfmatters, ' +
            'SG Optimizer, Cloudflare Rocket Loader) combining, deferring or delaying mps-blocks.js, ' +
            'or separating it from the inline data emitted directly above it. Exclude mps-blocks.js ' +
            'AND its inline script from all JS optimization, then reload this page.'
        );
    }

    function attempt(){
        state.attempts++;

        if(missingDeps().length === 0){
            registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
            createElement = window.wp.element.createElement;
            decodeEntities = window.wp.htmlEntities.decodeEntities;
            gatewayKeys().forEach(registerGateway);
        }

        // Settled: at least one method is live and the page has finished loading, so no further
        // data globals are coming.
        if(registeredCount() > 0 && document.readyState === 'complete'){
            stop();
            return;
        }

        if(state.attempts >= MAX_ATTEMPTS){
            stop();
            warnIfNothingRegistered();
        }
    }

    // A support handle worth its two lines: turns the three-command console diagnostic we ask
    // merchants to run into one call.
    window.mpsBlocksStatus = function(){
        return {
            version: '2.5.8',
            missingDependencies: missingDeps(),
            gatewayDataGlobals: gatewayKeys(),
            registered: Object.keys(state.registered),
            attempts: state.attempts,
            stillWatching: !!state.timer
        };
    };

    // Try immediately — on a healthy store everything is already there and this registers on the
    // first pass, exactly as before. Only a broken load order reaches the retry loop.
    attempt();
    if(!state.timer && registeredCount() === 0 && state.attempts < MAX_ATTEMPTS){
        state.timer = setInterval(attempt, RETRY_MS);
    }
})();
