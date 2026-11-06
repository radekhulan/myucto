<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class JournalEntryLinkerTest extends SharedMigrationDbTestCase
{
    public function testAttachSetsSourceOnFirstEntryAndLinksAll(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $first = $this->entry($supplier, $period, 'purchase_invoice');
        $second = $this->entry($supplier, $period, 'purchase_invoice');
        $linker = new JournalEntryLinker($this->db, 'Pohody');
        $doc = $this->document($supplier, 'purchase_invoice');

        self::assertTrue($linker->attach($supplier, null, 'purchase_invoice', 'purchase_invoice', 'purchase_invoice', $doc, [$first, $second]));
        self::assertSame(['source_type' => 'purchase_invoice', 'source_id' => $doc], $this->source($first));
        self::assertNull($this->source($second)['source_id']);
        $links = $this->rows('SELECT entry_id, note FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? ORDER BY entry_id', [$supplier, 'purchase_invoice', $doc]);
        self::assertSame([[$first, 'Převzato z Pohody'], [$second, 'Převzato z Pohody']], array_map(static fn (array $l): array => [(int) $l['entry_id'], $l['note']], $links));
        self::assertSame([$first, $second], $this->postingIds($supplier, 'purchase_invoice', $doc), 'Vlastní předpis tvoří všechny zápisy převzatého dokladu.');

        self::assertFalse($linker->attach($supplier, null, 'purchase_invoice', 'purchase_invoice', 'purchase_invoice', $doc, [$first, $second]), 'opakované navázání není nová vazba');
        self::assertSame([$first, $second], $this->postingIds($supplier, 'purchase_invoice', $doc));
    }

    public function testAttachRetypesOnlyEntryWithExpectedSource(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $manual = $this->entry($supplier, $period, 'manual');
        $second = $this->entry($supplier, $period, 'manual');
        $bank = $this->entry($supplier, $period, 'bank');
        $linker = new JournalEntryLinker($this->db, 'PREMIER', true);
        $doc = $this->document($supplier, 'invoice');

        self::assertTrue($linker->attach($supplier, null, 'invoice', 'manual', 'invoice', $doc, [$manual, $second]));
        self::assertSame(['source_type' => 'invoice', 'source_id' => $doc], $this->source($manual));
        self::assertNull($this->source($second)['source_id']);
        self::assertSame([$manual, $second], $this->postingIds($supplier, 'invoice', $doc));

        $linker->attach($supplier, null, 'invoice', 'manual', 'invoice', 8, [$bank]);
        self::assertSame(['source_type' => 'bank', 'source_id' => null], $this->source($bank), 'zápis s jiným zdrojem se nepřeznačí');
        self::assertSame([], $this->postingIds($supplier, 'invoice', 8));
    }

    public function testInvoiceGroupExcludesPaymentsOpeningEntriesAndOtherAgendas(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $doc = $this->document($supplier, 'invoice');
        $prescription = $this->entry($supplier, $period, 'invoice');
        $bank = $this->entry($supplier, $period, 'bank');
        $cash = $this->entry($supplier, $period, 'cash');
        $opening = $this->entry($supplier, $period, 'opening', $period);
        $manual = $this->entry($supplier, $period, 'manual');
        $linker = new JournalEntryLinker($this->db, 'Pohody');

        $linker->attach($supplier, null, 'invoice', 'invoice', 'invoice', $doc, [$prescription, $bank, $cash, $opening, $manual]);
        self::assertSame([$prescription], $this->postingIds($supplier, 'invoice', $doc));
        self::assertCount(5, $this->rows('SELECT entry_id FROM journal_entry_document_links WHERE supplier_id=? AND doc_type=? AND doc_id=?', [$supplier, 'invoice', $doc]), 'Informativní souvislosti se zachovají i bez vlastnictví.');
        self::assertSame(['source_type' => 'manual', 'source_id' => null], $this->source($manual), 'Ruční zápis z jiné agendy není doložený předpis faktury.');
    }

    public function testPaymentSourceWithInvoiceLinkDoesNotCreatePostingGroup(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $doc = $this->document($supplier, 'invoice');
        $entry = $this->entry($supplier, $period, 'manual');
        (new JournalEntryLinker($this->db, 'PREMIER'))->attach($supplier, null, 'bank', 'manual', 'invoice', $doc, [$entry]);

        self::assertSame(['source_type' => 'bank', 'source_id' => $doc], $this->source($entry));
        self::assertSame([], $this->postingIds($supplier, 'invoice', $doc));
        self::assertCount(1, $this->rows('SELECT entry_id FROM journal_entry_document_links WHERE supplier_id=? AND entry_id=?', [$supplier, $entry]));
    }

    public function testNewFlagDiffersOnlyWhenSourceIsNotChanged(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $entry = $this->entry($supplier, $period, 'bank');
        $this->db->pdo()->prepare('INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, ?, ?)')
            ->execute([$supplier, $entry, 'invoice', 9]);

        // Zápis vazbu už má a přeznačit nejde: Money S3 a POHODA to počítají jako novou vazbu, PREMIER ne.
        self::assertTrue((new JournalEntryLinker($this->db, 'Pohody'))->attach($supplier, null, 'invoice', 'invoice', 'invoice', 9, [$entry]));
        self::assertFalse((new JournalEntryLinker($this->db, 'PREMIER', true))->attach($supplier, null, 'invoice', 'manual', 'invoice', 9, [$entry]));
    }

    public function testLinkOpeningUsesOpeningEntryOfPeriod(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $linker = new JournalEntryLinker($this->db, 'PREMIER');

        self::assertFalse($linker->linkOpening($supplier, null, $period, 'invoice', 11), 'bez otevíracího zápisu není na co navázat');
        self::assertFalse($linker->linkOpening($supplier, null, null, 'invoice', 11));

        $opening = $this->entry($supplier, $period, 'opening', $period, '2025-01-01');
        self::assertTrue($linker->linkOpening($supplier, null, $period, 'invoice', 11));
        self::assertFalse($linker->linkOpening($supplier, null, $period, 'invoice', 11));
        self::assertSame(
            ['entry_id' => $opening, 'note' => OpeningBalanceDocuments::NOTE . ' (převzato z PREMIER)'],
            (static fn (array $r): array => ['entry_id' => (int) $r['entry_id'], 'note' => $r['note']])(
                $this->row('SELECT entry_id, note FROM journal_entry_document_links WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?', [$supplier, 'invoice', 11])
            ),
        );
        self::assertSame([], $this->postingIds($supplier, 'invoice', 11));
    }

    public function testCashDocumentGetsFirstEntry(): void
    {
        $supplier = $this->supplier();
        $period = $this->period($supplier, 2025);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, 'Pokladna', '211', 'CZK', 1)")->execute([$supplier]);
        $register = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, status)
                       VALUES (?, ?, 'in', 'sale', 'P1', '2025-03-01', 'Tržba', 100, 'posted')")->execute([$supplier, $register]);
        $cash = (int) $pdo->lastInsertId();
        $entry = $this->entry($supplier, $period, 'cash');

        (new JournalEntryLinker($this->db, 'Money S3'))->attach($supplier, null, 'cash', 'cash', 'cash', $cash, [$entry]);
        self::assertSame($entry, (int) $this->row('SELECT journal_entry_id FROM cash_documents WHERE id = ?', [$cash])['journal_entry_id']);
    }

    public function testMarkBankBookedWithoutDocumentRespectsCondition(): void
    {
        $supplier = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, statement_date) VALUES (?, 'import', 'x.import', ?, '1000000005', '2025-03-31')")
            ->execute([$supplier, hash('sha256', 'shared-linker-' . $supplier)]);
        $statement = (int) $pdo->lastInsertId();
        $tx = [];
        foreach (['2025-03-01', '2025-03-02', '2025-03-03'] as $i => $date) {
            $pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint) VALUES ('statement', ?, ?, ?, 'CZK', ?)")
                ->execute([$statement, $date, -10 - $i, hash('sha256', 'shared-linker-tx-' . $supplier . '-' . $i)]);
            $tx[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx[2]]);
        $linker = new JournalEntryLinker($this->db, 'Pohody');

        $marked = $linker->markBankBookedWithoutDocument($supplier, null, [$tx[0], $tx[1], $tx[2], $tx[0]], 'pohoda_booked', 'Zaúčtováno bez dokladu.', 't.id <> ' . $tx[1]);
        self::assertSame(1, $marked);
        $state = $this->rows('SELECT id, match_status, match_reason, ignore_note FROM bank_transactions WHERE statement_id = ? ORDER BY id', [$statement]);
        self::assertSame(['ignored', 'pohoda_booked', 'Zaúčtováno bez dokladu.'], [$state[0]['match_status'], $state[0]['match_reason'], $state[0]['ignore_note']]);
        self::assertSame('unmatched', $state[1]['match_status'], 'podmínka volajícího pohyb vyřadila');
        self::assertSame('manual', $state[2]['match_status'], 'spárovaný pohyb se nemění');
        self::assertSame(0, $linker->markBankBookedWithoutDocument($supplier, null, [], 'pohoda_booked', 'x'));
    }

    /** Skutečný syntetický doklad ověřuje i vlastnictví firmy, nikoli jen číslo v měkké vazbě. */
    private function document(int $supplier, string $type): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
            SELECT id, 'Syntetický partner', 'Test 1', 'Brno', '60200', country_id, default_currency_id FROM supplier WHERE id=?")
            ->execute([$supplier]);
        $client = (int) $pdo->lastInsertId();
        $currency = (int) $this->row('SELECT default_currency_id FROM supplier WHERE id=?', [$supplier])['default_currency_id'];
        if ($type === 'invoice') {
            $pdo->prepare("INSERT INTO invoices (supplier_id, client_id, currency_id, varsymbol, issue_date, tax_date, due_date, created_by)
                VALUES (?, ?, ?, 'TEST-LINK', '2025-03-01', '2025-03-01', '2025-03-15', ?)")
                ->execute([$supplier, $client, $currency, $this->userId]);
        } else {
            $pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, currency_id, varsymbol, vendor_invoice_number,
                vendor_snapshot, issue_date, tax_date, due_date, received_at, created_by)
                VALUES (?, ?, ?, 'TEST-LINK', 'TEST-LINK', '{}', '2025-03-01', '2025-03-01', '2025-03-15', '2025-03-01', ?)")
                ->execute([$supplier, $client, $currency, $this->userId]);
        }
        return (int) $pdo->lastInsertId();
    }

    /** @return array{source_type:string,source_id:?int} */
    private function source(int $entryId): array
    {
        $r = $this->row('SELECT source_type, source_id FROM journal_entries WHERE id = ?', [$entryId]);
        return ['source_type' => (string) $r['source_type'], 'source_id' => $r['source_id'] !== null ? (int) $r['source_id'] : null];
    }

    /** @return list<int> */
    private function postingIds(int $supplier, string $type, int $doc): array
    {
        return array_map(static fn (array $row): int => (int) $row['entry_id'], $this->rows(
            'SELECT entry_id FROM journal_document_postings WHERE supplier_id=? AND doc_type=? AND doc_id=? ORDER BY entry_id',
            [$supplier, $type, $doc],
        ));
    }
}
