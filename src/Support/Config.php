<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Accesso tipizzato alla configurazione applicativa (variabili d'ambiente).
 * Ogni chiave è documentata in .env.example.
 */
final class Config
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromEnv(string $rootPath): self
    {
        $values = ['ROOT_PATH' => rtrim($rootPath, '/')];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && !isset($values[$key])) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';

        return $value !== '' ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? '';

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->values[$key] ?? '';

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower($this->values[$key] ?? '');
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    public function rootPath(): string
    {
        return $this->values['ROOT_PATH'] ?? dirname(__DIR__, 2);
    }

    public function isProduction(): bool
    {
        return $this->str('APP_ENV', 'production') === 'production';
    }
}
