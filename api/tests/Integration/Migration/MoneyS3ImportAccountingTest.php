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
final class MoneyS3ImportAccountingTest extends MoneyS3ImportTestCase
{
    public function testOpeningWithoutDimensionsIsNotAcceptedEvenWhenAccountTotalsMatch(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/no-opening-dimensions.lz', SyntheticAgenda::filesWithoutOpeningDimensions());
        $backup = Ms3Backup::extract($this->tmp . '/no-opening-dimensions.lz', $this->tmp . '/no-opening-dimensions');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $closing = array_column($protocol->get('closing'), null, 'year')[2024];
        self::assertSame('mismatch', $closing['status'], $this->explain($protocol));
        self::assertNotEmpty($closing['diffs']);
        self::assertSame('open', $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2024)['status']);
    }

    public function testImportedAccountingPreservesPeriodsBalancesAndClassification(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        $this->assertNewYearsDayEntryStaysInNewYear($supplierId, $protocol);
        $this->assertEntryDatedOutsideItsBookYearIsPostedAtPeriodBoundary($supplierId, $protocol);
        $this->assertSyntheticFromRetiredGroupTakesTypeFromAccountClass($supplierId, $protocol);
        $this->assertMoneyYearEndClosingIsNotImported($supplierId, $protocol);
        $this->assertUnusedPostingRulesAreTransferredInactive($supplierId, $protocol);
        $this->assertHistoricalYearClosesDespiteDocumentMoneyLeftUnposted($supplierId, $protocol);
        $this->assertUnpostedReviewDocumentFromHistoricalYearIsSkipped($supplierId, $protocol);
        $this->assertHistoricalYearIsClosedWithoutDoublingOpeningBalances($supplierId, $protocol);
        $this->assertAccountingModeIsRecordedInBothPlaces($supplierId, $protocol);
    }

    private function assertNewYearsDayEntryStaysInNewYear(int $supplierId, ImportProtocol $protocol): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no = 'ID25001'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['entry_date' => '2025-01-01', 'fiscal_year' => 2025], ['entry_date' => $row['entry_date'], 'fiscal_year' => (int) $row['fiscal_year']]);
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "entry_date = '2024-12-31' AND source_type <> 'closing'"),
            'Z deníku Money patří na 31. 12. 2024 jen ID24001 (uzávěrkový zápis roku je vlastní zápis MyÚčta).');
    }

    /**
     * Money vede v knize roku i doklad s datem z vedlejšího roku a počítá ho do obratů
     * toho roku. Zápis jde k prvnímu dni období, původní datum zůstává jako datum dokladu.
     */
    private function assertEntryDatedOutsideItsBookYearIsPostedAtPeriodBoundary(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date, e.document_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no = 'ID25002'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['entry_date' => '2025-01-01', 'document_date' => '2024-12-31', 'fiscal_year' => 2025],
            ['entry_date' => $row['entry_date'], 'document_date' => $row['document_date'], 'fiscal_year' => (int) $row['fiscal_year']]);
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertContains('entry_date_outside_year', array_column($journal['messages'], 'code'));
    }

    /**
     * Skupinu 61 osnova od roku 2016 nemá, šablona MyÚčta taky ne. Typ účtu se převezme
     * od sourozence ze stejné třídy (6 = výnosy), jinak by převod staré agendy skončil.
     */
    private function assertSyntheticFromRetiredGroupTakesTypeFromAccountClass(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_code, account_type FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('602', '613', '613.000') ORDER BY account_code"
        );
        $stmt->execute([$supplierId]);
        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $types[(string) $r['account_code']] = (string) $r['account_type'];
        }
        self::assertSame(['602', '613', '613.000'], array_map('strval', array_keys($types)));
        self::assertSame($types['602'], $types['613']);
        self::assertSame($types['602'], $types['613.000']);
    }

    /**
     * Uzávěrkové zápisy Money (zdroj XZ) převádějí konečné stavy na 702/710. Převzaté by
     * vynulovaly konečné stavy roku a uzávěrka MyÚčta by nesouhlasila s počátečními stavy
     * dalšího roku — rok by zůstal otevřený.
     */
    private function assertMoneyYearEndClosingIsNotImported(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status']);
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "description = 'Účetní závěrka roku 2024'"));
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertContains('year_end_closing_skipped', array_column($journal['messages'], 'code'));
        $closing = array_column($protocol->toArray()['steps'], null, 'key')['closing'];
        self::assertNotContains('closed_without_money_closing', array_column($closing['messages'], 'code'));
    }

    /**
     * Daňová uznatelnost nákladového účtu jde z osnovy Money (`Typ` 0 = nedaňový) — bez ní
     * DPPO ř. 40 nevidí nic. Účet, který Money vede jako daňový, zůstane daňový.
     */
    public function testChartDeductibilityIsTakenFromMoney(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/typ.lz', SyntheticAgenda::filesWithChartDeductibility());
        $backup = Ms3Backup::extract($this->tmp . '/typ.lz', $this->tmp . '/typ');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            "SELECT LEFT(c.account_code, 3), c.tax_deductibility FROM chart_of_accounts c
              WHERE c.supplier_id = ? AND LEFT(c.account_code, 3) IN ('518', '568')
                AND c.id IN (SELECT l.account_id FROM journal_entry_lines l WHERE l.supplier_id = c.supplier_id)
              ORDER BY c.account_code"
        );
        $stmt->execute([$supplierId]);
        self::assertSame(['518' => 'deductible', '568' => 'non_deductible'], $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Money otevře další rok i bez uzávěrky (XZ). Rok, jehož PS navazují, převod uzavře
     * dál (reálné agendy nemají XZ u většiny podaných let), ale upozorní, že uzávěrka
     * v Money neproběhla.
     */
    public function testYearClosedWithoutMoneyYearEndClosingIsReported(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/noxz.lz', SyntheticAgenda::filesWithoutYearEndClosing());
        $backup = Ms3Backup::extract($this->tmp . '/noxz.lz', $this->tmp . '/noxz');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status']);
        $closing = array_column($protocol->toArray()['steps'], null, 'key')['closing'];
        $warning = array_column($closing['messages'], null, 'code')['closed_without_money_closing'] ?? null;
        self::assertNotNull($warning, $this->explain($protocol));
        self::assertSame([2024], $warning['context']['years']);
    }

    /** Předkontace, kterou doklady posledních dvou let nepoužily, se převede vypnutá. */
    private function assertUnusedPostingRulesAreTransferredInactive(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('posting_rules', $supplierId, "rule_key = 'PV001' AND is_active = 1"));
        self::assertSame(1, $this->rowCount('posting_rules', $supplierId, "rule_key = 'PF001' AND is_active = 0"));
        $rules = array_column($protocol->toArray()['steps'], null, 'key')['posting_rules'];
        self::assertSame(2, $rules['counts']['inactive'] ?? 0);
    }

    /**
     * Money rok uzavřelo i s fakturou, kterou nezaúčtovalo. Průvodce uzávěrkou MyÚčta by
     * kvůli ní historický rok neuzavřel nikdy — u dokladů převzatých z Money proto uzavře
     * knihy s výjimkou a důvodem (audit + závěrkový balíček).
     */
    private function assertHistoricalYearClosesDespiteDocumentMoneyLeftUnposted(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status']);
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2024-099' AND status <> 'draft'"));
    }

    /**
     * Money dovolí ručně přepsat počáteční stavy, takže konečné stavy roku nemusí navazovat
     * na další rok. Uzávěrka MyÚčta takový rok neuzavře a s ním ani žádný pozdější — kontrola
     * před převodem to musí říct předem a doporučit rok, od kterého roky navazují.
     */
    public function testBrokenYearChainIsReportedWithSuggestedStartYear(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/reclass.lz', SyntheticAgenda::filesWithOpeningReclass());
        $backup = Ms3Backup::extract($this->tmp . '/reclass.lz', $this->tmp . '/reclass');

        $preflight = $this->importer->preflight($supplierId, $backup, AgendaInfo::fromBackup($backup), new ImportOptions());

        $byCode = array_column($preflight, null, 'code');
        self::assertSame('warning', $byCode['opening_chain_break']['level'] ?? null, json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['year' => 2024, 'next' => 2025], array_intersect_key($byCode['opening_chain_break']['context'], ['year' => 0, 'next' => 0]));
        self::assertEqualsWithDelta(100.0, $byCode['opening_chain_break']['context']['accounts']['211000'], 0.001);
        self::assertSame(2025, $byCode['suggested_from_year']['context']['from_year'] ?? null);

        $clean = $this->importer->preflight($supplierId, $this->backup(), AgendaInfo::fromBackup($this->backup()), new ImportOptions());
        self::assertNotContains('opening_chain_break', array_column($clean, 'code'));
    }

    /**
     * Náhled průvodce staví kontrolu před převodem z `meta.json` (náhled agendy a rozvrh
     * deníku z jobu nahrání). Dřív při každém otevření průvodce prošel deník každého roku
     * čtyřikrát, u gigabajtové agendy přes deset sekund. Výsledek je stejný jako ze zálohy
     * i po cestě přes JSON (celé částky bez desetinné části), a to bez deníků v záloze.
     */
    public function testPreflightFromMetaPreviewEqualsPreflightFromBackupWithoutReadingJournals(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/meta.lz', SyntheticAgenda::filesWithOpeningReclass());
        $backup = Ms3Backup::extract($this->tmp . '/meta.lz', $this->tmp . '/meta');
        $expected = $this->importer->preflight($supplierId, $backup, AgendaInfo::fromBackup($backup), new ImportOptions());
        self::assertContains('opening_chain_break', array_column($expected, 'code'));
        $roundTrip = static fn (array $data): array => (array) json_decode((string) json_encode($data), true);
        $preview = $roundTrip($this->importer->journalPreview($backup));
        $agenda = AgendaInfo::fromArray($roundTrip(AgendaInfo::fromBackup($backup)->toArray()));

        foreach ($backup->yearDirs() as $dir) {
            foreach (glob($dir . '/*.[Dd][Aa][Tt]') ?: [] as $table) {
                if (strcasecmp(pathinfo($table, PATHINFO_FILENAME), 'UcDenik') === 0) {
                    unlink($table);
                }
            }
        }

        self::assertSame($expected, $this->importer->preflight($supplierId, Ms3Backup::open($backup->dir()), $agenda, new ImportOptions(), $preview));
        // Jiné volby převodu než výchozí náhled z meta.json nepoužijí.
        $fromYear = $this->importer->preflight($supplierId, Ms3Backup::open($backup->dir()), $agenda,
            new ImportOptions(ImportOptions::MODE_DRY_RUN, true, null, [], [], false, 2025), $preview);
        self::assertContains('from_year_empty', array_column($fromYear, 'code'));
    }

    /**
     * Agenda vedená v Money jako daňová evidence má místo účetního deníku peněžní deník.
     * Kontrola před převodem ji nehlásí jako „záloha neobsahuje účetní deník" (uživatel by
     * hledal chybu v záloze), ale projde peněžní deník: deník, jehož řádky nevedou na doklady
     * banky a pokladny, převod zastaví s vysvětlením. Platí i pro náhled z meta.json.
     */
    public function testTaxRecordsAgendaIsCheckedNotRejectedAsMissingJournal(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/evidence.lz', SyntheticAgenda::filesWithTaxRecords());
        $backup = Ms3Backup::extract($this->tmp . '/evidence.lz', $this->tmp . '/evidence');
        $roundTrip = static fn (array $data): array => (array) json_decode((string) json_encode($data), true);

        foreach ([
            'záloha' => $this->importer->preflight($supplierId, $backup, AgendaInfo::fromBackup($backup), new ImportOptions()),
            'meta.json' => $this->importer->preflight($supplierId, $backup, AgendaInfo::fromArray($roundTrip(AgendaInfo::fromBackup($backup)->toArray())),
                new ImportOptions(), $roundTrip($this->importer->journalPreview($backup))),
        ] as $source => $preflight) {
            $byCode = array_column($preflight, null, 'code');
            $dump = $source . ': ' . (json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '');
            self::assertSame('error', $byCode['cashbook_orphan']['level'] ?? null, $dump);
            self::assertStringContainsString('peněžní deník', $byCode['cashbook_orphan']['message']);
            self::assertSame('info', $byCode['tax_evidence_kept']['level'] ?? null, $dump);
            self::assertArrayNotHasKey('no_journal', $byCode, $source);
            self::assertArrayNotHasKey('empty_year', $byCode, $source);
            self::assertArrayNotHasKey('switch_to_double_entry', $byCode, $source);
        }

        // Agenda bez účetního i peněžního deníku dál hlásí obecnou chybu.
        $files = SyntheticAgenda::files();
        unset($files['ROK.001/UcDenik.DAT'], $files['ROK.002/UcDenik.DAT']);
        SyntheticAgenda::writeLzFiles($this->tmp . '/empty.lz', $files);
        $empty = Ms3Backup::extract($this->tmp . '/empty.lz', $this->tmp . '/empty');
        $codes = array_column($this->importer->preflight($supplierId, $empty, AgendaInfo::fromBackup($empty), new ImportOptions()), 'code');
        self::assertContains('no_journal', $codes);
        self::assertNotContains('tax_evidence_kept', $codes);
    }

    /**
     * „Převést od roku": starší roky i jejich doklady se vynechají, první převedený rok
     * s počátečními stavy začíná 1. 1. a převod je bez chyb.
     */
    public function testFromYearSkipsOlderYearsAndTheirDocuments(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, 2025));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare('SELECT fiscal_year, starts_on FROM accounting_periods WHERE supplier_id = ? ORDER BY fiscal_year');
        $stmt->execute([$supplierId]);
        self::assertSame([['fiscal_year' => 2025, 'starts_on' => '2025-01-01']], array_map(
            static fn (array $r): array => ['fiscal_year' => (int) $r['fiscal_year'], 'starts_on' => (string) $r['starts_on']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number IN ('DF-2024-017', 'DF-2024-099')"));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV24001'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-020'"));
    }

    /**
     * Předvaha může sedět na haléř, a přesto výkaz nevyjde: účet, který mapa výkazů nezná
     * (syntetika založená podle osnovy Money), ve výkazech chybí. Rekonciliace to musí
     * odhalit a účet jmenovat.
     */
    public function testUnmappedAccountFailsBalanceSheetCheck(): void
    {
        $supplierId = $this->supplier();
        $this->db->pdo()->exec("DELETE FROM statement_account_map WHERE account_prefix = '325'");

        $protocol = $this->import($supplierId);

        $year2024 = array_column($protocol->get('reconciliation'), null, 'year')[2024];
        self::assertFalse(array_column($year2024['checks'], 'ok', 'key')['balance_sheet_balanced']);
        self::assertSame(['325'], array_column($year2024['unmapped_accounts'], 'account'));
    }

    /**
     * Doklad k ruční kontrole, který Money v historickém roce vůbec nezaúčtovalo, se nepřevádí —
     * v uzavřeném roce by jen visel jako koncept. V posledním roce (RC-2025-001) zůstává ke
     * kontrole. Zálohová faktura (ZF24001) koncept není: převede se jako nezaúčtovaná záloha.
     */
    private function assertUnpostedReviewDocumentFromHistoricalYearIsSkipped(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'RC-2024-001'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'RC-2025-001' AND status = 'draft'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'ZF-2024-001' AND document_kind = 'advance' AND booked_at IS NULL"));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['purchase_invoices']['counts']['unposted_review_skipped'] ?? 0);
        self::assertNotContains('ZF24001', array_column($protocol->get('orphans'), 'document_no'),
            'Zálohovou fakturu Money neúčtuje, zápis v deníku u ní převod nečeká.');
    }

    public function testMoneyCostCentreAndJobBecomeDimensions(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        // Vůz z knihy jízd se značkou zapsanou jinak než v Money (bez mezery).
        $pdo->prepare("INSERT INTO cars (supplier_id, registration, name) VALUES (?, '1AB2345', 'Dodávka')")->execute([$supplierId]);
        $carId = (int) $pdo->lastInsertId();

        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $enabled = $pdo->prepare('SELECT dimensions_enabled FROM supplier WHERE id = ?');
        $enabled->execute([$supplierId]);
        self::assertSame(1, (int) $enabled->fetchColumn(), 'Převod dimenzí sekci Dimenze zapne.');

        $values = $pdo->prepare(
            'SELECT t.kind, t.supplier_group_id, v.id, v.code, v.name, v.is_active, v.car_id, cc.code AS cost_center_code
               FROM dimension_values v
               JOIN dimension_types t ON t.id = v.type_id
          LEFT JOIN cost_centers cc ON cc.id = v.cost_center_id
              WHERE v.supplier_id = ?'
        );
        $values->execute([$supplierId]);
        $byCode = [];
        foreach ($values->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byCode[(string) $row['code']] = $row;
        }
        ksort($byCode);
        self::assertSame(['1AB 2345', 'REZIE', 'ZAK01', 'ZAK02'], array_keys($byCode));
        self::assertSame('cost_center', $byCode['REZIE']['kind']);
        self::assertSame('REZIE', $byCode['REZIE']['cost_center_code'], 'Středisko je navázané na číselník středisek.');
        self::assertSame('vehicle', $byCode['1AB 2345']['kind']);
        self::assertSame($carId, (int) $byCode['1AB 2345']['car_id'], 'Vozidlo je navázané na knihu jízd.');
        self::assertSame('1AB 2345 - Dodávka', $byCode['1AB 2345']['name']);
        self::assertSame('project', $byCode['ZAK01']['kind']);
        self::assertNull($byCode['ZAK01']['supplier_group_id'], 'Firma bez skupiny má projekt firemní.');
        self::assertSame(1, (int) $byCode['ZAK01']['is_active']);
        $projects = $pdo->prepare('SELECT COUNT(*) FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?');
        $projects->execute([$supplierId]);
        self::assertSame(0, (int) $projects->fetchColumn(), 'Zakázka z Money nezakládá fakturační zakázku.');

        $line = $pdo->prepare(
            "SELECT jel.cost_center, GROUP_CONCAT(v.code ORDER BY v.code SEPARATOR ',') AS dims
               FROM journal_entry_lines jel
               JOIN journal_entries je ON je.id = jel.entry_id
               JOIN chart_of_accounts a ON a.id = jel.account_id
          LEFT JOIN journal_entry_line_dimensions jd ON jd.line_id = jel.id
          LEFT JOIN dimension_values v ON v.id = jd.dimension_value_id
              WHERE je.supplier_id = ? AND je.document_no = ? AND a.account_code LIKE ?
              GROUP BY jel.id"
        );
        foreach ([['FP24001', '518%', 'REZIE,ZAK01'], ['PV24001', '501%', '1AB 2345,REZIE']] as [$doc, $account, $dims]) {
            $line->execute([$supplierId, $doc, $account]);
            $row = $line->fetch(PDO::FETCH_ASSOC);
            self::assertSame(['REZIE', $dims], [$row['cost_center'], $row['dims']], $doc);
        }

        $purchase = $pdo->prepare(
            "SELECT GROUP_CONCAT(v.code ORDER BY v.code SEPARATOR ',')
               FROM purchase_invoices pi
               JOIN document_dimensions d ON d.supplier_id = pi.supplier_id AND d.doc_type = 'purchase_invoice'
                AND d.doc_id = pi.id AND d.item_no = 0
               JOIN dimension_values v ON v.id = d.dimension_value_id
              WHERE pi.supplier_id = ? AND pi.vendor_invoice_number = 'DF-2024-017'"
        );
        $purchase->execute([$supplierId]);
        self::assertSame('REZIE,ZAK01', $purchase->fetchColumn(), 'Doklad přebírá dimenze ze svých řádků.');

        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status']);
        (new \MyInvoice\Repository\DimensionRepository($this->db))->updateValue($supplierId, (int) $byCode['ZAK01']['id'], ['is_active' => false]);
        $second = $this->import($supplierId);
        $step = array_column($second->toArray()['steps'], null, 'key')['dimensions'];
        self::assertSame(0, $step['counts']['lines'] ?? 0, 'Opakovaný převod dimenze nemění.');
        self::assertSame(0, $step['counts']['documents'] ?? 0);
        self::assertSame(4, $step['counts']['values_existing'] ?? 0);
        self::assertSame(0, $step['counts']['values_created'] ?? 0);
        self::assertNotContains('period_not_open', array_column($step['messages'], 'code'));
        self::assertTrue((new \MyInvoice\Repository\DimensionRepository($this->db))->findValue($supplierId, (int) $byCode['ZAK01']['id'])['is_active'], 'Money může znovu aktivovat historickou hodnotu beze změny její struktury.');
    }

    public function testMoneyDimensionsDoNotRewriteClosedYearFromChangedBackup(): void
    {
        $supplierId = $this->supplier();
        $first = $this->import($supplierId);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        self::assertSame('closed', array_column($first->get('closing'), null, 'year')[2024]['status']);
        $pdo = $this->db->pdo();
        $split = $pdo->prepare("INSERT INTO journal_entry_line_dimension_splits
            (line_id, dimension_type_id, dimension_value_id, supplier_id, share)
            SELECT l.id, v.type_id, v.id, l.supplier_id, 0.5
            FROM journal_entries je
            JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
            JOIN dimension_values v ON v.supplier_id = je.supplier_id AND v.code IN ('ZAK01', 'ZAK02')
            WHERE je.supplier_id = ? AND je.document_no = 'FP24001' AND je.entry_date < '2025-01-01' AND l.line_no = 1");
        $split->execute([$supplierId]);
        self::assertSame(2, $split->rowCount());
        $pdo->prepare('DELETE d FROM journal_entry_line_dimensions d
            JOIN journal_entry_line_dimension_splits s ON s.line_id = d.line_id AND s.dimension_type_id = d.dimension_type_id
            WHERE d.supplier_id = ?')->execute([$supplierId]);
        $before = $this->moneyDimensionSnapshot($supplierId);

        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithChangedDimensions());
        $second = $this->import($supplierId);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame($before, $this->moneyDimensionSnapshot($supplierId));
        $step = array_column($second->toArray()['steps'], null, 'key')['dimensions'];
        self::assertContains('period_not_open', array_column($step['messages'], 'code'));
    }

    public function testMoneyDimensionsStillUpdateOpenYear(): void
    {
        $supplierId = $this->supplier();
        $options = new ImportOptions(ImportOptions::MODE_IMPORT, true, fromYear: 2025);
        $first = $this->importer->run($supplierId, $this->userId, $this->backup(), $options);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $before = $this->moneyDimensionSnapshot($supplierId);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithChangedDimensions(2025));
        $second = $this->importer->run($supplierId, $this->userId, $this->backup(), $options);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertNotSame($before, $this->moneyDimensionSnapshot($supplierId));
        $step = array_column($second->toArray()['steps'], null, 'key')['dimensions'];
        self::assertGreaterThan(0, $step['counts']['lines']);
        self::assertNotContains('period_not_open', array_column($step['messages'], 'code'));
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = ? AND cost_center = 'NOVE1'");
        $stmt->execute([$supplierId]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn());
    }

    public function testMoneyDimensionsDoNotFillMissingClosedDocumentHeader(): void
    {
        $supplierId = $this->supplier();
        $first = $this->import($supplierId);
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $pdo = $this->db->pdo();
        $delete = $pdo->prepare("DELETE dd FROM document_dimensions dd
            JOIN purchase_invoices pi ON pi.id = dd.doc_id AND pi.supplier_id = dd.supplier_id
            WHERE dd.supplier_id = ? AND dd.doc_type = 'purchase_invoice' AND pi.vendor_invoice_number = 'DF-2024-017'");
        $delete->execute([$supplierId]);
        self::assertGreaterThan(0, $delete->rowCount());
        $before = $this->moneyDimensionSnapshot($supplierId);
        $second = $this->import($supplierId);
        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame($before, $this->moneyDimensionSnapshot($supplierId));
        $step = array_column($second->toArray()['steps'], null, 'key')['dimensions'];
        self::assertContains('period_not_open', array_column($step['messages'], 'code'));
    }

    private function moneyDimensionSnapshot(int $supplierId): array
    {
        $out = [];
        foreach ([
            'lines' => 'SELECT l.id, l.cost_center FROM journal_entry_lines l WHERE l.supplier_id = ? ORDER BY l.id',
            'dimensions' => 'SELECT d.* FROM journal_entry_line_dimensions d WHERE d.supplier_id = ? ORDER BY d.line_id, d.dimension_type_id',
            'splits' => 'SELECT d.* FROM journal_entry_line_dimension_splits d WHERE d.supplier_id = ? ORDER BY d.line_id, d.dimension_type_id, d.dimension_value_id',
            'documents' => 'SELECT d.* FROM document_dimensions d WHERE d.supplier_id = ? ORDER BY d.doc_type, d.doc_id, d.item_no, d.dimension_type_id',
        ] as $key => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId]);
            $out[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    public function testMoneyJobIsGlobalProjectOfSupplierGroup(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO supplier_groups (name) VALUES ('Syntetická skupina')")->execute();
        $groupId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET supplier_group_id = ? WHERE id = ?')->execute([$groupId, $supplierId]);

        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $types = $pdo->prepare(
            'SELECT kind, supplier_id, supplier_group_id FROM dimension_types
              WHERE supplier_id = ? OR supplier_group_id = ? ORDER BY kind'
        );
        $types->execute([$supplierId, $groupId]);
        $byKind = [];
        foreach ($types->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $byKind[$t['kind']] = $t;
        }
        self::assertSame($groupId, (int) $byKind['project']['supplier_group_id'], 'Projekt firmy ve skupině je globální.');
        self::assertNull($byKind['project']['supplier_id']);
        self::assertSame($supplierId, (int) $byKind['cost_center']['supplier_id'], 'Středisko zůstává firemní.');
        self::assertSame($supplierId, (int) $byKind['vehicle']['supplier_id'], 'Vozidlo zůstává firemní.');
        $global = $pdo->prepare('SELECT COUNT(*) FROM dimension_values WHERE supplier_group_id = ?');
        $global->execute([$groupId]);
        self::assertSame(2, (int) $global->fetchColumn(), 'ZAK01 a ZAK02 jsou hodnoty skupiny.');
    }

    public function testAssetRegisterBecomesAssetCardsWithMigratedDepreciation(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAssets());
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['assets']['counts']['created'] ?? 0, $this->explain($protocol));
        self::assertSame(1, $steps['assets']['counts']['helper_cards'] ?? 0, 'Pomocná karta bez majetkového účtu se nepřevádí.');
        self::assertContains('ledger_match', array_column($steps['assets']['messages'], 'code'), 'Karta sedí na 022 a 082.');

        $pdo = $this->db->pdo();
        $card = $pdo->prepare('SELECT * FROM assets WHERE supplier_id = ?');
        $card->execute([$supplierId]);
        $assets = $card->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $assets);
        $a = $assets[0];
        self::assertSame(['DM-001', 'in_use', 'straight', 2, '022.100', '082.100', '2023-07-01'],
            [$a['inventory_number'], $a['status'], $a['tax_method'], (int) $a['tax_group'], $a['asset_account_code'], $a['accumulated_account_code'], $a['put_into_use_date']]);
        self::assertEqualsWithDelta(120000.0, (float) $a['input_price'], 0.001);
        // Účetně před převodem srpen až prosinec 2023, daňově první rok 2023 (11 % ve 2. skupině).
        self::assertSame([5, 10000.0, 1, 13200.0, 60],
            [(int) $a['opening_acc_months'], (float) $a['opening_acc_amount'], (int) $a['opening_tax_years'], (float) $a['opening_tax_amount'], (int) $a['acc_useful_life_months']]);

        $entries = $pdo->prepare('SELECT kind, fiscal_year, amount, status, detail FROM depreciation_entries WHERE asset_id = ? ORDER BY kind, fiscal_year');
        $entries->execute([(int) $a['id']]);
        $rows = array_map(static fn (array $r): array => [$r['kind'], (int) $r['fiscal_year'], (float) $r['amount'], $r['status']], $entries->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame([
            // Daňový odpis 2. roku 22,25 %; otevřený rok 2025 daňový řádek zatím nemá.
            ['tax', 2024, 26700.0, 'confirmed'],
            ['accounting', 2024, 24000.0, 'posted'],
            ['accounting', 2025, 24000.0, 'posted'],
        ], $rows);
        $entries->execute([(int) $a['id']]);
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['kind'] === 'accounting') {
                self::assertTrue(\MyInvoice\Repository\DepreciationEntryRepository::isBookedByMigratedJournal($r), 'Odpis je v převzatém deníku, znovu se neúčtuje.');
            }
        }

        $small = $pdo->prepare('SELECT inventory_number, status, price, location, disposed_at FROM small_assets WHERE supplier_id = ? ORDER BY inventory_number');
        $small->execute([$supplierId]);
        self::assertSame([
            ['DR-001', 'in_use', 15000.0, 'Kancelář Brno', null],
            ['DR-002', 'disposed', 8000.0, null, '2025-02-01'],
        ], array_map(static fn (array $r): array => [$r['inventory_number'], $r['status'], (float) $r['price'], $r['location'], $r['disposed_at']], $small->fetchAll(PDO::FETCH_ASSOC)));

        $second = $this->import($supplierId);
        $steps = array_column($second->toArray()['steps'], null, 'key');
        self::assertSame(1, $steps['assets']['counts']['existing'] ?? 0);
        self::assertSame(2, $steps['small_assets']['counts']['existing'] ?? 0);
        self::assertSame(1, $this->rowCount('assets', $supplierId));
        self::assertSame(2, $this->rowCount('small_assets', $supplierId));
    }

    /**
     * Daňové zvláštnosti evidence Money: odpis zůstatkové ceny při vyřazení není účetní odpis,
     * pomocná karta nese daňové odpisy karty „jen ÚČETNÍ", skupina z číselníku `FL_LGMajSk`,
     * mimořádný odpis bezemisního vozidla a daňový odpis rovný účetnímu u hmotného majetku.
     */
    public function testAssetTaxCasesFromMoney(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAssetTaxCases());
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame(2, $steps['assets']['counts']['helper_cards'] ?? 0, $this->explain($protocol));
        self::assertContains('helper_card_paired', array_column($steps['assets']['messages'], 'code'), 'Pomocná karta 7 patří k hale 6.');

        $cards = $this->assetsByInventory($supplierId);
        self::assertArrayNotHasKey('DM-006 DAŇ.', $cards, 'Pomocná karta se nepřevádí.');

        // Auto: odpis zůstatkové ceny 146 000 Kč ke dni vyřazení účetním odpisem není; daňově půlodpis 2. roku.
        $car = $cards['DM-005'];
        self::assertSame(['disposed', '2024-10-15', 'straight', 2], [$car['status'], $car['disposal_date'], $car['tax_method'], (int) $car['tax_group']]);
        self::assertSame([27000.0, 22000.0], [(float) $car['opening_acc_amount'], (float) $car['opening_tax_amount']]);
        self::assertSame(['accounting' => [2024 => 27000.0], 'tax' => [2024 => 22250.0]], $this->entries((int) $car['id']));

        // Hala „jen ÚČETNÍ": daňově podle pomocné karty (vstupní cena 1,1 mil. Kč, 5. sk. z číselníku,
        // odpis 2023 z pohybu D), účetně podle vlastních odpisů.
        $hall = $cards['DM-006'];
        self::assertSame(['in_use', 'accelerated', 5, 1, 40000.0, 30000.0], [$hall['status'], $hall['tax_method'], (int) $hall['tax_group'],
            (int) $hall['opening_tax_years'], (float) $hall['opening_tax_amount'], (float) $hall['opening_acc_amount']]);
        self::assertEqualsWithDelta(1300000.0, (float) $hall['input_price'], 0.001);
        self::assertStringContainsString('pomocné karty Money č. 7', (string) $hall['description']);
        // 2. rok zrychleně: 2 × (1 100 000 − 40 000) / (31 − 1).
        self::assertSame(['accounting' => [2024 => 60000.0, 2025 => 60000.0], 'tax' => [2024 => 70667.0]], $this->entries((int) $hall['id']));

        // Stroj bez OdpisSkupi: 3. skupina z číselníku, rovnoměrně 5,5 % a 10,5 %.
        $machine = $cards['DM-008'];
        self::assertSame(['straight', 3, 16500.0], [$machine['tax_method'], (int) $machine['tax_group'], (float) $machine['opening_tax_amount']]);
        self::assertSame(31500.0, $this->entries((int) $machine['id'])['tax'][2024] ?? null);

        // Elektromobil: mimořádné odpisy §30a, 60 % za prvních 12 měsíců od dubna 2024.
        $ev = $cards['DM-009'];
        self::assertSame(['extraordinary', null, 1, 1], [$ev['tax_method'], $ev['tax_group'], (int) $ev['is_zero_emission'], (int) $ev['is_first_owner']]);
        self::assertSame(450000.0, $this->entries((int) $ev['id'])['tax'][2024] ?? null);

        // FVE s daňovým odpisem rovným účetnímu: 7 měsíců × měsíční odpis 1 000 Kč, karta bez daňové metody.
        $pv = $cards['DM-010'];
        self::assertSame('none', $pv['tax_method']);
        self::assertSame(['accounting' => [2024 => 6500.0, 2025 => 12000.0], 'tax' => [2024 => 7000.0]], $this->entries((int) $pv['id']));
    }

    public function testDisposalYearTaxDepreciationCanBeSkipped(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAssetTaxCases());
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_NONE));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $car = $this->assetsByInventory($supplierId)['DM-005'];
        self::assertSame(['accounting' => [2024 => 27000.0]], $this->entries((int) $car['id']), 'V roce vyřazení bez daňového odpisu.');
        self::assertSame(22000.0, (float) $car['opening_tax_amount']);
    }

    /** Odpis zůstatku u karty, která zůstala v užívání, rekonciliace majetku ohlásí. */
    public function testResidualWriteOffOnCardInUseIsReported(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAssetTaxCases(true));
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], []));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $found = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] as $m) {
                if ($m['code'] === 'residual_writeoff_in_use') {
                    $found[] = [$m['level'], $m['context']['document_no'] ?? null];
                }
            }
        }
        self::assertSame([['warning', '8']], $found, $this->explain($protocol));
        self::assertSame('in_use', $this->assetsByInventory($supplierId)['DM-008']['status']);
    }

    private function assertHistoricalYearIsClosedWithoutDoublingOpeningBalances(int $supplierId, ImportProtocol $protocol): void
    {
        $closing = array_column($protocol->get('closing'), null, 'year');
        self::assertSame('closed', $closing[2024]['status'], $this->explain($protocol));
        self::assertSame('open', $closing[2025]['status']);

        $period2025 = $this->periodId($supplierId, 2025);
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "source_type = 'opening' AND period_id = {$period2025}"));
        $tb = $this->container(TrialBalanceService::class)->build($supplierId, $period2025, null, null, false);
        $rows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(8250.0, $rows['431']['ps_d'], 0.001);
        self::assertEqualsWithDelta(62150.0, $rows['221']['ps_md'], 0.001);
        self::assertTrue($tb['checks']['opening_balanced']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT status FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = 2024"
        );
        $stmt->execute([$supplierId]);
        self::assertSame('closed', $stmt->fetchColumn());
    }

    /**
     * Uzavření knih a otevření dalšího roku jsou dva kroky průvodce. Selže-li otevření
     * (nebo kontrola po něm), rok zůstane uzavřený s nedokončeným „open_next" — další
     * běh ho nesmí přeskočit jako „už uzavřený", jinak se další rok nikdy neotevře.
     */
    public function testClosedYearWithPendingOpenNextIsFinishedOnRerun(): void
    {
        $supplierId = $this->supplier();
        $this->import($supplierId);
        $period2024 = $this->periodId($supplierId, 2024);
        $period2025 = $this->periodId($supplierId, 2025);
        $this->db->pdo()->prepare(
            "UPDATE accounting_closing_steps SET status = 'pending', done_at = NULL
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        )->execute([$supplierId, $period2024]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $closing = array_column($protocol->get('closing'), null, 'year');
        self::assertSame('next_opened', $closing[2024]['status']);
        $step = $this->db->pdo()->prepare(
            "SELECT status FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'open_next'"
        );
        $step->execute([$supplierId, $period2024]);
        self::assertSame('done', $step->fetchColumn());
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "source_type = 'opening' AND period_id = {$period2025}"));
    }

    /** Pozdější záznam daňové evidence v historii režimů nesmí převedené roky vrátit do DE. */
    public function testLaterTaxEvidenceRecordDoesNotOverrideConvertedYears(): void
    {
        $supplierId = $this->supplier();
        $modes = $this->container(AccountingModeRepository::class);
        $modes->record($supplierId, '2025-06-01', 'tax_evidence');

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('double_entry', $modes->forYear($supplierId, 2025));
    }

    private function assertAccountingModeIsRecordedInBothPlaces(int $supplierId, ImportProtocol $protocol): void
    {
        $s = $this->db->pdo()->prepare('SELECT accounting_mode, accounting_enabled, accounting_starts_on FROM supplier WHERE id = ?');
        $s->execute([$supplierId]);
        self::assertSame(['accounting_mode' => 'double_entry', 'accounting_enabled' => 1, 'accounting_starts_on' => '2024-01-01'],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $s->fetch(PDO::FETCH_ASSOC)));
        $h = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = ?');
        $h->execute([$supplierId, '2024-01-01']);
        self::assertSame('double_entry', $h->fetchColumn());
    }

    public function testDifferenceAgainstMoneyReportIsReported(): void
    {
        $supplierId = $this->supplier();
        file_put_contents($this->tmp . '/chybna.csv', str_replace('10 300,00;0,00', '10 305,00;0,00', SyntheticAgenda::trialBalanceCsv2024()));
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN, [2024 => $this->tmp . '/chybna.csv']);

        $year = $protocol->get('reconciliation')[0];
        self::assertFalse($year['ok']);
        self::assertSame(['518'], array_column($year['money_report']['diffs'], 'account'));
        self::assertTrue($protocol->hasErrors());
        // Rozdíl předvahy nad korunu je rozdíl k přijetí, ne chyba.
        self::assertTrue($protocol->toArray()['acceptable_only'], $this->explain($protocol));
    }

    /** Rozdíl předvahy do koruny včetně je zaokrouhlení: upozornění se seznamem účtů, K1 sedí. */
    public function testRoundingAgainstMoneyReportIsAWarning(): void
    {
        $supplierId = $this->supplier();
        file_put_contents($this->tmp . '/zaokrouhleni.csv', str_replace('10 300,00;0,00', '10 301,00;0,00', SyntheticAgenda::trialBalanceCsv2024()));
        $protocol = $this->import($supplierId, ImportOptions::MODE_DRY_RUN, [2024 => $this->tmp . '/zaokrouhleni.csv']);

        $year = $protocol->get('reconciliation')[0];
        self::assertTrue($year['ok'], $this->explain($protocol));
        self::assertSame(['518'], array_column($year['money_report']['diffs'], 'account'));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $rounding = array_values(array_filter(array_merge(...array_column($protocol->toArray()['steps'], 'messages')),
            static fn (array $m): bool => $m['code'] === 'rounding_difference'));
        self::assertCount(1, $rounding);
        self::assertSame('warning', $rounding[0]['level']);
        self::assertSame([['account' => '518', 'difference' => -1.0]], $rounding[0]['context']['accounts']);
    }
}
