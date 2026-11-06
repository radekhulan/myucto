<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenSubmissionIdentity;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionFlagMatrix;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Nulové opravné hlášení (anulace měsíce) ve verzi 2027.
 *
 * Postup i fail-closed předpoklady jsou stejné jako ve verzi 2026
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroAmendmentXmlSerializer}):
 * jen `bezPriznaku`, jen nulový formulář, bez souběhu a bez ročních úhrnů.
 * Liší se tvar: formulář zaměstnání s hlavičkou v prostoru `form`, povinná
 * `zalohaNaDan` (10579 a prohlášení 10419 se z původního formuláře
 * přebírají jako „neopravuje se", s podepsaným prohlášením i datum podpisu
 * 10575, IN16), místo výkonu práce jako adresa a daň vztahu se dvěma
 * nulovými příjmy (10535, 10555).
 */
final class JmhzZeroAmendmentXml2027Serializer
{
    private const FORMS = '/p:jmhz/p:formulareZamestnani/f:formularZamestnani';

    /**
     * Peněžní listy původního formuláře, které musí být nulové (relativně
     * k tělu `bezPriznaku`). Logické a datumové prvky zálohy se nekontrolují.
     */
    private const MONETARY_LEAVES = [
        'f:souhrnDataZec/f:prijmy//*',
        'f:souhrnDataZec/f:zalohaNaDan/f:zakladDane',
        'f:souhrnDataZec/f:zalohaNaDan/f:vypoctenaZaloha',
        'f:souhrnDataZec/f:zalohaNaDan/f:danZalohaPoSleve',
        'f:souhrnDataZec/f:zalohaNaDan/f:danBonus',
        'f:souhrnDataZec/f:zalohaNaDan/f:zakladDanePar6',
        'f:souhrnDataZec/f:zalohaNaDan/f:srazenaDanPar6',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:zakladniSleva',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:zakladniSlevaInvalidita12',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:rozsirenaSlevaInvalidita3',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:slevaZTPP',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:slevaStudent/f:vyseSlevy',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:danoveZvyhodneniDetiMesic',
        'f:souhrnDataZec/f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:slevaDite',
        'f:souhrnDataZec/f:mzdaCista/f:mzdaCista',
        'f:souhrnDataZec/f:zdravPojZamestnavatel/f:zdravotniPojisteni',
        'f:souhrnDataZec/f:zdravPojZamestnanec/f:zdravotniPojisteni',
        'f:pojisteni/f:vymerovaciZaklad//*',
        'f:pojisteni/f:vymerovaciZakladParagraf5//*',
        'f:pojisteni/f:eldpSeznam/f:eldp/f:vymerovaciZaklad',
        'f:pojisteni/f:pojisteniZamestnanec/f:socialniPojisteni',
        'f:pojisteni/f:pojisteniZamestnavatel/f:socialniPojisteni',
        'f:prijem/f:dan/f:prijem',
        'f:prijem/f:dan/f:prijemPar6',
        'f:mzda//*',
    ];

    /** @param list<string> $formGuids */
    public function serialize(string $originalXml, array $formGuids, JmhzSubmissionEnvelope $envelope): string
    {
        [, $xpath] = self::load($originalXml);
        $identity = JmhzFrozenSubmissionIdentity::read($originalXml);
        Jmhz2027Schema::assertPeriod($identity->year, $identity->month);
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
        $root = Jmhz2027Schema::root($out, $envelope);
        $count = (string) count($originals);
        $header = $out->createElementNS(Jmhz2027Schema::NS_PODANI, 'hlavicka');
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
            $header->appendChild($out->createElementNS(Jmhz2027Schema::NS_PODANI, $name, $value));
        }
        $forms = $out->createElementNS(Jmhz2027Schema::NS_PODANI, 'formulareZamestnani');
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

