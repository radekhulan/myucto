<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Migration\Shared\FiledDppoImporter;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\TaxLossService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převzetí podaného přiznání k DPPO (EPO XML) do rozpracovaného přiznání a evidence
 * ztrát. Podání staví náš builder ze syntetických čísel; firma v testu nemá účetnictví,
 * takže se liší jen řádky spočtené z účetnictví, řádky ze vstupů musí sedět.
 */
#[Group('integration')]
final class FiledDppoImporterTest extends TestCase
{
    private Connection $db;
    private FiledDppoImporter $importer;
    private TaxReturnRepository $returns;
    private TaxLossService $losses;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(FiledDppoImporter::class);
            $this->returns = $container->get(TaxReturnRepository::class);
            $this->losses = $container->get(TaxLossService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, ic, dic)
             VALUES ("Převzetí DPPO s.r.o.", "Testovací 1", "Praha", "11000", ?, "filed-dppo-test@example.com", ?, ?, "12345679", "CZ12345679")'
        )->execute([$czId, $currencyId, $vatRateId]);
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

    /** @param array<string,mixed> $data @param array<string,mixed> $inputs */
    private function filed(int $year, array $data, array $inputs, string $ic = '12345679'): string
    {
        $calc = (new DppoReturnCalculator())->compute($data, $inputs, TaxConstants::forYear($year));
        return (new DppoXmlBuilder())->build([
            'company_name' => 'Převzetí DPPO s.r.o.', 'street' => 'Testovací 1', 'city' => 'Praha', 'zip' => '11000',
            'country_iso2' => 'CZ', 'ic' => $ic, 'dic' => 'CZ' . $ic, 'taxpayer_type' => 'po', 'financial_office_code' => '451',
        ], $year, $calc)['xml'];
    }

    private function xml2025(): string
    {
        return $this->filed(2025, ['vh' => 500_000, 'non_deductible_costs' => 8_000], [
            'manual_increase_items' => [['text' => 'Nepeněžní plnění statutárnímu orgánu', 'amount' => 400_000, 'line' => 30]],
            'manual_decrease_items' => [['text' => 'Podíly na zisku', 'amount' => 50_000, 'line' => 110]],
            'loss_carryforward' => 100_000,
            'disabled_employees_avg' => 1,
            'tax_paid_advances' => 12_000,
        ]);
    }

    public function testImportsInputsLossesAndMatchesFiledLinesOutsideAccounting(): void
    {
        $xml2024 = $this->filed(2024, ['vh' => -300_000], []);
        $r2024 = $this->importer->apply($this->supplierId, $xml2024, null);
        self::assertSame('created', $r2024['status']);
        self::assertSame(300000.0, $r2024['losses']['year_loss']);

        $preview = $this->importer->preview($this->supplierId, $this->xml2025(), 2025);
        self::assertSame(0, $preview['input_mismatches'], json_encode(array_values(array_filter($preview['diff']['rows'], static fn ($r) => !$r['match'])), JSON_UNESCAPED_UNICODE));
        self::assertFalse($preview['current']['exists']);

        $result = $this->importer->apply($this->supplierId, $this->xml2025(), null, 2025);
        self::assertSame('created', $result['status']);
        $inputs = (array) $this->returns->find($this->supplierId, 2025, 'po')['inputs'];
        self::assertSame(30, $inputs['manual_increase_items'][0]['line']);
        self::assertEquals(400000, $inputs['manual_increase_items'][0]['amount']);
        self::assertSame(40, $inputs['manual_increase_items'][1]['line'], 'ř. 40 nad nedaňové účty firmy (tady bez účetnictví celý)');
        self::assertEquals(8000, $inputs['manual_increase_items'][1]['amount']);
        self::assertSame(110, $inputs['manual_decrease_items'][0]['line']);
        self::assertEquals(100000, $inputs['loss_carryforward']);
        self::assertEquals(12000, $inputs['tax_paid_advances']);
        self::assertEqualsWithDelta(1.0, $inputs['disabled_employees_avg'], 0.0001);
        self::assertSame('B', $inputs['filed_source']['forma']);

        $card = $this->losses->card($this->supplierId, 2025, 'po');
        $loss2024 = array_values(array_filter($card['losses'], static fn ($l) => $l['origin_year'] === 2024))[0];
        self::assertEquals(300000, $loss2024['amount']);
        self::assertEquals(100000, $loss2024['applied'], 'ztráty navazují mezi roky');
    }

    public function testExistingDraftIsKeptUnlessReplacementIsRequestedAndForeignKeysSurvive(): void
    {
        $this->returns->create($this->supplierId, 2025, 'po', ['notes' => 'Poznámka účetní', 'manual_increase_items' => [['text' => 'Stará', 'amount' => 1]]], null);

        $kept = $this->importer->apply($this->supplierId, $this->xml2025(), null, 2025);
        self::assertSame('kept', $kept['status']);
        self::assertSame('Stará', $this->returns->find($this->supplierId, 2025, 'po')['inputs']['manual_increase_items'][0]['text']);

        $replaced = $this->importer->apply($this->supplierId, $this->xml2025(), null, 2025, 'radne', 1, true, false);
        self::assertSame('replaced', $replaced['status']);
        $inputs = (array) $this->returns->find($this->supplierId, 2025, 'po')['inputs'];
        self::assertSame('Poznámka účetní', $inputs['notes']);
        self::assertCount(2, $inputs['manual_increase_items'], 'stará ruční položka se nahradila převzatými');
        self::assertSame(30, $inputs['manual_increase_items'][0]['line']);
    }

    public function testRejectsAnotherCompanysFilingAndWrongYear(): void
    {
        try {
            $this->importer->preview($this->supplierId, $this->filed(2025, ['vh' => 1], [], '87654321'));
            self::fail('Očekávána výjimka pro cizí IČO.');
        } catch (TaxReturnException $e) {
            self::assertSame('filed_other_company', $e->errorCode);
        }
        try {
            $this->importer->preview($this->supplierId, $this->xml2025(), 2024);
            self::fail('Očekávána výjimka pro jiný rok.');
        } catch (TaxReturnException $e) {
            self::assertSame('reconcile_year_mismatch', $e->errorCode);
        }
    }

    public function testHandledPartialLossImportFailureDoesNotCommitDeletedApplications(): void
    {
        $this->losses->reconcileFinalize($this->supplierId, 'po', 2022, 100000, 0, null);
        $this->losses->reconcileFinalize($this->supplierId, 'po', 2023, 100000, 30000, null);
        $this->losses->reconcileFinalize($this->supplierId, 'po', 2024, 0, 160000, null);
        $q = $this->db->pdo()->prepare('SELECT * FROM tax_loss_applications WHERE supplier_id=? ORDER BY id');
        $q->execute([$this->supplierId]); $before = $q->fetchAll(\PDO::FETCH_ASSOC);
        $notices = $this->importer->registerLosses($this->supplierId, 2023, 50000, 0);
        self::assertNotEmpty($notices);
        self::assertStringContainsString('nelze snížit', implode(' ', $notices));
        $q->execute([$this->supplierId]); self::assertSame($before, $q->fetchAll(\PDO::FETCH_ASSOC));
        self::assertTrue($this->db->pdo()->inTransaction());
    }
}
