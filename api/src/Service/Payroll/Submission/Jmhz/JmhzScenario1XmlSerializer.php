<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;

/**
 * Serializér podporovaných běžných profilů měsíčního hlášení: `scenario_1`
 * (`form:bezPriznaku`) a statutární profil scénáře 3 (`form:cinnostKS`),
 * řádné i obsahově opravné podání, jeden dílčí balík.
 *
 * Pracuje VÝHRADNĚ s vyřešeným normalizovaným dokumentem. Nesahá do databáze,
 * nedopočítává a nezaokrouhluje — každá hodnota, která v dokumentu není
 * zmrazená, je tvrdá chyba, nikdy nula ani `false`. Pořadí elementů odpovídá
 * `xs:sequence` připnutého JMHZ 1.4.3.6; nepovinné bloky, pro které nemáme
 * doložený zdroj, se raději neuvádějí, než aby se odhadovaly.
 */
final class JmhzScenario1XmlSerializer
{
    public function __construct(
        private readonly JmhzPackageSplitter $packageSplitter = new JmhzPackageSplitter(),
    ) {}

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
        /*
         * `SENDER` (XSD ho má nepovinný, i s `ISDSreport`) se u JMHZ záměrně
         * neposílá, na rozdíl od NEMPRI, HZUPN a OZUSPOJ, kde `ISDSreport="3"`
         * zajišťuje strojově čitelnou odpověď v datové schránce. U JMHZ to
         * nic nedokládá: pokyny ani pravidla podání JMHZ atribut nezmiňují,
         * vzorový soubor MPSV ho má jen se zástupnými hodnotami a dílčí
         * protokol ČSSZ chodí do schránky jako XML (JMH-DILCI-PROTOKOL-…),
         * který zpracování čte podle obsahu. Přidat ho by změnilo zmrazenou
         * datovou větu bez doloženého přínosu; e-mailovou notifikaci aplikace
         * u JMHZ nesbírá. Změnit se to má, až ČSSZ pro JMHZ doloží, že bez
         * `ISDSreport` odpověď strojově čitelná není.
         */
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

    /**
     * Nejvýš tolik součástí individualizované části nese jeden dílčí balík
     * (kontroly 300 a 301); první balík k nim přidává souhrn a pojistnou část.
     */
    public const PACKAGE_FORM_LIMIT = JmhzPackageSplitter::DEFAULT_FORM_LIMIT;

    public function packageFormLimit(): int
    {
        return $this->packageSplitter->formLimit;
    }

    /**
     * Řádné hlášení rozdělené do dílčích balíků.
     *
     * Do 1500 formulářů je to jediný balík, bajtově shodný se {@see self::serialize()}.
     * Nad 1500 vzniká víc balíků podle pravidel ČSSZ: všechny nesou tentýž GUID
     * podání i datum a čas vyplnění (obálka), liší se pořadím balíku;
     * souhrn a pojistnou část nese jen první balík (kontrola 235), počet
     * formulářů v balíku je 1500 (+ 2 v prvním) a počet formulářů celkem
     * je součet všech součástí + 2 (kontrola 227).
     *
     * @return list<string>
     */
    public function serializePackages(
        JmhzScenario1NormalizedDocument $document,
        JmhzSubmissionEnvelope $envelope,
    ): array {
        $payload = $document->payload;
        $forms = $this->employmentForms($this->rows($payload['people'] ?? null));
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
            $vendor->setAttribute('productName', $packageEnvelope->productName);
            $vendor->setAttribute('productVersion', $packageEnvelope->productVersion);
            $root->appendChild($vendor);
            $root->appendChild($this->header(
                $dom,
                $payload,
                $packageEnvelope,
                [],
                count($chunk) + ($index === 0 ? 2 : 0),
                $total,
            ));
            if ($index === 0) {
                $root->appendChild($this->summary($dom, $payload));
                $root->appendChild($this->pvpoj($dom, $payload));
            }
            $root->appendChild($this->formsFromPairs($dom, $chunk, $packageEnvelope));
            $xml = $dom->saveXML();
            if ($xml === false) {
                throw new JmhzXmlException(
                    'jmhz_xml_serialization_failed',
                    'XML měsíčního hlášení nelze serializovat.',
                );
            }
            $packages[] = rtrim($xml, "\r\n");
        }

