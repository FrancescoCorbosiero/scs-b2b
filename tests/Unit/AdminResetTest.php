<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\MarginRuleRepository;
use App\Repository\SettingsRepository;
use App\Repository\VatRateRepository;
use App\Service\MarginResolver;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * I due componenti UX di /admin/margini — "Azzera" e "Ripristina" — lato dati:
 * regole margine, margine di default e aliquote VAT devono poter tornare ai
 * valori di partenza senza passare dal DB a mano.
 */
final class AdminResetTest extends TestCase
{
    private PDO $pdo;
    private MarginRuleRepository $rules;
    private VatRateRepository $vatRates;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->rules = new MarginRuleRepository($this->pdo);
        $this->vatRates = new VatRateRepository($this->pdo);
        $this->settings = new SettingsRepository($this->pdo);
    }

    public function testClearRemovesEveryRuleAndLeavesTheDefaultMargin(): void
    {
        $this->rules->insert(10, 'brand', 'Nike', 'fixed', 3.0);
        $this->rules->insert(20, 'sku', 'JS3801', 'fixed_price', 129.9);

        self::assertSame(2, $this->rules->deleteAll());
        self::assertSame([], $this->rules->all());

        // senza regole vale il margine di default (seed di test: 30%)
        $margin = (new MarginResolver($this->rules, $this->settings))->resolve('Nike', 'Nike Dunk Low', 'JS3801');
        self::assertSame(30.0, $margin['margin_value']);
        self::assertNull($margin['rule_id']);
    }

    public function testRestoreReplacesRulesWithTheStartingSet(): void
    {
        $this->rules->insert(10, 'brand', 'Marca Inventata', 'percent', 80.0);

        $restored = $this->rules->restoreStartingRules();

        self::assertSame(count(MarginRuleRepository::STARTING_RULES), $restored);
        $values = array_map(
            static fn (array $r): string => $r['match_value'],
            $this->rules->all(),
        );
        self::assertNotContains('Marca Inventata', $values, 'Sostituzione integrale: niente residui');
        self::assertContains('Nike', $values);
        self::assertContains('Saucony', $values);

        // ripetibile: due ripristini di fila danno lo stesso stato
        $this->rules->restoreStartingRules();
        self::assertCount(count(MarginRuleRepository::STARTING_RULES), $this->rules->all());
    }

    public function testStartingRulesAreValidForTheResolver(): void
    {
        $this->rules->restoreStartingRules();
        $margin = (new MarginResolver($this->rules, $this->settings))->resolve('Nike', 'Nike Dunk Low', 'NK1001');

        self::assertSame(['fixed', 3.0], [$margin['margin_type'], $margin['margin_value']]);
    }

    public function testVatRatesCanBeZeroedAndRestored(): void
    {
        $this->vatRates->updateRate('IT', 5.0);

        $zeroed = $this->vatRates->zeroAllRates();
        self::assertGreaterThan(0, $zeroed);
        foreach ($this->vatRates->all() as $rate) {
            self::assertSame(0.0, $rate['vat_rate'], $rate['country_code']);
        }

        $this->vatRates->restoreStandardRates();
        self::assertSame(22.0, $this->vatRates->find('IT')['vat_rate']);
        self::assertSame(19.0, $this->vatRates->find('DE')['vat_rate']);
        self::assertSame(8.1, $this->vatRates->find('CH')['vat_rate'], 'Anche i paesi extra-UE tornano al loro valore');
    }

    public function testRestoreOnlyTouchesTheRatesThatDrifted(): void
    {
        $this->vatRates->updateRate('DE', 0.0);

        self::assertSame(1, $this->vatRates->restoreStandardRates(), 'Una sola aliquota era fuori standard');
        self::assertSame(0, $this->vatRates->restoreStandardRates(), 'Secondo giro: niente da fare');
    }

    public function testFactoryDefaultMarginIsTheOwnerValue(): void
    {
        $this->settings->set('default_margin_type', 'fixed');
        $this->settings->set('default_margin_value', '99');

        $this->settings->set('default_margin_type', SettingsRepository::FACTORY_DEFAULT_MARGIN['type']);
        $this->settings->set('default_margin_value', SettingsRepository::FACTORY_DEFAULT_MARGIN['value']);

        $margin = (new MarginResolver($this->rules, $this->settings))->defaultMargin();
        self::assertSame(['percent', 5.0], [$margin['margin_type'], $margin['margin_value']]);
    }

    /** Le aliquote standard in codice devono coprire tutti i paesi a DB. */
    public function testStandardRatesCoverEveryCountryInTheTable(): void
    {
        foreach ($this->vatRates->all() as $rate) {
            self::assertArrayHasKey($rate['country_code'], VatRateRepository::STANDARD_RATES);
        }
    }
}
