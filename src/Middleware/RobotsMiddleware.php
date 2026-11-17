<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * X-Robots-Tag di tutte le risposte dell'app (Regola d'oro n.2, docs/07).
 *
 * Solo le pagine pubbliche di presentazione sono indicizzabili; tutto il
 * resto (vetrina, catalogo, carrello, account, admin, API, PDF, export)
 * riceve `noindex, nofollow` anche come header, oltre al meta tag del layout.
 *
 * Prima lo mandava nginx su OGNI risposta, home compresa: le pagine pubbliche
 * dichiaravano `index, follow` nel meta ma l'header le escludeva comunque dai
 * motori. Ora decide l'app, con lo stesso elenco di robots.txt e sitemap.xml.
 */
final class RobotsMiddleware implements MiddlewareInterface
{
    /** Percorsi indicizzabili: devono coincidere con le pagine `indexable` di PageController. */
    public const INDEXABLE_PATHS = ['/', '/spedizioni', '/come-ordinare', '/richiedi-accesso'];

    public static function isIndexable(string $path): bool
    {
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return in_array($path, self::INDEXABLE_PATHS, true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $indexable = self::isIndexable($request->getUri()->getPath()) && $response->getStatusCode() === 200;

        return $response->withHeader('X-Robots-Tag', $indexable ? 'index, follow' : 'noindex, nofollow');
    }
}
