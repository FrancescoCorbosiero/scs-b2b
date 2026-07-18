# 06 — Pagine e funzionalità

Tutte le rotte (tranne `/login`) richiedono la sessione catalogo attiva.
UI multi-lingua **IT/EN** (default italiano): stringhe in `lang/it.php` +
`lang/en.php`, switcher in header, preferenza in sessione. Mobile-first: i
clienti usano molto lo smartphone. Nav con: Catalogo, Carrello (con badge
conteggio pezzi), Contatti, **selettore paese di residenza** (default IT,
determina il VAT alla richiesta d'ordine), selettore lingua, logout.
Ovunque i prezzi sono **VAT esclusa**, con dicitura esplicita (banner
catalogo + footer).

## /login
Campo password unico + "ricordami". Rate limited (vedi 07). Nessun hint sulla password.

## / (Catalogo)

Grid di card prodotto. Ogni card:
- immagine (lazy, fallback placeholder), badge "Recommended" se flag attivo
- SKU con pulsante copia-negli-appunti, nome prodotto, brand
- "Disponibilità totale: N"
- chips taglia→qty (es. `36 2/3 · 1`), nel sistema taglie attivo (EU default)
- prezzo "da X €" (min tra le taglie, prezzo netto di listino, VAT esclusa)
- CTA "Aggiungi al carrello" → aggiunge il prodotto e porta/espande la vista carrello
  del prodotto (o modale con la tabella taglie)

**Navigazione brand dedicata**: sidebar sticky su desktop (elenco brand con
conteggio prodotti, stato attivo evidenziato, ricerca client-side oltre gli
8 brand) e chips scorrevoli su mobile; i link preservano gli altri filtri.
Il brand sulla card è cliccabile.

Toolbar filtri (stato su query string → URL condivisibili, enhancement Alpine):
- Disponibilità: Alta / Media / Bassa — soglie da `.env`
  (`AVAILABILITY_HIGH_MIN=60`, `AVAILABILITY_LOW_MAX=20` sul totale pezzi)
- Recommended (toggle)
- Prezzo min–max (sul prezzo netto di listino)
- Ricerca per nome/SKU (debounce 300ms)
- Toggle taglie **EU / US** (persiste in sessione)
- Ordinamento: rilevanza/nome, prezzo ↑↓, disponibilità ↓
- Paginazione 24/pagina
- **Export Excel** del risultato filtrato: SKU, nome, brand, taglia EU, taglia US,
  barcode, qty, prezzo netto (colonna marcata "VAT esclusa"). MAI offer_price.

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
- Riepilogo laterale (sticky): pezzi totali, **totale netto (VAT esclusa)** con
  nota sul paese, avviso "Ordine minimo: 5 pezzi" (`MIN_ORDER_ITEMS=5`) con CTA
  disabilitata sotto soglia.
- Persistenza: sessione server-side; sopravvive a refresh e navigazione.

## /richiesta-ordine (dal carrello) — ciclo di vita a stati

Form: nome*, azienda, email*, telefono*, **indirizzo di spedizione***
(via/civico, città, CAP), **paese di residenza*** (precompilato dal selettore
in header), **partita IVA** (facoltativa), note. Honeypot + CSRF + rate limit
(max 3 invii/ora per sessione/IP). Il riepilogo mostra un'anteprima live di
imponibile / VAT / totale che reagisce a paese e P.IVA (JS, dati pubblici);
il calcolo autoritativo resta server-side (`VatService`, docs/04). Un banner
esplicita che il pagamento avviene SOLO via bonifico e che **l'ordine viene
confermato all'arrivo del pagamento**, con modal "Come funziona" (coordinate
bancarie da `BANK_*` in `.env`).

**Stati**: `pending` (in attesa di pagamento) → `confirmed` / `cancelled`.

All'invio (stato `pending`), in quest'ordine:
1. Rivalidare carrello vs stock corrente; risolvere il VAT per paese/P.IVA;
   salvare `order_requests` (snapshot completo + imponibile/VAT/totale +
   indirizzo). NIENTE numero ricevuta a questo stadio.
2. **Auto-dropship** (se `AUTO_DROPSHIP_ON_REQUEST=1`): crea subito l'ordine
   presso GoldenSneakers con l'indirizzo del cliente per bloccare lo stock
   prima che arrivi il bonifico (vedi docs/09 § Auto-dropship; in
   `DROPSHIP_MODE=simulation` nessuna chiamata parte). L'esito è riportato
   nell'email admin; un fallimento non blocca mai la richiesta.
3. Email admin a `ADMIN_EMAIL`, sempre in italiano: tabella completa, paese,
   P.IVA, indirizzo, esito auto-dropship, promemoria "conferma alla ricezione
   del pagamento"; se `ADMIN_EMAIL_SHOW_COST=1` anche costo e margine.
4. Email al cliente nella sua lingua (IT/EN): riepilogo + **istruzioni di
   pagamento** (coordinate, importo, causale "Richiesta ordine #id") con
   l'avviso esplicito che l'ordine si conferma alla ricezione del pagamento.
   Nessun allegato.
5. Svuotare il carrello → pagina di conferma con recapiti.

**Conferma admin** (`POST /admin/richieste/{id}/conferma`, dopo verifica
dell'accredito): stato `confirmed`, assegnazione del numero ricevuta
(PF-<anno>-<NNNN>) e **email di conferma al cliente con la ricevuta pro-forma
PDF in allegato** (dompdf; scaricabile anche da /admin). **Annulla**
(`/annulla`): stato `cancelled`, nessuna email.

Un fallimento SMTP NON deve perdere la richiesta né la conferma (già a DB,
flag `email_*_sent=0` / flash admin, log dell'errore).

## /contatti

Recapiti da config (valori in `01-overview.md`): email, telefono, WhatsApp
(`wa.me`), sede, P.IVA, link al sito principale. Card semplici + bottoni azione
(mailto, tel, WhatsApp). Sezione **Pagamenti — Bonifico bancario** con le
coordinate da `BANK_*` (con `BANK_IBAN` vuoto: invito a contattarci).

## /admin (password dedicata `ADMIN_PASSWORD_HASH`)

Minimale, server-rendered (sempre in italiano):
- Elenco richieste d'ordine (stato del ciclo con badge e filtro, data, cliente,
  paese/regime VAT, numero ricevuta, pezzi, imponibile, stato invio email) con
  paginazione; dashboard con contatore "in attesa di pagamento"; dettaglio con
  snapshot, indirizzo di spedizione, totali imponibile/VAT/lordo, costo
  fornitore, margine, **bottoni Conferma (pagamento ricevuto) / Annulla** e
  **download della ricevuta pro-forma PDF**.
- **/admin/margini — gestione margini** (docs/04): regole per brand o
  nome-contiene (percentuale o importo fisso, priorità, attiva/disattiva,
  conteggio prodotti corrispondenti), margine di default, aliquote VAT per
  paese. Ogni modifica alle regole ricalcola subito i prezzi (reprice).
- Ultimi sync (`sync_logs`) + pulsante "Sincronizza ora" (esegue il sync in
  foreground con feedback, o accoda al container cron).
- Toggle "Recommended" per SKU (ricerca per SKU → flag on/off).
