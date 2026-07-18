# B2B Sneakers Catalog — b2b.shoesclothingstore.com

Piattaforma catalogo sneakers B2B in sola lettura, ad accesso riservato con
account personali creati dall'admin via invito email (più una password condivisa
"ospite" di transizione, `GUEST_LOGIN_ENABLED`), valida in tutta Europa
(UE-27 + UK/CH) e multi-lingua IT/EN (default IT).
Gli utenti sfogliano il catalogo (stock per taglia, prezzi netti VAT esclusa),
compongono un carrello e inviano una **richiesta d'ordine** che riceve subito le
istruzioni di pagamento via **bonifico bancario** (unico canale): l'ordine viene
**confermato dall'admin all'arrivo del pagamento**, momento in cui parte l'email
di conferma con la ricevuta pro-forma PDF. Il VAT si calcola alla richiesta in
base al paese di residenza (reverse charge per B2B UE con P.IVA). Con
`AUTO_DROPSHIP_ON_REQUEST=1` la richiesta crea subito l'ordine dropship presso
GoldenSneakers (docs/09) per bloccare lo stock prima del bonifico.
Sito secondario del principale https://shoesclothingstore.com/ (WordPress, non toccarlo).

## Come usare questa documentazione

Leggi i file in `docs/` in ordine numerico PRIMA di scrivere codice:

| File | Contenuto |
|---|---|
| `docs/01-overview.md` | Obiettivo, utenti, flussi, cosa NON è in scope |
| `docs/02-architecture.md` | Stack, Docker, struttura repo target, build |
| `docs/03-feed-goldensneakers.md` | Feed fornitore: endpoint, auth, mapping, sync |
| `docs/04-pricing.md` | Piani markup (base/pro/max), formula prezzi, sicurezza |
| `docs/05-data-model.md` | Schema database |
| `docs/06-features.md` | Pagine e funzionalità dettagliate |
| `docs/07-security.md` | Auth, sessioni, hardening |
| `docs/08-roadmap.md` | Milestone di sviluppo + domande aperte |
| `docs/09-order-dropship.md` | Ordini dropship GoldenSneakers (anteprima, solo simulazione) |

Fixture reale del feed: `fixtures/goldensneakers-sample.json`.
Variabili d'ambiente: `.env.example` (documentato riga per riga).

## Stack (vincolante)

- PHP 8.3, Composer, micro-framework Slim 4 (o routing custom leggero), PDO/MySQL
- Template server-side (Twig), Tailwind CSS compilato in build, Alpine.js per interattività
- Niente SPA, niente Laravel, niente Node in runtime (Node solo per build asset)
- Deploy: Docker Compose su VPS (nginx + php-fpm + mysql + cron)

## Regole d'oro (non negoziabili)

1. **`offer_price` (prezzo wholesale del fornitore) non deve MAI raggiungere il client**:
   non in HTML, non in JSON, non in export, non in email al cliente, non nella
   ricevuta pro-forma. L'unico prezzo esposto è il netto di listino precalcolato
   con le regole margine admin (vedi `docs/04-pricing.md`).
2. Il sito è interamente dietro login a password condivisa e **non indicizzabile**
   (noindex + robots.txt).
3. La fonte di verità del catalogo è il feed GoldenSneakers: nessun CRUD prodotti.
4. Tutte le stringhe UI centralizzate e multi-lingua: `lang/it.php` (fonte di
   verità, default) + `lang/en.php` (stesse chiavi; fallback sull'italiano).
   Area admin ed email admin solo in italiano; email cliente e ricevuta nel
   locale del cliente. **I prezzi si mostrano sempre VAT esclusa.**
5. Prepared statements ovunque, escaping sistematico dell'output, CSRF su ogni POST.
6. Se una spec è ambigua o manca un dato (vedi `docs/08-roadmap.md` § Domande aperte):
   **chiedi, non assumere**.

## Comandi previsti

```bash
docker compose up -d              # ambiente locale (nginx, php, mysql)
composer install                  # dipendenze PHP
npm run build                     # compila Tailwind/Alpine in public/assets
php bin/sync-feed.php             # sync manuale del feed (idempotente)
php bin/migrate.php               # applica schema/migrazioni
```

## Processo di lavoro

- Procedi per milestone (`docs/08-roadmap.md`); a fine milestone riepiloga e verifica.
- Prima della milestone 1, presenta il piano (struttura cartelle, rotte, schema) per conferma.
- Usa `fixtures/goldensneakers-sample.json` come dato di sviluppo finché il token
  del feed non è configurato nel `.env`.
