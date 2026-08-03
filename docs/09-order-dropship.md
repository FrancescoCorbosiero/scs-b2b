# 09 — Ordini dropship GoldenSneakers

Il dominio **order-dropship** dell'API GoldenSneakers permette di creare gli
ordini direttamente presso il fornitore: lo stock GoldenSneakers viene scalato
alla fonte e resta allineato col nostro catalogo. Sostituisce (in prospettiva)
il giro manuale "richiesta email → ordine a mano sul sito del fornitore".

> ⚠ **Stato: live IMPLEMENTATO ma NON ancora validato col fornitore.** Il
> client (`src/Adapter/GoldenSneakersDropshipClient.php`) ha due modalità:
> con `DROPSHIP_MODE=simulation` (default; qualsiasi valore ≠ `live` degrada
> qui) **non effettua mai chiamate HTTP** e restituisce risposte fittizie;
> con `DROPSHIP_MODE=live` invia davvero (bearer `FEED_BEARER_TOKEN`).
> Creare un ordine dropship reale è **irreversibile** (il fornitore lo
> conferma e scala il suo stock): PRIMA di mettere `live` in produzione va
> completata la checklist di attivazione in fondo a questo documento — in
> particolare la **verifica dei path su Swagger**, oggi ancora non confermati.

## Comportamento della modalità live (soldi veri: leggere prima di attivare)

- **Mai retry sulla POST di creazione**: un timeout dopo l'invio non può
  distinguere "ordine creato" da "ordine perso"; ritentare alla cieca rischia
  un ordine doppio con addebito reale.
- **Esiti incerti tracciati**: timeout dopo l'invio, HTTP 5xx o risposta 2xx
  senza `order_id` leggibile sollevano `DropshipUncertainException`; il
  service registra una riga in `dropship_orders` con **status `UNKNOWN`**
  (payload incluso) e scarta la bozza. L'admin verifica sul portale
  GoldenSneakers se l'ordine esiste PRIMA di ricominciare il flusso.
- **Fallimenti certi**: fornitore irraggiungibile (DNS/connect/TLS), HTTP 4xx
  (rifiuto esplicito, con messaggio del fornitore riportato) o redirect 3xx
  (endpoint sbagliato) ⇒ nessun ordine creato, si può correggere e ripetere.
- **Tetto di spesa opzionale** `DROPSHIP_MAX_ORDER_EUR`: se il costo
  fornitore stimato supera il tetto l'invio è rifiutato prima della chiamata.
- **GET dettagli idempotente**: un retry con backoff; gli errori di lettura
  non modificano nulla.
- La **GET dettagli in live** aggiorna stato e tracking; stati fuori dalla
  lista documentata non sovrascrivono quello salvato.

## Endpoint (base: https://www.goldensneakers.net)

- Documentazione Swagger: `/api/docs/v1/swagger/schema/` (richiede bearer token).
- **Creazione ordine** (POST, path configurato in `DROPSHIP_CREATE_ENDPOINT`,
  **da verificare su Swagger**): payload

  ```json
  {
    "delivery_address": {
      "name": "Mario Rossi",
      "city": "Milano",
      "zip_code": "20121",
      "street": "Via Montenapoleone 12",
      "country_code": "IT",
      "phone": "+393401234567",
      "email": "mario.rossi@example.it"
    },
    "client_provides_shipping_label": false,
    "items": [
      { "size_id": 123, "quantity": 2 },
      { "sku": "AIR-JORDAN-1-HIGH", "size_us": "9.5", "quantity": 1 }
    ]
  }
  ```

  Risposta: `{ "message", "order_id", "total_price", "dropship_package_id" }`.

- **Dettagli/stato ordine**: `GET /orders-dropship/order-details/{order_id}/`
  (`DROPSHIP_DETAILS_ENDPOINT`). Solo il proprietario dell'ordine può leggerlo.
  Risposta: stato, totale, `tracking_numbers`, righe con prezzi unitari.

Auth prevista: lo stesso bearer token del feed (`FEED_BEARER_TOKEN`).

## Stati ordine

| Stato | Significato |
|---|---|
| `UNCONFIRMED` | creato, non ancora confermato dal fornitore |
| `TO_SHIP` | confermato, pronto alla spedizione |
| `ENDED` | completato e consegnato |
| `CANCELED` | annullato |
| `WAITING_FOR_INVOICE` | in attesa di fatturazione |

## Identificazione delle taglie: `size_id`

Gli item accettano `size_id` **oppure** `sku` + `size_us`. Il `size_id` è l'`id`
riga del feed assortment-flat (una riga per SKU+taglia): dal sync viene salvato
in `product_sizes.supplier_size_id` (migrazione `0002_dropship.sql`). Il payload
usa `size_id` quando disponibile e ripiega su `sku`+`size_us`; una riga senza
né `size_id` né `size_us` non è ordinabile (serve un sync del feed).

## Flusso in /admin (tripla conferma)

Dal dettaglio di una richiesta d'ordine (`/admin/richieste/{id}`), card
"Ordine dropship GoldenSneakers" → tre step, **tutti rivalidati lato server**
(`DropshipOrderService`), perché l'invio reale confermerebbe l'ordine:

