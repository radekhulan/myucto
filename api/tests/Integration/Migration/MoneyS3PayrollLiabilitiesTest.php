<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\PayrollLiabilityImporter;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Mzdové závazky Money za prosinec posledního převáděného roku jako ostatní závazky: s vlastním
 * zápisem KZ převzaté bez nového účtování, čistá mzda (Money ji vede 331/331) potvrzená bez
 * zápisu, závazek mimo účty mezd (379) jen ohlášený.
 */
#[Group('integration')]
final class MoneyS3PayrollLiabilitiesTest extends MoneyS3ImportTestCase
{
    public function testOpenPayrollLiabilitiesBecomeOtherItems(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPayrollLiabilities());
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $items = $this->items($supplierId);
        self::assertSame(['ZA241201', 'ZA241202', 'ZA241203', 'ZA241204'], array_keys($items), $this->explain($protocol));
        foreach (['ZA241201' => '336.200', 'ZA241202' => '336.100', 'ZA241203' => '342.100'] as $doc => $account) {
            self::assertSame('posted', $items[$doc]['status'], $doc);
            self::assertSame($account, $items[$doc]['account_code'], $doc);
            self::assertNotNull($items[$doc]['journal_entry_id'], $doc);
            self::assertSame(['other_item', (string) $items[$doc]['id']], $this->entrySource((int) $items[$doc]['journal_entry_id']), $doc);
        }
        self::assertSame('9570.00', $items['ZA241201']['amount']);
        self::assertSame('0.00', $items['ZA241201']['settled_outside_amount'], 'Uhrazeno až v lednu 2025 - ke konci roku otevřené celé.');
        // Čistá mzda: předpis nese interní doklad mezd, položka je potvrzená bez zápisu.
        self::assertSame('confirmed', $items['ZA241204']['status']);
        self::assertSame('331.000', $items['ZA241204']['account_code']);
        self::assertNull($items['ZA241204']['journal_entry_id']);
        self::assertContains('payroll_liabilities_other_accounts', array_column(array_column($protocol->toArray()['steps'], null, 'key')['payroll_liabilities']['messages'], 'code'));
        // Bez nového účtování: všechny zápisy firmy jsou převzaté z Money.
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = {$supplierId}
            AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)")->fetchColumn());

        $before = $this->snapshotCounts($supplierId) + ['items' => $this->rowCount('other_items', $supplierId)];
        $again = $this->convert($supplierId);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshotCounts($supplierId) + ['items' => $this->rowCount('other_items', $supplierId)]);
    }

    /** Opravný převod vazeb mzdové závazky nezakládá. */
    public function testRelinkDoesNotCreatePayrollLiabilities(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->convert($supplierId)->hasErrors());
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPayrollLiabilities());

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024, relinkOnly: true));

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->rowCount('other_items', $supplierId));
    }

    public function testOpenAmountAtYearEnd(): void
    {
        $row = ['Celkem' => 1000.0, 'UhZbyva' => 300.0, 'UhDatum' => '2024-12-10'];
        self::assertSame(300.0, PayrollLiabilityImporter::openAt($row, '2024-12-31'), 'Část uhrazená v prosinci.');
        self::assertSame(1000.0, PayrollLiabilityImporter::openAt(['UhDatum' => '2025-01-12', 'UhZbyva' => 0.0] + $row, '2024-12-31'), 'Úhrada po konci roku se nepočítá.');
        self::assertSame(0.0, PayrollLiabilityImporter::openAt(['UhZbyva' => 0.0] + $row, '2024-12-31'));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function convert(int $supplierId): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024));
    }

    /** @return array<string,array<string,mixed>> */
    private function items(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM other_items WHERE supplier_id = ? ORDER BY document_no');
        $stmt->execute([$supplierId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'document_no');
    }

    /** @return array{0:string,1:string} */
    private function entrySource(int $entryId): array
    {
        $row = $this->db->pdo()->query("SELECT source_type, source_id FROM journal_entries WHERE id = {$entryId}")->fetch(PDO::FETCH_NUM);
        return [(string) $row[0], (string) $row[1]];
    }
}
