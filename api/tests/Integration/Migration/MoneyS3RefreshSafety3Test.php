<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;

/**
 * Aktualizace otevřeného období ve více otevřených letech a u dokladu s více zápisy:
 * rozhodnutí o dokladu je v každém roce stejné a doklad se nerozejde s žádným svým zápisem.
 */
#[Group('integration')]
final class MoneyS3RefreshSafety3Test extends MoneyS3ImportTestCase
{
    /**
     * Oba roky 2024 a 2025 jsou otevřené (převod bez uzavření historie). Money změní zápis
     * FV24001 (doklad ne) a doklad i zápis FV25001. Kontrola dokladu v kroku deníku musí
     * znát i doklady roku 2025, přestože se poprvé ptá už v roce 2024.
     */
    public function testDocumentDecisionIsSameForLaterOpenYear(): void
    {
        $supplierId = $this->supplier();
        $options = new ImportOptions(ImportOptions::MODE_IMPORT, false);
        self::assertFalse($this->importer->run($supplierId, $this->userId, $this->backup(), $options)->hasErrors());

        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.001/UcDenik.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FV24001' && (string) $r['UcD'] === '602000' ? ['UcD' => '501100'] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/VFaktury.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FV25001' ? ['Zaklad_2' => 1100.0, 'DPH_2' => 231.0, 'CelkemSDPH' => 1331.0] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', static fn (array $r): array => trim((string) $r['Doklad']) !== 'FV25001' ? $r
            : ['Castka' => (string) $r['UcD'] === '602000' ? 1100.0 : 231.0] + $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), $options);

        $counts = $this->counts($protocol);
        self::assertSame(1331.0, $this->documentTotal($supplierId), 'Platná změna dokladu z Money se převezme. ' . json_encode($counts));
        self::assertSame(1331.0, $this->entry311($supplierId), json_encode($counts));
        self::assertSame(1, $counts['issued_invoices']['updated'] ?? 0, json_encode($counts));
        self::assertArrayNotHasKey('changed_kept', $counts['journal'], json_encode($counts));
    }

    /**
     * Doklad FV25001 má v Money dva zápisy (vystavení a pozdější přeúčtování tržby). Money
     * změní oba i doklad; pozdější zápis má v MyÚčtu poznámku, takže se ponechá. Ponechá se
     * proto i dřívější zápis a doklad - jinak by se dřívější zápis přepsal, doklad zůstal
     * a doklad s deníkem by se rozešly.
     */
    public function testDocumentWithTwoEntriesKeepsBothWhenOneIsKept(): void
    {
        $reclass = static fn (float $amount): array => [
            'Cislo' => 501, 'Zdroj' => 'FV', 'Doklad' => 'FV25001', 'Datum' => '2025-06-30', 'Popis' => 'Přeúčtování tržby',
            'UcMD' => '602000', 'UcD' => '613000', 'Castka' => $amount,
        ];
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/UcDenik.DAT', null, [$reclass(50.0)]));
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $later = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND document_no = 'FV25001' AND entry_date = '2025-06-30'")->fetchColumn();
        self::assertGreaterThan(0, $later);
        $pdo->prepare("INSERT INTO journal_entry_notes (supplier_id, entry_id, body, created_by) VALUES (?, ?, 'Přeúčtování ověřeno', ?)")
            ->execute([$supplierId, $later, $this->userId]);

        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/VFaktury.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FV25001' ? ['Zaklad_2' => 1100.0, 'DPH_2' => 231.0, 'CelkemSDPH' => 1331.0] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', static fn (array $r): array => trim((string) $r['Doklad']) !== 'FV25001' ? $r
            : ['Castka' => (string) $r['UcD'] === '602000' ? 1100.0 : 231.0] + $r, [$reclass(60.0)]);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->import($supplierId);

        $counts = $this->counts($protocol);
        self::assertSame($this->documentTotal($supplierId), $this->entry311($supplierId), 'Doklad a jeho zápisy se nerozejdou. ' . json_encode($counts));
        self::assertSame(1210.0, $this->documentTotal($supplierId), json_encode($counts));
        self::assertSame(2, $counts['journal']['changed_kept'] ?? 0, json_encode($counts));
    }

    /** @return array<string,array<string,int|float>> */
    private function counts(ImportProtocol $protocol): array
    {
        return array_map(static fn (array $s): array => $s['counts'] ?? [],
            array_intersect_key(array_column($protocol->toArray()['steps'], null, 'key'), ['journal' => 1, 'issued_invoices' => 1]));
    }

    private function documentTotal(int $supplierId): float
    {
        return (float) $this->db->pdo()->query("SELECT total_with_vat FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'FV25001'")->fetchColumn();
    }

    private function entry311(int $supplierId): float
    {
        return (float) $this->db->pdo()->query("SELECT SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.account_id
           WHERE e.supplier_id = {$supplierId} AND e.document_no = 'FV25001' AND a.account_code LIKE '311%'")->fetchColumn();
    }
}
