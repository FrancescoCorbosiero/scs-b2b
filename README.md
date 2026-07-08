# B2B Sneakers Catalog — b2b.shoesclothingstore.com

Catalogo sneakers B2B in sola lettura, dietro password condivisa. Gli utenti
sfogliano il catalogo (stock per taglia, prezzi per piano Base/Pro/Max),
compongono un carrello e inviano una **richiesta d'ordine via email** — nessun
checkout/pagamento. La fonte di verità del catalogo è il feed GoldenSneakers.

La specifica completa è in [`docs/`](docs/) (leggere in ordine 01→08).
Le regole non negoziabili sono in [`CLAUDE.md`](CLAUDE.md) — su tutte:
**`offer_price` (costo wholesale) non deve MAI raggiungere il client.**

## Stack

PHP 8.3 · Slim 4 + PHP-DI · Twig · PDO/MySQL 8 · Tailwind CSS + Alpine.js
(compilati in build, niente Node in runtime) · PHPMailer · Docker Compose.

## Setup locale (Docker)

```bash
cp .env.example .env
php bin/hash-password.php "password-catalogo"   # → CATALOG_PASSWORD_HASH nel .env
php bin/hash-password.php "password-admin"      # → ADMIN_PASSWORD_HASH nel .env

npm ci && npm run build                          # asset in public/assets/

docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.dev.yml exec php composer install
docker compose -f docker-compose.dev.yml exec php php bin/migrate.php --wait=60
docker compose -f docker-compose.dev.yml exec php php bin/sync-feed.php   # FEED_SOURCE=fixture
```

- App: http://localhost:8080 — Mailpit (email di test): http://localhost:8025
- Con `FEED_SOURCE=fixture` il sync legge `fixtures/goldensneakers-dev.json`
  (12 SKU realistici); quando il proprietario fornisce il token, impostare
  `FEED_SOURCE=live` e `FEED_BEARER_TOKEN` nel `.env`.

### Sviluppo senza Docker (facoltativo)

`DB_DRIVER=sqlite` + `DB_NAME=var/dev.sqlite` fanno girare l'app su SQLite con
`php -S localhost:8080 -t public` — solo per sviluppo/test: lo schema ufficiale
resta MySQL (`database/migrations/`).

## Test e analisi statica

```bash
composer test    # PHPUnit (formula pricing, adapter feed, carrello, rate limiting, sync)
composer stan    # PHPStan livello 6
```

## Deploy su VPS (dietro Caddy Docker Proxy)

Il VPS ha già `lucaslorentz/caddy-docker-proxy` che possiede le porte 80/443,
gestisce TLS/ACME e instrada verso i container sulla network esterna `caddy`.
`docker-compose.yml` rispetta i vincoli: nessuna porta pubblicata, solo il
servizio `web` sta sulla network `caddy`, config via label.

```bash
git clone <repo> && cd scs-b2b
cp .env.example .env               # compilare TUTTO (hash, DB, SMTP, token feed)
npm ci && npm run build            # asset compilati prima del deploy

docker network inspect caddy >/dev/null   # la network esterna deve esistere
docker compose up -d --build
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php bin/migrate.php --wait=60
docker compose exec php php bin/sync-feed.php
```

Il DNS di `b2b.shoesclothingstore.com` deve puntare al VPS: Caddy emette il
certificato al primo accesso.

### Cron del feed

Il container `cron` esegue `php bin/sync-feed.php` ogni `FEED_SYNC_INTERVAL`
secondi (default 7200 = 2h) e logga su `logs/cron-sync.log`. Ogni run è
registrato anche in tabella `sync_logs` (visibile in `/admin/sync`, dove c'è
anche il pulsante "Sincronizza ora"). Dopo un cambio di percentuali in `.env`:

```bash
docker compose exec php php bin/sync-feed.php --reprice
```

### IP reale del client (rate limiting)

nginx accetta `X-Forwarded-For` dalle subnet Docker private
(`docker/nginx/default.conf`, direttive `set_real_ip_from`). Per restringere
alla sola subnet della network `caddy`:

```bash
docker network inspect caddy | grep Subnet
# → sostituire le set_real_ip_from in docker/nginx/default.conf
```

Senza questa configurazione tutti i client sembrerebbero l'IP di Caddy e il
lockout del login diventerebbe globale.

## Rotazione password e token

1. **Password catalogo/admin**: `php bin/hash-password.php "nuova-password"`,
   aggiornare `CATALOG_PASSWORD_HASH` / `ADMIN_PASSWORD_HASH` nel `.env`, poi
   `docker compose restart php cron`. Le sessioni attive restano valide fino a
   scadenza (7 giorni di default): per invalidarle subito riavviare anche il
   container `php` dopo aver cambiato il nome cookie o attendere la scadenza.
2. **Token feed**: aggiornare `FEED_BEARER_TOKEN` nel `.env` e
   `docker compose restart php cron`. Il token non è mai committato né loggato.

## Backup database

Dump giornaliero via cron dell'host (esempio, ore 3:30):

```cron
30 3 * * * cd /path/scs-b2b && docker compose exec -T mysql sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | gzip > /var/backups/b2b-$(date +\%F).sql.gz
```

Conservare almeno 14 giorni; le richieste d'ordine (`order_requests`) sono
l'unico dato non ricostruibile dal feed.

## Struttura del repo

Vedi `docs/02-architecture.md` § "Struttura repo target" (rispettata 1:1).
Punti d'ingresso: `public/index.php` (web), `bin/sync-feed.php` (cron),
`bin/migrate.php` (schema), `bin/hash-password.php` (utility).

## Domande aperte (per il proprietario)

Restano quelle di `docs/08-roadmap.md`: percentuali reali dei piani (ora
30/25/20 placeholder in `.env`), comportamento del rounding (il sample del
feed suggerisce `none`, il default configurato è `whole`: decidere 74,54 € vs
75 €), credenziali SMTP reali, eventale testo privacy sul form ordine.
