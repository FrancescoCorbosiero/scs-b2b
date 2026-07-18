# 09 — Ordini dropship GoldenSneakers (ANTEPRIMA)

Il dominio **order-dropship** dell'API GoldenSneakers permette di creare gli
ordini direttamente presso il fornitore: lo stock GoldenSneakers viene scalato
alla fonte e resta allineato col nostro catalogo. Sostituisce (in prospettiva)
il giro manuale "richiesta email → ordine a mano sul sito del fornitore".

> ⚠ **Stato: ANTEPRIMA — SOLO SIMULAZIONE.** Il client
> (`src/Adapter/GoldenSneakersDropshipClient.php`) **non effettua mai chiamate
> HTTP**: con `DROPSHIP_MODE=simulation` restituisce risposte fittizie nella
> forma documentata; con qualsiasi altro valore rifiuta con `DropshipException`.
> Creare un ordine dropship reale è **irreversibile** (il fornitore lo conferma
> e scala il suo stock): la modalità live va implementata e attivata solo dopo
> aver validato endpoint e comportamento su Swagger col fornitore.

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
| `DROPSHIP_MODE` | `simulation` (default) — qualsiasi altro valore ≠ `live` degrada a simulazione; `live` oggi viene rifiutato dal client |
| `DROPSHIP_CREATE_ENDPOINT` | path POST creazione (da verificare su Swagger) |
| `DROPSHIP_DETAILS_ENDPOINT` | path GET dettagli (da verificare su Swagger) |

## Auto-dropship alla richiesta d'ordine (M8)

Con `AUTO_DROPSHIP_ON_REQUEST=1`, alla richiesta del cliente parte subito
`DropshipOrderService::autoCreateFromRequest()`: ordine creato con l'indirizzo
di spedizione del cliente (nuovi campi del form) e le righe dello snapshot
clampate allo stock, saltando il flusso a 3 conferme (che resta per l'uso
manuale da /admin). Motivazione: bloccare lo stock del fornitore PRIMA che
arrivi il bonifico (il "delta" del pagamento).

⚠ **Rischio accettato dal titolare** (decisione del 18/07/2026): chiunque
abbia la password condivisa del catalogo può innescare la chiamata autenticata
al fornitore. Paracadute in atto:
- flag `.env` dedicato = kill-switch immediato (default 0);
- in `DROPSHIP_MODE=simulation` nessuna chiamata parte (e live oggi è
  comunque rifiutato dal client);
- restano rate limit richieste (3/ora/IP) e ordine minimo;
- l'esito (o il fallimento, che non blocca mai la richiesta) è riportato
  nell'email admin per il monitoraggio.
Prima di attivare il live con auto-dropship, valutare password per cliente
o approvazione admin entro una finestra temporale.

## Per attivare la modalità live (checklist futura)

1. Verificare su Swagger (col token) path esatti, method e codici d'errore
   di creazione/dettagli; aggiornare `DROPSHIP_*_ENDPOINT` e questo documento.
2. Implementare le chiamate HTTP in `GoldenSneakersDropshipClient`
   (bearer `FEED_BEARER_TOKEN`, timeout, retry SOLO sulla GET dettagli — mai
   retry automatico sulla POST di creazione: rischio ordine doppio).
3. Gestire gli errori API (stock esaurito, size_id sconosciuto, indirizzo
   invalido) mappandoli su messaggi in `lang/it.php`.
4. Decidere la politica di riconciliazione: dopo un ordine live, eseguire un
   sync del feed per riallineare lo stock locale.
5. Test end-to-end con un ordine concordato col fornitore, poi
   `DROPSHIP_MODE=live` in `.env`.

## Domande aperte

- Path e method esatti dell'endpoint di creazione (la doc fornita non li
  esplicita; `order-details` è documentato).
- La valuta è sempre EUR? (`currency` compare nella risposta dettagli).
- `client_provides_shipping_label=true`: quale flusso operativo per caricare
  l'etichetta?
- Esiste un webhook/notifica di cambio stato o va fatto polling su
  order-details?