        return $packages;
    }

    /**
     * Zkusí sestavit každý formulář zvlášť a vrátí vady s vazbou na vztah.
     *
     * Používá ji resolver: vadu, kterou pozná až serializér, tak převede na
     * nález u konkrétního pracovního vztahu - dá se na něj prokliknout
     * a vztah se dá odložit z řádného hlášení. Celé XML by spadlo na první
     * vadě a neřeklo by, u koho.
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
            $employeeId = is_int($person['employee_id'] ?? null)
                ? $person['employee_id']
                : null;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                try {
                    $this->bool($employment['primary'] ?? null, '10495');
                    $this->formBody(new DOMDocument('1.0', 'UTF-8'), $summary, $employment);
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

    /**
     * Obsahová oprava v jediném balíku. Nad limit součástí balíku se oprava
     * dělí, viz {@see self::serializeCorrectionPackages()}.
     */
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

    /**
     * Obsahová oprava rozdělená do dílčích balíků.
     *
     * Pravidla podání JMHZ 1.4.5, kap. 3: opravné hlášení s více než 1500
     * opravenými nebo stornovanými součástmi se dělí do více dílčích podání
     * stejně jako řádné ({@see self::serializePackages()}). Všechny balíky
     * nesou GUID řádného podání a týž čas vyplnění, liší se pořadím; souhrn
     * a pojistnou část, opravují-li se, nese jen první balík. Limit se poměřuje
     * s opravovanými součástmi, ne s celou přípravou: oprava jediného vztahu
     * ve firmě s 2 000 zaměstnanci je jeden balík.
     *
     * @return list<string>
     */
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
            $vendor->setAttribute('productName', $packageEnvelope->productName);
            $vendor->setAttribute('productVersion', $packageEnvelope->productVersion);
            $root->appendChild($vendor);
            $root->appendChild($this->correctionHeader(
                $dom,
                $payload,
                $packageEnvelope,
                count($chunk) + ($first ? $layers : 0),
                $total,
            ));
            if ($first && $plan->includeSummary) {
                $root->appendChild($this->summary($dom, $payload));
            }
            if ($first && $plan->includePvpoj) {
                $root->appendChild($this->pvpoj($dom, $payload));
            }
            $root->appendChild($this->correctionForms($dom, $chunk, $packageEnvelope, $plan));

            $xml = $dom->saveXML();
            if ($xml === false) {
                throw new JmhzXmlException(
                    'jmhz_xml_serialization_failed',
                    'XML obsahové opravy měsíčního hlášení nelze serializovat.',
                );
            }
            $packages[] = rtrim($xml, "\r\n");
        }

        return $packages;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertProfile(
        array $payload,
        JmhzSubmissionEnvelope $envelope,
        bool $split = false,
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
        $formCount = count($this->employmentForms($people));
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
        // Řádná cesta staví souhrnnou i pojistnou část vždy. Obsahová oprava
        // povolené podmnožiny ověřuje samostatně v JmhzContentCorrectionPlan.
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            true,
            true,
            array_fill(0, $formCount, JmhzSubmissionFlagMatrix::TYPE_REGULAR),
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
        ?int $packageFormCount = null,
        ?int $totalFormCount = null,
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
        // Součást vzniká za pracovní vztah, takže osoba v souběhu jich má víc.
        $formCount = $packageFormCount ?? count($this->employmentForms($people)) + 2;
        if ($formCount > 1502) {
            $this->invalid(
                'jmhz_xml_form_limit_exceeded',
                'Balík dat pojme nejvýše 1502 formulářů včetně souhrnu a PVPOJ.',
            );
        }
        $this->text($dom, $node, JmhzSchemaCatalog::NS_PODANI, 'formularePocetVBaliku', (string) $formCount);
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_PODANI,
            'formularePocetCelkem',
            (string) ($totalFormCount ?? $formCount),
        );

        return $node;
    }

    /** @param array<string,mixed> $payload */
    private function correctionHeader(
        DOMDocument $dom,
        array $payload,
        JmhzSubmissionEnvelope $envelope,
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
            'formularePocetCelkem' => (string) ($totalFormCount ?? $formCount),
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
        return $this->formsFromPairs($dom, $this->employmentForms($people), $envelope);
    }

    /** @param list<array{0:array<string,mixed>,1:array<string,mixed>}> $pairs */
    private function formsFromPairs(
        DOMDocument $dom,
        array $pairs,
        JmhzSubmissionEnvelope $envelope,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        foreach ($pairs as [$summary, $employment]) {
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
     * Formuláře osob v pořadí podání: jeden za každý pracovněprávní vztah,
     * spolu se souhrnem jeho osoby.
     *
     * Souhrnná data zaměstnance jsou za osobu a nese je jen formulář
     * primárního vztahu (kontrola 248), takže každá osoba musí mít právě jeden
     * primární vztah. Resolver to hlídá blokátorem; tady je to pojistka, aby se
     * souhrn nikdy nevypsal dvakrát ani nechyběl.
     *
     * @param list<array<string,mixed>> $people
     * @return list<array{0:array<string,mixed>,1:array<string,mixed>}>
     */
    private function employmentForms(array $people): array
    {
        $forms = [];
        foreach ($people as $person) {
            $summary = $this->object($person['summary'] ?? null);
            $primaryCount = 0;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                if ($this->bool($employment['primary'] ?? null, '10495')) {
                    $primaryCount++;
                }
                $forms[] = [$summary, $employment];
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
     * @param list<array{0:array<string,mixed>,1:array<string,mixed>}> $forms
     */
    private function correctionForms(
        DOMDocument $dom,
        array $forms,
        JmhzSubmissionEnvelope $envelope,
        JmhzContentCorrectionPlan $plan,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        foreach ($forms as [$summary, $employment]) {
            $employmentId = $employment['employment_id'] ?? null;
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
     * Formuláře vybrané plánem obsahové opravy. Plán je po pracovních
     * vztazích, takže u osoby v souběhu se může opravovat jen jeden z jejích
     * formulářů.
     *
     * @param array<string,mixed> $payload
     * @return list<array{0:array<string,mixed>,1:array<string,mixed>}>
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
        foreach ($this->employmentForms($this->rows($payload['people'] ?? null)) as [$summary, $employment]) {
            $employmentId = $employment['employment_id'] ?? null;
            if (!is_int($employmentId)) {
                continue;
            }
            $correction = $plan->formForEmployment($employmentId);
            if ($correction === null) {
                continue;
            }
            $correction->assertEnvelopeGuid($envelope->formGuid($employmentId));
            $selected[] = [$summary, $employment];
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
        // `souhrnDataZec` (XSD minOccurs=0) nese jen formulář primárního
        // vztahu — kontrola 248; vedlejší vztah ho nemá vůbec.
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $summary));
        }
        $node->appendChild($this->insurance($dom, $summary, $employment));
        $node->appendChild($this->position($dom, $employment));
        $node->appendChild($this->workMonth($dom, $employment));
        $node->appendChild($this->income($dom, $summary, $employment));
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
            'scenario_4' => $this->vezen($dom, $summary, $employment),
            'scenario_5' => $this->jinyPrijem($dom, $summary, $employment),
            'scenario_6' => $this->mezinarodniPronajemSily($dom, $summary, $employment),
            'scenario_8' => $this->odlozenyPrijem($dom, $summary, $employment),
            default => $this->invalid(
                'jmhz_xml_scenario_unsupported',
                'Součást nepatří do podporovaného scénáře JMHZ.',
            ),
        };
    }

    /**
     * Odložený příjem (scénář 8, `formOdlozenyPrijem.xsd`): příjem zúčtovaný
     * po skončení pracovního vztahu. Formulář nemá vykonávanou pozici, průběh
     * zaměstnání ani mzdu; souhrnná data (na primárním formuláři), pojištění
     * s ELDP po obdobích (10537/10538) a daň ano. Typ 10548 je první element.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function odlozenyPrijem(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $deferred = $this->object(
            $this->object($employment['eldp'] ?? null)['deferred_income'] ?? null,
        );
        $type = $this->string($deferred['type'] ?? null, '10548');
        if (!in_array($type, ['1', '2', '3', '4', '5', '6'], true)) {
            $this->invalid(
                'jmhz_xml_deferred_income_type_invalid',
                'Typ odloženého příjmu musí být z číselníku ČSSZ (1 až 6).',
            );
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:odlozenyPrijem');
        $this->text($dom, $node, JmhzSchemaCatalog::NS_FORM, 'form:typ', $type);
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $summary));
        }
        $node->appendChild($this->insurance($dom, $summary, $employment, false, $deferred));
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
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
        if (!PayrollEmploymentJmhzActivityFamily::isCorporateBodyActivity(
            $selector['activity_code'] ?? null,
        )
            || ($selector['relationship_detail_code'] ?? null) !== '1'
        ) {
            $this->invalid(
                'jmhz_xml_scenario_3_profile_unsupported',
                'Větev činnost K–S podporuje druhy činnosti K a N až S'
                    . ' s bližším určením „žádné“.',
            );
        }
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:cinnostKS');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $summary, 'cinnostKS'));
        }
        $node->appendChild($this->insurance($dom, $summary, $employment, true));
        $node->appendChild($this->position($dom, $employment));
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
    }

    /**
     * Vězeň (scénář 4, `formVezen.xsd`): druh činnosti 1 až 9 s bližším
     * určením 10502 = výkon trestu odnětí svobody nebo zabezpečovací detence.
     *
     * Matice „1 až 9 výkon trestu" (Datové scénáře 1.4.0.2) vede podmnožinu
     * scénáře 1: souhrn s čistou mzdou, ale bez zdravotního pojištění;
     * pojištění s trváním, ELDP a slevou zaměstnance, ale bez vyměřovacích
     * základů a pojistného (ty nese jen pojistná část); z průběhu zaměstnání
     * odpracované hodiny; ze mzdy jen náhradu při DPN. Pozici, fond pracovní
     * doby ani rozpad mzdy formulář nemá.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function vezen(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vezen');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $summary, 'vezen'));
        }
        $insurance = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:pojisteni');
        $insurance->appendChild($this->insuranceDuration($dom, $employment));
        $insurance->appendChild($this->eldpList($dom, $employment, true));
        $insurance->appendChild($this->employeeDiscounts($dom, $employment));
        $node->appendChild($insurance);
        $work = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prubehZamestnani');
        $work->appendChild($this->workedHours(
            $dom,
            $employment,
            self::reportedWorkedHours(
                $this->reportedUnworkedHours($this->workSummaryValues($employment)),
            ),
        ));
        $node->appendChild($work);
        $node->appendChild($this->income($dom, $summary, $employment));
        $earnings = $this->object($employment['earnings_by_attribute_czk'] ?? null);
        $wage = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mzda');
        $sickness = $this->earning($earnings, '10342');
        if ($sickness !== null) {
            $compensation = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:nahrady');
            $this->text(
                $dom,
                $compensation,
                JmhzSchemaCatalog::NS_FORM,
                'form:docasnaNeschopnost',
                (string) $this->int($sickness, '10342'),
            );
            $wage->appendChild($compensation);
        }
        $node->appendChild($wage);

        return $node;
    }

    /**
     * Jiný příjem (scénář 5, `formJinyPrijem.xsd`): druh činnosti 11, 13
     * nebo 14. Formulář nese identifikaci, souhrn za osobu (na primárním
     * vztahu) a daňový základ. Pojištění nemá: takový příjem u plátce účast
     * nezakládá ({@see self::assertWithoutInsurance()}).
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function jinyPrijem(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $this->assertWithoutInsurance($employment);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:jinyPrijem');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $summary, 'jinyPrijem'));
        }
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
    }

    /**
     * Mezinárodní pronájem pracovní síly (scénář 6,
     * `formMezinarodniPronajemSily.xsd`): druh činnosti 12. Souhrn je zúžený
     * (`souhrnDataZecMpsType`): úhrn příjmu bez osvobozené části, záloha bez
     * bonusu, ze slev jen základní sleva na poplatníka a z výsledku ročního
     * zúčtování jen celkový přeplatek (kontroly 78 a 79 pro scénář 12).
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function mezinarodniPronajemSily(
        DOMDocument $dom,
        array $summary,
        array $employment,
    ): DOMElement {
        $this->assertWithoutInsurance($employment);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:mezinarodniPronajemSily');
        $node->appendChild($this->identification($dom, $employment));
        if ($this->bool($employment['primary'] ?? null, '10495')) {
            $node->appendChild($this->employeeSummary($dom, $summary, 'mezinarodniPronajemSily'));
        }
        $node->appendChild($this->income($dom, $summary, $employment));

        return $node;
    }

    /**
     * Formuláře scénářů 5 a 6 blok `pojisteni` nemají. Vztah, za který se
     * vyměřovací základ nebo pojistné odvádí, by v nich pojistné zamlčel
     * a pojistná část by se s formuláři rozešla.
     *
     * @param array<string,mixed> $employment
     */
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

    /**
     * Souhrnná data zaměstnance podle typu formuláře součásti.
     *
     * Typy se liší jen ubíráním prvků: `bezPriznaku` (a odložený příjem) má
     * celý souhrn včetně příspěvků zaměstnavatele, čisté mzdy a zdravotního
     * pojištění; `cinnostKS` nemá čistou mzdu ani zdravotní pojištění
     * zaměstnavatele; `vezen` má společný souhrn a čistou mzdu; `jinyPrijem`
     * jen společný souhrn (`souhrnDataZecType`); `mezinarodniPronajemSily`
     * zúžený souhrn (`souhrnDataZecMpsType`).
     *
     * @param array<string,mixed> $summary
     */
    private function employeeSummary(
        DOMDocument $dom,
        array $summary,
        string $form = 'bezPriznaku',
    ): DOMElement {
        $cinnostKs = $form === 'cinnostKS';
        $international = $form === 'mezinarodniPronajemSily';
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
         *
         * Kontrola 283: při nulovém úhrnu 10286 nesmí být 10289 vyplněné ani
         * nulou; měsíc bez příjmu ho proto vynechá stejně jako přijatá hlášení.
         */
        // Zúžený souhrn pronájmu síly osvobozený příjem nevede (matice
        // scénáře 12 atribut 10289 nemá), takže se tam neuvádí vůbec.
        $exemptIncome = $international ? null : ($summary['exempt_income_czk'] ?? null);
        if ($exemptIncome !== null && $incomeTotal !== 0) {
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
        // Příspěvky zaměstnavatele (10417 a rozpad) jsou rozšíření příjmů jen
        // formulářů bez příznaku a činnosti K–S; matice scénářů 4 až 6 je
        // nevedou a příspěvek zůstává součástí osvobozeného úhrnu 10289.
        // Při nulovém úhrnu je interakce IN34 odebírá stejně jako 10289
        // (kontrola 283 bere za vyplněnou i nulu).
        if (($form === 'bezPriznaku' || $cinnostKs) && $incomeTotal !== 0) {
            $this->appendEmployerContributions($dom, $income, $summary);
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
            if ($element === 'form:danBonus' && $international) {
                // `souhrnDataZecMpsType` měsíční bonus (10306) nevede.
                if ($this->int($advance[$key] ?? null, $attributeId) !== 0) {
                    $this->invalid(
                        'jmhz_xml_international_hire_attribute_unsupported',
                        'Formulář mezinárodního pronájmu pracovní síly nemá kam'
                            . ' zapsat měsíční daňový bonus (10306).',
                    );
                }

                continue;
            }
            // Kontrola 243 zakazuje 10306 nerezidentovi s prohlášením stejně,
            // jako kontrola 244 zaměstnanci bez prohlášení: za vyplněný bere
            // i nulu (zamítnutí 40244 u 244). Bonus nerezident mít nemůže,
            // výpočet mu zvýhodnění na děti odmítne.
            $nonResident = ($summary['tax_residence'] ?? null) === TaxResidence::NonResident->value;
            if ($element === 'form:danBonus' && ($nonResident || !$declarationSigned)) {
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
        // Zúžený souhrn pronájmu síly zná ze slev jen základní slevu na
        // poplatníka (10299); jiná sleva nebo zvýhodnění na děti by se ztratily.
        if ($international
            && ($childCredit !== null || array_diff(array_keys($claimed), ['form:zakladniSleva']) !== [])
        ) {
            $this->invalid(
                'jmhz_xml_international_hire_attribute_unsupported',
                'Formulář mezinárodního pronájmu pracovní síly nese ze slev'
                    . ' jen základní slevu na poplatníka (10299).',
            );
        }
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
                /*
                 * 10321 je součet 10322 + 10323 (kontrola 78) a 10323 nese
                 * přeplacený bonus záporně, takže výsledek smí být záporný
                 * (Pokyny MH 1.4.14 kap. 2.4.8, `cisloN14Type`). Nedoplatek
                 * na dani se v 10322 vykazuje nulou, proto zůstává nezáporný.
                 */
                $this->text(
                    $dom,
                    $resultNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:preplatekRok',
                    (string) $this->signedInt(
                        $result['settlement_difference_czk'] ?? null,
                        '10321',
                    ),
                );
                // Pronájem síly vede z výsledku ročního zúčtování jen 10321
                // (kontrola 79 pro scénář 12, kontrolu 78 neuplatňuje).
                if ($international) {
                    $annualNode->appendChild($resultNode);
                    $node->appendChild($annualNode);

                    return $node;
                }
                $this->text(
                    $dom,
                    $resultNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:danPreplatekRok',
                    (string) $this->int($result['tax_difference_czk'] ?? null, '10322'),
                );
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

        if (in_array($form, ['bezPriznaku', 'vezen'], true)) {
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

        $healthAmounts = match ($form) {
            'bezPriznaku' => [
                'form:zdravPojZamestnavatel' => ['employer_health_czk', '10482'],
                'form:zdravPojZamestnanec' => ['employee_health_czk', '10371'],
            ],
            'cinnostKS' => ['form:zdravPojZamestnanec' => ['employee_health_czk', '10371']],
            default => [],
        };
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

    /**
     * Příspěvek zaměstnavatele na produkty spoření na stáří a na pojištění
     * dlouhodobé péče (10417 a rozpad 10418, 10292–10296).
     *
     * Stojí uvnitř `prijmy` za osvobozeným úhrnem a je jeho ČÁSTÍ: příspěvek
     * na penzijní produkt je osvobozený příjem podle § 6 odst. 9 písm. p) ZDP,
     * takže se objeví jednou v 10289 a podruhé tady, rozepsaný podle druhu
     * produktu. Není to dvojí vykázání částky, ale dva pohledy na tutéž.
     *
     * Úhrn 10417 je podle vlastního názvu atributu součtem produktů spoření na
     * stáří I pojištění dlouhodobé péče, takže rozpad se do něj rolluje přes
     * topologii cílových atributů; serializér ho jen opíše z vektoru výdělků.
     *
     * Blok vzniká jen tehdy, když vektor nese aspoň jeden z atributů. Prázdný
     * blok se sedmi nulami by tvrdil, že zaměstnavatel na penzijní produkty
     * nepřispívá — což u zaměstnavatele, který složku vůbec nemá zavedenou,
     * neplyne z ničeho.
     *
     * @param array<string,mixed> $summary
     */
    private function appendEmployerContributions(
        DOMDocument $dom,
        DOMElement $income,
        array $summary,
    ): void {
        $contributions = $this->object($summary['employer_contributions_czk'] ?? null);
        if ($contributions === []) {
            return;
        }
        $values = [];
        /*
         * Znaménko se řídí XSD, ne jednotným pravidlem: `cisloN14Type` je
         * `xs:int`, tedy se znaménkem (vratka příspěvku je legitimní vstup),
         * kdežto `cislo14Type` je nezáporný. Vykázat zápornou hodnotu tam, kde
         * schéma povoluje jen nezápornou, by podání shodilo až na validaci
         * u ČSSZ, takže se rozlišuje tady.
         */
        foreach ([
            'form:prispevekZelSporeniOsvob' => ['10417', true],
            'form:prispevekZelPojDlPece' => ['10418', false],
            'form:prispevekPenzPripoj' => ['10292', true],
            'form:prispevekDoplnPenzPripoj' => ['10293', true],
            'form:prispevekPenzPoj' => ['10294', true],
            'form:prispevekZivotPoj' => ['10295', false],
            'form:prispevekDip' => ['10296', false],
        ] as $element => [$attributeId, $signed]) {
            if (!array_key_exists($attributeId, $contributions)) {
                continue;
            }
            $values[$element] = $signed
                ? $this->signedInt($contributions[$attributeId], $attributeId)
                : $this->int($contributions[$attributeId], $attributeId);
        }
        if ($values === []) {
            return;
        }
        $node = $this->node(
            $dom,
            JmhzSchemaCatalog::NS_FORM,
            'form:prispevekZamestnavatele',
        );
        foreach ($values as $element => $value) {
            $this->text($dom, $node, JmhzSchemaCatalog::NS_FORM, $element, (string) $value);
        }
        $income->appendChild($node);
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
     * @param array<string,mixed>|null $deferred odložený příjem (scénář 8)
     */
    private function insurance(
        DOMDocument $dom,
        array $summary,
        array $employment,
        bool $cinnostKs = false,
        ?array $deferred = null,
    ): DOMElement {
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:pojisteni');
        // Odložený příjem trvání pojištění nevykazuje: vztah v měsíci netrvá.
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

        $list = $this->eldpList($dom, $employment);
        if ($deferred === null) {
            $node->appendChild($list);
        } else {
            /*
             * Odložený příjem vykazuje ELDP po obdobích: měsíc (10537) a rok
             * (10538), za který je hlášeno, a jeho ELDP. Typ 1 má jediné
             * období, měsíc zúčtování (pravidla podání JMHZ, kap. 6).
             */
            $periods = $this->rows($deferred['periods'] ?? null);
            if (count($periods) !== 1) {
                $this->invalid(
                    'jmhz_xml_deferred_income_periods_unsupported',
                    'Odložený příjem typu 1 nese právě jedno období ELDP.',
                );
            }
            $block = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:eldpObdobi');
            $period = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:obdobi');
            $month = $this->int($periods[0]['month'] ?? null, '10537');
            if ($month < 1 || $month > 12) {
                $this->invalid(
                    'jmhz_xml_deferred_income_period_invalid',
                    'Měsíc odloženého příjmu musí být 1 až 12.',
                );
            }
            $this->text($dom, $period, JmhzSchemaCatalog::NS_FORM, 'form:mesic', (string) $month);
            $this->text(
                $dom,
                $period,
                JmhzSchemaCatalog::NS_FORM,
                'form:rok',
                (string) $this->int($periods[0]['year'] ?? null, '10538'),
            );
            $period->appendChild($list);
            $block->appendChild($period);
            $node->appendChild($block);
        }

        // Výsledek s pojistným po vztazích: každý účastný vztah nese své
        // pojistné na svém formuláři (`social_contributions`), kontrola 12 pak
        // sčítá 10370 přes formuláře. Starší dokument nese pojistné za OSOBU
        // v souhrnu a vykazuje ho nejvýš jeden její formulář
        // (JmhzScenario1DocumentResolver::socialContributionEmployment()).
        // Dokument bez příznaku vznikl v době, kdy měla osoba vždy jen jeden
        // formulář.
        $reportsSocial = ($employment['reports_social_contributions'] ?? true) === true;
        $ownContributions = $this->object($employment['social_contributions'] ?? null);
        foreach ([
            'form:pojisteniZamestnanec' => ['employee_social_czk', '10370'],
            'form:pojisteniZamestnavatel' => ['employer_social_czk', '10481'],
        ] as $element => [$key, $attributeId]) {
            $value = $ownContributions !== []
                ? ($ownContributions[$key] ?? null)
                : ($summary[$key] ?? null);
            if (!$reportsSocial || !is_int($value) || $amount === null) {
                continue;
            }
            $wrapper = $this->node($dom, JmhzSchemaCatalog::NS_FORM, $element);
            $this->text(
                $dom,
                $wrapper,
                JmhzSchemaCatalog::NS_FORM,
                'form:socialniPojisteni',
                (string) $this->int($value, $attributeId),
            );
            $node->appendChild($wrapper);
        }

        $node->appendChild($this->employeeDiscounts($dom, $employment));

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

    /**
     * Trvání pojištění v měsíci (10354, 10355), společné všem formulářům
     * s blokem `pojisteni` kromě odloženého příjmu.
     *
     * @param array<string,mixed> $employment
     */
    private function insuranceDuration(DOMDocument $dom, array $employment): DOMElement
    {
        $eldp = $this->object($employment['eldp'] ?? null);
        $interval = $this->object($eldp['insurance_interval'] ?? null);
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

        return $duration;
    }

    /**
     * Seznam ELDP sekcí vztahu (`eldpSeznam`). Vězeň má zúžený typ sekce
     * (`eldpVezenType`), proto se vyloučené doby píší jiným rozpadem.
     *
     * @param array<string,mixed> $employment
     */
    private function eldpList(DOMDocument $dom, array $employment, bool $prisoner = false): DOMElement
    {
        $eldp = $this->object($employment['eldp'] ?? null);
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
            if ($prisoner) {
                $this->appendPrisonerEldpExcludedDays($dom, $entry, $section, $code);
            } else {
                $this->appendEldpExcludedDays($dom, $entry, $section, $code);
            }
            $list->appendChild($entry);
        }

        return $list;
    }

    /**
     * Vyloučené doby ELDP vězně (`vylouceneDnyVezenType`): úhrn 10357, DPN
     * 10358, PPM 10359 a úhrn § 18 odst. 7 (10366) - bez ošetřování,
     * otcovské, § 16 odst. 4 písm. j) a bez rozpadu § 18. Matice „1 až 9
     * výkon trestu" je nevede, takže je formulář nemá kam zapsat; nenulová
     * hodnota by se tiše ztratila, proto je to vada.
     *
     * @param array<string,mixed> $section
     */
    private function appendPrisonerEldpExcludedDays(
        DOMDocument $dom,
        DOMElement $entry,
        array $section,
        mixed $code,
    ): void {
        $total = $section['excluded_days_total'] ?? null;
        $components = $this->object($section['excluded_days'] ?? null);
        $section18 = $section['section18_days_total'] ?? null;
        foreach (['osetrovaniClenaRodiny' => '10360', 'otcovska' => '10362', 'vyloucenePar16' => '10536'] as $key => $attributeId) {
            if (($components[$key] ?? 0) !== 0) {
                $this->invalid(
                    'jmhz_xml_prisoner_excluded_days_unsupported',
                    "Formulář vězně nemá kam zapsat vyloučené doby atributu {$attributeId}.",
                );
            }
        }
        $hasCode = is_string($code) && $code !== '';
        $total = $total === null ? null : $this->int($total, '10357');
        $section18 = $section18 === null ? null : $this->int($section18, '10366');
        if (!$hasCode) {
            if (($total ?? 0) !== 0) {
                $this->invalid(
                    'jmhz_xml_eldp_excluded_days_without_code',
                    'Vyloučené doby nelze vykázat v ELDP sekci bez kódu ELDP.',
                );
            }
            if (($section18 ?? 0) === 0) {
                return;
            }
        }
        if ($total === null && ($section18 ?? 0) === 0) {
            return;
        }
        $block = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:vylouceneDny');
        if ($hasCode && $total !== null) {
            $sickness = $this->int($components['docasNeschopnost'] ?? 0, '10358');
            $maternity = $this->int($components['penezitaPomocMaterstvi'] ?? 0, '10359');
            if ($sickness + $maternity !== $total) {
                $this->invalid(
                    'jmhz_xml_eldp_excluded_days_sum_mismatch',
                    'Úhrn vyloučených dob neodpovídá rozpadu podle § 16 odst. 4'
                        . ' zákona č. 155/1995 Sb.',
                );
            }
            $this->text($dom, $block, JmhzSchemaCatalog::NS_FORM, 'form:vylouceneDobyCelkem', (string) $total);
            if ($total > 0) {
                $this->text($dom, $block, JmhzSchemaCatalog::NS_FORM, 'form:docasNeschopnost', (string) $sickness);
                $this->text($dom, $block, JmhzSchemaCatalog::NS_FORM, 'form:penezitaPomocMaterstvi', (string) $maternity);
            }
        }
        if ($section18 !== null) {
            $this->text($dom, $block, JmhzSchemaCatalog::NS_FORM, 'form:vyloucenePar18', (string) $section18);
        }
        $entry->appendChild($block);
    }

    /**
     * Sleva na pojistném ZAMĚSTNANCE — 10490 (pracující důchodci, § 7d
     * ZPSZ) a 10546 (ovocnářství a pěstování zeleniny). Matice povinností
     * 1.4.0.2 vede oba příznaky jako povinné jádro scénáře, ne jako údaj
     * podmíněný interakcí, a `slevaZamestnanceType` je má oba v jednom
     * bloku před slevou zaměstnavatele.
     *
     * 10490 = ANO a výši slevy 10491 nese jen vztah, kterému resolver
     * slevu přiřadil
     * ({@see JmhzScenario1DocumentResolver::employeeSocialDiscount()}):
     * tentýž, který nese pojistné osoby. Ostatní vztahy vykazují NE.
     * Pořadí prvků určuje `slevaZamestnanceType`: příznak, výše slevy,
     * teprve potom příznak ovocnářů.
     *
     * Vykázat příznak je nutné i jako NE: kontrola 297 poměřuje počet
     * zaměstnanců v `pvpoj:slevyZamestnancu` s počtem vztahů, u nichž je
     * 10490 = ANO, a kontrola 213 stejně tak úhrn vyměřovacích základů.
     * Mlčení na formulářích při vyplněné pojistné části je proto rozpor
     * uvnitř jednoho podání.
     *
     * 10546 zůstává NE: sezónní slevu běžný profil nepodporuje a příprava
     * ji potvrdit nedovolí. ANO u obou na jednom formuláři zakazuje
     * kontrola 275 a resolver takovou kombinaci zablokuje dřív.
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
                'Slevu na pojistném zaměstnance smí nést jen formulář,'
                    . ' který vykazuje pojistné osoby.',
            );
        }
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
            $employeeDiscount === [] ? 'false' : 'true',
        );
        if ($employeeDiscount !== []) {
            $discountCzk = $this->int($employeeDiscount['amount_czk'] ?? null, '10491');
            // 10490 = ANO jen při nenulové slevě; nula s příznakem by tvrdila
            // uplatněnou slevu, která se neuplatnila.
            if ($discountCzk === 0) {
                $this->invalid(
                    'jmhz_xml_employee_social_discount_zero',
                    'Sleva na pojistném zaměstnance s příznakem ANO nesmí být nulová.',
                );
            }
            $amountNode = $this->node(
                $dom,
                JmhzSchemaCatalog::NS_FORM,
                'form:slevaZamestnance',
            );
            $this->text(
                $dom,
                $amountNode,
                JmhzSchemaCatalog::NS_FORM,
                'form:vyseSlevy',
                (string) $discountCzk,
            );
            $employeeDiscounts->appendChild($amountNode);
        }
        $this->text(
            $dom,
            $employeeDiscounts,
            JmhzSchemaCatalog::NS_FORM,
            'form:slevaZamestnanceOvoZelEvidovana',
            'false',
        );

        return $employeeDiscounts;
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
        $this->text(
            $dom,
            $node,
            JmhzSchemaCatalog::NS_FORM,
            'form:docasnePrideleniEvidovano',
            $assignment ? 'true' : 'false',
        );
        if ($assignment) {
            // Kontrola 103: s 10251 = ANO právě jedna identifikace uživatele,
            // IČO (10252), nebo zahraniční osoba (10492 + 10493 + 10494).
            $node->appendChild($this->temporaryAssignment($dom, $term));
        }

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

    /**
     * Uživatel dočasného přidělení (§ 43a ZP) podle podmínek vztahu.
     *
     * @param array<string,mixed> $term
     */
    private function temporaryAssignment(DOMDocument $dom, array $term): DOMElement
    {
        $wrapper = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:docasnePrideleni');
        $user = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:uzivatel');
        $kind = $term['jmhz_assignment_user_kind'] ?? null;
        if ($kind === 'ico') {
            $ico = $this->string($term['jmhz_assignment_user_ico'] ?? null, '10252');
            if (preg_match('/^[0-9]{8}$/', $ico) !== 1) {
                $this->invalid(
                    'jmhz_xml_temporary_assignment_user_invalid',
                    'IČO uživatele dočasného přidělení musí mít osm číslic.',
                );
            }
            $this->text($dom, $user, JmhzSchemaCatalog::NS_FORM, 'form:ico', $ico);
        } elseif ($kind === 'foreign') {
            $foreign = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:zahranicniOsoba');
            $this->text(
                $dom,
                $foreign,
                JmhzSchemaCatalog::NS_FORM,
                'form:kodStatu',
                $this->string($term['jmhz_assignment_user_country_code'] ?? null, '10492'),
            );
            $this->text(
                $dom,
                $foreign,
                JmhzSchemaCatalog::NS_FORM,
                'form:identifikace',
                $this->string($term['jmhz_assignment_user_foreign_id'] ?? null, '10493'),
            );
            $this->text(
                $dom,
                $foreign,
                JmhzSchemaCatalog::NS_FORM,
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

    /** @param array<string,mixed> $employment */
    private function workMonth(DOMDocument $dom, array $employment): DOMElement
    {
        $values = self::reportedWorkedHours(
            $this->reportedUnworkedHours($this->workSummaryValues($employment)),
        );
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prubehZamestnani');
        $days = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:odpracovaneDny');
        $this->text(
            $dom,
            $days,
            JmhzSchemaCatalog::NS_FORM,
            'form:dnyEvidencniStav',
            (string) $this->int($values['evidence_days'] ?? null, '10265'),
        );
        /*
         * Počet odpracovaných dnů (10267) a přesčasové hodiny (10269) nese až
         * pracovní souhrn `jmhz-work-month.v4`. Starší zmrazený souhrn je nemá
         * vůbec a `null` tady znamená NEUVEDENO, ne nulu: nula by tvrdila, že
         * zaměstnanec neodpracoval ani den, což ze staršího řezu neplyne.
         * Oba atributy jsou v matici povinností nepovinné, takže je vynechání
         * legální.
         */
        if (($values['worked_days'] ?? null) !== null) {
            $this->text(
                $dom,
                $days,
                JmhzSchemaCatalog::NS_FORM,
                'form:dnyOdpracovanePocet',
                (string) $this->int($values['worked_days'], '10267'),
            );
        }
        $node->appendChild($days);
        $node->appendChild($this->workedHours($dom, $employment, $values));
        $this->appendUnworkedHoursAndObstacles($dom, $node, $values);

        return $node;
    }

    /**
     * Odpracované hodiny (10268) s rozpadem přesčasu a rizikové práce
     * (`odpracovaneHodinyType`), společné formuláři bez příznaku i vězni.
     *
     * @param array<string,mixed> $employment
     * @param array<string,mixed> $values
     */
    private function workedHours(DOMDocument $dom, array $employment, array $values): DOMElement
    {
        $hours = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:odpracovaneHodiny');
        $this->text(
            $dom,
            $hours,
            JmhzSchemaCatalog::NS_FORM,
            'form:pocet',
            $this->decimal($values['worked_millihours'] ?? null, 3, '10268'),
        );
        /*
         * Kontrola 282: při nulových odpracovaných hodinách (10268) nesmí být
         * rozpad (10269–10274) vyplněný ani nulou. Měsíc celého neplaceného
         * volna nebo PPM proto rozpad vynechá; přijatá hlášení to tak mají.
         */
        $risk = $this->object($employment['risk_work'] ?? null);
        $riskCodes = is_array($risk['categorization_codes'] ?? null)
            ? array_values($risk['categorization_codes'])
            : [];
        $hasOvertime = ($values['overtime_millihours'] ?? null) !== null;
        if (($hasOvertime || $riskCodes !== [])
            && ($values['worked_millihours'] ?? null) !== 0
        ) {
            $worked = $this->int($values['worked_millihours'] ?? null, '10268');
            $breakdown = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:rozpad');
            if ($hasOvertime) {
                // Kontrola ČSSZ hlídá, že přesčas není vyšší než odpracované
                // hodiny — je to jejich PODMNOŽINA, ne přičtený čas navíc.
                $overtime = $this->int($values['overtime_millihours'], '10269');
                if ($overtime > $worked) {
                    $this->invalid(
                        'jmhz_xml_overtime_exceeds_worked_hours',
                        'Přesčasové hodiny nesmějí být vyšší než počet'
                            . ' odpracovaných hodin.',
                    );
                }
                $this->text(
                    $dom,
                    $breakdown,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:prescas',
                    $this->decimal($overtime, 3, '10269'),
                );
            }
            if ($riskCodes !== []) {
                /*
                 * IN29: hodiny v rizikovém zaměstnání, práci záchranáře nebo
                 * člena HZS podniku (10273) a kategorizace rizika (10274).
                 * Zařazení platí pro celý vztah, takže hodiny jsou odpracované
                 * hodiny vztahu (kontrola 57: nepřekročí 10268). Při nule
                 * odpracovaných hodin se blok nevykazuje (kontrola 282).
                 *
                 * Pokyny k vyplnění MH 1.4.14 (revize) u 10273: „celé nezáporné
                 * číslo (případný zbytek minut nižší než 60 se považuje za
                 * 1 hodinu)“, stejně jako evidence doby rizikových prací podle
                 * zákona č. 582/1991 Sb. Hodiny se proto zaokrouhlují NAHORU na celé.
                 * Kontrolu 57 to neporuší: katalog kontrol 1.4.2.10 pro ni
                 * desetinné 10268 také zaokrouhluje nahoru.
                 */
                $riskNode = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:riziko');
                $this->text(
                    $dom,
                    $riskNode,
                    JmhzSchemaCatalog::NS_FORM,
                    'form:hodinyOdpracovanePocet',
                    (string) JmhzWholeHours::fromMillihours($worked),
                );
                foreach ($riskCodes as $code) {
                    if (!in_array($code, ['1', '6', '7'], true)) {
                        $this->invalid(
                            'jmhz_xml_risk_categorization_invalid',
                            'Kategorizace rizika musí být z číselníku ČSSZ (1, 6 nebo 7).',
                        );
                    }
                    $this->text(
                        $dom,
                        $riskNode,
                        JmhzSchemaCatalog::NS_FORM,
                        'form:kategorizaceRizika',
                        $code,
                    );
                }
                $breakdown->appendChild($riskNode);
            }
            $hours->appendChild($breakdown);
        }

        return $hours;
    }

    /**
     * Neodpracované hodiny (10275–10280) a překážky v práci (10471, 10472)
     * formuláře bez příznaku.
     *
     * @param array<string,mixed> $values
     */
    private function appendUnworkedHoursAndObstacles(DOMDocument $dom, DOMElement $node, array $values): void
    {
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
    }

    /**
     * Hodiny nemoci s náhradou mzdy (§ 192 ZP) v atributech 10276 a 10471.
     *
     * Pracovní souhrn vede v `unworked_paid_millihours` všechny neodpracované
     * hodiny, za které náleží náhrada mzdy, včetně nemoci v okně § 192 ZP.
     * Tak je potřebuje sleva zaměstnavatele podle § 7a ZPSZ („hodina, za
     * kterou náleží náhrada mzdy") a tak je souhrn potvrzuje účetní. ELDP
     * builder to vynucuje: úhrn se musí rovnat součtu dovolené, nemoci
     * s náhradou a obou překážek.
     *
     * Hlášení je ale chce jinak. Pokyny MPSV k vyplnění MH 1.4.13 u 10276:
     * „Neuvádí se hodiny neodpracované z důvodu dočasné pracovní neschopnosti
     * a ošetřování člena rodiny." A 10471 jsou překážky na straně zaměstnance
     * podle zákoníku práce části osmé, hlavy I a II, tedy včetně dočasné
     * pracovní neschopnosti (§ 191 a 192). Oficiální vzorový příklad (nemoc
     * v okně náhrady): 10275 = 80, 10276 = 0, 10278 = 80, 10471 = 80.
     *
     * Proto se tady, a jen tady, hodiny nemoci s náhradou z 10276 odečtou
     * a k 10471 přičtou. Nulové 10276 se vynechá stejně jako u měsíce bez
     * placených hodin (XSD ho má nepovinné).
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
                'Placené neodpracované hodiny pracovního souhrnu nezahrnují hodiny'
                    . ' nemoci s náhradou mzdy.',
            );
        }
        $obstacle = $values['employee_obstacle_paid_millihours'] ?? null;
        $values['unworked_paid_millihours'] = $paid === 0 ? null : $paid;
        $values['employee_obstacle_paid_millihours']
            = ($obstacle === null ? 0 : $this->int($obstacle, '10471')) + $sickness;

        return $values;
    }

    /**
     * Odpracované hodiny 10268 a přesčas 10269 v měsíci čerpání náhradního
     * volna za přesčas (§ 114 odst. 1 ZP).
     *
     * Pokyny MPSV k vyplnění MH k 10269: „V měsíci, kdy byly přesčasy
     * odpracované, se vykáže celkový počet odpracovaných přesčasových hodin
     * a odečtou se přesčasové hodiny, za které bylo poskytnuto náhradní volno
     * (bez ohledu na to, zda … za hodiny odpracované v aktuálním měsíci, nebo
     * … z předchozích měsíců). Stejný princip platí … do 10268. … záporný …
     * uvedou se nuly." K 10268: „za hodiny odpracované v přesčase se
     * nepovažují hodiny, za které bylo poskytnuto náhradní volno."
     *
     * VÝKLAD POKYNU, dokud MPSV neodpoví na dotaz: hodiny náhradního volna
     * čerpaného v měsíci se odečtou od přesčasu (záporný → 0) i od
     * odpracovaných hodin, přičemž 10268 neklesne pod 10269 (kontrola ČSSZ
     * 10268 ≥ 10269). Hodiny volna zůstávají v úhrnu neodpracovaných 10275
     * (ne v 10276), takže za měsíc přesčasu a měsíc čerpání dohromady
     * nevznikne dvojí započtení. Fond 160 h: březen přesčas 8 h → 10268 = 168,
     * 10269 = 8; duben volno 8 h a v práci 152 h → 10268 = 144, 10269 = 0,
     * 10275 = 8; úhrn 168 + 144 + 8 = 320 = 2 × 160.
     *
     * Jen reportovací převod: pracovní souhrn i výpočet mzdy nesou dál
     * skutečně odpracované hodiny, stejně jako {@see reportedUnworkedHours()}.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function reportedWorkedHours(array $values): array
    {
        $timeOff = $values['compensatory_time_off_millihours'] ?? null;
        $worked = $values['worked_millihours'] ?? null;
        if (!is_int($timeOff) || $timeOff <= 0 || !is_int($worked)) {
            return $values;
        }
        $overtime = $values['overtime_millihours'] ?? null;
        $reportedOvertime = is_int($overtime) ? max(0, $overtime - $timeOff) : null;
        $values['overtime_millihours'] = $reportedOvertime;
        $values['worked_millihours'] = max($reportedOvertime ?? 0, $worked - $timeOff);

        return $values;
    }

    /**
     * 10535 je zdanitelný příjem TOHOTO vztahu (základ zálohy, nebo základ
     * srážkové daně); resolver ho bere z rozpadu výsledku daně po vztazích
     * a součet vztahů v režimu zálohy se rovná základu zálohy v souhrnu.
     * Dokument bez per-vztahové hodnoty vznikl
     * v době, kdy měla osoba vždy jen jeden formulář, a tam je to základ osoby.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     */
    private function income(DOMDocument $dom, array $summary, array $employment): DOMElement
    {
        $taxableIncome = array_key_exists('taxable_income_czk', $employment)
            ? $employment['taxable_income_czk']
            : ($this->object($summary['advance_tax_czk'] ?? null)['taxable_income'] ?? null);
        $node = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:prijem');
        $tax = $this->node($dom, JmhzSchemaCatalog::NS_FORM, 'form:dan');
        $this->text(
            $dom,
            $tax,
            JmhzSchemaCatalog::NS_FORM,
            'form:zakladDane',
            (string) $this->int($taxableIncome, '10535'),
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
        $surcharges = $this->wageSurcharges(
            $earnings,
            $this->reportsOvertime($this->workSummaryValues($employment)),
        );
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
     * Výjimkou je přesčas. Kontrola 36 (blokující) chce při 10269 > 0 vyplněný
     * i příplatek za práci přesčas 10333 a pokyny MPSV u něj říkají, že
     * „pokud nebyly v daném měsíci příplatky proplaceny, je nutné uvést 0".
     * Firma bez složky příplatku za přesčas (přesčas zahrnutý ve mzdě podle
     * § 114 odst. 3 ZP, nebo náhradní volno) proto dostane 10333 = 0, a protože
     * `celkem` je v `priplatkyType` povinný, i úhrn 10332 = 0, pokud ho vektor
     * nenese. Nula tu není dopočet, ale předepsaný zápis „nic se neproplatilo".
     *
     * @param array<array-key,mixed> $earnings
     * @return array<string,int>
     */
    private function wageSurcharges(array $earnings, bool $overtimeReported = false): array
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
        if ($overtimeReported && !array_key_exists('form:prescas', $surcharges)) {
            if ($surcharges === []) {
                $surcharges['form:celkem'] = 0;
            }
            // Pořadí prvků určuje `priplatkyType`: úhrn, přesčas, zbytek.
            $surcharges = array_slice($surcharges, 0, 1, true)
                + ['form:prescas' => 0]
                + array_slice($surcharges, 1, null, true);
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
     * Vykáže formulář kladné přesčasové hodiny (10269)? Táž podmínka jako
     * zápis rozpadu v {@see workMonth()}, aby kontrola 36 a příplatek četly
     * jeden údaj.
     *
     * @param array<string,mixed> $values
     */
    private function reportsOvertime(array $values): bool
    {
        $overtime = $values['overtime_millihours'] ?? null;

        return is_int($overtime)
            && $overtime > 0
            && ($values['worked_millihours'] ?? null) !== 0;
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
     * Vyloučené doby se zapisují jen tam, kde sekce nese kód ELDP: bez kódu je
     * kontrola 307 ČSSZ (atributy 10357 a 10358–10536 bez 10240) odmítne —
     * vyloučená doba bez doby pojištění nedává smysl. Vyloučené dny § 18
     * odst. 7 do výčtu kontroly 307 nepatří a sekce bez kódu je nese také.
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
     * Odečítané doby (10375, 10462–10469) se nezapisují vůbec: měsíc s kódem
     * D a nenulovými odečtenými dobami ELDP řez zastaví
     * (`jmhz_eldp_deducted_days_unsupported`), takže sem dojde jen sekce, která
     * žádné nemá, a builder je nechává neuvedené.
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
            // Bez vyloučených dob a bez vyloučeného dne není co uvést; nulový
            // blok by v sekci bez kódu nic netvrdil.
            if (!$hasSection18 || $section['section18_days_total'] === 0) {
                return;
            }
            // Sekce bez kódu ELDP (poživatel starobního důchodu) nese § 18
            // dál: jsou to údaje nemocenského pojištění, ne třída ELDP, a
            // kontrola 307 je v sekci bez kódu nezakazuje (MPSV v diskuzi
            // k JMHZ: u důchodce „vyloučené dny § 18 pro nemocenské se mají
            // uvádět").
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
