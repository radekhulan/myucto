<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fáze E nález E1 (§23 odst. 2 ZDP): FO s podvojným účetnictvím odvozuje §7 dílčí základ
 * z výsledku hospodaření deníku (výnosy − náklady), NE z kasové báze / paušálu.
 *
 * Vše v jedné rollbackované transakci (rok 2097). Soft-skip bez cfg.php / DB / migrací.
 */
#[Group('integration')]
final class DpfoDoubleEntryReturnTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private PostingService $posting;
    private DpfoReturnDataProvider $provider;
    private TaxReturnService $returns;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->posting  = $c->get(PostingService::class);
            $this->provider = $c->get(DpfoReturnDataProvider::class);
            $this->returns  = $c->get(TaxReturnService::class);
            $this->periods  = $c->get(AccountingPeriodRepository::class);
            $seeder         = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'tax_losses'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1042 (tax_losses) neproběhla.');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country).');
        }

        $pdo->beginTransaction();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, taxpayer_type, is_vat_payer)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry", "fo", 0)'
        )->execute(['E1 OSVČ účetnictví', $czId, 'e1-de@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    /** Čtení přes API token koncept přiznání nezakládá; náhled v aplikaci ano. */
    public function testReadWithoutSideEffectsDoesNotCreateDraft(): void
    {
        $count = fn (): int => (int) $this->db->pdo()
            ->query('SELECT COUNT(*) FROM income_tax_returns WHERE supplier_id = ' . $this->supplierId)
            ->fetchColumn();

        $read = $this->returns->getReturn($this->supplierId, self::YEAR, 'fo', $this->userId, sideEffects: false);

        self::assertSame('draft', $read['return']['status']);
        self::assertSame(0, $read['return']['row_version']);
        self::assertSame(0, $count(), 'Neuložený řádek: čtení nic nezapsalo.');

        $this->returns->getReturn($this->supplierId, self::YEAR, 'fo', $this->userId);
        self::assertSame(1, $count(), 'Náhled v aplikaci koncept založí jako dosud.');
    }

    public function testSection7DerivedFromProfitAndLoss(): void
    {
        // Výnos 602 = 500 000, náklad 518 = 200 000 → VH = §7 základ = 300 000.
        $this->manual([
            ['account_code' => '311', 'side' => 'debit', 'amount' => 500000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 500000.00],
        ], self::YEAR . '-03-01');
        $this->manual([
            ['account_code' => '518', 'side' => 'debit', 'amount' => 200000.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 200000.00],
        ], self::YEAR . '-04-01');

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertSame('double_entry', $result['accounting_mode']);
        self::assertSame('actual', $result['expense_mode']);
        self::assertEqualsWithDelta(500000.0, $result['s7_income'], 0.001, '§7 příjem = výnosy 6xx.');
        self::assertEqualsWithDelta(200000.0, $result['s7_expenses'], 0.001, '§7 výdaje = náklady 5xx.');
        self::assertEqualsWithDelta(300000.0, $result['s7_base'], 0.001, '§7 základ = VH (výnosy − náklady).');
        self::assertStringContainsString('výsledku hospodaření', implode("\n", $result['warnings']));
    }

    /**
     * REGRESE (Fáze E adversariální review, nález N1, HIGH): §25 addback nedaňových nákladů.
     * Výnos 500 000, DAŇOVĚ uznatelný náklad (518) 200 000, NEDAŇOVÝ náklad (513 reprezentace,
     * tax_deductibility='non_deductible', §25 ZDP) 50 000 Kč — celkem účetní náklady 250 000,
     * účetní VH (bez addbacku) by byl jen 250 000. Se správným addbackem se nedaňový náklad
     * vyloučí z uznaných výdajů §7 (250 000 − 50 000 = 200 000 uznané výdaje) → §7 základ
     * MUSÍ vyjít 300 000 (STEJNĚ jako kdyby k nedaňovému nákladu vůbec nedošlo —
     * {@see testSection7DerivedFromProfitAndLoss}), NE 250 000 (podhodnocený základ, bez
     * addbacku by nedaňový náklad neprávem snížil daňový základ o celou svou výši).
     */
    public function testNonDeductibleCostsAddedBackToSection7Base(): void
    {
        $this->manual([
            ['account_code' => '311', 'side' => 'debit', 'amount' => 500000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 500000.00],
        ], self::YEAR . '-03-01');
        $this->manual([
            ['account_code' => '518', 'side' => 'debit', 'amount' => 200000.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 200000.00],
        ], self::YEAR . '-04-01');
        // Nedaňový náklad: reprezentace (513, §25 ZDP).
        $this->manual([
            ['account_code' => '513', 'side' => 'debit', 'amount' => 50000.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 50000.00],
        ], self::YEAR . '-05-01');

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(500000.0, $result['s7_income'], 0.001);
        self::assertEqualsWithDelta(200000.0, $result['s7_expenses'], 0.001, '§7 výdaje = 250000 účetních (518+513) − 50000 nedaňových (513).');
        self::assertEqualsWithDelta(300000.0, $result['s7_base'], 0.001, 'Addback vrátí základ na 300000 — jako by nedaňový náklad nebyl vůbec zaúčtován (ne podhodnocených 250000).');
        self::assertStringContainsString('§25', implode("\n", $result['warnings']));
    }

    /**
     * REGRESE (nález N1): ruční položky §23 (manual_increase_items/manual_decrease_items,
     * stejné jako DPPO) se promítnou do §7 základu FO s podvojným účetnictvím.
     */
    public function testManualSection23ItemsAdjustSection7Base(): void
    {
        $this->manual([
            ['account_code' => '311', 'side' => 'debit', 'amount' => 500000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 500000.00],
        ], self::YEAR . '-03-01');
        $this->manual([
            ['account_code' => '518', 'side' => 'debit', 'amount' => 200000.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 200000.00],
        ], self::YEAR . '-04-01');

        $inputs = [
            'manual_increase_items' => [['text' => 'Zvýšení základu', 'amount' => 10000]],
            'manual_decrease_items' => [['text' => 'Snížení základu', 'amount' => 4000]],
        ];
        $result = $this->provider->gather($this->supplierId, self::YEAR, $inputs);

        // VH 300000 + manual increase 10000 − manual decrease 4000 = 306000.
        self::assertEqualsWithDelta(306000.0, $result['s7_base'], 0.001);
        self::assertStringContainsString('Ruční položky', implode("\n", $result['warnings']));
    }

    public function testClosingEntriesExcludedFromSection7(): void
    {
        // Provozní výnos 602 = 100 000; uzávěrkový převod 6xx→710 (source_type='closing')
        // NESMÍ §7 vynulovat.
        $this->manual([
            ['account_code' => '311', 'side' => 'debit', 'amount' => 100000.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100000.00],
        ], self::YEAR . '-05-01');
        $this->posting->postDocument(
            $this->supplierId,
            'closing',
            null,
            [
                ['account_code' => '602', 'side' => 'debit', 'amount' => 100000.00],
                ['account_code' => '710', 'side' => 'credit', 'amount' => 100000.00],
            ],
            ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );

        $result = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertEqualsWithDelta(100000.0, $result['s7_income'], 0.001, 'Uzávěrkový převod se z §7 vylučuje.');
    }

    public function testYearLossFinalizesAndReopensWithoutInventingDppoPeriodProof(): void
    {
        $this->manual([['account_code'=>'518','side'=>'debit','amount'=>400000.00],['account_code'=>'321','side'=>'credit','amount'=>400000.00]],self::YEAR.'-06-01');
        $draft=$this->returns->getReturn($this->supplierId,self::YEAR,'fo',$this->userId);self::assertGreaterThan(0,$draft['computed']['summary']['year_tax_loss']);
        $final=$this->returns->finalize($this->supplierId,self::YEAR,'fo',$draft['return']['row_version'],$this->userId);self::assertSame('final',$final['return']['status']);
        $q=$this->db->pdo()->prepare('SELECT period_proof_kind,period_proof_hash FROM tax_losses WHERE supplier_id=? AND taxpayer_type="fo" AND origin_year=?');$q->execute([$this->supplierId,self::YEAR]);self::assertSame(['period_proof_kind'=>null,'period_proof_hash'=>null],$q->fetch(PDO::FETCH_ASSOC));
        self::assertSame('draft',$this->returns->reopen($this->supplierId,self::YEAR,'fo',$final['return']['row_version'],$this->userId)['return']['status']);
    }

    /** @param list<array{account_code:string,side:string,amount:float}> $lines */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }
}
