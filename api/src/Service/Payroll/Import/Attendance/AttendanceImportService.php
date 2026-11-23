<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\PayrollPeriodLabel;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementConflictException;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollImportLinkRepository;
use MyInvoice\Repository\Payroll\PayrollImportProfileRepository;
use MyInvoice\Repository\Payroll\PayrollInputImportRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\Payroll\Absence\ImportAbsenceCompensationRates;
use MyInvoice\Service\Payroll\Absence\PayrollImportAbsenceCompensationMaterializer;
use MyInvoice\Service\Payroll\Component\PayrollInputImportService;
use MyInvoice\Service\Payroll\Component\PayrollWholeCrowns;
use MyInvoice\Service\Payroll\Employment\PayrollOpenAgreements;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\PayrollTaxDeclarationBulkSign;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportSummaryWriter;

/**
 * Import měsíčních podkladů z docházkového systému (typicky GIRITON):
 * náhled → použití (dávka s hodinami + návrhy mzdových vstupů) a založení
 * chybějících osob.
 *
 * Server při použití náhled VŽDY přepočítá ze souborů — klientovi se nevěří.
 * Hodiny zůstávají evidencí dávky; nepřevádějí se na peníze ani na intervaly
 * docházky a nevymýšlejí se dny. Peníze jdou jen přes vstupní bránu
 * {@see PayrollInputImportService} se stabilním `external_id`, takže opravený
 * soubor nevyrobí druhou odměnu, jen duplicitu.
 *
 * Mapování sloupců se nastavuje jednorázově v profilu; měsíční import si
 * profil vybere sám podle toho, kolik sloupců nahraných souborů rozpozná.
 *
 * @phpstan-import-type AttendanceProfileComponent from AttendanceProfileComponents
 */
final class AttendanceImportService
{
    private const MAX_PERSONS_PER_REQUEST = 200;
    public const EXPORT_FORMAT = 'myucto-attendance-profile';
    public const EXPORT_VERSION = 1;

    /**
     * Běh v těchto stavech už je uzavřený výstup (nebo k tomu míří). Kontrola
     * zúčtování v podmínkách ({@see PayrollEmploymentRepository::assertTermsOpenFrom()})
     * pouští i schválený běh, protože kartu vztahu opravuje člověk, který běh
     * vidí. Hromadný import je přísnější: schválený měsíc nepřepočítá nikdo,
     * kdo o změně ví, proto do něj nezapíše vůbec.
     */
    private const WAGE_BLOCKING_RUN_STATUSES = [
        'approved', 'posted', 'payment_ready', 'paid', 'closed', 'correction_pending',
    ];

    /**
     * Druhy složek, kterými se vyplácí základ mzdy podle docházky. Rychlý
     * vstup bere hodinovou mzdu jako spravovaný základ (měsíční mzdu pak
     * nepoužije), úkolovou jako „ostatní" — ta by se k základu z měsíční
     * mzdy přičetla podruhé. Ani u jedné se sjednaná měsíční mzda z podkladů
     * do podmínek nezapisuje: platila by jako základ v každém měsíci, kdy
     * složka z docházky nepřijde.
     */
    private const WAGE_BASE_KINDS = ['hourly_wage', 'task_wage'];

    /**
     * Srážky z podkladů = dohody o srážce na importovaný měsíc; pořadí za ručně
     * sjednanými dohodami. Význam sloupce => [klíč v referenci dohody, druh].
     *
     * Obecná „Srážka" z podkladů (v převodu PAMICA složka zadaná částkou, např.
     * S07) má druh `imported`, ne „Jiná srážka": dohoda o srážkách podle OZ za
     * ní doložená není (typicky srážky za stravování z docházkového systému),
     * takže do JMHZ 10116 nepatří ({@see DeductionAgreementTerms::reportedAsWageDeduction()}).
     * Klíč reference zůstává `other`, aby opakovaný import našel dohody založené
     * dřív a nezaložil vedle nich druhé.
     */
    private const DEDUCTION_KINDS = [
        'net_meal_deduction' => ['meal', 'meal'],
        'net_other_deduction' => ['other', 'imported'],
    ];
    private const DEDUCTION_PRIORITY = 500;

