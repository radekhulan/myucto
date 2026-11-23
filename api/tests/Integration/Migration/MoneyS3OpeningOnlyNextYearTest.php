<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Otevření roku po posledním převáděném z počátečních stavů Money: rok 2024 hraje poslední
 * převáděný rok, rok 2025 rok, který už běží v MyÚčtu (vlastní zápisy bez otevíracího
 * zápisu). Z adresáře 2025 se převezme jen otevírací zápis (XP) a rok 2024 se proti němu
 * ověří a uzavře jako historické roky.
 */
#[Group('integration')]
final class MoneyS3OpeningOnlyNextYearTest extends MoneyS3ImportTestCase
{
    public function testOpeningOnlyNextYearVerifiesAndClosesAgendaYear(): void
    {
        $supplierId = $this->companyRunning2025();
        $own = $this->ownEntries2025($supplierId);

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024, openingOnlyNext: true));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $codes = array_column($protocol->get('preflight'), 'code');
        self::assertContains('opening_only_next', $codes);
        self::assertNotContains('journal_not_empty', $codes);
        $periods = $this->container(AccountingPeriodRepository::class);
        self::assertSame('closed', (string) $periods->findByYear($supplierId, 2024)['status'], $this->explain($protocol));
        self::assertSame('open', (string) $periods->findByYear($supplierId, 2025)['status']);
        $opening = $this->openingEntries($supplierId, 2025);
        self::assertCount(1, $opening, 'Jediný otevírací zápis 2025: převzatý z Money, otevření roku po uzávěrce ho nezdvojí.');
        self::assertNotNull($this->map->get($supplierId, 'journal_entry', '2025|XP'));
        // Počáteční stavy 2025 z Money (XP) sedí na konečné stavy 2024 včetně VH na 431.
        self::assertSame(-8250.0, $this->openingBalance($supplierId, 2025, '431'));
        self::assertSame(62150.0, $this->openingBalance($supplierId, 2025, '221'));
        // Rok 2025 vedený v MyÚčtu: vlastní zápisy beze změny, deník ani doklady 2025 z Money nepřibyly.
        self::assertSame($own, $this->ownEntries2025($supplierId));
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "entry_date >= '2025-01-01' AND source_type <> 'opening'"));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "issue_date >= '2025-01-01'"));
        self::assertSame(0, $this->rowCount('purchase_invoices', $supplierId, "issue_date >= '2025-01-01'"));
        // Kroky, které proběhly v Money, má uzávěrka 2024 přeskočené s poznámkou.
        $skipped = $this->closingSteps($supplierId, 2024);
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            self::assertSame('skipped', $skipped[$step]['status'] ?? null, $step);
            self::assertStringContainsString('Money S3', (string) ($skipped[$step]['note'] ?? ''), $step);
        }
        $closing = array_column((array) $protocol->get('closing'), 'status', 'year');
        self::assertSame(['2024' => 'closed', '2025' => 'opening_only'], array_combine(array_map('strval', array_keys($closing)), array_values($closing)));

        // Opakovaný převod nic nezdvojí.
        $before = $this->snapshotCounts($supplierId);
        $again = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024, openingOnlyNext: true));
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshotCounts($supplierId));
        self::assertCount(1, $this->openingEntries($supplierId, 2025));
    }

    /** Bez volby zůstává chování beze změny: rok 2024 otevřený, rok 2025 bez počátečních stavů. */
    public function testWithoutOptionLastYearStaysOpen(): void
    {
        $supplierId = $this->companyRunning2025();

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame('open', (string) $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2024)['status']);
        self::assertSame([], $this->openingEntries($supplierId, 2025));
        self::assertArrayNotHasKey('opening_only_next', $protocol->get('options'));
    }

    /**
     * Rok 2025 otevřený v MyÚčtu jeho vlastní uzávěrkou (otevírací zápis z MyÚčta, ne z Money):
     * převod otevírací zápis nemění a nezdvojí, počáteční stavy z Money s ním porovná uzávěrka
     * roku 2024 a rozdíly vyjmenuje.
     */
    public function testExistingOpeningEntryIsKeptAndDifferencesReported(): void
    {
        $supplierId = $this->companyRunning2025();
        $periodId = $this->periodId($supplierId, 2025);
        $accounts = $this->accounts($supplierId, ['211', '411']);
        $entry = $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-01-01', 'source_type' => 'opening', 'source_id' => $periodId,
             'description' => 'Otevření roku v MyÚčtu', 'posted_at' => '2025-01-01 08:00:00'],
            [['account_id' => $accounts['211'], 'side' => 'debit', 'amount' => '999.00'], ['account_id' => $accounts['411'], 'side' => 'credit', 'amount' => '999.00']],
        );

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024, openingOnlyNext: true));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertContains('opening_only_existing', array_column($protocol->get('preflight'), 'code'));
        self::assertSame([$entry], array_map('intval', array_column($this->openingEntries($supplierId, 2025), 'id')));
        self::assertSame('open', (string) $this->container(AccountingPeriodRepository::class)->findByYear($supplierId, 2024)['status']);
        $closing = array_column($protocol->toArray()['steps'], null, 'key')['closing'];
        $mismatch = array_values(array_filter($closing['messages'], static fn (array $m): bool => $m['code'] === 'closing_mismatch'));
        self::assertCount(1, $mismatch, $this->explain($protocol));
        self::assertStringContainsString('Rozdíly', $mismatch[0]['text']);
        self::assertNotEmpty($mismatch[0]['context']['diffs'] ?? []);
    }

    /**
     * Převod od roku 2025 s počátečními stavy z dalšího roku: posledním převáděným rokem je
     * předposlední rok zálohy 2024, rozsah je prázdný. Převod se zastaví a rok 2025 určený
     * jen pro počáteční stavy nepřevezme celý.
     */
    public function testEmptyRangeStopsInsteadOfConvertingOpeningOnlyYear(): void
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');

        $protocol = $this->convert($supplierId, new ImportOptions(ImportOptions::MODE_IMPORT, fromYear: 2025, openingOnlyNext: true));

        self::assertTrue($protocol->failed(), $this->explain($protocol));
        self::assertContains('opening_only_empty', array_column(array_filter($protocol->get('preflight'), static fn (array $m): bool => $m['level'] === 'error'), 'code'));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId));
        self::assertSame(0, $this->rowCount('invoices', $supplierId));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function convert(int $supplierId, ImportOptions $options): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), $options);
    }

    /** Firma, která rok 2025 už účtuje v MyÚčtu: období 2025 bez otevíracího zápisu a vlastní tržba. */
    private function companyRunning2025(): int
    {
        $supplierId = $this->supplier(SyntheticAgenda::ICO, 'double_entry');
        $this->container(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $periodId = $this->container(AccountingPeriodRepository::class)->create($supplierId, 2025, '2025-01-01', '2025-12-31');
        $accounts = $this->accounts($supplierId, ['311', '602']);
        $this->container(JournalEntryRepository::class)->insert(
            ['supplier_id' => $supplierId, 'period_id' => $periodId, 'entry_date' => '2025-02-03', 'source_type' => 'manual',
             'description' => 'Vlastní tržba', 'posted_at' => '2025-02-03 10:00:00'],
            [['account_id' => $accounts['311'], 'side' => 'debit', 'amount' => '100.00'], ['account_id' => $accounts['602'], 'side' => 'credit', 'amount' => '100.00']],
        );
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query("SELECT default_currency_id FROM supplier WHERE id = {$supplierId}")->fetchColumn();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id) VALUES (?, 'Vlastní odběratel s.r.o.', 'Vzorová 2', 'Brno', '60200', ?, ?)")
            ->execute([$supplierId, $this->czId, $currency]);
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, invoice_type, status, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, total_without_vat, total_vat, total_with_vat)
             VALUES (?, 'invoice', 'sent', ?, '2025900001', '2025-02-03', '2025-02-03', '2025-02-17', ?, 100, 21, 121)"
        )->execute([$supplierId, (int) $pdo->lastInsertId(), $currency]);
        return $supplierId;
    }

    /**
     * @param list<string> $codes
     * @return array<string,int>
     */
    private function accounts(int $supplierId, array $codes): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')');
        $stmt->execute([$supplierId, ...$codes]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return list<array<string,mixed>> */
    private function ownEntries2025(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.entry_date, e.description, e.source_type, e.row_version, l.account_id, l.side, l.amount
               FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
              WHERE e.supplier_id = ? AND e.entry_date >= '2025-01-01' AND e.source_type <> 'opening' ORDER BY e.id, l.id"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function openingEntries(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = 'opening' AND reversed_by IS NULL");
        $stmt->execute([$supplierId, $this->periodId($supplierId, $year)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Počáteční stav syntetického účtu v otevíracím zápisu roku: MD − D. */
    private function openingBalance(int $supplierId, int $year, string $synthetic): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type = 'opening' AND a.account_code LIKE ?"
        );
        $stmt->execute([$supplierId, $this->periodId($supplierId, $year), $synthetic . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** @return array<string,array<string,mixed>> */
    private function closingSteps(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ?');
        $stmt->execute([$supplierId, $this->periodId($supplierId, $year)]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'step_key');
    }
}
