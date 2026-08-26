<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollProductionGateCoverageTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string}>
     */
    public static function productionEntrypoints(): iterable
    {
        yield 'JMHZ production dispatch' => [
            'Action/Payroll/PayrollJmhzTransportAction.php',
            'assertEnvironmentActive',
        ];
        yield 'PREZEC and REGZEC production dispatch' => [
            'Action/Payroll/PayrollRegistrationTransportAction.php',
            'assertEnvironmentActive',
        ];
        yield 'JMHZ ISDS outbox' => [
            'Action/Payroll/PayrollJmhzIsdsAction.php',
            'assertEnvironmentActive',
        ];
        yield 'health-insurance ISDS outbox' => [
            'Action/Payroll/PayrollHealthInsuranceIsdsAction.php',
            'assertActive',
        ];
        yield 'ISDS gateway production dispatch' => [
            'Action/Submission/IsdsGatewayAction.php',
            'assertEnvironmentActive',
        ];
        yield 'payment command materialization' => [
            'Service/Payroll/Run/PayrollRunPaymentPreparationService.php',
            'assertActive',
        ];
        yield 'payment batch materialization' => [
            'Service/Payroll/Payment/PayrollPaymentBatchBuilder.php',
            'assertActive',
        ];
        yield 'direct payment API materialization' => [
            'Action/Payroll/PayrollPaymentAction.php',
            'assertActive',
        ];
    }

    #[DataProvider('productionEntrypoints')]
    public function testEveryProductionEntrypointUsesQualificationGate(
        string $relativePath,
        string $assertion,
    ): void {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/' . $relativePath,
        );

        self::assertStringContainsString(
            '$this->productionGate->' . $assertion . '(',
            $source,
            "Produkční cesta {$relativePath} obchází kvalifikační bránu.",
        );
    }
}
