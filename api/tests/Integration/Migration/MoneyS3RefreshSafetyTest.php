<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Opakovaný převod Money S3 nesmí přepsat, co se v MyÚčtu upravilo, ani smazat, co Money
 * nenahradí: aktualizace otevřeného období (zápisy a doklady změněné v Money), pohyby
 * stažené z banky na účtu Money a opravný převod vazeb pod zámkem k datu.
 */
#[Group('integration')]
final class MoneyS3RefreshSafetyTest extends MoneyS3ImportTestCase
{
    /**
     * Firma převedená před migrací 2334 (otisk v mapě je NULL). Účetní v MyÚčtu opravila
     * účet nákladu FP25001 (518 -> 568). Money zápis nezměnilo. Opakovaný převod ale opravu
     * vrátí: bez uloženého otisku nejde odlišit změnu v Money od úpravy v MyÚčtu.
     */
    public function testLegacyEntryCorrectedInMyUctoIsNotRevertedWhenMoneyUnchanged(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $entryId = $this->entryOf($supplierId, 'FP25001');
        // Stav firmy převedené před zavedením otisků.
        $this->db->pdo()->prepare("UPDATE money_s3_import_map SET fingerprint = NULL WHERE supplier_id = ?")->execute([$supplierId]);
        $this->moveLine($supplierId, $entryId, '518.000', '568.000');

        $protocol = $this->import($supplierId);

        // Rekonciliace K1 zdvojení odhalí, ale kroky deníku už jsou zapsané (každý krok má vlastní transakci).
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertArrayHasKey('568.000', $this->entryNet($entryId), 'Oprava účetní v MyÚčtu nesmí zmizet, když Money zápis nezměnilo. counts=' . json_encode($journal['counts'] ?? []));
    }

