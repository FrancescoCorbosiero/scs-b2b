# 04 — Pricing: piani Base / Pro / Max

## Concetto

L'utente sceglie il proprio piano da un **dropdown in header** (Base / Pro / Max).
La scelta persiste in sessione (default: Base) e determina TUTTI i prezzi mostrati:
catalogo, carrello, riepilogo, email d'ordine. Il piano scelto viene incluso nella
richiesta d'ordine.

## Formula

Per ogni taglia/prodotto, per ogni piano P:

```
prezzo(P) = round( offer_price × (1 + MARKUP_P/100) × (1 + VAT/100) )
```

- `MARKUP_BASE`, `MARKUP_PRO`, `MARKUP_MAX`: percentuali in `.env`
  (⚠ valori reali da confermare — vedi 08; usare 30 / 25 / 20 come placeholder).
- `VAT_PERCENTAGE`: default `22`.
- `PRICE_ROUNDING`: default `whole` (arrotonda all'intero); supportare anche
  `half` (0,50) e `none`. La formula replica quella dell'API GoldenSneakers
  (`rounding_type=whole&markup_percentage=...&vat_percentage=...`), ma il calcolo
  avviene **solo nel nostro backend** partendo da `offer_price` grezzo.
- I prezzi si mostrano come "IVA inclusa".

## Precalcolo (nessun costo runtime)

I tre prezzi vengono calcolati **una volta, durante il sync del feed**, e salvati
come colonne (`price_base`, `price_pro`, `price_max`) su `product_sizes` (e/o
denormalizzati su `products` se uniformi per SKU). A runtime si legge la colonna
del piano attivo: zero calcoli lato client, zero query aggiuntive.

Se le percentuali in `.env` cambiano, un re-sync (o `php bin/sync-feed.php --reprice`)
ricalcola tutto.

## Regole di sicurezza (rimando a CLAUDE.md § Regole d'oro)

- `offer_price` vive solo in DB e nei log di sync: mai in HTML, JSON verso il client,
  export Excel/CSV, email al cliente.
- L'email all'amministratore PUÒ includere `offer_price` e il margine (utile al
  titolare), ma dietro flag di config `ADMIN_EMAIL_SHOW_COST` (default on).
- Il cambio piano dal dropdown è libero per l'utente (scelta di prodotto consapevole:
  i tre listini sono visibili a chiunque abbia la password). Nessun tentativo di
  "nascondere" i piani non selezionati è richiesto.
