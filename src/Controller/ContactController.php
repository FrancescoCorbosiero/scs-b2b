<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ContactController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        // i recapiti arrivano dal global "contact" di View (valori da .env)
        return $this->view->render($response, 'contact/index.twig');
    }
}
