<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Jmhz;

use MyInvoice\Action\Payroll\PayrollJmhzTransportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolExplainer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

\DG\BypassFinals::allowPaths([
    '*/api/src/Repository/Payroll/PayrollSubmissionTransportAttemptRepository.php',
    '*/api/src/Service/Payroll/PayrollModuleAccess.php',
    '*/api/src/Service/Payroll/PayrollProductionGate.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/Transport/JmhzDispatchService.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/Transport/JmhzProtocolExplainer.php',
]);

final class PayrollJmhzTransportQualificationGateTest extends TestCase
{
    public function testUnqualifiedProductionJmhzDispatchIsRejected(): void
    {
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $access = $this->createStub(PayrollModuleAccess::class);
        $access->method('isEnabled')->willReturn(true);
        $gate = $this->createMock(PayrollProductionGate::class);
        $gate->expects(self::once())
            ->method('assertEnvironmentActive')
            ->with(11, 'production')
            ->willThrowException(new PayrollProductionGateException());
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/42/transport/send')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 11)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 9, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withHeader('Idempotency-Key', 'production-must-not-run')
            ->withParsedBody([
                'environment' => 'production',
                'variable_symbol' => '1234567890',
            ]);
        $action = new PayrollJmhzTransportAction(
            $dispatch,
            new JmhzProtocolExplainer(),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $access,
            $gate,
        );

        $response = $action->send(
            $request,
            new Response(),
            ['submissionId' => '42'],
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(409, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertSame(
            PayrollProductionGateException::ERROR_CODE,
            $payload['error']['code'] ?? null,
        );
    }
}
