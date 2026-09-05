<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollDimensionConflictException;
use MyInvoice\Repository\Payroll\PayrollDimensionHistoryLockedException;
use MyInvoice\Repository\Payroll\PayrollDimensionInUseException;
use MyInvoice\Repository\Payroll\PayrollDimensionOverlapException;
use MyInvoice\Repository\Payroll\PayrollDimensionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Settings\PayrollDimensionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollDimensionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDimensionRepository $dimensions,
        private readonly PayrollDimensionService $service,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $type = $query['type'] ?? null;
        if ($type !== null && !in_array($type, ['cost_center', 'project', 'activity'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný typ dimenze.', 422);
        }
        $includeInactive = !isset($query['active_only']);

        return Json::ok($response, [
            'dimensions' => $this->dimensions->list(
                $this->currentSupplierId($request),
                is_string($type) ? $type : null,
                $includeInactive,
            ),
        ]);
    }

    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $dimension = $this->dimensions->find(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );

        return $dimension === null
            ? Json::error($response, 'not_found', 'Mzdová dimenze nebyla nalezena.', 404)
            : Json::ok($response, ['dimension' => $dimension]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var(
            $body['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 0]],
        );
        if ($version === false) {
            return Json::error($response, 'validation_failed', 'Nová dimenze musí mít row_version 0.', 422);
        }
        unset($body['row_version']);

        $supplierId = $this->currentSupplierId($request);
        try {
            $dimension = $this->service->save($supplierId, null, $body, 0, $this->userId($request));
        } catch (PayrollDimensionOverlapException $e) {
            return Json::error($response, 'dimension_interval_overlap', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $this->audit($request, 'payroll.dimension.created', $dimension);

        return Json::ok($response, ['dimension' => $dimension], 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var(
            $body['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($version === false) {
            return Json::error($response, 'validation_failed', 'Upravovaná dimenze musí mít kladné row_version.', 422);
        }
        unset($body['row_version']);

        $supplierId = $this->currentSupplierId($request);
        try {
            $dimension = $this->service->save(
                $supplierId,
                (int) ($args['id'] ?? 0),
                $body,
                (int) $version,
                $this->userId($request),
            );
        } catch (PayrollDimensionOverlapException $e) {
            return Json::error($response, 'dimension_interval_overlap', $e->getMessage(), 409);
        } catch (PayrollDimensionHistoryLockedException $e) {
            return Json::error($response, 'dimension_history_locked', $e->getMessage(), 409);
        } catch (PayrollDimensionConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'Mzdová dimenze nebyla nalezena.') {
                throw $e;
            }
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }

        $this->audit($request, 'payroll.dimension.updated', $dimension);

        return Json::ok($response, ['dimension' => $dimension]);
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->dimensions->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Mzdová dimenze nebyla nalezena.', 404);
        }

        try {
            $deleted = $this->dimensions->delete($supplierId, $id);
        } catch (PayrollDimensionInUseException $e) {
            return Json::error($response, 'dimension_in_use', $e->getMessage(), 409);
        }
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Mzdová dimenze nebyla nalezena.', 404);
        }

        $this->audit($request, 'payroll.dimension.deleted', $existing);

        return Json::ok($response, ['deleted' => true]);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.settings', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
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

    /** @param array<string,mixed> $dimension */
    private function audit(Request $request, string $action, array $dimension): void
    {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_dimension',
            $this->int($dimension, 'id'),
            [
                'dimension_type' => $this->string($dimension, 'dimension_type'),
                'code' => $this->string($dimension, 'code'),
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
            throw new \UnexpectedValueException("DTO dimenze nemá celé pole {$field}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("DTO dimenze nemá textové pole {$field}.");
        }

        return $value;
    }
}
