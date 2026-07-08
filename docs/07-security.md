# 07 — Sicurezza

## Autenticazione

- **Catalogo**: password unica condivisa. In `.env` come `CATALOG_PASSWORD_HASH`
  (Argon2id via `password_hash`). Mai in chiaro nel repo o nei log.
- **Admin**: seconda password dedicata, `ADMIN_PASSWORD_HASH`, sessione separata
  (flag distinto in sessione, non riusare quella catalogo).
- Sessioni PHP: cookie `HttpOnly`, `Secure`, `SameSite=Lax`; rigenerare l'id al login;
  durata `SESSION_LIFETIME_DAYS` (default 7) con "ricordami".
- Rate limiting login su tabella `login_attempts`: lockout dopo 5 tentativi falliti
  in 15 minuti per (IP, scope), risposta generica senza rivelare il motivo esatto.
- Fornire `bin/hash-password.php` per generare gli hash da mettere in `.env`.

## Non indicizzabilità

- `robots.txt`: `Disallow: /` per tutti gli user agent.
- Header `X-Robots-Tag: noindex, nofollow` su ogni risposta (nginx).
- Meta robots noindex nel layout come cintura+bretelle.

## IP reale del client dietro Caddy (critico per il rate limiting)

L'app riceve il traffico da Caddy Docker Proxy: senza configurazione, l'IP visto
da PHP è quello del container Caddy e **tutto il rate limiting per IP (login,
form ordine) diventa un lockout globale**. Obbligatorio:

- nginx: `set_real_ip_from` sulla subnet della network Docker `caddy` +
  `real_ip_header X-Forwarded-For`, così `REMOTE_ADDR` arriva già corretto a PHP.
- In alternativa, un helper `ClientIp::resolve()` che accetta `X-Forwarded-For`
  SOLO se `REMOTE_ADDR` appartiene alla subnet fidata del proxy (mai fidarsi
  dell'header in modo incondizionato: è spoofabile).
- Test esplicito: due "client" con IP diversi non devono condividere il contatore
  di `login_attempts`.
- Analogamente, il TLS termina su Caddy e internamente il traffico è HTTP: fidarsi
  di `X-Forwarded-Proto` (dalla stessa subnet) per marcare la richiesta come HTTPS,
  altrimenti i cookie `Secure` e gli URL assoluti generati risultano errati.
  In produzione forzare comunque cookie `Secure=true` via config (`APP_ENV=production`).

## Hardening applicativo

- CSRF token per sessione su ogni POST (login incluso), verifica costante-time.
- Output escaping: Twig autoescape sempre attivo; niente `|raw` su dati del feed
  o input utente.
- PDO con prepared statements; nessuna concatenazione SQL.
- Validare/sanificare i dati del feed a sync (tipi, lunghezze, URL immagine con
  whitelist di host `goldensneakers.net`).
- Header nginx: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: same-origin`, CSP ragionevole (self + host immagini fornitore).
- Honeypot + rate limit sul form ordine (3/ora per IP) per lo spam.
- Upload: non ce ne sono; nessun input file.

## Segreti e dati riservati

- `FEED_BEARER_TOKEN`, hash password, credenziali SMTP e DB: SOLO in `.env`
  (gitignored). `.env.example` con placeholder.
- `offer_price`: vedi CLAUDE.md Regola d'oro n.1. In particolare controllare i punti
  facili da sbagliare: export Excel/CSV, endpoint JSON del carrello, email cliente,
  messaggi di errore/log esposti.
- Log in `logs/` fuori dal document root; mai loggare token o password.

## Robustezza

- Error handler globale: pagina di errore cortese, dettagli solo a log.
- Sync feed transazionale (vedi 03): un feed rotto non deve mai svuotare il catalogo.
- Backup: documentare nel README un dump mysql giornaliero (cron) su volume/host.
