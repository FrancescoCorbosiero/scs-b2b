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

    public function testSkuRuleTargetsOnlyListedSkus(): void
    {
        // più SKU nella stessa regola, separati da virgola, case-insensitive
        $this->rules->insert(100, 'sku', 'JS3801, dd1391-100', 'fixed', 10.0);
        $resolver = $this->resolver();

        $hit = $resolver->resolve('Adidas', "adidas Gazelle Indoor J 'Better Scarlet'", 'js3801');
        self::assertSame(['fixed', 10.0], [$hit['margin_type'], $hit['margin_value']]);
        self::assertSame(['fixed', 10.0], [
            $resolver->resolve('Nike', 'Nike Dunk Low', 'DD1391-100')['margin_type'],
            $resolver->resolve('Nike', 'Nike Dunk Low', 'DD1391-100')['margin_value'],
        ]);

        // SKU non elencato → margine di default (seed: 30%)
        $miss = $resolver->resolve('Adidas', 'adidas Samba OG', 'IE3439');
        self::assertSame(30.0, $miss['margin_value']);
        self::assertNull($miss['rule_id']);
    }

    public function testSkuRuleBeatsBrandAndNameRulesRegardlessOfPriority(): void
    {
        // la regola SKU è la più specifica: vince anche con priority più alta
        $this->rules->insert(1, 'brand', 'Nike', 'percent', 5.0);
        $this->rules->insert(1, 'name', 'dunk', 'percent', 7.0);
        $this->rules->insert(999, 'sku', 'DD1391-100', 'fixed', 12.0);

        $margin = $this->resolver()->resolve('Nike', 'Nike Dunk Low Retro', 'DD1391-100');
        self::assertSame(['fixed', 12.0], [$margin['margin_type'], $margin['margin_value']]);
    }

    public function testWithoutSkuArgumentSkuRulesNeverMatch(): void
    {
        $this->rules->insert(10, 'sku', 'JS3801', 'fixed', 10.0);

        // chiamata "legacy" senza SKU: si ricade sul default, nessun falso match
        self::assertSame(30.0, $this->resolver()->resolve('Adidas', 'adidas Gazelle')['margin_value']);
    }

    /** "Tutti i GS al 10%": regola per categoria di taglia. */
    public function testSizeCategoryRuleMatchesOnlyThatCategory(): void
    {
        $this->rules->insert(10, 'size_category', 'gs', 'percent', 10.0);
        $resolver = $this->resolver();

        $gs = $resolver->resolve('Nike', "Nike Dunk Low 'Panda' (GS)", 'NK9001', 'gs');
        self::assertSame(['percent', 10.0], [$gs['margin_type'], $gs['margin_value']]);

        // altra categoria e chiamata senza categoria → margine di default (seed: 30%)
        self::assertSame(30.0, $resolver->resolve('Nike', 'Nike Dunk Low', 'NK1001', 'adult')['margin_value']);
        self::assertSame(30.0, $resolver->resolve('Nike', 'Nike Dunk Low', 'NK1001')['margin_value']);
    }

    /** Prezzo fisso su uno SKU: il tipo arriva intatto al PricingService. */
    public function testFixedPriceRuleOnSku(): void
    {
        $this->rules->insert(100, 'brand', 'Nike', 'fixed', 3.0);
        $this->rules->insert(100, 'sku', 'DD1391-100', 'fixed_price', 129.0);

        $margin = $this->resolver()->resolve('Nike', 'Nike Dunk Low Retro', 'DD1391-100');
        self::assertSame(['fixed_price', 129.0], [$margin['margin_type'], $margin['margin_value']]);
    }

    /** Un prezzo fisso finito nelle settings non può valere per tutto il catalogo. */
    public function testDefaultMarginNeverBecomesAFixedPrice(): void
    {
        $this->settings->set('default_margin_type', 'fixed_price');

        self::assertSame('percent', $this->resolver()->defaultMargin()['margin_type']);
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
