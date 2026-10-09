<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Absence\PayrollSicknessInputMaterializer;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
use MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol;
use MyInvoice\Service\Tax\TaxConstants;

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
     * SSOT pro „záporný příjem se do JMHZ hlásí nulou".
     *
     * ČSSZ (potvrzeno v úřední diskuzi k JMHZ): čistý příjem (10344) NIKDY
     * nesmí být záporný — vždy 0. Šířeji (sdělení z jednání ČSSZ s výrobci
     * mzdového SW, nepotvrzené písemně): u příjmových atributů 10328/10329/
     * /10330/10331 (mzda a její rozpad) a 10286 (úhrn příjmů) se do
     * 31. 12. 2026 místo záporné hodnoty vykazuje nula — hlášení se kvůli
     * přeplatku dovolené nebo doplatku po celoměsíční nemoci nesmí zablokovat.
     * Interní evidence (mzdový běh, přehled) drží dál skutečnou zápornou
     * hodnotu; do JMHZ jde jen tahle nula.
     *
     * Hranice je záměrně úzká — je i JINÉ atributy, kde je znaménko
     * legitimní (viz {@see JmhzScenario1XmlSerializer::signedInt()}, např.
     * 10323), a plošné „záporné číslo = nula" by je tiše rozbilo.
     *
     * TODO (od 1. 1. 2027): ČSSZ má podle stejného sdělení záporné hodnoty
     * u těchto atributů povolit. Bez písemného potvrzení termínu klamp
     * NEPŘEPÍNAT podle data — až přijde, ořezání tady zrušit.
     *
     * @var list<string>
     */
    /**
     * Příspěvek zaměstnavatele na produkty spoření na stáří (úhrn 10417
     * a rozpad 10418, 10292-10296) v pořadí sekvence XSD.
     *
     * Souhrnná data zaměstnance se vykazují jednou za osobu, takže se tyhle
     * atributy sčítají přes všechny její pracovněprávní vztahy.
     *
     * @var list<string>
     */
    private const EMPLOYER_CONTRIBUTION_ATTRIBUTES = [
        '10417', '10418', '10292', '10293', '10294', '10295', '10296',
    ];

    private ?JmhzControlParameterCatalog $controlParameters = null;

    private ?JmhzScenario1XmlSerializer $probeSerializer = null;

    private const NEGATIVE_INCOME_REPORTED_AS_ZERO = [
        '10286',
        '10328',
        '10329',
        '10330',
        '10331',
        // Náhrady mzdy celkem a za dovolenou: vrácená náhrada za dovolenou
        // (§ 147 odst. 1 písm. e) ZP) v měsíci bez čerpání dá záporný součet,
        // datový slovník ani XSD (celé nezáporné číslo) ho nepřipouští.
        '10337',
        '10338',
        '10344',
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
     * @param array<int,string> $testVariableSymbols testovací VS účtáren podle
     *        id — jen pro testovací prostředí ČSSZ, jinak prázdné
     */
    public function resolve(
        JmhzVerifiedPreparationSnapshot $preparation,
        ?JmhzPvpojPreview $pvpoj,
        ?string $pvpojFailureCode = null,
        ?int $officeId = null,
        array $testVariableSymbols = [],
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
                || array_diff($scenarioSet, JmhzScenarioFormProfile::ordinaryDocumentScenarios()) !== []
            ) {
                return new JmhzScenario1Resolution(null, [
                    $this->blocker(
                        'jmhz_scenario1_scope_unsupported',
                        'preparation',
                        $preparation->id,
                    ),
                ]);
            }
            // Odložený příjem (scénář 8) i formuláře vězně, jiného příjmu
            // a pronájmu síly (4 až 6) jsou jen jiné druhy formuláře téhož
            // řádného podání, dokument zůstává běžného profilu. Formulář
            // vybírá selektor každé součásti, ne rozsah dokumentu.
            $scope['scenario_key'] = $scenarioSet === ['scenario_3']
                ? 'scenario_3'
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
            /*
             * „Zdroje hlášení nejsou úplné" je SOUHRN nad konkrétními nálezy,
             * ne úkol. Dokud se přidával i vedle nich, stál v seznamu kroků
             * jako čtvrtý řádek, na kterém účetní nemá co udělat — a tlačítko
             * u něj vedlo do mzdového běhu, kde příčina není. Počítal se přitom
             * do „N kroků k doplnění", takže seznam sliboval o práci navíc.
             *
             * Zůstává jen tehdy, když konkrétní nález chybí: to je jediný stav,
             * kdy je souhrn to nejpřesnější, co umíme říct, a zamlčet ho by
             * znamenalo tvrdit, že hlášení jde sestavit.
             */
            if ($scopedReadinessIssues === []) {
                $blockers[] = $this->blocker(
                    'jmhz_preparation_not_ready',
                    'preparation',
                    $preparation->id,
                );
            }
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
        // Formulář osoby vzniká za každý pracovněprávní vztah, ne za osobu.
        // Nad 1500 formulářů se hlášení nedělá chybou dokumentu: serializér ho
        // rozdělí do dílčích balíků (JmhzScenario1XmlSerializer::serializePackages)
        // a test ho celé ověří. Zmrazení k odeslání dílčí balíky zatím nepodporuje
        // a řekne to samo (JmhzSubmissionBridgeService).
        $formCount = 0;
        foreach ($people as $person) {
            $formCount += count($this->rows($person['employments'] ?? null));
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
            /*
             * Ordinary evidence se zmrazuje per pracovní vztah a úplná musí být
             * u KAŽDÉHO vztahu osoby, protože každý vztah je samostatný
             * formulář. Příznak srážek 10116 ale patří do souhrnných dat
             * zaměstnance, která nese jen formulář primárního vztahu, takže se
             * čte z evidence právě toho vztahu. Odvozuje se za osobu
             * (JmhzOrdinaryEvidenceBuilder::resolveWageDeductionsRecorded()),
             * všechny vztahy téže osoby ho proto nesou stejný.
             */
            $personEvidence = [];
            foreach ($employments as $employmentRow) {
                $employmentKey = $employmentRow['employment_id'] ?? null;
                $evidence = is_int($employmentKey)
                    ? ($ordinaryEvidence[$employmentKey] ?? null)
                    : null;
                if ($evidence === null) {
                    $ordinaryEvidenceComplete = false;
                    continue;
                }
                if ($personEvidence === []
                    || ($this->object($employmentRow['employment'] ?? null)['is_primary'] ?? null) === true
                ) {
                    $personEvidence = $evidence;
                }
            }
            if ($employments === []) {
                $ordinaryEvidenceComplete = false;
            } else {
                $this->inspectPrimaryEmployment($employments, $employeeId, $blockers);
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
            $withholdingTaxCzk = $this->withholdingTaxCzk($tax, $employeeId, $blockers);
            $taxCreditsCzk = $this->taxCreditsCzk($tax, $employeeId, $blockers);
            $taxableIncomeCzk = $this->relationshipTaxableIncomeCzk(
                $tax,
                $employments,
                $advanceTaxCzk['taxable_income'] === null || ($withholdingTaxCzk !== null && $withholdingTaxCzk['base'] === null)
                    ? null
                    : $advanceTaxCzk['taxable_income'] + ($withholdingTaxCzk['base'] ?? 0),
                $employeeId,
                $blockers,
            );
            $relationshipContributions = $this->relationshipContributions(
                $employments,
                $social,
                $employeeId,
                $blockers,
            );
            $socialEmploymentId = $relationshipContributions === null
                ? $this->socialContributionEmployment(
                    $employments,
                    $social,
                    $payslip,
                    $employeeId,
                    $blockers,
                )
                : null;
            $relationshipEmployerSocialCzk = null;
            $declarationSigned = null;

            $normalizedEmployments = [];
            // `null` dokud některý vztah úhrn nenese: zmrazená příprava starší
            // než odvozování osvobozených příjmů ho nemá a element se vynechá.
            $exemptIncomeMinor = null;
            $employerContributions = [];
            foreach ($employments as $employment) {
                if (is_int($employment['exempt_income_minor'] ?? null)) {
                    $exemptIncomeMinor = ($exemptIncomeMinor ?? 0)
                        + $employment['exempt_income_minor'];
                }
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                $employmentSource = $this->object($employment['employment'] ?? null);
                $selector = $this->object($employment['scenario_resolution'] ?? null);
                $scenarioKey = $selector['scenario_key'] ?? null;
                $relationType = $employmentSource['relation_type'] ?? null;
                if (!is_string($scenarioKey)
                    || !in_array($scenarioKey, $scenarioSet, true)
                    || ($scenarioKey !== 'scenario_8'
                        && !JmhzScenarioFormProfile::preparable(
                            $scenarioKey,
                            is_string($selector['activity_code'] ?? null) ? $selector['activity_code'] : null,
                            is_string($selector['relationship_detail_code'] ?? null)
                                ? $selector['relationship_detail_code']
                                : null,
                            is_string($relationType) ? $relationType : '',
                        ))
                ) {
                    $blockers[] = $this->blocker(
                        'jmhz_scenario_profile_unsupported',
                        'employment',
                        $employmentId,
                        ['10239', '10502'],
                    );
                }
                if (($employmentSource['is_primary'] ?? null) === true) {
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
                /*
                 * Příspěvek zaměstnavatele na produkty spoření na stáří sedí
                 * v XSD pod `souhrnDataZec`, tedy JEDNOU ZA OSOBU, kdežto
                 * vektor výdělků je po vztazích. Sčítá se proto přes všechny
                 * vztahy téže osoby stejně jako zúčtovaný příjem — jinak by
                 * zaměstnanec se dvěma souběžnými vztahy měl příspěvek
                 * v hlášení dvakrát, nebo naopak jen z jednoho vztahu.
                 *
                 * Úhrn 10417 je ve vektoru už dopočítaný rollupem topologie
                 * cílových atributů, takže se jen opisuje.
                 */
                foreach (self::EMPLOYER_CONTRIBUTION_ATTRIBUTES as $attributeId) {
                    if (!array_key_exists($attributeId, $earningsCzk)) {
                        continue;
                    }
                    $employerContributions[$attributeId] =
                        ($employerContributions[$attributeId] ?? 0)
                        + $earningsCzk[$attributeId];
                }
                $identity = $this->object($employment['identity'] ?? null);
                $personIdentifier = $this->object(
                    $identity['person_external_identifier'] ?? null,
                );
                $employmentIdentifier = $this->object(
                    $identity['jmhz_employment_external_identifier'] ?? null,
                );
                // Jmenná větev `identifikaceType`: uplatní se, dokud ČSSZ
                // nepřidělila OIČ a ID PPV. Historie identity osoby je v
                // zmrazeném snímku pod `identity.identity`, den nástupu na
                // zdrojovém řádku vztahu — skutečný má přednost před
                // sjednaným stejně jako v evidenci důchodového pojištění
                // (viz JmhzEldpEvidenceBuilder).
                $personFacts = $this->object($identity['identity'] ?? null);
                $average = $this->object($employment['average_earning'] ?? null);
                $socialBase = $this->socialBase(
                    $employment['insurance'] ?? null,
                    $employmentId,
                    $blockers,
                );
                /*
                 * Výsledek s pojistným po vztazích: každý účastný vztah vykazuje
                 * své pojistné (10370, 10481) na svém formuláři, viz
                 * relationshipContributions(). Starší výsledek pojistné po
                 * vztazích nenese a pojistné osoby pak nese nejvýš jeden
                 * formulář, viz socialContributionEmployment().
                 */
                $ownContribution = $relationshipContributions !== null && is_int($employmentId)
                    ? ($relationshipContributions[$employmentId] ?? null)
                    : null;
                $reportsSocial = count($employments) === 1
                    || ($ownContribution !== null && $ownContribution['participates'])
                    || ($relationshipContributions === null
                        && $employmentId !== null
                        && $employmentId === $socialEmploymentId);
                $socialContributions = null;
                if ($ownContribution !== null && $reportsSocial) {
                    $employerSocial = $this->relationshipEmployerSocialCzk(
                        $socialBase,
                        $preparation->periodStart,
                    );
                    $socialContributions = [
                        'employee_social_czk' => $this->wholeCzk(
                            $ownContribution['before_minor'],
                            '10370',
                            'employment',
                            $employmentId,
                            $blockers,
                        ),
                        'employer_social_czk' => $employerSocial,
                    ];
                    if (is_int($employerSocial)) {
                        $relationshipEmployerSocialCzk = ($relationshipEmployerSocialCzk ?? 0)
                            + $employerSocial;
                    }
                }
                $normalizedEmployments[] = [
                    'employment_id' => $employmentId,
                    'social_base' => $socialBase,
                    'social_contributions' => $socialContributions,
                    'part_time_discount' => $this->partTimeDiscount(
                        $employment['insurance'] ?? null,
                        $employment['scenario_resolution'] ?? null,
                        $employmentId,
                        $blockers,
                        count(array_filter(
                            $employments,
                            fn (mixed $row): bool => is_array($row)
                                && ($this->object($row['insurance'] ?? null)['kind'] ?? null) === 'employment',
                        )),
                    ),
                    'primary' => $employmentSource['is_primary'] ?? null,
                    // 10535 za TENTO vztah; viz relationshipTaxableIncomeCzk().
                    'taxable_income_czk' => is_int($employmentId)
                        ? ($taxableIncomeCzk[$employmentId] ?? null)
                        : null,
                    'reports_social_contributions' => $reportsSocial,
                    'employee_social_discount' => $this->employeeSocialDiscount(
                        $social,
                        $employment,
                        $reportsSocial,
                        is_int($employmentId) ? ($ordinaryEvidence[$employmentId] ?? null) : null,
                        $employmentId,
                        $blockers,
                        $ownContribution === null ? null : $ownContribution['discount_minor'],
                    ),
                    'identity' => [
                        'person_external_identifier' => $personIdentifier['value'] ?? null,
                        'employment_external_identifier' => $employmentIdentifier['value'] ?? null,
                        'family_name' => $personFacts['last_name'] ?? null,
                        'given_name' => $personFacts['first_name'] ?? null,
                        'birth_date' => $personFacts['birth_date'] ?? null,
                        'employment_start_date' =>
                            $employmentSource['actual_start_date']
                                ?? $employmentSource['start_date']
                                ?? null,
                    ],
                    'selector' => $employment['scenario_resolution'] ?? null,
                    'term' => $employment['term'] ?? null,
                    'risk_work' => $this->riskWork(
                        $employment['term'] ?? null,
                        $employmentId,
                        $blockers,
                    ),
                    // Doklad, že se nevyplněné „ano/ne" vyložilo jako „ne".
                    // Bez něj serializér nic nedomýšlí (viz
                    // JmhzScenario1XmlSerializer::tristate()).
                    'jmhz_default_interpretations' =>
                        $employment['jmhz_default_interpretations'] ?? null,
                    'work_month' => $employment['work_month'] ?? null,
                    'eldp' => $employment['eldp'] ?? null,
                    // Nulový formulář vztahu bez příjmu: 10345 = 0 podle
                    // JmhzZeroReportProfile (Pravidla podání 1.4.5, kap. 4).
                    // Příprava v takovém měsíci průměr nezmrazí.
                    'average_hourly' => [
                        'minor_units' => $average === []
                            && JmhzZeroReportProfile::isMonthWithoutIncome(
                                $earnings,
                                is_int($employment['exempt_income_minor'] ?? null)
                                    ? $employment['exempt_income_minor']
                                    : 0,
                            )
                            ? 0
                            : ($average['average_hourly_minor'] ?? null),
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
            $this->inspectWithholdingAgainstThresholds(
                $normalizedEmployments,
                $withholdingTaxCzk,
                $preparation->periodStart,
                $employeeId,
                $blockers,
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
                    /*
                     * Osvobozené příjmy ze zúčtovaných příjmů (10289) za OSOBU.
                     * Souhrnná data zaměstnance se vykazují jednou za osobu na
                     * primárním vztahu, takže se sčítají přes všechny vztahy
                     * téže osoby stejně jako zúčtovaný příjem.
                     *
                     * `null` znamená NEUVEDENO: příprava zmrazená dřív, než se
                     * úhrn odvozoval, ho nenese a element se nezapíše.
                     */
                    // Prázdné pole = zaměstnavatel na penzijní produkty
                    // nepřispívá nebo složku nemá zavedenou; blok se pak
                    // nevykazuje vůbec.
                    'employer_contributions_czk' => $employerContributions,
                    'exempt_income_czk' => $exemptIncomeMinor === null
                        ? null
                        : $this->wholeCzk(
                            $exemptIncomeMinor,
                            '10289',
                            'person',
                            $employeeId,
                            $blockers,
                        ),
                    'net_income_czk' => $this->wholeCzk(
                        $this->netIncomeMinor($tax, $social, $health, $net, $employments),
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
                        $this->employeeSocialBeforeDiscountMinor(
                            $social,
                            $employeeId,
                            $blockers,
                        ),
                        '10370',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    // S pojistným po vztazích nese 10481 každý formulář sám;
                    // tady je jen úhrn osoby pro přehled.
                    'employer_social_czk' => $relationshipContributions !== null
                        ? ($relationshipEmployerSocialCzk ?? 0)
                        : $this->employerSocialCzk(
                            $social,
                            $payslip,
                            $normalizedEmployments,
                            $preparation->periodStart,
                            $employeeId,
                            $blockers,
                        ),
                    'deductions_recorded' => $personEvidence === []
                        ? null
                        : ($personEvidence['attribute_values']['10116'] ?? null),
                    'taxpayer_declaration_signed' => $declarationSigned,
                    'advance_tax_czk' => $advanceTaxCzk,
                    'withholding_tax_czk' => $withholdingTaxCzk,
                    'tax_credits_czk' => $taxCreditsCzk,
                    'child_credit' => $this->childCredit(
                        $person['child_credit_evidence'] ?? null,
                        $tax,
                        $declarationSigned,
                        $employeeId,
                        $blockers,
                    ),
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
        /*
         * Testovací prostředí ČSSZ má vlastní přidělený VS (Nastavení mezd →
         * účtárna), jiný než ostrý. Obálka ho do testu posílá, a protože se
         * s hlavičkou musí shodovat, patří i sem. Bez vyplněného testovacího
         * VS zůstává VS registrace; produkce testovací VS nikdy nedostane.
         */
        if ($variableSymbol !== null && $registration['id'] !== null) {
            $variableSymbol = CsszEmployerVariableSymbol::preferTest(
                $variableSymbol,
                $testVariableSymbols[$registration['id']] ?? null,
            );
            // EDV ID 10221 (C_COKR + Luhn) jako u registrací: ČSSZ by hlášení
            // s takovým symbolem odmítla, takže se dokument nesestaví.
            if (CsszEmployerVariableSymbol::invalidReason($variableSymbol) !== null) {
                $blockers[] = $this->blocker(
                    'jmhz_office_variable_symbol_invalid',
                    'office',
                    $registration['id'],
                    ['10221'],
                );
            }
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
                'individual_form_count' => $formCount,
                'total_form_count' => $formCount + 2,
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
     * Rozhodnutí pro podání: {@see self::resolve()} a k tomu vady formulářů,
     * které pozná až serializér, jako nálezy na konkrétním vztahu.
     *
     * Serializér dřív tyhle vady (přesčas nad odpracovanými hodinami, bonus
     * bez prohlášení, rozpad mzdy bez mzdy …) hlásil výjimkou až při sestavení
     * XML. Hlášení tím spadlo jako celek a účetní nevěděla, u koho - vztah
     * nešlo odložit ani se na něj prokliknout.
     *
     * @param array<int,string> $testVariableSymbols
     */
    public function resolveForSubmission(
        JmhzVerifiedPreparationSnapshot $preparation,
        ?JmhzPvpojPreview $pvpoj,
        ?string $pvpojFailureCode = null,
        ?int $officeId = null,
        array $testVariableSymbols = [],
    ): JmhzScenario1Resolution {
        return $this->withFormProbe($this->resolve(
            $preparation,
            $pvpoj,
            $pvpojFailureCode,
            $officeId,
            $testVariableSymbols,
        ));
    }

    private function withFormProbe(JmhzScenario1Resolution $resolution): JmhzScenario1Resolution
    {
        if ($resolution->candidate === null) {
            return $resolution;
        }
        $found = $this->formProbeBlockers($resolution->candidate, $resolution->blockers);
        if ($found === []) {
            return $resolution;
        }

        return new JmhzScenario1Resolution(
            $resolution->candidate,
            $this->normalizeBlockers([...$resolution->blockers, ...$found]),
            $resolution->excludedBlockers,
            $resolution->exclusion,
        );
    }

    /**
     * Hlášení bez formulářů vynechaných vztahů.
     *
     * Formulář vynechaného vztahu se nepodává, pojistná část a souhrn ale
     * zůstávají za všechny zaměstnance (viz {@see JmhzFormExclusion}). Proto:
     *
     *  - přehled o výši pojistného (PVPOJ) je dál celý podíl účtárny,
     *  - souhrn daní zahrne i vynechané osoby, pokud mají zálohu na daň
     *    spočtenou; kde spočtená není, zůstanou mimo a eviduje se to
     *    v `provenance.form_exclusion.summary_excluded_employee_ids`,
     *  - nálezy vynechaných vztahů sestavení neblokují, ale vrací se
     *    v `excludedBlockers`, aby je UI ukázalo jako nesplněnou povinnost.
     *
     * Vynechává se vždy CELÁ osoba v rámci registrace. Pojistné osoby
     * (10370, 10481) i souhrnná data zaměstnance nese jediný formulář;
     * vynechat jeden ze souběžných vztahů by změnilo, co vykazují ostatní
     * formuláře téže osoby. Odložení to hlídá už při založení, tady je to
     * pojistka (`jmhz_deferral_concurrent_incomplete`); výběr obsahové opravy
     * se na celou osobu rozšíří sám.
     *
     * @param array<int,string> $testVariableSymbols
     */
    public function resolveExcluding(
        JmhzVerifiedPreparationSnapshot $preparation,
        ?JmhzPvpojPreview $pvpoj,
        ?string $pvpojFailureCode,
        ?int $officeId,
        array $testVariableSymbols,
        JmhzFormExclusion $exclusion,
    ): JmhzScenario1Resolution {
        $full = $this->resolveForSubmission(
            $preparation,
            $pvpoj,
            $pvpojFailureCode,
            $officeId,
            $testVariableSymbols,
        );
        if ($full->candidate === null || $exclusion->isEmpty()) {
            return $full;
        }

        $officePeople = $this->officePeople(
            $this->rows($preparation->payload['people'] ?? null),
            $officeId,
        );
        /** @var array<int,list<int>> $employmentsByEmployee */
        $employmentsByEmployee = [];
        $officeEmploymentIds = [];
        foreach ($officePeople as $person) {
            $employeeId = $person['employee_id'] ?? null;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $employmentId = $employment['employment_id'] ?? null;
                if (!is_int($employmentId)) {
                    continue;
                }
                $officeEmploymentIds[$employmentId] = true;
                if (is_int($employeeId)) {
                    $employmentsByEmployee[$employeeId][] = $employmentId;
                }
            }
        }
        $excluded = [];
        foreach ($exclusion->employmentIds as $employmentId) {
            if (isset($officeEmploymentIds[$employmentId])) {
                $excluded[$employmentId] = true;
            }
        }
        if ($excluded === []) {
            return $full;
        }

        $guards = [];
        $excludedEmployees = [];
        foreach ($employmentsByEmployee as $employeeId => $employmentIds) {
            $touched = array_filter(
                $employmentIds,
                static fn (int $id): bool => isset($excluded[$id]),
            );
            if ($touched === []) {
                continue;
            }
            if (count($touched) !== count($employmentIds)) {
                if ($exclusion->purpose === JmhzFormExclusion::PURPOSE_CORRECTION_SCOPE) {
                    foreach ($employmentIds as $employmentId) {
                        $excluded[$employmentId] = true;
                    }
                } else {
                    $guards[] = $this->blocker(
                        'jmhz_deferral_concurrent_incomplete',
                        'person',
                        $employeeId,
                        ['10370', '10481', '10495'],
                    );
                }
            }
            $excludedEmployees[$employeeId] = true;
        }
        if (count($excluded) >= count($officeEmploymentIds)) {
            $guards[] = $this->blocker(
                'jmhz_deferral_no_form_left',
                'revision',
                $preparation->sourceRevisionId,
                ['10015', '10488'],
            );
        }

        $partial = $this->resolveForSubmission(
            $this->withoutEmployments($preparation, $excluded, $excludedEmployees),
            $pvpoj,
            $pvpojFailureCode,
            $officeId,
            $testVariableSymbols,
        );
        if ($partial->candidate === null) {
            return $partial;
        }

        $excludedBlockers = array_values(array_filter(
            $full->blockers,
            static fn (JmhzScenario1Blocker $blocker): bool
                => $blocker->entityId !== null
                && (($blocker->entityType === 'employment'
                        && isset($excluded[$blocker->entityId]))
                    || (in_array($blocker->entityType, ['person', 'employee'], true)
                        && isset($excludedEmployees[$blocker->entityId]))),
        ));

        $payload = $partial->candidate->payload;
        $keptPeople = $this->rows($payload['people'] ?? null);
        $summaryPeople = $keptPeople;
        $summaryExcluded = [];
        foreach ($this->rows($full->candidate->payload['people'] ?? null) as $person) {
            $employeeId = $person['employee_id'] ?? null;
            if (!is_int($employeeId) || !isset($excludedEmployees[$employeeId])) {
                continue;
            }
            $advance = $this->object(
                $this->object($person['summary'] ?? null)['advance_tax_czk'] ?? null,
            );
            if (is_int($advance['after_credits'] ?? null) && is_int($advance['bonus'] ?? null)) {
                $summaryPeople[] = $person;
            } else {
                $summaryExcluded[] = $employeeId;
            }
        }
        if ($summaryExcluded !== []
            && $exclusion->purpose === JmhzFormExclusion::PURPOSE_CORRECTION_SCOPE
        ) {
            foreach ($summaryExcluded as $employeeId) {
                $guards[] = $this->blocker(
                    'jmhz_excluded_summary_unavailable',
                    'person',
                    $employeeId,
                    ['10034', '10035'],
                );
            }
        }
        $employer = $this->object($payload['employer'] ?? null);
        $employer['summary_totals'] = $this->employerTaxTotals($summaryPeople);
        $payload['employer'] = $employer;
        $excludedIds = array_keys($excluded);
        sort($excludedIds, SORT_NUMERIC);
        $excludedEmployeeIds = array_keys($excludedEmployees);
        sort($excludedEmployeeIds, SORT_NUMERIC);
        sort($summaryExcluded, SORT_NUMERIC);
        $provenance = $this->object($payload['provenance'] ?? null);
        $provenance['form_exclusion'] = [
            'purpose' => $exclusion->purpose,
            'employment_ids' => $excludedIds,
            'employee_ids' => $excludedEmployeeIds,
            'deferral_ids' => $exclusion->deferralIds,
            'summary_excluded_employee_ids' => $summaryExcluded,
        ];
        $payload['provenance'] = $provenance;

        return new JmhzScenario1Resolution(
            new JmhzScenario1NormalizedDocument($payload),
            $this->normalizeBlockers([...$partial->blockers, ...$guards]),
            $this->normalizeBlockers($excludedBlockers),
            $exclusion,
        );
    }

    /**
     * Příprava bez vynechaných vztahů - vstup pro sestavení zbytku hlášení.
     *
     * Nálezy připravenosti vynechaných vztahů a osob odejdou s nimi. Když tím
     * zmizí všechny, příprava je pro zbytek hlášení úplná; jinak by resolver
     * přidal souhrnný nález „zdroje nejsou úplné", na kterém už nic není.
     *
     * @param array<int,true> $excluded
     * @param array<int,true> $excludedEmployees
     */
    private function withoutEmployments(
        JmhzVerifiedPreparationSnapshot $preparation,
        array $excluded,
        array $excludedEmployees,
    ): JmhzVerifiedPreparationSnapshot {
        $payload = $preparation->payload;
        $people = [];
        foreach ($this->rows($payload['people'] ?? null) as $person) {
            $employments = array_values(array_filter(
                $this->rows($person['employments'] ?? null),
                static fn (array $employment): bool
                    => !is_int($employment['employment_id'] ?? null)
                    || !isset($excluded[$employment['employment_id']]),
            ));
            if ($employments === []) {
                continue;
            }
            $person['employments'] = $employments;
            $people[] = $person;
        }
        $payload['people'] = $people;
        $issues = $this->rows($payload['readiness_issues'] ?? null);
        $remaining = array_values(array_filter(
            $issues,
            static function (array $issue) use ($excluded, $excludedEmployees): bool {
                $entityType = $issue['entity_type'] ?? null;
                $entityId = $issue['entity_id'] ?? null;
                if (!is_int($entityId)) {
                    return true;
                }

                return match ($entityType) {
                    'employment' => !isset($excluded[$entityId]),
                    'person', 'employee' => !isset($excludedEmployees[$entityId]),
                    default => true,
                };
            },
        ));
        $payload['readiness_issues'] = $remaining;
        $readiness = $preparation->readiness;
        if ($issues !== [] && $remaining === []) {
            $readiness['status'] = 'source_ready';
        }

        return new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            $preparation->builderVersion,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $readiness,
            $payload,
        );
    }

    /**
     * Vady formuláře, které zná až serializér, jako nálezy NA VZTAHU.
     *
     * Serializér dřív tyhle vady (přesčas nad odpracovanými hodinami, bonus
     * bez prohlášení, rozpad mzdy bez mzdy …) hlásil výjimkou až při sestavení
     * XML. Hlášení tím spadlo jako celek a účetní nevěděla, u koho - nešlo
     * vztah ani odložit, ani se na něj prokliknout. Zkusí se proto sestavit
     * každý formulář zvlášť a vada se vrátí jako nález s `employment_id`.
     *
     * Vztahy, které už mají nález z resolveru, se nezkouší: jejich formulář
     * by jen opakoval tutéž příčinu jiným kódem.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     * @return list<JmhzScenario1Blocker>
     */
    private function formProbeBlockers(
        JmhzScenario1NormalizedDocument $candidate,
        array $blockers,
    ): array {
        $blockedEmployments = [];
        $blockedEmployees = [];
        foreach ($blockers as $blocker) {
            if ($blocker->entityId === null) {
                continue;
            }
            if ($blocker->entityType === 'employment') {
                $blockedEmployments[$blocker->entityId] = true;
            } elseif (in_array($blocker->entityType, ['person', 'employee'], true)) {
                $blockedEmployees[$blocker->entityId] = true;
            }
        }
        $found = [];
        foreach ($this->probeSerializer()->probeForms($candidate) as $failure) {
            if (($failure['employment_id'] !== null
                    && isset($blockedEmployments[$failure['employment_id']]))
                || ($failure['employee_id'] !== null
                    && isset($blockedEmployees[$failure['employee_id']]))
            ) {
                continue;
            }
            $found[] = new JmhzScenario1Blocker(
                $failure['code'],
                $failure['employment_id'] !== null ? 'employment' : 'person',
                $failure['employment_id'] ?? $failure['employee_id'],
                $failure['attribute_ids'],
                $failure['message'],
            );
        }

        return $found;
    }

    private function probeSerializer(): JmhzScenario1XmlSerializer
    {
        return $this->probeSerializer ??= new JmhzScenario1XmlSerializer();
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
     * Primární pracovněprávní vztah (10495).
     *
     * Souhrnná data zaměstnance se vyplňují jednou za osobu, a to právě na
     * formuláři primárního vztahu (kontrola 248). Osoba proto musí mít PRÁVĚ
     * JEDEN vztah s `true`. Vedlejší vztah (`false`) je legitimní souběh;
     * blokuje jen nerozhodnutý údaj nebo jiný počet primárních vztahů.
     *
     * @param list<array<string,mixed>> $employments
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function inspectPrimaryEmployment(
        array $employments,
        ?int $employeeId,
        array &$blockers,
    ): void {
        $primaryCount = 0;
        $resolved = true;
        foreach ($employments as $employment) {
            $flag = $this->object($employment['employment'] ?? null)['is_primary'] ?? null;
            if (!is_bool($flag)) {
                $resolved = false;
            } elseif ($flag) {
                $primaryCount++;
            }
        }
        if (!$resolved || $primaryCount !== 1) {
            $blockers[] = $this->blocker(
                'jmhz_primary_employment_unresolved',
                'person',
                $employeeId,
                ['10495'],
            );
        }
    }

    /**
     * Zdanitelný příjem (10535) po pracovních vztazích, v celých Kč podle
     * `employment_id`.
     *
     * Záloha na daň se počítá za OSOBU (§ 38h ZDP) a její základ 10297 nese
     * souhrn na primárním formuláři. 10535 ale stojí v každém formuláři
     * zvlášť a vykazuje příjem TOHO vztahu, takže se bere z rozpadu výsledku
     * daně po vztazích. `taxable_base_minor_units` je součet složek se
     * zdaňovaným příjmem (osvobozené do něj nevstupují).
     *
     * Datový slovník 1.4.1.6 i Pokyny MH 1.4.14 kap. 3.4 k 10535: „uvádí se
     * částka základu pro výpočet zálohy na daň nebo částka základu pro výpočet
     * daně podle srážkové daně". Vztah zdaněný srážkou (§ 6 odst. 4 ZDP)
     * proto vykazuje svůj základ, ne nulu. Kontroly 245
     * a 325 katalogu podle součtu 10535 po druzích činnosti rozhodují, zda
     * jde o zálohu, nebo srážku, a nula by jim srážku zatajila.
     *
     * Součet vztahů v režimu zálohy se musí rovnat základu zálohy osoby (10297)
     * na haléř a součet vztahů se srážkou po skupinách zaokrouhlený dolů
     * základu srážkové daně (10307). Jinak by 10535 formulářů neodpovídal
     * souhrnu a rozpor se zmrazeným výsledkem by se v XML už nedohledal.
     *
     * Výsledek daně bez rozpadu po vztazích (starší zmrazená revize) se u osoby
     * s jediným vztahem vykáže základem osoby (záloha nebo srážka). U víc
     * vztahů se rozdělit nedá a hlášení se zablokuje.
     *
     * @param array<string,mixed> $tax
     * @param list<array<string,mixed>> $employments
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<int,?int>
     */
    private function relationshipTaxableIncomeCzk(
        array $tax,
        array $employments,
        ?int $personTaxableIncomeCzk,
        ?int $employeeId,
        array &$blockers,
    ): array {
        $employmentIds = [];
        foreach ($employments as $employment) {
            if (is_int($employment['employment_id'] ?? null)) {
                $employmentIds[] = $employment['employment_id'];
            }
        }
        $unavailable = function () use ($employeeId, &$blockers): array {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_income_tax_result_not_calculated',
                'person',
                $employeeId,
                ['10535'],
            );

            return [];
        };
        $relationships = $tax['relationships'] ?? null;
        if (!is_array($relationships)
            || !array_is_list($relationships)
            || $relationships === []
        ) {
            if (count($employments) === 1 && count($employmentIds) === 1) {
                return [$employmentIds[0] => $personTaxableIncomeCzk];
            }

            return $unavailable();
        }
        $baseByReference = [];
        $advanceTotal = 0;
        $withholdingByGroup = [];
        foreach ($relationships as $relationship) {
            $row = is_array($relationship) ? $relationship : [];
            $reference = $row['relationship_reference'] ?? null;
            $base = $row['taxable_base_minor_units'] ?? null;
            $regime = $row['regime'] ?? null;
            if (!is_string($reference)
                || !is_int($base)
                || !is_string($regime)
                || array_key_exists($reference, $baseByReference)
            ) {
                return $unavailable();
            }
            $minor = 0;
            if ($regime === TaxRegime::Advance->value) {
                $minor = $base;
                $advanceTotal += $base;
            } elseif ($regime === TaxRegime::Withholding->value) {
                $group = $row['withholding_group'] ?? null;
                if (!is_string($group)) {
                    return $unavailable();
                }
                $minor = $base;
                $withholdingByGroup[$group] = ($withholdingByGroup[$group] ?? 0) + $base;
            }
            $baseByReference[$reference] = $minor;
        }
        $advance = $this->object($tax['advance_tax'] ?? null);
        if (($advance['taxable_income_minor_units'] ?? null) !== $advanceTotal) {
            return $unavailable();
        }
        // Základ srážkové daně (10307) je úhrn skupiny zaokrouhlený na celé
        // koruny dolů (§ 36 odst. 3 ZDP); rozpad po vztazích ho musí složit.
        $withholdingRounded = 0;
        foreach ($withholdingByGroup as $groupBase) {
            $withholdingRounded += intdiv($groupBase, 100) * 100;
        }
        if ($withholdingRounded !== ($withholdingByGroup === [] ? 0 : ($tax['withholding_base_minor_units'] ?? null))) {
            return $unavailable();
        }
        $result = [];
        foreach ($employmentIds as $employmentId) {
            $minor = $baseByReference["employment:{$employmentId}"] ?? null;
            if ($minor === null) {
                return $unavailable();
            }
            $result[$employmentId] = $this->wholeCzk(
                $minor,
                '10535',
                'employment',
                $employmentId,
                $blockers,
            );
        }

        return $result;
    }

    /**
     * Pracovní vztah, na jehož formuláři se vykáže pojistné osoby
     * (10370, 10481). `null` = žádný vztah osoby s víc vztahy.
     *
     * Výsledek sociálního pojištění je jen za OSOBU: pojistné se počítá
     * z úhrnu vyměřovacích základů účastných vztahů a na vztahy se nerozpadá.
     * Na formulář ho proto jde přiřadit jen tehdy, když je v měsíci účastný
     * NEJVÝŠ JEDEN vztah. Celé pojistné pak patří jemu a ostatní vztahy
     * (typicky dohoda pod rozhodným příjmem) pojistné nevykazují. Kontrola 12
     * ČSSZ porovnává úhrn 10028 pojistné části se součtem 10370 přes
     * formuláře, takže se pojistné nesmí objevit dvakrát ani ztratit.
     *
     * Víc účastných vztahů najednou by vyžadovalo pojistné rozpočítat, a to
     * výsledek nedokládá. Hlášení se zablokuje, místo aby se pojistné dělilo
     * odhadem. Totéž platí pro nenulové pojistné bez účastného vztahu.
     *
     * U osoby s jediným vztahem se nic nemění, pojistné nese ten vztah.
     *
     * @param list<array<string,mixed>> $employments
     * @param array<string,mixed> $social
     * @param array<string,mixed> $payslip
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function socialContributionEmployment(
        array $employments,
        array $social,
        array $payslip,
        ?int $employeeId,
        array &$blockers,
    ): ?int {
        if (count($employments) <= 1) {
            return null;
        }
        $participating = [];
        foreach ($employments as $employment) {
            $participation = $this->object(
                $this->object($employment['insurance'] ?? null)['participation'] ?? null,
            );
            if (($participation['status'] ?? null) === 'participates') {
                $participating[] = $employment['employment_id'] ?? null;
            }
        }
        if (count($participating) === 1 && is_int($participating[0])) {
            return $participating[0];
        }
        $employeeSocial = $social['employee_contribution_minor_units'] ?? null;
        $employerSocial = $this->employerSocialMinor($social, $payslip);
        if ($participating !== []
            || (is_int($employeeSocial) && $employeeSocial !== 0)
            || (is_int($employerSocial) && $employerSocial !== 0)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_concurrent_participation_unsupported',
                'person',
                $employeeId,
                ['10370', '10481'],
            );
        }

        return null;
    }

    /**
     * Pojistné zaměstnance a sleva pracujícího důchodce PO VZTAZÍCH, jak je
     * zapsal sociální výpočet ({@see \MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthCalculator}).
     *
     * Souběh dvou účastných vztahů téže osoby u téhož zaměstnavatele: měsíční
     * hlášení vykazuje pojistné zaměstnance (10370) a jeho vyměřovací základ
     * (10477) na formuláři KAŽDÉHO vztahu. Kontrola 118 chce na každém
     * formuláři 7,1 % z jeho 10477 zaokrouhleno nahoru a kontrola 12 součet
     * 10370 rovný úhrnu 10028 pojistné části. Výpočet proto zaokrouhluje po
     * vztazích a pojistné osoby je jejich součet; tady se to jen ověří
     * a přiřadí formulářům. Nic se nedělí odhadem.
     *
     * `null` = výsledek pojistné po vztazích nenese (revize zmrazená dřív).
     * Pak platí původní cesta přes {@see socialContributionEmployment()}.
     * Součet, který nesedí na výsledek osoby, je nález, ne tichý návrat.
     *
     * @param list<array<string,mixed>> $employments
     * @param array<string,mixed> $social
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<int,array{before_minor:int,discount_minor:int,participates:bool}>|null
     */
    private function relationshipContributions(
        array $employments,
        array $social,
        ?int $employeeId,
        array &$blockers,
    ): ?array {
        $result = [];
        $before = 0;
        $discount = 0;
        foreach ($employments as $employment) {
            $employmentId = $employment['employment_id'] ?? null;
            $insurance = $this->object($employment['insurance'] ?? null);
            $ownBefore = $insurance['employee_contribution_before_discount_minor_units'] ?? null;
            $ownDiscount = $insurance['working_pensioner_discount_minor_units'] ?? null;
            if (!is_int($employmentId) || !is_int($ownBefore) || !is_int($ownDiscount)) {
                return null;
            }
            $participation = $this->object($insurance['participation'] ?? null);
            $result[$employmentId] = [
                'before_minor' => $ownBefore,
                'discount_minor' => $ownDiscount,
                'participates' => ($participation['status'] ?? null) === 'participates',
            ];
            $before += $ownBefore;
            $discount += $ownDiscount;
        }
        if ($result === []) {
            return null;
        }
        $personBefore = $social['employee_contribution_before_discount_minor_units']
            ?? $social['employee_contribution_minor_units']
            ?? null;
        $personDiscount = $social['working_pensioner_discount_minor_units'] ?? 0;
        if ($personBefore !== $before || $personDiscount !== $discount) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_social_result_not_calculated',
                'person',
                $employeeId,
                ['10370', '10491'],
            );

            return null;
        }
        foreach ($result as $employmentId => $row) {
            if (!$row['participates'] && ($row['before_minor'] !== 0 || $row['discount_minor'] !== 0)) {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_social_result_not_calculated',
                    'employment',
                    $employmentId,
                    ['10370', '10491'],
                );

                return null;
            }
        }

        return $result;
    }

    /**
     * Riziková práce, práce zdravotnického záchranáře a člena jednotky HZS
     * podniku (JMHZ 10273/10274, interakce IN29).
     *
     * Zdrojem je sazbová kategorie vztahu podle § 5a odst. 1 ZPSZ, tatáž,
     * podle které se vyměřovací základ vykazuje pod 10479 (písm. b) nebo 10480
     * (písm. c). Kategorizace rizika 10274 z ní plyne: písm. c) = 1 (práce
     * zařazená do kategorie 4), písm. b) = 6 (záchranář) nebo 7 (HZS podniku)
     * podle volby na kartě vztahu. Hodiny 10273 jsou odpracované hodiny vztahu
     * (10268), protože zařazení platí pro celý vztah; kontrola 57 hlídá, že je
     * nepřekročí.
     *
     * `null` = běžná sazba, blok se nevykazuje.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array{categorization_codes:list<string>}|null
     */
    private function riskWork(mixed $term, ?int $employmentId, array &$blockers): ?array
    {
        $values = $this->object($term);
        $category = $values['social_employer_rate_category']
            ?? (($values['risky_work'] ?? false) === true ? 'risk_employment' : 'ordinary');
        if ($category === 'risk_employment') {
            return ['categorization_codes' => ['1']];
        }
        if ($category !== 'rescue_and_company_fire_service') {
            return null;
        }
        $code = $values['jmhz_risk_categorization_code'] ?? null;
        if (!in_array($code, ['6', '7'], true)) {
            $blockers[] = $this->blocker(
                'jmhz_risk_categorization_missing',
                'employment',
                $employmentId,
                ['10274'],
            );

            return null;
        }

        return ['categorization_codes' => [$code]];
    }

    /**
     * Pojistné zaměstnavatele (10481) JEDNOHO formuláře tak, jak ho počítá
     * kontrola 315: vyměřovací základ formuláře (10477) sazbou jeho písmene
     * § 5a odst. 1, zaokrouhleno nahoru. Pokyny MPSV k 10481: částka, kterou
     * by zaměstnavatel platil, „jako kdyby dotčený zaměstnanec byl jediným
     * zaměstnancem zaměstnavatele".
     *
     * `null` = základ nebo písmeno chybí; neznámé písmeno už blokuje
     * `jmhz_employer_rate_category_unverified`.
     *
     * @param array<string,mixed>|null $socialBase
     */
    private function relationshipEmployerSocialCzk(?array $socialBase, string $periodStart): ?int
    {
        $base = $socialBase['assessment_base_czk'] ?? null;
        $letter = $socialBase['paragraph5_letter'] ?? null;
        if ($base === 0) {
            return 0;
        }
        if (is_int($base) && $base > 0 && is_string($letter)
            && isset(JmhzControlParameterCatalog::EMPLOYER_SOCIAL_RATE_BY_PARAGRAPH5_LETTER[$letter])
        ) {
            return $this->controlParameters()->employerSocialInsuranceCzk(
                $base,
                $letter,
                $periodStart,
            );
        }

        return null;
    }

    /**
     * Pojistné zaměstnance na formuláři (10370) PŘED slevou pracujícího
     * důchodce.
     *
     * Pokyny MPSV definují 10370 jako 7,1 % z 10477 a pojistná část vykazuje
     * pojistné za zaměstnance (10028) také před slevami; sleva se odečítá až
     * v úhrnu k úhradě (kontrola 4: 10033 = 10029 − 10032 − 10487 − 10545).
     * Kontrola 12 pak porovnává 10028 se součtem 10370. Kdyby se sem psalo
     * pojistné po slevě, rozešly by se obě strany o slevu hned u prvního
     * důchodce.
     *
     * Bez slevy jsou obě částky totéž, takže výsledek, který částku před
     * slevou nenese, se použije jen tehdy, když je sleva nulová.
     *
     * @param array<string,mixed> $social
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function employeeSocialBeforeDiscountMinor(
        array $social,
        ?int $employeeId,
        array &$blockers,
    ): ?int {
        $before = $social['employee_contribution_before_discount_minor_units'] ?? null;
        if (is_int($before)) {
            return $before;
        }
        $after = $social['employee_contribution_minor_units'] ?? null;
        if (!is_int($after)) {
            return null;
        }
        if (($social['working_pensioner_discount_minor_units'] ?? 0) === 0) {
            return $after;
        }
        $blockers[] = $this->blocker(
            'jmhz_scenario1_social_result_not_calculated',
            'person',
            $employeeId,
            ['10370', '10491'],
        );

        return null;
    }

    /**
     * Sleva na pojistném zaměstnance, pracujícího důchodce (§ 7d a § 7e
     * ZPSZ), pro JEDEN formulář: příznak 10490 a výše 10491.
     *
     * Výsledek sociálního pojištění nese slevu jen za OSOBU. Pokyny MPSV
     * k 10487 chtějí slevu stanovit a zaokrouhlit „u každého zaměstnance
     * (a každého jeho zaměstnání, má-li jich u zaměstnavatele více)
     * samostatně" a 10491 je 6,5 % z 10477 téhož formuláře. Obojí se potká
     * jen u vztahu, který nese pojistné osoby
     * ({@see socialContributionEmployment()}): je v měsíci jediný účastný,
     * jeho vyměřovací základ je celý základ osoby, a sleva osoby je proto
     * přesně sleva toho vztahu. Víc účastných vztahů blokuje už
     * `jmhz_scenario1_concurrent_participation_unsupported`. Kdyby se základ
     * vztahu od základu osoby přesto lišil, sleva se odhadem nepřiřadí
     * a hlášení se zablokuje.
     *
     * Ostatní vztahy osoby slevu nevykazují (10490 = NE), jinak by kontroly
     * 209 a 213 napočítaly slevu i základ dvakrát.
     *
     * Kontrola 275 zakazuje na jednom formuláři 10490 i 10546 = ANO. Sezónní
     * slevu dnes potvrdit nejde ({@see JmhzOrdinaryEvidenceBuilder} připouští
     * jen „Ne"), pojistka tu přesto je, protože jinak by rozpor odhalil až
     * protokol ČSSZ.
     *
     * @param array<string,mixed> $social
     * @param array<string,mixed> $employment
     * @param array<string,mixed>|null $evidence
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array{amount_czk:int}|null
     */
    private function employeeSocialDiscount(
        array $social,
        array $employment,
        bool $reportsSocial,
        ?array $evidence,
        ?int $employmentId,
        array &$blockers,
        ?int $relationshipDiscountMinor = null,
    ): ?array {
        if ($social === []) {
            return null;
        }
        if ($relationshipDiscountMinor !== null) {
            /*
             * Sleva po vztazích (souběh účastných vztahů): výpočet ji
             * stanovil a zaokrouhlil u každého zaměstnání samostatně, jak
             * chtějí pokyny MPSV k 10487, takže se jen opíše na formulář
             * svého vztahu.
             */
            if ($relationshipDiscountMinor === 0 || !$reportsSocial) {
                return null;
            }
            $attributes = $this->object($evidence['attribute_values'] ?? null);
            if (($attributes['10546'] ?? null) === true) {
                $blockers[] = $this->blocker(
                    'jmhz_employee_social_discount_exclusive',
                    'employment',
                    $employmentId,
                    ['10490', '10546'],
                );
                return null;
            }
            $amount = $this->wholeCzk(
                $relationshipDiscountMinor,
                '10491',
                'employment',
                $employmentId,
                $blockers,
            );

            return $amount === null ? null : ['amount_czk' => $amount];
        }
        $discount = $social['working_pensioner_discount_minor_units'] ?? 0;
        if (!is_int($discount) || $discount < 0) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_social_result_not_calculated',
                'employment',
                $employmentId,
                ['10490', '10491'],
            );
            return null;
        }
        if ($discount === 0 || !$reportsSocial) {
            return null;
        }
        $relationship = $this->object($employment['insurance'] ?? null);
        $participation = $this->object($relationship['participation'] ?? null);
        $relationshipBase = $relationship['capped_assessment_base_minor_units'] ?? null;
        if (($participation['status'] ?? null) !== 'participates'
            || !is_int($relationshipBase)
            || $relationshipBase !== ($social['capped_assessment_base_minor_units'] ?? null)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_employee_social_discount_relationship_unresolved',
                'employment',
                $employmentId,
                ['10477', '10490', '10491'],
            );
            return null;
        }
        $attributes = $this->object($evidence['attribute_values'] ?? null);
        if (($attributes['10546'] ?? null) === true) {
            $blockers[] = $this->blocker(
                'jmhz_employee_social_discount_exclusive',
                'employment',
                $employmentId,
                ['10490', '10546'],
            );
            return null;
        }
        $amount = $this->wholeCzk(
            $discount,
            '10491',
            'employment',
            $employmentId,
            $blockers,
        );

        return $amount === null ? null : ['amount_czk' => $amount];
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
        // Nulový základ se vynechává jen u neúčastného vztahu. Účastný vztah
        // bez příjmu (měsíc mimo dobu pojištění, celý měsíc nemoci) ho nese
        // jako nulu: ČSSZ chybějící 10477 bere jako nulu a poměřuje s ním
        // pojistné (chyba 20315), a přijatá hlášení ho v takovém měsíci píší.
        $base = $cappedBase === 0
            && ($participation['status'] ?? null) !== 'participates'
                ? null
                : $cappedBase;
        $letter = $base === null || $base === 0 ? null : match ($relationship['employer_rate_category'] ?? null) {
            'ordinary' => 'a',
            'rescue_and_company_fire_service' => 'b',
            'risk_employment' => 'c',
            default => null,
        };
        if ($base !== null && $base > 0 && $letter === null) {
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
        int $employmentRelationships,
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
            /*
             * 10373 je týdenní doba ze všech pracovních poměrů osoby
             * u zaměstnavatele dohromady (Pokyny k vyplnění MH kap. 3.6.9),
             * tedy úhrn, nad kterým výpočet pojistného posoudil § 7a odst. 2.
             * Výsledek zmrazený dřív úhrn nenese; vlastní sjednaná doba se
             * z něj smí vzít jen tehdy, když je pracovní poměr osoby jediný.
             */
            $millihours = $relationship['part_time_discount_weekly_working_millihours_total']
                ?? ($employmentRelationships === 1
                    ? ($relationship['agreed_weekly_working_millihours'] ?? null)
                    : null);
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
     * Čistý příjem (10344) za osobu, v haléřích; ořez záporné hodnoty na nulu
     * dělá {@see wholeCzk()}.
     *
     * Pokyny MPSV k vyplnění MH 1.4.13 u 10344: příjem ze závislé činnosti
     * „podléhající dani" po odečtení pojistného na sociální zabezpečení,
     * zdravotního pojištění a zálohy, resp. daně; „do rozhodného příjmu se
     * nezapočítává daňový bonus … Patří sem i příjmy podle § 192 ZP" a
     * záporná hodnota se vykáže nulou. Údaj slouží dávkám státní sociální
     * podpory, ne srážkám ze mzdy, takže se NEpočítá z čisté mzdy pro srážky
     * (`net_before_deductions_minor_units`): ta podle § 277 OSŘ bonus
     * obsahuje a nese jen peněžní příjem.
     *
     * Skladba:
     *  + zdaňovaný příjem (základ zálohy 10297 a základ srážkové daně 10307),
     *    tedy peněžní i nepeněžní plnění, které podléhá dani — zdanitelná
     *    část stravování, soukromé užití vozidla, jiný nepeněžní příjem;
     *  + náhrada mzdy při dočasné pracovní neschopnosti (§ 192 ZP), kterou
     *    číselník složek vede jako osvobozenou; pokyny ji jmenují výslovně;
     *  − pojistné zaměstnance na sociální zabezpečení PO slevě pracujícího
     *    důchodce a zdravotní pojištění zaměstnance;
     *  − záloha po slevách (bez bonusu) a srážková daň.
     * Nezapočítává se daňový bonus ani vratka nebo doplatek z ročního
     * zúčtování; osvobozené benefity (stravenkový paušál) dani nepodléhají.
     * Přijatá hlášení jiného systému (1 437 formulářů) dávají přesně tenhle
     * součet, bonus v žádném z nich není.
     *
     * Příprava zmrazená dřív, než nesla rozpad vstupů po složkách, se vykáže
     * jako dosud — z čisté mzdy před srážkami bez bonusu.
     *
     * @param array<string,mixed> $tax
     * @param array<string,mixed> $social
     * @param array<string,mixed> $health
     * @param array<string,mixed> $net
     * @param list<array<string,mixed>> $employments
     */
    private function netIncomeMinor(
        array $tax,
        array $social,
        array $health,
        array $net,
        array $employments,
    ): ?int {
        $sicknessCompensation = 0;
        foreach ($employments as $employment) {
            $inputs = $this->object($employment['calculation'] ?? null)['inputs'] ?? null;
            if (!is_array($inputs) || !array_is_list($inputs)) {
                $legacy = $net['net_before_deductions_minor_units'] ?? null;
                $bonus = $this->object($tax['advance_tax'] ?? null)['tax_bonus_minor_units'] ?? 0;

                return is_int($legacy) && is_int($bonus) ? $legacy - $bonus : null;
            }
            foreach ($this->rows($inputs) as $input) {
                if (($input['component_code'] ?? null) !== PayrollSicknessInputMaterializer::COMPONENT_CODE) {
                    continue;
                }
                $totals = $this->object($input['totals'] ?? null);
                $source = $totals['source_amount_minor'] ?? null;
                $taxBase = $totals['tax_base_minor'] ?? null;
                if (!is_int($source) || !is_int($taxBase)) {
                    return null;
                }
                // Jen osvobozená část; zdaněná už je v základu zálohy.
                $sicknessCompensation += $source - $taxBase;
            }
        }
        $advance = $this->object($tax['advance_tax'] ?? null);
        $parts = [
            $advance === [] ? 0 : ($advance['taxable_income_minor_units'] ?? null),
            $tax['withholding_base_minor_units'] ?? 0,
            $social['employee_contribution_minor_units'] ?? null,
            $health['employee_contribution_minor_units'] ?? null,
            $advance === [] ? 0 : ($advance['tax_after_credits_minor_units'] ?? null),
            $tax['withholding_tax_minor_units'] ?? null,
        ];
        foreach ($parts as $part) {
            if (!is_int($part)) {
                return null;
            }
        }
        [$advanceBase, $withholdingBase, $employeeSocial, $employeeHealth, $advanceTax, $withholdingTax] = $parts;

        return $advanceBase + $withholdingBase + $sicknessCompensation
            - $employeeSocial - $employeeHealth - $advanceTax - $withholdingTax;
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
        if (($withholdingTax !== 0 || $withholdingGroups !== [])
            && !is_int($tax['withholding_base_minor_units'] ?? null)
        ) {
            // Bez základu není co do 10307 vykázat; sražená daň bez základu by
            // byla formulář, který sám sobě neodpovídá.
            $blockers[] = $this->blocker(
                'jmhz_scenario1_withholding_base_missing',
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
            }
        }
    }

    /**
     * Měsíční daňové zvýhodnění na děti: 10303 (zvýhodnění), 10304 (uplatněná
     * sleva) a blok `zvyhodneniDetiMesic` (10439, 10440, 10453).
     *
     * Vrací `null`, když se zvýhodnění neuplatňuje — blok se pak nepíše vůbec,
     * protože kontrola 244 se u téhle skupiny řídí PŘÍTOMNOSTÍ elementu.
     *
     * Fail-closed zůstává tam, kde zmrazený podklad neodpovídá vypočtené
     * částce: bez dětí, bez jména dítěte, s nerozhodnutou nebo rozpornou
     * odpovědí na 10453 a bez podepsaného prohlášení se raději nevykáže nic.
     *
     * @param array<string,mixed> $tax
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>|null
     */
    private function childCredit(
        mixed $evidence,
        array $tax,
        ?bool $declarationSigned,
        ?int $employeeId,
        array &$blockers,
    ): ?array {
        $advance = $this->object($tax['advance_tax'] ?? null);
        $claimed = $advance['child_credit_minor_units'] ?? null;
        $applied = $tax['applied_child_credit_minor_units'] ?? null;
        $frozen = $this->object($evidence);
        $children = $this->rows($frozen['children'] ?? null);
        // Dítě „N" (`credit_claimed = false`) nese jen pořadí v domácnosti,
        // zvýhodnění na ně uplatňuje jiná osoba. Samo o sobě blok nezakládá:
        // kdo uvádí jen děti „N", zvýhodnění neuplatňuje a blok se nepíše.
        $claimedChildren = array_values(array_filter(
            $children,
            static fn (array $child): bool => ($child['credit_claimed'] ?? true) !== false,
        ));
        if (!is_int($claimed) || $claimed <= 0) {
            if ($claimedChildren !== []) {
                // Zmrazený nárok bez částky znamená, že se podklad a výpočet
                // rozešly — vykázat jedno z toho by zakrylo, které je špatně.
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_child_credit_source_inconsistent',
                    'person',
                    $employeeId,
                    ['10303', '10304'],
                );
            }

            return null;
        }
        if (!is_int($applied) || $applied < 0 || $applied > $claimed) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_income_tax_result_not_calculated',
                'person',
                $employeeId,
                ['10303', '10304'],
            );

            return null;
        }
        if ($declarationSigned !== true) {
            // § 35d odst. 1: zvýhodnění se měsíčně uplatní jen u plátce,
            // u kterého je podepsané prohlášení. Rozpor je vada podkladu.
            $blockers[] = $this->blocker(
                'jmhz_scenario1_child_credit_without_declaration',
                'person',
                $employeeId,
                ['10303', '10304', '10419'],
            );

            return null;
        }
        if ($claimedChildren === []) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_child_credit_source_inconsistent',
                'person',
                $employeeId,
                ['10303', '10304', '10440'],
            );

            return null;
        }

        $status = $frozen['other_household_caregiver_status'] ?? null;
        if (!in_array($status, ['none', 'present'], true)) {
            // 10453 je povinná položka bloku. `unknown` = otázku nikdo
            // nezodpověděl, `inconsistent` = nároky si u téže domácnosti
            // odporují; obojí musí rozhodnout účetní na kartě osoby.
            $blockers[] = $this->blocker(
                $status === 'inconsistent'
                    ? 'jmhz_scenario1_child_credit_caregiver_inconsistent'
                    : 'jmhz_scenario1_child_credit_caregiver_unknown',
                'person',
                $employeeId,
                ['10453'],
            );

            return null;
        }
        $caregivers = [];
        foreach ($this->rows($frozen['other_household_caregivers'] ?? null) as $row) {
            $caregiver = [
                'given_name' => $row['given_name'] ?? null,
                'family_name' => $row['family_name'] ?? null,
                'birth_date' => $row['birth_date'] ?? null,
            ];
            if (!$this->isPersonName($caregiver['given_name'])
                || !$this->isPersonName($caregiver['family_name'])
                || !is_string($caregiver['birth_date'])
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $caregiver['birth_date']) !== 1
            ) {
                $caregivers = null;
                break;
            }
            $caregivers[] = $caregiver;
        }
        // Kontrola 127 (blocking): u 10453 = ANO musí být jiná osoba
        // pojmenovaná (10431, 10432, 10433/10434). Dítě „N" samo tvrdí, že
        // zvýhodnění uplatňuje jiná osoba v téže domácnosti, takže 10453 musí
        // být ANO a ta osoba jmenovaná.
        if ($caregivers === null
            || ($status === 'present' && $caregivers === [])
            || ($status === 'none' && $caregivers !== [])
            || ($status !== 'present' && count($claimedChildren) !== count($children))
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_child_credit_caregiver_identity_missing',
                'person',
                $employeeId,
                ['10431', '10432', '10433', '10453'],
            );

            return null;
        }

        $normalized = [];
        $orders = [];
        foreach ($children as $child) {
            $identity = $this->object($child['identity'] ?? null);
            $order = $child['order'] ?? null;
            if (!$this->isPersonName($identity['given_name'] ?? null)
                || !$this->isPersonName($identity['family_name'] ?? null)
                || !$this->isPersonBirthDate($identity['birth_date'] ?? null)
            ) {
                // Jméno dítěte nese karta vyživované osoby a `full_name` se
                // úmyslně nedělí automaticky; bez rozdělených částí by do
                // podání šla právně jiná identita.
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_child_identity_incomplete',
                    'person',
                    $employeeId,
                    ['10435', '10436', '10437'],
                );

                return null;
            }
            // Číselník 10440 zná 1, 2, 3 a N, přičemž 3 znamená „třetí
            // a každé další": katalog kontrol MH u kontroly 110 výslovně
            // říká, že čtvrté a další dítě má pořadí 3. Kolizi hlídá ČSSZ jen
            // u pořadí 1 a 2, trojka se opakovat smí.
            //
            // Dítě „N" má v evidenci pořadí v domácnosti (musí být platné),
            // ale do podání jde jako „N" — pokyny MPSV k 10440: dítě ve
            // společné domácnosti, na které poplatník zvýhodnění neuplatňuje,
            // „musí uvést s kódem N". Díky němu projde kontrola 110 u dítěte
            // s pořadím 2 nebo 3, jehož nižší pořadí uplatňuje druhý rodič.
            $creditClaimed = ($child['credit_claimed'] ?? true) !== false;
            $reportedOrder = is_int($order) ? min($order, 3) : null;
            if ($reportedOrder === null
                || $reportedOrder < 1
                || ($creditClaimed && $reportedOrder < 3 && isset($orders[$reportedOrder]))
            ) {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_child_order_unsupported',
                    'person',
                    $employeeId,
                    ['10440'],
                );

                return null;
            }
            if ($creditClaimed) {
                $orders[$reportedOrder] = true;
            }
            $normalized[] = [
                'identity' => [
                    'given_name' => trim((string) $identity['given_name']),
                    'family_name' => trim((string) $identity['family_name']),
                    'birth_date' => $identity['birth_date'],
                ],
                'ztp_p' => ($child['ztp_p'] ?? null) === true,
                'order' => $creditClaimed ? (string) $reportedOrder : 'N',
            ];
        }

        return [
            'monthly_credit_czk' => $this->wholeCzk(
                $claimed,
                '10303',
                'person',
                $employeeId,
                $blockers,
            ),
            'applied_credit_czk' => $this->wholeCzk(
                $applied,
                '10304',
                'person',
                $employeeId,
                $blockers,
            ),
            'other_household_caregiver' => $status === 'present',
            'other_household_caregivers' => $caregivers,
            'children' => $normalized,
        ];
    }

    private function isPersonName(mixed $value): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && mb_strlen(trim($value)) <= 100;
    }

    private function isPersonBirthDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
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
        // Klíče rozpadu jsou hodnoty TaxCreditKind (`disability-basic`, `ztp-p`),
        // tak jak je zapisuje výpočet daně. Dřív se tu hledaly s podtržítkem,
        // takže každá sleva na invaliditu nebo ZTP/P zablokovala hlášení
        // jako „rozpad nesedí na úhrn".
        $kindsByKey = [
            'basic' => TaxCreditKind::Taxpayer->value,
            'disability_basic' => TaxCreditKind::DisabilityBasic->value,
            'disability_extended' => TaxCreditKind::DisabilityExtended->value,
            'ztp_p' => TaxCreditKind::ZtpP->value,
        ];
        /*
         * 10299–10302 nesou NÁROK na slevu podle prohlášení, ne částku, která
         * se do zálohy vešla. Částečné uplatnění (záloha před slevou je nižší
         * než nárok) je u nízkého příjmu běžný stav a výsledek krácení nese
         * 10305. Přijatá hlášení tří různých mzdových systémů vykazují
         * u zálohy 1 800 Kč slevu 2 570 Kč a 10305 nulu, a v měsíci bez
         * příjmu slevu i bez bloku zálohy. Žádná kontrola ČSSZ výši slevy
         * s 10298 a 10305 nepoměřuje (244 a 245 hlídají jen přítomnost bez
         * prohlášení), takže rozpad nároku po druzích se nemusí krátit.
         */
        if ($applied > $claimed || $applied < 0) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_tax_credit_breakdown_unavailable',
                'person',
                $employeeId,
                ['10299', '10300', '10301', '10302'],
            );

            return $empty;
        }
        $result = $empty;
        $total = 0;
        foreach ($kindsByKey as $key => $kind) {
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
     * Daň podle zvláštní sazby (§ 6 odst. 4 ZDP). Vykazuje se v samostatném
     * bloku `zvlastniSazbaDane` (10307 základ, 10309 sražená daň); záloha na
     * daň se u čistě srážkové osoby neuvádí vůbec, což řeší serializér.
     *
     * Vrací `null`, když srážková daň nenastala — pak se blok nepíše.
     *
     * @param array<string,mixed> $tax
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array{base:?int,tax:?int}|null
     */
    /**
     * Kontrola 325 katalogu ČSSZ (zamítavá): bez odměn nerezidentů ve
     * statutárním orgánu (10416, aplikace je nevykazuje) a s úhrnem 10535
     * dohod o provedení práce (druh činnosti T až ZC) aspoň na rozhodné
     * hranici DPP a zároveň úhrnem 10535 ostatních vztahů aspoň na hranici
     * ZMR nesmí osoba nést srážkovou daň (10307, 10309).
     *
     * Z hotového XML se kontrola vyhodnotit nedá, protože druh činnosti
     * (10239) první profil nevykazuje. Vynucuje se proto tady nad rozhodnutím
     * selektoru, stejně jako kontrola 42. Hranice jsou z mzdového rulesetu
     * (TaxConstants), tedy tytéž, podle kterých mzda režim zdanění určila.
     * Rok bez ověřených konstant kontrolu nevyhodnotí; nevydává ji za splněnou,
     * jen ji nechá protokolu ČSSZ.
     *
     * @param list<array<string,mixed>> $employments
     * @param array{base:?int,tax:?int}|null $withholding
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function inspectWithholdingAgainstThresholds(
        array $employments,
        ?array $withholding,
        string $periodStart,
        ?int $employeeId,
        array &$blockers,
    ): void {
        if ($withholding === null) {
            return;
        }
        try {
            $constants = TaxConstants::forYear((int) substr($periodStart, 0, 4));
        } catch (\OutOfRangeException) {
            return;
        }
        $dppLimit = $constants['dpp_withholding_limit'] ?? null;
        $smallScaleLimit = $constants['sickness_participation_threshold'] ?? null;
        if (!is_numeric($dppLimit) || !is_numeric($smallScaleLimit)) {
            return;
        }
        $agreements = 0;
        $others = 0;
        foreach ($employments as $employment) {
            $base = $employment['taxable_income_czk'] ?? null;
            $selector = $this->object($employment['selector'] ?? null);
            $activityCode = $selector['activity_code']
                ?? $this->object($employment['term'] ?? null)['activity_code']
                ?? null;
            if (!is_int($base) || !is_string($activityCode) || $activityCode === '') {
                return;
            }
            if (PayrollEmploymentJmhzActivityFamily::isAgreementToCompleteJobActivity($activityCode)) {
                $agreements += $base;
            } else {
                $others += $base;
            }
        }
        if ($agreements >= (float) $dppLimit && $others >= (float) $smallScaleLimit) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_withholding_above_thresholds',
                'person',
                $employeeId,
                ['10307', '10309', '10535', '10239'],
            );
        }
    }

    private function withholdingTaxCzk(
        array $tax,
        ?int $employeeId,
        array &$blockers,
    ): ?array {
        $taxMinor = $tax['withholding_tax_minor_units'] ?? null;
        $baseMinor = $tax['withholding_base_minor_units'] ?? null;
        $groups = $tax['withholding_groups'] ?? null;
        $hasWithholding = (is_int($taxMinor) && $taxMinor !== 0)
            || (is_array($groups) && $groups !== []);
        if (!$hasWithholding) {
            return null;
        }
        if (!is_int($baseMinor) || !is_int($taxMinor)) {
            // Blocker hlásí `inspectUnsupportedTax()`; tady se jen nevykazuje
            // polovina bloku.
            return null;
        }

        return [
            'base' => $this->wholeCzk($baseMinor, '10307', 'person', $employeeId, $blockers),
            'tax' => $this->wholeCzk($taxMinor, '10309', 'person', $employeeId, $blockers),
        ];
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
        // 10297 je podle Pokynů MPSV k vyplnění MH 1.4.13 úhrn zdaňovaných
        // příjmů „bez zaokrouhlení" (vzor: 10297 = 15 353, 10298 = 15 % ze
        // 15 400). Zaokrouhlený základ je jen mezihodnota výpočtu 10298 a do
        // hlášení nepatří; mzdový list a výplatnice ho dál ukazují jako
        // „zaokrouhlený základ", tam je na místě.
        return [
            'base' => $this->advanceTaxField(
                $advance,
                'taxable_income_minor_units',
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
        /*
         * Srážka ze mzdy hlášení neblokuje. JMHZ o srážkách nechce částky —
         * 10116 je v XSD prostý boolean a čistý příjem 10344 se srážkami
         * nekrátí (viz netIncomeMinor()), takže exekuce,
         * insolvence ani dohoda o srážkách nemají do vykázaných čísel co
         * mluvit. Samotný příznak nese ordinary evidence, odvozená ze zmrazené
         * revize; tady se proto nekontroluje nic dalšího.
         */
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

    /**
     * Haléřová částka JMHZ je nález, ne zaokrouhlení.
     *
     * Podklady, ze kterých se hlášení staví, chtějí u peněžních polí celé
     * číslo, ale neříkají, jak naložit s haléři:
     * - XSD JMHZ 1.4.3.6: `cislo8Type`/`cislo12Type`/`cislo14Type` nad
     *   `bt:simpleNNType`, vzor `[0-9]*` (api/xsd/jmhz/jmhz-1.4.3.6);
     * - datový slovník 1.4.1.6: upřesnění „celé číslo"; zaokrouhlení dává
     *   jen u pojistného a slev (10370, 10481, 10491, 10547, vždy nahoru);
     * - katalog kontrol 1.4.2.9: totéž (kontroly 8, 10, 118, 167, 315);
     * - MPSV, Pokyny k vyplnění měsíčního hlášení 1.4.13 (11. 5. 2026,
     *   cssz.gov.cz): u 10286, 10328 až 10331, 10307, 10309, 10535 jen „celé
     *   číslo", u 10297 dokonce „bez zaokrouhlení";
     * - JMHZ srozumitelně 1.4, Struktura a pravidla podání 1.4.2, Pravidla
     *   podání 1.4.3 (developers.mpsv.cz) a Nejčastější dotazy při podávání
     *   JMHZ (ČSSZ, 9. 6. 2026): o haléřích nic.
     * Zákon zaokrouhlení dává mzdě jako celku (§ 142 odst. 2 ZP, přes § 144
     * i odměně z dohody a náhradě mzdy: „na celé koruny směrem nahoru")
     * a vyměřovacímu základu (§ 5d ZPSZ). Kam se rozdíl promítne v rozpadu
     * 10329 až 10336, neurčuje nic. Zaokrouhlit tady každé pole zvlášť by
     * navíc rozbilo úhrn příplatků 10332 ≥ 10334 + 10335 + 10336
     * (10,40 + 10,40 Kč dá úhrn 21, části 11 + 11). Náprava proto patří do
     * mzdového běhu, viz JmhzBlockerExplainer.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     */
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
        $whole = intdiv($minor, 100);
        if ($whole < 0 && in_array($attributeId, self::NEGATIVE_INCOME_REPORTED_AS_ZERO, true)) {
            // Viz self::NEGATIVE_INCOME_REPORTED_AS_ZERO — záporný výsledek
            // se u těchto atributů vykazuje jako 0, ne jako blokace podání.
            return 0;
        }
        return $whole;
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
     * Pojistné zaměstnavatele za osobu (10481) tak, jak ho počítá kontrola 315.
     *
     * Firemní pojistné se zaokrouhluje až z úhrnu vyměřovacích základů (§ 7
     * ZPSZ), takže podíl připadající na osobu ve výplatní pásce běžně nese
     * haléře. Opsat ho do hlášení nejde dvakrát: XSD bere jen celé koruny
     * a kontrola 315 chce na formuláři přesně základ 10477 (resp. jeho rozpad
     * podle § 5a) krát sazbu, zaokrouhleno nahoru. Dřív tu proto padal nález
     * `jmhz_scenario1_whole_czk_required` u každého, jehož podíl nevyšel na
     * celé koruny — u běžné firmy u většiny lidí.
     *
     * Počítá se ze základu vztahu, který pojistné osoby nese (viz
     * socialContributionEmployment()), sazbou jeho písmene. Kde základ nebo
     * písmeno chybí, zůstává původní cesta: ta buď vrátí celé koruny, nebo
     * nález — a neznámé písmeno už blokuje `jmhz_employer_rate_category_unverified`.
     *
     * @param array<string,mixed> $social
     * @param array<string,mixed> $payslip
     * @param list<array<string,mixed>> $employments
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function employerSocialCzk(
        array $social,
        array $payslip,
        array $employments,
        string $periodStart,
        int $employeeId,
        array &$blockers,
    ): ?int {
        foreach ($employments as $employment) {
            if (($employment['reports_social_contributions'] ?? false) !== true) {
                continue;
            }
            $socialBase = $this->object($employment['social_base'] ?? null);
            $base = $socialBase['assessment_base_czk'] ?? null;
            $letter = $socialBase['paragraph5_letter'] ?? null;
            if ($base === 0) {
                return 0;
            }
            if (is_int($base) && $base > 0 && is_string($letter)
                && isset(JmhzControlParameterCatalog::EMPLOYER_SOCIAL_RATE_BY_PARAGRAPH5_LETTER[$letter])
            ) {
                return $this->controlParameters()->employerSocialInsuranceCzk(
                    $base,
                    $letter,
                    $periodStart,
                );
            }
            break;
        }

        return $this->wholeCzk(
            $this->employerSocialMinor($social, $payslip),
            '10481',
            'person',
            $employeeId,
            $blockers,
        );
    }

    private function controlParameters(): JmhzControlParameterCatalog
    {
        return $this->controlParameters ??= JmhzControlSourceCatalog::load()->parameters();
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