    public function __construct(
        private readonly AttendanceWorkbookReader $reader,
        private readonly AttendanceColumnMapper $mapper,
        private readonly AttendancePersonAggregator $aggregator,
        private readonly AttendancePersonMatcher $matcher,
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollImportLinkRepository $links,
        private readonly PayrollImportProfileRepository $profiles,
        private readonly PayrollInputImportRepository $inputRepository,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputImportService $inputs,
        private readonly PayrollSensitiveData $sensitive,
        private readonly PayrollPersonCreateService $personCreate,
        private readonly PayrollEmploymentRepository $employments,
        private readonly LicenseCapacityGate $license,
        private readonly Connection $db,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollTimeImportSummaryWriter $timeSummaries,
        private readonly PayrollTimeImportApprovalService $timeApprovals,
        private readonly PayrollImportAbsenceCompensationMaterializer $absenceCompensations,
        private readonly PayrollDeductionAgreementRepository $deductions,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonProfileRepository $personProfiles,
        private readonly PayrollPersonAccountVerificationService $accountVerification,
        private readonly DemoMode $demo,
        private readonly AttendanceOpenAgreementMatcher $openAgreementMatcher,
        private readonly PayrollOpenAgreements $openAgreements,
        private readonly RegistrationImportWriter $registrationWriter,
        private readonly PayrollTaxDeclarationBulkSign $declarations,
        private readonly AttendanceRegistrationCodebooks $registrationCodebooks,
    ) {
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array<string,mixed>
     */
    public function preview(
        int $supplierId,
        string $period,
        array $files,
        mixed $rules,
        ?int $profileId,
        mixed $components = null,
        bool $roundWholeCrowns = false,
    ): array {
        $periodStart = $this->period($period);
        $modifiedSamples = $this->ensureSampleProfile($supplierId);
        $read = $this->readFiles($files);
        [$validated, $profileComponents, $profileMeta] = $this->resolveMapping(
            $supplierId,
            $read['sheets'],
            $rules,
            $profileId,
            $components,
        );

        return self::public($this->compute($supplierId, $periodStart, $files, $read, $validated, $profileComponents, $profileMeta, $roundWholeCrowns))
            + [
                'upgrade_available' => self::sampleUpgrade($modifiedSamples, $profileMeta['id']),
                'source_checks' => $this->sourceChecks($supplierId, $periodStart, $files, $read['sheets']),
            ];
    }

    /**
     * Kontroly před použitím dávky, které chrání před dvojími vstupy:
     *
     *  - období podle názvů souborů a listů („11-2025", „Mzdy 11-25", „1125")
     *    proti vybranému období. Výchozí období je pracovní měsíc mezd, takže
     *    podklady za starší měsíc by se jinak tiše zapsaly do špatného měsíce;
     *  - platné vstupy téhož období z jiného importu (jiná sada souborů, převod
     *    z POHODY / PAMICA). Opakované použití týchž souborů se nepočítá, to
     *    zdvojení nevyrobí.
     *
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @param list<AttendanceSheet> $sheets
     * @return array{period:array{selected:string,detected:list<array{period:string,sources:list<string>}>,mismatch:bool},other_sources:list<array<string,mixed>>,requires_confirmation:bool}
     */
    private function sourceChecks(int $supplierId, string $periodStart, array $files, array $sheets): array
    {
        $selected = substr($periodStart, 0, 7);
        $names = array_map(static fn (array $file): string => $file['name'], $files);
        foreach ($sheets as $sheet) {
            $names[] = $sheet->name;
        }
        $detected = [];
        foreach (AttendancePeriodDetector::detect($names) as $period => $sources) {
            $detected[] = ['period' => $period, 'sources' => $sources];
        }
        $mismatch = array_diff(array_column($detected, 'period'), [$selected]) !== [];

        $current = array_map(static fn (array $file): string => $file['sha256'], $files);
        sort($current);
        $other = [];
        foreach ($this->imports->activeInputSources($supplierId, $periodStart) as $source) {
            $hashes = [];
            $fileNames = [];
            foreach ($source['files'] as $file) {
                $fileNames[] = (string) ($file['name'] ?? '');
                if (is_string($file['sha256'] ?? null)) {
                    $hashes[] = $file['sha256'];
                }
            }
            sort($hashes);
            if ($hashes !== [] && $hashes === $current) {
                continue;
            }
            $other[] = [
                'attendance_import_id' => $source['attendance_import_id'],
                'input_import_id' => $source['input_import_id'],
                'files' => $fileNames,
                'active_inputs' => $source['active_inputs'],
                'created_at' => $source['created_at'],
            ];
        }

        return [
            'period' => ['selected' => $selected, 'detected' => $detected, 'mismatch' => $mismatch],
            'other_sources' => $other,
            'requires_confirmation' => $mismatch || $other !== [],
        ];
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        string $period,
        array $files,
        mixed $rules,
        mixed $links,
        bool $saveLinks,
        bool $createInputs,
        ?int $userId,
        mixed $components = null,
        bool $createComponents = false,
        ?int $profileId = null,
        bool $adoptPersonalNumbers = false,
        bool $adoptMonthlyWage = false,
        bool $writeTimeSummary = false,
        bool $approveCleanTimeMonths = false,
        bool $materializeAbsenceCompensations = false,
        bool $createDeductions = false,
        /*
         * Potvrzení kontrol období a dvojích vstupů ({@see self::sourceChecks()}).
         * `null` = volající kontroly neřeší (převod z POHODY, skripty), `false` =
         * dávku s nálezem nepoužít a vrátit nález, `true` = účetní nález potvrdila.
         */
        ?bool $sourceChecksConfirmed = null,
        /*
         * Doplnit přiřazeným osobám údaje pro registraci ČSSZ, které jim
         * v evidenci chybí ({@see AttendanceRegistrationData}). Jako souhrn
         * docházky se zapisuje až po dávce a i při jejím opakování; zapisuje se
         * jen chybějící, takže opakování nic nepřepíše.
         */
        bool $fillRegistrationData = false,
        /*
         * Zapsat navržené pravděpodobné výdělky vztahům, které žádný nemají
         * ({@see self::probableEarnings()}). Jako mzda až po dávce, opakování
         * už zapsaný výdělek nenavrhne znovu.
         */
        bool $adoptProbableEarnings = false,
        /*
         * Zaokrouhlit částky složek vykazovaných v JMHZ nahoru na celé koruny
         * ({@see PayrollWholeCrowns}). Volající, který volbu neřeší (převody,
         * skripty), dostane částky beze změny; obrazovka ji u měsíců JMHZ zapíná.
         */
        bool $roundWholeCrowns = false,
    ): array {
        if ($approveCleanTimeMonths && !$writeTimeSummary) {
            throw new \InvalidArgumentException(
                'Hromadné schválení pracovních měsíců vyžaduje zápis souhrnu docházky do pracovních měsíců.',
            );
        }
        if ($materializeAbsenceCompensations && !$writeTimeSummary) {
            throw new \InvalidArgumentException(
                'Výpočet náhrad mzdy z hodin nepřítomnosti vyžaduje zápis souhrnu docházky do pracovních měsíců.',
            );
        }
        $periodStart = $this->period($period);
        $read = $this->readFiles($files);
        if ($sourceChecksConfirmed === false) {
            $checks = $this->sourceChecks($supplierId, $periodStart, $files, $read['sheets']);
            if ($checks['requires_confirmation']) {
                throw new AttendanceSourceConfirmationRequired($checks);
            }
        }
        [$validated, $profileComponents, $profileMeta] = $this->resolveMapping(
            $supplierId,
            $read['sheets'],
            $rules,
            $profileId,
            $components,
        );
        $computed = $this->compute($supplierId, $periodStart, $files, $read, $validated, $profileComponents, $profileMeta, $roundWholeCrowns);
        $linkMap = self::linkMap($links);

        $options = [];
        foreach ($computed['employment_options'] as $option) {
            $options[$option['employment_id']] = $option;
        }
        $personsByKey = [];
        foreach ($computed['persons'] as $person) {
            $personsByKey[$person['key']] = $person;
        }
        foreach ($linkMap as $key => $employmentId) {
            if (!isset($personsByKey[$key])) {
                throw new \InvalidArgumentException(
                    'Osoba vybraná k přiřazení v nahraných podkladech není. Načtěte náhled znovu.',
                );
            }
            if (!isset($options[$employmentId])) {
                throw new \InvalidArgumentException(
                    "Vybraný pracovní vztah osoby „{$personsByKey[$key]['display_name']}“ ve firmě neexistuje "
                    . "nebo v období {$period} neplatí. Vyberte jiný vztah.",
                );
            }
        }

        $assigned = [];
        $skipped = [];
        $usedBy = [];
        foreach ($computed['persons'] as $person) {
            $match = $person['match'];
            $employmentId = $linkMap[$person['key']]
                ?? (in_array($match['status'], ['linked', 'matched'], true) ? $match['employment_id'] : null);
            if ($employmentId === null) {
                $skipped[] = [
                    'key' => $person['key'],
                    'display_name' => $person['display_name'],
                    'reason' => match ($match['status']) {
                        'ambiguous' => 'Osoba má víc možných pracovních vztahů. Vyberte vztah ručně.',
                        'conflict' => (string) ($match['conflict'] ?? 'Rodné a osobní číslo v podkladech si odporují. Vyberte vztah ručně.'),
                        default => 'Osoba nemá v období pracovní vztah. Založte ji nebo vyberte vztah ručně.',
                    },
                ];
                continue;
            }
            if (isset($usedBy[$employmentId])) {
                $skipped[] = [
                    'key' => $person['key'],
                    'display_name' => $person['display_name'],
                    'reason' => "Pracovní vztah {$options[$employmentId]['code']} už je přiřazený osobě „{$usedBy[$employmentId]}“.",
                ];
                continue;
            }
            $usedBy[$employmentId] = $person['display_name'];
            $assigned[] = ['person' => $person, 'employment' => $options[$employmentId]];
        }

        /*
         * Otisk dávky = soubory + pravidla + přiřazení + volby. Dvojklik nebo
         * opakovaný požadavek vrátí tutéž dávku; oprava mapování nad stejnými
         * soubory ale projde jako nová dávka — jinak by šla opravit jen smazáním.
         * Peníze duplicitně nevzniknou ani tak (stabilní external_id vstupů).
         */
        $fingerprint = hash('sha256', json_encode([
            'files' => self::sortedHashes($files),
            'rules' => $computed['rules'],
            'assignments' => array_map(
                static fn (array $item): array => [$item['person']['key'], $item['employment']['employment_id']],
                $assigned,
            ),
            'create_inputs' => $createInputs,
            'create_components' => $createComponents,
            'adopt_personal_numbers' => $adoptPersonalNumbers,
        // Klíče jen při zapnutí, ať otisky dávek bez těchto voleb zůstanou stejné.
        ] + ($adoptMonthlyWage ? ['adopt_monthly_wage' => true] : [])
            + ($createDeductions ? ['create_deductions' => true] : [])
            + ($roundWholeCrowns ? ['round_whole_crowns' => true] : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);

        /*
         * Mzda se zapisuje až po dávce, vztah po vztahu, a to i při opakování
         * téže dávky: zápis se pokaždé plánuje znovu nad aktuálními podmínkami,
         * takže převzatá mzda se podruhé nezapíše a odmítnutá (třeba kvůli
         * schválenému běhu) projde, jakmile překážka zmizí.
         */
        $adoptWages = fn (): array => $adoptMonthlyWage
            ? $this->adoptMonthlyWages(
                $supplierId,
                $periodStart,
                $assigned,
                self::componentKinds($computed['component_checks'], $computed['component_definitions']),
                $userId,
            )
            : ['adopted' => 0, 'conflicts' => [], 'runs_needing_refresh' => []];

        /*
         * Souhrn pracovního měsíce se zapisuje až po dávce a i při opakování
         * téže dávky. Zápis je idempotentní a volba není v otisku dávky, takže
         * zapnutí nad už použitými podklady souhrn jen doplní.
         */
        $timeSteps = function (int $importId) use ($supplierId, $userId, $writeTimeSummary, $approveCleanTimeMonths, $materializeAbsenceCompensations, $computed): array {
            if (!$writeTimeSummary) {
                return ['time_summary' => null, 'time_approval' => null, 'absence_compensation' => null];
            }
            $summary = $this->timeSummaries->writeFromBatch($supplierId, $importId, $userId);

            return [
                'time_summary' => $summary,
                // Hromadné schválení čistých měsíců stojí na právě zapsaných
                // souhrnech; výjimky se vracejí seznamem, nic nezastaví import.
                'time_approval' => $approveCleanTimeMonths
                    ? $this->timeApprovals->approveWritten($supplierId, $importId, $summary, true, $userId)
                    : null,
                'absence_compensation' => $materializeAbsenceCompensations
                    ? $this->absenceCompensations->materializeFromBatch(
                        $supplierId,
                        $importId,
                        $userId,
                        self::compensationRates($computed['rules']),
                    )
                    : null,
            ];
        };

        $assignedEmployments = array_fill_keys(
            array_map(static fn (array $item): int => (int) $item['employment']['employment_id'], $assigned),
            true,
        );
        $registration = fn (): array => [
            'registration' => $fillRegistrationData
                ? $this->fillRegistration($supplierId, $periodStart, $assigned, $userId)
                : null,
            'probable_earnings' => $adoptProbableEarnings
                ? $this->adoptProbableEarnings(
                    $supplierId,
                    $periodStart,
                    array_values(array_filter(
                        $computed['probable_earning_changes'],
                        static fn (array $change): bool => isset($assignedEmployments[(int) $change['employment_id']]),
                    )),
                    $userId,
                )
                : null,
        ];

        $existing = $this->imports->findBatchByHash($supplierId, $periodStart, $fingerprint);
        if ($existing !== null) {
            return self::replay($existing, $skipped, $adoptWages()) + $timeSteps((int) $existing['id']) + $registration();
        }

        $result = $this->transactional(function () use (
            $supplierId,
            $periodStart,
            $period,
            $files,
            $computed,
            $assigned,
            $fingerprint,
            $saveLinks,
            $createInputs,
            $createComponents,
            $adoptPersonalNumbers,
            $createDeductions,
            $userId,
        ): ?array {
            $rows = [];
            foreach ($assigned as $item) {
                $employmentId = $item['employment']['employment_id'];
                foreach ($item['person']['_metrics'] as $meaning => $metric) {
                    $rows[] = [
                        'employment_id' => $employmentId,
                        'meaning' => (string) $meaning,
                        'component_code' => '',
                        'quantity_millihours' => $metric['millihours'] ?? null,
                        'amount_minor' => $metric['amount_minor'] ?? null,
                        'source_ref' => $metric['source'],
                    ];
                }
                // Odpracované dny nejsou hodiny: řádek dávky je nese jako
                // tisíciny (dny × 1000), souhrn měsíce je převezme jako celé dny.
                if (is_int($item['person']['worked_days'] ?? null)) {
                    $rows[] = [
                        'employment_id' => $employmentId,
                        'meaning' => 'worked_days',
                        'component_code' => '',
                        'quantity_millihours' => $item['person']['worked_days'] * 1000,
                        'amount_minor' => null,
                        'source_ref' => (string) ($item['person']['_worked_days_source'] ?? ''),
                    ];
                }
                foreach ($item['person']['_components'] as $code => $component) {
                    $rows[] = [
                        'employment_id' => $employmentId,
                        'meaning' => AttendanceMeaning::COMPONENT,
                        'component_code' => (string) $code,
                        'quantity_millihours' => null,
                        'amount_minor' => $component['amount_minor'],
                        'source_ref' => $component['source'],
                    ];
                }
                foreach ($item['person']['_deductions'] ?? [] as $meaning => $deduction) {
                    $rows[] = [
                        'employment_id' => $employmentId,
                        'meaning' => (string) $meaning,
                        'component_code' => '',
                        'quantity_millihours' => null,
                        'amount_minor' => $deduction['amount_minor'],
                        'source_ref' => $deduction['source'],
                    ];
                }
            }
            $importId = $this->imports->insertBatch(
                $supplierId,
                $periodStart,
                AttendanceMeaning::SOURCE_SYSTEM,
                $fingerprint,
                array_map(static fn (array $file): array => ['name' => $file['name'], 'sha256' => $file['sha256']], $files),
                $computed['rules'],
                count($assigned),
                $rows,
                $userId,
            );
            if ($importId === null) {
                return null;
            }

            $linksSaved = 0;
            if ($saveLinks) {
                foreach ($assigned as $item) {
                    $linksSaved += $this->saveLinks(
                        $supplierId,
                        ($item['person']['_number_ambiguous'] ?? false) ? '' : (string) ($item['person']['personal_number'] ?? ''),
                        (string) $item['person']['_name_key'],
                        $item['employment']['employee_id'],
                        $item['employment']['employment_id'],
                        $userId,
                    ) ? 1 : 0;
                }
            }

            $created = [];
            if ($createInputs && $createComponents) {
                $created = $this->createMissingComponents(
                    $supplierId,
                    $periodStart,
                    $assigned,
                    $computed['component_definitions'],
                );
            }

            $inputs = ['import_id' => null, 'created' => 0, 'updated' => 0, 'overridden' => 0, 'duplicates' => 0, 'errors' => []];
            if ($createInputs) {
                $inputs = $this->createInputs($supplierId, $period, $importId, $assigned, $userId);
            }

            // Až po vstupech: brána vstupů páruje vztah i podle stávajícího kódu.
            $adoption = $adoptPersonalNumbers
                ? $this->adoptPersonalNumbers($supplierId, $assigned, $userId)
                : ['adopted' => 0, 'conflicts' => []];

            return [
                'import_id' => $importId,
                'inputs' => $inputs,
                'links_saved' => $linksSaved,
                'components_created' => $created,
                'adoption' => $adoption,
                'deductions' => $createDeductions
                    ? $this->createDeductions($supplierId, $periodStart, $assigned, $userId)
                    : null,
            ];
        });

        if ($result === null) {
            $winner = $this->imports->findBatchByHash($supplierId, $periodStart, $fingerprint)
                ?? throw new \RuntimeException('Souběžně založenou dávku importu nelze načíst.');

            return self::replay($winner, $skipped, $adoptWages()) + $timeSteps((int) $winner['id']) + $registration();
        }
        $wages = $adoptWages();
        $time = $timeSteps((int) $result['import_id']) + $registration();

        return [
            'replayed' => false,
            'batch' => self::publicBatch($this->imports->batch($supplierId, $result['import_id'])
                ?? throw new \RuntimeException('Dávku importu nelze načíst.')),
            'inputs' => $result['inputs'],
            'links_saved' => $result['links_saved'],
            'components_created' => $result['components_created'],
            'personal_numbers_adopted' => $result['adoption']['adopted'],
            'personal_number_conflicts' => $result['adoption']['conflicts'],
            'monthly_wages_adopted' => $wages['adopted'],
            'wage_conflicts' => $wages['conflicts'],
            'runs_needing_refresh' => $wages['runs_needing_refresh'],
            'skipped_persons' => $skipped,
            'deductions' => $result['deductions'],
        ] + $time;
    }

    /**
     * Osobní údaje z podkladů podle klíče osoby: rodné číslo, datum narození,
     * kód zdravotní pojišťovny a číslo účtu. Klient zná rodné číslo a účet jen
     * maskované a ostatní údaje vůbec, takže je při zakládání osob doplní server ze
     * stejných souborů a stejného mapování, ze kterých vznikl náhled.
     *
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array<string,array<string,string>>
     */
    private function personDataFromFiles(
        int $supplierId,
        string $periodStart,
        array $files,
        mixed $rules,
        ?int $profileId,
        mixed $components,
    ): array {
        $read = $this->readFiles($files);
        [$validated, $profileComponents, $profileMeta] = $this->resolveMapping(
            $supplierId,
            $read['sheets'],
            $rules,
            $profileId,
            $components,
        );
        $computed = $this->compute($supplierId, $periodStart, $files, $read, $validated, $profileComponents, $profileMeta);
        $data = [];
        foreach ($computed['persons'] as $person) {
            foreach ([
                'birth_number' => '_birth_number',
                'birth_date' => '_birth_date',
                'health_insurer_code' => '_health_insurer_code',
                'bank_account' => '_bank_account',
            ] as $field => $internal) {
                $value = $person[$internal] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $data[(string) $person['key']][$field] = trim($value);
                }
            }
            if (is_array($person['open_agreement'] ?? null)) {
                $data[(string) $person['key']]['_open_agreement'] = $person['open_agreement'];
            }
            if (($person['_registration'] ?? []) !== []) {
                $data[(string) $person['key']]['_registration'] = $person['_registration'];
            }
            if (is_array($person['existing_person'] ?? null)) {
                $data[(string) $person['key']]['_existing_person'] = $person['existing_person'];
            }
            if ($person['_number_ambiguous'] ?? false) {
                $data[(string) $person['key']]['_number_ambiguous'] = true;
            }
            $blocking = $person['match']['status'] === 'conflict'
                ? $person['match']['conflict']
                : ($person['_birth_number_error'] ?? null);
            if (is_string($blocking)) {
                $data[(string) $person['key']]['_blocking_error'] = $blocking;
            }
            if (($person['probable_earning']['source'] ?? null) === 'amount') {
                $data[(string) $person['key']]['_probable_earning'] = $person['probable_earning'];
            }
        }

        return $data;
    }

    /**
     * Se soubory (a mapováním jako u náhledu) doplní osobám bez rodného
     * čísla to z podkladů.
     *
     * @param list<array{name:string,content:string,sha256:string,extension:string}>|null $files
     * @return array{results:list<array<string,mixed>>}
     */
    public function persons(
        int $supplierId,
        string $period,
        mixed $persons,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        ?array $files = null,
        mixed $rules = null,
        ?int $profileId = null,
        mixed $components = null,
    ): array {
        $periodStart = $this->period($period);
        if (!is_array($persons) || !array_is_list($persons) || $persons === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jednu osobu k založení.');
        }
        if (count($persons) > self::MAX_PERSONS_PER_REQUEST) {
            throw new \InvalidArgumentException(
                'Najednou lze založit nejvýše ' . self::MAX_PERSONS_PER_REQUEST . ' osob. Rozdělte je na víc kroků.',
            );
        }
        $personData = $files === null
            ? []
            : $this->personDataFromFiles($supplierId, $periodStart, $files, $rules, $profileId, $components);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $results = [];
        foreach ($persons as $item) {
            $key = is_array($item) && is_string($item['person_key'] ?? null) ? $item['person_key'] : '';
            try {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Údaje osoby nemají platný tvar.');
                }
                $open = $personData[$key]['_open_agreement'] ?? null;
                $existing = $personData[$key]['_existing_person'] ?? null;
                $blocking = $personData[$key]['_blocking_error'] ?? null;
                unset($personData[$key]['_open_agreement'], $personData[$key]['_existing_person'], $personData[$key]['_blocking_error']);
                if (is_string($blocking)) {
                    throw new \InvalidArgumentException($blocking);
                }
                // Údaje pro registraci bere server jen ze souborů, nikdy z požadavku.
                unset($item['_registration'], $item['_probable_earning'], $item['_number_ambiguous']);
                // Shoda jen podle jména a data narození: rozhodne účetní, ne import.
                if (is_array($existing) && ($existing['requires_confirmation'] ?? false)) {
                    if (($item['create_new_person'] ?? false) === true) {
                        $existing = null;
                    } elseif (($item['confirm_existing_person'] ?? null) !== (int) $existing['employee_id']) {
                        throw new \InvalidArgumentException(
                            "V evidenci je osoba {$existing['employee_name']} se stejným jménem a datem narození. "
                            . 'Potvrďte, že jde o ni (založí se jí jen nový vztah), nebo zvolte založení nové osoby.',
                        );
                    }
                }
                foreach ($personData[$key] ?? [] as $field => $value) {
                    if (in_array($item[$field] ?? null, [null, ''], true)) {
                        $item[$field] = $value;
                    }
                }
                $reused = $this->reuseOpenAgreement($supplierId, $item, is_array($open) ? $open : null, $userId, $ip, $userAgent);
                if ($reused !== null) {
                    $results[] = ['person_key' => $key, 'status' => 'reused'] + $reused;
                    continue;
                }
                // Osoba z evidence (vazba, rodné číslo, osobní číslo) dostane jen
                // nový vztah, druhá karta osoby se stejným rodným číslem nevznikne.
                if (is_array($existing)) {
                    $created = $this->license->mutatePayrollEmployees(
                        fn (): array => $this->createEmploymentForExisting($supplierId, $item, $existing, $today, $userId, $ip, $userAgent),
                    );
                    $results[] = ['person_key' => $key, 'status' => 'employment_created'] + $created;
                    continue;
                }
                $created = $this->license->mutatePayrollEmployees(
                    fn (): array => $this->createPerson($supplierId, $item, $today, $userId, $ip, $userAgent),
                );
                $results[] = [
                    'person_key' => $key,
                    'status' => 'created',
                    'employee_id' => $created['employee_id'],
                    'employment_id' => $created['employment_id'],
                    'message' => $created['message'],
                ];
            } catch (LicensePayrollLimitExceeded) {
                $results[] = self::failed($key, 'Dalšího aktivního zaměstnance lze přidat až po rozšíření mzdového doplňku.');
            } catch (\InvalidArgumentException|\DomainException|PayrollEmploymentConflictException $e) {
                $results[] = self::failed($key, $e->getMessage());
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $results[] = self::failed(
                    $key,
                    'Osobu se nepodařilo založit kvůli kolizi údajů — například rodné číslo už má jiná osoba. Zkontrolujte seznam zaměstnanců.',
                );
            }
        }

        return ['results' => $results];
    }

    /**
     * Jedna dohoda z importu dohod: osobě z evidence (`$existing` jako
     * {@see AttendanceOpenAgreementMatcher} `existing_person`) jen nový vztah,
     * jinak karta osoby i vztah. Stejné zakládání jako krok Osoby importu
     * docházky, včetně údajů pro registraci a uložené vazby.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $existing
     * @return array{employee_id:int,employment_id:int,message:?string}
     */
    public function createAgreement(
        int $supplierId,
        array $item,
        ?array $existing,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return $this->license->mutatePayrollEmployees(
            fn (): array => $existing === null
                ? $this->createPerson($supplierId, $item, $today, $userId, $ip, $userAgent)
                : $this->createEmploymentForExisting($supplierId, $item, $existing, $today, $userId, $ip, $userAgent),
        );
    }

    /** @return list<array<string,mixed>> */
    public function batches(int $supplierId, ?string $period): array
    {
        $periodStart = $period === null || $period === '' ? null : $this->period($period);

        return array_map(
            self::publicBatch(...),
            $this->imports->batches($supplierId, AttendanceMeaning::SOURCE_SYSTEM, $periodStart),
        );
    }

    /** @return array{batch:array<string,mixed>,rows:list<array<string,mixed>>}|null */
    public function batch(int $supplierId, int $importId): ?array
    {
        $batch = $this->imports->batch($supplierId, $importId);
        if ($batch === null || $batch['source_system'] !== AttendanceMeaning::SOURCE_SYSTEM) {
            return null;
        }

        return [
            'batch' => self::publicBatch($batch),
            'rows' => array_map(static fn (array $row): array => [
                'employment_id' => $row['employment_id'],
                'employee_name' => $row['employee_name'],
                'employment_code' => $row['employment_code'],
                'meaning' => $row['meaning'],
                'component_code' => $row['component_code'],
                'hours' => $row['quantity_millihours'] === null
                    ? null
                    : AttendanceDecimal::formatMillihours($row['quantity_millihours']),
                'amount_minor' => $row['amount_minor'],
                'source' => $row['source'],
            ], $this->imports->batchRows($supplierId, $importId)),
        ];
    }

    /**
     * Hrubá a čistá mzda z mzdového exportu v dávce proti výpočtu mzdového běhu období.
     *
     * @return array<string,mixed>|null
     */
    public function comparison(int $supplierId, int $importId): ?array
    {
        $batch = $this->imports->batch($supplierId, $importId);
        if ($batch === null || $batch['source_system'] !== AttendanceMeaning::SOURCE_SYSTEM) {
            return null;
        }

        return ['batch' => self::publicBatch($batch)] + AttendanceReferenceComparison::compare(
            $this->imports->batchRows($supplierId, $importId),
            $this->imports->runResultsForPeriod($supplierId, $batch['period'] . '-01'),
        );
    }

    /** @return list<array<string,mixed>> */
    public function profiles(int $supplierId): array
    {
        $outdated = array_column($this->ensureSampleProfile($supplierId), 'id');

        return array_map(
            static fn (array $profile): array => $profile + ['upgrade_available' => in_array($profile['id'], $outdated, true)],
            $this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM),
        );
    }

