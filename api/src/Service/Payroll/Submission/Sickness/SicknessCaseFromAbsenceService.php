<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessRepository;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;

/**
 * Případ dávky ze schválené nebo převzaté absence.
 *
 * ## Proč
 *
 * Lhůta NEMPRI podle § 97 odst. 2 zák. č. 187/2006 Sb. běží od 15. dne
 * neschopnosti bez ohledu na to, jestli si toho někdo všiml. Dokud případ
 * zakládala jen účetní rukou, byla hlídaná jen ta lhůta, na kterou už někdo
 * myslel — a schválená neschopnost v absencích vyrobila náhradu mzdy, ale
 * žádný případ. Schválení absence druhu, ze kterého plyne dávka, proto případ
 * založí, naváže na existující, nebo prodlouží navazující.
 *
 * Totéž platí pro převod mezd z předchozího programu
 * ({@see self::onTakenOver()}): rozběhnutá neschopnost přes první měsíc vedení
 * mezd v MyÚčtu je případ, jehož NEMPRI často podal předchozí program, ale
 * HZUPN k návratu do práce podá už MyÚčto.
 *
 * ## Den vzniku události
 *
 * `incapacity_from` je SKUTEČNÝ den vzniku: první den řetězu navazujících
 * absencí minus dny téže neschopnosti, které padly ještě u předchozího plátce
 * (`sickness_window_carried_days`). Od něj se počítá lhůta, kontrola při
 * založení i kontrola při přípravě podání; kdyby každá vycházela z jiného dne,
 * případ by šel založit, ale NEMPRI by nešlo připravit.
 *
 * ## Co patří předchozímu programu
 *
 * Podání, jehož lhůta začala běžet před prvním měsícem vedení mezd v MyÚčtu
 * (`payroll_module_state.start_period`), podával předchozí program. Převzatý
 * případ ho nese jako vyřízené předchozím programem a MyÚčto ho nepřipravuje;
 * hlídá jen to, co připadá na dobu, kdy mzdy vede MyÚčto. U schválené absence
 * se to jen nabídne ({@see self::documentsDueBefore()}), a kdyby převod
 * odhadl špatně, vrací se podání zpět výsledkem `pending`
 * ({@see SicknessCaseService::recordReceipt()}).
 *
 * ## Co se nesmí stát
 *
 * Schválení absence je mzdový krok a nesmí spadnout kvůli podání: chybí-li
 * firmě kód OSSZ nebo vznikla událost mimo ochrannou lhůtu, absence se schválí
 * a výsledek řekne, proč případ nevznikl a kde se to opraví. Výjimky se tu
 * proto nepropouštějí dál, jen pojmenují.
 *
 * Prostředí je vždy `production`: povinnost z § 97 plní jen ostré podání,
 * testovací prostředí ČSSZ žádnou lhůtu nesplní.
 */
