# 09 — Ordini GoldenSneakers (API ordini)

Quando un cliente invia una richiesta d'ordine, la piattaforma crea l'ordine
direttamente presso GoldenSneakers, che spedisce all'indirizzo del cliente:
lo stock del fornitore viene impegnato alla fonte e resta allineato col
nostro catalogo. Nel codice, nella tabella `dropship_orders` e nelle
variabili `.env` il nome resta "dropship" (il fornitore spedisce direttamente
al nostro cliente), ma l'API è quella degli **ordini** (`/api/orders/`).

> **Decisione del titolare del 02/10/2026**: gli ordini si creano con l'API
> ordini GoldenSneakers — POST `/api/orders/create/` — e la piattaforma
> integra anche elenco (GET `/api/orders/`) e dettaglio
> (GET `/api/orders/{id}/`) per seguirne stato, pro-forma, fattura e
> pagamento. Sostituisce la decisione del 29/09/2026 ("sempre ordini
> orders-dropship, wholesale non usato"): l'API orders-dropship resta solo
> per rileggere gli ordini registrati prima (§ Ordini storici).

> ⚠ **Stato: live IMPLEMENTATO, verificato contro un mock locale con le
> risposte d'esempio del fornitore, NON ancora con un ordine reale.** I
> client (`src/Adapter/GoldenSneakersOrdersClient.php` e, per gli storici,
> `GoldenSneakersDropshipClient.php`, entrambi su `GoldenSneakersApiClient`)
> hanno due modalità: con `DROPSHIP_MODE=simulation` (default; qualsiasi
> valore ≠ `live` degrada qui) la creazione **non parte mai** e la risposta è
> fittizia; con `DROPSHIP_MODE=live` invia davvero (bearer
> `FEED_BEARER_TOKEN`). Creare un ordine reale è **irreversibile** (il
> fornitore lo prende in carico e impegna il suo stock): PRIMA di mettere
> `live` in produzione va completata la checklist in fondo a questo
> documento. I path sono **fissi nel codice** (costanti `*_PATH` dei client).

## Comportamento della modalità live (soldi veri: leggere prima di attivare)

Regole comuni ai due client (`GoldenSneakersApiClient`):

- **Mai retry sulla POST di creazione**: un timeout dopo l'invio non può
  distinguere "ordine creato" da "ordine perso"; ritentare alla cieca rischia
  un ordine doppio con addebito reale.
- **Esiti incerti tracciati**: timeout dopo l'invio, HTTP 5xx o risposta 2xx
  senza `order_id` leggibile sollevano `DropshipUncertainException`; il
  service registra una riga in `dropship_orders` con **status `UNKNOWN`**
  (payload incluso) e scarta la bozza. L'admin verifica sul portale
  GoldenSneakers se l'ordine esiste PRIMA di ricominciare il flusso; gli
  UNKNOWN sono evidenziati in `/admin/ordini-fornitore`.
- **Fallimenti certi**: fornitore irraggiungibile (DNS/connect/TLS), HTTP 4xx
  (rifiuto esplicito, con messaggio del fornitore riportato) o redirect 3xx
  (endpoint sbagliato) ⇒ nessun ordine creato, si può correggere e ripetere.
- **Tetto di spesa opzionale** `DROPSHIP_MAX_ORDER_EUR`: se il costo
  fornitore stimato supera il tetto l'invio è rifiutato prima della chiamata.
- **Letture (GET) idempotenti**: un retry con backoff; gli errori di lettura
  non modificano nulla. Con l'API ordini le letture partono **anche in
  simulazione** purché ci sia il token (elenco e dettaglio degli ordini reali
  dell'account sono informazioni, non effetti); non si rileggono mai gli
  ordini registrati in simulazione, che hanno ID fittizi.
- La paginazione dell'elenco (`{results, next}`) si segue **solo sullo stesso
  schema e host di `FEED_BASE_URL`**: il token non parte mai verso altri host.
- Link di pro-forma e fattura mostrati solo se `https` sul dominio
  `goldensneakers.net` (i relativi si risolvono contro `FEED_BASE_URL`).

## Endpoint (base `FEED_BASE_URL` = https://www.goldensneakers.net)

Documentazione: https://www.goldensneakers.net/api/docs/ (tag
`wholesale-orders` e `dropshipping-orders`). Auth: `Authorization: Bearer
<FEED_BEARER_TOKEN>`, lo stesso token del feed.

### API ordini — `GoldenSneakersOrdersClient` (in uso)

| Method | Path | Uso |
|---|---|---|
| POST | `/api/orders/create/` | creazione ordine (richiesta del cliente, automatica o dal flusso admin) |
| GET | `/api/orders/` | elenco ordini dell'account (`/admin/ordini-fornitore`) |
| GET | `/api/orders/{order_id}/` | dettaglio: righe, indirizzi, pro-forma, fattura, pagamento |

- **Creazione** — payload (esempio del fornitore, 02/10/2026):

  ```json
  {
    "currency": "EUR",
    "shipping_address": {
      "recipient_name": "Mario Rossi",
      "address_l1": "Via Roma 1",
      "address_l2": "",
      "city": "Milano",
      "zip_code": "20121",
      "country": "IT",
      "phone": "+390401234567",
      "email": "info@sneakershop.it"
    },
    "items": [
      { "size_id": 12280, "quantity": 6 },
      { "sku": "B75806", "size_us": "4", "quantity": 4 }
    ]
  }
  ```

  Risposta: `{ "order_id": 3125, "status": "UNCONFIRMED", "currency": "EUR",
  "total_amount": 585, "created_at": "2025-05-04T09:00:00Z", "shipping_cost": 15,
  "free_shipping": false, "payment_status": "unpaid" }`. Nessun prezzo nel
  payload: il totale (spedizione del fornitore compresa) lo calcola
  GoldenSneakers. `currency` è sempre `EUR`.

- **Elenco** — lista di `{ order_id, status, currency, total_amount,
  created_at, payment_status, is_paid, has_proforma, has_invoice }`;
  accettata anche la forma paginata `{results, next}`.

- **Dettaglio** — `order_id`, `status`, `currency`, `total_amount`,
  `created_at`, `billing` (la NOSTRA intestazione presso il fornitore: `name`,
  `vat_id`, `full_vat_id`, indirizzo, `email`, `phone`), `shipping_address`
  (come nel payload), `items[]` (`size_id`, `sku`, `product_name`, `size_us`,
  `quantity`, `unit_price`, `total_price` — costi fornitore, SOLO area admin),
  `proforma` e `invoice` (`{url, symbol, uploaded_at}` oppure `null`; l'URL
  apre il portale GoldenSneakers e richiede l'accesso all'account),
  `payment` (`status`, `is_paid`, `paid_amount`, `total_amount`, `currency`,
  `due_date`): è il NOSTRO pagamento al fornitore, mai mostrato al cliente.

### API orders-dropship — `GoldenSneakersDropshipClient` (solo ordini storici)

Gli ordini registrati prima del 02/10/2026 (`dropship_orders.api =
'dropship'`) si rileggono ancora con la loro API: order-details
(`/api/orders-dropship/order-details/{id}/`), package-details
(`/api/orders-dropship/package-details/{id}/`) e, per quelli creati con
`client_provides_shipping_label=True`, upload-shipping-label
(`/api/orders-dropship/upload-shipping-label/{id}/`, multipart, una sola
volta per ordine). La creazione (`/api/orders-dropship/create-order/`) resta
nel client ma la piattaforma non la usa più. Le vecchie variabili
`DROPSHIP_*_ENDPOINT` di un `.env` sono ignorate e vanno tolte.

## Stati ordine e pagamento

| Stato | Significato |
|---|---|
| `UNCONFIRMED` | creato, non ancora confermato dal fornitore |
| `TO_SHIP` | confermato, pronto alla spedizione |
| `ENDED` | completato e consegnato |
| `CANCELED` | annullato |
| `WAITING_FOR_INVOICE` | in attesa di fatturazione |
| `UNKNOWN` | solo locale: esito della creazione incerto, da verificare sul portale |

Gli stati fuori da questa lista non sovrascrivono quello salvato (restano
visibili nello snapshot del dettaglio). Lo stato del **pagamento al
fornitore** (`payment_status`, es. `unpaid`/`paid`, e `is_paid`) arriva
dall'API ordini e si salva sulla riga: valori nuovi si mostrano così come
arrivano.

## Identificazione delle taglie: `size_id`

Gli item accettano `size_id` **oppure** `sku` + `size_us`. Il `size_id` è l'`id`
riga del feed assortment-flat (una riga per SKU+taglia): dal sync viene salvato
in `product_sizes.supplier_size_id` (migrazione `0002_dropship.sql`). Il payload
usa `size_id` quando disponibile e ripiega su `sku`+`size_us`; una riga senza
né `size_id` né `size_us` non è ordinabile (serve un sync del feed). I
prodotti propri non hanno mai un `size_id` (l'`id` dei file importati viene
ignorato) e non sono mai ordinabili al fornitore, nemmeno via `sku`+`size_us`:
l'esclusione guarda l'origine della riga (`source`), sullo snapshot e sul
catalogo corrente.

## Flusso in /admin

### Ordine manuale dalla richiesta (tripla conferma)

Dal dettaglio di una richiesta d'ordine (`/admin/richieste/{id}`), card
"Ordine GoldenSneakers" → tre step, **tutti rivalidati lato server**
(`DropshipOrderService`), perché l'invio reale crea l'ordine:

1. **Prepara** (`GET /admin/richieste/{id}/dropship`): `shipping_address`
   precompilato coi dati del cliente (destinatario, `address_l1`, `address_l2`
   facoltativo, città, CAP, paese, telefono, email) e righe del carrello
   verificate contro stock, `size_id` e origine correnti; quantità
   modificabili (0 = escludi riga). Le righe di **prodotti propri** (docs/06)
   sono marcate e non ordinabili: non sono del fornitore.
2. **Riepilogo** (`POST …/dropship/riepilogo`): payload JSON esatto che
   verrebbe inviato, stima a costo fornitore e **tre caselle di conferma
   obbligatorie** (indirizzo verificato, righe verificate, consapevolezza
   dell'irreversibilità). La bozza vive in sessione con token monouso e scade
   dopo 15 minuti.
3. **Conferma definitiva** (`POST …/dropship/conferma`): va digitata la frase
   `CONFERMA <id richiesta>`; il bottone resta disabilitato finché non
   corrisponde e la frase è riverificata dal server all'invio
   (`POST …/dropship/invia`).

L'esito viene registrato in `dropship_orders` (`api = 'orders'`, payload
esatto, risposta, snapshot righe, stato, totale e spedizione del fornitore,
stato del pagamento, modalità) e mostrato in `/admin/dropship/{id}` con badge
**SIMULAZIONE** quando `mode=simulation`. "Aggiorna stato dal fornitore"
legge il dettaglio (`GET /api/orders/{id}/`) e aggiorna stato, pagamento e
totale; lo snapshot completo finisce in `details_payload` e la pagina mostra
righe, pro-forma, fattura, pagamento e indirizzi come li ha il fornitore.

### Ordini GoldenSneakers (`/admin/ordini-fornitore`)

- **Sul tuo account GoldenSneakers**: elenco letto in tempo reale da
  `GET /api/orders/` (stato, totale, pagamento, pro-forma/fattura
  disponibili), collegato alle richieste d'ordine della piattaforma quando
  l'ordine è nato da lì (solo righe `live`: gli ID simulati non si collegano
  mai). Ogni riga apre `/admin/ordini-fornitore/{id}`: dettaglio live con
  righe, indirizzi, link a pro-forma e fattura, pagamento.
