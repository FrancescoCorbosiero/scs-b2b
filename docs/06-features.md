# 06 — Pagine e funzionalità

Il **sito pubblico** (`/`, `/spedizioni`, `/come-ordinare`, `/richiedi-accesso`)
è accessibile senza login ed è l'unica parte indicizzabile. Senza login si
apre anche la **vetrina** `/vetrina`: lo stesso catalogo SENZA prezzi,
`noindex` (vedi § /vetrina). Tutto il resto (catalogo con i prezzi, carrello,
area personale, admin) richiede la sessione catalogo attiva ed è `noindex`
(vedi `public/robots.txt` e docs/07).
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

## Sito pubblico (senza login, indicizzabile)

Pagine di presentazione servite da `PageController` con header/footer dedicati
(layout `public_page`), meta description + Open Graph e `robots: index, follow`.
**Non mostrano mai prezzi o prodotti del feed**: solo informazioni commerciali
(i brand sono nomi, che aprono la vetrina già filtrata). I prodotti si
vedono nella vetrina, mai su queste pagine indicizzabili.

- **`/` — home**: hero con claim e CTA (**"Sfoglia il catalogo"** → vetrina
  come azione principale, poi "Richiedi accesso" e "Come funziona"), numeri
  chiave (modelli, brand, giorni di consegna, paesi), elenco brand cliccabili
  (ognuno apre `/vetrina?brand=…`, più "Tutto il catalogo"), fascia in
  evidenza "Catalogo aperto" con il bottone per la vetrina, sei motivi per
  usare il catalogo, teaser dei 5 passi, blocco spedizioni, CTA finale. La
  vetrina è anche la prima voce del menu pubblico ("Catalogo", su desktop e
  mobile) e del footer; per chi ha già l'accesso gli stessi link portano a
  `/catalogo`.
- **`/come-ordinare`**: i 5 passi in timeline (registrazione → richiesta
  d'ordine → bonifico → conferma → ricezione), ognuno con testo, due punti
  chiave e icona; comparsa animata allo scroll. In fondo nota sui tempi e FAQ
  a fisarmonica (Alpine).
- **`/spedizioni`**: tempi (`SHIPPING_DAYS_MIN`–`SHIPPING_DAYS_MAX`, default
  4–5 giorni lavorativi), tabella costi (gratis da `FREE_SHIPPING_MIN_ITEMS`
  paia, altrimenti `SHIPPING_FEE`), copertura UE-27 + UK/CH, cosa succede dopo
  la conferma e dettagli operativi.
- **`/richiedi-accesso`**: modulo di richiesta profilo (vedi sotto).

Le pagine leggono i valori reali da `ShippingService` e `MIN_ORDER_ITEMS`:
cambiando `.env` cambia anche il testo pubblico, senza copia da riallineare.

**Immagini** (`App\Support\Images`, disponibili nei template come `images.*`):
sfondi delle intestazioni scure, texture della fascia brand, blocchi CTA
illustrati e mappa della rete di consegna. Di serie sono illustrazioni SVG in
`public/img/`; per passare a **foto reali** basta metterle in
`public/img/custom/` con lo stesso nome (`hero.jpg`, `cta.jpg`, `network.jpg`,
`pattern.png`) — vengono usate automaticamente, con cache-busting sul
timestamp del file e nessuna modifica ai template (istruzioni in
`public/img/custom/README.md`). Gli sfondi sono `<img>` posizionati, non
`background-image`: la CSP vieta gli style inline. Mai usare foto dei prodotti
del feed su queste pagine: sono indicizzabili.

## /vetrina — catalogo pubblico senza prezzi

Lo **stesso catalogo** di `/catalogo` (stessi template, stessi filtri, stessa
scheda rapida, stesse sezioni), aperto a chiunque senza login, ma **senza
alcun prezzo**. Header e footer sono quelli del sito pubblico. Serve a far
vedere ai potenziali rivenditori modelli, taglie e disponibilità reali prima
di chiedere l'accesso.

- **Nessun prezzo arriva al client**: `CatalogController` toglie i prezzi dai
  DATI prima del rendering (`price_from` delle card, `price` e `barcode`
  delle taglie nel JSON della scheda rapida e nei frammenti "Carica altri"),
  non solo dal markup. Niente export Excel, niente carrello.
- **Filtri e ordinamenti per prezzo ignorati lato server** (`prezzo_min`,
  `prezzo_max`, `ordina=prezzo_*`): una query string costruita a mano non
  permette di risalire ai prezzi per bisezione. Il pannello filtri non ha la
  sezione prezzo e il menu ordinamento non ha le voci per prezzo.
- Al posto del prezzo la card mostra "Riservato ai rivenditori" e il bottone
  "Vedi taglie" apre la scheda rapida con taglie e disponibilità; un banner
  e la scheda invitano a "Richiedi accesso" o "Accedi per i prezzi". Senza
  JS il bottone porta a `/richiedi-accesso`.
- Chi ha già la sessione catalogo viene rediretto a `/catalogo` con gli
  stessi filtri.
- `noindex` (meta robots) e `Disallow: /vetrina` in `robots.txt`: mostra i
  prodotti del feed, quindi resta fuori dai motori di ricerca come il resto
  del catalogo.
- Le preferenze taglie EU/US e densità griglia (`POST /taglie`, `/griglia`)
  sono pubbliche: solo sessione, con CSRF.

## /richiedi-accesso — profilazione self-service

Il cliente chiede l'attivazione inviando **dati aziendali** (ragione sociale,
P.IVA, indirizzo completo, paese) e **referente** (nome, email, telefono):
sono gli stessi dati che poi precompilano checkout, ricevuta e ordine dropship
GoldenSneakers, così non vanno richiesti a ogni ordine.

