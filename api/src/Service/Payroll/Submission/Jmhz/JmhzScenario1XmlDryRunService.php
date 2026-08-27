<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Report\EpoEnvelope;

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
        private JmhzDeadlinePolicy $deadlines = new JmhzDeadlinePolicy(),
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
        $blockers = array_map(
            static fn (JmhzScenario1Blocker $blocker): array => $blocker->toArray(),
            $resolution->blockers,
        );
        if ($resolution->status() !== 'resolved') {
            $result = [
                'status' => 'blocked',
                'preparation_id' => $preparationId,
                'office_id' => $officeId,
                'blockers' => $blockers,
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
        $result = $this->validator->dryRun(
            $resolution,
            JmhzSubmissionEnvelope::create(
                $this->guids->next(),
                $this->formGuids($document),
                gmdate('Y-m-d\TH:i:s\Z'),
                self::PRODUCT_NAME,
                EpoEnvelope::appVersion() ?? '0',
            ),
        );

        // XSD hlídá tvar, katalog kontrol obsah. Teprve oboje dohromady říká,
        // jestli by ČSSZ podání přijala — a mezera v pokrytí katalogu se musí
        // projevit jako nepřipravenost, ne jako zelený test.
        $controls = $this->controls->validate(
            $result['xml'],
            JmhzControlContext::today(schemaValidated: true),
        );

        return [
            'status' => $controls->submittable() ? 'dry_run_valid' : 'dry_run_incomplete',
            'preparation_id' => $preparationId,
            'office_id' => $officeId,
            'blockers' => [],
            'controls' => $controls->toArray(),
            'deadline' => $this->deadline($document),
            'xml' => $result['xml'],
            'xml_sha256' => $result['sha256'],
            'schema' => $result['schema'],
            'guids' => [
                'scope' => 'preview_only',
                'note' => 'GUIDy náhledu se pro ostré podání nepoužijí; to si vyžádá vlastní a zmrazí je.',
            ],
            'official_submission' => $this->officialSubmission(),
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
