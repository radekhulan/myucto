<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Run\PayrollMonthGuideService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollMonthGuideAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollMonthGuideService $guide,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)
            || !$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        $query = $request->getQueryParams();
        $period = $query['period'] ?? null;
        $environment = $query['environment'] ?? 'production';
        try {
            $result = $this->guide->guide(
                $this->currentSupplierId($request),
                is_string($period) ? trim($period) : '',
                is_string($environment) ? $environment : '',
                $this->userId($request),
                [
                    'submissions' => RequestAuthorization::allows($request, 'payroll.submissions', AccessLevel::READ),
                    'imports' => RequestAuthorization::allows($request, 'payroll.inputs.write', AccessLevel::WRITE),
                    'documents' => RequestAuthorization::allows($request, 'payroll.documents', AccessLevel::READ),
                ],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return $this->noStore(Json::ok($response, $result));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
