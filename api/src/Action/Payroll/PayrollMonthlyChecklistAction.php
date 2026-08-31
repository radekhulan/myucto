<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Jeden měsíční přehled pro účetní — co přesně vygenerovat/odeslat, kam,
 * jakou cestou a do kdy, včetně toho, co appka neodesílá. Viz
 * {@see PayrollMonthlyChecklistService} pro to, ze kterých pramenů skládá.
 */
final class PayrollMonthlyChecklistAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollMonthlyChecklistService $service,
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
        $period = $query['period'] ?? null;
        try {
            $result = $this->service->checklist(
                $this->currentSupplierId($request),
                is_string($environment) ? $environment : '',
                is_string($period) ? $period : '',
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
