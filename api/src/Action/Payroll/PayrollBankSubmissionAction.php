<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Payment\PayrollBankSubmissionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollBankSubmissionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollBankSubmissionService $service,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::READ)) return $denied;
        try {
            return Json::ok($response, $this->service->overview($this->currentSupplierId($request), (int) $args['batchId']))
                ->withHeader('Cache-Control', 'no-store');
        } catch (BankConnectorOperationException $e) {
            return $this->failure($response, $e);
        }
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->denied($request, $response, AccessLevel::WRITE)) return $denied;
        $body = $request->getParsedBody();
        $connectionId = is_array($body) ? ($body['connection_id'] ?? null) : null;
        $userId = $this->userId($request);
        if (!is_int($connectionId) || $connectionId < 1 || $userId === null) {
            return Json::error($response, 'validation_failed', 'Vyberte platné napojení banky.', 422);
        }
        $supplierId = $this->currentSupplierId($request);
        $batchId = (int) $args['batchId'];
        try {
            $result = $this->service->submit($supplierId, $batchId, $connectionId, $userId);
            $this->activity->log('payroll.bank_submission', $userId, 'payroll_payment_batch', $batchId,
                ['connection_id' => $connectionId, 'status' => $result['submission']['status'], 'created' => $result['created']],
                supplierId: $supplierId);
            return Json::ok($response, $result, $result['created'] ? 201 : 200)->withHeader('Cache-Control', 'no-store');
        } catch (BankConnectorOperationException $e) {
            return $this->failure($response, $e);
        }
    }

    private function denied(Request $request, Response $response, AccessLevel $minimum): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        foreach (['payroll.payments', 'settings.bank_accounts'] as $permission) {
            if (!$this->requirePermission($request, $response, $permission, $minimum, $error)) return $error;
        }
        return $this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error) ? null : $error;
    }

    private function failure(Response $response, BankConnectorOperationException $e): Response
    {
        return Json::error($response, $e->errorCode, 'Mzdový příkaz se nepodařilo bezpečně předat bance.',
            $e->errorCode === 'payment_order_not_found' ? 404 : 409);
    }
}
