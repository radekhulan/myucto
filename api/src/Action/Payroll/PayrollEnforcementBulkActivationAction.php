<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Garnishment\EnforcementBulkActivationService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné ověření a zahájení srážek u případů čekajících na ověření.
 * Pravidla drží {@see EnforcementBulkActivationService}.
 */
final class PayrollEnforcementBulkActivationAction
{
    use PayrollActionSupport;

    private const MAX_ITEMS = 200;

    public function __construct(
        private readonly EnforcementBulkActivationService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function readiness(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok($response, ['items' => $this->service->readiness($this->currentSupplierId($request))]);
    }

    public function activate(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'documents', AccessLevel::READ, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        $body = $request->getParsedBody();
        $rawItems = is_array($body) ? ($body['items'] ?? null) : null;
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > self::MAX_ITEMS) {
            return Json::error($response, 'validation_failed', 'Vyberte 1 až ' . self::MAX_ITEMS . ' případů.', 422);
        }
        if (!is_array($body) || ($body['confirm_verified'] ?? null) !== true) {
            return Json::error(
                $response,
                'validation_failed',
                'Potvrďte, že jste u vybraných případů ověřili usnesení, doručení a výši pohledávky.',
                422,
            );
        }
        $items = [];
        foreach ($rawItems as $raw) {
            $caseId = is_array($raw) ? ($raw['case_id'] ?? null) : null;
            $version = is_array($raw) ? ($raw['row_version'] ?? null) : null;
            if (!is_int($caseId) || $caseId <= 0 || !is_int($version) || $version <= 0) {
                return Json::error($response, 'validation_failed', 'Každý případ potřebuje case_id a row_version.', 422);
            }
            $items[] = ['case_id' => $caseId, 'row_version' => $version];
        }
        $results = $this->service->activate($this->currentSupplierId($request), $items, $this->userId($request));
        foreach ($results as $result) {
            if ($result['status'] !== 'activated') {
                continue;
            }
            $this->logger->log(
                'payroll.enforcement.case.bulk_activated',
                $this->userId($request),
                'payroll_enforcement_case',
                $result['case_id'],
                ['command' => 'mark_final', 'bulk' => true],
                $this->ipMatcher->clientIpFromRequest(
                    PayrollTimeValue::row($request->getServerParams(), 'server_params'),
                ),
                $request->getHeaderLine('User-Agent'),
            );
        }

        return Json::ok($response, ['results' => $results]);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.enforcement', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
