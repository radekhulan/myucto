<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\PreFinalizeCheckService;
use PHPUnit\Framework\TestCase;

final class ReviewedPeriodTaxCheckTest extends TestCase
{
    public function testReviewedBooksPassTaxPeriodCheck(): void
    {
        $class = new \ReflectionClass(PreFinalizeCheckService::class);
        $check = $class->getMethod('checkPeriodStatus')->invoke(
            $class->newInstanceWithoutConstructor(),
            ['status' => 'reviewed', 'fiscal_year' => 2025],
            true,
        );
        self::assertTrue($check['ok']);
    }
}
