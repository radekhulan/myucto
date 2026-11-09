<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\PostingService;
use Psr\Container\ContainerInterface;

/**
 * Společná syntetická sada zápisů pro sestavy jednoduchého zápisu (opis, hlavní kniha,
 * cash flow, export deníku). Izolovaná firma v transakci, kterou tearDown odvolá.
 *
 * Sada pokrývá tvary, na kterých se protiúčet počítá různě: 1 : N (faktura, hromadná
 * platba), dvojice se shodnou částkou (pokladní doklad), M : N bez pravidla, červené
 * storno, převod mezi peněžními účty, investiční a finanční tok.
 */
trait PairReportFixture
{
    private const YEAR = 2099;

    private ContainerInterface $container;
    private Connection $db;
    private PostingService $posting;
    private EntryFormService $entryForms;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    /** @var array<string,int> kód účtu => id */
    private array $accountIds = [];

    private function setUpFixture(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->posting = $this->container->get(PostingService::class);
            $this->entryForms = $this->container->get(EntryFormService::class);
            $seeder = $this->container->get(ChartOfAccountsSeeder::class);
            $periods = $this->container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $template = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($template === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Kopie první firmy se všemi sloupci (plátcovství, období DPH, adresa), aby šly
        // sestavit i DPH výkazy; kumulativní PS jde přes celou historii deníku firmy.
        $columns = $pdo->query(
            "SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION)
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%GENERATED%'"
        )->fetchColumn();
        $pdo->exec("INSERT INTO supplier ({$columns}) SELECT {$columns} FROM supplier WHERE id = {$template}");
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')->execute(['Dvojice test s.r.o.', $this->supplierId]);

        $seeder->seedForSupplier($this->supplierId, self::YEAR . '-01-01');
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        $stmt = $pdo->prepare('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$code, $id]) {
            $this->accountIds[(string) $code] = (int) $id;
        }
    }

    private function tearDownFixture(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    private function accountId(string $code): int
    {
        if (!isset($this->accountIds[$code])) {
            throw new \RuntimeException('Účet ' . $code . ' v osnově chybí.');
        }
        return $this->accountIds[$code];
    }

    /**
     * Syntetická sada zápisů (všechny ručně, aby sestavy nezávisely na builderech dokladů).
     *
     * @return array<string,int> klíč scénáře => entry_id
     */
    private function postScenario(): array
    {
        $y = self::YEAR;
        $ids = [];
        $ids['invoice'] = $this->manual("{$y}-01-10", 'FV-1', 'Vydaná faktura', [
            ['311', 'debit', '1210.00'], ['602', 'credit', '1000.00'], ['343.200', 'credit', '210.00'],
        ]);
        $ids['purchase'] = $this->manual("{$y}-01-12", 'FP-1', 'Přijatá faktura', [
            ['513', 'debit', '1000.00'], ['343.100', 'debit', '210.00'], ['321', 'credit', '1210.00'],
        ]);
        $ids['receipt'] = $this->manual("{$y}-01-20", 'BV-1', 'Úhrada FV-1', [
            ['221', 'debit', '1210.00'], ['311', 'credit', '1210.00'],
        ]);
        $ids['bulk'] = $this->manual("{$y}-01-25", 'BV-2', 'Hromadná platba', [
            ['321', 'debit', '1210.00'], ['321', 'debit', '605.00'], ['568', 'debit', '15.00'], ['221', 'credit', '1830.00'],
        ]);
        $ids['cash'] = $this->manual("{$y}-02-03", 'PD-1', 'Pokladní doklad', [
            ['512', 'debit', '100.00'], ['513', 'debit', '100.00'], ['211', 'credit', '100.00'], ['211', 'credit', '100.00'],
        ]);
        $ids['mn'] = $this->manual("{$y}-02-10", 'ID-1', 'Složený zápis M : N', [
            ['513', 'debit', '300.00'], ['512', 'debit', '200.00'], ['321', 'credit', '250.00'], ['211', 'credit', '250.00'],
        ]);
        $ids['storno'] = $this->manual("{$y}-02-15", 'FV-2', 'Opravný doklad', [
            ['311', 'debit', '121.00', true], ['602', 'credit', '100.00', true], ['343.200', 'credit', '21.00', true],
        ]);
        $ids['transfer'] = $this->manual("{$y}-03-01", 'BV-3', 'Výběr do pokladny', [
            ['211', 'debit', '5000.00'], ['221', 'credit', '5000.00'],
        ]);
        $ids['asset'] = $this->manual("{$y}-03-05", 'FP-2', 'Nákup stroje', [
            ['022', 'debit', '20000.00'], ['321', 'credit', '20000.00'],
        ]);
        $ids['asset_pay'] = $this->manual("{$y}-03-06", 'BV-4', 'Úhrada stroje', [
            ['321', 'debit', '20000.00'], ['221', 'credit', '20000.00'],
        ]);
        $ids['loan'] = $this->manual("{$y}-03-10", 'BV-5', 'Úvěr', [
            ['221', 'debit', '50000.00'], ['461', 'credit', '50000.00'],
        ]);
        $ids['sale2'] = $this->manual("{$y}-03-20", 'FV-3', 'Vydaná faktura dvě sazby', [
            ['311', 'debit', '13220.00'], ['602', 'credit', '10000.00'], ['343.200', 'credit', '2100.00'],
            ['604', 'credit', '1000.00'], ['343.200', 'credit', '120.00'],
        ]);
        return $ids;
    }

    /**
     * @param list<array{0:string,1:string,2:string,3?:bool}> $lines
     */
    private function manual(string $date, string $documentNo, string $description, array $lines): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            array_map(static fn (array $l): array => [
                'account_code'  => $l[0],
                'side'          => $l[1],
                'amount'        => $l[2],
                'is_red_storno' => $l[3] ?? false,
            ], $lines),
            [
                'entry_date'  => $date,
                'document_no' => $documentNo,
                'description' => $description,
                'posted_by'   => $this->userId,
                'user_id'     => $this->userId,
            ],
        );
    }

