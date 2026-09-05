<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentConflictException;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollRecurringComponentValidator;
use MyInvoice\Service\Payroll\Component\PayrollRecurringMaterializer;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollRecurringComponentsAction
{
    use PayrollActionSupport;
    use PayrollDeletionResponse;

    public function __construct(
        private readonly PayrollRecurringComponentRepository $recurring,
        private readonly PayrollRecurringComponentDeletionRepository $deletion,
        private readonly PayrollRecurringComponentValidator $validator,
        private readonly PayrollRecurringMaterializer $materializer,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $employmentId = $query['employment_id'] ?? null;
        if ($employmentId !== null) {
            $parsed = filter_var($employmentId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($parsed === false) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'employment_id musí být kladné celé číslo.',
                    422,
                );
            }
            $employmentId = (int) $parsed;
        }
        // `employment_id` je volitelný, takže bez filtru je seznam součin
        // „počet lidí × počet předpisů". Strop je tvrdý, ne jen výchozí.
        $limit = max(1, min(
            PayrollRecurringComponentRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollRecurringComponentRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $page = $this->recurring->list(
            $this->currentSupplierId($request),
            is_int($employmentId) ? $employmentId : null,
            $limit,
            $offset,
        );

        // Klíč `recurring_components` zůstává kvůli stávajícím volajícím.
        return Json::ok($response, [
            'recurring_components' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $recurring = $this->recurring->create(
                $this->currentSupplierId($request),
                $this->validator->validate($this->input($request)),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->audit($request, 'payroll.recurring_component.created', $recurring);
        return Json::ok($response, ['recurring_component' => $recurring], 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($version === false) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        unset($body['row_version']);
        try {
            $recurring = $this->recurring->update(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->validator->validate($body),
                (int) $version,
                $this->userId($request),
            );
        } catch (PayrollRecurringComponentConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if ($recurring === null) {
            return Json::error($response, 'not_found', 'Předpis nebyl nalezen.', 404);
        }
        $this->audit($request, 'payroll.recurring_component.updated', $recurring);
        return Json::ok($response, ['recurring_component' => $recurring]);
    }

    /**
     * Smaže čerstvý předpis, ze kterého se ještě nic nematerializovalo.
     *
     * Právo je `payroll.inputs.write`, tedy TOTÉŽ, kterým se předpis zakládá.
     * Po materializaci chrání blokátor v repozitáři a zůstává deaktivace.
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $cascade = $this->deletion->delete(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->optionalRowVersion($this->input($request)['row_version'] ?? null),
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->deletionError($response, $e);
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    public function materialize(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        try {
            $result = $this->materializer->materialize(
                $this->currentSupplierId($request),
                PayrollTimeValue::string($body['period'] ?? null, 'period'),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.recurring_components.materialized',
            $this->userId($request),
            'payroll_period',
            null,
            [
                'period' => $result['period'],
                'created_count' => $result['created_count'],
                'replayed_count' => $result['replayed_count'],
                'manual_review_count' => $result['manual_review_count'],
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
        return Json::ok($response, ['materialization' => $result]);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        $permission = $level === AccessLevel::READ ? 'payroll' : 'payroll.inputs.write';
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
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

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    /** @param array<string,mixed> $recurring */
    private function audit(Request $request, string $action, array $recurring): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_recurring_component',
            PayrollTimeValue::int($recurring['id'] ?? null, 'id'),
            [
                'employment_id' => $recurring['employment_id'],
                'component_id' => $recurring['component_id'],
                'calculation_kind' => $recurring['calculation_kind'],
                'valid_from' => $recurring['valid_from'],
                'valid_to' => $recurring['valid_to'],
                'is_active' => $recurring['is_active'],
                'row_version' => $recurring['row_version'],
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}
