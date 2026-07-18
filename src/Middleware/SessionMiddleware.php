<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Lang;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly Lang $lang,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();
        // la lingua della richiesta segue la preferenza in sessione (default: it)
        $this->lang->setLocale($this->session->locale());

        return $handler->handle($request);
    }
}
