<?php

declare(strict_types=1);

namespace App\Adapter;

use App\Support\Config;
use Psr\Log\LoggerInterface;

/**
 * Base comune dei client "ordini" dell'API GoldenSneakers:
 * GoldenSneakersOrdersClient (ordini /api/orders/, quello in uso) e
 * GoldenSneakersDropshipClient (API orders-dropship, solo per gli ordini
 * registrati prima del 02/10/2026).
 *
 * Qui vive tutto ciò che riguarda la sicurezza delle chiamate, uguale per
 * entrambi (docs/09):
 *  - modalità DROPSHIP_MODE: qualsiasi valore diverso da "live" degrada a
 *    simulazione (mai un ordine reale per un errore di battitura in .env);
 *  - la POST di creazione NON viene MAI ritentata: un retry dopo un timeout
 *    può produrre un ordine doppio con addebito reale;
 *  - esiti ambigui (timeout dopo l'invio, HTTP 5xx, 2xx senza order_id
 *    leggibile) ⇒ DropshipUncertainException; fallimenti certi (fornitore
 *    irraggiungibile, HTTP 4xx, redirect) ⇒ DropshipException;
 *  - le GET sono idempotenti: un retry con backoff;
 *  - mai seguire redirect, mai loggare gli header (contengono il token).
 */
abstract class GoldenSneakersApiClient
{
    public const MODE_SIMULATION = 'simulation';
    public const MODE_LIVE = 'live';

    /**
     * Errori cURL che avvengono PRIMA che la richiesta parta (DNS, connect,
     * handshake TLS, proxy): il fornitore non ha ricevuto nulla, fallire è
     * sicuro. Tutto il resto (timeout, errori di invio/ricezione) è ambiguo.
     */
    private const PRE_SEND_ERRNOS = [
        CURLE_UNSUPPORTED_PROTOCOL,   // 1
        CURLE_URL_MALFORMAT,          // 3
        CURLE_COULDNT_RESOLVE_PROXY,  // 5
        CURLE_COULDNT_RESOLVE_HOST,   // 6
        CURLE_COULDNT_CONNECT,        // 7
        CURLE_SSL_CONNECT_ERROR,      // 35
    ];

    /**
     * @param \Closure|null $transport SOLO per i test: sostituisce cURL.
     *   Firma: fn(string $method, string $url, list<string> $headers,
     *   string|array|null $body, int $timeout): array{status: int, body: string,
     *   errno: int, error: string}
     */
    public function __construct(
        protected readonly Config $config,
        protected readonly LoggerInterface $logger,
        private readonly ?\Closure $transport = null,
    ) {
    }

    public function mode(): string
    {
        return strtolower($this->config->str('DROPSHIP_MODE', self::MODE_SIMULATION)) === self::MODE_LIVE
            ? self::MODE_LIVE
            : self::MODE_SIMULATION;
    }

    public function isSimulation(): bool
    {
        return $this->mode() === self::MODE_SIMULATION;
    }

    /** Il bearer token è configurato (senza, nessuna chiamata può partire). */
    public function hasToken(): bool
    {
        return $this->config->str('FEED_BEARER_TOKEN') !== '';
    }

    /**
     * POST di creazione ordine: inviata UNA sola volta, esito classificato.
     * Ritorna il JSON della risposta con un order_id positivo garantito.
     *
     * @param array<string, mixed> $payload payload esatto dell'API
     * @param string $what nome dell'ordine nei log (es. "ordine GoldenSneakers")
     * @return array<string, mixed>
     * @throws DropshipException fallimento certo: nessun ordine creato
     * @throws DropshipUncertainException esito ambiguo: l'ordine POTREBBE esistere
     */
    protected function postCreate(string $path, array $payload, string $what): array
    {
        $url = $this->liveUrl($path);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new DropshipException('Payload non serializzabile in JSON: nessun ordine inviato.');
        }

        // NESSUN retry: la creazione non è idempotente
        $res = $this->request('POST', $url, $body);

        if ($res['errno'] !== 0) {
            if (in_array($res['errno'], self::PRE_SEND_ERRNOS, true)) {
                $this->logger->error("Creazione {$what}: connessione fallita, nessun invio", [
                    'errno' => $res['errno'], 'error' => $res['error'],
                ]);
                throw new DropshipException(
                    "Fornitore non raggiungibile ({$res['error']}): nessun ordine è stato inviato. Riprova più tardi."
                );
            }
            $this->logger->error("Creazione {$what}: esito INCERTO (errore di rete dopo l'invio)", [
                'errno' => $res['errno'], 'error' => $res['error'],
            ]);
            throw new DropshipUncertainException(
                "Errore di rete dopo l'invio ({$res['error']}): l'ordine potrebbe essere stato creato."
            );
        }

        $status = $res['status'];
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($res['body'], true);
            $orderId = is_array($decoded) ? $this->positiveInt($decoded['order_id'] ?? null) : null;
            if (!is_array($decoded) || $orderId === null) {
                $this->logger->error("Creazione {$what}: HTTP 2xx ma risposta illeggibile", [
                    'status' => $status, 'body' => mb_substr($res['body'], 0, 500),
                ]);
                throw new DropshipUncertainException(
                    "Il fornitore ha risposto HTTP {$status} ma senza un order_id leggibile: l'ordine potrebbe essere stato creato."
                );
            }
            $this->logger->info("Creazione {$what} riuscita presso il fornitore", [
                'order_id' => $orderId, 'status' => $status,
            ]);
            /** @var array<string, mixed> $decoded */
            $decoded['order_id'] = $orderId;

