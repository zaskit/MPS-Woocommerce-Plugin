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
        input.addEventListener('input',function(){
            var v = this.value.replace(/\D/g,'').substring(0,4);
            if(v.length>=3){
                this.value = v.substring(0,2)+' / '+v.substring(2);
            } else {
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

    function init(){
        // Card number fields
        document.querySelectorAll('.mps-card-form input[name$="_card_number"]').forEach(function(el){
            formatCardNumber(el);
            setupMastercardCheck(el);
        });

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
