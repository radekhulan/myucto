<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzDeferralRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationChangeSettlement;
use Psr\Clock\ClockInterface;

/**
 * Odložení pracovního vztahu z řádného měsíčního hlášení.
 *
 * Když jeden zaměstnanec nemá úplná data, nesmí kvůli němu zůstat nepodané
 * hlášení za ostatní. Účetní u blokovaného vztahu rozhodne „odložit": řádné
 * hlášení se podá bez jeho formuláře a formulář se po doplnění dat pošle
 * opravným hlášením. Do té doby je to NESPLNĚNÁ POVINNOST - lhůta je stejná
 * jako u řádného hlášení (20. den následujícího měsíce) a ČSSZ na chybějící
 * součást vyzve (kontrola 226: počet součástí proti registru).
 *
 * Pravidla, na kterých odložení stojí:
 *
 *  1. Odložit lze jen vztah, který v přípravě NÁLEZ MÁ. Zdravý vztah se
 *     podává v řádném hlášení.
 *  2. Odkládá se vždy celá osoba v rámci registrace. Pojistné osoby i souhrnná
 *     data zaměstnance nese jediný formulář, takže vynechat jeden ze
 *     souběžných vztahů by změnilo, co vykazují ostatní (souběh).
 *  3. Firemní nález (účtárna, variabilní symbol, pojistná část, souhrn)
 *     odložit nejde - bez něj hlášení nesestavíme ani za ostatní.
 *  4. Po zmrazení řádného hlášení se odložení nemění. Co hlášení vynechalo,
 *     doplní opravné.
 *  5. Rozhodnutí je vázané na revizi běhu a přípravu, nad kterou vzniklo; nová
 *     revize (přepočet) znamená nová data. Odložení, které řádné hlášení ještě
 *     nevynechalo, lze převzít do nové revize, pokud jeho důvod trvá.
 */
