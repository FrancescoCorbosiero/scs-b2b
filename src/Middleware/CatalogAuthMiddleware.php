<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\UserRepository;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CatalogAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->session->isCatalogAuthed()) {
            return (new Response(302))->withHeader('Location', '/login');
        }

        // account disattivato dall'admin → la sessione decade subito
        $userId = $this->session->userId();
        if ($userId !== null && $this->users->findActive($userId) === null) {
            $this->session->logout('catalog');

            return (new Response(302))->withHeader('Location', '/login');
        }

        return $handler->handle($request);
    }
}
