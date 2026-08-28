<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollOperationalReconciliationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollOperationalReconciliationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollOperationalReconciliationService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }
        try {
            $result = $this->service->forPeriod(
                $this->currentSupplierId($request),
                $this->period($request),
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            )->withHeader('Cache-Control', 'no-store, private');
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function sweep(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }
        try {
            $result = $this->service->sweep(
                $this->currentSupplierId($request),
                $this->period($request),
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            )->withHeader('Cache-Control', 'no-store, private');
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /** @param array{issueId:string} $args */
    public function detail(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }
        $rawId = $args['issueId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $rawId) !== 1) {
            return Json::error(
                $response,
                'validation_failed',
                'ID reconciliation issue musí být kladné celé číslo.',
                422,
            )->withHeader('Cache-Control', 'no-store, private');
        }
        $detail = $this->service->issue(
            $this->currentSupplierId($request),
            (int) $rawId,
        );
        if ($detail === null) {
            return Json::error(
                $response,
                'not_found',
                'Reconciliation issue nebylo nalezeno.',
                404,
            )->withHeader('Cache-Control', 'no-store, private');
        }

        return Json::ok($response, $detail)
            ->withHeader('Cache-Control', 'no-store, private');
    }

    private function period(Request $request): string
    {
        $query = $request->getQueryParams();
        $period = $query['period'] ?? '';
        if (!is_string($period)
            || preg_match('/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D', $period) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Query parametr period musí mít tvar RRRR-MM.',
            );
        }

        return $period;
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            )->withHeader('Cache-Control', 'no-store, private');
            return false;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll',
            $level,
            $error,
        )) {
            return false;
        }

        return $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function guardFailure(?Response $error): Response
    {
        return ($error ?? throw new \LogicException(
            'Payroll reconciliation guard selhal bez odpovědi.',
        ))->withHeader('Cache-Control', 'no-store, private');
    }
}

