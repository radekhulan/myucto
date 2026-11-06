<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\MoneyS3MigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Importer;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3ImportJobService;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class MoneyS3ImportWorkflowTest extends MoneyS3ImportTestCase
{
    /**
     * Faktura převedená dřív jako neuhrazená a spárovaná s úhradou až v dalším běhu
     * (novější záloha) musí dostat i stav „uhrazeno" — samotný záznam párování nestačí.
     */
    public function testLaterRunMarksInvoicePaidWhenItsPaymentIsLinked(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $pdo = $this->db->pdo();
        $find = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2024-017'");
        $find->execute([$supplierId]);
        $invoiceId = (int) $find->fetchColumn();
        // Stav po dřívějším běhu, kdy faktura v záloze ještě uhrazená nebyla.
        $pdo->prepare("UPDATE purchase_invoices SET status = 'booked', paid_at = NULL WHERE id = ? AND supplier_id = ?")->execute([$invoiceId, $supplierId]);
        $pdo->prepare('DELETE FROM payment_matches WHERE purchase_invoice_id = ? AND supplier_id = ?')->execute([$invoiceId, $supplierId]);
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'payment' AND money_key = 'p|2024|FP24001'")->execute([$supplierId]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId, "purchase_invoice_id = {$invoiceId}"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "id = {$invoiceId} AND status = 'paid' AND paid_at = '2024-02-20'"));
    }

    /**
     * Opakovaný převod novější zálohy existující doklad nepřepisuje (může být už
     * zaúčtovaný nebo upravený v MyÚčtu), ale změnu v Money musí ohlásit.
     */
    public function testChangeInMoneyAfterImportIsReportedNotOverwritten(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET total_with_vat = 6000 WHERE supplier_id = ? AND vendor_invoice_number = 'DF-2025-003'")
            ->execute([$supplierId]);

        $protocol = $this->import($supplierId);

        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertArrayHasKey('purchase_invoices', $steps, $this->explain($protocol));
        $messages = $steps['purchase_invoices']['messages'];
        $changed = array_values(array_filter($messages, static fn (array $m): bool => $m['code'] === 'changed_in_money'));
        self::assertCount(1, $changed, $this->explain($protocol));
        self::assertSame('FP25001', $changed[0]['context']['document_no']);
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003' AND total_with_vat = 6000"));
    }

    public function testRepeatedImportCreatesNothingNew(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $before = $this->snapshotCounts($supplierId);

        $second = $this->import($supplierId);

        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame($before, $this->snapshotCounts($supplierId));
        $journal = array_column($second->toArray()['steps'], null, 'key')['journal'];
        self::assertSame(0, $journal['counts']['entries'] ?? 0);
    }

    public function testAutomationIsOffDuringImportAndRestoredAfterwards(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);

        $protocol = $this->import($supplierId);

        $automation = $protocol->get('automation');
        self::assertSame('off', $automation['during']);
        self::assertTrue($automation['restored']);
        self::assertSame('assisted', $automation['after']);
    }

    /**
     * Spadlý worker nezapíše protokol a řádek běhu zůstane „running". Další běh musí
     * automatiku vrátit na stav PŘED prvním během, ne na „vypnuto" po pádu.
     */
    public function testCrashedImportRestoresAutomationFromBeforeTheCrash(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);
        $crashed = $this->map->startRun($supplierId, null, 'import', [], $this->userId);
        try {
            $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), $crashed,
                static function (string $step): void {
                    if ($step === 'partners') {
                        throw new \RuntimeException('worker spadl');
                    }
                });
            self::fail('Běh měl spadnout.');
        } catch (\RuntimeException $e) {
            self::assertSame('worker spadl', $e->getMessage());
        }
        self::assertSame('off', $this->policy->listPolicy($supplierId)['automation_level']);

        self::assertSame(1, $this->map->closeInterruptedRuns($supplierId));
        self::assertSame('failed', $this->map->findRun($crashed, $supplierId)['status']);
        $next = $this->map->startRun($supplierId, null, 'import', [], $this->userId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), $next);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('assisted', $protocol->get('automation')['after']);
        self::assertNull($this->map->pendingAutomationSnapshot($supplierId), 'Po obnovení nesmí čekat žádný snímek.');
    }

    /**
     * Worker spadlý na vyčerpané paměti uzavře ze shutdown handleru běhy svého jobu,
     * aby průvodce ukázal chybu hned, a ne rozpracovaný běh až do dalšího převodu.
     */
    public function testFatalWorkerCrashFailsOnlyRunsOfItsJob(): void
    {
        $supplierId = $this->supplier();
        $crashed = $this->map->startRun($supplierId, 900001, 'dry_run', [], $this->userId);
        $other = $this->map->startRun($supplierId, 900002, 'dry_run', [], $this->userId);

        self::assertSame(1, $this->map->failJobRuns(900001, 'Převod spadl na nedostatek paměti serveru.'));

        $run = $this->map->findRun($crashed, $supplierId);
        self::assertSame('failed', $run['status']);
        self::assertSame('Převod spadl na nedostatek paměti serveru.', $run['protocol']['error']);
        self::assertSame('running', $this->map->findRun($other, $supplierId)['status']);
        self::assertSame(0, $this->map->failJobRuns(900001, 'znovu'), 'Uzavřený běh se nepřepisuje.');
    }

    public function testMapRefusesSecondTargetForTheSameMoneyRecord(): void
    {
        $supplierId = $this->supplier();
        $this->map->put($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV1', 10, null);

        $this->expectException(MoneyS3Exception::class);
        $this->map->put($supplierId, MoneyS3ImportRepository::KIND_INVOICE, '2025|FV1', 11, null);
    }

    /** Druhý worker téže firmy (třeba po úklidu „mrtvého" jobu zkoušky) se nespustí. */
    public function testSecondWorkerForTheSameCompanyIsRefused(): void
    {
        $supplierId = $this->supplier();
        $other = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->container->get(Config::class)));
        $otherRuns = new MoneyS3ImportRepository($other);
        self::assertTrue($otherRuns->acquireLock($supplierId));
        try {
            self::assertFalse($this->map->isLockFree($supplierId));
            $jobs = $this->container(ImportJobRepository::class);
            $jobId = $jobs->create($supplierId, MoneyS3ImportJobService::SOURCE, ['token' => str_repeat('a', 16), 'mode' => 'dry_run'], $this->userId);

            $this->container(MoneyS3ImportJobService::class)->run($jobId);

            $job = $jobs->find($jobId, $supplierId);
            self::assertSame('failed', $job['status']);
            self::assertStringContainsString('už běží', (string) $job['last_error']);
            self::assertSame([], $this->map->listRuns($supplierId));
        } finally {
            $otherRuns->releaseLock($supplierId);
            $other->close();
        }
        self::assertTrue($this->map->isLockFree($supplierId));
    }

    public function testFirstActivationGetsAccountingUnitDefaultsAfterImport(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertSame('off', $protocol->get('automation')['during']);
        self::assertSame('full', $protocol->get('automation')['after']);
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertTrue($protocol->get('reconciliation')[0]['ok']);
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->rowCount('accounting_periods', $supplierId));
        self::assertSame(0, $this->map->countAll($supplierId));
        $s = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        self::assertSame('tax_evidence', $s->fetchColumn());
    }

    /**
     * Zkouška nanečisto drží jednu transakci po celou dobu běhu. Kdyby v ní vypínala
     * automatiku zápisem do řádku firmy, držela by na něm zámek a běžná práce firmy
     * (každý nový doklad kontroluje cizí klíč na firmu) by čekala na konec zkoušky.
     */
    public function testDryRunDoesNotWriteCompanyAutomationWhileRunning(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->policy->applyPreset($supplierId, 'assisted', $this->userId);
        $this->db->pdo()->prepare('UPDATE supplier SET auto_post_invoices = 1 WHERE id = ?')->execute([$supplierId]);
        $seen = [];
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN), null,
            function (string $step) use ($supplierId, &$seen): void {
                if ($step !== 'purchase_invoices') {
                    return;
                }
                $flag = $this->db->pdo()->prepare('SELECT auto_post_invoices FROM supplier WHERE id = ?');
                $flag->execute([$supplierId]);
                $seen = ['level' => $this->policy->listPolicy($supplierId)['automation_level'], 'auto_post_invoices' => (int) $flag->fetchColumn()];
            });

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['level' => 'assisted', 'auto_post_invoices' => 1], $seen);
        self::assertSame('off', $protocol->get('automation')['during'], 'Protokol ukazuje, co udělá ostrý převod.');
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status'], $this->explain($protocol));
    }

    public function testAgendaOfAnotherCompanyIsRejected(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::VENDOR_ICO);
        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed());
        self::assertSame(['ico_mismatch'], array_column(array_filter($protocol->get('preflight'), static fn ($m) => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->map->countAll($supplierId));
    }

    /**
     * Ostrý převod zapisuje deník, přepíná režim účetnictví a automatiku a uzavírá roky —
     * samotné právo na import dat na to nestačí. Zkouška nanečisto nic nezanechá, ta
     * zůstává na `utilities.import`.
     */
    public function testLiveImportRequiresAccountingAndCompanySettingsRights(): void
    {
        $supplierId = $this->supplier();
        $action = $this->container(MoneyS3MigrationAction::class);
        $importOnly = ['utilities.import' => 2];
        $withoutClose = $importOnly + ['accounting.journal.write' => 2, 'settings.company.write' => 2];
        $full = $withoutClose + ['accounting.periods.close' => 2];
        $status = function (array $permissions, array $body) use ($action, $supplierId): int {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/imports/money-s3/uploads/' . str_repeat('a', 16) . '/start')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
                ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, $permissions))
                ->withParsedBody($body);
            return $action->start($request, (new ResponseFactory())->createResponse(), ['token' => str_repeat('a', 16)])->getStatusCode();
        };

        self::assertSame(403, $status($importOnly, ['mode' => 'import', 'close_history' => false]));
        self::assertSame(403, $status($withoutClose, ['mode' => 'import', 'close_history' => true]));
        // Práva sedí → požadavek projde autorizací a narazí až na neexistující zálohu.
        self::assertSame(404, $status($withoutClose, ['mode' => 'import', 'close_history' => false]));
        self::assertSame(404, $status($full, ['mode' => 'import', 'close_history' => true]));
        self::assertSame(404, $status($importOnly, ['mode' => 'dry_run']));
    }

    /**
     * Bez IČO na jedné ze stran nejde ověřit, že záloha patří téhle firmě. Zkouška
     * nanečisto jen upozorní, ostrý převod potřebuje výslovné potvrzení.
     */
    public function testLiveImportWithoutVerifiableIcoNeedsExplicitConfirmation(): void
    {
        $supplierId = $this->supplier('');

        $refused = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertTrue($refused->failed());
        self::assertSame(['ico_unverified'], array_column(array_filter($refused->get('preflight'), static fn ($m) => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));

        $dry = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertFalse($dry->hasErrors(), $this->explain($dry));

        $confirmed = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], true));
        self::assertFalse($confirmed->hasErrors(), $this->explain($confirmed));
    }

    public function testImportIsScopedToTheTargetCompany(): void
    {
        $a = $this->supplier();
        $this->import($a);
        $countsA = $this->snapshotCounts($a);

        $b = $this->supplier();
        $protocol = $this->import($b);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($countsA, $this->snapshotCounts($a), 'Převod do firmy B nesmí sáhnout na firmu A.');
        self::assertSame($countsA, $this->snapshotCounts($b), 'Firma B dostane vlastní kopii, ne odkaz na data firmy A.');

        $runA = $this->map->startRun($a, null, 'import', [], $this->userId);
        self::assertNull($this->map->findRun($runA, $b), 'Protokol cizí firmy není vidět.');
    }

    public function testExistingBookkeepingIsNotMixedWithMoneyJournal(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->container(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $periodId = $this->container(AccountingPeriodRepository::class)->create($supplierId, 2025, '2025-01-01', '2025-12-31');
        $stmt = $this->db->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('518', '321') ORDER BY account_code");
        $stmt->execute([$supplierId]);
        [$liability, $expense] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-03-01', 'source_type' => 'manual', 'posted_at' => '2025-03-01 10:00:00'],
            [['account_id' => $expense, 'side' => 'debit', 'amount' => '10.00'], ['account_id' => $liability, 'side' => 'credit', 'amount' => '10.00']],
        );

        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed());
        self::assertContains('journal_not_empty', array_column($protocol->get('preflight'), 'code'));
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId));
    }

    /**
     * Firma převedená z Money se dál účtuje v MyÚčtu, řádný opakovaný převod rozjetý deník
     * odmítne. Opravný převod vazeb (relinkOnly) projde zálohu, ale nic nového nezaloží -
     * ani doklady, které v Money mezitím přibyly - a převzatým dokladům jen znovu dopočítá
     * úhrady. Vlastní zápis uživatele zůstane, jak byl.
     */
    public function testRelinkOnlyRestoresPaymentsWithoutCreatingAnything(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();

        // Uživatel po převodu zaúčtuje vlastní zápis; dřívější verze převodu platby nezaložila.
        $period = $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2025);
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('518.000', '211.000') ORDER BY account_code");
        $stmt->execute([$supplierId]);
        [$cash, $expense] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $own = $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => (int) $period['id'], 'entry_date' => '2025-12-20', 'source_type' => 'manual',
             'description' => 'Zápis zadaný v MyÚčtu', 'posted_at' => '2025-12-20 10:00:00'],
            [['account_id' => $expense, 'side' => 'debit', 'amount' => '75.00'], ['account_id' => $cash, 'side' => 'credit', 'amount' => '75.00']],
        );
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$supplierId]);

        $regular = $this->import($supplierId);
        self::assertTrue($regular->failed());
        self::assertContains('journal_not_empty', array_column($regular->get('preflight'), 'code'));
        self::assertSame(0, $this->rowCount('invoice_payments', $supplierId));

        // V Money mezitím přibyly další doklady a pohyby (FV25002, FP25007, BP25004…).
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPartialPayments());
        $before = $this->snapshotCounts($supplierId);
        $relink = $this->importer->run($supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_HALF, false, true));

        self::assertFalse($relink->hasErrors(), $this->explain($relink));
        self::assertContains('relink_keeps_own_journal', array_column($relink->get('preflight'), 'code'));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId,
            "amount = 24200.00 AND source = 'bank' AND invoice_id = (SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'FV24001')"));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId));
        self::assertSame($before, $this->snapshotCounts($supplierId), 'Opravný převod nic nového nezaloží.');
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "varsymbol IN ('FV25002', 'FV25003')"));
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "id = {$own} AND description = 'Zápis zadaný v MyÚčtu'"));
        $skipped = 0;
        foreach ($relink->toArray()['steps'] as $step) {
            $skipped += (int) ($step['counts']['relink_skipped'] ?? 0);
        }
        self::assertGreaterThan(0, $skipped, $this->explain($relink));
    }

    /**
     * Opravný převod doplní evidované úhrady k pohybům, které převod spároval dřív, a smí
     * doplnit párování pohybu, jehož všechna párování vytvořil převod. Pohyb, který spároval
     * uživatel, nechá být.
     */
    public function testRelinkRestoresPaymentsOfMatchedMovementsAndKeepsUserMatches(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPartialPayments());
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $txOf = static function (string $ref) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare('SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.bank_ref = ?');
            $stmt->execute([$supplierId, $ref]);
            return (int) $stmt->fetchColumn();
        };
        $fp25007 = (int) $pdo->query("SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = 'DF-2025-070'")->fetchColumn();
        $bv25004 = $txOf('BV25004');

        // Uživatel splátku BV25004 přepáruje sám (jen část), dřívější převod nezaložil evidované
        // úhrady vydaných faktur a firma se dál účtuje v MyÚčtu.
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$supplierId, $bv25004]);
        $pdo->prepare('INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id) VALUES (?, ?, ?, 500.00, "manual", ?)')
            ->execute([$supplierId, $bv25004, $fp25007, $this->userId]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$supplierId]);
        $period = $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2025);
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('518.000', '211.000') ORDER BY account_code");
        $stmt->execute([$supplierId]);
        [$cash, $expense] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => (int) $period['id'], 'entry_date' => '2025-12-20', 'source_type' => 'manual', 'posted_at' => '2025-12-20 10:00:00'],
            [['account_id' => $expense, 'side' => 'debit', 'amount' => '75.00'], ['account_id' => $cash, 'side' => 'credit', 'amount' => '75.00']],
        );

        $relink = $this->importer->run($supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_HALF, false, true));

        self::assertFalse($relink->hasErrors(), $this->explain($relink));
        // FV25002: splátku BP25004 spároval převod podle symbolu, doplatek BP25005 podle `UDoklad`.
        self::assertSame(2, $this->rowCount('invoice_payments', $supplierId,
            "source = 'bank' AND amount IN (5000.00, 7100.00) AND invoice_id = (SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'FV25002')"),
            $this->explain($relink));
        // Pohyb spárovaný uživatelem zůstává, jak ho nechal.
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId, "bank_transaction_id = {$bv25004} AND amount = 500.00"));
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId, "bank_transaction_id = {$bv25004}"));
    }
}
