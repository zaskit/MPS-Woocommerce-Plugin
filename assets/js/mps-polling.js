(function() {
    var params = new URLSearchParams(window.location.search);
    var isVp3d = params.get('mps_vp3d_poll') === '1';
    var isEp2d = params.get('mps_ep2d_poll') === '1';

    if (!isVp3d && !isEp2d) return;

    var orderId = params.get('order_id');
    var orderKey = params.get('key');
    if (!orderId || !orderKey) return;

    var action = isVp3d ? 'mps_vp3d_poll_status' : 'mps_ep2d_poll_status';
    var maxPollTime = 90000; // 90 seconds
    var pollInterval = 3000; // 3 seconds
    var startTime = Date.now();

    // Create overlay
    var overlay = document.createElement('div');
    overlay.id = 'mps-polling-overlay';
    overlay.innerHTML = '<div style="position:fixed;inset:0;background:rgba(255,255,255,0.95);z-index:99999;display:flex;align-items:center;justify-content:center;flex-direction:column;">'
        + '<div style="text-align:center;max-width:400px;padding:40px;">'
        + '<div style="width:48px;height:48px;border:4px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:mps-spin 1s linear infinite;margin:0 auto 24px;"></div>'
        + '<h2 style="margin:0 0 8px;font-size:20px;color:#111;">Processing your payment</h2>'
        + '<p id="mps-poll-msg" style="margin:0;color:#6b7280;font-size:14px;">Please wait while we verify your payment...</p>'
        + '</div></div>'
        + '<style>@keyframes mps-spin{to{transform:rotate(360deg)}}</style>';
    document.body.appendChild(overlay);

    function poll() {
        if (Date.now() - startTime > maxPollTime) {
            document.getElementById('mps-poll-msg').textContent = 'Still processing. You will receive a confirmation email shortly.';
            return;
        }

        var url = (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php')
            + '?action=' + action + '&order_id=' + orderId + '&key=' + encodeURIComponent(orderKey);

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                var data = resp.data || {};
                if (data.status === 'approved' && data.redirect_url) {
                    document.getElementById('mps-poll-msg').textContent = 'Payment approved! Redirecting...';
                    setTimeout(function() { window.location.href = data.redirect_url; }, 500);
                } else if (data.status === 'redirect_3ds' && data.redirect_url) {
                    document.getElementById('mps-poll-msg').textContent = 'Redirecting for verification...';
                    setTimeout(function() { window.location.href = data.redirect_url; }, 500);
                } else if (data.status === 'failed' && data.redirect_url) {
                    document.getElementById('mps-poll-msg').textContent = 'Payment was not successful.';
                    setTimeout(function() { window.location.href = data.redirect_url; }, 1500);
                } else {
                    setTimeout(poll, pollInterval);
                }
            })
            .catch(function() {
                setTimeout(poll, pollInterval);
            });
    }

    poll();
})();
