<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Pair\JournalPairConversionJob;
use MyInvoice\Service\Accounting\Pair\JournalPairConversionService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Provedení převodu: 1 : N zápisy v otevřeném nezamčeném období s jednoduchou formou
 * se fyzicky rozdělí na dvojice (bez mazání řádků, původní id zůstávají), součty po
 * účtech (předvaha) se nezmění, uzavřené období zůstane beze změny včetně systémové
 * historie, ostatní zápisy dostanou stav v journal_entry_pair_status a opakovaný běh
 * nic nového nerozdělí.
 *
 * Skupina `pairs-e2e`: rozdělení dělá JournalEntryRepository::splitIntoPairs(), které
 * dodává jádro jednoduchého zápisu; do té doby testy padají na LogicException.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class ConversionApplyTest extends TestCase
{
    use PairConversionFixture;

    private JournalPairConversionService $service;
    private JournalPairConversionJob $job;

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->service = new JournalPairConversionService(
            $this->db,
            $this->container->get(ActivityLogger::class),
            static fn (int $runId): ?string => null,
        );
        $this->job = $this->container->get(JournalPairConversionJob::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testApplySplitsOneToManyAndKeepsTrialBalance(): void
    {
        $this->setSingleFrom('2098-01-01');
        $auto = $this->invoiceOneToMany('2098-02-01');
        $originalIds = array_map('intval', array_column($this->lines($auto), 'id'));
        $manyToMany = $this->manyToMany('2098-02-02');
        $closed = $this->invoiceOneToMany('2097-06-01');
        $trialBefore = $this->trialBalance();
        $closedHistory = $this->historyCount($closed);
        $closedLines = $this->lines($closed);

        $runId = $this->service->apply($this->supplierId, null, null, $this->actorId);
        $this->job->run($runId);

        $run = $this->service->run($this->supplierId, $runId);
        self::assertSame('done', $run['status']);
        self::assertSame(1, $run['counts']['converted']);
        self::assertSame(0, $run['counts']['failed']);

        $lines = $this->lines($auto);
        self::assertCount(4, $lines, '311 MD 1 210 → dvě dvojice 311/602 a 311/343');
        foreach ($lines as $line) {
            self::assertNotNull($line['pair_no']);
        }
        $ids = array_map('intval', array_column($lines, 'id'));
        self::assertSame([], array_diff($originalIds, $ids), 'Původní řádky se nemažou, první díl zůstává na původním id.');
        $pairs = [];
        foreach ($lines as $line) {
            $pairs[(int) $line['pair_no']][] = $line;
        }
        self::assertSame([1, 2], array_keys($pairs));
        foreach ($pairs as $pair) {
            self::assertNull(JournalPairInvariant::pairViolation($pair[0], $pair[1]));
        }

        self::assertSame($trialBefore, $this->trialBalance(), 'Předvaha po převodu musí být shodná.');
        self::assertSame($closedLines, $this->lines($closed));
        self::assertSame($closedHistory, $this->historyCount($closed), 'Uzavřené období bez nové verze řádků.');

        $status = $this->pairStatus();
        self::assertSame('composite_mn', $status[$manyToMany]['status'] ?? null);
        self::assertSame('ineligible', $status[$closed]['status'] ?? null);
        self::assertSame(JournalPairConversionJob::REASON_CLOSED_PERIOD, $status[$closed]['reason'] ?? null);
        self::assertArrayNotHasKey($auto, $status);

        $audit = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ?"
        );
        $audit->execute([$this->supplierId, JournalPairConversionJob::AUDIT_CONVERTED, $auto]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testRepeatedApplyIsIdempotent(): void
    {
        $this->setSingleFrom('2098-01-01');
        $auto = $this->invoiceOneToMany('2098-02-01');

        $first = $this->service->apply($this->supplierId, null, null, $this->actorId);
        $this->job->run($first);
        $after = $this->lines($auto);

        $second = $this->service->apply($this->supplierId, null, null, $this->actorId);
        $this->job->run($second);
        $counts = $this->service->run($this->supplierId, $second)['counts'];

        self::assertSame(0, $counts['converted']);
        self::assertSame(1, $counts['paired']);
        self::assertSame($after, $this->lines($auto));
    }

    public function testEntriesBeforeSingleFormStayComposite(): void
    {
        $this->setSingleFrom('2098-06-01');
        $before = $this->invoiceOneToMany('2098-02-01');
        $inside = $this->invoiceOneToMany('2098-07-01');
        $beforeLines = $this->lines($before);

        $runId = $this->service->apply($this->supplierId, null, null, $this->actorId);
        $this->job->run($runId);
        $counts = $this->service->run($this->supplierId, $runId)['counts'];

        self::assertSame(1, $counts['skipped_composite_form']);
        self::assertSame(1, $counts['converted']);
        self::assertSame($beforeLines, $this->lines($before), 'Složená forma k datu nesmí dostat pair_no (I6).');
        self::assertCount(4, $this->lines($inside));
        self::assertSame(EntryForm::Composite, $this->container->get(\MyInvoice\Service\Accounting\EntryForm\EntryFormService::class)
            ->formAt($this->supplierId, '2098-02-01', currentRead: true));
    }

    /** @return array<int,string> account_id => podepsaný zůstatek */
    private function trialBalance(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT account_id, SUM(CASE WHEN side = 'debit' THEN signed_amount ELSE -signed_amount END) AS balance
               FROM journal_entry_lines WHERE supplier_id = ? GROUP BY account_id ORDER BY account_id"
        );
        $stmt->execute([$this->supplierId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function historyCount(int $entryId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entry_lines FOR SYSTEM_TIME ALL WHERE supplier_id = ? AND entry_id = ?');
        $stmt->execute([$this->supplierId, $entryId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array{status:string, reason:?string}> */
    private function pairStatus(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT entry_id, status, reason FROM journal_entry_pair_status WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['entry_id']] = ['status' => (string) $row['status'], 'reason' => $row['reason']];
        }
        return $out;
    }
}
