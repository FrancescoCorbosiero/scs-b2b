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

        // ── Conferma prima del submit (es. eliminazione regole margine) ──
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!window.confirm(form.getAttribute('data-confirm') || '')) e.preventDefault();
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

        // ── Righe cliccabili: tutta la riga apre il dettaglio del record ──
        // Progressive enhancement: ogni riga contiene comunque il link vero
        // (prima cella) per tastiera e navigazione senza JS. Click su link,
        // bottoni o campi interni restano loro; ctrl/cmd/tasto centrale
        // aprono in una nuova scheda come su un link normale.
        document.querySelectorAll('[data-row-link]').forEach(function (row) {
            var href = row.getAttribute('data-row-link');
            if (!href) return;
            var newTab = row.getAttribute('data-row-link-target') === '_blank';
            var isInteractive = function (target) {
                return target.closest && target.closest('a, button, input, select, textarea, label, form');
            };
            var hasSelection = function () {
                var sel = window.getSelection();
                return sel && sel.type === 'Range' && sel.toString().trim() !== '';
            };
            row.classList.add('cursor-pointer');
            row.addEventListener('click', function (e) {
                if (isInteractive(e.target) || hasSelection()) return;
                if (newTab || e.ctrlKey || e.metaKey) window.open(href, '_blank', 'noopener');
                else window.location.assign(href);
            });
            row.addEventListener('auxclick', function (e) {
                if (e.button !== 1 || isInteractive(e.target)) return;
                e.preventDefault();
                window.open(href, '_blank', 'noopener');
            });
        });

        // ── Form ordine: anteprima VAT per paese (il server resta la verità) ──
        var vatPreview = document.querySelector('[data-vat-preview]');
        if (vatPreview) {
            var vatCountries = [];
            try { vatCountries = JSON.parse(vatPreview.getAttribute('data-countries') || '[]'); } catch (e) { vatCountries = []; }
            var vatByCode = {};
            vatCountries.forEach(function (c) { vatByCode[c.code] = c; });
            // la spedizione è accessoria ai beni: entra nell'imponibile VAT
            var netCents = Math.round(parseFloat(vatPreview.getAttribute('data-net') || '0') * 100)
                + Math.round(parseFloat(vatPreview.getAttribute('data-shipping') || '0') * 100);
            var countrySelect = document.getElementById('o-country');
            var vatNumberInput = document.getElementById('o-vat-number');

            var updateVatPreview = function () {
                var code = countrySelect ? countrySelect.value : 'IT';
                var entry = vatByCode[code] || { rate: 0, is_eu: true };
                var hasVatNumber = vatNumberInput && vatNumberInput.value.trim() !== '';
                var scheme, rate;
                if (!entry.is_eu) { scheme = 'export'; rate = 0; }
                else if (code !== 'IT' && hasVatNumber) { scheme = 'reverse'; rate = 0; }
                else { scheme = code === 'IT' ? 'domestic' : 'eu'; rate = entry.rate; }
                var vatCents = Math.round(netCents * rate / 100);

                var label = (vatPreview.getAttribute('data-label-' + scheme) || '').replace(':rate', String(rate));
                var labelEl = vatPreview.querySelector('[data-vat-label]');
                if (labelEl) labelEl.textContent = label;
                var amountEl = vatPreview.querySelector('[data-vat-amount]');
                if (amountEl) amountEl.textContent = formatEur(vatCents / 100);
                var grossEl = vatPreview.querySelector('[data-vat-gross]');
                if (grossEl) grossEl.textContent = formatEur((netCents + vatCents) / 100);
                var hintEl = vatPreview.querySelector('[data-vat-hint]');
                if (hintEl) hintEl.textContent = vatPreview.getAttribute('data-hint-' + scheme) || '';
            };
            if (countrySelect) countrySelect.addEventListener('change', updateVatPreview);
            if (vatNumberInput) vatNumberInput.addEventListener('input', updateVatPreview);
            updateVatPreview();
        }

        // ── Richiesta ordine: countdown di ripensamento PRIMA dell'invio ──
        // Nulla parte (né email né ordine al fornitore) finché il countdown
        // non scade; "Annulla" riporta al form. Senza JS: invio diretto.
        var orderForm = document.querySelector('[data-order-form]');
        if (orderForm) {
            var countdownBox = orderForm.querySelector('[data-submit-countdown]');
            var countdownText = orderForm.querySelector('[data-countdown-text]');
            var cancelBtn = orderForm.querySelector('[data-countdown-cancel]');
            var submitBtn = orderForm.querySelector('[data-order-submit]');
            var countdownTimer = null;

            var stopCountdown = function () {
                if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
                if (countdownBox) countdownBox.classList.add('hidden');
                if (submitBtn) { submitBtn.classList.remove('hidden'); submitBtn.disabled = false; }
            };

            orderForm.addEventListener('submit', function (e) {
                if (orderForm.dataset.countdownDone === '1' || !countdownBox) return;
                if (!orderForm.checkValidity()) return; // lascia i messaggi nativi del browser
                e.preventDefault();
                var remaining = parseInt(orderForm.getAttribute('data-countdown-seconds') || '15', 10);
                var template = countdownBox.getAttribute('data-text-template') || ':s';
                var render = function () {
                    if (countdownText) countdownText.textContent = template.replace(':s', String(remaining));
                };
                if (submitBtn) { submitBtn.disabled = true; submitBtn.classList.add('hidden'); }
                countdownBox.classList.remove('hidden');
                countdownBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                render();
                countdownTimer = setInterval(function () {
                    remaining--;
                    if (remaining <= 0) {
                        clearInterval(countdownTimer);
                        countdownTimer = null;
                        orderForm.dataset.countdownDone = '1';
                        orderForm.submit();
                        return;
                    }
                    render();
                }, 1000);
            });

            if (cancelBtn) cancelBtn.addEventListener('click', stopCountdown);
        }

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

                    // spedizione: gratuita da N pezzi in su (il server resta la verità)
                    var summary = document.querySelector('[data-shipping-free-from]');
                    var shippingCents = Math.round(parseFloat(data.shipping_amount || '0') * 100);
                    var shippingEl = document.querySelector('[data-summary-shipping]');
                    if (shippingEl && summary) {
                        shippingEl.textContent = data.shipping_free
                            ? (summary.getAttribute('data-shipping-free-label') || '')
                            : formatEur(shippingCents / 100);
                        shippingEl.classList.toggle('text-emerald-600', !!data.shipping_free);
                    }
                    var shippingHint = document.querySelector('[data-shipping-hint]');
                    if (shippingHint && summary) {
                        shippingHint.textContent = data.shipping_items_to_free === 1
                            ? (summary.getAttribute('data-shipping-to-free-one') || '')
                            : (summary.getAttribute('data-shipping-to-free-template') || '')
                                .replace(':n', String(data.shipping_items_to_free));
                        shippingHint.classList.toggle('hidden', !!data.shipping_free || data.total_items === 0);
                    }

                    // stima VAT/totale lordo dal rate del paese selezionato
                    // (imponibile = merce + spedizione)
                    var rateEl = document.querySelector('[data-vat-rate]');
                    if (rateEl) {
                        var rate = parseFloat(rateEl.getAttribute('data-vat-rate')) || 0;
                        var netCents = Math.round(parseFloat(data.total_amount) * 100) + shippingCents;
                        var vatCents = Math.round(netCents * rate / 100);
                        var vatEl = document.querySelector('[data-summary-vat]');
                        if (vatEl) vatEl.textContent = formatEur(vatCents / 100);
                        var grossEl = document.querySelector('[data-summary-gross]');
                        if (grossEl) grossEl.textContent = formatEur((netCents + vatCents) / 100);
                    }

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
