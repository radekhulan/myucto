<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;

/**
 * Serializér podporovaných běžných profilů měsíčního hlášení: `scenario_1`
 * (`form:bezPriznaku`) a statutární profil scénáře 3 (`form:cinnostKS`),
 * řádné i obsahově opravné podání, jeden dílčí balík.
 *
 * Pracuje VÝHRADNĚ s vyřešeným normalizovaným dokumentem. Nesahá do databáze,
 * nedopočítává a nezaokrouhluje — každá hodnota, která v dokumentu není
 * zmrazená, je tvrdá chyba, nikdy nula ani `false`. Pořadí elementů odpovídá
 * `xs:sequence` připnutého JMHZ 1.4.3.4; nepovinné bloky, pro které nemáme
 * doložený zdroj, se raději neuvádějí, než aby se odhadovaly.
 */
final class JmhzScenario1XmlSerializer
{
    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    /**
     * Písmena § 5a odst. 1 ZPSZ a jejich elementy. Rozlišují sazbu
     * zaměstnavatele — a) běžná, b) zdravotnická záchranná služba a hasičský
     * záchranný sbor podniku, c) rizikové zaměstnání — takže záměna písmene
     * je záměna sazby, ne kosmetika.
     *
     * @var array<string, string>
     */
    private const PARAGRAPH5_ELEMENTS = [
        'a' => 'form:pismenoA',
        'b' => 'form:pismenoB',
        'c' => 'form:pismenoC',
    ];

    /**
     * Složky vyloučených dob v pořadí sekvence `vylouceneDnyType`.
     *
     * Klíč je název elementu i klíč rozpadu z
     * {@see \MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver::COMPONENTS},
     * hodnota je ID atributu datového slovníku — v chybové hlášce má být to,
     * na co se odvolávají kontroly ČSSZ.
     *
     * @var array<string, string>
     */
    private const ELDP_EXCLUDED_DAYS = [
        'docasNeschopnost' => '10358',
        'penezitaPomocMaterstvi' => '10359',
        'osetrovaniClenaRodiny' => '10360',
        'otcovska' => '10362',
        'vyloucenePar16' => '10536',
    ];

    /**
     * Rozpad vyloučených dnů podle § 18 odst. 7 zákona č. 187/2006 Sb.
     * v pořadí sekvence `vylouceneDnyType`, za složkami § 16 odst. 4.
     *
     * Úhrn 10366 datový slovník definuje jako součet těchhle tří, takže se
     * blok vykazuje celý, nebo vůbec — odvození drží
     * {@see \MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver::deriveSection18()}.
     *
     * @var array<string, string>
     */
    private const ELDP_SECTION18_DAYS = [
        'omluvenaNepritomnost' => '10473',
        'pracovniNeschopnost' => '10474',
        'vyplaceniDavek' => '10475',
    ];

    public function serialize(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
    ): string {
        $payload = $document->payload;
        $this->assertProfile($payload, $envelope);
        $people = $this->rows($payload['people'] ?? null);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'jmhz');
        $dom->appendChild($root);
        $root->setAttribute(
            'verze',
            (new JmhzSchemaCatalog())->entryPoint()['data_version'],
        );
        // Prefixy importovaných jmenných prostorů se deklarují na kořeni.
        // Libxml si `xmlns:form` u formulářových součástí ještě jednou zopakuje;
        // je to redundantní, ale platné, deterministické a XSD to projde.
        // Sestavovat kořen přes `loadXML()` by deklarace sjednotilo, jenže
        // rozbije hlavičku dokumentu i diakritiku, takže se to nedělá.
        foreach ([
            'xmlns:so' => JmhzSchemaCatalog::NS_SOUHRN,
            'xmlns:pvpoj' => JmhzSchemaCatalog::NS_PVPOJ,
            'xmlns:form' => JmhzSchemaCatalog::NS_FORM,
        ] as $name => $namespace) {
            $root->setAttributeNS(self::XMLNS, $name, $namespace);
        }

