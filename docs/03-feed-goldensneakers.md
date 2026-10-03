# 03 — Feed fornitore: GoldenSneakers

Fonte di verità unica del catalogo. Base: https://www.goldensneakers.net

## Endpoint

- **Assortment flat** (da usare): `GET /api/assortment-flat/`
  - Restituisce **una riga per combinazione SKU+taglia** (vedi fixture).
  - Supporta query param di pricing lato API: `rounding_type`, `markup_percentage`,
    `vat_percentage`. **NON usarli**: vedi § Pricing sotto.
- Documentazione Swagger: `/api/docs/v1/swagger/schema/` — **richiede autenticazione**
  (da anonimo risponde 404). Ispezionarla col bearer token in fase di sviluppo per
  verificare parametri, paginazione e rate limit; se emergono differenze rispetto a
  questo documento, aggiornarlo.
- Esiste un endpoint alternativo non-flat (prodotti annidati): usare il flat, è
  sufficiente e più semplice da normalizzare.

## Autenticazione

Bearer token: header `Authorization: Bearer <FEED_BEARER_TOKEN>` (da `.env`).
Il token non va mai committato né loggato.

## Formato riga (dal sample reale in `fixtures/goldensneakers-sample.json`)

| Campo feed | Tipo | Uso |
|---|---|---|
| `id` | int | id riga fornitore per SKU+taglia — salvato in `product_sizes.supplier_size_id`: è il `size_id` dell'API ordini (vedi `09-order-dropship.md`) |
| `sku` | string | **chiave di raggruppamento prodotto** (es. `JS3801`) |
| `product_name` | string | nome prodotto |
| `brand_name` | string | brand (per filtro) |
| `size_mapper_name` | string | scala taglie (es. `Adidas MENS/GS`) — salvare, mostrare opzionale |
| `barcode` | string | EAN per singola taglia — salvare, utile nell'email ordine |
| `size_us` | string | taglia US |
| `size_eu` | string | taglia EU — **taglia primaria della UI**; nota valori frazionari tipo `36 2/3`: trattare come stringhe, MAI come numeri |
| `offer_price` | number | **prezzo wholesale — RISERVATO, mai esposto al client** |
| `presented_price` | number | prezzo calcolato dall'API coi query param — **ignorare** |
| `available_quantity` | int | stock per quella taglia |
| `image` / `image_full_url` | string | immagine: **formato non coerente** — a volte URL assoluto (`https://media.goldensneakers.net/...`), a volte percorso relativo senza host (`/images/IH6001/main/`); a volte cartella base, a volte già il file completo |
| `image_name` | string | filename immagine |

## Normalizzazione (flat → modello relazionale)

1. Raggruppare le righe per `sku` → 1 record `products`
   (nome, brand, size_mapper, immagine, offer_price di riferimento).
2. Ogni riga → 1 record `product_sizes` (size_eu, size_us, barcode, quantity).
3. Se `offer_price` varia tra taglie dello stesso SKU, salvarlo **per taglia**
   (il pricing si calcola a livello taglia; verificare sul feed reale se accade).
4. URL immagine: si parte da `image_full_url` (fallback su `image` se vuoto) e
   si normalizza, perché il fornitore manda formati misti e non li sistemerà:
   - percorso relativo (`/images/IH6001/main/`) → risolto contro `FEED_BASE_URL`;
     idem protocol-relative (`//media.goldensneakers.net/...` → schema `https`);
   - se il percorso finisce già col filename di `image_name` non si concatena
     (altrimenti `.../x.png/x.png` → 404), altrimenti si unisce `+ image_name`
     (es. `https://www.goldensneakers.net/images/JS3801/main/Screenshot_....png`).
   La whitelist di dominio (`goldensneakers.net` e sottodomini, solo `https`)
   si applica **dopo** la risoluzione: quel che non passa diventa `null`.
   **Verificare empiricamente** alla prima integrazione; prevedere placeholder di
   fallback se l'immagine è 404 e un flag di config `IMAGE_CACHE_LOCAL` (default off)
   per scaricare/cachare le immagini in locale qualora gli URL risultino instabili
   o lenti.


## Prodotti esauriti (stock 0 a feed)

Il feed **continua a elencare** i prodotti esauriti, con
`available_quantity = 0` su tutte le taglie: non spariscono dal payload. Sono
due situazioni diverse, gestite diversamente:

| Situazione | Nel feed | Effetto |
|---|---|---|
| Prodotto ritirato dal fornitore | assente | `is_active = 0` (`deactivateExcept`): sparisce da catalogo, ricerca ed export |
| Prodotto esaurito | presente con quantità 0 | resta attivo con `total_quantity = 0`: il catalogo lo mostra marcato **Esaurito**, in fondo alla griglia e fuori dall'export (docs/06) |

