<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\StatementReconciliationConfirmation;
use PHPUnit\Framework\TestCase;

final class StatementReconciliationConfirmationTest extends TestCase
{
    public function testLargeStatementCanConfirmEveryCandidate(): void
    {
        $keys = array_map(static fn (int $i): string => hash('sha256', 'synthetic-' . $i), range(1, 1000));
        self::assertSame($keys, StatementReconciliationConfirmation::parse($keys));
    }
}
