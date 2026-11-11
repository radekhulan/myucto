<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Http\IsoDate;
use MyInvoice\Repository\Payroll\PayrollRegistrationA1CandidateRepository;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use Psr\Clock\ClockInterface;

/**
 * Hromadná příprava přihlášek zaměstnanců (REGZEC A1).
 *
 * Seznam ukáže vztahy, které v zvoleném období trvají nebo začnou a u ČSSZ
 * ještě přihlášené nejsou: bez ID PPV v daném prostředí, bez živé přihlášky
 * a bez přihlášky podané předchozím programem. U každého řekne, jestli je
 * profil A1 úplný, co v něm chybí a do kdy se má přihláška podat.
 *
 * Příprava jde pro každý vybraný vztah TOUTÉŽ cestou jako tlačítko
 * „Připravit podání" na kartě vztahu
 * ({@see PayrollRegistrationSubmissionService::prepare()}): XML se tu nestaví
 * a nehlídá jinak. Připravuje jen vztahy s ověřeným profilem; ostatní
 * přeskočí s důvodem. Odeslání zůstává ve frontě podání, nic se neodesílá.
 */
final readonly class PayrollRegistrationA1BatchService
{
    private const MAX_BATCH = 200;

    /** Po přijatém částečném přihlášení (P1) je plná přihláška A1 další krok. */
    private const ACCEPTED_STATUSES = ['accepted', 'partially_accepted'];

    public function __construct(
        private PayrollRegistrationA1CandidateRepository $repository,
        private PayrollRegistrationSubmissionService $registrations,
        private PayrollRegistrationIdentityService $identities,
        private PayrollEmployeeRegistrationDeadlinePolicy $deadlines,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{items:list<array<string,mixed>>,from:string,to:string,today:string}
     */
    public function candidates(
        int $supplierId,
        string $environment,
        mixed $from,
        mixed $to,
    ): array {
        $today = $this->today();
        $from = $this->date($from, 'Začátek období', substr($today, 0, 8) . '01');
        $to = $this->date(
            $to,
            'Konec období',
            (new \DateTimeImmutable(substr($today, 0, 8) . '01'))
                ->modify('+1 month')
                ->format('Y-m-t'),
        );
        if ($from > $to) {
            throw new \InvalidArgumentException(
                'Začátek období musí být nejpozději v den jeho konce.',
            );
        }
        $startPeriod = $this->repository->startPeriod($supplierId);
        $items = [];
        foreach ($this->repository->rows($supplierId, $environment, $from, $to) as $row) {
            if (self::exclusionReason($row, $startPeriod) !== null) {
                continue;
            }
            $items[] = $this->describe($supplierId, $row, $today);
        }

        return ['items' => $items, 'from' => $from, 'to' => $to, 'today' => $today];
    }

    /**
     * @param list<mixed> $employmentIds
     * @return array{results:list<array<string,mixed>>}
     */
    public function prepare(
        int $supplierId,
        string $environment,
        array $employmentIds,
        int $userId,
    ): array {
        $ids = [];
        foreach ($employmentIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException(
                    'Seznam pracovních vztahů k přihlášení obsahuje neplatné číslo.',
                );
            }
            $ids[$id] = $id;
        }
        if ($ids === [] || count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException(
                'Vyberte 1 až ' . self::MAX_BATCH . ' pracovních vztahů k přihlášení.',
            );
        }
        $rows = [];
        foreach ($this->repository->rows($supplierId, $environment, null, null, array_values($ids)) as $row) {
            $rows[$row['employment_id']] = $row;
        }
        $startPeriod = $this->repository->startPeriod($supplierId);
        $results = [];
        foreach ($ids as $employmentId) {
            $row = $rows[$employmentId] ?? null;
            $results[] = $row === null
                ? self::skipped(
                    $employmentId,
                    'not_found',
                    'Pracovní vztah u téhle firmy není, je archivovaný, nebo zaměstnanec nenastoupil, takže se za něj přihláška nepodává.',
                )
                : $this->prepareOne($supplierId, $environment, $row, $startPeriod, $userId);
        }

        return ['results' => $results];
    }

    /**
     * Proč vztah přihlášku A1 nepotřebuje; `null` = potřebuje.
     *
     * Jediné místo, kde se o tom rozhoduje — volá ho seznam i hromadná
     * příprava. Pravidla odpovídají kartě vztahu: přihláška se nepodává
     * podruhé ({@see PayrollRegistrationSubmissionService::prepare()} ji
     * odmítne u ID PPV i u živé přihlášky) a u převzaté firmy ji za vztah
     * s nástupem před začátkem vedení mezd v MyÚčtu podal předchozí program
     * ({@see PayrollPredecessorObligationScope::handledByPredecessor()}
     * s položkou checklistu registrace).
     *
     * @param array<string,mixed> $row
     */
    public static function exclusionReason(array $row, ?string $startPeriod): ?string
    {
        if ($row['has_id_ppv'] === true) {
            return 'id_ppv';
        }
        if ($row['live_submission_id'] !== null
            && !($row['live_agenda_code'] === PayrollRegistrationSubmissionService::AGENDA_PREZEC
                && in_array($row['live_status'], self::ACCEPTED_STATUSES, true))
        ) {
            return 'live_registration';
        }
        if ($row['predecessor_submission_id'] !== null
            || PayrollPredecessorObligationScope::handledByPredecessor(
                $startPeriod,
                PayrollRegistrationSubmissionService::CHECKLIST_ITEM_KEY,
                PayrollRegistrationSubmissionService::CHECKLIST_PHASE,
                null,
                $row['start_date'],
                $row['end_date'],
            )
        ) {
            return 'predecessor';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function prepareOne(
        int $supplierId,
        string $environment,
        array $row,
        ?string $startPeriod,
        int $userId,
    ): array {
        $employmentId = (int) $row['employment_id'];
        $reason = self::exclusionReason($row, $startPeriod);
        if ($reason !== null) {
            return self::skipped(
                $employmentId,
                $reason,
                match ($reason) {
                    'id_ppv' => 'Vztah už má od ČSSZ přidělené ID PPV, takže je přihlášený. Druhou přihlášku ČSSZ odmítne jako duplicitu.',
                    'live_registration' => 'Přihláška k tomuto vztahu už je připravená nebo odeslaná (podání č. '
                        . (int) $row['live_submission_id'] . '). Druhá se nezakládá.',
                    default => 'Přihlášku za tenhle vztah podal předchozí program, takže se znovu nepodává.',
                },
                $reason === 'live_registration' ? (int) $row['live_submission_id'] : null,
            );
        }
        if ($row['profile_status'] !== 'verified') {
            return self::skipped(
                $employmentId,
                'profile_incomplete',
                'Profil A1 není úplný, přihláška se nepřipravila. Doplňte ho na kartě vztahu (Doplnit profil, Kontrola).',
            );
        }
        try {
            $prepared = $this->registrations->prepare(
                $supplierId,
                $environment,
                $employmentId,
                $userId,
                null,
                true,
            );
        } catch (PayrollRegistrationXmlException
            | PayrollRegistrationIdentitySnapshotException $exception
        ) {
            return self::skipped($employmentId, $exception->validationCode, $exception->getMessage());
        } catch (\InvalidArgumentException
            | \DomainException
            | \OutOfBoundsException $exception
        ) {
            return self::skipped($employmentId, 'validation_failed', $exception->getMessage());
        }

        return [
            'employment_id' => $employmentId,
            'status' => 'prepared',
            'submission_id' => (int) $prepared['submission_id'],
            'agenda_code' => (string) $prepared['agenda_code'],
            'created' => (bool) ($prepared['created'] ?? true),
            'code' => null,
            'message' => null,
            'problems' => array_values($prepared['problems'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function describe(int $supplierId, array $row, string $today): array
    {
        $profileStatus = $row['profile_status'] === null
            ? 'missing'
            : ($row['profile_status'] === 'verified' ? 'verified' : 'draft');
        $problems = [];
        if ($profileStatus === 'missing') {
            try {
                PayrollRegistrationBusinessMatrix::requireA1Variant(null);
            } catch (PayrollRegistrationXmlException $exception) {
                $problems[] = [
                    'field' => null,
                    'code' => $exception->validationCode,
                    'message' => $exception->getMessage(),
                ];
            }
        } elseif ($profileStatus === 'draft') {
            try {
                $problems = $this->identities->storedA1ProfileProblems(
                    $supplierId,
                    (int) $row['employee_id'],
                    (int) $row['employment_id'],
                ) ?? [];
            } catch (\InvalidArgumentException | \DomainException | \OutOfBoundsException $exception) {
                $problems = [[
                    'field' => null,
                    'code' => 'validation_failed',
                    'message' => $exception->getMessage(),
                ]];
            }
        }
        $afterPreRegistration = $row['live_agenda_code'] === PayrollRegistrationSubmissionService::AGENDA_PREZEC;
        $warnings = [];
        if ($row['duplicate_employment_id'] !== null) {
            $warnings[] = [
                'code' => 'duplicate_dpp',
                'employment_id' => (int) $row['duplicate_employment_id'],
                'employment_code' => (string) $row['duplicate_code'],
                'message' => 'Osoba má jinou otevřenou DPP (' . (string) $row['duplicate_code']
                    . '), která už má od ČSSZ ID PPV. Nejspíš jde o tentýž vztah založený dvakrát; '
                    . 'zvažte sloučení vztahů místo nové přihlášky.',
            ];
        }

        return [
            'employment_id' => (int) $row['employment_id'],
            'employee_id' => (int) $row['employee_id'],
            'employee_name' => (string) $row['employee_name'],
            'code' => (string) $row['code'],
            'relation_type' => (string) $row['relation_type'],
            'status' => (string) $row['status'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'profile_status' => $profileStatus,
            'complete' => $profileStatus === 'verified',
            'problems' => array_map(static fn (array $problem): array => [
                'field' => $problem['field'] ?? null,
                'code' => (string) $problem['code'],
                'message' => (string) $problem['message'],
            ], $problems),
            'after_pre_registration' => $afterPreRegistration,
            'deadline' => $this->deadline($row['start_date'], $afterPreRegistration, $today),
            'warnings' => $warnings,
        ];
    }

    /** @return array{earliest_registration_on:string,due_on:string,derived:bool,overdue:bool}|null */
    private function deadline(?string $startOn, bool $afterPreRegistration, string $today): ?array
    {
        if ($startOn === null) {
            return null;
        }
        try {
            $window = $afterPreRegistration
                ? $this->deadlines->forFullRegistrationAfterPreRegistration($startOn)
                : $this->deadlines->forEmploymentStart($startOn);
        } catch (PayrollRegistrationXmlException) {
            return null;
        }

        return [
            'earliest_registration_on' => $window->earliestRegistrationOn,
            'due_on' => $window->dueOn,
            'derived' => $window->derived,
            'overdue' => $window->dueOn < $today,
        ];
    }

    /** @return array<string,mixed> */
    private static function skipped(
        int $employmentId,
        string $code,
        string $message,
        ?int $submissionId = null,
    ): array {
        return [
            'employment_id' => $employmentId,
            'status' => 'skipped',
            'submission_id' => $submissionId,
            'agenda_code' => null,
            'created' => false,
            'code' => $code,
            'message' => $message,
            'problems' => [],
        ];
    }

    private function date(mixed $value, string $label, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !IsoDate::isValid($value)) {
            throw new \InvalidArgumentException(
                $label . ' musí být platné datum ve tvaru RRRR-MM-DD.',
            );
        }

        return $value;
    }

    private function today(): string
    {
        return PayrollSubmissionCalendar::today($this->clock->now());
    }
}
