<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentDimensionConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentDimensionOverlapException;
use MyInvoice\Repository\Payroll\PayrollEmploymentDimensionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Settings\PayrollEmploymentDimensionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmploymentDimensionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentDimensionRepository $assignments,
        private readonly PayrollEmploymentDimensionService $service,
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
        if (!$this->assignments->employmentExists($supplierId, $employmentId)) {
            return Json::error($response, 'not_found', 'Pracovní vztah nebyl nalezen.', 404);
        }

        return Json::ok($response, [
            'dimensions' => $this->assignments->listForEmployment($supplierId, $employmentId),
        ]);
    }

    /** @param array<string,string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        try {
            $assignment = $this->service->create(
                $supplierId,
                $employmentId,
                $this->input($request),
                $this->userId($request),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        $this->audit($request, 'payroll.employment_dimension.created', $assignment);

        return Json::ok($response, ['dimension' => $assignment], 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        $assignmentId = (int) ($args['assignmentId'] ?? 0);
        try {
            $assignment = $this->service->update(
                $supplierId,
                $employmentId,
                $assignmentId,
                $this->input($request),
                $this->userId($request),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }

        $this->audit($request, 'payroll.employment_dimension.updated', $assignment);

        return Json::ok($response, ['dimension' => $assignment]);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
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

    private function domainError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof PayrollEmploymentDimensionOverlapException => Json::error(
                $response,
                'employment_dimension_interval_overlap',
                $e->getMessage(),
                409,
            ),
            $e instanceof PayrollEmploymentDimensionConflictException => Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            ),
            $e instanceof \InvalidArgumentException => Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            ),
            $e instanceof \RuntimeException && $e->getMessage() === 'Přiřazení dimenze nebylo nalezeno.' => Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            ),
            $e instanceof \RuntimeException && $e->getMessage() === 'Pracovní vztah pro přiřazení dimenze nebyl nalezen.' => Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            ),
            default => throw $e,
        };
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            return [];
        }
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $assignment */
    private function audit(Request $request, string $action, array $assignment): void
    {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_employment_dimension',
            $this->int($assignment, 'id'),
            [
                'employment_id' => $this->int($assignment, 'employment_id'),
                'dimension_id' => $this->int($assignment, 'dimension_id'),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("DTO přiřazení dimenze nemá celé pole {$field}.");
        }

        return $value;
    }
}
