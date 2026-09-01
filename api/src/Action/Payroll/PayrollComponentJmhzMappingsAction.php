<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingConflictException;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzTargetCatalog;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollComponentJmhzMappingsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollComponentRepository $components,
        private readonly PayrollComponentJmhzMappingRepository $mappings,
        private readonly PayrollComponentJmhzTargetCatalog $targets,
        private readonly PayrollComponentJmhzMappingDefaults $mappingDefaults,
        private readonly Connection $db,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function targets(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok($response, [
            'package_key' => PayrollComponentJmhzTargetCatalog::PACKAGE_KEY,
            'manifest_sha256' => PayrollComponentJmhzTargetCatalog::MANIFEST_SHA256,
            'topology_hash' => $this->targets->topologyHash(),
            'targets' => $this->targets->targets(),
        ]);
    }

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        // Výchozí zařazení jednoznačných složek se doplňuje až při čtení téhle
        // obrazovky, ne při zakládání firmy. Je to týž vzorec „doinstaluje se při
        // prvním použití", jaký v modulu už používají číselníky JMHZ (balíček
        // specifikace se instaluje teprve při zápisu mapování), takže dřív by
        // nebylo na co mapovat. Účetní tak vidí předvyplněný stav rovnou tady
        // a může ho přepsat; doplňuje se jen tam, kde ještě žádná volba není.
        foreach ($this->mappingDefaults->apply($supplierId) as $default) {
            $this->audit($request, 'payroll.component_jmhz_mapping.default_applied', $default, null);
        }
        $mappings = $this->mappings->listForSupplier($supplierId);
        $items = [];
        foreach ($this->components->list($supplierId) as $component) {
            $componentId = PayrollTimeValue::int($component['id'] ?? null, 'component_id');
            $items[] = $this->responsePayload($component, $mappings[$componentId] ?? null);
        }

        return Json::ok($response, ['items' => $items]);
    }

    /** @param array<string,string> $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $componentId = (int) ($args['id'] ?? 0);
        $component = $this->components->find($supplierId, $componentId);
        if ($component === null) {
            return Json::error($response, 'not_found', 'Mzdová složka nebyla nalezena.', 404);
        }

        return Json::ok($response, $this->responsePayload(
            $component,
            $this->mappings->find($supplierId, $componentId),
        ));
    }

    /** @param array<string,string> $args */
    public function put(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $componentId = (int) ($args['id'] ?? 0);
        $component = $this->components->find($supplierId, $componentId);
        if ($component === null) {
            return Json::error($response, 'not_found', 'Mzdová složka nebyla nalezena.', 404);
        }
        $body = $this->input($request);
        $targetId = $body['target_attribute_id'] ?? null;
        if (!is_string($targetId) || $targetId === '') {
            return Json::error(
                $response,
                'validation_failed',
                'target_attribute_id musí být podporovaný atribut JMHZ.',
                422,
            );
        }
        try {
            $expectedVersion = $this->optionalVersion($body['row_version'] ?? null);
            $mapping = $this->saveMapping(
                $request,
                $supplierId,
                $componentId,
                $targetId,
                $expectedVersion,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'jmhz_mapping_not_allowed', $e->getMessage(), 409);
        } catch (PayrollComponentJmhzMappingConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, $this->responsePayload($component, $mapping));
    }

    /** @param array<string,string> $args */
    public function remove(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $componentId = (int) ($args['id'] ?? 0);
        if ($this->components->find($supplierId, $componentId) === null) {
            return Json::error($response, 'not_found', 'Mzdová složka nebyla nalezena.', 404);
        }
        $body = $this->input($request);
        try {
            $version = $this->requiredVersion($body['row_version'] ?? null);
            $before = $this->mappings->find($supplierId, $componentId);
            if ($before === null || !PayrollTimeValue::bool($before['is_active'] ?? null, 'is_active')) {
                return $response->withStatus(204);
            }
            $this->disableMapping($request, $supplierId, $componentId, $version);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollComponentJmhzMappingConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return $response->withStatus(204);
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
        $permission = $level === AccessLevel::READ ? 'payroll' : 'payroll.inputs.write';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
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

    private function optionalVersion(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->requiredVersion($value);
    }

    private function requiredVersion(mixed $value): int
    {
        $version = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false) {
            throw new \InvalidArgumentException('row_version musí být kladné celé číslo.');
        }

        return (int) $version;
    }

    /**
     * @param array<string,mixed> $component
     * @param array<string,mixed>|null $mapping
     * @return array<string,mixed>
     */
    private function responsePayload(array $component, ?array $mapping): array
    {
        $treatment = PayrollTimeValue::string($component['jmhz_treatment'] ?? null, 'jmhz_treatment');
        $active = $mapping !== null
            && PayrollTimeValue::bool($mapping['is_active'] ?? null, 'is_active');
        $status = match ($treatment) {
            'included' => $active ? 'configured' : 'missing',
            'manual_review' => 'manual_review',
            default => 'excluded',
        };

        return [
            'component_id' => PayrollTimeValue::int($component['id'] ?? null, 'component_id'),
            'jmhz_treatment' => $treatment,
            'status' => $status,
            'mapping' => $mapping,
        ];
    }

    /** @return array<string,mixed> */
    private function saveMapping(
        Request $request,
        int $supplierId,
        int $componentId,
        string $targetId,
        ?int $expectedVersion,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->lockComponent($supplierId, $componentId);
            $before = $this->mappings->find($supplierId, $componentId);
            $mapping = $this->mappings->put(
                $supplierId,
                $componentId,
                $targetId,
                $expectedVersion,
                $this->userId($request),
            );
            if ($before !== $mapping) {
                $this->audit($request, 'payroll.component_jmhz_mapping.saved', $mapping, $before);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $mapping;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function disableMapping(
        Request $request,
        int $supplierId,
        int $componentId,
        int $expectedVersion,
    ): void {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->lockComponent($supplierId, $componentId);
            $before = $this->mappings->find($supplierId, $componentId);
            $mapping = $this->mappings->remove(
                $supplierId,
                $componentId,
                $expectedVersion,
                $this->userId($request),
            );
            if ($before !== null && $mapping !== null && $before !== $mapping) {
                $this->audit(
                    $request,
                    'payroll.component_jmhz_mapping.disabled',
                    $mapping,
                    $before,
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function lockComponent(int $supplierId, int $componentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId, $componentId]);
        if ($stmt->fetchColumn() === false) {
            throw new \OutOfBoundsException('Mzdová složka nebyla nalezena.');
        }
    }

    /**
     * @param array<string,mixed> $mapping
     * @param array<string,mixed>|null $before
     */
    private function audit(
        Request $request,
        string $action,
        array $mapping,
        ?array $before,
    ): void {
        $supplierId = $this->currentSupplierId($request);
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_component_definition',
            PayrollTimeValue::int($mapping['component_definition_id'] ?? null, 'component_definition_id'),
            [
                'mapping_id' => PayrollTimeValue::int($mapping['id'] ?? null, 'mapping_id'),
                'package_key' => PayrollTimeValue::string($mapping['package_key'] ?? null, 'package_key'),
                'spec_manifest_sha256' => PayrollTimeValue::string(
                    $mapping['spec_manifest_sha256'] ?? null,
                    'spec_manifest_sha256',
                ),
                'previous_target_attribute_id' => $before['target_attribute_id'] ?? null,
                'target_attribute_id' => $mapping['target_attribute_id'] ?? null,
                'previous_row_version' => $before['row_version'] ?? null,
                'row_version' => $mapping['row_version'] ?? null,
                'is_active' => $mapping['is_active'] ?? null,
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