Quindi un prodotto che non compare più sulla piattaforma del fornitore può
comunque restare visibile da noi: è esaurito, non ritirato. Se invece un
prodotto ritirato resta visibile, il sospetto è un **sync fermo o fallito** —
si controlla da `/admin/sync` (l'ultimo run in stato `error` lascia il
catalogo com'era, per progetto) e dai `sync_logs`.

## Categoria di taglia (normali / GS / PS)

Il feed **non** dichiara se un modello è da adulto, da ragazzo (GS, grade
school) o da bambino (PS, pre-school). La si deduce a sync in
`App\Service\SizeCategory` e si salva in `products.size_category`:

1. sigle nel **nome**: `(GS)`, `(PS)`, `(TD)`, `Big Kids`, `Toddler`, `Junior`,
   più la `J` finale di adidas ("Gazelle Indoor J" — solo in coda al modello,
   così `'J Balvin'` resta una collaborazione da adulto);
2. `size_mapper_name`, ma **solo se univoco**: `Nike GS` decide, `Adidas MENS/GS`
   copre due scale e non decide nulla;
3. **taglie EU**: fino alla 35 è per forza bambino. Il range GS (35,5–40) si
   sovrappone all'adulto, quindi senza sigle il prodotto resta "normale".

Alimenta il filtro del catalogo (docs/06) e le regole margine per categoria
(docs/04). Se il fornitore aggiungesse un campo dedicato, diventerebbe la
fonte di verità al posto dell'euristica.

## Strategia di sync (`bin/sync-feed.php`)

- Eseguito da cron ogni `FEED_SYNC_INTERVAL` (default 2h) + trigger manuale da /admin.
- **Idempotente e transazionale**: scaricare e validare TUTTO il payload prima di
  toccare il DB; in caso di errore HTTP/parsing, abortire senza modifiche.
- Upsert per `sku` + replace dello stock taglie; i prodotti spariti dal feed vanno
  marcati `is_active = 0`, non cancellati (le richieste d'ordine passate li referenziano).
  Il sync lavora solo sui prodotti con `source = 'feed'`: i prodotti propri
  (§ sotto) non vengono mai aggiornati né disattivati da qui.
- Precalcolare a sync il prezzo netto di listino con le regole margine (vedi `04-pricing.md`).
- Registrare ogni run in `sync_logs`: iniziato/finito, righe lette, prodotti
  creati/aggiornati/disattivati, errori. Uno SKU del feed che appartiene già a
  un prodotto proprio viene saltato (il prodotto proprio resta com'è) e il run
  lo segnala nel messaggio del log ("SKU del feed ignorati…").
- Timeout HTTP ragionevole (es. 60s), retry singolo con backoff, User-Agent identificativo.
- Gestire con grazia payload molto grandi (streaming/chunk se necessario; verificare
  su Swagger se l'endpoint è paginato).

## Prodotti propri (import admin, stesso formato)

Da `/admin/prodotti-propri` (docs/06) l'admin carica prodotti suoi da un file
**JSON o CSV nello stesso formato riga del feed** (§ Formato riga): array di
righe (o la pagina DRF `{"results": [...]}`, come il feed) per il JSON,
intestazioni con gli stessi nomi di campo per il CSV. Nessun campo in più
rispetto a un prodotto del feed.

- Stessa validazione (`GoldenSneakersAdapter::normalizeRow`) e stessa pipeline
  del sync (`FeedSyncService::importCustom`): categoria di taglia, regole
  margine, prezzo netto da `offer_price`, upsert + taglie, sotto lo stesso
  lock e in transazione. L'import è **tutto o niente**: un solo errore e il DB
  resta com'era.
- `products.source = 'custom'`; lo SKU è unico su tutto il catalogo, quindi
  l'import rifiuta gli SKU già usati dal feed (e il sync salta quelli usati
  dai prodotti propri, sopra).
- `id` (il `size_id` del fornitore) viene ignorato: i prodotti propri non
  vengono mai ordinati a GoldenSneakers (docs/09).
- Immagini ammesse anche da `shoesclothingstore.com` oltre che da
  `goldensneakers.net` (CSP allineata, docs/07).
- Con "Sostituisci l'elenco" i prodotti propri assenti dal file diventano
  `is_active = 0`, come i prodotti ritirati dal feed. Nessuna riga in
  `sync_logs`: quella tabella racconta solo il feed.

## Sviluppo senza token

Finché `FEED_BEARER_TOKEN` non è valorizzato, il sync deve poter girare in modalità
fixture (`FEED_SOURCE=fixture`) leggendo `fixtures/goldensneakers-sample.json`, così
tutto il resto dell'app è sviluppabile e testabile da subito. Estendere la fixture
con 8–10 SKU sintetici extra (brand diversi, stock alti/medi/bassi, taglie multiple)
per popolare in modo realistico filtri e paginazione.
