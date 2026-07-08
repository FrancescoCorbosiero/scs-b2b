# Prompt di avvio per Claude Code

> Questo file NON serve a Claude Code durante lo sviluppo (può anche essere rimosso
> dopo il kickoff). Incolla il blocco qui sotto nella prima sessione.

---

Leggi `CLAUDE.md` e poi tutti i file in `docs/` in ordine (01→08): contengono la
specifica completa del progetto, decisioni già prese e i vincoli non negoziabili.

Poi:

1. Verifica la fixture `fixtures/goldensneakers-sample.json` e la formula pricing di
   `docs/04-pricing.md`: calcola i prezzi attesi sul sample e confrontali con
   `presented_price` (che usa markup 30%, IVA 22%, rounding whole) per capire il
   comportamento esatto dell'arrotondamento. Riporta cosa scopri.
2. Presentami il piano per la Milestone 1 (`docs/08-roadmap.md`): struttura cartelle,
   rotte, docker-compose e schema DB definitivo. Attendi la mia conferma prima di
   scrivere codice.
3. Procedi milestone per milestone; a fine milestone fai un riepilogo e i check
   di "done" indicati nella roadmap.
4. Se incontri una delle "domande aperte" di `docs/08-roadmap.md` o qualsiasi
   ambiguità: fermati e chiedi.

Ricorda la Regola d'oro n.1: `offer_price` non deve mai raggiungere il client.
