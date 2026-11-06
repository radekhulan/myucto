<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionPlan;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPackageSplitter;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzReportedWorkHours;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionFlagMatrix;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzWholeHours;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Serializér měsíčního hlášení ve verzi 2027 (XSD 1.5.0.3) pro tytéž
 * formuláře jako verze 2026: bez příznaku (scénář 1), činnost K–S (3),
 * vězeň (4), jiný příjem (5), mezinárodní pronájem pracovní síly (6)
 * a odložený příjem (8); řádné podání, dílčí balíky i obsahová oprava.
 *
 * Stojí VEDLE serializéru 2026
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer}),
 * který se nemění. Pracuje se stejným normalizovaným dokumentem, nic
 * nedopočítává a nezaokrouhluje; klíče, které verze 2027 potřebuje navíc,
 * popisuje {@see Jmhz2027DocumentContract}. Chybí-li povinný, hlášení se
 * nesestaví.
 *
 * Strukturální změny proti 1.4.3.6, které určují pořadí prvků:
 *
 * - `formulareOsob/formularOsoby` je `formulareZamestnani/form:formularZamestnani`
 *   a hlavička formuláře leží v prostoru `form`,
 * - `zalohaNaDan` je povinná a nese i 10579, samostatný základ § 6 odst. 4
 *   (10307, 10309), prohlášení 10419 a blok slev s datem podpisu 10575
 *   a slevou na studenta; bloky `zvlastniSazbaDane` a `prohlaseniPoplatnika`
 *   na úrovni souhrnu zanikly,
 * - `prijmyExtType` nese jen úhrn příspěvku 10417 a odměnu nerezidenta 10308,
 *   rozpad příspěvků (10418, 10292–10296) zanikl,
 * - vyloučené dny § 18 odst. 7 (10366, 10473–10475) se přesunuly z ELDP sekce
 *   do bloku `nemocenskePojisteni` za `eldpSeznam`,
 * - místo výkonu práce je adresa (10550–10554) a nese ho i vězeň a pronájem
 *   pracovní síly; činnost K–S má průběh zaměstnání (10587, IN43) a mzdu,
 * - `riziko` se opakuje po kategorizaci a nese počet směn 10580,
 * - souhrn nese kolektivní smlouvy (10214) každý měsíc.
 */
final class JmhzScenario1Xml2027Serializer
{
    public const PACKAGE_FORM_LIMIT = JmhzPackageSplitter::DEFAULT_FORM_LIMIT;

    /** @var array<string, string> */
    private const PARAGRAPH5_ELEMENTS = [
        'a' => 'form:pismenoA',
        'b' => 'form:pismenoB',
        'c' => 'form:pismenoC',
    ];

    /** @var array<string, string> */
    private const ELDP_EXCLUDED_DAYS = [
        'docasNeschopnost' => '10358',
        'penezitaPomocMaterstvi' => '10359',
        'osetrovaniClenaRodiny' => '10360',
        'otcovska' => '10362',
        'vyloucenePar16' => '10536',
    ];

    /** @var array<string, string> */
    private const SECTION18_DAYS = [
        'omluvenaNepritomnost' => '10473',
        'pracovniNeschopnost' => '10474',
        'vyplaceniDavek' => '10475',
    ];

    /** Kategorizace rizika (10274) z číselníku ČSSZ. */
    private const RISK_CODES = ['1', '6', '7'];

    public function __construct(
        private readonly JmhzPackageSplitter $packageSplitter = new JmhzPackageSplitter(),
    ) {}

    public function packageFormLimit(): int
    {
        return $this->packageSplitter->formLimit;
    }

    public function serialize(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
    ): string {
        $payload = $document->payload;
        $this->assertProfile($payload, $envelope);
        $forms = $this->employmentForms($payload);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = Jmhz2027Schema::root($dom, $envelope);
        $root->appendChild($this->header($dom, $payload, $envelope, 'R', count($forms) + 2));
        $root->appendChild($this->summary($dom, $payload));
        $root->appendChild($this->pvpoj($dom, $payload));
        $root->appendChild($this->formsFromPairs($dom, $forms, $envelope, null));

        return Jmhz2027Schema::save($dom, 'XML měsíčního hlášení nelze serializovat.');
    }

    /**
     * Řádné hlášení v dílčích balících podle stejných pravidel jako verze 2026
     * (kontroly 227, 235, 300, 301). Do 1500 formulářů jediný balík, bajtově
     * shodný se {@see self::serialize()}.
     *
     * @return list<string>
     */
    public function serializePackages(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
    ): array {
        $payload = $document->payload;
        $forms = $this->employmentForms($payload);
        if (!$this->packageSplitter->requiresSplit(count($forms))) {
            return [$this->serialize($document, $envelope->forPackage(1, 1))];
        }
        $this->assertProfile($payload, $envelope, true);
        $chunks = $this->packageSplitter->split($forms);
        $total = count($forms) + 2;
        $packages = [];
        foreach ($chunks as $index => $chunk) {
            $packageEnvelope = $envelope->forPackage($index + 1, count($chunks));
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;
            $root = Jmhz2027Schema::root($dom, $packageEnvelope);
            $root->appendChild($this->header(
                $dom,
                $payload,
                $packageEnvelope,
                'R',
                count($chunk) + ($index === 0 ? 2 : 0),
                $total,
            ));
            if ($index === 0) {
                $root->appendChild($this->summary($dom, $payload));
                $root->appendChild($this->pvpoj($dom, $payload));
            }
            $root->appendChild($this->formsFromPairs($dom, $chunk, $packageEnvelope, null));
            $packages[] = Jmhz2027Schema::save($dom, 'XML měsíčního hlášení nelze serializovat.');
        }

        return $packages;
    }

    /**
     * Vady jednotlivých formulářů s vazbou na vztah, stejně jako
     * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer::probeForms()}.
     * Chybějící klíče kontraktu 2027 se hlásí u vztahu, kterého se týkají.
     *
     * @return list<array{
     *   employment_id:?int,employee_id:?int,code:string,message:string,
     *   attribute_ids:list<string>
     * }>
     */
    public function probeForms(JmhzScenario1NormalizedDocument $document): array
    {
        $failures = [];
        foreach ($this->rows($document->payload['people'] ?? null) as $person) {
            $summary = $this->object($person['summary'] ?? null);
            $employeeId = is_int($person['employee_id'] ?? null) ? $person['employee_id'] : null;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                try {
                    $this->bool($employment['primary'] ?? null, '10495');
                    $missing = Jmhz2027DocumentContract::missingForForm($summary, $employment);
                    if ($missing !== []) {
                        Jmhz2027DocumentContract::throwMissingForForm($missing);
                    }
                    $this->formBody(new DOMDocument('1.0', 'UTF-8'), $this->periodYear($document->payload), $summary, $employment);
                } catch (JmhzXmlException $exception) {
                    preg_match_all('/\b(1\d{4})\b/', $exception->getMessage(), $matches);
                    $attributes = array_values(array_unique($matches[1]));
                    sort($attributes, SORT_STRING);
                    $failures[] = [
                        'employment_id' => $employmentId,
                        'employee_id' => $employeeId,
                        'code' => $exception->validationCode,
                        'message' => $exception->getMessage(),
                        'attribute_ids' => $attributes,
                    ];
                }
            }
        }

