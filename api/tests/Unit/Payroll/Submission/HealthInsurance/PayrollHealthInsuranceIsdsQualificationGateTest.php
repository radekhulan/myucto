<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\HealthInsurance;

use MyInvoice\Action\Payroll\PayrollHealthInsuranceIsdsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsSubmissionService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

\DG\BypassFinals::allowPaths([
    '*/api/src/Service/Payroll/PayrollModuleAccess.php',
    '*/api/src/Repository/Payroll/PayrollModuleStateRepository.php',
    '*/api/src/Service/Payroll/Submission/HealthInsurance/HealthInsuranceIsdsSubmissionService.php',
]);

final class PayrollHealthInsuranceIsdsQualificationGateTest extends TestCase
{
    public function testUnqualifiedHealthInsuranceIsdsEnqueueIsRejected(): void
    {
        $isds = $this->createMock(HealthInsuranceIsdsSubmissionService::class);
        $isds->expects(self::never())->method('enqueue');
        $access = $this->createStub(PayrollModuleAccess::class);
        $access->method('isEnabled')->willReturn(true);
        // SKUTEČNÁ brána, ne mock: `PayrollProductionGate` je final a BypassFinals
        // odstraňuje `final` jen při načtení třídy. V plné sadě ji stihne načíst
        // dřívější test, takže `allowPaths()` tady přijde pozdě a zdvojení skončí
        // na ClassIsFinalException — což se při běhu s `--filter` neprojeví.
        // Neuvolněný produkt zamítne ostrý provoz sám, na to je `releasedOverride`.
        $gate = new PayrollProductionGate(
            $this->createStub(PayrollModuleStateRepository::class),
            releasedOverride: false,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/42/health-isds/111')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 11)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 9, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $action = new PayrollHealthInsuranceIsdsAction($isds, $access, $gate);

        $response = $action->enqueue(
            $request,
            new Response(),
            ['submissionId' => '42', 'insurerCode' => '111'],
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