final readonly class JmhzDeferralService
{
    private const REGULAR_DONE = ['accepted', 'partially_accepted'];

    /** Důvod odložení: čerstvě přijatá změna údajů zaměstnance (48 h). */
    public const SETTLING_CODE = 'jmhz_registration_change_settling';

    /** Stav odložení pro UI; páruje ho `PayrollEnumContractTest`. */
    public const STATES = ['pending', 'omitted', 'to_complete', 'completing', 'completed', 'revoked', 'stale'];

    public function __construct(
        private JmhzDeferralRepository $repository,
        private JmhzPreparationSnapshotService $preparations,
        private JmhzScenario1DocumentService $documents,
        private JmhzContentCorrectionSubmissionService $corrections,
        private PayrollPeopleRepository $people,
        private JmhzDeadlinePolicy $deadlines,
        private ClockInterface $clock,
        private ?PayrollRegistrationChangeSettlement $settlement = null,
    ) {}

    /**
     * Odložení za mzdový běh revize - aktivní i odvolaná, s vazbou na podání.
     *
     * @return array<string,mixed>
     */
    public function listForRevision(int $supplierId, int $revisionId, string $environment): array
    {
        self::assertEnvironment($environment);
        $scope = $this->repository->revisionScope($supplierId, $revisionId);
        if ($scope === null) {
            throw new \DomainException('Mzdová revize nebyla nalezena.');
        }
        $rows = $this->repository->forRun($supplierId, $scope['run_id'], $scope['period_start']);
        $bindings = [];
        foreach ($this->repository->bindings(
            $supplierId,
            array_map(static fn (array $row): int => $row['id'], $rows),
        ) as $binding) {
            $bindings[$binding['deferral_id']][] = $binding;
        }
        $names = $this->people->namesForTenant(
            $supplierId,
            array_values(array_unique(array_map(
                static fn (array $row): int => $row['employee_id'],
                $rows,
            ))),
        );
        $window = $this->deadlines->forPeriod($scope['period_start']);
        $today = $this->today();
        $currentRevisionId = $this->repository->currentApprovedRevisionId($supplierId, $scope['run_id']);

        $items = [];
        foreach ($rows as $row) {
            $own = array_values(array_filter(
                $bindings[$row['id']] ?? [],
                static fn (array $binding): bool => $binding['environment'] === $environment,
            ));
            $items[] = $this->item($row, $own, $names, $currentRevisionId);
        }
        $open = array_filter(
            $items,
            static fn (array $item): bool => !in_array($item['state'], ['revoked', 'completed', 'stale'], true),
        );

        $currentEmployments = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'active' && $row['source_revision_id'] === $currentRevisionId) {
                $currentEmployments[$row['employment_id']] = true;
            }
        }
        $completable = count(array_filter(
            $items,
            static fn (array $item): bool => $item['state'] === 'to_complete',
        ));

        return [
            'environment' => $environment,
            'run_id' => $scope['run_id'],
            'carry_over_count' => count(array_filter(
                $items,
                static fn (array $item): bool => $item['state'] === 'stale' && $item['status'] === 'active'
                    && $item['regular_submission_id'] === null
                    && !isset($currentEmployments[$item['employment_id']]),
            )),
            'completable_count' => $completable,
            'period_start' => $scope['period_start'],
            'due_on' => $window->dueOn,
            'overdue' => $open !== [] && $today > $window->dueOn,
            'open_count' => count($open),
            'deferrals' => $items,
        ];
    }

    /**
     * Odloží vztah (a s ním všechny vztahy téže osoby v registraci).
     *
     * Opakované volání nad týmž vztahem vrátí existující odložení.
     *
     * @return array<string,mixed>
     */
    public function defer(
        int $supplierId,
        string $environment,
        int $preparationId,
        int $employmentId,
        string $reason,
        int $userId,
        ?int $officeId = null,
    ): array {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah a uživatel musí být kladná čísla.');
        }
        $result = $this->deferMany(
            $supplierId,
            $environment,
            $preparationId,
            [$employmentId],
            $reason,
            $userId,
            $officeId,
        );
        $person = $result['people'][0];

        return [
            'created' => $result['created'],
            'deferral_ids' => $result['deferral_ids'],
            'employee_id' => $person['employee_id'],
            'employment_ids' => $person['employment_ids'],
            'office_id' => $person['office_id'],
            'source_revision_id' => $result['source_revision_id'],
            'blocker_codes' => $person['blocker_codes'],
        ];
    }

    /**
     * Odloží jedním rozhodnutím všechny dotčené osoby přípravy: každou osobu,
     * jejíž vztah nese nález, kvůli kterému lze odložit (`$employmentIds`
     * = `null`), nebo jen osoby zadaných vztahů. Jeden důvod platí pro všechny.
     *
     * Nález firemní (účtárna, pojistná část) se tím neodstraní; řádné hlášení
     * pak dál zastaví, ale odložení osob zůstane.
     *
     * @param list<int>|null $employmentIds
     * @return array{
     *   created:bool,deferral_ids:list<int>,source_revision_id:int,
     *   employment_ids:list<int>,people:list<array{employee_id:int,office_id:?int,employment_ids:list<int>,blocker_codes:list<string>}>,
     *   firm_blocker_count:int
     * }
     */
    public function deferMany(
        int $supplierId,
        string $environment,
        int $preparationId,
        ?array $employmentIds,
        string $reason,
        int $userId,
        ?int $officeId = null,
    ): array {
        self::assertEnvironment($environment);
        $reason = self::reason($reason);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah a uživatel musí být kladná čísla.');
        }
        if ($employmentIds !== null) {
            foreach ($employmentIds as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new \InvalidArgumentException('Pracovní vztahy musí být kladná čísla.');
                }
            }
        }
        $preparation = $this->preparations->loadVerified($supplierId, $environment, $preparationId);
        $index = self::employmentIndex($preparation);
        if ($employmentIds !== null) {
            foreach ($employmentIds as $id) {
                if (!isset($index[$id])) {
                    throw new JmhzXmlException(
                        'jmhz_deferral_employment_unknown',
                        'Pracovní vztah v přípravě hlášení není.',
                    );
                }
            }
        }
        $resolution = $this->documents->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            JmhzFormExclusion::correctionScope([]),
        );
        $codes = [];
        $firmBlockers = 0;
        foreach ($resolution->blockers as $blocker) {
            if (!$blocker->deferrable()) {
                $firmBlockers++;
                continue;
            }
            foreach ($index as $employmentId => $row) {
                if (($blocker->entityType === 'employment' && $blocker->entityId === $employmentId)
                    || (in_array($blocker->entityType, ['person', 'employee'], true)
                        && $blocker->entityId === $row['employee_id'])
                ) {
                    $codes[self::clusterKey($row)][$blocker->code] = true;
                }
            }
        }
        foreach ($this->settlingEmployments($supplierId, $environment, array_keys($index)) as $employmentId) {
            if (isset($index[$employmentId])) {
                $codes[self::clusterKey($index[$employmentId])][self::SETTLING_CODE] = true;
            }
        }
        $clusters = [];
        foreach ($index as $employmentId => $row) {
            if ($officeId !== null && $row['office_id'] !== $officeId) {
                continue;
            }
            $key = self::clusterKey($row);
            $clusters[$key] ??= [
                'employee_id' => $row['employee_id'],
                'office_id' => $row['office_id'],
                'employment_ids' => [],
                'requested' => $employmentIds === null,
            ];
            $clusters[$key]['employment_ids'][] = $employmentId;
            if ($employmentIds !== null && in_array($employmentId, $employmentIds, true)) {
                $clusters[$key]['requested'] = true;
            }
        }
        $selected = [];
        foreach ($clusters as $key => $cluster) {
            if (!$cluster['requested'] || !isset($codes[$key])) {
                continue;
            }
            $clusterCodes = array_keys($codes[$key]);
            sort($clusterCodes, SORT_STRING);
            sort($cluster['employment_ids'], SORT_NUMERIC);
            $selected[] = [
                'employee_id' => $cluster['employee_id'],
                'office_id' => $cluster['office_id'],
                'employment_ids' => $cluster['employment_ids'],
                'blocker_codes' => $clusterCodes,
            ];
        }
        if ($selected === []) {
            throw new JmhzXmlException(
                'jmhz_deferral_not_blocked',
                $employmentIds !== null && count($employmentIds) === 1
                    ? 'Vztah v přípravě nemá žádný nález, který by bránil podání - podá se'
                        . ' v řádném hlášení a odkládat ho není důvod.'
                    : 'V přípravě není žádný vztah s nálezem, který by šlo odložit. Zbývající'
                        . ' nálezy jsou firemní a opravují se v nastavení mezd.',
            );
        }
        $officeEmployments = [];
        foreach ($index as $employmentId => $row) {
            $officeEmployments[(string) $row['office_id']][] = $employmentId;
        }

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $preparation,
            $selected,
            $officeEmployments,
            $reason,
            $userId,
            $firmBlockers,
        ): array {
            $byOffice = [];
            foreach ($selected as $cluster) {
                $byOffice[(string) $cluster['office_id']][] = $cluster;
            }
            $active = $this->repository->activeForRevision($supplierId, $preparation->sourceRevisionId, true);
            $created = [];
            $deferred = [];
            foreach ($byOffice as $officeKey => $clusters) {
                $office = $officeKey === '' ? null : (int) $officeKey;
                $live = $this->repository->liveRegularSubmission(
                    $supplierId,
                    'production',
                    $preparation->runId,
                    $office,
                    $preparation->periodStart,
                );
                if ($live !== null) {
                    throw new JmhzXmlException(
                        'jmhz_deferral_regular_frozen',
                        'Řádné hlášení za toto období je už zmrazené, odložení se do něj'
                            . ' nepromítne. Vztah doplňte opravným hlášením.',
                    );
                }
                $activeEmployments = [];
                foreach ($active as $row) {
                    if ($row['office_id'] === $office) {
                        $activeEmployments[$row['employment_id']] = $row;
                    }
                }
                $remaining = array_diff(
                    $officeEmployments[$officeKey] ?? [],
                    array_keys($activeEmployments),
                    ...array_map(static fn (array $cluster): array => $cluster['employment_ids'], $clusters),
                );
                if ($remaining === []) {
                    throw new JmhzXmlException(
                        'jmhz_deferral_no_form_left',
                        'Odložením by v hlášení za registraci nezůstal žádný formulář.'
                            . ' Hlášení podejte až po doplnění dat.',
                    );
                }
                foreach ($clusters as $cluster) {
                    foreach ($cluster['employment_ids'] as $employmentId) {
                        if (isset($activeEmployments[$employmentId])) {
                            continue;
                        }
                        $created[] = $this->repository->insert([
                            'supplier_id' => $supplierId,
                            'run_id' => $preparation->runId,
                            'source_revision_id' => $preparation->sourceRevisionId,
                            'period_start' => $preparation->periodStart,
                            'office_id' => $cluster['office_id'],
                            'employee_id' => $cluster['employee_id'],
                            'employment_id' => $employmentId,
                            'reason' => $reason,
                            'blocker_codes_json' => CanonicalJson::encode($cluster['blocker_codes']),
                            'decided_environment' => $environment,
                            'decided_preparation_id' => $preparation->id,
                            'decided_snapshot_fingerprint' => $preparation->snapshotFingerprint,
                            'created_by' => $userId,
                        ]);
                    }
                    $deferred[] = $cluster;
                }
            }
            $employmentIds = array_merge(...array_map(
                static fn (array $cluster): array => $cluster['employment_ids'],
                $deferred,
            ));
            sort($employmentIds, SORT_NUMERIC);

            return [
                'created' => $created !== [],
                'deferral_ids' => $created,
                'source_revision_id' => $preparation->sourceRevisionId,
                'employment_ids' => $employmentIds,
                'people' => $deferred,
                'firm_blocker_count' => $firmBlockers,
            ];
        });
    }

    /**
     * Zruší odložení (celé osoby v registraci). Po zmrazení řádného hlášení
     * nejde - hlášení vztah vynechalo a doplní ho jen opravné.
     *
     * @return array<string,mixed>
     */
    public function revoke(
        int $supplierId,
        int $deferralId,
        int $rowVersion,
        string $reason,
        int $userId,
    ): array {
        $reason = self::reason($reason);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Odložení může zrušit jen přihlášený uživatel.');
        }

        return $this->repository->transaction(function () use (
            $supplierId,
            $deferralId,
            $rowVersion,
            $reason,
            $userId,
        ): array {
            $deferral = $this->repository->find($supplierId, $deferralId, true);
            if ($deferral === null) {
                throw new \DomainException('Odložení nebylo nalezeno.');
            }
            if ($deferral['status'] !== 'active') {
                throw new JmhzXmlException(
                    'jmhz_deferral_not_active',
                    'Odložení už bylo zrušené.',
                );
            }
            if ($deferral['row_version'] !== $rowVersion) {
                throw new JmhzXmlException(
                    'jmhz_deferral_conflict',
                    'Odložení mezitím změnil někdo jiný. Načtěte přehled znovu.',
                );
            }
            $siblings = array_values(array_filter(
                $this->repository->activeForRevision($supplierId, $deferral['source_revision_id'], true),
                static fn (array $row): bool => $row['employee_id'] === $deferral['employee_id']
                    && $row['office_id'] === $deferral['office_id'],
            ));
            $bindings = $this->repository->bindings(
                $supplierId,
                array_map(static fn (array $row): int => $row['id'], $siblings),
            );
            foreach ($bindings as $binding) {
                if ($binding['environment'] === 'production'
                    && !in_array($binding['regular_status'], ['cancelled_in_time', 'superseded'], true)
                ) {
                    throw new JmhzXmlException(
                        'jmhz_deferral_regular_frozen',
                        'Řádné hlášení vztah už vynechalo; zrušit odložení nejde.'
                            . ' Vztah doplňte opravným hlášením.',
                    );
                }
            }
            $revoked = [];
            foreach ($siblings as $row) {
                if (!$this->repository->revoke(
                    $supplierId,
                    $row['id'],
                    $row['row_version'],
                    $userId,
                    $reason,
                )) {
                    throw new JmhzXmlException(
                        'jmhz_deferral_conflict',
                        'Odložení mezitím změnil někdo jiný. Načtěte přehled znovu.',
                    );
                }
                $revoked[] = $row['id'];
            }

            return [
                'revoked_ids' => $revoked,
                'employee_id' => $deferral['employee_id'],
                'source_revision_id' => $deferral['source_revision_id'],
            ];
        });
    }

    /**
     * Doplní formulář odloženého vztahu opravným hlášením.
     *
     * Nad aktuální schválenou revizí běhu se připraví hlášení znovu - data
     * vztahu už musí být doplněná - a z něj se zmrazí opravné hlášení nesoucí
     * formuláře odložených vztahů osoby. Ostatní vztahy s nálezem opravu
     * nezastaví (obsahová oprava posuzuje jen vybrané formuláře).
     *
     * @return array<string,mixed>
     */
    public function complete(
        int $supplierId,
        int $deferralId,
        string $environment,
        int $userId,
    ): array {
        self::assertEnvironment($environment);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Opravné hlášení může připravit jen přihlášený uživatel.');
        }
        $deferral = $this->repository->find($supplierId, $deferralId);
        if ($deferral === null) {
            throw new \DomainException('Odložení nebylo nalezeno.');
        }
        $binding = null;
        foreach ($this->repository->bindings($supplierId, [$deferralId]) as $row) {
            if ($row['environment'] === $environment) {
                $binding = $row;
            }
        }
        if ($binding === null) {
            throw new JmhzXmlException(
                'jmhz_deferral_regular_missing',
                'Řádné hlášení s odloženým vztahem ještě není zmrazené. Nejdřív podejte'
                    . ' řádné hlášení, pak vztah doplňte opravou.',
            );
        }
        if (!in_array($binding['regular_status'], self::REGULAR_DONE, true)) {
            throw new JmhzXmlException(
                'jmhz_deferral_regular_not_accepted',
                'Opravné hlášení lze navázat až na přijaté nebo částečně přijaté řádné'
                    . ' hlášení. Počkejte na protokol ČSSZ.',
            );
        }
        $revisionId = $this->repository->currentApprovedRevisionId($supplierId, $deferral['run_id']);
        if ($revisionId === null) {
            throw new JmhzXmlException(
                'jmhz_deferral_revision_missing',
                'Mzdový běh nemá schválenou revizi, ze které by šlo hlášení připravit.',
            );
        }
        $preparation = $this->preparations->freeze(
            $supplierId,
            $revisionId,
            $environment,
            'jmhz-deferral-complete:' . $deferralId . ':' . bin2hex(random_bytes(12)),
            $userId,
        );
        $preparationId = (int) $preparation['id'];
        $siblings = array_values(array_filter(
            $this->repository->bindingsForRegular(
                $supplierId,
                $environment,
                $binding['regular_submission_id'],
            ),
            static fn (array $row): bool => $row['employee_id'] === $deferral['employee_id']
                && !in_array($row['correction_status'], ['accepted', 'partially_accepted'], true),
        ));
        $employmentIds = array_map(static fn (array $row): int => $row['employment_id'], $siblings);
        if ($employmentIds === []) {
            $employmentIds = [$deferral['employment_id']];
        }
        $resolution = $this->documents->resolveForCorrection(
            $supplierId,
            $environment,
            $preparationId,
            $deferral['office_id'],
            [],
        );
        $identifiers = [];
        $people = $resolution->candidate?->payload['people'] ?? [];
        foreach (is_array($people) ? $people : [] as $person) {
            foreach ((is_array($person) ? ($person['employments'] ?? []) : []) as $employment) {
                if (is_array($employment)
                    && in_array($employment['employment_id'] ?? null, $employmentIds, true)
                    && is_string($employment['identity']['employment_external_identifier'] ?? null)
                ) {
                    $identifiers[] = $employment['identity']['employment_external_identifier'];
                }
            }
        }
        if ($resolution->status() !== 'resolved' || count($identifiers) !== count($employmentIds)) {
            $still = array_values(array_filter(
                [...$resolution->blockers, ...$resolution->excludedBlockers],
                static fn (JmhzScenario1Blocker $blocker): bool
                    => ($blocker->entityType === 'employment'
                        && in_array($blocker->entityId, $employmentIds, true))
                    || (in_array($blocker->entityType, ['person', 'employee'], true)
                        && $blocker->entityId === $deferral['employee_id'])
                    || !$blocker->deferrable(),
            ));
            throw new JmhzXmlException(
                'jmhz_deferral_still_blocked',
                'Odložený vztah zatím doplnit nejde: '
                    . JmhzBlockerExplainer::describeNamed($still, $this->people, $supplierId),
            );
        }

        $result = $this->corrections->freeze(
            $supplierId,
            $environment,
            $binding['regular_submission_id'],
            $preparationId,
            $identifiers,
            $userId,
            $deferral['office_id'],
        );

        return $result + [
            'preparation_id' => $preparationId,
            'completed_employment_ids' => $employmentIds,
        ];
    }

    /**
     * Doplní všechny odložené vztahy běhu, jejichž řádné hlášení ČSSZ přijala,
     * jedním opravným hlášením za každé řádné hlášení (registraci).
     *
     * Vztah, který zatím doplnit nejde (údaj stále chybí), opravu nezastaví:
     * zůstane odložený a vrátí se v `still_blocked_employment_ids`.
     *
     * @return array{submissions:list<array<string,mixed>>,completed_employment_ids:list<int>,still_blocked_employment_ids:list<int>,preparation_id:int}
     */
    public function completeAll(
        int $supplierId,
        int $revisionId,
        string $environment,
        int $userId,
    ): array {
        self::assertEnvironment($environment);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Opravné hlášení může připravit jen přihlášený uživatel.');
        }
        $scope = $this->repository->revisionScope($supplierId, $revisionId);
        if ($scope === null) {
            throw new \DomainException('Mzdová revize nebyla nalezena.');
        }
        $rows = array_values(array_filter(
            $this->repository->forRun($supplierId, $scope['run_id'], $scope['period_start']),
            static fn (array $row): bool => $row['status'] === 'active',
        ));
        $groups = [];
        foreach ($this->repository->bindings(
            $supplierId,
            array_map(static fn (array $row): int => $row['id'], $rows),
        ) as $binding) {
            if ($binding['environment'] !== $environment
                || !in_array($binding['regular_status'], self::REGULAR_DONE, true)
            ) {
                continue;
            }
            $groups[$binding['regular_submission_id']] = true;
        }
        if ($groups === []) {
            throw new JmhzXmlException(
                'jmhz_deferral_nothing_to_complete',
                'Žádný odložený vztah nečeká na doplnění. Opravné hlášení lze navázat'
                    . ' až na přijaté nebo částečně přijaté řádné hlášení.',
            );
        }
        $currentRevisionId = $this->repository->currentApprovedRevisionId($supplierId, $scope['run_id']);
        if ($currentRevisionId === null) {
            throw new JmhzXmlException(
                'jmhz_deferral_revision_missing',
                'Mzdový běh nemá schválenou revizi, ze které by šlo hlášení připravit.',
            );
        }
        $preparation = $this->preparations->freeze(
            $supplierId,
            $currentRevisionId,
            $environment,
            'jmhz-deferral-complete-all:' . $scope['run_id'] . ':' . bin2hex(random_bytes(12)),
            $userId,
        );
        $preparationId = (int) $preparation['id'];
        $submissions = [];
        $completed = [];
        $blocked = [];
        $lastBlockers = [];
        foreach (array_keys($groups) as $regularSubmissionId) {
            $pending = array_values(array_filter(
                $this->repository->bindingsForRegular($supplierId, $environment, $regularSubmissionId),
                static fn (array $row): bool => $row['status'] === 'active'
                    && !in_array($row['correction_status'], ['accepted', 'partially_accepted'], true)
                    && ($row['correction_submission_id'] === null || $row['correction_status'] === 'rejected'),
            ));
            if ($pending === []) {
                continue;
            }
            $officeId = $pending[0]['office_id'];
            $resolution = $this->documents->resolveForCorrection(
                $supplierId,
                $environment,
                $preparationId,
                $officeId,
                [],
            );
            $identifiers = [];
            $people = $resolution->status() === 'resolved' ? ($resolution->candidate?->payload['people'] ?? []) : [];
            foreach (is_array($people) ? $people : [] as $person) {
                foreach ((is_array($person) ? ($person['employments'] ?? []) : []) as $employment) {
                    if (is_array($employment)
                        && is_int($employment['employment_id'] ?? null)
                        && is_string($employment['identity']['employment_external_identifier'] ?? null)
                    ) {
                        $identifiers[$employment['employment_id']] = $employment['identity']['employment_external_identifier'];
                    }
                }
            }
            $ready = [];
            foreach ($pending as $row) {
                if (isset($identifiers[$row['employment_id']])) {
                    $ready[$row['employment_id']] = $identifiers[$row['employment_id']];
                } else {
                    $blocked[] = $row['employment_id'];
                }
            }
            if ($ready === []) {
                $lastBlockers = [...$resolution->blockers, ...$resolution->excludedBlockers];
                continue;
            }
            $submissions[] = $this->corrections->freeze(
                $supplierId,
                $environment,
                $regularSubmissionId,
                $preparationId,
                array_values($ready),
                $userId,
                $officeId,
            );
            array_push($completed, ...array_keys($ready));
        }
        if ($submissions === []) {
            throw new JmhzXmlException(
                'jmhz_deferral_still_blocked',
                'Odložené vztahy zatím doplnit nejde: '
                    . JmhzBlockerExplainer::describeNamed($lastBlockers, $this->people, $supplierId),
            );
        }
        sort($completed, SORT_NUMERIC);
        $blocked = array_values(array_unique($blocked));
        sort($blocked, SORT_NUMERIC);

        return [
            'submissions' => $submissions,
            'completed_employment_ids' => $completed,
            'still_blocked_employment_ids' => $blocked,
            'preparation_id' => $preparationId,
        ];
    }

    /**
     * Převezme do revize přípravy odložení z dřívější revize téhož běhu, která
     * se do řádného hlášení nepromítla, pokud jejich důvod trvá (vztah v nové
     * přípravě dál nese nález, kvůli kterému lze odložit). Vztah, který už je
     * v pořádku, se nepřevezme a podá se v řádném hlášení.
     *
     * @return array{created:bool,deferral_ids:list<int>,carried_from:array<int,int>,employment_ids:list<int>,source_revision_id:int}
     */
    public function carryOver(
        int $supplierId,
        string $environment,
        int $preparationId,
        int $userId,
        ?int $officeId = null,
    ): array {
        self::assertEnvironment($environment);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Odložení může převzít jen přihlášený uživatel.');
        }
        $preparation = $this->preparations->loadVerified($supplierId, $environment, $preparationId);
        $previous = $this->carryCandidates($supplierId, $preparation->runId, $preparation->periodStart, $preparation->sourceRevisionId);
        $index = self::employmentIndex($preparation);
        $byEmployment = [];
        foreach ($previous as $row) {
            if (isset($index[$row['employment_id']])
                && ($officeId === null || $index[$row['employment_id']]['office_id'] === $officeId)
            ) {
                $byEmployment[$row['employment_id']] = $row;
            }
        }
        $empty = [
            'created' => false,
            'deferral_ids' => [],
            'carried_from' => [],
            'employment_ids' => [],
            'source_revision_id' => $preparation->sourceRevisionId,
        ];
        if ($byEmployment === []) {
            return $empty;
        }
        $created = [];
        $carried = [];
        $employmentIds = [];
        foreach ($byEmployment as $employmentId => $row) {
            if (in_array($employmentId, $employmentIds, true)) {
                continue;
            }
            $reason = mb_substr('Převzato z revize ' . ($row['revision_no'] ?? '?') . ': ' . $row['reason'], 0, 500);
            try {
                $result = $this->deferMany(
                    $supplierId,
                    $environment,
                    $preparationId,
                    [$employmentId],
                    $reason,
                    $userId,
                    $officeId,
                );
            } catch (JmhzXmlException $exception) {
                if ($exception->validationCode === 'jmhz_deferral_not_blocked') {
                    continue;
                }
                throw $exception;
            }
            foreach ($result['deferral_ids'] as $id) {
                $created[] = $id;
                $carried[$id] = $row['id'];
            }
            array_push($employmentIds, ...$result['employment_ids']);
        }
        sort($employmentIds, SORT_NUMERIC);

        return [
            'created' => $created !== [],
            'deferral_ids' => $created,
            'carried_from' => $carried,
            'employment_ids' => $employmentIds,
        ] + $empty;
    }

    /**
     * Aktivní odložení dřívějších revizí běhu, která žádné řádné hlášení
     * nevynechalo (nemají vazbu na podání) - s novou revizí zastarala.
     *
     * @return list<array<string,mixed>>
     */
    private function carryCandidates(int $supplierId, int $runId, string $periodStart, int $revisionId): array
    {
        $rows = $this->repository->forRun($supplierId, $runId, $periodStart);
        $current = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'active' && $row['source_revision_id'] === $revisionId) {
                $current[$row['employment_id']] = true;
            }
        }
        $old = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['status'] === 'active'
                && $row['source_revision_id'] !== $revisionId
                && !isset($current[$row['employment_id']]),
        ));
        $bound = [];
        foreach ($this->repository->bindings(
            $supplierId,
            array_map(static fn (array $row): int => $row['id'], $old),
        ) as $binding) {
            $bound[$binding['deferral_id']] = true;
        }

        return array_values(array_filter(
            $old,
            static fn (array $row): bool => !isset($bound[$row['id']]),
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $bindings
     * @param array<int,string> $names
     * @return array<string,mixed>
     */
    private function item(array $row, array $bindings, array $names, ?int $currentRevisionId): array
    {
        $binding = $bindings === [] ? null : $bindings[count($bindings) - 1];
        $state = match (true) {
            $row['status'] === 'revoked' => 'revoked',
            $binding === null && $currentRevisionId !== $row['source_revision_id'] => 'stale',
            $binding === null => 'pending',
            in_array($binding['correction_status'], self::REGULAR_DONE, true) => 'completed',
            $binding['correction_submission_id'] !== null
                && $binding['correction_status'] !== 'rejected' => 'completing',
            in_array($binding['regular_status'], self::REGULAR_DONE, true) => 'to_complete',
            default => 'omitted',
        };
        $codes = json_decode((string) $row['blocker_codes_json'], true);

        return [
            'id' => $row['id'],
            'row_version' => $row['row_version'],
            'status' => $row['status'],
            'state' => $state,
            'run_id' => $row['run_id'],
            'source_revision_id' => $row['source_revision_id'],
            'revision_no' => $row['revision_no'] ?? null,
            'office_id' => $row['office_id'],
            'employee_id' => $row['employee_id'],
            'employee_name' => $names[$row['employee_id']] ?? null,
            'employment_id' => $row['employment_id'],
            'reason' => $row['reason'],
            'blocker_codes' => is_array($codes) ? array_values($codes) : [],
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by'],
            'revoked_at' => $row['revoked_at'],
            'revoke_reason' => $row['revoke_reason'],
            'regular_submission_id' => $binding['regular_submission_id'] ?? null,
            'regular_status' => $binding['regular_status'] ?? null,
            'correction_submission_id' => $binding['correction_submission_id'] ?? null,
            'correction_status' => $binding['correction_status'] ?? null,
            'can_revoke' => $row['status'] === 'active' && $state === 'pending',
            'can_complete' => $state === 'to_complete',
        ];
    }

    /**
     * Vztahy přípravy s osobou a registrací.
     *
     * @return array<int,array{employee_id:int,office_id:?int}>
     */
    private static function employmentIndex(JmhzVerifiedPreparationSnapshot $preparation): array
    {
        $people = $preparation->payload['people'] ?? [];
        $index = [];
        foreach (is_array($people) ? $people : [] as $person) {
            $employeeId = is_array($person) ? ($person['employee_id'] ?? null) : null;
            if (!is_int($employeeId)) {
                continue;
            }
            foreach ((is_array($person['employments'] ?? null) ? $person['employments'] : []) as $employment) {
                if (!is_array($employment) || !is_int($employment['employment_id'] ?? null)) {
                    continue;
                }
                $source = is_array($employment['employment'] ?? null) ? $employment['employment'] : [];
                $index[$employment['employment_id']] = [
                    'employee_id' => $employeeId,
                    'office_id' => is_int($source['office_id'] ?? null) ? $source['office_id'] : null,
                ];
            }
        }

        return $index;
    }

    /** @param array{employee_id:int,office_id:?int} $row */
    private static function clusterKey(array $row): string
    {
        return $row['employee_id'] . ':' . ($row['office_id'] ?? '');
    }

    /**
     * Vztahy, u kterých ČSSZ za posledních 48 hodin přijala změnu údajů
     * (REGZEC A3). Hlášení s nimi se nezmrazí; odložit je lze.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    private function settlingEmployments(int $supplierId, string $environment, array $employmentIds): array
    {
        if ($this->settlement === null || $employmentIds === []) {
            return [];
        }
        $pending = $this->settlement->pendingUntil(
            $supplierId,
            $environment,
            $employmentIds,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );

        return $pending === null ? [] : $pending['employment_ids'];
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Důvod musí mít 3 až 500 znaků.');
        }

        return $reason;
    }

    private static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException('Prostředí musí být test nebo production.');
        }
    }

    private function today(): string
    {
        return PayrollSubmissionCalendar::today($this->clock->now());
    }
}