- **Creati dalla piattaforma**: registro locale (ultimi 30), simulazioni
  comprese, con gli esiti `UNKNOWN` in evidenza.

Senza `FEED_BEARER_TOKEN` l'elenco non è leggibile e la pagina lo dice;
in simulazione un avviso ricorda che le richieste non creano ordini.

## Configurazione (.env)

| Variabile | Uso |
|---|---|
| `DROPSHIP_ENABLED` | `1` mostra la sezione in /admin e abilita gli ordini al fornitore (default 0) |
| `DROPSHIP_MODE` | `simulation` (default) — qualsiasi altro valore ≠ `live` degrada a simulazione; `live` crea davvero gli ordini |
| `DROPSHIP_HTTP_TIMEOUT` | timeout in secondi delle chiamate (default 30, min 5) |
| `DROPSHIP_MAX_ORDER_EUR` | tetto sul costo fornitore stimato di un ordine; oltre ⇒ invio rifiutato prima della chiamata (0 = nessun tetto) |
| `AUTO_DROPSHIP_ON_REQUEST` | `1` crea l'ordine a ogni richiesta del cliente (flusso di default) |
| `AUTO_DROPSHIP_ALLOW_LIVE` | `1` (valore del flusso standard in `.env.example`) permette all'invio automatico di partire in live; con 0 **o riga assente** in live l'automatico rifiuta e resta solo il flusso manuale |

