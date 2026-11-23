<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetUnitDisFormat;
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

    /**
     * Soubor CSV pro hromadný import evidenčních jednotek v DIS+. Bez `all=1`
     * obsahuje jen účinné jednotky, kterým DIS+ ještě nepřidělil číslo. Když
     * některé jednotce chybí údaj, vrátí 422 se seznamem, co doplnit.
     */
    public function disImport(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.settings', AccessLevel::READ)) !== null) {
            return $denied;
        }
        $all = filter_var($request->getQueryParams()['all'] ?? false, FILTER_VALIDATE_BOOL);
        $units = $this->units->listForDisImport(
            SupplierGuard::currentId($request),
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $all,
        );
        if ($units === []) {
            return Json::error($response, 'eet_units_nothing_to_export', $all
                ? 'Firma nemá žádnou účinnou evidenční jednotku.'
                : 'Všechny účinné jednotky už mají číslo z DIS+. Pro export všech zvolte „všechny jednotky".', 422);
        }
        if (count($units) > EetUnitDisFormat::MAX_ROWS) {
            return Json::error($response, 'eet_units_too_many', 'DIS+ přijme v jednom souboru nejvýš '
                . EetUnitDisFormat::MAX_ROWS . ' jednotek.', 422);
        }
        $problems = [];
        foreach ($units as $unit) {
            foreach (EetUnitDisFormat::problems($unit) as $problem) {
                $problems[] = ['unit_id' => $unit['id'], 'unit_name' => $unit['name']] + $problem;
            }
        }
        if ($problems !== []) {
            return Json::error(
                $response,
                'eet_units_incomplete',
                'Některým jednotkám chybí údaje pro DIS+: ' . implode(' ', array_map(
                    static fn (array $p): string => '„' . $p['unit_name'] . '": ' . $p['message'],
                    $problems,
                )),
                422,
                ['problems' => $problems],
            );
        }

        $response->getBody()->write(EetUnitDisFormat::csv($units));
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="eet-jednotky-dis.csv"')
            ->withHeader('Cache-Control', 'no-store');
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
