<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\Activation\DocumentBackfill;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingBackfillJobService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test backfillu deníku (api/bin/backfill-accounting.php).
 *
 * Zrcadlí jádro backfillu (buildFromInvoice/buildFromPurchaseInvoice → postDocument)
 * nad čerstvě založenou vydanou i přijatou fakturou testovací firmy. Ověřuje, že:
 *   - deník je po backfillu vyvážený (Σ MD == Σ D v haléřích, přes journal_entry_lines),
 *   - druhý běh je idempotentní (žádné duplicity, stejná id zápisů, stále vyváženo).
 * Vše běží v jedné transakci → tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class BackfillAccountingTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private DocumentBackfill $backfill;
    private PendingBackfillCounter $pending;
    private PostingBackfillJobService $job;
    private ImportJobRepository $jobs;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->backfill = $container->get(DocumentBackfill::class);
            $this->pending = $container->get(PendingBackfillCounter::class);
            $this->job     = $container->get(PostingBackfillJobService::class);
            $this->jobs    = $container->get(ImportJobRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolace od existujícího deníku suppliera (v rámci rollbacku) — test počítá zápisy absolutně.
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->ensureOpenPeriodFor($this->supplierId, self::YEAR . '-06-15');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            } else {
                // Transakce už neexistuje → někde uvnitř backfillu proběhl IMPLICITNÍ
                // commit (v MariaDB ho způsobí každé DDL) a rollback tím pádem nic
                // neuklidil. Data by přežila do dalších testů: konkrétně bankovní výpis
                // rozbil BankStatementOwnershipSweepTest, protože `lastBankImportAt`
                // pro tenanta najednou nevracel null.
                //
                // Úklid je proto explicitní a idempotentní, podle markerů tohoto testu.
                // (Projevilo se to až po doplnění seedu do testovací DB — do té doby
                // se celý test tiše přeskakoval.)
                $this->cleanupLeftovers($pdo);
            }
            $this->db->close();
        }
    }

    /** Nouzový úklid pro případ, že transakci zrušil implicitní commit. */
    private function cleanupLeftovers(\PDO $pdo): void
    {
        try {
            $pdo->prepare("DELETE FROM bank_statements WHERE file_name = 'activation-advance.gpc'")->execute();
            $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol LIKE '%BF-2098%'")
                ->execute([$this->supplierId]);
            $pdo->prepare("DELETE FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number LIKE '%BF-2098%'")
                ->execute([$this->supplierId]);
        } catch (\Throwable $e) {
            fwrite(STDERR, '[BackfillAccountingTest] Úklid selhal: ' . $e->getMessage() . "\n");
        }
    }

    public function testBackfillBalancesAndIsIdempotent(): void
    {
        $clientId   = $this->client('Protistrana s.r.o.');
        $invoiceId  = $this->sale('FV-BF-2098-1', $clientId, 1000.00, 210.00, 21.00);
        $purchaseId = $this->purchase('PF-BF-2098-1', $clientId, 500.00, 105.00, 21.00);

        // 1. běh — zaúčtování obou dokladů (jádro backfillu)
        $invLines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryInv = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $invLines, [
            'entry_date' => self::YEAR . '-06-15',
        ]);
        $piLines  = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryPi  = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $piLines, [
            'entry_date' => self::YEAR . '-06-15',
        ]);

        self::assertGreaterThan(0, $entryInv);
        self::assertGreaterThan(0, $entryPi);
        self::assertSame(2, $this->entryCount(), 'Vznikly právě 2 zápisy.');

        // BALANCE CHECK — Σ MD == Σ D přes celý deník firmy (v haléřích)
        $bal = $this->balanceCents();
        self::assertSame($bal['debit'], $bal['credit'], 'Deník je vyvážený (Σ MD == Σ D).');
        self::assertGreaterThan(0, $bal['debit'], 'Deník není prázdný.');

        // 2. běh — idempotence: přepočet téhož, žádné duplicity, stejná id
        $entryInv2 = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId), ['entry_date' => self::YEAR . '-06-15']);
        $entryPi2  = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId), ['entry_date' => self::YEAR . '-06-15']);

        self::assertSame($entryInv, $entryInv2, 'Idempotence — vydaná faktura má týž zápis.');
        self::assertSame($entryPi, $entryPi2, 'Idempotence — přijatá faktura má týž zápis.');
        self::assertSame(2, $this->entryCount(), 'Po druhém běhu stále 2 zápisy (žádné duplicity).');

        $bal2 = $this->balanceCents();
        self::assertSame($bal2['debit'], $bal2['credit'], 'Deník zůstal vyvážený i po druhém běhu.');
        self::assertSame($bal['debit'], $bal2['debit'], 'Součty se přepočtem nezměnily.');
    }

    public function testBackfillIncludesEveryPostableIssuedDocumentType(): void
    {
        $clientId = $this->client('Typy vydaných dokladů s.r.o.');
        $before = $this->pending->count($this->supplierId, self::YEAR . '-01-01')['invoices'];
        $ids = [
            $this->sale('DB-BF-2098-1', $clientId, -1000.00, -210.00, 21.00, 'credit_note'),
            $this->sale('DD-BF-2098-1', $clientId, 1000.00, 210.00, 21.00, 'tax_document'),
            $this->sale('PN-BF-2098-1', $clientId, 75.00, 0.00, 0.00, 'penalty'),
        ];

        self::assertSame($before + 3, $this->pending->count($this->supplierId, self::YEAR . '-01-01')['invoices']);

        $expected = $this->pending->count($this->supplierId, self::YEAR . '-01-01');
        $dryRun = $this->backfill->run($this->supplierId, null, self::YEAR, true);
        self::assertSame($expected['invoices'], $dryRun['invoice']['posted'], 'Dry-run musí pokrýt všechny nezaúčtované vydané doklady.');
        self::assertSame($expected['purchase_invoices'], $dryRun['purchase_invoice']['posted'], 'Dry-run musí pokrýt všechny nezaúčtované přijaté doklady.');

        $this->backfill->run($this->supplierId, null, self::YEAR, false);
        foreach ($ids as $id) {
            self::assertSame(1, $this->sourceEntryCount('invoice', $id));
        }
        self::assertSame($before, $this->pending->count($this->supplierId, self::YEAR . '-01-01')['invoices']);
        self::assertStringStartsWith('Vydaný dobropis', $this->sourceDescription('invoice', $ids[0]));
        self::assertStringStartsWith('Daňový doklad k přijaté platbě', $this->sourceDescription('invoice', $ids[1]));
        self::assertStringStartsWith('Penalizační faktura', $this->sourceDescription('invoice', $ids[2]));

        $this->backfill->run($this->supplierId, null, self::YEAR, false);
        foreach ($ids as $id) {
            self::assertSame(1, $this->sourceEntryCount('invoice', $id), 'Opakovaný backfill nesmí vytvořit duplicitu.');
        }
    }

    public function testPurchaseAdvanceRequiresPaymentLegInsteadOfDocumentPosting(): void
    {
        $vendorId = $this->client('Dodavatel zálohy s.r.o.');
        $pendingBefore = $this->pending->count($this->supplierId, self::YEAR . '-01-01')['purchase_invoices'];
        $advanceId = $this->purchase('ZAL-BF-2098-1', $vendorId, 1000.00, 210.00, 21.00, 'advance');

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $advanceId);
            self::fail('Přijatá zálohová výzva nesmí vytvořit předpis 321/náklad.');
        } catch (PostingException $e) {
            self::assertSame('advance_payment_only', $e->errorCode);
            self::assertStringContainsString('314', $e->getMessage());
        }

        $report = $this->backfill->run($this->supplierId, null, self::YEAR, true);
        $issues = array_values(array_filter(
            $report['document_issues'],
            static fn (array $issue): bool => $issue['source_type'] === 'purchase_invoice'
                && $issue['source_id'] === $advanceId,
        ));

        self::assertSame(0, $report['purchase_invoice']['failed']);
        self::assertSame($pendingBefore, $this->pending->count($this->supplierId, self::YEAR . '-01-01')['purchase_invoices']);
        self::assertSame([], $issues);
        self::assertSame(0, $this->sourceEntryCount('purchase_invoice', $advanceId));
    }

    public function testBackfillReportsTheExactDocumentThatFailed(): void
    {
        $vendorId = $this->client('Dodavatel nepodporované kombinace s.r.o.');
        $purchaseId = $this->purchase('ERR-BF-2098-1', $vendorId, 1000.00, 210.00, 21.00);
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET reverse_charge = 1, vat_deduction = 'proportional' WHERE id = ?"
        )->execute([$purchaseId]);

        $report = $this->backfill->run($this->supplierId, null, self::YEAR, true);
        $issues = array_values(array_filter(
            $report['document_issues'],
            static fn (array $issue): bool => $issue['source_type'] === 'purchase_invoice'
                && $issue['source_id'] === $purchaseId,
        ));

        self::assertGreaterThanOrEqual(1, $report['purchase_invoice']['failed']);
        self::assertCount(1, $issues);
        self::assertSame('failed', $issues[0]['severity']);
        self::assertSame('rc_partial_deduction_unsupported', $issues[0]['error_code']);
        self::assertSame('ERR-BF-2098-1', $issues[0]['document_no']);
    }

    public function testSettlementPassUpdatesFinalInvoiceAfterAdvancePaymentPosting(): void
    {
        $clientId = $this->client('Odběratel se zálohou s.r.o.');
        $proformaId = $this->sale('PRO-BF-2098', $clientId, 500.00, 105.00, 21.00, 'proforma');
        $finalId = $this->sale('FV-BF-2098-Z', $clientId, 500.00, 105.00, 21.00, 'invoice', $proformaId);

        $this->backfill->run($this->supplierId, null, self::YEAR, false);
        self::assertSame(0.0, $this->sourceAccountAmount('invoice', $finalId, '324', 'debit'));

        $statement = $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, ?, ?, ?, ?, "CZK", ?, ?)'
        );
        $statement->execute([
            $this->supplierId,
            'activation-advance.gpc',
            hash('sha256', uniqid('activation-advance', true)),
            '1000000005',
            '0100',
            self::YEAR . '-06-15',
            $this->userId,
        ]);
        $statementId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol,
                 counterparty_name, description, match_status, matched_invoice_id)
             VALUES (?, "statement", ?, 605.00, "CZK", ?, "Syntetický odběratel", "Úhrada zálohy", "auto_exact", ?)'
        )->execute([$statementId, self::YEAR . '-06-15', 'PRO-BF-2098', $proformaId]);
        $txId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, 605.00, "CZK", "bank", ?)'
        )->execute([$this->supplierId, $proformaId, self::YEAR . '-06-15', $txId]);
        $this->posting->postDocument(
            $this->supplierId,
            'bank',
            $txId,
            $this->posting->buildFromAdvancePayment($this->supplierId, 'received', '221', 605.00),
            ['entry_date' => self::YEAR . '-06-15'],
        );

        $settlements = $this->backfill->run(
            $this->supplierId,
            null,
            self::YEAR,
            false,
            false,
            null,
            null,
            true,
        );

        self::assertSame(1, $settlements['invoice']['updated']);
        self::assertSame(605.0, $this->sourceAccountAmount('invoice', $finalId, '324', 'debit'));
        self::assertSame(1, $this->sourceEntryCount('invoice', $finalId), 'Druhý průchod přepíše původní zápis in-place.');
    }

    public function testPostingBackfillJobPostsOnlyUnpostedDocuments(): void
    {
        $clientId = $this->client('Doúčtování s.r.o.');
        $postedId = $this->sale('FV-BF-2098-P', $clientId, 1000.00, 210.00, 21.00);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $postedId,
            $this->posting->buildFromInvoice($this->supplierId, $postedId), [
                'entry_date' => self::YEAR . '-06-15',
                'posted_at' => self::YEAR . '-06-16 08:00:00',
                'posted_by' => $this->userId,
            ]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET description = ? WHERE id = ?')
            ->execute(['Ručně upravený popis', $entryId]);
        $before = $this->entryHeader($entryId);

        $unpostedId = $this->sale('FV-BF-2098-N', $clientId, 500.00, 105.00, 21.00);

        $jobId = $this->jobs->create($this->supplierId, 'document_backfill',
            ['from' => null, 'year' => self::YEAR, 'dry_run' => false], $this->userId);
        try {
            $this->job->run($jobId);
        } finally {
            @unlink(RuntimePaths::storage('posting-backfill/' . $this->supplierId) . '/' . $jobId . '.json');
        }

        self::assertSame($before, $this->entryHeader($entryId), 'Doúčtování nesmí přepsat už zaúčtovaný doklad.');
        self::assertSame(1, $this->sourceEntryCount('invoice', $unpostedId), 'Nezaúčtovaný doklad se zaúčtuje.');
        self::assertSame(1, (int) $this->jobs->findById($jobId)['created_count']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function entryHeader(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT description, document_no, document_date, posted_at, posted_by, row_version
               FROM journal_entries WHERE id = ?'
        );
        $stmt->execute([$entryId]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function entryCount(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}"
        )->fetchColumn();
    }

    private function sourceEntryCount(string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ?'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        return (int) $stmt->fetchColumn();
    }

    private function sourceDescription(string $sourceType, int $sourceId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT description FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        return (string) $stmt->fetchColumn();
    }

    private function sourceAccountAmount(string $sourceType, int $sourceId, string $accountCode, string $side): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(jel.amount), 0)
               FROM journal_entries je
               JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ?
                AND coa.account_code = ? AND jel.side = ? AND je.reversed_by IS NULL'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId, $accountCode, $side]);
        return (float) $stmt->fetchColumn();
    }

    /** @return array{debit:int, credit:int} součty stran v haléřích */
    private function balanceCents(): array
    {
        $row = $this->db->pdo()->query(
            "SELECT
                CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'debit'  THEN amount END), 0) * 100) AS SIGNED) AS debit_cents,
                CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'credit' THEN amount END), 0) * 100) AS SIGNED) AS credit_cents
               FROM journal_entry_lines
              WHERE supplier_id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        return ['debit' => (int) $row['debit_cents'], 'credit' => (int) $row['credit_cents']];
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(
        string $varsymbol,
        int $clientId,
        float $base,
        float $vat,
        float $rate,
        string $invoiceType = 'invoice',
        ?int $parentInvoiceId = null,
    ): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $invoiceType, $parentInvoiceId, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0, "1")'
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }

    private function purchase(
        string $number,
        int $vendorId,
        float $base,
        float $vat,
        float $rate,
        string $documentKind = 'invoice',
    ): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", ?)'
        );
        $snapshot = json_encode(['company_name' => 'Protistrana s.r.o.'], JSON_UNESCAPED_UNICODE);
        $stmt->execute([$this->supplierId, $vendorId, $number, $snapshot, $documentKind, $issue, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0, "40")'
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }
}