    /** @return array<string,mixed> */
    public function saveProfile(
        int $supplierId,
        mixed $id,
        mixed $name,
        mixed $rules,
        ?int $userId,
        mixed $components = null,
    ): array {
        if ($id !== null && (!is_int($id) || $id <= 0)) {
            throw new \InvalidArgumentException('Profil mapování nemá platné ID.');
        }
        $name = self::profileName($name);
        $validated = AttendanceRules::validate($rules);
        if ($validated === []) {
            throw new \InvalidArgumentException('Profil musí obsahovat aspoň jedno pravidlo mapování.');
        }

        return $this->profiles->save(
            $supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            $id,
            $name,
            $validated,
            $userId,
            AttendanceProfileComponents::validate($components),
        ) ?? throw new \InvalidArgumentException('Profil mapování neexistuje. Načtěte seznam profilů znovu.');
    }

    public function deleteProfile(int $supplierId, int $id): bool
    {
        return $this->profiles->delete($supplierId, AttendanceMeaning::SOURCE_SYSTEM, $id);
    }

    /**
     * Znovu založí vestavěný vzor (GIRITON, MyÚčto), třeba po omylem smazaném.
     * Vzor vznikne v aktuální verzi a dál se sám aktualizuje; existující vzor
     * téhož klíče se vrátí beze změny. Obsadil-li jeho název vlastní profil,
     * dostane vzor příponu.
     *
     * @return array{profile:array<string,mixed>,restored:bool}
     */
    public function restoreSampleProfile(int $supplierId, ?int $userId, string $sampleKey = AttendanceSampleProfiles::GIRITON): array
    {
        if (!AttendanceSampleProfiles::isKey($sampleKey)) {
            throw new \InvalidArgumentException('Neznámý vzor profilu mapování.');
        }
        $existing = $this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM);
        foreach ($existing as $profile) {
            if (!empty($profile['is_sample']) && $profile['sample_key'] === $sampleKey) {
                return ['profile' => $profile, 'restored' => false];
            }
        }
        $taken = array_map(static fn (array $profile): string => mb_strtolower((string) $profile['name'], 'UTF-8'), $existing);
        $base = AttendanceSampleProfiles::name($sampleKey);
        $name = $base;
        for ($suffix = 2; in_array(mb_strtolower($name, 'UTF-8'), $taken, true); ++$suffix) {
            $name = $base . " ({$suffix})";
        }
        $profile = $this->profiles->save(
            $supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            null,
            $name,
            AttendanceSampleProfiles::rules($sampleKey),
            $userId,
            AttendanceSampleProfiles::components($sampleKey),
            true,
            AttendanceSampleProfiles::version($sampleKey),
            $sampleKey,
        ) ?? throw new \RuntimeException('Vzorový profil se nepodařilo založit.');