Le variabili sono le stesse di prima del 02/10/2026 e valgono per l'API
ordini: **un `.env` già in `live` con l'invio automatico attivo crea gli
ordini con `/api/orders/create/` dal primo deploy**. Auth: bearer
`FEED_BEARER_TOKEN` (lo stesso del feed); senza token nessuna chiamata parte.
Base URL: `FEED_BASE_URL`. I path non sono configurabili.

## Dropshipping per il rivenditore (consegna al SUO cliente finale)

Al checkout il rivenditore sceglie la consegna (default: al proprio
indirizzo, comportamento storico):

- **"A un mio cliente (dropshipping)"** (`ship_to=customer`): compila un
  destinatario dedicato (nome, indirizzo, paese, telefono) salvato nei campi
  `recipient_*` di `order_requests` (migrazione `0010`). L'ordine
  GoldenSneakers (manuale o automatico) parte con QUEL `shipping_address`;
  l'email di contatto verso il fornitore resta quella del rivenditore (il
  cliente finale non riceve comunicazioni). Il VAT continua a calcolarsi sul
  paese del RIVENDITORE (è lui il nostro cliente B2B), non su quello di
  consegna.
- **"Fornirò io l'etichetta"** — ⚠ **NASCOSTA** (decisione del 06/08/2026) e
  ora anche **non prevista dall'API ordini**, che non ha un equivalente di
  `client_provides_shipping_label`: spedizione ed etichetta le gestisce
  sempre GoldenSneakers. Checkbox rimossa dal checkout
  (`templates/order/form.twig`) e flag forzato a `false` in
  `OrderService::submit()`. L'upload etichetta + tracking da
  `/admin/dropship/{id}` resta solo per gli ordini storici creati con
  l'etichetta nostra (file PDF/JPG/PNG max 10 MB, MIME verificato; esito in
  `label_uploaded_at`/`label_file_name`, tracking in `tracking_numbers`).

