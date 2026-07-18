<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MarginRuleRepository;
use App\Repository\SettingsRepository;

/**
 * Risolve il margine di un prodotto dalle regole admin (/admin/margini):
 * la prima regola attiva che corrisponde (priority crescente) vince, altrimenti
 * si applica il margine di default dalla tabella settings. Esempio tipico:
 * "Air Force 1 al 7%" (name), "Jordan +3€ fissi" (brand), "tutto il resto 5%".
 */
final class MarginResolver
{
    /** @var list<array{id: int, priority: int, match_type: string, match_value: string, margin_type: string, margin_value: float, is_active: bool}>|null */
    private ?array $rules = null;

    /** @var array{margin_type: string, margin_value: float, rule_id: int|null}|null */
    private ?array $default = null;

    public function __construct(
        private readonly MarginRuleRepository $ruleRepo,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** @return array{margin_type: string, margin_value: float, rule_id: int|null} */
    public function resolve(string $brand, string $name): array
    {
        $this->rules ??= $this->ruleRepo->activeOrdered();

        $brandLower = mb_strtolower(trim($brand));
        $nameLower = mb_strtolower($name);
        foreach ($this->rules as $rule) {
            $value = mb_strtolower(trim($rule['match_value']));
            if ($value === '') {
                continue;
            }
            $matches = $rule['match_type'] === 'brand'
                ? $brandLower === $value
                : str_contains($nameLower, $value);
            if ($matches) {
                return [
                    'margin_type' => $rule['margin_type'],
                    'margin_value' => $rule['margin_value'],
                    'rule_id' => $rule['id'],
                ];
            }
        }

        return $this->defaultMargin();
    }

    /** @return array{margin_type: string, margin_value: float, rule_id: int|null} */
    public function defaultMargin(): array
    {
        if ($this->default === null) {
            $type = $this->settings->get('default_margin_type', 'percent');
            $this->default = [
                'margin_type' => in_array($type, PricingService::MARGIN_TYPES, true) ? $type : 'percent',
                'margin_value' => (float) $this->settings->get('default_margin_value', '30'),
                'rule_id' => null,
            ];
        }

        return $this->default;
    }

    /** Da richiamare dopo un salvataggio regole nello stesso processo (es. reprice post-save). */
    public function clearCache(): void
    {
        $this->rules = null;
        $this->default = null;
    }
}
