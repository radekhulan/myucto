<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetDeviceRepository;
use MyInvoice\Service\Eet\EetException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Logická zařízení (pokladny) EET. DELETE zařízení deaktivuje; jednotku
 * z těla ověřuje repozitář proti firmě, pokladnu `TenantReferenceGuard`.
 */
final class EetDevicesAction
{
    public function __construct(
        private readonly EetDeviceRepository $devices,
        private readonly TenantReferenceGuard $references,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::READ)) !== null) {
            return $denied;
        }
        return Json::ok($response, ['items' => $this->devices->listFor(SupplierGuard::currentId($request))]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = EetActionSupport::body($request);
        if (($invalid = $this->invalidReference($response, $supplierId, $body)) !== null) {
            return $invalid;
        }
        try {
            $device = $this->devices->create($supplierId, $body);
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->audit($request, 'eet_device_create', $device, $supplierId);
        return Json::ok($response, $device, 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = EetActionSupport::body($request);
        if (($invalid = $this->invalidReference($response, $supplierId, $body)) !== null) {
            return $invalid;
        }
        $previous = $this->devices->find($supplierId, EetActionSupport::id($args));
        try {
            $device = $this->devices->update($supplierId, EetActionSupport::id($args), $body);
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->audit($request, 'eet_device_update', $device, $supplierId, $previous);
        return Json::ok($response, $device);
    }

    /** @param array<string,string> $args */
    public function deactivate(Request $request, Response $response, array $args): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        try {
            $device = $this->devices->deactivate($supplierId, EetActionSupport::id($args));
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->audit($request, 'eet_device_deactivate', $device, $supplierId);
        return Json::ok($response, $device);
    }

    /** @param array<string,mixed> $body */
    private function invalidReference(Response $response, int $supplierId, array $body): ?Response
    {
        $violations = $this->references->violations($supplierId, $body, ['cash_register_id']);
        if ($violations === []) {
            return null;
        }
        return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($violations), 422);
    }

    /**
     * Audit zařízení. U změny nese předchozí stav (`old`), aby šlo dohledat, kdy a kdo
     * přepnul pověření nebo EIČ pověřujícího.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed>|null $previous
     */
    private function audit(Request $request, string $action, array $device, int $supplierId, ?array $previous = null): void
    {
        $payload = self::auditState($device);
        if ($previous !== null) {
            $payload = ['old' => self::auditState($previous), 'new' => $payload];
        }
        $this->logger->log(
            $action,
            EetActionSupport::userId($request),
            'eet_device',
            (int) $device['id'],
            $payload,
            null,
            null,
            $supplierId,
        );
    }

    /**
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private static function auditState(array $device): array
    {
        return [
            'code' => $device['code'], 'unit_id' => $device['unit_id'], 'active' => $device['active'],
            'cash_register_id' => $device['cash_register_id'],
            'delegation' => $device['delegation'], 'delegating_eic' => $device['delegating_eic'],
        ];
    }
}
