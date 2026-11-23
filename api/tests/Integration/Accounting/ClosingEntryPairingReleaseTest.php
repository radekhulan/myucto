<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Revert kroku uzávěrky, opakovaný běh a náhrada převzatého otevíracího zápisu mažou
 * zápisy natvrdo ({@see ClosingRepository::deleteClosingEntry()},
 * {@see ClosingRepository::deleteOpeningEntry()}). Otevírací zápis i kurzové rozdíly,
 * rezervy a časové rozlišení leží v okně otevřených položek, takže jejich řádky bývají
 * spárované. Systémový přepočet zápis jen nahrazuje, proto okruh neblokuje: uvolní ho
 * jako storno, zapíše do auditu a krok vrátí počet uvolněných párování. Dřív okruh
 * odešel kaskádou beze stopy. Rozpad saldokonta dál blokuje, ten systém neobnoví.
 *
 * Vše v transakci, kterou tearDown rollbackne.
 */
#[Group('integration')]
final class ClosingEntryPairingReleaseTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private ClosingRepository $closing;
    private ClosingService $service;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->closing = $container->get(ClosingRepository::class);
            $this->service = $container->get(ClosingService::class);
            $this->posting = $container->get(PostingService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
            $periods       = $container->get(AccountingPeriodRepository::class);
            $this->periods = $periods;
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        if ($periods->findForDate($this->supplierId, self::YEAR . '-06-30') === null) {
            $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testRevertedStepReleasesPairingAndReportsCount(): void
    {
        $period = $this->periods->findForDate($this->supplierId, self::YEAR . '-06-30');
        $periodId = (int) $period['id'];
        $entryId = $this->entry('income_tax', $periodId);
        $partnerId = $this->entry('manual', 991003);
        $pairing = $this->pair($entryId, $partnerId);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE supplier_id = ? AND id = ?")
            ->execute([$this->supplierId, $periodId]);
        $rowVersion = (int) $this->periods->findById($this->supplierId, $periodId)['row_version'];

        $result = $this->service->revertStep($this->supplierId, $periodId, 'income_tax', $rowVersion, ['user_id' => null]);

        self::assertSame(1, $result['released_pairings'], 'Krok hlásí počet uvolněných párování.');
        self::assertSame(0, $this->entryCount($entryId));
        self::assertSame(1, $this->entryCount($partnerId), 'Protějšek z okruhu zůstal.');
        self::assertSame(0, $this->pairingItems($pairing), 'Okruh se rozpustil, protějšek je zase otevřený.');
        $audit = $this->audit($entryId);
        self::assertSame('closing_replace', $audit['reason']);
        self::assertSame(1, $audit['pairing_count']);
        self::assertEqualsCanonicalizing([$entryId, $partnerId], $audit['entry_ids']);
        self::assertSame(['311'], $audit['accounts']);
    }

    public function testRevertedFxRevaluationReleasesPairing(): void
    {
        $fxSourceId = 9_990_000_001;
        $entryId = $this->entry('fx_revaluation', $fxSourceId);
        $pairing = $this->pair($entryId, $this->entry('manual', 991001));

        $dump = $this->closing->deleteClosingEntry($this->supplierId, 'fx_revaluation', $fxSourceId);

        self::assertNotNull($dump);
        self::assertCount(2, $dump['released_pairings'], 'Uvolněná položka zápisu i rozpuštěný zbytek okruhu.');
        self::assertSame(0, $this->entryCount($entryId));
        self::assertSame(0, $this->pairingItems($pairing));
        self::assertSame(1, count(array_unique(array_column($this->closing->takeReleasedPairings(), 'pairing_id'))));
        self::assertSame([], $this->closing->takeReleasedPairings(), 'Počítadlo se po přečtení vynuluje.');
    }

    public function testReplacedOpeningEntryReleasesPairingButKeepsSaldoAllocation(): void
    {
        $openingId = $this->entry('opening', null);
        $pairing = $this->pair($openingId, $this->entry('manual', 991002));
        $line = $this->firstLine($openingId);
        $this->db->pdo()->prepare(
            "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, doc_type, doc_id, amount, kind)
             VALUES (?, ?, ?, ?, 'debit', 'other_item', 1, 100, 'settlement')"
        )->execute([$this->supplierId, $openingId, $line['line_no'], $line['account_id']]);
        try {
            $this->closing->deleteOpeningEntry($this->supplierId, $openingId);
            self::fail('Otevírací zápis s rozpadem saldokonta se nesmí smazat.');
        } catch (ClosingException $e) {
            self::assertSame('allocation_saldo_in_use', $e->errorCode);
        }
        self::assertSame(1, $this->entryCount($openingId));
        self::assertSame(2, $this->pairingItems($pairing), 'Odmítnutí okruh nechá být.');

        $this->db->pdo()->prepare('DELETE FROM journal_settlement_allocations WHERE supplier_id = ? AND entry_id = ?')
            ->execute([$this->supplierId, $openingId]);
        $dump = $this->closing->deleteOpeningEntry($this->supplierId, $openingId);

        self::assertNotNull($dump);
        self::assertNotEmpty($dump['released_pairings']);
        self::assertSame(0, $this->entryCount($openingId));
        self::assertSame(0, $this->pairingItems($pairing));
    }

    /** @return array<string,mixed> */
    private function audit(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'accounting.pairing_released'
                AND entity_type = 'journal_entry' AND entity_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->supplierId, $entryId]);
        return (array) json_decode((string) $stmt->fetchColumn(), true);
    }

    /** Zaúčtuje zápis 311 / 602 a přepíše mu zdroj (uzávěrkové zápisy vznikají mimo PostingService). */
    private function entry(string $sourceType, ?int $sourceId): int
    {
        $id = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 500.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-06-30', 'description' => 'Syntetický zápis ' . $sourceType, 'posted' => true]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET source_type = ?, source_id = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$sourceType, $sourceId, $this->supplierId, $id]);
        return $id;
    }

    /** @return array{line_no:int, account_id:int} */
    private function firstLine(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT line_no, account_id FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no LIMIT 1');
        $stmt->execute([$this->supplierId, $entryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ['line_no' => (int) $row['line_no'], 'account_id' => (int) $row['account_id']];
    }

    private function pair(int $entryId, int $partnerId): int
    {
        $pdo = $this->db->pdo();
        $line = $this->firstLine($entryId);
        $pdo->prepare('INSERT INTO journal_line_pairings (supplier_id, account_id) VALUES (?, ?)')->execute([$this->supplierId, $line['account_id']]);
        $pairingId = (int) $pdo->lastInsertId();
        $item = $pdo->prepare('INSERT INTO journal_line_pairing_items (supplier_id, entry_id, line_no, pairing_id, account_id) VALUES (?, ?, ?, ?, ?)');
        $item->execute([$this->supplierId, $entryId, $line['line_no'], $pairingId, $line['account_id']]);
        $partner = $this->firstLine($partnerId);
        $item->execute([$this->supplierId, $partnerId, $partner['line_no'], $pairingId, $partner['account_id']]);
        return $pairingId;
    }

    private function pairingItems(int $pairingId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_line_pairing_items WHERE supplier_id = ? AND pairing_id = ?');
        $stmt->execute([$this->supplierId, $pairingId]);
        return (int) $stmt->fetchColumn();
    }

    private function entryCount(int $entryId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $entryId]);
        return (int) $stmt->fetchColumn();
    }
}
