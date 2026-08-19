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

    function isMastercard(digits){
        if(digits.length<2) return null;
        var two = parseInt(digits.substring(0,2),10);
        if(two>=51 && two<=55) return true;
        if(digits.length>=4){
            var four = parseInt(digits.substring(0,4),10);
            if(four>=2221 && four<=2720) return true;
        }
        return false;
    }

    function setupMastercardCheck(input){
        if(input.getAttribute('data-mc-only') !== '1') return;

        var notice = document.createElement('div');
        notice.className = 'mps-mc-notice';
        notice.textContent = 'Only Mastercard is accepted on this gateway. Please use a Mastercard.';
        input.parentNode.appendChild(notice);

        input.addEventListener('input',function(){
            var digits = this.value.replace(/\D/g,'');
            if(digits.length<2){ notice.style.display='none'; input.style.borderColor=''; return; }
            var mc = isMastercard(digits);
            if(mc===false){
                notice.style.display='block';
                input.style.borderColor='#b91c1c';
            } else {
                notice.style.display='none';
                input.style.borderColor='';
            }
        });
    }

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
     * Blocked BINs — refuse a card the processor will never approve, as it is typed.
     *
     * Purely for immediate feedback: the server checks the same list before processing, so if this
     * script is stripped or broken by an optimizer nothing gets through that should not. It only
     * ever ADDS a message and disables the button; it never enables anything.
     */
    function setupBinBlock(input){
        if(input.dataset.mpsBinBound) return;
        input.dataset.mpsBinBound = '1';

        var rules;
        try { rules = JSON.parse(input.getAttribute('data-blocked-bins') || '[]'); }
        catch(e){ return; }
        if(!rules || !rules.length) return;

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

        function check(){
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
            } else {
                if(notice){ notice.style.display = 'none'; }
                input.classList.remove('mps-input-error');
                // Only re-enable a button THIS check disabled — another plugin may have its own
                // reason for the button being disabled, and stealing that would be worse than the
                // problem being solved.
                if(btn && btn.classList.contains('mps-blocked-submit')){
                    btn.disabled = false;
                    btn.classList.remove('mps-blocked-submit');
                }
            }
        }

        input.addEventListener('input', check);
        input.addEventListener('blur', check);
        check();
    }

    function init(){
        // Card number fields
        document.querySelectorAll('.mps-card-form input[name$="_card_number"]').forEach(function(el){
            formatCardNumber(el);
            setupMastercardCheck(el);
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
