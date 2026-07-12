# 08 — Roadmap e domande aperte

## Milestone

**M1 — Fondamenta**
Scaffolding (Slim, Twig, Docker Compose, build Tailwind/Alpine), `.env`,
migrazioni/schema, auth catalogo + admin con rate limiting, layout base con nav.
✔ Done quando: login funziona in Docker locale, pagine vuote protette, PHPStan verde.

**M2 — Feed e pricing**
`GoldenSneakersAdapter` + `bin/sync-feed.php` (modalità fixture e live),
normalizzazione flat→DB, precalcolo prezzi 3 piani, `sync_logs`, seed da fixture.
✔ Done quando: sync da fixture popola il DB con prezzi corretti per i 3 piani
(test unitari sulla formula, rounding incluso) e un secondo run è idempotente.

**M3 — Catalogo**
Grid, card, filtri (disponibilità/brand/prezzo/recommended), ricerca, ordinamento,
paginazione, toggle EU/US, dropdown piano, export Excel.
✔ Done quando: tutti i filtri componibili via URL, export corretto e senza dati riservati.

**M4 — Carrello e ordine**
Carrello in sessione, tabella taglie con validazione stock, riepilogo, minimo 5 pezzi,
form richiesta, salvataggio `order_requests`, email admin + cliente, pagina conferma.
✔ Done quando: flusso completo end-to-end con SMTP di test (es. Mailpit nel compose dev)
e resilienza a SMTP giù verificata.

**M5 — Admin e rifiniture**
/admin (richieste, sync log, sync now, flag recommended), pagina contatti,
error pages, README di deploy, hardening finale, revisione mobile.

**M6 — Ordini dropship GoldenSneakers (anteprima in corso, vedi docs/09)**
Flusso /admin con tripla conferma per creare l'ordine direttamente presso il
fornitore. Oggi SOLO simulazione (`DROPSHIP_MODE=simulation`, nessuna chiamata
HTTP); la modalità live richiede la checklist in docs/09.
✔ Done (anteprima) quando: flusso a 3 step funzionante end-to-end in
simulazione, payload conforme all'API, `supplier_size_id` popolato dal sync.

## Domande aperte (chiedere al proprietario, NON assumere)

1. **Percentuali dei 3 piani**: Base / Pro / Max = ? / ? / ? %
   (in `.env.example` ci sono 30/25/20 come placeholder).
2. Il **rounding**: comportamento da verificare empiricamente. Anomalia già
   riscontrata sul sample: `offer_price=47` → 47 × 1,30 × 1,22 = 74,542, ma il feed
   riporta `presented_price=74.54` nonostante `rounding_type=whole` nell'URL
   (nessun arrotondamento all'intero). O il sample è stato estratto con parametri
   diversi, o il parametro non agisce come atteso. Chiedere al proprietario quale
   prezzo finale vuole (74,54 o 75) e testare l'API live prima di fissare
   `PRICE_ROUNDING`.
3. L'endpoint flat è **paginato**? Rate limit? → verificare su Swagger col token.
4. Badge "Recommended": in v1 flag manuale da /admin — confermare che vada bene
   o se esiste un campo del fornitore da usare.
5. SMTP: host/porta/user reali (il proprietario li inserirà direttamente in `.env`).
6. Serve un testo legale/privacy sul form ordine (i dati restano a uso interno)?

## Valori già decisi (non richiedere di nuovo)

- Ordine minimo: **5 pezzi** · IVA: **22%** · Valuta: **EUR** · Taglie primarie: **EU**
- Email ordini: **info@shoesclothingstore.com** · Dominio: **b2b.shoesclothingstore.com**
- PHP host: **8.3.6** · Deploy: **Docker Compose dietro Caddy Docker Proxy**
  (network esterna `caddy`, config via label, TLS gestito da Caddy — vedi 02)
- Recapiti pubblici: vedi `01-overview.md`
