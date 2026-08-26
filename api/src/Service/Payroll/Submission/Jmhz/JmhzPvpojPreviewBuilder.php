<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Payment\PayrollSocialOfficeAllocator;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzPvpojPreviewBuilder
{
    private const MAX_XSD_AMOUNT_CZK = 999_999_999_999;
    private const MAX_XSD_PERSON_COUNT = 999_999;

    public function __construct(
        private readonly PayrollSocialOfficeAllocator $officeAllocator
            = new PayrollSocialOfficeAllocator(),
    ) {}

    /**
     * Přehled o výši pojistného za JEDNU mzdovou účtárnu.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * ZÁVAZNÉ ROZHODNUTÍ: přehled účtárny vzniká ROZDĚLENÍM kořenové částky
     * ─────────────────────────────────────────────────────────────────────────
     * Přehled se NIKDY nepočítá znovu z vyměřovacích základů účtárny. Kořenový
     * sociální výsledek (`payroll_statutory_results`) je jediný zdroj pravdy a
     * součet přehledů přes účtárny se mu musí rovnat na korunu — proto se jen
     * rozděluje, a to výhradně přes
     * {@see \MyInvoice\Service\Payroll\Payment\PayrollSocialOfficeAllocator},
     * tedy stejným rozpadem, ze kterého vznikají závazky ČSSZ.
     *
     * Vlastní přepočet po účtárnách (základ × sazba, zaokrouhlení nahoru podle
     * § 7 odst. 3) by v každé účtárně přidal vlastní zaokrouhlení a založil
     * trvalý rozdíl mezi podáním, účetnictvím a platbami.
     *
     * Formální nevýhoda je přijatá vědomě: částka registrace není odvozená
     * z jejího vlastního úhrnu základů, ale je podílem firemního výsledku.
     * NEOPRAVOVAT „zpátky" na přepočet — je to rozhodnutí zadavatele, ne opomenutí.
     *
     * @param array{
     *   revision:array<string,mixed>,
     *   statutory_result:array<string,mixed>,
     *   offices:list<array<string,mixed>>
     * } $source
     * @param int|null $officeId účtárna, za kterou se podává; `null` uspěje jen
     *        u běhu s jedinou účtárnou
     */
    public function build(
        int $supplierId,
        array $source,
        ?int $officeId = null,
    ): JmhzPvpojPreview {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být kladné číslo.');
        }
        $revision = $this->object($source['revision'], 'revision');
        $statutory = $this->object(
            $source['statutory_result'],
            'statutory_result',
        );
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt(
            $revision['revision_no'] ?? null,
            'revision.revision_no',
        );
        if (($revision['revision_status'] ?? null) !== 'approved'
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid(
                'jmhz_revision_not_current_approved',
                'PVPOJ preview lze vytvořit jen z aktuální schválené mzdové revize.',
            );
        }
        $periodStart = $this->date(
            $revision['period_start'] ?? null,
            'revision.period_start',
        );
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid(
                'jmhz_period_invalid',
                'Mzdové období PVPOJ nezačíná prvním dnem měsíce.',
            );
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $period = substr($periodStart, 0, 7);

        $revisionInput = $this->canonicalObject(
            $this->nonEmptyString(
                $revision['input_snapshot_json'] ?? null,
                'revision.input_snapshot_json',
            ),
            $this->hash(
                $revision['input_snapshot_hash'] ?? null,
                'revision.input_snapshot_hash',
            ),
            'zmrazeného vstupu revize',
        );
        if (($revisionInput['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($revisionInput['supplier_id'] ?? null) !== $supplierId
            || ($revisionInput['period_start'] ?? null) !== $periodStart
        ) {
            $this->invalid(
                'jmhz_revision_input_mismatch',
                'Zmrazený vstup revize neodpovídá firmě nebo období.',
            );
        }

        if (($statutory['supplier_id'] ?? null) !== $supplierId
            || ($statutory['revision_id'] ?? null) !== $revisionId
        ) {
            $this->invalid(
                'jmhz_source_scope_mismatch',
                'Sociální výsledek nepatří zvolené firmě a revizi.',
            );
        }
        if (($statutory['schema_version'] ?? null) !== 'payroll-social-result.v1'
            || ($statutory['result_status'] ?? null) !== 'calculated'
        ) {
            $this->invalid(
                'jmhz_social_result_not_calculated',
                'PVPOJ preview vyžaduje úplný vypočtený sociální výsledek.',
            );
        }
        $statutoryInput = $this->object(
            $statutory['input_snapshot'] ?? null,
            'statutory_result.input_snapshot',
        );
        $statutoryInputHash = $this->hash(
            $statutory['input_snapshot_hash'] ?? null,
            'statutory_result.input_snapshot_hash',
        );
        $this->assertSnapshotHash(
            $statutoryInput,
            $statutoryInputHash,
            'zákonného vstupu',
        );
        if (!hash_equals(
            $this->hash(
                $revision['input_snapshot_hash'] ?? null,
                'revision.input_snapshot_hash',
            ),
            $statutoryInputHash,
        ) || CanonicalJson::encode($statutoryInput)
            !== CanonicalJson::encode($revisionInput)
        ) {
            $this->invalid(
                'jmhz_revision_input_mismatch',
                'Sociální výsledek nevychází ze stejného zmrazeného vstupu.',
            );
        }

        $root = $this->object(
            $statutory['result_snapshot'] ?? null,
            'statutory_result.result_snapshot',
        );
        $rootHash = $this->hash(
            $statutory['result_snapshot_hash'] ?? null,
            'statutory_result.result_snapshot_hash',
        );
        $this->assertSnapshotHash($root, $rootHash, 'sociálního výsledku');
        if (($root['status'] ?? null) !== 'calculated') {
            $this->invalid(
                'jmhz_social_result_not_calculated',
                'Kořenový sociální výsledek není bezvýhradně vypočtený.',
            );
        }
        $this->assertNoIssues($root['issues'] ?? null, 'result.issues');
        if ($this->date(
            $root['calculation_date'] ?? null,
            'result.calculation_date',
        ) !== $periodEnd) {
            $this->invalid(
                'jmhz_period_mismatch',
                'Datum sociálního výsledku neodpovídá mzdovému období.',
            );
        }
        $rulesetId = $this->nonEmptyString(
            $statutory['ruleset_id'] ?? null,
            'statutory_result.ruleset_id',
        );
        $rulesetHash = $this->hash(
            $statutory['ruleset_hash'] ?? null,
            'statutory_result.ruleset_hash',
        );
        if (($root['ruleset_id'] ?? null) !== $rulesetId
            || ($root['ruleset_hash'] ?? null) !== $rulesetHash
        ) {
            $this->invalid(
                'jmhz_ruleset_mismatch',
                'Ruleset kořenového sociálního výsledku nesouhlasí.',
            );
        }

        $frozenPeople = $this->frozenPeople($revisionInput);
        $office = $this->resolveOffice(
            $this->officeIds($frozenPeople),
            $this->rows($source['offices'] ?? null, 'offices'),
            $officeId,
        );
        $reconciled = $this->reconcilePeople(
            $this->rows(
                $statutory['people'] ?? null,
                'statutory_result.people',
            ),
            $frozenPeople,
        );
        $this->assertRootTotals($root, $reconciled['totals']);

        $employerBeforeDiscount = $this->minor(
            $root['employer_contribution_before_discount_minor_units'] ?? null,
            'result.employer_contribution_before_discount_minor_units',
        );
        $employerDiscount = $this->minor(
            $root['part_time_discount_minor_units'] ?? null,
            'result.part_time_discount_minor_units',
        );
        $employerAfterDiscount = $this->minor(
            $root['employer_contribution_minor_units'] ?? null,
            'result.employer_contribution_minor_units',
        );
        if ($employerDiscount > $employerBeforeDiscount
            || $employerAfterDiscount !== $employerBeforeDiscount - $employerDiscount
            || $reconciled['totals']['part_time_discount_base_minor']
                !== $this->minor(
                    $root['part_time_discount_assessment_base_minor_units'] ?? null,
                    'result.part_time_discount_assessment_base_minor_units',
                )
        ) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Sleva zaměstnavatele neodpovídá kořenovému sociálnímu výsledku.',
            );
        }
        if (($employerDiscount === 0)
            !== ($reconciled['totals']['part_time_discount_person_count'] === 0)
        ) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Počet osob se slevou zaměstnavatele neodpovídá částce slevy.',
            );
        }

        $employeeBeforeDiscount =
            $reconciled['totals']['employee_before_discount_minor'];
        $employeeDiscount = $reconciled['totals']['employee_discount_minor'];
        $employeeAfterDiscount =
            $reconciled['totals']['employee_after_discount_minor'];
        $contributionBeforeDiscount = $this->add(
            $employerBeforeDiscount,
            $employeeBeforeDiscount,
        );
        $payable = $this->subtract(
            $this->subtract(
                $contributionBeforeDiscount,
                $employerDiscount,
            ),
            $employeeDiscount,
        );
        if ($payable !== $this->add($employerAfterDiscount, $employeeAfterDiscount)) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Pojistné k úhradě nemá shodný výpočet před a po slevách.',
            );
        }

        $allocations = $this->allocate(
            $revisionInput,
            $this->rows($statutory['people'] ?? null, 'statutory_result.people'),
            $root,
            $payable,
        );
        $share = $allocations[$office['office_id']] ?? null;
        if ($share === null) {
            $this->invalid(
                'jmhz_social_office_unknown',
                "Rozpad sociálního odvodu nezná mzdovou účtárnu office:{$office['office_id']}.",
            );
        }

        $pvpoj = [
            'pojistne' => $this->employerCategoryBlocks(
                $root,
                $reconciled['totals'],
                $employerBeforeDiscount,
                $share,
            ) + [
                'pojistneZamestnavateleCelkem' => $this->toCzk(
                    $share['employer_before_discount_minor'],
                    'pojistneZamestnavateleCelkem',
                ),
                'pojistneZamestnance' => $this->toCzk(
                    $share['employee_before_discount_minor'],
                    'pojistneZamestnance',
                ),
                'pojistneCelkem' => $this->toCzk(
                    $this->add(
                        $share['employer_before_discount_minor'],
                        $share['employee_before_discount_minor'],
                    ),
                    'pojistneCelkem',
                ),
            ],
        ];
        if ($share['employer_discount_minor'] > 0) {
            $pvpoj['slevaZamestnavatele'] = [
                'pocetZamestnancu' => $this->xsdPersonCount(
                    $share['employer_discount_person_count'],
                ),
                'uhrnVymerovacichZakladu' => $this->toCzk(
                    $share['employer_discount_base_minor'],
                    'slevaZamestnavatele.uhrnVymerovacichZakladu',
                ),
                'pojistneSleva' => $this->toCzk(
                    $share['employer_discount_minor'],
                    'slevaZamestnavatele.pojistneSleva',
                ),
            ];
        }
        if ($share['employee_discount_minor'] > 0) {
            $pvpoj['slevyZamestnancu'] = [
                'pocetZamestnancu' => $this->xsdPersonCount(
                    $share['employee_discount_person_count'],
                ),
                'uhrnVymerovacichZakladu' => $this->toCzk(
                    $share['employee_discount_base_minor'],
                    'slevyZamestnancu.uhrnVymerovacichZakladu',
                ),
                'pojistneSleva' => $this->toCzk(
                    $share['employee_discount_minor'],
                    'slevyZamestnancu.pojistneSleva',
                ),
            ];
        }
        $pvpoj['pojistneUhrada'] = $this->toCzk(
            $share['amount_minor'],
            'pojistneUhrada',
        );

        return new JmhzPvpojPreview(
            $supplierId,
            $runId,
            $revisionId,
            $revisionNo,
            $period,
            $office,
            $this->allocationSummary($allocations),
            [
                'revision_input_hash' =>
                    $this->hash(
                        $revision['input_snapshot_hash'] ?? null,
                        'revision.input_snapshot_hash',
                    ),
                'statutory_result_id' => $this->positiveInt(
                    $statutory['id'] ?? null,
                    'statutory_result.id',
                ),
                'statutory_result_hash' => $rootHash,
                'ruleset_id' => $rulesetId,
                'ruleset_hash' => $rulesetHash,
            ],
            $pvpoj,
            $reconciled['people'],
        );
    }

    /**
     * Seznam mzdových účtáren, za které se z revize podává.
     *
     * Chybějící variabilní symbol tu ještě není blocker — účetní ho musí
     * v seznamu VIDĚT, aby ho měl kde doplnit. Podání za takovou účtárnu
     * zastaví {@see self::build()}.
     *
     * @param array{
     *   revision:array<string,mixed>,
     *   offices:list<array<string,mixed>>
     * } $source
     * @return list<array{
     *   office_id:int,
     *   code:string,
     *   name:string,
     *   social_security_variable_symbol:?string,
     *   submittable:bool
     * }>
     */
    public function offices(array $source): array
    {
        $revision = $this->object($source['revision'], 'revision');
        $input = $this->canonicalObject(
            $this->nonEmptyString(
                $revision['input_snapshot_json'] ?? null,
                'revision.input_snapshot_json',
            ),
            $this->hash(
                $revision['input_snapshot_hash'] ?? null,
                'revision.input_snapshot_hash',
            ),
            'zmrazeného vstupu revize',
        );
        $known = [];
        foreach ($this->rows($source['offices'] ?? null, 'offices') as $office) {
            $id = $office['id'] ?? null;
            if (is_int($id) && $id > 0) {
                $known[$id] = $office;
            }
        }
        $result = [];
        foreach ($this->officeIds($this->frozenPeople($input)) as $officeId) {
            $office = $known[$officeId] ?? null;
            if ($office === null) {
                $this->invalid(
                    'jmhz_social_office_unknown',
                    "Mzdová účtárna office:{$officeId} v číselníku firmy neexistuje.",
                );
            }
            $variableSymbol = $office['social_security_variable_symbol'] ?? null;
            $valid = self::isSubmittableVariableSymbol($variableSymbol);
            $result[] = [
                'office_id' => $officeId,
                'code' => $this->nonEmptyString($office['code'] ?? null, 'offices.code'),
                'name' => $this->nonEmptyString($office['name'] ?? null, 'offices.name'),
                'social_security_variable_symbol' => $valid
                    ? (string) $variableSymbol
                    : null,
                'submittable' => $valid,
            ];
        }

        return $result;
    }

    /**
     * Variabilní symbol zaměstnavatele je v podání povinně DESETIMÍSTNÝ
     * (`jmhzPodani.xsd`, prvek `variabilniSymbol`, `xs:length 10`); stejnou
     * délku vyžaduje příprava i GovTalk obálka. Sloupec v `payroll_offices`
     * připouští 1–10 číslic kvůli platebnímu použití, takže kratší symbol
     * se do číselníku uložit dá — a bez téhle kontroly by prošel přehledem
     * a spadl až na XSD, kde už uživatel netuší, které účtárny se to týká.
     */
    private static function isSubmittableVariableSymbol(mixed $symbol): bool
    {
        return is_string($symbol) && preg_match('/^[0-9]{10}$/D', $symbol) === 1;
    }

    /**
     * Mzdové účtárny, za které se z revize podává.
     *
     * Účtárna se bere ze ZMRAZENÉHO VSTUPU revize
     * (`input.people[].employments[].employment.office_id`), ne z kořenového
     * `input.office_id` — ten je jen filtr rozsahu běhu a u celofiremního běhu
     * je legitimně `null`.
     *
     * Vztah BEZ účtárny zůstává blockerem: PVPOJ by ho neměl pod jakým
     * variabilním symbolem vykázat a tiché přiřazení k cizí registraci je horší
     * než hlasitá chyba.
     *
     * @param array<int,array{
     *   input:array<string,mixed>,
     *   relationships:array<int,array<string,mixed>>
     * }> $frozenPeople
     * @return list<int>
     */
    private function officeIds(array $frozenPeople): array
    {
        $offices = [];
        foreach ($frozenPeople as $person) {
            foreach ($person['relationships'] as $employmentId => $entry) {
                $employment = $this->object(
                    $entry['employment'] ?? null,
                    'input.employment',
                );
                $officeId = $employment['office_id'] ?? null;
                if (!is_int($officeId) || $officeId <= 0) {
                    $this->invalid(
                        'jmhz_employment_without_office',
                        "Pracovní vztah employment:{$employmentId} nemá mzdovou účtárnu,"
                        . ' takže ho PVPOJ nemá pod jakým variabilním symbolem vykázat.',
                    );
                }
                $offices[$officeId] = true;
            }
        }
        $ids = array_keys($offices);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Registrace u OSSZ, za kterou se přehled podává.
     *
     * Běh přes víc účtáren UŽ NENÍ chyba — jen se z něj musí vybrat účtárna,
     * protože každá má vlastní registraci a vlastní variabilní symbol. Chybějící
     * variabilní symbol je blocker: prázdná hodnota by odešla do podání jako
     * platná a přehled by se přiřadil k cizí nebo k žádné registraci.
     *
     * @param list<int> $officeIds
     * @param list<array<string,mixed>> $offices
     * @return array{office_id:int,code:string,name:string,variable_symbol:string}
     */
    private function resolveOffice(
        array $officeIds,
        array $offices,
        ?int $officeId,
    ): array {
        if ($officeId === null) {
            if (count($officeIds) !== 1) {
                $this->invalid(
                    'jmhz_social_multiple_offices',
                    'Mzdový běh obsahuje vztahy z více mzdových účtáren. PVPOJ se podává'
                    . ' za každou účtárnu zvlášť — zvolte, za kterou se má sestavit.',
                );
            }
            $officeId = $officeIds[0];
        } elseif ($officeId <= 0 || !in_array($officeId, $officeIds, true)) {
            $this->invalid(
                'jmhz_social_office_unknown',
                "Mzdová účtárna office:{$officeId} nemá v této revizi žádný pracovní vztah.",
            );
        }
        foreach ($offices as $office) {
            if (($office['id'] ?? null) !== $officeId) {
                continue;
            }
            $variableSymbol = $office['social_security_variable_symbol'] ?? null;
            if (!self::isSubmittableVariableSymbol($variableSymbol)) {
                $this->invalid(
                    'jmhz_office_variable_symbol_missing',
                    "Mzdová účtárna office:{$officeId} nemá variabilní symbol"
                    . ' zaměstnavatele u ČSSZ v podatelném tvaru (deset číslic)'
                    . ' — bez něj přehled podat nelze.',
                );
            }

            return [
                'office_id' => $officeId,
                'code' => $this->nonEmptyString(
                    $office['code'] ?? null,
                    'offices.code',
                ),
                'name' => $this->nonEmptyString(
                    $office['name'] ?? null,
                    'offices.name',
                ),
                'variable_symbol' => $variableSymbol,
            ];
        }
        $this->invalid(
            'jmhz_social_office_unknown',
            "Mzdová účtárna office:{$officeId} v číselníku firmy neexistuje.",
        );
    }

    /**
     * Rozpad kořenových částek na účtárny.
     *
     * SSOT rozdělení je {@see PayrollSocialOfficeAllocator} — tentýž rozpad,
     * ze kterého vznikají závazky ČSSZ. Přehled tu žádné vlastní pravidlo
     * nemá, jen si z rozpadu vybere svou účtárnu.
     *
     * @param array<string,mixed> $input
     * @param list<array<string,mixed>> $people
     * @param array<string,mixed> $root
     * @return array<int,array<string,mixed>> office_id => podíl účtárny
     */
    private function allocate(
        array $input,
        array $people,
        array $root,
        int $payable,
    ): array {
        try {
            $allocations = $this->officeAllocator->allocate(
                $input,
                $people,
                $root,
            );
        } catch (\DomainException | \OverflowException $exception) {
            throw new JmhzPvpojPreviewException(
                'jmhz_social_office_allocation_failed',
                'Rozpad sociálního odvodu na mzdové účtárny selhal: '
                . $exception->getMessage(),
                $exception,
            );
        }
        $byOffice = [];
        $total = 0;
        foreach ($allocations as $allocation) {
            $byOffice[$allocation['office_id']] = $allocation;
            $total = $this->add($total, $allocation['amount_minor']);
        }
        if ($total !== $payable) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Součet přehledů přes mzdové účtárny nedává kořenové pojistné k úhradě.',
            );
        }
        ksort($byOffice, SORT_NUMERIC);

        return $byOffice;
    }

    /**
     * @param array<int,array<string,mixed>> $allocations
     * @return list<array<string,mixed>>
     */
    private function allocationSummary(array $allocations): array
    {
        $summary = [];
        foreach ($allocations as $allocation) {
            $summary[] = [
                'office_id' => $allocation['office_id'],
                'employee_contribution_minor_units' => $allocation['employee_minor'],
                'employer_contribution_minor_units' => $allocation['employer_minor'],
                'amount_minor_units' => $allocation['amount_minor'],
            ];
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,array{
     *   input:array<string,mixed>,
     *   relationships:array<int,array<string,mixed>>
     * }>
     */
    private function frozenPeople(array $input): array
    {
        $people = [];
        foreach ($this->rows($input['people'] ?? null, 'input.people') as $index => $person) {
            $employee = $this->object(
                $person['employee'] ?? null,
                "input.people.{$index}.employee",
            );
            $employeeId = $this->positiveInt(
                $employee['id'] ?? null,
                "input.people.{$index}.employee.id",
            );
            if (isset($people[$employeeId])) {
                $this->invalid(
                    'jmhz_person_set_mismatch',
                    "Zmrazený vstup obsahuje employee:{$employeeId} vícekrát.",
                );
            }
            $relationships = [];
            foreach ($this->rows(
                $person['employments'] ?? null,
                "input.people.{$index}.employments",
            ) as $employmentIndex => $entry) {
                $employment = $this->object(
                    $entry['employment'] ?? null,
                    "input.people.{$index}.employments.{$employmentIndex}.employment",
                );
                $employmentId = $this->positiveInt(
                    $employment['id'] ?? null,
                    'input.employment.id',
                );
                if (($employment['employee_id'] ?? null) !== $employeeId
                    || isset($relationships[$employmentId])
                ) {
                    $this->invalid(
                        'jmhz_relationship_set_mismatch',
                        "Zmrazený vztah employment:{$employmentId} nemá jednoznačného vlastníka.",
                    );
                }
                $relationships[$employmentId] = $entry;
            }
            ksort($relationships, SORT_NUMERIC);
            $people[$employeeId] = [
                'input' => $person,
                'relationships' => $relationships,
            ];
        }
        ksort($people, SORT_NUMERIC);

        return $people;
    }

    /**
     * @param list<array<string,mixed>> $people
     * @param array<int,array{
     *   input:array<string,mixed>,
     *   relationships:array<int,array<string,mixed>>
     * }> $frozen
     * @return array{
     *   totals:array{
     *     capped_base_minor:int,
     *     employee_before_discount_minor:int,
     *     employee_discount_minor:int,
     *     employee_after_discount_minor:int,
     *     employee_discount_person_count:int,
     *     employee_discount_base_minor:int,
     *     part_time_discount_person_count:int,
     *     part_time_discount_base_minor:int
     *   },
     *   people:list<array<string,mixed>>
     * }
     */
    private function reconcilePeople(array $people, array $frozen): array
    {
        $seen = [];
        $totals = [
            'capped_base_minor' => 0,
            'employee_before_discount_minor' => 0,
            'employee_discount_minor' => 0,
            'employee_after_discount_minor' => 0,
            'employee_discount_person_count' => 0,
            'employee_discount_base_minor' => 0,
            'part_time_discount_person_count' => 0,
            'part_time_discount_base_minor' => 0,
            /*
             * Dílčí vyměřovací základy podle § 5a odst. 1 — 10478 písm. a),
             * 10479 písm. b), 10480 písm. c). Sčítají se po VZTAZÍCH, protože
             * podle písmen se rozlišují vztahy, ne osoby: jeden člověk může mít
             * rizikový i běžný vztah a jeho osobní úhrn by rozpad ztratil.
             */
            'category_base_minor' => [],
        ];
        $reconciliation = [];
        foreach ($people as $index => $person) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                "people.{$index}.employee_id",
            );
            if (isset($seen[$employeeId]) || !isset($frozen[$employeeId])) {
                $this->invalid(
                    'jmhz_person_set_mismatch',
                    "Sociální výsledek obsahuje neočekávanou nebo duplicitní osobu employee:{$employeeId}.",
                );
            }
            $seen[$employeeId] = true;
            if (($person['result_status'] ?? null) !== 'calculated') {
                $this->invalid(
                    'jmhz_person_not_calculated',
                    "Sociální výsledek osoby employee:{$employeeId} není vypočtený.",
                );
            }
            $personInput = $this->object(
                $person['input_snapshot'] ?? null,
                "people.{$index}.input_snapshot",
            );
            $this->assertSnapshotHash(
                $personInput,
                $this->hash(
                    $person['input_snapshot_hash'] ?? null,
                    "people.{$index}.input_snapshot_hash",
                ),
                "vstupu employee:{$employeeId}",
            );
            if (CanonicalJson::encode($personInput)
                !== CanonicalJson::encode($frozen[$employeeId]['input'])
            ) {
                $this->invalid(
                    'jmhz_person_set_mismatch',
                    "Vstup employee:{$employeeId} neodpovídá zmrazené revizi.",
                );
            }
            $personResult = $this->object(
                $person['result_snapshot'] ?? null,
                "people.{$index}.result_snapshot",
            );
            $this->assertSnapshotHash(
                $personResult,
                $this->hash(
                    $person['result_snapshot_hash'] ?? null,
                    "people.{$index}.result_snapshot_hash",
                ),
                "výsledku employee:{$employeeId}",
            );
            if (($personResult['person_id'] ?? null) !== "employee:{$employeeId}"
                || ($personResult['status'] ?? null) !== 'calculated'
            ) {
                $this->invalid(
                    'jmhz_person_not_calculated',
                    "Výsledek employee:{$employeeId} není bezvýhradně vypočtený.",
                );
            }
            $this->assertNoIssues(
                $personResult['issues'] ?? null,
                "people.{$index}.result_snapshot.issues",
            );

            $relationshipSeen = [];
            $relationshipBase = 0;
            $partTimeDiscountBase = 0;
            $partTimeDiscountClaims = 0;
            $relationshipReferences = [];
            foreach ($this->rows(
                $person['relationships'] ?? null,
                "people.{$index}.relationships",
            ) as $relationshipIndex => $relationship) {
                $employmentId = $this->positiveInt(
                    $relationship['employment_id'] ?? null,
                    "people.{$index}.relationships.{$relationshipIndex}.employment_id",
                );
                if (isset($relationshipSeen[$employmentId])
                    || !isset($frozen[$employeeId]['relationships'][$employmentId])
                ) {
                    $this->invalid(
                        'jmhz_relationship_set_mismatch',
                        "Sociální výsledek obsahuje neočekávaný nebo duplicitní vztah employment:{$employmentId}.",
                    );
                }
                $relationshipSeen[$employmentId] = true;
                if (($relationship['result_status'] ?? null) !== 'calculated') {
                    $this->invalid(
                        'jmhz_relationship_not_calculated',
                        "Sociální výsledek employment:{$employmentId} není vypočtený.",
                    );
                }
                $relationshipInput = $this->object(
                    $relationship['input_snapshot'] ?? null,
                    'relationship.input_snapshot',
                );
                $this->assertSnapshotHash(
                    $relationshipInput,
                    $this->hash(
                        $relationship['input_snapshot_hash'] ?? null,
                        'relationship.input_snapshot_hash',
                    ),
                    "vstupu employment:{$employmentId}",
                );
                if (CanonicalJson::encode($relationshipInput)
                    !== CanonicalJson::encode(
                        $frozen[$employeeId]['relationships'][$employmentId],
                    )
                ) {
                    $this->invalid(
                        'jmhz_relationship_set_mismatch',
                        "Vstup employment:{$employmentId} neodpovídá zmrazené revizi.",
                    );
                }
                $result = $this->object(
                    $relationship['result_snapshot'] ?? null,
                    'relationship.result_snapshot',
                );
                $this->assertSnapshotHash(
                    $result,
                    $this->hash(
                        $relationship['result_snapshot_hash'] ?? null,
                        'relationship.result_snapshot_hash',
                    ),
                    "výsledku employment:{$employmentId}",
                );
                if (($result['relationship_id'] ?? null) !== "employment:{$employmentId}") {
                    $this->invalid(
                        'jmhz_relationship_set_mismatch',
                        "Výsledek employment:{$employmentId} má cizí identitu.",
                    );
                }
                $participation = $this->object(
                    $result['participation'] ?? null,
                    'relationship.result_snapshot.participation',
                );
                if (($participation['relationship_id'] ?? null)
                    !== "employment:{$employmentId}"
                    || !in_array(
                        $participation['status'] ?? null,
                        ['participates', 'does_not_participate'],
                        true,
                    )
                ) {
                    $this->invalid(
                        'jmhz_relationship_not_calculated',
                        "Účast employment:{$employmentId} není uzavřená.",
                    );
                }
                $rateCategory = $result['employer_rate_category'] ?? null;
                if (!in_array(
                    $rateCategory,
                    ['ordinary', 'rescue_and_company_fire_service', 'risk_employment'],
                    true,
                )) {
                    $this->invalid(
                        'jmhz_pvpoj_rate_category_unsupported',
                        'PVPOJ preview nepředstírá rozpad sazeb B/C bez vypočteného kategoriálního výsledku.',
                    );
                }
                $base = $this->minor(
                    $result['capped_assessment_base_minor_units'] ?? null,
                    'relationship.capped_assessment_base_minor_units',
                );
                $totals['category_base_minor'][(string) $rateCategory] = $this->add(
                    $totals['category_base_minor'][(string) $rateCategory] ?? 0,
                    $base,
                );
                if ($participation['status'] === 'does_not_participate'
                    && $base !== 0
                ) {
                    $this->invalid(
                        'jmhz_social_totals_mismatch',
                        "Neúčastný vztah employment:{$employmentId} má nenulový základ.",
                    );
                }
                $relationshipBase = $this->add($relationshipBase, $base);
                $discount = $result['part_time_employer_discount'] ?? null;
                if (!in_array($discount, ['not_claimed', 'verified'], true)) {
                    $this->invalid(
                        'jmhz_relationship_not_calculated',
                        "Sleva employment:{$employmentId} není ověřená.",
                    );
                }
                $discountOutcome = $result['part_time_employer_discount_outcome'] ?? null;
                if ($discountOutcome !== null && $discountOutcome !== 'applied') {
                    $discount = 'not_claimed';
                }
                if ($discount === 'verified') {
                    $partTimeDiscountClaims++;
                    $partTimeDiscountBase = $this->add(
                        $partTimeDiscountBase,
                        $base,
                    );
                }
                $relationshipReferences[] = "employment:{$employmentId}";
            }
            $actualRelationshipIds = array_keys($relationshipSeen);
            $expectedRelationshipIds = array_keys(
                $frozen[$employeeId]['relationships'],
            );
            sort($actualRelationshipIds, SORT_NUMERIC);
            sort($expectedRelationshipIds, SORT_NUMERIC);
            if ($actualRelationshipIds !== $expectedRelationshipIds) {
                sort($relationshipReferences, SORT_STRING);
                $this->invalid(
                    'jmhz_relationship_set_mismatch',
                    "Sociální výsledek employee:{$employeeId} nepokrývá přesně zmrazené vztahy.",
                );
            }
            if ($partTimeDiscountClaims > 1) {
                $this->invalid(
                    'jmhz_social_totals_mismatch',
                    "Employee:{$employeeId} má více slev zaměstnavatele.",
                );
            }

            $personBase = $this->minor(
                $personResult['capped_assessment_base_minor_units'] ?? null,
                'person.capped_assessment_base_minor_units',
            );
            $employeeBefore = $this->minor(
                $personResult['employee_contribution_before_discount_minor_units']
                    ?? null,
                'person.employee_contribution_before_discount_minor_units',
            );
            $employeeDiscount = $this->minor(
                $personResult['working_pensioner_discount_minor_units'] ?? null,
                'person.working_pensioner_discount_minor_units',
            );
            $employeeAfter = $this->minor(
                $personResult['employee_contribution_minor_units'] ?? null,
                'person.employee_contribution_minor_units',
            );
            if ($relationshipBase !== $personBase
                || $employeeDiscount > $employeeBefore
                || $employeeAfter !== $employeeBefore - $employeeDiscount
            ) {
                $this->invalid(
                    'jmhz_social_totals_mismatch',
                    "Součty employee:{$employeeId} nesouhlasí.",
                );
            }
            if ($employeeDiscount > 0) {
                $totals['employee_discount_person_count']++;
                $totals['employee_discount_base_minor'] = $this->add(
                    $totals['employee_discount_base_minor'],
                    $personBase,
                );
            }
            if ($partTimeDiscountClaims === 1) {
                $totals['part_time_discount_person_count']++;
                $totals['part_time_discount_base_minor'] = $this->add(
                    $totals['part_time_discount_base_minor'],
                    $partTimeDiscountBase,
                );
            }
            $totals['capped_base_minor'] = $this->add(
                $totals['capped_base_minor'],
                $personBase,
            );
            $totals['employee_before_discount_minor'] = $this->add(
                $totals['employee_before_discount_minor'],
                $employeeBefore,
            );
            $totals['employee_discount_minor'] = $this->add(
                $totals['employee_discount_minor'],
                $employeeDiscount,
            );
            $totals['employee_after_discount_minor'] = $this->add(
                $totals['employee_after_discount_minor'],
                $employeeAfter,
            );
            sort($relationshipReferences, SORT_STRING);
            $reconciliation[$employeeId] = [
                'employee_reference' => "employee:{$employeeId}",
                'relationship_references' => $relationshipReferences,
                'capped_assessment_base_minor_units' => $personBase,
                'employee_contribution_before_discount_minor_units' =>
                    $employeeBefore,
                'employee_discount_minor_units' => $employeeDiscount,
                'employee_contribution_minor_units' => $employeeAfter,
            ];
        }
        $actualEmployeeIds = array_keys($seen);
        $expectedEmployeeIds = array_keys($frozen);
        sort($actualEmployeeIds, SORT_NUMERIC);
        sort($expectedEmployeeIds, SORT_NUMERIC);
        if ($actualEmployeeIds !== $expectedEmployeeIds) {
            $this->invalid(
                'jmhz_person_set_mismatch',
                'Sociální výsledek nepokrývá přesně zmrazené osoby.',
            );
        }
        ksort($reconciliation, SORT_NUMERIC);

        return [
            'totals' => $totals,
            'people' => array_values($reconciliation),
        ];
    }

    /**
     * Bloky A, B a C pojistné části podle § 5a odst. 1 a § 7 odst. 1.
     *
     * ČSSZ počítá kontrolami 8, 10 a 167 každý blok samostatně: 10024 je sazba
     * a) ze základu 10023, 10026 sazba b) ze základu 10025 a 10484 sazba c) ze
     * základu 10483; teprve 10027 je jejich součet. Sečíst základy do jednoho
     * bloku by proto podání neprošlo — a to je i důvod, proč se rozpad nesmí
     * dopočítat odhadem.
     *
     * Revize zmrazená dřív, než výsledek kategorie nesl, žádný rozpad nemá.
     * Tam se vykáže jediný blok A, protože jediné, co tehdy modul uměl
     * spočítat, byla běžná sazba — a všechny vztahy takové revize to
     * v `assertPeople` musí potvrdit.
     *
     * Vykazují se ČÁSTKY ÚČTÁRNY, tedy podíly z kořenových bloků. Kontrola
     * dílčích základů a součtu bloků proti kořeni zůstává na úrovni celého
     * běhu — rozdělovat se smí jen to, co v kořeni sedí.
     *
     * @param array<string,mixed> $root
     * @param array<string,mixed> $totals
     * @param array<string,mixed> $share podíl účtárny z rozpadu
     * @return array<string,int>
     */
    private function employerCategoryBlocks(
        array $root,
        array $totals,
        int $employerBeforeDiscount,
        array $share,
    ): array {
        /** @var array<string,int> $relationshipBases */
        $relationshipBases = $totals['category_base_minor'];
        $letters = [
            'ordinary' => 'A',
            'rescue_and_company_fire_service' => 'B',
            'risk_employment' => 'C',
        ];
        $categories = $this->rows($root['employer_categories'] ?? [], 'result.employer_categories');
        if ($categories === []) {
            if (array_keys($relationshipBases) !== ['ordinary']) {
                $this->invalid(
                    'jmhz_pvpoj_rate_category_unsupported',
                    'Starší revize bez rozpadu § 5a nesmí obsahovat jinou než běžnou sazbu.',
                );
            }

            return [
                'zakladZamestnavateleA' => $this->toCzk(
                    $share['capped_base_minor'],
                    'zakladZamestnavateleA',
                ),
                'pojistneZamestnavateleA' => $this->toCzk(
                    $share['employer_before_discount_minor'],
                    'pojistneZamestnavateleA',
                ),
            ];
        }

        $blocks = [];
        $seen = [];
        $contributionTotal = 0;
        $officeContributionTotal = 0;
        foreach ($categories as $index => $category) {
            $value = $category['category'] ?? null;
            $letter = is_string($value) ? ($letters[$value] ?? null) : null;
            if ($letter === null || isset($seen[$value])) {
                $this->invalid(
                    'jmhz_pvpoj_rate_category_unsupported',
                    "Rozpad § 5a má neznámou nebo zdvojenou kategorii na pozici {$index}.",
                );
            }
            $seen[$value] = true;
            $base = $this->minor(
                $category['assessment_base_minor_units'] ?? null,
                "result.employer_categories.{$index}.assessment_base_minor_units",
            );
            $contribution = $this->minor(
                $category['contribution_minor_units'] ?? null,
                "result.employer_categories.{$index}.contribution_minor_units",
            );
            if ($base !== ($relationshipBases[(string) $value] ?? 0)) {
                $this->invalid(
                    'jmhz_social_totals_mismatch',
                    "Dílčí základ § 5a písm. {$letter} neodpovídá součtu vztahů.",
                );
            }
            $contributionTotal = $this->add($contributionTotal, $contribution);
            $officeBase = $share['category_base_minor'][(string) $value] ?? null;
            $officeContribution =
                $share['category_contribution_minor'][(string) $value] ?? null;
            if (!is_int($officeBase) || !is_int($officeContribution)) {
                $this->invalid(
                    'jmhz_social_totals_mismatch',
                    "Rozpad na účtárny nezná dílčí blok § 5a písm. {$letter}.",
                );
            }
            $officeContributionTotal = $this->add(
                $officeContributionTotal,
                $officeContribution,
            );
            $blocks["zakladZamestnavatele{$letter}"] = $this->toCzk(
                $officeBase,
                "zakladZamestnavatele{$letter}",
            );
            $blocks["pojistneZamestnavatele{$letter}"] = $this->toCzk(
                $officeContribution,
                "pojistneZamestnavatele{$letter}",
            );
        }
        if (array_diff(array_keys($relationshipBases), array_keys($seen)) !== []
            || $contributionTotal !== $employerBeforeDiscount
            || array_sum($relationshipBases) !== $totals['capped_base_minor']
            || $officeContributionTotal !== $share['employer_before_discount_minor']
            || array_sum($share['category_base_minor'])
                !== $share['capped_base_minor']
        ) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Rozpad § 5a nedává firemní pojistné ani úhrn vyměřovacích základů.',
            );
        }

        return $blocks;
    }

    /**
     * @param array<string,mixed> $root
     * @param array<string,mixed> $totals
     */
    private function assertRootTotals(array $root, array $totals): void
    {
        if ($this->minor(
            $root['capped_assessment_base_minor_units'] ?? null,
            'result.capped_assessment_base_minor_units',
        ) !== $totals['capped_base_minor']
            || $this->minor(
                $root['employee_contribution_minor_units'] ?? null,
                'result.employee_contribution_minor_units',
            ) !== $totals['employee_after_discount_minor']
        ) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Součty osob neodpovídají kořenovému sociálnímu výsledku.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(
        string $json,
        string $expectedHash,
        string $label,
    ): array {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new JmhzPvpojPreviewException(
                'jmhz_snapshot_invalid',
                "Kanonický JSON {$label} není platný.",
                $exception,
            );
        }
        $object = $this->object($decoded, $label);
        $canonical = CanonicalJson::encode($object);
        if ($canonical !== $json
            || !hash_equals($expectedHash, hash('sha256', $canonical))
        ) {
            $this->invalid(
                'jmhz_snapshot_hash_mismatch',
                "Otisk {$label} nesouhlasí.",
            );
        }

        return $object;
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshotHash(
        array $snapshot,
        string $expectedHash,
        string $label,
    ): void {
        if (!hash_equals(
            $expectedHash,
            hash('sha256', CanonicalJson::encode($snapshot)),
        )) {
            $this->invalid(
                'jmhz_snapshot_hash_mismatch',
                "Otisk {$label} nesouhlasí.",
            );
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} musí být objekt.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                $this->invalid(
                    'jmhz_source_invalid',
                    "{$field} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(
        mixed $value,
        string $field,
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} musí být seznam.",
            );
        }
        $result = [];
        foreach ($value as $index => $row) {
            $result[] = $this->object($row, "{$field}.{$index}");
        }

        return $result;
    }

    private function assertNoIssues(mixed $value, string $field): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} musí být seznam.",
            );
        }
        if ($value !== []) {
            $this->invalid(
                'jmhz_social_result_not_calculated',
                "{$field} obsahuje blokující problémy.",
            );
        }
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $value;
    }

    private function minor(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} musí být nezáporné celé číslo.",
            );
        }

        return $value;
    }

    private function hash(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} není platný SHA-256.",
            );
        }

        return $value;
    }

    private function nonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->invalid(
                'jmhz_source_invalid',
                "{$field} musí být neprázdný text.",
            );
        }

        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
        ) {
            $this->invalid('jmhz_source_invalid', "{$field} není platné datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_source_invalid', "{$field} není platné datum.");
        }

        return $value;
    }

    private function toCzk(int $minor, string $field): int
    {
        if ($minor % 100 !== 0) {
            $this->invalid(
                'jmhz_pvpoj_whole_czk_required',
                "Pole {$field} nelze bezpečně převést na celé Kč podle XSD.",
            );
        }
        $czk = intdiv($minor, 100);
        if ($czk > self::MAX_XSD_AMOUNT_CZK) {
            $this->invalid(
                'jmhz_pvpoj_xsd_limit_exceeded',
                "Pole {$field} překračuje limit XSD.",
            );
        }

        return $czk;
    }

    private function xsdPersonCount(int $count): int
    {
        if ($count <= 0 || $count > self::MAX_XSD_PERSON_COUNT) {
            $this->invalid(
                'jmhz_pvpoj_xsd_limit_exceeded',
                'Počet zaměstnanců se slevou překračuje limit XSD.',
            );
        }

        return $count;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            $this->invalid(
                'jmhz_amount_overflow',
                'Součet PVPOJ přetekl.',
            );
        }

        return $left + $right;
    }

    private function subtract(int $left, int $right): int
    {
        if ($right > $left) {
            $this->invalid(
                'jmhz_social_totals_mismatch',
                'Sleva převyšuje pojistné.',
            );
        }

        return $left - $right;
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzPvpojPreviewException($code, $message);
    }
}
