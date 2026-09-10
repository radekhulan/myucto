<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\PreFinalizeCheckService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fáze E návrh E10 (audit 2026-07): předfinalizační kontrolní checklist DPPO/DPFO.
 * Minimální fixtures v deníku (bez modulu majetku), rollbackovaná transakce,
 * soft-skip bez cfg.php / DB / migrace daně z příjmů.
 *
 * vh_vs_statement kontrola závisí na naseedovaném mapování výkazů (FinancialStatementService),
 * které v testovací DB nemusí být — ověřujeme jen její přítomnost, ne výsledek.
 */
#[Group('integration')]
final class PreFinalizeCheckServiceTest extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private PreFinalizeCheckService $service;
    private int $supplierId = 0;
    private int $periodId = 0;
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
        foreach (['chart_of_accounts', 'journal_entries', 'journal_entry_lines', 'accounting_periods'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE '$t'")->fetch() === false) {
                $this->markTestSkipped("Tabulka $t (podvojné účetnictví) chybí.");
            }
        }
        if ($pdo->query("SHOW COLUMNS FROM chart_of_accounts LIKE 'tax_deductibility'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1030 (tax_deductibility) neproběhla.');
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
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, taxpayer_type, is_vat_payer, vat_period)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "po", 1, "quarterly")'
        )->execute(['E10 checklist', $czId, 'e10@example.com', $currencyId, $vatRateId]);
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

    public function testHappyPathAllChecksPass(): void
    {
        $this->mkPeriod('closed');
        $acc543 = $this->mkAccount('543', 'Dary', 'expense', 'deductible');
        $acc321 = $this->mkAccount('321', 'Dodavatelé', 'liability', 'deductible');
        // Obrat 543 = 5 000, který sedí se zadanými dary §20/8.
        $this->mkPostedEntry([[$acc543, 'debit', 5000.0], [$acc321, 'credit', 5000.0]]);
        // DPH přiznání za všechna 4 čtvrtletí archivována.
        foreach ([1, 2, 3, 4] as $q) {
            $this->mkVatSubmission($q);
        }

        $inputs = ['donation_items' => [['text' => 'Dar nadaci', 'amount' => 5000.0]]];
        $res = $this->service->run($this->supplierId, self::YEAR, 'po', $inputs, $this->computation(12345.0));
        $byKey = array_column($res['checks'], null, 'key');

        self::assertTrue($byKey['period_status']['ok']);
        self::assertTrue($byKey['depreciation_551']['ok'], '0 obrat 551 = 0 účetních odpisů.');
        self::assertTrue($byKey['donations_543']['ok'], '543 obrat 5000 = dary 5000.');
        self::assertTrue($byKey['non_deductible_accounts']['ok']);
        self::assertSame(0, $byKey['non_deductible_accounts']['value']['count']);
        self::assertTrue($byKey['vat_returns_filed']['ok']);
        self::assertArrayHasKey('vh_vs_statement', $byKey);
    }

    public function testWarningScenarios(): void
    {
        $this->mkPeriod('open'); // otevřené období → warning
        $acc551 = $this->mkAccount('551', 'Odpisy DHM', 'expense', 'deductible');
        $acc543 = $this->mkAccount('543', 'Dary', 'expense', 'deductible');
        $acc513 = $this->mkAccount('513', 'Reprezentace', 'expense', 'non_deductible');
        $acc321 = $this->mkAccount('321', 'Dodavatelé', 'liability', 'deductible');
        // 551 obrat 10 000 bez zaúčtování v modulu majetku (depreciation_entries) → rozdíl.
        $this->mkPostedEntry([[$acc551, 'debit', 10000.0], [$acc321, 'credit', 10000.0]]);
        // 543 obrat 2 000, ale dary v přiznání nezadány → rozdíl.
        $this->mkPostedEntry([[$acc543, 'debit', 2000.0], [$acc321, 'credit', 2000.0]]);
        // Nedaňový účet 513 s obratem 3 000 → výčet (drill-down).
        $this->mkPostedEntry([[$acc513, 'debit', 3000.0], [$acc321, 'credit', 3000.0]]);
        // DPH: chybí 4. čtvrtletí.
        foreach ([1, 2, 3] as $q) {
            $this->mkVatSubmission($q);
        }

        $res = $this->service->run($this->supplierId, self::YEAR, 'po', [], $this->computation(0.0));
        $byKey = array_column($res['checks'], null, 'key');

        self::assertFalse($byKey['period_status']['ok']);
        self::assertSame('warning', $byKey['period_status']['severity']);
        self::assertSame('open', $byKey['period_status']['value']['status']);

        self::assertFalse($byKey['depreciation_551']['ok']);
        self::assertEqualsWithDelta(10000.0, $byKey['depreciation_551']['value']['diff'], 0.01);

        self::assertFalse($byKey['donations_543']['ok']);
        self::assertEqualsWithDelta(2000.0, $byKey['donations_543']['value']['turnover'], 0.01);

        self::assertSame(1, $byKey['non_deductible_accounts']['value']['count']);
        self::assertSame('513', $byKey['non_deductible_accounts']['value']['accounts'][0]['account_code']);
        self::assertEqualsWithDelta(3000.0, $byKey['non_deductible_accounts']['value']['accounts'][0]['turnover'], 0.01);

        self::assertFalse($byKey['vat_returns_filed']['ok']);
        self::assertContains(4, $byKey['vat_returns_filed']['value']['missing']);
    }

    public function testExpenseModeTransitionAndMissingFoSourcesWarnWithoutBlocking(): void
    {
        $computation = $this->computation(0.0);
        $computation['podklady']['accounting_mode'] = 'tax_evidence';
        $computation['podklady']['blocking_issues'] = [['key' => 'missing_source', 'message' => 'Chybí podklad pro příjem.']];
        $computation['warnings'][] = 'BLOKUJÍCÍ KONTROLA §23 odst. 8 ZDP: změna režimu.';

        $result = $this->service->run($this->supplierId, self::YEAR, 'fo', [], $computation);
        $byKey = array_column($result['checks'], null, 'key');
        self::assertFalse($byKey['missing_source']['ok']);
        self::assertSame('Chybí podklad pro příjem.', $byKey['missing_source']['value']['message']);
        self::assertFalse($byKey['expense_mode_transition_23_8']['ok']);
        self::assertStringStartsWith('VAROVÁNÍ §23 odst. 8 ZDP:', $byKey['expense_mode_transition_23_8']['value']['message']);
        self::assertSame('warning', $byKey['expense_mode_transition_23_8']['severity']);
        self::assertTrue($result['can_finalize']);
        self::assertSame(0, $result['summary']['blocker']);
        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                self::assertSame('warning', $check['severity']);
            }
        }
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /** @return array{result:array<string,mixed>,podklady:array<string,mixed>,warnings:list<string>} */
    /**
     * Limit vlastního příjmu manžela/ky (§ 35ba odst. 1 písm. b) musí pocházet
     * z ročníkových konstant, ne z literálu v kódu.
     *
     * Do fáze F2 tu bylo natvrdo `<= 68000`, zatímco konstanta `spouse_income_limit`
     * existovala, byla editovatelná v číselníku daňových konstant (TaxConstantsAction)
     * a NIKDO ji nečetl — úprava limitu tedy byla tichý no-op. Test proto limit
     * pro testovaný rok posune a ověří, že kontrola změnu respektuje v OBOU směrech.
     */
    public function testSpouseIncomeLimitComesFromTaxConstants(): void
    {
        $this->mkPeriod('closed');
        $this->setSpouseIncomeLimit(90_000.0);

        $spouse = static fn (float $ownIncome): array => ['profile' => ['spouse_claim' => [
            'first_name'               => 'Jana',
            'last_name'                => 'Nováková',
            'birth_date'               => '1990-01-01',
            'own_income'               => $ownIncome,
            'income_proved'            => 1,
            'shared_household_proved'  => 1,
            'child_under_three_proved' => 1,
        ]]];

        // 80 000 je nad starým literálem 68 000, ale pod novým limitem 90 000.
        $res = $this->service->run(
            $this->supplierId,
            self::YEAR,
            'fo',
            [],
            ['result' => [], 'podklady' => $spouse(80_000.0), 'warnings' => []],
        );
        $byKey = array_column($res['checks'], null, 'key');
        self::assertTrue(
            $byKey['dpfo_spouse']['ok'],
            'Příjem 80 000 Kč je pod limitem 90 000 Kč z konstant — kontrola nesmí blokovat.',
        );

        // A nad novým limitem musí pořád blokovat, ať test neprojde jen tím, že nic nekontroluje.
        $res = $this->service->run(
            $this->supplierId,
            self::YEAR,
            'fo',
            [],
            ['result' => [], 'podklady' => $spouse(90_000.01), 'warnings' => []],
        );
        $byKey = array_column($res['checks'], null, 'key');
        self::assertFalse(
            $byKey['dpfo_spouse']['ok'],
            'Příjem nad limitem z konstant musí finalizaci blokovat.',
        );
    }

    private function setSpouseIncomeLimit(float $limit): void
    {
        $pdo = $this->db->pdo();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $constants['spouse_income_limit'] = $limit;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
    }

    private function computation(float $vh): array
    {
        return [
            'result' => ['summary' => ['total_tax' => 0.0, 'vh' => $vh]],
            'podklady' => ['vh' => $vh, 'accounting_mode' => 'double_entry'],
            'warnings' => [],
        ];
    }

    private function mkPeriod(string $status): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31', $status]);
        $this->periodId = (int) $pdo->lastInsertId();
    }

    private function mkAccount(string $code, string $name, string $type, string $deductibility): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, tax_deductibility)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $code, $name, $type, $deductibility]);
        return (int) $pdo->lastInsertId();
    }

    /** @param list<array{0:int,1:string,2:float}> $lines [accountId, side, amount] */
    private function mkPostedEntry(array $lines): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, posted_at)
             VALUES (?, ?, ?, 'manual', NOW())"
        )->execute([$this->supplierId, $this->periodId, self::YEAR . '-06-15']);
        $entryId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $n = 0;
        foreach ($lines as [$accountId, $side, $amount]) {
            $ins->execute([$entryId, $this->supplierId, $accountId, $side, $amount, $n++]);
        }
    }

    private function mkVatSubmission(int $quarter): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_quarter, xml_content, xml_size_bytes, xml_sha256, validation_status)
             VALUES (?, 'dphdp3', ?, ?, '<x/>', 4, REPEAT('a',64), 'passed')"
        )->execute([$this->supplierId, self::YEAR, $quarter]);
    }
}
