<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Migration\Abra;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\Abra\AbraAccountingImporter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vazby převzatých dokladů ABRA Flexi, ze kterých čte saldokonto: doklady počátečních
 * stavů, dobropisy, čerpání přijatých záloh a vyrovnání interním dokladem.
 */
#[Group('integration')]
final class AbraDocumentRelationsTest extends TestCase
{
    private Connection $db;
    private AbraAccountingImporter $importer;
    private int $supplier;
    private int $user;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(AbraAccountingImporter::class);
        $pdo = $this->db->pdo();
        $base = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base);
        self::assertGreaterThan(0, $this->user);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO supplier (company_name, ic, street, city, zip, country_id, email, default_currency_id,
            default_vat_rate_id, accounting_enabled, accounting_mode)
            SELECT "Synthetic ABRA target", "88888888", "Testovací", "Praha", "11000", country_id,
                "abra-target@example.com", default_currency_id, default_vat_rate_id, 1, "double_entry" FROM supplier WHERE id = ?')->execute([$base]);
        $this->supplier = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, "CZK", "CZK", "Kč", "Koruna", "Koruna", 2, 1, 1)')->execute([$this->supplier]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $this->supplier]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testPreviousYearInvoiceIsLinkedToOpeningEntryOfItsReceivableAccount(): void
    {
        $snapshot = $this->snapshot([
            $this->entry('3', '2025-06-02', '221000', '311000', '71.00', 'banka/301'),
        ]);
        $snapshot['faktura-vydana'] = [$this->issued('101', 'SYN-2024-001', '2024-12-20', '121.00', [
            'datSplat' => '2025-01-05', 'zbyvaUhradit' => '50.00', 'primUcet' => 'code:311000',
        ])];
        $snapshot['banka'] = [$this->bank('301', '2025-06-02', '71.00')];
        $snapshot['vazba'] = [$this->link('401', 'faktura-vydana/101', 'banka/301', '71.00', 'uhrada')];
        $snapshot['stav-uctu'] = [
            ['ucet' => 'code:221000', 'mena' => 'code:CZK', 'pocatekMD' => '50.00', 'pocatekDal' => '0.00', 'postingPeriod' => 'code:2025'],
            ['ucet' => 'code:311000', 'mena' => 'code:CZK', 'pocatekMD' => '121.00', 'pocatekDal' => '0.00', 'postingPeriod' => 'code:2025'],
            ['ucet' => 'code:343000', 'mena' => 'code:CZK', 'pocatekMD' => '0.00', 'pocatekDal' => '171.00', 'postingPeriod' => 'code:2025'],
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $link = $this->db->pdo()->query("SELECT a.account_code FROM journal_entry_document_links dl
            JOIN invoices i ON i.id = dl.doc_id AND dl.doc_type = 'invoice'
            JOIN journal_entries e ON e.id = dl.entry_id AND e.source_type = 'opening'
            JOIN journal_entry_lines l ON l.entry_id = e.id AND l.side = 'debit'
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE dl.supplier_id = {$this->supplier}")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['311000'], $link, 'Doklad musí ukazovat na otevírací zápis svého saldokontního účtu.');
        self::assertSame(['asset', 'debit'], $this->db->pdo()->query("SELECT account_type, normal_side FROM chart_of_accounts
            WHERE supplier_id = {$this->supplier} AND account_code = '311'")->fetch(\PDO::FETCH_NUM),
            'Aktivní účet třídy 3 (druhUctu.aktivni) je pohledávka, ne závazek.');
        $saldo = $this->saldo('311');
        self::assertEqualsWithDelta(50.0, $saldo['gl_balance'], 0.001);
        self::assertEqualsWithDelta(50.0, $saldo['open_items_total'], 0.001);
        self::assertEqualsWithDelta(0.0, $saldo['difference'], 0.001);

        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        self::assertSame(1, $this->rowCount('journal_entry_document_links', "doc_type = 'invoice' AND note LIKE 'Zastoupeno%'"));
    }

    public function testCreditNoteIsLinkedToCorrectedInvoiceAndOffsetWithoutPayment(): void
    {
        $snapshot = $this->snapshot([
            $this->entry('1', '2025-06-01', '311000', '602000', '100.00', 'faktura-vydana/101'),
            $this->entry('2', '2025-06-01', '311000', '343000', '21.00', 'faktura-vydana/101'),
            $this->entry('3', '2025-06-10', '311000', '602000', '-17.36', 'faktura-vydana/102'),
            $this->entry('4', '2025-06-10', '311000', '343000', '-3.64', 'faktura-vydana/102'),
            $this->entry('5', '2025-06-12', '221000', '311000', '100.00', 'banka/301'),
        ]);
        $snapshot['faktura-vydana'] = [
            $this->issued('101', 'SYN-2025-001', '2025-06-01', '121.00', ['zbyvaUhradit' => '0.00']),
            $this->issued('102', 'SYN-2025-002', '2025-06-10', '-21.00', ['zbyvaUhradit' => '0.00',
                'typDokl' => 'code:DOBROPIS', 'sumZklCelkem' => '-17.36', 'sumDphCelkem' => '-3.64'],
                ['sumZkl' => '-17.36', 'sumDph' => '-3.64', 'sumCelkem' => '-21.00']),
        ];
        $snapshot['banka'] = [$this->bank('301', '2025-06-12', '100.00')];
        $snapshot['vazba'] = [
            $this->link('401', 'faktura-vydana/101', 'banka/301', '100.00', 'uhrada'),
            $this->link('402', 'faktura-vydana/101', 'faktura-vydana/102', '0.00', 'hlavaDobropis'),
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $pdo = $this->db->pdo();
        $note = $pdo->query("SELECT id, parent_invoice_id FROM invoices WHERE supplier_id = {$this->supplier} AND invoice_type = 'credit_note'")
            ->fetch(\PDO::FETCH_ASSOC);
        $invoice = (int) $pdo->query("SELECT id FROM invoices WHERE supplier_id = {$this->supplier} AND invoice_type = 'invoice'")->fetchColumn();
        self::assertSame($invoice, (int) $note['parent_invoice_id']);
        $offset = $pdo->query("SELECT invoice_id, credit_note_id, amount, invoice_payment_id FROM credit_note_offsets
            WHERE supplier_id = {$this->supplier}")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($offset);
        self::assertSame($invoice, (int) $offset['invoice_id']);
        self::assertSame((int) $note['id'], (int) $offset['credit_note_id']);
        self::assertEquals(21.0, (float) $offset['amount']);
        self::assertSame(['credit_note' => '21.00', 'bank' => '100.00'], $pdo->query("SELECT source, amount FROM invoice_payments
            WHERE invoice_id = {$invoice} ORDER BY source = 'bank', id")->fetchAll(\PDO::FETCH_KEY_PAIR));

        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        self::assertSame(1, $this->rowCount('credit_note_offsets'));
    }

    public function testCreditNoteRefundedByBankIsLinkedButNotOffset(): void
    {
        $snapshot = $this->snapshot([
            $this->entry('1', '2025-06-01', '311000', '602000', '121.00', 'faktura-vydana/101'),
            $this->entry('3', '2025-06-10', '311000', '602000', '-21.00', 'faktura-vydana/102'),
            $this->entry('5', '2025-06-12', '311000', '221000', '21.00', 'banka/301'),
        ]);
        $snapshot['faktura-vydana'] = [
            $this->issued('101', 'SYN-2025-001', '2025-06-01', '121.00', ['zbyvaUhradit' => '121.00']),
            $this->issued('102', 'SYN-2025-002', '2025-06-10', '-21.00', ['zbyvaUhradit' => '0.00',
                'typDokl' => 'code:DOBROPIS', 'sumZklCelkem' => '-17.36', 'sumDphCelkem' => '-3.64'],
                ['sumZkl' => '-17.36', 'sumDph' => '-3.64', 'sumCelkem' => '-21.00']),
        ];
        $snapshot['banka'] = [$this->bank('301', '2025-06-12', '-21.00')];
        $snapshot['vazba'] = [
            $this->link('401', 'faktura-vydana/102', 'banka/301', '21.00', 'uhrada'),
            $this->link('402', 'faktura-vydana/101', 'faktura-vydana/102', '0.00', 'hlavaDobropis'),
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->rowCount('invoices', "invoice_type = 'credit_note' AND parent_invoice_id IS NOT NULL"));
        self::assertSame(0, $this->rowCount('credit_note_offsets'));
    }

    public function testAdvanceDeductionsAreRecordedPerAdvanceAndTaxDocumentBelongsToAdvance(): void
    {
        $snapshot = $this->snapshot([
            $this->entry('1', '2025-03-01', '314000', '221000', '121.00', 'banka/301'),
            $this->entry('2', '2025-03-02', '343000', '314000', '21.00', 'faktura-prijata/802'),
            $this->entry('3', '2025-03-05', '314000', '221000', '50.00', 'banka/302'),
            $this->entry('4', '2025-06-01', '501000', '321000', '300.00', 'faktura-prijata/803'),
            $this->entry('5', '2025-06-01', '321000', '314000', '100.00', 'faktura-prijata/803'),
            $this->entry('6', '2025-06-01', '321000', '314000', '48.00', 'faktura-prijata/803'),
            $this->entry('7', '2025-06-01', '563000', '314000', '2.00', 'interni-doklad/950'),
        ]);
        $advance = static fn (string $id, string $code, string $total): array => [
            'id' => $id, 'kod' => $code, 'cisDosle' => $code, 'typDokl' => 'code:ZÁLOHA', 'datVyst' => '2025-02-28',
            'datSplat' => '2025-03-10', 'mena' => 'code:CZK', 'sumZklCelkem' => $total, 'sumDphCelkem' => '0.00',
            'sumCelkem' => $total, 'zbyvaUhradit' => '0.00', 'nazFirmy' => 'Synthetic vendor',
            'firma' => ['id' => '70', 'evidencePath' => 'adresar/70'], 'stat' => 'code:CZ',
            'polozkyDokladu' => [['id' => $id . '1', 'mnozMj' => '1', 'szbDph' => '0', 'sumZkl' => $total,
                'sumDph' => '0.00', 'sumCelkem' => $total, 'clenDph' => 'code:000U']],
        ];
        $snapshot['faktura-prijata'] = [
            $advance('801', 'SYN-FPZ-1', '121.00'),
            $advance('804', 'SYN-FPZ-2', '50.00'),
            ['id' => '802', 'kod' => 'SYN-ZDD-1', 'cisDosle' => 'SYN-ZDD-1', 'typDokl' => 'code:ZDD', 'datVyst' => '2025-03-02',
                'datUcto' => '2025-03-02', 'datSplat' => '2025-03-02', 'mena' => 'code:CZK', 'zuctovano' => true,
                'sumZklCelkem' => '100.00', 'sumDphCelkem' => '21.00', 'sumCelkem' => '121.00', 'zbyvaUhradit' => '0.00',
                'nazFirmy' => 'Synthetic vendor', 'firma' => ['id' => '70', 'evidencePath' => 'adresar/70'], 'stat' => 'code:CZ',
                'polozkyDokladu' => [['id' => '8021', 'mnozMj' => '1', 'szbDph' => '21', 'sumZkl' => '100.00',
                    'sumDph' => '21.00', 'sumCelkem' => '121.00', 'clenDph' => 'code:40-41']]],
            ['id' => '803', 'kod' => 'SYN-FPS-1', 'cisDosle' => 'SYN-FPS-1', 'typDokl' => 'code:FAKTURA', 'datVyst' => '2025-06-01',
                'datUcto' => '2025-06-01', 'datSplat' => '2025-06-15', 'mena' => 'code:CZK', 'zuctovano' => true,
                'sumZklCelkem' => '152.00', 'sumDphCelkem' => '0.00', 'sumCelkem' => '152.00', 'zbyvaUhradit' => '152.00',
                'nazFirmy' => 'Synthetic vendor', 'firma' => ['id' => '70', 'evidencePath' => 'adresar/70'], 'stat' => 'code:CZ',
                'polozkyDokladu' => [
                    ['id' => '8031', 'mnozMj' => '1', 'szbDph' => '0', 'sumZkl' => '300.00', 'sumDph' => '0.00', 'sumCelkem' => '300.00', 'clenDph' => 'code:000U'],
                    ['id' => '8032', 'mnozMj' => '1', 'szbDph' => '0', 'sumZkl' => '-100.00', 'sumDph' => '-21.00', 'sumCelkem' => '-121.00', 'clenDph' => 'code:000U'],
                    ['id' => '8033', 'mnozMj' => '1', 'szbDph' => '0', 'sumZkl' => '-48.00', 'sumDph' => '0.00', 'sumCelkem' => '-48.00', 'clenDph' => 'code:000U'],
                ]],
        ];
        $snapshot['banka'] = [$this->bank('301', '2025-03-01', '-121.00'), $this->bank('302', '2025-03-05', '-50.00')];
        $snapshot['vazba'] = [
            $this->link('501', 'faktura-prijata/801', 'banka/301', '121.00', 'uhradaZALOHY'),
            $this->link('502', 'faktura-prijata/802', 'banka/301', '121.00', 'vazbaZDD'),
            $this->link('503', 'faktura-prijata/804', 'banka/302', '50.00', 'uhradaZALOHY'),
            $this->link('504', 'faktura-prijata-polozka/8032', 'faktura-prijata/802', '0.00', 'odpocetZDD'),
            $this->link('505', 'faktura-prijata-polozka/8033', 'faktura-prijata/804', '0.00', 'odpocetZALOHY'),
            $this->link('506', 'faktura-prijata/804', 'interni-doklad/950', '2.00', 'zalHlavKr'),
            $this->link('507', 'faktura-prijata/803', 'interni-doklad/950', '2.00', 'odpocetZddKr'),
        ];
        foreach (['314', '501', '563', '321', '314000', '501000', '563000', '321000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $pdo = $this->db->pdo();
        $ids = $pdo->query("SELECT vendor_invoice_number, id FROM purchase_invoices WHERE supplier_id = {$this->supplier}")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        $zdd = $pdo->query("SELECT document_kind, parent_purchase_invoice_id FROM purchase_invoices WHERE id = {$ids['SYN-ZDD-1']}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('tax_document', $zdd['document_kind']);
        self::assertSame((int) $ids['SYN-FPZ-1'], (int) $zdd['parent_purchase_invoice_id']);
        self::assertSame(0, $this->rowCount('payment_matches', "purchase_invoice_id = {$ids['SYN-ZDD-1']}"),
            'Platbu zálohy nese záloha, ZDD ji nesmí započíst podruhé.');
        $deductions = $pdo->query("SELECT advance_invoice_id, amount FROM purchase_advance_deductions
            WHERE supplier_id = {$this->supplier} AND final_invoice_id = {$ids['SYN-FPS-1']} ORDER BY advance_invoice_id")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame([(int) $ids['SYN-FPZ-1'] => '100.00', (int) $ids['SYN-FPZ-2'] => '50.00'], $deductions,
            'Se ZDD se čerpá základ, bez ZDD celá položka; kurzová ztráta zálohy čerpání zvyšuje.');

        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        self::assertSame(2, $this->rowCount('purchase_advance_deductions'));
    }

    public function testExchangeDifferenceDocumentSettlesOnlyUniqueInvoice(): void
    {
        $snapshot = $this->snapshot([
            $this->entry('1', '2025-06-01', '311000', '602000', '121.00', 'faktura-vydana/101'),
            $this->entry('2', '2025-06-01', '311000', '602000', '50.00', 'faktura-vydana/102'),
            $this->entry('3', '2025-06-02', '221000', '311000', '120.00', 'banka/301'),
            $this->entry('4', '2025-06-02', '563000', '311000', '1.00', 'interni-doklad/901'),
            $this->entry('5', '2025-06-03', '563000', '311000', '0.50', 'interni-doklad/902'),
        ]);
        $snapshot['faktura-vydana'] = [
            $this->issued('101', 'SYN-2025-001', '2025-06-01', '121.00', ['zbyvaUhradit' => '0.00']),
            $this->issued('102', 'SYN-2025-002', '2025-06-01', '50.00', ['zbyvaUhradit' => '50.00']),
        ];
        $snapshot['banka'] = [$this->bank('301', '2025-06-02', '120.00')];
        $snapshot['vazba'] = [
            $this->link('401', 'faktura-vydana/101', 'banka/301', '120.00', 'uhrada'),
            $this->link('402', 'faktura-vydana/101', 'interni-doklad/901', '1.00', 'uhradaKR'),
            $this->link('403', 'faktura-vydana/101', 'interni-doklad/902', '0.25', 'uhradaKR'),
            $this->link('404', 'faktura-vydana/102', 'interni-doklad/902', '0.25', 'uhradaKR'),
        ];
        foreach (['563', '563000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $linked = $this->db->pdo()->query("SELECT i.varsymbol, e.document_no FROM journal_entry_document_links dl
            JOIN invoices i ON i.id = dl.doc_id AND dl.doc_type = 'invoice'
            JOIN journal_entries e ON e.id = dl.entry_id AND e.source_type = 'manual'
            JOIN journal_entry_lines l ON l.entry_id = e.id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.account_code = '563000'
            WHERE dl.supplier_id = {$this->supplier}")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $linked, json_encode($linked));
        self::assertSame('SYN-2025-001', $linked[0]['varsymbol']);
        self::assertContains('internal_settlement_requires_review', $report['warnings']);
    }

    private function transfer(array $snapshot): array
    {
        return $this->importer->import($this->supplier, $this->user, $snapshot, [2025], static function (): void {}, static fn (): bool => false);
    }

    /** @param list<array<string,mixed>> $entries */
    private function snapshot(array $entries): array
    {
        $period = ['id' => '1', 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31'];
        return [
            '_meta' => ['mode' => 'initial', 'company' => ['ico' => '88888888', 'base_currency' => 'CZK'], 'periods' => [$period]],
            'ucetni-obdobi' => [$period],
            'ucetni-osnova' => array_map(static fn ($code) => ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account']
                + (in_array(substr($code, 0, 3), ['221', '311'], true)
                    ? ['typUctuK' => 'typUctu.rozvahovy', 'druhUctuK' => 'druhUctu.aktivni'] : []),
                ['221', '311', '343', '602', '701', '221000', '311000', '343000', '602000']),
            'ucetni-denik' => $entries,
            'pohyb-na-uctech' => array_map(static fn ($row) => $row + ['sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz']], $entries),
            'faktura-vydana' => [], 'faktura-prijata' => [], 'pokladni-pohyb' => [], 'banka' => [], 'vazba' => [],
        ];
    }

    private function entry(string $id, string $date, string $debit, string $credit, string $amount, string $document): array
    {
        return ['idUcetniDenik' => $id, 'datUcto' => $date, 'mdUcet' => 'code:' . $debit, 'dalUcet' => 'code:' . $credit,
            'sumTuz' => $amount, 'idDokl@evidencePath' => $document];
    }

    private function issued(string $id, string $code, string $date, string $total, array $extra = [], array $item = []): array
    {
        $base = round((float) $total / 1.21, 2);
        $vat = round((float) $total - $base, 2);
        return $extra + [
            'id' => $id, 'kod' => $code, 'datVyst' => $date, 'datUcto' => $date, 'datSplat' => $date, 'mena' => 'code:CZK',
            'sumZklCelkem' => number_format($base, 2, '.', ''), 'sumDphCelkem' => number_format($vat, 2, '.', ''),
            'sumCelkem' => $total, 'zuctovano' => true, 'nazFirmy' => 'Synthetic partner',
            'firma' => ['id' => '7', 'evidencePath' => 'adresar/7'], 'stat' => 'code:CZ',
            'polozkyDokladu' => [$item + ['id' => $id . '1', 'popis' => 'Synthetic item', 'mnozMj' => '1', 'szbDph' => '21',
                'sumZkl' => number_format($base, 2, '.', ''), 'sumDph' => number_format($vat, 2, '.', ''),
                'sumCelkem' => $total, 'clenDph' => 'code:01-02']],
        ];
    }

    private function bank(string $id, string $date, string $amount): array
    {
        return ['id' => $id, 'kod' => 'SYN-B-' . $id, 'datUcto' => $date, 'mena' => 'code:CZK',
            'sumCelkem' => ltrim($amount, '-'), 'typPohybuK' => str_starts_with($amount, '-') ? 'typPohybu.vydej' : 'typPohybu.prijem',
            'banka' => ['id' => '1', 'evidencePath' => 'bankovni-ucet/1'], 'bankaUcet' => '1000000005', 'bankaKod' => '0100'];
    }

    private function link(string $id, string $a, string $b, string $amount, string $type): array
    {
        [$aEvidence, $aId] = explode('/', $a);
        [$bEvidence, $bId] = explode('/', $b);
        return ['id' => $id, 'typVazbyK' => 'typVazbyDokl.' . $type,
            'a' => $aId, 'a@ref' => '/c/demo/' . $a . '.json', 'b' => $bId, 'b@ref' => '/c/demo/' . $b . '.json',
            'castka' => $amount, 'mena' => 'code:CZK'];
    }

    private function rowCount(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM {$table} WHERE supplier_id = {$this->supplier} AND {$where}")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function saldo(string $account): array
    {
        $period = (int) $this->db->pdo()->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplier} AND fiscal_year = 2025")->fetchColumn();
        $saldo = Bootstrap::buildContainer()->get(SaldoService::class)->build($this->supplier, $period, '2025-12-31', $account);
        foreach ($saldo['accounts'] as $block) {
            if ($block['account']['code'] === $account) return $block;
        }
        self::fail('Účet ' . $account . ' v saldokontu chybí.');
    }
}
