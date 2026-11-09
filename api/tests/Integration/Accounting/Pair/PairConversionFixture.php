<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Sdílený podklad testů převodu a noční kontroly dvojic: izolovaná podvojná firma
 * v jedné transakci (tearDown ji vrátí), vlastní malá osnova a zápisy vložené přímo
 * (mimo PostingService), aby šly založit i stavy, které by zaúčtování nepustilo.
 * Jen syntetická data.
 */
trait PairConversionFixture
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierId = 0;
    private int $actorId = 0;
    private int $openPeriodId = 0;
    private int $closedPeriodId = 0;
    /** @var array<string,int> */
    private array $accounts = [];

    private function setUpFixture(): void
    {
        if (!is_file(dirname(__DIR__, 5) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí výchozí firma pro izolovaný test.');
        }
        $this->actorId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', legal_form = '112' WHERE id = ?")
            ->execute([$this->supplierId]);
        $period = $pdo->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status) VALUES (?, ?, ?, ?, ?)'
        );
        $period->execute([$this->supplierId, 2097, '2097-01-01', '2097-12-31', 'closed']);
        $this->closedPeriodId = (int) $pdo->lastInsertId();
        $period->execute([$this->supplierId, 2098, '2098-01-01', '2098-12-31', 'open']);
        $this->openPeriodId = (int) $pdo->lastInsertId();

        $account = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, ?, ?, ?)'
        );
        foreach ([
            '211' => 'asset', '221' => 'asset', '311' => 'asset', '321' => 'liability', '331' => 'liability',
            '343' => 'liability', '518' => 'expense', '521' => 'expense', '568' => 'expense',
            '602' => 'revenue', '604' => 'revenue',
        ] as $code => $type) {
            $account->execute([$this->supplierId, $code, 'Účet ' . $code, $type]);
            $this->accounts[$code] = (int) $pdo->lastInsertId();
        }
    }

    private function tearDownFixture(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * @param list<array{0:string, 1:'debit'|'credit', 2:string, 3?:int}> $lines [účet, strana, částka, pair_no]
     */
    private function entry(string $date, array $lines, string $sourceType = 'manual', ?int $periodId = null, bool $posted = true): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, source_type, posted_at, posted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $periodId ?? ($date < '2098-01-01' ? $this->closedPeriodId : $this->openPeriodId),
            $date,
            'T-' . $date,
            $sourceType,
            $posted ? $date . ' 10:00:00' : null,
            $posted && $this->actorId > 0 ? $this->actorId : null,
        ]);
        $entryId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no, pair_no)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($lines) as $i => $line) {
            $insert->execute([$entryId, $this->supplierId, $this->accounts[$line[0]], $line[1], $line[2], $i + 1, $line[3] ?? null]);
        }
        return $entryId;
    }

    /** Vydaná faktura 1 : N (311 MD proti 602 a 343). */
    private function invoiceOneToMany(string $date, ?int $periodId = null): int
    {
        return $this->entry($date, [
            ['311', 'debit', '1210.00'],
            ['602', 'credit', '1000.00'],
            ['343', 'credit', '210.00'],
        ], 'invoice', $periodId);
    }

    /** M : N bez pravidla (dva řádky na každé straně s různými částkami). */
    private function manyToMany(string $date): int
    {
        return $this->entry($date, [
            ['518', 'debit', '700.00'],
            ['568', 'debit', '300.00'],
            ['321', 'credit', '600.00'],
            ['221', 'credit', '400.00'],
        ]);
    }

    private function setSingleFrom(string $date): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')"
        )->execute([$this->supplierId, $date]);
        \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared($this->supplierId);
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_id, side, amount, line_no, pair_no FROM journal_entry_lines
              WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
