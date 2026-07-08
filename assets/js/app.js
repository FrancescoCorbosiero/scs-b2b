// Progressive enhancement: il sito funziona anche senza JS (form + redirect).
// Nessun JS inline nei template (CSP: script-src 'self' 'unsafe-eval').
(function () {
    'use strict';

    function formatEur(amount) {
        return new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            .format(parseFloat(amount)) + ' €';
    }

    document.addEventListener('DOMContentLoaded', function () {
        // ── Select/checkbox che inviano il proprio form al change ────
        document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (el.form) el.form.submit();
            });
        });

        // ── Ricerca con debounce 300ms ───────────────────────────────
        document.querySelectorAll('[data-debounce-submit]').forEach(function (el) {
            var timer = null;
            el.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                timer = setTimeout(function () {
                    if (el.form) el.form.submit();
                }, 300);
            });
        });

        // ── Copia SKU negli appunti ──────────────────────────────────
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy') || '';
                var done = function () {
                    btn.classList.add('text-emerald-500');
                    setTimeout(function () { btn.classList.remove('text-emerald-500'); }, 1200);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    done();
                }
            });
        });

        // ── Fallback immagini prodotto ───────────────────────────────
        document.querySelectorAll('img[data-fallback]').forEach(function (img) {
            img.addEventListener('error', function () {
                var fallback = img.getAttribute('data-fallback');
                if (fallback && img.src.indexOf(fallback) === -1) {
                    img.src = fallback;
                }
            }, { once: true });
        });

        // ── Carrello: aggiornamento quantità via fetch ───────────────
        var csrfInput = document.querySelector('input[name="_csrf"]');
        var csrf = csrfInput ? csrfInput.value : '';

        document.querySelectorAll('[data-cart-qty]').forEach(function (input) {
            input.addEventListener('change', function () {
                var max = parseInt(input.max || '0', 10);
                var qty = Math.max(0, Math.min(parseInt(input.value || '0', 10) || 0, max));
                input.value = qty;

                var body = new URLSearchParams();
                body.set('_csrf', csrf);
                body.set('sku', input.getAttribute('data-sku') || '');
                body.set('size_eu', input.getAttribute('data-size') || '');
                body.set('qty', String(qty));

                fetch('/carrello/aggiorna', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: body,
                    credentials: 'same-origin'
                }).then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                }).then(function (data) {
                    input.value = data.applied;

                    var section = input.closest('[data-cart-product]');
                    if (section) {
                        var productTotal = section.querySelector('[data-product-total]');
                        if (productTotal) productTotal.textContent = formatEur(data.product_total);
                    }
                    var items = document.querySelector('[data-summary-items]');
                    if (items) items.textContent = data.total_items;
                    var total = document.querySelector('[data-summary-total]');
                    if (total) total.textContent = formatEur(data.total_amount);

                    var badge = document.querySelector('[data-cart-badge]');
                    if (badge) {
                        badge.textContent = data.total_items;
                        badge.classList.toggle('hidden', data.total_items === 0);
                    }
                    var warning = document.querySelector('[data-minimum-warning]');
                    if (warning) warning.classList.toggle('hidden', data.meets_minimum);
                    var proceed = document.querySelector('[data-proceed]');
                    if (proceed) {
                        proceed.classList.toggle('pointer-events-none', !data.meets_minimum);
                        proceed.classList.toggle('bg-neutral-200', !data.meets_minimum);
                        proceed.classList.toggle('text-neutral-400', !data.meets_minimum);
                        proceed.classList.toggle('bg-neutral-900', data.meets_minimum);
                        proceed.classList.toggle('text-white', data.meets_minimum);
                    }
                }).catch(function () {
                    // in caso di errore ricarica: lo stato server resta la verità
                    window.location.reload();
                });
            });
        });
    });
})();
