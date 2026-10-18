<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\MoneyS3\AgendaInfo;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticTaxAgenda;
use PHPUnit\Framework\Attributes\Group;

/**
 * Převod agendy, kterou Money vede jako daňovou evidenci ({@see SyntheticTaxAgenda}).
 * Převod nikdy nezapíše podvojné účetnictví pro rok, který Money vede jako daňovou
 * evidenci, a firmu v daňové evidenci nepřepne.
 */
#[Group('integration')]
final class MoneyS3TaxRecordsImportTest extends MoneyS3ImportTestCase
{
    /** @param array<string,string> $files */
    private function taxBackup(array $files): Ms3Backup
    {
        $name = 'de' . bin2hex(random_bytes(3));
        SyntheticAgenda::writeLzFiles("{$this->tmp}/{$name}.lz", $files);
        return Ms3Backup::extract("{$this->tmp}/{$name}.lz", "{$this->tmp}/{$name}");
    }

    /** @return array<string,array{level:string,code:string,message:string,context:array<string,mixed>}> */
    private function preflight(int $supplierId, Ms3Backup $backup): array
    {
        $byCode = [];
        foreach ($this->importer->preflight($supplierId, $backup, AgendaInfo::fromBackup($backup), new ImportOptions()) as $m) {
            $byCode[$m['code']] ??= $m;
        }
        return $byCode;
    }

    /**
     * Kontrola před převodem daňové evidence projde peněžní deník proti bance, pokladně
     * a vazbám, vypíše souhrn roků a řekne, že firma zůstane v daňové evidenci. Hláška
     * o přepnutí do podvojného účetnictví u ní nesmí být.
     */
    public function testTaxRecordsPreflightChecksSourceAndKeepsCompanyMode(): void
    {
        $supplierId = $this->supplier();
        $preflight = $this->importer->preflight($supplierId, $backup = $this->taxBackup(SyntheticTaxAgenda::files()),
            AgendaInfo::fromBackup($backup), new ImportOptions());
        $codes = array_column($preflight, 'code');
        $dump = json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '';

        self::assertSame([], array_values(array_filter($preflight, static fn (array $m): bool => $m['level'] === 'error')), $dump);
        self::assertSame(2, count(array_keys($codes, 'tax_records_year_plan', true)), $dump);
        self::assertContains('tax_evidence_kept', $codes, $dump);
        self::assertNotContains('switch_to_double_entry', $codes, $dump);
        self::assertNotContains('target_double_entry', $codes, $dump);
        $plan = array_values(array_filter($preflight, static fn (array $m): bool => $m['code'] === 'tax_records_year_plan'));
        self::assertStringStartsWith('Rok 2025 (adresář ROK.001): peněžní deník má 4 pohybů (banka 3, pokladna 1', $plan[0]['message']);
    }

