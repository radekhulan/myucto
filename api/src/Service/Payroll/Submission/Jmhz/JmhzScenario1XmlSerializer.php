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
        $this->text(
            $dom,
            $monthly,
            JmhzSchemaCatalog::NS_SOUHRN,
            'so:danBonus',
            (string) $this->int($totals['tax_bonus'] ?? null, '10035'),
        );
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

    /** @param array<string,mixed> $employment */
    private function identification(
        DOMDocument $dom,
        array $employment,
    ): DOMElement {
        // `identifikaceType` je `xs:choice`. Po doručení OIČ a ID zaměstnání je
        // povinné je uvádět ve všech dalších hlášeních, takže se používá jen
        // identifikátorová větev; jmenná se záměrně nestaví vůbec.
        $identity = $this->object($employment['identity'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:identifikace');
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:ikMpsv',
            $this->string($identity['person_external_identifier'] ?? null, '10051'),
        );
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:idPpv',
            $this->string($identity['employment_external_identifier'] ?? null, '10228'),
        );

        return $node;
    }

    /** @param array<string,mixed> $summary */
    private function employeeSummary(
        DOMDocument $dom,
        array $summary,
        bool $cinnostKs = false,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:souhrnDataZec');
        $income = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prijmy');
        $this->text(
            $dom,
            $income,
            JmhzSchemaCatalog::NS_FORM,
            'form:zuctovanoCelkem',
            (string) $this->int($summary['income_total_czk'] ?? null, '10286'),
        );
        $node->appendChild($income);

        $advance = $this->object($summary['advance_tax_czk'] ?? null);
        $tax = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:zalohaNaDan');
        foreach ([
            'form:zakladDane' => ['base', '10297'],
            'form:vypoctenaZaloha' => ['computed', '10298'],
            'form:danZalohaPoSleve' => ['after_credits', '10305'],
            'form:danBonus' => ['bonus', '10306'],
        ] as $element => [$key, $attributeId]) {
            $this->text(
                $dom,
                $tax,
                JmhzSchemaCatalog::NS_FORM,
                $element,
                (string) $this->int($advance[$key] ?? null, $attributeId),
            );
        }
        $node->appendChild($tax);

        $declarationSigned = $this->bool(
            $summary['taxpayer_declaration_signed'] ?? null,
            '10419',
        );
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
        if ($claimed !== []) {
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

        // Sleva zaměstnavatele podle § 7a stojí v sekvenci
        // `pojisteniBezPriznakuType` až za pojistným, a to jen tehdy, když se
        // uplatňuje: prázdný blok by kontrole 1 ČSSZ přidal zaměstnance, který
        // v pojistné části slevu nemá. Částka slevy tady NENÍ — § 7c odst. 1 ji
        // odečítá z pojistného za všechny kategorie § 5a odst. 1 dohromady,
        // takže ji hlášení vykazuje jednou za zaměstnavatele (10032), ne po
        // součástech.
        $discount = $this->object($employment['part_time_discount'] ?? null);
        if (!$cinnostKs && $discount !== []) {
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
                'true',
            );
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

        $apz = $this->tristate($term['jmhz_apz_contribution_status'] ?? null, '10232');
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
            $this->tristate($term['jmhz_functional_benefits_status'] ?? null, '10247')
                ? 'true'
                : 'false',
        );
        $assignment = $this->tristate(
            $term['jmhz_temporary_assignment_status'] ?? null,
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
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:mzdaZuctovana',
            (string) $this->int($this->earning($earnings, '10328'), '10328'),
        );
        $breakdown = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mzdaRozpad');
        foreach ([
            'form:tarif' => '10329',
            'form:odmenyPravidelne' => '10330',
            'form:odmenyNepravidelne' => '10331',
        ] as $element => $attributeId) {
            $this->text(
                $dom,
                $breakdown,
                JmhzSchemaCatalog::NS_FORM,
                $element,
                (string) $this->int(
                    $this->earning($earnings, $attributeId),
                    $attributeId,
                ),
            );
        }
        $node->appendChild($breakdown);

        $average = $this->object($employment['average_hourly'] ?? null);
        $wrapper = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vydelek');
        $this->text(
            $dom,
            $wrapper,
            JmhzSchemaCatalog::NS_FORM,
            'form:vydelekPrumernyHod',
            $this->decimal($average['minor_units'] ?? null, 2, '10345'),
        );
        $node->appendChild($wrapper);

        return $node;
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
     * Ověřený tri-state z účinného termu. `unverified` NENÍ `no` — bez
     * doloženého rozhodnutí se formulář nestaví.
     */
    private function tristate(mixed $value, string $attributeId): bool
    {
        if ($value === 'yes') {
            return true;
        }
        if ($value === 'no') {
            return false;
        }
        $this->unresolved($attributeId);
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
