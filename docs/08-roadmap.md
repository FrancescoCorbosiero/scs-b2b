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

**M7 — Catalogo europeo (luglio 2026) ✔**
Prezzi VAT esclusa con dicitura esplicita; selettore paese (UE-27 + UK/CH,
default IT) e calcolo VAT alla richiesta d'ordine (domestic / aliquota paese /
reverse charge con P.IVA / export); multi-lingua IT/EN (default IT); listino
unico con regole margine gestite da /admin/margini (per brand o nome, percento
o fisso, + default) al posto dei piani Base/Pro/Max; email cliente formale
localizzata con ricevuta pro-forma PDF (dompdf) numerata PF-<anno>-<NNNN>.
Migrazione: 0003_europe.sql (al deploy: `php bin/migrate.php` poi un
`--reprice`/sync per passare ai prezzi netti).

**M8 — Ciclo ordine con pagamento via bonifico (luglio 2026) ✔**
Stati richiesta (pending → confirmed/cancelled) con azioni admin; email alla
richiesta = istruzioni di pagamento via bonifico (coordinate da `BANK_*`,
causale, avviso esplicito "ordine confermato all'arrivo del pagamento");
ricevuta pro-forma spostata all'email di conferma (numero assegnato lì);
indirizzo di spedizione nel form ordine; **auto-dropship alla richiesta**
dietro flag `AUTO_DROPSHIP_ON_REQUEST` (kill-switch; vedi docs/09); dati
bancari in /contatti e nel modal al checkout; navigazione brand con sidebar
sticky/chips mobile; nuovo numero di telefono/WhatsApp (+39 392 772 0691).
Migrazione: 0004_order_lifecycle.sql.

## Domande aperte (chiedere al proprietario, NON assumere)

1. **Margine di default**: la migrazione parte da **30%** (continuità col
   vecchio piano Base, ma ora al NETTO dell'IVA rimossa) — confermare o
   modificare da /admin/margini (l'esempio citato dal titolare era "flat 5%").
2. Il **rounding**: comportamento da verificare empiricamente. Anomalia già
   riscontrata sul sample: il feed riporta `presented_price` senza
   arrotondamento all'intero nonostante `rounding_type=whole` nell'URL.
   `PRICE_ROUNDING=whole` oggi arrotonda il prezzo netto all'intero: confermare.
3. L'endpoint flat è **paginato**? Rate limit? → verificare su Swagger col token.
4. Badge "Recommended": in v1 flag manuale da /admin — confermare che vada bene
   o se esiste un campo del fornitore da usare.
5. SMTP: host/porta/user reali (il proprietario li inserirà direttamente in `.env`).
6. Serve un testo legale/privacy sul form ordine (i dati restano a uso interno)?
7. **P.IVA / reverse charge**: oggi la validazione è solo sul formato (niente
   VIES). Serve la verifica VIES automatica in futuro?

## Valori già decisi (non richiedere di nuovo)

- Ordine minimo: **5 pezzi** · Valuta: **EUR** · Taglie primarie: **EU**
- Prezzi di listino: **VAT esclusa**; aliquote per paese in tabella `vat_rates`
  (UE-27 + UK/CH), extra-UE = export 0%; reverse charge con P.IVA UE valida
- Lingue: **IT (default) + EN**; area admin ed email admin solo in italiano
- Email ordini: **info@shoesclothingstore.com** · Dominio: **b2b.shoesclothingstore.com**
- PHP host: **8.3.6** · Deploy: **Docker Compose dietro Caddy Docker Proxy**
  (network esterna `caddy`, config via label, TLS gestito da Caddy — vedi 02)
- Recapiti pubblici: vedi `01-overview.md`
