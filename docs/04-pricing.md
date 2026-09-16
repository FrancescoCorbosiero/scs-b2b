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

1. Le regole **SKU** attive: sono le più specifiche e vengono valutate
   **prima di tutte le altre**, a prescindere dalla `priority` (tra loro
   vale comunque la priority crescente). Corrispondenza: uguaglianza
   case-insensitive con uno dei codici in `match_value` (uno o più SKU
   separati da virgola, es. "JS3801, DD1391-100") — così l'admin decide
   esattamente quali SKU influenzare col margine.
2. La prima regola brand/nome/categoria **attiva** che corrisponde al prodotto,
   in ordine di `priority` crescente (a parità, la più vecchia). Corrispondenza:
   - `brand`: uguaglianza case-insensitive col brand del feed;
   - `name`: il nome prodotto **contiene** il valore (case-insensitive) —
     es. "air force 1";
   - `size_category`: la categoria di taglia del prodotto (`adult` = normali,
     `gs` = ragazzi, `ps` = bambini), dedotta a sync dal feed (docs/03) — es.
     "tutti i GS al 10%".
3. Nessuna regola → **margine di default** (tabella `settings`:
   `default_margin_type` + `default_margin_value`).

Ogni regola è `percent` (`offer × (1 + m/100)`), `fixed` (`offer + m` in EUR)
oppure `fixed_price` (vedi sotto). Il **default**, che vale su tutto il
catalogo, ammette solo `percent` e `fixed`.

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

### Prezzo fisso manuale (`fixed_price`)

Quando il titolare vuole decidere lui la cifra ("lo SKU JS3801 lo vendo a
129,90 € e basta"), la regola è di tipo **Prezzo fisso**: `margin_value` non è
un margine ma il **prezzo netto di listino** (VAT esclusa) applicato a tutte le
taglie del prodotto.

- `offer_price` del feed **non entra** nel calcolo: il prezzo resta quello
  anche se il fornitore cambia il costo (fino a che la regola è attiva).
- Nessun arrotondamento: 129,90 resta 129,90 anche con `PRICE_ROUNDING=whole`
  (è una cifra scelta a mano, non il risultato di una formula).
- Mai negativo: un valore < 0 viene rifiutato in `/admin/margini`.
- Vale con qualsiasi tipo di corrispondenza, ma il caso tipico è `sku` — che
  è anche la regola valutata per prima, quindi un prezzo fisso batte sempre
  le regole di brand/nome/categoria.

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
  di imponibile/spedizione/VAT/totale (il server resta l'unica verità).
- La **spedizione** (`ShippingService`, docs/06 § /carrello) è netta e segue lo
  stesso regime dei beni perché accessoria alla cessione (art. 12 DPR 633/72 ·
  art. 78 Dir. 2006/112/CE): l'imponibile è `merce + spedizione`, quindi in
  reverse charge/export resta a VAT 0% come il resto dell'ordine.

## Precalcolo (nessun costo runtime)

Il prezzo netto è calcolato **una volta, durante il sync del feed** e salvato
in `product_sizes.price` (+ `products.min_price` denormalizzato). A runtime si
legge la colonna: zero calcoli lato client.

Ogni salvataggio in `/admin/margini` (regola creata/attivata/eliminata,
default modificato) esegue subito un **reprice** (`FeedSyncService::run(
repriceOnly: true)`) che ricalcola tutti i prezzi da `offer_price` senza
riscaricare il feed — e ne approfitta per riallineare `products.size_category`.
Equivalente CLI: `php bin/sync-feed.php --reprice`.
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
