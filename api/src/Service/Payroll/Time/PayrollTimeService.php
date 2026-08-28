<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollOvertimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Time\Overtime\CompensatoryTimeOffReconciliation;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimits;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeProtectionWindow;
use MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService;

final class PayrollTimeService
{
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
    ) {}

    /** @return array<string,mixed> */
    public function overview(
        int $supplierId,
        string $period,
        bool $incompleteOnly,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employmentId = null,
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
        $employments = $this->repository->employments($supplierId, $periodStart, $periodEnd);
        // Zúžení na jeden vztah padá STEJNĚ brzy jako „jen nedokončené"
        // a stránkování — před stavbou řádků. Dokud běželo v prohlížeči nad
        // načtenou stránkou, vztah z jiné strany se tiše neprojevil: lišta
        // zmizela a seznam zůstal celý, což vypadá jako prázdný výsledek,
        // ne jako nefunkční filtr.
        if ($employmentId !== null) {
            $employments = array_values(array_filter(
                $employments,
                fn (array $employment): bool => PayrollTimeValue::int(
                    $employment['id'] ?? null,
                    'id',
                ) === $employmentId,
            ));
        }
        $shifts = $this->groupByEmployment(
            $this->startingInPeriod(
                $this->repository->shifts($supplierId, $startsAtUtc, $endsAtUtc),
                $period,
            ),
        );
        $entries = $this->groupByEmployment(
            $this->startingInPeriod(
                $this->repository->entries($supplierId, $startsAtUtc, $endsAtUtc),
                $period,
            ),
        );
        $states = [];
        foreach ($this->repository->monthStates($supplierId, $periodStart) as $state) {
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
        $periodLastDay = (new \DateTimeImmutable($periodEnd))
            ->modify('-1 day')
            ->format('Y-m-d');
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
            $calendarVersions = $this->repository->calendars(
                $supplierId,
                $employmentId,
                $periodStart,
                $periodEnd,
            );
            $calendarDto = null;
            $fundMinutes = 0;
            if ($calendarVersions !== []) {
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
                $calendarDto = $latestCalendar + [
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
                ];
            }

            $employmentShifts = $shifts[$employmentId] ?? [];
            $employmentEntries = $entries[$employmentId] ?? [];
            $plannedMinutes = 0;
            foreach ($employmentShifts as &$shift) {
                $minutes = $this->netMinutes($shift);
                $shift['net_minutes'] = $minutes;
                $shift['starts_at'] = $this->displayInstant($shift, 'starts_at_utc');
                $shift['ends_at'] = $this->displayInstant($shift, 'ends_at_utc');
                $plannedMinutes += $minutes;
            }
            unset($shift);

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
            $actualMinutes = $categories['regular'] + $categories['overtime'];
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
                    'actual_minutes' => $actualMinutes,
                    'difference_minutes' => $actualMinutes - $plannedMinutes,
                    'category_minutes' => $categories,
                    'incomplete' => $incomplete,
                ],
                'jmhz_work_summary' => [
                    'preview' => $state['status'] === 'open'
                        ? $this->publicJmhzPreview(
                            $this->jmhzWorkSummary->preview(
                                $supplierId,
                                $employmentId,
                                $periodStart,
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
            ];
            $items[] = $item;
        }

        return [
            'period' => $period,
            'incomplete_only' => $incompleteOnly,
            'employment_id' => $employmentId,
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
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
            if (!isset($entryInput['timezone']) && $defaultTimezone !== null) {
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
