<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Report\EpoEnvelope;
use Psr\Clock\ClockInterface;

/**
 * Lokální test podání. Sestaví XML běžného měsíčního hlášení z ověřeného
 * preparation snapshotu a ověří je proti připnutému XSD — nic neodesílá,
 * nic neukládá a nezakládá žádné podání.
 *
 * GUIDy vznikají při každém běhu nové a jsou proto použitelné VÝHRADNĚ pro
 * náhled. Ostré podání si musí GUID vyžádat a zmrazit, protože duplicitu
 * přijatého podání nelze u ČSSZ nikdy zopakovat.
 */
final readonly class JmhzScenario1XmlDryRunService
{
    private const PRODUCT_NAME = 'MyÚčto.cz';

    public function __construct(
        private JmhzScenario1DocumentService $documents,
        private JmhzScenario1XmlValidator $validator,
        private JmhzSubmissionGuidFactory $guids,
        private JmhzScenario1ControlValidator $controls,
        private JmhzDeadlinePolicy $deadlines,
        // Datum vyplnění a den vyhodnocení kontrol (90: měsíc musí skončit)
        // z hodin aplikace jako u zmrazení; bez nich by test hlášení a jeho
        // zmrazení posuzovaly totéž podání k různým dnům.
        private ClockInterface $clock,
    ) {}

    /**
     * @param int|null $officeId registrace u OSSZ, za kterou se nacvičuje;
     *        `null` je jednoúčtárenský běh
     * @return array<string,mixed>
     */
    public function dryRun(
        int $supplierId,
        string $environment,
        int $preparationId,
        ?int $officeId = null,
    ): array {
        $resolution = $this->documents->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
        );
        $blockers = self::withoutConsequentialBlockers(array_map(
            static fn (JmhzScenario1Blocker $blocker): array => $blocker->toArray(),
            $resolution->blockers,
        ));
        if ($resolution->status() !== 'resolved') {
            $result = [
                'status' => 'blocked',
                'preparation_id' => $preparationId,
                'office_id' => $officeId,
                'blockers' => $blockers,
                'deferred' => self::deferred($resolution, null),
                'official_submission' => $this->officialSubmission(),
            ];
            if (in_array(
                'jmhz_scenario1_scope_unsupported',
                array_column($blockers, 'code'),
                true,
            )) {
                $scenario2 = $this->documents->resolveScenario2(
                    $supplierId,
                    $environment,
                    $preparationId,
                );
                $result['scenario_2'] = [
                    'status' => $scenario2->status(),
                    'candidate' => $scenario2->candidate?->payload,
                    'candidate_sha256' => $scenario2->candidate?->sha256(),
                    'blockers' => array_map(
                        static fn (JmhzScenario1Blocker $blocker): array => $blocker->toArray(),
                        $scenario2->blockers,
                    ),
                ];
                $specialScenarios = $this->documents->resolveSpecialScenarios(
                    $supplierId,
                    $environment,
                    $preparationId,
                );
                if ($specialScenarios !== null) {
                    $result['special_scenarios'] = [
                        'status' => $specialScenarios->status(),
                        'candidate' => $specialScenarios->candidate?->payload,
                        'candidate_sha256' => $specialScenarios->candidate?->sha256(),
                        'blockers' => array_map(
                            static fn (JmhzScenario1Blocker $blocker): array => $blocker->toArray(),
                            $specialScenarios->blockers,
                        ),
                    ];
                }
            }

            return $result;
        }

        $document = $resolution->requireResolvedDocument();
        $result = $this->validator->dryRunPackages(
            $resolution,
            JmhzSubmissionEnvelope::create(
                $this->guids->next(),
                $this->formGuids($document),
                PayrollSubmissionCalendar::filledAt($this->clock->now()),
                self::PRODUCT_NAME,
                EpoEnvelope::appVersion() ?? '0',
            ),
        );

        // XSD hlídá tvar, katalog kontrol obsah. Teprve oboje dohromady říká,
        // jestli by ČSSZ podání přijala — a mezera v pokrytí katalogu se musí
        // projevit jako nepřipravenost, ne jako zelený test.
        $reports = [];
        foreach ($result['packages'] as $package) {
            $reports[] = $this->controls->validate(
                $package['xml'],
                JmhzControlContext::today(schemaValidated: true, at: $this->clock->now()),
            );
        }
        $controls = $reports[0];
        $submittable = array_reduce(
            $reports,
            static fn (bool $carry, JmhzControlEvaluationReport $report): bool
                => $carry && $report->submittable(),
            true,
        );
        $first = $result['packages'][0];

        return [
            'status' => $submittable ? 'dry_run_valid' : 'dry_run_incomplete',
            'preparation_id' => $preparationId,
            'office_id' => $officeId,
            'blockers' => [],
            'deferred' => self::deferred($resolution, $controls),
            'controls' => $controls->toArray(),
            // Nad 1500 formulářů je hlášení rozdělené do dílčích balíků; každý
            // se ověřuje zvlášť a kontroly souhrnu a pojistné části jsou
            // v prvním.
            'packages' => count($result['packages']) === 1 ? [] : array_map(
                static fn (array $package, JmhzControlEvaluationReport $report): array => [
                    'ordinal' => $package['ordinal'],
                    'xml' => $package['xml'],
                    'xml_sha256' => $package['sha256'],
                    'submittable' => $report->submittable(),
                    'blocking' => array_map(
                        static fn (JmhzControlFinding $finding): array => $finding->toArray(),
                        [...$report->blocking(), ...$report->coverageGaps()],
                    ),
                ],
                $result['packages'],
                $reports,
            ),
            'deadline' => $this->deadline($document),
            'xml' => $first['xml'],
            'xml_sha256' => $first['sha256'],
            'schema' => $result['schema'],
            'guids' => [
                'scope' => 'preview_only',
                'note' => 'GUIDy náhledu se pro ostré podání nepoužijí; to si vyžádá vlastní a zmrazí je.',
            ],
            'official_submission' => $this->officialSubmission(),
        ];
    }

    /**
     * Propustné kontroly, které porovnávají pojistnou část se SOUČTEM
     * podaných formulářů. Pojistná část nese pojistné a slevu za všechny
     * zaměstnance včetně odložených (jinak by se sleva po splatnosti už
     * uplatnit nedala), takže při odložení se musí rozejít - a ČSSZ to tak
     * výslovně připouští. Varování z nich jsou očekávaná, ne chyba.
     */
    public const DEFERRAL_EXPECTED_CONTROL_IDS = [1, 7, 9, 12, 142, 207, 209, 213, 227, 269, 297, 298];

    /**
     * Chybějící pracovní souhrn pro ELDP je u vztahu s neschválenou docházkou
     * jen důsledek téže příčiny a opravuje se týmž krokem. Hlášený dvakrát
     * zdvojnásobil seznam nálezů (u firmy před koncem měsíce stovky řádků)
     * a vypadal jako druhá, samostatná vada dat.
     *
     * @param list<array<string,mixed>> $blockers
     * @return list<array<string,mixed>>
     */
    public static function withoutConsequentialBlockers(array $blockers): array
    {
        $unapproved = [];
        foreach ($blockers as $blocker) {
            if (($blocker['code'] ?? null) === 'jmhz_work_month_not_approved'
                && ($blocker['entity_type'] ?? null) === 'employment'
                && is_int($blocker['entity_id'] ?? null)
            ) {
                $unapproved[$blocker['entity_id']] = true;
            }
        }
        if ($unapproved === []) {
            return $blockers;
        }

        return array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => !(
                ($blocker['code'] ?? null) === 'jmhz_eldp_work_summary_missing'
                && ($blocker['entity_type'] ?? null) === 'employment'
                && isset($unapproved[$blocker['entity_id'] ?? -1])
            ),
        ));
    }

    /**
     * Vynechané formuláře hlášení: kdo, proč a která varování kontrol to
     * vysvětluje.
     *
     * @return array<string,mixed>|null
     */
    private static function deferred(
        JmhzScenario1Resolution $resolution,
        ?JmhzControlEvaluationReport $controls,
    ): ?array {
        if ($resolution->exclusion === null || $resolution->exclusion->isEmpty()) {
            return null;
        }
        $provenance = $resolution->candidate?->payload['provenance'] ?? null;
        $exclusion = is_array($provenance) ? ($provenance['form_exclusion'] ?? null) : null;
        $expected = [];
        foreach ($controls?->warnings() ?? [] as $finding) {
            if (in_array($finding->controlId, self::DEFERRAL_EXPECTED_CONTROL_IDS, true)) {
                $expected[$finding->controlId] = true;
            }
        }
        $expected = array_keys($expected);
        sort($expected, SORT_NUMERIC);

        return [
            'purpose' => $resolution->exclusion->purpose,
            'deferral_ids' => $resolution->exclusion->deferralIds,
            'employment_ids' => is_array($exclusion) ? ($exclusion['employment_ids'] ?? []) : [],
            'employee_ids' => is_array($exclusion) ? ($exclusion['employee_ids'] ?? []) : [],
            'summary_excluded_employee_ids' => is_array($exclusion)
                ? ($exclusion['summary_excluded_employee_ids'] ?? [])
                : [],
            'blockers' => array_map(
                static fn (JmhzScenario1Blocker $blocker): array => $blocker->toArray(),
                $resolution->excludedBlockers,
            ),
            'expected_warning_control_ids' => $expected,
        ];
    }

    /**
     * Lhůta pro podání za vykazované období. Do testu patří proto, že „XML je
     * v pořádku" a „ještě to stihnu" jsou dvě různé otázky a uživatel se ptá na
     * obě naráz. Termín se posouvá na nejbližší pracovní den, takže odhadnout
     * ho od dvacátého v měsíci nejde.
     *
     * @return array<string,string>|null
     */
    private function deadline(JmhzScenario1NormalizedDocument $document): ?array
    {
        $scope = $document->payload['scope'] ?? null;
        $periodStart = is_array($scope) ? ($scope['period_start'] ?? null) : null;
        if (!is_string($periodStart)) {
            return null;
        }
        $window = $this->deadlines->forPeriod($periodStart);

        return [
            'period_start' => $periodStart,
            'earliest_submission_on' => $window->earliestSubmissionOn,
            'due_on' => $window->dueOn,
            'calendar_basis' => $window->calendarBasis,
            'ruleset_id' => $window->rulesetId,
        ];
    }

    /** @return array<int,string> */
    private function formGuids(JmhzScenario1NormalizedDocument $document): array
    {
        $people = $document->payload['people'] ?? null;
        $guids = [];
        if (!is_array($people)) {
            return $guids;
        }
        foreach ($people as $person) {
            $employments = is_array($person) ? ($person['employments'] ?? null) : null;
            if (!is_array($employments)) {
                continue;
            }
            foreach ($employments as $employment) {
                $employmentId = is_array($employment)
                    ? ($employment['employment_id'] ?? null)
                    : null;
                if (is_int($employmentId) && $employmentId > 0) {
                    $guids[$employmentId] = $this->guids->next();
                }
            }
        }

        return $guids;
    }

    /**
     * Proč tenhle výsledek NENÍ podání.
     *
     * Důvod se změnil a mlčet o tom by bylo zavádějící: kanál VREP je zapojený
     * a ověřený odesláním do testovacího prostředí ČSSZ. Test ale zůstává
     * testem ze dvou důvodů, které s dopravou nesouvisejí — GUIDy tu vznikají
     * při každém běhu nové, kdežto ostré podání si je musí zmrazit (duplicitu
     * u ČSSZ nelze vzít zpět), a nezakládá se žádný záznam podání, takže není
     * co odeslat ani k čemu přiřadit protokol.
     *
     * @return array<string,mixed>
     */
    private function officialSubmission(): array
    {
        return [
            'supported' => false,
            'reason_code' => 'jmhz_dry_run_is_not_a_submission',
            'reason' => 'Jde o lokální test: GUIDy vznikají při každém běhu nové'
                . ' a nezakládá se žádné podání. Odeslání na ČSSZ se spouští'
                . ' zvlášť nad zmrazeným podáním.',
        ];
    }
}
