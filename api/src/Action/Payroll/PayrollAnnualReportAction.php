<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Report\PayrollAnnualReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollAnnualReportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollAnnualReportService $reports,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{year:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error($response, 'session_required', 'Tento endpoint je dostupný pouze z přihlášené webové session.', 403);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.reports', AccessLevel::READ, $error)
            || !$this->requirePayrollEnabled($request, $response, $this->access, $error)
        ) {
            return $error ?? Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        }
        try {
            return Json::ok($response, [
                'report' => $this->reports->report(
                    $this->currentSupplierId($request),
                    (int) $args['year'],
                ),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
    }
}
