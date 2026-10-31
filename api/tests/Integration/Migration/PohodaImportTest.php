<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\Activation\PendingBackfillCounter;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\NonprofitActivityByCentre;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Tests\Fixtures\Pohoda\OssVariant;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod syntetického XML exportu z POHODY do firmy v MyÚčtu - celý řetěz nad skutečnou
 * DB: osnova, deník s počátečními stavy, adresář, faktury, banka, vazby, úhrady
 * a rekonciliace. Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaImportTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private OssLedgerService $ossLedger;
    private NonprofitActivityByCentre $byCentre;
    private SaldoService $saldo;
    private BankPostingService $bankPosting;
    private string $tmp = '';
    private int $userId = 0;
    private int $anyCurrencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
            $this->ossLedger = $container->get(OssLedgerService::class);
            $this->byCentre = $container->get(NonprofitActivityByCentre::class);
            $this->saldo = $container->get(SaldoService::class);
            $this->bankPosting = $container->get(BankPostingService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if (!$this->db->hasColumn('pohoda_import_map', 'pohoda_key')) {
            $this->markTestSkipped('Chybí migrace 1844 (pohoda_import_map).');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->anyCurrencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_int_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
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
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testImportReconcilesToTheCentAndIsIdempotent(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(1, $reconciliation);
        self::assertTrue($reconciliation[0]['ok'], json_encode($reconciliation[0], JSON_UNESCAPED_UNICODE));
        self::assertSame([], $reconciliation[0]['journal_diffs']);

        // Deník: jeden otevírací zápis + 5 dokladů; přijatá faktura je MD 518 / D 321.
        self::assertSame(6, $this->rows('journal_entries', $supplierId));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "source_type = 'opening'"));
        $side = $this->db->pdo()->prepare(
            "SELECT l.side FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND a.account_code = '518.000'"
        );
        $side->execute([$supplierId]);
        self::assertSame('debit', $side->fetchColumn());

        // Doklady navázané na deník a uhrazené bankou.
        self::assertSame(1, $this->rows('invoices', $supplierId, "status = 'paid' AND total_with_vat = 1210.00"));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "status = 'paid' AND total_with_vat = 605.00 AND vendor_invoice_number = 'D-2026-7'"));
        self::assertSame(2, $this->rows('payment_matches', $supplierId));
        // Úhrada vydané faktury je i evidovanou platbou - z ní saldokonto počítá uhrazenou část.
        self::assertSame(1, $this->rows('invoice_payments', $supplierId, "amount = 1210.00 AND currency = 'CZK' AND source = 'bank' AND bank_transaction_id IS NOT NULL"));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "source_type = 'invoice' AND source_id IS NOT NULL"));
        self::assertSame(2, $this->rows('clients', $supplierId));

        // Výpis navazuje na počáteční stav 221 z deníku; doklad „Počáteční stav bankovního
        // účtu" není pohyb a podruhé se nezapočte.
        self::assertSame(1, $this->rows('bank_statements', $supplierId));
        self::assertSame(1, $this->rows('bank_statements', $supplierId, 'prev_balance = 100000.00 AND curr_balance = 100555.00 AND transaction_count = 3'));

        // Pohyb, který Pohoda zaúčtovala bez dokladu (poplatek), je vyřízený - výpis nesvítí
        // jako nedopárovaný. Úhrady faktur zůstávají spárované.
        $status = $this->db->pdo()->prepare(
            'SELECT t.match_status, t.match_reason, COUNT(*) AS n FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? GROUP BY t.match_status, t.match_reason ORDER BY t.match_status'
        );
        $status->execute([$supplierId]);
        self::assertSame([['manual', null, 2], ['ignored', 'pohoda_booked', 1]],
            array_map(static fn (array $r): array => [$r['match_status'], $r['match_reason'], (int) $r['n']], $status->fetchAll(\PDO::FETCH_ASSOC)));

        // Neuhrazená faktura minulého období se převede kvůli saldu, bez zápisu v deníku roku.
        self::assertSame(1, $this->rows('invoices', $supplierId, "status = 'sent' AND issue_date = '2025-12-20'"));
        $link = self::stepCounts($protocol, 'link');
        self::assertSame(1, $link['previous_period'] ?? 0);
        self::assertArrayNotHasKey('orphans', $link);

        // Doklad minulého období je v deníku zastoupený počátečními stavy - Doúčtování
        // dokladů ho nesmí nabízet, jinak by se zaúčtoval podruhé.
        $pending = Bootstrap::buildContainer()->get(PendingBackfillCounter::class)->count($supplierId);
        self::assertSame(0, $pending['invoices'], json_encode($pending));
        self::assertSame(0, $pending['purchase_invoices'], json_encode($pending));
        self::assertSame(0, $pending['cash_documents'], json_encode($pending));

        // Opakovaný převod téhož exportu nic nezdvojí a nehlásí nic navíc - doklad minulého
        // období nesmí napodruhé vypadat jako doklad bez zápisu v deníku.
        $before = $this->snapshot($supplierId);
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshot($supplierId));
        $link = self::stepCounts($again, 'link');
        self::assertSame(1, $link['previous_period'] ?? 0, $this->explain($again));
        self::assertArrayNotHasKey('orphans', $link, $this->explain($again));
        self::assertSame('completed', $again->status(), $this->explain($again));
        self::assertSame(1, $this->rows('invoice_payments', $supplierId));

        // Firma převedená dřívější verzí platbu nemá; opakovaný převod ji doplní k už
        // spárované úhradě a nic dalšího nemění.
        $this->db->pdo()->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$supplierId]);
        $repair = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(1, $this->rows('invoice_payments', $supplierId, "amount = 1210.00 AND source = 'bank'"));
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /**
     * Opravný převod vazeb: firma se po převodu účtuje v MyÚčtu, takže řádný opakovaný převod
     * odmítne rozjetý deník. Opravný převod projde novější export, nový doklad (26PF0002) ani
     * zápis nezaloží, vlastní zápis uživatele nechá být a chybějící úhrady převzatých faktur
     * dopočítá znovu.
     */
    public function testRelinkRestoresPaymentsWithoutCreatingNewRecords(): void
    {
        $supplierId = $this->supplier();
        $first = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/a')), false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $payments = $this->rows('invoice_payments', $supplierId);
        self::assertGreaterThan(0, $payments);
        $purchases = $this->rows('purchase_invoices', $supplierId);

        $periodId = (int) $this->db->pdo()->query("SELECT id FROM accounting_periods WHERE supplier_id = {$supplierId} ORDER BY starts_on DESC LIMIT 1")->fetchColumn();
        $account = static fn (\PDO $pdo, string $code): int => (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$supplierId} AND account_code = '{$code}'")->fetchColumn();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, posted_at) VALUES (?, ?, ?, 'Vlastní zápis v MyÚčtu', 'manual', NOW())")
            ->execute([$supplierId, $periodId, SyntheticPohodaExport::YEAR . '-11-30']);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) VALUES (?, ?, ?, 'debit', 100, 1), (?, ?, ?, 'credit', 100, 2)")
            ->execute([$entryId, $supplierId, $account($pdo, '518000') ?: $account($pdo, '518'), $entryId, $supplierId, $account($pdo, '321001') ?: $account($pdo, '321')]);
        $entries = $this->rows('journal_entries', $supplierId);
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$supplierId]);

        $newer = PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/b', true));
        $refused = $this->importer->run($supplierId, $this->userId, $newer, false);
        self::assertTrue($refused->hasErrors(), 'Řádný převod do rozjetého deníku nesmí projít.');

        $relink = $this->importer->run($supplierId, $this->userId, $newer, false, relinkOnly: true);
        self::assertFalse($relink->hasErrors(), $this->explain($relink));
        self::assertSame($entries, $this->rows('journal_entries', $supplierId), 'Opravný převod nezakládá zápisy.');
        self::assertSame($purchases, $this->rows('purchase_invoices', $supplierId), 'Novější doklad z POHODY se nepřidá.');
        self::assertGreaterThan(0, self::stepCounts($relink, 'purchase_invoices')['relink_skipped'] ?? 0, $this->explain($relink));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "id = {$entryId}"), 'Vlastní zápis uživatele zůstává.');
        self::assertSame($payments, $this->rows('invoice_payments', $supplierId), 'Úhrady převzatých faktur se dopočítají znovu.');
    }

    public function testKeepOwnJournalAddsOnlyRecordsBeforeFirstOwnEntry(): void
    {
        $supplierId = $this->supplier();
        $first = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/a')), false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $pdo = $this->db->pdo();
        $periodId = (int) $pdo->query("SELECT id FROM accounting_periods WHERE supplier_id = {$supplierId} ORDER BY starts_on DESC LIMIT 1")->fetchColumn();
        $account = static fn (string $code): int => (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$supplierId} AND account_code = '{$code}'")->fetchColumn();
        $ownDate = SyntheticPohodaExport::YEAR . '-01-01';
        $pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, posted_at) VALUES (?, ?, ?, 'Vlastní zápis v MyÚčtu', 'manual', NOW())")
            ->execute([$supplierId, $periodId, $ownDate]);
        $ownId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) VALUES (?, ?, ?, 'debit', 100, 1), (?, ?, ?, 'credit', 100, 2)")
            ->execute([$ownId, $supplierId, $account('518000') ?: $account('518'), $ownId, $supplierId, $account('321001') ?: $account('321')]);
        $entries = $this->rows('journal_entries', $supplierId);
        $purchases = $this->rows('purchase_invoices', $supplierId);

        $newer = PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/b', true));
        self::assertTrue($this->importer->run($supplierId, $this->userId, $newer, false)->hasErrors(), 'Bez dorovnání zůstává zákaz.');

        $merge = $this->importer->run($supplierId, $this->userId, $newer, false, keepOwnJournal: true);
        self::assertContains('own_journal_cutoff', $this->messageCodes($merge), $this->explain($merge));
        self::assertNotContains('unexpected', $this->messageCodes($merge), $this->explain($merge));
        self::assertNotContains('journal_not_empty', $this->messageCodes($merge), $this->explain($merge));
        self::assertSame($entries, $this->rows('journal_entries', $supplierId), 'Vše z novější zálohy je po prvním vlastním zápisu, nic se nepřidá.');
        self::assertSame($purchases, $this->rows('purchase_invoices', $supplierId));
        self::assertSame(1, $this->rows('journal_entries', $supplierId, "id = {$ownId}"));
    }

    public function testUnknownDocumentTypesWarnAndKeepImportingKnownDocuments(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        foreach (['issued' => ['invoice', 'inv:invoiceType'], 'bank' => ['bank', 'bnk:bankType']] as $key => [$tag, $typeTag]) {
            $path = $dir . '/' . PohodaExport::FILES[$key];
            $xml = (string) file_get_contents($path);
            self::assertSame(1, preg_match('/<lst:' . $tag . '\b.*?<\/lst:' . $tag . '>/s', $xml, $m, PREG_OFFSET_CAPTURE));
            $unknown = preg_replace('/<' . $typeTag . '>.*?<\/' . $typeTag . '>/', '<' . $typeTag . '>unknownTestType</' . $typeTag . '>', $m[0][0]);
            $xml = substr_replace($xml, $unknown, $m[0][1], 0);
            file_put_contents($path, $xml);
        }

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('completed_with_warnings', $protocol->status());
        self::assertSame(2, self::stepCounts($protocol, 'preflight')['skipped_documents']);
        $warnings = array_values(array_filter((array) $protocol->get('preflight'), static fn (array $m): bool => $m['level'] === 'warning'));
        self::assertCount(2, $warnings);
        self::assertSame(['xml_document_type', 'xml_document_type'], array_column($warnings, 'code'));
        self::assertSame(['unknownTestType', 'unknownTestType'], array_column(array_column($warnings, 'context'), 'source_type'));
        self::assertSame(2, $this->rows('invoices', $supplierId));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId));
        self::assertSame(6, $this->rows('journal_entries', $supplierId));
        self::assertSame(2, $this->rows('payment_matches', $supplierId));
        self::assertTrue($protocol->get('reconciliation')[0]['ok']);
    }

    public function testOtherItemsReuseImportedJournalAndDoNotDuplicateOnRerun(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir);
        $export = PohodaExport::open($dir);

        $first = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame(2, $this->rows('other_items', $supplierId));
        self::assertSame(0, $this->rows('invoices', $supplierId, "varsymbol = '26OP0001'"));
        self::assertSame(0, $this->rows('purchase_invoices', $supplierId, "varsymbol = '26OZ0001'"));
        self::assertSame(2, $this->rows('journal_entries', $supplierId,
            "source_type = 'other_item' AND source_id IS NOT NULL"));
        self::assertSame(8, $this->rows('journal_entries', $supplierId));

        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(2, $this->rows('other_items', $supplierId));
        self::assertSame(8, $this->rows('journal_entries', $supplierId));
        self::assertSame(2, $this->rows('pohoda_import_map', $supplierId, "kind = 'other_item'"));
    }

    public function testSettledOtherItemKeepsBankPaymentsAndPayrollLiabilityStaysInModule(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        $export = PohodaExport::open($dir);

        $first = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame(4, $this->rows('other_items', $supplierId));
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no IN ('26OZM0001', '26OZD0001', '26OZD0002')"));
        $counts = self::stepCounts($first, 'other_items');
        // Odvod na 336 a doplatek daně na 341 vlastní mzdový a daňový modul.
        self::assertSame(2, $counts['payroll_or_tax'] ?? 0);
        self::assertSame(1, $counts['created_unposted'] ?? 0);
        self::assertSame(1, $counts['tax_advances'] ?? 0);

        $pdo = $this->db->pdo();
        // Evidenční závazek bez zaúčtování: potvrzený bez vlastního zápisu, deník se nemění,
        // úhrada z banky je přiřazená.
        $unposted = $pdo->query("SELECT id, status, journal_entry_id, account_code FROM other_items WHERE supplier_id = {$supplierId} AND document_no = '26OZB0001'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['confirmed', null, '364.000'], [$unposted['status'], $unposted['journal_entry_id'], $unposted['account_code']]);
        self::assertSame('400.00', (string) $pdo->query("SELECT SUM(amount) FROM other_item_allocations WHERE other_item_id = {$unposted['id']}")->fetchColumn());
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "source_type = 'other_item' AND source_id = {$unposted['id']}"));

        // Záloha na daň: předpis daňového modulu uhrazený spárovaným pohybem.
        $advance = $pdo->query("SELECT taxpayer_type, period_year, seq_no, amount, due_date, status, paid_source, matched_transaction_id
            FROM tax_advance_schedules WHERE supplier_id = {$supplierId}")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $advance);
        self::assertSame(['2026', '1', '1000.00', '2026-03-16', 'paid', 'bank'],
            [(string) $advance[0]['period_year'], (string) $advance[0]['seq_no'], (string) $advance[0]['amount'],
             $advance[0]['due_date'], $advance[0]['status'], $advance[0]['paid_source']]);
        self::assertNotNull($advance[0]['matched_transaction_id']);

        $settled = (int) $pdo->query("SELECT id FROM other_items WHERE supplier_id = {$supplierId} AND document_no = '26OZ0002'")->fetchColumn();
        self::assertSame(2, $this->rows('other_item_allocations', $supplierId, "other_item_id = {$settled} AND reversed_on IS NULL"));
        self::assertSame('300.00', (string) $pdo->query("SELECT SUM(amount) FROM other_item_allocations WHERE other_item_id = {$settled}")->fetchColumn());
        // Alokovaný pohyb je vyřízený alokací, převod ho neoznačí jako zaúčtovaný bez dokladu.
        self::assertSame(0, $this->rows('other_item_allocations', $supplierId,
            "EXISTS (SELECT 1 FROM bank_transactions t WHERE t.id = other_item_allocations.bank_transaction_id AND t.match_status <> 'unmatched')"));
        self::assertSame(1, $this->rows('other_items', $supplierId, "document_no = '26OZ0002' AND partner_id IS NOT NULL"));

        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(4, $this->rows('other_items', $supplierId));
        self::assertSame(3, $this->rows('other_item_allocations', $supplierId));
        self::assertSame(1, $this->rows('tax_advance_schedules', $supplierId));
    }

    /**
     * Zůstatek ostatní položky sedí na POHODU: úhrada bez pohybu v roce agendy jde do
     * `settled_outside_amount`, výdejový pokladní doklad se přiřadí jako alokace.
     * Opakovaný převod částky nenásobí.
     */
    public function testOtherItemBalanceFollowsPohodaIncludingCashAndEarlierPayments(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withOtherItemSettlements($dir);
        $export = PohodaExport::open($dir);
        $pdo = $this->db->pdo();
        $balance = static fn (string $number): array => array_map('strval', $pdo->query(
            "SELECT oi.settled_outside_amount, COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a WHERE a.other_item_id = oi.id AND a.reversed_on IS NULL), 0.00)
               FROM other_items oi WHERE oi.supplier_id = {$supplierId} AND oi.document_no = '{$number}'")->fetch(\PDO::FETCH_NUM));

        foreach ([1, 2] as $run) {
            $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            self::assertStringNotContainsString('other_item_payment', $this->explain($protocol));
            self::assertSame(['700.00', '0.00'], $balance(SyntheticPohodaExport::OTHER_ITEM_PAID_EARLIER), "běh {$run}");
            self::assertSame(['0.00', '250.00'], $balance(SyntheticPohodaExport::OTHER_ITEM_PAID_BY_CASH), "běh {$run}");
            // Úhrady z banky se do mimobankovní části nepočítají, neuhrazený zbytek zůstává otevřený.
            self::assertSame(['0.00', '300.00'], $balance('26OZ0002'), "běh {$run}");
            self::assertSame(['0.00', '400.00'], $balance('26OZB0001'), "běh {$run}");
            self::assertSame(['0.00', '0.00'], $balance('26OZ0001'), "běh {$run}");
        }
    }

    /**
     * Přeplatky zaúčtované z banky a pokladny přímo na 315.999 a 325.999: zůstatek dostane
     * ostatní položku nad svým řádkem, deník se nemění, saldokonto sedí na hlavní knihu
     * a opakovaný převod nic nezdvojí. Platba a vrácení téhož partnera položku nezakládají.
     */
    public function testBalanceWithoutDocumentBecomesOtherItemOverItsJournalLine(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOverpayments($dir);
        $export = PohodaExport::open($dir);

        foreach ([1, 2] as $run) {
            $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            self::assertSame(5, $this->rows('other_items', $supplierId), "běh {$run}");
            self::assertSame(5, $this->rows('pohoda_import_map', $supplierId, "kind = 'balance_item'"), "běh {$run}");
            self::assertSame(5, $this->rows('journal_settlement_allocations', $supplierId, "kind = 'origin' AND doc_type = 'other_item'"), "běh {$run}");
            self::assertSame(13, $this->rows('journal_entries', $supplierId), "běh {$run}");
            self::assertSame(['difference' => 0.0, 'gl' => -1600.0, 'open' => -1600.0], $this->saldoOf($supplierId, '315'), "běh {$run}");
            self::assertSame(['difference' => 0.0, 'gl' => -250.0, 'open' => -250.0], $this->saldoOf($supplierId, '325'), "běh {$run}");
        }

        $items = $this->db->pdo()->query(
            "SELECT oi.side, oi.status, oi.account_code, oi.amount, oi.variable_symbol, oi.journal_entry_id, oi.partner_id IS NOT NULL AS has_partner, oi.partner_name, e.document_no
               FROM other_items oi
               JOIN journal_settlement_allocations ja ON ja.doc_id = oi.id AND ja.doc_type = 'other_item' AND ja.supplier_id = oi.supplier_id
               JOIN journal_entries e ON e.id = ja.entry_id
              WHERE oi.supplier_id = {$supplierId} ORDER BY e.document_no"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $summary = array_map(static fn (array $i): array => [$i['document_no'], $i['side'], $i['account_code'], (string) $i['amount'], $i['variable_symbol'], $i['partner_name']], $items);
        self::assertSame([
            ['26PP0001', 'payable', '325.999', '350.00', null, 'Hotovost Test s.r.o.'],
            ['BAN0010001', 'payable', '315.999', '100.00', '260001', 'Odběratel Test s.r.o.'],
            [SyntheticPohodaExport::OVERPAYMENT_BANK, 'payable', '315.999', '1500.00', '2026101', 'Odběratel Přeplatek s.r.o.'],
            ['BAN0020004', 'receivable', '325.999', '900.00', '2026102', 'Dodavatel Přeplatek s.r.o.'],
            ['BAN0020005', 'payable', '325.999', '300.00', null, 'Vratka Test s.r.o.'],
        ], $summary);
        foreach ($items as $item) {
            self::assertSame(['confirmed', null, 1], [$item['status'], $item['journal_entry_id'], (int) $item['has_partner']]);
        }
    }

    /** Položku, kterou účetní stornovala, převod znovu nezaloží. Nové vrácení dostane vlastní položku a saldokonto dál sedí. */
    public function testBalanceItemRerunRespectsStornoAndLaterRefund(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOverpayments($dir);
        $first = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($first->hasErrors(), $this->explain($first));

        SyntheticPohodaExport::withOverpaymentRefund($dir);
        $refund = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($refund->hasErrors(), $this->explain($refund));
        self::assertSame(6, $this->rows('other_items', $supplierId));
        self::assertSame(['difference' => 0.0, 'gl' => -100.0, 'open' => -100.0], $this->saldoOf($supplierId, '315'));

        $this->db->pdo()->exec("UPDATE journal_settlement_allocations SET released_on = '2026-06-30'
            WHERE supplier_id = {$supplierId} AND amount = 900.00");
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(6, $this->rows('other_items', $supplierId));
    }

    public function testBalanceItemsLeaveNothingBehindInDryRun(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOverpayments($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(5, self::stepCounts($protocol, 'payments')['balance_items'] ?? 0);
        self::assertSame(0, $this->rows('other_items', $supplierId));
        self::assertSame(0, $this->rows('journal_settlement_allocations', $supplierId));
    }

    /** Pohyb bez partnera i symbolu se nezakládá a je v protokolu; rozdíl zůstává vidět. */
    public function testBalanceWithoutPartnerAndSymbolIsOnlyWarned(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withUnclearOverpayment($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->rows('other_items', $supplierId));
        self::assertContains('balance_item_unclear', $this->messageCodes($protocol));
        self::assertSame(['difference' => -250.0, 'gl' => -250.0, 'open' => 0.0], $this->saldoOf($supplierId, '315'));
    }

    /**
     * Vrácení přeplatku z počátečního stavu: účet má nulový zůstatek, položka by rozdíl
     * saldokonta z nuly otevřela. Nezaloží se a řádek skončí v protokolu.
     */
    public function testBalanceItemNeverOpensDifferenceAgainstOpeningBalance(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOpeningOverpaymentRefund($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->rows('other_items', $supplierId));
        self::assertContains('balance_item_no_effect', $this->messageCodes($protocol));
        self::assertSame(0.0, $this->saldoOf($supplierId, '315')['difference']);
    }

    /** @return array{difference:float,gl:float,open:float} */
    private function saldoOf(int $supplierId, string $account): array
    {
        $period = (int) $this->db->pdo()->query("SELECT id FROM accounting_periods WHERE supplier_id = {$supplierId} AND fiscal_year = " . SyntheticPohodaExport::YEAR)->fetchColumn();
        $block = $this->saldo->build($supplierId, $period, SyntheticPohodaExport::YEAR . '-12-31', $account)['accounts'][0];
        return ['difference' => (float) $block['difference'], 'gl' => (float) $block['gl_balance'], 'open' => (float) $block['open_items_total']];
    }

    /**
     * Stejné číslo bankovního dokladu po roce (nebo déle než půl roku od zápisu): zápis deníku
     * patří jen pohybu blízkému datem, druhý pohyb zůstává nezaúčtovaný a rekonciliace sedí.
     */
    public function testRepeatedBankNumberDoesNotTakeOverOlderJournalEntry(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withRepeatedBankNumber($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $linked = $this->db->pdo()->query("SELECT t.amount FROM bank_transactions t
            JOIN bank_statements s ON s.id = t.statement_id
            JOIN journal_entry_document_links k ON k.doc_id = t.id AND k.doc_type = 'bank' AND k.supplier_id = s.supplier_id
           WHERE s.supplier_id = {$supplierId} AND t.bank_ref = 'BAN0010020'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['-100.00'], array_map('strval', $linked));
    }

    /**
     * Agenda neziskové organizace jde jen do firmy s rámcem 504: do firmy v rámci 500 ji převod
     * odmítne (účty 9xx a výkazy by tam nesedly), ve firmě 504 se deník převede a rozvaha
     * se ověří. Běžná agenda do firmy 504 dál nesmí.
     */
    public function testNonprofitAgendaRequiresFramework504(): void
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withNonprofitChart($dir);
        $export = PohodaExport::open($dir);
        self::assertTrue($export->hasNonprofitChart());

        $framework500 = $this->supplier();
        $refused = $this->importer->run($framework500, $this->userId, $export, false);
        self::assertContains('nonprofit_agenda_needs_504', $this->messageCodes($refused), $this->explain($refused));
        self::assertSame(0, $this->rows('journal_entries', $framework500));

        $framework504 = $this->supplier();
        $this->adopt504($framework504);
        $imported = $this->importer->run($framework504, $this->userId, $export, false);
        self::assertFalse($imported->hasErrors(), $this->explain($imported));
        self::assertGreaterThan(0, $this->rows('journal_entries', $framework504));
        self::assertSame('cz_504', $this->db->pdo()->query("SELECT accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id = {$framework504}")->fetchColumn());
        self::assertSame(1, $this->rows('chart_of_accounts', $framework504, "account_code = '901' AND account_type = 'equity'"));

        $entries = $this->rows('journal_entries', $framework504);
        $newerDir = $this->tmp . DIRECTORY_SEPARATOR . 'newer';
        mkdir($newerDir);
        $newer = SyntheticPohodaExport::write($newerDir, true);
        SyntheticPohodaExport::withNonprofitChart($newer);
        $again = $this->importer->run($framework504, $this->userId, PohodaExport::open($newer), false);
        self::assertNotContains('unexpected', $this->messageCodes($again), 'Deník firmy 504 s už založenou činností: ' . $this->explain($again));
        self::assertGreaterThan($entries, $this->rows('journal_entries', $framework504));

        $ordinaryDir = $this->tmp . DIRECTORY_SEPARATOR . 'ordinary';
        mkdir($ordinaryDir);
        $ordinary = PohodaExport::open(SyntheticPohodaExport::write($ordinaryDir));
        self::assertFalse($ordinary->hasNonprofitChart());
        $mismatch = $this->supplier();
        $this->adopt504($mismatch);
        $wrong = $this->importer->run($mismatch, $this->userId, $ordinary, false);
        self::assertContains('accounting_framework_import_unsupported', $this->messageCodes($wrong), $this->explain($wrong));
    }

    private function adopt504(int $supplierId): void
    {
        $this->db->pdo()->exec("UPDATE supplier SET legal_form = '706' WHERE id = {$supplierId}");
        $service = new \MyInvoice\Service\Accounting\AccountingFrameworkService($this->db, new \MyInvoice\Repository\AccountingFrameworkRepository($this->db));
        self::assertTrue($service->adoptCz504BaselineForNewSupplier($supplierId, $this->userId));
    }

    /**
     * Přijatá faktura uhrazená zčásti v minulém roce: zůstatek sedí na POHODU, opakovaný
     * převod částku nenásobí a faktura zůstává otevřená.
     */
    public function testPurchasePaidInEarlierYearKeepsPohodaBalance(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withPurchasePaidEarlier($dir);
        $export = PohodaExport::open($dir);
        $pdo = $this->db->pdo();
        $id = null;
        foreach ([1, 2] as $run) {
            $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $id ??= $this->documentId('purchase_invoices', $supplierId, SyntheticPohodaExport::PURCHASE_PAID_EARLIER);
            $row = $pdo->query('SELECT pi.status, pi.settled_outside_amount, ' . \MyInvoice\Support\Sql\PurchaseSettledExpr::remaining('pi') . " AS remaining
                FROM purchase_invoices pi WHERE pi.id = {$id}")->fetch(\PDO::FETCH_ASSOC);
            self::assertSame('600.00', (string) $row['settled_outside_amount'], "běh {$run}");
            self::assertEqualsWithDelta(400.0, (float) $row['remaining'], 0.001, "běh {$run}");
            self::assertNotSame('paid', $row['status'], "běh {$run}");
        }
    }

    /**
     * Doklad, jehož skupinu párovacího symbolu má POHODA vyrovnanou, je uhrazený i ve stavu,
     * jinak by na něj chodily upomínky. Deník se nemění a opakovaný převod úhradu nezdvojí.
     */
    public function testClosedPairingGroupSettlesDocumentIncludingItsStatus(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withClosedPairingGroups($dir);
        $export = PohodaExport::open($dir);
        $pdo = $this->db->pdo();
        foreach ([1, 2] as $run) {
            $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $issued = $this->documentId('invoices', $supplierId, SyntheticPohodaExport::PAIRED_ISSUED);
            $purchase = $this->documentId('purchase_invoices', $supplierId, SyntheticPohodaExport::PAIRED_PURCHASE);

            $invoice = $pdo->query("SELECT status, paid_total, paid_at FROM invoices WHERE id = {$issued}")->fetch(\PDO::FETCH_ASSOC);
            self::assertSame(['paid', '1210.00', '2026-12-31'], [$invoice['status'], (string) $invoice['paid_total'], (string) $invoice['paid_at']], "běh {$run}");
            self::assertSame(['1210.00'], $pdo->query("SELECT amount FROM invoice_payments WHERE invoice_id = {$issued} AND source = 'settlement'")->fetchAll(\PDO::FETCH_COLUMN), "běh {$run}");

            $received = $pdo->query("SELECT status, settled_outside_amount FROM purchase_invoices WHERE id = {$purchase}")->fetch(\PDO::FETCH_ASSOC);
            self::assertSame(['paid', '1210.00'], [$received['status'], (string) $received['settled_outside_amount']], "běh {$run}");

            self::assertSame(4, $this->rows('journal_entries', $supplierId, "document_no IN ('" . SyntheticPohodaExport::PAIRED_ISSUED . "', '" . SyntheticPohodaExport::PAIRED_PURCHASE . "', '26IN0090', '26IN0091')"), "běh {$run}");
            self::assertEqualsWithDelta(0.0, $this->saldoOf($supplierId, '311')['difference'], 0.001, "běh {$run}");
            self::assertEqualsWithDelta(0.0, $this->saldoOf($supplierId, '321')['difference'], 0.001, "běh {$run}");
        }
    }

    /**
     * Středisko z deníku je na řádcích jako dimenze Středisko a opakovaný převod ho nezdvojí.
     * Export bez středisek dimenze nezapíná.
     */
    public function testCostCentreFromJournalBecomesDimension(): void
    {
        $plainDir = $this->tmp . DIRECTORY_SEPARATOR . 'plain';
        mkdir($plainDir);
        $plain = $this->supplier();
        $this->importer->run($plain, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($plainDir)), false);
        self::assertSame(0, $this->rows('cost_centers', $plain));
        self::assertSame(0, $this->rows('journal_entry_line_dimensions', $plain));
        self::assertSame('0', (string) $this->db->pdo()->query("SELECT dimensions_enabled FROM supplier WHERE id = {$plain}")->fetchColumn());

        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withCostCentre($dir);
        $export = PohodaExport::open($dir);
        foreach ([1, 2] as $run) {
            $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $rows = $this->db->pdo()->query("SELECT l.cost_center, v.code, v.name, t.code AS type_code
                  FROM journal_entry_lines l
                  JOIN journal_entries e ON e.id = l.entry_id
                  JOIN journal_entry_line_dimensions d ON d.line_id = l.id
                  JOIN dimension_values v ON v.id = d.dimension_value_id
                  JOIN dimension_types t ON t.id = d.dimension_type_id
                 WHERE e.supplier_id = {$supplierId} AND e.document_no = '" . SyntheticPohodaExport::CENTRE_DOCUMENT . "'")->fetchAll(\PDO::FETCH_NUM);
            self::assertSame(array_fill(0, 2, ['VYUKA', 'VYUKA', 'Výuka hry na nástroj', 'stredisko']), $rows, "běh {$run}");
        }
        self::assertSame(1, $this->rows('cost_centers', $supplierId));
    }

    /**
     * Tabulka středisko => činnost přeřadí řádky nákladů a výnosů. Řádky mimo tabulku a účty
     * rozvahy zůstávají, zkouška nanečisto nic nezapíše a vrátí totéž co ostrý běh, opakovaný
     * běh ani opakovaný převod nic nezmění a audit neroste.
     */
    public function testActivityByCentreReassignsLinesAndIsIdempotent(): void
    {
        [$supplierId, $export] = $this->nonprofitWithCentres();
        $service = $this->activityByCentre();
        $map = ['VYUKA' => 'main', 'SVETLO' => 'economic'];
        $before = $this->activityByDocument($supplierId);
        self::assertEquals(['26IN0097' => 'main', '26IN0098' => 'main', self::centreDoc() => 'main'], array_intersect_key($before, array_flip(['26IN0097', '26IN0098', self::centreDoc()])));
        $auditBefore = $this->rows('activity_log', $supplierId);

        $dry = $service->run($supplierId, $map, true);
        self::assertTrue($dry['dry_run']);
        self::assertSame($before, $this->activityByDocument($supplierId), 'Zkouška nanečisto nesmí zapsat.');
        self::assertSame($auditBefore, $this->rows('activity_log', $supplierId), 'Zkouška nanečisto nesmí auditovat.');
        self::assertSame(1, $dry['changed']);
        self::assertSame(1, $dry['unchanged']);
        self::assertSame(0, $dry['skipped']);
        self::assertSame(1, $dry['outside_map']['JINE'] ?? null);
        self::assertArrayNotHasKey('SVETLO', $dry['outside_map']);
        self::assertGreaterThan(0, $dry['no_centre']);
        self::assertSame([], $dry['unknown_centres']);
        self::assertContains(['centre' => 'SVETLO', 'activity' => 'economic', 'outcome' => 'changed', 'lines' => 1, 'debit' => '0.00', 'credit' => '1200.00'], self::normalizedRows($dry));
        self::assertContains(['centre' => 'VYUKA', 'activity' => 'main', 'outcome' => 'unchanged', 'lines' => 1, 'debit' => '500.00', 'credit' => '0.00'], self::normalizedRows($dry));

        $live = $service->run($supplierId, $map, false, $this->userId);
        self::assertFalse($live['dry_run']);
        self::assertSame(array_diff_key($dry, ['dry_run' => 1]), array_diff_key($live, ['dry_run' => 1]), 'Zkouška nanečisto odpovídá ostrému běhu.');
        $after = $this->activityByDocument($supplierId);
        self::assertSame('economic', $after['26IN0098']);
        self::assertSame('main', $after['26IN0097'], 'Středisko mimo mapu zůstává.');
        self::assertSame('main', $after[self::centreDoc()]);
        foreach (array_diff_key($before, array_flip(['26IN0098'])) as $document => $activity) {
            self::assertSame($activity, $after[$document], "Doklad {$document} se měnit neměl.");
        }
        $balanceSheet = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
               JOIN journal_entry_line_dimensions d ON d.line_id = l.id
               JOIN dimension_types t ON t.id = d.dimension_type_id AND t.system_key = 'activity'
              WHERE l.supplier_id = ? AND a.account_code NOT REGEXP '^[56]'"
        );
        $balanceSheet->execute([$supplierId]);
        self::assertSame(0, (int) $balanceSheet->fetchColumn(), 'Účty rozvahy činnost nedostávají.');
        self::assertSame($auditBefore + 2, $this->rows('activity_log', $supplierId), 'Audit zápisu a souhrn běhu.');
        self::assertSame(1, $this->rows('activity_log', $supplierId, "action = 'dimension.activity_by_centre' AND user_id = {$this->userId}"));
        self::assertSame(1, $this->rows('activity_log', $supplierId, "action = 'dimension.journal_lines_updated' AND JSON_VALUE(payload, '$.source') = 'pohoda_activity_by_centre'"));

        $auditAfter = $this->rows('activity_log', $supplierId);
        $again = $service->run($supplierId, $map);
        self::assertSame(0, $again['changed']);
        self::assertSame(2, $again['unchanged']);
        self::assertSame($auditAfter, $this->rows('activity_log', $supplierId), 'Opakovaný běh do auditu nepíše.');

        $reimported = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($reimported->hasErrors(), $this->explain($reimported));
        self::assertSame('economic', $this->activityByDocument($supplierId)['26IN0098'], 'Opakovaný převod přeřazení nevrátí.');
    }

    /**
     * Uzavřené období se nepřepisuje a řádek se počítá jako přeskočený, stejně jako řádek
     * s rozpadem, storno a řádek se střediskem změněným v MyÚčtu. Kód se porovnává přesně.
     */
    public function testActivityByCentreSkipsWhatItMustNotOverwrite(): void
    {
        [$supplierId] = $this->nonprofitWithCentres();
        $pdo = $this->db->pdo();
        $service = $this->activityByCentre();
        $map = ['SVETLO' => 'economic', 'ROZPAD' => 'economic', 'STORNO' => 'economic', 'PRESUN' => 'economic'];
        $typeId = (int) $pdo->query("SELECT id FROM dimension_types WHERE supplier_id = {$supplierId} AND system_key = 'activity'")->fetchColumn();
        $values = $pdo->query("SELECT semantic_key, id FROM dimension_values WHERE type_id = {$typeId}")->fetchAll(\PDO::FETCH_KEY_PAIR);

        $rozpad = $this->revenueLine($supplierId, '26IN0096');
        $pdo->exec("DELETE FROM journal_entry_line_dimensions WHERE line_id = {$rozpad} AND dimension_type_id = {$typeId}");
        $pdo->exec("INSERT INTO journal_entry_line_dimension_splits (line_id, dimension_type_id, dimension_value_id, supplier_id, share)
            VALUES ({$rozpad}, {$typeId}, {$values['main']}, {$supplierId}, 0.5), ({$rozpad}, {$typeId}, {$values['economic']}, {$supplierId}, 0.5)");

        $original = $this->entryId($supplierId, '26IN0095');
        $reversal = $this->entryId($supplierId, '26IN0094');
        $pdo->exec("UPDATE journal_entries SET reversed_by = {$reversal} WHERE id = {$original}");

        $presun = $this->revenueLine($supplierId, '26IN0093');
        $other = $pdo->query("SELECT v.id, v.type_id FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id
            WHERE t.supplier_id = {$supplierId} AND t.kind = 'cost_center' AND v.code = 'JINE'")->fetch(\PDO::FETCH_ASSOC);
        $pdo->exec("UPDATE journal_entry_line_dimensions SET dimension_value_id = {$other['id']} WHERE line_id = {$presun} AND dimension_type_id = {$other['type_id']}");

        $open = $service->run($supplierId, $map + ['svetlo' => 'main'], true);
        $outcomes = array_column(self::normalizedRows($open), 'outcome', 'centre');
        self::assertSame('changed', $outcomes['SVETLO']);
        self::assertSame('split', $outcomes['ROZPAD']);
        self::assertSame('reversed', $outcomes['STORNO']);
        self::assertSame('centre_changed', $outcomes['PRESUN']);
        self::assertSame(['svetlo'], $open['unknown_centres'], 'Kód střediska se porovnává přesně.');
        $otherCase = $service->run($supplierId, ['svetlo' => 'economic'], true);
        self::assertSame(['svetlo'], $otherCase['unknown_centres']);
        self::assertSame(1, $otherCase['outside_map']['SVETLO'] ?? null, 'Jiná velikost písmen není totéž středisko.');
        self::assertSame(0, $otherCase['changed']);
        self::assertSame(1, $open['changed']);
        self::assertSame(4, $open['skipped'], 'Rozpad, obě strany storna a změněné středisko.');

        $periodId = (int) $pdo->query("SELECT period_id FROM journal_entries WHERE id = {$original}")->fetchColumn();
        $pdo->exec("UPDATE accounting_periods SET status = 'closed' WHERE id = {$periodId}");
        $before = $this->activityByDocument($supplierId);
        foreach ([true, false] as $dryRun) {
            $closed = $service->run($supplierId, $map, $dryRun);
            self::assertSame(0, $closed['changed'], $dryRun ? 'nanečisto' : 'ostře');
            self::assertSame(5, $closed['skipped'], $dryRun ? 'nanečisto' : 'ostře');
            self::assertContains(['centre' => 'SVETLO', 'activity' => 'economic', 'outcome' => 'period_not_open', 'lines' => 1, 'debit' => '0.00', 'credit' => '1200.00'], self::normalizedRows($closed));
            self::assertSame($before, $this->activityByDocument($supplierId), 'Uzavřené období se nepřepisuje.');
        }
        $split = $pdo->query("SELECT COUNT(*) FROM journal_entry_line_dimension_splits WHERE line_id = {$rozpad}")->fetchColumn();
        self::assertSame(2, (int) $split, 'Rozpad zůstává.');
    }

    public function testActivityByCentreRefusesBadInput(): void
    {
        self::assertSame(['VÝUKA' => 'main', 'Zvuk=Světlo' => 'economic'], NonprofitActivityByCentre::parseMap(' VÝUKA = main ,Zvuk=Světlo=economic'));
        foreach (['', 'VYUKA', 'VYUKA=hlavni', '=main', 'VYUKA=main,VYUKA=economic'] as $bad) {
            try {
                NonprofitActivityByCentre::parseMap($bad);
                self::fail("Mapa „{$bad}\" neměla projít.");
            } catch (PohodaException $e) {
                self::assertSame('invalid_centre_map', $e->errorCode);
            }
        }
        try {
            $this->activityByCentre()->run($this->supplier(), ['VYUKA' => 'main'], true);
            self::fail('Firma bez dimenze Činnost neměla projít.');
        } catch (PohodaException $e) {
            self::assertSame('activity_dimension_missing', $e->errorCode);
        }
    }

    private function activityByCentre(): NonprofitActivityByCentre
    {
        return $this->byCentre;
    }

    private static function centreDoc(): string
    {
        return SyntheticPohodaExport::CENTRE_DOCUMENT;
    }

    /** @return array{0:int,1:PohodaExport} převedená firma 504 se středisky na nákladech a výnosech */
    private function nonprofitWithCentres(): array
    {
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withCostCentre($dir);
        foreach ([
            ['26IN0098', 'SVETLO', 'Zvuk a světlo', 1200.0, '311001', '602000'],
            ['26IN0097', 'JINE', 'Jiné', 300.0, '518000', '321001'],
            ['26IN0096', 'ROZPAD', 'Rozdělené', 100.0, '311001', '602000'],
            ['26IN0095', 'STORNO', 'Stornované', 70.0, '311001', '602000'],
            ['26IN0094', 'STORNO', 'Stornované', 70.0, '311001', '602000'],
            ['26IN0093', 'PRESUN', 'Přesunuté', 40.0, '311001', '602000'],
        ] as [$number, $centre, $name, $amount, $debit, $credit]) {
            SyntheticPohodaExport::withCentreEntry($dir, $number, $centre, $name, 'Pohyb střediska', $amount, $debit, $credit);
        }
        SyntheticPohodaExport::withNonprofitChart($dir);
        $export = PohodaExport::open($dir);
        $supplierId = $this->supplier();
        $this->adopt504($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        return [$supplierId, $export];
    }

    /** @return array<string,string> číslo dokladu => činnost řádku nákladů nebo výnosů */
    private function activityByDocument(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(e.document_no, CONCAT('#', l.id)), v.semantic_key
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               JOIN journal_entry_line_dimensions d ON d.line_id = l.id
               JOIN dimension_types t ON t.id = d.dimension_type_id AND t.system_key = 'activity'
               JOIN dimension_values v ON v.id = d.dimension_value_id
              WHERE l.supplier_id = ? AND a.account_code REGEXP '^[56]'
              ORDER BY l.id"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function entryId(int $supplierId, string $documentNo): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id = ? AND document_no = ?');
        $stmt->execute([$supplierId, $documentNo]);
        return (int) $stmt->fetchColumn();
    }

    private function revenueLine(int $supplierId, string $documentNo): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code REGEXP '^[56]'"
        );
        $stmt->execute([$supplierId, $this->entryId($supplierId, $documentNo)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{rows:list<array<string,mixed>>} $result
     * @return list<array<string,mixed>>
     */
    private static function normalizedRows(array $result): array
    {
        return array_map(static fn (array $row): array => [
            'centre' => $row['centre'], 'activity' => $row['activity'], 'outcome' => $row['outcome'],
            'lines' => $row['lines'], 'debit' => $row['debit'], 'credit' => $row['credit'],
        ], $result['rows']);
    }

    public function testInternalDocumentGetsHeaderOverItsJournalEntry(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withCostCentre($dir);
        $export = PohodaExport::open($dir);
        $pdo = $this->db->pdo();
        $number = SyntheticPohodaExport::CENTRE_DOCUMENT;

        foreach ([1, 2] as $run) {
            $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            $row = $pdo->query("SELECT d.title, d.amount, d.status, d.origin, d.journal_entry_id = e.id AS same_entry, d.entry_date = e.entry_date AS same_date
                  FROM internal_documents d
                  JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.document_no = d.document_no
                 WHERE d.supplier_id = {$supplierId} AND d.document_no = '{$number}'")->fetch(\PDO::FETCH_ASSOC);
            self::assertSame(['title' => 'Učebny', 'amount' => '500.00', 'status' => 'posted', 'origin' => 'journal', 'same_entry' => 1, 'same_date' => 1], $row, "běh {$run}");
            self::assertSame(1, $this->rows('internal_documents', $supplierId, "document_no = '{$number}'"), "běh {$run}");
            self::assertSame(2, $this->rows('internal_document_lines', $supplierId), "běh {$run}");
        }
        self::assertSame(1, self::stepCounts($protocol, 'payments')['internal_documents_existing'] ?? 0, $this->explain($protocol));
        self::assertArrayNotHasKey('internal_documents', self::stepCounts($protocol, 'payments'));
    }

    /** @return array<string,array{kind:string,partner_id:?string,partner_name:?string}> */
    private function internalDocuments(int $supplierId): array
    {
        $rows = $this->db->pdo()->query("SELECT document_no, kind, partner_id, partner_name FROM internal_documents WHERE supplier_id = {$supplierId}")->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['document_no']] = ['kind' => (string) $row['kind'], 'partner_id' => $row['partner_id'] === null ? null : (string) $row['partner_id'], 'partner_name' => $row['partner_name']];
        }
        return $out;
    }

    /**
     * Hlavička interního dokladu nese druh z kontace a jednoznačného partnera z řádků deníku.
     * Opakovaný převod ani opravný převod vazeb nepřepíší, co na dokladu změnil uživatel.
     */
    public function testInternalDocumentGetsKindAndSolePartnerAndKeepsThemOnRepeatedRun(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withInternalDocumentKinds($dir);
        $export = PohodaExport::open($dir);

        $dry = $this->importer->run($supplierId, $this->userId, $export, true);
        self::assertFalse($dry->hasErrors(), $this->explain($dry));
        self::assertSame(0, $this->rows('internal_documents', $supplierId), 'zkouška nanečisto nic nezanechá');

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $vendor = (string) $this->db->pdo()->query("SELECT id FROM clients WHERE supplier_id = {$supplierId} AND ic = '" . SyntheticPohodaExport::VENDOR_ICO . "'")->fetchColumn();
        self::assertNotSame('', $vendor);
        $documents = $this->internalDocuments($supplierId);
        self::assertSame(['kind' => 'other', 'partner_id' => $vendor, 'partner_name' => 'Dodavatel Test'], $documents[SyntheticPohodaExport::INT_KNOWN_PARTNER]);
        self::assertSame(['kind' => 'other', 'partner_id' => null, 'partner_name' => null], $documents[SyntheticPohodaExport::INT_TWO_PARTNERS]);
        self::assertSame(['kind' => 'other', 'partner_id' => null, 'partner_name' => SyntheticPohodaExport::INT_UNKNOWN_NAME], $documents[SyntheticPohodaExport::INT_UNKNOWN_PARTNER]);
        self::assertSame(['kind' => 'other', 'partner_id' => null, 'partner_name' => null], $documents[SyntheticPohodaExport::INT_OWN_PARTNER]);
        self::assertSame('fx_difference', $documents[SyntheticPohodaExport::INT_FX]['kind']);
        self::assertSame('accrual', $documents[SyntheticPohodaExport::INT_ACCRUAL]['kind']);
        self::assertSame('offset', $documents[SyntheticPohodaExport::INT_OFFSET]['kind']);
        self::assertSame('other', $documents[SyntheticPohodaExport::INT_PAYROLL]['kind']);
        self::assertSame(0, $this->rows('clients', $supplierId, "company_name = '" . SyntheticPohodaExport::INT_UNKNOWN_NAME . "'"), 'převod interního dokladu kartu nezakládá');

        $this->db->pdo()->exec("UPDATE internal_documents SET kind = 'correction', partner_id = NULL, partner_name = 'Upraveno ručně'
             WHERE supplier_id = {$supplierId} AND document_no = '" . SyntheticPohodaExport::INT_KNOWN_PARTNER . "'");
        $edited = $this->internalDocuments($supplierId);
        foreach ([false, true] as $relinkOnly) {
            $again = $this->importer->run($supplierId, $this->userId, $export, false, relinkOnly: $relinkOnly);
            self::assertFalse($again->hasErrors(), $this->explain($again));
            self::assertSame($edited, $this->internalDocuments($supplierId));
        }
    }

    /** Zápočet se pozná z úhrad dokladů, i když to z textu dokladu není vidět. */
    public function testInternalDocumentSettlingInvoicesIsOffset(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withAdvancesAndSettlements($dir);
        SyntheticPohodaExport::withOffsetTextHidden($dir);

        $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false, acceptDifferences: true);

        self::assertSame('offset', $this->internalDocuments($supplierId)[SyntheticPohodaExport::OFFSET_DOCUMENT]['kind'] ?? null);
    }

    /** Karta z mapy převodu, která mezitím zmizela, nesmí doklad připravit o hlavičku. */
    public function testInternalDocumentFallsBackToNameWhenMappedClientIsGone(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withInternalDocumentKinds($dir);
        (new PohodaImportRepository($this->db))->put($supplierId, PohodaImportRepository::KIND_CLIENT, 'name:' . mb_strtolower(SyntheticPohodaExport::INT_UNKNOWN_NAME), 2147483000, null);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['kind' => 'other', 'partner_id' => null, 'partner_name' => SyntheticPohodaExport::INT_UNKNOWN_NAME], $this->internalDocuments($supplierId)[SyntheticPohodaExport::INT_UNKNOWN_PARTNER]);
    }

    /**
     * Oprava odpočtu § 74b z interního dokladu POHODY se dostane do evidence § 74b, a tím
     * do přiznání a KH: vrácení v měsíci opravy, opětovné uplatnění v měsíci úhrady.
     */
    public function testDebtorDeductionCorrectionGoesToSection74bLedger(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        $target = '26PF0001';
        SyntheticPohodaExport::withDebtorDeductionCorrection($dir, $target, [100.0, 21.0]);
        $export = PohodaExport::open($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, self::stepCounts($protocol, 'internal_tax_documents')['s74b_corrections'] ?? 0, $this->explain($protocol));

        $invoice = $this->documentId('purchase_invoices', $supplierId, $target);
        $movements = $this->db->pdo()->query("SELECT purchase_invoice_id, period_year, period_month, movement, vat_amount, state
            FROM vat_s74b_corrections WHERE supplier_id = {$supplierId} ORDER BY period_month")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([
            [(string) $invoice, '2026', '5', 'reduction', '21.00', 'corrected'],
            [(string) $invoice, '2026', '6', 'restoration', '21.00', 'restored'],
        ], array_map(static fn (array $row): array => array_map('strval', $row), $movements));

        $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertSame(2, $this->rows('vat_s74b_corrections', $supplierId));
    }

    /** @return array<string,int|float> */
    private static function stepCounts(ImportProtocol $protocol, string $key): array
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step['counts'];
            }
        }
        return [];
    }

    /**
     * Krácený odpočet (§ 76): převod nastaví koeficient stejně jako u Money S3, jinak by
     * přiznání s ř. 52 nešlo sestavit (vat_coefficient_missing).
     */
    public function testReducedDeductionGetsCoefficientSoTheReturnCanBeBuilt(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withReducedDeduction($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "vat_deduction = 'reduced'"));
        self::assertContains('provisional_from_own_year', $this->messageCodes($protocol));

        $coefficient = $this->db->pdo()->prepare('SELECT provisional_percent, settled_at FROM vat_coefficients WHERE supplier_id = ? AND year = ?');
        $coefficient->execute([$supplierId, SyntheticPohodaExport::YEAR]);
        self::assertSame(['100', null], array_values(array_map(static fn ($v) => $v === null ? null : (string) $v, $coefficient->fetch(\PDO::FETCH_ASSOC) ?: [])));

        $return = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class)->build($supplierId, SyntheticPohodaExport::YEAR, 1, 'monthly');
        self::assertEqualsWithDelta(105.0, (float) ($return['summary']['lines']['40k']['vat'] ?? 0), 0.005, 'Krácený odpočet ř. 40 (sloupec krácený).');
    }

    /** Členění s ř. 47 (pořízení majetku): položky nesou příznak a přiznání má ř. 47. */
    public function testFixedAssetClassificationFillsLine47(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withFixedAssetPurchase($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "vendor_invoice_number = 'D-2026-7' AND is_fixed_asset = 1"));

        $return = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class)->build($supplierId, SyntheticPohodaExport::YEAR, 1, 'monthly');
        self::assertEqualsWithDelta(500.0, (float) ($return['summary']['lines']['47']['base'] ?? 0), 0.005, json_encode($return['summary']['lines']));
        self::assertEqualsWithDelta(105.0, (float) ($return['summary']['lines']['40']['vat'] ?? 0), 0.005);
    }

    /**
     * Služba z EU se samovyměřením v cizí měně: POHODA vyměří daň ze základu 2 500 Kč (kurz ke
     * dni plnění), faktura zní na 2 530 Kč (kurz faktury). Rozdíl 30 Kč je položka dokladu, ale
     * předmětem daně není - v přiznání ani v KH A.2 nesmí být. Dřív ho evidence DPH kvůli
     * příznaku samovyměření na hlavičce zařadila jako službu z EU (24e) a dopočítala z něj
     * daň 6,30 Kč na ř. 5 i ř. 43 a do KH A.2.
     */
    public function testSelfAssessedForeignPurchaseDoesNotTaxTheRateDifference(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withSelfAssessedForeignPurchase($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertSame(1, self::stepCounts($protocol, 'purchase_invoices')['self_assessed'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, sprintf(
            "varsymbol = '%s' AND reverse_charge = 1 AND total_with_vat = 2530.00 AND status <> 'draft'", SyntheticPohodaExport::SELF_ASSESSED_PURCHASE)), $this->explain($protocol));

        $container = Bootstrap::buildContainer();
        $lines = $container->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class)->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines'];
        self::assertEqualsWithDelta(2500.0, (float) ($lines['5']['base'] ?? 0), 0.005, json_encode($lines));
        self::assertEqualsWithDelta(525.0, (float) ($lines['5']['vat'] ?? 0), 0.005, json_encode($lines));
        self::assertEqualsWithDelta(525.0, (float) ($lines['43']['vat'] ?? 0), 0.005, json_encode($lines));

        $a2 = ['base' => 0.0, 'vat' => 0.0];
        foreach ($container->get(\MyInvoice\Service\Report\VatLedgerService::class)->rows($supplierId, '2026-02-01', '2026-02-28') as $row) {
            if ($row['kh_section'] === 'A.2') {
                $a2['base'] += $row['base_czk'];
                $a2['vat'] += $row['vat_czk'];
            }
        }
        self::assertEqualsWithDelta(2500.0, $a2['base'], 0.005, 'KH A.2 jen ze základu samovyměření.');
        self::assertEqualsWithDelta(525.0, $a2['vat'], 0.005);
    }

    /**
     * Samovyměření vedené jen dvojicí interních dokladů (vyměření `DD…` + odpočet `PD…`)
     * u faktury v členění mimo přiznání nebo u smíšené tuzemské faktury. Převod dřív tyto
     * interní doklady nepřiřadil („doplňte ručně") a ř. 3–13 i 43, 44 zůstaly prázdné.
     * Rozdíl proti téže agendě bez těchto dokladů: ř. 5 +3 500 / 735, ř. 10 +10 000 / 2 100,
     * ř. 43 jen ze dvou dokladů s odpočtem (525 + 2 100), tuzemská doprava ř. 40 +210.
     */
    public function testSelfAssessmentKeptOnlyOnInternalDocumentsGoesToTheReturn(): void
    {
        $base = $this->supplier();
        $baseDir = $this->tmp . DIRECTORY_SEPARATOR . 'base';
        mkdir($baseDir);
        $this->importer->run($base, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($baseDir)), false);

        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withSelfAssessmentByInternalDocuments($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);

        self::assertSame(3, self::stepCounts($protocol, 'purchase_invoices')['self_assessed'] ?? 0, $this->explain($protocol));
        self::assertStringNotContainsString('self_assessment_unlinked', $this->explain($protocol));
        self::assertSame(3, $this->rows('purchase_invoices', $supplierId, sprintf("varsymbol IN ('%s', '%s', '%s') AND reverse_charge = 1 AND status <> 'draft'",
            SyntheticPohodaExport::PAIR_SERVICE, SyntheticPohodaExport::PAIR_MIXED, SyntheticPohodaExport::PAIR_NO_DEDUCTION)), $this->explain($protocol));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, sprintf("varsymbol = '%s' AND vat_deduction = 'none'", SyntheticPohodaExport::PAIR_NO_DEDUCTION)));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, sprintf("varsymbol = '%s' AND total_with_vat = 11210.00 AND total_vat = 210.00", SyntheticPohodaExport::PAIR_MIXED)));

        $builder = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
        $before = $builder->build($base, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines'];
        $after = $builder->build($supplierId, SyntheticPohodaExport::YEAR, 2, 'monthly')['summary']['lines'];
        $delta = static fn (string $line, string $key): float => (float) ($after[$line][$key] ?? 0) - (float) ($before[$line][$key] ?? 0);
        $context = json_encode(['before' => $before, 'after' => $after]);
        self::assertEqualsWithDelta(3500.0, $delta('5', 'base'), 0.005, $context);
        self::assertEqualsWithDelta(735.0, $delta('5', 'vat'), 0.005, $context);
        self::assertEqualsWithDelta(10000.0, $delta('10', 'base'), 0.005, $context);
        self::assertEqualsWithDelta(2100.0, $delta('10', 'vat'), 0.005, $context);
        self::assertEqualsWithDelta(2625.0, $delta('43', 'vat'), 0.005, $context);
        self::assertEqualsWithDelta(210.0, $delta('40', 'vat'), 0.005, $context);
    }

    /**
     * Platební brána: poplatek a příjem jsou v POHODĚ dva bankovní doklady se stejným číslem
     * a jejich zápisy převod sloučí do jednoho. Vlastnit ho musí pohyb, který hradí fakturu -
     * dřív ho dostal první pohyb (poplatek) a uhrazená faktura visela mezi nezaúčtovanými
     * pohyby, ačkoli zápis v deníku má.
     */
    public function testPaymentGatewayEntryBelongsToTheMovementPayingTheInvoice(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withPaymentGateway($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.amount, t.match_status, EXISTS(SELECT 1 FROM journal_entries j WHERE j.supplier_id = s.supplier_id AND j.source_type = 'bank'
                    AND j.source_id = t.id AND j.reversed_by IS NULL) AS owns_entry
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.bank_ref = ? ORDER BY t.id"
        );
        $stmt->execute([$supplierId, SyntheticPohodaExport::GATEWAY_BANK]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows, $this->explain($protocol));
        $payment = array_values(array_filter($rows, static fn (array $r): bool => (float) $r['amount'] > 0))[0] ?? [];
        self::assertSame('manual', $payment['match_status'] ?? null, json_encode($rows));
        self::assertSame(1, (int) ($payment['owns_entry'] ?? 0), json_encode($rows));
    }

    /**
     * Odpočet uplatněný v POHODĚ před datem plnění. Doklad běžného období zařadí evidence
     * DPH do období plnění a protokol na posun upozorní. Doklad minulého období s datem
     * pro KH v minulém roce má POHODA v prvním období agendy, stejně jako evidence DPH
     * podle data plnění - v lednu zůstává a varování nedostane.
     */
    public function testEarlyClaimIsReportedOnlyWhenThePeriodDiffers(): void
    {
        $base = $this->supplier();
        $baseDir = $this->tmp . DIRECTORY_SEPARATOR . 'base';
        mkdir($baseDir);
        $this->importer->run($base, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($baseDir)), false);

        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withEarlyClaims($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertSame(1, self::stepCounts($protocol, 'purchase_invoices')['claim_moved'] ?? 0, $this->explain($protocol));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        $warned = array_column(array_filter($steps['purchase_invoices']['messages'],
            static fn (array $m): bool => $m['code'] === 'claim_before_document'), 'context');
        self::assertSame([SyntheticPohodaExport::EARLY_CLAIM_CURRENT], array_column($warned, 'document_no'));

        $builder = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
        $delta = function (int $month, string $line) use ($builder, $base, $supplierId): float {
            $after = $builder->build($supplierId, SyntheticPohodaExport::YEAR, $month, 'monthly')['summary']['lines'];
            $before = $builder->build($base, SyntheticPohodaExport::YEAR, $month, 'monthly')['summary']['lines'];
            return (float) ($after[$line]['vat'] ?? 0) - (float) ($before[$line]['vat'] ?? 0);
        };
        self::assertEqualsWithDelta(210.0, $delta(1, '40'), 0.005, 'Doklad minulého období v prvním období agendy.');
        self::assertEqualsWithDelta(210.0, $delta(3, '40'), 0.005, 'Odpočet běžného dokladu v období plnění.');
    }

    /** Vydaný doklad v tuzemském přenesení daňové povinnosti (ř. 25) nese příznak na hlavičce. */
    public function testDomesticReverseSaleIsFlagged(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withDomesticReverseSale($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND vat_classification_code = '25s' AND reverse_charge = 1 AND status <> 'draft'", SyntheticPohodaExport::REVERSE_SALE)), $this->explain($protocol));
        self::assertSame(0, $this->rows('invoices', $supplierId, sprintf("varsymbol <> '%s' AND reverse_charge = 1", SyntheticPohodaExport::REVERSE_SALE)));
    }

    /** Dvě firmy v jednom procesu: kontakty každé dostanou měnu z číselníku své firmy. */
    public function testPartnerCurrencyBelongsToItsOwnSupplier(): void
    {
        foreach ([$this->supplier(), $this->supplier()] as $supplierId) {
            $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/' . $supplierId)), false);
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            self::assertGreaterThan(0, $this->rows('clients', $supplierId));
            self::assertSame(0, $this->rows('clients', $supplierId,
                'NOT EXISTS (SELECT 1 FROM currencies cu WHERE cu.id = clients.currency_default_id AND cu.supplier_id = clients.supplier_id)'));
        }
    }

    public function testDryRunLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshot($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /** Bankovní účet s pohyby patří mezi účty firmy (tab Účty, zůstatky), i když firma už korunový účet má. */
    public function testBankAccountJoinsCompanyAccounts(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE currencies SET account_number = '2000000009', bank_code = '0300' WHERE supplier_id = ?")->execute([$supplierId]);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(2, $this->rows('currencies', $supplierId, "code = 'CZK'"));
        self::assertSame(1, $this->rows('currencies', $supplierId, "account_number = '2000000009' AND is_default = 1"));
        self::assertSame(1, $this->rows('currencies', $supplierId, sprintf("account_number = '%s' AND bank_code = '%s' AND is_default = 0 AND is_active = 1",
            SyntheticPohodaExport::BANK_ACCOUNT, SyntheticPohodaExport::BANK_CODE)));
        $linked = $pdo->prepare(
            'SELECT COUNT(*) FROM supplier_bank_accounts a JOIN currencies c ON c.id = a.currency_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND c.account_number = ?'
        );
        $linked->execute([$supplierId, SyntheticPohodaExport::BANK_ACCOUNT]);
        self::assertSame(1, (int) $linked->fetchColumn());

        // Opakovaný převod účet nezdvojí.
        $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);
        self::assertSame(2, $this->rows('currencies', $supplierId, "code = 'CZK'"));
    }

    /**
     * Majetek z datového souboru POHODY: karta zařazená bez zápisu v deníku, počáteční
     * stavy pokrývají odpisy do posledního měsíce zaúčtovaného v POHODĚ (březen 2026)
     * a účetní odpis roku v MyÚčtu je jen za zbytek roku - leden až březen se nezdvojí.
     */
    public function testAssetsContinueAfterDepreciationBookedInPohoda(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, true));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, self::stepCounts($protocol, 'assets')['created'] ?? 0, $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare('SELECT * FROM assets WHERE supplier_id = ? AND inventory_number = ?');
        $stmt->execute([$supplierId, SyntheticPohodaExport::ASSET_NUMBER]);
        $asset = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($asset);
        self::assertSame('in_use', $asset['status']);
        self::assertSame('2025-01-15', $asset['put_into_use_date']);
        self::assertSame('022.001', $asset['asset_account_code']);
        self::assertSame('082.001', $asset['accumulated_account_code']);
        // Účet pořízení se v deníku nepoužil, převod ho proto nezaložil - karta dostane syntetický 042.
        self::assertSame('042', $asset['acquisition_account_code']);
        self::assertSame('straight', $asset['tax_method']);
        self::assertSame(1, (int) $asset['tax_group']);
        self::assertSame([1, '24000.00'], [(int) $asset['opening_tax_years'], (string) $asset['opening_tax_amount']]);
        self::assertSame([14, '75000.00', 23], [(int) $asset['opening_acc_months'], (string) $asset['opening_acc_amount'], (int) $asset['acc_useful_life_months']]);
        // Zařazení se neúčtuje - zůstatky 022/082 přišly počátečními stavy.
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "source_type = 'asset'"));

        $plan = Bootstrap::buildContainer()->get(AssetService::class)->plan($supplierId, (int) $asset['id']);
        $year = array_values(array_filter($plan['accounting'], static fn (array $row): bool => (int) $row['fiscal_year'] === SyntheticPohodaExport::YEAR))[0] ?? null;
        self::assertNotNull($year, json_encode($plan['accounting']));
        self::assertEqualsWithDelta(45000.0, (float) $year['amount'], 0.005, 'Duben až prosinec 2026 po 5 000 Kč.');

        // Drobný majetek: karta navázaná na převedenou přijatou fakturu i s položkou, vyřazená karta bez dokladu
        // a dvě karty bez odkazu navázané cenou za kus na jednu položku se 2 ks.
        $counts = self::stepCounts($protocol, 'small_assets');
        ksort($counts);
        self::assertSame(['created' => 4, 'linked' => 3, 'matched_by_amount' => 2, 'no_document' => 1], $counts, $this->explain($protocol));
        $small = $this->db->pdo()->prepare(
            'SELECT s.inventory_number, s.status, s.disposed_at, s.quantity, s.price, s.location, s.document_ref, s.purchase_invoice_item_id,
                    pi.vendor_invoice_number
               FROM small_assets s LEFT JOIN purchase_invoices pi ON pi.id = s.purchase_invoice_id
              WHERE s.supplier_id = ? ORDER BY s.inventory_number'
        );
        $small->execute([$supplierId]);
        [$drill, $chairs, $monitor, $monitor2] = $small->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['DM0003', 'D-2026-8', 'D-2026-8'], [$monitor['inventory_number'], $monitor['document_ref'], $monitor['vendor_invoice_number']]);
        self::assertNotNull($monitor['purchase_invoice_item_id'], 'Karta bez odkazu se naváže podle data a ceny za kus.');
        self::assertSame($monitor['purchase_invoice_item_id'], $monitor2['purchase_invoice_item_id'], 'Položka se 2 ks nese dvě karty.');
        // Položka s kartou je náklad na drobný majetek - obrat drobného majetku sedí na evidenci.
        $kinds = $this->db->pdo()->prepare('SELECT id, expense_kind FROM purchase_invoice_items WHERE id IN (?, ?)');
        $kinds->execute([$monitor['purchase_invoice_item_id'], $drill['purchase_invoice_item_id']]);
        self::assertSame(['small_asset'], array_values(array_unique($kinds->fetchAll(\PDO::FETCH_KEY_PAIR))));
        self::assertSame(['DM0001', 'in_use', 'Dílna', '26PF0001', 'D-2026-7'],
            [$drill['inventory_number'], $drill['status'], $drill['location'], $drill['document_ref'], $drill['vendor_invoice_number']]);
        self::assertNotNull($drill['purchase_invoice_item_id'], 'Karta je navázaná i na položku faktury.');
        self::assertSame(['DM0002', 'disposed', '2026-02-01', '2.000', '3000.00', null],
            [$chairs['inventory_number'], $chairs['status'], $chairs['disposed_at'], (string) $chairs['quantity'], (string) $chairs['price'], $chairs['document_ref']]);

        // Karta, kterou dřívější převod na doklad nenavázal, se při opakovaném převodu dohledá.
        $this->db->pdo()->prepare("UPDATE small_assets SET purchase_invoice_id = NULL, purchase_invoice_item_id = NULL WHERE supplier_id = ? AND inventory_number = 'DM0004'")
            ->execute([$supplierId]);

        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertSame(1, self::stepCounts($again, 'assets')['existing'] ?? 0, $this->explain($again));
        self::assertSame(4, self::stepCounts($again, 'small_assets')['existing'] ?? 0, $this->explain($again));
        $relinked = $this->db->pdo()->prepare("SELECT purchase_invoice_item_id FROM small_assets WHERE supplier_id = ? AND inventory_number = 'DM0004'");
        $relinked->execute([$supplierId]);
        self::assertSame((string) $monitor['purchase_invoice_item_id'], (string) $relinked->fetchColumn());
        self::assertSame(1, $this->rows('assets', $supplierId));
        self::assertSame(4, $this->rows('small_assets', $supplierId));
    }

    /**
     * Sklad z `92_sklad.xml`: karta ze dvou skladů je jedna, stav se založí po skladech
     * příjemkou na haléř podle ocenění POHODY a do deníku nic nejde (hodnotu zásob nese
     * převedený deník). Konsignační sklad zvolený jako „nepřevádět" se nepřevede, záporný
     * stav ani služba stav nedostanou, ceníky jsou cenové hladiny s cenou bez DPH.
     */
    public function testStockIsTakenOverWithOpeningPricesAndCategories(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withStock($dir);
        $export = PohodaExport::open($dir);
        $stock = ['warehouses' => ['HL' => 'goods', 'MAT' => 'material', 'KON' => 'skip']];

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false, stock: $stock);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $counts = self::stepCounts($protocol, 'stock');
        foreach (['warehouses' => 2, 'goods' => 3, 'materials' => 1, 'products' => 1, 'services' => 1, 'opening_lines' => 4, 'opening_documents' => 2,
            'opening_negative' => 1, 'skipped_text' => 1, 'skipped_warehouse_cards' => 1, 'merged_with_differences' => 1,
            'price_levels' => 2, 'price_rules' => 2, 'categories' => 2, 'units' => 1] as $key => $expected) {
            self::assertSame($expected, $counts[$key] ?? 0, $key . ': ' . $this->explain($protocol));
        }
        self::assertEqualsWithDelta(2847.0, (float) ($counts['opening_value'] ?? 0), 0.001, $this->explain($protocol));

        $levels = $this->db->pdo()->prepare(
            'SELECT w.code, i.sku, i.item_type, l.qty, l.value_total FROM stock_levels l
               JOIN warehouses w ON w.id = l.warehouse_id JOIN stock_items i ON i.id = l.stock_item_id
              WHERE l.supplier_id = ? ORDER BY w.code, i.sku'
        );
        $levels->execute([$supplierId]);
        self::assertSame([
            ['HL', 'K-001', 'goods', '120.500', '1210.37'],
            ['HL', 'V-01', 'product', '2.000', '1000.00'],
            ['MAT', 'K-001', 'goods', '30.000', '303.33'],
            ['MAT', 'M-001', 'material', '1000.000', '333.30'],
        ], array_map(static fn (array $r): array => array_values(array_map('strval', $r)), $levels->fetchAll(\PDO::FETCH_ASSOC)));
        self::assertSame(0, $this->rows('warehouses', $supplierId, "code = 'KON'"));
        self::assertSame(0, $this->rows('stock_items', $supplierId, "sku IN ('KON-01', 'T-01')"));
        self::assertSame(1, $this->rows('stock_items', $supplierId, "sku = 'SL-01' AND is_stocked = 0"));
        self::assertSame(1, $this->rows('stock_items', $supplierId, "sku = 'K-001' AND unit = 'm' AND ean = '4006381333931' AND sale_price_without_vat = 25.00 AND min_qty = 10.000 AND weight_g = 50"));
        self::assertSame(2, $this->rows('stock_documents', $supplierId, "doc_type = 'receipt' AND status = 'posted' AND doc_date = '2026-06-30' AND journal_entry_id IS NULL"));

        $rules = $this->db->pdo()->prepare(
            'SELECT l.code, r.fixed_price, r.currency_code FROM stock_price_level_rules r JOIN stock_price_levels l ON l.id = r.price_level_id
              WHERE r.supplier_id = ? ORDER BY l.code'
        );
        $rules->execute([$supplierId]);
        self::assertSame([['B2B', '30.00', 'CZK'], ['EUR', '1.20', 'EUR']],
            array_map(static fn (array $r): array => array_values(array_map('strval', $r)), $rules->fetchAll(\PDO::FETCH_ASSOC)));

        $category = $this->db->pdo()->prepare(
            'SELECT c.name, p.name AS parent FROM stock_item_categories ic JOIN stock_categories c ON c.id = ic.category_id
               LEFT JOIN stock_categories p ON p.id = c.parent_id JOIN stock_items i ON i.id = ic.stock_item_id
              WHERE ic.supplier_id = ? AND i.sku = ?'
        );
        $category->execute([$supplierId, 'K-001']);
        self::assertSame(['name' => 'Kabely', 'parent' => 'Elektro'], $category->fetch(\PDO::FETCH_ASSOC));
        $units = $this->db->pdo()->prepare('SELECT u.unit_code, u.numerator, u.denominator FROM stock_item_units u JOIN stock_items i ON i.id = u.stock_item_id WHERE u.supplier_id = ? AND i.sku = ?');
        $units->execute([$supplierId, 'K-001']);
        self::assertSame(['cívka', 100, 1], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $units->fetch(\PDO::FETCH_ASSOC))));

        // Opakovaný převod nic nezdvojí.
        $again = $this->importer->run($supplierId, $this->userId, $export, false, stock: $stock);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $counts = self::stepCounts($again, 'stock');
        self::assertSame([6, 4, 2, 0, 0], [$counts['items_existing'] ?? 0, $counts['opening_existing'] ?? 0, $counts['price_levels_existing'] ?? 0,
            $counts['opening_lines'] ?? 0, $counts['price_rules'] ?? 0], $this->explain($again));
        self::assertSame(2, $this->rows('stock_documents', $supplierId));
        self::assertSame(2, $this->rows('stock_price_level_rules', $supplierId));
    }

    /** Bez volby v průvodci a bez zapnutého skladu se sklad nepřevede, zbytek převodu ano. */
    public function testStockIsSkippedWithoutChoiceOrStockModule(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withStock($dir);
        $export = PohodaExport::open($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, true);
        self::assertContains('stock_not_selected', $this->messageCodes($protocol));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, true, stock: ['warehouses' => []]);
        self::assertContains('stock_module_missing', $this->messageCodes($protocol));
        self::assertSame(0, $this->rows('stock_items', $supplierId));
    }

    /**
     * Doklad v režimu OSS (členění mimo přiznání, sazba státu spotřeby, odběratel bez DIČ)
     * se převezme jako OSS plnění, ne jako koncept k ruční kontrole. U e-shopu prodávajícího
     * do EU jde o stovky až tisíce dokladů ročně - ručně neprůchodné.
     */
    public function testOssDocumentIsTakenOverAsOssSupplyNotAsDraft(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, false, true));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, 'Doklad v režimu OSS se nepřevedl. ' . $this->explain($protocol));
        self::assertNotSame('draft', $row['status'], 'OSS doklad nesmí skončit jako koncept. ' . $this->explain($protocol));
        self::assertNull($row['vat_classification_code'], 'Do českého přiznání OSS plnění nepatří.');
        self::assertSame(1, (int) $row['oss_applicable']);
        self::assertSame(SyntheticPohodaExport::OSS_COUNTRY, $row['oss_consumer_country']);
        self::assertSame('standard', $row['oss_rate_type']);
        // Jednotka „ks" je záměrně neutrální a firma nemá CZ-NACE ani výchozí typ na kartě,
        // takže typ plnění spadne na fallback „služba" - a protokol na to upozorní.
        self::assertSame('services', $row['oss_supply_type']);
        self::assertSame(0, (int) $row['oss_needs_manual_review']);
        self::assertContains('oss_item_warning', $this->messageCodes($protocol), $this->explain($protocol));
        // Sazba se napárovala ve státě spotřeby, ne v tuzemsku - jinak by slovenská daň
        // seděla na české sazbě a doklad by nešlo ani otevřít v editoru.
        self::assertSame(SyntheticPohodaExport::OSS_COUNTRY, $row['rate_country']);
        self::assertSame('23.00', (string) $row['vat_rate_snapshot']);

        self::assertSame(1, self::stepCounts($protocol, 'issued_invoices')['oss_items'] ?? 0, $this->explain($protocol));
        self::assertSame(0, self::stepCounts($protocol, 'issued_invoices')['review'] ?? 0, $this->explain($protocol));
    }

    /**
     * Issue #75: POHODA píše sazbu státu spotřeby, která se do české sazbové úrovně nevejde,
     * jako `historyHigh` BEZ atributu `value` a skutečné procento dává do `percentVAT`.
     * Převod četl jen atribut a úroveň, takže řádek s daní měl sazbu 0 %, OSS politika ho
     * jako „bez daně" přeskočila a doklad skončil konceptem s hláškou „členění DPH „UN“
     * mimo přiznání u dokladu s daní" - u zákazníka 1 160 dokladů, přestože režim OSS
     * i sazby státu spotřeby měl nastavené správně.
     */
    public function testOssDocumentWithPercentVatIsTakenOverAsOssSupply(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_PERCENT));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertNull($this->reviewMessage($protocol), 'OSS doklad nesmí skončit jako koncept. ' . $this->explain($protocol));
        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, 'Doklad v režimu OSS se nepřevedl. ' . $this->explain($protocol));
        self::assertNotSame('draft', $row['status'], $this->explain($protocol));
        self::assertNull($row['vat_classification_code'], 'Do českého přiznání OSS plnění nepatří.');
        self::assertSame(1, (int) $row['oss_applicable']);
        self::assertSame(SyntheticPohodaExport::OSS_COUNTRY, $row['oss_consumer_country']);
        self::assertSame(SyntheticPohodaExport::OSS_COUNTRY, $row['rate_country']);
        self::assertSame('23.00', (string) $row['vat_rate_snapshot']);
        self::assertSame(1, self::stepCounts($protocol, 'issued_invoices')['oss_items'] ?? 0, $this->explain($protocol));
    }

    /**
     * Issue #75: POHODA nese OSS údaje přímo v dokladu - stát spotřeby v hlavičce (`MOSS`)
     * a typ plnění na položce (`typeServiceMOSS`). Převod je nečetl, takže typ plnění byl
     * u každého řádku odhad „služba" s varováním a stát spotřeby jen z adresy odběratele.
     * Adresa tady zemi nenese vůbec: bez MOSS by stát spotřeby nebyl z čeho určit.
     */
    public function testOssSupplyTypeAndConsumerCountryComeFromMossFields(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_PERCENT,
            oss: new OssVariant(supplyType: 'GD', partnerCountry: null)));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertNull($this->reviewMessage($protocol), $this->explain($protocol));
        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, $this->explain($protocol));
        self::assertSame(1, (int) $row['oss_applicable'], $this->explain($protocol));
        self::assertSame(SyntheticPohodaExport::OSS_COUNTRY, $row['oss_consumer_country']);
        self::assertSame('goods', $row['oss_supply_type']);
        // Typ plnění uvádí doklad - varování o dosazené „službě" by bylo nepravdivé.
        self::assertNotContains('oss_item_warning', $this->messageCodes($protocol), $this->explain($protocol));
    }

    /** Ostatní druhy plnění POHODY (OS, ES, TS…) jsou v MyÚčtu služby - bez varování o odhadu. */
    public function testOssServiceTypeFromMossIsServicesWithoutGuessWarning(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_PERCENT,
            oss: new OssVariant(supplyType: 'OS')));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, $this->explain($protocol));
        self::assertSame(1, (int) $row['oss_applicable'], $this->explain($protocol));
        self::assertSame('services', $row['oss_supply_type']);
        self::assertNotContains('oss_item_warning', $this->messageCodes($protocol), $this->explain($protocol));
    }

    /**
     * Doklad v EUR u firmy bez eura v číselníku měn: převod euro založí a doklad převezme
     * v měně. OSS řádek nese částky pro přiznání v EUR z dokladu, ne koruny přepočtené
     * zpátky kurzem ECB ke konci čtvrtletí (na reálné agendě o 0,8 % víc).
     */
    public function testEurOssDocumentGoesToReturnInEurosFromTheDocument(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_PERCENT));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, $this->explain($protocol));
        self::assertSame(1, (int) $row['oss_applicable'], $this->explain($protocol));
        self::assertEqualsWithDelta(40.0, (float) $row['total_without_vat'], 0.001, 'Doklad převzatý v EUR.');
        self::assertEqualsWithDelta(9.2, (float) $row['total_vat'], 0.001);
        self::assertSame(1, $this->rows('currencies', $supplierId, "code = 'EUR'"));
        self::assertStringContainsString('currency_created', $this->explain($protocol));
        self::assertNotNull($row['oss_taxable_amount_return']);
        self::assertEqualsWithDelta(40.0, (float) $row['oss_taxable_amount_return'], 0.001);
        self::assertEqualsWithDelta(9.2, (float) $row['oss_vat_amount_return'], 0.001);

        // Náhled OSS podání bere eura z dokladu - bez kurzu ECB, který by v testu ani nebyl.
        $preview = $this->ossLedger->preview($supplierId, SyntheticPohodaExport::YEAR, 1);
        $sk = array_column($preview['countries'], null, 'country')[SyntheticPohodaExport::OSS_COUNTRY] ?? null;
        self::assertNotNull($sk, json_encode($preview, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(40.0, (float) $sk['base'], 0.001);
        self::assertEqualsWithDelta(9.2, (float) $sk['vat'], 0.001);
    }

    /**
     * Firma s eurem v číselníku měn: OSS doklad v EUR, jehož koruny z POHODY vycházejí
     * přesně kurzem (40 × 25 = 1 000, 9,20 × 25 = 230), se převezme v EUR. Částky pro OSS
     * podání i náhled zůstávají tytéž eura z dokladu.
     */
    public function testEurOssDocumentTakenOverInEurosKeepsTheOssReturn(): void
    {
        $supplierId = $this->supplier();
        $this->currency($supplierId, 'EUR');
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_PERCENT));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, $this->explain($protocol));
        self::assertSame(1, (int) $row['oss_applicable'], $this->explain($protocol));
        self::assertEqualsWithDelta(40.0, (float) $row['total_without_vat'], 0.001, 'Doklad je v EUR.');
        self::assertEqualsWithDelta(9.2, (float) $row['total_vat'], 0.001);
        self::assertEqualsWithDelta(40.0, (float) $row['oss_taxable_amount_return'], 0.001);
        self::assertEqualsWithDelta(9.2, (float) $row['oss_vat_amount_return'], 0.001);
        $stmt = $this->db->pdo()->prepare('SELECT c.code, i.exchange_rate FROM invoices i JOIN currencies c ON c.id = i.currency_id WHERE i.supplier_id = ? AND i.varsymbol = ?');
        $stmt->execute([$supplierId, SyntheticPohodaExport::OSS_DOCUMENT]);
        self::assertSame(['EUR', '25.000000'], $stmt->fetch(\PDO::FETCH_NUM));

        $preview = $this->ossLedger->preview($supplierId, SyntheticPohodaExport::YEAR, 1);
        $sk = array_column($preview['countries'], null, 'country')[SyntheticPohodaExport::OSS_COUNTRY] ?? null;
        self::assertNotNull($sk, json_encode($preview, JSON_UNESCAPED_UNICODE));
        self::assertEqualsWithDelta(40.0, (float) $sk['base'], 0.001);
        self::assertEqualsWithDelta(9.2, (float) $sk['vat'], 0.001);
        self::assertTrue($protocol->get('reconciliation')[0]['ok'], json_encode($protocol->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Členění mimo přiznání s daní, ale BEZ státu MOSS: POHODA takový doklad do svého OSS
     * přiznání nezahrnula. Převod ho dřív do OSS zařadil sám podle adresy odběratele - OSS
     * podání v MyÚčtu by pak neodpovídalo tomu, co firma z POHODY podala. Zůstane konceptem
     * s konkrétním důvodem a řádek je označený k ručnímu posouzení.
     */
    public function testUnDocumentWithTaxButWithoutMossStaysDraft(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_PERCENT,
            oss: new OssVariant(moss: null)));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, 'Doklad se musí převzít, jinak nesedí saldo. ' . $this->explain($protocol));
        self::assertSame('draft', $row['status'], $this->explain($protocol));
        self::assertSame(1, (int) $row['oss_needs_manual_review']);
        self::assertNull($row['vat_classification_code'], 'Do tuzemského přiznání koncept nepatří.');
        $reason = $this->reviewMessage($protocol);
        self::assertNotNull($reason, $this->explain($protocol));
        self::assertStringContainsString('chybí stát MOSS', $reason);
        self::assertSame(0, self::stepCounts($protocol, 'issued_invoices')['oss_items'] ?? 0, $this->explain($protocol));
    }

    /**
     * Když export sazbu řádku s daní opravdu nenese (ani `percentVAT`, ani `@value`), zůstane
     * doklad konceptem - ale hláška musí říct proč, ne jen zopakovat členění.
     */
    public function testOssDocumentWithoutRateInExportIsDraftWithConcreteReason(): void
    {
        $supplierId = $this->supplier();
        $this->enableOss($supplierId);
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, withOss: true, ossRateForm: SyntheticPohodaExport::OSS_RATE_HISTORY));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, $this->explain($protocol));
        self::assertSame('draft', $row['status']);
        self::assertSame(0, (int) $row['oss_applicable']);
        $reason = $this->reviewMessage($protocol);
        self::assertNotNull($reason, $this->explain($protocol));
        self::assertStringContainsString('sazbu DPH', $reason, 'Hláška musí pojmenovat příčinu, ne jen konstatovat členění.');
    }

    /**
     * Bez zapnutého režimu OSS se cizí daň do tuzemského přiznání nepustí ani omylem.
     * Doklad se nepřevezme (23 % není tuzemská sazba, takže není na co řádek navázat),
     * ale zbytek agendy doteče a protokol jednou za běh řekne, co zapnout.
     */
    public function testOssDocumentIsRefusedWhenOssModeIsOff(): void
    {
        $supplierId = $this->supplier();
        $this->foreignRate(SyntheticPohodaExport::OSS_COUNTRY, SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, false, true));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);

        self::assertNull($this->ossDocument($supplierId), 'Cizí daň nesmí do tuzemské větve.');
        $codes = $this->messageCodes($protocol);
        self::assertContains('oss_setup', $codes, $this->explain($protocol));
        self::assertContains('unknown_vat_rate', $codes, $this->explain($protocol));
        // Ostatní doklady agendy převod dotáhne - jeden vadný doklad ho nesmí zastavit.
        self::assertSame(1, $this->rows('invoices', $supplierId, "varsymbol = '26FV0001'"), $this->explain($protocol));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = '26PF0001'"), $this->explain($protocol));
    }

    /**
     * Firma, která má zahraniční sazbu omylem založenou se zemí CZ (formulář ji tak
     * předvyplňuje), a vypnutý režim OSS: doklad projde jako koncept k ruční kontrole
     * a důvod konečně říká, co doplnit. Přesně tenhle stav nahlásil zákazník.
     */
    public function testOssDocumentBecomesDraftWithActionableReasonWhenRateLooksDomestic(): void
    {
        $supplierId = $this->supplier();
        $this->domesticRate(SyntheticPohodaExport::OSS_RATE);

        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, false, true));
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);

        $row = $this->ossDocument($supplierId);
        self::assertNotNull($row, $this->explain($protocol));
        self::assertSame('draft', $row['status']);
        self::assertSame(0, (int) $row['oss_applicable']);
        self::assertNull($row['vat_classification_code'], 'Do českého přiznání nepatří ani jako koncept.');

        $reason = $this->reviewMessage($protocol);
        self::assertNotNull($reason, $this->explain($protocol));
        self::assertStringContainsString('OSS', $reason, 'Hláška musí pojmenovat příčinu, ne jen konstatovat členění.');
        // Důvod z OSS plánovače je celá věta s tečkou - hláška ani poznámka dokladu z ní
        // nesmí udělat „..".
        self::assertStringNotContainsString('..', $reason);
        self::assertStringNotContainsString('..', $this->ossNote($supplierId));
    }

    private function ossNote(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT note_below_items FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, SyntheticPohodaExport::OSS_DOCUMENT]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Odpočet nedaňové zálohy, který POHODA zaúčtovala kladně 311/602: faktura zní na
     * 1 210 Kč, zálohou je uhrazená, ale 311 z ní v deníku POHODY drží 2 420 Kč. Převod
     * doklad i deník převzal věrně (předvaha sedí), rekonciliace proto rozdíl vysvětlí jako
     * rozdíl, který je už v POHODĚ, a převod neshodí. Stalo se u reálné agendy (-314 114 Kč).
     */
    public function testAdvanceDeductionBookedOnReceivableInPohodaIsExplainedAsSourceDifference(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, false, false, true));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $reconciliation = $protocol->get('reconciliation')[0];
        self::assertTrue($reconciliation['ok'], json_encode($reconciliation, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $reconciliation['journal_diffs']);
        $issued = array_column($reconciliation['documents'], null, 'key')['issued_invoices'];
        self::assertEqualsWithDelta(2420.0, $issued['documents'], 0.005);
        self::assertEqualsWithDelta(3630.0, $issued['journal'], 0.005);
        self::assertSame([['document_no' => SyntheticPohodaExport::ADVANCE_DOCUMENT, 'difference' => -1210.0, 'reason' => 'advance_deduction']], $issued['source_differences'] ?? null);
        self::assertContains('source_difference', $this->messageCodes($protocol));

        // Doklad sám je převedený správně: zní na 1 210 Kč a je uhrazený zálohou.
        self::assertSame(1, $this->rows('invoices', $supplierId, sprintf(
            "varsymbol = '%s' AND total_with_vat = 1210.00 AND advance_paid_amount = 1210.00 AND status = 'paid'", SyntheticPohodaExport::ADVANCE_DOCUMENT)));

        // Rozdíl, který v POHODĚ není (doklad v MyÚčtu zní na jinou částku), zůstává chybou.
        $this->db->pdo()->prepare('UPDATE invoices SET total_with_vat = 1000.00 WHERE supplier_id = ? AND varsymbol = ?')
            ->execute([$supplierId, SyntheticPohodaExport::ADVANCE_DOCUMENT]);
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        $reconciliation = $again->get('reconciliation')[0];
        self::assertFalse($reconciliation['ok'], json_encode($reconciliation, JSON_UNESCAPED_UNICODE));
        $issued = array_column($reconciliation['documents'], null, 'key')['issued_invoices'];
        self::assertFalse($issued['ok']);
        self::assertArrayNotHasKey('source_differences', $issued);
    }

    /**
     * Rozdíl saldokonta proti hlavní knize je upozornění: přeplatek bez dokladu drží na 311
     * částku, ke které není otevřená položka. Hlásí se i při zkoušce nanečisto, stav převodu
     * ani kontroly rekonciliace nemění. Kontrola běží k poslednímu zápisu v deníku, ne ke konci roku.
     */
    public function testSaldoDifferenceIsWarningThatDoesNotFailTheConversion(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOverpaymentWithoutDocument($dir, 5000.0);
        $export = PohodaExport::open($dir);

        $dry = $this->importer->run($supplierId, $this->userId, $export, true);
        $live = $this->importer->run($supplierId, $this->userId, $export, false);
        $asOf = $this->lastJournalDate($supplierId);
        self::assertLessThan(SyntheticPohodaExport::YEAR . '-12-31', $asOf);

        foreach ([$dry, $live] as $protocol) {
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            self::assertSame('completed_with_warnings', $protocol->status(), $this->explain($protocol));
            $reconciliation = $protocol->get('reconciliation')[0];
            self::assertTrue($reconciliation['ok'], json_encode($reconciliation, JSON_UNESCAPED_UNICODE));

            self::assertCount(1, $reconciliation['saldo']);
            $saldo = $reconciliation['saldo'][0];
            self::assertSame(SyntheticPohodaExport::YEAR, $saldo['year']);
            self::assertSame($reconciliation['period_id'], $saldo['period_id']);
            self::assertSame($asOf, $saldo['as_of']);
            self::assertSame('differences', $saldo['status']);
            self::assertSame(['311'], array_column($saldo['differences'], 'account'));
            self::assertSame(-5000.0, $saldo['differences'][0]['gl_balance']);
            self::assertSame(0.0, $saldo['differences'][0]['open_items_total']);
            self::assertSame(-5000.0, $saldo['differences'][0]['difference']);

            $warnings = $this->messages($protocol, 'saldo_difference');
            self::assertCount(1, $warnings, $this->explain($protocol));
            self::assertSame('warning', $warnings[0]['level']);
            self::assertSame('311', $warnings[0]['context']['account']);
            self::assertStringContainsString('účet 311', $warnings[0]['text']);
            self::assertStringContainsString('zůstatek hlavní knihy -5 000,00 Kč, součet položek 0,00 Kč, rozdíl -5 000,00 Kč', $warnings[0]['text']);
        }
    }

    /** Saldokonto, které na hlavní knihu sedí, protokol neplní a rekonciliace ho vykáže jako v pořádku. */
    public function testMatchingSaldoIsNotReported(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([], $this->messages($protocol, 'saldo_difference'), $this->explain($protocol));
        $saldo = $protocol->get('reconciliation')[0]['saldo'];
        self::assertCount(1, $saldo);
        self::assertSame('ok', $saldo[0]['status']);
        self::assertSame([], $saldo[0]['differences']);
    }

    /** Tolerance je koruna na účet včetně; o haléř víc už je rozdíl. */
    public function testSaldoDifferenceToleranceIsOneCrownPerAccount(): void
    {
        $supplierId = $this->supplier();
        foreach ([[1.0, 0], [1.01, 1]] as $i => [$amount, $warnings]) {
            $dir = SyntheticPohodaExport::write($this->tmp . '/' . $i);
            SyntheticPohodaExport::withOverpaymentWithoutDocument($dir, $amount);
            $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), true);
            self::assertCount($warnings, $this->messages($protocol, 'saldo_difference'), $this->explain($protocol));
        }
    }

    /**
     * Sestava přes strop otevřených položek převod neshodí a skončí jako informace. Sestava
     * běží jednou za každé období převodu, i nanečisto: skutečný {@see SaldoService} se tu
     * zastaví na prvním účtu, takže počet dotazů na účet je počet sestav.
     */
    public function testUnavailableSaldoIsOnlyInformationAndRunsOncePerPeriod(): void
    {
        $repository = $this->createMock(SaldoRepository::class);
        $repository->expects(self::exactly(2))->method('resolveAccount')
            ->willReturn(['id' => 1, 'code' => '311', 'name' => 'Odběratelé', 'normal_side' => 'debit', 'account_type' => 'asset']);
        $repository->method('openItems')->willReturn(array_fill(0, SaldoService::MAX_OPEN_ITEMS + 1, [
            'partner_id' => 1, 'partner_name' => 'Odběratel', 'booked_signed' => 100.0, 'foreign_signed' => 0.0, 'paid_ratio' => 0.0,
            'doc_type' => 'invoice', 'doc_id' => 1, 'doc_no' => '26FV0001', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15', 'currency_code' => 'CZK',
        ]));
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        $container->set(SaldoRepository::class, $repository);
        $importer = $container->get(PohodaImporter::class);

        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, unbooked: true));
        $protocol = $importer->run($supplierId, $this->userId, $export, true);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNotContains('saldo_difference', $this->messageCodes($protocol));
        $messages = $this->messages($protocol, 'saldo_unavailable');
        self::assertCount(2, $messages, $this->explain($protocol));
        self::assertSame(['info', 'info'], array_column($messages, 'level'));
        self::assertStringContainsString('otevřených položek', $messages[0]['text']);
        $years = $protocol->get('reconciliation')[0]['saldo'];
        self::assertSame([SyntheticPohodaExport::YEAR, SyntheticPohodaExport::NEXT_YEAR], array_column($years, 'year'));
        self::assertSame(['unavailable', 'unavailable'], array_column($years, 'status'));
    }

    /**
     * Doklad převzatý v cizí měně nese částky v EUR a kurz. Všechno, co převod kontroluje
     * nebo páruje v Kč (doklady proti deníku 311/321, změna dokladu v POHODĚ, doplnění
     * úhrady, párování nezaúčtované platby podle částky), ho musí přepočítat kurzem
     * dokladu - jinak by rekonciliace nesouhlasila, opakovaný převod hlásil změnu a platba
     * v Kč by se s fakturou nespárovala.
     */
    public function testForeignCurrencyDocumentsAreComparedAndPairedInCrowns(): void
    {
        $supplierId = $this->supplier();
        $eur = $this->currency($supplierId, 'EUR');
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withForeignCurrencyDocuments($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        // Doklady v EUR tak, jak je převezme převod v měně (idempotentní i po zapnutí převzetí).
        $pdo = $this->db->pdo();
        foreach ([['invoices', 'invoice_items', 'invoice_id', SyntheticPohodaExport::FOREIGN_ISSUED, [[100.0, 21.0, 50.0], [40.0, 8.4, 40.0]]],
            ['purchase_invoices', 'purchase_invoice_items', 'purchase_invoice_id', SyntheticPohodaExport::FOREIGN_PURCHASE, [[200.0, 42.0, 200.0]]]] as [$table, $itemsTable, $fk, $number, $items]) {
            $id = $this->documentId($table, $supplierId, $number);
            $base = array_sum(array_column($items, 0));
            $vat = array_sum(array_column($items, 1));
            $pdo->prepare("UPDATE {$table} SET currency_id = ?, exchange_rate = ?, total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = 0"
                . ($table === 'invoices' ? ', paid_total = IF(paid_total = 0, 0, ?)' : '') . ' WHERE id = ?')
                ->execute(array_merge([$eur, SyntheticPohodaExport::FOREIGN_RATE, $base, $vat, $base + $vat], $table === 'invoices' ? [$base + $vat] : [], [$id]));
            foreach ($items as $i => [$itemBase, $itemVat, $unit]) {
                $pdo->prepare("UPDATE {$itemsTable} SET unit_price_without_vat = ?, total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE {$fk} = ? AND order_index = ?")
                    ->execute([$unit, $itemBase, $itemVat, $itemBase + $itemVat, $id, $i]);
            }
        }

        SyntheticPohodaExport::withForeignCurrencyReceipt($dir);
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertNotContains('changed_in_pohoda', $this->messageCodes($again), $this->explain($again));
        $reconciliation = $again->get('reconciliation')[0];
        self::assertTrue($reconciliation['ok'], json_encode($reconciliation, JSON_UNESCAPED_UNICODE));
        $documents = array_column($reconciliation['documents'], null, 'key');
        self::assertEqualsWithDelta($documents['issued_invoices']['journal'], $documents['issued_invoices']['documents'], 0.005);
        self::assertEqualsWithDelta($documents['purchase_invoices']['journal'], $documents['purchase_invoices']['documents'], 0.005);

        // Nezaúčtovaný příjem 4 255,33 Kč s VS faktury: spárovaný podle částky v Kč, platba
        // evidovaná v měně faktury, úhrada zaúčtovaná v Kč bez kurzového rozdílu.
        self::assertSame(['auto_exact', null, 1], $this->txState($supplierId, SyntheticPohodaExport::FOREIGN_RECEIPT), $this->explain($again));
        self::assertSame(1, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND status = 'paid' AND paid_total = 169.40", SyntheticPohodaExport::FOREIGN_ISSUED)));
        self::assertSame(1, $this->rows('invoice_payments', $supplierId, "amount = 169.40 AND currency = 'EUR' AND source = 'bank'"));
        self::assertSame([['debit', '221.001', '4255.33'], ['credit', '311.001', '4255.33']], $this->bankEntryLines($supplierId, SyntheticPohodaExport::FOREIGN_RECEIPT));
    }

    /**
     * Doklad v EUR se převezme v měně a kurzu z POHODY, jen když částky v EUR kurzem dají
     * přesně Kč z POHODY - DPH, KH i deník pak zůstanou na haléř stejné jako převzetí v Kč.
     * Firma B dostane týž export bez částek v cizí měně (převzetí v Kč, jak to bylo dřív)
     * a přiznání i KH obou firem se musí shodovat.
     */
    public function testForeignCurrencyDocumentsAreTakenOverInTheirCurrencyWithIdenticalVatReturn(): void
    {
        $container = Bootstrap::buildContainer();
        $foreign = $this->supplier();
        $this->currency($foreign, 'EUR');
        $dirA = SyntheticPohodaExport::write($this->tmp . '/a');
        SyntheticPohodaExport::withForeignCurrencyDocuments($dirA);
        $protocol = $this->importer->run($foreign, $this->userId, PohodaExport::open($dirA), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertTrue($protocol->get('reconciliation')[0]['ok'], json_encode($protocol->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));

        $home = $this->supplier();
        $this->currency($home, 'EUR');
        $dirB = SyntheticPohodaExport::write($this->tmp . '/b');
        SyntheticPohodaExport::withForeignCurrencyDocuments($dirB);
        SyntheticPohodaExport::withoutForeignAmounts($dirB);
        $inCrowns = $this->importer->run($home, $this->userId, PohodaExport::open($dirB), false);
        self::assertFalse($inCrowns->hasErrors(), $this->explain($inCrowns));

        $doc = static fn (Connection $db, string $table, int $supplierId, string $number): array => (function () use ($db, $table, $supplierId, $number): array {
            $stmt = $db->pdo()->prepare("SELECT c.code, d.exchange_rate, d.total_without_vat, d.total_vat, d.total_with_vat, d.rounding, d.note_below_items
                FROM {$table} d JOIN currencies c ON c.id = d.currency_id WHERE d.supplier_id = ? AND d.varsymbol = ?");
            $stmt->execute([$supplierId, $number]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        })();
        $issued = $doc($this->db, 'invoices', $foreign, SyntheticPohodaExport::FOREIGN_ISSUED);
        self::assertSame(['EUR', '25.120000', '140.00', '29.40', '169.40', '0.00'], array_slice(array_values($issued), 0, 6));
        self::assertStringContainsString('doklad v EUR, převzat v měně dokladu kurzem 25,12 Kč', $issued['note_below_items']);
        $items = $this->db->pdo()->prepare('SELECT unit_price_without_vat, total_without_vat, total_vat FROM invoice_items WHERE invoice_id = (SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?) ORDER BY order_index');
        $items->execute([$foreign, SyntheticPohodaExport::FOREIGN_ISSUED]);
        self::assertSame([['50.000000', '100.00', '21.00'], ['40.000000', '40.00', '8.40']], $items->fetchAll(\PDO::FETCH_NUM));
        $purchase = $doc($this->db, 'purchase_invoices', $foreign, SyntheticPohodaExport::FOREIGN_PURCHASE);
        self::assertSame(['EUR', '25.120000', '200.00', '42.00', '242.00', '0.00'], array_slice(array_values($purchase), 0, 6));
        // Daň v Kč nevychází přepočtem daně v EUR - doklad zůstává v Kč a poznámka říká proč.
        $mismatch = $doc($this->db, 'invoices', $foreign, SyntheticPohodaExport::FOREIGN_MISMATCH);
        self::assertSame(['CZK', null, '251.20', '52.76', '303.96'], array_slice(array_values($mismatch), 0, 5));
        self::assertStringContainsString('doklad v EUR, převzat v Kč (daň 1. položky 2,10 × kurz 25,12 nedává 52,76 Kč ze zdroje)', $mismatch['note_below_items']);
        self::assertSame([1, 1], [self::stepCounts($protocol, 'issued_invoices')['foreign_currency'] ?? 0, self::stepCounts($protocol, 'issued_invoices')['foreign_currency_in_home'] ?? 0]);
        self::assertSame(1, self::stepCounts($protocol, 'purchase_invoices')['foreign_currency'] ?? 0);
        self::assertContains('foreign_currency_in_home', $this->messageCodes($protocol));

        // Bez částek v měně jsou všechny tři v Kč jako dřív.
        self::assertSame('CZK', $doc($this->db, 'invoices', $home, SyntheticPohodaExport::FOREIGN_ISSUED)['code']);

        // Přiznání DPH a KH za únor (všechny tři doklady) na haléř stejné jako převzetí v Kč.
        $dph = $container->get(\MyInvoice\Service\Report\DphPriznaniBuilder::class);
        $kh = $container->get(\MyInvoice\Service\Report\KontrolniHlaseniBuilder::class);
        $returnA = $dph->build($foreign, SyntheticPohodaExport::YEAR, 2, 'monthly');
        $returnB = $dph->build($home, SyntheticPohodaExport::YEAR, 2, 'monthly');
        self::assertEqualsWithDelta(3768.0, (float) $returnB['summary']['lines']['1']['base'], 0.005, 'Kontrola fixture: 3 516,80 + 251,20 Kč na ř. 1.');
        self::assertSame($returnB['summary']['lines'], $returnA['summary']['lines']);
        $khA = $kh->build($foreign, SyntheticPohodaExport::YEAR, 2);
        $khB = $kh->build($home, SyntheticPohodaExport::YEAR, 2);
        self::assertSame($khB['summary'], $khA['summary']);
        $sections = static fn (string $xml): array => preg_match_all('~<Veta[ABC][^>]*/>~', $xml, $m) > 0 ? $m[0] : [];
        self::assertNotSame([], $sections($khB['xml']));
        self::assertSame($sections($khB['xml']), $sections($khA['xml']));

        // Deník převzatý z POHODY se nemění.
        $journal = fn (int $supplierId): array => $this->journalTotals($supplierId);
        self::assertSame($journal($home), $journal($foreign));
    }

    /** @return array<string,string> účet => obrat MD - D převedeného deníku firmy */
    private function journalTotals(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? GROUP BY a.account_code ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function documentId(string $table, int $supplierId, string $number): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND varsymbol = ?");
        $stmt->execute([$supplierId, $number]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, $number);
        return $id;
    }

    private function currency(int $supplierId, string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0)"
        )->execute([$supplierId, $code, $code, $code, $code, $code]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array{level:string,code:string,text:string,context:array<string,mixed>}> */
    private function messages(ImportProtocol $protocol, string $code): array
    {
        $out = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                if ($m['code'] === $code) {
                    $out[] = $m;
                }
            }
        }

        return $out;
    }

    private function lastJournalDate(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT MAX(entry_date) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        return substr((string) $stmt->fetchColumn(), 0, 10);
    }

    /** @return list<string> */
    private function messageCodes(ImportProtocol $protocol): array
    {
        $codes = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }

        return $codes;
    }

    /** Doklad OSS i s položkou a sazbou, na kterou se navázal. @return ?array<string,mixed> */
    private function ossDocument(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.status, i.vat_classification_code, it.oss_applicable, it.oss_consumer_country, it.oss_rate_type,
                    it.oss_supply_type, it.oss_needs_manual_review, it.vat_rate_snapshot, r.country AS rate_country,
                    it.total_without_vat, it.total_vat, it.oss_taxable_amount_return, it.oss_vat_amount_return
               FROM invoices i
               JOIN invoice_items it ON it.invoice_id = i.id
               JOIN vat_rates r ON r.id = it.vat_rate_id
              WHERE i.supplier_id = ? AND i.varsymbol = ?'
        );
        $stmt->execute([$supplierId, SyntheticPohodaExport::OSS_DOCUMENT]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function enableOss(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET oss_enabled = 1, oss_identification_country = 'CZ', oss_return_currency = 'EUR',
                    oss_valid_from = '2025-01-01', oss_valid_to = NULL WHERE id = ?"
        )->execute([$supplierId]);
    }

    /** Sazba státu spotřeby v číselníku DPH sazeb - bez ní se řádek nemá na co navázat. */
    private function foreignRate(string $country, float $rate): void
    {
        $this->rate($country . '-' . (int) $rate, $rate, $country);
    }

    /** Táž sazba omylem založená jako tuzemská - nejčastější chyba při zakládání sazeb. */
    private function domesticRate(float $rate): void
    {
        $this->rate('CZ-' . (int) $rate, $rate, 'CZ');
    }

    private function rate(string $code, float $rate, string $country): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, "2025-01-01", NULL, 900)'
        )->execute([$code, $rate, $country, $code, $code]);
    }

    /** Text hlášky „doklad převzat jako koncept k ruční kontrole". */
    private function reviewMessage(ImportProtocol $protocol): ?string
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                if ($m['code'] === 'needs_review') {
                    return (string) $m['text'];
                }
            }
        }

        return null;
    }

    /**
     * Platba, kterou POHODA nezaúčtovala (předkontace „Nevím") a kterou převod neumí
     * jednoznačně přiřadit (platba kartou), není vyřízená - zůstane nespárovaná. Dřívější
     * převod ji chybně označil jako vyřízenou; opakovaný převod ji vrátí k párování.
     */
    public function testUnbookedBankPaymentStaysOpenForMatching(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, unbooked: true));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['unmatched', null, 0], $this->txState($supplierId, 'BAN0010015'));
        self::assertContains('unbooked_bank', $this->messageCodes($protocol));

        $this->db->pdo()->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'ignored', t.match_reason = 'pohoda_booked', t.ignore_note = 'Zaúčtováno v Pohodě bez dokladu (převod z POHODY).'
              WHERE s.supplier_id = ? AND t.bank_ref = 'BAN0010015'"
        )->execute([$supplierId]);
        $before = $this->snapshot($supplierId);

        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(['unmatched', null, 0], $this->txState($supplierId, 'BAN0010015'));
        self::assertSame(1, self::stepCounts($again, 'payments')['unbooked_released'] ?? 0, $this->explain($again));
        self::assertSame($before, $this->snapshot($supplierId));
    }

    /**
     * Pohyby bez zápisu v deníku POHODY: jednoznačná úhrada (párovací symbol, VS + částka,
     * účet dodavatele + částka) se spáruje s fakturou a rovnou zaúčtuje jako bankovní zápis
     * 321/221 resp. 221/311 v období podle data pohybu. Nejednoznačná shoda je jen návrh
     * párování, platba kartou zůstává na Doúčtování. Opakovaný převod nic nezdvojí a zápisy
     * převodu nejsou pro kontrolu před převodem „cizí".
     */
    public function testUnbookedPaymentsArePairedAndPostedOnlyWhenUnambiguous(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, laterPayment: SyntheticPohodaExport::LATER_OPEN, unbooked: true));

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $payments = self::stepCounts($protocol, 'payments');
        self::assertSame([2, 1, 1, 1, 4], [$payments['unbooked_matched_vs'] ?? 0, $payments['unbooked_matched_account'] ?? 0,
            $payments['unbooked_matched_parsym'] ?? 0, $payments['unbooked_suggested'] ?? 0, $payments['unbooked_posted'] ?? 0], $this->explain($protocol));

        // VS + částka: přijatá faktura uhrazená a úhrada zaúčtovaná 321/221 k datu pohybu.
        self::assertSame(['auto_exact', null, 1], $this->txState($supplierId, SyntheticPohodaExport::LATER_PAID_BANK));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "vendor_invoice_number = 'D-2026-9' AND status = 'paid' AND paid_at = '2026-01-28'"));
        self::assertSame([['debit', '321.001', '726.00'], ['credit', '221.001', '726.00']], $this->bankEntryLines($supplierId, SyntheticPohodaExport::LATER_PAID_BANK));
        // Účet dodavatele + částka a párovací symbol pohybu.
        self::assertSame(['auto_exact', null, 1], $this->txState($supplierId, 'BAN0010013'));
        self::assertSame(['auto_exact', null, 1], $this->txState($supplierId, 'BAN0010014'));
        foreach ([SyntheticPohodaExport::ACCOUNT_PURCHASE, SyntheticPohodaExport::PARSYM_PURCHASE] as $number) {
            self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "varsymbol = '{$number}' AND status = 'paid'"), $number);
        }
        // Vydaná faktura uhrazená příjmem v následujícím roce: evidovaná platba, 221/311 v jeho období.
        self::assertSame(['auto_exact', null, 1], $this->txState($supplierId, 'BAN0010011'));
        self::assertSame(1, $this->rows('invoices', $supplierId, sprintf("varsymbol = '%s' AND status = 'paid' AND paid_total = 1815.00", SyntheticPohodaExport::UNBOOKED_ISSUED)));
        self::assertSame(1, $this->rows('invoice_payments', $supplierId, "amount = 1815.00 AND source = 'bank' AND bank_transaction_id IS NOT NULL"));
        self::assertSame([['debit', '221.001', '1815.00'], ['credit', '311.001', '1815.00']], $this->bankEntryLines($supplierId, 'BAN0010011'));
        self::assertSame(SyntheticPohodaExport::NEXT_YEAR, $this->bankEntryYear($supplierId, 'BAN0010011'));

        // Dvě stejné faktury téhož dodavatele: jen návrh s oběma kandidáty, nic uhrazeno.
        self::assertSame(['unmatched', null, 0], $this->txState($supplierId, 'BAN0010012'));
        $suggestion = $this->db->pdo()->prepare(
            "SELECT b.status, b.reason, JSON_LENGTH(b.candidates_json) FROM bank_match_suggestions b
               JOIN bank_transactions t ON t.id = b.bank_transaction_id JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.bank_ref = 'BAN0010012'"
        );
        $suggestion->execute([$supplierId]);
        self::assertSame([['pending', 'ambiguous_amount_date_match', 2]], array_map(
            static fn (array $r): array => [$r[0], $r[1], (int) $r[2]], $suggestion->fetchAll(\PDO::FETCH_NUM)));
        self::assertSame(2, $this->rows('purchase_invoices', $supplierId, sprintf("varsymbol IN ('%s') AND status = 'booked'", implode("','", SyntheticPohodaExport::AMBIGUOUS_PURCHASES))));
        // Platba kartou: bez párování, bez návrhu, bez zápisu - ani když VS a částka sedí na fakturu.
        self::assertSame(['unmatched', null, 0], $this->txState($supplierId, 'BAN0010015'));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, sprintf("varsymbol = '%s' AND status = 'booked'", SyntheticPohodaExport::CARD_PURCHASE)));
        self::assertSame(0, $this->rows('bank_match_suggestions', $supplierId, "bank_transaction_id = (SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = bank_match_suggestions.supplier_id AND t.bank_ref = 'BAN0010015')"));

        $reconciliation = $protocol->get('reconciliation')[0];
        self::assertTrue($reconciliation['ok'], json_encode($reconciliation, JSON_UNESCAPED_UNICODE));
        self::assertSame(4, $reconciliation['derived_payments']['entries']);
        $map = (new PohodaImportRepository($this->db))->counts($supplierId);
        self::assertSame([4, 4], [$map[PohodaImportRepository::KIND_DERIVED_MATCH] ?? 0, $map[PohodaImportRepository::KIND_DERIVED_ENTRY] ?? 0]);

        // Opakovaný převod: kontrola před převodem zápisy převodu nepočítá jako cizí a nic se nezdvojí.
        $before = $this->snapshot($supplierId) + ['suggestions' => $this->rows('bank_match_suggestions', $supplierId), 'invoice_payments' => $this->rows('invoice_payments', $supplierId)];
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertNotContains('journal_not_empty', array_column((array) $again->get('preflight'), 'code'));
        self::assertSame($before, $this->snapshot($supplierId) + ['suggestions' => $this->rows('bank_match_suggestions', $supplierId), 'invoice_payments' => $this->rows('invoice_payments', $supplierId)]);

        // Pohyb, který uživatel spároval sám (bez zaúčtování), převod nezaúčtuje - to je jeho krok.
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             SELECT s.supplier_id, t.id, pi.id, 363.00, 'manual', ?
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
               JOIN purchase_invoices pi ON pi.supplier_id = s.supplier_id AND pi.varsymbol = ?
              WHERE s.supplier_id = ? AND t.bank_ref = 'BAN0010012'"
        )->execute([$this->userId, SyntheticPohodaExport::AMBIGUOUS_PURCHASES[0], $supplierId]);
        $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id SET t.match_status = 'manual'
              WHERE s.supplier_id = ? AND t.bank_ref = 'BAN0010012'"
        )->execute([$supplierId]);
        $third = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($third->hasErrors(), $this->explain($third));
        self::assertSame(['manual', null, 0], $this->txState($supplierId, 'BAN0010012'));
    }

    /**
     * Opakovaný převod novějšího exportu, ve kterém účetní platbu v POHODĚ mezitím zaúčtovala
     * a zlikvidovala: odvozený zápis úhrady se stornuje a pohyb nese zápis z deníku POHODY,
     * párování s fakturou zůstává jedno. Vydaná faktura doplacená bez pohybu v bance je uhrazená
     * podle likvidace. Další běh už nic nemění.
     */
    public function testReimportReplacesDerivedPaymentWhenPohodaBooksIt(): void
    {
        $supplierId = $this->supplier();
        $open = $this->importer->run($supplierId, $this->userId,
            PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/open', laterPayment: SyntheticPohodaExport::LATER_OPEN)), false);
        self::assertFalse($open->hasErrors(), $this->explain($open));
        self::assertSame(['auto_exact', null, 1], $this->txState($supplierId, SyntheticPohodaExport::LATER_PAID_BANK));
        $derived = $this->bankEntryId($supplierId, SyntheticPohodaExport::LATER_PAID_BANK);
        $before = $this->snapshot($supplierId);
        $settledExport = PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/settled', laterPayment: SyntheticPohodaExport::LATER_SETTLED));

        $settled = $this->importer->run($supplierId, $this->userId, $settledExport, false);
        self::assertFalse($settled->hasErrors(), $this->explain($settled));
        $after = $this->snapshot($supplierId);
        foreach (['purchase_invoices', 'invoices', 'clients', 'bank_statements', 'chart_of_accounts', 'payment_matches'] as $t) {
            self::assertSame($before[$t], $after[$t], $t);
        }
        // Zápis z deníku POHODY + storno odvozeného zápisu.
        self::assertSame($before['journal_entries'] + 2, $after['journal_entries']);
        self::assertSame(1, self::stepCounts($settled, 'link')['superseded'] ?? 0, $this->explain($settled));
        self::assertSame(1, self::stepCounts($settled, 'payments')['already_matched'] ?? 0, $this->explain($settled));
        $state = $this->db->pdo()->prepare('SELECT source_id IS NULL, reversed_by IS NOT NULL FROM journal_entries WHERE id = ?');
        $state->execute([$derived]);
        self::assertSame([1, 1], array_map('intval', $state->fetch(\PDO::FETCH_NUM)));
        $pohoda = $this->bankEntryId($supplierId, SyntheticPohodaExport::LATER_PAID_BANK);
        self::assertNotSame($derived, $pohoda);
        self::assertSame(1, $this->rows('journal_entry_document_links', $supplierId, "entry_id = {$pohoda} AND doc_type = 'bank'"));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "vendor_invoice_number = 'D-2026-9' AND status = 'paid' AND paid_at = '2026-01-28'"));
        self::assertSame(1, $this->rows('invoices', $supplierId, "issue_date = '2025-12-20' AND status = 'paid' AND paid_total = 500.00 AND paid_at = '2026-01-05'"));
        self::assertTrue($settled->get('reconciliation')[0]['ok'], json_encode($settled->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));

        $repeat = $this->importer->run($supplierId, $this->userId, $settledExport, false);
        self::assertFalse($repeat->hasErrors(), $this->explain($repeat));
        self::assertSame($after, $this->snapshot($supplierId));
    }

    /** Zkouška nanečisto s odvozenými úhradami a obdobím následujícího roku po sobě nic nenechá. */
    public function testDryRunWithUnbookedPaymentsLeavesNothingBehind(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshot($supplierId) + ['suggestions' => $this->rows('bank_match_suggestions', $supplierId)];
        $protocol = $this->importer->run($supplierId, $this->userId,
            PohodaExport::open(SyntheticPohodaExport::write($this->tmp, laterPayment: SyntheticPohodaExport::LATER_OPEN, unbooked: true)), true);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(4, self::stepCounts($protocol, 'payments')['unbooked_posted'] ?? 0, $this->explain($protocol));
        self::assertSame($before, $this->snapshot($supplierId) + ['suggestions' => $this->rows('bank_match_suggestions', $supplierId)]);
    }

    /**
     * Pohyb bez zápisu dostane návrh zaúčtování jen tam, kde ho podloží aspoň 3 shodné pohyby
     * téhož protiúčtu a směru a aspoň 90 % shoda (9 z 10 ano, 3 ze 4 ne). Nic se nezaúčtuje,
     * ani po obnovení automatiky a ani u pravidla s pěknou historií. Zkouška nanečisto po sobě
     * nic nenechá, opakovaný převod pravidla ani návrhy nezdvojí a odmítnutý návrh nevrátí.
     * Schválení návrhu zaúčtuje pohyb na kontaci z historie a analytiku účtu výpisu.
     */
    public function testUnbookedPaymentsGetPostingProposalsOnlyWithConsistentHistory(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withBookedBankHistory($dir);
        $export = PohodaExport::open($dir);

        $dry = $this->importer->run($supplierId, $this->userId, $export, true);
        self::assertFalse($dry->hasErrors(), $this->explain($dry));
        self::assertSame(3, self::stepCounts($dry, 'payments')['unbooked_proposed'] ?? 0, $this->explain($dry));
        self::assertSame([0, 0], [$this->rows('bank_posting_rules', $supplierId), $this->rows('bank_posting_suggestions', $supplierId)]);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $payments = self::stepCounts($protocol, 'payments');
        self::assertSame([3, 3, 2, 1, 1], [
            $payments['unbooked_proposed'] ?? 0, $payments['unbooked_proposal_rules'] ?? 0, $payments['unbooked_proposal_no_history'] ?? 0,
            $payments['unbooked_proposal_inconsistent'] ?? 0, $payments['unbooked_proposal_saldo'] ?? 0,
        ], $this->explain($protocol));
        self::assertContains('unbooked_proposals', $this->messageCodes($protocol));

        self::assertSame(['518.000', '221', 'suggest'], $this->postingProposal($supplierId, SyntheticPohodaExport::PROPOSAL_BY_ACCOUNT));
        self::assertSame(['568.000', '221', 'suggest'], $this->postingProposal($supplierId, SyntheticPohodaExport::PROPOSAL_BY_NAME));
        self::assertSame(['518.000', '221', 'suggest'], $this->postingProposal($supplierId, SyntheticPohodaExport::PROPOSAL_AT_THRESHOLD));
        foreach ([SyntheticPohodaExport::NO_PROPOSAL_INCONSISTENT, SyntheticPohodaExport::NO_PROPOSAL_TOO_FEW,
            SyntheticPohodaExport::NO_PROPOSAL_SALDO, SyntheticPohodaExport::NO_PROPOSAL_OTHER_DIRECTION] as $ref) {
            self::assertNull($this->postingProposal($supplierId, $ref), $ref);
        }
        self::assertSame(1, $this->rows('bank_posting_rules', $supplierId,
            sprintf("counterparty_account = '%s' AND message_contains IS NULL AND amount_min IS NULL AND direction = 'outgoing'", SyntheticPohodaExport::PROPOSAL_ACCOUNT)));
        self::assertSame(1, $this->rows('bank_posting_rules', $supplierId, "counterparty_account IS NULL AND message_contains = 'postovne test'"));
        self::assertSame(3, (new PohodaImportRepository($this->db))->counts($supplierId)[PohodaImportRepository::KIND_BANK_PROPOSAL_RULE] ?? 0);

        // Nic se nezaúčtovalo a ani po obnovení plné automatiky se nezaúčtuje: pravidlo zůstává
        // návrhem, i kdyby už mělo historii použití a rozsah částky.
        foreach ([SyntheticPohodaExport::PROPOSAL_BY_ACCOUNT, SyntheticPohodaExport::PROPOSAL_BY_NAME, SyntheticPohodaExport::PROPOSAL_AT_THRESHOLD] as $ref) {
            self::assertSame(['unmatched', null, 0], $this->txState($supplierId, $ref), $ref);
        }
        $level = $this->db->pdo()->prepare('SELECT level FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = ?');
        $level->execute([$supplierId, OperationType::BANK_RULE_CUSTOM]);
        self::assertSame('auto', $level->fetchColumn());
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET hit_count = 10, amount_min = 1, amount_max = 100000 WHERE supplier_id = ?')->execute([$supplierId]);
        $suggestions = $this->rows('bank_posting_suggestions', $supplierId);
        $res = $this->bankPosting->handleTransaction($this->bankTxId($supplierId, SyntheticPohodaExport::PROPOSAL_BY_ACCOUNT), $this->userId);
        self::assertSame('suggested', $res['action'], (string) json_encode($res));
        self::assertSame(['unmatched', null, 0], $this->txState($supplierId, SyntheticPohodaExport::PROPOSAL_BY_ACCOUNT));
        self::assertSame($suggestions, $this->rows('bank_posting_suggestions', $supplierId));
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET hit_count = 0, amount_min = NULL, amount_max = NULL WHERE supplier_id = ?')->execute([$supplierId]);

        // Opakovaný převod nezdvojí pravidla ani návrhy.
        $state = fn (): array => $this->snapshot($supplierId) + [
            'rules' => $this->rows('bank_posting_rules', $supplierId),
            'suggestions' => $this->rows('bank_posting_suggestions', $supplierId),
        ];
        $before = $state();
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $state());
        self::assertSame(0, self::stepCounts($again, 'payments')['unbooked_proposed'] ?? 0, $this->explain($again));
        self::assertContains('unbooked_proposals', $this->messageCodes($again));

        // Odmítnutý návrh se znovu nenabízí, smazané pravidlo se nevrací.
        $this->bankPosting->rejectSuggestion($supplierId, $this->pendingSuggestionId($supplierId, SyntheticPohodaExport::PROPOSAL_BY_NAME), ['user_id' => $this->userId]);
        $this->db->pdo()->prepare('DELETE FROM bank_posting_suggestions WHERE supplier_id = ? AND rule_id IN (SELECT id FROM bank_posting_rules WHERE supplier_id = ? AND counterparty_account = ?)')
            ->execute([$supplierId, $supplierId, '7000000010']);
        $this->db->pdo()->prepare('DELETE FROM bank_posting_rules WHERE supplier_id = ? AND counterparty_account = ?')->execute([$supplierId, '7000000010']);
        $third = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($third->hasErrors(), $this->explain($third));
        self::assertNull($this->postingProposal($supplierId, SyntheticPohodaExport::PROPOSAL_BY_NAME));
        self::assertNull($this->postingProposal($supplierId, SyntheticPohodaExport::PROPOSAL_AT_THRESHOLD));
        self::assertSame(1, self::stepCounts($third, 'payments')['unbooked_proposal_rule_changed'] ?? 0, $this->explain($third));

        // Schválení účtuje stejnou cestou jako tlačítko v UI: kontace z historie, analytika účtu výpisu.
        $this->bankPosting->approveSuggestion($supplierId, $this->pendingSuggestionId($supplierId, SyntheticPohodaExport::PROPOSAL_BY_ACCOUNT), ['user_id' => $this->userId]);
        self::assertSame([['debit', '518.000', '1500.00'], ['credit', '221.001', '1500.00']], $this->bankEntryLines($supplierId, SyntheticPohodaExport::PROPOSAL_BY_ACCOUNT));
    }

    /**
     * Agenda vede i doklady následujícího roku. Jejich zápisy jdou do účetního období podle
     * skutečného data (období se založí otevřené), rok agendy zůstává neuzavřený. Zápis, který
     * dřívější převod posunul k 31. 12., opakovaný převod přesune do jeho období. Cizí zápis
     * v období následujícího roku převod zastaví stejně jako v roce agendy.
     */
    public function testNextYearDocumentsGoToTheirOwnPeriod(): void
    {
        $supplierId = $this->supplier();
        $export = PohodaExport::open(SyntheticPohodaExport::write($this->tmp, unbooked: true));
        $next = SyntheticPohodaExport::NEXT_YEAR;

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertContains('later_periods', array_column((array) $protocol->get('preflight'), 'code'));
        self::assertContains('later_period', $this->messageCodes($protocol));
        $periods = $this->db->pdo()->prepare('SELECT fiscal_year, status FROM accounting_periods WHERE supplier_id = ? ORDER BY fiscal_year');
        $periods->execute([$supplierId]);
        self::assertSame([[SyntheticPohodaExport::YEAR, 'open'], [$next, 'open']],
            array_map(static fn (array $r): array => [(int) $r[0], (string) $r[1]], $periods->fetchAll(\PDO::FETCH_NUM)));
        $entries = $this->db->pdo()->prepare(
            'SELECT e.entry_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no IN (?, ?) ORDER BY e.entry_date'
        );
        $entries->execute([$supplierId, SyntheticPohodaExport::NEXT_YEAR_ISSUED, 'BAN0010016']);
        self::assertSame([[$next . '-01-10', $next], [$next . '-01-20', $next]],
            array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1]], $entries->fetchAll(\PDO::FETCH_NUM)));
        self::assertSame(0, $this->rows('journal_entries', $supplierId, sprintf("entry_date = '%d-12-31'", SyntheticPohodaExport::YEAR)));
        // Úhrada nese id bankovního dokladu a opis čísla jiného pohybu: páruje se podle id.
        $paidBy = $this->db->pdo()->prepare(
            'SELECT t.bank_ref FROM payment_matches pm JOIN invoices i ON i.id = pm.invoice_id JOIN bank_transactions t ON t.id = pm.bank_transaction_id
              WHERE pm.supplier_id = ? AND i.varsymbol = ?'
        );
        $paidBy->execute([$supplierId, SyntheticPohodaExport::NEXT_YEAR_ISSUED]);
        self::assertSame(['BAN0010016'], $paidBy->fetchAll(\PDO::FETCH_COLUMN));
        self::assertTrue($protocol->get('reconciliation')[0]['ok'], json_encode($protocol->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));

        // Stav po dřívějším převodu: zápisy následujícího roku posunuté k 31. 12. roku agendy.
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "UPDATE journal_entries e JOIN accounting_periods p ON p.supplier_id = e.supplier_id AND p.fiscal_year = ?
                SET e.period_id = p.id, e.entry_date = ?
              WHERE e.supplier_id = ? AND e.document_no IN (?, ?)"
        )->execute([SyntheticPohodaExport::YEAR, SyntheticPohodaExport::YEAR . '-12-31', $supplierId, SyntheticPohodaExport::NEXT_YEAR_ISSUED, 'BAN0010016']);
        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(2, self::stepCounts($again, 'journal')['relocated'] ?? 0, $this->explain($again));
        $entries->execute([$supplierId, SyntheticPohodaExport::NEXT_YEAR_ISSUED, 'BAN0010016']);
        self::assertSame([[$next . '-01-10', $next], [$next . '-01-20', $next]],
            array_map(static fn (array $r): array => [(string) $r[0], (int) $r[1]], $entries->fetchAll(\PDO::FETCH_NUM)));
        self::assertTrue($again->get('reconciliation')[0]['ok'], json_encode($again->get('reconciliation')[0], JSON_UNESCAPED_UNICODE));

        // Cizí zápis v období následujícího roku: deník se do rozjetého účetnictví nepřimíchá.
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, posted_at)
             SELECT supplier_id, id, ?, 'Ruční zápis', 'manual', NOW() FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?"
        )->execute([$next . '-02-01', $supplierId, $next]);
        $refused = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertTrue($refused->hasErrors());
        $errors = array_values(array_filter((array) $refused->get('preflight'), static fn (array $m): bool => $m['code'] === 'journal_not_empty'));
        self::assertSame([$next], array_column(array_column($errors, 'context'), 'year'));
    }

    /**
     * Převod agendy následujícího roku po agendě, která už vedla jeho doklady: zápisy, které
     * přinesla minulá agenda, podruhé nevzniknou, a pohyb se stejným číslem jako zaúčtovaný
     * pohyb minulého roku (číselná řada banky se opakuje) se nebere jako zaúčtovaný.
     */
    public function testNextYearAgendaDoesNotDuplicateEntriesCarriedByPreviousAgenda(): void
    {
        $supplierId = $this->supplier();
        $first = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/y1', unbooked: true)), false);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $before = $this->rows('journal_entries', $supplierId);

        $next = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::writeNextYear($this->tmp . '/y2')), false);
        self::assertSame(['reconciliation'], array_values(array_unique(array_map(
            static fn (array $s): string => $s['key'],
            array_filter($next->toArray()['steps'], static fn (array $s): bool => $s['status'] === 'error'),
        ))), 'Bez počátečních stavů v agendě následujícího roku nesedí jen rekonciliace. ' . $this->explain($next));
        self::assertSame(2, self::stepCounts($next, 'journal')['existing'] ?? 0, $this->explain($next));
        self::assertSame(0, self::stepCounts($next, 'journal')['entries'] ?? 0, $this->explain($next));
        self::assertSame($before, $this->rows('journal_entries', $supplierId));
        $fee = $this->db->pdo()->prepare(
            "SELECT t.match_status FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.bank_ref = 'BAN0010003' AND t.posted_at >= ?"
        );
        $fee->execute([$supplierId, SyntheticPohodaExport::NEXT_YEAR . '-01-01']);
        self::assertSame(['unmatched'], $fee->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Úhradu, kterou uživatel mezi převody spároval v MyÚčtu, převod nezdvojí. */
    public function testReimportKeepsPaymentMatchedByUserMeanwhile(): void
    {
        $supplierId = $this->supplier();
        $open = $this->importer->run($supplierId, $this->userId,
            PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/open', laterPayment: SyntheticPohodaExport::LATER_OPEN)), false);
        self::assertFalse($open->hasErrors(), $this->explain($open));
        $pdo = $this->db->pdo();
        $ids = $pdo->prepare(
            "SELECT t.id, (SELECT pi.id FROM purchase_invoices pi WHERE pi.supplier_id = s.supplier_id AND pi.vendor_invoice_number = 'D-2026-9')
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.variable_symbol = ?"
        );
        $ids->execute([$supplierId, SyntheticPohodaExport::LATER_PAID_VS]);
        [$txId, $invoiceId] = array_map('intval', $ids->fetch(\PDO::FETCH_NUM));
        // Uživatel převodem odvozené párování zrušil a platbu spároval znovu sám.
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$supplierId, $txId]);
        $pdo->prepare("INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type) VALUES (?, ?, ?, 726.00, 'auto')")
            ->execute([$supplierId, $txId, $invoiceId]);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'auto_exact' WHERE id = ?")->execute([$txId]);
        $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = '2026-01-29' WHERE id = ?")->execute([$invoiceId]);
        $before = $this->snapshot($supplierId);

        $settled = $this->importer->run($supplierId, $this->userId,
            PohodaExport::open(SyntheticPohodaExport::write($this->tmp . '/settled', laterPayment: SyntheticPohodaExport::LATER_SETTLED)), false);
        self::assertFalse($settled->hasErrors(), $this->explain($settled));
        self::assertSame($before['payment_matches'], $this->rows('payment_matches', $supplierId));
        self::assertSame(1, self::stepCounts($settled, 'payments')['already_matched'] ?? 0, $this->explain($settled));
        self::assertSame(1, $this->rows('purchase_invoices', $supplierId, "id = {$invoiceId} AND status = 'paid' AND paid_at = '2026-01-29'"));
    }

    /** @return array{0:string,1:?string,2:int} stav a důvod párování pohybu a počet jeho živých bankovních zápisů */
    private function txState(int $supplierId, string $bankRef): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.match_status, t.match_reason,
                    (SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = s.supplier_id AND e.source_type = 'bank' AND e.source_id = t.id AND e.reversed_by IS NULL)
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.bank_ref = ?"
        );
        $stmt->execute([$supplierId, $bankRef]);
        $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
        self::assertCount(1, $rows);
        return [(string) $rows[0][0], $rows[0][1] === null ? null : (string) $rows[0][1], (int) $rows[0][2]];
    }

    private function bankTxId(int $supplierId, string $bankRef): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.bank_ref = ?');
        $stmt->execute([$supplierId, $bankRef]);
        return (int) $stmt->fetchColumn();
    }

    private function pendingSuggestionId(int $supplierId, string $bankRef): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM bank_posting_suggestions WHERE supplier_id = ? AND bank_transaction_id = ? AND status = 'pending'");
        $stmt->execute([$supplierId, $this->bankTxId($supplierId, $bankRef)]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{0:string,1:string,2:string}|null MD, D a režim pravidla čekajícího návrhu pohybu */
    private function postingProposal(int $supplierId, string $bankRef): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.debit_account_code, s.credit_account_code, r.mode FROM bank_posting_suggestions s
               JOIN bank_posting_rules r ON r.id = s.rule_id AND r.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND s.bank_transaction_id = ? AND s.status = 'pending' AND s.source = 'rule'"
        );
        $stmt->execute([$supplierId, $this->bankTxId($supplierId, $bankRef)]);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row === false ? null : array_map('strval', $row);
    }

    private function bankEntryId(int $supplierId, string $bankRef): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id FROM journal_entries e
               JOIN bank_transactions t ON t.id = e.source_id JOIN bank_statements s ON s.id = t.statement_id AND s.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.reversed_by IS NULL AND t.bank_ref = ?"
        );
        $stmt->execute([$supplierId, $bankRef]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(1, $ids);
        return $ids[0];
    }

    /** @return list<array{0:string,1:string,2:string}> strana, účet, částka živého bankovního zápisu pohybu */
    private function bankEntryLines(int $supplierId, string $bankRef): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.side, a.account_code, l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? ORDER BY l.side = 'credit', l.id"
        );
        $stmt->execute([$this->bankEntryId($supplierId, $bankRef)]);
        return array_map(static fn (array $r): array => [(string) $r[0], (string) $r[1], (string) $r[2]], $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    private function bankEntryYear(int $supplierId, string $bankRef): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id WHERE e.id = ?');
        $stmt->execute([$this->bankEntryId($supplierId, $bankRef)]);
        return (int) $stmt->fetchColumn();
    }

    /** Export cizí firmy se do téhle nevmíchá. */
    public function testExportOfAnotherCompanyIsRefused(): void
    {
        $supplierId = $this->supplier('99999994');
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaExport::write($this->tmp)), false);
        self::assertTrue($protocol->hasErrors());
        self::assertContains('ico_mismatch', array_column((array) $protocol->get('preflight'), 'code'));
        self::assertSame(0, $this->rows('journal_entries', $supplierId));
    }

    private function supplier(string $ico = SyntheticPohodaExport::ICO): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, $ico, 'CZ' . $ico, $this->anyCurrencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $id]);
        return $id;
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> */
    private function snapshot(int $supplierId): array
    {
        $out = [];
        foreach (['journal_entries', 'journal_entry_lines', 'accounting_periods', 'purchase_invoices', 'invoices', 'clients',
            'payment_matches', 'posting_rules', 'bank_statements', 'journal_entry_document_links', 'chart_of_accounts'] as $t) {
            $out[$t] = $this->rows($t, $supplierId);
        }
        $out['map'] = array_sum((new PohodaImportRepository($this->db))->counts($supplierId));
        return $out;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps'], 'preflight' => $protocol->get('preflight')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
