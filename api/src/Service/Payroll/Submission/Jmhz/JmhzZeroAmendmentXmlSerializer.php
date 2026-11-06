<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\JmhzZeroAmendmentXml2027Serializer;

/**
 * Nulové opravné hlášení, které anuluje měsíc, jenž už neměl být hlášen,
 * protože pracovněprávní vztah v něm netrval (Pravidla podání JMHZ 1.4.5,
 * kap. 1, „Podrobný popis řešení situací po 20. dni", ř. 540-659).
 *
 * Formulář se staví ze ZMRAZENÉHO původního formuláře, ne z mzdové přípravy:
 * pro měsíc, ve kterém vztah netrval, žádná revize mzdového běhu s tímto
 * vztahem není. Z původního formuláře se přebírá jen to, co tabulka
 * {@see JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH} vede
 * jako „neopravuje se" (identifikace, místo výkonu práce, stanovený fond,
 * prohlášení poplatníka) a první den pojištění; ostatní povinné atributy
 * nesou hodnotu z tabulky (0, NE, neuvádí se). Nepovinné bloky, které
 * tabulka nezná (slevy na dani, rozpad mzdy, vyloučené doby ELDP …), se
 * neuvádějí: vztah v měsíci neexistoval.
 *
 * Fail-closed, protože anulace je nevratná:
 *
 * - jen formulář scénáře `bezPriznaku` (ostatní scénáře mají jiné povinné
 *   bloky a tabulka pro ně v Pravidlech není),
 * - jen formulář, jehož peněžní údaje jsou nulové. Formulář s příjmem nebo
 *   pojistným by po anulaci rozešel souhrnnou a pojistnou část podání,
 *   které by se musely opravit spolu s ním (otevřená otázka 1 zadání A1),
 * - jen osoba bez souběžného vztahu v témže podání: souhrnná data osoby
 *   nese primární vztah a „v případě souběžného PPV opravdu dosažená
 *   hodnota" by se musela dopočítat,
 * - bez ročních úhrnů (leden až březen): ty patří k předchozímu roku
 *   a tabulka je neopravuje.
 */
final class JmhzZeroAmendmentXmlSerializer
{
    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    /**
     * Peněžní listy původního formuláře, které musí být nulové. Cesty jsou
     * relativní k tělu `bezPriznaku`.
     */
    private const MONETARY_LEAVES = [
        'f:souhrnDataZec/f:prijmy//*',
        'f:souhrnDataZec/f:zalohaNaDan//*',
        'f:souhrnDataZec/f:zvlastniSazbaDane//*',
        'f:souhrnDataZec/f:mzdaCista/f:mzdaCista',
        'f:souhrnDataZec/f:zdravPojZamestnavatel/f:zdravotniPojisteni',
        'f:souhrnDataZec/f:zdravPojZamestnanec/f:zdravotniPojisteni',
        'f:pojisteni/f:vymerovaciZaklad//*',
        'f:pojisteni/f:vymerovaciZakladParagraf5//*',
        'f:pojisteni/f:eldpSeznam/f:eldp/f:vymerovaciZaklad',
        'f:pojisteni/f:pojisteniZamestnanec/f:socialniPojisteni',
        'f:pojisteni/f:pojisteniZamestnavatel/f:socialniPojisteni',
        'f:prijem/f:dan/f:zakladDane',
        'f:mzda//*',
    ];

    /**
     * Opravné podání (typ O) s anulačními formuláři vybraných součástí.
     * Souhrnná ani pojistná část se neopravuje: anulovat jde jen nulový
     * formulář, takže se jejich hodnoty nemění.
     *
     * @param list<string> $formGuids GUIDy součástí původního podání
     */
    public function __construct(
        private readonly JmhzZeroAmendmentXml2027Serializer $serializer2027 = new JmhzZeroAmendmentXml2027Serializer(),
    ) {}