final readonly class SicknessCaseFromAbsenceService
{
    public const ENVIRONMENT = 'production';

    /**
     * Druh absence → druh dávky. Karanténa je nemocenské (§ 26 zák.
     * č. 187/2006 Sb. ji vede spolu s neschopností).
     *
     * @var array<string,SicknessBenefitKind>
     */
    private const KIND_BY_ABSENCE = [
        'dpn' => SicknessBenefitKind::Nem,
        'quarantine' => SicknessBenefitKind::Nem,
        'ocr' => SicknessBenefitKind::Ose,
        'long_term_care' => SicknessBenefitKind::Dlo,
        'ppm' => SicknessBenefitKind::Ppm,
        'paternity' => SicknessBenefitKind::Opp,
    ];

    public function __construct(
        private PayrollSicknessCaseRepository $cases,
        private PayrollAbsenceRepository $absences,
        private SicknessCaseService $caseService,
        private SicknessDeadlinePolicy $deadlines,
        private PayrollModuleStateRepository $moduleState,
        private PayrollWorkCalendarSchedule $schedule,
        private ?PayrollSicknessRepository $sicknessEvents = null,
    ) {}

    public static function benefitKindFor(string $absenceType): ?SicknessBenefitKind
    {
        return self::KIND_BY_ABSENCE[$absenceType] ?? null;
    }

    /**
     * @param array<string,mixed> $absence schválená absence
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}|null
     *         `null` = z absence dávka neplyne (i neschopnost, která zatím
     *         nepřesáhla okno náhrady mzdy podle § 192 ZP)
     */
    public function onApproved(int $supplierId, array $absence, ?int $userId): ?array
    {
        $kind = self::benefitKindFor((string) ($absence['absence_type'] ?? ''));
        if ($kind === null) {
            return null;
        }
        $absenceId = (int) $absence['id'];
        $employmentId = (int) $absence['employment_id'];
        $from = (string) $absence['date_from'];
        $to = (string) $absence['date_to'];

        try {
            $linked = $this->cases->findByAbsence($supplierId, self::ENVIRONMENT, $absenceId);
            if ($linked !== null) {
                return $this->result('linked', $linked, $kind);
            }

            $contiguous = $this->cases->contiguousOpenCase(
                $supplierId,
                self::ENVIRONMENT,
                $employmentId,
                $kind->value,
                $from,
            );
            if ($contiguous !== null) {
                $this->caseService->update(
                    $supplierId,
                    self::ENVIRONMENT,
                    (int) $contiguous['id'],
                    (int) $contiguous['row_version'],
                    ['incapacity_to' => $to],
                );

                return $this->result('extended', $contiguous, $kind);
            }

            $overlapping = $this->linkOverlapping($supplierId, $employmentId, $kind, $from, $to, $absenceId);
            if ($overlapping !== null) {
                return $this->result('linked', $overlapping, $kind);
            }

            // § 26 odst. 1 a § 97 odst. 2 zák. č. 187/2006 Sb.: prvních 14 dnů
            // neschopnosti kryje náhrada mzdy (§ 192 ZP), dávka ani NEMPRI z nich
            // nevzniká. Případ se proto zakládá až za událost delší než okno —
            // i tehdy, když ji přes 14. den dotáhne teprve navazující absence;
            // začátek se pak bere od první z nich. Dny vyčerpané u předchozího
            // plátce posouvají den vzniku zpět (DPN-05).
            [$eventFrom, $caseAbsenceId, $carried] = $this->eventStart($supplierId, $kind, $absence);
            // § 26 odst. 3: odpracovaná celá směna v den vzniku posouvá první den
            // neschopnosti. Potvrzení dala účetní při schválení první absence
            // řetězu; u neschopnosti převzaté z dřívějška den vzniku neznáme.
            $workedFirstDay = $kind === SicknessBenefitKind::Nem && $carried === 0
                ? $this->sicknessEvents?->firstDayFullyWorkedForAbsence($supplierId, $caseAbsenceId)
                : null;
            if (!$this->deadlines->nempriRequired($kind, $eventFrom, $to, 0, $workedFirstDay === true)) {
                return null;
            }

            return $this->createCase(
                $supplierId,
                $kind,
                $employmentId,
                $eventFrom,
                $to,
                $caseAbsenceId,
                $workedFirstDay,
                $kind === SicknessBenefitKind::Ose && (bool) ($absence['lone_carer'] ?? false),
                $userId,
                false,
                null,
            );
        } catch (SicknessException $exception) {
            return $this->skipped($kind, $exception->validationCode, $exception->getMessage());
        }
    }

    /**
     * Doplní případy ke schváleným absencím, u kterých při schválení nevznikly.
     *
     * Schválení absence případ přeskočí, když se podání nedá založit (firmě
     * chybí kód OSSZ). Lhůta § 97 ale běží dál, takže po doplnění kódu (a při
     * otevření přehledu případů) se přeskočené absence projdou znovu stejnou
     * cestou jako při schválení. Bere se jen rok zpět a nic před prvním
     * měsícem vedení mezd v MyÚčtu — dřívější události vyřídil předchozí
     * program a starší než rok by z MyÚčta už nikdo nepodával.
     *
     * @return list<array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}>
     */
    public function settleApprovedWithoutCase(int $supplierId, ?int $userId): array
    {
        if ($userId === null || $userId <= 0) {
            return [];
        }
        $endingFrom = (new \DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d');
        $startDay = $this->startDay($supplierId);
        if ($startDay !== null && $startDay > $endingFrom) {
            $endingFrom = $startDay;
        }
        $results = [];
        foreach ($this->cases->approvedAbsencesWithoutCase(
            $supplierId,
            self::ENVIRONMENT,
            array_keys(self::KIND_BY_ABSENCE),
            $endingFrom,
        ) as $absence) {
            $result = $this->onApproved($supplierId, $absence, $userId);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Rozběhnutá událost převzatá z předchozího mzdového programu.
     *
     * Převod zapisuje nepřítomnosti přímo repozitářem, mimo schválení
     * v Nepřítomnostech, takže případ by jinak nevznikl a lhůtu HZUPN k návratu
     * do práce by nikdo nehlídal. Zakládá se jen u události, která trvá aspoň
     * do prvního měsíce vedení mezd v MyÚčtu; dřív skončenou vyřídil celou
     * předchozí program.
     *
     * Případ nese `source = predecessor`. NEMPRI, jehož lhůta začala běžet před
     * prvním měsícem vedení mezd, je vyřízené předchozím programem; HZUPN zůstává
     * otevřené. Opakovaný převod případ nezdvojí: najde se podle absence nebo
     * podle přirozeného klíče (vztah, druh, den vzniku).
     *
     * @param array<string,mixed> $absence zapsaná převzatá absence
     * @param string $startPeriod první měsíc vedení mezd (`YYYY-MM`)
     * @param string|null $eventFrom skutečný den vzniku podle zdroje; bez něj
     *        se odvodí z absence a dnů vyčerpaných u předchozího plátce
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}|null
     */
    public function onTakenOver(
        int $supplierId,
        array $absence,
        ?int $userId,
        string $startPeriod,
        ?string $eventFrom = null,
        ?string $externalReference = null,
    ): ?array {
        $kind = self::benefitKindFor((string) ($absence['absence_type'] ?? ''));
        if ($kind === null
            || in_array($absence['status'] ?? null, ['cancelled', 'rejected'], true)
        ) {
            return null;
        }
        $startDay = substr($startPeriod, 0, 7) . '-01';
        $to = (string) $absence['date_to'];
        if ($to < $startDay) {
            return null;
        }
        $absenceId = (int) $absence['id'];
        $employmentId = (int) $absence['employment_id'];

        try {
            $linked = $this->cases->findByAbsence($supplierId, self::ENVIRONMENT, $absenceId);
            if ($linked !== null) {
                return $this->result('linked', $linked, $kind);
            }
            if ($eventFrom === null) {
                [$eventFrom, , ] = $this->eventStart($supplierId, $kind, $absence);
            }
            $existing = $this->cases->findByScope(
                $supplierId,
                self::ENVIRONMENT,
                $employmentId,
                $kind->value,
                $eventFrom,
            );
            if ($existing !== null) {
                if ($existing['absence_id'] === null) {
                    $this->cases->update(
                        $supplierId,
                        self::ENVIRONMENT,
                        (int) $existing['id'],
                        (int) $existing['row_version'],
                        ['absence_id' => $absenceId],
                    );
                }

                return $this->result('linked', $existing, $kind);
            }
            $overlapping = $this->linkOverlapping($supplierId, $employmentId, $kind, $eventFrom, $to, $absenceId);
            if ($overlapping !== null) {
                return $this->result('linked', $overlapping, $kind);
            }
            if (!$this->deadlines->nempriRequired($kind, $eventFrom, $to)) {
                return null;
            }

            return $this->createCase(
                $supplierId,
                $kind,
                $employmentId,
                $eventFrom,
                $to,
                $absenceId,
                null,
                false,
                $userId,
                true,
                $externalReference,
            );
        } catch (SicknessException $exception) {
            return $this->skipped($kind, $exception->validationCode, $exception->getMessage());
        }
    }

    /**
     * Zrušená absence zruší i případ, ze kterého se ještě nic nepodalo.
     * Případ s připraveným nebo odeslaným podáním zůstává: ČSSZ o něm už ví
     * a zrušit ho jde jen opravným podáním.
     *
     * Zrušená NAVAZUJÍCÍ absence (případ jen prodloužila, vazbu `absence_id`
     * nemá) vrátí konec případu na den před sebou — jinak by HZUPN hlásilo
     * návrat do práce o celé zrušené období později.
     *
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}|null
     */
    public function onCancelled(int $supplierId, int $absenceId): ?array
    {
        $case = $this->cases->findByAbsence($supplierId, self::ENVIRONMENT, $absenceId);
        if ($case === null) {
            return $this->shortenAfterCancelledContinuation($supplierId, $absenceId);
        }
        $kind = SicknessBenefitKind::from((string) $case['benefit_kind']);
        if ($case['status'] === SicknessCaseStatus::Cancelled->value) {
            return $this->result('cancelled', $case, $kind);
        }
        if ($case['status'] !== SicknessCaseStatus::Draft->value
            || $case['nempri_submission_id'] !== null
            || ($case['nempri_transfer_submission_id'] ?? null) !== null
            || $case['hzupn_submission_id'] !== null
        ) {
            return $this->kept($case, $kind);
        }
        $this->caseService->cancel($supplierId, self::ENVIRONMENT, (int) $case['id']);

        return $this->result('cancelled', $case, $kind);
    }

    /**
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}|null
     */
    private function shortenAfterCancelledContinuation(int $supplierId, int $absenceId): ?array
    {
        $absence = $this->absences->find($supplierId, $absenceId);
        $kind = $absence === null ? null : self::benefitKindFor((string) ($absence['absence_type'] ?? ''));
        if ($absence === null || $kind === null) {
            return null;
        }
        $case = $this->cases->caseEndingWith(
            $supplierId,
            self::ENVIRONMENT,
            (int) $absence['employment_id'],
            $kind->value,
            (string) $absence['date_from'],
            (string) $absence['date_to'],
        );
        if ($case === null) {
            return null;
        }
        // Konec případu nese HZUPN u nemocenského, jinak NEMPRI. Připravené
        // nebo vyřízené podání už nese starý konec; mění se opravným podáním.
        $document = $kind->hasEndOfIncapacityReport()
            ? SicknessDocumentKind::Hzupn
            : SicknessDocumentKind::Nempri;
        if ($case[$document->submissionColumn()] !== null
            || SicknessCaseService::documentStatus($case, $document)->isSettled()
        ) {
            return $this->kept($case, $kind);
        }
        $previousDay = (new \DateTimeImmutable((string) $absence['date_from']))
            ->modify('-1 day')
            ->format('Y-m-d');
        $updated = $this->caseService->update(
            $supplierId,
            self::ENVIRONMENT,
            (int) $case['id'],
            (int) $case['row_version'],
            ['incapacity_to' => $previousDay],
        );

        return $this->result('shortened', $updated, $kind);
    }

    /**
     * Skutečný den vzniku události, absence, ke které se případ váže, a dny
     * téže neschopnosti vyčerpané před ní u předchozího plátce.
     *
     * @param array<string,mixed> $absence
     * @return array{0:string,1:int,2:int}
     */
    private function eventStart(int $supplierId, SicknessBenefitKind $kind, array $absence): array
    {
        $absenceId = (int) $absence['id'];
        if ($kind !== SicknessBenefitKind::Nem) {
            return [(string) $absence['date_from'], $absenceId, 0];
        }
        $chain = $this->absences->contiguousChainStart($supplierId, $absenceId);
        $chainFrom = (string) ($chain['date_from'] ?? $absence['date_from']);
        $carried = (int) ($chain['carried_days'] ?? PayrollAbsenceRepository::carriedWindowDays($absence));
        $eventFrom = $carried > 0
            ? (new \DateTimeImmutable($chainFrom))->modify('-' . $carried . ' days')->format('Y-m-d')
            : $chainFrom;

        return [$eventFrom, (int) ($chain['id'] ?? $absenceId), $carried];
    }

    /**
     * Existující případ téže události (ručně založený, nebo z dřívějška) se
     * k absenci jen připojí — druhý případ téže události by ČSSZ nespárovala.
     *
     * @return array<string,mixed>|null
     */
    private function linkOverlapping(
        int $supplierId,
        int $employmentId,
        SicknessBenefitKind $kind,
        string $from,
        string $to,
        int $absenceId,
    ): ?array {
        $overlapping = $this->cases->overlappingForEmployment(
            $supplierId,
            self::ENVIRONMENT,
            $employmentId,
            $kind->value,
            $from,
            $to,
        );
        if ($overlapping === []) {
            return null;
        }
        $existing = $this->caseService->requireCase(
            $supplierId,
            self::ENVIRONMENT,
            (int) $overlapping[0]['id'],
        );
        if ($existing['absence_id'] === null) {
            $this->cases->update(
                $supplierId,
                self::ENVIRONMENT,
                (int) $existing['id'],
                (int) $existing['row_version'],
                ['absence_id' => $absenceId],
            );
        }

        return $existing;
    }

    /**
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}
     */
    private function createCase(
        int $supplierId,
        SicknessBenefitKind $kind,
        int $employmentId,
        string $eventFrom,
        string $to,
        int $caseAbsenceId,
        ?bool $workedFirstDay,
        bool $loneCarer,
        ?int $userId,
        bool $takenOver,
        ?string $externalReference,
    ): array {
        if ($userId === null || $userId <= 0) {
            return $this->skipped(
                $kind,
                'sickness_case_actor_missing',
                'Případ dávky se zakládá jménem účetní, která absenci schválila. Založte ho ručně v Podání → Dávky nemocenského pojištění.',
            );
        }
        $input = [
            'incapacity_from' => $eventFrom,
            'incapacity_to' => $to,
        ];
        if ($loneCarer) {
            $input['lone_caregiver'] = true;
        }
        // NEMPRI „pracoval v den vzniku" je totéž potvrzení, které účetní
        // dala při schválení DPN (první směna celá odpracována). Pracoval-li,
        // DV NEMPRI25 chce i pracovní dobu a odpracované hodiny toho dne —
        // celá směna znamená obojí stejné, délka směny je ze zveřejněných směn
        // nebo z týdenního rozvrhu.
        $hoursMissing = false;
        if ($workedFirstDay !== null) {
            $input['worked_on_decisive_day'] = $workedFirstDay;
            if ($workedFirstDay) {
                $minutes = $this->cases->publishedShiftMinutesOn($supplierId, $employmentId, $eventFrom)
                    ?? ($this->schedule->plannedMinutes($supplierId, $employmentId, [$eventFrom])[$eventFrom] ?? null);
                if ($minutes !== null && $minutes > 0) {
                    $hours = number_format($minutes / 60, 2, '.', '');
                    $input['daily_working_hours'] = $hours;
                    $input['hours_worked'] = $hours;
                } else {
                    $hoursMissing = true;
                }
            }
        }

        $startDay = $this->startDay($supplierId);
        $beforeStart = $startDay === null
            ? []
            : $this->documentsDueBefore($startDay, $kind, $eventFrom, $to, $loneCarer, $workedFirstDay === true);
        $system = [];
        if ($takenOver) {
            foreach ($beforeStart as $document) {
                $system[$document->statusColumn()] = SicknessDocumentStatus::Predecessor->value;
            }
            if ($externalReference !== null && trim($externalReference) !== '') {
                $system['external_reference'] = $externalReference;
            }
        }
        if ($takenOver || $beforeStart !== [] || ($startDay !== null && $eventFrom < $startDay)) {
            $system['source'] = SicknessCaseService::SOURCE_PREDECESSOR;
        }
        $created = $this->caseService->create(
            $supplierId,
            self::ENVIRONMENT,
            $employmentId,
            $kind->value,
            $input,
            $userId,
            $system,
        );
        $this->cases->update(
            $supplierId,
            self::ENVIRONMENT,
            (int) $created['id'],
            (int) $created['row_version'],
            ['absence_id' => $caseAbsenceId],
        );

        $result = $this->result('created', $created, $kind);
        $messages = [];
        if (!$takenOver && $beforeStart !== []) {
            $result['reason_code'] = 'sickness_case_predecessor_period';
            $labels = implode(' a ', array_map(
                static fn (SicknessDocumentKind $document): string => $document->agendaCode(),
                $beforeStart,
            ));
            $messages[] = 'Lhůta ' . $labels . ' začala běžet před prvním měsícem vedení mezd v MyÚčtu, '
                . 'kdy mzdy vedl předchozí program. Podal-li je on, zapište to u případu tlačítkem '
                . '„podal předchozí program“. Jinak je připravte a podejte z MyÚčta; do té doby je '
                . 'hlídač termínů vede jako nepodané.';
        }
        if ($hoursMissing) {
            $result['reason_code'] ??= 'nempri_worked_hours_missing';
            $messages[] = 'Zaměstnanec v den vzniku neschopnosti odpracoval celou směnu, ale směna '
                . 'ani týdenní rozvrh ten den nejsou zapsané. Případ zůstal v konceptu: doplňte u něj '
                . 'pracovní dobu a odpracované hodiny, bez nich NEMPRI neprojde.';
        }
        if ($messages !== []) {
            $result['message'] = implode(' ', $messages);
        }

        return $result;
    }

    /** První den vedení mezd v MyÚčtu, nebo `null`, když modul začátek nemá. */
    private function startDay(int $supplierId): ?string
    {
        $startPeriod = $this->moduleState->get($supplierId)['start_period'] ?? null;
        if (!is_string($startPeriod) || $startPeriod === '') {
            return null;
        }

        return substr($startPeriod, 0, 7) . '-01';
    }

    /**
     * Podání, jejichž lhůta začala běžet před prvním měsícem vedení mezd
     * v MyÚčtu — v době, kdy mzdy vedl předchozí program.
     *
     * Jako podané předchozím programem je případ nese jen u PŘEVODU
     * ({@see self::onTakenOver()}): událost tehdy vedl předchozí program a z něj
     * pochází. Schválení absence v Nepřítomnostech nic takového nedokládá —
     * účetní mohla zpětně zapsat neschopnost, kterou předchozí program nikdy
     * nepodal. Tam případ jen nese `source = predecessor`, takže vyřízení
     * předchozím programem jde zapsat jedním krokem, ale povinnost zůstává
     * hlídaná, dokud to účetní neudělá. Lepší připomínka navíc než tiše
     * zmizelá povinnost.
     *
     * @return list<SicknessDocumentKind>
     */
    private function documentsDueBefore(
        string $startDay,
        SicknessBenefitKind $kind,
        string $eventFrom,
        string $to,
        bool $loneCarer,
        bool $workedFirstDay,
    ): array {
        $documents = [];
        try {
            $earliest = $this->deadlines->forNempri(
                $kind,
                $eventFrom,
                $to,
                null,
                $loneCarer,
                $workedFirstDay,
            )->earliestNotificationOn;
            if ($earliest < $startDay) {
                $documents[] = SicknessDocumentKind::Nempri;
            }
        } catch (SicknessException) {
            // Lhůta nejde spočítat (VPM bez výplatního dne) — rozhodne účetní.
        }
        if ($kind->hasEndOfIncapacityReport()
            && (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') < $startDay
        ) {
            $documents[] = SicknessDocumentKind::Hzupn;
        }

        return $documents;
    }

    /**
     * @param array<string,mixed> $case
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}
     */
    private function result(string $outcome, array $case, SicknessBenefitKind $kind): array
    {
        $due = null;
        if ($kind !== SicknessBenefitKind::Vpm
            && SicknessCaseService::documentStatus($case, SicknessDocumentKind::Nempri)->needsAction()
        ) {
            try {
                $due = $this->deadlines->forNempri(
                    $kind,
                    (string) $case['incapacity_from'],
                    $case['incapacity_to'] === null ? null : (string) $case['incapacity_to'],
                    null,
                    (bool) ($case['lone_caregiver'] ?? false),
                    SicknessCaseService::firstDayFullyWorked($case),
                )->dueOn;
            } catch (SicknessException) {
                $due = null;
            }
        }

        return [
            'outcome' => $outcome,
            'case_id' => (int) $case['id'],
            'benefit_kind' => $kind->value,
            'nempri_due_on' => $due,
            'reason_code' => null,
            'message' => null,
        ];
    }

    /**
     * @param array<string,mixed> $case
     * @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string}
     */
    private function kept(array $case, SicknessBenefitKind $kind): array
    {
        return [
            'outcome' => 'kept',
            'case_id' => (int) $case['id'],
            'benefit_kind' => $kind->value,
            'nempri_due_on' => null,
            'reason_code' => 'sickness_case_has_submission',
            'message' => 'Z případu dávky už bylo připravené nebo odeslané podání, takže '
                . 'se se zrušením absence nezměnil. Zkontrolujte ho v Podání → Dávky '
                . 'nemocenského pojištění a případně podejte opravné podání.',
        ];
    }

    /** @return array{outcome:string,case_id:?int,benefit_kind:?string,nempri_due_on:?string,reason_code:?string,message:?string} */
    private function skipped(SicknessBenefitKind $kind, string $code, string $message): array
    {
        return [
            'outcome' => 'skipped',
            'case_id' => null,
            'benefit_kind' => $kind->value,
            'nempri_due_on' => null,
            'reason_code' => $code,
            'message' => $message,
        ];
    }
}
