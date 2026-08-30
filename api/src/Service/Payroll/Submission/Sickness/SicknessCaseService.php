<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;

/**
 * Evidence případů dávek nemocenského pojištění.
 *
 * Případ vzniká DŘÍV než podání a žije i tehdy, když ho nikdo nepodá — přesně
 * proto tu je. Lhůta podle § 97 odst. 2 zák. č. 187/2006 Sb. běží od 15. dne
 * trvání dočasné pracovní neschopnosti bez ohledu na to, jestli si toho někdo
 * všiml; kdyby existovala jen jako vedlejší produkt podání, neuhlídal by ji
 * nikdo.
 *
 * Služba vědomě NEUMÍ nastavit stav `accepted` přímo. Povinnost je splněná
 * PŘEDÁNÍM územní správě sociálního zabezpečení, takže přijetí se zapisuje jen
 * přes {@see self::recordReceipt()} a vždy se dnem z protokolu.
 */
final readonly class SicknessCaseService
{
    /**
     * Sloupce, které smí zapsat klient. Whitelist, ne blacklist: `status`,
     * `accepted_on`, obě vazby na podání i `row_version` musí zůstat mimo
     * dosah HTTP požadavku, jinak by šlo prohlásit povinnost za splněnou bez
     * jediného odeslaného bajtu.
     *
     * @var array<string,string>
     */
    private const EDITABLE = [
        'ossz_code' => 'int',
        'decision_number' => 'text',
        'foreign_case' => 'bool',
        'correction' => 'bool',
        'incapacity_from' => 'date',
        'incapacity_to' => 'date',
        'issued_on' => 'date',
        'payroll_payment_date' => 'date',
        'worked_on_decisive_day' => 'bool',
        'hours_worked' => 'decimal',
        'daily_working_hours' => 'decimal',
        'small_scope_income_minor' => 'int',
        'receives_pension' => 'bool',
        'pension_kind' => 'text',
        'is_student' => 'bool',
        'within_school_holidays' => 'nullable_bool',
        'first_employment_free_time' => 'bool',
        'unpaid_leave' => 'bool',
        'unpaid_leave_from' => 'date',
        'unpaid_leave_to' => 'date',
        'starts_maternity' => 'nullable_bool',
        'child_birth_date' => 'date',
        'transferred_other_work' => 'bool',
        'transferred_on' => 'date',
        'enforcement' => 'bool',
        'insolvency' => 'bool',
        'returned_to_work' => 'nullable_bool',
        'return_reason' => 'text',
        'returned_on' => 'date',
        'hours_worked_last_day' => 'decimal',
        'shift_hours_last_day' => 'decimal',
        'additional_note' => 'text',
    ];

    public function __construct(
        private PayrollSicknessCaseRepository $cases,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        $rows = $this->cases->listForSupplier(
            $supplierId,
            $environment,
            $employmentId,
        );
        foreach ($rows as $index => $row) {
            $rows[$index]['work_days'] = $this->cases->workDays(
                $supplierId,
                $environment,
                (int) $row['id'],
            );
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        array $input,
        int $createdBy,
    ): array {
        $kind = $this->benefitKind($benefitKind);
        $values = $this->normalize($input, true);
        $incapacityFrom = (string) $values['incapacity_from'];
        $context = $this->requireContext(
            $supplierId,
            $employmentId,
            $incapacityFrom,
        );
        $overlapping = $this->cases->overlappingForEmployment(
            $supplierId,
            $environment,
            $employmentId,
            $kind->value,
            $incapacityFrom,
            $values['incapacity_to'] === null
                ? null
                : (string) $values['incapacity_to'],
        );
        if ($overlapping !== []) {
            throw new SicknessException(
                'sickness_case_overlaps',
                'Pro tenhle pracovní vztah už je evidovaný případ téhož druhu dávky, '
                . 'který se s obdobím překrývá. Dvě podání za tutéž událost ČSSZ nespáruje.',
            );
        }
        if ($values['ossz_code'] === null) {
            $values['ossz_code'] = $this->defaultOsszCode($context);
        }
        $workDays = $this->workIntervals($input);

        $caseId = $this->cases->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $kind,
            $values,
            $context,
            $createdBy,
            $workDays,
        ): int {
            $id = $this->cases->insert($supplierId, $environment, [
                'employee_id' => (int) $context['employee_id'],
                'employment_id' => $employmentId,
                'benefit_kind' => $kind->value,
                'created_by' => $createdBy,
                ...$values,
            ]);
            $this->cases->replaceWorkDays(
                $supplierId,
                $environment,
                $id,
                $workDays,
            );

            return $id;
        });

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(
        int $supplierId,
        string $environment,
        int $caseId,
        int $rowVersion,
        array $input,
    ): array {
        $row = $this->requireCase($supplierId, $environment, $caseId);
        $status = SicknessCaseStatus::from((string) $row['status']);
        if (!$status->isOpen()) {
            throw new SicknessException(
                'sickness_case_not_editable',
                'Přijatý ani zrušený případ se už needituje. Opravu podejte opravným podáním '
                . 's číslem rozhodnutí.',
            );
        }
        $values = $this->normalize($input, false);
        if ($values !== []) {
            if (!$this->cases->update(
                $supplierId,
                $environment,
                $caseId,
                $rowVersion,
                $values,
            )) {
                throw new SicknessException(
                    'sickness_case_conflict',
                    'Případ mezitím někdo změnil. Načtěte ho znovu a úpravu zopakujte.',
                );
            }
        }
        if (array_key_exists('work_days', $input)) {
            $this->cases->replaceWorkDays(
                $supplierId,
                $environment,
                $caseId,
                $this->workIntervals($input),
            );
        }

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /**
     * Zápis výsledku z protokolu ČSSZ.
     *
     * `accepted` vyžaduje den doručení. Bez něj by povinnost byla „splněná
     * někdy" a hlídač termínů by neměl co porovnat s lhůtou.
     *
     * @return array<string,mixed>
     */
    public function recordReceipt(
        int $supplierId,
        string $environment,
        int $caseId,
        string $outcome,
        ?string $acceptedOn,
        ?string $reason,
    ): array {
        $row = $this->requireCase($supplierId, $environment, $caseId);
        $changes = match ($outcome) {
            'accepted' => [
                'status' => SicknessCaseStatus::Accepted->value,
                'accepted_on' => $this->requireDate(
                    $acceptedOn,
                    'sickness_receipt_date_missing',
                    'Přijetí musí nést den doručení podání z protokolu ČSSZ.',
                ),
                'rejection_reason' => null,
            ],
            'rejected' => [
                'status' => SicknessCaseStatus::Rejected->value,
                'accepted_on' => null,
                'rejection_reason' => $this->requireText(
                    $reason,
                    'sickness_rejection_reason_missing',
                    'Odmítnutí musí nést důvod z protokolu ČSSZ.',
                ),
            ],
            'cancelled' => [
                'status' => SicknessCaseStatus::Cancelled->value,
                'accepted_on' => null,
                'rejection_reason' => null,
            ],
            default => throw new SicknessException(
                'sickness_receipt_outcome_invalid',
                'Výsledek podání musí být accepted, rejected nebo cancelled.',
            ),
        };
        if (!$this->cases->update(
            $supplierId,
            $environment,
            $caseId,
            (int) $row['row_version'],
            $changes,
        )) {
            throw new SicknessException(
                'sickness_case_conflict',
                'Případ mezitím někdo změnil. Načtěte ho znovu a výsledek zapište znovu.',
            );
        }

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /** @return array<string,mixed> */
    public function requireCase(
        int $supplierId,
        string $environment,
        int $caseId,
    ): array {
        $row = $this->cases->find($supplierId, $environment, $caseId);
        if ($row === null) {
            throw new \OutOfBoundsException(
                'Případ dávky nemocenského pojištění nebyl nalezen.',
            );
        }
        $row['work_days'] = $this->cases->workDays(
            $supplierId,
            $environment,
            $caseId,
        );

        return $row;
    }

    /** @return array<string,mixed> */
    public function requireContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): array {
        $context = $this->cases->findEmploymentContext(
            $supplierId,
            $employmentId,
            $onDate,
        );
        if ($context === null) {
            throw new SicknessException(
                'sickness_employment_missing',
                'Pracovní vztah k datu vzniku sociální události neexistuje.',
            );
        }

        return $context;
    }

    public function benefitKind(string $value): SicknessBenefitKind
    {
        $kind = SicknessBenefitKind::tryFrom(strtoupper(trim($value)));
        if ($kind === null) {
            throw new SicknessException(
                'sickness_benefit_kind_invalid',
                'Druh dávky musí být NEM, VPM, OPP, PPM, OSE nebo DLO podle číselníku ČSSZ.',
            );
        }

        return $kind;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input, bool $requireCore): array
    {
        $values = [];
        foreach (self::EDITABLE as $column => $type) {
            if (!array_key_exists($column, $input)) {
                continue;
            }
            $values[$column] = $this->cast($column, $type, $input[$column]);
        }
        if ($requireCore) {
            if (!isset($values['incapacity_from'])) {
                throw new SicknessException(
                    'sickness_incapacity_from_missing',
                    'Případ musí mít den vzniku sociální události.',
                );
            }
            foreach (['ossz_code'] as $optional) {
                $values[$optional] ??= null;
            }
        }
        if (isset($values['incapacity_from'], $values['incapacity_to'])
            && $values['incapacity_to'] !== null
            && $values['incapacity_to'] < $values['incapacity_from']
        ) {
            throw new SicknessException(
                'sickness_case_period_invalid',
                'Den skončení sociální události nesmí předcházet dni jejího vzniku.',
            );
        }

        return $values;
    }

    private function cast(string $column, string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            if ($type === 'bool') {
                return 0;
            }

            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'bool', 'nullable_bool' => $this->boolean($value) ? 1 : 0,
            'decimal' => $this->decimal($column, $value),
            'date' => $this->requireDate(
                (string) $value,
                'sickness_date_invalid',
                'Datum v případu musí být ve tvaru RRRR-MM-DD.',
            ),
            default => trim((string) $value),
        };
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'ano', 'a', 'yes'],
            true,
        );
    }

    private function decimal(string $column, mixed $value): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new SicknessException(
                'sickness_hours_invalid',
                'Hodnota „' . $column . '" musí být kladné číslo s nejvýše dvěma desetinnými místy.',
            );
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array{from:string,to:string}>
     */
    private function workIntervals(array $input): array
    {
        $raw = $input['work_days'] ?? [];
        if (!is_array($raw)) {
            throw new SicknessException(
                'hzupn_work_intervals_invalid',
                'Dny práce v době neschopnosti musí být seznam intervalů.',
            );
        }
        $intervals = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new SicknessException(
                    'hzupn_work_intervals_invalid',
                    'Každý interval práce musí mít den od a den do.',
                );
            }
            $from = $this->requireDate(
                isset($item['from']) ? (string) $item['from'] : null,
                'hzupn_work_intervals_invalid',
                'Interval práce v době neschopnosti musí mít den od ve tvaru RRRR-MM-DD.',
            );
            $to = $this->requireDate(
                isset($item['to']) ? (string) $item['to'] : null,
                'hzupn_work_intervals_invalid',
                'Interval práce v době neschopnosti musí mít den do ve tvaru RRRR-MM-DD.',
            );
            $intervals[] = ['from' => $from, 'to' => $to];
        }
        usort(
            $intervals,
            static fn (array $a, array $b): int => $a['from'] <=> $b['from'],
        );

        return $intervals;
    }

    /** @param array<string,mixed> $context */
    private function defaultOsszCode(array $context): int
    {
        $code = $context['employer_ossz_code'] ?? null;
        if (!is_numeric($code) || (int) $code < 100 || (int) $code > 999) {
            throw new SicknessException(
                'sickness_ossz_code_missing',
                'Firma nemá vyplněný tříciferný kód OSSZ podle číselníku pracovišť ČSSZ. '
                . 'Doplňte ho v Nastavení mezd → Zaměstnavatel, nebo ho zadejte u případu.',
            );
        }

        return (int) $code;
    }

    private function requireDate(
        ?string $value,
        string $code,
        string $message,
    ): string {
        $trimmed = $value === null ? '' : trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $trimmed
        ) {
            throw new SicknessException($code, $message);
        }

        return $trimmed;
    }

    private function requireText(
        ?string $value,
        string $code,
        string $message,
    ): string {
        $trimmed = $value === null ? '' : trim($value);
        if ($trimmed === '') {
            throw new SicknessException($code, $message);
        }

        return $trimmed;
    }
}
