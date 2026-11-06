<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzDeferralRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
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
 *     revize (přepočet) znamená nová data a nové rozhodnutí.
 */
final readonly class JmhzDeferralService
{
    private const REGULAR_DONE = ['accepted', 'partially_accepted'];

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

        return [
            'environment' => $environment,
            'run_id' => $scope['run_id'],
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
        self::assertEnvironment($environment);
        $reason = self::reason($reason);
        if ($employmentId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah a uživatel musí být kladná čísla.');
        }
        $preparation = $this->preparations->loadVerified($supplierId, $environment, $preparationId);
        $cluster = self::cluster($preparation, $employmentId);
        $resolution = $this->documents->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            JmhzFormExclusion::correctionScope([]),
        );
        $codes = [];
        foreach ($resolution->blockers as $blocker) {
            if (!$blocker->deferrable()) {
                continue;
            }
            if (($blocker->entityType === 'employment'
                    && in_array($blocker->entityId, $cluster['employment_ids'], true))
                || (in_array($blocker->entityType, ['person', 'employee'], true)
                    && $blocker->entityId === $cluster['employee_id'])
            ) {
                $codes[$blocker->code] = true;
            }
        }
        if ($codes === []) {
            throw new JmhzXmlException(
                'jmhz_deferral_not_blocked',
                'Vztah v přípravě nemá žádný nález, který by bránil podání - podá se'
                    . ' v řádném hlášení a odkládat ho není důvod.',
            );
        }
        $codes = array_keys($codes);
        sort($codes, SORT_STRING);

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $preparation,
            $cluster,
            $codes,
            $reason,
            $userId,
        ): array {
            $live = $this->repository->liveRegularSubmission(
                $supplierId,
                'production',
                $preparation->runId,
                $cluster['office_id'],
                $preparation->periodStart,
            );
            if ($live !== null) {
                throw new JmhzXmlException(
                    'jmhz_deferral_regular_frozen',
                    'Řádné hlášení za toto období je už zmrazené, odložení se do něj'
                        . ' nepromítne. Vztah doplňte opravným hlášením.',
                );
            }
            $active = $this->repository->activeForRevision($supplierId, $preparation->sourceRevisionId, true);
            $activeEmployments = [];
            foreach ($active as $row) {
                if ($row['office_id'] === $cluster['office_id']) {
                    $activeEmployments[$row['employment_id']] = $row;
                }
            }
            $remaining = array_diff(
                $cluster['office_employment_ids'],
                array_keys($activeEmployments),
                $cluster['employment_ids'],
            );
            if ($remaining === []) {
                throw new JmhzXmlException(
                    'jmhz_deferral_no_form_left',
                    'Odložením by v hlášení za registraci nezůstal žádný formulář.'
                        . ' Hlášení podejte až po doplnění dat.',
                );
            }
            $created = [];
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
                    'blocker_codes_json' => CanonicalJson::encode($codes),
                    'decided_environment' => $environment,
                    'decided_preparation_id' => $preparation->id,
                    'decided_snapshot_fingerprint' => $preparation->snapshotFingerprint,
                    'created_by' => $userId,
                ]);
            }

            return [
                'created' => $created !== [],
                'deferral_ids' => $created,
                'employee_id' => $cluster['employee_id'],
                'employment_ids' => $cluster['employment_ids'],
                'office_id' => $cluster['office_id'],
                'source_revision_id' => $preparation->sourceRevisionId,
                'blocker_codes' => $codes,
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
     * Vztah a jeho souběžné vztahy téže osoby v téže registraci.
     *
     * @return array{employee_id:int,office_id:?int,employment_ids:list<int>,office_employment_ids:list<int>}
     */
    private static function cluster(JmhzVerifiedPreparationSnapshot $preparation, int $employmentId): array
    {
        $people = $preparation->payload['people'] ?? [];
        $found = null;
        foreach (is_array($people) ? $people : [] as $person) {
            foreach ((is_array($person) ? ($person['employments'] ?? []) : []) as $employment) {
                if (is_array($employment) && ($employment['employment_id'] ?? null) === $employmentId) {
                    $source = is_array($employment['employment'] ?? null) ? $employment['employment'] : [];
                    $found = [
                        'employee_id' => $person['employee_id'] ?? null,
                        'office_id' => is_int($source['office_id'] ?? null) ? $source['office_id'] : null,
                    ];
                }
            }
        }
        if ($found === null || !is_int($found['employee_id'])) {
            throw new JmhzXmlException(
                'jmhz_deferral_employment_unknown',
                'Pracovní vztah v přípravě hlášení není.',
            );
        }
        $employmentIds = [];
        $officeEmploymentIds = [];
        foreach (is_array($people) ? $people : [] as $person) {
            foreach ((is_array($person) ? ($person['employments'] ?? []) : []) as $employment) {
                if (!is_array($employment) || !is_int($employment['employment_id'] ?? null)) {
                    continue;
                }
                $source = is_array($employment['employment'] ?? null) ? $employment['employment'] : [];
                $office = is_int($source['office_id'] ?? null) ? $source['office_id'] : null;
                if ($office !== $found['office_id']) {
                    continue;
                }
                $officeEmploymentIds[] = $employment['employment_id'];
                if (($person['employee_id'] ?? null) === $found['employee_id']) {
                    $employmentIds[] = $employment['employment_id'];
                }
            }
        }
        sort($employmentIds, SORT_NUMERIC);

        return [
            'employee_id' => $found['employee_id'],
            'office_id' => $found['office_id'],
            'employment_ids' => $employmentIds,
            'office_employment_ids' => $officeEmploymentIds,
        ];
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
