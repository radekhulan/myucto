<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollInputApprovalException;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Skončení pracovního vztahu na kartě vztahu: způsob a důvod skončení,
 * vyrovnání dovolené, odstupné a úmrtí (§ 328 ZP).
 */
final class PayrollEmploymentTerminationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentTerminationService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::READ, null, fn (int $supplierId, int $employmentId): array =>
            $this->service->overview($supplierId, $employmentId), $args);
    }

    /** @param array<string,string> $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_saved', fn (int $supplierId, int $employmentId): array =>
            $this->service->save($supplierId, $employmentId, $this->body($request), $this->userId($request)), $args);
    }

    /** @param array<string,string> $args */
    public function settleLeave(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_leave_settled', fn (int $supplierId, int $employmentId): array =>
            $this->service->settleLeave($supplierId, $employmentId, $this->userId($request)), $args);
    }

    /** @param array<string,string> $args */
    public function reverseLeave(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_leave_reversed', fn (int $supplierId, int $employmentId): array =>
            $this->service->reverseLeaveSettlement($supplierId, $employmentId, $this->userId($request)), $args);
    }

    /** @param array<string,string> $args */
    public function createSeverance(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_severance_created', fn (int $supplierId, int $employmentId): array =>
            $this->service->createSeveranceInput(
                $supplierId,
                $employmentId,
                $this->userId($request),
                self::optionalInt($this->body($request)['garnishment_multiple'] ?? null, 'Počet násobků pro srážky'),
            ), $args);
    }

    /** @param array<string,string> $args */
    public function createWorkInjuryCompensation(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_work_injury_compensation_created', fn (int $supplierId, int $employmentId): array =>
            $this->service->createWorkInjuryCompensation($supplierId, $employmentId, $this->body($request), $this->userId($request)), $args);
    }

    private static function optionalInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \InvalidArgumentException("{$label} musí být celé číslo.");
    }

    /** @param array<string,string> $args */
    public function addSurvivor(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_survivor_added', fn (int $supplierId, int $employmentId): array =>
            $this->service->addSurvivor($supplierId, $employmentId, $this->body($request), $this->userId($request)), $args);
    }

    /** @param array<string,string> $args */
    public function removeSurvivor(Request $request, Response $response, array $args): Response
    {
        $survivorId = (int) ($args['survivorId'] ?? 0);

        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_survivor_removed', fn (int $supplierId, int $employmentId): array =>
            $this->service->removeSurvivor($supplierId, $employmentId, $survivorId), $args);
    }

    /** @param array<string,string> $args */
    public function assessDeathTax(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, 'payroll.employment.termination_death_tax_assessed', fn (int $supplierId, int $employmentId): array =>
            $this->service->assessDeathTax($supplierId, $employmentId, $this->body($request), $this->userId($request)), $args);
    }

    /**
     * @param \Closure(int,int):array<string,mixed> $operation
     * @param array<string,string> $args
     */
    private function run(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?string $event,
        \Closure $operation,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response, $level)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        try {
            $result = $operation($supplierId, $employmentId);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (PayrollEmploymentConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (PayrollInputApprovalException $e) {
            return Json::error($response, 'input_approval_failed', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Json::error($response, 'termination_state_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if ($event !== null) {
            $this->logger->log(
                $event,
                $this->userId($request),
                'payroll_employment',
                $employmentId,
                ['termination' => $result['termination']['termination_method'] ?? null],
                $this->ipMatcher->clientIpFromRequest(
                    PayrollTimeValue::row($request->getServerParams(), 'server_params'),
                ),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return Json::ok($response, $result);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.employment.write', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
