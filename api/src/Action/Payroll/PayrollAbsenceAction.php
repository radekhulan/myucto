<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollAbsenceConflictException;
use MyInvoice\Repository\Payroll\PayrollAbsenceOverlapException;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveEntitlementDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveLedgerDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use MyInvoice\Service\Payroll\Absence\AutomaticLeaveEntitlementConflictException;
use MyInvoice\Service\Payroll\Absence\AutomaticLeaveEntitlementService;
use MyInvoice\Service\Payroll\Absence\LeaveEntitlementCalculator;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollAbsenceAction
{
    use PayrollActionSupport;
    use PayrollDeletionResponse;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollLeaveRepository $leave,
        private readonly PayrollSicknessRepository $sickness,
        private readonly PayrollAbsenceValidator $validator,
        private readonly AverageEarningCalculator $averageCalculator,
        private readonly LeaveEntitlementCalculator $leaveCalculator,
        private readonly AutomaticLeaveEntitlementService $automaticLeaveEntitlements,
        private readonly SicknessCompensationCalculator $sicknessCalculator,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollAverageEarningDeletionRepository $averageDeletion,
        private readonly PayrollLeaveLedgerDeletionRepository $ledgerDeletion,
        private readonly PayrollLeaveEntitlementDeletionRepository $entitlementDeletion,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function context(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        return Json::ok($response, [
            'employments' => $this->absences->employments($this->currentSupplierId($request)),
            'support_status' => 'manual_review',
        ]);
    }

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        try {
            $from = $this->queryDate($query['from'] ?? null, 'from');
            $to = $this->queryDate($query['to'] ?? null, 'to');
            if ($to < $from) {
                throw new \InvalidArgumentException('Konec filtru nesmí předcházet začátku.');
            }
            $employmentId = $this->optionalPositiveInt($query['employment_id'] ?? null, 'employment_id');
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        // Počet řádků roste součinem počtu zaměstnanců a délky rozsahu, takže
        // roční filtr u větší firmy vrátí neomezenou odpověď. Strop je tvrdý
        // (ne jen výchozí), aby ho nešlo obejít parametrem z URL.
        $limit = max(1, min(
            PayrollAbsenceRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollAbsenceRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->absences->list(
            $this->currentSupplierId($request),
            $from,
            $to,
            $employmentId,
            $limit,
            $offset,
        );

        // Klíč `absences` zůstává, aby stávající volající nespadli;
        // `total`/`limit`/`offset` přibyly vedle něj, protože seznam už nemusí
        // být úplný.
        return Json::ok($response, [
            'absences' => $page['items'],
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
            $absence = $this->absences->create(
                $this->currentSupplierId($request),
                $this->validator->absence($this->body($request)),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceOverlapException $e) {
            return Json::error($response, 'absence_overlap', $e->getMessage(), 409);
        }
        return Json::ok($response, ['absence' => $absence], 201);
    }

    /** @param array<string,string> $args */
    public function decision(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $body = $this->body($request);
        $id = (int) ($args['id'] ?? 0);
        try {
            $version = $this->requiredNonNegativeInt($body['row_version'] ?? null, 'row_version');
            $decision = (string) ($body['decision'] ?? '');
            $absence = $this->absences->find($supplierId, $id)
                ?? throw new \InvalidArgumentException('Absence nebyla nalezena.');
            $calculation = null;
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                if ($decision === 'approved' && $absence['absence_type'] === 'vacation') {
                    $segments = $this->absences->publishedShiftSegments($absence, false);
                    $minutes = array_sum(array_column($segments, 'eligible_minutes'));
                    $calculation = $this->leave->recordTaken($absence, $minutes, $this->userId($request));
                }
                if ($decision === 'approved'
                    && in_array($absence['absence_type'], ['dpn', 'quarantine'], true)
                ) {
                    if ($absence['average_hourly_minor'] === null) {
                        throw new \InvalidArgumentException('DPN vyžaduje schválený snapshot průměru.');
                    }
                    $firstWorked = $this->boolean($body['first_day_fully_worked'] ?? false);
                    $insured = $this->boolean($body['insurance_eligibility_confirmed'] ?? false);
                    $noConflict = $this->boolean($body['conflicting_benefit_excluded'] ?? false);
                    if (!$insured || !$noConflict) {
                        throw new \InvalidArgumentException(
                            'Potvrď účast na pojištění a vyloučení souběžné dávky.'
                        );
                    }
                    $segments = $this->absences->publishedShiftSegments($absence, $firstWorked);
                    $result = $this->sicknessCalculator->calculate(
                        (string) $absence['date_from'],
                        (int) $absence['average_hourly_minor'],
                        $segments,
                    );
                    $calculation = $this->sickness->record(
                        $absence,
                        $firstWorked,
                        $insured,
                        $noConflict,
                        $result,
                        $this->userId($request),
                    );
                }
                $absence = $this->absences->decide(
                    $supplierId,
                    $id,
                    $version,
                    $decision,
                    $this->userId($request),
                );
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, ['absence' => $absence, 'calculation' => $calculation]);
    }

    /** @param array<string,string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = $this->body($request);
        try {
            $version = $this->requiredNonNegativeInt($body['row_version'] ?? null, 'row_version');
            $before = $this->absences->find($supplierId, $id)
                ?? throw new \InvalidArgumentException('Absence nebyla nalezena.');
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                if ($before['status'] === 'approved' && $before['absence_type'] === 'vacation') {
                    $this->leave->reverseTaken($before, $this->userId($request));
                }
                $absence = $this->absences->cancel(
                    $supplierId,
                    $id,
                    $version,
                    $this->userId($request),
                );
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, ['absence' => $absence]);
    }

    public function averages(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $employmentId = $this->optionalPositiveInt(
                $request->getQueryParams()['employment_id'] ?? null,
                'employment_id',
            ) ?? throw new \InvalidArgumentException('employment_id je povinné.');
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, [
            'snapshots' => $this->averages->list(
                $this->currentSupplierId($request),
                $employmentId,
            ),
        ]);
    }

    public function createAverage(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->average($this->body($request));
            $applicationDate = sprintf(
                '%04d-%02d-01',
                $data['applicable_year'],
                (($data['applicable_quarter'] - 1) * 3) + 1,
            );
            $result = $this->averageCalculator->calculate(
                $applicationDate,
                $data['gross_earnings_minor'],
                $data['longer_period_allocated_minor'],
                $data['worked_minutes'],
                $data['worked_days'],
                $data['probable_hourly_minor'],
                $data['rationale'],
            );
            $ruleset = $this->rulesets->forDate(
                PayrollRulesetDomain::CompensationAverages,
                $applicationDate,
            );
            $snapshot = $this->averages->create(
                $this->currentSupplierId($request),
                $data['employment_id'],
                $data['applicable_year'],
                $data['applicable_quarter'],
                $data['decisive_from'],
                $data['decisive_to'],
                $data['gross_earnings_minor'],
                $data['longer_period_allocated_minor'],
                $data['worked_minutes'],
                $data['worked_days'],
                $data['rationale'],
                $result,
                $ruleset,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['snapshot' => $snapshot], 201);
    }

    /** @param array<string,string> $args */
    public function approveAverage(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $version = $this->requiredNonNegativeInt(
                $this->body($request)['row_version'] ?? null,
                'row_version',
            );
            $snapshot = $this->averages->approve(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $version,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, ['snapshot' => $snapshot]);
    }

    public function leaveLedger(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $employmentId = $this->optionalPositiveInt($query['employment_id'] ?? null, 'employment_id')
                ?? throw new \InvalidArgumentException('employment_id je povinné.');
            $year = $this->optionalPositiveInt($query['year'] ?? null, 'year')
                ?? throw new \InvalidArgumentException('year je povinný.');
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $supplierId = $this->currentSupplierId($request);
        return Json::ok($response, [
            'entries' => $this->leave->list($supplierId, $employmentId, $year),
            // Revize nároku patří do stejné odpovědi jako kniha — bez nich by
            // uživatel neměl kde spočítaný nárok najít, natož ho smazat.
            'entitlements' => $this->leave->entitlements($supplierId, $employmentId, $year),
            'balance_minutes' => $this->leave->balance($supplierId, $employmentId, $year),
        ]);
    }

    public function createLeaveEntry(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $this->body($request);
        try {
            $leaveYear = $this->requiredPositiveInt($body['leave_year'] ?? null, 'leave_year');
            $effectiveDate = $this->queryDate($body['effective_date'] ?? null, 'effective_date');
            $this->validator->assertLeaveEntryPeriod($leaveYear, $effectiveDate);
            $entry = $this->leave->appendManual(
                $this->currentSupplierId($request),
                $this->requiredPositiveInt($body['employment_id'] ?? null, 'employment_id'),
                $leaveYear,
                $effectiveDate,
                trim((string) ($body['entry_type'] ?? '')),
                $this->requiredNonZeroInt($body['minutes_delta'] ?? null, 'minutes_delta'),
                trim((string) ($body['reason'] ?? '')),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['entry' => $entry], 201);
    }

    public function createEntitlement(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->entitlement($this->body($request));
            $supplierId = $this->currentSupplierId($request);
            $relationType = $this->leave->employmentRelationType(
                $supplierId,
                $data['employment_id'],
            );
            $result = $this->leaveCalculator->calculate(
                sprintf('%04d-01-01', $data['leave_year']),
                $relationType,
                $data['weekly_minutes'],
                $data['entitlement_weeks'],
                $data['continuous_calendar_days'],
                $data['worked_equivalent_minutes'],
                $data['rationale'],
            );
            $entitlement = $this->leave->recordEntitlement(
                $supplierId,
                $data['employment_id'],
                $data['leave_year'],
                $relationType,
                $data['entitlement_weeks'],
                $data['continuous_calendar_days'],
                $data['worked_equivalent_minutes'],
                $data['rationale'],
                $result,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['entitlement' => $entitlement], 201);
    }

    public function leaveEntitlementCandidates(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $year = $this->requiredPositiveInt($query['year'] ?? null, 'year');
            $through = $this->queryDate($query['through'] ?? null, 'through');
            $limit = max(1, min(
                AutomaticLeaveEntitlementService::MAX_LIMIT,
                (int) ($query['limit'] ?? AutomaticLeaveEntitlementService::DEFAULT_LIMIT),
            ));
            $offset = max(0, (int) ($query['offset'] ?? 0));
            $page = $this->automaticLeaveEntitlements->page(
                $this->currentSupplierId($request),
                $year,
                $through,
                $limit,
                $offset,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, $page);
    }

    public function createAutomaticEntitlements(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $year = $this->requiredPositiveInt($body['year'] ?? null, 'year');
            $through = $this->queryDate($body['through'] ?? null, 'through');
            $items = $body['items'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new \InvalidArgumentException('Výběr pracovních vztahů není platný.');
            }
            $entitlements = $this->automaticLeaveEntitlements->calculateBatch(
                $this->currentSupplierId($request),
                $year,
                $through,
                $items,
                $this->userId($request),
            );
        } catch (AutomaticLeaveEntitlementConflictException $exception) {
            return Json::error(
                $response,
                'leave_entitlement_inputs_changed',
                $exception->getMessage(),
                409,
                ['employment_id' => $exception->employmentId],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, ['entitlements' => $entitlements], 201);
    }

    /**
     * Smaže špatně zadaný průměrný výdělek, který ještě nikdo neschválil.
     *
     * Právo je `payroll.time.write`, tedy TOTÉŽ, kterým se průměr zakládá.
     * Před schváleným výpočtem a navázanou náhradou chrání blokátory
     * v repozitáři, ne zvláštní právo.
     *
     * @param array<string,string> $args
     */
    public function deleteAverage(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }

        return $this->runDeletion(
            $request,
            $response,
            fn (int $supplierId, int $id, ?int $version, ?int $userId, string $ip, string $agent): array
                => $this->averageDeletion->delete($supplierId, $id, $version, $userId, $ip, $agent),
            (int) ($args['id'] ?? 0),
        );
    }

    /**
     * Smaže ručně zadaný zápis v knize dovolené.
     *
     * @param array<string,string> $args
     */
    public function deleteLeaveEntry(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }

        return $this->runDeletion(
            $request,
            $response,
            fn (int $supplierId, int $id, ?int $version, ?int $userId, string $ip, string $agent): array
                => $this->ledgerDeletion->delete($supplierId, $id, $version, $userId, $ip, $agent),
            (int) ($args['id'] ?? 0),
        );
    }

    /**
     * Smaže poslední revizi nároku na dovolenou i její zápisy v knize.
     *
     * @param array<string,string> $args
     */
    public function deleteEntitlement(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }

        return $this->runDeletion(
            $request,
            $response,
            fn (int $supplierId, int $id, ?int $version, ?int $userId, string $ip, string $agent): array
                => $this->entitlementDeletion->delete($supplierId, $id, $version, $userId, $ip, $agent),
            (int) ($args['id'] ?? 0),
        );
    }

    /** @param \Closure(int,int,?int,?int,string,string):array<string,int> $delete */
    private function runDeletion(
        Request $request,
        Response $response,
        \Closure $delete,
        int $id,
    ): Response {
        try {
            $cascade = $delete(
                $this->currentSupplierId($request),
                $id,
                $this->optionalRowVersion($this->deletionBody($request)['row_version'] ?? null),
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->deletionServerParams($request)),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->deletionError($response, $e);
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Mzdové absence jsou dostupné pouze z přihlášené relace.',
                403,
            );
        }
        $permission = $level === AccessLevel::READ ? 'payroll' : 'payroll.time.write';
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    private function queryDate(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být platné datum ve formátu RRRR-MM-DD.',
            );
        }
        return $text;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->requiredPositiveInt($value, $field);
    }

    private function requiredPositiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být kladné celé číslo.',
            );
        }
        return (int) $result;
    }

    private function requiredNonNegativeInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($result === false) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být nezáporné celé číslo.',
            );
        }
        return (int) $result;
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'from' => 'Datum od',
            'to' => 'Datum do',
            'through' => 'Datum výpočtu',
            'effective_date' => 'Datum účinnosti',
            'employment_id' => 'Pracovní vztah',
            'leave_year', 'year' => 'Rok',
            'row_version' => 'Verze záznamu',
            'minutes_delta' => 'Změna nároku v minutách',
            default => 'Zadaná hodnota',
        };
    }

    private function requiredNonZeroInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if ($result === false || (int) $result === 0) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být nenulové celé číslo.',
            );
        }
        return (int) $result;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
