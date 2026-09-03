<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use Psr\Clock\ClockInterface;

final class PayrollObligationService
{
    private const SUBJECT_TYPES = [
        'employer',
        'office',
        'person',
        'employment',
        'payroll_run',
        'other',
    ];
    private const OBLIGATION_KINDS = [
        'regular',
        'correction',
        'cancellation',
    ];
    private const CHANNELS = [
        'manual_upload',
        'isds',
        'vrep_apep',
        'pikr',
        'health_portal',
        'other',
    ];
    private const CALENDAR_BASES = ['calendar_days', 'business_days'];
    private const REPLACEMENT_MODES = [
        'fully_replaced',
        'partially_replaced',
        'standalone',
        'unknown',
    ];
    private const ENVIRONMENTS = ['production', 'test'];

    /**
     * Agendy, u kterých smí za jedno rozhodné období existovat právě jedna
     * ŘÁDNÁ povinnost.
     *
     * PROČ NE PLOŠNĚ: `regular` tu neznamená „za období", ale „ne oprava".
     * Oznamovací povinnosti vůči zdravotní pojišťovně se evidují jako řádné
     * s `period_start` = den vzniku UDÁLOSTI a jeden pracovní poměr může mít
     * v týž den víc různých povinností (skončení zaměstnání a změna údajů).
     * Plošné pravidlo by je rozbilo. Jmenovitý seznam je proto správnější
     * i bezpečnější než dedukce ze `subject_type`.
     *
     * PROČ KATALOG V KÓDU: jestli úřad za období přijme jen jedno řádné
     * podání, je vlastnost agendy daná zákonem a provozem úřadu, ne nastavení
     * firmy; stejným způsobem a ze stejného důvodu je v kódu
     * {@see PayrollAgendaCorrectionPolicy}.
     *
     * Seznam MUSÍ zůstat shodný s výrazem generovaného sloupce
     * `payroll_obligations.regular_period_scope_on` (migrace 1731) — rozšíření
     * o další agendu je tedy vždy dvojice: konstanta tady a nová migrace.
     * Že se obojí nerozešlo, hlídá
     * `PayrollObligationRegularPeriodUniquenessTest`.
     *
     * @var list<string>
     */
    public const UNIQUE_REGULAR_PERIOD_AGENDAS = [
        // ČSSZ přijme za jedno rozhodné období jediné řádné podání JMHZ;
        // druhé zamítne kódem 40326 a vzít zpět se to nedá.
        'JMHZ25',
    ];