    /**
     * Uživatel v MyÚčtu přiřadil řádku převzatého zápisu vlastní dimenzi (typ, který převod
     * nespravuje). Money pak zápis změní, převod ho přepíše a dimenzi ztratí.
     */
    public function testUserDimensionOnLineSurvivesRefreshOrEntryIsKept(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $entryId = $this->entryOf($supplierId, 'FP25001');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind) VALUES (?, 'obchodnik', 'Obchodník', 'custom')")->execute([$supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'NOVAK', 'Novák')")->execute([$typeId, $supplierId]);
        $valueId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin)
             SELECT l.id, ?, ?, ?, 'manual' FROM journal_entry_lines l WHERE l.entry_id = ? AND l.supplier_id = ?"
        )->execute([$typeId, $supplierId, $valueId, $entryId, $supplierId]);
        $before = (int) $pdo->query("SELECT COUNT(*) FROM journal_entry_line_dimensions WHERE dimension_type_id = {$typeId}")->fetchColumn();
        self::assertGreaterThan(0, $before);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithFp25001On501());

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $kept = array_key_exists('518.000', $this->entryNet($entryId));
        $dims = (int) $pdo->query("SELECT COUNT(*) FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id
            WHERE l.entry_id = {$entryId} AND d.dimension_type_id = {$typeId}")->fetchColumn();
        self::assertTrue($kept || $dims > 0, 'Zápis s dimenzí zadanou v MyÚčtu se buď nepřepíše, nebo dimenzi zachová.');
    }

    /**
     * Přiznání DPH za 5/2025 je v MyÚčtu podané. Uživatel zrušil zámek k datu, jak radí
     * chyba date_locked. Money pak změní FV25001 (plnění 20. 5. 2025) a převod doklad
     * v podaném období přepíše bez varování.
     */
    public function testDocumentInFiledVatPeriodIsNotRewritten(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, form_variant, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
             VALUES (?, 'dphdp3', 2025, 5, 'B', '', 0, ?, 'submitted', NOW())"
        )->execute([$supplierId, str_repeat('0', 64)]);
        $this->container(AccountingSupplierSettingsRepository::class)->setLockedUntil($supplierId, null);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithFv25001Changed());

        $protocol = $this->import($supplierId);

        // Doklad i jeho zápis zůstanou, rozdíl proti Money ukáže rekonciliace jako rozdíl k přijetí.
        self::assertTrue(!$protocol->hasErrors() || $protocol->acceptableOnly(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV25001' AND total_with_vat = 1210.00"),
            'Doklad v podaném období DPH převod nepřepisuje, jen ohlásí. issued=' . json_encode(array_column($protocol->toArray()['steps'], null, 'key')['issued_invoices']['counts'] ?? []));
    }

    /**
     * Money opravilo číslo dokladu dodavatele (PrijatDokl, evidenční číslo pro KH).
     * Otisk zdroje se liší, převod doklad „aktualizuje“, číslo ale nepřevezme a nový otisk
     * změnu zahladí: příští převod ji už ani neohlásí.
     */
    public function testVendorNumberChangedInMoneyIsAppliedOrReported(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/PFaktury.DAT',
            static fn (array $r): array => trim((string) $r['PrijatDokl']) === 'DF-2025-003' ? ['PrijatDokl' => 'DF-2025-003A'] + $r : $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $applied = $this->rowCount('purchase_invoices', $supplierId, "vendor_invoice_number = 'DF-2025-003A'") === 1;
        $step = array_column($protocol->toArray()['steps'], null, 'key')['purchase_invoices'] ?? [];
        $reported = in_array('changed_in_money', array_column($step['messages'] ?? [], 'code'), true);
        self::assertTrue($applied || $reported, 'Změna čísla dokladu dodavatele v Money se převezme, nebo aspoň ohlásí. ' . json_encode($step['counts'] ?? []));
    }

    /**
     * Vydaná faktura převzatá z Money byla z MyÚčta odeslaná e-mailem (sent_at, uložené PDF).
     * Money ji pak změní. Převod přepíše částky a PDF v cache (pdf_path) zůstane staré.
     */
    public function testSentInvoiceWithCachedPdfIsKeptOrPdfInvalidated(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $this->db->pdo()->prepare("UPDATE invoices SET sent_at = NOW(), pdf_path = 'probe/FV25001.pdf', pdf_generated_at = NOW() WHERE supplier_id = ? AND varsymbol = 'FV25001'")
            ->execute([$supplierId]);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithFv25001Changed());

        $protocol = $this->import($supplierId);

        self::assertTrue(!$protocol->hasErrors() || $protocol->acceptableOnly(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV25001' AND (total_with_vat = 1210.00 OR pdf_path IS NULL)"),
            'Odeslaná faktura se nepřepíše, nebo se aspoň zahodí uložené PDF se starými částkami.');
    }

    /** Vydaná faktura aktualizovaná podle Money nesmí nechat v cache PDF se starými částkami. */
    public function testUpdatedInvoiceDropsCachedPdf(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $this->db->pdo()->prepare("UPDATE invoices SET pdf_path = 'probe/FV25001.pdf', pdf_generated_at = NOW() WHERE supplier_id = ? AND varsymbol = 'FV25001'")
            ->execute([$supplierId]);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $this->filesWithFv25001Changed());

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol = 'FV25001' AND total_with_vat = 1331.00 AND pdf_path IS NULL"), $this->explain($protocol));
    }

    /**
     * Opravný převod vazeb (relinkOnly) deník nemění a podle ImportContext::writable() smí
     * měnit vazby i pod zámkem. Zámek k datu v otevřeném roce ho ale nově zastaví.
     */
    public function testRelinkRunsUnderDateLock(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $this->container(AccountingSupplierSettingsRepository::class)->setLockedUntil($supplierId, '2025-03-31');

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, true, relinkOnly: true));

        $codes = [];
        foreach ($protocol->toArray()['steps'] as $st) { foreach ($st['messages'] ?? [] as $m) { if (($m['level'] ?? '') === 'error') $codes[] = $m['code']; } }
        self::assertFalse($protocol->hasErrors(), 'errors=' . json_encode($codes) . ' preflight=' . json_encode(array_column(array_filter($protocol->get('preflight'), fn ($m) => $m['level'] === 'error'), 'code')));
    }

    /**
     * Zápis FP25001 má v MyÚčtu poznámku (zásah uživatele). Money změní datum dokladu, takže
     * se změní klíč zápisu `rok|zdroj|číslo|datum`. Převod založí nový zápis pod novým klíčem
     * a starý (dotčený) nechá - doklad je v deníku dvakrát.
     */
    public function testTouchedEntryWithChangedDateInMoneyIsNotDoubled(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $entryId = $this->entryOf($supplierId, 'FP25001');
        $this->db->pdo()->prepare("INSERT INTO journal_entry_notes (supplier_id, entry_id, body, created_by) VALUES (?, ?, 'Konzultováno s dodavatelem', ?)")
            ->execute([$supplierId, $entryId, $this->userId]);
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/UcDenik.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FP25001' ? ['Datum' => '2025-01-16'] + $r : $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $protocol = $this->import($supplierId);

        // Rekonciliace K1 zdvojení odhalí, ale kroky deníku už jsou zapsané (každý krok má vlastní transakci).
        $journal = array_column($protocol->toArray()['steps'], null, 'key')['journal'];
        self::assertSame(1, $this->rowCount('journal_entries', $supplierId, "document_no = 'FP25001' AND entry_date >= '2025-01-01' AND reversed_by IS NULL"),
            'FP25001 je v deníku jen jednou. counts=' . json_encode($journal['counts'] ?? []));
    }

    /**
     * Zápis FP25001 má zásah uživatele (poznámka; stejně párování saldokonta), takže ho
     * převod nechá. Doklad FP25001 zásah nemá a převod ho přepíše na novou částku z Money:
     * doklad 6 655 Kč, zápis na 321 dál 6 050 Kč.
     */
    public function testDocumentIsNotRewrittenWhenItsJournalEntryIsKept(): void
    {
        $supplierId = $this->supplier();
        self::assertFalse($this->import($supplierId)->hasErrors());
        $entryId = $this->entryOf($supplierId, 'FP25001');
        $this->db->pdo()->prepare("INSERT INTO journal_entry_notes (supplier_id, entry_id, body, created_by) VALUES (?, ?, 'Spárováno ručně', ?)")
            ->execute([$supplierId, $entryId, $this->userId]);
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/PFaktury.DAT',
            static fn (array $r): array => trim((string) $r['PrijatDokl']) === 'DF-2025-003' ? ['Zaklad_2' => 5500.0, 'DPH_2' => 1155.0, 'CelkemSDPH' => 6655.0] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', static fn (array $r): array => trim((string) $r['Doklad']) !== 'FP25001' ? $r
            : ['Castka' => (string) $r['UcMD'] === '518000' ? 5500.0 : 1155.0] + $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);

        $this->import($supplierId);

        $entry321 = -($this->entryNet($entryId)['321.000'] ?? 0.0);
        $doc = (float) $this->db->pdo()->query("SELECT total_with_vat FROM purchase_invoices WHERE supplier_id = {$supplierId} AND vendor_invoice_number = 'DF-2025-003'")->fetchColumn();
        self::assertSame($entry321, $doc, 'Doklad a jeho zápis na 321 se nerozejdou (zápis zůstal, doklad se přepsal).');
    }

    /**
     * API pohyb na účtu BU, který v Money nemá protějšek (účetní ho do Money nezapsala),
     * s datem před posledním pohybem Money. Převod ho smaže a nic ho nenahradí.
     */
    public function testApiMovementWithoutMoneyCounterpartIsNotDeleted(): void
    {
        $supplierId = $this->supplier();
        $this->apiStatement($supplierId, [['2024-04-10', '777.00', '999']]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->movements($supplierId, '2024-04-10', '777.00'), 'Pohyb z banky, který Money nemá, převod nesmaže (nebo ho aspoň vyjmenuje).');
    }

    /**
     * Pohyb z API s protějškem v Money, který uživatel zařadil v peněžním deníku (vazba mimo
     * párování): převod ho nesmaže a zastaví se s výpisem.
     */
    public function testApiMovementClassifiedInMyUctoStopsImport(): void
    {
        $supplierId = $this->supplier();
        $this->apiStatement($supplierId, [['2024-03-20', '24200.00', '2024001']]);
        $pdo = $this->db->pdo();
        $txId = (int) $pdo->query("SELECT t.id FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id WHERE s.supplier_id = {$supplierId} AND t.source_ref = 'api-0'")->fetchColumn();
        $pdo->prepare("INSERT INTO de_movement_classification (supplier_id, source_type, bank_transaction_id, tax_bucket) VALUES (?, 'bank', ?, 'income_taxable')")
            ->execute([$supplierId, $txId]);

        $protocol = $this->import($supplierId);

        $bank = array_column($protocol->toArray()['steps'], null, 'key')['bank'] ?? [];
        self::assertContains('bank_foreign_movements_used', array_column($bank['messages'] ?? [], 'code'), $this->explain($protocol));
        self::assertSame(1, $this->movements($supplierId, '2024-03-20', '24200.00'));
    }

    /**
     * Pohyb z API, který má v Money protějšek, převod smaže a v protokolu vyjmenuje; pohyb
     * bez protějšku nechá a vyjmenuje zvlášť.
     */
    public function testDuplicateApiMovementIsDeletedAndListed(): void
    {
        $supplierId = $this->supplier();
        $this->apiStatement($supplierId, [['2024-03-20', '24200.00', '2024001'], ['2024-04-10', '777.00', '999']]);

        $protocol = $this->import($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->movements($supplierId, '2024-03-20', '24200.00'));
        self::assertSame(1, $this->movements($supplierId, '2024-04-10', '777.00'));
        $messages = array_column(array_column($protocol->toArray()['steps'], null, 'key')['bank']['messages'], null, 'code');
        self::assertSame(['2024-03-20'], array_column($messages['foreign_movements_deleted']['context']['movements'], 'posted_at'));
        self::assertSame(['2024-04-10'], array_column($messages['foreign_movements_without_counterpart']['context']['movements'], 'posted_at'));
    }

    /**
     * Účet z Money bez kódu banky a IBANu: jsou-li v MyÚčtu výpisy stejného čísla u dvou
     * bank, převod pohyby nemaže a zastaví se.
     */
    public function testAccountWithoutBankCodeMatchingTwoBanksIsAnError(): void
    {
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.001/SzUcPokl.DAT', static fn (array $r): array => trim((string) $r['Zkrat']) === 'BU' ? ['BKod' => ''] + $r : $r);
        $files = SyntheticAgenda::patch($files, 'ROK.002/SzUcPokl.DAT', static fn (array $r): array => trim((string) $r['Zkrat']) === 'BU' ? ['BKod' => ''] + $r : $r);
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
        $supplierId = $this->supplier();
        $this->apiStatement($supplierId, [['2024-03-20', '24200.00', '2024001']]);
        $this->apiStatement($supplierId, [['2024-03-20', '24200.00', '2024001']], '0800');

        $protocol = $this->import($supplierId);

        $bank = array_column($protocol->toArray()['steps'], null, 'key')['bank'] ?? [];
        self::assertContains('bank_account_ambiguous', array_column($bank['messages'] ?? [], 'code'), $this->explain($protocol));
        self::assertSame(2, $this->movements($supplierId, '2024-03-20', '24200.00'));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    /** @param list<array{0:string,1:string,2:string}> $movements */
    private function apiStatement(int $supplierId, array $movements, string $bankCode = '0100'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date, transaction_count)
             VALUES (?, 'bank_api', 'api-bu', ?, '3000000004', ?, 'CZK', '2026-02-01', ?)"
        )->execute([$supplierId, hash('sha256', 'api-bu-' . $supplierId . $bankCode), $bankCode, count($movements)]);
        $statementId = (int) $pdo->lastInsertId();
        $tx = $pdo->prepare(
            "INSERT INTO bank_transactions (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, match_status, import_fingerprint)
             VALUES ('statement', ?, ?, ?, ?, 'CZK', ?, 'unmatched', ?)"
        );
        foreach ($movements as $i => [$date, $amount, $vs]) {
            $tx->execute(["api-{$i}", $statementId, $date, $amount, $vs, hash('sha256', "api|{$supplierId}|{$bankCode}|{$i}")]);
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

    /** @return array<string,string> */
    private function filesWithFv25001Changed(): array
    {
        $files = SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/VFaktury.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FV25001' ? ['Zaklad_2' => 1100.0, 'DPH_2' => 231.0, 'CelkemSDPH' => 1331.0] + $r : $r);
        return SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', static fn (array $r): array => trim((string) $r['Doklad']) !== 'FV25001' ? $r
            : ['Castka' => (string) $r['UcD'] === '602000' ? 1100.0 : 231.0] + $r);
    }

    /** @return array<string,string> */
    private function filesWithFp25001On501(): array
    {
        return SyntheticAgenda::patch(SyntheticAgenda::files(), 'ROK.002/UcDenik.DAT',
            static fn (array $r): array => trim((string) $r['Doklad']) === 'FP25001' && (string) $r['UcMD'] === '518000' ? ['UcMD' => '501100'] + $r : $r);
    }

    private function moveLine(int $supplierId, int $entryId, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            "UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                SET l.account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?)
              WHERE l.entry_id = ? AND a.account_code = ?"
        )->execute([$supplierId, $to, $entryId, $from]);
    }

    private function entryOf(int $supplierId, string $documentNo): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND document_no = ? AND entry_date >= '2025-01-01'");
        $stmt->execute([$supplierId, $documentNo]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,float> */
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
