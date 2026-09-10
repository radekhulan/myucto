<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\PreFinalizeCheckService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P-1 — jednotná BLOKUJÍCÍ brána na nepodporované případy u OBOU přiznání.
 *
 * Dřív existovala jen u DPFO, a to jen jako ruční volný text `unsupported_cases`
 * v roční uzávěrce daňové evidence; u DPPO nebyla vůbec, takže třeba poplatník
 * v likvidaci dostal přiznání typu A, jako by se nic nedělo. Tenhle test ověřuje
 * celou cestu z databáze (příznaky na firmě) až po `can_finalize`, ne jen čistá
 * pravidla detektoru ({@see \MyInvoice\Tests\Unit\Tax\Return\UnsupportedCaseDetectorTest}).
 *
 * Všechna data jsou vymyšlená; transakce se v tearDown rollbackuje.
 */
#[Group('integration')]
final class UnsupportedCaseGateTest extends TestCase
{
    private const YEAR = 2094;

    private Connection $db;
    private PreFinalizeCheckService $service;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(PreFinalizeCheckService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW COLUMNS FROM supplier LIKE 'epo_taxpayer_code'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1782 (příznaky nepodporovaných případů) neproběhla.');
        }
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, cz_nace_code)
             VALUES (?, "Zkušební 1", "Vzorov", "11000", ?, ?, ?, ?, "po", "62020")'
        )->execute(['P-1 brána', $czId, 'p1-gate@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    private function setSupplierFlags(array $columns): void
    {
        $sets = [];
        $params = [];
        foreach ($columns as $col => $value) {
            $sets[] = "$col = ?";
            $params[] = $value;
        }
        $params[] = $this->supplierId;
        $this->db->pdo()->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** @return array{result:array<string,mixed>,podklady:array<string,mixed>,warnings:list<string>} */
    private function computationPo(): array
    {
        return [
            'result' => ['summary' => ['total_tax' => 0.0]],
            'podklady' => [
                'vh' => 0.0,
                'accounting_mode' => 'double_entry',
                'period' => ['starts_on' => self::YEAR . '-01-01', 'ends_on' => self::YEAR . '-12-31'],
            ],
            'warnings' => [],
        ];
    }

    /** @return array{result:array<string,mixed>,podklady:array<string,mixed>,warnings:list<string>} */
    private function computationFo(string $accountingMode): array
    {
        return [
            'result' => [],
            'podklady' => [
                'accounting_mode' => $accountingMode,
                'closing' => ['status' => 'final', 'unsupported_cases' => []],
                'profile' => [],
            ],
            'warnings' => [],
        ];
    }

    /** @param array<string,mixed> $result */
    private function unsupportedKeys(array $result): array
    {
        return array_values(array_filter(
            array_column($result['checks'], 'key'),
            static fn (string $k): bool => str_starts_with($k, 'unsupported_'),
        ));
    }

    /** Kontrolní vzorek: běžná s.r.o. nesmí mít žádný nález, jinak je brána nepoužitelná. */
    public function testOrdinaryCompanyPassesTheGate(): void
    {
        $result = $this->service->run($this->supplierId, self::YEAR, 'po', [], $this->computationPo());
        self::assertSame([], $this->unsupportedKeys($result));
    }

    /** Typ poplatníka mimo „ostatní" varováním doprovodí finalizaci (dřív se natvrdo posílala 1). */
    public function testInvestmentFundTaxpayerTypeWarnsWithoutBlockingFinalization(): void
    {
        $this->setSupplierFlags(['epo_taxpayer_code' => '4']);
        $result = $this->service->run($this->supplierId, self::YEAR, 'po', [], $this->computationPo());

        self::assertContains('unsupported_taxpayer_type_unsupported', $this->unsupportedKeys($result));
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
        $byKey = array_column($result['checks'], null, 'key');
        self::assertSame('warning', $byKey['unsupported_taxpayer_type_unsupported']['severity']);
        self::assertNotSame('', $byKey['unsupported_taxpayer_type_unsupported']['value']['action']);
    }

    /** Likvidace/insolvence/přeměna mění typ přiznání — aplikace posílá vždy „A". */
    public function testLiquidationWarnsWithoutBlockingFinalization(): void
    {
        $this->setSupplierFlags([
            'tax_entity_status' => 'liquidation',
            'tax_entity_status_date' => self::YEAR . '-05-01',
        ]);
        $result = $this->service->run($this->supplierId, self::YEAR, 'po', [], $this->computationPo());

        self::assertContains('unsupported_entity_status_unsupported', $this->unsupportedKeys($result));
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }

    /** ATAD/CFC a investiční pobídky — příznaky, které aplikace z dat odvodit neumí. */
    public function testAtadAndIncentiveFlagsWarnWithoutBlockingFinalization(): void
    {
        $this->setSupplierFlags(['tax_atad_cfc' => 1, 'tax_investment_incentive' => 1]);
        $result = $this->service->run($this->supplierId, self::YEAR, 'po', [], $this->computationPo());
        $keys = $this->unsupportedKeys($result);

        self::assertContains('unsupported_atad_cfc', $keys);
        self::assertContains('unsupported_investment_incentive', $keys);
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }

    /** Atypické zkrácené období: dřív tichý fallback na typ_zo „A" bez jediného slova. */
    public function testAtypicalPeriodWarnsWithoutBlockingFinalization(): void
    {
        $computation = $this->computationPo();
        $computation['podklady']['period'] = ['starts_on' => self::YEAR . '-01-01', 'ends_on' => self::YEAR . '-07-31'];
        $result = $this->service->run($this->supplierId, self::YEAR, 'po', [], $computation);

        self::assertContains('unsupported_tax_period_atypical', $this->unsupportedKeys($result));
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }

    /** Brána nově platí i pro DPFO: podvojné účetnictví FO bez účetních výkazů. */
    public function testFoDoubleEntryWarnsWithoutBlockingFinalization(): void
    {
        $this->setSupplierFlags(['taxpayer_type' => 'fo']);
        $result = $this->service->run($this->supplierId, self::YEAR, 'fo', [], $this->computationFo('double_entry'));

        self::assertContains('unsupported_fo_double_entry_statements', $this->unsupportedKeys($result));
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }

    /** OSVČ v daňové evidenci bez příznaků prochází — kontrolní vzorek pro FO větev. */
    public function testOrdinarySoleTraderPassesTheGate(): void
    {
        $this->setSupplierFlags(['taxpayer_type' => 'fo']);
        $result = $this->service->run($this->supplierId, self::YEAR, 'fo', [], $this->computationFo('tax_evidence'));
        self::assertSame([], $this->unsupportedKeys($result));
    }

    /** § 13 spolupracující osoba a § 38f zápočet — příznaky jen u FO. */
    public function testFoFlagsWarnWithoutBlockingFinalization(): void
    {
        $this->setSupplierFlags([
            'taxpayer_type' => 'fo',
            'tax_cooperating_person' => 1,
            'tax_foreign_income_credit' => 1,
        ]);
        $result = $this->service->run($this->supplierId, self::YEAR, 'fo', [], $this->computationFo('tax_evidence'));
        $keys = $this->unsupportedKeys($result);

        self::assertContains('unsupported_cooperating_person_13', $keys);
        self::assertContains('unsupported_foreign_income_credit_38f', $keys);
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }

    /** Ruční `unsupported_cases` z roční uzávěrky se slévá do stejného seznamu nálezů. */
    public function testManualUnsupportedCasesJoinTheSameList(): void
    {
        $this->setSupplierFlags(['taxpayer_type' => 'fo']);
        $computation = $this->computationFo('tax_evidence');
        $computation['podklady']['closing']['unsupported_cases'] = [['label' => 'Vymyšlený ruční případ']];
        $result = $this->service->run($this->supplierId, self::YEAR, 'fo', [], $computation);

        self::assertContains('unsupported_manual_unsupported_cases', $this->unsupportedKeys($result));
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }
}
