# 01 — Overview

## Cos'è

`b2b.shoesclothingstore.com` è un catalogo B2B di sneakers in sola lettura per i clienti
di SHOES & CLOTHING RESELLING. È il sito secondario del principale
https://shoesclothingstore.com/ (WordPress + WooCommerce), che resta invariato.

Riferimento funzionale: portali wholesale tipo GoldenSneakers — grid prodotti con SKU,
disponibilità totale, taglie/quantità, prezzo; carrello con tabella taglia → prezzo /
stock / quantità ordinata; riepilogo laterale. Va replicata la **struttura informativa**,
non la grafica.

## Utenti e accesso

- Un solo tipo di utente: il cliente B2B, che accede con una **password unica condivisa**
  (nessuna registrazione, nessun account individuale).
- Un'area `/admin` separata, protetta da una seconda password, per il titolare.

## Flusso principale

1. Login con password condivisa → catalogo.
2. L'utente seleziona il proprio **piano prezzi** (Base / Pro / Max) da un dropdown;
   la scelta persiste in sessione e determina tutti i prezzi mostrati.
3. Sfoglia/filtra/cerca il catalogo, vede stock per taglia (EU, con toggle US).
4. Aggiunge prodotti al carrello indicando quantità per taglia (validate contro lo stock).
5. Dal carrello (minimo 5 pezzi totali) compila il form di richiesta ordine:
   nome, azienda, email, telefono, note.
6. Il sistema salva la richiesta a DB e invia:
   - email all'amministratore (info@shoesclothingstore.com) con la tabella d'ordine
     completa (SKU, prodotto, taglia, qty, prezzo unitario del piano scelto, totali,
     piano selezionato, dati di contatto);
   - email di conferma/riepilogo al cliente.
7. Pagina di conferma con i recapiti aziendali.

## Fuori scope (esplicitamente)

- Pagamenti, checkout, gestione ordini oltre alla ricezione della richiesta.
- Account utente individuali, wishlist persistenti cross-device.
- CRUD prodotti: il catalogo arriva SOLO dal feed GoldenSneakers.
- Multi-lingua (UI solo in italiano, ma stringhe centralizzate per un futuro EN).
- Qualsiasi modifica al sito WordPress principale.

## Recapiti aziendali (usare questi valori come default in config)

- Ragione sociale: SHOES & CLOTHING RESELLING OFFICIAL STORE
- Email pubblica: shoes.clothing_reselling@yahoo.com
- Telefono: +39 380 477 8879
- WhatsApp: +39 329 772 0691 (link `https://wa.me/393297720691`)
- Sede: Via Paolo Ricci 12/a, 76121 Barletta (BT)
- P.IVA: 091460600729
- Email amministrativa (riceve gli ordini, non mostrata pubblicamente):
  info@shoesclothingstore.com
- Sito principale: https://shoesclothingstore.com/
