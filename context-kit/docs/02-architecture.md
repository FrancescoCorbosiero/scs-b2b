# 02 — Architettura e deploy

## Stack

| Layer | Scelta | Note |
|---|---|---|
| Runtime | PHP 8.3 (FPM) | versione confermata sull'host |
| Framework | Slim 4 + PHP-DI | in alternativa routing custom minimale; NO Laravel |
| DB | MySQL 8 / MariaDB, PDO | prepared statements ovunque |
| Template | Twig | autoescaping attivo |
| CSS | Tailwind CSS | compilato in build (`npm run build`), niente CDN in prod |
| JS | Alpine.js | filtri, carrello, modali, toggle EU/US; bundle locale |
| Email | PHPMailer via SMTP | credenziali in `.env` |
| Config | vlucas/phpdotenv | tutto il configurabile in `.env` |
| Excel export | PhpSpreadsheet | solo se non appesantisce; fallback CSV accettabile |

Niente SPA, niente API pubblica: rendering server-side con progressive enhancement.
Le interazioni carrello possono usare endpoint JSON interni (stessa sessione, CSRF).

## Deploy: Docker Compose su VPS dietro Caddy Docker Proxy

Target: sottodominio `b2b.shoesclothingstore.com`. Sul VPS gira già un'infrastruttura
**Caddy Docker Proxy** (`lucaslorentz/caddy-docker-proxy`) che:
- possiede le porte 80/443/443-udp dell'host e gestisce **TLS automatico (ACME)**;
- instrada verso i container che stanno sulla network Docker esterna **`caddy`**
  (`CADDY_INGRESS_NETWORKS=caddy`) e si configura **tramite label Docker**.

Conseguenze vincolanti per il nostro `docker-compose.yml`:

- **Nessun `ports:` verso l'host** su nessun servizio (né 80, né 443, né 3306).
- Due network: `caddy` (external: true) solo per il servizio web-facing, e una
  network interna `app` per web ↔ php ↔ mysql. MySQL e php-fpm NON toccano `caddy`.
- Il servizio web-facing porta le label del proxy, ad esempio:

  ```yaml
  services:
    web:   # nginx interno che serve public/ e proxy-a php-fpm sulla network app
      networks: [caddy, app]
      labels:
        caddy: b2b.shoesclothingstore.com
        caddy.reverse_proxy: "{{upstreams 80}}"
        caddy.header.X-Robots-Tag: "noindex, nofollow"
  ```

- TLS, redirect HTTP→HTTPS e HTTP/3 sono gestiti da Caddy: non configurarli in nginx.
- Gli header di sicurezza possono stare in nginx o nelle label `caddy.header.*`:
  sceglierne UNA sede (preferire nginx, già specificato in 07) evitando duplicati.

Servizi previsti in `docker-compose.yml`:

- `web` — nginx: document root `public/`, fastcgi verso `php`, header di sicurezza
  (vedi 07); unico servizio sulla network `caddy`
- `php` — php-fpm 8.3 con estensioni: pdo_mysql, mbstring, intl, zip, gd, opcache
- `mysql` — volume persistente; credenziali via env; solo network `app`
- `cron` — container/sidecar che esegue `php bin/sync-feed.php` a intervallo
  configurabile (`FEED_SYNC_INTERVAL`, default ogni 2 ore) e logga l'esito

Fornire anche `docker-compose.dev.yml` (o profili) per sviluppo locale SENZA
dipendenza da Caddy (porta pubblicata es. `8080:80` + Mailpit per SMTP di test).

## Struttura repo target

```
.
├── CLAUDE.md
├── docs/                  # questo context kit (mantenere aggiornato)
├── fixtures/              # sample del feed per sviluppo e test
├── public/                # document root: index.php, assets compilati
├── src/
│   ├── Controller/
│   ├── Service/           # FeedSync, Pricing, Cart, OrderMailer, ...
│   ├── Repository/
│   ├── Adapter/           # GoldenSneakersAdapter (vedi 03)
│   └── Support/           # csrf, rate limit, session, helpers
├── templates/             # Twig
├── lang/it.php            # tutte le stringhe UI
├── bin/                   # sync-feed.php, migrate.php
├── config/
├── database/              # schema.sql, migrazioni, seed.sql (da fixtures)
├── logs/                  # non servito dal web
├── docker/                # Dockerfile, conf nginx/php
├── docker-compose.yml
├── .env.example
└── package.json           # solo build asset (tailwind, alpine)
```

## Qualità

- PHPStan livello ≥ 6 e uno smoke test per i servizi core (adapter feed, pricing,
  validazione carrello) — non serve coverage completa.
- Log applicativi su file in `logs/` (monolog o logger minimale) con contesto.
- `README.md` con: setup locale, build, deploy su VPS, configurazione cron,
  procedura di rotazione password e token feed.