    public function __construct(
        private readonly PayrollSubmissionRepository $repository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array{id:int,due_on:string,status:string,row_version:int,created:bool}
     */
    public function register(
        int $supplierId,
        string $agendaCode,
        string $subjectType,
        string $subjectReference,
        string $periodStart,
        string $periodEnd,
        string $obligationKind,
        string $channel,
        string $sourceEventType,
        string $sourceEventReference,
        string $sourceEventHash,
        string $earliestSubmissionOn,
        string $dueOn,
        string $calendarBasis,
        string $rulesetId,
        string $rulesetHash,
        string $idempotencyKey,
        ?int $responsibleUserId = null,
        ?int $createdBy = null,
        ?int $fictionDeliveryDays = null,
        string $environment = 'production',
    ): array {
        $registration = $this->registrationRows(
            $supplierId,
            $agendaCode,
            $subjectType,
            $subjectReference,
            $periodStart,
            $periodEnd,
            $obligationKind,
            $channel,
            $sourceEventType,
            $sourceEventReference,
            $sourceEventHash,
            $earliestSubmissionOn,
            $dueOn,
            $calendarBasis,
            $rulesetId,
            $rulesetHash,
            $idempotencyKey,
            $responsibleUserId,
            $createdBy,
            $fictionDeliveryDays,
            $environment,
        );
        $idempotencyHash = $registration['obligation']['idempotency_key_hash'];
        $requestFingerprint = $registration['obligation']['request_fingerprint'];

        return $this->repository->transaction(function () use (
            $supplierId,
            $agendaCode,
            $subjectType,
            $subjectReference,
            $periodStart,
            $periodEnd,
            $obligationKind,
            $channel,
            $sourceEventType,
            $sourceEventReference,
            $sourceEventHash,
            $earliestSubmissionOn,
            $dueOn,
            $calendarBasis,
            $rulesetId,
            $rulesetHash,
            $idempotencyHash,
            $responsibleUserId,
            $createdBy,
            $fictionDeliveryDays,
            $environment,
            $requestFingerprint,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma povinnosti nebyla nalezena.');
            }
            $existing = $this->repository
                ->findObligationByIdempotencyForUpdate(
                    $supplierId,
                    $idempotencyHash,
                    $environment,
                );
            if ($existing !== null) {
                if (!hash_equals(
                    $existing['request_fingerprint'],
                    $requestFingerprint,
                )) {
                    throw new \DomainException(
                        'Idempotency klíč povinnosti už patří jiným vstupům.',
                    );
                }
                return [
                    'id' => $existing['id'],
                    'due_on' => $existing['due_on'],
                    'status' => $existing['status'],
                    'row_version' => $existing['row_version'],
                    'created' => false,
                ];
            }
            $this->assertRegularPeriodFree(
                $supplierId,
                $environment,
                $agendaCode,
                $subjectReference,
                $periodStart,
                $obligationKind,
            );

            $obligationId = $this->repository->insertObligation(
                $supplierId,
                $environment,
                $agendaCode,
                $subjectType,
                $subjectReference,
                $periodStart,
                $periodEnd,
                $obligationKind,
                $channel,
                $sourceEventType,
                $sourceEventReference,
                $sourceEventHash,
                $requestFingerprint,
                $idempotencyHash,
                $responsibleUserId,
                $createdBy,
            );
            $this->repository->insertDeadline(
                $supplierId,
                $environment,
                $obligationId,
                'regular',
                $earliestSubmissionOn,
                $dueOn,
                $calendarBasis,
                $fictionDeliveryDays,
                $rulesetId,
                $rulesetHash,
                $sourceEventHash,
                $createdBy,
            );

            return [
                'id' => $obligationId,
                'due_on' => $dueOn,
                'status' => 'open',
                'row_version' => 1,
                'created' => true,
            ];
        });
    }