    /**
     * Daňovou evidenci převod převede jen do firmy v daňové evidenci — firmu v podvojném
     * účetnictví do ní nepřepne a do podvojného nepřevede.
     */
    public function testTaxRecordsIntoDoubleEntryCompanyAreRefused(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $preflight = $this->preflight($supplierId, $this->taxBackup(SyntheticTaxAgenda::files()));

        self::assertSame('error', $preflight['target_double_entry']['level'] ?? null, json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertArrayNotHasKey('tax_evidence_kept', $preflight);
        self::assertArrayNotHasKey('switch_to_double_entry', $preflight);
    }

    /**
     * Agenda, která se v Money z podvojného účetnictví vrátila do daňové evidence: převod by
     * firmu přepnul do podvojného i pro rok daňové evidence. Kontrola ho zastaví a ostrý
     * převod firmu nepřepne — ani na firmě, ani v historii režimu.
     */
    public function testAgendaReturnedToTaxRecordsIsRefusedAndCompanyStaysTaxEvidence(): void
    {
        $supplierId = $this->supplier();
        $backup = $this->taxBackup(SyntheticTaxAgenda::filesReturnedToTaxRecords());
        $preflight = $this->preflight($supplierId, $backup);
        self::assertSame('error', $preflight['tax_records_after_double_entry']['level'] ?? null, json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['year' => 2025, 'dir' => 'ROK.002'], $preflight['tax_records_after_double_entry']['context']);
        self::assertArrayNotHasKey('switch_to_double_entry', $preflight);

        $protocol = $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertTrue($protocol->hasErrors());
        $pdo = $this->db->pdo();
        $mode = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        self::assertSame('tax_evidence', $mode->fetchColumn());
        self::assertSame(0, $this->rowCount('supplier_accounting_modes', $supplierId, "accounting_mode = 'double_entry'"));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
    }

    /**
     * Ostrý převod daňové evidence: doklady, banka a pokladna, úhrady přesně podle
     * peněžního deníku, zařazení pohybů bez dokladu, otevřený závazek, plátcovství DPH,
     * podaná přiznání a zámek. Peněžní deník MyÚčta sedí s Money po letech i po pohybech
     * a firma zůstane v daňové evidenci bez deníku.
     */
    public function testTaxRecordsAgendaIsConverted(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->taxBackup(SyntheticTaxAgenda::files()), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $result = $protocol->toArray();
        self::assertSame([], array_values(array_filter(array_merge(...array_column($result['steps'], 'messages')),
            static fn (array $m): bool => in_array($m['level'], ['error', 'warning'], true))), $this->explain($protocol));

        $pdo = $this->db->pdo();
        $value = static function (string $sql, array $params) use ($pdo): mixed {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        };
        // Režim firmy beze změny, žádný deník ani období.
        self::assertSame('tax_evidence', $value('SELECT accounting_mode FROM supplier WHERE id = ?', [$supplierId]));
        self::assertSame(0, $this->rowCount('supplier_accounting_modes', $supplierId, "accounting_mode = 'double_entry'"));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->rowCount('accounting_periods', $supplierId));

        // Doklady a jejich úhrady: zaplaceno jen podle navázaného pohybu.
        self::assertSame(2, $this->rowCount('invoices', $supplierId));
        self::assertSame(6, $this->rowCount('purchase_invoices', $supplierId));
        $status = static fn (string $table, string $column, string $number): string => (string) $value(
            "SELECT status FROM {$table} WHERE supplier_id = ? AND {$column} = ?", [$supplierId, $number]);
        self::assertSame('paid', $status('invoices', 'varsymbol', 'FV24001'));
        self::assertSame('sent', $status('invoices', 'varsymbol', 'FV25001'));
        self::assertSame('paid', $status('purchase_invoices', 'vendor_invoice_number', 'DF-2024-031'));
        self::assertSame('paid', $status('purchase_invoices', 'vendor_invoice_number', 'ZF-2025-001'));
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "source = 'bank' AND amount = 12100"));
        // Úhrada faktury v hotovosti i vratka k jejímu dobropisu.
        self::assertSame(2, $this->rowCount('cash_documents', $supplierId, "purpose = 'purchase_payment' AND purchase_invoice_id IS NOT NULL"));
        self::assertSame('paid', $status('purchase_invoices', 'vendor_invoice_number', 'DB-2024-031'));
        // Vyúčtování zálohy má zálohu jako uhrazenou, dobropis je vyrovnaný zápočtem.
        self::assertEquals(0.0, (float) $value('SELECT amount_to_pay FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?', [$supplierId, 'DF-2025-012']));
        self::assertSame(1, $this->rowCount('credit_note_offsets', $supplierId));

        // Zařazení pohybů bez dokladu podle sloupců peněžního deníku Money.
        $buckets = $pdo->prepare('SELECT COALESCE(bt.bank_ref, cd.doc_number) AS doc, c.tax_bucket FROM de_movement_classification c
            LEFT JOIN bank_transactions bt ON bt.id = c.bank_transaction_id LEFT JOIN cash_documents cd ON cd.id = c.cash_document_id
            WHERE c.supplier_id = ? ORDER BY doc');
        $buckets->execute([$supplierId]);
        self::assertSame([
            'BP24002' => 'income_nontax', 'BP25001' => 'income_taxable', 'BV24001' => 'expense_nontax', 'BV24002' => 'private',
            'BV25002' => 'expense_nontax', 'PP24001' => 'private',
        ], $buckets->fetchAll(\PDO::FETCH_KEY_PAIR));

        // Otevřený závazek k datu zálohy, plátcovství DPH, podaná přiznání a zámek.
        self::assertSame(1, $this->rowCount('other_items', $supplierId, "side = 'payable' AND amount = 1500"));
        $history = $pdo->prepare('SELECT effective_from, is_vat_payer FROM supplier_vat_status_history WHERE supplier_id = ? AND effective_from >= "2024-01-01" ORDER BY effective_from');
        $history->execute([$supplierId]);
        self::assertSame(['2024-01-01' => 0, '2024-07-01' => 1], array_map('intval', $history->fetchAll(\PDO::FETCH_KEY_PAIR)));
        self::assertSame('quarterly', $value('SELECT vat_period FROM supplier WHERE id = ?', [$supplierId]));
        self::assertSame(20, $this->rowCount('tax_submissions', $supplierId, "status = 'submitted'"));
        self::assertSame('2025-12-31', $value('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?', [$supplierId]));

        // Peněžní deník MyÚčta = peněžní deník Money, bez virtuálních pohybů.
        self::assertSame([true, true], array_column($result['tax_records_reconciliation'], 'ok'), $this->explain($protocol));
        $journal = $this->container(\MyInvoice\Service\TaxEvidence\CashJournalService::class);
        // 2024: služba 1 000 Kč bez DPH minus dobropis 100 Kč (vratka v hotovosti).
        foreach ([2024 => [10000.0, 900.0], 2025 => [500.0, 3420.0]] as $year => [$income, $expense]) {
            $built = $journal->build($supplierId, "{$year}-01-01", "{$year}-12-31", ['year' => $year]);
            self::assertEqualsWithDelta($income, $built['totals']['prijem_danovy'], 0.005, "{$year} daňové příjmy");
            self::assertEqualsWithDelta($expense, $built['totals']['vydaj_danovy'], 0.005, "{$year} daňové výdaje");
            self::assertEqualsWithDelta(0.0, $built['totals']['nezarazeno'], 0.005);
            self::assertSame([], array_values(array_diff(array_unique(array_column($built['rows'], 'source_type')), ['bank', 'cash'])), "{$year}: jen fyzické pohyby");
        }
        // Výpis nese počáteční stav účtu z Money.
        self::assertEquals(50000.0, (float) $value("SELECT prev_balance FROM bank_statements WHERE supplier_id = ? AND source = 'import' ORDER BY statement_date LIMIT 1", [$supplierId]));
    }

    /**
     * Nulový doklad Money (přeúčtování DPH zálohy při vyúčtování) MyÚčto nepřebírá: rozdíl
     * daňové části úhrad dokladu rekonciliace ukáže u konkrétního dokladu jako rozdíl
     * k přijetí. Bez přijetí převod skončí chybou, s přijetím projde s upozorněním.
     */
    public function testAdvanceVatReclassIsAcceptableDifference(): void
    {
        $supplierId = $this->supplier();
        $files = SyntheticTaxAgenda::filesWithAdvanceVatReclass();
        $refused = $this->importer->run($supplierId, $this->userId, $this->taxBackup($files), new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertTrue($refused->hasErrors(), $this->explain($refused));
        self::assertTrue($refused->acceptableOnly(), $this->explain($refused));
        $messages = array_column(array_column($refused->toArray()['steps'], null, 'key')['tax_records_reconciliation']['messages'], null, 'code');
        self::assertSame([['document' => 'přijatá faktura FP25002', 'documents' => 'PV25003', 'cause' => 'zero_document', 'money_amount' => 0.0,
            'myucto_amount' => 0.0, 'money_taxable' => 420.0, 'myucto_taxable' => 0.0]], $messages['tax_records_difference']['context']['differences']);
        self::assertSame("Rok 2025: 1 doklad má v peněžním deníku MyÚčta jinou částku nebo daňovou část úhrad než v Money:\n"
            . '– přijatá faktura FP25002, nulový doklad PV25003 z 20. 2. 2025. Money: daňový výdaj -420,00 Kč (VP_106), DPH 420,00 Kč (VP_DPH). '
            . 'MyÚčto: bez pohybu. Nulový doklad PV25003 v Money přeúčtoval 420,00 Kč z daňového výdaje do DPH (typicky DPH zálohy při vyúčtování '
            . 'faktury), MyÚčto nulové doklady nepřebírá. Daňový výdaj je v MyÚčtu o 420,00 Kč vyšší.', $messages['tax_records_difference']['text']);

        $accepted = $this->importer->run($supplierId, $this->userId, $this->taxBackup($files),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, null, ImportOptions::DISPOSAL_YEAR_TAX_HALF, true));
        self::assertFalse($accepted->hasErrors(), $this->explain($accepted));
        self::assertSame('completed_with_warnings', $accepted->status());
    }

    /**
     * Úhrady, které Money rozdělil na základ a DPH jinak než MyÚčto, hláška rozepíše po
     * dokladech: uhrazený doklad, číslo a datum pohybu, rozdělení v Money s kódem pohybu,
     * rozdělení v MyÚčtu a příčinu. Zbytek platby nad fakturu, který MyÚčto nezařadí, se
     * hlásí jednou, u nezařazených pohybů, ne ještě jako rozdíl dokladu a součtu roku.
     */
    public function testPaymentSplitDifferencesAreItemized(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->taxBackup(SyntheticTaxAgenda::filesWithPaymentSplitDifferences()),
            new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertTrue($protocol->acceptableOnly(), $this->explain($protocol));
        $messages = array_column(array_column($protocol->toArray()['steps'], null, 'key')['tax_records_reconciliation']['messages'], null, 'code');
        self::assertSame(['tax_records_unclassified', 'tax_records_difference'], array_keys($messages), $this->explain($protocol));
        self::assertSame("Rok 2025: peněžní deník MyÚčta má nezařazené pohyby za 0,60 Kč, které nevstoupí do daňového základu:\n"
            . '– BP25002 z 20. 6. 2025 (příjem 775,00 Kč): navázané doklady (vydaná faktura FV25002) pokrývají jen 774,40 Kč, zbytek 0,60 Kč '
            . 'zůstane nezařazený (zařazení v peněžním deníku platí pro celý pohyb). Money zbytek vede jako daňový příjem 0,60 Kč (PP_504).',
            $messages['tax_records_unclassified']['text']);
        self::assertSame("Rok 2025: 1 doklad má v peněžním deníku MyÚčta jinou částku nebo daňovou část úhrad než v Money:\n"
            . '– přijatá faktura FP25005, úhrada BV25003 z 12. 5. 2025 (1 210,00 Kč). Money: DPH 1 210,00 Kč (VP_DPH). '
            . 'MyÚčto: daňový výdaj 1 000,00 Kč, DPH a nedaňové 210,00 Kč. Money vede celou úhradu jako DPH, MyÚčto ji rozdělí na základ '
            . 'a DPH poměrem DPH dokladu. Daňový výdaj je v MyÚčtu o 1 000,00 Kč vyšší.', $messages['tax_records_difference']['text']);
        self::assertSame('vat_only', $messages['tax_records_difference']['context']['differences'][0]['cause']);
    }

    /** Zkouška nanečisto projde stejnými kroky a v databázi nic nenechá. */
    public function testTaxRecordsDryRunWritesNothing(): void
    {
        $supplierId = $this->supplier();
        $before = $this->snapshotCounts($supplierId) + $this->taxCounts($supplierId);
        $protocol = $this->importer->run($supplierId, $this->userId, $this->taxBackup(SyntheticTaxAgenda::files()), new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame([true, true], array_column((array) $protocol->get('tax_records_reconciliation'), 'ok'), $this->explain($protocol));
        self::assertSame($before, $this->snapshotCounts($supplierId) + $this->taxCounts($supplierId));
    }

    /**
     * Opakovaný převod téže zálohy nic nezdvojí: doklady, úhrady, zařazení, ostatní položky
     * i podání najde v mapě převodu, i když jsou převedené roky už pod zámkem dokladů.
     */
    public function testTaxRecordsRerunDuplicatesNothing(): void
    {
        $supplierId = $this->supplier();
        $first = $this->importer->run($supplierId, $this->userId, $this->taxBackup(SyntheticTaxAgenda::files()), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertFalse($first->hasErrors(), $this->explain($first));
        $counts = $this->snapshotCounts($supplierId) + $this->taxCounts($supplierId);

        $second = $this->importer->run($supplierId, $this->userId, $this->taxBackup(SyntheticTaxAgenda::files()), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertFalse($second->hasErrors(), $this->explain($second));
        self::assertSame($counts, $this->snapshotCounts($supplierId) + $this->taxCounts($supplierId));
    }

    /**
     * Do rozjeté evidence se daňová evidence z Money nepřimíchává, do uzamčeného období
     * první převod nezapisuje a hospodářský rok daňová evidence nemá.
     */
    public function testTaxRecordsTargetMustBeEmptyUnlockedCalendarCompany(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $register = $pdo->prepare('INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, "Vlastní pokladna", "211", "CZK", 1)');
        $register->execute([$supplierId]);
        $pdo->prepare('INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, currency_code, status)
            VALUES (?, ?, "in", "other", "VLASTNI1", "2024-05-05", "Vlastní doklad", 100, "CZK", "posted")')->execute([$supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, "2024-03-31")')->execute([$supplierId]);

        $preflight = $this->preflight($supplierId, $this->taxBackup(SyntheticTaxAgenda::files()));
        self::assertSame('error', $preflight['tax_records_year_not_empty']['level'] ?? null, json_encode($preflight, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['year' => 2024], $preflight['tax_records_year_not_empty']['context']);
        self::assertStringContainsString('1 pokladních dokladů', $preflight['tax_records_year_not_empty']['message']);
        self::assertSame('error', $preflight['tax_records_locked']['level'] ?? null);

        $pdo->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$supplierId]);
        self::assertSame('error', $this->preflight($supplierId, $this->taxBackup(SyntheticTaxAgenda::files()))['tax_records_fiscal_year']['level'] ?? null);
    }

    /**
     * Rok 2026 pokračuje v MyÚčtu: výpis z banky za období, které převod převzal, pohyby
     * z Money nezdvojí (hranice převzetí = poslední převzatý pohyb účtu), nový pohyb založí.
     */
    public function testBankStatementAfterConversionDoesNotDuplicateMovements(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->taxBackup(SyntheticTaxAgenda::files()), new ImportOptions(ImportOptions::MODE_IMPORT));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $pdo = $this->db->pdo();
        $currency = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' AND account_number = ? LIMIT 1");
        $currency->execute([$supplierId, SyntheticTaxAgenda::BANK_ACCOUNT]);
        $currencyId = (int) $currency->fetchColumn();
        self::assertGreaterThan(0, $currencyId, 'převod doplní číslo účtu firmy');

        $tx = static fn (string $date, float $amount, string $ref): array => ['posted_at' => $date, 'amount' => $amount, 'currency' => 'CZK',
            'variable_symbol' => null, 'constant_symbol' => null, 'specific_symbol' => null, 'counterparty_account' => '1000000005',
            'counterparty_bank' => '0100', 'counterparty_name' => 'Odběratel Beta a.s.', 'description' => 'Platba', 'bank_ref' => $ref];
        // Výpis v PDF jde stejnou cestou zápisu pohybů jako GPC, jen bez vlastní transakce
        // napojeného účtu (test běží v transakci).
        $result = $this->container(\MyInvoice\Service\Bank\StatementImporter::class)->importParsedPdf([
            'header' => ['account_number' => SyntheticTaxAgenda::BANK_ACCOUNT, 'statement_date' => '2025-03-31', 'statement_number' => '3',
                'prev_balance' => null, 'curr_balance' => null, 'credit_total' => null, 'debit_total' => null, 'period_kind' => 'period'],
            'transactions' => [$tx('2025-03-10', 500.0, 'BANKA-1'), $tx('2025-03-20', 700.0, 'BANKA-2')],
        ], '%PDF-de-hranice-' . $supplierId, 'vypis-2025.pdf', null, $currencyId, $supplierId);
        self::assertSame(1, $result['transactions'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(1, $result['skipped_migrated']);
    }

    /** @return array<string,int> */
    private function taxCounts(int $supplierId): array
    {
        $out = [];
        foreach (['invoice_payments', 'credit_note_offsets', 'de_movement_classification', 'other_items', 'tax_submissions',
            'supplier_vat_status_history', 'money_s3_import_map'] as $table) {
            $out[$table] = $this->rowCount($table, $supplierId);
        }
        return $out;
    }

    /** Agenda podvojného účetnictví přepnutí dál ohlásí. */
    public function testDoubleEntryAgendaStillAnnouncesSwitch(): void
    {
        $supplierId = $this->supplier();
        $preflight = $this->preflight($supplierId, $this->backup());
        self::assertSame('info', $preflight['switch_to_double_entry']['level'] ?? null);
        self::assertArrayNotHasKey('tax_records_after_double_entry', $preflight);
    }
}
