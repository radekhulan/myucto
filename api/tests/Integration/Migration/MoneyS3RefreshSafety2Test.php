<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;

/**
 * Aktualizace otevřeného období a pohyby z banky na účtu Money: dimenze z MyÚčta se drží
 * svého účtu, oprava zápisu převedeného před otisky přežije ztrátu historie verzí, zápis
 * dokladu se nepřepíše, když doklad převod změnit nesmí, a pohyb se symbolem má přednost.
 */
#[Group('integration')]
final class MoneyS3RefreshSafety2Test extends MoneyS3ImportTestCase
{
    /**
     * Uživatel dá dimenzi „Obchodník“ jen nákladovému řádku FP25001 (518, řádek 1).
     * Money řádky přeskládá (řádek DPH první) a náklad přeúčtuje na 501. Přenos podle
     * pořadí řádku dá dimenzi řádku 343 a náklad 501 zůstane bez ní.
     */
    public function testUserDimensionFollowsItsAccountWhenMoneyReordersLines(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $entryId = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND document_no = 'FP25001' AND entry_date >= '2025-01-01'")->fetchColumn();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'obchodnik', 'Obchodník', 'custom')")->execute([$supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'NOVAK', 'Novák')")->execute([$typeId, $supplierId]);
        $valueId = (int) $pdo->lastInsertId();
        $inserted = $pdo->prepare(
            "INSERT INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin)
             SELECT l.id, ?, ?, ?, 'manual' FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code = '518.000'"
        );
        $inserted->execute([$typeId, $supplierId, $valueId, $entryId, $supplierId]);
        self::assertSame(1, $inserted->rowCount());

        $moved = null;
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/UcDenik.DAT', static function (array $r) use (&$moved): ?array {
            if (trim((string) $r['Doklad']) === 'FP25001' && (string) $r['UcMD'] === '518000') {
                $moved = ['UcMD' => '501100'] + $r;
                return null;
            }
            return $r;
        });
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', null, [$moved]);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->import($supplierId);

        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        $accounts = $pdo->query("SELECT a.account_code FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id
            JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = {$entryId} AND d.dimension_type_id = {$typeId}")->fetchAll(\PDO::FETCH_COLUMN);
        $kept = (int) $pdo->query("SELECT COUNT(*) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = {$entryId} AND a.account_code = '518.000'")->fetchColumn() > 0;
        self::assertTrue($kept || $accounts === ['501.100'], 'Dimenze nákladu nesmí přeskočit na jiný účet. Má ji: ' . json_encode($accounts) . ' counts=' . json_encode($journal['counts'] ?? []));
    }

    /**
     * Firma převedená před 2334 (otisk NULL), účetní opravila FP25001 (518 -> 568). Pak se
     * databáze obnovila ze zálohy mariadb-dump bez --dump-history (nebo zkopírovala na jiný
     * server): historie verzí řádků je pryč. pristineEntries() zápis vyhodnotí jako nedotčený
     * a převod opravu vrátí podle Money, i když Money nic nezměnilo.
     */
    public function testLegacyCorrectionSurvivesLostRowHistory(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $entryId = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$supplierId} AND document_no = 'FP25001' AND entry_date >= '2025-01-01'")->fetchColumn();
        $pdo->prepare("UPDATE money_s3_import_map SET fingerprint = NULL WHERE supplier_id = ?")->execute([$supplierId]);
        $pdo->prepare(
            "UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                SET l.account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '568.000')
              WHERE l.entry_id = ? AND a.account_code = '518.000'"
        )->execute([$supplierId, $entryId]);
        // Obnova ze zálohy bez historie verzí (v transakci testu, vrátí se).
        $pdo->exec('DELETE HISTORY FROM journal_entry_lines');

        $this->import($supplierId);

        $has568 = (int) $pdo->query("SELECT COUNT(*) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = {$entryId} AND a.account_code = '568.000'")->fetchColumn();
        self::assertGreaterThan(0, $has568, 'Oprava z MyÚčta se nesmí vrátit jen proto, že chybí historie verzí.');
    }

    /**
     * Money změní FV25001 tak, že doklad převod přepsat nesmí (jiné členění DPH, které
     * převod nezná) a zároveň změní částku v deníku. Zápis se přepíše podle Money, doklad
     * zůstane: doklad 1 210 Kč, zápis na 311 1 331 Kč.
     */
    public function testEntryIsNotReplacedWhenItsDocumentCannotBeUpdated(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $pdo = $this->db->pdo();
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/VFaktury.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FV25001' ? ['Zaklad_2' => 1100.0, 'DPH_2' => 231.0, 'CelkemSDPH' => 1331.0, 'KodDPH' => '19ZZZ'] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', static fn (array $r): array => trim((string) $r['Doklad']) !== 'FV25001' ? $r
            : ['Castka' => (string) $r['UcD'] === '602000' ? 1100.0 : 231.0] + $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->import($supplierId);

        $doc = (float) $pdo->query("SELECT total_with_vat FROM invoices WHERE supplier_id = {$supplierId} AND varsymbol = 'FV25001'")->fetchColumn();
        $entry = (float) $pdo->query("SELECT SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.account_id
           WHERE e.supplier_id = {$supplierId} AND e.document_no = 'FV25001' AND a.account_code LIKE '311%'")->fetchColumn();
        $counts = json_encode(array_map(static fn ($s) => $s['counts'] ?? [], array_intersect_key(array_column($protocol->toArray()['steps'], null, 'key'), ['journal' => 1, 'issued_invoices' => 1])));
        self::assertSame($doc, $entry, 'Doklad a jeho zápis se nerozejdou. ' . $counts);
    }

    /**
     * Money má 20. 3. 2024 jediný pohyb 24 200 Kč s VS 2024001. Z banky přišly dva pohyby
     * téhož dne a částky: nejdřív platba bez VS (Money ji nemá), pak platba s VS 2024001.
     * Párování bere první vhodný protějšek: pohyb bez VS „spotřebuje“ pohyb Money, smaže se,
     * a pohyb s VS zůstane jako duplicita k pohybu z Money.
     */
    public function testApiMovementWithSymbolWinsOverMovementWithoutSymbol(): void
    {
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
             VALUES (?, 'bank_api', 'api-bu', ?, '3000000004', '0100', 'CZK', '2026-02-01', 2)"
        )->execute([$supplierId, hash('sha256', 'api-bu-' . $supplierId)]);
        $statementId = (int) $pdo->lastInsertId();
        $tx = $pdo->prepare(
            "INSERT INTO bank_transactions (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, match_status, import_fingerprint, description)
             VALUES ('statement', ?, ?, '2024-03-20', '24200.00', 'CZK', ?, 'unmatched', ?, ?)"
        );
        $tx->execute(['api-a', $statementId, null, hash('sha256', "a|{$supplierId}"), 'Platba kartou bez VS']);
        $tx->execute(['api-b', $statementId, '2024001', hash('sha256', "b|{$supplierId}"), 'Úhrada s VS']);

        $protocol = $this->import($supplierId);

        $left = $pdo->query("SELECT t.description FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
            WHERE s.supplier_id = {$supplierId} AND s.source = 'bank_api'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['Platba kartou bez VS'], $left, 'Smazat se má duplicita s VS, pohyb bez protějšku zůstat. ' . json_encode($left));
    }
}
