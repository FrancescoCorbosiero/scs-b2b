# 10 — API catalogo per i siti collegati

Endpoint in sola lettura che espone il **listino netto** del catalogo (prezzi già
calcolati con le regole margine di docs/04) a siti esterni collegati, server-to-server.

Primo consumatore: **ignaziodalto.alpacode.it** (repo
`FrancescoCorbosiero/ignaziodalto`), catalogo vetrina che mostra il nostro
listino netto **+3,50 €** a taglia. Il margine aggiuntivo lo applica il sito
collegato: qui non si tocca nulla.

## Richiesta

```
GET /api/v1/catalogo
Authorization: Bearer <CATALOG_API_TOKEN>
If-None-Match: "<etag della risposta precedente>"   (facoltativo)
```

| Caso | Risposta |
|---|---|
| `CATALOG_API_TOKEN` vuoto nel `.env` | `404` — API spenta |
| Token mancante/errato | `401` + `WWW-Authenticate: Bearer` |
| `If-None-Match` uguale all'ETag attuale | `304` vuoto (listino invariato) |
| OK | `200` JSON |

Nessuna sessione né cookie (`SessionMiddleware` salta `/api/`), header
`X-Robots-Tag: noindex, nofollow`, `Cache-Control: private, no-cache`.

## Risposta

```json
{
  "version": 1,
  "generated_at": "2026-11-17T17:29:13+00:00",
  "synced_at": "2026-11-17T17:29:13+00:00",
  "currency": "EUR",
  "vat_included": false,
  "count": 1,
  "products": [
    {
      "sku": "AD2001",
      "name": "adidas Samba OG 'Cloud White'",
      "brand": "Adidas",
      "size_mapper": "Adidas MENS/GS",
      "size_category": "adult",
      "image_url": "https://www.goldensneakers.net/images/AD2001/main/main.png",
      "total_quantity": 67,
      "price_from": "75.00",
      "updated_at": "2026-11-17 18:29:13",
      "sizes": [
        { "size_eu": "38", "size_us": "5.5", "barcode": "4000000630056", "quantity": 8, "price": "75.00" }
      ]
    }
  ]
}
```

- **Solo i prodotti del feed attivi** (`source = 'feed'`, `is_active = 1`): i
  prodotti propri "Disponibili in sede" non escono. Gli esauriti (stock 0) sì,
  con `quantity: 0`: decide il consumatore se mostrarli.
- `price` = prezzo netto di listino (VAT esclusa), stringa con 2 decimali, lo
  stesso del catalogo riservato. **`offer_price` non esce mai** (Regola d'oro n.1),
  né l'id riga del fornitore. Test: `tests/Unit/CatalogApiTest.php`.
- `synced_at` = fine dell'ultimo sync riuscito: il consumatore lo può mostrare
  ("aggiornato alle …") o usare per accorgersi di un feed fermo.
- L'**ETag** è calcolato sui dati: cambia a ogni sync che modifica qualcosa e a
  ogni reprice da `/admin/margini`. Il consumatore può interrogare spesso (es.
  ogni 5–10 minuti): finché non cambia nulla riceve un 304 da pochi byte.

## Perché il token

Il listino netto è riservato ai rivenditori con accesso (Regola d'oro n.2: i
prezzi stanno dietro login). Senza token l'endpoint sarebbe un export
completo e strutturato del listino, a disposizione di chiunque. Il token vive
solo nei due `.env` (qui e nel sito collegato), mai nel browser: il sito
collegato lo usa dal proprio server.

Rotazione: generare un nuovo valore (`openssl rand -hex 32`), aggiornarlo nei
due `.env` e riavviare. Per spegnere l'API: svuotare `CATALOG_API_TOKEN`.
