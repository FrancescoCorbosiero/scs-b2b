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
}
