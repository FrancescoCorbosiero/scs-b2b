// Progressive enhancement: il sito funziona anche senza JS (form + redirect).
// Nessun JS inline nei template (CSP: script-src 'self' 'unsafe-eval').
(function () {
    'use strict';

    function formatEur(amount) {
        return new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            .format(parseFloat(amount) || 0) + ' €';
    }
    // usata anche dalle espressioni Alpine della scheda rapida
    window.scsFormatEur = formatEur;

    // ── Messaggi temporanei (toast) ──────────────────────────────────
    function toast(message, type) {
        var host = document.querySelector('[data-toasts]');
        if (!host) return;
        var el = document.createElement('div');
        el.className = 'scs-toast pointer-events-auto rounded-xl px-4 py-2.5 text-sm font-semibold shadow-lg ' +
            (type === 'error' ? 'bg-red-600 text-white' : 'bg-neutral-900 text-white');
        el.textContent = message;
        host.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .25s, transform .25s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(8px)';
            setTimeout(function () { el.remove(); }, 260);
        }, 2600);
    }
    window.scsToast = toast;

    function csrfToken() {
        var input = document.querySelector('input[name="_csrf"]');
        return input ? input.value : '';
    }

    function updateCartBadge(count) {
        // il badge esiste in più punti (nav desktop + pulsante carrello mobile)
        document.querySelectorAll('[data-cart-badge]').forEach(function (badge) {
            badge.textContent = count;
            badge.classList.toggle('hidden', count === 0);
        });
    }

    /** Scrive una quantità nel carrello di sessione (stessa rotta del carrello). */
    function postCartQty(sku, sizeEu, qty) {
        var body = new URLSearchParams();
        body.set('_csrf', csrfToken());
        body.set('sku', sku);
        body.set('size_eu', sizeEu);
        body.set('qty', String(qty));

        return fetch('/carrello/aggiorna', {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        });
    }

    // ── Ricerca catalogo: continuità della digitazione tra un invio e l'altro ──
    var SEARCH_DRAFT_KEY = 'scs:catalog-search-draft';

    function rememberSearchDraft() {
        var input = document.querySelector('[data-search-input]');
        if (!input) return;
        try {
            sessionStorage.setItem(SEARCH_DRAFT_KEY, input.value);
        } catch (e) { /* sessionStorage non disponibile: pazienza */ }
    }

    function restoreSearchDraft() {
        var input = document.querySelector('[data-search-input]');
        if (!input) return;
        var draft = null;
        try {
            draft = sessionStorage.getItem(SEARCH_DRAFT_KEY);
            sessionStorage.removeItem(SEARCH_DRAFT_KEY);
        } catch (e) { return; }
        if (draft === null) return;
        // solo se la pagina mostra davvero il risultato di quella ricerca
        if (draft.trim() !== input.value.trim()) return;

        input.value = draft;
        input.focus();
        try {
            input.setSelectionRange(draft.length, draft.length);
        } catch (e) { /* alcuni browser non lo permettono su type=search */ }
    }

    /** Feedback visivo mentre il server ricalcola i filtri del catalogo. */
    function submitWithFeedback(form) {
        if (!form) return;
        // URL condivisibili: fuori i parametri vuoti o ai valori di default
        // (disabilitare un campo lo esclude dall'invio; tanto si naviga via)
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled) return;
            var isChoice = el.type === 'radio' || el.type === 'checkbox';
            var isDefault = el.getAttribute('data-default') !== null && el.value === el.getAttribute('data-default');
            var isEmpty = isChoice ? (el.checked && el.value === '') : String(el.value).trim() === '';
            if (isEmpty || isDefault) el.disabled = true;
        });
        var bar = document.createElement('div');
        bar.className = 'scs-progress fixed inset-x-0 top-0 z-[70] h-0.5 bg-amber-500';
        document.body.appendChild(bar);
        var grid = document.querySelector('[data-catalog-grid]');
        if (grid) {
            grid.classList.add('opacity-40', 'pointer-events-none');
        }
        form.submit();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // ── Select/checkbox che inviano il proprio form al change ────
        document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () {
                submitWithFeedback(el.form);
            });
        });

        // ── Campi che inviano il form dopo una pausa di digitazione ──
        // (default 600ms; data-debounce-submit="700" per i campi numerici)
        document.querySelectorAll('[data-debounce-submit]').forEach(function (el) {
            var wait = parseInt(el.getAttribute('data-debounce-submit') || '', 10) || 600;
            var timer = null;
            el.addEventListener('input', function () {
                if (timer) clearTimeout(timer);
                // uno spazio in coda vuol dire "sto per scrivere un'altra
                // parola": si aspetta di più prima di interrompere con un invio
                var pending = typeof el.value === 'string' && /\s$/.test(el.value);
                timer = setTimeout(function () {
                    rememberSearchDraft();
                    submitWithFeedback(el.form);
                }, pending ? wait * 2 : wait);
            });
        });

        // Ricerca catalogo: dopo l'invio la pagina si ricarica, quindi si
        // rimette il fuoco nel campo col testo esatto digitato (spazio finale
        // incluso: il server lo taglia e "air " + "max" diventerebbe "airmax").
        restoreSearchDraft();

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
        // in capture: 'error' non fa bubbling, e così copre anche le immagini
        // create dopo il caricamento (scheda rapida, "carica altri")
        document.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG') return;
            var fallback = img.getAttribute('data-fallback');
            if (fallback && img.getAttribute('src') !== fallback) {
                img.src = fallback;
            }
        }, true);

        // ── Pagine pubbliche: comparsa dei blocchi allo scroll ───────
        // Lo stato nascosto lo aggiunge il JS: senza JS resta tutto visibile.
        var revealEls = document.querySelectorAll('[data-reveal]');
        if (revealEls.length && 'IntersectionObserver' in window) {
            revealEls.forEach(function (el) { el.classList.add('scs-reveal-init'); });
            var revealObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.remove('scs-reveal-init');
                    entry.target.classList.add('scs-reveal-in');
                    revealObserver.unobserve(entry.target);
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
            revealEls.forEach(function (el) { revealObserver.observe(el); });
        }

        // ══ Catalogo ════════════════════════════════════════════════
        // ── Scheda rapida: ordina taglia per taglia senza cambiare pagina ──
        // Senza JS il bottone della card resta un POST /carrello/aggiungi
        // (prodotto nel carrello, taglie da scegliere lì).
        function openQuickview(raw) {
            var product;
            try { product = JSON.parse(raw); } catch (e) { return false; }
            product.sizes = (product.sizes || []).map(function (s) {
                return { size_eu: s.size_eu, size_us: s.size_us, quantity: s.quantity, price: s.price, qty: 0 };
            });
            window.dispatchEvent(new CustomEvent('scs-quickview', { detail: product }));
            // il pannello prende il fuoco: Esc e Tab restano dentro la scheda
            setTimeout(function () {
                var panel = document.querySelector('[data-qv-panel]');
                if (panel) panel.focus();
            }, 60);

            return true;
        }

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-quickview]');
            if (!trigger) return;
            if (openQuickview(trigger.getAttribute('data-product') || '')) e.preventDefault();
        });

        document.addEventListener('submit', function (e) {
            var form = e.target.closest('form[data-quickview-form]');
            if (!form) return;
            if (openQuickview(form.getAttribute('data-product') || '')) e.preventDefault();
        });

        // aggiunta al carrello dalla scheda rapida: una riga per taglia
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-qv-submit]');
            if (!btn) return;
            var panel = document.querySelector('[data-qv-panel]');
            var sku = panel ? panel.getAttribute('data-qv-sku') : '';
            if (!sku) return;

            var rows = [];
            panel.querySelectorAll('[data-qv-qty]').forEach(function (input) {
                var qty = parseInt(input.value || '0', 10) || 0;
                if (qty > 0) rows.push({ size: input.getAttribute('data-size') || '', qty: qty });
            });
            if (rows.length === 0) return;

            btn.disabled = true;
            var pieces = 0;
            var chain = Promise.resolve();
            var lastTotal = null;
            rows.forEach(function (row) {
                chain = chain.then(function () {
                    return postCartQty(sku, row.size, row.qty).then(function (data) {
                        pieces += data.applied;
                        lastTotal = data.total_items;
                    });
                });
            });
            chain.then(function () {
                if (lastTotal !== null) updateCartBadge(lastTotal);
                toast((btn.getAttribute('data-added-label') || '+:n').replace(':n', String(pieces)));
                window.dispatchEvent(new CustomEvent('scs-quickview-close'));
            }).catch(function () {
                toast(btn.getAttribute('data-error-label') || 'Error', 'error');
            }).then(function () {
                btn.disabled = false;
            });
        });

        // ── "Carica altri": accoda le card della pagina successiva ───
        var loadMoreBtn = document.querySelector('[data-loadmore]');
        var loadMoreWrap = document.querySelector('[data-loadmore-wrap]');
        if (loadMoreBtn && loadMoreWrap) {
            var pagination = document.querySelector('[data-pagination]');
            if (pagination) pagination.classList.add('hidden');
            loadMoreWrap.classList.remove('hidden');
            loadMoreWrap.classList.add('flex');

            var autoLoad = false;
            var loading = false;
            var loadNext = function () {
                if (loading) return;
                var url = loadMoreBtn.getAttribute('data-next-url');
                var next = parseInt(loadMoreBtn.getAttribute('data-next-page') || '2', 10);
                var totalPages = parseInt(loadMoreBtn.getAttribute('data-total-pages') || '1', 10);
                var grid = document.querySelector('[data-catalog-grid]');
                if (!url || !grid) return;

                loading = true;
                loadMoreBtn.disabled = true;
                loadMoreBtn.textContent = loadMoreBtn.getAttribute('data-loading-label') || '…';

                fetch(url, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
                    .then(function (res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.text();
                    })
                    .then(function (html) {
                        var holder = document.createElement('div');
                        holder.innerHTML = html;
                        while (holder.firstElementChild) grid.appendChild(holder.firstElementChild);

                        if (next + 1 > totalPages) {
                            loadMoreWrap.remove();
                            return;
                        }
                        loadMoreBtn.setAttribute('data-next-page', String(next + 1));
                        loadMoreBtn.setAttribute('data-next-url', url.replace('page=' + next, 'page=' + (next + 1)));
                        loadMoreBtn.textContent = loadMoreBtn.getAttribute('data-label') || '+';
                        loadMoreBtn.disabled = false;
                    })
                    .catch(function () {
                        // fallback onesto: si torna alla paginazione classica
                        loadMoreWrap.classList.add('hidden');
                        if (pagination) pagination.classList.remove('hidden');
                    })
                    .then(function () { loading = false; });
            };

            loadMoreBtn.addEventListener('click', function () {
                autoLoad = true; // dopo la prima volta prosegue da solo scorrendo
                loadNext();
            });

            if ('IntersectionObserver' in window) {
                new IntersectionObserver(function (entries) {
                    if (autoLoad && entries[0] && entries[0].isIntersecting) loadNext();
                }, { rootMargin: '400px' }).observe(loadMoreWrap);
            }
        }

        // ── "/" mette a fuoco la ricerca, Esc la svuota ──────────────
        var searchInput = document.querySelector('[data-search-input]');
        if (searchInput) {
            document.addEventListener('keydown', function (e) {
                var tag = (e.target.tagName || '').toLowerCase();
                if (e.key === '/' && tag !== 'input' && tag !== 'textarea' && tag !== 'select') {
                    e.preventDefault();
                    searchInput.focus();
                    searchInput.select();
                }
            });
        }

        // ── Torna su ─────────────────────────────────────────────────
        var backToTop = document.querySelector('[data-back-to-top]');
        if (backToTop) {
            var toggleBackToTop = function () {
                var show = window.scrollY > 700;
                backToTop.classList.toggle('hidden', !show);
                backToTop.classList.toggle('flex', show);
            };
            window.addEventListener('scroll', toggleBackToTop, { passive: true });
            backToTop.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            toggleBackToTop();
        }

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

        // ── Carrello: quantità via fetch, viste mobile e desktop in sincrono ──
        // Ogni taglia esiste due volte nel DOM (tessere mobile + tabella
        // desktop): si aggiornano entrambe, come tutti i riepiloghi presenti.
        var cartInputs = document.querySelectorAll('[data-cart-qty]');
        if (cartInputs.length) {
            var setAllText = function (selector, text) {
                document.querySelectorAll(selector).forEach(function (el) { el.textContent = text; });
            };
            var toggleAll = function (selector, className, on) {
                document.querySelectorAll(selector).forEach(function (el) { el.classList.toggle(className, on); });
            };

            var renderCart = function (data, source) {
                // stesse quantità nelle due viste della stessa taglia
                document.querySelectorAll('[data-cart-qty][data-sku="' + (source.getAttribute('data-sku') || '') +
                    '"][data-size="' + (source.getAttribute('data-size') || '') + '"]').forEach(function (twin) {
                    twin.value = data.applied;
                });

                var section = source.closest('[data-cart-product]');
                if (section) {
                    section.querySelectorAll('[data-product-total]').forEach(function (el) {
                        el.textContent = formatEur(data.product_total);
                    });
                    section.querySelectorAll('[data-product-items]').forEach(function (el) {
                        el.textContent = data.product_items;
                    });
                }

                setAllText('[data-summary-items]', data.total_items);
                setAllText('[data-summary-total]', formatEur(data.total_amount));

                // spedizione: gratuita da N pezzi in su (il server resta la verità)
                var summary = document.querySelector('[data-shipping-free-from]');
                var shippingCents = Math.round(parseFloat(data.shipping_amount || '0') * 100);
                if (summary) {
                    setAllText('[data-summary-shipping]', data.shipping_free
                        ? (summary.getAttribute('data-shipping-free-label') || '')
                        : formatEur(shippingCents / 100));
                    toggleAll('[data-summary-shipping]', 'text-emerald-600', !!data.shipping_free);

                    setAllText('[data-shipping-hint]', data.shipping_items_to_free === 1
                        ? (summary.getAttribute('data-shipping-to-free-one') || '')
                        : (summary.getAttribute('data-shipping-to-free-template') || '')
                            .replace(':n', String(data.shipping_items_to_free)));
                    toggleAll('[data-shipping-hint]', 'hidden', !!data.shipping_free || data.total_items === 0);
                }

                // stima VAT/totale lordo dal rate del paese selezionato
                // (imponibile = merce + spedizione)
                var rateEl = document.querySelector('[data-vat-rate]');
                if (rateEl) {
                    var rate = parseFloat(rateEl.getAttribute('data-vat-rate')) || 0;
                    var netCents = Math.round(parseFloat(data.total_amount) * 100) + shippingCents;
                    var vatCents = Math.round(netCents * rate / 100);
                    setAllText('[data-summary-vat]', formatEur(vatCents / 100));
                    setAllText('[data-summary-gross]', formatEur((netCents + vatCents) / 100));
                }

                updateCartBadge(data.total_items);
                toggleAll('[data-minimum-warning]', 'hidden', data.meets_minimum);
                document.querySelectorAll('[data-proceed]').forEach(function (proceed) {
                    proceed.classList.toggle('pointer-events-none', !data.meets_minimum);
                    proceed.classList.toggle('bg-neutral-200', !data.meets_minimum);
                    proceed.classList.toggle('text-neutral-400', !data.meets_minimum);
                    proceed.classList.toggle('bg-neutral-900', data.meets_minimum);
                    proceed.classList.toggle('text-white', data.meets_minimum);
                });
            };

            var pushQty = function (input) {
                var max = parseInt(input.max || '0', 10);
                var qty = Math.max(0, Math.min(parseInt(input.value || '0', 10) || 0, max));
                input.value = qty;

                postCartQty(input.getAttribute('data-sku') || '', input.getAttribute('data-size') || '', qty)
                    .then(function (data) { renderCart(data, input); })
                    .catch(function () {
                        // in caso di errore ricarica: lo stato server resta la verità
                        window.location.reload();
                    });
            };

            cartInputs.forEach(function (input) {
                input.addEventListener('change', function () { pushQty(input); });
            });

            // stepper +/- delle tessere taglia (mobile)
            document.addEventListener('click', function (e) {
                var step = e.target.closest('[data-qty-step]');
                if (!step) return;
                var input = step.parentElement.querySelector('[data-cart-qty]');
                if (!input || input.disabled) return;
                var max = parseInt(input.max || '0', 10);
                var next = (parseInt(input.value || '0', 10) || 0) + parseInt(step.getAttribute('data-qty-step'), 10);
                input.value = Math.max(0, Math.min(next, max));
                pushQty(input);
            });
        }
    });
})();
