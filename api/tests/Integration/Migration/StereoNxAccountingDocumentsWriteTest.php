<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Fixtures/StereoNx/SyntheticStereoNxTables.php';

#[Group('integration')]
final class StereoNxAccountingDocumentsWriteTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImporter $importer;
    private VatLedgerService $vatLedger;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxImporter::class);
        $this->vatLedger = $container->get(VatLedgerService::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $identity = SyntheticStereoNxTables::identity();
        $pdo->prepare("UPDATE supplier SET company_name = ?, ic = ?, dic = ?, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        foreach ([['CZK', 'Kč', 1], ['EUR', '€', 0]] as [$code, $symbol, $default]) {
            $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)')->execute([
                    $this->supplierId, $code, $code, $symbol, $code, $code, $default,
                ]);
            if ($default === 1) {
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                    ->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
            }
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testSharedWriterCreatesDocumentsOnceAndNeverPostsThem(): void
    {
        $plan = $this->plan();
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);

        $this->db->pdo()->exec('SAVEPOINT stereo_documents_dry');
        $dry = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        self::assertGreaterThan(0, array_sum($dry['counts']));
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT stereo_documents_dry');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?'));

        $first = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        self::assertSame(count($plan['issued']), $first['counts']['issued']);
        self::assertSame(count($plan['purchases']), $first['counts']['purchases']);
        self::assertSame(count($plan['issued']), $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?'));
        self::assertSame(count($plan['purchases']), $this->scalar('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));

        $again = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        self::assertSame(0, $again['counts']['issued']);
        self::assertSame(0, $again['counts']['purchases']);
        self::assertSame(count($plan['issued']), $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?'));
        self::assertSame(count($plan['purchases']), $this->scalar('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?'));
        foreach ($again['review_documents'] as $review) self::assertGreaterThan(0, $review['target_id']);
    }

    public function testForeignDraftKeepsSignedTotalCurrencyRateAndNoVatOrJournal(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $header = $tables['Svfh'][0];
        $line = $tables['Svfp'][0];
        $header['Mena'] = 'EUR'; $header['Kurz'] = 25.1; $header['KurzMn'] = 1;
        $header['Celkem'] = -10.0; $header['TypDokladu'] = 'D'; $header['CenySDPH'] = false;
        $header['CelkemVlastni'] = -251.0;
        $line['Klic'] = -2147483647; $line['Mnozstvi'] = 2.0; $line['JednCenaC'] = -5.0;
        $line['ZakladDPH'] = -251.0; $line['CelkemDPH'] = 0.0;
        $tables['Svfh'] = [$header]; $tables['Svfp'] = [$line];
        $tables['SPFH'] = []; $tables['Spfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);

        $result = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        $row = $this->db->pdo()->query('SELECT i.invoice_type, i.status, i.total_without_vat, i.total_vat,
            i.total_with_vat, i.exchange_rate, c.code FROM invoices i JOIN currencies c ON c.id = i.currency_id
            WHERE i.supplier_id = ' . $this->supplierId)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('credit_note', $row['invoice_type']);
        self::assertSame('draft', $row['status']);
        self::assertSame('-10.00', $row['total_without_vat']);
        self::assertSame('0.00', $row['total_vat']);
        self::assertSame('-10.00', $row['total_with_vat']);
        self::assertEqualsWithDelta(25.1, (float) $row['exchange_rate'], 0.000001);
        self::assertSame('EUR', $row['code']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));
        self::assertGreaterThan(0, $result['review_documents'][0]['target_id']);

        $changed = $plan;
        $changed['issued'][0]['foreign_home_total'] = -252.0;
        try {
            $this->importer->writeAccountingDocuments($changed, $this->supplierId, $this->userId);
            self::fail('Změněný korunový důkaz musí změnit otisk zdroje.');
        } catch (\MyInvoice\Service\Migration\StereoNx\StereoNxException $e) {
            self::assertSame('source_changed', $e->errorCode);
        }
    }

    public function testForeignDocumentWithoutHomeTotalIsBlockedBeforeWrite(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['Mena'] = 'EUR';
        $tables['Svfh'][0]['Kurz'] = 25.1;
        $tables['Svfh'][0]['Celkem'] = 10.0;
        $tables['Svfp'][0]['Mnozstvi'] = 2.0;
        $tables['Svfp'][0]['JednCenaC'] = 5.0;
        $tables['SPFH'] = []; $tables['Spfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);

        try {
            $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
            self::fail('Doklad bez ověřeného korunového celku nesmí být zapsán.');
        } catch (\MyInvoice\Service\Migration\StereoNx\StereoNxException $e) {
            self::assertSame('document_foreign_unverified', $e->errorCode);
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?'));
        }
    }

    public function testCreditNoteAndProformaKeepTypesAndOnlyCreditNoteEntersVatLedger(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $credit = $tables['Svfh'][0];
        $credit['TypDokladu'] = 'D';
        $credit['ZaklDPHz'] = -100.0;
        $credit['DPHz'] = -21.0;
        $credit['Celkem'] = -121.0;
        $creditLine = $tables['Svfp'][0];
        $creditLine['Mnozstvi'] = -1.0;
        $creditLine['ZakladDPH'] = -100.0;
        $creditLine['CelkemDPH'] = -21.0;
        $proforma = $tables['Svfh'][0];
        $proforma['DoklSRada'] = 'VP';
        $proforma['DokladS'] = 'VP-1';
        $proforma['TypDokladu'] = 'P';
        $proforma['ZpracovatDPH'] = false;
        $proformaLine = $tables['Svfp'][0];
        $proformaLine['DoklSRada'] = 'VP';
        $proformaLine['ZalohaProforma'] = true;
        $tables['Svfh'] = [$credit, $proforma];
        $tables['Svfp'] = [$creditLine, $proformaLine];
        $tables['SPFH'] = [];
        $tables['Spfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);

        $result = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        self::assertSame(2, $result['counts']['issued']);
        $rows = $this->db->pdo()->query('SELECT id, invoice_type, status, total_without_vat, total_vat,
            total_with_vat FROM invoices WHERE supplier_id = ' . $this->supplierId)->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        $byType = array_column($rows, null, 'invoice_type');
        self::assertSame(['sent', 'sent'], [$byType['credit_note']['status'], $byType['proforma']['status']]);
        self::assertSame('-100.00', $byType['credit_note']['total_without_vat']);
        self::assertSame('-21.00', $byType['credit_note']['total_vat']);
        self::assertSame('-121.00', $byType['credit_note']['total_with_vat']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM invoice_payments ip
            JOIN invoices i ON i.id = ip.invoice_id WHERE i.supplier_id = ?'));

        $ledger = $this->vatLedger->rows($this->supplierId, '2025-03-01', '2025-03-31');
        $invoiceIds = array_map(static fn (array $row): int => (int) ($row['invoice_id'] ?? 0),
            array_filter($ledger, static fn (array $row): bool => ($row['source'] ?? '') === 'sale'));
        self::assertContains((int) $byType['credit_note']['id'], $invoiceIds);
        self::assertNotContains((int) $byType['proforma']['id'], $invoiceIds);
    }

    public function testAdvanceDocumentsKeepRecapButDoNotPostVatOrJournal(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['TypDokladu'] = 'Z';
        $tables['Svfh'][0]['ZpracovatDPH'] = false;
        $tables['Svfh'][0]['DatumDPH'] = null;
        $tables['Svfp'][0]['ZalohaProforma'] = true;
        $tables['SPFH'][0]['TypDokladu'] = 'Z';
        $tables['SPFH'][0]['ZpracovatDPH'] = false;
        $tables['SPFH'][0]['DatumDPH'] = null;
        $tables['SPFH'] = [$tables['SPFH'][0]];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);

        $result = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        self::assertSame(1, $result['counts']['issued']);
        self::assertSame(1, $result['counts']['purchases']);
        self::assertSame([], $result['review_documents']);
        $issued = $this->db->pdo()->query('SELECT id, invoice_type, status, total_vat FROM invoices
            WHERE supplier_id = ' . $this->supplierId)->fetch(\PDO::FETCH_ASSOC);
        $purchase = $this->db->pdo()->query('SELECT id, document_kind, status, total_vat FROM purchase_invoices
            WHERE supplier_id = ' . $this->supplierId)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('proforma', $issued['invoice_type']);
        self::assertSame('advance', $purchase['document_kind']);
        self::assertSame('sent', $issued['status']);
        self::assertSame('received', $purchase['status']);
        self::assertSame('21.00', $issued['total_vat']);
        self::assertSame('21.00', $purchase['total_vat']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?'));
        $ledger = $this->vatLedger->rows($this->supplierId, '2025-03-01', '2025-03-31');
        $ids = array_map(static fn (array $r): int => (int) ($r['invoice_id'] ?? 0), $ledger);
        self::assertNotContains((int) $issued['id'], $ids);
        self::assertNotContains((int) $purchase['id'], $ids);
    }

    public function testChangedSourceDocumentIsRejected(): void
    {
        $plan = $this->plan();
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);
        $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        $plan['issued'][0]['note'] = 'Změněný zdroj';

        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        $this->expectExceptionMessage('změnil');
        $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
    }

    public function testMissingIssuedItemsAreReconstructedFromRevenuePostingsWithoutChangingVatOrSourceIdentity(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfp'] = [];
        $tables['SPFH'] = $tables['Spfp'] = [];
        $source = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $source['source_company_index'] = 1;
        self::assertSame('vat_recap', $source['issued'][0]['origin']);
        self::assertCount(1, $source['issued'][0]['items']);
        $journal = ['accounting_plan' => ['entries' => []], 'account_mapping' => ['accounts' => []]];
        foreach ([['602', 6000], ['604', 4000], ['343.200', 2100]] as $index => [$account, $amount]) {
            $journal['accounting_plan']['entries'][] = [
                'source_key' => json_encode(['VF', 'VF', '1', '1', (string) $index], JSON_THROW_ON_ERROR),
                'date' => '2025-03-15', 'debit' => '311', 'credit' => $account,
                'target_debit' => '311', 'target_credit' => $account,
                'amount_cents' => $amount, 'is_opening' => false, 'is_red_storno' => false,
            ];
        }
        $mapped = StereoNxAccountingDocuments::withAccountMapping([
            'records' => ['issued' => $source['issued'], 'purchases' => []], 'warnings' => [],
        ], $journal);
        self::assertSame($source['issued'][0]['items'], $mapped['records']['issued'][0]['items']);
        self::assertCount(2, $mapped['records']['issued'][0]['target_items'] ?? [],
            'Jedinou souhrnnou položku musí nahradit doložené výnosové řádky 602 a 604.');
        self::assertContains('document_items_reconstructed', array_column($mapped['warnings'], 'code'));
        self::assertNotContains('document_item_account_ambiguous', array_column($mapped['warnings'], 'code'));
        $sourceHash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        self::assertSame($sourceHash->invoke(null, $source['issued'][0]),
            $sourceHash->invoke(null, $mapped['records']['issued'][0]));
        $mappedSource = [...$source, 'issued' => $mapped['records']['issued']];
        $this->importer->writeAccountingPartners($source['clients'], $source['identity'], 1, $this->supplierId);
        $first = $this->importer->writeAccountingDocuments($mappedSource, $this->supplierId, $this->userId);
        self::assertSame(1, $first['counts']['issued']);
        $pdo = $this->db->pdo();
        $invoice = $pdo->prepare('SELECT id, status, total_without_vat, total_vat, total_with_vat
            FROM invoices WHERE supplier_id=?');
        $invoice->execute([$this->supplierId]);
        $invoice = $invoice->fetch(\PDO::FETCH_ASSOC);
        $invoiceId = (int) $invoice['id'];
        self::assertSame('draft', $invoice['status'], 'Účetní rekonstrukce nenahrazuje kontrolu původních obchodních položek.');
        self::assertSame(['100.00', '21.00', '121.00'],
            [$invoice['total_without_vat'], $invoice['total_vat'], $invoice['total_with_vat']]);
        $items = $pdo->prepare('SELECT revenue_account_code, total_without_vat, total_vat, total_with_vat
            FROM invoice_items WHERE invoice_id=? ORDER BY revenue_account_code');
        $items->execute([$invoiceId]);
        self::assertSame([
            ['revenue_account_code' => '602', 'total_without_vat' => '60.00', 'total_vat' => '12.60', 'total_with_vat' => '72.60'],
            ['revenue_account_code' => '604', 'total_without_vat' => '40.00', 'total_vat' => '8.40', 'total_with_vat' => '48.40'],
        ], $items->fetchAll(\PDO::FETCH_ASSOC));
        $storedHash = $pdo->prepare("SELECT source_hash FROM stereo_nx_import_map
            WHERE supplier_id=? AND kind='issued' AND source_key=?");
        $storedHash->execute([$this->supplierId, $source['issued'][0]['source_key']]);
        self::assertSame($sourceHash->invoke(null, $source['issued'][0]), $storedHash->fetchColumn());

        foreach ([$mappedSource, $source] as $repeatSource) {
            $repeat = $this->importer->writeAccountingDocuments($repeatSource, $this->supplierId, $this->userId);
            self::assertSame(0, $repeat['counts']['issued']);
        }
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM invoices WHERE supplier_id=?'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM invoice_items ii
            JOIN invoices i ON i.id=ii.invoice_id WHERE i.supplier_id=?'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?'));

        // Rekonstrukce účtů není schválením chybějících obchodních položek; kontrolu
        // syntetického konceptu tu provede uživatel před nativním zaúčtováním.
        $pdo->prepare("UPDATE invoices SET status='sent' WHERE id=? AND supplier_id=?")
            ->execute([$invoiceId, $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $ledger = $this->vatLedger->documentRows($this->supplierId, 'sale', $invoiceId, '2025-03-01', '2025-03-31');
        self::assertNotEmpty($ledger);
        self::assertSame(100.0, array_sum(array_column($ledger, 'base_czk')));
        self::assertSame(21.0, array_sum(array_column($ledger, 'vat_czk')));
        $container = Bootstrap::buildApp()->getContainer();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $posting = $container->get(PostingService::class);
        $native = $posting->buildFromInvoice($this->supplierId, $invoiceId);
        self::assertSame(['602' => 60.0, '604' => 40.0], $this->revenueAmounts($native));
        $pdo->prepare("UPDATE invoice_items SET unit_price_without_vat=total_without_vat*2,
            total_without_vat=total_without_vat*2, total_vat=total_vat*2, total_with_vat=total_with_vat*2
            WHERE invoice_id=?")->execute([$invoiceId]);
        $pdo->prepare("UPDATE invoices SET total_without_vat=200, total_vat=42, total_with_vat=242,
            tax_date='2025-03-16' WHERE id=? AND supplier_id=?")->execute([$invoiceId, $this->supplierId]);
        self::assertSame(['602' => 120.0, '604' => 80.0],
            $this->revenueAmounts($posting->buildFromInvoice($this->supplierId, $invoiceId)));
    }

    public function testMissingDocumentCurrencyGivesActionableConfigurationError(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $header = $tables['Svfh'][0];
        $line = $tables['Svfp'][0];
        $header['Mena'] = 'EUR'; $header['Kurz'] = 25.1; $header['KurzMn'] = 1;
        $header['Celkem'] = 10.0; $header['CenySDPH'] = false;
        $header['CelkemVlastni'] = 251.0;
        $line['Mnozstvi'] = 2.0; $line['JednCenaC'] = 5.0;
        $line['ZakladDPH'] = 251.0; $line['CelkemDPH'] = 0.0;
        $tables['Svfh'] = [$header]; $tables['Svfp'] = [$line];
        $tables['SPFH'] = []; $tables['Spfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);
        $this->db->pdo()->prepare("DELETE FROM currencies WHERE supplier_id=? AND code='EUR'")->execute([$this->supplierId]);

        $this->expectException(\MyInvoice\Service\Migration\StereoNx\StereoNxException::class);
        $this->expectExceptionMessage('Nastavení měn');
        $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
    }

    public function testDefaultDocumentFieldsRemainCompatibleWithExistingImportHash(): void
    {
        $plan = $this->plan();
        $plan['source_company_index'] = 1;
        $this->importer->writeAccountingPartners($plan['clients'], $plan['identity'], 1, $this->supplierId);
        $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);

        $record = $plan['issued'][0];
        unset($record['row'], $record['header'], $record['currency_code'], $record['exchange_rate'], $record['target_document_kind']);
        $oldHash = StereoNxImportMap::fingerprint($record);
        $this->db->pdo()->prepare("UPDATE stereo_nx_import_map SET source_hash = ?
            WHERE supplier_id = ? AND source_ico = ? AND source_company_index = 1 AND kind = 'issued' AND source_key = ?")
            ->execute([$oldHash, $this->supplierId, $plan['identity']['ico'], $plan['issued'][0]['source_key']]);

        $repeat = $this->importer->writeAccountingDocuments($plan, $this->supplierId, $this->userId);
        self::assertSame(0, $repeat['counts']['issued']);
        self::assertSame(0, $repeat['counts']['purchases']);
    }

    private function plan(): array
    {
        return StereoNxSourcePlan::fromTables(
            SyntheticStereoNxTables::tables(), SyntheticStereoNxTables::identity(), true, true,
        );
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<array<string,mixed>> $lines @return array<string,float> */
    private function revenueAmounts(array $lines): array
    {
        $amounts = [];
        foreach ($lines as $line) {
            if (in_array($line['account_code'], ['602', '604'], true)) {
                self::assertSame('credit', $line['side']);
                $amounts[$line['account_code']] = ($amounts[$line['account_code']] ?? 0.0) + (float) $line['amount'];
            }
        }
        ksort($amounts);
        return $amounts;
    }
}
