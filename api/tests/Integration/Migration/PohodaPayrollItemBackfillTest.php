<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Migration\Pohoda\OtherItemImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaPayrollItemBackfill;
use MyInvoice\Service\Migration\Pohoda\PohodaPayrollItemCheck;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doplnění mzdových závazků (331, 336, 342) firmě převedené z POHODY dřív, než je převod
 * zakládal ({@see PohodaPayrollItemBackfill}). Převod před opravou se simuluje odebráním
 * mzdových položek, jejich úhrad a mapy po převodu syntetického exportu: zápisy deníku
 * zůstanou ruční a pohyb úhrady vyřízený bez dokladu, stejně jako je převod nechával.
 * Izolovaná firma, transakce s rollbackem v tearDown.
 */
#[Group('integration')]
final class PohodaPayrollItemBackfillTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PohodaImporter $importer;
    private PohodaPayrollItemBackfill $backfill;
    private OtherItemBankMatchService $bankMatch;
    private PohodaPayrollItemCheck $check;
    private InternalDocumentService $internalDocuments;
    private string $tmp = '';
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaImporter::class);
        $this->backfill = $container->get(PohodaPayrollItemBackfill::class);
        $this->bankMatch = $container->get(OtherItemBankMatchService::class);
        $this->check = $container->get(PohodaPayrollItemCheck::class);
        $this->internalDocuments = $container->get(InternalDocumentService::class);
        $this->pdo = $this->db->pdo();
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('Chybí uživatel v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
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

    /**
     * Pojistné s vlastním zápisem ho převezme bez nového účtování a dostane úhradu z převzaté
     * banky, mzda zaměstnance a daň z počátečního stavu jsou potvrzené bez zápisu. Zkouška
     * nanečisto nic nezapíše, deník se nezmění a druhé doplnění nic nepřidá.
     */
    public function testBackfillCreatesSkippedPayrollItemsWithoutPostingAndIsIdempotent(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $journal = $this->journal($supplierId);
        $conversion = ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null];

        $dry = $this->backfill->agenda($supplierId, $export, $conversion, false, null, $this->userId, '2026-12-31');
        self::assertCount(5, array_filter($dry['rows'], static fn (array $r): bool => str_starts_with($r['status'], 'created_')),
            json_encode($dry['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->payrollItems($supplierId), 'Zkouška nanečisto nic nezapíše.');
        self::assertSame($journal, $this->journal($supplierId));

        $result = $this->backfill->agenda($supplierId, $export, $conversion, true, null, $this->userId, '2026-12-31');
        $rows = array_column($result['rows'], null, 'number');
        self::assertSame($journal, $this->journal($supplierId), 'Doplnění do deníku nic nezapíše.');
        $expected = [
            '26OZM0001' => 'created_posted',
            SyntheticPohodaExport::PAYROLL_INSURANCE => 'created_posted',
            SyntheticPohodaExport::PAYROLL_TAX => 'created_posted',
            SyntheticPohodaExport::PAYROLL_WAGE => 'created_unposted',
            SyntheticPohodaExport::PAYROLL_OPENING_TAX => 'created_unposted',
        ];
        $statuses = array_map(static fn (array $r): string => $r['status'], array_intersect_key($rows, $expected));
        ksort($expected);
        ksort($statuses);
        self::assertSame($expected, $statuses);
        self::assertSame(5, $this->payrollItems($supplierId));

        foreach (['26OZM0001', SyntheticPohodaExport::PAYROLL_INSURANCE, SyntheticPohodaExport::PAYROLL_TAX] as $number) {
            $item = $this->item($supplierId, $number);
            self::assertSame('posted', $item['status'], $number);
            self::assertSame(1, $this->rows('journal_entries', $supplierId,
                "id = {$item['journal_entry_id']} AND source_type = 'other_item' AND source_id = {$item['id']}"), $number);
        }
        foreach ([SyntheticPohodaExport::PAYROLL_WAGE, SyntheticPohodaExport::PAYROLL_OPENING_TAX] as $number) {
            $item = $this->item($supplierId, $number);
            self::assertSame(['confirmed', null], [$item['status'], $item['journal_entry_id']], $number);
        }
        self::assertStringContainsString('interní doklad mezd 26IN0010', (string) $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE)['note']);
        self::assertSame('26IN0010', $rows[SyntheticPohodaExport::PAYROLL_WAGE]['source_document']);

        // Úhrada pojistného z převzaté banky: pohyb vyřízený bez dokladu se přiřadí k položce.
        $insurance = $rows[SyntheticPohodaExport::PAYROLL_INSURANCE];
        self::assertSame([6000.0, 0.0, 0.0], [$insurance['allocated'], $insurance['settled_outside'], $insurance['open']]);
        self::assertSame('unmatched', $this->pdo->query("SELECT t.match_status FROM bank_transactions t
            JOIN other_item_allocations a ON a.bank_transaction_id = t.id WHERE a.other_item_id = {$insurance['id']}")->fetchColumn());

        // Párovací údaje dokladu: VS, partner a účet protistrany.
        $tax = $this->item($supplierId, SyntheticPohodaExport::PAYROLL_TAX);
        self::assertSame([SyntheticPohodaExport::PAYROLL_TAX_VS, 'Finanční úřad Test', SyntheticPohodaExport::PARTNER_ACCOUNT, SyntheticPohodaExport::PARTNER_BANK, '342.000'],
            [$tax['variable_symbol'], $tax['partner_name'], $tax['counterparty_account'], $tax['counterparty_bank_code'], $tax['account_code']]);
        self::assertNotNull($tax['partner_id']);

        // Klíč mapy převodu: pozdější převod ani doplnění položku nezdvojí.
        self::assertSame(1, $this->rows('pohoda_import_map', $supplierId,
            "kind = 'other_item' AND pohoda_key = 'commitment|" . SyntheticPohodaExport::PAYROLL_TAX . "|2026-03-05'"));

        // Odsouhlasení: 336 sedí na deník (pojistné uhrazené, odvod 500 otevřený).
        $at = array_column($result['reconciliation'][0]['accounts'], null, 'account');
        self::assertSame('2026-12-31', $result['reconciliation'][0]['as_of']);
        self::assertSame(['account' => '336', 'ledger' => 500.0, 'open_items' => 500.0, 'difference' => 0.0], $at['336']);

        // Kontrola: srážka 331 v převzatém zápisu závazku k finančnímu úřadu (březen).
        $debits = array_column($this->check->check($supplierId)['item_debits'], null, 'month');
        self::assertSame(['month' => '2026-03', 'item_pohoda' => 2500.0, 'item_myucto' => 0.0, 'internal' => 0.0], $debits['2026-03'] ?? null);

        $items = $this->rows('other_items', $supplierId);
        $allocations = $this->rows('other_item_allocations', $supplierId);
        $map = $this->rows('pohoda_import_map', $supplierId);
        $again = $this->backfill->agenda($supplierId, $export, $conversion, true, null, $this->userId, '2026-12-31');
        $statuses = array_map(static fn (array $r): string => $r['status'], array_intersect_key(array_column($again['rows'], null, 'number'), $expected));
        ksort($statuses);
        self::assertSame(array_fill_keys(array_keys($expected), 'existing'), $statuses);
        self::assertSame([], array_filter($again['rows'], static fn (array $r): bool => $r['status'] !== 'existing'));
        self::assertSame($items, $this->rows('other_items', $supplierId));
        self::assertSame($allocations, $this->rows('other_item_allocations', $supplierId));
        self::assertSame($map, $this->rows('pohoda_import_map', $supplierId));
        self::assertSame($journal, $this->journal($supplierId));
    }

    /**
     * Mzdový doklad, jehož zápis v deníku chybí nebo ho mezitím převzal jiný doklad, se
     * nezaloží (ani bez zápisu, předpis by jinak v agendě visel dvakrát) a vrátí s důvodem.
     */
    public function testPayrollItemWithMissingOrForeignEntryIsRefused(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $entry = fn (string $number): int => (int) $this->pdo->query("SELECT target_id FROM pohoda_import_map
            WHERE supplier_id = {$supplierId} AND kind = 'journal_entry' AND pohoda_key LIKE '%|Ostatní závazky|{$number}|%'")->fetchColumn();
        $missing = $entry('26OZM0001');
        $owned = $entry(SyntheticPohodaExport::PAYROLL_TAX);
        self::assertGreaterThan(0, $missing);
        self::assertGreaterThan(0, $owned);
        $this->pdo->exec("UPDATE pohoda_import_map SET target_id = 999999999 WHERE supplier_id = {$supplierId} AND kind = 'journal_entry' AND target_id = {$missing}");
        // Pojistné bez mapy zápisu: založit ho bez zápisu by předpis zdvojilo.
        $this->pdo->exec("DELETE FROM pohoda_import_map WHERE supplier_id = {$supplierId} AND kind = 'journal_entry'
            AND pohoda_key LIKE '%|Ostatní závazky|" . SyntheticPohodaExport::PAYROLL_INSURANCE . "|%'");
        $this->pdo->exec("UPDATE journal_entries SET source_type = 'purchase_invoice', source_id = 999999999 WHERE id = {$owned}");
        $journal = $this->journal($supplierId);

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $rows = array_column($result['rows'], null, 'number');
        self::assertSame(['skipped', 'entry_missing'], [$rows['26OZM0001']['status'], $rows['26OZM0001']['reason']]);
        self::assertSame(['skipped', 'entry_owned'], [$rows[SyntheticPohodaExport::PAYROLL_TAX]['status'], $rows[SyntheticPohodaExport::PAYROLL_TAX]['reason']]);
        self::assertSame(['skipped', 'entry_missing'], [$rows[SyntheticPohodaExport::PAYROLL_INSURANCE]['status'], $rows[SyntheticPohodaExport::PAYROLL_INSURANCE]['reason']]);
        self::assertSame('created_unposted', $rows[SyntheticPohodaExport::PAYROLL_WAGE]['status']);
        self::assertSame(2, $this->payrollItems($supplierId));
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no IN ('26OZM0001', '" . SyntheticPohodaExport::PAYROLL_TAX . "', '" . SyntheticPohodaExport::PAYROLL_INSURANCE . "')"));
        self::assertSame($journal, $this->journal($supplierId));
    }

    /**
     * Tatáž mzda v POHODĚ dvakrát (jiné číslo, jeden předpis v interním dokladu mezd): převod
     * založí jen první závazek, druhý by v agendě visel bez předpisu a nikdy by se nespároval.
     * Opakovaný převod druhý nezaloží ani tehdy, když první už existuje.
     */
    public function testImporterDoesNotCreatePayrollItemBeyondPredpis(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        SyntheticPohodaExport::withDuplicatePayrollWage($dir);
        $export = PohodaExport::open($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors());
        self::assertSame(1, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE . "'"));
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE . "'"));
        self::assertContains('payroll_predpis_exceeded', $this->messageCodes($protocol->toArray(), OtherItemImporter::STEP));

        $again = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($again->hasErrors());
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE . "'"));
    }

    /**
     * Měsíc, jehož zaúčtování mezd je načtené z PAMICY i s jejími závazky: převod ani doplnění
     * mzdové závazky POHODY za něj nezakládá (zdvojily by závazky i úhrady). Měsíc určuje
     * předpis, ne datum výplaty: mzda za leden s datem v únoru patří k lednu, daň za únor
     * s datem v březnu k únoru.
     */
    public function testPamicaMonthIsDecidedByPredpisMonthNotPayoutDate(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $this->pamicaImport($supplierId, '2026-01', 'PAM-2026-01');

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $rows = array_column($result['rows'], null, 'number');
        foreach (['26OZM0001', SyntheticPohodaExport::PAYROLL_WAGE, SyntheticPohodaExport::PAYROLL_INSURANCE] as $number) {
            self::assertSame(['skipped', 'payroll_imported'], [$rows[$number]['status'], $rows[$number]['reason']], $number . ': předpis za leden (datum v únoru).');
        }
        self::assertSame('created_posted', $rows[SyntheticPohodaExport::PAYROLL_TAX]['status'], 'Daň s datem 5. 3. patří k únoru, ten PAMICA nenačetla.');
        self::assertSame('created_unposted', $rows[SyntheticPohodaExport::PAYROLL_OPENING_TAX]['status']);
    }

    /**
     * Párovací symbol měsíce PAMICY (MZ + rok a měsíc) rozhoduje před datem: doklad se
     * symbolem, který PAMICA nenačetla, se založí, i když jeho měsíc předpisu PAMICA načetla.
     */
    public function testPayrollDocumentWithForeignMonthSymbolIsCreatedInPamicaMonth(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix('MZ202603');
        $this->pamicaImport($supplierId, '2026-02', 'MZ202602');

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $rows = array_column($result['rows'], null, 'number');
        self::assertSame('created_posted', $rows[SyntheticPohodaExport::PAYROLL_TAX]['status'], 'Symbol MZ202603 PAMICA nenačetla.');
        self::assertSame('created_unposted', $rows[SyntheticPohodaExport::PAYROLL_WAGE]['status'], 'Mzda za leden, PAMICA má únor.');
    }

    /**
     * Jiný symbol než symbol měsíce PAMICY (vlastní párovací symbol POHODY) nerozhoduje:
     * doklad patří k měsíci předpisu a za únor načtený z PAMICY se nezaloží.
     */
    public function testNonPamicaSymbolFallsBackToPredpisMonth(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix('POH-2026-02');
        $this->pamicaImport($supplierId, '2026-02', 'MZ202602');

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $row = array_column($result['rows'], null, 'number')[SyntheticPohodaExport::PAYROLL_TAX];
        self::assertSame(['skipped', 'payroll_imported'], [$row['status'], $row['reason']], 'Daň s datem 5. 3. patří k únoru z PAMICY.');
    }

    /**
     * Dva zaměstnanci se stejnou mzdou v témže měsíci a ruční položka jen pro jednoho z nich
     * (vyšší číslo dokladu): doplnění založí mzdu druhého a jen tu se shodným partnerem
     * vynechá jako ruční protějšek.
     */
    public function testManualDuplicateIsMatchedByPartner(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        SyntheticPohodaExport::withSecondEmployeeWage($dir);
        $export = PohodaExport::open($dir);
        self::assertFalse($this->importer->run($supplierId, $this->userId, $export, false)->hasErrors());
        $this->pdo->exec("DELETE m FROM pohoda_import_map m JOIN other_items oi ON oi.id = m.target_id
            WHERE m.supplier_id = {$supplierId} AND m.kind = 'other_item' AND oi.account_code LIKE '331%'");
        $this->pdo->exec("DELETE FROM other_items WHERE supplier_id = {$supplierId} AND account_code LIKE '331%'");
        $this->manualItem($supplierId, 'RUCNI-A', 20000, '2026-02-10', 'Zaměstnanci Test');

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $rows = array_column($result['rows'], null, 'number');
        self::assertSame('created_unposted', $rows[SyntheticPohodaExport::PAYROLL_SECOND_WAGE]['status']);
        self::assertSame(['skipped', 'possible_manual_duplicate'], [$rows[SyntheticPohodaExport::PAYROLL_WAGE]['status'], $rows[SyntheticPohodaExport::PAYROLL_WAGE]['reason']]);
    }

    /**
     * Srážka na Má dáti 331 na začátku února (jiný účet na Dal) neposune lednovou mzdu s datem
     * 8. 2. do února: den předpisu určuje hlavní předpis na Dal účtu závazku. Převod mzdu založí
     * a kontrola ji nehlásí.
     */
    public function testEarlyDeductionDoesNotShiftPredpisMonth(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        SyntheticPohodaExport::withEarlyFebruaryDeduction($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors());
        self::assertNotContains('payroll_predpis_exceeded', $this->messageCodes($protocol->toArray(), OtherItemImporter::STEP));
        self::assertSame(1, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE . "'"));
        self::assertNotContains(SyntheticPohodaExport::PAYROLL_WAGE, array_column($this->check->check($supplierId)['uncovered'], 'document_no'));
    }

    /** Zaúčtování mezd z PAMICY za měsíc: interní doklad mezd a mzda zaměstnance bez zápisu. */
    private function pamicaImport(int $supplierId, string $period, string $symPar, float $amount = 999, ?string $date = null): int
    {
        $date ??= $period . '-28';
        $audit = ['user_id' => $this->userId, 'posted_by' => $this->userId, 'ip' => '127.0.0.1', 'user_agent' => 'phpunit'];
        $draft = $this->internalDocuments->create($supplierId, ['kind' => 'other', 'title' => 'Mzdy ' . $period . ' z PAMICY', 'document_date' => $date,
            'entry_date' => $date, 'pairs' => [['debit_account_code' => '521.000', 'credit_account_code' => '331.000', 'amount' => $amount]]], $this->userId);
        $document = (int) $this->internalDocuments->post($supplierId, (int) $draft['id'], $audit)['id'];
        $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, kind, title, issued_on, accounting_on, due_on, currency, amount,
                account_code, status, document_no) VALUES (?, 'payable', 'other', ?, ?, ?, ?, 'CZK', ?, '331.000', 'confirmed', ?)")
            ->execute([$supplierId, 'Mzda z PAMICY ' . $period, $date, $date, $date, $amount, 'PAMICA-' . $period]);
        $item = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO payroll_posting_imports (supplier_id, source_application, source_ico, sym_par, period, file_sha256,
                total_amount, internal_document_ids, other_item_ids) VALUES (?, 'PAMICA', ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$supplierId, SyntheticPohodaExport::ICO, $symPar, $period, hash('sha256', $symPar), $amount, json_encode([$document]), json_encode([$item])]);
        return $item;
    }

    /**
     * Mzda za prosinec minulého roku vyplacená v lednu nečerpá lednový předpis mezd: její
     * předpis je v počátečním stavu (měsíc před začátkem převzatého deníku). Převod založí
     * obě mzdy a kontrola žádnou nehlásí bez předpisu, ať jsou doklady v exportu v jakémkoli
     * pořadí.
     */
    public function testWageOfMonthBeforeJournalDoesNotConsumeJanuaryPredpis(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        SyntheticPohodaExport::withDecemberWagePaidInJanuary($dir);

        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors());
        self::assertNotContains('payroll_predpis_exceeded', $this->messageCodes($protocol->toArray(), OtherItemImporter::STEP));
        self::assertSame(1, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE . "'"));
        self::assertSame(1, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_DECEMBER_WAGE . "'"));
        self::assertSame([], $this->check->check($supplierId)['uncovered']);
    }

    /**
     * Stornovaný interní doklad mezd (protizápis na Má dáti 331) není předpis: kontrola ho
     * nepočítá, takže duplicitní mzdu dál hlásí bez předpisu.
     */
    public function testReversedInternalDocumentIsNotPredpisForCheck(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        self::assertFalse($this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false)->hasErrors());
        $this->manualItem($supplierId, 'RUCNI-3', 20000, '2026-02-08');
        $audit = ['user_id' => $this->userId, 'posted_by' => $this->userId, 'ip' => '127.0.0.1', 'user_agent' => 'phpunit'];
        $draft = $this->internalDocuments->create($supplierId, ['kind' => 'other', 'title' => 'Mzdy 1/2026 omylem', 'document_date' => '2026-01-31',
            'entry_date' => '2026-01-31', 'pairs' => [['debit_account_code' => '521.000', 'credit_account_code' => '331.000', 'amount' => 20000]]], $this->userId);
        $document = (int) $this->internalDocuments->post($supplierId, (int) $draft['id'], $audit)['id'];
        $this->internalDocuments->reverse($supplierId, $document, 'Omylem zadané mzdy', null, $audit);
        self::assertSame(1, $this->rows('journal_entries', $supplierId, 'reversed_by IS NOT NULL'));

        $report = $this->check->check($supplierId);
        self::assertSame(['RUCNI-3'], array_column($report['uncovered'], 'document_no'));
        self::assertSame([], $report['double_months'], 'Stornované mzdy z MyÚčta nejsou druhý předpis měsíce.');
    }

    /**
     * Firma převedená importérem, který mzdu zaměstnance založil dvakrát: kontrola najde
     * duplicitu bez předpisu v deníku, oprava ji zruší bez zápisu do deníku a nechá závazek,
     * který předpis nese. Nanečisto se nic nezmění.
     */
    public function testCheckFindsAndCancelsDuplicatePayrollItemWithoutTouchingJournal(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        self::assertFalse($this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false)->hasErrors());
        $wage = $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        // Druhá kopie mzdy, jak ji založil převod (mapa převodu, potvrzená bez zápisu).
        $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
                currency, amount, amount_czk, account_code, note, status, document_no)
             SELECT supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on, currency, amount, amount_czk,
                account_code, note, status, ? FROM other_items WHERE id = ?")
            ->execute([SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE, $wage['id']]);
        $duplicate = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'other_item', ?, ?)")
            ->execute([$supplierId, 'commitment|' . SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE . '|2026-02-08', $duplicate]);
        $journal = $this->journal($supplierId);

        $report = $this->check->check($supplierId);
        self::assertCount(1, $report['duplicates']);
        self::assertEqualsCanonicalizing([(int) $wage['id'], $duplicate], array_column($report['duplicates'][0]['items'], 'id'));
        self::assertSame([$duplicate], array_column($report['uncovered'], 'id'), 'Závazek z počátečního stavu předpis v deníku mít nemusí.');

        $this->check->run($supplierId, ['cancel_duplicates' => true], false, $this->userId);
        self::assertSame('confirmed', $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE)['status'], 'Nanečisto nic.');

        $fixed = $this->check->run($supplierId, ['cancel_duplicates' => true], true, $this->userId);
        self::assertSame([['id' => $duplicate, 'document_no' => SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE, 'result' => 'cancelled']], $fixed['fixes']['cancelled']);
        self::assertSame(0, $fixed['fixes']['journal_entries_added']);
        self::assertSame('cancelled', $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE_DUPLICATE)['status']);
        self::assertSame('confirmed', $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE)['status']);
        self::assertSame([], $fixed['after']['duplicates']);
        self::assertSame($journal, $this->journal($supplierId));
    }

    /**
     * Zaúčtování mezd z PAMICY načtené po převodu, který měsíc už převzal z POHODY: kontrola
     * najde měsíc s předpisem 331 dvakrát. Storno dokladů PAMICY jde jen výslovně, deník dostane
     * protizápis a doplnění pak mzdové závazky POHODY za měsíc založí.
     */
    public function testCheckReportsPamicaMonthOverlapAndReversesItOnlyOnRequest(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $this->pamicaImport($supplierId, '2026-01', '202601', 20000, '2026-01-31');
        $entries = $this->rows('journal_entries', $supplierId);

        $report = $this->check->check($supplierId);
        $months = array_column($report['double_months'], null, 'month');
        self::assertArrayHasKey('2026-01', $months);
        self::assertSame([20000.0, 20000.0], [$months['2026-01']['pohoda'], $months['2026-01']['myucto']]);
        self::assertSame([[['202601'], ['2026-01'], 20000.0, 20000.0]], array_map(static fn (array $i): array => [$i['sym_pars'], $i['months'], $i['amount'], $i['pohoda']], $report['pamica_overlaps']));
        self::assertSame($entries, $this->rows('journal_entries', $supplierId), 'Kontrola deník nemění.');

        $dry = $this->check->run($supplierId, ['reverse_pamica' => true], false, $this->userId);
        self::assertSame('reversed', $dry['fixes']['reversed'][0]['result']);
        self::assertSame($entries, $this->rows('journal_entries', $supplierId), 'Nanečisto se deník vrátí.');

        try {
            $this->check->run($supplierId, ['reverse_pamica' => true], true, null);
            self::fail('Storno do deníku bez autora.');
        } catch (\InvalidArgumentException) {
        }
        $done = $this->check->run($supplierId, ['reverse_pamica' => true], true, $this->userId);
        self::assertSame(1, $done['fixes']['journal_entries_added'], 'Storno interního dokladu PAMICY.');
        self::assertArrayNotHasKey('2026-01', array_column($done['after']['double_months'], null, 'month'));
        self::assertSame('cancelled', $this->item($supplierId, 'PAMICA-2026-01')['status']);

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        self::assertSame('created_unposted', array_column($result['rows'], null, 'number')[SyntheticPohodaExport::PAYROLL_WAGE]['status']);
    }

    /**
     * Načtení z PAMICY s jiným předpisem 331, než nese převzatý deník POHODY v jeho měsíci,
     * nejsou tytéž mzdy: storno ho odmítne a deník se nezmění.
     */
    public function testPamicaReversalIsRefusedWhenAmountsDiffer(): void
    {
        [$supplierId] = $this->convertedBeforeFix();
        $this->pamicaImport($supplierId, '2026-01', '202601', 15000, '2026-01-31');
        $entries = $this->rows('journal_entries', $supplierId);

        $done = $this->check->run($supplierId, ['reverse_pamica' => true], true, $this->userId);
        self::assertStringStartsWith('refused: amounts_differ', $done['fixes']['reversed'][0]['result']);
        self::assertSame(0, $done['fixes']['journal_entries_added']);
        self::assertSame($entries, $this->rows('journal_entries', $supplierId));
        self::assertSame('confirmed', $this->item($supplierId, 'PAMICA-2026-01')['status']);
    }

    /**
     * Dvě načtení z PAMICY v témže měsíci (původní a doplňující, jiný symbol) zdvojují spolu
     * předpis POHODY: porovná se jejich součet a stornují se obě, nebo žádné.
     */
    public function testPamicaImportsOfOneMonthAreComparedAndReversedTogether(): void
    {
        [$supplierId] = $this->convertedBeforeFix();
        $this->pamicaImport($supplierId, '2026-01', 'MZ202601', 12000, '2026-01-31');
        $this->pdo->prepare("UPDATE other_items SET document_no = 'PAMICA-2026-01-A' WHERE supplier_id = ? AND document_no = 'PAMICA-2026-01'")->execute([$supplierId]);
        $this->pamicaImport($supplierId, '2026-01', 'MZ202601B', 8000, '2026-01-31');

        $report = $this->check->check($supplierId);
        self::assertCount(1, $report['pamica_overlaps']);
        self::assertSame([20000.0, 20000.0], [$report['pamica_overlaps'][0]['amount'], $report['pamica_overlaps'][0]['pohoda']]);

        $done = $this->check->run($supplierId, ['reverse_pamica' => true], true, $this->userId);
        self::assertSame('reversed', $done['fixes']['reversed'][0]['result']);
        self::assertSame(2, $done['fixes']['journal_entries_added']);
        self::assertSame('cancelled', $this->item($supplierId, 'PAMICA-2026-01-A')['status']);
        self::assertSame('cancelled', $this->item($supplierId, 'PAMICA-2026-01')['status']);
    }

    /**
     * Opakované doplnění nepřepisuje uhrazenou část mimo banku, kterou položce mezitím nastavila
     * účetní: u už založené položky jen doplní úhrady z exportu.
     */
    public function testRepeatedBackfillKeepsSettlementSetInMyUcto(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $conversion = ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null];
        $this->backfill->agenda($supplierId, $export, $conversion, true, null, $this->userId, '2026-12-31');
        $wage = $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE);
        $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = 5000 WHERE id = ?')->execute([$wage['id']]);

        $again = $this->backfill->agenda($supplierId, $export, $conversion, true, null, $this->userId, '2026-12-31');
        self::assertSame('existing', array_column($again['rows'], null, 'number')[SyntheticPohodaExport::PAYROLL_WAGE]['status']);
        self::assertSame('5000.00', (string) $this->item($supplierId, SyntheticPohodaExport::PAYROLL_WAGE)['settled_outside_amount']);
    }

    /**
     * Hromadná výplata, ze které doplněné závazky pokryjí jen část: zbytek zůstane nespárovaný
     * a protokol ho uvede. Položky v uzavřeném období se založí a výpis je označí.
     */
    public function testPartiallyCoveredPayoutAndClosedPeriodAreReported(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $this->pdo->prepare("UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id SET t.amount = -10000
            WHERE s.supplier_id = ? AND t.match_reason = 'pohoda_booked'")->execute([$supplierId]);
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = 2026")->execute([$supplierId]);

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        self::assertContains('bank_partially_allocated', $this->messageCodes($result['protocol']->toArray(), 'payments'));
        $insurance = array_column($result['rows'], null, 'number')[SyntheticPohodaExport::PAYROLL_INSURANCE];
        self::assertSame(['created_posted', 6000.0], [$insurance['status'], $insurance['allocated']]);
        self::assertSame('2026 (closed)', $insurance['closed_period']);
        self::assertContains('period_not_open', $this->messageCodes($result['protocol']->toArray(), OtherItemImporter::STEP));
    }

    /**
     * Mzdový závazek, který účetní po převodu zadala v MyÚčtu jako zůstatek bez dokladu nad
     * řádkem převzatého zápisu: doplnění zápis nepřevezme (předpis by byl v agendě dvakrát)
     * a vrátí doklad s důvodem.
     */
    public function testEntryWithBalanceItemFromMyUctoIsNotTakenOver(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $entry = (int) $this->pdo->query("SELECT target_id FROM pohoda_import_map WHERE supplier_id = {$supplierId}
            AND kind = 'journal_entry' AND pohoda_key LIKE '%|Ostatní závazky|26OZM0001|%'")->fetchColumn();
        $line = $this->pdo->query("SELECT l.line_no, l.account_id FROM journal_entry_lines l WHERE l.entry_id = {$entry} AND l.side = 'credit'")->fetch(PDO::FETCH_ASSOC);
        $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, kind, title, issued_on, accounting_on, due_on, currency, amount,
                account_code, status, document_no) VALUES (?, 'payable', 'other', 'Sociální pojištění (zůstatek)', '2026-02-04', '2026-02-04', '2026-02-20', 'CZK', 500, '336.000', 'confirmed', 'ZBD-1')")
            ->execute([$supplierId]);
        $balance = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, doc_type, doc_id, amount, kind)
            VALUES (?, ?, ?, ?, 'credit', 'other_item', ?, 500, 'origin')")->execute([$supplierId, $entry, $line['line_no'], $line['account_id'], $balance]);

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $row = array_column($result['rows'], null, 'number')['26OZM0001'];
        self::assertSame(['skipped', 'entry_origin'], [$row['status'], $row['reason']]);
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no = '26OZM0001'"));
        self::assertSame(0, $this->rows('journal_entries', $supplierId, "id = {$entry} AND source_type = 'other_item'"));
    }

    /**
     * Mzdu, kterou účetní po převodu zadala v MyÚčtu ručně (stejný účet, částka a měsíc
     * předpisu), doplnění nezaloží podruhé a ohlásí ji.
     */
    public function testPayrollItemEnteredInMyUctoIsNotCreatedAgain(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $this->manualItem($supplierId, 'RUCNI-1', 20000, '2026-02-10');

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $row = array_column($result['rows'], null, 'number')[SyntheticPohodaExport::PAYROLL_WAGE];
        self::assertSame(['skipped', 'possible_manual_duplicate'], [$row['status'], $row['reason']]);
        self::assertSame(0, $this->rows('other_items', $supplierId, "document_no = '" . SyntheticPohodaExport::PAYROLL_WAGE . "'"));
    }

    /**
     * Předpis mezd čerpají všechny mzdové položky bez zápisu, i ty zadané v MyÚčtu: s ruční
     * zálohou na mzdu 15 000 Kč lednový předpis 20 000 Kč mzdu 20 000 Kč nepokryje.
     */
    public function testPredpisIsReducedByItemsEnteredInMyUcto(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $this->manualItem($supplierId, 'RUCNI-2', 15000, '2026-02-10');

        $result = $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $row = array_column($result['rows'], null, 'number')[SyntheticPohodaExport::PAYROLL_WAGE];
        self::assertSame(['skipped', 'predpis_exceeded'], [$row['status'], $row['reason']]);
    }

    private function manualItem(int $supplierId, string $number, float $amount, string $date, ?string $partner = null): int
    {
        $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, kind, title, partner_name, issued_on, accounting_on, due_on, currency, amount,
                account_code, status, document_no) VALUES (?, 'payable', 'other', 'Mzda zadaná ručně', ?, ?, ?, ?, 'CZK', ?, '331.000', 'confirmed', ?)")
            ->execute([$supplierId, $partner, $date, $date, $date, $amount, $number]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Doplněný závazek se spáruje s úhradou zadanou až v MyÚčtu podle VS a účtu protistrany. */
    public function testBankPaymentEnteredInMyUctoPairsBackfilledItem(): void
    {
        [$supplierId, $export] = $this->convertedBeforeFix();
        $this->backfill->agenda($supplierId, $export, ['run_id' => 0, 'skipped_years' => [], 'own_journal_from' => null], true, null, $this->userId, '2026-12-31');
        $item = $this->item($supplierId, SyntheticPohodaExport::PAYROLL_TAX);

        $statement = (int) $this->pdo->query("SELECT MIN(id) FROM bank_statements WHERE supplier_id = {$supplierId}")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, variable_symbol, counterparty_account,
                counterparty_bank, counterparty_name, description, match_status)
             VALUES (?, '2026-03-20', -2500, 'CZK', ?, ?, ?, 'Finanční úřad Test', 'Daň ze mzdy', 'unmatched')"
        )->execute([$statement, SyntheticPohodaExport::PAYROLL_TAX_VS, SyntheticPohodaExport::PARTNER_ACCOUNT, SyntheticPohodaExport::PARTNER_BANK]);
        $txId = (int) $this->pdo->lastInsertId();

        $candidates = array_column($this->bankMatch->candidates($supplierId, $txId), null, 'id');
        self::assertArrayHasKey((int) $item['id'], $candidates, json_encode($candidates, JSON_UNESCAPED_UNICODE));
        self::assertSame(array_key_first($candidates), (int) $item['id'], 'Nejlepší shoda je doplněný závazek.');
        self::assertNotEmpty($candidates[(int) $item['id']]['match_reasons']);

        $matched = $this->bankMatch->match($supplierId, $txId, (int) $item['id'], $this->userId, [], true);
        self::assertEqualsWithDelta(0.0, (float) $matched['remaining_amount'], 0.001);
        self::assertSame(1, $this->rows('other_item_allocations', $supplierId, "other_item_id = {$item['id']} AND bank_transaction_id = {$txId}"));
    }

    /**
     * Celý běh z exportu: najde převedenou agendu podle protokolu převodu, doplní ji, zapíše
     * běh do historie a vypíše založené položky i odsouhlasení. Bez exportu jen přehled.
     */
    public function testRunFindsConvertedAgendaInExportAndReports(): void
    {
        [$supplierId] = $this->convertedBeforeFix();
        $runs = new PohodaImportRepository($this->db);
        $runId = $runs->startRun($supplierId, null, 'import', ['ico' => SyntheticPohodaExport::ICO, 'year' => SyntheticPohodaExport::YEAR], $this->userId);
        $runs->finishRun($runId, $supplierId, 'completed', ['kind' => 'accounting', 'agenda' => ['skipped_years' => [], 'relink_only' => false],
            'steps' => [['key' => OtherItemImporter::STEP, 'status' => 'ok', 'counts' => ['payroll_or_tax' => 5], 'messages' => []]]]);

        $out = [];
        $print = static function (string $line) use (&$out): void { $out[] = $line; };
        self::assertSame(PohodaPayrollItemBackfill::EXIT_OK, $this->backfill->run($supplierId, null, false, null, $this->userId, $print));
        self::assertStringContainsString("agenda 2026: převod #{$runId}", implode("\n", $out));
        self::assertStringContainsString('vynecháno 5', implode("\n", $out));

        $out = [];
        self::assertSame(PohodaPayrollItemBackfill::EXIT_OK, $this->backfill->run($supplierId, $this->tmp, true, null, $this->userId, $print, '2026-12-31'));
        $text = implode("\n", $out);
        self::assertStringContainsString('Založeno s převzetím zápisu (bez nového účtování): 3', $text);
        self::assertStringContainsString('Založeno jako potvrzené bez zápisu: 2', $text);
        self::assertStringContainsString('Odsouhlasení mzdových účtů k 2026-12-31', $text);
        self::assertSame(5, $this->payrollItems($supplierId));
        self::assertSame(1, $this->rows('pohoda_imports', $supplierId, "id > {$runId} AND mode = 'import' AND JSON_EXTRACT(protocol, '$.payroll_backfill') = 'true'"),
            json_encode($this->pdo->query("SELECT id, mode, status, LEFT(protocol, 300) p FROM pohoda_imports WHERE supplier_id = {$supplierId}")->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE));

        $out = [];
        self::assertSame(PohodaPayrollItemBackfill::EXIT_OK, $this->backfill->run($supplierId, $this->tmp, false, null, $this->userId, $print, '2026-12-31'));
        self::assertStringContainsString('Už v agendě (dřívější převod nebo doplnění): 5', implode("\n", $out));
    }

    /**
     * Firma převedená před opravou: převod syntetického exportu s mzdovými závazky, po něm
     * odebrané mzdové položky (331, 336, 342), jejich úhrady a záznamy mapy. Zápisy deníku
     * zůstanou ruční a pohyb úhrady vyřízený bez dokladu.
     *
     * @return array{int,PohodaExport}
     */
    private function convertedBeforeFix(string $taxSymPar = ''): array
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withOtherItems($dir, true);
        SyntheticPohodaExport::withPayrollItems($dir);
        SyntheticPohodaExport::withPayrollTaxLiability($dir, $taxSymPar);
        $export = PohodaExport::open($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, $export, false);
        self::assertFalse($protocol->hasErrors(), (string) json_encode($protocol->toArray()['steps'], JSON_UNESCAPED_UNICODE));

        $ids = array_map('intval', $this->pdo->query("SELECT id FROM other_items WHERE supplier_id = {$supplierId}
            AND (account_code LIKE '331%' OR account_code LIKE '336%' OR account_code LIKE '342%')")->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(5, $ids, 'Převod po opravě zakládá pět mzdových závazků.');
        $in = implode(',', $ids);
        $tx = array_map('intval', $this->pdo->query("SELECT bank_transaction_id FROM other_item_allocations
            WHERE supplier_id = {$supplierId} AND other_item_id IN ({$in}) AND bank_transaction_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
        self::assertNotSame([], $tx);
        foreach ($ids as $id) {
            $this->pdo->exec("DELETE FROM pohoda_import_map WHERE supplier_id = {$supplierId} AND kind = 'payment' AND pohoda_key LIKE 'other_item|{$id}|%'");
        }
        $this->pdo->exec("DELETE FROM other_item_allocations WHERE supplier_id = {$supplierId} AND other_item_id IN ({$in})");
        $this->pdo->exec("UPDATE journal_entries SET source_type = 'manual', source_id = NULL
            WHERE supplier_id = {$supplierId} AND source_type = 'other_item' AND source_id IN ({$in})");
        $this->pdo->exec("DELETE FROM pohoda_import_map WHERE supplier_id = {$supplierId} AND kind = 'other_item' AND target_id IN ({$in})");
        $this->pdo->exec("DELETE FROM other_item_posting_lines WHERE supplier_id = {$supplierId} AND other_item_id IN ({$in})");
        $this->pdo->exec("DELETE FROM other_items WHERE supplier_id = {$supplierId} AND id IN ({$in})");
        $this->pdo->exec("UPDATE bank_transactions SET match_status = 'ignored', match_reason = 'pohoda_booked', matched_at = NOW(),
            ignore_note = 'Zaúčtováno v Pohodě bez dokladu (převod z POHODY).' WHERE id IN (" . implode(',', $tx) . ')');
        self::assertSame(0, $this->payrollItems($supplierId));
        return [$supplierId, $export];
    }

    /**
     * @param array<string,mixed> $protocol
     * @return list<string>
     */
    private function messageCodes(array $protocol, string $step): array
    {
        foreach ($protocol['steps'] as $s) {
            if ($s['key'] === $step) {
                return array_column($s['messages'], 'code');
            }
        }
        return [];
    }

    private function supplier(): int
    {
        $czId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currencyId = (int) $this->pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vatRateId = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }
        $this->pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $currencyId, $vatRateId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $this->pdo->lastInsertId(), $id]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function item(int $supplierId, string $number): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM other_items WHERE supplier_id = ? AND document_no = ?');
        $stmt->execute([$supplierId, $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, $number);
        return $row;
    }

    private function payrollItems(int $supplierId): int
    {
        return $this->rows('other_items', $supplierId, "(account_code LIKE '331%' OR account_code LIKE '336%' OR account_code LIKE '342%')");
    }

    /** @return array<string,mixed> počty a součty deníku firmy */
    private function journal(int $supplierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT e.id) AS entries, COUNT(l.id) AS line_count,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount END) AS debit,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount END) AS credit,
                    SUM(e.posted_at IS NOT NULL) AS posted
               FROM journal_entries e LEFT JOIN journal_entry_lines l ON l.entry_id = e.id
              WHERE e.supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function rows(string $table, int $supplierId, string $where = '1 = 1'): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
