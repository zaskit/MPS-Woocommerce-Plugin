NMI Payment Component — vendored, byte-for-byte unmodified.

File:     nmi-payments-1.0.2.iife.js
Source:   npm @nmipayments/nmi-pay@1.0.2, dist/nmi-payments-cdn.iife.js
SHA-256:  ecb5295fce64cd381e4c699e0ab065c953246f77873b887b5c680872668e41a7
Size:     144907 bytes
License:  MIT (declared in the package's package.json; the package ships no separate LICENSE file)
Added:    2026-09-30 (plugin v2.9.0 branch, D-Processor) — replaces NMI Collect.js.

Why bundled and not loaded from a CDN: NMI publishes no hosted script URL for the Payment Component,
and a copy served from the store itself keeps the checkout's third-party script list short
(PCI DSS 4.0.1 req. 6.4.3 inventory). What it talks to at runtime (all NMI):
  - https://secure.nmi.com/token/api/create   (token session, XHR)
  - https://secure.nmi.com/token/api/lookup   (masked number / BIN / brand, XHR)
  - https://secure.nmi.com/token/inline.php   (the card-field iframes)
  - https://secure.networkmerchants.com/js/v1/Gateway.js — ONLY when the 3-D Secure element is mounted.
    The plugin never mounts it.
Apple Pay / Google Pay code is in the file but inactive: the plugin passes paymentMethods: ['card'].

To upgrade: replace the file under a new versioned name, update this README (version, SHA-256) and
the handle version in MPS_DProcessor::register_scripts().
