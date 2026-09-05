<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MZ-18-W07 — GET /api/payroll/posting/reconciliation. Read-only: nikdy
 * nemění deník ani mzdovou revizi, jen porovnává už existující data.
 */
final class PayrollPostingReconciliationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPostingReconciliationService $reconciliation,
        private readonly PayrollModuleAccess $moduleAccess,
    ) {}

    /** @param array<string,string> $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        $periodValue = $request->getQueryParams()['period'] ?? null;
        if (!is_string($periodValue)) {
            return Json::error(
                $response,
                'validation_failed',
                'Mzdové období musí být text ve tvaru RRRR-MM.',
                422,
            );
        }
        try {
            $result = $this->reconciliation->forPeriod(
                $this->currentSupplierId($request),
                trim($periodValue),
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function authorize(
        Request $request,
        Response $response,
        ?Response &$error,
    ): bool {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::sessionRequired($response);
            return false;
        }

        return $this->requirePermission(
            $request,
            $response,
            'payroll.post',
            AccessLevel::READ,
            $error,
        ) && $this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        );
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}