        return $failures;
    }

    public function serializeCorrection(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): string {
        if ($envelope->packageCount !== 1) {
            $this->invalid(
                'jmhz_xml_split_submission_unsupported',
                'Jediný balík se staví s pořadím 1 z 1; dílčí balíky staví serializeCorrectionPackages.',
            );
        }
        $packages = $this->serializeCorrectionPackages($document, $envelope, $plan);
        if (count($packages) !== 1) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Nad 1500 opravených součástí se opravné hlášení dělí do dílčích balíků'
                    . ' (serializeCorrectionPackages).',
            );
        }

        return $packages[0];
    }

    /** @return list<string> */
    public function serializeCorrectionPackages(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): array {
        $payload = $document->payload;
        $this->assertProfile($payload, $envelope, true);
        $forms = $this->correctionPeople($payload, $envelope, $plan);
        $layers = ($plan->includeSummary ? 1 : 0) + ($plan->includePvpoj ? 1 : 0);
        $chunks = $this->packageSplitter->split($forms);
        $total = count($forms) + $layers;
        $packages = [];
        foreach ($chunks as $index => $chunk) {
            $first = $index === 0;
            $packageEnvelope = $envelope->forPackage($index + 1, count($chunks));
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;
            $root = Jmhz2027Schema::root($dom, $packageEnvelope);
            $root->appendChild($this->header(
                $dom,
                $payload,
                $packageEnvelope,
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                count($chunk) + ($first ? $layers : 0),
                $total,
            ));
            if ($first && $plan->includeSummary) {
                $root->appendChild($this->summary($dom, $payload));
            }
            if ($first && $plan->includePvpoj) {
                $root->appendChild($this->pvpoj($dom, $payload));
            }
            $root->appendChild($this->formsFromPairs($dom, $chunk, $packageEnvelope, $plan));
            $packages[] = Jmhz2027Schema::save($dom, 'XML obsahové opravy měsíčního hlášení nelze serializovat.');
        }

        return $packages;
    }

    /** @param array<string,mixed> $payload */
    private function assertProfile(
        array $payload,
        JmhzSubmissionEnvelope $envelope,
        bool $split = false,
    ): void {
        if (($payload['schema_reference'] ?? null) !== JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE) {
            $this->invalid(
                'jmhz_xml_document_version_unsupported',
                'Serializér umí jen aktuální normalizovaný dokument scénáře 1.',
            );
        }
        $scope = $this->object($payload['scope'] ?? null);
        if (!in_array($scope['scenario_key'] ?? null, ['scenario_1', 'scenario_3'], true)
            || ($scope['submission_kind'] ?? null) !== 'regular'
        ) {
            $this->invalid(
                'jmhz_xml_scenario_unsupported',
                'Zdrojem serializace musí být řádná příprava podporovaného scénáře.',
            );
        }
        $header = $this->object($payload['header'] ?? null);
        if (($header['type'] ?? null) !== 'R') {
            $this->invalid(
                'jmhz_xml_submission_type_unsupported',
                'Zdrojový dokument musí být úplná řádná příprava.',
            );
        }
        $month = $this->int($header['month'] ?? null, '10010');
        $year = $this->int($header['year'] ?? null, '10011');
        if ($month < 1 || $month > 12) {
            $this->invalid('jmhz_xml_period_invalid', 'Hlášený měsíc je mimo rozsah připnutého schématu.');
        }
        Jmhz2027Schema::assertPeriod($year, $month);
        if ($this->rows($payload['people'] ?? null) === []) {
            $this->invalid('jmhz_xml_no_valid_form', 'Podání musí obsahovat alespoň jednu platnou součást.');
        }
        $formCount = count($this->employmentForms($payload));
        if (!$split && $formCount > self::PACKAGE_FORM_LIMIT) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Nad 1500 součástí se hlášení dělí do dílčích balíků (serializePackages).',
            );
        }
        if (!$split && $envelope->packageCount !== 1) {
            $this->invalid(
                'jmhz_xml_split_submission_unsupported',
                'Jediný balík se staví s pořadím 1 z 1; dílčí balíky staví serializePackages.',
            );
        }
        Jmhz2027DocumentContract::assertComplete($payload);
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            true,
            true,
            array_fill(0, $formCount, JmhzSubmissionFlagMatrix::TYPE_REGULAR),
        );
    }

    /** @param array<string,mixed> $payload */
    private function header(
        DOMDocument $dom,
        array $payload,
        JmhzSubmissionEnvelope $envelope,
        string $type,
        int $formCount,
        ?int $totalFormCount = null,
    ): DOMElement {
        $header = $this->object($payload['header'] ?? null);
        if ($formCount > 1502) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Balík dat pojme nejvýše 1502 formulářů včetně souhrnu a PVPOJ.',
            );
        }
        $variableSymbol = $this->string($header['variable_symbol'] ?? null, '10221');
        if (preg_match('/^\d{10}$/D', $variableSymbol) !== 1) {
            $this->invalid(
                'jmhz_xml_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít přesně deset číslic.',
            );
        }
        $node = $this->node($dom, Jmhz2027Schema::NS_PODANI, 'hlavicka');
        foreach ([
            'idPodani' => $envelope->submissionGuid,
            'typPodani' => $type,
            'variabilniSymbol' => $variableSymbol,
            'mesic' => (string) $this->int($header['month'] ?? null, '10010'),
            'rok' => (string) $this->int($header['year'] ?? null, '10011'),
            'datumVyplneni' => $envelope->filledAt,
            'balikPoradi' => (string) $envelope->packageOrdinal,
            'balikyPocet' => (string) $envelope->packageCount,
            'formularePocetVBaliku' => (string) $formCount,
            'formularePocetCelkem' => (string) ($totalFormCount ?? $formCount),
        ] as $name => $value) {
            $this->text($dom, $node, Jmhz2027Schema::NS_PODANI, $name, $value);
        }

        return $node;
    }

    /**
     * Souhrnná část (`souhrnType` 1.5): měsíční daňové údaje, roční údaje
     * zaměstnavatele a nově povinné kolektivní smlouvy (ZP 102: periodicita
     * 10214 měsíční, ZP 069 pozice v XSD). `danUdajeRok` ani
     * `specifickaSkutecnost` se stejně jako ve verzi 2026 neuvádějí.
     *
     * @param array<string,mixed> $payload
     */
    private function summary(DOMDocument $dom, array $payload): DOMElement
    {
        $employer = $this->object($payload['employer'] ?? null);
        $totals = $this->object($employer['summary_totals'] ?? null);
        $node = $this->node($dom, Jmhz2027Schema::NS_SOUHRN, 'so:souhrn');
        $monthly = $this->node($dom, Jmhz2027Schema::NS_SOUHRN, 'so:danUdajeMesic');
        $this->text(
            $dom,
            $monthly,
            Jmhz2027Schema::NS_SOUHRN,
            'so:danZalohaPoSleve',
            (string) $this->int($totals['advance_tax_after_credits'] ?? null, '10034'),
        );
        $taxBonus = $this->int($totals['tax_bonus'] ?? null, '10035');
        if ($taxBonus !== 0) {
            $this->text($dom, $monthly, Jmhz2027Schema::NS_SOUHRN, 'so:danBonus', (string) $taxBonus);
        }
        $node->appendChild($monthly);

        $annual = $this->object($employer['annual'] ?? null);
        if ($annual !== []) {
            $annualNode = $this->node($dom, Jmhz2027Schema::NS_SOUHRN, 'so:zamestnavatelUdajeRok');
            $this->text(
                $dom,
                $annualNode,
                Jmhz2027Schema::NS_SOUHRN,
                'so:formaVlastnictvi',
                $this->string($annual['ownership_form'] ?? null, '10220'),
            );
            $ozp = $this->object($annual['ozp'] ?? null);
            if ($ozp !== []) {
                $ozpNode = $this->node($dom, Jmhz2027Schema::NS_SOUHRN, 'so:zamestnavaniOzp');
                foreach ([
                    'so:zecPocetPrepRok' => ['average_headcount_hundredths', '10038'],
                    'so:zecPocetPrepOzpRok' => ['average_disabled_headcount_hundredths', '10039'],
                    'so:podilZamZtp' => ['disabled_share_hundredths', '10452'],
                ] as $element => [$key, $attributeId]) {
                    $this->text(
                        $dom,
                        $ozpNode,
                        Jmhz2027Schema::NS_SOUHRN,
                        $element,
                        $this->decimal($ozp[$key] ?? null, 2, $attributeId),
                    );
                }
                $annualNode->appendChild($ozpNode);
            }
            $node->appendChild($annualNode);
        }

        $agreements = $employer[Jmhz2027DocumentContract::EMPLOYER_COLLECTIVE_AGREEMENTS] ?? null;
        if (!is_array($agreements) || !array_is_list($agreements) || $agreements === []) {
            $this->unresolved('10214');
        }
        $agreementsNode = $this->node($dom, Jmhz2027Schema::NS_SOUHRN, 'so:kolektivniSmlouvy');
        foreach ($agreements as $agreement) {
            $code = $this->string($agreement, '10214');
            // Typ kolektivní smlouvy je jednoznakový kód číselníku (`znakType`).
            if (strlen($code) !== 1) {
                $this->unresolved('10214');
            }
            $agreementNode = $this->node($dom, Jmhz2027Schema::NS_SOUHRN, 'so:kolektivniSmlouva');
            $this->text($dom, $agreementNode, Jmhz2027Schema::NS_SOUHRN, 'so:typKolektSmlouvy', $code);
            $agreementsNode->appendChild($agreementNode);
        }
        $node->appendChild($agreementsNode);

        return $node;
    }

    /** @param array<string,mixed> $payload */
    private function pvpoj(DOMDocument $dom, array $payload): DOMElement
    {
        $preview = $this->object($this->object($payload['employer'] ?? null)['pvpoj'] ?? null);
        $values = $this->object($preview['values'] ?? null);
        if ($values === []) {
            $this->invalid('jmhz_xml_pvpoj_missing', 'Řádné podání musí obsahovat pojistnou část.');
        }
        $node = $this->node($dom, Jmhz2027Schema::NS_PVPOJ, 'pvpoj:PVPOJ');
        $contributions = $this->object($values['pojistne'] ?? null);
        $group = $this->node($dom, Jmhz2027Schema::NS_PVPOJ, 'pvpoj:pojistne');
        foreach ([
            'zakladZamestnavateleA' => '10023',
            'pojistneZamestnavateleA' => '10024',
            'zakladZamestnavateleB' => '10025',
            'pojistneZamestnavateleB' => '10026',
            'zakladZamestnavateleC' => '10483',
            'pojistneZamestnavateleC' => '10484',
            'pojistneZamestnavateleCelkem' => '10027',
            'pojistneZamestnance' => '10028',
            'pojistneCelkem' => '10029',
        ] as $field => $attributeId) {
            if (!array_key_exists($field, $contributions)) {
                continue;
            }
            $this->text(
                $dom,
                $group,
                Jmhz2027Schema::NS_PVPOJ,
                'pvpoj:' . $field,
                (string) $this->int($contributions[$field], $attributeId),
            );
        }
        $node->appendChild($group);
        foreach (['slevaZamestnavatele', 'slevyZamestnancu', 'slevyZamestnancuOvoZel'] as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $discount = $this->object($values[$field]);
            $discountNode = $this->node($dom, Jmhz2027Schema::NS_PVPOJ, 'pvpoj:' . $field);
            foreach ([
                'pocetZamestnancu' => '10030',
                'uhrnVymerovacichZakladu' => '10031',
                'pojistneSleva' => '10032',
            ] as $child => $attributeId) {
                $this->text(
                    $dom,
                    $discountNode,
                    Jmhz2027Schema::NS_PVPOJ,
                    'pvpoj:' . $child,
                    (string) $this->int($discount[$child] ?? null, $attributeId),
                );
            }
            $node->appendChild($discountNode);
        }
        $this->text(
            $dom,
            $node,
            Jmhz2027Schema::NS_PVPOJ,
            'pvpoj:pojistneUhrada',
            (string) $this->int($values['pojistneUhrada'] ?? null, '10033'),
        );

        return $node;
    }

    /**
     * Formuláře zaměstnání. Řádné podání má všechny typu R, obsahová oprava
     * typ podle plánu.
     *
     * @param list<array{0:array<string,mixed>,1:array<string,mixed>}> $pairs
     */
    private function formsFromPairs(
        DOMDocument $dom,
        array $pairs,
        JmhzSubmissionEnvelope $envelope,
        ?JmhzContentCorrectionPlan $plan,
    ): DOMElement {
        $node = $this->node($dom, Jmhz2027Schema::NS_PODANI, 'formulareZamestnani');
        foreach ($pairs as [$summary, $employment, $reportYear]) {
            $employmentId = $employment['employment_id'] ?? null;
            $formType = 'R';
            if ($plan !== null) {
                if (!is_int($employmentId)) {
                    $this->invalid(
                        'jmhz_content_correction_employment_invalid',
                        'Opravovaný formulář nemá platný pracovní vztah.',
                    );
                }
                $correction = $plan->formForEmployment($employmentId);
                if ($correction === null) {
                    $this->invalid(
                        'jmhz_content_correction_plan_mismatch',
                        'Opravovaný formulář chybí v plánu obsahové opravy.',
                    );
                }
                $formType = $correction->formType;
            }
            $form = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:formularZamestnani');
            $header = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:hlavicka');
            $this->text(
                $dom,
                $header,
                Jmhz2027Schema::NS_FORM,
                'form:idFormulare',
                $envelope->formGuid(is_int($employmentId) ? $employmentId : null),
            );
            $this->text($dom, $header, Jmhz2027Schema::NS_FORM, 'form:typFormulare', $formType);
            $this->text(
                $dom,
                $header,
                Jmhz2027Schema::NS_FORM,
                'form:primarniPpv',
                $this->bool($employment['primary'] ?? null, '10495') ? 'true' : 'false',
            );
            $form->appendChild($header);
            $form->appendChild($this->formBody($dom, $reportYear, $summary, $employment));
            $node->appendChild($form);
        }

        return $node;
    }

    /**
     * Formuláře v pořadí podání: jeden za pracovněprávní vztah, se souhrnem
     * osoby a rokem vykazovaného období (rozhoduje o 10311/10312).
     *
     * @param array<string,mixed> $payload
     * @return list<array{0:array<string,mixed>,1:array<string,mixed>,2:int}>
     */
    private function employmentForms(array $payload): array
    {
        $year = $this->periodYear($payload);
        $forms = [];
        foreach ($this->rows($payload['people'] ?? null) as $person) {
            $summary = $this->object($person['summary'] ?? null);
            $primaryCount = 0;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                if ($this->bool($employment['primary'] ?? null, '10495')) {
                    $primaryCount++;
                }
                $forms[] = [$summary, $employment, $year];
            }
            if ($primaryCount !== 1) {
                $this->invalid(
                    'jmhz_xml_primary_employment_invalid',
                    'Každá osoba musí mít právě jeden primární pracovněprávní vztah,'
                        . ' na kterém se vykazují souhrnná data zaměstnance.',
                );
            }
        }

        return $forms;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{0:array<string,mixed>,1:array<string,mixed>,2:int}>
     */
    private function correctionPeople(
        array $payload,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): array {
        if (count($envelope->formGuids) !== count($plan->forms)) {
            $this->invalid(
                'jmhz_content_correction_envelope_mismatch',
                'GUIDy obálky neodpovídají formulářům obsahové opravy.',
            );
        }
        $selected = [];
        foreach ($this->employmentForms($payload) as $pair) {
            $employmentId = $pair[1]['employment_id'] ?? null;
            if (!is_int($employmentId)) {
                continue;
            }
            $correction = $plan->formForEmployment($employmentId);
            if ($correction === null) {
                continue;
            }
            $correction->assertEnvelopeGuid($envelope->formGuid($employmentId));
            $selected[] = $pair;
        }
        if (count($selected) !== count($plan->forms)) {
            $this->invalid(
                'jmhz_content_correction_source_form_missing',
                'Nová příprava neobsahuje všechny formuláře vybrané pro obsahovou opravu.',
            );
        }

        return $selected;
    }

    /** @param array<string,mixed> $payload */
    private function periodYear(array $payload): int
    {
        return $this->int($this->object($payload['header'] ?? null)['year'] ?? null, '10011');
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function formBody(DOMDocument $dom, int $year, array $summary, array $employment): DOMElement
    {
        $selector = $this->object($employment['selector'] ?? null);

        return match ($selector['scenario_key'] ?? null) {
            'scenario_1' => $this->bezPriznaku($dom, $year, $summary, $employment),
            'scenario_3' => $this->cinnostKs($dom, $year, $summary, $employment),
            'scenario_4' => $this->vezen($dom, $year, $summary, $employment),
            'scenario_5' => $this->jinyPrijem($dom, $year, $summary, $employment),
            'scenario_6' => $this->mezinarodniPronajemSily($dom, $year, $summary, $employment),
            'scenario_8' => $this->odlozenyPrijem($dom, $year, $summary, $employment),
            default => $this->invalid(
                'jmhz_xml_scenario_unsupported',
                'Součást nepatří do podporovaného scénáře JMHZ.',
            ),
        };
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function bezPriznaku(DOMDocument $dom, int $year, array $summary, array $employment): DOMElement
    {
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:bezPriznaku');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $year, $summary, 'bezPriznaku'));
        }
        $node->appendChild($this->insurance($dom, $summary, $employment));
        $node->appendChild($this->position($dom, $employment, true));
        $node->appendChild($this->workMonth($dom, $employment));
        $node->appendChild($this->income($dom, $summary, $employment));
        $node->appendChild($this->wage($dom, $employment));

        return $node;
    }

    /**
     * Odložený příjem (scénář 8): typ 10548, souhrn, pojištění bez trvání
     * s ELDP po obdobích a daň. Typy 2, 3 a 6 blokuje už příprava; tady se
     * ověřuje jen číselník a jediné období, stejně jako ve verzi 2026.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function odlozenyPrijem(DOMDocument $dom, int $year, array $summary, array $employment): DOMElement
    {
        $deferred = $this->object($this->object($employment['eldp'] ?? null)['deferred_income'] ?? null);
        $type = $this->string($deferred['type'] ?? null, '10548');
        if (!in_array($type, ['1', '2', '3', '4', '5', '6'], true)) {
            $this->invalid(
                'jmhz_xml_deferred_income_type_invalid',
                'Typ odloženého příjmu musí být z číselníku ČSSZ (1 až 6).',
            );
        }
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odlozenyPrijem');
        $this->text($dom, $node, Jmhz2027Schema::NS_FORM, 'form:typ', $type);
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $year, $summary, 'odlozenyPrijem'));
        }
        $node->appendChild($this->insurance($dom, $summary, $employment, false, $deferred));
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
    }

    /**
     * Činnost K–S (scénář 3). Verze 2027 přidává průběh zaměstnání s příznakem
     * státního zástupce 10587; jen u něj (IN43) se vykazují dny v evidenčním
     * stavu, odpracované a placené neodpracované hodiny, tarifní mzda
     * a průměrný výdělek.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function cinnostKs(DOMDocument $dom, int $year, array $summary, array $employment): DOMElement
    {
        $selector = $this->object($employment['selector'] ?? null);
        if (!PayrollEmploymentJmhzActivityFamily::isCorporateBodyActivity($selector['activity_code'] ?? null)
            || ($selector['relationship_detail_code'] ?? null) !== '1'
        ) {
            $this->invalid(
                'jmhz_xml_scenario_3_profile_unsupported',
                'Větev činnost K–S podporuje druhy činnosti K a N až S'
                    . ' s bližším určením „žádné“.',
            );
        }
        $prosecutor = $this->bool(
            $employment[Jmhz2027DocumentContract::EMPLOYMENT_STATE_PROSECUTOR] ?? null,
            '10587',
        );
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:cinnostKS');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $year, $summary, 'cinnostKS'));
        }
        $node->appendChild($this->insurance($dom, $summary, $employment, true));
        $node->appendChild($this->position($dom, $employment, true));

        $work = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prubehZamestnani');
        $this->text($dom, $work, Jmhz2027Schema::NS_FORM, 'form:statniZastupce', $prosecutor ? 'true' : 'false');
        if ($prosecutor) {
            $values = self::reportedWorkedHours($this->reportedUnworkedHours($this->workSummaryValues($employment)));
            $days = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odpracovaneDny');
            $this->text(
                $dom,
                $days,
                Jmhz2027Schema::NS_FORM,
                'form:dnyEvidencniStav',
                (string) $this->int($values['evidence_days'] ?? null, '10265'),
            );
            $work->appendChild($days);
            $hours = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odpracovaneHodiny');
            $this->text(
                $dom,
                $hours,
                Jmhz2027Schema::NS_FORM,
                'form:pocet',
                $this->decimal($values['worked_millihours'] ?? null, 3, '10268'),
            );
            $work->appendChild($hours);
            if (is_int($values['unworked_paid_millihours'] ?? null)) {
                $unworked = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:neodpracovaneHodiny');
                $this->text(
                    $dom,
                    $unworked,
                    Jmhz2027Schema::NS_FORM,
                    'form:hodinyNeodpracNahrada',
                    $this->decimal($values['unworked_paid_millihours'], 3, '10276'),
                );
                $work->appendChild($unworked);
            }
        }
        $node->appendChild($work);
        $node->appendChild($this->income($dom, $summary, $employment));
        if ($prosecutor) {
            $earnings = $this->object($employment['earnings_by_attribute_czk'] ?? null);
            $wage = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mzda');
            $breakdown = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mzdaRozpad');
            $this->text(
                $dom,
                $breakdown,
                Jmhz2027Schema::NS_FORM,
                'form:tarif',
                (string) $this->signedInt($this->earning($earnings, '10329'), '10329'),
            );
            $wage->appendChild($breakdown);
            $wage->appendChild($this->averageEarning($dom, $employment));
            $node->appendChild($wage);
        }

        return $node;
    }

    /**
     * Vězeň (scénář 4). Proti verzi 2026 přibylo místo výkonu práce
     * (`vykonavanaPoziceCoreType`) a § 18 v samostatném bloku
     * `nemocenskePojisteni` (jen úhrn 10366).
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function vezen(DOMDocument $dom, int $year, array $summary, array $employment): DOMElement
    {
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vezen');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $year, $summary, 'vezen'));
        }
        $insurance = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:pojisteni');
        $insurance->appendChild($this->insuranceDuration($dom, $employment));
        $insurance->appendChild($this->eldpList($dom, $employment, true));
        $sickness = $this->sicknessInsurance($dom, $employment, true);
        if ($sickness !== null) {
            $insurance->appendChild($sickness);
        }
        $insurance->appendChild($this->employeeDiscounts($dom, $employment));
        $node->appendChild($insurance);
        $node->appendChild($this->position($dom, $employment, false));
        $work = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prubehZamestnani');
        $work->appendChild($this->workedHours(
            $dom,
            $employment,
            self::reportedWorkedHours($this->reportedUnworkedHours($this->workSummaryValues($employment))),
        ));
        $node->appendChild($work);
        $node->appendChild($this->income($dom, $summary, $employment));
        $earnings = $this->object($employment['earnings_by_attribute_czk'] ?? null);
        $wage = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mzda');
        $sicknessPay = $this->earning($earnings, '10342');
        if ($sicknessPay !== null) {
            $compensation = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:nahrady');
            $this->text(
                $dom,
                $compensation,
                Jmhz2027Schema::NS_FORM,
                'form:docasnaNeschopnost',
                (string) $this->signedInt($sicknessPay, '10342'),
            );
            $wage->appendChild($compensation);
        }
        $node->appendChild($wage);

        return $node;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function jinyPrijem(DOMDocument $dom, int $year, array $summary, array $employment): DOMElement
    {
        $this->assertWithoutInsurance($employment);
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:jinyPrijem');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $year, $summary, 'jinyPrijem'));
        }
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
    }

    /**
     * Mezinárodní pronájem pracovní síly (scénář 6). XSD 1.5 vede plný souhrn
     * (zúžení scénáře 12 z verze 2026 přešlo do kontrol 78 a 79) a místo
     * výkonu práce.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function mezinarodniPronajemSily(
        DOMDocument $dom,
        int $year,
        array $summary,
        array $employment,
    ): DOMElement {
        $this->assertWithoutInsurance($employment);
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mezinarodniPronajemSily');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $year, $summary, 'mezinarodniPronajemSily'));
        }
        $node->appendChild($this->position($dom, $employment, false));
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
    }

    /** @param array<string,mixed> $employment */
    private function assertWithoutInsurance(array $employment): void
    {
        $social = $this->object($employment['social_base'] ?? null);
        $contributions = $this->object($employment['social_contributions'] ?? null);
        foreach ([
            $social['assessment_base_czk'] ?? null,
            $contributions['employee_social_czk'] ?? null,
            $contributions['employer_social_czk'] ?? null,
        ] as $amount) {
            if (is_int($amount) && $amount !== 0) {
                $this->invalid(
                    'jmhz_scenario_social_insurance_unreportable',
                    'Formulář jiného příjmu a mezinárodního pronájmu pracovní síly'
                        . ' nenese pojištění (atributy 10477, 10370, 10481), vztah ale'
                        . ' vyměřovací základ nebo pojistné má.',
                );
            }
        }
    }

    /**
     * `identifikaceType` beze změny proti verzi 2026: větev OIČ + ID PPV,
     * nebo jmenná větev pro první hlášení.
     *
     * @param array<string,mixed> $employment
     */
    private function identification(DOMDocument $dom, array $employment): DOMElement
    {
        $identity = $this->object($employment['identity'] ?? null);
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:identifikace');
        $person = $identity['person_external_identifier'] ?? null;
        $employmentIdentifier = $identity['employment_external_identifier'] ?? null;
        if ($person !== null || $employmentIdentifier !== null) {
            $this->text($dom, $node, Jmhz2027Schema::NS_FORM, 'form:ikMpsv', $this->string($person, '10051'));
            $this->text(
                $dom,
                $node,
                Jmhz2027Schema::NS_FORM,
                'form:idPpv',
                $this->string($employmentIdentifier, '10228'),
            );

            return $node;
        }
        $selector = $this->object($employment['selector'] ?? null);
        foreach ([
            'form:prijmeni' => $this->identityName($identity['family_name'] ?? null, '10053'),
            'form:jmeno' => $this->identityName($identity['given_name'] ?? null, '10054'),
            'form:datumNarozeni' => $this->identityDate($identity['birth_date'] ?? null, '10056'),
            'form:datumNastupu' => $this->identityDate($identity['employment_start_date'] ?? null, '10223'),
            'form:druhCinnosti' => $this->identityActivity($selector['activity_code'] ?? null, '10239'),
        ] as $element => $value) {
            $this->text($dom, $node, Jmhz2027Schema::NS_FORM, $element, $value);
        }

        return $node;
    }

    private function identityName(mixed $value, string $attributeId): string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->identityUnresolved($attributeId);
        }

        return $value;
    }

    private function identityDate(mixed $value, string $attributeId): string
    {
        $text = $this->identityName($value, $attributeId);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $text) {
            $this->identityUnresolved($attributeId);
        }

        return $text;
    }

    private function identityActivity(mixed $value, string $attributeId): string
    {
        $text = $this->identityName($value, $attributeId);
        if (preg_match('/^([1-9][0-9]?|[A-Z]{1,2})$/D', $text) !== 1) {
            $this->identityUnresolved($attributeId);
        }

        return $text;
    }

    private function identityUnresolved(string $attributeId): never
    {
        $this->invalid(
            'jmhz_xml_identity_name_incomplete',
            "Zaměstnanec nemá od ČSSZ přidělené OIČ ani ID PPV, hlásí se proto "
                . "jménem — a k tomu chybí doložený atribut {$attributeId}.",
        );
    }

    /**
     * Souhrnná data zaměstnance podle formuláře (XSD 1.5):
     *
     * - bez příznaku a odložený příjem: `prijmyExtType`, záloha, roční úhrny,
     *   čistá mzda s výdělkem OZP a srážkami, zdravotní pojištění obou stran,
     * - činnost K–S: `prijmyExtType`, záloha, roční úhrny, zdravotní
     *   pojištění zaměstnance,
     * - vězeň: `prijmyCoreType`, záloha, roční úhrny, čistá mzda,
     * - jiný příjem: `prijmyCoreType`, záloha, roční úhrny,
     * - pronájem pracovní síly: `prijmyCoreType` + 10417, záloha, roční úhrny.
     *
     * @param array<string,mixed> $summary
     */
    private function employeeSummary(DOMDocument $dom, int $year, array $summary, string $form): DOMElement
    {
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:souhrnDataZec');
        $incomeTotal = $this->int($summary['income_total_czk'] ?? null, '10286');
        $node->appendChild($this->summaryIncome($dom, $summary, $form, $incomeTotal));
        $node->appendChild($this->advanceTax($dom, $summary, $incomeTotal));

        $annual = $this->object($summary['annual'] ?? null);
        if ($annual !== []) {
            $node->appendChild($this->annualTotals($dom, $year, $annual));
        }

        if (in_array($form, ['bezPriznaku', 'odlozenyPrijem', 'vezen'], true)) {
            $net = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mzdaCista');
            $this->text(
                $dom,
                $net,
                Jmhz2027Schema::NS_FORM,
                'form:mzdaCista',
                (string) $this->int($summary['net_income_czk'] ?? null, '10344'),
            );
            $this->text(
                $dom,
                $net,
                Jmhz2027Schema::NS_FORM,
                'form:srazkyZeMzdyEvidovany',
                $this->bool($summary['deductions_recorded'] ?? null, '10116') ? 'true' : 'false',
            );
            JmhzOzpWageXml2027Writer::append($dom, $net, $summary, $form);
            $node->appendChild($net);
        } else {
            JmhzOzpWageXml2027Writer::append($dom, $node, $summary, $form);
        }

        $healthAmounts = match ($form) {
            'bezPriznaku', 'odlozenyPrijem' => [
                'form:zdravPojZamestnavatel' => ['employer_health_czk', '10482'],
                'form:zdravPojZamestnanec' => ['employee_health_czk', '10371'],
            ],
            'cinnostKS' => ['form:zdravPojZamestnanec' => ['employee_health_czk', '10371']],
            default => [],
        };
        foreach ($healthAmounts as $element => [$key, $attributeId]) {
            $wrapper = $this->node($dom, Jmhz2027Schema::NS_FORM, $element);
            $this->text(
                $dom,
                $wrapper,
                Jmhz2027Schema::NS_FORM,
                'form:zdravotniPojisteni',
                (string) $this->int($summary[$key] ?? null, $attributeId),
            );
            $node->appendChild($wrapper);
        }

        return $node;
    }

    /**
     * Příjmy osoby: úhrn 10286, osvobozené 10289 (kontroly 97, 283) a podle
     * typu formuláře odměna nerezidenta 10308 a úhrn příspěvku zaměstnavatele
     * na spoření na stáří 10417. Při nulovém úhrnu je IN34 odebírá všechny.
     *
     * @param array<string,mixed> $summary
     */
    private function summaryIncome(DOMDocument $dom, array $summary, string $form, int $incomeTotal): DOMElement
    {
        $income = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prijmy');
        $this->text($dom, $income, Jmhz2027Schema::NS_FORM, 'form:zuctovanoCelkem', (string) $incomeTotal);
        $exemptIncome = $summary['exempt_income_czk'] ?? null;
        if ($exemptIncome !== null && $incomeTotal !== 0) {
            $exemptIncome = $this->int($exemptIncome, '10289');
            if ($exemptIncome > $incomeTotal) {
                $this->invalid(
                    'jmhz_xml_exempt_income_exceeds_total',
                    'Osvobozené příjmy nesmějí být vyšší než zúčtovaný příjem celkem.',
                );
            }
            $this->text($dom, $income, Jmhz2027Schema::NS_FORM, 'form:osvobozenoCelkem', (string) $exemptIncome);
        }
        $extended = in_array($form, ['bezPriznaku', 'odlozenyPrijem', 'cinnostKS'], true);
        $boardFee = $summary[Jmhz2027DocumentContract::SUMMARY_NONRESIDENT_BOARD_FEE] ?? null;
        if ($boardFee !== null) {
            $boardFee = $this->int($boardFee, '10308');
            if ($boardFee !== 0 && (!$extended || $incomeTotal === 0)) {
                $this->invalid(
                    Jmhz2027DocumentContract::INCONSISTENT_CODE,
                    'Odměnu člena orgánu nerezidenta (10308) tento formulář nebo měsíc'
                        . ' bez zúčtovaného příjmu nevede.',
                );
            }
            if ($boardFee !== 0) {
                $this->text($dom, $income, Jmhz2027Schema::NS_FORM, 'form:odmenaNerezident', (string) $boardFee);
            }
        }
        $contributions = $this->object($summary['employer_contributions_czk'] ?? null);
        if (array_key_exists('10417', $contributions) && $form !== 'vezen' && $form !== 'jinyPrijem') {
            $contribution = $this->signedInt($contributions['10417'], '10417');
            if ($incomeTotal !== 0) {
                $this->text(
                    $dom,
                    $income,
                    Jmhz2027Schema::NS_FORM,
                    'form:prispevekZelSporeniOsvob',
                    (string) $contribution,
                );
            }
        }

        return $income;
    }

    /**
     * Záloha na daň (`zalohaNaDanType` 1.5) - povinný blok každého souhrnu.
     *
     * Pořadí: 10579, záloha (10297, 10298, 10305, 10306), samostatný základ
     * § 6 odst. 4 (10307, 10309), prohlášení 10419 a s podepsaným prohlášením
     * blok `prohlaseniPoplatnikaDane` s datem podpisu 10575 (IN16), slevami,
     * slevou na studenta (IN39) a zvýhodněním na děti. Pravidla vynechání
     * nulové zálohy a bonusu (kontroly 243, 244) jsou stejná jako ve verzi 2026.
     *
     * @param array<string,mixed> $summary
     */
    private function advanceTax(DOMDocument $dom, array $summary, int $incomeTotal): DOMElement
    {
        $tax = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:zalohaNaDan');
        $this->text(
            $dom,
            $tax,
            Jmhz2027Schema::NS_FORM,
            'form:zalohaPar38',
            $this->bool($summary[Jmhz2027DocumentContract::SUMMARY_MULTI_MONTH_ADVANCE] ?? null, '10579')
                ? 'true'
                : 'false',
        );
        $declarationSigned = $this->bool($summary['taxpayer_declaration_signed'] ?? null, '10419');
        $nonResident = ($summary['tax_residence'] ?? null) === TaxResidence::NonResident->value;
        $advance = $this->object($summary['advance_tax_czk'] ?? null);
        $advanceIsEmpty = true;
        foreach (['base', 'computed', 'after_credits', 'bonus'] as $key) {
            if ($this->int($advance[$key] ?? null, '10297') !== 0) {
                $advanceIsEmpty = false;
                break;
            }
        }
        if (!$advanceIsEmpty) {
            foreach ([
                'form:zakladDane' => ['base', '10297'],
                'form:vypoctenaZaloha' => ['computed', '10298'],
                'form:danZalohaPoSleve' => ['after_credits', '10305'],
                'form:danBonus' => ['bonus', '10306'],
            ] as $element => [$key, $attributeId]) {
                $value = $this->int($advance[$key] ?? null, $attributeId);
                if ($element === 'form:danBonus') {
                    if ($value !== 0 && ($nonResident || !$declarationSigned)) {
                        $this->invalid(
                            'jmhz_xml_bonus_without_declaration',
                            'Měsíční daňový bonus nelze vykázat bez podepsaného prohlášení poplatníka.',
                        );
                    }
                    if ($value === 0) {
                        continue;
                    }
                }
                $this->text($dom, $tax, Jmhz2027Schema::NS_FORM, $element, (string) $value);
            }
        }

        $paragraph6 = $summary[Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX] ?? null;
        if ($paragraph6 !== null) {
            $paragraph6 = $this->object($paragraph6);
            $base = $this->int($paragraph6['base'] ?? null, '10307');
            $withheld = $this->int($paragraph6['withheld'] ?? null, '10309');
            if ($incomeTotal === 0 && ($base !== 0 || $withheld !== 0)) {
                $this->invalid(
                    Jmhz2027DocumentContract::INCONSISTENT_CODE,
                    'Samostatný základ § 6 odst. 4 (10307, 10309) nelze vykázat v měsíci'
                        . ' bez zúčtovaného příjmu.',
                );
            }
            if ($base !== 0 || $withheld !== 0) {
                $this->text($dom, $tax, Jmhz2027Schema::NS_FORM, 'form:zakladDanePar6', (string) $base);
                $this->text($dom, $tax, Jmhz2027Schema::NS_FORM, 'form:srazenaDanPar6', (string) $withheld);
            }
        }

        $this->text(
            $dom,
            $tax,
            Jmhz2027Schema::NS_FORM,
            'form:prohlaseniPoplatnika',
            $declarationSigned ? 'true' : 'false',
        );

        $credits = $this->object($summary['tax_credits_czk'] ?? null);
        $claimed = array_filter(
            [
                'form:zakladniSleva' => ['basic', '10299'],
                'form:zakladniSlevaInvalidita12' => ['disability_basic', '10300'],
                'form:rozsirenaSlevaInvalidita3' => ['disability_extended', '10301'],
                'form:slevaZTPP' => ['ztp_p', '10302'],
            ],
            static fn (array $pair): bool => ($credits[$pair[0]] ?? null) !== null,
        );
        $childCredit = $summary['child_credit'] ?? null;
        $childCredit = $childCredit === null ? null : $this->object($childCredit);
        $student = $summary[Jmhz2027DocumentContract::SUMMARY_STUDENT_CREDIT] ?? null;
        if (!$declarationSigned) {
            if ($claimed !== [] || $childCredit !== null || $student !== null) {
                $this->invalid(
                    'jmhz_xml_credit_without_declaration',
                    'Uplatněnou slevu na dani nelze vykázat bez podepsaného prohlášení poplatníka.',
                );
            }

            return $tax;
        }

        $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prohlaseniPoplatnikaDane');
        $this->text(
            $dom,
            $block,
            Jmhz2027Schema::NS_FORM,
            'form:datumPodpisu',
            $this->date($summary[Jmhz2027DocumentContract::SUMMARY_DECLARATION_SIGNED_ON] ?? null, '10575'),
        );
        foreach ($claimed as $element => [$key, $attributeId]) {
            $this->text(
                $dom,
                $block,
                Jmhz2027Schema::NS_FORM,
                $element,
                (string) $this->int($credits[$key] ?? null, $attributeId),
            );
        }
        if ($student !== null) {
            $student = $this->object($student);
            $studentNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:slevaStudent');
            $amount = $this->int($student['amount_czk'] ?? null, '10565');
            if ($amount > 999_999) {
                $this->unresolved('10565');
            }
            $this->text($dom, $studentNode, Jmhz2027Schema::NS_FORM, 'form:vyseSlevy', (string) $amount);
            $this->text(
                $dom,
                $studentNode,
                Jmhz2027Schema::NS_FORM,
                'form:doktorant',
                $this->bool($student['doctoral'] ?? null, '10566') ? 'true' : 'false',
            );
            $block->appendChild($studentNode);
        }
        if ($childCredit !== null) {
            $this->monthlyChildCredit($dom, $block, $childCredit);
        }
        $tax->appendChild($block);

        return $tax;
    }

    /**
     * Roční úhrny (`rocniUhrnyType` 1.5). 10311 a 10312 se za vykazovaná
     * období roku 2027 nevyplňují (aktualita MPSV 7. 10. 2026, poprvé za
     * leden 2028); 10313 až 10318 nepíše ani verze 2026. Výsledek ročního
     * zúčtování je nezáporný a musí platit 10321 = 10322 + 10323 (kontrola 78).
     *
     * @param array<string,mixed> $annual
     */
    private function annualTotals(DOMDocument $dom, int $year, array $annual): DOMElement
    {
        $annualNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:rocniUhrny');
        $withholding = $this->object($annual['withholding'] ?? null);
        if ($withholding !== []
            && sprintf('%04d-01', $year) >= Jmhz2027DocumentContract::ANNUAL_WITHHOLDING_FIRST_PERIOD
        ) {
            $this->text(
                $dom,
                $annualNode,
                Jmhz2027Schema::NS_FORM,
                'form:prijemSrazkDanZvlSazba',
                (string) $this->int($withholding['paid_income_czk'] ?? null, '10311'),
            );
            $this->text(
                $dom,
                $annualNode,
                Jmhz2027Schema::NS_FORM,
                'form:danSrazenaZvlSazba',
                (string) $this->int($withholding['withholding_tax_czk'] ?? null, '10312'),
            );
        }
        $bonusTotal = $annual[Jmhz2027DocumentContract::ANNUAL_MONTHLY_BONUS_TOTAL] ?? null;
        if ($bonusTotal !== null) {
            $this->text(
                $dom,
                $annualNode,
                Jmhz2027Schema::NS_FORM,
                'form:uhrnBonusu',
                (string) $this->int($bonusTotal, '10577'),
            );
        }
        if (is_bool($annual['requested'] ?? null)) {
            $this->text(
                $dom,
                $annualNode,
                Jmhz2027Schema::NS_FORM,
                'form:rocniZuctovaniZadost',
                $annual['requested'] ? 'true' : 'false',
            );
        }
        $performed = $this->bool($annual['performed'] ?? null, '10320');
        $this->text(
            $dom,
            $annualNode,
            Jmhz2027Schema::NS_FORM,
            'form:rocniZuctovaniProvedeno',
            $performed ? 'true' : 'false',
        );
        if (!$performed) {
            return $annualNode;
        }
        $result = $this->object($annual['result'] ?? null);
        $total = $this->signedInt($result['settlement_difference_czk'] ?? null, '10321');
        $tax = $this->signedInt($result['tax_difference_czk'] ?? null, '10322');
        $bonus = $this->signedInt($result['bonus_difference_czk'] ?? null, '10323');
        if ($total < 0 || $tax < 0 || $bonus < 0) {
            $this->invalid(
                Jmhz2027DocumentContract::INCONSISTENT_CODE,
                'Výsledek ročního zúčtování se ve verzi 2027 vykazuje nezáporně (10321, 10322,'
                    . ' 10323); přeplacený bonus nemá v datové větě místo.',
            );
        }
        if ($total !== $tax + $bonus) {
            $this->invalid(
                Jmhz2027DocumentContract::INCONSISTENT_CODE,
                'Doplatek z ročního zúčtování (10321) musí být součtem přeplatku na dani (10322)'
                    . ' a doplatku bonusu (10323), kontrola 78.',
            );
        }
        $resultNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vysledekRocnihoZuctovani');
        $this->text($dom, $resultNode, Jmhz2027Schema::NS_FORM, 'form:doplatekRok', (string) $total);
        $this->text($dom, $resultNode, Jmhz2027Schema::NS_FORM, 'form:danPreplatekRok', (string) $tax);
        $this->text($dom, $resultNode, Jmhz2027Schema::NS_FORM, 'form:danBonusPreplatekRok', (string) $bonus);
        $this->text(
            $dom,
            $resultNode,
            Jmhz2027Schema::NS_FORM,
            'form:uplatnenaSlevaNaPartnera',
            $this->bool($result['spouse_credit_claimed'] ?? null, '10420') ? 'true' : 'false',
        );
        if (($result['spouse_credit_claimed'] ?? null) === true) {
            $this->annualSpouseCredit($dom, $resultNode, $result);
        }
        $this->text(
            $dom,
            $resultNode,
            Jmhz2027Schema::NS_FORM,
            'form:uplatnenoZvyhodneniNaDeti',
            $this->bool($result['child_credit_claimed'] ?? null, '10454') ? 'true' : 'false',
        );
        if (($result['child_credit_claimed'] ?? null) === true) {
            $this->annualChildCredit($dom, $resultNode, $result);
        }
        $annualNode->appendChild($resultNode);

        return $annualNode;
    }

    /** @param array<string,mixed> $result */
    private function annualSpouseCredit(DOMDocument $dom, DOMElement $resultNode, array $result): void
    {
        $details = $this->object($result['spouse_credit_details'] ?? null);
        $children = $this->rows($details['children'] ?? null);
        if ($details === [] || $children === []) {
            $this->unresolved('10421');
        }
        $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:slevaNaPartnera');
        $partner = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:partner');
        $partner->appendChild($this->annualPerson(
            $dom,
            $this->object($details['partner'] ?? null),
            'form:partnerUdaje',
            ['10421', '10422', '10424', '10423'],
        ));
        $childList = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:deti');
        foreach ($children as $child) {
            $childList->appendChild($this->annualPerson(
                $dom,
                $child,
                'form:dite',
                ['10539', '10540', '10541', '10542'],
            ));
        }
        $partner->appendChild($childList);
        $ztpP = $this->bool($details['ztp_p'] ?? null, '10425');
        $this->text($dom, $partner, Jmhz2027Schema::NS_FORM, 'form:prukazZtpp', $ztpP ? 'true' : 'false');
        $this->text(
            $dom,
            $partner,
            Jmhz2027Schema::NS_FORM,
            'form:slevaPocetMesicu',
            (string) $this->int($details['months'] ?? null, '10426'),
        );
        if ($ztpP) {
            $this->text(
                $dom,
                $partner,
                Jmhz2027Schema::NS_FORM,
                'form:slevaPocetMesicuZtpp',
                (string) $this->int($details['ztp_p_months'] ?? null, '10430'),
            );
        }
        $block->appendChild($partner);
        $resultNode->appendChild($block);
    }

    /** @param array<string,mixed> $result */
    private function annualChildCredit(DOMDocument $dom, DOMElement $resultNode, array $result): void
    {
        $details = $this->object($result['child_credit_details'] ?? null);
        $children = $this->rows($details['children'] ?? null);
        if ($children === []) {
            $this->unresolved('10446');
        }
        $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:zvyhodneniNaDeti');
        $other = $this->bool($details['other_household_caregiver'] ?? null, '10455');
        $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:vyzivujeJinaOsoba', $other ? 'true' : 'false');
        if ($other) {
            $caregivers = $this->rows($details['other_household_caregivers'] ?? null);
            if ($caregivers === []) {
                $this->unresolved('10441');
            }
            $caregiverList = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:jineOsoby');
            foreach ($caregivers as $caregiver) {
                $caregiverNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:jinaOsoba');
                $caregiverNode->appendChild($this->annualPerson(
                    $dom,
                    $this->object($caregiver['identity'] ?? null),
                    'form:osoba',
                    ['10441', '10442', '10443', '10444'],
                ));
                $this->text(
                    $dom,
                    $caregiverNode,
                    Jmhz2027Schema::NS_FORM,
                    'form:mesiceVyzivovani',
                    $this->string($caregiver['months_mask'] ?? null, '10445'),
                );
                $caregiverList->appendChild($caregiverNode);
            }
            $block->appendChild($caregiverList);
        }
        $childList = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vyzivovaneDeti');
        foreach ($children as $child) {
            $childNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vyzivovaneDite');
            $childNode->appendChild($this->annualPerson(
                $dom,
                $this->object($child['identity'] ?? null),
                'form:dite',
                ['10446', '10447', '10448', '10449'],
            ));
            $ztp = $this->string($child['ztp_p_months_mask'] ?? null, '10450');
            if ($ztp !== 'NNNNNNNNNNNN') {
                $this->text($dom, $childNode, Jmhz2027Schema::NS_FORM, 'form:prukazZtpp', $ztp);
            }
            $this->text(
                $dom,
                $childNode,
                Jmhz2027Schema::NS_FORM,
                'form:poradi',
                $this->string($child['order_months_mask'] ?? null, '10451'),
            );
            $childList->appendChild($childNode);
        }
        $block->appendChild($childList);
        $resultNode->appendChild($block);
    }

    /**
     * Měsíční zvýhodnění na děti uvnitř `prohlaseniPoplatnikaDane`, beze změny
     * proti verzi 2026.
     *
     * @param array<string,mixed> $childCredit
     */
    private function monthlyChildCredit(DOMDocument $dom, DOMElement $block, array $childCredit): void
    {
        $this->text(
            $dom,
            $block,
            Jmhz2027Schema::NS_FORM,
            'form:danoveZvyhodneniDetiMesic',
            (string) $this->int($childCredit['monthly_credit_czk'] ?? null, '10303'),
        );
        $children = $this->rows($childCredit['children'] ?? null);
        if ($children === []) {
            $this->invalid(
                'jmhz_xml_child_credit_without_children',
                'Měsíční zvýhodnění na děti nelze vykázat bez vyživovaných dětí.',
            );
        }
        $childBlock = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:zvyhodneniDetiMesic');
        $otherCaregiver = $this->bool($childCredit['other_household_caregiver'] ?? null, '10453');
        $this->text(
            $dom,
            $childBlock,
            Jmhz2027Schema::NS_FORM,
            'form:vyzivujeJinaOsoba',
            $otherCaregiver ? 'true' : 'false',
        );
        if ($otherCaregiver) {
            $caregiverList = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:jineOsoby');
            foreach ($this->rows($childCredit['other_household_caregivers'] ?? null) as $caregiver) {
                $caregiverList->appendChild($this->annualPerson(
                    $dom,
                    $caregiver,
                    'form:jinaOsoba',
                    ['10431', '10432', '10433', '10434'],
                ));
            }
            $childBlock->appendChild($caregiverList);
        }
        $childList = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vyzivovaneDeti');
        foreach ($children as $child) {
            $childNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vyzivovaneDite');
            $childNode->appendChild($this->annualPerson(
                $dom,
                $this->object($child['identity'] ?? null),
                'form:dite',
                ['10435', '10436', '10437', '10438'],
            ));
            $this->text(
                $dom,
                $childNode,
                Jmhz2027Schema::NS_FORM,
                'form:prukazZtpp',
                $this->bool($child['ztp_p'] ?? null, '10439') ? 'true' : 'false',
            );
            $this->text(
                $dom,
                $childNode,
                Jmhz2027Schema::NS_FORM,
                'form:poradi',
                $this->string($child['order'] ?? null, '10440'),
            );
            $childList->appendChild($childNode);
        }
        $childBlock->appendChild($childList);
        $block->appendChild($childBlock);
        $this->text(
            $dom,
            $block,
            Jmhz2027Schema::NS_FORM,
            'form:slevaDite',
            (string) $this->int($childCredit['applied_credit_czk'] ?? null, '10304'),
        );
    }

    /**
     * @param array<string,mixed> $identity
     * @param array{string,string,string,string} $attributeIds jméno, příjmení, datum narození, rodné číslo
     */
    private function annualPerson(DOMDocument $dom, array $identity, string $element, array $attributeIds): DOMElement
    {
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, $element);
        foreach ([
            'form:jmeno' => ['given_name', $attributeIds[0]],
            'form:prijmeni' => ['family_name', $attributeIds[1]],
        ] as $name => [$key, $attributeId]) {
            $this->text($dom, $node, Jmhz2027Schema::NS_FORM, $name, $this->string($identity[$key] ?? null, $attributeId));
        }
        if (($identity['birth_date'] ?? null) !== null) {
            $this->text(
                $dom,
                $node,
                Jmhz2027Schema::NS_FORM,
                'form:datumNarozeni',
                $this->date($identity['birth_date'], $attributeIds[2]),
            );
        }
        if (($identity['birth_number'] ?? null) !== null) {
            $this->text(
                $dom,
                $node,
                Jmhz2027Schema::NS_FORM,
                'form:rodneCislo',
                $this->string($identity['birth_number'], $attributeIds[3]),
            );
        }

        return $node;
    }

    /**
     * Pojištění formuláře bez příznaku, činnosti K–S a odloženého příjmu.
     * Pořadí: trvání (ne u odloženého příjmu), vyměřovací základ (10477,
     * 10476), rozpad § 5a (ne u K–S), ELDP, `nemocenskePojisteni`, pojistné
     * zaměstnance a zaměstnavatele, sleva zaměstnance, sleva zaměstnavatele
     * (ne u K–S; ve verzi 2027 povinná).
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     * @param array<string,mixed>|null $deferred
     */
    private function insurance(
        DOMDocument $dom,
        array $summary,
        array $employment,
        bool $cinnostKs = false,
        ?array $deferred = null,
    ): DOMElement {
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:pojisteni');
        if ($deferred === null) {
            $node->appendChild($this->insuranceDuration($dom, $employment));
        }

        $social = $this->object($employment['social_base'] ?? null);
        $amount = is_int($social['assessment_base_czk'] ?? null)
            ? $this->int($social['assessment_base_czk'], '10477')
            : null;
        $reportedIncome = is_int($social['reported_income_czk'] ?? null)
            ? $this->int($social['reported_income_czk'], '10476')
            : null;
        if ($amount !== null || $reportedIncome !== null) {
            $base = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vymerovaciZaklad');
            if ($amount !== null) {
                $this->text($dom, $base, Jmhz2027Schema::NS_FORM, 'form:castkaOdvodPojistneho', (string) $amount);
            }
            if ($reportedIncome !== null) {
                $this->text($dom, $base, Jmhz2027Schema::NS_FORM, 'form:zapocitatelnyPrijem', (string) $reportedIncome);
            }
            $node->appendChild($base);
        }

        if (!$cinnostKs && $amount !== null && $amount > 0) {
            $letter = $social['paragraph5_letter'] ?? null;
            if (!is_string($letter) || !isset(self::PARAGRAPH5_ELEMENTS[$letter])) {
                $this->invalid(
                    'jmhz_xml_employer_rate_category_unknown',
                    'Bez sazbové kategorie zaměstnavatele nelze vyměřovací základ'
                        . ' rozdělit podle § 5a odst. 1 ZPSZ.',
                );
            }
            $split = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vymerovaciZakladParagraf5');
            $this->text($dom, $split, Jmhz2027Schema::NS_FORM, self::PARAGRAPH5_ELEMENTS[$letter], (string) $amount);
            $node->appendChild($split);
        }

        $list = $this->eldpList($dom, $employment);
        if ($deferred === null) {
            $node->appendChild($list);
        } else {
            $periods = $this->rows($deferred['periods'] ?? null);
            if (count($periods) !== 1) {
                $this->invalid(
                    'jmhz_xml_deferred_income_periods_unsupported',
                    'Odložený příjem nese právě jedno období ELDP.',
                );
            }
            $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:eldpObdobi');
            $period = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:obdobi');
            $month = $this->int($periods[0]['month'] ?? null, '10537');
            if ($month < 1 || $month > 12) {
                $this->invalid(
                    'jmhz_xml_deferred_income_period_invalid',
                    'Měsíc odloženého příjmu musí být 1 až 12.',
                );
            }
            $this->text($dom, $period, Jmhz2027Schema::NS_FORM, 'form:mesic', (string) $month);
            $this->text(
                $dom,
                $period,
                Jmhz2027Schema::NS_FORM,
                'form:rok',
                (string) $this->int($periods[0]['year'] ?? null, '10538'),
            );
            $period->appendChild($list);
            $block->appendChild($period);
            $node->appendChild($block);
        }

        $sickness = $this->sicknessInsurance($dom, $employment, false);
        if ($sickness !== null) {
            $node->appendChild($sickness);
        }

        // 10370 a 10481 jsou povinné na KAŽDÉM formuláři s blokem pojištění
        // (scénáře 1.5.0.3 je vedou jako „P", Pokyny MH 1.4.14 kap. 3.6.7
        // a 3.6.8 jako „Povinný údaj"). Formulář bez účasti na pojištění
        // (DPP/DPČ pod hranicí, vztah v souběhu, který pojistné osoby nenese)
        // je proto vykazuje nulou stejně jako verze 2026
        // (JmhzScenario1XmlSerializer, oprava fix/jmhz-dpp-pojistne-nula).
        $reportsSocial = ($employment['reports_social_contributions'] ?? true) === true;
        $ownContributions = $this->object($employment['social_contributions'] ?? null);
        foreach ([
            'form:pojisteniZamestnanec' => ['employee_social_czk', '10370'],
            'form:pojisteniZamestnavatel' => ['employer_social_czk', '10481'],
        ] as $element => [$key, $attributeId]) {
            $value = match (true) {
                $ownContributions !== [] => $ownContributions[$key] ?? null,
                $reportsSocial => $summary[$key] ?? null,
                default => 0,
            };
            if (!is_int($value)) {
                continue;
            }
            $wrapper = $this->node($dom, Jmhz2027Schema::NS_FORM, $element);
            $this->text(
                $dom,
                $wrapper,
                Jmhz2027Schema::NS_FORM,
                'form:socialniPojisteni',
                (string) $this->int($value, $attributeId),
            );
            $node->appendChild($wrapper);
        }

        $node->appendChild($this->employeeDiscounts($dom, $employment));

        if (!$cinnostKs) {
            $discount = $this->object($employment['part_time_discount'] ?? null);
            $wrapper = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:slevaZamestnavatele');
            $this->text(
                $dom,
                $wrapper,
                Jmhz2027Schema::NS_FORM,
                'form:slevaZamestnavateleEvidovana',
                $discount === [] ? 'false' : 'true',
            );
            if ($discount !== []) {
                $split = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:slevaZamestnavateleRozpad');
                $centihours = $discount['weekly_working_time_centihours'] ?? null;
                if ($centihours !== null) {
                    $this->text(
                        $dom,
                        $split,
                        Jmhz2027Schema::NS_FORM,
                        'form:pracovniDobaKratsi',
                        $this->decimal($centihours, 2, '10373'),
                    );
                }
                $this->text(
                    $dom,
                    $split,
                    Jmhz2027Schema::NS_FORM,
                    'form:duvodUplatneni',
                    $this->string($discount['reason_code'] ?? null, '10374'),
                );
                $wrapper->appendChild($split);
            }
            $node->appendChild($wrapper);
        }

        return $node;
    }

    /** @param array<string,mixed> $employment */
    private function insuranceDuration(DOMDocument $dom, array $employment): DOMElement
    {
        $interval = $this->object($this->object($employment['eldp'] ?? null)['insurance_interval'] ?? null);
        $duration = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:trvani');
        $this->text(
            $dom,
            $duration,
            Jmhz2027Schema::NS_FORM,
            'form:pojisteniOd',
            $this->date($interval['insurance_from'] ?? null, '10354'),
        );
        $this->text(
            $dom,
            $duration,
            Jmhz2027Schema::NS_FORM,
            'form:pojisteniDo',
            $this->date($interval['insurance_to'] ?? null, '10355'),
        );

        return $duration;
    }

    /**
     * Seznam ELDP sekcí. Ve verzi 2027 sekce nese jen vyloučené DOBY § 16
     * odst. 4 a odečítané doby; vyloučené DNY § 18 jsou v
     * {@see self::sicknessInsurance()}.
     *
     * @param array<string,mixed> $employment
     */
    private function eldpList(DOMDocument $dom, array $employment, bool $prisoner = false): DOMElement
    {
        $sections = $this->rows($this->object($employment['eldp'] ?? null)['eldp_sections'] ?? null);
        if ($sections === []) {
            $this->invalid('jmhz_xml_eldp_missing', 'Součást musí obsahovat alespoň jednu ELDP sekci.');
        }
        $list = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:eldpSeznam');
        foreach ($sections as $section) {
            $entry = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:eldp');
            $code = $section['code'] ?? null;
            $hasCode = is_string($code) && $code !== '';
            if ($hasCode) {
                $this->text($dom, $entry, Jmhz2027Schema::NS_FORM, 'form:kod', $code);
                $this->text(
                    $dom,
                    $entry,
                    Jmhz2027Schema::NS_FORM,
                    'form:platnostOd',
                    $this->date($section['valid_from'] ?? null, '10241'),
                );
                $this->text(
                    $dom,
                    $entry,
                    Jmhz2027Schema::NS_FORM,
                    'form:platnostDo',
                    $this->date($section['valid_to'] ?? null, '10242'),
                );
            }
            $days = $this->int($section['insurance_days'] ?? null, '10356');
            $this->text($dom, $entry, Jmhz2027Schema::NS_FORM, 'form:pocetDnu', (string) $days);
            if ($days > 0 && !$hasCode) {
                $this->invalid(
                    'jmhz_xml_eldp_code_required',
                    'ELDP sekce s nenulovým počtem dnů musí mít kód ELDP.',
                );
            }
            if (is_int($section['assessment_base_czk'] ?? null)) {
                $this->text(
                    $dom,
                    $entry,
                    Jmhz2027Schema::NS_FORM,
                    'form:vymerovaciZaklad',
                    (string) $this->int($section['assessment_base_czk'], '10245'),
                );
            }
            $this->appendEldpExcludedDays($dom, $entry, $section, $hasCode, $prisoner);
            $this->appendEldpDeductedDays($dom, $entry, $section, $code, $prisoner);
            $list->appendChild($entry);
        }

        return $list;
    }

    /**
     * Vyloučené doby § 16 odst. 4 (10357 a rozpad). Úhrn je v bloku povinný;
     * rozpad jen při kladném úhrnu (kontroly 121, 329). Sekce bez kódu ELDP
     * vyloučené doby nést nesmí (kontrola 307). Vězeň vede jen DPN a PPM.
     *
     * @param array<string,mixed> $section
     */
    private function appendEldpExcludedDays(
        DOMDocument $dom,
        DOMElement $entry,
        array $section,
        bool $hasCode,
        bool $prisoner,
    ): void {
        $total = $section['excluded_days_total'] ?? null;
        $components = $section['excluded_days'] ?? null;
        if ($total === null && ($components === null || $components === [])) {
            return;
        }
        if (!$hasCode) {
            if ($this->hasNonZero($components) || ($total !== null && $total !== 0)) {
                $this->invalid(
                    'jmhz_xml_eldp_excluded_days_without_code',
                    'Vyloučené doby nelze vykázat v ELDP sekci bez kódu ELDP.',
                );
            }

            return;
        }
        $total = $this->int($total, '10357');
        if (!is_array($components) || array_is_list($components)) {
            $this->unresolved('10357');
        }
        $keys = $prisoner
            ? array_intersect_key(self::ELDP_EXCLUDED_DAYS, ['docasNeschopnost' => true, 'penezitaPomocMaterstvi' => true])
            : self::ELDP_EXCLUDED_DAYS;
        if ($prisoner) {
            foreach (array_diff_key(self::ELDP_EXCLUDED_DAYS, $keys) as $key => $attributeId) {
                if (($components[$key] ?? 0) !== 0) {
                    $this->invalid(
                        'jmhz_xml_prisoner_excluded_days_unsupported',
                        "Formulář vězně nemá kam zapsat vyloučené doby atributu {$attributeId}.",
                    );
                }
            }
        }
        $values = [];
        foreach ($keys as $key => $attributeId) {
            $values[$key] = $this->int($components[$key] ?? ($prisoner ? 0 : null), $attributeId);
        }
        if (array_sum($values) !== $total
            || (!$prisoner && count($components) !== count($values))
        ) {
            $this->invalid(
                'jmhz_xml_eldp_excluded_days_sum_mismatch',
                'Úhrn vyloučených dob neodpovídá rozpadu podle § 16 odst. 4 zákona č. 155/1995 Sb.',
            );
        }
        $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vylouceneDny');
        $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:vylouceneDobyCelkem', (string) $total);
        if ($total > 0) {
            foreach ($values as $key => $value) {
                $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:' . $key, (string) $value);
            }
        }
        $entry->appendChild($block);
    }

    /**
     * Odečítané doby (10375, 10462–10469, IN04), beze změny proti verzi 2026.
     *
     * @param array<string,mixed> $section
     */
    private function appendEldpDeductedDays(
        DOMDocument $dom,
        DOMElement $entry,
        array $section,
        mixed $code,
        bool $prisoner,
    ): void {
        $total = $section['deducted_days_total'] ?? null;
        if ($total === null) {
            return;
        }
        $total = $this->int($total, '10375');
        if ($total === 0) {
            return;
        }
        if (!is_string($code) || strlen($code) < 2 || $code[strlen($code) - 2] !== 'D') {
            $this->invalid(
                'jmhz_xml_eldp_deducted_days_without_code',
                'Odečítané doby patří jen do ELDP sekce s kódem D (po dovršení důchodového věku).',
            );
        }
        $attributes = [
            'pracovniNeschopnost' => '10462',
            'materstvi' => '10463',
            'osetrovaniSNarokem' => '10464',
            'osetrovaniBezNaroku' => '10465',
            'otcovska' => '10466',
            'neplaceneVolno' => '10468',
            'neomluveneAbsence' => '10469',
        ];
        $components = $section['deducted_days'] ?? null;
        if (!is_array($components) || array_is_list($components)
            || array_diff_key($components, $attributes) !== []
        ) {
            $this->unresolved('10375');
        }
        $values = [];
        foreach ($attributes as $key => $attributeId) {
            $value = $this->int($components[$key] ?? 0, $attributeId);
            if ($value === 0) {
                continue;
            }
            if ($prisoner && !in_array($key, ['pracovniNeschopnost', 'materstvi'], true)) {
                $this->invalid(
                    'jmhz_xml_prisoner_deducted_days_unsupported',
                    "Formulář vězně nemá kam zapsat odečítané doby atributu {$attributeId}.",
                );
            }
            $values[$key] = $value;
        }
        if ($values === [] || array_sum($values) !== $total) {
            $this->invalid(
                'jmhz_xml_eldp_deducted_days_sum_mismatch',
                'Úhrn odečítaných dob (10375) neodpovídá součtu složek 10462–10469.',
            );
        }
        $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odecitaneDny');
        $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:odecitaneDobyCelkem', (string) $total);
        foreach ($values as $key => $value) {
            $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:' . $key, (string) $value);
        }
        $entry->appendChild($block);
    }

    /**
     * Vyloučené dny § 18 odst. 7 zákona č. 187/2006 Sb. (`nemocenskePojisteni`,
     * IN40): úhrn 10366 a rozpad 10473–10475 (vězeň jen úhrn).
     *
     * Zdroj je klíč {@see Jmhz2027DocumentContract::EMPLOYMENT_SICKNESS_INSURANCE}.
     * Dokud ho resolver nenese, sečtou se hodnoty sekcí ELDP: sekce dělí měsíc
     * na disjunktní části a verze 2026 je vykazovala po sekcích. Nese-li
     * některá sekce úhrn bez rozpadu, blok se vynechá stejně jako ve verzi 2026
     * (vykázat část by tvrdilo, že zbytek je nula). Nulový úhrn se nevykazuje.
     *
     * @param array<string,mixed> $employment
     */
    private function sicknessInsurance(DOMDocument $dom, array $employment, bool $prisoner): ?DOMElement
    {
        $key = Jmhz2027DocumentContract::EMPLOYMENT_SICKNESS_INSURANCE;
        if (array_key_exists($key, $employment)) {
            $source = $employment[$key];
            if ($source === null) {
                return null;
            }
            $source = $this->object($source);
            $total = $this->int($source['excluded_days_total'] ?? null, '10366');
            $components = $source['excluded_days'] ?? null;
            if (!$prisoner && (!is_array($components) || array_is_list($components))) {
                $this->unresolved('10473');
            }
        } else {
            $total = null;
            $components = [];
            foreach ($this->rows($this->object($employment['eldp'] ?? null)['eldp_sections'] ?? null) as $section) {
                $sectionTotal = $section['section18_days_total'] ?? null;
                if ($sectionTotal === null) {
                    continue;
                }
                $total = ($total ?? 0) + $this->int($sectionTotal, '10366');
                if ($prisoner) {
                    continue;
                }
                $sectionComponents = $section['section18_days'] ?? null;
                if (!is_array($sectionComponents) || array_is_list($sectionComponents)) {
                    return null;
                }
                foreach (self::SECTION18_DAYS as $name => $attributeId) {
                    $components[$name] = ($components[$name] ?? 0)
                        + $this->int($sectionComponents[$name] ?? null, $attributeId);
                }
                if (count($sectionComponents) !== count(self::SECTION18_DAYS)) {
                    $this->invalid(
                        'jmhz_xml_eldp_section18_days_sum_mismatch',
                        'Úhrn vyloučených dnů neodpovídá rozpadu podle § 18 odst. 7 zákona č. 187/2006 Sb.',
                    );
                }
            }
            if ($total === null) {
                return null;
            }
        }
        if ($total === 0) {
            return null;
        }
        if ($total > 999) {
            $this->unresolved('10366');
        }
        $block = $this->node(
            $dom,
            Jmhz2027Schema::NS_FORM,
            'form:nemocenskePojisteni',
        );
        $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:vyloucenePar18', (string) $total);
        if (!$prisoner) {
            $values = [];
            foreach (self::SECTION18_DAYS as $name => $attributeId) {
                $values[$name] = $this->int($components[$name] ?? null, $attributeId);
            }
            if (array_sum($values) !== $total) {
                $this->invalid(
                    'jmhz_xml_eldp_section18_days_sum_mismatch',
                    'Úhrn vyloučených dnů neodpovídá rozpadu podle § 18 odst. 7 zákona č. 187/2006 Sb.',
                );
            }
            foreach ($values as $name => $value) {
                $this->text($dom, $block, Jmhz2027Schema::NS_FORM, 'form:' . $name, (string) $value);
            }
        }

        return $block;
    }

    /**
     * Sleva na pojistném zaměstnance (10490, 10491, 10546), ve verzi 2027
     * povinný blok všech formulářů s pojištěním.
     *
     * @param array<string,mixed> $employment
     */
    private function employeeDiscounts(DOMDocument $dom, array $employment): DOMElement
    {
        $reportsSocial = ($employment['reports_social_contributions'] ?? true) === true;
        $employeeDiscount = $this->object($employment['employee_social_discount'] ?? null);
        if ($employeeDiscount !== [] && !$reportsSocial) {
            $this->invalid(
                'jmhz_xml_employee_social_discount_misplaced',
                'Slevu na pojistném zaměstnance smí nést jen formulář, který vykazuje pojistné osoby.',
            );
        }
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:slevaZamestnance');
        $this->text(
            $dom,
            $node,
            Jmhz2027Schema::NS_FORM,
            'form:slevaZamestnanceEvidovana',
            $employeeDiscount === [] ? 'false' : 'true',
        );
        if ($employeeDiscount !== []) {
            $discountCzk = $this->int($employeeDiscount['amount_czk'] ?? null, '10491');
            if ($discountCzk === 0) {
                $this->invalid(
                    'jmhz_xml_employee_social_discount_zero',
                    'Sleva na pojistném zaměstnance s příznakem ANO nesmí být nulová.',
                );
            }
            $amountNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:slevaZamestnance');
            $this->text($dom, $amountNode, Jmhz2027Schema::NS_FORM, 'form:vyseSlevy', (string) $discountCzk);
            $node->appendChild($amountNode);
        }
        $this->text($dom, $node, Jmhz2027Schema::NS_FORM, 'form:slevaZamestnanceOvoZelEvidovana', 'false');

        return $node;
    }

    /**
     * Vykonávaná pozice: místo výkonu práce jako adresa (`adresaType`), u
     * formuláře bez příznaku a K–S navíc APZ, funkční požitky, dočasné
     * přidělení a fond pracovní doby (`vykonavanaPoziceType`).
     *
     * @param array<string,mixed> $employment
     */
    private function position(DOMDocument $dom, array $employment, bool $full): DOMElement
    {
        $term = $this->object($employment['term'] ?? null);
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vykonavanaPozice');
        $node->appendChild($this->workplaceAddress($dom, $term));
        if (!$full) {
            return $node;
        }
        $apz = $this->tristate($employment, $term, 'jmhz_apz_contribution_status', '10232');
        $this->text($dom, $node, Jmhz2027Schema::NS_FORM, 'form:uplatnujiPrispevekApz', $apz ? 'true' : 'false');
        if ($apz) {
            $this->text(
                $dom,
                $node,
                Jmhz2027Schema::NS_FORM,
                'form:nastrojApzKod',
                $this->string($term['jmhz_apz_instrument_code'] ?? null, '10233'),
            );
        }
        $this->text(
            $dom,
            $node,
            Jmhz2027Schema::NS_FORM,
            'form:funkcniPozitky',
            $this->tristate($employment, $term, 'jmhz_functional_benefits_status', '10247') ? 'true' : 'false',
        );
        $assignment = $this->tristate($employment, $term, 'jmhz_temporary_assignment_status', '10251');
        $this->text(
            $dom,
            $node,
            Jmhz2027Schema::NS_FORM,
            'form:docasnePrideleniEvidovano',
            $assignment ? 'true' : 'false',
        );
        if ($assignment) {
            $node->appendChild($this->temporaryAssignment($dom, $term));
        }
        $values = $this->workSummaryValues($employment);
        $fund = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:fondPracovniDoby');
        $this->text(
            $dom,
            $fund,
            Jmhz2027Schema::NS_FORM,
            'form:stanovenyFond',
            $this->decimal($values['standard_fund_millihours'] ?? null, 3, '10259'),
        );
        $this->text(
            $dom,
            $fund,
            Jmhz2027Schema::NS_FORM,
            'form:sjednanyFond',
            $this->decimal($values['agreed_fund_millihours'] ?? null, 3, '10260'),
        );
        $this->text(
            $dom,
            $fund,
            Jmhz2027Schema::NS_FORM,
            'form:stanovenaTydenniDoba',
            $this->decimal($values['weekly_work_centihours'] ?? null, 2, '10261'),
        );
        $node->appendChild($fund);

        return $node;
    }

    /**
     * Místo výkonu práce (`adresaType`): 10550 až 10553, obec 10229, kód obce
     * 10230, PSČ 10554 a stát 10231. Při kódu obce „Mimo ČR" (111111, IN41)
     * se adresa v ČR neuvádí. Číslo popisné a PSČ jsou v matici povinné, XSD
     * 1.5.0.3 je ale připouští vynechat; klíč proto musí v dokumentu být
     * a `null` je doložené „nemá".
     *
     * @param array<string,mixed> $term
     */
    private function workplaceAddress(DOMDocument $dom, array $term): DOMElement
    {
        $place = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mistoVykonuPrace');
        $municipalityCode = $this->string($term['jmhz_workplace_municipality_code'] ?? null, '10230');
        $abroad = $municipalityCode === Jmhz2027DocumentContract::MUNICIPALITY_ABROAD;
        $fields = [
            Jmhz2027DocumentContract::TERM_ADDRESS_POINT => ['10550', '/^[1-9][0-9]{0,8}$/D'],
            Jmhz2027DocumentContract::TERM_STREET => ['10551', null],
            Jmhz2027DocumentContract::TERM_HOUSE_NUMBER => ['10552', '/^[1-9][0-9]{0,3}$/D'],
            Jmhz2027DocumentContract::TERM_ORIENTATION_NUMBER => ['10553', '/^[0-9A-Za-z]{1,12}$/D'],
            Jmhz2027DocumentContract::TERM_POSTAL_CODE => ['10554', '/^[0-9]{5}$/D'],
        ];
        $address = [];
        foreach ($fields as $key => [$attributeId, $pattern]) {
            $value = $term[$key] ?? null;
            if (is_int($value)) {
                $value = (string) $value;
            }
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                $this->unresolved($attributeId);
            }
            $value = trim($value);
            if ($pattern !== null && preg_match($pattern, $value) !== 1) {
                $this->invalid(
                    'jmhz_xml_workplace_address_invalid',
                    "Údaj adresy místa výkonu práce (atribut {$attributeId}) neodpovídá tvaru datové věty.",
                );
            }
            if ($key === Jmhz2027DocumentContract::TERM_STREET && mb_strlen($value) > 50) {
                $this->invalid(
                    'jmhz_xml_workplace_address_invalid',
                    'Ulice místa výkonu práce (atribut 10551) má nejvýš 50 znaků.',
                );
            }
            $address[$key] = $value;
        }
        if ($abroad && $address !== []) {
            $this->invalid(
                'jmhz_xml_workplace_address_invalid',
                'Místo výkonu práce mimo ČR (kód obce 111111) nesmí nést adresu v ČR'
                    . ' (atributy 10550 až 10554, interakce IN41).',
            );
        }
        foreach ([
            Jmhz2027DocumentContract::TERM_ADDRESS_POINT => 'form:kodAdresnihoMista',
            Jmhz2027DocumentContract::TERM_STREET => 'form:ulice',
            Jmhz2027DocumentContract::TERM_HOUSE_NUMBER => 'form:cisloPopisne',
            Jmhz2027DocumentContract::TERM_ORIENTATION_NUMBER => 'form:cisloOrientacni',
        ] as $key => $element) {
            if (isset($address[$key])) {
                $this->text($dom, $place, Jmhz2027Schema::NS_FORM, $element, $address[$key]);
            }
        }
        $this->text(
            $dom,
            $place,
            Jmhz2027Schema::NS_FORM,
            'form:obec',
            $this->string($term['work_place'] ?? null, '10229'),
        );
        $this->text($dom, $place, Jmhz2027Schema::NS_FORM, 'form:kodObce', $municipalityCode);
        if (isset($address[Jmhz2027DocumentContract::TERM_POSTAL_CODE])) {
            $this->text(
                $dom,
                $place,
                Jmhz2027Schema::NS_FORM,
                'form:psc',
                $address[Jmhz2027DocumentContract::TERM_POSTAL_CODE],
            );
        }
        $this->text(
            $dom,
            $place,
            Jmhz2027Schema::NS_FORM,
            'form:kodStatu',
            $this->string($term['jmhz_workplace_country_code'] ?? null, '10231'),
        );

        return $place;
    }

    /** @param array<string,mixed> $term */
    private function temporaryAssignment(DOMDocument $dom, array $term): DOMElement
    {
        $wrapper = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:docasnePrideleni');
        $user = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:uzivatel');
        $kind = $term['jmhz_assignment_user_kind'] ?? null;
        if ($kind === 'ico') {
            $ico = $this->string($term['jmhz_assignment_user_ico'] ?? null, '10252');
            if (preg_match('/^[0-9]{8}$/', $ico) !== 1) {
                $this->invalid(
                    'jmhz_xml_temporary_assignment_user_invalid',
                    'IČO uživatele dočasného přidělení musí mít osm číslic.',
                );
            }
            $this->text($dom, $user, Jmhz2027Schema::NS_FORM, 'form:ico', $ico);
        } elseif ($kind === 'foreign') {
            $foreignId = $this->string($term['jmhz_assignment_user_foreign_id'] ?? null, '10493');
            // `icoZahranicniType` 1.5 je patnáct číslic; jiný tvar by spadl až na XSD.
            if (preg_match('/^[0-9]{15}$/D', $foreignId) !== 1) {
                $this->invalid(
                    'jmhz_xml_temporary_assignment_user_invalid',
                    'Identifikace zahraničního uživatele dočasného přidělení (10493) má ve'
                        . ' verzi 2027 patnáct číslic.',
                );
            }
            $foreign = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:zahranicniOsoba');
            $this->text(
                $dom,
                $foreign,
                Jmhz2027Schema::NS_FORM,
                'form:kodStatu',
                $this->string($term['jmhz_assignment_user_country_code'] ?? null, '10492'),
            );
            $this->text($dom, $foreign, Jmhz2027Schema::NS_FORM, 'form:identifikace', $foreignId);
            $this->text(
                $dom,
                $foreign,
                Jmhz2027Schema::NS_FORM,
                'form:nazev',
                $this->string($term['jmhz_assignment_user_name'] ?? null, '10494'),
            );
            $user->appendChild($foreign);
        } else {
            $this->invalid(
                'jmhz_xml_temporary_assignment_user_invalid',
                'Dočasné přidělení nemá vyplněného uživatele.',
            );
        }
        $wrapper->appendChild($user);

        return $wrapper;
    }

    /**
     * Průběh zaměstnání formuláře bez příznaku. Počet odpracovaných dnů 10267
     * ve verzi 2027 zanikl (ZP 066).
     *
     * @param array<string,mixed> $employment
     */
    private function workMonth(DOMDocument $dom, array $employment): DOMElement
    {
        $values = self::reportedWorkedHours($this->reportedUnworkedHours($this->workSummaryValues($employment)));
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prubehZamestnani');
        $days = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odpracovaneDny');
        $this->text(
            $dom,
            $days,
            Jmhz2027Schema::NS_FORM,
            'form:dnyEvidencniStav',
            (string) $this->int($values['evidence_days'] ?? null, '10265'),
        );
        $node->appendChild($days);
        $node->appendChild($this->workedHours($dom, $employment, $values));
        $this->appendUnworkedHoursAndObstacles($dom, $node, $values);

        return $node;
    }

    /**
     * Odpracované hodiny 10268 s rozpadem. Ve verzi 2027 je v rozpadu přesčas
     * 10269 povinný (smí být záporný) a `riziko` se opakuje po kategorizaci
     * 10274 s vlastními hodinami 10273 a počtem směn 10580 (IN42 u kódu 1).
     * Při nulových odpracovaných hodinách se rozpad nevykazuje (IN33).
     * Hodiny rizikové práce se zaokrouhlují na celé hodiny nahoru stejně jako
     * ve verzi 2026 ({@see JmhzWholeHours}).
     *
     * @param array<string,mixed> $employment
     * @param array<string,mixed> $values
     */
    private function workedHours(DOMDocument $dom, array $employment, array $values): DOMElement
    {
        $hours = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odpracovaneHodiny');
        $worked = $this->int($values['worked_millihours'] ?? null, '10268');
        $this->text($dom, $hours, Jmhz2027Schema::NS_FORM, 'form:pocet', $this->decimal($worked, 3, '10268'));
        $risk = $this->object($employment['risk_work'] ?? null);
        $categories = $this->riskCategories($risk, $worked);
        $overtime = $values['overtime_millihours'] ?? null;
        $hasOvertime = $overtime !== null;
        if ($worked === 0 || (!$hasOvertime && $categories === [])) {
            return $hours;
        }
        $overtime = $this->signedInt($overtime, '10269');
        if ($overtime > $worked) {
            $this->invalid(
                'jmhz_xml_overtime_exceeds_worked_hours',
                'Přesčasové hodiny nesmějí být vyšší než počet odpracovaných hodin.',
            );
        }
        $breakdown = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:rozpad');
        $this->text($dom, $breakdown, Jmhz2027Schema::NS_FORM, 'form:prescas', $this->signedDecimal($overtime, 3, '10269'));
        foreach ($categories as $category) {
            $riskNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:riziko');
            $this->text(
                $dom,
                $riskNode,
                Jmhz2027Schema::NS_FORM,
                'form:hodinyOdpracovanePocet',
                (string) JmhzWholeHours::fromMillihours($category['worked_millihours']),
            );
            if ($category['shift_count'] !== null) {
                $this->text(
                    $dom,
                    $riskNode,
                    Jmhz2027Schema::NS_FORM,
                    'form:smenyOdpracovanePocet',
                    (string) $category['shift_count'],
                );
            }
            $this->text($dom, $riskNode, Jmhz2027Schema::NS_FORM, 'form:kategorizaceRizika', $category['code']);
            $breakdown->appendChild($riskNode);
        }
        $hours->appendChild($breakdown);

        return $hours;
    }

    /**
     * Kategorie rizikové práce z {@see Jmhz2027DocumentContract::RISK_CATEGORIES}.
     * Hodiny kategorie nepřekročí odpracované hodiny vztahu (kontrola 57)
     * a kategorie se neopakuje.
     *
     * @param array<string,mixed> $risk
     * @return list<array{code:string,worked_millihours:int,shift_count:?int}>
     */
    private function riskCategories(array $risk, int $worked): array
    {
        $codes = $risk['categorization_codes'] ?? null;
        $rows = $risk[Jmhz2027DocumentContract::RISK_CATEGORIES] ?? null;
        if ($rows === null) {
            if (is_array($codes) && $codes !== []) {
                Jmhz2027DocumentContract::throwMissingForForm(['10273', '10580']);
            }

            return [];
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->unresolved('10273');
        }
        $categories = [];
        foreach ($rows as $row) {
            $row = $this->object($row);
            $code = $row['code'] ?? null;
            if (!is_string($code) || !in_array($code, self::RISK_CODES, true)) {
                $this->invalid(
                    'jmhz_xml_risk_categorization_invalid',
                    'Kategorizace rizika musí být z číselníku ČSSZ (1, 6 nebo 7).',
                );
            }
            if (isset($categories[$code])) {
                $this->invalid(
                    'jmhz_xml_risk_categorization_invalid',
                    'Kategorizace rizika se ve formuláři vykazuje jednou.',
                );
            }
            $hours = $this->int($row['worked_millihours'] ?? null, '10273');
            if ($hours > $worked) {
                $this->invalid(
                    'jmhz_xml_risk_categorization_invalid',
                    'Hodiny rizikové práce (10273) nesmějí být vyšší než odpracované hodiny (10268).',
                );
            }
            $shifts = $row['shift_count'] ?? null;
            if ($shifts !== null && !Jmhz2027DocumentContract::riskShiftCountRequired($code, $hours)) {
                // Kontrola 386 (katalog 1.5.0.4): 10580 jen při 10274 = 1
                // a 10273 > 0, „v ostatních případech nesmí být vyplněn".
                $this->invalid(
                    'jmhz_xml_risk_shift_count_forbidden',
                    'Počet směn (10580) se uvádí jen u kategorie rizika 1 s odpracovanými'
                        . ' hodinami rizikové práce.',
                );
            }
            if ($shifts !== null) {
                $shifts = $this->int($shifts, '10580');
                if ($shifts > 999) {
                    $this->unresolved('10580');
                }
            } elseif (Jmhz2027DocumentContract::riskShiftCountRequired($code, $hours)) {
                $this->unresolved('10580');
            }
            $categories[$code] = ['code' => $code, 'worked_millihours' => $hours, 'shift_count' => $shifts];
        }

        return array_values($categories);
    }

    /** @param array<string,mixed> $values */
    private function appendUnworkedHoursAndObstacles(DOMDocument $dom, DOMElement $node, array $values): void
    {
        $unworked = [
            'form:hodinyNeodpracCelkem' => ['unworked_total_millihours', '10275'],
            'form:hodinyNeodpracNahrada' => ['unworked_paid_millihours', '10276'],
            'form:hodinyNeodpracBezNahrady' => ['dpn_without_employer_compensation_millihours', '10277'],
            'form:hodinyNeodpracNeschop' => ['dpn_with_employer_compensation_millihours', '10278'],
            'form:hodinyNeodpracDovol' => ['vacation_millihours', '10279'],
            'form:hodinyNeodpracOcr' => ['care_millihours', '10280'],
        ];
        if (is_int($values['unworked_total_millihours'] ?? null)) {
            $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:neodpracovaneHodiny');
            foreach ($unworked as $element => [$key, $attributeId]) {
                $value = $values[$key] ?? null;
                if (!is_int($value)) {
                    continue;
                }
                $this->text($dom, $block, Jmhz2027Schema::NS_FORM, $element, $this->decimal($value, 3, $attributeId));
            }
            $node->appendChild($block);
        }
        $obstacles = [
            'form:prekazkaZamestnanec' => ['employee_obstacle_paid_millihours', '10471'],
            'form:prekazkaZamestnavatel' => ['employer_obstacle_millihours', '10472'],
        ];
        $hasObstacles = false;
        foreach ($obstacles as [$key]) {
            $hasObstacles = $hasObstacles || is_int($values[$key] ?? null);
        }
        if ($hasObstacles) {
            $block = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prekazkyVPraci');
            foreach ($obstacles as $element => [$key, $attributeId]) {
                $value = $values[$key] ?? null;
                if (!is_int($value)) {
                    continue;
                }
                $this->text($dom, $block, Jmhz2027Schema::NS_FORM, $element, $this->decimal($value, 3, $attributeId));
            }
            $node->appendChild($block);
        }
    }

    /**
     * Hodiny nemoci s náhradou mzdy se z 10276 přesouvají do 10471 (Pokyny MH
     * k 10276); stejný reportovací převod jako ve verzi 2026.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function reportedUnworkedHours(array $values): array
    {
        $sickness = $values['dpn_with_employer_compensation_millihours'] ?? null;
        if (!is_int($sickness) || $sickness <= 0) {
            return $values;
        }
        $paid = $this->int($values['unworked_paid_millihours'] ?? null, '10276') - $sickness;
        if ($paid < 0) {
            $this->invalid(
                'jmhz_xml_paid_unworked_hours_below_sickness',
                'Placené neodpracované hodiny pracovního souhrnu nezahrnují hodiny nemoci s náhradou mzdy.',
            );
        }
        $obstacle = $values['employee_obstacle_paid_millihours'] ?? null;
        $values['unworked_paid_millihours'] = $paid === 0 ? null : $paid;
        $values['employee_obstacle_paid_millihours']
            = ($obstacle === null ? 0 : $this->int($obstacle, '10471')) + $sickness;

        return $values;
    }

    /**
     * Náhradní volno za přesčas se odečítá od 10268 i 10269 (Pokyny MH k
     * 10269). Ve verzi 2027 je přesčas se znaménkem; pravidlo i znaménko
     * drží {@see JmhzReportedWorkHours}, ze kterého staví hodiny rizikové
     * práce i resolver.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function reportedWorkedHours(array $values): array
    {
        if (!is_int($values['compensatory_time_off_millihours'] ?? null)
            || !is_int($values['worked_millihours'] ?? null)
        ) {
            return $values;
        }

        return array_replace($values, JmhzReportedWorkHours::reported($values, true));
    }

    /**
     * Daň vztahu (`danType` 1.5): příjem pro výpočet zálohy 10535 a příjem
     * podle § 6 odst. 4 10555, oba povinné a nejvýš jeden kladný (kontrola 304).
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function income(DOMDocument $dom, array $summary, array $employment): DOMElement
    {
        $taxableIncome = array_key_exists('taxable_income_czk', $employment)
            ? $employment['taxable_income_czk']
            : ($this->object($summary['advance_tax_czk'] ?? null)['taxable_income'] ?? null);
        $advanceIncome = $this->int($taxableIncome, '10535');
        $paragraph6Income = $this->int(
            $employment[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME] ?? null,
            '10555',
        );
        if ($advanceIncome > 0 && $paragraph6Income > 0) {
            $this->invalid(
                Jmhz2027DocumentContract::INCONSISTENT_CODE,
                'Příjem pro výpočet zálohy (10535) a příjem podle § 6 odst. 4 (10555) nesmějí'
                    . ' být kladné oba (kontrola 304).',
            );
        }
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:prijem');
        $tax = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:dan');
        $this->text($dom, $tax, Jmhz2027Schema::NS_FORM, 'form:prijem', (string) $advanceIncome);
        $this->text($dom, $tax, Jmhz2027Schema::NS_FORM, 'form:prijemPar6', (string) $paragraph6Income);
        $node->appendChild($tax);

        return $node;
    }

    /**
     * Mzda formuláře bez příznaku. Ve verzi 2027 smějí být složky mzdy,
     * příplatky, náhrady a odměny záporné (ZP 103 a další, `cislo14Type`,
     * `cislo8Type`); úhrn 10328 zůstává nezáporný.
     *
     * @param array<string,mixed> $employment
     */
    private function wage(DOMDocument $dom, array $employment): DOMElement
    {
        $earnings = $this->object($employment['earnings_by_attribute_czk'] ?? null);
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mzda');
        $wageTotal = $this->int($this->earning($earnings, '10328'), '10328');
        $this->text($dom, $node, Jmhz2027Schema::NS_FORM, 'form:mzdaZuctovana', (string) $wageTotal);
        $components = [];
        foreach ([
            'form:tarif' => '10329',
            'form:odmenyPravidelne' => '10330',
            'form:odmenyNepravidelne' => '10331',
        ] as $element => $attributeId) {
            $components[$element] = $this->signedInt($this->earning($earnings, $attributeId), $attributeId);
        }
        $surcharges = $this->wageSurcharges($earnings, $this->reportsOvertime($this->workSummaryValues($employment)));
        if ($wageTotal === 0) {
            foreach ([...array_values($components), ...array_values($surcharges)] as $amount) {
                if ($amount !== 0) {
                    $this->invalid(
                        'jmhz_xml_wage_breakdown_without_wage',
                        'Rozpad mzdy nelze vykázat při nulové zúčtované mzdě.',
                    );
                }
            }
        } else {
            $breakdown = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:mzdaRozpad');
            foreach ($components as $element => $value) {
                $this->text($dom, $breakdown, Jmhz2027Schema::NS_FORM, $element, (string) $value);
            }
            if ($surcharges !== []) {
                $surchargeNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:priplatky');
                foreach ($surcharges as $element => $value) {
                    $this->text($dom, $surchargeNode, Jmhz2027Schema::NS_FORM, $element, (string) $value);
                }
                $breakdown->appendChild($surchargeNode);
            }
            $node->appendChild($breakdown);
        }
        $this->appendCompensations($dom, $node, $earnings);
        $standby = $this->earning($earnings, '10343');
        if ($standby !== null) {
            $standbyNode = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:odmeny');
            $this->text(
                $dom,
                $standbyNode,
                Jmhz2027Schema::NS_FORM,
                'form:pohotovost',
                (string) $this->signedInt($standby, '10343'),
            );
            $node->appendChild($standbyNode);
        }
        $node->appendChild($this->averageEarning($dom, $employment));

        return $node;
    }

    /** @param array<string,mixed> $employment */
    private function averageEarning(DOMDocument $dom, array $employment): DOMElement
    {
        $average = $this->object($employment['average_hourly'] ?? null);
        if (!is_int($average['minor_units'] ?? null)) {
            $this->invalid(
                'jmhz_average_hourly_earning_probable_missing',
                JmhzBlockerExplainer::guidance('jmhz_average_hourly_earning_probable_missing'),
            );
        }
        $wrapper = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:vydelek');
        $this->text(
            $dom,
            $wrapper,
            Jmhz2027Schema::NS_FORM,
            'form:vydelekPrumernyHod',
            $this->decimal($average['minor_units'], 2, '10345'),
        );

        return $wrapper;
    }

    /**
     * Příplatky 10332–10336 a pravidlo nulového příplatku za přesčas
     * (kontrola 36) jako ve verzi 2026.
     *
     * @param array<array-key,mixed> $earnings
     * @return array<string,int>
     */
    private function wageSurcharges(array $earnings, bool $overtimeReported): array
    {
        $surcharges = [];
        foreach ([
            'form:celkem' => '10332',
            'form:prescas' => '10333',
            'form:nocni' => '10334',
            'form:sobotaNedele' => '10335',
            'form:svatek' => '10336',
        ] as $element => $attributeId) {
            $value = $this->earning($earnings, $attributeId);
            if ($value === null) {
                continue;
            }
            $surcharges[$element] = $this->signedInt($value, $attributeId);
        }
        if ($overtimeReported && !array_key_exists('form:prescas', $surcharges)) {
            if ($surcharges === []) {
                $surcharges['form:celkem'] = 0;
            }
            $surcharges = array_slice($surcharges, 0, 1, true)
                + ['form:prescas' => 0]
                + array_slice($surcharges, 1, null, true);
        }
        if ($surcharges !== [] && !array_key_exists('form:celkem', $surcharges)) {
            $this->invalid(
                'jmhz_xml_surcharge_total_missing',
                'Rozpad příplatků nelze vykázat bez úhrnu příplatků (10332).',
            );
        }

        return $surcharges;
    }

    /** @param array<array-key,mixed> $earnings */
    private function appendCompensations(DOMDocument $dom, DOMElement $wage, array $earnings): void
    {
        $values = [];
        foreach ([
            'form:mzdyZuctovane' => '10337',
            'form:dovolena' => '10338',
            'form:svatky' => '10339',
            'form:prekazkyZamestnavatel' => '10340',
            'form:prekazkyZamestnanec' => '10341',
            'form:docasnaNeschopnost' => '10342',
        ] as $element => $attributeId) {
            $value = $this->earning($earnings, $attributeId);
            if ($value === null) {
                continue;
            }
            $values[$element] = $this->signedInt($value, $attributeId);
        }
        if ($values === []) {
            return;
        }
        $node = $this->node($dom, Jmhz2027Schema::NS_FORM, 'form:nahrady');
        $this->text(
            $dom,
            $node,
            Jmhz2027Schema::NS_FORM,
            'form:mzdyZuctovane',
            (string) ($values['form:mzdyZuctovane'] ?? 0),
        );
        unset($values['form:mzdyZuctovane']);
        foreach ($values as $element => $value) {
            $this->text($dom, $node, Jmhz2027Schema::NS_FORM, $element, (string) $value);
        }
        $wage->appendChild($node);
    }

    /** @param array<array-key,mixed> $earnings */
    private function earning(array $earnings, string $attributeId): mixed
    {
        return array_key_exists($attributeId, $earnings) ? $earnings[$attributeId] : null;
    }

    /** @param array<string,mixed> $values */
    private function reportsOvertime(array $values): bool
    {
        $overtime = $values['overtime_millihours'] ?? null;

        return is_int($overtime) && $overtime > 0 && ($values['worked_millihours'] ?? null) !== 0;
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function workSummaryValues(array $employment): array
    {
        $summary = $this->object($this->object($employment['work_month'] ?? null)['jmhz_work_summary'] ?? null);
        $values = $this->object($summary['values'] ?? null);
        if ($values === []) {
            $this->invalid('jmhz_xml_work_summary_missing', 'Součást nemá zmrazený pracovní souhrn.');
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $employment
     * @param array<string,mixed> $term
     */
    private function tristate(array $employment, array $term, string $field, string $attributeId): bool
    {
        $value = $term[$field] ?? null;
        if ($value === 'yes') {
            return true;
        }
        if ($value === 'no') {
            return false;
        }
        if ($value === 'unverified' && $this->defaultInterpretation($employment, $field, $attributeId)) {
            return false;
        }
        $this->unresolved($attributeId);
    }

    /** @param array<string,mixed> $employment */
    private function defaultInterpretation(array $employment, string $field, string $attributeId): bool
    {
        $records = $employment['jmhz_default_interpretations'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            return false;
        }
        foreach ($records as $record) {
            if (is_array($record)
                && ($record['field'] ?? null) === $field
                && ($record['attribute_id'] ?? null) === $attributeId
                && ($record['stored_value'] ?? null) === 'unverified'
                && ($record['applied_value'] ?? null) === 'no'
                && ($record['basis'] ?? null) === JmhzPreparationSnapshotBuilder::DEFAULT_TRISTATE_BASIS
            ) {
                return true;
            }
        }

        return false;
    }

    private function node(DOMDocument $dom, string $namespace, string $name): DOMElement
    {
        return $dom->createElementNS($namespace, $name);
    }

    private function text(DOMDocument $dom, DOMElement $parent, string $namespace, string $name, string $value): void
    {
        $node = $dom->createElementNS($namespace, $name);
        $node->appendChild($dom->createTextNode($value));
        $parent->appendChild($node);
    }

    private function string(mixed $value, string $attributeId): string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->unresolved($attributeId);
        }

        return $value;
    }

    private function int(mixed $value, string $attributeId): int
    {
        if (!is_int($value) || $value < 0) {
            $this->unresolved($attributeId);
        }

        return $value;
    }

    private function signedInt(mixed $value, string $attributeId): int
    {
        if (!is_int($value)) {
            $this->unresolved($attributeId);
        }

        return $value;
    }

    private function bool(mixed $value, string $attributeId): bool
    {
        if (!is_bool($value)) {
            $this->unresolved($attributeId);
        }

        return $value;
    }

    private function date(mixed $value, string $attributeId): string
    {
        if (!is_string($value)) {
            $this->unresolved($attributeId);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $this->unresolved($attributeId);
        }

        return $value;
    }

    private function decimal(mixed $value, int $scale, string $attributeId): string
    {
        if (!is_int($value) || $value < 0) {
            $this->unresolved($attributeId);
        }

        return $this->signedDecimal($value, $scale, $attributeId);
    }

    private function signedDecimal(mixed $value, int $scale, string $attributeId): string
    {
        if (!is_int($value)) {
            $this->unresolved($attributeId);
        }
        $divisor = 10 ** $scale;
        $absolute = abs($value);

        return ($value < 0 ? '-' : '') . intdiv($absolute, $divisor) . '.'
            . str_pad((string) ($absolute % $divisor), $scale, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    private function hasNonZero(mixed $components): bool
    {
        if (!is_array($components)) {
            return false;
        }
        foreach ($components as $value) {
            if (is_int($value) && $value !== 0) {
                return true;
            }
        }

        return false;
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

    private function unresolved(string $attributeId): never
    {
        $this->invalid(
            'jmhz_xml_attribute_unresolved',
            "Atribut {$attributeId} není ve zmrazeném dokumentu doložený, "
                . 'a nesmí se proto doplnit nulou ani nepravdou.',
        );
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzXmlException($code, $message);
    }
}
