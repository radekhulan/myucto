<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollOvertimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeImportInputsApprovedException;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Time\Juvenile\JuvenileWorkTimeFinding;
use MyInvoice\Service\Payroll\Time\Juvenile\PayrollJuvenileWorkTimeService;
use MyInvoice\Service\Payroll\Time\Overtime\CompensatoryTimeOffReconciliation;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimits;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeProtectionWindow;
use MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService;

final class PayrollTimeService
{
    /**
     * Důvod se v událostech měsíce ukládá i s popisem změny zdroje
     * (nejvýše 39 znaků) a sloupec má 500 znaků; ořez by text tiše ztratil.
     */
    public const SOURCE_REASON_MAX_LENGTH = 450;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveManualSummary(int $supplierId, string $period, array $input, ?int $userId): array
    {
        [$periodStart, $periodEnd] = $this->periodBounds($period);
        // Důvod je nepovinný jen u prvního zadání; zda jde o první, ví až
        // repozitář pod zámkem měsíce.
        $reason = $input['reason'] ?? null;
        if (($reason !== null && !is_string($reason)) || (is_string($reason) && mb_strlen(trim($reason)) > self::SOURCE_REASON_MAX_LENGTH)) {
            throw new \InvalidArgumentException('Uveďte důvod zadání hodin (nejvýše ' . self::SOURCE_REASON_MAX_LENGTH . ' znaků).');
        }
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
        $replace = $input['replace_source'] ?? false;
        if (!is_bool($replace)) {
            throw new \InvalidArgumentException('Potvrzení nahrazení zdroje musí být boolean.');
        }
        $segments = $input['segments'] ?? null;
        if (!is_array($segments) || !array_is_list($segments) || $segments === [] || count($segments) > 31) {
            throw new \InvalidArgumentException('Zadejte jeden až 31 úseků odpracované doby.');
        }
        $normalized = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                throw new \InvalidArgumentException('Úsek odpracované doby není platný.');
            }
            $from = $segment['date_from'] ?? null;
            $to = $segment['date_to'] ?? null;
            if (!self::strictDate($from) || !self::strictDate($to)
                || $from < $periodStart || $to >= $periodEnd || $to < $from) {
                throw new \InvalidArgumentException('Úsek musí být platný a celý ležet ve zvoleném měsíci.');
            }
            $worked = $segment['worked_duration_units'] ?? null;
            $overtime = $segment['overtime_duration_units'] ?? null;
            $days = $segment['worked_days'] ?? null;
            $span = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
            if (!is_int($worked) || $worked < 0 || $worked > $span * 24 * 3000
                || ($overtime !== null && (!is_int($overtime) || $overtime < 0 || $overtime > $worked))
                || ($days !== null && (!is_int($days) || $days < 0 || $days > $span
                    || (($worked === 0) !== ($days === 0))))) {
                throw new \InvalidArgumentException('Odpracovaná doba, přesčas nebo počet dnů nejsou platné.');
            }
            $normalized[] = ['date_from' => $from, 'date_to' => $to, 'worked_duration_units' => $worked,
                'overtime_duration_units' => $overtime, 'worked_days' => $days];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['date_from'] <=> $b['date_from']);
        foreach ($normalized as $i => $segment) {
            if ($i > 0 && $normalized[$i - 1]['date_to'] >= $segment['date_from']) {
                throw new \InvalidArgumentException('Úseky odpracované doby se nesmí překrývat.');
            }
        }
        return $this->repository->saveManualSummary(
            $supplierId, $this->positiveInt($input, 'employment_id'), $periodStart,
            $this->nonNegativeInt($input, 'row_version'), $reason, $replace, $normalized, $userId,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function restoreEntries(int $supplierId, string $period, array $input, ?int $userId): array
    {
        [$periodStart] = $this->periodBounds($period);
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > self::SOURCE_REASON_MAX_LENGTH || ($input['replace_source'] ?? null) !== true) {
            throw new \InvalidArgumentException('Změna zdroje vyžaduje potvrzení a důvod (nejvýše ' . self::SOURCE_REASON_MAX_LENGTH . ' znaků).');
        }
        $source = $input['source'] ?? 'entries';
        if (!in_array($source, ['entries', 'import_summary'], true)) {
            throw new \InvalidArgumentException('Vyberte předchozí zdroj docházky nebo importu.');
        }
        return $this->repository->restoreEntries($supplierId, $this->positiveInt($input, 'employment_id'), $periodStart, $this->nonNegativeInt($input, 'row_version'), trim($reason), $source, $userId);
    }

    /**
     * „Počítat ze směn": měsíc z importu docházky přepne na docházku jedním krokem.
     *
     * Dřív to byly tři kroky na dvou obrazovkách: hláška u zápisu směny poslala
     * na neexistující panel, změna zdroje pak spadla na nezrušených vstupech
     * z importu a ty se musely hledat a rušit v Mzdových vstupech. Teď se
     * v jedné transakci zruší neschválené importované vstupy vztahu za měsíc
     * a zdroj se přepne. Schválené a zamčené vstupy se nemění — to je
     * rozhodnutí účetní, ne vedlejší účinek přepnutí (409 se seznamem).
     *
     * `keep_import_inputs: true` je „Zapisovat směny, částky ponechat": zdroj
     * hodin se přepne a importované vstupy zůstanou. Smí jen vztah, u kterého
     * hodiny nevstupují do žádné částky
     * ({@see PayrollTimeRepository::importAmountsKeepableIds()}); jinde by se
     * mzda ze směn spočítala vedle importu podruhé.
     *
     * `take_over_shifts: true` v téže transakci převezme publikované směny
     * měsíce jako odpracovaný čas ({@see takeOverPublishedShifts()}).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function switchImportToEntries(
        int $supplierId,
        string $period,
        array $input,
        ?int $userId,
    ): array {
        [$periodStart] = $this->periodBounds($period);
        $note = $input['reason'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen(trim($note)) > self::SOURCE_REASON_MAX_LENGTH)) {
            throw new \InvalidArgumentException('Důvod může mít nejvýše ' . self::SOURCE_REASON_MAX_LENGTH . ' znaků.');
        }
        $keep = self::optionalFlag($input, 'keep_import_inputs');
        $takeOver = self::optionalFlag($input, 'take_over_shifts');
        $reason = is_string($note) && trim($note) !== ''
            ? trim($note)
            : ($keep
                ? 'Zapisovat směny, částky ponechat: importované vstupy zůstávají, zdroj přepnut na docházku'
                : 'Počítat ze směn: importované vstupy zrušeny, zdroj přepnut na docházku');
        $employmentId = $this->positiveInt($input, 'employment_id');
        $rowVersion = $this->nonNegativeInt($input, 'row_version');

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->repository->lockedWorkSource($supplierId, $employmentId, $periodStart) !== 'import_summary') {
                throw new \InvalidArgumentException('Měsíc nebere hodiny ze souhrnu importu docházky, není co přepínat.');
            }
            $cancelled = [];
            if (!$keep) {
                $active = $this->repository->activeImportInputs($supplierId, $employmentId, $periodStart);
                $frozen = array_values(array_map(
                    static fn (array $row): int => $row['id'],
                    array_filter($active, static fn (array $row): bool => $row['status'] !== 'draft'),
                ));
                if ($frozen !== []) {
                    throw new PayrollTimeImportInputsApprovedException($frozen);
                }
                foreach ($active as $row) {
                    $this->inputs->cancel($supplierId, $row['id'], $row['row_version']);
                    $cancelled[] = $row['id'];
                }
            }
            $result = $this->repository->restoreEntries(
                $supplierId,
                $employmentId,
                $periodStart,
                $rowVersion,
                $reason,
                'entries',
                $userId,
                $keep,
            );
            $created = [];
            if ($takeOver) {
                [$created, $month] = $this->takeOverPublishedShifts($supplierId, $employmentId, $periodStart, $result['month'], $userId);
                $result['month'] = $month;
            }
            $result['created_entry_ids'] = $created;
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $result + ['cancelled_input_ids' => $cancelled];
    }

    /**
     * Publikované směny měsíce jako odpracovaný čas: běžná práce se stejným
     * začátkem, koncem, přestávkou a časovým pásmem. Jde přes {@see saveEntry()},
     * takže platí tytéž kontroly (otevřený měsíc, překryv, revize). Původ nese
     * `source_kind = schedule` a odkaz `shift:{id}`.
     *
     * @param array<string,mixed> $month
     * @return array{list<int>,array<string,mixed>}
     */
    private function takeOverPublishedShifts(int $supplierId, int $employmentId, string $periodStart, array $month, ?int $userId): array
    {
        $created = [];
        $utc = new \DateTimeZone('UTC');
        foreach ($this->repository->publishedShiftsInPeriod($supplierId, [$employmentId], $periodStart)[$employmentId] ?? [] as $shift) {
            $shiftId = PayrollTimeValue::int($shift['id'] ?? null, 'id');
            $timezone = PayrollTimeValue::string($shift['timezone_name'] ?? null, 'timezone_name');
            $local = static fn (string $key): string => (new \DateTimeImmutable(PayrollTimeValue::string($shift[$key] ?? null, $key), $utc))
                ->setTimezone(new \DateTimeZone($timezone))->format(DATE_ATOM);
            $saved = $this->saveEntry($supplierId, [
                'employment_id' => $employmentId,
                'starts_at' => $local('starts_at_utc'),
                'ends_at' => $local('ends_at_utc'),
                'timezone' => $timezone,
                'category' => 'regular',
                'break_minutes' => PayrollTimeValue::int($shift['break_minutes'] ?? null, 'break_minutes'),
                'row_version' => 0,
                'month_row_version' => PayrollTimeValue::int($month['row_version'] ?? null, 'row_version'),
            ], $userId, 'schedule', 'shift:' . $shiftId, hash('sha256', 'shift:' . $shiftId, true));
            $created[] = PayrollTimeValue::int($saved['entry']['id'] ?? null, 'id');
            $month = $saved['month'];
        }

        return [$created, $month];
    }

    /** @param array<string,mixed> $input */
    private static function optionalFlag(array $input, string $key): bool
    {
        $value = $input[$key] ?? false;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException($key . ' musí být true nebo false.');
        }

        return $value;
    }

    /** Přesně YYYY-MM-DD; regex se `$` by propustil i datum s koncovým odřádkováním. */
    private static function strictDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    public const LIST_DEFAULT_LIMIT = 25;
    public const LIST_MAX_LIMIT = 200;

    /**
     * Kolik buněk měsíční mřížky se vejde do jednoho požadavku.
     *
     * Stránka mřížky je 25 vztahů × 31 dnů = 775 buněk, takže se plná stránka
     * uloží dvěma požadavky místo 775. Vyšší strop nedává smysl: dávka běží
     * buňku po buňce a delší požadavek by narazil na `max_execution_time`.
     */
    public const BATCH_MAX_CELLS = 500;

    /**
     * Kolik měsíců historie se vejde do jednoho požadavku.
     *
     * Každý měsíc stojí několik dotazů (verze kalendáře, jejich výjimky,
     * směny, zápisy, stav měsíce), takže rok je horní hranice toho, co má
     * smysl postavit naráz — a zároveň přesně ten výřez, kvůli kterému se
     * historie otevírá.
     */
    public const HISTORY_MAX_LIMIT = 12;
    public const HISTORY_DEFAULT_LIMIT = 12;

    private const CATEGORIES = [
        'regular',
        'overtime',
        'night',
        'weekend',
        'holiday',
        'difficult_environment',
    ];

    public function __construct(
        private readonly PayrollTimeRepository $repository,
        private readonly PayrollCalendarFundService $fund,
        private readonly PayrollJmhzWorkMonthSummaryBuilder $jmhzWorkSummary,
        private readonly PayrollOvertimeLimitService $overtimeLimits,
        private readonly PayrollOvertimeRepository $overtime,
        private readonly PayrollInputRepository $inputs,
        private readonly Connection $db,
        private readonly PayrollJuvenileWorkTimeService $juvenileWorkTime,
    ) {}

    /**
     * Hodiny ze směn proti původnímu souhrnu importu měsíce, který přešel na
     * směny. Jen upozornění: u vztahu s ponechanými částkami se mzda z hodin
     * nepočítá, rozdíl ale může znamenat chybějící nebo navíc zapsanou směnu.
     *
     * @return array{import_worked_duration_units:int,entries_worked_duration_units:int,differs:bool}
     */
    private static function importSummaryComparison(int $importMillihours, int $entryMinutes): array
    {
        $import = PayrollDuration::fromMillihours($importMillihours);
        $entries = PayrollDuration::fromMinutes($entryMinutes);

        return [
            'import_worked_duration_units' => $import,
            'entries_worked_duration_units' => $entries,
            'differs' => $import !== $entries,
        ];
    }

    /** @return array<string,mixed> */
    public function overview(
        int $supplierId,
        string $period,
        bool $incompleteOnly,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employmentId = null,
        ?string $search = null,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici: přehled staví na
        // řádek několik dotazů a náhledů, takže „vypiš všechno" nesmí jít
        // objednat ani jiným volajícím než akcí.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        if ($employmentId !== null && $employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }
        [$periodStart, $periodEnd, $startsAtUtc, $endsAtUtc] = $this->periodBounds($period);
        // Zúžení na jeden vztah padá STEJNĚ brzy jako „jen nedokončené"
        // a stránkování — a od zavedení historie po měsících rovnou do SQL.
        // Dokud běželo v prohlížeči nad načtenou stránkou, vztah z jiné strany
        // se tiše neprojevil: lišta zmizela a seznam zůstal celý, což vypadá
        // jako prázdný výsledek, ne jako nefunkční filtr. Filtr v PHP tu už
        // není schválně — druhé místo, kde se zúžení rozhoduje, by se s tím
        // v SQL dřív nebo později rozešlo.
        $employments = $this->repository->employments(
            $supplierId,
            $periodStart,
            $periodEnd,
            $employmentId,
            $search === null ? null : mb_substr(trim($search), 0, 100),
        );
        $shifts = $this->groupByEmployment(
            $this->startingInPeriod(
                $this->repository->shifts($supplierId, $startsAtUtc, $endsAtUtc, $employmentId),
                $period,
            ),
        );
        $entries = $this->groupByEmployment(
            $this->startingInPeriod(
                $this->repository->entries($supplierId, $startsAtUtc, $endsAtUtc, $employmentId),
                $period,
            ),
        );
        $states = [];
        foreach ($this->repository->monthStates($supplierId, $periodStart, $employmentId) as $state) {
            $states[PayrollTimeValue::int($state['employment_id'] ?? null, 'employment_id')] = $state;
        }

        // Zúžení „jen nedokončené" i stránkování musí padnout PŘED stavbou
        // řádků: rozpracovanost se pozná z dat, která jsou v paměti stejně
        // (směny, zápisy) plus jednoho hromadného dotazu na existenci
        // kalendáře. Kdyby se filtrovalo až nad hotovými řádky, zaplatil by
        // server fond, náhled JMHZ i limity přesčasu za každý vztah firmy.
        $allIds = [];
        foreach ($employments as $employment) {
            $allIds[] = PayrollTimeValue::int($employment['id'] ?? null, 'id');
        }
        $withCalendar = array_fill_keys(
            $this->repository->employmentIdsWithCalendar(
                $supplierId,
                $allIds,
                $periodStart,
                $periodEnd,
            ),
            true,
        );
        $incompleteFlags = [];
        foreach ($allIds as $id) {
            $incompleteFlags[$id] = $this->looksIncomplete(
                isset($withCalendar[$id]),
                $shifts[$id] ?? [],
                $entries[$id] ?? [],
            );
        }
        if ($incompleteOnly) {
            $employments = array_values(array_filter(
                $employments,
                fn (array $employment): bool => $incompleteFlags[
                    PayrollTimeValue::int($employment['id'] ?? null, 'id')
                ],
            ));
        }
        $total = count($employments);
        // Otevřené měsíce celého výběru, ne jen stránky: „Schválit měsíc všem"
        // dřív slibovalo a schvalovalo jen 25 viditelných řádků, kontrola běhu
        // přitom hlásila desítky dalších na dalších stránkách.
        $openTotal = 0;
        foreach ($employments as $employment) {
            $status = $states[PayrollTimeValue::int($employment['id'] ?? null, 'id')]['status'] ?? 'open';
            if ($status === 'open') {
                ++$openTotal;
            }
        }
        $employments = array_slice($employments, $offset, $limit);

        // § 93 zákoníku práce — stav limitů přesčasu se počítá pro CELOU
        // stránku jedním dotazem, protože roční i vyrovnávací okno sahá mimo
        // měsíc a dotaz na vztah by se jinak opakoval pro každý řádek.
        $employmentIds = [];
        $employmentStarts = [];
        foreach ($employments as $employment) {
            $id = PayrollTimeValue::int($employment['id'] ?? null, 'id');
            $employmentIds[] = $id;
            $start = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
            $employmentStarts[$id] = is_string($start) ? $start : null;
        }
        $manualSummaries = $this->repository->selectedManualSummaries($supplierId, $employmentIds, [$periodStart]);
        $importSummaries = $this->repository->selectedImportSummaries($supplierId, $employmentIds, [$periodStart]);
        $periodLastDay = (new \DateTimeImmutable($periodEnd))
            ->modify('-1 day')
            ->format('Y-m-d');
        $hourlyRanges = $this->repository->hourlyRemunerationRanges($supplierId, $employmentIds, $periodStart, $periodLastDay);
        $manualIds = [];
        $importIds = [];
        $entryIds = [];
        foreach ($employmentIds as $id) {
            $source = $states[$id]['work_source'] ?? 'entries';
            if ($source === 'manual_summary' && ($states[$id]['status'] ?? null) === 'open') {
                $manualIds[] = $id;
            }
            if ($source === 'import_summary') {
                $importIds[] = $id;
            } elseif ($source === 'entries' && isset($states[$id])) {
                $entryIds[] = $id;
            }
        }
        $keepableImports = $this->repository->importAmountsKeepableIds($supplierId, $importIds, $periodStart);
        $replacedImports = $this->repository->replacedImportWorkedMillihours($supplierId, $entryIds, $periodStart);
        $juvenileFindings = $this->juvenileWorkTime->findingsForMany($supplierId, $employmentIds, $periodStart);
        $manualEvidence = $manualIds === [] ? [] : $this->jmhzWorkSummary->manualSummaryEvidence($supplierId, $manualIds, $periodStart);
        $overtimeLimits = $this->overtimeLimits->assessMany(
            $supplierId,
            $employmentIds,
            $periodStart,
            $periodLastDay,
            $employmentStarts,
        );
        $overtimeConsents = $this->overtime->consentRowsForMany($supplierId, $employmentIds);
        $overtimeProtections = $this->overtime->protectionRowsForMany($supplierId, $employmentIds);
        // Náhradní volno se ukazuje za celé vyrovnávací okno, ne jen za měsíc —
        // z okna se odečítá podle dne PŘESČASU (§ 93 odst. 5) a účetní musí
        // vidět i zápis, který do zobrazeného měsíce nespadá.
        $overtimeCompensations = $this->overtime->compensationRowsForMany(
            $supplierId,
            $employmentIds,
            (new \DateTimeImmutable($periodStart))->modify('-52 weeks')->format('Y-m-d'),
            $periodLastDay,
        );
        // Náhradní volno se eviduje na dvou místech (viz
        // CompensatoryTimeOffReconciliation) a jednostranný zápis je tichá
        // vada. Porovnání proto padne rovnou do přehledu měsíce.
        $compensatoryReconciliation = $this->overtime
            ->compensatoryTimeOffReconciliationForMany(
                $supplierId,
                $employmentIds,
                $periodStart,
                $periodLastDay,
            );

        $items = [];
        foreach ($employments as $employment) {
            $employmentId = PayrollTimeValue::int($employment['id'] ?? null, 'id');
            [$fundMinutes, $calendarDto] = $this->fundMinutesForMonth(
                $supplierId,
                $employmentId,
                $period,
                $periodStart,
                $periodEnd,
            );

            [$employmentShifts, $plannedMinutes] = $this->summarizeShifts(
                $shifts[$employmentId] ?? [],
            );
            [$employmentEntries, $categories, $actualMinutes] = $this->summarizeEntries(
                $entries[$employmentId] ?? [],
            );
            $incomplete = $incompleteFlags[$employmentId];

            $state = $states[$employmentId] ?? [
                'id' => null,
                'supplier_id' => $supplierId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'status' => 'open',
                'revision_no' => 1,
                'row_version' => 0,
                'approved_at' => null,
                'reopened_at' => null,
                'reopen_reason' => null,
            ];
            $manualSummary = $manualSummaries[$employmentId][$periodStart] ?? null;
            $hourly = isset($hourlyRanges[$employmentId]);
            $selectedSummary = $this->selectedWorkSummary($state, $manualSummary, $importSummaries[$employmentId][$periodStart] ?? null, $periodStart, $actualMinutes, $plannedMinutes, $categories, $hourly);
            $jmhzRevision = $this->repository->jmhzWorkSummaryRevision(
                $supplierId,
                $employmentId,
                $periodStart,
            );
            $item = [
                'employment' => $employment,
                'calendar' => $calendarDto,
                'month' => $state,
                'summary' => [
                    'fund_minutes' => $fundMinutes,
                    'planned_minutes' => $plannedMinutes,
                    ...$selectedSummary,
                    'incomplete' => $incomplete,
                ],
                'jmhz_work_summary' => [
                    'preview' => $state['status'] === 'open'
                        ? $this->publicJmhzPreview(
                            $this->jmhzWorkSummary->preview(
                                $supplierId,
                                $employmentId,
                                $periodStart,
                                manualEvidence: $manualEvidence,
                            ),
                        )
                        : null,
                    'current_revision' => $jmhzRevision,
                ],
                'overtime_limits' => isset($overtimeLimits[$employmentId])
                    ? $overtimeLimits[$employmentId]->toArray()
                    : null,
                'overtime_consents' => $overtimeConsents[$employmentId] ?? [],
                'overtime_protections' => $overtimeProtections[$employmentId] ?? [],
                'overtime_compensations' => $overtimeCompensations[$employmentId] ?? [],
                'compensatory_time_off_check' => isset(
                    $compensatoryReconciliation[$employmentId],
                )
                    ? CompensatoryTimeOffReconciliation::fromRow(
                        $employmentId,
                        $period,
                        $compensatoryReconciliation[$employmentId],
                    )->toArray()
                    : null,
                'shifts' => $employmentShifts,
                'entries' => $employmentEntries,
                'manual_summary' => $manualSummary,
                'hourly_remuneration_in_period' => $hourly,
                'import_amounts_keepable' => isset($keepableImports[$employmentId]),
                'import_summary_comparison' => isset($replacedImports[$employmentId])
                    ? self::importSummaryComparison($replacedImports[$employmentId], $actualMinutes)
                    : null,
                'juvenile_work_time' => array_map(
                    static fn (JuvenileWorkTimeFinding $finding): array => $finding->toArray(),
                    $juvenileFindings[$employmentId] ?? [],
                ),
            ];
            $items[] = $item;
        }

        return [
            'period' => $period,
            'incomplete_only' => $incompleteOnly,
            'employment_id' => $employmentId,
            'items' => $items,
            'total' => $total,
            'open_total' => $openTotal,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Fond pracovní doby měsíce z verzí kalendáře plus DTO nejnovější verze.
     *
     * Vytaženo z {@see overview()} kvůli {@see history()}: ořez dnů podle
     * `valid_from`/`valid_to` je jediné místo, kde se rozhoduje, kolik hodin
     * měsíc „měl". Druhá kopie by se s touhle rozešla při první opravě a
     * historie by ukazovala jiná čísla než tentýž měsíc v přehledu.
     *
     * @return array{int,?array<string,mixed>}
     */
    private function fundMinutesForMonth(
        int $supplierId,
        int $employmentId,
        string $period,
        string $periodStart,
        string $periodEnd,
    ): array {
        $calendarVersions = $this->repository->calendars(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd,
        );
        if ($calendarVersions === []) {
            return [0, null];
        }

        $fundMinutes = 0;
        $combinedDays = [];
        foreach ($calendarVersions as $version) {
            $overrides = $this->repository->calendarDays(
                $supplierId,
                PayrollTimeValue::int($version['id'] ?? null, 'id'),
                $periodStart,
                $periodEnd,
            );
            $calendarMonth = $this->fund->month(
                $period,
                $this->weekPattern($version['week_pattern'] ?? null),
                $overrides,
            );
            foreach ($calendarMonth['days'] as $day) {
                $date = PayrollTimeValue::string($day['date'] ?? null, 'date');
                $validFrom = PayrollTimeValue::string(
                    $version['valid_from'] ?? null,
                    'valid_from',
                );
                if ($date < $validFrom) {
                    continue;
                }
                $validTo = $version['valid_to'] ?? null;
                if ($validTo !== null
                    && $date > PayrollTimeValue::string($validTo, 'valid_to')
                ) {
                    continue;
                }
                $combinedDays[$date] = $day;
            }
        }
        ksort($combinedDays);
        foreach ($combinedDays as $day) {
            $fundMinutes += PayrollTimeValue::int(
                $day['planned_minutes'] ?? null,
                'planned_minutes',
            );
        }
        $latestCalendar = $calendarVersions[array_key_last($calendarVersions)];

        return [$fundMinutes, $latestCalendar + [
            'fund_minutes' => $fundMinutes,
            'days' => array_values($combinedDays),
            'versions' => array_map(
                static fn (array $version): array => [
                    'id' => $version['id'],
                    'name' => $version['name'],
                    'valid_from' => $version['valid_from'],
                    'valid_to' => $version['valid_to'],
                    'row_version' => $version['row_version'],
                ],
                $calendarVersions,
            ),
        ]];
    }

    /**
     * Plánované minuty ze směn; řádky se doplní o čistou dobu a místní časy.
     *
     * @param list<array<string,mixed>> $employmentShifts
     * @return array{list<array<string,mixed>>,int}
     */
    private function summarizeShifts(array $employmentShifts): array
    {
        $plannedMinutes = 0;
        foreach ($employmentShifts as &$shift) {
            $minutes = $this->netMinutes($shift);
            $shift['net_minutes'] = $minutes;
            $shift['starts_at'] = $this->displayInstant($shift, 'starts_at_utc');
            $shift['ends_at'] = $this->displayInstant($shift, 'ends_at_utc');
            $plannedMinutes += $minutes;
        }
        unset($shift);

        return [$employmentShifts, $plannedMinutes];
    }

    /**
     * Minuty po kategoriích a skutečně odpracovaná doba ze zápisů.
     *
     * Skutečnost je součet BĚŽNÉ PRÁCE A PŘESČASU; noční, víkend, svátek a
     * ztížené prostředí jsou příznaky nad toutéž dobou, takže by se přičtením
     * počítaly dvakrát.
     *
     * @param list<array<string,mixed>> $employmentEntries
     * @return array{list<array<string,mixed>>,array<string,int>,int}
     */
    private function summarizeEntries(array $employmentEntries): array
    {
        $categories = array_fill_keys(self::CATEGORIES, 0);
        foreach ($employmentEntries as &$entry) {
            $minutes = $this->netMinutes($entry);
            $entry['net_minutes'] = $minutes;
            $entry['starts_at'] = $this->displayInstant($entry, 'starts_at_utc');
            $entry['ends_at'] = $this->displayInstant($entry, 'ends_at_utc');
            $category = PayrollTimeValue::string($entry['category'] ?? null, 'category');
            if (array_key_exists($category, $categories)) {
                $categories[$category] += $minutes;
            }
        }
        unset($entry);

        return [
            $employmentEntries,
            $categories,
            $categories['regular'] + $categories['overtime'],
        ];
    }

    /** @param array<string,mixed> $state @param ?array<string,mixed> $manualSummary @param ?array<string,mixed> $importSummary @param array<string,int> $categories @return array<string,mixed> */
    private function selectedWorkSummary(array $state, ?array $manualSummary, ?array $importSummary, string $periodStart, int $entryMinutes, int $plannedMinutes, array $categories, bool $hourly): array
    {
        $source = $state['work_source'] ?? 'entries';
        // Měsíčně odměňovaný vztah vidí importovaný měsíc beze změny; hodiny
        // ze souhrnu importu slouží jen hodinovému výpočtu.
        if ($source === 'manual_summary' || ($source === 'import_summary' && $hourly)) {
            $worked = $source === 'manual_summary'
                ? PayrollWorkedTimeSource::fromManualSummary($manualSummary, $periodStart)
                : PayrollWorkedTimeSource::fromImportSummary($importSummary);
            $units = $worked['issues'] === [] ? $worked['worked_duration_units'] : null;
            $minutes = $units === null ? null : PayrollDuration::exactMinutes($units);
            $categories = null;
        } else {
            $minutes = $entryMinutes;
            $units = PayrollDuration::fromMinutes($entryMinutes);
        }
        return ['work_source' => $source, 'worked_duration_units' => $units,
            'actual_minutes' => $minutes, 'difference_minutes' => $minutes === null ? null : $minutes - $plannedMinutes,
            'category_minutes' => $categories];
    }

    /**
     * Historie docházky JEDNOHO vztahu po měsících, sestupně a stránkovaně.
     *
     * Proč samostatná cesta a ne „přehled za dvanáct měsíců": přehled staví na
     * každý řádek náhled JMHZ a stav limitů přesčasu, což je u roku zpětně
     * práce navíc, kterou nikdo nečte. Historie je čtení — stejná čísla, jaká
     * pro tentýž měsíc vrátí {@see overview()}, protože je počítají TYTÉŽ
     * metody ({@see fundMinutesForMonth()}, {@see summarizeShifts()},
     * {@see summarizeEntries()}, {@see looksIncomplete()}).
     *
     * @param ?string $from první měsíc `YYYY-MM`; null = od nástupu vztahu
     * @param ?string $to poslední měsíc `YYYY-MM`; null = po ukončení nebo dnešek
     * @return array<string,mixed>
     */
    public function history(
        int $supplierId,
        int $employmentId,
        ?string $from,
        ?string $to,
        int $limit = self::HISTORY_DEFAULT_LIMIT,
        int $offset = 0,
        int $manualSummaryOffset = 0,
    ): array {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException('Vztah musí být kladné číslo.');
        }
        // Strop je tvrdý i tady: každý měsíc historie stojí několik dotazů
        // (kalendář, jeho výjimky, směny, zápisy, stav měsíce), takže „vypiš
        // celou historii" nesmí jít objednat ani jiným volajícím než akcí.
        $limit = max(1, min(self::HISTORY_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $employment = $this->repository->employment($supplierId, $employmentId);
        if ($employment === null) {
            // Cizí vztah se od neexistujícího nesmí poznat: prázdný výpis by
            // potvrdil, že id v jiné firmě existuje.
            throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
        }

        [$rangeFrom, $rangeTo] = $this->historyRange($supplierId, $employment, $from, $to);
        $periods = [];
        if ($rangeFrom <= $rangeTo) {
            $cursor = $this->periodCursor($rangeTo);
            $first = $this->periodCursor($rangeFrom);
            while ($cursor >= $first) {
                $periods[] = $cursor->format('Y-m');
                $cursor = $cursor->modify('-1 month');
            }
        }

        $page = array_slice($periods, $offset, $limit);
        $periodStarts = array_map(static fn (string $period): string => $period . '-01', $page);
        $selectedManual = $this->repository->selectedManualSummaries($supplierId, [$employmentId], $periodStarts)[$employmentId] ?? [];
        $selectedImport = $this->repository->selectedImportSummaries($supplierId, [$employmentId], $periodStarts)[$employmentId] ?? [];
        $hourlyRanges = $periodStarts === [] ? [] : $this->repository->hourlyRemunerationRanges(
            $supplierId, [$employmentId], min($periodStarts),
            (new \DateTimeImmutable(max($periodStarts)))->modify('last day of this month')->format('Y-m-d'),
        )[$employmentId] ?? [];
        $manualSummaryOffset = max(0, $manualSummaryOffset);
        $manualHistory = $this->repository->manualSummaryHistory($supplierId, $employmentId, $periodStarts, $manualSummaryOffset);
        $items = [];
        foreach ($page as $period) {
            $item = $this->historyMonth($supplierId, $employmentId, $period, $selectedManual[$period . '-01'] ?? null, $selectedImport[$period . '-01'] ?? null, self::rangesOverlapMonth($hourlyRanges, $period . '-01'));
            $history = $manualHistory[$period . '-01'] ?? [];
            $item['manual_summary_history_truncated'] = count($history) > 20;
            $item['manual_summary_history_offset'] = $manualSummaryOffset;
            $item['manual_summary_history'] = array_slice($history, 0, 20);
            $items[] = $item;
        }

        return [
            'employment' => $employment,
            'items' => $items,
            'total' => count($periods),
            'limit' => $limit,
            'offset' => $offset,
            'range' => ['from' => $rangeFrom, 'to' => $rangeTo],
        ];
    }

    /** @return array<string,mixed> */
    /** @param list<array{effective_from:string,effective_to:?string}> $ranges */
    private static function rangesOverlapMonth(array $ranges, string $periodStart): bool
    {
        $last = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        foreach ($ranges as $range) {
            if ($range['effective_from'] <= $last && ($range['effective_to'] === null || $range['effective_to'] >= $periodStart)) {
                return true;
            }
        }
        return false;
    }

    private function historyMonth(int $supplierId, int $employmentId, string $period, ?array $manualSummary, ?array $importSummary, bool $hourly): array
    {
        [$periodStart, $periodEnd, $startsAtUtc, $endsAtUtc] = $this->periodBounds($period);
        [$fundMinutes, $calendarDto] = $this->fundMinutesForMonth(
            $supplierId,
            $employmentId,
            $period,
            $periodStart,
            $periodEnd,
        );
        [$employmentShifts, $plannedMinutes] = $this->summarizeShifts(
            $this->startingInPeriod(
                $this->repository->shifts($supplierId, $startsAtUtc, $endsAtUtc, $employmentId),
                $period,
            ),
        );
        [$employmentEntries, $categories, $actualMinutes] = $this->summarizeEntries(
            $this->startingInPeriod(
                $this->repository->entries($supplierId, $startsAtUtc, $endsAtUtc, $employmentId),
                $period,
            ),
        );
        $states = $this->repository->monthStates($supplierId, $periodStart, $employmentId);
        $state = $states[0] ?? [
            'id' => null,
            'supplier_id' => $supplierId,
            'employment_id' => $employmentId,
            'period_start' => $periodStart,
            'status' => 'open',
            'revision_no' => 1,
            'row_version' => 0,
            'approved_at' => null,
            'reopened_at' => null,
            'reopen_reason' => null,
        ];

        return [
            'period' => $period,
            'month' => $state,
            'summary' => [
                'fund_minutes' => $fundMinutes,
                'planned_minutes' => $plannedMinutes,
                ...$this->selectedWorkSummary($state, $manualSummary, $importSummary, $periodStart, $actualMinutes, $plannedMinutes, $categories, $hourly),
                // „Má kalendář?" se tu pozná z téhož dotazu, který počítá fond
                // (`calendars()`), zatímco přehled na to má hromadný dotaz přes
                // celou stránku (`employmentIdsWithCalendar()`). Podmínka je
                // v obou stejná, jen jednou pro jeden vztah a jednou pro sto.
                'incomplete' => $this->looksIncomplete(
                    $calendarDto !== null,
                    $employmentShifts,
                    $employmentEntries,
                ),
            ],
            'shift_count' => count($employmentShifts),
            'entry_count' => count($employmentEntries),
            'manual_summary' => $manualSummary,
            'hourly_remuneration_in_period' => $hourly,
        ];
    }

    /**
     * Rozsah historie: od měsíce nástupu po měsíc ukončení, nejdál po dnešek.
     *
     * Rozšiřuje se o měsíce, ve kterých vztah něco v docházce má
     * ({@see PayrollTimeRepository::dataPeriodEdges()}). Převzatá evidence
     * z migrace umí ležet mimo interval vztahu a takový měsíc by jinak ve
     * výpisu tiše chyběl, přestože v něm hodiny jsou.
     *
     * @param array<string,mixed> $employment
     * @return array{string,string}
     */
    private function historyRange(
        int $supplierId,
        array $employment,
        ?string $from,
        ?string $to,
    ): array {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))
            ->format('Y-m');
        $started = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
        $ended = $employment['end_date'] ?? null;
        $rangeFrom = is_string($started) ? substr($started, 0, 7) : $today;
        $rangeTo = is_string($ended) ? min(substr($ended, 0, 7), $today) : $today;

        $edges = $this->repository->dataPeriodEdges(
            $supplierId,
            PayrollTimeValue::int($employment['id'] ?? null, 'id'),
        );
        $withData = [];
        foreach (['min', 'max'] as $bound) {
            $value = $edges['months'][$bound];
            if (is_string($value)) {
                $withData[] = substr($value, 0, 7);
            }
        }
        foreach ($edges['instants'] as $instant) {
            $withData[] = substr($this->displayInstant($instant, 'starts_at_utc'), 0, 7);
        }
        if ($withData !== []) {
            $rangeFrom = min($rangeFrom, min($withData));
            $rangeTo = max($rangeTo, max($withData));
        }

        // Výslovně zadaný výřez rozsah jen ZUŽUJE: „od ledna" nesmí historii
        // natáhnout před nástup ani za dnešek.
        if ($from !== null) {
            $rangeFrom = max($rangeFrom, $this->periodCursor($from)->format('Y-m'));
        }
        if ($to !== null) {
            $rangeTo = min($rangeTo, $this->periodCursor($to)->format('Y-m'));
        }

        return [$rangeFrom, $rangeTo];
    }

    private function periodCursor(string $period): \DateTimeImmutable
    {
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $period . '-01',
            new \DateTimeZone('Europe/Prague'),
        );
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('Měsíc musí být ve formátu YYYY-MM.');
        }
        return $start;
    }

    /**
     * Rozpracovanost řádku docházky bez stavby řádku.
     *
     * Musí odpovídat `summary.incomplete` v přehledu — proto se počítá jednou
     * a stavěný řádek si výsledek jen převezme.
     *
     * @param list<array<string,mixed>> $employmentShifts
     * @param list<array<string,mixed>> $employmentEntries
     */
    private function looksIncomplete(
        bool $hasCalendar,
        array $employmentShifts,
        array $employmentEntries,
    ): bool {
        if (!$hasCalendar) {
            return true;
        }
        $plannedMinutes = 0;
        foreach ($employmentShifts as $shift) {
            if (($shift['status'] ?? null) === 'draft') {
                return true;
            }
            $plannedMinutes += $this->netMinutes($shift);
        }
        $actualMinutes = 0;
        foreach ($employmentEntries as $entry) {
            if (($entry['status'] ?? null) === 'draft') {
                return true;
            }
            $category = PayrollTimeValue::string($entry['category'] ?? null, 'category');
            if ($category === 'regular' || $category === 'overtime') {
                $actualMinutes += $this->netMinutes($entry);
            }
        }

        return $plannedMinutes > 0 && $actualMinutes === 0;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createCalendar(
        int $supplierId,
        int $employmentId,
        array $input,
        ?int $userId,
    ): array {
        $name = $this->requiredString($input, 'name', 191);
        $timezone = $this->timezone($input['timezone'] ?? 'Europe/Prague');
        $scheduleType = $this->enum(
            $input,
            'schedule_type',
            ['regular', 'irregular', 'shift'],
        );
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('valid_to nesmí předcházet valid_from.');
        }
        $expectedVersion = $this->nonNegativeInt($input, 'row_version');
        $patternInput = $input['week_pattern'] ?? null;
        if (!is_array($patternInput)) {
            throw new \InvalidArgumentException('week_pattern musí obsahovat minuty pro dny 1 až 7.');
        }
        $pattern = [];
        $weeklyMinutes = 0;
        for ($day = 1; $day <= 7; ++$day) {
            $raw = $patternInput[(string) $day] ?? $patternInput[$day] ?? 0;
            if (!is_int($raw) || $raw < 0 || $raw > 1440) {
                throw new \InvalidArgumentException(
                    "week_pattern.{$day} musí být počet minut 0 až 1440."
                );
            }
            $pattern[$day] = $raw;
            $weeklyMinutes += $raw;
        }
        $days = $this->calendarDays($input['days'] ?? []);

        return $this->repository->createCalendarVersion(
            $supplierId,
            $employmentId,
            $name,
            $timezone,
            $scheduleType,
            $pattern,
            $weeklyMinutes,
            $validFrom,
            $validTo,
            $expectedVersion,
            $this->nonNegativeInt($input, 'month_row_version'),
            $days,
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{shift:array<string,mixed>,month:array<string,mixed>}
     */
    public function saveShift(
        int $supplierId,
        array $input,
        ?int $userId,
    ): array {
        $employmentId = $this->positiveInt($input, 'employment_id');
        $timezone = $this->timezone($input['timezone'] ?? null);
        $interval = PayrollTimeInterval::fromIso(
            $this->requiredString($input, 'starts_at', 40),
            $this->requiredString($input, 'ends_at', 40),
            $timezone,
        );
        $breakMinutes = $this->nonNegativeInt($input, 'break_minutes');
        if ($breakMinutes >= $interval->durationMinutes) {
            throw new \InvalidArgumentException('Přestávka musí být kratší než směna.');
        }
        $standbyMinutes = $this->nonNegativeInt($input, 'standby_minutes');
        if ($standbyMinutes > $interval->durationMinutes) {
            throw new \InvalidArgumentException('Pohotovost nesmí přesáhnout délku směny.');
        }
        $publish = $this->bool($input, 'publish');
        $localStart = (new \DateTimeImmutable($this->requiredString($input, 'starts_at', 40)))
            ->setTimezone(new \DateTimeZone($timezone));
        $periodStart = $localStart->format('Y-m-01');

        return $this->repository->saveShift(
            $supplierId,
            $employmentId,
            $periodStart,
            $interval->startsAtUtc,
            $interval->endsAtUtc,
            $interval->timezoneName,
            $breakMinutes,
            $this->bool($input, 'remote_work'),
            $standbyMinutes,
            $publish,
            $this->nullablePositiveInt($input, 'supersedes_id'),
            $this->nullablePositiveInt($input, 'calendar_id'),
            $this->nonNegativeInt($input, 'row_version'),
            $this->nonNegativeInt($input, 'month_row_version'),
            $userId,
        );
    }

    /**
     * Zrušení chybné směny (`shift`) nebo záznamu času (`entry`) bez náhrady.
     *
     * @param 'shift'|'entry' $kind
     * @param array<string,mixed> $input
     * @return array<string,mixed> měsíc po změně
     */
    public function cancelRecord(int $supplierId, string $kind, int $id, array $input, ?int $userId): array
    {
        if ($kind !== 'shift' && $kind !== 'entry') {
            throw new \InvalidArgumentException('Neznámý druh záznamu docházky.');
        }

        return $this->repository->cancelRecord(
            $supplierId,
            $kind,
            $this->positiveInt($input, 'employment_id'),
            $id,
            $this->nonNegativeInt($input, 'row_version'),
            $this->nonNegativeInt($input, 'month_row_version'),
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{entry:array<string,mixed>,month:array<string,mixed>}
     */
    public function saveEntry(
        int $supplierId,
        array $input,
        ?int $userId,
        string $sourceKind = 'manual',
        ?string $sourceReference = null,
        ?string $sourceHash = null,
    ): array {
        $employmentId = $this->positiveInt($input, 'employment_id');
        $input = $this->withSupersededEntryValues($supplierId, $employmentId, $input);
        $category = $this->enum($input, 'category', self::CATEGORIES);
        $timezone = $this->timezone($input['timezone'] ?? null);
        $interval = PayrollTimeInterval::fromIso(
            $this->requiredString($input, 'starts_at', 40),
            $this->requiredString($input, 'ends_at', 40),
            $timezone,
        );
        $breakMinutes = $this->nonNegativeInt($input, 'break_minutes');
        if ($breakMinutes >= $interval->durationMinutes) {
            throw new \InvalidArgumentException('Přestávka musí být kratší než časový interval.');
        }
        $localStart = (new \DateTimeImmutable($this->requiredString($input, 'starts_at', 40)))
            ->setTimezone(new \DateTimeZone($timezone));
        $periodStart = $localStart->format('Y-m-01');
        if ($sourceKind === 'manual') {
            $sourceHash = random_bytes(32);
        }
        if ($sourceHash === null || strlen($sourceHash) !== 32) {
            throw new \InvalidArgumentException('Interní deduplikační hash času není platný.');
        }

        return $this->repository->saveEntry(
            $supplierId,
            $employmentId,
            $periodStart,
            $category,
            $interval->startsAtUtc,
            $interval->endsAtUtc,
            $interval->timezoneName,
            $breakMinutes,
            $sourceKind,
            $sourceReference,
            $sourceHash,
            $this->nullablePositiveInt($input, 'supersedes_id'),
            $this->nonNegativeInt($input, 'row_version'),
            $this->nonNegativeInt($input, 'month_row_version'),
            $userId,
            // § 117 — počet ztěžujících vlivů PRÁVĚ TOHOTO zápisu. Sloupec na to
            // je od migrace 1625, ale zapsat ho dosud nešlo, takže se výpočet
            // příplatku opíral výhradně o obvyklý počet na zásadě vztahu.
            // Prázdno není nula: znamená „u tohoto zápisu se od obvyklého stavu
            // pracoviště nic neliší".
            $this->difficultyFactorCount($input, $category),
        );
    }

    /**
     * Dávkový zápis dnů z měsíční mřížky docházky.
     *
     * Proč to NENÍ jedna transakce nad celou dávkou: měsíc se zamyká
     * `FOR UPDATE` a každý uložený den mu zvedne `row_version`. Držet ten zámek
     * nad pěti sty dny by z běžného „vyplnit pracovní dny" udělalo minutu, po
     * kterou se k témuž měsíci nedostane nikdo jiný. Každá buňka je proto
     * vlastní transakce — dávka je částečně uložitelná ze své podstaty a
     * volající se dozví, co prošlo a co ne, u KONKRÉTNÍ buňky.
     *
     * `month_row_version` posílá klient jen pro PRVNÍ buňku daného vztahu; dál
     * si ho dávka drží z odpovědi předchozího zápisu. Kdyby to nedělala,
     * druhý den téhož člověka by vždycky spadl na optimistický zámek, který
     * zvedl náš vlastní předchozí zápis.
     *
     * @param array<string,mixed> $input
     * @return array{
     *     saved:int,
     *     failures:list<array<string,mixed>>,
     *     month_row_versions:array<int,int>
     * }
     */
    public function saveEntryBatch(int $supplierId, array $input, ?int $userId): array
    {
        $cells = $input['cells'] ?? null;
        if (!is_array($cells) || !array_is_list($cells)) {
            throw new \InvalidArgumentException('cells musí být seznam buněk.');
        }
        if ($cells === []) {
            throw new \InvalidArgumentException('Dávka neobsahuje žádnou buňku k uložení.');
        }
        if (count($cells) > self::BATCH_MAX_CELLS) {
            throw new \InvalidArgumentException(sprintf(
                'Najednou lze uložit nejvýše %d buněk docházky.',
                self::BATCH_MAX_CELLS,
            ));
        }
        $defaultTimezone = isset($input['timezone']) ? $input['timezone'] : null;

        $saved = 0;
        $failures = [];
        /** @var array<int,int> $monthVersions */
        $monthVersions = [];
        /** @var array<int,string> $stale */
        $stale = [];

        foreach ($cells as $index => $cell) {
            if (!is_array($cell)) {
                $failures[] = self::batchFailure($index, null, '', '', 'validation_failed', 'Buňka musí být objekt.');
                continue;
            }
            $employmentId = null;
            try {
                $employmentId = $this->positiveInt($cell, 'employment_id');
            } catch (\InvalidArgumentException $e) {
                $failures[] = self::batchFailure($index, null, '', '', 'validation_failed', $e->getMessage());
                continue;
            }
            $date = is_string($cell['starts_at'] ?? null) ? substr($cell['starts_at'], 0, 10) : '';
            $category = is_string($cell['category'] ?? null) ? $cell['category'] : '';

            // Jakmile u vztahu jednou selhal optimistický zámek, nevíme, na jaké
            // verzi měsíc je. Hádat ji dál by znamenalo buď tiše přepsat cizí
            // změnu, nebo sypat matoucí konflikty — tak se to řekne rovnou.
            if (isset($stale[$employmentId])) {
                $failures[] = self::batchFailure(
                    $index,
                    $employmentId,
                    $date,
                    $category,
                    'stale_after_conflict',
                    'Dřívější den téhož zaměstnance se neuložil, takže se zbytek jeho dnů'
                    . ' nezapisoval. Načtěte měsíc znovu a zkuste to na něm.',
                );
                continue;
            }

            $entryInput = $cell;
            // Oprava bez timezone si ji bere z opravované revize, ne z výchozí dávky.
            if (!isset($entryInput['timezone']) && $defaultTimezone !== null
                && (($entryInput['supersedes_id'] ?? null) === null || array_key_exists('timezone', $entryInput))
            ) {
                $entryInput['timezone'] = $defaultTimezone;
            }
            if (isset($monthVersions[$employmentId])) {
                $entryInput['month_row_version'] = $monthVersions[$employmentId];
            }

            try {
                $result = $this->saveEntry($supplierId, $entryInput, $userId);
                $monthVersions[$employmentId] = PayrollTimeValue::int(
                    $result['month']['row_version'] ?? null,
                    'row_version',
                );
                $saved += 1;
            } catch (PayrollTimeConflictException $e) {
                $stale[$employmentId] = 'conflict';
                $failures[] = self::batchFailure(
                    $index,
                    $employmentId,
                    $date,
                    $category,
                    'row_version_conflict',
                    $e->getMessage(),
                );
            } catch (PayrollTimeLockedException $e) {
                $stale[$employmentId] = 'locked';
                $failures[] = self::batchFailure(
                    $index,
                    $employmentId,
                    $date,
                    $category,
                    'payroll_time_locked',
                    $e->getMessage(),
                );
            } catch (\InvalidArgumentException $e) {
                // Neplatná buňka se odmítla ještě před dotykem měsíce, takže
                // ostatní dny téhož člověka můžou pokračovat na téže verzi.
                $failures[] = self::batchFailure(
                    $index,
                    $employmentId,
                    $date,
                    $category,
                    'validation_failed',
                    $e->getMessage(),
                );
            }
        }

        return [
            'saved' => $saved,
            'failures' => $failures,
            'month_row_versions' => $monthVersions,
        ];
    }

    /** @return array<string,mixed> */
    private static function batchFailure(
        int $index,
        ?int $employmentId,
        string $date,
        string $category,
        string $code,
        string $message,
    ): array {
        return [
            'index' => $index,
            'employment_id' => $employmentId,
            'date' => $date,
            'category' => $category,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * Oprava zápisu (`supersedes_id`) je nová revize téhož záznamu: co klient
     * neposlal, přebírá z opravované revize. Výslovný null počet vlivů maže.
     * Počet vlivů se přebírá jen při stejné kategorii — u jiné by ho validace
     * odmítla a z ručního zadání by se stala chyba, kterou nikdo neposlal.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function withSupersededEntryValues(int $supplierId, int $employmentId, array $input): array
    {
        $supersedesId = $this->nullablePositiveInt($input, 'supersedes_id');
        if ($supersedesId === null) {
            return $input;
        }
        $current = $this->repository->currentEntryRevision($supplierId, $employmentId, $supersedesId);
        if ($current === null) {
            return $input;
        }
        // Okamžik s literálním 'Z': '+00:00' by kontrola offsetu odmítla v každé
        // timezone mimo UTC, i v té uložené.
        $utc = new \DateTimeZone('UTC');
        $stored = [
            'category' => $current['category'] ?? null,
            'starts_at' => (new \DateTimeImmutable((string) $current['starts_at_utc'], $utc))
                ->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => (new \DateTimeImmutable((string) $current['ends_at_utc'], $utc))
                ->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $current['timezone_name'] ?? null,
            'break_minutes' => $current['break_minutes'] ?? null,
        ];
        foreach ($stored as $key => $value) {
            if (!array_key_exists($key, $input)) {
                $input[$key] = $value;
            }
        }
        if (!array_key_exists('difficulty_factor_count', $input)
            && $input['category'] === ($current['category'] ?? null)
            && ($current['difficulty_factor_count'] ?? null) !== null
        ) {
            $input['difficulty_factor_count'] = (int) $current['difficulty_factor_count'];
        }

        return $input;
    }

    /**
     * @param array<string,mixed> $input
     */
    private function difficultyFactorCount(array $input, string $category): ?int
    {
        $value = $this->nullablePositiveInt($input, 'difficulty_factor_count');
        if ($value === null) {
            return null;
        }
        if ($value > 255) {
            throw new \InvalidArgumentException(
                'Počet ztěžujících vlivů podle § 117 musí být 1 až 255.'
            );
        }
        if ($category !== 'difficult_environment') {
            // Násobit noční nebo víkendový příplatek počtem vlivů zákon
            // nedovoluje; tichý přeplatek by se poznal až při kontrole.
            throw new \InvalidArgumentException(
                'Počet ztěžujících vlivů lze zadat jen u zápisu ve ztíženém pracovním prostředí.'
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function approve(
        int $supplierId,
        string $period,
        array $input,
        ?int $userId,
    ): array {
        [$periodStart] = $this->periodBounds($period);
        $jmhzWorkSummary = null;
        if (array_key_exists('jmhz_work_summary', $input)) {
            if (!is_array($input['jmhz_work_summary'])) {
                throw new \InvalidArgumentException(
                    'Pracovní souhrn JMHZ musí být objekt.',
                );
            }
            $jmhzWorkSummary = $input['jmhz_work_summary'];
        }
        return $this->repository->approveMonth(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $periodStart,
            $this->nonNegativeInt($input, 'row_version'),
            $jmhzWorkSummary,
            $userId,
        );
    }

    /**
     * Souhlas zaměstnance s prací přesčas nad nařízený rozsah (§ 93 odst. 3).
     *
     * Bez téhle evidence nejde 151. hodinu přesčasu odlišit od porušení zákona,
     * proto je to samostatná právní skutečnost s vlastní platností, ne příznak
     * na docházce.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveOvertimeConsent(
        int $supplierId,
        array $input,
        ?int $userId,
    ): array {
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException(
                'Konec platnosti souhlasu nesmí předcházet jeho začátku.',
            );
        }

        return $this->overtime->saveConsent(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $this->nullablePositiveInt($input, 'id'),
            $validFrom,
            $validTo,
            $this->nullableString($input['document_reference'] ?? null, 191),
            $this->nullableString($input['note'] ?? null, 500),
            $this->nonNegativeInt($input, 'row_version'),
            $userId,
        );
    }

    /**
     * Zákaz práce přesčas u chráněné skupiny (§ 240 odst. 3 zákoníku práce).
     *
     * Mladistvost se sem nezapisuje — plyne z data narození zaměstnance
     * (§ 350 odst. 2) a druhý zdroj pravdy by se s prvním jen rozešel.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveOvertimeProtection(
        int $supplierId,
        array $input,
        ?int $userId,
    ): array {
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException(
                'Konec ochrany nesmí předcházet jejímu začátku.',
            );
        }

        return $this->overtime->saveProtection(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $this->nullablePositiveInt($input, 'id'),
            $this->enum($input, 'protection', OvertimeProtectionWindow::KINDS),
            $validFrom,
            $validTo,
            $this->nullableString($input['document_reference'] ?? null, 191),
            $this->nullableString($input['note'] ?? null, 500),
            $this->nonNegativeInt($input, 'row_version'),
            $userId,
        );
    }

    /**
     * Náhradní volno za práci přesčas (§ 93 odst. 5 zákoníku práce).
     *
     * Rozhodné je datum PŘESČASU, ne datum čerpání volna — vyjímá se „práce
     * přesčas, za kterou bylo poskytnuto náhradní volno".
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveOvertimeCompensation(
        int $supplierId,
        array $input,
        ?int $userId,
    ): array {
        $overtimeDate = $this->date($input['overtime_date'] ?? null, 'overtime_date');
        $grantedOn = $this->nullableDate($input['granted_on'] ?? null, 'granted_on');
        if ($grantedOn !== null && $grantedOn < $overtimeDate) {
            throw new \InvalidArgumentException(
                'Náhradní volno nelze vybrat dřív, než byl přesčas odpracován.',
            );
        }
        $minutes = $this->positiveInt($input, 'minutes');
        if ($minutes > 1440) {
            throw new \InvalidArgumentException(
                'Náhradní volno za jeden den nesmí přesáhnout 24 hodin.',
            );
        }

        return $this->overtime->saveCompensation(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $this->nullablePositiveInt($input, 'id'),
            $overtimeDate,
            $minutes,
            $grantedOn,
            $this->nullableString($input['document_reference'] ?? null, 191),
            $this->nullableString($input['note'] ?? null, 500),
            $this->nonNegativeInt($input, 'row_version'),
            $userId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function overtimeAveragingPeriods(int $supplierId): array
    {
        return $this->overtime->averagingPeriods($supplierId);
    }

    /**
     * Vyrovnávací období podle § 93 odst. 4.
     *
     * Delší období než zákonných 26 týdnů smí vymezit „jen kolektivní
     * smlouva", proto se bez odkazu na ni neuloží — a to už tady, aby uživatel
     * dostal větu, ne hlášku o porušení databázového omezení.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveOvertimeAveragingPeriod(
        int $supplierId,
        array $input,
        ?int $userId,
    ): array {
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException(
                'Konec platnosti vyrovnávacího období nesmí předcházet jeho začátku.',
            );
        }
        $basis = $this->enum($input, 'basis', OvertimeLimits::BASES);
        $weeks = $this->positiveInt($input, 'weeks');
        $reference = $this->nullableString($input['collective_agreement_reference'] ?? null, 255);
        if ($basis === OvertimeLimits::BASIS_STATUTORY) {
            if ($weeks > 26) {
                throw new \InvalidArgumentException(
                    'Bez kolektivní smlouvy smí vyrovnávací období činit nejvýše '
                    . '26 týdnů po sobě jdoucích (§ 93 odst. 4 zákoníku práce).',
                );
            }
            $reference = null;
        } else {
            if ($weeks > 52) {
                throw new \InvalidArgumentException(
                    'Ani kolektivní smlouva nesmí vymezit vyrovnávací období delší '
                    . 'než 52 týdnů po sobě jdoucích (§ 93 odst. 4 zákoníku práce).',
                );
            }
            if ($reference === null) {
                throw new \InvalidArgumentException(
                    'Vyrovnávací období delší než zákonné musí odkazovat na kolektivní '
                    . 'smlouvu, která ho vymezuje (§ 93 odst. 4 zákoníku práce).',
                );
            }
        }

        return $this->overtime->saveAveragingPeriod(
            $supplierId,
            $this->nullablePositiveInt($input, 'id'),
            $validFrom,
            $validTo,
            $weeks,
            $basis,
            $reference,
            $this->nullableString($input['note'] ?? null, 500),
            $this->nonNegativeInt($input, 'row_version'),
            $userId,
        );
    }

    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function publicJmhzPreview(array $preview): array
    {
        unset($preview['source_snapshot_json']);
        return $preview;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function reopen(
        int $supplierId,
        string $period,
        array $input,
        ?int $userId,
    ): array {
        [$periodStart] = $this->periodBounds($period);
        $reason = $this->requiredString($input, 'reason', 500);
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('Důvod znovuotevření musí mít alespoň 5 znaků.');
        }
        return $this->repository->reopenMonth(
            $supplierId,
            $this->positiveInt($input, 'employment_id'),
            $periodStart,
            $this->nonNegativeInt($input, 'row_version'),
            $reason,
            $userId,
        );
    }

    /** @return array{string,string,string,string} */
    public function periodBounds(string $period): array
    {
        $start = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $period . '-01',
            new \DateTimeZone('Europe/Prague'),
        );
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $end = $start->modify('first day of next month');
        $utc = new \DateTimeZone('UTC');
        return [
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,list<array<string,mixed>>>
     */
    private function groupByEmployment(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $employmentId = PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            );
            $grouped[$employmentId][] = $row;
        }
        return $grouped;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function startingInPeriod(array $rows, string $period): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => substr(
                $this->displayInstant($row, 'starts_at_utc'),
                0,
                7,
            ) === $period,
        ));
    }

    /** @param array<string,mixed> $row */
    private function netMinutes(array $row): int
    {
        $start = new \DateTimeImmutable(
            PayrollTimeValue::string($row['starts_at_utc'] ?? null, 'starts_at_utc'),
            new \DateTimeZone('UTC'),
        );
        $end = new \DateTimeImmutable(
            PayrollTimeValue::string($row['ends_at_utc'] ?? null, 'ends_at_utc'),
            new \DateTimeZone('UTC'),
        );
        return max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60)
            - PayrollTimeValue::int($row['break_minutes'] ?? 0, 'break_minutes'));
    }

    /** @param array<string,mixed> $row */
    private function displayInstant(array $row, string $field): string
    {
        $utc = new \DateTimeImmutable(
            PayrollTimeValue::string($row[$field] ?? null, $field),
            new \DateTimeZone('UTC'),
        );
        return $utc->setTimezone(new \DateTimeZone(
            PayrollTimeValue::string($row['timezone_name'] ?? null, 'timezone_name'),
        ))
            ->format('Y-m-d\TH:i:sP');
    }

    /** @return array<int,int> */
    private function weekPattern(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('week_pattern musí být objekt.');
        }
        $result = [];
        foreach ($value as $day => $minutes) {
            if (!is_int($day) || !is_int($minutes)) {
                throw new \UnexpectedValueException('week_pattern obsahuje neplatnou hodnotu.');
            }
            $result[$day] = $minutes;
        }
        return $result;
    }

    /**
     * @param mixed $raw
     * @return list<array{
     *   day_date:string,
     *   day_kind:string,
     *   planned_minutes:int,
     *   holiday_code:?string,
     *   holiday_name:?string,
     *   note:?string
     * }>
     */
    private function calendarDays(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('days musí být pole kalendářních výjimek.');
        }
        $result = [];
        $seen = [];
        foreach ($raw as $index => $day) {
            if (!is_array($day)) {
                throw new \InvalidArgumentException("days.{$index} není platný objekt.");
            }
            $dayInput = [];
            foreach ($day as $key => $value) {
                if (is_string($key)) {
                    $dayInput[$key] = $value;
                }
            }
            $date = $this->date($dayInput['day_date'] ?? null, "days.{$index}.day_date");
            if (isset($seen[$date])) {
                throw new \InvalidArgumentException("Datum {$date} je v kalendáři uvedeno vícekrát.");
            }
            $seen[$date] = true;
            $kind = $this->enum(
                $dayInput,
                'day_kind',
                ['workday', 'non_working', 'holiday'],
            );
            $minutes = $this->nonNegativeInt($dayInput, 'planned_minutes');
            if ($minutes > 1440) {
                throw new \InvalidArgumentException('planned_minutes nesmí být vyšší než 1440.');
            }
            $holidayCode = $this->nullableString($dayInput['holiday_code'] ?? null, 32);
            $holidayName = $this->nullableString($dayInput['holiday_name'] ?? null, 191);
            if ($kind === 'holiday' && ($holidayCode === null || $holidayName === null)) {
                throw new \InvalidArgumentException('Vlastní svátek musí mít kód a název.');
            }
            $result[] = [
                'day_date' => $date,
                'day_kind' => $kind,
                'planned_minutes' => $minutes,
                'holiday_code' => $holidayCode,
                'holiday_name' => $holidayName,
                'note' => $this->nullableString($dayInput['note'] ?? null, 255),
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("{$key} je povinné.");
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("{$key} je příliš dlouhé.");
        }
        return $value;
    }

    private function nullableString(mixed $raw, int $max): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Textová hodnota není platná.');
        }
        $value = trim($raw);
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException('Textová hodnota je příliš dlouhá.');
        }
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowed
     */
    private function enum(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$key} musí být jedna z hodnot: " . implode(', ', $allowed) . '.'
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function positiveInt(array $input, string $key): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$key} musí být kladné celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function nullablePositiveInt(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }
        return $this->positiveInt($input, $key);
    }

    /** @param array<string,mixed> $input */
    private function nonNegativeInt(array $input, string $key): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$key} musí být nezáporné celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$key} musí být boolean.");
        }
        return $value;
    }

    /** Sdílená kontrola s evidencí pracovních cest, viz PayrollTimeInterval. */
    private function timezone(mixed $raw): string
    {
        return PayrollTimeInterval::timezoneName($raw);
    }

    private function date(mixed $raw, string $field): string
    {
        if (!is_string($raw)) {
            throw new \InvalidArgumentException("{$field} musí být datum YYYY-MM-DD.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException("{$field} musí být datum YYYY-MM-DD.");
        }
        return $raw;
    }

    private function nullableDate(mixed $raw, string $field): ?string
    {
        return $raw === null || $raw === '' ? null : $this->date($raw, $field);
    }
}
