<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class PaymentMutationParityTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testFiveHundredCustomAssignmentsRemainValidAndGuardQueriesAreBatched(): void
    {
        $container = Bootstrap::buildContainer(); $db = $container->get(Connection::class); $pdo = $db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        try {
            $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
            $period = (new AccountingPeriodRepository($db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
            (new ChartOfAccountsSeeder($db))->seedForSupplier($supplier, '2026-01-01');
            $pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name,kind) VALUES (?,'SYNTHETIC','Synthetic custom','custom')")->execute([$supplier]);
            $type = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO dimension_values (supplier_id,type_id,code,name) VALUES (?,?,'VALUE','Synthetic value')")->execute([$supplier, $type]);
            $value = (int) $pdo->lastInsertId();
            $q = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='211'"); $q->execute([$supplier]); $account = (int) $q->fetchColumn();
            $journal = $container->get(JournalEntryRepository::class);
            $count = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
            $queries = [];
            foreach ([2, 1000] as $size) {
                $lines = [];
                for ($i = 0; $i < $size; ++$i) $lines[] = ['account_id' => $account, 'side' => $i % 2 === 0 ? 'debit' : 'credit', 'amount' => '0.01', 'dimensions' => [$type => $value]];
                $before = $count();
                $id = $journal->insert(['supplier_id' => $supplier, 'period_id' => $period, 'entry_date' => '2026-02-01', 'source_type' => 'manual', 'source_id' => null, 'posted_at' => '2026-02-01 12:00:00'], $lines);
                $queries[$size] = $count() - $before;
                $q = $pdo->prepare('SELECT COUNT(*),SUM(l.amount) FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id=d.line_id AND l.supplier_id=d.supplier_id WHERE l.supplier_id=? AND l.entry_id=?');
                $q->execute([$supplier, $id]); $actual = $q->fetch(PDO::FETCH_NUM);
                self::assertSame($size, (int) $actual[0]);
                self::assertSame(bcdiv((string) $size, '100', 2), $actual[1]);
            }
            self::assertLessThanOrEqual($queries[2] + 2, $queries[1000], json_encode($queries));
            // Strop 18: ochrana řádků členských příspěvků (MembershipUseGuard::assertLinesMutable) přidala
            // jeden dotaz na každých 500 vkládaných řádků; 1000 řádků = dva bloky. Růst s velikostí
            // zápisu hlídá podmínka výše, tento strop jen zachytí další přidaný guard. Strop 20: strážci
            // alokací a vyúčtování čtou odkazy zvlášť jednou za zápis místo výrazu nad každým řádkem.
            self::assertLessThanOrEqual(20, $queries[1000], json_encode($queries));
        } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
}