        return Jmhz2027Schema::save($out, 'XML nulového opravného hlášení nelze serializovat.');
    }

    /** @param list<string> $formGuids */
    public function effectiveSet(string $originalXml, array $formGuids): string
    {
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

        return Jmhz2027Schema::save($dom, 'XML nulového opravného hlášení nelze serializovat.');
    }

    /** @return array<string,?JmhzXmlException> */
    public function assess(string $originalXml): array
    {
        [, $xpath] = self::load($originalXml);
        $byPerson = self::formsByPerson($xpath);
        $result = [];
        foreach (self::nodes($xpath, self::FORMS) as $form) {
            $guid = strtoupper(self::text($xpath, './f:hlavicka/f:idFormulare', $form));
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
        $byPerson = self::formsByPerson($xpath);
        $found = [];
        foreach (self::nodes($xpath, self::FORMS) as $form) {
            $guid = strtoupper(self::text($xpath, './f:hlavicka/f:idFormulare', $form));
            if (isset($wanted[$guid])) {
                $found[$guid] = $form;
            }
        }
        if (array_diff_key($wanted, $found) !== []) {
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

    /** @return array<string,int> */
    private static function formsByPerson(DOMXPath $xpath): array
    {
        $byPerson = [];
        foreach (self::nodes($xpath, self::FORMS) as $form) {
            $person = self::text($xpath, './/f:identifikace/f:ikMpsv', $form);
            $byPerson[$person] = ($byPerson[$person] ?? 0) + 1;
        }

        return $byPerson;
    }

    /** @param array<string,int> $formsByPerson */
    private function assertAnnullable(DOMXPath $xpath, DOMElement $form, array $formsByPerson): void
    {
        $body = self::body($form);
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
        $body = self::body($original);
        if ($body === null) {
            throw new JmhzXmlException('jmhz_zero_amendment_scenario_unsupported', 'Původní formulář nemá datovou část.');
        }
        $form = $this->el($dom, 'formularZamestnani');
        $header = $this->el($dom, 'hlavicka');
        $form->appendChild($header);
        $header->appendChild($this->el($dom, 'idFormulare', self::text($source, './f:hlavicka/f:idFormulare', $original)));
        $header->appendChild($this->el($dom, 'typFormulare', $formType));
        $primary = self::optionalText($source, './f:hlavicka/f:primarniPpv', $original);
        if ($primary !== null) {
            $header->appendChild($this->el($dom, 'primarniPpv', $primary));
        }

        $out = $this->el($dom, 'bezPriznaku');
        $form->appendChild($out);
        $out->appendChild($dom->importNode(self::required($source, './f:identifikace', $body), true));

        $summary = self::optional($source, './f:souhrnDataZec', $body);
        if ($summary !== null) {
            $node = $this->el($dom, 'souhrnDataZec');
            $node->appendChild($this->wrap($dom, 'prijmy', 'zuctovanoCelkem', '0'));
            $tax = $this->el($dom, 'zalohaNaDan');
            $tax->appendChild($this->el($dom, 'zalohaPar38', self::text($source, './f:zalohaNaDan/f:zalohaPar38', $summary)));
            $signed = self::text($source, './f:zalohaNaDan/f:prohlaseniPoplatnika', $summary);
            $tax->appendChild($this->el($dom, 'prohlaseniPoplatnika', $signed));
            if ($signed === 'true') {
                $declaration = $this->el($dom, 'prohlaseniPoplatnikaDane');
                $declaration->appendChild($this->el(
                    $dom,
                    'datumPodpisu',
                    self::text($source, './f:zalohaNaDan/f:prohlaseniPoplatnikaDane/f:datumPodpisu', $summary),
                ));
                $tax->appendChild($declaration);
            }
            $node->appendChild($tax);
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
        $tax = $this->el($dom, 'dan');
        $tax->appendChild($this->el($dom, 'prijem', '0'));
        $tax->appendChild($this->el($dom, 'prijemPar6', '0'));
        $income->appendChild($tax);
        $out->appendChild($income);

        $wage = $this->el($dom, 'mzda');
        $wage->appendChild($this->el($dom, 'mzdaZuctovana', '0'));
        $wage->appendChild($this->wrap($dom, 'vydelek', 'vydelekPrumernyHod', '0.00'));
        $out->appendChild($wage);

        return $form;
    }

    private function el(DOMDocument $dom, string $name, ?string $value = null): DOMElement
    {
        $node = $dom->createElementNS(Jmhz2027Schema::NS_FORM, 'form:' . $name);
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

    /** Datová část formuláře: první prvek prostoru `form` kromě hlavičky. */
    private static function body(DOMElement $form): ?DOMElement
    {
        foreach ($form->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->namespaceURI === Jmhz2027Schema::NS_FORM
                && $child->localName !== 'hlavicka'
            ) {
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
        $xpath->registerNamespace('p', Jmhz2027Schema::NS_PODANI);
        $xpath->registerNamespace('f', Jmhz2027Schema::NS_FORM);

        return [$dom, $xpath];
    }
}