L'admin vede la richiesta dropshipping (badge + destinatario) nel dettaglio
richiesta e nell'email; il rivenditore vede i tracking dei propri ordini in
`/account/ordini` (solo tracking e stato: mai costi o dettagli fornitore).
L'API ordini, nell'esempio della doc, non riporta tracking: se il fornitore
li aggiunge al dettaglio (`tracking_numbers`), la colonna li mostra già.

⚠ Aperto (fiscale, non tecnico): per il dropshipping con consegna in un
paese diverso da quello del rivenditore, verificare col commercialista il
trattamento VAT (place of supply). Oggi il VAT segue il paese del
rivenditore.

## Ordine automatico alla richiesta d'ordine (M8) — flusso di DEFAULT

Dal 06/08/2026 è il flusso standard: `.env.example` porta
`AUTO_DROPSHIP_ON_REQUEST=1` e `AUTO_DROPSHIP_ALLOW_LIVE=1` (entrambi
restano kill-switch). Con `AUTO_DROPSHIP_ON_REQUEST=1`, alla richiesta del cliente parte subito
`DropshipOrderService::autoCreateFromRequest()`: ordine creato con
`POST /api/orders/create/`, l'indirizzo di spedizione del cliente e le righe
dello snapshot clampate allo stock, saltando il flusso a 3 conferme (che
resta per l'uso manuale da /admin). Motivazione: bloccare lo stock del
fornitore PRIMA che arrivi il bonifico (il "delta" del pagamento).

Le righe di **prodotti propri** (`/admin/prodotti-propri`, docs/06) restano
sempre fuori dall'ordine: li spedisce l'admin. Se la richiesta contiene solo
prodotti propri non parte nulla e l'email admin lo dice con un esito neutro
("Nessun ordine GoldenSneakers"). Con un ordine creato, l'email admin riporta
il numero d'ordine GoldenSneakers e — solo con `ADMIN_EMAIL_SHOW_COST=1` —
totale e spedizione del fornitore da pagare (pro-forma in
`/admin/ordini-fornitore`).

⚠ **`.env` creati prima del 06/08/2026** (caso reale, richiesta #4 del
28/09/2026): non hanno la riga `AUTO_DROPSHIP_ALLOW_LIVE`, che se assente
vale 0 — in live l'email admin riporta "Ordine GoldenSneakers automatico NON
creato: Ordine automatico in modalità live disattivato
(AUTO_DROPSHIP_ALLOW_LIVE=0)" anche con `AUTO_DROPSHIP_ON_REQUEST=1`.
Portavano inoltre `DROPSHIP_CREATE_ENDPOINT=/api/orders-dropship/create/`
(path sbagliato, ora ignorato: i path sono fissi nel codice). Per attivare l'invio automatico
reale va aggiunta a mano `AUTO_DROPSHIP_ALLOW_LIVE=1`: è una scelta
esplicita del titolare, il codice non la presume.

⚠ **Rischio accettato dal titolare** (decisione del 18/07/2026): chiunque
abbia accesso al catalogo può innescare la chiamata autenticata al fornitore.
Paracadute in atto:
- flag `.env` dedicato = kill-switch immediato (default 0);
- in `DROPSHIP_MODE=simulation` nessuna chiamata parte;
- **in live l'auto-dropship richiede anche `AUTO_DROPSHIP_ALLOW_LIVE=1`**
  (nel flusso di default è attivo; azzerarlo riporta gli ordini reali al
  solo flusso manuale admin a tre conferme);
- tetto opzionale `DROPSHIP_MAX_ORDER_EUR` anche su questo percorso;
- restano rate limit richieste (3/ora/IP) e ordine minimo;
- l'esito (o il fallimento, che non blocca mai la richiesta) è riportato
  nell'email admin per il monitoraggio.
Prima di attivare `AUTO_DROPSHIP_ALLOW_LIVE`, valutare password per cliente
o approvazione admin entro una finestra temporale.

## Per attivare la modalità live (checklist)

1. Configurare `FEED_BEARER_TOKEN` (se non già attivo per il feed) e
   verificare da `/admin/ordini-fornitore` che l'elenco degli ordini
   dell'account si legga: le letture funzionano anche in simulazione e
   confermano token e `FEED_BASE_URL` senza creare nulla.
2. Valutare un tetto `DROPSHIP_MAX_ORDER_EUR` prudente per i primi ordini.
3. Test end-to-end con un ordine concordato col fornitore (importo minimo)
   dal flusso manuale a tre conferme, con `DROPSHIP_MODE=live`; controllare
   l'ordine in `/admin/ordini-fornitore` e sul portale. Restano da osservare
   sul campo i codici d'errore reali della creazione (la doc non li elenca).
4. Dopo ogni ordine live, eseguire un sync del feed per riallineare lo stock
   locale a quello impegnato dal fornitore.
5. Solo quando il flusso manuale è rodato, valutare `AUTO_DROPSHIP_ALLOW_LIVE=1`
   (se non già attivo).

## Domande aperte

- Codici e messaggi d'errore reali di `POST /api/orders/create/` (la doc
  non li elenca): da osservare nei primi ordini live.
- Elenco completo degli stati ordine e dei valori di `payment_status`
  dell'API ordini (negli esempi: `UNCONFIRMED`, `TO_SHIP`, `unpaid`): quelli
  nuovi si vedono grezzi finché non vengono aggiunti a `lang/it.php`.
- `GET /api/orders/` è paginato oltre una certa soglia? Il client accetta
  già entrambe le forme.
- Un ordine creato alla richiesta ma mai pagato dal cliente va annullato a
  mano sul portale: l'API ordini ha un endpoint di annullamento?
- Il dettaglio ordine riporterà i numeri di tracking (oggi non presenti
  nell'esempio)?
- `total_amount` della creazione include la spedizione del fornitore
  (`shipping_cost`)? La piattaforma li mostra separati senza sommarli.
- Indirizzo di ritiro/mittente del magazzino GoldenSneakers e cosa stampa
  come mittente sul pacco: per il dropshipping verso il cliente finale serve
  un pacco "neutro" (mai prezzi wholesale).
- Esiste un webhook/notifica di cambio stato o va fatto polling sul
  dettaglio?
