<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\Migration\StereoNx\StereoNxCashOpeningWriter;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxCashOpeningWriterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImportMap $map;
    private int $supplierId;
    private int $cashId;
    private int $entryId;
    private array $context;
    private array $opening;
    private array $journal;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->map = $container->get(StereoNxImportMap::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $user = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $period = $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2025, '2025-01-01', '2025-12-31');
        $chart = $container->get(ChartOfAccountsRepository::class);
        $cashAccount = $chart->insert($this->supplierId, ['account_code' => '211.710', 'name' => 'Syntetická pokladna', 'account_type' => 'asset', 'normal_side' => 'debit']);
        $openingAccount = $chart->insert($this->supplierId, ['account_code' => '701', 'name' => 'Počáteční účet', 'account_type' => 'equity', 'normal_side' => 'credit']);
        $entry = ['source_key' => '["P","SYN","0","0","0"]', 'date' => '2025-01-01', 'posting_year' => 2025,
            'debit' => '211710', 'credit' => '701', 'target_debit' => '211.710', 'amount_cents' => 147025,
            'is_opening' => true, 'is_red_storno' => false];
        $entry['source_hash'] = StereoNxImportMap::fingerprint($entry);
        $this->entryId = $container->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $period, 'entry_date' => $entry['date'],
            'source_type' => 'opening', 'posted_at' => '2025-01-01 12:00:00', 'posted_by' => $user,
        ], [['account_id' => $cashAccount, 'side' => 'debit', 'amount' => '1470.25', 'line_no' => 1],
            ['account_id' => $openingAccount, 'side' => 'credit', 'amount' => '1470.25', 'line_no' => 2]]);
        $this->map->put($this->supplierId, '12345678', 0, 'accounting_journal', $entry['source_key'], $entry['source_hash'], $this->entryId);
        $movement = ['source_key' => '["2025","SYN","0"]', 'date' => '2025-01-01', 'amount' => 1470.25,
            'currency' => 'CZK', 'document_no' => 'SYN-CASH-OPEN', 'description' => 'Syntetický počáteční stav',
            'requires_draft' => true, 'review_codes' => ['pending_reconciliation']];
        $payments = new MigratedPaymentWriter($this->db);
        $register = $payments->cashRegister($this->supplierId, 'Syntetická pokladna');
        $pdo->prepare('UPDATE cash_registers SET account_code=? WHERE supplier_id=? AND id=?')->execute(['211.710', $this->supplierId, $register]);
        $this->cashId = $payments->insertCash($this->supplierId, $register, $movement['document_no'], $movement['date'], $movement['description'], $movement['amount'], true, $user);
        $this->map->put($this->supplierId, '12345678', 0, 'cash', $movement['source_key'], StereoNxImporter::sourceHash($movement), $this->cashId);
        $this->opening = ['movement' => $movement, 'opening_cents' => 147025, 'entry_keys' => [$entry['source_key']], 'account_code' => '211.710', 'date' => $movement['date']];
        $this->journal = ['accounting_plan' => ['entries' => [$entry]]];
        $this->context = ['supplier_id' => $this->supplierId, 'ico' => '12345678', 'company_index' => 0, 'user_id' => $user,
            'ids' => ['cash' => [$movement['source_key'] => $this->cashId]]];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testPromotesProvenDraftWithoutCreatingOrOwningJournalAndIsIdempotent(): void
    {
        $ledger = $this->ledger();
        self::assertSame(1, $this->write());
        $cash = $this->cash();
        self::assertSame('posted', $cash['status']);
        self::assertSame('other', $cash['purpose']);
        self::assertNull($cash['journal_entry_id']);
        $links = $this->rows("SELECT entry_id,note FROM journal_entry_document_links WHERE supplier_id=? AND doc_type='cash' AND doc_id={$this->cashId}");
        self::assertCount(1, $links);
        self::assertSame($this->entryId, (int) $links[0]['entry_id']);
        self::assertStringStartsWith(OpeningBalanceDocuments::NOTE, $links[0]['note']);
        self::assertSame($ledger, $this->ledger());
        self::assertSame(0, $this->write());
        self::assertSame($ledger, $this->ledger());
        self::assertSame(1, (int) $this->rows("SELECT COUNT(*) n FROM stereo_nx_import_map WHERE supplier_id=? AND kind='cash_opening'")[0]['n']);
        self::assertSame(0, (int) $this->rows("SELECT COUNT(*) n FROM cash_documents WHERE supplier_id=? AND status='posted' AND journal_entry_id IS NULL AND " . OpeningBalanceDocuments::notInOpeningSql('cash', 'cash_documents'))[0]['n']);
    }

    public function testCallerRollbackRestoresDraftAndRemovesOnlyNewLinkAndMap(): void
    {
        $before = $this->snapshot();
        $this->db->pdo()->exec('SAVEPOINT cash_opening_dry');
        $this->write();
        self::assertSame('posted', $this->cash()['status']);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT cash_opening_dry');
        self::assertSame($before, $this->snapshot());
    }

    public function testAccountingPaymentsDoesNotReturnOpeningToOrdinaryMovementReview(): void
    {
        $ledger = $this->ledger();
        $result = $this->importPayments();
        self::assertSame([], $result['warnings']);
        self::assertSame([], $result['review_movements']);
        self::assertSame(1, $result['counts']['cash_openings']);
        self::assertSame('posted', $this->cash()['status']);
        self::assertSame($ledger, $this->ledger());
        self::assertSame(0, $this->importPayments()['counts']['cash_openings']);
    }

    public function testFreshNumberCollisionAcceptsAllocatedNumberButRejectsItsLaterEdit(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM stereo_nx_import_map WHERE supplier_id=? AND kind='cash'")->execute([$this->supplierId]);
        // Původní číslo obsadí samostatný syntetický doklad, nový import musí použít -2.
        $result = $this->importPayments();
        self::assertSame(1, $result['counts']['cash_openings']);
        $key = $this->opening['movement']['source_key'];
        $id = $this->map->get($this->supplierId, '12345678', 0, 'cash', $key)['target_id'];
        $q = $pdo->prepare('SELECT status,doc_number,journal_entry_id FROM cash_documents WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplierId, $id]);
        self::assertSame(['status' => 'posted', 'doc_number' => 'SYN-CASH-OPEN-2', 'journal_entry_id' => null], $q->fetch(PDO::FETCH_ASSOC));
        self::assertSame(0, $this->importPayments()['counts']['cash_openings']);
        $pdo->prepare("UPDATE cash_documents SET doc_number='SYN-CASH-EDIT' WHERE supplier_id=? AND id=?")->execute([$this->supplierId, $id]);
        try {
            $this->importPayments();
            self::fail('Dodatečně změněné číslo musí narušit uložený důkaz.');
        } catch (StereoNxException $e) {
            self::assertSame('cash_opening_unverified', $e->errorCode);
        }
    }

    public function testNegativeOpeningPreservesDirectionWithoutAnOwnJournalEntry(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entry_lines SET side=IF(side='debit','credit','debit') WHERE supplier_id=?")->execute([$this->supplierId]);
        $entry = &$this->journal['accounting_plan']['entries'][0];
        [$entry['debit'], $entry['credit']] = [$entry['credit'], $entry['debit']];
        unset($entry['target_debit']); $entry['target_credit'] = '211.710';
        unset($entry['source_hash']); $entry['source_hash'] = StereoNxImportMap::fingerprint($entry);
        $pdo->prepare("UPDATE stereo_nx_import_map SET source_hash=? WHERE supplier_id=? AND kind='accounting_journal'")->execute([$entry['source_hash'], $this->supplierId]);
        $this->opening['movement']['amount'] *= -1; $this->opening['opening_cents'] *= -1;
        $pdo->prepare("UPDATE stereo_nx_import_map SET source_hash=? WHERE supplier_id=? AND kind='cash'")->execute([StereoNxImporter::sourceHash($this->opening['movement']), $this->supplierId]);
        $pdo->prepare("UPDATE cash_documents SET doc_type='out' WHERE supplier_id=?")->execute([$this->supplierId]);
        $ledger = $this->ledger();
        self::assertSame(1, $this->write());
        self::assertSame('out', $this->cash()['doc_type']);
        self::assertNull($this->cash()['journal_entry_id']);
        self::assertSame($ledger, $this->ledger());
    }

    public function testExistingVatBreakdownCannotBeConfirmedAsAnOpening(): void
    {
        $this->db->pdo()->prepare('INSERT INTO cash_document_vat_lines (cash_document_id,vat_rate,base_amount,vat_amount) VALUES (?,21,100,21)')->execute([$this->cashId]);
        $this->expectException(StereoNxException::class);
        $this->write();
    }

    public function testExistingUnownedOpeningLinkDoesNotAuthorizePostedOrDraftDocument(): void
    {
        $this->db->pdo()->prepare("INSERT INTO journal_entry_document_links (supplier_id,entry_id,doc_type,doc_id,note) VALUES (?,?,'cash',?,?)")
            ->execute([$this->supplierId, $this->entryId, $this->cashId, OpeningBalanceDocuments::NOTE]);
        $before = $this->snapshot();
        try { $this->write(); self::fail('Neznámou vazbu nesmí import převzít.'); }
        catch (StereoNxException $e) { self::assertSame('cash_opening_unverified', $e->errorCode); }
        self::assertSame($before, $this->snapshot());
    }

    public function testWriterRequiresTransaction(): void
    {
        $this->db->pdo()->rollBack();
        try { $this->write(); self::fail('Import vyžaduje transakci.'); }
        catch (StereoNxException $e) { self::assertSame('transaction_required', $e->errorCode); }
    }

    #[DataProvider('changedTarget')]
    public function testChangesToCashOrLedgerFailBeforeAnyWrite(string $sql): void
    {
        $this->db->pdo()->prepare($sql)->execute([$this->supplierId]);
        $before = $this->snapshot();
        try {
            $this->write();
            self::fail('Změněný doklad ani deník nesmí potvrdit počáteční stav.');
        } catch (StereoNxException $e) {
            self::assertSame('cash_opening_unverified', $e->errorCode);
        }
        self::assertSame($before, $this->snapshot());
    }

    public static function changedTarget(): array
    {
        return [
            'částka dokladu' => ['UPDATE cash_documents SET total_amount=total_amount+1 WHERE supplier_id=?'],
            'datum dokladu' => ["UPDATE cash_documents SET issue_date='2025-01-02' WHERE supplier_id=?"],
            'uživatelský popis' => ["UPDATE cash_documents SET description='Jiný syntetický popis' WHERE supplier_id=?"],
            'posted bez důkazu' => ["UPDATE cash_documents SET status='posted' WHERE supplier_id=?"],
            'účel' => ["UPDATE cash_documents SET purpose='sale' WHERE supplier_id=?"],
            'reversed' => ["UPDATE cash_documents SET status='reversed' WHERE supplier_id=?"],
            'měna' => ["UPDATE cash_documents SET currency_code='EUR' WHERE supplier_id=?"],
            'DPH' => ["UPDATE cash_documents SET vat_mode='vat' WHERE supplier_id=?"],
            'registr' => ["UPDATE cash_registers SET account_code='211.999' WHERE supplier_id=?"],
            'řádky deníku' => ['UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=?'],
            'red storno' => ['UPDATE journal_entry_lines SET is_red_storno=1 WHERE supplier_id=?'],
            'FX' => ["UPDATE journal_entry_lines SET currency_code='EUR',amount_foreign=10 WHERE supplier_id=?"],
            'jiný zdroj deníku' => ["UPDATE journal_entries SET source_type='manual' WHERE supplier_id=?"],
            'stornovaný deník' => ['UPDATE journal_entries SET reversed_by=id WHERE supplier_id=?'],
            'nezaúčtovaný deník' => ['UPDATE journal_entries SET posted_at=NULL WHERE supplier_id=?'],
            'změněný sourcehash' => ["UPDATE stereo_nx_import_map SET source_hash=REPEAT('f',64) WHERE supplier_id=? AND kind='accounting_journal'"],
        ];
    }

    private function write(): int
    {
        return (new StereoNxCashOpeningWriter($this->db, $this->map))->write($this->context, [$this->opening], $this->journal);
    }

    private function importPayments(): array
    {
        $importer = Bootstrap::buildApp()->getContainer()->get(StereoNxImporter::class);
        return $importer->writeAccountingPayments(['identity' => ['ico' => '12345678'], 'source_company_index' => 0,
            'cash_transactions' => [$this->opening['movement']], 'cash_openings' => [$this->opening], 'journal_plan' => $this->journal],
            $this->supplierId, $this->context['user_id']);
    }

    private function cash(): array
    {
        return $this->rows("SELECT * FROM cash_documents WHERE supplier_id=? AND id={$this->cashId}")[0];
    }

    private function ledger(): array
    {
        return [$this->rows('SELECT * FROM journal_entries WHERE supplier_id=? ORDER BY id'), $this->rows('SELECT * FROM journal_entry_lines WHERE supplier_id=? ORDER BY id')];
    }

    private function rows(string $sql): array
    {
        $q = $this->db->pdo()->prepare($sql);
        $q->execute([$this->supplierId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function snapshot(): array
    {
        return [$this->cash(), $this->ledger(), $this->rows('SELECT * FROM journal_entry_document_links WHERE supplier_id=? ORDER BY id'),
            $this->rows('SELECT * FROM stereo_nx_import_map WHERE supplier_id=? ORDER BY kind,source_key')];
    }
}
