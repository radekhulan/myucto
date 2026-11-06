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
use MyInvoice\Repository\Payroll\PayrollLeaveOverdrawException;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Absence\AbsenceHolidayTreatment;
use MyInvoice\Service\Payroll\Absence\PayrollAbsenceApprovalWarnings;
use MyInvoice\Service\Payroll\Absence\AutomaticLeaveEntitlementConflictException;
use MyInvoice\Service\Payroll\Absence\AutomaticLeaveEntitlementService;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchConflictException;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Absence\AverageEarningCalculator;
use MyInvoice\Service\Payroll\Absence\AverageEarningDerivationService;
use MyInvoice\Service\Payroll\Absence\LeaveEntitlementCalculator;
use MyInvoice\Service\Payroll\Absence\PayrollLeaveInputMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollObstacleInputMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollObstacleKind;
use MyInvoice\Service\Payroll\Absence\PayrollSicknessInputMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollAbsenceShiftsMissingException;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\PayrollAbsenceValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseFromAbsenceService;
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
        private readonly AverageEarningDerivationService $averageDerivation,
        private readonly LeaveEntitlementCalculator $leaveCalculator,
        private readonly AutomaticLeaveEntitlementService $automaticLeaveEntitlements,
        private readonly SicknessCompensationCalculator $sicknessCalculator,
        private readonly PayrollSicknessInputMaterializer $sicknessInputs,
        private readonly PayrollLeaveInputMaterializer $leaveInputs,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollAverageEarningDeletionRepository $averageDeletion,
        private readonly PayrollLeaveLedgerDeletionRepository $ledgerDeletion,
        private readonly PayrollLeaveEntitlementDeletionRepository $entitlementDeletion,
        private readonly IpMatcher $ipMatcher,
        private readonly AverageEarningBatchService $averageBatch,
        private readonly PayrollObstacleInputMaterializer $obstacleInputs,
        private readonly SicknessCaseFromAbsenceService $sicknessCases,
        private readonly PayrollAbsenceApprovalWarnings $approvalWarnings,
    ) {}

    public function context(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        return Json::ok($response, [
            'employments' => $this->absences->employments($this->currentSupplierId($request)),
            'support_status' => 'manual_review',
            'obstacle_kinds' => PayrollObstacleKind::catalog(),
        ]);
    }

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ, true)) !== null) {
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
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE, true)) !== null) {
            return $error;
        }
        try {
            $absence = $this->absences->create(
                $this->currentSupplierId($request),
                $this->validator->absence($this->body($request)),
                $this->userId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
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
        $decision = '';
        try {
            $version = $this->requiredNonNegativeInt($body['row_version'] ?? null, 'row_version');
            $decision = (string) ($body['decision'] ?? '');
            $absence = $this->absences->find($supplierId, $id)
                ?? throw new \InvalidArgumentException('Absence nebyla nalezena.');
            $calculation = null;
            $sicknessCase = null;
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                /*
                 * Průměr se kontroluje TADY, ne při ukládání absence. Zápis
                 * nepřítomnosti je evidence, schválení je peníze — teprve z něj
                 * vzniká mzdový vstup. Hláška proto říká, co chybí a kde se to
                 * doplní; dřív se tahle podmínka tvářila jako chyba formuláře
                 * a absenci nešlo vůbec uložit.
                 */
                // Neschopnost si průměr hlídá sama níž: navazující část ho bere z první
                // části, DPN bez nároku a část za vyčerpaným oknem ho nepotřebují.
                if ($decision === 'approved'
                    && in_array(
                        $absence['absence_type'],
                        PayrollAbsenceValidator::TYPES_REQUIRING_AVERAGE,
                        true,
                    )
                    && !PayrollAbsenceRepository::isSickness($absence)
                    && $absence['average_snapshot_id'] === null
                ) {
                    throw self::missingAverage($absence);
                }
                /*
                 * Placená překážka se bez druhu neschválí: jen druh říká, jaká
                 * náhrada přísluší a do které kolonky hlášení patří. Překážky
                 * zapsané před zavedením druhu (migrace 1927) se proto musí
                 * zapsat znovu — jinak by se schválením mzda zkrátila a náhrada
                 * nevznikla, přesně ta chyba, kterou druh odstraňuje.
                 */
                if ($decision === 'approved'
                    && PayrollObstacleKind::isObstacleType($absence['absence_type'])
                    && $absence['obstacle_kind'] === null
                ) {
                    throw new \InvalidArgumentException(
                        'U překážky v práci chybí druh (lékař, svatba, prostoj…), podle kterého se '
                        . 'určí náhrada mzdy. Nepřítomnost zrušte a zapište znovu s vybraným druhem.',
                    );
                }
                if ($decision === 'approved' && $absence['absence_type'] === 'vacation') {
                    // § 219 odst. 1 ZP — svátek uvnitř dovolené se nečerpá.
                    $segments = $this->absences->publishedShiftSegments(
                        $absence,
                        false,
                        AbsenceHolidayTreatment::ExcludeFromLeave,
                    );
                    $minutes = array_sum(array_column($segments, 'eligible_minutes'));
                    $calculation = $this->leave->recordTaken(
                        $absence,
                        $minutes,
                        $this->userId($request),
                        $this->boolean($body['overdraw_confirmed'] ?? false),
                    );
                }
                $sicknessEligible = null;
                if ($decision === 'approved' && PayrollAbsenceRepository::isSickness($absence)) {
                    [$absence, $version, $calculation, $sicknessEligible] = $this->approveSickness(
                        $supplierId,
                        $absence,
                        $version,
                        $body,
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
                // Mzdový vstup vzniká jen z kladné náhrady: DPN bez nároku, část za
                // vyčerpaným oknem ani náhrada snížená na nulu do mzdy nic nepřidávají.
                if ($decision === 'approved'
                    && $sicknessEligible === true
                    && is_array($calculation)
                    && (int) $calculation['compensation_minor'] > 0
                ) {
                    $this->sicknessInputs->materialize(
                        $supplierId,
                        (int) $calculation['id'],
                        $this->userId($request),
                    );
                }
                // § 222 odst. 1 ZP — kniha dovolené odepsala hodiny, tohle je
                // jejich peněžní půlka. Bez ní by se ze základní mzdy odečetly
                // hodiny, které by nic nenahradilo.
                if ($decision === 'approved' && $absence['absence_type'] === 'vacation') {
                    $this->leaveInputs->materialize(
                        $supplierId,
                        $absence,
                        $this->userId($request),
                    );
                }
                // § 199, § 207 až § 209 ZP — krácení mzdy dobu překážky ze
                // mzdy vyjme (titul PaidObstacle), tohle je náhrada za ni.
                if ($decision === 'approved' && PayrollObstacleKind::isObstacleType($absence['absence_type'])) {
                    $calculation = $this->obstacleInputs->materialize(
                        $supplierId,
                        $absence,
                        $this->userId($request),
                    );
                }
                // § 97 zák. č. 187/2006 Sb.: lhůta NEMPRI běží od události, ne
                // od toho, kdy si jí někdo všimne. Schválená absence, ze které
                // plyne dávka, proto rovnou založí (nebo prodlouží) případ.
                // DPN bez nároku dávku nezakládá, případ NEMPRI proto nevzniká.
                if ($decision === 'approved' && $sicknessEligible !== false) {
                    $sicknessCase = $this->sicknessCases->onApproved(
                        $supplierId,
                        $absence,
                        $this->userId($request),
                    );
                }
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (PayrollLeaveOverdrawException $e) {
            return Json::error($response, 'leave_overdraw_confirmation_required', $e->getMessage(), 409, [
                'balance_minutes' => $e->balanceMinutes,
                'requested_minutes' => $e->requestedMinutes,
            ]);
        } catch (PayrollAbsenceShiftsMissingException $e) {
            return Json::error($response, 'absence_shifts_missing', $e->getMessage(), 422, [
                'employment_id' => $e->employmentId,
                'period' => $e->period(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, [
            'absence' => $absence,
            'calculation' => $calculation,
            'sickness_case' => $sicknessCase,
            'warnings' => $decision === 'approved'
                ? $this->approvalWarnings->forApproved($supplierId, $absence)
                : [],
        ]);
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
            // Navazující část má okno § 192 ZP spočítané až za touto. Zrušit tuhle pod
            // ní by jí nechalo okno zkrácené o dny, které už neexistují.
            $successor = $before['status'] === 'approved'
                ? $this->absences->approvedSicknessSuccessor($supplierId, $before)
                : null;
            if ($successor !== null) {
                throw new \InvalidArgumentException(sprintf(
                    'Na tuto neschopnost navazuje schválená část %s – %s, jejíž náhrada mzdy počítá '
                    . 's okny obou částí. Zrušte nejdřív navazující část, potom tuto.',
                    $successor['date_from'],
                    $successor['date_to'],
                ));
            }
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                if ($before['status'] === 'approved' && $before['absence_type'] === 'vacation') {
                    $this->leave->reverseTaken($before, $this->userId($request));
                    $this->leaveInputs->reverseForAbsence(
                        $supplierId,
                        $id,
                        $this->userId($request),
                    );
                }
                $absence = $this->absences->cancel(
                    $supplierId,
                    $id,
                    $version,
                    $this->userId($request),
                );
                if (in_array($before['absence_type'], ['dpn', 'quarantine'], true)
                    && $before['status'] === 'approved'
                ) {
                    $this->sicknessInputs->reverseForAbsence(
                        $supplierId,
                        $id,
                        $this->userId($request),
                    );
                }
                if (PayrollObstacleKind::isObstacleType($before['absence_type'])
                    && $before['status'] === 'approved'
                ) {
                    $this->obstacleInputs->reverseForAbsence(
                        $supplierId,
                        $id,
                        $this->userId($request),
                    );
                }
                $sicknessCase = $before['status'] === 'approved'
                    ? $this->sicknessCases->onCancelled($supplierId, $id)
                    : null;
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, ['absence' => $absence, 'sickness_case' => $sicknessCase]);
    }

    /**
     * Výpočet náhrady mzdy při schválení DPN nebo karantény (§ 192 ZP).
     *
     * - Navazující část téže neschopnosti nedostane nové okno (DPN-01): vyčerpané dny
     *   určí řetěz schválených předchozích částí ({@see PayrollAbsenceRepository::sicknessChain()})
     *   a zapíšou se k části, takže z nich čte krácení mzdy i hlášení. Pravidla
     *   (redukční hranice, sazba) jsou z první části; průměr z první části platí do konce
     *   jejího čtvrtletí, dny okna v dalším čtvrtletí berou jeho průměr (§ 354 odst. 1 ZP).
     * - DPN bez nároku na nemocenské (§ 15a zák. č. 187/2006 Sb., DPN-02) se schválí
     *   s nulovou náhradou: mzda se krátí, mzdový vstup ani případ NEMPRI nevzniká.
     * - Snížení náhrady podle § 192 odst. 4 a 5 ZP (DPN-03) jde do výpočtu i do důkazu.
     *
     * @param array<string,mixed> $absence
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int,2:array<string,mixed>,3:bool}
     */
    private function approveSickness(
        int $supplierId,
        array $absence,
        int $version,
        array $body,
        ?int $userId,
    ): array {
        $firstWorked = $this->boolean($body['first_day_fully_worked'] ?? false);
        $eligible = $this->sicknessEligibility($body);
        $noConflict = $this->boolean($body['conflicting_benefit_excluded'] ?? false);
        if ($eligible && !$noConflict) {
            throw new \InvalidArgumentException(
                'Potvrďte, že za dny neschopnosti nenáleží souběžná dávka (vyloučení souběhu). '
                . 'Bez toho se náhrada mzdy nespočítá.',
            );
        }
        $reduction = $this->validator->sicknessReduction($body);
        if (!$eligible && !$reduction->isNone()) {
            throw new \InvalidArgumentException(
                'DPN bez nároku na nemocenské nemá náhradu mzdy, kterou by šlo snížit.',
            );
        }

        $chain = $this->absences->sicknessChain($supplierId, $absence);
        if ($chain['pending_predecessor'] !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Tahle neschopnost navazuje na část %s – %s, o které se ještě nerozhodlo. '
                . 'Schvalte nejdřív ji: okno náhrady mzdy (§ 192 ZP) běží od začátku celé neschopnosti.',
                $chain['pending_predecessor']['date_from'],
                $chain['pending_predecessor']['date_to'],
            ));
        }
        $successor = $this->absences->approvedSicknessSuccessor($supplierId, $absence);
        if ($successor !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Na tuto neschopnost už navazuje schválená část %s – %s, jejíž náhrada mzdy se '
                . 'počítala bez ní. Zrušte navazující část, schvalte tuto a navazující zapište znovu.',
                $successor['date_from'],
                $successor['date_to'],
            ));
        }

        $rulesDate = (string) $absence['date_from'];
        $averageSource = $absence;
        $start = $chain['chain_start'];
        if ($start !== null) {
            if ($firstWorked) {
                throw new \InvalidArgumentException(sprintf(
                    'Odpracovaný první den lze potvrdit jen u první části neschopnosti (od %s). '
                    . 'Tahle část na ni navazuje.',
                    $start['date_from'],
                ));
            }
            $startEvent = $chain['chain_start_event'];
            if ($startEvent !== null && $startEvent['insurance_eligibility_confirmed'] !== $eligible) {
                throw new \InvalidArgumentException(sprintf(
                    'Navazující část neschopnosti musí mít stejný nárok na náhradu jako její první část '
                    . '(od %s), ta byla schválena %s.',
                    $start['date_from'],
                    $startEvent['insurance_eligibility_confirmed'] ? 's nárokem' : 'bez nároku',
                ));
            }
            if ($chain['carried_days'] > PayrollAbsenceRepository::carriedWindowDays($absence)) {
                $absence = $this->absences->setSicknessWindowCarriedDays(
                    $supplierId,
                    (int) $absence['id'],
                    $chain['carried_days'],
                    $version,
                );
                $version = (int) $absence['row_version'];
            }
            $rulesDate = (string) $start['date_from'];
            if ($start['average_snapshot_id'] !== null) {
                $averageSource = $start;
            }
        }
        $absence['average_snapshot_id'] = $averageSource['average_snapshot_id'];

        if (!$eligible) {
            $result = $this->sicknessCalculator->withoutCompensation($rulesDate, 'not_eligible');
        } else {
            // § 192 odst. 1 ZP — za svátek v okně náhrada náleží i bez směny.
            $segments = $this->absences->publishedShiftSegments(
                $absence,
                $firstWorked,
                AbsenceHolidayTreatment::CompensateSickness,
            );
            if ($segments === []) {
                $bounds = $this->absences->absenceBounds($absence, $firstWorked);
                $windowEmpty = $bounds['window_to'] < $bounds['from'];
                if (!$windowEmpty
                    && $this->absences->publishedShiftSegmentsBeyondSicknessWindow($absence, $firstWorked) === []
                ) {
                    throw new PayrollAbsenceShiftsMissingException(
                        (int) $absence['employment_id'],
                        (string) $absence['date_from'],
                        (string) $absence['date_to'],
                    );
                }
                if (!$reduction->isNone()) {
                    throw new \InvalidArgumentException(
                        'V okně náhrady mzdy (§ 192 ZP) tahle část nemá žádnou zameškanou směnu, '
                        . 'náhrada je nulová a není co snížit.',
                    );
                }
                $result = $this->sicknessCalculator->withoutCompensation(
                    $rulesDate,
                    $windowEmpty ? 'window_exhausted' : 'no_shift_in_window',
                );
            } else {
                if ($averageSource['average_hourly_minor'] === null) {
                    throw self::missingAverage($averageSource);
                }
                $segments = $this->withQuarterAverages($supplierId, $absence, (string) $averageSource['date_from'], $segments);
                $result = $this->sicknessCalculator->calculate(
                    $rulesDate,
                    (int) $averageSource['average_hourly_minor'],
                    $segments,
                    $reduction,
                );
            }
        }
        $calculation = $this->sickness->record(
            $absence,
            $firstWorked,
            $eligible,
            $noConflict,
            $result,
            $userId,
        );

        return [$absence, $version, $calculation, $eligible];
    }

    /**
     * Nárok na náhradu mzdy při DPN: `confirmed` (účast na pojištění potvrzena), nebo
     * `not_eligible` (DPN bez nároku, § 15a zák. č. 187/2006 Sb.). Starší klient posílá
     * jen `insurance_eligibility_confirmed`; jeho `false` NENÍ volba „bez nároku" —
     * nezaškrtnuté políčko by jinak tiše schválilo neschopnost bez náhrady.
     *
     * @param array<string,mixed> $body
     */
    private function sicknessEligibility(array $body): bool
    {
        $value = $body['insurance_eligibility'] ?? null;
        if ($value === 'confirmed') {
            return true;
        }
        if ($value === 'not_eligible') {
            return false;
        }
        if ($value !== null && $value !== '') {
            throw new \InvalidArgumentException('Nárok na náhradu mzdy musí být „confirmed“, nebo „not_eligible“.');
        }
        if ($this->boolean($body['insurance_eligibility_confirmed'] ?? false)) {
            return true;
        }

        throw new \InvalidArgumentException(
            'Potvrďte účast na nemocenském pojištění, nebo zvolte, že zaměstnanec nárok na náhradu '
            . 'nemá (DPN bez nároku, § 15a zák. č. 187/2006 Sb.).',
        );
    }

    /**
     * Směny okna § 192 ZP v dalším kalendářním čtvrtletí než začátek neschopnosti dostanou
     * průměr toho čtvrtletí: průměrný výdělek se zjišťuje k prvnímu dni čtvrtletí a platí
     * po celé čtvrtletí (§ 351 a § 354 odst. 1 ZP). Redukční hranice a sazba zůstávají
     * z prvního dne neschopnosti.
     *
     * Průměr dalšího čtvrtletí vzniká až ze mzdy posledního měsíce toho předchozího,
     * takže neschopnost schválená před jeho schválením by na něj čekala. Dokud schválený
     * není, zůstává průměr čtvrtletí, kdy neschopnost začala.
     *
     * @param array<string,mixed> $absence
     * @param list<array<string,mixed>> $segments
     * @return list<array<string,mixed>>
     */
    private function withQuarterAverages(int $supplierId, array $absence, string $startDate, array $segments): array
    {
        $startKey = substr($startDate, 0, 4) . '-' . (intdiv((int) substr($startDate, 5, 2) - 1, 3) + 1);
        $byQuarter = [];
        foreach ($segments as $i => $segment) {
            $date = (string) $segment['local_date'];
            $year = (int) substr($date, 0, 4);
            $quarter = intdiv((int) substr($date, 5, 2) - 1, 3) + 1;
            if ("{$year}-{$quarter}" === $startKey) {
                continue;
            }
            if (!array_key_exists("{$year}-{$quarter}", $byQuarter)) {
                $average = $this->averages->findApproved($supplierId, (int) $absence['employment_id'], $year, $quarter);
                $byQuarter["{$year}-{$quarter}"] = $average !== null && ($average['support_status'] ?? null) === 'supported'
                    && is_int($average['average_hourly_minor'] ?? null) && $average['average_hourly_minor'] > 0
                    ? $average['average_hourly_minor']
                    : null;
            }
            if ($byQuarter["{$year}-{$quarter}"] !== null) {
                $segments[$i]['average_hourly_minor'] = $byQuarter["{$year}-{$quarter}"];
            }
        }

        return $segments;
    }

    /** @param array<string,mixed> $absence */
    private static function missingAverage(array $absence): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'Schválení téhle nepřítomnosti počítá náhradu z průměrného '
            . 'výdělku — doplňte schválený průměr za čtvrtletí, do kterého '
            . 'spadá ' . $absence['date_from'] . ' (záložka Průměry). '
            . 'Absence zůstává uložená, nic se neztratí.',
        );
    }

    /**
     * Doplní den porodu k peněžité pomoci v mateřství, i ke schválené.
     *
     * Pravidla (jednou a dost, uzávěrka roku, verze záznamu) drží
     * {@see PayrollAbsenceRepository::recordChildbirth()}.
     *
     * @param array<string,string> $args
     */
    public function childbirth(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = $this->body($request);
        try {
            $version = $this->requiredNonNegativeInt($body['row_version'] ?? null, 'row_version');
            $absence = $this->absences->find($supplierId, $id)
                ?? throw new \InvalidArgumentException('Absence nebyla nalezena.');
            $absence = $this->absences->recordChildbirth(
                $supplierId,
                $id,
                $version,
                $this->validator->childbirthDate($absence, $body['childbirth_date'] ?? null),
                $this->userId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollAbsenceConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }
        return Json::ok($response, ['absence' => $absence]);
    }

    /**
     * Zápis dnů okna náhrady mzdy (§ 192 ZP) vyčerpaných u DPN/karantény ještě
     * PŘED touhle nepřítomností — u předchozího zaměstnavatele nebo předchozího
     * mzdového programu. Pravidla (jen DPN/karanténa, otevřený rok, absence bez
     * spočítané náhrady) drží {@see PayrollAbsenceRepository::setSicknessWindowCarriedDays()}.
     *
     * @param array<string,string> $args
     */
    public function sicknessWindowCarried(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = $this->body($request);
        try {
            $version = $this->requiredNonNegativeInt($body['row_version'] ?? null, 'row_version');
            $days = $this->requiredNonNegativeInt(
                $body['sickness_window_carried_days'] ?? null,
                'sickness_window_carried_days',
            );
            $absence = $this->absences->setSicknessWindowCarriedDays($supplierId, $id, $days, $version);
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (\DomainException|\InvalidArgumentException $e) {
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
        if (($error = $this->authorize($request, $response, AccessLevel::READ, true)) !== null) {
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

    /**
     * Návrh vstupů průměru odvozený z uzavřených mzdových běhů.
     *
     * Jen ČTE — nic neukládá a schválení nenahrazuje. Formulář navržená čísla
     * předvyplní, účetní je smí přepsat a teprve její odeslání průměr založí.
     * Odpověď je proto pod právem `READ`, ne `WRITE`.
     */
    public function averageSuggestion(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ, true)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $suggestion = $this->averageDerivation->suggest(
                $this->currentSupplierId($request),
                $this->requiredPositiveInt($query['employment_id'] ?? null, 'employment_id'),
                $this->requiredPositiveInt($query['applicable_year'] ?? null, 'applicable_year'),
                $this->requiredPositiveInt($query['applicable_quarter'] ?? null, 'applicable_quarter'),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['suggestion' => $suggestion]);
    }

    public function createAverage(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->average($this->body($request));
            $mismatch = $this->averages->quarterMismatch(
                $this->currentSupplierId($request),
                $data['employment_id'],
                $data['applicable_year'],
                $data['applicable_quarter'],
            );
            if ($mismatch !== null) {
                throw new \InvalidArgumentException($mismatch);
            }
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
                // Kratší STANOVENÁ týdenní pracovní doba (§ 79 odst. 2 a 3 ZP)
                // hodinovou minimální mzdu zvyšuje, kratší SJEDNANÁ pracovní doba
                // (§ 80 ZP, částečný úvazek) ne. `payroll_employment_terms.weekly_hours`
                // drží jen jedno číslo a obojí nerozliší, takže se z něj nedovozuje:
                // u dvacetihodinového úvazku by přepočet zdvojnásobil minimum.
                // Dokud vztah stanovenou dobu neeviduje, platí sazba pro 40 hodin.
                null,
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
            // Hranice, před kterou smí vzniknout ručně zapsané čerpání. Bez ní
            // by formulář nabízel typ, který server odmítne, a uživatel by se
            // o omezení dozvěděl až z chyby.
            'payroll_start_period' => $this->leave->payrollStartPeriod($supplierId),
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
                // § 223 odst. 1 ZP krátí dovolenou o neomluveně zameškané hodiny —
                // vazba na absenci, kvůli které se krátí, je proto doklad položky.
                $this->optionalPositiveInt(
                    $body['source_absence_id'] ?? null,
                    'source_absence_id',
                ),
                // Převzaté čerpání (`taken`) se opisuje z výstupu předchozího
                // mzdového programu — doklad o původu nese tenhle údaj.
                trim((string) ($body['source_reference'] ?? '')) ?: null,
            );
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
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
            $decisions = $body['absence_decisions'] ?? [];
            if (!is_array($decisions) || ($decisions !== [] && array_is_list($decisions))) {
                throw new \InvalidArgumentException('Posouzení absencí musí být mapa druh absence => započítat / nezapočítat.');
            }
            $entitlements = $this->automaticLeaveEntitlements->calculateBatch(
                $this->currentSupplierId($request),
                $year,
                $through,
                $items,
                $this->userId($request),
                $decisions,
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
     * Návrhy průměrů za čtvrtletí pro všechny vztahy firmy (stránkovaně).
     *
     * Jen ČTE — stejně jako návrh pro jeden vztah. Založení a schválení je
     * {@see createAutomaticAverages()}.
     */
    public function averageCandidates(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            $query = $request->getQueryParams();
            $page = $this->averageBatch->page(
                $this->currentSupplierId($request),
                $this->requiredPositiveInt($query['year'] ?? null, 'year'),
                $this->requiredPositiveInt($query['quarter'] ?? null, 'quarter'),
                max(1, min(
                    AverageEarningBatchService::MAX_LIMIT,
                    (int) ($query['limit'] ?? AverageEarningBatchService::DEFAULT_LIMIT),
                )),
                max(0, (int) ($query['offset'] ?? 0)),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, $page);
    }

    /** Založí a schválí vybrané návrhy průměrů ({@see AverageEarningBatchService}). */
    public function createAutomaticAverages(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $items = $body['items'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new \InvalidArgumentException('Výběr pracovních vztahů není platný.');
            }
            $averages = $this->averageBatch->createBatch(
                $this->currentSupplierId($request),
                $this->requiredPositiveInt($body['year'] ?? null, 'year'),
                $this->requiredPositiveInt($body['quarter'] ?? null, 'quarter'),
                $items,
                $this->userId($request),
            );
        } catch (AverageEarningBatchConflictException $exception) {
            return Json::error(
                $response,
                'average_inputs_changed',
                $exception->getMessage(),
                409,
                ['employment_id' => $exception->employmentId],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, ['averages' => $averages], 201);
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

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
        bool $allowBearer = false,
    ): ?Response
    {
        if (!$allowBearer && !RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Mzdové absence jsou dostupné pouze z přihlášené relace.');
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
            'sickness_window_carried_days' => 'Dny okna náhrady vyčerpané předchozím plátcem',
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
