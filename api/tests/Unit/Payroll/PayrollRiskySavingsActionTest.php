<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Action\Payroll\PayrollRiskySavingsAction;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class PayrollRiskySavingsActionTest extends TestCase
{
    public function testSensitiveResponsesAreMarkedAsPrivateAndNotStored(): void
    {
        $action = (new \ReflectionClass(PayrollRiskySavingsAction::class))
            ->newInstanceWithoutConstructor();
        $response = (new \ReflectionMethod($action, 'noStore'))
            ->invoke($action, new Response());

        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }
}
