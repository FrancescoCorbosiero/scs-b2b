<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sessione server-side: auth catalogo/admin (flag distinti), piano prezzi,
 * sistema taglie, carrello, token CSRF e messaggi flash.
 */
final class Session
{
    public const PLANS = ['base', 'pro', 'max'];
    public const SIZE_SYSTEMS = ['eu', 'us'];

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $lifetime = $this->lifetimeSeconds();
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        session_name('b2b_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Con "ricordami" il cookie diventa persistente per SESSION_LIFETIME_DAYS,
     * altrimenti resta un cookie di sessione (scade alla chiusura del browser).
     */
    public function persist(bool $remember): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        setcookie(session_name() ?: 'b2b_session', session_id() ?: '', [
            'expires' => $remember ? time() + $this->lifetimeSeconds() : 0,
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // ── Auth catalogo ────────────────────────────────────────────────

    public function isCatalogAuthed(): bool
    {
        return ($_SESSION['auth_catalog'] ?? false) === true;
    }

    public function loginCatalog(): void
    {
        $this->regenerate();
        $_SESSION['auth_catalog'] = true;
    }

    // ── Auth admin (flag separato, mai riusare quella catalogo) ─────

    public function isAdminAuthed(): bool
    {
        return ($_SESSION['auth_admin'] ?? false) === true;
    }

    public function loginAdmin(): void
    {
        $this->regenerate();
        $_SESSION['auth_admin'] = true;
    }

    public function logout(string $scope): void
    {
        unset($_SESSION[$scope === 'admin' ? 'auth_admin' : 'auth_catalog']);
        $this->regenerate();
    }

    // ── Preferenze utente ────────────────────────────────────────────

    public function plan(): string
    {
        $plan = $_SESSION['plan'] ?? 'base';

        return in_array($plan, self::PLANS, true) ? $plan : 'base';
    }

    public function setPlan(string $plan): void
    {
        if (in_array($plan, self::PLANS, true)) {
            $_SESSION['plan'] = $plan;
        }
    }

    public function sizeSystem(): string
    {
        $system = $_SESSION['size_system'] ?? 'eu';

        return in_array($system, self::SIZE_SYSTEMS, true) ? $system : 'eu';
    }

    public function setSizeSystem(string $system): void
    {
        if (in_array($system, self::SIZE_SYSTEMS, true)) {
            $_SESSION['size_system'] = $system;
        }
    }

    // ── Carrello: sku => [size_eu => qty] ────────────────────────────

    /** @return array<string, array<string, int>> */
    public function cart(): array
    {
        $cart = $_SESSION['cart'] ?? [];
        if (!is_array($cart)) {
            return [];
        }
        $clean = [];
        foreach ($cart as $sku => $sizes) {
            if (!is_string($sku) || !is_array($sizes)) {
                continue;
            }
            $cleanSizes = [];
            foreach ($sizes as $size => $qty) {
                if (is_int($qty) && $qty > 0) {
                    $cleanSizes[(string) $size] = $qty;
                }
            }
            $clean[$sku] = $cleanSizes;
        }

        return $clean;
    }

    /** @param array<string, array<string, int>> $cart */
    public function setCart(array $cart): void
    {
        $_SESSION['cart'] = $cart;
    }

    public function cartItemCount(): int
    {
        $count = 0;
        foreach ($this->cart() as $sizes) {
            $count += array_sum($sizes);
        }

        return $count;
    }

    // ── CSRF ─────────────────────────────────────────────────────────

    public function csrfToken(): string
    {
        $token = $_SESSION['csrf'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf'] = $token;
        }

        return $token;
    }

    public function validateCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->csrfToken(), $token);
    }

    // ── Flash messages ───────────────────────────────────────────────

    public function flash(string $type, string $message): void
    {
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public function pullFlashes(): array
    {
        $raw = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        if (!is_array($raw)) {
            return [];
        }
        $flashes = [];
        foreach ($raw as $item) {
            if (is_array($item) && is_string($item['type'] ?? null) && is_string($item['message'] ?? null)) {
                $flashes[] = ['type' => $item['type'], 'message' => $item['message']];
            }
        }

        return $flashes;
    }

    // ── Storage generico ─────────────────────────────────────────────

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    private function lifetimeSeconds(): int
    {
        return max(1, $this->config->int('SESSION_LIFETIME_DAYS', 7)) * 86400;
    }

    private function isSecureRequest(): bool
    {
        if ($this->config->isProduction()) {
            return true; // dietro Caddy il TLS termina a monte: forziamo Secure
        }
        $https = $_SERVER['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && $https !== 'off';
    }
}