        return ['profile' => $profile, 'restored' => true];
    }

    /**
     * Kopie profilu do jiné firmy. Ukázkový příznak se nepřenáší: v cílové
     * firmě je to obyčejný profil, který si účetní upraví po svém.
     *
     * @return array<string,mixed>
     */
    public function copyProfile(int $supplierId, int $id, int $targetSupplierId, ?int $userId): array
    {
        $profile = $this->profiles->find($supplierId, AttendanceMeaning::SOURCE_SYSTEM, $id)
            ?? throw new \OutOfBoundsException('Profil mapování nebyl nalezen.');
        if (!$this->profiles->supplierExists($targetSupplierId)) {
            throw new \InvalidArgumentException('Cílová firma neexistuje.');
        }

        return $this->storeUnique(
            $targetSupplierId,
            $profile['name'],
            AttendanceRules::validate($profile['rules']),
            AttendanceProfileComponents::validate($profile['components']),
            $userId,
        );
    }

    /** @return array<string,mixed> */
    public function exportProfile(int $supplierId, int $id): array
    {
        $profile = $this->profiles->find($supplierId, AttendanceMeaning::SOURCE_SYSTEM, $id)
            ?? throw new \OutOfBoundsException('Profil mapování nebyl nalezen.');

        return [
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'name' => $profile['name'],
            'rules' => $profile['rules'],
            'components' => $profile['components'],
        ];
    }

    /** @return array<string,mixed> */
    public function importProfile(int $supplierId, mixed $payload, mixed $name, ?int $userId): array
    {
        if (!is_array($payload)
            || ($payload['format'] ?? null) !== self::EXPORT_FORMAT
            || ($payload['version'] ?? null) !== self::EXPORT_VERSION) {
            throw new \InvalidArgumentException(
                'Soubor není export profilu mapování z MyÚčta. Vyexportujte profil znovu tlačítkem Export.',
            );
        }
        $rules = AttendanceRules::validate($payload['rules'] ?? null);
        if ($rules === []) {
            throw new \InvalidArgumentException('Importovaný profil neobsahuje žádné pravidlo mapování.');
        }

        return $this->storeUnique(
            $supplierId,
            self::profileName(is_string($name) && trim($name) !== '' ? $name : ($payload['name'] ?? null)),
            $rules,
            AttendanceProfileComponents::validate($payload['components'] ?? null),
            $userId,
        );
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param list<AttendanceProfileComponent> $components
     * @return array<string,mixed>
     */
    private function storeUnique(int $supplierId, string $name, array $rules, array $components, ?int $userId): array
    {
        $taken = array_map(
            static fn (array $profile): string => mb_strtolower($profile['name'], 'UTF-8'),
            $this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM),
        );
        $candidate = $name;
        for ($suffix = 2; in_array(mb_strtolower($candidate, 'UTF-8'), $taken, true); ++$suffix) {
            $candidate = mb_substr($name, 0, 110) . " ({$suffix})";
        }

        return $this->profiles->save(
            $supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            null,
            $candidate,
            $rules,
            $userId,
            $components,
        ) ?? throw new \RuntimeException('Profil mapování se nepodařilo uložit.');
    }

    /**
     * Založí firmě vestavěné vzory, které ještě nedostala, a nedotčené starší
     * vzory převede na aktuální verzi — každý vzor podle svého klíče.
     *
     * @return list<array{id:int,name:string,sample_key:string,sample_version:?int}> upravené vzory starší verze
     */
    private function ensureSampleProfile(int $supplierId): array
    {
        // Ukázka je jen ke čtení: otevřením importu se vzory nezakládají, nepřevádějí
        // ani se na firmě nenastavuje značka, že je dostala. Zobrazí se, co v datech je.
        if ($this->demo->enabled()) {
            return [];
        }
        $modified = [];
        foreach (AttendanceSampleProfiles::keys() as $key) {
            $this->profiles->seedSampleOnce(
                $supplierId,
                AttendanceMeaning::SOURCE_SYSTEM,
                $key,
                AttendanceSampleProfiles::name($key),
                AttendanceSampleProfiles::rules($key),
                AttendanceSampleProfiles::components($key),
                AttendanceSampleProfiles::version($key),
            );
            array_push($modified, ...$this->profiles->upgradeSample(
                $supplierId,
                AttendanceMeaning::SOURCE_SYSTEM,
                $key,
                AttendanceSampleProfiles::version($key),
                AttendanceSampleProfiles::rules($key),
                AttendanceSampleProfiles::components($key),
            ));
        }

        return $modified;
    }

    /**
     * Nabídka nové verze vzoru pro upravený profil. Nese i pravidla a složky
     * vzoru: uloží-li je účetní do profilu (běžné uložení profilu), vzor se
     * při dalším načtení označí jako aktuální. Přednost má vzor, podle kterého
     * náhled právě počítal.
     *
     * @param list<array{id:int,name:string,sample_key:string,sample_version:?int}> $modified
     * @return array<string,mixed>|null
     */
    private static function sampleUpgrade(array $modified, ?int $usedProfileId = null): ?array
    {
        if ($modified === []) {
            return null;
        }
        $offer = $modified[0];
        foreach ($modified as $candidate) {
            if ($candidate['id'] === $usedProfileId) {
                $offer = $candidate;
                break;
            }
        }
        $key = $offer['sample_key'];

        return [
            'profile_id' => $offer['id'],
            'name' => $offer['name'],
            'sample_key' => $key,
            'version' => $offer['sample_version'],
            'latest_version' => AttendanceSampleProfiles::version($key),
            'rules' => AttendanceSampleProfiles::rules($key),
            'components' => AttendanceSampleProfiles::components($key),
        ];
    }

    /**
     * Pravidla v pořadí: z požadavku (neuložený editor) → vybraný profil →
     * nejlépe sedící profil firmy → automatický návrh podle hlaviček.
     *
     * @param list<AttendanceSheet> $sheets
     * @return array{0:list<array<string,mixed>>,1:list<AttendanceProfileComponent>,2:array{id:?int,name:?string,auto:bool}}
     */
    private function resolveMapping(int $supplierId, array $sheets, mixed $rules, ?int $profileId, mixed $components): array
    {
        $profile = null;
        if ($profileId !== null) {
            $profile = $this->profiles->find($supplierId, AttendanceMeaning::SOURCE_SYSTEM, $profileId)
                ?? throw new \InvalidArgumentException('Vybraný profil mapování neexistuje. Vyberte jiný nebo mapujte ručně.');
        }
        if ($rules !== null) {
            return [
                AttendanceRules::validate($rules),
                AttendanceProfileComponents::validate($components ?? $profile['components'] ?? null),
                ['id' => $profile['id'] ?? null, 'name' => $profile['name'] ?? null, 'auto' => false],
            ];
        }
        if ($profile !== null) {
            return [
                AttendanceRules::validate($profile['rules']),
                AttendanceProfileComponents::validate($profile['components']),
                ['id' => $profile['id'], 'name' => $profile['name'], 'auto' => false],
            ];
        }

        $this->ensureSampleProfile($supplierId);
        $best = null;
        $bestScore = 0;
        foreach ($this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM) as $candidate) {
            try {
                $candidateRules = AttendanceRules::validate($candidate['rules']);
                $candidateComponents = AttendanceProfileComponents::validate($candidate['components']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $score = self::mappingScore($this->mapper->map($sheets, $candidateRules)['sheets']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$candidateRules, $candidateComponents, ['id' => $candidate['id'], 'name' => $candidate['name'], 'auto' => true]];
            }
        }

        return $best ?? [[], [], ['id' => null, 'name' => null, 'auto' => true]];
    }

    /**
     * Kolik údajů profil v souborech rozpozná: listy s osobami a v nich
     * sloupce, které výslovně zná (ne automatický návrh, ne „ignorovat").
     * List, na kterém profil nezná ani jeden sloupec, se nepočítá - jinak by
     * profil jiného systému (třeba převod z POHODY) vyhrál nad prázdným výběrem
     * jen díky osobám, které našel automatický návrh.
     *
     * @param list<array<string,mixed>> $mappedSheets
     */
    private static function mappingScore(array $mappedSheets): int
    {
        $score = 0;
        foreach ($mappedSheets as $sheet) {
            if ($sheet['person_column'] === null || $sheet['data_rows'] === 0) {
                continue;
            }
            $known = 0;
            foreach ($sheet['columns'] as $binding) {
                if ($binding['rule_source'] === 'profile' && $binding['meaning'] !== AttendanceMeaning::IGNORE) {
                    ++$known;
                }
            }
            if ($known > 0) {
                $score += 1 + $known;
            }
        }

        return $score;
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{sheets:list<AttendanceSheet>,file_rows:list<array<string,mixed>>,warnings:list<string>}
     */
    private function readFiles(array $files): array
    {
        $names = [];
        foreach ($files as $file) {
            $lower = mb_strtolower($file['name'], 'UTF-8');
            if (isset($names[$lower])) {
                throw new \InvalidArgumentException(
                    "Dva soubory mají stejný název „{$file['name']}“. Jeden přejmenujte, ať jde poznat, odkud která hodnota je.",
                );
            }
            $names[$lower] = true;
        }

        $sheets = [];
        $fileRows = [];
        $warnings = [];
        foreach ($files as $index => $file) {
            try {
                $read = $this->reader->read($file['name'], $index, $file['extension'], $file['content']);
                foreach ($read as $sheet) {
                    $sheets[] = $sheet;
                    array_push($warnings, ...$sheet->warnings);
                }
                $fileRows[] = self::fileRow($file, count($read), null);
            } catch (\InvalidArgumentException $e) {
                $fileRows[] = self::fileRow($file, 0, $e->getMessage());
            }
        }

        return ['sheets' => $sheets, 'file_rows' => $fileRows, 'warnings' => $warnings];
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @param array{sheets:list<AttendanceSheet>,file_rows:list<array<string,mixed>>,warnings:list<string>} $read
     * @param list<array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}> $rules
     * @param list<AttendanceProfileComponent> $profileComponents
     * @param array{id:?int,name:?string,auto:bool} $profileMeta
     * @return array<string,mixed>
     */
    private function compute(
        int $supplierId,
        string $periodStart,
        array $files,
        array $read,
        array $rules,
        array $profileComponents,
        array $profileMeta,
        bool $roundWholeCrowns = false,
    ): array {
        $warnings = $read['warnings'];
        $mapped = $this->mapper->map($read['sheets'], $rules);
        $periodEnd = (new \DateTimeImmutable($periodStart))->format('Y-m-t');
        $matched = $this->matcher->match(
            $supplierId,
            $periodStart,
            $periodEnd,
            $this->aggregator->aggregate($mapped['sheets']),
        );
        $persons = $this->withRateWages($supplierId, $periodStart, $matched['persons']);
        $usedCodes = [];
        $daysInPeriod = (int) (new \DateTimeImmutable($periodStart))->format('t');
        foreach ($persons as &$person) {
            if (is_int($person['worked_days'] ?? null) && $person['worked_days'] > $daysInPeriod) {
                $person['warnings'][] = "Odpracované dny ({$person['worked_days']}) převyšují počet dnů měsíce ({$daysInPeriod}); "
                    . 'počet dnů se nepoužil. Opravte ho v podkladech.';
                $person['worked_days'] = null;
            }
            $birthNumber = $person['_birth_number'];
            if (is_string($birthNumber) && trim($birthNumber) !== '') {
                try {
                    $person['birth_number_masked'] = $this->sensitive->mask(
                        $birthNumber,
                        PayrollSensitiveField::PERSONAL_IDENTIFIER,
                    );
                } catch (\InvalidArgumentException) {
                    $person['birth_number_masked'] = null;
                }
            }
            $bankAccount = $person['_bank_account'] ?? null;
            if (is_string($bankAccount)) {
                try {
                    $bankAccount = $this->profileValidator->normalizeBankAccount($bankAccount);
                    $person['_bank_account'] = $bankAccount;
                    $person['bank_account_masked'] = $this->sensitive->mask($bankAccount, PayrollSensitiveField::BANK_ACCOUNT);
                } catch (\InvalidArgumentException $e) {
                    // Existující osobě import účet nemění, varování patří jen zakládané.
                    if (!in_array($person['match']['status'], ['linked', 'matched'], true)) {
                        $person['warnings'][] = self::bankAccountNote($e, false);
                    }
                }
            }
            foreach (array_keys($person['_components']) as $code) {
                $usedCodes[(string) $code] = true;
            }
            if (is_string($person['end_on'] ?? null) && in_array($person['match']['status'], ['linked', 'matched'], true)) {
                $person['warnings'][] = 'Podklady uvádějí ukončení k ' . self::czechDate($person['end_on'])
                    . '. Zkontrolujte, že je pracovní vztah k tomuto dni ukončený; import ho neukončuje.';
            }
        }
        unset($person);
        $persons = $this->withAgreementHours($supplierId, $periodStart, $persons, $matched['options']);
        $persons = $this->openAgreementMatcher->annotate($supplierId, $periodStart, $persons);
        $persons = $this->withRegistration($supplierId, $periodStart, $persons);

        $codes = [];
        foreach ($mapped['sheets'] as $sheet) {
            foreach ($sheet['columns'] as $binding) {
                foreach ([$binding, ...$binding['conditions']] as $candidate) {
                    if ($candidate['meaning'] === AttendanceMeaning::COMPONENT
                        && $candidate['component_code'] !== null
                        // Složka se hlásí jen tehdy, když sloupec opravdu nese částku.
                        && isset($usedCodes[$candidate['component_code']])) {
                        $codes[$candidate['component_code']] = true;
                    }
                }
            }
        }

        $definitions = [];
        foreach ($profileComponents as $component) {
            $definitions[$component['code']] = $component;
        }
        foreach ($mapped['auto_components'] as $code => $name) {
            $definitions[$code] ??= ['code' => $code, 'name' => $name, 'kind' => 'other'];
        }
        // Mzda dopočtená ze sazby a hodin ({@see self::withRateWages()}) nemá
        // sloupec v mapování; složka se hlásí a zakládá jako ostatní.
        if (isset($usedCodes[self::RATE_WAGE_COMPONENT['code']])) {
            $codes[self::RATE_WAGE_COMPONENT['code']] = true;
            $definitions[self::RATE_WAGE_COMPONENT['code']] ??= self::RATE_WAGE_COMPONENT;
        }

        /*
         * Vstupní brána čte složky přes PayrollComponentRepository, který si
         * výchozí katalog (ODMENA, MZDA_UKOLOVA, …) zakládá sám. Bez téhož
         * kroku by náhled hlásil „složka chybí" u něčeho, co použití přijme.
         */
        if ($codes !== []) {
            $this->components->ensureDefaults($supplierId);
        }
        $checks = [];
        $wholeCrownCodes = [];
        foreach (array_keys($codes) as $code) {
            $code = (string) $code;
            $component = $this->inputRepository->resolveComponent($supplierId, $code, $periodStart);
            $definition = $definitions[$code] ?? null;
            // Složku, kterou import teprve založí, posuzuje podle definice, se kterou ji založí.
            $classified = $component === null
                ? ($definition === null ? [] : AttendanceProfileComponents::definition($definition, $periodStart))
                : $component;
            if (PayrollWholeCrowns::required($classified)) {
                $wholeCrownCodes[$code] = true;
            }
            $checks[] = match (true) {
                $component === null && $definition !== null => [
                    'component_code' => $code,
                    'status' => 'will_create',
                    'name' => $definition['name'],
                    'kind' => $definition['kind'],
                    'message' => "Mzdová složka {$code} ({$definition['name']}) ve firmě zatím není. "
                        . 'Import ji na vaše potvrzení založí jako jednorázovou složku.',
                ],
                $component === null => [
                    'component_code' => $code,
                    'status' => 'missing',
                    'name' => null,
                    'kind' => null,
                    'message' => "Mzdová složka {$code} v období neexistuje nebo není jednoznačně účinná. "
                        . 'Založte ji v Nastavení mezd → Mzdové složky, jinak se částky nepřenesou.',
                ],
                ($component['frequency_kind'] ?? null) !== 'one_off' => [
                    'component_code' => $code,
                    'status' => 'not_one_off',
                    'name' => $component['name'] ?? null,
                    'kind' => $component['component_kind'] ?? null,
                    'message' => "Mzdová složka {$code} není jednorázová. Import přenáší jen jednorázové složky; vyberte jinou.",
                ],
                default => [
                    'component_code' => $code,
                    'status' => 'ok',
                    'name' => $component['name'] ?? null,
                    'kind' => $component['component_kind'] ?? null,
                    'message' => null,
                ],
            };
        }

        $unrecognized = [];
        foreach ($mapped['sheets'] as $sheet) {
            if ($sheet['person_column'] === null || $sheet['data_rows'] === 0) {
                continue;
            }
            foreach ($sheet['columns'] as $binding) {
                if ($binding['rule_source'] !== 'none' || $binding['samples'] === []) {
                    continue;
                }
                $unrecognized[] = [
                    'sheet_id' => $sheet['sheet']->id(),
                    'letter' => $binding['letter'],
                    'header' => $binding['header'],
                    'samples' => $binding['samples'],
                ];
            }
        }

        /*
         * Částky složek vykazovaných v JMHZ s haléři by měsíční hlášení odmítlo
         * až po schválení mzdy. Volbou se zaokrouhlí nahoru už tady, aby náhled
         * i vzniklé vstupy nesly totéž; náhled u složky ukáže původní částku.
         */
        $halereComponents = 0;
        foreach ($persons as &$person) {
            foreach ($person['components'] as &$component) {
                $code = (string) $component['component_code'];
                if (!isset($wholeCrownCodes[$code]) || !PayrollWholeCrowns::hasHalere((int) $component['amount_minor'])) {
                    continue;
                }
                ++$halereComponents;
                if (!$roundWholeCrowns) {
                    continue;
                }
                $rounded = PayrollWholeCrowns::roundUpMinor((int) $component['amount_minor']);
                $component['rounded_from_minor'] = (int) $component['amount_minor'];
                $component['amount_minor'] = $rounded;
                $component['amount'] = AttendanceDecimal::formatMinor($rounded);
                if (isset($person['_components'][$code])) {
                    $person['_components'][$code]['amount_minor'] = $rounded;
                }
            }
            unset($component);
        }
        unset($person);

        $summary = [
            'persons' => count($persons),
            'matched' => 0,
            'ambiguous' => 0,
            'not_found' => 0,
            'conflicts' => 0,
            'worked_days' => 0,
            'metrics' => 0,
            'components' => 0,
            'amount_minor_total' => 0,
            'deductions' => 0,
            'deduction_minor_total' => 0,
            'registration_gaps' => 0,
            'halere_components' => $halereComponents,
            // Mzda ze sazby a hodin: kolik osob ji dostane (a za kolik), kolik ne a proč.
            'rate_wages' => ['computed' => 0, 'amount_minor' => 0, 'no_hours' => 0, 'hourly_terms' => 0],
        ];
        foreach ($persons as $person) {
            $rateWage = $person['rate_wage']['status'] ?? null;
            if ($rateWage === 'computed') {
                ++$summary['rate_wages']['computed'];
                $summary['rate_wages']['amount_minor'] += (int) $person['_components'][self::RATE_WAGE_COMPONENT['code']]['amount_minor'];
            } elseif ($rateWage === 'no_hours' || $rateWage === 'hourly_terms') {
                ++$summary['rate_wages'][$rateWage];
            }
            $status = $person['match']['status'];
            if ($status === 'linked' || $status === 'matched') {
                ++$summary['matched'];
                if (($person['registration'] ?? []) !== []) {
                    ++$summary['registration_gaps'];
                }
            } elseif ($status === 'ambiguous') {
                ++$summary['ambiguous'];
            } else {
                ++$summary['not_found'];
                if ($status === 'conflict') {
                    ++$summary['conflicts'];
                }
            }
            if (is_int($person['worked_days'] ?? null)) {
                ++$summary['worked_days'];
            }
            $summary['metrics'] += count($person['metrics']);
            $summary['components'] += count($person['components']);
            foreach ($person['components'] as $component) {
                $summary['amount_minor_total'] += $component['amount_minor'];
            }
            $summary['deductions'] += count($person['deductions']);
            foreach ($person['deductions'] as $deduction) {
                $summary['deduction_minor_total'] += $deduction['amount_minor'];
            }
        }

        $matchedPairs = [];
        foreach ($persons as $person) {
            if (in_array($person['match']['status'], ['linked', 'matched'], true) && is_int($person['match']['employment_id'])) {
                $matchedPairs[] = ['person' => $person, 'employment_id' => $person['match']['employment_id']];
            }
        }
        [$wageChanges, $wageWarnings] = $this->wageChanges(
            $supplierId,
            $periodStart,
            $matchedPairs,
            self::componentKinds($checks, array_values($definitions)),
        );
        array_push($warnings, ...$wageWarnings);
        [$persons, $probableChanges] = $this->probableEarnings(
            $supplierId,
            $periodStart,
            $persons,
            self::componentKinds($checks, array_values($definitions)),
        );

        return [
            'period' => substr($periodStart, 0, 7),
            'content_hash' => hash('sha256', implode("\n", self::sortedHashes($files))),
            'profile' => $profileMeta,
            'files' => $read['file_rows'],
            'sheets' => array_map(static fn (array $sheet): array => [
                'id' => $sheet['sheet']->id(),
                'file' => $sheet['sheet']->file,
                'sheet' => $sheet['sheet']->name,
                'header_row' => $sheet['layout']->headerRow,
                'data_rows' => $sheet['data_rows'],
                'used' => $sheet['person_column'] !== null && $sheet['data_rows'] > 0,
                'columns' => array_values(array_map(static fn (array $binding): array => [
                    'letter' => $binding['letter'],
                    'header' => $binding['header'],
                    'meaning' => $binding['meaning'],
                    'unit' => $binding['unit'],
                    'component_code' => $binding['component_code'],
                    'rule_source' => $binding['rule_source'],
                    'samples' => $binding['samples'],
                    'conditions' => array_map(static fn (array $condition): array => [
                        'when_header' => $condition['when_header'],
                        'when_value' => $condition['when_value'],
                        'meaning' => $condition['meaning'],
                        'component_code' => $condition['component_code'],
                    ], $binding['conditions']),
                ], $sheet['columns'])),
            ], $mapped['sheets']),
            'rules' => $mapped['rules'],
            'component_definitions' => array_values($definitions),
            'employment_options' => $matched['options'],
            'persons' => $persons,
            'component_checks' => $checks,
            'wage_changes' => $wageChanges,
            'probable_earning_changes' => $probableChanges,
            'unrecognized_columns' => $unrecognized,
            'round_whole_crowns' => $roundWholeCrowns,
            'whole_crowns_default' => PayrollWholeCrowns::jmhzPeriod($periodStart),
            'summary' => $summary,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Založí mzdové složky, které přiřazené osoby potřebují a firma nemá.
     * Jen ty, které profil (nebo pravidlo „podle hlavičky") definuje — cizí
     * kód bez definice zůstane chybou vstupu, nic se nehádá.
     *
     * @param list<array{person:array<string,mixed>,employment:array<string,mixed>}> $assigned
     * @param list<AttendanceProfileComponent> $definitions
     * @return list<string>
     */
    private function createMissingComponents(int $supplierId, string $periodStart, array $assigned, array $definitions): array
    {
        $byCode = [];
        foreach ($definitions as $definition) {
            $byCode[$definition['code']] = $definition;
        }
        $needed = [];
        foreach ($assigned as $item) {
            foreach (array_keys($item['person']['_components']) as $code) {
                $needed[(string) $code] = true;
            }
        }
        $created = [];
        $validFrom = substr($periodStart, 0, 4) . '-01-01';
        foreach (array_keys($needed) as $code) {
            $code = (string) $code;
            if (!isset($byCode[$code]) || $this->inputRepository->resolveComponent($supplierId, $code, $periodStart) !== null) {
                continue;
            }
            // Import staršího roku po novějším (převod předchozího roku až po letošním):
            // složka už má pozdější verzi, starší tedy končí den před ní.
            $definition = AttendanceProfileComponents::definition($byCode[$code], $validFrom);
            $next = $this->components->nextVersionStart($supplierId, $code, $validFrom);
            if ($next !== null) {
                $definition['valid_to'] = (new \DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
            }
            $this->components->create($supplierId, $definition);
            $created[] = $code;
        }

        return $created;
    }

    /**
     * @param list<array{person:array<string,mixed>,employment:array<string,mixed>}> $assigned
     * @return array{import_id:?int,created:int,updated:int,overridden:int,duplicates:int,errors:list<array{row_number:int,error_message:string}>}
     */
    private function createInputs(int $supplierId, string $period, int $importId, array $assigned, ?int $userId): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('CSV mzdových vstupů nelze sestavit.');
        }
        $lines = [];
        try {
            fputcsv($stream, ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'], ';', '"', '');
            foreach ($assigned as $item) {
                $employment = $item['employment'];
                foreach ($item['person']['_components'] as $code => $component) {
                    fputcsv($stream, [
                        (string) $employment['employment_id'],
                        (string) $employment['code'],
                        (string) $code,
                        (string) $component['amount_minor'],
                        "attendance:{$period}:{$employment['employment_id']}:{$code}",
                    ], ';', '"', '');
                    $lines[count($lines) + 2] = $item['person']['display_name'] . ' · ' . $code;
                }
            }
            if ($lines === []) {
                return ['import_id' => null, 'created' => 0, 'updated' => 0, 'overridden' => 0, 'duplicates' => 0, 'errors' => []];
            }
            rewind($stream);
            $csv = (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        $result = $this->inputs->apply($supplierId, $period, 'csv', "dochazka-{$period}.csv", $csv, $userId);
        $inputImportId = (int) $result['id'];
        $this->imports->attachInputImport($supplierId, $importId, $inputImportId);
        $replayed = ($result['replayed'] ?? false) === true;
        $errors = [];
        foreach ($result['rows'] ?? [] as $row) {
            if (($row['status'] ?? null) !== 'error') {
                continue;
            }
            $rowNumber = (int) ($row['source_row_number'] ?? 0);
            $messages = array_map(
                static fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? '') : '',
                is_array($row['errors'] ?? null) ? $row['errors'] : [],
            );
            $errors[] = [
                'row_number' => $rowNumber,
                'error_message' => ($lines[$rowNumber] ?? "Řádek {$rowNumber}") . ': ' . implode(' ', array_filter($messages)),
            ];
        }

        return [
            'import_id' => $inputImportId,
            // `accepted_count` počítá jen nové vstupy; aktualizace konceptu
            // a ručně přepsané řádky nese brána zvlášť.
            'created' => $replayed ? 0 : (int) ($result['accepted_count'] ?? 0),
            'updated' => $replayed ? 0 : (int) ($result['updated_count'] ?? 0),
            'overridden' => $replayed ? 0 : (int) ($result['overridden_count'] ?? 0),
            'duplicates' => $replayed
                ? (int) ($result['accepted_count'] ?? 0) + (int) ($result['duplicate_count'] ?? 0)
                : (int) ($result['duplicate_count'] ?? 0),
            'errors' => $errors,
        ];
    }

    /**
     * Srážky z podkladů jako dohody o srážce platné jen pro importovaný měsíc.
     * Identita dohody je reference `attendance:{měsíc}:{zaměstnanec}:{druh}`,
     * takže opakovaný import dohodu opraví, místo aby založil druhou. Dohodu,
     * ze které schválená mzda už něco strhla, import nemění — jen to ohlásí.
     * Dohoda patří zaměstnanci, srážky z více jeho vztahů se sečtou.
     *
     * @param list<array{person:array<string,mixed>,employment:array<string,mixed>}> $assigned
     * @return array{created:int,updated:int,unchanged:int,conflicts:list<array{key:string,display_name:string,reason:string}>}
     */
    private function createDeductions(int $supplierId, string $periodStart, array $assigned, ?int $userId): array
    {
        $period = substr($periodStart, 0, 7);
        $periodEnd = (new \DateTimeImmutable($periodStart))->format('Y-m-t');
        $wanted = [];
        foreach ($assigned as $item) {
            foreach ($item['person']['_deductions'] ?? [] as $meaning => $deduction) {
                [$referenceKey, $kind] = self::DEDUCTION_KINDS[(string) $meaning] ?? [null, null];
                if ($referenceKey === null) {
                    continue;
                }
                $employeeId = (int) $item['employment']['employee_id'];
                $entry = $wanted[$employeeId][$referenceKey] ?? [
                    'kind' => $kind,
                    'amount_minor' => 0,
                    'sources' => [],
                    'key' => (string) $item['person']['key'],
                    'display_name' => (string) $item['person']['display_name'],
                ];
                $entry['amount_minor'] += (int) $deduction['amount_minor'];
                $entry['sources'][] = (string) $deduction['source'];
                $wanted[$employeeId][$referenceKey] = $entry;
            }
        }

        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => []];
        foreach ($wanted as $employeeId => $kinds) {
            foreach ($kinds as $referenceKey => $entry) {
                $kind = $entry['kind'];
                $conflict = static fn (string $reason): array => [
                    'key' => $entry['key'],
                    'display_name' => $entry['display_name'],
                    'reason' => $reason,
                ];
                $reference = "attendance:{$period}:{$employeeId}:{$referenceKey}";
                try {
                    $terms = DeductionAgreementTerms::fromRequest([
                        'agreement_reference' => $reference,
                        'title' => ($kind === 'meal' ? 'Obědy – úhrada zaměstnance ' : 'Srážky z podkladů docházky ')
                            . (new \DateTimeImmutable($periodStart))->format('n/Y'),
                        'deduction_kind' => $kind,
                        'priority_no' => self::DEDUCTION_PRIORITY,
                        'requested_minor' => $entry['amount_minor'],
                        'total_limit_minor' => $entry['amount_minor'],
                        'valid_from' => $periodStart,
                        'valid_to' => $periodEnd,
                        'note' => mb_substr(
                            "Import docházky za {$period} (" . implode(', ', $entry['sources']) . '). '
                            . ($kind === 'meal'
                                ? 'Srážka na základě dohody o srážkách ze mzdy (§ 146 písm. b) zákoníku práce).'
                                : 'Srážka převzatá z podkladů; dohoda o srážkách podle občanského zákoníku není doložená.'),
                            0,
                            500,
                        ),
                    ]);
                    $existing = $this->deductions->findByReference($supplierId, $employeeId, $reference);
                    if ($existing === null) {
                        $this->deductions->create($supplierId, $employeeId, $terms, DeductionAgreementStatus::Active, $userId);
                        ++$report['created'];
                        continue;
                    }
                    if ($existing['requested_minor'] === $entry['amount_minor']) {
                        ++$report['unchanged'];
                        continue;
                    }
                    if ($existing['withheld_total_minor'] > 0) {
                        $report['conflicts'][] = $conflict(
                            "Srážka za {$period} už byla použita ve schválené mzdě ("
                            . AttendanceDecimal::formatMinor($existing['requested_minor']) . ' Kč), podklady teď uvádějí '
                            . AttendanceDecimal::formatMinor($entry['amount_minor']) . ' Kč. Opravte ji ručně v Dohodách o srážkách.',
                        );
                        continue;
                    }
                    $this->deductions->update(
                        $supplierId,
                        $existing['id'],
                        $terms,
                        $existing['row_version'],
                        null,
                        "Oprava z importu docházky za {$period}.",
                        $userId,
                    );
                    ++$report['updated'];
                } catch (PayrollDeductionAgreementConflictException) {
                    $report['conflicts'][] = $conflict('Dohodu o srážce mezitím změnil někdo jiný. Použijte import znovu.');
                } catch (\DomainException|\InvalidArgumentException $e) {
                    $report['conflicts'][] = $conflict($e->getMessage());
                }
            }
        }

        return $report;
    }

    /**
     * Sazba náhrady za překážku na straně zaměstnavatele z pravidel profilu;
     * bez ní platí výchozí sazby materializace (80 %).
     *
     * @param list<array<string,mixed>> $rules
     */
    private static function compensationRates(array $rules): ?ImportAbsenceCompensationRates
    {
        $rate = AttendanceRules::obstacleEmployerRate($rules);

        return $rate === null ? null : ImportAbsenceCompensationRates::fromMap([AttendanceRules::RATE_MEANING => $rate]);
    }

    private static function czechDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }

    /**
     * Roční limit DPP (§ 75 ZP) s odpracovanými hodinami z importovaného měsíce.
     * Součet za celý rok bere {@see PayrollAgreementAnnualHours::yearWithoutMonth()}
     * bez uloženého souhrnu importovaných vztahů za importovaný měsíc, protože
     * dávka ho teprve zapíše nebo přepíše. Pozdější měsíce (import staršího
     * měsíce) i jiná dohoda téže osoby za týž měsíc se tak počítají.
     *
     * @param list<array<string,mixed>> $persons
     * @param list<array<string,mixed>> $options
     * @return list<array<string,mixed>>
     */
    private function withAgreementHours(int $supplierId, string $periodStart, array $persons, array $options): array
    {
        $employeeIds = array_keys(self::importedAgreementMillihours($persons, $options));
        if ($employeeIds === []) {
            return $persons;
        }
        $agreements = self::agreementEmploymentIds($options);
        $employmentIds = [];
        foreach ($persons as $person) {
            if (self::agreementEmployeeId($person, $agreements) !== null) {
                $employmentIds[(int) $person['match']['employment_id']] = true;
            }
        }
        $stored = (new PayrollAgreementAnnualHours($this->db))->yearWithoutMonth(
            $supplierId,
            $employeeIds,
            $periodStart,
            array_keys($employmentIds),
        );

        return self::annotateAgreementHours($persons, $options, $stored, (int) substr($periodStart, 0, 4));
    }

    /**
     * Připíše k osobám přiřazeným na DPP roční součet po importu a při dosažení
     * prahu nebo překročení limitu varování. Import nezastavuje.
     *
     * @param list<array<string,mixed>> $persons
     * @param list<array<string,mixed>> $options volby vztahů s `employment_id` a `relation_type`
     * @param array<int,array{own_minutes:?int,takeover_minutes:int,total_minutes:?int,own_duration_units?:?int,total_duration_units?:?int,manual_review_required?:bool}> $prior roční součet
     *        bez uloženého souhrnu importovaných vztahů za importovaný měsíc
     * @return list<array<string,mixed>>
     */
    public static function annotateAgreementHours(array $persons, array $options, array $prior, int $year): array
    {
        $imported = self::importedAgreementMillihours($persons, $options);
        $agreements = self::agreementEmploymentIds($options);
        foreach ($persons as &$person) {
            $employeeId = self::agreementEmployeeId($person, $agreements);
            if ($employeeId === null) {
                continue;
            }
            $before = $prior[$employeeId] ?? ['own_minutes' => 0, 'takeover_minutes' => 0, 'total_minutes' => 0];
            $importedMinutes = intdiv(($imported[$employeeId] ?? 0) * 60, 1000);
            $summary = PayrollAgreementAnnualHours::summary($year, PayrollAgreementAnnualHours::addOwnMinutes($before, $importedMinutes));
            $person['dpp_annual_hours'] = $summary + ['imported_minutes' => $importedMinutes];
            if ($summary['status'] !== PayrollAgreementAnnualHours::STATUS_OK) {
                $name = $person['match']['employee_name'] ?? null;
                $person['warnings'][] = 'Se započtením této docházky: ' . PayrollAgreementAnnualHours::statusMessage(
                    $summary['status'],
                    is_string($name) && $name !== '' ? $name : (string) ($person['display_name'] ?? ''),
                    $year,
                    $summary['total_minutes'] ?? 0,
                    $summary['takeover_minutes'],
                    $summary['total_duration_units'] ?? null,
                );
            }
        }
        unset($person);

        return $persons;
    }

    /**
     * Importované odpracované hodiny po osobách, jen u řádků přiřazených na DPP.
     *
     * @param list<array<string,mixed>> $persons
     * @param list<array<string,mixed>> $options
     * @return array<int,int> employee_id => tisíciny hodiny
     */
    private static function importedAgreementMillihours(array $persons, array $options): array
    {
        $agreements = self::agreementEmploymentIds($options);
        $result = [];
        foreach ($persons as $person) {
            $employeeId = self::agreementEmployeeId($person, $agreements);
            if ($employeeId === null) {
                continue;
            }
            $worked = $person['_metrics']['worked_hours']['millihours'] ?? 0;
            $result[$employeeId] = ($result[$employeeId] ?? 0) + (is_int($worked) ? $worked : 0);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $person
     * @param array<int,true> $agreements ID vztahů DPP
     */
    private static function agreementEmployeeId(array $person, array $agreements): ?int
    {
        $match = is_array($person['match'] ?? null) ? $person['match'] : [];
        if (!in_array($match['status'] ?? null, ['linked', 'matched'], true)
            || !is_int($match['employment_id'] ?? null)
            || !is_int($match['employee_id'] ?? null)
            || !isset($agreements[$match['employment_id']])
        ) {
            return null;
        }

        return $match['employee_id'];
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return array<int,true>
     */
    private static function agreementEmploymentIds(array $options): array
    {
        $result = [];
        foreach ($options as $option) {
            if (($option['relation_type'] ?? null) === 'dpp' && is_int($option['employment_id'] ?? null)) {
                $result[$option['employment_id']] = true;
            }
        }

        return $result;
    }

    /**
     * Údaje pro registraci ČSSZ z podkladů ({@see AttendanceRegistrationData}).
     * Osobě k založení se ukáže všechno, co se při založení zapíše; osobě
     * s vztahem v období jen to, co jí v evidenci chybí. Stav evidence se čte
     * jedním během dotazů pro všechny osoby.
     *
     * @param list<array<string,mixed>> $persons
     * @return list<array<string,mixed>>
     */
    private function withRegistration(int $supplierId, string $periodStart, array $persons): array
    {
        $pairs = [];
        foreach ($persons as $index => $person) {
            $persons[$index]['registration'] = [];
            $persons[$index]['_registration_plan'] = null;
            if (($person['_registration'] ?? []) === []) {
                continue;
            }
            $start = is_string($person['start_on'] ?? null) ? $person['start_on'] : $periodStart;
            $plan = AttendanceRegistrationData::plan($person['_registration'], $start, $this->registrationCodebooks);
            $persons[$index]['_registration_plan'] = $plan;
            array_push($persons[$index]['warnings'], ...$plan['warnings']);
            if (in_array($person['match']['status'], ['linked', 'matched'], true)
                && is_int($person['match']['employment_id']) && is_int($person['match']['employee_id'])) {
                $pairs[$index] = ['employee_id' => $person['match']['employee_id'], 'employment_id' => $person['match']['employment_id']];
            }
        }
        $state = $this->imports->registrationState(
            $supplierId,
            array_values($pairs),
            $periodStart,
            (int) substr($periodStart, 0, 4),
        );
        foreach ($persons as $index => $person) {
            $plan = $person['_registration_plan'];
            if ($plan === null) {
                continue;
            }
            if (isset($pairs[$index])) {
                $plan = isset($state[$pairs[$index]['employment_id']])
                    ? AttendanceRegistrationData::gaps($plan, $state[$pairs[$index]['employment_id']])
                    : null;
                $persons[$index]['_registration_plan'] = $plan;
            }
            $persons[$index]['registration'] = $plan === null ? [] : AttendanceRegistrationData::display($plan);
        }

        return $persons;
    }

    /**
     * Zapíše údaje pro registraci jedné osobě: skupiny věty registrace stejnou
     * cestou jako import registrace ČSSZ a podepsané prohlášení přes hromadný
     * zápis prohlášení (ten osobu s podepsaným prohlášením přeskočí).
     *
     * @param array<string,mixed> $plan {@see AttendanceRegistrationData::plan()}
     * @return list<string> co se nezapsalo a proč
     */
    private function writeRegistration(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $decisive,
        array $plan,
        int $taxYear,
        ?int $userId,
    ): array {
        $notes = [];
        if ($plan['steps'] !== []) {
            $notes = $this->registrationWriter->supplement(
                $supplierId,
                $employeeId,
                $employmentId,
                $decisive,
                $plan['steps'],
                'attendance-import:' . substr($decisive, 0, 7),
                $userId,
                null,
                null,
            )['notes'];
        }
        if (is_array($plan['declaration'])) {
            try {
                $result = $this->declarations->apply(
                    $supplierId,
                    $taxYear,
                    $plan['declaration']['signed_on'],
                    [$employeeId],
                    $userId,
                    null,
                    null,
                );
                $reasons = array_merge(...array_map(
                    static fn (array $item): array => array_map('strval', (array) ($item['reasons'] ?? [$item['message'] ?? ''])),
                    [...$result['skipped'], ...$result['failed']],
                ));
                if ($result['counts']['applied'] === 0 && !in_array('already_signed', $reasons, true)) {
                    $notes[] = 'Prohlášení poplatníka se nezapsalo (' . implode(', ', array_filter($reasons)) . '), doplňte ho na kartě osoby.';
                }
            } catch (\InvalidArgumentException|\DomainException $e) {
                $notes[] = 'Prohlášení poplatníka: ' . $e->getMessage();
            }
        }

        return $notes;
    }

    /**
     * Doplní údaje pro registraci přiřazeným osobám, kterým v evidenci chybí.
     * Každá osoba zvlášť; chyba jedné ostatní nezastaví.
     *
     * @param list<array{person:array<string,mixed>,employment:array<string,mixed>}> $assigned
     * @return array{filled:int,notes:list<array{key:string,display_name:string,reason:string}>}
     */
    private function fillRegistration(int $supplierId, string $periodStart, array $assigned, ?int $userId): array
    {
        $filled = 0;
        $notes = [];
        $wanted = array_values(array_filter(
            $assigned,
            static fn (array $item): bool => is_array($item['person']['_registration_plan'] ?? null)
                && !AttendanceRegistrationData::isEmpty($item['person']['_registration_plan']),
        ));
        $starts = $this->imports->employmentStarts(
            $supplierId,
            array_map(static fn (array $item): int => (int) $item['employment']['employment_id'], $wanted),
        );
        foreach ($wanted as $item) {
            $plan = $item['person']['_registration_plan'];
            $employmentId = (int) $item['employment']['employment_id'];
            // Údaj chyběl od začátku vztahu, doplňuje se proto k jeho nástupu.
            $reasons = $this->writeRegistration(
                $supplierId,
                (int) $item['employment']['employee_id'],
                $employmentId,
                $starts[$employmentId] ?? $periodStart,
                $plan,
                (int) substr($periodStart, 0, 4),
                $userId,
            );
            ++$filled;
            foreach ($reasons as $reason) {
                $notes[] = [
                    'key' => (string) $item['person']['key'],
                    'display_name' => (string) $item['person']['display_name'],
                    'reason' => $reason,
                ];
            }
        }

        return ['filled' => $filled, 'notes' => $notes];
    }

    /**
     * Otevřená DPP místo nové ({@see PayrollOpenAgreements}). Volba
     * `use_open_agreement` nese id dohody, `create_new_agreement` výslovné
     * založení nové. Když náhled u osoby otevřenou dohodu našel a volba chybí,
     * nová DPP se nezaloží: výchozí je použít tu otevřenou.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed>|null $open dohoda, kterou k osobě našel náhled
     * @return array{employee_id:int,employment_id:int,message:string}|null null = zakládá se nová osoba
     */
    private function reuseOpenAgreement(
        int $supplierId,
        array $item,
        ?array $open,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): ?array {
        if (($item['relation_type'] ?? null) !== 'dpp' || ($item['create_new_agreement'] ?? false) === true) {
            return null;
        }
        $chosen = $item['use_open_agreement'] ?? null;
        if ($chosen === null) {
            if ($open === null || ($open['reusable'] ?? false) !== true) {
                return null;
            }
            throw new \InvalidArgumentException(
                "Osoba už má otevřenou DPP {$open['code']}. Použijte ji, nebo výslovně zvolte založení nové dohody. "
                . PayrollOpenAgreements::GUIDANCE,
            );
        }
        if (!is_int($chosen) || $chosen <= 0) {
            throw new \InvalidArgumentException('Otevřená dohoda k použití není platná.');
        }
        if ($open !== null && (int) $open['employment_id'] !== $chosen) {
            throw new \InvalidArgumentException('Vybraná dohoda nepatří osobě z podkladů. Načtěte náhled znovu.');
        }
        $start = is_string($item['planned_start_on'] ?? null) ? $item['planned_start_on'] : '';
        if (!\MyInvoice\Http\IsoDate::isValid($start)) {
            throw new \InvalidArgumentException('Nástup nové dohody není platné datum.');
        }
        [$end] = self::fixedTermEnd($item['fixed_term_end_on'] ?? null, $start);
        $reused = $this->openAgreements->reuse(
            $supplierId,
            $chosen,
            $open === null ? null : (int) $open['employee_id'],
            $start,
            $end,
            $userId,
            $ip,
            $userAgent,
        );
        $this->saveLinks(
            $supplierId,
            is_string($item['personal_number'] ?? null) && !($item['_number_ambiguous'] ?? false) ? $item['personal_number'] : '',
            AttendanceText::personKey((string) ($item['full_name'] ?? '')),
            $reused['employee_id'],
            $reused['employment_id'],
            $userId,
        );

        return [
            'employee_id' => $reused['employee_id'],
            'employment_id' => $reused['employment_id'],
            'message' => 'Použila se otevřená dohoda, nová DPP se nezaložila'
                . ($reused['extended_to'] === null ? '.' : ' a sjednaný konec se prodloužil do ' . self::czechDate($reused['extended_to']) . '.'),
        ];
    }

    /**
     * Podmínky nového vztahu z řádku zakládání: úvazek, měsíční mzda, sjednaný
     * konec a pravděpodobný výdělek (ze sazby, jinak z odměny za hodiny).
     * Nečitelné údaje vztah neshodí, vrátí se v `notes`.
     *
     * @param array<string,mixed> $item
     * @return array{weekly_hours:?string,monthly_gross:?int,fixed_end:?string,hourly_minor:?int,rationale:?string,notes:list<string>}
     */
    private static function employmentTerms(array $item, string $plannedStart): array
    {
        // Úvazek z podkladů bývá i text („noční - 18,75", „DPP"); nepřečtený
        // osobu neshodí, vztah vznikne bez něj a výsledek řekne, co doplnit.
        $rawWeekly = $item['weekly_hours'] ?? null;
        if (is_float($rawWeekly) || is_int($rawWeekly)) {
            $rawWeekly = sprintf('%.2F', $rawWeekly);
        }
        $weeklyHours = null;
        $notes = [];
        if (is_string($rawWeekly) && trim($rawWeekly) !== '') {
            $weeklyHours = AttendanceDecimal::weeklyHours($rawWeekly);
            if ($weeklyHours === null && !in_array($item['relation_type'] ?? null, ['dpp', 'dpc'], true)) {
                $notes[] = sprintf(
                    'Úvazek „%s“ z podkladů nejde přečíst, vztah je založený bez něj. Doplňte ho na kartě zaměstnance.',
                    mb_substr(trim($rawWeekly), 0, 40),
                );
            }
        } elseif ($rawWeekly !== null) {
            throw new \InvalidArgumentException('Týdenní pracovní doba není platná.');
        }
        $monthlyGross = $item['monthly_gross'] ?? null;
        if ($monthlyGross !== null && !is_int($monthlyGross)) {
            throw new \InvalidArgumentException('Měsíční mzda musí být celé číslo v korunách.');
        }
        [$hourlyMinor, $hourlyNote] = self::hourlyRateMinor($item['hourly_rate'] ?? null);
        [$fixedEnd, $endNote] = self::fixedTermEnd($item['fixed_term_end_on'] ?? null, $plannedStart);
        array_push($notes, ...array_filter([$hourlyNote, $endNote]));
        $proposal = $hourlyMinor === null ? null : ['source' => 'rate', 'hourly_minor' => $hourlyMinor, 'amount_minor' => null, 'hours' => null];
        // Bez sazby v podkladech poslouží odměna za odpracované hodiny (návrh z náhledu, jen ze souborů).
        if ($proposal === null && $hourlyNote === null && is_array($item['_probable_earning'] ?? null)) {
            $proposal = $item['_probable_earning'];
            $hourlyMinor = (int) $proposal['hourly_minor'];
        }

        return [
            'weekly_hours' => $weeklyHours,
            'monthly_gross' => $monthlyGross,
            'fixed_end' => $fixedEnd,
            // Sazba jde jen do pravděpodobného výdělku (§ 355 ZP), ne do
            // hodinového odměňování — viz self::probableEarnings().
            'hourly_minor' => $hourlyMinor,
            'rationale' => $proposal === null ? null : self::probableRationale($proposal, $plannedStart),
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array{employee_id:int,employment_id:int,message:?string}
     */
    private function createPerson(
        int $supplierId,
        array $item,
        string $today,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $plannedStart = is_string($item['planned_start_on'] ?? null) ? $item['planned_start_on'] : '';
        $terms = self::employmentTerms($item, $plannedStart);
        $notes = $terms['notes'];
        $input = [
            'full_name' => $item['full_name'] ?? null,
            'first_name' => $item['first_name'] ?? null,
            'last_name' => $item['last_name'] ?? null,
            'birth_number' => $item['birth_number'] ?? null,
            'birth_date' => $item['birth_date'] ?? null,
            'health_insurer_code' => $item['health_insurer_code'] ?? null,
            'relation_type' => $item['relation_type'] ?? null,
            'planned_start_on' => $item['planned_start_on'] ?? null,
            'weekly_hours' => $terms['weekly_hours'],
            'monthly_gross' => $terms['monthly_gross'],
            'fixed_term_end_on' => $terms['fixed_end'],
            'probable_hourly_earning_minor' => $terms['hourly_minor'],
            'probable_earning_rationale' => $terms['rationale'],
        ];
        $person = $this->personCreate->create($supplierId, $input, $userId, $ip, $userAgent);
        $employeeId = (int) ($person['id'] ?? 0);
        $employment = $this->imports->latestEmploymentOfEmployee($supplierId, $employeeId)
            ?? throw new \LogicException('Nově založený pracovní vztah nebyl nalezen.');

        $bankAccount = $item['bank_account'] ?? null;
        if (is_string($bankAccount) && trim($bankAccount) !== '') {
            $accountNote = $this->savePayoutAccount($supplierId, $employeeId, trim($bankAccount), $plannedStart, $userId);
            if ($accountNote !== null) {
                $notes[] = $accountNote;
            }
        }
        $plan = is_array($item['_registration'] ?? null) && $item['_registration'] !== [] && $plannedStart !== ''
            ? AttendanceRegistrationData::plan($item['_registration'], $plannedStart, $this->registrationCodebooks)
            : null;

        return $this->finishEmployment($supplierId, $employeeId, $employment, $item, $plannedStart, $today, $plan, $notes, $userId, $ip, $userAgent);
    }

    /**
     * Nový vztah osobě, která už v evidenci je (uložená vazba, rodné číslo,
     * osobní číslo) a v období vztah nemá — typicky brigádník, jehož dohoda
     * minulý měsíc skončila. Nová karta osoby nevznikne, takže se rodné číslo
     * ani osoba nezdvojí. Osobní karty (účet, pojišťovna) se netýká; údaje pro
     * registraci se doplní jen tam, kde v evidenci chybí.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $existing {@see AttendanceOpenAgreementMatcher} `existing_person`
     * @return array{employee_id:int,employment_id:int,message:?string}
     */
    private function createEmploymentForExisting(
        int $supplierId,
        array $item,
        array $existing,
        string $today,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $employeeId = (int) $existing['employee_id'];
        $plannedStart = is_string($item['planned_start_on'] ?? null) ? $item['planned_start_on'] : '';
        $relationType = is_string($item['relation_type'] ?? null) && $item['relation_type'] !== ''
            ? $item['relation_type']
            : (string) ($existing['relation_type'] ?? 'dpp');
        $item['relation_type'] = $relationType;
        $terms = self::employmentTerms($item, $plannedStart);
        // Předchozí dohoda skončená před nástupem nové hlavní vztah neblokuje.
        $hasPrimary = $this->employments->hasOverlappingPrimary(
            $supplierId,
            $employeeId,
            $plannedStart === '' ? null : $plannedStart,
        );
        $weekly = $terms['weekly_hours'] ?? '40.00';
        $created = $this->employments->create(
            $supplierId,
            $employeeId,
            $this->employmentValidator->create([
                'code' => '',
                'relation_type' => $relationType,
                'monthly_gross_minor' => $terms['monthly_gross'] === null ? null : $terms['monthly_gross'] * 100,
                'terms' => [
                    'office_id' => null,
                    'effective_from' => $plannedStart,
                    'planned_start_on' => $plannedStart,
                    'contract_signed_on' => null,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => $terms['fixed_end'],
                    'probable_hourly_earning_minor' => $terms['hourly_minor'],
                    'probable_earning_rationale' => $terms['rationale'],
                    'weekly_hours' => $weekly,
                    'workload_basis_points' => \MyInvoice\Service\Payroll\PayrollPersonCreateValidator::workloadBasisPoints($weekly),
                    'work_place' => null,
                    'regular_workplace' => null,
                    'jmhz_workplace_municipality_code' => null,
                    'jmhz_workplace_country_code' => null,
                    'jmhz_apz_contribution_status' => 'unverified',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'unverified',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => null,
                    // Druh činnosti podle pořadí souběžných vztahů doplní repozitář.
                    'activity_code' => null,
                    'jmhz_relationship_detail_code' => null,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => !$hasPrimary,
                    'change_reason' => 'Nová dohoda z importu docházky osobě, která už je v evidenci.',
                ],
            ]),
            $userId,
            $ip,
            $userAgent,
        );
        $employment = ['id' => (int) $created['id'], 'row_version' => (int) $created['row_version']];
        $plan = null;
        if (is_array($item['_registration'] ?? null) && $item['_registration'] !== [] && $plannedStart !== '') {
            $state = $this->imports->registrationState(
                $supplierId,
                [['employee_id' => $employeeId, 'employment_id' => $employment['id']]],
                $plannedStart,
                (int) substr($plannedStart, 0, 4),
            );
            $plan = AttendanceRegistrationData::gaps(
                AttendanceRegistrationData::plan($item['_registration'], $plannedStart, $this->registrationCodebooks),
                $state[$employment['id']],
            );
        }
        $notes = [
            "Osoba {$existing['employee_name']} už v evidenci je, založil se jí nový pracovní vztah (nová karta osoby nevznikla).",
            ...$terms['notes'],
        ];

        return $this->finishEmployment($supplierId, $employeeId, $employment, $item, $plannedStart, $today, $plan, $notes, $userId, $ip, $userAgent);
    }

    /**
     * Společné dokončení nového vztahu: aktivace k nástupu, osobní číslo
     * z podkladů, údaje pro registraci a uložená vazba pro další měsíce.
     *
     * @param array<string,mixed> $item
     * @param array{id:int,row_version:int} $employment
     * @param array<string,mixed>|null $plan {@see AttendanceRegistrationData::plan()}
     * @param list<string> $notes
     * @return array{employee_id:int,employment_id:int,message:?string}
     */
    private function finishEmployment(
        int $supplierId,
        int $employeeId,
        array $employment,
        array $item,
        string $plannedStart,
        string $today,
        ?array $plan,
        array $notes,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $message = $notes === [] ? null : implode(' ', $notes);
        if (($item['activate'] ?? false) === true) {
            if ($plannedStart <= $today) {
                $this->employments->transition(
                    $supplierId,
                    $employment['id'],
                    'active',
                    $employment['row_version'],
                    $plannedStart,
                    'Aktivace při importu docházky.',
                    $userId,
                    $ip,
                    $userAgent,
                );
            } else {
                $message = trim(($message ?? '') . ' Nástup je v budoucnu, vztah zůstal plánovaný.');
            }
        }
        // Vztah dostane osobní číslo z podkladů místo automatického ZAM-….
        $number = self::personalNumber($item['personal_number'] ?? null);
        if ($number !== null) {
            $renameError = $this->renameEmployment($supplierId, $employeeId, $employment['id'], $number, $userId);
            if ($renameError !== null) {
                $message = trim(($message ?? '') . ' ' . $renameError);
            }
        }
        if ($plan !== null && !AttendanceRegistrationData::isEmpty($plan)) {
            $reasons = [
                ...$plan['warnings'],
                ...$this->writeRegistration(
                    $supplierId,
                    $employeeId,
                    $employment['id'],
                    $plannedStart,
                    $plan,
                    (int) substr($plannedStart, 0, 4),
                    $userId,
                ),
            ];
            if ($reasons !== []) {
                $message = trim(($message ?? '') . ' ' . implode(' ', $reasons));
            }
        }
        $this->saveLinks(
            $supplierId,
            is_string($item['personal_number'] ?? null) && !($item['_number_ambiguous'] ?? false) ? $item['personal_number'] : '',
            AttendanceText::personKey((string) ($item['full_name'] ?? '')),
            $employeeId,
            $employment['id'],
            $userId,
        );

        return ['employee_id' => $employeeId, 'employment_id' => $employment['id'], 'message' => $message];
    }

    /**
     * Hodinová sazba z podkladů v haléřích. Nečitelná nebo nekladná osobu
     * neshodí: vztah vznikne bez ní a vrátí se, co doplnit.
     *
     * @return array{0:?int,1:?string}
     */
    private static function hourlyRateMinor(mixed $raw): array
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [null, null];
        }
        $text = is_string($raw) ? trim($raw) : '';
        $number = $text === '' ? null : AttendanceDecimal::parseNumber($text);
        $minor = $number === null || str_starts_with($number, '-') ? 0 : AttendanceDecimal::scaled($number, 2);
        if ($minor <= 0) {
            return [null, sprintf(
                'Hodinovou sazbu „%s“ z podkladů nejde přečíst jako kladnou částku, vztah je založený bez ní. '
                    . 'Doplňte ji na kartě vztahu jako pravděpodobný výdělek.',
                mb_substr($text, 0, 40),
            )];
        }

        return [$minor, null];
    }

    /**
     * Sjednaný konec dohody nebo doby určité. Nečitelné datum nebo konec před
     * nástupem osobu neshodí: vztah vznikne bez konce a vrátí se, co doplnit.
     *
     * @return array{0:?string,1:?string}
     */
    private static function fixedTermEnd(mixed $raw, string $plannedStart): array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [null, null];
        }
        $text = is_string($raw) ? trim($raw) : '';
        $end = AttendancePersonAggregator::isoDate($text);
        if ($end === null) {
            return [null, sprintf(
                'Konec dohody „%s“ z podkladů nejde přečíst, vztah je založený bez něj. Doplňte ho na kartě vztahu.',
                mb_substr($text, 0, 40),
            )];
        }
        if ($plannedStart !== '' && $end < $plannedStart) {
            return [null, 'Konec dohody ' . self::czechDate($end) . ' je před nástupem, vztah je založený bez něj. '
                . 'Opravte ho na kartě vztahu.'];
        }

        return [$end, null];
    }

    /**
     * Výplatní účet nové osoby z podkladů, stejnou cestou a validací jako
     * osobní karta. Účet zůstane neověřený; neplatný osobu neshodí.
     *
     * @return ?string co doplnit, když se účet nezapsal
     */
    private function savePayoutAccount(int $supplierId, int $employeeId, string $bankAccount, string $from, ?int $userId): ?string
    {
        $current = $this->personProfiles->get($supplierId, $employeeId);
        if ($current === null) {
            return 'Osobní karta nové osoby nebyla nalezena, číslo účtu doplňte na kartě.';
        }
        try {
            $this->personProfiles->save($supplierId, $employeeId, $this->profileValidator->validate([
                'row_version' => $current['row_version'],
                'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
                'payout_method' => 'bank',
                'partner_settlement_account_code' => $current['partner_settlement_account_code'],
                'cash_allocation_basis_points' => 0,
                'payout_effective_on' => $from !== '' ? $from : ($current['payout_effective_on'] ?? date('Y-m-d')),
                'secure_delivery_channel' => $current['secure_delivery_channel'],
                'identity_history' => [],
                'addresses' => [],
                'contacts' => [],
                'identifiers' => [],
                'accounts' => [[
                    'id' => null,
                    'label' => 'Výplatní účet z importu docházky',
                    'bank_account' => $bankAccount,
                    'allocation_basis_points' => 10000,
                    'effective_from' => $from !== '' ? $from : date('Y-m-d'),
                    'effective_to' => null,
                    'is_active' => true,
                ]],
            ]), $current['row_version'], $userId, null, null);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return self::bankAccountNote($e, true);
        }

        return $this->verifyPayoutAccount($supplierId, $employeeId, $userId);
    }

    /**
     * Účet z podkladů rovnou ověří uživatel, který import spustil (stejně jako převod
     * mezd ověřuje účty z předchozího systému). Bez přihlášeného uživatele ověřit nejde
     * (`verified_by` je povinné), účet pak zůstane k ověření na kartě osoby.
     */
    private function verifyPayoutAccount(int $supplierId, int $employeeId, ?int $userId): ?string
    {
        $pending = 'Číslo účtu je uložené, ověřte ho na kartě osoby.';
        if ($userId === null) {
            return $pending;
        }
        $profile = $this->personProfiles->get($supplierId, $employeeId);
        foreach ($profile['accounts'] ?? [] as $account) {
            if ($account['verification_source'] !== null || $account['is_active'] !== true) {
                continue;
            }
            try {
                $this->accountVerification->verify(
                    $supplierId,
                    $employeeId,
                    (int) $account['id'],
                    'user_verified',
                    date('Y-m-d'),
                    $userId,
                    (int) $account['row_version'],
                );
            } catch (\DomainException|\InvalidArgumentException|\RuntimeException) {
                return $pending;
            }
        }

        return null;
    }

    private static function bankAccountNote(\Throwable $e, bool $created): string
    {
        return 'Číslo účtu z podkladů neplatí (' . rtrim($e->getMessage(), '.') . '), '
            . ($created ? 'osoba je založená bez něj. Doplňte ho na kartě osoby.' : 'nová osoba se založí bez něj.');
    }

    /**
     * Osobní číslo z podkladů se převezme jako kód pracovního vztahu — ten
     * aplikace zobrazuje jako osobní číslo. Přepisuje se jen kód, který vznikl
     * automaticky (`ZAM-7`, převod ze staré karty `1`); ručně zadané číslo
     * zůstává a rozdíl se jen ohlásí.
     *
     * @param list<array{person:array<string,mixed>,employment:array<string,mixed>}> $assigned
     * @return array{adopted:int,conflicts:list<array{key:string,display_name:string,reason:string}>}
     */
    private function adoptPersonalNumbers(int $supplierId, array $assigned, ?int $userId): array
    {
        $adopted = 0;
        $conflicts = [];
        foreach ($assigned as $item) {
            $number = ($item['person']['_number_ambiguous'] ?? false)
                ? null
                : self::personalNumber($item['person']['personal_number'] ?? null);
            $code = (string) $item['employment']['code'];
            if ($number === null || $code === $number) {
                continue;
            }
            $conflict = static fn (string $reason): array => [
                'key' => (string) $item['person']['key'],
                'display_name' => (string) $item['person']['display_name'],
                'reason' => $reason,
            ];
            if (!self::isGeneratedCode($code)) {
                $conflicts[] = $conflict(
                    "Pracovní vztah má osobní číslo „{$code}“, podklady uvádějí „{$number}“. Ponechalo se „{$code}“; "
                    . 'platí-li číslo z podkladů, změňte ho na kartě vztahu.',
                );
                continue;
            }
            $error = $this->renameEmployment(
                $supplierId,
                (int) $item['employment']['employee_id'],
                (int) $item['employment']['employment_id'],
                $number,
                $userId,
            );
            if ($error === null) {
                ++$adopted;
            } else {
                $conflicts[] = $conflict($error);
            }
        }

        return ['adopted' => $adopted, 'conflicts' => $conflicts];
    }

    /**
     * Druh mzdové složky podle kódu: existující složka firmy má přednost před
     * definicí z profilu.
     *
     * @param list<array<string,mixed>> $checks
     * @param list<array<string,mixed>> $definitions
     * @return array<string,string>
     */
    private static function componentKinds(array $checks, array $definitions): array
    {
        $kinds = [];
        foreach ($definitions as $definition) {
            $kinds[(string) $definition['code']] = (string) $definition['kind'];
        }
        foreach ($checks as $check) {
            if (is_string($check['kind'] ?? null)) {
                $kinds[(string) $check['component_code']] = $check['kind'];
            }
        }

        return $kinds;
    }

    /**
     * Rozdíly mezi měsíční mzdou z podkladů a sjednanými podmínkami vztahů.
     *
     * @param list<array{person:array<string,mixed>,employment_id:int}> $pairs
     * @param array<string,string> $kinds
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private function wageChanges(int $supplierId, string $periodStart, array $pairs, array $kinds): array
    {
        $warnings = [];
        $wanted = [];
        foreach ($pairs as $pair) {
            $raw = $pair['person']['monthly_wage'] ?? null;
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $imported = self::monthlyWageMinor($raw);
            if ($imported === null) {
                $warnings[] = "Měsíční mzdu „{$raw}“ osoby {$pair['person']['display_name']} nejde přečíst jako částku; "
                    . 'do podmínek vztahu se nezapíše.';
                continue;
            }
            if ($imported > 0) {
                $wanted[] = $pair + ['imported_minor' => $imported];
            }
        }
        if ($wanted === []) {
            return [[], $warnings];
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->format('Y-m-t');
        $terms = $this->imports->termsAt(
            $supplierId,
            array_map(static fn (array $pair): int => $pair['employment_id'], $wanted),
            $periodStart,
            $periodEnd,
        );
        $changes = [];
        foreach ($wanted as $pair) {
            $change = $this->planWage(
                $supplierId,
                $periodStart,
                $pair['person'],
                $pair['employment_id'],
                $pair['imported_minor'],
                $terms[$pair['employment_id']] ?? null,
                $kinds,
            );
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return [$changes, $warnings];
    }

    /**
     * Jak se měsíční mzda do podmínek zapíše, případně proč ne.
     *
     * `correct` = podmínky mzdu nemají, doplňuje se chybějící údaj opravou
     * platné verze (bez nové verze a bez sady povinností ke změně).
     * `add` = podmínky mají jinou mzdu, vzniká nová verze od 1. dne období.
     * Doplnění, které by přepsalo zúčtovaný měsíc, se zapíše jako nová verze
     * od začátku období — přesně to radí i hláška kontroly zúčtování.
     *
     * @param array<string,mixed> $person
     * @param array{terms_id:int,effective_from:string,effective_to:?string,monthly_gross_minor:?int,is_latest:bool,employment_status:string,hourly_in_period:bool}|null $terms
     * @param array<string,string> $kinds
     * @return array<string,mixed>|null null = mzda se shoduje, není co měnit
     */
    private function planWage(
        int $supplierId,
        string $periodStart,
        array $person,
        int $employmentId,
        int $imported,
        ?array $terms,
        array $kinds,
    ): ?array {
        $current = $terms['monthly_gross_minor'] ?? null;
        if ($current === $imported) {
            return null;
        }
        $mode = $current === null ? 'correct' : 'add';
        $entry = static fn (string $mode, ?string $reason, array $refresh = []): array => [
            'key' => (string) $person['key'],
            'display_name' => (string) $person['display_name'],
            'employment_id' => $employmentId,
            'current_minor' => $current,
            'imported_minor' => $imported,
            'mode' => $mode,
            'reason' => $reason,
            'runs_needing_refresh' => $refresh,
        ];

        foreach (array_keys($person['_components'] ?? []) as $code) {
            if (in_array($kinds[(string) $code] ?? null, self::WAGE_BASE_KINDS, true)) {
                return $entry($mode, "Osoba dostává v podkladech mzdu podle docházky (složka {$code}). "
                    . 'Měsíční mzda se do podmínek nezapíše, aby se základ nevyplatil dvakrát; '
                    . 'platí-li opravdu, zapište ji na kartě vztahu.');
            }
        }
        if ($terms === null) {
            return $entry($mode, 'Pracovní vztah nemá v období žádnou verzi sjednaných podmínek. Doplňte je na kartě vztahu.');
        }
        if (in_array($terms['employment_status'], ['ended', 'archived', 'no_show'], true)) {
            return $entry($mode, 'U ukončeného, archivovaného nebo nenastoupeného vztahu import podmínky nemění.');
        }
        if ($terms['hourly_in_period']) {
            return $entry($mode, 'Vztah je v období odměňovaný hodinově. Měsíční mzda se do podmínek nezapíše; sazbu upravte na kartě vztahu.');
        }
        if (!$terms['is_latest']) {
            return $entry($mode, 'Po začátku období už platí novější verze podmínek. Mzdu upravte na kartě vztahu.');
        }

        $from = $terms['effective_from'];
        $to = $terms['effective_to'];
        if ($mode === 'correct') {
            try {
                $this->employments->assertTermsOpenFrom($supplierId, $employmentId, $from, $to);
            } catch (PayrollTermsSettledException $e) {
                if ($from >= $periodStart) {
                    return $entry($mode, $e->getMessage());
                }
                $mode = 'add';
            }
        }
        if ($mode === 'add') {
            if ($from >= $periodStart) {
                return $entry($mode, "Platná verze podmínek začíná až {$from}, novou verzi od začátku období proto "
                    . 'založit nejde. Mzdu upravte na kartě vztahu.');
            }
            $from = $periodStart;
            $to = null;
            try {
                $this->employments->assertTermsOpenFrom($supplierId, $employmentId, $from, $to);
            } catch (PayrollTermsSettledException $e) {
                return $entry($mode, $e->getMessage());
            }
        }

        $refresh = [];
        foreach ($this->imports->runsCoveringEmployment($supplierId, $employmentId, $from, $to) as $run) {
            if (in_array($run['status'], self::WAGE_BLOCKING_RUN_STATUSES, true)) {
                return $entry($mode, "Mzdový běh za {$run['period']} je schválený, zaúčtovaný nebo vyplacený. "
                    . 'Import do jeho podmínek nezapisuje; vraťte běh k úpravám, nebo mzdu zapište na kartě vztahu '
                    . 'jako novou verzi od dalšího měsíce.');
            }
            $refresh[] = $run;
        }

        return $entry($mode, null, $refresh);
    }

    /**
     * Zapíše převzaté měsíční mzdy do podmínek, vztah po vztahu. Chyba
     * jednoho vztahu nezastaví ostatní a vrátí se jako konflikt (stejně jako
     * převzetí osobních čísel).
     *
     * @param list<array{person:array<string,mixed>,employment:array<string,mixed>}> $assigned
     * @param array<string,string> $kinds
     * @return array{adopted:int,conflicts:list<array{key:string,display_name:string,reason:string}>,runs_needing_refresh:list<array{run_id:int,period:string,status:string}>}
     */
    private function adoptMonthlyWages(
        int $supplierId,
        string $periodStart,
        array $assigned,
        array $kinds,
        ?int $userId,
    ): array {
        [$changes] = $this->wageChanges(
            $supplierId,
            $periodStart,
            array_map(
                static fn (array $item): array => ['person' => $item['person'], 'employment_id' => (int) $item['employment']['employment_id']],
                $assigned,
            ),
            $kinds,
        );
        $adopted = 0;
        $conflicts = [];
        $refresh = [];
        foreach ($changes as $change) {
            $reason = $change['reason'];
            if ($reason === null) {
                try {
                    $this->writeWage($supplierId, $periodStart, $change, $userId);
                    ++$adopted;
                    foreach ($change['runs_needing_refresh'] as $run) {
                        $refresh[$run['run_id']] = $run;
                    }
                    continue;
                } catch (PayrollEmploymentConflictException) {
                    $reason = 'Pracovní vztah mezitím změnil někdo jiný. Načtěte náhled znovu.';
                } catch (PayrollEmploymentNotFoundException|\DomainException|\InvalidArgumentException $e) {
                    $reason = $e->getMessage();
                }
            }
            $conflicts[] = [
                'key' => (string) $change['key'],
                'display_name' => (string) $change['display_name'],
                'reason' => (string) $reason,
            ];
        }

        return ['adopted' => $adopted, 'conflicts' => $conflicts, 'runs_needing_refresh' => array_values($refresh)];
    }

    /** @param array<string,mixed> $change */
    private function writeWage(int $supplierId, string $periodStart, array $change, ?int $userId): void
    {
        $employmentId = (int) $change['employment_id'];
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('Pracovní vztah nemá verzi sjednaných podmínek.');
        $version = $this->imports->employmentRowVersion($supplierId, $employmentId)
            ?? throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        $body = RegistrationImportWriter::termsBody(
            $current,
            'Měsíční mzda z importu docházky za ' . substr($periodStart, 0, 7) . '.',
        );
        $body['effective_from'] = $change['mode'] === 'add' ? $periodStart : (string) $current['effective_from'];
        $terms = $this->employmentValidator->terms(
            $body,
            $this->employments->currentCzIscoCode($supplierId, $employmentId),
            $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
            $this->employments->currentRelationType($supplierId, $employmentId),
        );
        $monthlyGross = (int) $change['imported_minor'];
        if ($change['mode'] === 'add') {
            $this->employments->addTerms($supplierId, $employmentId, $terms, $version, $userId, null, null, true, $monthlyGross);
        } else {
            $this->employments->correctTerms($supplierId, $employmentId, $terms, $version, $userId, null, null, true, $monthlyGross);
        }
    }

    /**
     * Pravděpodobný hodinový výdělek (§ 355 ZP) z podkladů: ze sloupce
     * hodinové sazby, jinak z odměny za odpracované hodiny (částka hodinové
     * mzdy / odpracované hodiny). Do sjednaných podmínek se sazba jako
     * hodinové odměňování NEZAPISUJE: vztah s hodinovými podmínkami počítá
     * mzdu sám z doložených hodin a importovanou hodinovou mzdu by odmítla
     * kontrola souběhu zdrojů ({@see \MyInvoice\Repository\Payroll\PayrollHourlyWageCollisionGuard}).
     *
     * Osobě k založení se návrh ukáže u zakládání (`probable_earning`).
     * Existující vztah ho dostane jako změnu k potvrzení, jen když pravděpodobný
     * výdělek ani schválený průměr pro čtvrtletí nemá.
     *
     * @param list<array<string,mixed>> $persons
     * @param array<string,string> $kinds
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function probableEarnings(int $supplierId, string $periodStart, array $persons, array $kinds): array
    {
        $pairs = [];
        foreach ($persons as $index => $person) {
            $proposal = self::probableProposal($person, $kinds);
            $persons[$index]['probable_earning'] = $proposal;
            if ($proposal !== null
                && in_array($person['match']['status'], ['linked', 'matched'], true)
                && is_int($person['match']['employment_id'])) {
                $pairs[$index] = $person['match']['employment_id'];
            }
        }
        if ($pairs === []) {
            return [$persons, []];
        }
        $month = (int) substr($periodStart, 5, 2);
        $covered = $this->imports->employmentsWithEarningBasis(
            $supplierId,
            array_values($pairs),
            (int) substr($periodStart, 0, 4),
            intdiv($month - 1, 3) + 1,
        );
        $changes = [];
        foreach ($pairs as $index => $employmentId) {
            if (isset($covered[$employmentId])) {
                continue;
            }
            $proposal = $persons[$index]['probable_earning'];
            $changes[] = [
                'key' => (string) $persons[$index]['key'],
                'display_name' => (string) $persons[$index]['display_name'],
                'employment_id' => $employmentId,
            ] + $proposal;
        }

        return [$persons, $changes];
    }

    /** Složka mzdy dopočtené ze sazby a hodin; tatáž jako hodinová mzda šablony Vzor MyÚčto. */
    private const RATE_WAGE_COMPONENT = ['code' => 'MZDA_HODINOVA_DOCH', 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage'];

    /**
     * Mzda ze sjednané hodinové sazby a odpracovaných hodin podkladů.
     *
     * Podklady agentury často nesou jen hodiny a sazbu, ne částku. Import ze
     * sazby dřív dělal jen pravděpodobný výdělek a mzda nevznikla vůbec, bez
     * upozornění (souhrn „Složky celkem 0,00 Kč"). Odměna z dohody nebo mzda
     * sjednaná hodinovou sazbou je součin sazby a odpracovaných hodin
     * (§ 138 ZP u dohod, § 109 ZP u mzdy), zaokrouhlený na haléř (polovina
     * nahoru, celočíselně). Dopočte se jen tam, kde podklady žádnou částku
     * mzdy nenesou; nesou-li ji, sazba zůstává jen pravděpodobným výdělkem.
     *
     * Vztah s hodinovým odměňováním na kartě mzdu z importu nedostane: tu
     * počítá mzdový běh ze schválené docházky a hotová částka by se s ním
     * srazila. Z importovaného souhrnu hodin ji ale běh nespočítá, proto
     * osoba dostane vysvětlení, jak se vrátit k mzdě z importu (návrat do
     * neurčeného režimu karta záměrně nedovolí, cesta vede přes měsíční).
     *
     * Každá osoba se sazbou nese `rate_wage` se stavem, aby náhled i výsledek
     * importu řekly, jaká mzda vznikne, nebo proč nevznikne.
     *
     * @param list<array<string,mixed>> $persons
     * @return list<array<string,mixed>>
     */
    private function withRateWages(int $supplierId, string $periodStart, array $persons): array
    {
        $employmentIds = [];
        foreach ($persons as $person) {
            if (in_array($person['match']['status'] ?? null, ['linked', 'matched'], true) && is_int($person['match']['employment_id'] ?? null)) {
                $employmentIds[] = $person['match']['employment_id'];
            }
        }
        $hourly = $this->hourlyRemunerated($supplierId, $periodStart, $employmentIds);
        foreach ($persons as &$person) {
            $rawRate = $person['hourly_rate'] ?? null;
            if (!is_string($rawRate) || trim($rawRate) === '') {
                continue;
            }
            [$rate] = self::hourlyRateMinor($rawRate);
            if ($rate === null) {
                continue;
            }
            if (array_filter($person['_components'], static fn (array $component): bool => (int) $component['amount_minor'] !== 0) !== []) {
                $person['rate_wage'] = ['status' => 'amount_in_source'];
                continue;
            }
            $millihours = $person['_metrics']['worked_hours']['millihours'] ?? null;
            if (!is_int($millihours) || $millihours <= 0) {
                $person['rate_wage'] = ['status' => 'no_hours'];
                $person['warnings'][] = 'Podklady uvádějí hodinovou sazbu, ale ne odpracované hodiny, takže mzda ze sazby nevznikne. '
                    . 'Doplňte hodiny do podkladů, nebo mzdu zadejte v Mzdy → Rychlý měsíční vstup.';
                continue;
            }
            $employmentId = $person['match']['employment_id'] ?? null;
            if (in_array($person['match']['status'] ?? null, ['linked', 'matched'], true) && isset($hourly[$employmentId])) {
                $person['rate_wage'] = ['status' => 'hourly_terms'];
                $person['warnings'][] = 'Vztah má na kartě hodinové odměňování, mzdu z importovaného souhrnu hodin proto import nevytvoří '
                    . 'a mzdový běh ji z něj nespočítá. Na kartě vztahu přepněte způsob odměňování na měsíční '
                    . '(u dohody stačí 0 Kč) a načtěte náhled znovu; import pak mzdu dopočte ze sazby a hodin.';
                continue;
            }
            $amount = intdiv(2 * $rate * $millihours + 1000, 2000);
            $code = self::RATE_WAGE_COMPONENT['code'];
            $source = (string) ($person['_metrics']['worked_hours']['source'] ?? '');
            $person['_components'][$code] = ['amount_minor' => $amount, 'source' => $source];
            $person['components'][] = [
                'component_code' => $code,
                'amount' => AttendanceDecimal::formatMinor($amount),
                'amount_minor' => $amount,
                'source' => $source,
                'conflicts' => [],
            ];
            $person['rate_wage'] = [
                'status' => 'computed',
                'component_code' => $code,
                'hours' => AttendanceDecimal::formatMillihours($millihours),
                'hourly_rate_minor' => $rate,
                'amount_minor' => $amount,
            ];
        }
        unset($person);

        return $persons;
    }

    /**
     * Vztahy s hodinovým odměňováním účinným v měsíci (jedním dotazem).
     *
     * @param list<int> $employmentIds
     * @return array<int,true>
     */
    private function hourlyRemunerated(int $supplierId, string $periodStart, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->format('Y-m-t');
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT DISTINCT employment_id
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id IN ({$in})
                AND remuneration_type = 'hourly'
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)"
        );
        $statement->execute([$supplierId, ...$employmentIds, $periodEnd, $periodStart]);

        return array_fill_keys(array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,string> $kinds
     * @return array{source:string,hourly_minor:int,amount_minor:?int,hours:?string}|null
     */
    private static function probableProposal(array $person, array $kinds): ?array
    {
        $rawRate = $person['hourly_rate'] ?? null;
        if (is_string($rawRate) && trim($rawRate) !== '') {
            [$rate] = self::hourlyRateMinor($rawRate);

            return $rate === null ? null : ['source' => 'rate', 'hourly_minor' => $rate, 'amount_minor' => null, 'hours' => null];
        }
        $millihours = $person['_metrics']['worked_hours']['millihours'] ?? null;
        $amount = 0;
        foreach ($person['_components'] ?? [] as $code => $component) {
            if (($kinds[(string) $code] ?? null) === 'hourly_wage') {
                $amount += (int) $component['amount_minor'];
            }
        }
        if (!is_int($millihours) || $millihours <= 0 || $amount <= 0) {
            return null;
        }

        return [
            'source' => 'amount',
            'hourly_minor' => self::hourlyFromAmount($amount, $millihours),
            'amount_minor' => $amount,
            'hours' => AttendanceDecimal::formatMillihours($millihours),
        ];
    }

    /**
     * Hodinový výdělek v haléřích = částka / hodiny, zaokrouhlený na haléř
     * (polovina nahoru) celočíselně: hodiny jsou v tisícinách, žádný float.
     */
    public static function hourlyFromAmount(int $amountMinor, int $millihours): int
    {
        return intdiv(2 * $amountMinor * 1000 + $millihours, 2 * $millihours);
    }

    /** @param array{source:string,hourly_minor:int,amount_minor:?int,hours:?string} $proposal */
    private static function probableRationale(array $proposal, string $periodStart): string
    {
        return $proposal['source'] === 'rate'
            ? sprintf(
                'Sjednaná hodinová sazba %s Kč z podkladů importu docházky.',
                number_format($proposal['hourly_minor'] / 100, 2, ',', ' '),
            )
            : sprintf(
                'Odměna za odpracované hodiny podle importu docházky za %s: %s Kč za %s h.',
                PayrollPeriodLabel::monthYear($periodStart),
                number_format((int) $proposal['amount_minor'] / 100, 2, ',', ' '),
                str_replace('.', ',', (string) $proposal['hours']),
            );
    }

    /**
     * Zapíše navržené pravděpodobné výdělky do podmínek vztahů. Oprava platné
     * verze; zasahuje-li do zúčtovaného měsíce, nová verze od začátku období.
     * Druh odměňování se nemění.
     *
     * @param list<array<string,mixed>> $changes
     * @return array{adopted:int,conflicts:list<array{key:string,display_name:string,reason:string}>}
     */
    private function adoptProbableEarnings(int $supplierId, string $periodStart, array $changes, ?int $userId): array
    {
        $adopted = 0;
        $conflicts = [];
        foreach ($changes as $change) {
            $employmentId = (int) $change['employment_id'];
            try {
                $current = $this->employments->currentTerms($supplierId, $employmentId)
                    ?? throw new \DomainException('Pracovní vztah nemá verzi sjednaných podmínek.');
                $body = RegistrationImportWriter::termsBody(
                    $current,
                    'Pravděpodobný výdělek z importu docházky za ' . substr($periodStart, 0, 7) . '.',
                );
                $body['probable_hourly_earning_minor'] = (int) $change['hourly_minor'];
                $body['probable_earning_rationale'] = self::probableRationale($change, $periodStart);
                $body['effective_from'] = (string) $current['effective_from'];
                $terms = fn (array $body): array => $this->employmentValidator->terms(
                    $body,
                    $this->employments->currentCzIscoCode($supplierId, $employmentId),
                    $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                    $this->employments->currentRelationType($supplierId, $employmentId),
                    $current,
                );
                $version = $this->imports->employmentRowVersion($supplierId, $employmentId)
                    ?? throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
                try {
                    $this->employments->correctTerms($supplierId, $employmentId, $terms($body), $version, $userId, null, null);
                } catch (PayrollTermsSettledException $e) {
                    if ((string) $current['effective_from'] >= $periodStart) {
                        throw $e;
                    }
                    $body['effective_from'] = $periodStart;
                    $this->employments->addTerms($supplierId, $employmentId, $terms($body), $version, $userId, null, null);
                }
                ++$adopted;
            } catch (PayrollEmploymentConflictException) {
                $conflicts[] = self::conflict($change, 'Pracovní vztah mezitím změnil někdo jiný. Načtěte náhled znovu.');
            } catch (PayrollEmploymentNotFoundException|\DomainException|\InvalidArgumentException $e) {
                $conflicts[] = self::conflict($change, $e->getMessage());
            }
        }

        return ['adopted' => $adopted, 'conflicts' => $conflicts];
    }

    /**
     * @param array<string,mixed> $change
     * @return array{key:string,display_name:string,reason:string}
     */
    private static function conflict(array $change, string $reason): array
    {
        return ['key' => (string) $change['key'], 'display_name' => (string) $change['display_name'], 'reason' => $reason];
    }

    /** Měsíční mzda z podkladů v haléřích; null, když text částkou není. */
    private static function monthlyWageMinor(string $value): ?int
    {
        $number = AttendanceDecimal::parseNumber($value);
        if ($number === null || str_starts_with($number, '-')) {
            return null;
        }

        return AttendanceDecimal::scaled($number, 2);
    }

    /** @return string|null důvod, proč se kód nezměnil */
    private function renameEmployment(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $code,
        ?int $userId,
    ): ?string {
        $version = null;
        foreach ($this->employments->listForEmployee($supplierId, $employeeId) as $row) {
            if ((int) $row['id'] === $employmentId) {
                $version = (int) $row['row_version'];
            } elseif ((string) ($row['code'] ?? '') === $code) {
                return "Osobní číslo „{$code}“ už má jiný pracovní vztah téže osoby.";
            }
        }
        if ($version === null) {
            return 'Pracovní vztah se nepodařilo načíst.';
        }
        try {
            $this->employments->rename($supplierId, $employmentId, $code, $version, $userId, null, 'attendance-import');
        } catch (PayrollEmploymentConflictException) {
            return 'Pracovní vztah mezitím změnil někdo jiný. Načtěte náhled znovu.';
        }

        return null;
    }

    /** Kód, který aplikace založila sama: `ZAM-7`, nebo holé číslo z převodu staré karty. */
    private static function isGeneratedCode(string $code): bool
    {
        return preg_match('/^(ZAM-)?\d+$/D', $code) === 1;
    }

    private static function personalNumber(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function saveLinks(
        int $supplierId,
        string $personalNumber,
        string $nameKey,
        int $employeeId,
        int $employmentId,
        ?int $userId,
    ): bool {
        $changed = false;
        if (trim($personalNumber) !== '') {
            $changed = $this->links->save(
                $supplierId,
                AttendanceMeaning::SOURCE_SYSTEM,
                PayrollImportLinkRepository::KIND_PERSONAL_NUMBER,
                $personalNumber,
                $employeeId,
                $employmentId,
                $userId,
            );
        }
        if ($nameKey !== '') {
            $changed = $this->links->save(
                $supplierId,
                AttendanceMeaning::SOURCE_SYSTEM,
                PayrollImportLinkRepository::KIND_NAME,
                $nameKey,
                $employeeId,
                $employmentId,
                $userId,
            ) || $changed;
        }

        return $changed;
    }

    private function period(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('Období musí být měsíc ve tvaru RRRR-MM.');
        }

        return $value . '-01';
    }

    private static function profileName(mixed $name): string
    {
        $name = is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : '';
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new \InvalidArgumentException('Zadejte název profilu (nejvýše 120 znaků).');
        }

        return $name;
    }

    /** @return array<string,int> klíč osoby → employment_id */
    private static function linkMap(mixed $links): array
    {
        if ($links === null) {
            return [];
        }
        if (!is_array($links) || !array_is_list($links)) {
            throw new \InvalidArgumentException('Přiřazení osob musí být seznam.');
        }
        $result = [];
        foreach ($links as $link) {
            $key = is_array($link) ? ($link['person_key'] ?? null) : null;
            $employmentId = is_array($link) ? ($link['employment_id'] ?? null) : null;
            if (!is_string($key) || $key === '' || !is_int($employmentId) || $employmentId <= 0) {
                throw new \InvalidArgumentException('Přiřazení osoby k pracovnímu vztahu nemá platný tvar.');
            }
            $result[$key] = $employmentId;
        }

        return $result;
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return list<string>
     */
    private static function sortedHashes(array $files): array
    {
        $hashes = array_map(static fn (array $file): string => $file['sha256'], $files);
        sort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * @param array{name:string,sha256:string,extension:string} $file
     * @return array<string,mixed>
     */
    private static function fileRow(array $file, int $sheets, ?string $error): array
    {
        return [
            'name' => $file['name'],
            'sha256' => $file['sha256'],
            'format' => $file['extension'],
            'sheets' => $sheets,
            'error' => $error,
        ];
    }

    /**
     * Dodatečné schválení dávky, která už proběhla dřív.
     *
     * Převod mezd z PAMICA měsíc, který v MyÚčtu jednou prošel, znovu nezakládá. Když
     * uživatel dodatečně řekne, že se převzaté podklady mají schválit, není co spustit -
     * `apply()` se k takovému měsíci vůbec nedostane. Tahle cesta proto nad hotovou dávkou
     * jen přepíše souhrny docházky (idempotentně), schválí čisté měsíce a vrátí dávku
     * mzdových vstupů, aby volající mohl schválit i je.
     *
     * @return array{time:array<string,mixed>,input_import_id:int}
     */
    public function approveTakenOverBatch(int $supplierId, int $importId, ?int $userId): array
    {
        $batch = $this->imports->batch($supplierId, $importId);

        return [
            'time' => $this->timeApprovals->applyBatch($supplierId, $importId, true, $userId),
            'input_import_id' => (int) ($batch['input_import_id'] ?? 0),
        ];
    }

    /**
     * Znovu schválí převzaté měsíce dávky, jejichž pracovní souhrn zastaral
     * ({@see \MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService::refreshStale()}).
     *
     * @return array{refreshed:int,exceptions:list<array{employment_id:int,code:string,message:string}>}
     */
    public function refreshTakenOverBatch(int $supplierId, int $importId, ?int $userId): array
    {
        return $this->timeApprovals->refreshStale($supplierId, $importId, $userId);
    }

    /**
     * @param array<string,mixed> $batch
     * @param list<array<string,mixed>> $skipped
     * @param array{adopted:int,conflicts:list<array<string,mixed>>,runs_needing_refresh:list<array<string,mixed>>} $wages
     * @return array<string,mixed>
     */
    private static function replay(array $batch, array $skipped, array $wages): array
    {
        return [
            'replayed' => true,
            'batch' => self::publicBatch($batch),
            'inputs' => [
                'import_id' => $batch['input_import_id'],
                'created' => 0,
                'updated' => 0,
                'overridden' => 0,
                'duplicates' => 0,
                'errors' => [],
            ],
            'links_saved' => 0,
            'components_created' => [],
            'personal_numbers_adopted' => 0,
            'personal_number_conflicts' => [],
            'monthly_wages_adopted' => $wages['adopted'],
            'wage_conflicts' => $wages['conflicts'],
            'runs_needing_refresh' => $wages['runs_needing_refresh'],
            'skipped_persons' => $skipped,
            'deductions' => null,
        ];
    }

    /**
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    private static function publicBatch(array $batch): array
    {
        unset($batch['input_import_id']);

        return $batch;
    }

    /** @return array<string,mixed> */
    private static function failed(string $key, string $message): array
    {
        return [
            'person_key' => $key,
            'status' => 'failed',
            'employee_id' => null,
            'employment_id' => null,
            'message' => $message,
        ];
    }

    /**
     * Interní pole osob (`_…`) nesmí do odpovědi — nesou mimo jiné nemaskované rodné číslo.
     *
     * @param array<string,mixed> $computed
     * @return array<string,mixed>
     */
    private static function public(array $computed): array
    {
        $computed['persons'] = array_map(
            static fn (array $person): array => array_filter(
                $person,
                static fn (string $key): bool => !str_starts_with($key, '_'),
                ARRAY_FILTER_USE_KEY,
            ) + (($person['_number_ambiguous'] ?? false) ? ['number_ambiguous' => true] : []),
            $computed['persons'],
        );

        return $computed;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_attendance_import');
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_attendance_import');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_attendance_import');
                $pdo->exec('RELEASE SAVEPOINT payroll_attendance_import');
            }
            throw $e;
        }
    }
}
