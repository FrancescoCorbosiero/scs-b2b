# 04 — Pricing: listino unico, margini admin, VAT per paese

> Revisione luglio 2026: sostituisce il vecchio sistema a 3 piani (Base/Pro/Max)
> con IVA inclusa. Le direttive: prezzi SEMPRE VAT esclusa, margini gestiti
> dall'admin per brand/nome prodotto, VAT calcolato per paese alla richiesta
> d'ordine (catalogo valido in tutta Europa).

## Concetto

Esiste **un solo listino**: per ogni taglia il prezzo netto (VAT esclusa) è

```
prezzo_netto = arrotonda( offer_price + margine )
```

dove il margine viene dalle **regole admin** (`/admin/margini`, tabella
`margin_rules`) risolte in quest'ordine:

1. La prima regola **attiva** che corrisponde al prodotto, in ordine di
   `priority` crescente (a parità, la più vecchia). Corrispondenza:
   - `brand`: uguaglianza case-insensitive col brand del feed;
   - `name`: il nome prodotto **contiene** il valore (case-insensitive) —
     es. "air force 1".
2. Nessuna regola → **margine di default** (tabella `settings`:
   `default_margin_type` + `default_margin_value`).

Ogni regola (e il default) è `percent` (`offer × (1 + m/100)`) oppure `fixed`
(`offer + m` in EUR).

**Valori di partenza indicati dal titolare (19/07/2026, migrazione 0006)**:
default **5%** per i brand nuovi/non elencati; Adidas 5%; Jordan, Nike,
Timberland, Ugg, Yeezy **+3€**; Autry, Asics, Puma, Vans, Birkenstock,
New Balance, In, Saucony **+2€**. Tutto modificabile da /admin/margini.

- `PRICE_ROUNDING` (`.env`): `whole` (intero, default) | `half` (0,50) | `none`.
- Il calcolo avviene **solo nel nostro backend** da `offer_price` grezzo
  (i query param `markup_percentage`/`vat_percentage` dell'API GoldenSneakers
  restano inutilizzati, vedi docs/03).
- Matematica in interi (centesimi/punti base), niente float: vedi
  `PricingService` e i suoi test.

## Niente IVA nel listino

**I prezzi mostrati sono sempre VAT esclusa** — catalogo, carrello, export
Excel, email. Ovunque compare la dicitura esplicita. Il VAT si calcola SOLO
alla richiesta d'ordine, in base al paese di residenza (`VatService`):

| Caso | Scheme | VAT applicato |
|---|---|---|
| Italia (con o senza P.IVA) | `domestic` | aliquota IT (22%) |
| UE ≠ IT **con** P.IVA plausibile | `reverse_charge` | 0% (artt. 194–196 Dir. 2006/112/CE) |
| UE ≠ IT senza P.IVA | `eu` | aliquota standard del paese |
| Extra-UE (UK, CH) | `export` | 0% (art. 8 DPR 633/72) |

- Aliquote standard per paese in tabella `vat_rates` (UE-27 + GB + CH),
  modificabili da `/admin/margini`. Extra-UE: `is_eu = 0`.
- La P.IVA è validata solo nel **formato** (normalizzazione + plausibilità,
  prefisso VIES `EL` per la Grecia): niente chiamata VIES, la verifica
  sostanziale resta al titolare in fase di conferma.
- Il paese si sceglie dal selettore in header (default IT, persiste in
  sessione) e si conferma nel form ordine; il form mostra un'anteprima live
  di imponibile/VAT/totale (il server resta l'unica verità).

## Precalcolo (nessun costo runtime)

Il prezzo netto è calcolato **una volta, durante il sync del feed** e salvato
in `product_sizes.price` (+ `products.min_price` denormalizzato). A runtime si
legge la colonna: zero calcoli lato client.

Ogni salvataggio in `/admin/margini` (regola creata/attivata/eliminata,
default modificato) esegue subito un **reprice** (`FeedSyncService::run(
repriceOnly: true)`) che ricalcola tutti i prezzi da `offer_price` senza
riscaricare il feed. Equivalente CLI: `php bin/sync-feed.php --reprice`.
Le aliquote VAT invece non richiedono reprice (toccano solo il calcolo a
fine ordine).

## Regole di sicurezza (rimando a CLAUDE.md § Regole d'oro)

- `offer_price` vive solo in DB e nei log di sync: mai in HTML, JSON verso il
  client, export Excel/CSV, email al cliente, ricevuta pro-forma.
- L'email all'amministratore PUÒ includere `offer_price` e il margine (utile al
  titolare), ma dietro flag di config `ADMIN_EMAIL_SHOW_COST` (default on).
- Le aliquote VAT standard dei paesi sono dati pubblici: possono raggiungere
  il client (servono all'anteprima nel form ordine).

## Storico (pre-migrazione 0003)

Il vecchio sistema: 3 piani con markup in `.env` (`MARKUP_BASE/PRO/MAX`) e
IVA 22% inclusa nel prezzo (`prezzo = offer × (1+markup) × (1+IVA)`), colonne
`price_base/pro/max`. Gli ordini storici conservano la colonna `plan`
(ora nullable) e i totali com'erano al momento della richiesta.
