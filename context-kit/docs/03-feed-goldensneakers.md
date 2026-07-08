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
| `id` | int | id riga fornitore (utile per debug, non chiave nostra) |
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
| `image` / `image_full_url` | string | directory immagine |
| `image_name` | string | filename immagine |

## Normalizzazione (flat → modello relazionale)

1. Raggruppare le righe per `sku` → 1 record `products`
   (nome, brand, size_mapper, immagine, offer_price di riferimento).
2. Ogni riga → 1 record `product_sizes` (size_eu, size_us, barcode, quantity).
3. Se `offer_price` varia tra taglie dello stesso SKU, salvarlo **per taglia**
   (il pricing si calcola a livello taglia; verificare sul feed reale se accade).
4. URL immagine: candidato `image_full_url + image_name`
   (es. `https://www.goldensneakers.net/images/JS3801/main/Screenshot_....png`).
   **Verificare empiricamente** alla prima integrazione; prevedere placeholder di
   fallback se l'immagine è 404 e un flag di config `IMAGE_CACHE_LOCAL` (default off)
   per scaricare/cachare le immagini in locale qualora gli URL risultino instabili
   o lenti.

## Strategia di sync (`bin/sync-feed.php`)

- Eseguito da cron ogni `FEED_SYNC_INTERVAL` (default 2h) + trigger manuale da /admin.
- **Idempotente e transazionale**: scaricare e validare TUTTO il payload prima di
  toccare il DB; in caso di errore HTTP/parsing, abortire senza modifiche.
- Upsert per `sku` + replace dello stock taglie; i prodotti spariti dal feed vanno
  marcati `is_active = 0`, non cancellati (le richieste d'ordine passate li referenziano).
- Precalcolare a sync i 3 prezzi per piano (vedi `04-pricing.md`).
- Registrare ogni run in `sync_logs`: iniziato/finito, righe lette, prodotti
  creati/aggiornati/disattivati, errori.
- Timeout HTTP ragionevole (es. 60s), retry singolo con backoff, User-Agent identificativo.
- Gestire con grazia payload molto grandi (streaming/chunk se necessario; verificare
  su Swagger se l'endpoint è paginato).

## Sviluppo senza token

Finché `FEED_BEARER_TOKEN` non è valorizzato, il sync deve poter girare in modalità
fixture (`FEED_SOURCE=fixture`) leggendo `fixtures/goldensneakers-sample.json`, così
tutto il resto dell'app è sviluppabile e testabile da subito. Estendere la fixture
con 8–10 SKU sintetici extra (brand diversi, stock alti/medi/bassi, taglie multiple)
per popolare in modo realistico filtri e paginazione.
