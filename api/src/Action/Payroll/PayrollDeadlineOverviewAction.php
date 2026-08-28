<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
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
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
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