    /** Firma přejde na jednoduchý zápis od data (stejná cesta jako nastavení v aplikaci). */
    private function switchToSingle(string $validFrom): void
    {
        $this->entryForms->schedule($this->supplierId, $validFrom, EntryForm::Single, $this->userId);
        $this->entryForms->forget();
    }

    /**
     * Rozloží řádky zápisu na dvojice přímo v DB (náhrada převodu z jiného proudu):
     * řádky zápisu se smažou a vloží znovu z výstupu {@see JournalPairer}. Součty po
     * účtech a stranách se nemění. M : N zápis zůstane beze změny.
     */
    private function pairEntryInPlace(int $entryId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, account_id, side, amount, is_red_storno, currency_code, fx_rate, amount_foreign,
                    cost_center, project_id, line_no
               FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = JournalPairer::pair($lines);
        if ($result['status'] !== JournalPairer::STATUS_PAIRED) {
            return false;
        }
        $pdo->prepare('DELETE FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ?')->execute([$this->supplierId, $entryId]);
        $insert = $pdo->prepare(
            'INSERT INTO journal_entry_lines
                (entry_id, supplier_id, account_id, side, amount, is_red_storno, currency_code, fx_rate,
                 amount_foreign, cost_center, project_id, line_no, pair_no, line_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($result['lines'] as $i => $l) {
            $insert->execute([
                $entryId, $this->supplierId, (int) $l['account_id'], $l['side'], $l['amount'], (int) $l['is_red_storno'],
                $l['currency_code'], $l['fx_rate'], $l['amount_foreign'], $l['cost_center'], $l['project_id'],
                $i + 1, (int) $l['pair_no'], $l['line_text'] ?? null,
            ]);
        }
        return true;
    }

    /** @param array<string,int> $entries */
    private function pairAllInPlace(array $entries): void
    {
        foreach ($entries as $entryId) {
            $this->pairEntryInPlace($entryId);
        }
    }

    private function questions(): int
    {
        return (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
