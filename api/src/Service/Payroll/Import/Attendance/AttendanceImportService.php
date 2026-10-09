<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
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
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\Payroll\Absence\ImportAbsenceCompensationRates;
use MyInvoice\Service\Payroll\Absence\PayrollImportAbsenceCompensationMaterializer;
use MyInvoice\Service\Payroll\Component\PayrollInputImportService;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
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

        return self::public($this->compute($supplierId, $periodStart, $files, $read, $validated, $profileComponents, $profileMeta))
            + [
                'upgrade_available' => self::sampleUpgrade($modifiedSamples),
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
        $computed = $this->compute($supplierId, $periodStart, $files, $read, $validated, $profileComponents, $profileMeta);
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
                    'reason' => $match['status'] === 'ambiguous'
                        ? 'Osoba má víc možných pracovních vztahů. Vyberte vztah ručně.'
                        : 'Osoba nemá v období pracovní vztah. Založte ji nebo vyberte vztah ručně.',
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
            + ($createDeductions ? ['create_deductions' => true] : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);

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

        $existing = $this->imports->findBatchByHash($supplierId, $periodStart, $fingerprint);
        if ($existing !== null) {
            return self::replay($existing, $skipped, $adoptWages()) + $timeSteps((int) $existing['id']);
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
                        (string) ($item['person']['personal_number'] ?? ''),
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

            return self::replay($winner, $skipped, $adoptWages()) + $timeSteps((int) $winner['id']);
        }
        $wages = $adoptWages();
        $time = $timeSteps((int) $result['import_id']);

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
     * Osobní údaje z podkladů podle klíče osoby: rodné číslo, datum narození
     * a kód zdravotní pojišťovny. Klient zná rodné číslo jen maskované a
     * ostatní údaje vůbec, takže je při zakládání osob doplní server ze
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
            foreach (['birth_number' => '_birth_number', 'birth_date' => '_birth_date', 'health_insurer_code' => '_health_insurer_code'] as $field => $internal) {
                $value = $person[$internal] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $data[(string) $person['key']][$field] = trim($value);
                }
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
                foreach ($personData[$key] ?? [] as $field => $value) {
                    if (in_array($item[$field] ?? null, [null, ''], true)) {
                        $item[$field] = $value;
                    }
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
     * Znovu založí vzorový profil GIRITON, třeba po omylem smazaném. Vzor vznikne
     * v aktuální verzi a dál se sám aktualizuje; existující vzor se vrátí beze
     * změny. Obsadil-li jeho název vlastní profil, dostane vzor příponu.
     *
     * @return array{profile:array<string,mixed>,restored:bool}
     */
    public function restoreSampleProfile(int $supplierId, ?int $userId): array
    {
        $existing = $this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM);
        foreach ($existing as $profile) {
            if (!empty($profile['is_sample'])) {
                return ['profile' => $profile, 'restored' => false];
            }
        }
        $taken = array_map(static fn (array $profile): string => mb_strtolower((string) $profile['name'], 'UTF-8'), $existing);
        $name = AttendanceSampleProfile::NAME;
        for ($suffix = 2; in_array(mb_strtolower($name, 'UTF-8'), $taken, true); ++$suffix) {
            $name = AttendanceSampleProfile::NAME . " ({$suffix})";
        }
        $profile = $this->profiles->save(
            $supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            null,
            $name,
            AttendanceSampleProfile::rules(),
            $userId,
            AttendanceSampleProfile::components(),
            true,
            AttendanceSampleProfile::VERSION,
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
     * Založí vzor firmě, která ho ještě nedostala, a nedotčený starší vzor
     * převede na aktuální verzi.
     *
     * @return list<array{id:int,name:string,sample_version:?int}> upravené vzory starší verze
     */
    private function ensureSampleProfile(int $supplierId): array
    {
        $this->profiles->seedSampleOnce(
            $supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            AttendanceSampleProfile::NAME,
            AttendanceSampleProfile::rules(),
            AttendanceSampleProfile::components(),
            AttendanceSampleProfile::VERSION,
        );

        return $this->profiles->upgradeSample(
            $supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            AttendanceSampleProfile::VERSION,
            AttendanceSampleProfile::rules(),
            AttendanceSampleProfile::components(),
        );
    }

    /**
     * Nabídka nové verze vzoru pro upravený profil. Nese i pravidla a složky
     * vzoru: uloží-li je účetní do profilu (běžné uložení profilu), vzor se
     * při dalším načtení označí jako aktuální.
     *
     * @param list<array{id:int,name:string,sample_version:?int}> $modified
     * @return array<string,mixed>|null
     */
    private static function sampleUpgrade(array $modified): ?array
    {
        if ($modified === []) {
            return null;
        }

        return [
            'profile_id' => $modified[0]['id'],
            'name' => $modified[0]['name'],
            'version' => $modified[0]['sample_version'],
            'latest_version' => AttendanceSampleProfile::VERSION,
            'rules' => AttendanceSampleProfile::rules(),
            'components' => AttendanceSampleProfile::components(),
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
        $persons = $matched['persons'];
        $usedCodes = [];
        foreach ($persons as &$person) {
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
            foreach (array_keys($person['_components']) as $code) {
                $usedCodes[(string) $code] = true;
            }
            if (is_string($person['end_on'] ?? null) && in_array($person['match']['status'], ['linked', 'matched'], true)) {
                $person['warnings'][] = 'Podklady uvádějí ukončení k ' . self::czechDate($person['end_on'])
                    . '. Zkontrolujte, že je pracovní vztah k tomuto dni ukončený; import ho neukončuje.';
            }
        }
        unset($person);

        $codes = [];
        foreach ($mapped['sheets'] as $sheet) {
            foreach ($sheet['columns'] as $binding) {
                foreach ([$binding, ...$binding['conditions']] as $candidate) {
                    if ($candidate['meaning'] === AttendanceMeaning::COMPONENT
                        && $candidate['component_code'] !== null
                        // Složka „podle hlavičky" se hlásí jen tehdy, když opravdu nese částku.
                        && (!isset($mapped['auto_components'][$candidate['component_code']])
                            || isset($usedCodes[$candidate['component_code']]))) {
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

        /*
         * Vstupní brána čte složky přes PayrollComponentRepository, který si
         * výchozí katalog (ODMENA, MZDA_UKOLOVA, …) zakládá sám. Bez téhož
         * kroku by náhled hlásil „složka chybí" u něčeho, co použití přijme.
         */
        if ($codes !== []) {
            $this->components->ensureDefaults($supplierId);
        }
        $checks = [];
        foreach (array_keys($codes) as $code) {
            $code = (string) $code;
            $component = $this->inputRepository->resolveComponent($supplierId, $code, $periodStart);
            $definition = $definitions[$code] ?? null;
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

        $summary = [
            'persons' => count($persons),
            'matched' => 0,
            'ambiguous' => 0,
            'not_found' => 0,
            'metrics' => 0,
            'components' => 0,
            'amount_minor_total' => 0,
            'deductions' => 0,
            'deduction_minor_total' => 0,
        ];
        foreach ($persons as $person) {
            $status = $person['match']['status'];
            if ($status === 'linked' || $status === 'matched') {
                ++$summary['matched'];
            } elseif ($status === 'ambiguous') {
                ++$summary['ambiguous'];
            } else {
                ++$summary['not_found'];
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
            'unrecognized_columns' => $unrecognized,
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
        // Úvazek z podkladů bývá i text („noční - 18,75", „DPP"); nepřečtený
        // osobu neshodí, vztah vznikne bez něj a výsledek řekne, co doplnit.
        $rawWeekly = $item['weekly_hours'] ?? null;
        if (is_float($rawWeekly) || is_int($rawWeekly)) {
            $rawWeekly = sprintf('%.2F', $rawWeekly);
        }
        $weeklyHours = null;
        $weeklyNote = null;
        if (is_string($rawWeekly) && trim($rawWeekly) !== '') {
            $weeklyHours = AttendanceDecimal::weeklyHours($rawWeekly);
            if ($weeklyHours === null && !in_array($item['relation_type'] ?? null, ['dpp', 'dpc'], true)) {
                $weeklyNote = sprintf(
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
        $input = [
            'full_name' => $item['full_name'] ?? null,
            'first_name' => $item['first_name'] ?? null,
            'last_name' => $item['last_name'] ?? null,
            'birth_number' => $item['birth_number'] ?? null,
            'birth_date' => $item['birth_date'] ?? null,
            'health_insurer_code' => $item['health_insurer_code'] ?? null,
            'relation_type' => $item['relation_type'] ?? null,
            'planned_start_on' => $item['planned_start_on'] ?? null,
            'weekly_hours' => $weeklyHours,
            'monthly_gross' => $monthlyGross,
        ];
        $person = $this->personCreate->create($supplierId, $input, $userId, $ip, $userAgent);
        $employeeId = (int) ($person['id'] ?? 0);
        $employment = $this->imports->latestEmploymentOfEmployee($supplierId, $employeeId)
            ?? throw new \LogicException('Nově založený pracovní vztah nebyl nalezen.');

        $message = $weeklyNote;
        $plannedStart = is_string($input['planned_start_on']) ? $input['planned_start_on'] : '';
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
        // Nová osoba dostane osobní číslo z podkladů místo automatického ZAM-….
        $number = self::personalNumber($item['personal_number'] ?? null);
        if ($number !== null) {
            $renameError = $this->renameEmployment($supplierId, $employeeId, $employment['id'], $number, $userId);
            if ($renameError !== null) {
                $message = trim(($message ?? '') . ' ' . $renameError);
            }
        }
        $this->saveLinks(
            $supplierId,
            is_string($item['personal_number'] ?? null) ? $item['personal_number'] : '',
            AttendanceText::personKey((string) $input['full_name']),
            $employeeId,
            $employment['id'],
            $userId,
        );

        return ['employee_id' => $employeeId, 'employment_id' => $employment['id'], 'message' => $message];
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
            $number = self::personalNumber($item['person']['personal_number'] ?? null);
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
     * @param array{terms_id:int,effective_from:string,effective_to:?string,monthly_gross_minor:?int,is_latest:bool,employment_status:string}|null $terms
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
            ),
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
