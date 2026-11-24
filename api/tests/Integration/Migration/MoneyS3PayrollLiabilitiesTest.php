<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\SaldoRepository;
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
        // Čistá mzda: předpis nese interní doklad mezd, položka je potvrzená bez zápisu
        // a jako předpis má část řádku Dal 331 interního dokladu IDM2412.
        self::assertSame('confirmed', $items['ZA241204']['status']);
        self::assertSame('331.000', $items['ZA241204']['account_code']);
        self::assertNull($items['ZA241204']['journal_entry_id']);
        self::assertSame([['IDM2412', 'credit', '331.000', '22210.00']], $this->origins((int) $items['ZA241204']['id']));
        foreach (['ZA241201', 'ZA241202', 'ZA241203'] as $doc) {
            self::assertSame([], $this->origins((int) $items[$doc]['id']), $doc);
        }
        self::assertContains('payroll_liabilities_other_accounts', array_column(array_column($protocol->toArray()['steps'], null, 'key')['payroll_liabilities']['messages'], 'code'));
        // Bez nového účtování: všechny zápisy firmy jsou převzaté z Money.
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM journal_entries e WHERE e.supplier_id = {$supplierId}
            AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)")->fetchColumn());

        $before = $this->snapshotCounts($supplierId) + ['items' => $this->rowCount('other_items', $supplierId),
            'origins' => $this->rowCount('journal_settlement_allocations', $supplierId)];
        $again = $this->convert($supplierId);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshotCounts($supplierId) + ['items' => $this->rowCount('other_items', $supplierId),
            'origins' => $this->rowCount('journal_settlement_allocations', $supplierId)]);
    }

    /**
     * Převod do 2024 založí otevřené závazky, pozdější převod i roku 2025 už má lednové úhrady.
     * Úhrada bankou se naváže na převzatý pohyb s jeho datem: saldokonto k 31. 12. 2024 závazek
     * pořád ukazuje, k 31. 12. 2025 už ne. Úhrada, kterou v převedené bance nenajde (BV25012),
     * závazek nezavře a převod ji ohlásí; opakovaný převod úhrady nezdvojí.
     */
    public function testPaymentInLaterYearKeepsLiabilityOpenAtYearEnd(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPayrollLiabilitiesPaidNextYear());
        $supplierId = $this->supplier();
        self::assertFalse($this->convert($supplierId)->hasErrors());

        $protocol = $this->convert($supplierId, 2025);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $items = $this->items($supplierId);
        foreach (['ZA241201' => '9570.00', 'ZA241204' => '22210.00'] as $doc => $amount) {
            self::assertSame('0.00', $items[$doc]['settled_outside_amount'], $doc . ' ' . $this->explain($protocol));
            self::assertSame([['2025-01-12', $amount]], $this->allocations((int) $items[$doc]['id']), $doc);
        }
        self::assertSame('0.00', $items['ZA241202']['settled_outside_amount']);
        self::assertSame([], $this->allocations((int) $items['ZA241202']['id']));
        $messages = array_column(array_column($protocol->toArray()['steps'], null, 'key')[PayrollLiabilityImporter::STEP]['messages'], null, 'code');
        self::assertStringContainsString('ZA241202', $messages['payroll_payments_unresolved']['text'] ?? '');

        self::assertSame(['ZA241201' => -9570.0, 'ZA241202' => -4050.0], $this->openItems($supplierId, '336', '2024-12-31'));
        self::assertSame(['ZA241204' => -22210.0], $this->openItems($supplierId, '331', '2024-12-31'));
        self::assertSame(['ZA241202' => -4050.0], $this->openItems($supplierId, '336', '2025-12-31'));
        self::assertSame([], $this->openItems($supplierId, '331', '2025-12-31'));

        $before = $this->rowCount('other_item_allocations', $supplierId);
        self::assertFalse($this->convert($supplierId, 2025)->hasErrors());
        self::assertSame($before, $this->rowCount('other_item_allocations', $supplierId));
    }

    /**
     * Měsíc, jehož zaúčtování mezd je načtené z PAMICY (s platným dokladem), mzdové závazky
     * z Money nedostane: import PAMICY je založil sám.
     */
    public function testMonthImportedFromPayrollProgramGetsNoLiabilities(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithPayrollLiabilities());
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO other_items (supplier_id, side, kind, title, issued_on, due_on, amount, amount_czk, status, document_no)
            VALUES (?, 'payable', 'other', 'Sociální pojištění z PAMICY', '2024-12-31', '2025-01-20', 9570, 9570, 'confirmed', 'PAM2412')")->execute([$supplierId]);
        $pdo->prepare("INSERT INTO payroll_posting_imports (supplier_id, source_application, source_ico, sym_par, period, file_sha256, internal_document_ids, other_item_ids)
            VALUES (?, 'PAMICA', ?, 'MZ202412', '2024-12', ?, '[]', ?)")
            ->execute([$supplierId, SyntheticAgenda::ICO, str_repeat('0', 64), json_encode([(int) $pdo->lastInsertId()])]);

        $protocol = $this->convert($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(['PAM2412'], array_keys($this->items($supplierId)));
        $messages = array_column(array_column($protocol->toArray()['steps'], null, 'key')[PayrollLiabilityImporter::STEP]['messages'], null, 'code');
        self::assertStringContainsString('12/2024', $messages['payroll_liabilities_payroll_import']['text'] ?? '', $this->explain($protocol));
    }

    /**
     * Vyhledání zápisů závazků neběží po jednotlivých dokladech: 30 dalších závazků s vlastním
     * zápisem KZ přidá krok nanejvýš pár čtení (mapa po letech, hlavičky a řádky po dávkách).
     */
    public function testLiabilityLookupDoesNotQueryPerDocument(): void
    {
        $selects = [];
        foreach ([SyntheticAgenda::ICO => 0, SyntheticAgenda::ADVANCE_CUSTOMER_ICO => 30] as $ico => $extra) {
            SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::forCompany(SyntheticAgenda::filesWithPayrollLiabilities($extra), (string) $ico, 'Mzdy ' . $extra));
            $supplierId = $this->supplier((string) $ico);
            $counts = [];
            $pdo = $this->db->pdo();
            $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, toYear: 2024),
                progress: function (string $step) use (&$counts, $pdo): void {
                    $counts[$step] = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
                });
            self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
            self::assertSame(3 + $extra, $this->rowCount('other_items', $supplierId, "journal_entry_id IS NOT NULL"), $this->explain($protocol));
            $steps = array_keys($counts);
            $next = $steps[array_search(PayrollLiabilityImporter::STEP, $steps, true) + 1];
            $selects[$extra] = $counts[$next] - $counts[PayrollLiabilityImporter::STEP];
        }
        self::assertLessThan(10, $selects[30] - $selects[0], json_encode($selects));
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

    private function convert(int $supplierId, int $toYear = 2024): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, toYear: $toYear));
    }

    /** @return list<array{0:string,1:string,2:string,3:string}> doklad zápisu, strana, účet a částka předpisů položky z řádku zápisu */
    private function origins(int $itemId): array
    {
        return $this->db->pdo()->query("SELECT e.document_no, ja.side, ca.account_code, ja.amount FROM journal_settlement_allocations ja
            JOIN journal_entries e ON e.id = ja.entry_id JOIN chart_of_accounts ca ON ca.id = ja.account_id
            WHERE ja.doc_type = 'other_item' AND ja.kind = 'origin' AND ja.doc_id = {$itemId} ORDER BY ja.id")->fetchAll(PDO::FETCH_NUM);
    }

    /** @return list<array{0:string,1:string}> datum a částka úhrad položky */
    private function allocations(int $itemId): array
    {
        return $this->db->pdo()->query("SELECT payment_on, amount FROM other_item_allocations
            WHERE other_item_id = {$itemId} AND reversed_on IS NULL ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<string,float> otevřené ostatní položky saldokonta účtu k datu: doklad => zůstatek */
    private function openItems(int $supplierId, string $code, string $asOf): array
    {
        $saldo = $this->container(SaldoRepository::class);
        $account = $saldo->resolveAccount($supplierId, $code, $asOf);
        $out = [];
        foreach ($saldo->openItems($supplierId, (int) $account['id'], $asOf, $code) as $item) {
            if ($item['doc_type'] === 'other_item') {
                $out[$item['doc_no']] = round($item['booked_signed'] * (1 - $item['paid_ratio']), 2);
            }
        }
        ksort($out);
        return $out;
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
