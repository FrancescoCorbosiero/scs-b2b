<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Twig\Environment;

/**
 * Rendering Twig con i global di layout (piano, carrello, CSRF, flash).
 */
final class View
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Session $session,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(Response $response, string $template, array $data = []): Response
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($uri) ? $uri : '/';
        $whatsappDigits = (string) preg_replace('/[^0-9]/', '', $this->config->str('CONTACT_WHATSAPP'));
        $globals = [
            'plan' => $this->session->plan(),
            'size_system' => $this->session->sizeSystem(),
            'cart_count' => $this->session->cartItemCount(),
            'csrf_token' => $this->session->csrfToken(),
            'is_catalog_authed' => $this->session->isCatalogAuthed(),
            'is_admin_authed' => $this->session->isAdminAuthed(),
            'flashes' => $this->session->pullFlashes(),
            'current_path' => parse_url($uri, PHP_URL_PATH) ?: '/',
            'current_uri' => $uri,
            'app_env' => $this->config->str('APP_ENV', 'production'),
            'main_site_url' => $this->config->str('MAIN_SITE_URL', 'https://shoesclothingstore.com/'),
            'company_name' => $this->config->str('CONTACT_COMPANY_NAME', 'SHOES & CLOTHING RESELLING'),
            'contact' => [
                'company' => $this->config->str('CONTACT_COMPANY_NAME'),
                'email' => $this->config->str('CONTACT_EMAIL'),
                'phone' => $this->config->str('CONTACT_PHONE'),
                'whatsapp' => $this->config->str('CONTACT_WHATSAPP'),
                'whatsapp_link' => $whatsappDigits !== '' ? 'https://wa.me/' . $whatsappDigits : '',
                'address' => $this->config->str('CONTACT_ADDRESS'),
                'vat' => $this->config->str('CONTACT_VAT'),
                'main_site' => $this->config->str('MAIN_SITE_URL'),
            ],
        ];
        $response->getBody()->write($this->twig->render($template, array_merge($globals, $data)));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
