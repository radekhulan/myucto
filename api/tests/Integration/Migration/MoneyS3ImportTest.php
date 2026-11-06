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
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
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
final class MoneyS3ImportTest extends MoneyS3ImportTestCase
{
    public function testRoundTripReconcilesToTheCent(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId, ImportOptions::MODE_IMPORT, [2024 => $this->tmp . '/predvaha-2024.csv']);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $reconciliation = $protocol->get('reconciliation');
        self::assertCount(2, $reconciliation);
        foreach ($reconciliation as $year) {
            self::assertTrue($year['ok'], "Rok {$year['year']} nesedí: " . json_encode($year, JSON_UNESCAPED_UNICODE));
            self::assertSame([], $year['journal_diffs']);
        }
        $checks2024 = array_column($reconciliation[0]['checks'], 'ok', 'key');
        self::assertTrue($checks2024['money_report'], 'Předvaha z Money (ručně spočtená) musí sedět na haléř.');

        // 2024: 4 řádky XP → 1 otevírací zápis (nulový a degenerovaný vypadnou),
        // 7 dokladů; smazaný doklad FP24099 se nepřenese.
        self::assertSame(8, $this->rowCount('journal_entries', $supplierId, "YEAR(entry_date) = 2024 AND source_type <> 'closing'"));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "document_no = 'FP24099'"));
        // Zálohová ZF24001 se převede (nezaúčtovaná), koncept RC-2024-001 z uzavřeného roku ne;
        // přibyla licence z EU FP25005 se samovyměřením.
        self::assertSame(12, $this->rowCount('purchase_invoices', $supplierId));
        // Tři vydané faktury a ostatní pohledávka PH25001, která je v přiznání DPH.
        self::assertSame(4, $this->rowCount('invoices', $supplierId));
        self::assertSame(4, $this->rowCount('cash_documents', $supplierId));
        // Šest partnerů z adresáře a dokladů; zakázky z Money jsou dimenze, klienta nezakládají.
        self::assertSame(7, $this->rowCount('clients', $supplierId));
        // Dva záznamy adresáře se stejným IČO (s vodicími nulami i bez) = jedna karta, doplněná z obou.
        self::assertSame(1, $this->rowCount('clients', $supplierId,
            "ic = '00012346' AND main_email = 'obec@example.invalid' AND street = 'Náměstí 1'"));
        // Značka člena skupiny DPH není DIČ: karta DIČ nemá, ale je plátcem.
        self::assertSame(1, $this->rowCount('clients', $supplierId, "ic = '00087650' AND dic IS NULL AND is_vat_payer = 1"));
        // Zahraniční partner má zemi podle DIČ — jinak by dodání do EU chybělo v souhrnném hlášení.
        self::assertSame(1, $this->rowCount('clients', $supplierId,
            "dic = 'DE123456789' AND country_id = (SELECT id FROM countries WHERE iso2 = 'DE')"));
        // Identifikátor, který není DIČ z EU (rejstříkové číslo), zemi neurčí — tu dá adresář Money.
        self::assertSame(1, $this->rowCount('clients', $supplierId,
            "dic = 'FN123456A' AND country_id = (SELECT id FROM countries WHERE iso2 = 'AT')"));
        // Údaj s číslicemi v poli státu (rodné číslo) zemí není: partner je tuzemský a obsah
        // pole se nevypisuje do protokolu jako neznámý stát.
        $codes = [];
        foreach ($protocol->toArray()['steps'] ?? [] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $codes[] = $m['code'];
            }
        }
        self::assertNotContains('country_unknown', $codes, $this->explain($protocol));
        self::assertSame(2, $this->rowCount('payment_matches', $supplierId));
        // Čárový kód z Money (BarCode) je klíč pro připojení naskenovaných příloh.
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "external_barcode = '90000101'"));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "external_barcode = '90000201'"));
        // FP24002 Money nezaúčtovalo — doklad je, zápis v deníku ne.
        self::assertSame([['purchase_invoice', 2024, 'FP24002']],
            array_map(static fn (array $o): array => [$o['type'], $o['year'], $o['document_no']], $protocol->get('orphans')));
        // DZ25001 Money účtuje jen 343/314 a FP25002 placenou kartou včetně úhrady (321 nulový) —
        // do kontroly dokladů proti 321 nepatří, vykážou se zvlášť.
        $documents2025 = array_column($reconciliation[1]['documents'], null, 'key');
        self::assertSame(2, $documents2025['purchase_invoices']['other_accounts']);

        $tb = $this->container(TrialBalanceService::class)->build($supplierId, $this->periodId($supplierId, 2024), null, null, false);
        $rows = array_column($tb['rows'], null, 'account_code');
        self::assertEqualsWithDelta(62150.0, $rows['221']['ks_md'], 0.001);
        self::assertEqualsWithDelta(20000.0, $rows['602']['turnover_d'], 0.001);
    }

    public function testImportedDocumentsPreserveNumberingPaymentsAndBankLinks(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        $this->assertRepeatedMoneyNumberingAcrossYearsDoesNotAbortImport($supplierId, $protocol);
        $this->assertMoneyPaymentMethodIsKeptOnPurchaseInvoice($supplierId, $protocol);
        $this->assertDeletedInvoiceWithReusedNumberIsSkipped($supplierId, $protocol);
        $this->assertNegativeCashReceiptBecomesExpenseAndZeroIsSkipped($supplierId, $protocol);
        $this->assertImportedBankStatementsAreMonthlyWithBalances($supplierId, $protocol);
        $this->assertBankEntriesCarryTransactionIdAndStatementSourceIsImport($supplierId, $protocol);
        $this->assertSameBankDocumentNumberOnTwoAccountsIsImportedTwice($supplierId, $protocol);
        $this->assertBankTransactionsBookedWithoutInvoiceAreResolved($supplierId, $protocol);
    }

    /**
     * Money čísluje řadu každý rok od začátku. Faktura FP24001 z roku 2025 narazí na
     * unikátní číslo dokladu z roku 2024 — nesmí shodit celý krok přijatých faktur
     * (a s ním pokladnu, banku, vazby a uzávěrku).
     */
    private function assertRepeatedMoneyNumberingAcrossYearsDoesNotAbortImport(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001' AND vendor_invoice_number = 'DF-2024-017'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "varsymbol = 'FP24001/2025' AND vendor_invoice_number = 'DF-2025-020'"));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        foreach (['purchase_invoices', 'cash', 'bank', 'link', 'payments', 'closing'] as $key) {
            self::assertNotSame('error', $steps[$key]['status'] ?? 'missing', "Krok {$key} nesmí selhat.");
        }
    }

    private function assertMoneyPaymentMethodIsKeptOnPurchaseInvoice(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-010' AND payment_method = 'card'"));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003' AND payment_method = 'bank_transfer'"));
    }

    /**
     * Smazaná faktura zůstává v souboru Money s příznakem `FlagDel` a její číslo řada
     * přidělí znovu. Převod ji nesmí vzít jako druhý doklad téhož čísla — mapa by hlásila
     * konflikt a zastavila celý převod.
     */
    private function assertDeletedInvoiceWithReusedNumberIsSkipped(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003'"));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'SMAZ-2025-001'"));
    }

    /**
     * Záporný pokladní příjem je vratka (peníze odešly) — jde jako výdej, jinak by pokladna
     * nesouhlasila s deníkem. Nulový doklad MyÚčto nepřijme a v pokladně nemá účinek.
     */
    private function assertNegativeCashReceiptBecomesExpenseAndZeroIsSkipped(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "doc_number = 'PP25001' AND doc_type = 'out' AND total_amount = 200"));
        self::assertSame(0, $this->rowCount('cash_documents', $supplierId, "doc_number = 'PP25002'"));
        $cash = array_column($protocol->toArray()['steps'], null, 'key')['cash'];
        self::assertSame(1, $cash['counts']['zero_amount'] ?? 0);
    }

    /**
     * Pohyby účtu se převedou do měsíčních výpisů (Money čísluje výpisy po dnech) a každý
     * nese počáteční a konečný stav — začátek roku z otevíracího zápisu účtu banky v deníku,
     * dál po pohybech. Převzatý výpis je výpis se zůstatkem: vidí ho záložka Stavy na účtech
     * a jde z něj vytvořit GPC.
     */
    private function assertImportedBankStatementsAreMonthlyWithBalances(int $supplierId, ImportProtocol $protocol): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, statement_number, currency, prev_balance, curr_balance FROM bank_statements
              WHERE supplier_id = ? AND statement_number LIKE 'BU/2024/%' ORDER BY statement_date, id"
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['BU/2024/02', 'BU/2024/03', 'BU/2024/06'], array_column($rows, 'statement_number'));
        self::assertSame(
            [[50000.0, 37900.0], [37900.0, 62100.0], [62100.0, 62150.0]],
            array_map(static fn (array $r): array => [(float) $r['prev_balance'], (float) $r['curr_balance']], $rows)
        );
        self::assertSame('CZK', $rows[0]['currency']);

        $snapshot = $this->container(\MyInvoice\Service\Bank\StatementBalanceService::class)->snapshot($supplierId, (int) $rows[1]['id']);
        self::assertSame('confirmed', $snapshot['status'], json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertEqualsWithDelta(62100.0, (float) $snapshot['closing'], 0.001);
        self::assertStringStartsWith('074', $this->container(\MyInvoice\Service\Bank\GpcExporter::class)->export($snapshot));
        // Reference platby kartou z pole VS v Money není variabilní symbol: zůstane v popisu
        // a výpis s ní jde do GPC (pevné pole VS má 10 číslic).
        $tx = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ? AND bank_ref = 'BP24003'
                AND variable_symbol IS NULL AND description LIKE '%ref. 955000000000017%'"
        );
        $tx->execute([(int) $rows[2]['id']]);
        self::assertSame(1, (int) $tx->fetchColumn());
        $juneSnapshot = $this->container(\MyInvoice\Service\Bank\StatementBalanceService::class)->snapshot($supplierId, (int) $rows[2]['id']);
        self::assertStringStartsWith('074', $this->container(\MyInvoice\Service\Bank\GpcExporter::class)->export($juneSnapshot));
        self::assertSame(1, $this->rowCount('currencies', $supplierId, "code = 'CZK' AND account_number = '3000000004'"),
            'Účet firmy se bere z posledního roku agendy (při shodě první účet).');
        // Účet je v evidenci účtů firmy s analytikou podle Money (PrimUcet 221001 → 221.001),
        // jinak záložka Kontace zůstane prázdná a pohyby nemají kam se zaúčtovat.
        self::assertSame(1, $this->rowCount('supplier_bank_accounts', $supplierId,
            "account_number = '3000000004' AND analytic_suffix = '001' AND label = 'Běžný účet' AND currency = 'CZK'"));
    }

    public function testForeignAccountOpeningIsAnchoredToLedger(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/foreign.lz', SyntheticAgenda::filesWithForeignAccount());
        $backup = Ms3Backup::extract($this->tmp . '/foreign.lz', $this->tmp . '/foreign');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            "SELECT statement_number, currency, prev_balance, curr_balance FROM bank_statements
              WHERE supplier_id = ? AND statement_number LIKE 'BE/%' ORDER BY statement_date, id"
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['BE/2024/05', 'BE/2025/03'], array_column($rows, 'statement_number'));
        self::assertSame('EUR', $rows[0]['currency']);
        // 100 EUR z doby před zálohou + 40 EUR v roce 2024 − 20 EUR v roce 2025.
        self::assertSame(
            [[100.0, 140.0], [140.0, 120.0]],
            array_map(static fn (array $r): array => [(float) $r['prev_balance'], (float) $r['curr_balance']], $rows)
        );
        self::assertSame(1, $this->rowCount('supplier_bank_accounts', $supplierId,
            "account_number = '5000000003' AND analytic_suffix = '003' AND currency = 'EUR' AND label = 'Devizový účet'"));
    }

    /**
     * Money může u korunové pokladny a účtu vést měnu jako „Kč". `strtoupper` z „Kč" neudělá
     * „KČ", takže by se pokladna i účet vzaly jako valutové v měně „Kč". Zůstanou korunové.
     */
    public function testCurrencyWrittenAsKcStaysCzk(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/kc.lz', SyntheticAgenda::filesWithCurrencyWrittenAsKc());
        $backup = Ms3Backup::extract($this->tmp . '/kc.lz', $this->tmp . '/kc');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        self::assertGreaterThan(0, $this->rowCount('cash_registers', $supplierId));
        self::assertSame(0, $this->rowCount('cash_registers', $supplierId, "currency_code <> 'CZK'"));
        self::assertGreaterThan(0, $this->rowCount('bank_statements', $supplierId));
        self::assertSame(0, $this->rowCount('bank_statements', $supplierId, "currency <> 'CZK'"));
        self::assertSame(0, $this->rowCount('supplier_bank_accounts', $supplierId, "currency <> 'CZK'"));
        self::assertSame(0, $this->rowCount('journal_entry_lines', $supplierId, 'currency_code IS NOT NULL'));
    }

    /**
     * Valutové pokladny z Money vzniknou ve své měně, doklady nesou kurz a částku ve valutě
     * (korunová částka z Money) a řádky deníku na účtu pokladny měnu - jinak uzávěrka
     * pokladnu kurzově nepřecení. Počáteční stav nese měnu jen v prvním roce účtu.
     */
    public function testForeignCashRegistersCarryCurrency(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->foreignCashBackup();
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $this->assertForeignCash($supplierId);
        // Rok 2024 i 2025: měnu nese otevírací zápis 2024 (z PocStav), 2025 už ne.
        self::assertSame([['2024-01-01', 'EUR', 25.0, 100.0], ['2025-04-10', 'EUR', 24.35, 289.2]], $this->foreignLines($supplierId, '211.002'));
        // Bez měny: otevírací zápis 2025 a uzávěrkový zápis 2024 (uzávěrka MyÚčta, v Kč).
        self::assertSame(2, $this->rowCount('journal_entry_lines', $supplierId,
            "currency_code IS NULL AND account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = journal_entry_lines.supplier_id AND account_code = '211.002')"));
        self::assertSame([['2024-01-01', 'HUF', 0.062, 50000.0], ['2025-04-12', 'HUF', 0.06121, 10120.0]], $this->foreignLines($supplierId, '211.003'));
        self::assertSame(0, $this->rowCount('journal_entry_lines', $supplierId,
            "currency_code IS NOT NULL AND account_id NOT IN (SELECT id FROM chart_of_accounts WHERE supplier_id = journal_entry_lines.supplier_id AND account_code IN ('211.002', '211.003'))"));
        $this->assertRevaluationProposals($supplierId);

        // Pokladny číslují vlastními řadami z Money a navazují na jeho čítač.
        $series = $this->db->pdo()->prepare(
            "SELECT CONCAT(r.account_code, ' ', s.series_code, ' ', s.fiscal_year, ' ', s.prefix, ' ', s.number_format, ' ', s.next_number)
               FROM accounting_document_series s JOIN cash_registers r ON r.id = s.register_id AND r.supplier_id = s.supplier_id
              WHERE s.supplier_id = ? AND r.own_series = 1 ORDER BY r.account_code, s.series_code"
        );
        $series->execute([$supplierId]);
        self::assertSame([
            '211.000 cash_in 2025 PP {PREFIX}{YY}{CCC} 3',
            '211.000 cash_out 2025 PV {PREFIX}{YY}{CCC} 3',
            '211.002 cash_in 2025 CPE {PREFIX}{YY}{CCCC} 4',
            '211.002 cash_out 2025 CVE {PREFIX}{YY}{CCCC} 1',
            '211.003 cash_in 2025 CPH {PREFIX}{YY}{CCCC} 1',
            '211.003 cash_out 2025 CVH {PREFIX}{YY}{CCCC} 2',
        ], $series->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, $this->rowCount('accounting_document_series', $supplierId, "register_id = 0 AND series_code IN ('cash_in', 'cash_out')"));

        // Opakovaný převod nic nezdvojí ani nepřepíše.
        $again = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $series->execute([$supplierId]);
        self::assertCount(6, $series->fetchAll(PDO::FETCH_COLUMN));
        $this->assertForeignCash($supplierId);
        self::assertSame([['2024-01-01', 'EUR', 25.0, 100.0], ['2025-04-10', 'EUR', 24.35, 289.2]], $this->foreignLines($supplierId, '211.002'));
    }

    /**
     * Firma převedená dřív bez valut (pokladny, doklady i deník v Kč) se opraví opravným
     * převodem vazeb: pokladny se přepnou na měnu z Money, doklady a řádky deníku otevřeného
     * roku dostanou valutu. Uzavřený rok 2024 zůstává, jak je - proto měnu nese počáteční
     * stav roku 2025.
     */
    public function testRelinkRepairsForeignCashOfEarlierConversion(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->foreignCashBackup();
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->forgetForeignCurrency($supplierId);
        $before = $this->snapshotCounts($supplierId);

        $repair = $this->relink($supplierId, $backup);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame($before, $this->snapshotCounts($supplierId));
        $this->assertForeignCash($supplierId);
        self::assertSame([['2025-01-01', 'EUR', 25.0, 100.0], ['2025-04-10', 'EUR', 24.35, 289.2]], $this->foreignLines($supplierId, '211.002'));
        self::assertSame([['2025-01-01', 'HUF', 0.062, 50000.0], ['2025-04-12', 'HUF', 0.06121, 10120.0]], $this->foreignLines($supplierId, '211.003'));
        $this->assertRevaluationProposals($supplierId);
    }

    /** Pokladna s doklady zadanými v MyÚčtu mimo převod měnu nemění, protokol to ohlásí. */
    public function testRelinkKeepsCzkRegisterWithOwnDocuments(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->foreignCashBackup();
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->forgetForeignCurrency($supplierId);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date, description, vat_mode, total_amount, currency_code, status)
             SELECT supplier_id, register_id, 'in', 'sale', 'VLASTNI1', '2025-05-01', '2025-05-01', 'Doklad zadaný v MyÚčtu', 'none', 100, 'CZK', 'posted'
               FROM cash_documents WHERE supplier_id = ? AND doc_number = 'CPE250001'"
        )->execute([$supplierId]);

        $repair = $this->relink($supplierId, $backup);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.002' AND currency_code = 'CZK'"));
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.003' AND currency_code = 'HUF'"));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId, "doc_number = 'CPE250001' AND currency_code = 'CZK' AND amount_foreign IS NULL"));
        self::assertStringContainsString('register_currency_kept', $this->explain($repair));
    }

    private function foreignCashBackup(): Ms3Backup
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/cash-fx.lz', SyntheticAgenda::filesWithForeignCash());
        return Ms3Backup::extract($this->tmp . '/cash-fx.lz', $this->tmp . '/cash-fx');
    }

    private function relink(int $supplierId, Ms3Backup $backup): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $backup,
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_HALF, false, true));
    }

    /** Stav firmy převedené před převzetím valut: vše v Kč. */
    private function forgetForeignCurrency(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE cash_registers SET currency_code = 'CZK' WHERE supplier_id = ?")->execute([$supplierId]);
        $pdo->prepare("UPDATE cash_documents SET currency_code = 'CZK', fx_rate = 1, amount_foreign = NULL WHERE supplier_id = ?")->execute([$supplierId]);
        $pdo->prepare('UPDATE journal_entry_lines SET currency_code = NULL, fx_rate = NULL, amount_foreign = NULL WHERE supplier_id = ?')->execute([$supplierId]);
    }

    private function assertForeignCash(int $supplierId): void
    {
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.002' AND currency_code = 'EUR'"));
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.003' AND currency_code = 'HUF'"));
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.000' AND currency_code = 'CZK'"));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId,
            "doc_number = 'CPE250001' AND doc_type = 'in' AND currency_code = 'EUR' AND fx_rate = 24.35 AND amount_foreign = 289.20 AND total_amount = 7042"));
        self::assertSame(1, $this->rowCount('cash_documents', $supplierId,
            "doc_number = 'CVH250001' AND doc_type = 'out' AND currency_code = 'HUF' AND fx_rate = 0.06121 AND amount_foreign = 10120 AND total_amount = 619"));
        self::assertSame(0, $this->rowCount('cash_documents', $supplierId, "doc_number NOT IN ('CPE250001', 'CVH250001') AND currency_code <> 'CZK'"));
        // Počáteční stav ve valutě z `PocStav` prvního převáděného roku: bez něj by valutový
        // zůstatek znal jen doklady (EUR 289,20, HUF -10 120).
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.002' AND opening_foreign_amount = 100 AND opening_foreign_date IS NOT NULL"));
        self::assertSame(1, $this->rowCount('cash_registers', $supplierId, "account_code = '211.003' AND opening_foreign_amount = 50000 AND opening_foreign_date IS NOT NULL"));
        $cash = $this->container->get(CashRegisterService::class);
        $ids = $this->db->pdo()->prepare('SELECT account_code, id FROM cash_registers WHERE supplier_id = ?');
        $ids->execute([$supplierId]);
        $byAccount = $ids->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(389.2, $cash->documentsSignedForeignTotal($supplierId, (int) $byAccount['211.002'], '2025-12-31'));
        self::assertSame(39880.0, $cash->documentsSignedForeignTotal($supplierId, (int) $byAccount['211.003'], '2025-12-31'));
    }

    /** @return list<array{0:string,1:string,2:float,3:float}> datum, měna, kurz, částka ve valutě */
    private function foreignLines(int $supplierId, string $account): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.entry_date, l.currency_code, l.fx_rate, l.amount_foreign FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND l.currency_code IS NOT NULL ORDER BY e.entry_date, l.id'
        );
        $stmt->execute([$supplierId, $account]);
        return array_map(static fn (array $r): array => [(string) $r['entry_date'], (string) $r['currency_code'], (float) $r['fx_rate'], (float) $r['amount_foreign']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Uzávěrka 2025 nabídne kurzové přecenění obou valutových pokladen. */
    private function assertRevaluationProposals(int $supplierId): void
    {
        $proposals = array_column($this->container(\MyInvoice\Repository\ClosingRepository::class)->bankProposals($supplierId, '2025-12-31'), null, 'account_code');
        self::assertSame('EUR', $proposals['211.002']['currency_code'] ?? null, json_encode($proposals) ?: '');
        self::assertEqualsWithDelta(389.2, $proposals['211.002']['foreign_balance'], 0.001);
        self::assertEqualsWithDelta(9542.0, $proposals['211.002']['czk_balance'], 0.001);
        self::assertSame('HUF', $proposals['211.003']['currency_code'] ?? null);
        self::assertEqualsWithDelta(39880.0, $proposals['211.003']['foreign_balance'], 0.001);
        self::assertEqualsWithDelta(2481.0, $proposals['211.003']['czk_balance'], 0.001);
    }

    /**
     * Storno výdeje v bance (vrácený poplatek) má v Money `Vydej` = 1 a zápornou částku —
     * peníze na účet přišly, pohyb je kladný. Rozdíl dokladu proti deníku, který je už
     * v samotném Money (kurzový rozdíl zaúčtovaný mimo účet banky, dobropis s jinou částkou
     * v deníku), rekonciliace vysvětlí rozdílem v Money a převod kvůli němu neselže.
     */
    public function testBankStornoIsIncomingAndDifferencesAlreadyInMoneyAreExplained(): void
    {
        $supplierId = $this->supplier();
        SyntheticAgenda::writeLzFiles($this->tmp . '/diff.lz', SyntheticAgenda::filesWithMoneyDifferences());
        $backup = Ms3Backup::extract($this->tmp . '/diff.lz', $this->tmp . '/diff');
        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $amount = $this->db->pdo()->prepare(
            'SELECT t.amount FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.source_ref = ?'
        );
        $amount->execute([$supplierId, 'BV25002']);
        self::assertSame(30.0, (float) $amount->fetchColumn());

        $year2025 = array_column($protocol->get('reconciliation'), null, 'year')[2025];
        self::assertTrue($year2025['ok'], (string) json_encode($year2025, JSON_UNESCAPED_UNICODE));
        $documents = array_column($year2025['documents'], null, 'key');
        self::assertSame([['document_no' => 'BV25003', 'difference' => -0.72]], $documents['bank']['source_differences'] ?? null);
        self::assertSame([['document_no' => 'DV25001', 'difference' => 10.0]], $documents['issued_invoices']['source_differences'] ?? null);

        // Opakovaný převod z téže zálohy nehlásí nic jako změněné v Money — ani konečnou
        // fakturu po odpočtu zálohy, kde `CelkemSDPH` nese celou cenu a doklad jen doplatek.
        $again = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $changed = [];
        foreach ($again->toArray()['steps'] ?? [] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                if ($m['code'] === 'changed_in_money') {
                    $changed[] = $m['text'];
                }
            }
        }
        self::assertSame([], $changed);
    }

    private function assertBankEntriesCarryTransactionIdAndStatementSourceIsImport(int $supplierId, ImportProtocol $protocol): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.source_type, e.source_id, t.id AS tx_id, s.source
               FROM journal_entries e
               JOIN bank_transactions t ON t.source_ref = e.document_no
               JOIN bank_statements s ON s.id = t.statement_id AND s.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.document_no = 'BV24001'"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('bank', $row['source_type']);
        self::assertSame((int) $row['tx_id'], (int) $row['source_id']);
        self::assertSame('import', $row['source']);
    }

    /**
     * Každý bankovní účet má v Money vlastní číselnou řadu, takže stejné číslo dokladu
     * na dvou účtech je běžné. Klíč jen „rok|číslo" by druhý pohyb zahodil (nebo shodil
     * celý krok banky) a oba pohyby by se navázaly na oba zápisy deníku.
     */
    private function assertSameBankDocumentNumberOnTwoAccountsIsImportedTwice(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.amount, COUNT(k.entry_id) AS links
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
               LEFT JOIN journal_entry_document_links k ON k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id
              WHERE s.supplier_id = ? AND t.source_ref = 'BV25001'
              GROUP BY t.id, t.amount
              ORDER BY t.amount"
        );
        $stmt->execute([$supplierId]);
        self::assertSame([['amount' => -100.0, 'links' => 1], ['amount' => -40.0, 'links' => 1]],
            array_map(static fn (array $r): array => ['amount' => (float) $r['amount'], 'links' => (int) $r['links']], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Každý vlastní účet s pohyby má v tabu Účty svůj řádek (měnu s číslem účtu) navázaný
     * na evidenci účtů firmy; jen první doplní prázdnou výchozí měnu. Opakovaný převod
     * řádky nezdvojí.
     */
    public function testEveryUsedBankAccountGetsItsOwnCompanyAccount(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_number, c.account_number AS currency_account
               FROM supplier_bank_accounts a
               LEFT JOIN currencies c ON c.id = a.currency_id AND c.supplier_id = a.supplier_id
              WHERE a.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame((string) $row['account_number'], (string) $row['currency_account'], 'Účet ' . $row['account_number'] . ' nemá řádek v tabu Účty.');
        }
        $czk = $this->rowCount('currencies', $supplierId, "code = 'CZK'");
        self::assertSame(count($rows), $czk);

        $this->import($supplierId);
        self::assertSame($czk, $this->rowCount('currencies', $supplierId, "code = 'CZK'"));
    }

    /**
     * Pohyb, který Money zaúčtovalo bez faktury (poplatek, vratka), je vyřízený - výpis
     * nesvítí jako nedopárovaný. Úhrady faktur zůstávají spárované.
     */
    private function assertBankTransactionsBookedWithoutInvoiceAreResolved(int $supplierId, ImportProtocol $protocol): void
    {
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.match_status, t.match_reason,
                    EXISTS (SELECT 1 FROM journal_entry_document_links k WHERE k.supplier_id = s.supplier_id AND k.doc_type = 'bank' AND k.doc_id = t.id) AS booked
               FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $byStatus = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['match_status'] . '|' . ($row['match_reason'] ?? '') . '|' . $row['booked'];
            $byStatus[$key] = ($byStatus[$key] ?? 0) + 1;
        }
        self::assertArrayNotHasKey('unmatched||1', $byStatus, json_encode($byStatus));
        self::assertGreaterThan(0, $byStatus['ignored|money_s3_booked|1'] ?? 0, json_encode($byStatus));
        self::assertGreaterThan(0, $byStatus['manual||1'] ?? 0, json_encode($byStatus));
    }

    /** Pokladní doklad hradící dvě faktury si ponechá vazbu na první, druhá ji nepřepíše. */
    public function testCashDocumentPayingTwoInvoicesKeepsFirstLink(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithCashPayingTwoInvoices());
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.vendor_invoice_number FROM cash_documents c JOIN purchase_invoices pi ON pi.id = c.purchase_invoice_id
              WHERE c.supplier_id = ? AND c.description LIKE 'Kancelářské%'"
        );
        $stmt->execute([$supplierId]);
        self::assertSame('DF-2025-110', $stmt->fetchColumn(), $this->explain($protocol));
        $payments = array_column($protocol->toArray()['steps'], null, 'key')['payments']['counts'] ?? [];
        self::assertSame(1, $payments['cash_multi_invoice'] ?? 0, json_encode($payments));
    }

    /**
     * Bankovní úhrada vydané faktury je i evidovanou platbou - z ní saldokonto počítá
     * uhrazenou část. Firma převedená dřívější verzí platbu nemá; opakovaný převod ji
     * doplní k už spárované úhradě a nic dalšího nezdvojí.
     */
    public function testIssuedInvoicePaymentIsRecordedAndRerunBackfillsIt(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $paid = "invoice_id = (SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'FV24001')
                 AND amount = 24200.00 AND currency = 'CZK' AND source = 'bank' AND paid_on = '2024-03-20' AND bank_transaction_id IS NOT NULL";
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, $paid), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId));

        $before = $this->snapshotCounts($supplierId);
        $this->db->pdo()->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$supplierId]);
        $repair = $this->import($supplierId);

        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, $paid), $this->explain($repair));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId));
        self::assertSame($before, $this->snapshotCounts($supplierId));
    }

    /**
     * Money drží u faktury jen poslední doklad, který ji vyrovnal (`UDoklad`); dřívější
     * splátky (a bankovní úhradu faktury dorovnané interním dokladem) najde až párovací
     * symbol deníku. Uhrazené faktury pak saldokonto nevykazuje jako otevřené a opakovaný
     * převod nic nezdvojí.
     */
    public function testPartialPaymentsArePairedByPairingSymbol(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPartialPayments());
        $supplierId = $this->supplier();
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $issued = static fn (string $no): string => "invoice_id = (SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = '{$no}')";
        $purchase = "purchase_invoice_id = (SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = 'DF-2025-070')";
        self::assertSame(2, $this->rowCount('invoice_payments', $supplierId, $issued('FV25002') . " AND source = 'bank' AND amount IN (5000.00, 7100.00)"), $this->explain($protocol));
        self::assertSame(2, $this->rowCount('payment_matches', $supplierId, $purchase . ' AND amount IN (2000.00, 4050.00)'), $this->explain($protocol));
        // FV25003 má v `UDoklad` interní doklad s odpisem; bankovní úhradu najde jen párovací symbol
        // a odpis nedoplatku (zápis vyrovnává jedinou fakturu) je na ni navázaný jako ruční zápis.
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, $issued('FV25003') . " AND source = 'bank' AND amount = 1200.00"), $this->explain($protocol));
        $invoiceId = static fn (string $no): string => "(SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = '{$no}')";
        $purchaseId = static fn (string $no): string => "(SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = '{$no}')";
        $entry = static fn (string $no): string => "(SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND source_type = 'manual' AND document_no = '{$no}')";
        self::assertSame(1, $this->rowCount('journal_entry_document_links', $supplierId,
            "doc_type = 'invoice' AND doc_id = {$invoiceId('FV25003')} AND entry_id = {$entry('ID25005')}"));
        // Vzájemný zápočet přes 395 jedním interním dokladem: dvě vydané faktury rozpadem po
        // řádcích deníku jako zápočty proti 395, jediná přijatá faktura vazbou na zápis.
        $settlement = fn (string $docExpr, string $amount): int => $this->rowCount('invoice_settlements', $supplierId,
            "doc_type = 'invoice' AND doc_id = {$docExpr} AND amount = {$amount} AND status = 'confirmed'
             AND account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = {$supplierId} AND account_code = '395.000')
             AND journal_entry_id = {$entry('ID25010')}");
        self::assertSame(1, $settlement($invoiceId('FV25005'), '605.00'), $this->explain($protocol));
        self::assertSame(1, $settlement($invoiceId('FV25006'), '363.00'));
        self::assertSame(2, $this->rowCount('invoice_payments', $supplierId, "source = 'settlement' AND amount IN (605.00, 363.00)"));
        self::assertSame(0, $this->rowCount('journal_entry_document_links', $supplierId, "doc_type = 'invoice' AND entry_id = {$entry('ID25010')}"));
        self::assertSame(1, $this->rowCount('journal_entry_document_links', $supplierId,
            "doc_type = 'purchase_invoice' AND doc_id = {$purchaseId('DF-2025-112')} AND entry_id = {$entry('ID25010')}"));
        // Úhrada se symbolem s překlepem patří jediné otevřené faktuře téhož dodavatele.
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId,
            "purchase_invoice_id = (SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = 'DF-2025-200') AND amount = 1210.00"), $this->explain($protocol));
        // Storno zápornou fakturou se započte jako dobropis, storno ale vazbu na fakturu nedostane
        // (zůstává dokladem k vyplacení, který vyrovnává jeho stav).
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, $issued('FV25007') . " AND source = 'credit_note' AND amount = 605.00"), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV25008' AND parent_invoice_id IS NULL"));
        // Vrácená záloha a DDKP s odkazem na bankovní doklad úhrady zálohy.
        $refunded = "(SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = 'ZF-2025-003')";
        self::assertSame(2, $this->rowCount('payment_matches', $supplierId, "purchase_invoice_id = {$refunded} AND amount = 1000.00"), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DZ-2025-003' AND parent_purchase_invoice_id = {$refunded}"));
        // Dobropis se stejným symbolem se započetl proti faktuře.
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, $issued('FV25004') . " AND source = 'credit_note' AND amount = 484.00"), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('credit_note_offsets', $supplierId, "doc_type = 'invoice' AND amount = 484.00 AND " . $issued('FV25004')
            . " AND credit_note_id = (SELECT id FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'DV25002' AND parent_invoice_id IS NOT NULL)"));
        // Odpočet zálohy na konečné faktuře a DDKP k záloze.
        $advance = "(SELECT id FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = 'ZF-2025-002')";
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-091' AND advance_purchase_invoice_id = {$advance}"), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DZ-2025-002' AND parent_purchase_invoice_id = {$advance}"), $this->explain($protocol));

        $period = $this->periodId($supplierId, 2025);
        $saldo = $this->container(\MyInvoice\Service\Accounting\Reports\SaldoService::class);
        $open = [];
        foreach (['311', '321', '314'] as $account) {
            foreach ($saldo->build($supplierId, $period, '2025-12-31', $account)['accounts'] as $a) {
                foreach ($a['partners'] as $partner) {
                    foreach ($partner['items'] as $item) {
                        $open[] = $item['doc_no'];
                    }
                }
            }
        }
        foreach (['FV25002', 'FV25003', 'FV25004', 'DV25002', 'FV25005', 'FV25006', 'FV25007', 'FV25008', 'DF-2025-070', 'DF-2025-091', 'DF-2025-112',
            'DF-2025-200', 'ZF-2025-002', 'ZF-2025-003'] as $docNo) {
            self::assertNotContains($docNo, $open, 'Uhrazená faktura nesmí být v saldokontu otevřená: ' . json_encode($open));
        }

        $counts = fn (): array => $this->snapshotCounts($supplierId) + [
            'invoice_payments' => $this->rowCount('invoice_payments', $supplierId),
            'credit_note_offsets' => $this->rowCount('credit_note_offsets', $supplierId),
            'invoice_settlements' => $this->rowCount('invoice_settlements', $supplierId),
            'purchase_advance_deductions' => $this->rowCount('purchase_advance_deductions', $supplierId),
        ];
        $before = $counts();
        $again = $this->import($supplierId);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $counts());
    }

    /**
     * Rozhodnutí uživatele po převodu opakovaný převod nepřepíše: pohyb, který uživatel
     * ignoroval, nespáruje; vazbu konečné faktury na jinou zálohu nechá a čerpání z Money
     * k ní nepřidá; doklad smazaný v MyÚčtu přeskočí (běh nespadne na cizím klíči).
     */
    public function testRerunRespectsUserDecisionsAndDeletedRecords(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPartialPayments());
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $purchase = static function (string $vendorNo) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?');
            $stmt->execute([$supplierId, $vendorNo]);
            return (int) $stmt->fetchColumn();
        };
        $tx = $pdo->prepare("SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = ? AND t.bank_ref = 'BV25004'");
        $tx->execute([$supplierId]);
        $bv25004 = (int) $tx->fetchColumn();

        // Uživatel splátku BV25004 odpáruje a ignoruje, FP25008 přepojí na jinou zálohu a DDKP DZ25003 smaže.
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?')->execute([$supplierId, $bv25004]);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored', match_reason = 'user_ignored', ignore_note = 'Rozhodnutí uživatele' WHERE id = ?")->execute([$bv25004]);
        $final = $purchase('DF-2025-091');
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = NULL WHERE id = ?')->execute([$final]);
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')->execute([$purchase('ZF-2025-003'), $final]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$purchase('DZ-2025-003')]);

        $again = $this->import($supplierId);

        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame(0, $this->rowCount('payment_matches', $supplierId, "bank_transaction_id = {$bv25004}"));
        $status = $pdo->prepare('SELECT match_status, match_reason, ignore_note FROM bank_transactions WHERE id = ?');
        $status->execute([$bv25004]);
        self::assertSame(['match_status' => 'ignored', 'match_reason' => 'user_ignored', 'ignore_note' => 'Rozhodnutí uživatele'], $status->fetch(PDO::FETCH_ASSOC));
        self::assertSame(1, $this->rowCount('purchase_invoices', $supplierId, "id = {$final} AND advance_purchase_invoice_id = " . $purchase('ZF-2025-003')));
        self::assertSame(0, $this->rowCount('purchase_advance_deductions', $supplierId, "final_invoice_id = {$final}"));
        $missing = 0;
        foreach ($again->toArray()['steps'] as $step) {
            $missing += (int) ($step['counts']['missing_target'] ?? 0);
        }
        self::assertGreaterThan(0, $missing, $this->explain($again));
    }

    /**
     * Opravu převedených firem přinese opakovaný převod. Co uživatel mezitím zadal
     * v MyÚčtu (platba faktury, zápočet), převod nesmaže ani nepřepíše a platby nezdvojí:
     * zadaná úhrada snižuje, co zbývá spárovat, a součet plateb faktury nepřesáhne její
     * uhrazenou částku.
     */
    public function testRerunKeepsDataEnteredAfterImportAndDoesNotDoublePayments(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPartialPayments());
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $invoiceId = static function (string $no) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
            $stmt->execute([$supplierId, $no]);
            return (int) $stmt->fetchColumn();
        };

        // Uživatel po převodu: zaplatí neuhrazenou FV25001 a z FV25005 smaže převzatý zápočet
        // i jeho platbu a zaeviduje vlastní platbu v hotovosti (jinak než v Money).
        $payments = $this->container(\MyInvoice\Service\Invoice\InvoicePaymentService::class);
        $payments->recordPayment($invoiceId('FV25001'), 1210.0, '2025-12-15', ['source' => 'manual', 'note' => 'Zadáno v MyÚčtu', 'created_by' => $this->userId]);
        $fv25005 = $invoiceId('FV25005');
        $pdo->prepare('DELETE FROM invoice_settlements WHERE supplier_id = ? AND doc_id = ? AND doc_type = ?')->execute([$supplierId, $fv25005, 'invoice']);
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'settlement'")->execute([$supplierId, $fv25005]);
        $payments->recordPayment($fv25005, 605.0, '2025-10-02', ['source' => 'manual', 'note' => 'Zadáno v MyÚčtu', 'created_by' => $this->userId]);
        $manual = $pdo->prepare("SELECT id, invoice_id, amount, paid_on, note FROM invoice_payments WHERE supplier_id = ? AND source = 'manual' ORDER BY id");
        $manual->execute([$supplierId]);
        $entered = $manual->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $entered);

        $again = $this->import($supplierId);

        self::assertFalse($again->hasErrors(), $this->explain($again));
        $manual->execute([$supplierId]);
        self::assertSame($entered, $manual->fetchAll(PDO::FETCH_ASSOC), 'Platby zadané v MyÚčtu zůstávají beze změny.');
        // Ručně zaplacená FV25005 už zápočet nedostane - platby by převýšily fakturu.
        self::assertSame(0, $this->rowCount('invoice_settlements', $supplierId, "doc_type = 'invoice' AND doc_id = {$fv25005}"));
        self::assertSame(0, $this->rowCount('invoices', $supplierId,
            '(SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = invoices.id) > ABS(total_with_vat) + 0.01'),
            'Součet plateb nesmí převýšit fakturu.');
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM (SELECT bank_transaction_id, invoice_id, purchase_invoice_id FROM payment_matches WHERE supplier_id = {$supplierId}
              GROUP BY bank_transaction_id, invoice_id, purchase_invoice_id HAVING COUNT(*) > 1) d"
        )->fetchColumn(), 'Pohyb se k témuž dokladu nespáruje dvakrát.');
    }
}