    public function serialize(
        string $originalXml,
        array $formGuids,
        JmhzSubmissionEnvelope $envelope,
    ): string {
        if (self::isVersion2027($originalXml)) {
            return $this->serializer2027->serialize($originalXml, $formGuids, $envelope);
        }
        [, $xpath] = self::load($originalXml);
        $identity = JmhzFrozenSubmissionIdentity::read($originalXml);
        if ($identity->submissionType !== JmhzSubmissionFlagMatrix::TYPE_REGULAR) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_target_invalid',
                'Anulovat nulovým opravným hlášením lze jen formulář řádného podání.',
            );
        }
        if (!hash_equals($identity->submissionGuid, $envelope->submissionGuid)) {
            throw new JmhzXmlException(
                'jmhz_cancellation_guid_mismatch',
                'Opravné podání se musí vázat na GUID opravovaného řádného podání.',
            );
        }
        $originals = $this->originalForms($xpath, $formGuids);
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            false,
            false,
            array_fill(0, count($originals), JmhzSubmissionFlagMatrix::TYPE_AMENDMENT),
        );
        $periodStart = sprintf('%04d-%02d-01', $identity->year, $identity->month);

        $out = new DOMDocument('1.0', 'UTF-8');
        $out->formatOutput = true;
        $root = $out->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'jmhz');
        $out->appendChild($root);
        $root->setAttribute('verze', (new JmhzSchemaCatalog())->entryPoint()['data_version']);
        foreach ([
            'xmlns:so' => JmhzSchemaCatalog::NS_SOUHRN,
            'xmlns:pvpoj' => JmhzSchemaCatalog::NS_PVPOJ,
            'xmlns:form' => JmhzSchemaCatalog::NS_FORM,
        ] as $name => $namespace) {
            $root->setAttributeNS(self::XMLNS, $name, $namespace);
        }
        $vendor = $out->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'VENDOR');
        $vendor->setAttribute('productName', $envelope->productName);
        $vendor->setAttribute('productVersion', $envelope->productVersion);
        $root->appendChild($vendor);

        $count = (string) count($originals);
        $header = $out->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        $root->appendChild($header);
        foreach ([
            'idPodani' => $identity->submissionGuid,
            'typPodani' => JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            'variabilniSymbol' => $identity->variableSymbol,
            'mesic' => (string) $identity->month,
            'rok' => (string) $identity->year,
            'datumVyplneni' => $envelope->filledAt,
            'balikPoradi' => '1',
            'balikyPocet' => '1',
            'formularePocetVBaliku' => $count,
            'formularePocetCelkem' => $count,
        ] as $name => $value) {
            $header->appendChild($out->createElementNS(JmhzSchemaCatalog::NS_PODANI, $name, $value));
        }
        $forms = $out->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        $root->appendChild($forms);
        foreach ($originals as $original) {
            $forms->appendChild($this->amendmentForm(
                $out,
                $xpath,
                $original,
                JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
                $periodStart,
            ));
        }

        return self::save($out);
    }

    /**
     * Efektivní set firmy po anulaci: původní řádné podání, ve kterém jsou
     * vybrané součásti nahrazené anulačním obsahem. Nad ním se pouští
     * kontroly katalogu stejně jako u obsahové opravy — souhrn a PVPOJ se
     * tak ověří proti tomu, co po opravě u ČSSZ platí.
     *
     * @param list<string> $formGuids
     */
    public function effectiveSet(string $originalXml, array $formGuids): string
    {
        if (self::isVersion2027($originalXml)) {
            return $this->serializer2027->effectiveSet($originalXml, $formGuids);
        }
        [$dom, $xpath] = self::load($originalXml);
        $identity = JmhzFrozenSubmissionIdentity::read($originalXml);
        $periodStart = sprintf('%04d-%02d-01', $identity->year, $identity->month);
        foreach ($this->originalForms($xpath, $formGuids) as $original) {
            $replacement = $this->amendmentForm(
                $dom,
                $xpath,
                $original,
                JmhzSubmissionFlagMatrix::TYPE_REGULAR,
                $periodStart,
            );
            $original->parentNode?->replaceChild($replacement, $original);
        }

        return self::save($dom);
    }

    /**
     * Posouzení všech součástí původního podání najednou (dokument se čte
     * jednou): GUID formuláře => `null`, pokud ho tvar dovoluje anulovat,
     * jinak výjimka, která by anulaci zastavila.
     *
     * @return array<string,?JmhzXmlException>
     */
    public function assess(string $originalXml): array
    {
        if (self::isVersion2027($originalXml)) {
            return $this->serializer2027->assess($originalXml);
        }
        [, $xpath] = self::load($originalXml);
        $byPerson = [];
        $forms = self::nodes($xpath, '/p:jmhz/p:formulareOsob/p:formularOsoby');
        foreach ($forms as $form) {
            $person = self::text($xpath, './/f:identifikace/f:ikMpsv', $form);
            $byPerson[$person] = ($byPerson[$person] ?? 0) + 1;
        }
        $result = [];
        foreach ($forms as $form) {
            $guid = strtoupper(self::text($xpath, './p:hlavicka/p:idFormulare', $form));
            try {
                $this->assertAnnullable($xpath, $form, $byPerson);
                $result[$guid] = null;
            } catch (JmhzXmlException $exception) {
                $result[$guid] = $exception;
            }
        }

        return $result;
    }

    /**
     * Původní podání ve verzi 2027 (jmenný prostor kořene) anuluje serializér
     * 2027; tvar formuláře zaměstnání se mezi verzemi liší.
     */
    private static function isVersion2027(string $originalXml): bool
    {
        [$dom] = self::load($originalXml);

        return JmhzSpecVersion::fromDocumentNamespace((string) $dom->documentElement?->namespaceURI)
            === JmhzSpecVersion::V2027;
    }

    /**
     * @param list<string> $formGuids
     * @return list<DOMElement>
     */
    private function originalForms(DOMXPath $xpath, array $formGuids): array
    {
        if ($formGuids === []) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_components',
                'Vyberte alespoň jeden pracovněprávní vztah, jehož měsíc se má anulovat.',
            );
        }
        $wanted = [];
        foreach ($formGuids as $guid) {
            $wanted[strtoupper(trim($guid))] = true;
        }
        $byPerson = [];
        $found = [];
        foreach (self::nodes($xpath, '/p:jmhz/p:formulareOsob/p:formularOsoby') as $form) {
            $person = self::text($xpath, './/f:identifikace/f:ikMpsv', $form);
            $byPerson[$person] = ($byPerson[$person] ?? 0) + 1;
            $guid = strtoupper(self::text($xpath, './p:hlavicka/p:idFormulare', $form));
            if (isset($wanted[$guid])) {
                $found[$guid] = $form;
            }
        }
        $missing = array_diff_key($wanted, $found);
        if ($missing !== []) {
            throw new JmhzXmlException(
                'jmhz_cancellation_component_not_frozen',
                'Vybraný pracovní vztah není mezi součástmi původního podání.',
            );
        }
        ksort($found, SORT_STRING);
        foreach ($found as $form) {
            $this->assertAnnullable($xpath, $form, $byPerson);
        }

        return array_values($found);
    }

    /** @param array<string,int> $formsByPerson */
    private function assertAnnullable(DOMXPath $xpath, DOMElement $form, array $formsByPerson): void
    {
        $body = self::body($xpath, $form);
        if ($body === null || $body->localName !== 'bezPriznaku') {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_scenario_unsupported',
                'Anulovat nulovým opravným hlášením lze zatím jen formulář bez zvláštního'
                    . ' příznaku (bezPriznaku). Pro ostatní datové scénáře Pravidla podání'
                    . ' tabulku nulového hlášení neuvádějí.',
            );
        }
        $person = self::text($xpath, './/f:identifikace/f:ikMpsv', $form);
        if (($formsByPerson[$person] ?? 0) > 1) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_concurrent_employment_unsupported',
                'Osoba má v podání souběžný pracovněprávní vztah. Souhrnná data osoby'
                    . ' by se po anulaci musela přepočítat podle druhého vztahu; anulaci'
                    . ' takového formuláře aplikace nesestaví.',
            );
        }
        if (self::nodes($xpath, './f:souhrnDataZec/f:rocniUhrny', $body) !== []) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_annual_summary_unsupported',
                'Formulář nese roční úhrny za předchozí rok. Ty tabulka nulového hlášení'
                    . ' neopravuje a anulace by je odstranila; anulaci takového měsíce'
                    . ' aplikace nesestaví.',
            );
        }
        foreach (self::MONETARY_LEAVES as $query) {
            foreach (self::nodes($xpath, './' . $query, $body) as $leaf) {
                if (self::hasElementChildren($leaf)) {
                    continue;
                }
                $value = trim($leaf->textContent);
                if (!is_numeric($value) || (float) $value !== 0.0) {
                    throw new JmhzXmlException(
                        'jmhz_zero_amendment_original_not_zero',
                        'Anulovaný formulář vykazuje příjem nebo pojistné. Jeho anulace by'
                            . ' změnila i souhrnnou a pojistnou část podání. Vrátil-li zaměstnanec'
                            . ' plnění, opravte mzdový běh a použijte obsahovou opravu z nové'
                            . ' přípravy; nevrátil-li ho, podejte opravné hlášení s formulářem'
                            . ' Odložený příjem ručně (Pravidla podání, tabulka typových situací).',
                    );
                }
            }
        }
    }

    private function amendmentForm(
        DOMDocument $dom,
        DOMXPath $source,
        DOMElement $original,
        string $formType,
        string $periodStart,
    ): DOMElement {
        $body = self::body($source, $original);
        if ($body === null) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_scenario_unsupported',
                'Původní formulář nemá datovou část.',
            );
        }
        $form = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'formularOsoby');
        $header = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        $form->appendChild($header);
        $header->appendChild($dom->createElementNS(
            JmhzSchemaCatalog::NS_PODANI,
            'idFormulare',
            self::text($source, './p:hlavicka/p:idFormulare', $original),
        ));
        $header->appendChild($dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'typFormulare', $formType));
        $primary = self::optionalText($source, './p:hlavicka/p:primarniPpv', $original);
        if ($primary !== null) {
            $header->appendChild($dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'primarniPpv', $primary));
        }

        $out = $this->el($dom, 'bezPriznaku');
        $form->appendChild($out);
        $out->appendChild($dom->importNode(self::required($source, './f:identifikace', $body), true));

        $summary = self::optional($source, './f:souhrnDataZec', $body);
        if ($summary !== null) {
            $node = $this->el($dom, 'souhrnDataZec');
            $node->appendChild($this->wrap($dom, 'prijmy', 'zuctovanoCelkem', '0'));
            $node->appendChild($this->el(
                $dom,
                'prohlaseniPoplatnika',
                self::text($source, './f:prohlaseniPoplatnika', $summary),
            ));
            $net = $this->el($dom, 'mzdaCista');
            $net->appendChild($this->el($dom, 'mzdaCista', '0'));
            $net->appendChild($this->el($dom, 'srazkyZeMzdyEvidovany', 'false'));
            $node->appendChild($net);
            $node->appendChild($this->wrap($dom, 'zdravPojZamestnavatel', 'zdravotniPojisteni', '0'));
            $node->appendChild($this->wrap($dom, 'zdravPojZamestnanec', 'zdravotniPojisteni', '0'));
            $out->appendChild($node);
        }

        $insuredFrom = self::text($source, './f:pojisteni/f:trvani/f:pojisteniOd', $body);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $insuredFrom) !== 1
            || substr($insuredFrom, 0, 7) !== substr($periodStart, 0, 7)
        ) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_interval_invalid',
                'První den pojištění původního formuláře neleží v anulovaném měsíci.',
            );
        }
        $insurance = $this->el($dom, 'pojisteni');
        $interval = $this->el($dom, 'trvani');
        $interval->appendChild($this->el($dom, 'pojisteniOd', $insuredFrom));
        $interval->appendChild($this->el($dom, 'pojisteniDo', $insuredFrom));
        $insurance->appendChild($interval);
        $eldpList = $this->el($dom, 'eldpSeznam');
        $eldpList->appendChild($this->wrap($dom, 'eldp', 'pocetDnu', '0'));
        $insurance->appendChild($eldpList);
        $insurance->appendChild($this->wrap($dom, 'pojisteniZamestnanec', 'socialniPojisteni', '0'));
        $insurance->appendChild($this->wrap($dom, 'pojisteniZamestnavatel', 'socialniPojisteni', '0'));
        $employeeDiscount = $this->el($dom, 'slevaZamestnance');
        $employeeDiscount->appendChild($this->el($dom, 'slevaZamestnanceEvidovana', 'false'));
        $employeeDiscount->appendChild($this->el($dom, 'slevaZamestnanceOvoZelEvidovana', 'false'));
        $insurance->appendChild($employeeDiscount);
        $insurance->appendChild($this->wrap($dom, 'slevaZamestnavatele', 'slevaZamestnavateleEvidovana', 'false'));
        $out->appendChild($insurance);

        $position = $this->el($dom, 'vykonavanaPozice');
        $position->appendChild($dom->importNode(
            self::required($source, './f:vykonavanaPozice/f:mistoVykonuPrace', $body),
            true,
        ));
        $position->appendChild($this->el($dom, 'uplatnujiPrispevekApz', 'false'));
        $position->appendChild($this->el($dom, 'funkcniPozitky', 'false'));
        $position->appendChild($this->el($dom, 'docasnePrideleniEvidovano', 'false'));
        $fund = $this->el($dom, 'fondPracovniDoby');
        $fund->appendChild($this->el(
            $dom,
            'stanovenyFond',
            self::text($source, './f:vykonavanaPozice/f:fondPracovniDoby/f:stanovenyFond', $body),
        ));
        $fund->appendChild($this->el($dom, 'sjednanyFond', '0.000'));
        $fund->appendChild($this->el($dom, 'stanovenaTydenniDoba', '0.00'));
        $position->appendChild($fund);
        $out->appendChild($position);

        $course = $this->el($dom, 'prubehZamestnani');
        $course->appendChild($this->wrap($dom, 'odpracovaneDny', 'dnyEvidencniStav', '0'));
        $course->appendChild($this->wrap($dom, 'odpracovaneHodiny', 'pocet', '0.000'));
        $out->appendChild($course);

        $income = $this->el($dom, 'prijem');
        $income->appendChild($this->wrap($dom, 'dan', 'zakladDane', '0'));
        $out->appendChild($income);

        $wage = $this->el($dom, 'mzda');
        $wage->appendChild($this->el($dom, 'mzdaZuctovana', '0'));
        $wage->appendChild($this->wrap($dom, 'vydelek', 'vydelekPrumernyHod', '0.00'));
        $out->appendChild($wage);

        return $form;
    }

    private function el(DOMDocument $dom, string $name, ?string $value = null): DOMElement
    {
        $node = $dom->createElementNS(JmhzSchemaCatalog::NS_FORM, 'form:' . $name);
        if ($value !== null) {
            $node->appendChild($dom->createTextNode($value));
        }

        return $node;
    }

    private function wrap(DOMDocument $dom, string $wrapper, string $leaf, string $value): DOMElement
    {
        $node = $this->el($dom, $wrapper);
        $node->appendChild($this->el($dom, $leaf, $value));

        return $node;
    }

    private static function body(DOMXPath $xpath, DOMElement $form): ?DOMElement
    {
        foreach ($form->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === JmhzSchemaCatalog::NS_FORM) {
                return $child;
            }
        }

        return null;
    }

    private static function hasElementChildren(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /** @return list<DOMElement> */
    private static function nodes(DOMXPath $xpath, string $query, ?DOMElement $context = null): array
    {
        $result = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        if ($result === false) {
            throw new JmhzXmlException(
                'jmhz_submission_components_unreadable',
                'Součásti zmrazeného podání nelze načíst.',
            );
        }
        $nodes = [];
        foreach ($result as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private static function optional(DOMXPath $xpath, string $query, DOMElement $context): ?DOMElement
    {
        return self::nodes($xpath, $query, $context)[0] ?? null;
    }

    private static function required(DOMXPath $xpath, string $query, DOMElement $context): DOMElement
    {
        return self::optional($xpath, $query, $context) ?? throw new JmhzXmlException(
            'jmhz_zero_amendment_original_incomplete',
            'Původní formulář nemá povinný údaj, který se v anulaci neopravuje.',
        );
    }

    private static function optionalText(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $node = self::optional($xpath, $query, $context);

        return $node === null ? null : trim($node->textContent);
    }

    private static function text(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $value = self::optionalText($xpath, $query, $context);
        if ($value === null || $value === '') {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_original_incomplete',
                'Původní formulář nemá povinný údaj, který se v anulaci neopravuje.',
            );
        }

        return $value;
    }

    /** @return array{0:DOMDocument,1:DOMXPath} */
    private static function load(string $xml): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_payload_invalid',
                'Zmrazenou datovou větu podání nelze přečíst.',
            );
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $xpath->registerNamespace('f', JmhzSchemaCatalog::NS_FORM);

        return [$dom, $xpath];
    }

    private static function save(DOMDocument $dom): string
    {
        $dom->formatOutput = true;
        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzXmlException(
                'jmhz_xml_serialization_failed',
                'XML nulového opravného hlášení nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }
}
