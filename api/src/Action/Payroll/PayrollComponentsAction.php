<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollComponentConflictException;
use MyInvoice\Repository\Payroll\PayrollComponentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollComponentValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollComponentsAction
{
    use PayrollActionSupport;
    use PayrollDeletionResponse;

    public function __construct(
        private readonly PayrollComponentRepository $components,
        private readonly PayrollComponentDeletionRepository $deletion,
        private readonly PayrollComponentValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ, true)) !== null) {
            return $error;
        }
        try {
            $effectiveOn = $this->optionalDate(
                $request->getQueryParams()['effective_on'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'components' => $this->components->list(
                $this->currentSupplierId($request),
                $effectiveOn,
            ),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $component = $this->components->create(
                $this->currentSupplierId($request),
                $this->validator->validate($this->input($request)),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->audit($request, 'payroll.component.created', $component);
        return Json::ok($response, ['component' => $component], 201);
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
            $component = $this->components->update(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->validator->validate($body),
                (int) $version,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Json::error($response, 'component_in_use', $e->getMessage(), 409);
        } catch (PayrollComponentConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($component === null) {
            return Json::error($response, 'not_found', 'Mzdová složka nebyla nalezena.', 404);
        }
        $this->audit($request, 'payroll.component.updated', $component);
        return Json::ok($response, ['component' => $component]);
    }

    /**
     * Smaže nikdy nepoužitou verzi mzdové složky.
     *
     * Právo je `payroll.inputs.write`, tedy TOTÉŽ, kterým se složka zakládá.
     * Před použitou složkou chrání blokátory v repozitáři; pro ni zůstává
     * deaktivace a ukončení platnosti přes `update()`.
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

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
        bool $allowBearer = false,
    ): ?Response {
        if (!$allowBearer && $request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        $permission = $level === AccessLevel::READ
            ? 'payroll'
            : 'payroll.inputs.write';
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
        return is_array($body)
            ? PayrollTimeValue::row($body, 'request_body')
            : [];
    }

    private function optionalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'effective_on musí být datum YYYY-MM-DD.'
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                'effective_on musí být datum YYYY-MM-DD.'
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $component */
    private function audit(Request $request, string $action, array $component): void
    {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_component_definition',
            PayrollTimeValue::int($component['id'] ?? null, 'id'),
            [
                'code' => PayrollTimeValue::string(
                    $component['code'] ?? null,
                    'code',
                ),
                'component_kind' => PayrollTimeValue::string(
                    $component['component_kind'] ?? null,
                    'component_kind',
                ),
                'row_version' => PayrollTimeValue::int(
                    $component['row_version'] ?? null,
                    'row_version',
                ),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}
