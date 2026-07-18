<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\MarginRuleRepository;
use App\Repository\SettingsRepository;
use App\Service\MarginResolver;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Il caso d'uso del titolare: "le Air Force 1 al 7%, le Jordan a 3 euro
 * fissi in più, tutto il resto flat al 5%".
 */
final class MarginResolverTest extends TestCase
{
    private PDO $pdo;
    private MarginRuleRepository $rules;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->rules = new MarginRuleRepository($this->pdo);
        $this->settings = new SettingsRepository($this->pdo);
    }

    private function resolver(): MarginResolver
    {
        return new MarginResolver($this->rules, $this->settings);
    }

    public function testOwnerScenario(): void
    {
        $this->rules->insert(10, 'name', 'air force 1', 'percent', 7.0);
        $this->rules->insert(20, 'brand', 'Jordan', 'fixed', 3.0);
        $this->settings->set('default_margin_type', 'percent');
        $this->settings->set('default_margin_value', '5');
        $resolver = $this->resolver();

        $af1 = $resolver->resolve('Nike', "Nike Air Force 1 '07 'Triple White'");
        self::assertSame(['percent', 7.0], [$af1['margin_type'], $af1['margin_value']]);

        $jordan = $resolver->resolve('Jordan', "Jordan 1 Low 'Bred Toe'");
        self::assertSame(['fixed', 3.0], [$jordan['margin_type'], $jordan['margin_value']]);

        $other = $resolver->resolve('Adidas', "adidas Samba OG 'Cloud White'");
        self::assertSame(['percent', 5.0], [$other['margin_type'], $other['margin_value']]);
        self::assertNull($other['rule_id']);
    }

    public function testBrandMatchIsCaseInsensitiveAndExact(): void
    {
        $this->rules->insert(10, 'brand', 'nike', 'percent', 8.0);
        $resolver = $this->resolver();

        self::assertSame(8.0, $resolver->resolve('NIKE', 'Nike Dunk Low')['margin_value']);
        // "New Balance" NON è "nike": il match brand è per uguaglianza, non per sottostringa
        self::assertSame(30.0, $resolver->resolve('New Balance', 'NB 550')['margin_value']);
    }

    public function testPriorityOrderDecidesBetweenOverlappingRules(): void
    {
        // una scarpa "Jordan 1 Retro" matcha sia il nome sia il brand:
        // vince la regola con priority più bassa
        $this->rules->insert(5, 'name', 'jordan 1', 'percent', 12.0);
        $this->rules->insert(10, 'brand', 'Jordan', 'fixed', 3.0);

        $margin = $this->resolver()->resolve('Jordan', 'Jordan 1 Retro High OG');
        self::assertSame(['percent', 12.0], [$margin['margin_type'], $margin['margin_value']]);
    }

    public function testInactiveRulesAreIgnored(): void
    {
        $id = $this->rules->insert(10, 'brand', 'Nike', 'percent', 9.0);
        $this->rules->setActive($id, false);

        $margin = $this->resolver()->resolve('Nike', 'Nike Dunk Low');
        self::assertSame(30.0, $margin['margin_value'], 'Regola disattivata → margine di default (seed: 30%)');
    }

    public function testDefaultFallsBackSafelyOnInvalidSettings(): void
    {
        $this->settings->set('default_margin_type', 'garbage');
        $margin = $this->resolver()->defaultMargin();
        self::assertSame('percent', $margin['margin_type']);
    }
}
