<?php

declare(strict_types=1);

namespace App\Support;

use App\Repository\AccountRequestRepository;
use App\Repository\UserRepository;
use App\Repository\VatRateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Twig\Environment;

/**
 * Rendering Twig con i global di layout (utente, paese, lingua, carrello, CSRF, flash).
 */
final class View
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Session $session,
        private readonly Config $config,
        private readonly Lang $lang,
        private readonly VatRateRepository $vatRates,
        private readonly UserRepository $users,
        private readonly AccountRequestRepository $accountRequests,
        private readonly Images $images,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(Response $response, string $template, array $data = []): Response
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($uri) ? $uri : '/';
        $whatsappDigits = (string) preg_replace('/[^0-9]/', '', $this->config->str('CONTACT_WHATSAPP'));
        $globals = [
            'current_user' => $this->currentUser(),
            'locale' => $this->lang->locale(),
            'locales' => Session::LOCALES,
            'country' => $this->session->country(),
            'countries' => $this->countries(),
            'size_system' => $this->session->sizeSystem(),
            'grid_size' => $this->session->gridSize(),
            'cart_count' => $this->session->cartItemCount(),
            'csrf_token' => $this->session->csrfToken(),
            'is_catalog_authed' => $this->session->isCatalogAuthed(),
            'is_admin_authed' => $this->session->isAdminAuthed(),
            // badge nella nav admin: richieste di profilo da evadere
            'account_requests_pending' => $this->pendingAccountRequests(),
            'flashes' => $this->session->pullFlashes(),
            'current_path' => parse_url($uri, PHP_URL_PATH) ?: '/',
            'current_uri' => $uri,
            'app_env' => $this->config->str('APP_ENV', 'production'),
            // versione degli asset: nginx li serve con Cache-Control immutable
            // per 7 giorni, quindi l'URL deve cambiare a ogni build
            'asset_version' => $this->assetVersion(),
            // immagini pagine pubbliche (sostituibili con foto in public/img/custom)
            'images' => $this->images->all(),
            'app_url' => rtrim($this->config->str('APP_URL', 'https://b2b.shoesclothingstore.com'), '/'),
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
            // bonifico bancario: unico canale di pagamento (docs/06)
            'bank' => [
                'holder' => $this->config->str('BANK_ACCOUNT_HOLDER'),
                'name' => $this->config->str('BANK_NAME'),
                'iban' => $this->config->str('BANK_IBAN'),
                'bic' => $this->config->str('BANK_BIC'),
            ],
        ];
        $response->getBody()->write($this->twig->render($template, array_merge($globals, $data)));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Marcatore di versione per CSS/JS: il timestamp del build più recente.
     * Senza, dopo un deploy i browser continuerebbero a usare gli asset in
     * cache (Cache-Control: immutable) e le pagine si romperebbero.
     */
    private function assetVersion(): string
    {
        $stamps = [];
        foreach (['app.css', 'app.js'] as $file) {
            $path = $this->config->rootPath() . '/public/assets/' . $file;
            if (is_file($path)) {
                $stamps[] = (int) filemtime($path);
            }
        }

        return (string) ($stamps === [] ? 1 : max($stamps));
    }

    /** Conteggio richieste profilo in attesa (solo per l'area admin). */
    private function pendingAccountRequests(): int
    {
        if (!$this->session->isAdminAuthed()) {
            return 0;
        }
        try {
            return $this->accountRequests->countPending();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{id: int, name: string, email: string}|null utente loggato (null = ospite) */
    private function currentUser(): ?array
    {
        $userId = $this->session->userId();
        if ($userId === null) {
            return null;
        }
        try {
            $user = $this->users->findActive($userId);
        } catch (\Throwable) {
            return null;
        }

        return $user === null ? null : [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
        ];
    }

    /**
     * Paesi per il selettore in header e nel form ordine, col nome tradotto
     * nel locale corrente: IT sempre in testa (sort_order), poi alfabetico.
     * Le aliquote standard sono dati pubblici: possono raggiungere il client.
     *
     * @return list<array{code: string, name: string, is_eu: bool, rate: float}>
     */
    private function countries(): array
    {
        try {
            $rows = $this->vatRates->all();
        } catch (\Throwable) {
            // DB non raggiungibile (es. pagina di errore): il layout non deve rompersi
            return [];
        }
        $countries = [];
        foreach ($rows as $row) {
            $countries[] = [
                'code' => $row['country_code'],
                'name' => $this->lang->t('country.' . $row['country_code']),
                'flag' => self::flagEmoji($row['country_code']),
                'is_eu' => $row['is_eu'],
                'rate' => $row['vat_rate'],
                'sort_order' => $row['sort_order'],
            ];
        }
        usort($countries, static fn (array $a, array $b): int => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]);

        return array_map(static fn (array $c): array => [
            'code' => $c['code'], 'name' => $c['name'], 'flag' => $c['flag'], 'is_eu' => $c['is_eu'], 'rate' => $c['rate'],
        ], $countries);
    }

    /** Bandiera emoji dal codice ISO (regional indicators): 'IT' → 🇮🇹. */
    private static function flagEmoji(string $code): string
    {
        $flag = '';
        foreach (str_split(strtoupper($code)) as $char) {
            $flag .= (string) mb_chr(0x1F1A5 + ord($char), 'UTF-8');
        }

        return $flag;
    }
}