1. **Prepara** (`GET /admin/richieste/{id}/dropship`): indirizzo di consegna
   precompilato coi dati del cliente (via/città/CAP da completare) e righe del
   carrello verificate contro stock e `size_id` correnti; quantità modificabili
   (0 = escludi riga).
2. **Riepilogo** (`POST …/dropship/riepilogo`): payload JSON esatto che
   verrebbe inviato, stima a costo fornitore e **tre caselle di conferma
   obbligatorie** (indirizzo verificato, righe verificate, consapevolezza
   dell'irreversibilità). La bozza vive in sessione con token monouso e scade
   dopo 15 minuti.
3. **Conferma definitiva** (`POST …/dropship/conferma`): va digitata la frase
   `CONFERMA <id richiesta>`; il bottone resta disabilitato finché non
   corrisponde e la frase è riverificata dal server all'invio
   (`POST …/dropship/invia`).

L'esito viene registrato in `dropship_orders` (payload esatto, risposta,
snapshot righe, stato, modalità) e mostrato in
`/admin/dropship/{id}` con badge **SIMULAZIONE** quando `mode=simulation`.
Il bottone "Aggiorna stato dal fornitore" usa l'endpoint order-details (in
simulazione: risposta fittizia, nessuna chiamata).

## Configurazione (.env)

| Variabile | Uso |
|---|---|
| `DROPSHIP_ENABLED` | `1` mostra la sezione in /admin (default 0) |
| `DROPSHIP_MODE` | `simulation` (default) — qualsiasi altro valore ≠ `live` degrada a simulazione; `live` invia davvero |
| `DROPSHIP_HTTP_TIMEOUT` | timeout in secondi delle chiamate live (default 30, min 5) |
| `DROPSHIP_MAX_ORDER_EUR` | tetto sul costo fornitore stimato di un ordine; oltre ⇒ invio rifiutato prima della chiamata (0 = nessun tetto) |
| `AUTO_DROPSHIP_ALLOW_LIVE` | `1` permette all'auto-dropship di inviare in live; con 0 (default) in live l'auto rifiuta e resta solo il flusso manuale |
| `DROPSHIP_CREATE_ENDPOINT` | path POST creazione (**da verificare su Swagger**) |
| `DROPSHIP_DETAILS_ENDPOINT` | path GET dettagli (**da verificare su Swagger**) |

Auth live: bearer `FEED_BEARER_TOKEN` (lo stesso del feed); senza token il
client rifiuta prima di inviare qualsiasi cosa.

## Auto-dropship alla richiesta d'ordine (M8)

Con `AUTO_DROPSHIP_ON_REQUEST=1`, alla richiesta del cliente parte subito
`DropshipOrderService::autoCreateFromRequest()`: ordine creato con l'indirizzo
di spedizione del cliente (nuovi campi del form) e le righe dello snapshot
clampate allo stock, saltando il flusso a 3 conferme (che resta per l'uso
manuale da /admin). Motivazione: bloccare lo stock del fornitore PRIMA che
arrivi il bonifico (il "delta" del pagamento).

⚠ **Rischio accettato dal titolare** (decisione del 18/07/2026): chiunque
abbia accesso al catalogo può innescare la chiamata autenticata al fornitore.
Paracadute in atto:
- flag `.env` dedicato = kill-switch immediato (default 0);
- in `DROPSHIP_MODE=simulation` nessuna chiamata parte;
- **in live l'auto-dropship rifiuta comunque salvo `AUTO_DROPSHIP_ALLOW_LIVE=1`**
  (doppio opt-in: di default gli ordini reali partono SOLO dal flusso manuale
  admin a tre conferme);
- tetto opzionale `DROPSHIP_MAX_ORDER_EUR` anche su questo percorso;
- restano rate limit richieste (3/ora/IP) e ordine minimo;
- l'esito (o il fallimento, che non blocca mai la richiesta) è riportato
  nell'email admin per il monitoraggio.
Prima di attivare `AUTO_DROPSHIP_ALLOW_LIVE`, valutare password per cliente
o approvazione admin entro una finestra temporale.

## Per attivare la modalità live (checklist)

1. **Verificare su Swagger** (col token: `/api/docs/v1/swagger/schema/`) path
   esatti, method e codici d'errore di creazione/dettagli; aggiornare
   `DROPSHIP_*_ENDPOINT` e questo documento. ⚠ Non ancora fatto: i default
   sono ipotesi ragionevoli, NON confermate.
2. Configurare `FEED_BEARER_TOKEN` (se non già attivo per il feed) e valutare
   un tetto `DROPSHIP_MAX_ORDER_EUR` prudente per i primi ordini.
3. Test end-to-end con un ordine concordato col fornitore (importo minimo),
   poi `DROPSHIP_MODE=live` in `.env`.
4. Dopo ogni ordine live, eseguire un sync del feed per riallineare lo stock
   locale a quello scalato dal fornitore.
5. Solo quando il flusso manuale è rodato, valutare `AUTO_DROPSHIP_ALLOW_LIVE=1`.

## Domande aperte

- Path e method esatti dell'endpoint di creazione (la doc fornita non li
  esplicita; `order-details` è documentato).
- La valuta è sempre EUR? (`currency` compare nella risposta dettagli).
- `client_provides_shipping_label=true`: quale flusso operativo per caricare
  l'etichetta?
- Esiste un webhook/notifica di cambio stato o va fatto polling su
  order-details?
