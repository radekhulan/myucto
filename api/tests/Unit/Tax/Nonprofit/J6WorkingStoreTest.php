<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6WorkingStore;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Tests\Support\J6CalculatorFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6WorkingStoreTest extends TestCase
{
    public function testKeepsExactInt64RowsIndexedAndDropsEveryOwnedTemporaryTable(): void
    {
        $pdo = J6CalculatorFactory::connection()->pdo();
        $before = $this->temporaryTables($pdo);
        $store = new J6WorkingStore($pdo);
        $hash = hash('sha256', 'synthetic');
        $store->appendActivity('1', ['evidence' => 'Synthetic activity'], $hash);
        $store->appendSource(['id' => 'line:1', 'kind' => 'revenue', 'amount_cents' => 9007199254740993,
            'deductible_cents' => 0, 'activity_kind_id' => 1, 'group' => '1:',
            'classification' => ['regime' => 'exempt', 'method' => 'none'],
            'decision' => ['title' => 'gift']], true);
        $store->appendPool('revenue', 'line:1', ['evidence' => 'Synthetic pool'], $hash);
        $store->appendMember('revenue', 'line:1', 'line:1');
        $store->appendLink('line:1', 'line:2', ['amount_cents' => 500, 'evidence' => 'Synthetic link'], $hash);
        $store->appendHistory(['assessment_id' => 7, 'period_id' => 6, 'status' => 'confirmed', 'current' => true,
            'anchor' => 'line:1', 'member_id' => 'line:1', 'net_source_cents' => 9007199254740993,
            'prior_drawdown_cents' => 0, 'drawdown_cents' => 500, 'remaining_cents' => 9007199254740493]);

        self::assertSame(9007199254740993, (int) $store->scalar('SELECT amount_cents FROM {sources}'));
        $plan = $store->prepare("EXPLAIN SELECT * FROM {funding_members} WHERE member_id='line:1' AND pool_kind='revenue'");
        $plan->execute();
        $row = $plan->fetch(PDO::FETCH_ASSOC);
        $plan->closeCursor();
        self::assertNotSame('ALL', $row['type']);
        self::assertSame(['evidence' => 'Synthetic activity'], $store->activity('1'));
        $pages = iterator_to_array($store->fundingHistoryEvidencePages(), false);
        self::assertCount(1, $pages);
        self::assertSame(NonprofitTaxRules::canonicalJson(['assessment_id' => 7, 'period_id' => 6, 'status' => 'confirmed', 'current' => true,
            'anchor' => 'line:1', 'member_id' => 'line:1', 'net_source_cents' => 9007199254740993,
            'prior_drawdown_cents' => 0, 'drawdown_cents' => 500, 'remaining_cents' => 9007199254740493]),
            NonprofitTaxRules::canonicalJson($pages[0][0]));
        self::assertNotSame($before, $this->temporaryTables($pdo));

        $store->closeAndDelete();
        self::assertSame($before, $this->temporaryTables($pdo));
    }

    public function testStreamReadsInBoundedPagesInStableOrderWhileTheConnectionStaysUsable(): void
    {
        $pdo = J6CalculatorFactory::connection()->pdo();
        $store = new J6WorkingStore($pdo);
        try {
            $hash = hash('sha256', 'synthetic');
            for ($i = 1203; $i >= 1; --$i) $store->appendActivity('a' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), ['evidence' => 'Synthetic'], $hash);
            $seen = [];
            foreach ($store->activities() as $activity) {
                $seen[] = $activity['activity_kind_id'];
                $store->scalar('SELECT COUNT(*) FROM {activities}');
            }
            self::assertCount(1203, $seen);
            $sorted = $seen;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $seen);
        } finally {
            $store->closeAndDelete();
        }
    }

    public function testMemberCannotBelongToTwoPoolsOfTheSameKindNorToAnUndeclaredPool(): void
    {
        $store = new J6WorkingStore(J6CalculatorFactory::connection()->pdo());
        try {
            $hash = hash('sha256', 'synthetic');
            $store->appendPool('revenue', 'line:1', ['evidence' => 'First'], $hash);
            $store->appendPool('revenue', 'line:2', ['evidence' => 'Second'], $hash);
            $store->appendMember('revenue', 'line:1', 'line:3');
            foreach ([['line:2', 'line:3'], ['line:9', 'line:4']] as [$anchor, $member]) {
                try {
                    $store->appendMember('revenue', $anchor, $member);
                    self::fail('Expected funding member refusal.');
                } catch (NonprofitTaxAssessmentException $failure) {
                    self::assertSame('nonprofit_tax_facts_incomplete', $failure->errorCode);
                }
            }
        } finally {
            $store->closeAndDelete();
        }
    }

    public function testWorksInsideAnOpenTransactionWithoutImplicitCommit(): void
    {
        $pdo = J6CalculatorFactory::connection()->pdo();
        $owned = !$pdo->inTransaction();
        if ($owned) $pdo->beginTransaction();
        try {
            $store = new J6WorkingStore($pdo);
            $store->appendActivity('1', ['evidence' => 'Synthetic'], hash('sha256', 'synthetic'));
            iterator_to_array($store->activities(), false);
            $store->closeAndDelete();
            self::assertTrue($pdo->inTransaction());
        } finally {
            if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    private function temporaryTables(PDO $pdo): array
    {
        $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type='TEMPORARY TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        sort($rows, SORT_STRING);
        return array_values(array_filter($rows, static fn (string $name): bool => str_starts_with($name, 'j6w_')));
    }
}
