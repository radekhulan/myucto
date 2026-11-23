<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceRenumberService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Číslování přijatých dokladů podle typu (migrace 2001) a přečíslování měsíce podle
 * data vystavení.
 *
 * - Režim `shared` zůstává beze změny: účtenka jede ve společné řadě faktur.
 * - Režim `by_kind`: účtenka má vlastní řadu a vlastní počítadlo, řada faktur její
 *   čísla nebere za svá (dřív {PP} v regexu bral libovolná dvě písmena).
 * - Přečíslování seřadí automaticky očíslované doklady měsíce podle data, ruční čísla
 *   nechá, přepíše číslo i popis v deníku a zamčené období odmítne.
 *
 * Izolace: období 2099-08, doklady se zakládají a mažou v testu.
 */
#[Group('integration')]
final class PurchaseNumberingByKindTest extends TestCase
{
    private const PERIOD = '209908';

    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private PurchaseInvoiceRenumberService $renumber;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];
    /** @var int[] */
    private array $journal = [];
    private ?int $createdPeriodId = null;
    private int $seq = 0;
    /** @var array<string,mixed> */
    private array $origSupplier = [];
    private string|false|null $origLockedUntil = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(PurchaseInvoiceRepository::class);
            $this->renumber = $c->get(PurchaseInvoiceRenumberService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->vendorId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/vendor/CZK currency/user.');
        }

        $this->origSupplier = (array) $pdo->query(
            "SELECT purchase_invoice_number_format, purchase_invoice_numbering, purchase_receipt_number_format
               FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        $locked = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $locked->execute([$this->supplierId]);
        $row = $locked->fetch(PDO::FETCH_ASSOC);
        $this->origLockedUntil = $row === false ? false : ($row['locked_until'] ?? null);

        $this->setSupplier(['purchase_invoice_number_format' => null, 'purchase_invoice_numbering' => 'shared', 'purchase_receipt_number_format' => null]);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        $this->setSupplier($this->origSupplier);
        $pdo = $this->db->pdo();
        if ($this->origLockedUntil === false) {
            $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
        } else {
            $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = ? WHERE supplier_id = ?')
                ->execute([$this->origLockedUntil, $this->supplierId]);
        }
        $this->db->close();
    }

    /** @param array<string,mixed> $values */
    private function setSupplier(array $values): void
    {
        foreach ($values as $col => $value) {
            $this->db->pdo()->prepare("UPDATE supplier SET {$col} = ? WHERE id = ?")->execute([$value, $this->supplierId]);
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->journal as $id) {
            $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$id]);
        }
        $this->journal = [];
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        if ($this->createdPeriodId !== null) {
            $pdo->prepare('DELETE FROM accounting_periods WHERE id = ?')->execute([$this->createdPeriodId]);
            $this->createdPeriodId = null;
        }
        $pdo->prepare("DELETE FROM purchase_invoice_counters WHERE supplier_id = ? AND period LIKE '%2099%'")
            ->execute([$this->supplierId]);
    }

    /** Koncept přijatého dokladu (bez čísla) s daným datem vystavení a typem. */
    private function draft(string $issueDate, string $kind = 'invoice', ?string $varsymbol = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '{}', ?, ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, 'BYKIND-' . (++$this->seq), $kind,
            $issueDate, $issueDate, $issueDate, $this->currencyId, $varsymbol === null ? 'draft' : 'received', $this->userId,
        ]);
        return $this->created[] = (int) $pdo->lastInsertId();
    }

    private function numberOf(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT varsymbol FROM purchase_invoices WHERE id = {$id}")->fetchColumn();
    }

    public function testSharedModeKeepsReceiptsInCommonSeries(): void
    {
        $invoice = $this->draft('2099-08-03');
        $receipt = $this->draft('2099-08-04', 'receipt');

        self::assertSame('PF9908001', $this->repo->ensureVarsymbol($invoice, $this->supplierId));
        self::assertSame('PF9908002', $this->repo->ensureVarsymbol($receipt, $this->supplierId));
    }

    public function testByKindGivesReceiptsOwnSeriesAndCounter(): void
    {
        $this->setSupplier(['purchase_invoice_numbering' => 'by_kind']);
        $receipt1 = $this->draft('2099-08-02', 'receipt');
        $invoice = $this->draft('2099-08-03');
        $receipt2 = $this->draft('2099-08-04', 'receipt');

        self::assertSame('UC9908001', $this->repo->ensureVarsymbol($receipt1, $this->supplierId));
        self::assertSame('PF9908001', $this->repo->ensureVarsymbol($invoice, $this->supplierId),
            'Řada faktur nesmí brát číslo účtenky UC9908001 za své a přeskočit ho.');
        self::assertSame('UC9908002', $this->repo->ensureVarsymbol($receipt2, $this->supplierId));
    }

    public function testInvoiceSeriesJumpIgnoresReceiptNumbers(): void
    {
        // Obsazené PF9908001 vynutí skok za nejvyšší použité číslo řady faktur. Číslo
        // účtenky UC9908005 do řady faktur nepatří, skok smí jít jen na 002.
        $this->setSupplier(['purchase_invoice_numbering' => 'by_kind']);
        $this->draft('2099-08-01', 'invoice', 'PF9908001');
        $this->draft('2099-08-01', 'receipt', 'UC9908005');
        $invoice = $this->draft('2099-08-03');

        self::assertSame('PF9908002', $this->repo->ensureVarsymbol($invoice, $this->supplierId));
    }

    public function testByKindUsesOwnReceiptTemplate(): void
    {
        $this->setSupplier(['purchase_invoice_numbering' => 'by_kind', 'purchase_receipt_number_format' => 'PAR-{YYYY}-{CCCC}']);
        $receipt = $this->draft('2099-08-02', 'receipt');

        self::assertSame('PAR-2099-0001', $this->repo->ensureVarsymbol($receipt, $this->supplierId));
    }

    public function testRenumberOrdersMonthByIssueDateAndKeepsManualNumbers(): void
    {
        // Přijímáno v opačném pořadí, než jsou data → čísla jdou proti datu.
        $late = $this->draft('2099-08-28');
        $mid = $this->draft('2099-08-15');
        $early = $this->draft('2099-08-02');
        foreach ([$late, $mid, $early] as $id) {
            $this->repo->ensureVarsymbol($id, $this->supplierId);
        }
        $manual = $this->draft('2099-08-10', 'invoice', 'RUCNI-77');
        self::assertSame('PF9908001', $this->numberOf($late));
        self::assertSame('PF9908003', $this->numberOf($early));

        $plan = $this->renumber->preview($this->supplierId, '2099-08');
        self::assertSame([], $plan['blocked']);
        self::assertSame(['RUCNI-77'], array_column($plan['kept'], 'varsymbol'));

        $result = $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);

        self::assertSame(2, $result['renumbered'], 'Prostřední doklad číslo 002 už má.');
        self::assertSame('PF9908001', $this->numberOf($early));
        self::assertSame('PF9908002', $this->numberOf($mid));
        self::assertSame('PF9908003', $this->numberOf($late));
        self::assertSame('RUCNI-77', $this->numberOf($manual));

        // Počítadlo stojí na posledním čísle, další přijatý doklad navazuje.
        $next = $this->draft('2099-08-30');
        self::assertSame('PF9908004', $this->repo->ensureVarsymbol($next, $this->supplierId));
    }

    public function testRenumberMovesDocumentToSeriesOfItsCurrentKind(): void
    {
        $this->setSupplier(['purchase_invoice_numbering' => 'by_kind']);
        $doc = $this->draft('2099-08-05');
        $this->repo->ensureVarsymbol($doc, $this->supplierId);
        self::assertSame('PF9908001', $this->numberOf($doc));
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'receipt' WHERE id = ?")->execute([$doc]);

        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);

        self::assertSame('UC9908001', $this->numberOf($doc));
    }

    public function testRenumberRewritesJournalNumberAndDescription(): void
    {
        $second = $this->draft('2099-08-20');
        $first = $this->draft('2099-08-01');
        $this->repo->ensureVarsymbol($second, $this->supplierId);
        $this->repo->ensureVarsymbol($first, $this->supplierId);
        self::assertSame('PF9908001', $this->numberOf($second));

        $pdo = $this->db->pdo();
        $periodId = $pdo->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND fiscal_year = 2099")->fetchColumn();
        if ($periodId === false) {
            $pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2099, '2099-01-01', '2099-12-31', 'open')")
                ->execute([$this->supplierId]);
            $periodId = $this->createdPeriodId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, description, source_type, source_id)
             VALUES (?, ?, '2099-08-20', 'PF9908001', 'PF PF9908001 / dod. X — Dodavatel — PF99080011 jinde', 'purchase_invoice', ?)"
        )->execute([$this->supplierId, (int) $periodId, $second]);
        $this->journal[] = (int) $pdo->lastInsertId();

        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);

        $entry = $pdo->query('SELECT document_no, description FROM journal_entries WHERE id = ' . end($this->journal))->fetch(PDO::FETCH_ASSOC);
        self::assertSame('PF9908002', $entry['document_no']);
        self::assertSame('PF PF9908002 / dod. X — Dodavatel — PF99080011 jinde', $entry['description'],
            'Přepíše se jen celé číslo, ne začátek delšího čísla.');
    }

    public function testRenumberRewritesReversalLinkedOnlyByReversedBy(): void
    {
        $second = $this->draft('2099-08-20');
        $first = $this->draft('2099-08-01');
        $this->repo->ensureVarsymbol($second, $this->supplierId);
        $this->repo->ensureVarsymbol($first, $this->supplierId);

        $pdo = $this->db->pdo();
        $periodId = $pdo->query("SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND fiscal_year = 2099")->fetchColumn();
        if ($periodId === false) {
            $pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, 2099, '2099-01-01', '2099-12-31', 'open')")
                ->execute([$this->supplierId]);
            $periodId = $this->createdPeriodId = (int) $pdo->lastInsertId();
        }
        // Storno nese source_id NULL, s originálem ho váže jen reversed_by.
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, description, source_type, source_id)
             VALUES (?, ?, '2099-08-25', 'STORNO PF9908001', 'Storno PF9908001', 'purchase_invoice', NULL)"
        )->execute([$this->supplierId, (int) $periodId]);
        $reversal = (int) $pdo->lastInsertId();
        $this->journal[] = $reversal;
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, description, source_type, source_id, reversed_by)
             VALUES (?, ?, '2099-08-20', 'PF9908001', 'PF PF9908001', 'purchase_invoice', ?, ?)"
        )->execute([$this->supplierId, (int) $periodId, $second, $reversal]);
        $this->journal[] = (int) $pdo->lastInsertId();

        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);

        $row = $pdo->query('SELECT document_no, description FROM journal_entries WHERE id = ' . $reversal)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('STORNO PF9908002', $row['document_no']);
        self::assertSame('Storno PF9908002', $row['description']);
    }

    public function testRenumberRefusesMonthAfterFinalTaxEvidenceClosing(): void
    {
        $late = $this->draft('2099-08-28');
        $early = $this->draft('2099-08-02');
        $this->repo->ensureVarsymbol($late, $this->supplierId);
        $this->repo->ensureVarsymbol($early, $this->supplierId);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, 2099, 'final')")
            ->execute([$this->supplierId]);
        $closingId = (int) $pdo->lastInsertId();

        try {
            $plan = $this->renumber->preview($this->supplierId, '2099-08');
            self::assertSame(['year_closed', 'year_closed'], array_column($plan['blocked'], 'reason'));
            try {
                $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);
                self::fail('Měsíc po roční uzávěrce se nesmí přečíslovat.');
            } catch (\DomainException) {
            }
            self::assertSame('PF9908001', $this->numberOf($late));
        } finally {
            $pdo->prepare('DELETE FROM tax_evidence_closings WHERE id = ?')->execute([$closingId]);
        }
    }

    public function testRenumberRefusesLockedMonth(): void
    {
        $late = $this->draft('2099-08-28');
        $early = $this->draft('2099-08-02');
        $this->repo->ensureVarsymbol($late, $this->supplierId);
        $this->repo->ensureVarsymbol($early, $this->supplierId);
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, '2099-12-31']);

        try {
            $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);
            self::fail('Zamčený měsíc se nesmí přečíslovat.');
        } catch (\DomainException) {
        }
        self::assertSame('PF9908001', $this->numberOf($late));
    }
}