    /**
     * @return array{
     *   obligation:array<string,int|string|null>,
     *   deadline:array<string,int|string|null>
     * }
     */
    public function registrationRows(
        int $supplierId,
        string $agendaCode,
        string $subjectType,
        string $subjectReference,
        string $periodStart,
        string $periodEnd,
        string $obligationKind,
        string $channel,
        string $sourceEventType,
        string $sourceEventReference,
        string $sourceEventHash,
        string $earliestSubmissionOn,
        string $dueOn,
        string $calendarBasis,
        string $rulesetId,
        string $rulesetHash,
        string $idempotencyKey,
        ?int $responsibleUserId = null,
        ?int $createdBy = null,
        ?int $fictionDeliveryDays = null,
        string $environment = 'production',
    ): array {
        $this->assertPositive($supplierId, 'Firma povinnosti');
        $this->assertActor($responsibleUserId);
        $this->assertActor($createdBy);
        $this->assertCode($agendaCode, 48, 'Agenda');
        $this->assertCode($sourceEventType, 64, 'Zdrojová událost');
        $this->assertReference($subjectReference);
        $this->assertReference($sourceEventReference);
        $this->assertHash($sourceEventHash, 'Otisk zdrojové události');
        $this->assertHash($rulesetHash, 'Otisk rulesetu');
        $this->assertCode($rulesetId, 96, 'Ruleset');
        $this->assertDateInterval($periodStart, $periodEnd, 'Období');
        $this->assertDateInterval(
            $earliestSubmissionOn,
            $dueOn,
            'Lhůta',
        );
        $this->assertAllowed($subjectType, self::SUBJECT_TYPES, 'Subjekt');
        $this->assertAllowed(
            $obligationKind,
            self::OBLIGATION_KINDS,
            'Druh povinnosti',
        );
        $this->assertAllowed($channel, self::CHANNELS, 'Kanál');
        $this->assertAllowed(
            $calendarBasis,
            self::CALENDAR_BASES,
            'Kalendář lhůty',
        );
        $this->assertAllowed(
            $environment,
            self::ENVIRONMENTS,
            'Prostředí podání',
        );
        if ($fictionDeliveryDays !== null
            && ($fictionDeliveryDays < 0 || $fictionDeliveryDays > 366)
        ) {
            throw new \InvalidArgumentException(
                'Počet dnů fikce doručení není platný.',
            );
        }
        $idempotencyHash = $this->idempotencyHash($idempotencyKey);
        $requestFingerprint = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' => 'payroll-obligation-register.v1',
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'agenda_code' => $agendaCode,
                'subject_type' => $subjectType,
                'subject_reference' => $subjectReference,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'obligation_kind' => $obligationKind,
                'channel' => $channel,
                'source_event_type' => $sourceEventType,
                'source_event_reference' => $sourceEventReference,
                'source_event_hash' => $sourceEventHash,
                'earliest_submission_on' => $earliestSubmissionOn,
                'due_on' => $dueOn,
                'calendar_basis' => $calendarBasis,
                'ruleset_id' => $rulesetId,
                'ruleset_hash' => $rulesetHash,
                'responsible_user_id' => $responsibleUserId,
                'fiction_delivery_days' => $fictionDeliveryDays,
            ]),
        );

        return [
            'obligation' => [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'agenda_code' => $agendaCode,
                'subject_type' => $subjectType,
                'subject_reference' => $subjectReference,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'obligation_kind' => $obligationKind,
                'preferred_channel' => $channel,
                'responsible_user_id' => $responsibleUserId,
                'source_event_type' => $sourceEventType,
                'source_event_reference' => $sourceEventReference,
                'source_event_hash' => $sourceEventHash,
                'request_fingerprint' => $requestFingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'created_by' => $createdBy,
            ],
            'deadline' => [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'obligation_id' => 0,
                'deadline_kind' => 'regular',
                'earliest_submission_on' => $earliestSubmissionOn,
                'due_on' => $dueOn,
                'calendar_basis' => $calendarBasis,
                'fiction_delivery_days' => $fictionDeliveryDays,
                'ruleset_id' => $rulesetId,
                'ruleset_hash' => $rulesetHash,
                'trigger_event_hash' => $sourceEventHash,
                'created_by' => $createdBy,
            ],
        ];
    }

    /** Smí u téhle agendy existovat za období jen jedna řádná povinnost? */
    public static function requiresUniqueRegularPeriod(string $agendaCode): bool
    {
        return in_array(
            $agendaCode,
            self::UNIQUE_REGULAR_PERIOD_AGENDAS,
            true,
        );
    }

    /**
     * Pojistka proti DRUHÉMU řádnému hlášení za totéž období.
     *
     * Sedí tady, a ne v mostu konkrétní agendy, protože `register()` je jediná
     * cesta, kterou povinnost vzniká pro VŠECHNY agendy (JMHZ, ELDP, OZUSPOJ,
     * nemocenská, registrace, zdravotní pojišťovny). Idempotenční klíč to
     * neuhlídá: nese přípravu a otisk snapshotu, takže druhá příprava za totéž
     * období je pro něj nový vstup a založí novou povinnost — a pod ní projde
     * i druhé řádné podání, protože `uq_payroll_submissions_regular` hlídá jen
     * jedno řádné podání NA POVINNOST.
     *
     * Vyhazuje se výjimka a NEVRACÍ se původní povinnost. Vrátit ji by vypadalo
     * smířlivěji, ale volající by nad ní rovnou stavěl druhé řádné podání a to
     * by skončilo syrovou chybou duplicity na `uq_payroll_submissions_regular`.
     * Věta o opravném hlášení je navíc to jediné, co uživatele posune dál.
     */
    private function assertRegularPeriodFree(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $subjectReference,
        string $periodStart,
        string $obligationKind,
    ): void {
        if ($obligationKind !== 'regular'
            || !self::requiresUniqueRegularPeriod($agendaCode)
        ) {
            return;
        }
        $live = $this->repository->findLiveRegularObligationForUpdate(
            $supplierId,
            $environment,
            $agendaCode,
            $subjectReference,
            $periodStart,
        );
        if ($live === null) {
            return;
        }

        throw new \DomainException(
            "Za období od {$periodStart} už je evidované řádné hlášení agendy "
            . "{$agendaCode} (povinnost #{$live['id']}). Další změny za tohle "
            . 'období se posílají opravným hlášením, ne druhým řádným.',
        );
    }

    public function registerAgendaMatrix(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
        ?string $validTo,
        string $replacementMode,
        string $rulesetId,
        string $rulesetHash,
        ?int $createdBy = null,
    ): int {
        $this->assertPositive($supplierId, 'Firma matice');
        $this->assertActor($createdBy);
        $this->assertCode($agendaCode, 48, 'Agenda');
        $this->assertDateInterval(
            $validFrom,
            $validTo ?? $validFrom,
            'Účinnost matice',
        );
        $this->assertAllowed(
            $replacementMode,
            self::REPLACEMENT_MODES,
            'Režim nahrazení',
        );
        $this->assertCode($rulesetId, 96, 'Ruleset');
        $this->assertHash($rulesetHash, 'Otisk rulesetu');

        return $this->repository->transaction(function () use (
            $supplierId,
            $agendaCode,
            $validFrom,
            $validTo,
            $replacementMode,
            $rulesetId,
            $rulesetHash,
            $createdBy,
        ): int {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma matice nebyla nalezena.');
            }
            $existing = $this->repository
                ->findAgendaMatrixByStartForUpdate(
                    $supplierId,
                    $agendaCode,
                    $validFrom,
                );
            if ($existing !== null) {
                if ($existing['valid_to'] !== $validTo
                    || $existing['replacement_mode'] !== $replacementMode
                    || $existing['ruleset_id'] !== $rulesetId
                    || !hash_equals(
                        $existing['ruleset_hash'],
                        $rulesetHash,
                    )
                ) {
                    throw new \DomainException(
                        'Stejný počátek účinnosti agendy už patří jinému pravidlu.',
                    );
                }

                return $existing['id'];
            }
            if ($this->repository->agendaMatrixOverlapsForUpdate(
                $supplierId,
                $agendaCode,
                $validFrom,
                $validTo,
            )) {
                throw new \DomainException(
                    'Účinnost agendy se překrývá s existujícím pravidlem.',
                );
            }

            return $this->repository->insertAgendaMatrix(
                $supplierId,
                $agendaCode,
                $validFrom,
                $validTo,
                $replacementMode,
                $rulesetId,
                $rulesetHash,
                $createdBy,
            );
        });
    }

    public function isAgendaAutomationAllowed(
        int $supplierId,
        string $agendaCode,
        string $onDate,
    ): bool {
        $this->assertPositive($supplierId, 'Firma matice');
        $this->assertCode($agendaCode, 48, 'Agenda');
        $this->assertDateInterval($onDate, $onDate, 'Datum matice');
        $mode = $this->repository->effectiveAgendaReplacementMode(
            $supplierId,
            $agendaCode,
            $onDate,
        );

        return $mode !== null && $mode !== 'unknown';
    }

    public function isOverdue(string $dueOn): bool
    {
        $dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if ($dueDate === false || $dueDate->format('Y-m-d') !== $dueOn) {
            throw new \InvalidArgumentException(
                'Datum splatnosti povinnosti není platné.',
            );
        }
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->setTime(0, 0);

        return $dueDate < $today;
    }

    private function idempotencyHash(string $key): string
    {
        if (mb_strlen($key, '8bit') < 8
            || mb_strlen($key, '8bit') > 200
        ) {
            throw new \InvalidArgumentException(
                'Idempotency klíč povinnosti není platný.',
            );
        }

        return hash('sha256', $key, true);
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                "{$field} musí být kladné číslo.",
            );
        }
    }

    private function assertActor(?int $value): void
    {
        if ($value !== null) {
            $this->assertPositive($value, 'Uživatel');
        }
    }

    private function assertCode(
        string $value,
        int $maxLength,
        string $field,
    ): void {
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
        ) {
            throw new \InvalidArgumentException(
                "{$field} není platná hodnota.",
            );
        }
    }

    private function assertReference(string $value): void
    {
        if (preg_match(
            '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$/D',
            $value,
        ) !== 1) {
            throw new \InvalidArgumentException(
                'Interní reference není platná.',
            );
        }
    }

    private function assertHash(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                "{$field} není SHA-256.",
            );
        }
    }

    private function assertDateInterval(
        string $from,
        string $to,
        string $field,
    ): void {
        $fromDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($fromDate === false
            || $toDate === false
            || $fromDate->format('Y-m-d') !== $from
            || $toDate->format('Y-m-d') !== $to
            || $toDate < $fromDate
        ) {
            throw new \InvalidArgumentException(
                "{$field} není platný interval.",
            );
        }
    }

    /** @param list<string> $allowed */
    private function assertAllowed(
        string $value,
        array $allowed,
        string $field,
    ): void {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$field} není podporovaný.",
            );
        }
    }
}
