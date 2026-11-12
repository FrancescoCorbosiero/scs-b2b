<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Content-Security-Policy di tutte le risposte dell'app (pagine, errori, JSON, PDF).
 *
 * Sta qui e non in nginx: arriva in produzione con il normale deploy
 * (`docker compose up -d --build`), senza ricaricare nginx. Gli altri header
 * di sicurezza, fissi, restano in docker/nginx/default.conf.
 */
final class ContentSecurityPolicyMiddleware implements MiddlewareInterface
{
    /** Hash del CSS del badge Alpacode (versioni di badge.js che lo mettono in un <style> inline). */
    private const ALPACODE_BADGE_STYLE = "'sha256-ycEC4tO5FkNa7TE3pNEn6c9lqJbxz3Z2URuhNC1MdVA='";

    public const POLICY = [
        'default-src' => "'self'",
        // 'unsafe-eval' richiesto da Alpine.js; alpacode.it: badge nel piè di pagina
        'script-src' => "'self' 'unsafe-eval' https://alpacode.it",
        'style-src' => "'self' " . self::ALPACODE_BADGE_STYLE,
        // immagini prodotto: fornitore (si sposta tra sottodomini) e sito principale
        // per i prodotti propri (deve coincidere con CustomProductService::IMAGE_DOMAINS)
        'img-src' => "'self' data: https://goldensneakers.net https://*.goldensneakers.net"
            . ' https://shoesclothingstore.com https://*.shoesclothingstore.com https://alpacode.it',
        'font-src' => "'self' https://alpacode.it",
        'connect-src' => "'self'",
        'frame-ancestors' => "'none'",
    ];

    public static function header(): string
    {
        $parts = [];
        foreach (self::POLICY as $directive => $sources) {
            $parts[] = $directive . ' ' . $sources;
        }

        return implode('; ', $parts);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('Content-Security-Policy', self::header());
    }
}
