<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use DomainException;
use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollEmploymentSurchargePolicyService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sjednané zásady zákonných příplatků § 114 až § 118 ZP na pracovním vztahu.
 *
 * Jednorázové SJEDNÁNÍ, ne měsíční údaj — proto karta vztahu, a ne rychlý
 * měsíční vstup. Verzuje se: nová kolektivní smlouva zakládá novou verzi vedle
 * staré a mzdy spočítané podle té staré na ni dál ukazují.
 */
final class PayrollEmploymentSurchargePolicyAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSurchargeRepository $repository,
        private readonly PayrollEmploymentSurchargePolicyService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        if (!$this->repository->employmentExists($supplierId, $employmentId)) {
            return Json::error($response, 'not_found', 'Pracovní vztah nebyl nalezen.', 404);
        }
        $query = $request->getQueryParams();
        $effectiveOn = is_string($query['effective_on'] ?? null) && $query['effective_on'] !== ''
            ? $query['effective_on']
            : (new \DateTimeImmutable('today'))->format('Y-m-d');

        try {
            return Json::ok($response, $this->service->forEmployment(
                $supplierId,
                $employmentId,
                $effectiveOn,
            ));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }

    /** @param array<string,string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        if (!$this->repository->employmentExists($supplierId, $employmentId)) {
            return Json::error($response, 'not_found', 'Pracovní vztah nebyl nalezen.', 404);
        }

        try {
            $body = $request->getParsedBody();
            $policy = $this->service->save(
                $supplierId,
                $employmentId,
                is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [],
                $this->userId($request),
            );
        } catch (DomainException $e) {
            // Zásada od téhož nebo pozdějšího dne už existuje. Není to vada
            // vstupu, ale stav — proto 409, ne 422.
            return Json::error($response, 'surcharge_policy_exists', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            // Sem padá i podlezené kogentní minimum z
            // PayrollSurchargePolicy::assertAgreedRateIsLawful().
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $this->logger->log(
            'payroll.employment_surcharge_policy.saved',
            $this->userId($request),
            'payroll_employment_surcharge_policy',
            PayrollTimeValue::int($policy['id'] ?? null, 'id'),
            [
                'employment_id' => $employmentId,
                'valid_from' => $policy['valid_from'] ?? null,
                'overtime_mode' => $policy['overtime_mode'] ?? null,
                'holiday_mode' => $policy['holiday_mode'] ?? null,
            ],
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['policy' => $policy], 201);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.employment.write',
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
