<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Repository\SyncLogRepository;
use App\Support\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API catalogo in sola lettura per i siti collegati (docs/10), es. il
 * catalogo di ignaziodalto.alpacode.it che ricarica il proprio margine sul
 * nostro listino.
 *
 * - Solo prodotti del feed, con il prezzo NETTO DI LISTINO già calcolato
 *   con le regole margine: offer_price non esce mai (Regola d'oro n.1).
 * - Il listino è riservato (Regola d'oro n.2): l'endpoint risponde solo a
 *   chi presenta CATALOG_API_TOKEN (`Authorization: Bearer …`). Senza token
 *   configurato l'API è spenta (404), come se non esistesse.
 * - ETag sul contenuto: chi ripete la richiesta con If-None-Match riceve un
 *   304 vuoto finché il listino non cambia (sync o modifica dei margini).
 */
final class CatalogApiController
{
    public const VERSION = 1;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly SyncLogRepository $syncLogs,
        private readonly Config $config,
    ) {
    }

    public function catalog(Request $request, Response $response): Response
    {
        $token = $this->config->str('CATALOG_API_TOKEN');
        if ($token === '') {
            return self::error($response, 404, 'not_found');
        }
        if (!hash_equals($token, self::bearer($request))) {
            return self::error($response, 401, 'unauthorized')
                ->withHeader('WWW-Authenticate', 'Bearer realm="catalog"');
        }

        $products = $this->products->feedCatalogForApi();
        $lastSync = $this->syncLogs->latestOk();

        // ETag sui dati, non sull'orario di generazione: cambia solo col listino
        $etag = '"' . sha1((string) json_encode($products)) . '"';
        $response = $response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');

        if (in_array($etag, array_map('trim', explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }

        $payload = [
            'version' => self::VERSION,
            'generated_at' => gmdate('c'),
            'synced_at' => $lastSync !== null && $lastSync['finished_at'] !== null
                ? gmdate('c', (int) strtotime((string) $lastSync['finished_at']))
                : null,
            'currency' => 'EUR',
            // prezzi netti di listino: VAT esclusa, come in tutto il catalogo
            'vat_included' => false,
            'count' => count($products),
            'products' => $products,
        ];
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private static function bearer(Request $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private static function error(Response $response, int $status, string $code): Response
    {
        $response->getBody()->write((string) json_encode(['error' => $code]));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
