<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetUnitRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Evidenční jednotky EET. DELETE ukončí účinnost, jednotku nemaže. */
final class EetUnitsAction
{
    public function __construct(
        private readonly EetUnitRepository $units,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::READ)) !== null) {
            return $denied;
        }
        return Json::ok($response, ['items' => $this->units->listFor(SupplierGuard::currentId($request))]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        try {
            $unit = $this->units->create($supplierId, EetActionSupport::body($request));
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->audit($request, 'eet_unit_create', $unit, $supplierId);
        return Json::ok($response, $unit, 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        try {
            $unit = $this->units->update($supplierId, EetActionSupport::id($args), EetActionSupport::body($request));
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->audit($request, 'eet_unit_update', $unit, $supplierId);
        return Json::ok($response, $unit);
    }

    /** @param array<string,string> $args */
    public function endValidity(Request $request, Response $response, array $args): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $validTo = $request->getQueryParams()['valid_to'] ?? null;
        try {
            $unit = $this->units->endValidity(
                $supplierId,
                EetActionSupport::id($args),
                is_string($validTo) && $validTo !== '' ? $validTo : null,
            );
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->audit($request, 'eet_unit_end', $unit, $supplierId);
        return Json::ok($response, $unit);
    }

    /** @param array<string,mixed> $unit */
    private function audit(Request $request, string $action, array $unit, int $supplierId): void
    {
        $this->logger->log(
            $action,
            EetActionSupport::userId($request),
            'eet_unit',
            (int) $unit['id'],
            ['unit_number' => $unit['unit_number'], 'valid_from' => $unit['valid_from'], 'valid_to' => $unit['valid_to']],
            null,
            null,
            $supplierId,
        );
    }
}