            return $decoded;
        }

        if ($status >= 400 && $status < 500) {
            // rifiuto esplicito del fornitore: nessun ordine creato
            $detail = $this->errorDetail($res['body']);
            $this->logger->error("Creazione {$what} rifiutata dal fornitore", [
                'status' => $status, 'detail' => $detail,
            ]);
            throw new DropshipException(
                "Il fornitore ha rifiutato l'ordine (HTTP {$status}" . ($detail !== '' ? ": {$detail}" : '') . '). Nessun ordine è stato creato.'
            );
        }

        if ($status >= 300 && $status < 400) {
            // redirect su una POST = URL sbagliato (FEED_BASE_URL senza https
            // o senza www, oppure path cambiato dal fornitore): la richiesta
            // non viene processata
            throw new DropshipException(
                "Il fornitore ha risposto con un redirect (HTTP {$status}): verificare FEED_BASE_URL e il path di creazione sulla documentazione API (docs/09). Nessun ordine creato."
            );
        }

        // 5xx o status anomalo: il fornitore potrebbe aver processato l'ordine
        $this->logger->error("Creazione {$what}: esito INCERTO", [
            'status' => $status, 'body' => mb_substr($res['body'], 0, 500),
        ]);
        throw new DropshipUncertainException(
            "Errore del fornitore (HTTP {$status}): l'ordine potrebbe essere stato creato."
        );
    }

    /**
     * GET idempotente con un retry e backoff: usata per le letture, mai
     * per la creazione.
     *
     * @return array<mixed> il JSON della risposta (oggetto o lista)
     */
    protected function getJson(string $url, string $context): array
    {
        $lastError = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $res = $this->request('GET', $url, null);
            if ($res['errno'] === 0 && $res['status'] >= 200 && $res['status'] < 300) {
                $decoded = json_decode($res['body'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                throw new DropshipException("Risposta {$context} non è JSON valido.");
            }
            $lastError = $res['errno'] !== 0
                ? $res['error']
                : 'HTTP ' . $res['status'] . ($this->errorDetail($res['body']) !== '' ? ': ' . $this->errorDetail($res['body']) : '');
            $this->logger->warning("Lettura {$context} fallita", [
                'url' => $url, 'attempt' => $attempt, 'error' => $lastError,
            ]);
            if ($attempt < 2) {
                sleep(2);
            }
        }

        throw new DropshipException("Lettura {$context} fallita: {$lastError}");
    }

    /**
     * Base URL + path API (costante), con token verificato PRIMA di ogni
     * chiamata: senza token non parte nulla.
     */
    protected function liveUrl(string $path, bool $forRead = false): string
    {
        if (!$this->hasToken()) {
            throw new DropshipException($forRead
                ? 'FEED_BEARER_TOKEN mancante: impossibile leggere gli ordini dal fornitore.'
                : 'FEED_BEARER_TOKEN mancante: impossibile usare DROPSHIP_MODE=live. Nessun ordine inviato.');
        }

        return $this->baseUrl() . $path;
    }

    protected function baseUrl(): string
    {
        return rtrim($this->config->str('FEED_BASE_URL', 'https://www.goldensneakers.net'), '/');
    }

    /**
     * @param string|array<string, mixed>|null $body stringa = JSON;
     *   array = campi multipart/form-data (i file come \CURLFile)
     * @return array{status: int, body: string, errno: int, error: string}
     */
    protected function request(string $method, string $url, string|array|null $body): array
    {
        $timeout = max(5, $this->config->int('DROPSHIP_HTTP_TIMEOUT', 30));
        $headers = [
            'Authorization: Bearer ' . $this->config->str('FEED_BEARER_TOKEN'),
            'Accept: application/json',
        ];
        if (is_string($body)) {
            $headers[] = 'Content-Type: application/json';
        }
        // multipart (array): il Content-Type col boundary lo imposta cURL

        if ($this->transport !== null) {
            /** @var array{status: int, body: string, errno: int, error: string} */
            return ($this->transport)($method, $url, $headers, $body, $timeout);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new DropshipException('Inizializzazione cURL fallita: nessuna richiesta inviata.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // MAI seguire redirect: una POST replicata altrove è imprevedibile
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'SCS-B2B-Catalog/1.0 (+https://b2b.shoesclothingstore.com)',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        // mai loggare né rilanciare gli header: contengono il token
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
            'errno' => $errno,
            'error' => $error,
        ];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    protected function optionalFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    protected function optionalString(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    protected function stringList(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry) && $entry !== '') {
                $list[] = $entry;
            } elseif (is_int($entry)) {
                $list[] = (string) $entry;
            }
        }

        return $list;
    }

    /** Messaggio d'errore leggibile dal body del fornitore (troncato, mai HTML). */
    protected function errorDetail(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach (['message', 'detail', 'error'] as $key) {
                if (is_string($decoded[$key] ?? null) && $decoded[$key] !== '') {
                    return mb_substr($decoded[$key], 0, 300);
                }
            }
            $flat = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            return is_string($flat) ? mb_substr($flat, 0, 300) : '';
        }
        $text = trim(strip_tags($body));

        return mb_substr($text, 0, 300);
    }
}
