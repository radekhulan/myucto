<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollBusinessTripConflictException;
use MyInvoice\Repository\Payroll\PayrollBusinessTripDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollBusinessTripRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Travel\BusinessTripCalculation;
use MyInvoice\Service\Payroll\Travel\BusinessTripCalculator;
use MyInvoice\Service\Payroll\Travel\BusinessTripMaterializer;
use MyInvoice\Service\Payroll\Travel\BusinessTripValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Session-only API evidence pracovních cest a jejich cestovních náhrad (MZ-08-W07).
 */
final class PayrollTravelAction
{
    use PayrollActionSupport;
    use PayrollDeletionResponse;

    public function __construct(
        private readonly PayrollBusinessTripRepository $trips,
        private readonly PayrollBusinessTripDeletionRepository $deletion,
        private readonly BusinessTripValidator $validator,
        private readonly BusinessTripCalculator $calculator,
        private readonly BusinessTripMaterializer $materializer,
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
        $period = $query['period'] ?? null;
        // Období je volitelné, takže bez stropu tenhle endpoint četl všechny
        // pracovní cesty firmy od jejího vzniku. Strop je tvrdý (ne jen výchozí),
        // aby ho nešlo obejít parametrem z URL.
        $limit = max(1, min(
            PayrollBusinessTripRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollBusinessTripRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $employmentId = self::narrowingId($query, 'employment_id');

        try {
            $periodStart = $period === null || $period === '' ? null : $this->month($period);
            $page = $this->trips->list(
                $this->currentSupplierId($request),
                $periodStart,
                $limit,
                $offset,
                $employmentId,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        // Klíč `trips` zůstává, aby stávající volající nespadli; `total`/`limit`/`offset`
        // přibyly vedle něj, protože seznam už nemusí být úplný. `employment_id`
        // hlásí uplatněné zúžení, aby prohlížeč poznal zúžené prázdno od nezúženého.
        return Json::ok($response, [
            'trips' => $page['items'],
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employment_id' => $employmentId,
        ]);
    }

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->validate($this->input($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'calculation' => $this->calculate($data)->jsonSerialize(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $trip = $this->trips->create(
                $this->currentSupplierId($request),
                $this->validator->validate($this->input($request)),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->audit($request, 'payroll.travel.created', $trip);

        return Json::ok($response, ['trip' => $trip], 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = $this->rowVersion($body['row_version'] ?? null);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        unset($body['row_version']);
        try {
            $trip = $this->trips->update(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->validator->validate($body),
                $version,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollBusinessTripConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($trip === null) {
            return Json::error($response, 'not_found', 'Pracovní cesta nebyla nalezena.', 404);
        }
        $this->audit($request, 'payroll.travel.updated', $trip);

        return Json::ok($response, ['trip' => $trip]);
    }

    /** @param array<string,string> $args */
    public function recalculate(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $trip = $this->trips->find(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );
        if ($trip === null) {
            return Json::error($response, 'not_found', 'Pracovní cesta nebyla nalezena.', 404);
        }

        return Json::ok($response, [
            'trip' => $trip,
            'calculation' => $this->calculateStored($trip)->jsonSerialize(),
        ]);
    }

    /** @param array<string,string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        $version = $this->rowVersion($this->input($request)['row_version'] ?? null);
        if ($version === null) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $trip = $this->trips->find($supplierId, $id);
        if ($trip === null) {
            return Json::error($response, 'not_found', 'Pracovní cesta nebyla nalezena.', 404);
        }
        $calculation = $this->calculateStored($trip);
        if (!$calculation->isSupported()) {
            return Json::error(
                $response,
                'travel_requires_manual_review',
                'Vyúčtování vyžaduje ruční posouzení, schválit ho nelze.',
                409,
                ['blockers' => $calculation->blockers],
            );
        }
        try {
            $approved = $this->trips->approve(
                $supplierId,
                $id,
                $version,
                $calculation->rulesetIds === [] ? 'none' : implode(',', $calculation->rulesetIds),
                CanonicalJson::encode($calculation->jsonSerialize()),
                $calculation->entitlementTotalMinor,
                $calculation->exemptTotalMinor,
                $calculation->taxableTotalMinor,
                $this->userId($request),
            );
        } catch (\DomainException $e) {
            return Json::error($response, 'trip_state_conflict', $e->getMessage(), 409);
        } catch (PayrollBusinessTripConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        if ($approved === null) {
            return Json::error($response, 'not_found', 'Pracovní cesta nebyla nalezena.', 404);
        }
        $this->audit($request, 'payroll.travel.approved', $approved);

        return Json::ok($response, [
            'trip' => $approved,
            'calculation' => $calculation->jsonSerialize(),
        ]);
    }

    /** @param array<string,string> $args */
    public function materialize(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        try {
            $result = $this->materializer->materialize(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->userId($request),
            );
        } catch (\DomainException $e) {
            return Json::error($response, 'trip_state_conflict', $e->getMessage(), 409);
        }
        if (($result['status'] ?? null) === 'not_found') {
            return Json::error($response, 'not_found', 'Pracovní cesta nebyla nalezena.', 404);
        }
        $this->logger->log(
            'payroll.travel.materialized',
            $this->userId($request),
            'payroll_business_trip',
            (int) ($args['id'] ?? 0),
            $result,
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );

        return Json::ok($response, ['materialization' => $result]);
    }

    /**
     * Smaže rozpracovanou pracovní cestu, která vůbec neměla vzniknout.
     *
     * Právo je `payroll.inputs.write`, tedy TOTÉŽ, kterým se cesta zakládá:
     * smazání konceptu je opak jeho založení, ne přísnější úkon. Před schválenou
     * a vyúčtovanou cestou chrání blokátory v repozitáři, ne zvláštní právo.
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

    /**
     * Zruší cestu, která se nakonec nekonala. Na rozdíl od smazání nechá stopu.
     *
     * Právo je `payroll.approve` — zrušení schválené cesty bere zpět schválení,
     * a to je úkon téže váhy jako schválit nebo vyúčtovat. Kdo má jen
     * `payroll.inputs.write`, uklidí svůj vlastní překlep smazáním konceptu.
     *
     * @param array<string,string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            AccessLevel::WRITE,
            'payroll.approve',
        )) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        try {
            $changed = $this->deletion->cancel(
                $supplierId,
                $id,
                $this->optionalRowVersion($this->input($request)['row_version'] ?? null),
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->deletionError($response, $e);
        }
        $trip = $this->trips->find($supplierId, $id);
        if ($trip === null) {
            return Json::error($response, 'not_found', 'Pracovní cesta nebyla nalezena.', 404);
        }

        // `changed` je false, když už cesta zrušená byla — opakované zrušení
        // nesmí spadnout ani vyrobit druhý auditní záznam.
        return Json::ok($response, ['trip' => $trip, 'cancelled' => $changed]);
    }

    /** @param array<string,mixed> $data */
    private function calculate(array $data): BusinessTripCalculation
    {
        /** @var list<array<string,mixed>> $items */
        $items = $data['items'] ?? [];
        /** @var array<string,int> $meals */
        $meals = $data['free_meals'] ?? [];
        try {
            $trip = BusinessTripValidator::toDomain($data, $items, $meals);
        } catch (\InvalidArgumentException $e) {
            return BusinessTripCalculation::blocked([$e->getMessage()]);
        }

        return $this->calculator->calculate($trip);
    }

    /** @param array<string,mixed> $trip */
    private function calculateStored(array $trip): BusinessTripCalculation
    {
        /** @var list<array<string,mixed>> $items */
        $items = $trip['items'] ?? [];
        /** @var array<string,int> $meals */
        $meals = $trip['free_meals'] ?? [];

        return $this->calculate([
            // Přepočet uložené cesty jede z MÍSTNÍHO času, ne z UTC instantu:
            // pásma stravného se počítají podle místních kalendářních dnů.
            'departure_at_local' => PayrollTimeValue::string(
                $trip['departure_at_local'] ?? null,
                'departure_at_local',
            ),
            'arrival_at_local' => PayrollTimeValue::string(
                $trip['arrival_at_local'] ?? null,
                'arrival_at_local',
            ),
            'country_code' => PayrollTimeValue::string(
                $trip['country_code'] ?? null,
                'country_code',
            ),
            'transport_mode' => PayrollTimeValue::string(
                $trip['transport_mode'] ?? null,
                'transport_mode',
            ),
            'meal_rate_band_1_minor' => $trip['meal_rate_band_1_minor'] ?? null,
            'meal_rate_band_2_minor' => $trip['meal_rate_band_2_minor'] ?? null,
            'meal_rate_band_3_minor' => $trip['meal_rate_band_3_minor'] ?? null,
            'advance_minor' => $trip['advance_minor'] ?? 0,
            'items' => $items,
            'free_meals' => $meals,
        ]);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?string $permissionOverride = null,
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        $permission = $permissionOverride ?? (
            $level === AccessLevel::READ ? 'payroll' : 'payroll.inputs.write'
        );
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

    private function month(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }

        return $value . '-01';
    }

    private function rowVersion(mixed $value): ?int
    {
        $version = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $version === false ? null : (int) $version;
    }

    /** @param array<string,mixed> $trip */
    private function audit(Request $request, string $action, array $trip): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_business_trip',
            PayrollTimeValue::int($trip['id'] ?? null, 'id'),
            [
                'employee_id' => PayrollTimeValue::int($trip['employee_id'] ?? null, 'employee_id'),
                'employment_id' => PayrollTimeValue::int(
                    $trip['employment_id'] ?? null,
                    'employment_id',
                ),
                'settlement_period_start' => PayrollTimeValue::string(
                    $trip['settlement_period_start'] ?? null,
                    'settlement_period_start',
                ),
                'status' => PayrollTimeValue::string($trip['status'] ?? null, 'status'),
                'row_version' => PayrollTimeValue::int(
                    $trip['row_version'] ?? null,
                    'row_version',
                ),
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