        $vendor = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'VENDOR');
        $vendor->setAttribute('productName', $envelope->productName);
        $vendor->setAttribute('productVersion', $envelope->productVersion);
        $root->appendChild($vendor);
        $root->appendChild($this->header($dom, $payload, $envelope, $people));
        $root->appendChild($this->summary($dom, $payload));
        $root->appendChild($this->pvpoj($dom, $payload));
        $root->appendChild($this->forms($dom, $people, $envelope));

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzXmlException(
                'jmhz_xml_serialization_failed',
                'XML měsíčního hlášení nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }

    public function serializeCorrection(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): string {
        $payload = $document->payload;
        $this->assertProfile($payload, $envelope);
        $people = $this->correctionPeople($payload, $envelope, $plan);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'jmhz');
        $dom->appendChild($root);
        $root->setAttribute(
            'verze',
            (new JmhzSchemaCatalog())->entryPoint()['data_version'],
        );
        foreach ([
            'xmlns:so' => JmhzSchemaCatalog::NS_SOUHRN,
            'xmlns:pvpoj' => JmhzSchemaCatalog::NS_PVPOJ,
            'xmlns:form' => JmhzSchemaCatalog::NS_FORM,
        ] as $name => $namespace) {
            $root->setAttributeNS(self::XMLNS, $name, $namespace);
        }

        $vendor = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'VENDOR');
        $vendor->setAttribute('productName', $envelope->productName);
        $vendor->setAttribute('productVersion', $envelope->productVersion);
        $root->appendChild($vendor);
        $root->appendChild($this->correctionHeader(
            $dom,
            $payload,
            $envelope,
            count($people)
                + ($plan->includeSummary ? 1 : 0)
                + ($plan->includePvpoj ? 1 : 0),
        ));
        if ($plan->includeSummary) {
            $root->appendChild($this->summary($dom, $payload));
        }
        if ($plan->includePvpoj) {
            $root->appendChild($this->pvpoj($dom, $payload));
        }
        $root->appendChild($this->correctionForms($dom, $people, $envelope, $plan));

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzXmlException(
                'jmhz_xml_serialization_failed',
                'XML obsahové opravy měsíčního hlášení nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertProfile(
        array $payload,
        JmhzSubmissionEnvelope $envelope,
    ): void {
        if (($payload['schema_reference'] ?? null)
            !== JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE
        ) {
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
        $people = $this->rows($payload['people'] ?? null);
        if ($people === []) {
            // Kontrola 211: podání, po němž nezbude validní součást, je vadné.
            $this->invalid(
                'jmhz_xml_no_valid_form',
                'Podání musí obsahovat alespoň jednu platnou součást.',
            );
        }
        if (count($people) > 1500) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Nad 1500 součástí je dělení podání povinné; serializér zatím staví jen jeden balík.',
            );
        }
        if ($envelope->packageCount !== 1) {
            $this->invalid(
                'jmhz_xml_split_submission_unsupported',
                'Dělené podání zatím serializér nestaví.',
            );
        }
        // Řádná cesta staví souhrnnou i pojistnou část vždy. Obsahová oprava
        // povolené podmnožiny ověřuje samostatně v JmhzContentCorrectionPlan.
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            true,
            true,
            array_fill(0, count($people), JmhzSubmissionFlagMatrix::TYPE_REGULAR),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<array<string,mixed>> $people
     */
    private function header(
        DOMDocument $dom,
        array $payload,
        JmhzSubmissionEnvelope $envelope,
        array $people,
    ): DOMElement {
        $header = $this->object($payload['header'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'idPodani', $envelope->submissionGuid);
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'typPodani', 'R');
        $variableSymbol = $this->string($header['variable_symbol'] ?? null, '10221');
        if (preg_match('/^\d{10}$/D', $variableSymbol) !== 1) {
            $this->invalid(
                'jmhz_xml_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít přesně deset číslic.',
            );
        }
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'variabilniSymbol', $variableSymbol);
        $month = $this->int($header['month'] ?? null, '10010');
        // Rozsah roku si hlídá připnuté XSD (`rok` má `minInclusive`
        // i `maxInclusive`); zadrátovat ho i sem by udělalo druhý zdroj pravdy
        // a letopočet-bránu v kódu, kterou modul nikde nemá mít.
        $year = $this->int($header['year'] ?? null, '10011');
        if ($month < 1 || $month > 12) {
            $this->invalid(
                'jmhz_xml_period_invalid',
                'Hlášený měsíc je mimo rozsah připnutého schématu.',
            );
        }
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'mesic', (string) $month);
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'rok', (string) $year);
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'datumVyplneni', $envelope->filledAt);
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'balikPoradi', (string) $envelope->packageOrdinal);
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'balikyPocet', (string) $envelope->packageCount);
        // Počítá se ze skutečně vypsaných součástí plus souhrn a PVPOJ, ne
        // z hodnoty uložené v dokumentu — jinak by se obě vrstvy mohly rozejít.
        $formCount = count($people) + 2;
        if ($formCount > 1502) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Balík dat pojme nejvýše 1502 formulářů včetně souhrnu a PVPOJ.',
            );
        }
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'formularePocetVBaliku', (string) $formCount);
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'formularePocetCelkem', (string) $formCount);

        return $node;
    }

    /** @param array<string,mixed> $payload */
    private function correctionHeader(
        DOMDocument $dom,
        array $payload,
        JmhzSubmissionEnvelope $envelope,
        int $formCount,
    ): DOMElement {
        $header = $this->object($payload['header'] ?? null);
        if ($formCount > 1502) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Balík dat pojme nejvýše 1502 formulářů včetně souhrnu a PVPOJ.',
            );
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'idPodani', $envelope->submissionGuid);
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_PODANI,
            'typPodani',
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
        );
        $variableSymbol = $this->string($header['variable_symbol'] ?? null, '10221');
        if (preg_match('/^\d{10}$/D', $variableSymbol) !== 1) {
            $this->invalid(
                'jmhz_xml_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít přesně deset číslic.',
            );
        }
        $month = $this->int($header['month'] ?? null, '10010');
        $year = $this->int($header['year'] ?? null, '10011');
        if ($month < 1 || $month > 12) {
            $this->invalid(
                'jmhz_xml_period_invalid',
                'Hlášený měsíc je mimo rozsah připnutého schématu.',
            );
        }
        foreach ([
            'variabilniSymbol' => $variableSymbol,
            'mesic' => (string) $month,
            'rok' => (string) $year,
            'datumVyplneni' => $envelope->filledAt,
            'balikPoradi' => (string) $envelope->packageOrdinal,
            'balikyPocet' => (string) $envelope->packageCount,
            'formularePocetVBaliku' => (string) $formCount,
            'formularePocetCelkem' => (string) $formCount,
        ] as $name => $value) {
            $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, $name, $value);
        }

        return $node;
    }

    /** @param array<string,mixed> $payload */
    private function summary(DOMDocument $dom, array $payload): DOMElement
    {
        $totals = $this->object(
            $this->object($payload['employer'] ?? null)['summary_totals'] ?? null,
        );
        $node = $this->node($dom, JmhzSchemaCatalog::NS_SOUHRN, 'so:souhrn');
        $monthly = $this->node($dom, JmhzSchemaCatalog::NS_SOUHRN, 'so:danUdajeMesic');
        $this->text(
            $dom,
            $monthly,
            JmhzSchemaCatalog::NS_SOUHRN,
            'so:danZalohaPoSleve',
            (string) $this->int($totals['advance_tax_after_credits'] ?? null, '10034'),
        );
        // Úhrn bonusů je souhrnný protějšek formulářového `form:danBonus`
        // (10306), který se od opravy kontroly 244 bez podepsaného prohlášení
        // nepíše vůbec — atribut se řídí PŘÍTOMNOSTÍ elementu, ne hodnotou.
        // Nulový úhrn znamená, že bonus nevznikl nikomu, takže se souhrnný
        // element (XSD `minOccurs=0`) vynechává stejnou úvahou.
        $taxBonus = $this->int($totals['tax_bonus'] ?? null, '10035');
        if ($taxBonus !== 0) {
            $this->text(
                $dom,
                $monthly,
                JmhzSchemaCatalog::NS_SOUHRN,
                'so:danBonus',
                (string) $taxBonus,
            );
        }
        $node->appendChild($monthly);
        $annual = $this->object(
            $this->object($payload['employer'] ?? null)['annual'] ?? null,
        );
        if ($annual !== []) {
            $annualNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_SOUHRN,
                'so:zamestnavatelUdajeRok',
            );
            $this->text(
                $dom,
                $annualNode,
                JmhzSchemaCatalog::NS_SOUHRN,
                'so:formaVlastnictvi',
                $this->string($annual['ownership_form'] ?? null, '10220'),
            );
            $ozp = $this->object($annual['ozp'] ?? null);
            if ($ozp !== []) {
                $ozpNode = $this->node(
                    $dom,
                    JmhzSchemaCatalog::NS_SOUHRN,
                    'so:zamestnavaniOzp',
                );
                foreach ([
                    'so:zecPocetPrepRok' => ['average_headcount_hundredths', '10038'],
                    'so:zecPocetPrepOzpRok' => ['average_disabled_headcount_hundredths', '10039'],
                    'so:podilZamZtp' => ['disabled_share_hundredths', '10452'],
                ] as $element => [$key, $attributeId]) {
                    $this->text(
                        $dom,
                        $ozpNode,
                        JmhzSchemaCatalog::NS_SOUHRN,
                        $element,
                        $this->decimal($ozp[$key] ?? null, 2, $attributeId),
                    );
                }
                $annualNode->appendChild($ozpNode);
            }
            $agreements = $annual['collective_agreement_types'] ?? null;
            if (!is_array($agreements) || !array_is_list($agreements) || $agreements === []) {
                $this->unresolved('10214');
            }
            $agreementsNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_SOUHRN,
                'so:kolektivniSmlouvy',
            );
            foreach ($agreements as $agreement) {
                $agreementNode = $this->node(
                    $dom,
                    JmhzSchemaCatalog::NS_SOUHRN,
                    'so:kolektivniSmlouva',
                );
                $this->text(
                    $dom,
                    $agreementNode,
                    JmhzSchemaCatalog::NS_SOUHRN,
                    'so:typKolektSmlouvy',
                    $this->string($agreement, '10214'),
                );
                $agreementsNode->appendChild($agreementNode);
            }
            $annualNode->appendChild($agreementsNode);
            $node->appendChild($annualNode);
        }
        // `danUdajeRok` je v připnutém XSD volitelný blok. Bez zmrazeného
        // ročního zdroje se vynechá; právní skutečnosti se z absence neodhadují.
        // `specifickaSkutecnost` se neuvádí, protože IN13 je doložené `false`.

        return $node;
    }

    /** @param array<string,mixed> $payload */
    private function pvpoj(DOMDocument $dom, array $payload): DOMElement
    {
        $preview = $this->object(
            $this->object($payload['employer'] ?? null)['pvpoj'] ?? null,
        );
        $values = $this->object($preview['values'] ?? null);
        if ($values === []) {
            $this->invalid(
                'jmhz_xml_pvpoj_missing',
                'Řádné podání musí obsahovat pojistnou část.',
            );
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PVPOJ, 'pvpoj:PVPOJ');
        $contributions = $this->object($values['pojistne'] ?? null);
        $group = $this->node($dom, JmhzSchemaCatalog::NS_PVPOJ, 'pvpoj:pojistne');
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
                JmhzSchemaCatalog::NS_PVPOJ,
                'pvpoj:' . $field,
                (string) $this->int($contributions[$field], $attributeId),
            );
        }
        $node->appendChild($group);
        foreach ([
            'slevaZamestnavatele',
            'slevyZamestnancu',
            'slevyZamestnancuOvoZel',
        ] as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $discount = $this->object($values[$field]);
            $discountNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_PVPOJ,
                'pvpoj:' . $field,
            );
            foreach ([
                'pocetZamestnancu' => '10030',
                'uhrnVymerovacichZakladu' => '10031',
                'pojistneSleva' => '10032',
            ] as $child => $attributeId) {
                $this->text(
                    $dom,
                    $discountNode,
                    JmhzSchemaCatalog::NS_PVPOJ,
                    'pvpoj:' . $child,
                    (string) $this->int($discount[$child] ?? null, $attributeId),
                );
            }
            $node->appendChild($discountNode);
        }
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_PVPOJ,
            'pvpoj:pojistneUhrada',
            (string) $this->int($values['pojistneUhrada'] ?? null, '10033'),
        );

        return $node;
    }

    /** @param list<array<string,mixed>> $people */
    private function forms(
        DOMDocument $dom,
        array $people,
        JmhzSubmissionEnvelope $envelope,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        foreach ($people as $person) {
            $summary = $this->object($person['summary'] ?? null);
            $employments = $this->rows($person['employments'] ?? null);
            if (count($employments) !== 1) {
                $this->invalid(
                    'jmhz_xml_multiple_employments_unsupported',
                    'První profil staví právě jednu součást na osobu.',
                );
            }
            $employment = $employments[0];
            $form = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'formularOsoby');
            $header = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
            $employmentId = $employment['employment_id'] ?? null;
            $this->text(
                $dom,
                $header,
                JmhzSchemaCatalog::NS_PODANI,
                'idFormulare',
                $envelope->formGuid(is_int($employmentId) ? $employmentId : null),
            );
            $this->text($dom, $header, JmhzSchemaCatalog::NS_PODANI, 'typFormulare', 'R');
            $this->text(
                $dom,
                $header,
                JmhzSchemaCatalog::NS_PODANI,
                'primarniPpv',
                $this->bool($employment['primary'] ?? null, '10495') ? 'true' : 'false',
            );
            $form->appendChild($header);
            $form->appendChild($this->formBody($dom, $summary, $employment));
            $node->appendChild($form);
        }

        return $node;
    }

    /**
     * @param list<array<string,mixed>> $people
     */
    private function correctionForms(
        DOMDocument $dom,
        array $people,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        foreach ($people as $person) {
            $summary = $this->object($person['summary'] ?? null);
            $employment = $this->rows($person['employments'] ?? null)[0];
            $employmentId = $employment['employment_id'];
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
            $form = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'formularOsoby');
            $header = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
            $this->text(
                $dom,
                $header,
                JmhzSchemaCatalog::NS_PODANI,
                'idFormulare',
                $envelope->formGuid($employmentId),
            );
            $this->text(
                $dom,
                $header,
                JmhzSchemaCatalog::NS_PODANI,
                'typFormulare',
                $correction->formType,
            );
            $this->text(
                $dom,
                $header,
                JmhzSchemaCatalog::NS_PODANI,
                'primarniPpv',
                $this->bool($employment['primary'] ?? null, '10495') ? 'true' : 'false',
            );
            $form->appendChild($header);
            $form->appendChild($this->formBody($dom, $summary, $employment));
            $node->appendChild($form);
        }

        return $node;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
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
        foreach ($this->rows($payload['people'] ?? null) as $person) {
            $employments = $this->rows($person['employments'] ?? null);
            if (count($employments) !== 1) {
                $this->invalid(
                    'jmhz_xml_multiple_employments_unsupported',
                    'První profil staví právě jednu součást na osobu.',
                );
            }
            $employmentId = $employments[0]['employment_id'] ?? null;
            if (!is_int($employmentId)) {
                continue;
            }
            $correction = $plan->formForEmployment($employmentId);
            if ($correction === null) {
                continue;
            }
            $correction->assertEnvelopeGuid($envelope->formGuid($employmentId));
            $selected[] = $person;
        }
        if (count($selected) !== count($plan->forms)) {
            $this->invalid(
                'jmhz_content_correction_source_form_missing',
                'Nová příprava neobsahuje všechny formuláře vybrané pro obsahovou opravu.',
            );
        }

        return $selected;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function bezPriznaku(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:bezPriznaku');
        $node->appendChild($this->identification($dom, $employment));
        $node->appendChild($this->employeeSummary($dom, $summary));
        $node->appendChild($this->insurance($dom, $summary, $employment));
        $node->appendChild($this->position($dom, $employment));
        $node->appendChild($this->workMonth($dom, $employment));
        $node->appendChild($this->income($dom, $summary));
        $node->appendChild($this->wage($dom, $employment));

        return $node;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function formBody(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $selector = $this->object($employment['selector'] ?? null);
        return match ($selector['scenario_key'] ?? null) {
            'scenario_1' => $this->bezPriznaku($dom, $summary, $employment),
            'scenario_3' => $this->cinnostKs($dom, $summary, $employment),
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
    private function cinnostKs(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $selector = $this->object($employment['selector'] ?? null);
        if (($selector['activity_code'] ?? null) !== 'S'
            || ($selector['relationship_detail_code'] ?? null) !== '1'
        ) {
            $this->invalid(
                'jmhz_xml_scenario_3_profile_unsupported',
                'Větev činnost K–S podporuje pouze statutární profil S/detail 1.',
            );
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:cinnostKS');
        $node->appendChild($this->identification($dom, $employment));
        $node->appendChild($this->employeeSummary($dom, $summary, true));
        $node->appendChild($this->insurance($dom, $summary, $employment, true));
        $node->appendChild($this->position($dom, $employment));
        $node->appendChild($this->income($dom, $summary));

        return $node;
    }

    /**
     * `identifikaceType` je `xs:choice` a staví se OBĚ jeho větve.
     *
     * ── Větev A: OIČ (10051) + ID PPV (10228) ───────────────────────────────
     * Jakmile ČSSZ obě čísla přidělí, je jejich uvádění ve všech dalších
     * hlášeních povinné, a jmenná větev se už nepoužije.
     *
     * ── Větev B: příjmení (10053) + jméno (10054) + datum narození (10056)
     *    + datum nástupu (10223) + druh činnosti (10239) ────────────────────
     * Obě čísla přiděluje ČSSZ sama až protokolem o přijetí registrace, takže
     * PRVNÍ hlášení za nově registrovaného zaměstnance je nemá odkud vzít.
     * Přesně na to má XSD jmennou větev: zaměstnanec se ohlásí jménem, ČSSZ v
     * protokolu OIČ i ID PPV přidělí a další hlášení už jede větví A. Odmítat
     * podání kvůli chybějícímu OIČ znamenalo požadovat po účetní číslo, které
     * vzniká až tímhle podáním.
     *
     * Pořadí elementů obou větví odpovídá `xs:sequence` v `formCommonTypes.xsd`
     * — `xs:choice` sice vybírá větev, uvnitř větve je ale pořadí závazné.
     *
     * @param array<string,mixed> $employment
     */
    private function identification(
        DOMDocument $dom,
        array $employment,
    ): DOMElement {
        $identity = $this->object($employment['identity'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:identifikace');
        $person = $identity['person_external_identifier'] ?? null;
        $employmentIdentifier = $identity['employment_external_identifier'] ?? null;
        if ($person !== null || $employmentIdentifier !== null) {
            // Půlka dvojice není větev A: `$this->string()` chybějící protějšek
            // ohlásí jako nedoložený atribut a podání se nepostaví.
            $this->text(
                $dom,
                $node,
                JmhzSchemaCatalog::NS_FORM,
                'form:ikMpsv',
                $this->string($person, '10051'),
            );
            $this->text(
                $dom,
                $node,
                JmhzSchemaCatalog::NS_FORM,
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
            'form:datumNastupu' => $this->identityDate(
                $identity['employment_start_date'] ?? null,
                '10223',
            ),
            'form:druhCinnosti' => $this->identityActivity(
                $selector['activity_code'] ?? null,
                '10239',
            ),
        ] as $element => $value) {
            $this->text($dom, $node, JmhzSchemaCatalog::NS_FORM, $element, $value);
        }

        return $node;
    }

    /** Povinný textový údaj jmenné větve `identifikaceType`. */
    private function identityName(mixed $value, string $attributeId): string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->identityUnresolved($attributeId);
        }

        return $value;
    }

    /** Povinné datum jmenné větve `identifikaceType` (`xs:date`). */
    private function identityDate(mixed $value, string $attributeId): string
    {
        $text = $this->identityName($value, $attributeId);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $text
        ) {
            $this->identityUnresolved($attributeId);
        }

        return $text;
    }

    /**
     * Druh činnosti jmenné větve. `druhCinnostiType` připouští jednu až dvě
     * číslice 1–99 nebo jedno až dvě velká písmena; cokoli jiného by spadlo až
     * na XSD, a to je pro účetní nečitelná hláška.
     */
    private function identityActivity(mixed $value, string $attributeId): string
    {
        $text = $this->identityName($value, $attributeId);
        if (preg_match('/^([1-9][0-9]?|[A-Z]{1,2})$/D', $text) !== 1) {
            $this->identityUnresolved($attributeId);
        }

        return $text;
    }

    /**
     * Blokátor jmenné větve. Kód je VLASTNÍ, ne identifikátorový: chybí-li
     * jméno, datum nástupu nebo druh činnosti, je vadou přesně ten údaj —
     * poslat účetní shánět OIČ, které stejně přiděluje až ČSSZ, by ji poslalo
     * za prací, kterou udělat nemůže.
     */
    private function identityUnresolved(string $attributeId): never
    {
        $this->invalid(
            'jmhz_xml_identity_name_incomplete',
            "Zaměstnanec nemá od ČSSZ přidělené OIČ ani ID PPV, hlásí se proto "
                . "jménem — a k tomu chybí doložený atribut {$attributeId}.",
        );
    }

    /** @param array<string,mixed> $summary */
    private function employeeSummary(
        DOMDocument $dom,
        array $summary,
        bool $cinnostKs = false,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:souhrnDataZec');
        $income = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prijmy');
        $incomeTotal = $this->int($summary['income_total_czk'] ?? null, '10286');
        $this->text(
            $dom,
            $income,
            JmhzSchemaCatalog::NS_FORM,
            'form:zuctovanoCelkem',
            (string) $incomeTotal,
        );
        /*
         * Osvobozené příjmy ze zúčtovaných příjmů (10289) stojí v sekvenci
         * hned za úhrnem a jsou jeho PODMNOŽINOU: kontrola 97 ČSSZ zní
         * „(10289) =< (10286)".
         *
         * `null` znamená NEUVEDENO — příprava zmrazená dřív, než se úhrn
         * odvozoval. Nula by tvrdila, že zaměstnanec žádný osvobozený příjem
         * neměl, což z takového řezu neplyne.
         */
        $exemptIncome = $summary['exempt_income_czk'] ?? null;
        if ($exemptIncome !== null) {
            $exemptIncome = $this->int($exemptIncome, '10289');
            if ($exemptIncome > $incomeTotal) {
                $this->invalid(
                    'jmhz_xml_exempt_income_exceeds_total',
                    'Osvobozené příjmy nesmějí být vyšší než zúčtovaný příjem'
                        . ' celkem.',
                );
            }
            $this->text(
                $dom,
                $income,
                JmhzSchemaCatalog::NS_FORM,
                'form:osvobozenoCelkem',
                (string) $exemptIncome,
            );
        }
        $node->appendChild($income);

        $declarationSigned = $this->bool(
            $summary['taxpayer_declaration_signed'] ?? null,
            '10419',
        );
        $advance = $this->object($summary['advance_tax_czk'] ?? null);
        $withholding = $summary['withholding_tax_czk'] ?? null;
        $withholding = is_array($withholding) && !array_is_list($withholding)
            ? $withholding
            : null;
        /*
         * Osoba zdaněná výhradně zvláštní sazbou (§ 6 odst. 4 ZDP — typicky
         * podlimitní DPP bez prohlášení) žádnou zálohu nemá. Vypsat kvůli XSD
         * `zalohaNaDan` s nulami je tatáž třída chyby jako nulový `danBonus`
         * u kontroly 244, proto se celý blok vynechává; XSD ho má
         * `minOccurs="0"`. Souběh zálohy a srážky (víc vztahů) je legitimní
         * a vypíší se oba bloky.
         */
        $advanceIsEmpty = true;
        foreach (['base', 'computed', 'after_credits', 'bonus'] as $key) {
            if ($this->int($advance[$key] ?? null, '10297') !== 0) {
                $advanceIsEmpty = false;
                break;
            }
        }
        $skipAdvance = $withholding !== null && $advanceIsEmpty;
        $tax = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:zalohaNaDan');
        foreach ($skipAdvance ? [] : [
            'form:zakladDane' => ['base', '10297'],
            'form:vypoctenaZaloha' => ['computed', '10298'],
            'form:danZalohaPoSleve' => ['after_credits', '10305'],
            'form:danBonus' => ['bonus', '10306'],
        ] as $element => [$key, $attributeId]) {
            if ($element === 'form:danBonus' && !$declarationSigned) {
                // Kontrola 244 bere za „vyplněný" atribut samotnou přítomnost
                // elementu, ne až nenulovou částku — nulový bonus u zaměstnance
                // bez prohlášení nechal ČSSZ celý formulář odmítnout (40244,
                // atribut 10306). Bonus bez prohlášení navíc vzniknout nemůže,
                // takže se element vynechává; nenulová hodnota je rozpor.
                if ($this->int($advance[$key] ?? null, $attributeId) !== 0) {
                    $this->invalid(
                        'jmhz_xml_bonus_without_declaration',
                        'Měsíční daňový bonus nelze vykázat bez podepsaného'
                            . ' prohlášení poplatníka.',
                    );
                }

                continue;
            }
            $this->text(
                $dom,
                $tax,
                JmhzSchemaCatalog::NS_FORM,
                $element,
                (string) $this->int($advance[$key] ?? null, $attributeId),
            );
        }
        if (!$skipAdvance) {
            $node->appendChild($tax);
        }
        if ($withholding !== null) {
            $block = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:zvlastniSazbaDane',
            );
            $this->text(
                $dom,
                $block,
                JmhzSchemaCatalog::NS_FORM,
                'form:zakladDane',
                (string) $this->int($withholding['base'] ?? null, '10307'),
            );
            $this->text(
                $dom,
                $block,
                JmhzSchemaCatalog::NS_FORM,
                'form:srazenaDan',
                (string) $this->int($withholding['tax'] ?? null, '10309'),
            );
            $node->appendChild($block);
        }

        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
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
        if ($claimed !== [] || $childCredit !== null) {
            if (!$declarationSigned) {
                // Slevu lze uplatnit jen s podepsaným prohlášením; kdyby to
                // vyšlo naopak, hlásili bychom vnitřně rozporný formulář.
                $this->invalid(
                    'jmhz_xml_credit_without_declaration',
                    'Uplatněnou slevu na dani nelze vykázat bez podepsaného prohlášení poplatníka.',
                );
            }
            $block = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:prohlaseniPoplatnikaDane',
            );
            foreach ($claimed as $element => [$key, $attributeId]) {
                $this->text(
                    $dom,
                    $block,
                    JmhzSchemaCatalog::NS_FORM,
                    $element,
                    (string) $this->int($credits[$key] ?? null, $attributeId),
                );
            }
            if ($childCredit !== null) {
                $this->monthlyChildCredit($dom, $block, $childCredit);
            }
            $node->appendChild($block);
        }

        $annual = $this->object($summary['annual'] ?? null);
        if ($annual !== []) {
            $annualNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:rocniUhrny',
            );
            $withholding = $this->object($annual['withholding'] ?? null);
            if ($withholding !== []) {
                $this->text(
                    $dom,
                    $annualNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:prijemSrazkDanZvlSazba',
                    (string) $this->int($withholding['paid_income_czk'] ?? null, '10311'),
                );
                $this->text(
                    $dom,
                    $annualNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:danSrazenaZvlSazba',
                    (string) $this->int(
                        $withholding['withholding_tax_czk'] ?? null,
                        '10312',
                    ),
                );
            }
            if (is_bool($annual['requested'] ?? null)) {
                $this->text(
                    $dom,
                    $annualNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:rocniZuctovaniZadost',
                    $annual['requested'] ? 'true' : 'false',
                );
            }
            $performed = $this->bool(
                $annual['performed'] ?? null,
                '10320',
            );
            $this->text(
                $dom,
                $annualNode,
                JmhzSchemaCatalog::NS_FORM,
                'form:rocniZuctovaniProvedeno',
                $performed ? 'true' : 'false',
            );
            if ($performed) {
                $result = $this->object($annual['result'] ?? null);
                $resultNode = $this->node(
                    $dom,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:vysledekRocnihoZuctovani',
                );
                foreach ([
                    'form:preplatekRok' => ['settlement_difference_czk', '10321'],
                    'form:danPreplatekRok' => ['tax_difference_czk', '10322'],
                ] as $element => [$key, $attributeId]) {
                    $this->text(
                        $dom,
                        $resultNode,
                        JmhzSchemaCatalog::NS_FORM,
                        $element,
                        (string) $this->int($result[$key] ?? null, $attributeId),
                    );
                }
                $this->text(
                    $dom,
                    $resultNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:danBonusPreplatekRok',
                    (string) $this->signedInt(
                        $result['bonus_difference_czk'] ?? null,
                        '10323',
                    ),
                );
                $this->text(
                    $dom,
                    $resultNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:uplatnenaSlevaNaPartnera',
                    $this->bool($result['spouse_credit_claimed'] ?? null, '10420')
                        ? 'true'
                        : 'false',
                );
                $this->text(
                    $dom,
                    $resultNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:uplatnenoZvyhodneniNaDeti',
                    $this->bool($result['child_credit_claimed'] ?? null, '10454')
                        ? 'true'
                        : 'false',
                );
                if (($result['child_credit_claimed'] ?? null) === true) {
                    $this->annualChildCredit($dom, $resultNode, $result);
                }
                $annualNode->appendChild($resultNode);
            }
            $node->appendChild($annualNode);
        }

        if (!$cinnostKs) {
            $net = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mzdaCista');
            $this->text(
                $dom,
                $net,
                JmhzSchemaCatalog::NS_FORM,
                'form:mzdaCista',
                (string) $this->int($summary['net_income_czk'] ?? null, '10344'),
            );
            $this->text(
                $dom,
                $net,
                JmhzSchemaCatalog::NS_FORM,
                'form:srazkyZeMzdyEvidovany',
                $this->bool($summary['deductions_recorded'] ?? null, '10116')
                    ? 'true'
                    : 'false',
            );
            $node->appendChild($net);
        }

        $healthAmounts = $cinnostKs
            ? ['form:zdravPojZamestnanec' => ['employee_health_czk', '10371']]
            : [
                'form:zdravPojZamestnavatel' => ['employer_health_czk', '10482'],
                'form:zdravPojZamestnanec' => ['employee_health_czk', '10371'],
            ];
        foreach ($healthAmounts as $element => [$key, $attributeId]) {
            $wrapper = $this->node($dom, JmhzSchemaCatalog::NS_FORM, $element);
            $this->text(
                $dom,
                $wrapper,
                JmhzSchemaCatalog::NS_FORM,
                'form:zdravotniPojisteni',
                (string) $this->int($summary[$key] ?? null, $attributeId),
            );
            $node->appendChild($wrapper);
        }

        return $node;
    }

    /** @param array<string,mixed> $result */
    private function annualChildCredit(
        DOMDocument $dom,
        DOMElement $resultNode,
        array $result,
    ): void {
        $details = $this->object($result['child_credit_details'] ?? null);
        $children = $this->rows($details['children'] ?? null);
        if ($children === []) {
            $this->unresolved('10446');
        }
        $block = $this->node(
            $dom,
            JmhzSchemaCatalog::NS_FORM,
            'form:zvyhodneniNaDeti',
        );
        $other = $this->bool(
            $details['other_household_caregiver'] ?? null,
            '10455',
        );
        $this->text(
            $dom,
            $block,
            JmhzSchemaCatalog::NS_FORM,
            'form:vyzivujeJinaOsoba',
            $other ? 'true' : 'false',
        );
        $caregivers = $this->rows($details['other_household_caregivers'] ?? null);
        if ($other) {
            if ($caregivers === []) {
                $this->unresolved('10441');
            }
            $caregiverList = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:jineOsoby',
            );
            foreach ($caregivers as $caregiver) {
                $caregiverNode = $this->node(
                    $dom,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:jinaOsoba',
                );
                $caregiverNode->appendChild($this->annualPerson(
                    $dom,
                    $this->object($caregiver['identity'] ?? null),
                    'form:osoba',
                    ['10441', '10442', '10443', '10444'],
                ));
                $this->text(
                    $dom,
                    $caregiverNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:mesiceVyzivovani',
                    $this->string($caregiver['months_mask'] ?? null, '10445'),
                );
                $caregiverList->appendChild($caregiverNode);
            }
            $block->appendChild($caregiverList);
        }
        $childList = $this->node(
            $dom,
            JmhzSchemaCatalog::NS_FORM,
            'form:vyzivovaneDeti',
        );
        foreach ($children as $child) {
            $childNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:vyzivovaneDite',
            );
            $childNode->appendChild($this->annualPerson(
                $dom,
                $this->object($child['identity'] ?? null),
                'form:dite',
                ['10446', '10447', '10448', '10449'],
            ));
            $ztp = $this->string($child['ztp_p_months_mask'] ?? null, '10450');
            if ($ztp !== 'NNNNNNNNNNNN') {
                $this->text(
                    $dom,
                    $childNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:prukazZtpp',
                    $ztp,
                );
            }
            $this->text(
                $dom,
                $childNode,
                JmhzSchemaCatalog::NS_FORM,
                'form:poradi',
                $this->string($child['order_months_mask'] ?? null, '10451'),
            );
            $childList->appendChild($childNode);
        }
        $block->appendChild($childList);
        $resultNode->appendChild($block);
    }

    /**
     * @param array<string,mixed> $identity
     * @param array{string,string,string,string} $attributeIds
     */
    /**
     * Měsíční blok `zvyhodneniDetiMesic` uvnitř `prohlaseniPoplatnikaDane`.
     *
     * Pořadí prvků drží sekvenci XSD (`prohlaseniPoplatnikaDaneType`):
     * 10303 `danoveZvyhodneniDetiMesic`, pak blok dětí, teprve pak 10304
     * `slevaDite`. `jineOsoby` se píše jen u 10453 = true — kontrola 127 tam
     * identitu vyžaduje a jinde by šlo o osobní údaj bez účelu.
     *
     * @param array<string,mixed> $childCredit
     */
    private function monthlyChildCredit(
        DOMDocument $dom,
        DOMElement $block,
        array $childCredit,
    ): void {
        $this->text(
            $dom,
            $block,
            JmhzSchemaCatalog::NS_FORM,
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
        $childBlock = $this->node(
            $dom,
            JmhzSchemaCatalog::NS_FORM,
            'form:zvyhodneniDetiMesic',
        );
        $otherCaregiver = $this->bool(
            $childCredit['other_household_caregiver'] ?? null,
            '10453',
        );
        $this->text(
            $dom,
            $childBlock,
            JmhzSchemaCatalog::NS_FORM,
            'form:vyzivujeJinaOsoba',
            $otherCaregiver ? 'true' : 'false',
        );
        if ($otherCaregiver) {
            $caregiverList = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:jineOsoby',
            );
            foreach (
                $this->rows($childCredit['other_household_caregivers'] ?? null)
                as $caregiver
            ) {
                $caregiverList->appendChild($this->annualPerson(
                    $dom,
                    $caregiver,
                    'form:jinaOsoba',
                    ['10431', '10432', '10433', '10434'],
                ));
            }
            $childBlock->appendChild($caregiverList);
        }
        $childList = $this->node(
            $dom,
            JmhzSchemaCatalog::NS_FORM,
            'form:vyzivovaneDeti',
        );
        foreach ($children as $child) {
            $childNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:vyzivovaneDite',
            );
            $childNode->appendChild($this->annualPerson(
                $dom,
                $this->object($child['identity'] ?? null),
                'form:dite',
                ['10435', '10436', '10437', '10438'],
            ));
            $this->text(
                $dom,
                $childNode,
                JmhzSchemaCatalog::NS_FORM,
                'form:prukazZtpp',
                $this->bool($child['ztp_p'] ?? null, '10439') ? 'true' : 'false',
            );
            $this->text(
                $dom,
                $childNode,
                JmhzSchemaCatalog::NS_FORM,
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
            JmhzSchemaCatalog::NS_FORM,
            'form:slevaDite',
            (string) $this->int($childCredit['applied_credit_czk'] ?? null, '10304'),
        );
    }

    private function annualPerson(
        DOMDocument $dom,
        array $identity,
        string $element,
        array $attributeIds,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, $element);
        foreach ([
            'form:jmeno' => ['given_name', $attributeIds[0]],
            'form:prijmeni' => ['family_name', $attributeIds[1]],
        ] as $name => [$key, $attributeId]) {
            $this->text(
                $dom,
                $node,
                JmhzSchemaCatalog::NS_FORM,
                $name,
                $this->string($identity[$key] ?? null, $attributeId),
            );
        }
        if (($identity['birth_date'] ?? null) !== null) {
            $this->text(
                $dom,
                $node,
                JmhzSchemaCatalog::NS_FORM,
                'form:datumNarozeni',
                $this->date($identity['birth_date'], $attributeIds[2]),
            );
        }
        if (($identity['birth_number'] ?? null) !== null) {
            $this->text(
                $dom,
                $node,
                JmhzSchemaCatalog::NS_FORM,
                'form:rodneCislo',
                $this->string($identity['birth_number'], $attributeIds[3]),
            );
        }

        return $node;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function insurance(
        DOMDocument $dom,
        array $summary,
        array $employment,
        bool $cinnostKs = false,
    ): DOMElement {
        $eldp = $this->object($employment['eldp'] ?? null);
        $interval = $this->object($eldp['insurance_interval'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:pojisteni');
        $duration = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:trvani');
        $this->text(
            $dom,
            $duration,
            JmhzSchemaCatalog::NS_FORM,
            'form:pojisteniOd',
            $this->date($interval['insurance_from'] ?? null, '10354'),
        );
        $this->text(
            $dom,
            $duration,
            JmhzSchemaCatalog::NS_FORM,
            'form:pojisteniDo',
            $this->date($interval['insurance_to'] ?? null, '10355'),
        );
        $node->appendChild($duration);

        $social = $this->object($employment['social_base'] ?? null);
        $amount = is_int($social['assessment_base_czk'] ?? null)
            ? $this->int($social['assessment_base_czk'], '10477')
            : null;
        $reportedIncome = is_int($social['reported_income_czk'] ?? null)
            ? $this->int($social['reported_income_czk'], '10476')
            : null;
        if ($amount !== null || $reportedIncome !== null) {
            $base = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vymerovaciZaklad');
            if ($amount !== null) {
                $this->text(
                    $dom,
                    $base,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:castkaOdvodPojistneho',
                    (string) $amount,
                );
            }
            if ($reportedIncome !== null) {
                $this->text(
                    $dom,
                    $base,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:prijemNepojistenaCinnost',
                    (string) $reportedIncome,
                );
            }
            $node->appendChild($base);
        }

        // Ve větvi `bezPriznaku` vede matice datových scénářů dílčí základy
        // podle § 5a jako povinné, a kontroly 216 a 284 to vynucují — ověřeno
        // odmítnutím podání, ve kterém chyběly. U nulového základu se rozpad
        // neuvádí: kontrola 284 se spouští až od nenulové částky a nula
        // rozdělená na složky nenese žádnou informaci.
        if (!$cinnostKs && $amount !== null && $amount > 0) {
            $letter = $social['paragraph5_letter'] ?? null;
            if (!is_string($letter) || !isset(self::PARAGRAPH5_ELEMENTS[$letter])) {
                $this->invalid(
                    'jmhz_xml_employer_rate_category_unknown',
                    'Bez sazbové kategorie zaměstnavatele nelze vyměřovací základ'
                        . ' rozdělit podle § 5a odst. 1 ZPSZ.',
                );
            }
            $split = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:vymerovaciZakladParagraf5',
            );
            $this->text(
                $dom,
                $split,
                JmhzSchemaCatalog::NS_FORM,
                self::PARAGRAPH5_ELEMENTS[$letter],
                (string) $amount,
            );
            $node->appendChild($split);
        }

        $sections = $this->rows($eldp['eldp_sections'] ?? null);
        if ($sections === []) {
            $this->invalid(
                'jmhz_xml_eldp_missing',
                'Součást musí obsahovat alespoň jednu ELDP sekci.',
            );
        }
        $list = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:eldpSeznam');
        foreach ($sections as $section) {
            $entry = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:eldp');
            $code = $section['code'] ?? null;
            if (is_string($code) && $code !== '') {
                $this->text($dom, $entry, JmhzSchemaCatalog::NS_FORM, 'form:kod', $code);
                $this->text(
                    $dom,
                    $entry,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:platnostOd',
                    $this->date($section['valid_from'] ?? null, '10241'),
                );
                $this->text(
                    $dom,
                    $entry,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:platnostDo',
                    $this->date($section['valid_to'] ?? null, '10242'),
                );
            }
            $days = $this->int($section['insurance_days'] ?? null, '10356');
            $this->text($dom, $entry, JmhzSchemaCatalog::NS_FORM, 'form:pocetDnu', (string) $days);
            // 10240 je povinný právě když 10356 > 0; opačně by kód bez dnů
            // vykázal neexistující dobu pojištění.
            if ($days > 0 && !(is_string($code) && $code !== '')) {
                $this->invalid(
                    'jmhz_xml_eldp_code_required',
                    'ELDP sekce s nenulovým počtem dnů musí mít kód ELDP.',
                );
            }
            if (is_int($section['assessment_base_czk'] ?? null)) {
                $this->text(
                    $dom,
                    $entry,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:vymerovaciZaklad',
                    (string) $this->int($section['assessment_base_czk'], '10245'),
                );
            }
            $this->appendEldpExcludedDays($dom, $entry, $section, $code);
            $list->appendChild($entry);
        }
        $node->appendChild($list);

        foreach ([
            'form:pojisteniZamestnanec' => ['employee_social_czk', '10370'],
            'form:pojisteniZamestnavatel' => ['employer_social_czk', '10481'],
        ] as $element => [$key, $attributeId]) {
            if (!is_int($summary[$key] ?? null) || $amount === null) {
                continue;
            }
            $wrapper = $this->node($dom, JmhzSchemaCatalog::NS_FORM, $element);
            $this->text(
                $dom,
                $wrapper,
                JmhzSchemaCatalog::NS_FORM,
                'form:socialniPojisteni',
                (string) $this->int($summary[$key] ?? null, $attributeId),
            );
            $node->appendChild($wrapper);
        }

        /*
         * Sleva na pojistném ZAMĚSTNANCE — 10490 (pracující důchodci, § 7d
         * ZPSZ) a 10546 (ovocnářství a pěstování zeleniny). Matice povinností
         * 1.4.0.2 vede oba příznaky jako povinné jádro scénáře, ne jako údaj
         * podmíněný interakcí, a `slevaZamestnanceType` je má oba v jednom
         * bloku před slevou zaměstnavatele.
         *
         * „Ne" tady není dopočtená nula. Příprava se zastaví hned, jakmile je
         * sleva zaměstnance nenulová
         * ({@see JmhzPreparationSnapshotBuilder::inspectDiscounts()},
         * `jmhz_employee_social_discount_unsupported`), takže do serializace se
         * dostane jen měsíc, ve kterém se ani jedna sleva neuplatňuje.
         *
         * Vykázat to je nutné, ne kosmetické: kontrola 297 poměřuje počet
         * zaměstnanců v `pvpoj:slevyZamestnancu` s počtem vztahů, u nichž je
         * 10490 = ANO, a kontrola 213 stejně tak úhrn vyměřovacích základů.
         * Mlčení na formulářích při vyplněné pojistné části je proto rozpor
         * uvnitř jednoho podání.
         *
         * AŽ blokace padne, musí se sem číst skutečný stav vztahu — hodnota
         * `true` se nikdy nesmí objevit u obou zároveň (kontrola 275).
         */
        $employeeDiscounts = $this->node(
            $dom,
            JmhzSchemaCatalog::NS_FORM,
            'form:slevaZamestnance',
        );
        $this->text(
            $dom,
            $employeeDiscounts,
            JmhzSchemaCatalog::NS_FORM,
            'form:slevaZamestnanceEvidovana',
            'false',
        );
        $this->text(
            $dom,
            $employeeDiscounts,
            JmhzSchemaCatalog::NS_FORM,
            'form:slevaZamestnanceOvoZelEvidovana',
            'false',
        );
        $node->appendChild($employeeDiscounts);

        /*
         * Sleva na pojistném ZAMĚSTNAVATELE podle § 7a stojí v sekvenci
         * `pojisteniBezPriznakuType` až za pojistným.
         *
         * Příznak 10372 je povinné jádro scénáře, ne údaj podmíněný interakcí,
         * takže se vykazuje VŽDY — u vztahu bez slevy jako „ne". Rozpad
         * (10373/10374) patří pod interakci IN02 a připíná se jen tam, kde se
         * sleva opravdu uplatňuje.
         *
         * Dřív se celý blok vynechával s odůvodněním, že prázdný blok přidá
         * kontrole 1 ČSSZ zaměstnance, který slevu nemá. To neplatí: kontrola
         * čte HODNOTU příznaku, ne přítomnost bloku, stejně jako u slevy
         * zaměstnance (10490). Přijatá hlášení jiných mzdových systémů mají
         * 10372 na každém formuláři.
         *
         * Částka slevy tady NENÍ — § 7c odst. 1 ji odečítá z pojistného za
         * všechny kategorie § 5a odst. 1 dohromady, takže ji hlášení vykazuje
         * jednou za zaměstnavatele (10032), ne po součástech.
         */
        $discount = $this->object($employment['part_time_discount'] ?? null);
        if (!$cinnostKs) {
            $wrapper = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:slevaZamestnavatele',
            );
            $this->text(
                $dom,
                $wrapper,
                JmhzSchemaCatalog::NS_FORM,
                'form:slevaZamestnavateleEvidovana',
                $discount === [] ? 'false' : 'true',
            );
            if ($discount !== []) {
                $split = $this->node(
                    $dom,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:slevaZamestnavateleRozpad',
                );
                // Kontrola 138: rozsah kratší doby se vyplňuje právě u důvodů
                // A až F. U písmene G (§ 7a odst. 1 písm. g), zaměstnanec mladší
                // 21 let) sleva náleží i při plném úvazku a 10373 se uvést NESMÍ.
                $centihours = $discount['weekly_working_time_centihours'] ?? null;
                if ($centihours !== null) {
                    $this->text(
                        $dom,
                        $split,
                        JmhzSchemaCatalog::NS_FORM,
                        'form:pracovniDobaKratsi',
                        $this->decimal($centihours, 2, '10373'),
                    );
                }
                $this->text(
                    $dom,
                    $split,
                    JmhzSchemaCatalog::NS_FORM,
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
    private function position(DOMDocument $dom, array $employment): DOMElement
    {
        $term = $this->object($employment['term'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vykonavanaPozice');
        $place = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mistoVykonuPrace');
        $this->text(
            $dom,
            $place,
            JmhzSchemaCatalog::NS_FORM,
            'form:obec',
            $this->string($term['work_place'] ?? null, '10229'),
        );
        $this->text(
            $dom,
            $place,
            JmhzSchemaCatalog::NS_FORM,
            'form:kodObce',
            $this->string($term['jmhz_workplace_municipality_code'] ?? null, '10230'),
        );
        $this->text(
            $dom,
            $place,
            JmhzSchemaCatalog::NS_FORM,
            'form:kodStatu',
            $this->string($term['jmhz_workplace_country_code'] ?? null, '10231'),
        );
        $node->appendChild($place);

        $apz = $this->tristate($employment, $term, 'jmhz_apz_contribution_status', '10232');
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:uplatnujiPrispevekApz',
            $apz ? 'true' : 'false',
        );
        if ($apz) {
            $this->text(
                $dom,
                $node,
                JmhzSchemaCatalog::NS_FORM,
                'form:nastrojApzKod',
                $this->string($term['jmhz_apz_instrument_code'] ?? null, '10233'),
            );
        }
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:funkcniPozitky',
            $this->tristate($employment, $term, 'jmhz_functional_benefits_status', '10247')
                ? 'true'
                : 'false',
        );
        $assignment = $this->tristate(
            $employment,
            $term,
            'jmhz_temporary_assignment_status',
            '10251',
        );
        if ($assignment) {
            // Identity uživatele (10252/10457/10492-10494) zmrazené nemáme,
            // takže by se `docasnePrideleni` nedalo naplnit.
            $this->invalid(
                'jmhz_xml_temporary_assignment_unsupported',
                'Dočasné přidělení zatím serializér nestaví.',
            );
        }
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:docasnePrideleniEvidovano',
            'false',
        );

        $values = $this->workSummaryValues($employment);
        $fund = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:fondPracovniDoby');
        $this->text(
            $dom,
            $fund,
            JmhzSchemaCatalog::NS_FORM,
            'form:stanovenyFond',
            $this->decimal($values['standard_fund_millihours'] ?? null, 3, '10259'),
        );
        $this->text(
            $dom,
            $fund,
            JmhzSchemaCatalog::NS_FORM,
            'form:sjednanyFond',
            $this->decimal($values['agreed_fund_millihours'] ?? null, 3, '10260'),
        );
        $this->text(
            $dom,
            $fund,
            JmhzSchemaCatalog::NS_FORM,
            'form:stanovenaTydenniDoba',
            $this->decimal($values['weekly_work_centihours'] ?? null, 2, '10261'),
        );
        $node->appendChild($fund);

        return $node;
    }

    /** @param array<string,mixed> $employment */
    private function workMonth(DOMDocument $dom, array $employment): DOMElement
    {
        $values = $this->workSummaryValues($employment);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prubehZamestnani');
        $days = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:odpracovaneDny');
        $this->text(
            $dom,
            $days,
            JmhzSchemaCatalog::NS_FORM,
            'form:dnyEvidencniStav',
            (string) $this->int($values['evidence_days'] ?? null, '10265'),
        );
        $node->appendChild($days);
        $hours = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:odpracovaneHodiny');
        $this->text(
            $dom,
            $hours,
            JmhzSchemaCatalog::NS_FORM,
            'form:pocet',
            $this->decimal($values['worked_millihours'] ?? null, 3, '10268'),
        );
        $node->appendChild($hours);

        $unworked = [
            'form:hodinyNeodpracCelkem' => ['unworked_total_millihours', '10275'],
            'form:hodinyNeodpracNahrada' => ['unworked_paid_millihours', '10276'],
            'form:hodinyNeodpracBezNahrady' =>
                ['dpn_without_employer_compensation_millihours', '10277'],
            'form:hodinyNeodpracNeschop' =>
                ['dpn_with_employer_compensation_millihours', '10278'],
            'form:hodinyNeodpracDovol' => ['vacation_millihours', '10279'],
            'form:hodinyNeodpracOcr' => ['care_millihours', '10280'],
        ];
        if (is_int($values['unworked_total_millihours'] ?? null)) {
            $block = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:neodpracovaneHodiny');
            foreach ($unworked as $element => [$key, $attributeId]) {
                $value = $values[$key] ?? null;
                if (!is_int($value)) {
                    continue;
                }
                $this->text(
                    $dom,
                    $block,
                    JmhzSchemaCatalog::NS_FORM,
                    $element,
                    $this->decimal($value, 3, $attributeId),
                );
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
            $block = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prekazkyVPraci');
            foreach ($obstacles as $element => [$key, $attributeId]) {
                $value = $values[$key] ?? null;
                if (!is_int($value)) {
                    continue;
                }
                $this->text(
                    $dom,
                    $block,
                    JmhzSchemaCatalog::NS_FORM,
                    $element,
                    $this->decimal($value, 3, $attributeId),
                );
            }
            $node->appendChild($block);
        }

        return $node;
    }

    /** @param array<string,mixed> $summary */
    private function income(DOMDocument $dom, array $summary): DOMElement
    {
        $advance = $this->object($summary['advance_tax_czk'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prijem');
        $tax = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:dan');
        $this->text(
            $dom,
            $tax,
            JmhzSchemaCatalog::NS_FORM,
            'form:zakladDane',
            (string) $this->int($advance['taxable_income'] ?? null, '10535'),
        );
        $node->appendChild($tax);

        return $node;
    }

    /** @param array<string,mixed> $employment */
    private function wage(DOMDocument $dom, array $employment): DOMElement
    {
        $earnings = $this->object($employment['earnings_by_attribute_czk'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mzda');
        $wageTotal = $this->int($this->earning($earnings, '10328'), '10328');
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:mzdaZuctovana',
            (string) $wageTotal,
        );
        $components = [];
        foreach ([
            'form:tarif' => '10329',
            'form:odmenyPravidelne' => '10330',
            'form:odmenyNepravidelne' => '10331',
        ] as $element => $attributeId) {
            $components[$element] = $this->int(
                $this->earning($earnings, $attributeId),
                $attributeId,
            );
        }
        /*
         * Kontrola 267 zakazuje vyplnit rozpad při nulové zúčtované mzdě a
         * „vyplněný" je pro ČSSZ — stejně jako u kontroly 244 (viz 40244,
         * atribut 10306) — samotná přítomnost elementu, ne až nenulová částka.
         * Měsíc bez zúčtované mzdy (nemoc po 14. dni, rodičovská, neplacené
         * volno) proto `mzdaRozpad` neuvádí vůbec; XSD ho má `minOccurs="0"`,
         * a jeho tři složky jsou uvnitř povinné, takže je to celý blok, nebo nic.
         * Nenulová složka při nulovém úhrnu je rozpor ve zdrojových datech.
         */
        $surcharges = $this->wageSurcharges($earnings);
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
            $breakdown = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mzdaRozpad');
            foreach ($components as $element => $value) {
                $this->text(
                    $dom,
                    $breakdown,
                    JmhzSchemaCatalog::NS_FORM,
                    $element,
                    (string) $value,
                );
            }
            $this->appendSurcharges($dom, $breakdown, $surcharges);
            $node->appendChild($breakdown);
        }
        $this->appendCompensations($dom, $node, $earnings);
        $this->appendStandbyPay($dom, $node, $earnings);

        $average = $this->object($employment['average_hourly'] ?? null);
        $wrapper = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vydelek');
        // 10345 je v XSD povinný bez `minOccurs=0`, takže tady hlášení skončí
        // i u dohody v prvním měsíci, kde skutečný průměr neexistuje. Generická
        // věta „atribut není doložený" účetní neřekne, kam jít — proto vlastní
        // kód s návodem ze SSOT {@see JmhzBlockerExplainer::guidance()}.
        if (!is_int($average['minor_units'] ?? null)) {
            $this->invalid(
                'jmhz_average_hourly_earning_probable_missing',
                JmhzBlockerExplainer::guidance('jmhz_average_hourly_earning_probable_missing'),
            );
        }
        $this->text(
            $dom,
            $wrapper,
            JmhzSchemaCatalog::NS_FORM,
            'form:vydelekPrumernyHod',
            $this->decimal($average['minor_units'], 2, '10345'),
        );
        $node->appendChild($wrapper);

        return $node;
    }

    /**
     * Příplatky (10332–10336) z vektoru výdělků.
     *
     * Prázdné pole znamená, že v měsíci žádný příplatek evidovaný není —
     * interakce IN10 („V měsíci jsou evidovány příplatky") se neuplatní a
     * `priplatky` se nevykazuje. Vektor totiž nese jen atributy, do kterých
     * má zaměstnavatel namapovanou mzdovou složku, takže PŘÍTOMNOST atributu
     * je tvrzení, ne implicitní nula.
     *
     * Úhrn 10332 je v matici povinností pro IN10 povinný, detaily 10333–10336
     * nepovinné, takže se každý zapíše jen tehdy, když ho vektor nese. Součet
     * detailů se s úhrnem NEporovnává: datový slovník mezi nimi žádný vzorec
     * nemá a v přijatých hlášeních se rozchází.
     *
     * @param array<array-key,mixed> $earnings
     * @return array<string,int>
     */
    private function wageSurcharges(array $earnings): array
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
            $surcharges[$element] = $this->int($value, $attributeId);
        }
        if ($surcharges === []) {
            return [];
        }
        if (!array_key_exists('form:celkem', $surcharges)) {
            // `celkem` je uvnitř `priplatkyType` povinný bez `minOccurs`, takže
            // detail bez úhrnu je nezapsatelný. Nedopočítáváme ho ze složek:
            // úhrn je vlastní atribut hlášení, ne jejich součet.
            $this->invalid(
                'jmhz_xml_surcharge_total_missing',
                'Rozpad příplatků nelze vykázat bez úhrnu příplatků (10332).',
            );
        }

        return $surcharges;
    }

    /**
     * @param array<string,int> $surcharges
     */
    private function appendSurcharges(
        DOMDocument $dom,
        DOMElement $breakdown,
        array $surcharges,
    ): void {
        if ($surcharges === []) {
            return;
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:priplatky');
        foreach ($surcharges as $element => $value) {
            $this->text($dom, $node, JmhzSchemaCatalog::NS_FORM, $element, (string) $value);
        }
        $breakdown->appendChild($node);
    }

    /**
     * Náhrady mzdy (10337–10342).
     *
     * Stojí v `mzdaBezPriznakuType` za rozpadem mzdy a mimo ni: náhrada není
     * mzda za práci, takže se do 10328 nepočítá a měsíc, ve kterém zaměstnanec
     * jen čerpal dovolenou, má `mzdaZuctovana` = 0 a náhrady tady.
     *
     * Úhrn 10337 je pro interakci IN11 povinný. Náhrada při dočasné pracovní
     * neschopnosti (10342) do něj nepatří — stojí vedle, viz
     * {@see \MyInvoice\Service\Payroll\Component\PayrollComponentJmhzTargetCatalog}
     * — takže měsíc, ve kterém byla zúčtovaná jen ona, vykáže povinný úhrn
     * jako nulu. Není to dopočtená nula: vektor v takovém měsíci žádnou
     * složku do 10337 nemapuje, a XSD úhrn vyžaduje.
     *
     * @param array<array-key,mixed> $earnings
     */
    private function appendCompensations(
        DOMDocument $dom,
        DOMElement $wage,
        array $earnings,
    ): void {
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
            $values[$element] = $this->int($value, $attributeId);
        }
        if ($values === []) {
            return;
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:nahrady');
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:mzdyZuctovane',
            (string) ($values['form:mzdyZuctovane'] ?? 0),
        );
        unset($values['form:mzdyZuctovane']);
        foreach ($values as $element => $value) {
            $this->text($dom, $node, JmhzSchemaCatalog::NS_FORM, $element, (string) $value);
        }
        $wage->appendChild($node);
    }

    /**
     * Odměny za pracovní pohotovost (10343, interakce IN12).
     *
     * `odmenyType` má `pohotovost` jako jediný a povinný prvek, takže blok
     * vzniká právě tehdy, když vektor atribut nese.
     *
     * @param array<array-key,mixed> $earnings
     */
    private function appendStandbyPay(
        DOMDocument $dom,
        DOMElement $wage,
        array $earnings,
    ): void {
        $value = $this->earning($earnings, '10343');
        if ($value === null) {
            return;
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:odmeny');
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:pohotovost',
            (string) $this->int($value, '10343'),
        );
        $wage->appendChild($node);
    }

    /**
     * Klíče vektoru výdělků jsou čísla atributů, takže je PHP drží jako
     * celočíselné indexy; `array_key_exists()` je tu jediné bezpečné čtení.
     *
     * @param array<array-key,mixed> $earnings
     */
    private function earning(array $earnings, string $attributeId): mixed
    {
        return array_key_exists($attributeId, $earnings)
            ? $earnings[$attributeId]
            : null;
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function workSummaryValues(array $employment): array
    {
        $summary = $this->object(
            $this->object($employment['work_month'] ?? null)['jmhz_work_summary'] ?? null,
        );
        $values = $this->object($summary['values'] ?? null);
        if ($values === []) {
            $this->invalid(
                'jmhz_xml_work_summary_missing',
                'Součást nemá zmrazený pracovní souhrn.',
            );
        }

        return $values;
    }

    /**
     * Vyloučené doby ELDP podle § 16 odst. 4 písm. a) zákona č. 155/1995 Sb.
     *
     * Blok se zapisuje jen tam, kde sekce nese kód ELDP: bez kódu ho kontrola
     * ČSSZ (atributy 10357 a 10358–10536 bez 10240) odmítne — vyloučená doba
     * bez doby pojištění nedává smysl.
     *
     * Rozpad na složky se uvádí jen při nenulovém úhrnu. Kontrola 329 říká, že
     * při 10357 = 0 nesmí být složky vyplněné nenulově, a nulový rozpad nenese
     * žádnou informaci; kontrola 121 pak při kladném úhrnu vyžaduje
     * 10357 = 10358 + 10359 + 10360 + 10362 + 10536, což se tady ověří dřív,
     * než se cokoliv zapíše.
     *
     * Chybějící `excluded_days_total` znamená sekci zmrazenou starším
     * builderem, který vyloučené doby vůbec neodvozoval — takový řez uměl
     * vzniknout jen bez nepřítomnosti nebo s dovolenou, tedy vždy s nulou.
     * Blok se pro něj nezapíše (element je v XSD nepovinný), místo aby se
     * doplnila nula, kterou zdroj netvrdí.
     *
     * Odečítané doby (10375, 10462–10469) se nezapisují vůbec: týkají se dob
     * po dosažení důchodového věku, který aplikace nezná, a builder je proto
     * nechává neuvedené.
     *
     * @param array<string,mixed> $section
     */
    private function appendEldpExcludedDays(
        DOMDocument $dom,
        DOMElement $entry,
        array $section,
        mixed $code,
    ): void {
        $total = $section['excluded_days_total'] ?? null;
        $components = $section['excluded_days'] ?? null;
        // Vyloučené DNY podle § 18 odst. 7 jsou samostatná veličina, ne rozpad
        // vyloučených DOB — sdílejí jen element `vylouceneDny`. Řez, který má
        // jen je (měsíc s neplaceným volnem a bez omluvného důvodu podle
        // § 16 odst. 4), je proto pořád řez, který má co vykázat.
        $hasSection18 = ($section['section18_days_total'] ?? null) !== null;
        if ($total === null && ($components === null || $components === [])) {
            if (!$hasSection18) {
                return;
            }
            if (!is_string($code) || $code === '') {
                return;
            }
            $block = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vylouceneDny');
            $this->appendEldpSection18Days($dom, $block, $section);
            $entry->appendChild($block);

            return;
        }
        /*
         * Sekce BEZ kódu ELDP je vztah, který žádný ELDP nemá — dohoda pod
         * hranicí účasti má nula dnů pojištění. Nula vyloučených dob tam není
         * tvrzení, ale prázdno, takže není co zapisovat a není proč padat:
         * jedna DPP by jinak shodila celé hlášení na tom, že nemá kód.
         *
         * V sekci S kódem se nula naopak zapisuje — tam je to tvrzení „žádné
         * vyloučené doby nebyly", a vynechat ho by znamenalo mlčet.
         *
         * Kontrola níž tak zůstává na tom, na čem záleží: vyloučené doby, které
         * NĚCO tvrdí, ale nemají se kam zapsat.
         */
        if ($total === 0
            && !$this->hasNonZeroExcludedDays($components)
            && !$hasSection18
            && !(is_string($code) && $code !== '')
        ) {
            return;
        }
        if (!is_string($code) || $code === '') {
            $this->invalid(
                'jmhz_xml_eldp_excluded_days_without_code',
                'Vyloučené doby nelze vykázat v ELDP sekci bez kódu ELDP.',
            );
        }
        $total = $this->int($total, '10357');
        if (!is_array($components) || array_is_list($components)) {
            $this->unresolved('10357');
        }
        $sum = 0;
        $values = [];
        foreach (self::ELDP_EXCLUDED_DAYS as $key => $attributeId) {
            $values[$key] = $this->int($components[$key] ?? null, $attributeId);
            $sum += $values[$key];
        }
        if ($sum !== $total || count($components) !== count($values)) {
            $this->invalid(
                'jmhz_xml_eldp_excluded_days_sum_mismatch',
                'Úhrn vyloučených dob neodpovídá rozpadu podle § 16 odst. 4'
                    . ' zákona č. 155/1995 Sb.',
            );
        }
        $block = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vylouceneDny');
        $this->text(
            $dom,
            $block,
            JmhzSchemaCatalog::NS_FORM,
            'form:vylouceneDobyCelkem',
            (string) $total,
        );
        if ($total > 0) {
            foreach ($values as $key => $value) {
                $this->text(
                    $dom,
                    $block,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:' . $key,
                    (string) $value,
                );
            }
        }
        $this->appendEldpSection18Days($dom, $block, $section);
        $entry->appendChild($block);
    }

    /**
     * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. (10366 a rozpad
     * 10473–10475).
     *
     * Jsou to dny vyřazené z rozhodného období pro denní vyměřovací základ
     * nemocenských dávek, ne vyloučené DOBY důchodového pojištění — proto
     * stojí ve `vylouceneDnyType` vedle sebe a smějí se v týchž dnech
     * překrývat (nemoc je v obou).
     *
     * `null` v řezu znamená NEUVEDENO: buď jde o řez zmrazený dřív, než se
     * § 18 odvozoval, nebo v měsíci byla nepřítomnost, jejíž rozpad na
     * 10473/10474/10475 ze zmrazeného snapshotu neplyne. Datový slovník
     * předepisuje `10366 = 10473 + 10474 + 10475`, takže se v obou případech
     * mlčí — vykázat část by tvrdilo, že zbytek je nula. Matice povinností
     * 1.4.0.2 to dovoluje: 10366 je „nepovinné, pokud je vyplněn 10357 > 0".
     *
     * @param array<string,mixed> $section
     */
    private function appendEldpSection18Days(
        DOMDocument $dom,
        DOMElement $block,
        array $section,
    ): void {
        $total = $section['section18_days_total'] ?? null;
        $components = $section['section18_days'] ?? null;
        if ($total === null || !is_array($components) || array_is_list($components)) {
            return;
        }
        $total = $this->int($total, '10366');
        $sum = 0;
        $values = [];
        foreach (self::ELDP_SECTION18_DAYS as $key => $attributeId) {
            $values[$key] = $this->int($components[$key] ?? null, $attributeId);
            $sum += $values[$key];
        }
        if ($sum !== $total || count($components) !== count($values)) {
            $this->invalid(
                'jmhz_xml_eldp_section18_days_sum_mismatch',
                'Úhrn vyloučených dnů neodpovídá rozpadu podle § 18 odst. 7'
                    . ' zákona č. 187/2006 Sb.',
            );
        }
        $this->text(
            $dom,
            $block,
            JmhzSchemaCatalog::NS_FORM,
            'form:vyloucenePar18',
            (string) $total,
        );
        foreach ($values as $key => $value) {
            $this->text(
                $dom,
                $block,
                JmhzSchemaCatalog::NS_FORM,
                'form:' . $key,
                (string) $value,
            );
        }
    }

    private function node(
        DOMDocument $dom,
        string $namespace,
        string $name,
    ): DOMElement {
        return $dom->createElementNS($namespace, $name);
    }

    private function text(
        DOMDocument $dom,
        DOMElement $parent,
        string $namespace,
        string $name,
        string $value,
    ): void {
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

    /**
     * Tri-state z účinného termu.
     *
     * `unverified` se vykládá jako `no` — příspěvek APZ, funkční požitky ani
     * dočasné přidělení drtivá většina firem nemá a nevyplnění je legitimní
     * odpověď „ne". Do formuláře se tak dostane `false`, ale JEN tehdy, když to
     * zmrazený snímek u vztahu doloží záznamem v `jmhz_default_interpretations`
     * (viz {@see JmhzPreparationSnapshotBuilder::DEFAULTED_TRISTATES}). Bez
     * něj by podání tvrdilo za účetní něco, co nikde není zapsané, a proto se
     * radši nepostaví.
     *
     * @param array<string,mixed> $employment zmrazený vztah ze snímku
     * @param array<string,mixed> $term účinné podmínky vztahu
     */
    private function tristate(
        array $employment,
        array $term,
        string $field,
        string $attributeId,
    ): bool {
        $value = $term[$field] ?? null;
        if ($value === 'yes') {
            return true;
        }
        if ($value === 'no') {
            return false;
        }
        if ($value === 'unverified'
            && $this->defaultInterpretation($employment, $field, $attributeId)
        ) {
            return false;
        }
        $this->unresolved($attributeId);
    }

    /**
     * Nese zmrazený snímek doklad, že se u tohoto vztahu nevyplněná hodnota
     * vyložila jako „ne"? Starší snímky (do v11) ho nemají — u těch se nic
     * nedomýšlí.
     *
     * @param array<string,mixed> $employment
     */
    private function defaultInterpretation(
        array $employment,
        string $field,
        string $attributeId,
    ): bool {
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
                && ($record['basis'] ?? null)
                    === JmhzPreparationSnapshotBuilder::DEFAULT_TRISTATE_BASIS
            ) {
                return true;
            }
        }

        return false;
    }

    private function date(mixed $value, string $attributeId): string
    {
        if (!is_string($value)) {
            $this->unresolved($attributeId);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->unresolved($attributeId);
        }

        return $value;
    }

    /**
     * Škálovaná celá čísla se převádějí na desetinný zápis bez plovoucí
     * čárky, aby se nikde neztratila poslední platná číslice.
     */
    private function decimal(mixed $value, int $scale, string $attributeId): string
    {
        if (!is_int($value) || $value < 0) {
            $this->unresolved($attributeId);
        }
        $divisor = 10 ** $scale;

        return intdiv($value, $divisor) . '.'
            . str_pad((string) ($value % $divisor), $scale, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @param mixed $components rozpad vyloučených dob, jak ho zmrazil builder */
    private function hasNonZeroExcludedDays(mixed $components): bool
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