Difese: CSRF, honeypot, rate limit 3 richieste/ora per IP, blocco dei doppioni
(email già cliente → invito ad accedere; richiesta già in coda → nessun nuovo
record). La richiesta finisce in `account_requests` con stato `pending`; parte
un'email all'admin (italiano, con tutti i dati) e una conferma di ricezione al
cliente nel suo locale (nessun link riservato).

**Coda admin** (`/admin/richieste-profilo`, badge nella nav e scheda in
dashboard): **Approva** crea l'account con i dati della richiesta — indirizzo
incluso — e invia l'invito per impostare la password (`UserService`), oppure
**Rifiuta** archivia senza creare nulla. Entrambe le azioni sono one-shot: una
richiesta già gestita non si riapre.

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

## /catalogo

**Sezioni**: i prodotti del feed e i **prodotti propri** (importati da
`/admin/prodotti-propri`) non si mescolano. Quando esiste almeno un prodotto
proprio attivo, sopra la griglia compaiono due schede: **"Catalogo"** (il
feed) e **"Disponibili in sede"** (`?sezione=sede`). Filtri, conteggi dei
brand/taglie/categorie, ricerca, paginazione ed export lavorano dentro la
sezione scelta; senza prodotti propri la scheda non esiste e il catalogo è
quello di sempre. Carrello e richiesta d'ordine accettano entrambi: le righe
dei prodotti propri restano fuori dall'ordine a GoldenSneakers (docs/09) e
sono marcate "In sede" lato admin.

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

**Prodotti esauriti**: il fornitore tiene a feed anche ciò che ha finito
(docs/03), quindi il catalogo può contenere prodotti a stock 0. Non vengono
nascosti ma **marcati**: badge `ESAURITO`, immagine sbiadita, niente prezzo
"a partire da" e niente bottone d'ordine (nemmeno la scheda rapida si apre),
sempre **in fondo alla griglia** qualunque sia l'ordinamento, ed **esclusi
dall'export Excel** — che è il listino di ciò che si può ordinare, quindi
salta anche le singole taglie a quantità 0. L'interruttore "Solo disponibili"
li toglie del tutto dalla vista.

