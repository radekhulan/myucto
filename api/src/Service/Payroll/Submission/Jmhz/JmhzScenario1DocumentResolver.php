<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;

final class JmhzScenario1DocumentResolver
{
    /**
     * Verze přípravy, ze kterých se scénář 1 normalizuje.
     *
     * @var list<string>
     */
    public const SUPPORTED_BUILDER_VERSIONS = [
        JmhzPreparationSnapshotBuilder::PREVIOUS_V4_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::PREVIOUS_V5_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::PREVIOUS_V6_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::PREVIOUS_V7_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::PREVIOUS_V8_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::PREVIOUS_V9_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::PREVIOUS_V10_BUILDER_VERSION,
        JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
    ];

    /**
     * Ordinary evidence přípravy podle `employment_id`.
     *
     * Do v6 včetně nesla příprava JEDNU evidenci (objekt), protože se dala
     * zmrazit jen revize s jedinou osobou a jediným vztahem. Od v7 je to
     * SEZNAM — jedna evidence na každý vztah revize. Obě čteme, aby dřív
     * zmrazené přípravy zůstaly zpracovatelné.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ordinaryEvidenceByEmployment(
        JmhzVerifiedPreparationSnapshot $preparation,
    ): array {
        $raw = $preparation->payload['ordinary_evidence'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $entries = array_is_list($raw) ? $raw : [$raw];
        $byEmployment = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                continue;
            }
            $scope = $entry['scope'] ?? null;
            $employmentId = is_array($scope) ? ($scope['employment_id'] ?? null) : null;
            if (is_int($employmentId) && $employmentId > 0) {
                $byEmployment[$employmentId] = $entry;
            }
        }
        return $byEmployment;
    }

    /**
     * @param int|null $officeId mzdová účtárna, za jejíž REGISTRACI u OSSZ se
     *        hlášení sestavuje; `null` uspěje jen u přípravy s jedinou
     *        registrací (zpětně kompatibilní jednoúčtárenský běh)
     */
    public function resolve(
        JmhzVerifiedPreparationSnapshot $preparation,
        ?JmhzPvpojPreview $pvpoj,
        ?string $pvpojFailureCode = null,
        ?int $officeId = null,
    ): JmhzScenario1Resolution {
        if (!in_array(
            $preparation->builderVersion,
            self::SUPPORTED_BUILDER_VERSIONS,
            true,
        )) {
            return new JmhzScenario1Resolution(null, [
                $this->blocker(
                    'jmhz_scenario1_source_version_unsupported',
                    'preparation',
                    $preparation->id,
                ),
            ]);
        }

        $blockers = [];
        $scope = $this->object($preparation->payload['scope'] ?? null);
        if ($preparation->builderVersion === JmhzPreparationSnapshotBuilder::BUILDER_VERSION) {
            $scenarioSet = $scope['scenario_set'] ?? null;
            if (!is_array($scenarioSet)
                || !array_is_list($scenarioSet)
                || $scenarioSet === []
                || array_values(array_unique($scenarioSet)) !== $scenarioSet
                || array_diff($scenarioSet, ['scenario_1', 'scenario_3']) !== []
            ) {
                return new JmhzScenario1Resolution(null, [
                    $this->blocker(
                        'jmhz_scenario1_scope_unsupported',
                        'preparation',
                        $preparation->id,
                    ),
                ]);
            }
            $scope['scenario_key'] = count($scenarioSet) === 1
                ? $scenarioSet[0]
                : 'scenario_1';
        } elseif (($scope['scenario_key'] ?? null) !== 'scenario_1') {
            return new JmhzScenario1Resolution(null, [
                $this->blocker(
                    'jmhz_scenario1_scope_unsupported',
                    'preparation',
                    $preparation->id,
                ),
            ]);
        } else {
            $scenarioSet = ['scenario_1'];
        }
        $sourceRevision = $this->object(
            $preparation->payload['source_revision'] ?? null,
        );
        $ordinaryEvidence = $this->ordinaryEvidenceByEmployment($preparation);
        $people = $this->officePeople(
            $this->rows($preparation->payload['people'] ?? null),
            $officeId,
        );
        $readinessIssues = $this->rows(
            $preparation->payload['readiness_issues'] ?? null,
        );
        $scopedReadinessIssues = $this->readinessIssuesForOffice(
            $readinessIssues,
            $people,
            $officeId,
        );
        if (($preparation->readiness['status'] ?? null) !== 'source_ready'
            && ($scopedReadinessIssues !== [] || $readinessIssues === [])
        ) {
            $blockers[] = $this->blocker(
                'jmhz_preparation_not_ready',
                'preparation',
                $preparation->id,
            );
            foreach ($scopedReadinessIssues as $issue) {
                $attributeIds = $issue['attribute_ids'] ?? [];
                $blockers[] = $this->blocker(
                    is_string($issue['code'] ?? null)
                        ? $issue['code']
                        : 'jmhz_preparation_issue_invalid',
                    is_string($issue['entity_type'] ?? null)
                        ? $issue['entity_type']
                        : 'preparation',
                    is_int($issue['entity_id'] ?? null)
                        ? $issue['entity_id']
                        : null,
                    is_array($attributeIds) && array_is_list($attributeIds)
                        ? array_values(array_filter($attributeIds, 'is_string'))
                        : [],
                );
            }
        }

        $registration = $this->registration(
            $preparation,
            $officeId,
            $blockers,
        );
        if (count($people) > 1500) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_form_limit_exceeded',
                'revision',
                $preparation->sourceRevisionId,
                ['10015', '10488'],
            );
        }
        $month = (int) substr($preparation->periodStart, 5, 2);
        $employerAnnual = $this->employerAnnual(
            $preparation->payload['employer_annual_evidence'] ?? null,
            $month,
            (int) substr($preparation->periodStart, 0, 4),
            $registration['id'],
            $preparation->sourceRevisionId,
            $blockers,
        );

        $normalizedPeople = [];
        // Bez osob není co potvrzovat — a hlavně není čím právní skutečnosti
        // doložit, takže prázdná příprava zůstává blokovaná jako dřív.
        $ordinaryEvidenceComplete = $people !== [];
        foreach ($people as $person) {
            $employeeId = is_int($person['employee_id'] ?? null)
                ? $person['employee_id']
                : null;
            $employments = $this->rows($person['employments'] ?? null);
            // Ordinary evidence se zmrazuje per pracovní vztah; scénář 1 už výš
            // blokuje osobu s víc vztahy, takže se bere evidence toho jediného.
            $personEvidence = [];
            foreach ($employments as $employmentRow) {
                $employmentKey = $employmentRow['employment_id'] ?? null;
                if (is_int($employmentKey) && isset($ordinaryEvidence[$employmentKey])) {
                    $personEvidence = $ordinaryEvidence[$employmentKey];
                    break;
                }
            }
            if ($personEvidence === []) {
                $ordinaryEvidenceComplete = false;
            }
            if (count($employments) !== 1) {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_multiple_employments_unsupported',
                    'person',
                    $employeeId,
                    ['10286', '10344', '10370', '10371', '10481', '10482', '10495'],
                );
            }
            $personSummary = $this->object($person['person_summary'] ?? null);
            $annual = $this->annualSummary(
                $person['annual_evidence'] ?? null,
                $preparation->periodStart,
                $employeeId,
                $blockers,
            );
            $statutory = $this->object($personSummary['statutory'] ?? null);
            $payslip = $this->object($personSummary['payslip_document'] ?? null);
            if (($statutory['status'] ?? null) !== 'calculated') {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_statutory_result_not_calculated',
                    'person',
                    $employeeId,
                );
            }
            $health = $this->calculatedResult(
                $statutory['health_insurance'] ?? null,
                'jmhz_scenario1_health_result_not_calculated',
                $employeeId,
                ['10371', '10482'],
                $blockers,
            );
            $social = $this->calculatedResult(
                $statutory['social_insurance'] ?? null,
                'jmhz_scenario1_social_result_not_calculated',
                $employeeId,
                ['10370', '10481'],
                $blockers,
            );
            $tax = $this->calculatedResult(
                $statutory['income_tax'] ?? null,
                'jmhz_scenario1_income_tax_result_not_calculated',
                $employeeId,
                ['10297', '10298', '10305', '10306', '10535'],
                $blockers,
            );
            $net = $this->netResult(
                $statutory['net_pay'] ?? null,
                $employeeId,
                $blockers,
            );
            $this->inspectUnsupportedTax($tax, $employeeId, $blockers);
            $this->inspectDeductions($net, $employeeId, $blockers);
            $advanceTaxCzk = $this->advanceTaxCzk($tax, $employeeId, $blockers);
            $taxCreditsCzk = $this->taxCreditsCzk($tax, $employeeId, $blockers);
            $declarationSigned = null;

            $normalizedEmployments = [];
            foreach ($employments as $employment) {
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                $employmentSource = $this->object($employment['employment'] ?? null);
                $selector = $this->object($employment['scenario_resolution'] ?? null);
                $scenarioKey = $selector['scenario_key'] ?? null;
                if (!is_string($scenarioKey)
                    || !in_array($scenarioKey, $scenarioSet, true)
                    || ($scenarioKey === 'scenario_3'
                        && (!in_array(
                            $employmentSource['relation_type'] ?? null,
                            ['partner_dependent', 'statutory_body'],
                            true,
                        )
                            || ($selector['activity_code'] ?? null) !== 'S'
                            || ($selector['relationship_detail_code'] ?? null) !== '1'))
                ) {
                    $blockers[] = $this->blocker(
                        'jmhz_scenario_profile_unsupported',
                        'employment',
                        $employmentId,
                        ['10239', '10502'],
                    );
                }
                if (($employmentSource['is_primary'] ?? null) !== true) {
                    $blockers[] = $this->blocker(
                        'jmhz_primary_employment_unresolved',
                        'person',
                        $employeeId,
                        ['10495'],
                    );
                } else {
                    // 10419 nese SDZ, a ta se vyplňuje jednou za zaměstnance na
                    // primárním PPV. Proto se prohlášení čte z účinného termu
                    // právě toho vztahu, ne z prvního v pořadí.
                    $declarationSigned = $this->taxpayerDeclaration(
                        $employment['term'] ?? null,
                        $employeeId,
                        $blockers,
                    );
                }
                $earnings = $this->earnings(
                    $employment['earnings_by_attribute_minor'] ?? null,
                );
                foreach (['10328', '10329', '10330', '10331'] as $attributeId) {
                    if (!array_key_exists($attributeId, $earnings)) {
                        $blockers[] = $this->blocker(
                            'jmhz_scenario1_earnings_vector_incomplete',
                            'employment',
                            $employmentId,
                            [$attributeId],
                        );
                    }
                }
                $earningsCzk = [];
                foreach ($earnings as $attributeId => $minor) {
                    $attributeId = (string) $attributeId;
                    $whole = $this->wholeCzk(
                        $minor,
                        $attributeId,
                        'employment',
                        $employmentId,
                        $blockers,
                    );
                    if ($whole !== null) {
                        $earningsCzk[$attributeId] = $whole;
                    }
                }
                ksort($earningsCzk, SORT_STRING);
                $identity = $this->object($employment['identity'] ?? null);
                $personIdentifier = $this->object(
                    $identity['person_external_identifier'] ?? null,
                );
                $employmentIdentifier = $this->object(
                    $identity['jmhz_employment_external_identifier'] ?? null,
                );
                $average = $this->object($employment['average_earning'] ?? null);
                $normalizedEmployments[] = [
                    'employment_id' => $employmentId,
                    'social_base' => $this->socialBase(
                        $employment['insurance'] ?? null,
                        $employmentId,
                        $blockers,
                    ),
                    'part_time_discount' => $this->partTimeDiscount(
                        $employment['insurance'] ?? null,
                        $employment['scenario_resolution'] ?? null,
                        $employmentId,
                        $blockers,
                    ),
                    'primary' => $employmentSource['is_primary'] ?? null,
                    'identity' => [
                        'person_external_identifier' => $personIdentifier['value'] ?? null,
                        'employment_external_identifier' => $employmentIdentifier['value'] ?? null,
                    ],
                    'selector' => $employment['scenario_resolution'] ?? null,
                    'term' => $employment['term'] ?? null,
                    'work_month' => $employment['work_month'] ?? null,
                    'eldp' => $employment['eldp'] ?? null,
                    'average_hourly' => [
                        'minor_units' => $average['average_hourly_minor'] ?? null,
                        'scale' => 2,
                    ],
                    'earnings_by_attribute_czk' => $earningsCzk,
                    'insurance' => $employment['insurance'] ?? null,
                ];
            }
            usort(
                $normalizedEmployments,
                static fn (array $left, array $right): int =>
                    (int) ($left['employment_id'] ?? 0)
                    <=> (int) ($right['employment_id'] ?? 0),
            );
            $normalizedPeople[] = [
                'employee_id' => $employeeId,
                'summary' => [
                    'income_total_czk' => $this->wholeCzk(
                        $this->nestedInt($personSummary, ['totals', 'jmhz_amount_minor']),
                        '10286',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'net_income_czk' => $this->wholeCzk(
                        is_int($net['net_before_deductions_minor_units'] ?? null)
                            ? $net['net_before_deductions_minor_units']
                            : null,
                        '10344',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'employee_health_czk' => $this->wholeCzk(
                        is_int($health['employee_contribution_minor_units'] ?? null)
                            ? $health['employee_contribution_minor_units']
                            : null,
                        '10371',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'employer_health_czk' => $this->wholeCzk(
                        is_int($health['employer_contribution_minor_units'] ?? null)
                            ? $health['employer_contribution_minor_units']
                            : null,
                        '10482',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'employee_social_czk' => $this->wholeCzk(
                        is_int($social['employee_contribution_minor_units'] ?? null)
                            ? $social['employee_contribution_minor_units']
                            : null,
                        '10370',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'employer_social_czk' => $this->wholeCzk(
                        $this->employerSocialMinor($social, $payslip),
                        '10481',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'deductions_recorded' => $personEvidence === []
                        ? null
                        : ($personEvidence['attribute_values']['10116'] ?? null),
                    'taxpayer_declaration_signed' => $declarationSigned,
                    'advance_tax_czk' => $advanceTaxCzk,
                    'tax_credits_czk' => $taxCreditsCzk,
                    'annual' => $annual,
                ],
                'employments' => $normalizedEmployments,
            ];
        }
        usort(
            $normalizedPeople,
            static fn (array $left, array $right): int =>
                (int) ($left['employee_id'] ?? 0)
                <=> (int) ($right['employee_id'] ?? 0),
        );

        $pvpojPayload = null;
        if ($pvpoj === null) {
            $blockers[] = $this->blocker(
                $pvpojFailureCode ?? 'jmhz_scenario1_pvpoj_unavailable',
                'revision',
                $preparation->sourceRevisionId,
            );
        } elseif ($pvpoj->supplierId !== $preparation->supplierId
            || $pvpoj->runId !== $preparation->runId
            || $pvpoj->revisionId !== $preparation->sourceRevisionId
            || $pvpoj->revisionNo !== $preparation->revisionNo
            || $pvpoj->period !== substr($preparation->periodStart, 0, 7)
            || ($pvpoj->source['revision_input_hash'] ?? null)
                !== ($sourceRevision['input_snapshot_hash'] ?? null)
            // Přehled je podíl JEDNÉ registrace. Kdyby se do hlášení dostal
            // přehled cizí účtárny, kontrola 12 ČSSZ (pojistné zaměstnanců
            // proti součtu součástí) by srovnávala dvě různé populace —
            // a to je přesně ten rozdíl, který se ve zmrazeném XML nedohledá.
            || ($officeId !== null
                && $pvpoj->office['office_id'] !== $officeId)
            || ($registration['id'] !== null
                && $pvpoj->office['office_id'] !== $registration['id'])
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_pvpoj_source_mismatch',
                'revision',
                $preparation->sourceRevisionId,
            );
        } else {
            $pvpojPayload = [
                'sha256' => $pvpoj->sha256(),
                'source' => $pvpoj->source,
                'values' => $pvpoj->pvpoj,
                'reconciliation' => $pvpoj->reconciliation,
            ];
        }

        /*
         * Variabilní symbol REGISTRACE, za kterou se podává.
         *
         * Přednost má přehled o výši pojistného, protože jeho variabilní symbol
         * patří přesně registraci vybrané ze zmrazené účtárny. Platební účet
         * ČSSZ není zákonným vstupem JMHZ a může se nastavit až při přípravě
         * plateb; sestavení hlášení proto na platebním závazku nezávisí.
         * Neresolvovaná registrace variabilní symbol NEDOSTANE — bez ní není za
         * co podat a doplnit ho z libovolného přehledu by znamenalo vykázat
         * lidi pod cizím číslem.
         */
        $variableSymbol = $registration['variable_symbol'];
        if ($pvpojPayload !== null && $registration['id'] !== null) {
            $previewSymbol = $pvpoj?->office['variable_symbol'] ?? null;
            if ($variableSymbol !== null && $previewSymbol !== $variableSymbol) {
                $blockers[] = $this->blocker(
                    'jmhz_office_variable_symbol_mismatch',
                    'office',
                    $registration['id'],
                    ['10221'],
                );
                $previewSymbol = null;
            }
            $variableSymbol = $previewSymbol;
        }

        // Nálezy zůstávají na revizi (ne na osobě): adresnost už nese readiness
        // přípravy, která chybějící evidenci hlásí na konkrétním vztahu, a tyhle
        // blockery se do dokumentu kopírují z ní. Tady je to jen pojistka, aby
        // dokument bez kompletní evidence nikdy neprošel.
        if (!$ordinaryEvidenceComplete) {
            $blockers[] = $this->blocker(
                'jmhz_attribute_10116_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10116'],
            );
            $blockers[] = $this->blocker(
                'jmhz_attribute_10546_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10546', '10547'],
            );
            $blockers[] = $this->blocker(
                'jmhz_interaction_in13_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10408', '10409', '10410'],
            );
            $blockers[] = $this->blocker(
                'jmhz_interaction_in28_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10347', '10348', '10349'],
            );
            $blockers[] = $this->blocker(
                'jmhz_interaction_in30_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10270', '10271', '10272'],
            );
        }
        $blockers = $this->normalizeBlockers($blockers);

        $candidate = new JmhzScenario1NormalizedDocument([
            'schema_reference' => JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE,
            'scope' => $scope + [
                'submission_kind' => 'regular',
                'office_id' => $registration['id'],
            ],
            'specification' => $preparation->payload['specification'] ?? null,
            'provenance' => [
                'preparation_id' => $preparation->id,
                'builder_version' => $preparation->builderVersion,
                'source_manifest_sha256' => $preparation->sourceManifestSha256,
                'readiness_sha256' => $preparation->readinessSha256,
                'snapshot_fingerprint' => $preparation->snapshotFingerprint,
                'source_revision' => $sourceRevision,
                'pvpoj_preview_sha256' => $pvpoj?->sha256(),
                'ordinary_evidence' => $preparation->payload['source_versions']['ordinary_evidence'] ?? null,
            ],
            'header' => [
                'type' => 'R',
                'variable_symbol' => $variableSymbol,
                'year' => (int) substr($preparation->periodStart, 0, 4),
                'month' => $month,
                'individual_form_count' => count($normalizedPeople),
                'total_form_count' => count($normalizedPeople) + 2,
            ],
            'employer' => [
                'source' => $preparation->payload['employer_summary']['employer'] ?? null,
                'pvpoj' => $pvpojPayload,
                'summary_totals' => $this->employerTaxTotals($normalizedPeople),
                'annual' => $employerAnnual,
            ],
            'people' => $normalizedPeople,
            'interactions' => [
                'IN13' => $ordinaryEvidenceComplete ? false : null,
                'IN28' => $ordinaryEvidenceComplete ? false : null,
                'IN30' => $ordinaryEvidenceComplete ? false : null,
                'IN36' => $ordinaryEvidenceComplete ? false : null,
            ],
        ]);

        return new JmhzScenario1Resolution($candidate, $blockers);
    }

    /**
     * Registrace u OSSZ, za kterou se hlášení sestavuje.
     *
     * Měsíční hlášení se podává za registraci, tedy za variabilní symbol
     * účtárny — ne za mzdový běh. Běh přes víc účtáren proto musí účtárnu
     * ZVOLIT; vykázat všechny osoby běhu pod jedním variabilním symbolem by
     * znamenalo přiřadit lidi k cizí registraci.
     *
     * Příprava starší než v6 registrace nenese. Tam se vrací její jediný
     * historický variabilní symbol, aby se jednoúčtárenský běh choval přesně
     * jako dřív.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     * @param-out list<JmhzScenario1Blocker> $blockers
     * @return array{id:?int,variable_symbol:?string}
     */
    private function registration(
        JmhzVerifiedPreparationSnapshot $preparation,
        ?int $officeId,
        array &$blockers,
    ): array {
        $summary = $this->object(
            $preparation->payload['employer_summary'] ?? null,
        );
        $legacy = $this->object($summary['office'] ?? null);
        $fallback = [
            'id' => is_int($legacy['id'] ?? null) ? $legacy['id'] : null,
            'variable_symbol' =>
                is_string($legacy['social_security_variable_symbol'] ?? null)
                    ? $legacy['social_security_variable_symbol']
                    : null,
        ];
        $registrations = $this->rows($summary['offices'] ?? null);
        if ($registrations === []) {
            if ($officeId !== null && $fallback['id'] !== $officeId) {
                $blockers[] = $this->blocker(
                    'jmhz_social_office_unknown',
                    'office',
                    $officeId,
                    ['10221'],
                );
            }

            return $fallback;
        }
        if ($officeId === null) {
            if (count($registrations) !== 1) {
                $blockers[] = $this->blocker(
                    'jmhz_social_multiple_offices',
                    'revision',
                    $preparation->sourceRevisionId,
                    ['10221'],
                );

                return ['id' => null, 'variable_symbol' => null];
            }
            $officeId = is_int($registrations[0]['id'] ?? null)
                ? $registrations[0]['id']
                : null;
        }
        foreach ($registrations as $registration) {
            if (($registration['id'] ?? null) !== $officeId) {
                continue;
            }
            $symbol = $registration['social_security_variable_symbol'] ?? null;
            if (!is_string($symbol)) {
                $blockers[] = $this->blocker(
                    'jmhz_office_variable_symbol_missing',
                    'office',
                    $officeId,
                    ['10221'],
                );
            }

            return [
                'id' => $officeId,
                'variable_symbol' => is_string($symbol) ? $symbol : null,
            ];
        }
        $blockers[] = $this->blocker(
            'jmhz_social_office_unknown',
            'office',
            $officeId,
            ['10221'],
        );

        return ['id' => null, 'variable_symbol' => null];
    }

    /**
     * Osoby TÉTO registrace.
     *
     * Individualizované součásti a pojistná část jedné datové věty musí popsat
     * TUTÉŽ populaci: kontrola 12 ČSSZ sčítá pojistné zaměstnanců (10370) přes
     * součásti a porovnává je s úhrnem 10028 pojistné části. Kdyby hlášení za
     * jednu registraci neslo součásti všech účtáren běhu, součet by nikdy
     * neseděl — a lidé by navíc byli vykázaní pod cizím variabilním symbolem.
     *
     * @param list<array<string,mixed>> $people
     * @return list<array<string,mixed>>
     */
    private function officePeople(array $people, ?int $officeId): array
    {
        if ($officeId === null) {
            return $people;
        }
        $filtered = [];
        foreach ($people as $person) {
            $employments = [];
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $source = $this->object($employment['employment'] ?? null);
                if (($source['office_id'] ?? null) === $officeId) {
                    $employments[] = $employment;
                }
            }
            if ($employments === []) {
                continue;
            }
            $person['employments'] = $employments;
            $filtered[] = $person;
        }

        return $filtered;
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @param list<array<string,mixed>> $people
     * @return list<array<string,mixed>>
     */
    private function readinessIssuesForOffice(
        array $issues,
        array $people,
        ?int $officeId,
    ): array {
        if ($officeId === null) {
            return $issues;
        }
        $employeeIds = [];
        $employmentIds = [];
        foreach ($people as $person) {
            $employeeId = $person['employee_id'] ?? null;
            if (is_int($employeeId)) {
                $employeeIds[$employeeId] = true;
            }
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $employmentId = $employment['employment_id'] ?? null;
                if (is_int($employmentId)) {
                    $employmentIds[$employmentId] = true;
                }
            }
        }

        return array_values(array_filter(
            $issues,
            static function (array $issue) use (
                $employeeIds,
                $employmentIds,
                $officeId,
            ): bool {
                $entityType = $issue['entity_type'] ?? null;
                $entityId = $issue['entity_id'] ?? null;
                return match ($entityType) {
                    'employment' => is_int($entityId)
                        && isset($employmentIds[$entityId]),
                    'person', 'employee' => is_int($entityId)
                        && isset($employeeIds[$entityId]),
                    'office' => $entityId === $officeId,
                    default => true,
                };
            },
        ));
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @param list<string> $attributeIds
     * @return array<string,mixed>
     */
    private function calculatedResult(
        mixed $value,
        string $code,
        ?int $employeeId,
        array $attributeIds,
        array &$blockers,
    ): array {
        $result = $this->object($value);
        $issues = $result['issues'] ?? null;
        if (($result['status'] ?? null) !== 'calculated'
            || !is_array($issues) || !array_is_list($issues) || $issues !== []
        ) {
            $blockers[] = $this->blocker(
                $code,
                'person',
                $employeeId,
                $attributeIds,
            );
        }
        return $result;
    }

    /**
     * Vyměřovací základ zaměstnance (10477) a jeho rozpad podle § 5a odst. 1
     * ZPSZ (10478 písm. a, 10479 písm. b, 10480 písm. c) — obojí za JEDEN
     * pracovní vztah, ne za osobu.
     *
     * Za osobu by to bylo špatně: součást hlášení se podává za pracovní vztah
     * a člověk jich může mít víc. Osobní úhrn by se pak vykázal u každé
     * součásti znovu.
     *
     * Rozpad určuje sazbová kategorie zaměstnavatele, protože § 5a rozlišuje
     * právě podle ní: písmeno a) je běžná sazba, b) zdravotnická záchranná
     * služba a hasičský záchranný sbor podniku, c) rizikové zaměstnání.
     * Neověřená kategorie je blokátor, ne důvod k vynechání — hádat písmeno
     * znamená hádat sazbu, a kontrola 315 to spočítá jinak než my.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>|null
     */
    private function socialBase(
        mixed $insurance,
        ?int $employmentId,
        array &$blockers,
    ): ?array {
        $relationship = $this->object($insurance);
        if ($relationship === []) {
            return null;
        }
        $cappedBase = $this->wholeCzk(
            is_int($relationship['capped_assessment_base_minor_units'] ?? null)
                ? $relationship['capped_assessment_base_minor_units']
                : null,
            '10477',
            'employment',
            $employmentId,
            $blockers,
        );
        $participation = $this->object($relationship['participation'] ?? null);
        $reportedIncome = $this->wholeCzk(
            is_int($participation['participation_income_minor_units'] ?? null)
                ? $participation['participation_income_minor_units']
                : null,
            '10476',
            'employment',
            $employmentId,
            $blockers,
        );
        $base = $cappedBase === 0 ? null : $cappedBase;
        $letter = $base === null ? null : match ($relationship['employer_rate_category'] ?? null) {
            'ordinary' => 'a',
            'rescue_and_company_fire_service' => 'b',
            'risk_employment' => 'c',
            default => null,
        };
        if ($base !== null && $letter === null) {
            $blockers[] = $this->blocker(
                'jmhz_employer_rate_category_unverified',
                'employment',
                $employmentId,
                ['10478', '10479', '10480'],
            );
        }

        return [
            'assessment_base_czk' => $base,
            'reported_income_czk' => $reportedIncome,
            'paragraph5_letter' => $letter,
        ];
    }

    /**
     * Uplatněná sleva zaměstnavatele podle § 7a u JEDNÉ součásti: příznak
     * 10372, rozsah kratší pracovní nebo služební doby 10373 a písmeno důvodu
     * 10374 podle číselníku `duvod_uplatneni_slevy`.
     *
     * Vykazuje se jen sleva, která po posouzení § 7a odst. 3 skutečně náleží.
     * Doložený nárok, který některá z mezí vyloučila, se v hlášení neuplatňuje
     * a žádnou položku nenese — kdyby ho podání vykázalo, kontrola 1 ČSSZ by
     * napočítala víc zaměstnanců se slevou, než kolik jich pojistná část
     * uvádí, a slevu by nesedělo ani pojistné k úhradě.
     *
     * Kontrola 42 ČSSZ pouští slevu jen k druhu činnosti (10239) „1" až „9"
     * s bližším určením pracovněprávního vztahu (10502) „Žádné" — tedy
     * k pracovnímu poměru, přesně jak okruh vymezuje § 7a odst. 1. První
     * profil ani jeden z těch atributů nevykazuje, takže se podmínka musí
     * vynutit tady, nad rozhodnutím selektoru scénáře; z hotového XML už ji
     * ověřit nelze.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>|null
     */
    private function partTimeDiscount(
        mixed $insurance,
        mixed $scenarioResolution,
        ?int $employmentId,
        array &$blockers,
    ): ?array {
        $relationship = $this->object($insurance);
        if (($relationship['part_time_employer_discount'] ?? null) !== 'verified'
            || ($relationship['part_time_employer_discount_outcome'] ?? null) !== 'applied'
        ) {
            return null;
        }
        $reason = SocialPartTimeDiscountReason::tryFrom(
            is_string($relationship['part_time_employer_discount_reason'] ?? null)
                ? $relationship['part_time_employer_discount_reason']
                : '',
        );
        if ($reason === null) {
            $blockers[] = $this->blocker(
                'jmhz_employer_part_time_discount_reason_missing',
                'employment',
                $employmentId,
                ['10374'],
            );
            return null;
        }
        $selector = $this->object($scenarioResolution);
        $activityCode = $selector['activity_code'] ?? null;
        if (!is_string($activityCode)
            || preg_match('/^[1-9]$/D', $activityCode) !== 1
            || ($selector['relationship_detail_code'] ?? null) !== '1'
        ) {
            $blockers[] = $this->blocker(
                'jmhz_employer_part_time_discount_activity_unsupported',
                'employment',
                $employmentId,
                ['10239', '10372', '10502'],
            );
            return null;
        }
        $weeklyCentihours = null;
        if ($reason->requiresShorterWorkingTime()) {
            $millihours = $relationship['agreed_weekly_working_millihours'] ?? null;
            // 10373 je `cislo4_2Type`, tedy nejvýše 99,99 hodiny na dvě
            // desetinná místa. Tisícina hodiny se do něj nevejde a zaokrouhlit
            // ji potichu by znamenalo vykázat jiný úvazek, než jaký je sjednaný.
            if (!is_int($millihours)
                || $millihours <= 0
                || $millihours % 10 !== 0
                || $millihours > 99990
            ) {
                $blockers[] = $this->blocker(
                    'jmhz_employer_part_time_discount_working_time_unresolved',
                    'employment',
                    $employmentId,
                    ['10373'],
                );
                return null;
            }
            $weeklyCentihours = intdiv($millihours, 10);
        }

        return [
            'reason_code' => strtoupper($reason->paragraph7aLetter()),
            'weekly_working_time_centihours' => $weeklyCentihours,
        ];
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>
     */
    private function netResult(
        mixed $value,
        ?int $employeeId,
        array &$blockers,
    ): array {
        $result = $this->object($value);
        if (!is_int($result['net_before_deductions_minor_units'] ?? null)
            || !is_int($result['deducted_minor_units'] ?? null)
            || !is_int($result['net_payable_minor_units'] ?? null)
            || !is_array($result['relationships'] ?? null)
            || !array_is_list($result['relationships'])
            || !is_array($result['deductions'] ?? null)
            || !array_is_list($result['deductions'])
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_net_result_not_calculated',
                'person',
                $employeeId,
                ['10116', '10344'],
            );
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $tax
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function inspectUnsupportedTax(array $tax, ?int $employeeId, array &$blockers): void
    {
        $withholdingTax = $tax['withholding_tax_minor_units'] ?? null;
        $withholdingGroups = $tax['withholding_groups'] ?? null;
        if (!is_int($withholdingTax)
            || !is_array($withholdingGroups)
            || !array_is_list($withholdingGroups)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_income_tax_result_not_calculated',
                'person',
                $employeeId,
                ['10297', '10298', '10305', '10306', '10535'],
            );
            return;
        }
        if ($withholdingTax !== 0 || $withholdingGroups !== []) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_withholding_tax_unsupported',
                'person',
                $employeeId,
                ['10307', '10309'],
            );
        }
        // `MonthlyAdvanceTaxResult` neexportuje `tax_credits_minor_units` — ten
        // klíč nikdy nevznikne a podmínka na něj byla fail-open, takže
        // poplatník s podepsaným prohlášením (tedy s uplatněnou základní slevou)
        // procházel jako zelený, přestože rozpad 10299–10304 nemáme čím naplnit.
        $advance = $tax['advance_tax'] ?? null;
        if (!is_array($advance) || array_is_list($advance)) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_advance_tax_missing',
                'person',
                $employeeId,
                ['10297', '10298', '10305', '10306'],
            );

            return;
        }
        foreach ([
            'non_refundable_credits_minor_units' =>
                ['10299', '10300', '10301', '10302'],
            'child_credit_minor_units' => ['10303', '10304'],
            'tax_bonus_minor_units' => ['10306'],
        ] as $field => $attributeIds) {
            $value = $advance[$field] ?? null;
            if (!is_int($value)) {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_income_tax_result_not_calculated',
                    'person',
                    $employeeId,
                    $attributeIds,
                );
            } elseif ($value > 0 && $field === 'child_credit_minor_units') {
                // Daňové zvýhodnění na děti přináší vedle 10303 i blok
                // `zvyhodneniDetiMesic` (10453, 10440, 10451) a ten zmrazený
                // nemáme; vykázat samotnou částku by zamlčelo pořadí dětí.
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_child_credit_breakdown_unavailable',
                    'person',
                    $employeeId,
                    ['10303', '10304', '10440', '10451', '10453'],
                );
            }
        }
    }

    /**
     * Rozpad nepřenositelných slev po druzích. Vykazuje se jen tehdy, když se
     * nárokovaná částka uplatnila CELÁ — při částečném uplatnění není zákonem
     * dané, která konkrétní sleva se zkrátila, a rozdělit ji odhadem by znamenalo
     * vykázat nedoložený údaj.
     *
     * @param array<string,mixed> $tax
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array{
     *   basic:?int,disability_basic:?int,disability_extended:?int,ztp_p:?int
     * }
     */
    private function taxCreditsCzk(
        array $tax,
        ?int $employeeId,
        array &$blockers,
    ): array {
        $empty = [
            'basic' => null,
            'disability_basic' => null,
            'disability_extended' => null,
            'ztp_p' => null,
        ];
        $claimed = $tax['claimed_non_refundable_credits_minor_units'] ?? null;
        $applied = $tax['applied_non_refundable_credits_minor_units'] ?? null;
        $breakdown = $tax['claimed_non_refundable_credit_breakdown'] ?? null;
        // Prázdný rozpad je legitimní stav (žádná sleva se neuplatňuje) a
        // `array_is_list([])` je `true`, takže se na prázdno testuje zvlášť.
        if (!is_int($claimed) || !is_int($applied)
            || !is_array($breakdown)
            || ($breakdown !== [] && array_is_list($breakdown))
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_income_tax_result_not_calculated',
                'person',
                $employeeId,
                ['10299', '10300', '10301', '10302'],
            );

            return $empty;
        }
        if ($claimed === 0 && $applied === 0) {
            return $empty;
        }
        if ($claimed !== $applied) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_partial_tax_credit_unsupported',
                'person',
                $employeeId,
                ['10299', '10300', '10301', '10302'],
            );

            return $empty;
        }
        $result = $empty;
        $total = 0;
        foreach ([
            'basic' => 'taxpayer',
            'disability_basic' => 'disability_basic',
            'disability_extended' => 'disability_extended',
            'ztp_p' => 'ztp_p',
        ] as $key => $kind) {
            $minor = $breakdown[$kind] ?? null;
            if ($minor === null) {
                continue;
            }
            $result[$key] = $this->wholeCzk(
                is_int($minor) ? $minor : null,
                '10299',
                'person',
                $employeeId,
                $blockers,
            );
            $total += is_int($minor) ? $minor : 0;
        }
        if ($total !== $claimed) {
            // Kdyby rozpad neseděl na úhrn, mlčky bychom vykázali jiné číslo,
            // než ze kterého se počítala záloha.
            $blockers[] = $this->blocker(
                'jmhz_scenario1_tax_credit_breakdown_unavailable',
                'person',
                $employeeId,
                ['10299', '10300', '10301', '10302'],
            );

            return $empty;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $tax
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array{base:?int,computed:?int,after_credits:?int,bonus:?int,taxable_income:?int}
     */
    private function advanceTaxCzk(
        array $tax,
        ?int $employeeId,
        array &$blockers,
    ): array {
        $advance = $tax['advance_tax'] ?? null;
        if (!is_array($advance) || array_is_list($advance)) {
            // Blocker se přidává i tady, přestože ho `inspectUnsupportedTax()`
            // pro tentýž stav hlásí taky. Spoléhat na pořadí volání by z toho
            // udělalo přesně tu implicitní podmínku, kterou tahle vrstva jinde
            // odstraňuje; duplicitu srovná `normalizeBlockers()`.
            $blockers[] = $this->blocker(
                'jmhz_scenario1_advance_tax_missing',
                'person',
                $employeeId,
                ['10297', '10298', '10305', '10306'],
            );

            return [
                'base' => null,
                'computed' => null,
                'after_credits' => null,
                'bonus' => null,
                'taxable_income' => null,
            ];
        }
        return [
            'base' => $this->advanceTaxField(
                $advance,
                'rounded_tax_base_minor_units',
                '10297',
                $employeeId,
                $blockers,
            ),
            'computed' => $this->advanceTaxField(
                $advance,
                'tax_before_credits_minor_units',
                '10298',
                $employeeId,
                $blockers,
            ),
            'after_credits' => $this->advanceTaxField(
                $advance,
                'tax_after_credits_minor_units',
                '10305',
                $employeeId,
                $blockers,
            ),
            'bonus' => $this->advanceTaxField(
                $advance,
                'tax_bonus_minor_units',
                '10306',
                $employeeId,
                $blockers,
            ),
            'taxable_income' => $this->advanceTaxField(
                $advance,
                'taxable_income_minor_units',
                '10535',
                $employeeId,
                $blockers,
            ),
        ];
    }

    /**
     * @param array<mixed> $advance
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function advanceTaxField(
        array $advance,
        string $field,
        string $attributeId,
        ?int $employeeId,
        array &$blockers,
    ): ?int {
        $minor = $advance[$field] ?? null;
        if (!is_int($minor) || $minor < 0) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_advance_tax_incomplete',
                'person',
                $employeeId,
                [$attributeId],
            );

            return null;
        }

        return $this->wholeCzk(
            $minor,
            $attributeId,
            'person',
            $employeeId,
            $blockers,
        );
    }

    /** @param list<JmhzScenario1Blocker> $blockers */
    private function taxpayerDeclaration(
        mixed $term,
        ?int $employeeId,
        array &$blockers,
    ): ?bool {
        $signed = $this->object($term)['tax_declaration_signed'] ?? null;
        if (!is_bool($signed)) {
            $blockers[] = $this->blocker(
                'jmhz_taxpayer_declaration_unresolved',
                'person',
                $employeeId,
                ['10419'],
            );

            return null;
        }

        return $signed;
    }

    /**
     * Souhrnná vrstva se skládá až z normalizovaných osob, aby úhrn nikdy
     * nevznikl z jiného zdroje než jednotlivé součásti. Chybí-li kterékoli
     * osobě zmrazená hodnota, zůstává úhrn `null` — nulou se nedoplňuje.
     *
     * @param list<array<string,mixed>> $people
     * @return array{advance_tax_after_credits:?int,tax_bonus:?int}
     */
    private function employerTaxTotals(array $people): array
    {
        $totals = ['advance_tax_after_credits' => 0, 'tax_bonus' => 0];
        foreach ($people as $person) {
            $advance = $this->object(
                $this->object($person['summary'] ?? null)['advance_tax_czk'] ?? null,
            );
            foreach ([
                'advance_tax_after_credits' => 'after_credits',
                'tax_bonus' => 'bonus',
            ] as $totalKey => $personKey) {
                if ($totals[$totalKey] === null) {
                    continue;
                }
                $value = $advance[$personKey] ?? null;
                $totals[$totalKey] = is_int($value)
                    ? $totals[$totalKey] + $value
                    : null;
            }
        }

        return $totals;
    }

    /**
     * @param array<string,mixed> $net
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function inspectDeductions(array $net, ?int $employeeId, array &$blockers): void
    {
        $deducted = $net['deducted_minor_units'] ?? null;
        $deductions = $net['deductions'] ?? null;
        if (!is_int($deducted)
            || !is_array($deductions)
            || !array_is_list($deductions)
        ) {
            return;
        }
        if ($deducted !== 0 || $deductions !== []) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_deductions_unsupported',
                'person',
                $employeeId,
                ['10116', '10350', '10351', '10352', '10353'],
            );
        }
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>|null
     */
    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @param-out list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>|null
     */
    private function employerAnnual(
        mixed $value,
        int $month,
        int $reportYear,
        ?int $officeId,
        int $revisionId,
        array &$blockers,
    ): ?array {
        if ($month !== 12) {
            return null;
        }
        $evidence = $this->object($value);
        $types = $evidence['collective_agreement_types'] ?? null;
        $validTypes = is_array($types)
            && array_is_list($types)
            && $types !== []
            && $types === array_values(array_unique($types, SORT_STRING))
            && array_filter(
                $types,
                static fn (mixed $type): bool => !is_string($type)
                    || !in_array($type, ['0', '1', '2', '3', '4', '5'], true),
            ) === []
            && (!in_array('0', $types, true) || $types === ['0']);
        if (!$validTypes
            || ($evidence['schema_reference'] ?? null)
                !== 'payroll-jmhz-employer-annual-evidence.v1'
            || ($evidence['report_year'] ?? null) !== $reportYear
        ) {
            $blockers[] = $this->blocker(
                'jmhz_december_collective_agreement_source_missing',
                'revision',
                $revisionId,
                ['10214'],
            );
        }
        $ownership = $evidence['ownership_form'] ?? null;
        if (!is_string($ownership)
            || !in_array($ownership, ['1', '2', '3', '4'], true)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_december_ownership_form_source_missing',
                'revision',
                $revisionId,
                ['10220'],
            );
        }
        $total = $evidence['average_headcount_hundredths'] ?? null;
        $disabled = $evidence['average_disabled_headcount_hundredths'] ?? null;
        $share = $evidence['disabled_share_hundredths'] ?? null;
        $expectedShare = is_int($total) && $total > 0 && is_int($disabled)
            ? intdiv(($disabled * 10_000) + intdiv($total, 2), $total)
            : null;
        $validOzp = is_int($total)
            && $total >= 0
            && is_int($disabled)
            && $disabled >= 0
            && $disabled <= $total
            && is_int($share)
            && $share === ($total === 0 ? 0 : $expectedShare)
            && $share >= 0
            && $share <= 10_000;
        if (!$validOzp) {
            $blockers[] = $this->blocker(
                'jmhz_december_ozp_annual_source_missing',
                'revision',
                $revisionId,
                ['10038', '10039', '10452'],
            );
        }
        if (!$validTypes
            || !is_string($ownership)
            || !in_array($ownership, ['1', '2', '3', '4'], true)
            || !$validOzp
        ) {
            return null;
        }
        $selectedOfficeId = $evidence['ozp_reporting_office_id'] ?? null;
        if ($selectedOfficeId !== null
            && (!is_int($selectedOfficeId) || $selectedOfficeId <= 0)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_december_ozp_annual_source_missing',
                'revision',
                $revisionId,
                ['10038', '10039', '10452'],
            );
            return null;
        }

        return [
            'source_id' => $evidence['id'] ?? null,
            'source_revision_no' => $evidence['revision_no'] ?? null,
            'report_year' => $reportYear,
            'ownership_form' => $ownership,
            'collective_agreement_types' => $types,
            'ozp' => $total > 2_500
                && ($selectedOfficeId === null || $selectedOfficeId === $officeId)
                    ? [
                        'average_headcount_hundredths' => $total,
                        'average_disabled_headcount_hundredths' => $disabled,
                        'disabled_share_hundredths' => $share,
                    ]
                    : null,
        ];
    }

    private function annualSummary(
        mixed $value,
        string $periodStart,
        ?int $employeeId,
        array &$blockers,
    ): ?array {
        $month = (int) substr($periodStart, 5, 2);
        if ($month < 1 || $month > 3) {
            return null;
        }
        $evidence = $this->object($value);
        $expectedTaxYear = (int) substr($periodStart, 0, 4) - 1;
        if (($evidence['tax_year'] ?? null) !== $expectedTaxYear) {
            $blockers[] = $this->blocker(
                'jmhz_annual_evidence_source_missing',
                'person',
                $employeeId,
                $month <= 2 ? ['10319', '10320'] : ['10320'],
            );
            return null;
        }

        $request = $this->object($evidence['request'] ?? null);
        $requestEvidence = $this->object($evidence['request_evidence'] ?? null);
        $requestLocked = $request !== []
            && ($requestEvidence['present'] ?? null) === true
            && ($requestEvidence['proof'] ?? null)
                === 'verified_request_row_under_unique_key_lock'
            && ($requestEvidence['tax_year'] ?? null) === $expectedTaxYear;
        $requestStatus = is_string($request['status'] ?? null)
            ? $request['status']
            : null;
        $requested = null;
        if ($month <= 2) {
            $requested = $requestLocked ? match ($requestStatus) {
                'requested' => true,
                'not_requested' => false,
                default => null,
            } : null;
            if ($requested === null) {
                $blockers[] = $this->blocker(
                    $request === []
                        ? 'jmhz_annual_request_source_missing'
                        : 'jmhz_annual_request_status_unresolved',
                    'person',
                    $employeeId,
                    ['10319'],
                );
            }
        }

        $settlement = $this->object($evidence['settlement'] ?? null);
        $settlementEvidence = $this->object($evidence['settlement_evidence'] ?? null);
        $frozenNotPerformed = $settlement === []
            && ($settlementEvidence['performed'] ?? null) === false
            && ($settlementEvidence['proof'] ?? null)
                === 'outcome_absent_under_unique_key_lock'
            && ($settlementEvidence['tax_year'] ?? null) === $expectedTaxYear;
        if ($settlement === [] && !$frozenNotPerformed) {
            $blockers[] = $this->blocker(
                'jmhz_annual_settlement_performance_source_missing',
                'person',
                $employeeId,
                ['10320'],
            );
            return null;
        }
        if ($settlement !== [] && $requestStatus === 'not_requested') {
            $blockers[] = $this->blocker(
                'jmhz_annual_settlement_source_inconsistent',
                'person',
                $employeeId,
                ['10319', '10320'],
            );
            return null;
        }

        $performed = $settlement !== []
            && ($settlement['performed'] ?? null) === true
            && is_string($settlement['settled_on'] ?? null)
            && substr($settlement['settled_on'], 0, 7) === substr($periodStart, 0, 7);
        $result = null;
        if ($performed) {
            if (!$requestLocked
                || $requestStatus !== 'requested'
                || ($request['annual_claims'] ?? null) !== 'none'
            ) {
                $blockers[] = $this->blocker(
                    'jmhz_annual_settlement_request_source_inconsistent',
                    'person',
                    $employeeId,
                    ['10319', '10320', '10420'],
                );
            }
            $childRows = $this->rows($settlement['child_rows'] ?? null);
            $childClaimed = $childRows !== [];
            $children = $childClaimed
                ? $this->annualChildren($childRows)
                : null;
            if ($childClaimed && $children === null) {
                $blockers[] = $this->blocker(
                    'jmhz_annual_settlement_child_details_unsupported',
                    'person',
                    $employeeId,
                    ['10441', '10442', '10443', '10444', '10445', '10446',
                        '10447', '10448', '10449', '10450', '10451', '10454', '10455'],
                );
            }
            $taxDifference = is_int($settlement['tax_difference_minor_units'] ?? null)
                ? $settlement['tax_difference_minor_units']
                : null;
            $bonusDifference = is_int($settlement['bonus_difference_minor_units'] ?? null)
                ? $settlement['bonus_difference_minor_units']
                : null;
            $result = [
                'settlement_difference_czk' => $this->wholeCzk(
                    $taxDifference === null || $bonusDifference === null
                        ? null
                        : max(0, $taxDifference) + $bonusDifference,
                    '10321', 'person', $employeeId, $blockers,
                ),
                'tax_difference_czk' => $this->wholeCzk(
                    $taxDifference === null ? null : max(0, $taxDifference),
                    '10322', 'person', $employeeId, $blockers,
                ),
                'bonus_difference_czk' => $this->wholeCzk(
                    $bonusDifference,
                    '10323', 'person', $employeeId, $blockers,
                ),
                'spouse_credit_claimed' => $requestLocked
                    && $requestStatus === 'requested'
                    && ($request['annual_claims'] ?? null) === 'none'
                        ? false
                        : null,
                'child_credit_claimed' => $childClaimed,
            ];
            if ($childClaimed) {
                $result['child_credit_details'] = $children;
            }
            if (in_array(null, $result, true)) {
                $blockers[] = $this->blocker(
                    'jmhz_annual_settlement_result_incomplete',
                    'person',
                    $employeeId,
                    ['10321', '10322', '10323', '10420', '10454'],
                );
            }
        }

        $certificate = $this->object($evidence['withholding_certificate'] ?? null);
        $withholding = null;
        if ($month === 1 && $certificate !== []) {
            $withholding = [
                'paid_income_czk' => $this->wholeCzk(
                    is_int($certificate['paid_income_minor_units'] ?? null)
                        ? $certificate['paid_income_minor_units']
                        : null,
                    '10311', 'person', $employeeId, $blockers,
                ),
                'withholding_tax_czk' => $this->wholeCzk(
                    is_int($certificate['withholding_tax_minor_units'] ?? null)
                        ? $certificate['withholding_tax_minor_units']
                        : null,
                    '10312', 'person', $employeeId, $blockers,
                ),
            ];
            if (in_array(null, $withholding, true)) {
                $withholding = null;
            }
        }

        return [
            'requested' => $requested,
            'performed' => $performed,
            'result' => $result,
            'withholding' => $withholding,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function annualChildren(array $rows): ?array
    {
        $children = [];
        $caregiver = null;
        foreach ($rows as $row) {
            $reference = $row['child_reference'] ?? null;
            $givenName = $row['given_name'] ?? null;
            $familyName = $row['family_name'] ?? null;
            $birthDate = $row['birth_date'] ?? null;
            $birthNumber = $row['birth_number'] ?? null;
            $ztpMask = $row['ztp_p_months_mask'] ?? null;
            $orderMask = $row['order_months_mask'] ?? null;
            $rowCaregiver = $row['other_household_caregiver'] ?? null;
            if (!is_string($reference) || trim($reference) === ''
                || !is_string($givenName) || trim($givenName) === ''
                || mb_strlen($givenName) > 100
                || !is_string($familyName) || trim($familyName) === ''
                || mb_strlen($familyName) > 100
                || ($birthDate !== null
                    && (!is_string($birthDate)
                        || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birthDate) !== 1))
                || ($birthNumber !== null
                    && (!is_string($birthNumber)
                        || preg_match('/^\d{9,10}$/D', $birthNumber) !== 1))
                || ($birthDate === null && $birthNumber === null)
                || !is_string($ztpMask)
                || preg_match('/^(A|N){12}$/D', $ztpMask) !== 1
                || !is_string($orderMask)
                || preg_match('/^([1-3]|N){12}$/D', $orderMask) !== 1
                || !is_bool($rowCaregiver)
                || ($caregiver !== null && $caregiver !== $rowCaregiver)
            ) {
                return null;
            }
            $caregiver = $rowCaregiver;
            $children[] = [
                'reference' => $reference,
                'identity' => [
                    'given_name' => trim($givenName),
                    'family_name' => trim($familyName),
                    'birth_date' => $birthDate,
                    'birth_number' => $birthNumber,
                ],
                'ztp_p_months_mask' => $ztpMask,
                'order_months_mask' => $orderMask,
            ];
        }
        $otherCaregivers = $this->rows($rows[0]['other_household_caregivers'] ?? null);
        if ($caregiver === true && $otherCaregivers === []) {
            return null;
        }
        if ($caregiver === false && $otherCaregivers !== []) {
            return null;
        }

        return [
            'other_household_caregiver' => $caregiver ?? false,
            'other_household_caregivers' => $otherCaregivers,
            'children' => $children,
        ];
    }

    /** @param list<JmhzScenario1Blocker> $blockers */
    private function wholeCzk(
        ?int $minor,
        string $attributeId,
        string $entityType,
        ?int $entityId,
        array &$blockers,
    ): ?int {
        if ($minor === null) {
            return null;
        }
        if ($minor % 100 !== 0) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_whole_czk_required',
                $entityType,
                $entityId,
                [$attributeId],
            );
            return null;
        }
        return intdiv($minor, 100);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $path
     */
    private function nestedInt(array $value, array $path): ?int
    {
        $current = $value;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return is_int($current) ? $current : null;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /**
     * @param array<string,mixed> $social
     * @param array<string,mixed> $payslip
     */
    private function employerSocialMinor(array $social, array $payslip): ?int
    {
        $legacy = $social['employer_contribution_minor_units'] ?? null;
        if (is_int($legacy)) {
            return $legacy;
        }
        $allocated = $payslip['employer_social_minor_units'] ?? null;

        return is_int($allocated) ? $allocated : null;
    }

    /** @return array<int|string,int> */
    private function earnings(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $attributeId => $minor) {
            if (!is_int($minor)) {
                continue;
            }
            $result[(string) $attributeId] = $minor;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        return array_values(array_filter(
            $value,
            static fn (mixed $row): bool => is_array($row) && !array_is_list($row),
        ));
    }

    /** @param list<string> $attributeIds */
    private function blocker(
        string $code,
        string $entityType,
        ?int $entityId,
        array $attributeIds = [],
    ): JmhzScenario1Blocker {
        sort($attributeIds, SORT_STRING);
        return new JmhzScenario1Blocker(
            $code,
            $entityType,
            $entityId,
            $attributeIds,
        );
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @return list<JmhzScenario1Blocker>
     */
    private function normalizeBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $key = $blocker->code . '|' . $blocker->entityType . '|'
                . ($blocker->entityId ?? '') . '|'
                . implode(',', $blocker->attributeIds);
            $unique[$key] = $blocker;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }
}
