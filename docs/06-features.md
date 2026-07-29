# 06 — Pagine e funzionalità

Tutte le rotte (tranne `/login`) richiedono la sessione catalogo attiva.
UI multi-lingua **IT/EN** (default italiano): stringhe in `lang/it.php` +
`lang/en.php`, switcher in header, preferenza in sessione. Mobile-first: i
clienti usano molto lo smartphone. Nav con: Catalogo, Carrello (con badge
conteggio pezzi), Contatti, **selettore paese di residenza** (default IT,
determina il VAT alla richiesta d'ordine), selettore lingua, logout.
Ovunque i prezzi sono **VAT esclusa**, con dicitura esplicita (banner
catalogo + footer).

**Convenzione righe cliccabili**: ovunque un record abbia una pagina di
dettaglio, si apre cliccando **l'intera riga/scheda**, non solo il link `#id`.
Il pattern è `data-row-link="<url>"` sul `<tr>` (gestito in `assets/js/app.js`;
`data-row-link-target="_blank"` per aprire in nuova scheda), più:
riga evidenziata in hover/focus, colonna finale "Apri ›" come affordance e il
link vero nella prima cella per tastiera e no-JS. Click su link, bottoni o
campi interni alla riga continuano a fare la loro azione; ctrl/cmd/tasto
centrale aprono in una nuova scheda come su un link normale.

## /login
Login con **account personale** (email + password) e, finché
`GUEST_LOGIN_ENABLED=1`, modalità ospite con la password condivisa
(toggle nella stessa pagina). "Password dimenticata?" → `/password-dimenticata`
(risposta neutra). Rate limited (vedi 07). Gli account si creano solo da
`/admin/clienti` con invito via email (link monouso 72h per impostare la
password su `/account/imposta-password`).

## /account (area personale — richiede account, non ospite)
- **Profilo**: nome, azienda, telefono, indirizzo, paese, P.IVA, lingua
  (email modificabile solo dall'admin) → precompila il checkout; al login le
  preferenze paese/lingua del profilo diventano quelle della sessione.
- **Cambio password** (con verifica dell'attuale).
- **/account/ordini**: richieste con stato e totali; **ricevuta pro-forma PDF**
  scaricabile per gli ordini confermati (ownership verificata per user_id o
  email; mai offer_price).

## / (Catalogo)

Grid di card prodotto (`catalog/_card.twig`, riusata dal frammento
`_cards.twig`). Ogni card:
- immagine (lazy, fallback placeholder, zoom in hover) con badge "Recommended"
  e **pillola disponibilità** colorata (alta/media/bassa + pezzi totali)
- SKU con pulsante copia-negli-appunti, nome su 2 righe, brand cliccabile
- strip delle **taglie in stock** (`42·11`), nel sistema taglie attivo (EU default)
- prezzo "a partire da X €" (min tra le taglie, netto di listino, VAT esclusa)
- CTA **"Scegli taglie"** → apre la scheda rapida; senza JS resta il POST
  `/carrello/aggiungi` che porta al carrello

**Scheda rapida (quick view)** — `catalog/_quickview.twig`: si apre dalla card
(immagine o CTA) e mostra tutte le taglie con stock, prezzo netto per taglia e
uno stepper quantità. Il totale pezzi/importo si aggiorna live; "Aggiungi al
carrello" scrive le quantità nel carrello di sessione via `/carrello/aggiorna`
(una chiamata per taglia), aggiorna il badge e mostra un toast — senza mai
lasciare il catalogo. `Esc` o clic fuori chiudono. Dati esposti: SOLO stock e
prezzi netti (mai offer_price).

**Pannello filtri** (`catalog/_filters.twig`): un unico blocco DOM che su
desktop è un rail sticky e su mobile un drawer a scorrimento. I campi sono
legati al form GET `#catalog-filters` con l'attributo `form=`, così lo stato
resta interamente nella query string (URL condivisibili) senza annidare form:
- **Brand** con conteggi, stato attivo e ricerca client-side
- **Taglie** (faccette con conteggio prodotti, solo taglie con stock): il
  prodotto passa se ha stock in almeno una delle taglie scelte
- **Prezzo** min–max (sul prezzo netto di listino)
- **Disponibilità**: Tutte / Alta / Media / Bassa — soglie da `.env`
  (`AVAILABILITY_HIGH_MIN=60`, `AVAILABILITY_LOW_MAX=20` sul totale pezzi)
- Interruttori **Recommended** e **Solo disponibili**

Toolbar sticky sopra la griglia: ricerca per nome/SKU (debounce 300ms, scorciatoia
`/`), ordinamento (rilevanza/nome, prezzo ↑↓, disponibilità ↓), toggle taglie
**EU/US** e densità griglia **grande/media/compatta** (entrambi persistono in
sessione), più i **chip dei filtri attivi**: ognuno si rimuove da solo con la
sua × e c'è "Azzera filtri".

Ogni cambio filtro invia il form (barra di avanzamento + griglia in dissolvenza
come feedback) e ripulisce l'URL dai parametri vuoti o di default. Senza JS
resta il bottone "Applica" (in `<noscript>`).

Altro:
- **Paginazione**: `PRODUCTS_PER_PAGE` (24/pagina). Con JS diventa "Carica altri
  prodotti" — il client chiede `?...&fragment=1&page=N` (solo le card) e le
  accoda, proseguendo poi in automatico allo scroll; senza JS restano i link
  Precedente/Successiva.
- Animazioni: comparsa a cascata delle card, hover con sollevamento e zoom
  immagine, transizioni della scheda rapida, toast, "torna su". Tutto disattivato
  con `prefers-reduced-motion` (vedi `assets/css/app.css`; niente style inline,
  la CSP vieta `style-src 'unsafe-inline'`).
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
- Riepilogo laterale (sticky): pezzi totali, **totale netto (VAT esclusa)**,
  **spedizione**, VAT stimata e totale, con nota sul paese e avviso "Ordine
  minimo: 5 pezzi" (`MIN_ORDER_ITEMS=5`) con CTA disabilitata sotto soglia.
- **Spedizione**: gratuita da `FREE_SHIPPING_MIN_ITEMS=7` paia in su, altrimenti
  forfait `SHIPPING_FEE=10.00` (netto, VAT esclusa). Il riquadro mostra la regola
  e quanti paia mancano al gratis; l'importo si aggiorna con le quantità (JS) ma
  il calcolo autoritativo è server-side (`ShippingService`). Essendo spesa
  accessoria alla cessione, entra nell'imponibile VAT insieme alla merce.
- Persistenza: sessione server-side; sopravvive a refresh e navigazione.

## /richiesta-ordine (dal carrello) — ciclo di vita a stati

Form: nome*, azienda, email*, telefono*, **indirizzo di spedizione***
(via/civico, città, CAP), **paese di residenza*** (precompilato dal selettore
in header), **partita IVA** (facoltativa), note. Honeypot + CSRF + rate limit
(max 3 invii/ora per sessione/IP). Il riepilogo mostra un'anteprima live di
imponibile / **spedizione** / VAT / totale che reagisce a paese e P.IVA (JS, dati pubblici);
il calcolo autoritativo resta server-side (`VatService`, docs/04). Un banner
esplicita che il pagamento avviene SOLO via bonifico e che **l'ordine viene
confermato all'arrivo del pagamento**, con modal "Come funziona" (coordinate
bancarie da `BANK_*` in `.env`).

**Stati**: `pending` (in attesa di pagamento) → `confirmed` / `cancelled`.

**Finestra di ripensamento**: al submit un countdown di 15 secondi con
bottone "Annulla" trattiene l'invio REALE (niente email né auto-dropship
finché non scade); senza JavaScript l'invio è diretto.

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

**Riallineamento admin** (`/admin/richieste/{id}/modifica`, solo `pending`):
se lo stock cambia durante l'attesa del bonifico, l'admin corregge le
quantità riga per riga (0 = rimuovi; stock corrente mostrato a fianco, righe
oltre stock evidenziate); prezzi unitari quotati invariati, totali, spedizione
(la soglia gratis vale sui nuovi pezzi) e VAT ricalcolati; con la spunta
"rinotifica" il cliente riceve le istruzioni di
pagamento AGGIORNATE. Poi conferma/annulla come sempre. Con l'auto-dropship
attivo il caso è raro (lo stock è bloccato subito): è la rete di sicurezza.

Un fallimento SMTP NON deve perdere la richiesta né la conferma (già a DB,
flag `email_*_sent=0` / flash admin, log dell'errore).

## /contatti

Recapiti da config (valori in `01-overview.md`): email, telefono, WhatsApp
(`wa.me`), sede, P.IVA, link al sito principale. Card semplici + bottoni azione
(mailto, tel, WhatsApp). Sezione **Pagamenti — Bonifico bancario** con le
coordinate da `BANK_*` (con `BANK_IBAN` vuoto: invito a contattarci).

## /admin (password dedicata `ADMIN_PASSWORD_HASH`)

Minimale, server-rendered (sempre in italiano):
- **/admin/clienti — gestione account**: lista con stato (attivo / invito in
  attesa / disattivato), ultimo accesso e conteggio ordini; creazione con
  invio automatico dell'invito nella lingua del cliente; reinvio invito o
  reset; disattivazione immediata (le sessioni attive decadono).
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