**IVA mai addebitata** (`VAT_ON_ORDER=0`, docs/04): il totale della richiesta
è `merce + spedizione` e coincide con l'importo da bonificare. Il messaggio è
ripetuto lungo tutto il percorso — banner catalogo, footer, riepilogo
carrello, anteprima del form ordine ("Totale da bonificare"), email con le
coordinate bancarie, ricevuta pro-forma, home e `/come-ordinare` — perché è
la promessa B2B della piattaforma.

**Azzera / Ripristina** (`components/_reset.twig` + `assets/js/app.js`): due
componenti riusabili presenti dove si seleziona o si configura qualcosa.
- lato client, un contenitore `data-reset-group` raccoglie i campi: `Azzera`
  li svuota, `Ripristina` li riporta al loro `data-default`. Con
  `data-reset-submit` il form parte subito (usato da ogni sezione del pannello
  filtri del catalogo, che così si azzera senza toccare gli altri filtri);
- lato server, per i dati salvati a DB, i bottoni sono piccoli form POST (quindi
  funzionano anche senza JS): in `/admin/margini` azzerano o ripristinano le
  regole margine (set di partenza, migrazione 0006), il margine di default e le
  aliquote VAT (tutte o per singolo paese).

**Pannello filtri** (`catalog/_filters.twig`): un unico blocco DOM che su
desktop è un rail sticky e su mobile un drawer a scorrimento. I campi sono
legati al form GET `#catalog-filters` con l'attributo `form=`, così lo stato
resta interamente nella query string (URL condivisibili) senza annidare form:
- **Brand** con conteggi, stato attivo e ricerca client-side
- **Categoria taglia**: Normali (adulti) / GS (ragazzi) / PS (bambini), con
  conteggio prodotti; selezione multipla (OR). La categoria è dedotta a sync
  dal feed (vedi docs/03 § Categoria di taglia) e finisce anche come badge
  sulla card e come colonna dell'export
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
bottone "Annulla" trattiene l'invio REALE (niente email né ordine automatico
al fornitore finché non scade); senza JavaScript l'invio è diretto.

All'invio (stato `pending`), in quest'ordine:
1. Rivalidare carrello vs stock corrente; risolvere il VAT per paese/P.IVA;
   salvare `order_requests` (snapshot completo + imponibile/VAT/totale +
   indirizzo). NIENTE numero ricevuta a questo stadio.
2. **Ordine automatico GoldenSneakers** (se `AUTO_DROPSHIP_ON_REQUEST=1`):
   crea subito l'ordine presso il fornitore con l'API ordini
   (`POST /api/orders/create/`) e l'indirizzo del cliente, per bloccare lo
   stock prima che arrivi il bonifico (vedi docs/09 § Ordine automatico; in
   `DROPSHIP_MODE=simulation` nessun ordine parte). Le righe di prodotti
   propri restano fuori. L'esito è riportato nell'email admin; un fallimento
   non blocca mai la richiesta.
3. Email admin a `ADMIN_EMAIL`, sempre in italiano: tabella completa (righe
   "IN SEDE" evidenziate), paese, P.IVA, indirizzo, esito dell'ordine
   GoldenSneakers (numero; totale e spedizione del fornitore solo con
   `ADMIN_EMAIL_SHOW_COST=1`), promemoria "conferma alla ricezione del
   pagamento"; se `ADMIN_EMAIL_SHOW_COST=1` anche costo e margine.
4. Email al cliente nella sua lingua (IT/EN): riepilogo + **istruzioni di
   pagamento** (coordinate, importo, causale "Richiesta ordine #id") con
   l'avviso esplicito che l'ordine si conferma alla ricezione del pagamento.
   Nessun allegato.
5. Svuotare il carrello → pagina di conferma con recapiti.

