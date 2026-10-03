<?php

declare(strict_types=1);

namespace App\Support;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TwigExtension extends AbstractExtension
{
    public function __construct(private readonly Lang $lang)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...)),
        ];
    }

    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            new TwigFilter('eur', self::formatEur(...)),
            new TwigFilter('when', self::formatWhen(...)),
        ];
    }

    /** @param array<string, string|int|float> $params */
    public function translate(string $key, array $params = []): string
    {
        return $this->lang->t($key, $params);
    }

    /**
     * Formatta un importo (stringa decimale dal DB o numero) in EUR italiano.
     */
    public static function formatEur(string|int|float|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return number_format((float) $amount, 2, ',', '.') . ' €';
    }

    /**
     * Data/ora che arriva da un'API esterna (ISO 8601, es. "2025-05-02T14:20:00Z")
     * nel fuso dell'app: gg/mm/aaaa hh:mm, solo gg/mm/aaaa se manca l'ora.
     * A differenza del filtro `date` di Twig non lancia eccezioni: un valore
     * illeggibile si mostra così com'è.
     */
    public static function formatWhen(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }
        $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format($dateOnly ? 'd/m/Y' : 'd/m/Y H:i');
    }
}
