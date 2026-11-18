<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Nonprofit\Membership\MembershipContactErasure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výmaz kontaktu člena (#321, krok 10) proti souběžně potvrzené vazbě na kontakt.
 *
 * Transakce výmazu má snímek (REPEATABLE READ) z dřívějška. Vazbu na kontakt potvrzenou jiným
 * spojením po vzniku snímku musí zjištění jiného použití kontaktu vidět, jinak by výmaz
 * anonymizoval kontakt, na kterém už stojí jiný doklad. Jen syntetická data.
 */
#[Group('integration')]
final class MembershipContactErasureConcurrencyTest extends TestCase
{
    private Connection $db;
    private ?Connection $other = null;
    private ?int $supplier = null;
    private int $client;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        // Kontakt nemusí existovat: invoice_counters nemá na kontakt cizí klíč a zjištění použití čte jen vazby.
        $this->client = 9_000_000_000 + random_int(1, 999_999_999);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->other !== null) {
            if ($this->supplier !== null) {
                $this->other->pdo()->prepare('DELETE FROM invoice_counters WHERE supplier_id=? AND client_id=?')->execute([$this->supplier, $this->client]);
            }
            $this->other->close();
        }
    }

    /**
     * Bez snímkové izolace InnoDB zamykající čtení vazbu vidí. Se snímkovou izolací (výchozí od MariaDB 11.8)
     * MariaDB transakci výmazu odvolá chybou 1020 a vlastník ji opakuje s novým snímkem. V žádném případě
     * se zjištění nesmí vrátit bez vazby, to by kontakt anonymizovalo.
     */
    public function testUseCommittedAfterSnapshotIsSeen(): void
    {
        $pdo = $this->db->pdo();
        $supplier = $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertNotNull($supplier, 'Test DB nemá žádnou firmu.');
        $this->supplier = (int) $supplier;
        $this->other = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $original = $pdo->query('SELECT @@SESSION.innodb_snapshot_isolation')->fetchColumn();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=OFF');
            self::assertContains('invoice_counters', $this->usesAfterConcurrentCommit('SYN-LOCK-1'), 'Vazba potvrzená po vzniku snímku kontakt drží.');
            $pdo->exec('SET SESSION innodb_snapshot_isolation=ON');
            try {
                $uses = $this->usesAfterConcurrentCommit('SYN-LOCK-2');
                self::assertContains('invoice_counters', $uses, 'Vazba potvrzená po vzniku snímku kontakt drží.');
            } catch (\PDOException $e) {
                self::assertTrue(TransactionFailure::aborted($e), $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ((int) $original === 1 ? 'ON' : 'OFF'));
        }
    }

    /** @return list<string> */
    private function usesAfterConcurrentCommit(string $period): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            // Snímek transakce vznikne prvním čtením, ještě před vazbou na kontakt.
            $before = $pdo->prepare('SELECT COUNT(*) FROM invoice_counters WHERE supplier_id=? AND client_id=? AND period=?');
            $before->execute([$this->supplier, $this->client, $period]);
            self::assertSame(0, (int) $before->fetchColumn());

            $this->other->pdo()->prepare("INSERT INTO invoice_counters(supplier_id,client_id,revenue_category_id,invoice_type,period,last_number) VALUES(?,?,0,'invoice',?,1)")
                ->execute([$this->supplier, $this->client, $period]);

            $before->execute([$this->supplier, $this->client, $period]);
            self::assertSame(0, (int) $before->fetchColumn(), 'Nezamykající čtení vidí snímek, vazbu ne (předpoklad testu).');
            return (new \ReflectionMethod(MembershipContactErasure::class, 'otherUses'))
                ->invoke(new MembershipContactErasure($this->db), $this->supplier, $this->client, 0);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
