<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotChunkSpool;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;
use PHPUnit\Framework\TestCase;

final class NonprofitTaxSnapshotHasherTest extends TestCase
{
    public function testSnapshotPageRejectsMoreThanNineFindingsPerBoundedSourceRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnapshotPageV2(array_fill(0, 500, []), array_fill(0, 4501, []), null, null, null, 500);
    }

    public function testChunkAndRootBindOrderRowsAndFindings(): void
    {
        $hasher = new NonprofitTaxSnapshotHasher();
        $cursor = new SnapshotCursor('2026-01-01', 10, 20);
        $page = new SnapshotPageV2([
            ['line_id' => 20, 'amount_cents' => 100, 'module_facts' => []],
        ], [['line_id' => 20, 'code' => 'missing_activity']], $cursor, $cursor, null, 1);
        $chunk = $hasher->chunk(0, $page);
        $root = $hasher->startRoot();
        $hasher->updateRoot($root, $chunk['descriptor']);
        $rootHash = $hasher->finishRoot($root, 1);
        self::assertSame($chunk['descriptor'], $hasher->verifyChunk($chunk['payload'], $chunk['descriptor']['chunk_hash']));
        $changed = $page->rows;
        $changed[0]['amount_cents'] = 101;
        $mutated = $hasher->chunk(0, new SnapshotPageV2($changed, $page->findings, $cursor, $cursor, null, 1));
        self::assertNotSame($chunk['descriptor']['chunk_hash'], $mutated['descriptor']['chunk_hash']);
        $otherRoot = $hasher->startRoot();
        $hasher->updateRoot($otherRoot, $mutated['descriptor']);
        self::assertNotSame($rootHash, $hasher->finishRoot($otherRoot, 1));
    }

    public function testSpoolReplaysTenThousandDescriptorsWithoutRetainingTheirArray(): void
    {
        $spool = SnapshotChunkSpool::create();
        $before = memory_get_usage(false);
        try {
            for ($ordinal = 0; $ordinal < 10000; ++$ordinal) {
                $spool->append($ordinal + 1, ['ordinal' => $ordinal, 'chunk_hash' => str_repeat('a', 64),
                    'row_count' => 500, 'finding_count' => 0, 'first_cursor' => null, 'last_cursor' => null]);
            }
            self::assertLessThan(2 * 1024 * 1024, memory_get_usage(false) - $before);
            $count = 0;
            foreach ($spool->records() as $record) {
                if ($count !== $record['descriptor']['ordinal']) throw new \UnexpectedValueException('Spool descriptor order changed.');
                ++$count;
            }
            self::assertSame(10000, $count);
            self::assertLessThan(2 * 1024 * 1024, memory_get_usage(false) - $before);
        } finally {
            $spool->closeAndDelete();
        }
    }
}
