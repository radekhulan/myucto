<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollForeignPermitActionTest extends TestCase
{
    public function testForeignPermitEndpointHasAnExplicitSessionOnlyAction(): void
    {
        self::assertTrue(class_exists(\MyInvoice\Action\Payroll\PayrollForeignPermitAction::class));
    }
}
