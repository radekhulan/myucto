<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollNetResultAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollNetResultQueryService $results,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{revisionId:string,employeeId:string} $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error ?? Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        try {
            $breakdown = $this->results->breakdown(
                $this->currentSupplierId($request),
                (int) $args['revisionId'],
                (int) $args['employeeId'],
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'net_result_unavailable', $e->getMessage(), 409);
        }

        return Json::ok($response, ['net_result' => $breakdown]);
    }
}
