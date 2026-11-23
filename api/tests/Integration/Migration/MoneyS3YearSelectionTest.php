<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Převod Money S3 při přechodu roku: rok 2024 hraje „převáděný rok" (v Money se účtuje
 * dál a převádí se opakovaně), rok 2025 „rok běžící v MyÚčtu". Volba „Převést do roku",
 * zámek účtování k datu, úhrady po konci převáděných období, pohyby z banky na účtu
 * Money, aktualizace zápisů změněných v Money a přepis popisů jen navázaných zápisů.
 */
#[Group('integration')]
final class MoneyS3YearSelectionTest extends MoneyS3ImportTestCase
{
    public function testAgendaYearOnlyKeepsNextYearRunningInMyUcto(): void
    {
        $supplierId = $this->companyRunning2025();
        $period = $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2025);
        $before = $this->entries2025($supplierId);

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));

        self::assertFalse($protocol->failed(), $this->explain($protocol));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertContains('later_years_skipped', array_column($protocol->get('preflight'), 'code'));
        self::assertSame($before, $this->entries2025($supplierId), 'Deník 2025 vedený v MyÚčtu převod nemění.');
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "issue_date >= '2025-01-01'"), 'Doklady 2025 z Money se nepřimíchají.');
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "issue_date >= '2025-01-01'"));
        self::assertSame($period, $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2025));
        self::assertGreaterThan(0, $this->rowCount('journal_entries', $supplierId, "entry_date < '2025-01-01'"));

        // Bez volby převod rok 2025 s vlastními zápisy sám vynechá (výchozí „Převést do roku").
        $default = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_DRY_RUN));
        self::assertFalse($default->hasErrors(), $this->explain($default));
        $codes = array_column($default->get('preflight'), 'code');
        self::assertContains('to_year_default', $codes);
        self::assertNotContains('journal_not_empty', $codes);
        self::assertSame(2024, $default->get('options')['to_year']);
    }

    public function testRepeatImportRespectsCompanyDateLock(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $this->container(AccountingSupplierSettingsRepository::class)->setLockedUntil($supplierId, '2025-06-30');
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/VFaktury.DAT', null, [
            $this->issued('FV25009', '2025-03-10', 1000.0, 210.0),
        ]);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', null, [
            ['Cislo' => 301, 'Zdroj' => 'FV', 'Doklad' => 'FV25009', 'Datum' => '2025-03-10', 'DatPlnDPH' => '2025-03-10', 'Popis' => 'Pod zámkem', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 1000.0],
            ['Cislo' => 302, 'Zdroj' => 'FV', 'Doklad' => 'FV25009', 'Datum' => '2025-03-10', 'DatPlnDPH' => '2025-03-10', 'Popis' => 'Pod zámkem', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 210.0],
        ]);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
        $before = $this->snapshotCounts($supplierId);

        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed(), $this->explain($protocol));
        self::assertContains('date_locked', array_column($protocol->get('preflight'), 'code'));
        self::assertSame($before, $this->snapshotCounts($supplierId));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV25009'"));
    }

    public function testDateLockCheckedInsideStepTransaction(): void
    {
        $supplierId = $this->supplier();
        $settings = $this->container(AccountingSupplierSettingsRepository::class);
        $progress = static function (string $step) use ($settings, $supplierId): void {
            // Zámek nastavený souběžně s převodem, po zápisu deníku.
            if ($step === 'accounting_mode') {
                $settings->setLockedUntil($supplierId, '2025-06-30');
            }
        };

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT), null, $progress);

        self::assertTrue($protocol->failed(), $this->explain($protocol));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame('error', $steps['accounting_mode']['status'] ?? null, $this->explain($protocol));
        self::assertContains('date_locked', array_column($steps['accounting_mode']['messages'], 'code'));
        self::assertSame(0, $this->rowCount('invoices', $supplierId));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId));
    }

    public function testPaidAfterLastTransferredYearStaysUnpaid(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithPaymentsAcrossYearEnd());
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $invoice = $this->invoice($supplierId, 'FV24002');
        self::assertSame('sent', $invoice['status'], $this->explain($protocol));
        self::assertSame(0.0, (float) $invoice['paid_total']);
        self::assertNull($invoice['paid_at']);
    }

    public function testPartialPaymentWithLaterPaidDateKeepsRemainderOpen(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithPaymentsAcrossYearEnd());
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $invoice = $this->invoice($supplierId, 'FV24003');
        self::assertSame(400.0, (float) $invoice['paid_total'], $this->explain($protocol));
        self::assertSame('sent', $invoice['status']);
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$invoice['id']} AND amount = 400.00"));
    }

    public function testBankMovementFromApiOnMoneyAccountIsNotDuplicated(): void
    {
        $supplierId = $this->supplier();
        $later = $this->apiStatement($supplierId, [['2024-03-20', '24200.00', '2024001'], ['2026-02-01', '500.00', '2026001']]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->movements($supplierId, '2024-03-20', '24200.00'), $this->explain($protocol));
        self::assertSame(1, $this->movements($supplierId, '2026-02-01', '500.00'), 'Pohyb po posledním pohybu Money zůstává.');
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT transaction_count FROM bank_statements WHERE id = {$later}")->fetchColumn());
    }

    public function testMatchedBankMovementFromApiOnMoneyAccountStopsImport(): void
    {
        $supplierId = $this->supplier();
        $this->apiStatement($supplierId, [['2024-03-20', '24200.00', '2024001']], 'manual');

        $protocol = $this->import($supplierId);

        self::assertTrue($protocol->failed(), $this->explain($protocol));
        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertContains('bank_foreign_movements_used', array_column($steps['bank']['messages'], 'code'), $this->explain($protocol));
        self::assertSame(1, $this->movements($supplierId, '2024-03-20', '24200.00'), 'Spárovaný pohyb převod nemaže.');
    }

    public function testChangedJournalEntryInOpenPeriodIsReplaced(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $entryId = $this->entryOf($supplierId, 'FP25001');
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithFp25001On501());

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($entryId, $this->entryOf($supplierId, 'FP25001'), 'Zápis se přepsal na místě.');
        self::assertSame(['321.000' => -6050.0, '343.100' => 1050.0, '501.100' => 5000.0], $this->entryNet($entryId));
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "document_no = 'FP25001' AND entry_date = '2025-01-15'"));
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertSame(1, $journal['counts']['replaced'] ?? 0, $this->explain($protocol));
    }

    public function testChangedJournalEntryEditedInMyUctoIsKept(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $entryId = $this->entryOf($supplierId, 'FP25001');
        // Uživatel zápis v MyÚčtu upravil (jiný účet nákladu).
        $this->db->pdo()->prepare(
            "UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                SET l.account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '568.000')
              WHERE l.entry_id = ? AND a.account_code = '518.000'"
        )->execute([$supplierId, $entryId]);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithFp25001On501());

        $protocol = $this->import($supplierId);

        self::assertArrayHasKey('568.000', $this->entryNet($entryId));
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertContains('journal_changed_kept', array_column($journal['messages'], 'code'), $this->explain($protocol));
    }

    public function testChangedDocumentInOpenPeriodIsUpdated(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $id = (int) $this->invoice($supplierId, 'FV25001')['id'];
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/VFaktury.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FV25001' ? ['Zaklad_2' => 1100.0, 'DPH_2' => 231.0, 'CelkemSDPH' => 1331.0] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', static fn (array $r): array => trim((string) $r['Doklad']) !== 'FV25001' ? $r
            : ['Castka' => (string) $r['UcD'] === '602000' ? 1100.0 : 231.0] + $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame($id, (int) $this->invoice($supplierId, 'FV25001')['id']);
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "id = {$id} AND total_without_vat = 1100.00 AND total_vat = 231.00 AND total_with_vat = 1331.00"), $this->explain($protocol));
        self::assertSame('1331.00', (string) $this->db->pdo()->query("SELECT SUM(total_with_vat) FROM invoice_items WHERE invoice_id = {$id}")->fetchColumn());
        $issued = array_column($protocol->toArray()['steps'], null, 'key')['issued_invoices'];
        self::assertSame(1, $issued['counts']['updated'] ?? 0, $this->explain($protocol));
    }

    public function testLinkRefreshesOnlyDescriptionsOfLinkedEntries(): void
    {
        $supplierId = $this->companyRunning2025();
        $ownEntry = (int) $this->db->pdo()->query("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND source_type = 'invoice'")->fetchColumn();
        self::assertGreaterThan(0, $ownEntry);

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('Vlastní tržba', $this->db->pdo()->query("SELECT description FROM journal_entries WHERE id = {$ownEntry}")->fetchColumn());
        // Převzaté zápisy roku 2024 popis s číslem dokladu a protistranou dostaly.
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "document_no = 'FV24001' AND description LIKE '%Odběratel Beta%'"));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function convert(int $supplierId, ImportOptions $options): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), $options);
    }

    /**
     * Firma, která rok 2025 už účtuje v MyÚčtu: období 2025, vlastní vydaná faktura a její
     * zápis (popis bez čísla dokladu, jak ho zadal uživatel).
     */
    private function companyRunning2025(): int
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->container(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $periodId = $this->container(AccountingPeriodRepository::class)->create($supplierId, 2025, '2025-01-01', '2025-12-31');
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query("SELECT default_currency_id FROM supplier WHERE id = {$supplierId}")->fetchColumn();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id) VALUES (?, 'Vlastní odběratel s.r.o.', 'Vzorová 2', 'Brno', '60200', ?, ?)")
            ->execute([$supplierId, $this->czId, $currency]);
        $clientId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, invoice_type, status, client_id, varsymbol, issue_date, tax_date, due_date, currency_id,
                                   total_without_vat, total_vat, total_with_vat)
             VALUES (?, 'invoice', 'sent', ?, '2025900001', '2025-02-03', '2025-02-03', '2025-02-17', ?, 100, 21, 121)"
        )->execute([$supplierId, $clientId, $currency]);
        $invoiceId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('311', '602')");
        $stmt->execute([$supplierId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-02-03', 'source_type' => 'invoice', 'source_id' => $invoiceId,
             'description' => 'Vlastní tržba', 'posted_at' => '2025-02-03 10:00:00'],
            [['account_id' => (int) $accounts['311'], 'side' => 'debit', 'amount' => '100.00'], ['account_id' => (int) $accounts['602'], 'side' => 'credit', 'amount' => '100.00']],
        );
        return $supplierId;
    }

    /** @return list<array<string,mixed>> */
    private function entries2025(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.entry_date, e.description, e.source_type, e.source_id, e.row_version, l.account_id, l.side, l.amount
               FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
              WHERE e.supplier_id = ? AND e.entry_date >= '2025-01-01' ORDER BY e.id, l.id"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function invoice(int $supplierId, string $number): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, status, paid_total, paid_at FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Faktura {$number} chybí.");
        return $row;
    }

    /** @return array<string,mixed> */
    private function issued(string $docNo, string $date, float $base, float $vat, array $extra = []): array
    {
        return $extra + [
            'O_ICO' => SyntheticAgenda::CUSTOMER_ICO, 'O_DIC' => 'CZ' . SyntheticAgenda::CUSTOMER_ICO,
            'O_Nazev' => 'Odběratel Beta a.s.', 'O_Ulice' => 'Ukázková 7', 'O_Mesto' => 'Ostrava', 'O_Psc' => '702 00',
            'SazbaDPH1' => 12.0, 'SazbaDPH2' => 21.0, 'Druh' => 'N', 'KodDPH' => SyntheticAgenda::KOD_DPH_SALE, 'Uhrada' => 'převodem',
            'Doklad' => $docNo, 'VarSymbol' => preg_replace('/\D/', '', $docNo), 'Vystaveno' => $date, 'DatUcPr' => $date, 'PlnenoDPH' => $date, 'Splatno' => $date,
            'Zaklad_2' => $base, 'DPH_2' => $vat, 'CelkemSDPH' => round($base + $vat, 2), 'Popis' => 'Služby',
        ];
    }

    /**
     * Faktury 2024 uhrazené v Money až v lednu 2025: FV24002 celá pohybem roku 2025,
     * FV24003 z části (400 Kč) pohybem z prosince 2024 a zbytek v lednu 2025.
     *
     * @return array<string,string>
     */
    private function filesWithPaymentsAcrossYearEnd(): array
    {
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.001/VFaktury.DAT', null, [
            $this->issued('FV24002', '2024-11-20', 10000.0, 2100.0, ['Uhrazeno' => '2025-01-15', 'UDoklad' => 'BP25009']),
            $this->issued('FV24003', '2024-11-25', 1000.0, 210.0, ['Uhrazeno' => '2025-01-15', 'UDoklad' => 'BP24004']),
        ]);
        $files = SyntheticAgenda::patch($files, 'ROK.001/UcDenik.DAT', null, [
            ['Cislo' => 201, 'Zdroj' => 'FV', 'Doklad' => 'FV24002', 'Datum' => '2024-11-20', 'DatPlnDPH' => '2024-11-20', 'Popis' => 'Služby', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 10000.0],
            ['Cislo' => 202, 'Zdroj' => 'FV', 'Doklad' => 'FV24002', 'Datum' => '2024-11-20', 'DatPlnDPH' => '2024-11-20', 'Popis' => 'Služby', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 2100.0],
            ['Cislo' => 203, 'Zdroj' => 'FV', 'Doklad' => 'FV24003', 'Datum' => '2024-11-25', 'DatPlnDPH' => '2024-11-25', 'Popis' => 'Služby', 'UcMD' => '311000', 'UcD' => '602000', 'Castka' => 1000.0],
            ['Cislo' => 204, 'Zdroj' => 'FV', 'Doklad' => 'FV24003', 'Datum' => '2024-11-25', 'DatPlnDPH' => '2024-11-25', 'Popis' => 'Služby', 'UcMD' => '311000', 'UcD' => '343200', 'Castka' => 210.0],
            ['Cislo' => 205, 'Zdroj' => 'BK', 'Doklad' => 'BP24004', 'Datum' => '2024-12-10', 'Popis' => 'Část úhrady FV24003', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 400.0],
        ]);
        $files = SyntheticAgenda::patch($files, 'ROK.001/BankKnih.DAT', null, [
            ['Doklad' => 'BP24004', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2024-12-10', 'DatPlat' => '2024-12-10', 'Celkem' => 400.0, 'VarSym' => '24003', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Část úhrady FV24003', 'Vypis' => 4],
        ]);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', null, [
            ['Cislo' => 205, 'Zdroj' => 'BK', 'Doklad' => 'BP25009', 'Datum' => '2025-01-15', 'Popis' => 'Úhrada FV24002', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 12100.0],
            ['Cislo' => 206, 'Zdroj' => 'BK', 'Doklad' => 'BP25010', 'Datum' => '2025-01-15', 'Popis' => 'Doplatek FV24003', 'UcMD' => '221001', 'UcD' => '311000', 'Castka' => 810.0],
        ]);
        return SyntheticAgenda::patch($files, 'ROK.002/BankKnih.DAT', null, [
            ['Doklad' => 'BP25009', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-01-15', 'DatPlat' => '2025-01-15', 'Celkem' => 12100.0, 'VarSym' => '24002', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Úhrada FV24002', 'Vypis' => 1],
            ['Doklad' => 'BP25010', 'Ucet' => 'BU', 'Vydej' => 0, 'DatUcPr' => '2025-01-15', 'DatPlat' => '2025-01-15', 'Celkem' => 810.0, 'VarSym' => '24003', 'AdNazev' => 'Odběratel Beta a.s.', 'Popis' => 'Doplatek FV24003', 'Vypis' => 1],
        ]);
    }

    /** Novější záloha: Money přeúčtovalo náklad FP25001 z 518 na 501. @return array<string,string> */
    private function filesWithFp25001On501(): array
    {
        return SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/UcDenik.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FP25001' && (string) $r['UcMD'] === '518000' ? ['UcMD' => '501100'] + $r : $r);
    }

    /**
     * Výpis stažený z banky (API) na účet BU, který vede Money.
     *
     * @param list<array{0:string,1:string,2:string}> $movements datum, částka, VS
     * @return int id výpisu
     */
    private function apiStatement(int $supplierId, array $movements, string $status = 'unmatched'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
             VALUES (?, 'bank_api', 'api-bu', ?, '3000000004', '0100', 'CZK', '2026-02-01', ?)"
        )->execute([$supplierId, hash('sha256', 'api-bu-' . $supplierId), count($movements)]);
        $statementId = (int) $pdo->lastInsertId();
        $tx = $pdo->prepare(
            "INSERT INTO bank_transactions (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, match_status, import_fingerprint)
             VALUES ('statement', ?, ?, ?, ?, 'CZK', ?, ?, ?)"
        );
        foreach ($movements as $i => [$date, $amount, $vs]) {
            $tx->execute(["api-{$i}", $statementId, $date, $amount, $vs, $status, hash('sha256', "api|{$supplierId}|{$i}")]);
        }
        return $statementId;
    }

    private function movements(int $supplierId, string $date, string $amount): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
              WHERE s.supplier_id = ? AND t.posted_at = ? AND t.amount = ?'
        );
        $stmt->execute([$supplierId, $date, $amount]);
        return (int) $stmt->fetchColumn();
    }

    private function entryOf(int $supplierId, string $documentNo): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND document_no = ? AND entry_date >= '2025-01-01'");
        $stmt->execute([$supplierId, $documentNo]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,float> účet => MD − D */
    private function entryNet(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? GROUP BY a.account_code ORDER BY a.account_code"
        );
        $stmt->execute([$entryId]);
        return array_map(static fn (string $v): float => round((float) $v, 2), $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
