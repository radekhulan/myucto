<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseFromAbsenceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Blížící se a zmeškané mzdové termíny za firmu — jedno volání pro panel
 * „Tento měsíc". Čtecí, bez období v cestě: dashboard se nemá ptát, které
 * období ho zajímá, když jde o to, co hoří TEĎ.
 */
final class PayrollDeadlineOverviewAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDeadlineOverviewService $service,
        private readonly PayrollModuleAccess $access,
        private readonly SicknessCaseFromAbsenceService $sicknessCases,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::READ,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
            }
            return $error;
        }
        if (!$this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
            }
            return $error;
        }

        $query = $request->getQueryParams();
        $environment = $query['environment'] ?? 'production';
        $horizon = $query['horizon_days'] ?? null;
        // Lhůta NEMPRI absence schválené bez případu (chyběl kód OSSZ) se
        // v přehledu objeví, jakmile případ jde založit.
        if ($environment === SicknessCaseFromAbsenceService::ENVIRONMENT
            && RequestAuthorization::allows($request, 'payroll.submissions', AccessLevel::WRITE)
        ) {
            $this->sicknessCases->settleApprovedWithoutCase(
                $this->currentSupplierId($request),
                $this->userId($request),
            );
        }
        try {
            $result = $this->service->overview(
                $this->currentSupplierId($request),
                is_string($environment) ? $environment : '',
                $horizon === null || $horizon === ''
                    ? PayrollDeadlineOverviewService::DEFAULT_HORIZON_DAYS
                    : (int) $horizon,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result);
    }
}