**Conferma admin** (`POST /admin/richieste/{id}/conferma`, dopo verifica
dell'accredito): stato `confirmed`, assegnazione del numero ricevuta
(PF-<anno>-<NNNN>) e **email di conferma al cliente con la ricevuta pro-forma
PDF in allegato** (dompdf; scaricabile anche da /admin). Con l'IVA non
addebitata la ricevuta porta la **Nota IVA** col totale che la richiesta
avrebbe con l'IVA di legge, solo indicativo (docs/04). **Annulla**
(`/annulla`): stato `cancelled`, nessuna email.

**Riallineamento admin** (`/admin/richieste/{id}/modifica`, solo `pending`):
se lo stock cambia durante l'attesa del bonifico, l'admin corregge le
quantità riga per riga (0 = rimuovi; stock corrente mostrato a fianco, righe
oltre stock evidenziate); prezzi unitari quotati invariati, totali, spedizione
(la soglia gratis vale sui nuovi pezzi) e VAT ricalcolati; con la spunta
"rinotifica" il cliente riceve le istruzioni di
pagamento AGGIORNATE. Poi conferma/annulla come sempre. Con l'ordine
automatico attivo il caso è raro (lo stock è bloccato subito): è la rete di
sicurezza.

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
- **/admin/ordini-fornitore — Ordini GoldenSneakers** (docs/09): elenco in
  tempo reale degli ordini dell'account dall'API ordini (stato, totale,
  pagamento al fornitore, pro-forma/fattura disponibili), collegato alle
  richieste della piattaforma; dettaglio live per ordine con righe,
  indirizzi, link a pro-forma e fattura e pagamento; registro degli ordini
  creati dalla piattaforma con gli esiti incerti (`UNKNOWN`) in evidenza.
- **/admin/prodotti-propri — Prodotti propri**: import da file **JSON o CSV
  nello stesso formato del feed** (una riga per SKU+taglia, colonne `sku`,
  `product_name`, `brand_name`, `size_mapper_name`, `size_eu`, `size_us`,
  `barcode`, `offer_price`, `available_quantity`, `image_full_url`/`image`,
  `image_name`; obbligatorie `sku`, `product_name`, `size_eu`, `offer_price`,
  `available_quantity`; le colonne in più vengono ignorate). Modello CSV
  (separatore ";", virgola decimale, BOM: si apre in colonne con Excel) ed
  esempio JSON scaricabili dalla pagina.
  - Validazione **tutto o niente** con le regole del feed
    (`GoldenSneakersAdapter::normalizeRow`) più: SKU già usati dal feed
    rifiutati (un prodotto proprio non si fonde mai con uno del fornitore),
    taglia ripetuta per lo stesso SKU rifiutata; gli errori arrivano in un
    unico elenco con il numero di riga del foglio. File max 5 MB / 10.000
    righe, letto in memoria e mai salvato. Excel: tollerati BOM, ";",
    virgola decimale (89,90 e 42,5) e file Windows-1252.
  - Prezzo **come per il feed**: `offer_price` (il costo) + regole di
    `/admin/margini` (per un prezzo esatto: regola "Prezzo fisso" sullo SKU);
    categoria di taglia dedotta allo stesso modo. Il reprice li include.
  - Immagini: URL `https` su `goldensneakers.net` o `shoesclothingstore.com`
    (e sottodomini; deve coincidere con la CSP); con `image_name` vuoto si
    usa il file di `image_full_url`. Fuori dominio ⇒ segnaposto.
  - "Sostituisci l'elenco" (facoltativo): i prodotti propri assenti dal
    file vengono nascosti, come fa il feed coi suoi; senza, il file aggiunge
    o aggiorna e basta. Per ogni prodotto: Nascondi/Mostra ed Elimina (le
    richieste passate non cambiano: hanno lo snapshot).
  - Separazione dal feed: `products.source = 'custom'`; il sync del feed non
    li aggiorna né li disattiva e salta, segnalandolo nel log del sync, uno
    SKU del feed che appartenga a un prodotto proprio; l'import non tocca
    mai i prodotti del feed; nessun `size_id` del fornitore (l'`id` del file
    è ignorato), quindi mai ordinati a GoldenSneakers.
- Ultimi sync (`sync_logs`) + pulsante "Sincronizza ora" (esegue il sync in
  foreground con feedback, o accoda al container cron).
- Toggle "Recommended" per SKU (ricerca per SKU → flag on/off).
