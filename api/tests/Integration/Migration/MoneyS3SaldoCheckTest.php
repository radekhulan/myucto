<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\SaldoOpeningCheck;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kontrolní sestava převodu: saldokonto k 1. 1. dalšího roku proti počátečním stavům účtů
 * 311, 321, 314, 324, 331, 336 a 342.
 */
#[Group('integration')]
final class MoneyS3SaldoCheckTest extends MoneyS3ImportTestCase
{
    /**
     * Mzdové závazky převzaté se zápisem pokryjí počáteční stav 336 a 342. Čistá mzda (potvrzená
     * položka bez zápisu) má předpis v řádku 331 interního dokladu mezd a pokryje počáteční stav 331.
     */
    public function testPayrollAccountsMatchOpeningBalances(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPayrollLiabilities());
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $check = $this->accounts($protocol);
        self::assertSame('2024-12-31', $protocol->get(SaldoOpeningCheck::STEP)['as_of']);
        foreach (['331' => 22210.0, '336' => 13620.0, '342' => 3810.0] as $key => $balance) {
            $code = (string) $key;
            $row = $this->row($check, $code);
            self::assertSame($balance, $row['balance'], $code . ' ' . $this->explain($protocol));
            self::assertSame($balance, $row['open_items'], $code);
            self::assertSame(0.0, $row['difference'], $code);
        }
        $step = array_column($protocol->toArray()['steps'], null, 'key')[SaldoOpeningCheck::STEP];
        self::assertNotContains('saldo_check_difference', array_column($step['messages'], 'code'), $this->explain($protocol));
        self::assertContains('saldo_check_ok', array_column($step['messages'], 'code'));
        // Rok 2025 se nepřevedl a otevírací zápis nemá: kontrola výslovně říká, proti čemu porovnávala.
        $against = array_values(array_filter($step['messages'], static fn (array $m): bool => $m['code'] === 'saldo_check_against_closing'));
        self::assertCount(1, $against, $this->explain($protocol));
        self::assertStringContainsString('konečnými stavy účtů k 31. 12. 2024', $against[0]['text']);
        self::assertSame('closing_balances', $protocol->get(SaldoOpeningCheck::STEP)['basis']);
    }

    /**
     * Otevírací zápis roku 2025 v MyÚčtu má na 311 jiný stav (500 Kč), než jaký vychází
     * z konečných stavů 2024 a otevřených položek (0 Kč). Kontrola porovná otevřené položky
     * se skutečným otevíracím zápisem a rozdíl ohlásí, i když hlavní kniha k 31. 12. sedí.
     */
    public function testOpenItemsAreComparedWithActualOpeningEntry(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->container(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $periodId = $this->container(AccountingPeriodRepository::class)->create($supplierId, 2025, '2025-01-01', '2025-12-31');
        $stmt = $this->db->pdo()->prepare("SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ('311', '411')");
        $stmt->execute([$supplierId]);
        $accounts = array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-01-01', 'source_type' => 'opening', 'source_id' => $periodId,
             'description' => 'Otevření roku v MyÚčtu', 'posted_at' => '2025-01-01 08:00:00'],
            [['account_id' => $accounts['311'], 'side' => 'debit', 'amount' => '500.00'], ['account_id' => $accounts['411'], 'side' => 'credit', 'amount' => '500.00']],
        );

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024, openingOnlyNext: true));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $step = array_column($protocol->toArray()['steps'], null, 'key')[SaldoOpeningCheck::STEP];
        $codes = array_column($step['messages'], 'code');
        self::assertContains('saldo_check_difference', $codes, $this->explain($protocol));
        self::assertNotContains('saldo_check_ok', $codes);
        $row = $this->row($this->accounts($protocol), '311');
        self::assertSame(0.0, $row['balance'], 'Hlavní kniha k 31. 12. 2024 na otevřené položky sedí.');
        self::assertSame(0.0, $row['open_items']);
        self::assertSame(500.0, $row['difference']);
        self::assertSame(500.0, $row['opening']);
        self::assertNotContains('saldo_check_against_closing', $codes);
        self::assertSame('opening_entry', $protocol->get(SaldoOpeningCheck::STEP)['basis']);
    }

    /** Zůstatek 311 bez dokladu (doklady počátečního stavu nevznikly) protokol ohlásí. */
    public function testReceivableWithoutDocumentIsReported(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear(1000.0));
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, fromYear: 2024, toYear: 2024));

        $messages = array_column(array_column($protocol->toArray()['steps'], null, 'key')[SaldoOpeningCheck::STEP]['messages'] ?? [], 'code');
        self::assertContains('saldo_check_difference', $messages, $this->explain($protocol));
        $row = $this->row($this->accounts($protocol), '311');
        self::assertSame(-210.0, $row['balance']);
        self::assertSame(-210.0, $row['difference'], 'Zůstatek bez dokladu.');
    }

    private function convert(int $supplierId, ImportOptions $options): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), $options);
    }

    /** @return list<array<string,mixed>> */
    private function accounts(ImportProtocol $protocol): array
    {
        return (array) ($protocol->get(SaldoOpeningCheck::STEP)['accounts'] ?? []);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function row(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if (($row['account'] ?? null) === $code) {
                return $row;
            }
        }
        self::fail("Účet {$code} v kontrole saldokonta chybí: " . json_encode($rows, JSON_UNESCAPED_UNICODE));
    }
}
