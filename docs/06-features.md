# 06 — Pagine e funzionalità

Tutte le rotte (tranne `/login`) richiedono la sessione catalogo attiva.
UI in italiano, stringhe in `lang/it.php`. Mobile-first: i clienti usano molto lo
smartphone. Nav con: Catalogo, Carrello (con badge conteggio pezzi), Contatti,
dropdown piano prezzi (Base/Pro/Max), logout.

## /login
Campo password unico + "ricordami". Rate limited (vedi 07). Nessun hint sulla password.

## / (Catalogo)

Grid di card prodotto. Ogni card:
- immagine (lazy, fallback placeholder), badge "Recommended" se flag attivo
- SKU con pulsante copia-negli-appunti, nome prodotto, brand
- "Disponibilità totale: N"
- chips taglia→qty (es. `36 2/3 · 1`), nel sistema taglie attivo (EU default)
- prezzo "da X €" (min tra le taglie, piano attivo, IVA inclusa)
- CTA "Aggiungi al carrello" → aggiunge il prodotto e porta/espande la vista carrello
  del prodotto (o modale con la tabella taglie)

Toolbar filtri (stato su query string → URL condivisibili, enhancement Alpine):
- Disponibilità: Alta / Media / Bassa — soglie da `.env`
  (`AVAILABILITY_HIGH_MIN=60`, `AVAILABILITY_LOW_MAX=20` sul totale pezzi)
- Recommended (toggle)
- Brand (dropdown popolato dai brand attivi)
- Prezzo min–max (sul prezzo del piano attivo)
- Ricerca per nome/SKU (debounce 300ms)
- Toggle taglie **EU / US** (persiste in sessione)
- Ordinamento: rilevanza/nome, prezzo ↑↓, disponibilità ↓
- Paginazione 24/pagina
- **Export Excel** del risultato filtrato: SKU, nome, brand, taglia EU, taglia US,
  barcode, qty, prezzo del piano attivo. MAI offer_price, mai gli altri due listini.

## /carrello

Per ogni prodotto nel carrello: thumbnail, SKU, nome, "Remove", e la **tabella taglie**:

| | 41.5 | 42 | 42.5 | … |
|---|---|---|---|---|
| PREZZO | 105 | 105 | … | |
| STOCK | 5 | 18 | … | (rosso se ≤ 5) |
| ORDINA | [input] | [input] | … | |

- Input numerici con `max` = stock; validazione client E server (lo stock può essere
  cambiato da un sync: alla submission ricontrollare e segnalare le righe ridotte).
- Per prodotto: "Prendi tutto" (qty = stock su ogni taglia), "Svuota", subtotale.
- Riepilogo laterale (sticky): pezzi totali, totale EUR piano attivo, avviso
  "Ordine minimo: 5 pezzi" (`MIN_ORDER_ITEMS=5`) con CTA disabilitata sotto soglia.
- Cambiare piano dal dropdown ricalcola i prezzi mostrati (non le quantità).
- Persistenza: sessione server-side; sopravvive a refresh e navigazione.

## /richiesta-ordine (dal carrello)

Form: nome*, azienda, email*, telefono*, note. Honeypot + CSRF + rate limit
(max 3 invii/ora per sessione/IP). All'invio, in quest'ordine:
1. Rivalidare carrello vs stock corrente; salvare `order_requests` (snapshot completo).
2. Email admin a `ADMIN_EMAIL` (info@shoesclothingstore.com): tabella HTML con
   SKU, prodotto, taglia EU/US, barcode, qty, prezzo unitario, subtotale, totale,
   piano scelto, dati contatto; se `ADMIN_EMAIL_SHOW_COST=1` anche costo e margine.
3. Email di riepilogo al cliente (senza costi/margini).
4. Svuotare il carrello → pagina di conferma con recapiti.
Un fallimento SMTP NON deve perdere la richiesta (già a DB, flag `email_*_sent=0`,
log dell'errore) e all'utente si mostra comunque la conferma con invito a
contattare i recapiti in caso di mancata risposta.

## /contatti

Recapiti da config (valori in `01-overview.md`): email, telefono, WhatsApp
(`wa.me`), sede, P.IVA, link al sito principale. Card semplici + bottoni azione
(mailto, tel, WhatsApp).

## /admin (password dedicata `ADMIN_PASSWORD_HASH`)

Minimale, server-rendered:
- Elenco richieste d'ordine (data, cliente, piano, pezzi, totale, dettaglio snapshot,
  stato invio email) con paginazione.
- Ultimi sync (`sync_logs`) + pulsante "Sincronizza ora" (esegue il sync in
  foreground con feedback, o accoda al container cron).
- Toggle "Recommended" per SKU (ricerca per SKU → flag on/off).
- Niente altro in v1.
