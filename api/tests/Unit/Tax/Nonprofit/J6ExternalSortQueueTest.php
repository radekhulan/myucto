<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ExternalSortQueue;
use PHPUnit\Framework\TestCase;

final class J6ExternalSortQueueTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-j6-sort-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) return;
        foreach (new \DirectoryIterator($this->base) as $entry) if (!$entry->isDot()) rmdir($entry->getPathname());
        rmdir($this->base);
    }

    public function testSortsManyRunsAndPreservesDuplicateInsertionOrderWithBoundedBuffer(): void
    {
        $queue = new J6ExternalSortQueue($this->base);
        for ($index = 20000; $index >= 1; --$index) {
            $key = sprintf('line:%08d', $index % 10000);
            $queue->append($key, ['key' => $key, 'sequence' => 20000 - $index]);
        }
        $previous = '';
        $sameKeySequence = -1;
        $count = 0;
        foreach ($queue->records() as $row) {
            self::assertGreaterThanOrEqual($previous, $row['key']);
            if ($row['key'] === $previous) self::assertGreaterThan($sameKeySequence, $row['sequence']);
            else $sameKeySequence = -1;
            $previous = $row['key'];
            $sameKeySequence = $row['sequence'];
            ++$count;
        }
        self::assertSame(20000, $count);
        self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . 'sort-*') ?: []);
    }

    public function testEmptyQueueAlsoDeletesItsOwnedDirectory(): void
    {
        $queue = new J6ExternalSortQueue($this->base);
        self::assertSame([], iterator_to_array($queue->records()));
        self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . 'sort-*') ?: []);
    }
}
