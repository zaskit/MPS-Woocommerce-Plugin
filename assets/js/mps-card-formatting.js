(function(){
    'use strict';

    function formatCardNumber(input){
        input.addEventListener('input',function(){
            var v = this.value.replace(/\D/g,'').substring(0,16);
            var parts = v.match(/.{1,4}/g);
            this.value = parts ? parts.join(' ') : v;
        });
    }

    function formatExpiry(input){
        input.addEventListener('input',function(e){
            var v = this.value.replace(/\D/g,'').substring(0,4);
            var deleting = /^delete/.test((e && e.inputType) || '');
            if(v.length>2){
                this.value = v.substring(0,2)+' / '+v.substring(2);
            } else if(v.length===2 && !deleting){
                // Separator appears as soon as the month is complete, so the customer can see the
                // year comes next and does not add a slash of their own (client 2026-07-22).
                this.value = v+' / ';
            } else {
                // While deleting, leave the bare digits — otherwise backspace would re-add the
                // separator and the field could never be cleared.
                this.value = v;
            }
        });
    }

    function limitNumeric(input,max){
        input.addEventListener('input',function(){
            this.value = this.value.replace(/\D/g,'').substring(0,max);
        });
    }

    /*
     * isMastercard()/setupMastercardCheck() lived here until v2.5.9. A Mastercard-only gateway is
     * just one shape of the portal's allowed_cards list, and the general check in setupBinBlock()
     * covers it — in the same message line as the other card problems, instead of a second notice
     * of its own saying nearly the same thing.
     */

    // The charge acknowledgment is mandatory (client 2026-07-29). It renders ticked; if the customer
    // clears it we put it straight back and say why, rather than letting them reach the pay button
    // with it clear and be stopped by an opaque browser validation bubble.
    function setupAckLock(box){
        if(box.getAttribute('data-mps-ack-locked') === '1') return;
        box.setAttribute('data-mps-ack-locked','1');
        box.checked = true;

        var field  = box.closest ? box.closest('.mps-ack-field') : null;
        var notice = field ? field.querySelector('.mps-ack-required') : null;

        box.addEventListener('change', function(){
            if(this.checked){
                if(notice) notice.style.display = 'none';
                return;
            }
            this.checked = true;
            if(notice) notice.style.display = 'block';
        });
    }


    /**
     * Card number sanity: scheme, length, Luhn. Mirrors MPS_Card_Validator in PHP — change one,
     * change both. Returns a message, or null when the number could be a real card.
     *
     * Deliberately one generic message: the fix is the same whichever test failed, and naming the
     * failing test would tell a card tester which digit to change.
     */
    var MPS_CARD_MESSAGE = "That card number doesn't look right. Please check the digits and try again.";

    // PAN lengths each scheme issues. Derived from mpsScheme() so the ranges live in ONE place.
    var MPS_SCHEME_LENGTHS = {
        visa:[13,16,19], mastercard:[16], amex:[15], discover:[16,19],
        diners:[14,16,19], jcb:[16,17,18,19], unionpay:[16,17,18,19]
    };

    function mpsLuhn(pan){
        var sum = 0, dbl = false, d;
        for(var i = pan.length - 1; i >= 0; i--){
            d = parseInt(pan.charAt(i), 10);
            if(dbl){ d *= 2; if(d > 9){ d -= 9; } }
            sum += d;
            dbl = !dbl;
        }
        return sum % 10 === 0;
    }

    var MPS_SCHEME_LABELS = {
        visa:'Visa', mastercard:'Mastercard', amex:'American Express',
        discover:'Discover', diners:'Diners Club', jcb:'JCB', unionpay:'UnionPay'
    };

    function mpsScheme(pan){
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

    /**
     * The scheme allow-list from the portal. Unlike validity, this is known from the first digits,
     * so it fires as the number is typed. An unrecognised range is allowed through — see
     * MPS_Card_Validator::brand_error().
     */
    function mpsBrandError(value, allowed){
        if(!allowed || !allowed.length) return null;
        var scheme = mpsScheme(String(value || '').replace(/\D/g,''));
        if(!scheme || allowed.indexOf(scheme) !== -1) return null;

        var names = allowed.map(function(a){ return MPS_SCHEME_LABELS[a] || a; });
        var list = names.length > 1
            ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1]
            : names[0];
        return 'Only ' + list + ' are accepted here. Please use a different card.';
    }

    function mpsCardNumberError(value){
        var pan = String(value || '').replace(/\D/g,'');
        if(!pan) return null;                                   // empty is "not filled in yet"
        if(pan.length < 12 || pan.length > 19) return MPS_CARD_MESSAGE;
        if(/^(\d)\1+$/.test(pan)) return MPS_CARD_MESSAGE;      // 0000... passes Luhn, is not a card
        var scheme = mpsScheme(pan);
        if(scheme && MPS_SCHEME_LENGTHS[scheme].indexOf(pan.length) === -1) return MPS_CARD_MESSAGE;
        if(!mpsLuhn(pan)) return MPS_CARD_MESSAGE;
        return null;
    }

    /**
     * The three checks that share the message line under the card field:
     *
     *   - blocked BIN: a real card the processor will never approve. Known from the first digits,
     *     so it fires as the number is typed, and the Place Order button is disabled — that state
     *     is terminal, no amount of re-typing this card will help.
     *   - wrong brand: a scheme this merchant does not accept (portal allowed_cards). Also known
     *     from the first digits, so it fires while typing — nobody should fill in expiry and CVV
     *     for a card we were never going to take.
     *   - invalid number: fails scheme/length/Luhn. Only checked on BLUR, because every partial
     *     number fails Luhn and flagging a customer mid-type is wrong. The button is left alone;
     *     they may still be editing, and the server refuses it anyway.
     *
     * Both are feedback only. The server repeats both checks before processing, so a stripped or
     * broken script cannot let anything through.
     */
    function setupBinBlock(input){
        if(input.dataset.mpsBinBound) return;
        input.dataset.mpsBinBound = '1';

        var rules, allowed;
        try { rules = JSON.parse(input.getAttribute('data-blocked-bins') || '[]'); }
        catch(e){ rules = []; }
        if(!rules){ rules = []; }
        try { allowed = JSON.parse(input.getAttribute('data-allowed-cards') || '[]'); }
        catch(e){ allowed = []; }

        var notice = input.parentNode.querySelector('.mps-bin-blocked');

        function match(digits){
            for(var i=0;i<rules.length;i++){
                var bin = String(rules[i].bin || '').replace(/\D/g,'');
                // Wait until they have typed at least as many digits as the rule is long, or a
                // 6-digit rule would fire on the first two digits of a perfectly good card.
                if(bin && digits.length >= bin.length && digits.indexOf(bin) === 0){ return rules[i]; }
            }
            return null;
        }

        function payButton(){
            var form = input.closest('form');
            return form ? form.querySelector('#place_order, button[type="submit"][name="woocommerce_checkout_place_order"]') : null;
        }

        function check(isFinal){
            var digits = (input.value || '').replace(/\D/g,'');
            var hit = match(digits);
            var btn = payButton();

            if(hit){
                if(notice){
                    notice.textContent = hit.message || 'This card cannot be used on this store. Please try a different card.';
                    notice.style.display = '';
                }
                input.classList.add('mps-input-error');
                if(btn){ btn.disabled = true; btn.classList.add('mps-blocked-submit'); }
                return;
            }

            // Not blocked. Release anything THIS check disabled — another plugin may have its own
            // reason for the button being disabled, and stealing that would be worse than the
            // problem being solved.
            if(btn && btn.classList.contains('mps-blocked-submit')){
                btn.disabled = false;
                btn.classList.remove('mps-blocked-submit');
            }

            // Brand is known from the first digits, so it is reported as they type — the customer
            // should not fill in expiry and CVV for a card we were never going to take.
            var wrongBrand = mpsBrandError(digits, allowed);
            if(wrongBrand){
                if(notice){ notice.textContent = wrongBrand; notice.style.display = ''; }
                input.classList.add('mps-input-error');
                return;
            }

            var invalid = isFinal ? mpsCardNumberError(digits) : null;
            if(invalid){
                if(notice){ notice.textContent = invalid; notice.style.display = ''; }
                input.classList.add('mps-input-error');
                return;
            }

            if(notice){ notice.style.display = 'none'; }
            input.classList.remove('mps-input-error');
        }

        input.addEventListener('input', function(){ check(false); });
        input.addEventListener('blur', function(){ check(true); });
        check(false);
    }

    function init(){
        // Card number fields
        document.querySelectorAll('.mps-card-form input[name$="_card_number"]').forEach(function(el){
            formatCardNumber(el);
        });

        // Blocked BINs
        document.querySelectorAll('.mps-card-form input[name$="_card_number"]').forEach(setupBinBlock);

        // Mandatory charge-acknowledgment tick-box
        document.querySelectorAll('.mps-card-form input[name$="_charge_ack"]').forEach(setupAckLock);

        // Expiry fields
        document.querySelectorAll('.mps-card-form input[name$="_card_expiry"]').forEach(formatExpiry);

        // CVV fields
        document.querySelectorAll('.mps-card-form input[name$="_card_cvv"]').forEach(function(el){
            limitNumeric(el,4);
        });
    }

    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',init);
    } else {
        init();
    }

    // Re-init on WooCommerce checkout update
    if(typeof jQuery !== 'undefined'){
        jQuery(document.body).on('updated_checkout payment_method_selected',function(){
            setTimeout(init,200);
        });
    }
})();
