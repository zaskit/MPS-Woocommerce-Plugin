/**
 * EP3D Checkout Polling — stays on checkout, polls until callback confirms result.
 */
(function($) {
    'use strict';

    var pollTimer = null;
    var pollCount = 0;
    var maxPolls  = 40; // 40 × 3s = 120s max wait
    var isPolling = false;

    // Clear overlay on WC checkout error
    $(document.body).on('checkout_error', function() {
        removeOverlay();
    });

    // Intercept WC AJAX checkout response
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (!settings.url || settings.url.indexOf('wc-ajax=checkout') === -1) return;

        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) { return; }

        if (resp && resp.redirect && resp.redirect === '#mps-ep3d-polling' && resp.mps_poll) {
            startPolling(resp.mps_poll);
        }
    });

    // Safety net: if WC's redirect handler fires and sets hash
    $(window).on('hashchange', function() {
        if (window.location.hash === '#mps-ep3d-polling' && !isPolling) {
            // Polling data should be in session — but we may not have it here.
            // The ajaxComplete handler is the primary path; this is just a fallback.
            showOverlay();
        }
    });

    function startPolling(pollData) {
        if (isPolling) return;
        isPolling = true;
        pollCount = 0;
        showOverlay();

        // Prevent WC from navigating away
        history.replaceState(null, '', window.location.pathname + window.location.search);

        pollTimer = setInterval(function() {
            pollCount++;
            if (pollCount > maxPolls) {
                clearInterval(pollTimer);
                isPolling = false;
                updateOverlayStatus('timeout');
                return;
            }

            $.ajax({
                url: pollData.ajax_url,
                data: {
                    action: 'mps_ep3d_poll_status',
                    order_id: pollData.order_id,
                    key: pollData.key,
                },
                method: 'GET',
                dataType: 'json',
                success: function(resp) {
                    if (!resp || !resp.data) return;

                    if (resp.data.status === 'approved') {
                        clearInterval(pollTimer);
                        isPolling = false;
                        updateOverlayStatus('approved');
                        setTimeout(function() {
                            window.location.href = pollData.thankyou;
                        }, 1500);
                    } else if (resp.data.status === 'failed') {
                        clearInterval(pollTimer);
                        isPolling = false;
                        removeOverlay();
                        // Re-enable checkout form
                        $('form.checkout').removeClass('processing').unblock();
                        $('.blockOverlay').remove();
                        // Show error
                        var msg = resp.data.message || 'Payment was declined. Please try again.';
                        showCheckoutError(msg);
                    }
                    // 'waiting' — continue polling
                }
            });
        }, 3000);
    }

    function showOverlay() {
        if ($('#mps-ep3d-overlay').length) return;
        var html = '<div id="mps-ep3d-overlay" style="position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;">' +
            '<div style="background:#fff;border-radius:12px;padding:40px 48px;text-align:center;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,0.3);">' +
            '<div class="mps-ep3d-spinner" style="margin:0 auto 20px;width:48px;height:48px;border:4px solid #e5e7eb;border-top:4px solid #215387;border-radius:50%;animation:mps-spin 1s linear infinite;"></div>' +
            '<h3 id="mps-ep3d-title" style="margin:0 0 8px;font-size:1.2rem;color:#1f2937;">Processing Payment</h3>' +
            '<p id="mps-ep3d-msg" style="margin:0;color:#6b7280;font-size:0.95rem;">Please wait while we verify your payment with the bank.<br>Do not close this page.</p>' +
            '</div></div>' +
            '<style>@keyframes mps-spin{to{transform:rotate(360deg)}}</style>';
        $('body').append(html);
    }

    function updateOverlayStatus(status) {
        if (status === 'approved') {
            $('.mps-ep3d-spinner').css({
                border: '4px solid #10b981',
                borderTop: '4px solid #10b981',
                animation: 'none',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center'
            }).html('<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>');
            $('#mps-ep3d-title').text('Payment Approved!').css('color', '#10b981');
            $('#mps-ep3d-msg').text('Redirecting to your order confirmation...');
        } else if (status === 'timeout') {
            $('.mps-ep3d-spinner').hide();
            $('#mps-ep3d-title').text('Taking longer than expected');
            $('#mps-ep3d-msg').html('Your payment is still being processed. You will receive an email confirmation once complete.<br><br><a href="' + window.location.pathname + '" style="color:#215387;font-weight:600;">Refresh page</a>');
        }
    }

    function removeOverlay() {
        $('#mps-ep3d-overlay').remove();
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        isPolling = false;
    }

    function showCheckoutError(message) {
        // Remove old errors first
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error').remove();

        var errorHtml = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
            '<ul class="woocommerce-error" role="alert"><li>Payment declined: ' + $('<span>').text(message).html() + '</li></ul></div>';

        if ($('form.checkout').length) {
            $('form.checkout').before(errorHtml);
        } else {
            $('.woocommerce').prepend(errorHtml);
        }

        $('html, body').animate({ scrollTop: 0 }, 300);
    }

})(jQuery);
