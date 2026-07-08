<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Verifica il token CSRF su ogni richiesta mutante (POST/PUT/PATCH/DELETE).
 * Il token viaggia nel campo "_csrf" del form o nell'header X-CSRF-Token.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $token = null;
        $body = $request->getParsedBody();
        if (is_array($body) && is_string($body['_csrf'] ?? null)) {
            $token = $body['_csrf'];
        } elseif ($request->getHeaderLine('X-CSRF-Token') !== '') {
            $token = $request->getHeaderLine('X-CSRF-Token');
        }

        if (!$this->session->validateCsrf($token)) {
            return $this->view->render(new Response(403), 'errors/403.twig');
        }

        return $handler->handle($request);
    }
}
