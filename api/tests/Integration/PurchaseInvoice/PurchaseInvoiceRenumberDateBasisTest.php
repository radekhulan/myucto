<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceRenumberService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přečíslování přijatých dokladů podle zvoleného data (vystavení, přijetí, DUZP).
 *
 * - Podle data přijetí se doklady měsíce vyberou i seřadí jinak než podle data vystavení.
 * - Bez volby platí uložená volba firmy, výchozí je datum vystavení (dosavadní chování).
 * - Doklad bez data přijetí se řadí podle data vystavení a náhled to označí.
 * - Doklad s automatickým číslem jiného měsíce dostane číslo měsíce zvoleného data, pokud
 *   tím přečíslování nesáhne na zamčený nebo uzavřený měsíc ani doklad.
 *
 * Izolace: období 2099-07 až 2099-09, doklady se zakládají a mažou v testu.
 */
#[Group('integration')]
final class PurchaseInvoiceRenumberDateBasisTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private PurchaseInvoiceRenumberService $renumber;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];
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
            "SELECT purchase_invoice_number_format, purchase_invoice_numbering, purchase_numbering_date_basis
               FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        $locked = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $locked->execute([$this->supplierId]);
        $row = $locked->fetch(PDO::FETCH_ASSOC);
        $this->origLockedUntil = $row === false ? false : ($row['locked_until'] ?? null);

        $this->setSupplier(['purchase_invoice_number_format' => null, 'purchase_invoice_numbering' => 'shared', 'purchase_numbering_date_basis' => 'issue']);
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
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        $pdo->prepare("DELETE FROM purchase_invoice_counters WHERE supplier_id = ? AND period LIKE '%2099%'")
            ->execute([$this->supplierId]);
    }

    /** Přijatý doklad s daty vystavení a přijetí, hned očíslovaný (pořadí volání = pořadí přijetí). */
    private function received(string $issueDate, string $receivedAt, ?string $taxDate = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, vendor_snapshot, status, created_by)
             VALUES (?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', 'draft', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, 'RNDATE-' . (++$this->seq), $issueDate, $taxDate,
            $issueDate, $receivedAt, $this->currencyId, $this->userId,
        ]);
        $id = $this->created[] = (int) $pdo->lastInsertId();
        $this->repo->ensureVarsymbol($id, $this->supplierId);
        return $id;
    }

    private function numberOf(int $id): string
    {
        return (string) $this->db->pdo()->query("SELECT varsymbol FROM purchase_invoices WHERE id = {$id}")->fetchColumn();
    }

    private function storedBasis(): string
    {
        return (string) $this->db->pdo()->query("SELECT purchase_numbering_date_basis FROM supplier WHERE id = {$this->supplierId}")->fetchColumn();
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<int,string> id dokladu → nové číslo
     */
    private static function newNumbers(array $plan): array
    {
        return array_column($plan['changes'], 'new_varsymbol', 'id');
    }

    public function testRenumberByReceivedDateOrdersDifferentlyThanIssueDate(): void
    {
        $a = $this->received('2099-08-02', '2099-08-20');
        $b = $this->received('2099-08-10', '2099-08-05');
        $c = $this->received('2099-08-15', '2099-08-12');
        self::assertSame(['PF9908001', 'PF9908002', 'PF9908003'], [$this->numberOf($a), $this->numberOf($b), $this->numberOf($c)]);

        $byIssue = $this->renumber->preview($this->supplierId, '2099-08', 'issue');
        self::assertSame('issue', $byIssue['date_basis']);
        self::assertSame([], array_filter(array_column($byIssue['changes'], 'changed')),
            'Podle data vystavení čísla pořadí odpovídají.');

        $byReceived = $this->renumber->preview($this->supplierId, '2099-08', 'received');
        self::assertSame('received', $byReceived['date_basis']);
        self::assertSame([$b, $c, $a], array_column($byReceived['changes'], 'id'));
        self::assertSame(['2099-08-05', '2099-08-12', '2099-08-20'], array_column($byReceived['changes'], 'date'));
        self::assertSame([$b => 'PF9908001', $c => 'PF9908002', $a => 'PF9908003'], self::newNumbers($byReceived));

        $result = $this->renumber->renumber($this->supplierId, '2099-08', $this->userId, 'received');

        self::assertSame(3, $result['renumbered']);
        self::assertSame('PF9908001', $this->numberOf($b));
        self::assertSame('PF9908002', $this->numberOf($c));
        self::assertSame('PF9908003', $this->numberOf($a));
        self::assertSame('received', $this->storedBasis(), 'Volba se uloží do nastavení firmy.');
        self::assertSame('received', $this->renumber->preview($this->supplierId, '2099-08')['date_basis'],
            'Náhled bez volby převezme uloženou volbu.');
    }

    public function testDefaultBasisKeepsIssueDateOrder(): void
    {
        $late = $this->received('2099-08-28', '2099-08-01');
        $early = $this->received('2099-08-02', '2099-08-30');

        $plan = $this->renumber->preview($this->supplierId, '2099-08');
        self::assertSame('issue', $plan['date_basis']);
        self::assertSame([$early, $late], array_column($plan['changes'], 'id'));
        self::assertSame(['2099-08-02', '2099-08-28'], array_column($plan['changes'], 'date'));
        self::assertSame([], $plan['kept']);

        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId);

        self::assertSame('PF9908001', $this->numberOf($early));
        self::assertSame('PF9908002', $this->numberOf($late));
        self::assertSame('issue', $this->storedBasis());
    }

    public function testDocumentWithoutReceivedDateIsOrderedByIssueDate(): void
    {
        $z = $this->received('2099-08-01', '2099-08-25');
        $y = $this->received('2099-08-10', '2099-08-01');
        $x = $this->received('2099-08-03', '2099-08-03');
        // Starší data z nestriktního režimu: nulové datum přijetí.
        $pdo = $this->db->pdo();
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = ''");
        try {
            $pdo->prepare("UPDATE purchase_invoices SET received_at = '0000-00-00' WHERE id = ?")->execute([$x]);
        } finally {
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
        }

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'received');

        self::assertSame([$y, $x, $z], array_column($plan['changes'], 'id'));
        $rows = array_column($plan['changes'], null, 'id');
        self::assertTrue($rows[$x]['date_fallback']);
        self::assertSame('2099-08-03', $rows[$x]['date'], 'Bez data přijetí platí datum vystavení.');
        self::assertFalse($rows[$y]['date_fallback']);
        self::assertSame([$y => 'PF9908001', $x => 'PF9908002', $z => 'PF9908003'], self::newNumbers($plan));
    }

    public function testDocumentWithoutTaxDateIsOrderedByIssueDate(): void
    {
        $a = $this->received('2099-08-20', '2099-08-20', '2099-08-02');
        $b = $this->received('2099-08-05', '2099-08-05');

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'tax');

        self::assertSame([$a, $b], array_column($plan['changes'], 'id'));
        self::assertSame([false, true], array_column($plan['changes'], 'date_fallback'));
    }

    public function testDocumentNumberedInOtherMonthMovesToMonthOfChosenDate(): void
    {
        $july = $this->received('2099-07-30', '2099-08-02');
        $septReceived = $this->received('2099-08-03', '2099-09-01');
        $august = $this->received('2099-08-05', '2099-08-06');
        self::assertSame('PF9907001', $this->numberOf($july));
        self::assertSame('PF9908001', $this->numberOf($septReceived));
        self::assertSame('PF9908002', $this->numberOf($august));

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'received');

        self::assertSame([$july, $august], array_column($plan['changes'], 'id'));
        $rows = array_column($plan['changes'], null, 'id');
        self::assertSame('209907', $rows[$july]['from_period'], 'Náhled uvede měsíc, ze kterého číslo odchází.');
        self::assertNull($rows[$august]['from_period']);
        self::assertSame([$septReceived => 'date_other_month'], array_column($plan['kept'], 'reason', 'id'),
            'Srpnové číslo dokladu přijatého v září zůstane obsazené.');
        self::assertSame([$july => 'PF9908002', $august => 'PF9908003'], self::newNumbers($plan));

        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId, 'received');

        self::assertSame('PF9908002', $this->numberOf($july), 'Doklad přijatý v srpnu dostane srpnové číslo.');
        self::assertSame('PF9908001', $this->numberOf($septReceived));
        self::assertSame('PF9908003', $this->numberOf($august));
        self::assertSame(3, $this->counter('209908'));
        self::assertSame(1, $this->counter('209907'), 'Řada původního měsíce se nesnižuje, uvolněné číslo se znovu nevydá.');

        $september = $this->renumber->preview($this->supplierId, '2099-09', 'received');
        self::assertSame([$septReceived => 'PF9909001'], self::newNumbers($september));
        self::assertSame([], $september['kept']);
        $this->renumber->renumber($this->supplierId, '2099-09', $this->userId, 'received');
        self::assertSame('PF9909001', $this->numberOf($septReceived));
    }

    public function testDocumentIsNotMovedOutOfLockedMonth(): void
    {
        // Číslo podle data přijetí (červenec), datum vystavení i doklad sám v srpnu.
        $this->setSupplier(['purchase_numbering_date_basis' => 'received']);
        $moved = $this->received('2099-08-10', '2099-07-28');
        $august = $this->received('2099-08-12', '2099-08-12');
        self::assertSame('PF9907001', $this->numberOf($moved));
        self::assertSame('PF9908001', $this->numberOf($august));
        $this->lockUntil('2099-07-31');

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'issue');

        self::assertSame([], $plan['blocked']);
        self::assertSame([$moved => 'number_month_locked'], array_column($plan['kept'], 'reason', 'id'),
            'Číslo zamčeného měsíce se nepřesune, náhled uvede důvod.');
        self::assertSame([$august], array_column($plan['changes'], 'id'));

        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId, 'issue');

        self::assertSame('PF9907001', $this->numberOf($moved));
        self::assertSame('PF9908001', $this->numberOf($august));
    }

    public function testDocumentIsNotMovedIntoLockedMonth(): void
    {
        // Číslo podle vystavení (září), přijatý v zamčeném srpnu.
        $moved = $this->received('2099-09-05', '2099-08-03');
        self::assertSame('PF9909001', $this->numberOf($moved));
        $this->lockUntil('2099-08-31');

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'received');

        self::assertSame([], $plan['changes']);
        self::assertSame([$moved => 'month_locked'], array_column($plan['kept'], 'reason', 'id'));
    }

    public function testLockedDocumentIsNotMoved(): void
    {
        $moved = $this->received('2099-07-30', '2099-08-02');
        $this->lockUntil('2099-07-30');

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'received');

        self::assertSame([$moved => 'document_locked'], array_column($plan['kept'], 'reason', 'id'));
        self::assertSame([], $plan['changes']);
    }

    public function testManualNumberIsNotMoved(): void
    {
        $manual = $this->received('2099-07-30', '2099-08-02');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ?')->execute(['RUCNI-7', $manual]);
        $august = $this->received('2099-08-05', '2099-08-06');

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'received');

        self::assertSame([$manual => 'manual_number'], array_column($plan['kept'], 'reason', 'id'));
        self::assertSame([$august => 'PF9908001'], self::newNumbers($plan));
        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId, 'received');
        self::assertSame('RUCNI-7', $this->numberOf($manual));
    }

    public function testChangedDocumentKindMovesToOwnSeriesOfChosenMonth(): void
    {
        $this->setSupplier(['purchase_invoice_numbering' => 'by_kind']);
        $receipt = $this->received('2099-07-30', '2099-08-02');
        self::assertSame('PF9907001', $this->numberOf($receipt));
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'receipt' WHERE id = ?")->execute([$receipt]);

        $plan = $this->renumber->preview($this->supplierId, '2099-08', 'received');

        self::assertSame([$receipt => 'UC9908001'], self::newNumbers($plan),
            'Doklad se změněným typem dostane číslo své řady v měsíci data přijetí.');
        $this->renumber->renumber($this->supplierId, '2099-08', $this->userId, 'received');
        self::assertSame('UC9908001', $this->numberOf($receipt));
        self::assertSame(1, $this->counter('receipt:209908'));
    }

    private function lockUntil(string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, $date]);
    }

    private function counter(string $key): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = ? AND period = ?');
        $stmt->execute([$this->supplierId, $key]);
        return (int) $stmt->fetchColumn();
    }

    public function testReceivedBasisStillRefusesLockedMonth(): void
    {
        $a = $this->received('2099-08-02', '2099-08-20');
        $b = $this->received('2099-08-10', '2099-08-05');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, '2099-12-31']);

        try {
            $this->renumber->renumber($this->supplierId, '2099-08', $this->userId, 'received');
            self::fail('Zamčený měsíc se nesmí přečíslovat ani podle data přijetí.');
        } catch (\DomainException) {
        }
        self::assertSame('PF9908001', $this->numberOf($a));
        self::assertSame('PF9908002', $this->numberOf($b));
        self::assertSame('issue', $this->storedBasis(), 'Odmítnuté přečíslování volbu neuloží.');
    }

    public function testInvalidBasisIsValidationError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renumber->preview($this->supplierId, '2099-08', 'due');
    }
}
