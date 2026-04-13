(function(){
    'use strict';

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var createElement = window.wp.element.createElement;
    var decodeEntities = window.wp.htmlEntities.decodeEntities;

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

    function formatExpiry(val) {
        var d = val.replace(/\D/g,'').substring(0,4);
        if(d.length >= 3) return d.substring(0,2) + ' / ' + d.substring(2);
        return d;
    }

    function numericOnly(val, max) {
        return val.replace(/\D/g,'').substring(0, max);
    }

    // Scan for all MPS gateway data variables
    var keys = Object.keys(window).filter(function(k){ return k.indexOf('mps_blocks_data_mps_') === 0; });

    keys.forEach(function(varName){
        var dataVar = window[varName];
        if(!dataVar || !dataVar.id) return;

        var gatewayId = dataVar.id;
        var is3ds = !!dataVar.supports_3ds;
        var hasFields = !!dataVar.has_fields && dataVar.has_fields !== '0' && dataVar.has_fields !== '';

        var Content = function(props){
            var eventRegistration = props.eventRegistration;
            var emitResponse = props.emitResponse;
            // Support both old and new WC Blocks event names
            var onPaymentSetup = eventRegistration.onPaymentSetup || eventRegistration.onPaymentProcessing;

            var stateRef = window.wp.element.useRef({});

            window.wp.element.useEffect(function(){
                if(!onPaymentSetup) return;
                var unsub = onPaymentSetup(function(){
                    var paymentData = {};
                    var s = stateRef.current;
                    for(var key in s){
                        paymentData[key] = s[key] || '';
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
                    var val = formatter ? formatter(e.target.value) : e.target.value;
                    e.target.value = val;
                    stateRef.current[fieldName] = val;
                };
            }

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
                elements.push(
                    createElement('div', {key:'f'+i, className:'mps-field'},
                        createElement('label', null, f.label),
                        createElement('input', {
                            type: f.type || 'text',
                            placeholder: f.placeholder,
                            maxLength: f.maxLength || undefined,
                            inputMode: f.inputMode || undefined,
                            autoComplete: f.autocomplete || undefined,
                            onChange: handleChange(f.name, fmt)
                        })
                    )
                );
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

            // Secure badge
            elements.push(
                createElement('div', {key:'badge', className:'mps-secure-badge'},
                    createElement('span', null, '\uD83D\uDD12 Secured with 256-bit encryption')
                )
            );

            return createElement('div', { className: 'mps-card-form' }, elements);
        };

        var Label = function(){
            var icons = (dataVar.icons || []).map(function(ic, i){
                return createElement('img', {key:i, src:ic.src, alt:ic.alt, style:{maxHeight:'24px',marginLeft:'6px',verticalAlign:'middle',display:'inline-block'}});
            });
            return createElement('span', {style:{display:'inline-flex',alignItems:'center',gap:'4px'}},
                decodeEntities(dataVar.title || 'Pay by Card'),
                icons
            );
        };

        registerPaymentMethod({
            name: gatewayId,
            label: createElement(Label),
            content: createElement(Content),
            edit: createElement(Content),
            canMakePayment: function(){ return true; },
            ariaLabel: dataVar.title || 'Pay by Card',
            supports: { features: dataVar.supports || ['products'] }
        });
    });
})();
